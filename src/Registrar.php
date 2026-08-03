<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber;

use Nexcess\PluginAbsorber\Contracts\Registrar_Interface;

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
	 * Assigning by key rather than appending is what makes a re-registration replace in place:
	 * the entry keeps its original position, so a host that registers conditionally in two code
	 * paths gets one entry and an unchanged load order.
	 *
	 * @since 1.0.0
	 *
	 * @param Sub_Plugin $sub_plugin Sub-plugin to register.
	 *
	 * @return void
	 */
	public function register( Sub_Plugin $sub_plugin ): void {
		$this->sub_plugins[ $sub_plugin->get_slug() ] = $sub_plugin;
	}

	/**
	 * @since 1.0.0
	 *
	 * @return array<string,Sub_Plugin>
	 */
	public function all(): array {
		return $this->sub_plugins;
	}

	/**
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->sub_plugins = [];
	}
}
