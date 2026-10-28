<?php
/**
 * Bootstrap for the PHPUnit integration tests.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

putenv('WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php');
$wirecore_rc_tests_dir = getenv('WP_PHPUNIT__DIR');

require $wirecore_rc_tests_dir . '/includes/functions.php';

tests_add_filter('muplugins_loaded', function () {
	require dirname(__DIR__) . '/wirecore-rest-control.php';
});

// Routes used by the tests. They are registered before the first REST server
// is created, because WireCore_RC_Routes caches the route list per request.
tests_add_filter('rest_api_init', function () {
	register_rest_route('wcdr-test/v1', '/item', [
		[
			'methods' => 'GET',
			'callback' => function () {
				return ['ok' => true];
			},
			'permission_callback' => '__return_true',
		],
		[
			'methods' => 'POST',
			'callback' => function () {
				return ['created' => true];
			},
			'permission_callback' => '__return_true',
			'args' => [
				'title' => ['type' => 'string', 'required' => true],
			],
		],
	]);
	// Stands in for Contact Form 7 to test the automatic allowances.
	register_rest_route('contact-form-7/v1', '/feedback', [
		'methods' => 'POST',
		'callback' => function () {
			return ['sent' => true];
		},
		'permission_callback' => '__return_true',
	]);
});

require $wirecore_rc_tests_dir . '/includes/bootstrap.php';

// The rules are only enforced for real REST requests; the tests dispatch
// requests directly to the REST server.
define('REST_REQUEST', true);

require __DIR__ . '/class-wirecore-rc-testcase.php';
