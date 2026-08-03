<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber;

use Nexcess\PluginAbsorber\Exceptions\Config_Exception;

/**
 * One registered sub-plugin: its configuration and every decision about it.
 *
 * @since 1.0.0
 */
class Sub_Plugin {
	/**
	 * @var array<string,mixed>
	 */
	private $config;

	/**
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $config Sub-plugin configuration.
	 *
	 * @throws Config_Exception When a required key is missing or empty.
	 */
	public function __construct( array $config ) {
		foreach ( [ 'slug', 'bundled_plugin_file', 'plugin_loaded_constant' ] as $required ) {
			if ( empty( $config[ $required ] ) ) {
				throw new Config_Exception( "Sub-plugin config is missing required key: {$required}" );
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
	 * The result is deliberately not validated here: a filter may legitimately return anything,
	 * and rejecting it at this boundary would hide the override rather than report it. Callers
	 * that dispatch on the value check it with Conflict_Policy::is_valid().
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_conflict_policy(): string {
		$policy = $this->config['conflict_policy'] ?? Conflict_Policy::DEACTIVATE;

		if ( is_callable( $policy ) ) {
			$policy = $policy( $this );
		}

		return (string) apply_filters(
			Config::get_hook_prefix() . '/plugin_absorber/conflict_policy',
			$policy,
			$this
		);
	}

	/**
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		$enabled = $this->config['enabled'] ?? true;

		return (bool) ( is_callable( $enabled ) ? $enabled() : $enabled );
	}

	/**
	 * True when the plugin's code is already present, from either copy. The fatal guard.
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
	public function is_standalone_plugin_active(): bool {
		if ( ! $this->has_standalone_plugin() ) {
			return false;
		}

		$this->load_plugin_functions();

		return is_plugin_active( $this->get_standalone_plugin_basename() )
			|| is_plugin_active_for_network( $this->get_standalone_plugin_basename() );
	}

	/**
	 * Whether the standalone is network-activated.
	 *
	 * Deactivating it requires passing $network_wide to deactivate_plugins(); without that the
	 * call silently no-ops and the resolver redirects forever.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_standalone_plugin_network_active(): bool {
		if ( ! $this->has_standalone_plugin() ) {
			return false;
		}

		$this->load_plugin_functions();

		return is_plugin_active_for_network( $this->get_standalone_plugin_basename() );
	}

	/**
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function are_dependencies_met(): bool {
		$check = $this->config['dependency_check'] ?? null;

		return is_callable( $check ) ? (bool) $check() : true;
	}

	/**
	 * Shown when the standalone is auto-deactivated, and when the user tries to re-activate it.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_conflict_notice_message(): string {
		return $this->resolve_message( $this->config['conflict_notice_message'] ?? '' );
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
		return (string) ( is_callable( $message ) ? $message() : $message );
	}

	/**
	 * WordPress only loads these in the admin, and we run at plugins_loaded on every request.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function load_plugin_functions(): void {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}
}
