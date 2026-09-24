# Durga Collections — Progress Log

WordPress site running locally on XAMPP.
Repo: https://github.com/mosin-shaikh01/durgaCollections

---

## Environment

| Item | Value |
|---|---|
| Project root | `C:\xampp\htdocs\sharayu` |
| Local URL | http://localhost/sharayu |
| Admin | http://localhost/sharayu/wp-admin |
| WordPress | 7.1.2 (db_version 61833) |
| PHP | 8.5.6 |
| Database | MariaDB 10.4.32 — db `sharayu`, user `root`, prefix `wp_`, InnoDB, `utf8mb4_unicode_520_ci` |
| WooCommerce | 11.1.2 — HPOS enabled (sync off), currency INR, stock management on, Coming Soon on (whole site) |
| Permalinks | `/%postname%/` |
| Timezone | UTC (`timezone_string` empty, offset 0) — **not yet changed; store is in India, change only with approval** |
| Active theme | twentytwentyfive |
| Active plugins | classic-editor, woocommerce, product-qrcode-barcode-generator (Product QR Code and Barcode Generator; installed in Phase 2 under its former name) |
| Admin user | Dev-admin |

_Environment re-verified 2026-09-24 at the start of Phase 2, at the start of Phase 3, and at the start of the plugin rename. There were no differences apart from the rename itself._

---

## Repository

Branch `main`, tracking `origin/main`.

- Phase 2 was committed as `50d7e0b` and Phase 3 as `a2e5643`; both are pushed to `origin/main`.
- The plugin rename was committed as `5f301be` and pushed to `origin/main`, with the user's approval (normal fast-forward, no force).
- Phase 4 was committed, with the user's approval, as a single commit "Phase 4: QR code + optional Code 128 barcode rendering, settings page, tests and build" and pushed to `origin/main` (normal push, no force). A commit can't record its own hash; see `git log`.

Tracked files — project code only; WordPress core, `wp-config.php`,
uploads and archives are excluded by `.gitignore`:

```
.gitignore
README.md
progress.md
wp-content/plugins/product-qrcode-barcode-generator/
```

| Commit | Message |
|---|---|
| `5f301be` | Rename plugin to Product QR Code and Barcode Generator |
| `a2e5643` | Phase 3: secure product code generation (CodeGenerator, ProductCodeService) |
| `fd07308` | Record Phase 2 commit and push in progress log |
| `50d7e0b` | Add Durga Product Codes plugin foundation (Phase 2) |
| `d9bb6a0` | Remove installer zip and empty extraction folder |
| `11e07fb` | Record tracking audit in progress log |
| `094e737` | Add .gitignore and progress tracker |
| `252e2d0` | first commit |

**Note:** `.gitignore` ignores `wp-content/*` wholesale, so a new
custom theme or plugin will not appear in `git status` until it is
un-ignored. Exactly one plugin is un-ignored
(`!wp-content/plugins/` + `wp-content/plugins/*` + `!wp-content/plugins/product-qrcode-barcode-generator/`);
every other plugin stays ignored. For a future custom theme the pattern is:

```
!wp-content/themes/
!wp-content/themes/durga-collections/
```

---

## Status

### Done
- [x] WordPress installed and verified — core files intact, all 12 tables present, homepage and login both return 200
- [x] Site title set to "Durga Collections"
- [x] Git repo initialized, `main` branch pushed to GitHub (first commit: `README.md`)
- [x] `.gitignore` added — excludes `wp-config.php`, `.htaccess`, `*.zip`/`*.sql`, WP core, `wp-content` uploads/cache, bundled themes and plugins, OS/editor cruft, `node_modules/`, `vendor/`
- [x] Exclusions verified with `git check-ignore` — confirmed `wp-config.php`, `wordpress-7.1.zip`, `wp-content/uploads`, `wp-admin` and `wp-includes` are genuinely ignored
- [x] `progress.md` created and committed
- [x] Deleted `wordpress-7.1.zip` (37 MB) from the web root — now returns 404 over HTTP
- [x] Removed the empty leftover `wordpress/` folder
- [x] Full tracking audit passed — `git add -A` dry run stages only intended files, recursive untracked scan returns 0, and no sensitive path appears anywhere in history

### Open items
- [x] Permalinks set to `/%postname%/` (verified 2026-09-24)
- [ ] Decide on theme approach — customize twentytwentyfive, use a child theme, or build custom

### Backlog
_To be filled in — site structure, pages, content, plugins._

---

## Product QR Code and Barcode Generator plugin

A custom WooCommerce plugin providing product QR/barcode inventory for our own shop staff.
It lives in `wp-content/plugins/product-qrcode-barcode-generator/`. It was called "Durga Product Codes" until the rename; see below. The full developer documentation is in that folder's `README.md`.

| Phase | Scope | Status |
|---|---|---|
| 1 | Environment audit and architecture | Done (audit only, no code) |
| **2** | **Plugin foundation and data layer** | **Done 2026-09-24. Committed `50d7e0b`, pushed.** |
| **3** | **Secure product code generation** | **Done 2026-09-24. Committed `a2e5643`, pushed.** |
| — | **Plugin rename** (no functional change) | **Done 2026-09-24. Committed `5f301be`, pushed.** |
| **4** | **QR code + optional barcode rendering** | **Done 2026-09-24. Approved, committed as one commit and pushed.** |
| 5 | Admin code management | Next. Not started |
| 6 → 12 | scan/product screen → mark sold + sales → printing → seller dashboard/history → bulk/CSV → hardening/performance → QA/documentation | Not started |

