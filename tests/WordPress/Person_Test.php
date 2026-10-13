<?php
/**
 * Tests for the way people from TouchPoint become WordPress users
 *
 * @package TouchPointWP\Tests\WordPress
 */

namespace tp\TouchPointWP\Tests\WordPress;

use stdClass;
use tp\TouchPointWP\Person;
use tp\TouchPointWP\Taxonomies;
use tp\TouchPointWP\TouchPointWP;
use WP_Error;

/**
 * Test case for creating and updating WordPress users from the data TouchPoint provides about people: finding the right
 * user, making a user when there isn't one, keeping duplicates from piling up, and choosing usernames.  The tests in
 * tests/Unit cover the parts of Person that only work on values.
 *
 * TouchPoint isn't contacted: updates that would be sent back to it are held back, and requests to other servers are
 * refused.
 *
 * @covers \tp\TouchPointWP\Person
 */
class Person_Test extends WPTestCase
{
    public function set_up(): void
    {
        parent::set_up();

        add_filter('pre_http_request', fn() => new WP_Error('http_request_failed', 'Not allowed in tests.'));

        $this->setSetting('meta_personEvFields', json_encode(['_updated' => date('c'), 'personEvFields' => []]));
        self::setStatic(Person::class, '_peopleWhoNeedWpIdUpdatedInTouchPoint', []);

        Taxonomies::registerTaxonomies(TouchPointWP::instance());
    }

    ///////////////
    // Helpers   //
    ///////////////

    /**
     * A person, as the API provides them.
     *
     * @param int   $peopleId
     * @param array $overrides Anything to change from the defaults.
     *
     * @return stdClass
     */
    private static function personData(int $peopleId, array $overrides = []): stdClass
    {
        return (object)($overrides + [
            'PeopleId'    => $peopleId,
            'FamilyId'    => 5000 + $peopleId,
            'GoesBy'      => 'Jane',
            'LastName'    => 'Testperson',
            'DisplayName' => 'Jane Testperson',
            'GenderId'    => 2,
            'Emails'      => ["jane$peopleId@example.org"],
            'Picture'     => null,
            'ResCode'     => null,
            'CampusId'    => null,
            'Usernames'   => ["jtest$peopleId"],
        ]);
    }

    /**
     * Import a person, the way the plugin does.
     *
     * @param stdClass $data
     * @param bool     $allowCreation
     *
     * @return ?Person
     */
    private function import(stdClass $data, bool $allowCreation = true): ?Person
    {
        return Person::updatePersonFromApiData($data, $allowCreation, false, true);
    }

    /**
     * The IDs of all the users who have a PeopleId.
     *
     * @return int[]
     */
    private function usersWithPeopleId(int $peopleId): array
    {
        return array_map('intval', get_users([
            'meta_key'   => Person::META_PEOPLEID,
            'meta_value' => $peopleId,
            'fields'     => 'ID',
        ]));
    }

    ///////////////////////
    // Creating users    //
    ///////////////////////

    public function test_aNewPersonBecomesAUser(): void
    {
        $person = $this->import(self::personData(1001));

        $user = get_userdata($person->ID);

        $this->assertInstanceOf(Person::class, $person);
        $this->assertSame('jtest1001', $user->user_login);
        $this->assertSame('Jane', $user->first_name);
        $this->assertSame('Testperson', $user->last_name);
        $this->assertSame('Jane Testperson', $user->display_name);
        $this->assertSame('jane1001@example.org', $user->user_email);
        $this->assertSame('1001', (string)get_user_option(Person::META_PEOPLEID, $person->ID));
        $this->assertSame('6001', (string)get_user_option(Person::META_FAMILYID, $person->ID));
        $this->assertSame('2', (string)get_user_option(Person::META_GENDERID, $person->ID));
        $this->assertSame('TouchPoint-WP', get_user_option('created_by', $person->ID));
    }

    public function test_aPersonWhoIsNotAUserIsNotMadeOneWhenCreationIsNotAllowed(): void
    {
        $before = count(get_users());

        $person = $this->import(self::personData(1002), false);

        $this->assertNull($person);
        $this->assertCount($before, get_users());
    }

    public function test_aNewUserIsQueuedToHaveItsWordPressIdSentToTouchPoint(): void
    {
        $person = $this->import(self::personData(1003));

        $queue = self::getStatic(Person::class, '_peopleWhoNeedWpIdUpdatedInTouchPoint');

        $this->assertSame([['PeopleId' => 1003, 'WpId' => $person->ID]], $queue);
    }

    public function test_aPersonWithNoEmailAddressIsAUserWithoutOne(): void
    {
        $person = $this->import(self::personData(1004, ['Emails' => []]));

        $this->assertSame('', get_userdata($person->ID)->user_email);
    }

    public function test_aPersonWhoseEmailAddressBelongsToAnotherUserIsStillImported(): void
    {
        self::factory()->user->create(['user_email' => 'shared@example.org']);

        $person = $this->import(self::personData(1005, ['Emails' => ['shared@example.org'], 'GoesBy' => 'Janet']));

        $user = get_userdata($person->ID);
        $this->assertSame('Janet', $user->first_name, 'The rest of the update still happened.');
        $this->assertNotSame('shared@example.org', $user->user_email);
    }

    /////////////////////////
    // Finding and updating //
    /////////////////////////

