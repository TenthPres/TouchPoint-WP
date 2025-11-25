<?php
/**
 * @package TouchPointWP
 */

namespace tp\TouchPointWP\Utilities;

/**
 * Used to manage colors
 */
abstract class Colors
{
	protected static array $colorAssignments = [];

	/**
	 * Arbitrarily pick a unique-ish color for a value.
	 *
	 * @param string $itemName The name of the item.  e.g. PA
	 * @param string $setName The name of the set to which the item belongs, within which there should be uniqueness.
	 *     e.g. States
	 *
	 * @return string The color in hex, starting with '#'.
	 */
	public static function getColorFor(string $itemName, string $setName): string
	{
		$current = null;

		/**
		 * Allows for a custom color function to assign a color for a given value.
		 *
		 * @param ?string $current The current value.  Null is provided to the function because the color hasn't otherwise been determined yet.
		 * @param string  $itemName The name of the current item.
		 * @param string  $setName The name of the set to which the item belongs.
		 *
		 * @return ?string The color in hex, starting with '#'.  Null to defer to the default color assignment.
		 * @since 0.0.90 Added
		 *
		 */
		$r = apply_filters('tp_custom_color_function', $current, $itemName, $setName);
		if ($r !== null) {
			return $r;
		}

		// If the set is new...
		if ( ! isset(self::$colorAssignments[$setName])) {
			self::$colorAssignments[$setName] = [];
		}

		// Find position in set...
		$idx = array_search($itemName, self::$colorAssignments[$setName], true);

		// If not in set...
		if ($idx === false) {
			$idx                                = count(self::$colorAssignments[$setName]);
			self::$colorAssignments[$setName][] = $itemName;
		}

		$array = [];

		/**
		 * Allows for a custom color set to be used for color assignment to match branding. This filter should return an
		 * array of colors in hex format, starting with '#'.  The colors will be assigned in order, but it is not
		 * deterministic which color will be assigned to which item.  If it needs to be, use the `tp_custom_color_function`
		 * filter instead.
		 *
		 * @param string[] $array The array of colors in hex format strings, starting with '#'.
		 * @param string   $setName The name of the set for which the colors are needed.
		 *
		 * @since 0.0.90 Added
		 *
		 */
		$colorSet = apply_filters('tp_custom_color_set', $array, $setName);

		if (count($colorSet) > 0) {
			return $colorSet[$idx % count($colorSet)];
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

		$f = function ($n) use ($h, $l, $a) {
			$k     = intval($n + $h / 30) % 12;
			$color = $l - $a * max(min($k - 3, 9 - $k, 1), -1);

			return round(255 * $color);
		};

		return "#" .
		       str_pad(dechex($f(0)), 2, 0, STR_PAD_LEFT) .
		       str_pad(dechex($f(8)), 2, 0, STR_PAD_LEFT) .
		       str_pad(dechex($f(4)), 2, 0, STR_PAD_LEFT);
	}
}