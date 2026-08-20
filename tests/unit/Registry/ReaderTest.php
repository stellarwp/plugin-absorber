<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Tests\Unit\Registry;

use Codeception\TestCase\WPTestCase;
use Nexcess\PluginAbsorber\Absorber;
use Nexcess\PluginAbsorber\Config;
use Nexcess\PluginAbsorber\Exceptions\Config_Exception;
use Nexcess\PluginAbsorber\Registry\Contracts\Registrar_Interface;
use Nexcess\PluginAbsorber\Registry\Reader;
use Nexcess\PluginAbsorber\Sub_Plugin;
use Nexcess\PluginAbsorber\Tests\Support\Absorber_State;
use Nexcess\PluginAbsorber\Tests\Support\Config_State;
use Nexcess\PluginAbsorber\Tests\Support\Spy_Registrar;
use Nexcess\PluginAbsorber\Tests\Support\Test_Container;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithContainer;
use RuntimeException;
use Throwable;

/**
 * The one collaborator that reaches the registration buffer, so that no other one has to.
 *
 * Everything asserted here is behaviour `Absorber::all()` already had — the flush, the registrar
 * behind it, the failure it reports. What is new is who can be handed it: a pass that reads the
 * registry now declares this object in its constructor instead of naming a static, which is why
 * `AbsorberTest` still owns the buffer's own edge cases and this file only pins that they arrive
 * through an instance.
 *
 * @since 1.0.0
 */
class ReaderTest extends WPTestCase {
	use WithContainer;

	public function setUp(): void {
		parent::setUp();

		Absorber_State::reset();
		Config_State::reset();
		Config::set_hook_prefix( 'give' );
	}

	public function tearDown(): void {
		Absorber_State::reset();
		Config_State::reset();
		$this->tear_down_container();
		parent::tearDown();
	}

	public function test_it_reads_every_registered_sub_plugin(): void {
		$this->set_up_container();
		$this->register( 'give-recurring' );
		$this->register( 'give-fee-recovery' );

		$all = $this->reader()->all();

		$this->assertSame( [ 'give-recurring', 'give-fee-recovery' ], array_keys( $all ) );
		$this->assertInstanceOf( Sub_Plugin::class, $all['give-recurring'] );
	}

	public function test_it_is_empty_before_anything_is_registered(): void {
		$this->set_up_container();

		$this->assertSame( [], $this->reader()->all() );
	}

	/**
	 * The whole reason a pass is handed this rather than a registrar. Registration is buffered on the
	 * facade until something reads, so a collaborator holding the registrar itself would miss every
	 * sub-plugin registered since the last read — a host registering from its own `plugins_loaded`
	 * callback included.
	 */
	public function test_it_flushes_what_was_registered_since_the_last_read(): void {
		$this->set_up_container();
		$this->register( 'give-recurring' );

		$reader = $this->reader();

		$this->assertSame( [ 'give-recurring' ], array_keys( $reader->all() ) );

		$this->register( 'give-fee-recovery' );

		$this->assertSame(
			[ 'give-recurring', 'give-fee-recovery' ],
			array_keys( $reader->all() ),
			'A read has to drain the buffer as it finds it, not as it found it the first time.'
		);
	}

	/**
	 * The registrar stays the single source of truth: a host that bound its own is read by this, and
	 * does not have to know the passes exist.
	 */
	public function test_it_reads_through_the_bound_registrar(): void {
		$registrar = new Spy_Registrar();
		$container = new Test_Container();
		$container->singleton(
			Registrar_Interface::class,
			static function () use ( $registrar ): Registrar_Interface {
				return $registrar;
			}
		);
		$this->set_up_container( $container );

		$this->register( 'give-recurring' );

		$this->assertSame( [ 'give-recurring' ], array_keys( $this->reader()->all() ) );
		$this->assertArrayHasKey( 'give-recurring', $registrar->sub_plugins );
	}

	/**
	 * The one bootstrap mistake that can still arrive at read time, and the reason both passes catch
	 * `Config_Exception` around their read: a slug is only found to be a duplicate when the buffer
	 * reaches the registrar, which is a read after both `register()` calls have returned.
	 *
	 * The container is not the other half of that any more. It is needed to *build* a reader, not to
	 * read from one — the registrar arrives as a constructor argument, so a reader that exists has
	 * one, and a container that could not supply it failed while this object was being built.
	 */
	public function test_a_duplicate_slug_surfaces_from_the_read(): void {
		$this->set_up_container();
		$this->register( 'give-recurring' );
		$this->register( 'give-recurring' );

		$reader = $this->reader();

		$this->expectException( Config_Exception::class );

		$reader->all();
	}

	/**
	 * A registrar the container cannot build leaves the registrations where they are: the reader is
	 * what drains the buffer, and it is never built at all if its own argument cannot be.
	 */
	public function test_registrations_survive_a_registrar_the_container_cannot_build(): void {
		$container = new Test_Container();
		$container->singleton(
			Registrar_Interface::class,
			static function (): Registrar_Interface {
				throw new RuntimeException( 'the host factory needed a database connection' );
			}
		);
		$this->set_up_container( $container );

		$this->register( 'give-recurring' );

		$failed = false;

		try {
			$this->reader();
		} catch ( Throwable $exception ) {
			$failed = true;
		}

		$this->assertTrue( $failed, 'A registrar that cannot be built has to surface, not be swallowed.' );

		$this->set_up_container();

		$this->assertSame(
			[ 'give-recurring' ],
			array_keys( $this->reader()->all() ),
			'The buffered registration must still be there once the container is usable.'
		);
	}

	/**
	 * The reader the container builds, which is the one every pass is handed.
	 *
	 * @return Reader
	 */
	private function reader(): Reader {
		return $this->resolve( Reader::class );
	}

	/**
	 * @param string $slug Slug to register under.
	 *
	 * @return void
	 */
	private function register( string $slug ): void {
		Absorber::register(
			[
				'slug'                   => $slug,
				'bundled_plugin_file'    => sprintf( '/tmp/%s.php', $slug ),
				'plugin_loaded_constant' => strtoupper( str_replace( '-', '_', $slug ) ) . '_VERSION_FIXTURE',
			]
		);
	}
}
