<?php

if (!defined('ABSPATH')) {
	exit();
}

/**
 * Manages the REST API restrictions of WireCore Disable REST.
 */
class WireCore_DR_Cli
{
	/**
	 * Shows the default rule, the number of custom rules and the main switches.
	 */
	public function status()
	{
		$settings = WireCore_DR_Settings::get();
		WP_CLI::log(
			sprintf(
				'Default: read=%s, write=%s',
				$settings['default']['read'],
				$settings['default']['write']
			)
		);
		WP_CLI::log(
			sprintf(
				'Custom rules: %d namespaces, %d routes',
				count($settings['namespaces']),
				count($settings['routes'])
			)
		);
		WP_CLI::log(
			sprintf(
				'Advanced exceptions: %d routes/namespaces',
				count($settings['access'])
			)
		);
		WP_CLI::log(
			sprintf(
				'Blocked IPs (global): %d',
				count($settings['ip_blacklist'])
			)
		);
		WP_CLI::log(
			sprintf(
				'Automatically allowed: %s',
				implode(
					', ',
					WireCore_DR_Settings::auto_allowed_namespaces()
				) ?:
				'-'
			)
		);
		foreach (WireCore_DR_Settings::FLAGS as $flag) {
			WP_CLI::log(
				sprintf('%s: %s', $flag, $settings[$flag] ? 'on' : 'off')
			);
		}
		WP_CLI::log(sprintf('log_ip: %s', $settings['log_ip']));
		if (defined('WIRECORE_DR_OFF') && WIRECORE_DR_OFF) {
			WP_CLI::warning('WIRECORE_DR_OFF is set, no rules are enforced.');
		}
	}

	/**
	 * Lists all registered routes with their source and effective rule.
	 *
	 * ## OPTIONS
	 *
	 * [--namespace=<namespace>]
	 * : Only routes of this namespace.
	 *
	 * [--source=<source>]
	 * : Only routes whose source contains this text (e.g. "WooCommerce").
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 */
	public function routes($args, $assoc_args)
	{
		$items = [];
		foreach (WireCore_DR_Routes::all() as $namespace => $data) {
			if (
				isset($assoc_args['namespace']) &&
				$assoc_args['namespace'] !== $namespace
			) {
				continue;
			}
			foreach ($data['routes'] as $route => $info) {
				if (
					isset($assoc_args['source']) &&
					stripos($info['source'], $assoc_args['source']) === false
				) {
					continue;
				}
				$items[] = [
					'namespace' => $namespace,
					'route' => $route,
					'methods' => implode(',', $info['methods']),
					'source' => $info['source'],
					'read' => $info['read']
						? WireCore_DR_Guard::rule($route, $namespace, 'GET')
						: '-',
					'write' => $info['write']
						? WireCore_DR_Guard::rule($route, $namespace, 'POST')
						: '-',
				];
			}
		}
		WP_CLI\Utils\format_items($assoc_args['format'], $items, [
			'namespace',
			'route',
			'methods',
			'source',
			'read',
			'write',
		]);
	}

	/**
	 * Sets a custom rule for a route or a namespace.
	 *
	 * ## OPTIONS
	 *
	 * <target>
	 * : Route pattern (starts with "/", e.g. "/wp/v2/users") or namespace (e.g. "wc/store/v1").
	 *
	 * [--read=<rule>]
	 * : Rule for GET/HEAD: inherit, public, auth, admin, blocked.
	 *
	 * [--write=<rule>]
	 * : Rule for POST/PUT/PATCH/DELETE: inherit, public, auth, admin, blocked.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wirecore-dr set contact-form-7/v1 --read=public --write=public
	 *     wp wirecore-dr set /wp/v2/users --read=admin --write=admin
	 *     wp wirecore-dr set /wp/v2/users --read=inherit --write=inherit
	 */
	public function set($args, $assoc_args)
	{
		$target = $args[0];
		$scope = strpos($target, '/') === 0 ? 'routes' : 'namespaces';

		$settings = WireCore_DR_Settings::get();
		$rules = $settings[$scope][$target] ?? [
			'read' => 'inherit',
			'write' => 'inherit',
		];
		foreach (['read', 'write'] as $group) {
			if (isset($assoc_args[$group])) {
				$rules[$group] = self::rule_arg($assoc_args[$group], true);
			}
		}
		$settings[$scope][$target] = $rules;
		WireCore_DR_Settings::save($settings);

		$server = rest_get_server();
		$known =
			$scope === 'routes'
				? array_key_exists($target, $server->get_routes())
				: in_array($target, $server->get_namespaces(), true);
		if (!$known) {
			WP_CLI::warning(
				sprintf(
					'"%s" is currently not registered. The rule was saved anyway.',
					$target
				)
			);
		}
		WP_CLI::success(
			sprintf(
				'%s: read=%s, write=%s',
				$target,
				$rules['read'],
				$rules['write']
			)
		);
	}

