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

## Repository

Branch `main`, tracking `origin/main`. Working tree clean.

Tracked files — project code only; WordPress core, `wp-config.php`,
uploads and archives are excluded by `.gitignore`:

```
.gitignore
README.md
progress.md
```

| Commit | Message |
|---|---|
| `11e07fb` | Record tracking audit in progress log |
| `094e737` | Add .gitignore and progress tracker |
| `252e2d0` | first commit |

**Note for later:** `.gitignore` ignores `wp-content/*` wholesale, so a new
custom theme or plugin will not appear in `git status` until it is
un-ignored. The pattern to add is in the commented block near the bottom
of `.gitignore`:

```
!wp-content/themes/
!wp-content/themes/durga-collections/
```

---

## Status

### Done
- [x] WordPress installed and verified — core files intact, all 12 tables present, homepage and login both return 200
- [x] Site title set to "Durga Collections"
- [x] Git repo initialized, `main` branch pushed to GitHub (first commit: `README.md`)
- [x] `.gitignore` added — excludes `wp-config.php`, `.htaccess`, `*.zip`/`*.sql`, WP core, `wp-content` uploads/cache, bundled themes and plugins, OS/editor cruft, `node_modules/`, `vendor/`
- [x] Exclusions verified with `git check-ignore` — confirmed `wp-config.php`, `wordpress-7.1.zip`, `wp-content/uploads`, `wp-admin` and `wp-includes` are genuinely ignored
- [x] `progress.md` created and committed
- [x] Deleted `wordpress-7.1.zip` (37 MB) from the web root — now returns 404 over HTTP
- [x] Removed the empty leftover `wordpress/` folder
- [x] Full tracking audit passed — `git add -A` dry run stages only intended files, recursive untracked scan returns 0, and no sensitive path appears anywhere in history

### Open items
- [ ] Set permalinks (currently plain `?p=123`; `.htaccess` has an empty WordPress block, mod_rewrite is loaded)
- [ ] Decide on theme approach — customize twentytwentyfive, use a child theme, or build custom

### Backlog
_To be filled in — site structure, pages, content, plugins._

---

## Work log

### 2026-09-24
- Verified the WordPress installation end to end: core files, `wp-config.php`, database connectivity, table set, and HTTP response.
- Found 1 published page, 1 published post, 1 draft page, 1 navigation menu — stock post-install content.
- Flagged three housekeeping issues: the 37 MB installer zip sitting in the web root, an empty leftover `wordpress/` folder, and an empty WordPress block in `.htaccess`.
- Initialized git, committed `README.md`, pushed to `origin/main` (`252e2d0`).
- Wrote `.gitignore` and confirmed the sensitive paths were excluded before staging anything else.
- Dropped the stock `wp-content/index.php` from tracking — it is a WordPress core file, not project code.
- Committed `.gitignore` and `progress.md`, pushed to `origin/main` (`094e737`).
- Audited tracking before the next push: verified the tracked set, ran `git add -A --dry-run`, scanned recursively for untracked-and-unignored files (0 found), and checked the full commit history for sensitive paths (none). No corrections were needed.
- Noted that `git check-ignore wp-content` reports the directory as unignored because the rule is `wp-content/*`, which matches contents rather than the folder — expected git behaviour, no effect on what gets staged.
- Confirmed `wordpress/` was genuinely empty (0 entries including hidden files) and that `wordpress-7.1.zip` was referenced by no code or config, then deleted both.
- Re-checked site health after deletion: homepage and login still return 200, and the zip URL now returns 404. The installer remains re-downloadable from wordpress.org if ever needed.
