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
	 * Append to the start of the array.
	 *
	 * @param mixed $value
	 * @param null  $key
	 */
	public function prepend(mixed $value, $key = null): void
	{
		$array = $this->getArrayCopy();
		if (!is_null($key)) {
			$array = array_merge([$key => $value], $array);
		} else {
			$array = array_merge([$value], $array);
		}
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
	public function join(?string $separator = null): string
	{
		if (is_null($separator)) {
			$separator = $this->separator;
		}
		return implode($separator, $this->getArrayCopy());
	}

	/**
	 * Convert the array to a list string with ampersands and such.
	 *
	 * @param int  $limit
	 * @param bool $andOthers
	 *
	 * @return string
	 */
	public function toListString(int $limit = PHP_INT_MAX, bool $andOthers = false): string
	{
		return Utilities::stringArrayToListString($this->getArrayCopy(), $limit, $andOthers);
	}
}