<?php
/**
 * Tests for the MeetingArray class
 *
 * @package TouchPointWP\Tests\Unit
 */

namespace tp\TouchPointWP\Tests\Unit;

use tp\TouchPointWP\MeetingArray;
use tp\TouchPointWP\Tests\Support\MeetingFixtures;
use tp\TouchPointWP\Tests\TestCase;

/**
 * Test case for the MeetingArray class, which holds the meetings of a group (an Edition or a Cluster), and presents the
 * group as if it were a single meeting.
 *
 * @covers \tp\TouchPointWP\MeetingArray
 */
class MeetingArray_Test extends TestCase
{
    use MeetingFixtures;

    public function test_constants_roles(): void
    {
        $this->assertSame('edition', MeetingArray::ROLE_EDITION);
        $this->assertSame('cluster', MeetingArray::ROLE_CLUSTER);
    }

    public function test_construct_defaults(): void
    {
        $group = new MeetingArray();

        $this->assertCount(0, $group);
        $this->assertSame("", $group->slugToUse);
        $this->assertSame("", $group->titleToUse);
        $this->assertNull($group->groupRole);
        $this->assertNull($group->spanningMeeting);
        $this->assertNull($group->isGroupMember);
    }

    public function test_construct_roleIsKept(): void
    {
        $group = new MeetingArray([], null, MeetingArray::ROLE_EDITION);

        $this->assertSame(MeetingArray::ROLE_EDITION, $group->groupRole);
    }

    /////////////////
    // Properties  //
    /////////////////

    public function test_get_emptyGroupHasNoProperties(): void
    {
        $group = new MeetingArray([], self::involvement(7, 'Conference'));

        foreach (['name', 'mtgId', 'mtgStartDt', 'mtgEndDt', 'location', 'status', 'involvementId'] as $property) {
            $this->assertNull($group->$property, $property);
        }
    }

    public function test_get_unknownPropertyIsNull(): void
    {
        $group = new MeetingArray([self::meeting(1, 7, '2026-03-14 09:00')]);

        $this->assertNull($group->bogus);
    }

    public function test_get_nameLocationAndIdComeFromTheInvolvement(): void
    {
        $involvement           = self::involvement(7, 'Conference');
        $involvement->location = 'Sanctuary';
        $group                 = new MeetingArray([self::meeting(1, 7, '2026-03-14 09:00')], $involvement);

        $this->assertSame('Conference', $group->name);
        $this->assertSame('Sanctuary', $group->location);
        $this->assertSame(7, $group->involvementId);
    }

    public function test_get_withoutAnInvolvement(): void
    {
        $group = new MeetingArray([self::meeting(1, 7, '2026-03-14 09:00')]);

        $this->assertSame("", $group->name);
        $this->assertNull($group->location);
        $this->assertNull($group->involvementId);
    }

    ///////////////
    // Meeting ID //
    ///////////////

    public function test_mtgId_isTheNegativeOfTheFirstMeetings(): void
    {
        $group = new MeetingArray(
            [self::meeting(5, 7, '2026-03-14 09:00'), self::meeting(6, 7, '2026-03-14 10:00')],
            null,
            MeetingArray::ROLE_CLUSTER
        );

        $this->assertSame(-5, $group->mtgId);
    }

    public function test_mtgId_ofEditionsAndClustersIsTheEarliestMeetingNoMatterTheOrder(): void
    {
        $later   = self::meeting(3, 7, '2026-03-15 09:00');
        $earlier = self::meeting(9, 7, '2026-03-14 09:00');

        $group = new MeetingArray([$later, $earlier], null, MeetingArray::ROLE_EDITION);

        $this->assertSame(-9, $group->mtgId);
    }

    public function test_mtgId_ofEditionsAndClustersBreaksTiesByEndAndThenMeetingId(): void
    {
        $longer  = self::meeting(1, 7, '2026-03-14 09:00', '2026-03-14 12:00');
        $shorter = self::meeting(8, 7, '2026-03-14 09:00', '2026-03-14 10:00');
        $sameA   = self::meeting(20, 7, '2026-03-14 09:00', '2026-03-14 10:00');

        $this->assertSame(-8, (new MeetingArray([$longer, $sameA, $shorter], null, MeetingArray::ROLE_CLUSTER))->mtgId);
        $this->assertSame(-8, (new MeetingArray([$sameA, $shorter], null, MeetingArray::ROLE_CLUSTER))->mtgId);
    }

    public function test_mtgId_ofPreviousBehaviorCollectionsIsTheFirstMeetingAdded(): void
    {
        // Existing posts are found by this ID, so it has to stay the first meeting added, not the earliest.
        $group = new MeetingArray([
            self::meeting(3, 7, '2026-03-15 09:00'),
            self::meeting(9, 7, '2026-03-14 09:00'),
        ]);

        $this->assertNull($group->groupRole);
        $this->assertSame(-3, $group->mtgId);
    }

