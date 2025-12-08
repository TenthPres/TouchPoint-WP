<?php

namespace tp\TouchPointWP\Utilities\Scheduling;

use RRule\RSet;
use RRule\RRule;

class ScheduleSet extends RSet
{
	/**
	 * Merge compatible RRules when possible.
	 * This method attempts to combine multiple RRules into fewer rules when they share compatible properties.
	 */
	public function mergeIfPossible(): void
	{
		$rules = $this->getRRules();
		
		if (count($rules) <= 1) {
			return; // Nothing to merge
		}

		// Group rules by frequency
		$groupedByFreq = [];
		foreach ($rules as $rule) {
			$ruleData = $rule->getRule();
			$freq = $ruleData['FREQ'];
			if (!isset($groupedByFreq[$freq])) {
				$groupedByFreq[$freq] = [];
			}
			$groupedByFreq[$freq][] = $rule;
		}

		// Process each frequency group
		$newRules = [];
		foreach ($groupedByFreq as $freq => $freqRules) {
			if ($freq === 'WEEKLY') {
				$merged = $this->mergeWeeklyRules($freqRules);
				$newRules = array_merge($newRules, $merged);
			} elseif ($freq === 'YEARLY') {
				$merged = $this->mergeYearlyRules($freqRules);
				$newRules = array_merge($newRules, $merged);
			} elseif ($freq === 'MONTHLY') {
				$merged = $this->mergeMonthlyRules($freqRules);
				$newRules = array_merge($newRules, $merged);
			} else {
				// Keep rules we can't merge
				$newRules = array_merge($newRules, $freqRules);
			}
		}

		// Replace the rules in this set
		$this->rrules = $newRules;
		$this->clearCache();
	}

	/**
	 * Merge weekly rules if they have compatible properties.
	 *
	 * @param array $rules Array of RRule objects
	 * @return array Merged rules
	 */
	private function mergeWeeklyRules(array $rules): array
	{
		if (count($rules) <= 1) {
			return $rules;
		}

		// Group by compatible properties
		$groups = [];
		foreach ($rules as $rule) {
			$ruleData = $rule->getRule();
			
			// Create a signature for grouping
			$signature = [
				'interval' => $ruleData['INTERVAL'] ?? 1,
				'byhour' => $ruleData['BYHOUR'] ?? null,
				'byminute' => $ruleData['BYMINUTE'] ?? null,
				'bysecond' => $ruleData['BYSECOND'] ?? null,
				'wkst' => $ruleData['WKST'] ?? 'MO',
			];
			
			// Handle COUNT vs UNTIL separately
			if ($ruleData['COUNT'] !== null && $ruleData['COUNT'] !== '') {
				$signature['count'] = $ruleData['COUNT'];
				$signature['until'] = null;
			} elseif ($ruleData['UNTIL'] !== null && $ruleData['UNTIL'] !== '') {
				$signature['count'] = null;
				$signature['until'] = $this->getWeekOfDate($ruleData['UNTIL']);
			} else {
				// Open-ended rules
				$signature['count'] = null;
				$signature['until'] = null;
			}
			
			$key = serialize($signature);
			if (!isset($groups[$key])) {
				$groups[$key] = [
					'signature' => $signature,
					'rules' => [],
				];
			}
			$groups[$key]['rules'][] = $rule;
		}

		// Merge within each group
		$mergedRules = [];
		foreach ($groups as $group) {
			$groupRules = $group['rules'];
			if (count($groupRules) === 1) {
				$mergedRules[] = $groupRules[0];
				continue;
			}

			$signature = $group['signature'];
			
			// For UNTIL-based rules, check if they end in the same week
			if ($signature['until'] !== null) {
				// Merge only if all UNTIL dates are in the same week
				$allSameWeek = true;
				$weekRef = null;
				$latestUntil = null;
				
				foreach ($groupRules as $rule) {
					$ruleData = $rule->getRule();
					$until = $ruleData['UNTIL'];
					$week = $this->getWeekOfDate($until);
					
					if ($weekRef === null) {
						$weekRef = $week;
						$latestUntil = $until;
					} else {
						if ($week !== $weekRef) {
							$allSameWeek = false;
							break;
						}
						// Keep the latest UNTIL date
						if ($until > $latestUntil) {
							$latestUntil = $until;
						}
					}
				}
				
				if (!$allSameWeek) {
					// Can't merge, keep them separate
					$mergedRules = array_merge($mergedRules, $groupRules);
					continue;
				}
				
				// Merge BYDAY
				$mergedByDay = $this->mergeByDayValues($groupRules);
				
				// Create merged rule
				$mergedRule = $this->createWeeklyRule(
					$mergedByDay,
					$signature['interval'],
					null,
					$latestUntil,
					$signature['byhour'],
					$signature['byminute'],
					$signature['bysecond'],
					$signature['wkst']
				);
				
				$mergedRules[] = $mergedRule;
			} else {
				// For COUNT-based or open-ended rules, merge BYDAY
				$mergedByDay = $this->mergeByDayValues($groupRules);
				
				// For COUNT-based rules, sum the counts
				$totalCount = null;
				if ($signature['count'] !== null) {
					$totalCount = 0;
					foreach ($groupRules as $rule) {
						$ruleData = $rule->getRule();
						$totalCount += $ruleData['COUNT'];
					}
				}
				
				// Create merged rule
				$mergedRule = $this->createWeeklyRule(
					$mergedByDay,
					$signature['interval'],
					$totalCount,
					null,
					$signature['byhour'],
					$signature['byminute'],
					$signature['bysecond'],
					$signature['wkst']
				);
				
				$mergedRules[] = $mergedRule;
			}
		}

		return $mergedRules;
	}

