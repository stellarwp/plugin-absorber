<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Unit\Conflict;

use Codeception\TestCase\WPTestCase;
use Generator;
use Nexcess\PluginAbsorber\Conflict\Redirector;

/**
 * @since 1.0.0
 */
class RedirectorTest extends WPTestCase {
	/**
	 * @dataProvider referrers
	 *
	 * @param string|false $referrer Referrer to decide from, as wp_get_referer() would report it.
	 * @param string|false $expected Destination that referrer must produce.
	 */
	public function test_it_decides_where_to_send_the_user( $referrer, $expected ): void {
		$this->assertSame( $expected, ( new Redirector() )->after_deactivation( $referrer ) );
	}

	/**
	 * Absolute admin URLs and bare paths both appear, because wp_get_referer() returns whichever
	 * the request carried: the _wp_http_referer field of an admin form holds a path with no scheme
	 * or host, while the Referer header holds a full URL.
	 *
	 * @return Generator<string,array{0:string|false,1:string|false}>
	 */
	public static function referrers(): Generator {
		yield 'no referrer at all' => [ false, admin_url( 'plugins.php' ) ];
		yield 'an empty referrer'  => [ '', admin_url( 'plugins.php' ) ];

		yield 'a plugin update screen' => [ admin_url( 'update.php?action=upgrade-plugin' ), admin_url( 'plugins.php' ) ];
		yield 'the core update screen' => [ admin_url( 'update-core.php' ), admin_url( 'plugins.php' ) ];

		yield 'the plugins list' => [ admin_url( 'plugins.php' ), false ];

		// On multisite this is /wp-admin/network/plugins.php, which no comparison against
		// admin_url() would recognise.
		yield 'the network plugins list' => [ network_admin_url( 'plugins.php' ), false ];

		yield 'another admin screen' => [
			admin_url( 'options-general.php?settings-updated=true' ),
			admin_url( 'options-general.php?settings-updated=true' ),
		];
		yield 'another network admin screen' => [ network_admin_url( 'sites.php' ), network_admin_url( 'sites.php' ) ];

		// What an admin form POST actually carries. Matching on the screen is what makes these two
		// behave the same as their absolute equivalents.
		yield 'a bare referrer path to the plugins list' => [ '/wp-admin/plugins.php?plugin_status=all', false ];
		yield 'a bare referrer path to another screen'   => [ '/wp-admin/options-general.php', '/wp-admin/options-general.php' ];
	}
}
