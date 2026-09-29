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

_Environment re-verified 2026-09-24 at the start of Phase 2, at the start of Phase 3, at the start of the plugin rename, and at the start of Phases 4 and 5, on 2026-09-25 at the start of Phases 6, 7, 8 and 9A, and on 2026-09-26 at the start of Phases 9B and 10. There were no differences apart from the rename itself. Also recorded in Phase 6: `blog_public = 0` (core sitemaps off), and logged-out visitors to `/my-account/` see the Coming Soon page._

---

## Repository

Branch `main`, tracking `origin/main`.

- Phase 2 was committed as `50d7e0b` and Phase 3 as `a2e5643`; both are pushed to `origin/main`.
- The plugin rename was committed as `5f301be` and pushed to `origin/main`, with the user's approval (normal fast-forward, no force).
- Phase 4 was committed as `3654086` ("Phase 4: QR code + optional Code 128 barcode rendering, settings page, tests and build") and pushed to `origin/main`, with the user's approval (normal fast-forward, no force).
- Phase 5 was committed as `ff7805c` ("Phase 5: admin code management (auto-assignment, lifecycle, atomic regeneration, product panel, downloads)") and pushed to `origin/main`, with the user's approval (normal fast-forward, no force).
- Phase 6 was committed as `381a909` ("Phase 6: scan route and mobile product screen (access control, status matrix, entry box)") and pushed to `origin/main`, with the user's approval after their phone test (normal fast-forward, no force).
- Phase 7 was committed as `2413698` ("Phase 7: mark as sold with atomic stock journal, undo, online-race compensation (schema v2)") and pushed to `origin/main`, with the user's approval after their phone test (normal fast-forward, no force).
- Phase 8 was committed as `753613c` ("Phase 8: label printing (A4 sheet and thermal layouts, QR minimum size, render cache, print page) and the Action Scheduler test-leak fix") and pushed to `origin/main`, with the user's approval, before their printer test (normal fast-forward, no force).
- Phase 9A was committed as a single commit as `5495b05` ("Phase 9A: sales history, payment method, admin-only cost price, seller My sales (schema v3)") and pushed to `origin/main`, with the user's approval on the automated tests, before their manual test (normal fast-forward, no force).
- Phase 9B was committed as two commits, with the user's approval on the automated tests (manual checks in the pre-launch acceptance checklist), and pushed to `origin/main` (normal fast-forward, no force): first the Phase 7 race fix as `072f4d6` ("Fix Phase 7 idempotency race: duplicate request mid-sale reported failed"), then Phase 9B as `716600d` ("Phase 9B: in-store reports and owner dashboard (end of day, profit, stock, dead stock; schema v4)"). Recorded at the start of Phase 10 (2026-09-26).
- Phase 10 was committed as `5b746a7` ("Phase 10: bulk code generation, codes CSV export, admin-only cost-price import") and pushed to `origin/main`, with the user's approval on the automated tests (manual checks in the pre-launch acceptance checklist; normal fast-forward, no force). Recorded at the start of Phase 10B (2026-09-27); this record is committed together with Phase 10B.
- Phase 10B was committed as `0145aff` ("Phase 10B: own QR & Barcodes menu, plugin Dashboard, shared navigation") and pushed to `origin/main`, with the user's approval on the automated tests (manual checks in the pre-launch acceptance checklist; normal fast-forward, no force). Recorded at the start of Phase 11 (2026-09-27), after checking that `HEAD` = `origin/main` = `0145aff` and the working tree was clean; this record is committed together with Phase 11.
- Phase 11 was committed as `32a8b90` ("Phase 11: hardening (health check, security fixes, undo expiry, perf signal, multisite refused)") and pushed to `origin/main`, with the user's approval on the automated tests (manual checks in the pre-launch acceptance checklist; normal fast-forward, no force). Recorded at the start of Phase 12 (2026-09-28), after checking that `HEAD` = `origin/main` = `32a8b90` and the working tree was clean; this record is committed together with Phase 12.
- Phase 12 was committed as two commits and pushed to `origin/main`, with the user's approval on the automated tests (manual checks in the pre-launch acceptance checklist; normal fast-forward, no force): first D12 as `ba18693` ("Set plugin author to Mosin Shaikh; remove shop name from plugin"), then Phase 12 as `1b583f9` ("Phase 12: theme compatibility (6 themes, cache safety, plain-permalink warnings)"), both 2026-09-28. Recorded at the start of Phase 13 (2026-09-28), after checking that `HEAD` = `origin/main` = `1b583f9` and the working tree was clean.
- Phase 13 was committed as two commits and pushed to `origin/main`, with the owner's approval (normal pushes, no force): first Phase 13 as `8670a30` (`8670a30fa466d564b08aa52ff7cb581f0f9f128c`, "Phase 13: release 1.0.0 (licences, guides, packaging, fresh-install test, launch runbook)"; 33 files), then the packaging fix as `7da1fa8` (`7da1fa8b648f2152b5a141fd6160ec1604851e0d`, "Fix build/package.php: clean builds deadlocked reading committed files"; `build/package.php` only, +5 / -2). **Release 1.0.0:** the annotated tag `pqbg-v1.0.0` (tag object `a0036035f5dd7de2a06f576b786f9858ea4acc6a`, message "Product QR Code and Barcode Generator 1.0.0") points at `7da1fa8` and is pushed. The release zip was built cleanly from `7da1fa8` (no `--allow-dirty`): `C:\xampp\backups\sharayu\release\product-qrcode-barcode-generator-1.0.0.zip`, 1,383,034 bytes, 252 files + 32 folders, **SHA-256 `a774011ae6479fbf236d582755469176055109e1fc5851d5a6b665eee12eb8df`** (the `.sha256` file says "built from commit 7da1fa8"). Recorded at the start of the 1.0.1 work (2026-09-28) from the Phase 13 report and the local refs (`main` = `refs/remotes/origin/main` = `7da1fa8b…`, tag ref = `a0036035…`, the working tree clean at the start of the session), then confirmed with git after `git fetch`: `HEAD` = `origin/main` = remote `main` = `7da1fa8b…`; `pqbg-v1.0.0` = `a0036035…` locally and on the remote, peeled to `7da1fa8b…` on both.
- Version 1.0.1 was committed as one commit ("Release 1.0.1: user manual PDF, Plugin guide button, seller guide links"), with the owner's approval on the automated tests (2,332 passed; the D10 upgrade check not run, owner's decision), tagged `pqbg-v1.0.1` (annotated, "Product QR Code and Barcode Generator 1.0.1") and pushed to `origin/main` (normal pushes, no force). The release zip was built from that commit into `C:\xampp\backups\sharayu\release\` (see the Version 1.0.1 section). The commit hash is recorded in the next session (it cannot be written into its own commit).

Tracked files — project code only; WordPress core, `wp-config.php`,
uploads and archives are excluded by `.gitignore`:

```
.gitignore
README.md
progress.md
LAUNCH.md   (Phase 13)
wp-content/plugins/product-qrcode-barcode-generator/
```

| Commit | Message |
|---|---|
| (1.0.1) | Release 1.0.1: user manual PDF, Plugin guide button, seller guide links (tagged `pqbg-v1.0.1`) |
| `7da1fa8` | Fix build/package.php: clean builds deadlocked reading committed files (tagged `pqbg-v1.0.0`) |
| `8670a30` | Phase 13: release 1.0.0 (licences, guides, packaging, fresh-install test, launch runbook) |
| `1b583f9` | Phase 12: theme compatibility (6 themes, cache safety, plain-permalink warnings) |
| `ba18693` | Set plugin author to Mosin Shaikh; remove shop name from plugin |
| `32a8b90` | Phase 11: hardening (health check, security fixes, undo expiry, perf signal, multisite refused) |
| `0145aff` | Phase 10B: own QR & Barcodes menu, plugin Dashboard, shared navigation |
| `5b746a7` | Phase 10: bulk code generation, codes CSV export, admin-only cost-price import |
| `716600d` | Phase 9B: in-store reports and owner dashboard (end of day, profit, stock, dead stock; schema v4) |
| `072f4d6` | Fix Phase 7 idempotency race: duplicate request mid-sale reported failed |
| `5495b05` | Phase 9A: sales history, payment method, admin-only cost price, seller My sales (schema v3) |
| `6c6dbc8` | Record Phase 8 printer-test open item (Phase 8: label printing, presets, QR min-size fitting, print page, SVG cache, AS leak fix) |
| `2861b15` | Record Phase 8 commit and push in progress log |
| `753613c` | Phase 8: label printing (A4 sheet and thermal layouts, QR minimum size, render cache, print page) and the Action Scheduler test-leak fix |
| `647424c` | Record Phase 7 commit and push in progress log |
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
- [ ] **Optional, later (owner's decision A, 2026-09-27: plugin first):** decide on the theme approach (customize twentytwentyfive, a child theme, or a custom theme). All plugin phases are completed before any theme work; the theme phases are at the end of the roadmap.
- [ ] The manual checks that must pass before launch are in **[`LAUNCH.md`](LAUNCH.md)** (the launch runbook; moved there from the Pre-launch acceptance checklist in Phase 13).
- [ ] **Deferred (owner to decide after launch):** a Hindi/Marathi seller guide (and translation of the staff screens; the .pot is ready). Recorded in Phase 13 at the owner's request.
- [ ] **Deferred (only for a possible WordPress.org release; owner's decision, Phase 13):** two translatable messages are built from separately translated parts, which translators cannot reorder: the print setup screen's local-address notice ("QR codes currently point to a local address. Do not print labels until the production URL is set." followed by "You can still print test labels; they are marked "TEST – NOT FOR USE"."; `PrintAdmin`), and the "Variation #%d: " prefix in front of a cost-price error (`CostPrice`). Left as they are.
- [ ] The user's label stock → possibly a new default print preset (A4 3 × 7 until then).
- [x] **Phase 11 (hardening):** the reconciliation check and the concurrency-test items from Phase 7. **Done in Phase 11:** the read-only Health check (Settings → Health check; a count on the Dashboard for administrators) and the minimum-stock / parent-level snapshot checks in the Phase 7 suite. See the Phase 11 section.
- [x] **Phase 10B = MENU RESTRUCTURE** (added 2026-09-27 in Phase 10; owner's decision Option 1): the plugin's own top-level "QR & Barcodes" menu with Dashboard, In-store sales, In-store reports, Bulk tools, Settings; nothing under WooCommerce; old addresses redirect. **Implemented 2026-09-27; see the Phase 10B section.**
- [x] **Phase 12 (theme compatibility, owner's decision B, 2026-09-27):** implemented and tested 2026-09-28 (see the Phase 12 section); committed as `ba18693` and `1b583f9` and pushed. Original item: plan it after Phase 11. The plugin must work with any WooCommerce theme: every front-end / staff-facing screen it outputs (the scan page, My sales, the login round trip, anything on product pages or My Account) works and looks usable with classic themes (e.g. Storefront) and block themes (e.g. Twenty Twenty-Five), with block and classic cart/checkout present; no theme-specific code; only WordPress/WooCommerce APIs and the plugin's own scoped CSS.
- [ ] **Performance (was the Phase 11 open item; still open by design):** If the dashboard exceeds 2 s on real data, or in-store sales exceed ~300/day, implement a daily roll-up table (option B) or a permission-keyed result cache (option C). Do not change the sale path for this before then. **Since Phase 11 the Dashboard tells administrators when this condition repeats** (3 of the last 10 Dashboard loads over 2 s, or more than 300 completed in-store sales a day over 30 days; `PerfSignal`); build nothing before it does. (Phase 9B measured the dashboard at about 1.4–1.55 s for 90 days with 50,000 sales in 90 days; every report meets the 1 s / 2 s targets at 5,000 sales in 90 days. See the Phase 9B section.)

### Pre-launch acceptance checklist

**Moved to [`LAUNCH.md`](LAUNCH.md) in Phase 13 (2026-09-28)**, the ordered launch runbook at the repository root, where every item of this checklist is a step marked **[acceptance]** (A2–A8, D3–D5), merged with the launch-day steps (hosting, moving the site, Scan base URL, permalinks, cache exclusions, Coming Soon, the phone-test snippet, staff accounts, the first real labels, backups on the host). The 50,000-sale stress checks are done (A8, Phase 13). Keep `LAUNCH.md` as the single list: future manual checks go there.

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
| **8** | **Printing** | **Done 2026-09-25. Committed `753613c`, pushed.** |
| **9A** | **Sales history, payment method & cost price** | **Done 2026-09-26. Approved on the automated tests; committed `5495b05`, pushed. Manual check pending (pre-launch acceptance checklist).** |
| **9B** | **Reports & owner dashboard** | **Done 2026-09-26. Approved on the automated tests; committed as `072f4d6` (Phase 7 race fix) and `716600d` (Phase 9B), pushed. Manual checks pending (pre-launch acceptance checklist).** |
| **10** | **Bulk / CSV tools** | **Done 2026-09-27. Approved on the automated tests; committed `5b746a7`, pushed. Manual checks pending (pre-launch acceptance checklist).** |
| **10B** | **Menu restructure (own "QR & Barcodes" menu) and plugin Dashboard** | **Done 2026-09-27. Approved on the automated tests; committed `0145aff`, pushed. Manual checks pending (pre-launch acceptance checklist).** |
| **11** | **Hardening and performance (incl. the 50,000-sale stress checks)** | **Done 2026-09-27. Approved on the automated tests; committed `32a8b90`, pushed.** Manual checks in the pre-launch acceptance checklist. |
| **12** | **Theme compatibility (owner's decision B: any WooCommerce theme, classic and block, block and classic cart/checkout; no theme-specific code)** | **Done 2026-09-28. Approved on the automated tests; committed as `ba18693` (D12, author and shop name) and `1b583f9` (Phase 12), pushed.** Manual checks in the pre-launch acceptance checklist. |
| **13** | **Plugin QA, documentation and packaging (version 1.0.0)** | **Done 2026-09-28. Approved; committed as `8670a30` and `7da1fa8` (packaging fix), tagged `pqbg-v1.0.0` on `7da1fa8` and pushed; release zip SHA-256 `a774011a…eb8df`** (see the Phase 13 section). Note from Phase 11 (owner's decision): **Revisit coding-standards cleanup if the plugin is ever published on WordPress.org.** (PHPCS findings recorded in the Phase 11 section; only the 3 missing translator comments were fixed.) |
| **1.0.1** | **User manual (PDF) and help links (Plugin guide button, Plugins screen link, seller guide links)** | **Done 2026-09-29. Tested (2,332 passed, 0 failed, 0 skipped; the upgrade check not run, owner's decision); committed as "Release 1.0.1: user manual PDF, Plugin guide button, seller guide links", tagged `pqbg-v1.0.1` and pushed (see the "Version 1.0.1" section).** |
| later | Theme work (optional, later; owner's decision A: plugin first) | Not started |

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
- ~~**Phase 9:** the sales history UI and the manager void UI on top of `SaleService::void_sale()`. Reports must count `completed` rows only, and show `voided` ones as voided.~~ **Done in Phase 9A** (see below); the rule carries over to the Phase 9B reports.

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

**Status:** implemented and tested. The plugin stays active. **Approved** by the user on 2026-09-25, before their printer test, then committed as a single commit, `753613c`, and pushed to `origin/main` with a normal push (no force). The manual printer test (the Phase 8 checklist in the plugin README) is still to be done by the user.

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
- **Must be done BEFORE printing real labels (i.e. before production launch):** Phase 8 physical printer test pending: print on plain paper and on the actual label stock, check size/alignment (use the printer offset if needed), check ₹ and text, and scan several labels with a phone. No printer was available on 2026-09-25, so this is NOT done; Phase 8 was approved on the automated print verification (headless Chrome/Edge geometry, 203/300 dpi decoding, PDF checks). Steps: the "Phase 8 checklist" in the plugin README.
- The user's label stock → possibly a new default preset (A4 3 × 7 until then).
- **Phase 11 (hardening):** the reconciliation check and the concurrency-test items from Phase 7 are still open.

**Next phase (not started):** Phase 9, Seller Dashboard / Sales History. **The production domain is still not final**: printed labels stay TEST labels until it is.

### Phase 9A: Sales history, payment method & cost price (2026-09-25 → 2026-09-26)

**Status:** implemented and tested. **Approved by the user on 2026-09-26 on the automated tests** (the manual test is NOT done yet: it is an open item, to be done before launch), then committed as a single commit, `5495b05` ("Phase 9A: sales history, payment method, admin-only cost price, seller My sales (schema v3)"), and pushed to `origin/main` with a normal push (no force). The plugin stays active; the live site migrated to schema v3 with 0 sales rows.

**Environment:** re-verified at the start, with no differences: WP 7.1.2, WC 11.1.2 (HPOS on), PHP 8.5.6 ZTS, MariaDB 10.4.32; 0 codes, 0 sales, 0 products, 0 orders, 1 user; DB_VERSION 2; Asia/Kolkata; INR, 2 decimals; Coming Soon on; `WP_ENVIRONMENT_TYPE` unset (tests use `PQBG_TESTS_ALLOW_PRODUCTION=1`). `HEAD` = `origin/main` = `6c6dbc8`, clean.

**Windows crash count** (`httpd.exe`, Event ID 1000): **139 before and after every run** in this phase (baseline, development runs, the interrupted run and the final run). No Apache restarts (the same two `httpd.exe` processes, PIDs 220 and 14284, throughout).

**Baseline before any change:** ALL PASSED, **1,135 checks, 0 failed, 0 skipped**, AS guard PASS for every suite (decoder and print-check from the session scratchpad).

**Approved plan** (D1–D13 as recommended, plus the user's two additions):
- **D1** My sales at `/scan/my-sales/` (a reserved segment under the existing rule; no new rewrite rule)
- **D2** the history opens on Today
- **D3** My sales hides failed attempts
- **D4** CSV neutralisation leaves generated numbers (e.g. `-60.00`) numeric
- **D5** one meta key `_pqbg_cost_price`; on a variable product it is the default for its variations (after variable → simple it becomes the simple product's cost)
- **D6** cost never in REST or exports, not even for administrators; REST, importers and Duplicate cannot write/copy it
- **D7** the delete-all uninstall also deletes the cost meta; the default uninstall is unchanged
- **D8** add `method_created` only on EXPLAIN evidence (done: added; a covering totals index measured and rejected)
- **D9** the seller column shows the name snapshot
- **D10** 50 rows per history page; My sales at most 300 lines
- **D11** void reason 1–500 characters; no age limit
- **D12** the new product/meta hooks approved (listed in the next-session instructions)
- **D13** the "false by design" updates to phase2-main, phase4 and phase7. Found during implementation and handled the same way: phase4's default-settings check, and two phase7 scope checks (`update_post_meta` now allowed only in `CostPrice` and only on its own key; `sales_table()` may appear in the read-only `SalesQuery`, which is checked to contain no `$wpdb` write). All listed in `tests/README.md`.
- **Addition 1 (WXR export):** `wxr_export_skip_postmeta` skips the cost **for everyone** (justification: the WXR importer cannot write it back, so an exported cost could only leak); tested with a real Tools → Export as a shop manager and as an administrator.
- **Addition 2 (deletion paths):** the delete guard never blocks legitimate removal: permanent deletion goes through `delete_metadata_by_mid` (never hooked), bulk `$delete_all` and user-less requests pass. Tested: permanent delete, trash → delete, variation removal (AJAX), variable → simple, the delete-all statement, WXR import as a shop manager (the official WordPress Importer, loaded in-process from outside the site), plus the WooCommerce CSV importer; no orphaned cost meta.

**Schema v3** (`migrate_3`, additive dbDelta): `pqbg_sales.payment_method varchar(20) NULL`, `unit_cost decimal(26,8) NULL`, `seller_name varchar(250) NULL`, `KEY method_created (payment_method, created_at_gmt)`. Existing rows keep NULL ("Not recorded" / unknown). `install()` syncs roles afterwards, which grants **`pqbg_view_costs`** (new, administrator only).

**D8 evidence** (50,000 rows on a temporary-prefix table, 90 days, before choosing the schema; best of 5):

| Query | Without `method_created` | With it |
|---|---|---|
| count, this month + card | full scan, 49,440 rows, 42.5 ms | `method_created`, 2,032 rows, 2.45 ms |
| count, 90 days + card | full scan, 47.4 ms | 13,382 rows, 7.5 ms |
| count, 90 days + not recorded | full scan, 39.5 ms | 1,042 rows, 0.8 ms |
| list, 90 days + other (LIMIT 50) | `created_at_gmt`, 24,720 rows, 13.8 ms | 1,558 rows, 1.1 ms |
| totals, 90 days + card | `status_created`, 219 ms | `method_created`, 37.5 ms |
| unfiltered queries | unchanged (same plans) | |

A covering index for the totals (`status, created_at_gmt, payment_method, quantity, line_total, unit_cost`) took 90-day totals from 485 to 175 ms; **rejected**: under the 1 s target without it, and a six-column index on every sale write.

**Design:**
- `PaymentMethods` (keys, labels, enabled list); `Settings`/`SettingsPage` ("In-store sales" section, at least one method, a presence marker for unticked checkboxes); `SaleRequest` validates after the existing-request check and keeps quantity and method on a re-shown form; `SaleService::sell()` validates again and writes `payment_method`, `unit_cost` (`CostPrice::effective()`, read fresh under the lock) and `seller_name` in the pending row.
- `CostPrice`: the three edit-screen fields, the two save actions (only `pqbg_view_costs`, only when posted), `normalize()`, `get()/effective()/set()`, and the guards (read_meta filter, add/update guard, narrow delete guard, WXR skip).
- `SalesQuery` (read-only): site-timezone ranges, filters, whitelisted sorting, the list, one-pass totals (grouped by status and method), keyset-paged export chunks. `SalePresenter`: labels and formatting. `SalesListTable` (`WP_List_Table`), `SalesAdmin` (menu after Orders, list/detail/void screens, the void POST), `SalesExport` (streamed CSV).
- My sales: `ScanRoute::decide()` → `ScanScreen::my_sales()` in the standalone template; header links "My sales"/"Scan".

**Files created** (plugin-relative): `includes/PaymentMethods.php`, `CostPrice.php`, `SalesQuery.php`, `SalePresenter.php`, `SalesListTable.php`, `SalesAdmin.php`, `SalesExport.php`; `tests/phase9a-sales-history.php`.

**Files modified:** `includes/Schema.php`, `Install.php`, `Permissions.php`, `Plugin.php`, `Settings.php`, `SettingsPage.php`, `SaleService.php`, `SaleRequest.php`, `ScanScreen.php`, `ScanRoute.php`, `ScanUrl.php`; `templates/pqbg-scan.php`; `assets/pqbg-scan.css`; `uninstall.php`; `tests/run.php`, `tests/phase2-main.php`, `tests/phase4-rendering.php`, `tests/phase7-sales.php`, `tests/README.md`; `README.md` (plugin); `progress.md`. `SaleRepository`, `CodeRepository`, the renderers, the printing classes and `vendor-prefixed/` are unchanged. No REST/AJAX/nopriv/shortcode.

**Tests.** `php tests/run.php` with `PQBG_TESTS_ALLOW_PRODUCTION=1`, `PQBG_DECODER`, `PQBG_PRINTCHECK` and `PQBG_WXR_IMPORTER` (all from the session scratchpad). **Final run: ALL PASSED, 1,388 checks, 0 failed, 0 skipped; AS guard PASS for every suite** (15.5 min, 2026-09-26 00:37–00:53). **Crash count 139 before and after.** The site is back to its clean state afterwards: 0 codes, 0 sales, 0 products, 0 orders, 1 user, 0 cost meta, no orphans, options byte-identical.

| Suite | Result |
|---|---|
| phase2-main | 83/83 (columns, 10 indexes, 8 admin caps updated for v3) |
| phase2-lifecycle | 17/17 |
| phase2-no-woocommerce | 12/12 |
| phase3-codes | 110/110 |
| phase4-rendering | 165/165 (default settings updated) |
| phase5-admin | 158/158 |
| phase6-scan | 214/214 |
| phase7-sales | 209/209 (payment method on every sale; v3-aware migration fixture; 2 scope checks updated) |
| phase8-printing | 167/167 |
| **phase9a-sales-history** | **253/253** (incl. the real WXR import) |

**Timings, final run** (50,000 synthetic sales over 90 days; in-process best of 3 with the object cache flushed; HTTP medians of 3):

| Measurement | Result |
|---|---|
| List page (rows + count): this month / 90 days / 90 days + card / + not recorded / + seller | 10.5 / 30.2 / 7.9 / 2.5 / 5.7 ms |
| List: 90 days + search / sorted by total | 178.8 / 169.0 ms |
| Totals bar: this month / 90 days / 90 days + card / + seller | 115.6 / 232.0 / 72.6 / 36.3 ms |
| **Target "list and totals under 1 s": met** (worst 232 ms) | |
| HTTP history page (admin): empty range (wp-admin alone) / this month / 90 days / 90 days + card | 685 / 631 / 773 / 543 ms (the previous run: 1,115 / 1,076 / 1,428 / 1,243 ms: machine load; the page adds ≤ 0.3 s over an empty wp-admin page) |
| HTTP My sales, last 7 days (~366 sales of that seller, 300 listed) | 241 ms |
| CSV, 50,018 rows: in-process / HTTP | 3.7 s / 3.3 s, 6.9 MB, **memory peak +7.1 MB**, 51 chunks of 1,000 |

**EXPLAIN (final run, real v3 table, 50,000 rows):** list 90 days → `created_at_gmt`; + card / + not recorded → `method_created` (14,556 / 1,009 rows); + seller → `seller_created` (9,316); totals 90 days (the whole table) → table scan (the cheapest plan there); totals + card → `method_created`.

**Problems found and fixed during development:**
- **CSV export speed.** The first version paged with OFFSET and formatted each row with `wc_format_decimal()`/`wp_date()`/fresh label lookups: 50,018 rows took 31 s (23.6 s of it OFFSET queries). Now: keyset paging on (sort value, id), 5,000 IDs per page, rows by primary key 1,000 at a time, labels memoised, `number_format()` and one timezone conversion per date → 3.3–5.9 s depending on machine load, memory peak about +7 MB. A first "all IDs at once" version held 50,000 rows in `$wpdb->last_result` (+25 MB) and was replaced; a test checks that keyset paging returns exactly the rows and order of one direct query (by date, by total with many ties, by product).
- **Totals:** one pass grouped by status and method replaced totals + a separate status count (90 days: 738 → about 340 ms).
- **Test-suite bugs** (not plugin bugs): a heredoc cut the suite file (rewritten with the editor); `wp_insert_user()` sanitises display names (the HTML seller name is written directly to test output escaping); WooCommerce's variation save turns a variation off without `variable_enabled`; `options.php` redirects to a relative path; `WP_List_Table` redirects a page past the end; a fixture product called "No Cost" matched the "no cost anywhere" check; the performance INSERT had 16 placeholders for 17 columns.
- **Incident 1 — `wp_die()` in the WooCommerce CSV importer aborted the first run before its cleanup** (a `.tmp` file name; `wp_die()` exits, so `finally` never ran). Left: 11 products/variations (IDs 6089–6099), 7 users (497–503), 6 sales rows, 10 codes, 13 + 11 lookup jobs, and `pqbg_settings` changed by the settings test. All listed first, then removed; tables' AUTO_INCREMENT reset; `pqbg_settings` restored byte for byte. The suite now turns `wp_die()` into an exception and uses real `.csv`/`.xml` file names.
- **Incident 2 — the WXR fixture moved `wp_posts` AUTO_INCREMENT to 987,654,322.** Its `<wp:post_id>987654321</wp:post_id>` became `wp_insert_post()`'s `import_id` (used when that ID is free), and InnoDB keeps the counter after the post is deleted. The next full run's Phase 6 cleanup then ran `range( start, max post ID )`, died (17 GB allocation) and left its data; the run was stopped during Phase 7. Left and removed (listed first, all verified as test data): 5 Phase 6 users (543–547) and one Quick Draft auto-draft, 22 code rows, test term #66, 17 orphaned postmeta rows / 2 term relationships / 1 lookup row of one deleted fixture product, 54 lookup jobs, Phase 6's temporary directory, and a leftover `pqbg9a-*.tmp`. Options, Coming Soon, permalinks and active plugins were already restored by Phase 6. `wp_posts` AUTO_INCREMENT was set back to 6200 (max ID 2922; all original content, 13 posts and their 4 meta rows, intact). The fixture now uses an existing post ID, and the suite asserts that the posts AUTO_INCREMENT does not jump.
- WooCommerce's own site jobs scheduled during this phase (count caches, pending-orders batch, `fetch_patterns`, `migration_hook`) were left in place, as in Phase 8 (they reference no test data).

**Known limitations:** one payment method per sale (no split payments, no editing: void and sell again); cost only on the classic edit screen by administrators (bulk import is Phase 10); Duplicate does not copy the cost; profit ignores taxes/discounts/returns; the history page's time is mostly wp-admin itself on this machine (0.7–1.1 s for an empty page); no PHPCS run (not installed).

**After the report (2026-09-26):**
- **The manual Phase 9A test has NOT been done.** (A message saying it had passed was sent by mistake and was withdrawn by the user; the database showed no manual-test data.) It is an open item, to be done before launch (see the Status open items).
- **Reference check** (the user's request): nothing references a post ID above 6200 (the reset AUTO_INCREMENT) in postmeta, term_relationships, comments, `wc_product_meta_lookup`, `wc_product_attributes_lookup`, the HPOS order tables (`wc_orders`, meta, addresses, operational data, order items, stats, product lookup), `pqbg_codes`/`pqbg_sales`, or Action Scheduler args and logs; nothing in the old 987,654,321+ range; no orphans.
- **Database backup:** `C:\xampp\backups\sharayu\sharayu-20260926-120456-before-phase9a-final.sql` (1.7 MB, 53 tables, complete), outside the web root and the repository.
- **Pre-commit run** (2026-09-26 12:08–12:24): **ALL PASSED, 1,388 checks, 0 failed, 0 skipped; AS guard PASS for every suite**; crash count **139 before and after** (same Apache processes). Same per-suite counts as the final run above; timings in line with it (list this month 16 ms, 90-day totals 221 ms, HTTP history 537–757 ms with wp-admin alone at 689 ms, CSV of 50,018 rows 3.1 s over HTTP). The site is clean afterwards.
- **Approved** by the user on the automated tests; committed and pushed (see Status).

**Next phase (not started):** Phase 9B, Reports & owner dashboard (since done; see below). **The production domain is still not final** and the Phase 8 printer test is still open.

### Phase 9B: Reports & owner dashboard (2026-09-26)

**Status:** done; approved on the automated tests and committed as two commits, pushed to `origin/main`: `072f4d6` (the Phase 7 race fix) and `716600d` (Phase 9B). _Originally written before approval as:_ implemented and tested; NOT committed, waiting for the user's approval. After approval: two commits, first the Phase 7 race fix ("Fix Phase 7 idempotency race: duplicate request mid-sale reported failed": the `SaleService` change, its comment correction and the new deterministic Phase 7 checks), then Phase 9B. The plugin stays active; the live site migrated to schema v4 with 0 sales rows.

**Environment:** re-verified at the start, with no differences: WP 7.1.2, WC 11.1.2 (HPOS on, sync off), PHP 8.5.6 ZTS, MariaDB 10.4.32; 0 codes, 0 sales, 0 products, 0 orders, 1 user; DB_VERSION 3; Asia/Kolkata, week starts Monday; INR, 2 decimals; Coming Soon on; WooCommerce Analytics on with scheduled import (12-hour lookup-table lag); low-stock threshold 2, out-of-stock 0. `HEAD` = `origin/main` = `5495b05`, clean.

**Database backup:** `C:\xampp\backups\sharayu\sharayu-20260926-123420-before-phase9b.sql` (1.7 MB, 53 tables, "Dump completed"), outside the web root and the repository.

**Windows crash count** (`httpd.exe`, Event ID 1000): **139 before and after every run** in this phase (baseline, Phase 7 re-runs, 9B development runs, profiling, the final 9B run and the final full run). No Apache restarts (PIDs 220 and 14284 throughout).

**Baseline before any change** (decoder, print-check and WXR importer from the session scratchpad): **1,387 passed, 1 failed, 0 skipped**, AS guard PASS for every suite. The failure was genuine, not a crash: Phase 7 "the same request_id from 4 processes at once → all get the same completed sale" got one "failed" with the row still pending. Cause: `SaleService::sell()` checked for an existing request *before* taking the stock lock and, finding the winning process's row still pending, `outcome()` treated it as a dead process. Data was right (one row, one decrement); the duplicate's answer was wrong. Phase 7 alone passed 209/209 on a re-run (intermittent).

**Approved plan:** D1–D15 as recommended (D1 WooCommerce → In-store reports after In-store sales; D2 comparison to the same point in time; D3 categories counted in each category with sub-categories rolled up once, deleted products in their own row; D4 dead stock reads online order line items, read-only, HPOS-safe; D5 migration 4 `void_restock`; D6 fix the Phase 7 race now; D7 no index, no persistent cache, stop if a target is missed; D8 in-process timing asserts; D9 no new capability, cost requests 403; D10 the no-code alert links to the Stock report's list; D11 new items are not dead; D12 shared stock valued only with one price/cost; D13 voids by sale date plus "voided today, sold earlier"; D14 presets; D15 the false-by-design updates). The user's additions: **the D6 fix as a separate commit before Phase 9B**, and **"Cash expected in drawer" / net collected** on the end of day (per method, per seller who sold, "Voided today by <user>", in the print page and the CSV).

**Performance decision (2026-09-26, after the D7 stop):** the dashboard missed "under 1 s for 90 days" at 50,000 sales in 90 days. The user chose option A: accept and document; keep the 50,000-sale checks as a 2 s regression guard; add a realistic-volume check (5,000 sales over 90 days) held to the original targets; record the miss here and in the README; add the Phase 11 open item (roll-up table or permission-keyed result cache if the dashboard exceeds 2 s on real data or sales exceed ~300/day; do not change the sale path before then). Options B (roll-up), C (result cache) and D (covering index) were **not** implemented.

**Design:**
- **Counting rules** (on every screen): in-store sales only; a sale is one row; revenue/items/sales count completed rows only; amounts are the snapshots; profit only where `unit_cost` is known, margin = profit ÷ revenue with a known cost, unknown cost always disclosed and never zero; site-timezone days; everything by sale date (reconciles with the history).
- `ReportPeriod` (presets, comparison, buckets), `ReportsQuery` (read-only aggregates: `INTERVAL()` buckets over the sale's Unix time with local boundaries from PHP; the peak grid from 15-minute UTC slots mapped in PHP; one scan for the dashboard's cards, payment split, chart, voids and top sellers), `StockQuery` (stock holders, dead stock, online last sale, missing codes), `ReportData` (one dataset per report, shared by the screen, the CSV and the print page), `ReportsAdmin` (menu, tabs, screens, the cost-refusal gate in `load-`), `ReportTable` (`WP_List_Table`), `ReportChart` (inline SVG, no JavaScript), `ReportsExport` (CSV), `ReportPrint` + `templates/pqbg-report-print.php` (standalone print page with the Phase 8 approach and a strict CSP), `assets/pqbg-reports.css`, `assets/pqbg-report-print.css`.
- `CostPrice::get_many()` (costs of many items in one query, the same values as `get()`); `SalesExport::put()` made public (shared CSV writer).
- **Schema v4** (`migrate_4`, additive): `pqbg_sales.void_restock tinyint(1) NULL`; `SaleService::void_fields()` writes 1 for a restocking void or an undo, 0 for a void without restock (`SaleRepository` allows the column in the stock statement).
- **D6 fix:** in `SaleService::sell()`, an existing row that is still `pending` (same seller, known stock holder) is re-read after waiting for the holder's lock; the `outcome()` comment now says why a pending row there can only be a dead process.
- Hooks: only `admin_menu`, `admin_enqueue_scripts`, the page's `load-` hook and two `admin_post_` handlers (CSV, print), admin requests only. No REST/AJAX/nopriv/shortcode, no new capability, no cache.

**Files created** (plugin-relative): `includes/ReportPeriod.php`, `ReportsQuery.php`, `StockQuery.php`, `ReportData.php`, `ReportsAdmin.php`, `ReportTable.php`, `ReportChart.php`, `ReportsExport.php`, `ReportPrint.php`; `templates/pqbg-report-print.php`; `assets/pqbg-reports.css`, `assets/pqbg-report-print.css`; `tests/phase9b-reports.php`.

**Files modified:** `includes/SaleService.php` (D6 fix; `void_restock`), `SaleRepository.php`, `Schema.php`, `Install.php` (v4), `CostPrice.php` (`get_many()`), `SalesExport.php` (`put()` public), `Plugin.php` (wiring, after the class files existed); `tests/run.php`, `tests/phase2-main.php`, `tests/phase7-sales.php` (D6 checks and the `finish` worker; v4 fixture; the `ReportsQuery` scope allowance), `tests/phase9a-sales-history.php` (v4-aware checks), `tests/README.md`; `README.md` (plugin); `progress.md`.

**Tests.** `php tests/run.php` with `PQBG_TESTS_ALLOW_PRODUCTION=1`, `PQBG_DECODER`, `PQBG_PRINTCHECK` and `PQBG_WXR_IMPORTER` (all from the session scratchpad). **Final Phase 9B suite run** (2026-09-26 17:05–17:17): **ALL PASSED, 148 checks, 0 failed; AS guard PASS**; crash count 139 before and after. **Final full run (2026-09-26 17:18, interrupted):** Claude Code stopped the runner because the machine ran critically low on memory (about 0.8 GB free of 8 GB, with other applications open) while the Phase 9B suite was in its cleanup; it was not a test failure and there was no Apache crash (139 before and after, the same processes). Every earlier suite had finished: Phases 2–6, 8 and 9A all passed; **Phase 7 had 212 passed, 1 failed**, a check made false by design by schema v4 and missed in the earlier updates ("pqbg_sales has stock_holder_id and failure_code, appended after every v1 column" expected 30 columns; there are now 31). It is fixed (31 columns) and passed in the final suite-by-suite run below. The interrupted cleanup left test data, which was listed, verified as that run's only (products "PQBG 9B …" with IDs 12680–13341, all 1,017 codes (the site had none), 5 `pqbg9b_*` users, 14 "PQBG9B" categories, 4 cost rows on those products, 1,096 lookup jobs of those products and 8 orphaned log rows of four of them) and removed; the site is back to its clean state (0 codes, 0 sales, 0 products, 0 orders, 1 user, no cost meta, no orphans, options unchanged). A suite-by-suite re-run followed (below).

**Final regression, suite by suite** (2026-09-26 22:49–23:18, after the user approved a re-run). Free memory was already 1,462 MB (below the agreed 1.5 GB threshold) before starting, so, as instructed, each suite ran on its own through `run.php` (with the AS guard), one at a time. **All 11 suites passed: 1,540 checks, 0 failed, 0 skipped; AS guard PASS for every suite; crash count 139 before and after every suite** (the same Apache processes). Free memory stayed between 1.0 and 1.6 GB.

| Suite | Result | Free memory before → after |
|---|---|---|
| phase2-main | 83/83, AS guard PASS | 1,469 → 1,487 MB |
| phase2-lifecycle | 17/17, AS guard PASS | 1,483 → 1,486 MB |
| phase2-no-woocommerce | 12/12, AS guard PASS | 1,491 → 1,490 MB |
| phase3-codes | 110/110, AS guard PASS | 1,481 → 1,469 MB |
| phase4-rendering | 165/165, AS guard PASS | 1,459 → 1,142 MB |
| phase5-admin | 158/158, AS guard PASS | 1,159 → 1,609 MB |
| phase6-scan | 214/214, AS guard PASS | 1,482 → 1,286 MB |
| phase7-sales | 213/213, AS guard PASS (with the 31-column fix) | 1,269 → 1,491 MB |
| phase8-printing | 167/167, AS guard PASS | 1,498 → 1,402 MB |
| phase9a-sales-history | 253/253, AS guard PASS | 1,296 → 1,284 MB |
| **phase9b-reports** | **148/148, AS guard PASS** | 1,247 → 1,398 MB |

**The race-fix checks, 5 runs in a row** (the whole Phase 7 suite each time, 23:18–23:35): 213/213 every time; the 4-process duplicate check passed every time; the deterministic duplicate waited 1.51 / 1.52 / 1.52 / 1.52 / 1.52 s for the lock and got "completed" every time; AS guard PASS; crash count 139 throughout.

**Evidence for the report** (scratchpad script, everything it created removed afterwards): on 5,000 marked sales over 90 days, every report total (dashboard cards and payment split; the sums of the rows of sales over time, products, sellers, the 168 peak cells; the categories' "each sale once" total; the end-of-day total; the profit total; the voids list) equals the Phase 9A history totals (`SalesQuery::totals()`) for the last 90 days (revenue 24,152,456.00, 4,541 sales, 9,072 items, profit 6,730,952.40, 1,345 sales with unknown cost, 279 voided, 180 failed) and for the last 7 days. A shop manager over real HTTP: every tab 200 with no cost term, no planted cost value and no cost column in any CSV; the print page has none; the profit tab, the profit CSV (even with an administrator's link), sorting by profit and sorting by value at cost all get 403 (the administrator gets 200 and sees them).

**Backups:** `sharayu-20260926-123420-before-phase9b.sql` (start of the phase) and `sharayu-20260926-224829-before-phase9b-final-regression.sql` (927,358 bytes, 53 tables, complete; smaller because WordPress had deleted 14 expired core transients, the dashboard news feeds, events and translations list, about 800 KB). The commit-1 patch is saved as `C:\xampp\backups\sharayu\phase7-race-fix.patch` (6,027 bytes; applies cleanly to `5495b05`); the final report as `C:\xampp\backups\sharayu\phase9b-final-report.txt`.

**D6 verification:** the new deterministic check fails on the pre-fix `SaleService` ("waited 0.00 s; failed") and passes with the fix (waited ~1.5 s for the lock, then the same completed sale).

**Phase 9B suite coverage:** schema v4 and `migrate_4` on a temporary prefix, the flag from real voids and an undo; periods (presets identical to the history's except "This month" running to the end of today, comparison to the same point in time, 31 March vs February in 2024 and 2026, 30/31 May, week start from Settings, buckets); every counting rule on a fixed week (10–16 March 2025) with hand-computed values (₹1,880 completed from 8 sales and 12 items; profit 170 on a known revenue of 1,300, margin 13.1 %; 4 sales / ₹580 with unknown cost; per method, day, item, product, category, seller) reconciled with the Phase 9A totals; 18:29:59 / 18:30:00 UTC boundaries for day, week, month, hour, the peak grid and the end of day; the end of day of 15 April 2025 (cash 1,490 − 1,000 refund = ₹490 expected; UPI −100; the same-day void not subtracted twice; per seller; "voided today by"; the CSV); stock values and thresholds (₹4,740 at price, ₹6,628.25 at cost with 4 uncosted items disclosed); missing codes; dead stock with five real WooCommerce orders; slow sellers; CSV rules; SVG charts; every tab, CSV and the print page over HTTP for five roles (no cost anywhere for the shop manager, 403 on cost requests); escaping; print headers and CSP; GET/HEAD never write; performance; scope; cleanup.

**Timings, final 9B run** (in-process, best of 3, object cache flushed; 1,000 sellable items; the suite removed the 1,098 WooCommerce lookup jobs of its own products first):

| | 5,000 sales, 90 days | 5,000 sales, 12 months | 50,000 sales, 90 days | 50,000 sales, 12 months |
|---|---|---|---|---|
| Dashboard | 205 ms | 204 ms | **1,499 ms** | 1,374 ms |
| Sales over time | 39 ms | 49 ms | 375 ms | 406 ms |
| Products | 296 ms | 529 ms | 582 ms | 570 ms |
| Categories | 55 ms | 70 ms | 301 ms | 317 ms |
| Sellers | 59 ms | 60 ms | 499 ms | 512 ms |
| Peak times | 79 ms | 162 ms | 419 ms | 439 ms |
| End of day (whole range) | 51 ms | 83 ms | 491 ms | 509 ms |
| Profit (by item) | 314 ms | 337 ms | 609 ms | 571 ms |
| Voids & failed | 40 ms | 50 ms | 632 ms | 394 ms |
| Slow sellers | 344 ms | 386 ms | 962 ms | 690 ms |
| Stock | 326 ms | 484 ms | 534 ms | 325 ms |
| Dead stock | 144 ms | 278 ms | 564 ms | 434 ms |
| **Target** | **< 1 s: met** | **< 2 s: met** | < 1 s: **dashboard not met** (guard < 2 s: met) | < 2 s: met |

HTTP at 50,000 sales (median of 3): empty wp-admin page 800 ms; dashboard 1,489 / 1,477 ms (+690 / +678 ms over the empty page, 90 days / 12 months), products 1,665 / 1,427 ms, sellers 1,046 / 1,031 ms, peak 928 / 939 ms, sales 810 / 728 ms, profit 733 / 756 ms.

EXPLAIN (50,000 rows): the period scan (status × method × seller) → table scan (the whole range), temporary; products (completed) → `status_created`; end of day for one day → `status_created` (353 rows); refunds of the day → `status_created` (voided rows, 3,054). No new index.

**Why the dashboard misses at 50,000 sales, and what was measured:**
- Grouping 50,000 rows costs 350–450 ms per query in MariaDB 10.4 (a temporary table); the dashboard needs two scans (the period, with payment split, chart and sellers; the top products) plus the stock counts. The comparison period is usually small.
- PHP overhead removed first: `wc_get_price_decimals()` read once per request, amounts summed once and rounded at the end (was: rounded per row), stock meta read by key instead of priming every meta row of 1,070 posts, costs in one query (`CostPrice::get_many()`), the dashboard names only its top 5 products, the products report totals from its own rows, slow sellers without names. Dashboard at 90 days: 2.0 s → 1.4–1.5 s; products 1.3 → 0.6–0.9 s; slow sellers 1.9 → 1.0 s.
- The planning probe ran 1.35–1.6× slower during this work than at planning (CPU 22–50 % busy with other applications); the early suite timings also ran during ~730 WooCommerce background jobs from the test's own product saves (now removed before timing).
- `ORDER BY NULL` and index hints: no gain. A covering index `(created_at_gmt, status, payment_method, seller_id, product_id, variation_id, quantity, line_total, unit_cost)` on a temporary copy: products and peak about −40 %, the dashboard scan unchanged, +0.4 ms per sale insert, 14 s to build per 50,000 rows → not added (D7; the user's decision).

**Problems found and fixed during development:**
- The Phase 7 race (above, D6).
- Line endings: the files edited in this session were written with CRLF; all were normalised to LF (as in the repository) before the final runs.
- Test-suite fixes (not plugin bugs): the stock and dead-stock builds need no period; "This month" in the reports ends today (by design) while the history's ends at month end; the injection fixture's product ID 1 is not a product, so its name carries "(deleted)"; edit links need a logged-in user in-process; row orders depended on random product names; the "GET never writes" checksum counted every WordPress option (wp-admin writes transients) and now covers the pqbg options only.

**Known limitations:** categories are the products' current ones; stock reports use current stock, prices and costs; shared stock with mixed prices or costs is not valued (disclosed); the end-of-day refund assumption (original payment method) is stated, not recorded; voids before v4 show "Not recorded"; online orders are read only for dead stock (no combined report); the dashboard's 90-day target is not met at 50,000 sales in 90 days (Phase 11 open item); no PHPCS run (not installed).

**Manual checks:** not asked for in this phase; added to the **Pre-launch acceptance checklist** (dashboard figures, end-of-day print, a CSV in Excel, a shop manager sees no profit) with the steps in the plugin README ("Phase 9B checklist").

**Next phase (not started):** Phase 10, Bulk / CSV tools. **The production domain is still not final** and the Phase 8 printer test is still open.

### Phase 10: Bulk and CSV tools (2026-09-26 → 2026-09-27)

**Status:** done; approved on the automated tests, committed as `5b746a7` and pushed to `origin/main`. _Originally written before approval as:_ implemented and tested; NOT committed, waiting for the user's review. The plugin stays active; no schema change (`DB_VERSION` 4).

**Start state:** `HEAD` = `origin/main` = `716600d`, clean. Roadmap numbering confirmed (Phase 10 = Bulk / CSV tools). Housekeeping: `072f4d6` and `716600d` recorded in this file.

**Backups** (`C:\xampp\backups\sharayu\`, outside the web root, never committed): `sharayu-20260926-234618-before-phase10.sql` (933,640 bytes, 53 tables, complete; before the baseline) and `sharayu-20260927-105123-before-phase10-tests.sql` (946,004 bytes, 53 tables, complete; before the final runs).

**Baseline** (suite by suite, free memory 1,271 MB): 1,540 passed, 0 failed, 0 skipped after re-running phases 4, 5, 6 and 8 with the decoder/print-check paths pointing at the `.mjs` files (the first pass had pointed at folders, so those suites skipped their round-trip checks; not a failure). Crash count 139 throughout.

**Approved plan:** D1a, D2a, D3a, D4, D5a, D6a (code import left out, in the backlog), D7, D9a, D10, D11a, D12a, D14, D15 with the timing targets, D16. **Changed by the user (D8/D13):** no new menu items; the tools are tabs of the existing WooCommerce → QR & Barcodes page (Settings | Code tools | Import cost prices), the page opens for `pqbg_manage_codes`, each tab gated on its own (Settings `pqbg_manage_settings`, Code tools `pqbg_manage_codes`, Import cost prices `pqbg_view_costs`), a tab the user lacks is not rendered and its URL and every handler return 403 even with an administrator's nonce/token. **Additions:** expired cost-import previews of every user deleted whenever the page loads and on uninstall (tested with an expired preview holding a cost); the test tools installed permanently in `C:\xampp\tools\pqbg\`; the MENU RESTRUCTURE open item (above).

**Design:**
- `BulkGenerator`: qualifying items = automatic-assignment rules (simple products and variations of variable products whose product status is publish/private/draft/pending/future; never trash, auto-draft, importing, variable parents, grouped/external, orphan or misplaced variations; stock tracking irrelevant); counts by type and status; batches of 100 items or ~10 s via `admin-post` POSTs (auto-continue script, Continue button without JS); no stored queue (every batch re-queries "qualifying, no active code, ID above the cursor"), so stop/resume/abandon/re-run are safe; codes only through `ProductCodeService::get_or_create()` (new optional `&$created` out-parameter); item re-checked before each code; one active run at a time (5-minute staleness), batches serialised by a MySQL `GET_LOCK` (released if the request dies); run state in `pqbg_bulk_run` (not autoloaded); "Print labels" links via `PrintAdmin::setup_url()`, 300 items each.
- `CodesExport`: one row per code (active/retired/all) plus optionally the items without a code; columns item ID, parent ID, type, SKU, product, attributes (WooCommerce's stored summary in `post_excerpt`, else built from the variation), product status, code, code status, scan URL (active codes only, `ScanUrl::for_code()`), created/retired (site timezone); filters; BOM; `SalesExport::put()` neutralisation; no cost; streamed.
- `CsvUpload`: 1 MB, 5,000 rows, 20 columns, 1,000 characters per cell, `.csv` + finfo text MIME, no NUL; UTF-8 (BOM stripped) or Windows-1252 (flagged); comma or semicolon; the file is read from PHP's temporary folder after `is_uploaded_file()` and deleted immediately.
- `CostImport`: headers ID/Item ID/Product ID and/or SKU and Cost price; matching by ID, else SKU (case-insensitive like WooCommerce); errors for mismatch, duplicates (every row), unknown (digits-only SKU gets the Excel leading-zero hint), trashed, grouped/external, orphan variations; variable parent = default for variations; values: empty = no change, `clear` = remove, numbers with ₹/Rs/Rs./INR before or after and `/-`, Western or Indian grouping, ≤ 2 decimals never rounded; then `CostPrice::normalize()` and `CostPrice::set()` only. Preview stored compressed in the uploader's user meta `pqbg_cost_import` (token, 1-hour expiry; kept under MariaDB's 1 MB packet limit). Apply row by row in chunks of 500 with "changed since preview" / "already" checks (resumable, idempotent); errors need an acknowledgement. Report CSV; template CSV with current costs.
- `BulkLog`: `pqbg_bulk_log` (not autoloaded), last 200 entries (time, user, tool, counts, file name + SHA-256; never a cost), shown as Recent bulk runs; cost entries only for `pqbg_view_costs`; one WooCommerce log line each; the log is re-read before each write.
- `ToolsAdmin`: tabs, page load gate (`load-` hook, 403/404, pruning), six handlers (`pqbg_bulk_generate`, `pqbg_codes_csv`, `pqbg_cost_upload`, `pqbg_cost_apply`, `pqbg_cost_report`, `pqbg_cost_template`), each method → capability → nonce; `admin_enqueue_scripts` for `assets/pqbg-tools.css/js`. `SettingsPage`: menu capability `pqbg_manage_codes`, tab navigation, Settings tab unchanged (options.php still `pqbg_manage_settings`).
- `uninstall.php`: always removes the previews and `pqbg_bulk_run`; `pqbg_bulk_log` only with delete-all.
- Hooks: admin requests only; no REST/AJAX/nopriv/shortcode; no product, meta or sale hooks; the sale path is untouched.

**Files created** (plugin-relative): `includes/BulkGenerator.php`, `BulkLog.php`, `CodesExport.php`, `CsvUpload.php`, `CostImport.php`, `ToolsAdmin.php`; `assets/pqbg-tools.css`, `assets/pqbg-tools.js`; `tests/phase10-bulk.php`.

**Files modified:** `includes/ProductCodeService.php` (`&$created`), `SettingsPage.php` (tabs, capability), `Plugin.php` (wiring, after the class files existed), `CostPrice.php` (docblock only), `uninstall.php`; `tests/run.php`, `tests/phase3-codes.php`, `tests/phase4-rendering.php`, `tests/phase9a-sales-history.php` (D16), `tests/README.md`; `README.md` (plugin); `progress.md`.

**False by design (D16, listed in tests/README):** Phase 4 "shop manager: direct URL refused (403)" → the page opens on Code tools with no settings form and `&tab=settings` is 403; Phase 4 "shop manager: … no settings item" → the QR & Barcodes item is present (still no warning); Phase 9A "shop manager cannot open the settings page" → `&tab=settings` 403 and no payment-method fields; Phase 3 options list also allows `pqbg_bulk_log` / `pqbg_bulk_run`.

**Development incidents:**
- **The first Phase 10 run was stopped by Claude Code for low system memory** (793 MB free of 8 GB, with Chrome, VS Code and Edge open; the machine had probably slept overnight during the run). It was not a test failure and there was no Apache crash (139). It left that run's test data (1,377 products/variations with IDs 17995–19372, 71 codes, 5 `pqbg10_` users, 7 costs, the log option, one render-cache entry, 1,377 completed lookup jobs); with the user's approval it was removed by a guarded script (refusing anything outside those IDs; 2,753 Action Scheduler jobs removed) and the clean state confirmed. The user closed Chrome/Edge and turned sleep off; the runner now has a **memory watchdog** (below 500 MB it creates `PQBG_STOP_FILE`, and the Phase 10 suite stops and cleans up instead of being killed).
- Found and fixed from the runs: `BulkGenerator::state()` now also clears WordPress's cached "option does not exist" (a process could miss a run another request created; new test with worker processes); `BulkLog::add()` re-reads the log before writing (new test: an entry written meanwhile by another process is kept); the codes export reads WooCommerce's stored attribute summary instead of loading every variation (4.1 s → 0.65 s for 2,070 rows); run-id/token parsing keeps the case (`sanitize_key()` would lowercase them). Test-side fixes: the print-link checks (now the exact item IDs in the setup screen's hidden field), the suite's object cache is flushed after every HTTP request, cleanup of render-cache entries the suite creates.

**Tests** (`PQBG_TESTS_ALLOW_PRODUCTION=1`, tools from `C:\xampp\tools\pqbg\`: `PQBG_DECODER=C:\xampp\tools\pqbg\decoder\decode.mjs`, `PQBG_PRINTCHECK=C:\xampp\tools\pqbg\print-check\check.mjs`, `PQBG_WXR_IMPORTER=C:\xampp\tools\pqbg\wordpress-importer\wordpress-importer.php`), 2026-09-27, suite by suite, free memory 3.0–3.5 GB (minimum 2,651 MB):

| Suite | Result | Crashes before → after |
|---|---|---|
| phase10-bulk (on its own first) | 197/197, 0 skipped, AS guard PASS | 139 → 139 |
| phase2-main | 83/83 | 139 → 139 |
| phase2-lifecycle | 17/17 | 139 → 139 |
| phase2-no-woocommerce | 12/12 | 139 → 139 |
| phase3-codes | 110/110 | 139 → 139 |
| phase4-rendering | 165/165 | 139 → 139 |
| phase5-admin | 158/158 | 139 → 139 |
| phase6-scan | 214/214 | 139 → 139 |
| phase7-sales | 213/213 | 139 → 139 |
| phase8-printing | 167/167 | 139 → 139 |
| phase9a-sales-history | 253/253 | 139 → 139 |
| phase9b-reports | 148/148 | 139 → 139 |
| **Total** | **1,737 passed, 0 failed, 0 skipped; AS guard PASS for every suite; no suite skipped its decoder/print checks** | |

**Volume timings** (2,000 items: 500 simple + 150 variable × 10 variations, in-process): counts 49 ms (target < 1 s); worst batch of 100 0.66 s (< 5 s); all 2,000 codes 11.7 s (< 60 s); codes export 2,070 rows 0.65 s, 1.2 s over HTTP (< 5 s); cost preview 2,000 rows 0.20 s, 20 KB stored (< 5 s); cost apply 2,000 rows 6.7 s (< 20 s); PHP peak memory of the volume part 107 MB (< 256 MB). Building the 2,150 products took 232 s (not timed against a target).

**Access evidence (HTTP):** shop manager: QR & Barcodes opens on Code tools only (no Settings/cost tab, no link, no cost wording or value, no cost log entries), `&tab=settings` and `&tab=costs` 403, every cost handler 403 even with the administrator's nonce and token, the template 403 with no cost in the body, may generate codes and download the codes CSV (no cost); seller and customer: 403 on the page and every handler, no menu item; logged out: login redirect / no handler; a second administrator cannot use the first one's import (nonce bound to the user; with their own nonce the token is refused).

**Known limitations:**
- BulkLog::add() re-reads before writing; two log writes at the same instant could still lose one entry. Acceptable for an activity log; revisit in hardening if needed.
- Code CSV import is not implemented (D6a, backlog); there is no undo of a cost import (the downloaded report keeps the old values).
- Items saved during a generation run with an ID below its cursor are picked up by the next run; a batch that dies midway keeps its committed codes but they are not in that run's counts.
- The codes export and the cost template read current product data (names, SKUs, statuses).

**Manual checks:** not asked for in this phase; added to the **Pre-launch acceptance checklist** with the steps in the plugin README ("Phase 10 checklist").

**Final report:** `C:\xampp\backups\sharayu\phase10-report.txt`.

### Phase 10B: Menu restructure and plugin Dashboard (2026-09-27)

**Status:** done; approved on the automated tests, committed as `0145aff` (together with the record of `5b746a7`) and pushed to `origin/main`. _Originally written before approval as:_ implemented and tested; NOT committed, waiting for the user's review. No schema, capability, option or stored-data change (`DB_VERSION` 4); the sale path is untouched.

**Start state:** `HEAD` = `origin/main` = `5b746a7`, clean. Roadmap: the MENU RESTRUCTURE open item became Phase 10B (owner's decision: Option 1, the plugin's own top-level menu).

**Backups** (`C:\xampp\backups\sharayu\`, outside the web root, never committed): `sharayu-20260927-120429-before-phase10b.sql` (950,829 bytes, 53 tables, complete; before the baseline); `sharayu-20260927-134249-before-phase10b-tests.sql` (953,748 bytes; before the first final run); `sharayu-20260927-152746-before-phase10b-final.sql` (954,764 bytes; after the approved cleanup, before the final runs). A first attempt at the first backup produced a 0-byte file (php not on the Bash PATH); it was deleted.

**Baseline** (suite by suite, free memory 1,811 MB at the start): 1,737 passed, 0 failed, 0 skipped, AS guard PASS; crash count 139 throughout. The first phase10-bulk run was stopped by the memory watchdog (491 MB free; Chrome had been reopened) at the start of its volume part, cleaned up completely and not counted; re-run on its own: 197/197.

**Approved plan:** D1–D15 as recommended (`C:\xampp\backups\sharayu\phase10b-plan.txt`), with seven changes: (1) In-store reports' "Dashboard" tab renamed **Summary** (`&tab=dashboard` still opens it); (2) Print setup and Regenerate stay where they are if they are hidden screens (**they were**: registered under Products and removed from the menu in `admin_head`; confirmed over HTTP); (3) no hard-coded screen IDs: pages recognised by the stored hook suffix, with a scope check; (4) the Dashboard's figure is labelled "Published products without a code" and links to Bulk tools → Code tools, which also counts drafts and other statuses (explained in the README); (5) `AdminUrl` also for the admin-post handlers' redirect targets; (6) a shared tab row (Dashboard | In-store sales | In-store reports | Bulk tools | Settings) at the top of every plugin page, per capability, page-internal tabs as a second row; (7) the 50,000-sale stress data opt-in (`PQBG_STRESS=1`) in the 9B and 10B suites, run once for the Dashboard in this phase. **Accepted afterwards:** a shop manager opening `admin.php?page=pqbg-settings` is redirected to Bulk tools (as in Phase 10); `&tab=settings` stays 403.

**Design:**
- `AdminMenu`: `add_menu_page` "QR & Barcodes" (slug `pqbg-dashboard`, `pqbg_view_all_sales`, `dashicons-grid-view`, position `55.7` → directly below Products) and five `add_submenu_page` calls in one `admin_menu` callback; stores each returned hook suffix (`is_page()`, `page_of()`), attaches each page's `load-` callback, enqueues `assets/pqbg-menu.css` on plugin pages, prints the shared tab row (`render_nav()`, one tab per page the user may open), and redirects the old `pqbg-settings` addresses on `admin_menu` at `PHP_INT_MAX` (before WordPress's page-access check in `wp-admin/menu.php`; GET/HEAD; only when the user may open the target; 302; every other query argument kept). The page classes lost their own `add_menu()` and the "find Orders / In-store sales" position code.
- `AdminUrl`: the only class that calls `admin_url()` and names the page slugs (constants; `SalesAdmin::SLUG` etc. are aliases). Page URLs (`dashboard()`, `sales()`, `sale()`, `sale_void()`, `reports()`, `bulk_tools()`, `settings()`, `print_setup()`, `regenerate_confirm()`), `admin_post()` (form actions and GET links; handlers still add their own action and nonce), `admin_php()`, `options()`, `products()`, `product_edit()`, `is_admin_home()` (ScanRoute's login comparison). Removed: `SalesAdmin::list_url/detail_url/void_url`, `ReportsAdmin::url`, `ToolsAdmin::url`, `PrintAdmin::setup_url`, `AdminProductPanel::confirm_url`.
- Slugs kept: `pqbg-sales`, `pqbg-reports`, `pqbg-settings` (their URLs did not change); new: `pqbg-dashboard`, `pqbg-bulk-tools`. `ToolsAdmin` is the Bulk tools page (tabs `tools`, `costs`; `TAB_SETTINGS` kept for the redirect map); `SettingsPage` renders only Settings (load: 403 without `pqbg_manage_settings`, 404 for any leftover tab, `CostImport::prune()`).
- `DashboardAdmin`: needs attention (local/http scan URL for `pqbg_manage_codes` users with the Settings link only for administrators; permalinks; `/scan/` conflicts; a run in progress / interrupted / stopped), today in the shop (cards, per payment method, voided today; profit/margin/unknown-cost note only for `pqbg_view_costs`), products and codes, recent bulk runs (5), setup, quick links. Every figure from `ReportsAdmin::dashboard_data( ReportPeriod::resolve( 'today' ), $costs )` (the Summary's own function), `BulkLog::visible()`, `BulkGenerator::state()`, `Settings`/`ScanUrl`/`ScanRoute`/`PaymentMethods`. `assets/pqbg-dashboard.css` (plus `pqbg-reports.css` cards) on the Dashboard only.
- Accessibility: one h1 per page; a labelled `nav` for the tab row (`aria-current="page"`); Dashboard sections labelled by their h2; figures as description lists; a captioned payment table; no outline removal; below 782 px the tabs wrap (40 px tap height), the Dashboard is one column and the quick links full-width 44 px buttons.

**Files created** (plugin-relative): `includes/AdminUrl.php`, `includes/AdminMenu.php`, `includes/DashboardAdmin.php`, `assets/pqbg-menu.css`, `assets/pqbg-dashboard.css`, `tests/phase10b-menu.php`. Class files were created before `Plugin.php` referenced them.

**Files modified:** `includes/Plugin.php` (wiring, last), `SettingsPage.php`, `ToolsAdmin.php`, `SalesAdmin.php`, `ReportsAdmin.php` (Summary), `ReportsExport.php`, `ReportPrint.php`, `ReportData.php`, `ReportTable.php`, `SalesListTable.php`, `SalesExport.php`, `PrintAdmin.php`, `PrintPage.php`, `AdminProductPanel.php`, `AdminActions.php`, `ScanRoute.php` (login comparison only); `assets/pqbg-reports.css` (comment); `README.md` (plugin: Admin menu, Old addresses, Dashboard, every menu path, Phase 10B checklist); `tests/README.md`, `tests/run.php`, `tests/phase2-no-woocommerce.php`, `phase4-rendering.php`, `phase9a-sales-history.php`, `phase9b-reports.php`, `phase10-bulk.php`; `progress.md`.

**False by design (listed with reasons in tests/README.md):** Phase 4 (menu item location; the shop manager's old address → 302 to Bulk tools; the shop manager's menu; the hooks check also covers `AdminMenu`); Phase 9A (menu position: QR & Barcodes → In-store sales after Dashboard, not under WooCommerce); Phase 9B (tab `summary` instead of `dashboard`; the hooks scope check: 3 actions, the menu and `load-` in `AdminMenu`; the 50,000-sale checks opt-in and a new 5,000-sale HTTP check at the original targets; 146 checks, 149 with `PQBG_STRESS=1`); Phase 10 (`$tab_url` from `AdminUrl`; `AdminUrl::print_setup()`; `ToolsAdmin::tabs()` without Settings; Bulk tools / Settings split; the shop manager's menu; `&tab=settings` 403 and the plain Settings address 302; the `load-` hook in `AdminMenu`); Phase 2 no-WooCommerce (the menu not registered). Two of these were first missed and failed in the final run (Phase 10's in-process tab list and the shop manager's Settings address), and one Phase 9A menu check I had edited was wrong (`array_search` found the top-level Dashboard link first); all three were corrected in the tests only and re-run.

**Development incidents:**
- Dev run 1 of the 10B suite: 4 wrong expectations in the new suite (single-quoted menu markup, the D8 redirect for a shop manager's Settings address, and a CSV nonce created in-process for user 0); fixed in the suite; dev run 2: 91/91.
- **The first final run was killed by Claude Code for low system memory** about a minute into the 10B suite (Edge had restarted in the background; about 0.9 GB free). No crash (139). Its leftovers (4 users 1031–1034, posts 32666–32668 with meta, 6 fixture sales rows, 1 code row, the render-cache index and one transient pair, 2 lookup jobs and logs) were listed read-only and, **with the user's approval**, removed by a guarded script that verified every item first; deleting the two products queued two more lookup jobs for them (65989, 65990), which the script removed after checking their hook and IDs. The sales and codes tables were empty, so their AUTO_INCREMENT was reset to 1. Clean state confirmed (0 products, 0 sales, 0 codes, 1 user, only `pqbg_db_version`, `pqbg_rewrite_version`, `pqbg_settings`).

**Tests** (`PQBG_TESTS_ALLOW_PRODUCTION=1`, `PQBG_DECODER`, `PQBG_PRINTCHECK` and `PQBG_WXR_IMPORTER` pointing at the files in `C:\xampp\tools\pqbg\`), 2026-09-27, one suite at a time with the 500 MB watchdog; free memory 3,112 MB before the final runs (Chrome/Edge closed; Edge background processes about 180 MB), lowest 2,552 MB:

| Suite | Result | Crashes before → after |
|---|---|---|
| phase10b-menu (on its own first) | 91/91, AS guard PASS | 139 → 139 |
| phase2-main | 83/83 | 139 → 139 |
| phase2-lifecycle | 17/17 | 139 → 139 |
| phase2-no-woocommerce | 12/12 | 139 → 139 |
| phase3-codes | 110/110 | 139 → 139 |
| phase4-rendering | 165/165 | 139 → 139 |
| phase5-admin | 158/158 | 139 → 139 |
| phase6-scan | 214/214 | 139 → 139 |
| phase7-sales | 213/213 | 139 → 139 |
| phase8-printing | 167/167 | 139 → 139 |
| phase9a-sales-history | 252/253 (a wrong check of mine) → re-run 253/253 | 139 → 139 |
| phase9b-reports | 146/146 | 139 → 139 |
| phase10-bulk | 195/197 (two missed false-by-design checks) → re-run 197/197 | 139 → 139 |
| **Total** | **1,826 passed, 0 failed, 0 skipped; AS guard PASS for every suite; no suite skipped its decoder, print or importer checks** | |
| phase10b-menu with `PQBG_STRESS=1` (one-off) | 93/93 | 139 → 139 |

**Dashboard timings** (1,000 sellable items; in-process best of 3; HTTP median of 3 minus an empty wp-admin page): 5,000 sales in 90 days: 99.9–106 ms in-process, +4 to +79 ms over HTTP (dev runs up to +324 ms with the browsers open), target under 1 s: met. **50,000 sales in 90 days (one-off): 148 ms in-process, +221 ms over HTTP (690.8 ms vs 469.4 ms)**, target under 2 s: met. Phase 10 volume timings in the final re-run: counts 62 ms, worst batch 0.89 s, all 2,000 codes 13.2 s, export 0.64 s / 1.2 s HTTP, preview 0.29 s, apply 7.1 s, peak 107 MB (the first final run, on a busier machine, measured apply 18.7 s against its 20 s target).

**Access evidence (HTTP):** administrator: the five sub-items and five tabs; shop manager: Dashboard, In-store sales, In-store reports, Bulk tools (Code tools only), no Settings anywhere, no cost/profit/margin on the Dashboard, 403 on `&tab=settings`, unknown tabs, Import cost prices and the 4 cost handlers; Store Seller and customer: no plugin menu, 403 on every plugin page and on all 15 admin-post handlers, never redirected to a plugin page; logged out: the login page with the old address as the destination.

**Known limitations:**
- Screen IDs changed (`woocommerce_page_pqbg-*` → `toplevel_page_pqbg-dashboard` / `qr-barcodes_page_pqbg-*`): a user's Screen Options hidden-column choice on In-store sales and report tables resets once; not migrated (no stored-data change).
- `ReportsAdmin::dashboard_data()` for "today" also computes the comparison and top-5 lists the Dashboard does not show (cheap for one day; measured above).
- A shop manager asking for `admin.php?page=pqbg-settings` gets the redirect to Bulk tools rather than 403 (accepted; `&tab=settings` stays 403).

**Manual checks:** not asked for in this phase; added to the Pre-launch acceptance checklist ("Phase 10B manual checks") with the steps in the plugin README ("Phase 10B checklist").

**Final report:** `C:\xampp\backups\sharayu\phase10b-report.txt`.
### Phase 11: Hardening (2026-09-27)

**Status:** implemented and tested; approved on the automated tests, **committed as `32a8b90` and pushed** (recorded 2026-09-28). No schema change (`DB_VERSION` 4), no new capability, the sale path unchanged (`SaleService`, `SaleRepository`, `SaleRequest`, `StockLock` and `Schema` byte-identical to `0145aff`; a scope check in the new suite). One new non-autoloaded runtime option, `pqbg_perf_samples` (the owner's change 2).

**Start state:** `HEAD` = `origin/main` = `0145aff`, clean. Housekeeping: `0145aff` recorded here. Plan (D1–D22): `C:\xampp\backups\sharayu\phase11-plan.txt`.

**Backups** (`C:\xampp\backups\sharayu\`, outside the web root, never committed): `sharayu-20260927-165517-before-phase11.sql` (961,712 bytes, before the baseline), `sharayu-20260927-184304-before-phase11-tests.sql` (967,061 bytes, before the first test run), `sharayu-20260927-192134-before-phase11-final.sql` (967,071 bytes, before the final runs), `sharayu-20260927-232450-before-phase11-stress-cleanup.sql` (2,154,471 bytes, before the approved cleanup below). All 53 tables, "Dump completed".

**Baseline:** 1,826 passed, 0 failed, 0 skipped (suite by suite; the watchdog tripped in phase 8 at 430 MB, which still passed; the user closed the browsers for 9A–10B). Crash count 139 throughout.

**Approved plan:** D1–D22 as recommended, with six changes by the owner: (1) D7: no reload; hide the Undo button with a CSS animation whose delay is set server-side (no JavaScript); (2) D9: warn only on a repeated condition (3 of the last 10 renders over 2 s, or more than 300 sales a day over 30 days), storing the minimum; (3) D13: the temporary must-use logger lives only for the test runs, logs outside the web root, removed afterwards; (4) every suite honours the stop file, before the stress run, which needs 2.5 GB free; (5) nothing may change the sale path, the schema or stored data, or add features, without asking; (6) PHPCS: fix only the 3 translator comments; the Phase 13 note. **Asked during implementation:** a late Undo is refused with 409 (not 403); the owner kept 409.

**Implemented:**
- **Health check** (D1–D5): `HealthCheck` (9 read-only checks: schema; negative stock on coded items and their stock holders; completed sales without `stock_after`; pending sales older than 15 minutes; codes on missing/unsuitable items; the one-active-code invariant; invalid cost meta via the new read-only `CostPrice::invalid_values()`; information: codes on trashed items, sales of deleted items) and `HealthCheckAdmin` (Settings → Health check, `&tab=health`, second-row tabs Settings | Health check; `SettingsPage::tab()`; `AdminUrl::health()`). Report only. Dashboard: administrators get "The health check found N problems" (errors and warnings; `DashboardAdmin::admin_attention()`). Speed: product types read in one grouped query; the tables checked with two `SELECT 1 … LIMIT 0` (not `SHOW TABLES`); the completed-sales check ignores `status_created` (a sequential scan, about 4× faster at 50,000 rows than an index lookup per completed row).
- **Undo expiry** (D7, owner's version): the sale page with an Undo form carries one `<style nonce>` with `animation-delay:{seconds left}s`; `pqbg-scan.css` has the zero-length animation (`animation-fill-mode: forwards`, fallback delay 600 s) that hides the form and shows "Undo is no longer available. Ask a manager to void the sale if needed."; `ScanRoute::csp()` adds `'nonce-…'` to `style-src` for that response only (`ScanScreen::style_nonce()`, 18 random bytes). No JavaScript, no reload; a late Undo is still refused (409).
- **BulkLog lock** (D8): `BulkLog::add()` re-reads and writes under `GET_LOCK` (`BulkLog::lock_name()`, 5 s; without it, writes anyway and logs a WooCommerce warning).
- **Performance signal** (D9, owner's version): `PerfSignal` (the last 10 Dashboard compute times in `pqbg_perf_samples`, written at most once per 60 s after the review; `evaluate()`, `message()`, `sales_per_day()`), shown to administrators on the Dashboard.
- **Security fixes** (D11): F1 `SalesExport::formula_risk()` (also after spaces, LF, full-width `＝＋－＠`), used by `neutralise()` and by `CsvUpload::unwrap()` (round trip); F2 uninstall removes the `pqbg_save_failure_*` transients (and `pqbg_perf_samples`). F4 needed no change: the invalid-code page already shows at most 100 characters (I had missed that in the plan). No High or Medium finding.
- **Requirements and multisite** (D18, D19): `Requirements::errors_for( $php, $wp, $wc, $multisite )`; multisite refused (network and per-site); `Install::activate()` lost its network-only branch.
- **PHPCS** (D12, owner's change 6): the 3 translator comments in `CostPrice` only; the `SettingsPage::tab()` annotation fixed.
- **Tests:** `tests/phase11-hardening.php` (130 checks); `tests/bootstrap.php` (`pqbg_test_stop_point()`, `PqbgTestStop`, STOPPED handling); `tests/run.php` (STOPPED, the error-capture summary, the new suite); every suite honours the stop file (phase2-main wrapped in `try`/`finally`; stop points in the long loops); Phase 2 lifecycle +5 (D16); Phase 7 +3 (D6: minimum stock observed 0 in 220–244 samples in every run; parent-level snapshots).

**False by design** (listed with reasons in `tests/README.md`): Phase 3 (the options list allows `pqbg_perf_samples`); Phase 7 (the sale page's CSP allows its own style nonce; the sales-table scope check also allows the read-only `HealthCheck` and `PerfSignal`); Phase 10 (the uninstall source check compares positions: the Phase 11 runtime state is removed before the delete-all guard too); Phase 10B (GET-never-writes leaves out `pqbg_perf_samples`, plus a check that the timing sample is the only write). Phase 5's "no raw writes" rule was **not** loosened: `HealthCheck` was changed to read with `get_var()` instead.

**Final tests** (`PQBG_TESTS_ALLOW_PRODUCTION=1`, tools from `C:\xampp\tools\pqbg\`, the 500 MB watchdog, the error capture), 2026-09-27, one suite at a time, free memory 3.0–3.4 GB:

| Suite | Result | Crashes |
|---|---|---|
| phase11-hardening | 129/129 (final code: 129/129 again, twice; 130/130 after the review's 60-s throttle, with phase10b 92/92 again) | 139 → 139 |
| phase2-main | 83/83 | 139 → 139 |
| phase2-lifecycle | 22/22 | 139 → 139 |
| phase2-no-woocommerce | 12/12 | 139 → 139 |
| phase3-codes | 110/110 | 139 → 139 |
| phase4-rendering | 165/165 (and 165/165 after the last comment fix) | 139 → 139 |
| phase5-admin | 157/158 (the raw-write rule; fixed in `HealthCheck`) → re-run 158/158 | 139 → 139 |
| phase6-scan | 214/214 | 139 → 139 |
| phase7-sales | 215/216 (a false-by-design scope check) → 216/216 twice | 139 → 139 |
| phase8-printing | 167/167 | 139 → 139 |
| phase9a-sales-history | 253/253 | 139 → 139 |
| phase9b-reports | 146/146 | 139 → 139 |
| phase10-bulk | 196/197 (a false-by-design uninstall check) → re-run 197/197 | 139 → 139 |
| phase10b-menu | 92/92 (spanned a sleep, see below) → re-run 92/92 | 139 → 139 |
| **Total (final code)** | **1,965 passed, 0 failed, 0 skipped; AS guard PASS for every suite; no suite skipped its decoder, print or importer checks** | |

**Error capture** (the temporary must-use logger, all final runs): **0 notices, warnings or deprecations from plugin code**, 0 from the tests; 18 "Array to string conversion" warnings in `wp-includes/pluggable.php:2475`, raised by WooCommerce's `WC_Form_Handler::process_login` (it passes the malformed-input sweep's `_wpnonce[]` array to `wp_verify_nonce()`); not plugin code. The logger and the `wp-content/mu-plugins` folder (created for it) were removed afterwards; neither was ever in git.

**50,000-sale stress runs** (`PQBG_STRESS=1`, 3.0 GB free): phase9b 149/149, phase10b 94/94, phase11 129/129; crashes 139. In-process at 50,000 sales in 90 days: Summary 1,035 ms, products 599, slow sellers 582, the rest under 470 ms; 12 months: Summary 822 ms. HTTP (added over an empty wp-admin page of 507 ms): Summary +925 ms, products +641 ms, sellers +338 ms. Dashboard: 219 ms in-process, +360 ms over HTTP. Health check: the tab 180 ms, the Dashboard's set 81 ms (under the 150 ms of D2, so the Dashboard keeps the count), the tab over HTTP 0.78 s. All under the 2 s regression guard.

**Incidents:**
- A parse error in `DashboardAdmin.php` for about a minute during development (a quote-escaping mistake in a scripted edit; only the Dashboard page loads that class). Fixed at once; later edits were linted on a scratch copy first.
- **The machine slept from 19:57:58 to 22:44:03** (Kernel-Power 42 / Power-Troubleshooter 1) during the final phase10b run; it paused and continued (92/92) but was not counted and was re-run. Sleep is "never" on AC but 30 minutes on battery (`powercfg`: DC 0x708).
- **The first stress run was killed by Claude Code for low memory** (the browsers had reopened; 1.48 GB free) during the Phase 9B suite's cleanup. Its measurements finished (the in-process guard passed; one HTTP guard failed under the memory pressure and is not counted). Leftovers: 844 products/variations (IDs 74724–75814), 1,017 codes, 5 `pqbg9b_` users, 14 PQBG9B categories, 4 cost meta rows, 252 lookup jobs. **With the user's approval**, removed by a guarded script (dry run first; it refused until the code range was widened to 75824 with a creation-time window, because the killed cleanup had already deleted items 75548–75824); 1,096 lookup jobs removed (the 252 plus 844 queued by the deletions). Clean state confirmed; the stress runs were then repeated and passed.

**Verified outside the suites:** the stop file on phase2-main (at its first section), phase6-scan (after 40 s, no catch block) and phase7-sales (after 60 s, with a catch block): each reported STOPPED, ran its cleanup and left the site clean. PHP 8.2.34 (official NTS build, SHA-256 verified, in `C:\xampp\tools\pqbg\php82\`): `php -l` on all 251 plugin PHP files, 0 errors.

**PHPCS** (final): 280 errors, 311 warnings (591) in 66 files; 0 PHP 8.2+ compatibility findings, 0 i18n findings, 0 nonce findings; the security-sniff reports are the reviewed false positives (plan, section F). Coding-style cleanup: Phase 13, only if the plugin is ever published on WordPress.org.

**Known limitations:** the health check is report-only (no repairs, by decision); if a browser pauses animations while the scan page is in the background, the Undo button can reappear briefly after the window (pressing it is refused); in DST timezones the autumn day's hourly report has one 2-hour bucket for the repeated hour (no sale is lost); multisite is not supported; the roll-up table / result cache is still deferred (now with the signal).

**Manual checks:** added to the Pre-launch acceptance checklist ("Phase 11 manual checks") with the steps in the plugin README ("Phase 11 checklist").

**Final report:** `C:\xampp\backups\sharayu\phase11-report.txt`.

### Phase 12: Theme compatibility (2026-09-28)

**Status:** implemented and tested; approved on the automated tests, **committed as `ba18693` (D12) and `1b583f9` (Phase 12) and pushed** (2026-09-28). Two commits, as intended: D12 (author and shop name) first, then Phase 12 (the D12 change alone is saved as `C:\xampp\backups\sharayu\phase12-d12-author.patch`). No schema change (`DB_VERSION` 4), no new capability or option, the sale path unchanged (`SaleService`, `SaleRepository`, `SaleRequest`, `StockLock`, `Schema` untouched).

**Start state:** `HEAD` = `origin/main` = `32a8b90`, clean. Recorded `32a8b90`. Theme state saved before anything else: `C:\xampp\backups\sharayu\phase12-theme-snapshot-before.json`. Plan (D1–D11): `C:\xampp\backups\sharayu\phase12-plan.txt`.

**Approved plan:** D1–D11 as recommended, Twenty Twenty-Four included; plus the owner's F (screenshots for the owner), G (fixes with reasons; what to leave out), H (the suite always restores the theme and settings, verified by a guard; README, tests/README, progress.md, a pre-launch item); plus: Plain permalinks break every printed label, so also a Health check error and a Dashboard warning, documented. And **D12** (the owner's request, a separate earlier commit): author Mosin Shaikh, the shop name removed from the plugin.

**D12 (author and shop name), every change:**
- `product-qrcode-barcode-generator.php`: `Author: Mosin Shaikh`, new `Author URI: https://www.linkedin.com/in/mosin-shaikh01s/`; the Description no longer names the shop ("for a WooCommerce shop's own staff"). There was no `Plugin URI`, so none was removed or added.
- `README.md` (plugin): the first line no longer names the shop, plus an author/licence line; the rename section describes the former name as "an earlier working name (identifier prefix `dpc_` / `DPC_`)" instead of naming it, the display-name/folder/text-domain/namespace rows merged into one row without the old names, "DC is the store brand, Durga Collections" became "the `DC-` prefix is part of the permanent code format", and the "`Author: Durga Collections` … not renamed" line was removed.
- `tests/phase4-rendering.php`: one test base URL `https://durgacollections.example/store` → `https://store.example.net/shop` (a fixture value, not functional).
- Not changed (functional or not the shop name): the `DC-` code prefix and the code format; option, meta, table, capability and role names; the text domain; the `dpc_`/`DPC_` identifiers named in the README's rename section (they are the old identifiers, not the shop name). There is no migration code for old `dpc_` data. The third-party licence files in `vendor-prefixed/` keep their own copyright holders. `progress.md` history and git history untouched.
- A case-insensitive search for "durga" in the plugin folder (every file, including ignored `node_modules`/build folders) finds **nothing**. Verified on its own: phase2-main 83/83, phase4 165/165, crash count 139.

