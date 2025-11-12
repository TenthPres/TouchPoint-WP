<?php
/**
 * Base test case for TouchPoint-WP tests
 *
 * @package TouchPointWP\Tests
 */

namespace tp\TouchPointWP\Tests;

use Yoast\PHPUnitPolyfills\TestCases\TestCase as PolyfillsTestCase;

/**
 * Base test case class that all TouchPoint-WP tests should extend.
 * Provides common setup and utility methods for testing.
 */
abstract class TestCase extends PolyfillsTestCase
{
    /**
     * Set up before each test.
     */
    protected function set_up(): void
    {
        parent::set_up();
        // Additional setup can be added here
    }

    /**
     * Tear down after each test.
     */
    protected function tear_down(): void
    {
        parent::tear_down();
        // Additional teardown can be added here
    }
}
