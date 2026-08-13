<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber;

use Nexcess\PluginAbsorber\Contracts\Plugin_Deactivator_Interface;
use Nexcess\PluginAbsorber\Traits\Loads_Plugin_Functions;

/**
 * Turns a plugin off, the way WordPress's own unattended paths do.
 *
 * @since 1.0.0
 */
class Plugin_Deactivator implements Plugin_Deactivator_Interface {
	use Loads_Plugin_Functions;

	/**
	 * @since 1.0.0
	 *
	 * @param string $basename Plugin basename.
	 *
	 * @return void
	 */
	public function deactivate( string $basename ): void {
		$this->load_plugin_functions();

		// Silent, and with no $network_wide argument.
		//
		// Silent because this is an unattended deactivation, and the standalone's own deactivation
		// hook has already been registered this request. Running it at plugins_loaded means a
		// routine flush_rewrite_rules() in that callback regenerates the rules before init has
		// registered a single post type, and every custom permalink on the site starts 404ing.
		// WordPress core makes the same call: its interactive paths are noisy, its automatic ones
		// -- validate_active_plugins(), the plugin upgrader -- are silent.
		//
		// The $network_wide default is null, not false, and null is the value that handles both
		// scopes. WordPress core enters the network branch on `false !== $network_wide` and the
		// blog branch on `true !== $network_wide`, so null takes both. A computed true would skip
		// the blog branch, stranding an entry for a plugin that is active in both, which then takes
		// a second request and a second deactivation hook to clear.
		deactivate_plugins( $basename, true );
	}
}
