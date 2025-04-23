<?php

namespace tp\TouchPointWP\Interfaces;

use DateTimeInterface;

/**
 * For meeting synchronization to assist with both Meetings and MeetingArrays.
 *
 * @property-read string name
 * @property-read int mtgId
 * @property-read DateTimeInterface mtgStartDt
 * @property-read DateTimeInterface mtgEndDt
 * @property-read ?string location
 * @property-read int status
 */
interface apiMeeting {
	public function __get(string $what);
}