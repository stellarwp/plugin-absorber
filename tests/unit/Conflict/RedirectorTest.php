<?php
/**
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Tests\Unit\Conflict;

use Codeception\TestCase\WPTestCase;
use Generator;
use lucatume\WPBrowser\Traits\UopzFunctions;
use Nexcess\PluginAbsorber\Conflict\Redirector;

/**
 * Where the user lands after a standalone has been deactivated.
 *
 * `is_network_admin()` and `is_user_admin()` are stubbed in every test rather than left to whatever
 * the harness happens to have set, because between them they pick the URL builder every destination
 * goes through, and the suite runs on both singlesite and multisite. The three builder tests stub
 * the builders as well: on singlesite `network_admin_url()`, `user_admin_url()` and `admin_url()`
 * return the same string, so no assertion about a real URL can tell which one produced it.
 *
 * @since 1.0.0
 */
class RedirectorTest extends WPTestCase {
	use UopzFunctions;

	/**
	 * @dataProvider request_uris
	 *
	 * @param string $request_uri Current request URI, as $_SERVER would carry it.
	 * @param string $expected    Destination that request URI must produce.
	 */
	public function test_it_decides_where_to_send_the_user( string $request_uri, string $expected ): void {
		$this->setFunctionReturn( 'is_network_admin', false );
		$this->setFunctionReturn( 'is_user_admin', false );

		$this->assertSame( $expected, ( new Redirector() )->after_deactivation( $request_uri ) );
	}

