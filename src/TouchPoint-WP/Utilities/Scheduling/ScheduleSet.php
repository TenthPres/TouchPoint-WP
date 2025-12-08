<?php

namespace tp\TouchPointWP\Utilities\Scheduling;

use RRule\RSet;

class ScheduleSet extends RSet
{
	/** @var Schedule[] $schedules */
	protected array $schedules = [];

	public function getSchedules(): array {
		return $this->schedules;
	}

	public function mergeIfPossible() {
		// Group schedules by DTSTART/DTEND time-of-day (HHMMSS) so schedules that occur at the same
		// clock time can be merged. We keep one merged Schedule per time-group.
		if (count($this->schedules) <= 1) {
			return;
		}

		$groups = [];

		foreach ($this->schedules as $sched) {
			// Expect DTSTART in format YYYYMMDDTHHMMSS or YYYYMMDDTHHMM (as used in tests)
			$opts = [];
			if (is_array($sched)) {
				$opts = $sched;
			} elseif (method_exists($sched, 'getOptions')) {
				$opts = $sched->getOptions();
			}

			$dtstart = $opts['DTSTART'] ?? null;
			$dtend = $opts['DTEND'] ?? null;

			$timeKey = null;
			if ($dtstart && strpos($dtstart, 'T') !== false) {
				$parts = explode('T', $dtstart, 2);
				$startTime = $parts[1];
				$endTime = '';
				if ($dtend && strpos($dtend, 'T') !== false) {
					$endTime = explode('T', $dtend, 2)[1];
				}
				$timeKey = $startTime . '|' . $endTime;
			} else {
				// fallback: group everything together
				$timeKey = '__default__';
			}

			$groups[$timeKey][] = $sched;
		}

		$merged = [];
		foreach ($groups as $timeKey => $group) {
			if (count($group) === 1) {
				$merged[] = $group[0];
				continue;
			}

			// Combine options from all schedules in the group
			$bydays = [];
			$freqs = [];
			$counts = [];
			$dtstarts = [];
			$dtends = [];

			foreach ($group as $s) {
				$opts = [];
				if (is_array($s)) {
					$opts = $s;
				} elseif (method_exists($s, 'getOptions')) {
					$opts = $s->getOptions();
				}

				if (!empty($opts['BYDAY']) && is_array($opts['BYDAY'])) {
					foreach ($opts['BYDAY'] as $d) $bydays[$d] = true;
				}
				if (!empty($opts['FREQ'])) $freqs[$opts['FREQ']] = ($freqs[$opts['FREQ']] ?? 0) + 1;
				if (!empty($opts['COUNT'])) $counts[] = (int)$opts['COUNT'];
				if (!empty($opts['DTSTART'])) $dtstarts[] = $opts['DTSTART'];
				if (!empty($opts['DTEND'])) $dtends[] = $opts['DTEND'];
			}

			// Pick earliest DTSTART and corresponding DTEND if possible
			$earliestDtstart = null;
			if (!empty($dtstarts)) {
				sort($dtstarts, SORT_STRING);
				$earliestDtstart = $dtstarts[0];
			}
			$chosenDtend = null;
			if (!empty($dtends)) {
				sort($dtends, SORT_STRING);
				$chosenDtend = $dtends[0];
			}

			// Choose FREQ: prefer the most common FREQ among group; if mixed DAILY/WEEKLY prefer WEEKLY
			$chosenFreq = 'WEEKLY';
			if (!empty($freqs)) {
				arsort($freqs);
				$most = key($freqs);
				$chosenFreq = $most;
				// if mix contains DAILY and WEEKLY, favour WEEKLY (keeps BYDAY semantics)
				if (isset($freqs['WEEKLY'])) $chosenFreq = 'WEEKLY';
			}

			// COUNT: use max of counts to avoid accidentally shortening
			$chosenCount = null;
			if (!empty($counts)) $chosenCount = max($counts);

			$newOpts = [];
			$newOpts['FREQ'] = $chosenFreq;
			if (!empty($bydays)) {
				$newOpts['BYDAY'] = array_values(array_keys($bydays));
			}
			if ($chosenCount !== null) $newOpts['COUNT'] = $chosenCount;
			if ($earliestDtstart) $newOpts['DTSTART'] = $earliestDtstart;
			if ($chosenDtend) $newOpts['DTEND'] = $chosenDtend;

			$merged[] = new Schedule($newOpts);
		}

		// Replace schedules with merged results
		$this->schedules = $merged;
	}


}