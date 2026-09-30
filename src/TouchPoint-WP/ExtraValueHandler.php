<?php
/**
 * @package TouchPointWP
 */

namespace tp\TouchPointWP;

use DateTime;

/**
 * Classes that have extra values should implement the ExtraValues trait, which provides a ExtraValues() method which
 * returns an instance of this class and allows getting the values via its getter. That trait has the abstract methods
 * that need to be implemented for Extra Values to be available.
 */
class ExtraValueHandler
{
	/** @var object|extraValues $owner The object that has extra values */
	protected object $owner; // Must have the extraValues trait.

	/**
	 * @throws TouchPointWP_Exception
	 */
	public function __construct(object $owner)
	{
		if ( ! in_array(extraValues::class, class_uses($owner, false))) {
			throw new TouchPointWP_Exception("An object of type " . get_class($owner) . " does not support Extra Values.");
		}
		$this->owner = $owner;
	}

	public static function standardizeExtraValueName(string $name): string
	{
		return preg_replace("/[^A-Za-z0-9]/", '', $name);
	}

	/**
	 * Take an array or object of Extra Values and change the values to their proper datatypes.
	 *
	 * @param object $evs
	 *
	 * @return object
	 */
	public static function jsonToDataTyped(object $evs): object
	{
		foreach ($evs as $ev) {
			if ($ev->type == "Date") {
				$siteTz = wp_timezone();
				$ev->value = DateTime::createFromFormat("Y-m-d\TH:i:s", $ev->value, $siteTz);
			}
		}

		return $evs;
	}

	public function __get($what)
	{
		$what = self::standardizeExtraValueName($what);

		return $this->owner->getExtraValue($what);
	}

	public function __isset($name)
	{
		return $this->owner->getExtraValue($name) !== null;
	}

	public function __call($name, $arguments)
	{
		return $this->__get($name);
	}
}