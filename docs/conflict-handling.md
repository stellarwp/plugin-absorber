# Conflict handling

## Policies

A policy is only reached for a sub-plugin that is enabled, names a `standalone_plugin_basename`,
whose standalone is active right now, and that the [`should_load` filter](filters.md#the-load-gate)
has not vetoed — a vetoed sub-plugin has no bundled copy to put in the standalone's place.

| Policy | Behavior |
|---|---|
| `Conflict_Policy::DEACTIVATE` | Deactivate the standalone, notify, and usually redirect; the bundled copy loads on the next request. **Default.** |
| `Conflict_Policy::DEFER` | Leave the standalone active; the load guard then stands the bundled copy down. |
| `Conflict_Policy::NOTICE_ONLY` | Leave it active and ask the user to deactivate it. |

A policy decides what happens to the *standalone* only: under `DEFER` the bundled copy stands down
because of [the load guard](#the-load-guard), not because of the policy.

Set one with the `conflict_policy` key — a constant or a `callable( Sub_Plugin ): string` — and the
`conflict_policy` [filter](filters.md) runs last:

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

**An unrecognised policy is treated as `NOTICE_ONLY`**, never as consent to deactivate: a typo like
`'defered'` only produces a notice.

For what the site owner sees under each policy, see
[the recipe](recipes.md#choose-a-policy-and-know-what-the-site-owner-sees).

## When resolution runs

At `plugins_loaded` priority 5, one ahead of the load at priority 6, so the load sees the guard
constant a surviving standalone defines. Priority 5 is also the deadline for `Absorber::boot()`.

Resolution runs **only on an interactive admin `GET`** — not WP-CLI, cron, ajax, or a form POST,
since it can end the request with a redirect that would discard what a POST submitted. Nothing is
lost: the standalone is still there on the next page view.

**A `GET` carrying an action is skipped too.** Anything naming `action` or `action2` waits for the
next plain page view, because a redirect would discard the work behind
`plugins.php?action=activate`. The rule is deliberately blunt, so `post.php?action=edit` waits too.

Resolution also requires the capability deactivation needs: `manage_network_plugins` on multisite,
`activate_plugins` otherwise. An unauthenticated admin GET reaches this code before
`auth_redirect()` has sent it to the login screen, and resolves nothing.

These gates apply whatever the policy is, and the [notice queue](notices.md#who-sees-them) asks for
the same capability.

Deactivation is silent, and covers both scopes on multisite: the standalone's own deactivation hook
must not run this early, where a `flush_rewrite_rules()` in it would 404 every custom permalink. On
multisite it can also decline outright — see [the stranding guard](#the-multisite-stranding-guard).

**A site that never loads wp-admin never resolves.** One administered only over SFTP, Composer, or
WP-CLI keeps the standalone active indefinitely. There is no fatal — [the load
guard](#the-load-guard) stands the bundled copy down on every request — but the switchover waits for
the first request that clears every gate above.

## The redirect

The standalone's code is already in memory when the conflict is resolved, so the redirect is how
the request sheds it. The destination is **the screen being requested**, not the one the user
came from, network and user admin included. `/wp-admin/` and the admin roots mean the dashboard;
`update.php` and `update-core.php` go to `plugins.php` instead, since reloading either would re-run
an update, and so does a URI naming no usable admin screen. There is no redirect loop: the next
request has no active standalone to resolve.

With several sub-plugins in conflict, all are resolved before the one redirect at the end, and the
redirect is skipped once headers have been sent. The [merge notice](notices.md) is queued first
either way.

## The multisite stranding guard

`deactivate_plugins()` runs with no `$network_wide` argument, so a network-active standalone is taken
out of *every* site's plugins — including sites the host plugin never runs on, which would be left
with no copy at all.

When the host names itself with `Config::set_host_plugin_basename( plugin_basename( __FILE__ ) )`,
the `DEACTIVATE` policy detects exactly that case — a network-active standalone whose host is not
network-activated — and declines: the standalone stays active, [the load guard](#the-load-guard)
stands the bundled copy down network-wide as under `DEFER`, and a [stranding notice](notices.md)
explains it, recurring until a network administrator resolves the topology.

Pass `plugin_basename( __FILE__ )`, not `__FILE__`: a basename no installed plugin answers to
reads as a host that is never network-active, so the guard would decline for ever. That one is
reported to the developer through `_doing_it_wrong()`, and the standalone is left where it is.

The guard is **opt-in and single-site-safe**: it never fires without a host basename set, and never
off a network, so every other topology deactivates as it always has.

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

The guard is read on `plugins_loaded` at priority 6, so a standalone defining it from a bootstrap
hooked below that is still seen in time and one hooked past it is not, leaving the bundled copy to
load on top. At 6 itself it comes down to which of the two hooked first. One that never defines the
name is never stood down at all —
[an included recipe](recipes.md#defer-to-a-standalone-that-is-a-new-codebase) is a worked example.

## Reactivating the standalone

The guard cannot help on the request that *activates* the standalone: WordPress includes the plugin
being activated **after** the bundled copy has loaded, so that re-declaration is a real fatal. Core
catches it in its activation sandbox and prints *"Plugin could not be activated because it triggered
a fatal error."*

So the library filters `wp_admin_notice_markup` and swaps that sentence for the sub-plugin's
`conflict_notice_message`, falling back to a generic one naming the slug. That filter is what puts
the WordPress floor at 6.4.

It touches nothing else: the markup comes back untouched unless the screen is `plugins`, or
`plugins-network` in the network admin; the `plugin` query arg names a registered standalone; and
`_error_nonce` verifies.

The replacement runs through `wp_kses_post()`, so a knowledge-base link survives, and a message that
filters down to nothing leaves core's wording standing. To keep core's wording throughout, remove
the filter — see [Extending](extending.md).

## Out of scope

**Version negotiation.** The library never compares versions. Read the version yourself and return
`Conflict_Policy::DEFER` from the config or the `conflict_policy` [filter](filters.md), which has the
final say — [this recipe](recipes.md#defer-to-a-standalone-that-is-a-new-codebase) is an example.

**Renamed standalone directories.** `standalone_plugin_basename` is the path as installed. A site
that renamed the standalone's directory is not detected, and no fallback derives the path from the
load guard: one key is the guard, the other is the path.
