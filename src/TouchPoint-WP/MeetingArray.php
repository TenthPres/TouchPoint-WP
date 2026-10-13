<?php

namespace tp\TouchPointWP;

if ( ! TOUCHPOINT_COMPOSER_ENABLED) {
	require_once "Interfaces/apiMeeting.php";
}

use ArrayAccess;
use ArrayIterator;
use Countable;
use DateTimeInterface;
use IteratorAggregate;
use stdClass;
use tp\TouchPointWP\Interfaces\apiMeeting;

/**
 * Class MeetingArray
 *
 * A class to hold an array of meetings grouped into a larger event, like a conference.
 *
 * With Meeting Grouping, a MeetingArray can also be an Edition or Cluster, and can contain other
 * MeetingArrays.  See Meeting_GroupingPlanner.
 *
 * @package tp\TouchPointWP
 *
 * @property-read string name
 * @property-read int mtgId
 * @property-read DateTimeInterface mtgStartDt
 * @property-read DateTimeInterface mtgEndDt
 * @property-read ?string location
 * @property-read int status
 * @property-read ?int involvementId
 */
class MeetingArray implements apiMeeting, IteratorAggregate, ArrayAccess, Countable
{
	public const ROLE_EDITION = "edition";
	public const ROLE_CLUSTER = "cluster";

	protected ?stdClass $_involvement = null; // This is NOT an Involvement class instance.
	protected array $_meetings = [];

	public string $slugToUse = "";
	public string $titleToUse = "";

	/**
	 * @var ?string The grouping type: one of the ROLE_ constants, or null for the previous behavior's collections.
	 */
	public ?string $groupRole = null;

	/**
	 * @var ?object For an Edition, its spanning meeting: one of the structure owner's own meetings that covers the whole
	 *              Edition, such as a week-long conference meeting.  The Edition takes its name, and the meeting isn't
	 *              listed within the Edition.  It's still one of the Edition's meetings.  Null if there isn't one.
	 */
	public ?object $spanningMeeting = null;

	/**
	 * @var ?bool Whether this group is inside another group.  Null (not false) when unknown, so the previous
	 *            behavior's isset() check still treats its collections as top-level.
	 */
	public ?bool $isGroupMember = null;

	/**
	 * @param array     $meetingArray The meetings, or other MeetingArrays, in this group.
	 * @param ?stdClass $involvement  The involvement this group represents (from the API, not an Involvement object).
	 * @param ?string   $groupRole    The grouping type, one of the ROLE_ constants.
	 */
	public function __construct($meetingArray = [], $involvement = null, ?string $groupRole = null)
	{
		$this->_meetings = $meetingArray;
		$this->_involvement = $involvement;
		$this->groupRole = $groupRole;
	}

	public function __get(string $what)
	{
		if ($this->count() < 1) {
			return null;
		}

		return match ($what) {
			'name' => $this->_involvement?->name ?? "",
			'mtgId' => -1 * $this->firstMeeting()->mtgId, // Meeting groups have negative meetingIds, which are the negative of the first meeting in the group
			'mtgStartDt' => $this->mtgStartDt(),
			'mtgEndDt' => $this->mtgEndDt(),
			'location' => $this->_involvement?->location ?? null,
			'status' => $this->status(),
			'involvementId' => $this->_involvement?->involvementId ?? null,
			default => null,
		};
	}

	/**
	 * Get the individual meetings in this group, including those within nested groups, in the order they were added.
	 *
	 * @return object[]
	 */
	public function leafMeetings(): array
	{
		$r = [];
		foreach ($this as $item) {
			if ($item instanceof MeetingArray) {
				$r = [...$r, ...$item->leafMeetings()];
			} else {
				$r[] = $item;
			}
		}
		return $r;
	}

