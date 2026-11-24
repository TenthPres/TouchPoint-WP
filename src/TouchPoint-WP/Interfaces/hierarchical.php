<?php
/**
 * @package TouchPointWP
 */

namespace tp\TouchPointWP\Interfaces;

if ( ! defined('ABSPATH')) {
	exit(1);
}


/**
 * Some items are hierarchical.  This trait provides a simpler interface for interacting with parents and allowing
 * attributes to be inherited by children from parents.
 *
 * @since 0.0.90 Added
 */
interface hierarchical
{
	/**
	 * Get the parent of this object **which may be an object of a different class**.
	 *
	 * Returns null if there is no parent. 
	 *
	 * @return mixed
	 */
	public function getParent(): mixed;

	/**
	 * Get a link to the parent, such as the event this is a part of.  Only a parent that's published is linked.
	 *
	 * @return ?string The HTML for the link, with the parent's title.  Null if there's no parent to link to.
	 *
	 * @since 0.2.2 Added
	 */
	public function parentLink(): ?string;

	/**
	 * Get the children to list on this object's page, in one list.
	 *
	 * @return object An object with `current` and `past` lists of the children, which can be printed with
	 *                printListItem().  Both are empty if this type of post doesn't list its children.
	 *
	 * @since 0.2.2 Added
	 */
	public function childItems(): object;
}