<?php
/**
 * @package TouchPointWP
 */

namespace tp\TouchPointWP;

if ( ! defined('ABSPATH')) {
	exit(1);
}

if ( ! TOUCHPOINT_COMPOSER_ENABLED) {
	require_once "api.php";
	require_once "jsInstantiation.php";
	require_once "jsonLd.php";
	require_once "updatesViaCron.php";
	require_once "Utilities.php";
	require_once "Utilities/Geo.php";
	require_once "Involvement_PostTypeSettings.php";
}

use DateInterval;
use DateTimeImmutable;
use Exception;
use stdClass;
use tp\TouchPointWP\Utilities\Http;
use tp\TouchPointWP\Utilities\PersonArray;
use tp\TouchPointWP\Utilities\PersonQuery;
use WP_Error;
use WP_Post;
use WP_Query;
use WP_Term;

/**
 * Fundamental object meant to correspond to an Involvement in TouchPoint
 */
class Involvement implements api, updatesViaCron, geo, module
{
	use jsInstantiation;
	use jsonLd;

	public const SHORTCODE_MAP = TouchPointWP::SHORTCODE_PREFIX . "Inv-Map";
	public const SHORTCODE_FILTER = TouchPointWP::SHORTCODE_PREFIX . "Inv-Filters";
	public const SHORTCODE_LIST = TouchPointWP::SHORTCODE_PREFIX . "Inv-List";
	public const SHORTCODE_NEARBY = TouchPointWP::SHORTCODE_PREFIX . "Inv-Nearby";
	public const SHORTCODE_ACTIONS = TouchPointWP::SHORTCODE_PREFIX . "Inv-Actions";
	public const CRON_HOOK = TouchPointWP::HOOK_PREFIX . "inv_cron_hook";
	public const CRON_OFFSET = 86400 + 3600;

	public const SCHEDULE_STRING_CACHE_EXPIRATION = 3600 * 8; // 8 hours.  Automatically deleted during sync.
	public const SCHED_STRING_CACHE_KEY = TouchPointWP::HOOK_PREFIX . "inv_schedule_string";

	protected static bool $_hasUsedMap = false;
	protected static bool $_hasArchiveMap = false;
	private static array $_instances = [];
	private static bool $_isLoaded = false;

	public static string $containerClass = 'inv-list';
	public static string $itemClass = 'inv-list-item';

	private static bool $filterJsAdded = false;
	public ?object $geo = null;
	static protected object $compareGeo;

	protected ?string $locationName = null;
	protected ?DateTimeImmutable $_nextMeeting;
	protected ?DateTimeImmutable $firstMeeting = null;
	protected ?DateTimeImmutable $lastMeeting = null;
	protected ?string $_scheduleString;
	protected ?array $_meetings = null;
	protected ?array $_schedules = null;
	protected PersonArray $_leaders;
	protected PersonArray $_members;
	protected ?PersonArray $_hosts;
	protected ?int $genderId = null;
	public ?string $color = "#999999";

	public string $name;
	public int $invId;

	/**
	 * @var string The Involvement Type is the post Type WITHOUT the possible prefix.
	 */
	public string $invType;

	public int $post_id;
	public string $post_excerpt;
	protected WP_Post $post;

	public const INVOLVEMENT_META_KEY = TouchPointWP::SETTINGS_PREFIX . "invId";

	public object $attributes;
	protected array $divisions;

	/**
	 * Involvement constructor.
	 *
	 * @param $object WP_Post|object an object representing the involvement's post.
	 *                  Must have post_id AND inv id attributes.
	 *
	 * @throws TouchPointWP_Exception
	 */
	protected function __construct(object $object)
	{
		$this->attributes = (object)[];

		if (gettype($object) === "object" && get_class($object) == WP_Post::class) {
			// WP_Post Object
			$this->post    = $object;
			$this->name    = $object->post_title;
			$this->invId   = intval($object->{self::INVOLVEMENT_META_KEY});
			$this->post_id = $object->ID;
			$this->invType = get_post_type($this->post_id);

			if ($this->invId === 0) {
				throw new TouchPointWP_Exception("No Involvement ID provided in the post.");
			}
		} elseif (gettype($object) === "object") {
			// Sql Object, probably.

			if ( ! property_exists($object, 'post_id')) {
				_doing_it_wrong(
					__FUNCTION__,
					esc_html(
						__('Creating an Involvement object from an object without a post_id is not yet supported.')
					),
					esc_attr(TouchPointWP::VERSION)
				);
			}

			$this->post    = get_post($object, "OBJECT");
			$this->post_id = $this->post->ID;
			$this->invType = $object->invType;

			foreach ($object as $property => $value) {
				if (property_exists(self::class, $property)) {
					$this->$property = $value;
				}
				// TODO add an else for nonstandard/optional metadata fields
			}
		} else {
			throw new TouchPointWP_Exception("Could not construct an Involvement with the information provided.");
		}

		// clean up involvement type to not have hook prefix, if it does.
		if (strpos($this->invType, TouchPointWP::HOOK_PREFIX) === 0) {
			$this->invType = substr($this->invType, strlen(TouchPointWP::HOOK_PREFIX));
		}

		$postTerms = [
			TouchPointWP::TAX_RESCODE,
			TouchPointWP::TAX_AGEGROUP,
			TouchPointWP::TAX_WEEKDAY,
			TouchPointWP::TAX_TENSE,
			TouchPointWP::TAX_DAYTIME,
			TouchPointWP::TAX_INV_MARITAL,
			TouchPointWP::TAX_DIV
		];
		if (TouchPointWP::instance()->settings->enable_campuses === "on") {
			$postTerms[] = TouchPointWP::TAX_CAMPUS;
		}

		$terms = wp_get_post_terms(
			$this->post_id,
			$postTerms
		);

		if (is_array($terms) && count($terms) > 0) {
			$hookLength = strlen(TouchPointWP::HOOK_PREFIX);
			foreach ($terms as $t) {
				/** @var WP_Term $t */
				$to = (object)[
					'name' => $t->name,
					'slug' => $t->slug
				];
				$ta = $t->taxonomy;
				if (strpos($ta, TouchPointWP::HOOK_PREFIX) === 0) {
					$ta = substr_replace($ta, "", 0, $hookLength);
				}
				if ( ! isset($this->attributes->$ta)) {
					$this->attributes->$ta = $to;
				} elseif ( ! is_array($this->attributes->$ta)) {
					$this->attributes->$ta = [$this->attributes->$ta, $to];
				} else {
					$this->attributes->$ta[] = $to;
				}
			}
		}

		$meta         = get_post_meta($this->post_id);
		$prefixLength = strlen(TouchPointWP::SETTINGS_PREFIX);

		foreach ($meta as $k_tp => $v) {
			if (substr($k_tp, 0, $prefixLength) !== TouchPointWP::SETTINGS_PREFIX) {
				continue; // not ours.
			}

			$k = substr($k_tp, $prefixLength);
			if ($k === "invId") {
				continue;
			}
			if (property_exists(self::class, $k)) {  // properties
				$this->$k = maybe_unserialize($v[0]);
			}
		}

		// JS attributes, for filtering mostly.
		$this->attributes->genderId = (string)$this->genderId;

		// Geo
		if (self::getSettingsForPostType($this->invType)->useGeo) {
			if (property_exists($object, 'geo_lat') &&
			    $object->geo_lat !== null &&
			    $object->geo_lat !== '') {
				// Probably a database query result
				$this->geo = (object)[
					'lat' => Utilities::toFloatOrNull($object->geo_lat),
					'lng' => Utilities::toFloatOrNull($object->geo_lng)
				];
			} elseif (get_class($object) === WP_Post::class) {
				// Probably a post
				$this->geo = (object)[
					'lat' => Utilities::toFloatOrNull($meta[TouchPointWP::SETTINGS_PREFIX . 'geo_lat'][0] ?? ""),
					'lng' => Utilities::toFloatOrNull($meta[TouchPointWP::SETTINGS_PREFIX . 'geo_lng'][0] ?? "")
				];
			}
			if ( ! $this->hasGeo()) {
				$this->geo = null;
			} else {
				$this->geo->lat = round($this->geo->lat, 3); // Roughly .2 mi
				$this->geo->lng = round($this->geo->lng, 3);
			}

			// Color!
			$this->color = Utilities::getColorFor("default", "involvement");
		}

		$this->registerConstruction();
	}

	/**
	 * Get the settings array of objects for Involvement Post Types
	 *
	 * @return Involvement_PostTypeSettings[]
	 */
	final protected static function &allTypeSettings(): array
	{
		return Involvement_PostTypeSettings::instance();
	}


	/**
	 * Register stuff
	 */
	public static function init(): void
	{
		foreach (self::allTypeSettings() as $type) {
			/** @var $type Involvement_PostTypeSettings */

			register_post_type(
				$type->postType,
				[
					'labels'            => [
						'name'          => $type->namePlural,
						'singular_name' => $type->nameSingular
					],
					'public'            => true,
					'hierarchical'      => $type->hierarchical,
					'show_ui'           => false,
					'show_in_nav_menus' => true,
					'show_in_rest'      => true,
					'supports'          => [
						'title',
						'custom-fields',
						'thumbnail'
					],
					'has_archive'       => true,
					'rewrite'           => [
						'slug'       => $type->slug,
						'with_front' => false,
						'feeds'      => false,
						'pages'      => true
					],
					'query_var'         => $type->slug,
					'can_export'        => false,
					'delete_with_user'  => false
				]
			);
		}

		// Register default templates for Involvements
		add_filter('template_include', [self::class, 'templateFilter']);

		// Register function to return schedule instead of publishing date
		add_filter('get_the_date', [self::class, 'filterPublishDate'], 10, 3);
		add_filter('get_the_time', [self::class, 'filterPublishDate'], 10, 3);

		// Register function to return leaders instead of authors
		add_filter('the_author', [self::class, 'filterAuthor'], 10, 3);
		add_filter('get_the_author_display_name', [self::class, 'filterAuthor'], 10, 3);
	}

	public static function checkUpdates(): void
	{
		// Return if not overdue.
		if (TouchPointWP::instance()->settings->inv_cron_last_run * 1 >= time() - self::CRON_OFFSET) {
			return;
		}

		// Fork sync to a different process, if supported. (only some linux systems)
		$forked = false;
		/** @noinspection SpellCheckingInspection */
		if (function_exists('pcntl_fork')) {
			$pid = pcntl_fork();
			if ($pid >= 0) {
				// Forking successful.
				$forked = true;

				if ($pid === 0) {
					// Child process.  Parent process will have some PID > 0.
					self::updateFromTouchPoint();
					exit;
				}
			}
		}
		if ( ! $forked) {
			self::updateFromTouchPoint();
		}
	}

	/**
	 * Query TouchPoint and update Involvements in WordPress
	 *
	 * @param bool $verbose Whether to print debugging info.
	 *
	 * @return false|int False on failure, or the number of groups that were updated or deleted.
	 */
	public static function updateFromTouchPoint(bool $verbose = false)
	{
		$count   = 0;
		$success = true;

		// Prevent other threads from attempting for an hour.
		TouchPointWP::instance()->settings->set('inv_cron_last_run', time() - self::CRON_OFFSET + 3600);

		$verbose &= TouchPointWP::currentUserIsAdmin();

		foreach (self::allTypeSettings() as $type) {
			if (count($type->importDivs) < 1) {
				// Don't update if there aren't any divisions selected yet.
				if ($verbose) {
					print "Skipping $type->namePlural because no divisions are selected.";
				}
				continue;
			}

			// Divisions
			$divs   = Utilities::idArrayToIntArray($type->importDivs, false);
			try {
				$update = self::updateInvolvementPostsForType($type, $divs, $verbose);
			} catch (Exception $e) {
				if ($verbose) {
					echo "An exception occurred while syncing $type->namePlural: " . $e->getMessage();
				}
				continue;
			}

			if ($update === false) {
				$success = false;
			} else {
				$count += $update;
			}
		}
		unset($type);

		if ($success && $count !== 0) {
			TouchPointWP::instance()->settings->set('inv_cron_last_run', time());
		} else {
			TouchPointWP::instance()->settings->set('inv_cron_last_run', 0);
		}

		if ($verbose) {
			echo "Updated $count items";
		}

		if ($count > 0) {
			TouchPointWP::instance()->flushRewriteRules();
		}

		return $count;
	}


