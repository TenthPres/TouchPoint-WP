<?php
/**
 * @package TouchPointWP
 */

namespace tp\TouchPointWP;

use DateTimeInterface;

if ( ! defined('ABSPATH')) {
	exit(1);
}

/**
 * Plans how the meetings of one structure are grouped into Editions and Clusters.
 *
 * A structure is an involvement (the structure owner) and, if its Meeting Grouping settings include child involvements,
 * its child and grandchild involvements.  The planner only arranges meetings; it doesn't read or write posts, and it
 * makes no WordPress calls.
 *
 * An Edition can have a spanning meeting: one of the owner's own meetings that covers the whole Edition, such as a
 * week-long conference meeting.  It isn't grouped into a Cluster, and the Edition takes its name.  See
 * MeetingArray::$spanningMeeting.
 *
 * The result is a list of top-level items, in chronological order.  Each item is either a meeting (as provided by the
 * API) or a MeetingArray whose groupRole is one of the MeetingArray::ROLE_ constants.  A MeetingArray may contain
 * meetings and other MeetingArrays.
 *
 * @since 0.0.98 Added
 */
class Meeting_GroupingPlanner
{
	protected object $owner;

	/** @var object[] The involvements in the structure, including the owner, keyed by involvement ID. */
	protected array $involvements;

	protected bool $editions;
	protected bool $clusters;
	protected int $editionGap;
	protected int $clusterGap;

	/**
	 * @param object   $owner        The structure owner, as provided by the API.
	 * @param object[] $involvements The involvements in the structure, as provided by the API, including the owner.
	 *                               Each must have a meetings array whose meetings have their involvementId set.
	 * @param bool     $editions     Whether to group meetings into Editions.
	 * @param bool     $clusters     Whether to group meetings of the same involvement into Clusters.
	 * @param int      $editionGap   The gap, in seconds, after which a new Edition starts.
	 * @param int      $clusterGap   The maximum gap, in seconds, between back-to-back meetings in a Cluster.
	 */
	public function __construct(
		object $owner,
		array $involvements,
		bool $editions,
		bool $clusters,
		int $editionGap,
		int $clusterGap
	) {
		$this->owner        = $owner;
		$this->involvements = [];
		foreach ($involvements as $inv) {
			$this->involvements[$inv->involvementId] = $inv;
		}
		$this->involvements[$owner->involvementId] = $owner;

		$this->editions   = $editions;
		$this->clusters   = $clusters;
		$this->editionGap = $editionGap;
		$this->clusterGap = $clusterGap;
	}

	/**
	 * Create a planner using the given Meeting Grouping settings and the gaps from the filters.
	 *
	 * @param object                   $owner        The structure owner, as provided by the API.
	 * @param object[]                 $involvements The involvements in the structure, including the owner.
	 * @param Meeting_GroupingSettings $rule         The settings that apply to the owner.
	 *
	 * @return Meeting_GroupingPlanner
	 */
	public static function fromSettings(
		object $owner,
		array $involvements,
		Meeting_GroupingSettings $rule
	): Meeting_GroupingPlanner {
		return new self(
			$owner,
			$rule->includeChildren ? $involvements : [$owner],
			$rule->editions,
			$rule->clusters,
			Meeting_GroupingSettings::editionGap(),
			Meeting_GroupingSettings::clusterGap()
		);
	}

