<?php
/**
 * Tests for the plugin's taxonomies, with WordPress's terms and database
 *
 * @package TouchPointWP\Tests\WordPress
 */

namespace tp\TouchPointWP\Tests\WordPress;

use tp\TouchPointWP\Taxonomies;
use tp\TouchPointWP\TouchPointWP;
use WP_Error;
use WP_Term;

/**
 * Test case for the way Divisions (which are Programs and the Divisions in them) become terms, are removed when they
 * stop being imported, are found again, and are listed for the filter dropdowns.
 *
 * TouchPoint isn't contacted: the API's data is put where the plugin keeps what it got from the API, and requests to
 * other servers are refused.
 *
 * @covers \tp\TouchPointWP\Taxonomies
 * @covers \tp\TouchPointWP\TouchPointWP
 */
class Taxonomies_Test extends WPTestCase
{
    private const POST_TYPE = 'tp_inv_taxtest';

    /**
     * Divisions, as the API provides them.
     */
    private const DIVISIONS = [
        ['id' => 101, 'proId' => 10, 'pName' => 'Adults', 'dName' => 'Bible Study'],
        ['id' => 102, 'proId' => 10, 'pName' => 'Adults', 'dName' => 'Prayer'],
        ['id' => 201, 'proId' => 20, 'pName' => 'Youth', 'dName' => 'Middle School'],
        ['id' => 202, 'proId' => 20, 'pName' => 'Youth', 'dName' => 'High School'],
    ];

    public function set_up(): void
    {
        parent::set_up();

        add_filter('pre_http_request', fn() => new WP_Error('http_request_failed', 'Not allowed in tests.'));

        register_post_type(self::POST_TYPE, ['public' => true, 'hierarchical' => true]);

        // What the plugin keeps from the API.  The resident codes are needed by the sync, even though they're not tested.
        $this->setSetting('meta_resCodes', json_encode(['_updated' => date('c'), 'resCodes' => []]));
        $this->setSetting('meta_divisions', json_encode(['_updated' => date('c'), 'divs' => array_map(fn($d) => (object)$d, self::DIVISIONS)]));
        $this->setSetting('dv_divisions', ['div101', 'div102', 'div201', 'div202']);
        $this->setSetting('dv_additional_post_types', [self::POST_TYPE]);

        Taxonomies::registerTaxonomies(TouchPointWP::instance());
    }

    ///////////////
    // Helpers   //
    ///////////////

    /**
     * Run the sync once, as the plugin does when it's upgraded or activated, as a new request: nothing the plugin or
     * WordPress remembered from earlier is still remembered.
     */
    private function syncOnce(): void
    {
        self::setStatic(Taxonomies::class, 'termExistsCache', []);
        self::setStatic(TouchPointWP::class, 'divisionTerms', []);
        wp_cache_flush();

        Taxonomies::insertTerms(TouchPointWP::instance());
    }

    /**
     * Run the sync until it has settled.  (When a program is new, it takes two runs to make all of its divisions.  See
     * test_aSingleSyncMakesEveryDivision.)
     */
    private function syncTerms(): void
    {
        $this->syncOnce();
        $this->syncOnce();
    }

    /**
     * The terms of the Division taxonomy, as a tree of names.
     *
     * @return array Programs' names, each with a list of its divisions' names.  Both are sorted.
     */
    private function divisionTree(): array
    {
        $terms = get_terms(['taxonomy' => Taxonomies::TAX_DIV, 'hide_empty' => false]);

        $tree = [];
        foreach ($terms as $t) {
            if ($t->parent === 0) {
                $tree[$t->name] = $tree[$t->name] ?? [];
            }
        }
        foreach ($terms as $t) {
            if ($t->parent !== 0) {
                $tree[get_term($t->parent)->name][] = $t->name;
            }
        }
        foreach ($tree as &$children) {
            sort($children);
        }
        ksort($tree);

        return $tree;
    }

    /**
     * Find a term of the Division taxonomy by its TouchPoint ID.
     *
     * @param int $divId
     *
     * @return ?WP_Term
     */
    private function termForDivision(int $divId): ?WP_Term
    {
        $terms = get_terms([
            'taxonomy'   => Taxonomies::TAX_DIV,
            'hide_empty' => false,
            'meta_key'   => TouchPointWP::SETTINGS_PREFIX . 'divId',
            'meta_value' => $divId,
        ]);

        return $terms[0] ?? null;
    }

