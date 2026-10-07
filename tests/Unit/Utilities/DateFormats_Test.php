<?php
/**
 * Tests for the DateFormats class
 *
 * @package TouchPointWP\Tests\Unit\Utilities
 */

namespace tp\TouchPointWP\Tests\Unit\Utilities;

use DateTimeImmutable;
use tp\TouchPointWP\Tests\Support\MeetingFixtures;
use tp\TouchPointWP\Tests\TestCase;
use tp\TouchPointWP\Utilities\DateFormats;
use tp\TouchPointWP\Utilities\DateTimeExtended;

/**
 * Test case for the DateFormats class, which writes the dates and times that visitors read.
 *
 * Unless a test says otherwise, the current time is Wednesday, 2025-11-12 21:00 UTC, the site's time zone is UTC, and the
 * time format is WordPress's default, "g:i a".  The text isn't translated.
 *
 * @covers \tp\TouchPointWP\Utilities\DateFormats
 */
class DateFormats_Test extends TestCase
{
    use MeetingFixtures;

    /**
     * A time that is far from "now", so its date isn't described relative to today.
     */
    private const FAR = '2025-12-20 19:00';  // A Saturday.

    /**
     * A DateTimeExtended, in UTC.  All-day if it's at midnight, as the importer decides.
     *
     * @param string $dateTime
     *
     * @return DateTimeExtended
     */
    private static function dt(string $dateTime): DateTimeExtended
    {
        $dt           = self::when($dateTime);
        $dt->isAllDay = $dt->format('His') === '000000';

        return $dt;
    }

    //////////////////
    // Timestamps   //
    //////////////////

    public function test_timestampWithoutOffset_nullIsZero(): void
    {
        $this->assertSame(0, DateFormats::timestampWithoutOffset(null));
    }

    public function test_timestampWithoutOffset_isTheMomentInTime(): void
    {
        $utc     = new DateTimeImmutable('2026-03-14 19:00:00', new \DateTimeZone('UTC'));
        $newYork = new DateTimeImmutable('2026-03-14 15:00:00', new \DateTimeZone('America/New_York')); // The same moment.

        $this->assertSame($utc->getTimestamp(), DateFormats::timestampWithoutOffset($utc));
        $this->assertSame($utc->getTimestamp(), DateFormats::timestampWithoutOffset($newYork));
    }

    public function test_timestampAndOffset_nullIsZero(): void
    {
        $this->assertSame(0, @DateFormats::timestampAndOffset(null));
    }

    public function test_timestampAndOffset_addsTheOffset(): void
    {
        $newYork = new DateTimeImmutable('2026-03-14 15:00:00', new \DateTimeZone('America/New_York')); // UTC-4.

        $this->assertSame($newYork->getTimestamp() - 4 * 3600, @DateFormats::timestampAndOffset($newYork));
    }

    ///////////////////////
    // Times of day      //
    ///////////////////////

    public function test_TimeStringFormatted_usesTheSitesTimeFormat(): void
    {
        $this->assertSame('7:00 pm', DateFormats::TimeStringFormatted(self::dt('2026-03-14 19:00')));
        $this->assertSame('12:05 am', DateFormats::TimeStringFormatted(self::dt('2026-03-14 00:05')));

        $this->setOption('time_format', 'H:i');

        $this->assertSame('19:00', DateFormats::TimeStringFormatted(self::dt('2026-03-14 19:00')));
    }

    public function test_TimeStringFormatted_showsTheTimeInTheSitesTimeZone(): void
    {
        $this->setTimezone('America/New_York');

        $newYorkTime = new DateTimeExtended('2026-03-14 19:00', new \DateTimeZone('America/New_York'));
        $utcTime     = new DateTimeExtended('2026-03-14 19:00', new \DateTimeZone('UTC')); // 3 pm in New York, in daylight time.

        $this->assertSame('7:00 pm', DateFormats::TimeStringFormatted($newYorkTime));
        $this->assertSame('3:00 pm', DateFormats::TimeStringFormatted($utcTime));
    }

    public function test_TimeStringFormatted_filterCanAdjustIt(): void
    {
        add_filter('tp_adjust_time_string', fn($ts) => str_replace(':00', '', $ts));

        $this->assertSame('7 pm', DateFormats::TimeStringFormatted(self::dt('2026-03-14 19:00')));
        $this->assertSame('7:30 pm', DateFormats::TimeStringFormatted(self::dt('2026-03-14 19:30')));
    }

