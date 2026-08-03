<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Unit;

use Codeception\TestCase\WPTestCase;
use Nexcess\PluginAbsorber\Contracts\Registrar_Interface;
use Nexcess\PluginAbsorber\Registrar;
use Nexcess\PluginAbsorber\Sub_Plugin;

/**
 * @since 1.0.0
 */
class RegistrarTest extends WPTestCase {
	/**
	 * @param string $slug Sub-plugin slug.
	 */
	private function make_sub_plugin( string $slug ): Sub_Plugin {
		return new Sub_Plugin(
			[
				'slug'                   => $slug,
				'bundled_plugin_file'    => "/tmp/{$slug}/{$slug}.php",
				'plugin_loaded_constant' => strtoupper( str_replace( '-', '_', $slug ) ) . '_VERSION',
			]
		);
	}

	public function test_it_satisfies_the_contract(): void {
		$this->assertInstanceOf( Registrar_Interface::class, new Registrar() );
	}

	public function test_it_starts_empty(): void {
		$this->assertSame( [], ( new Registrar() )->all() );
	}

	public function test_it_keys_registrations_by_slug(): void {
		$registrar  = new Registrar();
		$sub_plugin = $this->make_sub_plugin( 'give-recurring' );

		$registrar->register( $sub_plugin );

		$this->assertSame( [ 'give-recurring' => $sub_plugin ], $registrar->all() );
	}

	public function test_it_keeps_multiple_registrations_in_order(): void {
		$registrar = new Registrar();

		$registrar->register( $this->make_sub_plugin( 'give-recurring' ) );
		$registrar->register( $this->make_sub_plugin( 'give-fee-recovery' ) );

		$this->assertSame(
			[ 'give-recurring', 'give-fee-recovery' ],
			array_keys( $registrar->all() ),
			'The load path iterates this map, so a host that registers a dependency first must see it first.'
		);
	}

	public function test_registering_the_same_slug_twice_lets_the_last_one_win(): void {
		$registrar = new Registrar();
		$first     = $this->make_sub_plugin( 'give-recurring' );
		$second    = $this->make_sub_plugin( 'give-recurring' );

		$registrar->register( $first );
		$registrar->register( $second );

		$this->assertCount( 1, $registrar->all() );
		$this->assertSame( $second, $registrar->all()['give-recurring'] );
	}

	/**
	 * Re-registering must not move an entry to the end, or a host that conditionally re-registers
	 * would silently reorder the load.
	 */
	public function test_re_registering_keeps_the_original_position(): void {
		$registrar = new Registrar();

		$registrar->register( $this->make_sub_plugin( 'give-recurring' ) );
		$registrar->register( $this->make_sub_plugin( 'give-fee-recovery' ) );
		$registrar->register( $this->make_sub_plugin( 'give-recurring' ) );

		$this->assertSame(
			[ 'give-recurring', 'give-fee-recovery' ],
			array_keys( $registrar->all() )
		);
	}

	public function test_reset_empties_the_registry(): void {
		$registrar = new Registrar();
		$registrar->register( $this->make_sub_plugin( 'give-recurring' ) );

		$registrar->reset();

		$this->assertSame( [], $registrar->all() );
	}

	public function test_registrars_do_not_share_state(): void {
		$first  = new Registrar();
		$second = new Registrar();

		$first->register( $this->make_sub_plugin( 'give-recurring' ) );

		$this->assertSame( [], $second->all() );
	}
}
