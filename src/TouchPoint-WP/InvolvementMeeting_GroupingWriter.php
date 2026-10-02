<?php
/**
 * @package TouchPointWP
 */

namespace tp\TouchPointWP;

use tp\TouchPointWP\Utilities\Translation;
use WP_Post;
use WP_Query;

if ( ! defined('ABSPATH')) {
	exit(1);
}

/**
 * Writes the posts for a grouping plan from InvolvementMeeting_GroupingPlanner: finds existing posts wherever they are
 * in the tree, moves them into place, and creates posts that don't exist yet.
 *
 * Archived posts (those whose end is more than Archive After Days in the past) keep their content, title, and thumbnail.
 * They may be moved, and their slug may change if it would collide with a sibling.
 *
 * This is a trait of Involvement, so it can use Involvement's protected sync methods.
 *
 * @since 0.0.98 Added
 */
trait InvolvementMeeting_GroupingWriter
{
	/**
	 * Slug formats for Editions, in increasing specificity.  A bare year isn't used, because WordPress reads a numeric
	 * last URL segment as a page number.
	 */
	private static array $editionSlugFormats = ['Y-m', 'Y-m-d', 'Y-m-d-g', 'Y-m-d-ga', 'Y-m-d-gia', 'Y-m-d-His'];

	/**
	 * Slug formats for Time Slots, in increasing specificity.
	 */
	private static array $timeSlotSlugFormats = ['Y-m-d-Hi', 'Y-m-d-His'];

	/**
	 * Slug formats for meetings, and for Clusters whose title slug is taken, in increasing specificity.
	 */
	private static array $meetingSlugFormats = ['Y-m', 'Y-m-d', 'Y-m-d-g', 'Y-m-d-ga', 'Y-m-d-gia', 'Y-m-d-His'];

	/**
	 * Write the posts for a grouping plan.
	 *
	 * @param WP_Post                      $ownerPost    The structure owner's post.
	 * @param object                       $owner        The structure owner, as provided by the API.
	 * @param object[]                     $involvements The involvements in the structure, including the owner.
	 * @param array                        $items        The plan, from InvolvementMeeting_GroupingPlanner::plan().
	 * @param Involvement_PostTypeSettings $typeSets
	 * @param int                          $imagePostId  The image to use as the thumbnail, or 0 for none.
	 * @param bool                         $verbose
	 * @param bool                         $applyChanges
	 *
	 * @return int[] The IDs of the posts that were written, and should be kept.
	 */
	protected static function writeGroupingPlan(
		WP_Post $ownerPost,
		object $owner,
		array $involvements,
		array $items,
		Involvement_PostTypeSettings $typeSets,
		int $imagePostId,
		bool $verbose = false,
		bool $applyChanges = true
	): array {
		$ctx = (object)[
			'postType'     => $typeSets->postTypeWithPrefix(),
			'owner'        => $owner,
			'ownerPost'    => $ownerPost,
			'involvements' => [],
			'imagePostId'  => $imagePostId,
			'cutoff'       => self::updateExpiry()->getTimestamp(),
			'verbose'      => $verbose,
			'apply'        => $applyChanges,
			'meetingPosts' => [], // mtgId => WP_Post
			'combined'     => [], // mtgId => true, for meeting posts that were also a child involvement's post
			'groupPosts'   => [], // spl_object_id of a planned group => WP_Post
			'keep'         => [],
		];
		foreach ($involvements as $inv) {
			$ctx->involvements[$inv->involvementId] = $inv;
		}
		$ctx->involvements[$owner->involvementId] = $owner;

		$leaves = [];
		$groups = [];
		self::collectPlannedItems($items, $leaves, $groups);

		$childInvIds = array_values(array_diff(array_keys($ctx->involvements), [$owner->involvementId]));
		$ctx->meetingPosts = self::findMeetingPosts(
			$ctx->postType,
			array_map(fn($m) => $m->mtgId, $leaves),
			$childInvIds,
			$verbose
		);
		foreach ($ctx->meetingPosts as $mid => $p) {
			if (get_post_meta($p->ID, TouchPointWP::INVOLVEMENT_META_KEY, true) !== "") {
				$ctx->combined[$mid] = true;
			}
		}
		$ctx->groupPosts   = self::matchGroupPosts($ctx, $groups);

		self::writeGroupingItems($ownerPost, $items, false, false, $ctx);

		return $ctx->keep;
	}

