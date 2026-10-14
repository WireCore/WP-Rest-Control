<?php
/**
 * Enforcement on real REST requests dispatched through the REST server.
 */
class Test_WireCore_DR_Rest extends WireCore_DR_TestCase
{
	public function test_guests_are_blocked_by_default()
	{
		$this->assertBlocked($this->request('GET', '/wp/v2/users'));
		$this->assertBlocked($this->request('GET', '/wcdr-test/v1/item'));
	}

	public function test_public_namespace_is_reachable_for_guests()
	{
		$this->settings([
			'namespaces' => [
				'wcdr-test/v1' => ['read' => 'public', 'write' => 'inherit'],
			],
		]);

		$response = $this->request('GET', '/wcdr-test/v1/item');
		$this->assertSame(200, $response->get_status());
		$this->assertBlocked(
			$this->request('POST', '/wcdr-test/v1/item', ['title' => 'x'])
		);
	}

	public function test_automatic_allowance_applies_to_requests()
	{
		$response = $this->request('POST', '/contact-form-7/v1/feedback');
		$this->assertSame(200, $response->get_status());
	}

	/**
	 * Regression: WordPress validates parameters before the plugin runs. A
	 * blocked visitor must not learn which parameters a route expects.
	 */
	public function test_invalid_parameters_do_not_leak_for_blocked_routes()
	{
		$this->assertBlocked($this->request('POST', '/wcdr-test/v1/item'));

		wp_set_current_user($this->user('administrator')->ID);
		$response = $this->request('POST', '/wcdr-test/v1/item');
		$this->assertSame(400, $response->get_status());
		$this->assertSame(
			'rest_missing_callback_param',
			$response->get_data()['code']
		);
	}

	public function test_batch_sub_requests_are_checked_individually()
	{
		$this->settings([
			'routes' => [
				'/batch/v1' => ['read' => 'inherit', 'write' => 'public'],
			],
		]);

		$response = $this->request('POST', '/batch/v1', [
			'requests' => [
				[
					'method' => 'POST',
					'path' => '/wcdr-test/v1/item',
					'body' => ['title' => 'x'],
				],
			],
		]);

		$this->assertSame(207, $response->get_status());
		$sub = $response->get_data()['responses'][0];
		$this->assertSame(401, $sub['status']);
		$this->assertSame('rest_restricted', $sub['body']['code']);
	}

	public function test_index_hides_namespaces_the_visitor_cannot_use()
	{
		$this->settings([
			'routes' => ['/' => ['read' => 'public', 'write' => 'inherit']],
			'namespaces' => [
				'wcdr-test/v1' => ['read' => 'public', 'write' => 'inherit'],
			],
		]);

		$data = $this->request('GET', '/')->get_data();

		$this->assertContains('wcdr-test/v1', $data['namespaces']);
		$this->assertContains('contact-form-7/v1', $data['namespaces']);
		$this->assertNotContains('wp/v2', $data['namespaces']);
		$this->assertArrayNotHasKey('/wp/v2/users', $data['routes']);
	}

	public function test_route_list_for_the_settings_page_is_never_blocked_for_admins()
	{
		$this->settings([
			'admin_bypass' => false,
			'default' => ['read' => 'blocked', 'write' => 'blocked'],
		]);
		$_GET[WireCore_DR_Admin::ROUTES_PARAM] = '1';

		wp_set_current_user($this->user('administrator')->ID);
		$response = $this->request('GET', '/');
		$this->assertSame(200, $response->get_status());
		$this->assertStringContainsString(
			'wirecore-dr-namespace',
			$response->get_data()[WireCore_DR_Admin::ROUTES_PARAM]
		);

		wp_set_current_user($this->user('editor')->ID);
		$this->assertBlocked($this->request('GET', '/'));
	}

	public function test_log_stores_the_ip_as_configured()
	{
		$expected = [
			'full' => self::IP,
			'anonymized' => '203.0.113.0',
			'none' => '',
		];
		foreach ($expected as $mode => $ip) {
			WireCore_DR_Log::clear();
			$this->settings(['log_enabled' => true, 'log_ip' => $mode]);

			$this->request('GET', '/wp/v2/users');

			$entry = WireCore_DR_Log::get()['entries'][0];
			$this->assertSame($ip, $entry['ip'], $mode);
			$this->assertSame('/wp/v2/users', $entry['route']);
			$this->assertSame('auth', $entry['reason']);
		}
	}

	public function test_log_records_deny_list_as_reason_and_stays_off_by_default()
	{
		$this->request('GET', '/wp/v2/users');
		$this->assertSame(0, WireCore_DR_Log::get()['count']);

		$this->settings(['log_enabled' => true, 'ip_blacklist' => [self::IP]]);
		$this->request('GET', '/wp/v2/users');
		$this->assertSame(
			'denied',
			WireCore_DR_Log::get()['entries'][0]['reason']
		);
	}
}
