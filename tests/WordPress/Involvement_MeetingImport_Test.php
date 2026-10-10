<?php
/**
 * Tests for which involvements get posts for their meetings
 *
 * @package TouchPointWP\Tests\WordPress
 */

namespace tp\TouchPointWP\Tests\WordPress;

use stdClass;
use tp\TouchPointWP\Involvement;
use tp\TouchPointWP\Involvement_PostTypeSettings;
use tp\TouchPointWP\Meeting;
use WP_Post;

/**
 * Test case for deciding whether an involvement's meetings become posts.  That's part of the Meeting Calendar, so it only
 * happens while the calendar is enabled.  Then, it happens when the involvement is shown in sites, or when its Post Type
 * is set to import all of its meetings.  Otherwise, the meetings are only used to describe the involvement's schedule:
 * there are no posts for them, and no groups of them.
 *
 * (A Post Type's setting to import all meetings is itself off while the calendar is off.  That's in
 * Involvement_PostTypeSettings_Test.)
 *
 * The current time is Tuesday, 2026-03-10 at noon.
 *
 * @covers \tp\TouchPointWP\Involvement
 */
class Involvement_MeetingImport_Test extends WPTestCase
{
    private const POST_TYPE = 'tp_inv_importtest';

    public function set_up(): void
    {
        parent::set_up();

        register_post_type(self::POST_TYPE, ['public' => true, 'hierarchical' => true]);
        $this->setSetting('mc_archive_days', 7);
        $this->setNow('2026-03-10 12:00');
        $this->useGroupingSettings([]);
    }

    ///////////////
    // Helpers   //
    ///////////////

    /**
     * Turn the Meeting Calendar on or off.
     */
    private function calendar(bool $enabled): void
    {
        $this->setSetting('enable_meeting_cal', $enabled ? 'on' : '');
    }

    /**
     * Set Meeting Grouping settings that apply to every type of involvement.
     *
     * @param array $otherTypes Rule settings, such as ['clusters' => true].
     */
    private function useGroupingSettings(array $otherTypes): void
    {
        $this->saveGroupingSettings(['types' => [], 'otherTypes' => $otherTypes, 'legacyAvailable' => false]);
    }

    /**
     * The settings of the involvement's Post Type.
     *
     * @param bool $importMeetings Whether the type is set to import all meetings.
     */
    private function typeSettings(bool $importMeetings): Involvement_PostTypeSettings
    {
        return new Involvement_PostTypeSettings((object)['postType' => 'inv_importtest', 'importMeetings' => $importMeetings]);
    }

    /**
     * An involvement, as the API provides it after it's been standardized and classified.
     *
     * @param bool       $showInSites
     * @param stdClass[] $meetings
     *
     * @return stdClass
     */
    private static function makeInvolvement(bool $showInSites, array $meetings): stdClass
    {
        $inv                = self::involvement(1, 'Book Club', $meetings);
        $inv->titleToUse    = 'Book Club';
        $inv->description   = '<p>We read books.</p>';
        $inv->showInSites   = $showInSites;
        $inv->invTypeId     = 5;
        $inv->firstMeeting  = null;
        $inv->lastMeeting   = null;

        return $inv;
    }

    /**
     * An involvement's meetings: one a week, for three weeks, starting next month.
     *
     * @return stdClass[]
     */
    private static function weeklyMeetings(): array
    {
        return [
            self::meeting(11, 1, '2026-04-07 19:00', '2026-04-07 20:00'),
            self::meeting(12, 1, '2026-04-14 19:00', '2026-04-14 20:00'),
            self::meeting(13, 1, '2026-04-21 19:00', '2026-04-21 20:00'),
        ];
    }

    /**
     * Run the update of an involvement's meetings, as the importer does.
     *
     * @return int[] The IDs of the posts the importer would keep.
     */
    private function updateMeetings(WP_Post $post, stdClass $inv, Involvement_PostTypeSettings $typeSets): array
    {
        self::callStatic(Involvement::class, 'classifyForGrouping', [$inv], false);

        return self::callStatic(Involvement::class, 'updateMeetingsWithGrouping', $post, $inv, $typeSets, 0, false, true);
    }