    public function test_TimeStringFormatted_filterGetsTheDateTime(): void
    {
        $received = null;
        add_filter('tp_adjust_time_string', function ($ts, $dt) use (&$received) {
            $received = $dt;
            return $ts;
        }, 10, 2);
        $dt = self::dt('2026-03-14 19:00');

        DateFormats::TimeStringFormatted($dt);

        $this->assertSame($dt, $received);
    }

    public function test_TimeRangeStringFormatted(): void
    {
        $this->assertSame(
            '7:00 pm &ndash; 8:30 pm',
            DateFormats::TimeRangeStringFormatted(self::dt('2026-03-14 19:00'), self::dt('2026-03-14 20:30'))
        );
    }

    public function test_TimeRangeStringFormatted_filterCanCombineWithTheTimeFilter(): void
    {
        add_filter('tp_adjust_time_string', fn($ts) => str_replace(':00', '', $ts));
        add_filter('tp_adjust_time_range_string', function ($ts, $startStr, $endStr) {
            return "$startStr to $endStr";
        }, 10, 3);

        $this->assertSame(
            '7 pm to 8:30 pm',
            DateFormats::TimeRangeStringFormatted(self::dt('2026-03-14 19:00'), self::dt('2026-03-14 20:30'))
        );
    }

    /////////////////////
    // Dates           //
    /////////////////////

    /**
     * @return array[] [date, expected long form, expected short form].  "Now" is Wednesday, 2025-11-12 21:00.
     */
    public static function provider_dates(): array
    {
        return [
            'this morning'                        => ['2025-11-12 09:00', 'Today', 'Today'],
            'just before five this evening'       => ['2025-11-12 16:59', 'Today', 'Today'],
            'five this evening'                   => ['2025-11-12 17:00', 'Tonight', 'Tonight'],
            'tomorrow'                            => ['2025-11-13 10:00', 'Tomorrow', 'Tomorrow'],
            'yesterday'                           => ['2025-11-11 10:00', 'Yesterday', 'Yesterday'],
            'later this week'                     => ['2025-11-15 10:00', 'This Saturday, November 15', 'This Sat, Nov 15'],
            'just under a week from now'          => ['2025-11-19 20:00', 'This Wednesday, November 19', 'This Wed, Nov 19'],
            'a week from now exactly'             => ['2025-11-19 21:00', 'Next Wednesday, November 19', 'Next Wed, Nov 19'],
            'next week'                           => ['2025-11-22 10:00', 'Next Saturday, November 22', 'Next Sat, Nov 22'],
            'two weeks from now exactly'          => ['2025-11-26 21:00', 'Wednesday, November 26', 'Wed, Nov 26'],
            'last week'                           => ['2025-11-07 10:00', 'Last Friday, November 7', 'Last Fri, Nov 7'],
            'longer ago this year'                => ['2025-10-31 10:00', 'Friday, October 31', 'Fri, Oct 31'],
            'later this year'                     => ['2025-12-20 19:00', 'Saturday, December 20', 'Sat, Dec 20'],
            'next year'                           => ['2026-01-10 10:00', 'Saturday, January 10, 2026', 'Sat, Jan 10, 2026'],
            'last year'                           => ['2024-11-12 10:00', 'Tuesday, November 12, 2024', 'Tue, Nov 12, 2024'],
        ];
    }

    /**
     * @dataProvider provider_dates
     */
    public function test_DateStringFormatted(string $date, string $expected): void
    {
        $this->assertSame($expected, DateFormats::DateStringFormatted(self::dt($date)));
    }

    /**
     * @dataProvider provider_dates
     */
    public function test_DateStringFormattedShort(string $date, string $long, string $expected): void
    {
        $this->assertSame($expected, DateFormats::DateStringFormattedShort(self::dt($date)));
    }

    public function test_DateStringFormatted_filterCanAdjustIt(): void
    {
        add_filter('tp_adjust_date_string', fn($r, $dt) => strtoupper($r) . ' ' . $dt->format('Y'), 10, 2);

        $this->assertSame('SATURDAY, DECEMBER 20 2025', DateFormats::DateStringFormatted(self::dt(self::FAR)));
    }

    public function test_DateStringFormattedShort_filterCanAdjustIt(): void
    {
        add_filter('tp_adjust_date_string_short', fn($r, $dt) => strtoupper($r) . ' ' . $dt->format('Y'), 10, 2);

        $this->assertSame('SAT, DEC 20 2025', DateFormats::DateStringFormattedShort(self::dt(self::FAR)));
    }

    public function test_DateStringFormatted_andTheShortFormAgreeAboutTheYearOnNewYearsEve(): void
    {
        $this->setNow('2025-12-31 12:00');

        $this->assertSame('Sat, Dec 20', DateFormats::DateStringFormattedShort(self::dt('2025-12-20 19:00')));
        $this->assertSame('Saturday, December 20', DateFormats::DateStringFormatted(self::dt('2025-12-20 19:00')));
    }

