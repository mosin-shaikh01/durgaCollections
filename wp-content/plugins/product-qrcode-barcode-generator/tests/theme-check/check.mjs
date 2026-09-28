// Headless-browser checks for Phase 12 (theme compatibility). Test tooling only; never loaded by the plugin.
//
//   node check.mjs job.json
//
// Drives an INSTALLED Chrome or Edge (puppeteer-core never downloads a browser) through the pages
// listed in job.json. Every page gets its own incognito browser context (its own cookies) and prints,
// in one JSON object per run:
//   - status and final URL of the main document, console errors, page errors, failed requests
//     (network errors and HTTP >= 400) and every CSP violation (listener installed before any script)
//   - the stylesheets and scripts the page loaded, inline <script>/<style> elements, style attributes,
//     every requested URL that belongs to the plugin, and elements with a pqbg- class
//   - horizontal overflow (document scrollWidth vs. the viewport) at 375, 320 and 1280 px
//   - for kind "scan": touch targets under 44 x 44 CSS px, the focused element, required elements
//     that are missing, a computed-style fingerprint of chosen elements, images (loaded, within
//     the viewport), and SHA-256 hashes of the phone and desktop screenshots (saved as PNG when
//     "shot" is given)
//   - scanner: types a code and Enter on the focused element, as a USB/Bluetooth scanner does,
//     and reports where that landed and what is focused there
//   - kind "login": opens a scan URL logged out, fills in wp-login.php and reports where it lands
//   - kind "logoutBack": clicks Log out on a scan page, then Back, and reports what Back shows
//
// job.json: { "browser": exe, "out": dir,
//             "pages": [{ "name", "url", "kind": "scan"|"theme"|"login"|"logoutBack",
//                         "cookies": [...], "headers": {...}, "before": [urls opened first],
//                         "required": [selectors], "styles": [selectors], "scanner": "CODE",
//                         "shot": "folder/name" (no extension), "user", "pass" }] }
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { createHash } from 'node:crypto';
import puppeteer from 'puppeteer-core';

const job = JSON.parse(readFileSync(process.argv[2], 'utf8'));
mkdirSync(job.out, { recursive: true });

const VIEWPORTS = {
  phone: { width: 375, height: 667, deviceScaleFactor: 2 },
  narrow: { width: 320, height: 568, deviceScaleFactor: 1 },
  desktop: { width: 1280, height: 800, deviceScaleFactor: 1 },
};
const STYLE_PROPS = ['display', 'font-family', 'font-size', 'font-weight', 'line-height', 'color', 'background-color',
  'padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'margin-top', 'margin-bottom',
  'border-top-width', 'border-top-style', 'border-top-color', 'border-radius', 'min-height', 'width', 'height', 'text-align'];

const browser = await puppeteer.launch({
  executablePath: job.browser,
  headless: true,
  userDataDir: join(job.out, 'profile'),
  args: ['--no-first-run', '--no-default-browser-check', '--disable-extensions'],
});

const result = { browser: await browser.version(), pages: {} };

try {
  for (const spec of job.pages) {
    try {
      result.pages[spec.name] = await check(spec);
    } catch (e) {
      result.pages[spec.name] = { error: String(e && e.message ? e.message : e) };
    }
  }
} finally {
  await browser.close();
}

process.stdout.write(JSON.stringify(result));

