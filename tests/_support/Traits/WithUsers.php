<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Support\Traits;

use RuntimeException;
use WP_Error;

/**
 * Creating the users whose capabilities the library's gates turn on.
 *
 * @since 1.0.0
 */
trait WithUsers {
	/**
	 * @since 1.0.0
	 *
	 * @param string $role Role to create the user with.
	 *
	 * @throws RuntimeException When the user cannot be created.
	 *
	 * @return int
	 */
	protected function create_user( string $role ): int {
		$user_id = wp_insert_user(
			[
				'user_login' => uniqid( 'absorber-' ),
				'user_pass'  => wp_generate_password(),
				'role'       => $role,
			]
		);

		if ( $user_id instanceof WP_Error ) {
			throw new RuntimeException( 'Could not create a ' . $role . ': ' . $user_id->get_error_message() );
		}

		return $user_id;
	}

	/**
	 * Become someone who can activate plugins.
	 *
	 * On multisite activate_plugins maps through manage_network_plugins, so an administrator of a
	 * single site does not have it and the network administrator is the one who does.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	protected function become_plugin_administrator(): int {
		$user_id = $this->create_user( 'administrator' );

		if ( is_multisite() ) {
			grant_super_admin( $user_id );
		}

		wp_set_current_user( $user_id );

		return $user_id;
	}
}
