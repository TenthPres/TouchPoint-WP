# Testing TouchPoint-WP

This document describes how to run and write tests for the TouchPoint-WP plugin.

## Running Tests

### Prerequisites

- PHP 8.0 or higher
- Composer

### Installation

First, install the development dependencies:

```bash
composer install
```

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

To run tests in a specific file:

```bash
./vendor/bin/phpunit tests/Unit/GeoTest.php
```

To run a specific test method:

```bash
./vendor/bin/phpunit --filter test_distance_calculation
```

### Code Coverage

To generate a code coverage report:

```bash
composer test-coverage
```

This will create an HTML coverage report in the `coverage/` directory. Open `coverage/index.html` in your browser to view the report.

## Writing Tests

### Test Structure

Tests are organized in the `tests/` directory:

- `tests/Unit/` - Unit tests for individual classes and methods
- `tests/Integration/` - Integration tests (if needed)
- `tests/TestCase.php` - Base test case class that all tests should extend
- `tests/bootstrap.php` - Bootstrap file that sets up the test environment

### Creating a New Test

1. Create a new test file in the appropriate directory (e.g., `tests/Unit/MyClassTest.php`)
2. Extend the `tp\TouchPointWP\Tests\TestCase` class
3. Add the `@covers` annotation to specify which class you're testing
4. Write test methods (must start with `test_` or use the `@test` annotation)

Example:

```php
<?php

namespace tp\TouchPointWP\Tests\Unit;

use tp\TouchPointWP\MyClass;
use tp\TouchPointWP\Tests\TestCase;

/**
 * @covers \tp\TouchPointWP\MyClass
 */
class MyClassTest extends TestCase
{
    public function test_my_method(): void
    {
        $instance = new MyClass();
        $result = $instance->myMethod();
        
        $this->assertSame('expected', $result);
    }
}
```

### Test Naming Conventions

- Test files should be named `{ClassName}Test.php`
- Test methods should be named `test_{method_name}_{scenario}` (e.g., `test_distance_calculation_same_point`)
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

Tests are automatically run on every push and pull request via GitHub Actions. The test workflow:

- Runs on PHP versions 8.0, 8.1, 8.2, and 8.3
- Validates composer.json
- Installs dependencies
- Runs the complete test suite
- Generates code coverage report (PHP 8.3 only)

You can view the test results in the "Actions" tab of the GitHub repository.

## Best Practices

1. **Write isolated tests** - Each test should be independent and not rely on other tests
2. **Test one thing per test** - Each test method should verify one specific behavior
3. **Use descriptive test names** - Test names should clearly explain what is being tested
4. **Mock external dependencies** - Use mocks or stubs for external services and WordPress functions
5. **Keep tests fast** - Unit tests should run quickly; avoid unnecessary setup or external calls
6. **Test edge cases** - Include tests for boundary conditions, error cases, and unusual inputs
7. **Maintain tests** - Update tests when you change code; failing tests should be fixed, not removed

## Troubleshooting

### Tests fail with "undefined constant" errors

Make sure all required constants are defined in `tests/bootstrap.php`.

### Tests fail with "undefined function" errors

WordPress functions may need to be mocked in `tests/bootstrap.php` or in individual test files.

### Can't run tests

Ensure you have installed dev dependencies:

```bash
composer install
```

If you've only installed production dependencies, add the dev dependencies:

```bash
composer install --dev
```
