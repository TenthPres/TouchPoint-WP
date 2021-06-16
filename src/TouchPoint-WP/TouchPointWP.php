<?php

namespace tp\TouchPointWP;

use WP;
use WP_Error;
use WP_Http;
use WP_Term;

if ( ! defined('ABSPATH')) {
    exit;
}


/**
 * Main plugin class.
 *
 * Class TouchPointWP
 * @package tp\TouchPointWP
 */
class TouchPointWP
{

    /**
     * Version number
     */
    public const VERSION = "0.0.3";

    /**
     * The Token
     */
    public const TOKEN = "TouchPointWP";

    /**
     * Text domain for translation files
     */
    public const TEXT_DOMAIN = "TouchPoint-WP";

    /**
     * API Endpoint prefix, and specific endpoints.  All must be lower-case.
     */
    public const API_ENDPOINT = "touchpoint-api";
    public const API_ENDPOINT_GENERATE_SCRIPTS = "generate-scripts";
    public const API_ENDPOINT_APP_EVENTS = "app-events";

    /**
     * Prefix to use for all shortcodes.
     */
    public const SHORTCODE_PREFIX = "TP-";

    /**
     * Prefix to use for all filters and hooks.
     */
    public const HOOK_PREFIX = "tp_";

    /**
     * Prefix to use for all settings.
     */
    public const SETTINGS_PREFIX = "tp_";

    public const TAX_RESCODE = self::HOOK_PREFIX . "rescode";
    public const TAX_DIV = self::HOOK_PREFIX . "div";
    public const TAX_WEEKDAY = self::HOOK_PREFIX . "weekday";
    public const TAX_AGEGROUP = self::HOOK_PREFIX . "agegroup";
    public const TAX_INV_MARITAL = self::HOOK_PREFIX . "inv_marital";

    /**
     * Table Names
     */
    public const TABLE_PREFIX = "tp_";
    public const TABLE_IP_GEO = self::TABLE_PREFIX . "ipGeo";

    /**
     * The singleton.
     */
    private static ?TouchPointWP $_instance = null;

    /**
     * The admin object.
     */
    protected ?TouchPointWP_AdminAPI $admin = null;

    /**
     * Settings object
     */
    public ?TouchPointWP_Settings $settings = null;

    /**
     * The main plugin file.
     */
    public string $file;

    /**
     * The main plugin directory.
     */
    public static string $dir;

    /**
     * The plugin assets directory.
     */
    public string $assets_dir;

    /**
     * The plugin assets URL, with trailing slash.
     */
    public string $assets_url;

    /**
     * Suffix for JavaScripts.
     */
    public string $script_suffix;

    /**
     * @var ?bool The RSVP object for the RSVP tool, if feature is enabled.
     */
    protected ?bool $rsvp = null;

    /**
     * @var ?bool The Auth object for the Authentication tool, if feature is enabled.
     */
    protected ?bool $auth = null;

    /**
     * @var ?bool True after the Small Group feature is loaded.
     */
    protected ?bool $smallGroups = null;

    /**
     * @var ?bool True after the Classes feature is loaded.
     */
    protected ?bool $classes = null;

    /**
     * @var ?WP_Http Object for API requests.
     */
    private ?WP_Http $httpClient = null;

    /**
     * Constructor function.
     *
     * @param string $file
     */
    protected function __construct($file = '')
    {
        // Load plugin environment variables.
        $this->file       = $file;
        self::$dir        = dirname($this->file);
        $this->assets_dir = trailingslashit(self::$dir) . 'assets';
        $this->assets_url = esc_url(trailingslashit(plugins_url('/assets/', $this->file)));

        $this->script_suffix = defined('SCRIPT_DEBUG') && SCRIPT_DEBUG ? '' : '.min';

        register_activation_hook($this->file, [$this, 'activation']);
        register_deactivation_hook($this->file, [$this, 'deactivation']);
        register_uninstall_hook($this->file, [self::class, 'uninstall']);

        // Register frontend JS & CSS.
        add_action('init', [$this, 'registerScriptsAndStyles'], 0);

        // Register AJAX Interfaces
        add_action('init', [$this, 'registerAjaxCommon'], 0);

        // Load admin JS & CSS.
//		add_action( 'admin_enqueue_scripts', [$this, 'admin_enqueue_scripts'], 10, 1 ); // TODO restore?
//		add_action( 'admin_enqueue_scripts', [$this, 'admin_enqueue_styles'], 10, 1 ); // TODO restore?

        // Load API for generic admin functions.
//        if (is_admin()) {
//            $this->admin(); // SOMEDAY if we ever need to interact with other post types, this should be uncommented.
//        }

        add_filter('do_parse_request', [$this, 'parseRequest'], 10, 3);

        // Handle localisation.
        $this->load_plugin_textdomain();
        add_action('init', [$this, 'load_localisation'], 0);

        // Start session if not started already.
        if (session_status() === PHP_SESSION_NONE)
            session_start();

        // Adds async and defer attributes to script tags.
        add_filter('script_loader_tag', [$this, 'filterByTag'], 10, 2);


//        // Load Auth tool if enabled.  TODO it would seem that these are not used. Confirm for install without composer and remove.
//        if (get_option(self::SETTINGS_PREFIX . 'enable_authentication') === "on"
//            && ! class_exists("tp\TouchPointWP\Auth")) {
//            require_once 'Auth.php';
//        }
//
//        // Load RSVP tool if enabled.
//        if (get_option(self::SETTINGS_PREFIX . 'enable_rsvp') === "on"
//            && ! class_exists("tp\TouchPointWP\Rsvp")) {
//            require_once 'Rsvp.php';
//        }
//
//        // Load Small Group tool if enabled.
//        if (get_option(self::SETTINGS_PREFIX . 'enable_small_groups') === "on"
//            && ! class_exists("tp\TouchPointWP\SmallGroup")) {
//            require_once 'SmallGroup.php';
//        }
//
//        // Load Events if enabled (by presence of Events Calendar plugin)
//        if (self::useTribeCalendar()
//            && ! class_exists("tp\TouchPointWP\EventsCalendar")) {
//            require_once 'EventsCalendar.php';
//        }
    }

