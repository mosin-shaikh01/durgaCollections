# Tests

CLI regression suites for Product QR Code and Barcode Generator (Phases 2–7). They run against a real WordPress + WooCommerce site through `wp-load.php`; there is no PHPUnit.

- **CLI only.** Every PHP file here exits unless `PHP_SAPI === 'cli'`. The `.htaccess` in this directory answers every HTTP request with 403 (Apache). `index.php` stubs stop directory listings on other servers.
- **Never loaded by the plugin.** The autoloader only maps `includes/` and `vendor-prefixed/`.
- **Not for production.** Exclude `tests/` (and `build/`) from any production deployment; see the plugin README.
- **Development sites only.** The suites create and then delete test data: code rows, sales rows, products, users, Action Scheduler jobs and (Phase 7 only) two WooCommerce orders that simulate online checkouts. The lifecycle suite also briefly deactivates and reactivates the plugin.
  - Every suite cleans up everything it creates, even when a check fails, and checks its own cleanup.
  - The suites refuse to run when `wp_get_environment_type()` is `production`. See [Environment type](#environment-type).

## Suites

| File | Covers | Checks |
|---|---|---|
| `phase2-main.php` | Schema, options, repository invariant, sales table, migrations, install lock, roles and capabilities, HPOS, no endpoints | 83 |
| `phase2-lifecycle.php` | Deactivation, the default (data-preserving) uninstall path, reactivation | 17 |
| `phase2-no-woocommerce.php` | WooCommerce missing, simulated for this process only: no boot, admin notice | 12 |
| `phase3-codes.php` | Code format and alphabet, randomness, collisions, eligibility, authorization, assignment, retirement, database races, batch, scope. **A reconstruction**, see below. Scope checks updated for Phases 5 and 6. | 110 |
| `phase4-rendering.php` | Scan URLs, QR and barcode rendering with round-trip decoding, lazy library loading, SVG safety, base URL validation, the local-address and http:// warnings, settings page access and saving over HTTP, vendor isolation, scope | 165 (5 of them need the decoder) |
| `phase5-admin.php` | Automatic code assignment on every save path over real HTTP (classic edit screen, AJAX variations, Quick Edit, Bulk Edit, REST, Duplicate) and the CSV importer; users without the capability, cron, failure injection; trash, untrash, delete, Empty Trash, type changes; atomic regeneration including a mid-transaction failure and **4 concurrent PHP processes**; history timezone and "retired by" labels; the panel, variations panel, list column, confirmation page and handlers over HTTP; downloads with round-trip decoding; permissions and nonces for every handler; barcodes disabled; the 40-variation performance measurement; scope | 157 (2 of them need the decoder) |
| `phase6-scan.php` | The scan page over real HTTP: rewrite rules, subdirectory, canonical 301s, flush once, deactivation/reactivation, plain and `index.php` permalinks, slug conflicts; every row of the status → screen matrix (including a private variation vs a private simple product, and a trashed parent); price, sale, stock, category, image rendering and live data; HTML escaping; logged-out redirects; login round trips through `wp-login.php` and the My Account form for administrator, Shop Manager and Store Seller (also with Coming Soon on); the byte-identical customer/subscriber 403; entry box normalisation; methods; every security header on every response type; Coming Soon; sitemaps; QR round trip (QrRenderer → decoder → request); scan timing; scope | 213 (1 of them needs the decoder) |
| `phase7-sales.php` | Mark as Sold: the sale form (quantity list with totals, number field above 100, hidden token fields, separate from the code box) for sellers, shop managers and administrators and its absence for view-only users; a sale over real HTTP with every snapshot, the 303 and the success page; quantity bounds; every non-sellable state refused on POST (not just hidden); unmanaged stock, empty and zero price, backorders, stock and price changed since the form was opened; price normalisation; scheduled sale prices; own-stock and parent-level variation stock (decrement **and lock** on the parent); permissions, nonces, signed tokens, expiry; the sale page's 303 for other users; undo over HTTP and in-process (own sale, 9:59 vs 10:01, once, GET, lock, 4 concurrent undos) and `void_sale()`; idempotency (sequential, 4 concurrent processes, after expiry); **8 worker processes selling the last 3** (simple and parent-level stock); the **online race** with a real WooCommerce order from another process; failure injection before, during and after the stock change; the **SQL-override fallback** in both branches and the **WooCommerce compatibility check** on `woocommerce_update_product_stock_query`; stock hooks, stock status, lookup table and low/no-stock notifications; no WooCommerce orders from sales; the v1 → v2 migration on a temporary table prefix; escaping; headers; GET never writes; scope; timings | 209 |

Each suite ends with a check that plugin code raised no PHP notices, warnings or deprecations. That check is included in the counts.

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

## Requirements

- PHP CLI with the `curl` extension, able to load the site's `wp-load.php`.
- The site reachable over HTTP at `home_url()` from the same machine (Phases 4 and 5 log in as temporary users).
- `proc_open()` and `exec()` for the concurrency workers (Phases 5 and 7).
- **Optional: Node.js 18+ and the round-trip decoder**, for the 5 Phase 4, 2 Phase 5 and 1 Phase 6 checks that rasterise the SVGs and decode them with a real decoder.

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
- For the fallback, prefix the command with `PQBG_TESTS_ALLOW_PRODUCTION=1`. In PowerShell, run `$env:PQBG_TESTS_ALLOW_PRODUCTION = '1'` first.

Optional environment variables:

| Variable | Default | Purpose |
|---|---|---|
| `PQBG_TESTS_ALLOW_PRODUCTION` | unset | Fallback when the site reports environment type `production` (prefer `WP_ENVIRONMENT_TYPE`) |
| `PQBG_WP_LOAD` | four directories up + `/wp-load.php` | Path to `wp-load.php` |
| `PQBG_DECODER` | `tests/decoder/decode.mjs` | Another copy of `decode.mjs` whose `node_modules` sits next to it, e.g. outside the web root |

The runner exits 0 only when no selected suite has a failure. Skipped checks are listed in each suite's result line.
