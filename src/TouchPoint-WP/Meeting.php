<?php
/**
 * @package TouchPointWP
 */

namespace tp\TouchPointWP;

if ( ! defined('ABSPATH')) {
	exit(1);
}

if ( ! TOUCHPOINT_COMPOSER_ENABLED) {
	require_once 'api.php';
}

use Exception;
use tp\TouchPointWP\Utilities\Http;

/**
 * Handle meeting content, particularly RSVPs.
 */
abstract class Meeting implements api, module
{
	public const POST_TYPE = TouchPointWP::HOOK_PREFIX . "meeting";
	
	/**
	 * Register scripts and styles to be used on display pages.
	 */
	public static function registerScriptsAndStyles(): void
	{
		$i = TouchPointWP::instance();
		wp_register_script(
			TouchPointWP::SHORTCODE_PREFIX . 'meeting-defer',
			$i->assets_url . 'js/meeting-defer' . $i->script_ext,
			[TouchPointWP::SHORTCODE_PREFIX . 'base-defer', 'wp-i18n'],
			TouchPointWP::VERSION,
			true
		);
		wp_set_script_translations(
			TouchPointWP::SHORTCODE_PREFIX . 'meeting-defer',
			'TouchPoint-WP',
			$i->getJsLocalizationDir()
		);
	}

	/**
	 * Handle API requests
	 *
	 * @param array $uri The request URI already parsed by parse_url()
	 *
	 * @return bool False if endpoint is not found.  Should print the result.
	 */
	public static function api(array $uri): bool
	{
		if (count($uri['path']) === 2) {
			self::ajaxGetMeetingInfo();
			exit;
		}

		switch (strtolower($uri['path'][2])) {
			case "rsvp":
				self::ajaxSubmitRsvps();
				exit;
		}

		return false;
	}

	/**
	 * Print a calendar grid for a given month and year.
	 *
	 * @param WP_Query $q
	 * @param int|null $month
	 * @param int|null $year
	 *
	 * @return void
	 */
	public static function printCalendarGrid(WP_Query $q, int $month = null, int $year = null)
	{
		try {
			// Validate month & year; create $d as a day within the month
			$tz = wp_timezone();
			if ($month < 1 || $month > 12 || $year < 2020 || $year > 2100) {
				$d = new DateTime('now', $tz);
				$d = new DateTime($d->format('Y-m-01'), $tz);
			} else {
				$d = new DateTime("$year-$month-01", $tz);
			}
		} catch (Exception $e) {
			echo "<!-- Could not create calendar grid because an exception occurred. -->";
			return;
		}

		// Get the day of the week for the first day of the month (0 = Sunday, 1 = Monday, ..., 6 = Saturday)
		$offsetDays = intval($d->format('w')); // w: Numeric representation of the day of the week
		$d->modify("-$offsetDays days");

		// Create a table to display the calendar
		echo '<table>'; // TODO 1i18n
		echo '<tr><th>Sun</th><th>Mon</th><th>Tue</th><th>Wed</th><th>Thu</th><th>Fri</th><th>Sat</th></tr>';

		$isMonthBefore = ($offsetDays !== 0);
		$isMonthAfter = false;
		$aDay = new DateInterval("P1D");

		// Loop through the days of the month
		do {
			$cellClass = "";
			if ($isMonthBefore) {
				$cellClass = "before";
			} elseif ($isMonthAfter) {
				$cellClass = "after";
			}

			$day = $d->format("j");
			$wd =  $d->format("w");

			if ($wd === '0') {
				echo "<tr>";
			}

			// Print the cell
			echo "<td class=\"$cellClass\">";
			echo "<span class=\"calDay\">$day</span>";
			// TODO print items
			echo "</td>";

			if ($wd === '6') {
				echo "</tr>";
			}

			// Increment days
			$mo1 = $d->format('n');
			$d->add($aDay);
			$mo2 = $d->format('n');

			if ($mo1 !== $mo2) {
				if ($isMonthBefore) {
					$isMonthBefore = false;
				} else {
					$isMonthAfter = true;
				}
			}
		} while (!$isMonthAfter || $d->format('w') !== '0');
		echo '</table>';
	}

	/**
	 * @param $opts
	 *
	 * @return object
	 * @throws TouchPointWP_Exception
	 */
	private static function getMeetingInfo($opts): object
	{
		// TODO caching

		return TouchPointWP::instance()->apiPost('mtg', $opts);
	}

	/**
	 * Handles the API call to get meetings, mostly to prep RSVP links.
	 */
	private static function ajaxGetMeetingInfo(): void
	{
		header('Content-Type: application/json');

		if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
			http_response_code(Http::METHOD_NOT_ALLOWED);
			echo json_encode([
				                 'error'      => 'Only GET requests are allowed.',
				                 'error_i18n' => __("Only GET requests are allowed.", 'TouchPoint-WP')
			                 ]);
			exit;
		}

		try {
			$data = self::getMeetingInfo($_GET);
		} catch (TouchPointWP_Exception $ex) {
			http_response_code(Http::SERVER_ERROR);
			echo json_encode(['error' => $ex->getMessage()]);
			exit;
		}

		echo json_encode(['success' => $data->success]);
		exit;
	}

	/**
	 * Handles RSVP Submissions
	 */
	private static function ajaxSubmitRsvps(): void
	{
		header('Content-Type: application/json');

		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			http_response_code(Http::METHOD_NOT_ALLOWED);
			echo json_encode([
				                 'error'      => 'Only POST requests are allowed.',
				                 'error_i18n' => __("Only POST requests are allowed.", 'TouchPoint-WP')
			                 ]);
			exit;
		}

		$inputData = file_get_contents('php://input');
		if ($inputData[0] !== '{') {
			http_response_code(Http::BAD_REQUEST);
			echo json_encode([
				                 'error'      => 'Invalid data provided.',
				                 'error_i18n' => __("Invalid data provided.", 'TouchPoint-WP')
			                 ]);
			exit;
		}

		try {
			$data = TouchPointWP::instance()->apiPost('mtg_rsvp', json_decode($inputData));
		} catch (Exception $ex) {
			http_response_code(Http::SERVER_ERROR);
			echo json_encode(['error' => $ex->getMessage()]);
			exit;
		}

		echo json_encode(['success' => $data->success]);
		exit;
	}
}