<?php
/**
 * @package TouchPointWP
 */

namespace tp\TouchPointWP;

use tp\TouchPointWP\Interfaces\api;

if ( ! TOUCHPOINT_COMPOSER_ENABLED) {
	require_once "Interfaces/api.php";
}

if ( ! defined('ABSPATH')) {
	exit;
}

/**
 * Admin API class.
 */
class Lookup implements api
{

	/**
	 * Handle API requests
	 *
	 * @param array $uri The request URI already parsed by parse_url()
	 *
	 * @return bool False if endpoint is not found.  Should print the result.
	 */
	public static function api(array $uri): bool
	{
		try {
			$d = TouchPointWP::instance()->api->get('/api/v1/Lookup/' . $uri['path'][2]);
			header('Content-Type: application/json');
			echo $d['body']; // already JSON-encoded by the API
			exit;
		} catch (TouchPointWP_Exception) {
			return false;
		}
	}
}