	/**
	 * @param string $template
	 *
	 * @return string
	 *
	 * @noinspection unused
	 */
	public static function templateFilter(string $template): string
	{
		if (apply_filters(TouchPointWP::HOOK_PREFIX . 'use_default_templates', true, self::class)) {
			$postTypesToFilter        = Involvement_PostTypeSettings::getPostTypes();
			$templateFilesToOverwrite = TouchPointWP::TEMPLATES_TO_OVERWRITE;

			if (count($postTypesToFilter) == 0) {
				return $template;
			}

			if ( ! in_array(ltrim(strrchr($template, '/'), '/'), $templateFilesToOverwrite)) {
				return $template;
			}

			if (is_post_type_archive($postTypesToFilter) && file_exists(
					TouchPointWP::$dir . '/src/templates/involvement-archive.php'
				)) {
				$template = TouchPointWP::$dir . '/src/templates/involvement-archive.php';
			}

			if (is_singular($postTypesToFilter) && file_exists(
					TouchPointWP::$dir . '/src/templates/involvement-single.php'
				)) {
				$template = TouchPointWP::$dir . '/src/templates/involvement-single.php';
			}
		}

		return $template;
	}


	/**
	 * Whether the involvement can be joined
	 *
	 * @return bool|string  True if involvement can be joined. False if no registration exists. Or, a string with why
	 *     it can't be joined otherwise.
	 */
	public function acceptingNewMembers()
	{
		if (get_post_meta($this->post_id, TouchPointWP::SETTINGS_PREFIX . "groupFull", true) === '1') {
			return __("Currently Full", 'TouchPoint-WP');
		}

		if (get_post_meta($this->post_id, TouchPointWP::SETTINGS_PREFIX . "groupClosed", true) === '1') {
			return __("Currently Closed", 'TouchPoint-WP');
		}

		$now      = current_datetime();
		$regStart = get_post_meta($this->post_id, TouchPointWP::SETTINGS_PREFIX . "regStart", true);
		if ($regStart !== false && $regStart !== '' && $regStart > $now) {
			return __("Registration Not Open Yet", 'TouchPoint-WP');
		}

		$regEnd = get_post_meta($this->post_id, TouchPointWP::SETTINGS_PREFIX . "regEnd", true);
		if ($regEnd !== false && $regEnd !== '' && $regEnd < $now) {
			return __("Registration Closed", 'TouchPoint-WP');
		}

		if (intval(get_post_meta($this->post_id, TouchPointWP::SETTINGS_PREFIX . "regTypeId", true)) === 0) {
			return false; // no online registration available
		}

		return true;
	}

	/**
	 * Whether the involvement should link to a registration form, rather than directly joining the org.
	 *
	 * @return bool
	 */
	public function useRegistrationForm(): bool
	{
		if (!isset($this->_useRegistrationForm)) {
			$this->_useRegistrationForm = (get_post_meta($this->post_id, TouchPointWP::SETTINGS_PREFIX . "hasRegQuestions", true) === '1' ||
			        intval(get_post_meta($this->post_id, TouchPointWP::SETTINGS_PREFIX . "regTypeId", true)) !== 1);
		}
		return $this->_useRegistrationForm;
	}
	private bool $_useRegistrationForm;

	/**
	 * @return stdClass[]
	 */
	protected function meetings(): array
	{
		if ( ! isset($this->_meetings)) {
			$m = get_post_meta($this->post_id, TouchPointWP::SETTINGS_PREFIX . "meetings", true);
			if ($m === "") {
				$m = [];
			}
			$this->_meetings = $m;
		}

		return $this->_meetings;
	}

	/**
	 * @return stdClass[]
	 */
	protected function schedules(): array
	{
		if ( ! isset($this->_schedules)) {
			$s = get_post_meta($this->post_id, TouchPointWP::SETTINGS_PREFIX . "schedules", true);
			if ($s === "") {
				$s = [];
			}
			$this->_schedules = $s;
		}

		return $this->_schedules;
	}

	/**
	 * Get a description of the meeting schedule in a human-friendly phrase, e.g. Sundays at 11:00am, starting January
	 * 14.
	 *
	 * @return string
	 */
	public function scheduleString(): ?string
	{
		if ( ! isset($this->_scheduleString)) {
			$cacheGroup            = $this->invId . "_" . get_locale();
			$this->_scheduleString = wp_cache_get(self::SCHED_STRING_CACHE_KEY, $cacheGroup);
			if ( ! $this->_scheduleString) {
				$this->_scheduleString = $this->scheduleString_calc();
				wp_cache_set(
					self::SCHED_STRING_CACHE_KEY,
					$this->_scheduleString,
					$cacheGroup,
					self::SCHEDULE_STRING_CACHE_EXPIRATION
				);
			}
		}

		return $this->_scheduleString;
	}

	/**
	 * Get the next meeting date/time from either the meetings or schedules.
	 *
	 * @return DateTimeImmutable|null
	 */
	public function nextMeeting(): ?DateTimeImmutable
	{
		$now                = new DateTimeImmutable();
		$this->_nextMeeting = null;

		if ($this->_nextMeeting === null) {
			// meetings
			foreach ($this->meetings() as $m) {
				$mdt = $m->dt;
				if ($mdt > $now) {
					if ($this->_nextMeeting === null || $mdt < $this->_nextMeeting) {
						$this->_nextMeeting = $mdt;
					}
				}
			}

			// schedules
			foreach ($this->schedules() as $s) {
				$mdt = $s->next;
				if ($mdt > $now) {
					if ($this->_nextMeeting === null || $mdt < $this->_nextMeeting) {
						$this->_nextMeeting = $mdt;
					}
				}
			}
		}

		// schedules + 1 week (assumes schedules are recurring weekly)
		if ($this->_nextMeeting === null) { // really only needed if we don't have a date yet.
			foreach ($this->schedules() as $s) {
				$mdt = $s->next->modify("+1 week");
				if ($mdt > $now) {
					if ($this->_nextMeeting === null || $mdt < $this->_nextMeeting) {
						$this->_nextMeeting = $mdt;
					}
				}
			}
		}

		return $this->_nextMeeting;
	}

	/**
	 * @param array $meetings
	 * @param array $schedules
	 *
	 * @return ?array[]
	 */
	private static function computeCommonOccurrences(array $meetings = [], array $schedules = []): ?array
	{
		try {
			$siteTz = wp_timezone();
			$now    = new DateTimeImmutable(null, $siteTz);
		} catch (Exception $e) {
			return null;
		}

		$commonOccurrences = [];

		// Populate the schedules
		foreach ($schedules as $s) {
			if ( ! is_object($s)) {
				continue;
			}

			$dt = $s->next;

			$coInx                     = $dt->format('w-Hi');
			$commonOccurrences[$coInx] = [
				'count'   => 20,
				'example' => $dt
			];
		}
		unset($dt, $coInx, $s);

		// If there isn't a schedule, but there are common meeting dates/times, use those.
		foreach ($meetings as $m) {
			if ( ! is_object($m)) {
				continue;
			}

			$dt = $m->dt;

			if ($dt < $now) {
				continue;
			}

			$coInx = $dt->format('w-Hi');
			if (isset($commonOccurrences[$coInx])) {
				$commonOccurrences[$coInx]['count']++;
			} else {
				$commonOccurrences[$coInx] = [
					'count'   => 1,
					'example' => $dt
				];
			}
		}
		unset($dt, $coInx, $m);

		return array_filter($commonOccurrences, fn($co) => $co['count'] > 2);
	}

	/**
	 * Calculate the schedule string.
	 *
	 * @return string
	 */
	protected function scheduleString_calc(): ?string
	{
		$commonOccurrences = self::computeCommonOccurrences($this->meetings(), $this->schedules());

		$dayStr     = null;
		$timeFormat = get_option('time_format');
		$dateFormat = get_option('date_format');

		if (count($commonOccurrences) > 0) {
			$uniqueTimeStrings = [];
			$days              = [];
			if (count($commonOccurrences) > 1) { // this is only needed if there's multiple schedules
				foreach ($commonOccurrences as $k => $co) {
					$timeStr = substr($k, 2);
					if ( ! in_array($timeStr, $uniqueTimeStrings, true)) {
						$uniqueTimeStrings[] = $timeStr;

						$weekday = "d" . $k[0];
						if ( ! isset($days[$weekday])) {
							$days[$weekday] = [];
						}
						$days[$weekday][] = $co['example'];
					}
				}
				unset($timeStr, $k, $co, $weekday);
			} else {
				$cok                   = array_key_first($commonOccurrences);
				$days["d" . $cok[0]][] = $commonOccurrences[$cok]['example'];
			}

			if (count($uniqueTimeStrings) > 1) {  // Multiple different times.  Sun at 9am & 11am, and Sat at 6pm
				// multiple different times of day
				$dayStr = [];
				foreach ($days as $dk => $dta) {
					$timeStr = [];
					foreach ($dta as $dt) {
						/** @var $dt DateTimeImmutable */
						$ts        = $dt->format($timeFormat);
						$ts        = apply_filters(TouchPointWP::HOOK_PREFIX . 'adjust_time_string', $ts, $dt);
						$timeStr[] = $ts;
					}
					$timeStr = Utilities::stringArrayToListString($timeStr);

					if (count($days) > 1) {  // Mon at 7pm & Tue at 8pm
						$day = Utilities::getDayOfWeekShortForNumber(intval($dk[1]));
					} else {
						$day = Utilities::getPluralDayOfWeekNameForNumber(intval($dk[1]));
					}
					// translators: "Mon at 7pm"  or  "Sundays at 9am & 11am"
					$dayStr[] = wp_sprintf(__('%1$s at %2$s', 'TouchPoint-WP'), $day, $timeStr);
				}
				$dayStr = Utilities::stringArrayToListString($dayStr);
			} else {  // one time of day.  Tue & Thu at 7pm
				if (count($days) > 1) {
					// more than one day per week
					$dayStr = [];
					foreach ($days as $k => $d) {
						$dayStr[] = Utilities::getDayOfWeekShortForNumber(intval($k[1]));
					}
					$dayStr = Utilities::stringArrayToListString($dayStr);
				} else {
					// one day of the week
					$k      = array_key_first($days);
					$dayStr = Utilities::getPluralDayOfWeekNameForNumber(intval($k[1]));
				}
				$dt = array_values($days)[0][0];
				/** @var $dt DateTimeImmutable */
				$timeStr = $dt->format($timeFormat);
				$timeStr = apply_filters(TouchPointWP::HOOK_PREFIX . 'adjust_time_string', $timeStr, $dt);
				$dayStr  = wp_sprintf(__('%1$s at %2$s', 'TouchPoint-WP'), $dayStr, $timeStr);
			}
		}

		// Convert start and end to string.
		if ($this->firstMeeting !== null && $this->lastMeeting !== null) {
			if ($dayStr === null) {
				$dayStr = wp_sprintf(
				// translators: {start date} through {end date}  e.g. February 14 through August 12
					__('%1$s through %2$s', 'TouchPoint-WP'),
					$this->firstMeeting->format($dateFormat),
					$this->lastMeeting->format($dateFormat)
				);
			} else {
				$dayStr = wp_sprintf(
				// translators: {schedule}, {start date} through {end date}  e.g. Sundays at 11am, February 14 through August 12
					__('%1$s, %2$s through %3$s', 'TouchPoint-WP'),
					$dayStr,
					$this->firstMeeting->format($dateFormat),
					$this->lastMeeting->format($dateFormat)
				);
			}
		} elseif ($this->firstMeeting !== null) {
			if ($dayStr === null) {
				$dayStr = wp_sprintf(
				// translators: Starts {start date}  e.g. Starts September 15
					__('Starts %1$s', 'TouchPoint-WP'),
					$this->firstMeeting->format($dateFormat)
				);
			} else {
				$dayStr = wp_sprintf(
				// translators: {schedule}, starting {start date}  e.g. Sundays at 11am, starting February 14
					__('%1$s, starting %2$s', 'TouchPoint-WP'),
					$dayStr,
					$this->firstMeeting->format($dateFormat)
				);
			}
		} elseif ($this->lastMeeting !== null) {
			if ($dayStr === null) {
				$dayStr = wp_sprintf(
				// translators: Through {end date}  e.g. Through September 15
					__('Through %1$s', 'TouchPoint-WP'),
					$this->lastMeeting->format($dateFormat)
				);
			} else {
				$dayStr = wp_sprintf(
				// translators: {schedule}, through {end date}  e.g. Sundays at 11am, through February 14
					__('%1$s, through %2$s', 'TouchPoint-WP'),
					$dayStr,
					$this->lastMeeting->format($dateFormat)
				);
			}
		}

		return $dayStr;
	}

