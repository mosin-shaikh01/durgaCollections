# Launch runbook

The ordered steps to take the shop from this development laptop (`http://localhost/sharayu`) to its live host, with the Product QR Code and Barcode Generator plugin 1.0.1 (1.0.0 until 2026-09-28; 1.0.1 adds the user manual and help links). It replaces the "Pre-launch acceptance checklist" that was in `progress.md` (moved here in Phase 13, 2026-09-28): every item of that checklist is a step below, marked **[acceptance]**.

How to use it:
- Work from top to bottom. Tick a box only when its **Check** passes.
- **Who:** *Owner* = the shop owner; *Admin* = whoever looks after the website (may be the same person).
- The detailed steps of each **[acceptance]** check are in the plugin README (`wp-content/plugins/product-qrcode-barcode-generator/README.md`), in the checklist named in brackets.
- The plain-language guides are in the plugin's `docs/` folder: the user manual `user-manual.pdf` (since 1.0.1; it replaced `owner-guide.md` and opens from the Dashboard's "Plugin guide" button), and `seller-guide.pdf` / `seller-guide.html` for the staff.
- Write the date and your initials next to each ticked box.

State on 2026-09-28 (checked read-only at the start of this runbook): plugin 1.0.0 on WordPress 7.1.2, WooCommerce 11.1.2, PHP 8.5.6; permalinks `/%postname%/`; Scan base URL empty (the site address); WooCommerce Coming Soon **on**; Settings → Reading "Discourage search engines" **on** (`blog_public` = 0); the phone-test snippet is **not** in the local `wp-config.php`.

---

## A. Before moving (on the laptop)

- [ ] **A1. Backup.** *Admin.* A full database backup (and of `wp-content/uploads`), outside the web root.
  Check: the `.sql` file ends with "Dump completed" and has every table.
