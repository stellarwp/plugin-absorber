<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber;

use Nexcess\PluginAbsorber\Contracts\Activator_Interface;
use Nexcess\PluginAbsorber\Exceptions\Config_Exception;

/**
 * Default activation tracking: a slug => true map in one option.
 *
 * @since 1.0.0
 */
class Activator implements Activator_Interface {
	/**
	 * @since 1.0.0
	 *
	 * @param Sub_Plugin $sub_plugin Sub-plugin that has just been loaded.
	 *
	 * @throws Config_Exception When no hook prefix has been set.
	 *
	 * @return void
	 */
	public function maybe_run( Sub_Plugin $sub_plugin ): void {
		$callback = $sub_plugin->get_activation_callback();

		if ( $callback === null ) {
			return;
		}

		$slug = $sub_plugin->get_slug();
		$done = $this->completed();

		if ( ! empty( $done[ $slug ] ) ) {
			return;
		}

		// Recorded after the callback rather than before it. A callback that fatals halfway leaves
		// the site half-migrated either way, but recording first would also mean the next request
		// skips it, so the half-finished state becomes permanent and invisible.
		$callback( $sub_plugin );

		$done[ $slug ] = true;

		update_site_option( self::option_name(), $done );
	}

	/**
	 * The option every slug's activation record lives in.
	 *
	 * @since 1.0.0
	 *
	 * @throws Config_Exception When no hook prefix has been set.
	 *
	 * @return string
	 */
	public static function option_name(): string {
		return Config::get_option_name( 'activations' );
	}

	/**
	 * Slugs whose activation callback has already run, keyed by slug.
	 *
	 * A site option, not a plain one: `deactivate_plugins()` is network-wide on multisite, so an
	 * activation that follows a network-wide merge has to be recorded network-wide too, or every
	 * site in the network runs the callback again.
	 *
	 * @since 1.0.0
	 *
	 * @throws Config_Exception When no hook prefix has been set.
	 *
	 * @return array<string,mixed>
	 */
	private function completed(): array {
		$done = get_site_option( self::option_name(), [] );

		// Anything that is not an array is replaced rather than trusted. A corrupted option would
		// otherwise fatal on the array read, inside plugins_loaded, on every request.
		return is_array( $done ) ? $done : [];
	}
}
