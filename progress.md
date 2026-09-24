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
| Database | MariaDB 10.4.32 — db `sharayu`, user `root`, prefix `wp_` |
| Active theme | twentytwentyfive |
| Active plugins | none |
| Admin user | Dev-admin |

---

## Status

### Done
- [x] WordPress installed and verified — core files intact, all 12 tables present, homepage and login both return 200
- [x] Site title set to "Durga Collections"
- [x] Git repo initialized, `main` branch pushed to GitHub (first commit: `README.md`)

### Open items
- [ ] Add a `.gitignore` — `wp-config.php`, `wordpress-7.1.zip`, `wp-content/uploads/`, `wp-admin/`, `wp-includes/` are currently untracked but unignored
- [ ] Delete `wordpress-7.1.zip` (37 MB) from the web root — downloadable over HTTP
- [ ] Remove the empty `wordpress/` folder left over from extracting the zip
- [ ] Set permalinks (currently plain `?p=123`; `.htaccess` has an empty WordPress block, mod_rewrite is loaded)
- [ ] Decide on theme approach — customize twentytwentyfive, use a child theme, or build custom

### Backlog
_To be filled in — site structure, pages, content, plugins._

---

## Work log

### 2026-09-24
- Verified the WordPress installation end to end (files, config, database, HTTP response).
- Found 1 published page, 1 published post, 1 draft page, 1 navigation menu — stock post-install content.
- Initialized git, committed `README.md`, pushed to `origin/main`.

