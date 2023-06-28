<?php
/**
 * @package TouchPointWP
 */
namespace tp\TouchPointWP\Utilities;

/**
 * Utility class for geographical attributes and calculations.  Not to be confused with the geo interface.
 * @see \tp\TouchPointWP\geo
 */
abstract class Geo
{
	/**
	 * Get distance between two geographic points by lat/lng pairs.  Returns a number in miles.
	 *
	 * @param float $latA
	 * @param float $lngA
	 * @param float $latB
	 * @param float $lngB
	 *
	 * @return float
	 */
	public static function distance(float $latA, float $lngA, float $latB, float $lngB): float
	{
		$latA_r = deg2rad($latA);
		$lngA_r = deg2rad($lngA);
		$latB_r = deg2rad($latB);
		$lngB_r = deg2rad($lngB);

		return round(3959 * acos(
			             cos($latA_r) * cos($latB_r) * cos($lngB_r - $lngA_r) + sin($latA_r) * sin($latB_r)
		             ), 1);
	}
}