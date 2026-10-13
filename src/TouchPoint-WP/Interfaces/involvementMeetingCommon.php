<?php
/**
 * @package TouchPointWP
 */

namespace tp\TouchPointWP\Interfaces;

if ( ! TOUCHPOINT_COMPOSER_ENABLED) {
	require_once "hierarchical.php";
	require_once "scheduled.php";
	require_once "actionButtons.php";
	require_once "hasGeo.php";
}

/**
 * This class contains the elements common between the Involvement and Meeting classes.
 */
interface involvementMeetingCommon extends scheduled, hierarchical, actionButtons, hasGeo
{
	/**
	 * Indicates if the meeting is in the past.
	 *
	 * @return bool
	 */
	public function isPast(): bool;

}