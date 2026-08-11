<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Unit;

use Codeception\TestCase\WPTestCase;
use Generator;
use lucatume\WPBrowser\Traits\UopzFunctions;
use Nexcess\PluginAbsorber\Config;
use Nexcess\PluginAbsorber\Conflict_Policy;
use Nexcess\PluginAbsorber\Exceptions\Config_Exception;
use Nexcess\PluginAbsorber\Sub_Plugin;
use Nexcess\PluginAbsorber\Tests\Support\Config_State;
use Nexcess\PluginAbsorber\Tests\Support\Deferred_Message;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithSubPlugins;

/**
 * @since 1.0.0
 */
class SubPluginTest extends WPTestCase {
	use UopzFunctions;
	use WithSubPlugins;

	public function setUp(): void {
		parent::setUp();

		Config_State::reset();
		Config::set_hook_prefix( 'give' );

		// uopz cannot stub a function that does not exist yet.
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	/**
	 * UopzFunctions restores nothing of its own accord, and a define() outlives the test that made
	 * it for the rest of the process. Undoing it here rather than at the end of the test body means
	 * a failed assertion cannot strand a constant that later tests would then read as a real load.
	 * Both calls are no-ops when the test never set anything.
	 */
	public function tearDown(): void {
		$this->unsetConstant( 'ABSORBER_TEST_LOADED_CONSTANT' );
		$this->unsetFunctionHook( 'is_plugin_active' );
		$this->unsetFunctionHook( 'is_plugin_active_for_network' );

		Config_State::reset();
		parent::tearDown();
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
	 * @return Generator<string,array{0:string}>
	 */
	public static function required_keys(): Generator {
		yield 'slug'                   => [ 'slug' ];
		yield 'bundled_plugin_file'    => [ 'bundled_plugin_file' ];
		yield 'plugin_loaded_constant' => [ 'plugin_loaded_constant' ];
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
	 * @return Generator<string,array{0:mixed}>
	 */
	public static function unusable_required_values(): Generator {
		yield 'empty string' => [ '' ];
		yield 'array'        => [ [ 'give', 'recurring' ] ];
		yield 'null'         => [ null ];
		yield 'integer'      => [ 42 ];
		yield 'object'       => [ new \stdClass() ];
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
	 * @return Generator<string,array{0:string}>
	 */
	public static function callable_only_keys(): Generator {
		yield 'dependency_check'    => [ 'dependency_check' ];
		yield 'activation_callback' => [ 'activation_callback' ];
	}

	/**
	 * A basename names a file already on disk. Nothing about it waits on anything, so a closure
	 * under it is a mistake worth catching at registration: it would reach deactivate_plugins() as
	 * the string "Closure" much later, with nothing pointing back at the registration that caused
	 * it.
	 */
	public function test_it_rejects_a_standalone_basename_that_is_not_a_string(): void {
		$this->expectException( Config_Exception::class );
		$this->expectExceptionMessage( 'standalone_plugin_basename' );

		$this->make_sub_plugin( [ 'standalone_plugin_basename' => static fn() => 'give/give.php' ] );
	}

	/**
	 * Neither form, so it is rejected where the mistake was made. At read time an array would cast
	 * to the string "Array", and a [ class, method ] pair naming a method that does not exist would
	 * become the notice text itself.
	 *
	 * @dataProvider deferrable_keys
	 *
	 * @param string $key Key that holds a string or a callable.
	 */
	public function test_it_rejects_a_deferrable_key_that_is_neither_a_string_nor_a_callable( string $key ): void {
		$this->expectException( Config_Exception::class );
		$this->expectExceptionMessage( $key );

		$this->make_sub_plugin( [ $key => [ Deferred_Message::class, 'no_such_method' ] ] );
	}

	/**
	 * The shapes a host can land on by mistake, checked exhaustively on the one key that also takes
	 * a string; the test above proves each of the others rejects a shape of its own. `null` is
	 * deliberately absent: an unset key and a key set to null both mean "not configured", not
	 * "configured wrongly".
	 *
	 * @dataProvider unusable_deferrable_values
	 *
	 * @param mixed $value Value that is neither a string nor callable.
	 */
	public function test_it_rejects_a_deferrable_value_of_any_other_shape( $value ): void {
		$this->expectException( Config_Exception::class );
		$this->expectExceptionMessage( 'conflict_policy' );

		$this->make_sub_plugin( [ 'conflict_policy' => $value ] );
	}

	/**
	 * @return Generator<string,array{0:mixed}>
	 */
	public static function unusable_deferrable_values(): Generator {
		yield 'array of strings'          => [ [ 'give', 'recurring' ] ];
		yield 'object without __invoke'   => [ new \stdClass() ];
		yield 'integer'                   => [ 42 ];
		yield 'boolean'                   => [ true ];
		yield 'pair naming no such class' => [ [ 'Give_No_Such_Class', 'get_conflict_message' ] ];
	}

	/**
	 * The two keys carrying human-readable text take a callable and nothing else.
	 *
	 * A string under one of them is either already translated -- the too-early `__()` these keys
	 * exist to move -- or the name of a function the host hoped would be called. Nothing tells the
	 * two apart, and the library cannot honour the second without making the result depend on what
	 * else the site loaded. Refusing both at registration costs a host one `static fn()` and is
	 * caught the first time the code runs, where an eagerly translated string is a log notice on
	 * someone else's site.
	 *
	 * @dataProvider message_key_strings
	 *
	 * @param string $key     Key that only ever holds a callable.
	 * @param string $message String of any shape.
	 */
	public function test_it_rejects_a_message_that_is_already_a_string( string $key, string $message ): void {
		$this->expectException( Config_Exception::class );
		$this->expectExceptionMessage( $key );

		$this->make_sub_plugin( [ $key => $message ] );
	}

	/**
	 * Every string shape against both message keys. The first case is what a host gets back from
	 * `__()` -- there is nothing in the value to say so, which is the whole reason the rule cannot
	 * be narrower. The other two are the spellings a host reaches for when it wants a call.
	 *
	 * @return Generator<string,array{0:string,1:string}>
	 */
	public static function message_key_strings(): Generator {
		foreach ( [ 'conflict_notice_message', 'dependency_notice_message' ] as $key ) {
			yield "{$key}: translated text"    => [ $key, 'Recurring ships with Give now.' ];
			yield "{$key}: function name"      => [ $key, '__return_empty_string' ];
			yield "{$key}: static method name" => [ $key, Deferred_Message::class . '::from_a_static_method' ];
		}
	}

	/**
	 * The deferral these keys exist for. A host that writes `__()` into its config array translates
	 * while the array is being built -- at plugin load, before `init` and before its own textdomain
	 * -- which is what raises the `_load_textdomain_just_in_time` notice. Handing over something to
	 * call instead moves the translation to the moment the text is actually wanted.
	 *
	 * @dataProvider deferrable_keys
	 *
	 * @param string $key    Key that holds a string or a callable.
	 * @param string $getter Method under test.
	 */
	public function test_it_invokes_a_closure_under_a_deferrable_key( string $key, string $getter ): void {
		$this->assertSame(
			'Deferred.',
			$this->make_sub_plugin( [ $key => static fn() => 'Deferred.' ] )->{$getter}()
		);
	}

	/**
	 * Every callable form a string cannot be mistaken for. These are what a host reaches for when
	 * the text lives on one of its own classes, or comes out of its container.
	 *
	 * @dataProvider deferrable_keys
	 *
	 * @param string $key    Key that holds a string or a callable.
	 * @param string $getter Method under test.
	 */
	public function test_it_invokes_every_callable_form_that_is_not_a_string( string $key, string $getter ): void {
		$this->assertSame(
			'static: give-recurring',
			$this->make_sub_plugin( [ $key => [ Deferred_Message::class, 'from_a_static_method' ] ] )->{$getter}(),
			'A [ class, method ] pair.'
		);
		$this->assertSame(
			'instance: give-recurring',
			$this->make_sub_plugin( [ $key => [ new Deferred_Message(), 'from_an_instance_method' ] ] )->{$getter}(),
			'An [ object, method ] pair.'
		);
		$this->assertSame(
			'invoked: give-recurring',
			$this->make_sub_plugin( [ $key => new Deferred_Message() ] )->{$getter}(),
			'An object with __invoke().'
		);
	}

	/**
	 * @dataProvider deferrable_keys
	 *
	 * @param string $key    Key that holds a string or a callable.
	 * @param string $getter Method under test.
	 */
	public function test_a_deferrable_callable_receives_the_sub_plugin( string $key, string $getter ): void {
		$received = null;

		$sub_plugin = $this->make_sub_plugin(
			[
				$key => static function ( $passed ) use ( &$received ): string {
					$received = $passed;

					return 'Deferred.';
				},
			]
		);

		$sub_plugin->{$getter}();

		$this->assertSame( $sub_plugin, $received );
	}

	/**
	 * Called on every read rather than resolved once at registration -- resolving in the
	 * constructor would move the too-early call from the config array to the line after it.
	 *
	 * @dataProvider deferrable_keys
	 *
	 * @param string $key    Key that holds a string or a callable.
	 * @param string $getter Method under test.
	 */
	public function test_a_deferrable_callable_is_called_on_every_read( string $key, string $getter ): void {
		$text = 'First.';

		$sub_plugin = $this->make_sub_plugin(
			[
				$key => static function () use ( &$text ): string {
					return $text;
				},
			]
		);

		$this->assertSame( 'First.', $sub_plugin->{$getter}() );

		$text = 'Second.';

		$this->assertSame( 'Second.', $sub_plugin->{$getter}() );
	}

	/**
	 * @return Generator<string,array{0:string,1:string}>
	 */
	public static function deferrable_keys(): Generator {
		yield 'conflict_policy'           => [ 'conflict_policy', 'get_conflict_policy' ];
		yield 'conflict_notice_message'   => [ 'conflict_notice_message', 'get_conflict_notice_message' ];
		yield 'dependency_notice_message' => [ 'dependency_notice_message', 'get_dependency_notice_message' ];
	}

	/**
	 * A callable is the host's code, and casting what it returned would be a fatal at
	 * plugins_loaded. It is dropped the same way an uncastable filter return is, which leaves each
	 * key at the value it carries when nothing usable was configured.
	 *
	 * @dataProvider uncastable_callable_returns
	 *
	 * @param string $key      Key that holds a string or a callable.
	 * @param string $getter   Method under test.
	 * @param string $expected What the key is worth once the return is dropped.
	 */
	public function test_an_uncastable_callable_return_yields_nothing( string $key, string $getter, string $expected ): void {
		$this->assertSame(
			$expected,
			$this->make_sub_plugin( [ $key => static fn() => new \WP_Error( 'nope', 'Nope.' ) ] )->{$getter}()
		);
	}

	/**
	 * The policy is the one that differs: its key is set, so there is no configured value to fall
	 * back to, and an empty string matches no known policy -- which is where a caller that
	 * dispatches on it takes the conservative branch rather than deactivating anything.
	 *
	 * @return Generator<string,array{0:string,1:string,2:string}>
	 */
	public static function uncastable_callable_returns(): Generator {
		yield 'conflict_policy'         => [ 'conflict_policy', 'get_conflict_policy', '' ];
		yield 'conflict_notice_message' => [ 'conflict_notice_message', 'get_conflict_notice_message', '' ];
		yield 'dependency_notice_message' => [
			'dependency_notice_message',
			'get_dependency_notice_message',
			'give-recurring could not be loaded because its requirements are not met.',
		];
	}

	public function test_it_exposes_the_required_values(): void {
		$sub_plugin = $this->make_sub_plugin();

		$this->assertSame( 'give-recurring', $sub_plugin->get_slug() );
		$this->assertSame( '/tmp/give-recurring/give-recurring.php', $sub_plugin->get_bundled_plugin_file() );
		$this->assertSame( 'GIVE_RECURRING_VERSION_FIXTURE', $sub_plugin->get_plugin_loaded_constant() );
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

	/**
	 * No string is a valid boolean, so nothing on this key has to guess whether a string means
	 * itself or means a function to call. Every callable form works, a function name included.
	 */
	public function test_it_accepts_a_function_name_as_the_enabled_flag(): void {
		$this->assertFalse( $this->make_sub_plugin( [ 'enabled' => '__return_false' ] )->is_enabled() );
		$this->assertTrue( $this->make_sub_plugin( [ 'enabled' => '__return_true' ] )->is_enabled() );
	}

	public function test_it_reports_not_loaded_when_the_constant_is_undefined(): void {
		$this->assertFalse( $this->make_sub_plugin( [ 'plugin_loaded_constant' => 'ABSORBER_NEVER_DEFINED' ] )->is_already_loaded() );
	}

	public function test_it_reports_loaded_once_the_constant_is_defined(): void {
		$this->setConstant( 'ABSORBER_TEST_LOADED_CONSTANT', '1.0.0' );

		$this->assertTrue( $this->make_sub_plugin( [ 'plugin_loaded_constant' => 'ABSORBER_TEST_LOADED_CONSTANT' ] )->is_already_loaded() );
	}

	public function test_it_reports_no_standalone_when_the_basename_is_absent(): void {
		$sub_plugin = $this->make_sub_plugin();

		$this->assertFalse( $sub_plugin->has_standalone_plugin() );
		$this->assertSame( '', $sub_plugin->get_standalone_plugin_basename() );
	}

	/**
	 * Whether the standalone is active is a question about the site, and this object answers only
	 * from its own configuration. Asserting the answer is not enough on its own -- it would pass
	 * for an object that asked WordPress and happened to agree -- so this records the calls and
	 * asserts none were made.
	 */
	public function test_it_asks_wordpress_nothing_about_the_standalone(): void {
		$calls = [];

		foreach ( [ 'is_plugin_active', 'is_plugin_active_for_network' ] as $function ) {
			$this->setFunctionHook(
				$function,
				static function () use ( &$calls, $function ): void {
					$calls[] = $function;
				}
			);
		}

		$sub_plugin = $this->make_sub_plugin( [ 'standalone_plugin_basename' => 'give-recurring/give-recurring.php' ] );

		$this->assertTrue( $sub_plugin->has_standalone_plugin() );
		$this->assertSame( 'give-recurring/give-recurring.php', $sub_plugin->get_standalone_plugin_basename() );
		$this->assertSame( [], $calls, 'Sub_Plugin must answer from its configuration alone.' );

		// The assertion above is only worth anything if the recorder records. A hook that failed to
		// install would leave $calls empty however much Sub_Plugin asked WordPress.
		//
		// Asserted with assertContains rather than against a list: is_plugin_active() delegates to
		// is_plugin_active_for_network() internally, and how many functions core reaches on the way
		// is not this test's business.
		is_plugin_active( 'give-recurring/give-recurring.php' );

		$this->assertContains( 'is_plugin_active', $calls, 'The recorder itself must work.' );
	}

	public function test_it_reports_a_configured_standalone(): void {
		$sub_plugin = $this->make_sub_plugin( [ 'standalone_plugin_basename' => 'give-recurring/give-recurring.php' ] );

		$this->assertTrue( $sub_plugin->has_standalone_plugin() );
		$this->assertSame( 'give-recurring/give-recurring.php', $sub_plugin->get_standalone_plugin_basename() );
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

	/**
	 * Nothing a string could collide with on a callable-only key, so a plain function name is
	 * called rather than treated as a value.
	 */
	public function test_it_accepts_a_function_name_as_the_dependency_check(): void {
		$this->assertFalse( $this->make_sub_plugin( [ 'dependency_check' => '__return_false' ] )->are_dependencies_met() );
		$this->assertTrue( $this->make_sub_plugin( [ 'dependency_check' => '__return_true' ] )->are_dependencies_met() );
	}

	/**
	 * Asserted against Conflict_Policy::default() rather than a named policy: which policy is the
	 * default is that class's to state, and this only proves an unconfigured sub-plugin asks it.
	 */
	public function test_an_unconfigured_conflict_policy_falls_back_to_the_library_default(): void {
		$this->assertSame( Conflict_Policy::default(), $this->make_sub_plugin()->get_conflict_policy() );
	}

	/**
	 * The function-name cases are the ones that matter. is_callable() is true for any string
	 * naming an existing function, and a policy read from an option can easily be one, so a
	 * configured value that merely looks callable must still come back as the raw text. Invoking
	 * date() with no arguments would be a fatal on PHP 8.
	 *
	 * @dataProvider configured_strings
	 *
	 * @param string $policy Configured policy.
	 */
	public function test_it_returns_a_configured_conflict_policy( string $policy ): void {
		$this->assertSame(
			$policy,
			$this->make_sub_plugin( [ 'conflict_policy' => $policy ] )->get_conflict_policy()
		);
	}

	/**
	 * The last two are the ones a host is most likely to reach for: its own zero-argument
	 * `..._policy()` helper, and the `Class::method` spelling of the pair form that *is* honoured
	 * as an array. Both come back as raw text like every other string. `__return_empty_string`
	 * stands in for the helper because calling it is harmless and unmistakable -- an invoked case
	 * would return '', not the name.
	 *
	 * Both forms are callable as far as PHP is concerned. Barring them is what keeps a policy read
	 * from an option from depending on whether some plugin elsewhere on the site happens to have
	 * declared a function of that name.
	 *
	 * @return Generator<string,array{0:string}>
	 */
	public static function configured_strings(): Generator {
		yield 'ordinary text'           => [ 'Bundled now.' ];
		yield 'named policy'            => [ Conflict_Policy::DEFER ];
		yield 'php function name'       => [ 'date' ];
		yield 'wordpress function name' => [ 'flush' ];
		yield 'array pointer function'  => [ 'key' ];
		yield 'host helper name'        => [ '__return_empty_string' ];
		yield 'static method name'      => [ Deferred_Message::class . '::from_a_static_method' ];
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
			'The filter runs after the configured value, and wins.'
		);
	}

	public function test_the_filter_overrides_the_conflict_notice_message(): void {
		add_filter(
			'give/plugin_absorber/conflict_notice_message',
			static function () {
				return 'Filtered.';
			}
		);

		$this->assertSame(
			'Filtered.',
			$this->make_sub_plugin( [ 'conflict_notice_message' => static fn() => 'Configured.' ] )
				->get_conflict_notice_message()
		);
	}

	public function test_the_filter_overrides_the_dependency_notice_message(): void {
		add_filter(
			'give/plugin_absorber/dependency_notice_message',
			static function () {
				return 'Filtered.';
			}
		);

		$this->assertSame(
			'Filtered.',
			$this->make_sub_plugin( [ 'dependency_notice_message' => static fn() => 'Configured.' ] )
				->get_dependency_notice_message()
		);
	}

	/**
	 * Filtering last is what makes deferred translation possible, so the filter has to see the
	 * fallback text too -- not just a configured value it can only replace when one exists.
	 *
	 * @dataProvider unconfigured_messages
	 *
	 * @param string $hook     Message hook, less the prefix.
	 * @param string $expected Text the filter is expected to receive.
	 * @param string $getter   Method under test.
	 */
	public function test_the_message_filters_see_the_fallback( string $hook, string $expected, string $getter ): void {
		$received = null;

		add_filter(
			"give/plugin_absorber/{$hook}",
			static function ( $message ) use ( &$received ) {
				$received = $message;

				return $message;
			}
		);

		$this->make_sub_plugin()->{$getter}( 'Bundled now.' );

		$this->assertSame( $expected, $received );
	}

	/**
	 * @return Generator<string,array{0:string,1:string,2:string}>
	 */
	public static function unconfigured_messages(): Generator {
		yield 'the caller default' => [
			'conflict_notice_message',
			'Bundled now.',
			'get_conflict_notice_message',
		];
		yield 'the generic sentence' => [
			'dependency_notice_message',
			'give-recurring could not be loaded because its requirements are not met.',
			'get_dependency_notice_message',
		];
	}

	/**
	 * @dataProvider filtered_strings
	 *
	 * @param string $hook   Hook, less the prefix.
	 * @param string $getter Method under test.
	 */
	public function test_the_filters_receive_the_sub_plugin( string $hook, string $getter ): void {
		$received = null;

		add_filter(
			"give/plugin_absorber/{$hook}",
			static function ( $value, $passed ) use ( &$received ) {
				$received = $passed;

				return $value;
			},
			10,
			2
		);

		$sub_plugin = $this->make_sub_plugin();
		$sub_plugin->{$getter}();

		$this->assertSame( $sub_plugin, $received );
	}

	/**
	 * @dataProvider filtered_strings
	 *
	 * @param string $hook   Hook, less the prefix.
	 * @param string $getter Method under test.
	 */
	public function test_a_non_scalar_filter_return_yields_nothing( string $hook, string $getter ): void {
		add_filter(
			"give/plugin_absorber/{$hook}",
			static function () {
				return new \WP_Error( 'nope', 'Nope.' );
			}
		);

		$this->assertSame(
			'',
			$this->make_sub_plugin()->{$getter}(),
			'Casting an object would be a fatal; an empty string is simply not a valid value.'
		);
	}

	/**
	 * @dataProvider filtered_strings
	 *
	 * @param string $hook   Hook, less the prefix.
	 * @param string $getter Method under test.
	 */
	public function test_the_filtered_values_need_a_hook_prefix( string $hook, string $getter ): void {
		Config_State::reset();

		$this->expectException( Config_Exception::class );

		$this->make_sub_plugin()->{$getter}();
	}

	/**
	 * @return Generator<string,array{0:string,1:string}>
	 */
	public static function filtered_strings(): Generator {
		yield 'conflict_policy'           => [ 'conflict_policy', 'get_conflict_policy' ];
		yield 'conflict_notice_message'   => [ 'conflict_notice_message', 'get_conflict_notice_message' ];
		yield 'dependency_notice_message' => [ 'dependency_notice_message', 'get_dependency_notice_message' ];
	}

	public function test_the_conflict_notice_message_defaults_to_empty(): void {
		$this->assertSame( '', $this->make_sub_plugin()->get_conflict_notice_message() );
	}

	public function test_the_dependency_notice_message_falls_back_to_a_default(): void {
		$this->assertSame(
			'give-recurring could not be loaded because its requirements are not met.',
			$this->make_sub_plugin()->get_dependency_notice_message()
		);
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
			$this->make_sub_plugin( [ 'conflict_notice_message' => static fn() => 'Configured.' ] )
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
