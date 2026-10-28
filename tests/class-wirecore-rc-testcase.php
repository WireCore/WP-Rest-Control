<?php
/**
 * Base class for the plugin tests: resets the settings between tests and
 * offers helpers for settings, users and REST requests.
 */
abstract class WireCore_RC_TestCase extends WP_UnitTestCase
{
	const IP = '203.0.113.10';

	public function set_up()
	{
		parent::set_up();
		self::reset_settings_cache();
		delete_option(WireCore_RC_Settings::OPTION);
		delete_option(WireCore_RC_Log::OPTION);
		self::reset_settings_cache();
		$_SERVER['REMOTE_ADDR'] = self::IP;
		unset($_SERVER['HTTPS'], $_GET[WireCore_RC_Admin::ROUTES_PARAM]);
		wp_set_current_user(0);
	}

	public function tear_down()
	{
		self::reset_settings_cache();
		parent::tear_down();
	}

	/**
	 * The options table is rolled back after every test, the static cache of
	 * the settings class is not.
	 */
	protected static function reset_settings_cache()
	{
		$property = new ReflectionProperty(
			WireCore_RC_Settings::class,
			'cache'
		);
		// Needed before PHP 8.1, deprecated since PHP 8.5
		if (PHP_VERSION_ID < 80100) {
			$property->setAccessible(true);
		}
		$property->setValue(null, null);
	}

	protected function settings(array $changes)
	{
		WireCore_RC_Settings::save(
			array_merge(WireCore_RC_Settings::get(), $changes)
		);
	}

	protected function user($role)
	{
		return self::factory()->user->create_and_get(['role' => $role]);
	}

	protected function guest()
	{
		return new WP_User(0);
	}

	/**
	 * @return WP_REST_Response
	 */
	protected function request($method, $route, array $params = [])
	{
		$request = new WP_REST_Request($method, $route);
		foreach ($params as $key => $value) {
			$request->set_param($key, $value);
		}
		return rest_get_server()->dispatch($request);
	}

	protected function assertBlocked($response, $status = 401)
	{
		$this->assertSame($status, $response->get_status());
		$this->assertSame('rest_restricted', $response->get_data()['code']);
	}
}
