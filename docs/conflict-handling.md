# Conflict handling

## Policies

When a sub-plugin's standalone counterpart is still active:

| Policy | Behavior |
|---|---|
| `Conflict_Policy::DEACTIVATE` | Deactivate the standalone, notify, and redirect; the bundled copy loads on the next request. **Default.** |
| `Conflict_Policy::DEFER` | Leave the standalone active; the load guard stands the bundled copy down. |
| `Conflict_Policy::NOTICE_ONLY` | Leave it active and ask the user to deactivate it. |

Set one per sub-plugin with the `conflict_policy` key, or decide it at runtime with the
`conflict_policy` [filter](filters.md), which has the final say.

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

## What the guard cannot do

The guard cannot help on the request that *activates* the standalone: WordPress includes it after
the bundled copy has already loaded, so that re-declaration is a real fatal. WordPress catches it
in its activation sandbox, and this library rewrites the resulting error screen into an
explanation.
