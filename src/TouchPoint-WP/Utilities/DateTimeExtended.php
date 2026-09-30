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

	/**
	 * Get a formatted date string according to the given format.  For user-facing text, please use
	 * format_i18n instead, which allows the format string to be localized.
	 *
	 * @param string $format
	 *
	 * @return string
	 *
	 * @see self::format_i18n()
	 */
	public function format(string $format): string
	{
		return parent::format($format);
	}

	/**
	 * Get a human-intended formatted string according to the given format.
	 *
	 * @param string $format
	 *
	 * @return string
	 */
	public function format_i18n(string $format): string
	{
		return date_i18n($format, $this->getTimestamp() + $this->getOffset());
	}
}