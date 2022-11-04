<?php
/**
 * @package TouchPointWP
 */
namespace tp\TouchPointWP;

use DateTimeInterface;
use WP_Error;

/**
 * A few tools for managing things.
 */
abstract class Utilities
{
    /**
     * @param numeric $numeric
     *
     * @return float|null
     */
    public static function toFloatOrNull($numeric): ?float
    {
        if (is_numeric($numeric)) {
            return (float)$numeric;
        }

        return null;
    }

    /**
     * Gets the plural form of a weekday name.
     *
     * Translation: These are deliberately not scoped to TouchPoint-WP, so if the translation exists globally, it should
     * work here.
     *
     * @param int $dayNum
     *
     * @return string Plural weekday (e.g. Mondays)
     */
    public static function getPluralDayOfWeekNameForNumber(int $dayNum): string
    {
        $names = [
            __('Sundays'),
            __('Mondays'),
            __('Tuesdays'),
            __('Wednesdays'),
            __('Thursdays'),
            __('Fridays'),
            __('Saturdays'),
        ];

        return $names[$dayNum % 7];
    }

    /**
     * @param int $dayNum
     *
     * @return string
     */
    public static function getDayOfWeekShortForNumber(int $dayNum): string
    {
        $names = [
            'Sun',
            'Mon',
            'Tue',
            'Wed',
            'Thu',
            'Fri',
            'Sat',
        ];

        return $names[$dayNum % 7];
    }

    /**
     * Gets the non-specific time of day in words.
     *
     * Translation: These are deliberately not scoped to TouchPoint-WP, so if the translation exists globally, it should
     * work here.
     *
     * @param DateTimeInterface $dt
     *
     * @return string
     */
    public static function getTimeOfDayTermForTime(DateTimeInterface $dt): string
    {
        $timeInt = intval($dt->format('Hi'));

        if ($timeInt < 300 || $timeInt >= 2200) {
            return __('Late Night');
        } elseif ($timeInt < 800) {
            return __('Early Morning');
        } elseif ($timeInt < 1115) {
            return __('Morning');
        } elseif ($timeInt < 1300) {
            return __('Midday');
        } elseif ($timeInt < 1700) {
            return __('Afternoon');
        } elseif ($timeInt < 2015) {
            return __('Evening');
        } else {
            return __('Night');
        }
    }

    /**
     * Join an array of strings into a properly-formatted (English-style) list. Uses commas and ampersands by default.
     * This will switch to written "and" when an ampersand is present in a string, and will use semi-colons instead of
     * commas when commas are already present.
     *
     * Turn ['apples', 'oranges', 'pears'] into "apples, oranges & pears"
     *
     * @param string[] $strings
     *
     * @return string
     */
    public static function stringArrayToListString(array $strings): string
    {
        $concat = implode('', $strings);

        $comma = ', ';
        $and = ' & ';
        $useOxford = false;
        if (strpos($concat, ', ') !== false) {
            $comma     = '; ';
            $useOxford = true;
        }
        if (strpos($concat, ' & ') !== false) {
            $and = ' ' . __('and') . ' ';
            $useOxford = true;
        }

        $last = array_pop($strings);
        $str = implode($comma, $strings);
        if (count($strings) > 0) {
            if ($useOxford)
                $str .= trim($comma);
            $str .= $and;
        }
        $str .= $last;
        return $str;
    }

    /**
     * Convert a list (string or array) to an int array.  Strips out non-numerics and explodes.
     *
     * @param string|array $r
     *
     * @return int[]|string
     */
    public static function idArrayToIntArray($r, $explode = true)
    {
        if (is_array($r)) {
            $r = implode(",", $r);
        }

        $r = preg_replace('/[^0-9,]+/', '', $r);

        if ($explode) {
            return json_decode("[" . $r . "]");
        }
        return $r;
    }

    /**
     * Gets the post content for all posts that contain a particular shortcode.
     *
     * @param $shortcode
     *
     * @return object[]
     */
    public static function getPostContentWithShortcode($shortcode): array // TODO MULTI: does not update for all sites in the network.
    {
        global $wpdb;
        /** @noinspection SqlResolve */
        return $wpdb->get_results("SELECT post_content FROM $wpdb->posts WHERE post_content LIKE '%$shortcode%' AND post_status <> 'inherit'");
    }

    protected static array $colorAssignments = [];

    /**
     * Arbitrarily pick a unique-ish color for a value.
     *
     * @param string $itemName  The name of the item.  e.g. PA
     * @param string $setName   The name of the set to which the item belongs, within which there should be uniqueness. e.g. States
     *
     * @return string The color in hex, starting with '#'.
     */
    public static function getColorFor(string $itemName, string $setName): string
    {
        // TODO add hook for custom color algorithm.

        // If the set is new...
        if (!isset(self::$colorAssignments[$setName])) {
            self::$colorAssignments[$setName] = [];
        }

        // Find position in set...
        $idx = array_search($itemName, self::$colorAssignments[$setName], true);

        // If not in set...
        if ($idx === false) {
            $idx = count(self::$colorAssignments[$setName]);
            self::$colorAssignments[$setName][] = $itemName;
        }

        // Calc color! (This method generates 24 colors and then repeats. (8 hues * 3 lums)
        $h = ($idx * 135) % 360;
        $l = ((($idx >> 3) + 1) * 25) % 75 + 25;

        return self::hslToHex($h, 70, $l);
    }

