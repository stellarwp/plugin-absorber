# Engineering Plan: `stellarwp/sub-plugin-loader`

## Context

StellarWP repeatedly needs to **absorb formerly-standalone WordPress plugins into a host
plugin** — e.g. consolidating several Give payment-gateway extensions under one Payment
Gateway extension, or the way LearnDash absorbed its Reporting/ProPanel, Licensing (Hub),
and Course Grid addons. Doing this by hand is error-prone: the biggest risk is a **fatal
error from class/function/constant re-declaration** when the bundled copy and the still-
installed standalone plugin both load in the same request.

Two prior implementations solved this ad hoc:

- **`sfwd-lms` (LearnDash)** — copied each addon into `includes/<addon>/` and wrote a
  per-addon `Legacy\Loader` (`src/Core/Modules/Course_Grid/Legacy/Loader.php`, etc.). The
  three modules diverged in class shape (`Legacy\Loader` vs `ProPanel2` vs
  `Resolver`+`Loader`), guard-constant type, and whether version negotiation existed at
  all — the exact inconsistency we want to remove.
- **`kadence-shop-kit`** — the more polished, config-array-driven Strategy Pattern in
  `inc/Common/Features/` (Provider → Repository → Resolver → Strategy → value-object
  Models). Excellent mechanics, but the Strategy indirection is heavier than we need and
  it's coupled to Kadence's option store (`KWE_Options`) and DI52 container.

**Goal:** publish a small, dependency-free StellarWP library that gives any plugin a
**single standardized, safe way** to load bundled sub-plugins — togglable or always-on —
distilling the good parts of both references without the Strategy-Pattern overhead or a
container requirement.

### Decisions locked with the user
1. **Public API:** static facade (`Config` + `Loader`), matching `stellarwp/assets` and
   `stellarwp/admin-notices`. **Container support is included** (optional at runtime) via
   `stellarwp/container-contract` — see below.
2. **Default conflict policy:** when the standalone is still active, **auto-deactivate it**,
   load the bundled copy, safe-redirect, and queue an explanatory admin notice — overridable
   per sub-plugin (`deactivate` | `defer` | `notice_only`).
3. **Activation lifecycle:** provide an optional **`activation_callback`** that runs exactly once
   ever (tracked per slug), since `register_activation_hook()` never fires for a `require_once`'d
   file. It reproduces the absorbed plugin's original activation only; sub-plugins still own their
   own idempotent, version-gated upgrade migrations.
4. **Naming:** class names use `Snake_Case` (StellarWP/Kadence convention — `Sub_Plugin`,
   `Conflict_Policy`); methods are fully spelled-out and readable; config keys are descriptive
   and use WordPress-centric terminology (`plugin_basename`, `plugin file`, `Requires PHP`).
5. **Extensibility (seams + optional container):** every behavioral collaborator (`Registrar`,
   `Conflict\Resolver`, `Notices`, `Activation`) is interface-backed and container-resolvable, so a
   di52 host can globally rebind any of them — while the container stays optional. Plus two
   per-sub-plugin seams that need no container: a callable `conflict_policy` and namespaced WP
   filters (`…/should_load`, `…/conflict_policy`). Together these cover future one-off cases (a
   ProPanel-style "newer standalone supersedes the bundle" override) without baking that logic into
   core.

### Explicitly out of scope
- **No version negotiation / "safe version" threshold.** That existed only because of a
  LearnDash ProPanel quirk (v2.x was absorbed while a completely different plugin was rebranded
  ProPanel v3.0). We will not shape the architecture around that edge case.
- **No opinion on the toggle UI/storage.** The host plugin owns that and passes an `enabled`
  bool/callable.
- **No production dependency on any other StellarWP library** (mirrors `assets` /
  `admin-notices`). Admin-notice rendering is implemented internally, self-contained.
- We do **not** reproduce the Strategy/Resolver indirection; one load path handles every
  sub-plugin.

---

## Architecture Overview

```
Host plugin bootstrap
  └─ Config::set_hook_prefix('give'); Config::set_version('3.0.0');
  └─ Loader::register([...sub-plugin config...])   // one call per sub-plugin
  └─ Loader::register([...])
  └─ Loader::boot()                                // wires WP hooks once

WP request lifecycle (hooks wired by boot()):
  plugins_loaded @ 1  → Conflict\Resolver: for each enabled sub-plugin whose standalone is
                        active, act per conflict_policy (default: deactivate_plugins() +
                        queue notice + safe wp_safe_redirect(); exit;)
  plugins_loaded @ 2  → Loader::load_all():
                          for each sub-plugin:
                            - is_enabled()?                        (skip if off)
                            - are_dependencies_met()?              (skip + notice if not)
                            - is_already_loaded()?                 (skip: constant defined → avoid fatal)
                            - file_exists(bundled_plugin_file)?    (skip if missing)
                            - {prefix}/…/should_load filter?       (skip if false)
                            - require_once bundled_plugin_file
                            - activation()->maybe_run($sub_plugin) (run-once callback)
  admin_head-plugins.php → Notices: start output buffer (to rewrite WP's fatal-activation text)
  admin_notices          → Notices: render queued/merge notices, then clear
```

