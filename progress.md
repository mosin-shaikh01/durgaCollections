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
| Timezone | **Asia/Kolkata** (`timezone_string`; `gmt_offset` stored empty, as WordPress stores it for a city). Set on 2026-09-25 with the user's approval, after the Phase 7 phone test. It was UTC before. |
| Active theme | twentytwentyfive |
| Active plugins | classic-editor, woocommerce, product-qrcode-barcode-generator (Product QR Code and Barcode Generator; installed in Phase 2 under its former name) |
| Admin user | Dev-admin |

_Environment re-verified 2026-09-24 at the start of Phase 2, at the start of Phase 3, at the start of the plugin rename, and at the start of Phases 4 and 5, and on 2026-09-25 at the start of Phases 6, 7 and 8. There were no differences apart from the rename itself. Also recorded in Phase 6: `blog_public = 0` (core sitemaps off), and logged-out visitors to `/my-account/` see the Coming Soon page._

---

## Repository

Branch `main`, tracking `origin/main`.

- Phase 2 was committed as `50d7e0b` and Phase 3 as `a2e5643`; both are pushed to `origin/main`.
- The plugin rename was committed as `5f301be` and pushed to `origin/main`, with the user's approval (normal fast-forward, no force).
- Phase 4 was committed as `3654086` ("Phase 4: QR code + optional Code 128 barcode rendering, settings page, tests and build") and pushed to `origin/main`, with the user's approval (normal fast-forward, no force).
- Phase 5 was committed as `ff7805c` ("Phase 5: admin code management (auto-assignment, lifecycle, atomic regeneration, product panel, downloads)") and pushed to `origin/main`, with the user's approval (normal fast-forward, no force).
- Phase 6 was committed as `381a909` ("Phase 6: scan route and mobile product screen (access control, status matrix, entry box)") and pushed to `origin/main`, with the user's approval after their phone test (normal fast-forward, no force).
- Phase 7 was committed as `2413698` ("Phase 7: mark as sold with atomic stock journal, undo, online-race compensation (schema v2)") and pushed to `origin/main`, with the user's approval after their phone test (normal fast-forward, no force).

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
| `2413698` | Phase 7: mark as sold with atomic stock journal, undo, online-race compensation (schema v2) |
| `ee2769b` | Record Phase 6 commit and push in progress log |
| `381a909` | Phase 6: scan route and mobile product screen (access control, status matrix, entry box) |
| `7b9cc8f` | Record Phase 5 commit and push in progress log |
| `ff7805c` | Phase 5: admin code management (auto-assignment, lifecycle, atomic regeneration, product panel, downloads) |
| `a3f654e` | Record Phase 4 commit and push in progress log |
| `3654086` | Phase 4: QR code + optional Code 128 barcode rendering, settings page, tests and build |
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
| **4** | **QR code + optional barcode rendering** | **Done 2026-09-24. Committed `3654086`, pushed.** |
| **5** | **Admin code management** | **Done 2026-09-25. Committed `ff7805c`, pushed.** |
| **6** | **Scan/product screen** | **Done 2026-09-25. Committed `381a909`, pushed.** |
| **7** | **Mark sold + sales** | **Done 2026-09-25. Committed `2413698`, pushed.** |
| **8** | **Printing** | **Done 2026-09-25. Approved; committed and pushed (see Repository).** |
| 9 | Seller dashboard / sales history | Next. Not started |
| 10 → 12 | bulk/CSV → hardening/performance → QA/documentation | Not started |

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

**Status:** implemented and tested. The plugin stays active. **Approved** by the user after two review rounds, then committed as a single commit, `3654086` ("Phase 4: QR code + optional Code 128 barcode rendering, settings page, tests and build") and pushed to `origin/main` with a normal push (no force).

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

**Open items carried to Phase 5 (admin code management):** _all handled in Phase 5; see below._
- Generate codes on the first real save, including drafts, but **never for auto-drafts**.
- Trashing a product keeps its code active; permanent deletion retires it.
- Atomic regeneration: retire and create in one transaction, behind `pqbg_manage_codes`.
- Decide what the admin UI shows for retired codes, since the renderers will render any well-formed code.

**Open item carried to Phase 6 (scan/product screen):**
- Normalise manually typed codes (trim + uppercase) before lookup. The renderers deliberately do not normalise.

**Next phase (not started):** Phase 5, Admin Code Management. **The production domain is still not final**, so labels must not be printed yet. The admin warning says so until the scan base URL is public.

### Phase 5: Admin code management (2026-09-24 → 2026-09-25)

**Status:** implemented and tested. The plugin stays active. **Approved** by the user, then committed as a single commit, `ff7805c` ("Phase 5: admin code management (auto-assignment, lifecycle, atomic regeneration, product panel, downloads)") and pushed to `origin/main` with a normal push (no force).

**Environment:** re-verified at the start with no differences from the notes above.
- WP 7.1.2, WC 11.1.2 (HPOS on), PHP 8.5.6, MariaDB 10.4.32
- plugins: classic-editor, woocommerce, pqbg
- `pqbg_*` tables at 0 rows, 0 products, 1 user, `pqbg_settings = {"settings_version":1}`
- `/scan/` returned 404
- WooCommerce's **block-based product editor is disabled** (`product_block_editor` off)
- `WP_ENVIRONMENT_TYPE` is still not set, so tests use `PQBG_TESTS_ALLOW_PRODUCTION=1`

**Baseline before any change:** `php tests/run.php` ALL PASSED, **380 passed, 0 failed, 5 skipped** (decoder not installed).

**Approved decisions (plan review):**
1. **Product-save hooks are approved.** This lifts the "no save hooks without approval" rule for Phase 5.
2. **Retirement on delete or type change is not gated by `pqbg_manage_codes`.** WordPress has already authorised the action. `retired_by` = the current user, or 0. Generation is always gated.
3. **Auto-drafts are refused in `ProductCodeService::eligibility()`** (`pqbg_ineligible_status`), and so are variations of an auto-draft parent. Drafts, pending and private items stay eligible.
4. **Regeneration carries the expected code ID.** A stale ID changes nothing (`pqbg_code_changed`), which protects against double clicks and second tabs.
5. **The confirmation step is a server-rendered hidden admin page** (GET, no side effects), not a JS `confirm()`.
6. History times are stored in GMT and displayed in the **site timezone** with `wp_date()`.
7. "Retired by" shows **"System"** for 0 and **"User #ID (deleted)"** for a user that no longer exists.
8. The decoder is installed **outside the web root** (scratchpad) with `npm ci`, and `PQBG_DECODER` points to it, so no round-trip check is skipped.

**Hook decisions (verified in the installed WooCommerce 11.1.2 source):**
- **Saves: WooCommerce CRUD hooks.**
  - `woocommerce_new_product` / `woocommerce_update_product` (`class-wc-product-data-store-cpt.php`)
  - `woocommerce_new_product_variation` / `woocommerce_update_product_variation` (`class-wc-product-variation-data-store-cpt.php`)
  - They fire after the object, its type term and its parent are saved, on every `WC_Product::save()` path: classic edit screen, Add variation (`WC_AJAX::add_variation`), Save changes (`save_variations`), Quick and Bulk Edit (`WC_Admin_Post_Types`), CSV importer, REST, Duplicate (`WC_Admin_Duplicate_Product`, copy saved as draft).
  - `save_post` was rejected: it fires for core's auto-draft (`get_default_post_to_edit`) and revisions, and before WooCommerce writes the product type on the edit screen.
- **Deletes: core `deleted_post`**, after the row is gone.
  - WooCommerce deletes variations with `wp_delete_post()` both when the parent is deleted (`WC_Post_Data::delete_post_data` on `delete_post`) and when a product stops being variable (`update_version_and_type()` → `product_type_changed` → `delete_variations(…, true)`). The type change happens **before** `woocommerce_update_product` fires.
  - Safety net: deleting a product also retires codes still active under it as `parent_id`.
- **Trash and untrash: no hooks.** Codes stay active.
- **No product save runs inside a DB transaction** in WC 11.1.2. Only the Fulfillments store uses `wc_transaction_query`. This matters because `START TRANSACTION` implicitly commits an outer one.
- **New AJAX variations get their code immediately** if the parent is saved as variable.
  - If the parent is an auto-draft, or still `simple` in the database (`add_variation` only forces the type to variable in memory), the variation gets its code on the parent's first real save, through the **parent sweep**.
  - The sweep runs on every eligible variable-parent save and does one batched query.
- **CSV:** WC 11.1.2 itself rejects a new variation whose parent is still an import placeholder (`woocommerce_product_importer_variation_parent_missing`), so variations always arrive after their parent.
- **WooCommerce's `prevent_admin_access` exempts `admin-post.php` and `admin-ajax.php`**, so sellers do reach admin-post. The capability check refuses them with 403.

**Files created** (plugin-relative):
- `includes/CodeLifecycle.php`: save and delete hooks, parent sweep, retirement, save-failure notice (per-user transient `pqbg_save_failure_{user}`, shown once, dismissible). Registered on **every** request.
- `includes/AdminProductPanel.php`: meta box "QR & Barcode", variation panel text, "Code" list column, hidden confirmation page (validated on `load-{hook}` before output), assets, result notices.
- `includes/AdminActions.php`: `admin_post_pqbg_generate` (POST), `admin_post_pqbg_regenerate` (POST), `admin_post_pqbg_code_image` (GET view or download). Nonces are bound to the item, and there is no `nopriv`.
- `assets/admin.js` (click-to-load QR as `<img>`, delegated), `assets/admin.css`, `assets/index.php`
- `tests/phase5-admin.php`

**Files modified:**
- `includes/CodeRepository.php`: `replace_active()` (atomic: lock, retire, insert, one transaction, rollback on any failure), `find_active_for_products()`, `find_active_by_parent()`, `find_retired_for_product_or_parent()`
- `includes/ProductCodeService.php`: `regenerate()`, `retire_for_item()`, the auto-draft rule, `log_error()`
- `includes/Plugin.php`: wiring, done last, after the new class files existed
- `tests/run.php`: registers the suite
- `tests/phase3-codes.php`: the "no product save hooks" scope check was split for the approved hooks
- `tests/README.md`, `README.md` (plugin), `progress.md`

