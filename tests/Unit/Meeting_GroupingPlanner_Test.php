<?php
/**
 * Tests for the Meeting_GroupingPlanner class
 *
 * @package TouchPointWP\Tests\Unit
 */

namespace tp\TouchPointWP\Tests\Unit;

use stdClass;
use tp\TouchPointWP\MeetingArray;
use tp\TouchPointWP\Meeting_GroupingPlanner;
use tp\TouchPointWP\Meeting_GroupingSettings;
use tp\TouchPointWP\Tests\Support\MeetingFixtures;
use tp\TouchPointWP\Tests\TestCase;

/**
 * Test case for the Meeting_GroupingPlanner class, which arranges the meetings of an involvement (and, optionally, its
 * child involvements) into Editions and Clusters.  Results are compared using shape(), which describes a plan as nested
 * arrays of meeting IDs.
 *
 * Gaps are the plugin's defaults: 25 days between Editions, and 2 hours between the meetings of a Cluster.
 *
 * @covers \tp\TouchPointWP\Meeting_GroupingPlanner
 */
class Meeting_GroupingPlanner_Test extends TestCase
{
    use MeetingFixtures;

    private const EDITION_GAP = 25 * 86400;
    private const CLUSTER_GAP = 2 * 3600;

    /**
     * Plan the meetings of a structure.
     *
     * @param stdClass   $owner    The structure owner.
     * @param stdClass[] $children The other involvements in the structure.
     * @param bool       $editions
     * @param bool       $clusters
     *
     * @return array
     */
    private function plan(stdClass $owner, array $children, bool $editions, bool $clusters): array
    {
        $planner = new Meeting_GroupingPlanner(
            $owner,
            [$owner, ...$children],
            $editions,
            $clusters,
            self::EDITION_GAP,
            self::CLUSTER_GAP
        );

        return $planner->plan();
    }

    ///////////////////////
    // No grouping at all //
    ///////////////////////

    public function test_plan_noMeetings(): void
    {
        $owner = self::involvement(1, 'Conference');

        $this->assertSame([], $this->plan($owner, [], true, true));
    }

    public function test_plan_withoutEditionsOrClusters_listsMeetingsChronologically(): void
    {
        $owner = self::involvement(1, 'Small Group', [
            self::meeting(3, 1, '2026-03-03 19:00'),
            self::meeting(1, 1, '2026-03-01 19:00'),
            self::meeting(2, 1, '2026-03-02 19:00'),
        ]);

        $this->assertSame([1, 2, 3], self::shape($this->plan($owner, [], false, false)));
    }

    public function test_plan_withoutEditionsOrClusters_includesChildInvolvements(): void
    {
        $child = self::involvement(2, 'Child', [self::meeting(2, 2, '2026-03-02 19:00')]);
        $owner = self::involvement(1, 'Owner', [self::meeting(1, 1, '2026-03-01 19:00'), self::meeting(3, 1, '2026-03-03 19:00')]);

        $this->assertSame([1, 2, 3], self::shape($this->plan($owner, [$child], false, false)));
    }

    public function test_plan_order_doesNotDependOnTheOrderMeetingsAreGiven(): void
    {
        // Same start and end: involvement ID decides, and then the meeting's ID.
        $a = self::meeting(9, 1, '2026-03-01 19:00', '2026-03-01 20:00');
        $b = self::meeting(5, 2, '2026-03-01 19:00', '2026-03-01 20:00');
        $c = self::meeting(4, 2, '2026-03-01 19:00', '2026-03-01 20:00');

        $one = $this->plan(self::involvement(1, 'Owner', [$a]), [self::involvement(2, 'Child', [$b, $c])], false, false);
        $two = $this->plan(self::involvement(1, 'Owner', [$a]), [self::involvement(2, 'Child', [$c, $b])], false, false);

        $this->assertSame([9, 4, 5], self::shape($one));
        $this->assertSame(self::shape($one), self::shape($two));
    }

    /////////////
    // Titles  //
    /////////////