	/**
	 * Returns an array of the Involvement's Divisions, excluding those that cause it to be included.
	 *
	 * @return string[]
	 */
	public function getDivisionsStrings(): array
	{
		$exclude = $this->settings()->importDivs;

		if ( ! isset($this->divisions)) {
			if (count($exclude) > 1) {
				$mq = ['relation' => "AND"];
			} else {
				$mq = [];
			}

			foreach ($exclude as $e) {
				$mq[] = [
					'key'     => TouchPointWP::SETTINGS_PREFIX . 'divId',
					'value'   => substr($e, 3),
					'compare' => 'NOT LIKE'
				];
			}

			$this->divisions = wp_get_post_terms($this->post_id, TouchPointWP::TAX_DIV, ['meta_query' => $mq]);
		}

		$out = [];
		foreach ($this->divisions as $d) {
			$out[] = $d->name;
		}

		return $out;
	}

	/**
	 * Get the setting object for a specific post type or involvement type
	 *
	 * @param ?string $postType Accepts either the post type string, or the inv type string
	 *
	 * @return ?Involvement_PostTypeSettings
	 */
	public static function getSettingsForPostType(?string $postType): ?Involvement_PostTypeSettings
	{
		if ($postType === null) {
			return null;
		}

		return Involvement_PostTypeSettings::getForInvType($postType);
	}

	/**
	 * Get an array of Involvement Post Types
	 *
	 * @return array
	 */
	public static function getPostTypes(): array
	{
		$r = [];
		foreach (self::allTypeSettings() as $pt) {
			$r[] = $pt->postTypeWithPrefix();
		}

		return $r;
	}

	/**
	 * Display action buttons for an involvement.  Takes an id parameter for the Involvement ID.  If not provided,
	 * the current post will be used.
	 *
	 * @param array|string $params
	 * @param string       $content
	 *
	 * @return string
	 * @noinspection PhpUnusedParameterInspection
	 */
	public static function actionsShortcode($params = [], string $content = ""): string
	{
		// standardize parameters
		if (is_string($params)) {
			$params = explode(",", $params);
		}
		/** @noinspection PhpRedundantOptionalArgumentInspection */
		$params = array_change_key_case($params, CASE_LOWER);

		// set some defaults
		/** @noinspection SpellCheckingInspection */
		$params = shortcode_atts(
			[
				'class'    => 'TouchPoint-involvement actions',
				'btnclass' => 'btn button',
				'invid'    => null,
				'id'       => wp_unique_id('tp-actions-')
			],
			$params,
			self::SHORTCODE_ACTIONS
		);

		/** @noinspection SpellCheckingInspection */
		$iid = $params['invid'];

		// If there's no invId, try to get one from the Post
		if ($iid === null) {
			$post = get_post();

			if (is_object($post)) {
				try {
					$inv = self::fromPost($post);
					$iid = $inv->invId;
				} catch (TouchPointWP_Exception $e) {
					$iid = null;
				}
			}
		}

		// If there is no invId at this point, this is an error.
		if ($iid === null) {
			return "<!-- Error: Can't create Involvement Actions because there is no clear involvement.  Define the InvId and make sure it's imported. -->";
		}

		try {
			$inv = self::fromInvId($iid);
		} catch (TouchPointWP_Exception $e) {
			return "<!-- Error: " . $e->getMessage() . " -->";
		}

		if ($inv === null) {
			return "<!-- Error: Involvement isn't instantiated. -->";
		}

		$eltId = $params['id'];
		$class = $params['class'];

		return "<div id=\"$eltId\" class=\"$class\" data-tp-involvement=\"$inv->post_id\">{$inv->getActionButtons('actions-shortcode', $params['btnclass'])}</div>";
	}

	/**
	 * @param array|string $params
	 *
	 * @return string
	 */
	public static function filterShortcode($params = []): string
	{
		// Check that params aren't a string.
		if (is_string($params) && $params !== '') {
			_doing_it_wrong(
				__FUNCTION__,
				"Descriptive parameters are required for the filter shortcode.",
				TouchPointWP::VERSION
			);

			return "<!-- Descriptive parameters are required for the filter shortcode. -->";
		}

		if ($params === '') {
			$params = [];
		}

		// Attempt to infer the type if it doesn't exist.
		if ( ! isset($params['type'])) {
			$params['type'] = is_archive() ? get_queried_object()->name : false;
		}

		// Check that Type parameter exists.
		if ($params['type'] === false) {
			_doing_it_wrong(
				__FUNCTION__,
				"A Post Type is required for the Filter Shortcode.",
				TouchPointWP::VERSION
			);

			return "<!-- A Post Type is required for the Filter Shortcode. -->";
		}

		// Get the settings object
		$settings = self::getSettingsForPostType($params['type']);

		// Make sure post type provided is valid.
		if ($settings === null) {
			_doing_it_wrong(
				__FUNCTION__,
				"The Post Type provided to the Filter Shortcode is invalid.",
				TouchPointWP::VERSION
			);

			return "<!-- The Post Type provided to the Filter Shortcode is invalid. -->";
		}

		self::requireAllObjectsInJs();

		if ( ! self::$filterJsAdded) {
			wp_add_inline_script(
				TouchPointWP::SHORTCODE_PREFIX . 'base-defer',
				"
                tpvm.addEventListener('Involvement_fromObjArray', function() {
                    TP_Involvement.initFilters();
                });"
			);
			self::$filterJsAdded = true;
		}

		return self::filterDropdownHtml($params, $settings);
	}


	public static function doInvolvementList(WP_Query $q, $params = []): void
	{
		$q->set('posts_per_page', -1);
		$q->set('nopaging', true);
		$q->set('orderby', 'title'); // will mostly be overwritten by geographic sort, if available.
		$q->set('order', 'ASC');

		if ($q->is_post_type_archive()) {
			$q->set('post_parent', 0);
		}

		// Get the formalized post types
		$types = [];
		$terms = [null];
		if ( ! isset($params['type']) && $q->is_post_type_archive()) {
			$params['type'] = $q->query['post_type'];
		}
		$settings = null;
		foreach (explode(',', $params['type']) as $t) {
			$settings = self::getSettingsForPostType($params['type']);
			if ($settings !== null) {
				$types[] = $settings->postType;
			}
		}
		if (count($types) > 0) {
			$q->set('post_type', $types);
		}

		// CSS
		/** @noinspection SpellCheckingInspection */
		$params['includecss'] = ! isset($params['includecss']) ||
		                        $params['includecss'] === true ||
		                        $params['includecss'] === 'true';

		// Only group for single post types.
		$groupBy = null;
		if (count($types) === 1) {
			$groupBy      = $settings->groupBy;
			$groupByOrder = "ASC";
			if (strlen($groupBy) > 1 && $groupBy[0] === "-") {
				$groupBy      = substr($groupBy, 1);
				$groupByOrder = "DESC";
			}

			if ($groupBy !== "" && taxonomy_exists($groupBy)) {
				$terms = get_terms([
					                   'taxonomy'   => $groupBy,
					                   'order'      => $groupByOrder,
					                   'orderby'    => 'name',
					                   'hide_empty' => true,
					                   'fields'     => 'id=>name'
				                   ]);
			}
		}

		$taxQuery = ['relation' => 'AND'];

		// Filter by Division
		if (isset($params['div'])) {
			$divs = [];
			foreach (explode(',', $params['div']) as $d) {
				$tid = TouchPointWP::getDivisionTermIdByDivId($d);
				if ( ! ! $tid) {
					$divs[] = $tid;
				}
			}
			if (count($divs) > 0) {
				$taxQuery[] = [
					'taxonomy' => TouchPointWP::TAX_DIV,
					'field'    => 'ID',
					'terms'    => $divs
				];
			}
		}

		// Prepare to sort by distance if location is already cached.
		$userLoc = TouchPointWP::instance()->geolocate(false);
		if ($userLoc !== false) {
			// we have a viable location. Use it for sorting by distance.
			Involvement::setComparisonGeo($userLoc);
			if ( ! headers_sent()) { // Depending on when this was called, it may be too late.
				TouchPointWP::doCacheHeaders(TouchPointWP::CACHE_PRIVATE);
			}
		}

		$containerClass = $params['class'] ?? self::$containerClass;

		// Groupings
		foreach ($terms as $termId => $name) {
			if (count($terms) > 1 && $groupBy !== null) {
				// do the tax filtering
				$taxQuery[] = [
					'taxonomy' => $groupBy,
					'field'    => 'term_id',
					'terms'    => [$termId],
				];
			}

			$q->set('tax_query', $taxQuery);

			global $posts;
			$posts = $q->get_posts();

			if ($q->post_count > 0) {
				/** @noinspection SpellCheckingInspection */
				if ($params['includecss']) {
					TouchPointWP::enqueuePartialsStyle();
				}

				echo "<div class=\"$containerClass\">";

				if (count($terms) > 1 && $groupBy !== null) {
					echo "<h2>$name</h2>";
				}

				usort($posts, [Involvement::class, 'sortPosts']);

				foreach ($posts as $post) {
					$loadedPart = get_template_part('list-item', 'involvement-list-item');
					if ($loadedPart === false) {
						require TouchPointWP::$dir . "/src/templates/parts/involvement-list-item.php";
					}
				}

				echo "</div>";
			}

			// remove the grouping
			if (count($terms) > 1 && $groupBy !== null) {
				array_pop($taxQuery);
			}

			wp_reset_query();
		}
	}