    public function test_aPersonWhoIsAlreadyAUserIsUpdatedNotDuplicated(): void
    {
        $first = $this->import(self::personData(1010));

        $second = $this->import(self::personData(1010, ['GoesBy' => 'Janet', 'DisplayName' => 'Janet Testperson']));

        $this->assertSame($first->ID, $second->ID);
        $this->assertSame([$first->ID], $this->usersWithPeopleId(1010));
        $this->assertSame('Janet', get_userdata($first->ID)->first_name);
        $this->assertSame('Janet Testperson', get_userdata($first->ID)->display_name);
    }

    public function test_aUsersUsernameIsNotChangedByAnUpdate(): void
    {
        $person = $this->import(self::personData(1011));

        $this->import(self::personData(1011, ['Usernames' => ['somethingelse']]));

        $this->assertSame('jtest1011', get_userdata($person->ID)->user_login);
    }

    public function test_aPersonWhoIsAlreadyAUserIsNotQueuedAgainWhenTheirWordPressIdIsKnown(): void
    {
        $this->import(self::personData(1012));
        self::setStatic(Person::class, '_peopleWhoNeedWpIdUpdatedInTouchPoint', []);

        $this->import(self::personData(1012));

        // The WordPress ID is only known to TouchPoint if its extra value says so, which it doesn't here.
        $this->assertCount(1, self::getStatic(Person::class, '_peopleWhoNeedWpIdUpdatedInTouchPoint'));
    }

    public function test_aPersonCanBeFoundByTheirPeopleId(): void
    {
        $created = $this->import(self::personData(1013));

        $found = Person::fromPeopleId(1013);

        $this->assertInstanceOf(Person::class, $found);
        $this->assertSame($created->ID, $found->ID);
        $this->assertNull(Person::fromPeopleId(999999));
    }

    public function test_aPersonCanBeFoundByTheirWordPressId(): void
    {
        $created = $this->import(self::personData(1014));

        $found = Person::fromId($created->ID);

        $this->assertSame(1014, $found->peopleId);
        $this->assertSame(6014, $found->familyId);
        $this->assertNull(Person::fromId(0));
    }

    ///////////////////////////
    // Duplicates (#119)     //
    ///////////////////////////

    public function test_duplicateUsersForAPersonAreRemovedAndTheFirstIsKept(): void
    {
        $original  = self::factory()->user->create();
        $duplicate = self::factory()->user->create();
        update_user_option($original, Person::META_PEOPLEID, 1020, true);
        update_user_option($duplicate, Person::META_PEOPLEID, 1020, true);

        $person = $this->import(self::personData(1020), false);

        $this->assertSame($original, $person->ID);
        $this->assertSame([$original], $this->usersWithPeopleId(1020));
        $this->assertFalse(get_userdata($duplicate));
    }

    public function test_aDuplicateWhoIsAnAdministratorIsNotRemoved(): void
    {
        $original = self::factory()->user->create();
        $admin    = self::factory()->user->create(['role' => 'administrator']);
        update_user_option($original, Person::META_PEOPLEID, 1021, true);
        update_user_option($admin, Person::META_PEOPLEID, 1021, true);

        $this->import(self::personData(1021), false);

        $this->assertNotFalse(get_userdata($admin));
    }

    public function test_aUserThatIsNotFromTouchPointIsNeverRemovedAsADuplicate(): void
    {
        $original = self::factory()->user->create();
        $other    = self::factory()->user->create();   // No PeopleId.
        update_user_option($original, Person::META_PEOPLEID, 1022, true);

        $this->import(self::personData(1022), false);

        $this->assertNotFalse(get_userdata($other));
    }

    //////////////////////////////
    // Involvement memberships  //
    //////////////////////////////

    private static function membership(int $iid, string $memType, string $attType, ?string $descr = null): stdClass
    {
        return (object)['iid' => $iid, 'memType' => $memType, 'attType' => $attType, 'descr' => $descr];
    }

    public function test_involvementMembershipsAreStored(): void
    {
        $data      = self::personData(1030);
        $data->Inv = [self::membership(5, 'Member', 'Attender', 'Likes cake'), self::membership(6, 'Leader', 'Leader')];

        $person      = $this->import($data);
        $memberships = $person->getInvolvementMemberships();

        $this->assertEqualsCanonicalizing([5, 6], array_keys($memberships));
        $this->assertSame('Member', $memberships[5]->mt);
        $this->assertSame('Attender', $memberships[5]->at);
        $this->assertSame('Likes cake', $memberships[5]->description);
        $this->assertSame('Leader', $memberships[6]->mt);
    }

    public function test_involvementMembershipsAreUpdatedAndRemoved(): void
    {
        $data      = self::personData(1031);
        $data->Inv = [self::membership(5, 'Member', 'Attender'), self::membership(6, 'Leader', 'Leader')];
        $this->import($data);

        $changed      = self::personData(1031);
        $changed->Inv = [self::membership(5, 'Prospect', 'Attender')];   // Changed, and 6 is gone.
        $person       = $this->import($changed);

        $memberships = $person->getInvolvementMemberships();

        $this->assertSame([5], array_keys($memberships));
        $this->assertSame('Prospect', $memberships[5]->mt);
    }

    public function test_aPersonWhoseInvolvementsAreNotProvidedKeepsThem(): void
    {
        $data      = self::personData(1032);
        $data->Inv = [self::membership(5, 'Member', 'Attender')];
        $this->import($data);

        $person = $this->import(self::personData(1032));   // No "Inv" at all.

        $this->assertSame([5], array_keys($person->getInvolvementMemberships()));
    }

    /////////////////////////////
    // Residency and campuses  //
    /////////////////////////////

