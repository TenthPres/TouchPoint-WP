<?php
/**
 * Tests for the helpers Person uses to name and group people
 *
 * @package TouchPointWP\Tests\Unit
 */

namespace tp\TouchPointWP\Tests\Unit;

use stdClass;
use tp\TouchPointWP\Person;
use tp\TouchPointWP\Tests\TestCase;

/**
 * Test case for the static helpers of the Person class that don't need WordPress's users: writing the names of a family
 * together, grouping people into families, and choosing usernames.
 *
 * @covers \tp\TouchPointWP\Person
 */
class Person_Test extends TestCase
{
    /**
     * A person, as the API describes them.  GenderId 1 is male, 2 is female.
     *
     * @param string  $first
     * @param string  $last
     * @param int     $gender
     * @param ?string $url   The address of the person's page.  Null if they have no page.
     *
     * @return object
     */
    private static function person(string $first, string $last, int $gender = 0, ?string $url = null): object
    {
        return new class($first, $last, $gender, $url) {
            public $GoesBy;
            public $LastName;
            public $GenderId;
            private ?string $url;

            public function __construct($first, $last, $gender, $url)
            {
                $this->GoesBy   = $first;
                $this->LastName = $last;
                $this->GenderId = $gender;
                $this->url      = $url;
            }

            public function getUserUrl(): ?string
            {
                return $this->url;
            }
        };
    }

    private static function names(array $family, bool $asLink = false): ?string
    {
        return self::callStatic(Person::class, 'formatNamesForFamily', $family, $asLink);
    }

    //////////////////////////
    // formatNamesForFamily //
    //////////////////////////

    public function test_formatNamesForFamily_noOneIsNull(): void
    {
        $this->assertNull(self::names([]));
    }

    public function test_formatNamesForFamily_onePerson(): void
    {
        $this->assertSame('John Smith', self::names([self::person('John', 'Smith', 1)]));
    }

    public function test_formatNamesForFamily_twoPeopleShareALastName(): void
    {
        $this->assertSame('John & Jane Smith', self::names([self::person('John', 'Smith', 1), self::person('Jane', 'Smith', 2)]));
    }

    public function test_formatNamesForFamily_manyPeopleAreSeparatedWithCommas(): void
    {
        $family = [self::person('John', 'Smith', 1), self::person('Jane', 'Smith', 2), self::person('Joey', 'Smith', 1)];

        $this->assertSame('John, Joey & Jane Smith', self::names($family));
    }

    public function test_formatNamesForFamily_menAreListedBeforeWomen(): void
    {
        // Both orders of the same couple, with the man first in the output.
        $this->assertSame('John & Jane Smith', self::names([self::person('John', 'Smith', 1), self::person('Jane', 'Smith', 2)]));
        $this->assertSame('John & Jane Smith', self::names([self::person('Jane', 'Smith', 2), self::person('John', 'Smith', 1)]));
    }

    public function test_formatNamesForFamily_peopleWithoutAGenderKeepTheirOrder(): void
    {
        $this->assertSame('Pat & Sam Smith', self::names([self::person('Pat', 'Smith'), self::person('Sam', 'Smith')]));
    }

    public function test_formatNamesForFamily_differentLastNamesAreEachWritten(): void
    {
        $family = [self::person('John', 'Smith', 1), self::person('Jane', 'Doe', 2)];

        $this->assertSame('John Smith & Jane Doe', self::names($family));
    }

    public function test_formatNamesForFamily_differentLastNamesDontDependOnWhoIsListedFirst(): void
    {
        // The woman is listed first, but sorted after the man.  The result should be the same as above.
        $family = [self::person('Jane', 'Doe', 2), self::person('John', 'Smith', 1)];

        $this->assertSame('John Smith & Jane Doe', self::names($family));
    }

    public function test_formatNamesForFamily_peopleCanBeDescribedWithWordPressFieldNames(): void
    {
        $john = (object)['first_name' => 'John', 'last_name' => 'Smith'];
        $jane = (object)['first_name' => 'Jane', 'last_name' => 'Smith'];

        $this->assertSame('John & Jane Smith', self::names([$john, $jane]));
    }

    public function test_formatNamesForFamily_preferredFirstNamesAreUsed(): void
    {
        $person = (object)['GoesBy' => 'Johnny', 'first_name' => 'John', 'LastName' => 'Smith'];

        $this->assertSame('Johnny Smith', self::names([$person]));
    }

    public function test_formatNamesForFamily_linksToPeopleWithPages(): void
    {
        $this->assertSame(
            '<a href="/u/1">John Smith</a>',
            self::names([self::person('John', 'Smith', 1, '/u/1')], true)
        );
    }

    public function test_formatNamesForFamily_peopleWithoutPagesAreNotLinked(): void
    {
        $this->assertSame('John Smith', self::names([self::person('John', 'Smith', 1, null)], true));
        $this->assertSame('John & Jane Smith', self::names([self::person('John', 'Smith', 1), self::person('Jane', 'Smith', 2)], true));
    }