### The safety layers (lifted from both references)
1. **Load-guard constant** — every sub-plugin declares a `plugin_loaded_constant` that both
   the bundled copy and the standalone define when they run (e.g. `GIVE_RECURRING_VERSION`).
   Before `require_once`, if `defined( $constant )` the code is already present → skip,
   preventing re-declaration fatals regardless of load order. (LearnDash
   `Course_Grid/Legacy/Loader::is_loaded()`; Kadence `is_legacy_plugin_loaded()`.) The bundled
   file must itself define the constant inside an `if ( ! defined() )` guard.
2. **Standalone-active detection + policy** — `is_plugin_active()` /
   `is_plugin_active_for_network()` (lazy-loading `wp-admin/includes/plugin.php`), keyed off the
   standalone's WordPress **plugin basename**, then act per `conflict_policy`. (Kadence
   `Strategies\Plugin_Feature::deactivate()`.)

The load-guard constant and the standalone basename are **two separate, single-purpose config
keys** — no constant does double duty as both a guard and a path resolver.

---

## Repo Skeleton

Mirror `stellarwp/admin-notices` exactly (Codeception + PHPStan; no phpcs/Strauss config in
the library itself; `.gitattributes` export-ignore for dev files):

```
sub-plugin-loader/
├── src/
│   ├── Config.php                     # static config facade
│   ├── Loader.php                     # static registration + boot + load loop
│   ├── Sub_Plugin.php                 # value object per sub-plugin
│   ├── Conflict_Policy.php            # 'deactivate' | 'defer' | 'notice_only' constants
│   ├── Conflict/
│   │   ├── Resolver.php               # default: standalone detection, deactivation, safe redirect
│   │   └── Resolver_Interface.php     # rebindable conflict-resolution contract
│   ├── Registrar.php                  # default: holds the Sub_Plugin registry
│   ├── Activation.php                 # default: run-once activation_callback tracking
│   ├── Notices.php                    # default: self-contained admin notices (transient + buffer rewrite)
│   ├── Contracts/
│   │   ├── Registrar_Interface.php    # each collaborator is interface-backed so a host
│   │   ├── Notices_Interface.php      #   can bind/override any of them via its container
│   │   └── Activation_Interface.php
│   └── Exceptions/
│       └── Config_Exception.php
├── tests/                             # Codeception unit suite (WPLoader), mirrors src/
│   ├── _bootstrap.php  config.php  unit.suite.yml
│   ├── _support/  _data/  _output/
│   └── unit/
├── .github/workflows/
│   ├── static-analysis.yml            # setup-php 8.0, composer test:analysis
│   └── tests-php.yml                  # slic use / composer install / slic run unit
├── composer.json
├── phpstan.neon.dist                  # level 5, szepeviktor/phpstan-wordpress
├── codeception.dist.yml  codeception.slic.yml
├── .env.testing  .env.testing.slic
├── .editorconfig  .gitattributes  .gitignore
├── LICENSE  (GPL-2.0-or-later)
└── README.md
```

### `composer.json`

```json
{
    "name": "stellarwp/sub-plugin-loader",
    "description": "Safely load bundled WordPress plugins inside a host plugin, togglable or always-on, without fatal errors.",
    "type": "library",
    "license": "GPL-2.0-or-later",
    "minimum-stability": "stable",
    "authors": [{ "name": "StellarWP", "email": "eric_defore@vendor.stellarwp.com" }],
    "require": {
        "php": ">=7.4",
        "stellarwp/container-contract": "^1.0"
    },
    "require-dev": {
        "lucatume/di52": "^3.0",
        "lucatume/wp-browser": "^3.6.5",
        "codeception/module-asserts": "^1.0",
        "codeception/util-universalframework": "^1.0",
        "phpunit/phpunit": "^9.5",
        "szepeviktor/phpstan-wordpress": "^1.3"
    },
    "autoload": {
        "psr-4": { "Nexcess\\SubPluginLoader\\": "src/" }
    },
    "autoload-dev": {
        "psr-4": { "Nexcess\\SubPluginLoader\\Tests\\": "tests/" }
    },
    "scripts": {
        "test:analysis": [ "phpstan analyse -c phpstan.neon.dist --memory-limit=512M" ]
    },
    "config": { "platform": { "php": "7.4" } }
}
```