	/**
	 * Get a WP_Post by the Involvement ID if it exists.  Return null if it does not.
	 *
	 * @param string|string[] $postType
	 * @param                 $involvementId
	 *
	 * @return int|WP_Post|null
	 */
	private static function getWpPostByInvolvementId($postType, $involvementId)
	{
		$involvementId = (string)$involvementId;

		$q      = new WP_Query([
			                       'post_type'   => $postType,
			                       'meta_key'    => self::INVOLVEMENT_META_KEY,
			                       'meta_value'  => $involvementId,
			                       'numberposts' => 2
			                       // only need one, but if there's two, there should be an error condition.
		                       ]);
		$posts  = $q->get_posts();
		$counts = count($posts);
		if ($counts > 1) {  // multiple posts match, which isn't great.
			new TouchPointWP_Exception("Multiple Posts Exist", 170006);
		}
		if ($counts > 0) { // post exists already.
			return $posts[0];
		} else {
			return null;
		}
	}

	/**
	 * Print a list of involvements that match the given criteria.
	 *
	 * @param array|string $params
	 * @param string       $content
	 *
	 * @return string
	 *
	 * @noinspection PhpUnusedParameterInspection
	 */
	public static function listShortcode($params = [], string $content = ""): string
	{
		// standardize parameters
		if (is_string($params)) {
			$params = explode(",", $params);
		}
		/** @noinspection PhpRedundantOptionalArgumentInspection */
		$params = array_change_key_case($params, CASE_LOWER);

		// set some defaults
		/** @noinspection SpellCheckingInspection */
		$params = shortcode_atts(
			[
				'type'       => null,
				'div'        => null,
				'class'      => self::$containerClass,
				'includecss' => apply_filters(TouchPointWP::HOOK_PREFIX . 'use_css', true, self::class),
				'itemclass'  => self::$itemClass,
				'usequery'   => false
			],
			$params,
			self::SHORTCODE_NEARBY
		);

		global $wp_the_query;
		$q = $wp_the_query;
		if ( ! $q->is_post_type_archive() && ($params['usequery'] === false || $params['usequery'] === 'false')) {
			$q = new WP_Query();
		}
		ob_start();
		self::doInvolvementList($q, $params);
		$render = ob_get_clean();

		if (trim($render) == "") {
			return "<!-- Nothing to show -->";
		}

		return apply_shortcodes($render);
	}

	/**
	 * @param array|string $params
	 * @param string       $content
	 *
	 * @return string
	 * @noinspection PhpUnusedParameterInspection
	 */
	public static function nearbyShortcode($params = [], string $content = ""): string
	{
		TouchPointWP::requireScript("knockout-defer");
		TouchPointWP::requireScript("base-defer");

		if ($params === '') {
			$params = [];
		}

		// standardize parameters
		/** @noinspection PhpRedundantOptionalArgumentInspection */
		$params = array_change_key_case($params, CASE_LOWER);

		// set some defaults
		$params = shortcode_atts(
			[
				'count' => 3,
				'type'  => null
			],
			$params,
			self::SHORTCODE_NEARBY
		);

		// Attempt to infer the type if it doesn't exist.
		if ( ! isset($params['type'])) {
			$params['type'] = is_archive() ? get_queried_object()->name : false;
		}

		// Check that Type parameter exists.
		if ($params['type'] === false) {
			_doing_it_wrong(
				__FUNCTION__,
				"A Post Type is required for the Nearby Involvement Shortcode.",
				TouchPointWP::VERSION
			);

			return "<!-- A Post Type is required for the Nearby Involvement Shortcode. -->";
		}

		$nearbyListId = wp_unique_id('tp-nearby-list-');
		$type         = $params['type'];
		$count        = $params['count'];

		ob_start();
		$loadedPart = get_template_part('list-item', 'involvement-nearby-list');
		if ($loadedPart === false) {
			require TouchPointWP::$dir . "/src/templates/parts/involvement-nearby-list.php";
		}
		$content = ob_get_clean();

		$script = file_get_contents(TouchPointWP::$dir . "/src/js-partials/involvement-nearby-inline.js");

		$script = str_replace('{$nearbyListId}', $nearbyListId, $script);
		$script = str_replace('{$type}', $params['type'], $script);
		$script = str_replace('{$count}', $params['count'], $script);

		/** @noinspection PhpRedundantOptionalArgumentInspection */
		wp_add_inline_script(
			TouchPointWP::SHORTCODE_PREFIX . "knockout-defer",
			$script,
			'after'
		);

		// get any nesting
		return apply_shortcodes($content);
	}


	/**
	 * @param array                        $params
	 * @param Involvement_PostTypeSettings $settings
	 *
	 * @return string
	 */
	protected static final function filterDropdownHtml(array $params, Involvement_PostTypeSettings $settings): string
	{
		// standardize parameters
		/** @noinspection PhpRedundantOptionalArgumentInspection */
		$params = array_change_key_case($params, CASE_LOWER);

		// set some defaults
		$params = shortcode_atts(
			[
				'class'              => "TouchPoint-Involvement filterBar",
				'filters'            => strtolower(implode(",", $settings->filters)),
				'includeMapWarnings' => true
			],
			$params,
			static::SHORTCODE_FILTER
		);

		$filterBarId = $params['id'] ?? wp_unique_id('tp-filter-bar-');

		$filters = explode(',', $params['filters']);

		$class = $params['class'];

		$content = "<div class=\"$class\" id=\"$filterBarId\">";

		$any = __("Any", 'TouchPoint-WP');

		$postType = $settings->postType;

		// Division
		if (in_array('div', $filters)) {
			$exclude = $settings->importDivs;
			if (count(
				    $exclude
			    ) == 1) { // Exclude the imported div if there's only one, as all invs would have that div.
				$mq = ['relation' => "AND"];
				foreach ($exclude as $e) {
					$mq[] = [
						'key'     => TouchPointWP::SETTINGS_PREFIX . 'divId',
						'value'   => substr($e, 3),
						'compare' => 'NOT LIKE'
					];
				}
				$mq = [
					'relation' => "OR",
					[
						'key'     => TouchPointWP::SETTINGS_PREFIX . 'divId', // Allows for programs
						'compare' => 'NOT EXISTS'
					],
					$mq
				];
			} else {
				$mq = [];
			}
			$dvName = TouchPointWP::instance()->settings->dv_name_singular;
			$dvList = get_terms([
				                    'taxonomy'                              => TouchPointWP::TAX_DIV,
				                    'hide_empty'                            => true,
				                    'meta_query'                            => $mq,
				                    TouchPointWP::HOOK_PREFIX . 'post_type' => $postType
			                    ]);
			$dvList = TouchPointWP::orderHierarchicalTerms($dvList, true);
			if (count($dvList) > 1) {
				$content .= "<select class=\"$class-filter\" data-involvement-filter=\"div\">";
				$content .= "<option disabled selected>$dvName</option><option value=\"\">$any</option>";
				$isFirst = true;
				foreach ($dvList as $d) {
					if ($d->parent === 0 || $isFirst) {
						if ( ! $isFirst) {
							$content .= "</optgroup>";
						}
						$content .= "<optgroup label=\"$d->name\">";
					} else {
						$content .= "<option value=\"$d->slug\">$d->name</option>";
					}
					$isFirst = false;
				}
				$content .= "</optgroup></select>";
			}
		}

		// Gender
		if (in_array('genderid', $filters)) {
			$gList   = TouchPointWP::instance()->getGenders();
			$gName   = __("Genders", 'TouchPoint-WP');
			$content .= "<select class=\"$class-filter\" data-involvement-filter=\"genderId\">";
			$content .= "<option disabled selected>$gName</option><option value=\"\">$any</option>";
			foreach ($gList as $g) {
				if ($g->id === 0) {  // skip unknown
					continue;
				}

				$name    = $g->name;
				$id      = $g->id;
				$content .= "<option value=\"$id\">$name</option>";
			}
			$content .= "</select>";
		}

		// Resident Codes
		if (in_array('rescode', $filters)) {
			$rcName = TouchPointWP::instance()->settings->rc_name_singular;
			$rcList = get_terms(
				[
					'taxonomy'                              => TouchPointWP::TAX_RESCODE,
					'hide_empty'                            => true,
					TouchPointWP::HOOK_PREFIX . 'post_type' => $postType
				]
			);
			if (is_array($rcList) && count($rcList) > 1) {
				$content .= "<select class=\"$class-filter\" data-involvement-filter=\"rescode\">";
				$content .= "<option disabled selected>$rcName</option><option value=\"\">$any</option>";

				foreach ($rcList as $g) {
					$name    = $g->name;
					$id      = $g->slug;
					$content .= "<option value=\"$id\">$name</option>";
				}

				$content .= "</select>";
			}
		}

		// Campuses
		if (in_array('campus', $filters) && TouchPointWP::instance()->settings->enable_campuses === "on") {
			$cName = TouchPointWP::instance()->settings->camp_name_singular;
			if (strtolower($cName) == "language") {
				$cName = __("Language", 'TouchPoint-WP');
			}
			$cList = get_terms(
				[
					'taxonomy'                              => TouchPointWP::TAX_CAMPUS,
					'hide_empty'                            => true,
					TouchPointWP::HOOK_PREFIX . 'post_type' => $postType
				]
			);
			if (is_array($cList) && count($cList) > 1) {
				$content .= "<select class=\"$class-filter\" data-involvement-filter=\"campus\">";
				$content .= "<option disabled selected>$cName</option><option value=\"\">$any</option>";

				foreach ($cList as $g) {
					$name    = $g->name;
					$id      = $g->slug;
					$content .= "<option value=\"$id\">$name</option>";
				}

				$content .= "</select>";
			}
		}

		// Day of Week
		if (in_array('weekday', $filters)) {
			$wdName = __("Weekday", 'TouchPoint-WP');
			$wdList = get_terms(
				[
					'taxonomy'                              => TouchPointWP::TAX_WEEKDAY,
					'hide_empty'                            => true,
					'orderby'                               => 'id',
					TouchPointWP::HOOK_PREFIX . 'post_type' => $postType
				]
			);
			if (is_array($wdList) && count($wdList) > 1) {
				$content .= "<select class=\"$class-filter\" data-involvement-filter=\"weekday\">";
				$content .= "<option disabled selected>$wdName</option><option value=\"\">$any</option>";
				foreach ($wdList as $d) {
					$content .= "<option value=\"$d->slug\">" . _x(
							$d->name,
							'e.g. event happens weekly on...',
							'TouchPoint-WP'
						) . "</option>";
				}
				$content .= "</select>";
			}
		}

		// Time of Day
		/** @noinspection SpellCheckingInspection */
		if (in_array('timeofday', $filters)) {
			$todName = __("Time of Day", 'TouchPoint-WP');
			$todList = get_terms(
				[
					'taxonomy'                              => TouchPointWP::TAX_DAYTIME,
					'hide_empty'                            => true,
					'orderby'                               => 'id',
					TouchPointWP::HOOK_PREFIX . 'post_type' => $postType
				]
			);
			if (is_array($todList) && count($todList) > 1) {
				$content .= "<select class=\"$class-filter\" data-involvement-filter=\"timeOfDay\">";
				$content .= "<option disabled selected>$todName</option><option value=\"\">$any</option>";
				foreach ($todList as $t) {
					$label   = _x($t->name, 'Time of Day', 'TouchPoint-WP');
					$content .= "<option value=\"$t->slug\">$label</option>";
				}
				$content .= "</select>";
			}
		}

		// Marital Status
		if (in_array('inv_marital', $filters)) {
			$status  = __("Marital Status", 'TouchPoint-WP');
			$single  = _x("Mostly Single", "Marital status for a group of people", 'TouchPoint-WP');
			$married = _x("Mostly Married", "Marital status for a group of people", 'TouchPoint-WP');
			$content .= "<select class=\"$class-filter\" data-involvement-filter=\"inv_marital\">";
			$content .= "<option disabled selected>$status</option>";
			$content .= "<option value=\"\">$any</option>";
			$content .= "<option value=\"mostly_single\">$single</option>";
			$content .= "<option value=\"mostly_married\">$married</option>";
			$content .= "</select>";
		}

		// Age Groups
		if (in_array('agegroup', $filters)) {
			$agName = __("Age", 'TouchPoint-WP');
			$agList = get_terms([
				                    'taxonomy'                              => TouchPointWP::TAX_AGEGROUP,
				                    'hide_empty'                            => true,
				                    'orderby'                               => 't.id',
				                    TouchPointWP::HOOK_PREFIX . 'post_type' => $postType
			                    ]);
			if (is_array($agList) && count($agList) > 1) {
				$content .= "<select class=\"$class-filter\" data-involvement-filter=\"agegroup\">";
				$content .= "<option disabled selected>$agName</option><option value=\"\">$any</option>";
				foreach ($agList as $a) {
					$content .= "<option value=\"$a->slug\">$a->name</option>";
				}
				$content .= "</select>";
			}
		}

		if ($params['includeMapWarnings']) {
			$content .= "<p class=\"TouchPointWP-map-warnings\">";
			$content .= sprintf(
				"<span class=\"TouchPointWP-map-warning-visibleOnly\" style=\"display:none;\">%s  </span>",
				sprintf(
					__("The %s listed are only those shown on the map.", 'TouchPoint-WP'),
					$settings->namePlural
				)
			);
			$content .= sprintf(
				"<span class=\"TouchPointWP-map-warning-zoomOrReset\" style=\"display:none;\">%s  </span>",
				sprintf(
				// translators: %s is the link to "reset the map"
					__("Zoom out or %s to see more.", 'TouchPoint-WP'),
					sprintf(
						"<a href=\"#\" class=\"TouchPointWP-map-resetLink\">%s</a>",
						_x("reset the map", "Zoom out or reset the map to see more.", 'TouchPoint-WP')
					)
				)
			);
			$content .= "</p>";
		}

		$content .= "</div>";

		return $content;
	}

