<?php
/**
 * Tests for the Utilities class
 *
 * @package TouchPointWP\Tests\Unit
 */

namespace tp\TouchPointWP\Tests\Unit;

use DateTime;
use SimplePie\Parse\Date;
use tp\TouchPointWP\Utilities;
use tp\TouchPointWP\Tests\TestCase;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Test case for the Utilities class.
 *
 * @covers \tp\TouchPointWP\Utilities
 */
class Utilities_Test extends TestCase
{
	/**
	 * Set up before each test.
	 * Reset cached datetime values in Utilities class using reflection.
	 */
	protected function set_up(): void
	{
		parent::set_up();

		// Reset static properties using reflection to ensure clean state
		$reflection = new \ReflectionClass(Utilities::class);

		$properties = [
			'_dateTimeNow',
			'_dateTimeTodayAtMidnight',
			'_dateTimeNowPlus1Y',
			'_dateTimeNowPlus90D',
			'_dateTimeNowPlus1D',
			'_dateTimeNowMinus1D',
			'_utcTimeZone'
		];

		foreach ($properties as $propertyName) {
			$property = $reflection->getProperty($propertyName);
			$property->setAccessible(true);
			$property->setValue(null, null);
		}
	}

	/**
	 * Test toFloatOrNull returns null for non-numeric values.
	 */
	public function test_toFloatOrNull_nonNumeric(): void
	{
		$this->assertNull(Utilities::toFloatOrNull('not a number'));
		$this->assertNull(Utilities::toFloatOrNull('abc'));
		$this->assertNull(Utilities::toFloatOrNull([]));
		$this->assertNull(Utilities::toFloatOrNull(null));
	}

	/**
	 * Test toFloatOrNull converts numeric strings to float.
	 */
	public function test_toFloatOrNull_numericStrings(): void
	{
		$this->assertSame(123.0, Utilities::toFloatOrNull('123'));
		$this->assertSame(123.45, Utilities::toFloatOrNull('123.45'));
		$this->assertSame(-45.67, Utilities::toFloatOrNull('-45.67'));
	}

	/**
	 * Test toFloatOrNull converts integers to float.
	 */
	public function test_toFloatOrNull_integers(): void
	{
		$this->assertSame(123.0, Utilities::toFloatOrNull(123));
		$this->assertSame(0.0, Utilities::toFloatOrNull(0));
		$this->assertSame(-456.0, Utilities::toFloatOrNull(-456));
	}

	/**
	 * Test toFloatOrNull with rounding.
	 */
	public function test_toFloatOrNull_rounding(): void
	{
		$this->assertSame(123.46, Utilities::toFloatOrNull(123.456, 2));
		$this->assertSame(123.5, Utilities::toFloatOrNull(123.456, 1));
		$this->assertSame(123.0, Utilities::toFloatOrNull(123.456, 0));
	}

	/**
	 * Test dateTimeNow returns DateTimeImmutable.
	 */
	public function test_dateTimeNow_returnsDateTimeImmutable(): void
	{
		$now = Utilities::dateTimeNow();
		/** @noinspection PhpConditionAlreadyCheckedInspection */
		$this->assertInstanceOf(DateTimeImmutable::class, $now);
	}

	/**
	 * Test dateTimeNow caches the result.
	 */
	public function test_dateTimeNow_cachesResult(): void
	{
		$first = Utilities::dateTimeNow();
		sleep(1); // Ensure time would differ if not cached
		$second = Utilities::dateTimeNow();

		// Should be the exact same instance due to caching
		$this->assertSame($first, $second);
	}

	/**
	 * Test dateTimeTodayAtMidnight returns midnight.
	 */
	public function test_dateTimeTodayAtMidnight(): void
	{
		$midnight = Utilities::dateTimeTodayAtMidnight();

		/** @noinspection PhpConditionAlreadyCheckedInspection */
		$this->assertInstanceOf(DateTimeImmutable::class, $midnight);
		$this->assertSame('00:00', $midnight->format('H:i'));
		$this->assertSame(current_datetime()->format('Y-m-d'), $midnight->format('Y-m-d'));
	}

