<?php
/**
 * @package TouchPointWP
 */
namespace tp\TouchPointWP;

if ( ! defined('ABSPATH')) {
    exit(1);
}

if (!TOUCHPOINT_COMPOSER_ENABLED) {
    require_once 'api.php';
    require_once "jsInstantiation.php";
    require_once "Utilities.php";
}

use Exception;
use JsonSerializable;
use WP_Error;
use WP_Post;
use WP_Query;
use WP_Term;

/**
 * An Outreach partner, corresponding to a family in TouchPoint
 */
class Partner implements api, JsonSerializable
{
    use jsInstantiation {
        jsInstantiation::enqueueForJsInstantiation as protected enqueueForJsInstantiationTrait;
    }
    use extraValues;

    public const SHORTCODE_MAP = TouchPointWP::SHORTCODE_PREFIX . "Partner-Map";
    public const SHORTCODE_FILTER = TouchPointWP::SHORTCODE_PREFIX . "Partner-Filters";
    public const SHORTCODE_ACTIONS = TouchPointWP::SHORTCODE_PREFIX . "Partner-Actions";
    public const SHORTCODE_LIST = TouchPointWP::SHORTCODE_PREFIX . "Partner-List";
    public const CRON_HOOK = TouchPointWP::HOOK_PREFIX . "global_cron_hook";
    protected static bool $_hasUsedMap = false;
    protected static bool $_hasArchiveMap = false;
    protected static bool $_hasDecoupledInstances = false;
    protected static int $_decoupledAndEnqueuedCount = 0;
    private static array $_instances = [];
    private static bool $_isLoaded = false;

    private static bool $filterJsAdded = false;
    public ?object $geo = null;
    public bool $decoupleLocation;

    protected ?string $location = "";
    protected array $category = [];
    public ?string $color = "#999999";

    public string $name;
    protected int $familyId;

    public int $post_id;
    public string $post_excerpt;
    protected WP_Post $post;

    public const FAMILY_META_KEY = TouchPointWP::SETTINGS_PREFIX . "famId";
    public const IMAGE_META_KEY = TouchPointWP::SETTINGS_PREFIX . "imageUrl";

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
                TouchPointWP::TAX_GP_CATEGORY
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

