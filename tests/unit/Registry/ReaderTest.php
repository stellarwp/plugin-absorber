<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Tests\Unit\Registry;

use Codeception\TestCase\WPTestCase;
use Nexcess\PluginAbsorber\Absorber;
use Nexcess\PluginAbsorber\Config;
use Nexcess\PluginAbsorber\Registry\Contracts\Registrar_Interface;
use Nexcess\PluginAbsorber\Registry\Reader;
use Nexcess\PluginAbsorber\Sub_Plugin;
use Nexcess\PluginAbsorber\Tests\Support\Absorber_State;
use Nexcess\PluginAbsorber\Tests\Support\Config_State;
use Nexcess\PluginAbsorber\Tests\Support\Spy_Registrar;
use Nexcess\PluginAbsorber\Tests\Support\Test_Container;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithContainer;
use Nexcess\PluginAbsorber\Tests\Support\Traits\WithIncorrectUsage;
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
	use WithIncorrectUsage;

	/**
	 * Every `_doing_it_wrong()` message this test recorded for itself.
	 *
	 * `WithIncorrectUsage` asserts that a report was made and what it said; this counts how many times
	 * it was said, which is the difference between reporting a collision as it is discovered and
	 * reporting it again at every read.
	 *
	 * @var string[]
	 */
	private $reports = [];

	/**
	 * @var callable|null
	 */
	private $report_recorder = null;

	public function setUp(): void {
		parent::setUp();

		Absorber_State::reset();
		Config_State::reset();
		Config::set_hook_prefix( 'give' );
	}

	public function tearDown(): void {
		$this->stop_recording_reports();
		$this->stop_expecting_incorrect_usage();
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
	 * The one bootstrap mistake that can still arrive at read time: a slug is only found to be a
	 * duplicate when the buffer reaches the registrar, which is a read after both `register()` calls
	 * have returned.
	 *
	 * It is reported, and it goes no further. Raised as an exception it decided what the whole pass
	 * did — the load pass loading nothing at all, the conflict pass resolving nothing — over a
	 * registry that was intact and readable the entire time. The read answers with what the registrar
	 * legitimately holds, and the registration that arrived first under the slug is the one that
	 * stands.
	 */
	public function test_a_duplicate_slug_is_reported_from_the_read_rather_than_thrown(): void {
		$this->set_up_container();
		$this->register( 'give-recurring' );
		$this->register( 'give-recurring', '/tmp/give-recurring-again.php' );

		$this->expect_incorrect_usage();

		$all = $this->reader()->all();

		$this->assertSame( [ 'give-recurring' ], array_keys( $all ) );
		$this->assertSame(
			'/tmp/give-recurring.php',
			$all['give-recurring']->get_bundled_plugin_file(),
			'The registration that arrived first under a slug is the one that stands.'
		);
		$this->assert_the_library_reported_incorrect_usage_saying(
			'Two sub-plugins are registered under the slug "give-recurring"',
			'The collision is what failed, and the report has to say so rather than name some other gate.'
		);
		$this->assert_the_library_reported_incorrect_usage_saying(
			'/tmp/give-recurring-again.php',
			'The report has to name the registration that lost, or the host cannot find it.'
		);
		$this->assert_the_library_reported_incorrect_usage_saying(
			'/tmp/give-recurring-again.php was discarded',
			'Naming both files says nothing about which of them the site is running; the report has'
				. ' to say which registration was dropped.'
		);
	}

	/**
	 * A duplicate slug is reported through `_doing_it_wrong()`, which prints nothing on a production
	 * site, so it is also announced — and the sub-plugin it announces is the registration that was
	 * refused, not the one that stands. A listener is being told which object was thrown away; the
	 * one the registrar kept is readable from every other path there is.
	 */
	public function test_a_duplicate_slug_announces_the_registration_that_was_refused(): void {
		$this->set_up_container();
		$this->register( 'give-recurring' );
		$this->register( 'give-recurring', '/tmp/give-recurring-again.php' );

		$this->expect_incorrect_usage();

		$announced = [];

		add_action(
			'give/plugin_absorber/error',
			static function ( $message, $sub_plugin ) use ( &$announced ): void {
				$announced[] = $sub_plugin;
			},
			10,
			2
		);

		$this->reader()->all();

		$this->assertCount( 1, $announced );

		$refused = $announced[0];

		$this->assertInstanceOf( Sub_Plugin::class, $refused );
		$this->assertSame(
			'/tmp/give-recurring-again.php',
			$refused->get_bundled_plugin_file(),
			'The announcement has to carry the registration that lost, or a listener cannot find it.'
		);
	}

	/**
	 * The buffer is emptied before it is handed over, so a collision that aborted the hand-over would
	 * take everything registered behind the colliding entry with it: the buffered copies are gone, the
	 * registrar never saw them, and the read reports only the duplicate. A host left with a sub-plugin
	 * that is simply absent, named by nothing anywhere, is the worse of the two failures.
	 */
	public function test_a_duplicate_slug_does_not_discard_the_registrations_behind_it(): void {
		$this->set_up_container();
		$this->register( 'give-recurring' );
		$this->register( 'give-recurring', '/tmp/give-recurring-again.php' );
		$this->register( 'give-fee-recovery' );

		$this->expect_incorrect_usage();

		$this->assertSame(
			[ 'give-recurring', 'give-fee-recovery' ],
			array_keys( $this->reader()->all() ),
			'Everything registered after the collision has to reach the registrar regardless.'
		);
		$this->assert_the_library_reported_incorrect_usage_saying(
			'Two sub-plugins are registered under the slug "give-recurring"',
			'The registrations behind the collision surviving must not cost the collision its report.'
		);
	}

	/**
	 * The read that comes after the one that found the collision, which is every request in wp-admin:
	 * the conflict pass reads at `plugins_loaded` priority 5 and the load pass reads again at 6. A
	 * collision that emptied the registry, or that only the first reader could see, left the pass
	 * behind it with nothing to work on — the load pass loading none of the site's bundled plugins
	 * while the registry sat there readable.
	 */
	public function test_a_second_read_still_answers_with_the_whole_registry(): void {
		$this->set_up_container();
		$this->register( 'give-recurring' );
		$this->register( 'give-recurring', '/tmp/give-recurring-again.php' );
		$this->register( 'give-fee-recovery' );

		$this->expect_incorrect_usage();

		$reader = $this->reader();

		$this->assertSame( [ 'give-recurring', 'give-fee-recovery' ], array_keys( $reader->all() ) );
		$this->assertSame(
			[ 'give-recurring', 'give-fee-recovery' ],
			array_keys( $reader->all() ),
			'A pass reading behind the one that found the collision gets the same registry, not an empty one.'
		);
		$this->assert_the_library_reported_incorrect_usage();
	}

	/**
	 * Reported as it is discovered, and the buffer only drains once, so the two passes of one admin
	 * request print one sentence between them rather than each printing the same one. Reporting again
	 * at every read would cost the host a duplicate line per pass and per activation-error rewrite,
	 * for a mistake they have already been told about, and would buy nothing: registration runs at
	 * plugin-file scope, so the next request finds the collision and reports it again anyway.
	 */
	public function test_a_duplicate_is_reported_once_rather_than_at_every_read(): void {
		$this->set_up_container();
		$this->register( 'give-recurring' );
		$this->register( 'give-recurring', '/tmp/give-recurring-again.php' );

		$this->expect_incorrect_usage();
		$this->record_reports();

		$reader = $this->reader();

		$reader->all();

		// The recorder catching the first report is what makes the assertion after the second read
		// mean anything: a recorder that never attached would count nothing either way.
		$this->assertCount( 1, $this->reports, 'The read that drains the buffer is the read that reports.' );

		$reader->all();

		$this->assertCount( 1, $this->reports, 'A read with nothing left to drain has nothing left to report.' );
	}

	/**
	 * Two collisions in one buffer is two mistakes, each naming a slug of its own, and each is
	 * reported. Rationing the report to the first was all a single rethrown exception could carry;
	 * nothing rations it now, and hiding the second would only mean the host fixes one duplicate and
	 * meets the next on the following request.
	 */
	public function test_it_reports_every_duplicate_when_more_than_one_collides(): void {
		$this->set_up_container();
		$this->register( 'give-recurring' );
		$this->register( 'give-recurring', '/tmp/give-recurring-again.php' );
		$this->register( 'give-fee-recovery' );
		$this->register( 'give-fee-recovery', '/tmp/give-fee-recovery-again.php' );

		$this->expect_incorrect_usage();
		$this->record_reports();

		$all = $this->reader()->all();

		$this->assertSame( [ 'give-recurring', 'give-fee-recovery' ], array_keys( $all ) );
		$this->assertSame(
			'/tmp/give-fee-recovery.php',
			$all['give-fee-recovery']->get_bundled_plugin_file(),
			'The registration that arrived first under a slug is the one that stands.'
		);
		$this->assertCount( 2, $this->reports, 'Each collision is a mistake of its own to correct.' );
		$this->assert_the_library_reported_incorrect_usage_saying(
			'Two sub-plugins are registered under the slug "give-recurring"',
			'The first collision has to be reported.'
		);
		$this->assert_the_library_reported_incorrect_usage_saying(
			'Two sub-plugins are registered under the slug "give-fee-recovery"',
			'And the second, which a report rationed to the first would have hidden.'
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
	 * Count the library's reports for this test, so "reported once" can be told from "reported at
	 * every read".
	 *
	 * A recorder of this test's own rather than a reach into `WithIncorrectUsage`: that trait asserts
	 * that a report was made and what it said, which is a different question from how often.
	 *
	 * @return void
	 */
	private function record_reports(): void {
		$reports = &$this->reports;

		// Static, and closing over a reference: a closure left on a hook outlives the test object, and
		// `$this` inside one WordPress calls back is not this test.
		$recorder = static function ( $function_name, $message = '' ) use ( &$reports ): void {
			$reports[] = is_string( $message ) ? $message : '';
		};

		$this->report_recorder = $recorder;

		add_action( 'doing_it_wrong_run', $recorder, 10, 2 );
	}

	/**
	 * Take the recorder back off, by identity: the rest of the suite is on this hook too.
	 *
	 * @return void
	 */
	private function stop_recording_reports(): void {
		if ( $this->report_recorder !== null ) {
			remove_action( 'doing_it_wrong_run', $this->report_recorder );

			$this->report_recorder = null;
		}

		$this->reports = [];
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
