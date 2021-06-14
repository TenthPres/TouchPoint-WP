<?php

namespace tp\TouchPointWP;

use WP_Post;
use WP_Term;

require_once 'Involvement.php';

/**
 * Courses class file.
 *
 * Class Course
 * @package tp\TouchPointWP
 */


/**
 * The Course system class.
 */
class Course extends Involvement
{
//    public const SHORTCODE_MAP = TouchPointWP::SHORTCODE_PREFIX . "SgMap";  TODO change or remove
//    public const SHORTCODE_FILTER = TouchPointWP::SHORTCODE_PREFIX . "SgFilters";
    public const POST_TYPE = TouchPointWP::HOOK_PREFIX . "course";
    protected static TouchPointWP $tpwp;
    private static array $_instances = [];
    private static bool $_isInitiated = false;

    /**
     * Course constructor.
     *
     * @param WP_Post|object $object
     */
    protected function __construct($object)
    {
        parent::__construct($object);

        $terms = wp_get_post_terms(
            $this->post_id,
            [
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

        $joinable = $this->acceptingNewMembers();
        if ($joinable !== true) {
            $ret[] = $joinable;
        }

        return $ret;
    }

    public static function load(TouchPointWP $tpwp): bool
    {
        if (self::$_isInitiated) {
            return true;
        }

        self::$tpwp = $tpwp;

        self::$_isInitiated = true;

        add_action('init', [self::class, 'init']);

//        if ( ! shortcode_exists(self::SHORTCODE_MAP)) {  TODO change or remove
//            add_shortcode(self::SHORTCODE_MAP, [self::class, "mapShortcode"]);
//        }
//
//        if ( ! shortcode_exists(self::SHORTCODE_FILTER)) {
//            add_shortcode(self::SHORTCODE_FILTER, [self::class, "filterShortcode"]);
//        }

        return true;
    }

    /**
     * Register stuff
     */
    public static function init(): void
    {
//        self::registerAjax(); TODO remove

        register_post_type(
            self::POST_TYPE,
            [
                'labels'           => [
                    'name'          => self::$tpwp->settings->cs_name_plural,
                    'singular_name' => self::$tpwp->settings->cs_name_singular
                ],
                'public'           => true,
                'hierarchical'     => true,
                'show_ui'          => false,
                'show_in_rest'     => true,
                'supports'         => [
                    'title',
                    'custom-fields'
                ],
                'has_archive'      => true,
                'rewrite'          => [
                    'slug'       => self::$tpwp->settings->cs_slug,
                    'with_front' => false,
                    'feeds'      => false,
                    'pages'      => true
                ],
                'query_var'        => self::$tpwp->settings->cs_slug,
                'can_export'       => false,
                'delete_with_user' => false
            ]
        );

        self::$tpwp->registerTaxonomies(); // TODO probably needs to be moved to parent, but order matters.

        // If the slug has changed, update it.  Only executes if already enqueued.
        self::$tpwp->flushRewriteRules();

        // Register default templates for Courses
        add_filter('template_include', [self::class, 'templateFilter']);

        // Register function to return schedule instead of publishing date
        add_filter( 'get_the_date', [self::class, 'filterPublishDate'], 10, 3 ); // TODO consolidate with SG into involvement
        add_filter( 'get_the_time', [self::class, 'filterPublishDate'], 10, 3 );

        // Run cron if it hasn't been run before or is overdue. TODO rework cron.
    }

    /**
     * Query TouchPoint and update Courses in WordPress
     *
     * @return false|int False on failure, or the number of courses that were updated or deleted.
     */
    public static function updateCoursesFromTouchPoint()
    {
        if (count(self::$tpwp->settings->cs_divisions) < 1) {
            // Don't update if there aren't any divisions selected yet.
            return false;
        }

        // Divisions
        $divs = implode(',', self::$tpwp->settings->cs_divisions);
        $divs = str_replace('div', '', $divs);

        // Leader member types
        $lMTypes = implode(',', self::$tpwp->settings->cs_leader_types);
        $lMTypes = str_replace('mt', '', $lMTypes);

        return parent::updateInvolvementPosts(self::POST_TYPE, $divs, ['leadMemTypes' => $lMTypes]);
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
                TouchPointWP::$dir . '/src/templates/course-archive.php'
            )) {
            $template = TouchPointWP::$dir . '/src/templates/course-archive.php';
        }

        if (is_singular($postTypesToFilter) && file_exists(
                TouchPointWP::$dir . '/src/templates/course-single.php'
            )) {
            $template = TouchPointWP::$dir . '/src/templates/course-single.php';
        }

        return $template;
    }

