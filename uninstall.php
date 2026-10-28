<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit();
}

function wirecore_rc_uninstall_site()
{
	delete_option('wirecore_rc_settings');
	delete_option('wirecore_rc_log');
}

function wirecore_rc_uninstall()
{
	// Settings are stored per site on multisite
	if (!is_multisite()) {
		wirecore_rc_uninstall_site();
		return;
	}
	foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $site_id) {
		switch_to_blog($site_id);
		wirecore_rc_uninstall_site();
		restore_current_blog();
	}
}

wirecore_rc_uninstall();
