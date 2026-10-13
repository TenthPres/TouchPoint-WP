<?php
/**
 * Builders for meeting and involvement data shaped like the data the TouchPoint API provides.
 *
 * @package TouchPointWP\Tests
 */

namespace tp\TouchPointWP\Tests\Support;

use DateTimeZone;
use stdClass;
use tp\TouchPointWP\MeetingArray;
use tp\TouchPointWP\Utilities\DateTimeExtended;

/**
 * Use this in a test class to create meetings and involvements without needing the API or WordPress.
 *
 * Times are in UTC, so results don't depend on the time zone of the machine running the tests.
 */
trait MeetingFixtures
{
    /**
     * A date and time, in UTC.  Meetings from the API are DateTimeExtended objects.
     *
     * @param string $dateTime Anything DateTime understands, such as "2026-03-14 19:00".
     *
     * @return DateTimeExtended
     */
    protected static function when(string $dateTime): DateTimeExtended
    {
        return new DateTimeExtended($dateTime, new DateTimeZone('UTC'));
    }

    /**
     * A meeting, as the API provides it after Involvement::standardizeApiData().
     *
     * @param int     $id            The meeting ID.
     * @param int     $involvementId The involvement the meeting belongs to.
     * @param string  $start         When it starts.
     * @param ?string $end           When it ends.  Null, as for meetings with no end.
     * @param ?string $name          The meeting's own name.  Null if it doesn't have one, which is usual.
     * @param int     $status        1 for scheduled, 0 for cancelled.
     *
     * @return stdClass
     */
    protected static function meeting(
        int $id,
        int $involvementId,
        string $start,
        ?string $end = null,
        ?string $name = null,
        int $status = 1
    ): stdClass {
        return (object)[
            'mtgId'         => $id,
            'involvementId' => $involvementId,
            'mtgStartDt'    => self::when($start),
            'mtgEndDt'      => $end === null ? null : self::when($end),
            'name'          => $name,
            'location'      => null,
            'status'        => $status,
        ];
    }

    /**
     * An involvement, as the API provides it.
     *
     * @param int         $id       The involvement ID.
     * @param string      $name     The involvement's name.
     * @param stdClass[]  $meetings Its meetings.
     * @param ?string     $regTitle Its registration title, which is used instead of the name when it has one.
     *
     * @return stdClass
     */
    protected static function involvement(int $id, string $name, array $meetings = [], ?string $regTitle = null): stdClass
    {
        return (object)[
            'involvementId' => $id,
            'name'          => $name,
            'regTitle'      => $regTitle,
            'location'      => null,
            'meetings'      => $meetings,
        ];
    }

    /**
     * Describe a plan, or a group, as nested arrays of meeting IDs, so a test can compare a whole structure at once.
     * A group becomes [role => [contents]].  For example, an Edition holding a meeting and a Cluster of two meetings:
     * [['edition' => [1, ['cluster' => [2, 3]]]]].
     *
     * @param iterable $items The items of a plan, or a MeetingArray.
     *
     * @return array
     */
    protected static function shape(iterable $items): array
    {
        $shape = [];
        foreach ($items as $item) {
            $shape[] = $item instanceof MeetingArray
                ? [($item->groupRole ?? 'group') => self::shape($item)]
                : $item->mtgId;
        }
        return $shape;
    }
}
