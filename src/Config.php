<?php
/**
 * @package Nexcess\PluginAbsorber
 */

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
	 * @var string
	 */
	protected static $version = '';

	/**
	 * @var ContainerInterface|null
	 */
	protected static $container = null;

	/**
	 * Set the unique per-host slug that keys hooks, transients, and the activation option.
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
	 * @since 1.0.0
	 *
	 * @param string $version Host plugin version.
	 *
	 * @return void
	 */
	public static function set_version( string $version ): void {
		self::$version = $version;
	}

	/**
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function get_version(): string {
		return self::$version;
	}

	/**
	 * Share the host's container so collaborators become bindable.
	 *
	 * Entirely optional — with no container the library instantiates its own defaults.
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
	 * @return ContainerInterface|null
	 */
	public static function get_container(): ?ContainerInterface {
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
	 * Reset all static state. Test seam.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$hook_prefix = '';
		self::$version     = '';
		self::$container   = null;
	}
}
