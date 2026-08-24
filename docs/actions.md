# Actions

What the library tells you as it runs. [Filters](filters.md) are the other direction — the values
you override. `{prefix}` is the value passed to `Config::set_hook_prefix()`.

| Action | Arguments | Fires when |
|---|---|---|
| `{prefix}/plugin_absorber/error` | `string $message`, `Sub_Plugin\|null $sub_plugin` | Something went wrong that a developer has to fix. |

Everything announced here also goes to `_doing_it_wrong()`, which is silent unless `WP_DEBUG` is on.
This is the channel that is not — reach for it for a log line, a health check, or a support tool.

## Errors

`error` carries the sentence a developer needs, and the sub-plugin it belongs to when it belongs to
one — a duplicate slug, a broken bundled file, a sub-plugin whose own code threw, a conflict that
could not be resolved. It is `null` for a failure that belongs to no single registration: a boot
that came too late to wire, a pass that threw before it reached any sub-plugin, notices that could
not be rendered.

```php
add_action( 'give/plugin_absorber/error', function ( $message, $sub_plugin ) {
    error_log( 'plugin-absorber: ' . $message );
}, 10, 2 );
```

**A bootstrap with no hook prefix cannot be announced.** The prefix is what names this action, so
the one failure `error` can never carry is a missing `Config::set_hook_prefix()`. That one goes to
`_doing_it_wrong()` alone.

## Your listener cannot take the site down

`error` fires from inside `plugins_loaded`, and from inside the handlers that keep a failing
sub-plugin from white-screening the site, so a listener that throws is caught rather than allowed
out. A throw from it costs nothing at all and is itself reported through `_doing_it_wrong()`. That
is a backstop, not a licence — a listener here runs on every request the site serves, so keep it
cheap and keep it quiet.
