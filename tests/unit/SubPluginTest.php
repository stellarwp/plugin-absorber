<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Unit;

use Codeception\TestCase\WPTestCase;
use lucatume\WPBrowser\Traits\UopzFunctions;
use Nexcess\PluginAbsorber\Config;
use Nexcess\PluginAbsorber\Conflict_Policy;
use Nexcess\PluginAbsorber\Exceptions\Config_Exception;
use Nexcess\PluginAbsorber\Sub_Plugin;

/**
 * @since 1.0.0
 */
class SubPluginTest extends WPTestCase {
	use UopzFunctions;

	public function setUp(): void {
		parent::setUp();

		Config::reset();
		Config::set_hook_prefix( 'give' );

		// uopz cannot stub a function that does not exist yet.
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	public function tearDown(): void {
		Config::reset();
		parent::tearDown();
	}

	/**
	 * @param array<string,mixed> $overrides Config overrides.
	 */
	private function make_sub_plugin( array $overrides = [] ): Sub_Plugin {
		return new Sub_Plugin(
			array_merge(
				[
					'slug'                   => 'give-recurring',
					'bundled_plugin_file'    => '/tmp/give-recurring/give-recurring.php',
					'plugin_loaded_constant' => 'GIVE_RECURRING_VERSION_TEST',
				],
				$overrides
			)
		);
	}

	/**
	 * @dataProvider required_keys
	 *
	 * @param string $missing_key Key to omit.
	 */
	public function test_it_requires_every_required_key( string $missing_key ): void {
		$config = [
			'slug'                   => 'give-recurring',
			'bundled_plugin_file'    => '/tmp/x.php',
			'plugin_loaded_constant' => 'X_VERSION',
		];
		unset( $config[ $missing_key ] );

		$this->expectException( Config_Exception::class );
		$this->expectExceptionMessage( $missing_key );

		new Sub_Plugin( $config );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function required_keys(): array {
		return [
			'slug'                   => [ 'slug' ],
			'bundled_plugin_file'    => [ 'bundled_plugin_file' ],
			'plugin_loaded_constant' => [ 'plugin_loaded_constant' ],
		];
	}

	/**
	 * @dataProvider unusable_required_values
	 *
	 * @param mixed $value Value to put under a required key.
	 */
	public function test_it_rejects_a_required_key_that_is_not_a_non_empty_string( $value ): void {
		$this->expectException( Config_Exception::class );
		$this->expectExceptionMessage( 'slug' );

		$this->make_sub_plugin( [ 'slug' => $value ] );
	}

	/**
	 * An array is the one that matters: it survives a truthiness check and then casts to the
	 * string "Array", which every sub-plugin making the same mistake would collide on.
	 *
	 * @return array<string,array{0:mixed}>
	 */
	public function unusable_required_values(): array {
		return [
			'empty string' => [ '' ],
			'array'        => [ [ 'give', 'recurring' ] ],
			'null'         => [ null ],
			'integer'      => [ 42 ],
			'object'       => [ new \stdClass() ],
		];
	}

	/**
	 * @dataProvider callable_only_keys
	 *
	 * @param string $key Key that only ever holds a callable.
	 */
	public function test_it_rejects_a_callable_only_key_that_cannot_be_called( string $key ): void {
		$this->expectException( Config_Exception::class );
		$this->expectExceptionMessage( $key );

		$this->make_sub_plugin( [ $key => 'absorber_no_such_function' ] );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function callable_only_keys(): array {
		return [
			'dependency_check'    => [ 'dependency_check' ],
			'activation_callback' => [ 'activation_callback' ],
		];
	}

	public function test_it_exposes_the_required_values(): void {
		$sub_plugin = $this->make_sub_plugin();

		$this->assertSame( 'give-recurring', $sub_plugin->get_slug() );
		$this->assertSame( '/tmp/give-recurring/give-recurring.php', $sub_plugin->get_bundled_plugin_file() );
		$this->assertSame( 'GIVE_RECURRING_VERSION_TEST', $sub_plugin->get_plugin_loaded_constant() );
	}

	public function test_it_is_enabled_by_default(): void {
		$this->assertTrue( $this->make_sub_plugin()->is_enabled() );
	}

	public function test_it_honours_a_boolean_enabled_flag(): void {
		$this->assertFalse( $this->make_sub_plugin( [ 'enabled' => false ] )->is_enabled() );
	}

	public function test_it_resolves_a_callable_enabled_flag_at_call_time(): void {
		$switch     = false;
		$sub_plugin = $this->make_sub_plugin(
			[
				'enabled' => static function () use ( &$switch ) {
					return $switch;
				},
			]
		);

		$this->assertFalse( $sub_plugin->is_enabled() );

		$switch = true;

		$this->assertTrue( $sub_plugin->is_enabled(), 'The callable must be re-evaluated on each call.' );
	}

	public function test_it_reports_not_loaded_when_the_constant_is_undefined(): void {
		$this->assertFalse( $this->make_sub_plugin( [ 'plugin_loaded_constant' => 'ABSORBER_NEVER_DEFINED' ] )->is_already_loaded() );
	}

	public function test_it_reports_loaded_once_the_constant_is_defined(): void {
		define( 'ABSORBER_TEST_LOADED_CONSTANT', '1.0.0' );

		$this->assertTrue( $this->make_sub_plugin( [ 'plugin_loaded_constant' => 'ABSORBER_TEST_LOADED_CONSTANT' ] )->is_already_loaded() );
	}

	public function test_it_reports_no_standalone_when_the_basename_is_absent(): void {
		$sub_plugin = $this->make_sub_plugin();

		$this->assertFalse( $sub_plugin->has_standalone_plugin() );
		$this->assertSame( '', $sub_plugin->get_standalone_plugin_basename() );
		$this->assertFalse( $sub_plugin->is_standalone_plugin_active() );
		$this->assertFalse( $sub_plugin->is_standalone_plugin_network_active() );
	}

	public function test_it_never_calls_wordpress_without_a_standalone(): void {
		$this->setFunctionReturn( 'is_plugin_active', true );
		$this->setFunctionReturn( 'is_plugin_active_for_network', true );

		$this->assertFalse(
			$this->make_sub_plugin()->is_standalone_plugin_active(),
			'Absent a standalone basename the predicate must short-circuit.'
		);
	}

	public function test_it_reports_a_configured_standalone(): void {
		$sub_plugin = $this->make_sub_plugin( [ 'standalone_plugin_basename' => 'give-recurring/give-recurring.php' ] );

		$this->assertTrue( $sub_plugin->has_standalone_plugin() );
		$this->assertSame( 'give-recurring/give-recurring.php', $sub_plugin->get_standalone_plugin_basename() );
	}

	/**
	 * The basename is what later reaches deactivate_plugins() and the activation-error rewrite,
	 * so asserting only the return value would let the wrong string be passed unnoticed.
	 */
	public function test_it_passes_the_standalone_basename_to_wordpress(): void {
		$received = [];

		$this->setFunctionReturn(
			'is_plugin_active',
			static function ( $basename ) use ( &$received ) {
				$received['is_plugin_active'] = $basename;

				return true;
			},
			true
		);
		$this->setFunctionReturn(
			'is_plugin_active_for_network',
			static function ( $basename ) use ( &$received ) {
				$received['is_plugin_active_for_network'] = $basename;

				return true;
			},
			true
		);

		$sub_plugin = $this->make_sub_plugin( [ 'standalone_plugin_basename' => 'give-recurring/give-recurring.php' ] );

		$sub_plugin->is_standalone_plugin_active();
		$sub_plugin->is_standalone_plugin_network_active();

		$this->assertSame(
			[
				'is_plugin_active'             => 'give-recurring/give-recurring.php',
				'is_plugin_active_for_network' => 'give-recurring/give-recurring.php',
			],
			$received
		);
	}

	public function test_it_delegates_the_active_check_to_wordpress(): void {
		$this->setFunctionReturn( 'is_plugin_active', true );
		$this->setFunctionReturn( 'is_plugin_active_for_network', false );

		$sub_plugin = $this->make_sub_plugin( [ 'standalone_plugin_basename' => 'give-recurring/give-recurring.php' ] );

		$this->assertTrue( $sub_plugin->is_standalone_plugin_active() );
		$this->assertFalse( $sub_plugin->is_standalone_plugin_network_active() );
	}

	/**
	 * WordPress's own is_plugin_active() ORs in the network check, so a network-active plugin
	 * reports true from both. Stubbing is_plugin_active false here would describe a state
	 * WordPress cannot produce.
	 */
	public function test_it_detects_a_network_active_standalone(): void {
		$this->setFunctionReturn( 'is_plugin_active', true );
		$this->setFunctionReturn( 'is_plugin_active_for_network', true );

		$sub_plugin = $this->make_sub_plugin( [ 'standalone_plugin_basename' => 'give-recurring/give-recurring.php' ] );

		$this->assertTrue( $sub_plugin->is_standalone_plugin_active() );
		$this->assertTrue( $sub_plugin->is_standalone_plugin_network_active() );
	}

	public function test_it_detects_an_inactive_standalone(): void {
		$this->setFunctionReturn( 'is_plugin_active', false );
		$this->setFunctionReturn( 'is_plugin_active_for_network', false );

		$sub_plugin = $this->make_sub_plugin( [ 'standalone_plugin_basename' => 'give-recurring/give-recurring.php' ] );

		$this->assertFalse( $sub_plugin->is_standalone_plugin_active() );
	}

	public function test_dependencies_are_met_without_a_check(): void {
		$this->assertTrue( $this->make_sub_plugin()->are_dependencies_met() );
	}

	public function test_it_honours_the_dependency_check(): void {
		$this->assertFalse(
			$this->make_sub_plugin( [ 'dependency_check' => static fn() => false ] )->are_dependencies_met()
		);
		$this->assertTrue(
			$this->make_sub_plugin( [ 'dependency_check' => static fn() => true ] )->are_dependencies_met()
		);
	}

	public function test_the_conflict_policy_defaults_to_deactivate(): void {
		$this->assertSame( Conflict_Policy::DEACTIVATE, $this->make_sub_plugin()->get_conflict_policy() );
	}

	public function test_it_resolves_a_string_conflict_policy(): void {
		$this->assertSame(
			Conflict_Policy::DEFER,
			$this->make_sub_plugin( [ 'conflict_policy' => Conflict_Policy::DEFER ] )->get_conflict_policy()
		);
	}

	public function test_it_resolves_a_callable_conflict_policy_and_passes_itself(): void {
		$received   = null;
		$sub_plugin = $this->make_sub_plugin(
			[
				'conflict_policy' => static function ( Sub_Plugin $passed ) use ( &$received ) {
					$received = $passed;

					return Conflict_Policy::NOTICE_ONLY;
				},
			]
		);

		$this->assertSame( Conflict_Policy::NOTICE_ONLY, $sub_plugin->get_conflict_policy() );
		$this->assertSame( $sub_plugin, $received );
	}

	public function test_the_filter_overrides_the_resolved_policy(): void {
		add_filter(
			'give/plugin_absorber/conflict_policy',
			static function () {
				return Conflict_Policy::DEFER;
			}
		);

		$sub_plugin = $this->make_sub_plugin( [ 'conflict_policy' => Conflict_Policy::DEACTIVATE ] );

		$this->assertSame(
			Conflict_Policy::DEFER,
			$sub_plugin->get_conflict_policy(),
			'The filter runs after the config value and the callable, and wins.'
		);
	}

	public function test_the_filter_receives_the_sub_plugin(): void {
		$received = null;

		add_filter(
			'give/plugin_absorber/conflict_policy',
			static function ( $policy, $passed ) use ( &$received ) {
				$received = $passed;

				return $policy;
			},
			10,
			2
		);

		$sub_plugin = $this->make_sub_plugin();
		$sub_plugin->get_conflict_policy();

		$this->assertSame( $sub_plugin, $received );
	}

	public function test_the_conflict_notice_message_defaults_to_empty(): void {
		$this->assertSame( '', $this->make_sub_plugin()->get_conflict_notice_message() );
	}

	public function test_it_resolves_conflict_notice_messages_from_strings_and_callables(): void {
		$this->assertSame(
			'Bundled now.',
			$this->make_sub_plugin( [ 'conflict_notice_message' => 'Bundled now.' ] )->get_conflict_notice_message()
		);
		$this->assertSame(
			'Deferred.',
			$this->make_sub_plugin( [ 'conflict_notice_message' => static fn() => 'Deferred.' ] )->get_conflict_notice_message()
		);
	}

	public function test_the_dependency_notice_message_falls_back_to_a_default(): void {
		$this->assertSame(
			'give-recurring could not be loaded because its requirements are not met.',
			$this->make_sub_plugin()->get_dependency_notice_message()
		);
	}

	public function test_it_resolves_dependency_notice_messages_from_strings_and_callables(): void {
		$this->assertSame(
			'Needs WooCommerce.',
			$this->make_sub_plugin( [ 'dependency_notice_message' => 'Needs WooCommerce.' ] )->get_dependency_notice_message()
		);
		$this->assertSame(
			'Needs Give.',
			$this->make_sub_plugin( [ 'dependency_notice_message' => static fn() => 'Needs Give.' ] )->get_dependency_notice_message()
		);
	}

	/**
	 * is_callable() is true for any string naming an existing function, and a policy read from
	 * an option can easily be one. Invoking date() here would be a fatal on PHP 8.
	 *
	 * @dataProvider function_names
	 *
	 * @param string $name Name of a real PHP function.
	 */
	public function test_a_policy_string_is_never_invoked_as_a_function( string $name ): void {
		$this->assertSame(
			$name,
			$this->make_sub_plugin( [ 'conflict_policy' => $name ] )->get_conflict_policy()
		);
	}

	/**
	 * @dataProvider function_names
	 *
	 * @param string $name Name of a real PHP function.
	 */
	public function test_a_message_string_is_never_invoked_as_a_function( string $name ): void {
		$this->assertSame(
			$name,
			$this->make_sub_plugin( [ 'conflict_notice_message' => $name ] )->get_conflict_notice_message()
		);
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function function_names(): array {
		return [
			'date'  => [ 'date' ],
			'flush' => [ 'flush' ],
			'key'   => [ 'key' ],
		];
	}

	public function test_a_non_scalar_filter_return_yields_no_policy(): void {
		add_filter(
			'give/plugin_absorber/conflict_policy',
			static function () {
				return new \WP_Error( 'nope', 'Nope.' );
			}
		);

		$this->assertSame(
			'',
			$this->make_sub_plugin()->get_conflict_policy(),
			'Casting an object would be a fatal; an empty string is simply not a valid policy.'
		);
	}

	public function test_the_conflict_policy_needs_a_hook_prefix(): void {
		Config::reset();

		$this->expectException( Config_Exception::class );

		$this->make_sub_plugin()->get_conflict_policy();
	}

	public function test_the_enabled_callable_receives_the_sub_plugin(): void {
		$received   = null;
		$sub_plugin = $this->make_sub_plugin(
			[
				'enabled' => static function ( $passed ) use ( &$received ) {
					$received = $passed;

					return true;
				},
			]
		);

		$sub_plugin->is_enabled();

		$this->assertSame( $sub_plugin, $received );
	}

	public function test_the_dependency_check_receives_the_sub_plugin(): void {
		$received   = null;
		$sub_plugin = $this->make_sub_plugin(
			[
				'dependency_check' => static function ( $passed ) use ( &$received ) {
					$received = $passed;

					return true;
				},
			]
		);

		$sub_plugin->are_dependencies_met();

		$this->assertSame( $sub_plugin, $received );
	}

	public function test_the_conflict_notice_message_falls_back_to_the_given_default(): void {
		$this->assertSame(
			'Bundled now.',
			$this->make_sub_plugin()->get_conflict_notice_message( 'Bundled now.' )
		);
	}

	public function test_a_configured_conflict_notice_message_beats_the_default(): void {
		$this->assertSame(
			'Configured.',
			$this->make_sub_plugin( [ 'conflict_notice_message' => 'Configured.' ] )
				->get_conflict_notice_message( 'Default.' )
		);
	}

	public function test_the_activation_callback_is_null_by_default(): void {
		$this->assertNull( $this->make_sub_plugin()->get_activation_callback() );
	}

	public function test_it_returns_the_activation_callback(): void {
		$callback = static function () {};

		$this->assertSame( $callback, $this->make_sub_plugin( [ 'activation_callback' => $callback ] )->get_activation_callback() );
	}
}
