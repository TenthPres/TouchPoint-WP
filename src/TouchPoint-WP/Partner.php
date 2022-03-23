<?php
namespace tp\TouchPointWP;

if ( ! defined('ABSPATH')) {
    exit(1);
}

require_once 'api.iface.php';
require_once "jsInstantiation.php";
require_once "Utilities.php";

use WP_Error;
use WP_Post;
use WP_Query;
use WP_Term;

/**
 * Class Partner - Fundamental object meant to correspond to an outreach partner unit
 *
 * @package tp\TouchPointWP
 */
class Partner implements api
{
    use jsInstantiation;
    use extraValues;

    public const SHORTCODE_MAP = TouchPointWP::SHORTCODE_PREFIX . "Partner-Map";
    public const SHORTCODE_FILTER = TouchPointWP::SHORTCODE_PREFIX . "Partner-Filters";
    public const SHORTCODE_ACTIONS = TouchPointWP::SHORTCODE_PREFIX . "Partner-Actions";
    public const CRON_HOOK = TouchPointWP::HOOK_PREFIX . "global_cron_hook";
    protected static bool $_hasUsedMap = false;
    private static array $_instances = [];
    private static bool $_isLoaded = false;

    private static bool $filterJsAdded = false;
    public ?object $geo = null;

    public string $name;
    protected int $familyId;

    public int $post_id;
    public string $post_excerpt;
    protected WP_Post $post;

    public const FAMILY_META_KEY = TouchPointWP::SETTINGS_PREFIX . "famId";

    public const POST_TYPE = TouchPointWP::HOOK_PREFIX . "partner";

    public const META_FEV_PREFIX = TouchPointWP::SETTINGS_PREFIX . "fev_";

    public object $attributes;

