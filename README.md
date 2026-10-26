# WireCore Disable REST

Disable the WordPress REST API selectively – per namespace, route and HTTP method, including the routes of other plugins, with IP, role and user exceptions.

Instead of an all-or-nothing switch, WireCore Disable REST lists **every registered REST route** (core, themes and all plugins), shows which plugin or theme registered it, and lets you decide per namespace and per route who may read and who may write.

The user-facing description lives in [`readme.txt`](readme.txt), which is also shown on wordpress.org.

## Features

- Default rule plus overrides per namespace and route, separately for reading (GET/HEAD) and writing (POST/PUT/PATCH/DELETE). Rule levels: Public, Logged-in only (optionally limited to roles), Admins only, Blocked, Inherit.
- **Advanced** exceptions per namespace or route: allow IP addresses/CIDR ranges, roles and user IDs; deny IP addresses/CIDR ranges and roles. Denials win over allowances and also apply to administrators.
- **Automatic allowances** for front-end plugins (Contact Form 7, WooCommerce Store API, oEmbed, LiteSpeed/QUIC.cloud, WP Statistics), each one switchable.
- Global allow lists (user IDs, IPs), global IP deny list, "never block administrators".
- Custom error message and status code (401/403/404), HTTPS-only, JSONP off, REST links removed from headers/head/RSD, blocked routes hidden in `/wp-json/`.
- Covers `_embed` and `/batch/v1` sub-requests; requests with invalid parameters are blocked before WordPress reveals the expected parameters.
- Optional log of blocked requests (IP stored in full, shortened or not at all), self-test, JSON export/import, WP-CLI.
- Multisite: settings per site, network activation supported.
- Translations: English source strings, German (`de_DE`, `de_DE_formal`) bundled.

Requires WordPress 5.8+ and PHP 7.4+.

## Installation

From the WordPress plugin directory: _Plugins → Add New → "WireCore Disable REST"_.

