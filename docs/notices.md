# Notices

The three notices this library raises — the standalone was deactivated, the standalone is still
active, a dependency check failed — are queued in a single option named
`{option_prefix}_plugin_absorber_notices`, where `{option_prefix}` is the hook prefix lowercased
with hyphens folded to underscores: a hook prefix of `Give-Core` stores
`give_core_plugin_absorber_notices`. On multisite it is a **network** option, so the queue is shared
across every site on the network. It is an option and not a transient because a persistent object
cache keeps transients out of the database entirely, and a `wp_cache_flush()` would destroy a notice
that is raised exactly once and never re-queued.

## Who sees them

Rendering prints the queue and then clears it, gated on the `activate_plugins` capability. Since
rendering consumes the queue, a user who cannot act on a notice must not be shown one — a
subscriber loading their profile page would otherwise silently swallow the only warning an
administrator was ever going to get.

On multisite that is usually a network administrator rather than the site administrator who
installed the plugin: core maps `activate_plugins` through `manage_network_plugins` unless the
network has enabled the plugins menu for individual sites. Conflict resolution does not rely on that
mapping and asks for `manage_network_plugins` by name — see
[conflict handling](conflict-handling.md#when-resolution-runs).

## One message, two places

`conflict_notice_message` backs both the queued notice raised when the standalone is deactivated and
the [activation-error screen](conflict-handling.md#reactivating-the-standalone) a user meets if they
try to re-activate it. Write one sentence that reads sensibly both as a report of something already
done and as the explanation standing in for a fatal-error warning.

## Rendering them yourself

`Absorber::notices()->option_name()` tells you where the queue is kept, so you can render it
yourself without replacing anything. The value is an `array<string,string>` keyed `slug:type`, where
the type is `merge`, `conflict` or `dependency` — `give-recurring:merge`, for example. The first
two render as `notice-warning` and the third as `notice-error`, since a dependency notice reports a
plugin that did not load at all. The messages may contain markup; the built-in rendering passes
them through `wp_kses_post()`, so a link, emphasis or a list survives while scripts and event
handlers are stripped. Paragraphs come from `wpautop()`, so send the message unwrapped and let a
blank line break it — a `<p>` of your own is left as it is rather than nested inside another.

```php
use Nexcess\PluginAbsorber\Absorber;

add_action( 'admin_init', function () {
    // Gates the read, not just the delete: `admin_init` fires for every logged-in user, and
    // draining the queue for one who cannot act on it destroys the only warning an
    // administrator was going to get.
    if ( ! current_user_can( 'activate_plugins' ) ) {
        return;
    }

    $option  = Absorber::notices()->option_name();
    $notices = get_site_option( $option, [] );

    if ( ! is_array( $notices ) || ! $notices ) {
        return;
    }

    foreach ( $notices as $key => $message ) {
        my_plugin_enqueue_notice( $key, $message );
    }

    delete_site_option( $option );
} );
```

`admin_init` runs before `all_admin_notices`, where the built-in rendering happens, so deleting the
option there leaves ours nothing to draw and the notice is shown once, by you. Do the deleting: a
notice read and not cleared is shown on every request forever.

To reword the notices or replace the markup rather than render them alongside, see
[Extending](extending.md).
