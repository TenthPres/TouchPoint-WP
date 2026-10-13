<?php
/**
 * Tests for the parts of Meeting_GroupingSettings that need WordPress's options and database
 *
 * @package TouchPointWP\Tests\WordPress
 */

namespace tp\TouchPointWP\Tests\WordPress;

use tp\TouchPointWP\Meeting;
use tp\TouchPointWP\Meeting_GroupingSettings;

/**
 * Test case for the way Meeting_GroupingSettings reads what's been saved, and works out what a site that hasn't saved
 * any Meeting Grouping settings should use.  The tests in tests/Unit cover what the settings do once they're loaded.
 *
 * @covers \tp\TouchPointWP\Meeting_GroupingSettings
 */
class Meeting_GroupingSettings_Test extends WPTestCase
{
    /**
     * Make a post that looks like one made by the previous behavior for collecting meetings: a collection's meeting ID
     * is the negative of its first meeting's, and it has no group role.
     *
     * @param int    $mtgId
     * @param string $status
     * @param ?string $role  A group role to give it, which only the current behavior sets.
     *
     * @return int The post's ID.
     */
    private function makeCollectionPost(int $mtgId, string $status = 'publish', ?string $role = null): int
    {
        $id = self::factory()->post->create(['post_status' => $status, 'post_type' => 'post']);
        update_post_meta($id, Meeting::MEETING_META_KEY, $mtgId);
        if ($role !== null) {
            update_post_meta($id, Meeting::MEETING_GROUP_ROLE_META_KEY, $role);
        }

        return $id;
    }

    ///////////////////////////////
    // Reading saved settings    //
    ///////////////////////////////

    public function test_savedSettingsAreUsed(): void
    {
        $this->saveGroupingSettings([
            'types'      => [['invTypeId' => 5, 'includeChildren' => true, 'editions' => true]],
            'otherTypes' => ['clusters' => true],
        ]);

        $own   = Meeting_GroupingSettings::forInvolvementType(5);
        $other = Meeting_GroupingSettings::forInvolvementType(9);

        $this->assertTrue($own->editions);
        $this->assertTrue($own->includeChildren);
        $this->assertFalse($own->clusters);
        $this->assertTrue($other->clusters);
        $this->assertFalse($other->editions);
    }

    public function test_savedSettingsComeBackOutTheSameWay(): void
    {
        $this->saveGroupingSettings([
            'types'              => [['invTypeId' => 5, 'includeChildren' => true, 'editions' => true, 'clusters' => false]],
            'otherTypes'         => ['clusters' => true],
            'keepHiddenChildren' => true,
        ]);

        $this->assertSame(
            ['childTypes' => '5', 'listedTypes' => '5', 'childOther' => 0, 'keepHidden' => 1],
            Meeting_GroupingSettings::involvementQueryParameters()
        );
        $this->assertSame(25 * 86400, Meeting_GroupingSettings::editionGap());
    }

    ///////////////////////////////////////////////////////////
    // A site that hasn't saved Meeting Grouping settings    //
    ///////////////////////////////////////////////////////////

    public function test_aSiteThatNeverCollectedMeetingsKeepsEverythingOff(): void
    {
        $this->makeCollectionPost(-10);   // Not relevant, because meetings weren't collected.

        $rule = Meeting_GroupingSettings::forOtherTypes();

        $this->assertFalse($rule->clusters);
        $this->assertFalse($rule->editions);
        $this->assertFalse($rule->legacy);
        $this->assertFalse(Meeting_GroupingSettings::legacyAvailable());
    }

    public function test_aSiteThatCollectedMeetingsButHasNoCollectionsGetsTheNewSiteDefaults(): void
    {
        $this->setSetting('enable_meeting_cal', 'on');
        $this->setSetting('mc_grouping_method', Meeting::GROUP_ALL);

        $rule = Meeting_GroupingSettings::forOtherTypes();

        $this->assertTrue($rule->clusters);
        $this->assertFalse($rule->legacy);
        $this->assertFalse(Meeting_GroupingSettings::legacyAvailable());
    }

    public function test_aSiteWithCollectionsFromThePreviousBehaviorKeepsIt(): void
    {
        $this->setSetting('enable_meeting_cal', 'on');
        $this->setSetting('mc_grouping_method', Meeting::GROUP_ALL);
        $this->makeCollectionPost(-10);

        $rule = Meeting_GroupingSettings::forOtherTypes();

        $this->assertTrue($rule->legacy);
        $this->assertFalse($rule->clusters);
        $this->assertTrue(Meeting_GroupingSettings::legacyAvailable());
        $this->assertTrue(Meeting_GroupingSettings::previousBehaviorInUse());
    }

    public function test_theMeetingCalendarBeingOffMeansMeetingsWereNotCollected(): void
    {
        $this->setSetting('enable_meeting_cal', '');
        $this->setSetting('mc_grouping_method', Meeting::GROUP_ALL);
        $this->makeCollectionPost(-10);

        $this->assertFalse(Meeting_GroupingSettings::forOtherTypes()->legacy);
    }