	/**
	 * Create an Involvement object from an object from a WP_Post object.
	 *
	 * @param WP_Post $post
	 *
	 * @return Involvement
	 *
	 * @throws TouchPointWP_Exception If the involvement can't be created from the post, an exception is thrown.
	 */
	public static function fromPost(WP_Post $post): Involvement
	{
		$iid = intval($post->{self::INVOLVEMENT_META_KEY});

		if ( ! isset(self::$_instances[$iid])) {
			self::$_instances[$iid] = new Involvement($post);
		}

		return self::$_instances[$iid];
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
		if (count($uri['path']) < 3) {
			return false;
		}

		switch (strtolower($uri['path'][2])) {
			case "join":
				self::ajaxInvJoin();
				exit;

			case "contact":
				self::ajaxContact();
				exit;

			case "nearby":
				TouchPointWP::doCacheHeaders(TouchPointWP::CACHE_PRIVATE);
				self::ajaxNearby();
				exit;

			case "force-sync":
				TouchPointWP::doCacheHeaders(TouchPointWP::CACHE_NONE);
				echo self::updateFromTouchPoint(true);
				exit;
		}

		return false;
	}


	/**
	 * Handles the API call to get nearby involvements (probably small groups)
	 */
	public static function ajaxNearby(): void
	{
		header('Content-Type: application/json');

		$settings = self::getSettingsForPostType($_GET['type']);

		if ( ! $settings) {
			http_response_code(Http::NOT_FOUND);
			echo json_encode([
				                 "invList"    => [],
				                 "error"      => "This involvement type doesn't exist.",
				                 "error_i18n" => __("This involvement type doesn't exist.", 'TouchPoint-WP')
			                 ]);
			exit;
		}

		if ( ! $settings->useGeo) {
			http_response_code(Http::EXPECTATION_FAILED);
			echo json_encode([
				                 "invList"    => [],
				                 "error"      => "This involvement type doesn't have geographic locations enabled.",
				                 "error_i18n" => __(
					                 "This involvement type doesn't have geographic locations enabled.",
					                 'TouchPoint-WP'
				                 )
			                 ]);
			exit;
		}

		$r = [];

		if ($_GET['lat'] === "null" || $_GET['lng'] === "null" ||
		    $_GET['lat'] === null || $_GET['lng'] === null) {
			$geoObj = TouchPointWP::instance()->geolocate();

			if ($geoObj === false) {
				http_response_code(Http::PRECONDITION_FAILED);
				echo json_encode([
					                 "invList"    => [],
					                 "error"      => "Could not locate.",
					                 "error_i18n" => __("Could not locate.", 'TouchPoint-WP'),
					                 "geo"        => false
				                 ]);
				exit;
			}

			$_GET['lat'] = $geoObj->lat;
			$_GET['lng'] = $geoObj->lng;

			$r['geo'] = $geoObj;
		} else {
			$geoObj = TouchPointWP::instance()->reverseGeocode($_GET['lat'], $_GET['lng']);

			if ($geoObj !== false) {
				$r['geo'] = $geoObj;
			}
		}

		$invs = self::getInvsNear($_GET['lat'], $_GET['lng'], $settings->postType, $_GET['limit']);

		if ($invs === null) {
			http_response_code(Http::NOT_FOUND);
			echo json_encode([
				                 "invList"    => [],
				                 "error"      => sprintf("No %s Found.", $settings->namePlural),
				                 "error_i18n" => sprintf(__("No %s Found.", "TouchPoint-WP"), $settings->namePlural)
			                 ]);
			exit;
		}

		$errorMessage = null;
		foreach ($invs as $g) {
			try {
				$inv        = self::fromObj($g);
				$g->name    = html_entity_decode($inv->name);
				$g->invType = $settings->postTypeWithoutPrefix();
				$g->path    = get_permalink($inv->post_id);
			} catch (TouchPointWP_Exception $ex) {
				http_response_code(Http::SERVER_ERROR);
				$errorMessage = $ex->getMessage();
			}
		}

		$r['invList'] = $invs;

		if ($errorMessage !== null) {
			$r['error'] = $errorMessage;
		}

		echo json_encode($r);
		exit;
	}


	/**
	 * Gets an array of ID/Distance pairs for a given lat/lng.
	 *
	 * Math from https://stackoverflow.com/a/574736/2339939
	 *
	 * @param float  $lat Longitude
	 * @param float  $lng Longitude
	 * @param string $postType The Post Type to return.
	 * @param int    $limit Number of results to return.  0-100 inclusive.
	 *
	 * @return object[]|null  An array of database query result objects, or null if the location isn't provided or
	 *     valid.
	 */
	private static function getInvsNear(float $lat, float $lng, string $postType, int $limit = 3): ?array
	{
		if ($lat > 90 || $lat < -90 ||
		    $lng > 180 || $lng < -180
		) {
			return null;
		}

		$limit = min(max($limit, 0), 100);

		global $wpdb;
		$settingsPrefix = TouchPointWP::SETTINGS_PREFIX;
		/** @noinspection SqlResolve */
		$q = $wpdb->prepare(
			"
            SELECT l.Id as post_id,
                   l.post_title as name,
                   l.post_type as invType,
                   CAST(pmInv.meta_value AS UNSIGNED) as invId,
                   ROUND(3959 * acos(cos(radians(%s)) * cos(radians(lat)) * cos(radians(lng) - radians(%s)) +
                                sin(radians(%s)) * sin(radians(lat))), 1) AS distance
            FROM (SELECT DISTINCT p.Id,
                         p.post_title,
                         p.post_type,
                         CAST(pmLat.meta_value AS DECIMAL(10, 7)) as lat,
                         CAST(pmLng.meta_value AS DECIMAL(10, 7)) as lng
                  FROM $wpdb->posts as p
                           JOIN
                       $wpdb->postmeta as pmLat ON p.ID = pmLat.post_id AND pmLat.meta_key = '{$settingsPrefix}geo_lat'
                           JOIN
                       $wpdb->postmeta as pmLng ON p.ID = pmLng.post_id AND pmLng.meta_key = '{$settingsPrefix}geo_lng'
                WHERE p.post_type = %s
                 ) as l
                    JOIN $wpdb->postmeta as pmInv ON l.ID = pmInv.post_id AND pmInv.meta_key = '{$settingsPrefix}invId'
            ORDER BY distance LIMIT %d
            ",
			$lat,
			$lng,
			$lat,
			$postType,
			$limit
		);

		$r = $wpdb->get_results($q, 'OBJECT');
		foreach ($r as $iObj) {
			$cacheGroup = $iObj->invId . "_" . get_locale();
			$sch        = wp_cache_get(self::SCHED_STRING_CACHE_KEY, $cacheGroup);
			if ( ! $sch) {
				try {
					$i   = self::fromInvId($iObj->invId);
					$sch = $i !== null ? $i->scheduleString() : null;
				} catch (TouchPointWP_Exception $e) {
					$sch = null;
				}
			}
			$iObj->schedule = $sch;
		}

		return $r;
	}


	/**
	 * Create a Involvement object from an object from a database query.
	 *
	 * @param object $obj A database object from which an Involvement object should be created.
	 *
	 * @return Involvement
	 * @throws TouchPointWP_Exception
	 */
	private static function fromObj(object $obj): Involvement
	{
		$iid = intval($obj->invId);

		if ( ! isset(self::$_instances[$iid])) {
			self::$_instances[$iid] = new Involvement($obj);
		}

		return self::$_instances[$iid];
	}

	/**
	 * Create an Involvement object from an Involvement ID.  Only Involvements that are already imported as Posts are
	 * currently available.
	 *
	 * @param int $iid A database object from which an Involvement object should be created.
	 *
	 * @return ?Involvement  Null if the involvement is not imported/available.
	 * @throws TouchPointWP_Exception
	 */
	private static function fromInvId(int $iid): ?Involvement
	{
		if ( ! isset(self::$_instances[$iid])) {
			$post                   = self::getWpPostByInvolvementId(Involvement::getPostTypes(), $iid);
			self::$_instances[$iid] = new Involvement($post);
		}

		return self::$_instances[$iid];
	}


	/**
	 * Loads the module and initializes the other actions.
	 *
	 * @return bool
	 */
	public static function load(): bool
	{
		if (self::$_isLoaded) {
			return true;
		}

		self::$_isLoaded = true;

		add_action(TouchPointWP::INIT_ACTION_HOOK, [self::class, 'init']);

		//////////////////
		/// Shortcodes ///
		//////////////////

		if ( ! shortcode_exists(self::SHORTCODE_MAP)) {
			add_shortcode(self::SHORTCODE_MAP, [self::class, "mapShortcode"]);
		}

		if ( ! shortcode_exists(self::SHORTCODE_FILTER)) {
			add_shortcode(self::SHORTCODE_FILTER, [self::class, "filterShortcode"]);
		}

		if ( ! shortcode_exists(self::SHORTCODE_LIST)) {
			add_shortcode(self::SHORTCODE_LIST, [self::class, "listShortcode"]);
		}

		if ( ! shortcode_exists(self::SHORTCODE_NEARBY)) {
			add_shortcode(self::SHORTCODE_NEARBY, [self::class, "nearbyShortcode"]);
		}

		if ( ! shortcode_exists(self::SHORTCODE_ACTIONS)) {
			add_shortcode(self::SHORTCODE_ACTIONS, [self::class, "actionsShortcode"]);
		}

		///////////////
		/// Syncing ///
		///////////////

		// Do an update if needed.
		add_action(TouchPointWP::INIT_ACTION_HOOK, [self::class, 'checkUpdates']);

		// Setup cron for updating Involvements daily.
		add_action(self::CRON_HOOK, [self::class, 'updateCron']);
		if ( ! wp_next_scheduled(self::CRON_HOOK)) {
			// Runs at 6am EST (11am UTC), hypothetically after TouchPoint runs its Morning Batches.
			wp_schedule_event(
				date('U', strtotime('tomorrow') + 3600 * 11),
				'daily',
				self::CRON_HOOK
			);
		}

		return true;
	}

