<?php
/**
 * Tests for the helpers Involvement uses to prepare the data it imports from TouchPoint
 *
 * @package TouchPointWP\Tests\Unit
 */

namespace tp\TouchPointWP\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use stdClass;
use tp\TouchPointWP\Involvement;
use tp\TouchPointWP\Tests\Support\MeetingFixtures;
use tp\TouchPointWP\Tests\TestCase;
use tp\TouchPointWP\Utilities\DateTimeExtended;

/**
 * Test case for the static helpers of the Involvement class that work on the data from the TouchPoint API: normalizing
 * it, removing duplicates, working out recurring schedules, naming the pages of meetings, and deciding how each
 * involvement's meetings are grouped.  They're protected, so they're called with reflection.
 *
 * The current time is fixed at Wednesday, 2025-11-12 21:00 UTC unless a test sets it.
 *
 * @covers \tp\TouchPointWP\Involvement
 */
class Involvement_Test extends TestCase
{
    use MeetingFixtures;

    protected function set_up(): void
    {
        parent::set_up();

        // The archive cutoff is a week before the fixed current time.  Meetings that ended before it are archived.
        self::setStatic(Involvement::class, '_updateExpiry', new DateTimeImmutable('2025-11-05 21:00:00 UTC'));
        $this->useGroupingSettings([]);
    }

    /**
     * An involvement, as the API provides it before standardizeApiData(): dates are strings, in the site's time zone.
     *
     * @param array $fields Anything that should differ from the defaults.
     *
     * @return stdClass
     */
    private static function rawInvolvement(array $fields = []): stdClass
    {
        return (object)($fields + [
            'involvementId' => 1,
            'name'          => 'Involvement',
            'firstMeeting'  => null,
            'lastMeeting'   => null,
            'regStart'      => null,
            'regEnd'        => null,
            'schedules'     => [],
            'meetings'      => [],
        ]);
    }

    /**
     * A meeting, as the API provides it before standardizeApiData().
     *
     * @param array $fields Anything that should differ from the defaults.
     *
     * @return stdClass
     */
    private static function rawMeeting(array $fields = []): stdClass
    {
        return (object)($fields + [
            'mtgId'      => 100,
            'mtgStartDt' => '2026-03-14T19:00:00',
            'mtgEndDt'   => null,
            'name'       => null,
            'location'   => null,
            'status'     => 1,
        ]);
    }

    /**
     * Run standardizeApiData() and return the involvement.
     *
     * @param stdClass $inv
     * @param string   $timezone The site's time zone.
     *
     * @return stdClass
     */
    private function standardize(stdClass $inv, string $timezone = 'UTC'): stdClass
    {
        self::callStatic(Involvement::class, 'standardizeApiData', $inv, new DateTimeZone($timezone), false);

        return $inv;
    }

    /////////////////////////
    // standardizeApiData  //
    /////////////////////////

    public function test_standardizeApiData_firstAndLastMeetingBecomeAllDayDates(): void
    {
        $inv = $this->standardize(
            self::rawInvolvement(['firstMeeting' => '2026-03-14T00:00:00', 'lastMeeting' => '2026-06-01T00:00:00']),
            'America/New_York'
        );

        $this->assertInstanceOf(DateTimeExtended::class, $inv->firstMeeting);
        $this->assertSame('2026-03-14T00:00:00-04:00', $inv->firstMeeting->format('c'), 'In the site\'s time zone.');
        $this->assertTrue($inv->firstMeeting->isAllDay);
        $this->assertSame('2026-06-01T00:00:00-04:00', $inv->lastMeeting->format('c'));
        $this->assertTrue($inv->lastMeeting->isAllDay);
    }

    public function test_standardizeApiData_firstAndLastMeetingCanBeMissingOrInvalid(): void
    {
        $missing = $this->standardize(self::rawInvolvement());
        $invalid = $this->standardize(self::rawInvolvement(['firstMeeting' => 'not a date', 'lastMeeting' => 'also not']));

        $this->assertNull($missing->firstMeeting);
        $this->assertNull($missing->lastMeeting);
        $this->assertNull($invalid->firstMeeting);
        $this->assertNull($invalid->lastMeeting);
    }

