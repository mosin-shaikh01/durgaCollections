// Reads a PDF with pdfjs-dist (1.0.1 manual build and tests). Build-time only.
//
//   node pdf-text.mjs <file.pdf> <tools-dir>
//   node pdf-text.mjs <job.json>              (a job { file, tools }, as build-manual.php and the tests pass it)
//
// Prints one JSON line: { ok, pages, title, outline: [titles], text: [text of each page] }.
// <tools-dir> is the folder where build/manual/package.json was installed (PQBG_MANUAL_TOOLS).

import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

export async function readPdf(file, tools) {
	const { getDocument } = await import(pathToFileURL(join(tools, 'node_modules/pdfjs-dist/legacy/build/pdf.mjs')).href);
	// Text only: pdfjs warns once that its optional canvas package is missing (not installed; not needed).
	const task = getDocument({ data: new Uint8Array(readFileSync(file)), useSystemFonts: false, isEvalSupported: false, verbosity: 0 });
	const doc = await task.promise;
	const text = [];
	for (let i = 1; i <= doc.numPages; i++) {
		const page = await doc.getPage(i);
		const content = await page.getTextContent();
		text.push(content.items.map((item) => item.str + (item.hasEOL ? '\n' : '')).join(''));
	}
	const meta = await doc.getMetadata();
	// Every bookmark title, nested ones included (Chrome nests the chapters under the cover's h1).
	const flat = (items) => (items || []).flatMap((o) => [o.title, ...flat(o.items)]);
	const outline = flat(await doc.getOutline());
	await task.destroy();
	return { pages: text.length, title: String(meta.info?.Title || ''), outline, text };
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
	try {
		const job = process.argv[2].endsWith('.json') ? JSON.parse(readFileSync(process.argv[2], 'utf8')) : { file: process.argv[2], tools: process.argv[3] };
		console.log(JSON.stringify({ ok: true, ...(await readPdf(job.file, job.tools)) }));
	} catch (e) {
		console.log(JSON.stringify({ ok: false, error: String(e && e.stack ? e.stack : e) }));
	}
}
