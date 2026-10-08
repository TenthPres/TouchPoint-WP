<?php
/**
 * @package TouchPointWP
 */

namespace tp\TouchPointWP;

use DateInterval;
use DOMDocument;
use DOMElement;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use WP_Post_Type;

/**
 * A few tools for managing things.
 */
abstract class Utilities
{
	public const PLUGIN_UPDATE_TRANSIENT = TouchPointWP::SETTINGS_PREFIX . "plugin_update_data";
	public const PLUGIN_UPDATE_TRANSIENT_TTL = 43200; // 12 hours

	/**
	 * @param mixed    $numeric
	 * @param bool|int $round False to skip rounding. Otherwise, precision passed to round().
	 *
	 * @return float|null
	 * @see round()
	 *
	 */
	public static function toFloatOrNull($numeric, $round = false): ?float
	{
		if ( ! is_numeric($numeric)) {
			return null;
		}

		if ($round === false) {
			return (float)$numeric;
		} else {
			return round($numeric, $round);
		}
	}

	/**
	 * @return DateTimeImmutable
	 */
	public static function dateTimeNow(): DateTimeImmutable
	{
		if (self::$_dateTimeNow === null) {
			self::$_dateTimeNow = current_datetime();
		}

		return self::$_dateTimeNow;
	}

	/**
	 * @return DateTimeImmutable
	 */
	public static function dateTimeTodayAtMidnight(): DateTimeImmutable
	{
		if (self::$_dateTimeTodayAtMidnight === null) {
			self::$_dateTimeTodayAtMidnight = self::dateTimeNow()->setTime(0, 0);
		}
		return self::$_dateTimeTodayAtMidnight;
	}

	/**
	 * @return DateTimeImmutable
	 */
	public static function dateTimeNowPlus1Y(): DateTimeImmutable
	{
		if (self::$_dateTimeNowPlus1Y === null) {
			$aYear                    = new DateInterval('P1Y');
			self::$_dateTimeNowPlus1Y = self::dateTimeNow()->add($aYear);
		}

		return self::$_dateTimeNowPlus1Y;
	}

	/**
	 * @return DateTimeImmutable
	 */
	public static function dateTimeNowPlus90D(): DateTimeImmutable
	{
		if (self::$_dateTimeNowPlus90D === null) {
			$someDays                  = new DateInterval('P90D');
			self::$_dateTimeNowPlus90D = self::dateTimeNow()->add($someDays);
		}

		return self::$_dateTimeNowPlus90D;
	}

	/**
	 * @return DateTimeImmutable
	 */
	public static function dateTimeNowPlus1D(): DateTimeImmutable
	{
		if (self::$_dateTimeNowPlus1D === null) {
			$aDay                     = new DateInterval('P1D');
			self::$_dateTimeNowPlus1D = self::dateTimeNow()->add($aDay);
		}

		return self::$_dateTimeNowPlus1D;
	}

	/**
	 * @return DateTimeImmutable
	 */
	public static function dateTimeNowMinus1D(): DateTimeImmutable
	{
		if (self::$_dateTimeNowMinus1D === null) {
			$aDay                     = new DateInterval('P1D');
			$aDay->invert = 1;
			self::$_dateTimeNowMinus1D = self::dateTimeNow()->add($aDay);
		}

		return self::$_dateTimeNowMinus1D;
	}

	/**
	 * @return DateTimeZone
	 */
	public static function utcTimeZone(): DateTimeZone
	{
		if (self::$_utcTimeZone === null) {
			self::$_utcTimeZone = new DateTimeZone('UTC');
		}

		return self::$_utcTimeZone;
	}

	private static ?DateTimeImmutable $_dateTimeNow = null;
	private static ?DateTimeImmutable $_dateTimeTodayAtMidnight = null;
	private static ?DateTimeImmutable $_dateTimeNowPlus1Y = null;
	private static ?DateTimeImmutable $_dateTimeNowPlus90D = null;
	private static ?DateTimeImmutable $_dateTimeNowPlus1D = null;
	private static ?DateTimeImmutable $_dateTimeNowMinus1D = null;
	private static ?DateTimeZone $_utcTimeZone = null;

	/**
	 * Gets the plural form of a weekday name.
	 *
	 * @param int $dayNum
	 *
	 * @return string Plural weekday (e.g. Mondays)
	 */
	public static function getPluralDayOfWeekNameForNumber(int $dayNum): string
	{
		$names = [
			_x('Sundays', 'e.g. event happens weekly on...', 'TouchPoint-WP'),
			_x('Mondays', 'e.g. event happens weekly on...', 'TouchPoint-WP'),
			_x('Tuesdays', 'e.g. event happens weekly on...', 'TouchPoint-WP'),
			_x('Wednesdays', 'e.g. event happens weekly on...', 'TouchPoint-WP'),
			_x('Thursdays', 'e.g. event happens weekly on...', 'TouchPoint-WP'),
			_x('Fridays', 'e.g. event happens weekly on...', 'TouchPoint-WP'),
			_x('Saturdays', 'e.g. event happens weekly on...', 'TouchPoint-WP'),
		];

		return $names[$dayNum % 7];
	}