**One tiny, interface-only production dependency** (`stellarwp/container-contract`, the same one
`uplink` takes — `di52` stays dev-only). No dependency on any other StellarWP *feature*
library. Version-collision across plugins is solved *consumer-side* by Strauss re-namespacing
`Nexcess\SubPluginLoader\` into the host's vendor namespace — documented prominently in the
README, exactly as admin-notices does. (`stellarwp/container-contract` is safe to leave
unprefixed / in Strauss `exclude_from_copy`, like `psr/container`.)

---

## Public API

Root namespace `Nexcess\SubPluginLoader\`. Two static entry classes.

### `Config` (assets-style static facade)

```php
namespace Nexcess\SubPluginLoader;

use RuntimeException;
use StellarWP\ContainerContract\ContainerInterface;

class Config {
    /** @var string */
    protected static $hook_prefix = '';
    /** @var string */
    protected static $version = '';
    /** @var ContainerInterface|null */
    protected static $container = null;

    /** Unique per-host slug; keys hooks, transients, and the activation-tracking option. */
    public static function set_hook_prefix( string $prefix ): void {
        if ( preg_match( '/[^a-zA-Z0-9_-]/', $prefix ) ) {
            throw new RuntimeException( 'Hook prefix must only contain letters, numbers, hyphens, and underscores.' );
        }
        self::$hook_prefix = $prefix;
    }

    public static function get_hook_prefix(): string {
        if ( self::$hook_prefix === '' ) {
            throw new RuntimeException( 'You must call Config::set_hook_prefix() before booting the Sub Plugin Loader.' );
        }
        return self::$hook_prefix;
    }

    public static function set_version( string $version ): void { self::$version = $version; }
    public static function get_version(): string { return self::$version; }

    /** Optional: share the host's container so the library's collaborators are bindable/overridable. */
    public static function set_container( ContainerInterface $container ): void { self::$container = $container; }
    public static function get_container(): ?ContainerInterface { return self::$container; }
    public static function has_container(): bool { return self::$container !== null; }

    /** Resets all static state — used by tests (mirrors Assets\Config::reset()). */
    public static function reset(): void {
        self::$hook_prefix = '';
        self::$version     = '';
        self::$container   = null;
    }
}
```

#### Container support & rebinding (optional at runtime)

Typed to `StellarWP\ContainerContract\ContainerInterface` (`bind`/`singleton`/`get`/`has`) so we
never depend on a concrete container — the consumer adapts their di52 (or any) container and
passes it via `Config::set_container()`. It is **entirely optional**: with no container the
library instantiates its own defaults.

Every one of the library's behavioral collaborators is **interface-backed and
container-resolvable** — not just the registry. Each is resolved through one
"resolve-from-container-or-fall-back-to-`new`" helper (the admin-notices `getRegistrar()`
pattern, generalized):

| Interface | Default implementation | Responsibility |
|---|---|---|
| `Registrar_Interface` | `Registrar` | holds the registered `Sub_Plugin` value objects |
| `Conflict\Resolver_Interface` | `Conflict\Resolver` | standalone detection, deactivation, redirect |
| `Notices_Interface` | `Notices` | queued admin notices + activation-error rewrite |
| `Activation_Interface` | `Activation` | run-once activation-callback tracking |

A host running di52 can therefore `bind()` its own implementation of any of these to **globally
override** loader behavior (e.g. a custom `Conflict\Resolver` that adds site-specific version
negotiation), while a host with no container gets the zero-config defaults. The container stays
optional; it is **not** used to wire WP hooks (those remain plain static callbacks — see
`boot()`).

```php
namespace Nexcess\SubPluginLoader;

class Loader {
    /** @var array<class-string,object> resolved collaborators, memoized */
    private static $resolved = [];

    /** Resolve an interface from the container when bound, else construct the default. */
    private static function resolve( string $interface, string $default_class ): object {
        if ( isset( self::$resolved[ $interface ] ) ) {
            return self::$resolved[ $interface ];
        }
        $container = Config::get_container();
        if ( $container && $container->has( $interface ) ) {
            return self::$resolved[ $interface ] = $container->get( $interface );
        }
        return self::$resolved[ $interface ] = new $default_class();
    }

