<?php

namespace tp\TouchPointWP;

/**
 * A collection of people, easily cast to string.
 */
class PersonArray extends \ArrayObject
{
	public function __toString()
	{
		return Person::arrangeNamesForPeople($this);
	}
}