<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Conflict;

use Nexcess\PluginAbsorber\Conflict\Contracts\Resolver_Interface;
use Nexcess\PluginAbsorber\Conflict_Policy;
use Nexcess\PluginAbsorber\Exceptions\Config_Exception;
use Nexcess\PluginAbsorber\Notices\Contracts\Writer_Interface;
use Nexcess\PluginAbsorber\Plugin\Contracts\Deactivator_Interface;
use Nexcess\PluginAbsorber\Registry\Reader;
use Nexcess\PluginAbsorber\Sub_Plugin;
use Nexcess\PluginAbsorber\Traits\Guards_Hook_Prefix;
use Throwable;

/**
 * Default conflict resolution: act on each conflicting sub-plugin per its policy.
 *
 * Every peer is a required constructor argument, the registry read included, so this object is
 * complete the moment it exists and a test can hand it doubles instead of standing up global state.
 * Finding the conflict and turning the standalone off arrive separately because they are separate
 * jobs: `Detector` answers whether a standalone is in the way, and a host that wants deactivation to
 * be a no-op rebinds `Plugin\Contracts\Deactivator_Interface` without touching how detection works.
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
	use Guards_Hook_Prefix;

	/**
	 * @since 1.0.0
	 *
	 * @var Reader
	 */
	private $registry;

	/**
	 * @since 1.0.0
	 *
	 * @var Detector
	 */
	private $detector;

	/**
	 * @since 1.0.0
	 *
	 * @var Deactivator_Interface
	 */
	private $plugin_deactivator;

	/**
	 * @since 1.0.0
	 *
	 * @var Writer_Interface
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
	 * @param Reader                $registry           Which sub-plugins are registered.
	 * @param Detector              $detector           Whether a sub-plugin is in conflict.
	 * @param Deactivator_Interface $plugin_deactivator Turns the standalone off.
	 * @param Writer_Interface      $notices            Where the user is told what happened.
	 * @param Redirector            $redirector         Where the user lands afterwards.
	 */
	public function __construct(
		Reader $registry,
		Detector $detector,
		Deactivator_Interface $plugin_deactivator,
		Writer_Interface $notices,
		Redirector $redirector
	) {
		$this->registry           = $registry;
		$this->detector           = $detector;
		$this->plugin_deactivator = $plugin_deactivator;
		$this->notices            = $notices;
		$this->redirector         = $redirector;
	}

	/**
	 * @since 1.0.0
	 *
	 * @throws Config_Exception When no hook prefix has been set.
	 *
	 * @return void
	 */
	public function resolve_all(): void {
		// Every notice this pass raises is keyed and filtered by the host's prefix, so without one
		// there is no way to tell the site owner why a plugin of theirs went off. Standing down whole
		// is the honest answer -- deactivating first and failing to explain it is worse than leaving
		// the conflict for the request after the host fixes its bootstrap -- and it is what the load
		// pass at the next priority does with the same missing prefix.
		if ( ! self::has_hook_prefix() ) {
			return;
		}

		// "A standalone is gone", not "a deactivation was attempted". The redirect below is only ever
		// worth taking on the first of those, and taking it on the second is a loop.
		$standalone_gone = false;

		// The reader rather than a registrar of our own: it drains the registrations still buffered
		// on the facade before it reads, and a registrar asked directly would miss anything
		// registered since the last read.
		foreach ( $this->registry->all() as $sub_plugin ) {
			// The policy may be a host callable behind a filter any plugin on the site may have
			// hooked, the notice message is another callable, and deactivate_plugins() runs the
			// standalone's own deactivation hook -- so this loop calls arbitrary code, from inside
			// plugins_loaded, on an admin page view. A throw out of here would take away the screen
			// the site owner would have used to undo whatever caused it, and would leave a second
			// standalone running with nothing said about it. Reported per sub-plugin, and the next
			// one is still resolved.
			try {
				if ( ! $this->detector->is_in_conflict( $sub_plugin ) ) {
					continue;
				}

				if ( $this->resolve( $sub_plugin ) ) {
					$standalone_gone = true;
				}
			} catch ( Throwable $thrown ) {
				_doing_it_wrong(
					self::class,
					sprintf(
						'The conflict for "%s" threw while being resolved, so it was abandoned: %s',
						$sub_plugin->get_slug(),
						$thrown->getMessage()
					),
					'1.0.0'
				);
			}
		}

		// After the loop, never inside it. A site bundling two sub-plugins can have both standalones
		// active, and an `exit` on the first would leave the second's standalone running with no
		// notice raised about it — and would take the load pass at the next priority with it, so
		// nothing bundled loaded on the request that was supposed to fix the conflict.
		//
		// And only where a standalone really did go away. A request that deactivated nothing has
		// nothing to shed from memory, so re-requesting the screen would arrive at the same conflict
		// — while the notices this pass queued are waiting on a request that reaches
		// `all_admin_notices`, which a redirect never does.
		if ( $standalone_gone ) {
			$this->redirect();
		}
	}

	/**
	 * @since 1.0.0
	 *
	 * @param Sub_Plugin $sub_plugin Sub-plugin whose standalone is active.
	 *
	 * @throws Config_Exception When no hook prefix has been set.
	 *
	 * @return bool Whether the standalone is gone -- not whether deactivating it was attempted.
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
				// A network-active standalone whose bundled replacement ships in a host that is not
				// itself network-active: deactivating it network-wide would pull it from the sites the
				// host never reached, where nothing loads the bundled copy. Leave it -- the load guard
				// defers the bundled copy network-wide instead -- and say why. Opt-in and
				// single-site-safe: false whenever no host basename is configured, and false off a
				// network, so this is the ordinary deactivation in every other case.
				if ( $this->detector->deactivation_would_strand_sites( $sub_plugin ) ) {
					$this->notices->queue_stranding_notice( $sub_plugin );

					return false;
				}

				return $this->deactivate( $sub_plugin );

			// NOTICE_ONLY, and anything is_valid() would accept that this switch has grown no
			// branch for. The default sits on the branch that only talks, never on the one that
			// deactivates: a policy nobody wrote must not be read as consent to turn a plugin off.
			default:
				$this->notices->queue_conflict_notice( $sub_plugin );

				return false;
		}
	}

	/**
	 * Turn the standalone off, and report the merge only if it really went off.
	 *
	 * The answer is also what decides the redirect. Sending the user back to the screen they asked for
	 * while the standalone is still running arrives at the same conflict, deactivates to no effect and
	 * redirects again -- until the browser gives up with the whole of wp-admin out of reach, and with
	 * every one of those requests exiting before `all_admin_notices` could draw anything.
	 *
	 * @since 1.0.0
	 *
	 * @param Sub_Plugin $sub_plugin Sub-plugin whose standalone is active.
	 *
	 * @throws Config_Exception When no hook prefix has been set.
	 *
	 * @return bool Whether the standalone is gone -- not whether deactivating it was attempted.
	 */
	protected function deactivate( Sub_Plugin $sub_plugin ): bool {
		$this->plugin_deactivator->deactivate( $sub_plugin->get_standalone_plugin_basename() );

		// Asked again rather than assumed, because turning the standalone off is not the same event as
		// the standalone being off. A site or mu-plugin filtering `option_active_plugins` puts it
		// straight back, a host may have rebound `Plugin\Contracts\Deactivator_Interface` to something
		// that does nothing, and a rebound `Plugin\Contracts\Checker_Interface` may mean by "active"
		// something `deactivate_plugins()` never touches.
		if ( $this->detector->is_in_conflict( $sub_plugin ) ) {
			// Nothing is said and nothing is reported. The merge notice would tell the owner a plugin
			// they can watch still running had been deactivated, and would say it again on every admin
			// GET for as long as the site kept putting it back; and this is the DEFER outcome reached
			// by another route, so there is no failure to announce either -- the standalone is running,
			// which means its own guard constant stands the bundled copy down and nothing re-declares.
			// Every way of arriving here is a site's own configuration rather than a mistake in the
			// host's code, and `Plugin\Contracts\Deactivator_Interface` invites one of them by name.
			return false;
		}

		// Queued as each deactivation is confirmed rather than once at the end, so the explanation is
		// durable whether or not the request goes on to redirect — and so a site with two standalones
		// gets one notice per plugin it lost.
		$this->notices->queue_merge_notice( $sub_plugin );

		return true;
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

		// Read raw, not through wp_unslash(). Core adds the slashes in wp_magic_quotes(), which
		// wp-settings.php calls *after* do_action( 'plugins_loaded' ) -- so at priority 5 there are
		// none on this value and unslashing is a plain stripslashes() over the URL bar. An admin
		// searching for a Windows path who trips a conflict would be sent back to a search for
		// "C:projects", which is the one thing the redirect exists to get right. Reached from the
		// inline fallback a too-late boot reports, this can run after the slashing instead, where the
		// cost is a stray backslash in a re-encoded query arg rather than a deleted one.
		$request_uri = $_SERVER['REQUEST_URI'] ?? '';

		// $_SERVER carries whatever the SAPI put there, so the string the redirector is promised is
		// made one here rather than assumed.
		if ( ! is_string( $request_uri ) ) {
			$request_uri = '';
		}

		wp_safe_redirect( $this->redirector->after_deactivation( $request_uri ) );

		exit;
	}
}