**No schema change:** `DB_VERSION` is still 1. `vendor-prefixed/`, the renderers, `ScanUrl` and the settings are unchanged.

**Tests.** `php tests/run.php`, with `PQBG_TESTS_ALLOW_PRODUCTION=1` and `PQBG_DECODER` pointing at the scratchpad decoder. **Final run: ALL PASSED, 542 checks, 0 failed, 0 skipped.**

| Suite | Result |
|---|---|
| phase2-main | 83/83 |
| phase2-lifecycle | 17/17 |
| phase2-no-woocommerce | 12/12 |
| phase3-codes | 110/110 (scope check split) |
| phase4-rendering | 164/164, **0 skipped** (the 5 round-trip checks ran) |
| phase5-admin | 156/156, **0 skipped** |

**Phase 5 coverage:**
- **Auto-assignment over real HTTP:**
  - auto-draft gets no code
  - first save as draft, pending, private and publish, with `created_by` set
  - idempotent repeated saves
  - Add variation, both on a saved variable product (immediately) and on an auto-draft parent (on first save)
  - Save changes
  - Quick Edit and Bulk Edit
  - REST: create, variation, update, force delete
  - Duplicate: simple and variable, all codes new, nothing in meta
- **CSV import** (in-process through `WC_Product_CSV_Importer`): codes assigned, re-import idempotent.
- **Other save paths:**
  - revisions get no code
  - cron or user 0: no code, no error
  - a shop manager without the capability: the save succeeds, with no code, notice, panel or column
  - failure injection: save not blocked, error-code-only log line, notice shown once
- **Lifecycle over HTTP:**
  - trash, untrash (same code), delete (retired with `retired_by`)
  - variable parent delete retires every variation code
  - Empty Trash
  - every type change
- **Regeneration:**
  - old code retired with its metadata, exactly one active code
  - stale expected ID refused
  - forbidden users refused
  - a **mid-transaction failure** (the INSERT collides after the retire UPDATE) leaves the original active and the table checksum unchanged
  - one collision then success
- **Real concurrency:** 4 PHP processes at once.
  - Without an expected ID: 4 successes, 5 rows, 1 active.
  - With the same expected ID: 1 winner, 3 × `pqbg_code_changed`, 1 active.
- **History:**
  - `wp_date()` in the site timezone, checked with a filtered `Asia/Kolkata`: UTC 00:00 → 05:30
  - "System"
  - "User #ID (deleted)"
  - "Variation #ID (deleted)"
- **UI over HTTP:**
  - the panel for simple (exactly 1 QR), barcode on and off, no code, variable (table, 0 QR), grouped, external
  - the Generate form sits outside the post form
  - click-to-load view
  - variations panel (AJAX)
  - list column
  - the confirmation page has no side effects and isn't in the menu
  - regenerate, and a replay returns `pqbg_code_changed`
  - GET to generate or regenerate: 405
- **Downloads:**
  - exact headers and filenames
  - the body equals the `QrRenderer` output
  - **round-trip decoded:** the QR decodes to the exact scan URL at EC level M, and the barcode to the code
  - a retired code is never served, after regeneration or after deletion
  - an item without an active code: 404, never generated
  - barcode handler: 404 while disabled
- **Permissions:**
  - seller, logged-out and nocap users refused on every handler and on the confirmation page, even with the admin's valid nonces
  - missing, invalid and other-item nonces: 403 on all four
  - shop manager allowed
- **Barcodes disabled:** 0 Picqer classes loaded after every panel, the variation text, the column, the save hooks and regeneration.
- **Scope:**
  - no REST routes, shortcodes, rewrite rules, `nopriv` handlers or `wp_ajax_` in code tokens
  - the barcode library is referenced only in `BarcodeRenderer`
  - no `$wpdb` writes outside `CodeRepository`/`Install`/`Schema`
  - `/scan/` and `/scan/{CODE}/` return 404
  - direct HTTP to the new files returns empty output
- **Cleanup:**
  - tables at 0 rows, AUTO_INCREMENT reset
  - 0 products
  - settings restored byte-for-byte
  - temporary users and transients removed
  - Action Scheduler jobs for test products removed

**40-variation performance** (dev machine, last full run). The edit screen renders **0 QR codes** for a variable product and exactly 1 for a simple one.

| Measurement | Result |
|---|---|
| Panel render, in-process, median of 5 | about 34 ms (final run); 18–54 ms across runs |
| Edit page as shop manager **with** the panel, HTTP median of 5 | about 624 ms (final run) |
| The same page for a shop manager **without** `pqbg_manage_codes` (no panel) | about 539 ms (final run). The overhead was 85–150 ms across runs. |
| Parent re-save with the sweep (40 codes already present) | about 60–80 ms |
| Creating a product with 40 variations | 5.34 s with codes vs 4.81 s without (final run); WooCommerce's own save dominates |

The variations table primes post and meta caches with one `get_posts()` call. Before that, the HTTP difference was about 150 ms.

**Known limitations:**
- **Classic product editor only.** The block-based product editor is unsupported, and it is disabled here.
- Code that creates products with `wp_insert_post()` directly, bypassing WooCommerce CRUD, gets no code until the next WooCommerce save or a manual Generate.
- The failure notice is per user and shows once on the next admin page. A failure during a REST or import save as a user who never opens wp-admin is only logged.
- HTTP timings are noisy on XAMPP (they varied by ±30% between runs). Only the in-process panel budget is asserted.
- `CodeLifecycle::set_service()` is a test seam, PHP-only and unreachable from requests, like the injectable `CodeGenerator` from Phase 3.
- No PHPCS run, because it isn't installed.

**Open items carried to Phase 6 (scan/product screen):** _both handled in Phase 6; see below._
- Normalise manually typed codes (trim + uppercase) before lookup. The renderers deliberately do not normalise.
- **Define what the scan page shows when the code's product or variation is in trash, draft, pending or private.** Codes stay active in all those states per Phase 5. Do not implement before it is decided.

**Next phase (not started):** Phase 6, Scan/Product Screen. **The production domain is still not final**, so labels must not be printed yet.

### Phase 6: Scan / product screen (2026-09-25)

**Status:** implemented and tested. The plugin stays active. The user ran the manual phone test (Cloudflare quick tunnel, see the plugin README) and it **passed**. **Approved**, then committed as a single commit, `381a909` ("Phase 6: scan route and mobile product screen (access control, status matrix, entry box)"), and pushed to `origin/main` with a normal push (no force).

**Environment:** re-verified at the start. There were no differences from the notes above, apart from two facts recorded for the first time:
- WP 7.1.2, WC 11.1.2 (HPOS on), PHP 8.5.6, MariaDB 10.4.32, permalinks `/%postname%/`, Coming Soon on for the whole site
- `pqbg_*` tables at 0 rows, 0 products, 1 user, `/scan/` 404
- `blog_public = 0`, so core sitemaps are disabled
- logged-out visitors to `/my-account/` get the Coming Soon page, not the login form; `wp-login.php` still works

**Baseline before any change:** `php tests/run.php` ALL PASSED, **542 passed, 0 failed, 0 skipped**. The decoder was freshly `npm ci`'d into this session's scratchpad and used through `PQBG_DECODER`.

**Approved decisions (plan review, all 8 as proposed):**
1. **Logged-out scans go to `wp-login.php`.** Coming Soon hides the My Account form from logged-out visitors. The My Account form is supported when it is opened with `?redirect_to=<scan URL>`.
2. **Store Sellers who log in without a destination** land on `/scan/`. This covers both `wp-login.php` and My Account.
3. **HTTP statuses:** 200 for screens, 404 unknown code, 400 invalid, 403 no capability, 405 method.
4. **Catch-all under `/scan/`.** The route is **inactive** under Plain or `index.php` permalinks, with only an admin notice.
5. **Strict CSP**, and `Referrer-Policy: same-origin`.
6. **All whitespace is removed** from typed codes, not just trimmed.
7. **An active code whose product is missing** shows "This label is no longer valid."
8. **The Phase 3/4/5 scope checks** that Phase 6 makes false by design are updated, following the Phase 5 precedent.

**Design:**
- **Routes:** `{home}/scan/` (entry box) and `{home}/scan/{CODE}/`, via two top rewrite rules `^scan/?$` and `^scan/(.+?)/?$`. The patterns are built in `ScanUrl::rewrite_rules()` from `ScanUrl::PATH`.
- **Query vars:** `pqbg_scan` and `pqbg_code`.
- **Flushing:** once on activation, and again when `PQBG_VERSION:RULES_VERSION` changes. The flag option is `pqbg_rewrite_version` (autoloaded, holds no data). The flush is soft. Deactivation removes the rules and the flag.
- **Answered on `parse_request`**, which is before the main query, `redirect_canonical`, `template_redirect`, the theme and Coming Soon (`template_include`).
- **Order of checks:** route available → GET/HEAD (else 405) → logged in (else 302 to `wp_login_url(canonical URL)`) → `pqbg_view_products` (else a fixed 403, no lookup) → canonical path (else 301) → entry box or status matrix.
- **Canonical URLs** are built on this site's `home_url()` (`ScanUrl::site_url()`), not on the scan base URL setting. The label payload `ScanUrl::for_code()` is **unchanged**.
- **Status → screen matrix:** implemented exactly as approved, in `ScanScreen::resolve()`. It reads `CodeRepository::find_by_code()`, which already returned retired rows, so no repository method was added.
- **Standalone template** `templates/pqbg-scan.php`:
  - no theme, no `wp_head`/`wp_footer`, no JavaScript
  - only `assets/pqbg-scan.css`, printed with `wp_print_styles()` on scan pages
  - the box at the top of every staff screen, with `autofocus`
  - a Log out link on every screen
- **Headers on every scan response** (including redirects): `Cache-Control: no-store…private`, `X-Robots-Tag: noindex, nofollow`, `Referrer-Policy: same-origin`, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, and a CSP with `default-src 'none'` and `frame-ancestors 'none'`. HTML pages also carry meta robots.
- **Login filters:**
  - `login_redirect` sends Store Sellers without a destination to `/scan/`
  - `woocommerce_login_redirect` honours a same-host scan `redirect_to` carried on the My Account URL (the referer), and sends Store Sellers without a destination to `/scan/`
  - Both do nothing for users without `pqbg_view_products`, or while the route is unavailable.
