# WireCore Disable REST

WordPress plugin, published on wordpress.org (slug `wirecore-disable-rest`) and installed via WPackagist. All repository content – code comments, UI source strings, docs – is in English; German UI texts exist only in `languages/`.

## Conventions

- Static classes `WireCore_DR_*` in `includes/class-wirecore-dr-*.php`, no autoloader, no namespaces. Each class has an `init()` that registers its hooks.
- Prefix everything global: functions/options/hooks/nonces `wirecore_dr_`, constants `WIRECORE_DR_`, CSS classes/IDs `wirecore-dr-`, text domain `wirecore-disable-rest`, WP-CLI command `wp wirecore-dr`.
- Admin pages in plain PHP with `admin-post.php` forms, nonces, `wp_safe_redirect()` plus a notice query arg. No build step for JS/CSS.
- Minimum versions: WordPress 5.8, PHP 7.4 (verified with PHPCompatibility and a WP 5.8/PHP 7.4 instance) – do not use newer functions without raising them.
- Formatting with Prettier (tabs, single quotes). Plugin Check must stay free of errors and warnings; justified exceptions are marked with `phpcs:ignore`/`phpcs:disable` plus a comment explaining why.

## Architecture

- `WireCore_DR_Settings` – one option `wirecore_dr_settings`; everything that is stored (form, import, CLI) goes through `sanitize()`.
- `WireCore_DR_Routes` – reads `rest_get_server()->get_routes()` and detects via reflection which plugin or theme registered a route.
- `WireCore_DR_Guard` – check order in `decide()`: HTTPS → deny lists (global and "Advanced", also for admins) → admin bypass/global allow lists → allowances from "Advanced" → rule (route → namespace → automatic allowance → default). Enforced via `rest_request_before_callbacks`, which also covers `_embed` and `/batch/v1`, and also when WordPress already produced an error (otherwise blocked visitors would see parameter errors). `rule()` and `decide()` are the only place where rules are evaluated; `assets/admin.js` mirrors the inheritance for the display – keep both in sync.
- `WireCore_DR_Admin` – page under Settings → REST API.
    - The route list is loaded via JS through the REST index (`/wp-json/?wirecore_dr_routes=1&_fields=wirecore_dr_routes`), because plugins like WooCommerce only register some namespaces during REST requests for exactly that namespace or the index – they would be missing in wp-admin. The guard never blocks this request for admins.
    - The routes tab sends all rules as one JSON field (`rules_json`), because hundreds of selects would exceed `max_input_vars`.
- `WireCore_DR_Log`, `WireCore_DR_Cli` – log of blocked requests and WP-CLI commands.

## Translations

All UI strings are English in the code and translated in `languages/`: `de_DE` (informal) and `de_DE_formal` (formal). WP-CLI output stays English and untranslated. `load_plugin_textdomain()` is kept for the bundled translations until language packs exist on translate.wordpress.org.

After changing strings (inside the Docker container, `P=wp-content/plugins/wirecore-disable-rest`):

```bash
docker compose exec wordpress wp --allow-root i18n make-pot $P $P/languages/wirecore-disable-rest.pot --domain=wirecore-disable-rest --exclude=node_modules,db-data,languages
docker compose exec wordpress wp --allow-root i18n update-po $P/languages/wirecore-disable-rest.pot $P/languages
# translate new or changed entries in both .po files, then:
docker compose exec wordpress wp --allow-root i18n make-mo $P/languages
docker compose exec wordpress wp --allow-root i18n make-php $P/languages
```

`.po`, `.mo` and `.l10n.php` are part of the package. The files are created as root inside the container – `chown` them to your user if needed.

## Package contents

Only `wirecore-disable-rest.php`, `uninstall.php`, `index.php`, `readme.txt`, `LICENSE`, `includes/`, `assets/` and `languages/` are shipped. New development or configuration files must be added to both `.distignore` and `.gitattributes` (`export-ignore`).

## Checks

CI (`.github/workflows/ci.yml`) must stay green: PHP lint 7.4–8.4, `composer phpcs` (PHPCompatibilityWP, `phpcs.xml.dist`), `npm run format:check`, Plugin Check in strict mode on the package built from `.distignore`.

## Release

Bump `Version:` in `wirecore-disable-rest.php` and `Stable tag:` in `readme.txt` together, and add the entry to the changelog in `readme.txt` and `CHANGELOG.md`. Pushing a tag like `1.0.1` (no `v`) runs `.github/workflows/release.yml`, which verifies the version numbers and deploys to the wordpress.org SVN repository.
