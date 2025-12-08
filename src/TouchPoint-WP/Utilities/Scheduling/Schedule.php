<?php

namespace tp\TouchPointWP\Utilities\Scheduling;

use RRule\RRule;

class Schedule extends RRule
{
	/**
	 * Get the frequency of the rule.
	 *
	 * @return string|null The frequency (e.g., 'WEEKLY', 'MONTHLY', 'YEARLY')
	 */
	public function getFreq(): ?string
	{
		return $this->rule['FREQ'];
	}

	/**
	 * Get the count of occurrences.
	 *
	 * @return int|null The count
	 */
	public function getCount(): ?int
	{
		return $this->rule['COUNT'];
	}

	/**
	 * Get the UNTIL date.
	 *
	 * @return string|null The UNTIL date in RFC format
	 */
	public function getUntil(): ?string
	{
		return $this->rule['UNTIL'];
	}

	/**
	 * Get the interval.
	 *
	 * @return int The interval
	 */
	public function getInterval(): int
	{
		return $this->rule['INTERVAL'] ?? 1;
	}

	/**
	 * Get the BYDAY values as an array.
	 *
	 * @return array|null The BYDAY values (e.g., ['MO', 'WE', 'FR'])
	 */
	public function getByDay(): ?array
	{
		$byday = $this->rule['BYDAY'];
		if ($byday === null || $byday === '') {
			return null;
		}
		if (is_string($byday)) {
			return explode(',', $byday);
		}
		return $byday;
	}

	/**
	 * Get the BYMONTH values as an array.
	 *
	 * @return array|null The BYMONTH values (e.g., [1, 3, 9, 11])
	 */
	public function getByMonth(): ?array
	{
		$bymonth = $this->rule['BYMONTH'];
		if ($bymonth === null || $bymonth === '') {
			return null;
		}
		if (is_string($bymonth)) {
			return array_map('intval', explode(',', $bymonth));
		}
		if (is_array($bymonth)) {
			return array_map('intval', $bymonth);
		}
		return [(int)$bymonth];
	}

	/**
	 * Get the BYHOUR values as an array.
	 *
	 * @return array|null The BYHOUR values (e.g., [9, 11])
	 */
	public function getByHour(): ?array
	{
		$byhour = $this->rule['BYHOUR'];
		if ($byhour === null || $byhour === '') {
			return null;
		}
		if (is_string($byhour)) {
			return array_map('intval', explode(',', $byhour));
		}
		if (is_array($byhour)) {
			return array_map('intval', $byhour);
		}
		return [(int)$byhour];
	}

	/**
	 * Get the BYMINUTE values as an array.
	 *
	 * @return array|null The BYMINUTE values
	 */
	public function getByMinute(): ?array
	{
		$byminute = $this->rule['BYMINUTE'];
		if ($byminute === null || $byminute === '') {
			return null;
		}
		if (is_string($byminute)) {
			return array_map('intval', explode(',', $byminute));
		}
		if (is_array($byminute)) {
			return array_map('intval', $byminute);
		}
		return [(int)$byminute];
	}

	/**
	 * Get the BYSECOND values as an array.
	 *
	 * @return array|null The BYSECOND values
	 */
	public function getBySecond(): ?array
	{
		$bysecond = $this->rule['BYSECOND'];
		if ($bysecond === null || $bysecond === '') {
			return null;
		}
		if (is_string($bysecond)) {
			return array_map('intval', explode(',', $bysecond));
		}
		if (is_array($bysecond)) {
			return array_map('intval', $bysecond);
		}
		return [(int)$bysecond];
	}
}