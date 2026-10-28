<?php

if (!defined('ABSPATH')) {
	exit();
}

/**
 * The most recently blocked requests. Disabled by default, because every
 * blocked request causes a database write.
 */
class WireCore_RC_Log
{
	const OPTION = 'wirecore_rc_log';
	const LIMIT = 200;

	public static function get()
	{
		$log = get_option(self::OPTION);
		return array_merge(
			['count' => 0, 'entries' => []],
			is_array($log) ? $log : []
		);
	}

	public static function add(array $entry)
	{
		if (!WireCore_RC_Settings::get()['log_enabled']) {
			return;
		}
		$mode = WireCore_RC_Settings::get()['log_ip'];
		if ($mode === 'none') {
			$entry['ip'] = '';
		} elseif ($mode === 'anonymized' && $entry['ip'] !== '') {
			$entry['ip'] = wp_privacy_anonymize_ip($entry['ip']);
		}

		$log = self::get();
		$log['count']++;
		array_unshift($log['entries'], ['time' => time()] + $entry);
		$log['entries'] = array_slice($log['entries'], 0, self::LIMIT);
		update_option(self::OPTION, $log, false);
	}

	public static function clear()
	{
		delete_option(self::OPTION);
	}
}
