# Product QR Code and Barcode Generator

WooCommerce plugin for Durga Collections: product QR/barcode inventory for our own shop staff ("sellers").
Staff scan a product's code, see live WooCommerce product information, and mark it sold. Stock updates automatically and every sale is logged.

This is **not** a marketplace or multi-vendor system. Sellers are our own staff selling our own catalog.

## Current scope: Phases 2–6 (foundation, data layer, code generation, rendering, admin code management, scan page)

Implemented:

- plugin bootstrap, PSR-4-style autoloader, requirement checks with an admin notice
- activation, deactivation and uninstall handling
- `pqbg_codes` and `pqbg_sales` tables, versioned migrations and an install lock
- the Store Seller role (`pqbg_seller`) and PQBG capabilities, with a central `Permissions` class
- `CodeRepository`, which enforces one active code per item in the application layer
- WooCommerce HPOS compatibility declaration
- **Phase 3:** `CodeGenerator`, which produces secure random codes, and `ProductCodeService`, which checks product eligibility and assigns codes. See [Product codes](#product-codes).
- **Phase 4:** `ScanUrl`, `QrRenderer` and the optional `BarcodeRenderer`, which turn a product code into SVG. Also `Settings` and an administrator-only settings page. See [QR codes and barcodes](#qr-codes-and-barcodes) and [Settings](#settings).
- **Phase 5:** automatic code assignment on product save, lifecycle rules (trash, delete, type change), atomic regeneration, and the admin UI on the classic product screens: a "QR & Barcode" panel, codes in the variations panel, a "Code" column, SVG downloads. See [Admin code management](#admin-code-management).
- **Phase 6:** the front-end scan page `/scan/{CODE}/` and the entry page `/scan/`: login round trip, access control, the status → screen matrix, live product details, a standalone mobile template. Read-only. See [Scan page](#scan-page).

**Not implemented yet (later phases):**
- Mark-as-Sold, stock decrement, sales history and void UI, seller dashboard
- label printing and layouts, CSV import/export of codes, bulk generation and bulk tools
- caching of rendered images (planned for Phase 8)
- in-browser camera scanning
- REST/AJAX endpoints and shortcodes, and support for WooCommerce's block-based product editor

The plugin adds **no REST routes, AJAX handlers or shortcodes**. Its request handlers are:
- the authenticated `admin-post.php` actions of Phase 5, for users with `pqbg_manage_codes` (see [Admin handlers](#admin-handlers))
- the read-only scan page of Phase 6, which requires a login and `pqbg_view_products` before it shows anything (see [Scan page](#scan-page))

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

The CLI regression suites for Phases 2–6 are in [`tests/`](tests/README.md): a runner, round-trip QR/barcode decoding, and settings access checked over HTTP. `tests/` and `build/` are never loaded by the plugin, their PHP files exit outside the CLI, and `.htaccess` denies them over HTTP.

## Production deployment

**Exclude `tests/` and `build/` from any production deployment.** Deploy only the runtime files:

- `product-qrcode-barcode-generator.php`, `uninstall.php`, `index.php`
- `includes/`, `assets/`, `languages/`, `templates/`, `vendor-prefixed/`
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

**Product status** (Phase 5 rule): an **auto-draft** (the unsaved post WordPress creates for "Add new") never gets a code, and neither does a variation whose parent is an auto-draft (`pqbg_ineligible_status`). Drafts, pending, private and published items are eligible. Trashed items keep their codes (see [Lifecycle](#lifecycle)).

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

Since Phase 5, codes are also assigned automatically when products are saved; see [Automatic assignment](#automatic-assignment). That path calls the same `get_or_create()`.

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

**Regeneration** (Phase 5) replaces a code atomically: see [Regeneration](#regeneration).

### Code map

| Class | Responsibility |
|---|---|
| `CodeGenerator` | randomness, alphabet, format, collision retry. It never touches the database directly. |
| `CodeRepository` | persistence and queries, the active/retired state, the one-active-code invariant |
| `ProductCodeService` | authorization, WooCommerce eligibility (type and the auto-draft rule), mapping an item to its row, retrying on database conflicts, `regenerate()`, and `retire_for_item()` for the lifecycle |
| `CodeLifecycle` | the save and delete hooks: automatic assignment, the parent sweep, retirement on delete and type change, the save-failure notice |
| `AdminProductPanel` | the classic product screens: meta box, variation panel text, list column, regeneration confirmation page |
| `AdminActions` | the authenticated `admin-post.php` handlers: generate, regenerate, QR/barcode SVG |

## Admin code management

Phase 5. Everything in this section needs `pqbg_manage_codes`, which Administrators and Shop Managers have. Store Sellers and logged-out users get nothing.

### Supported editor

**Only WooCommerce's classic product edit screen is supported.** The block-based product editor (the `product_block_editor` feature) is disabled on this site and was verified off. The panel, the variation text and the handlers were built and tested for the classic screens only.

### Automatic assignment

A code is generated on the **first real save** of an eligible item (a simple product or a variation). That includes drafts, pending and private items. Auto-drafts and revisions never get one.

- **Acting user = the current user.** If they lack `pqbg_manage_codes`, or there is no user (cron, CLI), nothing is generated and nothing fails. The item stays without a code until someone permitted saves it or clicks **Generate code**.
- **The save is never blocked or changed.**
  - A generation failure is logged to the WooCommerce logger by error code only.
  - The saving user sees a dismissible notice on their next admin page.
- **Idempotent.** An item that has a code keeps it; repeated saves never create a second one.
- **Parent sweep.** Every eligible save of a variable product also assigns any of its variations that still lack a code. This covers:
  - a product's first save
  - simple → variable
  - duplicates
  - variations added before the parent was saved

**Hooks.** WooCommerce CRUD hooks, verified in WooCommerce 11.1.2:

| Hook | Fired by |
|---|---|
| `woocommerce_new_product`, `woocommerce_update_product` | `WC_Product_Data_Store_CPT::create()`/`update()` |
| `woocommerce_new_product_variation`, `woocommerce_update_product_variation` | `WC_Product_Variation_Data_Store_CPT::create()`/`update()` |

- They fire after WooCommerce has saved the object, its type and its parent.
- Every path that goes through `WC_Product::save()` reaches them:
  - the classic edit screen
  - **Add variation** and **Save changes** in the variations panel (AJAX)
  - Quick Edit and Bulk Edit
  - the CSV importer
  - the REST API
  - **Duplicate**
- `save_post` was rejected for three reasons:
  - it fires for the auto-draft WordPress creates on "Add new"
  - it fires for revisions
  - on the edit screen it fires before WooCommerce has written the product type
- Code that writes products with `wp_insert_post()` directly, bypassing WooCommerce, is not covered. Such items get a code on their next WooCommerce save, or manually.

**Skipped statuses:** `auto-draft`, `trash`, and `importing` (the CSV importer's placeholder). A variation is also skipped while its parent is in one of those states.

**New variations added with "Add variation" get their code immediately**, provided the parent is already saved as a variable product.
- A variation added to a product that has never been saved gets its code on the product's first real save, through the parent sweep.
- The same applies to a product that is still `simple` in the database: WooCommerce's "Add variation" only forces the type to variable in memory.

**CSV import:** WooCommerce 11.1.2 itself refuses a new variation whose parent row has not been imported yet, so variations always arrive after their parent.

### Lifecycle

| Event | Codes |
|---|---|
| Trash | Stay **active**, so a restored product keeps working labels. WooCommerce also trashes a variable product's variations; their codes stay active too. |
| Untrash | The same code, unchanged. |
| Permanent delete (post.php, Empty Trash, REST `force=true`, WooCommerce data stores, auto-draft cleanup) | The active code is **retired**. `retired_by` = the acting user, or 0 when there is none. Deleting a variable product deletes its variations, and every variation code is retired. |
| simple → variable / grouped / external | The product's code is retired. For variable, its variations get codes (parent sweep). |
| variable → simple | WooCommerce deletes the variations, and their codes are retired. The product becomes eligible and gets a **new** code. |
| variable → grouped / external | Variation codes are retired as they are deleted. The product gets none. |
| Duplicate | The copy, and each copied variation, gets its **own new code**. Codes live only in `pqbg_codes`, never in meta, so nothing is copied. |

- Deletion is detected with WordPress core's `deleted_post`, which fires after the row is really gone. As a safety net, deleting a product also retires any code still active under it as `parent_id`.
- **Retirement on delete or type change is not gated by `pqbg_manage_codes`.** WordPress has already authorised the delete or save, and a deleted or ineligible item must never keep an active code.
- **Generation always is gated.**
- Retired codes are never reactivated or reused.

### Regeneration

> **Printed labels with the old code will stop working.**

`ProductCodeService::regenerate( $item, $user, $expected_code_id )` → `CodeRepository::replace_active()` does the following in **one transaction**:

1. It locks the item's active row (`SELECT … FOR UPDATE`).
2. It retires the row, setting `retired_at_gmt`/`retired_by`.
3. It inserts a new active code.

**Guarantees:**
- never two active codes
- never zero active codes after success
- no partial state on failure: any failed step rolls back, and the original code stays active
- concurrent regenerations of the same item serialise on the lock and end with exactly one active code
- the old code stays in the history

**Double submits.** The confirmation form carries the code ID it showed. If the item's code has changed since (a double click or a second tab), nothing is regenerated and the user is told (`pqbg_code_changed`).

**UI.** **Regenerate…** opens a confirmation page (GET, no side effects) that states the warning above, the product and the current code. Its button POSTs with a nonce and needs `pqbg_manage_codes`.

### Product edit screen: "QR & Barcode" panel

| Product | Panel |
|---|---|
| Simple, with a code | The code, its QR code (inline SVG), the barcode if barcodes are enabled, **Download QR (SVG)**, **Download barcode (SVG)** (only if enabled), **Regenerate…** |
| Simple, no code yet | "No code yet." and **Generate code** |
| Not saved yet (auto-draft) | "Save the product to assign its product code." |
| Variable | No code for the product itself. A table of its variations (attributes, SKU, code, status) with **View QR** (loaded on click), **Download QR**, **Download barcode** (if enabled), **Regenerate…** or **Generate code** per variation. |
| Grouped, external | A short explanation, no buttons. |

- **History:** a read-only list of retired codes, with the code, "Retired at" and "Retired by".
  - For a variable product it covers all its variations, including deleted ones.
  - Times are stored in GMT and **displayed in the site timezone** with `wp_date()` and the site's date and time formats. The column header names the timezone.
  - "Retired by" shows the user's display name, **"System"** when `retired_by` is 0, or **"User #ID (deleted)"** when the user no longer exists.
- Retired codes appear only as text. They are never rendered or served.
- **Variations panel:** inside each variation, the code text with **View QR** and **Download QR** links. No image is rendered when the panel loads.
- **Products list:** a **Code** column with the code text, or "—" (variable, grouped and external products, and items without a code). It uses one query per page and shows no images.
- The Generate buttons sit inside the product form, and HTML forms can't be nested. They therefore submit small POST forms printed after it in the page footer, via the HTML `form` attribute.

**Performance.** QR rendering takes about 45–50 ms per code, so the edit screen renders **at most one QR code** synchronously (a simple product's).
- Variation QR codes load only when **View QR** is clicked, as an `<img>` pointing at the authenticated view handler. Without JavaScript the link opens the SVG in a new tab.
- The variations table loads its variations with one post query and one meta query.
- Measured for a product with 40 variations, on the dev machine:
  - panel render about 20–55 ms in-process (median of 5; it varies between runs)
  - edit page about 625 ms with the panel vs about 540 ms for the same page without it (medians of 5 HTTP requests, including WooCommerce's own screen). The overhead was 85–150 ms across runs.
  - 0 QR codes rendered on load

### Admin handlers

Handled by `admin-post.php`. Only `admin_post_*` is hooked, never `admin_post_nopriv_*`.

| Action | Method | Does |
|---|---|---|
| `pqbg_generate` | POST | Assigns a code to an item that has none (`get_or_create()`); redirects back with a message. |
| `pqbg_regenerate` | POST | Atomic regeneration, with the expected code ID from the confirmation page. |
| `pqbg_code_image` | GET | `type=qr` or `barcode`, `mode=view` or `download`. Serves the SVG of the item's **active** code. No side effects. |

The confirmation page is a hidden admin page, `edit.php?post_type=product&page=pqbg-regenerate&item=ID&_pqbg_nonce=…`. It needs `pqbg_manage_codes` and a nonce, and it validates before any output.

**Every handler:**
- checks the method: **405** otherwise, so GET can never generate or regenerate
- requires `pqbg_manage_codes` (**403**)
- requires a nonce **bound to the item** (`pqbg_<verb>_<ID>`, field `_pqbg_nonce`); a missing, invalid or other item's nonce gets **403**
- resolves the item server-side by ID: no handler accepts a code string, so a retired code can never be served. An unknown item or an item without an active code gets **404**.
- Barcode requests while barcodes are disabled get **404**, checked before anything else, so the barcode library stays unloaded.

**Image response headers:**
- `Content-Type: image/svg+xml; charset=utf-8`
- `X-Content-Type-Options: nosniff`
- `Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private`
- `Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox`
- `Content-Disposition: attachment; filename="{CODE}-qr.svg"` or `"{CODE}-barcode.svg"`. View mode uses `inline`.

**Who gets refused:**
- Store Sellers get **403**. WooCommerce's wp-admin redirect deliberately exempts `admin-post.php`, so the capability check is what refuses them.
- Logged-out users get core's **400**, because there is no `nopriv` handler.

**Barcodes disabled:** no barcode UI anywhere, and the barcode handler refuses. No barcode library class is loaded on any Phase 5 screen or path, and the tests verify this.

## Scan page

Phase 6. When staff scan a label's QR code with a phone camera, or type or scan a code into the page, they see the live WooCommerce product for that code. **Read-only:** nothing is sold, no stock changes, and the page writes nothing to the database.

- Classes: `ScanRoute` (route, access, redirects, headers, login redirects, admin notices), `ScanScreen` (code → screen, rendering) and `ScanUrl` (every scan URL and the rewrite patterns).
- Template: `templates/pqbg-scan.php`. Stylesheet: `assets/pqbg-scan.css`.

### URLs

| URL | What it is |
|---|---|
| `{home}/scan/{CODE}/` | The product screen for a code. **This is the label payload** (`ScanUrl::for_code()`), so its format is permanent. |
| `{home}/scan/` | The entry page: a "Scan or type a code" box. |
| `{home}/scan/?code=…` | What the box submits (GET). Redirects to `/scan/{CODE}/`. |

- **Rewrite rules:** two, `^scan/?$` and `^scan/(.+?)/?$`, added at the top so they take precedence over pages and posts.
  - **Everything below `/scan/` belongs to the plugin.** `/scan/a/b/` shows "Not a valid product code", not a theme 404.
  - They work in the subdirectory install (`/sharayu/scan/…`), because WordPress matches rules relative to the home path.
- **Flushing:** once, on activation, and on the first request after the plugin version or `ScanRoute::RULES_VERSION` changes (tracked in the option `pqbg_rewrite_version`).
  - Ordinary requests never flush.
  - The flush is soft: `.htaccess` is not rewritten.
  - Deactivation removes the rules and the flag.
- **Query vars:** `pqbg_scan` and `pqbg_code`.

### Request flow

The plugin answers on `parse_request`. That is before the main query, WordPress's canonical redirects, `template_redirect`, the theme, and WooCommerce Coming Soon (which acts at `template_include`).

| Step | Response |
|---|---|
| Permalinks are Plain or contain `index.php` | Not handled (see [Permalinks](#permalinks)). |
| Method other than GET or HEAD | **405** with `Allow: GET, HEAD`. |
| Logged out | **302** to `wp_login_url()`. `redirect_to` is the canonical scan URL, or `/scan/` when the path is not a well-formed code. The code's existence is never checked. |
| Logged in without `pqbg_view_products` (customers, subscribers) | **403**: one fixed page with no box and no product data. No lookup runs, so the response is byte-identical for existing, retired, unknown and invalid codes, and for the entry page. |
| Path not canonical: lowercase code, spaces, missing trailing slash, any query string, or raw `?pqbg_code=` | **301** to `{home}/scan/{CODE}/`. |
| Entry box `?code=` | The input is trimmed, stripped of all whitespace and uppercased; a pasted URL gives the segment after `/scan/`. A well-formed code gets **302** to `/scan/{CODE}/`. Anything else gets **400** "Not a valid product code.", with the input (escaped) back in the box. |
| Otherwise | The status → screen matrix below. |

### Status → screen matrix

| Code / item | Screen | HTTP |
|---|---|---|
| Not a well-formed code | "Not a valid product code." | 400 |
| Well-formed, not in `pqbg_codes` | "Code not found." | 404 |
| Retired, item exists | "This label is out of date." plus the name. Users with `pqbg_manage_codes` also get a link to the product edit screen to reprint the label, if they can edit it and it is not in the trash. | 200 |
| Retired, item deleted | "This label is out of date." plus "This label is no longer valid." | 200 |
| Active, item missing (deleted outside WordPress) | "This label is no longer valid." | 200 |
| Active, item or its parent in the trash | "This product is in the trash." Name and SKU only. | 200 |
| Active, product or parent is a draft, pending or scheduled | Full screen plus "Not published – cannot be sold yet." | 200 |
| Active, **variation** is private (WooCommerce's "disabled" variation) | Full screen plus "This variation is disabled – cannot be sold." | 200 |
| Active: published product or variation, **private simple product**, or variation of a private parent | Full screen | 200 |

- The two banners can appear together, for a disabled variation of a draft product.
- A private *simple* product is a normal, sellable product and gets no banner.

### Product screen

Everything is read live from WooCommerce on each request; nothing is cached. The screen shows:
- the main image: `woocommerce_thumbnail` size, `loading="lazy"`. A variation without its own image shows its parent's.
- the name (the parent's, for a variation) and the variation's attributes
- the SKU
- the price, formatted by WooCommerce in the store currency (INR). When on sale, the regular price is struck through. "Price not set" when there is no price.
- the stock status, plus the quantity when stock is managed (including stock managed on the parent)
- the categories as plain names (the parent's, for a variation)
- the code
- "Edit product", for users with `edit_products` (the parent's edit screen, for a variation)

It never shows cost, supplier data, private notes or customer data.

Every staff screen has the **"Scan or type a code"** box at the top and a **Log out** link. The box has `autofocus` and submits on Enter without JavaScript, so USB/Bluetooth scanners that "type" the code and press Enter work.

### Template and headers

- **Standalone template.** `templates/pqbg-scan.php` is a complete HTML document owned by the plugin.
  - It does not use the active theme (no `get_header()`/`get_footer()`).
  - It does not call `wp_head()`/`wp_footer()`, so no theme, admin bar or third-party output appears.
  - There is no JavaScript.
- **Stylesheet:** only `assets/pqbg-scan.css`, and only on scan pages. Mobile-first: 18 px text, tap targets of at least 48 px, high contrast.
- **Escaping:** every value is escaped. The price HTML from WooCommerce goes through `wp_kses_post()`, and the image tag comes from `wp_get_attachment_image()`.
- **Headers** on **every** scan response, redirects included:

  ```
  Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private
  X-Robots-Tag: noindex, nofollow
  Referrer-Policy: same-origin
  X-Frame-Options: DENY
  X-Content-Type-Options: nosniff
  Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self' https: data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'
  ```

  HTML pages also carry `<meta name="robots" content="noindex, nofollow">`.
- **Sitemaps:** scan pages are not posts or terms, so core sitemaps never list them. This is tested with sitemaps forced on.

### Login

- **Logged-out scans** go to **`wp-login.php`** (`wp_login_url()`) and come back to the same scan URL. While WooCommerce Coming Soon is on, logged-out visitors cannot see the My Account login form, but `wp-login.php` always works.
- **My Account form:** when it is opened with `?redirect_to=<scan URL>`, staff are sent to that scan URL after logging in (`woocommerce_login_redirect`). Only same-host URLs under the scan path are accepted.
- **Sellers without a destination** go to `/scan/`. This applies to any user who can view products but cannot use wp-admin (no `edit_posts`, i.e. Store Sellers) and logs in without asking for a page.
  - Without this, core would send them to `profile.php`, and WooCommerce would bounce them to My Account.
  - Administrators, Shop Managers and customers are unaffected.
- **Log out** returns to `/scan/`, which then asks for a login again.

### Permalinks

Scan URLs need **pretty permalinks**: any structure except "Plain", and not `/index.php/…`.
- With Plain or `index.php` permalinks the route is inactive, and users with `pqbg_manage_settings` see an admin notice.
- Printed labels keep the `/scan/{CODE}/` format either way; switching permalinks back makes them work again.

**Slug conflicts:** a page, post, product or public term may have an address at or below `/scan/`.
- The scan page takes precedence over it.
- Users with `pqbg_manage_settings` see a notice naming that content, so its slug can be changed.
- Addresses under a base, such as `/product-category/scan/`, are not affected and are not flagged.

### WooCommerce Coming Soon

Scan pages are answered before Coming Soon runs:
- logged-out visitors get the login redirect, not the Coming Soon page
- Store Sellers see the scan page. Unlike administrators and Shop Managers, they are not exempt from Coming Soon.

### Phone testing

A phone cannot open `http://localhost/sharayu`. For testing, use a **Cloudflare quick tunnel**: no account is needed, it is HTTPS, and it only makes outbound connections.

1. Install and start it (PowerShell):

   ```
   winget install --id Cloudflare.cloudflared -e
   cloudflared tunnel --url http://localhost:80
   ```

   - Note the printed `https://<words>.trycloudflare.com`. The name changes every time the tunnel starts.
   - Press Ctrl+C to stop it.
2. Add this to your **local** `wp-config.php`, above `/* That's all, stop editing! */`. It changes nothing for `localhost` requests.

   ```php
   // PQBG phone testing via a Cloudflare quick tunnel. Remove after testing.
   if ( isset( $_SERVER['HTTP_HOST'] ) && preg_match( '/^[a-z0-9-]+\.trycloudflare\.com$/D', $_SERVER['HTTP_HOST'] ) ) {
   	if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] ) {
   		$_SERVER['HTTPS'] = 'on';
   	}
   	define( 'WP_HOME', 'https://' . $_SERVER['HTTP_HOST'] . '/sharayu' );
   	define( 'WP_SITEURL', 'https://' . $_SERVER['HTTP_HOST'] . '/sharayu' );
   }
   ```

3. Go to **WooCommerce → QR & Barcodes**, set **Scan base URL** to `https://<words>.trycloudflare.com/sharayu`, and save. The QR codes in the admin now point at the tunnel, and the local-address warning disappears.
4. **To revert:** clear the Scan base URL field and save (the warning returns), stop the tunnel, and remove the snippet.

**Warning:** while the tunnel runs, the whole site is reachable at that URL. Scan pages still require a login, but keep the tunnel short-lived.

**Manual checklist:**
1. On the laptop (`http://localhost/sharayu/wp-admin`), open a product and its "QR & Barcode" panel.
2. Scan the QR on screen with the phone camera. The tunnel's login page should open.
3. Log in as a Store Seller. The product screen for that code should appear.
4. Regenerate the code, then scan the old QR (for example from an earlier download). You should see "This label is out of date."
5. Type the new code into the box, in lowercase with spaces. It should open the product.
6. Paste a full scan URL into the box. It should open the product.
7. Tap Log out, then scan again. You should be asked to log in.
8. Log in as a customer and scan. You should get "You do not have permission to view products." and no product data.

### Limitations

- **The scan base URL must reach this site.** The route answers only on this site's own `/scan/` path. A base URL on another host needs that host to forward to this site, as the tunnel does.
- **Timing on the dev machine:**
  - about 200–250 ms per product scan over HTTP (median); booting WordPress and WooCommerce dominates
  - 17–37 ms and 12 queries in-process
- **No guessing of excluded letters.** A code typed with characters outside the code alphabet (`0`, `O`, `1`, `I`, `L`) is not corrected; it gets "Not a valid product code."

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
| `pqbg_rewrite_version` | yes | `{plugin version}:{rules version}` of the scan rules last flushed (Phase 6). Holds no data; removed on deactivation and uninstall. |

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

Deactivation is non-destructive. Tables, codes, sales, settings, the role and capabilities are all kept. Only the scan route's two rewrite rules are removed (with a soft flush), along with the `pqbg_rewrite_version` flag, so `/scan/` stops answering until the plugin is reactivated. There are no cron events.

## Uninstall

**By default, all data is preserved.** Deleting the plugin from the Plugins screen removes only the transient install lock and the `pqbg_rewrite_version` flag. Tables, sales history, product codes, options, the Store Seller role and capabilities remain, and reinstalling picks them up again.

To permanently delete all plugin data, add this to `wp-config.php` **before** deleting the plugin:

```php
define( 'PQBG_UNINSTALL_DELETE_ALL_DATA', true );
```

This drops `pqbg_codes` and `pqbg_sales`, deletes `pqbg_settings` and `pqbg_db_version`, removes every `pqbg_*` capability, and deletes the Store Seller role. Affected users keep their accounts.
**This cannot be undone. Back up the database first.** On multisite, only the site running the uninstall is affected.

## Operational notes

- QR codes point to the scan base URL: `home_url()` unless it is overridden on the settings page. **Do not print labels until the production URL is set.** The admin warning stays visible while the URL is local, and a second warning appears while a public URL uses `http://`.
- The site timezone is currently UTC. The plugin stores UTC regardless, but the store timezone (India) should be set deliberately.
- WooCommerce "Coming Soon" mode is on for the whole site. The scan page works with it (see [WooCommerce Coming Soon](#woocommerce-coming-soon)). Logged-out staff log in through `wp-login.php`, because Coming Soon hides the My Account login form.
- Scan URLs need pretty permalinks (see [Permalinks](#permalinks)).