    public function test_aPersonsResidentCodeIsFoundFromItsTouchPointId(): void
    {
        Taxonomies::insertTermsForLookupBasedTaxonomy([(object)['id' => 7, 'name' => 'North Side']], Taxonomies::TAX_RESCODE, false);

        $person = $this->import(self::personData(1040, ['ResCode' => 7]));

        $this->assertSame('North Side', $person->rescode->name);
    }

    public function test_aPersonWithoutAResidentCodeHasNone(): void
    {
        $person = $this->import(self::personData(1041, ['ResCode' => null]));

        $this->assertNull($person->rescode);
    }

    public function test_aPersonWithoutAResidentCodeIsNotGivenTheFirstOne(): void
    {
        Taxonomies::insertTermsForLookupBasedTaxonomy(
            [(object)['id' => 7, 'name' => 'North Side'], (object)['id' => 8, 'name' => 'South Side']],
            Taxonomies::TAX_RESCODE,
            false
        );

        $person = $this->import(self::personData(1042, ['ResCode' => null]));

        $this->assertEmpty($person->rescode_term_id);
        $this->assertNull($person->rescode);
    }

    public function test_aPersonWithoutACampusIsNotGivenTheFirstOne(): void
    {
        $this->setSetting('enable_campuses', 'on');
        Taxonomies::registerTaxonomies(TouchPointWP::instance());
        Taxonomies::insertTermsForLookupBasedTaxonomy(
            [(object)['id' => 1, 'name' => 'Main Campus'], (object)['id' => 2, 'name' => 'East Campus']],
            Taxonomies::TAX_CAMPUS,
            false
        );

        $person = $this->import(self::personData(1043, ['CampusId' => null]));

        $this->assertEmpty($person->campus_term_id);
        $this->assertNull($person->campus);
    }

    public function test_aPersonsCampusIsFoundFromItsTouchPointId(): void
    {
        $this->setSetting('enable_campuses', 'on');
        Taxonomies::registerTaxonomies(TouchPointWP::instance());
        Taxonomies::insertTermsForLookupBasedTaxonomy(
            [(object)['id' => 1, 'name' => 'Main Campus'], (object)['id' => 2, 'name' => 'East Campus']],
            Taxonomies::TAX_CAMPUS,
            false
        );

        $person = $this->import(self::personData(1044, ['CampusId' => 2]));

        $this->assertSame('East Campus', $person->campus->name);
    }

    public function test_campusesAreIgnoredWhenTheyAreNotEnabled(): void
    {
        $person = $this->import(self::personData(1045, ['CampusId' => 2]));

        $this->assertNull($person->campus);
    }

    /**
     * Resident codes, as TouchPoint provides them, are in WordPress.
     */
    private function makeResidentCodes(): void
    {
        Taxonomies::insertTermsForLookupBasedTaxonomy(
            [(object)['id' => 7, 'name' => 'North Side'], (object)['id' => 8, 'name' => 'South Side']],
            Taxonomies::TAX_RESCODE,
            false
        );
    }

    /**
     * Campuses, as TouchPoint provides them, are in WordPress, and the Campus feature is on.
     */
    private function makeCampuses(): void
    {
        $this->setSetting('enable_campuses', 'on');
        Taxonomies::registerTaxonomies(TouchPointWP::instance());
        Taxonomies::insertTermsForLookupBasedTaxonomy(
            [(object)['id' => 1, 'name' => 'Main Campus'], (object)['id' => 2, 'name' => 'East Campus']],
            Taxonomies::TAX_CAMPUS,
            false
        );
    }

    /**
     * @return array[] [a value TouchPoint might provide in place of an ID]
     */
    public static function provider_blankIds(): array
    {
        return [
            'null'         => [null],
            'nothing'      => [''],
            'only spaces'  => ['   '],
        ];
    }

    /**
     * @dataProvider provider_blankIds
     */
    public function test_aBlankResidentCodeIsNoResidentCodeEvenWhenThereAreSome($blank): void
    {
        $this->makeResidentCodes();

        $person = $this->import(self::personData(1090, ['ResCode' => $blank]));

        $this->assertNull($person->rescode);
    }

    /**
     * @dataProvider provider_blankIds
     */
    public function test_aBlankCampusIsNoCampusEvenWhenThereAreSome($blank): void
    {
        $this->makeCampuses();

        $person = $this->import(self::personData(1091, ['CampusId' => $blank]));

        $this->assertNull($person->campus);
    }

    public function test_aResidentCodeThatTouchPointNoLongerHasIsRemoved(): void
    {
        $this->makeResidentCodes();
        $first = $this->import(self::personData(1092, ['ResCode' => 7]));
        $this->assertSame('North Side', $first->rescode->name);

        $second = $this->import(self::personData(1092, ['ResCode' => null]));

        $this->assertSame($first->ID, $second->ID);
        $this->assertNull($second->rescode);
        $this->assertEmpty($second->rescode_term_id);
    }

    public function test_aResidentCodeCanChange(): void
    {
        $this->makeResidentCodes();
        $this->import(self::personData(1093, ['ResCode' => 7]));

        $person = $this->import(self::personData(1093, ['ResCode' => 8]));

        $this->assertSame('South Side', $person->rescode->name);
    }

    public function test_aCampusThatTouchPointNoLongerHasIsRemoved(): void
    {
        $this->makeCampuses();
        $first = $this->import(self::personData(1094, ['CampusId' => 2]));
        $this->assertSame('East Campus', $first->campus->name);

        $second = $this->import(self::personData(1094, ['CampusId' => null]));

        $this->assertNull($second->campus);
        $this->assertEmpty($second->campus_term_id);
    }

