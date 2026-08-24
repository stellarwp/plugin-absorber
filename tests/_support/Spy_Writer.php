<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Tests\Support;

use Nexcess\PluginAbsorber\Notices\Contracts\Writer_Interface;
use Nexcess\PluginAbsorber\Sub_Plugin;

/**
 * A notice writer that records what was asked of it, for tests about who the library talks to.
 *
 * A named class rather than an anonymous one: a test reading `$spy->merge_notices` off a value typed
 * as `Writer_Interface` is reading a property the interface does not declare, and static analysis
 * rightly rejects it. Named, the spy's own type carries the counters.
 *
 * It stores nothing, which is the point — a test that binds this one proves the default writer was
 * never resolved by asserting the option is still absent.
 *
 * @since 1.0.0
 */
class Spy_Writer implements Writer_Interface {
	/**
	 * The option this spy would keep notices in, if it kept any.
	 *
	 * Deliberately not the default writer's name: a test that reads the real option while a spy is
	 * bound is reading somewhere nothing was written, and should say so rather than agree.
	 *
	 * @var string
	 */
	public $option = 'spy_queue_notices';

	/**
	 * Slugs handed to queue_merge_notice(), in order.
	 *
	 * @var string[]
	 */
	public $merge_notices = [];

	/**
	 * Slugs handed to queue_dependency_notice(), in order.
	 *
	 * @var string[]
	 */
	public $dependency_notices = [];

	/**
	 * @param Sub_Plugin $sub_plugin Sub-plugin concerned.
	 *
	 * @return void
	 */
	public function queue_merge_notice( Sub_Plugin $sub_plugin ): void {
		$this->merge_notices[] = $sub_plugin->get_slug();
	}

	/**
	 * Recorded nowhere, because nothing reads it: the conflict branch is asserted against the real
	 * queue's own `slug:conflict` key, in `Conflict\ResolverTest`. A recorder with no reader is a
	 * standing invitation to assert it is empty and be told nothing at all — so if a test here ever
	 * needs this call counted, the counter lands in the same change as the assertion that reads it.
	 *
	 * @param Sub_Plugin $sub_plugin Sub-plugin concerned.
	 *
	 * @return void
	 */
	public function queue_conflict_notice( Sub_Plugin $sub_plugin ): void {
	}

	/**
	 * @param Sub_Plugin $sub_plugin Sub-plugin concerned.
	 *
	 * @return void
	 */
	public function queue_dependency_notice( Sub_Plugin $sub_plugin ): void {
		$this->dependency_notices[] = $sub_plugin->get_slug();
	}

	/**
	 * Recorded nowhere, for the reason given on queue_conflict_notice(): the stranding branch is
	 * asserted against the real queue's own `slug:stranding` key.
	 *
	 * @param Sub_Plugin $sub_plugin Sub-plugin concerned.
	 *
	 * @return void
	 */
	public function queue_stranding_notice( Sub_Plugin $sub_plugin ): void {
	}

	/**
	 * @return string
	 */
	public function option_name(): string {
		return $this->option;
	}
}
