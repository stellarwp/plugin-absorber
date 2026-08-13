<?php
/**
 * Captures a redirect that ends the request.
 *
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Tests\Support\Traits;

use Nexcess\PluginAbsorber\Tests\Support\TestException;

/**
 * One shared way to assert on a code path that redirects and then calls `exit`.
 *
 * `exit` is never mocked — `UopzFunctions::preventExit()` would let the test run past the point
 * production stops, so a test that should fail reports as passing. The call immediately before it is
 * stubbed instead, and throws, which stops execution at a point the test controls.
 *
 * The discipline lives here rather than in each test body because two of its parts are easy to drop
 * and silent when dropped: the `fail()` on the line after the action, which is what turns "never
 * redirected at all" from a pass into a failure, and matching the exception's message, which stops an
 * unrelated `TestException` thrown earlier from satisfying the catch.
 *
 * Requires `lucatume\WPBrowser\Traits\UopzFunctions` on the same test class, for the stub.
 *
 * @since 1.0.0
 */
trait WithHaltedRedirects {
	/**
	 * Run an action that must redirect and terminate, and return where it sent the user.
	 *
	 * @since 1.0.0
	 *
	 * @param callable $action The call under test.
	 *
	 * @return string
	 */
	protected function capture_redirect( callable $action ): string {
		// Typed as a string from the start, and captured by reference: uopz runs the replacement with
		// no class scope, so neither $this nor a class constant is reachable from inside it.
		$location = '';
		$message  = self::halted_at_exit_message();

		$this->setFunctionReturn(
			'wp_safe_redirect',
			static function ( $to ) use ( &$location, $message ) {
				$location = is_string( $to ) ? $to : '';

				throw new TestException( $message );
			},
			true
		);

		try {
			$action();

			// Reached only when the action returned instead of halting, which is the failure this
			// helper exists to make impossible to miss.
			$this->fail( 'Expected the action to redirect and terminate.' );
		} catch ( TestException $exception ) {
			$this->assertSame(
				$message,
				$exception->getMessage(),
				'The halt has to come from the stubbed redirect, not from something thrown earlier.'
			);
		} finally {
			// In a finally block so a failed assertion cannot strand the stub for the rest of the
			// process, where a later test's redirect would throw for no reason it can see.
			$this->unsetFunctionReturn( 'wp_safe_redirect' );
		}

		$this->assertNotSame( '', $location, 'The action must redirect somewhere.' );

		return $location;
	}

	/**
	 * The message the stubbed redirect throws.
	 *
	 * A method rather than a constant: PHP 7.4 has no constants in traits.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	protected static function halted_at_exit_message(): string {
		return 'Halted where production calls exit().';
	}
}
