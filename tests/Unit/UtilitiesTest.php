<?php
/**
 * Tests for the Utilities class
 *
 * @package TouchPointWP\Tests\Unit
 */

namespace tp\TouchPointWP\Tests\Unit;

use tp\TouchPointWP\Utilities;
use tp\TouchPointWP\Tests\TestCase;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Test case for the Utilities class.
 *
 * @covers \tp\TouchPointWP\Utilities
 */
class UtilitiesTest extends TestCase
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
    public function test_to_float_or_null_returns_null_for_non_numeric(): void
    {
        $this->assertNull(Utilities::toFloatOrNull('not a number'));
        $this->assertNull(Utilities::toFloatOrNull('abc'));
        $this->assertNull(Utilities::toFloatOrNull([]));
        $this->assertNull(Utilities::toFloatOrNull(null));
    }

    /**
     * Test toFloatOrNull converts numeric strings to float.
     */
    public function test_to_float_or_null_converts_numeric_strings(): void
    {
        $this->assertSame(123.0, Utilities::toFloatOrNull('123'));
        $this->assertSame(123.45, Utilities::toFloatOrNull('123.45'));
        $this->assertSame(-45.67, Utilities::toFloatOrNull('-45.67'));
    }

    /**
     * Test toFloatOrNull converts integers to float.
     */
    public function test_to_float_or_null_converts_integers(): void
    {
        $this->assertSame(123.0, Utilities::toFloatOrNull(123));
        $this->assertSame(0.0, Utilities::toFloatOrNull(0));
        $this->assertSame(-456.0, Utilities::toFloatOrNull(-456));
    }

    /**
     * Test toFloatOrNull with rounding.
     */
    public function test_to_float_or_null_with_rounding(): void
    {
        $this->assertSame(123.46, Utilities::toFloatOrNull(123.456, 2));
        $this->assertSame(123.5, Utilities::toFloatOrNull(123.456, 1));
        $this->assertSame(123.0, Utilities::toFloatOrNull(123.456, 0));
    }

    /**
     * Test dateTimeNow returns DateTimeImmutable.
     */
    public function test_date_time_now_returns_datetime_immutable(): void
    {
        $now = Utilities::dateTimeNow();
        $this->assertInstanceOf(DateTimeImmutable::class, $now);
    }

    /**
     * Test dateTimeNow caches the result.
     */
    public function test_date_time_now_caches_result(): void
    {
        $first = Utilities::dateTimeNow();
        $second = Utilities::dateTimeNow();
        
        // Should be the exact same instance due to caching
        $this->assertSame($first, $second);
    }

    /**
     * Test dateTimeTodayAtMidnight returns midnight.
     */
    public function test_date_time_today_at_midnight(): void
    {
        $midnight = Utilities::dateTimeTodayAtMidnight();
        
        $this->assertInstanceOf(DateTimeImmutable::class, $midnight);
        $this->assertSame('00:00', $midnight->format('H:i'));
    }

    /**
     * Test dateTimeNowPlus1D is approximately one day in the future.
     */
    public function test_date_time_now_plus_1d(): void
    {
        $now = Utilities::dateTimeNow();
        $tomorrow = Utilities::dateTimeNowPlus1D();
        
        $diff = $tomorrow->getTimestamp() - $now->getTimestamp();
        
        // Should be approximately 86400 seconds (1 day)
        $this->assertEqualsWithDelta(86400, $diff, 1);
    }

    /**
     * Test dateTimeNowPlus90D is approximately 90 days in the future.
     */
    public function test_date_time_now_plus_90d(): void
    {
        $now = Utilities::dateTimeNow();
        $future = Utilities::dateTimeNowPlus90D();
        
        $diff = $future->getTimestamp() - $now->getTimestamp();
        
        // Should be approximately 7776000 seconds (90 days)
        $this->assertEqualsWithDelta(7776000, $diff, 1);
    }

    /**
     * Test dateTimeNowPlus1Y is approximately one year in the future.
     */
    public function test_date_time_now_plus_1y(): void
    {
        $now = Utilities::dateTimeNow();
        $nextYear = Utilities::dateTimeNowPlus1Y();
        
        $diff = $nextYear->getTimestamp() - $now->getTimestamp();
        
        // Should be approximately 31536000 seconds (365 days)
        // Allow for leap years
        $this->assertEqualsWithDelta(31536000, $diff, 86400);
    }

    /**
     * Test dateTimeNowMinus1D is approximately one day in the past.
     */
    public function test_date_time_now_minus_1d(): void
    {
        $now = Utilities::dateTimeNow();
        $yesterday = Utilities::dateTimeNowMinus1D();
        
        $diff = $now->getTimestamp() - $yesterday->getTimestamp();
        
        // Should be approximately 86400 seconds (1 day)
        $this->assertEqualsWithDelta(86400, $diff, 1);
    }

    /**
     * Test utcTimeZone returns UTC timezone.
     */
    public function test_utc_time_zone(): void
    {
        $utc = Utilities::utcTimeZone();
        
        $this->assertInstanceOf(DateTimeZone::class, $utc);
        $this->assertSame('UTC', $utc->getName());
    }

    /**
     * Test utcTimeZone caches the result.
     */
    public function test_utc_time_zone_caches_result(): void
    {
        $first = Utilities::utcTimeZone();
        $second = Utilities::utcTimeZone();
        
        // Should be the exact same instance due to caching
        $this->assertSame($first, $second);
    }

    /**
     * Test getPluralDayOfWeekNameForNumber_noI18n returns correct day names.
     */
    public function test_get_plural_day_of_week_name_for_number_no_i18n(): void
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
    public function test_get_plural_day_of_week_name_handles_modulo(): void
    {
        // Test that numbers > 6 wrap around
        $this->assertSame('Sundays', Utilities::getPluralDayOfWeekNameForNumber_noI18n(7));
        $this->assertSame('Mondays', Utilities::getPluralDayOfWeekNameForNumber_noI18n(8));
        $this->assertSame('Sundays', Utilities::getPluralDayOfWeekNameForNumber_noI18n(14));
    }
}
