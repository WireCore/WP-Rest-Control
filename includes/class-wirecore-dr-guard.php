<?php

if (!defined('ABSPATH')) {
	exit();
}

/**
 * Enforces the rules: checks every REST request, removes REST links from the
 * headers and <head>, disables JSONP and hides blocked routes in the index.
 */
class WireCore_DR_Guard
{
	// Reason of the last block for the log (rule, "denied" or "ssl")
	private static $reason = '';

	public static function init()
	{
		$settings = WireCore_DR_Settings::get();

		// Also covers _embed and sub-requests of /batch/v1, unlike
		// rest_pre_dispatch
		add_filter(
			'rest_request_before_callbacks',
			[self::class, 'check'],
			10,
			3
		);

		if ($settings['remove_links']) {
			remove_action('wp_head', 'rest_output_link_wp_head', 10);
			remove_action('template_redirect', 'rest_output_link_header', 11);
			remove_action('xmlrpc_rsd_apis', 'rest_output_rsd');
		}
		if ($settings['disable_jsonp']) {
			add_filter('rest_jsonp_enabled', '__return_false');
		}
		if ($settings['hide_index']) {
			add_filter('rest_index', [self::class, 'filter_index']);
			add_filter('rest_namespace_index', [self::class, 'filter_index']);
		}
	}

	/**
	 * Real REST requests only. Internal rest_do_request() calls made by PHP
	 * (editor preloading, server-side rendering) are not affected.
	 * WIRECORE_DR_OFF in wp-config.php is the emergency switch.
	 */
	public static function enforced()
	{
		return defined('REST_REQUEST') &&
			REST_REQUEST &&
			!(defined('WIRECORE_DR_OFF') && WIRECORE_DR_OFF);
	}

	public static function check($response, $handler, $request)
	{
		// Also check when $response is already an error (e.g. invalid
		// parameters): otherwise blocked visitors learn which parameters a
		// blocked route expects.
		if (!self::enforced()) {
			return $response;
		}

		$path = $request->get_route();
		$method = $request->get_method();
		$match = WireCore_DR_Routes::match($path, $method);
		list($route, $namespace) = $match ? $match : [$path, ''];

		// Route list of the settings page (manage_options only). Never block
		// it, otherwise the rules could no longer be edited.
		if ($route === '/' && WireCore_DR_Admin::routes_request()) {
			return $response;
		}

		$user = wp_get_current_user();
		$ip = self::client_ip();
		$result = self::decide($route, $namespace, $method, $user, $ip);
		if ($result === true) {
			return $response;
		}

		WireCore_DR_Log::add([
			'method' => $method,
			'route' => $path,
			'ip' => $ip,
			'user' => $user->ID,
			'reason' => self::$reason,
		]);
		return $result;
	}

	/**
	 * @return true|WP_Error
	 */
	public static function decide(
		$route,
		$namespace,
		$method,
		WP_User $user,
		$ip
	) {
		$settings = WireCore_DR_Settings::get();

		if ($settings['require_ssl'] && !is_ssl()) {
			self::$reason = 'ssl';
			return self::error(
				'rest_ssl_required',
				__(
					'The REST API is only available over HTTPS.',
					'wirecore-disable-rest'
				),
				403,
				$route,
				$method
			);
		}

		// Order: denials before allowances before the rule. Denials also apply
		// to administrators.
		$access = self::access($route, $namespace);
		if (self::denied($settings, $access, $user, $ip)) {
			self::$reason = 'denied';
			return self::restricted($settings, $route, $method);
		}

		if (
			self::bypass($settings, $user, $ip) ||
			self::excepted($access, $user, $ip)
		) {
			return true;
		}

		$rule = self::rule($route, $namespace, $method);
		if (self::allows($rule, $user, $settings)) {
			return true;
		}

		self::$reason = $rule;
		return self::restricted($settings, $route, $method);
	}

	private static function restricted(array $settings, $route, $method)
	{
		$status = $settings['error_status'];
		return self::error(
			// With 404, respond like a route that does not exist
			$status === 404 ? 'rest_no_route' : 'rest_restricted',
			$settings['error_message'] !== ''
				? $settings['error_message']
				: __(
					'Access to the REST API is restricted.',
					'wirecore-disable-rest'
				),
			$status,
			$route,
			$method
		);
	}

	/**
	 * The most specific rule wins: route, then namespace, then the automatic
	 * allowance of known front-end namespaces, then the default.
	 */
	public static function rule($route, $namespace, $method)
	{
		$settings = WireCore_DR_Settings::get();
		$group = in_array(
			strtoupper($method),
			WireCore_DR_Routes::READ_METHODS,
			true
		)
			? 'read'
			: 'write';

		foreach (
			[
				$settings['routes'][$route][$group] ?? 'inherit',
				$settings['namespaces'][$namespace][$group] ?? 'inherit',
			]
			as $rule
		) {
			if ($rule !== 'inherit') {
				return $rule;
			}
		}
		if (
			in_array(
				$namespace,
				WireCore_DR_Settings::auto_allowed_namespaces(),
				true
			)
		) {
			return 'public';
		}
		return $settings['default'][$group];
	}

