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
		return get_permalink($this->post_id);
	}

	/**
	 * Get the title for display within its parent, such as in the parent's list of its parts.  If the title starts
	 * with the parent's title and a separator, the parent's title is left out, since the parent's title is
	 * already visible: "Global Outreach Conference: Q&A Luncheon" is displayed as "Q&A Luncheon" within "Global Outreach
	 * Conference".  The stored title isn't changed.
	 *
	 * The result is filtered like any other title, so it can be printed in place of the_title().
	 *
	 * @param ?WP_Post $parent The parent to display this within.  Default is its own parent.
	 *
	 * @return string
	 *
	 * @since 0.0.98 Added
	 */
	public function titleWithinParent(?WP_Post $parent = null): string
	{
		$post      = $this->getPost();
		$fullTitle = get_the_title($post);
		$parent    ??= $post->post_parent ? get_post($post->post_parent) : null;
		$title     = $fullTitle;

		if ($parent !== null && $parent->post_type === $post->post_type) {
			$shortTitle = Utilities::titleWithoutPrefix($post->post_title, $parent->post_title);
			if ($shortTitle !== trim($post->post_title)) {
				$title = apply_filters('the_title', $shortTitle, $post->ID);
			}
		}

		/**
		 * Allows the title that's displayed for a post within its parent to be adjusted.  By default, the parent's title
		 * is removed from the start of the post's title when the two are separated by a colon, dash, or similar.  This
		 * can be used to handle other patterns, such as abbreviations, or to turn the behavior off by returning the full
		 * title.
		 *
		 * This isn't used where a post is displayed on its own page, or outside of its parent, such as in the calendar.
		 *
		 * @see PostTypeCapable::titleWithinParent()
		 *
		 * @since 0.0.98 Added
		 *
		 * @param string   $title     The title to display, filtered like any other title.
		 * @param WP_Post  $post      The post whose title is displayed.
		 * @param ?WP_Post $parent    The parent it's displayed within, if any.
		 * @param string   $fullTitle The post's full title, filtered like any other title.
		 */
		return (string)apply_filters('tp_title_within_parent', $title, $post, $parent, $fullTitle);
	}

	/**
	 * Get the title for display in a list.  If the list is on the page of this one's parent, this is the title within
	 * its parent (see titleWithinParent()).  Otherwise, such as in an archive or a list on some other page, this is
	 * displayed on its own, and its full title is needed.
	 *
	 * @return string
	 *
	 * @since 0.0.98 Added
	 */
	public function titleInList(): string
	{
		$post = $this->getPost();

		if ($post->post_parent && is_singular() && get_queried_object_id() === intval($post->post_parent)) {
			return $this->titleWithinParent();
		}

		return get_the_title($post);
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