	/**
	 * The destination comes from the current request, so these are the shapes $_SERVER['REQUEST_URI']
	 * really arrives in: a bare path, a path under a subdirectory install, and -- from a proxy that
	 * rewrites it -- an absolute URL.
	 *
	 * @return Generator<string,array{0:string,1:string}>
	 */
	public static function request_uris(): Generator {
		// What Resolver passes when $_SERVER carries no REQUEST_URI, or carries one it will not
		// vouch for as a string. Everything the documented type forbids is below, in its own test.
		yield 'an empty request uri' => [ '', admin_url( 'plugins.php' ) ];

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
		// fragment of its own comes back encoded. Encoding is the whole defence, which is why the
		// value itself is left alone: what the user searched for is what gets searched again.
		yield 'a query value that could break out' => [ '/wp-admin/edit.php?s=foo%26post_type%3Dpage', admin_url( 'edit.php?s=foo%26post_type%3Dpage' ) ];
		yield 'a query value carrying markup'      => [ '/wp-admin/edit.php?s=%3Cb%3Ehi%3C%2Fb%3E', admin_url( 'edit.php?s=%3Cb%3Ehi%3C%2Fb%3E' ) ];

		// The two shapes sanitize_text_field() destroys, and the reason it is not used here: it runs
		// after wp_parse_str() has url-decoded the value, so it deletes every '%xx' sequence and
		// entity-encodes a bare '<'. A search for '100%ab' would come back as '100' and one for
		// 'a<b' as 'a&lt;b' -- the redirect exists to re-render what the user asked for, and that
		// re-renders something else.
		yield 'a query value holding a percent sequence' => [ '/wp-admin/edit.php?s=100%25ab', admin_url( 'edit.php?s=100%25ab' ) ];
		yield 'a query value holding a less-than'        => [ '/wp-admin/edit.php?s=a%3Cb', admin_url( 'edit.php?s=a%3Cb' ) ];

		// CR, LF and the NUL byte are the exception, because the destination is handed to
		// wp_safe_redirect() and ends up in a Location header.
		yield 'a query value carrying a line break' => [ '/wp-admin/edit.php?s=a%0D%0Ab', admin_url( 'edit.php?s=ab' ) ];
		yield 'a query value carrying a null byte'  => [ '/wp-admin/edit.php?s=a%00b', admin_url( 'edit.php?s=ab' ) ];

		// A query value is not always a scalar: `s[]=` is the shape a list of checked boxes comes
		// back in, and wp_parse_str() hands it over as a nested array. The strip has to walk into
		// one, because a pass that only looked at strings would step over the array whole and put
		// the line break in the Location header inside a parameter of its own.
		yield 'a line break inside an array query value' => [
			'/wp-admin/edit.php?s[]=a%0D%0Ab&s[]=c',
			admin_url( 'edit.php?s%5B0%5D=ab&s%5B1%5D=c' ),
		];

		// An admin root names the dashboard by leaving it out, exactly as core's own /wp-admin/ link
		// does. Sending an admin who asked for the dashboard to the plugins list instead is the
		// failure this whole class is about, in its most common form.
		yield 'the dashboard'                        => [ '/wp-admin/', admin_url( 'index.php' ) ];
		yield 'the dashboard without its slash'      => [ '/wp-admin', admin_url( 'index.php' ) ];
		yield 'the dashboard of a subdirectory site' => [ '/blog/wp-admin/', admin_url( 'index.php' ) ];
		yield 'the dashboard with query args'        => [ '/wp-admin/?welcome=0', admin_url( 'index.php?welcome=0' ) ];
		yield 'the network admin root'               => [ '/wp-admin/network/', admin_url( 'index.php' ) ];
		yield 'the user admin root'                  => [ '/wp-admin/user/', admin_url( 'index.php' ) ];

		// A host's own screens are core's files with a `page` argument on them, which is why the
		// check below is for the file and not for a list of core's screens: a plugin page is a
		// screen the admin serves, and it comes back whole.
		yield 'a plugin page under admin.php'   => [ '/wp-admin/admin.php?page=give-settings', admin_url( 'admin.php?page=give-settings' ) ];
		yield 'a plugin page under a core list' => [ '/wp-admin/edit.php?post_type=give_forms&page=give-reports', admin_url( 'edit.php?post_type=give_forms&page=give-reports' ) ];

		// Nothing that fails to name an admin screen is worth reloading, so it takes the same route
		// as no request uri at all.
		yield 'a front-end permalink'                => [ '/2026/08/hello-world/', admin_url( 'plugins.php' ) ];
		yield 'a directory that is no admin root'    => [ '/wp-content/uploads/2026/', admin_url( 'plugins.php' ) ];
		yield 'a front-end permalink ending in /network/' => [ '/community/network/', admin_url( 'plugins.php' ) ];
		yield 'a traversal attempt'                  => [ '/wp-admin/../../etc/passwd', admin_url( 'plugins.php' ) ];
		yield 'garbage'                              => [ 'not a url at all', admin_url( 'plugins.php' ) ];

		// A well-formed name is not a screen. Every one of these would otherwise be rebuilt as an
		// admin URL for a file that is not in wp-admin, and land the user on the web server's own 404
		// -- on the request that was supposed to put them back where they were.
		yield 'a php file outside the admin'     => [ '/wp-content/plugins/give/give.php', admin_url( 'plugins.php' ) ];
		yield 'the login screen'                 => [ '/wp-login.php', admin_url( 'plugins.php' ) ];
		yield 'the cron endpoint'                => [ '/wp-cron.php', admin_url( 'plugins.php' ) ];
		yield 'an admin path naming no screen'   => [ '/wp-admin/not-a-screen.php', admin_url( 'plugins.php' ) ];
		yield 'an admin screen name with a typo' => [ '/wp-admin/edits.php?post_type=page', admin_url( 'plugins.php' ) ];

		// Asked of the admin this request belongs to, not of the three at once: sites.php is the
		// network admin's screen and there is no wp-admin/sites.php for a single site to go back to.
		yield 'a network screen from the site admin' => [ '/wp-admin/sites.php', admin_url( 'plugins.php' ) ];

		// The host is discarded with everything else in front of the screen name: the destination is
		// assembled from admin_url() and a basename, so a uri naming somewhere else cannot leave the
		// admin it was resolved on.
		yield 'a uri naming another host' => [ '//evil.test/wp-admin/plugins.php', admin_url( 'plugins.php' ) ];
	}

