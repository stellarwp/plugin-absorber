<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Tests\Support;

use Nexcess\PluginAbsorber\Registry\Reader;
use Nexcess\PluginAbsorber\Sub_Plugin;

/**
 * A registry read with nothing behind it: the sub-plugins a test names, and no global state at all.
 *
 * This is what the seam buys. A collaborator handed one of these is exercised without the static
 * registration buffer, without a container and without a registrar — so a test about what the load
 * pass or the resolver *does* with a sub-plugin no longer has to arrange for one to be registered
 * first, and a test asserting that a collaborator read the object it was handed can prove it by
 * handing it something the facade has never heard of.
 *
 * A subclass rather than an implementation of an interface, because the reader is bound by class
 * name — the seam a host rebinds — and a class is what the collaborators declare.
 *
 * @since 1.0.0
 */
class Stub_Registry_Reader extends Reader {
	/**
	 * What every read hands back, keyed by slug.
	 *
	 * @var array<string,Sub_Plugin>
	 */
	public $sub_plugins = [];

	/**
	 * @param Sub_Plugin[] $sub_plugins Sub-plugins this reader knows about, in registration order.
	 */
	public function __construct( array $sub_plugins = [] ) {
		foreach ( $sub_plugins as $sub_plugin ) {
			$this->sub_plugins[ $sub_plugin->get_slug() ] = $sub_plugin;
		}
	}

	/**
	 * @return array<string,Sub_Plugin>
	 */
	public function all(): array {
		return $this->sub_plugins;
	}

	/**
	 * Nothing to drain, and nowhere to drain it to. This reader answers from the sub-plugins a test
	 * named, so it holds no registrar — and the inherited flush would reach for one that was never
	 * constructed the moment a test that used this stub had also registered something.
	 *
	 * @return void
	 */
	public function flush(): void {
	}
}
