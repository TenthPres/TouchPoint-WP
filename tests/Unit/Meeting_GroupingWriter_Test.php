<?php
/**
 * Tests for the parts of Meeting_GroupingWriter that don't need WordPress's posts
 *
 * @package TouchPointWP\Tests\Unit
 */

namespace tp\TouchPointWP\Tests\Unit;

use ReflectionMethod;
use stdClass;
use tp\TouchPointWP\Involvement;
use tp\TouchPointWP\MeetingArray;
use tp\TouchPointWP\Tests\Support\MeetingFixtures;
use tp\TouchPointWP\Tests\TestCase;
use WP_Post;

/**
 * Test case for the helpers of the Meeting_GroupingWriter trait that work out what a post will be called and whether it
 * will exist: slugs (the last part of a page's address), titles, content, and which planned items get posts.
 * Writing the posts needs WordPress, so that isn't tested here.
 *
 * The trait belongs to Involvement, and its helpers are private, so they're called with reflection.
 *
 * @covers \tp\TouchPointWP\Meeting_GroupingWriter
 */
class Meeting_GroupingWriter_Test extends TestCase
{
    use MeetingFixtures;

    /**
     * A meeting that has been through the planner, which gives it a title.
     *
     * @param int    $id
     * @param string $start
     * @param string $title
     *
     * @return stdClass
     */
    private static function planned(int $id, string $start, string $title): stdClass
    {
        $meeting             = self::meeting($id, 1, $start);
        $meeting->titleToUse = $title;

        return $meeting;
    }

    /**
     * A group, as the planner makes it.
     *
     * @param string       $role
     * @param string       $title
     * @param stdClass[]   $meetings
     * @param ?stdClass    $involvement
     *
     * @return MeetingArray
     */
    private static function group(string $role, string $title, array $meetings, ?stdClass $involvement = null): MeetingArray
    {
        $group             = new MeetingArray($meetings, $involvement ?? self::involvement(1, $title), $role);
        $group->titleToUse = $title;

        return $group;
    }

    /**
     * One of the siblings that slugs are chosen for.
     *
     * @param object   $item
     * @param ?WP_Post $post     The item's existing post, if it has one.
     * @param bool     $isGroup
     *
     * @return stdClass
     */
    private static function entry(object $item, ?WP_Post $post = null, bool $isGroup = false): stdClass
    {
        return (object)['item' => $item, 'isGroup' => $isGroup, 'archived' => false, 'post' => $post, 'slug' => null];
    }

    /**
     * An existing post.
     *
     * @param int    $parentId
     * @param string $slug
     *
     * @return WP_Post
     */
    private static function post(int $parentId, string $slug): WP_Post
    {
        return new WP_Post(['ID' => 500 + crc32($slug) % 100, 'post_parent' => $parentId, 'post_name' => $slug]);
    }

    /**
     * Choose slugs for some siblings, and return them in order.
     *
     * @param stdClass[] $entries
     * @param string[]   $reserved     Slugs of other posts under the parent, which can't be used.
     * @param bool       $insideGroup  Whether the siblings are inside a group (an Edition or a Cluster).
     * @param string     $parentTitle
     *
     * @return string[]
     */
    private function assign(array $entries, array $reserved = [], bool $insideGroup = false, string $parentTitle = 'Parent'): array
    {
        $parent = new WP_Post(['ID' => 10, 'post_title' => $parentTitle]);

        self::callStatic(Involvement::class, 'assignSlugs', $entries, $reserved, $parent, $insideGroup);

        return array_map(fn($e) => $e->slug, $entries);
    }

    ///////////////////////////////////
    // assignSlugs: posts that stay  //
    ///////////////////////////////////

    public function test_assignSlugs_aPostThatStaysUnderItsParentKeepsItsSlug(): void
    {
        $entries = [self::entry(self::planned(1, '2026-03-14 19:00', 'Opening Session'), self::post(10, 'my-old-slug'))];

        $this->assertSame(['my-old-slug'], $this->assign($entries, [], true));
    }