    public static function registrar(): Contracts\Registrar_Interface {
        return self::resolve( Contracts\Registrar_Interface::class, Registrar::class );
    }
    public static function resolver(): Conflict\Resolver_Interface {
        return self::resolve( Conflict\Resolver_Interface::class, Conflict\Resolver::class );
    }
    public static function notices(): Contracts\Notices_Interface {
        return self::resolve( Contracts\Notices_Interface::class, Notices::class );
    }
    public static function activation(): Contracts\Activation_Interface {
        return self::resolve( Contracts\Activation_Interface::class, Activation::class );
    }
}
```

Collaborators reference each other through these accessors (e.g. `Conflict\Resolver` calls
`Loader::notices()->queue_merge_notice(...)`), so rebinding one automatically flows everywhere.

#### Two per-sub-plugin extension seams (no container required)

Global rebinding is coarse-grained; most real overrides are **per sub-plugin** (the ProPanel
"this specific successor supersedes the bundle" case). Two lighter seams cover that without any
container:

1. **Callable `conflict_policy`.** The key accepts a string constant *or* a
   `callable( Sub_Plugin ): string` returning one, so a single sub-plugin can decide its policy at
   runtime — reintroducing a ProPanel-style version check as pure config:
   ```php
   'conflict_policy' => static function ( Sub_Plugin $sub_plugin ) {
       // Defer to a newer standalone successor; otherwise absorb + deactivate.
       return standalone_version_at_least( $sub_plugin, '3.0.0' )
           ? Conflict_Policy::DEFER
           : Conflict_Policy::DEACTIVATE;
   },
   ```
2. **Namespaced WordPress filters** at each decision point (the idiom LearnDash/Kadence shipped,
   e.g. `learndash_module_course_grid_disabled`), keyed by `Config::get_hook_prefix()` and slug:
   - `"{$prefix}/sub_plugin_loader/should_load"` — `bool` gate applied right before `require_once`
     (args: `bool $should_load, Sub_Plugin $sub_plugin`).
   - `"{$prefix}/sub_plugin_loader/conflict_policy"` — `string` policy (args: `string $policy,
     Sub_Plugin $sub_plugin`), applied after the config value/callable resolves.

### Sub-plugin config schema (the array passed to `Loader::register()`)

Descriptive, WordPress-centric keys. The two conflict concerns are kept separate:
`plugin_loaded_constant` (load guard only) vs `standalone_plugin_basename` (active-detection /
deactivation only).

| Key | Type | Req | Meaning |
|---|---|---|---|
| `slug` | string | ✔ | Unique id for this sub-plugin (registry key, notice id, activation-tracking key). |
| `bundled_plugin_file` | string | ✔ | Absolute path to the **bundled** plugin's main file — the file we `require_once`. |
| `plugin_loaded_constant` | string | ✔ | Name of a constant the plugin defines when it loads (the bundled copy *and* the standalone define the same one, e.g. `GIVE_RECURRING_VERSION`). `defined()` ⇒ already loaded ⇒ skip, preventing re-declaration fatals. **Load-guard only.** |
| `standalone_plugin_basename` | string | — | The standalone's WordPress plugin basename (`dir/file.php`, the value `plugin_basename()` returns), used with `is_plugin_active()` / `deactivate_plugins()`. Omit for a sub-plugin with no standalone counterpart. **Detection/deactivation only.** |
| `enabled` | bool\|callable | — | `true` (default) = always-on; a callable resolved at load time = togglable. Host owns the toggle UI/storage. |
| `conflict_policy` | string\|callable | — | `Conflict_Policy::DEACTIVATE` (default) \| `DEFER` \| `NOTICE_ONLY`, or a `callable( Sub_Plugin ): string` returning one (per-sub-plugin runtime decision). |
| `conflict_notice_message` | string\|callable | — | Message shown when the standalone is auto-deactivated, and used to rewrite WP's fatal-activation text if the user re-activates the standalone. Callable to defer `__()` past `init` (WP 6.7+ early-translation `_doing_it_wrong`). |
| `activation_callback` | callable | — | Run **exactly once, ever** (first time this slug loads). Reproduces the standalone's original `register_activation_hook` routine, which never fires for a `require_once`'d file. |
| `dependency_check` | callable | — | Returns `bool`; skip load (with a notice) if unmet — e.g. `fn() => class_exists('WooCommerce')`. |

> Runtime dependency gates (is WooCommerce active, etc.) go through `dependency_check`; a
> sub-plugin inherits the host plugin's PHP floor, so there is no separate per-sub-plugin PHP gate.
>
> `activation_callback` reproduces the absorbed plugin's *original* activation hook and runs
> exactly once, ever (tracked by slug). It is not a place for ongoing upgrade logic — once merged,
> a sub-plugin adds no new activation code and handles version upgrades with its own idempotent,
> version-gated migrations on load (the `*_db_version`-option pattern both references use).

### Consumer usage (README example)

```php
use Nexcess\SubPluginLoader\Config;
use Nexcess\SubPluginLoader\Loader;
use Nexcess\SubPluginLoader\Conflict_Policy;

