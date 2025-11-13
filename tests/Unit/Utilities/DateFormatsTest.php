<?php
/**
 * Tests for the DateFormats class
 *
 * @package TouchPointWP\Tests\Unit\Utilities
 */

namespace tp\TouchPointWP\Tests\Unit\Utilities;

use Brain\Monkey;
use tp\TouchPointWP\Utilities;
use tp\TouchPointWP\Utilities\DateFormats;
use tp\TouchPointWP\Tests\TestCase;

/**
 * Test case for the DateFormats class.
 *
 * @covers \tp\TouchPointWP\Utilities\DateFormats
 */
class DateFormatsTest extends TestCase
{
    /**
     * Test timestampWithoutOffset returns 0 for null.
     */
    public function test_timestamp_without_offset_returns_zero_for_null(): void
    {
        $result = DateFormats::timestampWithoutOffset(null);
        
        $this->assertSame(0, $result);
    }

    /**
     * Test timestampWithoutOffset converts datetime to UTC timestamp.
     */
    public function test_timestamp_without_offset_converts_to_utc(): void
    {
        $dt = new \DateTimeImmutable('2025-01-15 10:30:00', new \DateTimeZone('America/New_York'));
        $result = DateFormats::timestampWithoutOffset($dt);
        
        // Should convert to UTC timestamp
        $this->assertIsInt($result);
        $this->assertGreaterThan(0, $result);
        
        // Verify the conversion happened by checking the timestamp
        $utcDt = $dt->setTimezone(new \DateTimeZone('UTC'));
        $this->assertSame($utcDt->getTimestamp(), $result);
    }

    /**
     * Test timestampAndOffset returns 0 for null.
     */
    public function test_timestamp_and_offset_returns_zero_for_null(): void
    {
        $result = DateFormats::timestampAndOffset(null);
        
        $this->assertSame(0, $result);
    }

    /**
     * Test timestampAndOffset includes offset.
     */
    public function test_timestamp_and_offset_includes_offset(): void
    {
        $dt = new \DateTimeImmutable('2025-01-15 10:30:00', new \DateTimeZone('America/New_York'));
        $result = DateFormats::timestampAndOffset($dt);
        
        $expected = $dt->getTimestamp() + $dt->getOffset();
        $this->assertSame($expected, $result);
    }

    /**
     * Test TimeStringFormatted calls wp_date and applies filter.
     */
    public function test_time_string_formatted_calls_wp_date(): void
    {
        $dt = new \DateTimeImmutable('2025-01-15 10:30:00', new \DateTimeZone('UTC'));
        
        // Mock get_option for time_format
        Monkey\Functions\when('get_option')
            ->justReturn('g:i a');
        
        // Mock wp_date
        Monkey\Functions\expect('wp_date')
            ->once()
            ->with('g:i a', \Mockery::type('int'))
            ->andReturn('10:30 am');
        
        // apply_filters is already mocked in TestCase base class to return arg 2
        
        $result = DateFormats::TimeStringFormatted($dt);
        
        $this->assertSame('10:30 am', $result);
    }

    /**
     * Test TimeRangeStringFormatted formats a time range.
     */
    public function test_time_range_string_formatted(): void
    {
        $startDt = new \DateTimeImmutable('2025-01-15 10:30:00', new \DateTimeZone('UTC'));
        $endDt = new \DateTimeImmutable('2025-01-15 12:00:00', new \DateTimeZone('UTC'));
        
        // Mock get_option for time_format
        Monkey\Functions\when('get_option')
            ->justReturn('g:i a');
        
        // Mock wp_date calls
        Monkey\Functions\when('wp_date')
            ->alias(function($format, $timestamp) {
                $dt = new \DateTimeImmutable('@' . $timestamp);
                return $dt->format('g:i a');
            });
        
        // Mock __ for translation - return the text with the ndash
        Monkey\Functions\when('__')
            ->justReturn('%1$s – %2$s'); // This is an en-dash
        
        // Mock wp_sprintf
        Monkey\Functions\when('wp_sprintf')
            ->alias(function($format, ...$args) {
                return sprintf($format, ...$args);
            });
        
        $result = DateFormats::TimeRangeStringFormatted($startDt, $endDt);
        
        $this->assertIsString($result);
        $this->assertStringContainsString('–', $result); // en-dash
    }

