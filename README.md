# WireCore REST Control

Disable the WordPress REST API selectively – per namespace, route and HTTP method, including the routes of other plugins, with IP, role and user exceptions.

Instead of an all-or-nothing switch, WireCore REST Control lists **every registered REST route** (core, themes and all plugins), shows which plugin or theme registered it, and lets you decide per namespace and per route who may read and who may write.

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

From the WordPress plugin directory: _Plugins → Add New → "WireCore REST Control"_.

With Composer via [WPackagist](https://wpackagist.org/):

```bash
composer require wpackagist-plugin/wirecore-rest-control
```

After activation, logged-out visitors can no longer use the REST API, except for the automatically allowed namespaces. The settings are under _Settings → REST API_.

### Emergency switch

If a rule locks you out, add this to `wp-config.php` – no rule is enforced while it is set:

```php
define('WIRECORE_RC_OFF', true);
```

## WP-CLI

```bash
wp wirecore-rest status
wp wirecore-rest routes [--namespace=<namespace>] [--source=<text>] [--format=table|json|csv]
wp wirecore-rest set <route|namespace> [--read=<rule>] [--write=<rule>]
wp wirecore-rest default [--read=<rule>] [--write=<rule>]
wp wirecore-rest export [<file>]
wp wirecore-rest import <file>
wp wirecore-rest log [--clear] [--format=table|json|csv]
```

Rules: `inherit`, `public`, `auth`, `admin`, `blocked` (`inherit` is not allowed for the default).

## Hooks

| Filter                         | Parameters                                       | Purpose                                                                     |
| ------------------------------ | ------------------------------------------------ | --------------------------------------------------------------------------- |
| `wirecore_rc_error`            | `WP_Error $error, string $route, string $method` | Change the error of a blocked request. Returning `true` allows the request. |
| `wirecore_rc_client_ip`        | `string $ip`                                     | Determine the client IP, e.g. behind a reverse proxy.                       |
| `wirecore_rc_known_namespaces` | `array $known`                                   | Plugins offered under "Automatic allowances" (`key => [namespaces]`).       |

## Development

Requirements: Docker, Node.js (for Prettier and the WireLayer CSS).

```bash
docker compose up -d   # WordPress on http://localhost:8092, phpMyAdmin on :8093
npm install            # Prettier with the PHP plugin, @wirecore/wirelayer-css
```

The container runs the newest supported PHP version (8.5, see `Dockerfile`), so deprecations show up during development.

The settings page uses the [WireLayer](https://github.com/WireCore/WireLayer) components (`.wc-*` classes). `npm run build:css` writes the components listed in `package.json`, scoped to `#wirecore-rc`, with their fonts to `assets/wirelayer/`. The output is committed, so the plugin needs no build step; run the command again after adding a component or updating `@wirecore/wirelayer-css`.

WP-CLI is available in the container:

```bash
docker compose exec wordpress wp --allow-root wirecore-rest routes
```

The self-test under _Tools_ does not work locally, because the container cannot reach `localhost:8092`.

### Code style and checks

- Formatting: Prettier with tabs and single quotes – `npm run format` / `npm run format:check`.
- Plugin Check (the tool used by the wordpress.org review):

    ```bash
    docker compose exec wordpress wp --allow-root plugin install plugin-check --activate
    docker compose exec wordpress wp --allow-root plugin check wirecore-rest-control \
        --exclude-directories=node_modules,db-data,.vscode,.github,vendor,tests \
        --exclude-files=.gitignore,.gitattributes,.distignore,.prettierrc,.prettierignore,CLAUDE.md,README.md,CHANGELOG.md,SECURITY.md
    ```

### Tests

PHPUnit integration tests run against the WordPress test suite (`wp-phpunit`, WordPress core from `roots/wordpress-no-content`) and a separate database `wordpress_test` – never point them at a real database, the suite empties it.

```bash
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app composer:2 composer install
docker compose exec db mariadb -uroot -ppassword -e "CREATE DATABASE IF NOT EXISTS wordpress_test"
docker compose exec -w /var/www/html/wp-content/plugins/wirecore-rest-control wordpress vendor/bin/phpunit
```

The database settings in [`tests/wp-tests-config.php`](tests/wp-tests-config.php) default to the Docker environment and can be overridden with `WP_TESTS_DB_HOST`, `WP_TESTS_DB_USER`, `WP_TESTS_DB_PASSWORD` and `WP_TESTS_DB_NAME`.

The plugin supports PHP 7.4 – 8.5. To run the syntax check and the tests on every version like the CI does, use the official PHP images in the network of the Docker environment:

```bash
for v in 7.4 8.0 8.1 8.2 8.3 8.4 8.5; do
    docker run --rm --network "$(basename "$PWD" | tr '[:upper:]' '[:lower:]')_default" -v "$PWD":/app -w /app -e WP_TESTS_DB_PASSWORD=password php:$v-cli \
        sh -c "docker-php-ext-install mysqli >/dev/null && find . -name '*.php' -not -path './vendor/*' -not -path './node_modules/*' -exec php -l {} \; | grep -v 'No syntax errors'; vendor/bin/phpunit"
done
```

Composer installs the dependencies for PHP 7.4 (`config.platform` in `composer.json`), so the same `vendor/` works on every version.

### Translations

All UI strings are English in the code (text domain `wirecore-rest-control`) and translated in `languages/`. After changing strings (`P=wp-content/plugins/wirecore-rest-control`):

```bash
docker compose exec wordpress wp --allow-root i18n make-pot $P $P/languages/wirecore-rest-control.pot --domain=wirecore-rest-control --exclude=node_modules,db-data,languages
docker compose exec wordpress wp --allow-root i18n update-po $P/languages/wirecore-rest-control.pot $P/languages
# translate new or changed entries in both .po files, then:
docker compose exec wordpress wp --allow-root i18n make-mo $P/languages
docker compose exec wordpress wp --allow-root i18n make-php $P/languages
```

### Package contents

Only the files needed by the plugin are shipped. Development files are excluded via [`.distignore`](.distignore) and mirrored in [`.gitattributes`](.gitattributes) – add new development files to both.

### Continuous integration

[`.github/workflows/ci.yml`](.github/workflows/ci.yml) runs on every push to `main` and on pull requests:

- PHP syntax check on PHP 7.4 – 8.5
- PHP compatibility from PHP 7.4 up to 8.5 with PHPCompatibility 10 (`composer phpcs`, see [`phpcs.xml.dist`](phpcs.xml.dist))
- PHPUnit on PHP 7.4 – 8.5 (`composer test`); deprecations fail the tests
- Prettier (`npm run format:check`)
- Plugin Check on the built package, warnings included

## Releasing

1. Bump `Version:` in `wirecore-rest-control.php` and `Stable tag:` in `readme.txt`, and add the changelog entry to `readme.txt` and `CHANGELOG.md`.
2. Commit and push to `main`, wait for CI to pass.
3. Create and push a tag with the version number, without a `v` prefix:

    ```bash
    git tag 1.0.1 && git push origin 1.0.1
    ```

[`.github/workflows/release.yml`](.github/workflows/release.yml) then checks that tag, plugin header and stable tag match, deploys the package (files selected by `.distignore`, assets from `.wordpress-org/`) to the wordpress.org SVN repository and creates a GitHub release with the ZIP.

One-time setup: add the secrets `SVN_USERNAME` and `SVN_PASSWORD` (the SVN password from the wordpress.org profile under _Account & Security_) to the GitHub environment `wordpress.org`. The very first version is submitted manually at [wordpress.org/plugins/developers/add](https://wordpress.org/plugins/developers/add/); the workflow takes over once the plugin has been approved.

## License

[GPL-2.0-or-later](LICENSE) © WireCore GmbH