    public function test_plan_meetingTitles_useTheMeetingsOwnNameFirst(): void
    {
        $owner = self::involvement(1, 'Owner Name', [
            self::meeting(1, 1, '2026-03-01 19:00', null, 'Opening Session'),
            self::meeting(2, 1, '2026-03-02 19:00'),
        ]);

        $plan = $this->plan($owner, [], false, false);

        $this->assertSame('Opening Session', $plan[0]->titleToUse);
        $this->assertSame('Owner Name', $plan[1]->titleToUse);
    }

    public function test_plan_meetingTitles_useTheirOwnInvolvementsTitle(): void
    {
        $child = self::involvement(2, 'Child Name', [self::meeting(2, 2, '2026-03-02 19:00')], '  Child Registration Title  ');
        $owner = self::involvement(1, 'Owner Name', [self::meeting(1, 1, '2026-03-01 19:00')]);

        $plan = $this->plan($owner, [$child], false, false);

        $this->assertSame('Owner Name', $plan[0]->titleToUse);
        $this->assertSame('Child Registration Title', $plan[1]->titleToUse, 'The registration title is used instead of the name, and trimmed.');
    }

    public function test_plan_titleOfAnInvolvement_prefersAnExistingTitleToUse(): void
    {
        $owner               = self::involvement(1, 'Name', [self::meeting(1, 1, '2026-03-01 19:00')], 'Registration Title');
        $owner->titleToUse   = 'Title Already Chosen';

        $plan = $this->plan($owner, [], false, false);

        $this->assertSame('Title Already Chosen', $plan[0]->titleToUse);
    }

    /////////////
    // Clusters //
    /////////////

    public function test_plan_clusters_backToBackMeetingsOfOneInvolvement(): void
    {
        $owner = self::involvement(1, 'Retreat', [
            self::meeting(1, 1, '2026-03-14 09:00', '2026-03-14 10:00'),
            self::meeting(2, 1, '2026-03-14 10:00', '2026-03-14 11:00'),
            self::meeting(3, 1, '2026-03-14 11:30', '2026-03-14 12:30'), // 30 minutes after the last.
            self::meeting(4, 1, '2026-03-14 16:00', '2026-03-14 17:00'), // Three and a half hours after.
        ]);

        $this->assertSame([['cluster' => [1, 2, 3]], 4], self::shape($this->plan($owner, [], false, true)));
    }

    public function test_plan_clusters_gapIsInclusive(): void
    {
        $owner = self::involvement(1, 'Retreat', [
            self::meeting(1, 1, '2026-03-14 09:00', '2026-03-14 10:00'),
            self::meeting(2, 1, '2026-03-14 12:00', '2026-03-14 13:00'), // Exactly 2 hours after.
            self::meeting(3, 1, '2026-03-14 15:01', '2026-03-14 16:00'), // 2 hours 1 minute after.
        ]);

        $this->assertSame([['cluster' => [1, 2]], 3], self::shape($this->plan($owner, [], false, true)));
    }

    public function test_plan_clusters_overlappingMeetingsAreBackToBack(): void
    {
        $owner = self::involvement(1, 'Retreat', [
            self::meeting(1, 1, '2026-03-14 09:00', '2026-03-14 12:00'),
            self::meeting(2, 1, '2026-03-14 10:00', '2026-03-14 11:00'),
        ]);

        $this->assertSame([['cluster' => [1, 2]]], self::shape($this->plan($owner, [], false, true)));
    }

    public function test_plan_clusters_meetingsWithoutAnEndUseTheirStart(): void
    {
        $owner = self::involvement(1, 'Retreat', [
            self::meeting(1, 1, '2026-03-14 09:00'),
            self::meeting(2, 1, '2026-03-14 09:30'),
            self::meeting(3, 1, '2026-03-14 14:00'),
        ]);

        $this->assertSame([['cluster' => [1, 2]], 3], self::shape($this->plan($owner, [], false, true)));
    }

    public function test_plan_clusters_neverMixInvolvements(): void
    {
        $child = self::involvement(2, 'Child', [self::meeting(2, 2, '2026-03-14 10:00', '2026-03-14 11:00')]);
        $owner = self::involvement(1, 'Owner', [self::meeting(1, 1, '2026-03-14 09:00', '2026-03-14 10:00')]);

        $this->assertSame([1, 2], self::shape($this->plan($owner, [$child], false, true)));
    }

