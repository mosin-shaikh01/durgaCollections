=== Product QR Code and Barcode Generator ===
Tags: woocommerce, qr code, barcode, labels, point of sale
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

QR code and barcode labels for a WooCommerce shop's own staff: product codes, label printing, in-store selling by scanning, sales history and reports.

== Description ==

Every product and variation gets a permanent, random code. Print the codes as QR (and optionally Code 128) labels. Staff scan a label with their phone, log in with their own account, and sell the item in the shop. Stock changes at once, safely alongside online orders.

* Labels on A4 sheets or thermal rolls, including one label per unit in stock.
* Selling by scanning, with a required payment method, Undo within 10 minutes, and "My sales" for each seller.
* Sales history, in-store reports (end of day with the cash expected in the drawer, products, sellers, stock, dead stock, profit) and CSV exports.
* Bulk tools: create missing codes, export codes, import cost prices with a preview.
* Cost prices, profit and margin are visible to administrators only.
* A read-only Health check.
* Works with any WooCommerce theme, classic or block.

For shop staff only: this is not a marketplace or vendor system. Single sites only.

Plain-language guides for the owner and the sellers are in the `docs` folder. Technical documentation is in `README.md`.

== Requirements ==

* WordPress 6.7 or newer, WooCommerce 9.0 or newer (WC tested up to 11.1), PHP 8.2 or newer.
* Pretty permalinks (not "Plain"). Every printed label contains a `/scan/` address.
* Tested with WordPress 7.1.2, WooCommerce 11.1.2 and PHP 8.5.6. The lower minimums are enforced but were not run.

== Installation ==

1. Install and activate WooCommerce.
2. Plugins > Add New > Upload Plugin, choose `product-qrcode-barcode-generator-1.0.0.zip`, then Install Now and Activate.
3. Open QR & Barcodes > Settings and check the Scan base URL, the payment methods and the label settings.
4. Create one account per seller with the Store Seller role.

See `docs/owner-guide.md` for the full setup.

== Uninstalling ==

Deleting the plugin keeps the product codes, the sales record, the settings and the Store Seller role, so that printed labels and the sales history survive an accidental delete. To remove all plugin data permanently, add `define( 'PQBG_UNINSTALL_DELETE_ALL_DATA', true );` to `wp-config.php` before deleting the plugin. This cannot be undone.

== Changelog ==

= 1.0.0 =
* First release. See CHANGELOG.md for the full list.

== Licences ==

The plugin is GPL-2.0-or-later (see LICENSE). It bundles bacon/bacon-qr-code and dasprid/enum (BSD-2-Clause) and picqer/php-barcode-generator (LGPL-3.0-or-later). Because of the LGPL-3.0 component, the plugin as distributed is covered by GPL-3.0 through the "or later" clause. Licence texts and the list of changes made to the libraries are in `vendor-prefixed/` (see `NOTICE.md`).
