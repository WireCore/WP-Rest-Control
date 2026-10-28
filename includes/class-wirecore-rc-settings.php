<?php

if (!defined('ABSPATH')) {
	exit();
}

/**
 * Settings in one option. Everything that is stored (form, import, WP-CLI)
 * goes through sanitize().
 */
class WireCore_RC_Settings
{
	const OPTION = 'wirecore_rc_settings';
	const RULES = ['inherit', 'public', 'auth', 'admin', 'blocked'];
	const STATUSES = [401, 403, 404];
	const LOG_IP_MODES = ['full', 'anonymized', 'none'];
	// Exceptions per route/namespace ("Advanced" dialog)
	const ACCESS_LISTS = [
		'ip_allow',
		'ip_deny',
		'roles_allow',
		'roles_deny',
		'users_allow',
	];
	const FLAGS = [
		'admin_bypass',
		'require_ssl',
		'disable_jsonp',
		'remove_links',
		'hide_index',
		'log_enabled',
	];

	private static $cache = null;

	public static function defaults()
	{
		return [
			// No access without login by default
			'default' => ['read' => 'auth', 'write' => 'auth'],
			'namespaces' => [],
			'routes' => [],
			// empty = all logged-in users
			'allowed_roles' => [],
			// Never block administrators, otherwise e.g. the block editor breaks
			'admin_bypass' => true,
			'user_whitelist' => [],
			'ip_whitelist' => [],
			// also applies to administrators
			'ip_blacklist' => [],
			// route pattern or namespace => lists from ACCESS_LISTS
			'access' => [],
			// keys from known_namespaces() whose namespaces are public as long
			// as there is no custom rule
			'auto_allow' => array_keys(self::known_namespaces()),
			'error_message' => '',
			'error_status' => 401,
			'require_ssl' => false,
			'disable_jsonp' => true,
			'remove_links' => true,
			'hide_index' => true,
			'log_enabled' => false,
			// IP in the log: full, shortened (wp_privacy_anonymize_ip) or none
			'log_ip' => 'anonymized',
		];
	}

	public static function get()
	{
		if (self::$cache === null) {
			$stored = get_option(self::OPTION);
			self::$cache = array_merge(
				self::defaults(),
				is_array($stored) ? $stored : []
			);
		}
		return self::$cache;
	}

	public static function save(array $settings)
	{
		self::$cache = null;
		update_option(self::OPTION, self::sanitize($settings));
	}

	/**
	 * Namespaces of known plugins that visitors need on the front end.
	 * key => namespaces, labels in WireCore_RC_Admin.
	 */
	public static function known_namespaces()
	{
		return apply_filters('wirecore_rc_known_namespaces', [
			'contact-form-7' => ['contact-form-7/v1'],
			'woocommerce-store' => ['wc/store', 'wc/store/v1'],
			'oembed' => ['oembed/1.0'],
			'litespeed' => ['litespeed/v1', 'litespeed/v3'],
			'wp-statistics' => ['wp-statistics/v2'],
		]);
	}

	/**
	 * Namespaces that are automatically public according to the settings.
	 */
	public static function auto_allowed_namespaces()
	{
		$known = self::known_namespaces();
		$namespaces = [];
		foreach (self::get()['auto_allow'] as $key) {
			if (isset($known[$key])) {
				$namespaces = array_merge($namespaces, (array) $known[$key]);
			}
		}
		return $namespaces;
	}

	public static function rule_labels()
	{
		return [
			'inherit' => __('Inherit', 'wirecore-rest-control'),
			'public' => __('Public', 'wirecore-rest-control'),
			'auth' => __('Logged-in only', 'wirecore-rest-control'),
			'admin' => __('Admins only', 'wirecore-rest-control'),
			'blocked' => __('Blocked', 'wirecore-rest-control'),
		];
	}