    public function admin(): TouchPointWP_AdminAPI
    {
        if ($this->admin === null) {
            require_once 'TouchPointWP_AdminAPI.php';
            $this->admin = new TouchPointWP_AdminAPI();
        }
        return $this->admin;
    }

    /**
     * Spit out headers that prevent caching.  Useful for API calls.
     */
    public static function noCacheHeaders(): void
    {
        header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
        header("Cache-Control: post-check=0, pre-check=0", false);
        header("Pragma: no-cache");
    }

    /**
     * @param bool      $continue   Whether or not to parse the request
     * @param WP        $wp         Current WordPress environment instance
     * @param array|string $extraVars Passed query variables
     *
     * @return bool Whether or not other request parsing functions should be allowed to function.
     *
     * @noinspection PhpMissingParamTypeInspection
     * @noinspection PhpUnusedParameterInspection
     */
    public function parseRequest($continue, $wp, $extraVars): bool
    {
        if ($continue) {
            $reqUri = parse_url(trim($_SERVER['REQUEST_URI'], '/'));

            $reqUri['path'] = explode("/", $reqUri['path']);

            if (count($reqUri['path']) < 2 || strtolower($reqUri['path'][0]) !== self::API_ENDPOINT) {
                return $continue;
            }

            // Parse parameters
            parse_str($reqUri['query'] ?? '', $queryParams);
            $reqUri['query'] = $queryParams;
            unset($queryParams);

            // App Events Endpoint
            if ($reqUri['path'][1] === "app-events" &&
                count($reqUri['path']) === 2 &&
                TouchPointWP::useTribeCalendar()) {

                if (!EventsCalendar::api($reqUri)) {
                    return $continue;
                }
            }

            if ($reqUri['path'][1] === "sg" &&
                get_option(self::SETTINGS_PREFIX . 'enable_small_groups') === "on"
            ) {
                if (!SmallGroup::api($reqUri)) {
                    return $continue;
                }
            }

            if ($reqUri['path'][1] === "cs" &&
                get_option(self::SETTINGS_PREFIX . 'enable_courses') === "on"
            ) {
                if (!Course::api($reqUri)) {
                    return $continue;
                }
            }

            // Generate Python Scripts
            if ($reqUri['path'][1] === self::API_ENDPOINT_GENERATE_SCRIPTS &&
                count($reqUri['path']) === 2 &&
                current_user_can('administrator')) {

                if (!$this->generateAndEchoPython()) {
                    // something went wrong...
                    return $continue;
                }
                exit;
            }
        }
        return $continue;
    }


    /**
     * Generate scripts package and send to client.
     *
     * There needs to be a permission check elsewhere, before this method is called.
     *
     * @return bool True on success, False on failure.
     */
    private function generateAndEchoPython(): bool
    {
        $fileName = $this->admin()->generatePython();

        if (! is_string($fileName)) {
            // something went wrong...
            return false;
        }

        TouchPointWP::noCacheHeaders();
        header("Content-disposition: attachment; filename=TouchPoint-WP-Scripts.zip");
        header('Content-type: application/zip');

        readfile($fileName);
        unlink ($fileName);
        return true;
    }


    /**
     * Load plugin textdomain
     */
    public function load_plugin_textdomain()
    {
        $locale = apply_filters('plugin_locale', get_locale(), self::TEXT_DOMAIN);

        load_textdomain(
            self::TEXT_DOMAIN,
            WP_LANG_DIR . '/' . self::TEXT_DOMAIN . '/' . self::TEXT_DOMAIN . '-' . $locale . '.mo'
        );
        load_plugin_textdomain(self::TEXT_DOMAIN, false, dirname(plugin_basename($this->file)) . '/lang/');
    }

    public static function load($file): TouchPointWP
    {
        $instance = self::instance($file);

        if (is_null($instance->settings)) {
            $instance->settings = TouchPointWP_Settings::instance($instance);
        }

        // Load Auth tool if enabled.
        if (get_option(self::SETTINGS_PREFIX . 'enable_authentication') === "on") {
            require_once 'Auth.php';
            $instance->auth = Auth::load($instance);
        }

        // Load RSVP tool if enabled.
        if (get_option(self::SETTINGS_PREFIX . 'enable_rsvp') === "on") {
            require_once 'Rsvp.php';
            $instance->rsvp = Rsvp::load();
        }

        // Load Small Groups tool if enabled.
        if (get_option(self::SETTINGS_PREFIX . 'enable_small_groups') === "on") {
            require_once 'SmallGroup.php';
            $instance->smallGroups = SmallGroup::load($instance);
        }

        // Load Classes tool if enabled.
        if (get_option(self::SETTINGS_PREFIX . 'enable_courses') === "on") {
            require_once 'Course.php';
            $instance->classes = Course::load($instance);
        }

        // Load Events if enabled (by presence of Events Calendar plugin)
        if (self::useTribeCalendar()
            && ! class_exists("tp\TouchPointWP\EventsCalendar")) {
            require_once 'EventsCalendar.php';
        }

        add_action('init', [self::class, 'init']);

        return $instance;
    }

    public static function init(): void
    {
        self::instance()->registerTaxonomies();

        // If any slugs have changed, flush.  Only executes if already enqueued.
        self::instance()->flushRewriteRules();
    }

    public function registerScriptsAndStyles(): void
    {
        // Register scripts that exist for all modules
        wp_register_script(
            self::SHORTCODE_PREFIX . 'base-defer',
            $this->assets_url . 'js/base-defer.js',
            [],
            self::VERSION,
            true
        );

        wp_add_inline_script(
            self::SHORTCODE_PREFIX . 'base-defer',
            file_get_contents($this->assets_dir . '/js/base-inline.js'),
            'before'
        );

        wp_register_script(
            self::SHORTCODE_PREFIX . 'swal2-defer',
            "//cdn.jsdelivr.net/npm/sweetalert2@10",
            [],
            self::VERSION,
            true
        );

        if ( ! ! $this->auth) {
            Auth::registerScriptsAndStyles();
        }

        if ( ! ! $this->rsvp) {
            Rsvp::registerScriptsAndStyles();
        }

        if ( ! ! $this->smallGroups) {
            SmallGroup::registerScriptsAndStyles();
        }
    }


