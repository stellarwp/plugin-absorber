# Conflict handling

## Policies

When a sub-plugin's standalone counterpart is still active:

| Policy | Behavior |
|---|---|
| `Conflict_Policy::DEACTIVATE` | Deactivate the standalone, notify, and usually redirect; the bundled copy loads on the next request. **Default.** |
| `Conflict_Policy::DEFER` | Leave the standalone active; the load guard stands the bundled copy down. |
| `Conflict_Policy::NOTICE_ONLY` | Leave it active and ask the user to deactivate it. |

Set one per sub-plugin with the `conflict_policy` key — a constant, or a `callable( Sub_Plugin ):
string`. The `conflict_policy` [filter](filters.md) runs after that and has the final say:

```php
// In the config: stand down when a newer standalone supersedes the bundled copy.
'conflict_policy' => static fn( Sub_Plugin $sub ) => give_standalone_is_newer( $sub )
    ? Conflict_Policy::DEFER
    : Conflict_Policy::DEACTIVATE,

// Anywhere, and last:
add_filter( 'give/plugin_absorber/conflict_policy', static function ( $policy, $sub ) {
    return $sub->get_slug() === 'give-recurring' ? Conflict_Policy::NOTICE_ONLY : $policy;
}, 10, 2 );
```

**An unrecognised policy is treated as `NOTICE_ONLY`**, never as consent to deactivate.
`Conflict_Policy::is_valid()` decides, so a typo like `'defered'` — in a policy a host persisted in
an option, or in whatever that filter returned — only produces a notice. A value nobody chose must
not turn off a plugin somebody chose.

A policy is only reached for a sub-plugin that is enabled, names a `standalone_plugin_basename`, and
whose standalone is active right now; everything else is skipped before any policy is read.

## When resolution runs

At `plugins_loaded` priority 5, one ahead of the load pass at 6: a standalone that survives the
conflict defines the guard constant as it loads, and the load pass has to see that. Priority 5 is
also the deadline for `Absorber::boot()`, since this is the first step it has to wire.

It runs **only on an interactive admin `GET`** — not WP-CLI, not cron, not ajax, not a form POST —
because resolving can deactivate a plugin and end the request with a redirect. Ungated, a visitor's
checkout POST would come back as a 302 that discards what was submitted and drops the order, and a
WP-CLI command would exit having printed nothing, because `header()` is a no-op under the CLI SAPI.
Waiting costs nothing: the standalone is still there to detect on the next page view.

**A `GET` that carries an action is skipped too.** `update.php?action=upgrade-plugin`,
`plugins.php?action=activate` and the `admin-post.php` links are all admin `GET`s that *do* something,
and a redirect discards their work exactly as it would a POST's — the user clicks Update and lands on
a list screen with nothing updated. Anything naming an `action` or `action2`, and the endpoints that
exist only to perform work, wait for the next plain page view. This is deliberately blunt: a
read-only `post.php?action=edit` waits as well.

It also requires the capability that matches what deactivation actually does. Deactivating a
standalone is network-wide wherever a network exists, so the check is `manage_network_plugins` on
multisite and `activate_plugins` otherwise — `activate_plugins` alone does not imply authority over
every site on a network. The gate matters at all because `plugins_loaded` fires well before
`auth_redirect()`, so an unauthenticated GET of an admin URL reaches this code on its way to the
login screen. It applies to every policy rather than only to `deactivate`, which costs nothing — the
other policies just queue a notice, and a notice is neither shown nor cleared for a user without the
same capability.

Both gates live in `Conflict\Gatekeeper`, along with a third that catches a host which reached
`plugins_loaded` without ever calling `Config::set_hook_prefix()` — that is reported through
`_doing_it_wrong()` and resolution stands down rather than throwing out of a core action. The hook
asks the gatekeeper *before* it resolves `Conflict\Contracts\Resolver_Interface` at all, so binding
your own resolver cannot drop any of them by omission: on a request that fails one, your
implementation is never built, let alone called. The capability is asked last, after
`Conflict\Detector::has_conflict()` has reported there is something to resolve — `current_user_can()`
resolves and caches the current user, and at priority 5 that would land ahead of any
`determine_current_user` filter an SSO or JWT plugin adds from its own `plugins_loaded` callback,
whose users would then be treated as signed out for the rest of the request.

The deactivation itself is silent, and covers both scopes on multisite. Silent because the
standalone's own deactivation hook has already been registered by the time we run: a routine
`flush_rewrite_rules()` in that callback, at `plugins_loaded`, regenerates the rules before `init`
has declared a single post type, and every custom permalink on the site starts 404ing.

