<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Notices;

use Nexcess\PluginAbsorber\Config;
use Nexcess\PluginAbsorber\Exceptions\Config_Exception;

/**
 * Where the notice queue lives: one option, keyed `slug:type`.
 *
 * An option rather than a transient. With an external object cache, `set_transient()` never
 * touches the database at all — the queue would live only in Redis or Memcached, where a routine
 * `wp_cache_flush()` from a deploy script or a "purge cache" button destroys it. The merge notice
 * is raised once and never again, so losing it means a site owner is never told their plugin was
 * deactivated. This queue is not a cache.
 *
 * Separate from Queue so that changing where notices are kept does not mean touching how they are
 * worded or drawn.
 *
 * @since 1.0.0
 */
class Store {
	/**
	 * The option name backing the queue. Read it directly to render these notices yourself.
	 *
	 * @since 1.0.0
	 *
	 * @throws Config_Exception When no hook prefix has been set.
	 *
	 * @return string
	 */
	public static function option_name(): string {
		return Config::get_hook_prefix() . '_plugin_absorber_notices';
	}

	/**
	 * Every queued message, with anything that is not one dropped.
	 *
	 * @since 1.0.0
	 *
	 * @throws Config_Exception When no hook prefix has been set.
	 *
	 * @return array<string,string>
	 */
	public function all(): array {
		// Outside multisite `get_site_option()` is `get_option()`, so this reads back whatever
		// put() wrote on either install type.
		$queue = get_site_option( self::option_name(), [] );

		if ( ! is_array( $queue ) ) {
			return [];
		}

		// Anything that is not a string message is dropped rather than printed. put() writes the
		// filtered array back, so a corrupted entry heals itself.
		return array_filter( $queue, 'is_string' );
	}

	/**
	 * Store one message, replacing any it already holds under the same key.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key     Queue key, `slug:type`.
	 * @param string $message Resolved message.
	 *
	 * @throws Config_Exception When no hook prefix has been set.
	 *
	 * @return void
	 */
	public function put( string $key, string $message ): void {
		$queue = $this->all();

		$queue[ $key ] = $message;

		// One call covers both install types. Outside multisite `update_site_option()` ends in
		// `update_option( $option, $value, false )`, or `add_option( $option, $value, '', false )`
		// the first time — either way autoload is off, which is exactly what this queue wants: it
		// is empty on almost every request and only ever read in the admin.
		update_site_option( self::option_name(), $queue );
	}

	/**
	 * @since 1.0.0
	 *
	 * @throws Config_Exception When no hook prefix has been set.
	 *
	 * @return void
	 */
	public function clear(): void {
		// Outside multisite `delete_site_option()` is `delete_option()`.
		delete_site_option( self::option_name() );
	}
}
