<?php
/**
 * Tests for the way settings are read, and how their defaults apply
 *
 * @package TouchPointWP\Tests\WordPress
 */

namespace tp\TouchPointWP\Tests\WordPress;

use tp\TouchPointWP\Settings;
use tp\TouchPointWP\TouchPointWP;

/**
 * Test case for reading the plugin's settings, and in particular their defaults.
 *
 * A setting's field is only defined while the feature it belongs to is enabled, and the defaults come from the fields.
 * So whether a setting that hasn't been saved has its default depends on which features are on.
 *
 * @covers \tp\TouchPointWP\Settings
 */
class Settings_Test extends WPTestCase
{
    private function settings(): Settings
    {
        return TouchPointWP::instance()->settings;
    }

    ///////////////////////////////////////////////////
    // The Extra Values to import for people         //
    ///////////////////////////////////////////////////

    public function test_peopleEvCustom_isAnEmptyListWhenNothingWasSavedAndPeopleListsIsOff(): void
    {
        // For example, a site that only lets people sign in with TouchPoint.
        $this->assertSame([], $this->settings()->people_ev_custom);
    }

    public function test_peopleEvCustom_isAnEmptyListWhenNothingWasSavedAndPeopleListsIsOn(): void
    {
        $this->setSetting('enable_people_lists', 'on');
        $this->rebuildSettingsFields();

        $this->assertSame([], $this->settings()->people_ev_custom);
    }

    public function test_peopleEvCustom_isTheSavedListWhateverTheFeaturesAre(): void
    {
        $this->setSetting('people_ev_custom', ['abc123', 'def456']);

        $this->assertSame(['abc123', 'def456'], $this->settings()->people_ev_custom, 'People Lists is off.');

        $this->setSetting('enable_people_lists', 'on');
        $this->rebuildSettingsFields();

        $this->assertSame(['abc123', 'def456'], $this->settings()->people_ev_custom, 'People Lists is on.');
    }

    public function test_peopleEvCustom_canBeUsedToFindThePeopleExtraValueFields(): void
    {
        // What the user import does with it.
        $this->setSetting('meta_personEvFields', json_encode(['_updated' => date('c'), 'personEvFields' => [
            (object)['field' => 'Grade', 'hash' => 'h1', 'type' => 'Text'],
        ]]));

        $this->assertSame([], TouchPointWP::instance()->getPersonEvFields($this->settings()->people_ev_custom));

        $this->setSetting('people_ev_custom', ['h1']);

        $this->assertSame('Grade', TouchPointWP::instance()->getPersonEvFields($this->settings()->people_ev_custom)[0]->field);
    }

    //////////////////////////////
    // Defaults in general      //
    //////////////////////////////

    public function test_aSettingThatDoesNotExistIsFalse(): void
    {
        $this->assertFalse($this->settings()->no_such_setting);
    }

    public function test_aSettingWithAFieldGetsItsDefaultWhenNothingWasSaved(): void
    {
        $this->setSetting('enable_meeting_cal', 'on');
        $this->rebuildSettingsFields();

        $this->assertSame(7, $this->settings()->mc_archive_days);
    }

    public function test_aSavedValueBeatsTheDefault(): void
    {
        $this->setSetting('enable_meeting_cal', 'on');
        $this->setSetting('mc_archive_days', 30);
        $this->rebuildSettingsFields();

        $this->assertSame(30, $this->settings()->mc_archive_days);
    }

    /**
     * Involvements use the number of days to archive meetings after, even when the Meeting Calendar is off, but the
     * setting (and so its default) only exists when the Meeting Calendar is on.  Without a default, nothing is archived
     * after 7 days: it's archived right away, because the number of days is read as false.
     *
     * @group known-issue
     */
    public function test_aSettingThatOtherFeaturesReadHasItsDefaultWhenItsOwnFeatureIsOff(): void
    {
        $this->assertSame(7, $this->settings()->mc_archive_days);
    }
}
