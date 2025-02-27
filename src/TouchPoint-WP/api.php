<?php
/**
 * @package TouchPointWP
 */

namespace tp\TouchPointWP;

if ( ! defined('ABSPATH')) {
	exit(1);
}


/**
 * Any classes that handle API requests from the client via /touchpoint-api/ should implement this interface.
 * 
 * This is NOT for the connection to TouchPoint's API, but rather for XHR and such from the client.
 */
interface api
{

	/**
	 * Handle API requests
	 *
	 * @param array $uri The request URI already parsed by parse_url()
	 *
	 * @return bool False if endpoint is not found.  Should print the result.
	 */
	public static function api(array $uri): bool;
}