	/**
	 * Plan the grouping.
	 *
	 * @return array The top-level items: meetings and MeetingArrays, in chronological order.
	 */
	public function plan(): array
	{
		$meetings = [];
		foreach ($this->involvements as $inv) {
			foreach ($inv->meetings ?? [] as $m) {
				$meetings[] = $m;
			}
		}
		if (count($meetings) === 0) {
			return [];
		}
		usort($meetings, [self::class, 'compare']);

		$groups = $this->editions ? $this->splitIntoEditions($meetings) : [$meetings];

		$top = [];
		foreach ($groups as $group) {
			// The spanning meeting is left out of Clusters, and added back as its own item in the Edition.
			$spanning = $this->editions ? $this->spanningMeeting($group) : null;
			if ($spanning === null) {
				$items = $this->groupWithin($group);
			} else {
				$items = $this->groupWithin(array_values(array_filter($group, fn($m) => $m !== $spanning)));
				$this->setMeetingTitle($spanning);
				$items[] = $spanning;
				usort($items, [self::class, 'compare']);
			}

			if ($this->editions && count($items) > 1) {
				$edition = new MeetingArray($items, $this->owner, MeetingArray::ROLE_EDITION);
				$edition->titleToUse = self::titleOf($this->owner);
				if ($spanning !== null) {
					$edition->spanningMeeting = $spanning;
					if (trim($spanning->name ?? "") !== "") {
						$edition->titleToUse = trim($spanning->name);
					}
				}
				$top[] = $edition;
			} else {
				// An Edition with only one item is just that item.  Without Editions, items are at the top level.
				$top = [...$top, ...$items];
			}
		}

		return $top;
	}

	/**
	 * Split chronologically sorted meetings into Editions, starting a new one wherever the time from the latest end so
	 * far to the next start is more than the Edition gap.
	 *
	 * @param object[] $meetings Sorted meetings.
	 *
	 * @return object[][]
	 */
	protected function splitIntoEditions(array $meetings): array
	{
		$groups = [];
		$current = [];
		$latestEnd = null;

		foreach ($meetings as $m) {
			if ($latestEnd !== null && self::startOf($m) - $latestEnd > $this->editionGap) {
				$groups[] = $current;
				$current = [];
				$latestEnd = null;
			}
			$current[] = $m;
			$latestEnd = max($latestEnd ?? PHP_INT_MIN, self::endOf($m));
		}
		$groups[] = $current;

		return $groups;
	}

