<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber;

use Nexcess\PluginAbsorber\Contracts\Plugin_State_Interface;

/**
 * Plugin state, straight from WordPress.
 *
 * The single place in this library that touches WordPress's plugin functions, so it is also the
 * single place that has to load them.
 *
 * @since 1.0.0
 */
class Plugin_State implements Plugin_State_Interface {
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

	/**
	 * WordPress only loads these in the admin, and we run at plugins_loaded on every request.
	 *
	 * Guarded on deactivate_plugins() rather than is_plugin_active(), because the latter is a
	 * common third-party shim: something else defining it would short-circuit this and leave the
	 * rest of the file unloaded, so the first call that needs a function nobody shimmed fatals.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function load_plugin_functions(): void {
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}
}
