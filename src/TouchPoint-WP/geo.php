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
interface geo {

	/**
	 * Indicates whether a map of a *single* item can be displayed.
	 *
	 * @return bool
	 */
	public function hasGeo(): bool;

	public function asGeoIFace(): ?object;
}