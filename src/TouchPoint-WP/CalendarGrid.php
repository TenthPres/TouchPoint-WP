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
use tp\TouchPointWP\Utilities\DateFormats;
use WP_Query;

if ( ! defined('ABSPATH')) {
	exit(1);
}

if ( ! TOUCHPOINT_COMPOSER_ENABLED) {
	require_once "Interfaces/api.php";
}

/**
 *
 * Creates a listing of events, typically arranged in a monthly grid, but transformable to a list.
 *
 * @package TouchPointWP
 */
class CalendarGrid {
	/**
	 * @var ?DateTimeImmutable The date of the next month.
	 */
	public ?DateTimeImmutable $next = null;

	/**
	 * @var ?DateTimeImmutable The date of the previous month.
	 */
	public ?DateTimeImmutable $prev = null;

	/**
	 * The HTML for the calendar grid.
	 *
	 * @var string
	 */
	public string $html;

	/**
	 * The name of the month being displayed.
	 *
	 * @var string
	 */
	public string $monthName;

	/**
	 * The number of events included in the grid.  Used for the "no events" logic.
	 * 
	 * @var int 
	 */
	public int $eventCount = 0;


	/**
	 * Create a calendar grid for a given month and year.
	 *
	 * @param WP_Query $q
	 * @param int|null $month
	 * @param int|null $year
	 *
	 * @return void
	 */
	public function __construct(WP_Query $q, int $month = null, int $year = null)
	{
		try {
			// Validate month & year; create $d as a day within the month
			$tz = wp_timezone();
			$monthInt = intval($month);
			$monthStr = substr("0$monthInt", -2);
			$year  = intval($year);
			if ($monthInt < 1 || $monthInt > 12 || $year < 2020 || $year > 2100) {
				$d = new DateTime('now', $tz);
				$d = new DateTime($d->format('Y-m-01 00:00:00'), $tz);
			} else {
				$d = new DateTime("$year-$monthStr-01 00:00:00", $tz);
			}
		} catch (Exception $e) {
			$this->html = "<!-- Could not create calendar grid because an exception occurred: {$e->getMessage()} -->";
			return;
		}

		$firstDayOfMonth = DateTimeImmutable::createFromMutable($d);
		$lastDayOfMonth = DateTimeImmutable::createFromMutable($d);

		$this->monthName = self::getMonthNameForDate($d);

		// Get the day of the week for the first day of the month (0 = Sunday, 1 = Monday, ..., 6 = Saturday)
		$offsetDays = intval($d->format('w')); // w: Numeric representation of the day of the week

		// Extra days at the end of the month
		$daysInMonth = intval($d->format('t'));
		$daysToShow = 7 * ceil(($daysInMonth + $offsetDays) / 7);
		
		// Set start of range to be before the offset
		try {
			$d->modify("-$offsetDays days");
		} catch (Exception) { // Exception is not feasible.
		}
		$d->setTimezone($tz);
		$r = "";

		// Create a table to display the calendar
		$r .= '<div class="calGrid">';
		foreach (Utilities::getDaysOfWeekShort() as $dayStr) {
			$r .= "<div class='calWeekdayHead'>$dayStr</div>";
		}

		$isMonthBefore = ($offsetDays !== 0);
		$isMonthAfter = false;

		// do a query for the whole range of days in the month
		$d2 = DateTimeImmutable::createFromMutable($d)->add(new DateInterval("P{$daysToShow}D"));
		try {
			$newQ = self::adjustQueryForRange($q, $d, $d2, $tz);
		} catch (Exception $e) {
			$this->html = "<!-- Could not create calendar grid because an exception occurred: {$e->getMessage()} -->";
			return;
		}
		unset($d2);

		$monthPosts = $newQ->get_posts();
		$monthEvents = [];
		foreach ($monthPosts as $e) {
			try {
				$monthEvents[] = Meeting::fromPost($e);
			} catch (Exception) {
				// Ignore any exceptions
			}
		}

		try {
			$aDay  = new DateInterval("P1D");
			$d2359 = new DateTime($d->format('Y-m-d 23:59:59'), $tz);
		} catch (Exception $e) {
			$this->html = "<!-- Could not create calendar grid because an exception occurred: {$e->getMessage()} -->";
			return;
		}

		// Loop through the days of the month
		do {
			$ts = DateFormats::timestampWithoutOffset($d);
			$day = wp_date("j", $ts);
			$fullDay = DateFormats::DateStringFormatted($d);
			$wd =  wp_date("w", $ts);

			$cellClass = ["calDay"];
			if ($isMonthBefore) {
				$cellClass[] = "before";
				$adder = 0;
			} elseif ($isMonthAfter) {
				$cellClass[] = "after";
				$adder = 0;
			} else {
				$adder = 1;
			}

			$dayEvents = [];
			foreach ($monthEvents as $m) {
				if ($m->startDt > $d2359) {
					break;
				}
				if ($m->startDt < $d2359 && ($m->endDt ?? $m->startDt) >= $d) {
					$dayEvents[] = $m;
				}
			}

			if (count($dayEvents) === 0) {
				$cellClass[] = "empty";
			}

			if ($d < Utilities::dateTimeTodayAtMidnight()) {
				$cellClass[] = "past";
			}

			$cellClass[] = "weekday-$wd";

			$dayHtml = "";
			$hasFirstDays = false;

			foreach ($dayEvents as $m) {
				$link = $m->permalink();
				$notFirstDay = $m->startDt < $d;

				$attr = "";
				$status = $m->status();

				if ($status === Meeting::STATUS_CANCELLED) {
					// Translators: %s is the singular name of the of a Meeting, such as "Event".
					$title = wp_sprintf(__("%s is cancelled.", "TouchPoint-WP"), TouchPointWP::instance()->settings->mc_name_singular);
					$attr = "title=\"$title\"";
				}

				$e = $m->getPost();

				$classes = "event ";
				$classes .= $status . " ";
				$classes .= $m->tense();
				if ($m->isFeatured() && !$notFirstDay) {
					$classes .= " feat";
				}
				if ($notFirstDay) {
					$classes .= " notFirstDay";
					$dayHtml .= "<a href=\"$link\" class=\"$classes\" $attr><span class=\"title\">$e->post_title</span></a>";
				} else {
					$hasFirstDays = true;
					$this->eventCount += $adder;
					$ts = $m->startTimeString();
					if ($ts) {
						$ts = "<span class=\"time\">$ts</span> ";
					} else {
						$ts = "";
					}
					$dayHtml .= "<a href=\"$link\" class=\"$classes\" $attr>$ts<span class=\"title\">$e->post_title</span></a>";
				}
			}

			if (!$hasFirstDays) {
				$cellClass[] = "noFirstDays";
			}

			$cellClass = implode(" ", $cellClass);

			// Print the cell
			$r .= "<div class=\"$cellClass\">";
			$r .= "<h3 class=\"calDayHead\">$fullDay</h3>";
			$r .= "<span class=\"calDayNum\">$day</span>";

			$r .= $dayHtml;

			$r .= "</div>";

			// Increment days
			$mo1 = $d->format('n');
			$oldDd = $d->format('d');
			$d->add($aDay);
			$d->setTimezone($tz);

			// handle fall DST transitions.
			while ($d->format('d') == $oldDd) {
				$d->add($aDay);
				$d->setTimezone($tz);
			}

			try {
				$d     = new DateTime($d->format('Y-m-d 00:00:00'), $tz);
				$d2359 = new DateTime($d->format('Y-m-d 23:59:59'), $tz);
			} catch (Exception) {
				// unlikely to ever run, since the format is provided.
				$d2359->add($aDay);
				$d2359->setTimezone($tz);
			}
			$mo2 = $d->format('n');

			if ($mo1 !== $mo2) {
				if ($isMonthBefore) {
					$isMonthBefore = false;
				} else {
					$isMonthAfter = true;
					$lastDayOfMonth = $d;
				}
			}
		} while (!$isMonthAfter || $d->format('w') !== '0');
		$r .= '</div>';

		if ($this->eventCount > 0) {
			$this->html = $r;
		} else {
			// Translators: %s is the plural name of the of the Meetings, such as "Events".
			$message = wp_sprintf(__("There are no %s published for this month.", "TouchPoint-WP"), TouchPointWP::instance()->settings->mc_name_plural);
			$this->html = "<div class=\"calGrid noEvents\">$message</div>";
		}

		$this->next = DateTimeImmutable::createFromMutable($lastDayOfMonth);
		try {
			$this->prev = $firstDayOfMonth->sub($aDay)->setTimezone($tz);
		} catch (Exception) {
			$this->prev = null;
		}
	}

