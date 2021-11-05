<?php
namespace tp\TouchPointWP;

if ( ! defined('ABSPATH')) {
    exit(1);
}

require_once 'api.iface.php';
require_once "jsInstantiation.trait.php";
require_once "Utilities.php";
require_once "Involvement_PostTypeSettings.php";

use DateInterval;
use DateTimeImmutable;
use Exception;
use WP_Error;
use WP_Post;
use WP_Query;
use WP_Term;

/**
 * Class Involvement - Fundamental object meant to correspond to an Involvement in TouchPoint
 *
 * @package tp\TouchPointWP
 */
class Involvement implements api
{
    use jsInstantiation;

    public const SHORTCODE_MAP = TouchPointWP::SHORTCODE_PREFIX . "Inv-Map";
    public const SHORTCODE_FILTER = TouchPointWP::SHORTCODE_PREFIX . "Inv-Filters";
    public const SHORTCODE_NEARBY = TouchPointWP::SHORTCODE_PREFIX . "Inv-Nearby";
    public const CRON_HOOK = TouchPointWP::HOOK_PREFIX . "inv_cron_hook";
    protected static TouchPointWP $tpwp;
    protected static bool $_hasUsedMap = false;
    private static array $_instances = [];
    private static bool $_isInitiated = false;

    private static bool $filterJsAdded = false;
    public object $geo;
    static protected object $compareGeo;

    public string $name;
    public int $invId;
    public string $invType;
    public int $post_id; // TODO Determine if there needs to be the possibility for instantiating multiple posts and/or multiple Involvement objs to account for the multiple Types an involvement could have.
    public string $post_excerpt;
    protected WP_Post $post;

    public const INVOLVEMENT_META_KEY = TouchPointWP::SETTINGS_PREFIX . "invId";

    public object $attributes;
    protected array $divisions;

