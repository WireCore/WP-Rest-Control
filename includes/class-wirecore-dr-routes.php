<?php

if (!defined('ABSPATH')) {
	exit();
}

/**
 * Reads all registered REST routes – including those of plugins and themes –
 * and detects via reflection which plugin or theme registered them.
 */
class WireCore_DR_Routes
{
	const READ_METHODS = ['GET', 'HEAD', 'OPTIONS'];

	private static $routes = null;

	/**
	 * Grouped by namespace:
	 * ['wp/v2' => ['source' => 'WordPress', 'routes' => ['/wp/v2/posts' =>
	 * ['methods' => [...], 'read' => bool, 'write' => bool, 'source' => ...]]]]
	 */
	public static function all()
	{
		$server = rest_get_server();
		$namespaces = [];
		foreach ($server->get_routes() as $route => $handlers) {
			$methods = [];
			$source = '';
			foreach ($handlers as $handler) {
				$methods = array_merge(
					$methods,
					array_keys(array_filter((array) $handler['methods']))
				);
				if ($source === '' && isset($handler['callback'])) {
					$source = self::source($handler['callback']);
				}
			}
			$methods = array_values(array_unique($methods));
			$namespace = self::namespace_of($route);

			$namespaces[$namespace]['routes'][$route] = [
				'methods' => $methods,
				'read' => (bool) array_intersect($methods, self::READ_METHODS),
				'write' => (bool) array_diff($methods, self::READ_METHODS),
				'source' =>
					$source !== ''
						? $source
						: __('Unknown', 'wirecore-disable-rest'),
			];
		}

		foreach ($namespaces as $namespace => $data) {
			// Source of a namespace = most common source of its routes. The
			// index route "/<namespace>" is registered by core itself, so it
			// does not count and inherits the source of the namespace.
			$index = '/' . $namespace;
			$routes = $data['routes'];
			if (count($routes) > 1) {
				unset($routes[$index]);
			}
			$counts = array_count_values(array_column($routes, 'source'));
			arsort($counts);
			$source = (string) key($counts);
			$namespaces[$namespace]['source'] = $source;
			if (isset($data['routes'][$index])) {
				$namespaces[$namespace]['routes'][$index]['source'] = $source;
			}
		}
		ksort($namespaces);
		return $namespaces;
	}

	/**
	 * Route pattern and namespace for a request path, matched the same way as
	 * WP_REST_Server::match_request_to_handler().
	 *
	 * @return array|null [pattern, namespace]
	 */
	public static function match($path, $method)
	{
		if (self::$routes === null) {
			self::$routes = rest_get_server()->get_routes();
		}
		// Core answers HEAD requests with the GET handlers
		$method = $method === 'HEAD' ? 'GET' : $method;
		foreach (self::$routes as $route => $handlers) {
			if (!preg_match('@^' . $route . '$@i', $path)) {
				continue;
			}
			foreach ($handlers as $handler) {
				if (!empty($handler['methods'][$method])) {
					return [$route, self::namespace_of($route)];
				}
			}
		}
		return null;
	}

	private static function namespace_of($route)
	{
		$options = rest_get_server()->get_route_options($route);
		return isset($options['namespace'])
			? (string) $options['namespace']
			: '';
	}

	/**
	 * For methods, the file of the object's class counts, not the file of the
	 * method – otherwise plugin controllers extending a core controller would
	 * be detected as "WordPress".
	 */
	private static function source($callback)
	{
		try {
			if (is_string($callback) && strpos($callback, '::') !== false) {
				$callback = explode('::', $callback, 2);
			}
			if (is_array($callback) && isset($callback[0])) {
				$reflection = new ReflectionClass($callback[0]);
			} elseif ($callback instanceof Closure || is_string($callback)) {
				$reflection = new ReflectionFunction($callback);
			} elseif (is_object($callback)) {
				$reflection = new ReflectionClass($callback);
			} else {
				return '';
			}
		} catch (ReflectionException $e) {
			return '';
		}
		$file = $reflection->getFileName();
		return $file ? self::source_of_file($file) : '';
	}

	private static function source_of_file($file)
	{
		$file = wp_normalize_path($file);

		$plugin_dir = trailingslashit(wp_normalize_path(WP_PLUGIN_DIR));
		if (strpos($file, $plugin_dir) === 0) {
			$slug = strtok(substr($file, strlen($plugin_dir)), '/');
			return sprintf(
				/* translators: %s: plugin name */
				__('%s (plugin)', 'wirecore-disable-rest'),
				self::plugin_name($slug)
			);
		}

		$mu_dir = trailingslashit(wp_normalize_path(WPMU_PLUGIN_DIR));
		if (strpos($file, $mu_dir) === 0) {
			$slug = strtok(substr($file, strlen($mu_dir)), '/');
			return sprintf(
				/* translators: %s: file or folder name of the must-use plugin */
				__('%s (must-use plugin)', 'wirecore-disable-rest'),
				$slug
			);
		}

		foreach ((array) $GLOBALS['wp_theme_directories'] as $theme_root) {
			$theme_root = trailingslashit(wp_normalize_path($theme_root));
			if (strpos($file, $theme_root) === 0) {
				$slug = strtok(substr($file, strlen($theme_root)), '/');
				return sprintf(
					/* translators: %s: theme name */
					__('%s (theme)', 'wirecore-disable-rest'),
					wp_get_theme($slug)->get('Name') ?: $slug
				);
			}
		}

		if (strpos($file, wp_normalize_path(ABSPATH . WPINC)) === 0) {
			return 'WordPress';
		}
		return '';
	}

	private static function plugin_name($slug)
	{
		static $names = null;
		if ($names === null) {
			if (!function_exists('get_plugins')) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$names = [];
			foreach (get_plugins() as $file => $data) {
				// "slug/file.php", or "file.php" for single-file plugins
				$names[strtok($file, '/')] = $data['Name'];
			}
		}
		return isset($names[$slug]) ? $names[$slug] : $slug;
	}
}
