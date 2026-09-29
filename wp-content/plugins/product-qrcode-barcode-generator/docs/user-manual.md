# User manual

## How to use this manual

This manual explains the Product QR Code and Barcode Generator plugin in plain language, with examples. It replaces the earlier owner guide. The PDF version (`user-manual.pdf`) opens from the **Plugin guide** button on the plugin's Dashboard and from the **User manual** link on the Plugins screen.

Who reads what:

- **The shop owner (administrator):** everything. Sections marked **Administrator only** are about screens only administrators can see or use.
- **Shop managers:** chapters 1 and 3 to 11 and 14. The administrator-only parts are marked, so you can skip them.
- **Sellers:** chapter 6 (Selling on the phone). The one-page seller guide (`seller-guide.pdf`, linked from the Dashboard and from My sales) has the same steps with pictures.

Every screenshot shows a sample shop called "Sample Shop" with sample products (kurtas, dupattas, sarees…), sample staff (Owner, Ravi, Asha, Vikram) and sample sales. The amounts are in Indian rupees (₹). Your own screens show your shop, your products and your numbers.

Words used in this manual:

- **Item:** something you sell as one unit: a simple product, or one variation of a variable product (for example the size M of a kurta).
- **Code:** the item's permanent product code, for example `DC-7K4M-9P2X-Q8RT`. It is printed on the label as a QR code.
- **Label:** the sticker with the QR code that you put on the item.
- **Scan page:** the page a phone opens when it scans a label.

## 1. What the plugin does

Every product and every variation (for example each size or colour) gets its own permanent code. You print the code as a QR label and stick it on the item. In the shop, a seller scans the label with their phone, sees the product, the price and the stock, and marks it as sold. The stock goes down straight away: the same stock your online shop uses.

**Example.** A customer buys a cotton kurta in size M. Asha, a seller, scans the kurta's label with a phone, chooses quantity 1 and "Cash", and presses **Confirm sale**. The kurta's stock goes from 8 to 7, the sale appears in Asha's **My sales** list, and in the evening it is part of the **End of day** report as ₹1,499 in cash.

What the plugin does **not** do:

- It does not create WooCommerce orders. In-store sales are kept in the plugin's own sales record. You see them under **QR & Barcodes**, not under WooCommerce → Orders or Analytics.
- It does not take payments. The seller records how the customer paid (Cash, UPI, Card or Other). The money itself is handled as usual.
- It never shows cost prices, profit or margin to anyone except administrators.
- It does not change how your online shop looks. Customers never see the plugin.

Everything is in the **QR & Barcodes** menu in wp-admin, just below Products. It has five pages: **Dashboard**, **In-store sales**, **In-store reports**, **Bulk tools** and **Settings**.

## 2. Setting up {admin}

Do these steps once, in this order.

### 2.1 WooCommerce stock

WooCommerce must be installed and active. Turn on stock management (WooCommerce → Settings → Products → Inventory → "Enable stock management"), and on each product tick **Track stock quantity for this product** on its Inventory tab. Items without tracked stock can be scanned, but not sold.

### 2.2 Permalinks

**Settings → Permalinks** must be anything except "Plain" (for example "Post name"). Every label contains an address ending in `/scan/…`, and that address only works with these "pretty" permalinks. With "Plain", the plugin shows a warning on the Dashboard and an error in the Health check, and every label opens an error page. Changing the permalinks back fixes all labels; nothing needs to be reprinted.

### 2.3 Settings

Open **QR & Barcodes → Settings**.

![QR & Barcodes → Settings, with the scan base URL of the sample shop](shot:settings)

- **Scan base URL.** Leave it empty to use your site's address, or enter the address that phones should open. Before you print real labels it must be the final, public `https://` address of your shop. The line **QR codes will contain:** shows an example of what goes into every QR code.
- **Enable barcodes (for hardware scanners).** Tick this only if you use a USB or Bluetooth barcode scanner. QR codes work with any phone camera and need nothing extra.
- **Payment methods offered.** Cash, UPI and Card are on by default; Other is available. At least one must stay on. The seller must choose one for every sale.

> **Important: print real labels only after the shop is on its final https address.** A label contains the web address it was printed with. Labels printed while the site is on a test address (such as `localhost`) or on `http://` never work on the live shop and must be reprinted. While the address is local, the Dashboard and the print screen warn you, and test labels are marked "TEST – NOT FOR USE".

