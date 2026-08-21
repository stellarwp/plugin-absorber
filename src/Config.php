<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber;

use Nexcess\PluginAbsorber\Exceptions\Config_Exception;
use StellarWP\ContainerContract\ContainerInterface;

/**
 * Static configuration facade.
 *
 * @since 1.0.0
 */
class Config {
	/**
	 * @var string
	 */
	protected static $hook_prefix = '';

	/**
	 * @var ContainerInterface|null
	 */
	protected static $container = null;

	/**
	 * @var string
	 */
	protected static $host_plugin_basename = '';

	/**
	 * Set the unique per-host slug that keys this library's hooks and options.
	 *
	 * It is stored exactly as given: hook names repeat it verbatim, and only `get_option_name()`
	 * folds it.
	 *
	 * @since 1.0.0
	 *
	 * @param string $prefix Host slug.
	 *
	 * @throws Config_Exception When the prefix is empty or contains unsupported characters.
	 *
	 * @return void
	 */
	public static function set_hook_prefix( string $prefix ): void {
		if ( $prefix === '' ) {
			throw new Config_Exception( 'The hook prefix cannot be empty.' );
		}

		if ( preg_match( '/[^a-zA-Z0-9_-]/', $prefix ) ) {
			throw new Config_Exception(
				'Hook prefix must only contain letters, numbers, hyphens, and underscores.'
			);
		}

		self::$hook_prefix = $prefix;
	}

	/**
	 * @since 1.0.0
	 *
	 * @throws Config_Exception When no prefix has been set.
	 *
	 * @return string
	 */
	public static function get_hook_prefix(): string {
		if ( self::$hook_prefix === '' ) {
			throw new Config_Exception(
				'You must call Config::set_hook_prefix() before booting the Plugin Absorber.'
			);
		}

		return self::$hook_prefix;
	}

	/**
	 * Build the name of one of this library's filters.
	 *
	 * The prefix lives here, so everything derived from it is built here too. A collaborator that
	 * assembled its own name would be repeating the segment between the host's prefix and the
	 * hook's own name, and would have to be found and corrected if it ever changed.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name Hook name, less the prefix and this library's namespace.
	 *
	 * @throws Config_Exception When no prefix has been set.
	 *
	 * @return string
	 */
	public static function get_hook_name( string $name ): string {
		return self::get_hook_prefix() . '/plugin_absorber/' . $name;
	}

	/**
	 * Build the name of one of this library's options.
	 *
	 * The prefix is a hook-naming value: `set_hook_prefix()` takes anything WordPress will accept
	 * inside a filter name, mixed case and hyphens included, and `get_hook_name()` repeats it byte
	 * for byte — a host that passed `Give-Core` must be able to hook
	 * `Give-Core/plugin_absorber/should_load` and have it fire. A storage key answers to a
	 * narrower convention, so the folding happens here and nowhere else: the same prefix produces
	 * `give_core_plugin_absorber_notices`.
	 *
	 * The `_plugin_absorber_` segment lives here for the reason `get_hook_name()` gives for its
	 * own: the notice queue is not the only option keyed this way, and a segment each caller
	 * assembled would have to be found in every one of them if it ever changed.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name Option name, less the prefix and this library's namespace.
	 *
	 * @throws Config_Exception When no prefix has been set.
	 *
	 * @return string
	 */
	public static function get_option_name( string $name ): string {
		return self::get_option_prefix() . '_plugin_absorber_' . $name;
	}

	/**
	 * Share the host's container. Required, and required before boot().
	 *
	 * Every collaborator this library uses is resolved from it, which is what makes each of them
	 * replaceable by binding an interface. There is no second, container-less path to keep working
	 * beside that one: two ways to reach a collaborator means two sets of behaviour to reason
	 * about, and the one nobody runs is the one that rots.
	 *
	 * @since 1.0.0
	 *
	 * @param ContainerInterface $container Host container.
	 *
	 * @return void
	 */
	public static function set_container( ContainerInterface $container ): void {
		self::$container = $container;
	}

	/**
	 * @since 1.0.0
	 *
	 * @throws Config_Exception When no container has been set.
	 *
	 * @return ContainerInterface
	 */
	public static function get_container(): ContainerInterface {
		if ( self::$container === null ) {
			throw new Config_Exception(
				'You must call Config::set_container() before booting the Plugin Absorber.'
			);
		}

		return self::$container;
	}

	/**
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function has_container(): bool {
		return self::$container !== null;
	}

	/**
	 * Tell the library the host plugin's own basename, so a standalone's activation scope can be
	 * compared against the host's on multisite.
	 *
	 * Optional, and read by one thing: the conflict resolver's guard against deactivating a
	 * network-active standalone whose bundled replacement ships in a host that is not itself
	 * network-active -- a deactivation that would strand the sites the host never reached. Left
	 * unset, that guard stands down and deactivation behaves exactly as it always has. Stored
	 * exactly as given: a basename is a path, `directory/file.php`, not a hook-naming value, so the
	 * hook-prefix validator's character rules deliberately do not apply here.
	 *
	 * @since 1.0.0
	 *
	 * @param string $basename Host plugin basename, e.g. `plugin_basename( __FILE__ )`.
	 *
	 * @return void
	 */
	public static function set_host_plugin_basename( string $basename ): void {
		self::$host_plugin_basename = $basename;
	}

	/**
	 * The host plugin basename, or an empty string when none was set.
	 *
	 * Does not throw the way `get_hook_prefix()` and `get_container()` do. Those name a step the host
	 * must take before boot; this is optional, and an empty string is the honest answer for a host
	 * that did not opt into the multisite guard -- the same value that stands the guard down.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function get_host_plugin_basename(): string {
		return self::$host_plugin_basename;
	}

	/**
	 * The hook prefix folded into the shape a storage key takes.
	 *
	 * Only option names are folded, and only on the way out — the stored prefix keeps whatever the
	 * host passed, because that is what its hook names are made of. Two prefixes differing only in
	 * case or in hyphens against underscores would land on the same option, which is the price of
	 * asking a host for one prefix rather than two; no host runs both `Give-Core` and `give_core`.
	 *
	 * @since 1.0.0
	 *
	 * @throws Config_Exception When no prefix has been set.
	 *
	 * @return string
	 */
	private static function get_option_prefix(): string {
		return strtolower( str_replace( '-', '_', self::get_hook_prefix() ) );
	}
}