    public function test_DateAndTimeStringFormatted(): void
    {
        $this->assertSame('Sat, Dec 20 at 7:00 pm', DateFormats::DateAndTimeStringFormatted(self::dt(self::FAR)));
        $this->assertSame('Tomorrow at 9:30 am', DateFormats::DateAndTimeStringFormatted(self::dt('2025-11-13 09:30')));
    }

    ///////////////////////
    // Durations         //
    ///////////////////////

    public function test_DurationToStringArray_noStartIsEmpty(): void
    {
        $this->assertCount(0, DateFormats::DurationToStringArray(null, self::dt(self::FAR)));
    }

    public function test_DurationToStringArray_aTimedMeetingOnOneDay(): void
    {
        $r = DateFormats::DurationToStringArray(self::dt('2025-12-20 19:00'), self::dt('2025-12-20 20:30'));

        $this->assertSame(
            ['date' => 'Saturday, December 20', 'time' => '7:00 pm &ndash; 8:30 pm'],
            $r->getArrayCopy()
        );
    }

    public function test_DurationToStringArray_aMeetingWithoutAnEnd(): void
    {
        $r = DateFormats::DurationToStringArray(self::dt('2025-12-20 19:00'), null);

        $this->assertSame(['date' => 'Saturday, December 20', 'time' => '7:00 pm'], $r->getArrayCopy());
    }

    public function test_DurationToStringArray_anAllDayMeeting(): void
    {
        $r = DateFormats::DurationToStringArray(self::dt('2025-12-20 00:00'), self::dt('2025-12-20 23:59'), false, true);

        $this->assertSame(['datetime' => 'Saturday, December 20'], $r->getArrayCopy());
    }

    public function test_DurationToStringArray_anAllDayMeetingOverSeveralDays(): void
    {
        $r = DateFormats::DurationToStringArray(self::dt('2025-12-20 00:00'), self::dt('2025-12-22 00:00'), true, true);

        $this->assertSame(['datetime' => 'Sat, Dec 20 &ndash; Mon, Dec 22'], $r->getArrayCopy());
    }

    public function test_DurationToStringArray_anAllDayMeetingWithoutAnEndIsOneDay(): void
    {
        $r = DateFormats::DurationToStringArray(self::dt('2025-12-20 00:00'), null, true, true);

        $this->assertSame(['datetime' => 'Saturday, December 20'], $r->getArrayCopy());
    }

    public function test_DurationToStringArray_aTimedMeetingOverSeveralDays(): void
    {
        $r = DateFormats::DurationToStringArray(self::dt('2025-12-20 19:00'), self::dt('2025-12-22 09:00'), true, false);

        $this->assertSame(['datetime' => 'Sat, Dec 20 at 7:00 pm &ndash; Mon, Dec 22 at 9:00 am'], $r->getArrayCopy());
    }

    public function test_DurationToStringArray_severalDaysWithoutAnEnd(): void
    {
        $r = DateFormats::DurationToStringArray(self::dt('2025-12-20 19:00'), null, true, false);

        $this->assertSame(['datetime' => 'Saturday, December 20 at 7:00 pm'], $r->getArrayCopy());
    }

    public function test_DurationToStringArray_worksOutWhetherItSpansDaysWhenNotTold(): void
    {
        $oneDay   = DateFormats::DurationToStringArray(self::dt('2025-12-20 19:00'), self::dt('2025-12-20 20:00'));
        $twoDays  = DateFormats::DurationToStringArray(self::dt('2025-12-20 19:00'), self::dt('2025-12-21 09:00'));

        $this->assertSame(['date', 'time'], array_keys($oneDay->getArrayCopy()));
        $this->assertSame(['datetime'], array_keys($twoDays->getArrayCopy()));
    }

    public function test_DurationToString_isTheDateAndTime(): void
    {
        $this->assertSame(
            'Saturday, December 20 at 7:00 pm &ndash; 8:30 pm',
            DateFormats::DurationToString(self::dt('2025-12-20 19:00'), self::dt('2025-12-20 20:30'))
        );
    }

    public function test_DurationToString_anAllDayMeetingHasOnlyADate(): void
    {
        $this->assertSame('Saturday, December 20', DateFormats::DurationToString(self::dt('2025-12-20 00:00'), null));
    }

    public function test_DurationToString_severalDays(): void
    {
        $this->assertSame(
            'Sat, Dec 20 at 7:00 pm &ndash; Sun, Dec 21 at 9:00 am',
            DateFormats::DurationToString(self::dt('2025-12-20 19:00'), self::dt('2025-12-21 09:00'))
        );
    }

