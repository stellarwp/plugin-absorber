<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Unit;

use Codeception\TestCase\WPTestCase;
use Nexcess\PluginAbsorber\Contracts\Registrar_Interface;
use Nexcess\PluginAbsorber\Exceptions\Config_Exception;
use Nexcess\PluginAbsorber\Registrar;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithSubPlugins;

/**
 * @since 1.0.0
 */
class RegistrarTest extends WPTestCase {
	use WithSubPlugins;

	public function test_it_satisfies_the_contract(): void {
		$this->assertInstanceOf( Registrar_Interface::class, new Registrar() );
	}

	public function test_it_starts_empty(): void {
		$this->assertSame( [], ( new Registrar() )->all() );
	}

	public function test_it_keys_registrations_by_slug(): void {
		$registrar  = new Registrar();
		$sub_plugin = $this->make_sub_plugin( [ 'slug' => 'give-recurring' ] );

		$registrar->register( $sub_plugin );

		$this->assertSame( [ 'give-recurring' => $sub_plugin ], $registrar->all() );
	}

	public function test_it_keeps_multiple_registrations_in_order(): void {
		$registrar = new Registrar();

		$registrar->register( $this->make_sub_plugin( [ 'slug' => 'give-recurring' ] ) );
		$registrar->register( $this->make_sub_plugin( [ 'slug' => 'give-fee-recovery' ] ) );

		$this->assertSame(
			[ 'give-recurring', 'give-fee-recovery' ],
			array_keys( $registrar->all() ),
			'The load path iterates this map, so a host that registers a dependency first must see it first.'
		);
	}

	public function test_it_rejects_a_slug_that_is_already_registered(): void {
		$registrar = new Registrar();
		$registrar->register( $this->make_sub_plugin( [ 'slug' => 'give-recurring' ] ) );

		$this->expectException( Config_Exception::class );

		$registrar->register( $this->make_sub_plugin( [ 'slug' => 'give-recurring' ] ) );
	}

	/**
	 * The collision is between two sub-plugins the reader cannot see from the stack trace: the
	 * registrations come from different code paths, and often from different host plugins. Naming
	 * both bundled files is what turns the fatal into a diagnosis.
	 */
	public function test_the_rejection_names_the_slug_and_both_bundled_files(): void {
		$registrar = new Registrar();
		$registrar->register(
			$this->make_sub_plugin(
				[
					'slug'                => 'give-recurring',
					'bundled_plugin_file' => '/give/vendor/bundled/give-recurring.php',
				]
			)
		);

		try {
			$registrar->register(
				$this->make_sub_plugin(
					[
						'slug'                => 'give-recurring',
						'bundled_plugin_file' => '/other/vendor/bundled/recurring.php',
					]
				)
			);
		} catch ( Config_Exception $exception ) {
			$this->assertStringContainsString( 'give-recurring', $exception->getMessage() );
			$this->assertStringContainsString( '/give/vendor/bundled/give-recurring.php', $exception->getMessage() );
			$this->assertStringContainsString( '/other/vendor/bundled/recurring.php', $exception->getMessage() );

			return;
		}

		$this->fail( 'register() accepted a duplicate slug instead of throwing.' );
	}

	public function test_a_rejected_registration_leaves_the_registry_untouched(): void {
		$registrar = new Registrar();
		$first     = $this->make_sub_plugin( [ 'slug' => 'give-recurring' ] );
		$registrar->register( $first );

		try {
			$registrar->register( $this->make_sub_plugin( [ 'slug' => 'give-recurring' ] ) );
		} catch ( Config_Exception $exception ) {
			$this->assertSame( [ 'give-recurring' => $first ], $registrar->all() );

			return;
		}

		$this->fail( 'register() accepted a duplicate slug instead of throwing.' );
	}

	/**
	 * A host that boots twice in one process re-runs its registration routine, so the guard has to
	 * live in the same state `reset()` clears or the second boot fatals.
	 */
	public function test_reset_lets_a_slug_be_registered_again(): void {
		$registrar = new Registrar();
		$registrar->register( $this->make_sub_plugin( [ 'slug' => 'give-recurring' ] ) );

		$registrar->reset();
		$registrar->register( $this->make_sub_plugin( [ 'slug' => 'give-recurring' ] ) );

		$this->assertCount( 1, $registrar->all() );
	}

	public function test_reset_empties_the_registry(): void {
		$registrar = new Registrar();
		$registrar->register( $this->make_sub_plugin( [ 'slug' => 'give-recurring' ] ) );

		$registrar->reset();

		$this->assertSame( [], $registrar->all() );
	}

	public function test_registrars_do_not_share_state(): void {
		$first  = new Registrar();
		$second = new Registrar();

		$first->register( $this->make_sub_plugin( [ 'slug' => 'give-recurring' ] ) );

		$this->assertSame( [], $second->all() );
	}
}
