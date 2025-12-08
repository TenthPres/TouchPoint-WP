<?php
/**
 * Integration tests for Colors class filters
 *
 * @package TouchPointWP\Tests\Integration
 */

namespace tp\TouchPointWP\Tests\Integration;

use tp\TouchPointWP\Utilities\Colors;
use tp\TouchPointWP\Tests\TestCase;

/**
 * Test case for Colors class filters.
 * Tests the WordPress filter integration for color customization.
 *
 * @covers \tp\TouchPointWP\Utilities\Colors
 */
class ColorsFiltersTest extends TestCase
{
    /**
     * Test that tp_custom_color_function filter can override color assignment.
     */
    public function test_custom_color_function_filter_overrides_color(): void
    {
        // Add filter to return a custom color
        add_filter('tp_custom_color_function', function($current, $itemName, $setName) {
            if ($itemName === 'PA' && $setName === 'States') {
                return '#FF0000'; // Red for Pennsylvania
            }
            return $current;
        }, 10, 3);

        $color = Colors::getColorFor('PA', 'States');
        
        $this->assertSame('#FF0000', $color);
        
        // Clean up filter
        remove_all_filters('tp_custom_color_function');
    }

    /**
     * Test that tp_custom_color_function filter receives correct parameters.
     */
    public function test_custom_color_function_filter_receives_correct_params(): void
    {
        $receivedParams = [];
        
        add_filter('tp_custom_color_function', function($current, $itemName, $setName) use (&$receivedParams) {
            $receivedParams = [
                'current' => $current,
                'itemName' => $itemName,
                'setName' => $setName
            ];
            return null; // Don't override
        }, 10, 3);

        Colors::getColorFor('NY', 'States');
        
        $this->assertNull($receivedParams['current']);
        $this->assertSame('NY', $receivedParams['itemName']);
        $this->assertSame('States', $receivedParams['setName']);
        
        remove_all_filters('tp_custom_color_function');
    }

    /**
     * Test that tp_custom_color_function filter can return null to defer to default.
     */
    public function test_custom_color_function_filter_can_defer_to_default(): void
    {
        add_filter('tp_custom_color_function', function($current, $itemName, $setName) {
            return null; // Defer to default color assignment
        }, 10, 3);

        $color = Colors::getColorFor('CA', 'States');
        
        // Should return a valid hex color (default behavior)
        $this->assertStringStartsWith('#', $color);
        $this->assertSame(7, strlen($color)); // # + 6 hex chars
        
        remove_all_filters('tp_custom_color_function');
    }

    /**
     * Test that tp_custom_color_set filter can provide custom color palette.
     */
    public function test_custom_color_set_filter_provides_palette(): void
    {
	    add_filter('tp_custom_color_set', function($array, $setName) {
		    $customColors = ['#FF0000', '#00FF00', '#0000FF'];
		    if ($setName === 'TestSet') {
                return $customColors;
            }
            return $array;
        }, 10, 2);

        $color1 = Colors::getColorFor('Item1', 'TestSet');
        $color2 = Colors::getColorFor('Item2', 'TestSet');
        $color3 = Colors::getColorFor('Item3', 'TestSet');
        $color4 = Colors::getColorFor('Item4', 'TestSet');
        
        $this->assertSame('#FF0000', $color1);
        $this->assertSame('#00FF00', $color2);
        $this->assertSame('#0000FF', $color3);
        $this->assertSame('#FF0000', $color4); // Wraps around to first color
        
        remove_all_filters('tp_custom_color_set');
    }

    /**
     * Test that tp_custom_color_set filter receives correct parameters.
     */
    public function test_custom_color_set_filter_receives_correct_params(): void
    {
        $receivedParams = [];
        
        add_filter('tp_custom_color_set', function($array, $setName) use (&$receivedParams) {
            $receivedParams = [
                'array' => $array,
                'setName' => $setName
            ];
            return [];
        }, 10, 2);

        Colors::getColorFor('Item1', 'MySet');
        
        $this->assertIsArray($receivedParams['array']);
        $this->assertEmpty($receivedParams['array']); // Should be empty array initially
        $this->assertSame('MySet', $receivedParams['setName']);
        
        remove_all_filters('tp_custom_color_set');
    }

