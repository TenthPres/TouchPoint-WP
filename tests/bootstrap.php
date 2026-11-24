<?php
/**
 * PHPUnit bootstrap.  The plugin's files refuse to load outside WordPress, so this defines just enough to let them
 * declare their classes.  No WordPress code is executed by the API surface tests.
 */

define('ABSPATH', __DIR__ . '/');
define('WPINC', 'wp-includes');
define('TOUCHPOINT_COMPOSER_ENABLED', true); // Normally defined by touchpoint-wp.php; the Composer autoloader is in use.

// Minimal stand-ins for the WordPress classes that plugin classes extend, so reflection can resolve their parents.
if (!class_exists('WP_User')) {
    class WP_User {}
}
if (!class_exists('WP_User_Query')) {
    class WP_User_Query {}
}

// Resolves plugin classes (PSR-4) so files can load in any order.
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/ApiSurface.php';
