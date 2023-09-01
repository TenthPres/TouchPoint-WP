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
}