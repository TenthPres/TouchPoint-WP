<?php
/**
 * Unit tests for the Colors class
 * @package TouchPointWP\Tests\Unit\Utilities
 */

namespace tp\TouchPointWP\Tests\Unit\Utilities;

use tp\TouchPointWP\Utilities\Colors;
use PHPUnit\Framework\TestCase;

class Colors_Test extends TestCase
{
    public function test_hslToHex_rgb()
    {
        // Test known HSL to HEX conversions
        $this->assertEquals('#ff0000', Colors::hslToHex(0, 100, 50)); // Red
        $this->assertEquals('#00ff00', Colors::hslToHex(120, 100, 50)); // Green
        $this->assertEquals('#0000ff', Colors::hslToHex(240, 100, 50)); // Blue
    }

    public function test_hslToHex_common()
    {
        // Test edge values
        $this->assertEquals('#808080', Colors::hslToHex(0, 0, 50)); // Gray
        $this->assertEquals('#ffffff', Colors::hslToHex(0, 0, 100)); // White
        $this->assertEquals('#000000', Colors::hslToHex(0, 0, 0)); // Black
    }

    public function test_getColorFor_returnsHex()
    {
        $color = Colors::getColorFor('TestItem', 'TestSet');
        $this->assertMatchesRegularExpression('/^#[0-9a-fA-F]{6}$/', $color);
    }

    public function test_getColorFor_UniquenessWithinSet()
    {
        $color1 = Colors::getColorFor('Item1', 'SetA');
        $color2 = Colors::getColorFor('Item2', 'SetA');
        $this->assertNotEquals($color1, $color2);
    }

    public function test_getColorFor_sameColorForSameItem()
    {
        $color1 = Colors::getColorFor('ItemX', 'SetB');
		Colors::getColorFor('ItemY', 'SetB');
        $color2 = Colors::getColorFor('ItemX', 'SetB');
        $this->assertEquals($color1, $color2);
    }

    public function test_getColorFor_differentSets()
    {
        $colorA = Colors::getColorFor('ItemY', 'Set1');
        $colorB = Colors::getColorFor('ItemY', 'Set2');
        $this->assertEquals($colorA, $colorB);
    }
}