	/**
	 * Get the first meeting in the group, which determines the group's (negative) mtgId.
	 *
	 * For the previous behavior's collections (no groupRole), this is the first meeting added, which is how those
	 * collections have always been identified.  Existing posts are found by that ID, so it must not change.
	 *
	 * For Editions and Clusters, it's the earliest meeting: by start, then end, then lowest meeting ID.
	 * This doesn't depend on the order the meetings were added.
	 *
	 * @return object
	 */
	public function firstMeeting(): object
	{
		$leaves = $this->leafMeetings();

		if ($this->groupRole === null) {
			return $leaves[0];
		}

		$first = null;
		foreach ($leaves as $m) {
			if ($first === null || self::sortKey($m) < self::sortKey($first)) {
				$first = $m;
			}
		}
		return $first;
	}

	/**
	 * A key for putting meetings in chronological order: start, then end (or start, if there's no end), then meeting ID.
	 *
	 * @param object $m
	 *
	 * @return int[]
	 */
	protected static function sortKey(object $m): array
	{
		return [
			$m->mtgStartDt->getTimestamp(),
			($m->mtgEndDt ?? $m->mtgStartDt)->getTimestamp(),
			intval($m->mtgId)
		];
	}

	/**
	 * Get the first start time of the meetings in the array.
	 *
	 * @return DateTimeInterface
	 */
	public function mtgStartDt(): DateTimeInterface
	{
		$minDate = null;
		foreach ($this as $meeting) {
			if ($minDate === null || $meeting->mtgStartDt < $minDate) {
				$minDate = $meeting->mtgStartDt;
			}
		}
		return $minDate;
	}

	/**
	 * Get the last end time (or start time) of the meetings in the array.
	 *
	 * @return DateTimeInterface
	 */
	public function mtgEndDt(): DateTimeInterface
	{
		$maxDate = null;
		foreach ($this as $meeting) {
			if ($maxDate === null || ($meeting->mtgEndDt ?? $meeting->mtgStartDt) > $maxDate) {
				$maxDate = ($meeting->mtgEndDt ?? $meeting->mtgStartDt);
			}
		}
		return $maxDate;
	}

	/**
	 * Get the status of the meetings in the array.
	 *
	 * @return int
	 */
	public function status(): int
	{
		$status = 0;
		foreach ($this as $meeting) {
			if ($meeting->status > $status) {
				$status = $meeting->status;
			}
		}
		return $status;
	}

	/**
	 * Retrieve an external iterator
	 *
	 * @link https://php.net/manual/en/iteratoraggregate.getiterator.php
	 */
	public function getIterator(): ArrayIterator
	{
		return new ArrayIterator($this->_meetings);
	}

	/**
	 * Whether a offset exists
	 *
	 * @link https://php.net/manual/en/arrayaccess.offsetexists.php
	 */
	public function offsetExists(mixed $offset): bool
	{
		return isset($this->_meetings[$offset]);
	}

	/**
	 * Offset to retrieve
	 *
	 * @link https://php.net/manual/en/arrayaccess.offsetget.php
	 */
	public function offsetGet(mixed $offset): mixed
	{
		if (isset($this->_meetings[$offset])) {
			return $this->_meetings[$offset];
		}

		return null;
	}

	/**
	 * Offset to set
	 *
	 * @link https://php.net/manual/en/arrayaccess.offsetset.php
	 */
	public function offsetSet(mixed $offset, mixed $value): void
	{
		if ($offset === null) {
			$this->_meetings[] = $value;
		} else {
			$this->_meetings[$offset] = $value;
		}
	}

	/**
	 * Offset to unset
	 *
	 * @link https://php.net/manual/en/arrayaccess.offsetunset.php
	 */
	public function offsetUnset(mixed $offset): void
	{
		if (isset($this->_meetings[$offset])) {
			unset($this->_meetings[$offset]);
		}
	}

	/**
	 * Count meetings in the array
	 *
	 * @link https://php.net/manual/en/countable.count.php
	 */
	public function count(): int
	{
		return count($this->_meetings);
	}
}