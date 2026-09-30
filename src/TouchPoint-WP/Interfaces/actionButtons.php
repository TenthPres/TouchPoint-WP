<?php
/**
 * @package TouchPointWP
 */

namespace tp\TouchPointWP\Interfaces;

use tp\TouchPointWP\Utilities\StringableArray;

/**
 * This is a base interface for classes that make action buttons available for a given object.
 */
interface actionButtons
{
	/**
	 * @param string|null $context A string that gives filters some context for where the request is coming from
	 * @param string      $btnClass HTML class names to put into the buttons/links
	 * @param bool        $withTouchPointLink Whether to include a link to the item within TouchPoint.
	 * @param bool        $absoluteLinks  Set true to make the links absolute, so they work from apps or emails.
	 *
	 * @return StringableArray
	 */
	public function getActionButtons(?string $context = null, string $btnClass = "", bool $withTouchPointLink = true, bool $absoluteLinks = false): StringableArray;
}