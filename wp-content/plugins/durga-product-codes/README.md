# Durga Product Codes

WooCommerce plugin for Durga Collections: product QR/barcode inventory for our own shop staff ("sellers").
Staff scan a product's code, see live WooCommerce product information, and mark it sold. Stock updates automatically and every sale is logged.

This is **not** a marketplace or multi-vendor system. Sellers are our own staff selling our own catalog.

## Current scope: Phases 2–3 (foundation, data layer, code generation)

Implemented:

- plugin bootstrap, PSR-4-style autoloader, requirement checks with an admin notice
- activation, deactivation and uninstall handling
- `dpc_codes` and `dpc_sales` tables, versioned migrations and an install lock
- the Seller role and DPC capabilities, with a central `Permissions` class
- `CodeRepository`, which enforces one active code per item in the application layer
- WooCommerce HPOS compatibility declaration
- **Phase 3:** `CodeGenerator`, which produces secure random codes, and `ProductCodeService`, which checks product eligibility and assigns codes. See [Product codes](#product-codes).

**Not implemented yet (later phases):** QR and barcode rendering, `/scan/` URLs and scan pages, login redirect flow, product screen, Mark-as-Sold, stock decrement, sales history and void UI, seller dashboard, label printing, CSV import/export, bulk generation and bulk tools, product admin UI and metaboxes, product lifecycle hooks (automatic code assignment), code regeneration/replacement, REST/AJAX endpoints, shortcodes and templates.

The plugin adds **no** public endpoints of any kind. Phase 3 code is a PHP service layer only. No hook, screen or endpoint calls it yet.

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
- If the random source fails, the result is a controlled `WP_Error` (`dpc_random_unavailable`), never a weaker fallback.

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
| ID that is not a WooCommerce product | No (`dpc_invalid_product`) | — |

The product type is resolved through `wc_get_product()` and `WC_Product::is_type()`, not raw post data.

**Product status** (draft, private, published and so on) is **not** checked. No status rule has been decided yet. It will be settled with admin code management (Phase 5).

"Purchasable" means the sellable unit, not WooCommerce's `is_purchasable()`, which depends on price and status and changes over time.

### Assigning a code

```php
use Durga\ProductCodes\ProductCodeService;

$row = ( new ProductCodeService() )->get_or_create( $product_or_variation_id, get_current_user_id() );
// array (the dpc_codes row) on success, WP_Error otherwise.
```

- **Authorization.** The acting `$user_id` must have `dpc_manage_codes` (Shop Manager, Administrator). Otherwise the call returns `dpc_forbidden`. The check uses the user passed in, not the current user.
- **Idempotent.** If the item already has an active code, that row is returned unchanged. No new code is generated and no second active code is created.
- **Eligibility** errors are `dpc_invalid_product` and `dpc_ineligible_product`. Nothing is written.
- All persistence goes through `CodeRepository::create_active()`. The generator and service contain no SQL.
- The code is stored only in `dpc_codes.code`, never in product meta, options or order data.

Phase 3 registers **no hooks**. Codes are not created automatically on product save or creation. A caller (the Phase 5 admin screens, or bulk tools later) must request them explicitly.

### Uniqueness and collision handling

Codes are globally unique across the whole `dpc_codes` table, including retired codes. Three layers enforce this:

1. **Random generation.** A single collision is extremely unlikely.
2. **Pre-insert check.** `CodeGenerator::generate_unique()` asks `CodeRepository::code_exists()`, which counts both active and retired codes. On a collision it draws a new candidate. It stops after `CodeGenerator::MAX_ATTEMPTS = 10` and returns `dpc_code_generation_failed`.
   - Why 10: even with a million stored codes, one candidate collides with probability about 1.3 × 10⁻¹². Ten collisions in a row means a broken random source or bad data. Failing loudly is safer than looping.
3. **`UNIQUE(code)` in the database.** This is the final authority. The check-then-insert sequence can race with another request. If the insert hits the unique index, `create_active()` rolls back and returns `dpc_code_conflict`. The service then:
   - uses the active code another request just assigned to the same item, if there is one
   - otherwise retries with a fresh code, at most `ProductCodeService::MAX_SAVE_ATTEMPTS = 3` times
   - if it still fails, returns `dpc_code_generation_failed`

A failed generation writes no row, never reuses an existing code and never changes other records. It is logged to the WooCommerce logger (source `durga-product-codes`) with the error code only.

The column collation (`utf8mb4_unicode_520_ci`) is case-insensitive, so a lowercase copy of an existing code is also rejected.

### Lifecycle: active → retired

- `active`: the item's current code. There is at most one per item.
- `retired`: kept forever for history. It is **never reactivated** (there is no method for that) and **never reissued**, to the same item or any other, because `code_exists()` and `UNIQUE(code)` both still see it.
- After `CodeRepository::retire()`, the next `get_or_create()` for that item generates a **new** code.
- There is no `orphaned` status.

**Regeneration is deferred.** An atomic "replace code" operation (retire the old code and create the new one in a single transaction, behind `dpc_manage_codes`) is not needed until codes can be managed in the admin area. It belongs to Phase 5. Until then, retire followed by `get_or_create()` is the only path.

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
| PHP | 8.1 | 8.5.6 |
| WooCommerce | 9.0 | 11.1.2 (HPOS on) |
| Database | MariaDB 10.2+ / MySQL 5.7+ | MariaDB 10.4.32 |

WooCommerce must be active. The `Requires Plugins: woocommerce` header makes WordPress enforce this at activation.
If WooCommerce is later deactivated, this plugin does nothing except show an admin notice to users who can manage plugins.

## Installation

1. Copy the plugin to `wp-content/plugins/durga-product-codes/`.
2. Activate it under **Plugins**. Network activation on multisite is refused; activate it per site.

Activation creates or updates the tables, runs pending migrations, creates `dpc_settings` and syncs roles and capabilities. It is safe to run repeatedly.

## Database

Tables use `$wpdb->prefix`. They are created with `dbDelta()` from `includes/Schema.php`, and all timestamps are UTC (`*_gmt`).

### `{prefix}dpc_codes`

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
3. **CHECK constraint `{prefix}dpc_codes_active_chk`, where the server supports it** (MariaDB 10.2+, MySQL 8.0.16+). Active product rows must have `active_product_id = product_id`; all other rows must have NULL. It is added by migration 1 if missing and skipped quietly on servers that don't support it.

### `{prefix}dpc_sales`

The future Mark-as-Sold ledger. It is empty in Phase 2.

`product_id` and `variation_id` follow WooCommerce's order-item convention: `product_id` is the simple product or the variation's parent, and `variation_id` is the variation (`0` for simple products).
Note that this differs from `dpc_codes.product_id`, which is the purchasable item itself.

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
| `dpc_db_version` | yes | integer schema version (currently `1`) |
| `dpc_settings` | no | settings array; read via `Plugin::settings()` (defaults merged with `wp_parse_args`, unknown keys dropped) |
| `dpc_install_lock` | no | short-lived install/migration lock; exists only while an install is running |

## Migrations

`Install::migrations()` maps each target version to a callback.

- On every boot, `Install::maybe_upgrade()` runs any migration newer than `dpc_db_version`, in order.
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

| Capability | Seller (`dpc_seller`) | Shop Manager | Administrator |
|---|:-:|:-:|:-:|
| `dpc_view_products` | ✔ | ✔ | ✔ |
| `dpc_sell` | ✔ | ✔ | ✔ |
| `dpc_view_own_sales` | ✔ | ✔ | ✔ |
| `dpc_view_all_sales` | | ✔ | ✔ |
| `dpc_void_sale` | | ✔ | ✔ |
| `dpc_manage_codes` | | ✔ | ✔ |
| `dpc_manage_settings` | | | ✔ |

The Seller role also has `read`. It has nothing else: no `edit_products`, `manage_woocommerce` or `edit_posts`.

Role sync (`Permissions::sync_roles()`) only adds or removes `dpc_*` capabilities, and only on these three roles. Every other capability is left untouched.
WooCommerce only lets Shop Managers assign the `customer` role, so only Administrators can make someone a Seller.

### Rules for later phases

- Check permissions through `Permissions::can_*()` or its constants, server-side, on every privileged operation.
- A REST `permission_callback` must use them. Never use `__return_true` for privileged routes.
- Logged-out users must never receive product or sales data.
- Nonces:
  - action: `Permissions::nonce_action( 'verb_object' )`, which gives `dpc_verb_object`
  - field: `_dpc_nonce`
  - REST requests use the core `wp_rest` nonce
  - A nonce check is always paired with a capability check.
- `CodeRepository` does not check capabilities itself. Callers must check `Permissions::can_manage_codes()` first.

## HPOS

The plugin declares compatibility with the `custom_order_tables` feature through `FeaturesUtil::declare_compatibility()` on `before_woocommerce_init`.
It never reads or writes orders or the legacy order tables.

## Deactivation

Deactivation is non-destructive. Tables, codes, sales, settings, the role and capabilities are all kept. Phase 2 adds no rewrite rules or cron events, so nothing needs flushing.

## Uninstall

**By default, all data is preserved.** Deleting the plugin from the Plugins screen removes only the transient install lock. Tables, sales history, product codes, options, the Seller role and capabilities remain, and reinstalling picks them up again.

To permanently delete all plugin data, add this to `wp-config.php` **before** deleting the plugin:

```php
define( 'DPC_UNINSTALL_DELETE_ALL_DATA', true );
```

This drops `dpc_codes` and `dpc_sales`, deletes `dpc_settings` and `dpc_db_version`, removes every `dpc_*` capability, and deletes the Seller role. Affected users keep their accounts.
**This cannot be undone. Back up the database first.** On multisite, only the site running the uninstall is affected.

## Operational notes

- QR URLs will be built from `home_url()`. Do not print production labels until the production domain is final.
- The site timezone is currently UTC. The plugin stores UTC regardless, but the store timezone (India) should be set deliberately.
- WooCommerce "Coming Soon" mode is on for the whole site. The future `/scan/` route must work with it.
