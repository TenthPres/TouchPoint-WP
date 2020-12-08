<?php

namespace tp\TouchPointWP;

use DateTime;
use Exception;
use WP_Error;
use WP_Post;
use WP_Query;

/**
 * SmallGroup class file.
 *
 * Class SmallGroup
 * @package tp\TouchPointWP
 */


/**
 * The Small Group system class.
 */
abstract class SmallGroup
{
    public const SHORTCODE = TouchPointWP::SHORTCODE_PREFIX . "SG";
    public const POST_TYPE = TouchPointWP::HOOK_PREFIX . "smallgroup";

    private static bool $_isInitiated = false;
    protected static TouchPointWP $tpwp;

    public static function load(TouchPointWP $tpwp)
    {
        if (self::$_isInitiated) {
            return true;
        }

        self::$tpwp = $tpwp;

        self::$_isInitiated = true;

        add_action('init', [self::class, 'registerPostType']);

//        TODO on activation and deactivation, or change to the small group slug flush rewrite rules.

        return true;
    }

    /**
     * Register the tp_smallgroup post type
     */
    public static function registerPostType()
    {
        register_post_type( self::POST_TYPE, [
            'labels' => [
                'name'          =>  self::$tpwp->settings->sg_name_plural,
                'singular_name' =>  self::$tpwp->settings->sg_name_singular
            ],
            'public'        =>  true,
            'hierarchical'  =>  false,
//            'show_ui'       =>  false,
            'show_ui'       =>  true,
            'show_in_rest'  =>  true,
//            'rest_controller_class' => TODO: this.
            'supports'      => [
                'title',
                'custom-fields'
            ],
            'has_archive' => true,
            'rewrite' => [
                'slug' => self::$tpwp->settings->sg_slug,
                'with_front' => false,
                'feeds' => false,
                'pages' => true
            ],
            'menu_icon' => "dashicons-groups",
            'query_var' => self::$tpwp->settings->sg_slug,
            'can_export' => false,
            'delete_with_user' => false
        ]);
        self::updateSmallGroupsFromTouchPoint(); // TODO remove
    }


    /**
     * Query TouchPoint and update Small Groups in WordPress
     *
     * @return false|int False on failure, or the number of groups that were updated or deleted.
     */
    public static function updateSmallGroupsFromTouchPoint()
    {
        $divs     = implode(',', self::$tpwp->settings->sg_divisions);
        $divs     = str_replace('div', '', $divs);
        $response = self::$tpwp->apiGet("OrgsForDivs", ['divs' => $divs]);

        $siteTz = wp_timezone();

        if ($response instanceof WP_Error) {
            return false;
        }

        $orgData = json_decode($response['body'])->data->data;

        $postsToKeep = [];

        foreach ($orgData as $org) {
            set_time_limit(10);

            $q    = new WP_Query(
                [
                    'post_type'  => self::POST_TYPE,
                    'meta_key'   => TouchPointWP::SETTINGS_PREFIX . "orgId",
                    'meta_value' => $org->organizationId
                ]
            );
            $post = $q->get_posts();
            if (count($post) > 0) { // post exists already.
                $post = $post[0];
            } else {
                $post = wp_insert_post(
                    [ // create new
                        'post_type'  => self::POST_TYPE,
                        'post_name'  => $org->name,
                        'meta_input' => [
                            TouchPointWP::SETTINGS_PREFIX . "orgId" => $org->organizationId
                        ]
                    ]
                );
                $post = get_post($post);
            }

            // TODO check for $post being an instanceof WP_Error and report that something went wrong.

            /** @var $post WP_Post */

            $post->post_content = strip_tags($org->description, "<p><br><a><em><b><i><u><hr>");

            if ($post->post_title != $org->name) // only update if there's a change.  Otherwise, urls increment.
            {
                $post->post_title = $org->name;
            }

            $post->post_status = 'publish';

            wp_update_post($post);

            update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "locationName", $org->location);
            update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "memberCount", $org->memberCount);
            update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "genderId", $org->genderId);
            update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "groupFull", ! ! $org->groupFull);
            update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "groupClosed", ! ! $org->closed);

            // Determine next meeting date/time
            $nextMeetingDateTime = array_diff([$org->sched1Time, $org->sched2Time, $org->meetNextMeeting], [null]);
            if (count($nextMeetingDateTime) > 0) {
                $nextMeetingDateTime = min($nextMeetingDateTime);
                try {
                    $nextMeetingDateTime = new DateTime($nextMeetingDateTime, $siteTz);
                } catch (Exception $e) {
                    $nextMeetingDateTime = null;
                }
            } else {
                $nextMeetingDateTime = null;
            }
            update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "nextMeeting", $nextMeetingDateTime);

            // Determine schedule string
            // Day(s) of week
            $days = array_diff([$org->sched1Day, $org->sched2Day], [null]);
            if (count($days) > 1) {
                $days = TouchPointWP::getDayOfWeekShortForNumber(
                        $days[0]
                    ) . " & " . TouchPointWP::getDayOfWeekShortForNumber($days[1]);
            } elseif (count($days) === 1) {
                $days = TouchPointWP::getDayOfWeekNameForNumber(reset($days));
            } elseif ($org->meetNextMeeting !== null) {
                $days = TouchPointWP::getDayOfWeekNameForNumber(date("w", strtotime($org->meetNextMeeting)));
            } else {
                $days = null;
            }
            // Times of day  TODO (eventually) allow for different times of day on different days of the week.
            if ($days !== null) {
                $times = array_diff([$org->sched1Time, $org->sched2Time, $org->meetNextMeeting], [null]);

                if (count($times) > 0) {
                    $times = date(get_option('time_format'), strtotime(reset($times)));
                    $days  .= " " . __('at', TouchPointWP::TEXT_DOMAIN) . " " . $times;
                }
            }
            update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "meetingSchedule", $days);

            $postsToKeep[] = $post->ID;
        }

        /* Delete posts that are no longer current  */
        $q = new WP_Query(
            [
                'post_type' => self::POST_TYPE
            ]
        );
        $removals = 0;
        foreach ($q->get_posts() as $post) {
            if (! in_array($post->ID, $postsToKeep)) {
                wp_delete_post($post->ID);
                $removals++;
            }
        }
        return count($orgData) + $removals;
    }


}