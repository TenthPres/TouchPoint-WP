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
		$set = new ScheduleSet("FREQ=WEEKLY;BYDAY=MO,WE;COUNT=10");
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
		$set = new ScheduleSet("FREQ=DAILY;COUNT=10");
		$set->addRRule("FREQ=WEEKLY;BYDAY=MO,WE;COUNT=5");
		$set->mergeIfPossible();

		$this->assertCount(2, $set->getRRules());
	}

	/**
	 * Test merging two rules where one has a defined end date
	 */
	public function test_merge_withEndDate(): void
	{
		$set = new ScheduleSet("FREQ=WEEKLY;BYDAY=MO,WE;UNTIL=20231231T000000Z");
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
}