    /**
     * Convert HSL color to RGB Color
     *
     * @param int $h Hue (0-365)
     * @param int $s Saturation (0-100)
     * @param int $l Luminosity (0-100)
     *
     * @return string
     *
     * @cite Adapted from https://stackoverflow.com/a/44134328/2339939
     * @license CC BY-SA 4.0
     */
    public static function hslToHex(int $h, int $s, int $l): string
    {
        $l /= 100;
        $a = $s * min($l, 1 - $l) / 100;

        $f = function($n) use ($h, $l, $a) {
            $k = ($n + $h / 30) % 12;
            $color = $l - $a * max(min($k - 3, 9 - $k, 1), -1);
            return round(255 * $color);
        };

        return "#" .
            str_pad(dechex($f(0)), 2, 0, STR_PAD_LEFT) .
            str_pad(dechex($f(8)), 2, 0, STR_PAD_LEFT) .
            str_pad(dechex($f(4)), 2, 0, STR_PAD_LEFT);
    }

    /**
     * Wrapper for the WordPress term_exists function to reduce database calls
     *
     * @param int|string $term     The term to check. Accepts term ID, slug, or name.
     * @param string     $taxonomy Optional. The taxonomy name to use.
     * @param int|null   $parent   Optional. ID of parent term under which to confine the exists search.
     *
     * @return mixed Returns null if the term does not exist.
     *               Returns the term ID if no taxonomy is specified and the term ID exists.
     *               Returns an array of the term ID and the term taxonomy ID if the taxonomy is specified and the pairing exists.
     *               Returns 0 if term ID 0 is passed to the function.
     *
     * @see term_exists()
     */
    public static function termExists($term, string $taxonomy = "", ?int $parent = null)
    {
        $key = $term . "|" . $taxonomy . "|" . $parent;
        if (!array_key_exists($key, self::$termExistsCache)) {
            self::$termExistsCache[$key] = term_exists($term, $taxonomy, $parent);
        }
        return self::$termExistsCache[$key];
    }
    private static array $termExistsCache = [];


    /**
     * Wrapper for the WordPress wp_insert_term function to reduce database calls
     *
     * Add a new term to the database.
     *
     * A non-existent term is inserted in the following sequence:
     * 1. The term is added to the term table, then related to the taxonomy.
     * 2. If everything is correct, several actions are fired.
     * 3. The 'term_id_filter' is evaluated.
     * 4. The term cache is cleaned.
     * 5. Several more actions are fired.
     * 6. An array is returned containing the `term_id` and `term_taxonomy_id`.
     *
     * If the 'slug' argument is not empty, then it is checked to see if the term
     * is invalid. If it is not a valid, existing term, it is added and the term_id
     * is given.
     *
     * If the taxonomy is hierarchical, and the 'parent' argument is not empty,
     * the term is inserted and the term_id will be given.
     *
     * Error handling:
     * If `$taxonomy` does not exist or `$term` is empty,
     * a WP_Error object will be returned.
     *
     * If the term already exists on the same hierarchical level,
     * or the term slug and name are not unique, a WP_Error object will be returned.
     *
     * @param string       $term     The term name to add.
     * @param string       $taxonomy The taxonomy to which to add the term.
     * @param array|string $args {
     *     Optional. Array or query string of arguments for inserting a term.
     *
     *     @type string    $alias_of    Slug of the term to make this term an alias of.
     *                               Default empty string. Accepts a term slug.
     *     @type string    $description The term description. Default empty string.
     *     @type int       $parent      The id of the parent term. Default 0.
     *     @type string    $slug        The term slug to use. Default empty string.
     * }
     * @return array|WP_Error {
     *     An array of the new term data, WP_Error otherwise.
     *
     *     @type int        $term_id          The new term ID.
     *     @type int|string $term_taxonomy_id The new term taxonomy ID. Can be a numeric string.
     * }
     *
     * @see wp_insert_term()
     */
    public static function insertTerm(string $term, string $taxonomy, $args = [])
    {
        $parent = $args['parent'] ?? null;
        $key = $term . "|" . $taxonomy . "|" . $parent;
        $r = wp_insert_term($term, $taxonomy, $args);
        if (! is_wp_error($r)) {
            self::$termExistsCache[$key] = $r;
        }
        return $r;
    }

    /**
     * Generates a Microsoft-friendly globally unique identifier (Guid).
     *
     * @return string A new random globally unique identifier.
     */
    public static function createGuid(): string
    {
        mt_srand(( double )microtime() * 10000);
        $char   = strtoupper(md5(uniqid(rand(), true)));
        $hyphen = chr(45); // "-"

        return substr($char, 0, 8) . $hyphen
               . substr($char, 8, 4) . $hyphen
               . substr($char, 12, 4) . $hyphen
               . substr($char, 16, 4) . $hyphen
               . substr($char, 20, 12);
    }

    /**
     * Get all HTTP request headers.
     *
     * @return array
     */
    public static function getAllHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        return $headers;
    }
}