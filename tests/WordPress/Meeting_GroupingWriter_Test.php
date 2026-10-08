<?php
/**
 * Tests for writing Meeting Grouping plans as WordPress posts
 *
 * @package TouchPointWP\Tests\WordPress
 */

namespace tp\TouchPointWP\Tests\WordPress;

use stdClass;
use tp\TouchPointWP\Involvement;
use tp\TouchPointWP\Involvement_PostTypeSettings;
use tp\TouchPointWP\Meeting;
use tp\TouchPointWP\Meeting_GroupingPlanner;
use tp\TouchPointWP\MeetingArray;
use tp\TouchPointWP\TouchPointWP;
use WP_Post;

/**
 * Test case for the way Meeting Grouping plans are turned into posts: the posts that are created, kept, adopted, moved,
 * renamed, and left alone, with the slugs WordPress and the plugin choose for them.  The tests in tests/Unit cover how
 * the plans are made, and the choices of slugs that don't depend on what's already in the database.
 *
 * The current time is Tuesday, 2026-03-10 at noon, and meetings that ended more than a week before that are archived.
 *
 * @covers \tp\TouchPointWP\Meeting_GroupingWriter
 */
class Meeting_GroupingWriter_Test extends WPTestCase
{
    private const POST_TYPE = 'tp_inv_writertest';

    private Involvement_PostTypeSettings $typeSets;

    private WP_Post $ownerPost;

    public function set_up(): void
    {
        parent::set_up();

        register_post_type(self::POST_TYPE, ['public' => true, 'hierarchical' => true]);
        $this->typeSets = new Involvement_PostTypeSettings((object)['postType' => 'inv_writertest']);

        $this->setSetting('mc_archive_days', 7);
        $this->setNow('2026-03-10 12:00');

        $this->ownerPost = get_post(self::factory()->post->create([
            'post_type'  => self::POST_TYPE,
            'post_title' => 'Spring Retreat',
        ]));
    }

    ///////////////
    // Helpers   //
    ///////////////

    /**
     * A meeting that lasts an hour.
     *
     * @param int     $id
     * @param string  $start
     * @param ?string $name
     * @param int     $involvementId
     *
     * @return stdClass
     */
    private static function hourLong(int $id, string $start, ?string $name = null, int $involvementId = 1): stdClass
    {
        return self::meeting($id, $involvementId, $start, date('Y-m-d H:i', strtotime("$start UTC") + 3600), $name);
    }

    /**
     * The structure owner.
     *
     * @param stdClass[] $meetings
     * @param string     $description
     *
     * @return stdClass
     */
    private static function owner(array $meetings, string $description = '<p>Come along.</p>'): stdClass
    {
        $owner              = self::involvement(1, 'Spring Retreat', $meetings);
        $owner->titleToUse  = 'Spring Retreat';
        $owner->description = $description;

        return $owner;
    }

    /**
     * Plan the meetings and write the plan as posts.
     *
     * @param stdClass   $owner
     * @param bool       $editions
     * @param bool       $clusters
     * @param stdClass[] $children
     * @param int        $imagePostId
     * @param bool       $applyChanges
     * @param bool       $verbose
     *
     * @return int[] The IDs of the posts to keep.
     */
    private function write(
        stdClass $owner,
        bool $editions,
        bool $clusters,
        array $children = [],
        int $imagePostId = 0,
        bool $applyChanges = true,
        bool $verbose = false
    ): array {
        $involvements = [$owner, ...$children];
        $items        = (new Meeting_GroupingPlanner($owner, $involvements, $editions, $clusters, 25 * 86400, 2 * 3600))->plan();

        return self::callStatic(
            Involvement::class,
            'writeGroupingPlan',
            $this->ownerPost,
            $owner,
            $involvements,
            $items,
            $this->typeSets,
            $imagePostId,
            $verbose,
            $applyChanges
        );
    }

    /**
     * The posts directly under another post, in the order they were made.
     *
     * @param int $parentId
     *
     * @return WP_Post[]
     */
    private function childrenOf(int $parentId): array
    {
        return get_posts([
            'post_type'   => self::POST_TYPE,
            'post_parent' => $parentId,
            'post_status' => 'any',
            'numberposts' => -1,
            'orderby'     => 'ID',
            'order'       => 'ASC',
        ]);
    }

