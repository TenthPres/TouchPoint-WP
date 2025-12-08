<?php
/**
 * Unit tests for the ScheduleSet class
 * @package TouchPointWP\Tests\Unit\Utilities\Scheduling
 */

namespace tp\TouchPointWP\Tests\Unit\Utilities\Scheduling;

use tp\TouchPointWP\Utilities\Scheduling\ScheduleSet;
use tp\TouchPointWP\Utilities\Scheduling\Schedule;
use PHPUnit\Framework\TestCase;

class ScheduleSet_Test extends TestCase
{
	/**
	 * Initialize ScheduleSet with multiple Schedule objects
	 */
	public function test_init_with_multiple_schedules(): void
	{
		$set = new ScheduleSet();
		$set->addRRule("FREQ=DAILY;COUNT=10");
		$set->addRRule("FREQ=WEEKLY;BYDAY=MO,WE,FR;COUNT=5");

		$set->mergeIfPossible();

		$this->assertInstanceOf(ScheduleSet::class, $set);
		$this->assertCount(2, $set->getRRules());
	}

	/**
	 * Test merging two ScheduleSet objects that are both weekly with no start or end date
	 */
	public function test_merge_weeklyOpenEnded(): void
	{
		$set = new ScheduleSet("RRULE:FREQ=WEEKLY;BYDAY=MO,WE;COUNT=10");
		$set->addRRule("FREQ=WEEKLY;BYDAY=FR;COUNT=10");

		$set->mergeIfPossible();

		$this->assertCount(1, $set->getRRules());
		$this->assertEquals(20, $set->getRRules()[0]->getCount());
		$this->assertEquals(['MO', 'WE', 'FR'], $set->getRRules()[0]->getByDay());
	}

	/**
	 * Test merging two ScheduleSet objects with incompatible frequencies
	 */
	public function test_merge_differentFrequencies(): void
	{
		$set = new ScheduleSet("RRULE:FREQ=DAILY;COUNT=10");
		$set->addRRule("FREQ=WEEKLY;BYDAY=MO,WE;COUNT=5");
		$set->mergeIfPossible();

		$this->assertCount(2, $set->getRRules());
	}

	/**
	 * Test merging two rules where one has a defined end date
	 */
	public function test_merge_withEndDate(): void
	{
		$set = new ScheduleSet("RRULE:FREQ=WEEKLY;BYDAY=MO,WE;UNTIL=20231231T000000Z");
		$set->addRRule("FREQ=WEEKLY;BYDAY=FR;COUNT=10");
		$set->mergeIfPossible();

		$this->assertCount(2, $set->getRRules());
	}

	/**
	 * Test merging two weekly rules, where both have end dates, such that they end the same week and are mergeable.
	 */
	public function test_merge_weeklyWithEndDates(): void
	{
		$schedule1 = new Schedule("FREQ=WEEKLY;BYDAY=MO,WE;UNTIL=20251225T000000Z");
		$schedule2 = new Schedule("FREQ=WEEKLY;BYDAY=FR;UNTIL=20251227T000000Z");

		$set = new ScheduleSet([$schedule1, $schedule2]);

		$set->mergeIfPossible();

		$this->assertCount(1, $set->getRRules());
		$mergedSchedule = $set->getRRules()[0];
		$this->assertEquals("WEEKLY", $mergedSchedule->getFreq());
		$this->assertEquals(['MO', 'WE', 'FR'], $mergedSchedule->getByDay());
		$this->assertEquals('20251227T000000Z', $mergedSchedule->getUntil());
	}

