<?php
/**
 * Bootstrap for the tests that run within WordPress.
 *
 * WordPress's own test library (from the wp-phpunit package) loads WordPress, with a database, and the plugin is loaded
 * the way WordPress would load it.  The tests in tests/Unit don't use this file; they run without WordPress, using the
 * stand-ins in tests/bootstrap.php, and the two can't be run in the same process.
 *
 * See TESTING.md for how to set up the database.
 *
 * @package TouchPointWP\Tests
 */

$tpRoot = dirname(__DIR__, 2);

require_once $tpRoot . '/vendor/autoload.php';  // Also sets WP_PHPUNIT__DIR.

if ( ! defined('WP_TESTS_PHPUNIT_POLYFILLS_PATH')) {
	define('WP_TESTS_PHPUNIT_POLYFILLS_PATH', $tpRoot . '/vendor/yoast/phpunit-polyfills');
}
define('WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php');

$tpWpTestsDir = getenv('WP_PHPUNIT__DIR');
if ( ! $tpWpTestsDir || ! file_exists($tpWpTestsDir . '/includes/functions.php')) {
	fwrite(STDERR, "WordPress's test library wasn't found.  Run `composer install`.\n");
	exit(1);
}

// Give access to tests_add_filter().
require_once $tpWpTestsDir . '/includes/functions.php';

// Load the plugin the way WordPress would, before WordPress finishes starting up.
tests_add_filter(
	'muplugins_loaded',
	static function () use ($tpRoot) {
		require $tpRoot . '/touchpoint-wp.php';
	}
);

// Start WordPress.
require $tpWpTestsDir . '/includes/bootstrap.php';
