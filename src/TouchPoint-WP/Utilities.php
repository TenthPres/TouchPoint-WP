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
    public static function getPostContentWithShortcode($shortcode): array
    {
        global $wpdb;
        /** @noinspection SqlResolve */
        return $wpdb->get_results("SELECT post_content FROM $wpdb->posts WHERE post_content LIKE '%$shortcode%'");
    }
}