	/**
	 * Run the updating cron task.  Fail quietly to not disturb the visitor experience if using WP default cron
	 * handling.
	 *
	 * @return void
	 */
	public static function updateCron(): void
	{
		try {
			self::updateFromTouchPoint();
		} catch (Exception $ex) {
		}
	}

	/**
	 * Returns distance to the given involvement from the $compareGeo point.
	 *
	 * Math thanks to https://stackoverflow.com/a/574736/2339939
	 *
	 * @param bool $useHiForFalse Set to true if a high number should be used for distances that can't be computed.
	 *                  Used for sorting by distance with the closest first.
	 *
	 * @return float
	 */
	public function getDistance(bool $useHiForFalse = false)
	{
		if ( ! isset(self::$compareGeo->lat) || ! isset(self::$compareGeo->lng) ||
		     ! isset($this->geo->lat) || ! isset($this->geo->lng) ||
		     $this->geo->lat === null || $this->geo->lng === null) {
			return $useHiForFalse ? 25000 : false;
		}

		return Utilities\Geo::distance(
			$this->geo->lat,
			$this->geo->lng,
			self::$compareGeo->lat,
			self::$compareGeo->lng
		);
	}


	/**
	 * @param object $geo Set a geo object to use for distance comparisons.  Needs to be called before getDistance()
	 */
	public static function setComparisonGeo(object $geo): void
	{
		if (get_class($geo) === stdClass::class) {
			self::$compareGeo = $geo;
		}
	}


	/**
	 * Put SmallGroup objects in order of increasing distance.  Closed groups go to the end.
	 *
	 * @param Involvement $a
	 * @param Involvement $b
	 *
	 * @return int
	 */
	public static function sort(Involvement $a, Involvement $b): int
	{
		$ad = $a->getDistance(true);
		if ($a->acceptingNewMembers() !== true) {
			$ad += 30000;
		}
		$bd = $b->getDistance(true);
		if ($b->acceptingNewMembers() !== true) {
			$bd += 30000;
		}
		if ($ad == $bd) {
			return strcasecmp($a->name, $b->name);
		}

		return $ad <=> $bd;
	}


	/**
	 * Put Post objects that represent Small Groups in order of increasing distance.
	 *
	 * @param WP_Post $a
	 * @param WP_Post $b
	 *
	 * @return int
	 */
	public static function sortPosts(WP_Post $a, WP_Post $b): int
	{
		try {
			$a = self::fromPost($a);
			$b = self::fromPost($b);

			return self::sort($a, $b);
		} catch (TouchPointWP_Exception $ex) {
			return $a <=> $b;
		}
	}


	/**
	 * Register scripts and styles to be used on display pages.
	 */
	public static function registerScriptsAndStyles(): void
	{
	}

	/**
	 * @param string|array $params
	 * @param string       $content
	 *
	 * @return string
	 *
	 * @noinspection PhpUnusedParameterInspection WordPress API
	 */
	public static function mapShortcode($params = [], string $content = ""): string
	{
		if ( ! self::$_hasUsedMap) {
			if (is_string($params)) {
				$params = explode(",", $params);
			}

			self::$_hasUsedMap = true;

			// standardize parameters
			/** @noinspection PhpRedundantOptionalArgumentInspection */
			$params = array_change_key_case($params, CASE_LOWER);

			TouchPointWP::requireScript("googleMaps");
			TouchPointWP::requireScript("base-defer");

			// set some defaults
			$params = shortcode_atts(
				[
					'class' => 'TouchPoint-smallgroup map',
					'all'   => null
				],
				$params,
				self::SHORTCODE_MAP
			);

			$mapDivId = $params['id'] ?? wp_unique_id('tp-map-');

			if ($params['all'] === null) {
				$params['all'] = is_archive();
			}

			if ($params['all']) {
				self::requireAllObjectsInJs();
				self::$_hasArchiveMap = true;
			}

			$script = file_get_contents(TouchPointWP::$dir . "/src/js-partials/involvement-map-inline.js");

			$script = str_replace('{$mapDivId}', $mapDivId, $script);

			wp_add_inline_script(
				TouchPointWP::SHORTCODE_PREFIX . "googleMaps",
				$script
			);

			// TODO move the style to a css file... or something.
			$content = "<div class=\"TouchPoint-Involvement-Map\" style=\"height: 100%; width: 100%; position: absolute; top: 0; left: 0; \" id=\"$mapDivId\"></div>";
		} else {
			$content = "<!-- Error: Involvement map can only be used once per page. -->";
		}

		return $content;
	}


	/**
	 * Indicates whether a map of a single Involvement can be displayed.
	 *
	 * @return bool
	 */
	public function hasGeo(): bool
	{
		if ( ! $this->settings()->useGeo) {
			return false;
		}

		return $this->geo !== null && $this->geo->lat !== null && $this->geo->lng !== null;
	}

	public function asGeoIFace(string $type = "unknown"): ?object
	{
		if ($this->hasGeo()) {
			return (object)[
				'lat'   => $this->geo->lat,
				'lng'   => $this->geo->lng,
				'human' => $this->name,
				'type'  => $type
			];
		}

		return null;
	}


