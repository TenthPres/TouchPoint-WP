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
Plugin Name: TouchPoint WP
Plugin URI: https://github.com/tenthpres/touchpoint-wp
GitHub Plugin URI: https://github.com/tenthpres/touchpoint-wp
Description: Connect a church website with TouchPoint Church Management.
Version: 0.0.1
Author: James K
Author URI: https://github.com/jkrrv
License: AGPLv3+
Requires at least: 5.5
Tested up to: 5.5
Requires PHP: 7.2
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
	require_once "src/TouchPoint-WP/TouchPointWP.php";
	require_once "src/TouchPoint-WP/TouchPointWP_Settings.php";
}


// Returns the singleton so we can avoid using globals.
function TouchPoint_WP() {
	$instance = TouchPointWP::instance( __FILE__ );

	if ( is_null( $instance->settings ) ) {
		$instance->settings = TouchPointWP_Settings::instance( $instance );
	}

	return $instance;
}

TouchPoint_WP();
