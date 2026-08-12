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

On multisite `activate_plugins` maps through `manage_network_plugins`, so it is a network
administrator, not the site administrator who installed the plugin, who sees these.

## Rendering them yourself

`Notices\Queue::option_name()` is public, so you can render the queue yourself without replacing
anything. The value is an `array<string,string>` keyed `slug:type` — `give-recurring:merge`, for
example — and the messages may contain markup; the default rendering passes them through
`wp_kses_post()`, so a link, emphasis or a list survives while scripts and event handlers are
stripped. Paragraphs come from `wpautop()`, so send the message unwrapped and let a blank line
break it — a `<p>` of your own is left as it is rather than nested inside another.

```php
use Nexcess\PluginAbsorber\Notices\Queue;

add_action( 'admin_init', function () {
    $notices = get_site_option( Queue::option_name(), [] );

    if ( ! is_array( $notices ) || ! $notices ) {
        return;
    }

    foreach ( $notices as $key => $message ) {
        my_plugin_enqueue_notice( $key, $message );
    }

    delete_site_option( Queue::option_name() );
} );
```

`admin_init` runs before `all_admin_notices`, where the built-in rendering happens, so deleting the
option there leaves ours nothing to draw and the notice is shown once, by you. Do the deleting: a
notice read and not cleared is shown on every request forever. And keep the capability check —
reading the option directly skips the `activate_plugins` gate `Queue::render()` applies, and a
consumed notice is gone for the administrator who could have acted on it.

The queue is three classes: `Notices\Queue` decides what a notice says and who may consume it,
`Notices\Store` keeps it, `Notices\Renderer` draws it. `Queue` takes both as constructor arguments
and all three are bound in the container, so rebinding `Notices\Renderer` replaces the markup and
leaves the storage alone, and rebinding `Notices\Store` does the reverse.
