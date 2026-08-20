<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Boot;

use Nexcess\PluginAbsorber\Absorber;
use Nexcess\PluginAbsorber\Conflict\Contracts\Resolver_Interface;
use Nexcess\PluginAbsorber\Conflict\Detector;
use Nexcess\PluginAbsorber\Conflict\Gatekeeper;
use Nexcess\PluginAbsorber\Exceptions\Config_Exception;
use Nexcess\PluginAbsorber\Loader;
use StellarWP\ContainerContract\ContainerInterface;
use Throwable;
use WP_Hook;

/**
 * When this library's work happens: which hook, at which priority, and what to do when it is
 * already too late to say so.
 *
 * Its own class because timing is the part of booting that changes for its own reasons — a step
 * added, a priority moved, a hook that fires too late to wire — and none of those are reasons to
 * touch registration or the load pass. The provider says how collaborators are built and this says
 * when they run, so neither has to be read to change the other.
 *
 * @since 1.0.0
 */
class Scheduler {
	/**
	 * plugins_loaded priority the load pass runs at.
	 *
	 * Ahead of the default priority, so a bundled plugin is in memory before the plugins that
	 * expect it start their own work.
	 *
	 * Every priority below this one is a band of the bundled plugin's *own* plugins_loaded
	 * callbacks that silently never fire: a standalone copy is included by wp-settings.php before
	 * the action is dispatched at all and keeps every callback it registers, while a bundled copy
	 * required from a callback here only keeps the ones above the priority that required it.
	 * Hooking plugins_loaded below the default is already a special case, so the band this gives
	 * up is a narrow one — but it is given up silently, which is why the number moves down when
	 * there is any doubt and not up.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	private const LOAD_PRIORITY = 6;

	/**
	 * plugins_loaded priority conflict resolution runs at, ahead of the load pass.
	 *
	 * A standalone that survives the conflict defines the guard constant as it loads, and the load
	 * pass has to see that, so resolution cannot share a priority with it.
	 *
	 * This is the number a host is measured against, not the load: it is the first step in the
	 * sequence, so it is the priority `set_container()` and `boot()` have to beat. At 1 the only
	 * slot left was 0, which made a documented convention a hard requirement — and both LearnDash
	 * and MemberDash wire Harbor's `set_container()` at priority 1, so a host copying the habit it
	 * already has landed exactly on the barrier and got the inline fallback instead of the hooks.
	 * Five slots ahead of it covers both habits with room over, and costs the load pass four
	 * priorities it was not using.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	private const RESOLVE_PRIORITY = 5;

	/**
	 * @since 1.0.0
	 *
	 * @var ContainerInterface
	 */
	private $container;

	/**
	 * @since 1.0.0
	 *
	 * @param ContainerInterface $container Container every step resolves from, when it runs.
	 */
	public function __construct( ContainerInterface $container ) {
		$this->container = $container;
	}

	/**
	 * Wire the WordPress hooks.
	 *
	 * Nothing is resolved here. Each step is a closure over the container that asks for its
	 * collaborator when the hook fires, so a host may still rebind one after boot() and up until
	 * plugins_loaded, and a binding nothing reaches is never built at all.
	 *
	 * Called too late, the steps run inline instead of being wired — and conflict resolution can
	 * end the request, so on an admin page load this call may not return. Boot before plugins_loaded
	 * priority 5, as documented, and it always does.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function wire(): void {
		if ( is_admin() ) {
			// all_admin_notices, not admin_notices. WordPress dispatches admin_notices,
			// network_admin_notices and user_admin_notices as mutually exclusive branches, so a
			// superadmin working in the network admin -- exactly where a network-wide
			// deactivation gets noticed -- would never see the queue rendered.
			add_action( 'all_admin_notices', [ Absorber::class, 'render_notices' ] );

			// A named static trampoline like the notice step above, not a closure. Both resolve
			// their collaborator when they fire, so neither builds anything at boot, but a named
			// callback can also be taken back with remove_filter() -- which matters most here, on
			// the one hook that rewrites a screen WordPress drew rather than adding one of our own.
			// The closures below are shaped that way for a reason these two do not share: the
			// plugins_loaded sequence has to be runnable inline as well as wirable.
			add_filter( 'wp_admin_notice_markup', [ Absorber::class, 'filter_activation_error_markup' ] );
		}

		// Adding an action at a priority the current dispatch has already passed is accepted and
		// then never fires. Booting from plugins_loaded at the default priority -- the commonest
		// hook mistake there is -- would otherwise mean nothing loads at all, with no warning and
		// a site that looks entirely healthy.
		if ( $this->wiring_window_has_closed() ) {
			_doing_it_wrong(
				Absorber::class . '::boot',
				'Absorber::boot() must run before plugins_loaded priority 5. Resolving and loading inline instead.',
				'1.0.0'
			);

			// In the order the hooks would have run them.
			foreach ( $this->sequence() as $step ) {
				$run = $step['run'];

				$run();
			}

			return;
		}

		foreach ( $this->sequence() as $step ) {
			add_action( 'plugins_loaded', $step['run'], $step['priority'] );
		}
	}

	/**
	 * The plugins_loaded steps, in run order, as priority and callback.
	 *
	 * Stated once because wire() expresses this order twice — as hook priorities when it can still
	 * wire, and as straight calls when it is too late to and has to run them inline. Those two are
	 * the same sequence, and a comment is the only thing that could hold them in agreement.
	 * Iterating one list cannot drift.
	 *
	 * A method rather than a constant because a step is a closure now: naming the step and giving
	 * its priority in two separate lists would put the drift straight back, one list deep.
	 *
	 * @since 1.0.0
	 *
	 * @return non-empty-array<int,array{priority:int,run:callable():void}>
	 */
	private function sequence(): array {
		$container = $this->container;

		return [
			[
				'priority' => self::RESOLVE_PRIORITY,
				'run'      => static function () use ( $container ): void {
					self::resolve_conflicts( $container );
				},
			],
			[
				'priority' => self::LOAD_PRIORITY,
				'run'      => static function () use ( $container ): void {
					self::load( $container );
				},
			],
		];
	}

