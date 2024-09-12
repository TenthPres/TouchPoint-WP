<?php
/**
 * @package TouchPointWP
 */

namespace tp\TouchPointWP\Utilities;

use DateTimeInterface;
use tp\TouchPointWP\Utilities;

/**
 * Formatting Dates
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
		$ts = wp_date(get_option('time_format'), self::timestampWithoutOffset($dt));

		/**
		 * Allows for manipulation of the string returned as a formatted time.
		 *
		 * @since 0.0.34 Added
		 *
		 * @param string $ts The string, as formatted so far.
		 * @param DateTimeInterface $dt The DateTimeInterface object for the time being formatted.
		 */
		return apply_filters('tp_adjust_time_string', $ts, $dt);
	}

	/**
	 * @param ?DateTimeInterface $dt
	 *
	 * @return int
	 *
	 * @since 0.0.90 added
	 */
	public static function timestampWithoutOffset(?DateTimeInterface $dt): int
	{
		if ($dt === null) {
			return 0;
		}

		$dt = $dt->setTimezone(Utilities::utcTimeZone());
		return $dt->getTimestamp();
	}

	/**
	 * @param ?DateTimeInterface $dt
	 *
	 * @return int
	 *
	 * @since 0.0.90 added
	 * @deprecated 0.0.91  Now that date_i18n is deprecated, this is probably not needed.
	 */
	public static function timestampAndOffset(?DateTimeInterface $dt): int
	{
		if ($dt === null) {
			return 0;
		}
		return $dt->getTimestamp() + $dt->getOffset();
	}

	/**
	 * Get a time string for a given pair of DateTimesInterfaces.  This should generally only be used when all of these
	 * are true:
	 * - The DateTimes are on the same day.
	 * - The event represented is not all-day.
	 * - Neither are null
	 *
	 * @param DateTimeInterface $startDt
	 * @param DateTimeInterface $endDt
	 *
	 * @return string
	 */
	public static function TimeRangeStringFormatted(DateTimeInterface $startDt, DateTimeInterface $endDt): string
	{
		$startStr = self::TimeStringFormatted($startDt);
		$endStr = self::TimeStringFormatted($endDt);

		// translators: %1$s is the start time, %2$s is the end time.
		$ts = wp_sprintf(__('%1$s &ndash; %2$s', 'TouchPoint-WP'), $startStr, $endStr);

		/**
		 * Allows for manipulation of a time range string.  For example, combined with `tp_adjust_time_string`, you
		 * can change a range of 1:00pm - 2:00pm to 1-2pm.
		 *
		 * @since 0.0.90 Added
		 *
		 * @param string            $ts The string, as formatted so far.
		 * @param string            $startStr The start string, with default formatting.
		 * @param string            $endStr The end string, with default formatting
		 * @param DateTimeInterface $startDt The DateTimeInterface object for the start.
		 * @param DateTimeInterface $endDt The DateTimeInterface object for the $end.
		 */
		return apply_filters('tp_adjust_time_range_string', $ts, $startStr, $endStr, $startDt, $endDt);
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
				$ts    = DateFormats::timestampWithoutOffset($dt);
				$nowTs = DateFormats::timestampWithoutOffset($now);


				if ($tomorrow->format("Y") === $dt->format("Y")) { // Same Year
					$day = wp_date(_x('l', "Date string for day of the week, when the year is current.", "TouchPoint-WP"), $ts);
					$date = wp_date(_x('F j', "Date string when the year is current.", "TouchPoint-WP"), $ts);
				} else {
					$day = wp_date(_x('l', "Date string for day of the week, when the year is not current.", "TouchPoint-WP"), $ts);
					$date = wp_date(_x('F j, Y', "Date string when the year is not current.", "TouchPoint-WP"), $ts);
				}

				// Last week
				if ($ts < $nowTs && $ts - $nowTs > -7 * 86400) {
					// translators: %1$s is "Monday".  %2$s is "January 1".
					$r = sprintf(_x('Last %1$s, %2$s', "Date format string", 'TouchPoint-WP'), $day, $date);
				}

				// This week
				else if ($ts > $nowTs && $ts - $nowTs < 7 * 86400) {
					// translators: %1$s is "Monday".  %2$s is "January 1".
					$r = sprintf(_x('This %1$s, %2$s', "Date format string", 'TouchPoint-WP'), $day, $date);
				}

				// Next week
				else if ($ts > $nowTs && $ts - $nowTs < 14 * 86400) {
					// translators: %1$s is "Monday".  %2$s is "January 1".
					$r = sprintf(_x('Next %1$s, %2$s', "Date format string", 'TouchPoint-WP'), $day, $date);

				// Other Times
				} else {
					// translators: %1$s is "Monday".  %2$s is "January 1".
					$r = sprintf(_x('%1$s, %2$s', "Date format string", 'TouchPoint-WP'), $day, $date);
				}
			}
		}

		/**
		 * Allows for manipulation of the string returned as a formatted date.
		 *
		 * @since 0.0.90 Added
		 *
		 * @param string $r The string, as formatted so far.
		 * @param DateTimeInterface $dt The DateTimeInterface object for the date being formatted.
		 */
		return apply_filters('tp_adjust_date_string', $r, $dt);
	}


	/**
	 * Get a (shorter) string for a single given datetime string.
	 *
	 * @param DateTimeInterface $dt
	 *
	 * @return string
	 */
	public static function DateStringFormattedShort(DateTimeInterface $dt): string
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
				$ts    = DateFormats::timestampWithoutOffset($dt);
				$nowTs = DateFormats::timestampWithoutOffset($now);


				if ($tomorrow->format("Y") === $dt->format("Y")) { // Same Year
					$day = wp_date(_x('D', "Short date string for day of the week, when the year is current.", "TouchPoint-WP"), $ts);
					$date = wp_date(_x('M j', "Short date string when the year is current.", "TouchPoint-WP"), $ts);
				} else {
					$day = wp_date(_x('D', "Short date string for day of the week, when the year is not current.", "TouchPoint-WP"), $ts);
					$date = wp_date(_x('M j, Y', "Short date string when the year is not current.", "TouchPoint-WP"), $ts);
				}

				// Last week
				if ($ts < $nowTs && $ts - $nowTs > -7 * 86400) {
					// translators: %1$s is "Mon".  %2$s is "Jan 1".
					$r = sprintf(_x('Last %1$s, %2$s', "Short date format string", 'TouchPoint-WP'), $day, $date);
				}

				// This week
				else if ($ts > $nowTs && $ts - $nowTs < 7 * 86400) {
					// translators: %1$s is "Mon".  %2$s is "Jan 1".
					$r = sprintf(_x('This %1$s, %2$s', "Short date format string", 'TouchPoint-WP'), $day, $date);
				}

				// Next week
				else if ($ts > $nowTs && $ts - $nowTs < 14 * 86400) {
					// translators: %1$s is "Mon".  %2$s is "Jan 1".
					$r = sprintf(_x('Next %1$s, %2$s', "Short date format string", 'TouchPoint-WP'), $day, $date);

				// Other Times
				} else {
					// translators: %1$s is "Mon".  %2$s is "Jan 1".
					$r = sprintf(_x('%1$s, %2$s', "Short date format string", 'TouchPoint-WP'), $day, $date);
				}
			}
		}

		/**
		 * Allows for manipulation of the string returned as a (short) formatted date.
		 *
		 * @since 0.0.90 Added
		 *
		 * @param string $r The string, as formatted so far.
		 * @param DateTimeInterface $dt The DateTimeInterface object for the date being formatted.
		 */
		return apply_filters('tp_adjust_date_string_short', $r, $dt);
	}


	/**
	 * Get a string for the period between $start and $end.
	 *
	 * @param ?DateTimeExtended $start
	 * @param ?DateTimeExtended $end
	 * @param bool|null        $multiDay
	 *
	 * @return ?string
	 */
	public static function DurationToString(?DateTimeExtended $start, ?DateTimeExtended $end, ?bool $multiDay = null, ): ?string
	{
		if ($start === null) {
			return null;
		}

		if ($multiDay === null) {
			if ($end === null) {
				$multiDay = false;
			} else {
				$multiDay = ($start->format('Ymd') !== $end->format('Ymd'));
			}
		}

		$allDay = $start->isAllDay;

		$r = self::DurationToStringArray($start, $end, $multiDay, $allDay);

		if (isset($r['datetime'])) {
			return $r['datetime'];
		}

		if (isset($r['date']) && isset($r['time'])) {
			// translators: %1$s is the date(s), %2$s is the time(s).
			return wp_sprintf(__('%1$s at %2$s', 'TouchPoint-WP'), $r['date'], $r['time']);
		}

		return null;
	}

	/**
	 * Takes a start and end and returns one or two strings that are intended for use in a Meeting's Notable Attributes,
	 * but can be used for other similar cases.
	 *
	 * @param ?DateTimeInterface $start
	 * @param ?DateTimeInterface $end
	 * @param ?bool              $multiDay
	 * @param ?bool              $allDay
	 *
	 * @return StringableArray
	 */
	public static function DurationToStringArray(?DateTimeInterface $start, ?DateTimeInterface $end, ?bool $multiDay = null, ?bool $allDay = null): StringableArray
	{
		$r = new StringableArray();

		if ($start === null) {
			return $r;
		}

		if ($multiDay === null) {
			if ($end === null) {
				$multiDay = false;
			} else {
				$multiDay = ($start->format('Ymd') !== $end->format('Ymd'));
			}
		}

		// outputs are commented by bits corresponding to multiDay, end==null, and allDay

		if ($allDay === true) {
			if ($multiDay && $end !== null) {
				// 001

				$date1 = self::DateStringFormattedShort($start);
				$date2 = self::DateStringFormattedShort($end);

				// Translators: %1$s is the start date, %2$s is the end date.
				$r['datetime'] = wp_sprintf(__('%1$s &ndash; %2$s', 'TouchPoint-WP'), $date1, $date2);

			} else {
				// 011
				// 101
				// 111

				$r['datetime'] = self::DateStringFormatted($start);
			}

			return $r;
		}

		if ($multiDay) {
			if ($end === null) {
				// 110

				$date = self::DateStringFormatted($start);
				$time = self::TimeStringFormatted($start);

				// Translators: %1$s is the start date, %2$s is the start time.
				$r['datetime'] = wp_sprintf(__('%1$s at %2$s', 'TouchPoint-WP'), $date, $time);

			} else {
				// 100

				$date1 = self::DateStringFormattedShort($start);
				$date2 = self::DateStringFormattedShort($end);

				$time1 = self::TimeStringFormatted($start);
				$time2 = self::TimeStringFormatted($end);

				// translators: %1$s is the start date, %2$s start time, %3$s is the end date, and %4$s end time.
				$r['datetime'] = wp_sprintf(__('%1$s at %2$s &ndash; %3$s at %4$s', 'TouchPoint-WP'), $date1, $time1, $date2, $time2);
			}

			return $r;
		}

		$r['date'] = self::DateStringFormatted($start);

		if ($end === null) {
			// 010

			$time = self::TimeStringFormatted($start);
			$r['time'] = $time;
		} else {
			// 000

			$r['time'] = self::TimeRangeStringFormatted($start, $end);
		}

		return $r;

	}
}