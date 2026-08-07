<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber;

use Nexcess\PluginAbsorber\Exceptions\Config_Exception;

/**
 * One registered sub-plugin: its configuration, and the answers that configuration alone decides.
 *
 * Deliberately not a window onto WordPress. Asking whether the standalone counterpart is active is
 * a question about the site rather than about this configuration, and it belongs to
 * Plugin_State_Interface; this object only names the plugin to ask about.
 *
 * @since 1.0.0
 */
class Sub_Plugin {
	/**
	 * Keys without which this object cannot do its job.
	 *
	 * @since 1.0.0
	 *
	 * @var string[]
	 */
	private const REQUIRED_KEYS = [ 'slug', 'bundled_plugin_file', 'plugin_loaded_constant' ];

	/**
	 * Keys that are only ever a callable, never a value.
	 *
	 * @since 1.0.0
	 *
	 * @var string[]
	 */
	private const CALLABLE_KEYS = [ 'dependency_check', 'activation_callback' ];

	/**
	 * @var array<string,mixed>
	 */
	private $config;

	/**
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $config Sub-plugin configuration.
	 *
	 * @throws Config_Exception When a required key is missing, empty, or not a string, or when a
	 *                          callable-only key holds something that cannot be called.
	 */
	public function __construct( array $config ) {
		foreach ( self::REQUIRED_KEYS as $required ) {
			if ( ! isset( $config[ $required ] ) ) {
				throw new Config_Exception( "Sub-plugin config is missing required key: {$required}" );
			}

			// Not just a truthiness check. An array survives one of those and then casts to the
			// string "Array", which every sub-plugin with the same mistake would share as its
			// registry key, its activation-tracking key, and its notice id.
			if ( ! is_string( $config[ $required ] ) || $config[ $required ] === '' ) {
				throw new Config_Exception(
					"Sub-plugin config key must be a non-empty string: {$required}"
				);
			}
		}

		// Rejected here rather than ignored at read time, where "not configured" and "configured
		// but uncallable" would collapse into the same answer. A dependency_check that is a
		// private method or a typo'd function name would otherwise report dependencies met and
		// let the load proceed into the fatal it exists to prevent.
		foreach ( self::CALLABLE_KEYS as $key ) {
			if ( isset( $config[ $key ] ) && ! is_callable( $config[ $key ] ) ) {
				throw new Config_Exception( "Sub-plugin config key must be callable: {$key}" );
			}
		}

		$this->config = $config;
	}

	/**
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return (string) $this->config['slug'];
	}

	/**
	 * Absolute path to the bundled plugin's main file — the file we require_once.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_bundled_plugin_file(): string {
		return (string) $this->config['bundled_plugin_file'];
	}

	/**
	 * Constant both the bundled copy and the standalone define when they load.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_plugin_loaded_constant(): string {
		return (string) $this->config['plugin_loaded_constant'];
	}

	/**
	 * Resolve the policy from a string or callable, then let the filter override it.
	 *
	 * The result is not checked against the known policies here: a filter may legitimately
	 * return anything, and rejecting it at this boundary would hide the override rather than
	 * report it. Callers that dispatch on the value check it with Conflict_Policy::is_valid().
	 *
	 * @since 1.0.0
	 *
	 * @throws Config_Exception When no hook prefix has been set.
	 *
	 * @return string
	 */
	public function get_conflict_policy(): string {
		$policy = $this->config['conflict_policy'] ?? Conflict_Policy::default();

		$policy = apply_filters(
			Config::get_hook_prefix() . '/plugin_absorber/conflict_policy',
			$this->resolve_callable( $policy ),
			$this
		);

		// A filter returning an object or an array is a mistake, and casting one would be a
		// fatal at plugins_loaded. An empty string is not a valid policy, so it routes to the
		// conservative branch instead.
		return is_scalar( $policy ) ? (string) $policy : '';
	}

	/**
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return (bool) $this->resolve_callable( $this->config['enabled'] ?? true );
	}

	/**
	 * True when the plugin's code is already present, from either copy. The fatal guard.
	 *
	 * Only sound when the constant is defined at file scope. A standalone that defines it from a
	 * bootstrap hooked at plugins_loaded or later has not defined it yet when this is asked.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_already_loaded(): bool {
		return defined( $this->get_plugin_loaded_constant() );
	}

	/**
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function has_standalone_plugin(): bool {
		return ! empty( $this->config['standalone_plugin_basename'] );
	}

	/**
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_standalone_plugin_basename(): string {
		return (string) ( $this->config['standalone_plugin_basename'] ?? '' );
	}

	/**
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function are_dependencies_met(): bool {
		$check = $this->config['dependency_check'] ?? null;

		if ( $check === null ) {
			return true;
		}

		return (bool) $check( $this );
	}

	/**
	 * Shown when the standalone is auto-deactivated, and when the user tries to re-activate it.
	 *
	 * The fallback is a parameter because the two contexts want different wording, and because
	 * a caller with no fallback of its own would otherwise render nothing at all.
	 *
	 * @since 1.0.0
	 *
	 * @param string $default Used when nothing is configured.
	 *
	 * @return string
	 */
	public function get_conflict_notice_message( string $default = '' ): string {
		$message = $this->resolve_message( $this->config['conflict_notice_message'] ?? '' );

		return $message !== '' ? $message : $default;
	}

	/**
	 * Shown when a dependency_check fails. Falls back to a generic, untranslated sentence —
	 * pass a callable returning __() to localise it.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_dependency_notice_message(): string {
		$message = $this->resolve_message( $this->config['dependency_notice_message'] ?? '' );

		if ( $message !== '' ) {
			return $message;
		}

		return sprintf(
			'%s could not be loaded because its requirements are not met.',
			$this->get_slug()
		);
	}

	/**
	 * @since 1.0.0
	 *
	 * @return callable|null
	 */
	public function get_activation_callback(): ?callable {
		$callback = $this->config['activation_callback'] ?? null;

		return is_callable( $callback ) ? $callback : null;
	}

	/**
	 * Resolve a string-or-callable message.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $message Configured message.
	 *
	 * @return string
	 */
	private function resolve_message( $message ): string {
		$message = $this->resolve_callable( $message );

		return is_scalar( $message ) ? (string) $message : '';
	}

	/**
	 * Call a configured value if it is meant to be called, and return it as-is otherwise.
	 *
	 * Strings are returned untouched even when a function of that name exists. is_callable()
	 * alone would treat a policy or a message read from an option as a function to invoke —
	 * "date" and "flush" are both valid function names and plausible option values.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Configured value.
	 *
	 * @return mixed
	 */
	private function resolve_callable( $value ) {
		if ( is_string( $value ) || is_bool( $value ) || ! is_callable( $value ) ) {
			return $value;
		}

		return $value( $this );
	}
}