    public function test_standardizeApiData_schedules(): void
    {
        $inv = $this->standardize(self::rawInvolvement(['schedules' => [
            (object)['nextStartDt' => '2026-03-15T11:00:00', 'nextEndDt' => '2026-03-15T12:30:00'],
            (object)['nextStartDt' => '2026-03-16T00:00:00', 'nextEndDt' => '2026-03-16T00:00:00'],
            (object)['nextStartDt' => '2026-03-17T09:00:00', 'nextEndDt' => null],
        ]]));

        [$timed, $allDay, $noEnd] = $inv->schedules;

        $this->assertInstanceOf(DateTimeExtended::class, $timed->nextStartDt);
        $this->assertSame('2026-03-15T11:00:00+00:00', $timed->nextStartDt->format('c'));
        $this->assertFalse($timed->nextStartDt->isAllDay);
        $this->assertSame('2026-03-15T12:30:00+00:00', $timed->nextEndDt->format('c'));

        $this->assertTrue($allDay->nextStartDt->isAllDay, 'A start at midnight is all-day.');
        $this->assertNull($allDay->nextEndDt, 'An end that equals the start is no end.');
        $this->assertNull($noEnd->nextEndDt);
    }

    public function test_standardizeApiData_scheduleWithAnInvalidStartIsRemoved(): void
    {
        $inv = $this->standardize(self::rawInvolvement(['schedules' => [
            (object)['nextStartDt' => 'garbage', 'nextEndDt' => null],
            (object)['nextStartDt' => '2026-03-15T11:00:00', 'nextEndDt' => null],
        ]]));

        $this->assertCount(1, $inv->schedules);
        $this->assertSame('2026-03-15T11:00:00+00:00', array_values($inv->schedules)[0]->nextStartDt->format('c'));
    }

    public function test_standardizeApiData_meetingTimes(): void
    {
        $inv = $this->standardize(self::rawInvolvement([
            'involvementId' => 12,
            'meetings'      => [
                self::rawMeeting(['mtgId' => 1, 'mtgStartDt' => '2026-03-14T19:00:00', 'mtgEndDt' => '2026-03-14T20:30:00']),
                self::rawMeeting(['mtgId' => 2, 'mtgStartDt' => '2026-03-15T19:00:00', 'mtgEndDt' => null]),
                self::rawMeeting(['mtgId' => 3, 'mtgStartDt' => '2026-03-16T19:00:00', 'mtgEndDt' => '2026-03-16T19:00:00']),
                self::rawMeeting(['mtgId' => 4, 'mtgStartDt' => '2026-03-17T00:00:00']),
            ],
        ]), 'America/New_York');

        [$timed, $noEnd, $sameEnd, $allDay] = $inv->meetings;

        $this->assertInstanceOf(DateTimeExtended::class, $timed->mtgStartDt);
        $this->assertSame('2026-03-14T19:00:00-04:00', $timed->mtgStartDt->format('c'), 'In the site\'s time zone.');
        $this->assertSame('2026-03-14T20:30:00-04:00', $timed->mtgEndDt->format('c'));
        $this->assertFalse($timed->mtgStartDt->isAllDay);
        $this->assertNull($noEnd->mtgEndDt);
        $this->assertNull($sameEnd->mtgEndDt, 'An end that equals the start is no end.');
        $this->assertTrue($allDay->mtgStartDt->isAllDay, 'A start at midnight is all-day.');
    }

    public function test_standardizeApiData_anEndBeforeTheStartIsNoEnd(): void
    {
        // TouchPoint gives every meeting in a series the end of the first.
        $inv = $this->standardize(self::rawInvolvement(['meetings' => [
            self::rawMeeting(['mtgStartDt' => '2026-03-21T19:00:00', 'mtgEndDt' => '2026-03-14T20:30:00']),
        ]]));

        $this->assertNull($inv->meetings[0]->mtgEndDt);
    }

    public function test_standardizeApiData_meetingsKnowTheirInvolvement(): void
    {
        $inv = $this->standardize(self::rawInvolvement([
            'involvementId' => 12,
            'meetings'      => [self::rawMeeting(['mtgId' => 1]), self::rawMeeting(['mtgId' => 2])],
        ]));

        $this->assertSame(12, $inv->meetings[0]->involvementId);
        $this->assertSame(12, $inv->meetings[1]->involvementId);
    }

