<?php
/**
 * @package TouchPointWP
 */

namespace tp\TouchPointWP;

if ( ! defined('ABSPATH')) {
	exit(1);
}


/**
 * For classes that have geographic attributes, or potential geographic attributes
 */
interface geo
{

	/**
	 * Indicates whether a map of a *single* item can be displayed.
	 *
	 * @return bool
	 */
	public function hasGeo(): bool;

	/**
	 * Returns a standardized stdObject, or null if not viable.
	 * Return object properties are lat, lng, human, and type.
	 *
	 * @param string $type 'loc' for navigator location, or 'ip' for ip address location
	 *
	 * @return object|null
	 */
	public function asGeoIFace(string $type = "unknown"): ?object;
}