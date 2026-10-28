<?php
/**
 * WireCore_RC_Guard::decide() and ::rule(): rule precedence, rule levels,
 * allow lists, deny lists and the error response.
 */
class Test_WireCore_RC_Guard extends WireCore_RC_TestCase
{
	const ROUTE = '/wp/v2/posts';
	const NS = 'wp/v2';

	private function decide(
		WP_User $user,
		$method = 'GET',
		$ip = self::IP,
		$route = self::ROUTE,
		$namespace = self::NS
	) {
		return WireCore_RC_Guard::decide(
			$route,
			$namespace,
			$method,
			$user,
			$ip
		);
	}

	public function test_most_specific_rule_wins()
	{
		$this->settings([
			'default' => ['read' => 'auth', 'write' => 'auth'],
			'namespaces' => [
				self::NS => ['read' => 'public', 'write' => 'inherit'],
			],
			'routes' => [
				self::ROUTE => ['read' => 'blocked', 'write' => 'inherit'],
			],
		]);

		$this->assertSame(
			'blocked',
			WireCore_RC_Guard::rule(self::ROUTE, self::NS, 'GET')
		);
		$this->assertSame(
			'public',
			WireCore_RC_Guard::rule('/wp/v2/pages', self::NS, 'GET')
		);
		$this->assertSame(
			'auth',
			WireCore_RC_Guard::rule(self::ROUTE, self::NS, 'POST')
		);
		$this->assertSame(
			'auth',
			WireCore_RC_Guard::rule('/other', 'other/v1', 'GET')
		);
	}

