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
use tp\TouchPointWP\Interfaces\apiMeeting;

/**
 * Class MeetingArray
 *
 * A class to hold an array of meetings grouped into a larger event, like a conference.
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
	protected ?\stdClass $_involvement = null;
	protected array $_meetings = [];

	public string $slugToUse = "";
	public string $titleToUse = "";

	public function __construct($meetingArray = [], $involvement = null)
	{
		$this->_meetings = $meetingArray;
		$this->_involvement = $involvement;
	}

	public function __get(string $what)
	{
		if ($this->count() < 1) {
			return null;
		}

		return match ($what) {
			'name' => $this->_involvement?->name ?? "",
			'mtgId' => -1 * $this[0]->mtgId, // Meeting groups have negative meetingIds, which are the negative of the first meeting in the group
			'mtgStartDt' => $this->mtgStartDt(),
			'mtgEndDt' => $this->mtgEndDt(),
			'location' => $this->_involvement?->location ?? null,
			'status' => $this->status(),
			'involvementId' => $this->_involvement?->involvementId ?? null,
			default => null,
		};
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