async function check(spec) {
  const context = await browser.createBrowserContext();
  const page = await context.newPage();
  const out = { consoleErrors: [], pageErrors: [], failedRequests: [], requests: [] };

  try {
    if (spec.cookies && spec.cookies.length) {
      await context.setCookie(...spec.cookies);
    }
    if (spec.headers) {
      await page.setExtraHTTPHeaders(spec.headers);
    }
    await page.setViewport(VIEWPORTS.phone);
    await page.evaluateOnNewDocument(() => {
      window.__pqbgCsp = [];
      document.addEventListener('securitypolicyviolation', (e) => {
        window.__pqbgCsp.push(`${e.violatedDirective} ${e.blockedURI}`);
      });
    });
    out.documentStatus = [];
    page.on('console', (m) => {
      if (m.type() !== 'error') return;
      // Chromium logs a document's own 4xx status (an intended 400/403/404 page) as a console
      // error; that is kept apart from real errors, which come from scripts or subresources.
      if (/^Failed to load resource: the server responded with a status of \d+/.test(m.text()) && m.location()?.url === page.url()) {
        out.documentStatus.push(m.text());
        return;
      }
      out.consoleErrors.push(m.text());
    });
    page.on('pageerror', (e) => out.pageErrors.push(String(e.message || e)));
    page.on('request', (r) => out.requests.push(r.url()));
    page.on('requestfailed', (r) => {
      // A navigation that replaces a still-loading request aborts it; that is not a page error.
      if (r.failure()?.errorText !== 'net::ERR_ABORTED') out.failedRequests.push(`${r.url()} ${r.failure()?.errorText}`);
    });
    page.on('response', (r) => {
      if (r.status() >= 400 && r.request().resourceType() !== 'document') out.failedRequests.push(`${r.url()} HTTP ${r.status()}`);
    });

    for (const url of spec.before || []) {
      await load(page, url);
    }
    out.requests = [];

    if (spec.kind === 'login') {
      await load(page, spec.url);
      out.loginUrl = page.url();
      await page.type('#user_login', spec.user);
      await page.type('#user_pass', spec.pass);
      await Promise.all([page.waitForNavigation({ waitUntil: 'load', timeout: 60000 }), page.click('#wp-submit')]);
      out.finalUrl = page.url();
      out.isScan = await page.evaluate(() => document.body.classList.contains('pqbg-scan'));
      out.active = await page.evaluate(() => document.activeElement && document.activeElement.id);
      out.csp = await page.evaluate(() => window.__pqbgCsp);
      return out;
    }

    const response = await load(page, spec.url);
    out.status = response ? response.status() : 0;
    out.finalUrl = page.url();

    if (spec.kind === 'logoutBack') {
      out.before = await page.evaluate(() => document.body.classList.contains('pqbg-scan'));
      await Promise.all([page.waitForNavigation({ waitUntil: 'load', timeout: 60000 }), page.click('.pqbg-scan__logout')]);
      out.afterLogout = page.url();
      await page.goBack({ waitUntil: 'load', timeout: 60000 });
      out.backUrl = page.url();
      out.backIsScan = await page.evaluate(() => document.body.classList.contains('pqbg-scan'));
      out.backHasProduct = await page.evaluate(() => !!document.querySelector('.pqbg-scan__name'));
      return out;
    }

    Object.assign(out, await page.evaluate(inspect, spec.kind === 'scan', spec.required || [], spec.styles || [], STYLE_PROPS));
    out.overflow = { phone: await overflow(page) };

    if (spec.kind === 'scan') {
      out.shots = {};
      out.shots.phone = await shot(page, spec, 'phone');
      await page.setViewport(VIEWPORTS.narrow);
      out.overflow.narrow = await overflow(page);
      await page.setViewport(VIEWPORTS.desktop);
      out.overflow.desktop = await overflow(page);
      out.shots.desktop = await shot(page, spec, 'desktop');
      await page.setViewport(VIEWPORTS.phone);

      if (spec.scanner) {
        // A hardware scanner types into whatever has focus and ends with Enter.
        await page.keyboard.type(spec.scanner, { delay: 5 });
        const [nav] = await Promise.all([page.waitForNavigation({ waitUntil: 'load', timeout: 60000 }), page.keyboard.press('Enter')]);
        out.scanner = {
          status: nav ? nav.status() : 0,
          url: page.url(),
          active: await page.evaluate(() => document.activeElement && document.activeElement.id),
          isScan: await page.evaluate(() => document.body.classList.contains('pqbg-scan')),
        };
      }
    } else if (spec.kind === 'theme') {
      if (spec.shot) {
        out.shots = { phone: await shot(page, spec, 'phone') };
      }
      await page.setViewport(VIEWPORTS.desktop);
      out.overflow.desktop = await overflow(page);
      if (spec.shot) {
        out.shots.desktop = await shot(page, spec, 'desktop');
      }
    }

    out.csp = await page.evaluate(() => window.__pqbgCsp);
    out.plugin = {
      requests: out.requests.filter((u) => u.includes('/product-qrcode-barcode-generator/')),
      ...(await page.evaluate(() => ({
        classes: document.querySelectorAll('[class*="pqbg"]').length,
        text: document.documentElement.outerHTML.includes('pqbg'),
      }))),
    };
    delete out.requests;
    return out;
  } finally {
    await context.close();
  }
}