    /**
     * A description of the posts under another post, with each post's slug, and its children.  Meetings are shown by
     * their meeting ID, and groups by their role.
     *
     * @param int $parentId
     *
     * @return array
     */
    private function treeUnder(int $parentId): array
    {
        $tree = [];
        foreach ($this->childrenOf($parentId) as $post) {
            $role = get_post_meta($post->ID, Meeting::MEETING_GROUP_ROLE_META_KEY, true);
            $key  = $post->post_name . ($role !== '' ? " ($role)" : ' (meeting ' . get_post_meta($post->ID, Meeting::MEETING_META_KEY, true) . ')');

            $tree[$key] = $this->treeUnder($post->ID);
        }

        return $tree;
    }

    /**
     * The post that has a meeting's ID.
     *
     * @param int $mtgId
     *
     * @return WP_Post
     */
    private function postOfMeeting(int $mtgId): WP_Post
    {
        $posts = get_posts([
            'post_type'   => self::POST_TYPE,
            'post_status' => 'any',
            'numberposts' => -1,
            'meta_key'    => Meeting::MEETING_META_KEY,
            'meta_value'  => $mtgId,
        ]);

        $this->assertCount(1, $posts, "Posts for meeting $mtgId");

        return $posts[0];
    }

    /**
     * The post of a group with a role, of which there is expected to be only one.
     *
     * @param string $role "edition" or "cluster".
     *
     * @return WP_Post
     */
    private function groupPost(string $role): WP_Post
    {
        $posts = get_posts([
            'post_type'   => self::POST_TYPE,
            'post_status' => 'any',
            'numberposts' => -1,
            'meta_key'    => Meeting::MEETING_GROUP_ROLE_META_KEY,
            'meta_value'  => $role,
        ]);

        $this->assertCount(1, $posts, "Posts for a $role");

        return $posts[0];
    }

    /**
     * The meetings used by most tests.  Four are upcoming, one is far in the future, and one is archived.
     *
     * @return stdClass[]
     */
    private static function retreatMeetings(): array
    {
        return [
            self::hourLong(1, '2026-04-10 09:00'),
            self::hourLong(2, '2026-04-10 10:00'),
            self::hourLong(3, '2026-04-10 11:00'),
            self::hourLong(4, '2026-04-11 09:00', 'Saturday Breakfast'),
            self::hourLong(5, '2026-06-20 09:00'),
            self::hourLong(6, '2026-02-01 09:00'),   // Archived.
        ];
    }

    //////////////////////////////
    // Meetings with no groups  //
    //////////////////////////////

    public function test_eachMeetingGetsAPostUnderTheOwnersPost(): void
    {
        $this->write(self::owner(self::retreatMeetings()), false, false);

        $this->assertSame(
            [
                '2026-04-10-9 (meeting 1)'  => [],
                '2026-04-10-10 (meeting 2)' => [],
                '2026-04-10-11 (meeting 3)' => [],
                '2026-04-11 (meeting 4)'    => [],
                '2026-06 (meeting 5)'       => [],
            ],
            $this->treeUnder($this->ownerPost->ID)
        );
    }

    public function test_meetingsThatAreArchivedAndHaveNoPostAreNotCreated(): void
    {
        $this->write(self::owner(self::retreatMeetings()), false, false);

        $posts = get_posts([
            'post_type'   => self::POST_TYPE,
            'post_status' => 'any',
            'numberposts' => -1,
            'meta_key'    => Meeting::MEETING_META_KEY,
            'meta_value'  => 6,
        ]);

        $this->assertCount(0, $posts);
    }

    public function test_theKeptPostsAreEverythingThatWasWritten(): void
    {
        $keep = $this->write(self::owner(self::retreatMeetings()), false, true);

        $all = array_map(fn($p) => $p->ID, get_posts([
            'post_type'   => self::POST_TYPE,
            'post_status' => 'any',
            'numberposts' => -1,
            'exclude'     => [$this->ownerPost->ID],
        ]));

        $this->assertEqualsCanonicalizing($all, $keep);
    }

