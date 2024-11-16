<?php

/**
 * TouchPoint WP
 *
 * @author  James K
 * @license AGPLv3+
 * @link    https://github.com/TenthPres/TouchPoint-WP
 * @package TouchPointWP
 */

/*
Plugin Name:        TouchPoint WP
Plugin URI:         https://github.com/tenthpres/touchpoint-wp
Update URI:         https://github.com/tenthpres/touchpoint-wp
Description:        A WordPress Plugin for integrating with TouchPoint Church Management Software.
Version:            0.0.94
Author:             James K
Author URI:         https://github.com/jkrrv
License:            AGPLv3+
Text Domain:        TouchPoint-WP
Requires at least:  6.0
Tested up to:       6.7
Requires PHP:       8.0
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
	require_once __DIR__ . "/src/TouchPoint-WP/PostTypeCapable.php";
	require_once __DIR__ . "/src/TouchPoint-WP/RegistrationType.php";
	require_once __DIR__ . "/src/TouchPoint-WP/Geo.php";
	require_once __DIR__ . "/src/TouchPoint-WP/Utilities.php";
	require_once __DIR__ . "/src/TouchPoint-WP/Utilities/Cleanup.php";
	require_once __DIR__ . "/src/TouchPoint-WP/Utilities/Translation.php";
	require_once __DIR__ . "/src/TouchPoint-WP/Utilities/PersonArray.php";
	require_once __DIR__ . "/src/TouchPoint-WP/Utilities/StringableArray.php";
	require_once __DIR__ . "/src/TouchPoint-WP/Utilities/DateFormats.php";
	require_once __DIR__ . "/src/TouchPoint-WP/Utilities/DateTimeExtended.php";
	require_once __DIR__ . "/src/TouchPoint-WP/Utilities/Http.php";
	require_once __DIR__ . "/src/TouchPoint-WP/Taxonomies.php";
	require_once __DIR__ . "/src/TouchPoint-WP/hasGeo.php";

	require_once __DIR__ . "/src/TouchPoint-WP/Person.php";
	require_once __DIR__ . "/src/TouchPoint-WP/Meeting.php";
	require_once __DIR__ . "/src/TouchPoint-WP/CalendarGrid.php";
	require_once __DIR__ . "/src/TouchPoint-WP/Involvement.php";
	require_once __DIR__ . "/src/TouchPoint-WP/Location.php";
	require_once __DIR__ . "/src/TouchPoint-WP/Report.php";
}

/*** Load (set action hooks, etc.) ***/
TouchPointWP::load(__FILE__);
