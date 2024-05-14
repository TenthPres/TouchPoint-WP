<?php
/**
 * @package TouchPointWP
 */

namespace tp\TouchPointWP\Utilities;

use ArrayObject;
use tp\TouchPointWP\Person;

/**
 * A collection of people, easily cast to string.
 */
class PersonArray extends ArrayObject
{
	public function __toString()
	{
		return Person::arrangeNamesForPeople($this) ?? "";
	}

	/**
	 * Get the list of people, as a human-readable list of people, with links to their author pages.
	 *
	 * @return string
	 */
	public function toLinks(): string
	{
		return Person::arrangeNamesForPeople($this, true) ?? "";
	}
}