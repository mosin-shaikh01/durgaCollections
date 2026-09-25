// Headless-browser checks for the Phase 8 print page. Test tooling only; never loaded by the plugin.
//
//   node check.mjs job.json
//
// Drives an INSTALLED Chrome or Edge (puppeteer-core never downloads a browser)
// through the pages listed in job.json, logged in with the given cookies, and
// prints one JSON object with, per page:
//   - HTTP status, console errors, page errors, failed requests and every CSP
//     violation (a securitypolicyviolation listener installed before any script)
//   - for print pages:
//     - clickPrint: whether clicking the Print button called window.print()
//       (window.print is replaced by a counter, so no dialog opens)
//     - geometry in print media, in mm: every sheet and label, the QR, text and
//       barcode boxes, and every text line's scroll/client sizes
//     - fonts: the platform fonts Chromium actually used for the price text
//       (CDP CSS.getPlatformFontsForNode) and a glyph test: the "₹" drawn in the
//       label's font must differ from a missing-glyph box (U+0378 is unassigned)
//     - screenshots: every label at dpi/96 device pixels per CSS pixel, i.e. at its
//       real printed size at that resolution, saved as PNG for the decoder
//     - pdf: page.pdf() with the page's own @page size; page sizes and the
//       position of every code text, read back with pdf.js
//
// job.json: { "browser": exe, "cookies": [...], "out": dir,
//             "pages": [{ "name", "url", "kind": "setup"|"print", "dpi",
//                         "clickPrint", "fonts", "screenshots", "pdf" }] }
import { readFileSync, mkdirSync } from 'node:fs';
import { join } from 'node:path';
import puppeteer from 'puppeteer-core';
import { getDocument } from 'pdfjs-dist/legacy/build/pdf.mjs';

const job = JSON.parse(readFileSync(process.argv[2], 'utf8'));
mkdirSync(job.out, { recursive: true });

const browser = await puppeteer.launch({
  executablePath: job.browser,
  headless: true,
  userDataDir: join(job.out, 'profile'),
  args: ['--no-first-run', '--no-default-browser-check', '--disable-extensions'],
});

const result = { browser: await browser.version(), pages: {} };

try {
  for (const spec of job.pages) {
    result.pages[spec.name] = await check(spec);
  }
} finally {
  await browser.close();
}

process.stdout.write(JSON.stringify(result));

async function check(spec) {
  const page = await browser.newPage();
  const out = { consoleErrors: [], pageErrors: [], failedRequests: [], csp: [] };

  await page.setCookie(...job.cookies);
  await page.evaluateOnNewDocument(() => {
    window.__pqbgCsp = [];
    document.addEventListener('securitypolicyviolation', (e) => {
      window.__pqbgCsp.push(`${e.violatedDirective} ${e.blockedURI}`);
    });
    window.__pqbgPrints = 0;
    window.print = () => {
      window.__pqbgPrints += 1;
    };
  });
  page.on('console', (m) => {
    if (m.type() === 'error') out.consoleErrors.push(m.text());
  });
  page.on('pageerror', (e) => out.pageErrors.push(String(e.message || e)));
  page.on('requestfailed', (r) => out.failedRequests.push(`${r.url()} ${r.failure()?.errorText}`));
  page.on('response', (r) => {
    if (r.status() >= 400) out.failedRequests.push(`${r.url()} HTTP ${r.status()}`);
  });

  const response = await page.goto(spec.url, { waitUntil: 'networkidle0', timeout: 120000 });
  out.status = response ? response.status() : 0;

  if (spec.kind === 'print') {
    if (spec.clickPrint) {
      await page.click('#pqbg-print-button');
      out.printCalls = await page.evaluate(() => window.__pqbgPrints);
    }

    await page.emulateMediaType('print');
    out.geometry = await page.evaluate(geometry);

    if (spec.fonts) {
      out.fonts = await fonts(page);
    }

    if (spec.screenshots) {
      out.screenshots = await screenshots(page, spec);
    }

    if (spec.pdf) {
      out.pdf = await pdf(page, spec);
    }
  }

  out.csp = await page.evaluate(() => window.__pqbgCsp);
  await page.close();

  return out;
}