    /**
     * Adds async/defer attributes to enqueued / registered scripts.  If -defer or -async is present in the script's
     * handle, the respective attribute is added.
     *
     * DOES apply to ALL scripts, not just those in the template.
     *
     * @param ?string $tag The script tag.
     * @param ?string $handle The script handle.
     *
     * @return string The HTML string.
     *
     * @noinspection DuplicatedCode  This functionality is also added by Tenth's Themes.
     */
    public function filterByTag(?string $tag, ?string $handle): string
    {
        if (strpos($tag, 'async') !== false &&
            strpos($handle, '-async') > 0) {
            $tag = str_replace(' src=', ' async="async" src=', $tag);
        }
        if (strpos($tag, 'defer') !== false &&
            strpos($handle, '-defer') > 0
        ) {
            $tag = str_replace('<script ', '<script defer ', $tag);
        }

        return $tag;
    }



    public function registerAjaxCommon(): void
    {
        $tp_ = self::HOOK_PREFIX;
        add_action( "wp_ajax_{$tp_}ident", [$this, 'ajaxIdent'] );
        add_action( "wp_ajax_nopriv_{$tp_}ident", [$this, 'ajaxIdent'] );
        add_action( "wp_ajax_{$tp_}inv_join", [$this, 'ajaxInvJoin'] );
        add_action( "wp_ajax_nopriv_{$tp_}inv_join", [$this, 'ajaxInvJoin'] );
        add_action( "wp_ajax_{$tp_}inv_contact", [$this, 'ajaxInvContact'] );
        add_action( "wp_ajax_nopriv_{$tp_}inv_contact", [$this, 'ajaxInvContact'] );
        add_action( "wp_ajax_{$tp_}geolocate", [$this, 'ajaxGeolocate'] );
        add_action( "wp_ajax_nopriv_{$tp_}geolocate", [$this, 'ajaxGeolocate'] );
    }