    public function test_aResidentCodeThatIsNotInWordPressIsNotUsed(): void
    {
        $this->makeResidentCodes();

        $person = $this->import(self::personData(1095, ['ResCode' => 99]));

        $this->assertNull($person->rescode);
        $this->assertEmpty($person->rescode_term_id);
    }

    public function test_aResidentCodeThatWasDeletedAfterItWasAssignedIsNone(): void
    {
        $this->makeResidentCodes();
        $person = $this->import(self::personData(1096, ['ResCode' => 7]));
        wp_delete_term(Taxonomies::getTaxTermId(Taxonomies::TAX_RESCODE, 7), Taxonomies::TAX_RESCODE);

        // A request later, when nothing is remembered about the person.
        self::setStatic(Person::class, '_instances', []);
        $later = Person::fromId($person->ID);

        $this->assertNull($later->rescode);
    }

    public function test_aCampusThatWasDeletedAfterItWasAssignedIsNone(): void
    {
        $this->makeCampuses();
        $person = $this->import(self::personData(1097, ['CampusId' => 1]));
        wp_delete_term(Taxonomies::getTaxTermId(Taxonomies::TAX_CAMPUS, 1), Taxonomies::TAX_CAMPUS);

        self::setStatic(Person::class, '_instances', []);
        $later = Person::fromId($person->ID);

        $this->assertNull($later->campus);
    }

    ///////////////////
    // Usernames     //
    ///////////////////

    private static function username(stdClass $pData): string
    {
        return self::callStatic(Person::class, 'generateUserName', $pData);
    }

    public function test_aUsernameFromTouchPointIsUsedIfItIsAvailable(): void
    {
        $this->assertSame('jtest', self::username(self::personData(1050, ['Usernames' => ['jtest', 'other']])));
    }

    public function test_aUsernameThatBelongsToAnotherUserIsSkipped(): void
    {
        self::factory()->user->create(['user_login' => 'jtest']);

        $this->assertSame('other', self::username(self::personData(1051, ['Usernames' => ['jtest', 'other']])));
    }

    public function test_aUsernameWithAdminInItIsNeverUsed(): void
    {
        $this->assertSame('janetestperson', self::username(self::personData(1052, ['Usernames' => ['admin', 'SuperAdmin']])));
    }

    public function test_theNameIsUsedWhenThereAreNoUsernames(): void
    {
        $this->assertSame('janetestperson', self::username(self::personData(1053, ['Usernames' => []])));
    }

    public function test_theIdIsAddedToANameThatBelongsToAnotherUser(): void
    {
        self::factory()->user->create(['user_login' => 'janetestperson']);

        $this->assertSame('janetestperson1054', self::username(self::personData(1054, ['Usernames' => []])));
    }

    public function test_aUsernameIsAlwaysFoundEvenWhenEverythingIsTaken(): void
    {
        foreach (['jtest', 'janetestperson', 'janetestperson1055'] as $login) {
            self::factory()->user->create(['user_login' => $login]);
        }

        $this->assertSame(Person::BACKUP_USER_PREFIX . '1055', self::username(self::personData(1055, ['Usernames' => ['jtest']])));
    }

    public function test_twoPeopleWithTheSameNameBothGetUsers(): void
    {
        $one = $this->import(self::personData(1056, ['Usernames' => []]));
        $two = $this->import(self::personData(1057, ['Usernames' => [], 'DisplayName' => 'Jane Testperson']));

        $this->assertSame('janetestperson', get_userdata($one->ID)->user_login);
        $this->assertSame('janetestperson1057', get_userdata($two->ID)->user_login);
    }

    ///////////////////
    // Links         //
    ///////////////////

    public function test_aPersonWithoutPostsHasNoUserPage(): void
    {
        $person = $this->import(self::personData(1060));

        $this->assertFalse($person->hasUserPage());
        $this->assertNull($person->getUserUrl());
    }

    public function test_aPersonWithPostsHasAUserPage(): void
    {
        $person = $this->import(self::personData(1061));
        self::factory()->post->create(['post_author' => $person->ID]);

        $this->assertTrue($person->hasUserPage());
        $this->assertSame(get_author_posts_url($person->ID), $person->getUserUrl());
    }

    public function test_aFamilysNamesLinkToTheUserPagesThatExist(): void
    {
        $withPage = $this->import(self::personData(1062, ['GoesBy' => 'John', 'GenderId' => 1, 'LastName' => 'Smith', 'DisplayName' => 'John Smith', 'FamilyId' => 77]));
        $without  = $this->import(self::personData(1063, ['GoesBy' => 'Jane', 'GenderId' => 2, 'LastName' => 'Smith', 'DisplayName' => 'Jane Smith', 'FamilyId' => 77]));
        self::factory()->post->create(['post_author' => $withPage->ID]);

        $html = Person::arrangeNamesForPeople([$withPage, $without], true);

        $this->assertSame('<a href="' . get_author_posts_url($withPage->ID) . '">John</a> & Jane Smith', $html);
    }

    ///////////////////////////////////////////////////
    // The Extra Values to import, when none are set //
    ///////////////////////////////////////////////////

    public function test_aPersonCanBeImportedWhenTheExtraValuesToImportWereNeverSaved(): void
    {
        // A site that uses TouchPoint to sign in, but doesn't have People Lists.
        $this->assertFalse(get_option(TouchPointWP::SETTINGS_PREFIX . 'people_ev_custom', false));

        $person = $this->import(self::personData(1070));

        $this->assertInstanceOf(Person::class, $person);
        $this->assertSame('jtest1070', get_userdata($person->ID)->user_login);
    }

    ////////////////////////////
    // Finding a person by ID //
    ////////////////////////////

