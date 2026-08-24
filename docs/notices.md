# Notices

The notices this library raises — the standalone was deactivated, the standalone is still active, a
network-active standalone was left active to avoid stranding sites, a dependency check failed — are
queued in a single option named
`{option_prefix}_plugin_absorber_notices`, where `{option_prefix}` is the hook prefix lowercased
with hyphens folded to underscores: a hook prefix of `Give-Core` stores
`give_core_plugin_absorber_notices`. On multisite it is a **network** option, so the queue is shared
across every site on the network. It is an option and not a transient because a persistent object
cache keeps transients out of the database entirely, and a `wp_cache_flush()` would destroy a notice
that is raised exactly once and never re-queued.

## Who sees them

Rendering prints the queue and then clears it, gated on the capability
[conflict resolution](conflict-handling.md#when-resolution-runs) asks for: `manage_network_plugins`
on multisite and `activate_plugins` otherwise. Since rendering consumes the queue, a user who cannot
act on a notice must not be shown one — a subscriber loading their profile page would otherwise
silently swallow the only warning an administrator was ever going to get.

On multisite that means a network administrator rather than the site administrator who installed the
plugin, and the network capability is asked for by name rather than left to core's mapping of
`activate_plugins`, which only widens into it while the network keeps the Plugins menu off for
individual sites. On a network that has turned that menu on, every site administrator holds
`activate_plugins` outright: any one of them opening any admin screen would otherwise print a notice
raised for the network and clear it network-wide, and the queue is one option shared by every site,
so it would be gone for everyone else. That covers the dependency notice too — a site administrator
no longer consumes one, which is no loss, since a queue shared by the whole network was never theirs
alone to consume.

## One message, two places

`conflict_notice_message` backs both the queued notice raised when the standalone is deactivated and
the [activation-error screen](conflict-handling.md#reactivating-the-standalone) a user meets if they
try to re-activate it. Write one sentence that reads sensibly both as a report of something already
done and as the explanation standing in for a fatal-error warning.

## The stranding notice

On multisite only, a network-active standalone whose bundled copy ships in a host plugin that is not
itself network-activated is left active rather than deactivated: turning it off across the network
would remove it from the sites the host is not active on, where nothing loads the bundled copy. This
notice explains that, and — unlike the one-time deactivation notice — it recurs until the topology is
resolved, either by network-activating the host or by removing the standalone from the Network Admin.
Its text is the `stranding_notice_message` [filter](filters.md); there is no config key for it.

## Rendering them yourself

`Absorber::notices()->option_name()` tells you where the queue is kept, so you can render it
yourself without replacing anything. The value is an `array<string,string>` keyed `slug:type`, where
the type is `merge`, `conflict`, `stranding` or `dependency` — `give-recurring:merge`, for example.
The first three render as `notice-warning` and the last as `notice-error`, since a dependency notice
reports a plugin that did not load at all. The messages may contain markup; the built-in rendering passes
them through `wp_kses_post()`, so a link, emphasis or a list survives while scripts and event
handlers are stripped. Paragraphs come from `wpautop()`, so send the message unwrapped and let a
blank line break it — a `<p>` of your own is left as it is rather than nested inside another.

```php
use Nexcess\PluginAbsorber\Absorber;

add_action( 'admin_init', function () {
    // Gates the read, not just the delete: `admin_init` fires for every logged-in user, and
    // draining the queue for one who cannot act on it destroys the only warning an
    // administrator was going to get. The same capability the built-in rendering asks for.
    if ( ! current_user_can( is_multisite() ? 'manage_network_plugins' : 'activate_plugins' ) ) {
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
