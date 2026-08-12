<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Conflict;

use Nexcess\PluginAbsorber\Absorber;
use Nexcess\PluginAbsorber\Conflict\Contracts\Resolver_Interface;
use Nexcess\PluginAbsorber\Conflict_Policy;
use Nexcess\PluginAbsorber\Contracts\Plugin_Deactivator_Interface;
use Nexcess\PluginAbsorber\Exceptions\Config_Exception;
use Nexcess\PluginAbsorber\Notices\Contracts\Queue_Interface;
use Nexcess\PluginAbsorber\Sub_Plugin;

/**
 * Default conflict resolution: act on each conflicting sub-plugin per its policy.
 *
 * Every peer is a required constructor argument, so this object is complete the moment it exists and
 * a test can hand it four doubles instead of standing up global state. Finding the conflict and
 * turning the standalone off arrive separately because they are separate jobs: `Detector` answers
 * whether a standalone is in the way, and a host that wants deactivation to be a no-op rebinds
 * `Plugin_Deactivator_Interface` without touching how detection works.
 *
 * Neither of the two questions asked ahead of this class is asked here. `Detector` reports that
 * there is something to resolve and `Gatekeeper` decides who may have it resolved, and the conflict
 * step asks both before this class is built at all.
 *
 * Every conflicting sub-plugin is resolved before anyone is sent anywhere. The redirect is one
 * event for the request, not one per plugin.
 *
 * @since 1.0.0
 */
class Resolver implements Resolver_Interface {
	/**
	 * @since 1.0.0
	 *
	 * @var Detector
	 */
	private $detector;

	/**
	 * @since 1.0.0
	 *
	 * @var Plugin_Deactivator_Interface
	 */
	private $plugin_deactivator;

	/**
	 * @since 1.0.0
	 *
	 * @var Queue_Interface
	 */
	private $notices;

	/**
	 * @since 1.0.0
	 *
	 * @var Redirector
	 */
	private $redirector;

	/**
	 * @since 1.0.0
	 *
	 * @param Detector                     $detector           Whether a sub-plugin is in conflict.
	 * @param Plugin_Deactivator_Interface $plugin_deactivator Turns the standalone off.
	 * @param Queue_Interface              $notices            Where the user is told what happened.
	 * @param Redirector                   $redirector         Where the user lands afterwards.
	 */
	public function __construct(
		Detector $detector,
		Plugin_Deactivator_Interface $plugin_deactivator,
		Queue_Interface $notices,
		Redirector $redirector
	) {
		$this->detector           = $detector;
		$this->plugin_deactivator = $plugin_deactivator;
		$this->notices            = $notices;
		$this->redirector         = $redirector;
	}

	/**
	 * @since 1.0.0
	 *
	 * @throws Config_Exception When no hook prefix has been set, or a container binding is unusable.
	 *
	 * @return void
	 */
	public function resolve_all(): void {
		$deactivated = false;

		// Absorber::all() rather than a registrar of our own: it flushes the pending registrations
		// before it reads, and a registrar asked directly would not see anything registered since
		// the last read.
		foreach ( Absorber::all() as $sub_plugin ) {
			if ( ! $this->detector->is_in_conflict( $sub_plugin ) ) {
				continue;
			}

			if ( $this->resolve( $sub_plugin ) ) {
				$deactivated = true;
			}
		}

		// After the loop, never inside it. A site bundling two sub-plugins can have both standalones
		// active, and an `exit` on the first would leave the second's standalone running with no
		// notice raised about it — and would take the load pass at the next priority with it, so
		// nothing bundled loaded on the request that was supposed to fix the conflict.
		if ( $deactivated ) {
			$this->redirect();
		}
	}

	/**
	 * @since 1.0.0
	 *
	 * @param Sub_Plugin $sub_plugin Sub-plugin whose standalone is active.
	 *
	 * @throws Config_Exception When no hook prefix has been set, or a container binding is unusable.
	 *
	 * @return bool Whether the standalone was deactivated.
	 */
	protected function resolve( Sub_Plugin $sub_plugin ): bool {
		$policy = $sub_plugin->get_conflict_policy();

		// A host may persist a policy in an option and a filter may return anything. Falling
		// through to deactivate() would turn off a plugin the site owner deliberately activated
		// on the strength of a typo, so an unrecognised policy takes the conservative branch.
		if ( ! Conflict_Policy::is_valid( $policy ) ) {
			$policy = Conflict_Policy::NOTICE_ONLY;
		}

		switch ( $policy ) {
			case Conflict_Policy::DEFER:
				// The standalone wins. Its own constant makes the load path skip the bundled copy.
				return false;

			case Conflict_Policy::DEACTIVATE:
				$this->deactivate( $sub_plugin );

				return true;

			// NOTICE_ONLY, and anything is_valid() would accept that this switch has grown no
			// branch for. The default sits on the branch that only talks, never on the one that
			// deactivates: a policy nobody wrote must not be read as consent to turn a plugin off.
			default:
				$this->notices->queue_conflict_notice( $sub_plugin );

				return false;
		}
	}

	/**
	 * @since 1.0.0
	 *
	 * @param Sub_Plugin $sub_plugin Sub-plugin whose standalone is active.
	 *
	 * @throws Config_Exception When no hook prefix has been set, or a container binding is unusable.
	 *
	 * @return void
	 */
	protected function deactivate( Sub_Plugin $sub_plugin ): void {
		$this->plugin_deactivator->deactivate( $sub_plugin->get_standalone_plugin_basename() );

		// Queued as the deactivation is made rather than once at the end, so the explanation is
		// durable whether or not the request goes on to redirect — and so a site with two
		// standalones gets one notice per plugin it lost.
		$this->notices->queue_merge_notice( $sub_plugin );
	}

	/**
	 * Re-request the current screen, now that the standalone's code is out of the way.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function redirect(): void {
		// Boot\Scheduler runs this whole sequence inline when a host boots too late, immediately
		// after a _doing_it_wrong() that prints on a debugging site — so the headers are gone before
		// we get here, wp_safe_redirect() would warn and set no Location, and the exit behind it
		// would end the request on a blank page. Falling through instead lets the page finish
		// rendering, which is where the merge notice queued above is waiting to be read.
		if ( headers_sent() ) {
			return;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';

		// $_SERVER carries whatever the SAPI put there and wp_unslash() hands back the shape it was
		// given, so the string the redirector is promised is made one here rather than assumed.
		if ( ! is_string( $request_uri ) ) {
			$request_uri = '';
		}

		wp_safe_redirect( $this->redirector->after_deactivation( $request_uri ) );

		exit;
	}
}