	/**
	 * Schedules for each: annual on second saturday of January, March, September, and November.  Other details are the same, so should be merge to one schedule.
	 */
	public function test_merge_annualSecondSaturdayMultipleMonths(): void
	{
		$set = new ScheduleSet();

		foreach ([1, 3, 9, 11] as $month) {
			$set->addRRule("FREQ=YEARLY;BYMONTH={$month};BYDAY=2SA;BYHOUR=10;BYMINUTE=0;BYSECOND=0");
		}

		$set->mergeIfPossible();

		$this->assertCount(1, $set->getRRules());
		$mergedSchedule = $set->getRRules()[0];
		$this->assertEquals("YEARLY", $mergedSchedule->getFreq());
		$this->assertEquals([1, 3, 9, 11], $mergedSchedule->getByMonth());
		$this->assertEquals(['2SA'], $mergedSchedule->getByDay());
		$this->assertEquals([10], $mergedSchedule->getByHour());
	}

	/**
	 * Schedules for 9 and 11 am on the Nth Sunday of the month, every month (for all Ns 1-5).  Should merge to one WEEKLY schedule with both hours and all Nth Sundays.
	 */
	public function test_merge_monthlyNthSundayMultipleTimes(): void
	{
		$set = new ScheduleSet();
		for ($n = 1; $n <= 5; $n++) {
			$set->addRRule("FREQ=MONTHLY;BYDAY={$n}SU;BYHOUR=9;BYMINUTE=0;BYSECOND=0");
			$set->addRRule("FREQ=MONTHLY;BYDAY=-{$n}SU;BYHOUR=11;BYMINUTE=0;BYSECOND=0");
		}

		$set->mergeIfPossible();

		$this->assertCount(1, $set->getRRules());
		$mergedSchedule = $set->getRRules()[0];
		$this->assertEquals("WEEKLY", $mergedSchedule->getFreq());
		$this->assertEquals(['SU'], $mergedSchedule->getByDay());
		$this->assertEquals([9, 11], $mergedSchedule->getByHour());
	}

	/**
	 * Test end date compatibility: rules that CAN merge because earlier rule has no occurrences between its end and later end
	 */
	public function test_merge_endDateCompatible_noConflict(): void
	{
		// MO ending Dec 22, TU ending Dec 30
		// Monday on Dec 22, next Monday would be Dec 29
		// So Monday rule ending Dec 22 WOULD conflict if extended to Dec 30
		$schedule1 = new Schedule("FREQ=WEEKLY;BYDAY=TU;UNTIL=20251223T000000Z"); // Tuesday Dec 23
		$schedule2 = new Schedule("FREQ=WEEKLY;BYDAY=WE;UNTIL=20251227T000000Z"); // Wednesday Dec 27
		
		$set = new ScheduleSet([$schedule1, $schedule2]);
		$set->mergeIfPossible();
		
		// Should merge because Tuesday (Dec 23) doesn't occur again before Dec 27
		$this->assertCount(1, $set->getRRules());
		$merged = $set->getRRules()[0];
		$this->assertEquals(['TU', 'WE'], $merged->getByDay());
		$this->assertEquals('20251227T000000Z', $merged->getUntil());
	}

	/**
	 * Test end date compatibility: rules that CANNOT merge because earlier rule would have occurrences before later end
	 */
	public function test_merge_endDateIncompatible_withConflict(): void
	{
		// Monday ending Dec 22, Tuesday ending Dec 30
		// If we extend Monday to Dec 30, there would be a Monday on Dec 29
		$schedule1 = new Schedule("FREQ=WEEKLY;BYDAY=MO;UNTIL=20251222T000000Z"); // Monday Dec 22
		$schedule2 = new Schedule("FREQ=WEEKLY;BYDAY=TU;UNTIL=20251230T000000Z"); // Tuesday Dec 30
		
		$set = new ScheduleSet([$schedule1, $schedule2]);
		$set->mergeIfPossible();
		
		// Should NOT merge because Monday rule would have occurrence on Dec 29
		$this->assertCount(2, $set->getRRules());
	}