With Composer via [WPackagist](https://wpackagist.org/):

```bash
composer require wpackagist-plugin/wirecore-disable-rest
```

After activation, logged-out visitors can no longer use the REST API, except for the automatically allowed namespaces. The settings are under _Settings → REST API_.

### Emergency switch

If a rule locks you out, add this to `wp-config.php` – no rule is enforced while it is set:

```php
define('WIRECORE_DR_OFF', true);
```

## WP-CLI

```bash
wp wirecore-dr status
wp wirecore-dr routes [--namespace=<namespace>] [--source=<text>] [--format=table|json|csv]
wp wirecore-dr set <route|namespace> [--read=<rule>] [--write=<rule>]
wp wirecore-dr default [--read=<rule>] [--write=<rule>]
wp wirecore-dr export [<file>]
wp wirecore-dr import <file>
wp wirecore-dr log [--clear] [--format=table|json|csv]
```

Rules: `inherit`, `public`, `auth`, `admin`, `blocked` (`inherit` is not allowed for the default).

## Hooks

| Filter                         | Parameters                                       | Purpose                                                                     |
| ------------------------------ | ------------------------------------------------ | --------------------------------------------------------------------------- |
| `wirecore_dr_error`            | `WP_Error $error, string $route, string $method` | Change the error of a blocked request. Returning `true` allows the request. |
| `wirecore_dr_client_ip`        | `string $ip`                                     | Determine the client IP, e.g. behind a reverse proxy.                       |
| `wirecore_dr_known_namespaces` | `array $known`                                   | Plugins offered under "Automatic allowances" (`key => [namespaces]`).       |

## Development

Requirements: Docker, Node.js (for Prettier and the WireLayer CSS).

```bash
docker compose up -d   # WordPress on http://localhost:8092, phpMyAdmin on :8093
npm install            # Prettier with the PHP plugin, @wirecore/wirelayer-css
```

The settings page uses the [WireLayer](https://github.com/WireCore/WireLayer) components (`.wc-*` classes). `npm run build:css` writes the components listed in `package.json`, scoped to `#wirecore-dr`, with their fonts to `assets/wirelayer/`. The output is committed, so the plugin needs no build step; run the command again after adding a component or updating `@wirecore/wirelayer-css`.

WP-CLI is available in the container:

```bash
docker compose exec wordpress wp --allow-root wirecore-dr routes
```

The self-test under _Tools_ does not work locally, because the container cannot reach `localhost:8092`.

### Code style and checks

- Formatting: Prettier with tabs and single quotes – `npm run format` / `npm run format:check`.
- Plugin Check (the tool used by the wordpress.org review):

    ```bash
    docker compose exec wordpress wp --allow-root plugin install plugin-check --activate
    docker compose exec wordpress wp --allow-root plugin check wirecore-disable-rest \
        --exclude-directories=node_modules,db-data,.vscode,.github,vendor,tests \
        --exclude-files=.gitignore,.gitattributes,.distignore,.prettierrc,.prettierignore,CLAUDE.md,README.md,CHANGELOG.md,SECURITY.md
    ```

### Tests

PHPUnit integration tests run against the WordPress test suite (`wp-phpunit`, WordPress core from `roots/wordpress-no-content`) and a separate database `wordpress_test` – never point them at a real database, the suite empties it.

```bash
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app composer:2 composer install
docker compose exec db mariadb -uroot -ppassword -e "CREATE DATABASE IF NOT EXISTS wordpress_test"
docker compose exec -w /var/www/html/wp-content/plugins/wirecore-disable-rest wordpress vendor/bin/phpunit
```

The database settings in [`tests/wp-tests-config.php`](tests/wp-tests-config.php) default to the Docker environment and can be overridden with `WP_TESTS_DB_HOST`, `WP_TESTS_DB_USER`, `WP_TESTS_DB_PASSWORD` and `WP_TESTS_DB_NAME`.

### Translations

All UI strings are English in the code (text domain `wirecore-disable-rest`) and translated in `languages/`. After changing strings (`P=wp-content/plugins/wirecore-disable-rest`):

```bash
docker compose exec wordpress wp --allow-root i18n make-pot $P $P/languages/wirecore-disable-rest.pot --domain=wirecore-disable-rest --exclude=node_modules,db-data,languages
docker compose exec wordpress wp --allow-root i18n update-po $P/languages/wirecore-disable-rest.pot $P/languages
# translate new or changed entries in both .po files, then:
docker compose exec wordpress wp --allow-root i18n make-mo $P/languages
docker compose exec wordpress wp --allow-root i18n make-php $P/languages
```

### Package contents

Only the files needed by the plugin are shipped. Development files are excluded via [`.distignore`](.distignore) and mirrored in [`.gitattributes`](.gitattributes) – add new development files to both.

### Continuous integration

[`.github/workflows/ci.yml`](.github/workflows/ci.yml) runs on every push to `main` and on pull requests:

- PHP syntax check on PHP 7.4 – 8.4
- PHP compatibility for the minimum PHP version (`composer phpcs`, see [`phpcs.xml.dist`](phpcs.xml.dist))
- PHPUnit on PHP 7.4 and 8.3 (`composer test`)
- Prettier (`npm run format:check`)
- Plugin Check on the built package, warnings included

## Releasing

1. Bump `Version:` in `wirecore-disable-rest.php` and `Stable tag:` in `readme.txt`, and add the changelog entry to `readme.txt` and `CHANGELOG.md`.
2. Commit and push to `main`, wait for CI to pass.
3. Create and push a tag with the version number, without a `v` prefix:

    ```bash
    git tag 1.0.1 && git push origin 1.0.1
    ```

[`.github/workflows/release.yml`](.github/workflows/release.yml) then checks that tag, plugin header and stable tag match, deploys the package (files selected by `.distignore`, assets from `.wordpress-org/`) to the wordpress.org SVN repository and creates a GitHub release with the ZIP.

One-time setup: add the secrets `SVN_USERNAME` and `SVN_PASSWORD` (the SVN password from the wordpress.org profile under _Account & Security_) to the GitHub environment `wordpress.org`. The very first version is submitted manually at [wordpress.org/plugins/developers/add](https://wordpress.org/plugins/developers/add/); the workflow takes over once the plugin has been approved.

## License

[GPL-2.0-or-later](LICENSE) © WireCore GmbH