	/**
	 * Find an Edition's spanning meeting: one of the owner's own meetings that starts no later than, and ends no
	 * earlier than, every other meeting in the Edition.  If more than one qualifies, the first in chronological order
	 * is used.
	 *
	 * @param object[] $meetings The Edition's meetings, sorted.
	 *
	 * @return ?object Null if there isn't one, or if the Edition has only one meeting.
	 */
	protected function spanningMeeting(array $meetings): ?object
	{
		if (count($meetings) < 2) {
			return null;
		}

		foreach ($meetings as $candidate) {
			if ($candidate->involvementId != $this->owner->involvementId ||
				self::endOf($candidate) <= self::startOf($candidate)) {
				continue;
			}

			$spans = true;
			foreach ($meetings as $m) {
				if ($m !== $candidate &&
					(self::startOf($m) < self::startOf($candidate) || self::endOf($m) > self::endOf($candidate))) {
					$spans = false;
					break;
				}
			}
			if ($spans) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * Group the meetings of one Edition (or of the whole structure, without Editions) into Clusters.
	 *
	 * @param object[] $meetings Sorted meetings.
	 *
	 * @return array Meetings and MeetingArrays, in chronological order.
	 */
	protected function groupWithin(array $meetings): array
	{
		$items = [];
		$grouped = []; // spl_object_id => true, for meetings that have been placed in a group.

		if ($this->clusters) {
			// Within an Edition, all the remaining meetings of each child involvement form one Cluster.
			if ($this->editions) {
				$byInv = [];
				foreach ($meetings as $m) {
					if (isset($grouped[spl_object_id($m)]) || $m->involvementId == $this->owner->involvementId) {
						continue;
					}
					$byInv[$m->involvementId][] = $m;
				}
				foreach ($byInv as $invId => $set) {
					if (count($set) < 2) {
						continue;
					}
					$items[] = $this->newCluster($set, $invId);
					foreach ($set as $m) {
						$grouped[spl_object_id($m)] = true;
					}
				}
			}

			// Back-to-back meetings of the same involvement, with no other meeting between them.
			$run = [];
			$runEnd = null;
			foreach ($meetings as $m) {
				$eligible = ! isset($grouped[spl_object_id($m)]);
				$continues = $eligible && count($run) > 0 &&
				             $run[0]->involvementId == $m->involvementId &&
				             self::startOf($m) - $runEnd <= $this->clusterGap;

				if ( ! $continues) {
					$this->closeRun($run, $items, $grouped);
					$run = [];
					$runEnd = null;
				}
				if ($eligible) {
					$run[] = $m;
					$runEnd = max($runEnd ?? PHP_INT_MIN, self::endOf($m));
				}
			}
			$this->closeRun($run, $items, $grouped);
		}

		// Everything not in a group stands alone.
		foreach ($meetings as $m) {
			if ( ! isset($grouped[spl_object_id($m)])) {
				$items[] = $m;
			}
		}

		usort($items, [self::class, 'compare']);

		foreach ($meetings as $m) {
			$this->setMeetingTitle($m);
		}

		return $items;
	}

	/**
	 * If a run of back-to-back meetings has more than one meeting, make it a Cluster.
	 *
	 * @param object[] $run
	 * @param array    $items   The items being collected, which the Cluster is added to.
	 * @param bool[]   $grouped The meetings that have been placed in a group.
	 *
	 * @return void
	 */
	protected function closeRun(array $run, array &$items, array &$grouped): void
	{
		if (count($run) < 2) {
			return;
		}
		$items[] = $this->newCluster($run, $run[0]->involvementId);
		foreach ($run as $m) {
			$grouped[spl_object_id($m)] = true;
		}
	}

	/**
	 * Create a Cluster of meetings from one involvement.
	 *
	 * @param object[] $meetings
	 * @param mixed    $involvementId
	 *
	 * @return MeetingArray
	 */
	protected function newCluster(array $meetings, mixed $involvementId): MeetingArray
	{
		$inv = $this->involvements[$involvementId] ?? $this->owner;
		$cluster = new MeetingArray($meetings, $inv, MeetingArray::ROLE_CLUSTER);
		$cluster->titleToUse = self::titleOf($inv);

		return $cluster;
	}

	/**
	 * Set the title of a meeting: its name from TouchPoint, or else the title of its own involvement.
	 *
	 * @param object $m
	 *
	 * @return void
	 */
	protected function setMeetingTitle(object $m): void
	{
		$inv = $this->involvements[$m->involvementId] ?? $this->owner;
		$m->titleToUse = $m->name ?? self::titleOf($inv);
	}

	/**
	 * The title to use for an involvement.
	 *
	 * @param object $inv
	 *
	 * @return string
	 */
	protected static function titleOf(object $inv): string
	{
		return $inv->titleToUse ?? trim($inv->regTitle ?? $inv->name ?? "");
	}

	/**
	 * The start of a meeting or group, as a timestamp.
	 *
	 * @param object $item
	 *
	 * @return int
	 */
	protected static function startOf(object $item): int
	{
		return $item->mtgStartDt->getTimestamp();
	}

	/**
	 * The end of a meeting or group, as a timestamp.  The start is used if there is no end.
	 *
	 * @param object $item
	 *
	 * @return int
	 */
	protected static function endOf(object $item): int
	{
		$end = $item->mtgEndDt;
		return $end instanceof DateTimeInterface ? $end->getTimestamp() : self::startOf($item);
	}

	/**
	 * Chronological comparison of meetings or groups: by start, then end, then involvement, then meeting ID.
	 *
	 * @param object $a
	 * @param object $b
	 *
	 * @return int
	 */
	protected static function compare(object $a, object $b): int
	{
		return [self::startOf($a), self::endOf($a), intval($a->involvementId), abs(intval($a->mtgId))] <=>
		       [self::startOf($b), self::endOf($b), intval($b->involvementId), abs(intval($b->mtgId))];
	}
}
