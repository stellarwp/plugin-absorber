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
 * Checker_Interface; this object only names the plugin to ask about.
 *
 * @since 1.0.0
 *
 * @phpstan-type Sub_Plugin_Config array{
 *     slug: string,
 *     bundled_plugin_file: string,
 *     plugin_loaded_constant: string,
 *     enabled?: bool|callable,
 *     conflict_policy?: string|callable,
 *     standalone_plugin_basename?: string,
 *     dependency_check?: callable,
 *     conflict_notice_message?: callable,
 *     dependency_notice_message?: callable,
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
	private const STRING_KEYS = [ 'standalone_plugin_basename' ];

	/**
	 * Keys that take a string, or something to call for one when it is wanted.
	 *
	 * @since 1.0.0
	 *
	 * @var string[]
	 */
	private const DEFERRABLE_KEYS = [ 'conflict_policy' ];

	/**
	 * Keys that carry human-readable text, and so only ever hold something to call for it.
	 *
	 * @since 1.0.0
	 *
	 * @var string[]
	 */
	private const MESSAGE_KEYS = [ 'conflict_notice_message', 'dependency_notice_message' ];

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
	 * @throws Config_Exception When a required key is missing, empty, or not a string; when a
	 *                          callable-only key holds something that cannot be called; or when a
	 *                          message key holds a string rather than something to call for one.
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

		// A basename names a file already on disk; nothing about it waits on anything. A closure
		// under it would reach deactivate_plugins() as the string "Closure", so it is worth a loud
		// failure at registration rather than a puzzling one much later.
		foreach ( self::STRING_KEYS as $key ) {
			if ( isset( $config[ $key ] ) && ! is_string( $config[ $key ] ) ) {
				throw new Config_Exception(
					"Sub-plugin config key must be a string: {$key}"
				);
			}
		}

		// Anything that is neither a string nor a callable is rejected where the mistake was made.
		// At read time an array would cast to the string "Array", and a [ class, method ] pair
		// naming a method that does not exist would become the value itself.
		foreach ( self::DEFERRABLE_KEYS as $key ) {
			if ( ! isset( $config[ $key ] ) ) {
				continue;
			}

			if ( ! is_string( $config[ $key ] ) && ! is_callable( $config[ $key ] ) ) {
				throw new Config_Exception(
					"Sub-plugin config key must be a string, or a callable that is not one: {$key}"
				);
			}
		}

		// A string is refused outright here, unlike everywhere else. Text a host has already
		// translated cannot be told from text it has not, and a config array is built before init
		// -- so accepting the one shape that can only have been produced too early would leave the
		// just-in-time textdomain notice these keys exist to prevent. One `static fn()` at the call
		// site fails here the first time the code runs, rather than in someone else's log.
		foreach ( self::MESSAGE_KEYS as $key ) {
			if ( ! isset( $config[ $key ] ) ) {
				continue;
			}

			if ( is_string( $config[ $key ] ) || ! is_callable( $config[ $key ] ) ) {
				throw new Config_Exception(
					"Sub-plugin config key must be a callable that is not a string: {$key}"
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
		$policy = $this->config['conflict_policy'] ?? Conflict_Policy::default();
		$policy = $this->as_string( $this->resolve_deferred( $policy ) );

		/**
		 * Filters the policy applied when a standalone copy of a sub-plugin is found active.
		 *
		 * The dynamic portion of the hook name, `$hook_prefix`, is the prefix given to
		 * Config::set_hook_prefix().
		 *
		 * Fires after the configured value and its default, so a host can decide per request
		 * rather than at registration. Returning a value that is not one of the Conflict_Policy
		 * constants is not consent to deactivate — callers leave the standalone alone instead.
		 *
		 * @since 1.0.0
		 *
		 * @param string     $policy     One of the Conflict_Policy constants.
		 * @param Sub_Plugin $sub_plugin The sub-plugin the policy applies to.
		 */
		$policy = apply_filters( Config::get_hook_name( 'conflict_policy' ), $policy, $this );

		// An empty string matches no known policy, so a filter that returned something uncastable
		// lands at the conservative branch rather than at a deactivation.
		return $this->as_string( $policy );
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
	 * Configured as a callable and never as a string, so that __() cannot run in the config array,
	 * where it would run before the textdomain is loaded.
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
		$configured = $this->config['conflict_notice_message'] ?? '';
		$message    = $this->as_string( $this->resolve_deferred( $configured ) );

		if ( $message === '' ) {
			$message = $default;
		}

		/**
		 * Filters the notice shown when a standalone copy is deactivated, and when the user tries
		 * to activate it again.
		 *
		 * The dynamic portion of the hook name, `$hook_prefix`, is the prefix given to
		 * Config::set_hook_prefix().
		 *
		 * Fires when the message is asked for rather than when the sub-plugin is registered, which
		 * is what makes this the place to translate it: the textdomain is loaded by then.
		 *
		 * @since 1.0.0
		 *
		 * @param string     $message    The configured message, or the caller's fallback.
		 * @param Sub_Plugin $sub_plugin The sub-plugin in conflict.
		 */
		$message = apply_filters( Config::get_hook_name( 'conflict_notice_message' ), $message, $this );

		// An empty string renders no notice, which is where a filter returning an array or an
		// object lands rather than in a fatal cast.
		return $this->as_string( $message );
	}

	/**
	 * Shown when a dependency_check fails. Falls back to a generic, untranslated sentence — the key
	 * takes a callable, and never a string, so that a host's own __() cannot run in its config
	 * array, before the textdomain is loaded.
	 *
	 * @since 1.0.0
	 *
	 * @throws Config_Exception When no hook prefix has been set.
	 *
	 * @return string
	 */
	public function get_dependency_notice_message(): string {
		$configured = $this->config['dependency_notice_message'] ?? '';
		$message    = $this->as_string( $this->resolve_deferred( $configured ) );

		if ( $message === '' ) {
			$message = sprintf(
				'%s could not be loaded because its requirements are not met.',
				$this->get_slug()
			);
		}

		/**
		 * Filters the notice shown when a sub-plugin's dependency_check fails.
		 *
		 * The dynamic portion of the hook name, `$hook_prefix`, is the prefix given to
		 * Config::set_hook_prefix().
		 *
		 * Fires when the message is asked for rather than when the sub-plugin is registered, which
		 * is what makes this the place to translate the generic default: the textdomain is loaded
		 * by then.
		 *
		 * @since 1.0.0
		 *
		 * @param string     $message    The configured message, or a generic untranslated one.
		 * @param Sub_Plugin $sub_plugin The sub-plugin whose dependencies are unmet.
		 */
		$message = apply_filters( Config::get_hook_name( 'dependency_notice_message' ), $message, $this );

		// An empty string renders no notice, which is where a filter returning an array or an
		// object lands rather than in a fatal cast.
		return $this->as_string( $message );
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
	 * Take a configured value as it stands, or call it for one.
	 *
	 * A string is always the value itself, however real a function of that name happens to be.
	 * `date`, `flush` and `key` are all existing functions and all plausible configured values, so
	 * honouring a string as a call would make the answer depend on what else the site had loaded --
	 * and calling one of those with a Sub_Plugin argument is a fatal, not a value. Only the policy
	 * arrives here as one; the message keys take no string at all.
	 *
	 * Every other callable form says "call me" and nothing else: a closure, a [ class, method ]
	 * pair, an invokable object, a container callback. That is what lets a host defer __() to the
	 * moment the text is wanted, rather than translating while it builds its config array -- which
	 * happens before init, and is what raises WordPress's just-in-time textdomain notice.
	 *
	 * Called on every read. Resolving once in the constructor would move the too-early call from
	 * the config array to the line after it.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Configured value, proved a string or a callable at registration.
	 *
	 * @return mixed
	 */
	private function resolve_deferred( $value ) {
		// The constructor has already made this good. It stays because reaching a call on something
		// uncallable would be a fatal where the wrong shape is merely a notice that says so.
		if ( is_string( $value ) || ! is_callable( $value ) ) {
			return $value;
		}

		return $value( $this );
	}

	/**
	 * Reduce whatever a filter or a configured callable returned to a string.
	 *
	 * Only the cast is shared. Each filter is applied at the method that owns it rather than
	 * through a common helper, so that every hook keeps a documented call site a reader and a hook
	 * scanner can both find, and a version of its own.
	 *
	 * Both run at plugins_loaded, where casting an array or an object would be a fatal. Anything
	 * uncastable is treated as though nothing had come back at all; what nothing means is for each
	 * caller to say.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Whatever came back from apply_filters() or from a configured callable.
	 *
	 * @return string
	 */
	private function as_string( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}
}