    /**
     * Involvement constructor.
     *
     * @param $object WP_Post|object an object representing the involvement's post.
     *                  Must have post_id and inv id attributes.
     *
     * @throws TouchPointWP_Exception
     */
    protected function __construct(object $object)
    {
        $this->attributes = (object)[];

        if (gettype($object) === "object" && get_class($object) == WP_Post::class) {
            /** @var $object WP_Post */
            // WP_Post Object
            $this->post = $object;
            $this->name = $object->post_title;
            $this->invId = intval($object->{self::INVOLVEMENT_META_KEY});
            $this->post_id = $object->ID;
            $this->invType = get_post_type($this->post_id);

        } elseif (gettype($object) === "object") {
            // Sql Object, probably.

            if (!property_exists($object, 'post_id'))
                _doing_it_wrong(
                    __FUNCTION__,
                    esc_html(__('Creating an Involvement object from an object without a post_id is not yet supported.')),
                    esc_attr(TouchPointWP::VERSION)
                );

            $this->post = get_post($object, "OBJECT");
            $this->post_id = $this->post->ID;
            $this->invType = $object->invType;


            foreach ($object as $property => $value) {
                if (property_exists(self::class, $property)) {
                    $this->$property = $value;
                }

                // TODO add an else for nonstandard/optional metadata fields
            }
        } else {
            throw new TouchPointWP_Exception("Could not construct an Involvement with the information provided.");
        }

        // clean up involvement type to not have hook prefix, if it does.
        if (strpos($this->invType, TouchPointWP::HOOK_PREFIX) === 0) {
            $this->invType = substr($this->invType, strlen(TouchPointWP::HOOK_PREFIX));
        }

        $terms = wp_get_post_terms(
            $this->post_id,
            [
                TouchPointWP::TAX_RESCODE,
                TouchPointWP::TAX_AGEGROUP,
                TouchPointWP::TAX_WEEKDAY,
                TouchPointWP::TAX_DAYTIME,
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

        if (self::getSettingsForPostType($this->invType)->useGeo) {
            if (property_exists($object, 'geo_lat') &&
                $object->geo_lat !== null &&
                $object->geo_lat !== '') {
                // Probably a database query result
                $this->geo = (object)[
                    'lat' => Utilities::toFloatOrNull($object->geo_lat),
                    'lng' => Utilities::toFloatOrNull($object->geo_lng)
                ];
            } elseif (get_class($object) === WP_Post::class) {
                // Probably a post
                $this->geo = (object)[
                    'lat' => Utilities::toFloatOrNull(
                        get_post_meta($this->post_id, TouchPointWP::SETTINGS_PREFIX . "geo_lat", true)
                    ),
                    'lng' => Utilities::toFloatOrNull(
                        get_post_meta($this->post_id, TouchPointWP::SETTINGS_PREFIX . "geo_lng", true)
                    )
                ];
            }
        }

        $this->registerConstruction();
    }

    /**
     * Get the settings array of objects for Involvement Post Types
     *
     * @return Involvement_PostTypeSettings[]
     */
    final protected static function &settings(): array
    {
        return Involvement_PostTypeSettings::instance();
    }


    /**
     * Register stuff
     */
    public static function init(): void
    {
        foreach (self::settings() as $type) {
            /** @var $type Involvement_PostTypeSettings */

            register_post_type(
                $type->postType,
                [
                    'labels'       => [
                        'name'          => $type->namePlural,
                        'singular_name' => $type->nameSingular
                    ],
                    'public'       => true,
                    'hierarchical' => false,
                    'show_ui'      => false,
                    'show_in_nav_menus' => true,
                    'show_in_rest' => true,
                    'supports'     => [
                        'title',
                        'custom-fields'
                    ],
                    'has_archive'  => true,
                    'rewrite'      => [
                        'slug' => $type->slug,
                        'with_front' => false,
                        'feeds'      => false,
                        'pages'      => true
                    ],
                    'query_var'        => $type->slug,
                    'can_export'       => false,
                    'delete_with_user' => false
                ]
            );
        }

        // Register default templates for Involvements
        add_filter('template_include', [self::class, 'templateFilter']);

        // Register function to return schedule instead of publishing date
        add_filter('get_the_date', [self::class, 'filterPublishDate'], 10, 3);
        add_filter('get_the_time', [self::class, 'filterPublishDate'], 10, 3);

        // Run cron if it hasn't been run before or is overdue.
        if (self::$tpwp->settings->inv_cron_last_run * 1 < time() - 86400 - 3600) {
            self::updateFromTouchPoint();
        }
    }


    /**
     * Query TouchPoint and update Involvements in WordPress
     *
     * @param bool $verbose Whether to print debugging info.
     *
     * @return false|int False on failure, or the number of groups that were updated or deleted.
     */
    public static function updateFromTouchPoint(bool $verbose = false)
    {
        $count = 0;

        foreach (self::settings() as $type) {
            if (count($type->importDivs) < 1) {
                // Don't update if there aren't any divisions selected yet.
                continue;
            }

            // Divisions
            $divs = implode(',', $type->importDivs);
            $divs = str_replace('div', '', $divs);

            $count += self::updateInvolvementPostsForType(
                $type,
                $divs,
                $verbose
            );
        }

        if ($count !== false && $count !== 0) {
            self::$tpwp->settings->set('inv_cron_last_run', time());
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
        $postTypesToFilter        = Involvement_PostTypeSettings::getPostTypes();
        $templateFilesToOverwrite = ['archive.php', 'singular.php', 'single.php', 'index.php'];

        if ( ! in_array(ltrim(strrchr($template, '/'), '/'), $templateFilesToOverwrite)) {
            return $template;
        }
        
        if (is_post_type_archive($postTypesToFilter) && file_exists(
                TouchPointWP::$dir . '/src/templates/involvement-archive.php'
            )) {
            $template = TouchPointWP::$dir . '/src/templates/involvement-archive.php';
        }

        if (is_singular($postTypesToFilter) && file_exists(
                TouchPointWP::$dir . '/src/templates/involvement-single.php'
            )) {
            $template = TouchPointWP::$dir . '/src/templates/involvement-single.php';
        }

        return $template;
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
     * Whether the involvement can be joined
     *
     * @return bool|string  True if involvement can be joined.  Or, a string with why it can't be joined otherwise.
     */
    public function acceptingNewMembers()
    {
        if (get_post_meta($this->post_id, TouchPointWP::SETTINGS_PREFIX . "groupFull", true) === '1') {
            return __("Currently Full", TouchPointWP::TEXT_DOMAIN);
        }

        if (get_post_meta($this->post_id, TouchPointWP::SETTINGS_PREFIX . "groupClosed", true) === '1') {
            return __("Currently Closed", TouchPointWP::TEXT_DOMAIN);
        }

        $now = current_datetime();
        $regStart = get_post_meta($this->post_id, TouchPointWP::SETTINGS_PREFIX . "regStart", true);
        if ($regStart !== false && $regStart !== '' && $regStart > $now) {
            return __("Registration Not Open Yet", TouchPointWP::TEXT_DOMAIN);
        }

        $regEnd = get_post_meta($this->post_id, TouchPointWP::SETTINGS_PREFIX . "regEnd", true);
        if ($regEnd !== false && $regEnd !== '' && $regEnd < $now) {
            return __("Registration Closed", TouchPointWP::TEXT_DOMAIN);
        }

        if (intval(get_post_meta($this->post_id, TouchPointWP::SETTINGS_PREFIX . "regTypeId", true)) === 0) {
            return false; // no online registration available
        }

        return true;
    }

    /**
     * Whether the involvement should link to a registration form, rather than directly joining the org.
     *
     * @return bool
     */
    public function useRegistrationForm(): bool
    {
        return (get_post_meta($this->post_id, TouchPointWP::SETTINGS_PREFIX . "hasRegQuestions", true) === '1' ||
                intval(get_post_meta($this->post_id, TouchPointWP::SETTINGS_PREFIX . "regTypeId", true)) !== 1);
    }

    /**
     * @param $exclude
     *
     * @return string[]
     */
    public function getDivisionsStrings(array $exclude = []): array
    {
        if ($exclude === null) {
            $exclude = self::$tpwp->settings->sg_divisions;
        }

        if (!isset($this->divisions)) {
            if (count($exclude) > 1) {
                $mq = ['relation' => "AND"];
            } else {
                $mq = [];
            }

            foreach ($exclude as $e) {
                $mq[] = [
                    'key' => TouchPointWP::SETTINGS_PREFIX . 'divId',
                    'value' => substr($e, 3),
                    'compare' => 'NOT LIKE'
                ];
            }

            $this->divisions = wp_get_post_terms($this->post_id, TouchPointWP::TAX_DIV, ['meta_query' => $mq]);
        }

        $out = [];
        foreach ($this->divisions as $d) {
            $out[] = $d->name;
        }
        return $out;
    }

    /**
     * Get the setting object for a specific post type
     *
     * @param $postType
     *
     * @return ?Involvement_PostTypeSettings
     */
    public static function getSettingsForPostType($postType): ?Involvement_PostTypeSettings
    {
        return Involvement_PostTypeSettings::getForPostType($postType);
    }

    /**
     * @param array|string $params
     *
     * @return string
     */
    public static function filterShortcode($params = []): string
    {
        // Check that params aren't a string.
        if (is_string($params)) {
            _doing_it_wrong(
                __FUNCTION__,
                "Descriptive parameters are required for the filter shortcode.",
                TouchPointWP::VERSION
            );

            return "<!-- Descriptive parameters are required for the filter shortcode. -->";
        }

        // Attempt to infer the type if it doesn't exist.
        if (! isset($params['type']) || is_null($params['type'])) {
            $params['type'] = is_archive() ? get_queried_object()->name : false;
        }

        // Check that Type parameter exists.
        if ($params['type'] === false) {
            _doing_it_wrong(
                __FUNCTION__,
                "A Post Type is required for the Filter Shortcode.",
                TouchPointWP::VERSION
            );

            return "<!-- A Post Type is required for the Filter Shortcode. -->";
        }

        // Get the settings object
        $settings = self::getSettingsForPostType($params['type']);

        // Make sure post type provided is valid.
        if ($settings === null) {
            _doing_it_wrong(
                __FUNCTION__,
                "The Post Type provided to the Filter Shortcode is invalid.",
                TouchPointWP::VERSION
            );

            return "<!-- The Post Type provided to the Filter Shortcode is invalid. -->";
        }

        self::requireAllObjectsInJs();

        if ( ! self::$filterJsAdded) {
            wp_add_inline_script(
                TouchPointWP::SHORTCODE_PREFIX . 'base-defer',
                "
                tpvm.addEventListener('Involvement_fromArray', function() {
                    TP_Involvement.initFilters();
                });"
            );
            self::$filterJsAdded = true;
        }

        return self::filterDropdownHtml(
            $params,
            $settings->filters,
            $settings->importDivs,
            $settings->postType
        );
    }

    /**
     * @param array|string  $params
     * @param string $content
     *
     * @return string
     */
    public static function nearbyShortcode($params = [], string $content = ""): string
    {
        TouchPointWP::requireScript("knockout-defer");
        TouchPointWP::requireScript("base-defer");

        if ($params === '') {
            $params = [];
        }

        // standardize parameters
        $params = array_change_key_case($params, CASE_LOWER);

        // set some defaults
        $params = shortcode_atts(
            [
                'count' => 3,
                'type' => null
            ],
            $params,
            self::SHORTCODE_NEARBY
        );

        // Attempt to infer the type if it doesn't exist.
        if (! isset($params['type']) || is_null($params['type'])) {
            $params['type'] = is_archive() ? get_queried_object()->name : false;
        }

        // Check that Type parameter exists.
        if ($params['type'] === false) {
            _doing_it_wrong(
                __FUNCTION__,
                "A Post Type is required for the Nearby Involvement Shortcode.",
                TouchPointWP::VERSION
            );

            return "<!-- A Post Type is required for the Nearby Involvement Shortcode. -->";
        }

        if ($content === '') {
            // TODO Switch to template, or switch templates to match this.
            $content = file_get_contents(TouchPointWP::$dir . "/src/templates/parts/involvement-nearby-list-item.html");
        }

        $nearbyListId = wp_unique_id('tp-nearby-list-');

        $script = file_get_contents(TouchPointWP::$dir . "/src/js-partials/involvement-nearby-inline.js");

        $script = str_replace('{$nearbyListId}', $nearbyListId, $script);
        $script = str_replace('{$type}', $params['type'], $script);
        $script = str_replace('{$count}', $params['count'], $script);

        wp_add_inline_script(
            TouchPointWP::SHORTCODE_PREFIX . "knockout-defer",
            $script,
            'after'
        );


        $content = "<div class=\"\" id=\"$nearbyListId\" data-bind=\"foreach: nearby\">" . $content . "</div>";

        // get any nesting
        return do_shortcode($content);
    }


    /**
     * @param array    $params
     * @param string[] $filterListSetting
     * @param string[] $divisions
     * @param string   $postType
     *
     * @return string
     */
    protected static final function filterDropdownHtml(array $params, array $filterListSetting, array $divisions, string $postType): string
    {
        // standardize parameters
        $params = array_change_key_case($params, CASE_LOWER);

        // set some defaults
        $params = shortcode_atts(
            [
                'class'   => "TouchPoint-Involvement filterBar",
                'filters' => strtolower(implode(",", $filterListSetting))
            ],
            $params,
            static::SHORTCODE_FILTER
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
            $exclude = $divisions;
            if (count($exclude) > 0) {
                $mq = ['relation' => "AND"];
                foreach ($exclude as $e) {
                    $mq[] = [
                        'key'     => TouchPointWP::SETTINGS_PREFIX . 'divId',
                        'value'   => substr($e, 3),
                        'compare' => 'NOT LIKE'
                    ];
                }
                $mq = [
                    'relation' => "OR",
                    [
                        'key'     => TouchPointWP::SETTINGS_PREFIX . 'divId', // Allows for programs
                        'compare' => 'NOT EXISTS'
                    ],
                    $mq
                ];
            } else {
                $mq = [];
            }
            $dvName = TouchPointWP::instance()->settings->dv_name_singular;
            $dvList = get_terms([
                                    'taxonomy'                              => TouchPointWP::TAX_DIV,
                                    'hide_empty'                            => true,
                                    'meta_query'                            => $mq,
                                    TouchPointWP::HOOK_PREFIX . 'post_type' => $postType
                                ]);
            $dvList = TouchPointWP::orderHierarchicalTerms($dvList, true);
            if (is_array($dvList) && count($dvList) > 1) {
                $content .= "<select class=\"$class-filter\" data-involvement-filter=\"div\">";
                $content .= "<option disabled selected>{$dvName}</option><option value=\"\">{$any}</option>";
                $isFirst = true;
                foreach ($dvList as $d) {
                    if ($d->parent === 0 || $isFirst) {
                        if ( ! $isFirst) {
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
            $gList   = TouchPointWP::instance()->getGenders();
            $content .= "<select class=\"$class-filter\" data-involvement-filter=\"genderId\">";
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
            $rcName = TouchPointWP::instance()->settings->rc_name_singular;
            $rcList = get_terms(
                [
                    'taxonomy'                              => TouchPointWP::TAX_RESCODE,
                    'hide_empty'                            => true,
                    TouchPointWP::HOOK_PREFIX . 'post_type' => $postType
                ]
            );
            if (is_array($rcList) && count($rcList) > 1) {
                $content .= "<select class=\"$class-filter\" data-involvement-filter=\"rescode\">";
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
            $wdList = get_terms(
                [
                    'taxonomy'   => TouchPointWP::TAX_WEEKDAY,
                    'hide_empty' => true,
                    'orderby'    => 'id',
                    TouchPointWP::HOOK_PREFIX . 'post_type' => $postType
                ]
            );
            if (is_array($wdList) && count($wdList) > 1) {
                $content .= "<select class=\"$class-filter\" data-involvement-filter=\"weekday\">";
                $content .= "<option disabled selected>{$wdName}</option><option value=\"\">{$any}</option>";
                foreach ($wdList as $d) {
                    $content .= "<option value=\"{$d->slug}\">{$d->name}</option>";
                }
                $content .= "</select>";
            }
        }

        // Time of Day
        if (in_array('timeofday', $filters)) {
            $todName = __("Time of Day");
            $todList = get_terms(
                [
                    'taxonomy'   => TouchPointWP::TAX_DAYTIME,
                    'hide_empty' => true,
                    'orderby'    => 'id',
                    TouchPointWP::HOOK_PREFIX . 'post_type' => $postType
                ]
            );
            if (is_array($todList) && count($todList) > 1) {
                $content .= "<select class=\"$class-filter\" data-involvement-filter=\"timeOfDay\">";
                $content .= "<option disabled selected>{$todName}</option><option value=\"\">{$any}</option>";
                foreach ($todList as $t) {
                    $content .= "<option value=\"{$t->slug}\">{$t->name}</option>";
                }
                $content .= "</select>";
            }
        }

        // Marital Status
        if (in_array('inv_marital', $filters)) {
            $content .= "<select class=\"$class-filter\" data-involvement-filter=\"inv_marital\">";
            $content .= "<option disabled selected>Marital Status</option>";
            $content .= "<option value=\"\">{$any}</option>";
            $content .= "<option value=\"mostly_single\">Mostly Single</option>";  // i18n
            $content .= "<option value=\"mostly_married\">Mostly Married</option>"; // i18n
            $content .= "</select>";
        }

        // Age Groups
        if (in_array('agegroup', $filters)) {
            $agName = __("Age");
            $agList = get_terms([
                                    'taxonomy'                              => TouchPointWP::TAX_AGEGROUP,
                                    'hide_empty'                            => true,
                                    'orderby'                               => 't.id',
                                    TouchPointWP::HOOK_PREFIX . 'post_type' => $postType
                                ]);
            if (is_array($agList) && count($agList) > 1) {
                $content .= "<select class=\"$class-filter\" data-involvement-filter=\"agegroup\">";
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
     * Create an Involvement object from an object from a WP_Post object.
     *
     * @param WP_Post $post
     *
     * @return Involvement
     */
    public static function fromPost(WP_Post $post): Involvement
    {
        $iid = intval($post->{self::INVOLVEMENT_META_KEY});

        if ( ! isset(self::$_instances[$iid])) {
            self::$_instances[$iid] = new Involvement($post);
        }

        return self::$_instances[$iid];
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
            case "join":
                self::ajaxInvJoin();
                exit;

            case "contact":
                self::ajaxInvContact();
                exit;

            case "nearby":
                TouchPointWP::doCacheHeaders(TouchPointWP::CACHE_PRIVATE);
                self::ajaxNearby();
                exit;

            case "force-sync":
                TouchPointWP::doCacheHeaders(TouchPointWP::CACHE_NONE);
                echo self::updateFromTouchPoint(true);
                exit;
        }

        return false;
    }


    public static function ajaxNearby() // TODO this should get some fairly drastic reworking to fit into other existing data structures.
    {
        $settings = self::getSettingsForPostType($_GET['type']);

        if (! $settings->useGeo) {
            return [];
        }

        $r = self::getGroupsNear($_GET['lat'], $_GET['lng'], $settings->postType, $_GET['limit']);

        if ($r === null) {
            $r = [];
        }

        foreach ($r as $g) {
            $inv      = self::fromObj($g);
            $g->name = $inv->name;
            $g->invType = $settings->postTypeWithoutPrefix();
            $g->path = get_permalink($inv->post_id);
        }

        echo json_encode($r);
        exit;
    }


    /**
     * Gets an array of ID/Distance pairs for a given lat/lng.
     *
     * Math from https://stackoverflow.com/a/574736/2339939
     *
     * @param float|null $lat Longitude
     * @param float|null $lng Longitude
     * @param int        $limit Number of results to return.  0-100 inclusive.
     *
     * @return object[]|null  An array of database query result objects, or null if the location isn't provided or
     *     valid.
     */
    private static function getGroupsNear(?float $lat = null, ?float $lng = null, string $postType = null, int $limit = 3): ?array
    {
        if ($lat === null || $lng === null) {
            $geoObj = self::$tpwp->geolocate();

            if ( ! isset($geoObj->error)) {
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

        $limit = min(max($limit, 0), 100);

        global $wpdb;
        $settingsPrefix = TouchPointWP::SETTINGS_PREFIX;
        /** @noinspection SqlResolve */
        $q = $wpdb->prepare(
            "
            SELECT l.Id as post_id,
                   l.post_title as name,
                   l.post_type as invType,
                   CAST(pmInv.meta_value AS UNSIGNED) as invId,
                   pmSch.meta_value as schedule,
                   ROUND(3959 * acos(cos(radians(%s)) * cos(radians(lat)) * cos(radians(lng) - radians(%s)) +
                                sin(radians(%s)) * sin(radians(lat))), 1) AS distance
            FROM (SELECT DISTINCT p.Id,
                         p.post_title,
                         p.post_type,
                         CAST(pmLat.meta_value AS DECIMAL(10, 7)) as lat,
                         CAST(pmLng.meta_value AS DECIMAL(10, 7)) as lng
                  FROM $wpdb->posts as p
                           JOIN
                       $wpdb->postmeta as pmLat ON p.ID = pmLat.post_id AND pmLat.meta_key = '{$settingsPrefix}geo_lat'
                           JOIN
                       $wpdb->postmeta as pmLng ON p.ID = pmLng.post_id AND pmLng.meta_key = '{$settingsPrefix}geo_lng'
                WHERE p.post_type = %s
                 ) as l
                    JOIN $wpdb->postmeta as pmInv ON l.ID = pmInv.post_id AND pmInv.meta_key = '{$settingsPrefix}invId'
                    LEFT JOIN $wpdb->postmeta as pmSch ON l.ID = pmSch.post_id AND pmSch.meta_key = '{$settingsPrefix}meetingSchedule'
            ORDER BY distance LIMIT %d
            ",
            $lat,
            $lng,
            $lat,
            $postType,
            $limit
        );

        return $wpdb->get_results($q, 'OBJECT');
    }


    /**
     * Create a Involvement object from an object from a database query.
     *
     * @param object $obj A database object from which an Involvement object should be created.
     *
     * @return Involvement
     */
    private static function fromObj(object $obj): Involvement
    {
        $iid = intval($obj->invId);

        if ( ! isset(self::$_instances[$iid])) {
            self::$_instances[$iid] = new Involvement($obj);
        }

        return self::$_instances[$iid];
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
        add_action(self::CRON_HOOK, [self::class, 'updateFromTouchPoint']);
        if ( ! wp_next_scheduled(self::CRON_HOOK)) {
            // Runs at 6am EST (11am UTC), hypothetically after TouchPoint runs its Morning Batches.
            wp_schedule_event(
                date('U', strtotime('tomorrow') + 3600 * 11),
                'daily',
                self::CRON_HOOK
            );
        }

        return true;
    }


    /**
     * Math thanks to https://stackoverflow.com/a/574736/2339939
     *
     * @param bool $useHiForFalse Set to true if a high number should be used for distances that can't be computed.  Used for sorting by distance with closest first.
     *
     * @return float
     */
    public function getDistance(bool $useHiForFalse = false)
    {
        if ( ! isset(self::$compareGeo->lat) || ! isset(self::$compareGeo->lng) ||
             ! isset($this->geo->lat) || ! isset($this->geo->lng) ||
             $this->geo->lat === null || $this->geo->lng === null) {
            return $useHiForFalse ? 25000 : false;
        }

        $latA_r = deg2rad($this->geo->lat);
        $lngA_r = deg2rad($this->geo->lng);
        $latB_r = deg2rad(self::$compareGeo->lat);
        $lngB_r = deg2rad(self::$compareGeo->lng);

        return round(3959 * acos(
                         cos($latA_r) * cos($latB_r) * cos($lngB_r - $lngA_r) + sin($latA_r) * sin($latB_r)
                     ), 1);
    }


    /**
     * @param object $geo Set a geo object to use for distance comparisons.  Needs to be called before getDistance()
     */
    public static function setComparisonGeo(object $geo): void
    {
        if (get_class($geo) !== \WP_Error::class) {
            self::$compareGeo = $geo;
        }
    }


    /**
     * Put SmallGroup objects in order of increasing distance.  Closed groups go to the end.
     *
     * @param Involvement $a
     * @param Involvement $b
     *
     * @return int
     */
    public static function sort(Involvement $a, Involvement $b): int
    {
        $ad = $a->getDistance(true);
        if ($a->acceptingNewMembers() !== true) {
            $ad += 30000;
        }
        $bd = $b->getDistance(true);
        if ($b->acceptingNewMembers() !== true) {
            $bd += 30000;
        }
        if ($ad == $bd) {
            return strcasecmp($a->name, $b->name);
        }
        return $ad <=> $bd;
    }


    /**
     * Put Post objects that represent Small Groups in order of increasing distance.
     *
     * @param WP_Post $a
     * @param WP_Post $b
     *
     * @return int
     */
    public static function sortPosts(WP_Post $a, WP_Post $b): int
    {
        $a = self::fromPost($a);
        $b = self::fromPost($b);
        return self::sort($a, $b);
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
    }

    /**
     * @param array  $params
     * @param string $content
     *
     * @return string
     *
     * @noinspection PhpUnusedParameterInspection WordPress API
     */
    public static function mapShortcode(array $params = [], string $content = ""): string
    {
        if ( ! self::$_hasUsedMap) {
            self::$_hasUsedMap = true;

            // standardize parameters
            $params = array_change_key_case($params, CASE_LOWER);

            TouchPointWP::requireScript("googleMaps");
            TouchPointWP::requireScript("base-defer");

            // set some defaults
            $params = shortcode_atts(
                [
                    'class' => 'TouchPoint-smallgroup map',
                    'all'   => null
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

            if ($params['all']) {
                self::requireAllObjectsInJs();
            }

            $script = file_get_contents(TouchPointWP::$dir . "/src/js-partials/involvement-map-inline.js");

            $script = str_replace('{$mapDivId}', $mapDivId, $script);

            wp_add_inline_script(
                TouchPointWP::SHORTCODE_PREFIX . "googleMaps",
                $script
            );

            // TODO move the style to a css file... or something.
            $content = "<div class=\"TouchPoint-Involvement-Map\" style=\"height: 100%; width: 100%; position: absolute; top: 0; left: 0; \" id=\"$mapDivId\"></div>";
        } else {
            $content = "<!-- Error: Involvement map can only be used once per page. -->";
        }

        return $content;
    }


    /**
     * Update posts that are based on an involvement.
     *
     * @param Involvement_PostTypeSettings $typeSets
     * @param string|int                   $divs
     * @param bool                         $verbose
     *
     * @return false|int  False on failure.  Otherwise, the number of updates.
     */
    final protected static function updateInvolvementPostsForType(Involvement_PostTypeSettings $typeSets, $divs, $verbose = false) {
        $siteTz = wp_timezone();

        set_time_limit(60);

        $qOpts = [];

        // Leader member types
        $lMTypes = implode(',', $typeSets->leaderTypes);
        $qOpts['leadMemTypes'] = str_replace('mt', '', $lMTypes);

        if ($typeSets->useGeo) {
            // Host member types
            $hMTypes = implode(',', $typeSets->hostTypes);
            $qOpts['hostMemTypes'] = str_replace('mt', '', $hMTypes);
        }

        try {
            $response = TouchPointWP::instance()->apiGet(
                "InvsForDivs",
                array_merge($qOpts, ['divs' => $divs])
            );
        } catch (TouchPointWP_Exception $e) {
            return false;
        }

        if ($response instanceof WP_Error) {
            return false;
        }

        $invData = json_decode($response['body'])->data->invs ?? []; // null coalesce for case where there is no data.

        $postsToKeep = [];

        try {
            $now = new DateTimeImmutable(null, $siteTz);
            $aYear = new DateInterval('P1Y');
            $nowPlus1Y = $now->add($aYear);
            unset($aYear);
        } catch  (Exception $e) {
            return false;
        }

        foreach ($invData as $inv) {
            set_time_limit(15);

            if ($verbose) {
                var_dump($inv);
            }

            $q = new WP_Query(
                [
                    'post_type'  => $typeSets->postType,
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
                        'post_type'  => $typeSets->postType,
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

            $post->post_content = strip_tags($inv->description, ['p', 'br', 'a', 'em', 'strong', 'b', 'i', 'u', 'hr', 'ul', 'ol', 'li']);

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
            update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "hasRegQuestions", ! ! $inv->hasRegQuestions);
            update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "regTypeId", intval($inv->regTypeId));

            // Registration start
            if ($inv->regStart !== null) {
                try {
                    $inv->regStart = new DateTimeImmutable($inv->regStart, $siteTz);
                } catch  (Exception $e) {
                    $inv->regStart = null;
                }
            }
            if ($inv->regStart === null) {
                delete_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "regStart");
            } else {
                update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "regStart", $inv->regStart);
            }

            // Registration end
            if ($inv->regEnd !== null) {
                try {
                    $inv->regEnd = new DateTimeImmutable($inv->regEnd, $siteTz);
                } catch  (Exception $e) {
                    $inv->regEnd = null;
                }
            }
            if ($inv->regEnd === null) {
                delete_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "regEnd");
            } else {
                update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "regEnd", $inv->regEnd);
            }


            ////////////////////
            //// SCHEDULING ////
            ////////////////////

            // Establish data model for figuring everything else out.
            if (!is_array($inv->occurrences)) {
                $inv->occurrences = [];
            }

            // TODO deal with frequency on Schedule dates.  That is, occurrences may include non-meeting days.
            // These occurrences will have a type of "S" and should be adjusted forward to the next compliant date.
            // TODO consider removing meeting date/times and only using schedules.

            $upcomingDateTimes = [];
            foreach ($inv->occurrences as $o) {

                if (! is_object($o))
                    continue;

                try {
                    $upcomingDateTimes[] = new DateTimeImmutable($o->dt, $siteTz);
                } catch (Exception $e) {
                }
            }

            // Sort.  Hypothetically, this is already done by the api.
            sort($upcomingDateTimes); // The next meeting datetime is now in position 0.

            // Save next meeting metadata
            if (count($upcomingDateTimes) > 0) {
                update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "nextMeeting", $upcomingDateTimes[0]);
                if ($verbose) {
                    echo "<p>Next occurrence: " . $upcomingDateTimes[0]->format('c') . "</p>";
                }
            } else {
                // No upcoming dates.  Remove meta key.
                delete_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "nextMeeting");
                if ($verbose) {
                    echo "<p>No upcoming occurrences</p>";
                }
            }

            // Determine schedule characteristics for stringifying.
            $uniqueTimeStrings = [];
            $timeTerms = [];
            $days = [];
            $timeFormat = get_option('time_format');
            foreach ($upcomingDateTimes as $dt) {
                /** @var $dt DateTimeImmutable */

                $weekday = "d" . $dt->format('w');

                // days
                if (!isset($days[$weekday])) {
                    $days[$weekday] = [];
                }
                $days[$weekday][] = $dt;

                // times
                $timeStr = $dt->format($timeFormat);
                if (!in_array($timeStr, $uniqueTimeStrings)) {
                    $uniqueTimeStrings[] = $timeStr;
                    $timeTerm = Utilities::getTimeOfDayTermForTime($dt);
                    if (! in_array($timeTerm, $timeTerms)) {
                        $timeTerms[] = $timeTerm;
                    }
                }
                unset($timeStr, $weekday);
            }

            if (count($uniqueTimeStrings) > 1) {
                // multiple different times of day
                $dayStr = [];
                foreach ($days as $dk => $dta) {
                    $timeStr = [];
                    foreach ($dta as $dt) {
                        /** @var $dt DateTimeImmutable */
                        $timeStr[] = $dt->format($timeFormat);
                    }
                    $timeStr = __('at', TouchPointWP::TEXT_DOMAIN) . " " . Utilities::stringArrayToList($timeStr);

                    if (count($days) > 1) {
                        $dayStr[] = Utilities::getDayOfWeekShortForNumber(intval($dk[1])) . ' ' . $timeStr;
                    } else {
                        $dayStr[] = Utilities::getPluralDayOfWeekNameForNumber(intval($dk[1])) . ' ' . $timeStr;
                    }
                }
                $dayStr = Utilities::stringArrayToList($dayStr);

            } elseif (count($uniqueTimeStrings) == 1) {
                // one time of day.
                if (count($days) > 1) {
                    // more than one day per week
                    $dayStr = [];
                    foreach ($days as $k => $d) {
                        $dayStr[] = Utilities::getDayOfWeekShortForNumber(intval($k[1]));
                    }
                    $dayStr = Utilities::stringArrayToList($dayStr);
                } else {
                    // one day of the week
                    $k = array_key_first($days);
                    $dayStr = Utilities::getPluralDayOfWeekNameForNumber(intval($k[1]));
                }
                $dayStr .= ' ' . __('at', TouchPointWP::TEXT_DOMAIN) . " " . $uniqueTimeStrings[0];
            } else {
                $dayStr = null;
            }

            // Start and end dates
            if ($inv->firstMeeting !== null) {
                try {
                    $inv->firstMeeting = new DateTimeImmutable($inv->firstMeeting, $siteTz);
                } catch  (Exception $e) {
                    $inv->firstMeeting = null;
                }
            }
            if ($inv->lastMeeting !== null) {
                try {
                    $inv->lastMeeting = new DateTimeImmutable($inv->lastMeeting, $siteTz);
                } catch  (Exception $e) {
                    $inv->lastMeeting = null;
                }
            }
            // Filter start and end dates to be relevant
            if ($inv->lastMeeting !== null && $inv->lastMeeting < $now) { // last meeting already happened.
                if ($verbose) {
                    echo "<p>Stopping processing because all meetings are in the past.  Involvement will be deleted from WordPress.</p>";
                }
                continue; // Stop processing this involvement.  This will cause it to be removed.
            }
            if ($inv->firstMeeting !== null && $inv->firstMeeting < $now) { // First meeting already happened.
                $inv->firstMeeting = null; // We don't need to list info from the past.
            }
            if ($inv->lastMeeting !== null && $inv->lastMeeting > $nowPlus1Y) { // Last mtg is > 1yr away
                $inv->lastMeeting = null; // For all practical purposes: it's not ending.
            }
            // Convert start and end dates to strings.
            $format = get_option('date_format');
            if ($inv->firstMeeting !== null && $inv->lastMeeting !== null) {
                if ($dayStr === null) {
                    $dayStr = $inv->firstMeeting->format($format) . " " .
                               __("through") . " " . $inv->lastMeeting->format($format);
                } else {
                    $dayStr .= ", " . $inv->firstMeeting->format($format) . " " .
                               __("through") . " " . $inv->lastMeeting->format($format);
                }
            } elseif ($inv->firstMeeting !== null) {
                if ($dayStr === null) {
                    $dayStr .= __("Starts") . " " . $inv->firstMeeting->format($format);
                } else {
                    $dayStr .= ", " . __("starting") . " " . $inv->firstMeeting->format($format);
                }
            } elseif ($inv->lastMeeting !== null) {
                if ($dayStr === null) {
                    $dayStr = __("Through") . " " . $inv->lastMeeting->format($format);
                } else {
                    $dayStr .= ", " . __("through") . " " . $inv->lastMeeting->format($format);
                }
            }

            if ($verbose) {
                echo "<p>Meeting schedule: $dayStr</p>";
            }
            update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "meetingSchedule", $dayStr);

            // Day of week taxonomy
            $dayTerms = [];
            foreach ($days as $k => $d) {
                $dayTerms[] = Utilities::getDayOfWeekShortForNumber(intval($k[1]));
            }
            wp_set_post_terms($post->ID, $dayTerms, TouchPointWP::TAX_WEEKDAY, false);

            // Time of day taxonomy
            wp_set_post_terms($post->ID, $timeTerms, TouchPointWP::TAX_DAYTIME, false);

            ////////////////////////
            //// END SCHEDULING ////
            ////////////////////////

            // Handle leaders  TODO make leaders WP Users
            if (array_key_exists('leadMemTypes', $qOpts) && property_exists($inv, "leaders")) {
                $nameString = Person::arrangeNamesForPeople($inv->leaders);
                update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "leaders", $nameString);
            }

            // Handle locations for involvement types that have hosts
            if (array_key_exists('hostMemTypes', $qOpts)) {

                // Handle locations TODO handle cases other than hosted at home  (Also applies to ResCode)
                if (property_exists($inv, "hostGeo") && $inv->hostGeo !== null) {
                    update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "geo_lat", $inv->hostGeo->lat);
                    update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "geo_lng", $inv->hostGeo->lng);
                } else {
                    delete_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "geo_lat");
                    delete_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "geo_lng");
                }

                // Handle Resident Code
                if (property_exists($inv, "hostGeo") && $inv->hostGeo !== null && $inv->hostGeo->resCodeName !== null) {
                    wp_set_post_terms($post->ID, [$inv->hostGeo->resCodeName], TouchPointWP::TAX_RESCODE, false);
                } else {
                    wp_set_post_terms($post->ID, [], TouchPointWP::TAX_RESCODE, false);
                }
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

            // Handle divisions
            $divs = [];
            if ($inv->divs !== null) {
                foreach ($inv->divs as $d) {
                    $tid = TouchPointWP::getDivisionTermIdByDivId($d);
                    if ( ! ! $tid) {
                        $divs[] = $tid;
                    }
                }
            }
            wp_set_post_terms($post->ID, $divs, TouchPointWP::TAX_DIV, false);

            if ($verbose) {
                echo "<p>Division Terms:</p>";
                var_dump($divs);
            }

            $postsToKeep[] = $post->ID;

            if ($verbose) {
                echo "<hr />";
            }
        }

        // Delete posts that are no longer current
        $q = new WP_Query(
            [
                'post_type' => $typeSets->postType,
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

        return $removals + count($invData);
    }

    /**
     * @param $theDate
     * @param $format
     * @param $post
     *
     * @return string
     *
     * @noinspection PhpUnusedParameterInspection WordPress API
     */
    public static function filterPublishDate($theDate, $format, $post = null): string
    {
        if ($post == null)
            $post = get_the_ID();

        $invTypes = Involvement_PostTypeSettings::getPostTypes();

        if (in_array(get_post_type($post), $invTypes)) {
            if (!is_numeric($post))
                $post = $post->ID;
            $theDate = get_post_meta($post, TouchPointWP::SETTINGS_PREFIX . "meetingSchedule", true);
        }
        return $theDate;
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

        $canJoin = $this->acceptingNewMembers();
        if ($canJoin !== true) {
            $ret[] = $canJoin;
        }

        return $ret;
    }

    /**
     * Returns the html with buttons for actions the user can perform.
     *
     * @return string
     */
    public function getActionButtons(): string
    {
        TouchPointWP::requireScript('swal2-defer');
        TouchPointWP::requireScript('base-defer');
        $this->enqueueForJsInstantiation();

        $text = __("Contact Leaders", TouchPointWP::TEXT_DOMAIN);
        $ret = "<button type=\"button\" data-tp-action=\"contact\">{$text}</button>  ";

        if ($this->acceptingNewMembers() === true) {
            if ($this->useRegistrationForm()) {
                $text = __('Register', TouchPointWP::TEXT_DOMAIN);
                switch (get_post_meta($this->post_id, TouchPointWP::SETTINGS_PREFIX . "regTypeId", true)) {
                    case 1:  // Join Involvement (skip other options because this option is common)
                        break;
                    case 5:  // Create Account
                        $text = __('Create Account', TouchPointWP::TEXT_DOMAIN);
                        break;
                    case 6:  // Choose Volunteer Times
                    case 22: // Volunteer Scheduler
                        $text = __('Schedule', TouchPointWP::TEXT_DOMAIN);
                        break;
                    case 8:  // Online Giving (legacy)
                    case 9:  // Online Pledge (legacy)
                    case 14: // Manage Recurring Giving (legacy)
                        $text = __('Give', TouchPointWP::TEXT_DOMAIN);
                        break;
                    case 15: // Manage Subscriptions
                        $text = __('Manage Subscriptions', TouchPointWP::TEXT_DOMAIN);
                        break;
                    case 18: // Record Family Attendance
                        $text = __('Record Attendance', TouchPointWP::TEXT_DOMAIN);
                        break;
                    case 21: // Ticketing
                        $text = __('Get Tickets', TouchPointWP::TEXT_DOMAIN);
                        break;
                }
                $link = TouchPointWP::instance()->host() . "/OnlineReg/" . $this->invId;
                $ret  .= "<a class=\"btn button\" href=\"{$link}\">{$text}</a>  ";
            } else {
                $text = __('Join', TouchPointWP::TEXT_DOMAIN);
                $ret  .= "<button type=\"button\" data-tp-action=\"join\">{$text}</button>  ";
            }
        }
        return $ret;
    }

    public static function getJsInstantiationString(): string
    {
        $queue = static::getQueueForJsInstantiation();

        if (count($queue) < 1) {
            return "\t// No Involvements to instantiate.\n";
        }

        $listStr = json_encode($queue);

        return "\ttpvm.addEventListener('Involvement_class_loaded', function() {
        TP_Involvement.fromArray($listStr);\n\t});\n";
    }

    /**
     * Handles the API call to join an involvement through a 'join' button.
     */
    private static function ajaxInvJoin(): void
    {
        header('Content-Type: application/json');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['error' => 'Only POST requests are allowed.']);
            exit;
        }

        $inputData = file_get_contents('php://input');
        if ($inputData[0] !== '{') {
            echo json_encode(['error' => 'Invalid data provided.']);
            exit;
        }

        $data = TouchPointWP::instance()->apiPost('inv_join', json_decode($inputData));

        if ($data instanceof WP_Error) {
            echo json_encode(['error' => $data->get_error_message()]);
            exit;
        }

        echo json_encode(['success' => $data->success]);
        exit;
    }


    /**
     * Handles the API call to send a message through a contact form.
     */
    private static function ajaxInvContact(): void
    {
        $inputData = TouchPointWP::postHeadersAndFiltering();

        $data = TouchPointWP::instance()->apiPost('inv_contact', json_decode($inputData));

        if ($data instanceof WP_Error) {
            echo json_encode(['error' => $data->get_error_message()]);
            exit;
        }

        echo json_encode(['success' => $data->success]);
        exit;
    }
}