	/**
	 * Test dateTimeNowPlus1D is approximately one day in the future.
	 */
	public function test_dateTimeNowPlus1D(): void
	{
		$now      = Utilities::dateTimeNow();
		$tomorrow = Utilities::dateTimeNowPlus1D();

		$diff = $tomorrow->getTimestamp() - $now->getTimestamp();

		// Should be approximately 86400 seconds (1 day)
		$this->assertEqualsWithDelta(86400, $diff, 1);
	}

	/**
	 * Test dateTimeNowPlus90D is approximately 90 days in the future.
	 */
	public function test_dateTimeNowPlus90D(): void
	{
		$now    = Utilities::dateTimeNow();
		$future = Utilities::dateTimeNowPlus90D();

		$diff = $future->getTimestamp() - $now->getTimestamp();

		// Should be approximately 7776000 seconds (90 days)
		$this->assertEqualsWithDelta(7776000, $diff, 1);
	}

	/**
	 * Test dateTimeNowPlus1Y is approximately one year in the future.
	 */
	public function test_dateTimeNowPlus1Y(): void
	{
		$now      = Utilities::dateTimeNow();
		$nextYear = Utilities::dateTimeNowPlus1Y();

		$diff = $nextYear->getTimestamp() - $now->getTimestamp();

		// Should be approximately 31536000 seconds (365 days)
		// Allow for leap years
		$this->assertEqualsWithDelta(31536000, $diff, 86400);
	}

	/**
	 * Test dateTimeNowMinus1D is approximately one day in the past.
	 */
	public function test_dateTimeNowMinus1D(): void
	{
		$now       = Utilities::dateTimeNow();
		$yesterday = Utilities::dateTimeNowMinus1D();

		$diff = $now->getTimestamp() - $yesterday->getTimestamp();

		// Should be approximately 86400 seconds (1 day)
		$this->assertEqualsWithDelta(86400, $diff, 1);
	}

	/**
	 * Test utcTimeZone returns UTC timezone.
	 */
	public function test_utcTimeZone(): void
	{
		$utc = Utilities::utcTimeZone();

		/** @noinspection PhpConditionAlreadyCheckedInspection */
		$this->assertInstanceOf(DateTimeZone::class, $utc);
		$this->assertSame('UTC', $utc->getName());
	}

	/**
	 * Test utcTimeZone caches the result.
	 */
	public function test_utcTimeZone_caching(): void
	{
		$first  = Utilities::utcTimeZone();
		$second = Utilities::utcTimeZone();

		// Should be the exact same instance due to caching
		$this->assertSame($first, $second);
	}

	/**
	 * Test getPluralDayOfWeekNameForNumber_noI18n returns correct day names.
	 */
	public function test_getPluralDayOfWeekNameForNumber_noI18n(): void
	{
		$this->assertSame('Sundays', Utilities::getPluralDayOfWeekNameForNumber_noI18n(0));
		$this->assertSame('Mondays', Utilities::getPluralDayOfWeekNameForNumber_noI18n(1));
		$this->assertSame('Tuesdays', Utilities::getPluralDayOfWeekNameForNumber_noI18n(2));
		$this->assertSame('Wednesdays', Utilities::getPluralDayOfWeekNameForNumber_noI18n(3));
		$this->assertSame('Thursdays', Utilities::getPluralDayOfWeekNameForNumber_noI18n(4));
		$this->assertSame('Fridays', Utilities::getPluralDayOfWeekNameForNumber_noI18n(5));
		$this->assertSame('Saturdays', Utilities::getPluralDayOfWeekNameForNumber_noI18n(6));
	}