> **Pre-rename records.**
>
> The Phase 2 and Phase 3 sections below are kept exactly as they were written, under the plugin's former name "Durga Product Codes".
> - Their identifiers (`durga-product-codes`, `Durga\ProductCodes`, `DPC_*`, `dpc_*` tables, options, capabilities and role) are **historical**.
> - For the current names, see [Plugin rename](#plugin-rename-2026-09-24) and the identifier map in the plugin README.
> - Phase 3 has since been committed as `a2e5643`.

### Phase 2: Plugin foundation and data layer (2026-09-24)

**Status:** implemented and tested. The plugin is **active**. Committed as `50d7e0b` and pushed to `origin/main` with the user's approval (normal fast-forward, no force).

**Files created** (all under `wp-content/plugins/durga-product-codes/`):

- `durga-product-codes.php`: plugin header, constants, autoloader, activation/deactivation hooks, HPOS declaration, boots on `plugins_loaded`
- `includes/Plugin.php`: runtime boot and settings access
- `includes/Requirements.php`: WordPress, PHP and WooCommerce checks, plus an admin notice
- `includes/Install.php`: activation, versioned migrations and install lock
- `includes/Schema.php`: table definitions for `dbDelta` and the CHECK constraint
- `includes/Permissions.php`: capabilities, role map, role sync and `can_*` helpers
- `includes/CodeRepository.php`: data access that enforces one active code per item
- `uninstall.php`: preserves all data by default
- `README.md`
- `index.php` in the plugin root, `includes/` and `languages/`

**Files modified:**

- `.gitignore`: un-ignores only `wp-content/plugins/durga-product-codes/`
- `progress.md`: this section, plus reconciled environment facts

**Database changes:**

- New table `wp_dpc_codes`: 11 columns.
  - Unique indexes: `code`, `active_product_id`.
  - Other indexes: `product_status`, `parent_id`, `status`.
  - CHECK constraint `wp_dpc_codes_active_chk`.
- New table `wp_dpc_sales`: 25 columns.
  - Unique index: `request_id`.
  - Other indexes: `code_id`, `product_variation`, `seller_created`, `status_created`, `created_at_gmt`, `order_id`.
- New options:
  - `dpc_db_version = 1` (autoloaded)
  - `dpc_settings = {"settings_version":1}` (not autoloaded)
  - `dpc_install_lock` exists only while an install is running.
- Both tables are **empty**. All test rows were removed and the AUTO_INCREMENT counters were reset to 1.

**Roles and capabilities:**

| Role | DPC capabilities |
|---|---|
| New role `dpc_seller` ("Seller") | `read`, `dpc_view_products`, `dpc_sell`, `dpc_view_own_sales` |
| `shop_manager` | the 3 seller capabilities, plus `dpc_view_all_sales`, `dpc_void_sale`, `dpc_manage_codes` |
| `administrator` | all 7 |

- **`dpc_manage_settings` is administrator-only.**
- No other capability on any role was changed. This was verified against a snapshot taken before activation.

**Compatibility:**

- Minimums: WordPress 6.7, PHP 8.1, WooCommerce 9.0. Header `WC tested up to: 11.1`.
- HPOS is declared compatible with feature ID `custom_order_tables`, checked against the installed WooCommerce 11.1.2 source.
- No other WooCommerce feature was declared.

**Tests** (PHP CLI scripts loading `wp-load.php`, plus HTTP checks):

- `php -l` on all 11 PHP files passed.
- A TEMPORARY-table probe confirmed that on MariaDB 10.4.32, UNIQUE allows many NULLs and rejects duplicate non-NULL values, and that the CHECK constraint is enforced. The temporary table was dropped.
  - Finding: the CHECK must include `active_product_id IS NOT NULL`, because a CHECK that evaluates to UNKNOWN passes.
- The main suite passed **80 of 80**, covering:
  - constants and autoloading
  - column types, defaults, nullability and collation
  - indexes, InnoDB and the CHECK constraint
  - a second `dbDelta` run is a no-op
  - options
  - application-level rejection of a second active code or a duplicate code string
  - rejection of invalid codes and invalid product references
  - DB-level UNIQUE and CHECK rejections
  - retiring, no reactivation, and no reissue of a retired code string
  - UTC timestamps
  - sales-table defaults, decimals and unique `request_id`
  - `install()`/activation run twice with data and schema unchanged
  - migration v0 → v1 with data preserved, and no downgrade from a newer version
  - lock: exclusive, blocks install and upgrade, a foreign token can't release it, an expired lock is recovered
  - exact role capabilities, other roles untouched, sync idempotent, permission helpers for seller, admin and logged-out users
  - HPOS declared compatible
  - no REST routes, shortcodes or AJAX actions
  - cleanup
- WooCommerce missing (simulated for one process only, nothing written): no fatal error, the plugin does not boot, and the admin notice appears only for users who can manage plugins.
- Lifecycle:
  - Deactivation kept the tables, a marker row, the options and the role.
  - The **default uninstall path** (constant not defined) kept everything.
  - Reactivation succeeded with the data intact.
  - The marker row was then removed.
- HTTP:
  - `/` 200, `/wp-login.php` 200, `/wp-admin/` 302, `/shop/` 200.
  - `/wp-json/` returns valid JSON with no `dpc` namespace or routes.
  - Direct requests to plugin PHP files return empty output.
- A static security grep found no superglobals, no endpoints, only escaped output, and no unprepared dynamic SQL.

**Known limitations / not tested:**

- The destructive uninstall branch (`DPC_UNINSTALL_DELETE_ALL_DATA`) was code-reviewed but deliberately **not executed**.
- The "WooCommerce too old" and "WordPress/PHP too old" branches were code-reviewed only. The installed versions can't be faked in-process. The "WooCommerce missing" branch was tested.
- Multisite network activation is refused; this was not exercised on a multisite install.
- The CHECK constraint is defence in depth only. MySQL before 8.0.16 ignores it, and `CodeRepository` enforces the invariant everywhere.
- No PHPCS/WPCS run, because Composer and PHPCS are not installed.

**Carried-forward risks (unchanged, not acted on):**

- The production domain is not final. QR URLs use `home_url()`, so **don't print labels yet**.
- The timezone is UTC while the store is in India. Change it only with approval.
- WooCommerce Coming Soon is on for the whole site. The `/scan/` route must handle it.
- Online checkout can race a seller sale. Handle this in the Mark-as-Sold phase; the `request_id` unique key is already in place.
- WooCommerce redirects users without staff capabilities away from wp-admin (`woocommerce_prevent_admin_access`). The seller UI is planned as a mobile-friendly **front-end** screen.
- `WP_ENVIRONMENT_TYPE` is not set, so `wp_get_environment_type()` reports `production` locally.

**Next phase at the end of Phase 2:** Phase 3, code generation. It has since been done; see below.

- A secure random code format (e.g. `DC-XXXX-XXXX-XXXX`) using CSPRNG and an unambiguous alphabet.
- Generating codes for simple products and variations through `CodeRepository`.
- The rules for which product types get codes: simple and variation yes; variable parent, grouped and external no.

### Phase 3: Secure product code generation (2026-09-24)

**Status:** implemented and tested. The plugin stays active. **Not committed**; this is waiting for the user's approval.

**Environment:** re-verified at the start with no differences from the Phase 2 notes.
- WordPress 7.1.2, PHP 8.5.6, MariaDB 10.4.32, WooCommerce 11.1.2 (HPOS on, sync off)
- Theme twentytwentyfive; plugins classic-editor, woocommerce, durga-product-codes
- DPC tables were empty, options were `dpc_db_version = 1` and `dpc_settings`, and the roles and capabilities were as Phase 2 left them
- The store had 0 products

**Files created** (under `wp-content/plugins/durga-product-codes/`):

- `includes/CodeGenerator.php`
  - Uses the alphabet `ABCDEFGHJKMNPQRSTUVWXYZ23456789` (31 symbols, no `0 O 1 I L`) and the format `DC-XXXX-XXXX-XXXX`.
  - Each character is picked with `random_int()`.
  - Validates with the strict pattern `/^DC(-[A-HJKMNP-Z2-9]{4}){3}$/D`.
  - `generate_unique()` retries on collision up to `MAX_ATTEMPTS = 10`.
  - It never writes to the database.
- `includes/ProductCodeService.php`
  - Handles eligibility: simple products and variations of variable parents only.
  - Checks authorization with `dpc_manage_codes` on the acting user.
  - `get_or_create()` returns the existing active code or creates one through `CodeRepository`.
  - Retries up to `MAX_SAVE_ATTEMPTS = 3` when the database rejects a duplicate code.
  - Logs failures to the WooCommerce logger.

**Files modified:**

- `includes/CodeRepository.php`: added `code_exists()`, which counts active **and** retired codes and treats malformed input as taken. The class docblock was updated. No existing behaviour changed.
- `README.md` (plugin): new "Product codes" section, and the scope list was updated.
- `progress.md`: this section.

**Architecture:**

```
ProductCodeService::get_or_create( item, user )
  → Permissions::can_manage_codes( user )
  → eligibility( item )           (WooCommerce product object and type)
  → CodeRepository::find_active_for_product( item )   (existing? return it)
  → CodeGenerator::generate_unique()                  (CSPRNG, then code_exists() check, max 10 tries)
  → CodeRepository::create_active( code, item, parent, user )
       UNIQUE(code) conflict → another request assigned this item? use its code
                             → otherwise retry with a new code (max 3 tries)
```

**Product eligibility:**

- Eligible: simple products (`parent_id` 0), and variations whose parent is `variable` (`parent_id` is the parent's ID).
- Not eligible: variable parents, grouped, external, variations whose parent is missing or not variable, and any other or custom type.
- Product status is **not** checked, because no rule exists yet. That decision belongs to Phase 5.

**Decisions:**

- **No hooks.** Nothing runs on product save or creation, because no approved document requires automatic generation. Callers must request codes explicitly.
- **No schema change.** `DB_VERSION` is still 1 and there is no migration.
- **Atomic regeneration is deferred to Phase 5.** Retire followed by `get_or_create()` already gives a new code and never reactivates or reuses the old one.
- Codes are stored only in `dpc_codes.code`, never in post meta or options.

**Tests** (scratchpad PHP CLI script `t_phase3.php` loading `wp-load.php`): **92 of 92 passed**. It covers:

- **Alphabet and format:**
  - the alphabet constant
  - the pattern accepts exactly the alphabet, checked for every one of the 256 byte values in each group
  - 17 malformed inputs rejected, including a trailing newline, NUL and lowercase
- **Generated codes (20,000 samples):**
  - all well-formed
  - no excluded characters
  - all distinct
  - every symbol appears in every position
- **Randomness source:**
  - `random_int` is the default source (checked by reflection)
  - `generate()` takes no input
  - a token scan finds no `rand`, `mt_rand`, `uniqid`, `microtime`, time, hash, SKU or name functions
- **Generator collisions:**
  - a collision leads to the next candidate
  - exhaustion returns a controlled error after exactly 10 checks and 120 RNG calls
  - an RNG exception or out-of-range value returns a controlled error without leaking details
- **`code_exists`:** covers active, unknown, malformed and retired codes.
- **Eligibility:**
  - simple and variation eligible
  - variable, grouped, external and orphaned variation not eligible
  - 0, negative, nonexistent, `PHP_INT_MAX` and page IDs rejected
  - ID 0 does not fall back to the global `$post`
  - a draft product is eligible
- **Authorization:**
  - user 0, a seller and a nonexistent user are all forbidden, and no rows are written
  - the acting user is checked, not the current user
  - a shop manager is allowed
- **Assignment:**
  - a simple product gets a code with the correct row fields, readable through `CodeRepository`
  - each variation gets its own code with `parent_id` set
  - variable, grouped, external, orphaned variation and page IDs get no code
- **One active code:**
  - a repeat request returns the same row, still 1 row and 1 active
  - the RNG is never called when a code already exists
- **Retired codes:**
  - retiring leads to a new code
  - the old row is preserved and not reactivated
  - the generator skips the retired code
  - the repository and database reject it for another item and for its own item
- **Service collisions:** a pre-insert collision leads to a new code, and the existing row is untouched.
- **Database race:**
  - with the pre-check blinded, `UNIQUE(code)` catches the duplicate and the service retries successfully, leaving the other row untouched
  - a persistent conflict fails after exactly 3 saves (36 RNG calls) with no partial row and one log entry that contains no code value
  - generator exhaustion through the service writes no row
  - when another request wins the same item concurrently, the winner's code is returned, only one row is active, and the losing candidate is never stored
  - raw duplicate inserts are blocked, including a lowercase variant
- **Batch:**
  - 300 generated codes are persisted with no conflicts
  - no duplicate codes, and no item has more than one active code
  - the invariant holds on every row
- **Scope:**
  - no DPC post meta or options
  - no hooks, endpoints, SQL, superglobals or URLs in the new classes (the scan is sanity-checked to be live)
  - no DPC or `/scan/` REST routes, shortcodes, AJAX actions or rewrite rules
  - no DPC callbacks on product save or creation hooks

**Phase 2 regression:** the original Phase 2 suites were rerun unchanged.

- Main suite: 80 of 80 passed.
- Lifecycle suite (deactivate, default uninstall, reactivate): 16 of 16 passed.
- HTTP:
  - `/` 200, `/shop/` 200, `/wp-login.php` 200, `/wp-admin/` 302
  - `/scan/` 404
  - direct requests to the new PHP files return empty output
  - `/wp-json/` has no DPC namespace. `dpc_seller` appears there only as a value in WooCommerce's customer-role enum, which has been true since Phase 2.

**Cleanup:**

- Every test product, variation, page and user was deleted, along with their postmeta, term relationships, WooCommerce lookup rows, and the `woocommerce_run_product_attribute_lookup_update_callback` Action Scheduler jobs they caused.
- `dpc_codes` and `dpc_sales` are empty, with AUTO_INCREMENT reset to 1.
- The store has 0 products or variations and 1 user.
- The log output from the test was captured in memory, so no WooCommerce log file was written.

**Known limitations:**

- True concurrency, with two PHP processes at once, was simulated deterministically through injected callbacks rather than tested with parallel requests.
- Product status is not checked yet.
- There is no atomic regeneration yet.
- `CodeRepository::CODE_PATTERN` is still the broad Phase 2 pattern, so the repository alone accepts non-`DC-` strings. Only `ProductCodeService` guarantees the `DC-` format.
- The `CodeGenerator` constructor accepts an injected RNG so tests can force collisions. It is PHP-only and not reachable from any request.
- No PHPCS run, because it is not installed.

**Next phase (not started):** Phase 4, QR and barcode. **The production domain is still not final**, so any URL encoded in a QR code must not be printed yet.

### Plugin rename (2026-09-24)

**Status:** done and tested. The renamed plugin is **active**. Committed as `5f301be` and pushed to `origin/main` with the user's approval (normal fast-forward, no force). Phase 3 was committed first, as a separate commit `a2e5643`, and pushed.

**What changed:** "Durga Product Codes" became **"Product QR Code and Barcode Generator"**. This is a rename only; there are no functional changes.

| Kind | Before | After |
|---|---|---|
| Folder / main file | `durga-product-codes/durga-product-codes.php` | `product-qrcode-barcode-generator/product-qrcode-barcode-generator.php` (moved with `git mv`, so history is kept) |
| Text domain, log source | `durga-product-codes` | `product-qrcode-barcode-generator` |
| Namespace | `Durga\ProductCodes` | `ProductQrBarcode` |
| Constants | `DPC_*`, incl. `DPC_UNINSTALL_DELETE_ALL_DATA` | `PQBG_*`, incl. `PQBG_UNINSTALL_DELETE_ALL_DATA` |
| Tables | `wp_dpc_codes`, `wp_dpc_sales` | `wp_pqbg_codes`, `wp_pqbg_sales` (identical structure) |
| CHECK constraint | `wp_dpc_codes_active_chk` | `wp_pqbg_codes_active_chk` |
| Options | `dpc_db_version`, `dpc_settings`, `dpc_install_lock` | `pqbg_db_version`, `pqbg_settings`, `pqbg_install_lock` |
| Capabilities | 7 × `dpc_*` | 7 × `pqbg_*` (same role matrix) |
| Role | `dpc_seller` "Seller" | `pqbg_seller` "Store Seller" |
| Nonces | `dpc_<verb>`, `_dpc_nonce` | `pqbg_<verb>`, `_pqbg_nonce` |
| `WP_Error` codes | `dpc_*` | `pqbg_*` |

**Header:**
- Added `Update URI: false`. The name is generic, and this stops WordPress from offering a same-slug wordpress.org plugin as an "update".
- Requirements are unchanged: WordPress 6.7, PHP 8.1, `Requires Plugins: woocommerce`, WooCommerce 9.0, tested up to 11.1.
- HPOS is still declared as `custom_order_tables`, through `PQBG_PLUGIN_FILE`.

**Not changed:**
- the `DC-XXXX-XXXX-XXXX` code format, the alphabet and the generator logic: "DC" is the store brand
- every column, index and business rule, and `DB_VERSION` (still 1)
- `Author: Durga Collections` and other references to the store

**Files:**
- `.gitignore`: the exception now points at the new folder. No other rule changed.
- Every plugin PHP file (namespace, text domain and identifiers) except the three `index.php` stubs.
- The plugin `README.md`.
- `progress.md`.

**Database and WordPress state:**
1. Before cleanup, both `dpc_` tables were confirmed to have **0 rows**, and no user held `dpc_seller`.
2. A one-time CLI script outside the web root, not committed, then:
   - deactivated the old plugin
   - dropped `wp_dpc_codes` and `wp_dpc_sales`
   - deleted the three `dpc_` options
   - removed the `dpc_*` capabilities from all roles and removed the `dpc_seller` role
3. The renamed plugin was then activated normally, and its installer created the `pqbg_` schema, options and role.
4. No legacy migration code is shipped.

**Tests** (ported copies of every suite, run through PHP CLI from outside the web root, then deleted):
- **Rename suite: 25 of 25.** It checked:
  - the plugin is active under the new path, and the old folder is gone
  - header values, including `Update URI: false`
  - `PQBG_*` constants are defined and `DPC_*` constants are not
  - the new namespace autoloads and the old one does not
  - HPOS is compatible under the new path
  - no `dpc` tables, options, CHECK constraint, capabilities or role remain, in roles or in user meta
  - the exact role and capability matrix
  - the nonce convention
  - the `DC-` format constants are unchanged
- **Phase 2 main suite: 80 of 80.** This includes the exact table columns, types and indexes, the CHECK constraint, and unrelated roles being unchanged against a snapshot taken before activation.
- **Lifecycle: 16 of 16.**
- **WooCommerce missing:** correct behaviour and admin notice. A positive-control check confirmed that the hook name it looks for is live.
- **Phase 3: 92 of 92.**
- **HTTP:**
  - `/` 200, `/shop/` 200, `/wp-login.php` 200, `/wp-admin/` 302, `/scan/` 404
  - direct requests to plugin files return empty output, and the old plugin path returns 404
  - no PQBG REST namespace; `pqbg_seller` appears only in WooCommerce's customer-role enum
- **Logs:** no PHP errors or notices; the Apache error log didn't grow, and neither `php_error_log` nor `debug.log` exists.
- **Cleanup:** both tables have 0 rows with AUTO_INCREMENT at 1; 0 products; 1 user.

### Locked decision for Phase 4: QR code + optional barcode

_Recorded before Phase 4 and implemented in Phase 4 (see below)._

- **QR code: always generated.** It is the primary scan method, using a phone camera.
- **Barcode: optional and OFF by default.**
  - It is controlled by one admin-only setting, "Enable barcodes (for hardware scanners)".
  - Changing that setting requires `pqbg_manage_settings`.
- **Both encode the same product code**, so turning barcodes on later needs no regeneration.
- **When barcodes are disabled:**
  - no barcode is rendered anywhere
  - no barcode library code runs

### Phase 4: QR code + optional barcode rendering (2026-09-24)

**Status:** implemented and tested. The plugin stays active. **Approved** by the user after two review rounds, then committed as a single commit ("Phase 4: QR code + optional Code 128 barcode rendering, settings page, tests and build") and pushed to `origin/main` with a normal push (no force).

**Environment:** re-verified at the start with no differences from the notes above.
- WP 7.1.2, WC 11.1.2, PHP 8.5.6, MariaDB 10.4.32
- `pqbg_*` tables at 0 rows, 0 products, 1 user
- `/scan/` returned 404

**Approved decisions (from the plan review):**
- **PHP minimum raised from 8.1 to 8.2**, in the plugin header, `Requirements::MIN_PHP` and the READMEs.
  - The only maintained Code 128 library that qualifies, picqer 3.x, requires PHP 8.2. The 2.x branch supports 8.1 but hasn't had a release since 2024-09.
  - PHP 8.1 has been end-of-life since 2025-12-31.
- The local-address check also covers `*.localhost` and single-label hostnames.
- An ABSPATH guard is injected into every vendor file by a PHP-Scoper patcher.
- Regression tests and the build config are **kept in the repo**, in `tests/` and `build/`. This supersedes "delete tests afterwards" for those files.

**Libraries** (bundled, namespace-prefixed under `ProductQrBarcode\Vendor\` with PHP-Scoper 0.18.19, in `vendor-prefixed/`):

| Package | Version | License | Why |
|---|---|---|---|
| `bacon/bacon-qr-code` | 3.1.1 (2026-04-05) | BSD-2-Clause | QR encoder. PHP `^8.1`, needs `ext-iconv`, no GD/Imagick. |
| `dasprid/enum` | 1.0.7 | BSD-2-Clause | bacon's only dependency |
| `picqer/php-barcode-generator` | 3.3.0 (2026-08-22) | LGPL-3.0-or-later | Code 128 encoder. PHP `^8.2`, no dependencies, no GD for SVG. |

- chillerlan/php-qrcode was evaluated and not chosen. tc-lib-barcode was rejected because it requires `ext-gd`.
- **License note:** LGPL-3.0 is compatible with the plugin's GPL-2.0-or-later only through "or later", so the bundle as distributed is effectively GPLv3.
- **Why PHP-Scoper:** Strauss 0.30.0 fails on Windows.
- **Build:** reproducible (two builds were byte-identical), with Composer 2.10.3 and PHP-Scoper pinned by SHA-256. The Composer checksum matches getcomposer.org's.

**Design:**
- **Encoders only.** The libraries produce the bit matrix and the bars; `Svg` writes the markup.
  - Output is `<svg>`, `<rect>`, `<path>` and `<text>` only, with integer attributes and the escaped code as the only text.
  - No prolog, DOCTYPE, ids, scripts, styles or links.
  - picqer's own SVG was not used: no quiet zone, no text, an external DTD reference and a hard-coded `id`.
- **QR:**
  - payload is exactly `{base}/scan/{CODE}/`
  - EC level M, 4-module quiet zone
  - ISO-8859-1 byte mode, so no ECI header
- **Code 128:** content is the code only, 10-module quiet zones, the code printed beneath.
- **Renderers:**
  - take a code string validated with `CodeGenerator::is_valid_format()`
  - never touch the database, generate codes or write files
  - render on demand with no cache
- **Barcode setting checked first.** When barcodes are disabled, the result is `pqbg_barcode_disabled` and the library is never autoloaded.
- **`ScanUrl` is the only place scan URLs are built.** The `/scan/` route is **not** registered and still returns 404.
- **Settings:**
  - New keys `barcodes_enabled` (default `false`) and `scan_base_url` (default `''`, meaning `home_url()`) inside `pqbg_settings`.
  - No new option and no migration.
  - `sanitize()` changes only the submitted keys.
  - Base URL validation: strict absolute http(s), at most 100 characters, trailing slash, query string and fragment stripped, invalid input keeps the old value.
- **Settings page:** WooCommerce → QR & Barcodes.
  - Only users with `pqbg_manage_settings` can see it.
  - Settings API through `options.php`, with the `option_page_capability_pqbg_settings` filter, a nonce and escaping.
  - It calls `settings_errors()` without a filter, because "Settings saved." is filed under `general`. This was a bug caught by the HTTP test.
- **Scan URL notices** for users with `pqbg_manage_settings` on every admin screen, at most one at a time:
  - The local-address warning (exact approved wording) takes priority. The dev site shows it.
  - Otherwise, a public host on plain `http://` gets "Labels should use an https:// scan URL in production." This is a warning only; `http://` URLs remain valid and are saved.
  - Both are shown by `SettingsPage::scan_url_notice()`, using `Settings::is_local_url()` and `Settings::is_http_url()`.

**Files created** (plugin-relative):
- `includes/ScanUrl.php`, `includes/Settings.php`, `includes/Svg.php`, `includes/QrRenderer.php`, `includes/BarcodeRenderer.php`, `includes/SettingsPage.php`
- `vendor-prefixed/`: 135 scoped library files, 3 LICENSE files, `NOTICE.md`, and 26 `index.php` stubs. Generated; don't edit.
- `build/`: `composer.json`, `composer.lock`, `scoper.inc.php`, `patcher.php`, `build.php`, `README.md`, `.htaccess` (`Require all denied`), `index.php`
- `tests/`:
  - suites and runner: `bootstrap.php`, `run.php`, `phase2-main.php`, `phase2-lifecycle.php`, `phase2-no-woocommerce.php`, `phase3-codes.php` (a reconstruction), `phase4-rendering.php`
  - `README.md`, `.htaccess`, `index.php`
  - `decoder/`: `package.json`, `package-lock.json`, `decode.mjs`, `index.php`

**Files modified:**
- `product-qrcode-barcode-generator.php`: vendor prefix map in the autoloader, `Requires PHP: 8.2`, description
- `includes/Plugin.php`: new default keys; `SettingsPage::register()` on admin requests
- `includes/Requirements.php`: `MIN_PHP = '8.2'`
- `README.md` (plugin)
- `progress.md`
- root `.gitignore`: un-ignores the two `.htaccess` files and ignores `build/tools/` and `build/scoped/`. `vendor/` and `node_modules/` were already ignored.

**Tests.** They are committed now: `php tests/run.php`, run from the CLI outside the web root, with the production guard overridden via `PQBG_TESTS_ALLOW_PRODUCTION=1`, because `WP_ENVIRONMENT_TYPE` is not set in `wp-config.php` yet. **Final run: all passed, 385 checks, 0 failed, 0 skipped** (with the decoder installed).

| Suite | Result | Notes |
|---|---|---|
| phase2-main | 83/83 | the original 80, plus 2 cleanup checks and a no-PHP-notices check |
| phase2-lifecycle | 17/17 | the original 16, plus the notice check |
| phase2-no-woocommerce | 12/12 | the original printouts turned into assertions |
| phase3-codes | 109/109 | **a reconstruction** of the deleted 92-check suite; covers every category in the Phase 3 record |
| phase4-rendering | 164/164 | see below; 5 of these are round-trip checks that SKIP (not fail) without Node.js or the decoder install |

**Phase 4 coverage:**
- **Lazy loading:** 0 library classes at start. QR renders while no Picqer class or file has been loaded. Only a stored boolean `true` enables barcodes.
- **Payload:** exactly `{base}/scan/{CODE}/` for 8 codes × 5 bases. No class other than `ScanUrl` contains a scan path literal.
- **Round-trip:** SVGs rasterised with resvg 2.6.2 and decoded with ZXing-C++ (zxing-wasm 3.1.4).
  - QR: **12/12** decode to the exact URL, and the decoder reports EC level **M** (3 base URLs).
  - Barcode: **8/8** decode to the exact code.
  - Damaged QR and barcode negative controls do not decode.
- **Geometry:** the quiet zones are exactly 4 and 10 modules, and the text sits below the bars.
- **Invalid input:** 18 invalid-code cases rejected by both renderers.
- **SVG safety:** checked with a DOM allowlist of elements and attributes, plus a regex for script, `on*`, `href`, `url()` and DOCTYPE.
- **Base URLs:** 11 valid inputs normalised and 31 invalid inputs rejected. `sanitize()` merges correctly, keeps unrelated keys and handles errors. A checksum shows `pqbg_codes` untouched by base URL changes and renders. The renderers ran 0 queries.
- **Local-address check:** 18 local and 8 public hosts classified correctly. The notice appears and disappears correctly and is admin-only.
- **HTTP, logged in as temporary users:**
  - admin: 200, both fields, effective URL, warning, menu item, nonce
  - shop manager: direct URL 403, no menu item or warning on the Dashboard
  - seller: kept out
  - logged out: redirected to login
- **HTTP saving:**
  - A valid save is normalised, keeps unrelated keys and shows "Settings saved.".
  - An invalid URL keeps the old value and the error is shown.
  - A bad nonce gets 403.
  - A shop manager **with a valid nonce** still gets 403.
  - Seller and logged-out saves are refused.
- **Vendor isolation:**
  - all 135 files prefixed and guarded
  - 0 unprefixed classes, 0 vendor global functions
  - direct HTTP to vendor and new include files returns an empty 200
  - `tests/` and `build/` return 403
- **Scope:** no REST, AJAX, admin-post or shortcode additions and no rewrite rules. `/scan/` and `/scan/{CODE}/` return 404. Nothing is written to uploads.
- **Performance:** QR about 44–50 ms, barcode under 1 ms.

**Cleanup (verified):**
- `pqbg_codes` and `pqbg_sales` at 0 rows, AUTO_INCREMENT reset
- `pqbg_settings` restored byte-for-byte (`{"settings_version":1}`; the new keys come from defaults)
- 0 products, 1 user
- 22 Action Scheduler jobs from the Phase 3 test products removed
- the temporary directory removed
- no scratch files under the WordPress directory: the decoder's `node_modules` and the build tools/vendor were kept outside or deleted
- HTTP: `/` 200, `/shop/` 200, `/wp-login.php` 200, `/wp-admin/` 302

**Incident during development:**
- One PHP fatal error on the dev site at 2026-09-24 18:18:57 (local time): `Class "ProductQrBarcode\SettingsPage" not found`.
- It happened because `Plugin.php` was edited to call `SettingsPage::register()` about two minutes before `SettingsPage.php` was written, and one request arrived in that window.
- It was recorded in `wp-content/uploads/wc-logs/fatal-errors-2026-09-24-*.log` and the Apache `error.log`. There has been no error since.
- With the user's permission, the WooCommerce log file was deleted; it contained only that one entry. The line in Apache's `error.log` was left alone: it is a shared, live server log that Apache holds open, and rewriting it could break logging.
- **Lesson:** on the live dev site, create a new class file before wiring it into boot code.

**Known limitations:**
- **QR rendering takes about 50 ms** because bacon's pure-PHP encoder scores 8 masks. Bulk label printing (Phase 8) should add caching or batching; 100 labels take about 5 s.
- **The round-trip tests need Node.js** and `npm ci` in `tests/decoder` (documented in `tests/README.md`). Without them the 5 round-trip checks are **skipped** with a clear message, and the rest of the suite still runs; both cases (decoder not installed, Node not on `PATH`) were verified: 159 passed, 5 skipped. `node_modules/` is ignored by `.gitignore` and never committed. The decoder loads its wasm locally (zxing-wasm downloads it from jsDelivr by default); this was verified with `fetch` blocked.
- **The HTTP tests need the site to be reachable** at `home_url()`. They were run on Apache/XAMPP, and the `.htaccess` denial is Apache-only; `index.php` stubs cover directory listing elsewhere.
- **The Phase 3 suite is a reconstruction**, not the original script.
- **The renderers check format, not existence.** A retired code still renders; Phase 5 decides what the admin UI shows.
- **Human-readable barcode text uses a generic `monospace` font**, so its look depends on the viewer's fonts. The bars don't depend on fonts.
- **The local-address warning checks the host name only.** It doesn't resolve DNS, so a public-looking name that points to a private IP isn't flagged.
- **No PHPCS run**, because it isn't installed.

**Open items carried to Phase 5 (admin code management):**
- Generate codes on the first real save, including drafts, but **never for auto-drafts**.
- Trashing a product keeps its code active; permanent deletion retires it.
- Atomic regeneration: retire and create in one transaction, behind `pqbg_manage_codes`.
- Decide what the admin UI shows for retired codes, since the renderers will render any well-formed code.

**Open item carried to Phase 6 (scan/product screen):**
- Normalise manually typed codes (trim + uppercase) before lookup. The renderers deliberately do not normalise.

**Next phase (not started):** Phase 5, Admin Code Management. **The production domain is still not final**, so labels must not be printed yet. The admin warning says so until the scan base URL is public.

### Instructions for the next Claude session

- Read this file and `wp-content/plugins/product-qrcode-barcode-generator/README.md` first. Re-verify the environment; don't trust these notes blindly.
- **Current names:**
  - plugin "Product QR Code and Barcode Generator"
  - slug `product-qrcode-barcode-generator`
  - namespace `ProductQrBarcode`
  - prefix `pqbg_` / `PQBG_`
  - role `pqbg_seller` "Store Seller"

  The `dpc_`/`DPC_`/`Durga\ProductCodes` names in the Phase 2 and Phase 3 sections are pre-rename history. Never reintroduce them.
- Get codes only through `ProductCodeService::get_or_create()`. Don't call `CodeRepository::create_active()` with hand-made strings, and don't write to `pqbg_codes` directly.
- Phase 4 is done. Build scan URLs only through `ScanUrl`, and render only through `QrRenderer`/`BarcodeRenderer`. Never reference the barcode library outside `BarcodeRenderer`, and keep the "barcodes disabled means the library is not loaded" guarantee.
- **Run `php tests/run.php` before and after every phase** (see `tests/README.md`). Preferred: `define( 'WP_ENVIRONMENT_TYPE', 'local' );` in the local `wp-config.php`, which the user will add themselves; never edit or commit `wp-config.php`. The fallback is `PQBG_TESTS_ALLOW_PRODUCTION=1`. The round-trip checks need `npm ci` in `tests/decoder`, or `PQBG_DECODER` pointing to a copy outside the web root. Add each new phase's suite to `tests/` and to `run.php`.
- **Exclude `tests/` and `build/` from any production deployment** (see "Production deployment" in the plugin README).
- **Never edit `vendor-prefixed/` by hand.** Change `build/` and run `php build/build.php` (see `build/README.md`).
- The PHP minimum is now **8.2**.
- On this live dev site, create new class files **before** referencing them from boot code (see the Phase 4 incident).
- Phase 5 must handle the open items listed at the end of the Phase 4 section.
- Don't add product-save hooks for automatic code generation without explicit approval.
- Nothing in this file authorizes future work. Each phase needs explicit user approval.
- **Never commit or push without explicit approval.** No reset, rebase, amend or force-push.
- Schema changes go through a new migration (`Install::migrations()` plus a `DB_VERSION` bump). Never edit data by dropping or recreating tables.
- All privileged operations must use `Permissions` helpers server-side. Never use `__return_true`.
- Price and stock always come from WooCommerce server-side. Insufficient stock blocks a sale; backorders are not bypassed.
- Sellers are our own staff. This is not a marketplace or vendor system.
- Test scripts must remove every test row they create.

---

## Work log

### 2026-09-24
- Verified the WordPress installation end to end: core files, `wp-config.php`, database connectivity, table set, and HTTP response.
- Found 1 published page, 1 published post, 1 draft page, 1 navigation menu — stock post-install content.
- Flagged three housekeeping issues: the 37 MB installer zip sitting in the web root, an empty leftover `wordpress/` folder, and an empty WordPress block in `.htaccess`.
- Initialized git, committed `README.md`, pushed to `origin/main` (`252e2d0`).
- Wrote `.gitignore` and confirmed the sensitive paths were excluded before staging anything else.
- Dropped the stock `wp-content/index.php` from tracking — it is a WordPress core file, not project code.
- Committed `.gitignore` and `progress.md`, pushed to `origin/main` (`094e737`).
- Audited tracking before the next push: verified the tracked set, ran `git add -A --dry-run`, scanned recursively for untracked-and-unignored files (0 found), and checked the full commit history for sensitive paths (none). No corrections were needed.
- Noted that `git check-ignore wp-content` reports the directory as unignored because the rule is `wp-content/*`, which matches contents rather than the folder — expected git behaviour, no effect on what gets staged.
- Confirmed `wordpress/` was genuinely empty (0 entries including hidden files) and that `wordpress-7.1.zip` was referenced by no code or config, then deleted both.
- Re-checked site health after deletion: homepage and login still return 200, and the zip URL now returns 404. The installer remains re-downloadable from wordpress.org if ever needed.
- **Phase 2 (Durga Product Codes foundation):**
  - Re-verified the environment. Differences from the older notes: Classic Editor and WooCommerce are active, and permalinks are already `/%postname%/`.
  - Built the plugin foundation and data layer, activated it, and ran 80 automated checks plus lifecycle, WooCommerce-missing and HTTP tests. All passed.
  - Removed all test data.
  - With the user's approval, committed as `50d7e0b` and pushed to `origin/main`.
- **Phase 3 (secure product code generation):**
  - Re-verified the environment. There were no differences.
  - Reviewed the Phase 2 code before changing anything.
  - Added `CodeGenerator` and `ProductCodeService`, plus `CodeRepository::code_exists()`. No schema change and no hooks.
  - Phase 3 suite passed 92 of 92. The Phase 2 suites still pass: 80 of 80 main and 16 of 16 lifecycle.
  - Removed all test products, users, codes and Action Scheduler jobs.
  - Not committed; waiting for approval.
- **Plugin rename:**
  - Committed and pushed Phase 3 as `a2e5643`, with approval.
  - Renamed "Durga Product Codes" to "Product QR Code and Barcode Generator": `git mv` of the folder and main file, then the full identifier map (`pqbg_`/`PQBG_`, `ProductQrBarcode`, "Store Seller"). Added `Update URI: false`.
  - Confirmed the old tables were empty, then removed the old `dpc_` state with a one-time CLI script and activated the renamed plugin.
  - All suites pass under the new names: rename 25/25, Phase 2 80/80, lifecycle 16/16, WooCommerce-missing OK, Phase 3 92/92.
  - Recorded the locked Phase 4 QR/barcode decision.
  - With the user's approval, committed as `5f301be` and pushed to `origin/main`.
- **Phase 4 (QR code + optional barcode rendering):**
  - Re-verified the environment. There were no differences.
  - Evaluated the libraries (Packagist metadata, license files, a spike on PHP 8.5.6) and chose bacon/bacon-qr-code 3.1.1 and picqer/php-barcode-generator 3.3.0. With approval, raised the PHP minimum to 8.2.
  - Prefixed the libraries with PHP-Scoper (Strauss fails on Windows) into `vendor-prefixed/`, with ABSPATH guards and a reproducible, pinned build in `build/`.
  - Added `ScanUrl`, `Settings`, `Svg`, `QrRenderer`, `BarcodeRenderer` and `SettingsPage` (WooCommerce → QR & Barcodes, administrators only), plus the local-address warning.
  - Moved the regression tests into `tests/`: the ported Phase 2 suites, a reconstructed Phase 3 suite and a new Phase 4 suite, with a runner and an offline round-trip decoder. 379/379 checks passed at first review.
  - The HTTP tests caught a real bug: "Settings saved." was not displayed. Fixed.
  - One transient fatal error during development, caused by the order of edits; see the Phase 4 section.
  - Review round 2 (user's requested changes):
    - round-trip checks now SKIP without Node or the decoder, and the install is documented
    - README states that `tests/` and `build/` must be excluded from production
    - new `http://` public-host warning, with tests
    - `WP_ENVIRONMENT_TYPE=local` documented as the preferred test guard
    - dev-gap fatal log file deleted
  - Final run: 385/385.
  - Approved; committed as one commit and pushed to `origin/main`.
