<?php
/**
 * @package TouchPointWP
 */

namespace tp\TouchPointWP;

if ( ! defined('ABSPATH')) {
	exit(1);
}

/**
 * The Meeting Grouping settings for one TouchPoint Involvement Type (or for all other Involvement Types).  Determines
 * which grouping types are used when meetings are imported: Editions, Time Slots, and Clusters, and whether child
 * involvements' meetings are included.
 *
 * All settings are stored together in the mc_grouping_json setting.
 *
 * @since 0.0.98 Added
 *
 * @property-read ?int $invTypeId       The TouchPoint Involvement Type ID.  Null for the "all other types" settings.
 * @property-read bool $includeChildren Whether meetings of child (and grandchild) involvements are included.
 * @property-read bool $editions        Whether meetings are grouped into Editions.
 * @property-read bool $timeSlots       Whether simultaneous meetings of different involvements in the same structure are
 *                                      grouped into Time Slots.
 * @property-read bool $clusters        Whether meetings of the same involvement are grouped into Clusters.
 * @property-read bool $legacy          Whether the previous behavior (collect meetings less than 23 hours apart) is used.
 *                                      Only possible for the "all other types" settings.
 */
class Meeting_GroupingSettings
{
	/**
	 * Default gap, in seconds, after which a new Edition starts.  Adjustable with the tp_meeting_edition_gap filter.
	 */
	public const DEFAULT_EDITION_GAP = 25 * DAY_IN_SECONDS;

	/**
	 * Default maximum gap, in seconds, between back-to-back meetings in a Cluster.  Adjustable with the
	 * tp_meeting_cluster_gap filter.
	 */
	public const DEFAULT_CLUSTER_GAP = 2 * HOUR_IN_SECONDS;

	protected static bool $_loaded = false;

	/** @var Meeting_GroupingSettings[] Keyed by Involvement Type ID. */
	protected static array $_types = [];
	protected static Meeting_GroupingSettings $_otherTypes;
	protected static bool $_skipScheduled = true;
	protected static bool $_keepHiddenChildren = false;
	protected static bool $_legacyAvailable = false;

	protected ?int $invTypeId = null;
	protected bool $includeChildren = false;
	protected bool $editions = false;
	protected bool $timeSlots = false;
	protected bool $clusters = false;
	protected bool $legacy = false;

	/**
	 * @param object $o          The stored settings for one Involvement Type.
	 * @param bool   $allowLegacy Whether the previous behavior may be selected for this Involvement Type.
	 */
	protected function __construct(object $o, bool $allowLegacy = false)
	{
		$this->invTypeId       = isset($o->invTypeId) && is_numeric($o->invTypeId) ? intval($o->invTypeId) : null;
		$this->includeChildren = ! ! ($o->includeChildren ?? false);
		$this->editions        = ! ! ($o->editions ?? false);
		$this->clusters        = ! ! ($o->clusters ?? false);
		$this->legacy          = $allowLegacy && ! ! ($o->legacy ?? false);

		// Time Slots are made from meetings of different involvements, so they require child involvements.
		$this->timeSlots = $this->includeChildren && ! ! ($o->timeSlots ?? false);

		if ($this->legacy) {
			$this->includeChildren = false;
			$this->editions        = false;
			$this->timeSlots       = false;
			$this->clusters        = false;
		}
	}

	public function __get($what)
	{
		if (property_exists(self::class, $what) && ! str_starts_with($what, '_')) {
			return $this->$what;
		}

		return Settings::UNDEFINED_PLACEHOLDER;
	}

	/**
	 * Load the settings, if they haven't been loaded yet.
	 *
	 * @return void
	 */
	protected static function load(): void
	{
		if (self::$_loaded) {
			return;
		}

		$json = TouchPointWP::instance()->settings->get('mc_grouping_json');
		$data = is_string($json) && $json !== '' ? json_decode($json) : null;

		if ( ! is_object($data)) {
			// Nothing has been saved yet.  Keep whatever the site did before.
			$data = self::defaultsForExistingSite();
		}

		self::applyData($data);
		self::$_loaded = true;
	}