    public function test_plan_clusters_anotherInvolvementsMeetingBetweenBreaksTheRun(): void
    {
        $child = self::involvement(2, 'Child', [self::meeting(2, 2, '2026-03-14 10:00', '2026-03-14 10:30')]);
        $owner = self::involvement(1, 'Owner', [
            self::meeting(1, 1, '2026-03-14 09:00', '2026-03-14 10:00'),
            self::meeting(3, 1, '2026-03-14 10:30', '2026-03-14 11:30'),
        ]);

        $this->assertSame([1, 2, 3], self::shape($this->plan($owner, [$child], false, true)));
    }

    public function test_plan_clusters_describeThemselvesAsTheirInvolvement(): void
    {
        $child = self::involvement(2, 'Child Name', [
            self::meeting(7, 2, '2026-03-14 09:00', '2026-03-14 10:00'),
            self::meeting(6, 2, '2026-03-14 10:00', '2026-03-14 11:30'),
        ]);
        $owner = self::involvement(1, 'Owner Name');

        $plan = $this->plan($owner, [$child], false, true);

        $this->assertCount(1, $plan);
        $cluster = $plan[0];
        $this->assertInstanceOf(MeetingArray::class, $cluster);
        $this->assertSame(MeetingArray::ROLE_CLUSTER, $cluster->groupRole);
        $this->assertSame('Child Name', $cluster->titleToUse);
        $this->assertSame(2, $cluster->involvementId);
        $this->assertSame(-7, $cluster->mtgId, 'The ID is the earliest meeting\'s.');
        $this->assertEquals(self::when('2026-03-14 09:00'), $cluster->mtgStartDt);
        $this->assertEquals(self::when('2026-03-14 11:30'), $cluster->mtgEndDt);
    }

    public function test_plan_clustersWithoutEditions_dontGatherNonAdjacentMeetingsOfAChild(): void
    {
        $child = self::involvement(2, 'Child', [
            self::meeting(1, 2, '2026-03-14 09:00', '2026-03-14 10:00'),
            self::meeting(2, 2, '2026-03-14 15:00', '2026-03-14 16:00'),
        ]);
        $owner = self::involvement(1, 'Owner');

        $this->assertSame([1, 2], self::shape($this->plan($owner, [$child], false, true)));
    }

    ////////////////
    // Editions   //
    ////////////////

    public function test_plan_editions_splitAtTheGap(): void
    {
        $owner = self::involvement(1, 'Annual Event', [
            self::meeting(1, 1, '2026-01-01 10:00', '2026-01-01 11:00'),
            self::meeting(2, 1, '2026-01-02 10:00', '2026-01-02 11:00'),
            self::meeting(3, 1, '2026-01-20 10:00', '2026-01-20 11:00'), // 18 days later: the same Edition.
            self::meeting(4, 1, '2026-03-01 10:00', '2026-03-01 11:00'), // 40 days later: a new one.
            self::meeting(5, 1, '2026-03-02 10:00', '2026-03-02 11:00'),
        ]);

        $this->assertSame(
            [['edition' => [1, 2, 3]], ['edition' => [4, 5]]],
            self::shape($this->plan($owner, [], true, false))
        );
    }

    public function test_plan_editions_gapIsInclusive(): void
    {
        $owner = self::involvement(1, 'Annual Event', [
            self::meeting(1, 1, '2026-01-01 10:00', '2026-01-01 11:00'),
            self::meeting(2, 1, '2026-01-26 11:00', '2026-01-26 12:00'), // Exactly 25 days after the first one ends.
            self::meeting(3, 1, '2026-02-20 12:01', '2026-02-20 13:00'), // 25 days and a minute after.
        ]);

        $this->assertSame([['edition' => [1, 2]], 3], self::shape($this->plan($owner, [], true, false)));
    }

