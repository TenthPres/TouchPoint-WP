<?php
/**
 * WordPress WP_Post class mock for testing
 *
 * @package TouchPointWP\Tests
 */

if (!class_exists('WP_Post')) {
    /**
     * Mock WP_Post class for testing purposes.  It has the fields the plugin reads, and none of WordPress's behavior.
     */
    class WP_Post
    {
        public $ID = 0;
        public $post_parent = 0;
        public $post_name = '';
        public $post_title = '';
        public $post_content = '';
        public $post_type = 'post';
        public $post_status = 'publish';

        /**
         * @param object|array|null $post Values for any of the fields.
         */
        public function __construct($post = null)
        {
            foreach ((array)$post as $field => $value) {
                $this->$field = $value;
            }
        }
    }
}