	/**
	 * Set the static state from a decoded settings object.
	 *
	 * @param object $data
	 *
	 * @return void
	 */
	protected static function applyData(object $data): void
	{
		self::$_legacyAvailable    = ! ! ($data->legacyAvailable ?? false);
		self::$_skipScheduled      = ! ! ($data->skipScheduled ?? true);
		self::$_keepHiddenChildren = ! ! ($data->keepHiddenChildren ?? false);
		self::$_otherTypes         = new self((object)($data->otherTypes ?? []), self::$_legacyAvailable);
		self::$_otherTypes->invTypeId = null;

		self::$_types = [];
		foreach (is_array($data->types ?? null) ? $data->types : [] as $t) {
			if ( ! is_object($t) && ! is_array($t)) {
				continue;
			}
			$s = new self((object)$t);
			if ($s->invTypeId === null || isset(self::$_types[$s->invTypeId])) {
				continue; // Rows need a type, and each type can only be listed once.
			}
			self::$_types[$s->invTypeId] = $s;
		}
	}

	/**
	 * Whether the site used the previous behavior for collecting meetings.  This is true if the Meeting Calendar or
	 * any Involvement Post Type that imports meetings was set to collect them.
	 *
	 * @return bool
	 */
	protected static function previousBehaviorWasUsed(): bool
	{
		$settings = TouchPointWP::instance()->settings;

		if ($settings->enable_meeting_cal === 'on' &&
			($settings->mc_grouping_method ?: Meeting::GROUP_UNSCHEDULED) !== Meeting::GROUP_NONE) {
			return true;
		}

		// Read the stored JSON directly, rather than through Involvement_PostTypeSettings, which depends on settings.
		$invTypes = json_decode($settings->inv_json ?: '[]');
		foreach ($invTypes ?? [] as $it) {
			if (($it->importMeetings ?? false) &&
				($it->meetingGroupingMethod ?? Meeting::GROUP_NONE) !== Meeting::GROUP_NONE) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The settings for a site that hasn't saved Meeting Grouping settings yet.  Nothing changes: if meetings were
	 * collected before, the previous behavior continues to be used.
	 *
	 * @return object
	 */
	protected static function defaultsForExistingSite(): object
	{
		$legacy = self::previousBehaviorWasUsed();

		return (object)[
			'types'           => [],
			'otherTypes'      => (object)['legacy' => $legacy],
			'skipScheduled'   => true,
			'legacyAvailable' => $legacy,
		];
	}

	/**
	 * The settings for a new installation, which has never collected meetings.  Back-to-back meetings are grouped
	 * into Clusters.
	 *
	 * @return object
	 */
	public static function defaultsForNewSite(): object
	{
		return (object)[
			'types'           => [],
			'otherTypes'      => (object)['clusters' => true],
			'skipScheduled'   => true,
			'legacyAvailable' => false,
		];
	}

	/**
	 * Get the Meeting Grouping settings that apply to a given TouchPoint Involvement Type.  If the type doesn't have
	 * its own settings, the settings for all other types are returned.
	 *
	 * Note that when an involvement is included in its parent's structure (the parent's settings include child
	 * involvements), the parent's settings apply, not the child's.
	 *
	 * @param ?int $invTypeId The TouchPoint Involvement Type ID.
	 *
	 * @return Meeting_GroupingSettings
	 */
	public static function forInvolvementType(?int $invTypeId): Meeting_GroupingSettings
	{
		self::load();

		if ($invTypeId !== null && isset(self::$_types[$invTypeId])) {
			return self::$_types[$invTypeId];
		}

		return self::$_otherTypes;
	}

	/**
	 * Get the Meeting Grouping settings for Involvement Types that don't have their own settings.
	 *
	 * @return Meeting_GroupingSettings
	 */
	public static function forOtherTypes(): Meeting_GroupingSettings
	{
		self::load();

		return self::$_otherTypes;
	}

	/**
	 * Whether involvements with a weekly schedule should never have their meetings grouped, regardless of their
	 * Involvement Type.  This also prevents such involvements from being included in their parent's structure.
	 *
	 * Does not apply to the previous behavior, which has its own handling of scheduled involvements.
	 *
	 * @return bool
	 */
	public static function skipScheduled(): bool
	{
		self::load();

		return self::$_skipScheduled;
	}

	/**
	 * Whether the previous behavior (collecting meetings less than 23 hours apart) can be selected.  Only sites that
	 * used it before upgrading can select it.  It stays available even after other options are chosen.
	 *
	 * @return bool
	 */
	public static function legacyAvailable(): bool
	{
		self::load();

		return self::$_legacyAvailable;
	}

	/**
	 * Whether archived meetings of child involvements are kept after the child involvement's "Show in Sites" is turned
	 * off in TouchPoint.  If false, the child involvement's posts are removed when "Show in Sites" is turned off.  If
	 * true, its archived meetings are kept, but not its upcoming ones.
	 *
	 * @return bool
	 */
	public static function keepHiddenChildren(): bool
	{
		self::load();

		return self::$_keepHiddenChildren;
	}

	/**
	 * Get the parameters the TouchPoint Involvement query needs to find structure owners and their child involvements.
	 *
	 * - childTypes: the Involvement Type IDs whose settings include child involvements.
	 * - listedTypes: the Involvement Type IDs that have their own settings.
	 * - childOther: 1 if the settings for all other Involvement Types include child involvements.  This applies to
	 *   types that aren't listed, and to involvements without a type.
	 * - keepHidden: 1 if hidden child involvements should still be returned.  See keepHiddenChildren().
	 *
	 * @return array{childTypes: string, listedTypes: string, childOther: int, keepHidden: int}
	 */
	public static function involvementQueryParameters(): array
	{
		self::load();

		$childTypes = [];
		foreach (self::$_types as $id => $t) {
			if ($t->includeChildren) {
				$childTypes[] = $id;
			}
		}

		return [
			'childTypes'  => implode(',', $childTypes),
			'listedTypes' => implode(',', array_keys(self::$_types)),
			'childOther'  => self::$_otherTypes->includeChildren ? 1 : 0,
			'keepHidden'  => self::$_keepHiddenChildren ? 1 : 0,
		];
	}

	/**
	 * The gap, in seconds, after which a new Edition starts.  Measured from the end of one meeting to the start of the
	 * next.
	 *
	 * @return int
	 */
	public static function editionGap(): int
	{
		/**
		 * Adjust the gap after which a new Edition starts.  When Editions are used, meetings are split into a new
		 * Edition wherever the time from the end of one meeting to the start of the next is more than this.
		 *
		 * @since 0.0.98 Added
		 *
		 * @param int $seconds The gap, in seconds.  Default is 25 days.
		 */
		return intval(apply_filters('tp_meeting_edition_gap', self::DEFAULT_EDITION_GAP));
	}

	/**
	 * The maximum gap, in seconds, between back-to-back meetings in a Cluster.  Measured from the end of one meeting
	 * to the start of the next.
	 *
	 * @return int
	 */
	public static function clusterGap(): int
	{
		/**
		 * Adjust the maximum gap between back-to-back meetings in a Cluster.  Meetings of the same involvement are
		 * grouped into a Cluster when the time from the end of one to the start of the next is no more than this, and
		 * no other meeting is between them.
		 *
		 * @since 0.0.98 Added
		 *
		 * @param int $seconds The gap, in seconds.  Default is 2 hours.
		 */
		return intval(apply_filters('tp_meeting_cluster_gap', self::DEFAULT_CLUSTER_GAP));
	}

	/**
	 * Get the current settings as an object suitable for the settings form or for storage.
	 *
	 * @return object
	 */
	public static function toObject(): object
	{
		self::load();

		$types = [];
		foreach (self::$_types as $t) {
			$types[] = $t->rowObject();
		}

		return (object)[
			'types'              => $types,
			'otherTypes'         => self::$_otherTypes->rowObject(),
			'skipScheduled'      => self::$_skipScheduled,
			'keepHiddenChildren' => self::$_keepHiddenChildren,
			'legacyAvailable'    => self::$_legacyAvailable,
		];
	}

	/**
	 * Get the settings for this Involvement Type as a plain object.
	 *
	 * @return object
	 */
	protected function rowObject(): object
	{
		$o = (object)[
			'includeChildren' => $this->includeChildren,
			'editions'        => $this->editions,
			'timeSlots'       => $this->timeSlots,
			'clusters'        => $this->clusters,
		];

		if ($this->invTypeId === null) {
			$o->legacy = $this->legacy;
		} else {
			$o->invTypeId = $this->invTypeId;
		}

		return $o;
	}

	/**
	 * Validate and normalize the settings submitted from the settings form.
	 *
	 * @param mixed $new The JSON string submitted.
	 *
	 * @return string The JSON string to store.
	 */
	public static function validateNewSettings(mixed $new): string
	{
		$data = is_string($new) ? json_decode($new) : null;

		self::$_loaded = false;
		self::load(); // Current state, so that legacyAvailable can't be turned on by the form.
		$legacyAvailable = self::$_legacyAvailable;

		if ( ! is_object($data)) {
			return json_encode(self::toObject());
		}

		$data->legacyAvailable = $legacyAvailable;
		self::applyData($data);

		$r = json_encode(self::toObject());

		self::$_loaded = false; // Reload on next use, in case the value is filtered on save.

		return $r;
	}
}
