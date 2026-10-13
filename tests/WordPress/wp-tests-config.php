<?php
/**
 * Configuration for WordPress's test library.  The database settings come from environment variables, so the same
 * files work on a developer's machine, in WSL, and on GitHub Actions.
 *
 * WARNING: WordPress's test library empties the tables of the database it's given.  Never point this at a database
 * that has anything in it that you want to keep.
 *
 * Environment variables (all optional):
 *   TP_TESTS_DB_HOST      Default 127.0.0.1.  May include a port, like 127.0.0.1:3307.
 *   TP_TESTS_DB_NAME      Default touchpoint_wp_tests.
 *   TP_TESTS_DB_USER      Default root.
 *   TP_TESTS_DB_PASSWORD  Default empty.
 *
 * @package TouchPointWP\Tests
 */

/**
 * Get an environment variable, or a default if it isn't set.
 *
 * @param string $name
 * @param string $default
 *
 * @return string
 */
$tp_env = static function (string $name, string $default): string {
	$value = getenv($name);

	return $value === false ? $default : $value;
};

// The copy of WordPress that's installed by Composer.
define('ABSPATH', dirname(__DIR__, 2) . '/vendor/johnpbloch/wordpress-core/');

define('DB_HOST', $tp_env('TP_TESTS_DB_HOST', '127.0.0.1'));
define('DB_NAME', $tp_env('TP_TESTS_DB_NAME', 'touchpoint_wp_tests'));
define('DB_USER', $tp_env('TP_TESTS_DB_USER', 'root'));
define('DB_PASSWORD', $tp_env('TP_TESTS_DB_PASSWORD', ''));
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

define('WP_DEBUG', true);
define('WP_TESTS_DOMAIN', 'example.org');
define('WP_TESTS_EMAIL', 'admin@example.org');
define('WP_TESTS_TITLE', 'TouchPoint-WP Test Site');
define('WP_PHP_BINARY', 'php');
define('WPLANG', '');

$table_prefix = 'wptests_';
