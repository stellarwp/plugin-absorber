<?php
/**
 * The host's own wiring, driven end to end.
 *
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Tests\Unit\Scenario;

use Nexcess\PluginAbsorber\Conflict\Contracts\Resolver_Interface;
use Nexcess\PluginAbsorber\Conflict\Gatekeeper;
use Nexcess\PluginAbsorber\Conflict_Policy;
use Nexcess\PluginAbsorber\Contracts\Activator_Interface;
use Nexcess\PluginAbsorber\Contracts\Plugin_Checker_Interface;
use Nexcess\PluginAbsorber\Contracts\Plugin_Deactivator_Interface;
use Nexcess\PluginAbsorber\Notices\Contracts\Writer_Interface;
use Nexcess\PluginAbsorber\Registry\Contracts\Registrar_Interface;
use Nexcess\PluginAbsorber\Tests\Support\Spy_Activator;
use Nexcess\PluginAbsorber\Tests\Support\Spy_Gatekeeper;
use Nexcess\PluginAbsorber\Tests\Support\Spy_Registrar;
use Nexcess\PluginAbsorber\Tests\Support\Spy_Resolver;
use Nexcess\PluginAbsorber\Tests\Support\Spy_Writer;
use Nexcess\PluginAbsorber\Tests\Support\Test_Container;

/**
 * The two halves of the bootstrap a host owns: when it boots, and what it boots with.
 *
 * Every other scenario file is about what the library does once it is running. This one is about the
 * host's own wiring — that `Absorber::boot()` survives being called after the window to wire has
 * closed and still leaves the site with its bundled plugins, and that the implementations a host
 * bound into the container are the objects the request actually reaches, rather than defaults the
 * library resolved behind its back.
 *
 * @since 1.0.0
 */
class HostTest extends Bootstrap_Test_Case {
	/**
	 * Booting from plugins_loaded at the default priority is the commonest hook mistake there is, and
	 * an add_action() at a priority the running dispatch has already passed is accepted and then never
	 * fires. The library reports the mistake and runs the sequence inline, so the site the host
	 * shipped still gets its bundled plugins.
	 */
	public function test_a_host_that_boots_too_late_still_gets_its_sub_plugins(): void {
		$this->expect_incorrect_usage();

		$constant = $this->register();

		$this->add_tracked_action(
			'plugins_loaded',
			function (): void {
				$this->boot();
			}
		);

		$this->run_request();

		$this->assertSame( 1, $this->bundled_plugin_loads(), 'A late boot must still load.' );
		$this->assertTrue( defined( $constant ) );
		$this->assert_the_library_reported_incorrect_usage();
	}