add_action( 'plugins_loaded', function () {
    Config::set_hook_prefix( 'give' );
    Config::set_version( GIVE_VERSION );
    Config::set_container( give()->container ); // optional — share the host's di52 container

    Loader::register( [
        'slug'                       => 'give-recurring',
        'bundled_plugin_file'        => GIVE_PLUGIN_DIR . 'subs/give-recurring/give-recurring.php',
        'plugin_loaded_constant'     => 'GIVE_RECURRING_VERSION',
        'standalone_plugin_basename' => 'give-recurring/give-recurring.php',
        'enabled'                    => static fn() => give_is_gateway_enabled( 'recurring' ),
        'conflict_policy'            => Conflict_Policy::DEACTIVATE,
        'conflict_notice_message'    => static fn() => __( 'Give Recurring is now bundled with Give and can be deactivated.', 'give' ),
        'activation_callback'        => static function () { \Give\Recurring\Install::create_tables(); },
        'dependency_check'           => static fn() => class_exists( 'Give' ),
    ] );

    Loader::boot();
}, 0 ); // register before our own plugins_loaded @ 1/2 fire
```

> The host registers at `plugins_loaded` priority `0` (or at the plugin-file top level) so the
> loader's own `@1`/`@2` hooks are attached before they run. `boot()` is idempotent and guards
> against double-wiring.

---

## Core Classes (implementation sketches)

### `Conflict_Policy.php`
```php
namespace Nexcess\SubPluginLoader;
final class Conflict_Policy {
    const DEACTIVATE  = 'deactivate';
    const DEFER       = 'defer';
    const NOTICE_ONLY = 'notice_only';
}
```

### `Sub_Plugin.php` — value object + all predicate logic

Generalizes Kadence's `Models\Plugin_Feature` (drop the DI52 `Model` base + `KWE_Options`);
readable method names throughout:

```php
namespace Nexcess\SubPluginLoader;

use Nexcess\SubPluginLoader\Exceptions\Config_Exception;

class Sub_Plugin {
    /** @var array<string,mixed> */
    private $config;

    public function __construct( array $config ) {
        foreach ( [ 'slug', 'bundled_plugin_file', 'plugin_loaded_constant' ] as $required ) {
            if ( empty( $config[ $required ] ) ) {
                throw new Config_Exception( "Sub-plugin config is missing required key: {$required}" );
            }
        }
        $this->config = $config;
    }

    public function get_slug(): string                   { return (string) $this->config['slug']; }
    public function get_bundled_plugin_file(): string    { return (string) $this->config['bundled_plugin_file']; }
    public function get_plugin_loaded_constant(): string { return (string) $this->config['plugin_loaded_constant']; }

    /** Resolve the policy (string or callable), then let a namespaced filter override it. */
    public function get_conflict_policy(): string {
        $policy = $this->config['conflict_policy'] ?? Conflict_Policy::DEACTIVATE;
        if ( is_callable( $policy ) ) {
            $policy = $policy( $this );
        }
        return (string) apply_filters(
            Config::get_hook_prefix() . '/sub_plugin_loader/conflict_policy',
            $policy,
            $this
        );
    }

    public function is_enabled(): bool {
        $enabled = $this->config['enabled'] ?? true;
        return (bool) ( is_callable( $enabled ) ? $enabled() : $enabled );
    }

    /** True when the plugin's code is already present (bundled copy OR standalone) — the fatal guard. */
    public function is_already_loaded(): bool {
        return defined( $this->get_plugin_loaded_constant() );
    }

    public function has_standalone_plugin(): bool {
        return ! empty( $this->config['standalone_plugin_basename'] );
    }

    public function get_standalone_plugin_basename(): string {
        return (string) ( $this->config['standalone_plugin_basename'] ?? '' );
    }

    public function is_standalone_plugin_active(): bool {
        if ( ! $this->has_standalone_plugin() ) {
            return false;
        }
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $basename = $this->get_standalone_plugin_basename();
        return is_plugin_active( $basename ) || is_plugin_active_for_network( $basename );
    }

    public function are_dependencies_met(): bool {
        $check = $this->config['dependency_check'] ?? null;
        return is_callable( $check ) ? (bool) $check() : true;
    }

    public function get_conflict_notice_message(): string {
        $message = $this->config['conflict_notice_message'] ?? '';
        return (string) ( is_callable( $message ) ? $message() : $message );
    }

    /** @return callable|null */
    public function get_activation_callback() {
        return $this->config['activation_callback'] ?? null;
    }
}
```

### `Loader.php` — static facade (register + boot + load loop)

The registry itself lives in a small `Registrar` (implementing `Contracts\Registrar_Interface`:
`register(Sub_Plugin)`, `all(): array`, `reset()`) — a plain object holding a
`slug => Sub_Plugin` map, resolved via the `registrar()` helper above so it's container-bindable.

```php
namespace Nexcess\SubPluginLoader;

class Loader {
    /** @var bool */
    private static $booted = false;

    // resolve()/registrar()/resolver()/notices()/activation() — see the Container section above.

    public static function register( array $config ): void {
        self::registrar()->register( new Sub_Plugin( $config ) ); // last-wins dedupe by slug
    }

    /** @return array<string,Sub_Plugin> */
    public static function all(): array { return self::registrar()->all(); }

