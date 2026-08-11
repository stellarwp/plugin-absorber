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
example — and the messages are plain text; the default rendering escapes them with `esc_html()`, so
a link in a message comes out as literal angle brackets.

The queue is three classes: `Notices\Queue` decides what a notice says and who may consume it,
`Notices\Store` keeps it, `Notices\Renderer` draws it. Both collaborators are constructor arguments,
so `new Queue( null, $renderer )` keeps the queue and replaces only the markup, and
`new Queue( $store )` does the reverse. Replacing either one leaves the other alone.