    public function test_assignSlugs_aPostThatMovesGetsANewSlug(): void
    {
        $entries = [self::entry(self::planned(1, '2026-03-14 19:00', 'Opening Session'), self::post(99, 'my-old-slug'))];

        $this->assertSame(['opening-session'], $this->assign($entries, [], true));
    }

    public function test_assignSlugs_aNewPostGetsASlug(): void
    {
        $entries = [self::entry(self::planned(1, '2026-03-14 19:00', 'Opening Session'))];

        $this->assertSame(['opening-session'], $this->assign($entries, [], true));
    }

    public function test_assignSlugs_slugsThatCantBeKeptAreReplaced(): void
    {
        $blank   = self::entry(self::planned(1, '2026-03-14 19:00', 'Blank'), self::post(10, ''));
        $trashed = self::entry(self::planned(2, '2026-03-14 19:00', 'Trashed'), self::post(10, 'old__trashed'));
        $taken   = self::entry(self::planned(3, '2026-03-14 19:00', 'Taken'), self::post(10, 'taken-elsewhere'));

        $slugs = $this->assign([$blank, $trashed, $taken], ['taken-elsewhere'], true);

        $this->assertSame(['blank', 'trashed', 'taken'], $slugs);
    }

    public function test_assignSlugs_twoPostsWithTheSameSlugCantBothKeepIt(): void
    {
        $first  = self::entry(self::planned(1, '2026-03-14 19:00', 'First'), self::post(10, 'shared'));
        $second = self::entry(self::planned(2, '2026-03-21 19:00', 'Second'), self::post(10, 'shared'));

        $this->assertSame(['shared', 'second'], $this->assign([$first, $second], [], true));
    }

    public function test_assignSlugs_newSlugsStayOutOfTheWayOfKeptOnes(): void
    {
        $kept = self::entry(self::planned(1, '2026-03-14 19:00', 'Kept'), self::post(10, 'session'));
        $new  = self::entry(self::planned(2, '2026-03-21 19:00', 'Session'));

        // The new one's title would make "session", which the other already has.
        $this->assertSame(['session', '2026-03'], $this->assign([$kept, $new], [], true));
    }

    ///////////////////////////////////
    // assignSlugs: choosing new ones //
    ///////////////////////////////////

    public function test_assignSlugs_meetingsInAGroupAreNamedByTheirTitlesWithoutTheGroupsTitle(): void
    {
        $entries = [
            self::entry(self::planned(1, '2026-11-06 23:00', 'Global Outreach Conference: The Church and the Nations')),
            self::entry(self::planned(2, '2026-11-07 14:00', 'Global Outreach Conference: Saturday Breakfast & Lunch')),
            self::entry(self::planned(3, '2026-11-08 17:30', 'Global Outreach Conference: Q&A Luncheon')),
            self::entry(self::planned(4, '2026-11-08 00:00', "Worshipping in God's House")),
        ];

        $slugs = $this->assign($entries, [], true, 'Global Outreach Conference');

        $this->assertSame(['the-church-and-the-nations', 'saturday-breakfast-lunch', 'q-a-luncheon', 'worshipping-in-gods-house'], $slugs);
    }

    public function test_assignSlugs_meetingsOutsideAGroupAreNamedByTheirDates(): void
    {
        $entries = [self::entry(self::planned(1, '2026-03-14 19:00', 'Opening Session'))];

        $this->assertSame(['2026-03'], $this->assign($entries, [], false));
    }

    public function test_assignSlugs_titlesThatRepeatAreReplacedByDates(): void
    {
        $entries = [
            self::entry(self::planned(1, '2026-03-14 19:00', 'Session')),
            self::entry(self::planned(2, '2026-03-21 19:00', 'Session')),
        ];

        $this->assertSame(['2026-03-14', '2026-03-21'], $this->assign($entries, [], true));
    }

    public function test_assignSlugs_datesGetMoreSpecificUntilTheyAreUnique(): void
    {
        $entries = [
            self::entry(self::planned(1, '2026-03-14 09:00', 'Session')),
            self::entry(self::planned(2, '2026-03-14 14:00', 'Session')),
            self::entry(self::planned(3, '2026-03-15 09:00', 'Session')),
        ];

        // Meetings 1 and 2 share a day, so they get their hours.  Meeting 3 is alone on its day.
        $this->assertSame(['2026-03-14-9', '2026-03-14-2', '2026-03-15'], $this->assign($entries, [], true));
    }

