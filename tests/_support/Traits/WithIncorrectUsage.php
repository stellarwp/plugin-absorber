<?php
/**
 * Expects a `_doing_it_wrong()` report without pinning which member made it.
 *
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Support\Traits;

/**
 * Assert that the library reported a developer mistake, and that it reported it as its own.
 *
 * `setExpectedIncorrectUsage()` matches the first argument of `_doing_it_wrong()` exactly, which for a
 * report made with `__METHOD__` means restating a private method name in the test. That name is an
 * implementation detail of where a gate happens to live: moving the inline-boot fallback from `Loader`
 * to `Boot\Scheduler` changes it without changing anything a host can observe. What a host *can*
 * observe — that the mistake is reported at all, and reported against this library rather than
 * swallowed or blamed on WordPress — is what these assertions pin.
 *
 * The expectation is registered from the report itself, so an unexpected report still fails the test:
 * anything the library reports is recorded here and asserted over.
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
	 * @var callable|null
	 */
	private $incorrect_usage_listener = null;

	/**
	 * Start accepting — and recording — incorrect-usage reports.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function expect_incorrect_usage(): void {
		$reports = &$this->incorrect_usage_reports;

		$listener = function ( $function_name ) use ( &$reports ): void {
			if ( ! is_string( $function_name ) ) {
				return;
			}

			$reports[] = $function_name;

			$this->setExpectedIncorrectUsage( $function_name );
		};

		$this->incorrect_usage_listener = $listener;

		add_action( 'doing_it_wrong_run', $listener );
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
	 * Take the listener back off. Call from tearDown.
	 *
	 * Removed by identity rather than by clearing the hook, which WordPress and the rest of the suite
	 * are also on.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function stop_expecting_incorrect_usage(): void {
		if ( $this->incorrect_usage_listener !== null ) {
			remove_action( 'doing_it_wrong_run', $this->incorrect_usage_listener );

			$this->incorrect_usage_listener = null;
		}

		$this->incorrect_usage_reports = [];
	}
}
