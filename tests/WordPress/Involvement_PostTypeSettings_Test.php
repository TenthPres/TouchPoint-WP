<?php
/**
 * Tests for the way an Involvement Post Type's settings are read, and the Meeting Calendar's effect on them
 *
 * @package TouchPointWP\Tests\WordPress
 */

namespace tp\TouchPointWP\Tests\WordPress;

use tp\TouchPointWP\Involvement;
use tp\TouchPointWP\Involvement_PostTypeSettings;
use tp\TouchPointWP\Meeting;

/**
 * Test case for Involvement Post Types' settings, which are stored as JSON and read into Involvement_PostTypeSettings.
 *
 * Importing all of a type's meetings is part of the Meeting Calendar: its checkbox is only shown while the calendar is
 * enabled.  So while the calendar is off, the setting doesn't apply, whatever was saved, and it applies again, as it was
 * saved, if the calendar is turned back on.
 *
 * @covers \tp\TouchPointWP\Involvement_PostTypeSettings
 */
class Involvement_PostTypeSettings_Test extends WPTestCase
{
    /**
     * Involvement Post Types, as they're stored.  One imports all meetings, and the other doesn't.
     *
     * @return array[]
     */
    private static function savedTypes(): array
    {
        return [
            [
                'postType'              => 'inv_groups',
                'slug'                  => 'groups',
                'nameSingular'          => 'Group',
                'namePlural'            => 'Groups',
                'importMeetings'        => true,
                'meetingGroupingMethod' => Meeting::GROUP_ALL,
            ],
            [
                'postType'       => 'inv_classes',
                'slug'           => 'classes',
                'nameSingular'   => 'Class',
                'namePlural'     => 'Classes',
                'importMeetings' => false,
                'hierarchical'   => true,
            ],
        ];
    }

    /**
     * Save the types, and turn the Meeting Calendar on or off.
     */
    private function saveTypes(bool $calendarEnabled): void
    {
        $this->setSetting('enable_involvements', 'on');
        $this->setSetting('inv_json', json_encode(self::savedTypes()));
        $this->setSetting('enable_meeting_cal', $calendarEnabled ? 'on' : '');
        $this->forgetTypes();
    }

    /**
     * Make the types be read from what's saved the next time they're used.
     */
    private function forgetTypes(): void
    {
        self::setStatic(Involvement_PostTypeSettings::class, 'settings', null);
    }

    /**
     * @param string $postType Without the prefix.
     *
     * @return ?Involvement_PostTypeSettings
     */
    private function typeNamed(string $postType): ?Involvement_PostTypeSettings
    {
        foreach (Involvement_PostTypeSettings::instance() as $type) {
            if ($type->postTypeWithoutPrefix() === $postType) {
                return $type;
            }
        }

        return null;
    }

    /////////////////////////////////
    // While the calendar is on    //
    /////////////////////////////////

    public function test_importingMeetingsIsAsSavedWhileTheCalendarIsOn(): void
    {
        $this->saveTypes(true);

        $this->assertTrue($this->typeNamed('inv_groups')->importMeetings);
        $this->assertFalse($this->typeNamed('inv_classes')->importMeetings);
    }

    public function test_theCalendarsOwnPostTypeImportsItsMeetingsWhileTheCalendarIsOn(): void
    {
        $this->saveTypes(true);

        $this->assertTrue($this->typeNamed(Meeting::POST_TYPE_WO_PRE)->importMeetings);
    }

    ///////////////////////////////////
    // While the calendar is off     //
    ///////////////////////////////////

    public function test_noTypeImportsMeetingsWhileTheCalendarIsOff(): void
    {
        $this->saveTypes(false);

        $this->assertFalse($this->typeNamed('inv_groups')->importMeetings, 'It was saved as true.');
        $this->assertFalse($this->typeNamed('inv_classes')->importMeetings);
    }

    public function test_theSameIsTrueWhenATypeIsLookedUpByItsPostType(): void
    {
        $this->saveTypes(false);

        $this->assertFalse(Involvement_PostTypeSettings::getForInvType('tp_inv_groups')->importMeetings);

        $this->saveTypes(true);

        $this->assertTrue(Involvement_PostTypeSettings::getForInvType('tp_inv_groups')->importMeetings);
    }

    public function test_theCalendarsOwnPostTypeIsNotThereWhileTheCalendarIsOff(): void
    {
        $this->saveTypes(false);

        $this->assertNull($this->typeNamed(Meeting::POST_TYPE_WO_PRE));
        $this->assertNotContains(Meeting::POST_TYPE, Involvement_PostTypeSettings::getPostTypes());
    }

    public function test_everythingElseAboutATypeIsTheSameWhileTheCalendarIsOff(): void
    {
        $this->saveTypes(false);

        $groups  = $this->typeNamed('inv_groups');
        $classes = $this->typeNamed('inv_classes');

        $this->assertSame('groups', $groups->slug);
        $this->assertSame('Groups', $groups->namePlural);
        $this->assertSame('all', $groups->meetingGroupingMethod);
        $this->assertFalse($groups->hierarchical);
        $this->assertTrue($classes->hierarchical);
        $this->assertSame(['tp_inv_groups', 'tp_inv_classes'], Involvement_PostTypeSettings::getPostTypes());
    }

    ///////////////////////////////////////////
    // What was saved isn't changed          //
    ///////////////////////////////////////////

    public function test_theSavedChoiceIsNotChangedByReadingItWhileTheCalendarIsOff(): void
    {
        $this->saveTypes(false);
        $this->typeNamed('inv_groups');

        $stored = json_decode(get_option('tp_inv_json'));

        $this->assertTrue($stored[0]->importMeetings);
    }

    public function test_theSavedChoiceAppliesAgainWhenTheCalendarIsTurnedBackOn(): void
    {
        $this->saveTypes(false);
        $this->assertFalse($this->typeNamed('inv_groups')->importMeetings);

        $this->setSetting('enable_meeting_cal', 'on');
        $this->forgetTypes();

        $this->assertTrue($this->typeNamed('inv_groups')->importMeetings);
    }

    public function test_savingTheSettingsPageWhileTheCalendarIsOffKeepsTheChoice(): void
    {
        // The checkbox isn't on the page, but the form sends the value it was given, which is the saved one.
        $this->saveTypes(false);

        $saved = json_decode(Involvement_PostTypeSettings::validateNewSettings(json_encode(self::savedTypes())));

        $this->assertTrue($saved[0]->importMeetings);
        $this->assertFalse($saved[1]->importMeetings);
    }

    ///////////////////////////////////
    // The posts' registration       //
    ///////////////////////////////////

    public function test_aTypeThatOnlyImportedMeetingsAsSavedIsNotHierarchicalWhileTheCalendarIsOff(): void
    {
        $this->saveTypes(false);

        Involvement::init();

        $this->assertFalse(get_post_type_object('tp_inv_groups')->hierarchical, 'It is not hierarchical, and meetings are not imported.');
        $this->assertTrue(get_post_type_object('tp_inv_classes')->hierarchical, 'It is hierarchical by its own setting.');
    }

    public function test_typesAreHierarchicalWhileTheCalendarIsOn(): void
    {
        $this->saveTypes(true);

        Involvement::init();

        $this->assertTrue(get_post_type_object('tp_inv_groups')->hierarchical);
        $this->assertTrue(get_post_type_object('tp_inv_classes')->hierarchical);
    }
}
