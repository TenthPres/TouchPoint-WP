<?php
/**
 * @package TouchPointWP
 */

namespace tp\TouchPointWP;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Exception;
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
			try {
				self::$_dateTimeNow = new DateTimeImmutable('now', wp_timezone());
			} catch (Exception $e) {
			}
		}

		return self::$_dateTimeNow;
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

	private static ?DateTimeImmutable $_dateTimeNow = null;
	private static ?DateTimeImmutable $_dateTimeNowPlus1Y = null;

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
		$names = [
			_x('Sun', 'e.g. event happens weekly on...', 'TouchPoint-WP'),
			_x('Mon', 'e.g. event happens weekly on...', 'TouchPoint-WP'),
			_x('Tue', 'e.g. event happens weekly on...', 'TouchPoint-WP'),
			_x('Wed', 'e.g. event happens weekly on...', 'TouchPoint-WP'),
			_x('Thu', 'e.g. event happens weekly on...', 'TouchPoint-WP'),
			_x('Fri', 'e.g. event happens weekly on...', 'TouchPoint-WP'),
			_x('Sat', 'e.g. event happens weekly on...', 'TouchPoint-WP'),
		];

		return $names[$dayNum % 7];
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
		$timeInt = intval($dt->format('Hi'));

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
	 *
	 * @return string
	 */
	public static function stringArrayToListString(array $strings): string
	{
		$concat = implode('', $strings);

		$comma     = ', ';
		$and       = ' & ';
		$useOxford = false;
		if (strpos($concat, ', ') !== false) {
			$comma     = '; ';
			$useOxford = true;
		}
		if (strpos($concat, ' & ') !== false) {
			$and       = ' ' . __('and', 'TouchPoint-WP') . ' ';
			$useOxford = true;
		}

		$last = array_pop($strings);
		$str  = implode($comma, $strings);
		if (count($strings) > 0) {
			if ($useOxford) {
				$str .= trim($comma);
			}
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
		// TODO add hook for custom color algorithm.

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
			$k     = ($n + $h / 30) % 12;
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

	/**
	 * Updates or removes a post's featured image from a URL (e.g. from TouchPoint).
	 *
	 * If the $newUrl is blank or null, the image is removed.
	 *
	 * @param int         $postId
	 * @param string|null $newUrl
	 * @param string      $title
	 *
	 * @return int|string The attachmentId for the image.  Can be reused for other posts.
	 * @since 0.0.24
	 */
	public static function updatePostImageFromUrl(int $postId, ?string $newUrl, string $title)
	{
		// Required for image handling
		require_once(ABSPATH . 'wp-admin/includes/media.php');
		require_once(ABSPATH . 'wp-admin/includes/file.php');
		require_once(ABSPATH . 'wp-admin/includes/image.php');

		// Post image
		global $wpdb;
		$oldAttId = get_post_thumbnail_id($postId);
		$oldFName = $wpdb->get_var( "SELECT meta_value FROM $wpdb->postmeta WHERE post_id = '$oldAttId' AND meta_key = '_wp_attached_file'" );
		$oldFName = substr($oldFName, strrpos($oldFName, '/') + 1);

		$newUrl = trim((string)$newUrl); // nulls are now ""

		$newFName = "";
		if ($newUrl !== "") {
			$newFName = substr($newUrl, strrpos($newUrl, '/') + 1);
		}

		// Compare image file names without extensions, versions, and increments.
		$newFName = explode(".", $newFName, 2)[0];
		if (strlen($newFName) > 5) {
			$oldFName = substr($oldFName, 0, strlen($newFName));
		}

		$attId = 0;
		try {
			if ($newFName !== $oldFName) {
				if ($oldAttId > 0) { // Remove and delete old one.
					wp_delete_attachment($oldAttId, true);
				}
				if ($newUrl !== "") { // Load and save new one
					$attId = media_sideload_image($newUrl, $postId, $title, 'id');
					set_post_thumbnail($postId, $attId);
				}
			}
		} catch (Exception $e) {
			echo "Exception occurred: " . $e->getMessage();
			wp_delete_attachment($attId, true);
			return 0;
		}
		if (is_wp_error($attId)) {
			echo "Exception occurred: " . $attId->get_error_message();
			return 0;
		}
		return $attId;
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
			if (str_contains($input, "<h$i ") || str_contains($input, "<h$i>")) {
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
	 * @param string  $html The HTML to be standardized.
	 * @param ?string $context A context string to pass to hooks.
	 *
	 * @return string
	 */
	public static function standardizeHtml(string $html, ?string $context = null): string
	{
		// The tp_standardize_html filter would completely replace the pre-defined process.
		$o = apply_filters(TouchPointWP::HOOK_PREFIX . 'standardize_html', $html, $context);
		if ($o !== $html) {
			return $o;
		}

		$html      = apply_filters(TouchPointWP::HOOK_PREFIX . 'pre_standardize_html', $html, $context);
		$maxHeader = intval(apply_filters(TouchPointWP::HOOK_PREFIX . 'standardize_h_tags_max_h', 2, $context));

		$allowedTags = [
			'p', 'br', 'a', 'em', 'strong', 'b', 'i', 'u', 'hr', 'ul', 'ol', 'li',
			'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
			'table', 'tr', 'th', 'td', 'thead', 'tbody', 'tfoot'
		];
		$allowedTags = apply_filters(TouchPointWP::HOOK_PREFIX . 'standardize_allowed_tags', $allowedTags, $context);

		$html = self::standardizeHTags($maxHeader, $html);
		$html = strip_tags($html, $allowedTags);
		$html = trim($html);

		return apply_filters(TouchPointWP::HOOK_PREFIX . 'post_standardize_html', $html, $context);
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
			'slug'          => 'touchpoint-wp',
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
	 * @param string $nickname
	 * @param string $emailAddress
	 * @param ?string $resultComment If a spam provider provides a comment about why content was allowed or rejected,
	 *     it goes here.
	 *
	 * @return bool  True if acceptable, false if not acceptable.
	 */
	public static function validateRegistrantEmailAddress(string $nickname, string $emailAddress, string &$resultComment = null): bool {
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
	 * @param string  $nickname
	 * @param string  $emailAddress
	 * @param string  $message
	 * @param ?string $resultComment If a spam provider provides a comment about why content was allowed or rejected,
	 *     it goes here.
	 *
	 * @return bool  True if acceptable, false if not acceptable.
	 */
	public static function validateMessage(string $nickname, string $emailAddress, string $message, string &$resultComment = null): bool
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