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
| Active plugins | classic-editor, woocommerce, durga-product-codes (since Phase 2) |
| Admin user | Dev-admin |

_Environment re-verified 2026-09-24 at the start of Phase 2, and again at the start of Phase 3 with no differences._

---

## Repository

Branch `main`, tracking `origin/main`. Phase 2 committed as `50d7e0b` and pushed to `origin/main`. **Phase 3 is implemented but not committed**; it is waiting for the user's approval.

Tracked files — project code only; WordPress core, `wp-config.php`,
uploads and archives are excluded by `.gitignore`:

```
.gitignore
README.md
progress.md
wp-content/plugins/durga-product-codes/
```

| Commit | Message |
|---|---|
| `50d7e0b` | Add Durga Product Codes plugin foundation (Phase 2) |
| `d9bb6a0` | Remove installer zip and empty extraction folder |
| `11e07fb` | Record tracking audit in progress log |
| `094e737` | Add .gitignore and progress tracker |
| `252e2d0` | first commit |

**Note:** `.gitignore` ignores `wp-content/*` wholesale, so a new
custom theme or plugin will not appear in `git status` until it is
un-ignored. Phase 2 un-ignored exactly one plugin
(`!wp-content/plugins/` + `wp-content/plugins/*` + `!wp-content/plugins/durga-product-codes/`);
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

## Durga Product Codes plugin

A custom WooCommerce plugin providing product QR/barcode inventory for our own shop staff.
It lives in `wp-content/plugins/durga-product-codes/`. The full developer documentation is in that folder's `README.md`.

| Phase | Scope | Status |
|---|---|---|
| 1 | Environment audit and architecture | Done (audit only, no code) |
| **2** | **Plugin foundation and data layer** | **Done 2026-09-24. Committed `50d7e0b`, pushed.** |
| **3** | **Secure product code generation** | **Done 2026-09-24. Not committed yet.** |
| 4 → 12 | QR + barcode → admin code management → scan/product screen → mark sold + sales → printing → seller dashboard/history → bulk/CSV → hardening/performance → QA/documentation | Not started |

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

### Instructions for the next Claude session

- Read this file and `wp-content/plugins/durga-product-codes/README.md` first. Re-verify the environment; don't trust these notes blindly.
- Get codes only through `ProductCodeService::get_or_create()`. Don't call `CodeRepository::create_active()` with hand-made strings, and don't write to `dpc_codes` directly.
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