    /**
     * Make a post of the test type, in some divisions.
     *
     * @param int[]  $divIds
     * @param string $postType
     *
     * @return int
     */
    private function makePostInDivisions(array $divIds, string $postType = self::POST_TYPE): int
    {
        $id = self::factory()->post->create(['post_type' => $postType]);
        wp_set_post_terms($id, array_map(fn($d) => $this->termForDivision($d)->term_id, $divIds), Taxonomies::TAX_DIV);

        return $id;
    }

    //////////////////////
    // Syncing terms    //
    //////////////////////

    /**
     * @group known-issue
     */
    public function test_aSingleSyncMakesEveryDivision(): void
    {
        $this->syncOnce();

        $this->assertSame(
            [
                'Adults' => ['Bible Study', 'Prayer'],
                'Youth'  => ['High School', 'Middle School'],
            ],
            $this->divisionTree()
        );
    }

    public function test_syncMakesATermForEachProgramWithItsDivisionsInside(): void
    {
        $this->syncTerms();

        $this->assertSame(
            [
                'Adults' => ['Bible Study', 'Prayer'],
                'Youth'  => ['High School', 'Middle School'],
            ],
            $this->divisionTree()
        );
    }

    public function test_syncRemembersTheTouchPointIdsOfProgramsAndDivisions(): void
    {
        $this->syncTerms();

        $division = $this->termForDivision(102);
        $program  = get_term($division->parent);

        $this->assertSame('Prayer', $division->name);
        $this->assertSame('Adults', $program->name);
        $this->assertSame('10', (string)get_term_meta($program->term_id, TouchPointWP::SETTINGS_PREFIX . 'programId', true));
    }

    public function test_syncOnlyMakesTermsForTheDivisionsThatAreEnabled(): void
    {
        $this->setSetting('dv_divisions', ['div101', 'div201']);

        $this->syncTerms();

        $this->assertSame(['Adults' => ['Bible Study'], 'Youth' => ['Middle School']], $this->divisionTree());
    }

    public function test_aDivisionWithoutAProgramOrNameIsNotImported(): void
    {
        $divs = self::DIVISIONS;
        $divs[] = ['id' => 301, 'proId' => 0, 'pName' => '', 'dName' => 'Orphaned'];
        $divs[] = ['id' => 302, 'proId' => 30, 'pName' => 'Nameless', 'dName' => ''];
        $this->setSetting('meta_divisions', json_encode(['_updated' => date('c'), 'divs' => array_map(fn($d) => (object)$d, $divs)]));
        $this->setSetting('dv_divisions', ['div101', 'div301', 'div302']);

        $this->syncTerms();

        $this->assertSame(['Adults' => ['Bible Study']], $this->divisionTree());
    }

    public function test_syncingAgainChangesNothing(): void
    {
        $this->syncTerms();
        $before = [$this->termForDivision(101)->term_id, $this->termForDivision(202)->term_id];

        $this->syncOnce();
        $this->syncOnce();

        $this->assertSame($before, [$this->termForDivision(101)->term_id, $this->termForDivision(202)->term_id]);
        $this->assertSame(
            ['Adults' => ['Bible Study', 'Prayer'], 'Youth' => ['High School', 'Middle School']],
            $this->divisionTree()
        );
    }

    public function test_syncRemovesTheTermsOfDivisionsThatAreNoLongerImported(): void
    {
        $this->syncTerms();

        $this->setSetting('dv_divisions', ['div101', 'div201']);
        $this->syncTerms();

        $this->assertSame(['Adults' => ['Bible Study'], 'Youth' => ['Middle School']], $this->divisionTree());
        $this->assertNull($this->termForDivision(102));
    }

    public function test_syncRemovesAProgramWhenNoneOfItsDivisionsAreImportedAnymore(): void
    {
        $this->syncTerms();

        $this->setSetting('dv_divisions', ['div101', 'div102']);
        $this->syncTerms();

        $this->assertSame(['Adults' => ['Bible Study', 'Prayer']], $this->divisionTree());
    }

    public function test_syncRemovesATermThatDoesNotBelong(): void
    {
        $this->syncTerms();
        wp_insert_term('Added By Hand', Taxonomies::TAX_DIV);
        wp_insert_term('Also By Hand', Taxonomies::TAX_DIV, ['parent' => get_term_by('name', 'Adults', Taxonomies::TAX_DIV)->term_id]);

        $this->syncTerms();

        $this->assertSame(
            ['Adults' => ['Bible Study', 'Prayer'], 'Youth' => ['High School', 'Middle School']],
            $this->divisionTree()
        );
    }

