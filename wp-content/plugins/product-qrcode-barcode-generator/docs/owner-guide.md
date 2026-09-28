# Owner guide

Product QR Code and Barcode Generator, version 1.0.0.

This guide is for the shop owner and managers. It explains what the plugin does and how to use it day to day, without technical detail. The seller's one-page guide is `seller-guide.html` (and `seller-guide.pdf`) in this folder. Technical documentation is in `README.md`.

## Contents

1. [What the plugin does](#1-what-the-plugin-does)
2. [Setting up](#2-setting-up)
3. [Staff accounts and roles](#3-staff-accounts-and-roles)
4. [Product codes](#4-product-codes)
5. [Printing labels](#5-printing-labels)
6. [Selling in the shop](#6-selling-in-the-shop)
7. [End of day and cash in the drawer](#7-end-of-day-and-cash-in-the-drawer)
8. [Sales history, voids and corrections](#8-sales-history-voids-and-corrections)
9. [Reports](#9-reports)
10. [Bulk tools](#10-bulk-tools)
11. [Cost prices](#11-cost-prices)
12. [Health check and the Dashboard](#12-health-check-and-the-dashboard)
13. [Backups, updates and removing the plugin](#13-backups-updates-and-removing-the-plugin)
14. [When something goes wrong](#14-when-something-goes-wrong)

---

## 1. What the plugin does

Every product and every variation (for example each size or colour) gets its own permanent code. You print the code as a QR label and stick it on the item. In the shop, a seller scans the label with their phone, sees the product, price and stock, and marks it as sold. The shop's stock goes down straight away, the same stock your online shop uses.

What it does **not** do:

- It does not create WooCommerce orders. In-store sales are kept in the plugin's own sales record. You see them under **QR & Barcodes**, not under WooCommerce → Orders or Analytics.
- It does not take payments. The seller records how the customer paid (Cash, UPI, Card or Other). The money itself is handled as usual.
- It never shows cost prices, profit or margin to anyone except administrators.
- It does not change how your online shop looks. Customers never see the plugin.

Everything is under **QR & Barcodes** in the wp-admin menu, just below Products. It has five pages: Dashboard, In-store sales, In-store reports, Bulk tools and Settings.

## 2. Setting up

Do these once, in this order.

1. **WooCommerce first.** WooCommerce must be installed and active. Turn on stock management (WooCommerce → Settings → Products → Inventory), and on each product tick "Track stock quantity". Items without tracked stock can be scanned but not sold.
2. **Permalinks.** Settings → Permalinks must be anything except "Plain" (for example "Post name"). Every label contains an address ending in `/scan/…`, and that only works with pretty permalinks. If they are Plain, the plugin shows a warning on the Dashboard and in the Health check.
3. **QR & Barcodes → Settings** (administrators only):
   - **Scan base URL.** Leave it empty to use your site's address, or enter the address phones should open. It must be the final, public `https://` address of the shop before you print real labels (see below).
   - **Enable barcodes.** Only if you use a USB or Bluetooth barcode scanner. QR codes work with any phone camera and need nothing extra.
   - **Payment methods offered.** Cash, UPI and Card are on by default; Other is available. At least one must stay on.
4. **Staff accounts.** See section 3.

> **Important: print real labels only after the shop is on its final https address.** A label contains the web address it was printed with. If you print labels while the site is still on a test address (such as `localhost`) or on `http://`, those labels will never work on the live shop and must be reprinted. While the address is local, the plugin warns "QR codes currently point to a local address. Do not print labels until the production URL is set." and marks test labels "TEST – NOT FOR USE".

## 3. Staff accounts and roles

**Give every person their own login. Never share an account.** Every sale records who made it, the "My sales" page shows each seller only their own sales, and a seller can only undo their own sale. A shared login makes all of this meaningless.

To create a seller: Users → Add New, choose the role **Store Seller**, and give them a strong password. They log in on their own phone the first time they scan a label.

What each role can see and do:

| | Store Seller | Shop Manager | Administrator |
|---|---|---|---|
| Scan labels, see product, price and stock | Yes | Yes | Yes |
| Sell, and undo their own sale within 10 minutes | Yes | Yes | Yes |
| My sales (own sales only) | Yes | Yes | Yes |
| Dashboard, In-store sales, In-store reports | No | Yes | Yes |
| Void any sale (with a reason) | No | Yes | Yes |
| Create and replace codes, print labels, Code tools | No | Yes | Yes |
| Cost prices, profit and margin (anywhere) | No | **No** | Yes |
| Import cost prices | No | No | Yes |
| Settings and Health check | No | No | Yes |

What a **shop manager** does **not** see: any cost price, profit, margin or stock value at cost; the Profit report; the Import cost prices tool; Settings; the Health check. Their Dashboard and reports show sales, revenue, stock and sellers only.

A **Store Seller** has no wp-admin menu at all. They only use the scan pages on their phone.

## 4. Product codes

- A code looks like `DC-7K4M-9P2X-Q8RT`. It is random, so it cannot be guessed, and it is permanent.
- Codes are created automatically when an administrator or shop manager saves a product. A simple product gets one code; a variable product gets one code per variation (not one for the parent).
- To create codes for many existing products at once, use **Bulk tools → Code tools** (section 10).
- On the product edit screen, the **QR & Barcode** box shows the code, lets you view or download the QR image, and print the label.
- **Replacing a code** ("Regenerate"): use this only if a label was copied or damaged in a way you do not trust. The old code stops working immediately ("This label is out of date") and is never used again. Every label with the old code must be replaced.
- Deleting or trashing a product does not delete its sales. A label of a trashed product shows "This product is in the trash".

## 5. Printing labels

You can start printing from three places:

- **One product:** on the product edit screen, **Print label** (for a variable product, **Print all variation labels** or **Print label** on one variation).
- **Several products:** Products list, tick the products, choose the bulk action **Print QR labels**.
- **Everything that just got a code:** Bulk tools → Code tools gives "Print labels" links after generating codes.

The **print setup** screen then lets you choose:

- **Layout.** A4 sheets: 3 × 7 (the default, 21 per page), 3 × 8, 4 × 10, 5 × 13. Thermal label printers: 50 × 25 mm, 38 × 25 mm, 100 × 50 mm. Or a custom size to match your label stock.
- **Start at position.** To reuse a partly used sheet, start at label N.
- **Copies.** A fixed number per item, or **one label per unit in stock**. With "one per unit in stock", an item with 12 in stock gets 12 labels. This needs the item's own tracked stock; if stock is shared with the parent product or not tracked, it prints 1 label and tells you. Items with no stock are skipped.
- **What to show:** product name, variation (size, colour…), SKU, price, store name. The code text is always printed. A printed price goes out of date when you change the price; the QR code always shows the live price.
- **Printer offset.** If your printer prints slightly off, move everything up to 5 mm right or down.

Then **Preview and print**. In the browser's print dialog set: scale **100%** ("Default" or "Actual size", never "Fit to page"), margins **None**, headers and footers **off**. For a thermal printer, also set the label size in the printer's own settings.

The plugin never prints a QR code too small to scan. If a layout is too small for the QR code, it tells you how much room it needs instead of shrinking it. At most 300 labels per print job.

**Before your first real labels:** print one sheet on plain paper, hold it against the label stock to check the alignment, and scan several labels with a phone. Without a printer you can still check: open the print page, choose "Save as PDF", open the PDF on a computer screen and scan the codes from the screen with a phone.

## 6. Selling in the shop

The seller's steps are in the seller guide. In short: scan the label, check the product, choose the quantity and how the customer paid, and press **Confirm sale**.

What happens behind the scenes:

- The price and stock always come from WooCommerce at that moment. The seller cannot change the price.
- The sale is refused if the item is out of stock, has no price, is not published, or if the price changed since the page was opened (the seller sees a clear message and nothing changes).
- Stock goes down at once and safely, even if an online order for the same item arrives at the same second. If the last unit just sold online, the in-store sale is refused ("This item just sold online") and stock is not changed.
- Pressing the button twice, or a poor connection, never sells twice.
- The seller can **undo** their own sale for **10 minutes**. After that the Undo button disappears by itself and only a manager can void the sale.

## 7. End of day and cash in the drawer

At closing time, open **QR & Barcodes → In-store reports → End of day** (or the "End of day" link on the Dashboard).

It shows, for the day:

- sales and revenue per payment method (Cash, UPI, Card, Other),
- sales voided today and by whom, and refunds of earlier days' sales,
- per seller,
- **Cash expected in drawer**: today's cash sales, minus the cash of earlier days' sales that were voided (refunded) today. A sale made and voided on the same day is simply not counted. Count the drawer and compare.

Use **Print** for a paper copy, or **Export CSV**. If the cash does not match, look at In-store sales for today, filtered by "Paid by: Cash".

## 8. Sales history, voids and corrections

**QR & Barcodes → In-store sales** lists every in-store sale: date and time, product, quantity, price, total, how it was paid, seller and status (Completed, Voided, Failed).

- Filter by day or date range, seller, payment method, status, or search by product name, SKU or code. The totals at the top cover the filtered list (completed sales only).
- Click a sale for its detail: everything recorded at the moment of sale, and who voided it and why.
- **Export CSV** exports what you see. To open it in Excel with the ₹ sign and codes intact, use Excel's Data → From Text/CSV.

**Voiding a sale** (shop managers and administrators): open the sale, **Void sale**, give a reason (required), and choose whether to put the quantity back in stock (ticked by default). Use it for a mistake noticed after 10 minutes, or a return. Voided sales stay in the history with who, when and why; nothing is ever deleted.

To change the payment method of a sale: void it (with the reason) and sell again with the right method.

## 9. Reports

**QR & Barcodes → In-store reports** (shop managers and administrators). Choose a period: Today, Yesterday, Last 7 days, This week, This month, Last month, Last 12 months, or Custom dates. Each report compares with the previous period up to the same point in time.

- **Summary:** revenue, sales, items, average sale, payment split and alerts.
- **Sales over time**, **Products** (best sellers), **Categories**, **Sellers**, **Peak times** (busiest hours and days).
- **End of day** (section 7) and **Voids & failed**.
- **Stock** (with low stock and value), **Dead stock** (items in stock with no sale for 30, 60 or 90 days, counting online orders too), **Slow sellers**.
- **Profit & margin** (administrators only).

Rules used everywhere: revenue counts completed sales only; profit is counted only where the cost is known, and the report says how many sales had no cost price; days follow the shop's timezone.

Every report has **Export CSV**, and several have **Print**. The **Dashboard** shows today's figures at a glance; they always match In-store reports → Summary → Today.

## 10. Bulk tools

**QR & Barcodes → Bulk tools.**

- **Code tools** (shop managers and administrators):
  - **Generate missing codes** creates codes for every product and variation that needs one, in batches. You can stop and continue later. When it finishes, it gives "Print labels" links (300 items per link).
  - **Export codes CSV:** every code with its product, variation, SKU and status. It never contains cost prices.
  - Why two different "missing code" numbers? The Dashboard counts only published items (what can be sold now); Code tools also counts drafts and private items.
- **Import cost prices** (administrators only): download the template, fill in the cost prices, upload it. You first see a **preview** of every change and every error (nothing is saved yet). Only after you confirm are the prices saved. A report shows what was changed.
- Every bulk action is recorded in a log (who, when, how many). Shop managers do not see cost-import entries.

## 11. Cost prices

Cost prices are optional. They let administrators see profit and margin.

- Enter them on the product edit screen (**Cost price (₹)** under the price; for variable products a **Default cost price** plus an optional cost per variation), or in bulk with Import cost prices.
- Only administrators can see or change them. Shop managers, sellers, customers, exports, labels and the scan pages never show them.
- Each sale remembers the cost at the moment of sale. Changing a cost later does not change past profit.
- A missing cost is "unknown", never zero. Reports leave those sales out of profit and say how many there were.

## 12. Health check and the Dashboard

**QR & Barcodes → Settings → Health check** (administrators only) looks for problems in the stored data and explains each one. It only reads; it never changes anything.

| Finding | What to do |
|---|---|
| Database tables | Deactivate and reactivate the plugin. |
| Scan links (permalinks) | Choose any permalink setting except Plain. Labels need no reprint. |
| Negative stock | Count the item and correct the stock on the product screen. |
| Sales without a stock snapshot | Nothing; stock and sale agree. |
| Interrupted sales | Nothing; they are closed automatically by the next sale. |
| Codes on missing or unsuitable items | Check the product; these labels cannot sell. |
| One active code per item | Should never happen. Keep a backup and ask for help before changing anything. |
| Cost prices | Re-enter the cost on the product or with Import cost prices. |
| Codes on trashed items, Sales of deleted items | Information only. |

The **Dashboard** shows administrators "The health check found N problems" when there is something to look at. It also warns when labels would point to a local or `http://` address, when permalinks are Plain, and when a code-generation run was interrupted.

If the Dashboard says it has become slow on real data (it only says so when it happens repeatedly), tell the person who looks after the website: there is a planned improvement for that case.

## 13. Backups, updates and removing the plugin

- **Backups.** Your web host should back up the database daily. The plugin's codes and sales are in the database, in tables ending in `pqbg_codes` and `pqbg_sales`. Make an extra backup before bulk actions on the whole catalogue and before updates.
- **Updates.** Install a new version by uploading its zip (Plugins → Add New → Upload Plugin; WordPress offers to replace the current version). Your codes, sales and settings stay.
- **Deactivating** the plugin stops scanning and selling (labels show an error page) but keeps all data. Reactivating brings everything back.
- **Deleting** the plugin from the Plugins screen also keeps your codes, sales, settings and the Store Seller role. This is on purpose: printed labels and your sales record survive an accidental delete, and reinstalling picks everything up again.
- **To remove everything permanently** (only if you will never use the labels or the sales record again): make a backup, ask the person who looks after the website to add `define( 'PQBG_UNINSTALL_DELETE_ALL_DATA', true );` to `wp-config.php`, then delete the plugin. This cannot be undone.

## 14. When something goes wrong

| What you see | Why, and what to do |
|---|---|
| A scanned label opens an error or "page not found" page | Permalinks are Plain, or the label was printed with another address. Check Settings → Permalinks and QR & Barcodes → Settings → Scan base URL. |
| "This label is out of date" | The code was replaced. Print a new label from the product. |
| "This product is in the trash" / "Not published – cannot be sold yet" | Restore or publish the product. |
| "Stock tracking is off for this product" | On the product's Inventory tab, tick "Track stock quantity for this product". |
| "This item has no price" | Set a price on the product. |
| A seller sees "You do not have permission" | Their account needs the Store Seller role (or Shop Manager). |
| A seller cannot log in on the phone | Reset their password under Users. Never give them another person's login. |
| "Someone else is selling this item right now" | Two people pressed Sell for the same item at the same moment. Wait two seconds and try again. |
| A label does not scan | Check the print scale was 100% and the label is not smudged; try the phone closer or further. Reprint from the product. |
| Cash in the drawer does not match End of day | Check In-store sales for today filtered by Cash, and voids. |

For anything else, the technical details are in `README.md` in the plugin folder.
