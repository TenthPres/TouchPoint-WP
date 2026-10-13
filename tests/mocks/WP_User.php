<?php
/**
 * WordPress WP_User class mock for testing
 *
 * @package TouchPointWP\Tests
 */

if (!class_exists('WP_User')) {
    /**
     * Mock WP_User class for testing purposes.  It exists so that classes that extend WP_User, such as Person, can be
     * loaded.  It has none of WordPress's behavior.
     */
    class WP_User
    {
        public $ID = 0;
        public $user_login = '';
        public $user_email = '';
        public $display_name = '';
    }
}