**Implemented (Phase 12):**
- **Standalone scan page kept** (D9): the scan pages already use no theme, `wp_head()` or script; nothing on the store pages or emails.
- **F1, a logged-out page-cache poisoning (found and confirmed, then fixed):** WP Fastest Cache stores any logged-out, non-POST HTML response and ignores `no-store`; one logged-out `PUT` to `/scan/` or `/scan/{CODE}/` stored the 405 page, which was then served (200) to every logged-out visitor instead of the login redirect. `ScanRoute::decide()` now answers every logged-out request (any method) with the 302 to the login page, before the method check. Logged-in users still get 405.
- **F2:** `ScanRoute::no_page_cache()` defines `DONOTCACHEPAGE`, `DONOTMINIFY` and `DONOTCDN` on every scan response (`NO_CACHE_CONSTANTS`), besides the unchanged no-store headers.
- **Permalinks (F3 and the owner's addition):** the "pretty permalinks needed" notice also goes to users with `pqbg_manage_codes` (shop managers print labels; the slug-conflict notice stays administrators-only) and says every printed label opens an error page; a new Health check **error** `permalinks` (reason `plain` or `index_php`, counted on the Dashboard for administrators); the Dashboard warning says what breaks and links to Settings → Permalinks for users with `manage_options` (`AdminUrl::permalinks()`). No fallback URL (the label format is permanent); documented.
- **Recommended leaving out:** a theme-wrapped scan page (would bring theme JS/CSS under the CSP, admin bars and caches); a `?pqbg_code=` fallback for Plain permalinks (printed labels are permanent); cache-plugin-specific hooks (e.g. LiteSpeed's) beyond the common constants; WP Super Cache / LiteSpeed tests (WPSC writes `wp-config.php`; LiteSpeed needs a LiteSpeed server).
- **Tests:** `tests/phase12-themes.php` (new, in `run.php`), `tests/theme-check/` (puppeteer-core tool; installed in `C:\xampp\tools\pqbg\theme-check\`), `tests/phase12-repair.php` (repairs a killed run from the saved start state; dry run by default). False by design (listed in `tests/README.md`): Phase 6 (the permalink notice for shop managers), Phase 11 (ten Health checks).
- **Tools** (outside the web root, never committed): theme zips in `C:\xampp\tools\pqbg\themes\` (Storefront 4.6.2, Astra 4.14.0, Kadence 1.5.2, OceanWP 4.2.6; SHA-256 in `SHA256SUMS.txt`), `C:\xampp\tools\pqbg\cache-plugin\wp-fastest-cache.1.5.2.zip`. The themes and the cache plugin exist in the site only while the suite runs.

**Backups** (`C:\xampp\backups\sharayu\`, outside the web root, never committed; all 53 tables, "Dump completed"): `sharayu-20260928-001017-before-phase12.sql` (973,223 bytes, before the baseline), `…-005958-before-phase12-d12-tests.sql` (978,770), `…-012101-before-phase12-tests.sql` (988,177), `…-101505-before-phase12-dev2.sql` (1,003,563), `…-103342-before-phase12-final.sql` (993,613), `…-112522-before-phase12-p11-rerun.sql` (1,013,236). Also `phase12-htaccess-before.txt` (`.htaccess` before the cache experiment; identical to the current file).

**Baseline:** 1,965 passed, 0 failed, 0 skipped (suite by suite; the watchdog never tripped, lowest 932 MB; 0 plugin notices; crash count 139 throughout).

**Final tests** (2026-09-28, `PQBG_TESTS_ALLOW_PRODUCTION=1`, every tool from `C:\xampp\tools\pqbg\`, the 500 MB watchdog (never tripped; lowest 2,277 MB), the error capture, on mains power; one suite at a time, free memory 2.9–3.3 GB):

| Suite | Result | Crashes |
|---|---|---|
| phase12-themes | 274/274 (945 s) | 139 → 139 |
| phase2-main | 83/83 | 139 → 139 |
| phase2-lifecycle | 22/22 | 139 → 139 |
| phase2-no-woocommerce | 12/12 | 139 → 139 |
| phase3-codes | 110/110 | 139 → 139 |
| phase4-rendering | 165/165 | 139 → 139 |
| phase5-admin | 158/158 | 139 → 139 |
| phase6-scan | 214/214 | 139 → 139 |
| phase7-sales | 216/216 | 139 → 139 |
| phase8-printing | 167/167 | 139 → 139 |
| phase9a-sales-history | 253/253 | 139 → 139 |
| phase9b-reports | 146/146 | 139 → 139 |
| phase10-bulk | 197/197 | 139 → 139 |
| phase10b-menu | 92/92 | 139 → 139 |
| phase11-hardening | 129/130 (a false-by-design section count I had missed: 9 → 10 Health check sections; fixed and listed) → re-run 130/130 | 139 → 139 |
| **Total** | **2,239 passed, 0 failed, 0 skipped; AS guard PASS for every suite; 0 notices from plugin code** (6 known WooCommerce `process_login` warnings in phase11, as in Phase 11) | |

**After the final run:** PHPCS on the five changed plugin files found one new sniff (a `define()` with a variable name in `ScanRoute::no_page_cache()`, deliberately the unprefixed constants cache plugins check); annotated with `phpcs:ignore` and a reason (a comment only), after which the files have the same 48 findings as at `32a8b90` (all style or reviewed database sniffs; none from the security, i18n or PHP-compatibility sniffs). Backup `…-113025-before-phase12-comment-rerun.sql` (1,013,237 bytes); phase5-admin 158/158 and phase6-scan 214/214 again (they read `ScanRoute.php`'s source); crash count 139.

**Phase 12 results in brief:** under all six themes, every scan screen passed every HTTP and browser check in Edge and Chrome; computed styles identical to Twenty Twenty-Five's; screenshots identical (10 of them within the 0.01% anti-aliasing tolerance, 17–33 pixels); the store pages load no plugin asset or markup and the plugin adds no browser error; admin pages and the menu position hold; no plugin PHP notice. Permalinks: four pretty structures work, Plain and `index.php` give the notice (administrator and shop manager), the Dashboard warning and the Health check error. WP Fastest Cache: no leak between users or to logged-out visitors, no cache file for `/scan/`, logged-out PUT/DELETE/OPTIONS no longer poison it. Restore guard: every option, post, file, folder, `.htaccess` identical to the start; `wp-config.php` never changed. 156 screenshots in `C:\xampp\backups\sharayu\phase12-screens\` (6.9 MB). Seen but not the plugin's: WooCommerce's block and classic cart/checkout request `…/undefinedwc/store/v1/cart` (404) in the browser on this site, with or without the plugin.

**Incidents:**
- **The machine slept from 01:24:40 to 09:54:07** (Kernel-Power 42, "Button or Lid") during the first development run of the Phase 12 suite; that run was not counted. It left three options (`pqbg_perf_samples`, `bsf_usage_migrated`, `wc_blocks_use_blockified_product_grid_block_as_template`), none present before; listed, then removed. The suite's cleanup now removes them (reasons in the code).
- WooCommerce logged three fatal errors in `wc-logs/fatal-errors-2026-09-27-….log` from a scratch environment probe of mine (an undefined `get_filesystem_method()` in a CLI script outside the site), not from the site or the plugin.
- The F1 experiment and the theme-switch prototype were restored exactly (verified by fingerprint: only cron, transients and Action Scheduler locks differ).

**Manual checks:** added to the Pre-launch acceptance checklist ("Phase 12 manual checks") with the steps in the plugin README ("Phase 12 checklist").

**Final report:** `C:\xampp\backups\sharayu\phase12-report.txt`.

### Phase 13: Plugin QA, documentation and packaging (2026-09-28)

**Status:** done 2026-09-28. Approved by the owner; committed as `8670a30` ("Phase 13: release 1.0.0 (licences, guides, packaging, fresh-install test, launch runbook)") plus the packaging fix `7da1fa8`, tagged `pqbg-v1.0.0` (annotated, on `7da1fa8`) and pushed to `origin/main` with the tag (normal pushes). Release zip SHA-256 `a774011ae6479fbf236d582755469176055109e1fc5851d5a6b665eee12eb8df` (1,383,034 bytes, built cleanly from `7da1fa8`); see Repository above.

**Start state:** `HEAD` = `origin/main` = `1b583f9` (after `git fetch`), clean. Recorded `ba18693` and `1b583f9` above, and corrected three places that still said Phase 12 was not committed. Plan: `C:\xampp\backups\sharayu\phase13-plan.txt` (areas A–J).

**Backups** (`C:\xampp\backups\sharayu\`, all 53 tables, "Dump completed"): `sharayu-20260928-115709-before-phase13.sql` (1,011,921 bytes, before the baseline), `sharayu-20260928-125249-before-phase13-stress.sql` (999,689 bytes, before the stress run).

**Baseline** (11:59–12:49, one suite at a time, every tool from `C:\xampp\tools\pqbg\`, the error capture, the 500 MB watchdog (never tripped; lowest 799 MB with the browsers open), mains power): **2,239 passed, 0 failed, 0 skipped; AS guard PASS for every suite; 0 plugin notices** (the 6 known WooCommerce `process_login` warnings in phase11); crash count **139** before and after every suite, httpd PIDs 220 and 14284 throughout. A first attempt stopped after phase2-main (which had passed) because the session's own progress monitor locked the runner's log file; it was not counted and the baseline was re-run from the start.

**50,000-sale stress checks (owner's decision C, before launch): done** (12:53–13:08, `PQBG_STRESS=1`, 3.2 GB free): phase9b 149/149, phase10b 94/94, phase11 130/130; crash count 139. Summary 1,000 ms in-process for 90 days (the slowest report; 12 months 898 ms), +936 ms over HTTP; Dashboard 146 ms in-process, +210 ms over HTTP; Health check tab 171 ms, the Dashboard's set 70 ms. All under the 2 s guard; no regression against Phase 11. Details in the plan.

**Found (baseline):** `pqbg_perf_samples` (runtime timings only) existed before the baseline and was missing after it. Traced to two suites that run the real default uninstall on the site: `phase2-lifecycle` and (found in the final run) `phase8-printing`. Both now restore that runtime state byte for byte (see the test changes below).

**Approved plan:** D1–D17 as recommended, plus the `pqbg_perf_samples` cleanup fix, with three changes: (1) the seller guide stays a self-contained HTML page and is also a PDF (made with the headless browser), with 3–4 phone-width screenshots of sample data (scan screen, sell with quantity and payment, Undo, My sales); the owner guide text-only; (2) ship the licence file of every bundled library and dependency plus the GPL-3.0 text, listed in the report; (3) a Hindi/Marathi seller guide recorded as deferred (owner to decide after launch). No commit, tag or push.

**Implemented (no feature, schema, capability, option or sale-path change; `DB_VERSION` 4; `SaleService`, `SaleRepository`, `SaleRequest`, `StockLock`, `Schema` untouched):**
- **A. Review:** no debug code, TODO/FIXME or commented-out code; every PHP file guarded; silence files everywhere (new `docs/index.php`); no unused private method or constant (four public methods without a runtime caller are a `WP_List_Table` override and three documented test hooks: kept). Harmless cleanups, comments only: four "later phases" comments made timeless (`Permissions`, `CodeRepository`, `Install`, `Plugin`), and translator comments aligned (`CodesExport`, `ReportsAdmin`, the scan template) so make-pot gives no warning. `php -l` on PHP 8.5.6 and 8.2.34: 227 runtime files, 0 errors. PHPCS 592 findings (591 before, plus the new `docs/index.php`'s file-comment style, the same as the other silence files); 0 security findings beyond the 13 reviewed ones, 0 i18n, 0 PHP-compatibility.
- **B. Metadata:** version 1.0.0 (header, `PQBG_VERSION`); a new Description (codes, labels, in-store selling, history, reports, bulk tools; no shop name); `License URI`; `readme.txt` (Tested up to 7.1, Stable tag 1.0.0; no Contributors line, because the wordpress.org username is unknown); `CHANGELOG.md` (1.0.0, Phases 2–12 in plain language, and an upgrade note: one rewrite flush, label images drawn again).
- **C. Licences:** `LICENSE` (GPL-2.0 from gnu.org, SHA-256 `edaef632cbb643e4e7a221717a6c441a4c1a7c918e6e4d56debc3d8739b233f6`); `build/licenses/gpl-3.0.txt` (gnu.org, SHA-256 `3972dc9744f6499f0f9b2dbf76696f2ae7ad8af9b23dde66d6af86c9dfb36986`, pinned in `build/build.php`), copied by the build to `vendor-prefixed/picqer/php-barcode-generator/GPL-3.0.txt`; `NOTICE.md` lists every licence file and the TCPDF origin. `vendor-prefixed/` rebuilt with the pinned tools (both SHA-256 matched): all 135 library files byte-identical, only `NOTICE.md` and the new GPL text changed; `build/vendor/` and `build/tools/` removed afterwards.
- **D. Translation:** WP-CLI 2.12.0 (the latest; SHA-512 matched) in `C:\xampp\tools\pqbg\wp-cli\`; `languages/product-qrcode-barcode-generator.pot`, 811 strings, no personal e-mail. Two messages are joined from separately translated parts and were left as they are (complete sentences, or a prefix): the local-address notice on the print setup screen, and "Variation #%d: " before a cost error.
- **E. Guides:** `docs/owner-guide.md` (14 sections, text only, every button and tab name checked against the .pot); `docs/seller-guide.html` (self-contained, 810 KB, four inlined phone screenshots) and `docs/seller-guide.pdf` (one A4 page, 278 KB), built by the new `tests/guide-screenshots.php` from `tests/guide/seller-guide.template.html` with fictional sample data (seller "Asha", a kurta and a dupatta), removed afterwards (checked; AS guard clean). The screenshots show "Your shop" in the header (set in the headless browser only; the site's title is not in the guide). The PNGs are also in `C:\xampp\backups\sharayu\phase13-guide-shots\`.
- **F. Packaging:** `build/package.php` (see "Release and packaging" in the plugin README). Test build from the working tree (not a commit): `C:\xampp\backups\sharayu\release\product-qrcode-barcode-generator-1.0.0.zip`, 1,382,870 bytes, 252 files in 32 folders, SHA-256 `d9276ef5d80b248cb65786a0a64709e046e2edcf55047a2d82780854c3e3efdf`; a second build gave the same SHA-256 (reproducible); `unzip -t` clean; no development file. After the commit, a clean build (without `--allow-dirty`) is the release.
- **G. Fresh install** (temporary site `C:\xampp\htdocs\pqbg-fresh`, database `pqbg_fresh`; WordPress 7.1.2 from the official zip (SHA-1 checked) and WooCommerce 11.1.2, checksums verified; the plugin uploaded through Plugins → Add New → Upload Plugin over HTTP): **50 passed, 0 failed** (install, schema identical to this site's, roles, menu, settings, Health check, code, QR image, scan, sale and undo, My sales, deactivate, default delete keeps the data, reinstall picks it up, delete-all leaves nothing). Deleted afterwards (database dropped, folder removed). This site's fingerprint (53 tables with checksums, 465 options, 9,983 files, `wp-config.php`, `.htaccess`, the databases and htdocs folders) was identical before and after every attempt. Two earlier attempts failed at setup (WP-CLI's tar.gz extraction truncates WordPress 7.1's long paths; fixed by using the official zip) and were torn down the same way; evidence in `C:\xampp\backups\sharayu\phase13-fresh-install\`.
- **Cart 404 (D13):** on the fresh site the `…/undefinedwc/store/v1/cart` 404 appeared once in 12 visits (the cart page, plugin active); a focused repeat (5 rounds with the plugin inactive, 5 active, interleaved) showed it in none. With Phase 12's sighting with the plugin off, it is intermittent and not the plugin's. `LAUNCH.md` D4 updated.
- **H.** `LAUNCH.md` at the repository root (the Pre-launch acceptance checklist moved into it; see above).
- **Found; owner's decision: leave as is.** The delete-all uninstall removes the Store Seller role and every capability, but WordPress's `remove_role()` leaves the role's name in each former seller's own capabilities meta (it grants nothing). Noted in the plugin README's Uninstall section. The two concatenated translatable messages (D above) were also left as they are and recorded as deferred for a possible WordPress.org release (open items).

**Test changes:** `phase2-lifecycle` (23) and `phase8-printing` (168) restore the runtime state the real uninstall removes (each verified with planted timing samples and a planted cost-import preview); new `phase13-release.php` (28 checks, needs `PQBG_WPCLI`); `tests/guide-screenshots.php` (a tool, not in `run.php`). No check became false by design.

**Backups** (all 53 tables, "Dump completed"): `sharayu-20260928-115709-before-phase13.sql`, `…-125249-before-phase13-stress.sql`, five `…-before-phase13-guide-shots(-2…-5).sql` (13:32–14:39), `…-144642-before-phase13-release-dev.sql`, `…-144817-before-phase13-lifecycle-verify.sql`, `…-145352-before-phase13-final.sql`, `…-150140-before-phase13-final-run.sql`, `…-155427-before-phase13-phase8-fix.sql`, three `…-before-phase13-fresh-install.sql` (15:59, 16:05, 16:07), `…-161449-before-phase13-fresh-cart.sql`.

**Final tests** (2026-09-28 15:01–15:52, one suite at a time, every tool from `C:\xampp\tools\pqbg\` including `PQBG_WPCLI`, the 500 MB watchdog (never tripped; lowest 2,005 MB), the error capture, mains power checked before each suite): phase2-main 83, phase2-lifecycle 23, phase2-no-woocommerce 12, phase3 110, phase4 165, phase5 158, phase6 214, phase7 216, phase8 167 (168 after the fix, re-run 15:54–15:59), phase9a 253, phase9b 146, phase10 197, phase10b 92, phase11 130, phase12 274, phase13-release 28: **2,269 passed, 0 failed, 0 skipped; AS guard PASS for every suite; 0 events from plugin code** (the 6 known WordPress warnings in phase11). Crash count **139** before and after every run. The laptop ran on battery once (about 15:00, power line offline): no run was started until it was back on mains power.

**Final report:** `C:\xampp\backups\sharayu\phase13-report.txt`.

### Version 1.0.1: user manual (PDF) and help links (2026-09-28 → 2026-09-29): DONE

**Status:** done 2026-09-29. Tested on the final code (2,332 passed, 0 failed, 0 skipped); the D10 upgrade check 1.0.0 → 1.0.1 was **not run (owner's decision, 2026-09-29)**. Committed as "Release 1.0.1: user manual PDF, Plugin guide button, seller guide links", tagged `pqbg-v1.0.1` (annotated) and pushed with the owner's approval (normal pushes). Plan: `C:\xampp\backups\sharayu\manual-plan.txt` (D1–D11 approved). Report: `C:\xampp\backups\sharayu\manual-report.txt`.

> **If a manual build is killed, run the repair mode before anything else:** `php build/manual/build-manual.php --repair` (dry run; show it to the owner), then `--repair --apply` (see `build/manual/README.md`). A stop-file stop (`PQBG_STOP_FILE`) runs the build's own cleanup and needs no repair.

**Implemented (working tree):**
- Plugin: `AdminUrl::user_manual()` / `seller_guide()` (the only place that writes a `docs/` address; `?ver=PQBG_VERSION`); the Dashboard's "Plugin guide" button with a help icon, tooltip (`role="tooltip"`, `aria-describedby`, hover and focus, Escape via the new `assets/pqbg-help.js`, CSS in `pqbg-dashboard.css`) and "Seller guide (1 page, for staff)" (`DashboardAdmin::render_help()`); the new `includes/PluginLinks.php` ("User manual" in the plugin's row meta, registered in `Plugin::boot()` for admin requests); "How to sell (guide)" at the end of My sales (`ScanScreen::my_sales()` `guide`, `templates/pqbg-scan.php`, `pqbg-scan.css`). `ScanRoute` untouched. No schema, option, capability or sale-path change.
- Version 1.0.1 in the header, `PQBG_VERSION`, `readme.txt` (Stable tag, changelog, upgrade notice, the docs sentence) and `CHANGELOG.md` (1.0.1 entry, dated 2026-09-28); `.pot` regenerated (817 strings).
- Documents: `docs/user-manual.md` (the manual's source, 14 chapters plus "How to use this manual"); `docs/user-manual.pdf` (see below); `docs/owner-guide.md` removed with `git rm` (staged deletion only).
- Build: `build/manual/` (`build-manual.php`, `shots.mjs`, `render.mjs`, `pdf-text.mjs`, `manual.template.html`, `manual.css`, `package.json` + `package-lock.json` pinning marked 18.0.14 and pdfjs-dist 6.3.289, `README.md`, `index.php`); installed outside the site in `C:\xampp\tools\pqbg\manual\` (`PQBG_MANUAL_TOOLS`). `build/package.php` requires `docs/user-manual.md` and `.pdf` instead of the owner guide. A review-only page renderer (not part of the build) is in `C:\xampp\tools\pqbg\manual-review\`.
- Tests: new `tests/phase14-manual.php` (+ `tests/manual/help-check.mjs`), added to `run.php`; `phase13-release.php` (version read generically, the manual instead of the owner guide, a real test zip ≤ 6 MB containing the PDF); the new category-lookup leak guard (below).
- Docs: plugin `README.md` (version, "Help links" section, packaging, guides, strings), `tests/README.md` (phase14 row, `PQBG_MANUAL_TOOLS` / `PQBG_MANUAL_SHOTS`, the leak-guard gap and fix), `build/README.md`, `LAUNCH.md` (version, docs sentence, new manual check A8b, the 1.0.1 zip in C1).
- Deviation from the plan: the "no `docs/` URL outside AdminUrl" scope check is in `phase14-manual`, not in `phase10b-menu`.

**Manual builds so far (2026-09-28):**
- Backups: `sharayu-20260928-191430-before-1.0.1-manual.sql`, `…-193329-…`, `…-193941-before-1.0.1-manual.sql` (all 53 tables, complete). Crash count 139 before each build (httpd PIDs 220 and 14284).
- Build 1: 48 passed, 6 failed (the Plugins-row selector; a WordPress core ViewTransition script error on the Plugins screen, now reported instead of fatal; `imagedestroy()` deprecated in PHP 8.5; and 6 rows left in `wp_wc_category_lookup`). Its other cleanup was complete.
- Build 2: every check passed up to and including the PDF (29 screenshots, none showing a real name; `docs/user-manual.pdf` 33 pages, 1,658,607 bytes, TOC stable across both passes, no real name in its text). Then **Claude Code killed it for low system memory at the start of its cleanup** (the watchdog was killed with it).
- Build 2's PDF was replaced on 2026-09-29 (see "Testing and release" below).

**Cleanup done (owner-approved, 2026-09-28, guarded script with a dry run first; every ID checked against its expected login, SKU or category name):** users 1592–1595 (`pqbg_manual_owner` first, then ravi, asha, vikram, with their meta and sessions); products 120415–120417 (sample T-shirts 198–200); categories 378–383 and their 6 lookup rows; the plugin options restored byte for byte from the 19:39 backup (4 options; Scan base URL empty again; `pqbg_bulk_run`, `pqbg_bulk_log`, `pqbg_svg_cache_index` and 4 label-cache transients deleted, as they were not in the backup); the temp folder `%TEMP%\pqbg-manual-XkGucHzl`; then the orphaned `wp_wc_category_lookup` rows (category or tree ID not in `wp_term_taxonomy`): **92 counted and deleted** (the 6 from build 1 and the older ones from earlier phases). None of the 15 pending Action Scheduler jobs referenced a sample product (all WooCommerce/WordPress recurring jobs), so none was touched. Verified afterwards: options byte-identical to the backup, 0 sample users/meta/SKUs/categories/transients, 0 codes, 0 sales, 1 user, **0 orphaned category lookup rows** (1 row left: Uncategorized), the same 15 pending jobs.

**Second cleanup round (owner-approved, 2026-09-28, dry run first each time):**
- Post **120414** ("Cotton T-shirt – sample 197", created 23:14:05 by build 2; its SKU meta was already gone, so the first, SKU-based listing missed it): a bare `product` row with no meta, term relationships, code, children or attachments, and one `wc_product_meta_lookup` row (removed by WooCommerce's delete hook). Verified: 0 left, 0 products on the site.
- A read-only sweep against the highest IDs of the 19:14 backup (before either build) then found only **422 completed Action Scheduler jobs** (`woocommerce_run_product_attribute_lookup_update_callback`, action IDs 165029–165450, naming 210 deleted posts 120208–120417, build 2's products; it was killed before its AS cleanup) and their **1,266 log rows**: deleted exactly those (the script refused anything else in the range). Verified: 0 left, 0 orphaned logs, the same 15 pending jobs.
- The sweep found nothing else: no post above ID 2922, no "sample" title or SKU, no orphaned postmeta, term relationships, product meta/attribute lookup or category lookup rows, no user above ID 1, no orphaned user meta, no term above ID 53, the same 4 plugin options, 0 codes, 0 sales. Left untouched on the owner's instruction: nine WordPress/WooCommerce options created since the backup (the helper-subscriptions, theme-roots, theme-patterns and `wc_term_counts` transients, and `product_cat_children`, whose value `a:0:{}` equals the backup's), and `%TEMP%\pqbg-sed.txt` (0 bytes, 09:12, before this work).

**Clean state confirmed** (2026-09-28, after both rounds): the lookup guard's mark is `0,0` (0 orphaned category lookup rows, 0 orphaned product meta lookup rows) and its check passes; 0 sample users, posts, terms, codes, sales; the plugin options byte-identical to the 19:39 backup; 15 pending jobs, none for test data.

**Added after the cleanup (owner's items, not yet run in a suite):**
- `lookup-guard.php` also checks `wp_wc_product_meta_lookup` (rows whose `product_id` has no post; `pqbg_test_prodlookup_orphans()` in `bootstrap.php`); the mark is now "category,product". Tried: mark `0,0`, check PASS, the old one-number form refused. Noted in `tests/README.md`.
- **Repair mode for the manual build** (`build/manual/repair.php`, `build-manual.php --repair [--apply]`): the build saves a start snapshot (`%TEMP%\pqbg-manual-start.ser`: the plugin options byte for byte, the highest post/user/term/job/log IDs) before anything else, keeps it unless its cleanup checks all pass, refuses to start while one exists; the repair lists (dry run) and then removes only rows above those IDs with a sample marker (see `build/manual/README.md`). Tried: without a snapshot "nothing to do"; with a snapshot of the clean site the dry run found nothing (the snapshot file was then deleted by hand; the site was not touched). `phase14-manual` checks that `repair.php` exists. Tested against a real killed build on 2026-09-29 and fixed (below).
- The manual build was not run again on 2026-09-28 (owner: not tonight); it was run on 2026-09-29 (below).

**Leak-guard gap fixed (owner's item 2):** `wp_wc_category_lookup` was guarded by nothing; see `tests/README.md` ("1.0.1: WooCommerce category lookup leak guard"). `bootstrap.php` helpers (`pqbg_test_catlookup_mark/cleanup/check`) in `phase6-scan`, `phase9b-reports`, `phase12-themes`, `guide-screenshots.php` and `build-manual.php`; `tests/lookup-guard.php` in `run.php` after every suite. `phase10b-menu` creates no category (only the runner guard applies). Checked: `lookup-guard.php` mark 0, check PASS on the clean site.

**Testing and release (2026-09-29):**
- Start: Apache and MySQL were stopped and the laptop on battery; after the owner fixed the charger and closed Chrome and Edge, Claude started MySQL and Apache (free memory 3,016 MB, mains power for every run). Backups (53 tables, complete): `sharayu-20260929-150722-before-1.0.1-repair-test.sql`, `…-151643-before-1.0.1-manual-build.sql`, `…-153445-before-1.0.1-manual-rebuild.sql`, `…-154040-before-1.0.1-suites.sql`, `…-164109-before-1.0.1-manual-final.sql`, `…-164455-before-1.0.1-final-suites.sql`, `…-182702-before-1.0.1-upgrade-check.sql`.
- **Repair mode:** a stop-file stop runs the build's own cleanup (all checks passed, snapshot deleted, `--repair` "Nothing to do"). A hard kill after 126 sample sales: the dry run listed exactly the 23 leftover items and `--apply` removed them, but 10 completed WooCommerce attribute-lookup jobs (165502–165511) and 30 logs were left, created by the repair's own product deletes. **Fixed** (owner-approved): `repair.php` sweeps the jobs again after the deletes and counts them in its verification; the 10 jobs and 30 logs removed with a guarded script (dry run first). A second hard kill with the fixed repair: the site identical to its state before the kill; lookup guard PASS.
- **Documents:** final build `--with-seller-guide` (16:41–16:44): seller guide 13/13, manual 55/55. `docs/user-manual.pdf` 34 pages, 1,660,796 bytes, 35 bookmarks, 29 screenshots (702.6 KB; none with a real name); `docs/seller-guide.pdf` one page, 286,300 bytes, version 1.0.1. The worked examples match (21 labels; ₹4,297 − ₹899 = ₹3,398; 200 codes stopped at 100; the cost import). Screenshots kept in `C:\xampp\backups\sharayu\manual-shots\`.
- **Fixes:** `pqbg-scan.css` (the My sales guide link was 133 × 43 px, below the 44 px touch target; phase12 found it in 6 themes × 2 browsers); `templates/pqbg-scan.php` (a space before its screen-reader note); `build/manual/manual.css` (padding-top on chapter headings: Chrome wrote every chapter bookmark twice); `phase14-manual.php` (the TOC check joined all lines; four prose phrases; the Tab count reported); `tests/manual/help-check.mjs` (Tab limit 80 → 400: WordPress 7.1 needs 100 presses); `tests/guide/guide-shots.mjs` (page errors name the page). Cosmetic, left (Chrome's outline): the cover bookmark "…BarcodeGenerator" and the badge bookmarks "2. Setting upAdministrator only".
- **Final tests** (the final code, one suite at a time, every tool set, watchdog never tripped, error capture per run): phase14 57, phase2-main 83, phase2-lifecycle 23, phase2-no-woocommerce 12, phase3 110, phase4 165, phase5 158, phase6 215, phase7 216, phase8 168 (re-run: the laptop slept during the first run, reason "button or lid"), phase9a 253, phase9b 147, phase10 197, phase10b 92, phase11 130, phase12 275, phase13 31: **2,332 passed, 0 failed, 0 skipped**; AS guard and lookup guard PASS for every suite; 0 events from plugin code (phase11: the 6 known WordPress warnings).
- **Crash count 139 → 143** (httpd.exe in php8ts.dll, none in plugin code): 15:08 outside any run, 15:17 during a seller guide run, 15:26 (two) during the first phase14 run; the affected runs were repeated; 143 before and after every final run.
- **D10 upgrade check 1.0.0 → 1.0.1: not run (owner's decision, 2026-09-29).** No temporary site or database was created.
- WordPress/WooCommerce caches left as they are (not test data): two `_site_transient_feed_*` transients and the WooCommerce option `ptk_patterns`.

### Instructions for the next Claude session

- **Owner decision, 2026-09-29: no further test runs unless the owner asks.** Do not run any test, suite, build check or document build unless the owner explicitly asks. This overrides the "run `php tests/run.php` before and after every phase" instruction below until the owner says otherwise.

- Read this file and `wp-content/plugins/product-qrcode-barcode-generator/README.md` first. Re-verify the environment; don't trust these notes blindly.
- **Per-phase manual tests are no longer required. Future phases add their manual checks to `LAUNCH.md` (the launch runbook, which replaced the Pre-launch acceptance checklist in Phase 13) instead of asking the user to test per phase, unless the user's reviewer explicitly asks for an immediate manual test.**
- **Reports rules** (Phase 9B):
  - Read reports only through `ReportsQuery` (read-only aggregates over `pqbg_sales`), `StockQuery` (stock, dead stock, missing codes) and `ReportData` (the dataset shared by the screen, the CSV and the print page). Periods only through `ReportPeriod` (site-timezone days, week start from Settings, comparison "to the same point in time").
  - Revenue counts completed sales only; profit only where `unit_cost` is known, margin = profit ÷ revenue with a known cost; unknown cost always disclosed; everything by sale date.
  - Anything about cost, profit, margin or value at cost exists only for `pqbg_view_costs`: not built at all otherwise, and a request for it (profit tab/CSV, sorting by a cost column) is a 403. Costs in reports only through `CostPrice::get_many()`.
  - Orders are only *read* (dead stock: order line items of processing, completed and on-hold orders through `OrderUtil::get_table_for_orders()`); never write orders.
  - No result cache and no reports index yet; see the Phase 11 performance open item before adding either, and never change the sale path for reporting speed without that decision.
  - `SaleService::void_fields()` records `void_restock` (schema v4) on every void and undo.
- **Before running tests in a new session, take a database backup with mysqldump to a folder OUTSIDE the web root (never commit it) and record the file name in the session report.** Use `C:\xampp\backups\sharayu\` (outside `htdocs` and the repository), `C:\xampp\mysql\bin\mysqldump.exe --single-transaction --routines --triggers --default-character-set=utf8mb4`, with the credentials read from `wp-config.php` into a temporary `--defaults-extra-file` in the session scratchpad (never on the command line or in output), deleted afterwards. Check the file ends with "Dump completed". The first one: `sharayu-20260926-120456-before-phase9a-final.sql` (2026-09-26, Phase 9A).
- **Current names:**
  - plugin "Product QR Code and Barcode Generator"
  - slug `product-qrcode-barcode-generator`
  - namespace `ProductQrBarcode`
  - prefix `pqbg_` / `PQBG_`
  - role `pqbg_seller` "Store Seller"

  The `dpc_`/`DPC_`/`Durga\ProductCodes` names in the Phase 2 and Phase 3 sections are pre-rename history. Never reintroduce them.
- Get codes only through `ProductCodeService::get_or_create()`. Don't call `CodeRepository::create_active()` with hand-made strings, and don't write to `pqbg_codes` directly.
- Phases 4 and 5 are done. Build scan URLs only through `ScanUrl`, and render only through `QrRenderer`/`BarcodeRenderer`. Never reference the barcode library outside `BarcodeRenderer`, and keep the "barcodes disabled means the library is not loaded" guarantee.
- **Report the Windows crash count** (`httpd.exe` Application Error events, Event ID 1000) before and after test runs. A crashed run is neither a pass nor a fail of plugin logic: re-run the suite. It was 139 on 2026-09-25 after Phase 8, and still 139 after every Phase 9A and Phase 9B run.
- **In test suites, turn `wp_die()` into an exception** (`wp_die_handler` filter, as the Phase 9A suite does) before calling importers or other WordPress code in-process: `wp_die()` exits, and `finally` cleanup does not run on exit (see the Phase 9A notes).
- **Run `php tests/run.php` before and after every phase** (see `tests/README.md`). Preferred: `define( 'WP_ENVIRONMENT_TYPE', 'local' );` in the local `wp-config.php`, which the user will add themselves; never edit or commit `wp-config.php`. The fallback is `PQBG_TESTS_ALLOW_PRODUCTION=1`. The round-trip checks need `npm ci` in `tests/decoder`, or `PQBG_DECODER` pointing to a copy outside the web root. Add each new phase's suite to `tests/` and to `run.php`.
- **Exclude `tests/` and `build/` from any production deployment** (see "Production deployment" in the plugin README).
- **Never edit `vendor-prefixed/` by hand.** Change `build/` and run `php build/build.php` (see `build/README.md`).
- The PHP minimum is now **8.2**. Since Phase 11, `C:\xampp\tools\pqbg\php82\` holds the official PHP 8.2.34 NTS build for `php -l` on the minimum version (only 8.5.6 runs the site).
- **Hardening rules** (Phase 11):
  - The Health check (`HealthCheck`, `HealthCheckAdmin`; Settings → Health check, `pqbg_manage_settings`) is read-only and report-only: SELECT queries only, no repair buttons, no cache. A repair needs its own decision. Never show a cost value there (`CostPrice::invalid_values()` returns reasons only).
  - The Dashboard's only write is `PerfSignal::record()` (the last 10 compute times in `pqbg_perf_samples`, at most one write per 60 s); the signal warns only on a repeated condition. Do not build the roll-up table or result cache before it says so.
  - The Undo button hides itself with a zero-length CSS animation whose delay is set in a nonce'd `<style>` element; `ScanRoute::csp( $nonce )` allows exactly that nonce on that response. No JavaScript and no reload on the scan page.
  - `BulkLog::add()` writes under a named lock (5 s, then writes anyway).
  - Multisite is refused by `Requirements` (network and per-site). Test requirement branches with `Requirements::errors_for()`.
  - Every suite honours `PQBG_STOP_FILE` (`pqbg_test_stop_point()`; `pqbg_section()` is a safe point; nothing stops during "cleanup"). New suites must keep their body in `try`/`finally` with every section inside it, and call `pqbg_test_stop_point()` in long loops.
  - For test runs only, the error logger `C:\xampp\tools\pqbg\errors\pqbg-error-capture.php` may be copied to `wp-content/mu-plugins/` with `PQBG_ERROR_CAPTURE=C:\xampp\tools\pqbg\errors`; remove it (and the `mu-plugins` folder, which did not exist before) afterwards. Target: zero events from plugin code.
  - PHPCS: `C:\xampp\tools\pqbg\phpcs\` (see `tests/README.md`).
- **Theme compatibility rules** (Phase 12):
  - No theme-specific code: never name a theme or branch on the active theme (`get_template()`, `wp_is_block_theme()` …); the Phase 12 suite has a scope check.
  - The scan pages stay standalone (no theme, `wp_head()`, script); the plugin outputs nothing on store pages or emails (the suite checks the front-end hook list).
  - Every scan response defines `DONOTCACHEPAGE`, `DONOTMINIFY`, `DONOTCDN`; a logged-out visitor only ever gets the 302 to the login page (any method), so no page cache can store a scan page.
  - Plain / `index.php` permalinks: the Health check error, the Dashboard warning and the admin notice (settings or code managers); no fallback URL.
  - `tests/phase12-themes.php` needs `PQBG_THEMECHECK`, `PQBG_THEME_PACKS`, `PQBG_CACHE_PLUGIN`, `PQBG_ERROR_CAPTURE` (run it through `run.php`) and `PQBG_SCREENS`; it takes about 16 minutes. If a run is killed, run `php tests/phase12-repair.php` (dry run), then with `--apply`, before anything else.
  - **Keep the laptop on mains power with the lid open during runs** (a closed lid slept the machine during a Phase 12 development run).
- **Release rules** (Phase 13):
  - The version is in four places (the header, `PQBG_VERSION`, `readme.txt` Stable tag, the top of `CHANGELOG.md`); `phase13-release.php` fails when they differ.
  - After changing any user-facing string, regenerate `languages/product-qrcode-barcode-generator.pot` with WP-CLI (`C:\xampp\tools\pqbg\wp-cli\wp-cli.phar`; the command is in the plugin README, "Release and packaging").
  - After changing a scan screen or `tests/guide/seller-guide.template.html`, rebuild the seller guide with `php tests/guide-screenshots.php` (backup first; `PQBG_THEMECHECK`).
  - Build the release zip only with `build/package.php` from a commit (`php -d extension=zip build/package.php --out=C:\xampp\backups\sharayu\release`); `--allow-dirty` is for test builds.
  - `LAUNCH.md` is the single list of manual checks and launch steps; add new manual checks there.
  - Suites that run the real uninstall must restore the runtime state it removes (`phase2-lifecycle`, `phase8-printing`).
  - WordPress 7.1 has paths too long for WP-CLI's tar.gz extraction on Windows: install WordPress from the official zip (SHA-1 from wordpress.org), as the fresh-install test does.
- On this live dev site, create new class files **before** referencing them from boot code (see the Phase 4 incident).
- **Phase 7 (Mark as Sold) is done, approved, committed as `2413698` and pushed** (2026-09-25).
- **Phase 8 (Printing) is done, approved, committed as `753613c` and pushed** (2026-09-25). **The physical printer test is NOT done** (no printer was available): it must be done before printing real labels / before production launch (see the Status open items).
- **Phase 9A (Sales history, payment method & cost price) is done, approved on the automated tests, committed as `5495b05` and pushed** (2026-09-26). **Its manual check is NOT done** (the "Phase 9A checklist" in the plugin README): it is in the pre-launch acceptance checklist.
- **Phase 9B (Reports & owner dashboard) is done, approved on the automated tests, committed as `072f4d6` (the Phase 7 race fix) and `716600d` (Phase 9B) and pushed** (2026-09-26). **Its manual checks are NOT done** (the "Phase 9B checklist" in the plugin README): they are in the pre-launch acceptance checklist. **Phase 10 (Bulk / CSV tools) is done, approved on the automated tests, committed as `5b746a7` and pushed** (2026-09-27); its manual checks are in the pre-launch acceptance checklist. **Phase 10B (menu restructure and plugin Dashboard) is done, approved on the automated tests, committed as `0145aff` and pushed** (2026-09-27); its manual checks are in the pre-launch acceptance checklist.
- **Bulk tools rules** (Phase 10):
  - Since Phase 10B the tools are QR & Barcodes → Bulk tools (`page=pqbg-bulk-tools&tab=tools|costs`; the page needs `pqbg_manage_codes`) and Settings is its own page (`page=pqbg-settings`, `pqbg_manage_settings`); each tab and handler checks its own capability (Code tools `pqbg_manage_codes`, Import cost prices `pqbg_view_costs`) before the nonce.
  - Bulk codes only through `BulkGenerator` → `ProductCodeService::get_or_create()`; never a stored queue; keep the batch `GET_LOCK` and the re-check before each code.
  - Costs in bulk only through `CostImport` → `CostPrice::normalize()` / `CostPrice::set()`; never name the meta key outside `CostPrice` and `uninstall.php`; the codes CSV never contains costs.
  - Uploaded files are read from PHP's temporary folder and deleted at once; only parsed rows are kept (user meta `pqbg_cost_import`, 1 hour, pruned on page load, removed on uninstall).
  - Every bulk action is logged in `pqbg_bulk_log` (counts only); cost entries are shown only to `pqbg_view_costs`.
  - Code CSV **import** is deliberately not implemented (D6, backlog).
- **Menu and admin URL rules** (Phase 10B):
  - Every plugin page lives in the plugin's own top-level menu **QR & Barcodes** (`AdminMenu`): Dashboard, In-store sales, In-store reports, Bulk tools, Settings. Never register a plugin page under WooCommerce. The two hidden screens (print setup, Regenerate) stay under Products.
  - Build every wp-admin URL (links, form actions, redirect targets) only through `AdminUrl`; never call `admin_url()` or write a page slug elsewhere (the 10B suite has a scope check).
  - Never hard-code a screen ID (`woocommerce_page_…`, `…_page_pqbg…`, `toplevel_page_…`): compare with `AdminMenu::is_page()` / `hook()` (the stored hook suffix; its prefix is the translated menu title).
  - Every plugin page shows `AdminMenu::render_nav()` first; each page keeps its own capability check on its `load-` hook.
  - Keep the old-address redirects (`AdminMenu::old_url_target()`) permanently.
  - In-store reports' first tab is **Summary** (`&tab=summary`; `&tab=dashboard` still opens it). The plugin Dashboard's figures come only from `ReportsAdmin::dashboard_data()` for "today".
- **Owner decisions of 2026-09-27 (Phase 10B):** (A) plugin first: every plugin phase before any theme work; the theme phases are at the end of the roadmap, optional, later. (B) The plugin must work with any WooCommerce theme: Phase 12 (Theme compatibility), after Phase 11 and before plugin QA/packaging (Phase 13); plan its scope then (see the open item). (C) The 50,000-sale stress checks (`PQBG_STRESS=1`, Phase 9B and 10B suites) run in Phase 11 and once before launch; default runs use the 5,000-sale checks. (D) No printer yet: the physical printer test waits for the printer and label stock; an interim no-printer check is in the pre-launch checklist.
- **Test tools:** permanently in `C:\xampp\tools\pqbg\` (outside the web root): `PQBG_DECODER=C:\xampp\tools\pqbg\decoder\decode.mjs`, `PQBG_PRINTCHECK=C:\xampp\tools\pqbg\print-check\check.mjs`, `PQBG_WXR_IMPORTER=C:\xampp\tools\pqbg\wordpress-importer\wordpress-importer.php`. Point them at the files, not the folders; state "0 skipped" in every test report.
- **Memory:** close Chrome/Edge and turn off sleep before long runs; with less than 1.5 GB free run the suites one at a time; stop a run cleanly below 500 MB (the Phase 10 suite honours `PQBG_STOP_FILE`). Claude Code stops background commands when the machine runs critically low on memory; a killed suite leaves its test data, which must be listed and removed with the user's approval.
- **Sales history rules** (Phase 9A):
  - Every sale needs a payment method enabled at that moment (`PaymentMethods`), validated in `SaleRequest` and again in `SaleService::sell()`, after the existing-request check (idempotency first). It, `unit_cost` and `seller_name` are written in the pending row.
  - Reports count `completed` rows only; `voided`/`failed` are counted separately. Unknown cost (`unit_cost` NULL) is never zero: exclude those lines from cost/profit and report how many.
  - Read the history only through `SalesQuery` (read-only; keyset paging for exports) and format through `SalePresenter`. Date ranges are site-timezone days (`SalesQuery::range()`).
  - **Cost is visible only with `pqbg_view_costs` (administrators).** Read it with `CostPrice::get()/effective()`, write it only with `CostPrice::set()` (the guards refuse every other write). Never add it to REST, Store API, exports, scan/My sales pages or labels. Keep `CostPrice`'s hooks (read_meta filter, write/delete guards, WXR skip) and never hook `delete_post_metadata_by_mid`.
  - My sales is `/scan/my-sales/`, built only by `ScanUrl::my_sales_url()`, served by `ScanRoute` through the existing rule; the seller always comes from the session.
  - The Phase 9B reports reuse `SalesQuery::where()` and the same rules (see the reports rules below).
- **Printing rules** (Phase 8):
  - Print only ACTIVE codes, read through `CodeRepository::find_active_for_products()`; printing never generates codes.
  - Payloads only from `ScanUrl::for_code()`, images only from `QrRenderer`/`BarcodeRenderer`, cached only through `PrintCache` (its key must include everything that changes the image; bump `PrintCache::VERSION` if the output changes outside the renderers' constants).
  - Keep the QR module ≥ `PrintLayout::MIN_MODULE_MM` (0.40 mm); refuse layouts instead of shrinking it.
  - The print page and setup screen are GET and read-only (only the render cache may be written); options are saved only by the `pqbg_print_prepare` POST. Keep the print CSP: styles only from the nonce'd `<style>` element and `assets/pqbg-print.css`, script only `assets/pqbg-print.js`, no inline style attributes.
- **Action Scheduler in tests:** every suite uses `pqbg_test_as_mark()` / `pqbg_test_as_cleanup()` / `pqbg_test_as_check()` from `tests/bootstrap.php`, and `run.php` fails a suite that leaks (`tests/as-guard.php`). New suites must do the same.
- **Browser checks:** install `tests/print-check` outside the web root (copy, `npm ci`) and set `PQBG_PRINTCHECK`; it uses the installed Edge/Chrome (`PQBG_BROWSERS` to override).
- **WXR import check (Phase 9A):** unzip the official WordPress Importer (https://downloads.wordpress.org/plugin/wordpress-importer.zip) outside the site and set `PQBG_WXR_IMPORTER` to its `wordpress-importer.php`; without it that one check is skipped. Never install it into the site for this.
- **Phone testing:** never change Settings → General → WordPress Address for phone tests; use the `wp-config.php` snippet from the plugin README instead.
- **Don't toggle `woocommerce_coming_soon` with `update_option()` from the CLI.** WooCommerce then re-saves the Cart page as user 0 and re-serializes its content (see the Phase 7 post-review notes).
- **Selling rules** (Phase 7):
  - Sell only through `SaleService::sell()`, undo through `SaleService::undo()`, and void through `SaleService::void_sale()`.
  - Only `SaleRepository` writes `pqbg_sales`, and rows are never deleted.
  - Never wrap the sale code in a DB transaction.
  - Never change stock except through `wc_update_product_stock()` inside `SaleService::change_stock()`.
  - If WooCommerce is upgraded, run the Phase 7 suite: its COMPATIBILITY checks fail loudly if `woocommerce_update_product_stock_query` stops firing or its SQL changes shape.
- `DB_VERSION` is **4** (Phase 9B: `void_restock`; Phase 10 needed no schema change). The next schema change is migration 5.
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
- Product-save and delete hooks now exist, in `CodeLifecycle` only, on exactly the approved hooks (the four WooCommerce CRUD save hooks and `deleted_post`). Phase 9A added, with approval (D12), `CostPrice`'s hooks: the three product-editor field actions, `woocommerce_admin_process_product_object` / `woocommerce_admin_process_variation_object`, `woocommerce_data_store_wp_post_read_meta`, `add/update/delete_post_metadata` (its own key only) and `wxr_export_skip_postmeta`. Don't add others without explicit approval.
- Regenerate only through `ProductCodeService::regenerate()` (atomic `CodeRepository::replace_active()`). Admin requests go through `AdminActions`: POST for anything that writes, a nonce bound to the item, and `pqbg_manage_codes`. Never add `nopriv` handlers.
- Only the classic product editor is supported. Re-check `product_block_editor` before relying on the Phase 5 UI (and the Phase 9A cost fields).
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
  - Approved by the user (before the printer test); committed as `753613c` and pushed to `origin/main`.
- **Phase 9A (Sales history, payment method & cost price), 2026-09-25 → 2026-09-26:**
  - Re-verified the environment; no differences. Crash count 139; PHP 8.5.6 unchanged. Baseline: 1,135 passed, 0 failed, 0 skipped.
  - Found in the WooCommerce 11.1.2 source that the v3 REST products API returns protected meta in `meta_data`, and that `woocommerce_data_store_wp_post_read_meta` filters every WC_Data meta read. Wrote the plan; the user approved D1–D13 with two additions (WXR export, deletion paths).
  - Measured the `method_created` index on 50,000 rows before choosing schema v3; rejected a covering totals index.
  - Added `PaymentMethods`, `CostPrice`, `SalesQuery`, `SalePresenter`, `SalesListTable`, `SalesAdmin`, `SalesExport`, schema v3 (`migrate_3`) and `pqbg_view_costs`; the payment radio group, "Paid by", My sales and the header links on the scan page. Class files were created before `Plugin.php` referenced them. The live site migrated to v3 with 0 sales rows.
  - Added `tests/phase9a-sales-history.php` and updated the Phase 2, 4 and 7 checks made false by design.
  - Made the 50,000-row CSV export 5–6× faster (keyset paging, cheaper formatting) and the totals one pass.
  - Two test-caused incidents (an importer's `wp_die()`; a WXR `import_id` that moved the posts AUTO_INCREMENT), both cleaned up after listing every row, both prevented in the suite now (see the Phase 9A section).
  - 2026-09-26: reference check clean (no reference to post IDs above 6200); database backup `sharayu-20260926-120456-before-phase9a-final.sql`; the manual test recorded as pending (an earlier message saying it passed was sent by mistake); pre-commit run ALL PASSED, 1,388 checks, 0 skipped, AS guard PASS, crash count 139 before and after.
  - Approved on the automated tests; committed as a single commit and pushed to `origin/main` (normal push).

### 2026-09-26
- **Phase 9B (Reports & owner dashboard):**
  - Re-verified the environment; no differences. Backup `sharayu-20260926-123420-before-phase9b.sql`. Crash count 139 throughout.
  - Baseline: 1,387 passed, 1 failed (a genuine, intermittent Phase 7 race: a duplicate request mid-sale answered "failed"), 0 skipped.
  - Wrote the plan; the user approved D1–D15 with two additions (the race fix as a separate commit; "Cash expected in drawer" / net collected on the end of day).
  - Fixed the race (D6) with a deterministic check that fails on the old code; added schema v4 (`void_restock`), the reports classes, the dashboard, ten reports, SVG charts, CSVs and the end-of-day print page; `tests/phase9b-reports.php`.
  - Performance: the dashboard misses "under 1 s for 90 days" at 50,000 sales in 90 days (about 1.4–1.5 s); the user chose to accept and document it (5,000-sale check at the original targets: met; 50,000-sale check at 2 s as a regression guard; a Phase 11 open item).
  - Final 9B run: 148/148. The final full run was stopped by Claude Code for low memory during the 9B cleanup (Phase 7 had one false-by-design failure, since fixed); the leftover test data was listed and removed.
  - Re-run approved: with free memory already below 1.5 GB, every suite ran on its own: all 11 passed, 1,540 checks, AS guard PASS, crash count 139 throughout. The race-fix checks passed 5 times in a row. Reconciliation and shop-manager evidence recorded. Commit-1 patch and a fresh backup saved in `C:\xampp\backups\sharayu\`.
  - Housekeeping: `5495b05` recorded; the Pre-launch acceptance checklist created (Phase 8 printer test, Phase 9A and 9B manual checks) with the new instruction on manual tests.
  - Not committed; waiting for the user's approval (then two commits).
  - Approved on the automated tests; committed as `072f4d6` (the Phase 7 race fix) and `716600d` (Phase 9B), pushed to `origin/main` (normal push).
- **Phase 10 (Bulk / CSV tools), planning:**
  - `HEAD` = `origin/main` = `716600d`, clean; roadmap numbering confirmed. Recorded `072f4d6` and `716600d`.
  - Backup `sharayu-20260926-234618-before-phase10.sql`. Baseline 1,540 passed, 0 failed, 0 skipped (suite by suite; phases 4, 5, 6, 8 re-run with the tool paths pointing at the `.mjs` files). Crash count 139.
  - Wrote the plan (D1–D16).

### 2026-09-27
- **Phase 10 (Bulk / CSV tools):**
  - The user approved the plan with the tools as tabs of QR & Barcodes (per-tab capabilities), pruning of expired cost previews, permanent test tools in `C:\xampp\tools\pqbg\` and the MENU RESTRUCTURE open item.
  - Added `BulkGenerator`, `BulkLog`, `CodesExport`, `CsvUpload`, `CostImport`, `ToolsAdmin`, the tools CSS/JS, the tabs in `SettingsPage`, `ProductCodeService::get_or_create()`'s `&$created`, the uninstall changes and `tests/phase10-bulk.php`. Class files were created before `Plugin.php` referenced them. No schema change.
  - The first Phase 10 run was stopped by Claude Code for low system memory; its test data was listed and, with the user's approval, removed by a guarded script; clean state confirmed. Added a memory watchdog to the runner and a cooperative stop (`PQBG_STOP_FILE`) to the suite.
  - Fixed from the runs: `BulkGenerator::state()` stale "option does not exist" cache, `BulkLog::add()` re-read, faster export (stored attribute summary); new tests for the first two.
  - Backup `sharayu-20260927-105123-before-phase10-tests.sql`. Phase 10 on its own: 197/197; then every other suite one at a time: 1,540/1,540. Total 1,737 passed, 0 failed, 0 skipped; AS guard PASS everywhere; crash count 139 throughout. All volume timings within target.
  - Updated the Phase 3, 4 and 9A checks made false by design (D16), `tests/README.md`, the plugin README (Bulk tools, Phase 10 checklist) and this file. Report: `C:\xampp\backups\sharayu\phase10-report.txt`.
  - Not committed; waiting for the user's review.
  - Approved on the automated tests; committed as `5b746a7` and pushed to `origin/main` (normal push).
- **Phase 10B (menu restructure and plugin Dashboard), planning:**
  - `HEAD` = `origin/main` = `5b746a7`, clean. Recorded `5b746a7`; the roadmap now names the MENU RESTRUCTURE phase as Phase 10B (owner's decision: Option 1).
  - Backup `sharayu-20260927-120429-before-phase10b.sql` (950,829 bytes, 53 tables, complete). Baseline suite by suite: 1,737 passed, 0 failed, 0 skipped; AS guard PASS; crash count 139 throughout. The first phase10-bulk run was stopped by the memory watchdog (491 MB free; Chrome had been opened) and cleaned up fully; not counted; re-run on its own: 197/197.
  - Wrote the plan (D1–D15): `C:\xampp\backups\sharayu\phase10b-plan.txt`. Waiting for approval; nothing implemented.
- **Phase 10B (menu restructure and plugin Dashboard):**
  - The user approved D1–D15 with seven changes (Summary tab, hidden screens stay, stored hook suffixes, the missing-code label, `AdminUrl` for redirects, the shared tab row, opt-in stress data) and recorded owner decisions A–D (plugin first; Phase 12 theme compatibility; stress checks in Phase 11 and before launch; no printer yet, interim check). Roadmap renumbered: 11 hardening, 12 theme compatibility, 13 plugin QA/packaging, theme work optional later.
  - Added `AdminUrl`, `AdminMenu`, `DashboardAdmin`, `pqbg-menu.css`, `pqbg-dashboard.css`; switched every admin link, form action and redirect to `AdminUrl`; split Bulk tools and Settings; renamed the reports' Dashboard tab to Summary; `tests/phase10b-menu.php`; the false-by-design edits; the 9B stress checks opt-in. Class files were created before `Plugin.php` referenced them.
  - The first final run was killed for low memory; its leftovers were removed with the user's approval by a guarded script; clean state confirmed. The user accepted the shop manager's redirect from the Settings address to Bulk tools.
  - Backup `sharayu-20260927-152746-before-phase10b-final.sql`. Final: 10B 91/91 alone, then every other suite one at a time; Phase 9A and 10 re-run after three test-only corrections; total 1,826 passed, 0 failed, 0 skipped; AS guard PASS everywhere; crash count 139 throughout. The one-off 50,000-sale Dashboard check: 93/93, 148 ms in-process, +221 ms over HTTP.
  - Updated the plugin README, `tests/README.md` and this file; report `C:\xampp\backups\sharayu\phase10b-report.txt`.
  - Not committed; waiting for the user's review.
  - Approved on the automated tests; committed as `0145aff` and pushed to `origin/main` (normal push).
- **Phase 11 (hardening), planning:**
  - `HEAD` = `origin/main` = `0145aff`, clean. Recorded `0145aff`. Collected every open item and known limitation into one list (in the plan).
  - Backup `sharayu-20260927-165517-before-phase11.sql` (961,712 bytes, 53 tables, complete). Baseline suite by suite: 1,826 passed, 0 failed, 0 skipped; AS guard PASS; no decoder/print/importer check skipped; crash count 139 throughout. Free memory was 1,294 MB at the start; the watchdog tripped during phase 8 (430 MB; the suite still completed and passed); the user closed the browsers and 9A, 9B, 10 and 10B ran with about 3.5 GB free. Clean state confirmed afterwards.
  - Installed PHP_CodeSniffer 3.13.6 + WPCS 3.4.1 + PHPCompatibilityWP 2.1.8 outside the repository in `C:\xampp\tools\pqbg\phpcs\` (ruleset `pqbg.xml`); first run: 276 errors, 280 warnings, 0 PHP 8.2+ compatibility findings; every security-sniff report is a false positive. Security review: no High/Medium findings; two Low (CSV neutralisation edge cases, uninstall leaves save-failure transients), two Info.
  - Wrote the plan (D1–D22): `C:\xampp\backups\sharayu\phase11-plan.txt`. Waiting for approval; nothing implemented.
- **Phase 11 (hardening):**
  - The user approved D1–D22 with six changes (Undo hidden by CSS without a reload; a repeated-condition performance signal; the temporary logger only for the runs; the stop file in every suite before the stress run; ask before touching the sale path, schema or stored data; PHPCS: only the translator comments, plus a Phase 13 note). Kept 409 for a late Undo when asked.
  - Added `HealthCheck`, `HealthCheckAdmin`, `PerfSignal`; changed `SettingsPage`, `AdminUrl`, `DashboardAdmin`, `ScanScreen`, `ScanRoute`, the scan template and CSS, `BulkLog`, `SalesExport`, `CsvUpload`, `CostPrice`, `Requirements`, `Install`, `uninstall.php`; `tests/phase11-hardening.php` (130 checks), the stop file in every suite, the runner's STOPPED and error-capture reporting. Class files were created before anything referenced them.
  - Final: 1,965 passed, 0 failed, 0 skipped; 0 notices from plugin code; crash count 139 throughout. Stress (50,000 sales): 9B, 10B, 11 all passed.
  - Incidents: a minute-long parse error in `DashboardAdmin.php` during development; the machine slept 19:58–22:44 (a run re-done); the first stress run killed for low memory, its leftovers removed with the user's approval by a guarded script.
  - Not committed; waiting for the user's review. Report: `C:\xampp\backups\sharayu\phase11-report.txt`.
  - Approved on the automated tests; committed as `32a8b90` and pushed to `origin/main` (normal push).

### 2026-09-28
- **Phase 12 (theme compatibility), planning:**
  - `HEAD` = `origin/main` = `32a8b90`, clean. Recorded `32a8b90`. Theme state recorded for exact restoration: twentytwentyfive (block) active, `theme_mods_twentytwentyfive` = `{"custom_css_post_id":-1}`, global styles #2922 and navigation #4 in the database; values and hashes in `C:\xampp\backups\sharayu\phase12-theme-snapshot-before.json` (12,330 bytes).
  - Backup `sharayu-20260928-001017-before-phase12.sql` (973,223 bytes, 53 tables, complete). Baseline suite by suite: 1,965 passed, 0 failed, 0 skipped; AS guard PASS; 0 plugin notices; crash count 139 throughout. Clean state confirmed afterwards.
  - Inspected every front-end output: the scan pages, My sales and the error pages are a standalone document (no theme, no `wp_head()`); the plugin prints nothing on shop, product, cart, checkout, My Account or emails. Findings: logged-out non-GET requests get a cacheable-looking 405 page (a WP Fastest Cache hypothesis to test), no `DONOTCACHEPAGE` on scan responses, the permalink notice hidden from shop managers.
  - Wrote the plan (D1–D11): `C:\xampp\backups\sharayu\phase12-plan.txt`. Waiting for approval; nothing implemented.
- **Phase 12 (theme compatibility):**
  - The user approved D1–D11 (with Twenty Twenty-Four), F–H, the Plain-permalink Health check error and Dashboard warning, and D12 (author Mosin Shaikh, shop name removed; a separate earlier commit).
  - D12 done first: header, README and one test URL; "durga" found nowhere in the plugin folder; phase2-main and phase4 passed; its diff saved as `phase12-d12-author.patch`.
  - Prototyped a theme switch and its exact restore (WooCommerce regenerates the placeholder image sizes; WordPress rewrites `sidebars_widgets` and the old theme's mods). Confirmed F1 with WP Fastest Cache (a logged-out PUT poisoned `/scan/` in the cache), then fixed it; added the cache constants, the permalink notice audience, the Health check error and the Dashboard warning.
  - Added `tests/phase12-themes.php`, `tests/theme-check/`, `tests/phase12-repair.php`; updated the Phase 6 and 11 checks (false by design), both READMEs and this file.
  - Development runs: the first spanned a sleep (lid closed at 01:24, woke 09:54; not counted) and left three options, removed after listing; found test-side issues (fixture names containing "pqbg", the caret in screenshots, the document's own 4xx status as a console error, the permalink form's redirect not followed). The second: 272 passed, 2 failed (screenshot anti-aliasing noise; a GD comparison with a 0.01% tolerance added).
  - Final: Phase 12 274/274; every other suite one at a time; Phase 11 re-run after a missed false-by-design count (130/130). Total 2,239 passed, 0 failed, 0 skipped; 0 plugin notices; crash count 139 throughout. The site is back to its start state (Twenty Twenty-Five, `/%postname%/`, no test theme, cache plugin or mu-plugins).
  - Not committed; waiting for the user's review. Report: `C:\xampp\backups\sharayu\phase12-report.txt`.
  - Approved on the automated tests; committed as `ba18693` (D12) and `1b583f9` (Phase 12) and pushed to `origin/main` (normal push).
- **Phase 13 (QA, documentation and packaging), planning:**
  - `HEAD` = `origin/main` = `1b583f9`, clean. Recorded `ba18693` and `1b583f9`. Backup `sharayu-20260928-115709-before-phase13.sql`. Baseline 2,239 passed, 0 failed, 0 skipped; the 50,000-sale stress checks passed (decision C). Plan (A–J, D1–D17): `C:\xampp\backups\sharayu\phase13-plan.txt`.
- **Phase 13 (QA, documentation and packaging):**
  - The owner approved D1–D17, the `pqbg_perf_samples` fix, a PDF of the seller guide with phone screenshots, every licence file listed, and a deferred Hindi/Marathi guide.
  - Version 1.0.0, `readme.txt`, `CHANGELOG.md`, `LICENSE`, the GPL-3.0 text via the vendor build, the .pot (811 strings), the owner and seller guides, `build/package.php`, `phase13-release.php`, `LAUNCH.md`; comment-only cleanups; two suites' uninstall cleanup fixed.
  - Final: 2,269 passed, 0 failed, 0 skipped; crash count 139. Zip 1,382,870 bytes, 252 files, SHA-256 `d9276ef5…3efdf` (test build, reproducible). Fresh install from the zip on a temporary site: 50/50; the site and its database deleted; this site's fingerprint unchanged.
  - Report: `C:\xampp\backups\sharayu\phase13-report.txt`. The owner left both review findings as they are (README note; deferred item); approved; committed, the release zip rebuilt from the commit, tagged `pqbg-v1.0.0` and pushed (normal pushes).
- **Version 1.0.1 (User manual PDF and Help button), planning:**
  - Recorded `8670a30`, `7da1fa8`, the tag `pqbg-v1.0.0` (on `7da1fa8`) and the 1.0.0 release zip SHA-256 `a774011ae6479fbf236d582755469176055109e1fc5851d5a6b665eee12eb8df`.
  - Plan: `C:\xampp\backups\sharayu\manual-plan.txt`. Approved by the owner: D1–D11 as recommended (D9 both links; D10 the upgrade check); anything that would change the sale path, schema or stored data needs the owner first.
  - Backup `sharayu-20260928-191430-before-1.0.1-manual.sql` (1,020,339 bytes, 53 tables, complete). The hashes above confirmed with git after `git fetch`; no difference.
- **Version 1.0.1, implementation:** implemented (see the "Version 1.0.1" section); two manual builds (the second killed by Claude Code for low memory during its cleanup); the leftovers and 92 orphaned category lookup rows removed with the owner's approval; the leak-guard gap for `wp_wc_category_lookup` fixed in the suites and the runner; `docs/owner-guide.md` removed. Second round (approved): post 120414 and the killed build's 422 completed Action Scheduler jobs with 1,266 logs removed; a read-only sweep found nothing else; clean state confirmed. `lookup-guard.php` extended to `wp_wc_product_meta_lookup`; a repair mode added to the manual build. No suite run yet; the build not re-run; nothing committed.

### 2026-09-29
- **Version 1.0.1, testing and release:** repair mode tested (stop file; two hard kills) and fixed (the jobs queued by its own deletes); manual and seller guide rebuilt (55/55, 13/13; 34 pages); fixes: the My sales guide link touch target and screen-reader space, doubled chapter bookmarks, four phase14 test bugs; final tests 2,332 passed, 0 failed, 0 skipped; crash count 139 → 143 (none in plugin code, affected runs repeated). Report: `C:\xampp\backups\sharayu\manual-report.txt`.
- **Owner decision: no further test runs unless the owner asks.** The D10 upgrade check was not run.
- Committed as "Release 1.0.1: user manual PDF, Plugin guide button, seller guide links", the release zip built from the commit into `C:\xampp\backups\sharayu\release\`, tagged `pqbg-v1.0.1` (annotated) and pushed (normal pushes), on the owner's instruction.
