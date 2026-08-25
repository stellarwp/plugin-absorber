# Filters

`{prefix}` is the value passed to `Config::set_hook_prefix()`.

| Filter | Arguments | Purpose |
|---|---|---|
| `{prefix}/plugin_absorber/conflict_policy` | `string $policy`, `Sub_Plugin $sub_plugin` | Final say over the conflict policy. |
| `{prefix}/plugin_absorber/conflict_notice_message` | `string $message`, `Sub_Plugin $sub_plugin` | Final say over the conflict notice text. Receives the configured message, or the caller's fallback when nothing is configured. |
| `{prefix}/plugin_absorber/dependency_notice_message` | `string $message`, `Sub_Plugin $sub_plugin` | Final say over the dependency notice text. Receives the configured message, or the generic default sentence when nothing is configured. |
| `{prefix}/plugin_absorber/stranding_notice_message` | `string $message`, `Sub_Plugin $sub_plugin` | Final say over the multisite stranding notice text. Receives the generic default; this notice has no config key, so the filter is its only override. |

Each runs last, after the configured value and any fallback, and fires when the value is read rather
than at registration — so it is also the place to call `__()`. A non-scalar return yields an empty
string rather than a fatal cast, and a `conflict_policy` return that is not one of the three
constants is treated as [`NOTICE_ONLY`, never as consent to
deactivate](conflict-handling.md#policies).

## The load gate

| Filter | Arguments | Purpose |
|---|---|---|
| `{prefix}/plugin_absorber/should_load` | `bool $should_load`, `Sub_Plugin $sub_plugin` | Last word before `require_once`, and before a conflict is resolved. |

```php
add_filter( 'give/plugin_absorber/should_load', function ( $should_load, $sub_plugin ) {
    return $sub_plugin->get_slug() === 'give-recurring' ? false : $should_load;
}, 10, 2 );
```

It is consulted only for a sub-plugin that would otherwise have loaded — after the enabled check,
the guard constant, the dependency check and the file check. Returning `true` cannot force a load
past the guard constant, and anything but a truthy return skips the load.

**Conflict handling reads it too**, at `plugins_loaded` priority 5, one ahead of the load: a
sub-plugin you veto is invisible to it, so no standalone is deactivated to make room for a bundled
copy that is not going to load. The filter is therefore asked more than once in a request, and has
to *decide* rather than do — no logging, no counters, no writes. Whether that pass sees your callback
turns on when you call `add_filter()`, not on the priority you give it: registered after
`plugins_loaded` priority 5, it has missed the pass. Put a toggle both passes always read in the
`enabled` [config key](configuration.md) instead.

**Watch the polarity when you wire an existing gate to this one.** `should_load` true means *do
load*. A host filter named for the opposite — LearnDash's `learndash_module_{x}_disabled`, where
true means *do not load* — inverts the gate if passed through unnegated, and fails silently in the
direction that loads a plugin the site turned off.
