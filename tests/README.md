# API surface tests

These tests fail when the plugin's public API changes, so that changes are deliberate rather than accidental.

## What counts as public API

`tests/api-surface.json` is a committed snapshot of:

- **Classes, interfaces, and traits** in `src/TouchPoint-WP`: kind, abstract/final, parent, interfaces, traits, and every
  `public`/`protected` constant, property, and method (static/abstract/final, parameter names, types, defaults,
  by-reference/variadic, and return types).
- **Hooks**: the filters and actions the plugin provides (`apply_filters` / `do_action` using `HOOK_PREFIX` or `TOKEN`).
- **Templates**: files under `src/templates`, which themes may override.

Private members are ignored. Hooks are recorded by the name expression as written in the source.

## Running

```powershell
composer install
composer test
```

On failure, PHPUnit shows a diff of the snapshot against the current code, per class.

## When a change is intentional

1. Update the relevant docs (`docs/`) and docblocks.
2. Regenerate the snapshot, then review the diff in `tests/api-surface.json`:

   ```powershell
   composer update-api-snapshot
   ```

3. Commit the snapshot with the change that caused it.

## Notes

- WordPress is not loaded. `tests/bootstrap.php` defines the few constants and stub classes (`WP_User`,
  `WP_User_Query`) needed for the plugin's files to declare their classes.
- Generate the snapshot with the PHP version CI uses (see `.github/workflows/tests.yml`); reflection output can differ
  slightly between PHP versions.
