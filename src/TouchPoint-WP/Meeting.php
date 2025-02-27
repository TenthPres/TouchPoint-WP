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
	require_once 'hierarchical.php';
	require_once 'scheduled.php';
}

use DateTime;
use DateTimeImmutable;
use Exception;
use tp\TouchPointWP\Utilities\DateFormats;
use tp\TouchPointWP\Utilities\StringableArray;
use WP_Post;
use tp\TouchPointWP\Utilities\Http;
use WP_Query;
use WP_Term;

/**
 * Handle meeting content, particularly RSVPs.
 */
class Meeting extends PostTypeCapable implements api, module, hasGeo, hierarchical, scheduled
{
	use jsInstantiation;
//	use jsonLd; TODO

	public const POST_TYPE_WO_PRE = "meeting";
	public const POST_TYPE = TouchPointWP::HOOK_PREFIX . self::POST_TYPE_WO_PRE;

	public const MEETING_META_KEY = TouchPointWP::SETTINGS_PREFIX . "mtgId";
	public const MEETING_START_META_KEY = TouchPointWP::SETTINGS_PREFIX . "mtgStartDt";
	public const MEETING_END_META_KEY = TouchPointWP::SETTINGS_PREFIX . "mtgEndDt";
	public const MEETING_FEAT_META_KEY = TouchPointWP::SETTINGS_PREFIX . "mtgFeatured";
	public const MEETING_STATUS_META_KEY = TouchPointWP::SETTINGS_PREFIX . "status";
	public const MEETING_INV_ID_META_KEY = TouchPointWP::SETTINGS_PREFIX . "mtgInvId";

	public const SHORTCODE_GRID = TouchPointWP::SHORTCODE_PREFIX . "calendar";

	public const STATUS_CANCELLED = "cancelled";
	public const STATUS_SCHEDULED = "scheduled";
	public const STATUS_UNKNOWN = "unknown";

	// This is the same as the meta key for involvement locations.
	public const MEETING_LOCATION_META_KEY = TouchPointWP::SETTINGS_PREFIX . "locationName";

	private static bool $_isLoaded = false;
	private static array $_instances = [];
	private static ?Involvement_PostTypeSettings $_typeSet = null;

	public ?DateTimeImmutable $startDt = null;
	public ?DateTimeImmutable $endDt = null;

	protected object $attributes;
	protected string $name;
	protected int $mtgId;


	/**
	 * Meeting constructor.
	 *
	 * @param $object WP_Post|object an object representing the meeting's post.
	 *				  Must have post_id AND mtg id attributes.
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
			$this->mtgId   = intval($object->{Meeting::MEETING_META_KEY});
			$this->post_id = $object->ID;

			if ($this->mtgId === 0) {
				throw new TouchPointWP_Exception("No Meeting ID provided in the post.", 171003);
			}
		} elseif (gettype($object) === "object") {
			// Sql Object, probably.

			if ( ! property_exists($object, 'post_id')) {
				_doing_it_wrong(
					__FUNCTION__,
					esc_html(
						__('Creating a Meeting object from an object without a post_id is not yet supported.', 'TouchPoint-WP')
					),
					esc_attr(TouchPointWP::VERSION)
				);
			}

			$this->post    = get_post($object, "OBJECT");
			$this->post_id = $this->post->ID;

			foreach ($object as $property => $value) {
				if (property_exists(self::class, $property)) {
					$this->$property = $value;
				}
				// TODO add an else for nonstandard/optional metadata fields
			}
		} else {
			throw new TouchPointWP_Exception("Could not construct a Meeting with the information provided.");
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
			if ($k === "mtgId") {
				continue;
			}
			if (property_exists(self::class, $k)) {  // properties
				$this->$k = maybe_unserialize($v[0]);
			}
		}

		// JS attributes, for filtering mostly.
//		$this->attributes->genderId = (string)$this->genderId;  TODO restore if needed (may be able to just inherit from parent).

		// Start and End
		$start = intval(get_post_meta($this->post_id, self::MEETING_START_META_KEY, true));
		$end = intval(get_post_meta($this->post_id, self::MEETING_END_META_KEY, true));
		$tz = wp_timezone();
		$tz0 = Utilities::utcTimeZone();
		$startDt = $start === 0 ? null : DateTime::createFromFormat("U", $start, $tz0)->setTimezone($tz);
		$endDt = $end   === 0 ? null : DateTime::createFromFormat("U", $end, $tz0)->setTimezone($tz);
		$this->startDt = ($startDt === null ? null : DateTimeImmutable::createFromMutable($startDt));
		$this->endDt   = ($endDt   === null ? null : DateTimeImmutable::createFromMutable($endDt));

		$this->registerConstruction();
	}

	/**
	 * Get the Meeting ID.
	 *
	 * @return int
	 */
	public function mtgId(): int
	{
		return $this->mtgId;
	}


