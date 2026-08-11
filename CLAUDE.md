# Plugin Absorber — working notes

A dependency-free StellarWP library that loads bundled ("absorbed") WordPress plugins inside a host
plugin without re-declaration fatals. Namespace `Nexcess\PluginAbsorber`, package
`stellarwp/plugin-absorber`.

Human-facing docs live in `README.md` and `docs/`. Keep them short and keep rationale here or in
code comments — do not grow the README back.

## Layout

| Path | What |
|---|---|
| `src/Config.php` | Static facade: hook prefix + optional container. |
| `src/Sub_Plugin.php` | Value object; validates config and answers everything config alone decides. |
| `src/Conflict_Policy.php` | The three policy constants, `default()`, `is_valid()`. |
| `src/Plugin_State.php` | The only file that touches WordPress plugin functions. |
| `src/Contracts/`, `src/Exceptions/` | `Plugin_State_Interface`, `Config_Exception`. |
| `engineering-plan.md` | The full design, including parts not built yet (`Loader`, `Conflict\Resolver`, `Notices`, `Activation`). |
| `docs/superpowers/` | Original spec and plan. |

The registration/boot facade (`Loader`) is not implemented yet; `engineering-plan.md` is the
contract to build it against.

## Commands

```bash
composer test:unit      # slic run unit
composer test:analysis  # phpstan, level 9
```

PHPStan runs at **level 9** over `src` and `tests`, with `treatPhpDocTypesAsCertain: false` —
runtime type guards must survive even where the PHPDoc says they cannot fail. PHP floor is 7.4,
pinned in `config.platform`; do not use 8.x syntax (no enums, no promoted properties, no `mixed`
type declarations).

## Conventions

- Class names are `Snake_Case` (`Sub_Plugin`, `Conflict_Policy`). Methods are fully spelled out.
- WordPress coding standards: tabs, Yoda-free, spaces inside parens.
- Every class member gets a docblock with `@since 1.0.0`.
- Member order is public, then protected, then private. No private helper above the public API.
- Comments describe behaviour, never plan items or task numbers — the code outlives the plan.
- Comments earn their place by explaining *why*, especially where a plausible alternative is wrong.
- Tests share fixtures through `tests/_support/Traits/WithSubPlugins.php`, and use generator data
  providers.

## Invariants — do not "simplify" these away

- **The guard constant and the standalone basename are two separate keys.** No constant does double
  duty as both a load guard and a path resolver.
- **String-only config keys reject callables.** `standalone_plugin_basename`, `conflict_policy`,
  `conflict_notice_message`, `dependency_notice_message` throw `Config_Exception` on a non-string.
  A string function name is indistinguishable from a string value, so honouring both would make the
  result depend on what else the site loaded. Runtime decisions belong in the filters.
- **Callable-only keys reject non-callables at registration**, not at read time — otherwise "not
  configured" and "configured but uncallable" collapse into the same answer, and a typo'd
  `dependency_check` reports dependencies met.
- **Required keys must be non-empty strings**, checked with `is_string()` rather than truthiness: an
  array passes truthiness and then casts to `"Array"`, which every sub-plugin with the same mistake
  would share as its registry key.
- **`get_conflict_policy()` does not validate its result.** A filter may return anything; rejecting
  it there would hide the override. Callers that dispatch on it use `Conflict_Policy::is_valid()`
  and must not treat an unrecognised value as consent to deactivate.
- **Filters run last** — after the configured value and any fallback — which is what makes deferred
  translation work. A non-scalar filter return becomes `''`, never a fatal cast.
- **`deactivate_plugins()` is called silent, with no `$network_wide` argument.** Silent because a
  `flush_rewrite_rules()` in the standalone's deactivation hook at `plugins_loaded` 404s the site.
  The `null` default takes both the network and blog branches; a computed `true` strands an entry.
- **`Plugin_State::load_plugin_functions()` guards on `deactivate_plugins()`**, not
  `is_plugin_active()` — the latter is a common third-party shim.
- **Strauss must not rewrite `plugin_loaded_constant` values.** They are shared runtime constants;
  prefixing them defeats the entire mechanism.