    private function makeInvolvementPost(): WP_Post
    {
        $id = self::factory()->post->create(['post_type' => self::POST_TYPE, 'post_title' => 'Book Club']);
        update_post_meta($id, \tp\TouchPointWP\TouchPointWP::INVOLVEMENT_META_KEY, 1);

        return get_post($id);
    }

    /**
     * @return int How many posts have meeting IDs, that is, are posts for meetings or groups of them.
     */
    private function countMeetingPosts(): int
    {
        return count(get_posts([
            'post_type'   => self::POST_TYPE,
            'post_status' => 'any',
            'numberposts' => -1,
            'meta_key'    => Meeting::MEETING_META_KEY,
        ]));
    }

    ////////////////////////////////////////////////////////////
    // When the calendar is off, or neither the involvement      //
    // nor its type imports meetings                              //
    ////////////////////////////////////////////////////////////

    /**
     * @return array[] [whether the Meeting Calendar is on]
     */
    public static function provider_calendarStates(): array
    {
        return ['the calendar is off' => [false], 'the calendar is on' => [true]];
    }

    /**
     * @dataProvider provider_calendarStates
     */
    public function test_noMeetingPostsAreMadeWhenNothingSaysToImportMeetings(bool $calendar): void
    {
        $this->calendar($calendar);
        $post = $this->makeInvolvementPost();

        $keep = $this->updateMeetings($post, self::makeInvolvement(false, self::weeklyMeetings()), $this->typeSettings(false));

        $this->assertSame([$post->ID], $keep);
        $this->assertSame(0, $this->countMeetingPosts());
    }

    /**
     * @dataProvider provider_calendarStates
     */
    public function test_noGroupsOfMeetingsAreMadeWhenNothingSaysToImportMeetings(bool $calendar): void
    {
        $this->calendar($calendar);
        $this->useGroupingSettings(['clusters' => true, 'editions' => true]);
        $post = $this->makeInvolvementPost();

        $this->updateMeetings($post, self::makeInvolvement(false, self::weeklyMeetings()), $this->typeSettings(false));

        $this->assertSame(0, $this->countMeetingPosts());
        $this->assertSame([], get_children(['post_parent' => $post->ID, 'post_type' => self::POST_TYPE]));
    }

    /**
     * @dataProvider provider_calendarStates
     */
    public function test_aSingleMeetingDoesNotMakeTheInvolvementsPostAMeetingPostWhenNothingSaysToImportMeetings(bool $calendar): void
    {
        $this->calendar($calendar);
        $post = $this->makeInvolvementPost();

        $this->updateMeetings($post, self::makeInvolvement(false, [self::weeklyMeetings()[0]]), $this->typeSettings(false));

        $this->assertSame('', get_post_meta($post->ID, Meeting::MEETING_META_KEY, true));
        $this->assertSame('', get_post_meta($post->ID, Meeting::MEETING_START_META_KEY, true));
    }

    public function test_meetingPostsThatWereMadeAreNotKeptWhenNothingSaysToImportMeetingsAnymore(): void
    {
        // For example, "Show in Sites" was unchecked in TouchPoint.  The posts aren't in what's kept, so they're removed.
        $this->calendar(true);
        $post = $this->makeInvolvementPost();
        $keepBefore = $this->updateMeetings($post, self::makeInvolvement(true, self::weeklyMeetings()), $this->typeSettings(false));
        $this->assertCount(4, $keepBefore);

        $keepAfter = $this->updateMeetings($post, self::makeInvolvement(false, self::weeklyMeetings()), $this->typeSettings(false));

        $this->assertSame([$post->ID], $keepAfter);
    }

    ////////////////////////////////////////////////////////////
    // When the involvement is shown in sites, or its type    //
    // imports meetings                                       //
    ////////////////////////////////////////////////////////////

