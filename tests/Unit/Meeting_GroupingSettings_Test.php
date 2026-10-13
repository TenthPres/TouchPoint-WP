<?php
/**
 * Tests for the Meeting_GroupingSettings class
 *
 * @package TouchPointWP\Tests\Unit
 */

namespace tp\TouchPointWP\Tests\Unit;

use tp\TouchPointWP\Meeting_GroupingSettings;
use tp\TouchPointWP\Settings;
use tp\TouchPointWP\Tests\TestCase;

/**
 * Test case for the Meeting_GroupingSettings class, which says how the meetings of each TouchPoint Involvement Type are
 * grouped.  The settings are provided with useGroupingSettings(), in the form they're stored in.
 *
 * Reading the settings from WordPress, and working out the settings for a site that hasn't saved any, need WordPress's
 * settings and database, so they aren't tested here.
 *
 * @covers \tp\TouchPointWP\Meeting_GroupingSettings
 */
class Meeting_GroupingSettings_Test extends TestCase
{
    //////////////////////////////////
    // Which settings apply to a type //
    //////////////////////////////////

    public function test_forInvolvementType_aTypeWithItsOwnSettingsUsesThem(): void
    {
        $this->useGroupingSettings([
            'types'      => [['invTypeId' => 5, 'includeChildren' => true, 'editions' => true, 'clusters' => false]],
            'otherTypes' => ['clusters' => true],
        ]);

        $rule = Meeting_GroupingSettings::forInvolvementType(5);

        $this->assertSame(5, $rule->invTypeId);
        $this->assertTrue($rule->includeChildren);
        $this->assertTrue($rule->editions);
        $this->assertFalse($rule->clusters);
    }

    public function test_forInvolvementType_anyOtherTypeUsesTheOtherTypesSettings(): void
    {
        $this->useGroupingSettings([
            'types'      => [['invTypeId' => 5, 'editions' => true]],
            'otherTypes' => ['clusters' => true],
        ]);

        foreach ([9, 0, null] as $typeId) {
            $rule = Meeting_GroupingSettings::forInvolvementType($typeId);

            $this->assertNull($rule->invTypeId);
            $this->assertTrue($rule->clusters, "Type " . var_export($typeId, true));
            $this->assertFalse($rule->editions, "Type " . var_export($typeId, true));
        }
    }

    public function test_forOtherTypes(): void
    {
        $this->useGroupingSettings(['otherTypes' => ['editions' => true, 'includeChildren' => true]]);

        $rule = Meeting_GroupingSettings::forOtherTypes();

        $this->assertTrue($rule->editions);
        $this->assertTrue($rule->includeChildren);
        $this->assertFalse($rule->clusters);
        $this->assertSame($rule, Meeting_GroupingSettings::forInvolvementType(123));
    }

    public function test_settings_everythingIsOffUnlessSet(): void
    {
        $this->useGroupingSettings(['types' => [['invTypeId' => 5]]]);

        foreach ([Meeting_GroupingSettings::forInvolvementType(5), Meeting_GroupingSettings::forOtherTypes()] as $rule) {
            $this->assertFalse($rule->includeChildren);
            $this->assertFalse($rule->editions);
            $this->assertFalse($rule->clusters);
            $this->assertFalse($rule->legacy);
        }
    }

    public function test_settings_typeIdsAreIntegers(): void
    {
        $this->useGroupingSettings(['types' => [['invTypeId' => '12', 'editions' => true]]]);

        $this->assertSame(12, Meeting_GroupingSettings::forInvolvementType(12)->invTypeId);
    }

    public function test_settings_rowsThatCantBeUsedAreIgnored(): void
    {
        $this->useGroupingSettings([
            'types' => [
                ['editions' => true],                                // No type.
                ['invTypeId' => 'abc', 'editions' => true],          // Not a number.
                'not a row',
                ['invTypeId' => 7, 'editions' => true],
            ],
        ]);

        $this->assertSame('7', Meeting_GroupingSettings::involvementQueryParameters()['listedTypes']);
        $this->assertTrue(Meeting_GroupingSettings::forInvolvementType(7)->editions);
    }

    public function test_settings_eachTypeCanOnlyBeListedOnce(): void
    {
        $this->useGroupingSettings([
            'types' => [
                ['invTypeId' => 7, 'editions' => true],
                ['invTypeId' => 7, 'clusters' => true],
            ],
        ]);

        $rule = Meeting_GroupingSettings::forInvolvementType(7);

        $this->assertTrue($rule->editions, 'The first one is used.');
        $this->assertFalse($rule->clusters);
    }

    public function test_get_unknownAndInternalPropertiesAreUndefined(): void
    {
        $this->useGroupingSettings([]);
        $rule = Meeting_GroupingSettings::forOtherTypes();

        $this->assertSame(Settings::UNDEFINED_PLACEHOLDER, $rule->bogus);
        $this->assertSame(Settings::UNDEFINED_PLACEHOLDER, $rule->_loaded);
    }

    ///////////////////////////
    // The previous behavior //
    ///////////////////////////

    public function test_legacy_canBeSelectedForOtherTypesWhenAvailable(): void
    {
        $this->useGroupingSettings([
            'otherTypes'      => ['legacy' => true, 'editions' => true, 'clusters' => true, 'includeChildren' => true],
            'legacyAvailable' => true,
        ]);

        $rule = Meeting_GroupingSettings::forOtherTypes();

        $this->assertTrue($rule->legacy);
        $this->assertTrue(Meeting_GroupingSettings::legacyAvailable());
        $this->assertTrue(Meeting_GroupingSettings::previousBehaviorInUse());
        $this->assertFalse($rule->editions, 'The previous behavior can\'t be combined with the new groupings.');
        $this->assertFalse($rule->clusters);
        $this->assertFalse($rule->includeChildren);
    }

