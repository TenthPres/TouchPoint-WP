<?php
/**
 * @package TouchPointWP
 */

namespace tp\TouchPointWP\Utilities;

use tp\TouchPointWP\TouchPointWP;

/**
 * A collection of things that can be cast to a string by gluing them together with a simple implode().
 */
class NotableAttributes extends StringableArray
{
	public function __construct($initialValue = [])
	{
		parent::__construct($initialValue);
		$this->separator = TouchPointWP::$joiner;
		$this->itemPrefix = '<span class="meta-text">';
		$this->itemSuffix = "</span>";
	}
}