    public function test_fromId_aUserThatExistsIsAPerson(): void
    {
        $id = self::factory()->user->create();

        $this->assertInstanceOf(Person::class, Person::fromId($id));
        $this->assertSame($id, Person::fromId($id)->ID);
        $this->assertSame($id, Person::fromId((string)$id)->ID, 'An ID as text.');
        $this->assertSame($id, Person::fromId((object)['ID' => $id])->ID, 'An object with an ID.');
        $this->assertSame($id, Person::fromId(['ID' => $id])->ID, 'An array with an ID.');
    }

    public function test_fromId_aUserThatDoesNotExistIsNull(): void
    {
        $this->assertNull(Person::fromId(99999999));
        $this->assertNull(Person::fromId('99999999'));
        $this->assertNull(Person::fromId((object)['ID' => 99999999]));
        $this->assertNull(Person::fromId(['ID' => 99999999]));
    }

    public function test_fromId_noIdIsNull(): void
    {
        $this->assertNull(Person::fromId(0));
        $this->assertNull(Person::fromId(null));
        $this->assertNull(Person::fromId(''));
        $this->assertNull(Person::fromId((object)['ID' => 0]));
    }

    public function test_fromId_theSamePersonIsReturnedEachTime(): void
    {
        $id = self::factory()->user->create();

        $this->assertSame(Person::fromId($id), Person::fromId($id));
    }

    public function test_fromId_aUserThatDoesNotExistIsNotRememberedAsAPerson(): void
    {
        Person::fromId(99999999);
        Person::fromId(99999999);

        $remembered = self::getStatic(Person::class, '_instances');

        $this->assertArrayNotHasKey(0, $remembered);
        $this->assertArrayNotHasKey(99999999, $remembered);
    }

    public function test_fromId_aUserThatIsMadeAfterALookupIsFound(): void
    {
        global $wpdb;

        $this->assertNull(Person::fromId(88888888));

        $wpdb->insert($wpdb->users, [
            'ID'              => 88888888,
            'user_login'      => 'arrives-later',
            'user_pass'       => 'not-a-real-hash',
            'user_nicename'   => 'arrives-later',
            'user_email'      => 'later@example.org',
            'user_registered' => '2026-01-01 00:00:00',
            'display_name'    => 'Arrives Later',
        ]);
        clean_user_cache(88888888);

        $this->assertSame(88888888, Person::fromId(88888888)->ID);
    }

    public function test_fromId_aUserThatWasDeletedIsNull(): void
    {
        require_once ABSPATH . 'wp-admin/includes/user.php';

        $id = self::factory()->user->create();
        wp_delete_user($id);

        $this->assertNull(Person::fromId($id));
    }

    public function test_fromId_aLookupByUsernameStillWorks(): void
    {
        $id = self::factory()->user->create(['user_login' => 'someone-special']);

        $this->assertSame($id, Person::fromId('someone-special')->ID);
    }

    public function test_currentUserPerson_isNullWhenNobodyIsSignedIn(): void
    {
        wp_set_current_user(0);

        $this->assertNull(TouchPointWP::currentUserPerson());
    }

    public function test_currentUserPerson_isTheUserWhoIsSignedIn(): void
    {
        $id = self::factory()->user->create();
        wp_set_current_user($id);

        $this->assertSame($id, TouchPointWP::currentUserPerson()->ID);

        wp_set_current_user(0);
    }

    /////////////////////////////
    // Pictures, as avatars    //
    /////////////////////////////

    private const PICTURE = [
        'large'  => 'https://example.org/large.jpg',
        'medium' => 'https://example.org/medium.jpg',
        'small'  => 'https://example.org/small.jpg',
        'thumb'  => 'https://example.org/thumb.jpg',
    ];

    private function importWithPicture(int $peopleId): Person
    {
        return $this->import(self::personData($peopleId, ['Picture' => (object)self::PICTURE]));
    }

    public function test_aPersonsPictureIsUsedAtTheSizeThatFits(): void
    {
        $id = $this->importWithPicture(1080)->ID;

        $this->assertSame(self::PICTURE['large'], Person::getPictureForPerson($id));
        $this->assertSame(self::PICTURE['large'], Person::getPictureForPerson($id, ['size' => 500]));
        $this->assertSame(self::PICTURE['medium'], Person::getPictureForPerson($id, ['width' => 300, 'height' => 300]));
        $this->assertSame(self::PICTURE['small'], Person::getPictureForPerson($id, ['size' => 100]));
        $this->assertSame(self::PICTURE['thumb'], Person::getPictureForPerson($id, ['size' => 40]));
    }

    public function test_aPictureCanBeFoundFromAnIdAnObjectOrAPerson(): void
    {
        $person = $this->importWithPicture(1081);

        $this->assertSame(self::PICTURE['large'], Person::getPictureForPerson($person));
        $this->assertSame(self::PICTURE['large'], Person::getPictureForPerson((string)$person->ID));
        $this->assertSame(self::PICTURE['large'], Person::getPictureForPerson((object)['ID' => $person->ID]));
        $this->assertSame(self::PICTURE['large'], Person::getPictureForPerson((object)['user_id' => $person->ID]));
        $this->assertSame(self::PICTURE['large'], Person::getPictureForPerson(get_userdata($person->ID)->user_email));
    }

    public function test_aPictureCanBeUsedAsAnAvatar(): void
    {
        $person = $this->importWithPicture(1082);
        add_filter('get_avatar_url', [Person::class, 'pictureFilter'], 10, 3);

        $this->assertSame(self::PICTURE['large'], get_avatar_url($person->ID, ['size' => 500]));
    }

