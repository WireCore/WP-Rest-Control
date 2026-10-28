# WireCore REST Control

WordPress plugin, published on wordpress.org (slug `wirecore-rest-control`) and installed via WPackagist. All repository content – code comments, UI source strings, docs – is in English; German UI texts exist only in `languages/`.

## Conventions

- Static classes `WireCore_RC_*` in `includes/class-wirecore-rc-*.php`, no autoloader, no namespaces. Each class has an `init()` that registers its hooks.
- Prefix everything global: functions/options/hooks/nonces `wirecore_rc_`, constants `WIRECORE_RC_`, CSS classes/IDs `wirecore-rc-`, text domain `wirecore-rest-control`, WP-CLI command `wp wirecore-rest`.
- Admin pages in plain PHP with `admin-post.php` forms, nonces, `wp_safe_redirect()` plus a notice query arg. No build step for JS; the only CSS build is `npm run build:css` (below).
- UI in the WireLayer corporate design: the markup uses the `.wc-*` classes of `@wirecore/wirelayer-css` (class reference: `node_modules/@wirecore/wirelayer-css/llms-full.txt`). `npm run build:css` writes the used components, scoped to `#wirecore-rc`, to `assets/wirelayer/` – committed, CI checks it is up to date. Add a component to the list in `package.json` before using its classes, and run the command again after updating the package. `wirecore-rc-*` classes are hooks for `admin.js` and page layout in `assets/admin.css` (scoped to `#wirecore-rc`, colors and spacing from WireLayer variables). Icons are inline Lucide SVGs (`WireCore_RC_Admin::icon()`).
- Supported versions: WordPress 5.8+, PHP 7.4 – 8.5 (verified with PHPCompatibility 10, a WP 5.8/PHP 7.4 instance and the tests on every PHP version) – do not use newer functions without raising the minimum, and avoid anything deprecated up to PHP 8.5 (e.g. `ReflectionProperty::setAccessible()` only below PHP 8.1). The Docker environment runs PHP 8.5.
- Formatting with Prettier (tabs, single quotes). Plugin Check must stay free of errors and warnings; justified exceptions are marked with `phpcs:ignore`/`phpcs:disable` plus a comment explaining why.

## Architecture

- `WireCore_RC_Settings` – one option `wirecore_rc_settings`; everything that is stored (form, import, CLI) goes through `sanitize()`.
- `WireCore_RC_Routes` – reads `rest_get_server()->get_routes()` and detects via reflection which plugin or theme registered a route.
- `WireCore_RC_Guard` – check order in `decide()`: HTTPS → deny lists (global and "Advanced", also for admins) → admin bypass/global allow lists → allowances from "Advanced" → rule (route → namespace → automatic allowance → default). Enforced via `rest_request_before_callbacks`, which also covers `_embed` and `/batch/v1`, and also when WordPress already produced an error (otherwise blocked visitors would see parameter errors). `rule()` and `decide()` are the only place where rules are evaluated; `assets/admin.js` mirrors the inheritance for the display – keep both in sync.
- `WireCore_RC_Admin` – page under Settings → REST API.
    - The route list is loaded via JS through the REST index (`/wp-json/?wirecore_rc_routes=1&_fields=wirecore_rc_routes`), because plugins like WooCommerce only register some namespaces during REST requests for exactly that namespace or the index – they would be missing in wp-admin. The guard never blocks this request for admins.
    - The routes tab sends all rules as one JSON field (`rules_json`), because hundreds of selects would exceed `max_input_vars`.
- `WireCore_RC_Log`, `WireCore_RC_Cli` – log of blocked requests and WP-CLI commands.

## Translations

All UI strings are English in the code and translated in `languages/`: `de_DE` (informal) and `de_DE_formal` (formal). WP-CLI output stays English and untranslated. `load_plugin_textdomain()` is kept for the bundled translations until language packs exist on translate.wordpress.org.

After changing strings (inside the Docker container, `P=wp-content/plugins/wirecore-rest-control`):

```bash
docker compose exec wordpress wp --allow-root i18n make-pot $P $P/languages/wirecore-rest-control.pot --domain=wirecore-rest-control --exclude=node_modules,db-data,languages
docker compose exec wordpress wp --allow-root i18n update-po $P/languages/wirecore-rest-control.pot $P/languages
# translate new or changed entries in both .po files, then:
docker compose exec wordpress wp --allow-root i18n make-mo $P/languages
docker compose exec wordpress wp --allow-root i18n make-php $P/languages
```

`.po`, `.mo` and `.l10n.php` are part of the package. The files are created as root inside the container – `chown` them to your user if needed.

## Package contents

Only `wirecore-rest-control.php`, `uninstall.php`, `index.php`, `readme.txt`, `LICENSE`, `includes/`, `assets/` and `languages/` are shipped. New development or configuration files must be added to both `.distignore` and `.gitattributes` (`export-ignore`).

## Checks

CI (`.github/workflows/ci.yml`) must stay green: PHP lint 7.4–8.5, `composer phpcs` (PHPCompatibilityWP, `phpcs.xml.dist`), PHPUnit on PHP 7.4–8.5 (see README for the loop over all versions in Docker), `npm run format:check`, `assets/wirelayer/` up to date with `npm run build:css`, Plugin Check in strict mode on the package built from `.distignore`.

Tests live in `tests/` (`test-*.php`, base class `WireCore_RC_TestCase`). They are integration tests against the WordPress test suite: dispatch requests through `rest_get_server()->dispatch()` (the bootstrap defines `REST_REQUEST`), and register fixture routes in `tests/bootstrap.php` – the route list is cached, so routes must exist before the first REST server is created. Run them inside the Docker container (see README) against the separate database `wordpress_test`.

## Release

Bump `Version:` in `wirecore-rest-control.php` and `Stable tag:` in `readme.txt` together, and add the entry to the changelog in `readme.txt` and `CHANGELOG.md`. Pushing a tag like `1.0.1` (no `v`) runs `.github/workflows/release.yml`, which verifies the version numbers and deploys to the wordpress.org SVN repository.
