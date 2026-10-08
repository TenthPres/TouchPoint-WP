<?php
/**
 * Smoke tests: does the plugin load within WordPress?
 *
 * @package TouchPointWP\Tests\WordPress
 */

namespace tp\TouchPointWP\Tests\WordPress;

use tp\TouchPointWP\TouchPointWP;
use WP_UnitTestCase;

class Plugin_Test extends WP_UnitTestCase
{
    public function test_theMainClassIsLoaded(): void
    {
        $this->assertTrue(class_exists(TouchPointWP::class));
        $this->assertInstanceOf(TouchPointWP::class, TouchPointWP::instance());
    }
}