	/**
	 * The conflict step: the two gates, the probe between them, and the resolve behind all three.
	 *
	 * Static, and handed the container rather than reading one, so the closure in sequence() stays a
	 * closure over the container like the load step beside it.
	 *
	 * @since 1.0.0
	 *
	 * @param ContainerInterface $container Container each collaborator is resolved from.
	 *
	 * @return void
	 */
	private static function resolve_conflicts( ContainerInterface $container ): void {
		try {
			$gatekeeper = $container->get( Gatekeeper::class );

			// The shape of the request first. It reads the request and nothing else, so cron, WP-CLI, a
			// POST and every front-end view are turned away having resolved no user and built no
			// resolver.
			if ( ! $gatekeeper->request_may_resolve() ) {
				return;
			}

			// Then whether there is a conflict at all, before anyone asks who is signed in.
			// current_user_can() resolves and caches the current user, and this step runs at
			// plugins_loaded priority 5 -- ahead of the plugins that add their determine_current_user
			// filter from a plugins_loaded callback of their own. Ask on every admin GET and an SSO or
			// JWT visitor is pinned as logged out for the rest of the request, on requests with nothing
			// to resolve. The detector reports and changes nothing, so the capability is only asked for
			// where its answer decides something.
			if ( ! $container->get( Detector::class )->has_conflict() ) {
				return;
			}

			if ( ! $gatekeeper->user_may_resolve() ) {
				return;
			}

			// Both gates and the probe live here rather than inside the resolver, so a host binding
			// its own cannot drop one by omission -- and asking them first means a resolver is built
			// only on the request that goes on to use it.
			$container->get( Resolver_Interface::class )->resolve_all();
		} catch ( Config_Exception $exception ) {
			// Reading the registry is where a duplicate slug surfaces, and this step reads it a
			// priority ahead of the load pass that has always guarded the same read. Named separately
			// from the catch below because it is the one failure here a developer can act on directly,
			// and the message says which.
			_doing_it_wrong(
				self::class,
				sprintf(
					'The registered sub-plugins could not be read, so no conflict was resolved: %s',
					$exception->getMessage()
				),
				'1.0.0'
			);
		} catch ( Throwable $thrown ) {
			// The backstop, and the promise the whole library rests on: plugins_loaded fires on every
			// request a site serves, so a throw out of a step is a white screen on all of them. What
			// reaches here is a collaborator a host's factory could not build, a gate, the probe, or a
			// resolver a host bound itself -- the passes guard their own per-sub-plugin loops, and this
			// guards everything the step touches on the way to them.
			self::report_a_step_that_threw( 'conflict pass', 'no conflict was resolved', $thrown );
		}
	}

	/**
	 * The load step.
	 *
	 * @since 1.0.0
	 *
	 * @param ContainerInterface $container Container the load pass is resolved from.
	 *
	 * @return void
	 */
	private static function load( ContainerInterface $container ): void {
		// Guarded like the conflict step, and for the same reason. `Loader::load_all()` already reports
		// per sub-plugin and carries on, so what is left for this to catch is the pass itself -- a
		// container that cannot build it above all, which is the shape a host's own broken binding
		// takes.
		try {
			$container->get( Loader::class )->load_all();
		} catch ( Throwable $thrown ) {
			self::report_a_step_that_threw( 'load pass', 'no sub-plugin was loaded', $thrown );
		}
	}

	/**
	 * Tell the developer which step was abandoned, and why.
	 *
	 * @since 1.0.0
	 *
	 * @param string    $step        Step that threw, named as the sequence names it.
	 * @param string    $consequence What the site got instead.
	 * @param Throwable $thrown      What came out of the step.
	 *
	 * @return void
	 */
	private static function report_a_step_that_threw( string $step, string $consequence, Throwable $thrown ): void {
		_doing_it_wrong(
			self::class,
			sprintf( 'The %s threw, so %s: %s', $step, $consequence, $thrown->getMessage() ),
			'1.0.0'
		);
	}

	/**
	 * Whether it is already too late to wire the first step of the sequence.
	 *
	 * Measured against the earliest priority in sequence(), read rather than restated, because a
	 * boot that can still wire a later step but has missed an earlier one has missed something —
	 * and with resolution at 5 and the load at 6, booting between the two is a real window.
	 *
	 * The comparison is inclusive. A callback added to the priority currently being dispatched is
	 * accepted and never reached either: WP_Hook::apply_filters() walks `$this->callbacks[$priority]`
	 * with a by-value foreach, so the append lands on an array the running loop has already copied.
	 * Booting from plugins_loaded at that priority is the case a host is likeliest to hit by
	 * accident, and an exclusive comparison would let exactly that one through unreported.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	private function wiring_window_has_closed(): bool {
		if ( ! did_action( 'plugins_loaded' ) ) {
			return false;
		}

		if ( ! doing_action( 'plugins_loaded' ) ) {
			return true;
		}

		$hook = $GLOBALS['wp_filter']['plugins_loaded'] ?? null;

		return $hook instanceof WP_Hook
			&& $hook->current_priority() >= min( array_column( $this->sequence(), 'priority' ) );
	}
}
