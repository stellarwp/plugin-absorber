<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Tests\Support;

use Nexcess\PluginAbsorber\Registry\Contracts\Registrar_Interface;
use Nexcess\PluginAbsorber\Sub_Plugin;

/**
 * A registrar that records what was done to it, for tests about who the Absorber talks to.
 *
 * A named class rather than an anonymous one: a test that reads `$spy->register_calls` off a value
 * typed as `Registrar_Interface` is reading a property the interface does not declare, and static
 * analysis rightly rejects it. Named, the spy's own type carries the counters.
 *
 * @since 1.0.0
 */
class Spy_Registrar implements Registrar_Interface {
	/**
	 * Everything handed to register(), keyed by slug.
	 *
	 * @var array<string,Sub_Plugin>
	 */
	public $sub_plugins = [];

	/**
	 * How many times register() was called.
	 *
	 * Counted rather than inferred from the map, which cannot see a slug registered twice — the
	 * thing a buffer that forgets to empty itself would do.
	 *
	 * @var int
	 */
	public $register_calls = 0;

	/**
	 * @param Sub_Plugin $sub_plugin Sub-plugin to register.
	 *
	 * @return void
	 */
	public function register( Sub_Plugin $sub_plugin ): void {
		++$this->register_calls;

		$this->sub_plugins[ $sub_plugin->get_slug() ] = $sub_plugin;
	}

	/**
	 * @return array<string,Sub_Plugin>
	 */
	public function all(): array {
		return $this->sub_plugins;
	}
}