	/**
	 * Sets the default rule for all routes without a custom rule.
	 *
	 * ## OPTIONS
	 *
	 * [--read=<rule>]
	 * : public, auth, admin or blocked.
	 *
	 * [--write=<rule>]
	 * : public, auth, admin or blocked.
	 *
	 * @subcommand default
	 */
	public function set_default($args, $assoc_args)
	{
		$settings = WireCore_DR_Settings::get();
		foreach (['read', 'write'] as $group) {
			if (isset($assoc_args[$group])) {
				$settings['default'][$group] = self::rule_arg(
					$assoc_args[$group],
					false
				);
			}
		}
		WireCore_DR_Settings::save($settings);
		WP_CLI::success(
			sprintf(
				'Default: read=%s, write=%s',
				$settings['default']['read'],
				$settings['default']['write']
			)
		);
	}

	/**
	 * Exports the settings as JSON.
	 *
	 * ## OPTIONS
	 *
	 * [<file>]
	 * : Target file. Prints to STDOUT if omitted.
	 */
	public function export($args)
	{
		$json = wp_json_encode(
			WireCore_DR_Settings::export(),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		if (!isset($args[0])) {
			WP_CLI::line($json);
			return;
		}
		if (file_put_contents($args[0], $json . "\n") === false) {
			WP_CLI::error(sprintf('Could not write "%s".', $args[0]));
		}
		WP_CLI::success(sprintf('Exported to %s.', $args[0]));
	}

	/**
	 * Imports settings from an export file and replaces the current ones.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Export file.
	 */
	public function import($args)
	{
		if (!is_readable($args[0])) {
			WP_CLI::error(sprintf('Cannot read "%s".', $args[0]));
		}
		$result = WireCore_DR_Settings::import(file_get_contents($args[0]));
		if (is_wp_error($result)) {
			WP_CLI::error($result);
		}
		WP_CLI::success('Settings imported.');
	}

	/**
	 * Shows the most recently blocked requests.
	 *
	 * ## OPTIONS
	 *
	 * [--clear]
	 * : Clear the log.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 */
	public function log($args, $assoc_args)
	{
		if (!empty($assoc_args['clear'])) {
			WireCore_DR_Log::clear();
			WP_CLI::success('Log cleared.');
			return;
		}
		$log = WireCore_DR_Log::get();
		$items = array_map(function ($entry) {
			$entry['time'] = wp_date('Y-m-d H:i:s', $entry['time']);
			return $entry;
		}, $log['entries']);
		WP_CLI\Utils\format_items($assoc_args['format'], $items, [
			'time',
			'method',
			'route',
			'ip',
			'user',
			'reason',
		]);
		if ($assoc_args['format'] === 'table') {
			WP_CLI::log(sprintf('Blocked in total: %d', $log['count']));
		}
	}

	private static function rule_arg($rule, $allow_inherit)
	{
		$rules = WireCore_DR_Settings::RULES;
		if (!$allow_inherit) {
			$rules = array_diff($rules, ['inherit']);
		}
		if (!in_array($rule, $rules, true)) {
			WP_CLI::error(
				sprintf(
					'Invalid rule "%s". Allowed: %s',
					$rule,
					implode(', ', $rules)
				)
			);
		}
		return $rule;
	}
}
