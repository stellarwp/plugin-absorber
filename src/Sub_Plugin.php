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
 *
 * @phpstan-type Sub_Plugin_Config array{
 *     slug: string,
 *     bundled_plugin_file: string,
 *     plugin_loaded_constant: string,
 *     enabled?: bool|callable,
 *     conflict_policy?: string,
 *     standalone_plugin_basename?: string,
 *     dependency_check?: callable,
 *     conflict_notice_message?: string,
 *     dependency_notice_message?: string,
 *     activation_callback?: callable
 * }
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
	 * Optional keys that are only ever a string, never a callable.
	 *
	 * @since 1.0.0
	 *
	 * @var string[]
	 */
	private const STRING_KEYS = [
		'standalone_plugin_basename',
		'conflict_policy',
		'conflict_notice_message',
		'dependency_notice_message',
	];

	/**
	 * Validated configuration, keyed as the constructor accepts it.
	 *
	 * @since 1.0.0
	 *
	 * @var Sub_Plugin_Config
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

		// These keys take a value, not a decision. A closure under one of them would render as the
		// string "Closure" at best, so it is worth a loud failure at registration rather than a
		// puzzling notice much later; the filters are how these are decided at runtime.
		foreach ( self::STRING_KEYS as $key ) {
			if ( isset( $config[ $key ] ) && ! is_string( $config[ $key ] ) ) {
				throw new Config_Exception(
					"Sub-plugin config key must be a string, and is filterable at runtime: {$key}"
				);
			}
		}

		// The loops above have proved every key this class reads. Only `enabled` is read without a
		// type behind it, and its two forms -- a bool and a callable -- cannot be confused.
		/** @var Sub_Plugin_Config $config */
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
	 * The configured policy, after the filter has had the final say.
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
		return $this->filter_string(
			'conflict_policy',
			(string) ( $this->config['conflict_policy'] ?? Conflict_Policy::default() )
		);
	}

	/**
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		$enabled = $this->config['enabled'] ?? true;

		// A bool is never callable, so nothing here is guessing at intent the way it would if this
		// key also took a string. Every callable form works, a plain function name included, and a
		// callable is re-evaluated on each call rather than resolved once at registration.
		return (bool) ( is_callable( $enabled ) ? $enabled( $this ) : $enabled );
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
	 * @throws Config_Exception When no hook prefix has been set.
	 *
	 * @return string
	 */
	public function get_conflict_notice_message( string $default = '' ): string {
		$message = (string) ( $this->config['conflict_notice_message'] ?? '' );

		return $this->filter_string( 'conflict_notice_message', $message !== '' ? $message : $default );
	}

	/**
	 * Shown when a dependency_check fails. Falls back to a generic, untranslated sentence —
	 * hook the filter to localise it, which also runs late enough for the textdomain to be loaded.
	 *
	 * @since 1.0.0
	 *
	 * @throws Config_Exception When no hook prefix has been set.
	 *
	 * @return string
	 */
	public function get_dependency_notice_message(): string {
		$message = (string) ( $this->config['dependency_notice_message'] ?? '' );

		if ( $message === '' ) {
			$message = sprintf(
				'%s could not be loaded because its requirements are not met.',
				$this->get_slug()
			);
		}

		return $this->filter_string( 'dependency_notice_message', $message );
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
	 * Let the host have the last word on a configured string.
	 *
	 * This is where a runtime decision belongs. The alternative — accepting a callable under the
	 * key itself — cannot work for a key whose value is a string, because a string naming a
	 * function is indistinguishable from a string meaning itself, and which one you got would
	 * depend on what else the site happens to have loaded.
	 *
	 * Filtering last, after the configured value and any fallback, is also what makes deferred
	 * translation possible: the hook fires when the message is asked for rather than when the
	 * sub-plugin is registered, by which time the textdomain is loaded.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook  Hook name, less the prefix this library shares.
	 * @param string $value Value to filter.
	 *
	 * @throws Config_Exception When no hook prefix has been set.
	 *
	 * @return string
	 */
	private function filter_string( string $hook, string $value ): string {
		$filtered = apply_filters(
			Config::get_hook_prefix() . '/plugin_absorber/' . $hook,
			$value,
			$this
		);

		// A filter returning an object or an array is a mistake, and casting one would be a fatal
		// at plugins_loaded. An empty string is not a valid policy and renders no notice, so both
		// route to the conservative branch instead.
		return is_scalar( $filtered ) ? (string) $filtered : '';
	}
}