	private static function allows($rule, WP_User $user, array $settings)
	{
		switch ($rule) {
			case 'public':
				return true;
			case 'auth':
				return $user->exists() &&
					(!$settings['allowed_roles'] ||
						array_intersect(
							$user->roles,
							$settings['allowed_roles']
						));
			case 'admin':
				return user_can($user, 'manage_options');
			default:
				return false;
		}
	}

	/**
	 * Exceptions from the "Advanced" dialog: entries of the route and of its
	 * namespace combined.
	 */
	public static function access($route, $namespace)
	{
		$all = WireCore_DR_Settings::get()['access'];
		$access = [];
		foreach (WireCore_DR_Settings::ACCESS_LISTS as $list) {
			$access[$list] = array_merge(
				$all[$route][$list] ?? [],
				$all[$namespace][$list] ?? []
			);
		}
		return $access;
	}

	private static function denied(
		array $settings,
		array $access,
		WP_User $user,
		$ip
	) {
		if (
			$ip !== '' &&
			self::ip_in_any(
				$ip,
				array_merge($settings['ip_blacklist'], $access['ip_deny'])
			)
		) {
			return true;
		}
		return (bool) array_intersect($user->roles, $access['roles_deny']);
	}

	private static function excepted(array $access, WP_User $user, $ip)
	{
		return ($ip !== '' && self::ip_in_any($ip, $access['ip_allow'])) ||
			($user->exists() &&
				in_array($user->ID, $access['users_allow'], true)) ||
			array_intersect($user->roles, $access['roles_allow']);
	}

	private static function ip_in_any($ip, array $ranges)
	{
		foreach ($ranges as $range) {
			if (self::ip_in_range($ip, $range)) {
				return true;
			}
		}
		return false;
	}

	private static function bypass(array $settings, WP_User $user, $ip)
	{
		if ($settings['admin_bypass'] && user_can($user, 'manage_options')) {
			return true;
		}
		if (
			$user->exists() &&
			in_array($user->ID, $settings['user_whitelist'], true)
		) {
			return true;
		}
		return $ip !== '' && self::ip_in_any($ip, $settings['ip_whitelist']);
	}

	private static function error($code, $message, $status, $route, $method)
	{
		$error = new WP_Error($code, $message, ['status' => $status]);
		return apply_filters('wirecore_dr_error', $error, $route, $method);
	}

	public static function client_ip()
	{
		$ip = isset($_SERVER['REMOTE_ADDR'])
			? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
			: '';
		// e.g. for sites behind a reverse proxy
		$ip = (string) apply_filters('wirecore_dr_client_ip', $ip);
		return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
	}

	private static function ip_in_range($ip, $range)
	{
		$parts = explode('/', $range, 2);
		$ip_bin = inet_pton($ip);
		$net_bin = inet_pton($parts[0]);
		if ($ip_bin === false || strlen($ip_bin) !== strlen($net_bin)) {
			return false;
		}
		if (!isset($parts[1])) {
			return $ip_bin === $net_bin;
		}
		$bits = (int) $parts[1];
		$bytes = intdiv($bits, 8);
		if (substr($ip_bin, 0, $bytes) !== substr($net_bin, 0, $bytes)) {
			return false;
		}
		if ($bits % 8 === 0) {
			return true;
		}
		$mask = (0xff << 8 - ($bits % 8)) & 0xff;
		return (ord($ip_bin[$bytes]) & $mask) ===
			(ord($net_bin[$bytes]) & $mask);
	}

	/**
	 * Removes routes the current user may not call with any of their methods,
	 * and namespaces without remaining routes.
	 */
	public static function filter_index($response)
	{
		if (!self::enforced() || !($response instanceof WP_REST_Response)) {
			return $response;
		}
		$data = $response->get_data();
		if (empty($data['routes']) || !is_array($data['routes'])) {
			return $response;
		}

		$user = wp_get_current_user();
		$ip = self::client_ip();
		$visible = [];
		foreach ($data['routes'] as $route => $info) {
			$namespace = isset($info['namespace'])
				? (string) $info['namespace']
				: '';
			$allowed = false;
			foreach ((array) ($info['methods'] ?? []) as $method) {
				if (
					self::decide($route, $namespace, $method, $user, $ip) ===
					true
				) {
					$allowed = true;
					break;
				}
			}
			if ($allowed) {
				$visible[$namespace] = true;
			} else {
				unset($data['routes'][$route]);
			}
		}
		if (isset($data['namespaces']) && is_array($data['namespaces'])) {
			$data['namespaces'] = array_values(
				array_filter($data['namespaces'], function ($namespace) use (
					$visible
				) {
					return isset($visible[$namespace]);
				})
			);
		}
		$response->set_data($data);
		return $response;
	}
}
