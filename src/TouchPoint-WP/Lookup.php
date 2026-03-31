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
abstract class Lookup implements api
{

	const TTL = HOUR_IN_SECONDS;

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
			header('Content-Type: application/json');
			echo json_encode(self::getLookup($uri['path'][2]));
			exit;
		} catch (TouchPointWP_Exception) {
			return false;
		}
	}

	/**
	 * @param string $path
	 * @param bool   $noCache
	 *
	 * @return mixed
	 * @throws TouchPointWP_Exception
	 */
	public static function getLookup(string $path, bool $noCache = false): mixed
	{
		$cacheKey = TouchPointWP::SETTINGS_PREFIX . "lookup_$path";

		if (!$noCache) {
			// check transients
			$v = get_transient($cacheKey);
			if ($v !== false) {
				return json_decode($v);
			}
		}

		$v = TouchPointWP::instance()->api->get("/api/v1/Lookup/$path");

		if (isset($v['body'])) {
			// assume body is already json
			set_transient($cacheKey, $v['body'], self::TTL);

			return json_decode($v['body']);
		}

		throw new TouchPointWP_Exception("Unexpected response from API: " . json_encode($v));
	}
}