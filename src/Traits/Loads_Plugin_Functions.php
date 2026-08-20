<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Traits;

/**
 * Makes WordPress's plugin functions available to the class using it.
 *
 * A trait rather than a fifth collaborator: there is nothing here to replace. It has no state, no
 * decision and no alternative implementation — the file either is loaded or it is not — so an
 * interface and a binding would buy a seam nobody would ever bind to, and both classes that touch
 * plugin functions would then have to be handed it.
 *
 * @since 1.0.0
 */
trait Loads_Plugin_Functions {
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