    public function test_firstMeeting_looksInsideNestedGroups(): void
    {
        $inner = new MeetingArray(
            [self::meeting(4, 7, '2026-03-14 08:00'), self::meeting(5, 7, '2026-03-14 09:00')],
            null,
            MeetingArray::ROLE_CLUSTER
        );
        $outer = new MeetingArray([self::meeting(6, 7, '2026-03-14 10:00'), $inner], null, MeetingArray::ROLE_EDITION);

        $this->assertSame(4, $outer->firstMeeting()->mtgId);
        $this->assertSame(-4, $outer->mtgId);
    }

    ///////////
    // Times //
    ///////////

    public function test_mtgStartDt_isTheEarliestStart(): void
    {
        $group = new MeetingArray([
            self::meeting(2, 7, '2026-03-15 09:00'),
            self::meeting(1, 7, '2026-03-14 18:00'),
            self::meeting(3, 7, '2026-03-16 09:00'),
        ]);

        $this->assertEquals(self::when('2026-03-14 18:00'), $group->mtgStartDt);
    }

    public function test_mtgEndDt_isTheLatestEnd(): void
    {
        $group = new MeetingArray([
            self::meeting(1, 7, '2026-03-14 09:00', '2026-03-14 10:00'),
            self::meeting(2, 7, '2026-03-14 11:00', '2026-03-14 15:00'),
            self::meeting(3, 7, '2026-03-14 12:00', '2026-03-14 13:00'),
        ]);

        $this->assertEquals(self::when('2026-03-14 15:00'), $group->mtgEndDt);
    }

    public function test_mtgEndDt_usesTheStartOfMeetingsWithoutAnEnd(): void
    {
        $group = new MeetingArray([
            self::meeting(1, 7, '2026-03-14 09:00', '2026-03-14 10:00'),
            self::meeting(2, 7, '2026-03-14 11:00'), // No end.
        ]);

        $this->assertEquals(self::when('2026-03-14 11:00'), $group->mtgEndDt);
    }

    public function test_times_includeNestedGroups(): void
    {
        $inner = new MeetingArray(
            [self::meeting(1, 7, '2026-03-14 08:00', '2026-03-14 09:00'), self::meeting(2, 7, '2026-03-14 20:00', '2026-03-14 21:00')],
            null,
            MeetingArray::ROLE_CLUSTER
        );
        $outer = new MeetingArray([self::meeting(3, 7, '2026-03-14 12:00', '2026-03-14 13:00'), $inner]);

        $this->assertEquals(self::when('2026-03-14 08:00'), $outer->mtgStartDt);
        $this->assertEquals(self::when('2026-03-14 21:00'), $outer->mtgEndDt);
    }

    ////////////
    // Status //
    ////////////

    public function test_status_isCancelledOnlyWhenEveryMeetingIs(): void
    {
        $cancelled = fn(int $id) => self::meeting($id, 7, '2026-03-14 09:00', null, null, 0);
        $scheduled = fn(int $id) => self::meeting($id, 7, '2026-03-14 09:00', null, null, 1);

        $this->assertSame(0, (new MeetingArray([$cancelled(1), $cancelled(2)]))->status);
        $this->assertSame(1, (new MeetingArray([$cancelled(1), $scheduled(2)]))->status);
        $this->assertSame(1, (new MeetingArray([$scheduled(1), $scheduled(2)]))->status);
    }

    ///////////////////
    // Contents       //
    ///////////////////

    public function test_leafMeetings_flattensNestedGroupsInOrder(): void
    {
        $a = self::meeting(1, 7, '2026-03-14 09:00');
        $b = self::meeting(2, 7, '2026-03-14 10:00');
        $c = self::meeting(3, 7, '2026-03-14 11:00');
        $d = self::meeting(4, 7, '2026-03-14 12:00');

        $inner = new MeetingArray([$b, $c], null, MeetingArray::ROLE_CLUSTER);
        $outer = new MeetingArray([$a, $inner, $d], null, MeetingArray::ROLE_EDITION);

        $this->assertSame([$a, $b, $c, $d], $outer->leafMeetings());
        $this->assertCount(3, $outer, 'count() counts the items directly inside the group, including nested groups.');
    }

    public function test_arrayAccess_readsWritesAndRemoves(): void
    {
        $a     = self::meeting(1, 7, '2026-03-14 09:00');
        $b     = self::meeting(2, 7, '2026-03-14 10:00');
        $group = new MeetingArray();

        $group[] = $a;
        $group[] = $b;

        $this->assertCount(2, $group);
        $this->assertTrue(isset($group[1]));
        $this->assertSame($b, $group[1]);

        unset($group[0]);

        $this->assertCount(1, $group);
        $this->assertFalse(isset($group[0]));
        $this->assertNull($group[0]);
    }

    public function test_iteration_isInTheOrderAdded(): void
    {
        $meetings = [self::meeting(2, 7, '2026-03-14 10:00'), self::meeting(1, 7, '2026-03-14 09:00')];
        $group    = new MeetingArray($meetings);

        $this->assertSame($meetings, iterator_to_array($group));
    }
}
