<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Contracts;

/**
 * The library's one way of turning a plugin off.
 *
 * Stated in terms of a plugin basename — "give-recurring/give-recurring.php" — because that is
 * the only identifier WordPress itself accepts. Bind a replacement to make deactivation a no-op
 * in an environment where plugin state is managed outside WordPress.
 *
 * @since 1.0.0
 */
interface Plugin_Deactivator_Interface {
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
