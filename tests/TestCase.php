<?php
/**
 * Base test case for TouchPoint-WP tests
 *
 * @package TouchPointWP\Tests
 */

namespace tp\TouchPointWP\Tests;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Yoast\PHPUnitPolyfills\TestCases\TestCase as PolyfillsTestCase;

/**
 * Base test case class that all TouchPoint-WP tests should extend.
 * Provides common setup and utility methods for testing.
 * Uses Brain Monkey for WordPress function mocking.
 */
abstract class TestCase extends PolyfillsTestCase
{
    use MockeryPHPUnitIntegration;

    /**
     * Set up before each test.
     * Initializes Brain Monkey for WordPress function mocking.
     */
    protected function set_up(): void
    {
        parent::set_up();
        Monkey\setUp();
        
        // Set up common WordPress function defaults
        $this->setUpWordPressFunctions();
    }

    /**
     * Tear down after each test.
     * Tears down Brain Monkey.
     */
    protected function tear_down(): void
    {
        Monkey\tearDown();
        parent::tear_down();
    }

    /**
     * Set up common WordPress function defaults.
     * Can be overridden in individual tests as needed.
     */
    protected function setUpWordPressFunctions(): void
    {
        // Mock current_datetime to return a consistent DateTimeImmutable
        Monkey\Functions\when('current_datetime')->justReturn(
            new \DateTimeImmutable('2025-11-12 21:00:00', new \DateTimeZone('UTC'))
        );

        // Mock is_admin to return false by default
        Monkey\Functions\when('is_admin')->justReturn(false);

        // Mock current_user_can to return false by default
        Monkey\Functions\when('current_user_can')->justReturn(false);

        // Mock get_option to return default value
        Monkey\Functions\when('get_option')->alias(function ($option, $default = false) {
            return $default;
        });

        // Mock translation functions to return the text unchanged
        Monkey\Functions\when('__')->returnArg();
        Monkey\Functions\when('_e')->returnArg();
        Monkey\Functions\when('_x')->returnArg();

        // Mock escaping functions
        Monkey\Functions\when('esc_html')->alias(function ($text) {
            return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        });
        Monkey\Functions\when('esc_attr')->alias(function ($text) {
            return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        });
        Monkey\Functions\when('esc_url')->returnArg();

        // Mock sanitization functions
        Monkey\Functions\when('sanitize_text_field')->alias(function ($str) {
            return trim(strip_tags($str));
        });
        Monkey\Functions\when('wp_kses_post')->returnArg();

        // Mock apply_filters to return the value unchanged
        Monkey\Functions\when('apply_filters')->returnArg(2);
    }
}
