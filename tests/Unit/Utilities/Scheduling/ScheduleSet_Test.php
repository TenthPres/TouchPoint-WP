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
		$schedule1 = new Schedule("FREQ=DAILY;COUNT=5");
		$schedule2 = new Schedule("FREQ=WEEKLY;COUNT=3;BYDAY=MO,WE,FR");

		$scheduleSet = new ScheduleSet([$schedule1, $schedule2]);

		$this->assertInstanceOf(ScheduleSet::class, $scheduleSet);
		$this->assertCount(2, $scheduleSet->getSchedules());
		$this->assertSame($schedule1, $scheduleSet->getSchedules()[0]);
		$this->assertSame($schedule2, $scheduleSet->getSchedules()[1]);
	}

	/**
	 * Test merging two ScheduleSet objects that are both weekly with no start or end date
	 */
	public function test_merge_weeklyOpenEnded(): void
	{
		$schedule1 = new Schedule("FREQ=WEEKLY;BYDAY=MO,WE");
		$schedule2 = new Schedule("FREQ=WEEKLY;BYDAY=FR");

		$scheduleSet1 = new ScheduleSet([$schedule1]);
		$scheduleSet2 = new ScheduleSet([$schedule2]);

		$mergedSet = $scheduleSet1->merge($scheduleSet2);

		$this->assertCount(1, $mergedSet->getSchedules());
		$mergedSchedule = $mergedSet->getSchedules()[0];
		$this->assertEquals("WEEKLY", $mergedSchedule->getFreq());
		$this->assertEquals(['MO', 'WE', 'FR'], $mergedSchedule->getByDay());
	}

	/**
	 * Test merging two ScheduleSet objects with incompatible frequencies
	 */
	public function test_merge_differentFrequencies(): void
	{
		$schedule1 = new Schedule("FREQ=DAILY;COUNT=5");
		$schedule2 = new Schedule("FREQ=WEEKLY;BYDAY=MO,WE,FR");

		$scheduleSet1 = new ScheduleSet([$schedule1]);
		$scheduleSet2 = new ScheduleSet([$schedule2]);

		$mergedSet = $scheduleSet1->merge($scheduleSet2);

		$this->assertCount(2, $mergedSet->getSchedules());
		$this->assertSame($schedule1, $mergedSet->getSchedules()[0]);
		$this->assertSame($schedule2, $mergedSet->getSchedules()[1]);
	}

	/**
	 * Test merging two ScheduleSet objects where one has a defined end date
	 */
	public function test_merge_withEndDate(): void
	{
		$schedule1 = new Schedule("FREQ=WEEKLY;BYDAY=MO,WE");
		$schedule2 = new Schedule("FREQ=WEEKLY;BYDAY=FR;UNTIL=20231231T000000Z");

		$scheduleSet1 = new ScheduleSet([$schedule1]);
		$scheduleSet2 = new ScheduleSet([$schedule2]);

		$mergedSet = $scheduleSet1->merge($scheduleSet2);

		$this->assertCount(2, $mergedSet->getSchedules());
		$this->assertSame($schedule1, $mergedSet->getSchedules()[0]);
		$this->assertSame($schedule2, $mergedSet->getSchedules()[1]);
	}

	/**
	 * Schedules for each: annual on second saturday of January, March, September, and November.  Other details are the same, so should be merge to one schedule.
	 */
	public function test_merge_annualSecondSaturdayMultipleMonths(): void
	{
		$schedule1 = new Schedule("FREQ=YEARLY;BYDAY=2SA;BYMONTH=1");
		$schedule2 = new Schedule("FREQ=YEARLY;BYDAY=2SA;BYMONTH=1");
		$schedule3 = new Schedule("FREQ=YEARLY;BYDAY=2SA;BYMONTH=9");
		$schedule4 = new Schedule("FREQ=YEARLY;BYDAY=2SA;BYMONTH=11");

		$scheduleSet1 = new ScheduleSet([$schedule1]);
		$scheduleSet2 = new ScheduleSet([$schedule2]);
		$scheduleSet3 = new ScheduleSet([$schedule3]);
		$scheduleSet4 = new ScheduleSet([$schedule4]);

		$mergedSet = $scheduleSet1->merge($scheduleSet2);
		$mergedSet = $mergedSet->merge($scheduleSet3);
		$mergedSet = $mergedSet->merge($scheduleSet4);

		$this->assertCount(1, $mergedSet->getSchedules());
		$mergedSchedule = $mergedSet->getSchedules()[0];
		$this->assertEquals("YEARLY", $mergedSchedule->getFreq());
		$this->assertEquals(['2SA'], $mergedSchedule->getByDay());
		$this->assertEquals([1, 3, 9, 11], $mergedSchedule->getByMonth());
	}

	/**
	 * Schedules for 9 and 11 am on the Nth Sunday of the month, every month (for all Ns 1-5).  Should merge to one WEEKLY schedule with both hours and all Nth Sundays.
	 */
	public function test_merge_monthlyNthSundayMultipleTimes(): void
	{
		$scheds = [];
		for ($n = 1; $n <= 5; $n++) {
			$scheds[] = new Schedule("FREQ=MONTHLY;BYDAY={$n}SU;BYHOUR=9");
			$scheds[] = new Schedule("FREQ=MONTHLY;BYDAY={$n}SU;BYHOUR=11");
		}

		$merged = new ScheduleSet([]);
		foreach ($scheds as $sched) {
			$merged = $merged->merge(new ScheduleSet([$sched]));
		}
		$this->assertCount(1, $merged->getSchedules());
		$mergedSchedule = $merged->getSchedules()[0];
		$this->assertEquals("WEEKLY", $mergedSchedule->getFreq());
		$this->assertEquals(['SU'], $mergedSchedule->getByDay());
		$this->assertEquals([9, 11], $mergedSchedule->getByHour());
	}
}