	/**
	 * Test getPluralDayOfWeekNameForNumber_noI18n handles modulo correctly.
	 */
	public function test_getPluralDayOfWeekNameForNumber_noI18n_gt7(): void
	{
		// Test that numbers > 6 wrap around
		$this->assertSame('Sundays', Utilities::getPluralDayOfWeekNameForNumber_noI18n(7));
		$this->assertSame('Mondays', Utilities::getPluralDayOfWeekNameForNumber_noI18n(8));
		$this->assertSame('Sundays', Utilities::getPluralDayOfWeekNameForNumber_noI18n(14));
	}

	/**
	 * Test converting numeric strings to floats with Geo coordinates.
	 */
	public function test_toFloatOrNull_string(): void
	{
		// Convert string coordinates to floats
		$lat = Utilities::toFloatOrNull('40.7128', 4);
		$lng = Utilities::toFloatOrNull('-74.0060', 4);

		$this->assertSame(40.7128, $lat);
		$this->assertSame(-74.0060, $lng);
	}

	/**
	 * Test getPluralDayOfWeekNameForNumber returns correct day names.
	 */
	public function test_getPluralDayOfWeekNameForNumber(): void
	{
		$this->assertSame('Sundays', Utilities::getPluralDayOfWeekNameForNumber(0));
		$this->assertSame('Mondays', Utilities::getPluralDayOfWeekNameForNumber(1));
		$this->assertSame('Tuesdays', Utilities::getPluralDayOfWeekNameForNumber(2));
		$this->assertSame('Wednesdays', Utilities::getPluralDayOfWeekNameForNumber(3));
		$this->assertSame('Thursdays', Utilities::getPluralDayOfWeekNameForNumber(4));
		$this->assertSame('Fridays', Utilities::getPluralDayOfWeekNameForNumber(5));
		$this->assertSame('Saturdays', Utilities::getPluralDayOfWeekNameForNumber(6));
	}

	/**
	 * Test getPluralDayOfWeekNameForNumber returns correct day names.
	 */
	public function test_getPluralDayOfWeekNameForNumber_gt7(): void
	{
		$this->assertSame('Saturdays', Utilities::getPluralDayOfWeekNameForNumber(13));
	}

	/**
	 * Test getDayOfWeekShortForNumber returns correct day names.
	 */
	public function test_getDayOfWeekShortForNumber(): void
	{
		$this->assertSame('Sun', Utilities::getDayOfWeekShortForNumber(0));
		$this->assertSame('Mon', Utilities::getDayOfWeekShortForNumber(1));
		$this->assertSame('Tue', Utilities::getDayOfWeekShortForNumber(2));
		$this->assertSame('Wed', Utilities::getDayOfWeekShortForNumber(3));
		$this->assertSame('Thu', Utilities::getDayOfWeekShortForNumber(4));
		$this->assertSame('Fri', Utilities::getDayOfWeekShortForNumber(5));
		$this->assertSame('Sat', Utilities::getDayOfWeekShortForNumber(6));
	}

	/**
	 * Test getDayOfWeekShortForNumber returns correct day names.
	 */
	public function test_getDayOfWeekShortForNumber_gt7(): void
	{
		$this->assertSame('Sat', Utilities::getDayOfWeekShortForNumber(13));
	}

	/**
	 * Test getDayOfWeekShortForNumber_noi18n returns correct day names.
	 */
	public function test_getDayOfWeekShortForNumber_noI18n(): void
	{
		$this->assertSame('Sun', Utilities::getDayOfWeekShortForNumber_noI18n(0));
		$this->assertSame('Mon', Utilities::getDayOfWeekShortForNumber_noI18n(1));
		$this->assertSame('Tue', Utilities::getDayOfWeekShortForNumber_noI18n(2));
		$this->assertSame('Wed', Utilities::getDayOfWeekShortForNumber_noI18n(3));
		$this->assertSame('Thu', Utilities::getDayOfWeekShortForNumber_noI18n(4));
		$this->assertSame('Fri', Utilities::getDayOfWeekShortForNumber_noI18n(5));
		$this->assertSame('Sat', Utilities::getDayOfWeekShortForNumber_noI18n(6));
	}

