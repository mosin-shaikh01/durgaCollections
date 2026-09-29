// The user manual's PDF (1.0.1). Run by build-manual.php, never on its own.
//
//   node render.mjs <job.json>
//
// The job names docs/user-manual.md, the folder with the compressed screenshots, the template and
// the stylesheet, the version and date, the browser, the puppeteer-core install to borrow (the
// theme-check tool's) and the manual tools (marked, pdfjs-dist; PQBG_MANUAL_TOOLS).
//
// Markdown conventions (docs/user-manual.md):
//   "## 5. Printing labels"        a chapter (new page, in the table of contents and the bookmarks)
//   "### 5.2 Copies"               a section (in the table of contents)
//   a heading ending in " {admin}" gets the "Administrator only" badge
//   "![Caption](shot:name)"        a screenshot from the shots folder (name.png); names starting
//                                  with "phone-" are phone screens; figures alone in a paragraph
//                                  stand side by side
//
// Page numbers: the footer prints "Page X of Y". The table of contents gets its numbers in two
// passes: pass 1 prints the PDF with placeholder numbers (same width), the text of every page is
// read (pdfjs-dist) to find the page of each heading, pass 2 prints with the numbers, and pass 2 is
// read again: the build fails if any heading moved or was not found exactly once in order.
// Prints one JSON line: { ok, pages, bytes, headings: [{ level, text, page }], errors }.

import { readFileSync, writeFileSync, existsSync, statSync } from 'node:fs';
import { createRequire } from 'node:module';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';
import { readPdf } from './pdf-text.mjs';

const job = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const result = { ok: false, pages: 0, bytes: 0, headings: [], errors: [] };

const escapeHtml = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const slug = (s) => 'h-' + s.toLowerCase().replace(/<[^>]+>/g, '').replace(/&[a-z]+;/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
const norm = (s) => String(s).replace(/<[^>]+>/g, '').replace(/&amp;/g, '&').replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/\s+/g, '').toLowerCase();