	/**
	 * Merge yearly rules if they have compatible properties.
	 *
	 * @param array $rules Array of RRule objects
	 * @return array Merged rules
	 */
	private function mergeYearlyRules(array $rules): array
	{
		if (count($rules) <= 1) {
			return $rules;
		}

		// Group by compatible properties (excluding BYMONTH)
		$groups = [];
		foreach ($rules as $rule) {
			$ruleData = $rule->getRule();
			
			$signature = [
				'interval' => $ruleData['INTERVAL'] ?? 1,
				'byday' => $ruleData['BYDAY'] ?? null,
				'byhour' => $ruleData['BYHOUR'] ?? null,
				'byminute' => $ruleData['BYMINUTE'] ?? null,
				'bysecond' => $ruleData['BYSECOND'] ?? null,
				'count' => $ruleData['COUNT'] ?? null,
				'until' => $ruleData['UNTIL'] ?? null,
			];
			
			$key = serialize($signature);
			if (!isset($groups[$key])) {
				$groups[$key] = [
					'signature' => $signature,
					'rules' => [],
				];
			}
			$groups[$key]['rules'][] = $rule;
		}

		// Merge within each group
		$mergedRules = [];
		foreach ($groups as $group) {
			$groupRules = $group['rules'];
			if (count($groupRules) === 1) {
				$mergedRules[] = $groupRules[0];
				continue;
			}

			// Merge BYMONTH
			$allMonths = [];
			foreach ($groupRules as $rule) {
				$ruleData = $rule->getRule();
				$bymonth = $ruleData['BYMONTH'];
				if ($bymonth !== null && $bymonth !== '') {
					if (is_string($bymonth)) {
						$months = explode(',', $bymonth);
					} elseif (is_array($bymonth)) {
						$months = $bymonth;
					} else {
						$months = [$bymonth];
					}
					foreach ($months as $month) {
						$allMonths[] = (int)$month;
					}
				}
			}
			
			$allMonths = array_unique($allMonths);
			sort($allMonths);
			
			// Create merged rule
			$signature = $group['signature'];
			$mergedRule = $this->createYearlyRule(
				$allMonths,
				$signature['byday'],
				$signature['interval'],
				$signature['count'],
				$signature['until'],
				$signature['byhour'],
				$signature['byminute'],
				$signature['bysecond']
			);
			
			$mergedRules[] = $mergedRule;
		}

		return $mergedRules;
	}

