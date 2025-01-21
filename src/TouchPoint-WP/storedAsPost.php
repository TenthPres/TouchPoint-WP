<?php
/**
 * @package TouchPointWP
 */

namespace tp\TouchPointWP;

use WP_Post;

/**
 * This is a base interface for classes that have "schedule" strings.
 */
interface storedAsPost
{
	/**
	 * Get the WP_Post object corresponding to the object.
	 *
	 * @param bool $create Set true if the post should be created if it doesn't exist.  This would need to be
	 * implemented in each module, and is not implemented in most.
	 *
	 * @return WP_Post|null
	 */
	public function getPost(bool $create = false): ?WP_Post;
}