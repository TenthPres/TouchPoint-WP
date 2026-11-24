<?php
/**
 * @package TouchPointWP
 */

namespace tp\TouchPointWP;

if ( ! defined('ABSPATH')) {
	exit(1);
}

if ( ! TOUCHPOINT_COMPOSER_ENABLED) {
	require_once "Interfaces/involvementMeetingCommon.php";
}

use tp\TouchPointWP\Interfaces\involvementMeetingCommon;
use WP_Post;

/**
 * The base for the posts that can have a parent and children of their own kind: Involvements and Meetings.  Partners,
 * which have neither, are PostTypeCapable but not this.
 *
 * Everything here works from the post, so a template can ask an Involvement or a Meeting the same questions.
 *
 * @since 0.2.2 Added
 */
abstract class InvolvementOrMeeting extends PostTypeCapable implements involvementMeetingCommon
{
	/**
	 * Get a link to the post's parent, such as the event this is a part of.  Only a parent that's published is linked.
	 *
	 * @return ?string The HTML for the link, with the parent's title.  Null if there's no parent to link to.
	 *
	 * @since 0.2.2 Added
	 */
	public function parentLink(): ?string
	{
		$parent = $this->getParent();
		if ($parent === null || $parent->post_id() === $this->post_id()) {
			return null;
		}

		$parentPost = get_post($parent->post_id());
		if ($parentPost === null || $parentPost->post_status !== 'publish') {
			return null;
		}

		return "<a href=\"" . esc_url(get_permalink($parentPost)) . "\">" . esc_html(get_the_title($parentPost)) . "</a>";
	}

	/**
	 * Get a notice to show about the post's status, such as that an event has been cancelled.  There's nothing to say
	 * about most involvements.
	 *
	 * @return ?object An object with a `level`, which is "alert" or "info", and a `message`.  Null if there isn't a notice.
	 *
	 * @since 0.2.2 Added
	 */
	public function statusNotice(): ?object
	{
		return null;
	}

	/**
	 * Get the posts to list on this post's page: its child involvements, meetings, and groups of meetings (Editions and
	 * Clusters), in one list.  Hidden posts aren't included.
	 *
	 * Child involvements, which have no dates of their own, come first, by title.  Then come the items that haven't
	 * ended, by date.  The items that are over are separate, with the newest first.
	 *
	 * @return object An object with `current` and `past` lists of PostTypeCapable objects, which can be printed with
	 *                printListItem().  Both are empty if this type of post doesn't list its children.
	 *
	 * @since 0.2.2 Added
	 */
	public function childItems(): object
	{
		$post  = $this->getPost();
		$items = (object)['current' => [], 'past' => []];

		$settings = Involvement::getSettingsForPostType($post->post_type);
		if ($settings === null ||
			! ($settings->hierarchical ||
			   ($settings->importMeetings && TouchPointWP::instance()->settings->enable_meeting_cal === "on"))) {
			return $items;
		}

		$now     = time();
		$current = [];
		$past    = [];

		foreach (get_children(['post_parent' => $post->ID, 'post_type' => $post->post_type]) as $child) {
			/** @var WP_Post $child */
			if (Meeting::isSpanningMeetingOf($child, $post)) {
				continue; // An Edition's spanning meeting is the Edition itself, as far as visitors are concerned.
			}

			$start = intval(get_post_meta($child->ID, Meeting::MEETING_START_META_KEY, true));
			$end   = intval(get_post_meta($child->ID, Meeting::MEETING_END_META_KEY, true)) ?: $start;

			if ($start === 0) {
				// Child involvements have no dates of their own.  They come first, as before.
				$current[] = [0, $child->post_title, $child];
			} elseif ($end >= $now) {
				$current[] = [1, $start, $child];
			} else {
				$past[] = [$start, $child];
			}
		}

		usort($current, fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
		usort($past, fn($a, $b) => $b[0] <=> $a[0]); // Newest first

		$items->current = self::postsToObjects(array_map(fn($c) => $c[2], $current));
		$items->past    = self::postsToObjects(array_map(fn($c) => $c[1], $past));

		return $items;
	}

	/**
	 * Make objects from posts, leaving out any that can't be made into one.
	 *
	 * @param WP_Post[] $posts
	 *
	 * @return PostTypeCapable[]
	 */
	private static function postsToObjects(array $posts): array
	{
		$objects = [];
		foreach ($posts as $post) {
			try {
				$object = self::fromPost($post);
			} catch (TouchPointWP_Exception) {
				$object = null;
			}
			if ($object !== null) {
				$objects[] = $object;
			}
		}

		return $objects;
	}

	/**
	 * Print this post as an item in a list of posts, such as the list of its parent's children.  A theme can replace the
	 * item with a template part named list-item-event-list-item (for meetings) or list-item-involvement-list-item.
	 *
	 * The list item changes the current post, so wp_reset_postdata() should be called after the list.
	 *
	 * @return void
	 *
	 * @since 0.2.2 Added
	 */
	public function printListItem(): void
	{
		global $post;
		$post = $this->getPost();
		setup_postdata($post);

		TouchPointWP::enqueuePartialsStyle("involvement-single child-item");

		$loadedPart = false;
		if (Meeting::postIsType($post)) {
			$loadedPart = get_template_part('list-item', 'event-list-item');
		}
		if ($loadedPart === false) {
			$loadedPart = get_template_part('list-item', 'involvement-list-item');
		}
		if ($loadedPart === false) {
			// The plugin's list item uses this to list its own children.  It can't work it out for a meeting.
			$settings = Involvement::getSettingsForPostType($post->post_type);
			require TouchPointWP::$dir . "/src/templates/parts/involvement-list-item.php";
		}
	}
}
