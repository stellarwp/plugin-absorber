<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Unit;

use Codeception\TestCase\WPTestCase;
use Nexcess\PluginAbsorber\Contracts\Registrar_Interface;
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

	public function test_registering_the_same_slug_twice_lets_the_last_one_win(): void {
		$registrar = new Registrar();
		$first     = $this->make_sub_plugin( [ 'slug' => 'give-recurring' ] );
		$second    = $this->make_sub_plugin( [ 'slug' => 'give-recurring' ] );

		$registrar->register( $first );
		$registrar->register( $second );

		$this->assertCount( 1, $registrar->all() );
		$this->assertSame( $second, $registrar->all()['give-recurring'] );
	}

	/**
	 * Re-registering must update in place rather than move the entry to the end.
	 *
	 * A host registers all of its bundled sub-plugins in one routine at load, then runs that
	 * routine again for a single slug once something it could not know up front resolves — a
	 * licence check that came back, a setting saved in the admin. Moving that slug to the end
	 * puts it behind sub-plugins registered after it, and an add-on extending a class the moved
	 * sub-plugin defines would then load first and fatal.
	 */
	public function test_re_registering_keeps_the_original_position(): void {
		$registrar = new Registrar();

		$registrar->register( $this->make_sub_plugin( [ 'slug' => 'give-recurring' ] ) );
		$registrar->register( $this->make_sub_plugin( [ 'slug' => 'give-fee-recovery' ] ) );
		$registrar->register( $this->make_sub_plugin( [ 'slug' => 'give-recurring' ] ) );

		$this->assertSame(
			[ 'give-recurring', 'give-fee-recovery' ],
			array_keys( $registrar->all() )
		);
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
