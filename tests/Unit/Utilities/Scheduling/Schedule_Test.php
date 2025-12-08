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
	/**
	 * Test getFreq() method
	 */
	public function test_getFreq(): void
	{
		$schedule = new Schedule("FREQ=WEEKLY;BYDAY=MO");
		$this->assertEquals("WEEKLY", $schedule->getFreq());
		
		$schedule2 = new Schedule("FREQ=MONTHLY;BYDAY=1MO");
		$this->assertEquals("MONTHLY", $schedule2->getFreq());
		
		$schedule3 = new Schedule("FREQ=YEARLY;BYMONTH=1");
		$this->assertEquals("YEARLY", $schedule3->getFreq());
	}
	
	/**
	 * Test getCount() method
	 */
	public function test_getCount(): void
	{
		$schedule = new Schedule("FREQ=WEEKLY;BYDAY=MO;COUNT=10");
		$this->assertEquals(10, $schedule->getCount());
		
		$scheduleNoCount = new Schedule("FREQ=WEEKLY;BYDAY=MO");
		$this->assertNull($scheduleNoCount->getCount());
	}
	
	/**
	 * Test getUntil() method with string date
	 */
	public function test_getUntil_withString(): void
	{
		$schedule = new Schedule("FREQ=WEEKLY;BYDAY=MO;UNTIL=20251231T000000Z");
		$until = $schedule->getUntil();
		$this->assertIsString($until);
		$this->assertEquals("20251231T000000Z", $until);
	}
	
	/**
	 * Test getUntil() method with DateTime object
	 */
	public function test_getUntil_withDateTime(): void
	{
		$schedule = new Schedule([
			'FREQ' => 'WEEKLY',
			'BYDAY' => 'MO',
			'UNTIL' => new \DateTime('2025-12-31 00:00:00')
		]);
		$until = $schedule->getUntil();
		$this->assertIsString($until);
		$this->assertRegExp('/^\d{8}T\d{6}Z$/', $until);
	}
	
	/**
	 * Test getUntil() method with no UNTIL
	 */
	public function test_getUntil_withNull(): void
	{
		$schedule = new Schedule("FREQ=WEEKLY;BYDAY=MO");
		$this->assertNull($schedule->getUntil());
	}
	
	/**
	 * Test getInterval() method
	 */
	public function test_getInterval(): void
	{
		$schedule = new Schedule("FREQ=WEEKLY;BYDAY=MO;INTERVAL=2");
		$this->assertEquals(2, $schedule->getInterval());
		
		$scheduleDefault = new Schedule("FREQ=WEEKLY;BYDAY=MO");
		$this->assertEquals(1, $scheduleDefault->getInterval());
	}
	
	/**
	 * Test getByDay() method with string
	 */
	public function test_getByDay_withString(): void
	{
		$schedule = new Schedule("FREQ=WEEKLY;BYDAY=MO,WE,FR");
		$this->assertEquals(['MO', 'WE', 'FR'], $schedule->getByDay());
		
		$singleDay = new Schedule("FREQ=WEEKLY;BYDAY=MO");
		$this->assertEquals(['MO'], $singleDay->getByDay());
	}
	
	/**
	 * Test getByDay() method with null
	 */
	public function test_getByDay_withNull(): void
	{
		$schedule = new Schedule("FREQ=DAILY;COUNT=10");
		$this->assertNull($schedule->getByDay());
	}
	
	/**
	 * Test getByMonth() method with string
	 */
	public function test_getByMonth_withString(): void
	{
		$schedule = new Schedule("FREQ=YEARLY;BYMONTH=1,3,9,11");
		$this->assertEquals([1, 3, 9, 11], $schedule->getByMonth());
	}
	
	/**
	 * Test getByMonth() method with single value
	 */
	public function test_getByMonth_withSingleValue(): void
	{
		$schedule = new Schedule("FREQ=YEARLY;BYMONTH=6");
		$this->assertEquals([6], $schedule->getByMonth());
	}
	
	/**
	 * Test getByMonth() method with null
	 */
	public function test_getByMonth_withNull(): void
	{
		$schedule = new Schedule("FREQ=WEEKLY;BYDAY=MO");
		$this->assertNull($schedule->getByMonth());
	}
	
	/**
	 * Test getByHour() method with string
	 */
	public function test_getByHour_withString(): void
	{
		$schedule = new Schedule("FREQ=WEEKLY;BYDAY=MO;BYHOUR=9,11");
		$this->assertEquals([9, 11], $schedule->getByHour());
	}
	
	/**
	 * Test getByHour() method with single value
	 */
	public function test_getByHour_withSingleValue(): void
	{
		$schedule = new Schedule("FREQ=WEEKLY;BYDAY=MO;BYHOUR=10");
		$this->assertEquals([10], $schedule->getByHour());
	}
	
	/**
	 * Test getByHour() method with null
	 */
	public function test_getByHour_withNull(): void
	{
		$schedule = new Schedule("FREQ=WEEKLY;BYDAY=MO");
		$this->assertNull($schedule->getByHour());
	}
	
	/**
	 * Test getByMinute() method with string
	 */
	public function test_getByMinute_withString(): void
	{
		$schedule = new Schedule("FREQ=WEEKLY;BYDAY=MO;BYHOUR=9;BYMINUTE=0,30");
		$this->assertEquals([0, 30], $schedule->getByMinute());
	}
	
	/**
	 * Test getByMinute() method with null
	 */
	public function test_getByMinute_withNull(): void
	{
		$schedule = new Schedule("FREQ=WEEKLY;BYDAY=MO");
		$this->assertNull($schedule->getByMinute());
	}
	
	/**
	 * Test getBySecond() method with string
	 */
	public function test_getBySecond_withString(): void
	{
		$schedule = new Schedule("FREQ=WEEKLY;BYDAY=MO;BYHOUR=9;BYMINUTE=0;BYSECOND=0,30");
		$this->assertEquals([0, 30], $schedule->getBySecond());
	}
	
	/**
	 * Test getBySecond() method with null
	 */
	public function test_getBySecond_withNull(): void
	{
		$schedule = new Schedule("FREQ=WEEKLY;BYDAY=MO");
		$this->assertNull($schedule->getBySecond());
	}
}


