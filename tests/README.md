# API surface tests

These tests fail when the plugin's public API changes, so that changes are deliberate rather than accidental.

## What counts as public API

Only what is deliberately offered to themes and other code. A class member being `public` or `protected` does not make it
API: many are public only so that other classes in the plugin can use them, and they are free to change.

Something is API when its docblock contains an `@api` tag. `tests/api-surface.json` is a committed snapshot of:

- **Classes, interfaces, and traits** tagged `@api` in `src/TouchPoint-WP`: kind, abstract/final, parent, interfaces, and
  traits. Tagging a class means the class itself is supported (instantiating it, extending it, calling its static entry
  points); it does not cover its members.
- **Constants, properties, and methods** tagged `@api`, each individually: visibility, static/abstract/final, parameter
  names, types, defaults, by-reference/variadic, and return types. A class whose members are tagged but which is not
  itself tagged is tracked for those members only.
- **Hooks**: the filters and actions the plugin provides (`apply_filters` / `do_action` using `HOOK_PREFIX` or `TOKEN`).
  These are all tracked, without tagging.
- **Templates**: files under `src/templates`, which themes may override. These are all tracked, without tagging.

Removing an `@api` tag is a change to the API, and the tests treat it as one.

## Marking something as API

Add `@api` to the docblock, after the description and other tags:

```php
/**
 * Get the Involvement that a post represents.
 *
 * @param WP_Post $post The post.
 *
 * @return Involvement
 * @api
 */
public static function fromPost(WP_Post $post): Involvement
```

Tag a member when a theme, a shortcode-style template, or the documentation in `docs/` tells people to use it, and
document it properly. New members are internal until someone tags them.

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
