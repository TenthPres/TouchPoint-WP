<?php

/**
 * @package TouchPointWP
 */

namespace tp\TouchPointWP;

use tp\TouchPointWP\Interfaces\module;
use tp\TouchPointWP\Interfaces\storedAsPost;
use tp\TouchPointWP\Utilities\StringableArray;
use WP_Post;

require_once 'Interfaces/module.php';
require_once 'Interfaces/storedAsPost.php';

/**
 * This is a base class for those objects that can be derived from a Post.
 */
abstract class PostTypeCapable implements module, storedAsPost
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
	 * @param array $exclude Attributes listed here will be excluded.  (e.g. if shown for a parent, not needed here.)
	 *
	 * @return string[]
	 */
	public abstract function notableAttributes(array $exclude = []): array;

	/**
	 * Handle exclusions for the notableAttributes $exclusion variable.
	 *
	 * Removes all array items that have a value or key contained in the $exclude array's values.
	 *
	 * @param array $subject
	 * @param array $exclude
	 *
	 * @return array
	 */
	protected function processAttributeExclusions(array $subject, array $exclude): array
	{
		$subject = array_diff($subject, $exclude);
		foreach ($exclude as $e) {
			if (isset($subject[$e])) {
				unset($subject[$e]);
			}
		}
		return $subject;
	}

	/**
	 * @param string|null $context A string that gives filters some context for where the request is coming from
	 * @param string      $btnClass HTML class names to put into the buttons/links
	 * @param bool        $withTouchPointLink Whether to include a link to the item within TouchPoint.
	 * @param bool        $absoluteLinks  Set true to make the links absolute, so they work from apps or emails.
	 *
	 * @return StringableArray
	 */
	public abstract function getActionButtons(?string $context = null, string $btnClass = "", bool $withTouchPointLink = true, bool $absoluteLinks = false): StringableArray;

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