    public function test_aPersonWithoutAPictureHasNone(): void
    {
        $person = $this->import(self::personData(1083, ['Picture' => null]));

        $this->assertNull(Person::getPictureForPerson($person->ID));
    }

    public function test_aUserThatDoesNotExistHasNoPicture(): void
    {
        $this->assertNull(Person::getPictureForPerson(99999999));
        $this->assertNull(Person::getPictureForPerson('99999999'));
        $this->assertNull(Person::getPictureForPerson((object)['ID' => 99999999]));
        $this->assertNull(Person::getPictureForPerson((object)['user_id' => 99999999]));
        $this->assertNull(Person::getPictureForPerson('nobody@example.org'));
    }

    ///////////////////////////////////
    // The avatar filter, for WordPress //
    ///////////////////////////////////

    private const WORDPRESS_AVATAR = 'https://example.org/wordpress-avatar.jpg';

    public function test_pictureFilter_usesTheTouchPointPictureWhenThereIsOne(): void
    {
        $person = $this->importWithPicture(1085);

        $this->assertSame(self::PICTURE['large'], Person::pictureFilter(self::WORDPRESS_AVATAR, $person->ID, []));
        $this->assertSame(self::PICTURE['thumb'], Person::pictureFilter(self::WORDPRESS_AVATAR, $person->ID, ['size' => 40]));
    }

    public function test_pictureFilter_leavesTheAvatarAloneWhenThereIsNoTouchPointPicture(): void
    {
        // A filter for avatar addresses gets the address WordPress has chosen, such as a Gravatar, and returns what to
        // use.  Without a TouchPoint picture, that's what WordPress chose.
        $person = $this->import(self::personData(1084, ['Picture' => null]));

        $this->assertSame(self::WORDPRESS_AVATAR, Person::pictureFilter(self::WORDPRESS_AVATAR, $person->ID, []));
    }

    public function test_pictureFilter_leavesTheAvatarAloneForSomeoneWhoIsNotAUser(): void
    {
        $this->assertSame(self::WORDPRESS_AVATAR, Person::pictureFilter(self::WORDPRESS_AVATAR, 'stranger@example.org', []));
        $this->assertSame(self::WORDPRESS_AVATAR, Person::pictureFilter(self::WORDPRESS_AVATAR, 99999999, []));
        $this->assertSame(self::WORDPRESS_AVATAR, Person::pictureFilter(self::WORDPRESS_AVATAR, md5('stranger@example.org'), []));
    }

    public function test_pictureFilter_whateverWordPressChoseIsLeftAloneWhenThereIsNoPicture(): void
    {
        // Including nothing, which is what WordPress uses when avatars are turned off.
        $this->assertFalse(Person::pictureFilter(false, 'stranger@example.org', []));
        $this->assertNull(Person::pictureFilter(null, 'stranger@example.org', []));
    }

    ///////////////////////////////////////////////////////////
    // Other plugins' avatar filters, and the order they run in //
    ///////////////////////////////////////////////////////////

    private const AVATAR_FROM_ANOTHER_PLUGIN = 'https://avatars.example.org/the-other-plugins-avatar.jpg';

    public function test_theAvatarFilterIsRegisteredAtTheDefaultPriority(): void
    {
        // The plugin registers it when Person is loaded.
        self::setStatic(Person::class, '_isLoaded', false);
        Person::load();
        self::setStatic(Person::class, '_isLoaded', false);

        $this->assertSame(10, has_filter('get_avatar_url', [Person::class, 'pictureFilter']));
    }

    public function test_theShowAvatarsFilterIsRegisteredAtTheLowestPriority(): void
    {
        self::setStatic(Person::class, '_isLoaded', false);
        Person::load();
        self::setStatic(Person::class, '_isLoaded', false);

        $this->assertSame(PHP_INT_MIN, has_filter('get_avatar_url', [Person::class, 'showAvatarsFilter']));
    }

    public function test_aPluginThatRunsBeforeTheAvatarFilterKeepsItsAvatarForPeopleWithoutATouchPointPicture(): void
    {
        add_filter('get_avatar_url', fn() => self::AVATAR_FROM_ANOTHER_PLUGIN, 5);
        add_filter('get_avatar_url', [Person::class, 'pictureFilter'], 10, 3);
        $person = $this->import(self::personData(1092, ['Picture' => null]));

        $this->assertSame(self::AVATAR_FROM_ANOTHER_PLUGIN, get_avatar_url($person->ID));
    }

    public function test_aPluginThatRunsBeforeTheAvatarFilterIsReplacedForPeopleWithATouchPointPicture(): void
    {
        add_filter('get_avatar_url', fn() => self::AVATAR_FROM_ANOTHER_PLUGIN, 5);
        add_filter('get_avatar_url', [Person::class, 'pictureFilter'], 10, 3);
        $person = $this->importWithPicture(1093);

        $this->assertSame(self::PICTURE['large'], get_avatar_url($person->ID, ['size' => 500]));
    }

    public function test_aPluginThatRunsAfterTheAvatarFilterHasTheLastWordForEveryone(): void
    {
        add_filter('get_avatar_url', [Person::class, 'pictureFilter'], 10, 3);
        add_filter('get_avatar_url', fn() => self::AVATAR_FROM_ANOTHER_PLUGIN, 20);
        $withPicture    = $this->importWithPicture(1094);
        $withoutPicture = $this->import(self::personData(1095, ['Picture' => null]));

        $this->assertSame(self::AVATAR_FROM_ANOTHER_PLUGIN, get_avatar_url($withPicture->ID));
        $this->assertSame(self::AVATAR_FROM_ANOTHER_PLUGIN, get_avatar_url($withoutPicture->ID));
    }

