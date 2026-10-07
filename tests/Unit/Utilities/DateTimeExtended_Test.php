<?php
/**
 * Tests for the DateTimeExtended class
 *
 * @package TouchPointWP\Tests\Unit\Utilities
 */

namespace tp\TouchPointWP\Tests\Unit\Utilities;

use DateTimeImmutable;
use DateTimeZone;
use tp\TouchPointWP\Tests\TestCase;
use tp\TouchPointWP\Utilities\DateTimeExtended;

/**
 * Test case for the DateTimeExtended class, a DateTimeImmutable that can also be marked as all-day.
 *
 * @covers \tp\TouchPointWP\Utilities\DateTimeExtended
 */
class DateTimeExtended_Test extends TestCase
{
    public function test_isADateTimeImmutable(): void
    {
        $this->assertInstanceOf(DateTimeImmutable::class, new DateTimeExtended('2026-03-14 19:00'));
    }

    public function test_isNotAllDayUnlessMarked(): void
    {
        $dt = new DateTimeExtended('2026-03-14 00:00');

        $this->assertFalse($dt->isAllDay);

        $dt->isAllDay = true;

        $this->assertTrue($dt->isAllDay);
    }

    public function test_acceptsATimeZone(): void
    {
        $dt = new DateTimeExtended('2026-03-14 19:00', new DateTimeZone('America/New_York'));

        $this->assertSame('2026-03-14T19:00:00-04:00', $dt->format('c'));
    }

    public function test_format_isThePlainFormat(): void
    {
        $dt = new DateTimeExtended('2026-03-14 19:05');

        $this->assertSame('Saturday 14 March 2026, 19:05', $dt->format('l j F Y, H:i'));
    }

    public function test_format_i18n_isTheTimeOnTheWallInTheDatesOwnTimeZone(): void
    {
        $newYork = new DateTimeExtended('2026-03-14 19:00', new DateTimeZone('America/New_York'));
        $tokyo   = new DateTimeExtended('2026-03-14 19:00', new DateTimeZone('Asia/Tokyo'));

        $this->assertSame('7:00 pm', $newYork->format_i18n('g:i a'));
        $this->assertSame('7:00 pm', $tokyo->format_i18n('g:i a'));
        $this->assertSame('Saturday, March 14', $tokyo->format_i18n('l, F j'));
    }

    public function test_changesReturnANewObjectAndLeaveTheOriginal(): void
    {
        $original = new DateTimeExtended('2026-03-14 19:00');
        $later    = $original->modify('+1 day');

        $this->assertSame('2026-03-14', $original->format('Y-m-d'));
        $this->assertSame('2026-03-15', $later->format('Y-m-d'));
        $this->assertInstanceOf(DateTimeExtended::class, $later);
    }

    public function test_changesKeepTheAllDayMark(): void
    {
        $original           = new DateTimeExtended('2026-03-14 00:00');
        $original->isAllDay = true;

        $this->assertTrue($original->modify('+1 day')->isAllDay);
    }
}
