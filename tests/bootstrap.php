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

// Composer autoloader must be loaded before anything else
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Define WordPress constants
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

// Mock WordPress filter/action functions for integration testing
if (!function_exists('add_filter')) {
    function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
        global $_wp_filters;
        if (!isset($_wp_filters)) {
            $_wp_filters = [];
        }
        if (!isset($_wp_filters[$hook])) {
            $_wp_filters[$hook] = [];
        }
        $_wp_filters[$hook][] = [
            'callback' => $callback,
            'priority' => $priority,
            'accepted_args' => $accepted_args
        ];
        return true;
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($hook, ...$args) {
        global $_wp_filters;
        if (!isset($_wp_filters) || !isset($_wp_filters[$hook])) {
            return $args[0] ?? null;
        }
        
        $value = $args[0] ?? null;
        
        // Sort by priority
        usort($_wp_filters[$hook], function($a, $b) {
            return $a['priority'] <=> $b['priority'];
        });
        
        foreach ($_wp_filters[$hook] as $filter) {
            $callback_args = array_slice($args, 0, $filter['accepted_args']);
            $value = call_user_func_array($filter['callback'], $callback_args);
            $args[0] = $value; // Update first arg for next filter
        }
        
        return $value;
    }
}

if (!function_exists('remove_all_filters')) {
    function remove_all_filters($hook, $priority = false) {
        global $_wp_filters;
        if (!isset($_wp_filters)) {
            return true;
        }
        if ($priority === false) {
            unset($_wp_filters[$hook]);
        } else {
            if (isset($_wp_filters[$hook])) {
                $_wp_filters[$hook] = array_filter($_wp_filters[$hook], function($filter) use ($priority) {
                    return $filter['priority'] != $priority;
                });
            }
        }
        return true;
    }
}

// Autoload Yoast PHPUnit Polyfills
require_once dirname(__DIR__) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