    public function test_legacy_isIgnoredWhenNotAvailable(): void
    {
        $this->useGroupingSettings([
            'otherTypes'      => ['legacy' => true, 'clusters' => true],
            'legacyAvailable' => false,
        ]);

        $rule = Meeting_GroupingSettings::forOtherTypes();

        $this->assertFalse($rule->legacy);
        $this->assertFalse(Meeting_GroupingSettings::legacyAvailable());
        $this->assertFalse(Meeting_GroupingSettings::previousBehaviorInUse());
        $this->assertTrue($rule->clusters, 'Everything else about the rule still applies.');
    }

    public function test_legacy_isNeverUsedByATypeWithItsOwnSettings(): void
    {
        $this->useGroupingSettings([
            'types'           => [['invTypeId' => 5, 'legacy' => true, 'editions' => true]],
            'legacyAvailable' => true,
        ]);

        $rule = Meeting_GroupingSettings::forInvolvementType(5);

        $this->assertFalse($rule->legacy);
        $this->assertTrue($rule->editions);
    }

    public function test_legacyAvailable_staysAvailableAfterOtherOptionsAreChosen(): void
    {
        $this->useGroupingSettings(['otherTypes' => ['clusters' => true], 'legacyAvailable' => true]);

        $this->assertTrue(Meeting_GroupingSettings::legacyAvailable());
        $this->assertFalse(Meeting_GroupingSettings::previousBehaviorInUse());
    }

    ///////////////////////
    // Other settings    //
    ///////////////////////

    public function test_keepHiddenChildren(): void
    {
        $this->useGroupingSettings([]);
        $this->assertFalse(Meeting_GroupingSettings::keepHiddenChildren());

        $this->useGroupingSettings(['keepHiddenChildren' => true]);
        $this->assertTrue(Meeting_GroupingSettings::keepHiddenChildren());
    }

    public function test_involvementQueryParameters_nothingSet(): void
    {
        $this->useGroupingSettings([]);

        $this->assertSame(
            ['childTypes' => '', 'listedTypes' => '', 'childOther' => 0, 'keepHidden' => 0],
            Meeting_GroupingSettings::involvementQueryParameters()
        );
    }

    public function test_involvementQueryParameters_saysWhichTypesIncludeChildren(): void
    {
        $this->useGroupingSettings([
            'types'              => [
                ['invTypeId' => 3, 'clusters' => true],
                ['invTypeId' => 5, 'includeChildren' => true, 'editions' => true],
                ['invTypeId' => 8, 'includeChildren' => true],
            ],
            'otherTypes'         => ['includeChildren' => true],
            'keepHiddenChildren' => true,
        ]);

        $this->assertSame(
            ['childTypes' => '5,8', 'listedTypes' => '3,5,8', 'childOther' => 1, 'keepHidden' => 1],
            Meeting_GroupingSettings::involvementQueryParameters()
        );
    }

    //////////////////////
    // Stored settings  //
    //////////////////////

    public function test_toObject_isWhatIsStored(): void
    {
        $this->useGroupingSettings([
            'types'              => [['invTypeId' => 5, 'includeChildren' => true, 'editions' => true, 'clusters' => false]],
            'otherTypes'         => ['clusters' => true],
            'keepHiddenChildren' => true,
            'legacyAvailable'    => true,
        ]);

        $this->assertEquals(
            (object)[
                'types'              => [(object)['includeChildren' => true, 'editions' => true, 'clusters' => false, 'invTypeId' => 5]],
                'otherTypes'         => (object)['includeChildren' => false, 'editions' => false, 'clusters' => true, 'legacy' => false],
                'keepHiddenChildren' => true,
                'legacyAvailable'    => true,
            ],
            Meeting_GroupingSettings::toObject()
        );
    }

    public function test_toObject_canBeStoredAndLoadedAgain(): void
    {
        $this->useGroupingSettings([
            'types'      => [['invTypeId' => 5, 'includeChildren' => true, 'editions' => true], ['invTypeId' => 9, 'clusters' => true]],
            'otherTypes' => ['legacy' => true],
            'legacyAvailable' => true,
        ]);
        $stored = json_encode(Meeting_GroupingSettings::toObject());

        $this->useGroupingSettings(json_decode($stored, true));

        $this->assertSame($stored, json_encode(Meeting_GroupingSettings::toObject()));
    }

    public function test_defaultsForNewSite_startWithClusters(): void
    {
        $defaults = Meeting_GroupingSettings::defaultsForNewSite();

        $this->assertSame([], $defaults->types);
        $this->assertTrue($defaults->otherTypes->clusters);
        $this->assertFalse($defaults->legacyAvailable);

        $this->useGroupingSettings(json_decode(json_encode($defaults), true));
        $rule = Meeting_GroupingSettings::forOtherTypes();

        $this->assertTrue($rule->clusters);
        $this->assertFalse($rule->editions);
        $this->assertFalse($rule->legacy);
    }

    ////////////
    // Gaps   //
    ////////////

    public function test_gaps_defaults(): void
    {
        $this->assertSame(25 * 86400, Meeting_GroupingSettings::editionGap());
        $this->assertSame(2 * 3600, Meeting_GroupingSettings::clusterGap());
    }

    public function test_gaps_filtersCanChangeThem(): void
    {
        add_filter('tp_meeting_edition_gap', fn($seconds) => 7 * 86400);
        add_filter('tp_meeting_cluster_gap', fn($seconds) => '1800');

        $this->assertSame(7 * 86400, Meeting_GroupingSettings::editionGap());
        $this->assertSame(1800, Meeting_GroupingSettings::clusterGap(), 'Whole seconds, even if the filter returns a string.');
    }
}
