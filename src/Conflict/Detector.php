<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Conflict;

use Nexcess\PluginAbsorber\Exceptions\Config_Exception;
use Nexcess\PluginAbsorber\Plugin\Contracts\Checker_Interface;
use Nexcess\PluginAbsorber\Registry\Reader;
use Nexcess\PluginAbsorber\Sub_Plugin;

/**
 * Whether a bundled sub-plugin's standalone counterpart is still active.
 *
 * Its own class because detection is asked for on its own: the conflict step needs something cheap
 * to ask between the request gate and the capability gate, so that `current_user_can()` — which
 * pins the current user for the rest of the request — is never reached on a request with nothing to
 * resolve. Answering that from `Resolver_Interface` would put a detection query on the contract of
 * every host that binds its own resolver, and give the resolver two reasons to change: how a
 * conflict is found, and what to do about one.
 *
 * Both methods leave the request exactly as they found it. Nothing here resolves a user,
 * deactivates a plugin or queues a notice — an answer is all a caller gets, and the acting is the
 * resolver's.
 *
 * Not `final`: it is bound by class name, which is the seam a host rebinds and a test subclasses.
 *
 * @since 1.0.0
 */
class Detector {
	/**
	 * @since 1.0.0
	 *
	 * @var Reader
	 */
	private $registry;

	/**
	 * @since 1.0.0
	 *
	 * @var Checker_Interface
	 */
	private $plugin_checker;

	/**
	 * @since 1.0.0
	 *
	 * @param Reader            $registry       Which sub-plugins are registered.
	 * @param Checker_Interface $plugin_checker Whether the standalone is active.
	 */
	public function __construct( Reader $registry, Checker_Interface $plugin_checker ) {
		$this->registry       = $registry;
		$this->plugin_checker = $plugin_checker;
	}

	/**
	 * Whether any registered sub-plugin's standalone counterpart is currently active.
	 *
	 * Short-circuits on the first one found: the caller only needs to know whether the rest of the
	 * conflict step is worth entering, and the resolver walks the whole registry again anyway.
	 *
	 * @since 1.0.0
	 *
	 * @throws Config_Exception When no container has been set, or a container binding is unusable.
	 *
	 * @return bool
	 */
	public function has_conflict(): bool {
		// The reader rather than a registrar of our own: it drains the registrations still buffered
		// on the facade before it reads, and a registrar asked directly would miss anything
		// registered since the last read.
		foreach ( $this->registry->all() as $sub_plugin ) {
			if ( $this->is_in_conflict( $sub_plugin ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether this sub-plugin's standalone counterpart is active and ours to act on.
	 *
	 * Policy is not consulted: a sub-plugin set to defer is still in conflict, and the resolver is
	 * where the decision to leave it alone belongs.
	 *
	 * @since 1.0.0
	 *
	 * @param Sub_Plugin $sub_plugin Sub-plugin to test.
	 *
	 * @return bool
	 */
	public function is_in_conflict( Sub_Plugin $sub_plugin ): bool {
		if ( ! $sub_plugin->is_enabled() || ! $sub_plugin->has_standalone_plugin() ) {
			return false;
		}

		return $this->plugin_checker->is_active( $sub_plugin->get_standalone_plugin_basename() );
	}
}