    public function test_meetingPostsGetTheirTitleDescriptionAndDetails(): void
    {
        $owner = self::owner(
            [self::hourLong(1, '2026-04-10 09:00'), self::hourLong(4, '2026-04-11 09:00', 'Saturday Breakfast')],
            '<h1>Welcome</h1><p>Come <script>alert(1)</script>along.</p>'
        );
        $this->write($owner, false, false);

        $first  = $this->postOfMeeting(1);
        $second = $this->postOfMeeting(4);

        $this->assertSame('Spring Retreat', $first->post_title, "An involvement's meeting is called what the involvement is.");
        $this->assertSame('Saturday Breakfast', $second->post_title, 'A meeting with its own name uses it.');
        $this->assertSame('<h2>Welcome</h2><p>Come along.</p>', $first->post_content, 'The description is standardized.');
        $this->assertSame('publish', $first->post_status);
        $this->assertSame($this->ownerPost->ID, $first->post_parent);

        $this->assertSame('1', (string)get_post_meta($first->ID, Meeting::MEETING_INV_ID_META_KEY, true));
        $this->assertSame('1', (string)get_post_meta($first->ID, Meeting::MEETING_STATUS_META_KEY, true));
        $this->assertSame('0', (string)get_post_meta($first->ID, Meeting::MEETING_IS_GROUP_MEMBER, true));
        $this->assertNotSame('', get_post_meta($first->ID, Meeting::MEETING_START_META_KEY, true));
        $this->assertNotSame('', get_post_meta($first->ID, Meeting::MEETING_END_META_KEY, true));
    }

    ///////////////////////
    // Clusters          //
    ///////////////////////

    public function test_aClusterIsAPostThatHoldsItsMeetings(): void
    {
        $this->write(self::owner(self::retreatMeetings()), false, true);

        $this->assertSame(
            [
                'spring-retreat (cluster)' => [
                    '2026-04-10-9 (meeting 1)'  => [],
                    '2026-04-10-10 (meeting 2)' => [],
                    '2026-04-10-11 (meeting 3)' => [],
                ],
                '2026-04-11 (meeting 4)' => [],
                '2026-06 (meeting 5)'    => [],
            ],
            $this->treeUnder($this->ownerPost->ID)
        );
    }

    public function test_aClustersPostRemembersItsMeetings(): void
    {
        $this->write(self::owner(self::retreatMeetings()), false, true);

        $cluster = $this->groupPost(MeetingArray::ROLE_CLUSTER);
        $members = array_map('intval', get_post_meta($cluster->ID, Meeting::MEETING_GROUP_MEMBERS_META_KEY));
        sort($members);

        $this->assertSame(MeetingArray::ROLE_CLUSTER, get_post_meta($cluster->ID, Meeting::MEETING_GROUP_ROLE_META_KEY, true));
        $this->assertSame([1, 2, 3], $members);
        $this->assertSame('-1', (string)get_post_meta($cluster->ID, Meeting::MEETING_META_KEY, true), 'A group has the negative of its first meeting\'s ID.');
    }

    public function test_meetingsInAClusterHaveNoDescriptionAndKnowTheyAreMembers(): void
    {
        $this->write(self::owner(self::retreatMeetings()), false, true);

        $cluster = $this->groupPost(MeetingArray::ROLE_CLUSTER);
        $member  = $this->postOfMeeting(2);

        $this->assertSame('<p>Come along.</p>', $cluster->post_content, 'The cluster shows the description.');
        $this->assertSame('', $member->post_content);
        $this->assertSame('1', (string)get_post_meta($member->ID, Meeting::MEETING_IS_GROUP_MEMBER, true));
    }

    ///////////////////////
    // Editions          //
    ///////////////////////