    public function test_standardizeApiData_blankMeetingNamesBecomeNull(): void
    {
        $inv = $this->standardize(self::rawInvolvement(['meetings' => [
            self::rawMeeting(['mtgId' => 1, 'name' => 'Opening Session']),
            self::rawMeeting(['mtgId' => 2, 'name' => '']),
            self::rawMeeting(['mtgId' => 3, 'name' => "  \t "]),
            self::rawMeeting(['mtgId' => 4, 'name' => null]),
        ]]));

        $this->assertSame(['Opening Session', null, null, null], array_map(fn($m) => $m->name, $inv->meetings));
    }

    public function test_standardizeApiData_meetingWithAnInvalidStartIsRemoved(): void
    {
        $inv = $this->standardize(self::rawInvolvement(['meetings' => [
            self::rawMeeting(['mtgId' => 1, 'mtgStartDt' => 'garbage']),
            self::rawMeeting(['mtgId' => 2]),
        ]]));

        $this->assertSame([2], array_values(array_map(fn($m) => $m->mtgId, $inv->meetings)));
    }

    public function test_standardizeApiData_meetingWithAnInvalidEndIsRemovedEntirely(): void
    {
        // A valid start doesn't save a meeting whose end can't be read.
        $inv = $this->standardize(self::rawInvolvement(['meetings' => [
            self::rawMeeting(['mtgId' => 1, 'mtgEndDt' => 'garbage']),
            self::rawMeeting(['mtgId' => 2]),
        ]]));

        $this->assertSame([2], array_values(array_map(fn($m) => $m->mtgId, $inv->meetings)));
    }

    public function test_standardizeApiData_lastMeetingIsForgottenIfAMeetingComesLater(): void
    {
        $later   = $this->standardize(self::rawInvolvement([
            'lastMeeting' => '2026-03-14T00:00:00',
            'meetings'    => [self::rawMeeting(['mtgStartDt' => '2026-03-21T19:00:00'])],
        ]));
        $earlier = $this->standardize(self::rawInvolvement([
            'lastMeeting' => '2026-03-31T00:00:00',
            'meetings'    => [self::rawMeeting(['mtgStartDt' => '2026-03-21T19:00:00'])],
        ]));

        $this->assertNull($later->lastMeeting);
        $this->assertSame('2026-03-31', $earlier->lastMeeting->format('Y-m-d'));
    }

    public function test_standardizeApiData_registrationDates(): void
    {
        $inv = $this->standardize(
            self::rawInvolvement(['regStart' => '2026-01-01T08:00:00', 'regEnd' => '2026-02-01T17:00:00']),
            'America/New_York'
        );

        $this->assertInstanceOf(DateTimeImmutable::class, $inv->regStart);
        $this->assertSame('2026-01-01T08:00:00-05:00', $inv->regStart->format('c'));
        $this->assertSame('2026-02-01T17:00:00-05:00', $inv->regEnd->format('c'));
    }

    public function test_standardizeApiData_registrationDatesCanBeMissingOrInvalid(): void
    {
        $missing = $this->standardize(self::rawInvolvement());
        $invalid = $this->standardize(self::rawInvolvement(['regStart' => 'garbage', 'regEnd' => 'garbage']));

        $this->assertNull($missing->regStart);
        $this->assertNull($missing->regEnd);
        $this->assertNull($invalid->regStart);
        $this->assertNull($invalid->regEnd);
    }

    public function test_standardizeApiData_printsNothingUnlessVerbose(): void
    {
        $this->expectOutputString('');

        $this->standardize(self::rawInvolvement(['meetings' => [self::rawMeeting()]]));
    }

    ///////////////////////////////
    // computeCommonOccurrences  //
    ///////////////////////////////

