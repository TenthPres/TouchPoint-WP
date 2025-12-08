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
 * Uses Yoast PHPUnit Polyfills for compatibility with multiple PHPUnit versions.
 */
abstract class TestCase extends PolyfillsTestCase
{
    /**
     * Set up before each test.
     */
    protected function set_up(): void
    {
        parent::set_up();
        
        // Reset filters for each test
        global $_wp_filters;
        $_wp_filters = [];
    }

    /**
     * Tear down after each test.
     */
    protected function tear_down(): void
    {
        // Clean up filters
        global $_wp_filters;
        $_wp_filters = [];
        
        parent::tear_down();
    }
}