	/**
	 * The whole point of a required container: a host binds its own implementation of an interface
	 * before boot, and that is the object the library uses for the rest of the request. Every id here
	 * is an interface, which is what makes binding first enough — nothing can build one unprompted, so
	 * `Provider` sees the host's binding and stands down. The concrete-class half of that rule is the
	 * scenario below.
	 *
	 * Two requests, because a deactivation ends the first one where production ends it: the resolver
	 * redirects so that the standalone's code is out of memory, and the load pass runs on the request
	 * after. The host's checker reports the standalone gone on that second one, exactly as the default
	 * reading `active_plugins` would. The defaults are asserted *not* to have run alongside the
	 * doubles — a library that resolved a second copy of the notice writer or the deactivator behind
	 * the host's back would satisfy every positive assertion here.
	 */
	public function test_a_host_binding_reaches_every_step_of_the_request(): void {
		$registrar = new Spy_Registrar();
		$writer    = new Spy_Writer();
		$activator = new Spy_Activator();

		$checker = new class() implements Plugin_Checker_Interface {
			/**
			 * Basenames this checker reports as active.
			 *
			 * Writable, because the standalone really does go away between the two requests below and a
			 * checker that never noticed would resolve the same conflict for ever.
			 *
			 * @var string[]
			 */
			public $active = [];

			/**
			 * @var string[]
			 */
			public $basenames = [];

			/**
			 * @param string $basename Plugin basename.
			 *
			 * @return bool
			 */
			public function is_active( string $basename ): bool {
				$this->basenames[] = $basename;

				return in_array( $basename, $this->active, true );
			}
		};

		$deactivator = new class() implements Plugin_Deactivator_Interface {
			/**
			 * @var string[]
			 */
			public $basenames = [];

			/**
			 * @param string $basename Plugin basename.
			 *
			 * @return void
			 */
			public function deactivate( string $basename ): void {
				$this->basenames[] = $basename;
			}
		};

		$checker->active = [ self::STANDALONE ];

		// Really active, so that the default deactivator would have emptied this option had it been
		// the one reached. Nothing else in this test would notice the difference.
		update_option( 'active_plugins', [ self::STANDALONE ] );

		$container = new Test_Container();
		$container->singleton(
			Registrar_Interface::class,
			static function () use ( $registrar ): Registrar_Interface {
				return $registrar;
			}
		);
		$container->singleton(
			Plugin_Checker_Interface::class,
			static function () use ( $checker ): Plugin_Checker_Interface {
				return $checker;
			}
		);
		$container->singleton(
			Plugin_Deactivator_Interface::class,
			static function () use ( $deactivator ): Plugin_Deactivator_Interface {
				return $deactivator;
			}
		);
		$container->singleton(
			Writer_Interface::class,
			static function () use ( $writer ): Writer_Interface {
				return $writer;
			}
		);
		$container->singleton(
			Activator_Interface::class,
			static function () use ( $activator ): Activator_Interface {
				return $activator;
			}
		);

		$this->register(
			[
				'standalone_plugin_basename' => self::STANDALONE,
				'conflict_policy'            => Conflict_Policy::DEACTIVATE,
				'activation_callback'        => static fn() => null,
			]
		);

		$this->boot( $container );

		// The conflict step, which ends where production ends it.
		$this->run_halted_request();

		$this->assertSame( [ self::STANDALONE ], $deactivator->basenames, 'The host deactivator is the one asked to turn it off.' );
		$this->assertSame( [ self::SLUG ], $writer->merge_notices, 'The host notice writer is told what happened.' );

		// What the host's own deactivator did, as far as its own checker is concerned. Nothing is
		// re-registered between the two — this is the next page view, not a second bootstrap.
		$checker->active = [];

		$this->run_request();

		$this->assertSame( [ self::SLUG ], array_keys( $registrar->sub_plugins ), 'The host registrar holds the registration.' );
		$this->assertSame(
			[ self::STANDALONE ],
			array_values( array_unique( $checker->basenames ) ),
			'The host checker answers whether the standalone is active, and is asked about nothing else.'
		);
		$this->assertSame( [ self::SLUG ], $activator->slugs, 'The host activator runs the one-time setup.' );
		$this->assertSame( 1, $this->bundled_plugin_loads() );

		$this->assertContains( self::STANDALONE, $this->active_plugins(), 'The default deactivator must not have run too.' );
		$this->assertSame( [], $this->queued_notices(), 'The default writer must not have been resolved alongside it.' );
		$this->assertSame( [], $this->activation_record(), 'The default activator must not have recorded anything.' );
	}

	/**
	 * The same guarantee for the two the conflict step resolves itself, and the two rules a host has
	 * to follow to get it.
	 *
	 * An interface id goes in *before* boot: nothing can build an interface unprompted, so `has()`
	 * answers it only where a binding exists and `Provider` leaves the host's object alone. A concrete
	 * class id goes in *after* boot, because di52 answers `has()` true for any class that exists
	 * whether or not anything was bound — the provider cannot tell the host's binding apart from the
	 * container's willingness to autowire, and binds over it. After boot is not too late, because
	 * `Boot\Scheduler` wires closures that resolve their collaborator when the hook fires rather than
	 * at boot; the lazy wiring is what leaves this window open at all.
	 *
	 * A host owns what a conflict means — but not who may have one resolved, which is why the gate is
	 * asked first and separately, and both halves of it are asserted here to have been asked at all.
	 */
	public function test_a_host_binding_replaces_the_gatekeeper_and_the_resolver(): void {
		$gatekeeper = new Spy_Gatekeeper( true );
		$resolver   = new Spy_Resolver();

		update_option( 'active_plugins', [ self::STANDALONE ] );

		$container = new Test_Container();
		$container->singleton(
			Resolver_Interface::class,
			static function () use ( $resolver ): Resolver_Interface {
				return $resolver;
			}
		);

		$this->register(
			[
				'standalone_plugin_basename' => self::STANDALONE,
				'conflict_policy'            => Conflict_Policy::DEACTIVATE,
			]
		);

		$this->boot( $container );

		// After boot, and the only place this one can go: bound first it would be replaced by the
		// provider's own default, and the test would assert against a spy nothing ever reached.
		$container->singleton(
			Gatekeeper::class,
			static function () use ( $gatekeeper ): Gatekeeper {
				return $gatekeeper;
			}
		);

		$this->run_request();

		$this->assertSame(
			1,
			$gatekeeper->request_may_resolve_calls,
			'The conflict step has to ask the gate before it resolves anything.'
		);
		$this->assertSame(
			1,
			$gatekeeper->user_may_resolve_calls,
			'And the capability half of it, once a conflict is known to exist.'
		);
		$this->assertSame( 1, $resolver->resolve_calls );
		$this->assertContains(
			self::STANDALONE,
			$this->active_plugins(),
			'A host resolver that does nothing means nothing is deactivated.'
		);
		$this->assertSame( [], $this->queued_notices() );
		$this->assertSame( 1, $this->bundled_plugin_loads(), 'The load pass still runs after it.' );
	}
}
