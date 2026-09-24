# Vendor build

Rebuilds `../vendor-prefixed/`, the bundled QR and barcode libraries with their namespaces prefixed. The plugin never runs Composer on the server; only the prefixed output is shipped.

This directory is build tooling only, and must be **excluded from any production deployment**:
- It is never loaded by the plugin.
- Every PHP file exits unless run from the CLI.
- `.htaccess` answers every HTTP request with 403.

| File | Purpose |
|---|---|
| `composer.json` / `composer.lock` | Exact library versions: `bacon/bacon-qr-code` 3.1.1, `dasprid/enum` 1.0.7, `picqer/php-barcode-generator` 3.3.0, resolved for PHP 8.2 |
| `scoper.inc.php` | PHP-Scoper config: prefix `ProductQrBarcode\Vendor`, each package's `src/` only |
| `patcher.php` | Inserts `defined( 'ABSPATH' ) \|\| exit;` after the namespace line of every file, and fails the build if a file doesn't have exactly one namespace declaration |
| `build.php` | Runs the steps below and verifies the result |

Not committed, and listed in the root `.gitignore`: `tools/`, `vendor/`, `scoped/`.

## Rebuild

1. Download the two pinned tools into `build/tools/`. `build.php` refuses to run if either SHA-256 differs.

   | File | Version | Source | SHA-256 |
   |---|---|---|---|
   | `composer.phar` | 2.10.3 | https://getcomposer.org/download/2.10.3/composer.phar | `7a2d379d5b8ffdaa028580ef26494c36d2feef4b178d3dd1473a4dbc5e17c8d6` (matches getcomposer.org's published checksum) |
   | `php-scoper.phar` | 0.18.19 | https://github.com/humbug/php-scoper/releases/download/0.18.19/php-scoper.phar | `170fb84bd3390defb30f99f7dc39c9a89d10c29973accc26f31c00abc5b25933` |

2. From the plugin directory, run `php build/build.php`. It performs these steps:
   1. `composer install` from `composer.lock` into `build/vendor/`, with no dev packages, plugins or scripts.
   2. `php-scoper add-prefix` into `build/scoped/`, applying the namespace prefix and the ABSPATH guard patcher.
   3. Replaces `vendor-prefixed/` with each package's scoped `src/` and its original LICENSE file.
   4. Writes `vendor-prefixed/NOTICE.md` (versions, licenses, modifications) and an `index.php` stub in every directory.
   5. Checks that every file is prefixed and has exactly one guard, then deletes `build/scoped/`.

3. Run `php tests/run.php phase4` (see `tests/README.md`).

The build is reproducible: two consecutive builds produced byte-identical `vendor-prefixed/` trees.

To upgrade a library, change its version in `composer.json`, then run `php build/tools/composer.phar update --no-dev --no-plugins --no-scripts` in `build/`. Review the license and PHP requirement again, rebuild, run the tests, and update the versions in the plugin README.

Strauss 0.30.0 was tried first and fails on Windows ("Unable to create a directory at ."), which is why PHP-Scoper is used.