    public function ajaxIdent(): void
    {
        header('Content-Type: application/json');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['error' => 'Only POST requests are allowed.']);
            wp_die();
        }

        $inputData = file_get_contents('php://input');
        if ($inputData[0] !== '{') {
            echo json_encode(['error' => 'Invalid data provided.']);
            wp_die();
        }

        $data = $this->apiPost('ident', json_decode($inputData));

        if ($data instanceof WP_Error) {
            echo json_encode(['error' => $data->get_error_message()]);
            wp_die();
        }

        $people = $data->people ?? [];

        // TODO sync or queue sync of people

        $ret = [];
        foreach ($people as $p) {
            $p->lastName = $p->lastName[0] ? $p->lastName[0] . "." : "";
            unset($p->lastInitial);
            $ret[] = $p;
        }

        echo json_encode(['people' => $ret]);
        wp_die();
    }

    public function ajaxInvJoin(): void
    {
        header('Content-Type: application/json');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['error' => 'Only POST requests are allowed.']);
            wp_die();
        }

        $inputData = file_get_contents('php://input');
        if ($inputData[0] !== '{') {
            echo json_encode(['error' => 'Invalid data provided.']);
            wp_die();
        }

        $data = $this->apiPost('inv_join', json_decode($inputData));

        if ($data instanceof WP_Error) {
            echo json_encode(['error' => $data->get_error_message()]);
            wp_die();
        }

        echo json_encode(['success' => $data->success]);
        wp_die();
    }


    public function ajaxInvContact(): void
    {
        header('Content-Type: application/json');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['error' => 'Only POST requests are allowed.']);
            wp_die();
        }

        $inputData = file_get_contents('php://input');
        if ($inputData[0] !== '{') {
            echo json_encode(['error' => 'Invalid data provided.']);
            wp_die();
        }

        $data = $this->apiPost('inv_contact', json_decode($inputData));

        if ($data instanceof WP_Error) {
            echo json_encode(['error' => $data->get_error_message()]);
            wp_die();
        }

        echo json_encode(['success' => $data->success]);
        wp_die();
    }


    public function ajaxGeolocate(): void
    {
        header('Content-Type: application/json');
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            echo json_encode(['error' => 'Only GET requests are allowed.']);
            wp_die();
        }

        // TODO validate that request is only coming from allowed referrers... or something like that.

        echo json_encode($this->geolocate());
        wp_die();
    }

    /**
     * @param ?string $ip To get info for a specific IP, pass it here.
     *
     * @return object An object with a 'lat' and 'lng' attribute, if a location could be identified. Or, an object with
     * an 'error' parameter if something went wrong.
     */
    public function geolocate($ip = null): object
    {
        if ($ip === null) {
            $ipHeaderKeys = [
                'HTTP_CLIENT_IP',
                'HTTP_X_FORWARDED_FOR',
                'HTTP_X_FORWARDED',
                'HTTP_FORWARDED_FOR',
                'HTTP_FORWARDED',
                'REMOTE_ADDR'
            ];
            $ip = null;
            foreach ($ipHeaderKeys as $k) {
                if ( ! empty($_SERVER[$k]) && filter_var($_SERVER[$k], FILTER_VALIDATE_IP)) {
                    $ip = $_SERVER[$k];
                    break;
                }
            }
        }

        if ($ip === '' || $ip === null) {
            return (object)['error' => 'No usable IP Address.'];
        }

        $return = $this->getIpData($ip);

        if ($return instanceof WP_Error) {
            return (object)['error' => implode(", ", $return->get_error_messages())];
        }

        $d = json_decode($return);
        if ($d->error) {
            return (object)['error' => $d->reason];
        }

        if ($d->country === "US") {
            $d->human = $d->city . ", " . $d->postal;
        } else {
            $d->human = $d->city . ", " . $d->country_name;
        }

        return (object)['lat' => $d->latitude, 'lng' => $d->longitude, 'human' => $d->human, 'type' => 'ip' ];
    }

    /**
     * The underlying IP Data function, which handles caching.
     *
     * @param $ip
     *
     * @return mixed
     */
    protected function getIpData($ip)
    {
        $ip_pton = inet_pton($ip);

        global $wpdb;
        $tableName = $wpdb->base_prefix . self::TABLE_IP_GEO;
        /** @noinspection SqlResolve */
        $q = $wpdb->prepare( "SELECT * FROM {$tableName} WHERE ip = '{$ip_pton}' and updatedDt > (NOW() - INTERVAL 30 DAY)");
        $cache = $wpdb->get_row($q);
        if ($cache) {
            return $cache->data;
        }

        // TODO allow admin to define some static IPs and corresponding locations

        $return = self::instance()->extGet("https://ipapi.co/" . $ip . "/json/");

        if ($return instanceof WP_Error) {
            return $return;
        }

        $return = $return['body'];

        /** @noinspection SqlResolve */
        $q = $wpdb->prepare( "
            INSERT INTO {$tableName} (ip, updatedDt, data)
            VALUES ('{$ip_pton}', NOW(), %s) ON DUPLICATE KEY UPDATE updatedDt = NOW(), data = %s;",
        $return, $return);
        $wpdb->query($q);

        return $return;
    }

    public function registerTaxonomies(): void
    {
        // Resident Codes
        $resCodeTypesToApply = ['user'];
        if (get_option(TouchPointWP::SETTINGS_PREFIX . 'enable_small_groups') === "on") {
            $resCodeTypesToApply[] = SmallGroup::POST_TYPE;
        }
        register_taxonomy(
            self::TAX_RESCODE,
            $resCodeTypesToApply,
            [
                'hierarchical'      => false,
                'show_ui'           => false,
                'description'       => __('Classify small groups and users by their general locations.'),
                'labels'            => [
                    'name'          => $this->settings->rc_name_plural,
                    'singular_name' => $this->settings->rc_name_singular,
                    'search_items'  => __('Search ' . $this->settings->rc_name_plural), // TODO change to sprintf
                    'all_items'     => __('All ' . $this->settings->rc_name_plural),
                    'edit_item'     => __('Edit ' . $this->settings->rc_name_singular),
                    'update_item'   => __('Update ' . $this->settings->rc_name_singular),
                    'add_new_item'  => __('Add New ' . $this->settings->rc_name_singular),
                    'new_item_name' => __('New ' . $this->settings->rc_name_singular . ' Name'),
                    'menu_name'     => $this->settings->rc_name_plural,
                ],
                'public'            => true,
                'show_in_rest'      => true,
                'show_admin_column' => true,

                // Control the slugs used for this taxonomy
                'rewrite'           => [
                    'slug'         => $this->settings->rc_slug,
                    'with_front'   => false,
                    'hierarchical' => false
                ],
            ]
        );
        foreach ($this->getResCodes() as $rc) {
            if ( ! term_exists($rc->name, self::TAX_RESCODE)) {
                wp_insert_term(
                    $rc->name,
                    self::TAX_RESCODE,
                    [
                        'description' => $rc->name,
                        'slug'        => sanitize_title($rc->name)
                    ]
                );
                self::queueFlushRewriteRules();
            }
        }
        // TODO remove defunct res codes

        // Divisions & Programs
        $divisionTypesToApply = ['user'];
        if (get_option(TouchPointWP::SETTINGS_PREFIX . 'enable_small_groups') === "on") {
            $divisionTypesToApply[] = SmallGroup::POST_TYPE;
        }
        // TODO allow this taxonomy to be applied to other post types as an option.
        register_taxonomy(
            self::TAX_DIV,
            $divisionTypesToApply,
            [
                'hierarchical'      => true,
                'show_ui'           => true, // TODO make false
                'description'       => sprintf(__('Classify things by %s.'), $this->settings->dv_name_singular),
                'labels'            => [
                    'name'          => $this->settings->dv_name_plural,
                    'singular_name' => $this->settings->dv_name_singular,
                    'search_items'  => __('Search ' . $this->settings->dv_name_plural), // TODO change to sprintf
                    'all_items'     => __('All ' . $this->settings->dv_name_plural),
                    'edit_item'     => __('Edit ' . $this->settings->dv_name_singular),
                    'update_item'   => __('Update ' . $this->settings->dv_name_singular),
                    'add_new_item'  => __('Add New ' . $this->settings->dv_name_singular),
                    'new_item_name' => __('New ' . $this->settings->dv_name_singular . ' Name'),
                    'menu_name'     => $this->settings->dv_name_plural,
                ],
                'public'            => true,
                'show_in_rest'      => true,
                'show_admin_column' => false, // TODO make an option?

                // Control the slugs used for this taxonomy
                'rewrite'           => [
                    'slug'         => $this->settings->dv_slug,
                    'with_front'   => false,
                    'hierarchical' => true
                ],
            ]
        );
        $enabledDivisions = $this->settings->dv_divisions;
        foreach ($this->getDivisions() as $d) {
            if (in_array('div' . $d->id, $enabledDivisions)) {

                // Program
                $pTermInfo = term_exists($d->pName, self::TAX_DIV);
                if ( $pTermInfo === null ) {
                    $pTermInfo = wp_insert_term(
                        $d->pName,
                        self::TAX_DIV,
                        [
                            'description' => $d->pName,
                            'slug'        => sanitize_title($d->pName)
                        ]
                    );
                    update_term_meta($pTermInfo['term_id'], self::SETTINGS_PREFIX . 'programId', $d->proId);
                    self::queueFlushRewriteRules();
                }

                // Division
                $dTermInfo = term_exists($d->dName, self::TAX_DIV);
                if ($dTermInfo === null) {
                    $dTermInfo = wp_insert_term(
                        $d->dName,
                        self::TAX_DIV,
                        [
                            'description' => $d->dName,
                            'parent'      => $pTermInfo['term_id'],
                            'slug'        => sanitize_title($d->dName)
                        ]
                    );
                    update_term_meta($dTermInfo['term_id'], self::SETTINGS_PREFIX . 'divId', $d->id);
                    self::queueFlushRewriteRules();
                }
            } else {
                // Remove terms that are no longer used. TODO also remove ones that no longer exist in TouchPoint.

                // Division
                $dTermInfo = term_exists($d->dName, self::TAX_DIV);
                if ($dTermInfo !== null) {
                    wp_delete_term($dTermInfo['term_id'], self::TAX_DIV);
                    self::queueFlushRewriteRules();
                }

                // Program
                // TODO remove program terms that are no longer used
            }
        }

        // Weekdays
        if (get_option(TouchPointWP::SETTINGS_PREFIX . 'enable_small_groups') === "on") {
            register_taxonomy(
                self::TAX_WEEKDAY,
                [SmallGroup::POST_TYPE],
                [
                    'hierarchical'      => false,
                    'show_ui'           => false,
                    'description'       => __('Classify small groups by the day on which they meet.'),
                    'labels'            => [
                        'name'          => __('Weekdays'),
                        'singular_name' => __('Weekday'),
                        'search_items'  => __('Search Weekdays'),
                        'all_items'     => __('All Weekdays'),
                        'edit_item'     => __('Edit Weekday'),
                        'update_item'   => __('Update Weekday'),
                        'add_new_item'  => __('Add New Weekday'),
                        'new_item_name' => __('New Weekday Name'),
                        'menu_name'     => __('Weekdays'),
                    ],
                    'public'            => true,
                    'show_in_rest'      => true,
                    'show_admin_column' => true,

                    // Control the slugs used for this taxonomy
                    'rewrite'           => [
                        'slug'         => 'weekday',
                        'with_front'   => false,
                        'hierarchical' => false
                    ],
                ]
            );
            for ($di = 0; $di < 7; $di++) {
                $name = self::getPluralDayOfWeekNameForNumber($di);
                if ( ! term_exists($name, self::TAX_WEEKDAY)) {
                    wp_insert_term(
                        $name,
                        self::TAX_WEEKDAY,
                        [
                            'description' => $name,
                            'slug'        => self::getDayOfWeekShortForNumber($di)
                        ]
                    );
                    self::queueFlushRewriteRules();
                }
            }
        }


        // Age Groups
        $ageGroupTypesToApply = ['user'];
        if (get_option(TouchPointWP::SETTINGS_PREFIX . 'enable_small_groups') === "on") {
            $ageGroupTypesToApply[] = SmallGroup::POST_TYPE;
        }
        register_taxonomy(self::TAX_AGEGROUP, $ageGroupTypesToApply, [
            'hierarchical' => false,
            'show_ui' => false,
            'description' => __( 'Classify small groups and users by their age groups.' ),
            'labels' => [
                'name' => __('Age Groups'),
                'singular_name' => __('Age Group'),
                'search_items' =>  __( 'Search Age Groups' ),
                'all_items' => __( 'All Age Groups'  ),
                'edit_item' => __( 'Edit Age Group' ),
                'update_item' => __( 'Update Age Group' ),
                'add_new_item' => __( 'Add New Age Group' ),
                'new_item_name' => __( 'New Age Group' ),
                'menu_name' => __('Age Groups'),
            ],
            'public' => true,
            'show_in_rest' => true,
            'show_admin_column' => true,

            // Control the slugs used for this taxonomy
            'rewrite' => [
                'slug' => self::TAX_AGEGROUP,
                'with_front' => false,
                'hierarchical' => false
            ],
        ]);
        foreach (["20s", "30s", "40s", "50s", "60s", "70+"] as $ag) {
            if (! term_exists($ag, self::TAX_AGEGROUP)) {
                wp_insert_term(
                    $ag,
                    self::TAX_RESCODE,
                    [
                        'description' => $ag,
                        'slug'        => sanitize_title($ag)
                    ]
                );
                self::queueFlushRewriteRules();
            }
        }


        // Involvement Marital Status
        if (get_option(TouchPointWP::SETTINGS_PREFIX . 'enable_small_groups') === "on") {
            register_taxonomy(
                self::TAX_INV_MARITAL,
                [SmallGroup::POST_TYPE],
                [
                    'hierarchical'      => false,
                    'show_ui'           => false,
                    'description'       => __('Classify small groups by whether participants are mostly single or married.'),
                    'labels'            => [
                        'name'          => __('Marital Status'),
                        'singular_name' => __('Marital Statuses'),
                        'search_items'  => __('Search Martial Statuses'),
                        'all_items'     => __('All Marital Statuses'),
                        'edit_item'     => __('Edit Marital Status'),
                        'update_item'   => __('Update Marital Status'),
                        'add_new_item'  => __('Add New Marital Status'),
                        'new_item_name' => __('New Marital Status'),
                        'menu_name'     => __('Marital Statuses'),
                    ],
                    'public'            => true,
                    'show_in_rest'      => true,
                    'show_admin_column' => true,

                    // Control the slugs used for this taxonomy
                    'rewrite'           => [
                        'slug'         => self::TAX_INV_MARITAL,
                        'with_front'   => false,
                        'hierarchical' => false
                    ],
                ]
            );
            foreach (['mostly_single', 'mostly_married'] as $ms) {
                if ( ! term_exists($ms, self::TAX_INV_MARITAL)) {
                    wp_insert_term(
                        $ms,
                        self::TAX_INV_MARITAL,
                        [
                            'description' => $ms,
                            'slug'        => sanitize_title($ms)
                        ]
                    );
                    self::queueFlushRewriteRules();
                }
            }
        }
    }

    private static array $divisionTerms = [];

    /**
     * @param int $divId
     *
     * @return int|false Returns the term ID number or false (or 0) if the division is not found, or not enabled.
     */
    public static function getDivisionTermIdByDivId(int $divId): int
    {
        if (!isset(self::$divisionTerms['d' . $divId])) {
            $t = get_terms(
                [
                    'taxonomy'   => self::TAX_DIV,
                    'hide_empty' => false,
                    'number'     => 1,
                    'fields'     => 'ids',
                    'meta_key'   => self::SETTINGS_PREFIX . 'divId',
                    'meta_value' => $divId
                ]
            );
            if (is_array($t) && count($t) > 0) {
                self::$divisionTerms['d' . $divId] = $t[0];
            } else { // not found, or division is not enabled for syncing.
                self::$divisionTerms['d' . $divId] = false;
            }
        }
        return self::$divisionTerms['d' . $divId];
    }

    /**
     * Main TouchPointWP Instance
     *
     * Ensures only one instance of TouchPointWP is loaded or can be loaded.
     *
     * @param string $file File instance.
     *
     * @return TouchPointWP instance
     * @see TouchPointWP()
     */
    public static function instance($file = ''): TouchPointWP
    {
        if (is_null(self::$_instance)) {
            self::$_instance = new self($file);
        }

        return self::$_instance;
    }

    /**
     * Get a random string with a timestamp on the end.
     *
     * @param int $timeout How long the token should last.
     *
     * @return string
     */
    public static function generateAntiForgeryId(int $timeout): string
    {
        return strtolower(substr(com_create_guid(), 1, 36) . "-" . dechex(time() + $timeout));
    }

    /**
     * @param string $afId Anti-forgery ID.
     *
     * @param int    $timeout
     *
     * @return bool True if the timestamp hasn't expired yet.
     */
    public static function AntiForgeryTimestampIsValid(string $afId, int $timeout): bool
    {
        $afIdTime = hexdec(substr($afId, 37));

        return ($afIdTime <= time() + $timeout) && $afIdTime >= time();
    }

    /**
     * Gets the plural form of a weekday name.
     *
     * Translation: These are deliberately not scoped to TouchPoint-WP, so if the translation exists globally, it should
     * work here.
     *
     * @param int $dayNum
     *
     * @return string Plural weekday (e.g. Mondays)
     */
    public static function getPluralDayOfWeekNameForNumber(int $dayNum): string
    {
        $names = [
            __('Sundays'),
            __('Mondays'),
            __('Tuesdays'),
            __('Wednesdays'),
            __('Thursdays'),
            __('Fridays'),
            __('Saturdays'),
        ];

        return $names[$dayNum % 7];
    }

    /**
     * @param int $dayNum
     *
     * @return string
     */
    public static function getDayOfWeekShortForNumber(int $dayNum): string
    {
        $names = [
            __('Sun'),
            __('Mon'),
            __('Tue'),
            __('Wed'),
            __('Thu'),
            __('Fri'),
            __('Sat'),
        ];

        return $names[$dayNum % 7];
    }

    /**
     * Join an array of strings into a properly-formatted (English-style) list. Uses commas and ampersands by default.
     * This will switch to written "and" when an ampersand is present in a string, and will use semi-colons instead of
     * commas when commas are already present.
     *
     * Turn ['apples', 'oranges', 'pears'] into "apples, oranges & pears"
     *
     * @param string[] $strings
     *
     * @return string
     */
    public static function stringArrayToList(array $strings): string
    {
        $concat = implode('', $strings);

        $comma = ', ';
        $and = ' & ';
        $useOxford = false;
        if (strpos($concat, ', ') !== false) {
            $comma     = '; ';
            $useOxford = true;
        }
        if (strpos($concat, ' & ') !== false) {
            $and = ' ' . __('and') . ' ';
            $useOxford = true;
        }

        $last = array_pop($strings);
        $str = implode($comma, $strings);
        if (count($strings) > 0) {
            if ($useOxford)
                $str .= trim($comma);
            $str .= $and;
        }
        $str .= $last;
        return $str;
    }

    /**
     * Load plugin localisation
     */
    public function load_localisation()
    {
        load_plugin_textdomain(self::TEXT_DOMAIN, false, dirname(plugin_basename($this->file)) . '/lang/');
    }

    /**
     * Don't clone.
     */
    public function __clone()
    {
        _doing_it_wrong(
            __FUNCTION__,
            esc_html(__('Cloning TouchPointWP is questionable.  Don\'t do it.')),
            esc_attr(self::VERSION)
        );
    }

    /**
     * don't deserialize.
     */
    public function __wakeup()
    {
        _doing_it_wrong(
            __FUNCTION__,
            esc_html(__('Deserializing TouchPointWP is questionable.  Don\'t do it.')),
            esc_attr(self::VERSION)
        );
    }

    /**
     * Activation. Runs on activation.
     */
    public function activation()
    {
        $this->_log_version_number();

        self::queueFlushRewriteRules();

        $this->createTables();
    }

    /**
     * Deactivation. Runs on deactivation.
     */
    public function deactivation()
    {
        $this->_log_version_number();

        self::flushRewriteRules(true);
    }

    /**
     * Uninstallation. Runs on uninstallation.
     */
    public static function uninstall()
    {
        // TODO remove all options.
        // TODO remove all taxonomies (maybe)
        // TODO remove all posts

        // TODO cancel Cron updating task.

        self::dropTables();
    }


    /**
     * Create or update database tables
     */
    protected function createTables(): void // TODO this should be called more than just installs to cover updates.
    {
        global $wpdb;
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        // IP Geo Caching table
        $tableName = $wpdb->base_prefix . self::TABLE_IP_GEO;
        $sql = "CREATE TABLE $tableName (
            id int(10) unsigned NOT NULL auto_increment,
            ip varbinary(16) NOT NULL UNIQUE,
            updatedDT datetime DEFAULT NOW(),
            data text NOT NULL,
            PRIMARY KEY  (id)
        )";
        dbDelta($sql);
    }

    /**
     * Drop database tables at uninstallation.
     */
    protected static function dropTables(): void
    {
        if ( !defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            // just to be really sure we want to do this.
            return;
        }

        global $wpdb;

        // IP Geo Caching table
        $tableName = $wpdb->base_prefix . self::TABLE_IP_GEO;
        $wpdb->query("DROP TABLE IF EXISTS $tableName");
    }

    /**
     * Log the plugin version number.
     */
    private function _log_version_number()
    {
        update_option(self::TOKEN . '_version', self::VERSION, false);
    }

    public static function useTribeCalendarPro(): bool
    {
        if ( ! function_exists( 'is_plugin_active' ) ){
            require_once( ABSPATH . '/wp-admin/includes/plugin.php' );
        }

        return is_plugin_active( 'events-calendar-pro/events-calendar-pro.php');
    }

    public static function useTribeCalendar(): bool
    {
        return self::useTribeCalendarPro() || is_plugin_active( 'the-events-calendar/the-events-calendar.php');
    }


    /**
     * Sort a list of heirarchical terms into a list in which each parent is immediately followed by its children.
     *
     * @param WP_Term[] $terms
     *
     * @return WP_Term[]
     */
    public static function orderHierarchicalTerms(array $terms): array
    {
        $lineage = [[]];
        foreach ($terms as $t) {
            if (! isset($lineage[$t->parent])) {
                $lineage[$t->parent] = [];
            }
            $lineage[$t->parent][] = $t;
        }

        usort($lineage[0], fn($a, $b) => strcmp($a->name, $b->name));

        $out = [];
        foreach ($lineage[0] as $t) {
            $out[] = $t;
            if (isset($lineage[$t->term_id])) {
                usort($lineage[$t->term_id], fn($a, $b) => strcmp($a->name, $b->name));

                foreach ($lineage[$t->term_id] as $t2) {
                    $out[] = $t2;
                }
            }
        }
        return $out;
    }

    /**
     * @return string The URL of the TouchPoint instance.
     */
    public function host(): string
    {
        $host = $this->settings->host;
        if ($host === TouchPointWP_Settings::UNDEFINED_PLACEHOLDER || $host === '')
            return TouchPointWP_Settings::UNDEFINED_PLACEHOLDER;
        return "https://" . $host;
    }

    public function getApiCredentials(): object
    {
        return (object)[
            'user' => $this->settings->api_user,
            'pass' => $this->settings->api_pass
        ];
    }

    /**
     * Get or generate an API key for use with TouchPoint
     *
     * @return string
     */
    public function getApiKey(): string
    {
        $k = $this->settings->get('api_secret_key');
        if ($k === false) {
            $k = $this->replaceApiKey();
        }

        return $k;
    }

    /**
     * @return string
     */
    public function replaceApiKey(): string
    {
        return $this->settings->set('api_secret_key', com_create_guid());
    }

    /**
     * Get the member types currently in use for the named divisions.
     *
     * @param $divisions
     *
     * @return array
     */
    public function getMemberTypesForDivisions($divisions = []): array
    {
        $divisions = implode(",", $divisions);
        $divisions = str_replace('div','', $divisions);

        // TODO caching.

        try {
            $return = $this->apiGet('MemTypes', ['divs' => $divisions]);
        } catch (TouchPointWP_Exception $e) {
            return [];
        }

        if ($return instanceof WP_Error) {
            return [];
        }

        $body = json_decode($return['body']);

        if (property_exists($body, "message") || ! is_array($body->data->memTypes)) {
            // an error happened; fail quietly.
            return [];
        }

        return $body->data->memTypes;
    }

    public function getMemberTypesForDivisionsAsKVArray($divisions = []): array
    {
        $r = [];
        foreach (self::getMemberTypesForDivisions($divisions) as $mt) {
            $r['mt' . $mt->id] = $mt->description;
        }

        return $r;
    }

    /**
     * Format the list of divisions into an array with form-name-friendly IDs as the key.
     *
     * @return string[]
     */
    public function getDivisionsAsKVArray(): array
    {
        $r = [];
        foreach ($this->getDivisions() as $d) {
            $r['div' . $d->id] = $d->name;
        }

        return $r;
    }

    /**
     * Returns an array of objects that correspond to divisions.  Each Division has a name and an id.  The name is both
     * the Program and Division.
     *
     * @returns object[]
     */
    public function getDivisions(): array
    {
        $divsObj = $this->settings->get('meta_divisions');

        $needsUpdate = false;
        if ($divsObj === false) {
            $needsUpdate = true;
        } else {
            $divsObj = json_decode($divsObj);
            if (strtotime($divsObj->_updated) < time() - 3600 * 2 || ! is_array($divsObj->divs)) {
                $needsUpdate = true;
            }
        }

        // Get update if needed.  TODO move to cron.
        if ($needsUpdate) {
            $divsObj = $this->updateDivisions();
        }

        // If update failed, show a notice on the admin interface.
        if ($divsObj === false) {
            add_action('admin_notices', [$this->admin(), 'Error_TouchPoint_API']);

            return [];
        }

        return $divsObj->divs;
    }

    /**
     * @return false|object Update the divisions if they're stale.
     */
    private function updateDivisions()
    {
        try {
            $return = $this->apiGet('Divisions');
        } catch (TouchPointWP_Exception $e) {
            return false;
        }

        if ($return instanceof WP_Error) {
            return false;
        }

        $body = json_decode($return['body']);

        if (property_exists($body, "message")) {
            return false;
        }

        if ( ! is_array($body->data->divs)) {
            return false;
        }

        $obj = (object)[
            '_updated' => date('c'),
            'divs'     => $body->data->divs
        ];

        $this->settings->set("meta_divisions", json_encode($obj));

        return $obj;
    }


    /**
     * Returns an array of objects that correspond to resident codes.  Each ResCode has a name, a code, and an id.
     *
     * @returns object[]
     */
    public function getResCodes(): array
    {
        $rcObj = $this->settings->get('meta_resCodes');

        $needsUpdate = false;
        if ($rcObj === false || $rcObj === null) {
            $needsUpdate = true;
        } else {
            $rcObj = json_decode($rcObj);
            if (strtotime($rcObj->_updated) < time() - 3600 * 2 || ! is_array($rcObj->resCodes)) {
                $needsUpdate = true;
            }
        }

        // Get update if needed.
        if ($needsUpdate) {
            $rcObj = $this->updateResCodes();
        }

        // If update failed, show a notice on the admin interface.
        if ($rcObj === false) {
            add_action('admin_notices', [$this->admin(), 'Error_TouchPoint_API']);

            return [];
        }

        return $rcObj->resCodes;
    }


    /**
     * @return false|object Update the resident codes if they're stale.
     */
    private function updateResCodes()
    {
        try {
            $return = $this->apiGet('ResCodes');
        } catch (TouchPointWP_Exception $e) {
            return false;
        }

        if ($return instanceof WP_Error) {
            return false;
        }

        $body = json_decode($return['body']);

        if (property_exists($body, "message")) {
            return false;
        }

        if ( ! is_array($body->data->resCodes)) {
            return false;
        }

        $obj = (object)[
            '_updated' => date('c'),
            'resCodes'     => $body->data->resCodes
        ];

        $this->settings->set("meta_resCodes", json_encode($obj));

        return $obj;
    }


    /**
     * Returns an array of objects that correspond to genders.  Each Gender has a name and an id.
     *
     * @returns object[]
     */
    public function getGenders(): array
    {
        $gObj = $this->settings->get('meta_genders');

        $needsUpdate = false;
        if ($gObj === false || $gObj === null) {
            $needsUpdate = true;
        } else {
            $gObj = json_decode($gObj);
            if (strtotime($gObj->_updated) < time() - 3600 * 2 || ! is_array($gObj->genders)) {
                $needsUpdate = true;
            }
        }

        // Get update if needed.
        if ($needsUpdate) {
            $gObj = $this->updateGenders();
        }

        // If update failed, show a notice on the admin interface.
        if ($gObj === false) {
            add_action('admin_notices', [$this->admin(), 'Error_TouchPoint_API']);

            return [];
        }

        return $gObj->genders;
    }


    /**
     * @return false|object Update the genders if they're stale.
     */
    private function updateGenders()
    {
        try {
            $return = $this->apiGet('Genders');
        } catch (TouchPointWP_Exception $e) {
            return false;
        }

        if ($return instanceof WP_Error) {
            return false;
        }

        $body = json_decode($return['body']);

        if (property_exists($body, "message")) {
            return false;
        }

        if ( ! is_array($body->data->genders)) {
            return false;
        }

        $obj = (object)[
            '_updated' => date('c'),
            'genders'     => $body->data->genders
        ];

        $this->settings->set("meta_genders", json_encode($obj));

        return $obj;
    }


    /**
     * @param string $command The thing to get
     * @param ?array $parameters URL parameters to be added.
     *
     * @return array|WP_Error An array with headers, body, and other keys, or WP_Error on failure.
     * Data is generally in json_decode($response['body'])->data
     *
     * @throws TouchPointWP_Exception Thrown if the API credentials are incomplete.
     */
    public function apiGet(string $command, ?array $parameters = null)
    {
        if ( ! is_array($parameters)) {
            $parameters = (array)$parameters;
        }

        if (!$this->settings->hasValidApiSettings()) {
            throw new TouchPointWP_Exception("Invalid or incomplete API Settings.");
        }

        $parameters['a'] = $command;

        $host = $this->host();

        if ($host === TouchPointWP_Settings::UNDEFINED_PLACEHOLDER)
            return new WP_Error('invalid_api_endpoint',
                                __('Host appears to be missing from TouchPoint-WP configuration.', 'TouchPoint-WP'));

        return $this->getHttpClient()->request($host . "/PythonApi/" .
            $this->settings->api_script_name . "?" . http_build_query($parameters),
            [
                'method'  => 'GET',
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode(
                            $this->settings->api_user . ':' . $this->settings->api_pass
                        )
                ]
            ]
        );
    }


    /**
     * @param string $command The thing to post
     * @param ?array $data Data to post
     *
     * @return object|WP_Error An object that corresponds to the Data python object in TouchPoint, or a WP_Error
     * instance if something went wrong.
     */
    public function apiPost(string $command, $data = null)
    {
        if (!$this->settings->hasValidApiSettings()) {
            return new WP_Error(self::SHORTCODE_PREFIX . "api-settings", "Invalid or incomplete API Settings.");
        }

        $host = $this->host();

        if ($host === TouchPointWP_Settings::UNDEFINED_PLACEHOLDER)
            return new WP_Error('invalid_api_endpoint',
                                __('Host appears to be missing from TouchPoint-WP configuration.', 'TouchPoint-WP'));

        $data = json_encode(['inputData' => $data]);

        $r = $this->getHttpClient()->request(
            $host . "/PythonApi/" .
            $this->settings->api_script_name . "?" . http_build_query(['a' => $command]),
            [
                'method'  => 'POST',
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode(
                            $this->settings->api_user . ':' . $this->settings->api_pass
                        )
                ],
                'body' => ['data' => $data]
            ]
        );

        if ($r instanceof WP_Error) {
            return $r;
        }

        $respDecoded = json_decode($r['body']);

        if ($respDecoded->output !== '') {
            return new WP_Error(self::SHORTCODE_PREFIX . "api-remote", $respDecoded->output);
        }

        return $respDecoded->data;
    }

    /**
     * @param string $url The destination of the request.
     * @param ?array $parameters URL parameters to be added.
     *
     * @return array|WP_Error An array with headers, body, and other keys, or WP_Error on failure.
     */
    public function extGet(string $url, ?array $parameters = null) {
        if ( ! is_array($parameters)) {
            $parameters = (array)$parameters;
        }

        return $this->getHttpClient()->request(
            $url . "?" . http_build_query($parameters),
            [
                'method'  => 'GET'
            ]
        );
    }

    /**
     * @return WP_Http|null
     */
    private function getHttpClient(): ?WP_Http
    {
        if ($this->httpClient === null) {
            $this->httpClient = new WP_Http();
        }

        return $this->httpClient;
    }

    /**
     * Cause a flushing of rewrite rules on next load.
     */
    public static function queueFlushRewriteRules(): void {
        $_SESSION[TouchPointWP::SETTINGS_PREFIX . 'flushRewriteOnNextLoad'] = true;
    }

    /**
     * Execute a flushing of the rewrite rules, if either absolutely necessary ($force = true) or enqueued by queuing function.
     *
     * @param bool $force
     * @see queueFlushRewriteRules()
     */
    public function flushRewriteRules(bool $force = false): void
    {
        if ( isset($_SESSION[TouchPointWP::SETTINGS_PREFIX . 'flushRewriteOnNextLoad']) || $force) {
            flush_rewrite_rules();
            unset($_SESSION[TouchPointWP::SETTINGS_PREFIX . 'flushRewriteOnNextLoad']);
        }
    }
}