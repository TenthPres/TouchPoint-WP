<?php
/**
 * Base test case for TouchPoint-WP tests
 *
 * @package TouchPointWP\Tests
 */

namespace tp\TouchPointWP\Tests;

use ReflectionMethod;
use ReflectionProperty;
use tp\TouchPointWP\Meeting_GroupingSettings;
use tp\TouchPointWP\Utilities;
use Yoast\PHPUnitPolyfills\TestCases\TestCase as PolyfillsTestCase;

/**
 * Base test case class that all TouchPoint-WP tests should extend.
 * Uses Yoast PHPUnit Polyfills for compatibility with multiple PHPUnit versions.
 *
 * Before and after every test, the state that tests can change is put back: filters, options, the current time, the time
 * zone, what Utilities has cached, and the Meeting Grouping settings.
 */
abstract class TestCase extends PolyfillsTestCase
{
    /**
     * The static properties in which Utilities keeps the current time and the client's IP address.
     */
    private const UTILITIES_CACHES = [
        '_dateTimeNow',
        '_dateTimeTodayAtMidnight',
        '_dateTimeNowPlus1Y',
        '_dateTimeNowPlus90D',
        '_dateTimeNowPlus1D',
        '_dateTimeNowMinus1D',
        '_utcTimeZone',
        '_clientIp',
    ];

    /**
     * Set up before each test.
     */
    protected function set_up(): void
    {
        parent::set_up();

        $this->resetEnvironment();
    }

    /**
     * Tear down after each test.
     */
    protected function tear_down(): void
    {
        $this->resetEnvironment();

        parent::tear_down();
    }

    /**
     * Put back everything a test might have changed.
     */
    private function resetEnvironment(): void
    {
        global $_wp_filters;
        $_wp_filters = [];

        $GLOBALS['_wp_options']   = [];
        $GLOBALS['_wp_usernames'] = [];
        unset($GLOBALS['_wp_now']);
        $this->resetUtilitiesCaches();

        self::setStatic(Meeting_GroupingSettings::class, '_loaded', false);
    }

    /**
     * Make Utilities work out the current time again, and look for the client's IP address again.
     */
    private function resetUtilitiesCaches(): void
    {
        foreach (self::UTILITIES_CACHES as $property) {
            self::setStatic(Utilities::class, $property, null);
        }
    }

    /**
     * Set a WordPress option, as get_option() will return it.  WordPress's defaults apply to the date and time formats.
     *
     * @param string $name
     * @param mixed  $value
     */
    protected function setOption(string $name, mixed $value): void
    {
        $GLOBALS['_wp_options'][$name] = $value;
    }

    /**
     * Set the site's time zone.  It's UTC otherwise.
     *
     * @param string $timezone A time zone name, such as "America/New_York".
     */
    protected function setTimezone(string $timezone): void
    {
        $this->setOption('timezone_string', $timezone);
        $this->resetUtilitiesCaches();
    }

    /**
     * Set the current time.  It's 2025-11-12 21:00 UTC otherwise.
     *
     * @param string  $dateTime Anything DateTime understands, in the site's time zone.
     * @param ?string $timezone Set the site's time zone too, such as "America/New_York".
     */
    protected function setNow(string $dateTime, ?string $timezone = null): void
    {
        if ($timezone !== null) {
            $this->setOption('timezone_string', $timezone);
        }
        $GLOBALS['_wp_now'] = $dateTime;
        $this->resetUtilitiesCaches();
    }

    /**
     * Set which usernames are already taken, as username_exists() will report.
     *
     * @param string[] $usernames
     */
    protected function setTakenUsernames(array $usernames): void
    {
        $GLOBALS['_wp_usernames'] = $usernames;
    }

    /**
     * Provide the Meeting Grouping settings, without them being read from WordPress's settings.
     *
     * @param array $settings The settings as they're stored: 'types' (a list of per-type rules, each with an
     *                        'invTypeId'), 'otherTypes' (the rule for all other types), and 'legacyAvailable'.  A rule
     *                        has 'includeChildren', 'editions', and 'clusters'.  See Meeting_GroupingSettings.
     */
    protected function useGroupingSettings(array $settings): void
    {
        $settings += ['types' => [], 'otherTypes' => [], 'legacyAvailable' => false];

        self::callStatic(Meeting_GroupingSettings::class, 'applyData', json_decode(json_encode($settings)));
        self::setStatic(Meeting_GroupingSettings::class, '_loaded', true);
    }

    /**
     * Set a static property, even if it's protected or private.
     *
     * @param string $class
     * @param string $property
     * @param mixed  $value
     */
    protected static function setStatic(string $class, string $property, mixed $value): void
    {
        $reflection = new ReflectionProperty($class, $property);
        if (PHP_VERSION_ID < 80100) {
            $reflection->setAccessible(true);
        }
        $reflection->setValue(null, $value);
    }

    /**
     * Call a static method, even if it's protected or private.
     *
     * @param string $class
     * @param string $method
     * @param mixed  ...$arguments
     *
     * @return mixed
     */
    protected static function callStatic(string $class, string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod($class, $method);
        if (PHP_VERSION_ID < 80100) {
            $reflection->setAccessible(true);
        }
        return $reflection->invokeArgs(null, $arguments);
    }
}
