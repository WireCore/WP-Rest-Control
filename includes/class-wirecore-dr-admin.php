<?php

if (!defined('ABSPATH')) {
	exit();
}

/**
 * Settings page under Settings → REST API with the tabs Routes, Settings, Log
 * and Tools.
 */
class WireCore_DR_Admin
{
	const PAGE = 'wirecore-disable-rest';
	const ACTIONS = ['routes', 'settings', 'log_clear', 'export', 'import'];
	const ROUTES_PARAM = 'wirecore_dr_routes';

	public static function init()
	{
		add_action('admin_menu', [self::class, 'menu']);
		add_filter('plugin_action_links_' . plugin_basename(WIRECORE_DR_FILE), [
			self::class,
			'action_links',
		]);
		add_action('admin_enqueue_scripts', [self::class, 'assets']);
		foreach (self::ACTIONS as $action) {
			add_action('admin_post_wirecore_dr_' . $action, [
				self::class,
				'handle_' . $action,
			]);
		}
	}

	public static function menu()
	{
		add_options_page(
			__('REST API', 'wirecore-disable-rest'),
			__('REST API', 'wirecore-disable-rest'),
			'manage_options',
			self::PAGE,
			[self::class, 'page']
		);
	}

	/**
	 * "Settings" link in the plugin list, leads to the route list.
	 */
	public static function action_links($links)
	{
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url(self::tab_url('routes')),
				esc_html__('Settings', 'wirecore-disable-rest')
			)
		);
		return $links;
	}

	public static function assets($hook)
	{
		if ($hook !== 'settings_page_' . self::PAGE) {
			return;
		}
		wp_enqueue_style(
			'wirecore-dr-admin',
			plugins_url('assets/admin.css', WIRECORE_DR_FILE),
			[],
			WIRECORE_DR_VERSION
		);
		wp_enqueue_script(
			'wirecore-dr-admin',
			plugins_url('assets/admin.js', WIRECORE_DR_FILE),
			[],
			WIRECORE_DR_VERSION,
			true
		);
		wp_localize_script('wirecore-dr-admin', 'wirecoreDr', [
			'labels' => WireCore_DR_Settings::rule_labels(),
			'read' => __('Read', 'wirecore-disable-rest'),
			'write' => __('Write', 'wirecore-disable-rest'),
			'auto' => __('automatic', 'wirecore-disable-rest'),
			'noNamespace' => __('(no namespace)', 'wirecore-disable-rest'),
			'autoAllow' => WireCore_DR_Settings::auto_allowed_namespaces(),
			'roles' => self::role_names(),
			'summary' => [
				'ip_allow' => __('IPs allowed', 'wirecore-disable-rest'),
				'users_allow' => __('Users allowed', 'wirecore-disable-rest'),
				'roles_allow' => __('Roles allowed', 'wirecore-disable-rest'),
				'ip_deny' => __('IPs denied', 'wirecore-disable-rest'),
				'roles_deny' => __('Roles denied', 'wirecore-disable-rest'),
				'allow' => __('Allowed', 'wirecore-disable-rest'),
				'deny' => __('Denied', 'wirecore-disable-rest'),
			],
			'expand' => __('Expand all', 'wirecore-disable-rest'),
			'collapse' => __('Collapse all', 'wirecore-disable-rest'),
			'url' => add_query_arg(
				[self::ROUTES_PARAM => 1, '_fields' => self::ROUTES_PARAM],
				rest_url()
			),
			'field' => self::ROUTES_PARAM,
			'nonce' => wp_create_nonce('wp_rest'),
			'loadError' => __(
				'The routes could not be loaded via the REST API:',
				'wirecore-disable-rest'
			),
		]);
	}

	public static function handle_routes()
	{
		self::require_capability();
		check_admin_referer('wirecore_dr_routes');

		// All rules arrive as one JSON field, hundreds of selects would exceed
		// max_input_vars.
		// A JSON document: sanitize_text_field() would break route patterns
		// like "(?P<id>[\d]+)". Every value is validated by
		// WireCore_DR_Settings::sanitize() before it is stored.
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$rules = isset($_POST['rules_json'])
			? json_decode(wp_unslash($_POST['rules_json']), true)
			: null;
		// phpcs:enable
		if (!is_array($rules)) {
			self::redirect('routes', 'no_js');
		}

		$settings = WireCore_DR_Settings::get();
		foreach (['default', 'namespaces', 'routes', 'access'] as $key) {
			$settings[$key] = isset($rules[$key]) ? (array) $rules[$key] : [];
		}
		WireCore_DR_Settings::save($settings);

		$saved = WireCore_DR_Settings::get();
		self::redirect(
			'routes',
			self::count_ips($saved['access']) ===
			self::count_ips($settings['access'])
				? 'saved'
				: 'invalid_ips'
		);
	}

	private static function count_ips(array $access)
	{
		$count = 0;
		foreach ($access as $lists) {
			foreach (['ip_allow', 'ip_deny'] as $list) {
				$count += count(array_unique((array) ($lists[$list] ?? [])));
			}
		}
		return $count;
	}

	private static function known_label($key)
	{
		$labels = [
			'contact-form-7' => 'Contact Form 7',
			'woocommerce-store' => __(
				'WooCommerce Store API (cart, checkout)',
				'wirecore-disable-rest'
			),
			'oembed' => __(
				'oEmbed (embedding posts on other sites)',
				'wirecore-disable-rest'
			),
			'litespeed' => __(
				'LiteSpeed Cache (QUIC.cloud)',
				'wirecore-disable-rest'
			),
			'wp-statistics' => 'WP Statistics',
		];
		return isset($labels[$key]) ? $labels[$key] : $key;
	}

	private static function role_names()
	{
		return array_map('translate_user_role', wp_roles()->get_names());
	}

	public static function handle_settings()
	{
		self::require_capability();
		check_admin_referer('wirecore_dr_settings');

		$post = wp_unslash($_POST);
		$ips = self::lines(
			isset($post['ip_whitelist']) ? $post['ip_whitelist'] : ''
		);
		$blocked_ips = self::lines(
			isset($post['ip_blacklist']) ? $post['ip_blacklist'] : ''
		);

		$settings = WireCore_DR_Settings::get();
		$settings['allowed_roles'] = isset($post['allowed_roles'])
			? (array) $post['allowed_roles']
			: [];
		$settings['user_whitelist'] = self::lines(
			isset($post['user_whitelist']) ? $post['user_whitelist'] : ''
		);
		$settings['ip_whitelist'] = $ips;
		$settings['ip_blacklist'] = $blocked_ips;
		$settings['auto_allow'] = isset($post['auto_allow'])
			? (array) $post['auto_allow']
			: [];
		$settings['log_ip'] = isset($post['log_ip']) ? $post['log_ip'] : '';
		$settings['error_message'] = isset($post['error_message'])
			? $post['error_message']
			: '';
		$settings['error_status'] = isset($post['error_status'])
			? $post['error_status']
			: 0;
		foreach (WireCore_DR_Settings::FLAGS as $flag) {
			$settings[$flag] = !empty($post[$flag]);
		}
		WireCore_DR_Settings::save($settings);

		$saved = WireCore_DR_Settings::get();
		self::redirect(
			'settings',
			count($saved['ip_whitelist']) === count(array_unique($ips)) &&
			count($saved['ip_blacklist']) === count(array_unique($blocked_ips))
				? 'saved'
				: 'invalid_ips'
		);
	}

	public static function handle_log_clear()
	{
		self::require_capability();
		check_admin_referer('wirecore_dr_log_clear');

		WireCore_DR_Log::clear();
		self::redirect('log', 'log_cleared');
	}

	public static function handle_export()
	{
		self::require_capability();
		check_admin_referer('wirecore_dr_export');

		nocache_headers();
		header('Content-Type: application/json; charset=utf-8');
		header(
			'Content-Disposition: attachment; filename=wirecore-disable-rest-settings.json'
		);
		echo wp_json_encode(
			WireCore_DR_Settings::export(),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		exit();
	}

	public static function handle_import()
	{
		self::require_capability();
		check_admin_referer('wirecore_dr_import');

		// $_FILES is not slashed by WordPress, and the temporary path is only
		// read after is_uploaded_file() confirmed it is a PHP upload.
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput
		$upload = isset($_FILES['import_file'])
			? (array) $_FILES['import_file']
			: [];
		// phpcs:enable
		$file = isset($upload['tmp_name']) ? (string) $upload['tmp_name'] : '';
		if (
			!$file ||
			($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK ||
			(int) ($upload['size'] ?? 0) > MB_IN_BYTES ||
			!is_uploaded_file($file)
		) {
			self::redirect('tools', 'import_failed');
		}
		$result = WireCore_DR_Settings::import(file_get_contents($file));
		self::redirect(
			'tools',
			$result === true ? 'imported' : 'import_failed'
		);
	}

	private static function require_capability()
	{
		if (!current_user_can('manage_options')) {
			wp_die(
				esc_html__(
					'Insufficient permissions.',
					'wirecore-disable-rest'
				),
				'',
				[
					'response' => 403,
				]
			);
		}
	}

	private static function redirect($tab, $notice)
	{
		wp_safe_redirect(
			add_query_arg(
				['tab' => $tab, 'wirecore_dr_notice' => $notice],
				admin_url('options-general.php?page=' . self::PAGE)
			)
		);
		exit();
	}

	private static function lines($text)
	{
		return preg_split(
			'/[\s,]+/',
			trim((string) $text),
			-1,
			PREG_SPLIT_NO_EMPTY
		);
	}

	private static function tab_url($tab)
	{
		return add_query_arg(
			'tab',
			$tab,
			admin_url('options-general.php?page=' . self::PAGE)
		);
	}

	public static function page()
	{
		if (!current_user_can('manage_options')) {
			return;
		}

		$tabs = [
			'routes' => __('Routes', 'wirecore-disable-rest'),
			'settings' => __('Settings', 'wirecore-disable-rest'),
			'log' => __('Log', 'wirecore-disable-rest'),
			'tools' => __('Tools', 'wirecore-disable-rest'),
		];
		// Read-only display parameters, they do not change anything.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$tab = isset($_GET['tab'])
			? sanitize_key(wp_unslash($_GET['tab']))
			: '';
		if (!isset($tabs[$tab])) {
			$tab = 'routes';
		}

		$notice = isset($_GET['wirecore_dr_notice'])
			? sanitize_key(wp_unslash($_GET['wirecore_dr_notice']))
			: '';
		// phpcs:enable
		$notices = [
			'saved' => [
				'success',
				__('Settings saved.', 'wirecore-disable-rest'),
			],
			'invalid_ips' => [
				'warning',
				__(
					'Saved. Invalid IP entries were discarded.',
					'wirecore-disable-rest'
				),
			],
			'no_js' => [
				'error',
				__(
					'The rules could not be read. JavaScript is required to save them.',
					'wirecore-disable-rest'
				),
			],
			'log_cleared' => [
				'success',
				__('The log has been cleared.', 'wirecore-disable-rest'),
			],
			'imported' => [
				'success',
				__('Settings imported.', 'wirecore-disable-rest'),
			],
			'import_failed' => [
				'error',
				__(
					'Import failed. Please choose an export file of this plugin.',
					'wirecore-disable-rest'
				),
			],
		];
		?>
		<div class="wrap wirecore-dr">
			<h1><?php echo esc_html(get_admin_page_title()); ?></h1>

			<?php if (defined('WIRECORE_DR_OFF') && WIRECORE_DR_OFF): ?>
				<div class="notice notice-warning">
					<p><?php esc_html_e(
     	'WIRECORE_DR_OFF is set in wp-config.php: no rules are enforced.',
     	'wirecore-disable-rest'
     ); ?></p>
				</div>
			<?php endif; ?>

			<?php if (isset($notices[$notice])): ?>
				<div class="notice notice-<?php echo esc_attr(
    	$notices[$notice][0]
    ); ?> is-dismissible">
					<p><?php echo esc_html($notices[$notice][1]); ?></p>
				</div>
			<?php endif; ?>

			<nav class="nav-tab-wrapper">
				<?php foreach ($tabs as $key => $label): ?>
					<a href="<?php echo esc_url(
     	self::tab_url($key)
     ); ?>" class="nav-tab<?php echo $key === $tab
	? ' nav-tab-active'
	: ''; ?>"><?php echo esc_html($label); ?></a>
				<?php endforeach; ?>
			</nav>

			<?php
   $method = 'tab_' . $tab;
   self::$method();?>
		</div>
		<?php
	}

	private static function rule_select(
		$scope,
		$key,
		$group,
		$value,
		$disabled = false
	) {
		$labels = WireCore_DR_Settings::rule_labels();
		if ($scope === 'default') {
			unset($labels['inherit']);
		}
		?>
		<select data-scope="<?php echo esc_attr(
  	$scope
  ); ?>" data-key="<?php echo esc_attr(
	$key
); ?>" data-group="<?php echo esc_attr(
	$group
); ?>" aria-label="<?php echo esc_attr(
	$group === 'read'
		? __('Read', 'wirecore-disable-rest')
		: __('Write', 'wirecore-disable-rest')
); ?>"<?php disabled($disabled); ?>>
			<?php foreach ($labels as $rule => $label): ?>
				<option value="<?php echo esc_attr($rule); ?>"<?php selected(
	$value,
	$rule
); ?>><?php echo esc_html($label); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Some plugins only register routes during REST requests, and then only for
	 * the requested namespace or the index (e.g. WooCommerce). The route list is
	 * therefore attached to the index /wp-json/ and loaded via JS, otherwise
	 * these routes would be missing in the admin.
	 */
	public static function rest_init()
	{
		add_filter('rest_index', [self::class, 'rest_routes']);
	}

	/**
	 * Only selects the output of the REST index; access is protected by the
	 * capability check (and the REST nonce of the request).
	 */
	public static function routes_request()
	{
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset($_GET[self::ROUTES_PARAM]) &&
			current_user_can('manage_options');
	}

	public static function rest_routes($response)
	{
		if (!self::routes_request()) {
			return $response;
		}
		ob_start();
		self::routes_body();
		$data = $response->get_data();
		$data[self::ROUTES_PARAM] = ob_get_clean();
		$response->set_data($data);
		return $response;
	}

	private static function tab_routes()
	{
		$settings = WireCore_DR_Settings::get(); ?>
		<form method="post" action="<?php echo esc_url(
  	admin_url('admin-post.php')
  ); ?>" id="wirecore-dr-routes">
			<input type="hidden" name="action" value="wirecore_dr_routes" />
			<input type="hidden" name="rules_json" value="" />
			<?php wp_nonce_field('wirecore_dr_routes'); ?>

			<h2><?php esc_html_e('Default rule', 'wirecore-disable-rest'); ?></h2>
			<p class="description"><?php esc_html_e(
   	'Applies to all routes without a custom rule. “Read” covers GET/HEAD, “Write” covers POST/PUT/PATCH/DELETE. The most specific rule wins: route before namespace before automatic allowance before default.',
   	'wirecore-disable-rest'
   ); ?></p>
			<p class="wirecore-dr-default">
				<label><?php esc_html_e('Read', 'wirecore-disable-rest'); ?>
					<?php self::rule_select(
     	'default',
     	'',
     	'read',
     	$settings['default']['read']
     ); ?></label>
				<label><?php esc_html_e('Write', 'wirecore-disable-rest'); ?>
					<?php self::rule_select(
     	'default',
     	'',
     	'write',
     	$settings['default']['write']
     ); ?></label>
			</p>

			<h2><?php esc_html_e('Quick actions', 'wirecore-disable-rest'); ?></h2>
			<p class="wirecore-dr-presets">
				<button type="button" class="button" data-preset="guests"><?php esc_html_e(
    	'Block everything for guests',
    	'wirecore-disable-rest'
    ); ?></button>
				<button type="button" class="button" data-preset="all"><?php esc_html_e(
    	'Block everything',
    	'wirecore-disable-rest'
    ); ?></button>
				<button type="button" class="button" data-preset="users"><?php esc_html_e(
    	'User list (/wp/v2/users) for admins only',
    	'wirecore-disable-rest'
    ); ?></button>
				<button type="button" class="button" data-preset="reset"><?php esc_html_e(
    	'Reset custom rules',
    	'wirecore-disable-rest'
    ); ?></button>
				<span class="description"><?php esc_html_e(
    	'Changes take effect only after saving.',
    	'wirecore-disable-rest'
    ); ?></span>
			</p>

			<h2><?php esc_html_e('Routes', 'wirecore-disable-rest'); ?></h2>
			<p class="description"><?php esc_html_e(
   	'Use “Advanced” to additionally allow or deny IP addresses, roles and users for a namespace or route. The entries of a namespace apply to all of its routes.',
   	'wirecore-disable-rest'
   ); ?></p>
			<div id="wirecore-dr-routes-body">
				<p><span class="spinner is-active wirecore-dr-spinner"></span><?php esc_html_e(
    	'Loading routes …',
    	'wirecore-disable-rest'
    ); ?></p>
			</div>

			<?php submit_button(
   	__('Save rules', 'wirecore-disable-rest'),
   	'primary',
   	'submit',
   	true,
   	['disabled' => 'disabled']
   ); ?>

			<?php self::advanced_dialog(); ?>
		</form>
		<?php
	}

	/**
	 * The "Advanced" dialog, one for all routes. Its fields have no name; the
	 * JS copies the values into data-access of the respective button.
	 */
	private static function advanced_dialog()
	{
		$roles = self::role_names(); ?>
		<dialog id="wirecore-dr-advanced" class="wirecore-dr-dialog" aria-labelledby="wirecore-dr-advanced-title">
			<h2 id="wirecore-dr-advanced-title"><?php esc_html_e(
   	'Advanced:',
   	'wirecore-disable-rest'
   ); ?> <code class="wirecore-dr-advanced-target"></code></h2>
			<p class="description"><?php esc_html_e(
   	'Applies to reading and writing. Denials take precedence over allowances and also apply to administrators. Allowances grant access regardless of the rule. For a route, the entries of its namespace apply as well.',
   	'wirecore-disable-rest'
   ); ?></p>
			<div class="wirecore-dr-dialog-columns">
				<fieldset>
					<legend><?php esc_html_e('Allow', 'wirecore-disable-rest'); ?></legend>
					<label for="wirecore-dr-adv-ip-allow"><?php esc_html_e(
     	'IP addresses / CIDR ranges',
     	'wirecore-disable-rest'
     ); ?></label>
					<textarea id="wirecore-dr-adv-ip-allow" class="code" rows="3" data-list="ip_allow" placeholder="203.0.113.5&#10;10.0.0.0/8"></textarea>
					<label for="wirecore-dr-adv-users-allow"><?php esc_html_e(
     	'User IDs',
     	'wirecore-disable-rest'
     ); ?></label>
					<input type="text" id="wirecore-dr-adv-users-allow" class="code" data-list="users_allow" placeholder="12, 34" />
					<p class="wirecore-dr-dialog-label"><?php esc_html_e(
     	'Roles',
     	'wirecore-disable-rest'
     ); ?></p>
					<?php foreach ($roles as $role => $name): ?>
						<label class="wirecore-dr-role"><input type="checkbox" data-list="roles_allow" value="<?php echo esc_attr(
      	$role
      ); ?>" /> <?php echo esc_html($name); ?></label>
					<?php endforeach; ?>
				</fieldset>
				<fieldset>
					<legend><?php esc_html_e('Deny', 'wirecore-disable-rest'); ?></legend>
					<label for="wirecore-dr-adv-ip-deny"><?php esc_html_e(
     	'IP addresses / CIDR ranges',
     	'wirecore-disable-rest'
     ); ?></label>
					<textarea id="wirecore-dr-adv-ip-deny" class="code" rows="3" data-list="ip_deny"></textarea>
					<p class="wirecore-dr-dialog-label"><?php esc_html_e(
     	'Roles',
     	'wirecore-disable-rest'
     ); ?></p>
					<?php foreach ($roles as $role => $name): ?>
						<label class="wirecore-dr-role"><input type="checkbox" data-list="roles_deny" value="<?php echo esc_attr(
      	$role
      ); ?>" /> <?php echo esc_html($name); ?></label>
					<?php endforeach; ?>
				</fieldset>
			</div>
			<p class="wirecore-dr-dialog-actions">
				<button type="button" class="button button-primary" data-dialog="apply"><?php esc_html_e(
    	'Apply',
    	'wirecore-disable-rest'
    ); ?></button>
				<button type="button" class="button" data-dialog="cancel"><?php esc_html_e(
    	'Cancel',
    	'wirecore-disable-rest'
    ); ?></button>
				<span class="description"><?php esc_html_e(
    	'Changes are saved with “Save rules”.',
    	'wirecore-disable-rest'
    ); ?></span>
			</p>
		</dialog>
		<?php
	}

	/**
	 * "Advanced" button with the exceptions as JSON and room for the summary
	 * that the JS shows next to it.
	 */
	private static function advanced_button($target, array $access)
	{
		?>
		<span class="wirecore-dr-advanced-wrap">
			<button type="button" class="button button-small wirecore-dr-advanced-open" data-target="<?php echo esc_attr(
   	$target
   ); ?>" data-access="<?php echo esc_attr(wp_json_encode((object) $access)); ?>"><?php esc_html_e('Advanced', 'wirecore-disable-rest'); ?></button>
			<span class="wirecore-dr-access-summary"></span>
		</span>
		<?php
	}

	private static function routes_body()
	{
		$settings = WireCore_DR_Settings::get();
		$namespaces = WireCore_DR_Routes::all();
		$auto = WireCore_DR_Settings::auto_allowed_namespaces();

		$total = 0;
		$open = 0;
		$sources = [];
		foreach ($namespaces as $namespace => $data) {
			foreach ($data['routes'] as $route => $info) {
				$total++;
				$sources[$info['source']] = true;
				if (
					($info['read'] &&
						WireCore_DR_Guard::rule($route, $namespace, 'GET') ===
							'public') ||
					($info['write'] &&
						WireCore_DR_Guard::rule($route, $namespace, 'POST') ===
							'public')
				) {
					$open++;
				}
			}
		}
		ksort($sources);

		// Rules for routes/namespaces that are currently not registered
		// (plugin deactivated, route registered conditionally) are kept
		$inherit = ['read' => 'inherit', 'write' => 'inherit'];
		$known_routes = [];
		foreach ($namespaces as $data) {
			$known_routes += $data['routes'];
		}
		$orphans = [];
		foreach ($settings['namespaces'] as $namespace => $rules) {
			if (!isset($namespaces[$namespace])) {
				$orphans[$namespace] = ['namespace', $rules];
			}
		}
		foreach ($settings['routes'] as $route => $rules) {
			if (!isset($known_routes[$route])) {
				$orphans[$route] = ['route', $rules];
			}
		}
		foreach (array_keys($settings['access']) as $target) {
			$is_route = strpos($target, '/') === 0;
			$known = $is_route
				? isset($known_routes[$target])
				: isset($namespaces[$target]);
			if (!$known && !isset($orphans[$target])) {
				$orphans[$target] = [
					$is_route ? 'route' : 'namespace',
					$inherit,
				];
			}
		}
		?>
		<div class="wirecore-dr-status">
			<p><?php echo esc_html(
   	sprintf(
   		/* translators: 1: number of routes, 2: number of namespaces, 3: routes reachable for guests */
   		__(
   			'%1$d routes registered in %2$d namespaces, %3$d of them reachable for logged-out visitors (without whitelists).',
   			'wirecore-disable-rest'
   		),
   		$total,
   		count($namespaces),
   		$open
   	)
   ); ?></p>
		</div>

		<div class="wirecore-dr-toolbar">
			<input type="search" id="wirecore-dr-search" placeholder="<?php esc_attr_e(
   	'Search route or namespace …',
   	'wirecore-disable-rest'
   ); ?>" />
			<select id="wirecore-dr-source" aria-label="<?php esc_attr_e(
   	'Source',
   	'wirecore-disable-rest'
   ); ?>">
				<option value=""><?php esc_html_e(
    	'All sources',
    	'wirecore-disable-rest'
    ); ?></option>
				<?php foreach (array_keys($sources) as $source): ?>
					<option value="<?php echo esc_attr($source); ?>"><?php echo esc_html(
	$source
); ?></option>
				<?php endforeach; ?>
			</select>
			<select id="wirecore-dr-filter" aria-label="<?php esc_attr_e(
   	'Status',
   	'wirecore-disable-rest'
   ); ?>">
				<option value=""><?php esc_html_e(
    	'All routes',
    	'wirecore-disable-rest'
    ); ?></option>
				<option value="override"><?php esc_html_e(
    	'With custom rule',
    	'wirecore-disable-rest'
    ); ?></option>
				<option value="public"><?php esc_html_e(
    	'Reachable for guests',
    	'wirecore-disable-rest'
    ); ?></option>
				<option value="restricted"><?php esc_html_e(
    	'Blocked for guests',
    	'wirecore-disable-rest'
    ); ?></option>
			</select>
			<button type="button" class="button" id="wirecore-dr-expand"><?php esc_html_e(
   	'Expand all',
   	'wirecore-disable-rest'
   ); ?></button>
		</div>

		<?php foreach ($namespaces as $namespace => $data):
  	$rules = $settings['namespaces'][$namespace] ?? $inherit; ?>
			<div class="wirecore-dr-namespace" data-namespace="<?php echo esc_attr(
   	$namespace
   ); ?>">
				<div class="wirecore-dr-namespace-head">
					<button type="button" class="wirecore-dr-toggle" aria-expanded="false">
						<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
						<code><?php echo esc_html(
      	$namespace !== ''
      		? $namespace
      		: __('(no namespace)', 'wirecore-disable-rest')
      ); ?></code>
					</button>
					<span class="wirecore-dr-meta"><?php echo esc_html(
     	$data['source']
     ); ?> · <?php echo esc_html(
 	sprintf(
 		/* translators: %d: number of routes */
 		_n(
 			'%d route',
 			'%d routes',
 			count($data['routes']),
 			'wirecore-disable-rest'
 		),
 		count($data['routes'])
 	)
 ); ?></span>
					<?php if (in_array($namespace, $auto, true)): ?>
						<span class="wirecore-dr-badge wirecore-dr-badge-public"><?php esc_html_e(
      	'Automatically allowed',
      	'wirecore-disable-rest'
      ); ?></span>
					<?php endif; ?>
					<?php self::advanced_button(
     	$namespace,
     	$settings['access'][$namespace] ?? []
     ); ?>
					<span class="wirecore-dr-selects">
						<label><?php esc_html_e('Read', 'wirecore-disable-rest'); ?>
							<?php self::rule_select(
       	'namespace',
       	$namespace,
       	'read',
       	$rules['read']
       ); ?></label>
						<label><?php esc_html_e('Write', 'wirecore-disable-rest'); ?>
							<?php self::rule_select(
       	'namespace',
       	$namespace,
       	'write',
       	$rules['write']
       ); ?></label>
					</span>
				</div>
				<table class="widefat striped wirecore-dr-routes" hidden>
					<thead>
						<tr>
							<th><?php esc_html_e('Route', 'wirecore-disable-rest'); ?></th>
							<th><?php esc_html_e('Methods', 'wirecore-disable-rest'); ?></th>
							<th><?php esc_html_e('Source', 'wirecore-disable-rest'); ?></th>
							<th><?php esc_html_e('Read', 'wirecore-disable-rest'); ?></th>
							<th><?php esc_html_e('Write', 'wirecore-disable-rest'); ?></th>
							<th><?php esc_html_e('Effective', 'wirecore-disable-rest'); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($data['routes'] as $route => $info):
      	$rules = $settings['routes'][$route] ?? $inherit; ?>
							<tr class="wirecore-dr-route" data-route="<?php echo esc_attr(
       	$route
       ); ?>" data-namespace="<?php echo esc_attr(
	$namespace
); ?>" data-source="<?php echo esc_attr($info['source']); ?>">
								<td>
									<code><?php echo esc_html($route); ?></code>
									<?php self::advanced_button($route, $settings['access'][$route] ?? []); ?>
								</td>
								<td><?php echo esc_html(implode(', ', $info['methods'])); ?></td>
								<td><?php echo esc_html($info['source']); ?></td>
								<td><?php self::rule_select(
        	'route',
        	$route,
        	'read',
        	$info['read'] ? $rules['read'] : 'inherit',
        	!$info['read']
        ); ?></td>
								<td><?php self::rule_select(
        	'route',
        	$route,
        	'write',
        	$info['write'] ? $rules['write'] : 'inherit',
        	!$info['write']
        ); ?></td>
								<td class="wirecore-dr-effective"></td>
							</tr>
						<?php
      endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php
  endforeach; ?>

		<?php if ($orphans): ?>
			<h2><?php esc_html_e(
   	'Rules without a registered route',
   	'wirecore-disable-rest'
   ); ?></h2>
			<p class="description"><?php esc_html_e(
   	'Rules for namespaces or routes that are currently not registered, e.g. because a plugin is deactivated. They are kept until they are set to “Inherit” and nothing is entered under “Advanced”.',
   	'wirecore-disable-rest'
   ); ?></p>
			<table class="widefat striped wirecore-dr-orphans">
				<thead>
					<tr>
						<th><?php esc_html_e('Namespace / route', 'wirecore-disable-rest'); ?></th>
						<th><?php esc_html_e('Read', 'wirecore-disable-rest'); ?></th>
						<th><?php esc_html_e('Write', 'wirecore-disable-rest'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($orphans as $key => $orphan):
     	list($scope, $rules) = $orphan; ?>
						<tr>
							<td>
								<code><?php echo esc_html($key); ?></code>
								<?php self::advanced_button($key, $settings['access'][$key] ?? []); ?>
							</td>
							<td><?php self::rule_select($scope, $key, 'read', $rules['read']); ?></td>
							<td><?php self::rule_select($scope, $key, 'write', $rules['write']); ?></td>
						</tr>
					<?php
     endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	private static function tab_settings()
	{
		$settings = WireCore_DR_Settings::get();
		$flags = [
			'admin_bypass' => [
				__('Administrators', 'wirecore-disable-rest'),
				__(
					'Never block administrators (recommended – the block editor needs the REST API)',
					'wirecore-disable-rest'
				),
			],
			'require_ssl' => [
				__('HTTPS', 'wirecore-disable-rest'),
				__(
					'Allow the REST API over HTTPS only',
					'wirecore-disable-rest'
				),
			],
			'disable_jsonp' => [
				__('JSONP', 'wirecore-disable-rest'),
				__(
					'Disable JSONP responses (?_jsonp=)',
					'wirecore-disable-rest'
				),
			],
			'remove_links' => [
				__('REST links', 'wirecore-disable-rest'),
				__(
					'Remove REST links from HTTP headers, HTML head and RSD',
					'wirecore-disable-rest'
				),
			],
			'hide_index' => [
				__('Index', 'wirecore-disable-rest'),
				__(
					'Hide blocked routes and namespaces in the index (/wp-json/)',
					'wirecore-disable-rest'
				),
			],
		];
		?>
		<form method="post" action="<?php echo esc_url(
  	admin_url('admin-post.php')
  ); ?>">
			<input type="hidden" name="action" value="wirecore_dr_settings" />
			<?php wp_nonce_field('wirecore_dr_settings'); ?>

			<h2><?php esc_html_e('Access', 'wirecore-disable-rest'); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e(
     	'Roles for “Logged-in only”',
     	'wirecore-disable-rest'
     ); ?></th>
					<td>
						<fieldset>
							<?php foreach (wp_roles()->get_names() as $role => $name): ?>
								<label><input type="checkbox" name="allowed_roles[]" value="<?php echo esc_attr(
        	$role
        ); ?>"<?php checked(
	in_array($role, $settings['allowed_roles'], true)
); ?> /> <?php echo esc_html(translate_user_role($name)); ?></label><br />
							<?php endforeach; ?>
						</fieldset>
						<p class="description"><?php esc_html_e(
      	'No selection = all logged-in users.',
      	'wirecore-disable-rest'
      ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wirecore-dr-user-whitelist"><?php esc_html_e(
     	'Allowed user IDs',
     	'wirecore-disable-rest'
     ); ?></label></th>
					<td>
						<textarea id="wirecore-dr-user-whitelist" name="user_whitelist" rows="3" class="regular-text code"><?php echo esc_textarea(
      	implode("\n", $settings['user_whitelist'])
      ); ?></textarea>
						<p class="description"><?php esc_html_e(
      	'These users are never blocked. One ID per line.',
      	'wirecore-disable-rest'
      ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wirecore-dr-ip-whitelist"><?php esc_html_e(
     	'Allowed IP addresses',
     	'wirecore-disable-rest'
     ); ?></label></th>
					<td>
						<textarea id="wirecore-dr-ip-whitelist" name="ip_whitelist" rows="4" class="regular-text code"><?php echo esc_textarea(
      	implode("\n", $settings['ip_whitelist'])
      ); ?></textarea>
						<p class="description"><?php echo esc_html(
      	sprintf(
      		/* translators: %s: current IP address */
      		__(
      			'Applies to all routes. One IP or CIDR range per line, e.g. 203.0.113.5 or 10.0.0.0/8. Allowances for individual routes are available in the route list under “Advanced”. Your current IP: %s',
      			'wirecore-disable-rest'
      		),
      		WireCore_DR_Guard::client_ip()
      	)
      ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wirecore-dr-ip-blacklist"><?php esc_html_e(
     	'Blocked IP addresses',
     	'wirecore-disable-rest'
     ); ?></label></th>
					<td>
						<textarea id="wirecore-dr-ip-blacklist" name="ip_blacklist" rows="4" class="regular-text code"><?php echo esc_textarea(
      	implode("\n", $settings['ip_blacklist'])
      ); ?></textarea>
						<p class="description"><?php esc_html_e(
      	'These IPs get no access to any route – not even as administrator or through an allowance. One IP or CIDR range per line.',
      	'wirecore-disable-rest'
      ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e('Automatic allowances', 'wirecore-disable-rest'); ?></h2>
			<p class="description"><?php esc_html_e(
   	'These plugins need the REST API for visitors on the front end (forms, cart, statistics). Their namespaces are public as long as no custom rule is set for the namespace or the route in the route list. The setting has no effect for plugins that are not installed.',
   	'wirecore-disable-rest'
   ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e('Allow', 'wirecore-disable-rest'); ?></th>
					<td>
						<fieldset>
							<?php foreach (WireCore_DR_Settings::known_namespaces() as $key => $known): ?>
								<label><input type="checkbox" name="auto_allow[]" value="<?php echo esc_attr(
        	$key
        ); ?>"<?php checked(
	in_array($key, $settings['auto_allow'], true)
); ?> /> <?php echo esc_html(
 	self::known_label($key)
 ); ?> <code><?php echo esc_html(
 	implode(', ', (array) $known)
 ); ?></code></label><br />
							<?php endforeach; ?>
						</fieldset>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e('Error response', 'wirecore-disable-rest'); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wirecore-dr-error-message"><?php esc_html_e(
     	'Error message',
     	'wirecore-disable-rest'
     ); ?></label></th>
					<td><input type="text" id="wirecore-dr-error-message" name="error_message" class="large-text" value="<?php echo esc_attr(
     	$settings['error_message']
     ); ?>" placeholder="<?php esc_attr_e(
	'Access to the REST API is restricted.',
	'wirecore-disable-rest'
); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="wirecore-dr-error-status"><?php esc_html_e(
     	'Status code',
     	'wirecore-disable-rest'
     ); ?></label></th>
					<td>
						<select id="wirecore-dr-error-status" name="error_status">
							<option value="401"<?php selected(
       	$settings['error_status'],
       	401
       ); ?>><?php esc_html_e(
	'401 – Unauthorized',
	'wirecore-disable-rest'
); ?></option>
							<option value="403"<?php selected(
       	$settings['error_status'],
       	403
       ); ?>><?php esc_html_e(
	'403 – Forbidden',
	'wirecore-disable-rest'
); ?></option>
							<option value="404"<?php selected(
       	$settings['error_status'],
       	404
       ); ?>><?php esc_html_e(
	'404 – Not found (route appears not to exist)',
	'wirecore-disable-rest'
); ?></option>
						</select>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e('Hardening', 'wirecore-disable-rest'); ?></h2>
			<table class="form-table" role="presentation">
				<?php foreach ($flags as $flag => $labels): ?>
					<tr>
						<th scope="row"><?php echo esc_html($labels[0]); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr(
      	$flag
      ); ?>" value="1"<?php checked(
	$settings[$flag]
); ?> /> <?php echo esc_html($labels[1]); ?></label></td>
					</tr>
				<?php endforeach; ?>
			</table>

			<h2><?php esc_html_e('Log', 'wirecore-disable-rest'); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e('Logging', 'wirecore-disable-rest'); ?></th>
					<td><label><input type="checkbox" name="log_enabled" value="1"<?php checked(
     	$settings['log_enabled']
     ); ?> /> <?php echo esc_html(
 	sprintf(
 		/* translators: %d: number of log entries */
 		__('Log blocked requests (last %d)', 'wirecore-disable-rest'),
 		WireCore_DR_Log::LIMIT
 	)
 ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="wirecore-dr-log-ip"><?php esc_html_e(
     	'IP address in log',
     	'wirecore-disable-rest'
     ); ?></label></th>
					<td>
						<select id="wirecore-dr-log-ip" name="log_ip">
							<option value="full"<?php selected(
       	$settings['log_ip'],
       	'full'
       ); ?>><?php esc_html_e(
	'Store in full',
	'wirecore-disable-rest'
); ?></option>
							<option value="anonymized"<?php selected(
       	$settings['log_ip'],
       	'anonymized'
       ); ?>><?php esc_html_e(
	'Store shortened (e.g. 203.0.113.0)',
	'wirecore-disable-rest'
); ?></option>
							<option value="none"<?php selected(
       	$settings['log_ip'],
       	'none'
       ); ?>><?php esc_html_e(
	'Do not store',
	'wirecore-disable-rest'
); ?></option>
						</select>
						<p class="description"><?php esc_html_e(
      	'Applies to new entries. Existing entries remain unchanged until the log is cleared.',
      	'wirecore-disable-rest'
      ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	private static function tab_log()
	{
		$log = WireCore_DR_Log::get();
		$labels = WireCore_DR_Settings::rule_labels();
		$labels['ssl'] = __('No HTTPS', 'wirecore-disable-rest');
		$labels['denied'] = __('Deny list', 'wirecore-disable-rest');
		$format = get_option('date_format') . ' ' . get_option('time_format');
		?>
		<?php if (!WireCore_DR_Settings::get()['log_enabled']): ?>
			<div class="notice notice-info inline">
				<p><?php esc_html_e(
    	'The log is disabled. It can be enabled under “Settings”.',
    	'wirecore-disable-rest'
    ); ?></p>
			</div>
		<?php endif; ?>

		<p><?php echo esc_html(
  	sprintf(
  		/* translators: %d: number of blocked requests */
  		__('Blocked requests in total: %d', 'wirecore-disable-rest'),
  		$log['count']
  	)
  ); ?></p>

		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e('Time', 'wirecore-disable-rest'); ?></th>
					<th><?php esc_html_e('Method', 'wirecore-disable-rest'); ?></th>
					<th><?php esc_html_e('Route', 'wirecore-disable-rest'); ?></th>
					<th><?php esc_html_e('IP', 'wirecore-disable-rest'); ?></th>
					<th><?php esc_html_e('User ID', 'wirecore-disable-rest'); ?></th>
					<th><?php esc_html_e('Rule', 'wirecore-disable-rest'); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if (!$log['entries']): ?>
					<tr><td colspan="6"><?php esc_html_e(
     	'No entries.',
     	'wirecore-disable-rest'
     ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ($log['entries'] as $entry): ?>
					<tr>
						<td><?php echo esc_html(wp_date($format, $entry['time'])); ?></td>
						<td><?php echo esc_html($entry['method']); ?></td>
						<td><code><?php echo esc_html($entry['route']); ?></code></td>
						<td><?php echo $entry['ip'] !== '' ? esc_html($entry['ip']) : '–'; ?></td>
						<td><?php echo $entry['user'] ? esc_html($entry['user']) : '–'; ?></td>
						<td><?php echo esc_html($labels[$entry['reason']] ?? $entry['reason']); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<form method="post" action="<?php echo esc_url(
  	admin_url('admin-post.php')
  ); ?>">
			<input type="hidden" name="action" value="wirecore_dr_log_clear" />
			<?php wp_nonce_field('wirecore_dr_log_clear'); ?>
			<?php submit_button(__('Clear log', 'wirecore-disable-rest'), 'secondary'); ?>
		</form>
		<?php
	}

	private static function tab_tools()
	{
		// Core filter, used the same way for the loopback requests of Site Health.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$sslverify = apply_filters('https_local_ssl_verify', false);
		$selftest =
			isset($_GET['selftest'], $_GET['_wpnonce']) &&
			wp_verify_nonce(
				sanitize_key(wp_unslash($_GET['_wpnonce'])),
				'wirecore_dr_selftest'
			);
		?>
		<h2><?php esc_html_e('Self-test', 'wirecore-disable-rest'); ?></h2>
		<p><?php esc_html_e(
  	'Calls the REST API without authentication and shows what logged-out visitors see.',
  	'wirecore-disable-rest'
  ); ?></p>
		<p><a class="button" href="<?php echo esc_url(
  	wp_nonce_url(
  		add_query_arg('selftest', 1, self::tab_url('tools')),
  		'wirecore_dr_selftest'
  	)
  ); ?>"><?php esc_html_e('Run test', 'wirecore-disable-rest'); ?></a></p>
		<?php if ($selftest): ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e('URL', 'wirecore-disable-rest'); ?></th>
						<th><?php esc_html_e('Status', 'wirecore-disable-rest'); ?></th>
						<th><?php esc_html_e('Response', 'wirecore-disable-rest'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach (
     	[rest_url(), rest_url('wp/v2/users'), rest_url('wp/v2/posts')]
     	as $url
     ):
     	$response = wp_remote_get($url, [
     		'timeout' => 10,
     		'sslverify' => $sslverify,
     	]); ?>
						<tr>
							<td><code><?php echo esc_html($url); ?></code></td>
							<?php if (is_wp_error($response)): ?>
								<td>–</td>
								<td><?php echo esc_html($response->get_error_message()); ?></td>
							<?php else: ?>
								<td><?php echo esc_html(wp_remote_retrieve_response_code($response)); ?></td>
								<td><code><?php echo esc_html(
        	mb_strimwidth(wp_remote_retrieve_body($response), 0, 200, '…')
        ); ?></code></td>
							<?php endif; ?>
						</tr>
					<?php
     endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<h2><?php esc_html_e('Export', 'wirecore-disable-rest'); ?></h2>
		<form method="post" action="<?php echo esc_url(
  	admin_url('admin-post.php')
  ); ?>">
			<input type="hidden" name="action" value="wirecore_dr_export" />
			<?php wp_nonce_field('wirecore_dr_export'); ?>
			<?php submit_button(
   	__('Export settings', 'wirecore-disable-rest'),
   	'secondary',
   	'submit',
   	false
   ); ?>
		</form>

		<h2><?php esc_html_e('Import', 'wirecore-disable-rest'); ?></h2>
		<p><?php esc_html_e(
  	'Replaces all current settings with those from the file.',
  	'wirecore-disable-rest'
  ); ?></p>
		<form method="post" action="<?php echo esc_url(
  	admin_url('admin-post.php')
  ); ?>" enctype="multipart/form-data">
			<input type="hidden" name="action" value="wirecore_dr_import" />
			<?php wp_nonce_field('wirecore_dr_import'); ?>
			<input type="file" name="import_file" accept=".json,application/json" required />
			<?php submit_button(
   	__('Import settings', 'wirecore-disable-rest'),
   	'secondary',
   	'submit',
   	false
   ); ?>
		</form>

		<h2><?php esc_html_e('Emergency switch', 'wirecore-disable-rest'); ?></h2>
		<p><?php esc_html_e(
  	'If a rule locks you out of the admin area, this line in wp-config.php disables all rules:',
  	'wirecore-disable-rest'
  ); ?></p>
		<p><code>define('WIRECORE_DR_OFF', true);</code></p>

		<h2><?php esc_html_e('For developers', 'wirecore-disable-rest'); ?></h2>
		<ul class="ul-disc">
			<li><?php echo wp_kses(
   	__(
   		'<code>wirecore_dr_error</code> – filters the WP_Error of a blocked request (parameters: error, route pattern, method). If the filter returns <code>true</code>, the request is allowed after all.',
   		'wirecore-disable-rest'
   	),
   	['code' => []]
   ); ?></li>
			<li><?php echo wp_kses(
   	__(
   		'<code>wirecore_dr_client_ip</code> – filters the client IP, e.g. behind a proxy.',
   		'wirecore-disable-rest'
   	),
   	['code' => []]
   ); ?></li>
			<li><?php echo wp_kses(
   	__(
   		'<code>wirecore_dr_known_namespaces</code> – filters the plugins under “Automatic allowances” (array <code>key =&gt; [namespaces]</code>). New entries appear there as a checkbox and can be enabled.',
   		'wirecore-disable-rest'
   	),
   	['code' => []]
   ); ?></li>
			<li><?php echo wp_kses(
   	__(
   		'WP-CLI: <code>wp wirecore-dr</code> (status, routes, set, default, export, import, log).',
   		'wirecore-disable-rest'
   	),
   	['code' => []]
   ); ?></li>
		</ul>
		<?php
	}
}
