<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Boot;

use Nexcess\PluginAbsorber\Absorber;
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
	 * expect it start their own work, and low enough to leave room for earlier wiring.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	private const LOAD_PRIORITY = 2;

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
		}

		// Adding an action at a priority the current dispatch has already passed is accepted and
		// then never fires. Booting from plugins_loaded at the default priority instead of 0 --
		// the commonest hook mistake there is -- would otherwise mean nothing loads at all, with
		// no warning and a site that looks entirely healthy.
		if ( $this->wiring_window_has_closed() ) {
			_doing_it_wrong(
				Absorber::class . '::boot',
				'Absorber::boot() must run before plugins_loaded priority 2. Loading inline instead.',
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
	 * @return array<int,array{priority:int,run:callable():void}>
	 */
	private function sequence(): array {
		$container = $this->container;

		return [
			[
				'priority' => self::LOAD_PRIORITY,
				'run'      => static function () use ( $container ): void {
					self::load( $container );
				},
			],
		];
	}

	/**
	 * The load step.
	 *
	 * Static, and handed the container rather than reading one, so the closure in sequence() stays a
	 * closure over the container.
	 *
	 * @since 1.0.0
	 *
	 * @param ContainerInterface $container Container the load pass is resolved from.
	 *
	 * @return void
	 */
	private static function load( ContainerInterface $container ): void {
		// plugins_loaded fires on every request a site serves, so a throw out of this step is a white
		// screen on all of them. `Loader::load_all()` already reports per sub-plugin and carries on,
		// so what is left for this to catch is the pass itself -- a container that cannot build it
		// above all, which is the shape a host's own broken binding takes.
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
	 * Whether it is already too late to wire the load hook.
	 *
	 * The comparison is inclusive. A callback added to the priority currently being dispatched is
	 * accepted and never reached either: WP_Hook::apply_filters() walks `$this->callbacks[$priority]`
	 * with a by-value foreach, so the append lands on an array the running loop has already copied.
	 * Booting from plugins_loaded at priority 2 is the case a host is likeliest to hit by accident,
	 * and an exclusive comparison would let exactly that one through unreported.
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

		return $hook instanceof WP_Hook && $hook->current_priority() >= self::LOAD_PRIORITY;
	}
}