    /**
     * Find the recurring times in some meetings and schedules.
     *
     * @param stdClass[] $meetings
     * @param stdClass[] $schedules
     * @param int        $minNumber
     *
     * @return array
     */
    private function commonOccurrences(array $meetings = [], array $schedules = [], int $minNumber = 3): array
    {
        return self::callStatic(Involvement::class, 'computeCommonOccurrences', $meetings, $schedules, $minNumber);
    }

    /**
     * A schedule, as it is after standardizeApiData().
     *
     * @param string $nextStart
     *
     * @return stdClass
     */
    private static function schedule(string $nextStart): stdClass
    {
        $start = self::when($nextStart);
        $start->isAllDay = $start->format('His') === '000000';

        return (object)['nextStartDt' => $start, 'nextEndDt' => null];
    }

    public function test_computeCommonOccurrences_nothing(): void
    {
        $this->assertSame([], $this->commonOccurrences());
    }

    public function test_computeCommonOccurrences_aScheduleCountsAsManyOccurrences(): void
    {
        // The schedule's next time is Sunday, November 16, 11:00.
        $result = $this->commonOccurrences([], [self::schedule('2025-11-16 11:00')]);

        $this->assertSame(['0-1100'], array_keys($result));
        $this->assertSame(20, $result['0-1100']['count']);
        $this->assertEquals(self::when('2025-11-16 11:00'), $result['0-1100']['example']);
        $this->assertNull($result['0-1100']['exampleEnd']);
    }

    public function test_computeCommonOccurrences_anAllDayScheduleHasNoTime(): void
    {
        $result = $this->commonOccurrences([], [self::schedule('2025-11-16 00:00')]);

        $this->assertSame(['0-9999'], array_keys($result));
    }

    public function test_computeCommonOccurrences_skipsSchedulesWithoutAStart(): void
    {
        $noStart = (object)['nextStartDt' => null, 'nextEndDt' => null];

        $result = $this->commonOccurrences([], ['not an object', $noStart, self::schedule('2025-11-16 11:00')]);

        $this->assertSame(['0-1100'], array_keys($result));
    }

    public function test_computeCommonOccurrences_meetingsNeedAtLeastThreeOccurrences(): void
    {
        $sunday = fn(int $id, string $day) => self::meeting($id, 1, "2025-11-$day 11:00");

        $this->assertSame([], $this->commonOccurrences([$sunday(1, '16'), $sunday(2, '23')]));

        $result = $this->commonOccurrences([$sunday(1, '16'), $sunday(2, '23'), $sunday(3, '30')]);
        $this->assertSame(['0-1100'], array_keys($result));
        $this->assertSame(3, $result['0-1100']['count']);
    }

    public function test_computeCommonOccurrences_theMinimumCanBeChanged(): void
    {
        $meetings = [self::meeting(1, 1, '2025-11-16 11:00')];

        $this->assertSame([], $this->commonOccurrences($meetings));
        $this->assertSame(['0-1100'], array_keys($this->commonOccurrences($meetings, [], 1)));
    }

    public function test_computeCommonOccurrences_meetingsInThePastAreIgnored(): void
    {
        // Now is Wednesday, November 12, 21:00.
        $meetings = [
            self::meeting(1, 1, '2025-11-02 11:00'),
            self::meeting(2, 1, '2025-11-09 11:00'),
            self::meeting(3, 1, '2025-11-16 11:00'),
        ];

        $this->assertSame([], $this->commonOccurrences($meetings));
    }

    public function test_computeCommonOccurrences_separatesDaysAndTimes(): void
    {
        $meetings = [];
        foreach (['16', '23', '30'] as $i => $day) {
            $meetings[] = self::meeting($i, 1, "2025-11-$day 09:00");         // Sundays at 9.
            $meetings[] = self::meeting($i + 10, 1, "2025-11-$day 11:00");    // Sundays at 11.
        }
        foreach (['17', '24', '01'] as $i => $day) {
            $month      = $day === '01' ? '12' : '11';
            $meetings[] = self::meeting($i + 20, 1, "2025-$month-$day 09:00"); // Mondays at 9.
        }

        $this->assertEqualsCanonicalizing(['0-0900', '0-1100', '1-0900'], array_keys($this->commonOccurrences($meetings)));
    }

