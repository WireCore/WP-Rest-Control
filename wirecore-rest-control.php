<?php
/*
Plugin Name: WireCore REST Control
Description: Disables the WordPress REST API selectively – globally, per namespace or per route, including routes of other plugins.
Author: WireCore GmbH
Author URI: https://wirecore.net
Version: 1.0.0
Requires at least: 5.8
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: wirecore-rest-control
Domain Path: /languages
*/

// Prevent direct access
if (!defined('WPINC')) {
	die();
}

define(
	'WIRECORE_RC_VERSION',
	get_file_data(__FILE__, ['Version' => 'Version'])['Version']
);
define('WIRECORE_RC_FILE', __FILE__);

require_once __DIR__ . '/includes/class-wirecore-rc-settings.php';
require_once __DIR__ . '/includes/class-wirecore-rc-routes.php';
require_once __DIR__ . '/includes/class-wirecore-rc-log.php';
require_once __DIR__ . '/includes/class-wirecore-rc-guard.php';
require_once __DIR__ . '/includes/class-wirecore-rc-admin.php';

add_action('init', function () {
	// Needed for the bundled translations until language packs exist on
	// translate.wordpress.org.
	// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound
	load_plugin_textdomain(
		'wirecore-rest-control',
		false,
		dirname(plugin_basename(__FILE__)) . '/languages'
	);
});

WireCore_RC_Guard::init();
WireCore_RC_Admin::rest_init();
if (is_admin()) {
	WireCore_RC_Admin::init();
}
if (defined('WP_CLI') && WP_CLI) {
	require_once __DIR__ . '/includes/class-wirecore-rc-cli.php';
	WP_CLI::add_command('wirecore-rest', 'WireCore_RC_Cli');
}
