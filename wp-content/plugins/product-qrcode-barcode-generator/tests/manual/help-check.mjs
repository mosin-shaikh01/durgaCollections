// 1.0.1 suite helper: the Dashboard's "Plugin guide" button in a real browser (keyboard, focus,
// tooltip, Escape, new tab). Run by tests/phase14-manual.php, never on its own.
//
//   node help-check.mjs <job.json>
//
// The job names the browser, the puppeteer-core install to borrow (the theme-check tool's), the
// login URL, a test administrator's login, the Dashboard URL and the manual's URL.
// Prints one JSON line with what was observed; the suite judges it.

import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { join } from 'node:path';

const job = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const require = createRequire(join(job.puppeteerDir, 'package.json'));
const puppeteer = require('puppeteer-core');
const out = { ok: false, errors: [], console: [] };

const browser = await puppeteer.launch({ executablePath: job.browser, headless: true, args: ['--no-first-run', '--no-default-browser-check'] });
try {
	const page = await browser.newPage();
	page.on('pageerror', (e) => out.errors.push(String(e)));
	page.on('console', (m) => { if ('error' === m.type()) { out.console.push(m.text()); } });
	await page.setViewport({ width: 1280, height: 800 });
	await page.goto(job.loginUrl, { waitUntil: 'load' });
	await page.type('#user_login', job.user);
	await page.type('#user_pass', job.pass);
	await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('#wp-submit')]);
	out.console = [];
	await page.goto(job.dashboardUrl, { waitUntil: 'networkidle2' });

	const tip = () => page.$eval('#pqbg-help-tip', (t) => ({ visibility: getComputedStyle(t).visibility, opacity: getComputedStyle(t).opacity }));
	out.hiddenAtStart = await tip();

	// Tab from the top of the page until the button has the focus. The admin bar and the whole admin menu
	// (every submenu link) come first: well over 80 stops on WordPress 7.1, so allow 400 and report the count.
	await page.evaluate(() => document.activeElement && document.activeElement.blur());
	out.tabs = 0;
	while (out.tabs < 400 && !(await page.evaluate(() => document.activeElement && document.activeElement.classList.contains('pqbg-help__link')))) {
		await page.keyboard.press('Tab');
		out.tabs++;
	}
	out.reachedByTab = await page.evaluate(() => document.activeElement.classList.contains('pqbg-help__link'));
	out.focusVisible = await page.evaluate(() => document.activeElement.matches(':focus-visible'));
	out.outline = await page.evaluate(() => { const s = getComputedStyle(document.activeElement); return s.outlineStyle + ' ' + s.outlineWidth; });
	out.onFocus = await tip();
	out.accessibleName = await page.evaluate(() => document.activeElement.textContent.trim());
	out.describedBy = await page.evaluate(() => { const id = document.activeElement.getAttribute('aria-describedby'); const t = id && document.getElementById(id); return t ? t.textContent.trim() : ''; });

	await page.keyboard.press('Escape');
	out.afterEscape = await tip();

	await page.keyboard.press('Tab'); // Focus leaves: the tooltip may show again next time.
	await page.keyboard.down('Shift');
	await page.keyboard.press('Tab');
	await page.keyboard.up('Shift');
	out.backOnFocus = await tip();

	await page.evaluate(() => document.activeElement && document.activeElement.blur());
	await page.mouse.move(5, 790);
	out.blurred = await tip();
	await page.hover('.pqbg-help__link');
	out.onHover = await tip();
	const tipBox = await page.$eval('#pqbg-help-tip', (t) => { const r = t.getBoundingClientRect(); return { x: r.x + 10, y: r.y + r.height - 4 }; });
	await page.mouse.move(tipBox.x, tipBox.y); // WCAG 1.4.13: the pointer can move onto the tooltip.
	out.onTooltipHover = await tip();

	// Enter on the focused button opens the manual in a new tab.
	await page.focus('.pqbg-help__link');
	const newTarget = browser.waitForTarget((t) => t.opener() === page.target(), { timeout: 15000 });
	await page.keyboard.press('Enter');
	const target = await newTarget;
	out.newTabUrl = target.url();
	out.ok = true;
} catch (e) {
	out.errors.push(String(e && e.stack ? e.stack : e));
} finally {
	await browser.close();
}
console.log(JSON.stringify(out));
