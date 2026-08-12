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

	/**
	 * The assertion that keeps hook names and option names apart. Only the storage key is folded,
	 * so a host that passed `Give-Core` can still hook the filter name it was given.
	 */
	public function test_a_hook_name_keeps_the_prefix_verbatim(): void {
		Config::set_hook_prefix( 'Give-Core' );

		$this->assertSame(
			'Give-Core/plugin_absorber/should_load',
			Config::get_hook_name( 'should_load' )
		);
	}

	/**
	 * @dataProvider option_name_prefixes
	 *
	 * @param string $prefix   Prefix under test.
	 * @param string $expected Option name it must produce.
	 */
	public function test_it_builds_an_option_name_from_a_normalised_prefix(
		string $prefix,
		string $expected
	): void {
		Config::set_hook_prefix( $prefix );

		$this->assertSame( $expected, Config::get_option_name( 'notices' ) );
	}

	/**
	 * @return Generator<string,array{0:string,1:string}>
	 */
	public static function option_name_prefixes(): Generator {
		yield 'nothing to fold'       => [ 'give', 'give_plugin_absorber_notices' ];
		yield 'underscore is kept'    => [ 'give_recurring', 'give_recurring_plugin_absorber_notices' ];
		yield 'mixed case'            => [ 'GiveRecurring', 'giverecurring_plugin_absorber_notices' ];
		yield 'hyphen'                => [ 'give-recurring', 'give_recurring_plugin_absorber_notices' ];
		yield 'mixed case and hyphen' => [ 'Give-Core', 'give_core_plugin_absorber_notices' ];
	}

	public function test_an_option_name_needs_a_prefix(): void {
		$this->expectException( Config_Exception::class );

		Config::get_option_name( 'notices' );
	}

	public function test_it_reports_no_container_by_default(): void {
		$this->assertFalse( Config::has_container() );
	}

	/**
	 * The container is required, so reading it without one is a configuration error rather than a
	 * null every caller then has to test for. Every collaborator comes from the container now: a
	 * silent null would surface as a TypeError from somewhere inside plugins_loaded, naming this
	 * library rather than the bootstrap that skipped a step.
	 */
	public function test_reading_a_container_that_was_never_set_is_a_configuration_error(): void {
		$this->expectException( Config_Exception::class );

		Config::get_container();
	}

	/**
	 * The probe stays, and answers without throwing — it is how a host asks whether it has already
	 * configured the library.
	 */
	public function test_the_probe_answers_without_throwing(): void {
		$this->assertFalse( Config::has_container() );

		Config::set_container( new Test_Container() );

		$this->assertTrue( Config::has_container() );
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

		foreach ( [ 'get_container', 'get_hook_prefix' ] as $accessor ) {
			try {
				Config::{$accessor}();
				$this->fail( sprintf( 'Config::%s() must throw once the state helper has run.', $accessor ) );
			} catch ( Config_Exception $exception ) {
				$this->assertNotSame( '', $exception->getMessage(), 'The host has to be told what is missing.' );
			}
		}
	}
}