	/**
	 * Update posts that are based on an involvement.
	 *
	 * @param Involvement_PostTypeSettings $typeSets
	 * @param string|int                   $divs
	 * @param bool                         $verbose
	 *
	 * @return false|int  False on failure.  Otherwise, the number of updates.
	 */
	final protected static function updateInvolvementPostsForType(
		Involvement_PostTypeSettings $typeSets,
		$divs,
		bool $verbose
	) {
		$siteTz = wp_timezone();

		set_time_limit(180);

		$qOpts = [];

		// Leader member types
		$qOpts['leadMemTypes'] = implode(',', $typeSets->leaderTypeInts());

		if ($typeSets->useGeo) {
			// Host member types
			$qOpts['hostMemTypes'] = implode(',', $typeSets->hostTypeInts());
		}

		try {
			$response = TouchPointWP::instance()->apiGet(
				"InvsForDivs",
				array_merge($qOpts, ['divs' => $divs]),
				180
			);
		} catch (TouchPointWP_Exception $e) {
			return false;
		}
		unset($qOpts);

		$invData = $response->invs ?? []; // null coalesce for case where there is no data.

		if ($verbose) {
			echo "API returned " . count($invData) . " objects";
		}

		/** @var int[] $postsToKeep An array collecting Post IDs that shouldn't be deleted. */
		$postsToKeep = [];

		try {
			$now       = new DateTimeImmutable(null, $siteTz);
			$aYear     = new DateInterval('P1Y');
			$nowPlus1Y = $now->add($aYear);
			unset($aYear);
		} catch (Exception $e) {
			return false;
		}

		foreach ($invData as $inv) {
			set_time_limit(15);

			if ($verbose) {
				var_dump($inv);
			}


			////////////////////////
			// Standardize Inputs //
			////////////////////////

			// Start and end dates
			if ($inv->firstMeeting !== null) {
				try {
					$inv->firstMeeting = new DateTimeImmutable($inv->firstMeeting, $siteTz);
				} catch (Exception $e) {
					$inv->firstMeeting = null;
				}
			}
			if ($inv->lastMeeting !== null) {
				try {
					$inv->lastMeeting = new DateTimeImmutable($inv->lastMeeting, $siteTz);
				} catch (Exception $e) {
					$inv->lastMeeting = null;
				}
			}

			// Meeting and Schedule date/time strings as DateTimeImmutables
			foreach ($inv->schedules as $i => $s) {
				try {
					$s->next = new DateTimeImmutable($s->next, $siteTz);
				} catch (Exception $e) {
					unset($inv->schedules[$i]);
				}
			}
			foreach ($inv->meetings as $i => $m) {
				try {
					$m->dt = new DateTimeImmutable($m->dt, $siteTz);
				} catch (Exception $e) {
					unset($inv->meetings[$i]);
				}
			}

			// Registration start
			if ($inv->regStart !== null) {
				try {
					$inv->regStart = new DateTimeImmutable($inv->regStart, $siteTz);
				} catch (Exception $e) {
					$inv->regStart = null;
				}
			}

			// Registration end
			if ($inv->regEnd !== null) {
				try {
					$inv->regEnd = new DateTimeImmutable($inv->regEnd, $siteTz);
				} catch (Exception $e) {
					$inv->regEnd = null;
				}
			}


			////////////////
			// Exclusions //
			////////////////

			// 'continue' causes involvement to be deleted (or not created).

			// Filter by end dates to stay relevant
			if ($inv->lastMeeting !== null && $inv->lastMeeting < $now) { // last meeting already happened.
				if ($verbose) {
					echo "<p>Stopping processing because all meetings are in the past.  Involvement will be deleted from WordPress.</p>";
				}
				continue; // Stop processing this involvement.  This will cause it to be removed if it exists already.
			}

			if (in_array("closed", $typeSets->excludeIf) && ! ! $inv->closed) {
				if ($verbose) {
					echo "<p>Stopping processing because Involvements with Closed Registrations are excluded.  Involvement will be deleted from WordPress.</p>";
				}
				continue;
			}

			if (in_array("child", $typeSets->excludeIf) && $inv->parentInvId > 0) {
				if ($verbose) {
					echo "<p>Stopping processing because Involvements with parents are excluded.  Involvement will be deleted from WordPress.</p>";
				}
				continue;
			}

			if (in_array("notWeekly", $typeSets->excludeIf) && $inv->notWeekly) {
				if ($verbose) {
					echo "<p>Stopping processing because Not-Weekly Involvements are excluded.  Involvement will be deleted from WordPress.</p>";
				}
				continue;
			}

			if (in_array("noRegistration", $typeSets->excludeIf) && intval($inv->regTypeId) === 0) {
				if ($verbose) {
					echo "<p>Stopping processing because Involvements with \"No Online Registration\" are excluded.  Involvement will be deleted from WordPress.</p>";
				}
				continue;
			}

			if (in_array("registrationEnded", $typeSets->excludeIf) &&
			    $inv->regEnd !== null && $inv->regEnd < $now) {
				if ($verbose) {
					echo "<p>Stopping processing because Involvements whose registrations have ended are excluded.  Involvement will be deleted from WordPress.</p>";
				}
				continue;
			}

			if (in_array("unscheduled", $typeSets->excludeIf) && count($inv->occurrences) > 0) {
				$hasSchedule = false;
				foreach ($inv->occurrences as $o) {
					if ($o->type === 'S') {
						$hasSchedule = true;
						break;
					}
				}
				if ( ! $hasSchedule) {
					if ($verbose) {
						echo "<p>Stopping processing because Involvements without schedules are excluded.  Involvement will be deleted from WordPress.</p>";
					}
					continue;
				}
			}


			/////////////////////////
			// Find or Create Post //
			/////////////////////////

			$post = self::getWpPostByInvolvementId($typeSets->postType, $inv->involvementId);

			$titleToUse = $inv->regTitle ?? $inv->name;
			$titleToUse = trim($titleToUse);

			if ($post === null) {
				$post = wp_insert_post(
					[ // create new
						'post_type'  => $typeSets->postType,
						'post_name'  => $titleToUse,
						'meta_input' => [
							self::INVOLVEMENT_META_KEY => $inv->involvementId
						]
					]
				);
				$post = get_post($post);
			}

			if ($post instanceof WP_Error) {
				new TouchPointWP_WPError($post);
				continue;
			}

			if ($post === null) {
				new TouchPointWP_Exception("Post could not be found or created.", 171001);
				continue;
			}

			/** @var $post WP_Post */
			if ($inv->description == null || trim($inv->description) == "") {
				$post->post_content = null;
			} else {
				$post->post_content = Utilities::standardizeHtml($inv->description, "involvement-import");
			}

			// Title & Slug -- slugs should only be updated if there's a reason, like a title change.  Otherwise, they increment.
			if ($post->post_title != $titleToUse || str_contains($post->post_name, "__trashed")) {
				$post->post_title = $titleToUse;
				$post->post_name  = ''; // Slug will regenerate;
			}

			// Parent Post
			if ($typeSets->hierarchical) {
				$parent = 0;
				if ($inv->parentInvId > 0) {
					$parent = self::getWpPostByInvolvementId($typeSets->postType, $inv->parentInvId);
					$parent = $parent->ID;

					if ($verbose) {
						echo "<p>Parent Post: $parent</p>";
					}
				}

				$post->post_parent = $parent;
			}

			// Status & Submit
			$post->post_status = 'publish';
			wp_update_post($post);

			update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "locationName", $inv->location);
			update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "memberCount", $inv->memberCount);
			update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "genderId", $inv->genderId);
			update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "groupFull", ! ! $inv->groupFull);
			update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "groupClosed", ! ! $inv->closed);
			update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "hasRegQuestions", ! ! $inv->hasRegQuestions);
			update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "regTypeId", intval($inv->regTypeId));


			// Registration start
			if ($inv->regStart === null) {
				delete_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "regStart");
			} else {
				update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "regStart", $inv->regStart);
			}

			// Registration end
			if ($inv->regEnd === null) {
				delete_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "regEnd");
			} else {
				update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "regEnd", $inv->regEnd);
			}

			// Update image, if appropriate.
			$imageUrl = "";
			if (!!$typeSets->useImages) {
				$imageUrl = $inv->imageUrl;
			}
			Utilities::updatePostImageFromUrl($post->ID, $imageUrl, $post->post_title);


			////////////////////
			//// SCHEDULING ////
			////////////////////

			// Establish a container
			if ( ! is_array($inv->meetings)) {
				$inv->meetings = [];
			}

			// Determine schedule characteristics for terms
			$upcomingDateTimes = self::computeCommonOccurrences($inv->meetings, $inv->schedules);
			$uniqueTimeStrings = [];
			$timeTerms         = [];
			$days              = [];

			foreach ($upcomingDateTimes as $dtString => $dt) {
				$weekday = "d" . $dtString[0];

				// days
				if ( ! isset($days[$weekday])) {
					$days[$weekday] = [];
				}
				$days[$weekday][] = $dt['example'];

				// times
				$timeStr = substr($dtString, 2);
				if ( ! in_array($timeStr, $uniqueTimeStrings)) {
					$uniqueTimeStrings[] = $timeStr;
					$timeTerm            = Utilities::getTimeOfDayTermForTime_noI18n($dt['example']);
					if ( ! in_array($timeTerm, $timeTerms)) {
						$timeTerms[] = $timeTerm;
					}
				}
				unset($timeStr, $weekday);
			}

			// Start and end dates
			$tense = TouchPointWP::TAX_TENSE_PRESENT;
			if ($inv->firstMeeting !== null && $inv->firstMeeting < $now) { // First meeting already happened.
				$inv->firstMeeting = null; // We don't need to list info from the past.
			}
			if ($inv->firstMeeting === null) {
				delete_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "firstMeeting");
			} else {
				update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "firstMeeting", $inv->firstMeeting);
			}

			if ($inv->lastMeeting !== null && $inv->lastMeeting > $nowPlus1Y) { // Last mtg is > 1yr away
				$inv->lastMeeting = null; // For all practical purposes: it's not ending.
			}
			if ($inv->lastMeeting === null) {
				delete_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "lastMeeting");
			} else {
				update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "lastMeeting", $inv->lastMeeting);
			}

			// Clear Cached Schedule String
			$cacheGroup = $inv->involvementId . "_" . get_locale();
			wp_cache_delete(self::SCHED_STRING_CACHE_KEY, $cacheGroup);

			// Tense
			if ($inv->firstMeeting !== null) {
				$tense = TouchPointWP::TAX_TENSE_FUTURE;
			}
			/** @noinspection PhpRedundantOptionalArgumentInspection */
			wp_set_post_terms($post->ID, [$tense], TouchPointWP::TAX_TENSE, false);

			// Update meetings and schedules
			update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "meetings", $inv->meetings);
			update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "schedules", $inv->schedules);

			// Day of week taxonomy
			$dayTerms = [];
			foreach ($days as $k => $d) {
				$dayTerms[] = Utilities::getDayOfWeekShortForNumber_noI18n(intval($k[1]));
			}
			/** @noinspection PhpRedundantOptionalArgumentInspection */
			wp_set_post_terms($post->ID, $dayTerms, TouchPointWP::TAX_WEEKDAY, false);

			// Time of day taxonomy
			/** @noinspection PhpRedundantOptionalArgumentInspection */
			wp_set_post_terms($post->ID, $timeTerms, TouchPointWP::TAX_DAYTIME, false);


			////////////////
			//// People ////
			////////////////

			// Leaders & Members are now imported through the Person sync.

			////////////////////
			//// Geographic ////
			////////////////////

			// Handle locations for involvement types that are geo-enabled
			if ($typeSets->useGeo) {
				// Handle locations
				if (property_exists($inv, "lat") && $inv->lat !== null &&
				    property_exists($inv, "lng") && $inv->lng !== null) {
					update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "geo_lat", $inv->lat);
					update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "geo_lng", $inv->lng);
				} else {
					delete_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "geo_lat");
					delete_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "geo_lng");
				}

				// Handle Resident Code
				if (property_exists($inv, "resCodeName") && $inv->resCodeName !== null) {
					/** @noinspection PhpRedundantOptionalArgumentInspection */
					wp_set_post_terms($post->ID, [$inv->resCodeName], TouchPointWP::TAX_RESCODE, false);
				} else {
					/** @noinspection PhpRedundantOptionalArgumentInspection */
					wp_set_post_terms($post->ID, [], TouchPointWP::TAX_RESCODE, false);
				}
			}


			////////////////
			//// Campus ////
			////////////////

			if (TouchPointWP::instance()->settings->enable_campuses === "on") {
				if (property_exists($inv, "campusName") && $inv->campusName !== null) {
					/** @noinspection PhpRedundantOptionalArgumentInspection */
					wp_set_post_terms($post->ID, [$inv->campusName], TouchPointWP::TAX_CAMPUS, false);
				} else {
					/** @noinspection PhpRedundantOptionalArgumentInspection */
					wp_set_post_terms($post->ID, [], TouchPointWP::TAX_CAMPUS, false);
				}
			}


			/////////////////////
			//// Demographic ////
			/////////////////////

			// Handle Marital Status
			$maritalTax = [];
			if ($inv->marital_denom > 4) { // only include involvements with at least 4 people with known marital statuses.
				$marriedProportion = (float)$inv->marital_married / $inv->marital_denom;
				if ($marriedProportion > 0.7) {
					$maritalTax[] = "mostly_married";
				} elseif ($marriedProportion < 0.3) {
					$maritalTax[] = "mostly_single";
				}
			}
			/** @noinspection PhpRedundantOptionalArgumentInspection */
			wp_set_post_terms($post->ID, $maritalTax, TouchPointWP::TAX_INV_MARITAL, false);

			// Handle Age Groups
			if ($inv->age_groups === null) {
				/** @noinspection PhpRedundantOptionalArgumentInspection */
				wp_set_post_terms($post->ID, [], TouchPointWP::TAX_AGEGROUP, false);
			} else {
				/** @noinspection PhpRedundantOptionalArgumentInspection */
				wp_set_post_terms($post->ID, $inv->age_groups, TouchPointWP::TAX_AGEGROUP, false);
			}


			///////////////////
			//// Divisions ////
			///////////////////

			// Handle divisions
			$divs = [];
			if ($inv->divs !== null) {
				foreach ($inv->divs as $d) {
					$tid = TouchPointWP::getDivisionTermIdByDivId($d);
					if ( ! ! $tid) {
						$divs[] = $tid;
					}
				}
			}
			/** @noinspection PhpRedundantOptionalArgumentInspection */
			wp_set_post_terms($post->ID, $divs, TouchPointWP::TAX_DIV, false);

			if ($verbose) {
				echo "<p>Division Terms:</p>";
				var_dump($divs);
			}

			$postsToKeep[] = $post->ID;

			if ($verbose) {
				echo "<hr />";
			}

			unset($post);
		}
		unset($inv);

		//////////////////
		//// Removals ////
		//////////////////

		// Delete posts that are no longer current
		$q        = new WP_Query([
			                         'post_type'    => $typeSets->postType,
			                         'nopaging'     => true,
			                         'post__not_in' => $postsToKeep
		                         ]);
		$removals = 0;
		foreach ($q->get_posts() as $post) {
			set_time_limit(10);
			wp_delete_post($post->ID, true);
			$removals++;
		}

		return $removals + count($invData);
	}

	/**
	 * Replace the date with the schedule summary
	 *
	 * @param $theDate
	 * @param $format
	 * @param $post
	 *
	 * @return mixed
	 *
	 * @noinspection PhpUnusedParameterInspection WordPress API
	 */
	public static function filterPublishDate($theDate, $format, $post = null): string
	{
		if ($post == null) {
			$post = get_the_ID();
		}

		$invTypes = Involvement_PostTypeSettings::getPostTypes();

		if (in_array(get_post_type($post), $invTypes)) {
			if (is_numeric($post)) {
				$post = get_post($post);
			}

			try {
				$inv = self::fromPost($post);
			} catch (TouchPointWP_Exception $e) {
				return $theDate;
			}

			$theDate = $inv->scheduleString() ?? "";
		}

		return $theDate;
	}

	/**
	 * Replace the author with the leaders
	 *
	 * @param $author Author's display name
	 *
	 * @return string
	 *
	 * @noinspection PhpUnusedParameterInspection WordPress API
	 */
	public static function filterAuthor($author): string
	{
		$postId = get_the_ID();

		$invTypes = Involvement_PostTypeSettings::getPostTypes();

		if (in_array(get_post_type($postId), $invTypes)) {
			$post = get_post($postId);
			try {
				$i = Involvement::fromPost($post);

				$author = $i->leaders()->__toString();
			} catch (TouchPointWP_Exception $e) {
			}
		}

		return $author;
	}

	/**
	 * Get the leaders of the Involvement
	 *
	 * @return PersonArray
	 */
	public function leaders(): PersonArray
	{
		if ( ! isset($this->_leaders)) {
			$s = $this->settings();

			$q = new PersonQuery(
				[
					'meta_key'     => Person::META_INV_MEMBER_PREFIX . $this->invId,
					'meta_value'   => $s->leaderTypes,
					'meta_compare' => 'IN'
				]
			);

			$this->_leaders = $q->get_results();
		}

		return $this->_leaders;
	}

	/**
	 * Get the hosts of the involvement.  Returns null if not a geo-enabled post type.
	 *
	 * @return ?PersonArray
	 */
	public function hosts(): ?PersonArray
	{
		if ( ! isset($this->_hosts)) {
			$s = $this->settings();

			if ( ! $s->useGeo) {
				return null;
			}

			$q = new PersonQuery(
				[
					'meta_key'     => Person::META_INV_MEMBER_PREFIX . $this->invId,
					'meta_value'   => $s->hostTypes,
					'meta_compare' => 'IN'
				]
			);

			$this->_hosts = $q->get_results();
		}

		return $this->_hosts;
	}

	/**
	 * Get the members of the involvement.  Note that not all members are necessarily synced to WordPress from
	 * TouchPoint.
	 *
	 * @return PersonArray
	 */
	public function members(): ?PersonArray
	{
		if ( ! isset($this->_members)) {
			$q = new PersonQuery(
				[
					'meta_key'     => Person::META_INV_MEMBER_PREFIX . $this->invId,
					'meta_compare' => 'EXISTS'
				]
			);

			$this->_members = $q->get_results();
		}

		return $this->_members;
	}

	/**
	 * Get the settings object that corresponds to the Involvement's Post Type
	 *
	 * @return Involvement_PostTypeSettings|null
	 */
	protected function settings(): ?Involvement_PostTypeSettings
	{
		return self::getSettingsForPostType($this->invType);
	}

	/**
	 * return an object that turns into JSON-LD as an event, compliant with schema.org
	 *
	 * @return ?array
	 */
	public function toJsonLD(): ?array
	{
		if ($this->locationName === null || $this->nextMeeting() === null) {
			// If either of these are missing, Google considers the markup invalid.
			return null;
		}

		$startDate = $this->firstMeeting ?? $this->nextMeeting();

		$fields = [
			"@context"      => "https://schema.org",
			"@type"         => "Event",
			"name"          => $this->name,
			"url"           => get_permalink($this->post_id),
			"location"      => $this->locationName,
			"startDate"     => $startDate->format('c'),
			"eventSchedule" => [
				"@type"            => "Schedule",
				"repeatFrequency"  => "P1W",
				"byDay"            => "https://schema.org/" . $this->nextMeeting()->format('l'),
				"startTime"        => $this->nextMeeting()->format('H:i:s'),
				"scheduleTimezone" => wp_timezone()->getName()
			]
		];

		$desc = wp_trim_words(get_the_excerpt($this->post_id), 20, "...");
		if (strlen($desc) > 10) {
			$fields["description"] = $desc;
		}

		return $fields;
	}

	/**
	 * Get notable attributes, such as gender restrictions, as strings.
	 *
	 * @param array $exclude Attributes listed here will be excluded.  (e.g. if shown for a parent inv, not needed
	 *     here.)
	 *
	 * @return string[]
	 */
	public function notableAttributes(array $exclude = []): array
	{
		$r = [];

		if ($this->scheduleString()) {
			$r[] = $this->scheduleString();
		}

		if ($this->locationName) {
			$r[] = $this->locationName;
		}

		foreach ($this->getDivisionsStrings() as $a) {
			$r[] = $a;
		}

		if ($this->leaders()->count() > 0) {
			$r[] = $this->leaders()->__toString();
		}

		if ($this->genderId != 0) {
			switch ($this->genderId) {
				case 1:
					$r[] = __('Men Only', 'TouchPoint-WP');
					break;
				case 2:
					$r[] = __('Women Only', 'TouchPoint-WP');
					break;
			}
		}

		$canJoin = $this->acceptingNewMembers();
		if (is_string($canJoin)) {
			$r[] = $canJoin;
		}

		$r = array_filter($r, fn($i) => ! in_array($i, $exclude));

		if ($this->hasGeo() &&
		    (
			    $exclude === [] ||
			    (
				    $this->locationName !== null &&
				    ! in_array($this->locationName, $exclude)
			    )
		    )
		) {
			$dist = $this->getDistance();
			if ($dist !== false) {
				$r[] = wp_sprintf(
					_x(
						"%2.1fmi",
						"miles. Unit is appended to a number.  %2.1f is the number, so %2.1fmi looks like '12.3mi'",
						'TouchPoint-WP'
					),
					$dist
				);
			}
		}

		return apply_filters(TouchPointWP::HOOK_PREFIX . "involvement_attributes", $r, $this);
	}

	/**
	 * Returns the html with buttons for actions the user can perform.  This must be called *within* an element with
	 * the
	 * `data-tp-involvement` attribute with the post_id (NOT the Inv ID) as the value.
	 *
	 * @param ?string $context A reference to where the action buttons are meant to be used.
	 * @param string  $btnClass A string for classes to add to the buttons.  Note that buttons can be a or button
	 *     elements.
	 *
	 * @return string
	 */
	public function getActionButtons(string $context = null, string $btnClass = ""): string
	{
		TouchPointWP::requireScript('swal2-defer');
		TouchPointWP::requireScript('base-defer');
		$this->enqueueForJsInstantiation();
		$this->enqueueForJsonLdInstantiation();
		Person::enqueueUsersForJsInstantiation();

		if ($btnClass !== "") {
			$btnClass = " class=\"$btnClass\"";
		}

		$ret = "";
		$count = 0;
		if (self::allowContact($this->invType)) {
			$text = __("Contact Leaders", 'TouchPoint-WP');
			$ret  = "<button type=\"button\" data-tp-action=\"contact\" $btnClass>$text</button> ";
			TouchPointWP::enqueueActionsStyle('inv-contact');
			$count++;
		}

		if ($this->acceptingNewMembers() === true) {
			if ($this->useRegistrationForm()) {
				$text = __('Register', 'TouchPoint-WP');
				switch (get_post_meta($this->post_id, TouchPointWP::SETTINGS_PREFIX . "regTypeId", true)) {
					case 1:  // Join Involvement (skip other options because this option is common)
						break;
					case 5:  // Create Account
						$text = __('Create Account', 'TouchPoint-WP');
						break;
					case 6:  // Choose Volunteer Times (legacy)
					case 22: // Scheduler
						$text = __('Schedule', 'TouchPoint-WP');
						break;
					case 8:  // Online Giving (legacy)
					case 9:  // Online Pledge (legacy)
					case 14: // Manage Recurring Giving (legacy)
						$text = __('Give', 'TouchPoint-WP');
						break;
					case 15: // Manage Subscriptions
						$text = __('Manage Subscriptions', 'TouchPoint-WP');
						break;
					case 18: // Record Family Attendance
						$text = __('Record Attendance', 'TouchPoint-WP');
						break;
					case 21: // Ticketing
						$text = __('Get Tickets', 'TouchPoint-WP');
						break;
				}
				$link = TouchPointWP::instance()->host() . "/OnlineReg/" . $this->invId;
				$ret  .= "<a class=\"btn button\" href=\"$link\" $btnClass>$text</a>  ";
				TouchPointWP::enqueueActionsStyle('inv-register');
			} else {
				$text = __('Join', 'TouchPoint-WP');
				$ret  .= "<button type=\"button\" data-tp-action=\"join\" $btnClass>$text</button>  ";
				TouchPointWP::enqueueActionsStyle('inv-join');
			}
			$count++;
		}

		// Show on map button.  (Only works if map is called before this is.)
		if (self::$_hasArchiveMap && $this->geo !== null) {
			$text = __("Show on Map", 'TouchPoint-WP');
			if ($count > 1) {
				TouchPointWP::requireScript("fontAwesome");
				$ret = "<button type=\"button\" data-tp-action=\"showOnMap\" title=\"$text\" $btnClass><i class=\"fa-solid fa-location-pin\"></i></button>  " . $ret;
			} else {
				$ret = "<button type=\"button\" data-tp-action=\"showOnMap\" $btnClass>$text</button>  " . $ret;
			}
		}

		return apply_filters(TouchPointWP::HOOK_PREFIX . "involvement_actions", $ret, $this, $context, $btnClass);
	}

	public static function getJsInstantiationString(): string
	{
		$queue = static::getQueueForJsInstantiation();

		if (count($queue) < 1) {
			return "\t// No Involvements to instantiate.\n";
		}

		$listStr = json_encode($queue);

		return "\ttpvm.addEventListener('Involvement_class_loaded', function() {
        TP_Involvement.fromObjArray($listStr);\n\t});\n";
	}

	public function getTouchPointId(): int
	{
		return $this->invId;
	}

	/**
	 * Handles the API call to join an involvement through a 'join' button.
	 */
	private static function ajaxInvJoin(): void
	{
		header('Content-Type: application/json');

		$inputData           = TouchPointWP::postHeadersAndFiltering();
		$inputData           = json_decode($inputData);
		$inputData->keywords = [];

		$settings = self::getSettingsForPostType($inputData->invType);
		if ( ! ! $settings) {
			$inputData->keywords    = Utilities::idArrayToIntArray($settings->joinKeywords);
			$inputData->owner       = $settings->taskOwner;
			$lTypes                 = implode(',', $settings->leaderTypes);
			$inputData->leaderTypes = str_replace('mt', '', $lTypes);
		} else {
			http_response_code(Http::NOT_FOUND);
			echo json_encode([
				                 'error'      => "Invalid Post Type.",
				                 'error_i18n' => __("Invalid Post Type.", 'TouchPoint-WP')
			                 ]);
			exit;
		}

		try {
			$data = TouchPointWP::instance()->apiPost('inv_join', $inputData);
		} catch (TouchPointWP_Exception $ex) {
			http_response_code(Http::SERVER_ERROR);
			echo json_encode(['error' => $ex->getMessage()]);
			exit;
		}

		echo json_encode(['success' => $data->success]);
		exit;
	}

	/**
	 * Whether this client should be allowed to contact this set of Involvement leaders.  This is NOT
	 * involvement-specific.
	 *
	 * @param string $invType
	 *
	 * @return bool
	 */
	protected static function allowContact(string $invType): bool
	{
		$allowed = !!apply_filters(TouchPointWP::HOOK_PREFIX . 'allow_contact', true);
		return !!apply_filters(TouchPointWP::HOOK_PREFIX . 'inv_allow_contact', $allowed, $invType);
	}

	/**
	 * Handles the API call to send a message through a contact form.
	 */
	private static function ajaxContact(): void
	{
		header('Content-Type: application/json');

		$inputData           = TouchPointWP::postHeadersAndFiltering();
		$inputData           = json_decode($inputData);
		$inputData->keywords = [];

		$settings = self::getSettingsForPostType($inputData->invType);
		if (!!$settings) {
			if (!self::allowContact($inputData->invType)) {
				echo json_encode([
					'error'      => "Contact Prohibited.",
					'error_i18n' => __("Contact Prohibited.", 'TouchPoint-WP')
				]);
				exit;
			}

			$inputData->keywords    = Utilities::idArrayToIntArray($settings->contactKeywords);
			$inputData->owner       = $settings->taskOwner;
			$lTypes                 = implode(',', $settings->leaderTypes);
			$inputData->leaderTypes = str_replace('mt', '', $lTypes);
		} else {
			http_response_code(Http::NOT_FOUND);
			echo json_encode([
				                 'error'      => "Invalid Post Type.",
				                 'error_i18n' => __("Invalid Post Type.", 'TouchPoint-WP')
			                 ]);
			exit;
		}

		try {
			$data = TouchPointWP::instance()->apiPost('inv_contact', $inputData);
		} catch (TouchPointWP_Exception $ex) {
			http_response_code(Http::SERVER_ERROR);
			echo json_encode(['error' => $ex->getMessage()]);
			exit;
		}

		echo json_encode(['success' => $data->success]);
		exit;
	}
}
