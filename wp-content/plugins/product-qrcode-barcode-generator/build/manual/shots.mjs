// The user manual's screenshots (1.0.1). Run by build-manual.php, never on its own.
//
//   node shots.mjs <job.json>
//
// One job = one sample user in one browser profile (so the login survives between jobs) at one
// width: "desktop" (1280 × 800) for wp-admin, "phone" (375 × 667 at 2×) for the scan pages.
// Each shot: optional steps (goto, follow a link, click, select, check, upload, wait for a text,
// focus, hover), then a screenshot of the viewport, the whole page or an element (clip).
//
// Before every screenshot: the caret is hidden, the site title is replaced by the sample shop name
// in the page's text (this browser only; the site is not changed), and the page's visible text is
// checked for every forbidden string (the real site title, the real administrator's login and
// display name). A forbidden string fails the shot and nothing is saved.
// Prints one JSON line: { ok, files, errors }.

import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { join } from 'node:path';

const job = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const require = createRequire(join(job.puppeteerDir, 'package.json'));
const puppeteer = require('puppeteer-core');
const result = { ok: false, files: [], errors: [] };
const phone = 'phone' === job.viewport;

const browser = await puppeteer.launch({
	executablePath: job.browser,
	headless: true,
	userDataDir: job.profile,
	args: ['--no-first-run', '--no-default-browser-check', '--lang=en-GB', '--hide-scrollbars'],
});