	/**
	 * Flatten a plan into its meetings and its groups.
	 *
	 * @param array          $items
	 * @param object[]       $leaves Collects the meetings.
	 * @param MeetingArray[] $groups Collects the groups, parents before children.
	 *
	 * @return void
	 */
	private static function collectPlannedItems(array $items, array &$leaves, array &$groups): void
	{
		foreach ($items as $item) {
			if ($item instanceof MeetingArray) {
				$groups[] = $item;
				self::collectPlannedItems(iterator_to_array($item), $leaves, $groups);
			} else {
				$leaves[] = $item;
			}
		}
	}

	/**
	 * Find the existing posts for meetings, anywhere within the post type.
	 *
	 * Posts that are also an involvement's post (from an involvement with a single meeting) are only included if the
	 * involvement is one of the included child involvements.  Such a post becomes the meeting's post, so its archived
	 * content isn't lost when the child's own post goes away.
	 *
	 * If a meeting has more than one post, the oldest is used.  The others aren't kept, so they're removed at the end
	 * of the sync.
	 *
	 * @param string $postType
	 * @param int[]  $mtgIds
	 * @param int[]  $childInvIds The involvement IDs of the included child involvements.
	 * @param bool   $verbose
	 *
	 * @return WP_Post[] Keyed by meeting ID.
	 */
	private static function findMeetingPosts(string $postType, array $mtgIds, array $childInvIds, bool $verbose): array
	{
		if (count($mtgIds) === 0) {
			return [];
		}

		$q = new WP_Query([
			'post_type'      => $postType,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'meta_query'     => [
				'relation' => 'AND',
				[
					'key'     => Meeting::MEETING_META_KEY,
					'value'   => array_map('strval', $mtgIds),
					'compare' => 'IN',
				],
				count($childInvIds) === 0 ? [
					'key'     => TouchPointWP::INVOLVEMENT_META_KEY,
					'compare' => 'NOT EXISTS',
				] : [
					'relation' => 'OR',
					[
						'key'     => TouchPointWP::INVOLVEMENT_META_KEY,
						'compare' => 'NOT EXISTS',
					],
					[
						'key'     => TouchPointWP::INVOLVEMENT_META_KEY,
						'value'   => array_map('strval', $childInvIds),
						'compare' => 'IN',
					],
				],
			],
		]);

		$r = [];
		foreach ($q->get_posts() as $p) {
			$mid = intval(get_post_meta($p->ID, Meeting::MEETING_META_KEY, true));
			if (isset($r[$mid])) {
				if ($verbose) {
					echo "<p>Meeting $mid has more than one post.  Post {$r[$mid]->ID} is used, and post $p->ID will be removed.</p>";
				}
				continue;
			}
			$r[$mid] = $p;
		}

		return $r;
	}