	public function test_read_and_write_rules_are_separate()
	{
		$this->settings([
			'routes' => [
				self::ROUTE => ['read' => 'public', 'write' => 'blocked'],
			],
		]);
		$guest = $this->guest();

		$this->assertTrue($this->decide($guest, 'GET'));
		$this->assertTrue($this->decide($guest, 'HEAD'));
		foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
			$this->assertWPError($this->decide($guest, $method), $method);
		}
	}

	public function test_known_frontend_namespaces_are_public_unless_overridden()
	{
		$route = '/contact-form-7/v1/feedback';
		$namespace = 'contact-form-7/v1';
		$this->assertSame(
			'public',
			WireCore_RC_Guard::rule($route, $namespace, 'POST')
		);

		$this->settings([
			'namespaces' => [
				$namespace => ['read' => 'inherit', 'write' => 'blocked'],
			],
		]);
		$this->assertSame(
			'blocked',
			WireCore_RC_Guard::rule($route, $namespace, 'POST')
		);

		$this->settings(['namespaces' => [], 'auto_allow' => []]);
		$this->assertSame(
			'auth',
			WireCore_RC_Guard::rule($route, $namespace, 'POST')
		);
	}

	public function test_auth_rule_requires_login_and_respects_allowed_roles()
	{
		$editor = $this->user('editor');

		$this->assertWPError($this->decide($this->guest()));
		$this->assertTrue($this->decide($editor));

		$this->settings(['allowed_roles' => ['author']]);
		$this->assertWPError($this->decide($editor));
		$this->assertTrue($this->decide($this->user('author')));
	}

	public function test_admin_and_blocked_rules()
	{
		$this->settings([
			'admin_bypass' => false,
			'routes' => [
				self::ROUTE => ['read' => 'admin', 'write' => 'blocked'],
			],
		]);
		$admin = $this->user('administrator');

		$this->assertTrue($this->decide($admin, 'GET'));
		$this->assertWPError($this->decide($this->user('editor'), 'GET'));
		$this->assertWPError($this->decide($admin, 'POST'));
	}

	public function test_admin_bypass_can_be_disabled()
	{
		$this->settings([
			'default' => ['read' => 'blocked', 'write' => 'blocked'],
		]);
		$admin = $this->user('administrator');

		$this->assertTrue($this->decide($admin));

		$this->settings(['admin_bypass' => false]);
		$this->assertWPError($this->decide($admin));
	}

	public function test_global_allow_lists()
	{
		$this->settings([
			'default' => ['read' => 'blocked', 'write' => 'blocked'],
		]);
		$editor = $this->user('editor');

		$this->settings(['user_whitelist' => [$editor->ID]]);
		$this->assertTrue($this->decide($editor));

		$this->settings([
			'ip_whitelist' => ['203.0.113.0/24', '2001:db8::/32'],
		]);
		$this->assertTrue($this->decide($this->guest(), 'GET', '203.0.113.99'));
		$this->assertTrue($this->decide($this->guest(), 'GET', '2001:db8::1'));
		$this->assertWPError(
			$this->decide($this->guest(), 'GET', '203.0.114.1')
		);
		$this->assertWPError(
			$this->decide($this->guest(), 'GET', '2001:db9::1')
		);
	}

	public function test_cidr_ranges_with_partial_bytes()
	{
		$this->settings([
			'default' => ['read' => 'blocked', 'write' => 'blocked'],
			'ip_whitelist' => ['192.0.2.128/25', '10.1.2.3'],
		]);
		$guest = $this->guest();

		$this->assertTrue($this->decide($guest, 'GET', '192.0.2.200'));
		$this->assertWPError($this->decide($guest, 'GET', '192.0.2.127'));
		$this->assertTrue($this->decide($guest, 'GET', '10.1.2.3'));
		$this->assertWPError($this->decide($guest, 'GET', '10.1.2.4'));
		$this->assertWPError($this->decide($guest, 'GET', ''));
	}

	public function test_advanced_allowances_of_route_and_namespace()
	{
		$this->settings([
			'default' => ['read' => 'blocked', 'write' => 'blocked'],
		]);
		$author = $this->user('author');
		$editor = $this->user('editor');

		$this->settings([
			'access' => [
				self::NS => ['ip_allow' => ['198.51.100.0/24']],
				self::ROUTE => [
					'users_allow' => [$editor->ID],
					'roles_allow' => ['author'],
				],
			],
		]);

		$this->assertTrue(
			$this->decide($this->guest(), 'POST', '198.51.100.7')
		);
		$this->assertTrue($this->decide($editor));
		$this->assertTrue($this->decide($author));
		$this->assertWPError(
			$this->decide($author, 'GET', self::IP, '/wp/v2/pages')
		);
		$this->assertTrue(
			$this->decide($this->guest(), 'GET', '198.51.100.7', '/wp/v2/pages')
		);
	}

	public function test_deny_lists_win_over_everything()
	{
		$this->settings([
			'default' => ['read' => 'public', 'write' => 'public'],
		]);
		$admin = $this->user('administrator');
		$subscriber = $this->user('subscriber');

		$this->settings(['ip_blacklist' => ['203.0.113.0/24']]);
		$this->assertWPError($this->decide($admin));

		$this->settings([
			'ip_blacklist' => [],
			'access' => [
				self::NS => ['roles_deny' => ['subscriber']],
				self::ROUTE => [
					'ip_deny' => ['192.0.2.1'],
					'ip_allow' => ['192.0.2.1'],
				],
			],
		]);
		$this->assertWPError($this->decide($subscriber));
		$this->assertWPError($this->decide($this->guest(), 'GET', '192.0.2.1'));
		$this->assertTrue($this->decide($this->guest(), 'GET', '192.0.2.2'));
	}

	public function test_https_is_required_when_enabled()
	{
		$this->settings([
			'default' => ['read' => 'public', 'write' => 'public'],
			'require_ssl' => true,
		]);

		$error = $this->decide($this->guest());
		$this->assertWPError($error);
		$this->assertSame('rest_ssl_required', $error->get_error_code());
		$this->assertSame(403, $error->get_error_data()['status']);

		$_SERVER['HTTPS'] = 'on';
		$this->assertTrue($this->decide($this->guest()));
	}

	public function test_error_response_uses_status_and_message_from_settings()
	{
		$error = $this->decide($this->guest());
		$this->assertSame('rest_restricted', $error->get_error_code());
		$this->assertSame(401, $error->get_error_data()['status']);

		$this->settings(['error_status' => 404, 'error_message' => 'Nope']);
		$error = $this->decide($this->guest());
		$this->assertSame('rest_no_route', $error->get_error_code());
		$this->assertSame('Nope', $error->get_error_message());
		$this->assertSame(404, $error->get_error_data()['status']);
	}

	public function test_error_filter_can_allow_a_request()
	{
		add_filter(
			'wirecore_rc_error',
			function ($error, $route) {
				return $route === self::ROUTE ? true : $error;
			},
			10,
			2
		);

		$this->assertTrue($this->decide($this->guest()));
		$this->assertWPError(
			$this->decide($this->guest(), 'GET', self::IP, '/wp/v2/pages')
		);
	}
}