	/**
	 * Test merging rules with same end date
	 */
	public function test_merge_sameEndDate(): void
	{
		$schedule1 = new Schedule("FREQ=WEEKLY;BYDAY=MO,WE;UNTIL=20251230T000000Z");
		$schedule2 = new Schedule("FREQ=WEEKLY;BYDAY=FR;UNTIL=20251230T000000Z");
		
		$set = new ScheduleSet([$schedule1, $schedule2]);
		$set->mergeIfPossible();
		
		$this->assertCount(1, $set->getRRules());
		$merged = $set->getRRules()[0];
		$this->assertEquals(['MO', 'WE', 'FR'], $merged->getByDay());
		$this->assertEquals('20251230T000000Z', $merged->getUntil());
	}

	/**
	 * Test constructor with array of rules
	 */
	public function test_constructor_withArray(): void
	{
		$schedule1 = new Schedule("FREQ=WEEKLY;BYDAY=MO");
		$schedule2 = new Schedule("FREQ=WEEKLY;BYDAY=TU");
		
		$set = new ScheduleSet([$schedule1, $schedule2]);
		
		$this->assertCount(2, $set->getRRules());
	}

	/**
	 * Test constructor with null
	 */
	public function test_constructor_withNull(): void
	{
		$set = new ScheduleSet();
		$this->assertCount(0, $set->getRRules());
	}

	/**
	 * Test mergeIfPossible with single rule (should not change)
	 */
	public function test_merge_singleRule(): void
	{
		$set = new ScheduleSet("RRULE:FREQ=WEEKLY;BYDAY=MO");
		$set->mergeIfPossible();
		
		$this->assertCount(1, $set->getRRules());
	}

	/**
	 * Test merging yearly rules with different BYMONTH
	 */
	public function test_merge_yearlyDifferentMonths(): void
	{
		$set = new ScheduleSet();
		$set->addRRule("FREQ=YEARLY;BYMONTH=1;BYDAY=1MO;BYHOUR=10");
		$set->addRRule("FREQ=YEARLY;BYMONTH=6;BYDAY=1MO;BYHOUR=10");
		
		$set->mergeIfPossible();
		
		$this->assertCount(1, $set->getRRules());
		$merged = $set->getRRules()[0];
		$this->assertEquals("YEARLY", $merged->getFreq());
		$this->assertEquals([1, 6], $merged->getByMonth());
	}

	/**
	 * Test merging monthly rules that don't simplify to weekly
	 */
	public function test_merge_monthlyNotSimplifiedToWeekly(): void
	{
		$set = new ScheduleSet();
		// Only 3 Nth values, not enough to simplify to weekly
		for ($n = 1; $n <= 3; $n++) {
			$set->addRRule("FREQ=MONTHLY;BYDAY={$n}SU;BYHOUR=9");
		}
		
		$set->mergeIfPossible();
		
		// Should stay as MONTHLY rules (not enough coverage for WEEKLY)
		$this->assertCount(3, $set->getRRules());
		foreach ($set->getRRules() as $rule) {
			$ruleData = $rule->getRule();
			$this->assertEquals("MONTHLY", $ruleData['FREQ']);
		}
	}

	/**
	 * Test merging with different intervals (should not merge)
	 */
	public function test_merge_differentIntervals(): void
	{
		$set = new ScheduleSet();
		$set->addRRule("FREQ=WEEKLY;BYDAY=MO;INTERVAL=1;COUNT=10");
		$set->addRRule("FREQ=WEEKLY;BYDAY=TU;INTERVAL=2;COUNT=10");
		
		$set->mergeIfPossible();
		
		$this->assertCount(2, $set->getRRules());
	}

	/**
	 * Test merging with different BYHOUR (should not merge)
	 */
	public function test_merge_differentByHour(): void
	{
		$set = new ScheduleSet();
		$set->addRRule("FREQ=WEEKLY;BYDAY=MO;BYHOUR=9;COUNT=10");
		$set->addRRule("FREQ=WEEKLY;BYDAY=TU;BYHOUR=10;COUNT=10");
		
		$set->mergeIfPossible();
		
		$this->assertCount(2, $set->getRRules());
	}