	/**
	 * Render the grid as HTML.
	 *
	 * @return string
	 */
	public function __toString(): string
	{
		self::enqueueCalendarStyle();
		return $this->html;
	}

	/**
	 * This function enqueues the stylesheet for the calendar grid.
	 */
	public static function enqueueCalendarStyle(): void
	{
		wp_enqueue_style(
			TouchPointWP::SHORTCODE_PREFIX . 'calendar-grid-style',
			TouchPointWP::instance()->assets_url . 'template/calendar-grid-style.css',
			[],
			TouchPointWP::VERSION
		);
	}

	/**
	 * This method returns a navigation bar for the calendar grid with simply next/prev month links.
	 *
	 * @param bool   $withMonthName
	 * @param string $class
	 *
	 * @return string
	 */
	public function navBar(bool $withMonthName = false, string $class=""): string
	{
		$r = "<div class=\"calGridNav $class\">";
		$r .= "<div class=\"prev\">";
		$r .= $this->getPrevLink();
		$r .= "</div>";

		if ($withMonthName) {
			$r .= "<div class=\"month\">";
			$r .= "<h2>$this->monthName</h2>";
			$r .= "</div>";
		}

		$r .= "<div class=\"next\">";
		$r .= $this->getNextLink();
		$r .= "</div>";
		$r .= "</div>";

		return $r;
	}