            // Primary category
            if (TouchPointWP::instance()->settings->global_primary_tax !== "") {
                $this->category = array_filter($terms, fn($t) => $t->taxonomy === TouchPointWP::TAX_GP_CATEGORY);
            }
        }

        // Location string
        if (TouchPointWP::instance()->settings->global_location !== "") {
            $this->location = get_post_meta($this->post_id, TouchPointWP::SETTINGS_PREFIX . "location", true);
        }

        // Geo
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
        if ($this->geo === null || $this->geo->lat === null || $this->geo->lng === null) {
            $this->geo = null;
        } else {
            $this->geo->lat = round($this->geo->lat, 3); // Roughly .2 mi
            $this->geo->lng = round($this->geo->lng, 3);
        }

        // Decouple Location
        $this->decoupleLocation = !!get_post_meta($this->post_id, TouchPointWP::SETTINGS_PREFIX . "geo_decouple", true);
        if ($this->decoupleLocation) {
            self::$_hasDecoupledInstances = true;
        }

        // Color!
        if (count($this->category) > 0) {
            $c = $this->category[0];
            $this->color = Utilities::getColorFor($c->slug, $c->taxonomy);
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
                'show_in_rest' => false, // For the benefit of secure partners
                'supports'     => [
                    'title',
                    'custom-fields',
                    'thumbnail'
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

        // Register function to return nulls instead of authors
        add_filter('the_author', [self::class, 'filterAuthor'], 10, 3);
        add_filter('get_the_author_display_name', [self::class, 'filterAuthor'], 10, 3);

        // Run cron if it hasn't been run before or is overdue.
        if (TouchPointWP::instance()->settings->global_cron_last_run * 1 < time() - 86400 - 3600) {
            try {
                self::updateFromTouchPoint();
            } catch (Exception $ex) {
            }
        }
    }

    /**
     * Run the updating cron task.  Fail quietly to not disturb the visitor experience if using WP default cron handling.
     *
     * @return void
     */
    public static function updateCron(): void
    {
        try {
            self::updateFromTouchPoint();
        } catch (Exception $ex) {
        }
    }


    /**
     * Query TouchPoint and update Partners in WordPress
     *
     * @param bool $verbose Whether to print debugging info.
     *
     * @return false|int False on failure, or the number of partner posts that were updated or deleted.
     * @throws TouchPointWP_Exception
     */
    public static function updateFromTouchPoint(bool $verbose = false)
    {
        set_time_limit(60);

        $verbose &= TouchPointWP::currentUserIsAdmin();

        // Required for image handling
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

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

        $locationEv = TouchPointWP::instance()->settings->global_location;
        if ($locationEv !== "") {
            $fevFields[] = $locationEv;
        }

        $categoryEv = TouchPointWP::instance()->settings->global_primary_tax;
        if ($categoryEv !== "") {
            $fevFields[] = $categoryEv;
        }

        $latEv = TouchPointWP::instance()->settings->global_geo_lat;
        $lngEv = TouchPointWP::instance()->settings->global_geo_lng;
        if ($latEv !== "" && $lngEv !== "") {
            $fevFields[] = $latEv;
            $fevFields[] = $lngEv;
        }

        $q = TouchPointWP::newQueryObject();
        $q['meta']['fev'] = TouchPointWP::instance()->getFamilyEvFields($fevFields);
        $q['meta']['geo'] = true;
        $q['src'] = [TouchPointWP::instance()->settings->global_search];
        $q['groupBy'] = 'FamilyId';
        $q['context'] = 'partner';

        // Submit to API
        $familyData = TouchPointWP::instance()->doPersonQuery($q, $verbose, 50);

        $postsToKeep = [];
        $count = 0;

        foreach ($familyData->people as $f) {
            /** @var object $f */
            set_time_limit(30);

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
                new TouchPointWP_WPError($post);
                continue;
            }

            /** @var $post WP_Post */

            // Apply Types
            $f->familyEV = ExtraValueHandler::jsonToDataTyped($f->familyEV);

            // Post Content
            $post->post_content = self::getFamEvAsContent($descriptionEv, $f, '');

            // Excerpt / Summary
            $post->post_excerpt = self::getFamEvAsContent($summaryEv, $f, null);

            // Partner Category
            $category = $f->familyEV->$categoryEv->value ?? null;
            // Insert Term if new
            if ($category !== null && !Utilities::termExists($category, TouchPointWP::TAX_GP_CATEGORY)) {
                Utilities::insertTerm(
                    $category,
                    TouchPointWP::TAX_GP_CATEGORY,
                    [
                        'description' => $category,
                        'slug'        => sanitize_title($category)
                    ]
                );
                TouchPointWP::queueFlushRewriteRules();
            }
            // Apply term to post
            wp_set_post_terms($post->ID, $category, TouchPointWP::TAX_GP_CATEGORY, false);

            // Title & Slug
            if ($post->post_title != $title) { // only update if there's a change.  Otherwise, urls increment.
                $post->post_title = $title;
                $post->post_name = ''; // Slug will regenerate;
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

            // Decouple location if appropriate.  (Keeps secure partners more secure.)
            $decouple = false;
            foreach ($f->people as $p) {
                if (!!$p->DecoupleLocation) {
                    $decouple = true;
                    break;
                }
            }
            update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "geo_decouple", $decouple);

            // Location EV
            $location = self::getFamEvAsContent($locationEv, $f, null);
            update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "location", $location);
            unset($location);

            // Positioning.
            if ($latEv !== "" && $lngEv !== "" &&   // Has EV Lat/Lng
                property_exists($f->familyEV, $latEv) && property_exists($f->familyEV, $lngEv) &&
                $f->familyEV->$latEv !== null && $f->familyEV->$latEv->value !== null &&
                $f->familyEV->$lngEv !== null && $f->familyEV->$lngEv->value !== null) {
                update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "geo_lat", Utilities::toFloatOrNull($f->familyEV->$latEv->value));
                update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "geo_lng", Utilities::toFloatOrNull($f->familyEV->$lngEv->value));
            } elseif ($f->geo !== null && !$decouple &&   // Use Family Lat/Lng
                is_numeric($f->geo->latitude) && is_numeric($f->geo->longitude) &&
                ! (floatval($f->geo->latitude) === 0.0 && floatval($f->geo->longitude) === 0.0)) {
                update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "geo_lat", floatval($f->geo->latitude));
                update_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "geo_lng", floatval($f->geo->longitude));
            } else {  // Remove lat/lng
                delete_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "geo_lat");
                delete_post_meta($post->ID, TouchPointWP::SETTINGS_PREFIX . "geo_lng");
            }

            // Post image
            $oldUrl = get_post_meta($post->ID, self::IMAGE_META_KEY, true);
            $newUrl = "";
            if ($f->picture !== null) {
                $newUrl = $f->picture->large ?? "";
            }
            $oldAttId = get_post_thumbnail_id($post->ID);
            if ($oldUrl !== $newUrl) {
                if ($oldAttId > 0) {
                    wp_delete_attachment($oldAttId, true);
                    delete_post_thumbnail($post->ID);
                }
                if ($newUrl === "") { // Remove and delete
                    delete_post_meta($post->ID, self::IMAGE_META_KEY);
                } else {
                    $attId = media_sideload_image($newUrl, $post->ID, $title,'id');
                    set_post_thumbnail($post->ID, $attId);
                    update_post_meta($post->ID, self::IMAGE_META_KEY, $newUrl);
                }
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
        if (apply_filters(TouchPointWP::HOOK_PREFIX . 'use_default_templates', true, self::class)) {
            $postTypesToFilter        = self::POST_TYPE;
            $templateFilesToOverwrite = TouchPointWP::TEMPLATES_TO_OVERWRITE;

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
                'btnclass' => 'btn button',
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

        return "<div id=\"$eltId\" class=\"$class\" data-tp-f=\"$prt->familyId\">{$prt->getActionButtons('actions-shortcode', $params['btnclass'])}</div>";
    }

    /**
     * Display a list of the partners, as used in the templates.
     *
     * @param array|string  $params
     * @param string $content
     *
     * @return string
     *
     * @noinspection PhpUnusedParameterInspection
     */
    public static function listShortcode($params = [], string $content = ""): string
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
                'class' => 'partner-list',
                'includecss' => 'true',
                'itemclass' => 'partner-list-item'
            ],
            $params,
            self::SHORTCODE_ACTIONS
        );

        global $the_query, $wp_the_query;
        $the_query = $wp_the_query;

        $the_query->set('posts_per_page', -1);
        $the_query->set('nopaging', true);
        $the_query->set('orderby', 'title');
        $the_query->set('order', 'ASC');

        $the_query->get_posts();
        $the_query->rewind_posts();

        $params['includecss'] = $params['includecss'] === true || $params['includecss'] === 'true';

        if ($params['includecss']) {
            TouchPointWP::enqueuePartialsStyle();
        }

        ob_start();

        while ($the_query->have_posts()) {
            $the_query->the_post();

            $loadedPart = get_template_part('list-item', 'partner-list-item');
            if ($loadedPart === false) {
                require TouchPointWP::$dir . "/src/templates/parts/partner-list-item.php";
            }
        }

        return apply_shortcodes("<div class=\"{$params['class']}\">" . ob_get_clean() . "</div>");
    }

    /**
     * @param array|string $params
     *
     * @return string
     */
    public static function filterShortcode($params = []): string
    {
        // Check that params aren't a string.
        if (is_string($params) && $params !== '') {
            _doing_it_wrong(
                __FUNCTION__,
                "Descriptive parameters are required for the filter shortcode.",
                TouchPointWP::VERSION
            );

            return "<!-- Descriptive parameters are required for the filter shortcode. -->";
        }

        if ($params === '') {
            $params = [];
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
     * @param string|array                        $params
     *
     * @return string
     */
    protected static final function filterDropdownHtml($params = []): string
    {
        if (is_string($params))
            $params = wp_parse_args($params);

        // standardize parameters
        $params = array_change_key_case($params, CASE_LOWER);

        // set some defaults
        $params = shortcode_atts(
            [
                'class'              => "TouchPoint-Partner filterBar",
                'filters'            => strtolower(implode(",", ["partner_category"])),
                'includeMapWarnings' => self::$_hasArchiveMap
            ],
            $params,
            static::SHORTCODE_FILTER
        );

        $filterBarId = $params['id'] ?? wp_unique_id('tp-filter-bar-');

        $filters = explode(',', $params['filters']);

        $class = $params['class'];

        $content = "<div class=\"$class\" id=\"$filterBarId\">";

        $any = __("Any", TouchPointWP::TEXT_DOMAIN);

        // Partner Category
        if (in_array('partner_category', $filters)) {
            $tax = get_taxonomy(TouchPointWP::TAX_GP_CATEGORY);
            $name = substr($tax->name, strlen(TouchPointWP::SETTINGS_PREFIX));
            $content .= "<select class=\"$class-filter\" data-partner-filter=\"$name\">";
            $content .= "<option disabled selected>$tax->label</option>";
            $content .= "<option value=\"\">$any</option>";
            foreach (get_terms(TouchPointWP::TAX_GP_CATEGORY) as $t) {
                $content .= "<option value=\"$t->slug\">$t->name</option>";
            }
            $content .= "</select>";
        }

        if ($params['includeMapWarnings']) {
            $content .= "<p class=\"TouchPointWP-map-warnings\">";
            $content .= sprintf(
                "<span class=\"TouchPointWP-map-warning-visibleOnly\" style=\"display:none;\">%s  </span>",
                sprintf( // i18n: %s is for the user-provided "Global Partner" term
                    __("The %s listed are only those shown on the map.", TouchPointWP::TEXT_DOMAIN),
                    TouchPointWP::instance()->settings->global_name_plural
                )
            );
            $content .= sprintf(
                "<span class=\"TouchPointWP-map-warning-visibleAndInvisible\" style=\"display:none;\">%s  </span>",
                sprintf( // i18n: %s is for the user-provided "Global Partner" and "Secure Partner" terms.
                    __("The %s listed are only those shown on the map, as well as all %s.", TouchPointWP::TEXT_DOMAIN),
                    TouchPointWP::instance()->settings->global_name_plural,
                    TouchPointWP::instance()->settings->global_name_plural_decoupled
                )
            );
            $content .= sprintf(
                "<span class=\"TouchPointWP-map-warning-zoomOrReset\" style=\"display:none;\">%s  </span>",
                sprintf( // i18n: %s is the link to reset the map
                    __("Zoom out or %s to see more.", TouchPointWP::TEXT_DOMAIN),
                    sprintf( // i18n: %s is the link to reset the map
                        "<a href=\"#\" class=\"TouchPointWP-map-resetLink\">%s</a>",
                        __("reset the map", TouchPointWP::TEXT_DOMAIN)
                    )
                )
            );
            $content .= "</p>";
        }

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
                try {
                    echo self::updateFromTouchPoint(true);
                } catch (Exception $ex) {
                    echo "Update Failed: " . $ex->getMessage();
                }
                exit;
        }

        return false;
    }

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

        if ( ! shortcode_exists(self::SHORTCODE_LIST)) {
            add_shortcode(self::SHORTCODE_LIST, [self::class, "listShortcode"]);
        }

        // Setup cron for updating Partners daily.
        add_action(self::CRON_HOOK, [self::class, 'updateCron']);
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
     * Used for sorting secure partners in JSON to decouple locations from entries.
     *
     * @var ?int
     */
    private ?int $rand = null;

    /**
     * Used for sorting secure partners in JSON to decouple locations from entries.
     *
     * @throws Exception
     */
    private function rand(): int
    {
        if ($this->rand === null) {
            $this->rand = random_int(PHP_INT_MIN, PHP_INT_MAX);
        }
        return $this->rand;
    }

    /**
     * Used for sorting secure partners in JSON to decouple locations from entries.
     *
     * @param Partner $a
     * @param Partner $b
     *
     * @return int
     * @throws Exception
     */
    protected static function sortQueueForSecure(Partner $a, Partner $b): int
    {
        $r = ($a->decoupleLocation <=> $b->decoupleLocation);
        if ($r === 0) {
            if ($a->decoupleLocation) {
                return $a->rand() <=> $b->rand();
            } else {
                return self::sort($a, $b);
            }
        }
        return $r;
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
        try {
            $a = self::fromPost($a);
            $b = self::fromPost($b);
            return self::sort($a, $b);
        } catch (TouchPointWP_Exception $ex) {
            return $a <=> $b;
        }
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
     * @param string|array  $params
     * @param string $content
     *
     * @return string
     *
     * @noinspection PhpUnusedParameterInspection WordPress API
     */
    public static function mapShortcode($params = [], string $content = ""): string
    {
        if (is_string($params)) {
            $params = explode(",", $params);
        }

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
                self::$_hasArchiveMap = true;
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
     * Replace the date with information that's relevant
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

        if (get_post_type($post) == self::POST_TYPE) {
            if ($format == '') {
                try {
                    $gp      = self::fromPost($post);
                    $theDate = $gp->notableAttributes();
                    $theDate = implode(TouchPointWP::$joiner, $theDate);
                } catch (TouchPointWP_Exception $e) {
                }
            } else {
                $theDate = "Can't do that format"; // TODO
            }
        }
        return $theDate;
    }


    /**
     * Prevents the author from being shown
     *
     * @param $author
     *
     * @return string
     *
     * @noinspection PhpUnusedParameterInspection WordPress API
     */
    public static function filterAuthor($author): string
    {
        $post = get_the_ID();

        if (get_post_type($post) == self::POST_TYPE) {
            return "";
        }
        return $author;
    }

    /**
     * Format an EV value for use as content.
     *
     * @param string $ev        The name of the extra value
     * @param object $famObj    The family object from the API call
     * @param ?string $default  Value to use if no content is provided in the EV.
     *
     * @return ?string
     */
    public static function getFamEvAsContent(string $ev, object $famObj, ?string $default): ?string
    {
        $newContent = $default;
        if ($ev !== "" && property_exists($famObj->familyEV, $ev) && $famObj->familyEV->$ev !== null && $famObj->familyEV->$ev->value !== null) {
            $newContent = $famObj->familyEV->$ev->value;
            $newContent = strip_tags(
                $newContent,
                ['p', 'br', 'a', 'em', 'strong', 'b', 'i', 'u', 'hr', 'ul', 'ol', 'li']
            );
            $newContent = trim($newContent);
        }

        return $newContent;
    }


    /**
     * Get notable attributes as strings.
     *
     * @return string[]
     */
    public function notableAttributes(): array
    {
        $r = [];

        if ($this->decoupleLocation) {
            $r[] = TouchPointWP::instance()->settings->global_name_singular_decoupled;
        } else if ($this->location !== "" && $this->location !== null) {
            $r[] = $this->location;
        }

        foreach ($this->category as $c) {
            $r[] = $c->name;
        }

        // Not shown on map (only if there is a map, and the partner isn't on it because they lack geo.)
        if (self::$_hasArchiveMap && $this->geo === null && !$this->decoupleLocation) {
            $r[] = __("Not Shown on Map", TouchPointWP::TEXT_DOMAIN);
            TouchPointWP::requireScript("fontAwesome");  // For map icons
        }

        return apply_filters(TouchPointWP::HOOK_PREFIX . "partner_attributes", $r, $this);
    }

    /**
     * Add to a queue for instantiation.
     *
     * @return bool True if added to queue, false if already in queue.
     */
    protected function enqueueForJsInstantiation(): bool
    {
        $newlyEnqueued = $this->enqueueForJsInstantiationTrait();
        if ($this->decoupleLocation && $newlyEnqueued) {
            self::$_decoupledAndEnqueuedCount++;
        }
        return $newlyEnqueued;
    }

    /**
     * Returns the html with buttons for actions the user can perform.  This must be called *within* an element with the
     * `data-tp-partner` attribute with the post_id as the value or 0 for secure partners.
     *
     * @param ?string $context A reference to where the action buttons are meant to be used.
     * @param string  $btnClass A string for classes to add to the buttons.  Note that buttons can be a or button elements.
     *
     * @return string
     */
    public function getActionButtons(string $context = null, string $btnClass = ""): string
    {
        $this->enqueueForJsInstantiation();

        $ret = "";
        if ($btnClass !== "") {
            $btnClass = " class=\"$btnClass\"";
        }

        // Show on map button.  (Only works if map is called before this is.)
        if (self::$_hasArchiveMap && !$this->decoupleLocation && $this->geo !== null) {
            $text = __("Show on Map", TouchPointWP::TEXT_DOMAIN);
            $ret .= "<button type=\"button\" data-tp-action=\"showOnMap\" $btnClass>$text</button>  ";
        }

        return apply_filters(TouchPointWP::HOOK_PREFIX . "partner_actions", $ret, $this, $context, $btnClass);
    }

    public static function getJsInstantiationString(): string
    {
        $queue = static::getQueueForJsInstantiation();

        if (count($queue) < 1) {
            return "\t// No Partners to instantiate.\n";
        }

        // Change order of queue to decouple location for secure partners.
        usort($queue, [self::class, 'sortQueueForSecure']);
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
     * Return the Post ID as needed by the JS classes.  Typically, this will be the post_id, but this can be overridden.
     *
     * @return int
     */
    public function jsId(): int{
        if ($this->decoupleLocation) {
            return 0;
        }
        return $this->post_id;
    }

    /**
     * Get an Extra Value.
     *
     * @param string $name The name of the extra value to get.
     *
     * @return mixed  The value of the extra value.  Returns null if it doesn't exist.
     */
    public function getExtraValue(string $name)
    {
        $name = ExtraValueHandler::standardizeExtraValueName($name);
        $meta = get_post_meta($this->post_id, self::META_FEV_PREFIX . $name, true);
        if ($meta === "") {
            return null;
        }
        return $meta;
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

    /**
     * Serialize.  Mostly, manage the security requirements.
     *
     * @return object
     */
    public function jsonSerialize(): object
    {
        if ($this->decoupleLocation) {
            if (self::$_decoupledAndEnqueuedCount < 2) { // If there's only one partner (e.g. single page), don't provide a location.
                return (object)[
                    'geo'        => null,
                    'name'       => TouchPointWP::instance()->settings->global_name_singular_decoupled,
                    'attributes' => $this->attributes,
                    'color'      => $this->color
                ];
            } else {
                return (object)[
                    'geo'        => $this->geo,
                    'name'       => TouchPointWP::instance()->settings->global_name_singular_decoupled,
                    'attributes' => $this->attributes,
                    'color'      => $this->color
                ];
            }
        }
        return $this;
    }
}
