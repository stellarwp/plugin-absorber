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
	 * The buffer is emptied before it is handed over, so a collision that aborted the hand-over would
	 * take everything registered behind the colliding entry with it: the buffered copies are gone, the
	 * registrar never saw them, and the pass's own catch reports only the duplicate. A host left with a
	 * sub-plugin that is simply absent, named by nothing anywhere, is the worse of the two failures.
	 */
	public function test_a_duplicate_slug_does_not_discard_the_registrations_behind_it(): void {
		$this->set_up_container();
		$this->register( 'give-recurring' );
		$this->register( 'give-recurring', '/tmp/give-recurring-again.php' );
		$this->register( 'give-fee-recovery' );

		$reader = $this->reader();

		try {
			$reader->all();
			$this->fail( 'Expected a Config_Exception naming the duplicated slug.' );
		} catch ( Config_Exception $exception ) {
			$this->assertStringContainsString( 'give-recurring', $exception->getMessage() );
			$this->assertStringContainsString(
				'/tmp/give-recurring-again.php',
				$exception->getMessage(),
				'The report has to name the registration that lost, or the host cannot find it.'
			);
		}

		$this->assertSame(
			[ 'give-recurring', 'give-fee-recovery' ],
			array_keys( $reader->all() ),
			'Everything registered after the collision has to reach the registrar regardless.'
		);
	}

	/**
	 * Two collisions in one buffer is one bootstrap mistake made twice, and the host reads the report
	 * from the top: the first is rethrown, and the second is what the next request reports once the
	 * first is fixed. Letting a later collision overwrite the first would move the report around
	 * between requests for no gain.
	 */
	public function test_it_reports_the_first_duplicate_when_more_than_one_collides(): void {
		$this->set_up_container();
		$this->register( 'give-recurring' );
		$this->register( 'give-recurring', '/tmp/give-recurring-again.php' );
		$this->register( 'give-fee-recovery' );
		$this->register( 'give-fee-recovery', '/tmp/give-fee-recovery-again.php' );

		$reader = $this->reader();

		try {
			$reader->all();
			$this->fail( 'Expected a Config_Exception naming the duplicated slug.' );
		} catch ( Config_Exception $exception ) {
			$this->assertStringContainsString( 'give-recurring', $exception->getMessage() );
			$this->assertStringNotContainsString(
				'give-fee-recovery',
				$exception->getMessage(),
				'The first collision is the one reported; a later one must not overwrite it.'
			);
		}

		$all = $reader->all();

		$this->assertSame( [ 'give-recurring', 'give-fee-recovery' ], array_keys( $all ) );
		$this->assertSame(
			'/tmp/give-fee-recovery.php',
			$all['give-fee-recovery']->get_bundled_plugin_file(),
			'The registration that arrived first under a slug is the one that stands.'
		);
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

			// On the message rather than on the type: the container wraps what a factory throws in
			// its own exception class, so the type says which container the test ran against while
			// the message is the only thing carrying the failure the host has to fix. Asserting the
			// flag alone would let an unrelated fault -- a typo in the binding, a missing class --
			// pass this test for the wrong reason.
			$this->assertStringContainsString(
				'the host factory needed a database connection',
				$exception->getMessage(),
				'The original failure has to stay readable, or the real cause is lost.'
			);
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
	 * @param string $file Bundled file to register with; derived from the slug when omitted. Two
	 *                     registrations under one slug need different files for the duplicate report
	 *                     to be able to tell them apart.
	 *
	 * @return void
	 */
	private function register( string $slug, string $file = '' ): void {
		Absorber::register(
			[
				'slug'                   => $slug,
				'bundled_plugin_file'    => $file !== '' ? $file : sprintf( '/tmp/%s.php', $slug ),
				'plugin_loaded_constant' => strtoupper( str_replace( '-', '_', $slug ) ) . '_VERSION_FIXTURE',
			]
		);
	}
}
