<?php

/**
 * TouchPoint WP
 *
 * @author  James K
 * @license AGPLv3+
 * @link    https://github.com/TenthPres/TouchPoint-WP
 * @package TouchPoint-WP
 */

/*
Plugin Name:        TouchPoint WP
Plugin URI:         https://github.com/tenthpres/touchpoint-wp
Update URI:         https://github.com/tenthpres/touchpoint-wp
Description:        A WordPress Plugin for integrating with TouchPoint Church Management Software.
Version:            0.0.33
Author:             James K
Author URI:         https://github.com/jkrrv
License:            AGPLv3+
Text Domain:        TouchPoint-WP
Requires at least:  5.5
Tested up to:       6.2
Requires PHP:       7.4
Release Asset:      true
*/

namespace tp\TouchPointWP;

// die if called directly.
if ( ! defined('WPINC')) {
    die;
}

define("TOUCHPOINT_COMPOSER_ENABLED", file_exists(__DIR__ . '/vendor/autoload.php'));

/*** Load everything **/
if (TOUCHPOINT_COMPOSER_ENABLED) {
    /** @noinspection PhpIncludeInspection
     *  @noinspection RedundantSuppression
     */
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    require_once __DIR__ . "/src/TouchPoint-WP/TouchPointWP_Exception.php";
    require_once __DIR__ . "/src/TouchPoint-WP/TouchPointWP_WPError.php";
    require_once __DIR__ . "/src/TouchPoint-WP/TouchPointWP.php";
    require_once __DIR__ . "/src/TouchPoint-WP/TouchPointWP_Settings.php";

    require_once __DIR__ . "/src/TouchPoint-WP/api.php";
    require_once __DIR__ . "/src/TouchPoint-WP/module.php";
    require_once __DIR__ . "/src/TouchPoint-WP/Utilities/Cleanup.php";
    require_once __DIR__ . "/src/TouchPoint-WP/Utilities/Geo.php";
    require_once __DIR__ . "/src/TouchPoint-WP/Utilities/PersonArray.php";
    require_once __DIR__ . "/src/TouchPoint-WP/Utilities/Http.php";
    require_once __DIR__ . "/src/TouchPoint-WP/geo.php";

    require_once __DIR__ . "/src/TouchPoint-WP/Person.php";
    require_once __DIR__ . "/src/TouchPoint-WP/Involvement.php";
    require_once __DIR__ . "/src/TouchPoint-WP/Location.php";
    require_once __DIR__ . "/src/TouchPoint-WP/Report.php";
}

/*** Load (set action hooks, etc.) ***/
TouchPointWP::load(__FILE__);