// Runs in the page: every sheet and label in mm, relative to its sheet / label.
function geometry() {
  const mm = (px) => Math.round((px * 25.4 / 96) * 1000) / 1000;
  const rel = (el, base) => {
    if (!el) return null;
    const r = el.getBoundingClientRect();
    const b = base.getBoundingClientRect();
    return { x: mm(r.left - b.left), y: mm(r.top - b.top), w: mm(r.width), h: mm(r.height) };
  };
  const toolbar = document.querySelector('.pqbg-toolbar');

  return {
    toolbarVisible: !!toolbar && getComputedStyle(toolbar).display !== 'none',
    styleAttributes: document.querySelectorAll('[style]').length,
    sheets: [...document.querySelectorAll('.pqbg-sheet')].map((sheet) => ({
      w: mm(sheet.getBoundingClientRect().width),
      h: mm(sheet.getBoundingClientRect().height),
      labels: [...sheet.querySelectorAll('.pqbg-label')].map((label) => ({
        code: label.dataset.code,
        slot: Number([...label.classList].find((c) => /^pqbg-s\d+$/.test(c)).slice(6)),
        box: rel(label, sheet),
        qr: rel(label.querySelector('.pqbg-qr'), label),
        text: rel(label.querySelector('.pqbg-text'), label),
        bc: rel(label.querySelector('.pqbg-bc'), label),
        lines: [...label.querySelectorAll('.pqbg-l')].map((l) => ({
          cls: [...l.classList].find((c) => c.startsWith('pqbg-l-')),
          text: l.textContent,
          box: rel(l, label),
          scrollW: l.scrollWidth,
          clientW: l.clientWidth,
          scrollH: l.scrollHeight,
          clientH: l.clientHeight,
        })),
      })),
    })),
  };
}

async function fonts(page) {
  const client = await page.createCDPSession();
  await client.send('DOM.enable');
  await client.send('CSS.enable');
  const { root } = await client.send('DOM.getDocument', { depth: -1 });
  const { nodeId } = await client.send('DOM.querySelector', { nodeId: root.nodeId, selector: '.pqbg-l-price' });

  if (!nodeId) return { found: false };

  const { fonts: used } = await client.send('CSS.getPlatformFontsForNode', { nodeId });
  const glyph = await page.evaluate(() => {
    const el = document.querySelector('.pqbg-l-price');
    const family = getComputedStyle(el).fontFamily;
    const draw = (text, font) => {
      const c = document.createElement('canvas');
      c.width = 96;
      c.height = 96;
      const ctx = c.getContext('2d');
      ctx.font = font;
      ctx.fillStyle = '#000';
      ctx.textBaseline = 'top';
      ctx.fillText(text, 8, 8);
      return { data: [...ctx.getImageData(0, 0, 96, 96).data].join(','), width: ctx.measureText(text).width };
    };
    const rupee = draw('₹', `64px ${family}`);
    const missing = draw('͸', `64px ${family}`);
    const known = draw('₹', '64px "Nirmala UI", "Segoe UI"');
    return {
      family,
      text: el.textContent,
      rupeeWidth: rupee.width,
      missingWidth: missing.width,
      knownWidth: known.width,
      differsFromMissingGlyph: rupee.data !== missing.data,
      inked: rupee.data.split(',').some((v, i) => i % 4 === 3 && v !== '0'),
    };
  });

  return { found: true, used, glyph };
}

async function screenshots(page, spec) {
  const scale = spec.dpi / 96;
  await page.setViewport({ width: 1200, height: 1200, deviceScaleFactor: scale });
  await page.emulateMediaType('print');

  const labels = await page.$$('.pqbg-label');
  const files = [];

  for (let i = 0; i < labels.length; i++) {
    const code = await labels[i].evaluate((el) => el.dataset.code);
    const path = join(job.out, `${spec.name}-${i}.png`);
    await labels[i].screenshot({ path });
    files.push({ file: path, code });
  }

  return { dpi: spec.dpi, scale, files };
}

async function pdf(page, spec) {
  const path = join(job.out, `${spec.name}.pdf`);
  await page.pdf({ path, preferCSSPageSize: true, printBackground: false });

  const doc = await getDocument({ data: new Uint8Array(readFileSync(path)), verbosity: 0 }).promise;
  const pages = [];

  for (let n = 1; n <= doc.numPages; n++) {
    const p = await doc.getPage(n);
    const [x0, y0, x1, y1] = p.view;
    const content = await p.getTextContent();
    const pt = (v) => Math.round((v / 72) * 25.4 * 1000) / 1000;
    pages.push({
      w: pt(x1 - x0),
      h: pt(y1 - y0),
      codes: content.items
        .filter((it) => /^DC-/.test(it.str))
        .map((it) => ({ str: it.str, x: pt(it.transform[4] - x0), baseline: pt(y1 - it.transform[5]) })),
    });
  }

  return { file: path, pages };
}