    public function test_formatNamesForFamily_eachLastNameIsLinkedWithItsPerson(): void
    {
        $family = [self::person('John', 'Smith', 1, '/u/1'), self::person('Jane', 'Doe', 2, '/u/2')];

        $this->assertSame('<a href="/u/1">John Smith</a> & <a href="/u/2">Jane Doe</a>', self::names($family, true));
    }

    public function test_formatNamesForFamily_linksAreBalancedWhenPeopleShareALastName(): void
    {
        $family = [self::person('John', 'Smith', 1, '/u/1'), self::person('Jane', 'Smith', 2, '/u/2')];

        $html = self::names($family, true);

        $this->assertSame(substr_count($html, '<a '), substr_count($html, '</a>'), "Unbalanced links: $html");
        $this->assertDoesNotMatchRegularExpression('~<a [^>]*>[^<]*<a ~', $html, "Nested links: $html");
        $this->assertStringContainsString('John', strip_tags($html));
        $this->assertSame('John & Jane Smith', strip_tags($html));
    }

    /////////////////////
    // groupByFamily   //
    /////////////////////

    public function test_groupByFamily_groupsPeopleByFamilyId(): void
    {
        $a = (object)['familyId' => 10, 'n' => 'a'];
        $b = (object)['familyId' => 20, 'n' => 'b'];
        $c = (object)['familyId' => 10, 'n' => 'c'];

        $this->assertSame([10 => [$a, $c], 20 => [$b]], Person::groupByFamily([$a, $b, $c]));
    }

    public function test_groupByFamily_acceptsEitherCapitalization(): void
    {
        $a = (object)['familyId' => 10];
        $b = (object)['FamilyId' => 10];

        $this->assertSame([10 => [$a, $b]], Person::groupByFamily([$a, $b]));
    }

    public function test_groupByFamily_idsAreIntegers(): void
    {
        $a = (object)['familyId' => '10'];
        $b = (object)['FamilyId' => 10.0];

        $this->assertSame([10 => [$a, $b]], Person::groupByFamily([$a, $b]));
    }

    public function test_groupByFamily_peopleWithoutAFamilyAreTogetherUnderZero(): void
    {
        $a = (object)['n' => 'a'];
        $b = (object)['familyId' => null];

        $this->assertSame([0 => [$a, $b]], Person::groupByFamily([$a, $b]));
    }

    public function test_groupByFamily_nullsAreSkipped(): void
    {
        $a = (object)['familyId' => 1];

        $this->assertSame([1 => [$a]], Person::groupByFamily([null, $a, null]));
        $this->assertSame([], Person::groupByFamily([]));
    }

    public function test_groupByFamily_theLowerCaseIdWinsWhenBothAreSet(): void
    {
        $a = (object)['familyId' => 1, 'FamilyId' => 2];

        $this->assertSame([1 => [$a]], Person::groupByFamily([$a]));
    }

    /////////////////////
    // generateUserName //
    /////////////////////

    /**
     * @param string[] $usernames
     * @param string   $displayName
     * @param int      $peopleId
     *
     * @return stdClass
     */
    private static function pData(array $usernames, string $displayName = 'Jane Q. Doe', int $peopleId = 1234): stdClass
    {
        return (object)['Usernames' => $usernames, 'DisplayName' => $displayName, 'PeopleId' => $peopleId];
    }

    private static function username(stdClass $pData): string
    {
        return self::callStatic(Person::class, 'generateUserName', $pData);
    }

    public function test_generateUserName_prefersTheTouchPointUsername(): void
    {
        $this->assertSame('jdoe', self::username(self::pData(['jdoe', 'janed'])));
    }

    public function test_generateUserName_skipsUsernamesThatAreTaken(): void
    {
        $this->setTakenUsernames(['jdoe']);

        $this->assertSame('janed', self::username(self::pData(['jdoe', 'janed'])));
    }

    public function test_generateUserName_neverUsesAnythingContainingAdmin(): void
    {
        $this->assertSame('janed', self::username(self::pData(['admin', 'SysAdmin2', 'janed'])));
        $this->assertSame('janeqdoe', self::username(self::pData(['Administrator'])));
    }

    public function test_generateUserName_withoutUsernamesUsesTheNameInLowerCase(): void
    {
        $this->assertSame('janeqdoe', self::username(self::pData([])));
    }

    public function test_generateUserName_theNameHasOnlyLettersAndNumbers(): void
    {
        $this->assertSame('maryannosmithjones', self::username(self::pData([], "Mary-Ann O'Smith  Jones")));
    }

    public function test_generateUserName_aTakenNameGetsTheIdAdded(): void
    {
        $this->setTakenUsernames(['janeqdoe']);

        $this->assertSame('janeqdoe1234', self::username(self::pData([])));
    }

    public function test_generateUserName_asALastResortIsPrefixedWithTheId(): void
    {
        $this->setTakenUsernames(['jdoe', 'janeqdoe', 'janeqdoe1234']);

        $this->assertSame(Person::BACKUP_USER_PREFIX . '1234', self::username(self::pData(['jdoe'])));
    }
}