- **Admin notices** (only for `pqbg_manage_settings`):
  - Plain or `index.php` permalinks.
  - Content whose permalink is at or below `/scan/`. The check uses the real permalink path, so `/product-category/scan/` is not flagged.
- **File names avoid "/scan":** the stylesheet and template are named `pqbg-scan.*` because the Phase 4 guard "no scan path literal outside `ScanUrl`" matches `/scan\b` in any string.

**Files created** (plugin-relative):
- `includes/ScanRoute.php`, `includes/ScanScreen.php`
- `templates/pqbg-scan.php`, `templates/index.php`
- `assets/pqbg-scan.css`
- `tests/phase6-scan.php`

**Files modified:**
- `includes/ScanUrl.php`: `site_url()`, `site_path()`, `is_site_scan_url()`, `extract_code()`, `rewrite_rules()`, and the class docblock. `for_code()` is unchanged.
- `includes/Install.php`: `activate()`/`deactivate()` call `ScanRoute`.
- `includes/Plugin.php`: wiring, done last, after the class files existed.
- `uninstall.php`: always deletes `pqbg_rewrite_version`, since it is runtime-only like the install lock. The plan said the delete-all branch; this is a deliberate small change.
- `tests/run.php`: registers the suite.
- `tests/phase3-codes.php`, `tests/phase4-rendering.php`, `tests/phase5-admin.php`: scope checks split (see below). Phase 4 also got the auto-draft cleanup fix.
- `README.md` (plugin): new "Scan page" section with the flow, matrix, template, headers, login, permalinks, Coming Soon, phone testing and limitations; options, deactivation, uninstall and deployment (`templates/`) updated.
- `tests/README.md`, `progress.md`.

**No schema change:** `DB_VERSION` is still 1. `vendor-prefixed/`, the renderers, the settings and `CodeRepository` are unchanged.

**Changes to earlier suites** (their assertions were made false by the approved route):
- "no scan rewrite rules" (3/4/5) → no pqbg/scan rules except the two from `ScanUrl::rewrite_rules()`
- "`/scan/` returns 404" (4/5) → logged out, it redirects to the login page
- "no `add_rewrite_rule` in source" (5) → only in `ScanRoute.php`, as a separate check, so Phase 5 now has 157 checks
- "only the two pqbg options" (3) → also allows `pqbg_rewrite_version`

**Tests.** `php tests/run.php`, with `PQBG_TESTS_ALLOW_PRODUCTION=1` and `PQBG_DECODER` set. **Final run: ALL PASSED, 756 checks, 0 failed, 0 skipped** (5 min 31 s).

| Suite | Result |
|---|---|
| phase2-main | 83/83 |
| phase2-lifecycle | 17/17 |
| phase2-no-woocommerce | 12/12 |
| phase3-codes | 110/110 (two checks updated) |
| phase4-rendering | 165/165 (+1: auto-draft cleanup) |
| phase5-admin | 157/157 (+1: `add_rewrite_rule` only in `ScanRoute`) |
| phase6-scan | 212/212, **0 skipped** |

**Phase 6 coverage:**
- **Routing:**
  - both rules present, ahead of every page/post catch-all
  - subdirectory URLs
  - query vars
  - flush once: a stale flag flushes exactly once, repeated calls and HTTP requests never flush
  - 301 for lowercase, a missing slash, spaces, a query string and raw query vars
  - `/scan` → `/scan/`
  - `X-Redirect-By` is the plugin, not WordPress's canonical redirect
  - `/scan/a/b/` handled (400)
- **Matrix over HTTP as a seller:** every row, including:
  - private simple product (no banner) vs private variation (disabled banner)
  - draft, pending and scheduled
  - both banners together
  - variation under a private parent
  - a trashed parent through WooCommerce's cascade, and a trashed parent only
  - retired after regeneration, with the reprint link for a shop manager but not for a seller
  - retired with the product deleted
  - active with the product deleted outside WordPress
  - unknown code, invalid code
- **Rendering:**
  - sale price with `<del>`/`<ins>` and ₹; a single price; "Price not set"
  - stock: managed quantity, unmanaged, out of stock, on backorder, managed on the parent
  - categories, SKU, lazy image, the code
  - a live price change shows on the next scan
  - no cost, supplier or customer fields
- **Escaping:** product name, category, entry-box input and path segment all carrying script/HTML payloads; no `<script>` on any screen.
- **Access:**
  - logged out: 302 with the exact `redirect_to` for every code type, and no product data
  - customer and subscriber: 403 with byte-identical bodies and identical headers (except Date) across active, retired, unknown, invalid, lowercase and trashed codes, the entry page and `?code=`
  - POST gets 405; HEAD gets 200 with an empty body
- **Login round trips:**
  - `wp-login.php` for admin, shop manager and seller: scan → login → the same scan URL → product screen
  - seller with no `redirect_to`, or `redirect_to` = wp-admin → `/scan/`
  - shop manager and customer defaults unchanged
  - customer with a scan `redirect_to` → 403
  - My Account form, with Coming Soon briefly off, for admin, shop manager and seller
  - seller with a plain My Account login → `/scan/`
  - My Account POST **with Coming Soon on** → the scan page
  - a customer via My Account is not redirected
  - foreign hosts, ports and `/scanner/` are rejected
  - the Log out link works
- **Entry box:**
  - accepted: spaces, tabs and newlines, spaces inside, lowercase, pasted site URL, pasted lowercase URL, pasted label URL on another base, URL with query and fragment
  - rejected: garbage, wrong alphabet, a URL without a code, too short
  - empty input shows the entry page
- **Headers:** all six security headers exact on 11 response types, plus meta robots; no `Last-Modified` or `X-Pingback`.
- **Template:**
  - standalone, with no theme, block, emoji, admin-bar or wp-json output
  - exactly one stylesheet link
  - the stylesheet is not on the home page
  - viewport meta; tap targets and font size checked in the CSS
- **Coming Soon:**
  - logged out: the login redirect
  - seller, shop manager and admin: the scan screen, with no marker and no `max-age=60`
  - control check: Coming Soon is live for the seller on `/`
- **Sitemaps** forced on in-process: 20 entries, none under `/scan/`.
- **Slug conflicts:**
  - a `scan` product category is not flagged
  - a page `scan` and its child are flagged
  - the notice is escaped, admin-only and shown on real admin screens
  - the plugin still wins for `/scan/` and `/scan/child/`
  - a trashed page is not a conflict
- **Plain and `index.php` permalinks:**
  - unavailable, with the notice
  - `handle()` leaves the request to WordPress
  - query-var access is not served
  - login filters are inert
  - everything restored
- **Deactivation and reactivation in-process:**
  - rules and flag removed; every other rule unchanged
  - `/scan/` not served while deactivated
  - codes untouched
  - rules and flag back on reactivation
- **Round trip:** `QrRenderer` SVG → decoder → exact scan URL → an HTTP GET as a seller → the right product screen.
- **Scope:**
  - no REST routes, shortcodes, nopriv or pqbg AJAX hooks
  - `add_rewrite_rule` only in `ScanRoute`
  - no DB writes in the scan classes
  - no theme calls in the template
  - no JavaScript
  - the new files give empty output over HTTP
  - the label format is unchanged
  - the codes checksum is unchanged by the whole suite
- **Cleanup:**
  - codes back to the starting count (AUTO_INCREMENT reset)
  - 0 products; no posts, meta, term relationships or terms above the start
  - upload file removed
  - options byte-identical: settings, Coming Soon, permalink structure, rewrite rules, flag, active plugins
  - users and temporary directory removed

**Timing** (dev machine):

| Measurement | Result |
|---|---|
| Product scan over HTTP as a seller (variation), median of 10 | 201–246 ms across runs (233 ms in the final run) |
| The same, in-process `resolve()` + `render()` | 17–37 ms, **12 queries** |

**Problems found and fixed during development:**
- **Pre-existing test-data leak.** Opening the Dashboard as a temporary user who can edit posts creates a Quick Draft auto-draft owned by that user, and `wp_delete_user()` then *trashes* it, leaving a revision.
  - The Phase 4 suite (the Shop Manager Dashboard check) had left one pair per run since Phase 4, which contradicts the earlier "clean state" notes. The Phase 6 suite did the same until fixed.
  - Both suites now permanently delete their temporary users' posts, and Phase 4 checks this.
  - This session's 3 pairs (IDs 1056/1057, 1248/1250, 1280/1282) were checked and deleted.
  - **The 10 older pairs were then deleted with the user's approval** (post/revision IDs 97/98, 111/112, 125/126, 127/128, 129/130, 143/144, 157/158, 171/172, 545/546, 881/882). Before deleting, each post was verified to be a trashed `post` titled "Auto Draft", with no content or excerpt, trashed from `auto-draft`, and authored by a deleted test user. Each revision was verified to be an "Auto Draft" revision with no content, and the only metadata were the three trash keys.
  - The only other link was WordPress's automatic default category ("Uncategorized"). That link went with each post; the term itself and its count (1) are unchanged.
  - Removed in total: 20 post rows, 30 meta rows and 10 term relationships. No trashed "Auto Draft" posts or revisions remain. The real admin's Quick Draft auto-draft (post 5) was kept.
- **The Phase 6 suite switched permalinks with `WP_Rewrite::init()`,** which also drops every registered endpoint. The later deactivation flush then wrote rules without WooCommerce's endpoints. The suite now sets only `$wp_rewrite->permalink_structure`, and the rule set after deactivation is checked exactly.
- **An Apache `%2F` 404:** Apache rejects encoded slashes in paths itself (`AllowEncodedSlashes Off`), so that test URL was changed.

**Environment finding: Apache/PHP crashes (not caused by plugin code).**
- `httpd.exe` child processes crash with `0xC0000005` in **`php8ts.dll` 8.5.6 at offset `0x666de5`**. Windows logged **50 such crashes from 2026-08-23 to 2026-09-24 13:24**, all before this session, and the Apache error log shows the same kind of restarts on this XAMPP since June.
- During Phase 6 development there were 37 crashes (00:43–00:59). Each one dropped the open connections, which showed up as empty responses (status 0, no curl error).
- They stopped completely once the Phase 6 test client used a fresh connection per request (`CURLOPT_FORBID_REUSE`). There were no crashes in the 5 later runs, including two full regressions.
- This is a PHP engine (ZTS) bug under Apache on Windows. It is worth watching; a PHP update may fix it.

