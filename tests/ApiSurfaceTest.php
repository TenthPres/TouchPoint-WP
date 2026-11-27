<?php

namespace tp\TouchPointWP\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Fails when the plugin's public API changes, so changes are deliberate (and versioned/documented) rather than
 * accidental.  See tests/README.md.
 */
class ApiSurfaceTest extends TestCase
{
    private const HINT = "\nIf this change is intentional, update docs, then run `composer update-api-snapshot` and commit the result.";

    private static array $expected;
    private static array $actual;

    public static function setUpBeforeClass(): void
    {
        self::$expected = self::loadSnapshot();
        self::$actual   = json_decode(ApiSurface::encode(ApiSurface::build()), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function loadSnapshot(): array
    {
        return json_decode(file_get_contents(ApiSurface::SNAPSHOT_PATH), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testNoClassesRemovedOrAdded(): void
    {
        $exp = array_keys(self::$expected['classes']);
        $act = array_keys(self::$actual['classes']);
        $this->assertSame([], array_values(array_diff($exp, $act)), 'Classes/interfaces/traits were removed or renamed.' . self::HINT);
        $this->assertSame([], array_values(array_diff($act, $exp)), 'Classes/interfaces/traits were added.' . self::HINT);
    }

    /**
     * @dataProvider classProvider
     */
    public function testClassSurfaceUnchanged(string $class): void
    {
        $this->assertArrayHasKey($class, self::$actual['classes'], "$class no longer exists." . self::HINT);
        $this->assertEquals(
            self::$expected['classes'][$class],
            self::$actual['classes'][$class],
            "Public API of $class changed." . self::HINT
        );
    }

    public static function classProvider(): array
    {
        $out = [];
        foreach (array_keys(self::loadSnapshot()['classes']) as $class) {
            $out[$class] = [$class];
        }
        return $out;
    }

    public function testHooksUnchanged(): void
    {
        $this->assertSame(self::$expected['hooks'], self::$actual['hooks'], 'Filters/actions provided by the plugin changed.' . self::HINT);
    }

    public function testOverridableTemplatesUnchanged(): void
    {
        $this->assertSame(self::$expected['templates'], self::$actual['templates'], 'Overridable template files changed.' . self::HINT);
    }
}
