<?php
/**
 * @package Nexcess\PluginAbsorber
 */

namespace Nexcess\PluginAbsorber\Tests\Unit\Conflict;

use Codeception\TestCase\WPTestCase;
use Generator;
use lucatume\WPBrowser\Traits\UopzFunctions;
use Nexcess\PluginAbsorber\Conflict\Redirector;

/**
 * Where the user lands after a standalone has been deactivated.
 *
 * `is_network_admin()` is stubbed in every test rather than left to whatever the harness happens to
 * have set, because its answer picks the URL builder every destination goes through, and the suite
 * runs on both singlesite and multisite. The two builder tests stub the builders as well: on
 * singlesite `network_admin_url()` and `admin_url()` return the same string, so no assertion about
 * a real URL can tell which one produced it.
 *
 * @since 1.0.0
 */
class RedirectorTest extends WPTestCase {
	use UopzFunctions;

	/**
	 * @dataProvider request_uris
	 *
	 * @param string|null $request_uri Current request URI, as $_SERVER would carry it.
	 * @param string      $expected    Destination that request URI must produce.
	 */
	public function test_it_decides_where_to_send_the_user( $request_uri, string $expected ): void {
		$this->setFunctionReturn( 'is_network_admin', false );

		$this->assertSame( $expected, ( new Redirector() )->after_deactivation( $request_uri ) );
	}

	/**
	 * The destination comes from the current request, so these are the shapes $_SERVER['REQUEST_URI']
	 * really arrives in: a bare path, a path under a subdirectory install, and -- from a proxy that
	 * rewrites it -- an absolute URL.
	 *
	 * @return Generator<string,array{0:string|null,1:string}>
	 */
	public static function request_uris(): Generator {
		yield 'no request uri at all' => [ null, admin_url( 'plugins.php' ) ];
		yield 'an empty request uri'  => [ '', admin_url( 'plugins.php' ) ];

		// Reloading either re-runs the update it is in the middle of.
		yield 'a plugin update screen' => [ '/wp-admin/update.php?action=upgrade-plugin&plugin=give', admin_url( 'plugins.php' ) ];
		yield 'the core update screen' => [ '/wp-admin/update-core.php', admin_url( 'plugins.php' ) ];

		// No "stay put" case: the standalone's code is in memory for this request either way, and
		// only a fresh request sheds it.
		yield 'the plugins list'             => [ '/wp-admin/plugins.php', admin_url( 'plugins.php' ) ];
		yield 'the plugins list, filtered'   => [ '/wp-admin/plugins.php?plugin_status=active', admin_url( 'plugins.php?plugin_status=active' ) ];

		// The screen an admin reaches from a bookmark or the toolbar, which sends no referrer at all.
		yield 'another admin screen'      => [ '/wp-admin/edit.php', admin_url( 'edit.php' ) ];
		yield 'a screen with query args'  => [ '/wp-admin/edit.php?post_type=page&paged=2', admin_url( 'edit.php?post_type=page&paged=2' ) ];
		yield 'a subdirectory install'    => [ '/blog/wp-admin/options-general.php', admin_url( 'options-general.php' ) ];
		yield 'an absolute url'           => [ 'https://example.test/wp-admin/edit.php?post_type=page', admin_url( 'edit.php?post_type=page' ) ];

		// Rebuilt rather than carried over, so a value that would otherwise start a parameter or a
		// fragment of its own comes back encoded, and markup does not come back at all.
		yield 'a query value that could break out' => [ '/wp-admin/edit.php?s=foo%26post_type%3Dpage', admin_url( 'edit.php?s=foo%26post_type%3Dpage' ) ];
		yield 'a query value carrying markup'      => [ '/wp-admin/edit.php?s=%3Cb%3Ehi%3C%2Fb%3E', admin_url( 'edit.php?s=hi' ) ];

		// An admin root names the dashboard by leaving it out, exactly as core's own /wp-admin/ link
		// does. Sending an admin who asked for the dashboard to the plugins list instead is the
		// failure this whole class is about, in its most common form.
		yield 'the dashboard'                        => [ '/wp-admin/', admin_url( 'index.php' ) ];
		yield 'the dashboard without its slash'      => [ '/wp-admin', admin_url( 'index.php' ) ];
		yield 'the dashboard of a subdirectory site' => [ '/blog/wp-admin/', admin_url( 'index.php' ) ];
		yield 'the dashboard with query args'        => [ '/wp-admin/?welcome=0', admin_url( 'index.php?welcome=0' ) ];
		yield 'the network admin root'               => [ '/wp-admin/network/', admin_url( 'index.php' ) ];
		yield 'the user admin root'                  => [ '/wp-admin/user/', admin_url( 'index.php' ) ];

		// Nothing that fails to name an admin screen is worth reloading, so it takes the same route
		// as no request uri at all.
		yield 'a front-end permalink'                => [ '/2026/08/hello-world/', admin_url( 'plugins.php' ) ];
		yield 'a directory that is no admin root'    => [ '/wp-content/uploads/2026/', admin_url( 'plugins.php' ) ];
		yield 'a front-end permalink ending in /network/' => [ '/community/network/', admin_url( 'plugins.php' ) ];
		yield 'a traversal attempt'                  => [ '/wp-admin/../../etc/passwd', admin_url( 'plugins.php' ) ];
		yield 'garbage'                              => [ 'not a url at all', admin_url( 'plugins.php' ) ];

		// The host is discarded with everything else in front of the screen name: the destination is
		// assembled from admin_url() and a basename, so a uri naming somewhere else cannot leave the
		// admin it was resolved on.
		yield 'a uri naming another host' => [ '//evil.test/wp-admin/plugins.php', admin_url( 'plugins.php' ) ];
	}

