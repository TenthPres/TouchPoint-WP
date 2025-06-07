<?php

/**
 * @package TouchPointWP
 */

namespace tp\TouchPointWP;

use ArrayAccess;
use tp\TouchPointWP\Interfaces\actionButtons;
use tp\TouchPointWP\Interfaces\module;
use tp\TouchPointWP\Interfaces\storedAsPost;
use tp\TouchPointWP\Utilities\NotableAttributes;
use tp\TouchPointWP\Utilities\StringableArray;
use WP_Post;

require_once 'Interfaces/actionButtons.php';
require_once 'Interfaces/module.php';
require_once 'Interfaces/storedAsPost.php';

/**
 * This is a base class for those objects that can be derived from a Post.
 */
abstract class PostTypeCapable implements module, storedAsPost, actionButtons
{

	protected int $post_id;
	protected ?WP_Post $post = null;


	/**
	 * Get the Post_id.
	 *
	 * @return int
	 */
	public function post_id(): int
	{
		return $this->post_id;
	}

	public function getPost(bool $create = false): ?WP_Post
	{
		if ($this->post === null) {
			$this->post = get_post($this->post_id);
		}
		return $this->post;
	}

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
	 * @param array|StringableArray $exclude Attributes listed here will be excluded.  (e.g. if shown for a parent, not needed here.)
	 *
	 * @return NotableAttributes
	 */
	public abstract function notableAttributes(array|StringableArray $exclude = []): NotableAttributes;

	/**
	 * Handle exclusions for the notableAttributes $exclusion variable.
	 *
	 * Removes all array items that have a value or key contained in the $exclude array's values.
	 *
	 * @param StringableArray $subject
	 * @param array           $exclude
	 *
	 * @return NotableAttributes
	 */
	protected function processAttributeExclusions(StringableArray $subject, array $exclude): NotableAttributes
	{
		$subject = array_diff($subject->getArrayCopy(), $exclude);
		foreach ($exclude as $e) {
			if (isset($subject[$e])) {
				unset($subject[$e]);
			}
		}
		return new NotableAttributes($subject);
	}

	/**
	 * Indicates if the given post can be instantiated as the given post type.
	 *
	 * @param WP_Post $post
	 *
	 * @return bool
	 */
	public static abstract function postIsType(WP_Post $post): bool;


	/**
	 * Indicates if the given post type name is the post type for this class.
	 *
	 * @param string $postType
	 *
	 * @return bool
	 */
	public static abstract function postTypeMatches(string $postType): bool;


	/**
	 * Gets a TouchPoint item ID number, regardless of what type of object this is.
	 *
	 * @return int
	 */
	public abstract function getTouchPointId(): int;

}