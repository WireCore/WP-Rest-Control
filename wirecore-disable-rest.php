<?php
/*
Plugin Name: WireCore Disable REST
Description: Disables the WordPress REST API selectively – globally, per namespace or per route, including routes of other plugins.
Author: WireCore GmbH
Author URI: https://wirecore.net
Version: 1.0.0
Requires at least: 5.8
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: wirecore-disable-rest
Domain Path: /languages
*/

// Prevent direct access
if (!defined('WPINC')) {
	die();
}

define(
	'WIRECORE_DR_VERSION',
	get_file_data(__FILE__, ['Version' => 'Version'])['Version']
);
define('WIRECORE_DR_FILE', __FILE__);

require_once __DIR__ . '/includes/class-wirecore-dr-settings.php';
require_once __DIR__ . '/includes/class-wirecore-dr-routes.php';
require_once __DIR__ . '/includes/class-wirecore-dr-log.php';
require_once __DIR__ . '/includes/class-wirecore-dr-guard.php';
require_once __DIR__ . '/includes/class-wirecore-dr-admin.php';

add_action('init', function () {
	// Needed for the bundled translations until language packs exist on
	// translate.wordpress.org.
	// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound
	load_plugin_textdomain(
		'wirecore-disable-rest',
		false,
		dirname(plugin_basename(__FILE__)) . '/languages'
	);
});

WireCore_DR_Guard::init();
WireCore_DR_Admin::rest_init();
if (is_admin()) {
	WireCore_DR_Admin::init();
}
if (defined('WP_CLI') && WP_CLI) {
	require_once __DIR__ . '/includes/class-wirecore-dr-cli.php';
	WP_CLI::add_command('wirecore-dr', 'WireCore_DR_Cli');
}
