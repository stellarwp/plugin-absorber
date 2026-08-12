<?php
/**
 * Sets the request method for a test, and puts back whatever was there before.
 *
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Support\Traits;

/**
 * One shared way to say what kind of request a test describes.
 *
 * `$_SERVER` outlives the test that wrote to it, so a test that sets the method and does not put the
 * old value back decides what every later test in the process looks like — and the gate that reads it
 * runs on `plugins_loaded`, so the test that then fails is rarely the one that changed it.
 *
 * Absence is restored as absence rather than as an empty string: whether the key exists at all is the
 * difference between a CLI or cron request and a web one, and half the point of setting a method is
 * to describe a request that is not the harness's.
 *
 * @since 1.0.0
 */
trait WithRequestMethod {
	/**
	 * What `$_SERVER` held before the first set_request_method() call, when it held anything.
	 *
	 * @var mixed
	 */
	private $original_request_method;

	/**
	 * Whether the key existed at that point, kept apart from the value so that "absent" is not
	 * indistinguishable from "present and empty".
	 *
	 * @var bool
	 */
	private $had_request_method = false;

	/**
	 * Whether the original has been captured yet.
	 *
	 * @var bool
	 */
	private $request_method_remembered = false;

	/**
	 * Make the request look like the one the test describes.
	 *
	 * The original is remembered on the first call only, so a test that switches method mid-body —
	 * GET in setUp, POST for the assertion — still restores what the harness had rather than the GET
	 * it set itself.
	 *
	 * @since 1.0.0
	 *
	 * @param string $method Request method to present, e.g. 'GET'.
	 *
	 * @return void
	 */
	protected function set_request_method( string $method ): void {
		if ( ! $this->request_method_remembered ) {
			$this->had_request_method        = array_key_exists( 'REQUEST_METHOD', $_SERVER );
			$this->original_request_method   = $this->had_request_method ? $_SERVER['REQUEST_METHOD'] : null;
			$this->request_method_remembered = true;
		}

		$_SERVER['REQUEST_METHOD'] = $method;
	}

	/**
	 * Put back what was there. Call from tearDown.
	 *
	 * A no-op when nothing was set, so a test that skipped before it got that far does not unset a
	 * value it never touched.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function restore_request_method(): void {
		if ( ! $this->request_method_remembered ) {
			return;
		}

		if ( $this->had_request_method ) {
			$_SERVER['REQUEST_METHOD'] = $this->original_request_method;
		} else {
			unset( $_SERVER['REQUEST_METHOD'] );
		}

		$this->original_request_method   = null;
		$this->had_request_method        = false;
		$this->request_method_remembered = false;
	}
}