![The Dashboard's warning while the site still runs on a local test address](shot:dashboard-local-warning)

### 2.4 Staff accounts

Create one account for each person (see chapter 3). Then print your first labels (chapter 5).

## 3. Staff accounts and roles

**Give every person their own login. Never share an account.** Every sale records who made it, "My sales" shows each seller only their own sales, and a seller can only undo their own sale. A shared login makes all of this meaningless.

To create a seller: **Users → Add New**, choose the role **Store Seller**, and give them a strong password. They log in on their own phone the first time they scan a label. Shop managers use the WooCommerce role **Shop manager**.

Who sees and does what:

| | Store Seller | Shop manager | Administrator |
|---|---|---|---|
| Scan labels, see product, price and stock | Yes | Yes | Yes |
| Sell, and undo their own sale within 10 minutes | Yes | Yes | Yes |
| My sales (their own sales only) | Yes | Yes | Yes |
| Dashboard, In-store sales, In-store reports | No | Yes | Yes |
| Void any sale (with a reason) | No | Yes | Yes |
| Create and replace codes, print labels, Code tools | No | Yes | Yes |
| Cost prices, profit and margin (anywhere) | No | **No** | Yes |
| Import cost prices | No | No | Yes |
| Settings and Health check | No | No | Yes |

A **shop manager** does **not** see any cost price, profit, margin or stock value at cost, the **Profit & margin** report, the **Import cost prices** tool, **Settings** or the **Health check**. Their Dashboard and reports show sales, revenue, stock and sellers only.

A **Store Seller** has no wp-admin menu at all. They only use the scan pages on their phone.

## 4. Product codes

- A code looks like `DC-7K4M-9P2X-Q8RT`. It is random, so it cannot be guessed, and it is permanent.
- Codes are created automatically when an administrator or a shop manager saves a product. A simple product gets one code. A variable product gets one code per variation (the product itself has none).
- To give codes to many existing products at once, use **Bulk tools → Code tools** (section 10.1).
- On the product's edit screen, the **QR & Barcode** box shows the codes, lets you view or download the QR image and print labels.

![The QR & Barcode box of a variable product: one code per size](shot:product-box)

**Replacing a code** (**Regenerate…**): use this only if a label was copied or damaged in a way you do not trust. The old code stops working at once ("This label is out of date") and is never used again. Every label with the old code must be replaced.

Deleting or trashing a product does not delete its sales. A label of a trashed product shows "This product is in the trash".

## 5. Printing labels

### 5.1 Where to start

You can start printing from three places:

- **One product:** on the product's edit screen, **Print label** (for a variable product, **Print all variation labels** or **Print label** on one variation).
- **Several products:** the Products list: tick the products and choose the bulk action **Print QR labels**.
- **Everything that just got a code:** after **Generate missing codes**, Bulk tools shows **Print labels** links (section 10.1).

### 5.2 The print setup

![The print setup for the three sizes of the sample kurta, with "One label per unit in stock"](shot:print-setup)

- **Layout.** A4 label sheets: 3 × 7 (the default, 21 per sheet), 3 × 8, 4 × 10 or 5 × 13. Thermal label printers: 50 × 25 mm, 38 × 25 mm or 100 × 50 mm. Or a **Custom layout** to match your label stock.
- **Start at position.** To use a partly used sheet, start at label N (counted row by row from the top left).
- **Copies.** A fixed number **per item**, or **One label per unit in stock**.
- **Show on the label:** product name, variation attributes (size, colour…), SKU, price, store name. The code is always printed. A printed price goes out of date when you change the price; the QR code always shows the live price.
- **Printer offset.** If your printer prints slightly off, move everything a few millimetres right or down.

> **Example: one label per unit in stock.** The sample kurta has three sizes. After the day's sales there are 10 in size S, 7 in size M and 4 in size L. With **One label per unit in stock**, the print job has 10 + 7 + 4 = **21 labels**: exactly one A4 sheet of 3 × 7. An item whose stock is not tracked, or is shared with its parent product, gets 1 label (the screen says so), and an item with 0 in stock gets none. With **1 per item** instead, you would get 3 labels.

### 5.3 Preview and print

Press **Preview and print**. The next page shows the labels as they will print.

![The label preview (sample shop, sample address)](shot:print-preview)

In the browser's print dialog set: scale **100%** ("Default" or "Actual size", never "Fit to page"), margins **None**, and headers and footers **off**. For a thermal printer, also set the label size in the printer's own settings.

The plugin never prints a QR code too small to scan. If a layout is too small for the QR code, it says how much room it needs instead of shrinking it. A print job has at most 300 labels.

**Before your first real labels:** print one sheet on plain paper, hold it against the label stock to check the alignment, and scan several labels with a phone. Without a printer you can still check: choose "Save as PDF" in the print dialog, open the PDF on a computer screen and scan the codes from the screen with a phone.

## 6. Selling on the phone

This chapter is for everyone who sells: Store Sellers, shop managers and administrators. The one-page **seller guide** has the same steps with pictures; open it from the Dashboard or from the link at the bottom of **My sales**.

### 6.1 Log in and scan

Scan the item's label with the phone camera and open the link. The first time, the phone asks you to log in: use **your own** username and password. After that the phone remembers you.

![The login page on a phone](shot:phone-login)

### 6.2 Check the product

The scan page shows the product, the variation (for example the size), the price and the stock. **Check that it is the item in your hand.** If the label does not scan, type the code printed under the QR code into **Scan or type a code** and press **Look up**.

### 6.3 Quantity and payment method

Choose the **Quantity** and how the customer paid under **Paid by** (Cash, UPI, Card or Other: whatever the shop offers). The payment method is required. Then press **Confirm sale**.

![The product screen after scanning](shot:phone-scan) ![Quantity 2 and "UPI" chosen](shot:phone-sell)

### 6.4 Sold, and Undo

The next screen says **Sold.** with the quantity, the total, the payment method and the stock left. Press **Scan next item** for the next sale.

Made a mistake? Press **Undo this sale**. The sale is cancelled and the stock goes back. Undo is available for **10 minutes**; after that the button disappears by itself and only a manager can void the sale (chapter 7).

### 6.5 My sales

**My sales** (at the top of the scan page) lists your own sales for **Today**, **Yesterday** or the **Last 7 days**, with a summary per payment method. The link **How to sell (guide)** at the bottom opens the seller guide.

![A completed sale with the Undo button](shot:phone-undo) ![My sales: Asha's sales today](shot:phone-mysales)

### 6.6 When a sale is refused

A sale is refused, and nothing changes, when the item is out of stock, has no price, is not published, or when its price changed since the page was opened. The screen says why. "This item just sold online" means the last one went to an online order at the same moment. Pressing **Confirm sale** twice, or a poor connection, never sells twice.

![An item that is out of stock cannot be sold](shot:phone-refused)

## 7. In-store sales history and void

**QR & Barcodes → In-store sales** (shop managers and administrators) lists every in-store sale: date and time, product, quantity, price, total, how it was paid, seller and status (Completed, Voided, Failed).

![In-store sales, today, with the totals of the filtered list](shot:sales-list)

- Filter by day or date range (**Today**, **Yesterday**, **Last 7 days**, **This month**, **Last month**, or **From** and **To**), by seller, payment method or status, or search by name, SKU or code. The totals at the top are for the filtered list (completed sales only).
- **Export CSV** exports what you see. To open it in Excel with the ₹ sign and the codes intact, use Excel's **Data → From Text/CSV** instead of double-clicking the file.
- Click a sale to see everything recorded at the moment of sale: price, seller, payment method, stock before and after, and who voided it and why.

![The detail of one sale](shot:sale-detail)

**Voiding a sale.** Open the sale and press **Void sale**. Enter a **Reason (required)** and choose whether to return the quantity to stock (ticked by default). Use it for a mistake noticed after the 10 minutes of Undo, or for a return. A voided sale stays in the history with who voided it, when and why; nothing is ever deleted, and it no longer counts in the totals.

![Voiding a sale: the reason is required](shot:void-form)

To change the payment method of a sale: void it (with the reason "wrong payment method") and sell the item again with the right method.

## 8. End of day and cash in the drawer

At closing time open **QR & Barcodes → In-store reports → End of day** (or **End of day** on the Dashboard). It shows, for the day:

- sales and revenue per payment method (Cash, UPI, Card, Other),
- sales voided today, and refunds of sales from earlier days,
- each seller's figures,
- **Cash expected in drawer**: today's cash sales, minus the cash of earlier days' sales that were voided (refunded) today. A sale made and voided on the same day is simply not counted.

![End of day for the sample shop](shot:end-of-day)

> **Example.** Today the sample shop sold two kurtas at ₹1,499 and a linen shirt at ₹1,299, all paid in cash: ₹4,297. A customer also returned a silk dupatta bought yesterday for ₹899 in cash, and Ravi voided that sale today and gave the money back. **Cash expected in drawer = ₹4,297 − ₹899 = ₹3,398.** Count the drawer and compare. UPI and card sales are listed too, but they are not cash in the drawer.

Use **Print** for a paper copy, or **Export CSV**. If the cash does not match, open **In-store sales** for today, filtered by **Paid by: Cash**, and check the voids.

## 9. Reports

**QR & Barcodes → In-store reports** (shop managers and administrators). Choose a period: Today, Yesterday, Last 7 days, This week, This month, Last month, Last 12 months, or your own dates. Each report compares with the previous period up to the same point in time ("Compared with …").

![In-store reports → Summary for the last 7 days](shot:reports-summary)

- **Summary:** revenue, sales, items, average sale, the split by payment method, and alerts (low stock, out of stock, voids, items without a code).
- **Sales over time**, **Products** (best and slow sellers), **Categories**, **Sellers**, **Peak times** (the busiest hours and days).
- **End of day** (chapter 8) and **Voids & failed**.
- **Stock** (with low stock and the value of the stock), **Dead stock** (items in stock with no sale for 30, 60 or 90 days, counting online orders too).
- **Profit & margin** (administrators only; see below).

Rules used everywhere: revenue counts completed sales only; days follow the shop's timezone; amounts are as recorded at the moment of sale. Every report has **Export CSV**, and several have **Print**.

### 9.1 Profit & margin {admin}

Profit is counted only where the cost price is known. The report says how many sales had no cost price, and leaves them out of profit and margin. Each sale keeps the cost price it had at the moment of sale, so changing a cost later does not change past profit.

![Profit & margin for the last 7 days (administrators only)](shot:reports-profit)

Cost prices are optional. Enter them on the product's edit screen (**Cost price (₹)** under the price; for a variable product a default cost price plus, if needed, one per variation), or many at once with **Import cost prices** (section 10.3). A missing cost is "unknown", never zero.

## 10. Bulk tools

**QR & Barcodes → Bulk tools** has two tabs: **Code tools** (shop managers and administrators) and **Import cost prices** (administrators only). Every bulk action is recorded in **Recent bulk runs** (section 10.4).

### 10.1 Generate missing codes

**Worked example: a catalogue of 200 T-shirts.** The sample shop imported 200 new T-shirts. Imported products have no codes yet, because codes are only created automatically when someone saves a product in wp-admin. **Code tools** shows how many items need one.

![Code tools: 200 published simple products without a code](shot:codes-before)

1. Check the numbers under **Without a code**. Tick the product statuses to include (normally only **Published**).
2. Tick **I understand that up to 200 new codes will be created for the ticked statuses, and that codes are permanent.**
3. Press **Generate missing codes**. The codes are created in batches of 100 while the page shows the progress (**100 of about 200 items**). Keep the page open; it continues by itself.

**Stop and continue.** You can press **Stop** at any time, for example to close the shop's computer. The codes already created stay. Code tools then shows **Stopped** with the counts so far, and the Dashboard reminds you: "Code generation was stopped before it finished." Press **Continue** (in Code tools, or **Continue in Bulk tools** on the Dashboard) to carry on where it stopped. If the page was simply closed, the run shows as **Interrupted** after a few minutes and can be continued the same way.

![A run stopped after the first 100 codes](shot:codes-stopped)

When the run is **Finished**, the summary shows **Codes created: 200** and links **Print labels for the codes just created**. Each link opens the label print setup for up to 300 items, so 200 T-shirts need one link: **Print labels: items 1–200**. Then continue as in chapter 5 (for example with **One label per unit in stock**).

![Finished: 200 codes created, with the link to print their labels](shot:codes-finished)

Codes are permanent: they are never deleted, so check the count before you start. Existing codes are never changed. Variable products themselves never get a code (their variations do), and items in the trash never do.

Why can the Dashboard's number be lower? The Dashboard counts only **published** items without a code (what can be sold now); Code tools also counts drafts, private, pending and scheduled items.

### 10.2 Export codes CSV

**Export codes (CSV)** downloads every code with its product, variation, SKU, product status, code status and scan URL. Choose **Active codes**, **Retired codes** or both, and tick **Also list products and variations without a code** to find the gaps. Then press **Download CSV**.

![Export codes (CSV)](shot:codes-export)

The file never contains cost prices. To open it in Excel with SKUs such as "00123" intact, use **Data → From Text/CSV** and set the SKU column to Text.

### 10.3 Import cost prices {admin}

**Worked example.** The owner wants to enter the cost prices of two new products, remove a wrong cost price, and (by mistake) types a letter O instead of a zero in one cost.

**Step 1 – the template.** On the **Import cost prices** tab, press **Download the template (all products with their current cost prices)**. It is a CSV file with one row per product and variation: ID, parent ID, type, SKU, product, attributes and **Cost price (₹)**.

**Step 2 – fill in the costs.** Open the file in Excel and change only the **Cost price** column. You can delete the rows you do not change. In the example:

- Printed cotton scarf: **180** (₹180)
- Wool shawl – grey: **1,200.50** (commas and decimals are fine)
- Silk dupatta – maroon: **clear** (removes the cost price; it becomes "unknown")
- Linen shirt – white: **78O** (a typing mistake: the letter O)
- Handloom saree – green: left **empty** (an empty cell changes nothing)

![The filled-in file, as it looks in a spreadsheet](shot:cost-file)

Numbers such as 1200.50, 1,200.50, 1,20,000, ₹1200, Rs. 1200 or 1200/- are all accepted. 0 means a known cost of zero. Save the file from Excel as **CSV UTF-8 (Comma delimited)**.

**Step 3 – upload and preview.** Choose the file under **CSV file** and press **Upload and preview**. Nothing is saved yet. The preview counts each outcome (**Update**, **Clear (becomes unknown)**, **No change**, **Error**) and lists the rows, errors first, with the reason.

![The preview: 2 updates, 1 clear, 1 empty cell and 1 error row with its reason](shot:cost-preview)

In the example the shirt's row says **Error**: "Not a number. Use e.g. 1200.50, 1,200.50, 1,20,000 or ₹1200…". You can correct the file and upload it again (**Cancel and upload another file**), or apply the valid rows now and fix the shirt later.

**Step 4 – apply.** Tick **Apply the 3 valid changes and skip the 1 rows with errors** (shown only when there are errors) and press **Apply 3 changes**. The prices are saved in batches.

**Step 5 – the report.** When it is done, the page shows **Applied** with the counts. **Download the full report (CSV)** gives every row with the old and new cost; it stays available for an hour, so keep it if you need a record.

![The import report: 3 cost prices applied](shot:cost-report)

If someone changed a cost on the product screen between your preview and your apply, that row is skipped ("Skipped: the cost was changed after the preview") so nothing is overwritten by mistake.

### 10.4 Recent bulk runs

The **Code tools** tab ends with **Recent bulk runs**: when, which tool and who, for code generation, code exports and cost imports. Only the counts are kept, never the prices. Shop managers do not see cost-import entries at all. The Dashboard shows the latest runs too.

![Recent bulk runs (administrator's view)](shot:recent-runs)

## 11. The Dashboard

**QR & Barcodes → Dashboard** (shop managers and administrators) shows today at a glance:

- **Needs attention** (only when something does): for example a local or `http://` scan address, plain permalinks, a stopped code-generation run, or, for administrators, problems found by the Health check.
- **Today in the shop:** revenue, sales and items sold today (administrators also see gross profit and margin), the split by payment method and the voids. The figures always match **In-store reports → Summary → Today**.
- **Products and codes** (for users who manage codes): published items without a code, low stock and out of stock, each with a link.
- **Recent bulk runs**, **Setup** (the scan address, barcodes and payment methods) and **Quick links**.

![The Dashboard for the owner (administrator), with the Plugin guide button](shot:dashboard-admin)

**Plugin guide.** The button next to the Dashboard heading opens this manual (PDF) in a new tab. Point at it, or move to it with the Tab key, to see "How to use this plugin: step-by-step guide (PDF)". Next to it, **Seller guide (1 page, for staff)** opens the seller guide, to print or send to new staff. Administrators also find a **User manual** link in the plugin's row on the **Plugins** screen.

![The User manual link on the Plugins screen](shot:plugins-row)

A shop manager sees the same Dashboard without anything about costs, profit or settings:

![The Dashboard for a shop manager](shot:dashboard-manager)

If the Dashboard says it has become slow on real data (it only says so when it happens repeatedly), tell the person who looks after the website: there is a planned improvement for that case.

## 12. Health check {admin}

**QR & Barcodes → Settings → Health check** looks for problems in the stored data and explains each one. It only reads; it never changes anything.

![The Health check of the sample shop: no problems found](shot:health-check)

| Finding | What to do |
|---|---|
| Database tables | Deactivate and reactivate the plugin. |
| Scan links (permalinks) | Choose any permalink setting except "Plain". Labels need no reprint. |
| Negative stock | Count the item and correct the stock on the product screen. |
| Sales without a stock snapshot | Nothing; stock and sale agree. |
| Interrupted sales | Nothing; they are closed automatically by the next sale of the item. |
| Codes on missing or unsuitable items | Check the product; these labels cannot sell. |
| One active code per item | Should never happen. Keep a backup and ask for help before changing anything. |
| Cost prices | Re-enter the cost on the product or with Import cost prices. |
| Codes on trashed items, Sales of deleted items | Information only. |

When the Health check finds errors or warnings, the Dashboard shows administrators "The health check found N problems in the plugin's data" with a link.

## 13. Backups, updates and removing the plugin

- **Backups.** Your web host should back up the database every day. The plugin's codes and sales are in the database, in tables ending in `pqbg_codes` and `pqbg_sales`. Make an extra backup before a bulk action on the whole catalogue and before every update.
- **Updates.** Install a new version by uploading its zip file (**Plugins → Add New → Upload Plugin**; WordPress offers to replace the current version). Your codes, sales and settings stay.
- **Deactivating** the plugin stops scanning and selling (labels show an error page) but keeps all data. Reactivating brings everything back.
- **Deleting** the plugin from the Plugins screen also keeps your codes, sales, settings and the Store Seller role. This is on purpose: printed labels and your sales record survive an accidental delete, and reinstalling picks everything up again.
- **To remove everything permanently** (only if you will never use the labels or the sales record again): make a backup, ask the person who looks after the website to add `define( 'PQBG_UNINSTALL_DELETE_ALL_DATA', true );` to `wp-config.php`, then delete the plugin. This cannot be undone.

## 14. Common problems

| What you see | Why, and what to do |
|---|---|
| A scanned label opens an error or "page not found" page | Permalinks are "Plain", or the label was printed with another address. Check Settings → Permalinks and QR & Barcodes → Settings → Scan base URL. |
| "This label is out of date" | The code was replaced. Print a new label from the product. |
| "This product is in the trash" / "Not published – cannot be sold yet" | Restore or publish the product. |
| The item cannot be sold because stock is not tracked | On the product's Inventory tab, tick "Track stock quantity for this product". |
| "Out of stock – cannot be sold." | Check the item and correct its stock on the product screen if it is wrong. |
| The item has no price | Set a price on the product. |
| A seller sees "You do not have permission" | Their account needs the Store Seller role (or Shop manager). |
| A seller cannot log in on the phone | Reset their password under Users. Never give them another person's login. |
| "Someone else is selling this item right now" | Two people pressed Confirm sale for the same item at the same moment. Wait two seconds and try again. |
| A label does not scan | Check the print scale was 100% and the label is clean; hold the phone closer or further. Reprint from the product. |
| Labels were printed with a local or http:// address | They will never work on the live shop. Set the Scan base URL (section 2.3) and reprint them. |
| Cash in the drawer does not match End of day | Check In-store sales for today filtered by Cash, and the voids. |
| The Plugin guide button opens nothing | The browser blocked the new tab: allow pop-ups for your site, or right-click the button and choose "Open link in new tab". |
| The manual downloads instead of opening | Some phones and browsers save PDF files instead of showing them. Open the downloaded file. |

For anything else, the technical details are in `README.md` in the plugin folder.