    public function test_plan_editions_gapIsMeasuredFromTheLatestEndSoFar(): void
    {
        $owner = self::involvement(1, 'Long Event', [
            self::meeting(1, 1, '2026-01-01 00:00', '2026-01-20 00:00'), // Runs for most of the month.
            self::meeting(2, 1, '2026-01-02 10:00', '2026-01-02 11:00'),
            // 38 days after meeting 2 ends, but 21 days after meeting 1 does.
            self::meeting(3, 1, '2026-02-10 09:00', '2026-02-10 10:00'),
        ]);

        $this->assertSame([['edition' => [1, 2, 3]]], self::shape($this->plan($owner, [], true, false)));
    }

    public function test_plan_editions_aLoneMeetingIsNotAnEdition(): void
    {
        $owner = self::involvement(1, 'One-Time Event', [self::meeting(1, 1, '2026-01-01 10:00', '2026-01-01 11:00')]);

        $plan = $this->plan($owner, [], true, true);

        $this->assertSame([1], self::shape($plan));
        $this->assertNotInstanceOf(MeetingArray::class, $plan[0]);
    }

    public function test_plan_editions_describeThemselvesAsTheOwner(): void
    {
        $owner = self::involvement(1, 'Annual Event', [
            self::meeting(4, 1, '2026-01-02 10:00', '2026-01-02 11:00'),
            self::meeting(3, 1, '2026-01-01 10:00', '2026-01-01 11:00'),
        ]);

        $plan = $this->plan($owner, [], true, false);

        $this->assertCount(1, $plan);
        $edition = $plan[0];
        $this->assertSame(MeetingArray::ROLE_EDITION, $edition->groupRole);
        $this->assertSame('Annual Event', $edition->titleToUse);
        $this->assertNull($edition->spanningMeeting);
        $this->assertSame(-3, $edition->mtgId);
        $this->assertEquals(self::when('2026-01-01 10:00'), $edition->mtgStartDt);
        $this->assertEquals(self::when('2026-01-02 11:00'), $edition->mtgEndDt);
    }

    public function test_plan_editions_includeTheMeetingsOfChildInvolvements(): void
    {
        $child = self::involvement(2, 'Child', [self::meeting(2, 2, '2026-01-01 14:00', '2026-01-01 15:00')]);
        $owner = self::involvement(1, 'Annual Event', [self::meeting(1, 1, '2026-01-01 10:00', '2026-01-01 11:00')]);

        $this->assertSame([['edition' => [1, 2]]], self::shape($this->plan($owner, [$child], true, false)));
    }

    ///////////////////////
    // Editions with Clusters //
    ///////////////////////

    public function test_plan_editionsAndClusters_eachChildsMeetingsFormOneCluster(): void
    {
        // Within an Edition, a child's meetings are one Cluster even when they aren't back to back.
        $friday   = self::involvement(2, 'Friday', [
            self::meeting(21, 2, '2026-03-13 09:00', '2026-03-13 10:00'),
            self::meeting(22, 2, '2026-03-13 15:00', '2026-03-13 16:00'),
        ]);
        $saturday = self::involvement(3, 'Saturday', [
            self::meeting(31, 3, '2026-03-14 09:00', '2026-03-14 10:00'),
            self::meeting(32, 3, '2026-03-14 14:00', '2026-03-14 15:00'),
            self::meeting(33, 3, '2026-03-14 19:00', '2026-03-14 20:00'),
        ]);
        $owner    = self::involvement(1, 'Conference');

        $this->assertSame(
            [['edition' => [['cluster' => [21, 22]], ['cluster' => [31, 32, 33]]]]],
            self::shape($this->plan($owner, [$friday, $saturday], true, true))
        );
    }

    public function test_plan_editionsAndClusters_clustersAreTitledAsTheirChild(): void
    {
        $friday = self::involvement(2, 'Friday Sessions', [
            self::meeting(21, 2, '2026-03-13 09:00', '2026-03-13 10:00'),
            self::meeting(22, 2, '2026-03-13 15:00', '2026-03-13 16:00'),
        ]);
        $owner  = self::involvement(1, 'Conference', [self::meeting(1, 1, '2026-03-13 08:00', '2026-03-13 08:30')]);

        $edition = $this->plan($owner, [$friday], true, true)[0];

        $this->assertSame('Conference', $edition->titleToUse);
        $clusters = array_values(array_filter($edition->leafMeetings() ? iterator_to_array($edition) : [], fn($i) => $i instanceof MeetingArray));
        $this->assertCount(1, $clusters);
        $this->assertSame('Friday Sessions', $clusters[0]->titleToUse);
        $this->assertSame(2, $clusters[0]->involvementId);
    }

