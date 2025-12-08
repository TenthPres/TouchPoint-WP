<?php
/**
 * WordPress WP_Error class mock for testing
 *
 * @package TouchPointWP\Tests
 */

if (!class_exists('WP_Error')) {
    /**
     * Mock WP_Error class for testing purposes.
     */
    class WP_Error
    {
        /**
         * Stores the list of errors.
         *
         * @var array
         */
        public $errors = [];

        /**
         * Stores the list of data for error codes.
         *
         * @var array
         */
        public $error_data = [];

        /**
         * Constructor.
         *
         * @param string $code    Error code.
         * @param string $message Error message.
         * @param mixed  $data    Error data.
         */
        public function __construct($code = '', $message = '', $data = '')
        {
            if (empty($code)) {
                return;
            }

            $this->errors[$code][] = $message;

            if (!empty($data)) {
                $this->error_data[$code] = $data;
            }
        }

        /**
         * Retrieve first error code available.
         *
         * @return string|int Empty string if no error codes are available.
         */
        public function get_error_code()
        {
            $codes = array_keys($this->errors);
            return empty($codes) ? '' : $codes[0];
        }

        /**
         * Retrieve first error message available.
         *
         * @param string|int $code Error code to retrieve message for.
         * @return string Empty string if no error messages are available.
         */
        public function get_error_message($code = '')
        {
            if (empty($code)) {
                $code = $this->get_error_code();
            }
            return isset($this->errors[$code]) ? $this->errors[$code][0] : '';
        }

        /**
         * Retrieve error data for error code.
         *
         * @param string|int $code Error code.
         * @return mixed Null if $code is invalid.
         */
        public function get_error_data($code = '')
        {
            if (empty($code)) {
                $code = $this->get_error_code();
            }
            return isset($this->error_data[$code]) ? $this->error_data[$code] : null;
        }

        /**
         * Add an error.
         *
         * @param string|int $code    Error code.
         * @param string     $message Error message.
         * @param mixed      $data    Error data.
         */
        public function add($code, $message, $data = '')
        {
            $this->errors[$code][] = $message;
            if (!empty($data)) {
                $this->error_data[$code] = $data;
            }
        }
    }
}