## The redirect

The standalone's code is already in memory by the time the conflict is resolved — WordPress included
it before `plugins_loaded` — so the redirect is how the request sheds it. The destination is **the
screen being requested**, not the one the user came from: it re-renders without the standalone, and
the admin stays where they asked to be. `/wp-admin/` and the network and user admin roots mean the
dashboard. The update screens (`update.php`, `update-core.php`) go to `plugins.php` instead, because
reloading one of those would re-run an update, and anything that names no usable admin screen falls
back to `plugins.php`.

The destination is assembled from the screen name and query string through `admin_url()` — or
`network_admin_url()` and `user_admin_url()` in the network and user admins, so a request resolved
in one of those comes back to it — never from the request URI itself, so nothing in the URI decides
the host. There is no redirect loop: the next request has no active standalone, so nothing
resolves.

With several sub-plugins in conflict, all of them are resolved before the one redirect at the end,
and the redirect is skipped entirely once headers have been sent — which is what a host booting too
late produces, since the `_doing_it_wrong()` notice is output. The request then finishes rendering
instead of dying blank.

`Conflict\Redirector` makes that decision and returns it; the redirect itself is the resolver's. The
merge notice is queued before either, so the explanation survives whether or not the request ends in
a redirect.

## The load guard

Before loading a bundled plugin, the library checks whether `plugin_loaded_constant` is already
defined. `defined()` ⇒ skip, which is what prevents the re-declaration fatal.

**The constant must be defined at file scope**, inside a `defined()` check so whichever copy loads
first wins:

```php
if ( ! defined( 'GIVE_RECURRING_VERSION' ) ) {
    define( 'GIVE_RECURRING_VERSION', '2.4.0' );
}
```

A standalone that defines it from a bootstrap hooked at `plugins_loaded` or later has not defined it
yet at the moment the guard is read, and the bundled copy would load on top of it.

## What is deliberately out of scope

**Version negotiation.** The library never compares versions, so it will not spare a standalone that
is newer than the bundled copy. Express that yourself: check the version and return
`Conflict_Policy::DEFER` from the `conflict_policy` [filter](filters.md), which has the final say —
[the recipe](recipes.md#defer-to-a-newer-standalone) is ten lines.

**Renamed standalone directories.** `standalone_plugin_basename` is the path as installed. A site
that renamed the standalone's directory is not detected, and there is no fallback that derives the
path from the load guard: one key is the guard and the other is the path, and no constant does both
jobs. The cost is a missed detection; the alternative costs the guarantee the guard exists for.

## What the guard cannot do

The guard cannot help on the request that *activates* the standalone: WordPress includes the plugin
being activated **after** the bundled copy has already loaded, so that re-declaration is a real
fatal. Core catches it in its activation sandbox and prints *"Plugin could not be activated because
it triggered a fatal error."* — true, and useless to whoever pressed the button.

So the library filters `wp_admin_notice_markup` and swaps that sentence for the sub-plugin's
`conflict_notice_message`, falling back to a generic one naming the slug. This is what puts the
WordPress floor at 6.4: the filter does not exist before it.

It touches nothing else. The markup comes back untouched unless every one of these holds — the
screen is `plugins`, or `plugins-network` in the network admin, where a super admin is the only one
who can reactivate anything; the `plugin` query arg names a standalone this library has registered;
and `_error_nonce` verifies against `plugin-activation-error_{basename}`. Another plugin's fatal is
another plugin's business. (One exception, and it is not about this screen: a filter ahead of ours
that returned something other than a string is normalised to `''`, because a `string` type
declaration here would turn that plugin's mistake into a `TypeError` raised on the error screen
least able to afford a second one.)

The replacement runs through `wp_kses_post()`, so a knowledge-base link survives, and it is
sanitised *before* it is checked for emptiness: a message that filters down to nothing leaves core's
wording in place rather than blanking the notice.

The filter is wired by `Boot\Scheduler` under `is_admin()`, as
`[ Absorber::class, 'filter_activation_error_markup' ]` — a named callback, so a host that would
rather keep core's wording can `remove_filter()` it. The rewriting itself is
`Conflict\Rewriter::rewrite()`, bound by class name like the rest of the conflict handling, so a host
can rebind this screen on its own — after `boot()`, as every class-name binding must be, and without
having to supply a notice writer to get it.