    public function test_plan_editionsAndClusters_aChildWithOneMeetingStandsAlone(): void
    {
        $friday   = self::involvement(2, 'Friday', [
            self::meeting(21, 2, '2026-03-13 09:00', '2026-03-13 10:00'),
            self::meeting(22, 2, '2026-03-13 15:00', '2026-03-13 16:00'),
        ]);
        $saturday = self::involvement(3, 'Saturday', [self::meeting(31, 3, '2026-03-14 09:00', '2026-03-14 10:00')]);
        $owner    = self::involvement(1, 'Conference');

        $this->assertSame(
            [['edition' => [['cluster' => [21, 22]], 31]]],
            self::shape($this->plan($owner, [$friday, $saturday], true, true))
        );
    }

    public function test_plan_editionsAndClusters_theOwnersOwnMeetingsOnlyClusterWhenBackToBack(): void
    {
        $owner = self::involvement(1, 'Conference', [
            self::meeting(1, 1, '2026-03-13 09:00', '2026-03-13 10:00'),
            self::meeting(2, 1, '2026-03-13 10:00', '2026-03-13 11:00'), // Back to back with meeting 1.
            self::meeting(3, 1, '2026-03-13 16:00', '2026-03-13 17:00'), // Not.
            self::meeting(4, 1, '2026-03-14 16:00', '2026-03-14 17:00'), // Not.
        ]);

        $this->assertSame(
            [['edition' => [['cluster' => [1, 2]], 3, 4]]],
            self::shape($this->plan($owner, [], true, true))
        );
    }

    public function test_plan_editionsAndClusters_eachEditionClustersSeparately(): void
    {
        $child = self::involvement(2, 'Child', [
            self::meeting(1, 2, '2026-01-01 09:00', '2026-01-01 10:00'),
            self::meeting(2, 2, '2026-01-01 15:00', '2026-01-01 16:00'),
            self::meeting(3, 2, '2026-06-01 09:00', '2026-06-01 10:00'),
            self::meeting(4, 2, '2026-06-01 15:00', '2026-06-01 16:00'),
        ]);
        $owner = self::involvement(9, 'Annual Event', [
            self::meeting(5, 9, '2026-01-01 12:00', '2026-01-01 13:00'),
            self::meeting(6, 9, '2026-06-01 12:00', '2026-06-01 13:00'),
        ]);

        $this->assertSame(
            [['edition' => [['cluster' => [1, 2]], 5]], ['edition' => [['cluster' => [3, 4]], 6]]],
            self::shape($this->plan($owner, [$child], true, true))
        );
    }

    public function test_plan_editionsAndClusters_anEditionWithOnlyOneItemIsJustThatItem(): void
    {
        // Each Edition here has a single Cluster in it, so there's nothing for an Edition to hold together.
        $child = self::involvement(2, 'Child', [
            self::meeting(1, 2, '2026-01-01 09:00', '2026-01-01 10:00'),
            self::meeting(2, 2, '2026-01-01 15:00', '2026-01-01 16:00'),
            self::meeting(3, 2, '2026-06-01 09:00', '2026-06-01 10:00'),
            self::meeting(4, 2, '2026-06-01 15:00', '2026-06-01 16:00'),
        ]);
        $owner = self::involvement(9, 'Annual Event');

        $this->assertSame(
            [['cluster' => [1, 2]], ['cluster' => [3, 4]]],
            self::shape($this->plan($owner, [$child], true, true))
        );
    }

    ////////////////////////
    // Spanning meetings   //
    ////////////////////////

