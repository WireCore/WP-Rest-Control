=== WireCore Disable REST ===
Contributors: wirecore
Tags: rest api, security, disable rest api, json api, privacy
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Disable the WordPress REST API selectively – per namespace, route and HTTP method, including routes of other plugins, with IP and role exceptions.

== Description ==

WireCore Disable REST restricts access to the WordPress REST API without breaking the block editor or the plugins your visitors rely on. Instead of an all-or-nothing switch, you decide per namespace and per route who may read and who may write.

The route list shows **every registered route** – WordPress core, themes and all other plugins – and tells you which plugin or theme registered it.

= Rules =

* A default rule plus overrides per namespace and per route. The most specific rule wins: route, then namespace, then automatic allowance, then default.
* Separate rules for reading (GET/HEAD) and writing (POST/PUT/PATCH/DELETE).
* Rule levels: Public, Logged-in only (optionally limited to selected roles), Admins only, Blocked, Inherit.
* Out of the box, logged-out visitors are blocked – except for the namespaces of known front-end plugins (see below).
* Quick actions: block everything for guests, block everything, restrict the user list (`/wp/v2/users`) to admins, reset custom rules.
* Search and filter the route list by text, source plugin/theme and status.

= Advanced exceptions per namespace or route =

* Allow IP addresses / CIDR ranges (IPv4 and IPv6), roles and user IDs.
* Deny IP addresses / CIDR ranges and roles.
* Denials take precedence over allowances and also apply to administrators.
* All exceptions are visible in the route list at a glance.

= Automatic allowances =

Some plugins need the REST API for visitors on the front end. Their namespaces are public as long as you do not set a custom rule. Each of them can be switched off in the settings:

* Contact Form 7
* WooCommerce Store API (cart, checkout)
* oEmbed (embedding posts on other sites)
* LiteSpeed Cache (QUIC.cloud)
* WP Statistics

= Global settings and hardening =

* Never block administrators (recommended – the block editor needs the REST API).
* Global allow lists for user IDs and IP addresses, global IP deny list.
* Custom error message and status code (401, 403 or 404 – the latter makes blocked routes look non-existent).
* Allow the REST API over HTTPS only.
* Disable JSONP.
* Remove REST links from HTTP headers, the HTML head and RSD.
* Hide blocked routes and namespaces in the REST index (`/wp-json/`).

= Log and tools =

* Optional log of the last 200 blocked requests. The IP address can be stored in full, shortened or not at all.
* Self-test: shows what logged-out visitors get from the REST API.
* Export and import of all settings as JSON.
* WP-CLI: `wp wirecore-dr status|routes|set|default|export|import|log`.

= Coverage =

Rules apply to every real REST request, including embedded responses (`_embed`) and sub-requests of the batch endpoint (`/batch/v1`). Internal `rest_do_request()` calls made by PHP – for example when the block editor preloads data – are not affected.

= Multisite =

Settings are stored per site, because every site has its own routes and plugins. The plugin can be network-activated; uninstalling removes the settings of all sites.

= For developers =

* `wirecore_dr_error` – filters the `WP_Error` of a blocked request (parameters: error, route pattern, method). Returning `true` allows the request after all.
* `wirecore_dr_client_ip` – filters the client IP, e.g. behind a reverse proxy.
* `wirecore_dr_known_namespaces` – filters the plugins offered under "Automatic allowances" (array `key => [namespaces]`).

= Privacy =

The plugin does not send any data to external services and does not set cookies. If you enable the log, it stores the method, route, IP address (in full, shortened or not at all – configurable), user ID and the reason of blocked requests in the database of your site. The self-test sends requests from your server to its own REST API.

== Installation ==

1. Install the plugin via *Plugins → Add New* or upload the plugin folder to `/wp-content/plugins/`.
2. Activate the plugin. From now on, logged-out visitors can no longer use the REST API, except for the automatically allowed namespaces.
3. Go to *Settings → REST API* to review the route list and adjust the rules.

With Composer, the plugin can be installed from WPackagist: `composer require wpackagist-plugin/wirecore-disable-rest`.

== Frequently Asked Questions ==

= A rule locked me out of the admin area. What can I do? =

Add the following line to your `wp-config.php`. It disables all rules until you remove it again:

`define( 'WIRECORE_DR_OFF', true );`

= Will administrators be blocked? =

Not by default: the option "Never block administrators" is enabled after activation, because the block editor needs the REST API. Deny lists (IP addresses, roles) always apply, also to administrators.

= My contact form or shop checkout stopped working. =

Check *Settings → REST API → Settings → Automatic allowances*. If the plugin is not listed there, search for its namespace in the route list and set it to "Public".

= Does it also restrict routes of other plugins? =

Yes. The route list contains every registered route and shows which plugin or theme registered it. Some plugins only register routes during REST requests; the list is therefore loaded through the REST API itself so that these routes are included as well.

= How can I check what visitors see? =

Use *Tools → Self-test* on the settings page, or open `/wp-json/` in a private browser window.

= Is the plugin translated? =

The plugin ships with German translations (formal and informal). Further translations are welcome on translate.wordpress.org.

== Screenshots ==

1. Route list with rules per namespace and route, source detection and the effective rule.
2. The "Advanced" dialog: allow and deny IP addresses, roles and users per namespace or route.
3. Settings: access, automatic allowances, error response, hardening and log.
4. Log of blocked requests.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
