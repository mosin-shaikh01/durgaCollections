// Seller-guide screenshots and PDF (Phase 13). Run by tests/guide-screenshots.php, never on its own.
//
//   node guide-shots.mjs <job.json>
//
// The job names the browser, the puppeteer-core install to borrow (the theme-check tool's, see
// tests/README.md), and either
//   mode "shots": log in as the demo seller at phone width (375 × 667, 2×) and save four PNGs:
//                 scan.png (the product screen), sell.png (quantity and payment chosen),
//                 undo.png (the sale page with Undo, after a real sale), mysales.png;
//   mode "pdf":   print the built seller guide to an A4 PDF.
// Prints one JSON line with the result.

import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

const job = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const require = createRequire(join(job.puppeteerDir, 'package.json'));
const puppeteer = require('puppeteer-core');

const browser = await puppeteer.launch({ executablePath: job.browser, headless: true, args: ['--no-first-run', '--no-default-browser-check'] });
const result = { ok: false, files: [], errors: [] };

try {
	const page = await browser.newPage();
	// With the page's address (without the query) and the first line of the stack, to find the source.
	page.on('pageerror', (e) => result.errors.push(page.url().replace(/\?.*$/, '') + ': ' + String(e) + ((e && e.stack) ? ' (' + String(e.stack).split('\n').slice(1, 2).join('').trim() + ')' : '')));

	if ('pdf' === job.mode) {
		await page.goto(pathToFileURL(job.html).href, { waitUntil: 'load' });
		await page.pdf({ path: job.pdf, format: 'A4', printBackground: true, preferCSSPageSize: true });
		result.pages = (readFileSync(job.pdf, 'latin1').match(/\/Type\s*\/Page[^s]/g) || []).length;
		result.files.push(job.pdf);
	} else {
		await page.setViewport({ width: 375, height: 667, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
		const shot = async (name, clip) => {
			const path = join(job.outDir, name + '.png');
			await page.screenshot(clip ? { path, clip, captureBeyondViewport: true } : { path });
			result.files.push(path);
		};
		// Hide the text caret, as the Phase 12 screenshots do (it blinks), and show a neutral shop name
		// in the header (in this browser only; the site is not changed) so the guide names no real shop.
		const nocaret = async () => {
			await page.addStyleTag({ content: '*{caret-color:transparent !important}' }).catch(() => {});
			await page.evaluate((name) => { const s = document.querySelector('.pqbg-scan__site'); if (s) { s.textContent = name; } }, job.shopName);
		};

		await page.goto(job.loginUrl, { waitUntil: 'load' });
		await page.type('#user_login', job.user);
		await page.type('#user_pass', job.pass);
		await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('#wp-submit')]);
		if (!page.url().startsWith(job.scanUrl)) {
			throw new Error('login did not land on the scan URL: ' + page.url());
		}
		await nocaret();
		await page.evaluate(() => document.activeElement && document.activeElement.blur());
		await shot('scan');

		await page.select('#pqbg-quantity', '2');
		await page.click('input[name="payment_method"][value="upi"]');
		// From the price line to the end of the sale form, phone width (the page is too short to
		// scroll the form to the top without cutting through the product name).
		const clip = await page.evaluate(() => {
			document.activeElement && document.activeElement.blur();
			const form = document.querySelector('#pqbg-quantity').closest('form').getBoundingClientRect();
			const title = document.querySelector('h1').getBoundingClientRect();
			const top = window.scrollY + title.bottom + 8; // below the title's descenders
			return { x: 0, y: top, width: 375, height: Math.min(667, window.scrollY + form.bottom + 16 - top) };
		});
		await shot('sell', clip);

		await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('.pqbg-scan__button--sell')]);
		if (!page.url().includes('sale=')) {
			throw new Error('the sale did not complete: ' + page.url());
		}
		await nocaret();
		await page.evaluate(() => document.activeElement && document.activeElement.blur());
		await shot('undo');

		await page.goto(job.mySalesUrl, { waitUntil: 'load' });
		await nocaret();
		await page.evaluate(() => document.activeElement && document.activeElement.blur());
		await shot('mysales');
	}
	result.ok = 0 === result.errors.length;
} catch (e) {
	result.errors.push(String(e && e.stack ? e.stack : e));
} finally {
	await browser.close();
}

console.log(JSON.stringify(result));
