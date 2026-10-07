<?php
/**
 * Unit tests for the color functions of the Utilities class
 * @package TouchPointWP\Tests\Unit\Utilities
 */

namespace tp\TouchPointWP\Tests\Unit\Utilities;

use tp\TouchPointWP\Utilities;
use PHPUnit\Framework\TestCase;

class Colors_Test extends TestCase
{
    public function test_hslToHex_rgb()
    {
        // Test known HSL to HEX conversions
        $this->assertEquals('#ff0000', Utilities::hslToHex(0, 100, 50)); // Red
        $this->assertEquals('#00ff00', Utilities::hslToHex(120, 100, 50)); // Green
        $this->assertEquals('#0000ff', Utilities::hslToHex(240, 100, 50)); // Blue
    }

    public function test_hslToHex_common()
    {
        // Test edge values
        $this->assertEquals('#808080', Utilities::hslToHex(0, 0, 50)); // Gray
        $this->assertEquals('#ffffff', Utilities::hslToHex(0, 0, 100)); // White
        $this->assertEquals('#000000', Utilities::hslToHex(0, 0, 0)); // Black
    }

    public function test_getColorFor_returnsHex()
    {
        $color = Utilities::getColorFor('TestItem', 'TestSet');
        $this->assertMatchesRegularExpression('/^#[0-9a-fA-F]{6}$/', $color);
    }

    public function test_getColorFor_UniquenessWithinSet()
    {
        $color1 = Utilities::getColorFor('Item1', 'SetA');
        $color2 = Utilities::getColorFor('Item2', 'SetA');
        $this->assertNotEquals($color1, $color2);
    }

    public function test_getColorFor_sameColorForSameItem()
    {
        $color1 = Utilities::getColorFor('ItemX', 'SetB');
		Utilities::getColorFor('ItemY', 'SetB');
        $color2 = Utilities::getColorFor('ItemX', 'SetB');
        $this->assertEquals($color1, $color2);
    }

    public function test_getColorFor_differentSets()
    {
        $colorA = Utilities::getColorFor('ItemY', 'Set1');
        $colorB = Utilities::getColorFor('ItemY', 'Set2');
        $this->assertEquals($colorA, $colorB);
    }
}


