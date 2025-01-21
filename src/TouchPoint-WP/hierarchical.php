<?php
/**
 * @package TouchPointWP
 */

namespace tp\TouchPointWP;

use WP_Post;

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
}