# Notices

The three notices this library raises — the standalone was deactivated, the standalone is still
active, a dependency check failed — are queued in a single option named
`{prefix}_plugin_absorber_notices`, where `{prefix}` is the value passed to
`Config::set_hook_prefix()`. On multisite it is a **network** option, so the queue is shared across
every site on the network.

An option and not a transient, on purpose. With a persistent object cache a transient never reaches
the database, so a `wp_cache_flush()` from a deploy script or a "purge cache" button would destroy
the queue. The deactivation notice is raised exactly once and never re-queued, so losing it means
the site owner is never told their plugin was turned off.

## Who sees them

`Notices\Queue::render()` prints the queue and then clears it, and it is gated on the
`activate_plugins` capability. Since rendering consumes the queue, a user who cannot act on a notice
must not be shown one — a subscriber loading their profile page would otherwise silently swallow the
only warning an administrator was ever going to get.

On multisite `activate_plugins` usually maps through `manage_network_plugins`, so it is normally a
network administrator, not the site administrator who installed the plugin, who sees these. Not
always, though: a network that has enabled the Plugins menu for its sites turns that mapping off, and
site administrators hold `activate_plugins` directly. Conflict resolution therefore asks for
`manage_network_plugins` by name rather than relying on the mapping — see
[conflict handling](conflict-handling.md#when-resolution-runs).

## Rendering them yourself

`Absorber::notices()->option_name()` tells you where the queue is kept, so you can render it
yourself without replacing anything — and it answers for whichever queue the site is running, so a
rebound implementation keeping its notices elsewhere still gives you the right name. The value is an `array<string,string>` keyed `slug:type` — `give-recurring:merge`, for
example — and the messages may contain markup; the default rendering passes them through
`wp_kses_post()`, so a link, emphasis or a list survives while scripts and event handlers are
stripped. Paragraphs come from `wpautop()`, so send the message unwrapped and let a blank line
break it — a `<p>` of your own is left as it is rather than nested inside another.

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

The queue is three classes: `Notices\Queue` decides what a notice says and who may consume it,
`Notices\Store` keeps it, `Notices\Renderer` draws it. `Queue` takes both as constructor arguments
and all three are bound in the container, so rebinding `Notices\Renderer` replaces the markup and
leaves the storage alone, and rebinding `Notices\Store` does the reverse.