**User action observed during testing (not a test side effect):** at 01:19 local, a Chrome session in wp-admin went WooCommerce Home → Payments task → Settings → Payments → Offline and **enabled "Cash on delivery"**. WooCommerce logged it, and the admin notification email failed because mail isn't configured locally. This was left as is.

**Known limitations:**
- **The scan base URL must reach this site.** The route answers only on this site's `/scan/` path.
- **The My Account login form is hidden** from logged-out visitors while Coming Soon is on, so staff use `wp-login.php` until launch. This is handled automatically.
- **Excluded letters are not corrected.** A code typed with `0/O/1/I/L` is rejected, not guessed.
- **HTTP timings are noisy on XAMPP.** Only a 3 s sanity bound is asserted.
- **The phone test is manual and still to be done by the user.** The steps are in the plugin README under "Phone testing".
- No PHPCS run, because it isn't installed.

**Next phase (not started):** Phase 7, Mark Sold + Sales. **The production domain is still not final**, so labels must not be printed yet.

### Phase 7: Mark as Sold + Sales (2026-09-25)

**Status:** implemented and tested. The plugin stays active.
- The user ran the **phone test** on a real phone, as a Store Seller over the local network, and it **passed**: product screen, sell, stock decrement, undo, zero-stock refusal, draft refusal.
- After the post-review changes below, Phase 7 was **approved**, committed as a single commit, `2413698` ("Phase 7: mark as sold with atomic stock journal, undo, online-race compensation (schema v2)"), and pushed to `origin/main` with a normal push (no force).

