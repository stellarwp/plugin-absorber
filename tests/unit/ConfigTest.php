<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Unit;

use Codeception\TestCase\WPTestCase;
use Generator;
use Nexcess\PluginAbsorber\Config;
use Nexcess\PluginAbsorber\Exceptions\Config_Exception;
use Nexcess\PluginAbsorber\Tests\Support\Config_State;
use Nexcess\PluginAbsorber\Tests\Support\Test_Container;
use RuntimeException;

/**
 * @since 1.0.0
 */
class ConfigTest extends WPTestCase {
	public function setUp(): void {
		parent::setUp();
		Config_State::reset();
	}

	public function tearDown(): void {
		Config_State::reset();
		parent::tearDown();
	}

	/**
	 * @dataProvider valid_hook_prefixes
	 *
	 * @param string $prefix Prefix under test.
	 */
	public function test_it_stores_and_returns_a_valid_hook_prefix( string $prefix ): void {
		Config::set_hook_prefix( $prefix );

		$this->assertSame( $prefix, Config::get_hook_prefix() );
	}

	/**
	 * @return Generator<string,array{0:string}>
	 */
	public static function valid_hook_prefixes(): Generator {
		yield 'lowercase'  => [ 'give' ];
		yield 'mixed case' => [ 'GiveRecurring' ];
		yield 'digits'     => [ 'give2' ];
		yield 'hyphen'     => [ 'give-recurring' ];
		yield 'underscore' => [ 'give_recurring' ];
		yield 'all of it'  => [ 'give-recurring_2' ];
	}

	/**
	 * @dataProvider invalid_hook_prefixes
	 *
	 * @param string $prefix Prefix under test.
	 */
	public function test_it_rejects_invalid_hook_prefixes( string $prefix ): void {
		$this->expectException( Config_Exception::class );

		Config::set_hook_prefix( $prefix );
	}

	/**
	 * @return Generator<string,array{0:string}>
	 */
	public static function invalid_hook_prefixes(): Generator {
		yield 'empty string' => [ '' ];
		yield 'slash'        => [ 'give/recurring' ];
		yield 'space'        => [ 'give recurring' ];
		yield 'dot'          => [ 'give.recurring' ];
		yield 'backslash'    => [ 'give\\recurring' ];
	}

	public function test_it_throws_when_the_hook_prefix_was_never_set(): void {
		$this->expectException( Config_Exception::class );

		Config::get_hook_prefix();
	}

	public function test_config_exception_is_catchable_as_a_runtime_exception(): void {
		try {
			Config::set_hook_prefix( 'give/recurring' );
		} catch ( RuntimeException $exception ) {
			$this->assertInstanceOf( Config_Exception::class, $exception );

			return;
		}

		$this->fail( 'set_hook_prefix() accepted an invalid prefix instead of throwing.' );
	}

	public function test_it_builds_a_hook_name_in_the_library_namespace(): void {
		Config::set_hook_prefix( 'give' );

		$this->assertSame(
			'give/plugin_absorber/conflict_policy',
			Config::get_hook_name( 'conflict_policy' )
		);
	}

	public function test_a_hook_name_needs_a_prefix(): void {
		$this->expectException( Config_Exception::class );

		Config::get_hook_name( 'conflict_policy' );
	}

	public function test_it_reports_no_container_by_default(): void {
		$this->assertFalse( Config::has_container() );
		$this->assertNull( Config::get_container() );
	}

	public function test_it_stores_and_returns_a_container(): void {
		$container = new Test_Container();

		Config::set_container( $container );

		$this->assertTrue( Config::has_container() );
		$this->assertSame( $container, Config::get_container() );
	}

	public function test_the_state_helper_clears_every_value(): void {
		Config::set_hook_prefix( 'give' );
		Config::set_container( new Test_Container() );

		Config_State::reset();

		$this->assertFalse( Config::has_container() );
		$this->assertNull( Config::get_container() );

		$this->expectException( Config_Exception::class );
		Config::get_hook_prefix();
	}
}
