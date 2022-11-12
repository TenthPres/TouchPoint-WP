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
Version:            0.0.20
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
