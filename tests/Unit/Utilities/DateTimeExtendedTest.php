<?php
/**
 * Tests for the DateTimeExtended class
 *
 * @package TouchPointWP\Tests\Unit\Utilities
 */

namespace tp\TouchPointWP\Tests\Unit\Utilities;

use Brain\Monkey;
use tp\TouchPointWP\Utilities\DateTimeExtended;
use tp\TouchPointWP\Tests\TestCase;

/**
 * Test case for the DateTimeExtended class.
 *
 * @covers \tp\TouchPointWP\Utilities\DateTimeExtended
 */
class DateTimeExtendedTest extends TestCase
{
    /**
     * Test that DateTimeExtended can be instantiated.
     */
    public function test_instantiation(): void
    {
        $dt = new DateTimeExtended();
        
        $this->assertInstanceOf(DateTimeExtended::class, $dt);
        $this->assertInstanceOf(\DateTimeImmutable::class, $dt);
    }

    /**
     * Test that DateTimeExtended can be instantiated with a specific time.
     */
    public function test_instantiation_with_time(): void
    {
        // Mock date_i18n since format() will be called internally
        Monkey\Functions\when('date_i18n')
            ->alias(function($format, $timestamp) {
                return date($format, $timestamp);
            });
        
        $dt = new DateTimeExtended('2025-01-15 10:30:00');
        
        $this->assertInstanceOf(DateTimeExtended::class, $dt);
        
        // Use PHP's date function to match what we'd expect
        $timestamp = $dt->getTimestamp();
        $this->assertSame('2025', date('Y', $timestamp));
        $this->assertSame('01', date('m', $timestamp));
        $this->assertSame('15', date('d', $timestamp));
    }

    /**
     * Test that DateTimeExtended can be instantiated with a timezone.
     */
    public function test_instantiation_with_timezone(): void
    {
        $timezone = new \DateTimeZone('America/New_York');
        $dt = new DateTimeExtended('2025-01-15 10:30:00', $timezone);
        
        $this->assertInstanceOf(DateTimeExtended::class, $dt);
        $this->assertSame('America/New_York', $dt->getTimezone()->getName());
    }

    /**
     * Test the isAllDay property defaults to false.
     */
    public function test_is_all_day_defaults_to_false(): void
    {
        $dt = new DateTimeExtended();
        
        $this->assertFalse($dt->isAllDay);
    }

    /**
     * Test that isAllDay property can be set.
     */
    public function test_is_all_day_can_be_set(): void
    {
        $dt = new DateTimeExtended();
        $dt->isAllDay = true;
        
        $this->assertTrue($dt->isAllDay);
    }

    /**
     * Test format method uses date_i18n.
     */
    public function test_format_uses_date_i18n(): void
    {
        // Mock date_i18n to return a specific format
        Monkey\Functions\expect('date_i18n')
            ->once()
            ->with('Y-m-d H:i:s', \Mockery::type('int'))
            ->andReturn('2025-01-15 10:30:00');

        $dt = new DateTimeExtended('2025-01-15 10:30:00');
        $result = $dt->format('Y-m-d H:i:s');
        
        $this->assertSame('2025-01-15 10:30:00', $result);
    }

    /**
     * Test format method with different format string.
     */
    public function test_format_with_custom_format(): void
    {
        // Mock date_i18n to return a custom format
        Monkey\Functions\expect('date_i18n')
            ->once()
            ->with('F j, Y', \Mockery::type('int'))
            ->andReturn('January 15, 2025');

        $dt = new DateTimeExtended('2025-01-15 10:30:00');
        $result = $dt->format('F j, Y');
        
        $this->assertSame('January 15, 2025', $result);
    }

    /**
     * Test that DateTimeExtended inherits DateTimeImmutable methods.
     */
    public function test_inherits_datetime_immutable_methods(): void
    {
        $dt = new DateTimeExtended('2025-01-15 10:30:00');
        
        // Test getTimestamp
        $this->assertIsInt($dt->getTimestamp());
        
        // Test modify (should return new instance due to immutability)
        $modified = $dt->modify('+1 day');
        $this->assertInstanceOf(DateTimeExtended::class, $modified);
        $this->assertNotSame($dt, $modified);
    }

    /**
     * Test that DateTimeExtended can be created from a timestamp.
     */
    public function test_creation_from_timestamp(): void
    {
        $timestamp = 1736934600; // 2025-01-15 10:30:00 UTC
        $dt = new DateTimeExtended('@' . $timestamp);
        
        $this->assertInstanceOf(DateTimeExtended::class, $dt);
        $this->assertSame($timestamp, $dt->getTimestamp());
    }
}
