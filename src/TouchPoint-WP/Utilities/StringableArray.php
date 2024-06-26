<?php
/**
 * @package TouchPointWP
 */

namespace tp\TouchPointWP\Utilities;

use ArrayObject;
use tp\TouchPointWP\Utilities;

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
	 * Length of array.
	 *
	 * @return int
	 */
	public function count(): int
	{
		return count($this->getArrayCopy());
	}

	/**
	 * Append to the end of the array.
	 *
	 * @param mixed $value
	 */
	public function prepend($value): void
	{
		$array = $this->getArrayCopy();
		array_unshift($array, $value);
		$this->exchangeArray($array);
	}

	/**
	 * Determine if the stringable array (haystack) contains the given needle
	 *
	 * @param $needle
	 *
	 * @return bool
	 */
	public function contains($needle): bool
	{
		return in_array($needle, $this->getArrayCopy());
	}

	/**
	 * Standard method to stringify.
	 *
	 * @return string
	 */
	public function __toString()
	{
		return $this->join();
	}

	/**
	 * Link the items together with a given separator, which may be different from the separator used in the constructor.
	 *
	 * @param string|null $separator
	 *
	 * @return string
	 *
	 * @since 0.0.90 Added
	 */
	public function join(string $separator = null): string
	{
		if (is_null($separator)) {
			$separator = $this->separator;
		}
		return implode($separator, $this->getArrayCopy());
	}

	/**
	 * Convert the array to a list string with ampersands and such.
	 *
	 * @return string
	 */
	public function toListString(): string
	{
		return Utilities::stringArrayToListString($this->getArrayCopy());
	}
}