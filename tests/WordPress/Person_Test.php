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

    /**
     * @group known-issue
     */
    public function test_aPersonWithoutAResidentCodeHasNone(): void
    {
        $person = $this->import(self::personData(1041, ['ResCode' => null]));

        $this->assertNull($person->rescode);
    }

    /**
     * @group known-issue
     */
    public function test_aPersonWithoutAResidentCodeIsNotGivenTheFirstOne(): void
    {
        Taxonomies::insertTermsForLookupBasedTaxonomy(
            [(object)['id' => 7, 'name' => 'North Side'], (object)['id' => 8, 'name' => 'South Side']],
            Taxonomies::TAX_RESCODE,
            false
        );

        $person = $this->import(self::personData(1042, ['ResCode' => null]));

        $this->assertEmpty($person->rescode_term_id);
    }

    /**
     * @group known-issue
     */
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

    /**
     * A filter for avatar addresses gets the address WordPress has chosen, and returns what to use.  Returning null
     * for someone without a TouchPoint picture removes WordPress's own choice (such as a Gravatar), so that person, or a
     * commenter who isn't a user at all, gets no avatar.
     *
     * @group known-issue
     */
    public function test_pictureFilter_leavesTheAvatarAloneWhenThereIsNoTouchPointPicture(): void
    {
        $person = $this->import(self::personData(1084, ['Picture' => null]));

        $this->assertSame('https://example.org/gravatar.jpg', Person::pictureFilter('https://example.org/gravatar.jpg', $person->ID, []));
        $this->assertSame('https://example.org/gravatar.jpg', Person::pictureFilter('https://example.org/gravatar.jpg', 'stranger@example.org', []));
    }
}