    public function test_anEditionHoldsClustersAndMeetings(): void
    {
        $this->write(self::owner(self::retreatMeetings()), true, true);

        $this->assertSame(
            [
                '2026-04 (edition)' => [
                    'spring-retreat (cluster)'       => [
                        '2026-04-10-9 (meeting 1)'  => [],
                        '2026-04-10-10 (meeting 2)' => [],
                        '2026-04-10-11 (meeting 3)' => [],
                    ],
                    'saturday-breakfast (meeting 4)' => [],
                ],
                '2026-06 (meeting 5)' => [],
            ],
            $this->treeUnder($this->ownerPost->ID),
            'The meeting in June is too far from the others to be part of the Edition, and a lone meeting isn\'t an Edition.'
        );
    }

    public function test_anEditionRemembersAllOfItsMeetings(): void
    {
        $this->write(self::owner(self::retreatMeetings()), true, true);

        $edition = $this->groupPost(MeetingArray::ROLE_EDITION);
        $members = array_map('intval', get_post_meta($edition->ID, Meeting::MEETING_GROUP_MEMBERS_META_KEY));
        sort($members);

        $this->assertSame(MeetingArray::ROLE_EDITION, get_post_meta($edition->ID, Meeting::MEETING_GROUP_ROLE_META_KEY, true));
        $this->assertSame([1, 2, 3, 4], $members);
    }

    ////////////////////////////////////////
    // Writing a plan again, or a new plan //
    ////////////////////////////////////////

    public function test_writingThePlanAgainChangesNothing(): void
    {
        $owner = self::owner(self::retreatMeetings());

        $firstKeep  = $this->write($owner, true, true);
        $firstTree  = $this->treeUnder($this->ownerPost->ID);
        $secondKeep = $this->write($owner, true, true);

        $this->assertEqualsCanonicalizing($firstKeep, $secondKeep, 'The same posts are kept, so none were added.');
        $this->assertSame($firstTree, $this->treeUnder($this->ownerPost->ID));
    }

    public function test_postsAreReusedWhenMeetingsAreGroupedDifferently(): void
    {
        $owner = self::owner(self::retreatMeetings());

        $this->write($owner, false, false);
        $before = [1 => $this->postOfMeeting(1)->ID, 4 => $this->postOfMeeting(4)->ID, 5 => $this->postOfMeeting(5)->ID];

        $this->write($owner, true, true);

        $this->assertSame($before[1], $this->postOfMeeting(1)->ID);
        $this->assertSame($before[4], $this->postOfMeeting(4)->ID);
        $this->assertSame($before[5], $this->postOfMeeting(5)->ID);

        $edition = $this->groupPost(MeetingArray::ROLE_EDITION);
        $this->assertSame($edition->ID, $this->postOfMeeting(4)->post_parent, 'The post of the meeting moved into the Edition.');
        $this->assertSame($this->ownerPost->ID, $this->postOfMeeting(5)->post_parent);
    }

    public function test_aGroupKeepsItsPostWhenItsMeetingsChange(): void
    {
        $this->write(self::owner(self::retreatMeetings()), false, true);
        $cluster = $this->groupPost(MeetingArray::ROLE_CLUSTER)->ID;

        // Meeting 3 moves to another day, so it's no longer in the cluster.
        $changed = self::retreatMeetings();
        $changed[2] = self::hourLong(3, '2026-04-17 11:00');
        $this->write(self::owner($changed), false, true);

        $members = array_map('intval', get_post_meta($cluster, Meeting::MEETING_GROUP_MEMBERS_META_KEY));
        sort($members);

        $this->assertSame([1, 2], $members);
        $this->assertSame($cluster, $this->postOfMeeting(2)->post_parent);
        $this->assertSame($this->ownerPost->ID, $this->postOfMeeting(3)->post_parent);
    }

    public function test_anExistingPostForAMeetingIsAdopted(): void
    {
        // For example, a post from before the plugin grouped meetings, or one that was moved by hand.
        $existing = self::factory()->post->create([
            'post_type'  => self::POST_TYPE,
            'post_title' => 'Old title',
            'post_name'  => 'old-name',
        ]);
        update_post_meta($existing, Meeting::MEETING_META_KEY, 2);

        $this->write(self::owner(self::retreatMeetings()), false, false);

        $post = $this->postOfMeeting(2);

        $this->assertSame($existing, $post->ID, 'No second post was made.');
        $this->assertSame($this->ownerPost->ID, $post->post_parent);
        $this->assertSame('Spring Retreat', $post->post_title);
    }

