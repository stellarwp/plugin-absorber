<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Conflict;

use Nexcess\PluginAbsorber\Config;
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
 * Nothing here leaves a mark on the request. Nothing resolves a user,
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
	 * @throws Config_Exception When no hook prefix has been set.
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
	 * The `should_load` filter is, and it is the one piece of host code this class reads. It decides
	 * whether the bundled copy will be in memory at all, one priority behind this — so a sub-plugin
	 * the host vetoes there is in conflict with nothing: deactivating its standalone would take away
	 * the only copy of that code the site has, and the merge notice would tell the owner the bundled
	 * copy had taken over. Invisible here exactly as a disabled sub-plugin already is.
	 *
	 * Asked last, so the sub-plugin has to be enabled, name a standalone, and have that standalone
	 * actually running before any of it executes — the order the load pass asks it in, last of its
	 * own gates, and it keeps a filter that is arbitrary host code off every admin GET with no
	 * conflict to resolve.
	 *
	 * Nothing here catches: `Boot\Scheduler` wraps the whole conflict step in `catch ( Throwable )`
	 * and `Conflict\Resolver` catches per sub-plugin behind that, so a host filter that throws is
	 * already reported and already survivable from both callers.
	 *
	 * @since 1.0.0
	 *
	 * @param Sub_Plugin $sub_plugin Sub-plugin to test.
	 *
	 * @throws Config_Exception When no hook prefix has been set.
	 *
	 * @return bool
	 */
	public function is_in_conflict( Sub_Plugin $sub_plugin ): bool {
		if ( ! $sub_plugin->is_enabled() || ! $sub_plugin->has_standalone_plugin() ) {
			return false;
		}

		if ( ! $this->plugin_checker->is_active( $sub_plugin->get_standalone_plugin_basename() ) ) {
			return false;
		}

		// The same two arguments the load pass passes, so a host wires one filter and sees one
		// signature wherever it is asked from.
		$should_load = apply_filters( Config::get_hook_name( 'should_load' ), true, $sub_plugin );

		return (bool) $should_load;
	}

	/**
	 * Whether deactivating this standalone would strand sites the bundled copy will never reach.
	 *
	 * `deactivate_plugins()` takes a network-active standalone out of *every* site's plugins, but the
	 * bundled copy only loads where the host plugin runs. So on the one topology of a network-active
	 * standalone whose host is not itself network-active, a network-wide deactivation removes it from
	 * the sites the host never loads on, where nothing stands in for it. The resolver reads this and
	 * declines, leaving the load guard to defer the bundled copy network-wide instead.
	 *
	 * Opt-in, and cheap in the common case: with no host basename configured the guard stands down on
	 * a single string compare, before any option is read. It needs no `is_multisite()` test either --
	 * `Checker_Interface::is_network_active()` is `false` off a network, so the whole predicate is
	 * `false` on a single site.
	 *
	 * @since 1.0.0
	 *
	 * @param Sub_Plugin $sub_plugin Sub-plugin whose standalone is active.
	 *
	 * @return bool
	 */
	public function deactivation_would_strand_sites( Sub_Plugin $sub_plugin ): bool {
		$host_basename = Config::get_host_plugin_basename();

		if ( $host_basename === '' ) {
			return false;
		}

		if ( ! $this->plugin_checker->is_network_active( $sub_plugin->get_standalone_plugin_basename() ) ) {
			return false;
		}

		return ! $this->plugin_checker->is_network_active( $host_basename );
	}
}
