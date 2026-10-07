# Testing TouchPoint-WP

This document describes how to run and write tests for the TouchPoint-WP plugin.

## Test Infrastructure

This project uses:
- **PHPUnit 9.6** as the testing framework
- **Yoast PHPUnit Polyfills**, so tests can use the same `set_up()` and `tear_down()` methods across PHPUnit versions
- **A small set of WordPress functions** implemented in `tests/bootstrap.php`, including the filter system

WordPress itself is **not** loaded.  The bootstrap defines just the WordPress functions the tests need, which keeps the
suite fast and means it runs anywhere PHP and Composer do.  The consequence is that the tests cover code that doesn't
depend on a real WordPress: utilities, value objects, date and schedule logic, and the way filters are applied.  Code
that reads or writes posts, post meta, or options isn't covered yet.

## Running Tests

### Prerequisites

- PHP 8.0 or higher
- Composer

### Installation

Install the dependencies, including the development ones:

```bash
composer install
```

(If Composer isn't installed globally, use `php composer.phar install`, and likewise for the commands below.)

### Running the Test Suite

To run all tests:

```bash
composer test
```

Or directly with PHPUnit:

```bash
./vendor/bin/phpunit
```

### Running Specific Tests

To run only unit tests:

```bash
./vendor/bin/phpunit tests/Unit
```

To run only integration tests:

```bash
./vendor/bin/phpunit tests/Integration
```

To run tests in a specific file:

```bash
./vendor/bin/phpunit tests/Unit/Utilities/Geo_Test.php
```

To run a specific test method:

```bash
./vendor/bin/phpunit --filter test_distance_calculation
```

To skip the tests for known problems (see below):

```bash
./vendor/bin/phpunit --exclude-group known-issue
```

#### Known issues

A test that describes how the code should behave, but that fails because it doesn't yet, is marked `@group known-issue`.
That keeps the problem visible, and the test ready for whoever fixes it, without hiding a real failure behind a skipped
or watered-down test.  Remove the group when the test passes.

### Code Coverage

To generate a code coverage report:

```bash
composer test-coverage
```

This creates an HTML coverage report in the `coverage/` directory.  Open `coverage/index.html` in your browser to view
it.  Coverage requires Xdebug.  The configuration turns on path coverage, which PCOV doesn't support.

## Writing Tests

### Test Structure

Tests are organized in the `tests/` directory:

- `tests/Unit/` - Unit tests for individual classes and methods
- `tests/Integration/` - Integration tests that exercise WordPress filters together with the code that applies them
- `tests/mocks/` - Minimal stand-ins for WordPress classes, such as `WP_Error`, `WP_Post`, and `WP_User`
- `tests/Support/` - Helpers shared by tests.  For example, `MeetingFixtures` builds meetings and involvements shaped like the TouchPoint API's, so the meeting logic can be tested without the API or WordPress
- `tests/TestCase.php` - Base test case class
- `tests/bootstrap.php` - Bootstrap file with the WordPress function implementations

The folders under `tests/Unit/` mirror the namespaces under `src/TouchPoint-WP/`.  For example, a test for
`tp\TouchPointWP\Utilities\StringableArray` is `tests/Unit/Utilities/StringableArray_Test.php`, in the namespace
`tp\TouchPointWP\Tests\Unit\Utilities`.

### Test Types

#### Unit Tests

Unit tests focus on testing individual methods and classes in isolation.

Example unit test:

```php
<?php

namespace tp\TouchPointWP\Tests\Unit;

use tp\TouchPointWP\MyClass;
use tp\TouchPointWP\Tests\TestCase;

/**
 * @covers \tp\TouchPointWP\MyClass
 */
class MyClass_Test extends TestCase
{
    public function test_myMethod_basic(): void
    {
        $instance = new MyClass();
        $result = $instance->myMethod();

        $this->assertSame('expected', $result);
    }
}
```

#### Integration Tests

Integration tests verify that WordPress filters change the behavior of the code that applies them.  The test
environment provides working implementations of `add_filter`, `apply_filters`, and `remove_all_filters`.

Example integration test:

```php
<?php

namespace tp\TouchPointWP\Tests\Integration;

use tp\TouchPointWP\Utilities;
use tp\TouchPointWP\Tests\TestCase;

/**
 * @covers \tp\TouchPointWP\Utilities
 */
class ColorsFilters_Test extends TestCase
{
    public function test_custom_color_filter(): void
    {
        add_filter('tp_custom_color_function', function($current, $itemName, $setName) {
            if ($itemName === 'PA') {
                return '#FF0000'; // Red for Pennsylvania
            }
            return $current;
        }, 10, 3);

        $color = Utilities::getColorFor('PA', 'States');

        $this->assertSame('#FF0000', $color);
    }
}
```

### The Test Environment

**Filters.**  The bootstrap implements WordPress's filter system:

- `add_filter($hook, $callback, $priority, $accepted_args)` - Add a filter
- `apply_filters($hook, $value, ...$args)` - Apply filters to a value
- `remove_all_filters($hook, $priority)` - Remove all filters from a hook

These work like WordPress's, including priority ordering, multiple filters on one hook, extra arguments, and chaining
(each filter receives the output of the previous one).  Actions (`add_action` and `do_action`) and `remove_filter` aren't
implemented.  `TestCase` clears all filters before and after every test, so a test doesn't need to clean up its own.

**Other WordPress functions.**  A handful of others are stubbed, such as `__()`, `esc_html()`, `get_option()`, and
`wp_date()`.  They're simple stand-ins, not WordPress's real behavior.  If the code you're testing calls a WordPress
function that isn't defined, add a stub to `tests/bootstrap.php`.  Keep it as simple as the test allows.

**The current time, time zone, and options.**  Unless a test says otherwise, the current time is 2025-11-12 21:00 UTC (a
Wednesday), the site's time zone is UTC, and the date and time formats are WordPress's defaults ("F j, Y" and "g:i a").
A test can change them with these methods of `TestCase`, and they're put back afterward:

- `setNow('2025-12-31 12:00')` sets the current time, and `setNow($time, 'America/New_York')` sets the time zone too
- `setTimezone('America/New_York')` sets the site's time zone
- `setOption('time_format', 'H:i')` sets any option that `get_option()` returns

`TestCase` also clears what `Utilities` caches (the current time and the client's IP address) before and after every
test, so a test doesn't have to.

**Protected code and settings.**  Many of the plugin's helpers are protected static methods.  `TestCase` has
`callStatic($class, $method, ...$arguments)` and `setStatic($class, $property, $value)` for calling and setting them with
reflection.  `useGroupingSettings([...])` provides the Meeting Grouping settings without reading them from WordPress, and
`setTakenUsernames([...])` says which WordPress usernames already exist.
(Static state that a test sets itself is the test's to put back.)

**Test data.**  `tests/Support/MeetingFixtures.php` builds meetings and involvements shaped like the TouchPoint API's.

**Logging.**  The plugin logs exceptions with `error_log()`.  The bootstrap sends that to the null device, so it doesn't
clutter the test output.

### Creating a New Test

1. Create a new test file in the appropriate directory:
   - `tests/Unit/` for unit tests
   - `tests/Integration/` for integration tests
2. Extend the `tp\TouchPointWP\Tests\TestCase` class
3. Add the `@covers` annotation to specify which class(es) you're testing
4. Write test methods (must start with `test_` or use the `@test` annotation)
5. If the code calls a WordPress function that isn't defined, add a stub for it to `tests/bootstrap.php`

### Test Naming Conventions

- Test files and classes should be named `{ClassName}_Test`, such as `Geo_Test.php`
- Test methods should be named `test_{methodName}_{scenario}` (e.g., `test_distance_samePoint`)
- Use descriptive names that explain what is being tested

### Assertions

PHPUnit provides many assertion methods. Common ones include:

- `assertSame($expected, $actual)` - Strict equality check
- `assertEquals($expected, $actual)` - Loose equality check
- `assertTrue($condition)` - Check if condition is true
- `assertFalse($condition)` - Check if condition is false
- `assertNull($value)` - Check if value is null
- `assertInstanceOf($class, $object)` - Check object type
- `assertEqualsWithDelta($expected, $actual, $delta)` - Check numeric equality with tolerance

See the [PHPUnit documentation](https://phpunit.readthedocs.io/) for more assertion methods.

## Continuous Integration

Tests run automatically on every push and pull request via GitHub Actions (`.github/workflows/tests.yml`).  The
workflow:

- Runs on PHP 8.0, 8.1, 8.2, 8.3, 8.4, and 8.5.  The versions run independently, so one failing doesn't cancel the others.
- Validates `composer.json`
- Runs `composer update` rather than `composer install`, so each PHP version gets dependencies it can use
- Runs the complete test suite with `composer test`
- Measures code coverage on PHP 8.4 only, prints the overall percentage in the run, and uploads the report as an artifact
- Submits the coverage report to [Coveralls](https://coveralls.io) from that same job.  The repository has to be enabled
  at coveralls.io first.  A problem submitting doesn't fail the run, so check the step's log if results don't appear.

You can view the test results in the "Actions" tab of the GitHub repository.

## Best Practices

1. **Write isolated tests** - Each test should be independent and not rely on other tests
2. **Test one thing per test** - Each test method should verify one specific behavior
3. **Use descriptive test names** - Test names should clearly explain what is being tested
4. **Keep WordPress out of it** - Stub the WordPress functions your code needs in `tests/bootstrap.php` instead of loading WordPress
5. **Keep tests fast** - Unit tests should run quickly; avoid unnecessary setup or external calls
6. **Test edge cases** - Include tests for boundary conditions, error cases, and unusual inputs
7. **Watch static state** - Static properties and caches persist between tests; reset them in `set_up()`
8. **Maintain tests** - Update tests when you change code; failing tests should be fixed, not removed

## Troubleshooting

### Tests fail with "undefined constant" errors

Make sure all required constants are defined in `tests/bootstrap.php`.

### Tests fail with "undefined function" errors

The code is calling a WordPress function that the bootstrap doesn't provide.  Add a stub to `tests/bootstrap.php`.

### Can't run tests

Ensure you have installed the development dependencies.  Composer installs them by default, so this only happens if
the dependencies were last installed with `--no-dev` (for example, by a build).  Run:

```bash
composer install
```