    public function test_assignSlugs_aSlugThatIsTakenIsSkipped(): void
    {
        $entries = [self::entry(self::planned(1, '2026-03-14 19:00', 'Opening Session'))];

        $this->assertSame(['2026-03-14'], $this->assign($entries, ['2026-03'], false));
        $this->assertSame(['2026-03-14'], $this->assign([self::entry(self::planned(1, '2026-03-14 19:00', 'Opening Session'))], ['2026-03', 'opening-session'], true));
    }

    public function test_assignSlugs_whenNothingIsUniqueTheMeetingIdIsUsed(): void
    {
        $entries = [
            self::entry(self::planned(11, '2026-03-14 19:00', 'Session')),
            self::entry(self::planned(12, '2026-03-14 19:00', 'Session')),
        ];

        $this->assertSame(['11', '12'], $this->assign($entries, [], true));
    }

    public function test_assignSlugs_aMeetingIdThatIsTakenGetsANumberAdded(): void
    {
        $entries = [
            self::entry(self::planned(11, '2026-03-14 19:00', 'Session')),
            self::entry(self::planned(12, '2026-03-14 19:00', 'Session')),
        ];

        $this->assertSame(['11-2', '12'], $this->assign($entries, ['11'], true));
    }

    public function test_assignSlugs_aGroupsIdUsedAsASlugIsPositive(): void
    {
        // A group's ID is the negative of its first meeting's.
        $a = self::group(MeetingArray::ROLE_CLUSTER, 'Day', [self::planned(5, '2026-03-14 09:00', 'x'), self::planned(6, '2026-03-14 10:00', 'x')]);
        $b = self::group(MeetingArray::ROLE_CLUSTER, 'Day', [self::planned(7, '2026-03-14 09:00', 'x'), self::planned(8, '2026-03-14 10:00', 'x')]);

        $slugs = $this->assign([self::entry($a, null, true), self::entry($b, null, true)], [], true);

        $this->assertSame(['5', '7'], $slugs);
    }

    public function test_assignSlugs_editionsAreNamedByTheirFirstMeetingsDate(): void
    {
        $march = self::group(MeetingArray::ROLE_EDITION, 'Annual Event', [self::planned(1, '2026-03-14 19:00', 'x'), self::planned(2, '2026-03-15 19:00', 'x')]);
        $june  = self::group(MeetingArray::ROLE_EDITION, 'Annual Event', [self::planned(3, '2026-06-06 19:00', 'x'), self::planned(4, '2026-06-07 19:00', 'x')]);

        $slugs = $this->assign([self::entry($march, null, true), self::entry($june, null, true)]);

        $this->assertSame(['2026-03', '2026-06'], $slugs);
    }

    public function test_assignSlugs_editionsInTheSameMonthAreToldApartByDay(): void
    {
        $first  = self::group(MeetingArray::ROLE_EDITION, 'Annual Event', [self::planned(1, '2026-03-01 19:00', 'x'), self::planned(2, '2026-03-02 19:00', 'x')]);
        $second = self::group(MeetingArray::ROLE_EDITION, 'Annual Event', [self::planned(3, '2026-03-20 19:00', 'x'), self::planned(4, '2026-03-21 19:00', 'x')]);

        $slugs = $this->assign([self::entry($first, null, true), self::entry($second, null, true)]);

        $this->assertSame(['2026-03-01', '2026-03-20'], $slugs);
    }

    public function test_assignSlugs_editionsNeverUseTheirTitle(): void
    {
        $edition = self::group(MeetingArray::ROLE_EDITION, 'Annual Event', [self::planned(1, '2026-03-14 19:00', 'x'), self::planned(2, '2026-03-15 19:00', 'x')]);

        $this->assertSame(['2026-03'], $this->assign([self::entry($edition, null, true)], [], true));
    }

    //////////////////////
    // slugCandidates   //
    //////////////////////

