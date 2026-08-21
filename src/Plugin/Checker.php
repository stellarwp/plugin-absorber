<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Plugin;

use Nexcess\PluginAbsorber\Plugin\Contracts\Checker_Interface;

/**
 * Plugin state, straight from WordPress.
 *
 * @since 1.0.0
 */
class Checker implements Checker_Interface {
	use Loads_Plugin_Functions;

	/**
	 * @since 1.0.0
	 *
	 * @param string $basename Plugin basename.
	 *
	 * @return bool
	 */
	public function is_active( string $basename ): bool {
		$this->load_plugin_functions();

		// WordPress's own is_plugin_active() already ORs in the network check, so asking
		// is_plugin_active_for_network() as well would only buy a second get_site_option() per
		// sub-plugin per request.
		return is_plugin_active( $basename );
	}

	/**
	 * @since 1.0.0
	 *
	 * @param string $basename Plugin basename.
	 *
	 * @return bool
	 */
	public function is_network_active( string $basename ): bool {
		$this->load_plugin_functions();

		// is_plugin_active_for_network() checks is_multisite() itself and returns false off a
		// network, so the stranding guard that reads this needs no is_multisite() test of its own.
		return is_plugin_active_for_network( $basename );
	}
}
