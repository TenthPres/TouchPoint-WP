<?php
/**
 * @package TouchPointWP
 */

namespace tp\TouchPointWP;

use DateInterval;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use WP_Query;

if ( ! defined('ABSPATH')) {
	exit(1);
}

if ( ! TOUCHPOINT_COMPOSER_ENABLED) {
	require_once 'api.php';
}

/**
 *
 * Creates a listing of events, typically arranged in a monthly grid, but transformable to a list.
 *
 * @package TouchPointWP
 */
class CalendarGrid {
	public ?DateTimeImmutable $next = null;
	public ?DateTimeImmutable $prev = null;

	public string $html;


	/**
	 * Create a calendar grid for a given month and year.
	 *
	 * @param WP_Query $q
	 * @param int|null $month
	 * @param int|null $year
	 */
	public function __construct(WP_Query $q, int $month = null, int $year = null)
	{
		try {
			// Validate month & year; create $d as a day within the month
			$tz = wp_timezone();
			$month = intval($month);
			$year  = intval($year);
			if ($month < 1 || $month > 12 || $year < 2020 || $year > 2100) {
				$d = new DateTime('now', $tz);
				$d = new DateTime($d->format('Y-m-01'), $tz);
			} else {
				$d = new DateTime("$year-$month-01", $tz);
			}
		} catch (Exception $e) {
			$this->html = "<!-- Could not create calendar grid because an exception occurred: {$e->getMessage()} -->";
			return;
		}

		$firstDayOfMonth = DateTimeImmutable::createFromMutable($d);
		$lastDayOfMonth = DateTimeImmutable::createFromMutable($d);

		// Get the day of the week for the first day of the month (0 = Sunday, 1 = Monday, ..., 6 = Saturday)
		$offsetDays = intval($d->format('w')); // w: Numeric representation of the day of the week
		$d->modify("-$offsetDays days");
		$r = "";

		// Create a table to display the calendar
		$r .= '<div class="calGrid">';
		foreach (Utilities::getDaysOfWeekShort() as $dayStr) {
			$r .= "<div class='calWeekdayHead'>$dayStr</div>";
		}

		$isMonthBefore = ($offsetDays !== 0);
		$isMonthAfter = false;
		$aDay = new DateInterval("P1D");
		$dateFormat = get_option('date_format');

		// Loop through the days of the month
		do {
			$day = $d->format("j");
			$fullDay = $d->format($dateFormat);
			$wd =  $d->format("w");

			try {
				$newQ = self::adjustQueryForDay($q, $d, $tz);

				$cellClass = ["calDay"];
				if ($isMonthBefore) {
					$cellClass[] = "before";
				} elseif ($isMonthAfter) {
					$cellClass[] = "after";
				}

				$posts = $newQ->get_posts();

				if (count($posts) === 0) {
					$cellClass[] = "empty";
				}

				if ($d < Utilities::dateTimeTodayAtMidnight()) {
					$cellClass[] = "past";
				}

				$cellClass[] = "weekday-$wd";

				$cellClass = implode(" ", $cellClass);

				// Print the cell
				$r .= "<div class=\"$cellClass\">";
				$r .= "<h3 class=\"calDayHead\">$fullDay</h3>";
				$r .= "<span class=\"calDayNum\">$day</span>";

				foreach ($posts as $e) {
					$m = Meeting::fromPost($e);

					$link = $m->permalink();

					$classes = "event ";
					$classes .= $m->status() . " ";
					$classes .= $m->tense();
					if ($m->isFeatured()) {
						$classes .= " feat";
					}
					if ($m->startDt < $d) {
						$classes .= " notFirstDay";
					}
					$ts = $m->startTimeString();
					$r .= "<a href=\"$link\" class=\"$classes\"><span class=\"time\">$ts</span> <span class=\"title\">$e->post_title</span></a>";
				}

				$r .= "</div>";

			} catch (Exception $e) {
				$r .= "<!-- An Exception occurred: {$e->getMessage()} -->";
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
			} else {
				if (!$isMonthAfter && $day > 27) {
					$lastDayOfMonth = DateTimeImmutable::createFromMutable($d);
				}
			}
		} while (!$isMonthAfter || $d->format('w') !== '0');
		$r .= '</div>';

		$this->html = $r;

		$this->next = $lastDayOfMonth->add($aDay);
		$this->prev = $firstDayOfMonth->sub($aDay);
	}

	/**
	 * Render the grid as HTML.
	 *
	 * @return string
	 */
	public function __toString(): string
	{
		return $this->html;
	}

	/**
	 * Adjust a WP_Query object to filter only to events that overlap with the given day.
	 *
	 * @param WP_Query          $q  The original query object.
	 * @param DateTimeInterface $d  The day to filter down to.  Only events on this day will be included.
	 * @param DateTimeZone      $tz The timezone to use.
	 *
	 * @return WP_Query
	 * @throws Exception
	 */
	private static function adjustQueryForDay(WP_Query $q, DateTimeInterface $d, DateTimeZone $tz): WP_Query
	{
		$q = clone $q;

		$dStart = new DateTime($d->format('Y-m-d 00:00:00'), $tz);
		$dEnd   = new DateTime($d->format('Y-m-d 23:59:59'), $tz);

		$existingMq = $q->get('meta_query');

		$mq = [
			[
				'key' => Meeting::MEETING_START_META_KEY,
				'value' => $dEnd->format('U'),
				'compare' => "<="
			],
			[
				[
					'key' => Meeting::MEETING_END_META_KEY,
					'value' => $dStart->format('U'),
					'compare' => ">="
				],
				[ // This condition is to allow for the possibility of events without end times.
					[
						'key' => Meeting::MEETING_END_META_KEY,
						'compare' => '=',
						'value' => 0
					],
					[
						'key' => Meeting::MEETING_START_META_KEY,
						'value' => $dStart->format('U'),
						'compare' => ">"
					],
					'relation' => 'AND'
				],
				'relation' => 'OR'
			],
			[
				'key' => Meeting::MEETING_META_KEY,
				'value' => 0,
				'compare' => ">"
			],
			'relation' => 'AND'
		];

		if (!empty($existingMq)) {
			$mq = [
				'relation' => 'AND',
				$existingMq,
				$mq,
			];
		}

		$q->set('meta_query', $mq);

		$q->set('meta_key', Meeting::MEETING_START_META_KEY);
		$q->set('orderby', 'meta_value');
		$q->set('order', 'ASC');

		$q->set('post_type', Involvement_PostTypeSettings::getPostTypes());

		return $q;
	}


	/**
	 * Get HTML for a link to the next month.
	 *
	 * @return string
	 */
	public function getNextLink(): string
	{
		return $this->getLinkForDate($this->next);
	}

	/**
	 * Get HTML for a link to the previous month.
	 *
	 * @return string
	 */
	public function getPrevLink(): string
	{
		return $this->getLinkForDate($this->prev);
	}

	/**
	 * Get an HTML link for the period that includes the given date.  This ONLY provides the URL Parameter portion, and
	 * is only meant to facilitate the next/prev links.
	 *
	 * @param DateTimeInterface $date
	 *
	 * @return string
	 */
	protected function getLinkForDate(DateTimeInterface $date): string
	{
		$link = "?page=" . $date->format('m-Y');
		if ($date->format('Y') === Utilities::dateTimeNow()->format('Y')) {
			$label = date_i18n('F', $date->getTimestamp());
		} else {
			$label = date_i18n('F Y', $date->getTimestamp());
		}
		return "<a href=\"$link\">$label</a>";
	}
}