<?php
/**
 * WireCore_DR_Routes: route matching and the grouped route list.
 */
class Test_WireCore_DR_Routes extends WireCore_DR_TestCase
{
	public function test_match_returns_pattern_and_namespace()
	{
		$this->assertSame(
			['/wp/v2/users/(?P<id>[\d]+)', 'wp/v2'],
			WireCore_DR_Routes::match('/wp/v2/users/12', 'GET')
		);
		$this->assertSame(
			['/wcdr-test/v1/item', 'wcdr-test/v1'],
			WireCore_DR_Routes::match('/wcdr-test/v1/item', 'POST')
		);
	}

	public function test_match_answers_head_with_get_handlers_and_checks_methods()
	{
		$this->assertSame(
			['/wcdr-test/v1/item', 'wcdr-test/v1'],
			WireCore_DR_Routes::match('/wcdr-test/v1/item', 'HEAD')
		);
		$this->assertNull(
			WireCore_DR_Routes::match('/wcdr-test/v1/item', 'DELETE')
		);
		$this->assertNull(WireCore_DR_Routes::match('/no/such/route', 'GET'));
	}

	public function test_route_list_groups_routes_with_methods_and_sources()
	{
		$all = WireCore_DR_Routes::all();

		$this->assertSame('WordPress', $all['wp/v2']['source']);
		$this->assertSame(
			'WordPress',
			$all['wp/v2']['routes']['/wp/v2/posts']['source']
		);
		$this->assertTrue($all['wp/v2']['routes']['/wp/v2/posts']['read']);
		$this->assertTrue($all['wp/v2']['routes']['/wp/v2/posts']['write']);

		$item = $all['wcdr-test/v1']['routes']['/wcdr-test/v1/item'];
		$this->assertSame(['GET', 'POST'], $item['methods']);
		$this->assertFalse(
			$all['contact-form-7/v1']['routes']['/contact-form-7/v1/feedback'][
				'read'
			]
		);
	}

	public function test_namespace_index_route_inherits_the_namespace_source()
	{
		$all = WireCore_DR_Routes::all();

		// The test routes live outside the plugin directory of the test
		// installation, so their source is unknown – the index route that core
		// registers must show the same instead of "WordPress".
		$this->assertSame(
			$all['wcdr-test/v1']['source'],
			$all['wcdr-test/v1']['routes']['/wcdr-test/v1']['source']
		);
		$this->assertNotSame('WordPress', $all['wcdr-test/v1']['source']);
	}
}
