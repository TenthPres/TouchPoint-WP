<?php

namespace tp\TouchPointWP;

use DateTime;
use Exception;
use WP_Error;
use WP_Post;
use WP_Query;

require_once 'Involvement.php';

/**
 * SmallGroup class file.
 *
 * Class SmallGroup
 * @package tp\TouchPointWP
 */


/**
 * The Small Group system class.
 */
class SmallGroup extends Involvement
{
    public const SHORTCODE_MAP = TouchPointWP::SHORTCODE_PREFIX . "SgMap";
    public const POST_TYPE = TouchPointWP::HOOK_PREFIX . "smallgroup";
    public const CRON_HOOK = TouchPointWP::HOOK_PREFIX . "sg_cron_hook";

    private static array $_instances = [];

    private static bool $_isInitiated = false;
    protected static TouchPointWP $tpwp;

    public object $geo;

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
            // Runs at 6am EST (11am UTC), hypothetically after TouchPoint runs its Morning Batches.
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
            'menu_icon' => "dashicons-groups", // TODO remove eventually.
            'query_var' => self::$tpwp->settings->sg_slug,
            'can_export' => false,
            'delete_with_user' => false
        ]);

        // If the slug has changed, update it.  Only executes if enqueued.
        self::$tpwp->flushRewriteRules();