try {
	const page = (await browser.pages())[0] || (await browser.newPage());
	// Script errors of the pages (e.g. WordPress core's own admin scripts) are reported, not fatal: the
	// screenshots show server-rendered screens, and the plugin's own script is tested by the 1.0.1 suite.
	result.warnings = [];
	page.on('pageerror', (e) => result.warnings.push(page.url().replace(/\?.*$/, '') + ': ' + String(e).split('\n')[0]));
	page.on('dialog', (d) => d.dismiss().catch(() => {}));
	await page.setViewport(phone ? { width: 375, height: 667, deviceScaleFactor: 2, isMobile: true, hasTouch: true } : { width: 1280, height: 800, deviceScaleFactor: 1 });

	const goto = async (url) => {
		const r = await page.goto(url, { waitUntil: 'networkidle2', timeout: 60000 });
		return r ? r.status() : 0;
	};
	const prepare = async () => {
		await page.addStyleTag({ content: '*{caret-color:transparent !important}' + (job.css || '') }).catch(() => {});
		await page.evaluate((title, shop) => {
			if (title) {
				const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
				const re = new RegExp(title.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'gi');
				for (let n = walker.nextNode(); n; n = walker.nextNode()) {
					if (re.test(n.nodeValue)) {
						n.nodeValue = n.nodeValue.replace(re, shop);
					}
					re.lastIndex = 0;
				}
				document.title = document.title.replace(re, shop);
			}
		}, job.siteTitle, job.shopName);
	};
	const forbidden = async () => {
		const text = (await page.evaluate(() => document.body.innerText + ' ' + document.title)).toLowerCase();
		return (job.forbidden || []).filter((f) => f && text.includes(f.toLowerCase()));
	};
	const shot = async (s) => {
		await prepare();
		if (!s.keepFocus) {
			await page.evaluate(() => document.activeElement && document.activeElement !== document.body && document.activeElement.blur());
		}
		const bad = await forbidden();
		if (bad.length) {
			throw new Error(`${s.name}: the page shows a real name (${bad.join(', ')})`);
		}
		const path = join(job.outDir, s.name + '.png');
		if (s.clip) {
			const box = await page.evaluate((c) => {
				// A section without its own element: from the h2 "heading" down to the h2 "until" (or the end of .wrap).
				if (c.heading) {
					const h2 = [...document.querySelectorAll('.wrap h2')];
					const from = h2.find((h) => h.textContent.trim() === c.heading);
					const to = c.until ? h2.find((h) => h.textContent.trim() === c.until) : null;
					const wrap = document.querySelector('#wpbody-content .wrap');
					if (!from || !wrap || (c.until && !to)) {
						return null;
					}
					from.scrollIntoView({ block: 'start' });
					const w = wrap.getBoundingClientRect();
					const top = from.getBoundingClientRect().top + window.scrollY;
					const bottom = (to ? to.getBoundingClientRect().top - 6 : w.bottom) + window.scrollY;
					return { x: w.left, y: top, width: w.width, height: bottom - top };
				}
				const els = [].concat(c.selector).map((sel) => document.querySelector(sel));
				if (els.some((e) => !e)) {
					return null;
				}
				els[0].scrollIntoView({ block: 'start' });
				const r = els.map((e) => e.getBoundingClientRect());
				const top = Math.min(...r.map((x) => x.top)) + window.scrollY;
				const bottom = Math.max(...r.map((x) => x.bottom)) + window.scrollY;
				const left = c.fullWidth ? 0 : Math.min(...r.map((x) => x.left));
				const right = c.fullWidth ? document.documentElement.clientWidth : Math.max(...r.map((x) => x.right));
				return { x: left, y: top, width: right - left, height: bottom - top };
			}, s.clip);
			if (!box) {
				throw new Error(`${s.name}: clip element not found (${s.clip.heading || [].concat(s.clip.selector).join(', ')})`);
			}
			const pad = s.clip.pad ?? 8;
			const width = Math.min(box.width + 2 * pad, (phone ? 375 : 1280) - Math.max(0, box.x - pad));
			const height = Math.min(box.height + 2 * pad, s.clip.maxHeight || 2400);
			await page.screenshot({ path, clip: { x: Math.max(0, box.x - pad), y: Math.max(0, box.y - pad), width, height }, captureBeyondViewport: true });
		} else {
			await page.screenshot({ path, fullPage: Boolean(s.full) });
		}
		result.files.push(path);
	};

	for (const s of job.shots) {
		for (const step of s.steps || []) {
			if (step.goto) {
				const status = await goto(step.goto);
				if (step.loginShot && (await page.$('#user_login'))) {
					await shot({ name: step.loginShot });
				}
				if (await page.$('#user_login')) {
					await page.type('#user_login', job.user);
					await page.type('#user_pass', job.pass);
					await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 60000 }), page.click('#wp-submit')]);
					if (!page.url().startsWith(step.goto.split('#')[0].split('?')[0])) {
						await goto(step.goto);
					}
				} else if (status >= 400 && !step.allowError) {
					throw new Error(`${s.name}: ${step.goto} answered ${status}`);
				}
			} else if (step.follow) {
				const href = await page.$eval(step.follow, (a) => a.href).catch(() => null);
				if (!href) {
					throw new Error(`${s.name}: link not found (${step.follow})`);
				}
				await goto(href);
			} else if (step.click) {
				if (step.nav) {
					await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 120000 }), page.click(step.click)]);
				} else {
					await page.click(step.click);
				}
			} else if (step.select) {
				await page.select(step.select[0], step.select[1]);
			} else if (step.check) {
				await page.$eval(step.check, (el) => { if (!el.checked) { el.click(); } });
			} else if (step.upload) {
				const input = await page.$(step.upload[0]);
				await input.uploadFile(step.upload[1]);
			} else if (step.waitText) {
				await page.waitForFunction((t) => document.body.innerText.includes(t), { timeout: step.timeout || 120000, polling: 500 }, step.waitText);
			} else if (step.focus) {
				await page.focus(step.focus);
			} else if (step.key) {
				await page.keyboard.press(step.key);
			} else if (step.hover) {
				await page.hover(step.hover);
			}
		}
		if (s.name) {
			await shot(s);
		}
	}
	result.ok = 0 === result.errors.length;
} catch (e) {
	result.errors.push(String(e && e.stack ? e.stack : e));
} finally {
	await browser.close();
}

console.log(JSON.stringify(result));