	/**
	 * Gets the plural form of a weekday name, but without translation for use in places like slugs.
	 *
	 * @param int $dayNum
	 *
	 * @return string Plural weekday (e.g. Mondays)
	 */
	public static function getPluralDayOfWeekNameForNumber_noI18n(int $dayNum): string
	{
		$names = [
			'Sundays',
			'Mondays',
			'Tuesdays',
			'Wednesdays',
			'Thursdays',
			'Fridays',
			'Saturdays',
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
		return self::getDaysOfWeekShort()[$dayNum % 7];
	}

	/**
	 * Get the short form of the day of week, translated.  Assumed to be usable as both singular and plural.
	 *
	 * @return string[]
	 */
	public static function getDaysOfWeekShort(): array
	{
		return [
			_x('Sun', 'e.g. "Event happens weekly on..." or "This ..."', 'TouchPoint-WP'),
			_x('Mon', 'e.g. "Event happens weekly on..." or "This ..."', 'TouchPoint-WP'),
			_x('Tue', 'e.g. "Event happens weekly on..." or "This ..."', 'TouchPoint-WP'),
			_x('Wed', 'e.g. "Event happens weekly on..." or "This ..."', 'TouchPoint-WP'),
			_x('Thu', 'e.g. "Event happens weekly on..." or "This ..."', 'TouchPoint-WP'),
			_x('Fri', 'e.g. "Event happens weekly on..." or "This ..."', 'TouchPoint-WP'),
			_x('Sat', 'e.g. "Event happens weekly on..." or "This ..."', 'TouchPoint-WP'),
		];
	}

	/**
	 * NOT internationalized, such as for slugs
	 *
	 * @param int $dayNum
	 *
	 * @return string
	 */
	public static function getDayOfWeekShortForNumber_noI18n(int $dayNum): string
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
	 * @param bool              $i18n
	 *
	 * @return string
	 */
	public static function getTimeOfDayTermForTime(DateTimeInterface $dt, bool $i18n = true): string
	{
		$timeInt = intval($dt->format('Gi'));

		if ($timeInt < 300 || $timeInt >= 2200) {
			return $i18n ? _x('Late Night', 'Time of Day', 'TouchPoint-WP') : "Late Night";
		} elseif ($timeInt < 800) {
			return $i18n ? _x('Early Morning', 'Time of Day', 'TouchPoint-WP') : "Early Morning";
		} elseif ($timeInt < 1115) {
			return $i18n ? _x('Morning', 'Time of Day', 'TouchPoint-WP') : "Morning";
		} elseif ($timeInt < 1300) {
			return $i18n ? _x('Midday', 'Time of Day', 'TouchPoint-WP') : "Midday";
		} elseif ($timeInt < 1700) {
			return $i18n ? _x('Afternoon', 'Time of Day', 'TouchPoint-WP') : "Afternoon";
		} elseif ($timeInt < 2015) {
			return $i18n ? _x('Evening', 'Time of Day', 'TouchPoint-WP') : "Evening";
		} else {
			return $i18n ? _x('Night', 'Time of Day', 'TouchPoint-WP') : "Night";
		}
	}

	public static function getTimeOfDayTermForTime_noI18n(DateTimeInterface $dt): string
	{
		return self::getTimeOfDayTermForTime($dt, false);
	}

	/**
	 * Join an array of strings into a properly-formatted (English-style) list. Uses commas and ampersands by default.
	 * This will switch to written "and" when an ampersand is present in a string, and will use semi-colons instead of
	 * commas when commas are already present.
	 *
	 * Turn ['apples', 'oranges', 'pears'] into "apples, oranges & pears"
	 *
	 * @param string[] $strings
	 * @param int      $limit The maximum number of items to include.  Default is very, very high.
	 * @param bool     $andOthers If true, the last item will be "others" instead of the actual last item.
	 *
	 * @return string
	 */
	public static function stringArrayToListString(array $strings, int $limit = PHP_INT_MAX, bool $andOthers = false): string
	{
		if ($limit < count($strings)) {
			$andOthers = true;
			$strings = array_slice($strings, 0, $limit);
		}

		$concat = implode('', $strings);

		$comma     = ', ';
		$and       = ' & ';
		$useOxford = false;
		if (str_contains($concat, ', ')) {
			$comma     = '; ';
		}
		if (str_contains($concat, ' & ')) {
			$and       = ' ' . __('and', 'TouchPoint-WP') . ' ';
			$useOxford = true;
		}

		if ($andOthers) {
			$last = _x("others", "list of items, and *others*", "TouchPoint-WP");
		} else {
			$last = array_pop($strings);
		}
		$str  = implode($comma, $strings);
		if ((count($strings) + $andOthers) > 0) {
			if ($useOxford) {
				$str .= trim($comma);
			}
			$str .= $and;
		}
		$str .= $last;

		return $str;
	}

	/**
	 * Convert a list (string or array) to an int array.  Strips out non-numerics and explodes.  Items that have no
	 * digits in them, such as the empty ones in "1,,2", are left out.
	 *
	 * @param array|string $r
	 * @param bool         $explode If false, the IDs are returned as a comma-separated string instead.
	 *
	 * @return int[]|string
	 */
	public static function idArrayToIntArray(array|string $r, bool $explode = true): array|string
	{
		if (is_array($r)) {
			$r = implode(",", $r);
		}

		$ids = [];
		foreach (explode(",", $r) as $item) {
			$digits = preg_replace('/[^0-9]+/', '', $item);
			if ($digits !== "") {
				$ids[] = intval($digits);
			}
		}

		if ($explode) {
			return $ids;
		}

		return implode(",", $ids);
	}

	/**
	 * Gets the post content for all posts that contain a particular shortcode.
	 *
	 * @param $shortcode
	 *
	 * TODO MULTI: does not update for all sites in the network.
	 *
	 * @return object[]
	 */
	public static function getPostContentWithShortcode($shortcode): array
	{
		global $wpdb;

		/** @noinspection SqlResolve */
		return $wpdb->get_results("SELECT post_content FROM $wpdb->posts WHERE post_content LIKE '%$shortcode%' AND post_status <> 'inherit'");
	}

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
		 * @since 0.0.90 Added
		 *
		 * @param ?string $current The current value.  Null is provided to the function because the color hasn't otherwise been determined yet.
		 * @param string $itemName The name of the current item.
		 * @param string $setName The name of the set to which the item belongs.
		 *
		 * @return ?string The color in hex, starting with '#'.  Null to defer to the default color assignment.
		 */
		$r = apply_filters('tp_custom_color_function', $current, $itemName, $setName);
		if ($r !== null)
			return $r;

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
		 * @since 0.0.90 Added
		 *
		 * @param string[] $array The array of colors in hex format strings, starting with '#'.
		 * @param string $setName The name of the set for which the colors are needed.
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
			$k     = round($n + $h / 30) % 12;
			$color = $l - $a * max(min($k - 3, 9 - $k, 1), -1);

			return round(255 * $color);
		};

