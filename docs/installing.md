# Installing

```bash
composer require stellarwp/plugin-absorber
```

Requires PHP 7.4+ and WordPress 6.4+.

## Strauss

Prefix this library with [Strauss](https://github.com/stellarwp/global-docs/blob/main/docs/strauss-setup.md).
Two or more plugins shipping different versions of it will collide otherwise.

> **Do not let `extra.strauss.constant_prefix` rewrite a sub-plugin's `plugin_loaded_constant`.**
> Those are real, shared runtime constants — the whole safety mechanism depends on the bundled
> copy and the standalone defining the *same* name. Add them to `exclude_from_copy`.