    /**
     * Test that empty custom color set falls back to default algorithm.
     */
    public function test_empty_custom_color_set_uses_default_algorithm(): void
    {
        add_filter('tp_custom_color_set', function($array, $setName) {
            return []; // Return empty array
        }, 10, 2);

        $color = Colors::getColorFor('Item1', 'TestSet');
        
        // Should still return a valid hex color using default algorithm
        $this->assertStringStartsWith('#', $color);
        $this->assertSame(7, strlen($color));
        
        remove_all_filters('tp_custom_color_set');
    }

    /**
     * Test that custom color function takes precedence over custom color set.
     */
    public function test_custom_color_function_takes_precedence_over_color_set(): void
    {
        // Set up both filters
        add_filter('tp_custom_color_set', function($array, $setName) {
            return ['#00FF00', '#0000FF']; // Green and Blue
        }, 10, 2);
        
        add_filter('tp_custom_color_function', function($current, $itemName, $setName) {
            if ($itemName === 'Special') {
                return '#FF0000'; // Red
            }
            return null;
        }, 10, 3);

        $normalColor = Colors::getColorFor('Normal', 'TestSet');
        $specialColor = Colors::getColorFor('Special', 'TestSet');
        
        // Normal item should use color set
        $this->assertSame('#00FF00', $normalColor);
        
        // Special item should use custom function (takes precedence)
        $this->assertSame('#FF0000', $specialColor);
        
        remove_all_filters('tp_custom_color_set');
        remove_all_filters('tp_custom_color_function');
    }

    /**
     * Test that colors are consistent for same item/set combination.
     */
    public function test_colors_are_consistent_for_same_item(): void
    {
        $color1 = Colors::getColorFor('TX', 'States');
        $color2 = Colors::getColorFor('TX', 'States');
        $color3 = Colors::getColorFor('TX', 'States');
        
        $this->assertSame($color1, $color2);
        $this->assertSame($color2, $color3);
    }

    /**
     * Test that different items in same set get different colors (when possible).
     */
    public function test_different_items_in_same_set_get_different_colors(): void
    {
        $colorA = Colors::getColorFor('ItemA', 'TestSet');
        $colorB = Colors::getColorFor('ItemB', 'TestSet');
        $colorC = Colors::getColorFor('ItemC', 'TestSet');
        
        // First few items should have different colors
        $this->assertNotSame($colorA, $colorB);
        $this->assertNotSame($colorB, $colorC);
        $this->assertNotSame($colorA, $colorC);
    }

    /**
     * Test that same item in different sets can have different colors.
     */
    public function test_same_item_different_sets_can_have_different_colors(): void
    {
        $colorInSet1 = Colors::getColorFor('Item', 'Set1');
        $colorInSet2 = Colors::getColorFor('Item', 'Set2');
        
        // Same item name in different sets may have different colors
        // (depends on their position in each set)
        $this->assertIsString($colorInSet1);
        $this->assertIsString($colorInSet2);
        $this->assertStringStartsWith('#', $colorInSet1);
        $this->assertStringStartsWith('#', $colorInSet2);
    }

    /**
     * Test filter priority - higher priority filter should override lower priority.
     */
    public function test_filter_priority_works_correctly(): void
    {
        // Add filter with priority 10
        add_filter('tp_custom_color_function', function($current, $itemName, $setName) {
            return '#00FF00'; // Green
        }, 10, 3);
        
        // Add filter with higher priority (20) - should run last and win
        add_filter('tp_custom_color_function', function($current, $itemName, $setName) {
            return '#FF0000'; // Red
        }, 20, 3);

        $color = Colors::getColorFor('Item', 'TestSet');
        
        // Higher priority (20) filter should win
        $this->assertSame('#FF0000', $color);
        
        remove_all_filters('tp_custom_color_function');
    }
}