    public function test_aPluginThatRunsAfterTheAvatarFilterIsGivenTheTouchPointPicture(): void
    {
        $received = null;
        add_filter('get_avatar_url', [Person::class, 'pictureFilter'], 10, 3);
        add_filter('get_avatar_url', function ($url) use (&$received) {
            $received = $url;

            return $url;
        }, 20);
        $person = $this->importWithPicture(1096);

        get_avatar_url($person->ID, ['size' => 500]);

        $this->assertSame(self::PICTURE['large'], $received, 'So it can decide whether to replace it.');
    }

    public function test_aPluginThatRunsAfterTheAvatarFilterIsGivenWordPressesAvatarForPeopleWithoutATouchPointPicture(): void
    {
        $received = null;
        add_filter('get_avatar_url', [Person::class, 'pictureFilter'], 10, 3);
        add_filter('get_avatar_url', function ($url) use (&$received) {
            $received = $url;

            return $url;
        }, 20);
        $person = $this->import(self::personData(1097, ['Picture' => null]));

        get_avatar_url($person->ID);

        $this->assertStringContainsString('gravatar.com', $received, 'The address WordPress chose, which it can use or replace.');
    }

    public function test_aPluginThatProvidesAvatarsBeforeWordPressLooksIsNotAffectedByTheAvatarFilter(): void
    {
        add_filter('pre_get_avatar_data', function ($args) {
            $args['url'] = self::AVATAR_FROM_ANOTHER_PLUGIN;

            return $args;
        });
        add_filter('get_avatar_url', [Person::class, 'pictureFilter'], 10, 3);
        $person = $this->importWithPicture(1098);

        $this->assertSame(self::AVATAR_FROM_ANOTHER_PLUGIN, get_avatar_url($person->ID), 'WordPress never gets as far as the filter.');
    }

    ////////////////////////////////////
    // The site's "Show Avatars" setting //
    ////////////////////////////////////

    /**
     * Register the filters as Person does, and set whether the site shows avatars.
     */
    private function siteShowsAvatars(bool $shows): void
    {
        update_option('show_avatars', $shows ? 1 : 0);
        add_filter('get_avatar_url', [Person::class, 'showAvatarsFilter'], PHP_INT_MIN);
        add_filter('get_avatar_url', [Person::class, 'pictureFilter'], 10, 3);
    }

    public function test_showAvatarsFilter_passesTheAddressThroughWhenTheSiteShowsAvatars(): void
    {
        update_option('show_avatars', 1);

        $this->assertSame(self::WORDPRESS_AVATAR, Person::showAvatarsFilter(self::WORDPRESS_AVATAR));
    }

    public function test_showAvatarsFilter_isFalseWhenTheSiteDoesNotShowAvatars(): void
    {
        update_option('show_avatars', 0);

        $this->assertFalse(Person::showAvatarsFilter(self::WORDPRESS_AVATAR));
    }

    public function test_whenTheSiteShowsAvatarsNothingChanges(): void
    {
        $this->siteShowsAvatars(true);
        $with    = $this->importWithPicture(1100);
        $without = $this->import(self::personData(1101, ['Picture' => null]));

        $this->assertSame(self::PICTURE['large'], get_avatar_url($with->ID, ['size' => 500]));
        $this->assertStringContainsString('gravatar.com', get_avatar_url($without->ID));
        $this->assertStringContainsString('gravatar.com', get_avatar_url('stranger@example.org'));
    }

    public function test_whenTheSiteDoesNotShowAvatarsThereIsNoGravatar(): void
    {
        $this->siteShowsAvatars(false);
        $without = $this->import(self::personData(1102, ['Picture' => null]));

        $this->assertFalse(get_avatar_url($without->ID));
        $this->assertFalse(get_avatar_url('stranger@example.org'));
    }

    public function test_whenTheSiteDoesNotShowAvatarsPeopleWithATouchPointPictureStillHaveIt(): void
    {
        $this->siteShowsAvatars(false);
        $with = $this->importWithPicture(1103);

        $this->assertSame(self::PICTURE['large'], get_avatar_url($with->ID, ['size' => 500]));
        $this->assertSame(self::PICTURE['thumb'], get_avatar_url($with->ID, ['size' => 40]));
    }

    /**
     * @return array[] [the priority of another plugin's filter]
     */
    public static function provider_priorities(): array
    {
        return [
            'lowest'       => [PHP_INT_MIN + 1],
            'negative'     => [-5],
            'zero'         => [0],
            'one'          => [1],
            'before ours'  => [5],
            'the default'  => [10],
            'after ours'   => [20],
            'highest'      => [PHP_INT_MAX],
        ];
    }

    /**
     * @dataProvider provider_priorities
     */
    public function test_whenTheSiteDoesNotShowAvatarsAnotherPluginCanStillProvideThemAtAnyPriority(int $priority): void
    {
        $this->siteShowsAvatars(false);
        add_filter('get_avatar_url', fn() => self::AVATAR_FROM_ANOTHER_PLUGIN, $priority);
        $without = $this->import(self::personData(1104, ['Picture' => null]));

        $this->assertSame(self::AVATAR_FROM_ANOTHER_PLUGIN, get_avatar_url($without->ID));
    }

