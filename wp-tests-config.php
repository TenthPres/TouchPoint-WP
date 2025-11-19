<?php
/**
 * WordPress test config file
 *
 * @package TouchPointWP
 */

// Test database settings - using in-memory SQLite for tests
if (!defined('DB_NAME')) {
    define('DB_NAME', ':memory:');
}
if (!defined('DB_USER')) {
    define('DB_USER', '');
}
if (!defined('DB_PASSWORD')) {
    define('DB_PASSWORD', '');
}
if (!defined('DB_HOST')) {
    define('DB_HOST', '');
}
if (!defined('DB_CHARSET')) {
    define('DB_CHARSET', 'utf8');
}
if (!defined('DB_COLLATE')) {
    define('DB_COLLATE', '');
}

if (!defined('WP_TESTS_DOMAIN')) {
    define('WP_TESTS_DOMAIN', 'example.org');
}
if (!defined('WP_TESTS_EMAIL')) {
    define('WP_TESTS_EMAIL', 'admin@example.org');
}
if (!defined('WP_TESTS_TITLE')) {
    define('WP_TESTS_TITLE', 'Test Blog');
}
if (!defined('WP_PHP_BINARY')) {
    define('WP_PHP_BINARY', 'php');
}
if (!defined('WPLANG')) {
    define('WPLANG', '');
}

$table_prefix = 'wptests_';