    public function test_DurationToString_noStartIsNull(): void
    {
        $this->assertNull(DateFormats::DurationToString(null, self::dt(self::FAR)));
    }

    //////////////////////
    // Occurrences      //
    //////////////////////

    public function test_OccurrencesToStringArray_noOccurrencesIsEmpty(): void
    {
        $this->assertCount(0, DateFormats::OccurrencesToStringArray([]));
        $this->assertCount(0, DateFormats::OccurrencesToStringArray([[null, null, false]]));
    }

    public function test_OccurrencesToStringArray_sameTimeOnSeveralDays(): void
    {
        $r = DateFormats::OccurrencesToStringArray([
            [self::dt('2025-12-24 19:30'), self::dt('2025-12-24 21:00'), false],
            [self::dt('2025-12-25 19:30'), self::dt('2025-12-25 21:00'), false],
        ]);

        $this->assertSame(
            ['date' => 'Wednesday, December 24 & Thursday, December 25', 'time' => '7:30 pm &ndash; 9:00 pm'],
            $r->getArrayCopy()
        );
    }

    public function test_OccurrencesToStringArray_severalTimesOnOneDay(): void
    {
        $r = DateFormats::OccurrencesToStringArray([
            [self::dt('2025-12-24 09:00'), null, false],
            [self::dt('2025-12-24 11:00'), null, false],
        ]);

        $this->assertSame(['date' => 'Wednesday, December 24', 'time' => '9:00 am & 11:00 am'], $r->getArrayCopy());
    }

    public function test_OccurrencesToStringArray_differentDatesAndTimesAreListedTogether(): void
    {
        $r = DateFormats::OccurrencesToStringArray([
            [self::dt('2025-12-24 09:00'), null, false],
            [self::dt('2025-12-25 11:00'), null, false],
        ]);

        $this->assertSame(
            ['datetime' => 'Wednesday, December 24 at 9:00 am & Thursday, December 25 at 11:00 am'],
            $r->getArrayCopy()
        );
    }

    public function test_OccurrencesToStringArray_anOccurrenceOverSeveralDaysGetsEverythingListedTogether(): void
    {
        $r = DateFormats::OccurrencesToStringArray([
            [self::dt('2025-12-24 19:00'), self::dt('2025-12-25 09:00'), false],
            [self::dt('2025-12-27 19:00'), self::dt('2025-12-27 20:00'), false],
        ]);

        $this->assertSame(
            ['datetime' => 'Wed, Dec 24 at 7:00 pm &ndash; Thu, Dec 25 at 9:00 am & Saturday, December 27 at 7:00 pm &ndash; 8:00 pm'],
            $r->getArrayCopy()
        );
    }

    public function test_OccurrencesToStringArray_allDayOccurrences(): void
    {
        $r = DateFormats::OccurrencesToStringArray([
            [self::dt('2025-12-24 00:00'), null, true],
            [self::dt('2025-12-25 00:00'), null, true],
        ]);

        $this->assertSame(
            ['date' => 'Wednesday, December 24 & Thursday, December 25', 'time' => 'All Day'],
            $r->getArrayCopy()
        );
    }

    public function test_OccurrencesToStringArray_canUseShortDates(): void
    {
        $r = DateFormats::OccurrencesToStringArray([
            [self::dt('2025-12-24 19:30'), null, false],
            [self::dt('2025-12-25 19:30'), null, false],
        ], PHP_INT_MAX, true);

        $this->assertSame('Wed, Dec 24 & Thu, Dec 25', $r['date']);
    }

    public function test_OccurrencesToStringArray_listsOnlyUpToTheLimit(): void
    {
        $r = DateFormats::OccurrencesToStringArray([
            [self::dt('2025-12-24 19:30'), null, false],
            [self::dt('2025-12-25 19:30'), null, false],
            [self::dt('2025-12-26 19:30'), null, false],
        ], 2, true);

        // Items that contain commas are separated with semicolons.
        $this->assertSame('Wed, Dec 24; Thu, Dec 25 & others', $r['date']);
        $this->assertSame('7:30 pm', $r['time']);
    }

    public function test_OccurrencesToStringArray_aRepeatedDateOrTimeIsListedOnce(): void
    {
        $r = DateFormats::OccurrencesToStringArray([
            [self::dt('2025-12-24 09:00'), null, false],
            [self::dt('2025-12-24 09:00'), null, false],
            [self::dt('2025-12-24 11:00'), null, false],
        ]);

        $this->assertSame('Wednesday, December 24', $r['date']);
        $this->assertSame('9:00 am & 11:00 am', $r['time']);
    }
}
