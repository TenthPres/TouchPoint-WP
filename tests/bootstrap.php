<?php
/**
 * PHPUnit bootstrap file for TouchPoint-WP plugin tests
 *
 * @package TouchPointWP
 */

// Define test environment
define('TOUCHPOINT_RUNNING_TESTS', true);
define('TOUCHPOINT_COMPOSER_ENABLED', true);

ini_set('memory_limit', '512M');

// Load Composer autoloader
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Define WordPress constants that are commonly used
if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}

if (!defined('WPINC')) {
    define('WPINC', 'wp-includes');
}

// Define WordPress time constants
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

if (!defined('WEEK_IN_SECONDS')) {
    define('WEEK_IN_SECONDS', 604800);
}

if (!defined('MONTH_IN_SECONDS')) {
    define('MONTH_IN_SECONDS', 2592000);
}

if (!defined('YEAR_IN_SECONDS')) {
    define('YEAR_IN_SECONDS', 31536000);
}

// Load WordPress class mocks
require_once __DIR__ . '/mocks/WP_Error.php';

// Autoload Yoast PHPUnit Polyfills
require_once dirname(__DIR__) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

