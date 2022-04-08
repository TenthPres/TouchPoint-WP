<?php

namespace tp\TouchPointWP;

use DateTimeInterface;

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
            __('Sun'),
            __('Mon'),
            __('Tue'),
            __('Wed'),
            __('Thu'),
            __('Fri'),
            __('Sat'),
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
}