    public static function boot(): void {
        if ( self::$booted ) {
            return;
        }
        self::$booted = true;

        // Plain static trampolines delegate to the (rebindable) resolved collaborators.
        add_action( 'plugins_loaded', [ self::class, 'run_conflict_resolution' ], 1 );
        add_action( 'plugins_loaded', [ self::class, 'load_all' ], 2 );

        if ( is_admin() ) {
            add_action( 'admin_head-plugins.php', [ self::class, 'start_notice_buffer' ] );
            add_action( 'admin_notices', [ self::class, 'render_notices' ] );
        }
    }

    public static function run_conflict_resolution(): void { self::resolver()->resolve_all(); }
    public static function start_notice_buffer(): void      { self::notices()->start_buffer(); }
    public static function render_notices(): void           { self::notices()->render(); }

    public static function load_all(): void {
        foreach ( self::all() as $sub_plugin ) {
            self::load( $sub_plugin );
        }
    }

    private static function load( Sub_Plugin $sub_plugin ): void {
        if ( ! $sub_plugin->is_enabled() ) {
            return;
        }
        if ( ! $sub_plugin->are_dependencies_met() ) {
            self::notices()->queue_dependency_notice( $sub_plugin );
            return;
        }
        if ( $sub_plugin->is_already_loaded() ) {   // constant defined → avoid fatal
            return;
        }
        if ( ! file_exists( $sub_plugin->get_bundled_plugin_file() ) ) {
            return;
        }

        // Final per-sub-plugin override seam.
        $should_load = apply_filters(
            Config::get_hook_prefix() . '/sub_plugin_loader/should_load',
            true,
            $sub_plugin
        );
        if ( ! $should_load ) {
            return;
        }

        require_once $sub_plugin->get_bundled_plugin_file();

        self::activation()->maybe_run( $sub_plugin );
    }

    /** Test seam. */
    public static function reset(): void { self::$resolved = []; self::$booted = false; }
}
```

> **Why plain static callbacks and not di52 "container callbacks"?** `admin-notices` sets the
> precedent we follow: its `initialize()` wires hooks with plain `add_action( 'admin_notices',
> [ self::class, 'setUpNotices' ] )` — no container callbacks. Container callbacks
> (`$container->callback( Class::class, 'method' )`, which lazily resolves an instance) are a
> di52 idiom reserved for **fully container-driven** libraries and plugin `ServiceProvider`s
> (uplink, telemetry, Kadence's `App::register( Provider::class )`), where a container is
> mandatory. Our public surface is a static facade like `admin-notices`/`assets`, so we hook plain
> static **trampoline** methods (`run_conflict_resolution`, `render_notices`, …) that each delegate
> to the resolved collaborator. That keeps the rebinding benefit — the collaborator behind the
> trampoline still comes from the container when bound — without making the container a hook-wiring
> dependency, so it stays genuinely optional.

### `Conflict/Resolver.php` — standalone deactivation + safe redirect

Default implementation of `Conflict\Resolver_Interface` (`resolve_all(): void`) — a di52 host can
bind a replacement to globally change conflict handling. Direct generalization of Kadence
`Strategies\Plugin_Feature::deactivate()` + `get_redirect()`:

```php
namespace Nexcess\SubPluginLoader\Conflict;

use Nexcess\SubPluginLoader\Conflict_Policy;
use Nexcess\SubPluginLoader\Loader;
use Nexcess\SubPluginLoader\Sub_Plugin;

class Resolver implements Resolver_Interface {
    public function resolve_all(): void {
        foreach ( Loader::all() as $sub_plugin ) {
            if ( ! $sub_plugin->is_enabled() || ! $sub_plugin->is_standalone_plugin_active() ) {
                continue;
            }
            $this->resolve( $sub_plugin );
        }
    }

    private function resolve( Sub_Plugin $sub_plugin ): void {
        switch ( $sub_plugin->get_conflict_policy() ) { // resolves callable + filter internally
            case Conflict_Policy::DEFER:
                // Standalone wins (its own constant makes load() skip the bundled copy). Do nothing.
                return;

            case Conflict_Policy::NOTICE_ONLY:
                Loader::notices()->queue_conflict_notice( $sub_plugin ); // ask the user to deactivate it
                return;

            case Conflict_Policy::DEACTIVATE:
            default:
                if ( ! function_exists( 'deactivate_plugins' ) ) {
                    require_once ABSPATH . 'wp-admin/includes/plugin.php';
                }
                deactivate_plugins( $sub_plugin->get_standalone_plugin_basename() );
                Loader::notices()->queue_merge_notice( $sub_plugin ); // transient-backed; survives redirect

                $destination = $this->redirect_destination( wp_get_referer() );
                if ( $destination !== false ) {
                    wp_safe_redirect( $destination );
                    exit;
                }
        }
    }

