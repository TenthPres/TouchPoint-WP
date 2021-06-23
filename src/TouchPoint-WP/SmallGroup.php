<?php

namespace tp\TouchPointWP;

if ( ! defined('ABSPATH')) {
    exit(1);
}

use WP_Post;
use WP_Term;

require_once 'api.php';
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
class SmallGroup extends Involvement implements api
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
                TouchPointWP::TAX_INV_MARITAL,
                TouchPointWP::TAX_DIV
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

        if (is_object($object) &&
            property_exists($object, 'geo_lat') &&
            $object->geo_lat !== null &&
            $object->geo_lat !== '') {
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

        // Register default templates for Small Groups
        add_filter('template_include', [self::class, 'templateFilter']);

        // Register function to return schedule instead of publishing date
        add_filter( 'get_the_date', [self::class, 'filterPublishDate'], 10, 3 );
        add_filter( 'get_the_time', [self::class, 'filterPublishDate'], 10, 3 );

        // Run cron if it hasn't been run before or is overdue.
        if (self::$tpwp->settings->sg_cron_last_run * 1 < time() - 86400 - 3600) {
            self::updateFromTouchPoint();
        }
    }

    /**
     * Query TouchPoint and update Small Groups in WordPress
     *
     * @param bool $verbose Whether to print debugging info.
     *
     * @return false|int False on failure, or the number of groups that were updated or deleted.
     */
    public static function updateFromTouchPoint($verbose = false)
    {
        if (count(self::$tpwp->settings->sg_divisions) < 1) {
            // Don't update if there aren't any divisions selected yet.
            return false;
        }

        // Divisions
        $divs = implode(',', self::$tpwp->settings->sg_divisions);
        $divs = str_replace('div', '', $divs);

        // Leader member types
        $lMTypes = implode(',', self::$tpwp->settings->sg_leader_types);
        $lMTypes = str_replace('mt', '', $lMTypes);

        // Host member types
        $hMTypes = implode(',', self::$tpwp->settings->sg_host_types);
        $hMTypes = str_replace('mt', '', $hMTypes);

        $count = parent::updateInvolvementPosts(self::POST_TYPE, $divs, ['leadMemTypes' => $lMTypes, 'hostMemTypes' => $hMTypes], $verbose);

        if ($count !== false) {
            self::$tpwp->settings->set('sg_cron_last_run', time());
        }

        return $count;
    }

    /**
     * @param string $template
     *
     * @return string
     *
     * @noinspection unused
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
     * Register scripts and styles to be used on display pages.
     */
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

        parent:: registerScriptsAndStyles();

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
     *
     * @noinspection PhpUnusedParameterInspection
     */
    public static function mapShortcode(array $params = [], string $content = ""): string
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

            /** @noinspection SqlResolve */
            /** @noinspection SpellCheckingInspection */
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

        // Division
        if (in_array('div', $filters)) {
            $exclude = TouchPointWP::instance()->settings->sg_divisions;
            if (count($exclude) > 0) {
                $mq = ['relation' => "AND"];
                foreach ($exclude as $e) {
                    $mq[] = [
                        'key' => TouchPointWP::SETTINGS_PREFIX . 'divId',
                        'value' => substr($e, 3),
                        'compare' => 'NOT LIKE'
                    ];
                }
                $mq = [
                    'relation' => "OR",
                    [
                        'key' => TouchPointWP::SETTINGS_PREFIX . 'divId', // Allows for programs
                        'compare' => 'NOT EXISTS'
                    ],
                    $mq
                ];
            } else {
                $mq = [];
            }
            $dvName = TouchPointWP::instance()->settings->dv_name_singular;
            $dvList = get_terms([
                'taxonomy'   => TouchPointWP::TAX_DIV,
                'hide_empty' => true,
                'meta_query' => $mq,
                'post_type'  => self::POST_TYPE
            ]);
            $dvList = TouchPointWP::orderHierarchicalTerms($dvList);
            if (is_array($dvList) && count($dvList) > 1) {
                $content .= "<select class=\"smallgroup-filter\" data-smallgroup-filter=\"div\">";
                $content .= "<option disabled selected>{$dvName}</option><option value=\"\">{$any}</option>";
                $isFirst = true;
                foreach ($dvList as $d) {
                    if ($d->parent === 0 || $isFirst) {
                        if (! $isFirst ) {
                            $content .= "</optgroup>";
                        }
                        $content .= "<optgroup label=\"{$d->name}\">";
                    } else {
                        $content .= "<option value=\"{$d->slug}\">{$d->name}</option>";
                    }
                    $isFirst = false;
                }
                $content .= "</optgroup></select>";
            }
        }

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
            $rcList = get_terms([
                'taxonomy' => TouchPointWP::TAX_RESCODE,
                'hide_empty' => true,
                'post_type'  => self::POST_TYPE
            ]);
            if (is_array($rcList) && count($rcList) > 1) {
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
            $wdList = get_terms([
                'taxonomy'   => TouchPointWP::TAX_WEEKDAY,
                'hide_empty' => true,
                'orderby'    => 'id',
                'post_type'  => self::POST_TYPE
            ]);
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
            if (is_array($agList) && count($agList) > 1) {
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
            TouchPointWP::SHORTCODE_PREFIX . 'involvement-template-style',
            self::$tpwp->assets_url . 'template/involvement-template-style.css',
            [],
            TouchPointWP::VERSION
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

    /**
     *
     * @noinspection PhpUnused
     */
    public static function ajaxNearby()
    {
        $r = self::getGroupsNear($_GET['lat'], $_GET['lng'], $_GET['limit']);

        if ($r === null) {
            $r = [];
        }

        foreach ($r as $g) {
            $sg      = self::fromObj($g);
            $g->name = $sg->name;
            $g->path = get_permalink($sg->post_id);
        }

        echo json_encode($r);
        exit;
    }

    /**
     * @param string[] $exclude
     *
     * @return string[]
     */
    public function getDivisionsStrings($exclude = null): array
    {
        if ($exclude === null) {
            $exclude = self::$tpwp->settings->sg_divisions;
        }
        return parent::getDivisionsStrings($exclude);
    }


    /**
     * Gets an array of ID/Distance pairs for a given lat/lng.
     *
     * @param float|null $lat   Longitude
     * @param float|null $lng   Longitude
     * @param int        $limit Number of results to return.  0-100 inclusive.
     *
     * @return object[]|null  An array of database query result objects, or null if the location isn't provided or valid.
     */
    protected static function getGroupsNear(?float $lat = null, ?float $lng = null, int $limit = 3): ?array
    {
        if ($lat === null || $lng === null) {
            $geoObj = self::$tpwp->geolocate();

            if (!isset($geoObj->error)) {
                $lat = $geoObj->lat;
                $lng = $geoObj->lng;
            }
        }

        if ($lat === null || $lng === null ||
            $lat > 90 || $lat < -90 ||
            $lat > 180 || $lat < -180
        ) {
            return null;
        }

        $limit = min(max(intval($limit), 0), 100);

        global $wpdb;
        $settingsPrefix = TouchPointWP::SETTINGS_PREFIX;
        /** @noinspection SqlResolve */
        /** @noinspection SpellCheckingInspection */
        $q = $wpdb->prepare("
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

        return $wpdb->get_results($q, 'OBJECT');
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
     * Whether the involvement is currently joinable.
     *
     * @return bool|string  True if joinable.  Or, a string with why it can't be joined otherwise.
     */
    public function acceptingNewMembers()
    {
        // TODO add extra value options
        return parent::acceptingNewMembers();
    }

    /**
     * Handle API requests
     *
     * @param array $uri The request URI already parsed by parse_url()
     *
     * @return bool False if endpoint is not found.  Should print the result.
     */
    public static function api(array $uri): bool
    {
        if (count($uri['path']) < 3) {
            return false;
        }

        switch (strtolower($uri['path'][2])) {
            case "nearby":
                TouchPointWP::noCacheHeaders();
                self::ajaxNearby();
                exit;

            case "force-sync":
                TouchPointWP::noCacheHeaders();
                echo self::updateFromTouchPoint(true);
                exit;
        }

        return parent::api($uri);
    }
}