<?php

namespace tp\TouchPointWP;

use DateTime;
use Exception;
use WP_Error;
use WP_Post;
use WP_Query;
use WP_Term;

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
    public const SHORTCODE_FILTER = TouchPointWP::SHORTCODE_PREFIX . "SgFilters";
    public const SHORTCODE_NEARBY = TouchPointWP::SHORTCODE_PREFIX . "SgNearby";
    public const POST_TYPE = TouchPointWP::HOOK_PREFIX . "smallgroup";
    public const CRON_HOOK = TouchPointWP::HOOK_PREFIX . "sg_cron_hook";
    protected static TouchPointWP $tpwp;
    protected static bool $_hasUsedMap = false;
    private static array $_instances = [];
    private static bool $_isInitiated = false;

    // TODO look at moving geo to a Trait or something.
    public object $geo;

    /**
     * SmallGroup constructor.
     *
     * @param WP_Post|object $object
     */
    protected function __construct($object)
    {
        parent::__construct($object);

        $terms = wp_get_post_terms(
            $this->post_id,
            [
                TouchPointWP::TAX_RESCODE,
                TouchPointWP::TAX_AGEGROUP,
                TouchPointWP::TAX_WEEKDAY,
                TouchPointWP::TAX_INV_MARITAL
            ]
        );

        if (is_array($terms) && count($terms) > 0) {
            $hookLength = strlen(TouchPointWP::HOOK_PREFIX);
            foreach ($terms as $t) {
                /** @var WP_Term $t */
                $to = (object)[
                    'name' => $t->name,
                    'slug' => $t->slug
                ];
                $ta = $t->taxonomy;
                if (strpos($ta, TouchPointWP::HOOK_PREFIX) === 0) {
                    $ta = substr_replace($ta, "", 0, $hookLength);
                }
                if ( ! isset($this->attributes->$ta)) {
                    $this->attributes->$ta = $to;
                } elseif ( ! is_array($this->attributes->$ta)) {
                    $this->attributes->$ta = [$this->attributes->$ta, $to];
                } else {
                    $this->attributes->$ta[] = $to;
                }
            }
        }

        $this->attributes->genderId = get_post_meta($this->post_id, TouchPointWP::SETTINGS_PREFIX . "genderId", true);

        if (is_object($object) && $object->geo_lat !== null && $object->geo_lat !== '') {
            // Probably a database query result
            $this->geo = (object)[
                'lat' => self::toFloatOrNull($object->geo_lat),
                'lng' => self::toFloatOrNull($object->geo_lng)
            ];
        } elseif (gettype($object) === "object" && get_class($object) === WP_Post::class) {
            // Probably a post
            $this->geo = (object)[
                'lat' => self::toFloatOrNull(
                    get_post_meta($this->post_id, TouchPointWP::SETTINGS_PREFIX . "geo_lat", true)
                ),
                'lng' => self::toFloatOrNull(
                    get_post_meta($this->post_id, TouchPointWP::SETTINGS_PREFIX . "geo_lng", true)
                )
            ];
        }
    }

    /**
     * Get notable attributes, such as gender restrictions, as strings.
     *
     * @return string[]
     */
    public function notableAttributes(): array
    {
        $ret = [];
        if ($this->attributes->genderId != 0) {
            switch($this->attributes->genderId) {
                case 1:
                    $ret[] = __('Men Only', TouchPointWP::TEXT_DOMAIN);
                    break;
                case 2:
                    $ret[] = __('Women Only', TouchPointWP::TEXT_DOMAIN);
                    break;
            }
        }
        return $ret;
    }

    // TODO why is this here?
    public static function toFloatOrNull($numeric): ?float
    {
        if (is_numeric($numeric)) {
            return (float)$numeric;
        }

        return null;
    }

    public static function load(TouchPointWP $tpwp): bool
    {
        if (self::$_isInitiated) {
            return true;
        }

        self::$tpwp = $tpwp;

        self::$_isInitiated = true;

        add_action('init', [self::class, 'init']);

        if ( ! shortcode_exists(self::SHORTCODE_MAP)) {
            add_shortcode(self::SHORTCODE_MAP, [self::class, "mapShortcode"]);
        }

        if ( ! shortcode_exists(self::SHORTCODE_FILTER)) {
            add_shortcode(self::SHORTCODE_FILTER, [self::class, "filterShortcode"]);
        }

        if ( ! shortcode_exists(self::SHORTCODE_NEARBY)) {
            add_shortcode(self::SHORTCODE_NEARBY, [self::class, "nearbyShortcode"]);
        }

        // Setup cron for updating Small Groups daily.
        add_action(self::CRON_HOOK, [self::class, 'updateSmallGroupsFromTouchPoint']);
        if ( ! wp_next_scheduled(self::CRON_HOOK)) {
            // Runs at 6am EST (11am UTC), hypothetically after TouchPoint runs its Morning Batches.
            wp_schedule_event(date('U', strtotime('tomorrow') + 3600 * 11),
                              'daily',
                              self::CRON_HOOK);
        }

        return true;
    }

    /**
     * Register stuff
     */
    public static function init(): void
    {
        self::registerAjax();

        register_post_type(
            self::POST_TYPE,
            [
                'labels'           => [
                    'name'          => self::$tpwp->settings->sg_name_plural,
                    'singular_name' => self::$tpwp->settings->sg_name_singular
                ],
                'public'           => true,
                'hierarchical'     => false,
                'show_ui'          => false,
                'show_in_rest'     => true,
                'supports'         => [
                    'title',
                    'custom-fields'
                ],
                'has_archive'      => true,
                'rewrite'          => [
                    'slug'       => self::$tpwp->settings->sg_slug,
                    'with_front' => false,
                    'feeds'      => false,
                    'pages'      => true
                ],
                'query_var'        => self::$tpwp->settings->sg_slug,
                'can_export'       => false,
                'delete_with_user' => false
            ]
        );

        self::$tpwp->registerTaxonomies(); // TODO probably needs to be moved to parent, but order matters.

        // If the slug has changed, update it.  Only executes if enqueued.
        self::$tpwp->flushRewriteRules();

        // Register default templates for Small Groups
        add_filter('template_include', [self::class, 'templateFilter']);

        // Register function to return schedule instead of publishing date
        add_filter( 'get_the_date', [self::class, 'filterPublishDate'], 10, 3 );
        add_filter( 'get_the_time', [self::class, 'filterPublishDate'], 10, 3 );

        // Run cron if it hasn't been run before or is overdue.
        if (self::$tpwp->settings->sg_cron_last_run * 1 < time() - 86400 - 3600) {
            self::updateSmallGroupsFromTouchPoint();
        }
    }

    /**
     *  Register AJAX endpoints specific to Small Groups
     */
    public static function registerAjax(): void
    {
        add_action('wp_ajax_tp_sg_nearby', [self::class, 'ajaxNearby']);
        add_action('wp_ajax_nopriv_tp_sg_nearby', [self::class, 'ajaxNearby']);
    }

    /**
     * Query TouchPoint and update Small Groups in WordPress
     *
     * @return false|int False on failure, or the number of groups that were updated or deleted.
     */
    public static function updateSmallGroupsFromTouchPoint() // TODO add OOP alignment  ... What's that?
    {
        if (count(self::$tpwp->settings->sg_divisions) < 1) {
            // Don't update if there aren't any divisions selected yet.
            return false;
        }

        $divs = implode(',', self::$tpwp->settings->sg_divisions);
        $divs = str_replace('div', '', $divs);

        $lMTypes = implode(',', self::$tpwp->settings->sg_leader_types);
        $lMTypes = str_replace('mt', '', $lMTypes);

        $hMTypes = implode(',', self::$tpwp->settings->sg_host_types);
        $hMTypes = str_replace('mt', '', $hMTypes);

        set_time_limit(60);

        try {
            $response = self::$tpwp->apiGet(
                "InvsForDivs",
                ['divs' => $divs, 'leadMemTypes' => $lMTypes, 'hostMemTypes' => $hMTypes]
            );
        } catch (TouchPointWP_Exception $e) {
            return false;
        }

        $siteTz = wp_timezone();

        if ($response instanceof WP_Error) {
            return false;
        }

        $invData = json_decode($response['body'])->data->invs ?? []; // null coalesce for case where there is no data.

        $postsToKeep = [];

        foreach ($invData as $inv) {
            set_time_limit(15);

            $q = new WP_Query(
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

            if ($post instanceof WP_Error) {
                error_log($post->get_error_message());
                continue;
            }

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
                $dayStr = TouchPointWP::getDayOfWeekShortForNumber($days[0]) .
                        " & " . TouchPointWP::getDayOfWeekShortForNumber($days[1]);
            } elseif (count($days) === 1) {
                $dayStr = TouchPointWP::getPluralDayOfWeekNameForNumber(reset($days));
            } elseif ($inv->meetNextMeeting !== null) {
                $dayStr = TouchPointWP::getPluralDayOfWeekNameForNumber(date("w", strtotime($inv->meetNextMeeting)));
            } else {
                $dayStr = null;
            }

            // Day of week attributes
            $dayTerms = [];
            foreach ($days as $d) {
                $dayTerms[] = TouchPointWP::getDayOfWeekShortForNumber($d);
            }
            wp_set_post_terms($post->ID, $dayTerms, TouchPointWP::TAX_WEEKDAY, false);

            // Times of day  TODO (eventually) allow for different times of day on different days of the week.
            if ($dayStr !== null) {
                $times = array_diff([$inv->sched1Time, $inv->sched2Time, $inv->meetNextMeeting], [null]);

                if (count($times) > 0) {
                    $times = date(get_option('time_format'), strtotime(reset($times)));
                    $dayStr  .= " " . __('at', TouchPointWP::TEXT_DOMAIN) . " " . $times;
                }
            }
            update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "meetingSchedule", $dayStr);

            // Handle leaders  TODO make leaders WP Users
            if (property_exists($inv, "leaders")) {
                $nameString = Person::arrangeNamesForPeople($inv->leaders);
                update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "leaders", $nameString);
            }

            // Handle locations TODO handle cases other than hosted at home  (Also applies to ResCode)
            if (property_exists($inv, "hostGeo") && $inv->hostGeo !== null) {
                update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "geo_lat", $inv->hostGeo->lat);
                update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "geo_lng", $inv->hostGeo->lng);
            }

            // Handle Resident Code
            if (property_exists($inv, "hostGeo") && $inv->hostGeo !== null && $inv->hostGeo->resCodeName !== null) {
                wp_set_post_terms($post->ID, [$inv->hostGeo->resCodeName], TouchPointWP::TAX_RESCODE, false);
            } else {
                wp_set_post_terms($post->ID, [], TouchPointWP::TAX_RESCODE, false);
            }

            // Handle Marital Status
            $maritalTax = [];
            if ($inv->marital_denom > 4) { // only include involvements with at least 4 people with known marital statuses.
                $marriedProportion = (float)$inv->marital_married / $inv->marital_denom;
                if ($marriedProportion > 0.7) {
                    $maritalTax[] = "mostly_married";
                } elseif ($marriedProportion < 0.3) {
                    $maritalTax[] = "mostly_single";
                }
            }
            wp_set_post_terms($post->ID, $maritalTax, TouchPointWP::TAX_INV_MARITAL, false);

            // Handle Age Groups
            if ($inv->age_groups === null) {
                wp_set_post_terms($post->ID, [], TouchPointWP::TAX_AGEGROUP, false);
            } else {
                wp_set_post_terms($post->ID, $inv->age_groups, TouchPointWP::TAX_AGEGROUP, false);
            }

            $postsToKeep[] = $post->ID;
        }

        // Delete posts that are no longer current
        $q = new WP_Query(
            [
                'post_type' => self::POST_TYPE,
                'nopaging'  => true,
            ]
        );
        $removals = 0;
        foreach ($q->get_posts() as $post) {
            if ( ! in_array($post->ID, $postsToKeep)) {
                set_time_limit(10);
                wp_delete_post($post->ID, true);
                $removals++;
            }
        }

        self::$tpwp->settings->set('sg_cron_last_run', time());

        return count($invData) + $removals;
    }

    /**
     * @param string $template
     *
     * @return string
     */
    public static function templateFilter(string $template): string
    {
        $postTypesToFilter        = [self::POST_TYPE];
        $templateFilesToOverwrite = ['archive.php', 'singular.php', 'single.php', 'index.php'];

        if ( ! in_array(ltrim(strrchr($template, '/'), '/'), $templateFilesToOverwrite)) {
            return $template;
        }

        if (is_post_type_archive($postTypesToFilter) && file_exists(
                TouchPointWP::$dir . '/src/templates/smallgroup-archive.php'
            )) {
            $template = TouchPointWP::$dir . '/src/templates/smallgroup-archive.php';
        }

        if (is_singular($postTypesToFilter) && file_exists(
                TouchPointWP::$dir . '/src/templates/smallgroup-single.php'
            )) {
            $template = TouchPointWP::$dir . '/src/templates/smallgroup-single.php';
        }

        return $template;
    }

    /**
     * @param $theDate
     * @param $format
     * @param $post
     *
     * @return string
     */
    public static function filterPublishDate($theDate, $format, $post): string
    {
        if (get_post_type($post) === SmallGroup::POST_TYPE) {
            if (!is_numeric($post))
                $post = $post->ID;
            $theDate = get_post_meta($post, TouchPointWP::SETTINGS_PREFIX . "meetingSchedule", true);
        }
        return $theDate;
    }

    public static function registerScriptsAndStyles(): void
    {
        wp_register_script(
            TouchPointWP::SHORTCODE_PREFIX . "googleMaps",
            sprintf(
                "https://maps.googleapis.com/maps/api/js?key=%s&v=3&libraries=geometry",
                self::$tpwp->settings->google_maps_api_key
            ),
            [TouchPointWP::SHORTCODE_PREFIX . "base-defer"],
            null,
            true
        );

        wp_register_script(
            TouchPointWP::SHORTCODE_PREFIX . "knockout",
            "https://ajax.aspnetcdn.com/ajax/knockout/knockout-3.5.0.js",
            [],
            '3.5.0',
            true
        );

        wp_register_script(
            TouchPointWP::SHORTCODE_PREFIX . "smallgroup-defer",
            self::$tpwp->assets_url . 'js/smallgroup-defer.js',
            [TouchPointWP::SHORTCODE_PREFIX . "base-defer"],
            TouchPointWP::VERSION,
            true
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
        if ( ! self::$_hasUsedMap) {
            self::$_hasUsedMap = true;

            // standardize parameters
            $params = array_change_key_case($params, CASE_LOWER);

            wp_enqueue_script(TouchPointWP::SHORTCODE_PREFIX . "googleMaps");
            wp_enqueue_script(TouchPointWP::SHORTCODE_PREFIX . "smallgroup-defer");

            // set some defaults
            $params = shortcode_atts(
                [
                    'class' => 'TouchPoint-smallgroup map',
                    'all' => null
                ],
                $params,
                self::SHORTCODE_MAP
            );

            if (isset($params['id'])) {
                $mapDivId = $params['id'];
            } else {
                $mapDivId = wp_unique_id('tp-map-');
            }

            if ($params['all'] === null) {
                $params['all'] = is_archive();
            }

            $script = file_get_contents(TouchPointWP::$dir . "/src/js-partials/smallgroup-map-inline.js");

            $script = str_replace('{$smallgroupsList}', json_encode(self::getSmallGroupsForMap($params)), $script);
            $script = str_replace('{$mapDivId}', $mapDivId, $script); // TODO it should be possible to have action buttons without a map

            wp_add_inline_script(
                TouchPointWP::SHORTCODE_PREFIX . "googleMaps",
                $script
            );

            // TODO move the style to a css file... or something.
            $content = "<div class=\"TouchPoint-SmallGroup-Map\" style=\"height: 100%; width: 100%; position: absolute; top: 0; left: 0; \" id=\"{$mapDivId}\"></div>";
        } else {
            $content = "<!-- Error: Small Group map can only be used once per page. -->";
        }
        return $content;
    }

    /**
     * Gets a list of small groups, used to load the metadata into JS for the map and filtering capabilities.
     *
     * @param $params array Parameters from shortcode
     * @param $post WP_Post A post object to use, especially if single.
     *
     * @return SmallGroup[]
     */
    protected static function getSmallGroupsForMap(array $params = [], $post = null): array
    {

        if ($params['all'] === true) {
            global $wpdb;

            $settingsPrefix = TouchPointWP::SETTINGS_PREFIX;
            $postType       = self::POST_TYPE;

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
                WHERE p.post_type = '{$postType}' AND p.post_status = 'publish' AND p.post_date_gmt < utc_timestamp()"; // TODO possibly add ResCode?

            $ret = [];
            foreach ($wpdb->get_results($sql, "OBJECT") as $row) {
                $ret[] = self::fromObj($row);
            }
            return $ret;
        }

        // Single
        if (!$post) {
            $post = get_post();
        }

        return [self::fromPost($post)];
    }

    /**
     * Create a SmallGroup object from an object from a database query.
     *
     * @param object $obj A database object from which a SmallGroup object should be created.
     *
     * @return SmallGroup
     */
    private static function fromObj(object $obj): SmallGroup
    {
        $iid = intval($obj->invId);

        if ( ! isset(self::$_instances[$iid])) {
            self::$_instances[$iid] = new SmallGroup($obj);
        }

        return self::$_instances[$iid];
    }

    /**
     * @param array $params
     *
     * @return string
     */
    public static function filterShortcode(array $params): string
    {
        // standardize parameters
        $params = array_change_key_case($params, CASE_LOWER);

        // set some defaults
        $params = shortcode_atts(
            [
                'class' => 'TouchPoint-smallgroup filterBar',
                'filters' => strtolower(implode(",", self::$tpwp->settings->get('sg_filter_defaults')))
            ],
            $params,
            self::SHORTCODE_FILTER
        );

        if (isset($params['id'])) {
            $filterBarId = $params['id'];
        } else {
            $filterBarId = wp_unique_id('tp-filter-bar-');
        }

        $filters = explode(',', $params['filters']);

        $class = $params['class'];

        $content = "<div class=\"{$class}\" id=\"{$filterBarId}\">";

        $any = __("Any", TouchPointWP::TEXT_DOMAIN);

        // Gender
        if (in_array('genderid', $filters)) {
            $gList   = self::$tpwp->getGenders();
            $content .= "<select class=\"smallgroup-filter\" data-smallgroup-filter=\"genderId\">";
            $content .= "<option disabled selected>Gender</option><option value=\"\">{$any}</option>";
            foreach ($gList as $g) {
                if ($g->id === 0) {  // skip unknown
                    continue;
                }

                $name    = $g->name;
                $id      = $g->id;
                $content .= "<option value=\"{$id}\">{$name}</option>";
            }
            $content .= "</select>";
        }

        // Resident Codes
        if (in_array('rescode', $filters)) {
            $rcName = self::$tpwp->settings->rc_name_singular;
            $rcList = get_terms(['taxonomy' => TouchPointWP::TAX_RESCODE, 'hide_empty' => true]);
            if (is_array($rcList)) {
                $content .= "<select class=\"smallgroup-filter\" data-smallgroup-filter=\"rescode\">";
                $content .= "<option disabled selected>{$rcName}</option><option value=\"\">{$any}</option>";

                foreach ($rcList as $g) {
                    $name    = $g->name;
                    $id      = $g->slug;
                    $content .= "<option value=\"{$id}\">{$name}</option>";
                }

                $content .= "</select>";
            }
        }

        // Day of Week
        if (in_array('weekday', $filters)) {
            $wdName = __("Weekday");
            $wdList = get_terms(['taxonomy' => TouchPointWP::TAX_WEEKDAY, 'hide_empty' => true, 'orderby' => 'id']);
            if (is_array($wdList) && count($wdList) > 1) {
                $content .= "<select class=\"smallgroup-filter\" data-smallgroup-filter=\"weekday\">";
                $content .= "<option disabled selected>{$wdName}</option><option value=\"\">{$any}</option>";
                foreach ($wdList as $d) {
                    $content .= "<option value=\"{$d->slug}\">{$d->name}</option>";
                }
                $content .= "</select>";
            }
        }

        // TODO Time of Day (ranges, probably)

        // Marital Status
        if (in_array('inv_marital', $filters)) {
            $content .= "<select class=\"smallgroup-filter\" data-smallgroup-filter=\"inv_marital\">";
            $content .= "<option disabled selected>Marital Status</option>";
            $content .= "<option value=\"\">{$any}</option>";
            $content .= "<option value=\"mostly_single\">Mostly Single</option>";  // i18n
            $content .= "<option value=\"mostly_married\">Mostly Married</option>"; // i18n
            $content .= "</select>";
        }

        // Age Groups
        if (in_array('agegroup', $filters)) {
            $agName = __("Age");
            $agList = get_terms(['taxonomy' => TouchPointWP::TAX_AGEGROUP, 'hide_empty' => true]);
            if (is_array($agList)) {
                $content .= "<select class=\"smallgroup-filter\" data-smallgroup-filter=\"agegroup\">";
                $content .= "<option disabled selected>{$agName}</option><option value=\"\">{$any}</option>";
                foreach ($agList as $a) {
                    $content .= "<option value=\"{$a->slug}\">{$a->name}</option>";
                }
                $content .= "</select>";
            }
        }

        $content .= "</div>";

        return $content;
    }


    /**
     * This function enqueues the stylesheet for the default templates, to avoid registering the style on sites where
     * custom templates exist.
     */
    public static function enqueueTemplateStyle()
    {
        wp_enqueue_style(
            TouchPointWP::SHORTCODE_PREFIX . 'smallgroups-template-style',
            self::$tpwp->assets_url . 'template/smallgroups-template-style.css',
            [],
            TouchPointWP::VERSION,
            'all'
        );
    }


    /**
     * @param array  $params
     * @param string $content
     *
     * @return string
     */
    public static function nearbyShortcode($params = [], string $content = ""): string
    {
        wp_enqueue_script(TouchPointWP::SHORTCODE_PREFIX . "knockout");
        wp_enqueue_script(TouchPointWP::SHORTCODE_PREFIX . "smallgroup-defer");

        if ($params === '') {
            $params = [];
        }

        // standardize parameters
        $params = array_change_key_case($params, CASE_LOWER);

        // set some defaults
        $params = shortcode_atts(
            [
                'count' => 3
            ],
            $params,
            self::SHORTCODE_NEARBY
        );

        if ($content === '') {
            // TODO Switch to template, or switch templates to match this.
            $content = file_get_contents(TouchPointWP::$dir . "/src/templates/parts/smallgroup-nearby-list-item.html");
        }

        $nearbyListId = wp_unique_id('tp-nearby-list-');

        $script = file_get_contents(TouchPointWP::$dir . "/src/js-partials/smallgroup-nearby-inline.js");

        $script = str_replace('{$nearbyListId}', $nearbyListId, $script);
        $script = str_replace('{$count}', $params['count'], $script);

        wp_add_inline_script(
            TouchPointWP::SHORTCODE_PREFIX . "smallgroup-defer",
            $script,
            'before'
        );


        $content = "<div class=\"\" id=\"{$nearbyListId}\" data-bind=\"foreach: nearby\">" . $content . "</div>";

        // get any nesting
        $content = do_shortcode($content);

        return $content;
    }

    public static function ajaxNearby()
    {
        $r = self::getGroupsNear($_GET['lat'], $_GET['lng'], $_GET['limit']);

        foreach ($r as $g) {
            $sg      = SmallGroup::fromObj($g);
            $g->name = $sg->name;
            $g->path = get_permalink($sg->post_id);
        }

        echo json_encode($r);
        wp_die();
    }


    /**
     * Gets an array of ID/Distance pairs for a given lat/lng.
     *
     * @param $lat    numeric Longitude
     * @param $lng    numeric Longitude
     * @param $limit  numeric Number of results to return.  0-100 inclusive.
     *
     * @return array|object|null
     */
    protected static function getGroupsNear($lat = null, $lng = null, $limit = 3)
    {
        if ($lat === null || $lng === null) {
            $geoObj = self::$tpwp->geolocate();

            if (!isset($geoObj->error)) {
                $lat = $geoObj->lat;
                $lng = $geoObj->lng;
            }
        }

        $lat = floatval($lat) ?? 39.949601081097036; // TODO use some kind of default.
        $lng = floatval($lng) ?? -75.17186043802126;

        $limit = min(max(intval($limit), 0), 100);

        global $wpdb;
        $settingsPrefix = TouchPointWP::SETTINGS_PREFIX;
        $q = $wpdb->prepare( "
            SELECT l.Id as post_id,
                   l.post_title as name,
                   CAST(pmInv.meta_value AS UNSIGNED) as invId,
                   pmSch.meta_value as schedule,
                   ROUND(3959 * acos(cos(radians(%s)) * cos(radians(lat)) * cos(radians(lng) - radians(%s)) +
                                sin(radians(%s)) * sin(radians(lat))), 1) AS distance
            FROM (SELECT DISTINCT p.Id,
                         p.post_title,
                         CAST(pmLat.meta_value AS DECIMAL(10, 7)) as lat,
                         CAST(pmLng.meta_value AS DECIMAL(10, 7)) as lng
                  FROM $wpdb->posts as p
                           JOIN
                       $wpdb->postmeta as pmLat ON p.ID = pmLat.post_id AND pmLat.meta_key = '{$settingsPrefix}geo_lat'
                           JOIN
                       $wpdb->postmeta as pmLng ON p.ID = pmLng.post_id AND pmLng.meta_key = '{$settingsPrefix}geo_lng'
                WHERE post_type = %s
                 ) as l
                    JOIN $wpdb->postmeta as pmInv ON l.ID = pmInv.post_id AND pmInv.meta_key = '{$settingsPrefix}invId'
                    LEFT JOIN $wpdb->postmeta as pmSch ON l.ID = pmSch.post_id AND pmSch.meta_key = '{$settingsPrefix}meetingSchedule'
            ORDER BY distance LIMIT %d
            ", $lat, $lng, $lat, self::POST_TYPE, $limit );

        return $wpdb->get_results($q);
    }

    /**
     * Create a SmallGroup object from an object from a WP_Post object.
     *
     * @param WP_Post $post
     *
     * @return SmallGroup
     */
    public static function fromPost(WP_Post $post): SmallGroup
    {
        $iid = intval($post->{self::INVOLVEMENT_META_KEY});

        if ( ! isset(self::$_instances[$iid])) {
            self::$_instances[$iid] = new SmallGroup($post);
        }

        return self::$_instances[$iid];
    }

    /**
     * Returns the html with buttons for actions the user can perform.
     *
     * @return string
     */
    public function getActionButtons(): string
    {
        return '
        <button type="button" data-tp-action="contact">Contact Leaders</button>
        <button type="button" data-tp-action="join">Join</button>';
    }


}