<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Tests\Support;

use Nexcess\PluginAbsorber\Contracts\Activator_Interface;
use Nexcess\PluginAbsorber\Sub_Plugin;

/**
 * An activator that records which slugs it was asked about and runs nothing.
 *
 * A named class rather than an anonymous one: a test reading `$spy->slugs` off a value typed as
 * `Activator_Interface` is reading a property the interface does not declare, and static analysis
 * rightly rejects it. Named, the spy's own type carries the recorder.
 *
 * It records rather than runs, which is the point — a test that binds this one proves the load path
 * reached the activator it was handed without writing the default's option along the way.
 *
 * @since 1.0.0
 */
class Spy_Activator implements Activator_Interface {
	/**
	 * Slugs maybe_run() was called for, in call order.
	 *
	 * @var string[]
	 */
	public $slugs = [];

	/**
	 * @param Sub_Plugin $sub_plugin Sub-plugin that has just been loaded.
	 *
	 * @return void
	 */
	public function maybe_run( Sub_Plugin $sub_plugin ): void {
		$this->slugs[] = $sub_plugin->get_slug();
	}
}
