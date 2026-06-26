# Project knowledge

This file gives Freebuff context about the **AgeVerif – Age Verification** WordPress plugin (slug/Folder: `ageverif-wordpress`).

## Quickstart
- Install: Upload the plugin folder into `wp-content/plugins/` of a WordPress 5.6+ site (PHP 7.4+), then activate from **Plugins** in wp-admin.
- Activation/setup: Settings → AgeVerif → paste your Public Live Key from the AgeVerif Webmasters Platform, pick protected content types, Save Changes.
- Dev: There is **no build step, package manager, or test runner** in this repo (no `composer.json`, `package.json`, `phpunit.xml`, `.phpcs`, etc.). Edit PHP/JS/CSS and use the site directly.
- Test: Manual — install/activate on a WP site, configure the Public Live Key, verify the gate on protected content types (posts/pages/products/custom post types). Use Test Mode to preview as admin.

## Architecture
- `ageverif-wordpress.php` — plugin bootstrap. Defines constants (`AGEVERIF_VERSION`, `AGEVERIF_PLUGIN_DIR`, `AGEVERIF_PLUGIN_URL`, `AGEVERIF_BASENAME`), registers the `AgeVerif\` PSR-style autoloader (maps `AgeVerif\Foo_Bar` → `includes/class-foo-bar.php`), and bootstraps `AgeVerif\AgeVerif_WordPress`.
- `includes/class-ageverif-wordpress.php` — core plugin class (lifecycle, settings registration, option sanitization).
- `includes/class-ageverif-frontend.php` — frontend gate rendering / protected-content filtering.
- `admin/class-ageverif-admin.php` — admin settings page UI and asset enqueue.
- `assets/admin.css` — admin-only styles.
- `languages/ageverif-wordpress.pot` — translation template (text domain: `ageverif-wordpress`).
- `uninstall.php` — runs on plugin uninstall; cleans options.
- `README.txt` — WordPress.org-style readme (header used by WP plugin repo).

## Class / file conventions
- All PHP classes live under namespace `AgeVerif\` and underscore the version name: class `AgeVerif_WordPress` lives at `includes/class-ageverif-wordpress.php`. **Mirror this naming when adding new classes.**
- All entry-point PHP files start with `defined( 'ABSPATH' ) || exit;` — keep this guard on any new top-level PHP file.
- Admin UI lives in `admin/`; core/shared logic lives in `includes/`. Do not mix.
- JS in `js/` is plain (no build pipeline). If you add a script, enqueue it via `wp_enqueue_script` in the appropriate admin/frontend class.
- Text domain for all translatable strings: `ageverif-wordpress`. After string changes, regenerate `languages/ageverif-wordpress.pot` (e.g. with WP-CLI `wp i18n make-pot . languages/ageverif-wordpress.pot`).
- Bump `AGEVERIF_VERSION` in `ageverif-wordpress.php` and the `Version:`/`Stable tag:` headers in `README.txt` together for every release.

## Things to avoid / gotchas
- **No automated tests, no linter, no CI.** Don't expect `npm test`, `composer test`, etc. Validation = manual WP install + smoke test.
- The autoloader lowercases the class name (`strtolower`) and converts `_` → `-`. Adding a class with uppercase letters or mismatched filenames will silently fail to load.
- Integration with the external service is via a single Public Live Key configured in Settings → AgeVerif; do not hard-code keys.
- The gate is SEO-safe by design — do not block search/AI crawlers when modifying the frontend filter.
- This is a distributed plugin (will be packaged as ZIP for WP.org). Hidden `.agents/` and `knowledge.md` at repo root are for the assistant only — make sure they are NOT included in the distributed ZIP (already gitignored or outside the plugin slug, but verify before tagging a release).
- License is GPL-2.0-or-later — keep all new PHP/JS/CSS compatible (no proprietary-only deps).
