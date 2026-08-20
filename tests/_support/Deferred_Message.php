<?php
/**
 * Stands in for a host class that produces a message when asked rather than at registration.
 *
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Tests\Support;

use Nexcess\PluginAbsorber\Sub_Plugin;

/**
 * Each method names the callable form it stands for, so a failed assertion says which form broke.
 *
 * @since 1.0.0
 */
class Deferred_Message {
	/**
	 * @since 1.0.0
	 *
	 * @param Sub_Plugin $sub_plugin The sub-plugin the message is about.
	 *
	 * @return string
	 */
	public static function from_a_static_method( Sub_Plugin $sub_plugin ): string {
		return "static: {$sub_plugin->get_slug()}";
	}

	/**
	 * @since 1.0.0
	 *
	 * @param Sub_Plugin $sub_plugin The sub-plugin the message is about.
	 *
	 * @return string
	 */
	public function from_an_instance_method( Sub_Plugin $sub_plugin ): string {
		return "instance: {$sub_plugin->get_slug()}";
	}

	/**
	 * @since 1.0.0
	 *
	 * @param Sub_Plugin $sub_plugin The sub-plugin the message is about.
	 *
	 * @return string
	 */
	public function __invoke( Sub_Plugin $sub_plugin ): string {
		return "invoked: {$sub_plugin->get_slug()}";
	}
}
