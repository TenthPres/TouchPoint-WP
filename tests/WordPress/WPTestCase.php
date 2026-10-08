<?php
/**
 * Base test case for the tests that run within WordPress
 *
 * @package TouchPointWP\Tests\WordPress
 */

namespace tp\TouchPointWP\Tests\WordPress;

use DateInterval;
use DateTimeImmutable;
use tp\TouchPointWP\Involvement;
use tp\TouchPointWP\Involvement_PostTypeSettings;
use tp\TouchPointWP\Meeting_GroupingSettings;
use tp\TouchPointWP\Taxonomies;
use tp\TouchPointWP\Tests\Support\MeetingFixtures;
use tp\TouchPointWP\Tests\Support\ReflectionHelpers;
use tp\TouchPointWP\TouchPointWP;
use tp\TouchPointWP\Utilities;
use WP_UnitTestCase;

/**
 * Base test case class for tests that need WordPress: its database, its options, its posts and terms, and its hooks.
 *
 * WordPress's test library puts the database back after each test (it runs each test in a transaction), so options,
 * posts, terms, and users that a test makes are gone afterward.  The plugin's own static caches aren't, so they're reset
 * before and after every test here.
 */
abstract class WPTestCase extends WP_UnitTestCase
{
    use MeetingFixtures;
    use ReflectionHelpers;

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

    public function set_up(): void
    {
        parent::set_up();

        $this->resetPluginState();
    }

    public function tear_down(): void
    {
        $this->resetPluginState();

        parent::tear_down();
    }

    /**
     * Put back what the plugin remembers between calls.
     */
    private function resetPluginState(): void
    {
        foreach (self::UTILITIES_CACHES as $property) {
            self::setStatic(Utilities::class, $property, null);
        }

        self::setStatic(Meeting_GroupingSettings::class, '_loaded', false);
        self::setStatic(Involvement_PostTypeSettings::class, 'settings', []);
        self::setStatic(Taxonomies::class, 'termExistsCache', []);
        self::setStatic(TouchPointWP::class, 'divisionTerms', []);
    }

    /**
     * Set one of the plugin's settings, as the settings page would have stored it.
     *
     * @param string $name  The setting, without the prefix.  For example, "mc_archive_days".
     * @param mixed  $value
     */
    protected function setSetting(string $name, mixed $value): void
    {
        update_option(TouchPointWP::SETTINGS_PREFIX . $name, $value);
    }

    /**
     * Store Meeting Grouping settings, as the settings page would have, and make the plugin read them again.
     *
     * @param array $settings The settings as they're stored: 'types' (a list of per-type rules, each with an
     *                        'invTypeId'), 'otherTypes' (the rule for all other types), 'legacyAvailable', and
     *                        'keepHiddenChildren'.
     */
    protected function saveGroupingSettings(array $settings): void
    {
        $this->setSetting('mc_grouping_json', json_encode($settings));
        self::setStatic(Meeting_GroupingSettings::class, '_loaded', false);
    }

    /**
     * Set the current time, as far as the plugin is concerned, and the point before which meetings are archived.
     *
     * @param string $dateTime Anything DateTime understands, in the site's time zone (UTC, unless a test changes it).
     */
    protected function setNow(string $dateTime): void
    {
        foreach (self::UTILITIES_CACHES as $property) {
            self::setStatic(Utilities::class, $property, null);
        }
        $now = new DateTimeImmutable($dateTime, wp_timezone());
        self::setStatic(Utilities::class, '_dateTimeNow', $now);

        $archiveDays = intval(TouchPointWP::instance()->settings->mc_archive_days);
        self::setStatic(Involvement::class, '_updateExpiry', $now->sub(new DateInterval("P{$archiveDays}D")));
    }

    /**
     * A post's slug as it's stored in the database, which WordPress's caching can't make out of date.
     *
     * @param int $postId
     *
     * @return string
     */
    protected function storedSlug(int $postId): string
    {
        global $wpdb;

        return (string)$wpdb->get_var($wpdb->prepare("SELECT post_name FROM $wpdb->posts WHERE ID = %d", $postId));
    }
}
