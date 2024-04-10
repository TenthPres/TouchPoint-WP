<?php
/**
 * @package TouchPointWP
 */

namespace tp\TouchPointWP\Utilities;

use ArrayObject;

/**
 * A collection of things that can be cast to a string by gluing them together with a simple implode().
 */
class StringableArray extends ArrayObject
{
	protected string $separator;

	/**
	 * StringableArray constructor.
	 *
	 * @param string $separator
	 * @param object|array $array
	 * @param int $flags
	 * @param string $iteratorClass
	 */
	public function __construct(string $separator = "\n ", object|array $array = [], int $flags = 0, string $iteratorClass = "ArrayIterator")
	{
		$this->separator = $separator;
		parent::__construct($array, $flags, $iteratorClass);
	}

	/**
	 * Standard method to stringify.
	 *
	 * @return string
	 */
	public function __toString()
	{
		return implode($this->separator, $this->getArrayCopy());
	}
}