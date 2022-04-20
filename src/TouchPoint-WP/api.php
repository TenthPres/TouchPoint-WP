<?php
/**
 * @package TouchPointWP
 */
namespace tp\TouchPointWP;

if ( ! defined('ABSPATH')) {
    exit(1);
}


/**
 * API Interface
 */
interface api {

    /**
     * Handle API requests
     *
     * @param array $uri The request URI already parsed by parse_url()
     *
     * @return bool False if endpoint is not found.  Should print the result.
     */
    public static function api(array $uri): bool;
}