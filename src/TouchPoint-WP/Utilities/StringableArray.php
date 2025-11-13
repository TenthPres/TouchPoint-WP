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
	protected string $separator = "\n";
	protected string $itemPrefix = "";
	protected string $itemSuffix = "";

	/**
	 * StringableArray constructor.
	 *
	 * @param object|array $array
	 * @param int $flags
	 * @param string $iteratorClass
	 *
	 * @since 0.0.90 Added
	 * @since 0.0.96 Changed signature to reflect actual usage.
	 */
	public function __construct(object|array $array = [], int $flags = 0, string $iteratorClass = "ArrayIterator")
	{
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
	 * Get the stringable array, as an array.
	 *
	 * @return array
	 */
	public function toArray(): array
	{
		return $this->getArrayCopy();
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
	 * Get the keys of the array.
	 *
	 * @return array
	 */
	public function keys(): array
	{
		return array_keys($this->getArrayCopy());
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
	 * @param string|null $prefix
	 * @param string|null $postfix
	 *
	 * @return string
	 *
	 * @since 0.0.90 Added
	 * @since 0.0.96 Added $prefix and $postfix parameters to allow for more flexible joining (and particularly, HTML).
	 */
	public function join(?string $separator = null, ?string $prefix = null, ?string $postfix = null): string
	{
		if ($this->count() === 0) {
			return "";
		}
		if (is_null($separator)) {
			$separator = $this->separator;
		}
		if (is_null($prefix)) {
			$prefix = $this->itemPrefix;
		}
		if (is_null($postfix)) {
			$postfix = $this->itemSuffix;
		}
		$joiner = $postfix . $separator . $prefix;
		return $prefix . implode($joiner, $this->getArrayCopy()) . $postfix;
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