# Changelog

All notable changes to this project are documented in this file. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses [Semantic Versioning](https://semver.org/).

## [1.0.0] - Unreleased

### Added

- Rules per namespace and route, separately for reading and writing, on top of a default rule.
- Route list with every registered route, including the routes of other plugins and themes, with source detection, search and filters.
- "Advanced" exceptions per namespace or route: allow IP addresses/CIDR ranges, roles and user IDs; deny IP addresses/CIDR ranges and roles.
- Automatic allowances for front-end plugins (Contact Form 7, WooCommerce Store API, oEmbed, LiteSpeed Cache, WP Statistics).
- Global allow lists, global IP deny list and "never block administrators".
- Custom error message and status code, HTTPS-only, JSONP off, removal of REST links, hiding blocked routes in the REST index.
- Optional log of blocked requests with configurable IP storage.
- Self-test, JSON export/import and WP-CLI commands (`wp wirecore-dr`).
- Multisite support with settings per site.
- German translations (`de_DE`, `de_DE_formal`).