	/**
	 * Match the planned groups to existing structural posts, by the meetings they contain.
	 *
	 * Candidates are posts whose stored member meetings (tp_groupMtgId) include any planned meeting, and groups that are
	 * currently ancestors of the planned meetings' posts (for the previous behavior's collections, which have no stored
	 * members yet).  Each candidate is assigned to the planned group it overlaps most (by Jaccard similarity, then by
	 * matching role), and each planned group takes its best-assigned candidate.  So a small collection isn't adopted as
	 * an Edition when it matches one of that Edition's Clusters better.
	 *
	 * @param object         $ctx
	 * @param MeetingArray[] $groups
	 *
	 * @return WP_Post[] Keyed by spl_object_id of the planned group.
	 */
	private static function matchGroupPosts(object $ctx, array $groups): array
	{
		if (count($groups) === 0) {
			return [];
		}

		// Planned members of each group.
		$planned = [];
		$allIds  = [];
		foreach ($groups as $g) {
			$ids = array_map(fn($m) => intval($m->mtgId), $g->leafMeetings());
			$planned[spl_object_id($g)] = $ids;
			$allIds = [...$allIds, ...$ids];
		}
		$allIds = array_values(array_unique($allIds));

		// Candidates with stored members.
		$candidateMembers = []; // post ID => int[]
		$q = new WP_Query([
			'post_type'      => $ctx->postType,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => [
				[
					'key'     => Meeting::MEETING_GROUP_MEMBERS_META_KEY,
					'value'   => array_map('strval', $allIds),
					'compare' => 'IN',
				],
			],
		]);
		foreach ($q->get_posts() as $pid) {
			$candidateMembers[$pid] = array_map('intval', get_post_meta($pid, Meeting::MEETING_GROUP_MEMBERS_META_KEY));
		}

		// Candidates that are current ancestors of the meetings' posts, and have no stored members.
		foreach ($ctx->meetingPosts as $mid => $p) {
			$parentId = $p->post_parent;
			for ($depth = 0; $depth < 3 && $parentId > 0 && $parentId !== $ctx->ownerPost->ID; $depth++) {
				$parent = get_post($parentId);
				if ($parent === null || $parent->post_type !== $ctx->postType) {
					break;
				}
				if (intval(get_post_meta($parentId, Meeting::MEETING_META_KEY, true)) < 0 &&
					count(get_post_meta($parentId, Meeting::MEETING_GROUP_MEMBERS_META_KEY)) === 0) {
					$candidateMembers[$parentId][] = intval($mid);
				}
				$parentId = $parent->post_parent;
			}
		}

		// Assign each candidate to the planned group it matches best.
		$byGroup = []; // spl_object_id => [[score, roleMatch, postId], ...]
		foreach ($candidateMembers as $pid => $members) {
			$members = array_unique($members);
			$role    = get_post_meta($pid, Meeting::MEETING_GROUP_ROLE_META_KEY, true);
			$best    = null;
			foreach ($groups as $g) {
				$ids   = $planned[spl_object_id($g)];
				$inter = count(array_intersect($ids, $members));
				if ($inter === 0) {
					continue;
				}
				$score     = $inter / count(array_unique([...$ids, ...$members]));
				$roleMatch = ($role === $g->groupRole) ? 1 : 0;
				$key       = [$score, $roleMatch, $pid];
				if ($best === null || [$key[0], $key[1]] > [$best[1][0], $best[1][1]]) {
					$best = [spl_object_id($g), $key];
				}
			}
			if ($best !== null) {
				$byGroup[$best[0]][] = $best[1];
			}
		}

		$r = [];
		foreach ($byGroup as $gid => $options) {
			// Highest score, then matching role, then the oldest post.
			usort($options, fn($a, $b) => [$b[0], $b[1], $a[2]] <=> [$a[0], $a[1], $b[2]]);
			$post = get_post($options[0][2]);
			if ($post !== null) {
				$r[$gid] = $post;
			}
		}

		return $r;
	}

