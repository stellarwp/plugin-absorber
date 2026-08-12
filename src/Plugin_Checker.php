<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber;

use Nexcess\PluginAbsorber\Contracts\Plugin_Checker_Interface;
use Nexcess\PluginAbsorber\Traits\Loads_Plugin_Functions;

/**
 * Plugin state, straight from WordPress.
 *
 * @since 1.0.0
 */
class Plugin_Checker implements Plugin_Checker_Interface {
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
}