		return "#" .
			   str_pad(dechex($f(0)), 2, 0, STR_PAD_LEFT) .
			   str_pad(dechex($f(8)), 2, 0, STR_PAD_LEFT) .
			   str_pad(dechex($f(4)), 2, 0, STR_PAD_LEFT);
	}

	/**
	 * Get the registered post types as a Key-Value array.  Excludes post types that start with 'tp_'.
	 *
	 * @return string[]
	 */
	public static function getRegisteredPostTypesAsKVArray(): array{
		global $wp_post_types;
		$r = [];
		$strLen = strlen(TouchPointWP::HOOK_PREFIX);
		foreach ($wp_post_types as $key => $object) {
			/** @var $object WP_Post_Type */

			if (substr($key, 0, 3) === 'wp_' ||
				substr($key, 0, $strLen) === TouchPointWP::HOOK_PREFIX ||
				$object->show_ui === false) {
				continue;
			}
			$r[$key] = $object->label;
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
		mt_srand(intval(microtime(true) * 10000) % PHP_INT_MAX);
		$char   = strtoupper(md5(uniqid(rand(), true)));
		$hyphen = chr(45); // "-"

		return substr($char, 0, 8) . $hyphen
			   . substr($char, 8, 4) . $hyphen
			   . substr($char, 12, 4) . $hyphen
			   . substr($char, 16, 4) . $hyphen
			   . substr($char, 20, 12);
	}

	/**
	 * Do a var_dump, but within a container that can be expanded or contracted.
	 *
	 * @param ...$args
	 *
	 * @return void
	 */
	public static function var_dump_expandable(...$args): void
	{
		echo "<div>";
		echo "<div style=\"display:none;\">";
		var_dump(...$args);
		echo "</div>";
		echo "<a onclick=\"this.parentElement.firstElementChild.style.display = 'block'; this.style.display = 'none';\">" . __("Expand", "TouchPoint-WP") . "</a>";
		echo "</div>";
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

	/**
	 * Get the attachment ID of a post's own featured image, ignoring any image inherited from an ancestor.
	 *
	 * Sync code should use this, not get_post_thumbnail_id(), which returns an inherited image for involvement and
	 * meeting posts.  Otherwise, a child could be taken to own (and then delete or replace) its parent's image.
	 *
	 * @param int $postId
	 *
	 * @return int 0 if the post has no image of its own.
	 *
	 * @since 0.0.98 Added
	 */
	public static function ownThumbnailId(int $postId): int
	{
		return intval(get_post_meta($postId, '_thumbnail_id', true));
	}

	/**
	 * Updates or removes a post's featured image from a URL (e.g. from TouchPoint).
	 *
	 * If the $newUrl is blank or null, the image is removed.
	 *
	 * The image that's replaced is deleted from the media library right away, unless an array is provided for
	 * $replacedAttIds.  Then, it's left in place, and its attachment ID is added to the array, so the caller can use
	 * deleteAttachmentIfUnused() once other posts using it have been updated.
	 *
	 * @param int         $postId
	 * @param string|null $newUrl
	 * @param string      $title
	 * @param bool        $verbose
	 * @param array|null  $replacedAttIds Provide an array to defer deleting the replaced image.
	 *
	 * @return int The attachmentId for the image.  Can be reused for other posts.
	 * @since 0.0.24 Added
	 * @since 0.0.98 Added $replacedAttIds
	 */
	public static function updatePostImageFromUrl(
		int $postId,
		?string $newUrl,
		string $title,
		bool $verbose = false,
		?array &$replacedAttIds = null
	): int {
		// Required for image handling
		require_once(ABSPATH . 'wp-admin/includes/media.php');
		require_once(ABSPATH . 'wp-admin/includes/file.php');
		require_once(ABSPATH . 'wp-admin/includes/image.php');

		// some standardization
		global $wpdb;
		$newUrl = trim((string)$newUrl); // nulls are now ""
		$title = sprintf('%1$s Image', $title);

		$newAttId = 0;

		// check if target image already exists in media library
		if ($newUrl !== "") {
			$newAttId = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT p.Id FROM $wpdb->posts p JOIN $wpdb->postmeta pm ON p.ID = pm.post_id WHERE post_type = 'attachment' AND meta_key = '_source_url' AND meta_value = %s",
					$newUrl
				)
			);
			$newAttId = (int)$newAttId;

			if ($verbose) {
				echo "<p>Existing Attachment ID with matching URL: $newAttId</p>";
			}
		}

		// get existing post image, if any
		$oldAttId = self::ownThumbnailId($postId);

		// determine if a change is needed
		if ($newAttId !== $oldAttId || ($newUrl !== "" && $oldAttId === 0)) {
			if ($oldAttId > 0) { // Remove and delete old one.
				if ($replacedAttIds === null) {
					wp_delete_attachment($oldAttId, true);
				} else {
					$replacedAttIds[] = $oldAttId;
				}
			}
			if ($newAttId === 0 && $newUrl !== "") { // New image isn't in media yet.
				set_time_limit(60);
				$newAttId = media_sideload_image($newUrl, $postId, $title, 'id');

				if (is_wp_error($newAttId)) {
					$newAttId->add('', "Error encountered while trying to import image: $newUrl");
					new TouchPointWP_WPError($newAttId);
					if ($verbose)
						echo "Error occurred: " . $newAttId->get_error_message();
					return 0;

				}
			}
			if ($newAttId > 0) { // New image is in media.
				set_post_thumbnail($postId, $newAttId);
			} else {
				// If the image is blank, remove the post thumbnail.
				if ($verbose) {
					echo "<p>Image URL is blank.  Removing post thumbnail.</p>";
				}
				delete_post_thumbnail($postId);
			}
		}

		return $newAttId;
	}

	/**
	 * Delete an image from the media library, unless a post still uses it as its featured image.  Archived meetings keep
	 * the image they had, so an image that was replaced is only deleted when nothing is holding on to it.
	 *
	 * @param int  $attachmentId
	 * @param bool $verbose
	 *
	 * @return bool True if the image was deleted.
	 *
	 * @since 0.0.98 Added
	 */
	public static function deleteAttachmentIfUnused(int $attachmentId, bool $verbose = false): bool
	{
		global $wpdb;

		$usedBy = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_thumbnail_id' AND meta_value = %s LIMIT 1",
				(string)$attachmentId
			)
		);

		if ($usedBy !== null) {
			if ($verbose) {
				echo "<p>Image $attachmentId was replaced, but is still used by Post $usedBy.  It will be kept.</p>";
			}
			return false;
		}

		return ! ! wp_delete_attachment($attachmentId, true);
	}

	/**
	 * @param int    $maxAllowed 1 to 6, corresponding to h1 to h6.
	 * @param string $input The string within which headings should be standardized.
	 *
	 * @return string
	 */
	public static function standardizeHTags(int $maxAllowed, string $input): string
	{
		$maxAllowed = min(max($maxAllowed, 1), 6);

		$deltas  = [0, 0, 0, 0, 0, 0];
		$indexes = [0, 0, 0, 0, 0, 0];
		$o       = 0;
		$i       = 1;
		for (; $i <= 6;) {
			$deltas[$i - 1] = 0;
			if (stripos($input, "<h$i ") !== false || stripos($input, "<h$i>") !== false) {
				$deltas[$i - 1]  = $maxAllowed - $i + $o;
				$indexes[$i - 1] = $deltas[$i - 1] * $i;
				$o++;
			}
			$i++;
		}

		arsort($indexes);

		foreach ($indexes as $ix => $x) {
			$delta = $deltas[$ix];
			if ($delta === 0) {
				continue;
			}

			$i = $ix + 1;
			$o = $i + $delta;

			if ($o < 7) {
				$input = str_ireplace(["<h$i ", "<h$i>", "</h$i>"],
									  ["<h$o ", "<h$o>", "</h$o>"],
									  $input);
			} else {
				$input = str_ireplace(["<h$i ", "<h$i>", "</h$i>"],
									  ["<p><strong ", "<p><strong>", "</strong></p>"],
									  $input);
			}
		}

		return $input;
	}

	/**
	 * Standardize HTML, typically as it is imported from TouchPoint, so that it is safe to publish and is rendered
	 * consistently within your theme.  The steps, in order, are:
	 *
	 *  1. Elements that shouldn't be shown or run, like script and style, are removed along with their content.
	 *  2. Attributes that could run code, like onclick, are removed, as are addresses (the href of a link, the src of an
	 *     image, etc.) that have a protocol that isn't allowed, like "javascript:".
	 *  3. Tags that aren't allowed, like section and font, are removed.  Their content is kept.
	 *  4. Headings are shifted so that the highest heading is at the highest level allowed (h2, by default), and
	 *     the headings below it keep their relationships.
	 *  5. Whitespace at the start and end is removed.
	 *
	 * Steps 1 and 2 read the HTML and write it out again, so the formatting of what's left may change a little: for
	 * example, tag names become lower case, entities like &nbsp; become characters, and addresses in links are
	 * percent-encoded.
	 *
	 * Each step can be adjusted with filters, and the whole process can be replaced with the `tp_standardize_html`
	 * filter.
	 *
	 * @param ?string $html    The HTML to be standardized.
	 * @param ?string $context A context string to pass to hooks.
	 *
	 * @return string
	 */
	public static function standardizeHtml(?string $html, ?string $context = null): string
	{
		if ($html === null) {
			$html = "";
		}

		/**
		 * Allows for the standardization of HTML content, typically during the import from TouchPoint.  If this
		 * filter is used, the default filtering will be bypassed. Use other filters for more precise control.
		 *
		 * @since 0.0.34 Added
		 *
		 * @param string $html The HTML to be standardized.
		 * @param string $context A context string to pass to hooks.
		 *
		 * @return string The standardized HTML.
		 */
		$o = apply_filters('tp_standardize_html', $html, $context);
		if ($o !== $html) {
			return $o;
		}

		/**
		 * Make any adjustments to HTML content before the rest of the standardization process happens.
		 *
		 * @since 0.0.25 Added
		 *
		 * @param string $html The HTML to be standardized.
		 * @param string $context A context string to pass to hooks.
		 *
		 * @return string The standardized HTML.
		 */
		$html      = apply_filters('tp_pre_standardize_html', $html, $context);


        $stripContentTags = [
            'script', 'style', 'iframe', 'object', 'embed', 'head'
        ];

		/**
		 * The tags that will be removed *with their content* in the HTML standardization process.  Default is script,
		 * style, iframe, object, embed, and head: tags whose content could run code, load something unexpected, or
		 * show up as text on the page.  These are removed even if the tag is also allowed by `tp_standardize_allowed_tags`.
		 *
		 * @since 0.2.2 Added
		 *
		 * @param string[] $stripContentTags The tags to be removed with their content from the HTML.
		 * @param string   $context A context string to pass to hooks.
		 *
		 * @return string[] The tags to be removed with their content from the HTML.
		 */
		$stripContentTags = apply_filters('tp_standardize_strip_content_tags', $stripContentTags, $context);

		/**
		 * The protocols that are allowed in the addresses within the HTML attributes, such as the href of a link.  The
		 * default is a set of common protocols that can't run code.  Addresses without a protocol, such as "/about" or
		 * "#top", are always allowed.  Attributes with other protocols are removed during the HTML standardization process.
		 *
		 * @since 0.2.2 Added
		 *
		 * @param string[] $allowedProtocols The allowed protocols, without colons. (e.g. "https")
		 * @param string   $context A context string to pass to hooks.
		 *
		 * @return string[] The allowed protocols.
		 */
		$allowedProtocols = apply_filters('tp_standardize_allowed_protocols', ['http', 'https', 'mailto', 'tel'], $context);

		$html = self::removeUnsafeMarkup($html, $stripContentTags, $allowedProtocols);


        $allowedTags = [
            'p', 'br', 'a', 'em', 'strong', 'b', 'i', 'u', 'hr', 'ul', 'ol', 'li',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'table', 'tr', 'th', 'td', 'thead', 'tbody', 'tfoot',
            'img', 'figure', 'figcaption', 'blockquote', 'code', 'pre', 'span', 'div'
        ];

		/**
		 * The allowed tags in the HTML standardization process.  Default is a set of common tags: paragraphs, line breaks,
		 * links, emphasis, lists, headings, tables, images, figures and captions, block quotes, code, and the generic
		 * containers div and span.  Tags that aren't listed, such as section and font, are removed, but their content is
		 * kept.  The intent is to provide a simple subset of HTML that will be rendered consistently within your theme.
		 *
		 * Attributes that could run code, and addresses with protocols that aren't allowed (see
		 * `tp_standardize_allowed_protocols`), are removed from allowed tags.  Tags in `tp_standardize_strip_content_tags`
		 * are removed along with their content, even if they're allowed here.
		 *
		 * @since 0.0.25 Added
		 * @since 0.2.2 Changed the default to also allow img, figure, figcaption, blockquote, code, pre, span, and div.
		 *
		 * @param string[] $allowedTags The allowed tags in the HTML.  (Names only, without angle brackets.)
		 * @param string   $context A context string to pass to hooks.
		 *
		 * @return string[] The allowed tags in the HTML.
		 */
		$allowedTags = apply_filters('tp_standardize_allowed_tags', $allowedTags, $context);

        $html = strip_tags($html, $allowedTags);

        
        $maxHeader = 2;

		/**
		 * The maximum header level to allow in an HTML string.  Default is 2.
		 *
		 * @since 0.0.25 Added
		 *
		 * @param int    $maxHeader The highest header level (lowest number) to allow in the HTML. (e.g. 2 for <h2> tags)
		 * @param string $context A context string to pass to hooks.
		 *
		 * @return int The maximum header level to allow in the HTML.
		 */
		$maxHeader = intval(apply_filters('tp_standardize_h_tags_max_h', $maxHeader, $context));

        $html = self::standardizeHTags($maxHeader, $html);

        $html = trim($html);

		/**
		 * Make any adjustments to HTML content after the rest of the standardization process happens.
		 *
		 * @since 0.0.25 Added
		 *
		 * @param string $html The HTML to be standardized.
		 * @param string $context A context string to pass to hooks.
		 *
		 * @return string The standardized HTML.
		 */
		return apply_filters('tp_post_standardize_html', $html, $context);
	}

	/**
	 * Attributes whose values are addresses, and so could be used to run code or load a document.
	 *
	 * @var string[]
	 */
	private const URL_ATTRIBUTES = [
		'href', 'src', 'srcset', 'action', 'formaction', 'xlink:href', 'poster', 'background', 'cite', 'data',
		'longdesc', 'usemap', 'manifest', 'ping', 'codebase', 'classid', 'profile', 'icon', 'dynsrc', 'lowsrc',
	];

	/**
	 * Remove markup that could run code or load something unexpected from HTML: some kinds of elements, along with
	 * everything inside them, and any attributes that could run code, such as onclick, or that have an address that
	 * could, such as a link to "javascript:".  Everything else is kept.
	 *
	 * The HTML is read the way a browser reads it (by a parser, not by pattern matching), so quoted text, comments, and
	 * attribute values that look like tags are treated as what they are.  An element that is never closed takes the rest
	 * of its parent with it.
	 *
	 * What's left is written out again from what the parser read, so it may be formatted a little differently than it
	 * was: for example, tag names are lower case, entities like &nbsp; become characters, and addresses in links are
	 * percent-encoded.  Text that can't be read as HTML at all is removed.
	 *
	 * @param string   $html              The HTML to clean.
	 * @param string[] $stripTags         The names of the elements to remove, without angle brackets.  Case doesn't matter.
	 * @param string[] $allowedProtocols  The protocols (such as "https") that addresses are allowed to have.  Addresses
	 *                                    without a protocol, like "/about" or "#top", are always allowed.
	 *
	 * @return string
	 */
	private static function removeUnsafeMarkup(string $html, array $stripTags, array $allowedProtocols): string
	{
		if ($html === '') {
			return $html;
		}

		// The parser stops reading at a null character, which would drop everything after it.
		$html = str_replace(chr(0), '', $html);

		// The declaration makes the parser read the text as UTF-8.  Without the body, loose text would be put in a paragraph.
		$previousErrorSetting = libxml_use_internal_errors(true);
		$doc                  = new DOMDocument();
		$loaded               = $doc->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_NONET);
		libxml_clear_errors();
		libxml_use_internal_errors($previousErrorSetting);

		$body = $loaded ? $doc->getElementsByTagName('body')->item(0) : null;
		if ($body === null) {
			return '';
		}

		foreach ($stripTags as $tag) {
			if ( ! is_string($tag) || $tag === '') {
				continue;
			}

			// A copy of the list, because the live one changes as elements are removed.
			foreach (iterator_to_array($doc->getElementsByTagName($tag), false) as $element) {
				$element->parentNode?->removeChild($element);
			}
		}

		$allowedProtocols = array_map('strtolower', array_filter($allowedProtocols, 'is_string'));
		foreach (iterator_to_array($body->getElementsByTagName('*'), false) as $element) {
			self::removeUnsafeAttributes($element, $allowedProtocols);
		}

		$out = '';
		foreach ($body->childNodes as $child) {
			$out .= $doc->saveHTML($child);
		}

		return $out;
	}

	/**
	 * Remove the attributes of an element that could run code: event handlers (onclick, onerror, ...), anything that
	 * isn't a plausible attribute name (a browser could read it as something else), and addresses that have a protocol
	 * that isn't allowed.
	 *
	 * @param DOMElement $element
	 * @param string[]   $allowedProtocols Lower case.
	 *
	 * @return void
	 */
	private static function removeUnsafeAttributes(DOMElement $element, array $allowedProtocols): void
	{
		$unsafe = [];
		foreach ($element->attributes as $attribute) {
			$name = strtolower($attribute->name);

			if (preg_match('/^[a-z][a-z0-9_.:-]*$/', $name) !== 1
				|| str_starts_with($name, 'on')
				|| $name === 'srcdoc'
				|| (in_array($name, self::URL_ATTRIBUTES, true) && ! self::addressesAreSafe($attribute->value, $allowedProtocols, $name === 'srcset'))) {
				$unsafe[] = $attribute;
			}
		}

		foreach ($unsafe as $attribute) {
			$element->removeAttributeNode($attribute);
		}
	}

	/**
	 * Determine whether an address (or, for a list of images, all of the addresses) has no protocol or an allowed one.
	 *
	 * @param string   $value
	 * @param string[] $allowedProtocols Lower case.
	 * @param bool     $isList           True if the value is a list of addresses with sizes, like the srcset of an image.
	 *
	 * @return bool
	 */
	private static function addressesAreSafe(string $value, array $allowedProtocols, bool $isList = false): bool
	{
		foreach ($isList ? explode(',', $value) : [$value] as $candidate) {
			// Browsers ignore whitespace and control characters anywhere in a protocol, like "java\tscript:".
			$address = $isList ? (string)strtok(trim($candidate), " \t\r\n\f") : $candidate;
			$address = preg_replace('/[\x00-\x20\x7f]+/', '', $address);

			if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $address, $protocol) === 1
				&& ! in_array(strtolower($protocol[1]), $allowedProtocols, true)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Sometimes WordPress tries to be smarter than we want it to be.
	 *
	 * THIS DOES NOT DO ANY KIND OF VALIDATION OR REPLACEMENT.  It is assumed that the input is already completely ready
	 * to go and won't cause collisions.
	 *
	 * @param $postId
	 * @param $newSlug
	 *
	 * @return void
	 */
	public static function forceSlugUpdate($postId, $newSlug): void
	{
		global $wpdb;
		$wpdb->update($wpdb->posts, ['post_name' => $newSlug], ['ID' => $postId]);
	}

	/**
	 * Convert a string to something that's acceptable for use as a slug.
	 *
	 * @param string $s
	 *
	 * @return string
	 */
	public static function stringToSlug(string $s): string
	{
		// split $s to only include anything before punctuation
		$pos = strcspn($s, ".,:;!|?");
		if ($pos > 0) {
			$s = substr($s, 0, $pos);
		}
		$s = preg_replace("/[^a-zA-Z0-9]/", "-", $s);
        $s = trim($s, " \t\n\r\0\x0B-_");
		$s = strtolower($s);
		return preg_replace("/-+/", "-", $s);
	}

	/**
	 * Remove a prefix from the start of a title, such as a parent event's title from the titles of its parts.  For
	 * example, "Global Outreach Conference: Q&A Luncheon" becomes "Q&A Luncheon" when the prefix is "Global Outreach
	 * Conference".
	 *
	 * The prefix is only removed if it's followed by a separator (a colon, bar, middle dot, bullet, en dash, em dash,
	 * or a hyphen with a space beside it), so "Christmas Eve Service" isn't shortened to "Eve Service" by "Christmas".  Case,
	 * and whether quotation marks and apostrophes are straight or curly, don't matter.
	 *
	 * @param string $title  The full title.
	 * @param string $prefix The prefix to remove.
	 *
	 * @return string The title without the prefix and separator.  If the title doesn't start with them, or nothing
	 *                would be left, the title is returned as it was (trimmed).
	 *
	 * @since 0.0.98 Added
	 */
	public static function titleWithoutPrefix(string $title, string $prefix): string
	{
		$title  = trim($title);
		$prefix = trim($prefix);

		$length = mb_strlen($prefix);
		if ($length === 0 || mb_strlen($title) <= $length) {
			return $title;
		}

		$normalize = fn(string $s): string => mb_strtolower(strtr($s, ["’" => "'", "‘" => "'", "“" => '"', "”" => '"']));
		if ($normalize(mb_substr($title, 0, $length)) !== $normalize($prefix)) {
			return $title;
		}

		$rest = mb_substr($title, $length);
		if ( ! preg_match('/^(?:\s*[:|·•–—]\s*|\s+-\s*|\s*-\s+)/u', $rest, $separator)) {
			return $title;
		}

		$rest = mb_substr($rest, mb_strlen($separator[0]));
		if ( ! preg_match('/[\p{L}\p{N}]/u', $rest)) {
			return $title;
		}

		return $rest;
	}

	/**
	 * Returns true if a new release is available.
	 *
	 * @return ?object
	 */
	public static function checkForUpdate(): ?object
	{
		$ghData = wp_remote_get("https://api.github.com/repos/tenthpres/touchpoint-wp/releases/latest", [
			'headers' => ['Accept' => 'application/json']
		]);
		if (is_wp_error($ghData)) {
			return null;
		}
		$ghData = json_decode(wp_remote_retrieve_body($ghData));

		if ( ! property_exists($ghData, 'tag_name')) {
			return null;
		}

		$tag = $ghData->tag_name;

		if ($tag == null) {
			return null;
		}

		if ($tag[0] !== "v") {
			return null;
		}

		$newV = substr($tag, 1);

		$initialHeaders = self::fileHeadersFromString(file_get_contents(__DIR__ . "/../../touchpoint-wp.php"), [
			'Requires at least' => null,
			'Requires PHP'      => null,
			'Tested up to'      => null
		]);

		$newDetails = self::fileHeadersFromWeb( "https://raw.githubusercontent.com/TenthPres/TouchPoint-WP/v$newV/touchpoint-wp.php", $initialHeaders);

		if ($newDetails === null) {
			$newDetails = self::fileHeadersFromWeb( "https://raw.githubusercontent.com/TenthPres/TouchPoint-WP/v$newV/TouchPoint-WP.php", $initialHeaders);
		}

		return (object)[
			'id'            => 'touchpoint-wp/touchpoint-wp.php',
			'slug'          => TouchPointWP::SLUG,
			'plugin'        => 'touchpoint-wp/touchpoint-wp.php',
			'new_version'   => $newV,
			'url'           => 'https://github.com/TenthPres/TouchPoint-WP/',
			'package'       => "https://github.com/TenthPres/TouchPoint-WP/releases/download/v$newV/touchpoint-wp.zip",
			'icons'         => [],
			'banners'       => [],
			'banners_rtl'   => [],
			'tested'        => $newDetails == null ? "" : $newDetails['Tested up to'],
			'requires_php'  => $newDetails == null ? "" : $newDetails['Requires PHP'],
			'requires'      => $newDetails == null ? "" : $newDetails['Requires at least'],
			'compatibility' => (object)[],
		];
	}


	public static function checkForUpdate_transient($transient)
	{
		$pluginTransient = get_transient(self::PLUGIN_UPDATE_TRANSIENT);

		$up = $pluginTransient ?: self::checkForUpdate();

		if ( ! $pluginTransient) {
			if ($up == null) {
				$up = "error";
			}
			set_transient(self::PLUGIN_UPDATE_TRANSIENT, $up, self::PLUGIN_UPDATE_TRANSIENT_TTL);
		}

		if (is_object($up) && is_object($transient)) {
			if (version_compare($up->new_version, TouchPointWP::VERSION, ">")) {
				$transient->response['touchpoint-wp/touchpoint-wp.php'] = $up;
			} else {
				$transient->no_update['touchpoint-wp/touchpoint-wp.php'] = $up;
			}
		}

		return $transient;
	}

	public static function fileHeadersFromWeb(string $url, array $headers = []): ?array
	{
		$data = wp_remote_get($url);
		if (is_wp_error($data)) {
			return null;
		}
		$data = wp_remote_retrieve_body($data);

		return self::fileHeadersFromString($data, $headers);
	}

	public static function fileHeadersFromString(string $data, array $headers = []): ?array
	{
		$data = explode("\n", $data);
		$keys = array_keys($headers);
		foreach ($data as $line) {
			$line = explode(":", $line, 2);
			if (count($line) < 2) {
				continue;
			}

			if (in_array($line[0], $keys)) {
				$headers[$line[0]] = trim($line[1]);
			}
		}

		return $headers;
	}

	protected static ?string $_clientIp = null;

	/**
	 * Get the IP address of the client making the request, as best as it can be determined.  Headers that proxies add to
	 * forward the client's address are used if they're present, but a request can include those headers with any value
	 * its sender chooses, so the result is only suitable for approximate purposes, like geolocation.  It must not be
	 * used for authentication or access control.
	 *
	 * @return ?string The address, or null if there isn't a valid one.
	 */
	public static function getClientIp(): ?string
	{
		if (self::$_clientIp === null) {
			$ipHeaderKeys = [
				'HTTP_CLIENT_IP',
				'HTTP_X_FORWARDED_FOR',
				'HTTP_X_FORWARDED',
				'HTTP_FORWARDED_FOR',
				'HTTP_FORWARDED',
				'REMOTE_ADDR'
			];

			foreach ($ipHeaderKeys as $k) {
				if ( ! empty($_SERVER[$k]) && filter_var($_SERVER[$k], FILTER_VALIDATE_IP)) {
					self::$_clientIp = $_SERVER[$k];
					break;
				}
			}
		}

		return self::$_clientIp;
	}

	/**
	 * Determine if an email address or user should be accepted as a new registrant. (e.g. informal auth)
	 *
	 * @param ?string  $nickname
	 * @param ?string $emailAddress
	 * @param ?string $resultComment If a spam provider provides a comment about why content was allowed or rejected,
	 *     it goes here.
	 *
	 * @return bool  True if acceptable, false if not acceptable.
	 */
	public static function validateRegistrantEmailAddress(?string $nickname, ?string $emailAddress, ?string &$resultComment = null): bool {
		// CleanTalk filter
		if (file_exists(ABSPATH . '/wp-content/plugins/cleantalk-spam-protect/cleantalk.php')
			|| function_exists('ct_test_registration')) {

			if ( ! function_exists('ct_test_registration')) {
				include_once(ABSPATH . '/wp-content/plugins/cleantalk-spam-protect/cleantalk.php');
			}
			if (function_exists('ct_test_registration')) {
				$res = ct_test_registration($nickname, $emailAddress, self::getClientIp());
				if ($resultComment !== null) {
					$resultComment = $res['comment'];
				}
				if ($res['allow'] < 1) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Determine if an email address or user should be accepted as a new registrant. (e.g. informal auth)
	 *
	 * @param ?string  $nickname
	 * @param ?string  $emailAddress
	 * @param ?string  $message
	 * @param ?string  $resultComment If a spam provider provides a comment about why content was allowed or rejected,
	 *     it goes here.
	 *
	 * @return bool  True if acceptable, false if not acceptable.
	 */
	public static function validateMessage(?string $nickname, ?string $emailAddress, ?string $message, ?string &$resultComment = null): bool
	{
		// CleanTalk filter
		if (file_exists(ABSPATH . '/wp-content/plugins/cleantalk-spam-protect/cleantalk.php')
			|| function_exists('ct_test_message')) {

			if ( ! function_exists('ct_test_message')) {
				include_once(ABSPATH . '/wp-content/plugins/cleantalk-spam-protect/cleantalk.php');
			}
			if (function_exists('ct_test_message')) {
				$res = ct_test_message($nickname, $emailAddress, self::getClientIp(), $message);
				if ($resultComment !== null) {
					$resultComment = $res['comment'];
				}

				if ($res['allow'] < 1) {
					return false;
				}
			}
		}
		return true;
	}
}