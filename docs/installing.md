# Installing

```bash
composer require stellarwp/plugin-absorber
```

Requires PHP 7.4+ and WordPress 6.4+. The WordPress floor comes from the `wp_admin_notice_markup`
filter, which does not exist before 6.4; WordPress is not a Composer dependency, so it is stated
here rather than enforced in `require`.

## Strauss

Prefix this library with [Strauss](https://github.com/stellarwp/global-docs/blob/main/docs/strauss-setup.md).
Two or more plugins shipping different versions of it will collide otherwise.

> **Nothing may rewrite a sub-plugin's `plugin_loaded_constant`.** Those are real, shared runtime
> constants: the whole safety mechanism depends on the bundled copy and the standalone defining the
> *same* name. This library only ever reads such a name out of your config, so its own source is
> safe to prefix in full — but if your build also runs the bundled plugin's own files through
> Strauss, keep `extra.strauss.constant_prefix` away from them.
