<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Support;

use Nexcess\PluginAbsorber\Notices\Contracts\Queue_Interface;
use Nexcess\PluginAbsorber\Sub_Plugin;

/**
 * A notice queue that records what was asked of it, for tests about who the library talks to.
 *
 * A named class rather than an anonymous one: a test reading `$spy->render_calls` off a value typed as
 * `Queue_Interface` is reading a property the interface does not declare, and static analysis rightly
 * rejects it. Named, the spy's own type carries the counters.
 *
 * It stores nothing, which is the point — a test that binds this one proves the default queue was
 * never resolved by asserting the option is still absent.
 *
 * @since 1.0.0
 */
class Spy_Queue implements Queue_Interface {
	/**
	 * Slugs handed to queue_merge_notice(), in order.
	 *
	 * @var string[]
	 */
	public $merge_notices = [];

	/**
	 * Slugs handed to queue_conflict_notice(), in order.
	 *
	 * @var string[]
	 */
	public $conflict_notices = [];

	/**
	 * Slugs handed to queue_dependency_notice(), in order.
	 *
	 * @var string[]
	 */
	public $dependency_notices = [];

	/**
	 * How many times render() was called.
	 *
	 * @var int
	 */
	public $render_calls = 0;

	/**
	 * Markup handed to filter_activation_error_markup(), in order.
	 *
	 * @var string[]
	 */
	public $filtered = [];

	/**
	 * What filter_activation_error_markup() hands back.
	 *
	 * Deliberately not the argument: a trampoline that returned its own input instead of the
	 * queue's answer would be indistinguishable from one that delegated properly.
	 *
	 * @var string
	 */
	public $filtered_markup = '<p>Rewritten by the queue.</p>';

	/**
	 * @param Sub_Plugin $sub_plugin Sub-plugin concerned.
	 *
	 * @return void
	 */
	public function queue_merge_notice( Sub_Plugin $sub_plugin ): void {
		$this->merge_notices[] = $sub_plugin->get_slug();
	}

	/**
	 * @param Sub_Plugin $sub_plugin Sub-plugin concerned.
	 *
	 * @return void
	 */
	public function queue_conflict_notice( Sub_Plugin $sub_plugin ): void {
		$this->conflict_notices[] = $sub_plugin->get_slug();
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
	 * @return void
	 */
	public function render(): void {
		++$this->render_calls;
	}

	/**
	 * @param string $markup Notice markup WordPress is about to print.
	 *
	 * @return string
	 */
	public function filter_activation_error_markup( string $markup ): string {
		$this->filtered[] = $markup;

		return $this->filtered_markup;
	}
}