	/**
	 * Test iterative reduction: Yearly -> Monthly -> Weekly (if applicable)
	 */
	public function test_iterativeReduction_multiLevel(): void
	{
		$set = new ScheduleSet();
		
		// Start with monthly rules that cover all 5 Sundays
		for ($n = 1; $n <= 5; $n++) {
			$set->addRRule("FREQ=MONTHLY;BYDAY={$n}SU;BYHOUR=9");
		}
		
		$set->mergeIfPossible();
		
		// Should be reduced to a single WEEKLY rule
		$this->assertCount(1, $set->getRRules());
		$this->assertEquals("WEEKLY", $set->getRRules()[0]->getFreq());
	}

	/**
	 * Test merging rules with open-ended (no COUNT or UNTIL)
	 */
	public function test_merge_openEnded(): void
	{
		$set = new ScheduleSet();
		$set->addRRule("FREQ=WEEKLY;BYDAY=MO");
		$set->addRRule("FREQ=WEEKLY;BYDAY=TU");
		
		$set->mergeIfPossible();
		
		$this->assertCount(1, $set->getRRules());
		$merged = $set->getRRules()[0];
		$this->assertEquals(['MO', 'TU'], $merged->getByDay());
		$this->assertNull($merged->getCount());
		$this->assertNull($merged->getUntil());
	}

	/**
	 * Test merging yearly rules with incompatible BYDAY
	 */
	public function test_merge_yearlyIncompatibleByDay(): void
	{
		$set = new ScheduleSet();
		$set->addRRule("FREQ=YEARLY;BYMONTH=1;BYDAY=1MO;BYHOUR=10");
		$set->addRRule("FREQ=YEARLY;BYMONTH=6;BYDAY=2MO;BYHOUR=10");
		
		$set->mergeIfPossible();
		
		// Should NOT merge because BYDAY is different
		$this->assertCount(2, $set->getRRules());
	}

	/**
	 * Test merging with COUNT and UNTIL mixed (should not merge)
	 */
	public function test_merge_mixedCountAndUntil(): void
	{
		$set = new ScheduleSet();
		$set->addRRule("FREQ=WEEKLY;BYDAY=MO;COUNT=10");
		$set->addRRule("FREQ=WEEKLY;BYDAY=TU;UNTIL=20251230T000000Z");
		
		$set->mergeIfPossible();
		
		// Should NOT merge because one has COUNT and one has UNTIL
		$this->assertCount(2, $set->getRRules());
	}

	/**
	 * Test constructor with RFC string
	 */
	public function test_constructor_withRFCString(): void
	{
		$set = new ScheduleSet("RRULE:FREQ=WEEKLY;BYDAY=MO;COUNT=10");
		$this->assertCount(1, $set->getRRules());
	}

	/**
	 * Test merging with BYMINUTE and BYSECOND
	 */
	public function test_merge_withByMinuteAndBySecond(): void
	{
		$set = new ScheduleSet();
		$set->addRRule("FREQ=WEEKLY;BYDAY=MO;BYHOUR=9;BYMINUTE=0;BYSECOND=0;COUNT=10");
		$set->addRRule("FREQ=WEEKLY;BYDAY=TU;BYHOUR=9;BYMINUTE=0;BYSECOND=0;COUNT=10");
		
		$set->mergeIfPossible();
		
		$this->assertCount(1, $set->getRRules());
		$merged = $set->getRRules()[0];
		$this->assertEquals(['MO', 'TU'], $merged->getByDay());
	}

	/**
	 * Test merging yearly rules with COUNT
	 */
	public function test_merge_yearlyWithCount(): void
	{
		$set = new ScheduleSet();
		$set->addRRule("FREQ=YEARLY;BYMONTH=1;BYDAY=1MO;COUNT=10");
		$set->addRRule("FREQ=YEARLY;BYMONTH=6;BYDAY=1MO;COUNT=10");
		
		$set->mergeIfPossible();
		
		$this->assertCount(1, $set->getRRules());
		$merged = $set->getRRules()[0];
		$this->assertEquals([1, 6], $merged->getByMonth());
		$this->assertEquals(10, $merged->getCount());
	}

