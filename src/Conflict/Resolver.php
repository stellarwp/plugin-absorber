<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Conflict;

use Nexcess\PluginAbsorber\Conflict\Contracts\Resolver_Interface;
use Nexcess\PluginAbsorber\Conflict_Policy;
use Nexcess\PluginAbsorber\Contracts\Plugin_Checker_Interface;
use Nexcess\PluginAbsorber\Contracts\Plugin_Deactivator_Interface;
use Nexcess\PluginAbsorber\Exceptions\Config_Exception;
use Nexcess\PluginAbsorber\Loader;
use Nexcess\PluginAbsorber\Notices\Contracts\Queue_Interface;
use Nexcess\PluginAbsorber\Sub_Plugin;

/**
 * Default conflict resolution: detect the active standalone and act per policy.
 *
 * Every peer is a required constructor argument, so this object is complete the moment it exists and
 * a test can hand it four doubles instead of standing up global state. Reading the standalone's
 * state and turning it off arrive separately because they are separate contracts: a host that wants
 * deactivation to be a no-op rebinds one of them and keeps the other.
 *
 * Who may have a conflict resolved is not decided here — `Gatekeeper` answers that, and the
 * conflict step asks it before this class is built at all.
 *
 * @since 1.0.0
 */
class Resolver implements Resolver_Interface {
	/**
	 * @since 1.0.0
	 *
	 * @var Plugin_Checker_Interface
	 */
	private $plugin_checker;

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
	 * @param Plugin_Checker_Interface     $plugin_checker     Whether the standalone is active.
	 * @param Plugin_Deactivator_Interface $plugin_deactivator Turns the standalone off.
	 * @param Queue_Interface              $notices            Where the user is told what happened.
	 * @param Redirector                   $redirector         Where the user lands afterwards.
	 */
	public function __construct(
		Plugin_Checker_Interface $plugin_checker,
		Plugin_Deactivator_Interface $plugin_deactivator,
		Queue_Interface $notices,
		Redirector $redirector
	) {
		$this->plugin_checker     = $plugin_checker;
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
		// Loader::all() rather than a registrar of our own: it flushes the pending registrations
		// before it reads, and a registrar asked directly would not see anything registered since
		// the last read.
		foreach ( Loader::all() as $sub_plugin ) {
			if ( ! $sub_plugin->is_enabled() || ! $sub_plugin->has_standalone_plugin() ) {
				continue;
			}

			if ( ! $this->plugin_checker->is_active( $sub_plugin->get_standalone_plugin_basename() ) ) {
				continue;
			}

			$this->resolve( $sub_plugin );
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
	protected function resolve( Sub_Plugin $sub_plugin ): void {
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
				return;

			case Conflict_Policy::DEACTIVATE:
				$this->deactivate( $sub_plugin );

				return;

			// NOTICE_ONLY, and anything is_valid() would accept that this switch has grown no
			// branch for. The default sits on the branch that only talks, never on the one that
			// deactivates: a policy nobody wrote must not be read as consent to turn a plugin off.
			default:
				$this->notices->queue_conflict_notice( $sub_plugin );
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

		// Queued after the deactivation but before the redirect, so the explanation is durable
		// whether or not the request goes on to end here.
		$this->notices->queue_merge_notice( $sub_plugin );

		$destination = $this->redirector->after_deactivation( wp_get_referer() );

		if ( $destination !== false ) {
			wp_safe_redirect( $destination );

			exit;
		}
	}
}