	/**
	 * The screen-name pattern is anchored with `\z` and not with `$`, and the whole of the
	 * difference is a trailing newline: in PCRE `$` also matches immediately before one, so
	 * "edit.php\n" would satisfy it and a line break would leave this class inside the one value it
	 * promises is validated — on its way into a Location header.
	 *
	 * No case in the provider above can pin that, and not for want of trying: PHP's own parse_url()
	 * rewrites every non-printable byte of a path to an underscore, so a request URI carrying a real
	 * line break reaches the pattern as "edit.php_" and is refused for a different reason entirely.
	 * That rewrite is an implementation detail of the parser rather than a promise this class is
	 * entitled to lean on, and the anchor is what holds if it ever changes — so the parse is stubbed
	 * to hand the path over verbatim, which is the only way to put the question to the pattern.
	 *
	 * `is_file()` is stubbed for the same reason the parse is. There is no wp-admin file named
	 * "edit.php\n" either, so the screen check would refuse this one on its own and the anchor could
	 * be taken out without a test noticing. Answering every file question yes leaves the pattern as
	 * the only thing that can refuse, which is what this test is asking about.
	 *
	 * The clean path is asserted first, under the same stubs. Without it the fallback below would be
	 * satisfied just as well by a stub that broke every destination, which is the wrong reason to
	 * pass.
	 */
	public function test_it_refuses_a_screen_name_with_a_line_break_after_it(): void {
		$this->setFunctionReturn( 'is_network_admin', false );
		$this->setFunctionReturn( 'is_user_admin', false );

		$this->setFunctionReturn(
			'wp_parse_url',
			static function ( $url, $component = -1 ) {
				return $component === PHP_URL_QUERY ? null : $url;
			},
			true
		);

		$redirector        = new Redirector();
		$expected_screen   = admin_url( 'edit.php' );
		$expected_fallback = admin_url( 'plugins.php' );

		// Everything else this test needs is built before is_file() stops telling the truth, and the
		// stub is undone the moment the calls under test return: it is process-global -- the
		// autoloader asks it too -- so the window it is wrong in has to be those two calls and
		// nothing else. The trait's `@after` is the backstop.
		$restore = $this->setFunctionReturn( 'is_file', true );

		try {
			$screen   = $redirector->after_deactivation( '/wp-admin/edit.php' );
			$fallback = $redirector->after_deactivation( "/wp-admin/edit.php\n" );
		} finally {
			$restore();
		}

		$this->assertSame(
			$expected_screen,
			$screen,
			'A path naming a screen still resolves to it, so neither stub is what refuses below.'
		);

		$this->assertSame( $expected_fallback, $fallback );
	}

	/**
	 * The parameter is documented `string` and declared as nothing, so every one of these is a
	 * static error at the call site and none of them is a fatal at runtime. That is the whole of the
	 * arrangement: $_SERVER carries whatever the SAPI put there and any plugin may have filtered it
	 * on the way, so a declared type would answer a host's broken $_SERVER with a TypeError raised
	 * from inside plugins_loaded -- on the request that was supposed to hand the admin a working
	 * screen back. The type tells callers what to pass; the guard survives them not doing it.
	 *
	 * @dataProvider request_uris_the_type_forbids
	 *
	 * @param mixed $request_uri A value the documented type does not admit.
	 */
	public function test_it_falls_back_when_the_request_uri_is_not_a_string( $request_uri ): void {
		$this->setFunctionReturn( 'is_network_admin', false );
		$this->setFunctionReturn( 'is_user_admin', false );

		/** @phpstan-ignore-next-line argument.type (the point of the test is the value the type forbids). */
		$destination = ( new Redirector() )->after_deactivation( $request_uri );

		$this->assertSame( admin_url( 'plugins.php' ), $destination );
	}

