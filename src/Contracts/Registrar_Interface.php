<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Contracts;

use Nexcess\PluginAbsorber\Sub_Plugin;

/**
 * Holds the registered sub-plugins. Bind a replacement to change registration behavior globally.
 *
 * @since 1.0.0
 */
interface Registrar_Interface {
	/**
	 * Register a sub-plugin. Registering an existing slug replaces it.
	 *
	 * @since 1.0.0
	 *
	 * @param Sub_Plugin $sub_plugin Sub-plugin to register.
	 *
	 * @return void
	 */
	public function register( Sub_Plugin $sub_plugin ): void;

	/**
	 * Every registered sub-plugin, in registration order.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,Sub_Plugin> Keyed by slug.
	 */
	public function all(): array;

	/**
	 * Empty the registry.
	 *
	 * On the contract rather than on the implementation because a container-bound singleton
	 * registrar survives a Loader reset — the same populated instance comes back on the next
	 * resolve. An implementation that no-ops this leaks registrations between boots.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function reset(): void;
}
