# Conflict handling

## Policies

A policy is only reached for a sub-plugin that is enabled, names a `standalone_plugin_basename`, and
whose standalone is active right now. Everything else is skipped before any policy is read.

| Policy | Behavior |
|---|---|
| `Conflict_Policy::DEACTIVATE` | Deactivate the standalone, notify, and usually redirect; the bundled copy loads on the next request. **Default.** |
| `Conflict_Policy::DEFER` | Leave the standalone active; the load guard then stands the bundled copy down. |
| `Conflict_Policy::NOTICE_ONLY` | Leave it active and ask the user to deactivate it. |

A policy decides what happens to the *standalone*, and nothing else. Under `DEFER` the bundled copy
stands down because of [the load guard](#the-load-guard), not because of the policy.

Set one per sub-plugin with the `conflict_policy` key — a constant, or a `callable( Sub_Plugin ):
string`. The `conflict_policy` [filter](filters.md) runs after that and has the final say:

```php
// In the config: leave the standalone alone when it is not the code that was absorbed.
'conflict_policy' => static fn( Sub_Plugin $sub ) => give_standalone_is_a_new_codebase( $sub )
    ? Conflict_Policy::DEFER
    : Conflict_Policy::DEACTIVATE,

// Anywhere, and last:
add_filter( 'give/plugin_absorber/conflict_policy', static function ( $policy, $sub ) {
    return $sub->get_slug() === 'give-recurring' ? Conflict_Policy::NOTICE_ONLY : $policy;
}, 10, 2 );
```

**An unrecognised policy is treated as `NOTICE_ONLY`**, never as consent to deactivate — a typo
like `'defered'`, from a persisted option or from the filter, only produces a notice.

For what the site owner sees under each policy, see
[the recipe](recipes.md#choose-a-policy-and-know-what-the-site-owner-sees).

## When resolution runs

At `plugins_loaded` priority 5, one ahead of the load at priority 6: a standalone that survives the
conflict defines the guard constant as it loads, and the load has to see that. Priority 5 is also
the deadline for `Absorber::boot()`, since this is the first step it wires.

Resolution runs **only on an interactive admin `GET`** — not WP-CLI, cron, ajax, or a form
POST — because resolving can deactivate a plugin and end the request with a redirect, and a 302
would discard whatever a POST submitted. Waiting costs nothing: the standalone is still there to
detect on the next page view.

**A `GET` carrying an action is skipped too.** `update.php?action=upgrade-plugin` and
`plugins.php?action=activate` are admin `GET`s that *do* something, and a redirect discards their
work exactly as it would a POST's. Anything naming `action` or `action2` waits for the next plain
page view — deliberately blunt, so a read-only `post.php?action=edit` waits as well.

Resolution also requires the capability matching what deactivation does: `manage_network_plugins` on
multisite and `activate_plugins` otherwise, since deactivating a standalone is network-wide wherever
a network exists. The check matters because `plugins_loaded` fires well before `auth_redirect()`, so
an unauthenticated GET of an admin URL reaches this code on its way to the login screen.

These gates apply whatever the policy is — the non-destructive policies only queue a notice, and a
notice is neither shown nor cleared for a user without the same capability.

The deactivation itself is silent, and covers both scopes on multisite. Silent because the
standalone's own deactivation hook would otherwise run this early: a routine `flush_rewrite_rules()`
in it would regenerate the rules before `init` declared a single post type, and every custom
permalink on the site would start 404ing.

**A site that never loads wp-admin never resolves.** Every gate above needs an interactive admin
page view. Any website administered entirely over SFTP, Composer, or WP-CLI — one whose owner never
opens an admin screen in a browser — keeps the standalone active for as long as that holds. There
is still no fatal, because [the load guard](#the-load-guard) runs on every request and stands the
bundled copy down regardless; what waits is the *switchover*. The standalone, frozen at the version
installed, goes on serving in place of the bundled copy the host ships updates for until the first
interactive admin `GET` arrives. That is the price of never ending a non-admin request with a
redirect — one that would drop a visitor's POST or cut a WP-CLI run short — and on such a site the
switchover is simply deferred until then.

## The redirect

The standalone's code is already in memory by the time the conflict is resolved — WordPress
included it before `plugins_loaded` — so the redirect is how the request sheds it. The destination
is **the screen being requested**, not the one the user came from: it re-renders without the
standalone, and the admin stays where they asked to be, network or user admin included. `/wp-admin/`
and the admin roots mean the dashboard; `update.php` and `update-core.php` go to `plugins.php`
instead, because reloading either would re-run an update, and so does anything naming no usable
admin screen. There is no redirect loop: the next request has no active standalone, so nothing
resolves.

With several sub-plugins in conflict, all of them are resolved before the one redirect at the end,
and the redirect is skipped entirely once headers have been sent — the request then finishes
rendering instead of dying blank. The [merge notice](notices.md) is queued first either way, so the
explanation survives whether or not the request ends in a redirect.

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
yet at the moment the guard is read, and the bundled copy would load on top of it. A standalone that
never defines the name at all is never stood down either, and the bundled copy loads alongside it;
[an included recipe](recipes.md#defer-to-a-standalone-that-is-a-new-codebase) is a functional
example of this behavior.

## Reactivating the standalone

The guard cannot help on the request that *activates* the standalone: WordPress includes the plugin
being activated **after** the bundled copy has already loaded, so that re-declaration is a real
fatal. Core catches it in its activation sandbox and prints *"Plugin could not be activated because
it triggered a fatal error."* — true, and useless to whoever pressed the button.

So the library filters `wp_admin_notice_markup` and swaps that sentence for the sub-plugin's
`conflict_notice_message`, falling back to a generic one naming the slug. This is what puts the
WordPress floor at 6.4: the filter does not exist before it.

It touches nothing else. The markup comes back untouched unless every one of these holds — the
screen is `plugins`, or `plugins-network` in the network admin; the `plugin` query arg names a
standalone this library has registered; and `_error_nonce` verifies.

The replacement runs through `wp_kses_post()`, so a knowledge-base link survives, and a message that
filters down to nothing leaves core's wording in place rather than blanking the notice. A host that
would rather keep core's wording throughout can remove the filter — see [Extending](extending.md).

## Out of scope

**Version negotiation.** The library never compares versions. Express it yourself: read the version
and return `Conflict_Policy::DEFER` from the config or the `conflict_policy` [filter](filters.md),
which has the final say — [this recipe](recipes.md#defer-to-a-standalone-that-is-a-new-codebase) is
an example.

**Renamed standalone directories.** `standalone_plugin_basename` is the path as installed. A site
that renamed the standalone's directory is not detected, and there is no fallback deriving the path
from the load guard: one key is the guard, the other is the path, and no constant does both jobs.
