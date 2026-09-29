# User manual build (1.0.1)

Regenerates `docs/user-manual.pdf` from `docs/user-manual.md`, with fresh screenshots of a sample shop. Build tooling only: not loaded by the plugin, not in the release zip (`build/` is excluded by `build/package.php`), and refused over HTTP (`build/.htaccess`).

| File | Purpose |
|---|---|
| `build-manual.php` | The orchestrator: sample data, screenshots, compression, the PDF, the checks and the cleanup |
| `shots.mjs` | The screenshots (headless Chrome or Edge, one browser profile per sample user) |
| `render.mjs` | Markdown to HTML to PDF, in two passes for the table of contents' page numbers |
| `pdf-text.mjs` | Reads a PDF's text, title and bookmarks (also used by `tests/phase14-manual.php`) |
| `repair.php` | The start snapshot and the repair mode (`--repair`) after a killed build; see below |
| `manual.template.html`, `manual.css` | The cover, the table of contents and the print styles (A4) |
| `package.json`, `package-lock.json` | The two build packages, pinned to exact versions with integrity hashes: `marked` 18.0.14 (Markdown) and `pdfjs-dist` 6.3.289 (PDF text) |

## One-time setup

Install the packages **outside the site**, as the other test tools:

```
mkdir C:\xampp\tools\pqbg\manual
copy build\manual\package.json build\manual\package-lock.json C:\xampp\tools\pqbg\manual\
cd C:\xampp\tools\pqbg\manual
npm ci --omit=optional
```

(`--omit=optional` leaves out pdfjs-dist's optional canvas package: only text is read. pdfjs prints one warning about it; that is expected.)

The screenshots use `puppeteer-core` from the theme-check tool (`PQBG_THEMECHECK`, see `tests/README.md`) and the installed Chrome or Edge.

## Build

Take a database backup first (the standing rule for anything that writes test data), check that at least 1.5 GB of memory is free, then, from the plugin folder:

```
set PQBG_TESTS_ALLOW_PRODUCTION=1            (only on a site without WP_ENVIRONMENT_TYPE=local)
set PQBG_THEMECHECK=C:\xampp\tools\pqbg\theme-check\check.mjs
set PQBG_MANUAL_TOOLS=C:\xampp\tools\pqbg\manual
set PQBG_MANUAL_SHOTS=C:\xampp\backups\sharayu\manual-shots   (optional: keep the screenshots)
php build/manual/build-manual.php --with-seller-guide
```

`--with-seller-guide` first rebuilds `docs/seller-guide.html` and `.pdf` (`tests/guide-screenshots.php`), so one command renews every document after a version or screen change. It takes about 10 minutes and honours `PQBG_STOP_FILE` (the runner's memory watchdog).

**Do not run it close to midnight in the shop's timezone:** the worked examples use "today" and "yesterday".

What it does, in order:

1. Creates sample data only: users "Owner (sample)" (administrator), "Ravi (sample)" (shop manager), "Asha" and "Vikram" (Store Sellers); a kurta in three sizes and six simple products (SKUs `SAMPLE-…`, categories "… (sample)") with their codes and some cost prices; 27 days of sales through `SaleService::sell()`, back-dated on the build's own rows (a fixed random seed, so every build has the same history); today's sales and a void, so that End of day shows the manual's example (cash ₹4,297 − ₹899 = ₹3,398).
2. Phone screenshots (375 × 667 at 2×) through the real scan page, including a real sale by "Asha".
3. Administrator screenshots (1280 × 800), with the Scan base URL temporarily `https://shop.example.com`; 200 T-shirts without codes for the Bulk tools example (one batch, Stop, Continue, through `BulkGenerator`); the cost import through the real upload form, with the template from `CostImport::write_template()` filled in.
4. The browser shows "Sample Shop" instead of the site title. A screenshot fails if its page shows the real site title, or a real administrator's login, name or e-mail.
5. Every screenshot is compressed to an 8-bit palette PNG of at most 200 KB; the PDF must be at most 4 MB, and its text must not contain a real name either. Only then is `docs/user-manual.pdf` replaced.
6. Cleanup (always, in `finally`): every sample product, code, sale, category (and its WooCommerce category-lookup rows), user and Action Scheduler job, and every plugin option restored byte for byte. The build then checks that no sample row is left, that no orphaned WooCommerce category lookup row was added (the shared guard in `tests/bootstrap.php`), and that every table has the same number of rows as before (the options table may differ by transients only, which are listed).

## If a build is killed: the repair mode

**If a manual build is killed, run the repair mode before anything else.** Before it creates anything (and before the seller guide), the build saves a snapshot, `pqbg-manual-start.ser` in the system temp folder, with the plugin's options byte for byte and the highest post, user, term, Action Scheduler job and log IDs. It deletes the snapshot only when its own cleanup checks passed; while a snapshot exists, a new build refuses to start.

```
php build/manual/build-manual.php --repair            (dry run: lists what the killed build left; exit 3 when it found something)
php build/manual/build-manual.php --repair --apply    (removes exactly that, verifies, then deletes the snapshot)
```

It identifies each item by two things, being above the snapshot's highest ID and carrying a sample marker: users `pqbg_manual_*` / `pqbg_guide_*` (the sample administrator first); products and variations with a `SAMPLE-` SKU or no SKU at all (a save cut short by the kill leaves a bare row), with their variations; posts written by a sample user; the plugin's code and sales rows of those posts; "… (sample)" categories; orphaned WooCommerce category and product lookup rows; Action Scheduler jobs naming a sample post ID (any status) with their logs, and orphaned logs (swept again after the deletes, because WooCommerce queues and runs an attribute lookup job for every deleted product; the verification counts them too); every option whose name contains `pqbg` (restored from the snapshot, or deleted when the snapshot had none); the `pqbg-manual-*` / `pqbg-guide-*` temp folders. Nothing else is touched, `docs/` included. Show the dry run to the owner before `--apply` (the standing rule for a killed run's data).

This happened once, on 2026-09-28, before the repair mode existed; the leftovers were then removed by hand with the owner's approval (see `progress.md`).

The PDF's bytes differ between builds (Chrome writes the creation date into it, and the sale times differ); the content comes from the same sources. The release zip stays byte-reproducible because the built PDF is committed.

## Writing the manual

`docs/user-manual.md` conventions (see the top of `render.mjs`): `## N. Title` is a chapter (new page, table of contents, bookmark), `### N.M Title` a section; a heading ending in ` {admin}` gets the "Administrator only" badge; `![Caption](shot:name)` inserts a screenshot (names starting with `phone-` are phone screens; figures alone in one paragraph stand side by side). Headings must be unique. Bold text names what is on the screen: `tests/phase14-manual.php` checks it against the plugin's strings.

Rebuild the manual whenever a screen it shows, or its text, changes.
