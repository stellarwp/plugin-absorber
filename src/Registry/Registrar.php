<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Registry;

use Nexcess\PluginAbsorber\Exceptions\Config_Exception;
use Nexcess\PluginAbsorber\Registry\Contracts\Registrar_Interface;
use Nexcess\PluginAbsorber\Sub_Plugin;

/**
 * Default registry: a plain slug => Sub_Plugin map.
 *
 * @since 1.0.0
 */
class Registrar implements Registrar_Interface {
	/**
	 * @var array<string,Sub_Plugin>
	 */
	private $sub_plugins = [];

	/**
	 * Register a sub-plugin.
	 *
	 * A slug is an identity, not a key this map happens to use: it also names the sub-plugin's
	 * notices and its once-ever activation record. Letting a second registration win would drop
	 * the first sub-plugin from the load silently and hand its activation record to the winner, so
	 * the collision is refused instead. There is no legitimate second registration to protect —
	 * a decision the host cannot make up front belongs in the `enabled` callable, which is
	 * re-evaluated on every load, not in a second call to this method.
	 *
	 * @since 1.0.0
	 *
	 * @param Sub_Plugin $sub_plugin Sub-plugin to register.
	 *
	 * @throws Config_Exception When the slug is already registered.
	 *
	 * @return void
	 */
	public function register( Sub_Plugin $sub_plugin ): void {
		$slug = $sub_plugin->get_slug();

		if ( isset( $this->sub_plugins[ $slug ] ) ) {
			// Both files, because the two registrations routinely come from different host plugins
			// and the stack trace only shows the one that lost.
			throw new Config_Exception(
				sprintf(
					'Two sub-plugins are registered under the slug "%1$s": %2$s and %3$s.'
						. ' A slug must identify exactly one sub-plugin.',
					$slug,
					$this->sub_plugins[ $slug ]->get_bundled_plugin_file(),
					$sub_plugin->get_bundled_plugin_file()
				)
			);
		}

		$this->sub_plugins[ $slug ] = $sub_plugin;
	}

	/**
	 * @since 1.0.0
	 *
	 * @return array<string,Sub_Plugin>
	 */
	public function all(): array {
		return $this->sub_plugins;
	}
}