    public function test_aDivisionThatMovesToAnotherProgramGetsATermInTheNewProgramAndLosesTheOldOne(): void
    {
        $this->syncTerms();

        // In TouchPoint, Prayer moved from Adults to Youth.
        $divs = array_map(fn($d) => $d['id'] === 102 ? ['pName' => 'Youth', 'proId' => 20] + $d : $d, self::DIVISIONS);
        $this->setSetting('meta_divisions', json_encode(['_updated' => date('c'), 'divs' => array_map(fn($d) => (object)$d, $divs)]));
        $this->syncTerms();

        $this->assertSame(
            ['Adults' => ['Bible Study'], 'Youth' => ['High School', 'Middle School', 'Prayer']],
            $this->divisionTree()
        );
        $this->assertSame('Youth', get_term($this->termForDivision(102)->parent)->name);
    }

    public function test_syncFindsNothingToDoWhenNothingIsEnabled(): void
    {
        $this->setSetting('dv_divisions', []);

        $this->syncTerms();

        $this->assertSame([], $this->divisionTree());
    }

    /////////////////////////////////
    // Finding a division's term   //
    /////////////////////////////////

    public function test_getDivisionTermIdByDivId_findsTheTerm(): void
    {
        $this->syncTerms();

        $this->assertSame($this->termForDivision(201)->term_id, TouchPointWP::getDivisionTermIdByDivId(201));
    }

    public function test_getDivisionTermIdByDivId_isZeroForADivisionThatIsNotImported(): void
    {
        $this->setSetting('dv_divisions', ['div101']);
        $this->syncTerms();

        $this->assertSame(0, TouchPointWP::getDivisionTermIdByDivId(201));
        $this->assertSame(0, TouchPointWP::getDivisionTermIdByDivId(99999));
    }

    /////////////////////////////////////////
    // Listing divisions for a dropdown    //
    /////////////////////////////////////////

    /**
     * Get the terms the way the filter dropdown does, for the post type.
     *
     * @param string $postType
     *
     * @return WP_Term[]
     */
    private function dropdownTerms(string $postType = self::POST_TYPE): array
    {
        $terms = get_terms([
            'taxonomy'                              => Taxonomies::TAX_DIV,
            'hide_empty'                            => true,
            'meta_query'                            => [],
            TouchPointWP::HOOK_PREFIX . 'post_type' => $postType,
        ]);

        return TouchPointWP::orderHierarchicalTerms($terms, true);
    }

    /**
     * @param WP_Term[] $terms
     *
     * @return string[] Names, with the Divisions indented under their Programs.
     */
    private static function indented(array $terms): array
    {
        return array_map(fn($t) => ($t->parent === 0 ? '' : '  ') . $t->name, $terms);
    }

    public function test_theDropdownListsOnlyDivisionsThatHavePostsOfTheType(): void
    {
        $this->syncTerms();
        $this->makePostInDivisions([101]);
        $this->makePostInDivisions([102, 201]);

        $this->assertSame(
            ['Adults', '  Bible Study', '  Prayer', 'Youth', '  Middle School'],
            self::indented($this->dropdownTerms())
        );
    }

    public function test_theDropdownDoesNotListProgramsThatHaveNoDivisionsWithPosts(): void
    {
        $this->syncTerms();
        $this->makePostInDivisions([201]);

        $this->assertSame(['Youth', '  Middle School'], self::indented($this->dropdownTerms()));
    }

    public function test_theDropdownIgnoresPostsOfOtherTypes(): void
    {
        $this->syncTerms();
        register_post_type('tp_other_type', ['public' => true]);
        register_taxonomy_for_object_type(Taxonomies::TAX_DIV, 'tp_other_type');
        $this->makePostInDivisions([101], 'tp_other_type');
        $this->makePostInDivisions([202]);

        $this->assertSame(['Youth', '  High School'], self::indented($this->dropdownTerms()));
        $this->assertSame(['Adults', '  Bible Study'], self::indented($this->dropdownTerms('tp_other_type')));
    }

    public function test_theDropdownIsEmptyWhenNothingIsInAnyDivision(): void
    {
        $this->syncTerms();

        $this->assertSame([], $this->dropdownTerms());
    }

    public function test_theDropdownNeverListsADivisionWithoutItsProgram(): void
    {
        $this->syncTerms();
        $this->makePostInDivisions([101, 102, 201, 202]);

        foreach ($this->dropdownTerms() as $term) {
            if ($term->parent !== 0) {
                $this->assertContains($term->parent, array_map(fn($t) => $t->term_id, $this->dropdownTerms()), $term->name);
            }
        }
    }
}
