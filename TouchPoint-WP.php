<?php

/**
 * TouchPoint WP
 *
 * @author  James K
 * @license AGPLv3+
 * @link    https://github.com/tenthpres/touchpoint-wp
 * @package touchpoint-wp
 */

/*
Plugin Name:        TouchPoint WP
Plugin URI:         https://github.com/tenthpres/touchpoint-wp
GitHub Plugin URI:  https://github.com/tenthpres/touchpoint-wp
Description:        A WordPress Plugin for integrating with TouchPoint Church Management Software.
Version:            0.0.12
Author:             James K
Author URI:         https://github.com/jkrrv
License:            AGPLv3+
Requires at least:  5.5
Tested up to:       5.9.3
Requires PHP:       7.4
*/

namespace tp\TouchPointWP;

// die if called directly.
if ( ! defined('WPINC')) {
    die;
}

/*** Utility functions ***/

if ( ! function_exists('com_create_guid')) {
    /**
     * Generates a Microsoft-friendly globally unique identifier ( Guid ).
     *
     * @deprecated TODO at least move to Utils
     *
     * @return string A new random globally unique identifier.
     */
    function com_create_guid(): string
    {
        mt_srand(( double )microtime() * 10000);
        $char   = strtoupper(md5(uniqid(rand(), true)));
        $hyphen = chr(45); // "-"

        return chr(123) // "{"
               . substr($char, 0, 8) . $hyphen
               . substr($char, 8, 4) . $hyphen
               . substr($char, 12, 4) . $hyphen
               . substr($char, 16, 4) . $hyphen
               . substr($char, 20, 12)
               . chr(125); // "}"
    }
}


if ( ! function_exists('getallheaders')) {
    function getallheaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }

        return $headers;
    }
}

define("TOUCHPOINT_COMPOSER_ENABLED", file_exists(__DIR__ . '/vendor/autoload.php'));

/*** Load everything **/
if (TOUCHPOINT_COMPOSER_ENABLED) {
    /** @noinspection PhpIncludeInspection */
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    require_once __DIR__ . "/src/TouchPoint-WP/TouchPointWP_Exception.php";
    require_once __DIR__ . "/src/TouchPoint-WP/TouchPointWP_WPError.php";
    require_once __DIR__ . "/src/TouchPoint-WP/TouchPointWP.php";
    require_once __DIR__ . "/src/TouchPoint-WP/TouchPointWP_Settings.php";

    require_once __DIR__ . "/src/TouchPoint-WP/api.php";
    require_once __DIR__ . "/src/TouchPoint-WP/Utilities/Cleanup.php";

    require_once __DIR__ . "/src/TouchPoint-WP/Person.php";
    require_once __DIR__ . "/src/TouchPoint-WP/Involvement.php";
}

/*** Load (set action hooks, etc.) ***/
TouchPointWP::load(__FILE__);
