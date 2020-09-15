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
Version:            0.0.1
Author:             James K
Author URI:         https://github.com/jkrrv
License:            AGPLv3+
Requires at least:  5.5
Tested up to:       5.5
Requires PHP:       7.4
*/

namespace tp\TouchPointWP;

// die if called directly.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// Load the Composer autoloader if it exists
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
} else {
	require_once __DIR__ . "src/TouchPoint-WP/TouchPointWP.php";
	require_once __DIR__ . "src/TouchPoint-WP/TouchPointWP_Settings.php";
}


TouchPointWP::init();