    /** Never trap the user during inline/AJAX plugin updates (Kadence get_redirect()). */
    private function redirect_destination( $referrer ) {
        if ( $referrer === false ) {
            return admin_url( 'plugins.php' );
        }
        foreach ( [ admin_url( 'update.php' ), admin_url( 'update-core.php' ) ] as $update_url ) {
            if ( strpos( $referrer, $update_url ) !== false ) {
                return admin_url( 'plugins.php' );
            }
        }
        if ( strpos( $referrer, admin_url( 'plugins.php' ) ) !== false ) {
            return false; // inline update on the plugins list — do not redirect
        }
        return $referrer;
    }
}
```

### `Activation.php` — run-once (ever) callback

`register_activation_hook()` never fires for a `require_once`'d file, so track it ourselves —
a simple per-slug "has this ever run?" flag in one option, so the callback fires exactly once ever.
Later upgrades are the sub-plugin's own idempotent, version-gated migrations on load.

```php
namespace Nexcess\SubPluginLoader;

use Nexcess\SubPluginLoader\Contracts\Activation_Interface;

class Activation implements Activation_Interface {
    private function option_name(): string {
        return Config::get_hook_prefix() . '_sub_plugin_activations';
    }

    public function maybe_run( Sub_Plugin $sub_plugin ): void {
        $callback = $sub_plugin->get_activation_callback();
        if ( ! is_callable( $callback ) ) {
            return;
        }
        $done = get_option( $this->option_name(), [] );
        $done = is_array( $done ) ? $done : [];

        if ( ! empty( $done[ $sub_plugin->get_slug() ] ) ) {
            return; // already run once, ever
        }
        $callback();

        $done[ $sub_plugin->get_slug() ] = true;
        update_option( $this->option_name(), $done, false );
    }
}
```

### `Notices.php` — self-contained (no `stellarwp/admin-notices` dependency)

Default implementation of `Contracts\Notices_Interface` (instance methods `start_buffer()`,
`render()`, `queue_merge_notice()`, `queue_conflict_notice()`, `queue_dependency_notice()`),
resolved via `Loader::notices()` so a host can bind its own. Two responsibilities, both from the
references:
1. **Transient-backed queue** (`{hook_prefix}_sub_plugin_notices`) so merge / conflict /
   dependency notices survive the `wp_safe_redirect()` and render on the next admin load, then
   clear (Kadence `queue_deactivation_notice()` / `display_deactivation_notices()`). Public:
   `queue_merge_notice()`, `queue_conflict_notice()`, `queue_dependency_notice()`, `render()`.
2. **Output-buffer rewrite** of WordPress's generic *"Plugin could not be activated because it
   triggered a fatal error."* on `plugins.php` — swap in the `conflict_notice_message` when the
   user tries to re-activate an absorbed standalone (Kadence
   `set_legacy_plugin_activation_error_notice()`; nonce-verified via
   `plugin-activation-error_{dir}/{file}`). Implemented with `ob_start()` on
   `admin_head-plugins.php` (`start_buffer()`) + a callback that `str_replace`s the
   default-domain string.

Kept intentionally minimal (plain `notice notice-warning is-dismissible` markup) so the library
stays dependency-free; a host that already uses `stellarwp/admin-notices` can ignore these and
render its own from the queued transient.

---

## Namespace / Version-Conflict Safety

The library ships under plain `Nexcess\SubPluginLoader\`. The multi-plugin, multi-version
collision problem is solved **consumer-side by Strauss** (the established StellarWP pattern —
admin-notices/assets do the same). README leads the install section with:

```
composer require stellarwp/sub-plugin-loader
```

immediately followed by the Strauss recommendation and a link to
`https://github.com/stellarwp/global-docs/blob/main/docs/strauss-setup.md`, plus a warning that
the host's `extra.strauss.constant_prefix` must **not** rewrite a sub-plugin's
`plugin_loaded_constant` — those are real, shared runtime constants (add them to Strauss
`exclude_from_copy` / exclude patterns, or keep them out of prefixed files).

---

## Testing

Codeception + `lucatume/wp-browser`, run through **slic** (StellarWP convention; no bare
`phpunit.xml`). `tests/unit/` mirrors `src/`. Key cases:

- **`Sub_Plugin`:** required-key validation throws `Config_Exception`; `is_enabled()` bool vs
  callable; `is_already_loaded()` reacts to `define()`; `is_standalone_plugin_active()` reflects
  a stubbed `is_plugin_active`; `are_dependencies_met()` gates on the `dependency_check` callable;
  `get_conflict_policy()` resolves a string, resolves a callable, and honors the
  `…/conflict_policy` filter (last wins).
