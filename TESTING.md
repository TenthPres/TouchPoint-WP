# Testing TouchPoint-WP

This document describes how to run and write tests for the TouchPoint-WP plugin.

## Test Infrastructure

This project uses:
- **PHPUnit 9.6** for testing framework
- **Brain Monkey** for WordPress function and filter mocking
- **Mockery** for general mocking capabilities
- **Yoast PHPUnit Polyfills** for PHP 8.0+ compatibility

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

To run all tests (unit and integration):

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
- `tests/Integration/` - Integration tests that test multiple components working together
- `tests/TestCase.php` - Base test case class with Brain Monkey integration
- `tests/bootstrap.php` - Bootstrap file that sets up the test environment
- `tests/mocks/` - Mock implementations of WordPress classes

### Test Types

#### Unit Tests

Unit tests focus on testing individual methods and classes in isolation. They use Brain Monkey to mock WordPress functions.

Example unit test:

```php
<?php

namespace tp\TouchPointWP\Tests\Unit;

use Brain\Monkey;
use tp\TouchPointWP\MyClass;
use tp\TouchPointWP\Tests\TestCase;

/**
 * @covers \tp\TouchPointWP\MyClass
 */
class MyClassTest extends TestCase
{
    public function test_my_method(): void
    {
        // Mock WordPress function if needed
        Monkey\Functions\when('get_option')->justReturn('test_value');
        
        $instance = new MyClass();
        $result = $instance->myMethod();
        
        $this->assertSame('expected', $result);
    }
}
```

#### Integration Tests

Integration tests verify that multiple components work together correctly.

Example integration test:

```php
<?php

namespace tp\TouchPointWP\Tests\Integration;

use tp\TouchPointWP\ClassA;
use tp\TouchPointWP\ClassB;
use tp\TouchPointWP\Tests\TestCase;

/**
 * @covers \tp\TouchPointWP\ClassA
 * @covers \tp\TouchPointWP\ClassB
 */
class ClassABIntegrationTest extends TestCase
{
    public function test_classes_work_together(): void
    {
        $classA = new ClassA();
        $classB = new ClassB();
        
        $result = $classA->processWithB($classB);
        
        $this->assertTrue($result);
    }
}
```

### Mocking WordPress Functions with Brain Monkey

Brain Monkey provides elegant WordPress function mocking:

```php
use Brain\Monkey;

// Simple return value
Monkey\Functions\when('get_option')->justReturn('value');

// Return argument unchanged (useful for escaping functions)
Monkey\Functions\when('esc_html')->returnArg();

// Custom callback
Monkey\Functions\when('apply_filters')->alias(function($tag, $value) {
    return $value;
});

// Expect a function to be called
Monkey\Functions\expect('wp_enqueue_script')
    ->once()
    ->with('my-script', 'path/to/script.js');
```

### Creating a New Test

1. Create a new test file in the appropriate directory:
   - `tests/Unit/` for unit tests
   - `tests/Integration/` for integration tests
2. Extend the `tp\TouchPointWP\Tests\TestCase` class
3. Add the `@covers` annotation to specify which class(es) you're testing
4. Write test methods (must start with `test_` or use the `@test` annotation)
5. Use Brain Monkey to mock WordPress functions as needed

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