    public function test_aChildInvolvementsPostThatWasAlsoAMeetingsPostIsNowOnlyTheMeetingsPost(): void
    {
        $existing = self::factory()->post->create(['post_type' => self::POST_TYPE, 'post_title' => 'Friday Session']);
        update_post_meta($existing, Meeting::MEETING_META_KEY, 7);
        update_post_meta($existing, TouchPointWP::INVOLVEMENT_META_KEY, 2);

        $child              = self::involvement(2, 'Friday Session', [self::hourLong(7, '2026-04-10 18:00', null, 2)]);
        $child->titleToUse  = 'Friday Session';
        $child->description = '';

        $this->write(self::owner([self::hourLong(1, '2026-04-10 09:00')]), false, false, [$child]);

        $post = $this->postOfMeeting(7);

        $this->assertSame($existing, $post->ID);
        $this->assertSame('', get_post_meta($post->ID, TouchPointWP::INVOLVEMENT_META_KEY, true));
        $this->assertSame($this->ownerPost->ID, $post->post_parent);
    }

    public function test_aPostOfAnInvolvementThatIsNotInTheStructureIsNotTakenOver(): void
    {
        $other = self::factory()->post->create(['post_type' => self::POST_TYPE, 'post_title' => 'Unrelated']);
        update_post_meta($other, Meeting::MEETING_META_KEY, 2);
        update_post_meta($other, TouchPointWP::INVOLVEMENT_META_KEY, 99);   // Not one of this structure's involvements.

        $this->write(self::owner(self::retreatMeetings()), false, false);

        $this->assertSame('Unrelated', get_post($other)->post_title);
        $this->assertSame(0, get_post($other)->post_parent);
    }

    //////////////////////////////////////////////
    // Titles, content, and archived posts      //
    //////////////////////////////////////////////

    public function test_titlesAndDescriptionsFollowTheInvolvementForUpcomingMeetings(): void
    {
        $this->write(self::owner(self::retreatMeetings()), false, false);

        $renamed              = self::owner(self::retreatMeetings(), '<p>New description.</p>');
        $renamed->titleToUse  = 'Summer Retreat';
        $this->write($renamed, false, false);

        $post = $this->postOfMeeting(5);

        $this->assertSame('Summer Retreat', $post->post_title);
        $this->assertSame('<p>New description.</p>', $post->post_content);
    }

    public function test_archivedPostsKeepWhatTheyHad(): void
    {
        $this->write(self::owner(self::retreatMeetings()), false, false);

        // A month later, the April meetings are archived.
        $this->setNow('2026-05-20 12:00');
        $renamed             = self::owner(self::retreatMeetings(), '<p>New description.</p>');
        $renamed->titleToUse = 'Summer Retreat';
        $this->write($renamed, false, false);

        $archived = $this->postOfMeeting(1);
        $upcoming = $this->postOfMeeting(5);

        $this->assertSame('Spring Retreat', $archived->post_title, 'What the meeting was called at the time.');
        $this->assertSame('<p>Come along.</p>', $archived->post_content);
        $this->assertSame('Summer Retreat', $upcoming->post_title);
        $this->assertSame('<p>New description.</p>', $upcoming->post_content);
    }

    public function test_archivedPostsAreStillKept(): void
    {
        $this->write(self::owner(self::retreatMeetings()), false, false);
        $this->setNow('2026-05-20 12:00');

        $keep = $this->write(self::owner(self::retreatMeetings()), false, false);

        $this->assertContains($this->postOfMeeting(1)->ID, $keep, 'Otherwise, the post would be deleted as stale.');
    }

    ///////////////////////////
    // Slugs, with real posts //
    ///////////////////////////

    public function test_aSlugUsedByAnotherPostUnderTheSameParentIsNotReused(): void
    {
        self::factory()->post->create([
            'post_type'   => self::POST_TYPE,
            'post_parent' => $this->ownerPost->ID,
            'post_title'  => 'Unrelated',
            'post_name'   => '2026-06',
        ]);

        $this->write(self::owner(self::retreatMeetings()), false, false);

        $this->assertSame('2026-06-20', $this->postOfMeeting(5)->post_name);
    }

