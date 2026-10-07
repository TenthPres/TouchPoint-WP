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

// The plugin logs exceptions with error_log(), which would otherwise be printed into the test output.
ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');

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

// Mock other WordPress functions used in tests
if (!function_exists('is_admin')) {
    function is_admin() {
        return false;
    }
}

// Dates and times work in UTC unless a test sets the site's time zone (see TestCase::setTimezone()).
date_default_timezone_set('UTC');

if (!function_exists('wp_timezone')) {
    function wp_timezone() {
        $zone = $GLOBALS['_wp_options']['timezone_string'] ?? '';
        return new \DateTimeZone($zone !== '' ? $zone : 'UTC');
    }
}

// The current time is fixed at 2025-11-12 21:00 UTC unless a test sets it (see TestCase::setNow()).
if (!function_exists('current_datetime')) {
    function current_datetime() {
        return new \DateTimeImmutable($GLOBALS['_wp_now'] ?? '2025-11-12 21:00:00', wp_timezone());
    }
}

if (!function_exists('wp_date')) {
    function wp_date($format, $timestamp = null, $timezone = null) {
        if ($timestamp === null) {
            $timestamp = time();
        }
        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone($timezone ?? wp_timezone())->format($format);
    }
}

if (!function_exists('date_i18n')) {
    function date_i18n($format, $timestamp = null) {
        if ($timestamp === null) {
            $timestamp = time();
        }
        return date($format, $timestamp);
    }
}

// Options are whatever a test sets (see TestCase::setOption()), with WordPress's defaults for the formats it has.
if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        $defaults = ['time_format' => 'g:i a', 'date_format' => 'F j, Y', 'timezone_string' => ''];
        return $GLOBALS['_wp_options'][$option] ?? $defaults[$option] ?? $default;
    }
}

if (!function_exists('__')) {
    function __($text, $domain = 'default') {
        return $text;
    }
}

if (!function_exists('_x')) {
    function _x($text, $context, $domain = 'default') {
        return $text;
    }
}

if (!function_exists('wp_sprintf')) {
    function wp_sprintf($pattern, ...$args) {
        return sprintf($pattern, ...$args);
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_url')) {
    function esc_url($url) {
        return $url;
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return trim(strip_tags($str));
    }
}

if (!function_exists('wp_kses_post')) {
    function wp_kses_post($data) {
        return $data;
    }
}

// Usernames that are already taken (see TestCase::setTakenUsernames()).  WordPress returns the user's ID, or false.
if (!function_exists('username_exists')) {
    function username_exists($username) {
        return in_array($username, $GLOBALS['_wp_usernames'] ?? [], true) ? 1 : false;
    }
}

// Accented letters are replaced with plain ones.  This only knows the common ones, where WordPress knows them all.
if (!function_exists('remove_accents')) {
    function remove_accents($text) {
        return strtr($text, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'Á' => 'A', 'À' => 'A', 'Ä' => 'A',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'É' => 'E', 'È' => 'E',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'Í' => 'I',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'Ó' => 'O', 'Ö' => 'O',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'Ú' => 'U', 'Ü' => 'U',
            'ñ' => 'n', 'Ñ' => 'N', 'ç' => 'c', 'Ç' => 'C',
        ]);
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can($capability) {
        return false;
    }
}

// Load WordPress class mocks
require_once __DIR__ . '/mocks/WP_Error.php';
require_once __DIR__ . '/mocks/WP_Post.php';
require_once __DIR__ . '/mocks/WP_User.php';

// Autoload Yoast PHPUnit Polyfills
require_once dirname(__DIR__) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