    /**
     * Test DateStringFormatted returns "Today" for current date (before 5pm).
     */
    public function test_date_string_formatted_returns_today(): void
    {
        // Create a datetime for the mocked "today" (2025-11-12) at 3pm
        $today = new \DateTimeImmutable('2025-11-12 15:00:00', new \DateTimeZone('UTC'));
        
        // Mock translation function
        Monkey\Functions\when('__')
            ->alias(function($text, $domain = 'default') {
                return $text;
            });
        
        $result = DateFormats::DateStringFormatted($today);
        
        $this->assertSame('Today', $result);
    }

    /**
     * Test DateStringFormatted returns "Tonight" for today evening.
     */
    public function test_date_string_formatted_returns_tonight(): void
    {
        // Create a datetime for the mocked "today" (2025-11-12) at 6pm (after 5pm)
        $tonight = new \DateTimeImmutable('2025-11-12 18:00:00', new \DateTimeZone('UTC'));
        
        // Mock translation function
        Monkey\Functions\when('__')
            ->alias(function($text, $domain = 'default') {
                return $text;
            });
        
        $result = DateFormats::DateStringFormatted($tonight);
        
        $this->assertSame('Tonight', $result);
    }

    /**
     * Test DateStringFormatted returns "Tomorrow" for next day.
     */
    public function test_date_string_formatted_returns_tomorrow(): void
    {
        // Create a datetime for tomorrow relative to mocked now (2025-11-13)
        $tomorrow = new \DateTimeImmutable('2025-11-13 12:00:00', new \DateTimeZone('UTC'));
        
        // Mock translation function
        Monkey\Functions\when('__')
            ->alias(function($text, $domain = 'default') {
                return $text;
            });
        
        $result = DateFormats::DateStringFormatted($tomorrow);
        
        $this->assertSame('Tomorrow', $result);
    }

    /**
     * Test DateStringFormatted returns "Yesterday" for previous day.
     */
    public function test_date_string_formatted_returns_yesterday(): void
    {
        // Create a datetime for yesterday relative to mocked now (2025-11-11)
        $yesterday = new \DateTimeImmutable('2025-11-11 12:00:00', new \DateTimeZone('UTC'));
        
        // Mock translation function
        Monkey\Functions\when('__')
            ->alias(function($text, $domain = 'default') {
                return $text;
            });
        
        $result = DateFormats::DateStringFormatted($yesterday);
        
        $this->assertSame('Yesterday', $result);
    }

    /**
     * Test DateStringFormatted handles future dates in current year.
     */
    public function test_date_string_formatted_future_date_current_year(): void
    {
        // Create a date in the future within the current year
        $futureDate = new \DateTimeImmutable('+30 days');
        
        // Mock translation functions
        Monkey\Functions\when('_x')
            ->alias(function($text) {
                return $text;
            });
        
        Monkey\Functions\when('wp_date')
            ->alias(function($format, $timestamp) {
                $dt = new \DateTimeImmutable('@' . $timestamp);
                return $dt->format('l F j');
            });
        
        $result = DateFormats::DateStringFormatted($futureDate);
        
        $this->assertIsString($result);
        // Result should contain day and date info
        $this->assertNotEmpty($result);
    }

    /**
     * Test that timestampWithoutOffset handles different timezones correctly.
     */
    public function test_timestamp_without_offset_handles_timezones(): void
    {
        // Create same moment in different timezones
        $dtUTC = new \DateTimeImmutable('2025-01-15 15:00:00', new \DateTimeZone('UTC'));
        $dtNY = new \DateTimeImmutable('2025-01-15 10:00:00', new \DateTimeZone('America/New_York'));
        
        $timestampUTC = DateFormats::timestampWithoutOffset($dtUTC);
        $timestampNY = DateFormats::timestampWithoutOffset($dtNY);
        
        // Both should return the same timestamp when representing the same moment
        $this->assertSame($timestampUTC, $timestampNY);
    }
}
