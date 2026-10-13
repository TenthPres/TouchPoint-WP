<?php
/**
 * Tests for the cleanup of report content
 *
 * @package TouchPointWP\Tests\Unit
 */

namespace tp\TouchPointWP\Tests\Unit;

use tp\TouchPointWP\Report;
use tp\TouchPointWP\Tests\TestCase;

/**
 * Test case for Report::cleanupSqlContent(), which removes the final table row of the content a report returns.
 *
 * @covers \tp\TouchPointWP\Report
 */
class Report_Test extends TestCase
{
    private static function clean(string $content): string
    {
        return self::callStatic(Report::class, 'cleanupSqlContent', $content);
    }

    public function test_cleanupSqlContent_removesTheLastRow(): void
    {
        $content = '<table><tr><td>Header</td></tr><tr><td>1</td></tr><tr class="x"><td>Footer</td></tr></table>';

        $this->assertSame('<table><tr><td>Header</td></tr><tr><td>1</td></tr></table>', self::clean($content));
    }

    public function test_cleanupSqlContent_aTableWithOneRowIsEmptied(): void
    {
        $this->assertSame('<table></table>', self::clean('<table><tr><td>Only</td></tr></table>'));
    }

    public function test_cleanupSqlContent_contentAfterTheLastRowIsKept(): void
    {
        $this->assertSame('<table></table><p>After</p>', self::clean('<table><tr><td>Only</td></tr></table><p>After</p>'));
    }

    public function test_cleanupSqlContent_withoutRowsIsUnchanged(): void
    {
        $this->assertSame('<p>No table</p>', self::clean('<p>No table</p>'));
        $this->assertSame('', self::clean(''));
    }

    public function test_cleanupSqlContent_withoutACompleteRowIsUnchanged(): void
    {
        $this->assertSame('<table><tr><td>Open</td></table>', self::clean('<table><tr><td>Open</td></table>'));
        $this->assertSame('<table></tr></table>', self::clean('<table></tr></table>'));
    }
}
