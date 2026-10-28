<?php
/**
 * WireCore_RC_Settings: defaults, sanitizing, export and import.
 */
class Test_WireCore_RC_Settings extends WireCore_RC_TestCase
{
	public function test_defaults_apply_without_stored_option()
	{
		$settings = WireCore_RC_Settings::get();

		$this->assertSame(
			['read' => 'auth', 'write' => 'auth'],
			$settings['default']
		);
		$this->assertTrue($settings['admin_bypass']);
		$this->assertSame(401, $settings['error_status']);
		$this->assertSame('anonymized', $settings['log_ip']);
		$this->assertSame(
			array_keys(WireCore_RC_Settings::known_namespaces()),
			$settings['auto_allow']
		);
	}

	public function test_default_rule_rejects_inherit_and_unknown_values()
	{
		$settings = WireCore_RC_Settings::sanitize([
			'default' => ['read' => 'inherit', 'write' => 'nonsense'],
		]);

		$this->assertSame(
			['read' => 'auth', 'write' => 'auth'],
			$settings['default']
		);
	}

	public function test_overrides_keep_route_patterns_and_drop_inherit_only_entries()
	{
		$route = '/wp/v2/users/(?P<id>[\d]+)';
		$settings = WireCore_RC_Settings::sanitize([
			'routes' => [
				$route => ['read' => 'admin', 'write' => 'bogus'],
				'/wp/v2/posts' => ['read' => 'inherit', 'write' => 'inherit'],
				"  \x01" => ['read' => 'public'],
			],
			'namespaces' => ['wp/v2' => 'not an array'],
		]);

		$this->assertSame(
			[$route => ['read' => 'admin', 'write' => 'inherit']],
			$settings['routes']
		);
		$this->assertSame([], $settings['namespaces']);
	}

	public function test_ip_lists_accept_addresses_and_valid_cidr_ranges_only()
	{
		$settings = WireCore_RC_Settings::sanitize([
			'ip_whitelist' => [
				' 203.0.113.5 ',
				'10.0.0.0/8',
				'2001:db8::/32',
				'10.0.0.0/33',
				'::1/129',
				'1.2.3.4/x',
				'nonsense',
				'10.0.0.0/8',
			],
			'ip_blacklist' => ['192.0.2.1', '999.1.1.1'],
		]);

		$this->assertSame(
			['203.0.113.5', '10.0.0.0/8', '2001:db8::/32'],
			$settings['ip_whitelist']
		);
		$this->assertSame(['192.0.2.1'], $settings['ip_blacklist']);
	}

	public function test_roles_and_user_ids_are_validated()
	{
		$settings = WireCore_RC_Settings::sanitize([
			'allowed_roles' => ['editor', 'no-such-role'],
			'user_whitelist' => ['3', '0', 'abc', '3'],
		]);

		$this->assertSame(['editor'], $settings['allowed_roles']);
		$this->assertSame([3], $settings['user_whitelist']);
	}

	public function test_access_lists_are_sanitized_and_empty_entries_removed()
	{
		$settings = WireCore_RC_Settings::sanitize([
			'access' => [
				'wp/v2' => [
					'ip_allow' => ['10.0.0.0/8', 'bad'],
					'roles_deny' => ['subscriber', 'ghost'],
					'users_allow' => ['7', 'x'],
				],
				'/wp/v2/posts' => ['ip_deny' => ['bad']],
			],
		]);

		$this->assertSame(['wp/v2'], array_keys($settings['access']));
		$this->assertSame(
			[
				'ip_allow' => ['10.0.0.0/8'],
				'ip_deny' => [],
				'roles_allow' => [],
				'roles_deny' => ['subscriber'],
				'users_allow' => [7],
			],
			$settings['access']['wp/v2']
		);
	}

	public function test_auto_allow_drops_unknown_keys_and_empty_list_means_none()
	{
		$this->assertSame(
			['oembed'],
			WireCore_RC_Settings::sanitize([
				'auto_allow' => ['oembed', 'nope'],
			])['auto_allow']
		);
		$this->assertSame(
			[],
			WireCore_RC_Settings::sanitize(['auto_allow' => []])['auto_allow']
		);
	}

	public function test_invalid_status_and_log_mode_fall_back_to_defaults()
	{
		$settings = WireCore_RC_Settings::sanitize([
			'error_status' => 500,
			'log_ip' => 'everything',
		]);

		$this->assertSame(401, $settings['error_status']);
		$this->assertSame('anonymized', $settings['log_ip']);
	}

	public function test_export_and_import_round_trip()
	{
		$this->settings([
			'default' => ['read' => 'blocked', 'write' => 'admin'],
			'access' => ['wp/v2' => ['ip_deny' => ['192.0.2.0/24']]],
		]);
		$json = wp_json_encode(WireCore_RC_Settings::export());
		$expected = WireCore_RC_Settings::get();

		delete_option(WireCore_RC_Settings::OPTION);
		self::reset_settings_cache();

		$this->assertTrue(WireCore_RC_Settings::import($json));
		$this->assertSame($expected, WireCore_RC_Settings::get());
	}

	public function test_import_rejects_files_of_other_plugins()
	{
		$result = WireCore_RC_Settings::import(
			wp_json_encode(['plugin' => 'other', 'settings' => []])
		);

		$this->assertWPError($result);
		$this->assertWPError(WireCore_RC_Settings::import('not json'));
	}
}