    public function test_whenTheSiteDoesNotShowAvatarsAPluginThatProvidesThemBeforeWordPressLooksIsNotAffected(): void
    {
        $this->siteShowsAvatars(false);
        add_filter('pre_get_avatar_data', function ($args) {
            $args['url'] = self::AVATAR_FROM_ANOTHER_PLUGIN;

            return $args;
        });
        $without = $this->import(self::personData(1105, ['Picture' => null]));

        $this->assertSame(self::AVATAR_FROM_ANOTHER_PLUGIN, get_avatar_url($without->ID));
    }

    public function test_whenTheSiteDoesNotShowAvatarsAPersonWithoutAPictureIsListedWithoutOne(): void
    {
        // The template of a person in a People List, which uses get_avatar_url().
        $this->siteShowsAvatars(false);
        $person   = $this->import(self::personData(1106, ['Picture' => null]));
        $btnClass = '';

        ob_start();
        include TouchPointWP::$dir . '/src/templates/parts/person-list-item.php';
        $html = ob_get_clean();

        $this->assertStringNotContainsString('background-image', $html);
        $this->assertStringNotContainsString('gravatar', $html);
        $this->assertStringContainsString('Jane Testperson', $html, 'They are still listed.');
    }

    public function test_whenTheSiteDoesNotShowAvatarsAPersonWithAPictureIsListedWithIt(): void
    {
        $this->siteShowsAvatars(false);
        $person   = $this->importWithPicture(1107);
        $btnClass = '';

        ob_start();
        include TouchPointWP::$dir . '/src/templates/parts/person-list-item.php';
        $html = ob_get_clean();

        $this->assertStringContainsString("background-image: url('" . self::PICTURE['medium'] . "')", $html);
    }

    public function test_getAvatarStillGivesNothingWhenTheSiteDoesNotShowAvatars(): void
    {
        $this->siteShowsAvatars(false);
        $person = $this->importWithPicture(1108);

        $this->assertFalse(get_avatar($person->ID), 'WordPress checks the setting here itself.');
    }

    public function test_pictureFilter_arguments_thatAreNotAnArrayAreIgnored(): void
    {
        // Filters get whatever other code gives them.
        $person = $this->importWithPicture(1091);

        $this->assertSame(self::PICTURE['large'], Person::pictureFilter(self::WORDPRESS_AVATAR, $person->ID, 'not an array'));
        $this->assertSame(self::WORDPRESS_AVATAR, Person::pictureFilter(self::WORDPRESS_AVATAR, 99999999, null));
    }

    public function test_aPersonWithoutAPictureStillHasAWordPressAvatar(): void
    {
        $person = $this->import(self::personData(1086, ['Picture' => null]));
        add_filter('get_avatar_url', [Person::class, 'pictureFilter'], 10, 3);

        $this->assertStringContainsString('gravatar.com', get_avatar_url($person->ID));
    }

    public function test_aCommenterWhoIsNotAUserStillHasAWordPressAvatar(): void
    {
        add_filter('get_avatar_url', [Person::class, 'pictureFilter'], 10, 3);

        $this->assertStringContainsString('gravatar.com', get_avatar_url('stranger@example.org'));
    }

    public function test_aPersonWithAPictureHasThatAsTheirAvatar(): void
    {
        $person = $this->importWithPicture(1087);
        add_filter('get_avatar_url', [Person::class, 'pictureFilter'], 10, 3);

        $this->assertSame(self::PICTURE['large'], get_avatar_url($person->ID, ['size' => 500]));
        $this->assertSame(self::PICTURE['thumb'], get_avatar_url($person->ID, ['size' => 40]));
    }

    ///////////////////////////////////////////////////////
    // Who an avatar is for, when WordPress says by object //
    ///////////////////////////////////////////////////////

    public function test_aPostsAvatarIsItsAuthorsPicture(): void
    {
        $author = $this->importWithPicture(1088);
        $post   = get_post(self::factory()->post->create(['post_author' => $author->ID]));

        $this->assertSame(self::PICTURE['large'], Person::getPictureForPerson($post));
    }

    public function test_aPostsAvatarIsNotThePictureOfTheUserWhoseIdIsThePostsId(): void
    {
        global $wpdb;

        $author = $this->import(self::personData(1089, ['Picture' => null]));
        $post   = get_post(self::factory()->post->create(['post_author' => $author->ID]));

        // A user whose ID happens to be the same number as the post's, and who has a picture.
        $wpdb->insert($wpdb->users, [
            'ID'              => $post->ID,
            'user_login'      => 'same-number-as-the-post',
            'user_pass'       => 'not-a-real-hash',
            'user_nicename'   => 'same-number-as-the-post',
            'user_email'      => 'same-number@example.org',
            'user_registered' => '2026-01-01 00:00:00',
            'display_name'    => 'Same Number',
        ]);
        update_user_option($post->ID, Person::META_PEOPLEID, 9999, true);
        update_user_option($post->ID, 'tp_picture', (object)self::PICTURE, true);
        clean_user_cache($post->ID);

        $this->assertNull(Person::getPictureForPerson($post), 'The post is by someone who has no picture.');
    }

    public function test_aCommentsAvatarIsItsUsersPicture(): void
    {
        $user    = $this->importWithPicture(1090);
        $comment = get_comment(self::factory()->comment->create(['user_id' => $user->ID, 'comment_post_ID' => self::factory()->post->create()]));

        $this->assertSame(self::PICTURE['large'], Person::getPictureForPerson($comment));
    }

    public function test_aGuestCommentHasNoTouchPointPicture(): void
    {
        $comment = get_comment(self::factory()->comment->create(['user_id' => 0, 'comment_post_ID' => self::factory()->post->create()]));

        $this->assertNull(Person::getPictureForPerson($comment));
    }
}