    /**
     * @param object $item
     * @param bool   $isGroup
     * @param bool   $insideGroup
     *
     * @return string[]
     */
    private function candidates(object $item, bool $isGroup, bool $insideGroup, string $parentTitle = 'Parent'): array
    {
        return self::callStatic(
            Involvement::class,
            'slugCandidates',
            $item,
            $isGroup,
            $insideGroup,
            new WP_Post(['ID' => 10, 'post_title' => $parentTitle])
        );
    }

    public function test_slugCandidates_aMeetingOutsideAGroupHasOnlyDates(): void
    {
        $this->assertSame(
            ['2026-03', '2026-03-14', '2026-03-14-7', '2026-03-14-7pm', '2026-03-14-700pm', '2026-03-14-190000'],
            $this->candidates(self::planned(1, '2026-03-14 19:00', 'Opening Session'), false, false)
        );
    }

    public function test_slugCandidates_aMeetingInAGroupTriesItsTitleFirst(): void
    {
        $candidates = $this->candidates(self::planned(1, '2026-03-14 19:00', 'Opening Session'), false, true);

        $this->assertSame('opening-session', $candidates[0]);
        $this->assertSame('2026-03', $candidates[1]);
        $this->assertCount(7, $candidates);
    }

    public function test_slugCandidates_aClusterTriesItsTitleFirst(): void
    {
        $cluster = self::group(MeetingArray::ROLE_CLUSTER, 'Friday Sessions', [self::planned(1, '2026-03-13 09:00', 'x'), self::planned(2, '2026-03-13 10:00', 'x')]);

        $candidates = $this->candidates($cluster, true, false);

        $this->assertSame('friday-sessions', $candidates[0]);
        $this->assertSame('2026-03', $candidates[1]);
    }

    public function test_slugCandidates_anEditionHasOnlyDates(): void
    {
        $edition = self::group(MeetingArray::ROLE_EDITION, 'Annual Event', [self::planned(1, '2026-03-13 09:00', 'x'), self::planned(2, '2026-03-14 10:00', 'x')]);

        $this->assertSame(
            ['2026-03', '2026-03-13', '2026-03-13-9', '2026-03-13-9am', '2026-03-13-900am', '2026-03-13-090000'],
            $this->candidates($edition, true, false)
        );
    }

    public function test_slugCandidates_aBlankTitleIsLeftOut(): void
    {
        $candidates = $this->candidates(self::planned(1, '2026-03-14 19:00', ''), false, true);

        $this->assertSame('2026-03', $candidates[0]);
        $this->assertCount(6, $candidates);
    }

    /////////////////
    // titleSlug   //
    /////////////////

    /**
     * @return array[] [title, the parent's title, the slug]
     */
    public static function provider_titleSlugs(): array
    {
        return [
            'the parent\'s title and a colon'  => ['Global Outreach Conference: Q&A Luncheon', 'Global Outreach Conference', 'q-a-luncheon'],
            'the parent\'s title and a dash'   => ['Global Outreach Conference - Day 1', 'Global Outreach Conference', 'day-1'],
            'a title that isn\'t the parent\'s' => ['Opening Session', 'Global Outreach Conference', 'opening-session'],
            'apostrophes'                      => ["Worshipping in God's House", 'Parent', 'worshipping-in-gods-house'],
            'curly apostrophes'                => ['Worshipping in God’s House', 'Parent', 'worshipping-in-gods-house'],
            'accents'                          => ['Reunión de Oración', 'Parent', 'reunion-de-oracion'],
            'the same title as the parent'     => ['Christmas Lessons & Carols', 'Christmas Lessons & Carols', 'christmas-lessons-carols'],
        ];
    }

    /**
     * @dataProvider provider_titleSlugs
     */
    public function test_titleSlug(string $title, string $parentTitle, string $expected): void
    {
        $slug = self::callStatic(Involvement::class, 'titleSlug', $title, new WP_Post(['post_title' => $parentTitle]));

        $this->assertSame($expected, $slug);
    }

    ///////////////////////////////////////////////
    // Titles, content, and which items get posts //
    ///////////////////////////////////////////////