try {
	const { Marked } = await import(pathToFileURL(join(job.tools, 'node_modules/marked/lib/marked.esm.js')).href);
	const headings = [];
	const missing = [];
	const marked = new Marked({ gfm: true });
	marked.use({
		renderer: {
			heading({ tokens, depth }) {
				let inner = this.parser.parseInline(tokens);
				const admin = /\s*\{admin\}\s*$/.test(inner);
				inner = inner.replace(/\s*\{admin\}\s*$/, '');
				const id = slug(inner);
				if (depth === 2 || depth === 3) {
					headings.push({ level: depth, id, html: inner, text: inner.replace(/<[^>]+>/g, '').replace(/&amp;/g, '&').replace(/&quot;/g, '"').replace(/&#39;/g, "'"), admin });
				}
				return `<h${depth} id="${id}">${inner}${admin ? '<span class="badge">Administrator only</span>' : ''}</h${depth}>\n`;
			},
			image({ href, text }) {
				const m = /^shot:([a-z0-9-]+)$/.exec(href || '');
				if (!m) {
					return `<img src="${escapeHtml(href)}" alt="${escapeHtml(text)}">`;
				}
				const file = join(job.shots, m[1] + '.png');
				const kind = m[1].startsWith('phone-') ? 'shot--phone' : 'shot--wide';
				if (!existsSync(file)) {
					missing.push(m[1]);
					return `<figure class="shot ${kind}"><div class="shot--missing">Screenshot missing: ${escapeHtml(m[1])}</div><figcaption>${escapeHtml(text)}</figcaption></figure>`;
				}
				const data = readFileSync(file).toString('base64');
				return `<figure class="shot ${kind}"><img src="data:image/png;base64,${data}" alt="${escapeHtml(text)}"><figcaption>${escapeHtml(text)}</figcaption></figure>`;
			},
		},
	});

	let body = marked.parse(readFileSync(job.md, 'utf8'));
	// A paragraph holding only figures: the figures stand side by side (phone screens).
	body = body.replace(/<p>((?:\s*<figure class="shot[^"]*">[\s\S]*?<\/figure>\s*)+)<\/p>/g, (all, figs) => ((figs.match(/<figure/g) || []).length > 1 ? `<div class="shots">${figs}</div>` : figs));
	// The first line of the source ("# User manual") is the cover's job.
	body = body.replace(/^<h1[^>]*>[\s\S]*?<\/h1>\s*/, '');

	if (missing.length && !job.draft) {
		throw new Error('screenshots missing: ' + missing.join(', '));
	}
	const ids = headings.map((h) => norm(h.text));
	const dup = ids.filter((x, i) => ids.indexOf(x) !== i);
	if (dup.length) {
		throw new Error('headings must be unique: ' + dup.join(', '));
	}

	const template = readFileSync(job.template, 'utf8');
	const css = readFileSync(job.css, 'utf8');
	const toc = (pages) => '<ol>' + headings.map((h, i) => `<li class="toc__h${h.level}"><a href="#${h.id}"><span class="toc__text">${h.html}${h.admin ? '<span class="badge">Administrator only</span>' : ''}</span><span class="toc__dots"></span><span class="toc__page">${pages ? pages[i] : '000'}</span></a></li>`).join('') + '</ol>';
	const html = (pages) => template.replace(/\{\{version\}\}/g, escapeHtml(job.version)).replace('{{date}}', escapeHtml(job.date)).replace('{{css}}', css).replace('{{toc}}', toc(pages)).replace('{{body}}', body);

	const require = createRequire(join(job.puppeteerDir, 'package.json'));
	const puppeteer = require('puppeteer-core');
	const browser = await puppeteer.launch({ executablePath: job.browser, headless: true, args: ['--no-first-run', '--no-default-browser-check'] });

	const footer = `<div style="width:100%;font-family:Segoe UI,Arial,sans-serif;font-size:7.5pt;color:#50575e;padding:0 16mm;display:flex;justify-content:space-between;"><span>Product QR Code and Barcode Generator ${escapeHtml(job.version)} – User manual</span><span>Page <span class="pageNumber"></span> of <span class="totalPages"></span></span></div>`;
	const print = async (pages) => {
		const htmlFile = join(job.work, 'manual.html'); // The build's temporary folder, never docs/.
		writeFileSync(htmlFile, html(pages));
		const page = await browser.newPage();
		page.on('pageerror', (e) => result.errors.push(String(e)));
		await page.goto(pathToFileURL(htmlFile).href, { waitUntil: 'load' });
		await page.pdf({
			path: job.out,
			format: 'A4',
			printBackground: true,
			displayHeaderFooter: true,
			headerTemplate: '<span></span>',
			footerTemplate: footer,
			margin: { top: '16mm', bottom: '18mm', left: '16mm', right: '16mm' },
			outline: true,
			tagged: true,
		});
		await page.close();
		return htmlFile;
	};
	// Every heading's page: in order, after the table of contents, each found once from the previous one on.
	const locate = async () => {
		const pdf = await readPdf(job.out, job.tools);
		const pagesText = pdf.text.map(norm);
		const tocEnd = pagesText.findIndex((t) => t.includes(norm('Page numbers are printed at the bottom of every page.')));
		if (tocEnd < 0) {
			throw new Error('the end of the table of contents was not found');
		}
		const found = [];
		let from = tocEnd + 1;
		for (const h of headings) {
			const key = norm(h.text);
			let at = -1;
			for (let p = from; p < pagesText.length; p++) {
				if (pagesText[p].includes(key)) {
					at = p;
					break;
				}
			}
			if (at < 0) {
				throw new Error('heading not found in the PDF: ' + h.text);
			}
			found.push(at + 1);
			from = at;
		}
		return { pages: found, pdf };
	};

	let htmlFile;
	try {
		htmlFile = await print(null);
		const pass1 = await locate();
		htmlFile = await print(pass1.pages);
		const pass2 = await locate();
		if (pass2.pages.join(',') !== pass1.pages.join(',')) {
			throw new Error('page numbers moved between passes: ' + pass1.pages.join(',') + ' / ' + pass2.pages.join(','));
		}
		result.pages = pass2.pdf.pages;
		result.title = pass2.pdf.title;
		result.outline = pass2.pdf.outline.length;
		result.headings = headings.map((h, i) => ({ level: h.level, text: h.text, admin: h.admin, page: pass2.pages[i] }));
		result.bytes = statSync(job.out).size;
		result.missing = missing;
	} finally {
		await browser.close();
		if (htmlFile && !job.keepHtml) {
			try { (await import('node:fs')).unlinkSync(htmlFile); } catch (e) { /* already gone */ }
		}
	}
	result.ok = 0 === result.errors.length;
} catch (e) {
	result.errors.push(String(e && e.stack ? e.stack : e));
}

console.log(JSON.stringify(result));