//        self::registerScriptsAndStyles();

        // Register default templates for Small Groups
        add_filter( 'template_include', [self::class, 'templateFilter'] );

        // Run cron if it hasn't been run before or is overdue.
        if (self::$tpwp->settings->sg_cron_last_run * 1 < time() - 86400 - 3600) {
            self::updateSmallGroupsFromTouchPoint();
        }
    }

    /**
     * @param string $template
     *
     * @return string
     */
    public static function templateFilter(string $template): string
    {
        $postTypesToFilter = [self::POST_TYPE];
        $templateFilesToOverwrite = ['archive.php', 'singular.php', 'index.php'];

        // echo "<!-- Template being applied: " . $template . " -->";

        if (!in_array(ltrim(strrchr($template, '/'), '/'), $templateFilesToOverwrite)) {
            return $template;
        }

        if ( is_post_type_archive($postTypesToFilter) && file_exists(TouchPointWP::$dir . '/src/templates/smallgroup-archive.php') ){
            $template = TouchPointWP::$dir . '/src/templates/smallgroup-archive.php';
            wp_enqueue_style(TouchPointWP::SHORTCODE_PREFIX . 'smallgroups-template-style');
        }

        if ( is_singular( $postTypesToFilter ) && file_exists(TouchPointWP::$dir . '/src/templates/smallgroup-single.php' ) ){
            $template = TouchPointWP::$dir . '/src/templates/smallgroup-singular.php';
        }

        return $template;
    }

    public static function registerScriptsAndStyles(): void
    {
        wp_register_script(TouchPointWP::SHORTCODE_PREFIX . "googleMaps",
                           sprintf(
                               "https://maps.googleapis.com/maps/api/js?key=%s&v=3&libraries=geometry",
                               self::$tpwp->settings->google_maps_api_key
                           ),
                           [],null,true);

        wp_register_script(TouchPointWP::SHORTCODE_PREFIX . "smallgroups-defer",
                           self::$tpwp->assets_url . 'js/smallgroup-defer.js',
                           [TouchPointWP::SHORTCODE_PREFIX . "base-defer"],
                           self::$tpwp::VERSION,
                           true);

        wp_register_style(TouchPointWP::SHORTCODE_PREFIX . 'smallgroups-template-style', // TODO determine whether this should be pre-registered, or just called by the template.
                          self::$tpwp->assets_url . 'template/smallgroups-template-style.css',
                          [],
                          self::$tpwp::VERSION,
                          'all'
        );
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

        $script = file_get_contents(TouchPointWP::$dir . "/src/js-partials/smallgroup-inline.js");

        $script = str_replace('{$smallgroupsList}', json_encode(self::getSmallGroupsForMap()), $script);
        $script = str_replace('{$mapDivId}', $mapDivId, $script);

        wp_add_inline_script(TouchPointWP::SHORTCODE_PREFIX . "googleMaps", $script); // todo move somewhere more appropriate

        // TODO move the style to a css file... or something.
        $content = "<div class=\"TouchPoint-SmallGroup-Map\" style=\"height: 100%; width: 100%; position: absolute; top: 0; left: 0; \" id=\"{$mapDivId}\"></div>";

        return $content;
    }


    /**
     * Query TouchPoint and update Small Groups in WordPress
     *
     * @return false|int False on failure, or the number of groups that were updated or deleted.
     */
    public static function updateSmallGroupsFromTouchPoint() // TODO add OOP alignment
    {
        $divs     = implode(',', self::$tpwp->settings->sg_divisions);
        $divs     = str_replace('div', '', $divs);

        $lMTypes  = implode(',', self::$tpwp->settings->sg_leader_types);
        $lMTypes     = str_replace('mt', '', $lMTypes);

        $hMTypes  = implode(',', self::$tpwp->settings->sg_host_types);
        $hMTypes     = str_replace('mt', '', $hMTypes);

        set_time_limit(60);

        $response = self::$tpwp->apiGet("InvsForDivs",
                                        ['divs' => $divs, 'leadMemTypes' => $lMTypes, 'hostMemTypes' => $hMTypes]);

        $siteTz = wp_timezone();

        if ($response instanceof WP_Error) {
            return false;
        }

        $invData = json_decode($response['body'])->data->invs ?? []; // null coalesce for case where there is no data.

        $postsToKeep = [];

        foreach ($invData as $inv) {
            set_time_limit(10);

            $q    = new WP_Query(
                [
                    'post_type'  => self::POST_TYPE,
                    'meta_key'   => TouchPointWP::SETTINGS_PREFIX . "invId",
                    'meta_value' => $inv->involvementId
                ]
            );
            $post = $q->get_posts();
            if (count($post) > 0) { // post exists already.
                $post = $post[0];
            } else {
                $post = wp_insert_post(
                    [ // create new
                        'post_type'  => self::POST_TYPE,
                        'post_name'  => $inv->name,
                        'meta_input' => [
                            TouchPointWP::SETTINGS_PREFIX . "invId" => $inv->involvementId
                        ]
                    ]
                );
                $post = get_post($post);
            }

            // TODO check for $post being an instanceof WP_Error and report that something went wrong.

            /** @var $post WP_Post */

            $post->post_content = strip_tags($inv->description, "<p><br><a><em><b><i><u><hr>");

            if ($post->post_title != $inv->name) // only update if there's a change.  Otherwise, urls increment.
            {
                $post->post_title = $inv->name;
            }

            $post->post_status = 'publish';

            wp_update_post($post);

            update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "locationName", $inv->location);
            update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "memberCount", $inv->memberCount);
            update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "genderId", $inv->genderId);
            update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "groupFull", ! ! $inv->groupFull);
            update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "groupClosed", ! ! $inv->closed);

            // Determine next meeting date/time
            $nextMeetingDateTime = array_diff([$inv->sched1Time, $inv->sched2Time, $inv->meetNextMeeting], [null]);
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

            // Determine schedule string  TODO add frequency options.
            // Day(s) of week
            $days = array_diff([$inv->sched1Day, $inv->sched2Day], [null]);
            if (count($days) > 1) {
                $days = TouchPointWP::getDayOfWeekShortForNumber($days[0]) .
                        " & " . TouchPointWP::getDayOfWeekShortForNumber($days[1]);
            } elseif (count($days) === 1) {
                $days = TouchPointWP::getPluralDayOfWeekNameForNumber(reset($days));
            } elseif ($inv->meetNextMeeting !== null) {
                $days = TouchPointWP::getPluralDayOfWeekNameForNumber(date("w", strtotime($inv->meetNextMeeting)));
            } else {
                $days = null;
            }
            // Times of day  TODO (eventually) allow for different times of day on different days of the week.
            if ($days !== null) {
                $times = array_diff([$inv->sched1Time, $inv->sched2Time, $inv->meetNextMeeting], [null]);

                if (count($times) > 0) {
                    $times = date(get_option('time_format'), strtotime(reset($times)));
                    $days  .= " " . __('at', TouchPointWP::TEXT_DOMAIN) . " " . $times;
                }
            }
            update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "meetingSchedule", $days);


            // Handle leaders  TODO make leaders WP Users
            if (property_exists($inv, "leaders")) {
                $nameString = Person::arrangeNamesForPeople($inv->leaders);
                update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "leaders", $nameString);
            }


            // Handle locations TODO handle cases other than hosted at home
            if (property_exists($inv, "hostGeo") && $inv->hostGeo !== null) {
                update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "geo_lat", $inv->hostGeo->lat);
                update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "geo_lng", $inv->hostGeo->lng);
            }

            $postsToKeep[] = $post->ID;
        }

        /* Delete posts that are no longer current  */  // TODO figure out why this isn't working correctly.
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

        return count($invData) + $removals;
    }

    /**
     * Gets a list of small groups, used to load the metadata into JS for the map and filtering capabilities.
     *
     * @return SmallGroup[]
     */
    protected static function getSmallGroupsForMap(): array
    {
        global $wpdb;

        $settingsPrefix = TouchPointWP::SETTINGS_PREFIX;
        $postType = self::POST_TYPE;

        $sql = "SELECT 
                    p.post_title as name,
                    p.ID as post_id,
                    p.post_excerpt,
                    mloc.meta_value as location,
                    miid.meta_value as invId,
                    mlat.meta_value as geo_lat,
                    mlng.meta_value as geo_lng
                FROM $wpdb->posts AS p 
            JOIN $wpdb->postmeta as miid ON p.ID = miid.post_id AND '{$settingsPrefix}invId' = miid.meta_key
            JOIN $wpdb->postmeta as mloc ON p.ID = mloc.post_id AND '{$settingsPrefix}locationName' = mloc.meta_key
            LEFT JOIN $wpdb->postmeta as mlat ON p.ID = mlat.post_id AND '{$settingsPrefix}geo_lat' = mlat.meta_key
            LEFT JOIN $wpdb->postmeta as mlng ON p.ID = mlng.post_id AND '{$settingsPrefix}geo_lng' = mlng.meta_key
            WHERE p.post_type = '{$postType}' AND p.post_status = 'publish' AND p.post_date_gmt < utc_timestamp()";
        // TODO add a condition that requires the presence of lat/long.

        $ret = [];
        foreach ($wpdb->get_results($sql, "OBJECT") as $row) {
            $ret[] = self::fromObj($row);
        }
        return $ret;
    }

    private static function fromObj(object $obj): SmallGroup
    {

        $iid = intval($obj->invId);

        if (!isset(self::$_instances[$iid])) {
            self::$_instances[$iid] = new SmallGroup($obj);
        }
        return self::$_instances[$iid];
    }


    public static function fromPost(WP_Post $post): SmallGroup
    {
        $iid = intval($post->{self::INVOLVEMENT_META_KEY});

        if (!isset(self::$_instances[$iid])) {
            self::$_instances[$iid] = new SmallGroup($post);
        }
        return self::$_instances[$iid];
    }


    public static function fromInvId($iid): SmallGroup
    {
        if (!isset(self::$_instances[$iid])) {
            self::$_instances[$iid] = new SmallGroup($iid);
        }
        return self::$_instances[$iid];
    }


    protected function __construct($invIdOrObj) {
        parent::__construct($invIdOrObj);

        if (gettype($invIdOrObj) == "object" && $invIdOrObj->geo_lat !== null) {
            $this->geo = (object)[
                'lat'     => (float)$invIdOrObj->geo_lat,
                'lng'     => (float)$invIdOrObj->geo_lng,
                'resCode' => "metro", // TODO import resident code
            ];
        } else {
            $this->geo = (object)[
                'lat'     => (float)get_post_meta($invIdOrObj->post_id, TouchPointWP::SETTINGS_PREFIX . "geo_lat", true),
                'lng'     => (float)get_post_meta($invIdOrObj->post_id, TouchPointWP::SETTINGS_PREFIX . "geo_lng", true),
                'resCode' => "metro", // TODO import resident code
            ];
        }
    }


    public function getActionButtons(): string
    {
        return '
        <button type="button">Contact Leaders</button>
        <button type="button">Join</button>';
    }


}