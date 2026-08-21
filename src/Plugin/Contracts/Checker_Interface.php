<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Plugin\Contracts;

/**
 * The library's one way of asking WordPress about a plugin.
 *
 * Stated in terms of a plugin basename — "give-recurring/give-recurring.php" — because that is the
 * only identifier WordPress itself accepts. Bind a replacement to answer from somewhere other than
 * the active-plugins option.
 *
 * Separate from `Plugin\Contracts\Deactivator_Interface` because the two are asked for by different code for
 * different reasons: reading plugin state is a question anything may ask, while turning a plugin
 * off is an action exactly one policy branch takes. A host that wants deactivation to be a no-op —
 * plugin state managed outside WordPress, say — should not have to reimplement the reading half
 * to say so.
 *
 * @since 1.0.0
 */
interface Checker_Interface {
	/**
	 * Whether the plugin is active, in either scope.
	 *
	 * Site-wide and network-wide both count. The conflict this answers for is "the standalone's
	 * code is going to run this request", and a network activation runs it just as surely.
	 *
	 * @since 1.0.0
	 *
	 * @param string $basename Plugin basename.
	 *
	 * @return bool
	 */
	public function is_active( string $basename ): bool;

	/**
	 * Whether the plugin is active across the whole network.
	 *
	 * Network scope only, unlike `is_active()`, which counts either scope. The two are asked for
	 * different reasons: `is_active()` answers "the standalone's code is going to run this request",
	 * while this answers "deactivating it reaches every site" -- the question the conflict resolver's
	 * stranding guard turns on. Returns `false` whenever the site is not multisite, because
	 * WordPress's own `is_plugin_active_for_network()` does, so a caller needs no `is_multisite()`
	 * guard of its own and the stranding guard is a no-op off a network.
	 *
	 * @since 1.0.0
	 *
	 * @param string $basename Plugin basename.
	 *
	 * @return bool
	 */
	public function is_network_active( string $basename ): bool;
}