    public function test_plan_spanningMeeting_isTheOwnersMeetingThatCoversTheEdition(): void
    {
        $child = self::involvement(2, 'Sessions', [
            self::meeting(11, 2, '2026-11-06 19:00', '2026-11-06 20:00'),
            self::meeting(12, 2, '2026-11-07 09:00', '2026-11-07 10:00'),
        ]);
        $owner = self::involvement(1, 'Global Outreach Conference', [
            self::meeting(10, 1, '2026-11-06 18:00', '2026-11-08 20:00', '  Global Outreach 2026  '),
        ]);

        $plan = $this->plan($owner, [$child], true, true);

        $this->assertSame([['edition' => [10, ['cluster' => [11, 12]]]]], self::shape($plan));

        $edition = $plan[0];
        $this->assertSame(10, $edition->spanningMeeting->mtgId);
        $this->assertSame('Global Outreach 2026', $edition->titleToUse, 'The Edition takes the spanning meeting\'s name, trimmed.');
        $this->assertContains($edition->spanningMeeting, $edition->leafMeetings(), 'It is still one of the Edition\'s meetings.');
    }

    public function test_plan_spanningMeeting_isNotClustered(): void
    {
        // The owner's two short meetings are back to back, and the spanning meeting starts with them.  It stays out of
        // their Cluster.  (Items are in order by start and then end, so the Cluster, which ends sooner, comes first.)
        $owner = self::involvement(1, 'Conference', [
            self::meeting(10, 1, '2026-11-06 18:00', '2026-11-08 20:00'),
            self::meeting(11, 1, '2026-11-06 18:00', '2026-11-06 19:00'),
            self::meeting(12, 1, '2026-11-06 19:00', '2026-11-06 20:00'),
        ]);

        $this->assertSame(
            [['edition' => [['cluster' => [11, 12]], 10]]],
            self::shape($this->plan($owner, [], true, true))
        );
    }

    public function test_plan_spanningMeeting_withoutANameLeavesTheOwnersTitle(): void
    {
        $owner = self::involvement(1, 'Conference', [
            self::meeting(10, 1, '2026-11-06 18:00', '2026-11-08 20:00', '   '),
            self::meeting(11, 1, '2026-11-07 09:00', '2026-11-07 10:00'),
        ]);

        $edition = $this->plan($owner, [], true, false)[0];

        $this->assertSame(10, $edition->spanningMeeting->mtgId);
        $this->assertSame('Conference', $edition->titleToUse);
    }

    public function test_plan_spanningMeeting_mustBelongToTheOwner(): void
    {
        $child = self::involvement(2, 'Child', [self::meeting(10, 2, '2026-11-06 18:00', '2026-11-08 20:00')]);
        $owner = self::involvement(1, 'Conference', [self::meeting(11, 1, '2026-11-07 09:00', '2026-11-07 10:00')]);

        $edition = $this->plan($owner, [$child], true, false)[0];

        $this->assertNull($edition->spanningMeeting);
        $this->assertSame('Conference', $edition->titleToUse);
    }

    public function test_plan_spanningMeeting_mustCoverEveryOtherMeeting(): void
    {
        $owner = self::involvement(1, 'Conference', [
            self::meeting(10, 1, '2026-11-06 18:00', '2026-11-08 20:00'),
            self::meeting(11, 1, '2026-11-07 09:00', '2026-11-09 10:00'), // Ends after the long meeting.
        ]);

        $edition = $this->plan($owner, [], true, false)[0];

        $this->assertNull($edition->spanningMeeting);
    }

    public function test_plan_spanningMeeting_needsAnEndAfterItsStart(): void
    {
        $owner = self::involvement(1, 'Conference', [
            self::meeting(10, 1, '2026-11-06 18:00'), // No end.
            self::meeting(11, 1, '2026-11-06 18:00'),
        ]);

        $edition = $this->plan($owner, [], true, false)[0];

        $this->assertNull($edition->spanningMeeting);
    }

    public function test_plan_spanningMeeting_firstInChronologicalOrderWinsATie(): void
    {
        $owner = self::involvement(1, 'Conference', [
            self::meeting(20, 1, '2026-11-06 18:00', '2026-11-08 20:00'),
            self::meeting(10, 1, '2026-11-06 18:00', '2026-11-08 20:00'),
            self::meeting(30, 1, '2026-11-07 09:00', '2026-11-07 10:00'),
        ]);

        $edition = $this->plan($owner, [], true, false)[0];

        $this->assertSame(10, $edition->spanningMeeting->mtgId);
    }

