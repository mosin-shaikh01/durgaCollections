# Changelog

All notable changes to Product QR Code and Barcode Generator.

## 1.0.0 (2026-09-28)

The first release. It brings together everything built and tested before launch.

### Product codes
- Every product and every variation gets its own permanent, random code (for example `DC-7K4M-9P2X-Q8RT`), created automatically when an administrator or shop manager saves it.
- A code can be replaced (for example if a label was copied). The old code stops working at once and is never reused.

### Labels
- Each code becomes a QR code, and optionally a Code 128 barcode, that opens the product's scan page.
- Print labels from a product, from the products list or from Bulk tools, on A4 sheets or thermal label rolls. You can print one label per unit in stock.
- The QR code is never printed too small to scan. A layout that cannot fit it is refused.

### Selling in the shop
- Staff scan a label with their phone, log in with their own account and see the product, its price and its stock.
- They choose the quantity and the payment method (always required), and mark the item as sold. The shop's stock goes down at once, safely, even while online orders arrive.
- A sale can be undone within 10 minutes; after that, a manager can void it with a reason.
- Each seller sees their own sales under "My sales".

### Sales history and cost prices
- Managers see every in-store sale, with filters, totals and a CSV export for Excel.
- Administrators can enter cost prices. Only administrators ever see costs, profit or margin; shop managers and sellers never do.

### Reports and Dashboard
- In-store reports: Summary, end of day with the cash expected in the drawer, products, categories, sellers, busiest hours, stock, dead stock, slow sellers, voids, and profit (administrators only). Each report can be printed or exported to CSV.
- The plugin has its own "QR & Barcodes" menu, with a Dashboard showing today's figures and anything that needs attention.

### Bulk tools
- Create the missing codes for the whole catalogue in batches, with links to print their labels.
- Export all codes to CSV.
- Import cost prices from a CSV file. A preview comes first, and the prices are only saved after you confirm.

### Safety and checks
- A read-only Health check finds problems in the stored data (for example negative stock or a code on a deleted product) and explains them. It never changes anything.
- The Dashboard warns if it becomes slow on real data.
- Works with any WooCommerce theme, classic or block. The staff screens do not depend on the theme, and the plugin adds nothing to the shop's pages. Scan pages are never stored by page caches.
- Warns when permalinks are set to "Plain", because printed labels need pretty permalinks.
- Single sites only (multisite is refused). Requires WordPress 6.7, WooCommerce 9.0 and PHP 8.2 or newer. Tested with WordPress 7.1.2, WooCommerce 11.1.2 and PHP 8.5.6.

### Upgrading from a pre-release copy (0.1.0)
- Nothing to do. After the update, WordPress refreshes the scan links once, and label images are drawn again the first time they are printed. No data changes.