    public function test_titleForItem_isTheItemsTitleOrElseTheOwners(): void
    {
        $ctx = (object)['owner' => (object)['titleToUse' => 'Owner Title']];

        $this->assertSame('Item Title', self::callStatic(Involvement::class, 'titleForItem', (object)['titleToUse' => 'Item Title'], $ctx));
        $this->assertSame('Owner Title', self::callStatic(Involvement::class, 'titleForItem', (object)[], $ctx));
        $this->assertSame('', self::callStatic(Involvement::class, 'titleForItem', (object)[], (object)['owner' => (object)[]]));
    }

    public function test_contentForItem_meetingsInAClusterHaveNone(): void
    {
        $inv = (object)['description' => '<p>Description</p>'];

        $this->assertSame('', self::callStatic(Involvement::class, 'contentForItem', self::planned(1, '2026-03-14 19:00', 'x'), $inv, true));
    }

    public function test_contentForItem_isTheStandardizedDescription(): void
    {
        $inv = (object)['description' => '<h1>Welcome</h1><p>Hello <span>big</span> world</p>'];

        $content = self::callStatic(Involvement::class, 'contentForItem', self::planned(1, '2026-03-14 19:00', 'x'), $inv, false);

        $this->assertSame('<h2>Welcome</h2><p>Hello big world</p>', $content);
    }

    public function test_contentForItem_usesTheMeetingImportContextForFilters(): void
    {
        $context = null;
        add_filter('tp_pre_standardize_html', function ($html, $c) use (&$context) {
            $context = $c;
            return $html;
        }, 10, 2);

        self::callStatic(Involvement::class, 'contentForItem', self::planned(1, '2026-03-14 19:00', 'x'), (object)['description' => '<p>x</p>'], false);

        $this->assertSame('meeting-import', $context);
    }

    public function test_contentForItem_noDescriptionIsNoContent(): void
    {
        $item = self::planned(1, '2026-03-14 19:00', 'x');

        $this->assertSame('', self::callStatic(Involvement::class, 'contentForItem', $item, (object)['description' => null], false));
        $this->assertSame('', self::callStatic(Involvement::class, 'contentForItem', $item, (object)['description' => "  \n "], false));
        $this->assertSame('', self::callStatic(Involvement::class, 'contentForItem', $item, (object)[], false));
    }

    public function test_endTimestamp_isTheEndOrElseTheStart(): void
    {
        $withEnd    = self::meeting(1, 1, '2026-03-14 19:00', '2026-03-14 20:30');
        $withoutEnd = self::meeting(2, 1, '2026-03-14 19:00');

        $this->assertSame(self::when('2026-03-14 20:30')->getTimestamp(), self::callStatic(Involvement::class, 'endTimestamp', $withEnd));
        $this->assertSame(self::when('2026-03-14 19:00')->getTimestamp(), self::callStatic(Involvement::class, 'endTimestamp', $withoutEnd));
    }

    /**
     * The details of what's planned, and what already exists, that shouldCreate() reads.  The archive cutoff is the
     * start of March 10, 2026.
     *
     * @param WP_Post[] $postsByMeetingId
     *
     * @return stdClass
     */
    private static function context(array $postsByMeetingId = []): stdClass
    {
        return (object)['meetingPosts' => $postsByMeetingId, 'cutoff' => self::when('2026-03-10 00:00')->getTimestamp()];
    }

    public function test_shouldCreate_anythingThatIsntArchivedGetsAPost(): void
    {
        $meeting = self::meeting(1, 1, '2026-03-14 19:00', '2026-03-14 20:00');
        $group   = self::group(MeetingArray::ROLE_CLUSTER, 'Day', [$meeting, self::meeting(2, 1, '2026-03-14 21:00')]);

        $this->assertTrue(self::callStatic(Involvement::class, 'shouldCreate', $meeting, false, self::context()));
        $this->assertTrue(self::callStatic(Involvement::class, 'shouldCreate', $group, false, self::context()));
    }

