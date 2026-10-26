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
		// WireLayer components, scoped to #wirecore-dr (npm run build:css)
		wp_enqueue_style(
			'wirecore-dr-wirelayer',
			plugins_url('assets/wirelayer/wirelayer.css', WIRECORE_DR_FILE),
			[],
			WIRECORE_DR_VERSION
		);
		wp_enqueue_style(
			'wirecore-dr-admin',
			plugins_url('assets/admin.css', WIRECORE_DR_FILE),
			['wirecore-dr-wirelayer'],
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
			// _locale=user: the route list in the language of the admin, not of the site
			'url' => add_query_arg(
				[
					self::ROUTES_PARAM => 1,
					'_fields' => self::ROUTES_PARAM,
					'_locale' => 'user',
				],
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
				'warn',
				__(
					'Saved. Invalid IP entries were discarded.',
					'wirecore-disable-rest'
				),
			],
			'no_js' => [
				'danger',
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
				'danger',
				__(
					'Import failed. Please choose an export file of this plugin.',
					'wirecore-disable-rest'
				),
			],
		];
		?>
		<div class="wrap wirecore-dr">
			<?php // WordPress moves the notices of other plugins here, outside the WireLayer area
		?>
			<hr class="wp-header-end" />
			<div id="wirecore-dr" data-mode="light">
				<div class="wc-page-header">
					<div class="wc-page-header-row">
						<div class="wc-page-header-heading">
							<h1 class="wc-page-header-title"><?php echo esc_html(
       	get_admin_page_title()
       ); ?></h1>
						</div>
					</div>
					<nav class="wc-tabs-list wc-page-header-tabs" aria-label="<?php echo esc_attr(
     	get_admin_page_title()
     ); ?>">
						<?php foreach ($tabs as $key => $label): ?>
							<a href="<?php echo esc_url(
       	self::tab_url($key)
       ); ?>" class="wc-tabs-trigger"<?php echo $key === $tab
	? ' data-selected aria-current="page"'
	: ''; ?>><?php echo esc_html($label); ?></a>
						<?php endforeach; ?>
					</nav>
				</div>

				<?php if (defined('WIRECORE_DR_OFF') && WIRECORE_DR_OFF) {
    	self::alert(
    		'warn',
    		__(
    			'WIRECORE_DR_OFF is set in wp-config.php: no rules are enforced.',
    			'wirecore-disable-rest'
    		)
    	);
    } ?>

				<?php if (isset($notices[$notice])) {
    	self::alert($notices[$notice][0], $notices[$notice][1]);
    } ?>

				<?php
    $method = 'tab_' . $tab;
    self::$method();?>
			</div>
		</div>
		<?php
	}

	/**
	 * Inline Lucide icon (ISC license), hidden from assistive technology.
	 */
	private static function icon($name, $class)
	{
		$paths = [
			'check' => '<path d="M20 6 9 17l-5-5"/>',
			'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
			'chevron-right' => '<path d="m9 18 6-6-6-6"/>',
			'loader' => '<path d="M21 12a9 9 0 1 1-6.219-8.56"/>',
			'info' =>
				'<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
			'success' =>
				'<circle cx="12" cy="12" r="10"/><path d="m16 9-5.5 5.5L8 12"/>',
			'warn' =>
				'<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
			'danger' =>
				'<path d="m15 9-6 6"/><path d="M2.586 16.726A2 2 0 0 1 2 15.312V8.688a2 2 0 0 1 .586-1.414l4.688-4.688A2 2 0 0 1 8.688 2h6.624a2 2 0 0 1 1.414.586l4.688 4.688A2 2 0 0 1 22 8.688v6.624a2 2 0 0 1-.586 1.414l-4.688 4.688a2 2 0 0 1-1.414.586H8.688a2 2 0 0 1-1.414-.586z"/><path d="m9 9 6 6"/>',
		];
		printf(
			'<svg class="%s" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">%s</svg>',
			esc_attr($class),
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG markup from the list above
			$paths[$name]
		);
	}

	/**
	 * Status message; $status is info, success, warn or danger.
	 */
	private static function alert($status, $text)
	{
		?>
		<div class="wc-alert wc-alert-<?php echo esc_attr(
  	$status
  ); ?>" role="<?php echo in_array($status, ['warn', 'danger'], true) ? 'alert' : 'status'; ?>">
			<span class="wc-alert-icon-box"><?php self::icon(
   	$status,
   	'wc-alert-icon'
   ); ?></span>
			<div class="wc-alert-content">
				<div class="wc-alert-description"><?php echo esc_html($text); ?></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Title and optional description at the top of a card.
	 */
	private static function card_header($title, $subtitle = '')
	{
		?>
		<div class="wc-card-parts-header">
			<div class="wc-card-parts-heading">
				<h2 class="wc-card-parts-title"><?php echo esc_html($title); ?></h2>
				<?php if ($subtitle !== ''): ?>
					<p class="wc-card-parts-subtitle"><?php echo esc_html($subtitle); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Checkbox with a native input; $attributes are the input's attributes
	 * (name, value, data-list …), $title an optional bold line above the label,
	 * $code an optional code snippet after it.
	 */
	private static function checkbox(
		array $attributes,
		$checked,
		$label,
		$title = '',
		$code = ''
	) {
		?>
		<label class="wc-checkbox">
			<input type="checkbox" class="wc-checkbox-input"<?php
   foreach ($attributes as $attribute => $value) {
   	printf(' %s="%s"', esc_attr($attribute), esc_attr($value));
   }
   checked($checked);
   ?> />
			<span class="wc-checkbox-control"><?php self::icon(
   	'check',
   	'wc-checkbox-icon'
   ); ?></span>
			<span class="wc-checkbox-label">
				<?php if ($title !== ''): ?>
					<span class="wirecore-dr-checkbox-title"><?php echo esc_html($title); ?></span>
				<?php endif; ?>
				<?php echo esc_html($label); ?>
				<?php if ($code !== ''): ?>
					<code class="wc-inline-code"><?php echo esc_html($code); ?></code>
				<?php endif; ?>
			</span>
		</label>
		<?php
	}

	/**
	 * Text with <code> from a translation, the code styled by WireLayer.
	 */
	private static function code_text($text)
	{
		echo wp_kses(
			str_replace('<code>', '<code class="wc-inline-code">', $text),
			['code' => ['class' => []]]
		);
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
		<span class="wc-native-select wc-native-select-sm wirecore-dr-rule">
			<select class="wc-native-select-select" data-scope="<?php echo esc_attr(
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
			<?php self::icon('chevron-down', 'wc-native-select-icon'); ?>
		</span>
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
  ); ?>" id="wirecore-dr-routes" class="wirecore-dr-stack">
			<input type="hidden" name="action" value="wirecore_dr_routes" />
			<input type="hidden" name="rules_json" value="" />
			<?php wp_nonce_field('wirecore_dr_routes'); ?>

			<section class="wc-card">
				<?php self::card_header(
    	__('Default rule', 'wirecore-disable-rest'),
    	__(
    		'Applies to all routes without a custom rule. “Read” covers GET/HEAD, “Write” covers POST/PUT/PATCH/DELETE. The most specific rule wins: route before namespace before automatic allowance before default.',
    		'wirecore-disable-rest'
    	)
    ); ?>
				<div class="wirecore-dr-row">
					<label class="wirecore-dr-labeled"><?php esc_html_e(
     	'Read',
     	'wirecore-disable-rest'
     ); ?>
						<?php self::rule_select(
      	'default',
      	'',
      	'read',
      	$settings['default']['read']
      ); ?></label>
					<label class="wirecore-dr-labeled"><?php esc_html_e(
     	'Write',
     	'wirecore-disable-rest'
     ); ?>
						<?php self::rule_select(
      	'default',
      	'',
      	'write',
      	$settings['default']['write']
      ); ?></label>
				</div>
			</section>

			<section class="wc-card">
				<?php self::card_header(
    	__('Quick actions', 'wirecore-disable-rest'),
    	__('Changes take effect only after saving.', 'wirecore-disable-rest')
    ); ?>
				<div class="wirecore-dr-row">
					<button type="button" class="wc-button wc-button-outline wc-button-sm" data-preset="guests"><?php esc_html_e(
     	'Block everything for guests',
     	'wirecore-disable-rest'
     ); ?></button>
					<button type="button" class="wc-button wc-button-outline wc-button-sm" data-preset="all"><?php esc_html_e(
     	'Block everything',
     	'wirecore-disable-rest'
     ); ?></button>
					<button type="button" class="wc-button wc-button-outline wc-button-sm" data-preset="users"><?php esc_html_e(
     	'User list (/wp/v2/users) for admins only',
     	'wirecore-disable-rest'
     ); ?></button>
					<button type="button" class="wc-button wc-button-outline wc-button-sm" data-preset="reset"><?php esc_html_e(
     	'Reset custom rules',
     	'wirecore-disable-rest'
     ); ?></button>
				</div>
			</section>

			<section class="wirecore-dr-stack">
				<?php self::card_header(
    	__('Routes', 'wirecore-disable-rest'),
    	__(
    		'Use “Advanced” to additionally allow or deny IP addresses, roles and users for a namespace or route. The entries of a namespace apply to all of its routes.',
    		'wirecore-disable-rest'
    	)
    ); ?>
				<div id="wirecore-dr-routes-body" class="wirecore-dr-stack wirecore-dr-namespaces">
					<span class="wc-spinner" role="status">
						<?php self::icon('loader', 'wc-spinner-icon'); ?>
						<span class="wc-spinner-label"><?php esc_html_e(
      	'Loading routes …',
      	'wirecore-disable-rest'
      ); ?></span>
					</span>
				</div>
			</section>

			<div class="wirecore-dr-row">
				<button type="submit" class="wc-button" disabled><?php esc_html_e(
    	'Save rules',
    	'wirecore-disable-rest'
    ); ?></button>
			</div>

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
		<dialog id="wirecore-dr-advanced" class="wc-dialog wc-dialog-xl" aria-labelledby="wirecore-dr-advanced-title">
			<div class="wc-dialog-content">
				<div class="wc-dialog-body">
					<h2 class="wc-dialog-title" id="wirecore-dr-advanced-title"><?php esc_html_e(
     	'Advanced:',
     	'wirecore-disable-rest'
     ); ?> <code class="wc-inline-code wirecore-dr-advanced-target"></code></h2>
					<p class="wc-dialog-description"><?php esc_html_e(
     	'Applies to reading and writing. Denials take precedence over allowances and also apply to administrators. Allowances grant access regardless of the rule. For a route, the entries of its namespace apply as well.',
     	'wirecore-disable-rest'
     ); ?></p>
					<div class="wirecore-dr-columns">
						<fieldset class="wc-fieldset">
							<legend class="wc-fieldset-legend"><?php esc_html_e(
       	'Allow',
       	'wirecore-disable-rest'
       ); ?></legend>
							<div class="wc-field">
								<label class="wc-field-label" for="wirecore-dr-adv-ip-allow"><?php esc_html_e(
        	'IP addresses / CIDR ranges',
        	'wirecore-disable-rest'
        ); ?></label>
								<textarea id="wirecore-dr-adv-ip-allow" class="wc-textarea" rows="3" data-list="ip_allow" placeholder="203.0.113.5&#10;10.0.0.0/8"></textarea>
							</div>
							<div class="wc-field">
								<label class="wc-field-label" for="wirecore-dr-adv-users-allow"><?php esc_html_e(
        	'User IDs',
        	'wirecore-disable-rest'
        ); ?></label>
								<div class="wc-input">
									<input type="text" id="wirecore-dr-adv-users-allow" class="wc-input-control" data-list="users_allow" placeholder="12, 34" />
								</div>
							</div>
							<fieldset class="wc-checkbox-group">
								<legend class="wc-checkbox-group-label"><?php esc_html_e(
        	'Roles',
        	'wirecore-disable-rest'
        ); ?></legend>
								<div class="wc-checkbox-group-items">
									<?php foreach ($roles as $role => $name) {
         	self::checkbox(
         		['data-list' => 'roles_allow', 'value' => $role],
         		false,
         		$name
         	);
         } ?>
								</div>
							</fieldset>
						</fieldset>
						<fieldset class="wc-fieldset">
							<legend class="wc-fieldset-legend"><?php esc_html_e(
       	'Deny',
       	'wirecore-disable-rest'
       ); ?></legend>
							<div class="wc-field">
								<label class="wc-field-label" for="wirecore-dr-adv-ip-deny"><?php esc_html_e(
        	'IP addresses / CIDR ranges',
        	'wirecore-disable-rest'
        ); ?></label>
								<textarea id="wirecore-dr-adv-ip-deny" class="wc-textarea" rows="3" data-list="ip_deny"></textarea>
							</div>
							<fieldset class="wc-checkbox-group">
								<legend class="wc-checkbox-group-label"><?php esc_html_e(
        	'Roles',
        	'wirecore-disable-rest'
        ); ?></legend>
								<div class="wc-checkbox-group-items">
									<?php foreach ($roles as $role => $name) {
         	self::checkbox(
         		['data-list' => 'roles_deny', 'value' => $role],
         		false,
         		$name
         	);
         } ?>
								</div>
							</fieldset>
						</fieldset>
					</div>
				</div>
				<div class="wc-dialog-footer">
					<span class="wirecore-dr-hint"><?php esc_html_e(
     	'Changes are saved with “Save rules”.',
     	'wirecore-disable-rest'
     ); ?></span>
					<button type="button" class="wc-button wc-button-outline" data-dialog="cancel"><?php esc_html_e(
     	'Cancel',
     	'wirecore-disable-rest'
     ); ?></button>
					<button type="button" class="wc-button" data-dialog="apply"><?php esc_html_e(
     	'Apply',
     	'wirecore-disable-rest'
     ); ?></button>
				</div>
			</div>
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
			<button type="button" class="wc-button wc-button-outline wc-button-xs wirecore-dr-advanced-open" data-target="<?php echo esc_attr(
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
		<?php self::alert(
  	'info',
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
  ); ?>

		<div class="wirecore-dr-row">
			<div class="wc-input wc-input-sm wirecore-dr-search">
				<input type="search" class="wc-input-control" id="wirecore-dr-search" placeholder="<?php esc_attr_e(
    	'Search route or namespace …',
    	'wirecore-disable-rest'
    ); ?>" aria-label="<?php esc_attr_e(
	'Search route or namespace …',
	'wirecore-disable-rest'
); ?>" />
			</div>
			<span class="wc-native-select wc-native-select-sm wirecore-dr-filter">
				<select class="wc-native-select-select" id="wirecore-dr-source" aria-label="<?php esc_attr_e(
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
				<?php self::icon('chevron-down', 'wc-native-select-icon'); ?>
			</span>
			<span class="wc-native-select wc-native-select-sm wirecore-dr-filter">
				<select class="wc-native-select-select" id="wirecore-dr-filter" aria-label="<?php esc_attr_e(
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
				<?php self::icon('chevron-down', 'wc-native-select-icon'); ?>
			</span>
			<button type="button" class="wc-button wc-button-outline wc-button-sm" id="wirecore-dr-expand"><?php esc_html_e(
   	'Expand all',
   	'wirecore-disable-rest'
   ); ?></button>
		</div>

		<?php foreach ($namespaces as $namespace => $data):
  	$rules = $settings['namespaces'][$namespace] ?? $inherit; ?>
			<div class="wc-card wirecore-dr-namespace" data-namespace="<?php echo esc_attr(
   	$namespace
   ); ?>">
				<div class="wirecore-dr-namespace-head">
					<button type="button" class="wirecore-dr-toggle" aria-expanded="false">
						<?php self::icon('chevron-right', 'wirecore-dr-toggle-icon'); ?>
						<code class="wc-inline-code"><?php echo esc_html(
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
						<span class="wc-badge wc-badge-success"><?php esc_html_e(
      	'Automatically allowed',
      	'wirecore-disable-rest'
      ); ?></span>
					<?php endif; ?>
					<?php self::advanced_button(
     	$namespace,
     	$settings['access'][$namespace] ?? []
     ); ?>
					<span class="wirecore-dr-selects">
						<label class="wirecore-dr-labeled"><?php esc_html_e(
      	'Read',
      	'wirecore-disable-rest'
      ); ?>
							<?php self::rule_select(
       	'namespace',
       	$namespace,
       	'read',
       	$rules['read']
       ); ?></label>
						<label class="wirecore-dr-labeled"><?php esc_html_e(
      	'Write',
      	'wirecore-disable-rest'
      ); ?>
							<?php self::rule_select(
       	'namespace',
       	$namespace,
       	'write',
       	$rules['write']
       ); ?></label>
					</span>
				</div>
				<div class="wc-data-table-scroll wirecore-dr-routes" hidden>
					<table class="wc-data-table-table">
						<thead>
							<tr class="wc-data-table-head-row">
								<th scope="col" class="wc-data-table-th"><?php esc_html_e(
        	'Route',
        	'wirecore-disable-rest'
        ); ?></th>
								<th scope="col" class="wc-data-table-th"><?php esc_html_e(
        	'Methods',
        	'wirecore-disable-rest'
        ); ?></th>
								<th scope="col" class="wc-data-table-th"><?php esc_html_e(
        	'Source',
        	'wirecore-disable-rest'
        ); ?></th>
								<th scope="col" class="wc-data-table-th"><?php esc_html_e(
        	'Read',
        	'wirecore-disable-rest'
        ); ?></th>
								<th scope="col" class="wc-data-table-th"><?php esc_html_e(
        	'Write',
        	'wirecore-disable-rest'
        ); ?></th>
								<th scope="col" class="wc-data-table-th"><?php esc_html_e(
        	'Effective',
        	'wirecore-disable-rest'
        ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($data['routes'] as $route => $info):
       	$rules = $settings['routes'][$route] ?? $inherit; ?>
								<tr class="wc-data-table-row wirecore-dr-route" data-route="<?php echo esc_attr(
        	$route
        ); ?>" data-namespace="<?php echo esc_attr(
	$namespace
); ?>" data-source="<?php echo esc_attr($info['source']); ?>">
									<td class="wc-data-table-td">
										<code class="wc-inline-code"><?php echo esc_html($route); ?></code>
										<?php self::advanced_button($route, $settings['access'][$route] ?? []); ?>
									</td>
									<td class="wc-data-table-td"><?php echo esc_html(
         	implode(', ', $info['methods'])
         ); ?></td>
									<td class="wc-data-table-td"><?php echo esc_html($info['source']); ?></td>
									<td class="wc-data-table-td"><?php self::rule_select(
         	'route',
         	$route,
         	'read',
         	$info['read'] ? $rules['read'] : 'inherit',
         	!$info['read']
         ); ?></td>
									<td class="wc-data-table-td"><?php self::rule_select(
         	'route',
         	$route,
         	'write',
         	$info['write'] ? $rules['write'] : 'inherit',
         	!$info['write']
         ); ?></td>
									<td class="wc-data-table-td wirecore-dr-effective"></td>
								</tr>
							<?php
       endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
		<?php
  endforeach; ?>

		<?php if ($orphans): ?>
			<section class="wirecore-dr-stack">
				<?php self::card_header(
    	__('Rules without a registered route', 'wirecore-disable-rest'),
    	__(
    		'Rules for namespaces or routes that are currently not registered, e.g. because a plugin is deactivated. They are kept until they are set to “Inherit” and nothing is entered under “Advanced”.',
    		'wirecore-disable-rest'
    	)
    ); ?>
				<div class="wc-data-table-scroll">
					<table class="wc-data-table-table">
						<thead>
							<tr class="wc-data-table-head-row">
								<th scope="col" class="wc-data-table-th"><?php esc_html_e(
        	'Namespace / route',
        	'wirecore-disable-rest'
        ); ?></th>
								<th scope="col" class="wc-data-table-th"><?php esc_html_e(
        	'Read',
        	'wirecore-disable-rest'
        ); ?></th>
								<th scope="col" class="wc-data-table-th"><?php esc_html_e(
        	'Write',
        	'wirecore-disable-rest'
        ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($orphans as $key => $orphan):
       	list($scope, $rules) = $orphan; ?>
								<tr class="wc-data-table-row">
									<td class="wc-data-table-td">
										<code class="wc-inline-code"><?php echo esc_html($key); ?></code>
										<?php self::advanced_button($key, $settings['access'][$key] ?? []); ?>
									</td>
									<td class="wc-data-table-td"><?php self::rule_select(
         	$scope,
         	$key,
         	'read',
         	$rules['read']
         ); ?></td>
									<td class="wc-data-table-td"><?php self::rule_select(
         	$scope,
         	$key,
         	'write',
         	$rules['write']
         ); ?></td>
								</tr>
							<?php
       endforeach; ?>
						</tbody>
					</table>
				</div>
			</section>
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
  ); ?>" class="wirecore-dr-stack">
			<input type="hidden" name="action" value="wirecore_dr_settings" />
			<?php wp_nonce_field('wirecore_dr_settings'); ?>

			<section class="wc-card">
				<?php self::card_header(__('Access', 'wirecore-disable-rest')); ?>
				<fieldset class="wc-checkbox-group" aria-describedby="wirecore-dr-roles-help">
					<legend class="wc-checkbox-group-label"><?php esc_html_e(
     	'Roles for “Logged-in only”',
     	'wirecore-disable-rest'
     ); ?></legend>
					<div class="wc-checkbox-group-items">
						<?php foreach (wp_roles()->get_names() as $role => $name) {
      	self::checkbox(
      		['name' => 'allowed_roles[]', 'value' => $role],
      		in_array($role, $settings['allowed_roles'], true),
      		translate_user_role($name)
      	);
      } ?>
					</div>
					<span class="wc-checkbox-group-helper-text" id="wirecore-dr-roles-help"><?php esc_html_e(
     	'No selection = all logged-in users.',
     	'wirecore-disable-rest'
     ); ?></span>
				</fieldset>
				<div class="wc-field">
					<label class="wc-field-label" for="wirecore-dr-user-whitelist"><?php esc_html_e(
     	'Allowed user IDs',
     	'wirecore-disable-rest'
     ); ?></label>
					<textarea id="wirecore-dr-user-whitelist" name="user_whitelist" rows="3" class="wc-textarea" aria-describedby="wirecore-dr-user-whitelist-help"><?php echo esc_textarea(
     	implode("\n", $settings['user_whitelist'])
     ); ?></textarea>
					<span class="wc-field-helper-text" id="wirecore-dr-user-whitelist-help"><?php esc_html_e(
     	'These users are never blocked. One ID per line.',
     	'wirecore-disable-rest'
     ); ?></span>
				</div>
				<div class="wc-field">
					<label class="wc-field-label" for="wirecore-dr-ip-whitelist"><?php esc_html_e(
     	'Allowed IP addresses',
     	'wirecore-disable-rest'
     ); ?></label>
					<textarea id="wirecore-dr-ip-whitelist" name="ip_whitelist" rows="4" class="wc-textarea" aria-describedby="wirecore-dr-ip-whitelist-help"><?php echo esc_textarea(
     	implode("\n", $settings['ip_whitelist'])
     ); ?></textarea>
					<span class="wc-field-helper-text" id="wirecore-dr-ip-whitelist-help"><?php echo esc_html(
     	sprintf(
     		/* translators: %s: current IP address */
     		__(
     			'Applies to all routes. One IP or CIDR range per line, e.g. 203.0.113.5 or 10.0.0.0/8. Allowances for individual routes are available in the route list under “Advanced”. Your current IP: %s',
     			'wirecore-disable-rest'
     		),
     		WireCore_DR_Guard::client_ip()
     	)
     ); ?></span>
				</div>
				<div class="wc-field">
					<label class="wc-field-label" for="wirecore-dr-ip-blacklist"><?php esc_html_e(
     	'Blocked IP addresses',
     	'wirecore-disable-rest'
     ); ?></label>
					<textarea id="wirecore-dr-ip-blacklist" name="ip_blacklist" rows="4" class="wc-textarea" aria-describedby="wirecore-dr-ip-blacklist-help"><?php echo esc_textarea(
     	implode("\n", $settings['ip_blacklist'])
     ); ?></textarea>
					<span class="wc-field-helper-text" id="wirecore-dr-ip-blacklist-help"><?php esc_html_e(
     	'These IPs get no access to any route – not even as administrator or through an allowance. One IP or CIDR range per line.',
     	'wirecore-disable-rest'
     ); ?></span>
				</div>
			</section>

			<section class="wc-card">
				<?php self::card_header(
    	__('Automatic allowances', 'wirecore-disable-rest'),
    	__(
    		'These plugins need the REST API for visitors on the front end (forms, cart, statistics). Their namespaces are public as long as no custom rule is set for the namespace or the route in the route list. The setting has no effect for plugins that are not installed.',
    		'wirecore-disable-rest'
    	)
    ); ?>
				<fieldset class="wc-checkbox-group">
					<legend class="wc-checkbox-group-label"><?php esc_html_e(
     	'Allow',
     	'wirecore-disable-rest'
     ); ?></legend>
					<div class="wc-checkbox-group-items">
						<?php foreach (WireCore_DR_Settings::known_namespaces() as $key => $known) {
      	self::checkbox(
      		['name' => 'auto_allow[]', 'value' => $key],
      		in_array($key, $settings['auto_allow'], true),
      		self::known_label($key),
      		'',
      		implode(', ', (array) $known)
      	);
      } ?>
					</div>
				</fieldset>
			</section>

			<section class="wc-card">
				<?php self::card_header(__('Error response', 'wirecore-disable-rest')); ?>
				<div class="wc-field">
					<label class="wc-field-label" for="wirecore-dr-error-message"><?php esc_html_e(
     	'Error message',
     	'wirecore-disable-rest'
     ); ?></label>
					<div class="wc-input">
						<input type="text" id="wirecore-dr-error-message" name="error_message" class="wc-input-control" value="<?php echo esc_attr(
      	$settings['error_message']
      ); ?>" placeholder="<?php esc_attr_e(
	'Access to the REST API is restricted.',
	'wirecore-disable-rest'
); ?>" />
					</div>
				</div>
				<div class="wc-field wirecore-dr-narrow">
					<label class="wc-field-label" for="wirecore-dr-error-status"><?php esc_html_e(
     	'Status code',
     	'wirecore-disable-rest'
     ); ?></label>
					<span class="wc-native-select">
						<select class="wc-native-select-select" id="wirecore-dr-error-status" name="error_status">
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
						<?php self::icon('chevron-down', 'wc-native-select-icon'); ?>
					</span>
				</div>
			</section>

			<section class="wc-card">
				<?php self::card_header(__('Hardening', 'wirecore-disable-rest')); ?>
				<div class="wirecore-dr-stack">
					<?php foreach ($flags as $flag => $labels) {
     	self::checkbox(
     		['name' => $flag, 'value' => '1'],
     		$settings[$flag],
     		$labels[1],
     		$labels[0]
     	);
     } ?>
				</div>
			</section>

			<section class="wc-card">
				<?php self::card_header(__('Log', 'wirecore-disable-rest')); ?>
				<?php self::checkbox(
    	['name' => 'log_enabled', 'value' => '1'],
    	$settings['log_enabled'],
    	sprintf(
    		/* translators: %d: number of log entries */
    		__('Log blocked requests (last %d)', 'wirecore-disable-rest'),
    		WireCore_DR_Log::LIMIT
    	),
    	__('Logging', 'wirecore-disable-rest')
    ); ?>
				<div class="wc-field wirecore-dr-narrow">
					<label class="wc-field-label" for="wirecore-dr-log-ip"><?php esc_html_e(
     	'IP address in log',
     	'wirecore-disable-rest'
     ); ?></label>
					<span class="wc-native-select">
						<select class="wc-native-select-select" id="wirecore-dr-log-ip" name="log_ip" aria-describedby="wirecore-dr-log-ip-help">
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
						<?php self::icon('chevron-down', 'wc-native-select-icon'); ?>
					</span>
					<span class="wc-field-helper-text" id="wirecore-dr-log-ip-help"><?php esc_html_e(
     	'Applies to new entries. Existing entries remain unchanged until the log is cleared.',
     	'wirecore-disable-rest'
     ); ?></span>
				</div>
			</section>

			<div class="wirecore-dr-row">
				<button type="submit" class="wc-button"><?php esc_html_e(
    	'Save settings',
    	'wirecore-disable-rest'
    ); ?></button>
			</div>
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
		<?php if (!WireCore_DR_Settings::get()['log_enabled']) {
  	self::alert(
  		'info',
  		__(
  			'The log is disabled. It can be enabled under “Settings”.',
  			'wirecore-disable-rest'
  		)
  	);
  } ?>

		<section class="wc-card">
			<?php self::card_header(
   	__('Log', 'wirecore-disable-rest'),
   	sprintf(
   		/* translators: %d: number of blocked requests */
   		__('Blocked requests in total: %d', 'wirecore-disable-rest'),
   		$log['count']
   	)
   ); ?>
			<div class="wc-data-table-scroll">
				<table class="wc-data-table-table">
					<thead>
						<tr class="wc-data-table-head-row">
							<th scope="col" class="wc-data-table-th"><?php esc_html_e(
       	'Time',
       	'wirecore-disable-rest'
       ); ?></th>
							<th scope="col" class="wc-data-table-th"><?php esc_html_e(
       	'Method',
       	'wirecore-disable-rest'
       ); ?></th>
							<th scope="col" class="wc-data-table-th"><?php esc_html_e(
       	'Route',
       	'wirecore-disable-rest'
       ); ?></th>
							<th scope="col" class="wc-data-table-th"><?php esc_html_e(
       	'IP',
       	'wirecore-disable-rest'
       ); ?></th>
							<th scope="col" class="wc-data-table-th"><?php esc_html_e(
       	'User ID',
       	'wirecore-disable-rest'
       ); ?></th>
							<th scope="col" class="wc-data-table-th"><?php esc_html_e(
       	'Rule',
       	'wirecore-disable-rest'
       ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if (!$log['entries']): ?>
							<tr class="wc-data-table-row"><td class="wc-data-table-td" colspan="6"><?php esc_html_e(
       	'No entries.',
       	'wirecore-disable-rest'
       ); ?></td></tr>
						<?php endif; ?>
						<?php foreach ($log['entries'] as $entry): ?>
							<tr class="wc-data-table-row">
								<td class="wc-data-table-td"><?php echo esc_html(
        	wp_date($format, $entry['time'])
        ); ?></td>
								<td class="wc-data-table-td"><?php echo esc_html($entry['method']); ?></td>
								<td class="wc-data-table-td"><code class="wc-inline-code"><?php echo esc_html(
        	$entry['route']
        ); ?></code></td>
								<td class="wc-data-table-td"><?php echo $entry['ip'] !== ''
        	? esc_html($entry['ip'])
        	: '–'; ?></td>
								<td class="wc-data-table-td"><?php echo $entry['user']
        	? esc_html($entry['user'])
        	: '–'; ?></td>
								<td class="wc-data-table-td"><?php echo esc_html(
        	$labels[$entry['reason']] ?? $entry['reason']
        ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<form method="post" action="<?php echo esc_url(
   	admin_url('admin-post.php')
   ); ?>" class="wc-card-parts-footer">
				<input type="hidden" name="action" value="wirecore_dr_log_clear" />
				<?php wp_nonce_field('wirecore_dr_log_clear'); ?>
				<button type="submit" class="wc-button wc-button-outline wc-button-sm"><?php esc_html_e(
    	'Clear log',
    	'wirecore-disable-rest'
    ); ?></button>
			</form>
		</section>
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
		<section class="wc-card">
			<?php self::card_header(
   	__('Self-test', 'wirecore-disable-rest'),
   	__(
   		'Calls the REST API without authentication and shows what logged-out visitors see.',
   		'wirecore-disable-rest'
   	)
   ); ?>
			<?php if ($selftest): ?>
				<div class="wc-data-table-scroll">
					<table class="wc-data-table-table">
						<thead>
							<tr class="wc-data-table-head-row">
								<th scope="col" class="wc-data-table-th"><?php esc_html_e(
        	'URL',
        	'wirecore-disable-rest'
        ); ?></th>
								<th scope="col" class="wc-data-table-th"><?php esc_html_e(
        	'Status',
        	'wirecore-disable-rest'
        ); ?></th>
								<th scope="col" class="wc-data-table-th"><?php esc_html_e(
        	'Response',
        	'wirecore-disable-rest'
        ); ?></th>
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
								<tr class="wc-data-table-row">
									<td class="wc-data-table-td"><code class="wc-inline-code"><?php echo esc_html(
         	$url
         ); ?></code></td>
									<?php if (is_wp_error($response)): ?>
										<td class="wc-data-table-td">–</td>
										<td class="wc-data-table-td"><?php echo esc_html(
          	$response->get_error_message()
          ); ?></td>
									<?php else: ?>
										<td class="wc-data-table-td"><?php echo esc_html(
          	wp_remote_retrieve_response_code($response)
          ); ?></td>
										<td class="wc-data-table-td"><code class="wc-inline-code"><?php echo esc_html(
          	mb_strimwidth(wp_remote_retrieve_body($response), 0, 200, '…')
          ); ?></code></td>
									<?php endif; ?>
								</tr>
							<?php
       endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
			<div class="wc-card-parts-footer">
				<a class="wc-button wc-button-outline wc-button-sm" href="<?php echo esc_url(
    	wp_nonce_url(
    		add_query_arg('selftest', 1, self::tab_url('tools')),
    		'wirecore_dr_selftest'
    	)
    ); ?>"><?php esc_html_e('Run test', 'wirecore-disable-rest'); ?></a>
			</div>
		</section>

		<section class="wc-card">
			<?php self::card_header(__('Export', 'wirecore-disable-rest')); ?>
			<form method="post" action="<?php echo esc_url(
   	admin_url('admin-post.php')
   ); ?>" class="wc-card-parts-footer">
				<input type="hidden" name="action" value="wirecore_dr_export" />
				<?php wp_nonce_field('wirecore_dr_export'); ?>
				<button type="submit" class="wc-button wc-button-outline wc-button-sm"><?php esc_html_e(
    	'Export settings',
    	'wirecore-disable-rest'
    ); ?></button>
			</form>
		</section>

		<section class="wc-card">
			<?php self::card_header(
   	__('Import', 'wirecore-disable-rest'),
   	__(
   		'Replaces all current settings with those from the file.',
   		'wirecore-disable-rest'
   	)
   ); ?>
			<form method="post" action="<?php echo esc_url(
   	admin_url('admin-post.php')
   ); ?>" enctype="multipart/form-data" class="wc-card-parts-footer">
				<input type="hidden" name="action" value="wirecore_dr_import" />
				<?php wp_nonce_field('wirecore_dr_import'); ?>
				<input type="file" name="import_file" accept=".json,application/json" required aria-label="<?php esc_attr_e(
    	'Import',
    	'wirecore-disable-rest'
    ); ?>" />
				<button type="submit" class="wc-button wc-button-outline wc-button-sm"><?php esc_html_e(
    	'Import settings',
    	'wirecore-disable-rest'
    ); ?></button>
			</form>
		</section>

		<section class="wc-card">
			<?php self::card_header(
   	__('Emergency switch', 'wirecore-disable-rest'),
   	__(
   		'If a rule locks you out of the admin area, this line in wp-config.php disables all rules:',
   		'wirecore-disable-rest'
   	)
   ); ?>
			<p><code class="wc-inline-code">define('WIRECORE_DR_OFF', true);</code></p>
		</section>

		<section class="wc-card">
			<?php self::card_header(__('For developers', 'wirecore-disable-rest')); ?>
			<ul class="wirecore-dr-list">
				<li><?php self::code_text(
    	__(
    		'<code>wirecore_dr_error</code> – filters the WP_Error of a blocked request (parameters: error, route pattern, method). If the filter returns <code>true</code>, the request is allowed after all.',
    		'wirecore-disable-rest'
    	)
    ); ?></li>
				<li><?php self::code_text(
    	__(
    		'<code>wirecore_dr_client_ip</code> – filters the client IP, e.g. behind a proxy.',
    		'wirecore-disable-rest'
    	)
    ); ?></li>
				<li><?php self::code_text(
    	__(
    		'<code>wirecore_dr_known_namespaces</code> – filters the plugins under “Automatic allowances” (array <code>key =&gt; [namespaces]</code>). New entries appear there as a checkbox and can be enabled.',
    		'wirecore-disable-rest'
    	)
    ); ?></li>
				<li><?php self::code_text(
    	__(
    		'WP-CLI: <code>wp wirecore-dr</code> (status, routes, set, default, export, import, log).',
    		'wirecore-disable-rest'
    	)
    ); ?></li>
			</ul>
		</section>
		<?php
	}
}