	/**
	 * Create a Meeting object from a Meeting ID.  Only Meetings that are already imported as Posts are currently
	 * available.
	 *
	 * @param int $mid A database object from which a Meeting object should be created.
	 *
	 * @return ?Meeting  Null if the involvement is not imported/available.
	 * @throws TouchPointWP_Exception
	 */
	private static function fromMtgId(int $mid): ?Meeting
	{
		if ( ! isset(self::$_instances[$mid])) {
			$post                   = self::getWpPostByMeetingId(Involvement::getPostTypes(), $mid);
			self::$_instances[$mid] = new Meeting($post);
		}

		return self::$_instances[$mid];
	}

	/**
	 * Get a WP_Post by the Meeting ID if it exists.  Return null if it does not.
	 *
	 * @param string|string[] $postType
	 * @param mixed           $meetingId
	 *
	 * @return WP_Post|null
	 */
	private static function getWpPostByMeetingId($postType, $meetingId): WP_Post|null
	{
		$meetingId = (string)$meetingId;

		$q      = new WP_Query([
			                       'post_type'   => $postType,
			                       'meta_key'    => self::MEETING_META_KEY,
			                       'meta_value'  => $meetingId,
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
	 * Get the PostTypeSettings object for Meeting Involvements.
	 *
	 * @return Involvement_PostTypeSettings
	 */
	public static function getTypeSettings(): Involvement_PostTypeSettings
	{
		if (self::$_typeSet == null) {
			self::$_typeSet = new Involvement_PostTypeSettings((object)[
				'namePlural'      => _x("Events", "What Meetings should be called, plural.", 'TouchPoint-WP'),
				'nameSingular'    => _x("Event", "What Meetings should be called, singular.", 'TouchPoint-WP'),
				'slug'            => TouchPointWP::instance()->settings->mc_slug,
				'importMeetings'  => true,
				'useImages'       => true,
				'useGeo'          => false,
				'hierarchical'    => true,
				'postType'        => self::POST_TYPE_WO_PRE
			]);
		}
		return self::$_typeSet;
	}


	/**
	 * Create a Meeting object from an object from a WP_Post object.
	 *
	 * @param WP_Post $post
	 *
	 * @return Meeting
	 *
	 * @throws TouchPointWP_Exception If the meeting can't be created from the post, an exception is thrown.
	 */
	public static function fromPost(WP_Post $post): Meeting
	{
		$mid = intval($post->{Meeting::MEETING_META_KEY});

		if ($mid === 0) {
			throw new TouchPointWP_Exception("Invalid Meeting ID provided.", 171003);
		}

		if ( ! isset(self::$_instances[$mid])) {
			self::$_instances[$mid] = new Meeting($post);
		}

		return self::$_instances[$mid];
	}

	/**
	 * Get the involvement ID (without necessarily instantiating the Involvement)
	 *
	 * @since 0.0.90 Added
	 *
	 * @return int
	 */
	public function involvementId(): int
	{
		return intval(get_post_meta($this->post_id, self::MEETING_INV_ID_META_KEY, true));
	}

	/**
	 * Get the Involvement object associated with this Meeting.
	 *
	 * @return Involvement
	 * @throws TouchPointWP_Exception
	 */
	public function involvement(): Involvement
	{
		if (Involvement::postIsType($this->post)) {
			return Involvement::fromPost($this->post);
		}
		$parent = get_post_parent($this->post_id);
		if (Involvement::postIsType($parent)) {
			return Involvement::fromPost(get_post($parent));
		}
		throw new TouchPointWP_Exception("Meeting is not associated with an Involvement.", 171002);
	}


	/**
	 * Get the parent of this object **which is a different class**.
	 *
	 * Returns null if there is no parent.
	 *
	 * In cases where a meeting post is also an involvement post, it will return the involvement, which has the same post_id.
	 *
	 * @return ?Involvement
	 */
	public function getParent(): ?Involvement
	{
		try {
			return $this->involvement();
		} catch (TouchPointWP_Exception) {
			return null;
		}
	}


	/**
	 * Get the meeting date/time in human-readable form.
	 *
	 * @param int      $objId
	 * @param ?Meeting $obj
	 *
	 * @return ?string
	 */
	public static function scheduleString(int $objId, $obj = null): ?string
	{
		if (!$obj) {
			try {
				$obj = self::fromMtgId($objId);
			} catch (TouchPointWP_Exception) {
				return null;
			}
		}

		$s = $obj?->scheduleStringArray();

		return $s?->join();
	}

	/**
	 * Get the human-readable schedule for the meeting as a string or set of strings in an array.
	 *
	 * @return StringableArray
	 *
	 * @since 0.0.90 Added
	 */
	public function scheduleStringArray(): StringableArray
	{
		return DateFormats::DurationToStringArray($this->startDt, $this->endDt, $this->isMultiDay(), $this->isAllDay());
	}


	/**
	 * Get notable attributes, such as gender restrictions, as strings.
	 *
	 * @param array $exclude Attributes listed here will be excluded.  (e.g. if shown for a parent, not needed here.)
	 *
	 * @return string[]
	 */
	public function notableAttributes(array $exclude = []): array
	{
		if (in_array('involvement', $exclude)) {
			$attrs = [];
		} else {
			try {
				$attrs = $this->involvement()->notableAttributes(['date', 'datetime', 'time', 'firstLast']);
			} catch (TouchPointWP_Exception) {
				$attrs = [];
			}
		}

		$d = $this->scheduleStringArray();

		$attrs = [...$d, ...$attrs];

		$status = $this->status_i18n(true);
		if ($status) {
			$attrs['status'] = $status;
		} else {
			// Add an "in the past" label if the thing is already past. (end may be null)
			if (($this->endDt ?? $this->startDt) < Utilities::dateTimeNow()) {
				$attrs['past'] = __("In the Past", "TouchPoint-WP");
			}
		}

		$loc = $this->locationName();
		if ($loc) {
			$attrs['location'] = $loc;
		}



		$attrs = $this->processAttributeExclusions($attrs, $exclude);

		/**
		 * Allows for manipulation of the notable attributes strings for a Meeting.  An array of strings.
		 * Typically, these are the standardized strings that appear on the Involvement to give information about it,
		 * such as the schedule, leaders, and location.
		 *
		 * @see Meeting::notableAttributes()
		 * @see PostTypeCapable::notableAttributes()
		 *
		 * @since 0.0.90 Added
		 *
		 * @param string[] $attrs The list of notable attributes.
		 * @param Meeting $this The Meeting object.
		 */
		return apply_filters("tp_meeting_attributes", $attrs, $this);
	}

	/**
	 * @param string|null $context A string that gives filters some context for where the request is coming from
	 * @param string      $btnClass HTML class names to put into the buttons/links
	 * @param bool        $withTouchPointLink Whether to include a link to the item within TouchPoint.
	 * @param bool        $absoluteLinks  Set true to make the links absolute, so they work from apps or emails.
	 *
	 * @return StringableArray
	 */
	public function getActionButtons(string $context = null, string $btnClass = "", bool $withTouchPointLink = true, bool $absoluteLinks = false): StringableArray
	{
		if (!$absoluteLinks) {
			TouchPointWP::requireScript('swal2-defer');
			TouchPointWP::requireScript('base-defer');
			$this->enqueueForJsInstantiation();
//		    $this->enqueueForJsonLdInstantiation();
			Person::enqueueUsersForJsInstantiation();
		}

		try {
			$inv = $this->involvement();
		} catch (TouchPointWP_Exception) {
			return new StringableArray();
		}

		$ret = $inv->getActionButtons($context . "_meeting", $btnClass, false, $absoluteLinks, false);

		if ($this->status() !== self::STATUS_CANCELLED) {
			if (($this->endDt ?? $this->startDt) > Utilities::dateTimeNow()) {
				$ret['register'] = $inv->getRegisterButton($btnClass, $absoluteLinks, $this);
			}

			if ($inv->getRegistrationType() === RegistrationType::RSVP) {
				if ($absoluteLinks) {
					$ret['register'] = $this->getRsvpLink($btnClass);
				} else {
					$ret['register'] = $this->getRsvpButton($btnClass);
				}
			}
		}

		if ($withTouchPointLink && TouchPointWP::currentUserIsAdmin()) {
			$tpHost = TouchPointWP::instance()->host();
			// Translators: %s is the system name.  "TouchPoint" by default.
			$title  = wp_sprintf(__("Meeting in %s", "TouchPoint-WP"), TouchPointWP::instance()->settings->system_name);
			$logo = TouchPointWP::TouchPointIcon();
			$ret['mtg_tp']  = "<a href=\"$tpHost/Meeting/$this->mtgId\" title=\"$title\" class=\"tp-TouchPoint-logo $btnClass\">$logo</a>";
		}

		/**
		 * Allows for manipulation of the action buttons for a Meeting.  This is the list of buttons that appear
		 * on the Meeting to allow the user to interact with it.
		 *
		 * @since 0.0.90 Added
		 *
		 * @see Meeting::getActionButtons()
		 * @see PostTypeCapable::getActionButtons()
		 *
		 * @param StringableArray $ret The list of action buttons.
		 * @param Meeting $this The Meeting object.
		 * @param ?string $context A reference to where the action buttons are meant to be used.
		 * @param string $btnClass A string for classes to add to the buttons.  Note that buttons can be 'a' or 'button'
		 *     elements.
		 */
		return apply_filters("tp_meeting_actions", $ret, $this, $context, $btnClass);
	}
	
	public function isFeatured(): bool
	{
		return !!get_post_meta($this->post_id, Meeting::MEETING_FEAT_META_KEY, true);
	}

	/**
	 * Get the status of the meeting, in a code-oriented name (for css, etc.)
	 *
	 * @return string
	 */
	public function status(): string
	{
		$status = intval(get_post_meta($this->post_id, self::MEETING_STATUS_META_KEY, true));

		return match ($status) {
			0 => self::STATUS_CANCELLED,
			1 => self::STATUS_SCHEDULED,
			default => self::STATUS_UNKNOWN,
		};
	}

	/**
	 * @param bool $excludeScheduled "Scheduled" is the default (and correct) status for most events.  Set this to true
	 * to return null instead of "Scheduled".
	 *
	 * @return string|null
	 */
	public function status_i18n(bool $excludeScheduled = false): ?string
	{
		$status = intval(get_post_meta($this->post_id, self::MEETING_STATUS_META_KEY, true));

		return match ($status) {
			0 => __("Cancelled", "TouchPoint-WP"),
			1 => $excludeScheduled ? null : __("Scheduled", "TouchPoint-WP"),
			default => _x("Unknown", "Event Status is not a recognized value.", "TouchPoint-WP"),
		};
	}

	/**
	 * Filters the post thumbnail ID.  Allows meetings to have the image of their parent without having an image themselves.
	 *
	 * @param int|false        $thumbnail_id Post thumbnail ID or false if the post does not exist.
	 * @param int|WP_Post|null $post         Post ID or WP_Post object. Default is global `$post`.
	 */
	public static function filterThumbnailId(int|false $thumbnail_id, int|WP_Post|null $post): bool|int
	{
		if ($thumbnail_id > 0) { // If already set, we have nothing to do.
			return $thumbnail_id;
		}

		if (is_numeric($post)) {
			$post = get_post($post);
		}

		if (!$post instanceof WP_Post) { // Something went wrong because we don't have a post.
			return $thumbnail_id;
		}

		if (!self::postIsType($post) || Involvement::postIsType($post)) {
			// Second condition is necessary to prevent loops when meeting post == involvement post
			return $thumbnail_id;
		}

		try {
			$meeting = Meeting::fromPost($post);
			$involvementPostId = $meeting->involvement()?->post_id();
			if (!$involvementPostId) {
				return $thumbnail_id;
			}

			if (get_the_content(post: $involvementPostId) !== get_the_content(post: $meeting->post_id())) {
				return $thumbnail_id;
			}

			return get_post_thumbnail_id($involvementPostId);
		} catch (TouchPointWP_Exception) {
		}

		return $thumbnail_id;
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
	 * @inheritDoc
	 */
	public static function getJsInstantiationString(): string
	{
		$queue = static::getQueueForJsInstantiation();

		if (count($queue) < 1) {
			return "\t// No Meetings to instantiate.\n";
		}

		$listStr = json_encode($queue);

		return "";  // TODO someday, probably.

//		return "\ttpvm.addEventListener('Involvement_class_loaded', function() {
//		TP_Involvement.fromObjArray($listStr);\n\t});\n";
	}

	/**
	 * Gets a TouchPoint item ID number, regardless of what type of object this is.
	 *
	 * @return int
	 */
	public function getTouchPointId(): int
	{
		return $this->mtgId;
	}

	/**
	 * @param $opts
	 *
	 * @return object
	 * @throws TouchPointWP_Exception
	 */
	private static function getMeetingInfoForRsvp($opts): object
	{
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
			$data = self::getMeetingInfoForRsvp($_GET);
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

		try {
			$stats = Stats::instance();
			$stats->rsvps += count($data->success);
			$stats->updateDb();
		} catch (Exception) {}

		echo json_encode(['success' => $data->success]);
		exit;
	}

	/**
	 * @param string $btnClass
	 *
	 * @return string
	 */
	public function getRsvpButton(string $btnClass = ""): string
	{
		TouchPointWP::requireScript('swal2-defer');
		TouchPointWP::requireScript('meeting-defer');
		TouchPointWP::enqueueActionsStyle('rsvp');
		Person::enqueueUsersForJsInstantiation();

		$link = __("RSVP", "TouchPoint-WP");
		$preloadMsg = __("Loading...", "TouchPoint-WP");
		
		$btnClass = trim($btnClass);
		if ($btnClass !== '' && !str_starts_with($btnClass, "class=")) {
			$btnClass = "class=\"$btnClass\"";
		}

		return "<a href=\"#\" onclick=\"return false;\" $btnClass disabled data-tp-action=\"rsvp\" data-tp-mtg=\"$this->mtgId\"><span class=\"rsvp-btn-content\" style=\"display:none\">$link</span><span class=\"rsvp-btn-preload\">$preloadMsg</span></a>";
	}

	/**
	 * Get a link to RSVP for the meeting that can be used in emails, apps, or other contexts.  This is a link to the
	 * RSVP function in WordPress, not an RSVP magic link used in TouchPoint emails.
	 *
	 * @param string $btnClass
	 *
	 * @return string
	 */
	public function getRsvpLink(string $btnClass = ""): string
	{
		$link = __("RSVP", "TouchPoint-WP");

		$baseUrl = get_permalink($this->post_id);

		$btnClass = trim($btnClass);
		if ($btnClass !== '' && !str_starts_with($btnClass, "class=")) {
			$btnClass = "class=\"$btnClass\"";
		}

		$mid = $this->mtgId;
		return "<a href=\"$baseUrl#tp-rsvp-m$mid\" $btnClass>$link</a>";

	}

	/**
	 * Indicates if the given post can be instantiated as a Meeting.
	 *
	 * @param \WP_Post $post
	 *
	 * @return bool
	 */
	public static function postIsType(WP_Post $post): bool
	{
		return intval(get_post_meta($post->ID, Meeting::MEETING_META_KEY, true)) > 0;
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
		return $postType === self::POST_TYPE;
	}

	public static function load(): bool
	{
		if (self::$_isLoaded) {
			return true;
		}

		self::$_isLoaded = true;

		add_action(TouchPointWP::INIT_ACTION_HOOK, [self::class, 'init']);

		if ( ! shortcode_exists(self::SHORTCODE_GRID)) {
			add_shortcode(self::SHORTCODE_GRID, [CalendarGrid::class, "shortcode"]);
		}

		return true;
	}

	public static function init(): void
	{
		add_filter('post_thumbnail_id', [self::class, 'filterThumbnailId'], 10, 3);
	}

	/**
	 * Indicate the tense of the meeting.
	 *
	 * @return string
	 */
	public function tense(): string
	{
		if ($this->endDt < Utilities::dateTimeNow()) {
			return Taxonomies::TAX_TENSE_PAST;
		}
		if ($this->startDt > Utilities::dateTimeNow()) {
			return Taxonomies::TAX_TENSE_FUTURE;
		}
		return Taxonomies::TAX_TENSE_PRESENT;
	}

	/**
	 * Indicates if the meeting is multi-day.
	 *
	 * @return bool
	 */
	public function isMultiDay(): bool
	{
		if ($this->endDt === null) {
			return false;
		}
		return $this->startDt->format("Ymd") !== $this->endDt->format("Ymd");
	}

	/**
	 * Indicates if the meeting is labeled as all-day.
	 * 
	 * Currently, TouchPoint doesn't have the capacity for this.
	 *
	 * TODO When TouchPoint supports an all-day marker, add it here. #184
	 *
	 * @return bool
	 */
	public function isAllDay(): bool
	{
		return $this->startDt->format("His") === "000000";
	}

	/**
	 * Get the date portion for the meeting start, formatted.
	 *
	 * @return string
	 */
	public function dateString(): string
	{
		if (!$this->isMultiDay() || $this->endDt === null) {
			return DateFormats::DateStringFormatted($this->startDt);
		}
		return DateFormats::DateStringFormatted($this->startDt) . " &endash; " . DateFormats::DateStringFormatted($this->endDt);
	}

	/**
	 * Get the time portion for the meeting start, formatted.
	 *
	 * @return ?string
	 */
	public function startTimeString(): ?string
	{
		if ($this->isAllDay())
			return null;
		return DateFormats::TimeStringFormatted($this->startDt);
	}

	/**
	 * Get the time portion for the meeting end, formatted.  Null if no end is defined or end is same as start.
	 *
	 * @return ?string
	 */
	public function endTimeString(): ?string
	{
		if ($this->endDt === null) {
			return null;
		}
		return DateFormats::TimeStringFormatted($this->endDt);
	}

	/**
	 * @inheritDoc
	 */
	public function hasGeo(): bool
	{
		try {
			$i = $this->involvement();
			if ($i->locationName() !== $this->locationName()) {
				// Meetings can't have geographical references yet.  TODO Add Meeting Geographical ref when possible. #187
				return false;
			}

			return $this->involvement()->hasGeo();
		} catch (TouchPointWP_Exception) {
			return false;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function asGeoIFace(string $type = "unknown"): ?Geo
	{
		// In case location on meeting doesn't match location on 
		if (!$this->hasGeo()) {
			return null;
		}

		try {
			return $this->involvement()->asGeoIFace($type);
		} catch (TouchPointWP_Exception) {
			return null;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function locationName(): ?string
	{
		$loc = get_post_meta($this->post_id, Meeting::MEETING_LOCATION_META_KEY, true);
		if ($loc) {
			return $loc;
		}
		try {
			if ($this->post_id() !== $this->involvement()->post_id()) {
				return $this->involvement()->locationName();
			}
		} catch (TouchPointWP_Exception) {}
		return null;
	}
}