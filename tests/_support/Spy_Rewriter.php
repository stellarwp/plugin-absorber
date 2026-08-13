<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Support;

use Nexcess\PluginAbsorber\Conflict\Rewriter;
use Throwable;

/**
 * A rewriter that records what it was handed, for tests about who the trampoline talks to.
 *
 * A named class rather than an anonymous one, for the reason `Spy_Writer` is: a test reading
 * `$spy->rewritten` off a value typed as `Rewriter` is reading a property the parent does not
 * declare, and static analysis rightly rejects it.
 *
 * The parent constructor is deliberately not called. Every method a test reaches is overridden here,
 * so the registry the real class reads through would only be a collaborator to build and hand over
 * for nobody to use.
 *
 * @since 1.0.0
 */
class Spy_Rewriter extends Rewriter {
	/**
	 * Markup handed to rewrite(), in order.
	 *
	 * @var string[]
	 */
	public $rewritten = [];

	/**
	 * What rewrite() hands back.
	 *
	 * Deliberately not the argument: a trampoline that returned its own input instead of the
	 * rewriter's answer would be indistinguishable from one that delegated properly.
	 *
	 * @var string
	 */
	public $rewritten_markup = '<p>Rewritten by the rewriter.</p>';

	/**
	 * What rewrite() throws instead of answering, when a test asks it to.
	 *
	 * The screen this runs on is already reporting a fatal, so a throw from inside the rewrite is
	 * the failure the trampoline exists to swallow — and it is the one a duplicate registration
	 * really produces, at the moment the registry drains.
	 *
	 * @var Throwable|null
	 */
	public $failure;

	/**
	 * Built without a registry: nothing here reads one.
	 */
	public function __construct() { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedFunction
	}

	/**
	 * @param string $markup Notice markup WordPress is about to print.
	 *
	 * @throws Throwable Whatever a test parked in $failure.
	 *
	 * @return string
	 */
	public function rewrite( string $markup ): string {
		$this->rewritten[] = $markup;

		if ( $this->failure !== null ) {
			throw $this->failure;
		}

		return $this->rewritten_markup;
	}
}
