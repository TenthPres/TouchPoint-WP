<?php

/**
 * @package TouchPointWP
 */

namespace tp\TouchPointWP\Utilities;

use DateTimeImmutable;

/**
 * A helper class for DateTimeImmutable.
 */
class DateTimeExtended extends DateTimeImmutable
{
	public bool $isAllDay = false;

	public function __construct($time = "now", $timezone = null)
	{
		parent::__construct($time, $timezone);
	}

	public function format(string $format): string
	{
		return date_i18n($format, $this->getTimestamp());
	}
}