	/**
	 * Merge monthly rules and potentially convert to weekly if applicable.
	 *
	 * @param array $rules Array of RRule objects
	 * @return array Merged rules
	 */
	private function mergeMonthlyRules(array $rules): array
	{
		if (count($rules) <= 1) {
			return $rules;
		}

		// Check if all rules can be simplified to a weekly rule
		// This happens when we have multiple monthly rules that cover all Nth occurrences of a weekday
		
		// Group by compatible properties (excluding BYDAY)
		$groups = [];
		foreach ($rules as $rule) {
			$ruleData = $rule->getRule();
			
			$signature = [
				'interval' => $ruleData['INTERVAL'] ?? 1,
				'bymonth' => $ruleData['BYMONTH'] ?? null,
				'count' => $ruleData['COUNT'] ?? null,
				'until' => $ruleData['UNTIL'] ?? null,
			];
			
			$key = serialize($signature);
			if (!isset($groups[$key])) {
				$groups[$key] = [
					'signature' => $signature,
					'rules' => [],
					'byhours' => [],
				];
			}
			$groups[$key]['rules'][] = $rule;
			
			// Collect BYHOUR values
			$byhour = $ruleData['BYHOUR'];
			if ($byhour !== null && $byhour !== '') {
				if (is_string($byhour)) {
					$hours = explode(',', $byhour);
				} elseif (is_array($byhour)) {
					$hours = $byhour;
				} else {
					$hours = [$byhour];
				}
				foreach ($hours as $hour) {
					$groups[$key]['byhours'][] = (int)$hour;
				}
			}
		}

		// Process each group
		$mergedRules = [];
		foreach ($groups as $group) {
			$groupRules = $group['rules'];
			$signature = $group['signature'];
			
			// Extract BYDAY patterns to see if they can be simplified
			$allByDays = [];
			$weekdayPattern = null;
			$canSimplify = true;
			
			foreach ($groupRules as $rule) {
				$ruleData = $rule->getRule();
				$byday = $ruleData['BYDAY'];
				
				if ($byday !== null && $byday !== '') {
					if (is_string($byday)) {
						$days = explode(',', $byday);
					} elseif (is_array($byday)) {
						$days = $byday;
					} else {
						$days = [$byday];
					}
					
					foreach ($days as $day) {
						// Parse Nth weekday (e.g., "1SU", "2MO", "-1FR")
						if (preg_match('/^(-?\d+)([A-Z]{2})$/', $day, $matches)) {
							$nth = $matches[1];
							$weekday = $matches[2];
							
							if ($weekdayPattern === null) {
								$weekdayPattern = $weekday;
							} elseif ($weekdayPattern !== $weekday) {
								$canSimplify = false;
							}
							
							$allByDays[] = $day;
						} else {
							$canSimplify = false;
						}
					}
				}
			}
			
			// Check if we have all Nth occurrences (1-5 and -1 to -5) for the same weekday
			if ($canSimplify && $weekdayPattern !== null && count($groupRules) >= 5) {
				// Count unique Nth values
				$nthValues = [];
				foreach ($allByDays as $day) {
					if (preg_match('/^(-?\d+)([A-Z]{2})$/', $day, $matches)) {
						$nthValues[] = (int)$matches[1];
					}
				}
				$nthValues = array_unique($nthValues);
				
				// If we have enough different Nth values, it's effectively "every weekday"
				if (count($nthValues) >= 5) {
					// Convert to weekly rule
					$allHours = array_unique($group['byhours']);
					sort($allHours);
					
					$byminute = null;
					$bysecond = null;
					// Get byminute and bysecond from first rule (assuming they're the same)
					if (count($groupRules) > 0) {
						$firstRuleData = $groupRules[0]->getRule();
						$byminute = $firstRuleData['BYMINUTE'] ?? null;
						$bysecond = $firstRuleData['BYSECOND'] ?? null;
					}
					
					$mergedRule = $this->createWeeklyRule(
						[$weekdayPattern],
						1, // weekly interval
						$signature['count'],
						$signature['until'],
						count($allHours) > 0 ? implode(',', $allHours) : null,
						$byminute,
						$bysecond,
						'MO'
					);
					
					$mergedRules[] = $mergedRule;
					continue;
				}
			}
			
			// Can't simplify, keep original rules
			$mergedRules = array_merge($mergedRules, $groupRules);
		}

		return $mergedRules;
	}

	/**
	 * Get the week identifier for a date string (year-week format).
	 *
	 * @param string $dateStr Date string in RFC format
	 * @return string Week identifier (e.g., "2025-52")
	 */
	private function getWeekOfDate(string $dateStr): string
	{
		// Parse the date string (e.g., "20251227T000000Z")
		if (preg_match('/^(\d{4})(\d{2})(\d{2})/', $dateStr, $matches)) {
			$year = $matches[1];
			$month = $matches[2];
			$day = $matches[3];
			
			$date = new \DateTime("{$year}-{$month}-{$day}");
			return $date->format('o-W'); // ISO-8601 year and week number
		}
		
		return $dateStr; // Fallback
	}