	/**
	 * Write a list of planned items (siblings) under a parent post, and then their contents.
	 *
	 * @param WP_Post $parent
	 * @param array   $items
	 * @param bool    $insideGroup Whether these items are inside a group (so aren't top-level on the calendar).
	 * @param bool    $inCluster   Whether these items are inside a Cluster (so their content is left empty).
	 * @param object  $ctx
	 *
	 * @return void
	 */
	private static function writeGroupingItems(
		WP_Post $parent,
		array $items,
		bool $insideGroup,
		bool $inCluster,
		object $ctx
	): void {
		// First, find each item's post, and skip archived items that have nothing to show.
		$entries = [];
		foreach ($items as $item) {
			$isGroup  = $item instanceof MeetingArray;
			$archived = self::endTimestamp($item) < $ctx->cutoff;
			$post     = $isGroup ? ($ctx->groupPosts[spl_object_id($item)] ?? null) : ($ctx->meetingPosts[$item->mtgId] ?? null);

			if ($post === null && ! self::shouldCreate($item, $archived, $ctx)) {
				if ($ctx->verbose) {
					$what = $isGroup ? "group of meetings starting " . $item->mtgStartDt->format('Y-m-d') : "Meeting $item->mtgId";
					echo "<p>No post found for $what.  As it is archived, it will not be created.</p>";
				}
				continue;
			}

			$entries[] = (object)[
				'item'     => $item,
				'isGroup'  => $isGroup,
				'archived' => $archived,
				'post'     => $post,
				'slug'     => null,
			];
		}

		self::assignSlugs($entries, self::siblingSlugsNotInPlan($parent, $items, $ctx), $parent, $insideGroup);

		foreach ($entries as $e) {
			$item     = $e->item;
			$isGroup  = $e->isGroup;
			$archived = $e->archived;
			$slug     = $e->slug;
			$isNew    = $e->post === null;

			if ($isNew) {
				$post = self::createGroupingPost($item, $parent, $ctx);
				if ($post === null) {
					continue;
				}
			} else {
				$post = $e->post;
				// Record the old path before moving or renaming.
				if ($post->post_parent !== $parent->ID || $post->post_name !== $slug) {
					self::recordOldPath($post, $parent, $slug, $ctx);
				}
			}

			// A child involvement's post that was also its meeting's post is now just the meeting's post.
			if ( ! $isGroup && isset($ctx->combined[$item->mtgId])) {
				if ($ctx->verbose) {
					echo "<p>Post $post->ID was the post of involvement $item->involvementId, and is now only the post of Meeting $item->mtgId.</p>";
				}
				if ($ctx->apply) {
					delete_post_meta($post->ID, TouchPointWP::INVOLVEMENT_META_KEY);
				}
			}

			$post->post_parent = $parent->ID;
			$inv = $isGroup ? self::involvementForGroup($item, $ctx) : ($ctx->involvements[$item->involvementId] ?? $ctx->owner);

			if ($isNew || ! $archived) {
				$post->post_title = self::titleForItem($item, $ctx);
				// A new archived post doesn't get today's description, which may not be what it said at the time.
				$post->post_content = $archived ? "" : self::contentForItem($item, $inv, $inCluster);
			}

			// Meta.  isGroupMember is read with isset(), so it's only set when true.
			if ($insideGroup) {
				$item->isGroupMember = true;
			} elseif ($isGroup) {
				$item->isGroupMember = null;
			} else {
				unset($item->isGroupMember);
			}
			self::doMeetingMetaUpdates($post, $item, ! ! ($inv->showInSites ?? false), $ctx->verbose, $ctx->apply);
			Translation::setPostLanguageFromCampus($inv->campusName ?? null, $post, $ctx->postType, $ctx->verbose, $ctx->apply);

			if ($ctx->apply) {
				$post->post_name = $slug;
				wp_update_post($post);

				if ($isGroup) {
					update_post_meta($post->ID, Meeting::MEETING_GROUP_ROLE_META_KEY, $item->groupRole);
					self::setGroupMembers($post->ID, array_map(fn($m) => intval($m->mtgId), $item->leafMeetings()));
				}

				if ($isNew || ! $archived) {
					if ($ctx->imagePostId > 0) {
						set_post_thumbnail($post->ID, $ctx->imagePostId);
					} else {
						delete_post_thumbnail($post->ID);
					}
				}

				// WordPress may have adjusted the slug to make it unique, or chosen one from the title for a new post.
				if (get_post_field('post_name', $post->ID) !== $slug) {
					Utilities::forceSlugUpdate($post->ID, $slug);
				}
			}

			$ctx->keep[] = $post->ID;

			if ($isGroup) {
				self::writeGroupingItems(
					$post,
					iterator_to_array($item),
					true,
					$item->groupRole === MeetingArray::ROLE_CLUSTER,
					$ctx
				);
			}
		}
	}

