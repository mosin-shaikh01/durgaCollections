# Tests

CLI regression suites for Product QR Code and Barcode Generator (Phases 2–13). They run against a real WordPress + WooCommerce site through `wp-load.php`; there is no PHPUnit.

- **CLI only.** Every PHP file here exits unless `PHP_SAPI === 'cli'`. The `.htaccess` in this directory answers every HTTP request with 403 (Apache). `index.php` stubs stop directory listings on other servers.
- **Never loaded by the plugin.** The autoloader only maps `includes/` and `vendor-prefixed/`.
- **Not for production.** Exclude `tests/` (and `build/`) from any production deployment; see the plugin README.
- **Development sites only.** The suites create and then delete test data: code rows, sales rows, products, users, Action Scheduler jobs and (Phase 7 only) two WooCommerce orders that simulate online checkouts. The lifecycle suite also briefly deactivates and reactivates the plugin.
  - Every suite cleans up everything it creates, even when a check fails, and checks its own cleanup.
  - After every suite, `run.php` runs the **Action Scheduler leak guard** (`as-guard.php`); see [Action Scheduler jobs](#action-scheduler-jobs).
  - The suites refuse to run when `wp_get_environment_type()` is `production`. See [Environment type](#environment-type).

## Suites

| File | Covers | Checks |
|---|---|---|
| `phase2-main.php` | Schema, options, repository invariant, sales table, migrations, install lock, roles and capabilities, HPOS, no endpoints | 83 |
| `phase2-lifecycle.php` | Deactivation, the default (data-preserving) uninstall path, reactivation; since Phase 11 the scan rewrite rules, their flag and `/scan/` over HTTP while active, deactivated and reactivated, and no cron events; since Phase 13 the runtime state the real uninstall removes (Dashboard timing samples, code-generation run state, cost-import previews) is restored byte for byte | 23 |
| `phase2-no-woocommerce.php` | WooCommerce missing, simulated for this process only: no boot, admin notice | 12 |
| `phase3-codes.php` | Code format and alphabet, randomness, collisions, eligibility, authorization, assignment, retirement, database races, batch, scope. **A reconstruction**, see below. Scope checks updated for Phases 5 and 6. | 110 |
| `phase4-rendering.php` | Scan URLs, QR and barcode rendering with round-trip decoding, lazy library loading, SVG safety, base URL validation, the local-address and http:// warnings, settings page access and saving over HTTP, vendor isolation, scope | 165 (5 of them need the decoder) |
| `phase5-admin.php` | Automatic code assignment on every save path over real HTTP (classic edit screen, AJAX variations, Quick Edit, Bulk Edit, REST, Duplicate) and the CSV importer; users without the capability, cron, failure injection; trash, untrash, delete, Empty Trash, type changes; atomic regeneration including a mid-transaction failure and **4 concurrent PHP processes**; history timezone and "retired by" labels; the panel, variations panel, list column, confirmation page and handlers over HTTP; downloads with round-trip decoding; permissions and nonces for every handler; barcodes disabled; the 40-variation performance measurement; scope | 158 (2 of them need the decoder) |
| `phase6-scan.php` | The scan page over real HTTP: rewrite rules, subdirectory, canonical 301s, flush once, deactivation/reactivation, plain and `index.php` permalinks, slug conflicts; every row of the status → screen matrix (including a private variation vs a private simple product, and a trashed parent); price, sale, stock, category, image rendering and live data; HTML escaping; logged-out redirects; login round trips through `wp-login.php` and the My Account form for administrator, Shop Manager and Store Seller (also with Coming Soon on); the byte-identical customer/subscriber 403; entry box normalisation; methods; every security header on every response type; Coming Soon; sitemaps; QR round trip (QrRenderer → decoder → request); scan timing; scope | 214 (1 of them needs the decoder) |
| `phase7-sales.php` | Mark as Sold: the sale form (quantity list with totals, number field above 100, hidden token fields, separate from the code box) for sellers, shop managers and administrators and its absence for view-only users; a sale over real HTTP with every snapshot, the 303 and the success page; quantity bounds; every non-sellable state refused on POST (not just hidden); unmanaged stock, empty and zero price, backorders, stock and price changed since the form was opened; price normalisation; scheduled sale prices; own-stock and parent-level variation stock (decrement **and lock** on the parent); permissions, nonces, signed tokens, expiry; the sale page's 303 for other users; undo over HTTP and in-process (own sale, 9:59 vs 10:01, once, GET, lock, 4 concurrent undos) and `void_sale()`; idempotency (sequential, 4 concurrent processes, after expiry); **8 worker processes selling the last 3** (simple and parent-level stock); the **online race** with a real WooCommerce order from another process; failure injection before, during and after the stock change; the **SQL-override fallback** in both branches and the **WooCommerce compatibility check** on `woocommerce_update_product_stock_query`; stock hooks, stock status, lookup table and low/no-stock notifications; no WooCommerce orders from sales; the v1 → v2 migration on a temporary table prefix; escaping; headers; GET never writes; scope; timings; since Phase 9B the deterministic duplicate-mid-sale checks; since Phase 11 the **minimum stock observed** by a sampler process while the 8 workers sell (simple and parent-level) and the parent-level per-row snapshots | 216 |
| `phase8-printing.php` | Label printing: layout geometry for every preset and custom layouts (slot positions, start-at-N, pages, 26 invalid custom layouts, 14 invalid options); the QR minimum size (module counts from the real encoding for base URLs of 24–100 characters, the planned module size or refusal for every preset, exactly 0.40 mm accepted and 0.01 mm less refused, thermal dot snapping, optional text dropped before refusing); job resolution (variable products expanded, duplicates, every skip reason, copies = N and = stock, the 300-label and 300-product limits, codes never generated); the local-address TEST mark and confirmation, no mark for https, the http warning; fields and code wrapping; retired codes never printed after regeneration; barcodes on/off and library loading; the render cache (hit/miss, TTL, not autoloaded, base URL and barcode-argument invalidation, tampered entries, eviction at 2,000, uninstall); **round trips at printed size** (every label's QR and barcode rasterised at 300 dpi for A4 and 203 dpi for thermal, and at exactly 0.40 mm for V4 and V8 URLs, then decoded); every entry point over real HTTP (panel links, bulk action, setup screen, POST, print page, HEAD, confirmation), nonces bound to the selection and the user, methods, the permission matrix (admin, shop manager, seller, customer, subscriber, logged out), GET never writes, escaping, headers and CSP; **headless Chrome and Edge** (see below); the 100/300-label timings; scope | 168 (4 need the decoder; the browser checks need the decoder, the print-check package and Chrome/Edge) |
| `phase9a-sales-history.php` | Sales history, payment method and cost price: the v2 → v3 migration on a temporary table prefix and the `pqbg_view_costs` capability; the payment-method setting (sanitising, the settings page over HTTP); the sale form (required radio group, nothing pre-selected, the single-method case) and every refusal (missing, invalid, disabled, disabled after the form was opened) with the form kept; idempotency with a changed method; the method, cost and seller name already in the **pending** row; cost validation (17 cases), inheritance, saving in-process and over HTTP (classic form, variations AJAX) and never by shop managers; the cost snapshot at the moment of sale and after later edits; **cost never exposed** (a planted value searched in the shop manager's edit screen and variations AJAX, WC REST v3 products/variations GET and PUT/POST, Store API, storefront, the product CSV export and import, Duplicate, **WXR export** as shop manager and administrator, **WXR import** with the official WordPress Importer, scan and sale pages, labels, the history and CSV for shop managers, My sales); the write/delete guards and every deletion path (permanent delete, trash, variation removal, variable → simple) with no orphaned meta; seller name snapshots and deleted users; site-timezone date ranges (IST day boundaries), filters, search, sorting, pagination and totals/profit with unknown costs on a fixed dataset; the history screens, detail and menu position over HTTP; the CSV (BOM, columns by capability, formula injection, headers, nonce, methods); void over HTTP (reason, restock on/off, the lock held by another process, stock tracking off, second void, permissions); My sales (own sales only, ranges, summary against an independent query, canonical URLs, methods, 403s); the permission matrix; GET/HEAD never write; **50,000-row performance** with EXPLAIN and keyset-export order checks; scope | 253 (1 needs `PQBG_WXR_IMPORTER`) |
| `phase9b-reports.php` | In-store reports and the owner dashboard: schema v4 (`void_restock`) and `migrate_4` on a temporary prefix, the flag from real voids and an undo; periods (presets identical to the history's, comparison to the same point in time, month lengths and a leap year, week start, buckets for hour/day/week/month); **every counting rule on a fixed week with hand-computed values** (completed vs voided vs failed, unknown costs, margin on known-cost revenue, a deleted product and a deleted seller, a product in two categories and a sub-category) and reconciliation with the Phase 9A totals; **18:29:59 / 18:30:00 UTC boundaries** for every grouping, the peak grid and the end of day; the peak grid and busiest summary; **end of day with net collected** (refunds of earlier sales, a same-day void not subtracted twice, per seller, "voided today by", the CSV); stock (thresholds own/parent/store, shared stock with one or mixed prices, below zero, values at price and cost), missing codes, **dead stock** (30/60/90/never, new items, real WooCommerce orders in five statuses), slow sellers; CSV rules; SVG charts (well-formed, escaped, accessible); **every tab, CSV and the print page over real HTTP** for administrator, shop manager, seller, customer and logged out, with no cost/profit/margin anywhere for the shop manager and 403 on cost requests; escaping; print-page headers and CSP; GET/HEAD never write; **performance** at 5,000 sales (the original targets, in-process and over HTTP) and, with `PQBG_STRESS=1`, 50,000 sales (a 2 s regression guard) with EXPLAIN; scope; cleanup | 146 (149 with `PQBG_STRESS=1`) |
| `phase10-bulk.php` | Bulk and CSV tools: the QR & Barcodes tabs per capability; **which items qualify** for missing-code generation (every status, trash, auto-draft, importing, grouped, external, a variation under a simple product, an orphan variation, enabled and disabled variations, a variable product without variations, an item with a code, an item with only a retired code) with the counts per type and status; generation in batches, idempotency, stop / resume / dismiss, stale and abandoned runs, a shop manager's run; **a run started or dismissed by another process is seen** (`state()` cache); **a worker process that dies right after its 3rd code** and the run that continues; **two workers at once** on one run (exact counts, no duplicates); print links (300 per link, the exact item IDs in the setup screen's hidden field); the **codes CSV** (header, columns, filters, retired and deleted items, missing items, attributes with and without WooCommerce's stored summary, formula injection for `=` `+` `-` `@` tab CR, SKUs byte for byte, Devanagari and ₹, no cost); the **cost import** (34 number cases, file rules and limits, header rules, 19 matching/outcome cases, preview writes nothing, acknowledgement, apply in chunks, "changed since preview", "already", resume, re-apply, token bound to the user, expiry and pruning of an expired preview holding a cost, the report, the template and its round trip); the audit log (who sees which entries; an entry written meanwhile by another process is kept); **every tab and handler over real HTTP** for an administrator, a second administrator, a shop manager, a seller, a customer and logged out (multipart uploads, 403 with an administrator's nonce/token, the uploaded file removed from PHP's temporary folder, nothing in `uploads/`); GET never writes; the **2,000-item volume timings** and the volume part's PHP peak memory; scope; cleanup | 197 |
| `phase10b-menu.php` | The plugin's own menu and Dashboard (Phase 10B): the menu definition and the old-address map in-process; **the menu tree per role over real HTTP** (administrator, shop manager, Store Seller, customer, logged out): QR & Barcodes directly below Products, the grid icon, the sub-items and labels in order, nothing under WooCommerce, the hidden Products screens still hidden, the only plugin links in the sidebar; every page per role; **the shared tab row** (one tab per page the user may open, in order, links from `AdminUrl`, the current page marked) and the page-internal second row; **highlighting** on sale detail, void, report tabs, Bulk tools tabs and "Settings saved"; Summary replaces the reports' Dashboard tab (`&tab=dashboard` still opens it; no CSV); **every old address** redirected (302, GET and HEAD) with its query arguments, never for a role that may not open the target, never for POST, logged out via the login page; In-store sales / reports addresses with their arguments unchanged; **403 for the wrong role** on every page and on all 15 `admin-post.php` handlers (seller, customer; the 4 cost handlers for the shop manager); **links** in the scan-URL notice, the Summary alerts, the product panel, the products-list bulk action, the print setup, the end-of-day print page, sale detail and void (and the void handler's redirect), a Bulk tools error page and the Dashboard quick links; no CSV contains an admin URL; **Dashboard figures** equal Summary (today) and the Phase 9A totals (hand-computed fixture deltas, per payment method, voids, unknown cost), no cost/profit for the shop manager, the missing-code count and its link, attention items (local URL, a run in progress / stopped) and the bulk log per role; GET/HEAD never write; accessibility markup (one h1, a labelled tab row, labelled sections, no empty link, no outline removal, narrow-screen rules); **Dashboard timings** at 5,000 sales (under 1 s; 50,000 with `PQBG_STRESS=1`, under 2 s); **scope**: no `admin_url()` or hand-built admin URL outside `AdminUrl`, page slugs only in `AdminUrl`, no hard-coded screen ID in PHP/CSS/JS, menu registration only in `AdminMenu` (plus the two hidden Products screens), only `wp_safe_redirect` in known places, the new classes read-only; cleanup | 92 (94 with `PQBG_STRESS=1`) |
| `phase11-hardening.php` | Hardening (Phase 11): **requirements** for every branch with given versions (PHP, WordPress, WooCommerce missing or 8.9, multisite) and WooCommerce 8.9 in another process (a stub): no boot; the **health check** with each check planted and the clean control (negative stock on an item and on a parent holding a variation's stock, a completed sale without `stock_after`, pending 16 vs 14 minutes, codes on a grouped product / a non-variable parent / a missing item / a page / an auto-draft, a trashed item as information, five kinds of bad cost meta and a valid one, a sale of a deleted item, a schema version from a filter), the invariant checks on a temporary prefix without constraints, the tab over HTTP for five roles, links, no cost value, 404 for unknown tabs, GET/HEAD never write, the Dashboard line per role; the **Undo button** hiding itself (the nonce'd style element, the delay equals the seconds left, the CSP allows exactly that nonce, a fresh nonce per response, no refresh, 590 s and 601 s after the sale, a late Undo refused with 409 and nothing changed, other pages without a nonce); **BulkLog** under its lock (8 processes at once, a lock held elsewhere); the **performance signal** (repeated slow renders, 300 vs 300.03 sales a day, samples kept, at most one write per 60 s, the Dashboard per role); **CSV formula neutralisation** (21 cases, the importer's round trip, the CSV over HTTP); **migrations** from real v1, v2 and v3 tables with data to v4 on a temporary prefix through `maybe_upgrade()` (the live version option untouched); **uninstall**, both branches, executed on a cloned temporary prefix (options, user meta, post meta, tables, roles and capabilities compared before and after); **time and money** (midnight in Asia/Kolkata, a later timezone change, UTC+5:30, DST days in Europe/London, paise against MariaDB's DECIMAL sum, very large amounts, plain CSV numbers); **malformed input** on the 15 handlers, the 5 pages and the scan routes (never a 500); health check **timings** at 5,000 sales and 2,000 coded items (50,000 with `PQBG_STRESS=1`); **scope** (the sale path and Schema byte-identical to Phase 10B, the new classes add no hook and never write except the timing sample); cleanup | 130 |
| `phase12-themes.php` | Theme compatibility (Phase 12): under **six themes** activated with `switch_theme()` (Twenty Twenty-Five, Twenty Twenty-Four; Storefront, Astra, Kadence, OceanWP, unzipped from their wordpress.org packages): every scan screen over HTTP (the status `ScanRoute::decide()` decides, the security headers, only the plugin stylesheet, no script, nothing from the theme) and in **headless Edge and Chrome** (no console/page errors, failed requests or CSP violations; no horizontal scroll at 375/320/1280 px; touch targets at least 44 × 44 px; the code box focused and a scanner's code + Enter; product images inside the viewport; computed styles **identical** to Twenty Twenty-Five's, and phone/desktop screenshots with the same hash or, when not, the same size with at most 0.01% of pixels differing (compared with GD); the login round trip; Back after Log out); the sale page with Undo; logged-in 405 and logged-out 302 for any method; the plugin's admin pages for administrator and shop manager and the menu position; the theme's **store pages** (home, shop, products, block and classic cart and checkout with an item, My Account, Coming Soon) over HTTP and in Chrome with the plugin on and **off for that request** (no plugin asset or markup; no browser error the plugin adds); no plugin PHP notice (error capture); **permalinks**: six structures saved through the real Settings → Permalinks form (URLs unchanged, the `/blog/` prefix, the notice for administrators and shop managers, the Dashboard warning, the Health check error for Plain and `index.php`, the rules restored); a **page cache** (WP Fastest Cache 1.5.2, under Twenty Twenty-Five and Storefront: two sellers and a logged-out visitor on the same URLs, logged-out PUT/DELETE/OPTIONS, no cache file for any `/scan/` path, the control page cached, `wp-config.php` untouched); **scope** (the cache constants, no theme names or theme checks in plugin code, no hook into theme, store-page or email output); the **restore guard** (see the Phase 12 suite notes) | 274 (all need the tools in the Phase 12 suite notes) |
| `phase13-release.php` | Release checks (Phase 13), reading files only: the version (header, `PQBG_VERSION`, `readme.txt`, `CHANGELOG.md`, the .pot) and the requirement fields (header, `readme.txt`, `Requirements`) agree, "Tested up to" is what this site runs; licences (the header, `LICENSE`, each bundled library's licence file against `composer.lock`, the GPL-3.0 text next to the LGPL-3.0 library pinned in `build/build.php`, `NOTICE.md`); a direct-access guard in every runtime PHP file and a silence `index.php` in every runtime folder; no debug call, no TODO/FIXME; every translation call with the plugin's text domain; **the .pot up to date** (regenerated with WP-CLI); the owner guide, the built seller guide (self-contained, matches its template) and its one-page PDF; `build/package.php --dry-run` (its own checks; exactly the runtime files, no development file); no shipped text file names the shop | 28 (1 needs `PQBG_WPCLI`) |
| `phase14-manual.php` | The user manual and help links (1.0.1): `docs/user-manual.pdf` (a PDF of at most 4 MB; the version in its title, on the cover and in every footer; every chapter and section a bookmark; the table of contents' page numbers equal to the pages the headings are really on; the "Administrator only" badges; one image per screenshot; no real site or administrator name, read with pdfjs-dist), `docs/user-manual.md` (chapters, badges, screenshots used once; every bold screen name a plugin string from the .pot or a known WordPress/WooCommerce label), the owner guide gone, the build files and their exact package pins; `AdminUrl::user_manual()` / `seller_guide()` and no `docs/` address outside `AdminUrl`; over HTTP both PDFs 200 `application/pdf` with the file's bytes (logged out and in); the "Plugin guide" button, its tooltip and the seller guide link on the Dashboard for an administrator and a shop manager (exact markup, no `title`), never for a Store Seller or a visitor, the script on the Dashboard only; "User manual" in this plugin's row on the Plugins screen only; "How to sell (guide)" on My sales only; the scan pages' security headers sent exactly as `ScanRoute::security_headers()`, whose source (with `csp()`) is byte-identical to the tagged 1.0.0 file; in headless Chrome (`tests/manual/help-check.mjs`): Tab reaches the button, visible focus, the tooltip on focus and hover (also on the tooltip itself), Escape hides it, Enter opens the manual in a new tab, no console error; the .pot has the new strings. Needs `PQBG_MANUAL_TOOLS` and `PQBG_THEMECHECK`. Creates three users and one product; restores the plugin's options byte for byte (the Dashboard records timing samples). |
| `phase15-prefix.php` | The configurable code prefix (Phase 15, not yet released): the Settings rule (2–4 characters, A–Z and 0–9, a letter first, lowercase uppercased; invalid input keeps the previous prefix with an error; a bad stored value or no key gives `DC`), the field for administrators only (`pqbg_manage_settings`), the barcode warning on Settings and the print setup screen; new codes use the prefix; `CodeGenerator::is_valid_format()` accepts every 2–6 character prefix whatever the setting; the scan route with an old-prefix and a current-prefix code (both open their product, an unknown code is 404, the entry box, the logged-out login redirect with no existence check); label layout for longer codes (barcode width per character, omitted for width on A4 3 × 7, kept on 100 × 50, the wrap after the second hyphen); no `DC-` outside comments in plugin code. In-process only. Creates one administrator and two products; restores `pqbg_settings` byte for byte. |

Each suite ends with a check that plugin code raised no PHP notices, warnings or deprecations. That check is included in the counts.

**1.0.1: WooCommerce lookup-table leak guard (a gap found 2026-09-28).** WooCommerce keeps a deleted product category's rows in `wp_wc_category_lookup`. The suites that create product categories (`phase6-scan`, `phase9b-reports`, `phase12-themes`) and the two document builders (`guide-screenshots.php`, `build/manual/build-manual.php`) deleted their categories but not those rows, and neither guard looked at that table: 87 orphaned rows had built up over earlier phases, and the first 1.0.1 manual build added 6 more (found by the build's own row-count check). All of them (92 after the killed second build's categories were removed) were deleted with the owner's approval on 2026-09-28; the table then held 0 orphaned rows. The fix:
- `bootstrap.php`: `pqbg_test_catlookup_mark()` (the highest term ID and the orphan count at the start), `pqbg_test_catlookup_cleanup()` (deletes only the orphaned rows of categories created after the mark) and `pqbg_test_catlookup_check()` (fails when there are more orphaned rows than at the start). The three suites and both builders call them in their cleanup, after the categories are deleted.
- `lookup-guard.php`: `run.php` counts the orphaned rows before and after every suite, next to the Action Scheduler guard, and fails the suite ("lookup guard FAIL") when the count grew, so a new suite that forgets it cannot leak unnoticed.
- An orphaned row is one whose `category_id` or `category_tree_id` no longer exists in `wp_term_taxonomy`.
- **Also `wp_wc_product_meta_lookup`** (added the same day, the owner's request): `lookup-guard.php` counts its rows whose `product_id` has no matching post (`pqbg_test_prodlookup_orphans()`), and fails the suite when that count grew too. Its mark is now "category,product" (e.g. `0,0`). WooCommerce removes a product's row when the product is deleted through its own API; the killed manual build left one such row (post 120414, removed with the owner's approval).

**Phase 13 changes to earlier suites.** No check became false by design (no feature, schema, capability, option or sale-path change; the version bump to 1.0.0 is read through `PQBG_VERSION` everywhere). One cleanup fix, approved with the plan:
- `phase2-lifecycle.php` runs the real default uninstall on the site, which always removes runtime state: before Phase 13 it left the site without its Dashboard timing samples (`pqbg_perf_samples`), a code-generation run state (`pqbg_bulk_run`) and every user's cost-import preview (user meta `pqbg_cost_import`) if they existed. It now saves them before and restores them byte for byte after reactivation, with a new check (23 checks). Verified with a planted preview and the site's own timing samples: both identical afterwards. The render cache stays cleared, as a real uninstall leaves it.
- `phase8-printing.php` also runs the real default uninstall (to check that it clears the render cache) and put back only the rewrite flag; found by the final Phase 13 run, when the timing samples were missing again. It now restores the rewrite flag, the timing samples, the run state and the cost-import previews byte for byte, with a new check (168 checks). Verified the same way (planted samples and preview, phase8 alone: identical afterwards).

**Phase 13 suite notes (`phase13-release.php`).** Reads files and runs two tools; creates no data. Needs `PQBG_WPCLI` (WP-CLI's `wp-cli.phar`, kept outside the site; on this machine `C:\xampp\tools\pqbg\wp-cli\wp-cli.phar`, WP-CLI 2.12.0, SHA-512 checked against the release's published checksum) for the .pot check; runs `build/package.php --dry-run --allow-dirty`. The "shop name" check searches every shipped text file for this site's title; the guide screenshots are images, so the guide tool shows "Your shop" in their header instead.

**Seller guide tool (`guide-screenshots.php`, Phase 13; not a suite, not in `run.php`).** `php tests/guide-screenshots.php` rebuilds `docs/seller-guide.html` and `docs/seller-guide.pdf` from `tests/guide/seller-guide.template.html`: it creates fictional sample data (a Store Seller "Asha", a cotton kurta and a silk dupatta in two "(sample)" categories, one earlier sale through `SaleService::sell()`), logs in as the seller in headless Chrome at phone width (375 × 667 at 2×, `tests/guide/guide-shots.mjs`), takes four screenshots (product screen, quantity and payment chosen, the sale page with Undo after a real sale over HTTP, My sales), inlines them, prints the A4 PDF, and removes every row it created (checked, with the Action Scheduler guard). Needs `PQBG_THEMECHECK` (it borrows that tool's puppeteer-core) and Chrome or Edge; `PQBG_GUIDE_SHOTS` keeps a copy of the PNGs. Take a backup first, as for the suites. Rebuild the guide whenever the scan screens or the template change; the release suite fails if the built guide no longer matches its template.

**Phase 12 changes to earlier suites.** Phase 12 was approved (the owner's addition to D7) to show the permalink notice also to users with `pqbg_manage_codes` (they print labels), and to add a Health check error for Plain / `index.php` permalinks. These checks became false by design; each still checks "nothing else, plus exactly the approved change":
- Phase 6: "admin notice for pqbg_manage_settings only" is now "for pqbg_manage_settings and pqbg_manage_codes (shop manager), not for sellers" (Plain and `index.php`). The slug-conflict notice is unchanged (administrators only).
- Phase 11: "all nine checks run" is now "all ten checks run": `permalinks` (error; 0 found on the test site) after `schema`, in the full set and in the Dashboard set; and the Health check tab over HTTP has ten sections instead of nine.
- No other suite changed. The logged-out redirect for every method (the D6 fix) contradicted no existing check: every 405 check in Phases 6 and 7 is made logged in.

**Phase 12 suite notes.**
- **Tools, all required.** A missing one fails the "tools" checks, and the suite stops before touching the site; nothing is skipped:
  - Node.js;
  - `PQBG_THEMECHECK` = `tests/theme-check/check.mjs` installed with `npm ci` (puppeteer-core only; it drives the installed Edge and Chrome, like print-check);
  - `PQBG_THEME_PACKS` = a folder with `storefront.4.6.2.zip`, `astra.4.14.0.zip`, `kadence.1.5.2.zip` and `oceanwp.4.2.6.zip` (from `downloads.wordpress.org/theme/`);
  - `PQBG_CACHE_PLUGIN` = `wp-fastest-cache.1.5.2.zip` (from `downloads.wordpress.org/plugin/`);
  - `PQBG_ERROR_CAPTURE`, with the logger in `wp-content/mu-plugins/` (as `run.php` sets it up);
  - optional `PQBG_SCREENS` (screenshots; default: the system temp folder).
- **What it changes and restores.**
  - It unzips the four themes into `wp-content/themes/` and the cache plugin into `wp-content/plugins/`, and activates them.
  - It writes a temporary must-use file `pqbg-phase12-plugin-off.php`: a request carrying a random header runs without this plugin (the "plugin off" half of the store-page comparison; nothing is written).
  - It changes permalinks through the form, and lets WooCommerce regenerate the placeholder image sizes after each theme switch.
  - The cleanup switches the theme back and restores **every option byte for byte** (anything changed that is not a runtime option: cron, transients, Action Scheduler locks). It deletes the options created by the theme switches, the test themes, the cache plugin and the suite's own Dashboard visits (listed with reasons in the code). It puts back the placeholder files and metadata and `.htaccess` byte for byte, and removes the folders, the must-use file, posts, terms, users, codes, sales, WooCommerce sessions and the Action Scheduler jobs it caused.
- **Restore guard** (the last checks): the original theme is active; every option is identical to the start and none is left behind; the theme state recorded before Phase 12 (`C:\xampp\backups\sharayu\phase12-theme-snapshot-before.json`, when present) is intact; posts (global styles, navigation, pages) are identical; so are the placeholder files and metadata and the theme, plugin, wp-content, uploads and must-use folders; `.htaccess` is identical and `wp-config.php` never changed; users, codes, sales and sessions, permalinks and `/scan/` are back.
- **If a run is killed** before its cleanup (the stop file does not do this; it runs the cleanup): `php tests/phase12-repair.php` lists what differs from the start state the suite saved in the system temp folder (`pqbg-phase12-start.ser`, deleted by a finished cleanup). `--apply` restores it; run it again afterwards to confirm nothing is left.
- **Details found while writing the suite:**
  - WooCommerce's block cart and checkout log `…/undefinedwc/store/v1/cart` 404s in the browser on this site, with and without the plugin. They are reported as INFO, not failed.
  - Screenshots hide the text caret: it blinks, so otherwise identical screenshots differed by a 1-pixel line. What remains between renders of the same page is Chromium's anti-aliasing of a border edge (about 30 pixels, channel difference under 50), hence the 0.01% tolerance; the computed-style check stays exact.
  - Chromium reports a document's own 4xx status as a console error; the tool keeps that apart for the intended 400/403/404 scan pages.
  - The permalink form's POST alone leaves the `.htaccess` that Plain wrote (without WordPress's rewrite block); the page it redirects to rewrites it, so the suite opens that page as a browser does.
- **Power:** keep the laptop on mains power with the lid open. A closed lid put the machine to sleep during a development run, which was not counted.

**Phase 11 changes to earlier suites.** Phase 11 was approved to add a Dashboard timing sample (the performance signal, D9 as changed by the owner: one small option written on every Dashboard render) and to let a sale page with an Undo form carry one nonce'd style element (D7 as changed by the owner). These checks became false by design; each still checks "nothing else, plus exactly the approved change":
- Phase 3: "only the pqbg options exist" also allows `pqbg_perf_samples`, which exists once the Dashboard has been opened.
- Phase 7: "security headers" on the sale-success page (and every Phase 7 response type): a page that has the Undo form carries one `<style>` element with this response's nonce, and its `Content-Security-Policy` is `ScanRoute::csp()` with that nonce; every other header, and the CSP of every other response, is exactly as before. And the scope check "sales rows are written only by SaleRepository" also allows the new read-only `HealthCheck` and `PerfSignal` to name the sales table, and checks that they contain no `$wpdb` write (as for `SalesQuery` and `ReportsQuery`).
- Phase 10: "uninstall: previews and the run state always removed; the log only with delete-all" compares positions instead of requiring the delete-all guard right after the run state: the Phase 11 runtime state (timing samples, save-failure notices) is also removed there, before the guard.
- Phase 10B: "GET and HEAD never write" leaves `pqbg_perf_samples` out of its options checksum, and a new check requires that the only write is the Dashboard's timing sample, throttled: all the Dashboard renders of that loop together write exactly one sample (at most one write per 60 s), not autoloaded; the option is restored byte for byte in the cleanup. 92 checks (94 with `PQBG_STRESS=1`).

**Added in Phase 11 (not false by design):**
- **Every suite honours `PQBG_STOP_FILE`** (the owner's change 4). `tests/bootstrap.php` has `pqbg_test_stop_point()`: when the file exists, the next safe point throws `PqbgTestStop` once, so the suite's `finally` cleanup still runs; every `pqbg_section()` heading is a safe point, and the long loops (the 9A 50,000-row insert, the 9B/10B stress data and item builds, the Phase 10 volume part, the Phase 11 volume data) call it too. From the section titled "cleanup" on, stop points do nothing, so a cleanup is never interrupted. A stopped suite prints `STOPPED` and `RESULT: …; STOPPED` and exits 3; `run.php` reports it as STOPPED (never a pass). `phase2-main.php` now runs its body in `try`/`finally` (not re-indented) with idempotent repairs in its cleanup (the schema version option, the install lock, the canary capability, the test user); `phase2-lifecycle.php` has one safe point before it creates anything. Phases 10 and 10B use the shared helper instead of their own. Verified by stopping phase2-main (at its first section), phase6-scan (no catch block, after 40 s) and phase7-sales (with a catch block, after 60 s): each reported STOPPED, ran its cleanup and left the site clean.
- Phase 2 lifecycle (D16): +5 checks (the rules, the flag and `/scan/` over HTTP active, deactivated and reactivated; no cron events).
- Phase 7 (D6): +3 checks (a `sample` worker records the minimum stock during both 8-worker tests; the parent-level rows' `stock_before` 3, 2, 1 and `stock_after` 2, 1, 0).

**Phase 11 suite notes.**
- Creates 4 users, 9 products and variations, a page and an auto-draft, fixture sale rows (marked `pqbg-11-fixture` / `pqbg-11-perf`), raw planted post meta (removed by meta ID) and, for the timings, 2,000 synthetic coded products written directly with SQL (no hooks; removed the same way) and 5,000 synthetic sales (50,000 with `PQBG_STRESS=1`). Temporary table prefixes `pqbg11m_`, `pqbg11f_`, `pqbg11i_` and `pqbg11u_` are dropped at the start (leftovers) and at the end. The settings, the bulk log, the timing samples, the run state, the render-cache index and the schema version option are restored byte for byte.
- Worker modes: `--worker wcold` (WooCommerce 8.9 stub), `holdlog` (holds the bulk-log lock), `uninstall <prefix> <0|1>` (runs `uninstall.php` for real against a cloned prefix; the shutdown hooks are removed first so nothing else touches the clone), `bulklog` (adds an entry at a common start time).
- The migration fixtures are the `CREATE TABLE` statements of the committed Schema at `5f301be` (v1, after the rename), `2413698` (v2) and `5495b05` (v3); `pqbg_codes` never changed. The live `pqbg_db_version` option is never written: `pre_option_` / `pre_update_option_` filters keep the version in memory while `Install::maybe_upgrade()` runs against the temporary prefix.
- The timezone cases use `pre_option_timezone_string` / `pre_option_gmt_offset` filters (nothing is written).

**Phase 10B changes to earlier suites.** Phase 10B was approved to move every plugin screen into the plugin's own top-level menu "QR & Barcodes" (Dashboard, In-store sales, In-store reports, Bulk tools, Settings), to split the Phase 10 page into Bulk tools and Settings (old addresses redirect), to rename In-store reports' "Dashboard" tab to "Summary", to build every admin URL through `AdminUrl`, and to make the 50,000-sale stress data opt-in. These checks became false by design; each still checks "nothing else, plus exactly the approved change":
- Phase 4: "admin: menu item under WooCommerce" → the Settings item is in the QR & Barcodes menu (`#toplevel_page_pqbg-dashboard`). "shop manager: the page opens on Code tools … the Settings tab URL is refused (403)" → `admin.php?page=pqbg-settings` redirects the shop manager (302) to Bulk tools, which opens on Code tools with no settings form and no Settings tab; `&tab=settings` is still 403. "shop manager: WooCommerce menu visible, the QR & Barcodes item … but no warning" → the QR & Barcodes menu has Bulk tools and no Settings link, still no warning. "admin hooks are not registered outside wp-admin" also checks `AdminMenu::add_pages`.
- Phase 9A: "menu: WooCommerce → In-store sales, right after Orders" → QR & Barcodes → In-store sales, right after the Dashboard sub-item (the block starts with the top-level link, which also points at the Dashboard), and no In-store sales link under WooCommerce. (The shop manager's `admin.php?page=pqbg-settings` is now a 302 to Bulk tools; "cannot open the Settings tab (403) or see the payment-method fields" still passes unchanged.)
- Phase 9B: the report tab list uses `summary` instead of `dashboard` (every tab, CSV and GET-never-writes loop; `&tab=dashboard` still opens Summary and is tested in the 10B suite); the menu check's wording (the adjacency In-store sales → In-store reports is unchanged and still passes); the hooks scope check: ReportsAdmin now adds 3 actions (admin_enqueue_scripts and two admin_post handlers) instead of 5, the menu and the `load-` hook are registered by `AdminMenu`. **Performance:** the 50,000-sale checks (fill, in-process 2 s guard, HTTP 2 s guard) run only with `PQBG_STRESS=1` (owner's decision: in Phase 11 and before launch); a new default check times the six heaviest pages over HTTP at 5,000 sales against the original targets (under 1 s for 90 days, under 2 s for 12 months, added to an empty wp-admin page). EXPLAIN and the reconciliation run on whichever volume is loaded. 146 checks by default, 149 with `PQBG_STRESS=1`.
- Phase 10: `$tab_url` builds from `AdminUrl` (Settings → `page=pqbg-settings`; Code tools / Import cost prices → `page=pqbg-bulk-tools&tab=…`), and the print link uses `AdminUrl::print_setup()` (was `PrintAdmin::setup_url()`). "administrator, no tab: the Settings tab with all three tab links" → Bulk tools without a tab opens Code tools with both tab links, and Settings is its own page with the form. "shop manager: the WooCommerce menu has the QR & Barcodes item" → the QR & Barcodes menu has Bulk tools and no Settings. "seller: no QR & Barcodes menu item" also checks the Bulk tools and Dashboard slugs. "tabs: administrator Settings | Code tools | Import cost prices" → Code tools | Import cost prices (`ToolsAdmin::tabs()`; Settings is its own page). "shop manager: the Settings tab URL is refused (403)" → the old `&tab=settings` address is still 403, and the plain Settings address redirects the shop manager to Bulk tools (302, the approved D8 behaviour). The hooks scope check finds the Bulk tools `load-` hook in `AdminMenu` (was `SettingsPage`).
- Phase 2 (no WooCommerce): "settings page not registered" also checks that the QR & Barcodes menu is not registered.
- Unchanged and still passing: Phase 5's Regenerate links (`page=pqbg-regenerate`) and Phase 8's print setup links (`page=pqbg-print`): those hidden screens stay under Products with the same addresses.

**Phase 10B suite notes.**
- Creates 4 users, 2 products, 6 fixture sale rows (marked `pqbg-10b-fixture`) and, for the timings, 1,000 sellable items (300 simple, 70 × 10 variations, saved by an administrator so they get codes) and 5,000 synthetic sales over 90 days (marked `pqbg-10b-perf`; 50,000 with `PQBG_STRESS=1`). Everything is removed; the settings, the run state, the bulk log and the render-cache index are restored byte for byte.
- The menu is read from the rendered `#adminmenu` (top-level item IDs in order, the QR & Barcodes sub-items and their labels, the highlighted item); the shared tab row from `nav.pqbg-plugin-nav`.
- A shop manager asking for `admin.php?page=pqbg-settings` gets the D8 redirect to Bulk tools (302), because that is also the old no-tab address; `&tab=settings`, unknown tabs and Import cost prices are 403.
- Honours `PQBG_STOP_FILE` like the Phase 10 suite, and removes the lookup jobs queued by its own product saves before timing. It prints `TIMING Dashboard …` lines.
**Phase 10 changes to earlier suites (D16).** Phase 10 was approved to put the bulk tools in tabs of WooCommerce → QR & Barcodes, with the page open to `pqbg_manage_codes` (Code tools) and the Settings tab still `pqbg_manage_settings`, and to add two options (D14). These checks became false by design; each still checks "nothing else, plus exactly the approved addition":
- Phase 4: "shop manager: direct URL refused (403), no form" → the page opens on Code tools (200) with no settings form, no `option_page` field and no Settings tab link, **and `&tab=settings` is refused with 403**. "shop manager: … no settings item" → the WooCommerce menu has the QR & Barcodes item (Code tools), still with no scan-URL warning. The `options.php` checks (the shop manager's valid nonce refused with 403, nothing saved) are unchanged and still pass.
- Phase 9A: "settings page: shop manager cannot open or save it" (was: not 200) → `&tab=settings` is refused with 403 and the shop manager's page has no payment-method fields.
- Phase 3: "only the pqbg options exist" also allows `pqbg_bulk_log` and `pqbg_bulk_run`, which exist once the bulk tools have been used (the suites restore them, so on a clean site the list is unchanged).
- Unchanged and still passing: Phase 5 "no raw writes outside …" (the new classes use no raw `$wpdb` writes), Phase 9A "the cost meta key appears only in CostPrice and uninstall.php", Phase 8's uninstall-order check, and the Phase 2 lifecycle's default uninstall (which now also removes the Phase 10 runtime state: previews and the run state).

**Phase 10 suite notes.**
- Fixture products are created as user 0 (no `pqbg_manage_codes`), so saving them assigns no code; it creates about 2,100 products and variations (2,000 for the volume timings), 5 users, and a few worker processes (`--worker crash|gen|start|dismiss|log`). Everything is removed, and `pqbg_bulk_run`, `pqbg_bulk_log` and the render-cache index are restored byte for byte; render-cache entries added by opening the print setup screen are removed.
- The HTTP helper flushes the suite's object cache after every request, so in-process reads after an HTTP change come from the database.
- **Cooperative stop:** when `PQBG_STOP_FILE` names a file that exists, the suite throws at the next section or loop step, so its cleanup in `finally` still runs. A runner can create that file when the machine runs low on memory (see [Running](#running)).
- Before timing, it removes the WooCommerce lookup jobs queued by its own product saves (as in Phase 9B). It prints `TIMINGS:` (ms, plus the volume part's PHP peak in MB via `memory_reset_peak_usage()` / `memory_get_peak_usage()`); asserted: counts < 1 s, each batch of 100 < 5 s, all 2,000 codes < 60 s, the codes export < 5 s, cost preview < 5 s, cost apply < 20 s, volume peak < 256 MB.

**Phase 9B changes to earlier suites.** Phase 9B was approved to add schema version 4 (`void_restock`, D5), a read-only reports query class, and to fix a Phase 7 race (D6), so these checks became false by design or were added; each still checks "nothing else, plus exactly the approved addition":
- Phase 2 main: the `pqbg_sales` column list ends with `void_restock` (still 10 indexes).
- Phase 7: the v1 migration fixture also removes the v4 column; the live table has 31 columns (was 30); the scope check "sales rows are written only by SaleRepository" also allows `ReportsQuery` to name the sales table, and checks that it contains no `$wpdb` write. **New (D6):** a `finish` worker (holds the stock lock, then completes a pending row) and three deterministic checks: a duplicate submission that finds the row still pending waits for the lock and gets the same completed sale (it failed on the pre-fix code: "waited 0.00 s; failed"); a pending row whose process died answers "failed" at once; another seller's replay is refused without waiting. 213 checks.
- Phase 9A: "DB_VERSION is 3" → at least 3 (4 since Phase 9B); `migrations()` starts with 1–3; the v3 columns are checked right after `failure_code` (the v4 column follows); the v2 migration fixture also removes the v4 column.

**Phase 9B suite notes.**
- Fixture sale rows are inserted directly into `pqbg_sales` (marked in `note`) for exact dates and statuses; real sales, voids and an undo go through `SaleService`. It creates about 1,100 products and variations (1,000 of them for the timings), a few categories and users, and five real WooCommerce orders (processing, completed, on hold, cancelled, pending) for the dead-stock check; all are removed, with their order notes and any Analytics lookup rows, and the options it touches (week start, stock thresholds, the category hierarchy cache) are restored byte for byte.
- Before timing, it removes the WooCommerce attribute-lookup jobs queued by its own product saves (otherwise Apache's queue runner processes hundreds of them during the measurements).
- It prints the in-process timings of the dashboard and every report at 5,000 and at 50,000 sales, the HTTP medians at 50,000 and the EXPLAIN plans. Asserted: at 5,000 sales in 90 days, under 1 s (90 days) and under 2 s (12 months) in-process; at 50,000 sales, under 2 s for both ranges in-process and under 2 s added to an empty wp-admin page over HTTP (a regression guard: the dashboard's 1 s target is not met at that volume; see the plugin README).

**Phase 9A changes to earlier suites.** Phase 9A was approved (D13) to add schema version 3, a capability, a setting and a required payment method, so these checks became false by design; each now checks "nothing else, plus exactly the approved addition":
- Phase 2 main: the `pqbg_sales` column list ends with `payment_method`, `unit_cost`, `seller_name`; 10 indexes (with `method_created`); the administrator has 8 pqbg capabilities (with `pqbg_view_costs`) and the shop manager still not `pqbg_view_costs`.
- Phase 4: the default settings include `payment_methods` (cash, upi, card).
- Phase 7: every sale it makes sends a payment method (`cash`: the worker, `$sell_in`, `$post_form` and one direct `SaleRequest::handle()` call); 8 capabilities; the DB-version check compares with `Install::DB_VERSION`; the v1 migration fixture first removes the v3 columns and index from `Schema::statements()`, and the v1 → v2 check looks at the columns right after the v1 ones (the v3 columns follow, because `migrate_2` applies the current schema). Two scope checks: `update_post_meta` is allowed only in `CostPrice`, and there only on its own key ("stock is changed only through wc_update_product_stock"); `sales_table()` may also appear in `SalesQuery`, which must contain no `$wpdb` write ("sales rows are written only by SaleRepository").
- Phases 3, 5 and 6: unchanged (the new hooks are not in their lists; the history classes only read; the scan template's header link is shown only to users who may see their own sales, so the customer 403 is still byte-identical).

**Phase 9A suite notes.**
- Fixture sale rows are inserted directly into `pqbg_sales` (marked in `note`), for exact dates and statuses; real sales go through `SaleService`. It creates about 15 products, 7 users, 50,000 performance rows (removed in the suite) and, for the import checks, a few posts; all are removed, and `pqbg_settings`, `woocommerce_meta_box_errors` and the render-cache index are restored byte for byte.
- `wp_die()` in an in-process call (e.g. an importer) is turned into an exception, so the cleanup in `finally` always runs.
- The "delete-all removes every cost row" check runs the exact `uninstall.php` statement only if the site had no cost prices when the suite started; otherwise it is skipped.
- It prints the 50,000-row timings and EXPLAIN plans (informational; list and totals are asserted under 1 s in-process, the HTTP pages under 3 s).

**Phase 8 changes to earlier suites.**
- Phases 3, 5, 6 and 7: Action Scheduler cleanup goes through the shared helpers (see [Action Scheduler jobs](#action-scheduler-jobs)). Phases 5 and 6 gain the zero-leak check (+1 check each).
- Phase 5: "no raw writes outside CodeRepository, SaleRepository, Install and Schema" also allows `PrintCache` (Phase 8). Its only raw SQL deletes its own transient rows, which the Phase 8 suite checks exactly.

**Phase 8 suite notes.**
- It creates about 330 products (300 of them for the timings) and 5 temporary users. It switches the scan base URL and the barcode setting in `pqbg_settings`; every option it touches is restored byte for byte and checked.
- It runs the default (data-preserving) path of `uninstall.php` in-process, to prove that the render cache is cleared and nothing else is. It restores the `pqbg_rewrite_version` flag that path removes.
- It prints the 100/300-label timings (informational: only "warm < cold" and "300 cold under 60 s" are asserted) and the PDF page sizes Chromium produced.

**Phase 7 changes to the Phase 2, 5 and 6 suites.** Phase 7 was approved to add selling and schema version 2, so these checks became false by design. As before, each now checks "nothing else, plus exactly the approved addition":
- Phase 2 main: the `pqbg_sales` column list ends with `stock_holder_id`, `failure_code`; the index count is 9 (with `holder_status`); the three "`pqbg_db_version` = 1" checks now compare with `Install::DB_VERSION` (2).
- Phase 2 lifecycle: the three version checks compare with `Install::DB_VERSION`.
- Phase 5: "no raw writes outside CodeRepository, Install and Schema" also allows `SaleRepository`.
- Phase 6:
  - "POST as seller: 405" is split in two: the entry page still answers 405 `Allow: GET, HEAD`; a code URL without a sale action answers 400. That makes one more check (213).
  - "POST logged out: 405" → a 302 to the login page.
  - The private simple product and the variation under a private parent (both with unmanaged stock) now show exactly one notice to a seller: the Phase 7 "Stock tracking is off…". There is still no status banner.
  - "no screen offers selling" → POST forms appear only as the Phase 7 sale form, and only on the two sellable fixtures.
- Phase 4: unchanged. Its "no scan path literal outside `ScanUrl`" guard is why `SaleService` leaves `pqbg_sales.source` to the column default (`scan`).

**Phase 7 suite notes.**
- It starts worker processes of itself (`--worker sell|undo|hold|online`): concurrent sales and undos, a process that holds a stock lock (to test "busy" and that the parent's lock is the one taken), and a process that places a real WooCommerce order and runs `wc_reduce_stock_levels()` in the middle of a sale (the online race). Both orders and their notes are deleted in cleanup.
- The migration checks build v1-shaped tables on a temporary prefix (`{prefix}pqbgmig_`) and drop them. The real tables are never altered.
- `pre_wp_mail` is short-circuited in-process and in the workers, because local mail isn't configured and a failing `mail()` takes about 2 s. Over HTTP the low/no-stock e-mails still fail slowly on this machine; most test products therefore use a per-product low-stock threshold of 0.
- It uses Phase 6's fresh-connection HTTP client (`CURLOPT_FORBID_REUSE`).
- It prints the sale and undo timings (informational; only a 3 s bound is asserted).

**Phase 6 changes to the Phase 3, 4 and 5 suites.** Phase 6 was approved to add the scan route, so four kinds of earlier check became false by design. As in Phase 5, each now checks "nothing else, plus exactly the approved addition":
- "no scan rewrite rules" (Phases 3, 4, 5) → no pqbg or scan rewrite rules other than the two scan rules from `ScanUrl::rewrite_rules()`
- "`/scan/` and `/scan/{CODE}/` return 404" (Phases 4, 5) → logged out, they redirect to the login page
- "no `add_rewrite_rule` in source" (Phase 5) → `add_rewrite_rule` appears only in `ScanRoute.php`, plus the unchanged ban on nopriv handlers, AJAX, REST routes, shortcodes and rewrite endpoints (one more check)
- "only the two pqbg options exist" (Phase 3) → also allows `pqbg_rewrite_version`, the rules flag

**Test data leak fixed in Phase 6.** Opening the wp-admin Dashboard as a temporary user who can edit posts makes WordPress's Quick Draft widget create an auto-draft owned by that user. `wp_delete_user()` then moved it to the trash, with a revision, instead of deleting it. The Phase 4 suite (Shop Manager Dashboard check) left one such pair per run.
- The Phase 4 and 6 suites now permanently delete their temporary users' own posts before deleting the users.
- Phase 4 has a new check for this, which is why it now has 165 checks.

**Phase 6 suite notes.**
- Its HTTP client opens a fresh connection for every request (`CURLOPT_FORBID_REUSE`). With about 20 cookie jars open, reused keep-alive connections that Apache had closed intermittently produced empty responses (status 0, no curl error) during development.
- It switches Coming Soon off briefly to fetch the real My Account login form, then logs in with Coming Soon back on. It switches permalinks to Plain and `/index.php/…` briefly, and deactivates and reactivates the plugin in-process. Every option it touches (`woocommerce_coming_soon`, `permalink_structure`, `rewrite_rules`, `pqbg_rewrite_version`, `active_plugins`, `pqbg_settings`) is restored byte-for-byte and checked.
- It prints the scan timing (informational; only a 3-second sanity bound is asserted).

**Phase 5 change to the Phase 3 suite.** Phase 3's scope check "no plugin callbacks on product save/create/delete hooks" was true until Phase 5, which was approved to add them. It is now two checks:
- there are still no callbacks on `save_post`, `wp_insert_post`, `transition_post_status`, `before_delete_post`, `wp_trash_post` and similar hooks
- the five approved hooks (the four WooCommerce CRUD save hooks and `deleted_post`) are served by `CodeLifecycle` only

The suite now has 110 checks.

**Phase 5 suite notes.**
- It starts 4 child PHP processes of itself (`--worker`) to regenerate one item at the same moment.
- It prints its performance timings, which are informational: only the in-process panel budget (< 500 ms) is asserted.
- It creates about 140 products and variations and cleans them all up, including their Action Scheduler jobs.

**Ported Phase 2 suites.** These are the original Phase 2 scripts with the renamed identifiers. The main suite has three intentional changes:
- `settings merge drops unknown keys` now compares with `Plugin::default_settings()`, because Phase 4 added keys.
- The role check no longer needs a pre-activation snapshot file. Instead it adds a canary capability to every role, runs `install()` and `sync_roles()`, and checks that nothing but `pqbg_*` capabilities changed.
- Row checks count only the suite's own `TEST-PQBG-*` rows, so existing codes don't break it.

The original counts were 80 and 16; the notice check and two cleanup checks are new.

**Phase 3 reconstruction.** The original Phase 3 script (92 checks) was deleted after Phase 3. `phase3-codes.php` was rebuilt in Phase 4 from the checklist recorded in `progress.md`. It covers every category listed there, but its checks are not the original ones.

## Action Scheduler jobs

WooCommerce schedules a product-attributes-lookup job (`woocommerce_run_product_attribute_lookup_update_callback`) on every product save and delete, and an Apache queue runner, started by the suites' own HTTP requests, may be running those jobs while a suite cleans up.

- **The leak (found and fixed in Phase 8).** The Phase 5 suite left 36 completed jobs per run. Its cleanup matched jobs to post IDs that still existed at cleanup time, so it missed the products it had already deleted inside its own sections (trash/delete, type changes, the edit screen, downloads). The progress log had blamed the Phase 3 suite; a trace of every suite showed that Phase 3 always cleaned up.
- **The fix (tests only).** `bootstrap.php` has shared helpers, used by Phases 3, 5, 6, 7 and 8:
  - `pqbg_test_as_mark()` records the highest action, log, post and order IDs when a suite starts.
  - `pqbg_test_as_cleanup()` matches jobs on **every post/order ID allocated during the suite** (up to `AUTO_INCREMENT − 1`, so deleted posts count). It waits up to 15 s for any claimed or running job, then deletes the jobs (Action Scheduler deletes their logs with them) and any orphaned log rows.
  - `pqbg_test_as_check()` is each suite's "zero Action Scheduler jobs or logs left for test data" check. It is new in Phases 5 and 6 and replaces the older check in Phases 3 and 7.
- **The guard.** `run.php` runs `as-guard.php mark` before each suite and `as-guard.php check` after the suite's process has exited, so after its PHP shutdown and after any queue runner (it waits up to 20 s). It fails the suite on:
  - any new job that references a test ID
  - any new job whose hook had no job before the suite
  - any orphaned log row

  New jobs of hooks that already existed and that reference no test ID are the site's own WP-Cron work, triggered by the suites' HTTP requests (e.g. `fetch_patterns`, the Action Scheduler migration hook). They are reported on the `AS-GUARD` line, not failed.

## Requirements

- PHP CLI with the `curl` extension, able to load the site's `wp-load.php`.
- The site reachable over HTTP at `home_url()` from the same machine (Phases 4 and 5 log in as temporary users).
- `proc_open()` and `exec()` for the concurrency workers (Phases 5, 7 and 10).
- **Optional: Node.js 18+ and the round-trip decoder**, for the 5 Phase 4, 2 Phase 5, 1 Phase 6 and 4 Phase 8 checks that rasterise the SVGs and decode them with a real decoder.
- **Optional: Node.js 22.13+ (or 24+), the print-check package and an installed Chrome or Edge**, for the Phase 8 browser checks (below).

### Installing the decoder

```
cd tests/decoder
npm ci
```

- This installs exactly what `package-lock.json` pins: `@resvg/resvg-js` 2.6.2 (SVG rasteriser) and `zxing-wasm` 3.1.4 (the ZXing-C++ decoder).
- `decode.mjs` loads the decoder's WebAssembly from `node_modules`, so nothing is downloaded at run time.
- **`node_modules/` is never committed.** The root `.gitignore` ignores it.
- It is also denied over HTTP, but prefer installing it outside the web root:
  1. Copy `tests/decoder/` somewhere else.
  2. Run `npm ci` there.
  3. Point `PQBG_DECODER` at that copy's `decode.mjs`.

**If Node.js or the decoder install is missing**, the round-trip checks are **skipped, not failed**:
- Each prints a `SKIP` line saying what is missing and how to install it.
- The suite's result line reports `N skipped`.
- The rest of the suite still runs, and the runner can still pass.

Install the decoder before relying on a run as the full Phase 4 verification.

`decode.mjs` takes `--width=PX` to rasterise the SVG files that follow at exactly that width (Phase 8: a label's QR code or barcode at its printed size), `--zoom` to go back to 2×, and PNG files (browser screenshots), which it decodes as they are.

### The WordPress Importer for the WXR import check (Phase 9A)

The official WordPress Importer plugin (https://wordpress.org/plugins/wordpress-importer/, 0.9.6 when written) is **not** installed on the site. Download and unzip it **outside the site** (e.g. a scratch folder) and point `PQBG_WXR_IMPORTER` at its `wordpress-importer.php`. The suite loads it in-process only (never activated) and imports a WXR file carrying a cost as a shop manager. Without it, that one check is skipped.

### Installing the print-check package (Phase 8)

```
cd tests/print-check
npm ci
```

- It installs exactly what `package-lock.json` pins: `puppeteer-core` 25.12.0 and `pdfjs-dist` 6.3.289. **puppeteer-core never downloads a browser**: `check.mjs` drives the Chrome or Edge that is already installed.
- As with the decoder, prefer a copy outside the web root, and point `PQBG_PRINTCHECK` at its `check.mjs`. `node_modules/` is never committed.
- Browsers: by default, the standard Edge and Chrome install paths on Windows (and `/usr/bin/google-chrome`, `/usr/bin/chromium`). `PQBG_BROWSERS` takes a `;`-separated list of executables.
- Without Node.js, the package, the decoder or a browser, the browser checks are **skipped** with a message.

What `check.mjs` checks in each browser, logged in as the suite's temporary administrator:
- The setup screen, the local-address confirmation page and the print pages load with **zero CSP violations, console errors, page errors or failed requests**. A `securitypolicyviolation` listener is installed before any script runs.
- **Clicking Print calls `window.print()`.** It is replaced by a counter, so no dialog opens.
- **Print-media geometry**, in mm:
  - every sheet and label matches `PrintLayout` to 0.06 mm, including the QR, text and barcode boxes
  - nothing overlaps, and the code text is never cut
  - long names are clamped and long SKUs ellipsised, inside the text column
- **The rupee sign:** every platform font Chromium actually used for the price (CDP `CSS.getPlatformFontsForNode`) is in the label font stack, and "₹" drawn in that font is inked and differs from a missing-glyph box.
- **Every label is decoded** from a screenshot taken at dpi/96 device pixels per CSS pixel, i.e. at its printed size: 300 dpi for A4, 203 dpi for thermal, and a label with 0.40 mm modules at 203 dpi.
- **The PDF** (`page.pdf()` with the page's own `@page` size, read back with pdf.js): one page per sheet or thermal label, the page size (±0.5 mm), and every code text at its layout position.

## Environment type

The suites refuse to run on a site whose `wp_get_environment_type()` is `production`.

**Preferred:** declare the development site as local in its `wp-config.php`. That file is never committed.

```php
define( 'WP_ENVIRONMENT_TYPE', 'local' );
```

**Fallback:** set `PQBG_TESTS_ALLOW_PRODUCTION=1` in the environment for a single run. Use it only on a development copy that doesn't declare its type.

## Running

From the plugin directory:

```
php tests/run.php                  # every suite
php tests/run.php phase4           # suites whose name contains "phase4"
php tests/phase3-codes.php         # one suite directly
```

- On Windows/XAMPP, use `C:\xampp\php\php.exe`.
- **Low memory:** with less than 1.5 GB free, run each suite on its own (`php tests/run.php phase7`, one after another) rather than the full run. Since Phase 11 **every suite** honours `PQBG_STOP_FILE`: a watchdog that samples free memory and creates that file below 500 MB makes the running suite stop at its next safe point and run its cleanup instead of being killed mid-run (a killed suite leaves its test data behind). A stopped suite is reported as STOPPED, never as a pass. Phase 8 starts headless Edge and Chrome and needs the most memory.
- For the fallback, prefix the command with `PQBG_TESTS_ALLOW_PRODUCTION=1`. In PowerShell, run `$env:PQBG_TESTS_ALLOW_PRODUCTION = '1'` first.

Optional environment variables:

| Variable | Default | Purpose |
|---|---|---|
| `PQBG_TESTS_ALLOW_PRODUCTION` | unset | Fallback when the site reports environment type `production` (prefer `WP_ENVIRONMENT_TYPE`) |
| `PQBG_WP_LOAD` | four directories up + `/wp-load.php` | Path to `wp-load.php` |
| `PQBG_DECODER` | `tests/decoder/decode.mjs` | Another copy of `decode.mjs` whose `node_modules` sits next to it, e.g. outside the web root |
| `PQBG_PRINTCHECK` | `tests/print-check/check.mjs` | Another copy of `check.mjs` whose `node_modules` sits next to it (Phase 8) |
| `PQBG_BROWSERS` | the standard Edge/Chrome paths | `;`-separated browser executables for the Phase 8 browser checks |
| `PQBG_WXR_IMPORTER` | unset | Path to `wordpress-importer.php` of the WordPress Importer, kept outside the site (Phase 9A WXR import check) |
| `PQBG_STOP_FILE` | unset | Every suite (Phases 10 and 10B since Phase 10; all since Phase 11): when this file exists, the suite stops at its next safe point, cleans up and reports STOPPED (a memory watchdog can create it) |
| `PQBG_STRESS` | unset | `1` adds the 50,000-sale stress checks to the Phase 9B, 10B and 11 suites (owner's decision: run them in Phase 11 and once before launch; default runs use the 5,000-sale checks) |
| `PQBG_ERROR_CAPTURE` | unset | Phase 11: the folder of the temporary error logger (see [Error capture](#error-capture)); `run.php` names the running suite in `<folder>/ACTIVE` and prints the suite's PHP notices, warnings, deprecations and fatal errors per source, and every one from plugin code |
| `PQBG_THEMECHECK` | `tests/theme-check/check.mjs` | Phase 12: another copy of the theme-check tool whose `node_modules` sits next to it |
| `PQBG_THEME_PACKS` | unset (required by Phase 12) | Folder with the four theme zips (Phase 12) |
| `PQBG_CACHE_PLUGIN` | unset (required by Phase 12) | Path to `wp-fastest-cache.1.5.2.zip` (Phase 12) |
| `PQBG_SCREENS` | system temp folder | Where Phase 12 saves the phone and desktop screenshots of every staff screen per theme |
| `PQBG_WPCLI` | unset (required by Phase 13's .pot check) | Path to `wp-cli.phar` (Phase 13) |
| `PQBG_GUIDE_SHOTS` | unset | `guide-screenshots.php` only: also keep the four PNGs in this folder |
| `PQBG_MANUAL_TOOLS` | unset (required by the 1.0.1 suite's PDF checks and by the manual build) | Folder where `build/manual/package.json` is installed with `npm ci --omit=optional` (marked, pdfjs-dist); see `build/manual/README.md` |
| `PQBG_MANUAL_SHOTS` | unset | `build/manual/build-manual.php` only: also keep the compressed screenshots in this folder |

**On this development machine** the tools are installed permanently outside the web root, in `C:\xampp\tools\pqbg\` (copied there on 2026-09-27 from the copies with `node_modules` already installed; `decode.mjs`, `check.mjs` and the package files are identical to `tests/decoder` and `tests/print-check`):

```
PQBG_DECODER=C:\xampp\tools\pqbg\decoder\decode.mjs
PQBG_PRINTCHECK=C:\xampp\tools\pqbg\print-check\check.mjs
PQBG_WXR_IMPORTER=C:\xampp\tools\pqbg\wordpress-importer\wordpress-importer.php
PQBG_THEMECHECK=C:\xampp\tools\pqbg\theme-check\check.mjs
PQBG_THEME_PACKS=C:\xampp\tools\pqbg\themes
PQBG_CACHE_PLUGIN=C:\xampp\tools\pqbg\cache-plugin\wp-fastest-cache.1.5.2.zip
PQBG_SCREENS=C:\xampp\backups\sharayu\phase12-screens
PQBG_WPCLI=C:\xampp\tools\pqbg\wp-cli\wp-cli.phar
PQBG_MANUAL_TOOLS=C:\xampp\tools\pqbg\manual
```

1.0.1 (2026-09-28): `C:\xampp\tools\pqbg\manual\` holds `build/manual/package.json` and `package-lock.json` installed with `npm ci --omit=optional` (marked 18.0.14, pdfjs-dist 6.3.289, integrity hashes in the lock file).

Phase 12 (2026-09-28): `theme-check` is installed there with `npm ci`, and the theme and plugin zips were downloaded from wordpress.org, with SHA-256 sums in `C:\xampp\tools\pqbg\themes\SHA256SUMS.txt`.

Each variable points at the **file**, not its folder (a folder makes the suites skip those checks). With all three set, no suite skips a check: every test report states "0 skipped".

The runner exits 0 only when no selected suite has a failure. Skipped checks are listed in each suite's result line.

## Error capture

Phase 11. Each suite's in-process error handler only sees its own process. To count the PHP notices, warnings and deprecations of **every** request a suite makes (Apache and CLI workers too) without touching `wp-config.php` (where `WP_DEBUG` is off, so WordPress hides notices and deprecations), a temporary must-use plugin records every E_ALL event (not @-suppressed ones) and fatal errors, whatever `error_reporting` says, with file, line and a short backtrace:

- Source kept outside the site and the repository: `C:\xampp\tools\pqbg\errors\pqbg-error-capture.php`. For test runs only, copy it to `wp-content/mu-plugins/` (that folder is ignored by `.gitignore`) and delete it afterwards.
- It records only while `C:\xampp\tools\pqbg\errors\ACTIVE` exists; `run.php` writes the suite name there when `PQBG_ERROR_CAPTURE=C:\xampp\tools\pqbg\errors` is set, and removes it after the suite. The log, `capture.log` in the same folder, holds one JSON line per request or process.
- `run.php` prints `error capture: plugin N, tests N, WordPress N, WooCommerce N, other N` per suite (by the file that raised it), lists every event from plugin code, and fails the suite for any. Events raised inside WordPress or WooCommerce functions carry a backtrace, so their caller can be checked.

## Coding standards (PHPCS)

Phase 11. PHP_CodeSniffer 3.13 with WordPress Coding Standards 3.4 and PHPCompatibilityWP 2.1 (PHP 8.2+) is installed outside the repository in `C:\xampp\tools\pqbg\phpcs\` (Composer 2.10.3, the same checksum as in `build/README.md`), with the ruleset `pqbg.xml` there (excludes `vendor-prefixed/`, `tests/`, `build/`). From that folder:

```
C:\xampp\php\php.exe vendor\bin\phpcs --standard=pqbg.xml
C:\xampp\php\php.exe vendor\bin\phpcs --standard=pqbg.xml --report=source
```

The findings and what was fixed are recorded in the Phase 11 section of `progress.md` (0 PHP 8.2+ compatibility findings; the security-sniff findings were each checked by hand; the coding-style cleanup is left for Phase 13 if the plugin is ever published on WordPress.org).
