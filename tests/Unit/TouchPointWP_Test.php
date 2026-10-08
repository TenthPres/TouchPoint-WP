<?php
/**
 * Tests for the small data helpers in the TouchPointWP class
 *
 * @package TouchPointWP\Tests\Unit
 */

namespace tp\TouchPointWP\Tests\Unit;

use stdClass;
use tp\TouchPointWP\Tests\TestCase;
use tp\TouchPointWP\TouchPointWP;

/**
 * Test case for the static helpers of the TouchPointWP class that rearrange lists of data: ordering categories so that
 * children follow their parents, turning lists of records into key-value arrays, and listing Extra Value fields.
 *
 * @covers \tp\TouchPointWP\TouchPointWP
 */
class TouchPointWP_Test extends TestCase
{
    /**
     * A term (category), as WordPress describes it.
     *
     * @param int    $id
     * @param int    $parent
     * @param string $name
     *
     * @return stdClass
     */
    private static function term(int $id, int $parent, string $name): stdClass
    {
        return (object)['term_id' => $id, 'parent' => $parent, 'name' => $name];
    }

    /**
     * @param stdClass[] $terms
     *
     * @return int[]
     */
    private static function ids(array $terms): array
    {
        return array_map(fn($t) => $t->term_id, $terms);
    }

    ////////////////////////////
    // orderHierarchicalTerms //
    ////////////////////////////

    public function test_orderHierarchicalTerms_childrenFollowTheirParents(): void
    {
        $terms = [
            self::term(3, 1, 'Child of A'),
            self::term(2, 0, 'B'),
            self::term(4, 2, 'Child of B'),
            self::term(1, 0, 'A'),
        ];

        $this->assertSame([1, 3, 2, 4], self::ids(TouchPointWP::orderHierarchicalTerms($terms)));
    }

    public function test_orderHierarchicalTerms_parentsAndChildrenAreSortedByNameIgnoringCase(): void
    {
        $terms = [
            self::term(1, 0, 'zebra'),
            self::term(2, 0, 'Apple'),
            self::term(3, 0, 'mango'),
            self::term(4, 3, 'b child'),
            self::term(5, 3, 'A Child'),
            self::term(6, 3, 'C child'),
        ];

        $this->assertSame([2, 3, 5, 4, 6, 1], self::ids(TouchPointWP::orderHierarchicalTerms($terms)));
    }

    public function test_orderHierarchicalTerms_parentsWithoutChildrenAreKeptByDefault(): void
    {
        $terms = [self::term(1, 0, 'Lonely'), self::term(2, 0, 'Parent'), self::term(3, 2, 'Child')];

        $this->assertSame([1, 2, 3], self::ids(TouchPointWP::orderHierarchicalTerms($terms)));
    }

    public function test_orderHierarchicalTerms_parentsWithoutChildrenCanBeLeftOut(): void
    {
        $terms = [self::term(1, 0, 'Lonely'), self::term(2, 0, 'Parent'), self::term(3, 2, 'Child')];

        $this->assertSame([2, 3], self::ids(TouchPointWP::orderHierarchicalTerms($terms, true)));
    }

    public function test_orderHierarchicalTerms_emptyListIsEmpty(): void
    {
        $this->assertSame([], TouchPointWP::orderHierarchicalTerms([]));
        $this->assertSame([], TouchPointWP::orderHierarchicalTerms([], true));
    }

    public function test_orderHierarchicalTerms_returnsTheSameObjects(): void
    {
        $a = self::term(1, 0, 'A');

        $this->assertSame([$a], TouchPointWP::orderHierarchicalTerms([$a]));
    }

    //////////////////////
    // flattenArrayToKV //
    //////////////////////

    public function test_flattenArrayToKV_arraysAndObjects(): void
    {
        $records = [
            ['id' => 1, 'name' => 'One'],
            (object)['id' => 2, 'name' => 'Two'],
        ];

        $this->assertSame([1 => 'One', 2 => 'Two'], TouchPointWP::flattenArrayToKV($records, 'id', 'name'));
    }

    public function test_flattenArrayToKV_keyPrefix(): void
    {
        $records = [['id' => 1, 'name' => 'One'], ['id' => 2, 'name' => 'Two']];

        $this->assertSame(['t1' => 'One', 't2' => 'Two'], TouchPointWP::flattenArrayToKV($records, 'id', 'name', 't'));
    }

    public function test_flattenArrayToKV_theLastOfDuplicateKeysWins(): void
    {
        $records = [['id' => 1, 'name' => 'First'], ['id' => 1, 'name' => 'Second']];

        $this->assertSame([1 => 'Second'], TouchPointWP::flattenArrayToKV($records, 'id', 'name'));
    }

