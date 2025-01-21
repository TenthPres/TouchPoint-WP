<?php

/**
 * @package TouchPointWP
 */

namespace tp\TouchPointWP\Utilities;

use DateTimeImmutable;

/**
 * A collection of people, easily cast to string.
 */
class DateTimeExtended extends DateTimeImmutable
{
	public bool $isAllDay = false;

	public function __construct($time = "now", $timezone = null)
	{
		parent::__construct($time, $timezone);
	}
}