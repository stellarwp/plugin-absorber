<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Tests\Support;

use Nexcess\PluginAbsorber\Notices\Presenter;
use Throwable;

/**
 * A presenter that counts how often it was asked to render, for tests about who the trampoline talks
 * to.
 *
 * A named class rather than an anonymous one, for the reason `Spy_Writer` is: a test reading
 * `$spy->render_calls` off a value typed as `Presenter` is reading a property the parent does not
 * declare, and static analysis rightly rejects it.
 *
 * The parent constructor is deliberately not called. `render()` is overridden here, so the store and
 * renderer the real class reads through would only be collaborators to build and hand over for
 * nobody to use.
 *
 * @since 1.0.0
 */
class Spy_Presenter extends Presenter {
	/**
	 * How many times render() was called.
	 *
	 * @var int
	 */
	public $render_calls = 0;

	/**
	 * What render() throws instead of drawing anything, when a test asks it to.
	 *
	 * The screen this runs on is every admin screen, so a throw from inside render() is the failure
	 * the trampoline exists to swallow.
	 *
	 * @var Throwable|null
	 */
	public $failure;

	/**
	 * Built without a store or renderer: nothing here reads either one.
	 */
	public function __construct() { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedFunction
	}

	/**
	 * @throws Throwable Whatever a test parked in $failure.
	 *
	 * @return void
	 */
	public function render(): void {
		++$this->render_calls;

		if ( $this->failure !== null ) {
			throw $this->failure;
		}
	}
}
