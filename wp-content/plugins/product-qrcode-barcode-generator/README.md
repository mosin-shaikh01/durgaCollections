# Product QR Code and Barcode Generator

WooCommerce plugin for Durga Collections: product QR/barcode inventory for our own shop staff ("sellers").
Staff scan a product's code, see live WooCommerce product information, and mark it sold. Stock updates automatically and every sale is logged.

This is **not** a marketplace or multi-vendor system. Sellers are our own staff selling our own catalog.

## Current scope: Phases 2–4 (foundation, data layer, code generation, rendering)

Implemented:

- plugin bootstrap, PSR-4-style autoloader, requirement checks with an admin notice
- activation, deactivation and uninstall handling
- `pqbg_codes` and `pqbg_sales` tables, versioned migrations and an install lock
- the Store Seller role (`pqbg_seller`) and PQBG capabilities, with a central `Permissions` class
- `CodeRepository`, which enforces one active code per item in the application layer
- WooCommerce HPOS compatibility declaration
- **Phase 3:** `CodeGenerator`, which produces secure random codes, and `ProductCodeService`, which checks product eligibility and assigns codes. See [Product codes](#product-codes).
- **Phase 4:** `ScanUrl`, `QrRenderer` and the optional `BarcodeRenderer`, which turn a product code into SVG. Also `Settings` and an administrator-only settings page. See [QR codes and barcodes](#qr-codes-and-barcodes) and [Settings](#settings).

**Not implemented yet (later phases):**
- the `/scan/` route and scan page (Phase 4 only *builds* scan URLs; they return 404), login redirect flow, product screen
- Mark-as-Sold, stock decrement, sales history and void UI, seller dashboard
- label printing, download/print buttons, CSV import/export, bulk generation and bulk tools
- product admin UI and metaboxes, product lifecycle hooks (automatic code assignment), code regeneration/replacement
- REST/AJAX endpoints, image endpoints, shortcodes and templates

The plugin adds **no** public endpoints of any kind. The only screen is the settings page. The code, QR and barcode classes are a PHP service layer: no hook, screen or endpoint calls them yet.

## QR codes and barcodes

These are the locked decisions from before Phase 4, now implemented:

- **QR code: always available.** It is the primary scan method, using a phone camera.
- **Barcode (Code 128): optional and OFF by default.** One administrator-only setting controls it, "Enable barcodes (for hardware scanners)", which needs `pqbg_manage_settings`.
- **Both carry the same product code**, so turning barcodes on later needs no code regeneration.
- **While barcodes are disabled**, the barcode renderer refuses with `pqbg_barcode_disabled`, and no barcode library class is loaded or executed. The tests verify both.

### Usage

```php
use ProductQrBarcode\{QrRenderer, BarcodeRenderer, ScanUrl};

$svg = ( new QrRenderer() )->render( 'DC-7K4M-9P2X-Q8RT' );      // string (SVG) or WP_Error
$svg = ( new BarcodeRenderer() )->render( 'DC-7K4M-9P2X-Q8RT' ); // string (SVG) or WP_Error
$url = ScanUrl::for_code( 'DC-7K4M-9P2X-Q8RT' );                  // string or WP_Error
```

- **Input:** a code string that must match `CodeGenerator::FORMAT_PATTERN` exactly. Lowercase, whitespace, trailing newlines, ambiguous characters, lookalike Unicode and anything else is rejected with `pqbg_invalid_code` *before* any library is called. Nothing is normalised; trimming and uppercasing typed input is left to the caller (Phase 6).
- **Database:** the renderers check the format only and never look the code up. They never write to the database, never generate codes and never write files. Code creation stays in `ProductCodeService`/`CodeRepository`.
- **No caching:** SVGs are rendered on demand, with no cache and no file storage.
  - Measured on the development machine (PHP 8.5.6 CLI, no JIT): about **50 ms per QR code** and **under 1 ms per barcode**.
  - Almost all of the QR time is bacon's pure-PHP encoder, which scores all eight mask patterns.
  - That is fine for one code at a time. **Bulk label printing (Phase 8) should revisit caching**; for example, 100 labels take about 5 s.
- **Errors:**
  - `pqbg_invalid_code`
  - `pqbg_barcode_disabled`
  - `pqbg_qr_unavailable`: PHP `iconv` extension missing
  - `pqbg_render_failed`: a library exception. The exception class, never the code, is logged to the WooCommerce logger (source `product-qrcode-barcode-generator`).

### QR code

- **Payload: the scan URL only**, `{scan base URL}/scan/{CODE}/`, e.g. `https://example.com/scan/DC-7K4M-9P2X-Q8RT/`.
  - It never contains the product name, SKU, price, stock or any other product data.
  - `ScanUrl` is the **only** class that builds scan URLs, and a test enforces this.
- Error correction **M**, quiet zone of **4 modules**.
- Byte mode in ISO-8859-1. The URL is ASCII-only, so no ECI header is added; some older scanners misread one.
- For a typical production URL this gives a version 4 symbol (33 × 33 modules, 41 × 41 with the quiet zone), displayed at 4 px per module by default.

### Barcode

- **Code 128**, whose content is the product code only (e.g. `DC-7K4M-9P2X-Q8RT`).
- Quiet zone of **10 modules** left and right.
- The code is printed beneath the bars. Displayed at 2 px per module by default.

### SVG output

The libraries only *encode*: the QR bit matrix and the Code 128 bars. `Svg` writes the markup itself, so the output is fully under the plugin's control:

- Only `<svg>`, `<rect>`, `<path>` and `<text>` elements. Attribute values are integers or fixed constants. The only free text is the validated code, escaped for XML.
- No XML prolog, DOCTYPE, `id`s, scripts, event handlers, styles, links or `url()` references. The SVG can therefore be inlined in HTML safely, and more than once per page.
- `role="img"` and `aria-label="{CODE}"`.
- A `viewBox` in module units, with `shape-rendering="crispEdges"`, so CSS or print styles can resize it without blurring.

The libraries' own SVG writers are not used. picqer's has no quiet zone and no human-readable text, references an external DTD and hard-codes `id="bars"`. bacon's needs `XMLWriter`.

## Settings

**WooCommerce → QR & Barcodes** (`wp-admin/admin.php?page=pqbg-settings`) is visible only to users with `pqbg_manage_settings`, which means administrators.
- Shop Managers get neither the menu item nor the page: the direct URL returns 403.
- Store Sellers are kept out of wp-admin by WooCommerce.

It uses the WordPress Settings API:
- The form posts to `options.php`, which checks the `pqbg_settings-options` nonce and, through `option_page_capability_pqbg_settings`, the `pqbg_manage_settings` capability.
- `Settings::sanitize()` cleans the values and changes only the fields on the form. Every other key in `pqbg_settings` is kept.
- The option is not exposed over REST.

| Field | Key in `pqbg_settings` | Default |
|---|---|---|
| Enable barcodes (for hardware scanners) | `barcodes_enabled` (bool) | `false`. Only a stored boolean `true` enables barcodes. |
| Scan base URL | `scan_base_url` (string) | `''`, meaning use the site URL (`home_url()`). The effective URL and an example payload are shown on the page. |

No new option was added, and no migration was needed: defaults are merged on read.

### Scan base URL

- **Accepted:** only absolute `http://` or `https://` URLs of at most 100 characters, with a valid host (DNS name, IPv4 or bracketed IPv6), made of printable ASCII.
- **Rejected:**
  - credentials (`user:pass@`), spaces and control characters
  - path characters other than unreserved characters and `%XX`
  - empty, `.` and `..` path segments
- **Normalised:** the scheme and host are lowercased, and any trailing slash, query string and fragment are removed. The port and path are kept.
- An invalid value shows an error and **keeps the previous value**.

**Codes are stored, URLs are not.** Only the base URL is stored, never a full scan URL. Changing it never touches `pqbg_codes`, and the tests check this with a checksum of the table.

### Local-address warning

While the effective base URL points to an address phones outside the shop can't reach, users with `pqbg_manage_settings` see this notice on every admin screen:

> QR codes currently point to a local address. Do not print labels until the production URL is set.

"Local" means:
- `localhost` and `*.localhost`, `*.local`, `*.test`
- single-label hostnames such as `intranet`
- any IP outside the global range: `127.0.0.0/8`, `::1`, `10/8`, `172.16/12`, `192.168/16`, `169.254/16`, `fc00::/7`, `fe80::/10`, `0.0.0.0` and other reserved ranges

The development site (`http://localhost/sharayu`) shows it.

### http:// warning

When the effective base URL points to a **public** host over plain `http://`, the same users see a separate notice instead:

> Labels should use an https:// scan URL in production.

- This is a warning only. An `http://` URL is valid and is saved.
- At most one of the two notices is shown, and the local-address warning takes priority. A local `http://` address, such as the development site, shows only the local-address warning.

## Bundled libraries

The libraries are bundled inside the plugin; no Composer is needed on the server.

- Their namespaces are **prefixed** under `ProductQrBarcode\Vendor\` with PHP-Scoper, so another plugin bundling the same library can't conflict.
- Each file got a `defined( 'ABSPATH' ) || exit;` guard, so a direct HTTP request returns empty output.
- The prefixed runtime code lives in `vendor-prefixed/`. It is autoloaded only when a class is first used.
- Details are in `vendor-prefixed/NOTICE.md`.

| Package | Version | License | Used for | PHP | Last release (at bundling) |
|---|---|---|---|---|---|
| `bacon/bacon-qr-code` | 3.1.1 | BSD-2-Clause | QR encoding | `^8.1` | 2026-04-05 |
| `dasprid/enum` | 1.0.7 | BSD-2-Clause | dependency of bacon | `>=7.1 <9.0` | 2025-09-16 |
| `picqer/php-barcode-generator` | 3.3.0 | LGPL-3.0-or-later | Code 128 encoding | `^8.2` | 2026-08-22 |

- **SVG only:** none of them needs GD or Imagick for the output used here. bacon needs `ext-iconv`.
- **PHP 8.5.6:** all three were checked with every error reported, and raised no notices.
- **Licenses:** BSD-2-Clause is compatible with the plugin's GPL-2.0-or-later.
  - LGPL-3.0-or-later is compatible only through the "or later" clause (it isn't compatible with GPL-2.0-only), so the plugin as distributed is effectively under GPLv3 terms.
  - Prefixing modifies the libraries. Each keeps its original license file next to its `src/`, and `NOTICE.md` records the modifications.
- **PHP minimum raised to 8.2** in Phase 4, because picqer's maintained 3.x line requires it. PHP 8.1 has been end-of-life since 31 Dec 2025.

**Rebuilding** `vendor-prefixed/` from the pinned `build/composer.lock` is described in [`build/README.md`](build/README.md). The build is reproducible, and the build tools are pinned by SHA-256.

## Tests

The CLI regression suites for Phases 2–4 are in [`tests/`](tests/README.md): a runner, round-trip QR/barcode decoding, and settings access checked over HTTP. `tests/` and `build/` are never loaded by the plugin, their PHP files exit outside the CLI, and `.htaccess` denies them over HTTP.

## Production deployment

**Exclude `tests/` and `build/` from any production deployment.** Deploy only the runtime files:

- `product-qrcode-barcode-generator.php`, `uninstall.php`, `index.php`
- `includes/`, `languages/`, `vendor-prefixed/`
- `README.md` (optional)

`tests/` and `build/` are development tooling. They are kept in the repository so the vendor bundle can be rebuilt exactly and the regression suites can be rerun, but they must never reach a live server:
- The test suites create and delete data, and one briefly deactivates the plugin.
- The `.htaccess` denial only works on Apache.
- `tests/decoder/node_modules/`, `build/vendor/` and `build/tools/` are never committed and must not be deployed either.

## Naming and the rename

This plugin was developed as **"Durga Product Codes"** and was renamed before its first real use. The name is generic, so the header sets `Update URI: false`. That stops WordPress from ever offering a wordpress.org plugin with the same slug as an update that would overwrite this one.

There is **no legacy migration code**. The old tables held no data, so they were removed with a one-time CLI script that was not committed, and the renamed plugin rebuilt its schema through the normal installer.

| Kind | Before | After |
|---|---|---|
| Display name | Durga Product Codes | Product QR Code and Barcode Generator |
| Folder / main file | `durga-product-codes/durga-product-codes.php` | `product-qrcode-barcode-generator/product-qrcode-barcode-generator.php` |
| Text domain, log source | `durga-product-codes` | `product-qrcode-barcode-generator` |
| PHP namespace | `Durga\ProductCodes` | `ProductQrBarcode` |
| Constants | `DPC_VERSION`, `DPC_PLUGIN_FILE`, `DPC_PLUGIN_DIR`, `DPC_PLUGIN_URL`, `DPC_UNINSTALL_DELETE_ALL_DATA` | `PQBG_VERSION`, `PQBG_PLUGIN_FILE`, `PQBG_PLUGIN_DIR`, `PQBG_PLUGIN_URL`, `PQBG_UNINSTALL_DELETE_ALL_DATA` |
| Tables | `{prefix}dpc_codes`, `{prefix}dpc_sales` | `{prefix}pqbg_codes`, `{prefix}pqbg_sales` (identical structure) |
| CHECK constraint | `{prefix}dpc_codes_active_chk` | `{prefix}pqbg_codes_active_chk` |
| Options | `dpc_db_version`, `dpc_settings`, `dpc_install_lock` | `pqbg_db_version`, `pqbg_settings`, `pqbg_install_lock` |
| Capabilities | `dpc_view_products`, `dpc_sell`, `dpc_view_own_sales`, `dpc_view_all_sales`, `dpc_void_sale`, `dpc_manage_codes`, `dpc_manage_settings` | the same seven with the `pqbg_` prefix, and the same role matrix |
| Role | `dpc_seller` ("Seller") | `pqbg_seller` ("Store Seller") |
| Nonces | action `dpc_<verb>`, field `_dpc_nonce` | action `pqbg_<verb>`, field `_pqbg_nonce` |
| `WP_Error` codes | `dpc_*` | `pqbg_*` |

**Not renamed:**
- the product code format `DC-XXXX-XXXX-XXXX`: "DC" is the store brand, Durga Collections
- the alphabet and all generator logic
- every column, index and business rule, and `DB_VERSION` (still 1)
- `Author: Durga Collections` and other references to the store

## Product codes

### Format

```
DC-XXXX-XXXX-XXXX        e.g. DC-7K4M-9P2X-Q8RT
```

- The prefix `DC-` is fixed. It is followed by 3 groups of 4 characters separated by hyphens, 17 characters in total.
- The alphabet is `CodeGenerator::ALPHABET`, which has 31 symbols:

  ```
  ABCDEFGHJKMNPQRSTUVWXYZ23456789
  ```

  That is A–Z without `I`, `L` and `O`, plus 2–9 without `0` and `1`. It is uppercase only and uses no punctuation other than the group hyphens. The excluded characters are easy to confuse when printed, scanned or typed by hand.
- The strict format is `CodeGenerator::FORMAT_PATTERN` = `/^DC(-[A-HJKMNP-Z2-9]{4}){3}$/D`. Its character class matches the alphabet exactly. The `D` modifier rejects a trailing newline.
- There are 31¹² ≈ 7.9 × 10¹⁷ possible codes (about 59 bits).

`CodeRepository::CODE_PATTERN` is intentionally broader: 4–32 characters of `A-Z 0-9 -`. It is the storage-level sanity check. `CodeGenerator::is_valid_format()` is the check for the `DC-` format.

### Randomness

- Every character is picked with PHP's `random_int()`, a CSPRNG, as an index into the alphabet.
- No product data (ID, SKU, name), timestamp, `rand()`/`mt_rand()`/`uniqid()`/`microtime()` or hash of predictable values is involved.
- `generate()` takes no input, so a code can't be derived from the product it is assigned to.
- If the random source fails, the result is a controlled `WP_Error` (`pqbg_random_unavailable`), never a weaker fallback.

### Product eligibility

A code identifies the **purchasable item**.

| WooCommerce object | Code | `parent_id` stored |
|---|---|---|
| Simple product | Yes | `0` |
| Variation (parent is a variable product) | Yes, one per variation | the variable parent's ID |
| Variable parent | **No**. It is only the container for its variations. | — |
| Grouped product | No | — |
| External/affiliate product | No | — |
| Variation whose parent is missing or not variable | No | — |
| Any other or custom product type | No. Nothing unsupported gets a code silently. | — |
| ID that is not a WooCommerce product | No (`pqbg_invalid_product`) | — |

The product type is resolved through `wc_get_product()` and `WC_Product::is_type()`, not raw post data.

**Product status** (draft, private, published and so on) is **not** checked. No status rule has been decided yet. It will be settled with admin code management (Phase 5).

"Purchasable" means the sellable unit, not WooCommerce's `is_purchasable()`, which depends on price and status and changes over time.

### Assigning a code

```php
use ProductQrBarcode\ProductCodeService;

$row = ( new ProductCodeService() )->get_or_create( $product_or_variation_id, get_current_user_id() );
// array (the pqbg_codes row) on success, WP_Error otherwise.
```

- **Authorization.** The acting `$user_id` must have `pqbg_manage_codes` (Shop Manager, Administrator). Otherwise the call returns `pqbg_forbidden`. The check uses the user passed in, not the current user.
- **Idempotent.** If the item already has an active code, that row is returned unchanged. No new code is generated and no second active code is created.
- **Eligibility** errors are `pqbg_invalid_product` and `pqbg_ineligible_product`. Nothing is written.
- All persistence goes through `CodeRepository::create_active()`. The generator and service contain no SQL.
- The code is stored only in `pqbg_codes.code`, never in product meta, options or order data.

Phase 3 registers **no hooks**. Codes are not created automatically on product save or creation. A caller (the Phase 5 admin screens, or bulk tools later) must request them explicitly.

### Uniqueness and collision handling

Codes are globally unique across the whole `pqbg_codes` table, including retired codes. Three layers enforce this:

1. **Random generation.** A single collision is extremely unlikely.
2. **Pre-insert check.** `CodeGenerator::generate_unique()` asks `CodeRepository::code_exists()`, which counts both active and retired codes. On a collision it draws a new candidate. It stops after `CodeGenerator::MAX_ATTEMPTS = 10` and returns `pqbg_code_generation_failed`.
   - Why 10: even with a million stored codes, one candidate collides with probability about 1.3 × 10⁻¹². Ten collisions in a row means a broken random source or bad data. Failing loudly is safer than looping.
3. **`UNIQUE(code)` in the database.** This is the final authority. The check-then-insert sequence can race with another request. If the insert hits the unique index, `create_active()` rolls back and returns `pqbg_code_conflict`. The service then:
   - uses the active code another request just assigned to the same item, if there is one
   - otherwise retries with a fresh code, at most `ProductCodeService::MAX_SAVE_ATTEMPTS = 3` times
   - if it still fails, returns `pqbg_code_generation_failed`

A failed generation writes no row, never reuses an existing code and never changes other records. It is logged to the WooCommerce logger (source `product-qrcode-barcode-generator`) with the error code only.

The column collation (`utf8mb4_unicode_520_ci`) is case-insensitive, so a lowercase copy of an existing code is also rejected.

### Lifecycle: active → retired

- `active`: the item's current code. There is at most one per item.
- `retired`: kept forever for history. It is **never reactivated** (there is no method for that) and **never reissued**, to the same item or any other, because `code_exists()` and `UNIQUE(code)` both still see it.
- After `CodeRepository::retire()`, the next `get_or_create()` for that item generates a **new** code.
- There is no `orphaned` status.

**Regeneration is deferred.** An atomic "replace code" operation (retire the old code and create the new one in a single transaction, behind `pqbg_manage_codes`) is not needed until codes can be managed in the admin area. It belongs to Phase 5. Until then, retire followed by `get_or_create()` is the only path.

### Code map

| Class | Responsibility |
|---|---|
| `CodeGenerator` | randomness, alphabet, format, collision retry. It never touches the database directly. |
| `CodeRepository` | persistence and queries, the active/retired state, the one-active-code invariant |
| `ProductCodeService` | authorization, WooCommerce eligibility, mapping an item to its row, retrying on database conflicts |

## Requirements

| | Minimum | Tested |
|---|---|---|
| WordPress | 6.7 | 7.1.2 |
| PHP | 8.2 (raised from 8.1 in Phase 4) | 8.5.6 |
| WooCommerce | 9.0 | 11.1.2 (HPOS on) |
| Database | MariaDB 10.2+ / MySQL 5.7+ | MariaDB 10.4.32 |

WooCommerce must be active. The `Requires Plugins: woocommerce` header makes WordPress enforce this at activation.
If WooCommerce is later deactivated, this plugin does nothing except show an admin notice to users who can manage plugins.

## Installation

1. Copy the plugin to `wp-content/plugins/product-qrcode-barcode-generator/`.
2. Activate it under **Plugins**. Network activation on multisite is refused; activate it per site.

Activation creates or updates the tables, runs pending migrations, creates `pqbg_settings` and syncs roles and capabilities. It is safe to run repeatedly.

## Database

Tables use `$wpdb->prefix`. They are created with `dbDelta()` from `includes/Schema.php`, and all timestamps are UTC (`*_gmt`).

### `{prefix}pqbg_codes`

One row per code ever issued. Rows are retired, never deleted.

| Column | Notes |
|---|---|
| `id` | bigint unsigned, PK |
| `code` | varchar(32), UNIQUE, uppercase `A-Z 0-9 -`, 4–32 chars (e.g. `DC-XXXX-XXXX-XXXX`) |
| `kind` | `product` (the only kind in the MVP; the column is reserved for a possible future `unit`) |
| `product_id` | the purchasable item: a simple product or a **variation** |
| `parent_id` | the variation's parent product, `0` for simple products |
| `active_product_id` | equals `product_id` while the code is active, otherwise NULL |
| `status` | `active` or `retired` |
| `created_at_gmt`, `created_by` | |
| `retired_at_gmt`, `retired_by` | NULL until retired |

Indexes: `code` (unique), `active_product_id` (unique), `product_status (product_id,status)`, `parent_id`, `status`.

**Invariant: one active code per item.** Three layers enforce it:

1. **`CodeRepository`, in the application.** It rejects a second active code and retires by clearing `active_product_id` in the same UPDATE. It deliberately has no "reactivate", so a retired code can never become active again. Replacing a code means retiring it and creating a new one.
2. **`UNIQUE(active_product_id)`.** InnoDB allows any number of NULLs, so this allows at most one active row per item.
3. **CHECK constraint `{prefix}pqbg_codes_active_chk`, where the server supports it** (MariaDB 10.2+, MySQL 8.0.16+). Active product rows must have `active_product_id = product_id`; all other rows must have NULL. It is added by migration 1 if missing and skipped quietly on servers that don't support it.

### `{prefix}pqbg_sales`

The future Mark-as-Sold ledger. It is empty in Phase 2.

`product_id` and `variation_id` follow WooCommerce's order-item convention: `product_id` is the simple product or the variation's parent, and `variation_id` is the variation (`0` for simple products).
Note that this differs from `pqbg_codes.product_id`, which is the purchasable item itself.

Main columns:

- `request_id` char(36): UNIQUE idempotency key
- `code_id`
- `unit_id` and `order_id`: nullable and reserved
- `seller_id`, `quantity`
- `unit_price`, `regular_price`, `line_total`: decimal(26,8)
- `currency` char(3)
- snapshots: `product_name`, `sku`, `attributes_json`
- `stock_before`, `stock_after`
- `source` (default `scan`), `status` (default `completed`)
- void fields, `note`, `created_at_gmt`

Indexes: `request_id` (unique), `code_id`, `product_variation`, `seller_created`, `status_created`, `created_at_gmt`, `order_id`.

**Rules for the future Mark-as-Sold phase:**

- The price always comes from the current server-side `WC_Product` price. A browser-supplied price or stock value is never trusted.
- Stock is checked server-side, and insufficient stock blocks the sale even if backorders are enabled.
- No WooCommerce orders are created for these sales.

### Options

| Option | Autoload | Purpose |
|---|---|---|
| `pqbg_db_version` | yes | integer schema version (currently `1`) |
| `pqbg_settings` | no | settings array (`settings_version`, `barcodes_enabled`, `scan_base_url`); read via `Plugin::settings()` / `Settings::get()` (defaults merged with `wp_parse_args`, unknown keys dropped). See [Settings](#settings). |
| `pqbg_install_lock` | no | short-lived install/migration lock; exists only while an install is running |

## Migrations

`Install::migrations()` maps each target version to a callback.

- On every boot, `Install::maybe_upgrade()` runs any migration newer than `pqbg_db_version`, in order.
- The stored version moves forward only after each migration succeeds.
- If the stored version is newer than the code, nothing runs (no downgrade).
- If the tables are missing but a version is stored, the schema is rebuilt from migration 1.

To add migration N:

1. Update `Schema::statements()` to the new current schema.
2. Add `N => [ Install::class, 'migrate_N' ]` and set `Install::DB_VERSION = N`.
3. Make `migrate_N()` idempotent: call `Schema::create_or_update()`, then run any guarded data transforms.

Migrations never drop tables or delete rows.

**The install lock** is an atomic `INSERT IGNORE` row in the options table. `add_option()` is not used because it runs `INSERT … ON DUPLICATE KEY UPDATE` and is therefore not atomic.
The lock expires after 5 minutes, so a crashed request cannot block upgrades permanently.
The lock is released in a `finally` block, using a compare-and-delete on its own token.

## Capabilities

| Capability | Store Seller (`pqbg_seller`) | Shop Manager | Administrator |
|---|:-:|:-:|:-:|
| `pqbg_view_products` | ✔ | ✔ | ✔ |
| `pqbg_sell` | ✔ | ✔ | ✔ |
| `pqbg_view_own_sales` | ✔ | ✔ | ✔ |
| `pqbg_view_all_sales` | | ✔ | ✔ |
| `pqbg_void_sale` | | ✔ | ✔ |
| `pqbg_manage_codes` | | ✔ | ✔ |
| `pqbg_manage_settings` | | | ✔ |

The Store Seller role also has `read`. It has nothing else: no `edit_products`, `manage_woocommerce` or `edit_posts`.

Role sync (`Permissions::sync_roles()`) only adds or removes `pqbg_*` capabilities, and only on these three roles. Every other capability is left untouched.
WooCommerce only lets Shop Managers assign the `customer` role, so only Administrators can make someone a Store Seller.

### Rules for later phases

- Check permissions through `Permissions::can_*()` or its constants, server-side, on every privileged operation.
- A REST `permission_callback` must use them. Never use `__return_true` for privileged routes.
- Logged-out users must never receive product or sales data.
- Nonces:
  - action: `Permissions::nonce_action( 'verb_object' )`, which gives `pqbg_verb_object`
  - field: `_pqbg_nonce`
  - REST requests use the core `wp_rest` nonce
  - A nonce check is always paired with a capability check.
- `CodeRepository` does not check capabilities itself. Callers must check `Permissions::can_manage_codes()` first.

## HPOS

The plugin declares compatibility with the `custom_order_tables` feature through `FeaturesUtil::declare_compatibility()` on `before_woocommerce_init`.
It never reads or writes orders or the legacy order tables.

## Deactivation

Deactivation is non-destructive. Tables, codes, sales, settings, the role and capabilities are all kept. The plugin adds no rewrite rules or cron events (as of Phase 4), so nothing needs flushing.

## Uninstall

**By default, all data is preserved.** Deleting the plugin from the Plugins screen removes only the transient install lock. Tables, sales history, product codes, options, the Store Seller role and capabilities remain, and reinstalling picks them up again.

To permanently delete all plugin data, add this to `wp-config.php` **before** deleting the plugin:

```php
define( 'PQBG_UNINSTALL_DELETE_ALL_DATA', true );
```

This drops `pqbg_codes` and `pqbg_sales`, deletes `pqbg_settings` and `pqbg_db_version`, removes every `pqbg_*` capability, and deletes the Store Seller role. Affected users keep their accounts.
**This cannot be undone. Back up the database first.** On multisite, only the site running the uninstall is affected.

## Operational notes

- QR codes point to the scan base URL: `home_url()` unless it is overridden on the settings page. **Do not print labels until the production URL is set.** The admin warning stays visible while the URL is local, and a second warning appears while a public URL uses `http://`.
- The site timezone is currently UTC. The plugin stores UTC regardless, but the store timezone (India) should be set deliberately.
- WooCommerce "Coming Soon" mode is on for the whole site. The future `/scan/` route must work with it.