**Environment:** re-verified at the start, with no differences.
- WP 7.1.2, WC 11.1.2 (HPOS on), PHP 8.5.6 ZTS (not updated; the user can't update XAMPP on this machine), MariaDB 10.4.32
- 0 codes, 0 sales, 0 products, 0 orders, 1 user; `pqbg_db_version` 1 at the start
- store settings: stock management on, hold stock 60 min, low-stock alert at 2, no-stock alert at 0, taxes off, INR with 2 decimals, Cash on delivery enabled, timezone still UTC
- the database supports `@@in_transaction` and `GET_LOCK`; autocommit is on; REPEATABLE-READ
- **Local mail is not configured:** a WooCommerce stock e-mail makes `mail()` fail after about 2 s.

**Windows crash count** (`httpd.exe` Application Error, Event ID 1000): **139** at the start: 137 in `php8ts.dll` at 0x666de5 and 2 in `ntdll.dll`, the latest at 00:59:10 during Phase 6.
- **Still 139 after every Phase 7 run:** the baseline, 3 Phase 7 development runs, the partial regressions and the final full run. There were no crashes and no Apache restarts (the same Apache process served every run).
- The Phase 6 workaround (a fresh HTTP connection per request) is kept in the new suite.

**Baseline before any change:** `php tests/run.php` ALL PASSED, **756 passed, 0 failed, 0 skipped** (272 s). The decoder was installed in this session's scratchpad with `npm ci` and used through `PQBG_DECODER`.

**Approved plan and decisions** (the user's review):
- **D1** the quantity is a list 1..stock where every option shows its total (up to 100 in stock); a number field above 100
- **D2** stock held for unpaid online checkouts is ignored (documented boundary)
- **D3** a price changed since the form was opened is refused; prices are compared as normalised decimals (`wc_format_decimal` to the store's price decimals), so "1499" = "1499.00" = 1499.0
- **D4** after a completed sale, WooCommerce's `wc_trigger_stock_change_actions()` sends the low/no-stock notification (WooCommerce only does this for order-based changes)
- **D5** failed attempts are kept as `failed` rows with a `failure_code`; the journal row is written before the stock changes
- **D6** the form is valid for 30 minutes; the lock timeout is 5 s
- **D7 (changed by the user)** an empty **or zero** price blocks the sale: "This item has no price (₹0). Set a price before selling."
- **Change 1:** an unauthorised `?sale=` gets a **303**, never a 301.
- **Change 2:** the normalised price comparison, tested with "1499" / "1499.00" / 1499.0.
- **Change 3:** the SQL-filter override:
  - it is removed in `finally`, which is tested after success, failure and exceptions
  - the exact fallback is implemented and both branches are tested
  - a WooCommerce compatibility test fails loudly if the filter or the SQL shape changes
- **Change 4:** the Phase 11 reconciliation open item (below).

**Design:**
- **Sale flow** (POST to the canonical `/scan/{CODE}/`, `pqbg_action=sell`):
  - checks in order: login → `pqbg_view_products` → canonical URL → action → `pqbg_sell` → nonce `pqbg_sell_{code row id}` → a signed form token (HMAC over request_id, issued, seen price, seen stock, user and code row) → **an existing sale with this request_id returns its outcome** (before the expiry check) → expiry (30 min) → quantity → `SaleService::sell()`
  - a completed sale answers **303** to `/scan/{CODE}/?sale={id}`
- **`SaleService::sell()`**, under `StockLock` (`GET_LOCK`, 5 s, a site-scoped name) on the **stock holder** (the parent for parent-level variation stock):
  1. recover stale `pending` rows of the holder → `failed`/`interrupted`
  2. fresh reads (`_stock` by direct SELECT, the product re-read, `get_price()`)
  3. stock and price checks
  4. **a `pending` journal row** with every snapshot
  5. `wc_update_product_stock()` with WooCommerce's UPDATE replaced, via `woocommerce_update_product_stock_query`, by **one multi-table statement** that changes the stock and sets the row `completed` only if it is still `pending`
  6. a fresh `_stock` read; below 0 (an online order won the race) or any error → compensate with `wc_update_product_stock( increase )`, whose statement sets the row `failed` (`sold_online` or `error`)
  7. the stock snapshots are written, the lock is released, and then the low/no-stock notification is sent
- **The replacement is used only for SQL of the expected shape.**
  - **Fallback:** if the row is still `pending` after WooCommerce ran, a fresh `_stock` read decides: a drop of at least the quantity since the read under the lock → `completed` (conditional on `pending`); otherwise → `failed`/`error`.
  - Warnings are logged with error codes only.
- **No transactions** anywhere in the sale code, so it can never implicitly commit a WooCommerce transaction. A hook that opens its own transaction (our own Phase 5 sweep runs on the parent's save for a shop manager) can't break the atomicity. Called inside an open transaction, the service refuses.
- **Undo** (sale page):
  - the same seller, `pqbg_sell`, own `completed` sale, ≤ 10 min, once
  - under the lock of the recorded `stock_holder_id`, one statement restores the stock and sets `voided`/`voided_by`/`voided_at_gmt`/`void_reason='undo'`, conditional on `completed`
  - rows are never deleted
- **`SaleService::void_sale()`** (service only, `pqbg_void_sale`): any completed sale, with or without restock. The UI is Phase 9.
- **Screens** (standalone Phase 6 template, no JavaScript, all escaped, every security header):
  - the Sell box (quantity list with totals, "Confirm sale"), in a separate form from the code box
  - the sale page: "Sold.", snapshots, total, stock now, time via `wp_date`, Undo with "available until", the "Scan next item" box
  - errors: 400/403/404/409/503 (`Retry-After: 2`)/500 with fixed messages
  - users without `pqbg_sell` see the Phase 6 screen unchanged
- **Capabilities:** the existing seven; no new one.
- **No WooCommerce order is created.** Scan sales are not in WooCommerce reports, Analytics or `total_sales`.

**Schema: DB_VERSION 1 → 2** (`migrate_2`, additive dbDelta):
- `pqbg_sales.stock_holder_id bigint unsigned NULL`
- `pqbg_sales.failure_code varchar(40) NULL`
- `KEY holder_status (stock_holder_id,status)`

The live site migrated on its first request after the change; it had 0 sales rows. No column was removed or renamed. Uninstall is unchanged.

**Files created** (plugin-relative):
- `includes/SaleService.php`, `includes/SaleRepository.php`, `includes/SaleRequest.php`, `includes/StockLock.php`
- `tests/phase7-sales.php`

**Files modified:**
- `includes/Schema.php` (v2 columns and index)
- `includes/Install.php` (DB_VERSION 2, `migrate_2`)
- `includes/ScanRoute.php` (POST dispatch, `?sale=`, per-response headers such as `Allow` and `Retry-After`)
- `includes/ScanScreen.php` (the sale form, the sale page, `with_error()`)
- `templates/pqbg-scan.php`, `assets/pqbg-scan.css`
- `tests/run.php`
- earlier suites, updated where Phase 7 made an assertion false by design (listed in `tests/README.md`):
  - `tests/phase2-main.php`: the column list, 9 indexes, and version checks against `Install::DB_VERSION`
  - `tests/phase2-lifecycle.php`: version checks
  - `tests/phase5-admin.php`: the write scope also allows `SaleRepository`
  - `tests/phase6-scan.php`: POST handling, the Phase 7 stock-tracking notice for sellers on unmanaged fixtures, sale forms only on sellable fixtures; +1 check
- `README.md` (plugin), `tests/README.md`, `progress.md`

`Plugin.php`, `Permissions.php`, `uninstall.php`, `CodeRepository`, the renderers and `vendor-prefixed/` are unchanged.

**Tests.** `php tests/run.php` with `PQBG_TESTS_ALLOW_PRODUCTION=1` and `PQBG_DECODER` set. **Final run: ALL PASSED, 965 checks, 0 failed, 0 skipped** (400 s). **Crash count 139 before and after.**

| Suite | Result |
|---|---|
| phase2-main | 83/83 (checks updated for v2) |
| phase2-lifecycle | 17/17 (checks updated for v2) |
| phase2-no-woocommerce | 12/12 |
| phase3-codes | 110/110 |
| phase4-rendering | 165/165 |
| phase5-admin | 157/157 (write scope updated) |
| phase6-scan | 213/213 (+1; POST and notice checks updated) |
| phase7-sales | 208/208 |

**Phase 7 coverage:**
- **The form:**
  - quantity list with totals
  - number field above 100
  - exact hidden fields
  - a new UUID v4 per render
  - separate from the code box
  - shown to seller, shop manager and admin; absent for view-only users and sellers without `pqbg_sell` (who also get no notices)
- **Blocked states:** unmanaged stock, empty and zero price, stock 0, stock 0 with backorders
- **A sale over HTTP:**
  - the 303
  - one row with every snapshot, `stock_holder_id` and the form's request_id
  - WooCommerce stock, status and lookup table
  - the success page: "Sold.", quantity × price, total, stock now, `wp_date` time, Undo "until", the "Scan next item" box with autofocus
  - a reload or HEAD changes nothing
  - resubmitting the form gives the same sale
- **Quantity bounds:** 0, -1, abc, 1.5, empty, " 1", 1e1, huge, missing, stock + 1; exactly all remaining → 0 and outofstock
- **Refused on POST (not just hidden):**
  - stock 0 with backorders
  - stock changed (and still allowed when the quantity fits)
  - stock tracking turned off
  - price removed, price 0, price changed
  - draft, pending, scheduled, trash, disabled variation, variation under a draft parent, trashed parent, retired code, vanished product, unknown code, invalid code
  - allowed: a private simple product, and a variation under a private parent
- **Prices:** "1499" vs "1499.00" vs 1499.0 gives no false "price changed"; a running scheduled sale records 1500 (regular 2000); a future one records 2000
- **Variations:**
  - own stock: the variation is decremented; `variation_set_stock` fires
  - parent-level stock: **the parent is decremented and locked**. With the parent's lock held by another process the sale gets 503 after about 5 s; a lock on the variation doesn't block it. The sibling then offers only the remaining stock.
- **Permissions and tokens:**
  - logged out → 302, never replayed
  - customer → the byte-identical fixed 403
  - viewer and seller without `pqbg_sell` → 403
  - missing, invalid, other-item and other-session nonce
  - tampered price, request_id or signature; another item's token
  - expired form; after expiry the same request_id still returns the original sale
  - unknown action; non-canonical URL and query string → 400
  - entry page POST → 405 `GET, HEAD`; PUT → 405 `GET, HEAD, POST`
- **The sale page:** another seller, a nonexistent sale or another code's sale → **303**; shop manager and admin 200 without Undo; viewer 303; other query strings keep the Phase 6 301
- **Undo:**
  - over HTTP: restore, voided row (not deleted), the page says undone, a second undo → 409, another seller → 403, GET can't undo, after 10 min the button is gone and a kept form is refused
  - in-process: 9:59 allowed and 10:01 refused, own sale only (also for shop managers), `pqbg_sell` required, the lock (busy), 4 concurrent undos → exactly one restore
  - `void_sale()`: seller refused; manager with and without restock; no double void; failed sales can't be undone or voided
- **Concurrency** (CLI worker processes):
  - the same request_id from 4 processes → one sale, one decrement; replay by another seller refused
  - **8 workers selling the last 3** → exactly 3 sales, stock 0, never negative, stock_after 2/1/0
  - the same across two sibling variations sharing parent stock
  - no pending rows left and every lock free
- **The online race:** another process places a real WooCommerce order (`wc_reduce_stock_levels`) between our read and our decrement → compensated, row `failed/sold_online`, final stock = start − online, WooCommerce ran both stock changes, and the seller sees 409 "This item just sold online. Stock was not changed."
- **Failure injection:**
  - an exception before the UPDATE, right after it, and after the product save → stock restored, row failed, filter removed, lock released
  - a broken snapshot UPDATE doesn't undo a completed sale
  - an open caller transaction → refused
  - a stale pending row → recovered as `interrupted`, and its request_id replays the failure
- **Fallback and compatibility:**
  - another plugin replacing our SQL with a plain decrement → `completed` via the conditional UPDATE, warning `pqbg_stock_marker_missing (applied)`
  - replacing it with a no-op → `failed/error`, `(not_applied)`
  - SQL of an unexpected shape is never replaced (`pqbg_stock_sql_unexpected`)
  - a normal sale logs nothing
  - **COMPATIBILITY:** the filter still fires once per change with (sql, id, new stock, operation), and the SQL has the expected shape
- **WooCommerce:** `product_set_stock` / `variation_set_stock`; outofstock and back to instock in the meta and the lookup table; one low-stock and one no-stock notification on sales, none on undo; **no orders** except the 2 simulated online ones; `total_sales` untouched
- **Migration** on a temporary prefix: a v1 fixture with a row → `migrate_2` adds the columns and index, the row is unchanged with NULLs, a re-run is a no-op (empty dbDelta log), a fresh install gives the full v2 schema; the real tables are never altered; the uninstall logic is unchanged
- **Output safety:** the product name and SKU with HTML/script payloads escaped on the form and the sale page; the sale page keeps the snapshot name after a rename; no `<script>`; every security header on 303/400/403/409/503/sale page
- **GET never writes:** GET/HEAD, the form as a query string, the entry box and the sale page change no sale and no stock
- **Scope:**
  - no transactions in the sale code
  - no order creation, REST, AJAX or nopriv
  - `wc_update_product_stock` only in `SaleService`
  - sales rows are written only by `SaleRepository`
  - no JavaScript
  - the new files give empty output over HTTP
- **Cleanup:** sales, codes, products, orders (items, addresses, operational data), comments/notes, users, Action Scheduler jobs, temporary tables; options byte-identical; no filter left behind

**Timings** (dev machine, final run; the earlier runs were similar):

| Measurement | Result |
|---|---|
| Sale over HTTP (POST → 303), median of 5 | 254 ms (254–304 ms across runs) |
| Undo over HTTP (POST → 303), median of 5 | 266 ms (245–285 ms across runs) |
| Sale in-process (`SaleService::sell()`), median of 5 | 48.7 ms |
| Undo in-process, median of 5 | 37.6 ms |
| Phase 6 product scan over HTTP, median of 10 | 215 ms; in-process 23.7 ms and **13 queries** (12 before, +1 for the seller's stock read) |

**Problems found and fixed during development:**
- **The low/no-stock e-mail first ran while the stock lock was held.** With local mail failing after about 2 s, that would have held up the next sale of the same item. It now runs after the lock is released.
- **Test-suite bugs** (not plugin bugs), found in the first runs:
  - a sold-out product was reused for a form
  - a status check ran after the undo
  - the log capture needed deduping, because WooCommerce calls the log filter once per handler
  - the scope check had to allow `Install.php` to name the sales table
- **The Phase 4 "no scan path literal" guard flagged `SaleService::SOURCE = 'scan'`.** The constant was removed; `source` comes from the column default (`scan`).
- **Cleanup of a probe:** a one-off probe of the WooCommerce stock SQL created and deleted one product, and left 2 completed Action Scheduler jobs (IDs 4274 and 4275, `woocommerce_run_product_attribute_lookup_update_callback` for the deleted product) with 6 log rows. Both were deleted; nothing else remained.

**Known limitations:**
- **Online-checkout boundary:**
  - WooCommerce checkout doesn't take our lock
  - a reduction between our read and our decrement is compensated
  - an online order paid after our sale can still oversell (WooCommerce's own behaviour)
  - held stock isn't counted (D2)
- **Side effects that can't be undone:** the low/no-stock e-mail already sent, third-party hooks on stock changes and product saves, the product's modified date, a stock status briefly visible to shoppers during a race.
- **Remaining crash windows:** a `completed` row with `stock_after` NULL (a crash after the atomic statement), or negative stock with a `completed` row (a crash between an online-race decrement and its compensation). **Records and stock still agree** in the first case; see the open item for Phase 11.
- **The fallback's stock comparison** can misjudge only if the SQL was overridden by another plugin **and** an online order lands in the same milliseconds.
- **The page doesn't refresh itself:** an Undo button can still be visible after 10 minutes; pressing it is refused.
- **Mail isn't configured locally:** a sale that triggers a stock e-mail takes about 2 s longer on this machine (after the lock is released).
- **Scan sales aren't in WooCommerce reports.** Phase 9 adds the history UI.
- **The phone test is manual** and still to be done by the user.
- No PHPCS run, because it isn't installed.

**Open items:**
- **Phase 11 (hardening): a "stock vs. sales reconciliation" check** that surfaces:
  - negative stock on any product that holds stock
  - `completed` sales rows with `stock_after` NULL, and any `pending` rows

  These are the documented crash windows above. It should report, not auto-fix.
- **Phase 9:** the sales history UI and the manager void UI on top of `SaleService::void_sale()`. Reports must count `completed` rows only, and show `voided` ones as voided.

**Post-review changes (after the phone test):**
- **Stock-tracking message reworded to match the WooCommerce 11.1.2 product editor**, checked in the installed source with site language `en_US`. The Inventory tab's "Stock management" checkbox reads "Track stock quantity for this product"; a variation's checkbox reads "Manage stock?". The messages are now:
  - simple product: "Stock tracking is off for this product. On the Inventory tab, tick 'Track stock quantity for this product' to sell from a scan."
  - variation: "Stock tracking is off for this variation. Tick 'Manage stock?' on the variation, or 'Track stock quantity for this product' on the product's Inventory tab, to sell from a scan."

  The Phase 6 and Phase 7 checks were updated, and a variation check was added (Phase 7: 209 checks).
- **Manual-test data removed** (listed first; deleted with the user's confirmation):
  - product #2921 "Test" with its meta, term relationships and lookup row
  - user #311 "test" (Store Seller) and its session
  - `pqbg_codes` #1
  - `pqbg_sales` #1 and #2 (both undone)
  - Action Scheduler jobs #5751, #5752 and #5754 (#5754 was scheduled by the product delete itself)
  - the plugin's WooCommerce log file from the test runs

  Both tables' AUTO_INCREMENT were reset.
- **Settings the phone test had changed, restored:** `home` and `siteurl` were `http://192.168.1.6.:80/sharayu` and are back to `http://localhost/sharayu`; Coming Soon was off and is back on.
- **Timezone set to Asia/Kolkata**, with the user's approval.
- **Kept, as the user decided:** post #2922 `wp_global_styles` "Custom Styles" (created when the Site Editor or Customize Store was opened), and the WooCommerce admin options written while browsing wp-admin.
- **Deleted: 422 leaked completed Action Scheduler jobs and their 1,266 log rows** (`woocommerce_run_product_attribute_lookup_update_callback` for products that no longer exist, dating from 2026-09-24). See the Phase 8 open item.
- **Side effect found and reverted: turning Coming Soon back on made WooCommerce re-save the Cart page.**
  - `ComingSoonCacheInvalidator` calls `wp_update_post()` on the Cart page (#8) whenever `woocommerce_coming_soon` changes.
  - Run from CLI as user 0 (no `unfiltered_html`), the content was re-serialized in two places: `"taxQuery":{}` became `[]`, and `<hr …/>` became `<hr … />`. Revision #2923 was created.
  - The original content was restored byte for byte (verified to differ only in those two spots), and the revision was deleted. `/cart/` returns 200.
  - **Lesson:** toggle Coming Soon through wp-admin, or with raw option writes as the Phase 6 suite does, not with `update_option()` from the CLI.
- **Verified afterwards:** as a temporary administrator (removed afterwards), wp-admin (Dashboard, Products, General Settings), the storefront (`/`, `/shop/`) and `/cart/` all load normally on `http://localhost/sharayu`.

**Re-run from the clean state:** ALL PASSED, **966 checks, 0 failed, 0 skipped** (418 s). **Crash count 139 before and after.**
- phase2-main 83, phase2-lifecycle 17, phase2-no-woocommerce 12, phase3-codes 110, phase4-rendering 165, phase5-admin 157, phase6-scan 213, phase7-sales 209
- timings: sale over HTTP 278 ms, undo over HTTP 303 ms, sale in-process 46.1 ms, undo in-process 31.7 ms
- As expected, this run leaked 36 more Phase 3 jobs (action IDs 5797–5872). They were left in place, because the approval covered the 422 only. See the Phase 8 open item.

**Phone testing:** never change Settings → General → WordPress Address for phone tests; use the `wp-config.php` snippet from the README instead.

**Open items:**
- ~~**At the start of Phase 8:** `phase3-codes.php` leaves ~35 completed Action Scheduler jobs per run…~~ **Done in Phase 8.** The leak was actually in `phase5-admin.php` (see the Phase 8 section); fixed in the test suites, with a runner-level guard, and the leaked jobs were deleted with the user's approval.
- **Phase 11 (hardening):** concurrency tests: print the minimum stock observed, and assert per-row stock_before/stock_after snapshots for the parent-level stock test as well.

**Next phase at the end of Phase 7:** Phase 8, Printing. Done; see below.

### Phase 8: Label printing (2026-09-25)

**Status:** implemented and tested. The plugin stays active. **Approved** by the user on 2026-09-25, before their printer test, then committed as a single commit and pushed to `origin/main` with a normal push (no force). The manual printer test (the Phase 8 checklist in the plugin README) is still to be done by the user.

**Environment:** re-verified at the start, with no differences.
- WP 7.1.2, WC 11.1.2 (HPOS on), PHP 8.5.6 ZTS (not updated; the user can't update XAMPP), MariaDB 10.4.32
- 0 codes, 0 sales, 0 products, 0 orders, 1 user; `pqbg_settings = {"settings_version":1}`; DB_VERSION 2; timezone Asia/Kolkata; scan base URL = `http://localhost/sharayu` (local)
- no persistent object cache; `WP_ENVIRONMENT_TYPE` still unset (tests use `PQBG_TESTS_ALLOW_PRODUCTION=1`)
- headless browsers available: Microsoft Edge 153 and Google Chrome (both used by the new browser checks)

**Windows crash count** (`httpd.exe` Application Error, Event ID 1000): **139** at the start, and **139 before and after every run** in this phase: the baseline, the diagnostic traces, the Phase 5 check, two Phase 8 development runs and the final full run. No Apache restarts (the same two `httpd.exe` processes throughout). The fresh-connection-per-request workaround is kept in the new suite.

**Baseline before any change:** `php tests/run.php` ALL PASSED, **966 checks, 0 failed, 0 skipped** (decoder via `PQBG_DECODER`). Afterwards: **36 leaked completed `woocommerce_run_product_attribute_lookup_update_callback` jobs** (IDs 6333–6408) with 108 log rows, plus 6 ordinary site WP-Cron jobs.

**The Action Scheduler leak (open item from Phase 7) — the wrong suite had been blamed.**
- A scratchpad tracer ran every suite and logged each job stored/deleted plus everything left after PHP shutdown (including jobs from Apache). Phases 2, 3 and 4 leave **nothing**; Phase 3 stores 22 jobs and deletes 22. **The leak is in `phase5-admin.php`**: 36 jobs for 17 products on every run.
- Cause: Phase 5's cleanup matched jobs to post IDs that *still existed* at cleanup, so products it had already deleted inside its own sections (trash/delete, type changes, edit screen, downloads) were missed; and it had no zero-leak check.
- **Fix (tests only):** shared helpers in `tests/bootstrap.php` (`pqbg_test_as_mark()`, `pqbg_test_as_cleanup()` — matches every post/order ID *allocated* during the suite, up to `AUTO_INCREMENT − 1`, waits for claimed/running jobs, deletes jobs and orphaned logs — and `pqbg_test_as_check()`), used by Phases 3, 5, 6, 7 and 8; plus a runner-level guard `tests/as-guard.php` that `run.php` runs after each suite's process has exited. Result: 0 leaked jobs and 0 orphan logs in every suite of the final run (Phase 5 now removes 318 jobs instead of 284).
- **Cleanup with the user's approval (D10):** deleted the 108 leaked lookup jobs (IDs 5797–5872 from Phase 7, 6333–6408 from the baseline, 6891–6965 from the diagnostic trace), their 324 log rows, and 1 orphaned log row (action 6144, "This action data appears to be corrupt…", from before this session). Each was verified first: completed lookup jobs for products that no longer exist.

**Approved plan and decisions** (D1–D12 as recommended, plus the user's three changes):
- **D1** default preset A4 3 × 7 (63.5 × 38.1 mm) until the user names their label stock
- **D2** QR module ≥ 0.40 mm, ≤ 0.99 mm; thermal presets rounded down to whole dots only when that stays ≥ 0.40 mm
- **D3** barcodes (when enabled) are left off with a notice where they don't fit at 0.25 mm per module; `BarcodeRenderer::render()` got optional `bar_height`/`text` arguments, default output byte-identical
- **D4** at most 300 labels and 300 selected products per job
- **D5** render cache in transients, 30 days, 2,000 entries, no purge hook on retirement
- **D6** copies = stock: own tracked stock → that many; shared (parent) or untracked stock → 1 with a note; ≤ 0 → skipped
- **D7** draft/pending/private items printed with a note; trashed items skipped
- **D8** printer offset ±5 mm (sheets only): yes
- **D9** the leak guard reports (does not fail) new jobs of pre-existing site hooks that reference no test ID
- **D10** delete the listed leaked jobs and logs: done (above)
- **D11** optional `tests/print-check` package (puppeteer-core + pdfjs-dist, pinned)
- **D12** print preferences (user meta) kept on uninstall unless `PQBG_UNINSTALL_DELETE_ALL_DATA`
- **Change 1 (CSP):** print page `script-src 'self'` (pqbg-print.js only) and `style-src 'self' 'nonce-…'` matching the one `<style>` element; a headless-browser test that clicking Print calls `window.print()` and that the setup, confirmation and print pages have zero CSP violations and console errors.
- **Change 2 (GS1):** verified at the source: *GS1 General Specifications Standard, Release 26.0 (ratified Jan 2026), Table 5-46 "Symbol specification table 1 addendum 2 for 2D barcodes", p. 412*: "QR Code (GS1 Digital Link URI)" X-dimension min 0.396 mm, target 0.495 mm, max 0.990 mm, quiet zone 4X (retail POS, consumer trade items). Cited in the code and README as a reference point only (our URLs are not GS1 Digital Link); the 0.40 mm floor is justified by the round-trip tests at exactly 0.40 mm. The 0.99 mm maximum follows the same table.
- **Change 3 (₹):** label font stack Segoe UI, Nirmala UI, Roboto, Noto Sans, Arial before the generic fallback; a browser test (CDP platform fonts + a glyph comparison against a missing-glyph box); "check ₹ prints correctly" in the manual checklist.

**Design:**
- **Flow:** product panel "Print label" (simple product, each variation row), "Print all variation labels (n)", products-list bulk action "Print QR labels" → **setup screen** (hidden page `edit.php?post_type=product&page=pqbg-print`, GET, read-only, nonce bound to the selection) → **POST** `admin-post.php?action=pqbg_print_prepare` (nonce + capability; saves the user's options in user meta `pqbg_print_prefs`; 303) → **print page** `admin-post.php?action=pqbg_print` (GET/HEAD, nonce bound to the selection, capability, options re-validated from the query string, read-only apart from the render cache).
- **Items:** simple product = 1 item; variable product → its variations (publish/private, menu order); variation ID = 1 item; duplicates printed once. Skipped with reasons: no code yet (with a product link), in the trash, not found, grouped/external, variable without variations, no stock (stock mode). Codes only via `CodeRepository::find_active_for_products()`; nothing is ever generated.
- **Layouts:** A4 3 × 7 63.5 × 38.1 (margins 7.25/15.15, gaps 2.5/0), A4 3 × 8 70 × 37 (0/0.5, edge-to-edge warning), A4 4 × 10 48.5 × 25.4 (8/21.5), A4 5 × 13 38.1 × 21.2 (4.75/10.7, gaps 2.5/0), thermal 50 × 25, 38 × 25, 100 × 50 (203 dpi, one per page); custom sheet or thermal (203/300 dpi), validated. Every A4 preset sums to exactly 210 × 297 mm.
- **Fit (`PrintLayout::fit()`):** the module count comes from the real encoding (V4 = 41 modules with quiet zone up to a 38-character base URL; 45/49/53; V8 = 57 at 99–100 characters). QR left of the text (stacked on tall labels), module = min(0.99, room/N), refused below 0.40 mm after dropping optional text (store → price → SKU → attributes → name). Code text always printed, wrapped after its second hyphen on narrow labels. TEST line required while the scan URL is local. Barcode strip 7 mm, 0.25 mm modules, ≤ 60.5 mm wide.
- **Print page:** standalone template `templates/pqbg-print.php` (no wp_head), `@page` exact size with zero margins, one `<section>` per page with `break-after: page` (not the last), on-screen outlines; toolbar with print-dialog instructions; local base URL → warning page, "Print TEST labels anyway" (GET `confirm_test=1`), then every label marked "TEST – NOT FOR USE"; public http:// → the Phase 4 https warning. Headers: `ScanRoute::security_headers()` + the print CSP.
- **Cache (`PrintCache`):** transients keyed by md5(type | code | exact payload or barcode args | renderer constants | versions), value checked on read (code + fingerprint), 30-day TTL, LRU index `pqbg_svg_cache_index` (≤ 2,000, not autoloaded), `clear_all()` on every uninstall. Barcode lookups check the setting first, so the library stays unloaded while disabled.

**Files created** (plugin-relative):
- `includes/PrintLayout.php`, `includes/PrintJob.php`, `includes/PrintCache.php`, `includes/PrintPage.php`, `includes/PrintAdmin.php`
- `templates/pqbg-print.php`, `assets/pqbg-print.css`, `assets/pqbg-print.js`
- `tests/phase8-printing.php`, `tests/as-guard.php`, `tests/print-check/` (`package.json`, `package-lock.json`, `check.mjs`, `index.php`)

**Files modified:**
- `includes/Plugin.php` (registers `PrintAdmin`/`PrintPage` in the admin block; wired after the class files existed)
- `includes/AdminProductPanel.php` (the print links), `includes/BarcodeRenderer.php` (optional arguments), `uninstall.php` (cache always cleared; prefs only with the delete-all flag)
- `tests/bootstrap.php`, `tests/run.php`, `tests/decoder/decode.mjs` (`--width`, PNG input; default unchanged), `tests/phase3-codes.php`, `tests/phase5-admin.php` (+ the PrintCache scope allowance), `tests/phase6-scan.php`, `tests/phase7-sales.php`
- `README.md` (plugin), `tests/README.md`, `progress.md`

`Schema`, `Install`, `CodeRepository`, `ProductCodeService`, `ScanUrl`, `QrRenderer`, `ScanRoute`, `SaleService` and `vendor-prefixed/` are unchanged. No schema change (DB_VERSION stays 2), no new capability, no REST/AJAX/nopriv/shortcode.

**Tests.** `php tests/run.php` with `PQBG_TESTS_ALLOW_PRODUCTION=1`, `PQBG_DECODER` and `PQBG_PRINTCHECK` (decoder and print-check installed in the session scratchpad). **Final run: ALL PASSED, 1,135 checks, 0 failed, 0 skipped; AS guard PASS for every suite** (~13 min). **Crash count 139 before and after.**

| Suite | Result |
|---|---|
| phase2-main | 83/83 |
| phase2-lifecycle | 17/17 |
| phase2-no-woocommerce | 12/12 |
| phase3-codes | 110/110 (shared AS cleanup) |
| phase4-rendering | 165/165 |
| phase5-admin | 158/158 (+1 zero-leak check; PrintCache scope allowance) |
| phase6-scan | 214/214 (+1 zero-leak check) |
| phase7-sales | 209/209 (shared AS cleanup) |
| phase8-printing | 167/167 |

**Phase 8 coverage (highlights):**
- geometry of every preset (exact spec, sums, every slot), start-at-N across sheets, no trailing blank page, thermal pagination, offsets; 26 invalid custom layouts and 14 invalid options rejected
- module counts for base URLs of 24/38/39/60/61/82/83/98/99/100 characters (41/41/45/45/49/49/53/53/57/57); the planned module or refusal for every preset; exactly 0.40 mm accepted, 0.01 mm less refused; dot snapping; optional text dropped before refusing
- **round trip at printed size:** every label's QR (and barcode) rasterised at 300 dpi (A4 3 × 7, 5 × 13) and 203 dpi (thermal 50 × 25, 38 × 25), plus exactly 0.40 mm modules for V4 and V8 URLs at 203/300 dpi — all decoded to `ScanUrl::for_code()` (EC M) / the code
- **headless Edge and Chrome** (each): zero CSP violations / console errors / page errors / failed requests on the setup, confirmation and print pages; Print → `window.print()` once; print-media geometry equal to `PrintLayout` within 0.06 mm with nothing overlapping and the code text never cut; long names clamped and long SKUs ellipsised; **₹** rendered by Segoe UI (all 9 price glyphs), width 34.5 px = the known-good font vs 41.3 px for a missing-glyph box; every label decoded from screenshots at printed size (18 A4 incl. barcodes, 5 × 13, 3 thermal, 0.40 mm at 203 dpi, 2 TEST labels); PDF page count and size, and every code text at its layout position (x ±0.3 mm)
- TEST mark and confirmation (in-process and HTTP), no mark for https, http warning; fields on/off; code wrapping; dropped-fields notice; retired code never printed after regeneration; barcodes off → no Picqer class loaded; on → strips on 3 × 7, notice on 4 × 10
- cache: cold/warm, 30-day TTL, not autoloaded, base-URL and barcode-argument invalidation, tampered entry rejected, eviction at 2,000 with transients deleted, expired entries dropped, `clear_all()`, and the real default uninstall path (cache gone, data kept)
- HTTP: every entry point (panel links, bulk action, setup, POST 303, print 200/HEAD, confirmation), invalid options and too-small layouts back on the setup screen with the message, 300 vs 400 labels, 301 products; nonce bound to the selection and the user; POST → 405, GET prepare → 405; admin and shop manager allowed; seller, customer, subscriber refused on setup, POST (403), print (403) and bulk action; logged out → login / no labels; **GET/HEAD/confirmation write nothing** (checksums); POST writes only the user's preferences; escaping of HTML in names/SKUs; headers and CSP nonce

**Timings** (dev machine, final run; A4 3 × 7, all fields, one product per label):

| Labels | In-process cold | In-process warm | HTTP cold | HTTP warm | Page |
|---|---|---|---|---|---|
| 100 | 4.47 s | 0.33 s | 4.77 s | 0.78 s | 448 KB |
| 300 | 14.17 s | 0.82 s | 13.67 s | 1.85 s | 1,340 KB |

(Warm = the next request with the cache filled; in-process warm flushes the in-memory object cache first, like a new request.)

**How the print geometry was verified:** headless Edge 153 and Chrome, driven by `tests/print-check/check.mjs` (puppeteer-core with the installed browsers), logged in as a temporary administrator over the real HTTP flow: DOM geometry under print media, screenshots at 203/300 dpi decoded, and `page.pdf()` read back with pdf.js. Measured PDF page sizes: **A4 → 209.889 × 297.011 mm** (Chromium's 595 × 842 pt), **50 × 25 mm → 50.123 × 25.061 mm** in both browsers; content is anchored top-left, so label positions matched the layout. The physical print (real printer, label stock, phone scan) is the user's manual test.

**Problems found and fixed during development:**
- Product names went through `wp_strip_all_tags()`, which would cut "Kurta <3 Size" at "<3"; names and SKUs are now printed literally (entities decoded, escaped on output); only WooCommerce's price/attribute HTML is stripped.
- `uninstall.php` could `require_once` `PrintCache.php` a second time (different path string) when the class was already loaded; now guarded with `class_exists( …, false )`.
- Warm HTTP time for 300 labels was 3.2 s; priming the post/meta caches for the selection brought it to 1.85 s.
- Test-tooling bugs: `escapeshellarg()` on Windows replaces double quotes (the guard's JSON mark → a digits-and-commas mark); a 400-ID bulk URL exceeded Apache's 8 KB request line; `curl_close()` is deprecated in PHP 8.5; several expectation errors in the new suite.

**Known limitations:**
- The print dialog settings (scale, margins, headers/footers) are the user's; the page explains them. Printer hardware margins can clip the edge-to-edge 3 × 8 sheet.
- Chromium's PDF page size differs from the CSS size by up to ~0.12 mm (above); labels are unaffected.
- The QR fit assumes one code length (true for `DC-XXXX-XXXX-XXXX`); the page uses the largest version in the job anyway.
- Cold rendering is ~45 ms per new QR code (bacon's pure-PHP mask scoring): 300 new codes ≈ 14 s.
- The plugin's WooCommerce log file for today (`wc-logs/product-qrcode-barcode-generator-2026-09-25-…log`) contains only Phase 7's failure-injection warnings from its worker processes (as after Phase 7); nothing from Phase 8. Left in place.
- No PHPCS run (not installed).

**Open items:**
- **The user's manual printer test** (plugin README, "Phase 8 checklist"), and their label stock → possibly a new default preset.
- **Phase 11 (hardening):** the reconciliation check and the concurrency-test items from Phase 7 are still open.

**Next phase (not started):** Phase 9, Seller Dashboard / Sales History. **The production domain is still not final**: printed labels stay TEST labels until it is.

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
- Phases 4 and 5 are done. Build scan URLs only through `ScanUrl`, and render only through `QrRenderer`/`BarcodeRenderer`. Never reference the barcode library outside `BarcodeRenderer`, and keep the "barcodes disabled means the library is not loaded" guarantee.
- **Report the Windows crash count** (`httpd.exe` Application Error events, Event ID 1000) before and after test runs. A crashed run is neither a pass nor a fail of plugin logic: re-run the suite. It was 139 on 2026-09-25 after Phase 8.
- **Run `php tests/run.php` before and after every phase** (see `tests/README.md`). Preferred: `define( 'WP_ENVIRONMENT_TYPE', 'local' );` in the local `wp-config.php`, which the user will add themselves; never edit or commit `wp-config.php`. The fallback is `PQBG_TESTS_ALLOW_PRODUCTION=1`. The round-trip checks need `npm ci` in `tests/decoder`, or `PQBG_DECODER` pointing to a copy outside the web root. Add each new phase's suite to `tests/` and to `run.php`.
- **Exclude `tests/` and `build/` from any production deployment** (see "Production deployment" in the plugin README).
- **Never edit `vendor-prefixed/` by hand.** Change `build/` and run `php build/build.php` (see `build/README.md`).
- The PHP minimum is now **8.2**.
- On this live dev site, create new class files **before** referencing them from boot code (see the Phase 4 incident).
- **Phase 7 (Mark as Sold) is done, approved, committed as `2413698` and pushed** (2026-09-25).
- **Phase 8 (Printing) is done, approved, committed and pushed** (2026-09-25). The user's printer test is still outstanding. **Phase 9 (Seller Dashboard / Sales History) is next.**
- **Printing rules** (Phase 8):
  - Print only ACTIVE codes, read through `CodeRepository::find_active_for_products()`; printing never generates codes.
  - Payloads only from `ScanUrl::for_code()`, images only from `QrRenderer`/`BarcodeRenderer`, cached only through `PrintCache` (its key must include everything that changes the image; bump `PrintCache::VERSION` if the output changes outside the renderers' constants).
  - Keep the QR module ≥ `PrintLayout::MIN_MODULE_MM` (0.40 mm); refuse layouts instead of shrinking it.
  - The print page and setup screen are GET and read-only (only the render cache may be written); options are saved only by the `pqbg_print_prepare` POST. Keep the print CSP: styles only from the nonce'd `<style>` element and `assets/pqbg-print.css`, script only `assets/pqbg-print.js`, no inline style attributes.
- **Action Scheduler in tests:** every suite uses `pqbg_test_as_mark()` / `pqbg_test_as_cleanup()` / `pqbg_test_as_check()` from `tests/bootstrap.php`, and `run.php` fails a suite that leaks (`tests/as-guard.php`). New suites must do the same.
- **Browser checks:** install `tests/print-check` outside the web root (copy, `npm ci`) and set `PQBG_PRINTCHECK`; it uses the installed Edge/Chrome (`PQBG_BROWSERS` to override).
- **Phone testing:** never change Settings → General → WordPress Address for phone tests; use the `wp-config.php` snippet from the plugin README instead.
- **Don't toggle `woocommerce_coming_soon` with `update_option()` from the CLI.** WooCommerce then re-saves the Cart page as user 0 and re-serializes its content (see the Phase 7 post-review notes).
- **Selling rules** (Phase 7):
  - Sell only through `SaleService::sell()`, undo through `SaleService::undo()`, and void through `SaleService::void_sale()`.
  - Only `SaleRepository` writes `pqbg_sales`, and rows are never deleted.
  - Never wrap the sale code in a DB transaction.
  - Never change stock except through `wc_update_product_stock()` inside `SaleService::change_stock()`.
  - If WooCommerce is upgraded, run the Phase 7 suite: its COMPATIBILITY checks fail loudly if `woocommerce_update_product_stock_query` stops firing or its SQL changes shape.
- `DB_VERSION` is **2**. The next schema change is migration 3.
- **The scan URL format `{base}/scan/{CODE}/` is permanent** (labels will be printed with it). Never change `ScanUrl::for_code()` or the two rewrite rules without a migration plan for printed labels. Bump `ScanRoute::RULES_VERSION` whenever `ScanUrl::rewrite_rules()` changes, so the rules are flushed once.
- **Scan page rules:**
  - access is checked before any lookup: logged out → login redirect, no `pqbg_view_products` → a fixed 403
  - every scan response sends `ScanRoute::security_headers()`
  - the template is standalone: no theme, no `wp_head()`, no JavaScript
  - keep the customer 403 identical for every code
- **Coming Soon hides the My Account login form** from logged-out visitors, so the scan flow uses `wp-login.php`.
- **In test suites:**
  - Don't call `WP_Rewrite::init()`: it drops every registered endpoint. Set `$wp_rewrite->permalink_structure` instead.
  - Permanently delete temporary users' own posts before `wp_delete_user()`: opening the Dashboard creates a Quick Draft auto-draft that would otherwise be trashed and left behind.
  - Use a fresh HTTP connection per request (`CURLOPT_FORBID_REUSE`) when a suite keeps many cookie jars open. This XAMPP's `php8ts.dll` 8.5.6 crashes (0xC0000005) under the keep-alive pattern (see the Phase 6 section). The user can't update XAMPP/PHP on this machine; don't ask them to.
  - Short-circuit `pre_wp_mail` in-process: local mail isn't configured and each failing `mail()` takes about 2 s.
- Product-save and delete hooks now exist, in `CodeLifecycle` only, on exactly the approved hooks (the four WooCommerce CRUD save hooks and `deleted_post`). Don't add others without explicit approval.
- Regenerate only through `ProductCodeService::regenerate()` (atomic `CodeRepository::replace_active()`). Admin requests go through `AdminActions`: POST for anything that writes, a nonce bound to the item, and `pqbg_manage_codes`. Never add `nopriv` handlers.
- Only the classic product editor is supported. Re-check `product_block_editor` before relying on the Phase 5 UI.
- For the full test run, install the decoder outside the web root (copy `tests/decoder`, run `npm ci`, and set `PQBG_DECODER`) so that 0 checks are skipped.
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
  - Approved; committed as `3654086` and pushed to `origin/main`.

### 2026-09-25
- **Phase 5 (admin code management):**
  - Re-verified the environment; no differences. The block product editor is off.
  - Baseline 380 passed, 5 skipped.
  - Verified the WooCommerce 11.1.2 save, delete and type-change paths in source. Wrote the plan, and the user approved it with decisions 1–4, the decoder, and three additions (site-timezone display, "System"/"User #ID (deleted)" labels, a Phase 6 open item for non-published states).
  - Added `CodeLifecycle`, `AdminProductPanel`, `AdminActions` and assets, `CodeRepository::replace_active()` and the batch queries, and `ProductCodeService::regenerate()`/`retire_for_item()`/the auto-draft rule. Class files were created before `Plugin.php` was wired.
  - The new HTTP tests caught two bugs, both fixed:
    - the confirmation page answered 200 on a bad nonce, because `wp_die()` ran after the admin header; validation moved to `load-{hook}`
    - variation labels showed "Any"
  - Primed the variation caches in the panel. The HTTP overhead for 40 variations is now 85–115 ms, down from about 150 ms.
  - The test Quick Edit and Bulk Edit requests now send `post_view`/`change_stock` like the real forms. Without them, core and WooCommerce logged "undefined array key" warnings in the Apache log during testing; these were not from plugin code.
  - Final run: **542 passed, 0 failed, 0 skipped** (decoder installed in the scratchpad). All test data removed.
  - Approved; committed as `ff7805c` and pushed to `origin/main`.
- **Phase 6 (scan / product screen):**
  - Re-verified the environment; no differences. Found that Coming Soon hides the My Account login form from logged-out visitors.
  - Baseline: 542 passed, 0 failed, 0 skipped.
  - Wrote the plan (routing, access flow, status matrix, screens, phone testing, tests). The user approved all 8 decisions.
  - Added `ScanRoute`, `ScanScreen`, the standalone template and stylesheet, the `ScanUrl` helpers, and activation/deactivation wiring. Class files were created before `Plugin.php` was wired.
  - Added `tests/phase6-scan.php` (212 checks) and updated the Phase 3/4/5 scope checks.
  - Found and fixed a pre-existing test leak (trashed Dashboard auto-drafts) in the Phase 4 suite. Deleted this session's 3 leaked pairs. The 10 older pairs were deleted later, with the user's approval, after each one was verified.
  - Traced intermittent empty HTTP responses to a pre-existing `php8ts.dll` crash on this XAMPP; a fresh connection per request avoids it.
  - Final run: **756 passed, 0 failed, 0 skipped**. The site is back to its clean state.
  - The user ran the manual phone test, and it passed.
  - Approved; committed as `381a909` and pushed to `origin/main`.
- **Phase 7 (Mark as Sold + Sales):**
  - Re-verified the environment; no differences. Crash count 139; PHP 8.5.6 unchanged.
  - Baseline: 756 passed, 0 failed, 0 skipped.
  - Checked in the WooCommerce 11.1.2 source:
    - how `wc_update_product_stock()` runs, and that its SQL goes through `woocommerce_update_product_stock_query`
    - that low/no-stock e-mails are sent only for order-based stock changes
    - that the Phase 5 sweep can start a transaction inside a product save
  - Wrote the plan. The user approved it with D7 changed (zero price blocked) and four changes: a 303 for `?sale=`, normalised price comparison, the exact SQL-override fallback plus a compatibility test, and a Phase 11 reconciliation item.
  - Added `SaleService`, `SaleRepository`, `SaleRequest`, `StockLock` and schema v2 (`migrate_2`), the sale form, the sale page and undo on the scan page. Class files were created before any wiring referenced them. The live site migrated to v2 with 0 sales rows.
  - Added `tests/phase7-sales.php` (208 checks) and updated the Phase 2, 5 and 6 checks that Phase 7 made false by design.
  - Final run: **965 passed, 0 failed, 0 skipped**. Crash count 139 before and after every run. The site is back to its clean state: 0 codes, 0 sales, 0 products, 0 orders, 1 user.
  - Phone test passed. Reworded the stock-tracking message to WooCommerce's own checkbox labels.
  - Removed the manual-test data and 422 leaked Action Scheduler jobs, and restored `home`, `siteurl` and Coming Soon, all with the user's confirmation. Set the timezone to Asia/Kolkata. Reverted the Cart page re-save side effect.
  - Re-run from the clean state: 966 passed, 0 failed, 0 skipped; crash count 139.
  - Approved; committed as `2413698` and pushed to `origin/main`.
- **Phase 8 (Label printing):**
  - Re-verified the environment; no differences. Crash count 139; PHP 8.5.6 unchanged.
  - Baseline: 966 passed, 0 failed, 0 skipped; 36 leaked lookup jobs afterwards.
  - Traced the Action Scheduler leak to `phase5-admin.php` (not Phase 3, as recorded); wrote the plan. The user approved D1–D12 with three changes (print CSP and a browser test, verify the GS1 figure at the source, the rupee font stack and a glyph test).
  - Deleted the 108 leaked jobs, 324 logs and 1 orphan log, with approval. Verified GS1 Release 26.0 Table 5-46 (0.396 mm) in the specification PDF.
  - Fixed the test cleanup (shared helpers, a runner-level guard). Added `PrintLayout`, `PrintJob`, `PrintCache`, `PrintPage`, `PrintAdmin`, the template, CSS and JS, the panel links and bulk action, `BarcodeRenderer` arguments and the uninstall cache clearing. Class files were created before `Plugin.php` referenced them.
  - Added `tests/phase8-printing.php` (167 checks), `tests/as-guard.php`, and the optional `tests/print-check` (headless Edge and Chrome).
  - Final run: **1,135 passed, 0 failed, 0 skipped**, AS guard PASS for every suite; crash count 139 before and after every run. The site is back to its clean state.
  - Approved by the user (before the printer test); committed and pushed to `origin/main`.