    public function test_computeCommonOccurrences_meetingsAddToASchedule(): void
    {
        $result = $this->commonOccurrences([self::meeting(1, 1, '2025-11-23 11:00')], [self::schedule('2025-11-16 11:00')]);

        $this->assertSame(21, $result['0-1100']['count']);
    }

    public function test_computeCommonOccurrences_remembersTheFirstMeetingsEnd(): void
    {
        $meetings = [
            self::meeting(1, 1, '2025-11-16 11:00', '2025-11-16 12:00'),
            self::meeting(2, 1, '2025-11-23 11:00', '2025-11-23 13:00'),
            self::meeting(3, 1, '2025-11-30 11:00', '2025-11-30 14:00'),
        ];

        $result = $this->commonOccurrences($meetings);

        $this->assertEquals(self::when('2025-11-16 11:00'), $result['0-1100']['example']);
        $this->assertEquals(self::when('2025-11-16 12:00'), $result['0-1100']['exampleEnd']);
    }

    public function test_computeCommonOccurrences_usesTheCurrentTime(): void
    {
        $meetings = [
            self::meeting(1, 1, '2025-11-16 11:00'),
            self::meeting(2, 1, '2025-11-23 11:00'),
            self::meeting(3, 1, '2025-11-30 11:00'),
        ];
        $this->setNow('2025-11-20 12:00');

        // The 16th is now in the past, so only two occurrences are left.
        $this->assertSame([], $this->commonOccurrences($meetings));
    }

    ////////////////////////////
    // dedupeApiInvolvements  //
    ////////////////////////////

    public function test_dedupeApiInvolvements_keepsTheFirstOfEachAndOrderIsKept(): void
    {
        $first  = (object)['involvementId' => 3, 'isParent' => 0, 'name' => 'first'];
        $other  = (object)['involvementId' => 1, 'isParent' => 0, 'name' => 'other'];
        $second = (object)['involvementId' => 3, 'isParent' => 0, 'name' => 'second'];

        $result = self::callStatic(Involvement::class, 'dedupeApiInvolvements', [$first, $other, $second]);

        $this->assertSame([$first, $other], $result);
    }