    public function test_plan_spanningMeeting_isOnlyConsideredWithEditions(): void
    {
        $owner = self::involvement(1, 'Conference', [
            self::meeting(10, 1, '2026-11-06 18:00', '2026-11-08 20:00'),
            self::meeting(11, 1, '2026-11-07 09:00', '2026-11-07 10:00'),
        ]);

        $this->assertSame([10, 11], self::shape($this->plan($owner, [], false, false)));
    }

    ///////////////////
    // fromSettings  //
    ///////////////////

    /**
     * Plan a structure with the planner that Meeting_GroupingPlanner::fromSettings() makes from the settings for all
     * types.
     *
     * @param array      $settings Settings for useGroupingSettings().
     * @param stdClass   $owner
     * @param stdClass[] $children
     *
     * @return array
     */
    private function planFromSettings(array $settings, stdClass $owner, array $children = []): array
    {
        $this->useGroupingSettings($settings);

        $rule = Meeting_GroupingSettings::forOtherTypes();

        return Meeting_GroupingPlanner::fromSettings($owner, [$owner, ...$children], $rule)->plan();
    }

    public function test_fromSettings_usesTheEditionAndClusterSettings(): void
    {
        $owner = self::involvement(1, 'Retreat', [
            self::meeting(1, 1, '2026-03-14 09:00', '2026-03-14 10:00'),
            self::meeting(2, 1, '2026-03-14 10:00', '2026-03-14 11:00'),
            self::meeting(3, 1, '2026-03-15 09:00', '2026-03-15 10:00'),
        ]);

        $this->assertSame([1, 2, 3], self::shape($this->planFromSettings([], $owner)));
        $this->assertSame([['cluster' => [1, 2]], 3], self::shape($this->planFromSettings(['otherTypes' => ['clusters' => true]], $owner)));
        $this->assertSame([['edition' => [1, 2, 3]]], self::shape($this->planFromSettings(['otherTypes' => ['editions' => true]], $owner)));
        $this->assertSame(
            [['edition' => [['cluster' => [1, 2]], 3]]],
            self::shape($this->planFromSettings(['otherTypes' => ['editions' => true, 'clusters' => true]], $owner))
        );
    }

    public function test_fromSettings_childrensMeetingsAreOnlyIncludedIfTheSettingsSayTo(): void
    {
        $child = self::involvement(2, 'Child', [self::meeting(2, 2, '2026-03-14 11:00')]);
        $owner = self::involvement(1, 'Owner', [self::meeting(1, 1, '2026-03-14 09:00')]);

        $without = $this->planFromSettings([], $owner, [$child]);
        $with    = $this->planFromSettings(['otherTypes' => ['includeChildren' => true]], $owner, [$child]);

        $this->assertSame([1], self::shape($without));
        $this->assertSame([1, 2], self::shape($with));
    }

    public function test_fromSettings_theGapFiltersApply(): void
    {
        $owner = self::involvement(1, 'Event', [
            self::meeting(1, 1, '2026-03-01 09:00', '2026-03-01 10:00'),
            self::meeting(2, 1, '2026-03-04 09:00', '2026-03-04 10:00'),   // Three days later.
            self::meeting(3, 1, '2026-03-04 11:30', '2026-03-04 12:30'),   // An hour and a half after meeting 2.
        ]);
        $settings = ['otherTypes' => ['editions' => true, 'clusters' => true]];

        $this->assertSame(
            [['edition' => [1, ['cluster' => [2, 3]]]]],
            self::shape($this->planFromSettings($settings, $owner)),
            'By default, the Edition gap is 25 days and the Cluster gap is 2 hours.'
        );

        add_filter('tp_meeting_edition_gap', fn($seconds) => 86400);
        add_filter('tp_meeting_cluster_gap', fn($seconds) => 600);

        $this->assertSame(
            [1, ['edition' => [2, 3]]],
            self::shape($this->planFromSettings($settings, $owner)),
            'A one-day Edition gap splits meeting 1 off, and a ten-minute Cluster gap keeps meetings 2 and 3 apart.'
        );
    }
}
