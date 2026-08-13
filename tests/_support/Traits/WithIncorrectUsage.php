<?php
/**
 * Expects a `_doing_it_wrong()` report without pinning which member made it.
 *
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Tests\Support\Traits;

/**
 * Assert that the library reported a developer mistake, and that it reported it as its own.
 *
 * `setExpectedIncorrectUsage()` matches the first argument of `_doing_it_wrong()` exactly, which for a
 * report made with `__METHOD__` means restating a private method name in the test. That name is an
 * implementation detail of where a gate happens to live: moving the inline-boot fallback from `Absorber`
 * to `Boot\Scheduler` changes it without changing anything a host can observe. What a host *can*
 * observe — that the mistake is reported at all, and reported against this library rather than
 * swallowed or blamed on WordPress — is what these assertions pin.
 *
 * The expectation is registered from the report itself, so an unexpected report still fails the test:
 * anything the library reports is recorded here and asserted over.
 *
 * The *message* is recorded alongside the name, because "something was reported" is a weak thing to
 * assert on its own: a failed registry read, a missing hook prefix and the gate a test is actually
 * about all satisfy it equally. A test about one particular failure asserts through
 * `assert_the_library_reported_incorrect_usage_saying()` instead, which is the only assertion here
 * that can tell two report sites apart.
 *
 * Requires `Codeception\TestCase\WPTestCase`, for `setExpectedIncorrectUsage()`.
 *
 * @since 1.0.0
 */
trait WithIncorrectUsage {
	/**
	 * Every `_doing_it_wrong()` first argument seen since the listener went on.
	 *
	 * @var string[]
	 */
	private $incorrect_usage_reports = [];

	/**
	 * Every `_doing_it_wrong()` message seen since the listener went on, in the same order.
	 *
	 * @var string[]
	 */
	private $incorrect_usage_messages = [];

	/**
	 * @var callable|null
	 */
	private $incorrect_usage_listener = null;

	/**
	 * Start accepting — and recording — incorrect-usage reports.
	 *
	 * Safe to call more than once: whatever this trait already put on the hook comes off first.
	 * `Scenario\Bootstrap_Test_Case::run_halted_request()` calls this once per halted request, so a
	 * scenario running two of them would otherwise strand the first listener on `doing_it_wrong_run`
	 * for the rest of the process, bound to a test object that has already finished and writing into
	 * properties nothing will ever read.
	 *
	 * What was already recorded stays. A caller that expected a report before the second call still
	 * has to be able to assert on it afterwards.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function expect_incorrect_usage(): void {
		$this->remove_incorrect_usage_listener();

		$reports  = &$this->incorrect_usage_reports;
		$messages = &$this->incorrect_usage_messages;

		$listener = function ( $function_name, $message = '' ) use ( &$reports, &$messages ): void {
			if ( ! is_string( $function_name ) ) {
				return;
			}

			$reports[]  = $function_name;
			$messages[] = is_string( $message ) ? $message : '';

			$this->setExpectedIncorrectUsage( $function_name );
		};

		$this->incorrect_usage_listener = $listener;

		add_action( 'doing_it_wrong_run', $listener, 10, 2 );
	}

	/**
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function assert_the_library_reported_incorrect_usage(): void {
		$this->assertNotSame(
			[],
			$this->incorrect_usage_reports,
			'The library has to report a developer mistake where a developer will see it.'
		);

		foreach ( $this->incorrect_usage_reports as $report ) {
			$this->assertStringStartsWith(
				'Nexcess\\PluginAbsorber\\',
				$report,
				'The report has to name this library, or the host goes looking in WordPress.'
			);
		}
	}

	/**
	 * The same, and that one of the reports says which failure it was raised for.
	 *
	 * The assertion above is deliberately loose, which makes it the wrong one for a test about a
	 * particular gate: every other reason this library reports — an unreadable registry, a bootstrap
	 * with no hook prefix, a step that threw — satisfies it just as well, so the test would go on
	 * passing after the gate it was written for stopped running at all.
	 *
	 * @since 1.0.0
	 *
	 * @param string $needle Text one report has to carry, distinctive enough to name only that report
	 *                       site.
	 * @param string $why    Why that report and no other is the one under test.
	 *
	 * @return void
	 */
	protected function assert_the_library_reported_incorrect_usage_saying( string $needle, string $why ): void {
		$this->assert_the_library_reported_incorrect_usage();

		$this->assertStringContainsString(
			$needle,
			implode( PHP_EOL, $this->incorrect_usage_messages ),
			$why
		);
	}

	/**
	 * Take the listener back off, and forget what it recorded. Call from tearDown.
	 *
	 * Idempotent, and safe on a test that never expected a report at all.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function stop_expecting_incorrect_usage(): void {
		$this->remove_incorrect_usage_listener();

		$this->incorrect_usage_reports  = [];
		$this->incorrect_usage_messages = [];
	}

	/**
	 * Unhook whatever this trait last installed, if anything.
	 *
	 * Removed by identity rather than by clearing the hook, which WordPress and the rest of the suite
	 * are also on.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function remove_incorrect_usage_listener(): void {
		if ( $this->incorrect_usage_listener === null ) {
			return;
		}

		remove_action( 'doing_it_wrong_run', $this->incorrect_usage_listener );

		$this->incorrect_usage_listener = null;
	}
}
