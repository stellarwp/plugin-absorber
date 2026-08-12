<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Contracts;

/**
 * Teaches a container how to build this library's collaborators.
 *
 * One method, and it binds — it wires no hooks and resolves nothing. When things run is
 * `Boot\Scheduler`'s subject, and a provider that also hooked would have to be replaced wholesale
 * by a host that only wanted a different implementation of one binding.
 *
 * @since 1.0.0
 */
interface Provider_Interface {
	/**
	 * Bind this library's collaborators.
	 *
	 * Called once, from `Loader::boot()`, and expected to be safe to call again: boot() is
	 * idempotent and a host may bind its own provider that another code path also registers.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void;
}