    public function test_aTypeThatImportsMeetingsMakesPostsForThem(): void
    {
        $this->calendar(true);
        $post = $this->makeInvolvementPost();

        $keep = $this->updateMeetings($post, self::makeInvolvement(false, self::weeklyMeetings()), $this->typeSettings(true));

        $this->assertSame(3, $this->countMeetingPosts());
        $this->assertCount(4, $keep, 'The involvement\'s post, and the meetings\'.');
    }

    public function test_anInvolvementShownInSitesMakesPostsForItsMeetingsWhetherOrNotItsTypeImportsMeetings(): void
    {
        $this->calendar(true);
        $post = $this->makeInvolvementPost();

        $this->updateMeetings($post, self::makeInvolvement(true, self::weeklyMeetings()), $this->typeSettings(false));

        $this->assertSame(3, $this->countMeetingPosts());
    }

    public function test_noMeetingPostsAreMadeForAnInvolvementShownInSitesWhileTheCalendarIsOff(): void
    {
        $this->calendar(false);
        $post = $this->makeInvolvementPost();

        $keep = $this->updateMeetings($post, self::makeInvolvement(true, self::weeklyMeetings()), $this->typeSettings(false));

        $this->assertSame([$post->ID], $keep);
        $this->assertSame(0, $this->countMeetingPosts());
    }

    public function test_noMeetingPostsAreMadeForATypeThatImportsMeetingsWhileTheCalendarIsOff(): void
    {
        // The setting is already off while the calendar is, but this doesn't depend on that.
        $this->calendar(false);
        $post = $this->makeInvolvementPost();

        $this->updateMeetings($post, self::makeInvolvement(true, self::weeklyMeetings()), $this->typeSettings(true));

        $this->assertSame(0, $this->countMeetingPosts());
    }

    public function test_noGroupsOfMeetingsAreMadeForAnInvolvementShownInSitesWhileTheCalendarIsOff(): void
    {
        $this->calendar(false);
        $this->useGroupingSettings(['clusters' => true, 'editions' => true]);
        $post = $this->makeInvolvementPost();

        $this->updateMeetings($post, self::makeInvolvement(true, self::weeklyMeetings()), $this->typeSettings(false));

        $this->assertSame(0, $this->countMeetingPosts());
        $this->assertSame([], get_children(['post_parent' => $post->ID, 'post_type' => self::POST_TYPE]));
    }

    public function test_aSingleMeetingDoesNotMakeTheInvolvementsPostAMeetingPostWhileTheCalendarIsOff(): void
    {
        $this->calendar(false);
        $post = $this->makeInvolvementPost();

        $this->updateMeetings($post, self::makeInvolvement(true, [self::weeklyMeetings()[0]]), $this->typeSettings(false));

        $this->assertSame('', get_post_meta($post->ID, Meeting::MEETING_META_KEY, true));
        $this->assertSame('', get_post_meta($post->ID, Meeting::MEETING_START_META_KEY, true));
    }

    public function test_meetingPostsThatWereMadeAreNotKeptOnceTheCalendarIsOff(): void
    {
        $this->calendar(true);
        $post = $this->makeInvolvementPost();
        $keepWhileOn = $this->updateMeetings($post, self::makeInvolvement(true, self::weeklyMeetings()), $this->typeSettings(false));
        $this->assertCount(4, $keepWhileOn);

        $this->calendar(false);
        $keepWhileOff = $this->updateMeetings($post, self::makeInvolvement(true, self::weeklyMeetings()), $this->typeSettings(false));

        $this->assertSame([$post->ID], $keepWhileOff);
    }

    public function test_aSingleMeetingMakesTheInvolvementsPostAMeetingPostWhenTheInvolvementIsShownInSites(): void
    {
        $this->calendar(true);
        $post = $this->makeInvolvementPost();

        $this->updateMeetings($post, self::makeInvolvement(true, [self::weeklyMeetings()[0]]), $this->typeSettings(false));

        $this->assertSame('11', (string)get_post_meta($post->ID, Meeting::MEETING_META_KEY, true));
    }

