<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber;

use Nexcess\PluginAbsorber\Exceptions\Config_Exception;

/**
 * One registered sub-plugin: its configuration, and every answer it can give without a
 * container-bound collaborator.
 *
 * Not the same as "configuration alone". is_already_loaded() reads the global constant table, and a
 * callable under `enabled` may query whatever the host likes. The line drawn here is dependency
 * direction: asking whether the standalone counterpart is active needs Checker_Interface, and
 * resolving one would put a container read in front of Absorber::register(), which deliberately
 * performs none so that the container may arrive at any point before boot. This object only names
 * the plugin to ask about.
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
				throw new Config_Exception(
					sprintf(
						'Sub-plugin config %1$s: required key "%2$s" is missing.',
						$this->describe_config_entry( $config ),
						$required
					)
				);
			}

			// Not just a truthiness check. An array survives one of those and then casts to the
			// string "Array", which every sub-plugin with the same mistake would share as its
			// registry key, its activation-tracking key, and its notice id.
			if ( ! is_string( $config[ $required ] ) || $config[ $required ] === '' ) {
				throw new Config_Exception(
					sprintf(
						'Sub-plugin config %1$s: key "%2$s" must be a non-empty string, %3$s given.',
						$this->describe_config_entry( $config ),
						$required,
						$this->describe_value( $config[ $required ] )
					)
				);
			}
		}

		// Rejected here rather than ignored at read time, where "not configured" and "configured
		// but uncallable" would collapse into the same answer. A dependency_check that is a
		// private method or a typo'd function name would otherwise report dependencies met and
		// let the load proceed into the fatal it exists to prevent.
		foreach ( self::CALLABLE_KEYS as $key ) {
			if ( isset( $config[ $key ] ) && ! is_callable( $config[ $key ] ) ) {
				throw new Config_Exception(
					sprintf(
						'Sub-plugin config %1$s: key "%2$s" must be callable, %3$s given.',
						$this->describe_config_entry( $config ),
						$key,
						$this->describe_value( $config[ $key ] )
					)
				);
			}
		}

		// A basename names a file already on disk; nothing about it waits on anything. A closure
		// under it would reach deactivate_plugins() as the string "Closure", so it is worth a loud
		// failure at registration rather than a puzzling one much later.
		foreach ( self::STRING_KEYS as $key ) {
			if ( isset( $config[ $key ] ) && ! is_string( $config[ $key ] ) ) {
				throw new Config_Exception(
					sprintf(
						'Sub-plugin config %1$s: key "%2$s" must be a string, %3$s given.',
						$this->describe_config_entry( $config ),
						$key,
						$this->describe_value( $config[ $key ] )
					)
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
					sprintf(
						'Sub-plugin config %1$s: key "%2$s" must be a policy string or a non-string'
							. ' callable, %3$s given.',
						$this->describe_config_entry( $config ),
						$key,
						$this->describe_value( $config[ $key ] )
					)
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
					sprintf(
						'Sub-plugin config %1$s: key "%2$s" must be a non-string callable, %3$s'
							. ' given. Wrap the text in a callable, e.g.'
							. " static function () { return __( 'text' ); }, so that it is"
							. ' translated after init rather than while the config array is built.',
						$this->describe_config_entry( $config ),
						$key,
						$this->describe_value( $config[ $key ] )
					)
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
	 * Shown on multisite when a network-active standalone is left active because the host plugin is
	 * not itself network-activated -- deactivating it network-wide would strand the sites the host
	 * never reached. Self-contained, with no config key: the text has no per-host variant worth a
	 * registration-time value, and the filter below is the seam for rewording or translating it.
	 *
	 * @since 1.0.0
	 *
	 * @throws Config_Exception When no hook prefix has been set.
	 *
	 * @return string
	 */
	public function get_stranding_notice_message(): string {
		$message = sprintf(
			'%1$s was left active. Its bundled copy loads only where the host plugin is active, and '
			. 'the host plugin is not network-activated, so deactivating %1$s across the network would '
			. 'leave the sites without the host plugin with no copy of it at all. To finish absorbing '
			. "it, either network-activate the host plugin, or deactivate %1\$s yourself from the "
			. "Network Admin's Plugins screen.",
			$this->get_slug()
		);

		/**
		 * Filters the notice shown when a network-active standalone is left active to avoid stranding
		 * the sites a host that is not network-activated does not reach.
		 *
		 * The dynamic portion of the hook name, `$hook_prefix`, is the prefix given to
		 * Config::set_hook_prefix().
		 *
		 * Fires when the message is asked for rather than when the sub-plugin is registered, which is
		 * what makes this the place to translate the default: the textdomain is loaded by then.
		 *
		 * @since 1.0.0
		 *
		 * @param string     $message    The generic, untranslated default.
		 * @param Sub_Plugin $sub_plugin The sub-plugin left active.
		 */
		$message = apply_filters( Config::get_hook_name( 'stranding_notice_message' ), $message, $this );

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

	/**
	 * Name the entry a rejection is about, for a host registering several from one loop.
	 *
	 * A host calling Absorber::register() inside a loop -- over a manifest array of five entries,
	 * for example -- gets the same file and line in the stack trace whichever entry was rejected,
	 * because there is only the one call. The rest of the message names the key at fault; only this
	 * names the entry that key belongs to.
	 *
	 * The slug first, because it is what every other message about a sub-plugin names, and the
	 * bundled file when the slug is itself the key at fault -- it identifies the entry just as well,
	 * and it names the file the host has to go and look at. Both are read defensively, since either
	 * may be the missing or malformed key being reported; when neither can be read, saying so is
	 * still better than a message that quietly names nothing.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $config Configuration exactly as the host supplied it.
	 *
	 * @return string
	 */
	private function describe_config_entry( array $config ): string {
		foreach ( [ 'slug', 'bundled_plugin_file' ] as $identifier ) {
			$value = $config[ $identifier ] ?? null;

			if ( is_string( $value ) && $value !== '' ) {
				return sprintf( 'for "%s"', $value );
			}
		}

		return 'with no usable slug or bundled_plugin_file';
	}

	/**
	 * Name the type a key was given, so that a stray value can be found rather than hunted for.
	 *
	 * An object reports its class: "object given" leaves a Closure and a WP_Error looking the same,
	 * and they are very different mistakes. The empty string is named as one, because "string given"
	 * under a key that must be a non-empty string says nothing the host does not already know.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Value the host put under the key.
	 *
	 * @return string
	 */
	private function describe_value( $value ): string {
		if ( is_object( $value ) ) {
			return get_class( $value );
		}

		if ( $value === '' ) {
			return 'empty string';
		}

		return gettype( $value );
	}
}