	/**
	 * `null` leads, because it is the shape a reader expects to be allowed and is not: Resolver
	 * turns a missing REQUEST_URI into an empty string before it calls, so nothing in this library
	 * ever passes null, and the documented type says exactly that.
	 *
	 * @return Generator<string,array{0:mixed}>
	 */
	public static function request_uris_the_type_forbids(): Generator {
		yield 'null'       => [ null ];
		yield 'an array'   => [ [ '/wp-admin/edit.php' ] ];
		yield 'an integer' => [ 0 ];
		yield 'a boolean'  => [ false ];
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
	 * The network admin serves only the files core gives it: there is no
	 * wp-admin/network/options-general.php, so no super admin was ever on that screen and there is
	 * nothing there to send one back to. The site admin's copy of the same name is not the answer
	 * either -- the destination is built with network_admin_url(), which would name the file that is
	 * missing. Asking the current admin's directory is what makes those two the same question.
	 */
	public function test_it_refuses_a_screen_the_network_admin_does_not_serve(): void {
		$this->setFunctionReturn( 'is_network_admin', true );

		$redirector = new Redirector();

		$this->assertSame(
			network_admin_url( 'sites.php' ),
			$redirector->after_deactivation( '/wp-admin/network/sites.php' ),
			'A screen the network admin does have still resolves to itself, so the refusal below is not blanket.'
		);

		$this->assertSame(
			network_admin_url( 'plugins.php' ),
			$redirector->after_deactivation( '/wp-admin/network/options-general.php' )
		);
	}

	/**
	 * The third admin holds fewer screens still, and `edit.php` is one it does not have.
	 */
	public function test_it_refuses_a_screen_the_user_admin_does_not_serve(): void {
		$this->setFunctionReturn( 'is_network_admin', false );
		$this->setFunctionReturn( 'is_user_admin', true );

		$redirector = new Redirector();

		$this->assertSame(
			user_admin_url( 'profile.php' ),
			$redirector->after_deactivation( '/wp-admin/user/profile.php' ),
			'A screen the user admin does have still resolves to itself, so the refusal below is not blanket.'
		);

		$this->assertSame(
			user_admin_url( 'plugins.php' ),
			$redirector->after_deactivation( '/wp-admin/user/edit.php' )
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
		$this->setFunctionReturn( 'is_user_admin', false );
		$this->stub_admin_url_builders();

		$redirector = new Redirector();

		$this->assertSame( 'site-admin/edit.php', $redirector->after_deactivation( '/wp-admin/edit.php' ) );
		$this->assertSame( 'site-admin/plugins.php', $redirector->after_deactivation( '/2026/08/hello-world/' ) );
	}

	public function test_it_keeps_a_user_admin_request_in_the_user_admin(): void {
		$this->setFunctionReturn( 'is_network_admin', false );
		$this->setFunctionReturn( 'is_user_admin', true );

		$this->assertSame(
			user_admin_url( 'profile.php' ),
			( new Redirector() )->after_deactivation( '/wp-admin/user/profile.php' )
		);
	}

	public function test_it_sends_the_user_admin_root_to_the_user_dashboard(): void {
		$this->setFunctionReturn( 'is_network_admin', false );
		$this->setFunctionReturn( 'is_user_admin', true );

		$this->assertSame(
			user_admin_url( 'index.php' ),
			( new Redirector() )->after_deactivation( '/wp-admin/user/' )
		);
	}

	/**
	 * The user admin is the third admin, and it is reachable: /wp-admin/user/ defines WP_ADMIN, so
	 * is_admin() is true and the request gate lets it through. Sent to admin_url() instead, someone
	 * editing their profile across a network lands on a site they may not even be a member of.
	 */
	public function test_it_builds_every_user_destination_with_user_admin_url(): void {
		$this->setFunctionReturn( 'is_network_admin', false );
		$this->setFunctionReturn( 'is_user_admin', true );
		$this->stub_admin_url_builders();

		$redirector = new Redirector();

		$this->assertSame( 'user-admin/profile.php', $redirector->after_deactivation( '/wp-admin/user/profile.php' ) );
		$this->assertSame( 'user-admin/plugins.php', $redirector->after_deactivation( '/2026/08/hello-world/' ) );
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
			'user_admin_url',
			static function ( $path = '' ) {
				return 'user-admin/' . $path;
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