    public function test_dedupeApiInvolvements_aRepeatedInvolvementIsAParentIfEitherRowIs(): void
    {
        $a = (object)['involvementId' => 3, 'isParent' => 0];
        $b = (object)['involvementId' => 3, 'isParent' => 1];

        $result = self::callStatic(Involvement::class, 'dedupeApiInvolvements', [$a, $b]);

        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]->isParent);
    }

    public function test_dedupeApiInvolvements_noInvolvements(): void
    {
        $this->assertSame([], self::callStatic(Involvement::class, 'dedupeApiInvolvements', []));
    }

    //////////////////
    // computeSlugs //
    //////////////////

    /**
     * Compute the slugs of some meetings and return them in order, by meeting ID.
     *
     * @param stdClass[] $meetings
     * @param bool       $includeTitle
     *
     * @return string[]
     */
    private function slugs(array $meetings, bool $includeTitle = false): array
    {
        $inv = (object)['titleToUse' => 'Involvement Title'];
        self::callStatic(Involvement::class, 'computeSlugs', $meetings, $inv, $includeTitle);

        $slugs = [];
        foreach ($meetings as $m) {
            $slugs[$m->mtgId] = $m->slugToUse;
        }
        return $slugs;
    }

    public function test_computeSlugs_aLoneMeetingGetsItsMonth(): void
    {
        $this->assertSame([1 => '2026-03'], $this->slugs([self::meeting(1, 1, '2026-03-14 19:00')]));
    }

    public function test_computeSlugs_meetingsInTheSameMonthGetTheirDates(): void
    {
        $slugs = $this->slugs([self::meeting(1, 1, '2026-03-14 19:00'), self::meeting(2, 1, '2026-03-21 19:00')]);

        $this->assertSame([1 => '2026-03-14', 2 => '2026-03-21'], $slugs);
    }

    public function test_computeSlugs_meetingsOnTheSameDayGetTheirHours(): void
    {
        $slugs = $this->slugs([self::meeting(1, 1, '2026-03-14 09:00'), self::meeting(2, 1, '2026-03-14 14:00')]);

        $this->assertSame([1 => '2026-03-14-9', 2 => '2026-03-14-2'], $slugs);
    }

    public function test_computeSlugs_morningAndEveningOfTheSameHourAreToldApartByAmAndPm(): void
    {
        $slugs = $this->slugs([self::meeting(1, 1, '2026-03-14 09:00'), self::meeting(2, 1, '2026-03-14 21:00')]);

        $this->assertSame([1 => '2026-03-14-9am', 2 => '2026-03-14-9pm'], $slugs);
    }

    public function test_computeSlugs_aLaterMeetingsMinutesCanTellItApart(): void
    {
        $slugs = $this->slugs([self::meeting(1, 1, '2026-03-14 09:00'), self::meeting(2, 1, '2026-03-14 09:30')]);

        $this->assertSame([1 => '2026-03-14-900am', 2 => '2026-03-14-930am'], $slugs);
    }

    public function test_computeSlugs_meetingsAtTheSameTimeFallBackToTheirIds(): void
    {
        $slugs = $this->slugs([self::meeting(11, 1, '2026-03-14 09:00'), self::meeting(12, 1, '2026-03-14 09:00')]);

        $this->assertSame([11 => 11, 12 => 12], $slugs);
    }

    public function test_computeSlugs_setsEachTitle(): void
    {
        $named   = self::meeting(1, 1, '2026-03-14 09:00', null, 'Opening Session');
        $unnamed = self::meeting(2, 1, '2026-03-21 09:00');

        $this->slugs([$named, $unnamed]);

        $this->assertSame('Opening Session', $named->titleToUse);
        $this->assertSame('Involvement Title', $unnamed->titleToUse);
    }

    public function test_computeSlugs_titlesCanBeUsedWhenTheyAreUnique(): void
    {
        $slugs = $this->slugs([
            self::meeting(1, 1, '2026-03-14 09:00', null, 'Opening Session'),
            self::meeting(2, 1, '2026-03-14 09:00', null, 'Closing Session'),
        ], true);

        $this->assertSame([1 => 'opening-session', 2 => 'closing-session'], $slugs);
    }

    public function test_computeSlugs_titlesThatRepeatAreNotUsed(): void
    {
        $slugs = $this->slugs([
            self::meeting(1, 1, '2026-03-14 19:00', null, 'Session'),
            self::meeting(2, 1, '2026-03-21 19:00', null, 'Session'),
        ], true);

        $this->assertSame([1 => '2026-03-14', 2 => '2026-03-21'], $slugs);
    }

    ////////////////////////
    // classifyForGrouping //
    ////////////////////////

    /**
     * An involvement, as it is after standardizeApiData(), with the fields that classifyForGrouping() reads.
     *
     * @param int   $id
     * @param array $fields Anything that should differ from the defaults.  'ownerInvId' is only set for involvements in
     *                      a structure: an owner's is its own ID, and a child's is its owner's.
     *
     * @return stdClass
     */
    private static function apiInvolvement(int $id, array $fields = []): stdClass
    {
        return (object)($fields + [
            'involvementId' => $id,
            'name'          => "Involvement $id",
            'titleToUse'    => "Involvement $id",
            'invTypeId'     => 1,
            'isParent'      => 0,
            'showInSites'   => 1,
            'meetings'      => [],
            'lastMeeting'   => null,
        ]);
    }

    /**
     * Run classifyForGrouping() and return the involvements, keyed by their IDs.
     *
     * @param stdClass[] $involvements In the order the API gives them: parents before children.
     * @param bool       $verbose
     *
     * @return stdClass[]
     */
    private function classify(array $involvements, bool $verbose = false): array
    {
        self::callStatic(Involvement::class, 'classifyForGrouping', $involvements, $verbose);

        $byId = [];
        foreach ($involvements as $inv) {
            $byId[$inv->involvementId] = $inv;
        }
        return $byId;
    }

    public function test_classifyForGrouping_anInvolvementOutsideAStructureIsNormal(): void
    {
        $involvements = $this->classify([self::apiInvolvement(1)]);

        $this->assertSame('normal', $involvements[1]->_groupingRole);
        $this->assertSame([], $involvements[1]->_groupingStructure);
    }

    public function test_classifyForGrouping_involvementsGetTheSettingsForTheirType(): void
    {
        $this->useGroupingSettings([
            'types'      => [['invTypeId' => 5, 'editions' => true, 'clusters' => true]],
            'otherTypes' => ['clusters' => true],
        ]);

        $involvements = $this->classify([
            self::apiInvolvement(1, ['invTypeId' => 5]),
            self::apiInvolvement(2, ['invTypeId' => 9]),
            self::apiInvolvement(3, ['invTypeId' => null]),
        ]);

        $this->assertTrue($involvements[1]->_grouping->editions, 'A type with its own settings.');
        $this->assertFalse($involvements[2]->_grouping->editions, 'Any other type.');
        $this->assertTrue($involvements[2]->_grouping->clusters);
        $this->assertFalse($involvements[3]->_grouping->editions, 'An involvement without a type.');
        $this->assertTrue($involvements[3]->_grouping->clusters);
    }

    public function test_classifyForGrouping_anOwnerBringsInItsChildrensMeetings(): void
    {
        $ownerMeeting  = self::meeting(1, 10, '2026-03-14 09:00');
        $childMeeting  = self::meeting(2, 11, '2026-03-13 09:00');
        $otherChild    = self::meeting(3, 12, '2026-03-15 09:00');

        $involvements = $this->classify([
            self::apiInvolvement(10, ['ownerInvId' => 10, 'meetings' => [$ownerMeeting]]),
            self::apiInvolvement(11, ['ownerInvId' => 10, 'meetings' => [$childMeeting]]),
            self::apiInvolvement(12, ['ownerInvId' => 10, 'meetings' => [$otherChild]]),
        ]);

        $owner = $involvements[10];
        $this->assertSame('owner', $owner->_groupingRole);
        $this->assertSame('child', $involvements[11]->_groupingRole);
        $this->assertSame('child', $involvements[12]->_groupingRole);
        $this->assertSame([$involvements[11], $involvements[12]], $owner->_groupingStructure);
        $this->assertSame([$ownerMeeting], $owner->_ownMeetings);
        $this->assertSame([$childMeeting, $ownerMeeting, $otherChild], $owner->meetings, 'All of them, in chronological order.');
    }

    public function test_classifyForGrouping_aChildWithoutMeetingsIsSkipped(): void
    {
        $involvements = $this->classify([
            self::apiInvolvement(10, ['ownerInvId' => 10, 'meetings' => [self::meeting(1, 10, '2026-03-14 09:00')]]),
            self::apiInvolvement(11, ['ownerInvId' => 10]),
        ]);

        $this->assertSame('skip', $involvements[11]->_groupingRole);
        $this->assertSame([], $involvements[10]->_groupingStructure);
    }

    public function test_classifyForGrouping_aChildWhoseOwnerIsntInTheDataIsSkipped(): void
    {
        $involvements = $this->classify([
            self::apiInvolvement(11, ['ownerInvId' => 10, 'meetings' => [self::meeting(2, 11, '2026-03-13 09:00')]]),
        ]);

        $this->assertSame('skip', $involvements[11]->_groupingRole);
    }

    public function test_classifyForGrouping_aGrandchildOfAChildIsSkippedUnlessItsOwnerIsAnOwner(): void
    {
        // Grandchildren point at their structure's owner.  One that points at a child has no owner to join.
        $involvements = $this->classify([
            self::apiInvolvement(10, ['ownerInvId' => 10, 'meetings' => [self::meeting(1, 10, '2026-03-14 09:00')]]),
            self::apiInvolvement(11, ['ownerInvId' => 10, 'meetings' => [self::meeting(2, 11, '2026-03-14 10:00')]]),
            self::apiInvolvement(12, ['ownerInvId' => 10, 'meetings' => [self::meeting(3, 12, '2026-03-14 11:00')]]),
            self::apiInvolvement(13, ['ownerInvId' => 11, 'meetings' => [self::meeting(4, 13, '2026-03-14 12:00')]]),
        ]);

        $this->assertSame('child', $involvements[12]->_groupingRole);
        $this->assertSame('skip', $involvements[13]->_groupingRole);
        $this->assertCount(2, $involvements[10]->_groupingStructure);
    }

    public function test_classifyForGrouping_aHiddenChildKeepsOnlyItsArchivedMeetings(): void
    {
        $archived = self::meeting(2, 11, '2025-10-01 09:00', '2025-10-01 10:00'); // Ended before the cutoff.
        $recent   = self::meeting(3, 11, '2025-11-10 09:00', '2025-11-10 10:00'); // Ended after it.
        $upcoming = self::meeting(4, 11, '2026-03-14 09:00', '2026-03-14 10:00');

        $involvements = $this->classify([
            self::apiInvolvement(10, ['ownerInvId' => 10, 'meetings' => [self::meeting(1, 10, '2026-03-14 09:00')]]),
            self::apiInvolvement(11, ['ownerInvId' => 10, 'showInSites' => 0, 'meetings' => [$archived, $recent, $upcoming]]),
        ]);

        $this->assertSame('child', $involvements[11]->_groupingRole);
        $this->assertSame([$archived], $involvements[11]->meetings);
    }

    public function test_classifyForGrouping_aHiddenChildWithoutArchivedMeetingsIsSkipped(): void
    {
        $involvements = $this->classify([
            self::apiInvolvement(10, ['ownerInvId' => 10, 'meetings' => [self::meeting(1, 10, '2026-03-14 09:00')]]),
            self::apiInvolvement(11, ['ownerInvId' => 10, 'showInSites' => 0, 'meetings' => [self::meeting(2, 11, '2026-03-14 09:00')]]),
        ]);

        $this->assertSame('skip', $involvements[11]->_groupingRole);
        $this->assertSame([], $involvements[10]->_groupingStructure);
    }

    public function test_classifyForGrouping_aVisibleChildKeepsAllItsMeetings(): void
    {
        $meetings = [
            self::meeting(2, 11, '2025-10-01 09:00', '2025-10-01 10:00'),
            self::meeting(3, 11, '2026-03-14 09:00', '2026-03-14 10:00'),
        ];

        $involvements = $this->classify([
            self::apiInvolvement(10, ['ownerInvId' => 10, 'meetings' => [self::meeting(1, 10, '2026-03-14 09:00')]]),
            self::apiInvolvement(11, ['ownerInvId' => 10, 'meetings' => $meetings]),
        ]);

        $this->assertSame($meetings, $involvements[11]->meetings);
    }

    public function test_classifyForGrouping_anOwnersLastMeetingIsForgottenIfAChildMeetsLater(): void
    {
        $later   = $this->classify([
            self::apiInvolvement(10, ['ownerInvId' => 10, 'lastMeeting' => self::when('2026-03-14'), 'meetings' => [self::meeting(1, 10, '2026-03-14 09:00')]]),
            self::apiInvolvement(11, ['ownerInvId' => 10, 'meetings' => [self::meeting(2, 11, '2026-04-01 09:00')]]),
        ]);
        $earlier = $this->classify([
            self::apiInvolvement(10, ['ownerInvId' => 10, 'lastMeeting' => self::when('2026-12-31'), 'meetings' => [self::meeting(1, 10, '2026-03-14 09:00')]]),
            self::apiInvolvement(11, ['ownerInvId' => 10, 'meetings' => [self::meeting(2, 11, '2026-04-01 09:00')]]),
        ]);

        $this->assertNull($later[10]->lastMeeting);
        $this->assertNotNull($earlier[10]->lastMeeting);
    }

    public function test_classifyForGrouping_describesWhatItDoesWhenVerbose(): void
    {
        $this->expectOutputRegex('/Involvement 11 \(Involvement 11\) has structure owner 10: child\..*Involvement 10 \(Involvement 10\) is a structure owner, including 1 child involvement\(s\)\./s');

        $this->classify([
            self::apiInvolvement(10, ['ownerInvId' => 10, 'meetings' => [self::meeting(1, 10, '2026-03-14 09:00')]]),
            self::apiInvolvement(11, ['ownerInvId' => 10, 'meetings' => [self::meeting(2, 11, '2026-03-14 10:00')]]),
        ], true);
    }
}