	/**
	 * Adjust a WP_Query object to filter only to events that overlap with the given day.
	 *
	 * @param WP_Query          $q   The original query object.
	 * @param DateTimeInterface $d1  The first day of the range.
	 * @param DateTimeInterface $d2  The last day of the range.
	 * @param DateTimeZone      $tz  The timezone to use.
	 *
	 * @return WP_Query
	 * @throws Exception
	 */
	private static function adjustQueryForRange(WP_Query $q, DateTimeInterface $d1, DateTimeInterface $d2, DateTimeZone $tz): WP_Query
	{
		$q = clone $q;

		$dStart = new DateTime($d1->format('Y-m-d 00:00:00'), $tz);
		$dEnd   = new DateTime($d2->format('Y-m-d 23:59:59'), $tz);

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

		$q->set('posts_per_page', 100000);
		$q->set('posts_per_archive_page', 100000);

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
		return self::getLinkForDate($this->next);
	}

	/**
	 * Get HTML for a link to the previous month.
	 *
	 * @return string
	 */
	public function getPrevLink(): string
	{
		return self::getLinkForDate($this->prev);
	}

	/**
	 * Get an HTML link for the period that includes the given date.  This ONLY provides the URL Parameter portion, and
	 * is only meant to facilitate the next/prev links.
	 *
	 * @param DateTimeInterface $date
	 *
	 * @return string
	 */
	protected static function getLinkForDate(DateTimeInterface $date): string
	{
		$link = "?page=" . $date->format('m-Y');
		$label = self::getMonthNameForDate($date);
		return "<a href=\"$link\">$label</a>";
	}

	/**
	 * Get the name of the month for a given date, with the year if different from current year.
	 *
	 * @param DateTimeInterface $date
	 *
	 * @return string
	 */
	protected static function getMonthNameForDate(DateTimeInterface $date): string
	{
		$ts = DateFormats::timestampWithoutOffset($date);
		if ($date->format('Y') === Utilities::dateTimeNow()->format('Y')) {
			$label = wp_date('F', $ts);
		} else {
			$label = wp_date('F Y', $ts);
		}
		return $label;
	}


	protected static ?CalendarGrid $defaultItem = null;

	/**
	 * Get a standard calendar grid, as would be used for most applications on a standard archive page.
	 *
	 * @return CalendarGrid
	 */
	public final static function getDefaultGrid(): CalendarGrid
	{
		global $wp_query;
		if (self::$defaultItem === null) {
			if (!isset($_GET['page']) || !preg_match('/^(?P<mo>[0-9]{2})-(?P<yr>[0-9]{4})$/', $_GET['page'], $matches)) {
				$matches = [
					'mo' => null,
					'yr' => null
				];
			}

			self::$defaultItem = new CalendarGrid($wp_query, $matches['mo'], $matches['yr']);
		}
		return self::$defaultItem;
	}

	/**
	 * @return void
	 */
	public static function shortcode(): void
	{
		if (have_posts()) {
			global $wp_query;

			$grid = self::getDefaultGrid();
			echo $grid->navBar(true);
			echo $grid;
			if ($grid->eventCount > 0) {
				echo $grid->navBar(false, 'bottom');
			}

			wp_reset_query();
			$taxQuery                          = [[]];
			$wp_query->tax_query->queries      = $taxQuery;
			$wp_query->query_vars['tax_query'] = $taxQuery;
			$wp_query->is_tax                  = false;  // prevents templates from thinking this is a taxonomy archive
		}
	}
}