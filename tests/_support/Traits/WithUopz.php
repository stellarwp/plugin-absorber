<?php
/**
 * uopz helpers for stubbing functions WordPress cannot hook.
 *
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Support\Traits;

/**
 * @since 1.0.0
 */
trait WithUopz {
	/**
	 * Function names with an active uopz return override.
	 *
	 * @var array<int,string>
	 */
	private $uopz_function_returns = [];

	/**
	 * Whether this test neutralised exit.
	 *
	 * @var bool
	 */
	private $uopz_exit_modified = false;

	/**
	 * Override a function's return value for the duration of the test.
	 *
	 * Pass a closure to have it invoked in place of the function.
	 *
	 * @since 1.0.0
	 *
	 * @param string $function_name Function to override.
	 * @param mixed  $return_value  Value to return, or a closure to execute.
	 *
	 * @return void
	 */
	protected function set_function_return( string $function_name, $return_value ): void {
		$this->skip_if_no_uopz();

		$this->uopz_function_returns[] = $function_name;

		uopz_set_return( $function_name, $return_value, $return_value instanceof \Closure );
	}

	/**
	 * Make exit a no-op so redirect branches can be asserted.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $allow Whether exit should terminate execution.
	 *
	 * @return void
	 */
	protected function allow_exit( bool $allow ): void {
		$this->skip_if_no_uopz();

		$this->uopz_exit_modified = true;

		uopz_allow_exit( $allow );
	}

	/**
	 * Skip the test when uopz is unavailable rather than failing confusingly.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function skip_if_no_uopz(): void {
		if ( ! extension_loaded( 'uopz' ) ) {
			$this->markTestSkipped( 'The uopz extension is required for this test.' );
		}
	}

	/**
	 * @since 1.0.0
	 *
	 * @after
	 *
	 * @return void
	 */
	protected function unset_uopz_returns(): void {
		foreach ( $this->uopz_function_returns as $function_name ) {
			uopz_unset_return( $function_name );
		}

		$this->uopz_function_returns = [];

		if ( $this->uopz_exit_modified ) {
			uopz_allow_exit( true );
			$this->uopz_exit_modified = false;
		}
	}
}