	/**
	 * Merge BYDAY values from multiple rules.
	 *
	 * @param array $rules Array of RRule objects
	 * @return array Merged BYDAY values
	 */
	private function mergeByDayValues(array $rules): array
	{
		$allDays = [];
		foreach ($rules as $rule) {
			$ruleData = $rule->getRule();
			$byday = $ruleData['BYDAY'];
			
			if ($byday !== null && $byday !== '') {
				if (is_string($byday)) {
					$days = explode(',', $byday);
				} elseif (is_array($byday)) {
					$days = $byday;
				} else {
					$days = [$byday];
				}
				
				foreach ($days as $day) {
					$allDays[] = $day;
				}
			}
		}
		
		$allDays = array_unique($allDays);
		
		// Sort by weekday order
		$dayOrder = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7];
		usort($allDays, function($a, $b) use ($dayOrder) {
			// Extract the weekday part (last 2 characters)
			$weekdayA = substr($a, -2);
			$weekdayB = substr($b, -2);
			
			$orderA = $dayOrder[$weekdayA] ?? 999;
			$orderB = $dayOrder[$weekdayB] ?? 999;
			
			return $orderA <=> $orderB;
		});
		
		return $allDays;
	}

	/**
	 * Create a new weekly RRule.
	 *
	 * @param array $byday Array of weekday values
	 * @param int $interval Interval
	 * @param int|null $count Count
	 * @param string|null $until Until date
	 * @param string|null $byhour Hour constraint
	 * @param string|null $byminute Minute constraint
	 * @param string|null $bysecond Second constraint
	 * @param string $wkst Week start
	 * @return RRule
	 */
	private function createWeeklyRule(
		array $byday,
		int $interval,
		?int $count,
		?string $until,
		?string $byhour,
		?string $byminute,
		?string $bysecond,
		string $wkst
	): RRule {
		$parts = [
			'FREQ' => 'WEEKLY',
			'BYDAY' => implode(',', $byday),
			'INTERVAL' => $interval,
			'WKST' => $wkst,
		];
		
		if ($count !== null) {
			$parts['COUNT'] = $count;
		}
		if ($until !== null) {
			$parts['UNTIL'] = $until;
		}
		if ($byhour !== null && $byhour !== '') {
			$parts['BYHOUR'] = $byhour;
		}
		if ($byminute !== null && $byminute !== '') {
			$parts['BYMINUTE'] = $byminute;
		}
		if ($bysecond !== null && $bysecond !== '') {
			$parts['BYSECOND'] = $bysecond;
		}
		
		return new RRule($parts);
	}

	/**
	 * Create a new yearly RRule.
	 *
	 * @param array $bymonth Array of month values
	 * @param string|null $byday Day constraint
	 * @param int $interval Interval
	 * @param int|null $count Count
	 * @param string|null $until Until date
	 * @param string|null $byhour Hour constraint
	 * @param string|null $byminute Minute constraint
	 * @param string|null $bysecond Second constraint
	 * @return RRule
	 */
	private function createYearlyRule(
		array $bymonth,
		?string $byday,
		int $interval,
		?int $count,
		?string $until,
		?string $byhour,
		?string $byminute,
		?string $bysecond
	): RRule {
		$parts = [
			'FREQ' => 'YEARLY',
			'BYMONTH' => implode(',', $bymonth),
			'INTERVAL' => $interval,
		];
		
		if ($byday !== null && $byday !== '') {
			$parts['BYDAY'] = $byday;
		}
		if ($count !== null) {
			$parts['COUNT'] = $count;
		}
		if ($until !== null) {
			$parts['UNTIL'] = $until;
		}
		if ($byhour !== null && $byhour !== '') {
			$parts['BYHOUR'] = $byhour;
		}
		if ($byminute !== null && $byminute !== '') {
			$parts['BYMINUTE'] = $byminute;
		}
		if ($bysecond !== null && $bysecond !== '') {
			$parts['BYSECOND'] = $bysecond;
		}
		
		return new RRule($parts);
	}
}