# Product QR Code and Barcode Generator

WooCommerce plugin for Durga Collections: product QR/barcode inventory for our own shop staff ("sellers").
Staff scan a product's code, see live WooCommerce product information, and mark it sold. Stock updates automatically and every sale is logged.

This is **not** a marketplace or multi-vendor system. Sellers are our own staff selling our own catalog.

## Current scope: Phases 2–9A (foundation, data layer, code generation, rendering, admin code management, scan page, Mark as Sold, label printing, sales history)

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
- **Phase 6:** the front-end scan page `/scan/{CODE}/` and the entry page `/scan/`: login round trip, access control, the status → screen matrix, live product details, a standalone mobile template. See [Scan page](#scan-page).
- **Phase 7:** Mark as Sold from the scan page: quantity, "Confirm sale", WooCommerce stock decrement, a permanent `pqbg_sales` record with snapshots, a 10-minute Undo for the seller, and `SaleService::void_sale()` for managers (service only). See [Mark as Sold](#mark-as-sold).
- **Phase 8:** label printing from wp-admin: "Print label" on the product panel, "Print QR labels" on the products list, a print setup screen (A4 sheet and thermal presets, custom layouts, start position, copies, fields), and a standalone print-ready page with exact millimetre geometry, plus a render cache. See [Label printing](#label-printing).
- **Phase 9A:** the payment method on every sale (required, chosen by the seller), an optional cost price per product/variation (administrators only) snapshotted on every sale, the seller's name snapshot, the managers' **In-store sales** history (filters, totals, profit, sale detail, CSV export, void), and the sellers' **My sales** page. Schema version 3 and the `pqbg_view_costs` capability. See [Sales history](#sales-history).

**Not implemented yet (later phases):**
- reports, charts and the owner dashboard (Phase 9B)
- CSV import/export of codes, bulk code generation, bulk cost import and bulk tools (Phase 10)
- split/mixed payments, receipts/invoices, returns/exchanges, discounts, GST/tax, customer data
- PDF output, print history, a label designer, direct printer drivers
- in-browser camera scanning
- REST/AJAX endpoints and shortcodes, and support for WooCommerce's block-based product editor

The plugin adds **no REST routes, AJAX handlers or shortcodes**. Its request handlers are:
- the authenticated `admin-post.php` actions of Phase 5, for users with `pqbg_manage_codes` (see [Admin handlers](#admin-handlers))
- the Phase 8 print setup screen (a hidden wp-admin page), its `admin-post.php` POST, and the print page (`admin-post.php`, GET/HEAD), all for users with `pqbg_manage_codes` (see [Label printing](#label-printing))
- the scan page of Phase 6, which requires a login and `pqbg_view_products` before it shows anything (see [Scan page](#scan-page)); since Phase 7 it also accepts POST (sell, undo) on code URLs from users with `pqbg_sell`, with a nonce and a signed form token (see [Mark as Sold](#mark-as-sold))
- Phase 9A (see [Sales history](#sales-history)): the **In-store sales** screens (a wp-admin page, GET, `pqbg_view_all_sales`), the void POST (`admin-post.php`, nonce, `pqbg_void_sale`), the CSV download (`admin-post.php`, GET, nonce, `pqbg_view_all_sales`), and **My sales** at `/scan/my-sales/` (GET/HEAD, `pqbg_view_own_sales`)
- Phase 9A cost price: two WooCommerce product-editor save actions for users with `pqbg_view_costs`, and filters that keep the cost out of WooCommerce's meta data, REST, exports and imports (see [Cost price](#cost-price))

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
| Payment methods offered (section "In-store sales", Phase 9A) | `payment_methods` (list of `cash`, `upi`, `card`, `other`) | `cash`, `upi`, `card`. At least one must stay enabled: unticking all of them is refused with "At least one payment method must stay enabled. The previous choice was kept." |

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

The CLI regression suites for Phases 2–8 are in [`tests/`](tests/README.md): a runner with an Action Scheduler leak guard, round-trip QR/barcode decoding (also at printed size, 203 and 300 dpi), HTTP checks of the admin, scan, sale and print flows, concurrency tests with worker processes, and optional headless Chrome/Edge checks of the print page. `tests/` and `build/` are never loaded by the plugin, their PHP files exit outside the CLI, and `.htaccess` denies them over HTTP.

## Production deployment

**Exclude `tests/` and `build/` from any production deployment.** Deploy only the runtime files:

- `product-qrcode-barcode-generator.php`, `uninstall.php`, `index.php`
- `includes/`, `assets/`, `languages/`, `templates/`, `vendor-prefixed/`
- `README.md` (optional)

`tests/` and `build/` are development tooling. They are kept in the repository so the vendor bundle can be rebuilt exactly and the regression suites can be rerun, but they must never reach a live server:
- The test suites create and delete data, and one briefly deactivates the plugin.
- The `.htaccess` denial only works on Apache.
- `tests/decoder/node_modules/`, `tests/print-check/node_modules/`, `build/vendor/` and `build/tools/` are never committed and must not be deployed either.

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

Phase 6. When staff scan a label's QR code with a phone camera, or type or scan a code into the page, they see the live WooCommerce product for that code. **GET and HEAD are read-only:** they never sell, change stock or write to the database. Selling is a POST from the sale form (Phase 7, see [Mark as Sold](#mark-as-sold)).

- Classes: `ScanRoute` (route, access, redirects, headers, login redirects, admin notices), `ScanScreen` (code → screen, rendering, the sale form and sale page) and `ScanUrl` (every scan URL and the rewrite patterns).
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
| Method other than GET or HEAD, except POST on a code URL | **405** with `Allow: GET, HEAD` (entry page) or `Allow: GET, HEAD, POST` (code URL). |
| Logged out | **302** to `wp_login_url()`. `redirect_to` is the canonical scan URL, or `/scan/` when the path is not a well-formed code. The code's existence is never checked. |
| Logged in without `pqbg_view_products` (customers, subscribers) | **403**: one fixed page with no box and no product data. No lookup runs, so the response is byte-identical for existing, retired, unknown and invalid codes, and for the entry page. |
| POST to `/scan/{CODE}/` (Phase 7: sell or undo) | Handled by `SaleRequest` (see [Mark as Sold](#mark-as-sold)). A POST to a non-canonical URL gets **400**; POSTs are never redirected. |
| `/scan/{CODE}/?sale={id}` (Phase 7 sale page) | **200** with the sale, or **303** (never 301, so it is not cached) to the code URL when the sale does not exist, belongs to another code, or is not the user's to see. |
| Path not canonical: lowercase code, spaces, missing trailing slash, any other query string, or raw `?pqbg_code=` | **301** to `{home}/scan/{CODE}/`. |
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

**Phase 7 checklist (Mark as Sold):** use a test product with **Manage stock** on and a stock of at least 2.
1. Scan its QR and log in as a Store Seller. The product screen shows a **Sell** box: a quantity list with the total per quantity, and **Confirm sale**.
2. Leave the quantity at 1 and tap **Confirm sale**. You should see "Sold.", the item, "1 × price", the total, "Stock now" one lower than before, the time, an **Undo this sale** button with "Undo available until …", and the "Scan next item" box.
3. On the laptop, open the product in wp-admin. The stock should be one lower.
4. Reload the success page on the phone. Nothing should change: still one sale, same stock.
5. Tap **Undo this sale**. You should see "This sale was undone at …". In wp-admin, the stock should be back to its original level.
6. Set the product's stock to 0 in wp-admin and scan it again. There is no Sell box, only "Out of stock – cannot be sold."
7. Change a product to **Draft** and scan it. You get "Not published – cannot be sold yet." and no Sell box.
8. (Optional) Log in as a customer and scan: still "You do not have permission to view products."

Restore the test product's stock and status afterwards.

**Phase 8 checklist (label printing).** Set up the tunnel and the `wp-config.php` snippet as in steps 1–3 above, so the labels point to `https://<words>.trycloudflare.com/sharayu`. **Never change Settings → General → WordPress Address for this.** With the tunnel URL saved as the Scan base URL, labels print without the TEST mark; if you skip the tunnel, they print with "TEST – NOT FOR USE" and phones cannot open them.

Use two or three test products with codes, one with a price (to check the ₹ sign) and one variable product.

1. **Products** list: tick the test products, choose **Print QR labels** under Bulk actions, and click **Apply**. The setup screen lists the items (variable products as their variations) and any "Skipped – no code yet" items.
2. Choose your layout: the A4 sheet that matches your label stock, or the thermal preset (set the same label size in the thermal printer's own driver settings). Keep the defaults otherwise, and click **Preview and print**.
3. On the print page, check the preview, then click **Print**. In the print dialog set **Scale 100%** ("Default" in Chrome/Edge; never "Fit to page"), **Margins: None**, **Headers and footers: off**.
4. **A4 sheets:** print one page on **plain paper** first and hold it against a label sheet in front of a light: every label outline should fall inside a label. If everything is shifted the same way, use **Printer offset** on the setup screen (e.g. 1 mm right) and print again. Then print on the label sheet.
5. Measure one printed label and its QR code with a ruler: the label size should match the layout, and the QR code the size shown on the print page (e.g. "QR code 35.1 mm").
6. **Check that ₹ prints correctly** in the price (a rupee sign, not an empty box or a question mark).
7. Scan several labels with your phone camera, including one from the last row or last thermal label, through the Phase 6 flow: the tunnel's login page (if logged out), then the product screen for exactly that item.
8. (Optional) Print a partly used sheet with **Start at position** set to the first free label.

**To revert:** clear the Scan base URL field and save, stop the tunnel, and remove the snippet. Throw away labels printed with the tunnel URL: they stop working when the tunnel stops.

**Phase 9A checklist (payment method, My sales, cost price, sales history).** Set up the tunnel and the `wp-config.php` snippet as in steps 1–3 above for the phone parts. Use two test products with **Manage stock** on (stock at least 5), one of them variable.

1. **Payment methods:** as an administrator, open **WooCommerce → QR & Barcodes**. Under "In-store sales", Cash, UPI and Card are ticked and Other is not. Untick all four and save: the page says "At least one payment method must stay enabled" and keeps them. Leave the defaults.
2. **Sell with each method (phone, as a Store Seller):** scan the first product. Under **Paid by**, nothing is selected. Tap **Confirm sale** without choosing: the browser asks you to choose one (or the page says "Choose how the customer paid."). Choose **Cash** and confirm: the sale page shows "Paid by: Cash". Sell again with **UPI**, then with **Card**.
3. **My sales (phone):** tap **My sales** at the top of the scan page. Today's three sales are listed with time, item, "quantity × price = total", the method and "Completed". The summary shows Cash, UPI and Card with their totals, and a Total line. Tap **Yesterday** and **Last 7 days**. Undo one sale from its sale page and check that My sales shows it as voided and counts "Voided: 1" (the summary no longer includes it). There is no cost or profit anywhere.
4. **Cost price (laptop, as an administrator):** edit the simple product. On the General tab, under the prices, fill **Cost price (₹)** (e.g. 900) and update. On the variable product, fill **Default cost price (₹)** (General tab) and, for one variation, its own **Cost price (₹)** (its placeholder shows the default). Update. Enter something invalid (e.g. `-5` or `1,499.00`) once: an error appears and the previous value stays.
5. **Sell the variations (phone):** sell one variation with its own cost and one without.
6. **History (laptop, administrator): WooCommerce → In-store sales** (right after Orders). Today's sales are listed with date/time, sale #, product, SKU, qty, unit price, total, "Paid by", seller, status, unit cost and profit. The earlier sales (before step 4) show cost "unknown". The totals bar shows the revenue per method, then "Cost … · Profit … — excludes N lines with unknown cost". Try the presets (Yesterday, This month) and the filters (seller, Paid by, status, search by SKU or by the product code). Copy the address and open it in a new tab: the same view appears.
7. **Shop Manager cannot see cost:** log in as a Shop Manager. On the product edit screen there is no cost field; in **In-store sales** there is no Unit cost or Profit column and no cost in the totals; the exported CSV has no cost columns.
8. **Void (as a Shop Manager or administrator):** open a sale (click its date or number) → **Void sale**. Submit without a reason: "Enter a reason for voiding this sale." Enter a reason, keep **Return 1 to stock** ticked, and click **Void sale**: "Sale voided. The quantity was returned to stock." The timeline shows who voided it, when and why. The product's stock in wp-admin is back up by 1. The sale stays in the list as Voided and is no longer in the totals.
9. **CSV:** click **Export CSV** and open the file in **Excel**: the ₹ sign in the headers shows correctly, dates are in Indian time, and there is one line per sale in the view.

Undo the test sales or leave them (they are real rows in `pqbg_sales` and cannot be deleted from the UI); restore the test products' stock afterwards.

### Limitations

- **The scan base URL must reach this site.** The route answers only on this site's own `/scan/` path. A base URL on another host needs that host to forward to this site, as the tunnel does.
- **Timing on the dev machine:**
  - about 200–250 ms per product scan over HTTP (median); booting WordPress and WooCommerce dominates
  - 17–37 ms and 12 queries in-process
- **No guessing of excluded letters.** A code typed with characters outside the code alphabet (`0`, `O`, `1`, `I`, `L`) is not corrected; it gets "Not a valid product code."

## Mark as Sold

Phase 7. From the product screen, a user with `pqbg_sell` sells the scanned item: WooCommerce stock goes down, a permanent row is written to `pqbg_sales`, and the seller can undo their own sale for 10 minutes.

- Classes: `SaleService` (rules, sell, undo, void), `SaleRepository` (all `pqbg_sales` SQL and the atomic stock statement), `StockLock` (per-stock-holder `GET_LOCK`), `SaleRequest` (POST handling, the form token, the sale page). `ScanScreen` builds the form and the sale page; the template renders them.
- **No WooCommerce order is created.** These sales do **not** appear in WooCommerce orders, reports, Analytics or the products' `total_sales`. The sales history is `pqbg_sales` (its UI is Phase 9).
- Not in this phase: payment method, customer details, tax/GST, receipts, sales history UI, manager void UI, REST, camera scanning.

### Rules

| Rule | Behaviour |
|---|---|
| Item | Resolved server-side from the code. Product IDs, prices and stock are never taken from the form. |
| Sellable states | Only the "full product screen" rows of the status matrix: a **published** or **private simple** product; a **published (enabled) variation** whose parent is published or private. Draft, pending, scheduled, disabled variation, trash, retired, deleted and unknown codes are refused server-side, not just hidden. |
| Stock tracking | Must be on, on the item or (for variations with parent-level stock) on the parent. Otherwise no Sell box and, in the wording of the WooCommerce 11.1.2 product editor, "Stock tracking is off for this product. On the Inventory tab, tick 'Track stock quantity for this product' to sell from a scan." (for a variation: "Stock tracking is off for this variation. Tick 'Manage stock?' on the variation, or 'Track stock quantity for this product' on the product's Inventory tab, to sell from a scan.") |
| Price | The WooCommerce **active price** (`get_price()`) at the moment of sale, including a running scheduled sale. **An empty or zero price blocks the sale**: "This item has no price. Set a price before selling." / "This item has no price (₹0). Set a price before selling." |
| Quantity | Default 1, min 1, max = the stock available now. Up to 100 in stock: a list where every option shows its total ("2 × ₹1,499.00 = ₹2,998.00"), so the total is live without JavaScript. Above 100: a number field (min 1, max stock) with "Total = quantity × price". |
| Stock | Stock 0 or not enough → blocked, **whatever the backorder setting**. Stock held for unpaid online checkouts is not subtracted (the seller has the item in hand). |
| Price or stock changed since the page was opened | Price changed → refused ("The price changed since you opened this page…"), compared as decimals with the store's price decimals, so "1499" = "1499.00" = 1499.0. Stock changed → refused only if the quantity no longer fits ("Stock changed since you opened this page. Now N in stock."). |
| Users with `pqbg_view_products` but not `pqbg_sell` | The Phase 6 product screen, with no sale controls and no sale notices. |

### Flow

1. **Form** (product screen, sellable item, user with `pqbg_sell`). POST to the canonical code URL with `pqbg_action=sell`, the nonce `pqbg_sell_{code row id}` in `_pqbg_nonce`, the quantity, and a **signed form token**:
   - `request_id`: a UUID v4 from `random_bytes()`, new on every render. It is the sale's idempotency key (UNIQUE in `pqbg_sales`).
   - `issued`, `seen_price`, `seen_stock`, and `sig`, an HMAC-SHA256 over these, the user and the code row, keyed with `wp_salt( 'nonce' )`.
   - The form is separate from the code box, so Enter in the box (or a scanner that types a code and Enter) only looks up a code.
2. **Checks, in order:**
   1. logged in (else 302 to the login page, back to the product URL; the POST is not replayed)
   2. `pqbg_view_products` (else the fixed Phase 6 403)
   3. canonical URL (else 400)
   4. a known action (else 400)
   5. `pqbg_sell` (else 403)
   6. the nonce (else 403)
   7. the signature (else 400)
   8. **an existing sale with this `request_id` → its outcome** (checked before expiry, so a resubmitted form never sells twice)
   9. the form is at most **30 minutes** old (else 400 "This sale form has expired…")
   10. the quantity (else 400)
   11. `SaleService::sell()`
3. **Result:** a completed sale answers **303** to `/scan/{CODE}/?sale={id}` (Post/Redirect/Get). Reloading it is a read-only GET. Errors show the product screen with the message and, where the item is still sellable, a fresh form.

**Sale page** (`?sale={id}`): the seller's own sale (`pqbg_view_own_sales`), or any sale for `pqbg_view_all_sales`. It shows the snapshots, not live product data, so it stays correct after the product changes: "Sold.", the item and attributes, quantity × unit price, total, "Stock now", the time in the site timezone (`wp_date()`), the Undo button while allowed, and the "Scan next item" box (autofocus).

**Error responses:**

| HTTP | Message |
|---|---|
| 400 | "Choose a quantity between 1 and N." / "Only N in stock. Choose a quantity between 1 and N." / "This form is not valid…" / "This sale form has expired…" / "This request could not be understood." |
| 403 | "You do not have permission to sell." / "This form is no longer valid…" (nonce) / "You can only undo your own sale." |
| 404 | "Code not found." |
| 409 | "Out of stock – cannot be sold." / "Stock changed…" / "The price changed…" / "This item just sold online. Stock was not changed." / the status messages / "This sale was already undone." / "Undo is no longer available (10-minute limit)." |
| 503 | "Someone else is selling this item right now. Try again." with `Retry-After: 2` |
| 500 | "The sale could not be completed. Stock was not changed." |

Every response carries the Phase 6 security headers. There is still no JavaScript.

### Stock changes, locking and atomicity

`SaleService::sell()`, under the lock of the **stock holder** (the product whose stock actually changes: the parent when a variation uses parent-level stock):

1. `StockLock::acquire()`: `GET_LOCK('pqbg:{site hash}:stock:{holder}', 5)`. On timeout: "busy" (503). It is released in `finally`, and MariaDB releases it anyway when a connection drops.
2. Recover stale journal rows: any `pending` row of this holder belongs to a process that died, so it becomes `failed` / `interrupted`.
3. Fresh reads: `_stock` straight from the database, the product re-read after clearing its cache, `get_price()`. Then the stock and price checks.
4. Insert the journal row: status `pending`, all snapshots, `stock_holder_id`.
5. `wc_update_product_stock( $holder, $qty, 'decrease' )`. For this one call, a filter on `woocommerce_update_product_stock_query` (at `PHP_INT_MAX`, removed in `finally`) replaces WooCommerce's own UPDATE with **one multi-table statement** that lowers the stock **and** sets the row to `completed` only if it is still `pending`. So the stock change and its record commit together or not at all. WooCommerce then does everything else as usual: lookup table, product save, stock status, caches and hooks.
6. Read `_stock` again. **Below 0** means an online order took stock between steps 3 and 5: compensate with `wc_update_product_stock( increase )`, whose statement also sets the row to `failed` / `sold_online`. The seller sees "This item just sold online. Stock was not changed." **Any exception or error** after step 4 is compensated the same way (`failed` / `error`).
7. Record `stock_before` / `stock_after`, release the lock, then call WooCommerce's `wc_trigger_stock_change_actions()` for the low/no-stock notification. WooCommerce sends those itself only for order-based stock changes.

**The replacement is used only if the SQL WooCommerce hands over has the expected shape:** `UPDATE {postmeta} SET meta_value = meta_value -2.000000 WHERE post_id = N AND meta_key='_stock'`, for this holder and quantity (`SaleService::is_expected_stock_sql()`). Otherwise WooCommerce's SQL runs unchanged.

**Fallback** (the row is still `pending` after WooCommerce ran: another plugin replaced the SQL, the filter did not fire, or WooCommerce threw first): a fresh `_stock` read decides. If the stock dropped by at least the quantity since the read under the lock, the row becomes `completed` (conditional on `pending`). Otherwise it becomes `failed` / `error`. A warning is logged with error codes only (`pqbg_stock_marker_missing`, `pqbg_stock_sql_unexpected`, `pqbg_stock_filter_not_fired`). Undo and compensation use the same rule in the other direction. The Phase 7 test suite has a **compatibility check** that fails loudly if WooCommerce stops passing its stock UPDATE through that filter or changes its shape.

**No transactions.** The sale code never runs `START TRANSACTION`, `COMMIT` or `ROLLBACK`, so it cannot implicitly commit a WooCommerce transaction (or any other). A hook that opens its own transaction during the product save (for example the Phase 5 code sweep, which runs on the parent's save for a shop manager) cannot break the sale's atomicity, which comes from single statements. If the service is called while the connection already has an open transaction (`@@in_transaction`), it refuses.

**Idempotency:** one `request_id` = one outcome, forever. Double taps, a resubmitted form, concurrent duplicates (serialised by the lock, with the UNIQUE index behind it) and a reloaded success page never create a second sale or a second decrement.

**Row statuses:** `pending` (journal; stock not changed) → `completed` → `voided`, or `pending` → `failed`, or `completed` → `failed` (compensated). `failure_code`: `sold_online`, `error`, `interrupted`. Rows are never deleted. Reports must count `completed` sales only.

**Side effects that compensation, undo and void cannot take back:**
- the low/no-stock e-mail sent after a completed sale (an undo does not "unsend" it)
- anything third-party code did on `woocommerce_product_set_stock` / `woocommerce_variation_set_stock`, `woocommerce_updated_product_stock` or the product save
- the product's modified date
- a stock status that shoppers could briefly see (for example "Out of stock" for a few milliseconds during an online race)

**Online checkout boundary:**
- WooCommerce checkout does not take our lock. A reduction that lands between our read and our decrement is caught (negative stock) and compensated.
- An online order paid **after** our sale can still take the stock below 0. That is WooCommerce's own oversell behaviour, not something the scan page can prevent.
- Stock held for unpaid checkouts (`woocommerce_hold_stock_minutes`) is not counted.

**Remaining crash windows** (documented; the Phase 11 reconciliation check will surface them):
- a crash after the atomic statement but before the snapshots leaves a `completed` row with `stock_after` NULL. Stock and record still agree.
- a crash between an online-race decrement and its compensation leaves a `completed` row and negative stock.

### Undo and void

- **Undo** (sale page): the **same seller**, with `pqbg_sell`, their own `completed` sale, within **10 minutes** of the sale, **once**. After 10 minutes the button is gone, and a kept form is refused server-side.
  - Under the lock of the recorded `stock_holder_id`, one statement puts the quantity back and sets `voided`, `voided_by`, `voided_at_gmt`, `void_reason = 'undo'`, only if the row is still `completed`. A second undo changes nothing ("This sale was already undone.").
  - The row is never deleted. GET cannot undo.
  - Refused if stock tracking was turned off or moved (parent ↔ variation) since the sale.
- **Void** (`SaleService::void_sale( $sale_id, $user_id, $reason, $restock = true )`): needs `pqbg_void_sale` (Shop Manager, Administrator). Any `completed` sale, any age; with or without restock. Its UI is the Phase 9A void screen (see [Void](#void)).

### Timings (dev machine)

| Measurement | Result |
|---|---|
| Sale over HTTP (POST → 303), median of 5 | about 260–310 ms |
| Undo over HTTP (POST → 303), median of 5 | about 245–290 ms |
| Sale in-process (`SaleService::sell()`), median of 5 | about 40–50 ms |
| Undo in-process, median of 5 | about 30–35 ms |

**Local mail is not configured on this XAMPP:** a low/no-stock notification makes `mail()` fail after about 2 s. The e-mail is sent after the lock is released, so it never holds up the next sale of the item, but that one response is slower.

### Limitations

- The live total is per option in the quantity list; above 100 in stock the number field shows only the formula (no JavaScript).
- The undo window is measured on the server clock from the sale's `created_at_gmt`. The page does not refresh itself, so the Undo button can still be visible after the 10 minutes; pressing it is then refused.
- Held stock for pending online checkouts is not subtracted (decision D2).
- Taxes are off in this store; the recorded price is `get_price()` as entered. Tax/GST handling is out of scope.

## Label printing

Phase 8. Administrators and Shop Managers (`pqbg_manage_codes`) print product labels from wp-admin with the browser's own print dialog, on A4 label sheets or thermal label printers. There is no PDF library: the page is HTML with inline SVG and exact millimetre CSS.

### Rules

- **Only ACTIVE codes are printed.** Items are resolved server-side and their codes read with `CodeRepository::find_active_for_products()`; a retired code is never printed.
- **Printing never generates codes.** An item without a code is listed as "Skipped – no code yet" with a link to the product. (Bulk code generation is Phase 10.)
- The QR payload comes only from `ScanUrl::for_code()` and the images only from `QrRenderer`/`BarcodeRenderer`.
- Barcodes are printed only when they are enabled in Settings; otherwise the barcode library is never loaded.
- Draft, pending and private items are printed, with a note on the setup screen: their labels scan to "Not published – cannot be sold yet" until they are published. Items in the trash are skipped.

### Entry points

| Where | What |
|---|---|
| Product edit screen, simple product | **Print label** next to Download QR |
| Product edit screen, variable product | **Print all variation labels (n)** above the variations table, and **Print label** on each variation row that has a code |
| Products list | Bulk action **Print QR labels** (a variable product expands to its variations) |

All three lead to the **print setup screen** (a hidden page under Products). **Preview and print** remembers your choices and opens the **print page**.

- **Job limit: 300 labels, and at most 300 selected products.** A cold job renders about 50 ms per new QR code, so 300 new codes take about 15 s; the page for 300 labels is about 1.5 MB, which print preview handles well; and 300 IDs keep the link far below server URL limits. Over the limit, the setup screen says "This job has N labels; the maximum is 300…".

### Setup options

- **Layout** (with the QR size each preset gives for your scan URL):

  | Preset | Paper | Grid | Label (mm) | Left / top margin (mm) | Column / row gap (mm) | Per page |
  |---|---|---|---|---|---|---|
  | `a4-3x7` **(default)** | A4 | 3 × 7 | 63.5 × 38.1 | 7.25 / 15.15 | 2.5 / 0 | 21 |
  | `a4-3x8` | A4 | 3 × 8 | 70 × 37 | 0 / 0.5 | 0 / 0 | 24 (edge-to-edge sheet) |
  | `a4-4x10` | A4 | 4 × 10 | 48.5 × 25.4 | 8 / 21.5 | 0 / 0 | 40 |
  | `a4-5x13` | A4 | 5 × 13 | 38.1 × 21.2 | 4.75 / 10.7 | 2.5 / 0 | 65 |
  | `th-50x25` | 50 × 25 | 1 | 50 × 25 | – | – | 1 (thermal, 203 dpi) |
  | `th-38x25` | 38 × 25 | 1 | 38 × 25 | – | – | 1 (thermal, 203 dpi) |
  | `th-100x50` | 100 × 50 | 1 | 100 × 50 | – | – | 1 (thermal, 203 dpi) |

  Every A4 preset adds up to exactly 210 × 297 mm. Content keeps a safe inset from each label edge (1–1.5 mm; 4 × 2.5 mm on the edge-to-edge 3 × 8 sheet). **Custom**: a label sheet (page size, label size, columns, rows, left/top margin, gaps; at most 500 labels per sheet, everything must fit on the page) or a thermal printer (label size, 203 or 300 dpi). Invalid values are refused with a message naming the field.
- **Start at position** N (label sheets only), counted row by row from the top left, to reuse a partly used sheet.
- **Copies:** a fixed number per item (1–100), or **one label per unit in stock**. The latter needs the item's own tracked stock; stock shared with the parent product, or not tracked, gives 1 label with a note; stock at or below 0 skips the item.
- **Show on the label:** product name (2 lines), variation attributes, SKU, price, store name (off by default). The code text is always printed. "Printed prices go out of date when you change them; the QR always shows the live price."
- **Printer offset** (label sheets only): moves everything right/down by up to ±5 mm to correct a printer that prints slightly off.
- Your choices are remembered per user (`pqbg_print_prefs` user meta), never site-wide.

### QR size

- The QR code is never printed with modules smaller than **0.40 mm**, and never larger than 0.99 mm. Its 4-module quiet zone lies inside the label's safe inset.
- The module count comes from the real encoding of the job's scan URLs at error correction level M: 41 × 41 modules including the quiet zone (version 4) for a scan base URL of up to 38 characters, then 45, 49 and 53, and 57 (version 8) at the 99–100 character maximum.
- **Why 0.40 mm:** the Phase 8 tests rasterise labels at exactly 0.40 mm modules at 203 and 300 dpi and decode them. For reference, GS1 General Specifications (Release 26.0, Table 5-46) give 0.396 mm as the minimum X-dimension for QR codes with GS1 Digital Link URIs on retail consumer items; our scan URLs are not GS1 Digital Link, so this is a reference point, not a conformance claim.
- On thermal printers the module is rounded down to whole printer dots (0.125 mm at 203 dpi) when that keeps it at or above 0.40 mm.
- If the chosen fields leave too little room, optional text is dropped first (store name, then price, SKU, attributes, name); the page lists what was not printed. If the QR code still does not fit, the layout is refused with the size it needs, e.g. "Your scan URL (61 characters) needs a QR code of at least 19.6 mm (49 × 49 modules …). This label has room for 19.2 mm."
- With a typical production base URL (version 4) the QR is 35.1 mm on 3 × 7, 32 mm on 3 × 8, 22.4 mm on 4 × 10, 19.2 mm on 5 × 13, 20.5 mm on thermal 50 × 25, 19.5 mm on 38 × 25 and 35.9 mm on 100 × 50.
- **Barcodes** (when enabled): a full-width strip at the bottom, 0.25 mm per module (2 dots at 203 dpi), 7 mm bars, up to 60.5 mm wide including its quiet zones. It fits 3 × 7, 3 × 8 and 100 × 50; on narrower labels it is left off with a notice.
- Text never overlaps the QR code or barcode: every text line has a fixed box, names are clamped to two lines, long values end with "…", and the code text wraps after its second hyphen on narrow labels.
- **Rupee sign:** the label font stack is Segoe UI, Nirmala UI, Roboto, Noto Sans, Arial, all of which contain "₹", before the generic fallback.

### The print page

- A standalone page (no wp-admin menus) with CSS `@page` set to the layout's exact size and zero margins, one page per sheet (or per thermal label), and an on-screen preview with page and label outlines.
- **In the print dialog:** Scale 100% ("Default" in Chrome/Edge, "Actual size" elsewhere; never "Fit to page"), Margins **None**, Headers and footers **off**. Background graphics are not needed. For a thermal printer, also set the label size in the printer's own driver settings.
- **Measured in headless Chrome and Edge 153** (`page.pdf()` with the page's own size): A4 comes out as 209.889 × 297.011 mm (Chromium uses the standard 595 × 842 pt), and 50 × 25 mm as 50.123 × 25.061 mm (rounded up to printer units). Content is placed from the top-left corner, so labels stay where they belong; the tests check the page count, the page size to ±0.5 mm and every label position.
- **Local scan URL:** the page shows a large warning and prints nothing until you click **Print TEST labels anyway**; every label then carries **TEST – NOT FOR USE**. With a public `https://` scan URL there is no mark. A public `http://` URL shows the "Labels should use an https:// scan URL in production." warning on the page (no mark on the labels).
- **Read-only:** opening or reloading the setup screen or the print page changes nothing (only the render cache below). Both are GET, capability-checked, and carry a nonce bound to the product selection (and to the user). The setup screen's POST (`pqbg_print_prepare`) only saves your options.
- **Headers:** the scan page's security headers (`Cache-Control: no-store…`, `X-Robots-Tag`, `Referrer-Policy`, `X-Frame-Options: DENY`, `nosniff`) with a print-page CSP:
  ```
  Content-Security-Policy: default-src 'none'; style-src 'self' 'nonce-…'; script-src 'self'; img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'
  ```
  The geometry is in one `<style>` element carrying the per-request nonce; the stylesheet (`assets/pqbg-print.css`) and the only script (`assets/pqbg-print.js`, the Print button) are same-origin files. There are no inline style attributes or event handlers.

### Render cache

- Rendered QR and barcode SVGs are cached so a 300-label job does not re-encode every code.
- **Storage:** transients (`pqbg_svg_{md5}`), which WordPress keeps in `wp_options` (not autoloaded) or in a persistent object cache when the host has one. Nothing is written to files.
- **Key:** the code plus everything that affects the image: for QR codes the exact payload from `ScanUrl::for_code()` (so the scan base URL), for barcodes the renderer arguments, plus the renderer constants, `PrintCache::VERSION` and the plugin version. Changing the scan base URL or the barcode settings never serves an old image; each stored entry repeats its code and fingerprint and both are checked on read. While barcodes are disabled, no barcode is looked up.
- **Retired codes** are never served: the print code only asks for codes it has just read as active rows.
- **Bounds:** entries expire after 30 days; an index option (`pqbg_svg_cache_index`, not autoloaded) keeps at most 2,000 entries (about 10 MB at most) and evicts the least recently used, once per request.
- **Uninstall** always clears the cache, whatever the data-preservation setting: it is not data.
- **Timings:** 300 labels take about 14 s cold and under 2 s warm over HTTP; see [the table below](#timings-dev-machine-1).

### Timings (dev machine)

Measured by the Phase 8 suite on the dev machine (XAMPP, PHP 8.5.6, no persistent object cache), A4 3 × 7 with every field, each label a different product. "Cold" is an empty render cache; "warm" is the next request with the cache filled. In-process = building and rendering the page in PHP; HTTP = the whole print page request.

| Labels | In-process cold | In-process warm | HTTP cold | HTTP warm | Page size |
|---|---|---|---|---|---|
| 100 | 4.47 s | 0.33 s | 4.77 s | 0.78 s | 448 KB |
| 300 | 14.17 s | 0.82 s | 13.67 s | 1.85 s | 1,340 KB |

Cold time is almost all QR encoding (about 45 ms per new code, in bacon's pure-PHP encoder); the cache removes it. Copies of the same code are rendered once per request.

### Limitations

- The browser's print dialog settings (scale, margins, headers and footers) are the user's; the page can only explain them. Always print one test page on plain paper first.
- Printer hardware margins: most office printers cannot print within 3–5 mm of the paper edge, so the edge-to-edge 3 × 8 sheet needs a printer that can.
- The QR size assumes all codes have the same length (they do: `DC-XXXX-XXXX-XXXX`); the page still uses the largest version in the job.
- Prices on labels are a snapshot; the QR always opens the live price.
- No PDF output, print history, label designer or printer drivers (out of scope).

## Sales history

Phase 9A: how each in-store sale was paid, what the item cost, and who sold it; a history for managers and a "My sales" page for sellers. Reports, charts and the owner dashboard are Phase 9B.

### Payment method

- Every sale records one payment method: `cash`, `upi`, `card` or `other` (labels "Cash", "UPI", "Card", "Other"), in `pqbg_sales.payment_method`. Sales made before schema version 3 have NULL, shown as **"Not recorded"**.
- Administrators choose the methods offered (see [Settings](#settings)); default Cash, UPI and Card.
- The sale form has a **required "Paid by" radio group with nothing selected** (large tap targets, no JavaScript). Only when exactly one method is offered is it pre-selected.
- The server checks the method against the methods offered **at the moment of sale** (`SaleRequest`, then `SaleService::sell()`): missing → 400 "Choose how the customer paid."; unknown or disabled (also: disabled after the form was opened) → 400 "That payment method is not available. Choose another." The form is shown again with the chosen quantity (and a still-valid method) kept; nothing is recorded.
- **Idempotency is unchanged:** a request ID that already has a sale returns that sale, whatever method the resubmission carries (and even if that method was disabled since).
- The method is written in the **pending journal row**, with the other snapshots, before the stock changes (the Phase 7 atomicity).
- The sale page shows "Paid by: …".
- **Not supported:** split or mixed payments (record the main method), and changing the method after the sale (void it and sell again).

### Cost price

- An optional **Cost price (₹)**, for administrators only (`pqbg_view_costs`):
  - simple product: General tab, under the prices (`woocommerce_product_options_pricing`)
  - variable product: **Default cost price (₹)** on the General tab, used by variations without their own (`woocommerce_product_options_general_product_data`)
  - each variation: **Cost price (₹)** after its prices, with the default as placeholder (`woocommerce_variation_options_pricing`)
- Stored in post meta **`_pqbg_cost_price`** (protected), normalised to the store's price decimals. Empty = unknown (the meta is deleted). Valid: a number ≥ 0 with at most 2 decimals (the store's), the store's decimal separator, no thousands separators, at most 12 integer digits. An invalid value keeps the previous one and shows an admin error.
- Saved through `woocommerce_admin_process_product_object` / `woocommerce_admin_process_variation_object` (classic form and the variations AJAX save, after WooCommerce's own nonce and capability checks), **only for `pqbg_view_costs` and only when the field was on the form**: a Shop Manager's save never touches it.
- **Snapshot:** every sale records the effective cost at that moment in `pqbg_sales.unit_cost` (a variation's own cost, else its parent's default; NULL when unknown — never 0). Changing a cost later never changes past sales.
- **Never exposed to anyone else** (decision D6: not even to administrators outside the edit screen and the history):
  - `woocommerce_data_store_wp_post_read_meta` keeps the key out of every WooCommerce object's `meta_data`, so it is not in the WC REST API (products, variations; WooCommerce does not filter protected meta there), the product CSV export, Duplicate (which therefore does not copy the cost), or anything else built on WooCommerce meta.
  - `add_post_metadata` / `update_post_metadata` accept the key only from `CostPrice::set()`: REST `meta_data`, the product CSV importer and the WordPress importer (WXR) cannot write it.
  - `delete_post_metadata`: a logged-in user without `pqbg_view_costs` cannot delete one item's cost through the meta API. **Never blocked:** permanent deletion of products/variations (WordPress deletes meta by ID, `delete_post_metadata_by_mid`, which is not hooked), bulk removal (`$delete_all`, e.g. uninstall), and requests without a user (cron, CLI).
  - `wxr_export_skip_postmeta`: Tools → Export never includes the cost, for anyone (the importer could not write it back anyway, so an exported cost could only leak).
  - The Store API, storefront, scan screens, labels and My sales never read it; the history, detail and CSV show it only to `pqbg_view_costs`.
- Deleting a product, removing a variation or changing variable → simple (WooCommerce deletes the variations) leaves no orphaned cost meta; after variable → simple the parent's default becomes the simple product's cost.

### Seller name

`pqbg_sales.seller_name` snapshots the seller's display name at the moment of sale. The history shows the snapshot; for older rows without one, the current display name. A deleted user is shown as "Name (deleted user)", or "User #ID (deleted)" without a snapshot; `voided_by = 0` is "System".

### In-store sales (managers)

**WooCommerce → In-store sales** (`admin.php?page=pqbg-sales`), right after Orders, for `pqbg_view_all_sales` (Shop Manager, Administrator). Every filter is in the URL, so a view can be bookmarked or shared. Everything is read-only GET.

- **Columns:** date/time (site timezone), sale #, product (with variation attributes), SKU, qty, unit price, total, paid by, seller, status (Completed / Voided / Failed; In progress for a sale being recorded). For `pqbg_view_costs` also unit cost and profit (total − qty × cost; "unknown" without a cost; "—" for rows that are not completed).
- **Date range:** Today (default), Yesterday, Last 7 days (today and the 6 days before), This month, Last month, or From/To (both inclusive; swapped if reversed). Whole days in the **site timezone** (Asia/Kolkata: a day starts at 18:30 UTC the day before).
- **Filters:** seller (everyone who has sold), paid by (including "Not recorded"), status, and search by product name or SKU (substring) or by a product code (typed, or a pasted scan URL; retired codes too).
- **Sorting** by date, sale #, product, qty or total (with the sale # as tie-break); **50 per page**.
- **Totals bar** for the filtered view, **completed sales only**: number of sales, items, revenue, and revenue per payment method; for `pqbg_view_costs` also cost and profit, **excluding lines with an unknown cost** ("excludes N lines with unknown cost (₹…)"), never counting them as zero; plus how many voided and failed rows the view contains.
- **Sale detail** (click the date or number): every snapshot (product, SKU, code, quantity, unit and regular price, total, currency, paid by, stock before → after and the stock holder; unit cost and profit for `pqbg_view_costs`), a link to the product while it exists, and the timeline: sold at/by, voided at/by with the reason ("Undone by the seller" for an undo), and the failure code.

### Void

From the sale detail, **Void sale** (for `pqbg_void_sale`) opens a confirmation page: a **required reason** (at most 500 characters) and **Return N to stock** (ticked by default). The POST (`admin-post.php?action=pqbg_void_sale`, nonce `pqbg_void_sale_{id}`) calls `SaleService::void_sale()` (the stock holder's lock and the atomic statement, as in Phase 7), then answers 303 to the detail with a message. Any age. A second void is refused ("already voided"); a busy lock, or stock tracking turned off since the sale (untick "Return to stock" then), is refused with an explanation and nothing changes. Voided rows stay in the history with who, when and why.

### CSV export

**Export CSV** exports the **current filtered view** (same filters and order): `admin-post.php?action=pqbg_sales_csv&_wpnonce=…&{filters}`, GET, `pqbg_view_all_sales`, no side effects.

- UTF-8 **with a byte order mark** (Excel shows ₹ correctly); `text/csv; charset=utf-8`, attachment `in-store-sales-{from}[-to-{to}].csv`, `nosniff`, `no-store`.
- Columns: date (site timezone, `Y-m-d H:i:s`), sale #, status, product, attributes, SKU, code, quantity, unit price, total, currency, paid by, seller, voided at/by, void reason, failure; for `pqbg_view_costs` also unit cost, cost, profit. Amounts are plain decimals.
- **Formula injection:** a text cell starting with `=`, `+`, `-`, `@`, a tab or a carriage return gets a leading apostrophe; plain numbers we generate (such as a negative profit `-60.00`) stay numbers.
- **Streamed:** IDs are read by keyset paging (5,000 at a time, continuing after the last sort value and ID) and rows fetched 1,000 at a time by primary key, written and flushed; memory stays flat and a sale recorded during the export never shifts a page.

### My sales (sellers)

`/scan/my-sales/` (and `?range=yesterday`, `?range=7d`), linked from the scan page header, for `pqbg_view_own_sales`. The same standalone mobile template, security headers and access flow as the scan page (logged out → login and back; no `pqbg_view_products` → the scan page's identical 403; no `pqbg_view_own_sales` → 403), GET/HEAD only (POST → 405), and other query strings redirect (301) to the canonical URL.

- **Only the logged-in user's own sales:** the seller comes from the session, never from the request.
- Tabs Today / Yesterday / Last 7 days; lines newest first (time, item, "qty × price = total", paid by, status; each links to its sale page), completed and voided (failed attempts changed no stock and are not listed); at most 300 lines, the summary always covers the whole range.
- **Summary (completed sales):** per payment method (every method offered, even without sales) and a total, plus the number of voided sales.
- Never shows cost or profit.
- The route reuses the scan rewrite rule: `my-sales` is lowercase and has no `DC-` prefix, so it can never be a product code. `ScanUrl::my_sales_url()` builds the URL. No new rewrite rule.

### Indexes and timings (dev machine, 50,000 sales)

Schema v3 adds `method_created (payment_method, created_at_gmt)` for the payment filter. EXPLAIN on 50,000 rows over 90 days (the Phase 9A suite prints it):

| Query | Without the index | With it |
|---|---|---|
| Count, this month + card | full scan (49,440 rows), 42.5 ms | `method_created` (2,032 rows), 2.5 ms |
| Totals, 90 days + card | `status_created`, 219 ms | `method_created`, 37 ms |
| List, 90 days + other | `created_at_gmt` (24,720 rows), 13.8 ms | `method_created` (1,558 rows), 1.1 ms |

A covering index for the totals (`status, created_at_gmt, payment_method, quantity, line_total, unit_cost`) was measured too (90-day totals 485 → 175 ms) and **not added**: the totals already stay under the target without it, and it would make every sale write a six-column index. The totals are one pass grouped by status and payment method (faster than totals plus a separate status count).

Measured by the Phase 9A suite (50,000 synthetic sales over 90 days, 10 sellers; best of 3 in-process with the object cache flushed; HTTP medians of 3):

| Measurement | Result |
|---|---|
| List page (rows + count), this month / 90 days / 90 days + card / + seller | 10.5 / 30 / 8 / 6 ms |
| List, 90 days + search / sorted by total | 179 / 169 ms |
| Totals bar, this month / 90 days / 90 days + card / + seller | 116 / 232 / 73 / 36 ms |
| History page over HTTP (admin): empty range (wp-admin itself) / this month / 90 days / 90 days + card | 685 / 631 / 773 / 543 ms (another run: 1,115 / 1,076 / 1,428 / 1,243 ms; the machine's load varies, the page adds at most ~0.1–0.3 s to an empty wp-admin page) |
| My sales over HTTP, last 7 days (~370 sales of that seller) | 241 ms |
| CSV of 50,018 rows, in-process / over HTTP | 3.7 s / 3.3 s, 6.9 MB; memory peak +7 MB |

The first CSV version took 31 s for the same export (OFFSET chunks alone 23.6 s); see the Phase 9A notes in `progress.md`.

### Limitations

- One payment method per sale; no split payments, no editing after the sale (void and sell again).
- Cost prices can be set only on the classic product edit screen by administrators (bulk/CSV cost import is Phase 10); Duplicate does not copy the cost.
- Profit ignores taxes, discounts and returns (out of scope). Unknown-cost lines are excluded from cost and profit and counted separately.
- The seller filter lists everyone who has ever sold; a deleted seller appears by the name snapshot.
- The history page's time is mostly wp-admin itself on this machine (0.7–1.1 s for an empty page, depending on load).

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

The Mark-as-Sold ledger (Phase 7). One row per sale attempt that reached the stock; rows are never deleted. See [Mark as Sold](#mark-as-sold).

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
- `source` (default `scan`), `status`: `pending`, `completed`, `voided` or `failed`
- void fields (`void_reason`, `voided_by`, `voided_at_gmt`), `note`, `created_at_gmt`
- **schema v2 (Phase 7):** `stock_holder_id` (the product whose stock the sale changed: the parent when a variation uses parent-level stock; undo restores exactly this one) and `failure_code` (`sold_online`, `error`, `interrupted`)
- **schema v3 (Phase 9A):** `payment_method` varchar(20) (`cash`, `upi`, `card`, `other`; NULL = not recorded), `unit_cost` decimal(26,8) (the effective cost price at the moment of sale; NULL = unknown), `seller_name` varchar(250) (the seller's display name at the moment of sale). All three are NULL on older rows and are written in the pending row.

Indexes: `request_id` (unique), `code_id`, `product_variation`, `seller_created`, `status_created`, `created_at_gmt`, `order_id`, (v2) `holder_status (stock_holder_id,status)` and (v3) `method_created (payment_method,created_at_gmt)`.

**Rules (Phase 7):**

- The price always comes from the current server-side `WC_Product` price. A browser-supplied price or stock value is never trusted.
- Stock is checked server-side, and insufficient stock blocks the sale even if backorders are enabled.
- No WooCommerce orders are created for these sales.
- Only `SaleRepository` writes this table (Install only migrates it).

### Options

| Option | Autoload | Purpose |
|---|---|---|
| `pqbg_db_version` | yes | integer schema version (currently `3`) |
| `pqbg_settings` | no | settings array (`settings_version`, `barcodes_enabled`, `scan_base_url`, `payment_methods`); read via `Plugin::settings()` / `Settings::get()` (defaults merged with `wp_parse_args`, unknown keys dropped). See [Settings](#settings). |
| `pqbg_install_lock` | no | short-lived install/migration lock; exists only while an install is running |
| `pqbg_rewrite_version` | yes | `{plugin version}:{rules version}` of the scan rules last flushed (Phase 6). Holds no data; removed on deactivation and uninstall. |
| `pqbg_svg_cache_index` | no | Phase 8 render cache index: `{transient key} => last used`, at most 2,000 entries. Not data; removed on every uninstall. |
| `_transient_pqbg_svg_{md5}` (+ `_transient_timeout_…`) | no | Phase 8 cached QR/barcode SVGs, 30-day expiry (in the object cache instead when the host has a persistent one). Not data; removed on every uninstall. |

User meta `pqbg_print_prefs` (Phase 8) holds each user's last-used print options; it is removed only with `PQBG_UNINSTALL_DELETE_ALL_DATA`.

Post meta `_pqbg_cost_price` (Phase 9A) holds a product's or variation's cost price (on a variable product: the default for its variations); see [Cost price](#cost-price). It is removed only with `PQBG_UNINSTALL_DELETE_ALL_DATA`.

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

| Version | Migration |
|---|---|
| 1 | `migrate_1`: the `pqbg_codes` and `pqbg_sales` tables, and the optional CHECK constraint |
| 2 | `migrate_2` (Phase 7): adds `pqbg_sales.stock_holder_id`, `pqbg_sales.failure_code` and the `holder_status` index. Additive (dbDelta): existing rows keep their values and get NULL; re-running changes nothing. |
| 3 | `migrate_3` (Phase 9A): adds `pqbg_sales.payment_method`, `pqbg_sales.unit_cost`, `pqbg_sales.seller_name` and the `method_created` index. Additive (dbDelta): existing rows keep their values and get NULL ("Not recorded" / unknown cost); re-running changes nothing. `install()` then syncs roles, which grants the new `pqbg_view_costs` to administrators. |

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
| `pqbg_view_costs` (Phase 9A) | | | ✔ |

`pqbg_view_costs`: see and edit cost prices, and see cost and profit in the sales history and its CSV. Nobody else sees costs anywhere (see [Cost price](#cost-price)).

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
- Phase 7 mapping: selling and undo need `pqbg_sell` (undo also: own sale, 10 minutes); the sale page needs `can_view_sale()`; `SaleService::void_sale()` needs `pqbg_void_sale`. No new capability was added.
- Phase 9A mapping: the In-store sales history, sale detail and CSV need `pqbg_view_all_sales`; the void screen and handler `pqbg_void_sale`; My sales `pqbg_view_own_sales` (after the scan page's `pqbg_view_products` gate); cost fields, cost and profit `pqbg_view_costs` (new, administrators only).

## HPOS

The plugin declares compatibility with the `custom_order_tables` feature through `FeaturesUtil::declare_compatibility()` on `before_woocommerce_init`.
It never reads, creates or writes orders or the legacy order tables. Sales go to `pqbg_sales` only.

## Deactivation

Deactivation is non-destructive. Tables, codes, sales, settings, the role and capabilities are all kept. Only the scan route's two rewrite rules are removed (with a soft flush), along with the `pqbg_rewrite_version` flag, so `/scan/` stops answering until the plugin is reactivated. There are no cron events.

## Uninstall

**By default, all data is preserved.** Deleting the plugin from the Plugins screen removes only runtime state: the transient install lock, the `pqbg_rewrite_version` flag and the render cache of QR/barcode images (Phase 8; the cache is not data). Tables, sales history, product codes, options, the Store Seller role and capabilities remain, and reinstalling picks them up again.

To permanently delete all plugin data, add this to `wp-config.php` **before** deleting the plugin:

```php
define( 'PQBG_UNINSTALL_DELETE_ALL_DATA', true );
```

This drops `pqbg_codes` and `pqbg_sales`, deletes `pqbg_settings` and `pqbg_db_version`, every user's remembered print options (`pqbg_print_prefs` user meta) and every cost price (`_pqbg_cost_price` post meta, Phase 9A), removes every `pqbg_*` capability (including `pqbg_view_costs`), and deletes the Store Seller role. Affected users keep their accounts.
**This cannot be undone. Back up the database first.** On multisite, only the site running the uninstall is affected.

## Operational notes

- QR codes point to the scan base URL: `home_url()` unless it is overridden on the settings page. **Do not print labels until the production URL is set.** The admin warning stays visible while the URL is local, and a second warning appears while a public URL uses `http://`. While the URL is local, the print page only prints labels marked "TEST – NOT FOR USE", after an explicit confirmation (see [Label printing](#label-printing)).
- The site timezone is Asia/Kolkata (set 2026-09-25). The plugin stores UTC (`*_gmt`) and displays times in the site timezone with `wp_date()`.
- WooCommerce "Coming Soon" mode is on for the whole site. The scan page works with it (see [WooCommerce Coming Soon](#woocommerce-coming-soon)). Logged-out staff log in through `wp-login.php`, because Coming Soon hides the My Account login form.
- Scan URLs need pretty permalinks (see [Permalinks](#permalinks)).
- Mail is not configured on this local XAMPP, so the low/no-stock e-mails sent after scan sales fail locally (about 2 s each, after the stock lock is released). Configure mail on the production server.
- Scan sales are not in WooCommerce reports or Analytics. Use `pqbg_sales` (Phase 9 adds the history UI).