    public function test_shouldCreate_anArchivedMeetingDoesntGetANewPost(): void
    {
        $meeting = self::meeting(1, 1, '2026-03-01 19:00', '2026-03-01 20:00');

        $this->assertFalse(self::callStatic(Involvement::class, 'shouldCreate', $meeting, true, self::context()));
    }

    public function test_shouldCreate_anArchivedGroupOfArchivedMeetingsWithoutPostsIsntCreated(): void
    {
        $group = self::group(MeetingArray::ROLE_CLUSTER, 'Day', [
            self::meeting(1, 1, '2026-03-01 09:00', '2026-03-01 10:00'),
            self::meeting(2, 1, '2026-03-01 11:00', '2026-03-01 12:00'),
        ]);

        $this->assertFalse(self::callStatic(Involvement::class, 'shouldCreate', $group, true, self::context()));
    }

    public function test_shouldCreate_anArchivedGroupIsCreatedIfAMeetingInItHasAPost(): void
    {
        $group = self::group(MeetingArray::ROLE_CLUSTER, 'Day', [
            self::meeting(1, 1, '2026-03-01 09:00', '2026-03-01 10:00'),
            self::meeting(2, 1, '2026-03-01 11:00', '2026-03-01 12:00'),
        ]);

        $this->assertTrue(self::callStatic(Involvement::class, 'shouldCreate', $group, true, self::context([2 => new WP_Post(['ID' => 7])])));
    }

    public function test_shouldCreate_anArchivedGroupIsCreatedIfAMeetingInItIsntArchived(): void
    {
        $group = self::group(MeetingArray::ROLE_EDITION, 'Annual Event', [
            self::meeting(1, 1, '2026-03-01 09:00', '2026-03-01 10:00'),
            self::meeting(2, 1, '2026-03-10 09:00', '2026-03-10 10:00'),
        ]);

        $this->assertTrue(self::callStatic(Involvement::class, 'shouldCreate', $group, true, self::context()));
    }

    public function test_involvementForGroup_aClusterIsItsInvolvementAndAnythingElseIsTheOwner(): void
    {
        $owner = self::involvement(1, 'Owner');
        $child = self::involvement(2, 'Child');
        $ctx   = (object)['owner' => $owner, 'involvements' => [1 => $owner, 2 => $child]];

        $cluster      = self::group(MeetingArray::ROLE_CLUSTER, 'Child', [self::meeting(1, 2, '2026-03-14 09:00')], $child);
        $unknown      = self::group(MeetingArray::ROLE_CLUSTER, 'Other', [self::meeting(2, 99, '2026-03-14 09:00')], self::involvement(99, 'Other'));
        $edition      = self::group(MeetingArray::ROLE_EDITION, 'Owner', [self::meeting(3, 1, '2026-03-14 09:00')], $child);

        $this->assertSame($child, self::callStatic(Involvement::class, 'involvementForGroup', $cluster, $ctx));
        $this->assertSame($owner, self::callStatic(Involvement::class, 'involvementForGroup', $unknown, $ctx), 'A cluster of an involvement that isn\'t known.');
        $this->assertSame($owner, self::callStatic(Involvement::class, 'involvementForGroup', $edition, $ctx));
    }

    public function test_collectPlannedItems_listsMeetingsAndGroupsParentsFirst(): void
    {
        $a        = self::meeting(1, 1, '2026-03-14 09:00');
        $b        = self::meeting(2, 1, '2026-03-14 10:00');
        $c        = self::meeting(3, 1, '2026-03-15 09:00');
        $cluster  = self::group(MeetingArray::ROLE_CLUSTER, 'Day', [$a, $b]);
        $edition  = self::group(MeetingArray::ROLE_EDITION, 'Event', [$cluster, $c]);
        $alone    = self::meeting(4, 1, '2026-06-01 09:00');

        $leaves = [];
        $groups = [];
        $method = new ReflectionMethod(Involvement::class, 'collectPlannedItems');
        if (PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }
        $method->invokeArgs(null, [[$edition, $alone], &$leaves, &$groups]);

        $this->assertSame([$a, $b, $c, $alone], $leaves);
        $this->assertSame([$edition, $cluster], $groups);
    }
}
