<?php
/**
 * @package TouchPointWP
 */

namespace tp\TouchPointWP\Interfaces;

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