    /**
     * @param $theDate
     * @param $format
     * @param $post
     *
     * @return string
     *
     * @noinspection PhpUnusedParameterInspection Not used by choice, but need to comply with the api.
     */
    public static function filterPublishDate($theDate, $format, $post = null): string // TODO combine with SG in Involvement
    {
        if ($post == null)
            $post = get_the_ID();

        if (get_post_type($post) === self::POST_TYPE) {
            if (!is_numeric($post))
                $post = $post->ID;
            $theDate = get_post_meta($post, TouchPointWP::SETTINGS_PREFIX . "meetingSchedule", true);
        }
        return $theDate;
    }

    public static function registerScriptsAndStyles(): void
    {
        wp_register_script( // TODO combine in Involvement
            TouchPointWP::SHORTCODE_PREFIX . "knockout",
            "https://ajax.aspnetcdn.com/ajax/knockout/knockout-3.5.0.js",
            [],
            '3.5.0',
            true
        );

        wp_register_script(
            TouchPointWP::SHORTCODE_PREFIX . "courses-defer",
            self::$tpwp->assets_url . 'js/courses-defer.js',
            [TouchPointWP::SHORTCODE_PREFIX . "base-defer"],
            TouchPointWP::VERSION,
            true
        );
    }

    /**
     * Create a Course object from an object from a database query.  TODO is this needed?
     *
     * @param object $obj A database object from which a Course object should be created.
     *
     * @return Course
     */
    private static function fromObj(object $obj): Course
    {
        $iid = intval($obj->invId);

        if ( ! isset(self::$_instances[$iid])) {
            self::$_instances[$iid] = new Course($obj);
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
                'class' => 'TouchPoint-course filterBar',
                'filters' => strtolower(implode(",", self::$tpwp->settings->get('cs_filter_defaults')))
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
            $exclude = TouchPointWP::instance()->settings->cs_divisions;
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
                        'key' => TouchPointWP::SETTINGS_PREFIX . 'divId', // Allow programs
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
                $content .= "<select class=\"course-filter\" data-course-filter=\"div\">";
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
            $content .= "<select class=\"course-filter\" data-course-filter=\"genderId\">";
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
                $content .= "<select class=\"course-filter\" data-course-filter=\"weekday\">";
                $content .= "<option disabled selected>{$wdName}</option><option value=\"\">{$any}</option>";
                foreach ($wdList as $d) {
                    $content .= "<option value=\"{$d->slug}\">{$d->name}</option>";
                }
                $content .= "</select>";
            }
        }

        // TODO Time of Day (services?)

        // Marital Status
        if (in_array('inv_marital', $filters)) {
            $content .= "<select class=\"course-filter\" data-course-filter=\"inv_marital\">";
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
                $content .= "<select class=\"course-filter\" data-course-filter=\"agegroup\">";
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
            TouchPointWP::SHORTCODE_PREFIX . 'courses-template-style',
            self::$tpwp->assets_url . 'template/courses-template-style.css',
            [],
            TouchPointWP::VERSION
        );
    }


    /**
     * @param string[] $exclude
     *
     * @return string[]
     */
    public function getDivisionsStrings($exclude = null): array
    {
        if ($exclude === null) {
            $exclude = self::$tpwp->settings->cs_divisions;
        }
        return parent::getDivisionsStrings($exclude);
    }

    /**
     * Create a Courses object from an object from a WP_Post object.
     *
     * @param WP_Post $post
     *
     * @return Course
     */
    public static function fromPost(WP_Post $post): Course
    {
        $iid = intval($post->{self::INVOLVEMENT_META_KEY});

        if ( ! isset(self::$_instances[$iid])) {
            self::$_instances[$iid] = new Course($post);
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
}