    public function test_flattenArrayToKV_emptyIsEmpty(): void
    {
        $this->assertSame([], TouchPointWP::flattenArrayToKV([], 'id', 'name'));
    }

    ////////////////////////////////////////
    // standardizeExtraValuesForKVArray   //
    ////////////////////////////////////////

    /**
     * @return stdClass[] Extra Value fields, as the API describes them.
     */
    private static function fields(): array
    {
        return [
            (object)['field' => 'Grade', 'hash' => 'h1', 'type' => 'Text'],
            (object)['field' => 'Campus', 'hash' => 'h2', 'type' => 'Code'],
            (object)['field' => '', 'hash' => 'h3', 'type' => 'Text'],
            (object)['field' => 'Mystery', 'hash' => 'h4', 'type' => ''],
            (object)['field' => 'Nickname', 'hash' => 'h5', 'type' => 'text'],
        ];
    }

    private static function evs(?string $type = null, bool $addNone = false): array
    {
        return self::callStatic(TouchPointWP::class, 'standardizeExtraValuesForKVArray', self::fields(), $type, $addNone);
    }

    public function test_standardizeExtraValues_unfilteredFieldsShowTheirTypes(): void
    {
        $this->assertSame(
            ['h1' => 'Grade (Text)', 'h2' => 'Campus (Code)', 'h4' => 'Mystery (Unknown Type)', 'h5' => 'Nickname (text)'],
            self::evs()
        );
    }

    public function test_standardizeExtraValues_blankFieldsAreSkipped(): void
    {
        $this->assertArrayNotHasKey('h3', self::evs());
        $this->assertArrayNotHasKey('h3', self::evs('Text'));
    }

    public function test_standardizeExtraValues_filteredByTypeShowsOnlyNames(): void
    {
        $this->assertSame(['h1' => 'Grade', 'h5' => 'Nickname'], self::evs('Text'));
        $this->assertSame(['h2' => 'Campus'], self::evs('Code'));
    }

    public function test_standardizeExtraValues_theTypeFilterIgnoresCaseAndSpaces(): void
    {
        $this->assertSame(['h1' => 'Grade', 'h5' => 'Nickname'], self::evs('  tEXT '));
    }

    public function test_standardizeExtraValues_aTypeWithNoFieldsIsEmpty(): void
    {
        $this->assertSame([], self::evs('Date'));
    }

    public function test_standardizeExtraValues_noneCanBeAddedFirst(): void
    {
        $this->assertSame(['' => '', 'h2' => 'Campus'], self::evs('Code', true));
        $this->assertSame(['' => ''], self::callStatic(TouchPointWP::class, 'standardizeExtraValuesForKVArray', [], null, true));
    }

    /**
     * @group known-issue
     */
    public function test_orderHierarchicalTerms_aTermWhoseParentIsNotInTheListIsKept(): void
    {
        // For example, the parent was left out of the list because it has no posts.  The term is listed with the top-level terms.
        $terms = [self::term(1, 0, 'A'), self::term(2, 99, 'Orphan'), self::term(3, 0, 'Z')];

        $this->assertSame([1, 2, 3], self::ids(TouchPointWP::orderHierarchicalTerms($terms)));
    }

    /**
     * @group known-issue
     */
    public function test_orderHierarchicalTerms_grandchildrenFollowTheirParents(): void
    {
        $terms = [
            self::term(1, 0, 'Root'),
            self::term(2, 1, 'Child'),
            self::term(3, 2, 'Grandchild'),
            self::term(4, 1, 'Second Child'),
            self::term(5, 0, 'Another Root'),
            self::term(6, 4, 'Second Grandchild'),
        ];

        $this->assertSame([5, 1, 2, 3, 4, 6], self::ids(TouchPointWP::orderHierarchicalTerms($terms)));
        $this->assertSame([1, 2, 3, 4, 6], self::ids(TouchPointWP::orderHierarchicalTerms(array_filter($terms, fn($t) => $t->term_id !== 5), true)));
    }

    /**
     * @group known-issue
     */
    public function test_orderHierarchicalTerms_noTermIsLost(): void
    {
        $terms = [
            self::term(1, 0, 'Root'),
            self::term(2, 1, 'Child'),
            self::term(3, 2, 'Grandchild'),
            self::term(4, 77, 'Orphan'),
            self::term(5, 4, 'Child of the orphan'),
        ];

        $this->assertEqualsCanonicalizing(self::ids($terms), self::ids(TouchPointWP::orderHierarchicalTerms($terms)));
    }
}