	/**
	 * Test merging yearly rules with UNTIL
	 */
	public function test_merge_yearlyWithUntil(): void
	{
		$set = new ScheduleSet();
		$set->addRRule("FREQ=YEARLY;BYMONTH=1;BYDAY=1MO;UNTIL=20251231T000000Z");
		$set->addRRule("FREQ=YEARLY;BYMONTH=6;BYDAY=1MO;UNTIL=20251231T000000Z");
		
		$set->mergeIfPossible();
		
		$this->assertCount(1, $set->getRRules());
		$merged = $set->getRRules()[0];
		$this->assertEquals([1, 6], $merged->getByMonth());
	}

	/**
	 * Test monthly rules with different weekdays stay separate
	 */
	public function test_merge_monthlyDifferentWeekdays(): void
	{
		$set = new ScheduleSet();
		for ($n = 1; $n <= 5; $n++) {
			$set->addRRule("FREQ=MONTHLY;BYDAY={$n}SU;BYHOUR=9");
		}
		for ($n = 1; $n <= 5; $n++) {
			$set->addRRule("FREQ=MONTHLY;BYDAY={$n}MO;BYHOUR=9");
		}
		
		$set->mergeIfPossible();
		
		// Each set of 5 with same weekday should stay as monthly (different Nth patterns per weekday)
		// They don't simplify because while each has 5 Nth values, they're for different weekdays
		$this->assertCount(10, $set->getRRules());
	}

	/**
	 * Test merging with WKST parameter
	 */
	public function test_merge_withWKST(): void
	{
		$set = new ScheduleSet();
		$set->addRRule("FREQ=WEEKLY;BYDAY=MO;WKST=SU;COUNT=10");
		$set->addRRule("FREQ=WEEKLY;BYDAY=TU;WKST=SU;COUNT=10");
		
		$set->mergeIfPossible();
		
		$this->assertCount(1, $set->getRRules());
	}

	/**
	 * Test merging fails when WKST is different
	 */
	public function test_merge_differentWKST(): void
	{
		$set = new ScheduleSet();
		$set->addRRule("FREQ=WEEKLY;BYDAY=MO;WKST=MO;COUNT=10");
		$set->addRRule("FREQ=WEEKLY;BYDAY=TU;WKST=SU;COUNT=10");
		
		$set->mergeIfPossible();
		
		// Should NOT merge because WKST is different
		$this->assertCount(2, $set->getRRules());
	}

	/**
	 * Test handling of non-standard frequencies
	 */
	public function test_merge_hourlyFrequency(): void
	{
		$set = new ScheduleSet();
		$set->addRRule("FREQ=HOURLY;INTERVAL=2;COUNT=10");
		$set->addRRule("FREQ=HOURLY;INTERVAL=2;COUNT=5");
		
		$set->mergeIfPossible();
		
		// Should keep separate as we don't have special handling for HOURLY
		$this->assertCount(2, $set->getRRules());
	}

	/**
	 * Test constructor with dtstart parameter
	 */
	public function test_constructor_withDtstart(): void
	{
		$dtstart = new \DateTime('2025-01-01 00:00:00');
		$set = new ScheduleSet("RRULE:FREQ=WEEKLY;BYDAY=MO", $dtstart);
		
		$this->assertCount(1, $set->getRRules());
	}

	/**
	 * Test merging when max iterations would be reached (edge case)
	 */
	public function test_merge_maxIterationsProtection(): void
	{
		// This is a theoretical test - in practice we shouldn't hit max iterations
		// Just verify that the method completes without infinite loop
		$set = new ScheduleSet();
		$set->addRRule("FREQ=WEEKLY;BYDAY=MO;COUNT=10");
		$set->addRRule("FREQ=WEEKLY;BYDAY=TU;COUNT=10");
		
		$set->mergeIfPossible();
		
		// Should complete successfully
		$this->assertCount(1, $set->getRRules());
	}
}