	/**
	 * Test getDayOfWeekShortForNumber_noI18n returns correct day names.
	 */
	public function test_getDayOfWeekShortForNumber_noI18n_gt7(): void
	{
		$this->assertSame('Sat', Utilities::getDayOfWeekShortForNumber_noI18n(13));
	}

	/**
	 * Test getTimeOfDayTermForTime returns correct terms.
	 */
	public function test_getTimeOfDayTermForTime(): void
	{
		$this->assertSame('Early Morning', Utilities::getTimeOfDayTermForTime(new DateTime('05:30')));
		$this->assertSame('Morning', Utilities::getTimeOfDayTermForTime(new DateTime('08:30')));
		$this->assertSame('Midday', Utilities::getTimeOfDayTermForTime(new DateTime('12:30')));
		$this->assertSame('Afternoon', Utilities::getTimeOfDayTermForTime(new DateTime('13:15')));
		$this->assertSame('Evening', Utilities::getTimeOfDayTermForTime(new DateTime('19:45')));
		$this->assertSame('Night', Utilities::getTimeOfDayTermForTime(new DateTime('21:00')));
		$this->assertSame('Late Night', Utilities::getTimeOfDayTermForTime(new DateTime('23:00')));
	}

	/**
	 * Test getTimeOfDayTermForTime_noI18n returns correct terms.
	 */
	public function test_getTimeOfDayTermForTime_noI18n(): void
	{
		$this->assertSame('Early Morning', Utilities::getTimeOfDayTermForTime_noI18n(new DateTime('05:30')));
		$this->assertSame('Morning', Utilities::getTimeOfDayTermForTime_noI18n(new DateTime('08:30')));
		$this->assertSame('Midday', Utilities::getTimeOfDayTermForTime_noI18n(new DateTime('12:30')));
		$this->assertSame('Afternoon', Utilities::getTimeOfDayTermForTime_noI18n(new DateTime('13:15')));
		$this->assertSame('Evening', Utilities::getTimeOfDayTermForTime_noI18n(new DateTime('19:45')));
		$this->assertSame('Night', Utilities::getTimeOfDayTermForTime_noI18n(new DateTime('21:00')));
		$this->assertSame('Late Night', Utilities::getTimeOfDayTermForTime_noI18n(new DateTime('23:00')));
	}


	/**
	 * Test stringArrayToListString converts arrays to list strings.
	 */
	public function test_stringArrayToListString(): void
	{
		// Basic test
		$this->assertSame(
			'apple, banana & cherry',
			Utilities::stringArrayToListString(['apple', 'banana', 'cherry'])
		);
	}

	public function test_stringArrayToListString_withLimitAndOthers(): void
	{
		// Test with limit
		$this->assertSame(
			'apple, banana & others',
			Utilities::stringArrayToListString(['apple', 'banana', 'cherry', 'date'], 2, true)
		);

		// Test without "and others" explicitly set
		$this->assertSame(
			'apple, banana & others',
			Utilities::stringArrayToListString(['apple', 'banana', 'cherry'], 2)
		);
	}

	public function test_stringArrayToListString_andComma(): void
	{
		$this->assertSame(
			'John, Paul, George & Ringo; and Peter, James & John',
			Utilities::stringArrayToListString(
				['John, Paul, George & Ringo', 'Peter, James & John']
			)
		);
	}

	public function test_stringArrayToListString_single(): void
	{
		// Test single item
		$this->assertSame(
			'apple',
			Utilities::stringArrayToListString(['apple'])
		);
	}

	public function test_stringArrayToListString_empty(): void
	{

		// Test empty array
		$this->assertSame(
			'',
			Utilities::stringArrayToListString([])
		);
	}
}