    public function test_slugsOfExistingPostsAreKeptWhileTheyStayPut(): void
    {
        $this->write(self::owner(self::retreatMeetings()), false, false);

        $post = $this->postOfMeeting(4);
        wp_update_post(['ID' => $post->ID, 'post_name' => 'breakfast-by-hand']);

        $this->write(self::owner(self::retreatMeetings()), false, false);

        $this->assertSame('breakfast-by-hand', $this->postOfMeeting(4)->post_name);
    }

    public function test_aSlugThatIsJustANumberIsKeptEvenThoughWordPressWouldChangeIt(): void
    {
        // Meetings at the same time with the same name can only be told apart by their IDs.  WordPress reads a slug that
        // is only a number as a page number, and would add "-2" to it.
        $meetings = [self::hourLong(11, '2026-04-10 09:00'), self::hourLong(12, '2026-04-10 09:00')];

        $this->write(self::owner($meetings), false, false);

        $this->assertSame('11', $this->storedSlug($this->postOfMeeting(11)->ID));
        $this->assertSame('12', $this->storedSlug($this->postOfMeeting(12)->ID));

        // It stays that way the next time, which is another request, so WordPress doesn't remember the posts.
        wp_cache_flush();
        $this->write(self::owner($meetings), false, false);

        $this->assertSame('11', $this->storedSlug($this->postOfMeeting(11)->ID));
        $this->assertSame('12', $this->storedSlug($this->postOfMeeting(12)->ID));
    }

    ///////////////////////
    // Images            //
    ///////////////////////

    public function test_theImageIsSetOnEveryPostAndRemovedWhenThereIsNone(): void
    {
        $image = self::factory()->attachment->create(['post_mime_type' => 'image/jpeg']);
        add_filter('wp_get_attachment_image_src', fn() => ['http://example.org/image.jpg', 10, 10, false]);

        $this->write(self::owner(self::retreatMeetings()), true, true, [], $image);

        $this->assertSame($image, intval(get_post_thumbnail_id($this->postOfMeeting(1)->ID)));
        $this->assertSame($image, intval(get_post_thumbnail_id($this->postOfMeeting(5)->ID)));
        $this->assertSame($image, intval(get_post_thumbnail_id($this->groupPost(MeetingArray::ROLE_EDITION)->ID)), 'The Edition, too.');

        $this->write(self::owner(self::retreatMeetings()), true, true, [], 0);

        $this->assertSame(0, intval(get_post_thumbnail_id($this->postOfMeeting(1)->ID)));
        $this->assertSame(0, intval(get_post_thumbnail_id($this->postOfMeeting(5)->ID)));
    }

    ///////////////////////
    // Previews          //
    ///////////////////////

    public function test_aPreviewChangesNothingAndSaysWhatItWouldDo(): void
    {
        $this->write(self::owner(self::retreatMeetings()), false, false);   // Existing posts, to be moved.
        $treeBefore = $this->treeUnder($this->ownerPost->ID);
        $titleBefore = $this->postOfMeeting(5)->post_title;

        $renamed             = self::owner(self::retreatMeetings());
        $renamed->titleToUse = 'Summer Retreat';

        ob_start();
        $this->write($renamed, true, true, [], 0, false, true);
        $output = ob_get_clean();

        $this->assertSame($treeBefore, $this->treeUnder($this->ownerPost->ID));
        $this->assertSame($titleBefore, $this->postOfMeeting(5)->post_title);
        $this->assertStringContainsString('Would create a new post', $output);
        $this->assertStringContainsString('Planned structure', $output);
    }

    public function test_aPreviewOfANewStructureCreatesNoPosts(): void
    {
        ob_start();
        $this->write(self::owner(self::retreatMeetings()), true, true, [], 0, false, true);
        ob_end_clean();

        $this->assertSame([], $this->childrenOf($this->ownerPost->ID));
    }
}