async function load(page, url) {
  const response = await page.goto(url, { waitUntil: 'load', timeout: 120000 });
  try {
    await page.waitForNetworkIdle({ idleTime: 500, timeout: 20000 });
  } catch {
    // Pages that keep polling never go idle; "load" plus 20 s is enough for the checks.
  }
  return response;
}

async function overflow(page) {
  return page.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    innerWidth: window.innerWidth,
  }));
}

async function shot(page, spec, view) {
  // The blinking caret in the focused code box would make otherwise identical screenshots differ.
  // CSSOM (not a style attribute or element), so the page's CSP is not involved; focus stays.
  await page.evaluate(() => {
    if (document.activeElement && document.activeElement.style) document.activeElement.style.caretColor = 'transparent';
  });
  const buf = await page.screenshot({ fullPage: true, type: 'png' });
  if (spec.shot && job.shots) {
    const file = join(job.shots, `${spec.shot}-${view}.png`);
    mkdirSync(dirname(file), { recursive: true });
    writeFileSync(file, buf);
  }
  return createHash('sha256').update(buf).digest('hex');
}

// Runs in the page.
function inspect(isScan, required, styles, props) {
  const visible = (el) => {
    const r = el.getBoundingClientRect();
    const cs = getComputedStyle(el);
    return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none';
  };
  const out = {
    title: document.title,
    stylesheets: [...document.querySelectorAll('link[rel~="stylesheet"]')].map((l) => l.href),
    scripts: [...document.querySelectorAll('script[src]')].map((s) => s.src),
    inlineScripts: document.querySelectorAll('script:not([src])').length,
    styleElements: document.querySelectorAll('style').length,
    styleAttributes: document.querySelectorAll('[style]').length,
    isScan: document.body.classList.contains('pqbg-scan'),
  };

  if (!isScan) {
    return out;
  }

  out.active = document.activeElement ? document.activeElement.id : '';
  out.missing = required.filter((s) => !document.querySelector(s));

  // Touch targets: links, buttons, inputs and the labels of radio buttons (the radio itself is
  // reached through its label). A link inside running text is exempt (WCAG 2.5.5).
  const targets = [...document.querySelectorAll('a[href], button, input:not([type="hidden"]):not([type="radio"]):not([type="checkbox"]), select, textarea, label')]
    .filter((el) => el.tagName !== 'LABEL' || el.querySelector('input[type="radio"], input[type="checkbox"]') || (el.htmlFor && document.getElementById(el.htmlFor)?.matches('input[type="radio"], input[type="checkbox"]')))
    .filter(visible)
    .filter((el) => !(el.tagName === 'A' && el.parentElement && ['P', 'LI', 'SPAN'].includes(el.parentElement.tagName) && el.parentElement.textContent.trim() !== el.textContent.trim()));
  out.targets = targets.length;
  out.smallTargets = targets
    .map((el) => ({ el, r: el.getBoundingClientRect() }))
    .filter(({ r }) => r.width < 44 || r.height < 44)
    .map(({ el, r }) => `${el.tagName.toLowerCase()}.${[...el.classList].join('.')} "${el.textContent.trim().slice(0, 30)}" ${Math.round(r.width)}x${Math.round(r.height)}`);

  out.styles = {};
  for (const sel of styles) {
    const el = document.querySelector(sel);
    if (el) {
      const cs = getComputedStyle(el);
      out.styles[sel] = props.map((p) => cs.getPropertyValue(p)).join('|');
    } else {
      out.styles[sel] = null;
    }
  }

  out.images = [...document.images].map((img) => ({
    complete: img.complete && img.naturalWidth > 0,
    right: Math.round(img.getBoundingClientRect().right),
    innerWidth: window.innerWidth,
  }));

  return out;
}