- **`Loader`:** `require_once` happens exactly once; skipped when disabled / already-loaded /
  file missing / the `…/should_load` filter returns false; `boot()` is idempotent (double-boot
  wires hooks once). Use `UopzFunctions` from wp-browser (`setFunctionReturn()`; the trait's own
  `@after` undoes every override) to stub `is_plugin_active`, `deactivate_plugins`,
  `wp_safe_redirect`.
- **Container / rebinding:** with a di52 container binding a custom `Registrar_Interface`,
  `Notices_Interface`, or `Conflict\Resolver_Interface`, the resolve helper returns the bound
  instance and the trampolines delegate to it; with no container, the local defaults are used.
  (di52 is the dev-only container under test.)
- **`Conflict\Resolver`:** DEACTIVATE calls `deactivate_plugins` + queues the merge notice +
  computes a redirect; DEFER does nothing; NOTICE_ONLY queues a conflict notice without
  deactivating; a callable `conflict_policy` (ProPanel-style) selects the branch per sub-plugin.
  Never mock the `exit` after the redirect — stub `wp_safe_redirect` to throw `TestException`
  instead, so the test stops where production would (see `tests/README.md`).
- **`Activation`:** callback runs exactly once ever per slug; a second load does not re-run it;
  never runs without an `activation_callback`.
- **`Notices`:** transient round-trips the redirect; buffer rewrite replaces the default fatal
  string only for the matching, nonce-verified plugin.

CI: `.github/workflows/static-analysis.yml` (PHPStan level 5 via `composer test:analysis`) and
`tests-php.yml` (slic unit suite), copied from admin-notices.

---

## Verification (end-to-end)

1. **Unit/static:** `composer install`, `composer test:analysis` (PHPStan clean), `slic run unit`
   all green.
2. **Real WP smoke test** in a local site (`slic` / `wp-env`):
   - Throwaway host plugin that `Loader::register()`s a bundled copy of one real standalone
     (e.g. `give-recurring`) whose `bundled_plugin_file` `define()`s the `plugin_loaded_constant`.
   - **Fresh load:** standalone not installed → bundled copy loads, constant defined,
     `activation_callback` fires once (verify tables/option created); a second request does not
     re-run it.
   - **Conflict, DEACTIVATE:** install + activate the standalone, then load the host → the
     standalone is auto-deactivated, the merge notice shows, no fatal, and reloading the plugins
     page doesn't loop.
   - **Re-activation attempt:** try to re-activate the now-absorbed standalone from the plugins
     list → WP's fatal-activation text is replaced by the friendly `conflict_notice_message`.
   - **DEFER / NOTICE_ONLY:** standalone active with each policy → confirm it is NOT deactivated
     and (NOTICE_ONLY) a notice appears; the bundled copy stands down via the load guard.
   - **Toggle off:** `enabled` callable returns false → bundled copy does not load.
3. Confirm loading two sub-plugins that share no symbols in one request produces no fatals/notices.

---

## Implementation Milestones

1. **Skeleton + tooling** — composer.json, phpstan, codeception config, CI workflows,
   `.gitattributes`, README stub, GPL-2.0 LICENSE. (Copy structure from admin-notices.)
2. **Core + resolve helper** — `Config` (incl. optional `set_container`), `Conflict_Policy`,
   `Exceptions\Config_Exception`, `Sub_Plugin`, the four `Contracts\*_Interface` + default
   `Registrar`, and `Loader` (register/boot/load path, `resolve()` helper + collaborator accessors,
   `should_load` filter). Unit tests for the happy path + already-loaded/disabled/file-missing skips
   + container-bound vs local resolution.
3. **Conflict handling** — `Conflict\Resolver` (+ `Resolver_Interface`): deactivate/defer/notice_only
   + safe redirect; callable `conflict_policy` + `…/conflict_policy` filter. Tests with
   `UopzFunctions`-stubbed WP functions.
4. **Activation + Notices** — run-once `Activation` (+ `Activation_Interface`); self-contained
   `Notices` (+ `Notices_Interface`; transient queue + `plugins.php` buffer rewrite). Tests.
5. **README** — intent, `composer require`, Strauss recommendation, `Config`/`Loader` API,
   optional container wiring + rebinding a collaborator, the two per-sub-plugin extension seams
   (callable policy + filters), full config-key table, conflict-policy + lifecycle docs, worked
   Give-Recurring example.
6. **E2E verification** in a real WP install per the section above; tag `1.0.0`.

### Key reference files to consult while implementing
- `kadence-shop-kit/inc/Common/Features/Strategies/Plugin_Feature.php` (deactivate, redirect,
  activation-error rewrite)
- `kadence-shop-kit/inc/Common/Features/Models/Plugin_Feature.php` (predicates, active-detection)
- `sfwd-lms/src/Core/Modules/Course_Grid/Legacy/Loader.php` (load-guard constant,
  is_legacy_plugin_active)
- `admin-notices/src/AdminNotices.php` + `composer.json` (static-facade + repo conventions)
