# Conflict handling

## Policies

When a sub-plugin's standalone counterpart is still active:

| Policy | Behavior |
|---|---|
| `Conflict_Policy::DEACTIVATE` | Deactivate the standalone, notify, and redirect; the bundled copy loads on the next request. **Default.** |
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

At `plugins_loaded` priority 1, one ahead of the load pass at 2: a standalone that survives the
conflict defines the guard constant as it loads, and the load pass has to see that.

It runs **only on an interactive admin `GET`** — not WP-CLI, not cron, not ajax, not a form POST —
because resolving can deactivate a plugin and end the request with a redirect. Ungated, a visitor's
checkout POST would come back as a 302 that discards what was submitted and drops the order, and a
WP-CLI command would exit having printed nothing, because `header()` is a no-op under the CLI SAPI.
Waiting costs nothing: the standalone is still there to detect on the next page view.

It also requires `current_user_can( 'activate_plugins' )`: `plugins_loaded` fires well before
`auth_redirect()`, so an unauthenticated GET of an admin URL reaches this code on its way to the
login screen, and whoever cannot activate a plugin must not be able to deactivate one. This applies
to every policy rather than only to `deactivate`, which costs nothing — the other policies just
queue a notice, and a notice is neither shown nor cleared for a user without that same capability,
so nothing is consumed by waiting for one who has it.

Both gates live in `Conflict\Gatekeeper`, and the hook asks it *before* it resolves
`Conflict\Contracts\Resolver_Interface` at all. So binding your own resolver cannot drop them by
omission: on a request that fails either gate your implementation is never built, let alone called.

## The redirect

After deactivating, the user goes back to whatever they were looking at, so it re-renders without the
standalone. Two referrers differ: `plugins.php` stays put, since the list is about to show the change
anyway, and the update screens (`update.php`, `update-core.php`) go to `plugins.php` instead, because
reloading one of those would re-run an update. With no referrer at all, `plugins.php`.

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
`Conflict_Policy::DEFER` from the `conflict_policy` [filter](filters.md), which has the final say.

**Renamed standalone directories.** `standalone_plugin_basename` is the path as installed. A site
that renamed the standalone's directory is not detected, and there is no fallback that derives the
path from the load guard: one key is the guard and the other is the path, and no constant does both
jobs. The cost is a missed detection; the alternative costs the guarantee the guard exists for.

## What the guard cannot do

The guard cannot help on the request that *activates* the standalone: WordPress includes it after
the bundled copy has already loaded, so that re-declaration is a real fatal. WordPress catches it
in its activation sandbox, and this library rewrites the resulting error screen into an
explanation.
