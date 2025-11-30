<?php
/**
 * @package TouchPointWP
 */

namespace tp\TouchPointWP;

if ( ! defined('ABSPATH')) {
	exit(1);
}

if ( ! TOUCHPOINT_COMPOSER_ENABLED) {
	require_once "jsInstantiation.php";
	require_once "jsonLd.php";
	require_once "Interfaces/hierarchical.php";
	require_once "Interfaces/scheduled.php";
	require_once "Interfaces/updatesViaCron.php";
	require_once "Utilities.php";
	require_once "Involvement_PostTypeSettings.php";
	require_once "MeetingArray.php";
}

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use JsonSerializable;
use stdClass;
use tp\TouchPointWP\Interfaces\api;
use tp\TouchPointWP\Interfaces\apiMeeting;
use tp\TouchPointWP\Interfaces\hasGeo;
use tp\TouchPointWP\Interfaces\hierarchical;
use tp\TouchPointWP\Interfaces\module;
use tp\TouchPointWP\Interfaces\scheduled;
use tp\TouchPointWP\Interfaces\updatesViaCron;
use tp\TouchPointWP\Utilities\DateFormats;
use tp\TouchPointWP\Utilities\DateTimeExtended;
use tp\TouchPointWP\Utilities\Http;
use tp\TouchPointWP\Utilities\PersonArray;
use tp\TouchPointWP\Utilities\PersonQuery;
use tp\TouchPointWP\Utilities\StringableArray;
use tp\TouchPointWP\Utilities\Translation;
use TypeError;
use WP_Error;
use WP_Post;
use WP_Query;
use WP_Term;

/**
 * Fundamental object meant to correspond to an Involvement in TouchPoint
 */
class Involvement extends PostTypeCapable implements api, updatesViaCron, hasGeo, module, hierarchical, JsonSerializable, scheduled
{
	use jsInstantiation;
	use jsonLd;

	public const SHORTCODE_MAP = TouchPointWP::SHORTCODE_PREFIX . "Inv-Map";
	public const SHORTCODE_FILTER = TouchPointWP::SHORTCODE_PREFIX . "Inv-Filters";
	public const SHORTCODE_LIST = TouchPointWP::SHORTCODE_PREFIX . "Inv-List";
	public const SHORTCODE_NEARBY = TouchPointWP::SHORTCODE_PREFIX . "Inv-Nearby";
	public const SHORTCODE_ACTIONS = TouchPointWP::SHORTCODE_PREFIX . "Inv-Actions";

	protected const SCHEDULE_STRING_CACHE_EXPIRATION = 3600 * 8; // 8 hours.  Automatically deleted during sync.
	protected const SCHEDULE_STRING_CACHE_GROUP = TouchPointWP::HOOK_PREFIX . "inv_schedule_string";
	protected const ENABLE_SCHEDULE_STRING_CACHE = true;

	protected const MEETING_STRATEGY_NONE = 0;
	protected const MEETING_STRATEGY_SINGLE = 1;
	protected const MEETING_STRATEGY_MULTIPLE = 2;

	public const CRON_HOOK = TouchPointWP::HOOK_PREFIX . "inv_cron_hook";
	public const CRON_OFFSET = 86400 + 3600;

	protected static bool $_hasUsedMap = false;
	protected static bool $_hasArchiveMap = false;
	private static array $_instances = [];
	private static bool $_isLoaded = false;

	public static string $containerClass = 'inv-list';
	public static string $itemClass = 'inv-list-item';

	private static bool $filterJsAdded = false;
	protected ?object $geo = null;
	static protected object $compareGeo;

	protected ?string $locationName = null;
	protected ?DateTimeExtended $_nextMeeting;
	protected ?DateTimeExtended $firstMeeting = null;
	protected ?DateTimeExtended $lastMeeting = null;
	protected ?array $_scheduleStrings = null;
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

	public string $post_excerpt;
	protected ?WP_Post $post = null;

	public object $attributes;
	protected array $divisions;

