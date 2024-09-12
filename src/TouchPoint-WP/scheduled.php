<?php
/**
 * @package TouchPointWP
 */

namespace tp\TouchPointWP;

/**
 * This is a base interface for classes that have "schedule" strings.
 */
interface scheduled
{
	/**
	 * Get a description of the schedule in a human-friendly phrase, e.g. Sunday, March 31 at 2:00pm.
	 *
	 * These strings should be cached.
	 *
	 * @param int   $objId
	 * @param mixed $obj  Needs to be of the type that implements this interface.
	 *
	 * @return ?string
	 */
	public static function scheduleString(int $objId, $obj = null): ?string;
}