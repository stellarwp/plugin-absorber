<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Contracts;

/**
 * The library's one way of asking WordPress about a plugin, and of turning one off.
 *
 * Everything here is stated in terms of a plugin basename — "give-recurring/give-recurring.php" —
 * because that is the only identifier WordPress itself accepts. Bind a replacement to answer from
 * somewhere other than the active-plugins option, or to make deactivation a no-op in an
 * environment where plugin state is managed outside WordPress.
 *
 * @since 1.0.0
 */
interface Plugin_State_Interface {
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
	 * Deactivate the plugin in every scope it is active in.
	 *
	 * Called unattended, during plugins_loaded, on behalf of a user who did not ask for it. An
	 * implementation that reports success without deactivating leaves two copies of the same
	 * plugin to load, which is the failure this library exists to prevent.
	 *
	 * @since 1.0.0
	 *
	 * @param string $basename Plugin basename.
	 *
	 * @return void
	 */
	public function deactivate( string $basename ): void;
}