    public function test_notCollectingMeetingsMeansTheyWereNotCollected(): void
    {
        $this->setSetting('enable_meeting_cal', 'on');
        $this->setSetting('mc_grouping_method', Meeting::GROUP_NONE);
        $this->makeCollectionPost(-10);

        $rule = Meeting_GroupingSettings::forOtherTypes();

        $this->assertFalse($rule->legacy);
        $this->assertFalse($rule->clusters);
    }

    public function test_anInvolvementPostTypeThatCollectedMeetingsCounts(): void
    {
        $this->setSetting('inv_json', json_encode([
            ['postType' => 'one', 'importMeetings' => false, 'meetingGroupingMethod' => Meeting::GROUP_ALL],
            ['postType' => 'two', 'importMeetings' => true, 'meetingGroupingMethod' => Meeting::GROUP_ALL],
        ]));
        $this->makeCollectionPost(-10);

        $this->assertTrue(Meeting_GroupingSettings::forOtherTypes()->legacy);
    }

    public function test_anInvolvementPostTypeThatDidNotImportOrCollectMeetingsDoesNotCount(): void
    {
        $this->setSetting('inv_json', json_encode([
            ['postType' => 'one', 'importMeetings' => false, 'meetingGroupingMethod' => Meeting::GROUP_ALL],
            ['postType' => 'two', 'importMeetings' => true, 'meetingGroupingMethod' => Meeting::GROUP_NONE],
        ]));
        $this->makeCollectionPost(-10);

        $this->assertFalse(Meeting_GroupingSettings::forOtherTypes()->legacy);
    }

    public function test_postsThatAreNotPreviousBehaviorCollectionsDoNotCount(): void
    {
        $this->setSetting('enable_meeting_cal', 'on');
        $this->setSetting('mc_grouping_method', Meeting::GROUP_ALL);

        $this->makeCollectionPost(10);                              // A meeting, not a collection.
        $this->makeCollectionPost(-11, 'publish', 'cluster');       // A group made by the current behavior.
        $this->makeCollectionPost(-12, 'trash');                    // Deleted.
        $this->makeCollectionPost(-13, 'auto-draft');               // Never really created.

        $rule = Meeting_GroupingSettings::forOtherTypes();

        $this->assertFalse($rule->legacy);
        $this->assertTrue($rule->clusters, 'Meetings were collected, but there is nothing to preserve.');
    }

    public function test_draftAndPrivateCollectionsStillCount(): void
    {
        $this->setSetting('enable_meeting_cal', 'on');
        $this->setSetting('mc_grouping_method', Meeting::GROUP_ALL);
        $this->makeCollectionPost(-10, 'draft');

        $this->assertTrue(Meeting_GroupingSettings::forOtherTypes()->legacy);
    }

    ////////////////////////////
    // Saving new settings    //
    ////////////////////////////

    public function test_validateNewSettings_returnsTheSettingsToStore(): void
    {
        $stored = Meeting_GroupingSettings::validateNewSettings(json_encode([
            'types'      => [['invTypeId' => '5', 'editions' => true, 'bogus' => 'x']],
            'otherTypes' => ['clusters' => true],
        ]));

        $data = json_decode($stored);

        $this->assertSame(5, $data->types[0]->invTypeId);
        $this->assertTrue($data->types[0]->editions);
        $this->assertObjectNotHasProperty('bogus', $data->types[0]);
        $this->assertTrue($data->otherTypes->clusters);
    }

    public function test_validateNewSettings_cantTurnOnThePreviousBehavior(): void
    {
        $this->saveGroupingSettings(['otherTypes' => ['clusters' => true], 'legacyAvailable' => false]);

        $stored = json_decode(Meeting_GroupingSettings::validateNewSettings(json_encode([
            'otherTypes'      => ['legacy' => true],
            'legacyAvailable' => true,
        ])));

        $this->assertFalse($stored->legacyAvailable);
        $this->assertFalse($stored->otherTypes->legacy);
    }

    public function test_validateNewSettings_keepsThePreviousBehaviorAvailableForASiteThatHasIt(): void
    {
        $this->saveGroupingSettings(['otherTypes' => ['legacy' => true], 'legacyAvailable' => true]);

        $stored = json_decode(Meeting_GroupingSettings::validateNewSettings(json_encode([
            'otherTypes'      => ['legacy' => true],
            'legacyAvailable' => false,   // The form can't turn it off either.
        ])));

        $this->assertTrue($stored->legacyAvailable);
        $this->assertTrue($stored->otherTypes->legacy);
    }

    public function test_validateNewSettings_somethingThatIsNotSettingsLeavesThemAsTheyWere(): void
    {
        $this->saveGroupingSettings(['otherTypes' => ['clusters' => true]]);

        $stored = json_decode(Meeting_GroupingSettings::validateNewSettings('not json'));

        $this->assertTrue($stored->otherTypes->clusters);
    }

    public function test_validateNewSettings_theNewSettingsAreUsedAfterTheyAreSaved(): void
    {
        $this->saveGroupingSettings(['otherTypes' => ['clusters' => true]]);

        $stored = Meeting_GroupingSettings::validateNewSettings(json_encode(['otherTypes' => ['editions' => true]]));
        $this->setSetting('mc_grouping_json', $stored);

        $rule = Meeting_GroupingSettings::forOtherTypes();

        $this->assertTrue($rule->editions);
        $this->assertFalse($rule->clusters);
    }
}
