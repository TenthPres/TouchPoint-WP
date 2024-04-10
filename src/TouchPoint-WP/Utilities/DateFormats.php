<?php
/**
 * @package TouchPointWP
 */

namespace tp\TouchPointWP\Utilities;

use DateTimeInterface;
use tp\TouchPointWP\Utilities;

/**
 * A collection of people, easily cast to string.
 */
abstract class DateFormats
{
	/**
	 * Get a string for a single given DateTime.
	 *
	 * @param DateTimeInterface $dt
	 *
	 * @return string
	 */
	public static function TimeStringFormatted(DateTimeInterface $dt): string
	{
		$ts = date_i18n(get_option('time_format'), $dt->getTimestamp());

		/**
		 * Allows for manipulation of the string returned as a formatted time.
		 *
		 * @since 0.0.34
		 *
		 * @param string $ts The string, as formatted so far.
		 * @param DateTimeInterface $dt The DateTimeInterface object for the time being formatted.
		 */
		return apply_filters('tp_adjust_time_string', $ts, $dt);
	}

	/**
	 * Get a string for a single given datetime string.
	 *
	 * @param DateTimeInterface $dt
	 *
	 * @return string
	 */
	public static function DateStringFormatted(DateTimeInterface $dt): string
	{
		// Today
		$now = Utilities::dateTimeNow();
		if ($dt->format("Ymd") === $now->format("Ymd")) {
			if ((int)$dt->format('G') >= 17) { // 5pm or later
				$r = __("Tonight", "TouchPoint-WP");
			} else {
				$r = __("Today", "TouchPoint-WP");
			}
		} else {
			// Tomorrow
			$tomorrow = Utilities::dateTimeNowPlus1D();
			if ($tomorrow->format("Ymd") === $dt->format("Ymd")) {
				$r = __("Tomorrow", "TouchPoint-WP");
			} else {
				$ts    = $dt->getTimestamp();
				$nowTs = $now->getTimestamp();


				if ($tomorrow->format("Y") === $dt->format("Y")) { // Same Year
					$day = date_i18n(_x('l', "Date string for day of the week, when given without a year.", "TouchPoint-WP"), $ts);
					$date = date_i18n(_x('M j', "Date string when given without a year", "TouchPoint-WP"), $ts);
				} else {
					$day = date_i18n(_x('D', "Date string for day of the week, when given with a year.", "TouchPoint-WP"), $ts);
					$date = date_i18n(_x('M j, Y', "Date string when given with a year", "TouchPoint-WP"), $ts);
				}

				// Last week
				if ($ts - $nowTs > -7 * 86400 && $ts - $nowTs < 0) {
					// translators: %1s is "Monday".  %2s is "January 1".
					$r = sprintf(_x('Last %1$s, %2$s', "Date format string", 'TouchPoint-WP'), $day, $date);
				}

				// This week
				else if ($ts - $nowTs < 7 * 86400) {
					// translators: %1s is "Monday".  %2s is "January 1".
					$r = sprintf(_x('This %1$s, %2$s', "Date format string", 'TouchPoint-WP'), $day, $date);
				}

				// Next week
				else if ($ts - $nowTs < 14 * 86400) {
					// translators: %1$s is "Monday".  %2$s is "January 1".
					$r = sprintf(_x('Next %1$s, %2$s', "Date format string", 'TouchPoint-WP'), $day, $date);

				// Other Times
				} else {
					// translators: %1s is "Monday".  %2s is "January 1".
					$r = sprintf(_x('%1$s, %2$s', "Date format string", 'TouchPoint-WP'), $day, $date);
				}
			}
		}

		/**
		 * Allows for manipulation of the string returned as a formatted date.
		 *
		 * @since 0.0.90
		 *
		 * @param string $ts The string, as formatted so far.
		 * @param DateTimeInterface $dt The DateTimeInterface object for the date being formatted.
		 */
		return apply_filters('tp_adjust_date_string', $r, $dt);
	}
}