	/**
	 * $_SERVER carries whatever the SAPI put there, and a host may filter it besides, so the guard
	 * is a runtime one rather than a promise the signature can keep.
	 */
	public function test_it_falls_back_when_the_request_uri_is_not_a_string(): void {
		$this->setFunctionReturn( 'is_network_admin', false );

		/** @phpstan-ignore-next-line argument.type (the point of the test is the value the type forbids). */
		$destination = ( new Redirector() )->after_deactivation( [ '/wp-admin/edit.php' ] );

		$this->assertSame( admin_url( 'plugins.php' ), $destination );
	}

	public function test_it_keeps_a_network_admin_request_in_the_network_admin(): void {
		$this->setFunctionReturn( 'is_network_admin', true );

		$this->assertSame(
			network_admin_url( 'sites.php?paged=3' ),
			( new Redirector() )->after_deactivation( '/wp-admin/network/sites.php?paged=3' )
		);
	}

	public function test_it_sends_the_network_admin_root_to_the_network_dashboard(): void {
		$this->setFunctionReturn( 'is_network_admin', true );

		$this->assertSame(
			network_admin_url( 'index.php' ),
			( new Redirector() )->after_deactivation( '/wp-admin/network/' )
		);
	}

	public function test_it_falls_back_to_the_network_plugins_list(): void {
		$this->setFunctionReturn( 'is_network_admin', true );

		$this->assertSame(
			network_admin_url( 'plugins.php' ),
			( new Redirector() )->after_deactivation( '/wp-admin/network/update-core.php' )
		);
	}

	/**
	 * A super admin who resolves a conflict from the network admin has to come back to it. Thrown
	 * onto the current blog's screens instead, they are looking at a list where a network-activated
	 * standalone is not manageable at all.
	 */
	public function test_it_builds_every_network_destination_with_network_admin_url(): void {
		$this->setFunctionReturn( 'is_network_admin', true );
		$this->stub_admin_url_builders();

		$redirector = new Redirector();

		$this->assertSame( 'network-admin/edit.php', $redirector->after_deactivation( '/wp-admin/network/edit.php' ) );
		$this->assertSame( 'network-admin/plugins.php', $redirector->after_deactivation( '/2026/08/hello-world/' ) );
	}

	public function test_it_builds_every_site_destination_with_admin_url(): void {
		$this->setFunctionReturn( 'is_network_admin', false );
		$this->stub_admin_url_builders();

		$redirector = new Redirector();

		$this->assertSame( 'site-admin/edit.php', $redirector->after_deactivation( '/wp-admin/edit.php' ) );
		$this->assertSame( 'site-admin/plugins.php', $redirector->after_deactivation( '/2026/08/hello-world/' ) );
	}

	/**
	 * Marks each builder's output so the branch is assertable in an environment where the two agree.
	 * On singlesite network_admin_url() is admin_url(), which would let the wrong branch pass.
	 */
	private function stub_admin_url_builders(): void {
		$this->setFunctionReturn(
			'network_admin_url',
			static function ( $path = '' ) {
				return 'network-admin/' . $path;
			},
			true
		);

		$this->setFunctionReturn(
			'admin_url',
			static function ( $path = '' ) {
				return 'site-admin/' . $path;
			},
			true
		);
	}
}
