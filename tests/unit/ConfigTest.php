<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Unit;

use Codeception\TestCase\WPTestCase;
use Nexcess\PluginAbsorber\Config;
use Nexcess\PluginAbsorber\Exceptions\Config_Exception;
use Nexcess\PluginAbsorber\Tests\Support\Test_Container;

/**
 * @since 1.0.0
 */
class ConfigTest extends WPTestCase {
	public function tearDown(): void {
		Config::reset();
		parent::tearDown();
	}

	public function test_it_stores_and_returns_the_hook_prefix(): void {
		Config::set_hook_prefix( 'give' );

		$this->assertSame( 'give', Config::get_hook_prefix() );
	}

	public function test_it_accepts_letters_numbers_hyphens_and_underscores(): void {
		Config::set_hook_prefix( 'give-recurring_2' );

		$this->assertSame( 'give-recurring_2', Config::get_hook_prefix() );
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
	 * @return array<string,array{0:string}>
	 */
	public function invalid_hook_prefixes(): array {
		return [
			'slash'     => [ 'give/recurring' ],
			'space'     => [ 'give recurring' ],
			'dot'       => [ 'give.recurring' ],
			'backslash' => [ 'give\\recurring' ],
		];
	}

	public function test_it_rejects_an_empty_hook_prefix(): void {
		$this->expectException( Config_Exception::class );

		Config::set_hook_prefix( '' );
	}

	public function test_it_throws_when_the_hook_prefix_was_never_set(): void {
		$this->expectException( Config_Exception::class );

		Config::get_hook_prefix();
	}

	public function test_it_stores_and_returns_the_version(): void {
		Config::set_version( '3.0.0' );

		$this->assertSame( '3.0.0', Config::get_version() );
	}

	public function test_the_version_defaults_to_an_empty_string(): void {
		$this->assertSame( '', Config::get_version() );
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

	public function test_reset_clears_every_value(): void {
		Config::set_hook_prefix( 'give' );
		Config::set_version( '3.0.0' );
		Config::set_container( new Test_Container() );

		Config::reset();

		$this->assertSame( '', Config::get_version() );
		$this->assertFalse( Config::has_container() );
		$this->assertNull( Config::get_container() );

		$this->expectException( Config_Exception::class );
		Config::get_hook_prefix();
	}
}