	/**
	 * Whether a planned item without a post should get one.  Meetings are created unless they're archived.  Groups are
	 * created unless they're archived and none of their meetings has (or will get) a post, since an empty archived
	 * group would have nothing to show.
	 *
	 * @param object $item
	 * @param bool   $archived
	 * @param object $ctx
	 *
	 * @return bool
	 */
	private static function shouldCreate(object $item, bool $archived, object $ctx): bool
	{
		if ( ! $archived) {
			return true;
		}
		if ( ! ($item instanceof MeetingArray)) {
			return false;
		}
		foreach ($item->leafMeetings() as $m) {
			if (isset($ctx->meetingPosts[$m->mtgId]) || self::endTimestamp($m) >= $ctx->cutoff) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Create a post for a planned item.
	 *
	 * @param object  $item
	 * @param WP_Post $parent
	 * @param object  $ctx
	 *
	 * @return ?WP_Post
	 */
	private static function createGroupingPost(object $item, WP_Post $parent, object $ctx): ?WP_Post
	{
		$title = self::titleForItem($item, $ctx);

		if ( ! $ctx->apply) {
			if ($ctx->verbose) {
				echo "<p>Would create a new post for \"$title\".</p>";
			}
			return new WP_Post((object)['post_parent' => $parent->ID, 'post_name' => '']);
		}

		if ($ctx->verbose) {
			echo "<p>Creating a new post for \"$title\".</p>";
		}

		$id = wp_insert_post([
			'post_type'   => $ctx->postType,
			'post_title'  => $title,
			'post_parent' => $parent->ID,
			'post_status' => 'publish',
			'meta_input'  => [
				Meeting::MEETING_META_KEY => $item->mtgId
			]
		]);

		if ($id === 0) {
			new TouchPointWP_Exception("A post could not be created for a meeting or group.", 171004);
			return null;
		}

		return get_post($id);
	}

	/**
	 * The slugs of posts under the parent that aren't part of this plan, which planned items must not reuse.
	 *
	 * @param WP_Post $parent
	 * @param array   $items
	 * @param object  $ctx
	 *
	 * @return string[]
	 */
	private static function siblingSlugsNotInPlan(WP_Post $parent, array $items, object $ctx): array
	{
		if ( ! $parent->ID) {
			return [];
		}

		$planned = [];
		foreach ($items as $item) {
			$p = $item instanceof MeetingArray ? ($ctx->groupPosts[spl_object_id($item)] ?? null) : ($ctx->meetingPosts[$item->mtgId] ?? null);
			if ($p !== null) {
				$planned[] = $p->ID;
			}
		}

		$slugs = [];
		foreach (get_children(['post_parent' => $parent->ID, 'post_type' => $ctx->postType, 'post_status' => 'any']) as $child) {
			if ( ! in_array($child->ID, $planned, true)) {
				$slugs[] = $child->post_name;
			}
		}

		return $slugs;
	}

	/**
	 * Choose slugs for a set of siblings.
	 *
	 * An existing post that stays under the same parent keeps its slug, unless it collides.  Everything else (new
	 * posts, and posts moving to a new parent) gets a slug chosen across all its siblings at once, the same way the
	 * previous behavior's computeSlugs() does: the first candidate that is unique among the siblings.  So, for example,
	 * two meetings in the same month both get a full date, rather than one getting the month and the other the date.
	 *
	 * Candidates, in order:
	 * - Editions: the date of their first meeting, from month to second.
	 * - Time Slots: the date and time of their start.
	 * - Clusters, and meetings inside a group: their title, and then dates.
	 * - Other meetings: dates.
	 *
	 * @param object[] $entries  The siblings.  Each gets its slug property set.
	 * @param string[] $reserved Slugs of other posts under the parent, which can't be used.
	 * @param WP_Post  $parent
	 * @param bool     $insideGroup
	 *
	 * @return void
	 */
	private static function assignSlugs(array $entries, array $reserved, WP_Post $parent, bool $insideGroup): void
	{
		// Posts that stay put keep their slug.
		$seen = [];
		foreach ($entries as $e) {
			$p = $e->post;
			if ($p === null || $p->post_parent !== $parent->ID) {
				continue;
			}
			$slug = $p->post_name;
			if ($slug === "" || str_contains($slug, "__trashed") || in_array($slug, $reserved, true) || isset($seen[$slug])) {
				continue;
			}
			$e->slug     = $slug;
			$seen[$slug] = true;
		}
		$reserved = [...$reserved, ...array_keys($seen)];

		// Candidates for everything else.
		$candidates = [];
		$counts     = [];
		foreach ($entries as $i => $e) {
			if ($e->slug !== null) {
				continue;
			}
			$c = self::slugCandidates($e->item, $e->isGroup, $insideGroup);
			$candidates[$i] = $c;
			foreach (array_unique($c) as $s) {
				$counts[$s] = ($counts[$s] ?? 0) + 1;
			}
		}

		foreach ($candidates as $i => $c) {
			$chosen = null;
			foreach ($c as $s) {
				if ($s !== "" && $counts[$s] === 1 && ! in_array($s, $reserved, true)) {
					$chosen = $s;
					break;
				}
			}
			if ($chosen === null) {
				// Collision-safe fallback: the meeting ID, as the previous behavior uses.
				$base   = (string)abs(intval($entries[$i]->item->mtgId));
				$chosen = $base;
				$n      = 2;
				while (in_array($chosen, $reserved, true)) {
					$chosen = $base . "-" . $n++;
				}
			}
			$entries[$i]->slug = $chosen;
			$reserved[]        = $chosen;
		}
	}

	/**
	 * The slug candidates for one planned item, in order of preference.
	 *
	 * @param object $item
	 * @param bool   $isGroup
	 * @param bool   $insideGroup
	 *
	 * @return string[]
	 */
	private static function slugCandidates(object $item, bool $isGroup, bool $insideGroup): array
	{
		$c = [];

		if ($isGroup && $item->groupRole === MeetingArray::ROLE_EDITION) {
			$formats = self::$editionSlugFormats;
		} elseif ($isGroup && $item->groupRole === MeetingArray::ROLE_TIME_SLOT) {
			$formats = self::$timeSlotSlugFormats;
		} else {
			$formats = self::$meetingSlugFormats;
			if (($isGroup || $insideGroup) && ($item->titleToUse ?? "") !== "") {
				$c[] = Utilities::stringToSlug($item->titleToUse);
			}
		}

		foreach ($formats as $f) {
			$c[] = $item->mtgStartDt->format($f);
		}

		return $c;
	}

	/**
	 * Remember a post's current path, before it's moved or renamed, so the old URL can be redirected.
	 *
	 * @param WP_Post $post
	 * @param WP_Post $newParent
	 * @param string  $newSlug
	 * @param object  $ctx
	 *
	 * @return void
	 */
	private static function recordOldPath(WP_Post $post, WP_Post $newParent, string $newSlug, object $ctx): void
	{
		$old = get_page_uri($post);
		if ( ! $old) {
			return;
		}

		if ($ctx->verbose) {
			$newParentPath = $newParent->ID ? get_page_uri($newParent) : "";
			echo "<p>Moving post $post->ID from <code>$old</code> to <code>$newParentPath/$newSlug</code>.</p>";
		}

		if ($ctx->apply && ! in_array($old, get_post_meta($post->ID, Meeting::MEETING_OLD_PATH_META_KEY), true)) {
			add_post_meta($post->ID, Meeting::MEETING_OLD_PATH_META_KEY, $old);
		}
	}

	/**
	 * Replace a group post's stored member meetings, if they've changed.
	 *
	 * @param int   $postId
	 * @param int[] $mtgIds
	 *
	 * @return void
	 */
	private static function setGroupMembers(int $postId, array $mtgIds): void
	{
		$current = array_map('intval', get_post_meta($postId, Meeting::MEETING_GROUP_MEMBERS_META_KEY));
		sort($current);
		$new = array_values(array_unique($mtgIds));
		sort($new);

		if ($current === $new) {
			return;
		}

		// Not update_post_meta(), which would set every row to the same value.
		delete_post_meta($postId, Meeting::MEETING_GROUP_MEMBERS_META_KEY);
		foreach ($new as $mid) {
			add_post_meta($postId, Meeting::MEETING_GROUP_MEMBERS_META_KEY, $mid);
		}
	}

	/**
	 * The involvement a group represents: its own for a Cluster, otherwise the structure owner.
	 *
	 * @param MeetingArray $group
	 * @param object       $ctx
	 *
	 * @return object
	 */
	private static function involvementForGroup(MeetingArray $group, object $ctx): object
	{
		if ($group->groupRole === MeetingArray::ROLE_CLUSTER) {
			return $ctx->involvements[$group->involvementId] ?? $ctx->owner;
		}
		return $ctx->owner;
	}

	/**
	 * The title for a planned item.  Time Slots are titled with their date and time when displayed; the stored title
	 * is a fallback in the site's formats.
	 *
	 * @param object $item
	 * @param object $ctx
	 *
	 * @return string
	 */
	private static function titleForItem(object $item, object $ctx): string
	{
		if ($item instanceof MeetingArray && $item->groupRole === MeetingArray::ROLE_TIME_SLOT) {
			return wp_date(get_option('date_format') . ' ' . get_option('time_format'), $item->mtgStartDt->getTimestamp());
		}
		return $item->titleToUse ?? $ctx->owner->titleToUse ?? "";
	}

	/**
	 * The content for a planned item.  Meetings inside a Cluster, and Time Slots, have no content of their own.
	 * Everything else gets the description of the involvement it represents.
	 *
	 * @param object $item
	 * @param object $inv
	 * @param bool   $inCluster
	 *
	 * @return string
	 */
	private static function contentForItem(object $item, object $inv, bool $inCluster): string
	{
		if ($inCluster || ($item instanceof MeetingArray && $item->groupRole === MeetingArray::ROLE_TIME_SLOT)) {
			return "";
		}
		if (($inv->description ?? null) === null || trim($inv->description) === "") {
			return "";
		}
		return Utilities::standardizeHtml($inv->description, "meeting-import");
	}

	/**
	 * The end of a meeting or group, as a timestamp.  The start is used if there is no end.
	 *
	 * @param object $item
	 *
	 * @return int
	 */
	private static function endTimestamp(object $item): int
	{
		return ($item->mtgEndDt ?? $item->mtgStartDt)->getTimestamp();
	}
}
