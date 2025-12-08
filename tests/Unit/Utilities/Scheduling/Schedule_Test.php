<?php
/**
 * Unit tests for the Schedule class
 * @package TouchPointWP\Tests\Unit\Utilities\Scheduling
 */

namespace tp\TouchPointWP\Tests\Unit\Utilities\Scheduling;

use tp\TouchPointWP\Utilities\Scheduling\Schedule;
use PHPUnit\Framework\TestCase;

class Schedule_Test extends TestCase
{
	public function test_init(): void
	{
		$rruleString = "FREQ=WEEKLY;COUNT=10;BYDAY=MO,WE,FR";
		$schedule = new Schedule($rruleString);

		$this->assertInstanceOf(Schedule::class, $schedule);
		$this->assertEquals(10, $schedule->getCount());
		$this->assertEquals(['MO', 'WE', 'FR'], $schedule->getByDay());
	}
}


