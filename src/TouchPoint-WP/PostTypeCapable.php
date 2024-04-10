<?php

/**
 * @package TouchPointWP
 */

namespace tp\TouchPointWP;

use tp\TouchPointWP\Utilities\StringableArray;
use WP_Post;

/**
 * This is a base class for those objects that can be derived from a
 */
abstract class PostTypeCapable implements module {

	/**
	 * Create relevant objects from a given post
	 *
	 * @param WP_Post $post
	 *
	 * @return ?PostTypeCapable
	 * @throws TouchPointWP_Exception
	 */
	public static function fromPost(WP_Post $post): ?self
	{
		if (Involvement::postIsType($post)) {
			return Involvement::fromPost($post);
		}
		if (Meeting::postIsType($post)) {
			return Meeting::fromPost($post);
		}
		if (Partner::postIsType($post)) {
			return Partner::fromPost($post);
		}
		return null;
	}

	/**
	 * Get the link for the post.
	 *
	 * @return string
	 */
	public function permalink(): string
	{
		return get_permalink($this->post);
	}

	/**
	 * Get notable attributes.
	 *
	 * @param array $exclude Attributes listed here will be excluded.  (e.g. if shown for a parent, not needed here.)
	 *
	 * @return string[]
	 */
	public abstract function notableAttributes(array $exclude = []): array;

	public abstract function getActionButtons(string $context, string $btnClass): StringableArray;

	/**
	 * Indicates if the given post can be instantiated as the given post type.
	 *
	 * @param WP_Post $post
	 *
	 * @return bool
	 */
	public static abstract function postIsType(WP_Post $post): bool;


	/**
	 * Gets a TouchPoint item ID number, regardless of what type of object this is.
	 *
	 * @return int
	 */
	public abstract function getTouchPointId(): int;

}