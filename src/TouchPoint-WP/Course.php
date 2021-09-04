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
    public const SHORTCODE_FILTER = TouchPointWP::SHORTCODE_PREFIX . "CsFilters";
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

    public static function load(TouchPointWP $tpwp): bool
    {
        if (self::$_isInitiated) {
            return true;
        }

        self::$tpwp = $tpwp;

        self::$_isInitiated = true;

        add_action('init', [self::class, 'init']);

        if ( ! shortcode_exists(self::SHORTCODE_FILTER)) {
            add_shortcode(self::SHORTCODE_FILTER, [self::class, "filterShortcode"]);
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
                    'name'          => self::$tpwp->settings->cs_name_plural,
                    'singular_name' => self::$tpwp->settings->cs_name_singular
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

        // Register default templates for Courses
        add_filter('template_include', [self::class, 'templateFilter']);

        // Register function to return schedule instead of publishing date
        add_filter( 'get_the_date', [self::class, 'filterPublishDate'], 10, 3 ); // TODO consolidate with SG into involvement
        add_filter( 'get_the_time', [self::class, 'filterPublishDate'], 10, 3 );

        // Run cron if it hasn't been run before or is overdue. TODO rework cron.
    }

    /**
     * Query TouchPoint and update Involvements in WordPress.  This function should generally call updateInvolvementPosts
     *
     * @param bool $verbose Whether to print debugging info.
     *
     * @return false|int False on failure, or the number of groups that were updated or deleted.
     */
    public static function updateFromTouchPoint(bool $verbose = false)
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

        return parent::updateInvolvementPosts(self::POST_TYPE, $divs, ['leadMemTypes' => $lMTypes], $verbose);
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


    private static bool $filterJsAdded = false;
    /**
     * @param array|string $params
     *
     * @return string
     */
    public static function filterShortcode($params): string
    {
        if (is_string($params)) {
            _doing_it_wrong(
                __FUNCTION__,
                "Descriptive parameters are required for the filter shortcode.",
                TouchPointWP::VERSION
            );

            return "<!-- Descriptive parameters are required for the filter shortcode. -->";
        }

        self::requireAllObjectsInJs();

        if (!self::$filterJsAdded) {
            wp_add_inline_script(
                TouchPointWP::SHORTCODE_PREFIX . 'base-defer',
                "
                tpvm.addEventListener('Involvement_fromArray', function() {
                    TP_Involvement.initFilters('course');
                });"
            );
            self::$filterJsAdded = true;
        }

        return parent::filterDropdownHtml(
            $params,
            'course',
            self::$tpwp->settings->get('cs_filter_defaults'),
            self::$tpwp->settings->cs_divisions
        );
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
     * Whether the involvement is currently able to be joined.
     *
     * @return bool|string  True if can be joined.  Or, a string with why it can't be joined otherwise.
     */
    public function acceptingNewMembers()
    {
        // TODO add extra value options
        return parent::acceptingNewMembers();
    }

    /**
     * @param $uri
     *
     * @return bool True on success (valid api endpoint), false on failure.
     */
    public static function api($uri): bool
    {
        if (count($uri['path']) < 3) {
            return false;
        }

        if ($uri['path'][2] === "force-sync") {
            TouchPointWP::doCacheHeaders(TouchPointWP::CACHE_NONE);
            echo self::updateFromTouchPoint(true);
            exit;
        }

        return parent::api($uri);
    }
}