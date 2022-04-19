<?php
/**
 * @package TouchPointWP
 */
namespace tp\TouchPointWP;

use WP_Error;



if ( ! defined('ABSPATH')) {
    exit;
}

/**
 * An Exception class.
 */
class TouchPointWP_WPError extends TouchPointWP_Exception
{
    public function __construct(WP_Error $wpE)
    {
        $code = $wpE->get_error_code();
        $code = is_int($code) ? $code : 0;
        parent::__construct($wpE->get_error_message(), $code);
    }
}