    public function test_meetingsAreGroupedWhenTheyAreImported(): void
    {
        $this->calendar(true);
        $this->useGroupingSettings(['editions' => true]);
        $post = $this->makeInvolvementPost();

        $this->updateMeetings($post, self::makeInvolvement(true, self::weeklyMeetings()), $this->typeSettings(false));

        $children = get_children(['post_parent' => $post->ID, 'post_type' => self::POST_TYPE]);
        $this->assertCount(1, $children, 'An Edition.');
        $this->assertSame('edition', get_post_meta(array_values($children)[0]->ID, Meeting::MEETING_GROUP_ROLE_META_KEY, true));
        $this->assertSame(4, $this->countMeetingPosts(), 'The Edition and its three meetings.');
    }

    ////////////////////////////////////////////////////////
    // Meeting Grouping, which is part of importing meetings //
    ////////////////////////////////////////////////////////

    /**
     * An owner and a child, as the API provides them for a structure.
     *
     * @return stdClass[]
     */
    private static function ownerAndChild(): array
    {
        $owner = self::makeInvolvement(true, [self::meeting(11, 1, '2026-04-07 19:00', '2026-04-07 20:00')]);
        $owner->ownerInvId = 1;

        $child              = self::makeInvolvement(true, [self::meeting(21, 2, '2026-04-08 19:00', '2026-04-08 20:00')]);
        $child->involvementId = 2;
        $child->ownerInvId    = 1;

        return [$owner, $child];
    }

    public function test_involvementsAreGroupedIntoStructuresWhileTheCalendarIsOn(): void
    {
        $this->calendar(true);
        $this->useGroupingSettings(['includeChildren' => true]);
        [$owner, $child] = self::ownerAndChild();

        self::callStatic(Involvement::class, 'classifyInvolvements', [$owner, $child], false);

        $this->assertSame('owner', $owner->_groupingRole);
        $this->assertSame('child', $child->_groupingRole);
        $this->assertSame([$child], $owner->_groupingStructure);
    }

    public function test_involvementsStandAloneWhileTheCalendarIsOff(): void
    {
        // However the data came to be arranged, and whatever the Meeting Grouping settings say, a child is not claimed by
        // an owner, which would leave nothing to make its own post.
        $this->calendar(false);
        $this->useGroupingSettings(['includeChildren' => true, 'clusters' => true, 'editions' => true]);
        [$owner, $child] = self::ownerAndChild();

        self::callStatic(Involvement::class, 'classifyInvolvements', [$owner, $child], false);

        $this->assertSame('normal', $owner->_groupingRole);
        $this->assertSame('normal', $child->_groupingRole);
        $this->assertSame([], $owner->_groupingStructure);
        $this->assertNull($owner->_grouping);
        $this->assertCount(1, $owner->meetings, 'The owner has only its own meetings.');
    }

    public function test_theApiIsToldAboutMeetingGroupingWhileTheCalendarIsOn(): void
    {
        $this->calendar(true);
        $this->saveGroupingSettings([
            'types'              => [['invTypeId' => 5, 'includeChildren' => true]],
            'otherTypes'         => ['includeChildren' => true],
            'keepHiddenChildren' => true,
        ]);

        $this->assertSame(
            ['childTypes' => '5', 'listedTypes' => '5', 'childOther' => 1, 'keepHidden' => 1],
            self::callStatic(Involvement::class, 'groupingQueryParameters')
        );
    }

    public function test_theApiIsNotToldAboutMeetingGroupingWhileTheCalendarIsOff(): void
    {
        $this->calendar(false);
        $this->saveGroupingSettings([
            'types'              => [['invTypeId' => 5, 'includeChildren' => true]],
            'otherTypes'         => ['includeChildren' => true],
            'keepHiddenChildren' => true,
        ]);

        $this->assertSame(
            ['childTypes' => '', 'listedTypes' => '', 'childOther' => 0, 'keepHidden' => 0],
            self::callStatic(Involvement::class, 'groupingQueryParameters')
        );
    }
}