    /**
     * Partner constructor.
     *
     * @param $object WP_Post|object an object representing the partner's post.
     *                  Must have post_id AND family id attributes.
     *
     * @throws TouchPointWP_Exception
     */
    protected function __construct(object $object)
    {
        $this->attributes = (object)[];

        if (gettype($object) === "object" && get_class($object) == WP_Post::class) {
            // WP_Post Object
            $this->post = $object;
            $this->name = $object->post_title;
            $this->familyId = intval($object->{self::FAMILY_META_KEY});
            $this->post_id = $object->ID;

            if ($this->familyId === 0) {
                throw new TouchPointWP_Exception("No Family ID provided in the post.");
            }

        } elseif (gettype($object) === "object") {
            // Sql Object, probably.

            if (! property_exists($object, 'post_id')) {
                _doing_it_wrong(
                    __FUNCTION__,
                    esc_html(
                        __('Creating a Partner object from an object without a post_id is not yet supported.')
                    ),
                    esc_attr(TouchPointWP::VERSION)
                );
            }

            $this->post = get_post($object, "OBJECT");
            $this->post_id = $this->post->ID;

            foreach ($object as $property => $value) {
                if (property_exists(self::class, $property)) {
                    $this->$property = $value;
                }
            }
        } else {
            throw new TouchPointWP_Exception("Could not construct a Partner with the information provided.");
        }

        $terms = wp_get_post_terms(
            $this->post_id,
            [
                // TODO set taxonomies
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
        if ($this->geo->lat === null || $this->geo->lng === null) {
            $this->geo = null;
        } else {
            $this->geo->lat = round($this->geo->lat, 4);
            $this->geo->lng = round($this->geo->lng, 4);
        }

        $this->registerConstruction();
    }


    /**
     * Register stuff
     */
    public static function init(): void
    {
        register_post_type(
            self::POST_TYPE,
            [
                'labels'       => [
                    'name'          => TouchPointWP::instance()->settings->global_name_plural,
                    'singular_name' => TouchPointWP::instance()->settings->global_name_singular
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
                    'slug'       => TouchPointWP::instance()->settings->global_slug,
                    'with_front' => false,
                    'feeds'      => false,
                    'pages'      => true
                ],
                'query_var'        => TouchPointWP::instance()->settings->global_slug,
                'can_export'       => false,
                'delete_with_user' => false
            ]
        );

        // Register default templates for Involvements
        add_filter('template_include', [self::class, 'templateFilter']);

        // Register function to return content instead of publishing date
        add_filter('get_the_date', [self::class, 'filterPublishDate'], 10, 3);
        add_filter('get_the_time', [self::class, 'filterPublishDate'], 10, 3);

        // Run cron if it hasn't been run before or is overdue.
        if (TouchPointWP::instance()->settings->global_cron_last_run * 1 < time() - 86400 - 3600) {
            self::updateFromTouchPoint();
        }
    }


    /**
     * Query TouchPoint and update Partners in WordPress
     *
     * @param bool $verbose Whether to print debugging info.
     *
     * @return false|int False on failure, or the number of partner posts that were updated or deleted.
     */
    public static function updateFromTouchPoint(bool $verbose = false)
    {
        set_time_limit(60);

        $customFev = TouchPointWP::instance()->settings->global_fev_custom;
        $fevFields = $customFev;

        $descriptionEv = TouchPointWP::instance()->settings->global_description;
        if ($descriptionEv !== "") {
            $fevFields[] = $descriptionEv;
        }

        $summaryEv = TouchPointWP::instance()->settings->global_summary;
        if ($summaryEv !== "") {
            $fevFields[] = $summaryEv;
        }

        $latEv = TouchPointWP::instance()->settings->global_geo_lat;
        $lngEv = TouchPointWP::instance()->settings->global_geo_lng;
        if ($latEv !== "" && $lngEv !== "") {
            $fevFields[] = $latEv;
            $fevFields[] = $lngEv;
        }

        $q = TouchPointWP::newQueryObject();
        $q['meta']['fev'] = TouchPointWP::instance()->getFamilyEvFields($fevFields);
        $q['src'] = [TouchPointWP::instance()->settings->global_search];
        $q['groupBy'] = 'FamilyId';
        $q['context'] = 'partner';

        // Submit to API
        $familyData = TouchPointWP::instance()->doPersonQuery($q, $verbose, 50);

        if ($familyData instanceof WP_Error || $familyData == false) {
            return false;
        }

        $postsToKeep = [];
        $count = 0;

        foreach ($familyData->people as $f) {
            /** @var object $f */
            set_time_limit(15);

            if ($verbose) {
                var_dump($f);
            }

            $title = Person::arrangeNamesForPeople($f->people);

            if ($title === null) { // If, somehow, every person is excluded.
                continue;
            }

            $q = new WP_Query(
                [
                    'post_type'  => self::POST_TYPE,
                    'meta_key'   => self::FAMILY_META_KEY,
                    'meta_value' => $f->familyId
                ]
            );
            $post = $q->get_posts();
            if (count($post) > 0) { // post exists already.
                $post = $post[0];
            } else {
                $post = wp_insert_post(
                    [ // create new
                        'post_type'  => self::POST_TYPE,
                        'post_name'  => $title,
                        'meta_input' => [
                            self::FAMILY_META_KEY => $f->familyId
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

            // Apply Types
            $f->familyEV = ExtraValueHandler::jsonToDataTyped($f->familyEV);

            // Post Content
            $newContent = '';
            if ($descriptionEv !== "" && $f->familyEV->$descriptionEv !== null && $f->familyEV->$descriptionEv->value !== null) {
                $newContent = $f->familyEV->$descriptionEv->value;
                $newContent = strip_tags($newContent, ['p', 'br', 'a', 'em', 'strong', 'b', 'i', 'u', 'hr', 'ul', 'ol', 'li']);
                $newContent = trim($newContent);
            }
            $post->post_content = $newContent;

            // Excerpt / Summary
            $newContent = null;
            if ($summaryEv !== "" && $f->familyEV->$summaryEv !== null && $f->familyEV->$summaryEv->value !== null) {
                $newContent = $f->familyEV->$summaryEv->value;
                $newContent = strip_tags($newContent, ['p', 'br', 'a', 'em', 'strong', 'b', 'i', 'u', 'hr', 'ul', 'ol', 'li']);
                $newContent = trim($newContent);
            }
            $post->post_excerpt = $newContent;

            // Title
            if ($post->post_title != $title) // only update if there's a change.  Otherwise, urls increment.
            {
                $post->post_title = $title;
            }

            // Status & Submit
            $post->post_status = 'publish';
            wp_update_post($post);

            try {
                $partner = self::fromPost($post);
            } catch (TouchPointWP_Exception $e) {
                if ($verbose) {
                    var_dump($e->getMessage());
                    echo "<hr />";
                }
                continue;
            }

            // Apply Custom EVs
            $fields = TouchPointWP::instance()->getFamilyEvFields(TouchPointWP::instance()->settings->global_fev_custom);
            foreach ($fields as $fld) {
                if (isset($f->familyEV->{$fld->hash})) {
                    $partner->setExtraValueWP($fld->field, $f->familyEV->{$fld->hash}->value);
                } else {
                    $partner->removeExtraValueWP($fld->field);
                }
            }
            unset($fields, $fld);

            // Positioning.  Ignores family addresses.
            if ($latEv !== "" && $lngEv !== "" &&
                $f->familyEV->$latEv !== null && $f->familyEV->$latEv->value !== null &&
                $f->familyEV->$lngEv !== null && $f->familyEV->$lngEv->value !== null) {
                update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "geo_lat", Utilities::toFloatOrNull($f->familyEV->$latEv->value));
                update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "geo_lng", Utilities::toFloatOrNull($f->familyEV->$lngEv->value));
            } else {
                delete_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "geo_lat");
                delete_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "geo_lng");
            }

            $postsToKeep[] = $post->ID;

            if ($verbose) {
                echo "<hr />";
            }

            $count++;
        }

        // Delete posts that are no longer current
        $q = new WP_Query(
            [
                'post_type' => self::POST_TYPE,
                'nopaging'  => true,
            ]
        );

        foreach ($q->get_posts() as $post) {
            if ( ! in_array($post->ID, $postsToKeep)) {
                set_time_limit(10);
                wp_delete_post($post->ID, true);
                $count++;
            }
        }

        if ($count !== 0) {
            TouchPointWP::instance()->settings->set('global_cron_last_run', time());
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
        $postTypesToFilter        = self::POST_TYPE;
        $templateFilesToOverwrite = ['archive.php', 'singular.php', 'single.php', 'index.php'];

        if ( ! in_array(ltrim(strrchr($template, '/'), '/'), $templateFilesToOverwrite)) {
            return $template;
        }
        
        if (is_post_type_archive($postTypesToFilter) && file_exists(
                TouchPointWP::$dir . '/src/templates/partner-archive.php'
            )) {
            $template = TouchPointWP::$dir . '/src/templates/partner-archive.php';
        }

        if (is_singular($postTypesToFilter) && file_exists(
                TouchPointWP::$dir . '/src/templates/partner-single.php'
            )) {
            $template = TouchPointWP::$dir . '/src/templates/partner-single.php';
        }

        return $template;
    }


    /**
     * Display action buttons for a partner.  Takes an id parameter for the family ID.  If not provided,
     * the current post will be used.
     *
     * @param array|string  $params
     * @param string $content
     *
     * @return string
     */
    public static function actionsShortcode($params = [], string $content = ""): string
    {
        // standardize parameters
        if (is_string($params)) {
            $params = explode(",", $params);
        }
        $params = array_change_key_case($params, CASE_LOWER);

        // set some defaults
        /** @noinspection SpellCheckingInspection */
        $params = shortcode_atts(
            [
                'class' => 'TouchPoint-partner actions',
                'famid' => null,
                'id'    => wp_unique_id('tp-actions-')
            ],
            $params,
            self::SHORTCODE_ACTIONS
        );

        /** @noinspection SpellCheckingInspection */
        $fid = $params['famid'];

        // If there's no famId, try to get one from the Post
        if ($fid === null) {
            $post = get_post();

            if (is_object($post)) {
                try {
                    $prt = self::fromPost($post);
                    $fid = $prt->familyId;
                } catch (TouchPointWP_Exception $e) {
                    $fid = null;
                }
            }
        }

        // If there is no invId at this point, this is an error.
        if ($fid === null) {
            return "<!-- Error: Can't create Partner Actions because there is no clear Partner.  Define the FId and make sure it's imported. -->";
        }

        try {
            $prt = self::fromFamId($fid);
        } catch(TouchPointWP_Exception $e) {
            return "<!-- Error: " . $e->getMessage() . " -->";
        }

        if ($prt === null) {
            return "<!-- Error: Partner isn't instantiated. -->";
        }

        $eltId = $params['id'];
        $class = $params['class'];

        return "<div id=\"$eltId\" class=\"$class\" data-tp-f=\"$prt->familyId\">{$prt->getActionButtons()}</div>";
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

        self::requireAllObjectsInJs();

        if ( ! self::$filterJsAdded) {
            wp_add_inline_script(
                TouchPointWP::SHORTCODE_PREFIX . 'base-defer',
                "
                tpvm.addEventListener('Partner_fromObjArray', function() {
                    TP_Partner.initFilters();
                });"
            );
            self::$filterJsAdded = true;
        }

        return self::filterDropdownHtml($params);
    }


    /**
     * @param array                        $params
     *
     * @return string
     */
    protected static final function filterDropdownHtml(array $params): string
    {
        // standardize parameters
        $params = array_change_key_case($params, CASE_LOWER);

        // set some defaults
        $params = shortcode_atts(
            [
                'class'   => "TouchPoint-Partner filterBar",
//                'filters' => strtolower(implode(",", $settings->filters)) // TODO
            ],
            $params,
            static::SHORTCODE_FILTER
        );

        $filterBarId = $params['id'] ?? wp_unique_id('tp-filter-bar-');

        $filters = explode(',', $params['filters']);

        $class = $params['class'];

        $content = "<div class=\"$class\" id=\"$filterBarId\">";

        $any = __("Any", TouchPointWP::TEXT_DOMAIN);

        $content .= "</div>";

        return $content;
    }

    /**
     * Create a Partner object from an object from a WP_Post object.
     *
     * @param WP_Post $post
     *
     * @return Partner
     *
     * @throws TouchPointWP_Exception If the partner can't be created from the post, an exception is thrown.
     */
    public static function fromPost(WP_Post $post): Partner
    {
        $fid = intval($post->{self::FAMILY_META_KEY});

        if ( ! isset(self::$_instances[$fid])) {
            self::$_instances[$fid] = new Partner($post);
        }

        return self::$_instances[$fid];
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
            case "force-sync":
                TouchPointWP::doCacheHeaders(TouchPointWP::CACHE_NONE);
                echo self::updateFromTouchPoint(true);
                exit;
        }

        return false;
    }


//    /**
//     * Create a Partner object from an object from a database query.
//     *
//     * @param object $obj A database object from which a Partner object should be created.
//     *
//     * @deprecated TODO remove if not used.
//     *
//     * @return Partner
//     * @throws TouchPointWP_Exception
//     */
//    private static function fromObj(object $obj): Partner
//    {
//        $fid = intval($obj->familyId);
//
//        if ( ! isset(self::$_instances[$fid])) {
//            self::$_instances[$fid] = new Partner($obj);
//        }
//
//        return self::$_instances[$fid];
//    }

    /**
     * Create a Partner object from a Family ID.  Only Partners that are already imported as Posts are
     * currently available.
     *
     * @param int $fid The family Id
     *
     * @return ?Partner  Null if the partner is not imported/available.
     * @throws TouchPointWP_Exception
     */
    private static function fromFamId(int $fid): ?Partner
    {
        if ( ! isset(self::$_instances[$fid])) {
            $q = new WP_Query(
                [
                    'post_type' => self::POST_TYPE,
                    'meta_key'   => self::FAMILY_META_KEY,
                    'meta_value' => (string)$fid
                ]
            );
            $post = $q->get_posts();
            if (count($post) > 0) { // post exists.
                $post = $post[0];
            } else {
                return null;
            }
            self::$_instances[$fid] = new Partner($post);
        }

        return self::$_instances[$fid];
    }


    public static function load(): bool
    {
        if (self::$_isLoaded) {
            return true;
        }

        self::$_isLoaded = true;

        add_action('init', [self::class, 'init']);

        if ( ! shortcode_exists(self::SHORTCODE_MAP)) {
            add_shortcode(self::SHORTCODE_MAP, [self::class, "mapShortcode"]);
        }

        if ( ! shortcode_exists(self::SHORTCODE_FILTER)) {
            add_shortcode(self::SHORTCODE_FILTER, [self::class, "filterShortcode"]);
        }

        if ( ! shortcode_exists(self::SHORTCODE_ACTIONS)) {
            add_shortcode(self::SHORTCODE_ACTIONS, [self::class, "actionsShortcode"]);
        }

        // Setup cron for updating Partners daily.
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
     * Put Partner objects in order.
     *
     * @param Partner $a
     * @param Partner $b
     *
     * @return int
     */
    public static function sort(Partner $a, Partner $b): int
    {
        return strcmp($a->post->post_title, $b->post->post_title);
    }


    /**
     * Put Post objects that represent Partners in order of increasing distance.
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
            TouchPointWP::SHORTCODE_PREFIX . 'partner-defer',
            TouchPointWP::instance()->assets_url . 'js/partner-defer.js',
            [TouchPointWP::SHORTCODE_PREFIX . 'base-defer'],
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
     * @noinspection PhpUnusedParameterInspection WordPress API
     */
    public static function mapShortcode(array $params = [], string $content = ""): string
    {
        if ( ! self::$_hasUsedMap) {
            self::$_hasUsedMap = true;

            // standardize parameters
            $params = array_change_key_case($params, CASE_LOWER);

            TouchPointWP::requireScript("googleMaps");
            TouchPointWP::requireScript("partner-defer");

            // set some defaults
            $params = shortcode_atts(
                [
                    'class' => 'TouchPoint-partners map',
                    'all'   => null
                ],
                $params,
                self::SHORTCODE_MAP
            );

            $mapDivId = $params['id'] ?? wp_unique_id('tp-map-');

            if ($params['all'] === null) {
                $params['all'] = is_archive();
            }

            if ($params['all']) {
                self::requireAllObjectsInJs();
            }

            $script = file_get_contents(TouchPointWP::$dir . "/src/js-partials/partner-map-inline.js");

            $script = str_replace('{$mapDivId}', $mapDivId, $script);

            wp_add_inline_script(
                TouchPointWP::SHORTCODE_PREFIX . "googleMaps",
                $script
            );

            // TODO move the style to a css file... or something.
            $content = "<div class=\"TouchPoint-Partner-Map\" style=\"height: 100%; width: 100%; position: absolute; top: 0; left: 0; \" id=\"$mapDivId\"></div>";
        } else {
            $content = "<!-- Error: Partner map can only be used once per page. -->";
        }

        return $content;
    }


    /**
     * Prevents the date from being shown.
     *
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
            $theDate = "";
        }
        return $theDate;
    }


    /**
     * Get notable attributes as strings.
     *
     * @return string[]
     */
    public function notableAttributes(): array
    {
        // TODO add hook
        return [];
    }

    /**
     * Returns the html with buttons for actions the user can perform.  This must be called *within* an element with the
     * `data-tp-involvement` attribute with the invId as the value.
     *
     * @return string
     */
    public function getActionButtons(): string
    {

        // TODO at the least, add a hook.

        return "";
    }

    public static function getJsInstantiationString(): string
    {
        $queue = static::getQueueForJsInstantiation();

        if (count($queue) < 1) {
            return "\t// No Partners to instantiate.\n";
        }

        $listStr = json_encode($queue);

        return "\ttpvm.addEventListener('Partner_class_loaded', function() {
        TP_Partner.fromObjArray($listStr);\n\t});\n";
    }

    /**
     * Gets a TouchPoint item ID number, regardless of what type of object this is.
     *
     * @return int
     */
    public function getTouchPointId(): int
    {
        return $this->familyId;
    }

    /**
     * Get an Extra Value.
     *
     * @param string $name The name of the extra value to get.
     *
     * @return mixed.  The value of the extra value.  Returns null if it doesn't exist.
     */
    public function getExtraValue(string $name)
    {
        $name = ExtraValueHandler::standardizeExtraValueName($name);
        return get_post_meta($this->post_id, self::META_FEV_PREFIX . $name);
    }

    /**
     * Set an extra value in WordPress.  Value should already be converted to appropriate datatype (e.g. DateTime)
     *
     * DOES NOT SET THE EXTRA VALUE IN TouchPoint, AND IS ONLY MEANT TO BE USED AS PART OF A SYNC PROCEDURE.
     *
     * @param string $name The name of the extra value to set.
     * @param mixed $value The value to set.
     *
     * @return bool|int Meta ID if the key didn't exist, true on successful update,
     *                  false on failure or if the value passed to the function
     *                  is the same as the one that is already in the database.
     */
    protected function setExtraValueWP(string $name, $value)
    {
        $name = ExtraValueHandler::standardizeExtraValueName($name);
        return update_post_meta($this->post_id, self::META_FEV_PREFIX . $name, $value);
    }

    /**
     * Remove an extra value in WordPress.
     *
     * DOES NOT REMOVE IT IN TouchPoint, AND IS ONLY MEANT TO BE USED AS PART OF A SYNC PROCEDURE.
     *
     * @param string $name The name of the extra value to remove.
     *
     * @return bool True on Success, False on failure.
     */
    protected function removeExtraValueWP(string $name): bool
    {
        $name = ExtraValueHandler::standardizeExtraValueName($name);
        return delete_post_meta($this->post_id, self::META_FEV_PREFIX . $name);
    }
}