	/**
	 * Involvement constructor.
	 *
	 * @param object $object WP_Post|object an object representing the involvement's post.
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
			$this->invId   = intval($object->{TouchPointWP::INVOLVEMENT_META_KEY});
			$this->post_id = $object->ID;
			$this->invType = get_post_type($this->post_id);

			if ($this->invId === 0) {
				throw new TouchPointWP_Exception("No Involvement ID provided in the post.", 171002);
			}
		} elseif (gettype($object) === "object") {
			// Sql Object, probably.

			if ( ! property_exists($object, 'post_id')) {
				_doing_it_wrong(
					__FUNCTION__,
					esc_html(
						__('Creating an Involvement object from an object without a post_id is not yet supported.', 'TouchPoint-WP')
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
		if (str_starts_with($this->invType, TouchPointWP::HOOK_PREFIX)) {
			$this->invType = substr($this->invType, strlen(TouchPointWP::HOOK_PREFIX));
		}

		$postTerms = [
			Taxonomies::TAX_RESCODE,
			Taxonomies::TAX_AGEGROUP,
			Taxonomies::TAX_WEEKDAY,
			Taxonomies::TAX_TENSE,
			Taxonomies::TAX_DAYTIME,
			Taxonomies::TAX_INV_MARITAL,
			Taxonomies::TAX_DIV
		];
		if (TouchPointWP::instance()->settings->enable_campuses === "on") {
			$postTerms[] = Taxonomies::TAX_CAMPUS;
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
				if (str_starts_with($ta, TouchPointWP::HOOK_PREFIX)) {
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
				try {
					$this->$k = maybe_unserialize($v[0]);
				} catch (TypeError $e) {
					new TouchPointWP_Exception($e);
				}
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
			register_post_type(
				$type->postType,
				[
					'labels'            => [
						'name'          => $type->namePlural,
						'singular_name' => $type->nameSingular
					],
					'public'            => true,
					'hierarchical'      => $type->hierarchical || $type->importMeetings ||
					                       TouchPointWP::instance()->settings->enable_meeting_cal === 'on',
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
					'delete_with_user'  => false,
					'capability_type'   => 'post',
					'capabilities' => [
						'create_posts'        => 'do_not_allow', // Disable creating new posts
						'edit_posts'          => 'do_not_allow', // Disable editing posts
						'edit_others_posts'   => 'do_not_allow', // Disable editing others' posts
						'delete_posts'        => 'do_not_allow', // Disable deleting posts
						'delete_others_posts' => 'do_not_allow', // Disable deleting others' posts
						'publish_posts'       => 'do_not_allow', // Disable publishing posts
					],
					'map_meta_cap' => true, // Ensure users can still view posts
				]
			);
		}

		// Register default templates for Involvements
		add_filter('template_include', [self::class, 'templateFilter'], 10, 1);

		// Register function to return schedule instead of publishing date
		add_filter('get_the_date', [self::class, 'filterPublishDate'], 10, 3);
		add_filter('get_the_time', [self::class, 'filterPublishDate'], 10, 3);

		// Register function to return leaders instead of authors
		add_filter('the_author', [self::class, 'filterAuthor'], 10, 1);
		add_filter('get_the_author_display_name', [self::class, 'filterAuthor'], 10, 1);
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
	 * @return int False on failure, or the number of groups that were updated or deleted.
	 */
	public final static function updateFromTouchPoint(bool $verbose = false, bool $applyChanges = true): int
	{
		$count   = 0;
		$success = true;

		$startTime = microtime(true);

		// Prevent other threads from attempting for an hour.
		if ($applyChanges) {
			TouchPointWP::instance()->settings->set('inv_cron_last_run', time() - self::CRON_OFFSET + 3600);
		}

		$verbose &= TouchPointWP::currentUserIsAdmin();

		ini_set('max_execution_time', 300);
		ini_set('memory_limit', '512M');
		if (!defined('WP_MAX_MEMORY_LIMIT')) {
			define('WP_MAX_MEMORY_LIMIT', '512M');
		}


		foreach (self::allTypeSettings() as $type) {

			if ($verbose) {
				echo "<h2>$type->namePlural</h2>";
			}

			if (count($type->importDivs) < 1 && $type->postType !== Meeting::POST_TYPE) {
				// Don't update if there aren't any divisions selected yet.
				if ($verbose) {
					print "Skipping $type->namePlural because no divisions are selected.";
				}
				continue;
			}

			// Divisions
			$update = false;
			try {
				TouchPointWP::instance()->setTpWpUserAsCurrent();
				$update = self::updateInvolvementPostsForType($type, $verbose, $applyChanges);
			} catch (Exception $e) {
				if ($verbose) {
					echo "An exception occurred while syncing $type->namePlural: " . $e->getMessage();
				}
				continue;
			} finally {
				TouchPointWP::instance()->unsetTpWpUserAsCurrent();
			}

			if ($update === false) {
				$success = false;
			} else {
				$count += $update;
			}

			if ($verbose) {
				$time = microtime(true) - $startTime;
				echo "<p>$time seconds have elapsed.</p>";
			}
		}
		unset($type);

		if ($applyChanges) {
			if ($success && $count !== 0) {
				TouchPointWP::instance()->settings->set('inv_cron_last_run', time());
			} else {
				TouchPointWP::instance()->settings->set('inv_cron_last_run', 0);
			}
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
		$className = self::class;
		$useTemplates = true;

		/**
		 * Determines whether the plugin's default templates should be used.  Theme developers can return false in this
		 * filter to prevent the default templates from applying, especially if they conflict with the theme.
		 *
		 * Default is true.
		 *
		 * @since 0.0.6 Added
		 *
		 * @param bool $value The value to return.  True will allow the default templates to be applied.
		 * @param string $className The name of the class calling for the template.
		 */
		if (!!apply_filters('tp_use_default_templates', $useTemplates, $className)) {
			$postTypesToFilter        = Involvement_PostTypeSettings::getPostTypes();
			$templateFilesToOverwrite = self::TEMPLATES_TO_OVERWRITE;

			if (count($postTypesToFilter) == 0) {
				return $template;
			}

			if (!in_array(ltrim(strrchr($template, '/'), '/'), $templateFilesToOverwrite)) {
				return $template;
			}

			if (is_post_type_archive(Meeting::POST_TYPE) && file_exists(
					TouchPointWP::$dir . '/src/templates/meeting-archive.php'
				)) {
				return TouchPointWP::$dir . '/src/templates/meeting-archive.php';
			}

			if (is_post_type_archive($postTypesToFilter) && file_exists(
					TouchPointWP::$dir . '/src/templates/involvement-archive.php'
				)) {
				return TouchPointWP::$dir . '/src/templates/involvement-archive.php';
			}

			if (is_singular($postTypesToFilter) && file_exists(
					TouchPointWP::$dir . '/src/templates/involvement-single.php'
				)) {
				return TouchPointWP::$dir . '/src/templates/involvement-single.php';
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
	public function acceptingNewMembers(): bool|string
	{
		if (!isset($this->_acceptingNewMembers)) {
			if (get_post_meta($this->post_id, TouchPointWP::SETTINGS_PREFIX . "groupFull", true) === '1') {
				$this->_acceptingNewMembers = __("Currently Full", 'TouchPoint-WP');
				return $this->_acceptingNewMembers;
			}

			if (get_post_meta($this->post_id, TouchPointWP::SETTINGS_PREFIX . "groupClosed", true) === '1') {
				$this->_acceptingNewMembers = __("Currently Closed", 'TouchPoint-WP');
				return $this->_acceptingNewMembers;
			}

			$now      = Utilities::dateTimeNow();
			$regStart = get_post_meta($this->post_id, TouchPointWP::SETTINGS_PREFIX . "regStart", true);
			if ($regStart !== false && $regStart !== '' && $regStart > $now) {
				$this->_acceptingNewMembers = __("Registration Not Open Yet", 'TouchPoint-WP');
				return $this->_acceptingNewMembers;
			}

			$regEnd = get_post_meta($this->post_id, TouchPointWP::SETTINGS_PREFIX . "regEnd", true);
			if ($regEnd !== false && $regEnd !== '' && $regEnd < $now) {
				$this->_acceptingNewMembers = __("Registration Closed", 'TouchPoint-WP');
				return $this->_acceptingNewMembers;
			}

			if (intval(get_post_meta($this->post_id, TouchPointWP::SETTINGS_PREFIX . "regTypeId", true)) === 0) {
				$this->_acceptingNewMembers = false; // no online registration available
				return $this->_acceptingNewMembers;
			}

			$this->_acceptingNewMembers = true;
		}

		return $this->_acceptingNewMembers;
	}
	private mixed $_acceptingNewMembers;


	/**
	 * Gets a URL for registration.  A registration url will be provided unless there is no viable registration url to
	 * send users to, in which case null will be returned.
	 *
	 * @return ?string
	 */
	public function getRegistrationUrl(): ?string
	{
		if ($this->_registrationUrl === "") {
			$regUrl = get_post_meta($this->post_id, TouchPointWP::SETTINGS_PREFIX . "regUrl", true);
			$regType = intval(get_post_meta($this->post_id, TouchPointWP::SETTINGS_PREFIX . "regTypeId", true));
			$hasRegQuestions = intval(get_post_meta($this->post_id, TouchPointWP::SETTINGS_PREFIX . "hasRegQuestions", true));
			if ($hasRegQuestions && ($regUrl === "" || $regUrl === null)) {
				$this->_registrationUrl = TouchPointWP::instance()->host() . "/OnlineReg/" . $this->invId;
			} elseif ($regType === 0 || $regUrl === "" || $regUrl === null) {
				$this->_registrationUrl = null;
			} elseif (stristr($regUrl, "http") === false) {
				$this->_registrationUrl = TouchPointWP::instance()->host() . $regUrl;
			} else {
				$this->_registrationUrl = $regUrl;
			}
		}
		return $this->_registrationUrl;
	}
	private ?string $_registrationUrl = "";


	/**
	 * Get the registration type for the involvement.
	 *
	 * @return int enum of RegistrationType
	 */
	public function getRegistrationType(): int
	{
		if (!isset($this->_registrationType)) {
			if ($this->acceptingNewMembers() !== true) {
				$this->_registrationType = RegistrationType::CLOSED;
				return $this->_registrationType;
			}

			// Determine intended indication based on site setting.
			$siteRegType = intval(get_post_meta($this->post_id, TouchPointWP::SETTINGS_PREFIX . "siteRegTypeId", true));
			$regUrl = $this->getRegistrationUrl();
			switch ($siteRegType) {
				case 1:
					$this->_registrationType = RegistrationType::FORM;
					return $this->_registrationType;

				case 3:
					$this->_registrationType = RegistrationType::RSVP;
					return $this->_registrationType;

				case 5:
					if ($regUrl !== null) {
						$this->_registrationType = RegistrationType::EXTERNAL;
						return $this->_registrationType;
					}
					break; // Registration isn't possible at a link, therefore, assume there was a mistake and continue.


				case 7:
					$this->_registrationType = RegistrationType::CLOSED;
					return $this->_registrationType;
			}

			// If the involvement has a redirection link, assume it's an external form
			if ($regUrl !== null) {
				$this->_registrationType = RegistrationType::EXTERNAL;
				return $this->_registrationType;
			}


			// If the involvement has registration questions, assume it's a form.
			if (
				// Has registration questions
				intval(get_post_meta($this->post_id, TouchPointWP::SETTINGS_PREFIX . "hasRegQuestions", true)) === 1 ||

				// Has registration type not equal to Join
				intval(get_post_meta($this->post_id, TouchPointWP::SETTINGS_PREFIX . "regTypeId", true)) !== 1
			) {
				$this->_registrationType = RegistrationType::FORM;
				return $this->_registrationType;
			}

			$this->_registrationType = RegistrationType::JOIN;
		}
		return $this->_registrationType;
	}
	private int $_registrationType;


	/**
	 * Whether the involvement should link to a registration form, rather than directly joining the org.
	 *
	 * @since 0.0.90 Deprecated
	 * @deprecated 0.0.90  Does not take into account all the possible registration types; will be removed in a future
	 *     version.
	 *
	 * @noinspection PHPUnused
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
	 * Get an array of objects that correspond to key details of meetings.  Does NOT return the actual Meeting objects.
	 * Since this is used for involvements regardless of whether their meetings are imported, this pulls from the object
	 * array that's imported directly from the API.  It does not take into account Meeting objects, or meetings that
	 * belong to child involvements.
	 *
	 * @return stdClass[]
	 */
	protected function meetings(): array
	{
		if ( ! isset($this->_meetings)) {
			$m = get_post_meta($this->post_id, TouchPointWP::SETTINGS_PREFIX . "meetings", true);
			if ($m === "") {
				$m = [];
			}

			// Make sure items are unique.  #204
			$m = array_unique($m, SORT_REGULAR);

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
	 * Get the parent of this object **which may be an object of a different class**.
	 *
	 * Returns null if there is no parent.
	 *
	 * @return Involvement|null
	 */
	public function getParent(): ?Involvement
	{
		if ($this->parentPostId === null) {
			$this->parentPostId = $this->post->post_parent;
			if ($this->parentPostId > 0) {
				$parent = get_post($this->parentPostId);
				if ($parent !== null) {
					try {
						$this->parentObject = self::fromPost($parent);
					} catch (TouchPointWP_Exception) {
						$this->parentObject = null;
					}
				}
			}
		}
		return $this->parentObject;
	}
	protected ?int $parentPostId = null;
	protected ?self $parentObject = null;

	/**
	 * Get the several different strings that can be used to describe the start/end/etc of this involvement.
	 *
	 * @param int           $invId
	 * @param ?Involvement  $inv
	 *
	 * @return ?string[]
	 */
	protected static function scheduleStrings(int $invId, $inv = null): ?array
	{
		if (isset($inv->_scheduleStrings)) {
			return $inv->_scheduleStrings;
		}

		$cacheKey = $invId . "_" . get_locale() . "_v2";
		$schStr = wp_cache_get($cacheKey, self::SCHEDULE_STRING_CACHE_GROUP);
		if (!! $schStr && self::ENABLE_SCHEDULE_STRING_CACHE) {
			return $schStr;
		}
		if (! $inv) {
			try {
				$inv = self::fromInvId($invId);
			} catch (TouchPointWP_Exception) {
				return null;
			}
		}
		$inv->_scheduleStrings = $inv->scheduleStrings_calc();
		wp_cache_set(
			$cacheKey,
			$inv->_scheduleStrings,
			self::SCHEDULE_STRING_CACHE_GROUP,
			self::SCHEDULE_STRING_CACHE_EXPIRATION
		);
		return $inv->_scheduleStrings;
	}


	/**
	 * Get a description of the meeting schedule in a human-friendly phrase, e.g. Sundays at 11:00am, starting January
	 * 14.
	 *
	 * This is separated out to a static method to prevent involvement from being instantiated (with those database
	 * hits) when the content is cached.  (10x faster or more)
	 *
	 * @param int          $objId  Involvement Id.
	 * @param ?Involvement $obj
	 *
	 * @return ?string
	 */
	public static function scheduleString(int $objId, $obj = null): ?string
	{
		$s = self::scheduleStrings($objId, $obj);
		return $s['combined'];
	}

	/**
	 * Get the next meeting date/time from either the meetings or schedules.
	 *
	 * @return DateTimeExtended|null
	 */
	public function nextMeeting(): ?DateTimeExtended
	{
		$now                = new DateTimeImmutable();
		$this->_nextMeeting = null;

		if ($this->_nextMeeting === null) {
			// meetings
			foreach ($this->meetings() as $m) {
				$mdt = $m->mtgStartDt;
				if ($mdt > $now) {
					if ($this->_nextMeeting === null || $mdt < $this->_nextMeeting) {
						$this->_nextMeeting = $mdt;
					}
				}
			}

			// schedules
			foreach ($this->schedules() as $s) {
				$mdt = $s->nextStartDt;
				if ($mdt === null) {
					continue;
				}
				if ($mdt <= $now) { // If "next meeting" is past, add a week and re-check.
					$mdt = $mdt->modify("+1 week");
				}
				if ($this->_nextMeeting === null || $mdt < $this->_nextMeeting) {
					$this->_nextMeeting = $mdt;
				}
			}
		}

		return $this->_nextMeeting;
	}

	/**
	 * @param $apiMeeting
	 *
	 * @return bool
	 *
	 * TODO update with #184
	 */
	protected static function apiMeetingIsAllDay($apiMeeting): bool
	{
		return $apiMeeting->mtgStartDt->format("His") === "000000";
	}


	/**
	 * @param $apiSchedule
	 *
	 * @return bool
	 *
	 * TODO remove or change with #184
	 */
	protected static function apiScheduleIsAllDay($apiSchedule): bool
	{
		return $apiSchedule->nextStartDt->format("His") === "000000";
	}

	/**
	 * Group meetings and schedules together such that typical recurrences can be stated. Only returns patterns that
	 * have at least N occurrences.
	 *
	 * @param array $meetings
	 * @param array $schedules
	 * @param int   $minNumber (N) The minimum required number of recurrences.
	 *
	 * @return ?array[]
	 */
	protected static function computeCommonOccurrences(array $meetings = [], array $schedules = [], int $minNumber = 3): ?array
	{
		try {
			$now = Utilities::dateTimeNow();
		} catch (Exception) {
			return null;
		}

		$commonOccurrences = [];

		// Populate the schedules
		foreach ($schedules as $s) {
			if ( ! is_object($s)) {
				continue;
			}

			/** @var DateTimeExtended $start */
			$start = $s->nextStartDt;
			if ($start === null) {
				continue;
			}
			if ($start->isAllDay) {
				$coInx = $start->format('w-9999');
			} else {
				$coInx = $start->format('w-Hi');
			}
			$commonOccurrences[$coInx] = [
				'count'      => 20,
				'example'    => $start,
				'exampleEnd' => null
			];
		}
		unset($start, $coInx, $s);

		// If there isn't a schedule, but there are common meeting dates/times, use those.
		foreach ($meetings as $m) {
			if ( ! is_object($m)) {
				continue;
			}

			/** @var DateTimeExtended $start */
			$start = $m->mtgStartDt;

			/** @var ?DateTimeExtended $end */
			$end = $m->mtgEndDt;

			if ($start < $now) {
				continue;
			}

			if ($start->isAllDay) {
				$coInx = $start->format('w-9999');
			} else {
				$coInx = $start->format('w-Hi');
			}
			if (isset($commonOccurrences[$coInx])) {
				$commonOccurrences[$coInx]['count']++;
			} else {
				$commonOccurrences[$coInx] = [
					'count'      => 1,
					'example'    => $start,
					'exampleEnd' => $end
				];
			}
		}
		unset($start, $coInx, $m);

		return array_filter($commonOccurrences, fn($co) => $co['count'] >= $minNumber);
	}

	/**
	 * Calculate the schedule strings.
	 *
	 * @return string[]
	 */
	protected function scheduleStrings_calc(): array
	{
		$commonOccurrences = self::computeCommonOccurrences($this->meetings(), $this->schedules());

		$dateFormat = get_option('date_format');

		$r = [
			'datetime' => null,
			'date' => null,
			'time' => null,
			'firstLast' => null,
			'combined' => null
		];

		$uniqueTimeStrings = [];
		$days              = [];
		$common            = true;
		if (count($commonOccurrences) > 1) { // this is only needed if there's multiple schedules
			foreach ($commonOccurrences as $k => $co) {
				$timeStr = substr($k, 2);
				if ( ! in_array($timeStr, $uniqueTimeStrings, true)) {
					$uniqueTimeStrings[] = $timeStr;
				}

				$weekday = "d" . $k[0];
				if ( ! isset($days[$weekday])) {
					$days[$weekday] = [];
				}

				if ( ! in_array($co['example'], $days[$weekday], true)) {
					$days[$weekday][] = $co['example'];
				}
			}
			unset($timeStr, $k, $co, $weekday);
		} elseif (count($commonOccurrences) > 0) {
			$cok = array_key_first($commonOccurrences);
			$days["d" . $cok[0]][] = $commonOccurrences[$cok]['example'];
		} else {
			$common = false;
			$commonOccurrences = self::computeCommonOccurrences($this->meetings(), $this->schedules(), 0);

			foreach ($commonOccurrences as $k => $co) {
				$timeStr = substr($k, 2);
				if (!in_array($timeStr, $uniqueTimeStrings, true)) {
					$uniqueTimeStrings[] = $timeStr;
				}

				$weekday = "d" . $k[0];
				if ( ! isset($days[$weekday])) {
					$days[$weekday] = [];
				}

				if ( ! in_array($co['example'], $days[$weekday], true)) {
					$days[$weekday][] = $co['example'];
				}
			}
			unset($timeStr, $k, $co, $weekday);
		}

		if ($common) {
			if (count($uniqueTimeStrings) > 1) {  // Multiple different times.  Sun at 9am & 11am, and Sat at 6pm
				// multiple different times of day
				$dayStr = [];
				foreach ($days as $dk => $dta) {
					$timeStr = [];
					foreach ($dta as $dt) {
						/** @var $dt DateTimeExtended */
						if ($dt->isAllDay) {
							continue; // skip all-days
						}
						$timeStr[] = DateFormats::TimeStringFormatted($dt);
					}

					if (count($days) > 1) {  // Mon at 7pm & Tue at 8pm
						$day = Utilities::getDayOfWeekShortForNumber(intval($dk[1]));
					} else {
						$day = Utilities::getPluralDayOfWeekNameForNumber(intval($dk[1]));
					}
					if (count($timeStr) > 0) {
						$timeStr = Utilities::stringArrayToListString($timeStr);
						// translators: %1$s is the date(s), %2$s is the time(s).
						$dayStr[] = wp_sprintf(__('%1$s at %2$s', 'TouchPoint-WP'), $day, $timeStr);
					} else {
						// translators: "Mon All Day"  or  "Sundays All Day"
						$dayStr[] = wp_sprintf(__('%1$s All Day', 'TouchPoint-WP'), $day);
					}
				}
				$dayStr = Utilities::stringArrayToListString($dayStr);
				$r['date'] = $dayStr;
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
				$r['date'] = $dayStr;
				$dt = array_values($days)[0][0];
				/** @var $dt DateTimeExtended */
				if ($dt->isAllDay) {
					// translators: "Mon All Day"  or  "Sundays All Day"
					$dayStr = wp_sprintf(__('%1$s All Day', 'TouchPoint-WP'), $dayStr);
					$r['time'] = __('All Day', 'TouchPoint-WP');
				} else {
					$timeStr = DateFormats::TimeStringFormatted($dt);

					// translators: %1$s is the date(s), %2$s is the time(s).
					$dayStr  = wp_sprintf(__('%1$s at %2$s', 'TouchPoint-WP'), $dayStr, $timeStr);
					$r['time'] = $timeStr;
				}
			}

			// Convert start and end to string,
			if ($this->firstMeeting !== null && $this->lastMeeting !== null) {
				$r['firstLast'] = wp_sprintf(
				// translators: {start date} through {end date}  e.g. February 14 through August 12
					__('%1$s through %2$s', 'TouchPoint-WP'),
					$this->firstMeeting->format($dateFormat),
					$this->lastMeeting->format($dateFormat)
				);
				if ($dayStr === null) {
					$dayStr = $r['firstLast'];
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
				$r['firstLast'] = wp_sprintf(
				// translators: Starts {start date}  e.g. Starts September 15
					__('Starts %1$s', 'TouchPoint-WP'),
					$this->firstMeeting->format($dateFormat)
				);
				if ($dayStr === null) {
					$dayStr = $r['firstLast'];
				} else {
					$dayStr = wp_sprintf(
					// translators: {schedule}, starting {start date}  e.g. Sundays at 11am, starting February 14
						__('%1$s, starting %2$s', 'TouchPoint-WP'),
						$dayStr,
						$this->firstMeeting->format($dateFormat)
					);
				}
			} elseif ($this->lastMeeting !== null) {
				$r['firstLast'] = wp_sprintf(
				// translators: Through {end date}  e.g. Through September 15
					__('Through %1$s', 'TouchPoint-WP'),
					$this->lastMeeting->format($dateFormat)
				);
				if ($dayStr === null) {
					$dayStr = $r['firstLast'];
				} else {
					$dayStr = wp_sprintf(
					// translators: {schedule}, through {end date}  e.g. Sundays at 11am, through February 14
						__('%1$s, through %2$s', 'TouchPoint-WP'),
						$dayStr,
						$this->lastMeeting->format($dateFormat)
					);
				}
			}

			$r['combined'] = $dayStr;
		} else { // Uncommon schedules

			if (count($commonOccurrences) === 0) {
				return $r;
			}

			$forceDateTime = false;
			$dateTimeArr = new StringableArray();
			$dateArr = new StringableArray();
			$timeArr = [];
			$now = Utilities::dateTimeNow();

			// filter meetings to only those not past
			$meetings = $this->meetings();
			$meetings = array_filter($meetings, fn($m) => $m->status == 1);
			$uncancelledMeetings = $meetings; // includes historical
//			$originalMeetingCount = count($meetings);
			$meetings = array_filter($meetings, fn($m) => $m->mtgEndDt > $now);
			if (count($meetings) === 0) { // if no future, revert to historical.
				$meetings = $uncancelledMeetings;
			}
//			$andOthers = (count($meetings) !== $originalMeetingCount);  This, when fed to the ->toListString methods
//			below can be used to add "and others" to the list of dates/times to indicate that there are historical
//			meetings that are not being shown.  However, this currently seems more confusing than helpful.

			foreach ($meetings as $m) {
				$a = DateFormats::DurationToStringArray($m->mtgStartDt, $m->mtgEndDt, null, $m->mtgStartDt->isAllDay);

				if (isset($a['datetime'])) {
					$forceDateTime = true;
					$dateTimeArr[] = $a['datetime'];
				} else {
					$dateTimeArr[] = wp_sprintf(
					// translators: %1$s is the date(s), %2$s is the time(s).
						__('%1$s at %2$s', 'TouchPoint-WP'), $a['date'], $a['time']
					);
					if ( !$dateArr->contains(['date'])) {
						$dateArr[] = $a['date'];
					}
					if (!in_array($a['time'], $timeArr)) {
						$timeArr[] = $a['time'];
					}
				}
			}
			if (count($timeArr) > 1) {
				$forceDateTime = true;
			}

			if ($forceDateTime) {
				$r['datetime'] = $dateTimeArr->toListString(2);
				$r['combined'] = $r['datetime'];
			} else {
				$dateStr = $dateArr->toListString(2);

				$r['date'] = $dateStr;
				$r['time'] = $timeArr[0];
				$r['combined'] = wp_sprintf(
				// translators: %1$s is the date(s), %2$s is the time(s).
					__('%1$s at %2$s', 'TouchPoint-WP'),
					$dateStr,
					$timeArr[0]
				);
			}
		}

		return $r;
	}


	/**
	 * Gets the division terms for the involvement.
	 *
	 * @return WP_Term[]
	 */
	protected function getDivisions(): array
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

			$this->divisions = wp_get_post_terms($this->post_id, Taxonomies::TAX_DIV, ['meta_query' => $mq]);
		}

		return $this->divisions;
	}

	/**
	 * Returns an array of the Involvement's Divisions, excluding those that cause it to be included.
	 *
	 * @return string[]
	 * @noinspection PhpUnused
	 */
	public function getDivisionsStrings(): array
	{
		$out = [];
		foreach ($this->getDivisions() as $d) {
			$out[] = $d->name;
		}

		return $out;
	}


	/**
	 * Returns an array of links to the Involvement's Divisions, excluding those that cause it to be included.
	 *
	 * @return string[]
	 */
	public function getDivisionsLinks(): array
	{
		$out = [];
		foreach ($this->getDivisions() as $d) {
			$name = $d->name;
			$link = get_term_link($d);
			$out[] = "<a href=\"$link\">$name</a>";
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
				} catch (TouchPointWP_Exception) {
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
				// language=javascript
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
					'taxonomy' => Taxonomies::TAX_DIV,
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

				foreach ($posts as $postI) {
					global $post;
					$post = $postI;

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
	 * @param mixed           $involvementId
	 *
	 * @return int|WP_Post|null
	 */
	private static function getWpPostByInvolvementId($postType, $involvementId): WP_Post|null
	{
		$involvementId = (string)$involvementId;

		$q      = new WP_Query([
			                       'post_type'   => $postType,
			                       'meta_key'    => TouchPointWP::INVOLVEMENT_META_KEY,
			                       'meta_value'  => $involvementId,
			                       'numberposts' => 2
			                       // only need one, but if there's two, there should be an error condition.
		                       ]);
		/** @var $posts WP_Post[] */
		$posts  = $q->get_posts();
		$counts = count($posts);
		if ($counts > 1) {  // multiple posts match, which isn't great.
			new TouchPointWP_Exception("Multiple Posts Exist", 170006);
		}
		if ($counts > 0) { // post exists already.
			return reset($posts);
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
	 * @noinspection PhpMissingParamTypeInspection
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

				/**
				 * Determines whether or not to automatically include the plugin-default CSS.  Return false to use your
				 * own CSS instead.
				 *
				 * @since 0.0.15 Added
				 *
				 * @param bool $useCss Whether or not to include the default CSS.  True = include
				 * @param string $className The name of the current calling class.
				 */
				'includecss' => apply_filters('tp_use_css', true, self::class),
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
			if (count($exclude) == 1) { // Exclude the imported div if there's only one as all would have it.
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
				                    'taxonomy'                              => Taxonomies::TAX_DIV,
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
					'taxonomy'                              => Taxonomies::TAX_RESCODE,
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
			if (Translation::useCampusAsLanguage()) {
				$cName = __("Language", 'TouchPoint-WP');
			}
			$cList = get_terms(
				[
					'taxonomy'                              => Taxonomies::TAX_CAMPUS,
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
					'taxonomy'                              => Taxonomies::TAX_WEEKDAY,
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
					'taxonomy'                              => Taxonomies::TAX_DAYTIME,
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
				                    'taxonomy'                              => Taxonomies::TAX_AGEGROUP,
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
			$content .= wp_sprintf(
				"<span class=\"TouchPointWP-map-warning-visibleOnly\" style=\"display:none;\">%s  </span>",
				wp_sprintf(
					__("The %s listed are only those shown on the map.", 'TouchPoint-WP'),
					$settings->namePlural
				)
			);
			$content .= wp_sprintf(
				"<span class=\"TouchPointWP-map-warning-zoomOrReset\" style=\"display:none;\">%s  </span>",
				wp_sprintf(
				// translators: %s is the link to "reset the map"
					__("Zoom out or %s to see more.", 'TouchPoint-WP'),
					wp_sprintf(
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
	 * @return ?Involvement
	 *
	 * @throws TouchPointWP_Exception If the involvement can't be created from the post, an exception is thrown.
	 */
	public static function fromPost(WP_Post $post): ?Involvement
	{
		$iid = intval($post->{TouchPointWP::INVOLVEMENT_META_KEY});

		if ($iid === 0) {
			throw new TouchPointWP_Exception("Invalid Involvement ID provided.", 171002);
		}

		if ( ! isset(self::$_instances[$iid])) {
			self::$_instances[$iid] = new Involvement($post);
		}

		return self::$_instances[$iid];
	}


	/**
	 * Create an Involvement object from an object from its involvement ID.
	 *
	 * @param string $postType
	 * @param int    $involvementId
	 *
	 * @return ?Involvement
	 *
	 * @throws TouchPointWP_Exception If the involvement can't be created, an exception is thrown.
	 */
	public static function fromInvolvementId(string $postType, int $involvementId): ?Involvement
	{
		if ( ! isset(self::$_instances[$involvementId])) {
			$post = self::getWpPostByInvolvementId($postType, $involvementId);
			if ($post === null) {
				return null;
			}
			self::$_instances[$involvementId] = Involvement::fromPost($post);
		}

		return self::$_instances[$involvementId];
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

			case "preview-sync":
				TouchPointWP::doCacheHeaders(TouchPointWP::CACHE_NONE);
				echo self::updateFromTouchPoint(true, false);
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

		$type  = $_GET['type'] ?? "";
		$lat   = $_GET['lat'] ?? null;
		$lng   = $_GET['lng'] ?? null;
		$limit = $_GET['limit'] ?? 10;

		$settings = self::getSettingsForPostType($type);

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

		if ($lat === "null" || $lng === "null" ||
		    $lat === null || $lng === null) {
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

			if ($geoObj->type == "loc") {
				$geoObj->type = "ip";
			}

			$lat = $geoObj->lat;
			$lng = $geoObj->lng;

			$r['geo'] = $geoObj;
		} else {
			$geoObj = TouchPointWP::instance()->reverseGeocode($lat, $lng);

			if ($geoObj !== false) {
				$geoObj->type = "nav";
				$r['geo'] = $geoObj;
			}
		}

		$invs = self::getInvsNear($lat, $lng, $settings->postType, $limit);

		if ($invs === null) {
			http_response_code(Http::NOT_FOUND);
			echo json_encode([
				                 "invList"    => [],
				                 "error"      => wp_sprintf("No %s Found.", $settings->namePlural),
				                 "error_i18n" => wp_sprintf(__("No %s Found.", "TouchPoint-WP"), $settings->namePlural)
			                 ]);
			exit;
		}

		$errorMessage = null;
		foreach ($invs as $g) {
			$g->name     = html_entity_decode($g->name);
			$g->schedule = self::scheduleString($g->invId);
			$g->invType  = $settings->postTypeWithoutPrefix();
			$g->path     = get_permalink($g->post_id);
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
	 *	 valid.
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
		$metaInvId = TouchPointWP::INVOLVEMENT_META_KEY;
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
                         CAST(pmLng.meta_value AS DECIMAL(10, 7)) as lng,
                         pmFull.meta_value as full,
                         pmClosed.meta_value as closed
                  FROM $wpdb->posts as p
                           JOIN
                       $wpdb->postmeta as pmLat ON p.ID = pmLat.post_id AND pmLat.meta_key = '{$settingsPrefix}geo_lat'
                           JOIN
                       $wpdb->postmeta as pmLng ON p.ID = pmLng.post_id AND pmLng.meta_key = '{$settingsPrefix}geo_lng'
                  		   LEFT JOIN
                       $wpdb->postmeta as pmFull ON p.ID = pmFull.post_id AND pmFull.meta_key = '{$settingsPrefix}groupFull'
                  		   LEFT JOIN
                       $wpdb->postmeta as pmClosed ON p.ID = pmClosed.post_id AND pmClosed.meta_key = '{$settingsPrefix}groupClosed'
                WHERE p.post_type = %s AND pmClosed.meta_value != 1 AND pmFull.meta_value != 1
                 ) as l
                    JOIN $wpdb->postmeta as pmInv ON l.ID = pmInv.post_id AND pmInv.meta_key = '$metaInvId'
            ORDER BY distance LIMIT %d
            ",
			$lat,
			$lng,
			$lat,
			$postType,
			$limit
		);

		return $wpdb->get_results($q, 'OBJECT');
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
		} catch (Exception) {
		}
	}

	protected static DateTimeImmutable $_updateExpiry;

	protected static function updateExpiry(): DateTimeImmutable
	{
		if (!isset(self::$_updateExpiry)) {
			$diff = TouchPointWP::instance()->settings->mc_archive_days;
			try {
				$interval = new DateInterval("P{$diff}D");
				self::$_updateExpiry = Utilities::dateTimeNow()->sub($interval);
			} catch (Exception) {
				self::$_updateExpiry = Utilities::dateTimeNow();
			}
		}
		return self::$_updateExpiry;
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

		return Geo::distance(
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
		} catch (TouchPointWP_Exception) {
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
			} else {
				// enqueue this object for js instantiation
				$post = get_post();
				if ($post) {
					$inv = null;
					if (Meeting::postIsType($post)) {
						$inv = Meeting::fromPost($post)?->involvement();
					} elseif (Involvement::postIsType($post)) {
						$inv = Involvement::fromPost($post);
					}
					$inv?->enqueueForJsInstantiation();
				}
			}

			$script = file_get_contents(TouchPointWP::$dir . "/src/js-partials/involvement-map-inline.js");

			$script = str_replace('{$mapDivId}', $mapDivId, $script);

			wp_add_inline_script(
				TouchPointWP::SHORTCODE_PREFIX . "googleMaps",
				$script
			);

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

	public function asGeoIFace(string $type = "unknown"): ?Geo
	{
		if ($this->hasGeo()) {
			return new Geo(
				$this->geo->lat,
				$this->geo->lng,
				$this->name,
				$type
			);
		}

		return null;
	}

	/**
	 * Update posts that are based on an involvement.
	 *
	 * @param Involvement_PostTypeSettings $typeSets
	 * @param bool                         $verbose
	 * @param bool                         $applyChanges
	 *
	 * @return false|int  False on failure.  Otherwise, the number of updates.
	 */
	final protected static function updateInvolvementPostsForType(Involvement_PostTypeSettings $typeSets, bool $verbose, bool $applyChanges = true): bool|int
	{
		$siteTz = wp_timezone();

		if (!set_time_limit(180) && $verbose) {
			echo "<p>Time limit could not be extended.  May not be able to complete all updates.</p>";
		}

		$qOpts = [];

		// Leader member types
		$qOpts['leadMemTypes'] = implode(',', $typeSets->leaderTypeInts());

		if ($typeSets->useGeo) {
			// Host member types
			$qOpts['hostMemTypes'] = implode(',', $typeSets->hostTypeInts());
		}

		try {
			$qOpts['camps'] = Utilities::idArrayToIntArray($typeSets->importCampuses, false);

			if ($typeSets->postType === Meeting::POST_TYPE) {
				$qOpts['featMtgs'] = 1;
				$qOpts['exDivs'] = Utilities::idArrayToIntArray(Involvement_PostTypeSettings::getAllDivs(), false);
			} else {
				$qOpts['divs'] = Utilities::idArrayToIntArray($typeSets->importDivs, false);
			}

			if (TouchPointWP::instance()->settings->enable_meeting_cal === 'on' || $typeSets->importMeetings) {
				// Meetings to be imported
				$qOpts['mtgHist']   = TouchPointWP::instance()->settings->mc_hist_days;
				$qOpts['mtgFuture'] = TouchPointWP::instance()->settings->mc_future_days;
			} else {
				// Meetings for involvements that aren't imported.  For schedule strings only.
				$qOpts['mtgHist']   = 0;
				$qOpts['mtgFuture'] = 365;
			}

			$response = TouchPointWP::instance()->api->pyGet("Invs", $qOpts, 180, $verbose);

		} catch (TouchPointWP_Exception) {
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
			$now       = Utilities::dateTimeNow();
			$histDays  = intval(TouchPointWP::instance()->settings->mc_hist_days);
			$histVal   = new DateInterval("P{$histDays}D");
			$nowMinusH = $now->sub($histVal);
			unset($aYear);
		} catch (Exception $e) {
			if ($verbose) {
				$m = $e->getMessage();
				echo "<p>Could not calculate date values.  Original Exception: $m</p>";
			}
			return false;
		}

		foreach ($invData as $inv) {
			set_time_limit(15);

			////////////////////////
			// Standardize Inputs //
			////////////////////////

			self::standardizeApiData($inv, $siteTz, $verbose);


			////////////////
			// Exclusions //
			////////////////

			// 'continue' causes involvement to be deleted (or not created).

			// Exclude Meeting type if there are no meetings.
			if ($typeSets->postType === Meeting::POST_TYPE && !$inv->isParent) {
				if (count($inv->meetings) < 1) {
					if ($verbose) {
						echo "<p>Stopping processing because no meetings were returned.  Involvement will be deleted from WordPress.</p>";
					}
					continue;
				}
			}

			// Filter by end dates to stay relevant
			if ($inv->lastMeeting !== null && (
					(!$typeSets->importMeetings && $inv->lastMeeting < $now) ||
					($typeSets->importMeetings && $inv->lastMeeting < $nowMinusH))
			) { // last meeting was long enough ago to no longer be relevant.
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

			$inv->titleToUse = $inv->regTitle ?? $inv->name;
			$inv->titleToUse = trim($inv->titleToUse);

			if ($post === null && $applyChanges) {
				$post = wp_insert_post(
					[ // create new
						'post_type'  => $typeSets->postType,
						'post_title' => $inv->titleToUse,
						'post_name'  => $inv->titleToUse,
						'post_status' => 'publish',
						'meta_input' => [
							TouchPointWP::INVOLVEMENT_META_KEY => $inv->involvementId
						]
					]
				);
				$post = get_post($post);
			}

			$postsToKeep =  [...$postsToKeep, ...Involvement::doPostUpdate($post, $inv, $typeSets, $verbose, $applyChanges)];
			$postsToKeep[] = $post->ID;
		}
		unset($inv);

		//////////////////
		//// Removals ////
		//////////////////


		if ($verbose) {
			$tsn = $typeSets->namePlural;
			echo "<h3>Deletions for $tsn</h3>";
		}


		// Delete posts that are no longer current
		$q        = new WP_Query([
			                         'post_type'    => $typeSets->postType,
			                         'nopaging'     => true,
			                         'post__not_in' => $postsToKeep
		                         ]);
		$removals = 0;
		foreach ($q->get_posts() as $post) {
			set_time_limit(10);
			if ($applyChanges) {
				wp_delete_post($post->ID, true);
			}
			$removals++;
		}

		if ($verbose) {
			echo "<p>Deleted $removals posts.";
		}

		return $removals + count($invData);
	}

	/**
	 * Does the heavy-lifting of updating a given post, with the given information.
	 *
	 * @param mixed                        $post
	 * @param object                       $inv
	 * @param Involvement_PostTypeSettings $typeSets
	 * @param bool                         $verbose
	 * @param bool                         $applyChanges
	 *
	 * @return int[] A list of Post IDs that should be kept.
	 */
	protected static function doPostUpdate($post, object $inv, Involvement_PostTypeSettings $typeSets, bool $verbose = false, bool $applyChanges = true): array
	{
		if ($post instanceof WP_Error) {
			new TouchPointWP_WPError($post);
			return [];
		}

		if (!$applyChanges && $post === null) {
			$post = new WP_Post((object)[]); // Create an empty placeholder to dry-run the logic.
		}

		if ($post === null) {
			new TouchPointWP_Exception("Post could not be found or created.", 171001);
			return [];
		}

		/** @var $post WP_Post */
		if ($inv->description == null || trim($inv->description) === "") {
			$post->post_content = "";
		} else {
			$post->post_content = Utilities::standardizeHtml($inv->description, "involvement-import");
		}

		// Title & Slug -- slugs should only be updated if there's a reason, like a title change.  Otherwise, they increment.
		if ($post->post_title != $inv->titleToUse || str_contains($post->post_name, "__trashed")) {
			$post->post_title = $inv->titleToUse;
			$post->post_name  = ''; // Slug will regenerate;
		}

		if ($verbose) {
			$link = get_permalink($post);
			echo "<p><a href=\"$link\">Updating Post $post->ID</a> based on Involvement $inv->involvementId ($inv->titleToUse).</p>";
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

			if ($parent > 0) {
				$post->post_parent = $parent;
			} else {
				$post->post_parent = 0;
			}
		}

		// Status & Submit
		$post->post_status = 'publish';
		if ($applyChanges) {
			wp_update_post($post);

			update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "locationName", $inv->location);
			update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "memberCount", $inv->memberCount);
			update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "genderId", $inv->genderId);
			update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "groupFull", ! ! $inv->groupFull);
			update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "groupClosed", ! ! $inv->closed);
			update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "hasRegQuestions", ! ! $inv->hasRegQuestions);
			update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "regUrl", $inv->redirectUrl);
			update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "regTypeId", intval($inv->regTypeId));
			update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "siteRegTypeId", intval($inv->siteRegTypeId));
			update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "hasRegQuestions", ! ! $inv->hasRegQuestions);


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
			if ( ! ! $typeSets->useImages) {
				$imageUrl = $inv->imageUrl;
			}
		}

		$imageId = Utilities::updatePostImageFromUrl($post->ID, $imageUrl, $post->post_title, $verbose, $applyChanges);

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
				if ($timeStr === "9999") {
					// All day; doesn't need to be categorized.  TODO see #184
					continue;
				}
				$timeTerm            = Utilities::getTimeOfDayTermForTime_noI18n($dt['example']);
				if ( ! in_array($timeTerm, $timeTerms)) {
					$timeTerms[] = $timeTerm;
				}
			}
			unset($timeStr, $weekday);
		}

		// first and last meeting dates
		$tense = Taxonomies::TAX_TENSE_PRESENT;
		if ($inv->firstMeeting !== null && $inv->firstMeeting < Utilities::dateTimeNow()) { // First meeting already happened.
			$inv->firstMeeting = null; // We don't need to list info from the past.
		}
		if ($applyChanges) {
			if ($inv->firstMeeting === null) {
				delete_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "firstMeeting");
			} else {
				update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "firstMeeting", $inv->firstMeeting);
			}
		}

		// Determine if there are meetings beyond the end date, and if so, nullify the end date
		if ($inv->lastMeeting !== null) {
			foreach ($inv->meetings as $m) {
				if ($m->mtgStartDt > $inv->lastMeeting) {
					$inv->lastMeeting = null;
					break;
				}
			}
		}
		if ($inv->lastMeeting !== null && $inv->lastMeeting > Utilities::dateTimeNowPlus1Y()) { // Last mtg is > 1yr away
			$inv->lastMeeting = null; // For all practical purposes: it's not ending.
		}
		if ($applyChanges) {
			if ($inv->lastMeeting === null) {
				delete_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "lastMeeting");
			} else {
				update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "lastMeeting", $inv->lastMeeting);
			}
		}

		// Clear Cached Schedule String
		$cacheKey = $inv->involvementId . "_" . get_locale();
		if ($applyChanges) {
			wp_cache_delete($cacheKey, self::SCHEDULE_STRING_CACHE_GROUP);
		}

		// Tense
		if ($inv->firstMeeting !== null) {
			$tense = Taxonomies::TAX_TENSE_FUTURE;
		}
		if ($applyChanges) {
			/** @noinspection PhpRedundantOptionalArgumentInspection */
			wp_set_post_terms($post->ID, [$tense], Taxonomies::TAX_TENSE, false);
		}

		// Update meetings and schedules
		if ($applyChanges) {
			update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "meetings", $inv->meetings);
			update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "schedules", $inv->schedules);
		}

		// Day of week taxonomy
		$dayTerms = [];
		foreach ($days as $k => $d) {
			$dayTerms[] = Utilities::getDayOfWeekShortForNumber_noI18n(intval($k[1]));
		}
		if ($applyChanges) {
			/** @noinspection PhpRedundantOptionalArgumentInspection */
			wp_set_post_terms($post->ID, $dayTerms, Taxonomies::TAX_WEEKDAY, false);
		}

		// Time of day taxonomy
		if ($applyChanges) {
			/** @noinspection PhpRedundantOptionalArgumentInspection */
			wp_set_post_terms($post->ID, $timeTerms, Taxonomies::TAX_DAYTIME, false);
		}


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
				if ($applyChanges) {
					update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "geo_lat", $inv->lat);
					update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "geo_lng", $inv->lng);
				}
			} else {
				if ($applyChanges) {
					delete_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "geo_lat");
					delete_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "geo_lng");
				}
			}

			// Handle Resident Code
			if (property_exists($inv, "resCodeName") && $inv->resCodeName !== null) {
				if ($applyChanges) {
					/** @noinspection PhpRedundantOptionalArgumentInspection */
					wp_set_post_terms($post->ID, [$inv->resCodeName], Taxonomies::TAX_RESCODE, false);
				}
			} else {
				if ($applyChanges) {
					/** @noinspection PhpRedundantOptionalArgumentInspection */
					wp_set_post_terms($post->ID, [], Taxonomies::TAX_RESCODE, false);
				}
			}
		}


		////////////////
		//// Campus ////
		////////////////

		if (TouchPointWP::instance()->settings->enable_campuses === "on") {
			if (property_exists($inv, "campusName") && $inv->campusName !== null) {
				if ($applyChanges) {
					/** @noinspection PhpRedundantOptionalArgumentInspection */
					wp_set_post_terms($post->ID, [$inv->campusName], Taxonomies::TAX_CAMPUS, false);
				}
			} else {
				if ($applyChanges) {
					/** @noinspection PhpRedundantOptionalArgumentInspection */
					wp_set_post_terms($post->ID, [], Taxonomies::TAX_CAMPUS, false);
				}
			}

			if (Translation::useCampusAsLanguage() && $inv->campusName !== null) {

				// Set content's original language based on Campus.
				$langCode = Translation::getWpmlLangCodeForString($inv->campusName);
				if ($langCode !== null) {
					$args = [
						'element_id'           => $post->ID,
						'element_type'         => apply_filters('wpml_element_type', $typeSets->postTypeWithPrefix()),
						'language_code'        => $langCode,
						'source_language_code' => $langCode,
						'trid'                 => $post->ID
					];
					if ($applyChanges) {
						do_action('wpml_set_element_language_details', $args);
					}
					if ($verbose) {
						echo "<p>Language Set to: $langCode</p>";
					}
				}
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
		if ($applyChanges) {
			/** @noinspection PhpRedundantOptionalArgumentInspection */
			wp_set_post_terms($post->ID, $maritalTax, Taxonomies::TAX_INV_MARITAL, false);
		}

		// Handle Age Groups
		if ($applyChanges) {
			if ($inv->age_groups === null) {
				/** @noinspection PhpRedundantOptionalArgumentInspection */
				wp_set_post_terms($post->ID, [], Taxonomies::TAX_AGEGROUP, false);
			} else {
				/** @noinspection PhpRedundantOptionalArgumentInspection */
				wp_set_post_terms($post->ID, $inv->age_groups, Taxonomies::TAX_AGEGROUP, false);
			}
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
		if ($applyChanges) {
			/** @noinspection PhpRedundantOptionalArgumentInspection */
			wp_set_post_terms($post->ID, $divs, Taxonomies::TAX_DIV, false);
		}
		
		if ($verbose) {
			echo "<p>Division Terms:</p>";
			Utilities::var_dump_expandable($divs);
		}


		//////////////////
		//// Meetings ////
		//////////////////

		$postsToKeep = self::updateMeetingsForInvolvement($post, $inv, $typeSets, $imageId, $verbose, $applyChanges);

		if ($verbose) {
			echo "<hr />";
		}

		unset($post);

		return $postsToKeep;
	}

	/**
	 * Compute the slug strategy for a set of meetings.
	 *
	 * @param apiMeeting[] $set An array of Meeting objects, grouped by their start date.
	 */
	protected static function computeSlugs(iterable $set, $inv, $includeTitle = false): void
	{
		// Slugs for groups (or ungrouped meetings)
		$slugStrategy = [];

		$slugFormats = [ // Define possible slugs, in increasing specificity.
			//'Y',  Causes issues with Core. https://wordpress.stackexchange.com/a/367757/185189
			'Y-m',
			'Y-m-d',
			'Y-m-d-g',
			'Y-m-d-ga',
			'Y-m-d-gia',
			'Y-m-d-His',
		];
		foreach ($set as $mtgO) {
			$mtgO->titleToUse = $mtgO->name ?? $inv->titleToUse;
			if ($includeTitle && isset($mtgO->titleToUse)) {
				$s = Utilities::stringToSlug($mtgO->titleToUse);
				if (!isset($slugStrategy[$s])) {
					$slugStrategy[$s] = 1;
				} else {
					$slugStrategy[$s]++;
				}
			}
			foreach ($slugFormats as $f) {
				$s = $mtgO->mtgStartDt->format($f);
				if (!isset($slugStrategy[$s])) {
					$slugStrategy[$s] = 1;
				} else {
					$slugStrategy[$s]++;
				}
			}
		}

		foreach ($set as $mtgO) {
			$slug  = $mtgO->mtgId; // Default slug of the meeting ID -- collision-safe.
			if ($includeTitle && isset($mtgO->titleToUse)) {
				$s = Utilities::stringToSlug($mtgO->titleToUse);
				if ($slugStrategy[$s] == 1) {
					$mtgO->slugToUse = $s;
					continue;
				}
			}
			foreach ($slugFormats as $f) {
				$s = $mtgO->mtgStartDt->format($f);
				if ($slugStrategy[$s] == 1) {
					$slug = $s;
					break;
				}
			}
			$mtgO->slugToUse = $slug;
		}
	}

	/**
	 * @param object                       $post The parent post, which could be a group or Meeting.
	 * @param object                       $inv The involvement object from the API.
	 * @param Involvement_PostTypeSettings $typeSets
	 * @param int                          $imagePostId
	 * @param bool                         $verbose
	 * @param bool                         $applyChanges
	 *
	 * @return int[] An array of Post IDs that have been updated, and which should be retained.
	 */
	protected static function updateMeetingsForInvolvement(
		object $post, object $inv, Involvement_PostTypeSettings $typeSets,
		int $imagePostId, bool $verbose = false, bool $applyChanges = true): array
	{

		// Return if meetings shouldn't be imported at all.
		if (!$typeSets->importMeetings && !$inv->showInSites) {
			/** @var $post WP_Post */
			self::doMeetingMetaUpdates($post, null, false, $verbose, $applyChanges);
			return [$post->ID];
		}

		////////////////////////
		// Determine Strategy //
		////////////////////////

		$strategy = match (count($inv->meetings)) {
			0 => self::MEETING_STRATEGY_NONE,
			1 => self::MEETING_STRATEGY_SINGLE,
			default => self::MEETING_STRATEGY_MULTIPLE,
		};

		$postsToKeep = [];

		if ($strategy === self::MEETING_STRATEGY_MULTIPLE) {

			// If the main post was previously a single, it needs to have the meeting info removed.
			/** @var $post WP_Post */
			self::doMeetingMetaUpdates($post, null, false, $verbose, $applyChanges);

			// Group meetings together if they're adjacent and settings allow.
			$doGrouping = match($typeSets->meetingGroupingMethod) {
				Meeting::GROUP_UNSCHEDULED => count($inv->schedules) === 0,
				Meeting::GROUP_ALL => true,
				default => false
			};

			usort($inv->meetings, fn($a, $b) => $a->mtgStartDt <=> $b->mtgStartDt);

			$c = 0;
			foreach ($inv->meetings as $i => $mtgO) {
				$mtgO->group = $c;
				if ($i > 0 && $doGrouping) {
					$diff = $mtgO->mtgStartDt->diff($inv->meetings[$i - 1]->mtgStartDt);

					if ($diff->days === 0 && $diff->h < 23) {
						$c--;
						$mtgO->group = $c;
					}
				}
				$c++;
			}
			unset($c);
			$grouped = [];
			foreach ($inv->meetings as $mtgO) {
				if (!isset($grouped[$mtgO->group])) {
					$grouped[$mtgO->group] = new MeetingArray(involvement:$inv);
				}
				$grouped[$mtgO->group][] = $mtgO;
			}

			self::computeSlugs($grouped, $inv);

			// execute the changes
			foreach ($grouped as $g) {

				$groupPost = null;
				$groupingActive = count($g) > 1;

				// A Meeting group, if it exists.
				if ($groupingActive) {
					$groupPost = self::updateMeeting($g, $inv, $typeSets, $post, $imagePostId, $verbose, $applyChanges);
					if ($groupPost) {
						$postsToKeep[] = $groupPost->ID;
					}
					self::computeSlugs($g, $inv, true);
				}

				// Meetings within group (or ungrouped)
				foreach ($g as $mtgO) {
					if ($groupingActive) {
						$mtgO->isGroupMember = true;
					} else {
						$mtgO->slugToUse     = $g->slugToUse;
						$mtgO->titleToUse    = $mtgO->name ?? $g->titleToUse;
					}
					$updatedPost = self::updateMeeting($mtgO, $inv, $typeSets, $groupPost ?? $post, $imagePostId, $verbose, $applyChanges);
					if ($updatedPost) {
						$postsToKeep[] = $updatedPost->ID;
					}
				}
			}
		} else { // Single and None
			if ($strategy == self::MEETING_STRATEGY_SINGLE) {
				$inv->titleToUse = $inv->meetings[0]->name ?? $inv->titleToUse;
			}
			$post->post_title = $inv->titleToUse;

			// TODO resolve $post declarations.
			// TODO resolve Undefined array key 0 warning
			// TODO make sure synced
			if ($strategy === self::MEETING_STRATEGY_SINGLE) {
				/** @var $post WP_Post */
				self::doMeetingMetaUpdates($post, $inv->meetings[0], !!$inv->showInSites, $verbose, $applyChanges);
			} else { // MEETING_STRATEGY_NONE
				/** @var $post WP_Post */
				self::doMeetingMetaUpdates($post, null, false, $verbose, $applyChanges);
			}

			if ($applyChanges) {
				wp_update_post($post);
			}

			$postsToKeep[] = $post->ID;
		}

		return $postsToKeep;
	}


	/**
	 * Update a meeting post, or create it if it doesn't exist.
	 *
	 * @param apiMeeting $mtgO
	 * @param object     $inv
	 * @param Involvement_PostTypeSettings $typeSets
	 * @param ?WP_Post   $parentPost
	 * @param int        $imagePostId
	 * @param bool       $verbose
	 *
	 * @return ?WP_Post
	 */
	protected static function updateMeeting(object $mtgO, object $inv, Involvement_PostTypeSettings $typeSets,
		?WP_Post $parentPost, int $imagePostId, bool $verbose = false, bool $applyChanges = true): ?WP_Post
	{
		/////////////////////////////////
		// Find or Create Meeting Post //
		/////////////////////////////////

		$loops = 1;
		do {
			$mtgP = new WP_Query([
				                     'post_type'      => $typeSets->postTypeWithPrefix(),
				                     'post_parent'    => $parentPost->ID,
				                     'posts_per_page' => 10,
				                     'numberposts'    => 10,
				                     'meta_key'       => Meeting::MEETING_META_KEY,
				                     'meta_value'     => $mtgO->mtgId,
				                     'meta_compare'   => '='
			                     ]);

			$counts = $mtgP->post_count;
			$mtgP   = $mtgP->get_posts();

			if ($counts > 1) {  // multiple posts match, which isn't great.
				new TouchPointWP_Exception(
					"Multiple Posts Exist, Attempting to Remedy Automatically",
					170007
				);
				if ($verbose) {
					echo "<p><b>Multiple Posts Exist.  An attempt will be made to remove them.</b></p>";
				}

				if (!$applyChanges) { // dont' loop forever, or do any deletions. 
					break;
				}
				for ($i = 1; $i <= $counts; $i++) {
					wp_delete_post($mtgP[$i]->ID, true);
				}
			}
			$loops++;
		} while ($counts > 1 && $loops < 3);

		$eventIsPast = ($mtgO->mtgEndDt ?? $mtgO->mtgStartDt) < self::updateExpiry();

		if ($counts > 0) { // post exists already.
			$mtgP = reset($mtgP);
		} elseif ($eventIsPast) {
			if ($verbose) {
				echo "<p>Post not found for Meeting $mtgO->mtgId.  As it is in the past, it will not be created.</p>";
			}
			return null;
		} else {
			if ($verbose) {
				echo "<p>Post not found for Meeting $mtgO->mtgId.  Creating.</p>";
			}
			if ($applyChanges) {
				// create new
				$mtgP = wp_insert_post([
					                       'post_type'   => $typeSets->postTypeWithPrefix(),
					                       'post_title'  => $mtgO->titleToUse,
					                       'post_name'   => $mtgO->slugToUse,
					                       'post_parent' => $parentPost->ID,
					                       'post_status' => 'publish',
					                       'meta_input'  => [
						                       Meeting::MEETING_META_KEY => $mtgO->mtgId
					                       ]
				                       ]);
				$mtgP = get_post($mtgP);
			} else {
				$mtgP = new WP_Post((object)[]); // Create an empty placeholder to dry-run the logic.
			}
		}

		$mtgP->post_title = $mtgO->titleToUse;
		if (!$eventIsPast) {
			if (isset($mtgO->isGroupMember) && $mtgO->isGroupMember) {
				$mtgP->post_content = "";
			} else {
				$mtgP->post_content = Utilities::standardizeHtml($inv->description, "meeting-import");
			}
		}
		$mtgP->post_parent = $parentPost->ID;

		self::doMeetingMetaUpdates($mtgP, $mtgO, ! ! $inv->showInSites, $verbose, $applyChanges);

		if ($applyChanges) {
			wp_update_post($mtgP);


			if ($imagePostId > 0) {
				set_post_thumbnail($mtgP->ID, $imagePostId);
			} else {
				delete_post_thumbnail($mtgP->ID);
			}

			if ($mtgP->post_name !== $mtgO->slugToUse) {
				Utilities::forceSlugUpdate($mtgP->ID, $mtgO->slugToUse);
			}
		}

		return $mtgP;
	}

	/**
	 * Update meta fields specific to Meetings, such as start/end date/time.
	 *
	 * (This is included in this class and not in Meeting because it's a part of the Involvement import process and
	 * the protected access is appropriate.)
	 *
	 * @param WP_Post $mtgP
	 * @param ?object $mtgO
	 * @param bool    $feature
	 * @param bool    $verbose
	 * @param bool    $applyChanges
	 *
	 * @return void
	 */
	protected static function doMeetingMetaUpdates(WP_Post $mtgP, ?object $mtgO, bool $feature, bool $verbose = false, bool $applyChanges = true): void
	{
		// If the main post was previously a single, it needs to have the meeting info removed.
		if ($mtgO === null) {
			if ($applyChanges) {
				delete_post_meta($mtgP->ID, Meeting::MEETING_META_KEY);
				delete_post_meta($mtgP->ID, Meeting::MEETING_START_META_KEY);
				delete_post_meta($mtgP->ID, Meeting::MEETING_END_META_KEY);
				delete_post_meta($mtgP->ID, Meeting::MEETING_FEAT_META_KEY);
				delete_post_meta($mtgP->ID, Meeting::MEETING_INV_ID_META_KEY);
				delete_post_meta($mtgP->ID, Meeting::MEETING_STATUS_META_KEY);
				delete_post_meta($mtgP->ID, Meeting::MEETING_IS_GROUP_MEMBER);
			}
		} else {
			if ($applyChanges) {
				$eventIsPast = ($mtgO->mtgEndDt ?? $mtgO->mtgStartDt) < Utilities::dateTimeNow();

				update_post_meta($mtgP->ID, Meeting::MEETING_META_KEY, $mtgO->mtgId);
				update_post_meta(
					$mtgP->ID,
					Meeting::MEETING_START_META_KEY,
					DateFormats::timestampWithoutOffset($mtgO->mtgStartDt)
				);
				update_post_meta(
					$mtgP->ID,
					Meeting::MEETING_END_META_KEY,
					DateFormats::timestampWithoutOffset($mtgO->mtgEndDt)
				);
				update_post_meta($mtgP->ID, Meeting::MEETING_FEAT_META_KEY, ! ! $feature);
				update_post_meta($mtgP->ID, Meeting::MEETING_INV_ID_META_KEY, $mtgO->involvementId);
				update_post_meta($mtgP->ID, Meeting::MEETING_STATUS_META_KEY, intval($mtgO->status));
				update_post_meta($mtgP->ID, Meeting::MEETING_IS_GROUP_MEMBER, 1 * isset($mtgO->isGroupMember));

				if ($mtgO->location !== null && ! $eventIsPast) {
					update_post_meta($mtgP->ID, Meeting::MEETING_LOCATION_META_KEY, $mtgO->location);
				}
			}

			if ($verbose) {
				$link = get_permalink($mtgP);
				echo "<p><a href=\"$link\">Updating Post $mtgP->ID</a> based on Meeting $mtgO->mtgId.</p>";
			}
		}
	}

	/**
	 * @param object       $inv Involvement info from API
	 * @param DateTimeZone $siteTz Timezone object
	 * @param bool         $verbose Print details
	 *
	 * @return void
	 */
	protected static function standardizeApiData(object $inv, DateTimeZone $siteTz, bool $verbose): void
	{
		if ($verbose) {
			Utilities::var_dump_expandable($inv);
		}

		// Start and end dates
		if ($inv->firstMeeting !== null) {
			try {
				$inv->firstMeeting = new DateTimeExtended($inv->firstMeeting, $siteTz);
				$inv->firstMeeting->isAllDay = true;
			} catch (Exception) {
				$inv->firstMeeting = null;
			}
		}
		if ($inv->lastMeeting !== null) {
			try {
				$inv->lastMeeting = new DateTimeExtended($inv->lastMeeting, $siteTz);
				$inv->lastMeeting->isAllDay = true;
			} catch (Exception) {
				$inv->lastMeeting = null;
			}
		}

		// Meeting and Schedule date/time strings as DateTimeExtended
		foreach ($inv->schedules as $i => $s) {
			try {
				if ($s->nextStartDt === $s->nextEndDt || $s->nextEndDt == null) {
					$s->nextEndDt = null;
				} else {
					$s->nextEndDt = new DateTimeExtended($s->nextEndDt, $siteTz);
				}
				$s->nextStartDt = new DateTimeExtended($s->nextStartDt, $siteTz);
				$s->nextStartDt->isAllDay = self::apiScheduleIsAllDay($s);
			} catch (Exception) {
				unset($inv->schedules[$i]);
			}
		}
		foreach ($inv->meetings as $i => $m) {
			try {
				if ($m->mtgStartDt === $m->mtgEndDt || $m->mtgEndDt == null) {
					$m->mtgEndDt = null;
				} else {
					$m->mtgEndDt = new DateTimeExtended($m->mtgEndDt, $siteTz);
				}
				$m->mtgStartDt = new DateTimeExtended($m->mtgStartDt, $siteTz);
				$m->mtgStartDt->isAllDay = self::apiMeetingIsAllDay($m);
			} catch (Exception) {
				unset($inv->meetings[$i]);
			}

			if ($m->name == null || trim($m->name) === "") {
				$m->name = null;
			}

			$m->involvementId = $inv->involvementId;
		}

		// Registration start
		if ($inv->regStart !== null) {
			try {
				$inv->regStart = new DateTimeImmutable($inv->regStart, $siteTz);
			} catch (Exception) {
				$inv->regStart = null;
			}
		}

		// Registration end
		if ($inv->regEnd !== null) {
			try {
				$inv->regEnd = new DateTimeImmutable($inv->regEnd, $siteTz);
			} catch (Exception) {
				$inv->regEnd = null;
			}
		}
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
			if (self::postIsType($post)) {
				$theDate = self::scheduleString(intval($post->{TouchPointWP::INVOLVEMENT_META_KEY})) ?? "";
			} elseif (Meeting::postIsType($post)) {
				$theDate = Meeting::scheduleString(intval($post->{Meeting::MEETING_META_KEY})) ?? "";
			}
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

			$author = null;
			try {
				if (Involvement::postIsType($post)) {
					$inv    = Involvement::fromPost($post);
					$author = $inv->leaders()->__toString();
				} elseif (Meeting::postIsType($post)) {
					$mtg    = Meeting::fromPost($post);
					$author = $mtg->involvement()->leaders()->__toString();
				}
			} catch (TouchPointWP_Exception) {
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

			// If there aren't leader types (as is the case for all Event types), default to attend leader type.
			if (count($s->leaderTypes) == 0) {
				$q = new PersonQuery(
					[
						'meta_key'     => Person::META_INV_ATTEND_PREFIX . $this->invId,
						'meta_value'   => ['at10'], // Leader Attend type.
						'meta_compare' => 'IN'
					]
				);
			} else {
				$q = new PersonQuery(
					[
						'meta_key'     => Person::META_INV_MEMBER_PREFIX . $this->invId,
						'meta_value'   => $s->leaderTypes,
						'meta_compare' => 'IN'
					]
				);
			}
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

			// If there aren't host types, there are no hosts.
			if (count($s->hostTypes) == 0) {
				$this->_hosts = new PersonArray();
			} else {
				$q = new PersonQuery(
					[
						'meta_key'     => Person::META_INV_MEMBER_PREFIX . $this->invId,
						'meta_value'   => $s->hostTypes,
						'meta_compare' => 'IN'
					]
				);

				$this->_hosts = $q->get_results();
			}
		}

		return $this->_hosts;
	}

	/**
	 * Get the members of the involvement.  Note that not all members are necessarily synced to WordPress from
	 * TouchPoint.
	 *
	 * @return ?PersonArray
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
	 * @return ?Involvement_PostTypeSettings
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
	 *	 here.)
	 *
	 * @return string[]
	 */
	public function notableAttributes(array $exclude = []): array
	{
		$asMeeting = $this->AsAMeeting();
		if ($asMeeting !== null) {
			$attrs = $asMeeting->notableAttributes(['involvement']);
		} else {
			$attrs = self::scheduleStrings($this->invId, $this);
			unset($attrs['combined']);
			$attrs = array_filter($attrs);
		}
		unset($schStr);

		$l = $this->locationName();
		if ($l) {
			$attrs['location'] = $l;
		}
		unset($l);

		if (!in_array('divisions', $exclude)) {
			foreach ($this->getDivisionsLinks() as $k => $a) {
				$attrs["divisions_$k"] = $a;
			}
		}

		if ($this->leaders()->count() > 0) {
			$attrs['leaders'] = $this->leaders()->toLinks();
		}

		if ($this->genderId != 0) {
			switch ($this->genderId) {
				case 1:
					$attrs['gender'] = __('Men Only', 'TouchPoint-WP');
					break;
				case 2:
					$attrs['gender'] = __('Women Only', 'TouchPoint-WP');
					break;
			}
		}

		$canJoin = $this->acceptingNewMembers();
		if (is_string($canJoin)) {
			$attrs['closed'] = $canJoin;
		}

		if ($this->hasGeo() && (
				$this->locationName !== null &&
				! in_array($this->locationName, $exclude)
			)
		) {
			$dist = $this->getDistance();
			if ($dist !== false) {
				$attrs['distance'] = wp_sprintf(
					_x(
						"%2.1fmi",
						"miles. Unit is appended to a number.  %2.1f is the number, so %2.1fmi looks like '12.3mi'",
						'TouchPoint-WP'
					),
					$dist
				);
			}
		}

		$attrs = $this->processAttributeExclusions($attrs, $exclude);

		/**
		 * Allows for manipulation of the notable attributes strings for an Involvement.  An array of strings.
		 * Typically, these are the standardized strings that appear on the Involvement to give information about it,
		 * such as the schedule, leaders, and location.
		 *
		 * @see Involvement::notableAttributes()
		 * @see PostTypeCapable::notableAttributes()
		 *
		 * @since 0.0.11 Added
		 *
		 * @param string[] $attrs The list of notable attributes.
		 * @param Involvement $this The Involvement object.
		 */
		return apply_filters("tp_involvement_attributes", $attrs, $this);
	}

	/**
	 * Returns the html with buttons for actions the user can perform.  This must be called *within* an element with
	 * the `data-tp-involvement` attribute with the post_id (NOT the Inv ID) as the value.
	 *
	 * @param string|null $context A string that gives filters some context for where the request is coming from
	 * @param string      $btnClass HTML class names to put into the buttons/links
	 * @param bool        $withTouchPointLink Whether to include a link to the item within TouchPoint.
	 * @param bool        $absoluteLinks Set true to make the links absolute, so they work from apps or emails.
	 * @param bool        $includeRegister Set false to exclude the register button.
	 *
	 * @return StringableArray
	 */
	public function getActionButtons(?string $context = null, string $btnClass = "", bool $withTouchPointLink = true, bool $absoluteLinks = false, bool $includeRegister = true): StringableArray
	{
		if (!$absoluteLinks) {
			TouchPointWP::requireScript('swal2-defer');
			TouchPointWP::requireScript('base-defer');
			$this->enqueueForJsInstantiation();
			$this->enqueueForJsonLdInstantiation();
			Person::enqueueUsersForJsInstantiation();
		}

		$classesOnly = $btnClass;
		if ($btnClass !== "") {
			$btnClass = " class=\"$btnClass\"";
		}

		$baseLink = get_permalink($this->post_id);

		$ret = new StringableArray();
		if (self::allowContact($this->invType) && $this->leaders()->count() > 0) {
			$text  = __("Contact Leaders", 'TouchPoint-WP');
			if (!$absoluteLinks) {
				$ret['contact_leader'] = "<button type=\"button\" data-tp-involvement=\"$this->post_id\" data-tp-action=\"contact\" $btnClass>$text</button> ";
				TouchPointWP::enqueueActionsStyle('inv-contact');
			} else {
				$iid = $this->invId;
				$ret['contact_leader'] = "<a href=\"$baseLink#tp-contact-i$iid\"$btnClass>$text</a> ";
			}
		}

		// Register Button
		if ($includeRegister === true) {
			$ret['register'] = $this->getRegisterButton($classesOnly);
		}

		// Show on map button.  (Only works if map is called before this is.)
		if (self::$_hasArchiveMap && $this->geo !== null && !$absoluteLinks) {
			$text = __("Show on Map", 'TouchPoint-WP');
			if ($ret->count() > 1) {
				TouchPointWP::requireScript("fontAwesome");
				$ret->prepend("<button type=\"button\" data-tp-action=\"showOnMap\" title=\"$text\" $btnClass><i class=\"fa-solid fa-location-pin\"></i></button>", "map");
			} else {
				$ret->prepend("<button type=\"button\" data-tp-action=\"showOnMap\" $btnClass>$text</button>", "map");
			}
		}

		if ($withTouchPointLink && TouchPointWP::currentUserIsAdmin()) {
			$tpHost = TouchPointWP::instance()->host();
			// Translators: %s is the system name.  "TouchPoint" by default.
			$title  = wp_sprintf(__("Involvement in %s", "TouchPoint-WP"), TouchPointWP::instance()->settings->system_name);
			$logo = TouchPointWP::TouchPointIcon();
			$ret['inv_tp']  = "<a href=\"$tpHost/Org/$this->invId\" title=\"$title\" class=\"tp-TouchPoint-logo $classesOnly\">$logo</a>";
		}

		/**
		 * Allows for manipulation of the action buttons for an Involvement.  This is the list of buttons that appear
		 * on the Involvement to allow the user to interact with it.
		 *
		 * @since 0.0.7 Added
		 *
		 * @see Involvement::getActionButtons()
		 * @see PostTypeCapable::getActionButtons()
		 *
		 * @param StringableArray $ret The list of action buttons.
		 * @param Involvement $this The Involvement object.
		 * @param ?string $context A reference to where the action buttons are meant to be used.
		 * @param string $btnClass A string for classes to add to the buttons.  Note that buttons can be 'a' or 'button'
		 *     elements.
		 */
		return apply_filters("tp_involvement_actions", $ret, $this, $context, $btnClass);
	}

	/**
	 * Get the HTML for the register button.  Labels depend on several settings within TouchPoint.
	 *
	 * @param string   $btnClass  Class names
	 * @param bool     $absoluteLinks  Whether only absolute links should be provided that can be used in emails, apps,
	 *     etc.
	 * @param ?Meeting $forMeeting  If these buttons are for a meeting, pass the meeting
	 *
	 * @return ?string HTML for the registration button, whatever that should be. Null if nothing to return.
	 */
	public function getRegisterButton(string $btnClass, bool $absoluteLinks = false, ?Meeting $forMeeting = null): ?string
	{
		if ($btnClass !== "") {
			$btnClass = " class=\"$btnClass\"";
		}

		switch ($this->getRegistrationType()) {
			case RegistrationType::FORM:
				$text = __('Register', 'TouchPoint-WP');
				$regTypeId = get_post_meta($this->post_id, TouchPointWP::SETTINGS_PREFIX . "regTypeId", true);
				switch ($regTypeId) {
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

				// If this is a meeting with record attendance type, don't show unless the meeting is within an hour.
				if ($forMeeting !== null && $regTypeId == 18) {
					if ($forMeeting->startDt > Utilities::dateTimeNow()->modify('+1 hour') ||
					    ($forMeeting->endDt ?? $forMeeting->startDt) < Utilities::dateTimeNow()->modify('-1 hour')) {
						return null;
					}
				}

				// If this is a meeting with tickets, don't show unless the meeting is in the present or future.
				if ($forMeeting !== null && $regTypeId == 21) {
					if (($forMeeting->endDt ?? $forMeeting->startDt->modify('+1 hour')) < Utilities::dateTimeNow()) {
						return null;
					}
				}

				$link  = TouchPointWP::instance()->host() . "/OnlineReg/" . $this->invId;
				if (!$absoluteLinks) {
					TouchPointWP::enqueueActionsStyle('inv-register');
				}
				return "<a href=\"$link\" $btnClass>$text</a>  ";

			case RegistrationType::JOIN:
				$text = __('Join', 'TouchPoint-WP');
				if (!$absoluteLinks) {
					TouchPointWP::enqueueActionsStyle('inv-join');
					return "<button type=\"button\" data-tp-involvement=\"$this->post_id\" data-tp-action=\"join\" $btnClass>$text</button>  ";
				}
				$link = get_permalink($this->post_id()) . "#tp-join-i" . $this->invId;
				return "<a href=\"$link\" $btnClass>$text</a>  ";

			case RegistrationType::EXTERNAL:
				$text = __('Register', 'TouchPoint-WP');
				$link = $this->getRegistrationUrl();
				if (!$absoluteLinks) {
					TouchPointWP::enqueueActionsStyle('inv-register');
				}
				return "<a href=\"$link\" $btnClass>$text</a>  ";

			case RegistrationType::RSVP:
				$asAMeeting = $this->AsAMeeting();
				if ($asAMeeting !== null) {
					if ($absoluteLinks) {
						return $asAMeeting->getRsvpLink($btnClass);
					}
					return $asAMeeting->getRsvpButton($btnClass);
				}
		}
		return null;
	}

	/**
	 * If the post for this involvement is also a single Meeting post, return that object.  Otherwise, null.
	 *
	 * @return ?Meeting
	 */
	protected function AsAMeeting(): ?Meeting
	{
		if (!$this->post) {
			$this->post = get_post($this->post_id);
		}
		if (Meeting::postIsType($this->post)) {
			try {
				return Meeting::fromPost($this->post);
			} catch (TouchPointWP_Exception) {
				return null;
			}
		}
		return null;
	}

	/**
	 * Get the JS for instantiation.
	 *
	 * @return string
	 */
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
	 * Indicates if the given post can be instantiated as an Involvement.
	 *
	 * @param WP_Post $post
	 *
	 * @return bool
	 */
	public static function postIsType(WP_Post $post): bool
	{
		return intval(get_post_meta($post->ID, TouchPointWP::INVOLVEMENT_META_KEY, true)) > 0;
	}

	/**
	 * Indicates if the given post type name is the post type for this class.
	 *
	 * @param string $postType
	 *
	 * @return bool
	 */
	public static function postTypeMatches(string $postType): bool
	{
		return str_starts_with($postType, "tp_inv_") || $postType == "tp_smallgroup" || $postType == "tp_course";
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
			$data = TouchPointWP::instance()->api->pyPost('inv_join', $inputData);
		} catch (TouchPointWP_Exception $ex) {
			http_response_code(Http::SERVER_ERROR);
			echo json_encode(['error' => $ex->getMessage()]);
			exit;
		}

		try {
			$stats = Stats::instance();
			$stats->involvementJoins += count($data->success);
			$stats->updateDb();
		} catch (Exception) {}

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
		$allowed = true;

		/**
		 * Determines whether contact of any kind is allowed.  This is meant to prevent abuse in contact forms by
		 * removing the ability to contact people and thereby hiding the forms.
		 *
		 * @since 0.0.35 Added
		 *
		 * @param bool $allowed True if contact is allowed.
		 */
		$allowed = !!apply_filters('tp_allow_contact', $allowed);

		/**
		 * Determines whether contact is allowed for any Involvements.  This is called *after* tp_allow_contact, and
		 * that will set the default.
		 *
		 * @since 0.0.35 Added
		 *
		 * @see tp_allow_contact
		 *
		 * @param bool $allowed Previous response from tp_allow_contact.  True if contact is allowed.
		 * @param string $invType The name of the Involvement Type.
		 */
		return !!apply_filters('tp_inv_allow_contact', $allowed, $invType);
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

		// Clean Talk filter
		$result   = "Contact Blocked for Spam.";
		$validate = Utilities::validateMessage(
			$inputData->fromPerson->displayName,
			$inputData->fromEmail,
			$inputData->message,
			$result
		);
		if (!$validate) {
			http_response_code(Http::BAD_REQUEST);
			echo json_encode([
				                 'error'      => $result,
				                 'error_i18n' => __("Contact Blocked for Spam.", 'TouchPoint-WP')
			                 ]);
			exit;
		}

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

		// Submit the contact
		try {
			$data = TouchPointWP::instance()->api->pyPost('inv_contact', $inputData);
		} catch (TouchPointWP_Exception $ex) {
			http_response_code(Http::SERVER_ERROR);
			echo json_encode(['error' => $ex->getMessage()]);
			exit;
		}

		try {
			$stats = Stats::instance();
			$stats->involvementContacts += count($data->success);
			$stats->updateDb();
		} catch (Exception) {}

		echo json_encode(['success' => $data->success]);
		exit;
	}

	/**
	 * Get the name of the location.
	 *
	 * @return ?string
	 */
	public function locationName(): ?string
	{
		return $this->locationName;
	}
}