	public static function sanitize(array $input)
	{
		$defaults = self::defaults();
		$settings = [];

		$default = isset($input['default']) ? (array) $input['default'] : [];
		foreach (['read', 'write'] as $group) {
			$rule = isset($default[$group]) ? (string) $default[$group] : '';
			$settings['default'][$group] =
				$rule !== 'inherit' && in_array($rule, self::RULES, true)
					? $rule
					: $defaults['default'][$group];
		}

		foreach (['namespaces', 'routes'] as $scope) {
			$settings[$scope] = self::sanitize_overrides(
				isset($input[$scope]) ? (array) $input[$scope] : []
			);
		}

		$settings['allowed_roles'] = self::sanitize_roles(
			isset($input['allowed_roles'])
				? (array) $input['allowed_roles']
				: []
		);
		$settings['user_whitelist'] = self::sanitize_users(
			isset($input['user_whitelist'])
				? (array) $input['user_whitelist']
				: []
		);
		$settings['ip_whitelist'] = self::sanitize_ips(
			isset($input['ip_whitelist']) ? (array) $input['ip_whitelist'] : []
		);

		$settings['ip_blacklist'] = self::sanitize_ips(
			isset($input['ip_blacklist']) ? (array) $input['ip_blacklist'] : []
		);

		$settings['access'] = [];
		$access = isset($input['access']) ? (array) $input['access'] : [];
		foreach ($access as $target => $lists) {
			$target = self::clean_key($target);
			if ($target === '' || !is_array($lists)) {
				continue;
			}
			$entry = [];
			foreach (self::ACCESS_LISTS as $list) {
				$values = isset($lists[$list]) ? (array) $lists[$list] : [];
				if ($list === 'users_allow') {
					$entry[$list] = self::sanitize_users($values);
				} elseif (strpos($list, 'roles_') === 0) {
					$entry[$list] = self::sanitize_roles($values);
				} else {
					$entry[$list] = self::sanitize_ips($values);
				}
			}
			if (array_filter($entry)) {
				$settings['access'][$target] = $entry;
			}
		}
		ksort($settings['access']);

		$known = array_keys(self::known_namespaces());
		$settings['auto_allow'] = isset($input['auto_allow'])
			? array_values(
				array_intersect(
					array_map('strval', (array) $input['auto_allow']),
					$known
				)
			)
			: $defaults['auto_allow'];

		$settings['error_message'] = isset($input['error_message'])
			? sanitize_text_field((string) $input['error_message'])
			: '';

		$status = isset($input['error_status'])
			? (int) $input['error_status']
			: 0;
		$settings['error_status'] = in_array($status, self::STATUSES, true)
			? $status
			: $defaults['error_status'];

		foreach (self::FLAGS as $flag) {
			$settings[$flag] = isset($input[$flag])
				? (bool) $input[$flag]
				: $defaults[$flag];
		}

		$log_ip = isset($input['log_ip']) ? (string) $input['log_ip'] : '';
		$settings['log_ip'] = in_array($log_ip, self::LOG_IP_MODES, true)
			? $log_ip
			: $defaults['log_ip'];

		return $settings;
	}

	/**
	 * Namespaces or route patterns (regex like "/wp/v2/users/(?P<id>[\d]+)"),
	 * so no sanitize_text_field(), which would strip "<id>" as a tag. They are
	 * only output escaped. Invalid = empty string.
	 */
	private static function clean_key($key)
	{
		$key = trim(
			preg_replace(
				'/[\x00-\x1F\x7F]/',
				'',
				wp_check_invalid_utf8((string) $key)
			)
		);
		return strlen($key) > 500 ? '' : $key;
	}

	private static function sanitize_overrides(array $overrides)
	{
		$clean = [];
		foreach ($overrides as $key => $pair) {
			$key = self::clean_key($key);
			if ($key === '' || !is_array($pair)) {
				continue;
			}
			$rules = [];
			foreach (['read', 'write'] as $group) {
				$rule = isset($pair[$group]) ? (string) $pair[$group] : '';
				$rules[$group] = in_array($rule, self::RULES, true)
					? $rule
					: 'inherit';
			}
			// entries that only inherit are no rule
			if ($rules['read'] !== 'inherit' || $rules['write'] !== 'inherit') {
				$clean[$key] = $rules;
			}
		}
		ksort($clean);
		return $clean;
	}

	private static function sanitize_roles(array $roles)
	{
		return array_values(
			array_intersect(
				array_map('strval', $roles),
				array_keys(wp_roles()->roles)
			)
		);
	}

	private static function sanitize_users(array $users)
	{
		return array_values(
			array_unique(array_filter(array_map('absint', $users)))
		);
	}

	private static function sanitize_ips(array $ips)
	{
		$ips = array_map('trim', array_map('strval', $ips));
		return array_values(
			array_unique(array_filter($ips, [self::class, 'is_ip_range']))
		);
	}

	/**
	 * Single IPv4/IPv6 address or CIDR range.
	 */
	public static function is_ip_range($range)
	{
		$parts = explode('/', (string) $range, 2);
		if (!filter_var($parts[0], FILTER_VALIDATE_IP)) {
			return false;
		}
		if (!isset($parts[1])) {
			return true;
		}
		$max = strpos($parts[0], ':') === false ? 32 : 128;
		return ctype_digit($parts[1]) && (int) $parts[1] <= $max;
	}

	public static function export()
	{
		return [
			'plugin' => 'wirecore-rest-control',
			'version' => WIRECORE_RC_VERSION,
			'settings' => self::get(),
		];
	}

	/**
	 * @return true|WP_Error
	 */
	public static function import($json)
	{
		$data = json_decode((string) $json, true);
		if (
			!is_array($data) ||
			($data['plugin'] ?? '') !== 'wirecore-rest-control' ||
			!isset($data['settings']) ||
			!is_array($data['settings'])
		) {
			return new WP_Error(
				'wirecore_rc_invalid_import',
				__(
					'The file is not a valid export of this plugin.',
					'wirecore-rest-control'
				)
			);
		}
		self::save($data['settings']);
		return true;
	}
}
