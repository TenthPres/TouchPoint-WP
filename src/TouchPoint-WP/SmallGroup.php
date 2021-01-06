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
    public const SHORTCODE_MAP = TouchPointWP::SHORTCODE_PREFIX . "SgMap";
    public const POST_TYPE = TouchPointWP::HOOK_PREFIX . "smallgroup";
    public const CRON_HOOK = TouchPointWP::HOOK_PREFIX . "sg_cron_hook";

    private static bool $_isInitiated = false;
    protected static TouchPointWP $tpwp;

    public static function load(TouchPointWP $tpwp): bool
    {
        if (self::$_isInitiated) {
            return true;
        }

        set_time_limit(300); // TODO remove.

        self::$tpwp = $tpwp;

        self::$_isInitiated = true;

        add_action('init', [self::class, 'init']);

        if ( ! shortcode_exists(self::SHORTCODE_MAP)) {
            add_shortcode(self::SHORTCODE_MAP, [self::class, "mapShortcode"]);
        }

        // Setup cron for updating Small Groups daily.
        add_action(self::CRON_HOOK, [self::class, 'updateSmallGroupsFromTouchPoint']);
        if ( ! wp_next_scheduled(self::CRON_HOOK)) {
            // Runs at 6am EST (11am UTC)
            wp_schedule_event(date('U', strtotime('tomorrow') + 3600 * 11), 'daily', self::CRON_HOOK);
        }

        // Deactivation



//        TODO on activation and deactivation, or change to the small group slug, flush rewrite rules.
        // TODO Deactivation: cancel Cron updating task.

        return true;
    }

    /**
     * Register the tp_smallgroup post type
     */
    public static function init(): void
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

        add_action("wp_enqueue_scripts", [self::class, "enqueueScripts"]);

        // Register default templates for Small Groups
        add_filter( 'template_include', [self::class, 'templateFilter'] );

        // Run cron if it hasn't been run before or is overdue.
        if (self::$tpwp->settings->sg_cron_last_run * 1 < time() - 86400 - 3600) {
            self::updateSmallGroupsFromTouchPoint();
        }
    }

    /**
     * @param $template
     *
     * @return string
     */
    public static function templateFilter($template): string
    {
        $postTypesToFilter = [self::POST_TYPE];
        $templateFilesToOverwrite = ['archive.php', 'singular.php', 'index.php'];

        // echo "<!-- Template being applied: " . $template . " -->";

        if (!in_array(ltrim(strrchr($template, '/'), '/'), $templateFilesToOverwrite)) {
            return $template;
        }

        if ( is_post_type_archive($postTypesToFilter) && file_exists(TouchPointWP::$dir . '/src/templates/SmallGroup-Archive.php') ){
            $template = TouchPointWP::$dir . '/src/templates/SmallGroup-Archive.php';
        }

        if ( is_singular( $postTypesToFilter ) && file_exists(TouchPointWP::$dir . '/src/templates/SmallGroup-Singular.php' ) ){
            $template = TouchPointWP::$dir . '.src/templates/SmallGroup-Singular.php';
        }

        return $template;
    }

    public static function enqueueScripts()
    {
        wp_register_script(TouchPointWP::SHORTCODE_PREFIX . "googleMaps",
                           "https://maps.googleapis.com/maps/api/js?key=" . self::$tpwp->settings->google_maps_api_key . "&v=3&libraries=geometry",
                           [],null,true);
    }

    /**
     * @param array  $params
     * @param string $content
     *
     * @return string
     */
    public static function mapShortcode(array $params, string $content = ""): string
    {
        // standardize parameters
        $params = array_change_key_case($params, CASE_LOWER);

        wp_enqueue_script(TouchPointWP::SHORTCODE_PREFIX . "googleMaps");

        // set some defaults
        $params  = shortcode_atts(
            [
                'class'     => 'TouchPoint-smallgroup map'
            ],
            $params,
            self::SHORTCODE_MAP
        );

        if (isset($params['id']))
            $mapDivId = $params['id'];
        else
            $mapDivId = wp_unique_id('tp-map-');

        $script = "
        var doMap = function() {
            var colors = {
                background: '#ffffff',
                primary: '#8800a1', // dark
                tertiary: '#b800d9', // mid
                secondary: '#d11cf1', // light
                text: '#222222'
            };
            var styles = [
                {
                    featureType: \"landscape\",
                    stylers: [
                        { color: colors.background }
                    ]
                }, {
                    featureType: \"road\",
                    elementType: \"labels.text.fill\",
                    stylers: [
                        { color: colors.text }
                    ]
                }, {
                    featureType: \"road\",
                    elementType: \"labels.text.stroke\",
                    stylers: [
                        { color: colors.background }
                    ]
                }, {
                    featureType: \"road\",
                    elementType: \"geometry.fill\",
                    stylers: [
                        { color: colors.primary }
                    ]
                }, {
                    featureType: \"road\",
                    elementType: \"geometry.stroke\",
                    stylers: [
                        { color: colors.secondary }
                    ]
                }, {
                    featureType: \"water\",
                    stylers: [
                        { color: colors.text }
                    ]
                }, {
                    featureType: \"poi\", //points of interest
                    stylers: [
                        { visibility: 'off' }
                    ]
                }, {
                    featureType: \"transit\", //points of interest
                    stylers: [
                        { visibility: 'off' }
                    ]
                }, {
                    featureType: \"administrative\", //points of interest
                    stylers: [
                        { visibility: 'off' }
                    ]
                }
            ];
        
            drexelLatLong = new google.maps.LatLng(39.954, -75.188);
            bounds = new google.maps.LatLngBounds(drexelLatLong);
        
            var mapOptions = {
                zoom: 14,
                mapTypeId: google.maps.MapTypeId.ROADMAP,
                styles: styles,
                center: drexelLatLong,
                disableDefaultUI: true
            },
            m = new google.maps.Map(document.getElementById('{$mapDivId}'), mapOptions);
        }
        doMap();";

        wp_add_inline_script(TouchPointWP::SHORTCODE_PREFIX . "googleMaps", $script);

        // TODO move the style to a css file... or something.
        $content = "<div class=\"TouchPoint-SmallGroup-Map\" style=\"height: 100%; width: 100%; position: absolute; top: 0px; left: 0px; \" id=\"{$mapDivId}\">";

        $content .= "</div>";

        return $content;
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

        self::$tpwp->settings->set('sg_cron_last_run', time());

        return count($orgData) + $removals;
    }


}