- [ ] **A2. Phase 9A manual test [acceptance].** *Owner + Admin* (the "Phase 9A checklist"). Sell with each payment method (required, none pre-selected), check My sales on the phone, set a cost price, check the history, totals and profit as an administrator, confirm a shop manager sees no cost anywhere, void a sale with a reason, open the CSV export in Excel.
- [ ] **A3. Phase 9B manual checks [acceptance].** *Owner* (the "Phase 9B checklist"). The Summary figures against In-store sales for the same day, the end-of-day "Cash expected in drawer" and its print page, a report CSV in Excel, and a shop manager sees no profit, margin or cost anywhere in the reports.
- [ ] **A4. Phase 10 manual checks [acceptance].** *Owner* (the "Phase 10 checklist"). After a backup, generate the missing codes on the real catalogue and open the first "Print labels" link (**do not print real labels yet**, see D4); open the codes CSV in Excel via Data → From Text/CSV (leading zeros kept, no cost); a cost import with preview (one wrong value shown as an error), apply and report; a shop manager sees only Code tools (no Settings or cost tab, no cost anywhere).
- [ ] **A5. Phase 10B manual checks [acceptance].** *Owner* (the "Phase 10B checklist"). Click through QR & Barcodes as an administrator and as a shop manager, on a desktop and on a phone: the menu below Products, the five pages (no Settings for the shop manager), nothing under WooCommerce, the tab row and highlighting, the Dashboard figures against In-store reports → Summary → Today, an old bookmark `admin.php?page=pqbg-settings&tab=tools` lands on Bulk tools, and on the phone the menu folds, tabs wrap and the Dashboard is one column.
- [ ] **A6. Phase 11 manual checks [acceptance].** *Owner* (the "Phase 11 checklist"). After a backup, open Settings → Health check on the real data (no errors, or each finding understood; a shop manager gets 403). On the phone, leave a sale page open and watch the Undo button disappear by itself about 10 minutes after the sale; then void the test sale.
  (Phone tests on the laptop need the Cloudflare tunnel and the phone-test `wp-config.php` snippet from the README's "Phone testing" section. **Remove the snippet and clear the Scan base URL afterwards**, see C3.)
- [ ] **A7. Interim label check without a printer [acceptance].** *Owner.* Open a label sheet (the print page), Save as PDF, and scan several codes from the screen with a phone.
- [ ] **A8. 50,000-sale stress checks [acceptance].** *Admin.* **Done 2026-09-28** at `1b583f9` (Phase 13): the reports and the Dashboard under 2 s and the Health check under 1 s at 50,000 sales (see the Phase 13 section of `progress.md`). Re-run (`PQBG_STRESS=1`, phase9b, phase10b and phase11 suites) only if a later change touches what the reports read.
- [ ] **A8b. User manual and help links (1.0.1) [acceptance].** *Owner.* On the computer: QR & Barcodes → Dashboard → **Plugin guide** opens the manual in a new tab; the tooltip shows when you point at the button or reach it with the Tab key, and Escape hides it; **Seller guide (1 page, for staff)** opens the seller guide; Plugins → the plugin's row → **User manual** opens the manual. On a phone: My sales → **How to sell (guide)** opens the seller guide (or downloads it: open the file). Print two pages of the manual (for example the table of contents and End of day): readable, page numbers at the bottom. Check that the screenshots still match the screens (Dashboard, End of day, Bulk tools); a mismatch means the manual needs rebuilding (`build/manual/README.md`).
- [ ] **A8c. Code prefix (Phase 15, only if the release that contains it is installed) [acceptance].** *Owner.* QR & Barcodes → Settings: the **Code prefix** field shows `DC`; entering `dur` saves `DUR`, and `D-C` is refused with the old prefix kept. Save a new test product: its code starts with `DUR-`. Scan an existing `DC-` label and the new label with the phone: both open their product and can be sold. Type the old code by hand in the scan box: it opens. Print both on one A4 sheet: the code text wraps after the second hyphen; with barcodes on, the Settings and print setup screens show the barcode warning. Set the prefix back to `DC` (or to the shop's chosen prefix before the first real labels), and delete the test product.
- [ ] **A9. Choose the label stock and, if possible, the printer.** *Owner.* If the stock is not A4 3 × 7, note its sizes for a custom layout (or a new default preset, which needs a small plugin change).

## B. Hosting

- [ ] **B1. Hosting requirements.** *Admin.* PHP 8.2 or newer (8.3/8.4 preferred), with `iconv`, `mbstring`, `mysqli`, `zip`; MySQL 8 or MariaDB 10.6+ with InnoDB; Apache with `mod_rewrite` or nginx with WordPress rewrite rules; single-site WordPress (the plugin refuses multisite); outgoing e-mail working (password resets).
- [ ] **B2. HTTPS.** *Admin.* A valid certificate for the shop's domain (and `www.` if used); every `http://` address redirects to `https://`.
  Check: the browser shows the padlock on the home page, `wp-login.php` and `/scan/`.
- [ ] **B3. Backups on the host.** *Admin.* Daily automatic backups of the database **and** files, kept for at least 14 days, stored away from the server. **Do a test restore of the database** (to a staging copy) once.
  Check: a restored copy opens and shows the latest sales.
- [ ] **B4. Cron.** *Admin.* A real cron job calling `wp-cron.php` (or the host's WP-Cron) so WooCommerce's background jobs run.

## C. Moving the site

- [ ] **C1. Copy files and database.** *Admin.* Files (WordPress, `wp-content` with uploads, themes and plugins; **not** the plugin's `tests/` and `build/` folders: install the plugin from the latest release zip, `product-qrcode-barcode-generator-1.0.1.zip`, instead) and the database. Replace every `http://localhost/sharayu` with the new `https://` address using a tool that understands serialized data (`wp search-replace` or a migration plugin), never a plain text replace in the `.sql` file.
- [ ] **C2. `wp-config.php` for the host.** *Admin.* New database credentials, **new salts** (https://api.wordpress.org/secret-key/1.1/salt/), `WP_ENVIRONMENT_TYPE` set to `production`, `WP_DEBUG` off. Do not set `PQBG_UNINSTALL_DELETE_ALL_DATA`.
- [ ] **C3. Phone-test snippet removed.** *Admin.* The "PQBG phone testing via a Cloudflare quick tunnel" block must not be in the live `wp-config.php`, and remove it from the laptop's too when phone testing is over. Stop any tunnel.
  Check: search the live `wp-config.php` for `trycloudflare`: no result.
- [ ] **C4. Permalinks.** *Admin.* Settings → Permalinks: keep "Post name" (never "Plain"), press **Save Changes** once on the new host.
  Check: QR & Barcodes → Settings → Health check has no "Scan links (permalinks)" error; `https://<shop>/scan/` redirects a logged-out visitor to the login page.
- [ ] **C5. Scan base URL.** *Admin.* QR & Barcodes → Settings → **Scan base URL**: leave empty if the site address is the final `https://` address phones will use, otherwise enter it. Save.
  Check: the example scan address on the Settings page and the Dashboard's "Setup" box start with the final `https://` address; neither the "local address" nor the "https://" warning appears.
- [ ] **C6. Health check and Dashboard.** *Admin.*
  Check: Health check shows no errors (warnings understood); the Dashboard's "Needs attention" is empty.

## D. Cache, staff and the first labels

- [ ] **D1. Cache and CDN exclusions.** *Admin.* In the host's page cache, any caching plugin and any CDN: exclude `/scan/*` (including `/scan/my-sales/`) from page caching, from HTML caching at the CDN, and from CSS/JS optimisation or minification. (The plugin already sends `Cache-Control: no-store` and sets `DONOTCACHEPAGE`, but not every cache obeys them.)
  Check: the response headers of a scan page (browser developer tools → Network) show `Cache-Control: no-store …` and no cache "HIT" header.
- [ ] **D2. Staff accounts.** *Owner.* One account per seller with the **Store Seller** role; shop managers only where needed. **Never share an account.** Remove or downgrade any test users.
  Check: each seller logs in once on their own phone by scanning a label, and sees the product screen.
- [ ] **D3. Phase 12 manual checks on the live site [acceptance].** *Owner + Admin* (the "Phase 12 checklist"), with the production theme, host and cache/CDN settings in place: scan a label on a phone logged out (login page, then the styled product screen with the code box focused), sell, undo, My sales, Log out and Back (the product page does not reappear); a second seller on another phone never sees the first seller's sale page or My sales; the D1 header check; permalinks not Plain and no "Scan links (permalinks)" error in the Health check.
- [ ] **D4. WooCommerce cart/checkout 404 [acceptance].** *Admin.* On this laptop the block and classic cart/checkout pages request `{page URL}/undefinedwc/store/v1/cart` (404), with or without the plugin. Check on the live host with Coming Soon off: open the cart and checkout with an item and look at the browser console / Network tab. Phase 13 looked on a fresh WordPress 7.1.2 + WooCommerce 11.1.2 site on this laptop: in 12 visits of add-to-cart, cart and checkout the 404 appeared **once** (the cart page, plugin active) and not in the other 11 (6 with the plugin inactive, 5 more with it active); in Phase 12 it appeared on this site with the plugin switched off for that request. It is intermittent and seen with and without the plugin, so it does not come from the plugin (a guess, not verified: a WooCommerce cart script reading its Store API address, "undefined", before its settings are loaded). If it shows on the live host and the cart or checkout misbehaves, report it to WooCommerce.
- [ ] **D5. Physical printer test [acceptance].** *Owner* (the "Phase 8 checklist"), when the printer and label stock are chosen, **before printing any real label**: print on plain paper and on the real label stock, check size and alignment (use the printer offset if needed), check ₹ and text, and scan several labels with a phone.
- [ ] **D6. First real labels.** *Owner.* **Only after C5 and D5.** A label contains the address it was printed with, so labels printed before the Scan base URL is final never work. Print a small first batch (one sheet), scan every label with a phone, then print the rest (Bulk tools → Code tools → "Print labels", or the Products list → "Print QR labels"; "one label per unit in stock" for stocked items).

## E. Opening

- [ ] **E1. Coming Soon off.** *Owner.* WooCommerce → Settings → **Site visibility** → Live, and save (in the admin screen, not from the command line).
  Check: a logged-out browser sees the shop, not the Coming Soon page.
- [ ] **E2. Search engines.** *Owner.* Settings → Reading: untick "Discourage search engines from indexing this site" when the shop should appear in search results.
- [ ] **E3. Seller guide.** *Owner.* Send `docs/seller-guide.pdf` to each seller (it fits one page; WhatsApp is fine) and walk through one sale with each of them.
- [ ] **E4. First day.** *Owner.* At closing, open In-store reports → **End of day** and compare "Cash expected in drawer" with the cash counted; check each seller's My sales. Note anything odd for the Admin.
- [ ] **E5. After the first week.** *Admin.* Health check clean; the Dashboard shows no performance message; a backup from the host restored once (B3 done).

---

## Deferred (decide after launch)

- A Hindi and/or Marathi translation of the seller guide and of the staff screens (the translation template `languages/product-qrcode-barcode-generator.pot` is ready). Owner to decide after launch.
- The coding-style cleanup (PHPCS), only if the plugin is ever published on WordPress.org.
- The daily roll-up table or result cache for reports, only when the Dashboard's performance message says so.
