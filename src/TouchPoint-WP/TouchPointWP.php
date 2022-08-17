<?php
/**
 * @package TouchPointWP
 */
namespace tp\TouchPointWP;

use stdClass;
use WP;
use WP_Error;
use WP_Http;
use WP_Term;

use tp\TouchPointWP\Utilities\Cleanup;

if ( ! defined('ABSPATH')) {
    exit;
}


/**
 * Main plugin class.
 */
class TouchPointWP
{

    /**
     * Version number
     */
    public const VERSION = "0.0.12";

    public const DEBUG = false;

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
    public const API_ENDPOINT_APP_EVENTS = "app-events";
    public const API_ENDPOINT_INVOLVEMENT = "inv";
    public const API_ENDPOINT_GLOBAL = "global";
    public const API_ENDPOINT_PERSON = "person";
    public const API_ENDPOINT_MEETING = "mtg";
    public const API_ENDPOINT_ADMIN = "admin";
    public const API_ENDPOINT_ADMIN_SCRIPTZIP = "admin/scriptzip";
    public const API_ENDPOINT_CLEANUP = "cleanup";
    public const API_ENDPOINT_GEOLOCATE = "geolocate";

    public const TEMPLATES_TO_OVERWRITE = ['archive.php', 'singular.php', 'single.php', 'index.php', 'template-canvas.php'];

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
    public const TAX_TENSE = self::HOOK_PREFIX . "tense";
    public const TAX_TENSE_PAST = "past";
    public const TAX_TENSE_PRESENT = "present";
    public const TAX_TENSE_FUTURE = "future";
    public const TAX_DAYTIME = self::HOOK_PREFIX . "timeOfDay";
    public const TAX_AGEGROUP = self::HOOK_PREFIX . "agegroup";
    public const TAX_INV_MARITAL = self::HOOK_PREFIX . "inv_marital";
    public const TAX_GP_CATEGORY = self::HOOK_PREFIX . "partner_category";

    /**
     * Table Names
     */
    public const TABLE_PREFIX = "tp_";
    public const TABLE_IP_GEO = self::TABLE_PREFIX . "ipGeo";

    /**
     * Typical amount of time in hours for metadata to last (e.g. genders and resCodes).
     */
    public const CACHE_TTL = 8;

    /**
     * Caching
     */
    public const CACHE_PUBLIC = 0;
    public const CACHE_PRIVATE = 10;
    public const CACHE_NONE = 20;
    private static int $cacheLevel = self::CACHE_PUBLIC;

    /**
     * @var string Used for imploding arrays together in human-friendly formats.
     */
    public static string $joiner = " &nbsp;&#9702;&nbsp; ";

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
     * @var ?bool True after the RSVP feature is loaded.
     */
    protected ?bool $rsvp = null;

    /**
     * @var ?bool The Auth object for the Authentication tool, if feature is enabled.
     */
    protected ?bool $auth = null;

    /**
     * @var ?bool True after the Involvements feature is loaded.
     */
    protected ?bool $involvements = null;

    /**
     * @var ?bool True after the Global feature is loaded.
     */
    protected ?bool $global = null;


    /**
     * @var ?bool True after the People feature is loaded.
     */
    protected ?bool $people = null;

    /**
     * @var ?WP_Http Object for API requests.
     */
    private ?WP_Http $httpClient = null;

    /** @var string Used to denote requests made in special circumstances, such as through the TouchPoint-WP API */
    protected static string $context = "";

    /**
     * Indicates that the current request is being processed through the API.
     *
     * @return bool
     */
    public static function isApi(): bool
    {
        return self::$context === "api";
    }

    /**
     * Constructor function.
     *
     * @param string $file
     */
    protected function __construct(string $file = '')
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

        add_action('wp_print_footer_scripts', [$this, 'printDynamicFooterScripts'], 1000);
        add_action('admin_print_footer_scripts', [$this, 'printDynamicFooterScripts'], 1000);

        add_action('admin_print_styles', [$this, 'adminPrintStyleOverrides'], 1000);

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

        add_filter('terms_clauses', [$this, 'getTermsClauses'], 10, 3);

        self::scheduleCleanup();
    }


    public function admin(): TouchPointWP_AdminAPI
    {
        if ($this->admin === null) {
            if (!TOUCHPOINT_COMPOSER_ENABLED) {
                require_once 'TouchPointWP_AdminAPI.php';
            }
            $this->admin = new TouchPointWP_AdminAPI();
        }
        return $this->admin;
    }


    public static function scheduleCleanup(): void
    {
        // Setup cron for updating Small Groups daily.
        add_action(Cleanup::CRON_HOOK, [Cleanup::class, 'cronCleanup']);
        if ( ! wp_next_scheduled(Cleanup::CRON_HOOK)) {
            // Runs at 3am EST (8am UTC)
            wp_schedule_event(
                date('U', strtotime('tomorrow') + 3600 * 8),
                'daily',
                Cleanup::CRON_HOOK
            );
        }
    }


    public static function setCaching(int $level): void
    {
        self::$cacheLevel = max(self::$cacheLevel, $level);
    }


    /**
     * Spit out headers that prevent caching.  Useful for API calls.
     */
    public static function doCacheHeaders(int $cacheLevel = null): void
    {
        if ($cacheLevel !== null) {
            self::setCaching($cacheLevel);
        }

        switch (self::$cacheLevel) {
            case self::CACHE_NONE:
                header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
                header("Cache-Control: post-check=0, pre-check=0", false);
                header("Pragma: no-cache");
                break;
            case self::CACHE_PRIVATE:
                header("Cache-Control: max-age=300, must-revalidate, private");
                break;
        }
    }


    public static function postHeadersAndFiltering(): string
    {
        header('Content-Type: application/json');
        TouchPointWP::doCacheHeaders(TouchPointWP::CACHE_NONE);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['error' => 'Only POST requests are allowed.']);
            exit;
        }

        $inputData = file_get_contents('php://input');
        if ($inputData[0] !== '{') {
            echo json_encode(['error' => 'Invalid data provided.']);
            exit;
        }

        return $inputData;
    }

    /**
     * @param bool      $continue   Whether to parse the request
     * @param WP        $wp         Current WordPress environment instance
     * @param array|string $extraVars Passed query variables
     *
     * @return bool Whether other request parsing functions should be allowed to function.
     *
     * @noinspection PhpMissingParamTypeInspection
     * @noinspection PhpUnusedParameterInspection WordPress API
     */
    public function parseRequest($continue, $wp, $extraVars): bool
    {
        if ($continue) {
            $reqUri = parse_url(trim($_SERVER['REQUEST_URI'], '/'));
            $reqUri['path'] = $reqUri['path'] ?? "";

            // Remove trailing slash if it exists (and, it probably does)
            if (substr($reqUri['path'], -1) === '/')
                $reqUri['path'] = substr($reqUri['path'], 0, -1);

            // Explode by slashes
            $reqUri['path'] = explode("/", $reqUri['path'] ?? "");

            // Skip requests that categorically don't match
            if (count($reqUri['path']) < 2 || strtolower($reqUri['path'][0]) !== self::API_ENDPOINT) {
                return $continue;
            }

            // Parse parameters
            parse_str($reqUri['query'] ?? '', $queryParams);
            $reqUri['query'] = $queryParams;
            unset($queryParams);

            self::$context = "api";

            // App Events Endpoint
            if ($reqUri['path'][1] === TouchPointWP::API_ENDPOINT_APP_EVENTS &&
                TouchPointWP::useTribeCalendar()
            ) {

                if (!EventsCalendar::api($reqUri)) {
                    return $continue;
                }
            }

            // Involvement endpoint
            if ($reqUri['path'][1] === TouchPointWP::API_ENDPOINT_INVOLVEMENT &&
                $this->settings->enable_involvements === "on"
            ) {
                if (!Involvement::api($reqUri)) {
                    return $continue;
                }
            }

            // Global Partner endpoint
            if ($reqUri['path'][1] === TouchPointWP::API_ENDPOINT_GLOBAL &&
                $this->settings->enable_global === "on"
            ) {
                if (!Partner::api($reqUri)) {
                    return $continue;
                }
            }

            // Person endpoint
            if ($reqUri['path'][1] === TouchPointWP::API_ENDPOINT_PERSON) {
                if (!Person::api($reqUri)) {
                    return $continue;
                }
            }

            // Meeting endpoints
            if ($reqUri['path'][1] === TouchPointWP::API_ENDPOINT_MEETING) {
                if (!Meeting::api($reqUri)) {
                    return $continue;
                }
            }

            // Admin endpoints
            if ($reqUri['path'][1] === TouchPointWP::API_ENDPOINT_ADMIN) {
                self::admin(); // initialize the instance.
                if (!TouchPointWP_AdminAPI::api($reqUri)) {
                    return $continue;
                }
            }

            // Cleanup endpoints
            if ($reqUri['path'][1] === TouchPointWP::API_ENDPOINT_CLEANUP) {
                if (!Cleanup::api($reqUri)) {
                    return $continue;
                }
            }

            // Geolocate via IP TODO rework to minimize extra requests
            if ($reqUri['path'][1] === TouchPointWP::API_ENDPOINT_GEOLOCATE &&
                count($reqUri['path']) === 2) {

                $this->ajaxGeolocate();
            }
        }

        self::$context = "";
        return $continue;
    }

    /**
     * Whether the current user is an admin.
     *
     * @return bool
     */
    public static function currentUserIsAdmin(): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * Filter to add a tp_post_type option to get_terms that takes either a string of one post type or an array of post
     * types.
     *
     * @param $clauses
     * @param $taxonomy
     * @param $args
     *
     * Hat tip https://dfactory.eu/wp-how-to-get-terms-post-type/
     *
     * @return mixed
     * @noinspection PhpUnusedParameterInspection  WordPress API
     */
    public function getTermsClauses($clauses, $taxonomy, $args): array
    {
        if ( isset( $args[self::HOOK_PREFIX . 'post_type'] ) && ! empty( $args[self::HOOK_PREFIX . 'post_type'] ) && $args['fields'] !== 'count' ) {
            global $wpdb;

            $post_types = [];

            if ( is_array( $args[self::HOOK_PREFIX . 'post_type'] ) ) {
                foreach ( $args[self::HOOK_PREFIX . 'post_type'] as $cpt ) {
                    $post_types[] = "'" . $cpt . "'";
                }
            } else {
                $post_types[] = "'" . $args[self::HOOK_PREFIX . 'post_type'] . "'";
            }

            if ( ! empty( $post_types ) ) {
                $clauses['fields'] = 'DISTINCT ' . str_replace( 'tt.*', 'tt.term_taxonomy_id, tt.taxonomy, tt.description, tt.parent', $clauses['fields'] ) . ', COUNT(p.post_type) AS count';
                $clauses['join'] .= ' LEFT JOIN ' . $wpdb->term_relationships . ' AS r ON r.term_taxonomy_id = tt.term_taxonomy_id LEFT JOIN ' . $wpdb->posts . ' AS p ON p.ID = r.object_id';
                $clauses['where'] .= ' AND (p.post_type IN (' . implode( ',', $post_types ) . ') OR (tt.parent = 0 AND tt.count = 0))';
                $clauses['orderby'] = 'GROUP BY t.term_id ' . $clauses['orderby'];
            }
        }
        return $clauses;
    }

    /**
     * Print Dynamic Instantiation scripts.
     *
     * @return void
     */
    public function printDynamicFooterScripts(): void
    {
        if (self::isApi())
            return;

        echo "<script defer id=\"TP-Dynamic-Instantiation\">\n";
        if (Person::useJsInstantiation()) {
            echo Person::getJsInstantiationString();
        }
        if ($this->involvements !== null) {
            echo Involvement::getJsInstantiationString();
        }
        if ($this->global !== null) {
            echo Partner::getJsInstantiationString();
        }
        echo "\n</script>";
    }

    /**
     * Force some styling overrides into admin views.
     *
     * @return void
     */
    public function adminPrintStyleOverrides(): void
    {
        if (is_admin()) {
            $screen = get_current_screen();

            echo "<style>";

            // Hide Bio field if it's likely to be loaded from TouchPoint
            if ($screen->base === "user-edit" && $this->settings->people_ev_bio !== '') { // We're on the user-edit page.
                global $user_id;
                if ($user_id !== null) {
                    $peopleId = get_user_meta($user_id, Person::META_PEOPLEID, true);
                    if ($peopleId !== null && $peopleId !== '') {
                        // There is a PeopleId, which means Bio can be imported. Therefore, hide the bio.
                        echo "table.form-table tr.user-description-wrap {display: none;}";
                    }
                }
            }

            echo file_get_contents(TouchPointWP::$dir . "/assets/template/admin-style.css");

            echo "</style>";
        }
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

    /**
     * Compare the version numbers to determine if a migration is needed.
     */
    public function checkMigrations(): void
    {
        if ($this->settings->version !== self::VERSION) {
            $this->settings->migrate();
        }
    }

    /**
     * Load the settings, connect the references, and check that there aren't pending migrations.
     *
     * @param $file
     *
     * @return TouchPointWP
     */
    public static function load($file): TouchPointWP
    {
        $instance = self::instance($file);

        if (is_null($instance->settings)) {
            $instance->settings = TouchPointWP_Settings::instance($instance);
            $instance->checkMigrations();
        }

        // Load Auth tool if enabled.
        if ($instance->settings->enable_authentication === "on") {
            if (!TOUCHPOINT_COMPOSER_ENABLED) {
                require_once 'Auth.php';
            }
            $instance->auth = Auth::load($instance);
        }

        // Load RSVP tool if enabled.
        if ($instance->settings->enable_rsvp === "on") {
            if (!TOUCHPOINT_COMPOSER_ENABLED) {
                require_once 'Rsvp.php';
            }
            $instance->rsvp = Rsvp::load();
        }

        // Load Involvements tool if enabled.
        if ($instance->settings->enable_involvements === "on") {
            if (!TOUCHPOINT_COMPOSER_ENABLED) {
                require_once 'Involvement.php';
            }
            $instance->involvements = Involvement::load();
        }

        // Load Global Partners tool if enabled.
        if ($instance->settings->enable_global === "on") {
            if (!TOUCHPOINT_COMPOSER_ENABLED) {
                require_once 'Partner.php';
            }
            $instance->global = Partner::load();
        }

        // Load Person for People Indexes.
        if ($instance->settings->enable_people_lists === "on") {
            if (!TOUCHPOINT_COMPOSER_ENABLED) {
                require_once 'Person.php';
            }
            $instance->people = Person::load();
        }

        // Load Events if enabled (by presence of Events Calendar plugin)
        if (self::useTribeCalendar()
            && ! class_exists("tp\TouchPointWP\EventsCalendar")) {
            if (!TOUCHPOINT_COMPOSER_ENABLED) {
                require_once 'EventsCalendar.php';
            }
        }

        add_action('init', [self::class, 'init']);

        return $instance;
    }

    public static function init(): void
    {
        self::instance()->registerTaxonomies();

        // If any slugs have changed, flush.  Only executes if already enqueued.
        self::instance()->flushRewriteRules();

        // If the scripts need to be updated, do that.
        self::instance()->updateDeployedScripts();

        self::requireScript("base");
    }

    public static function renderBaseInlineScript(): void
    {
        echo "<script type=\"text/javascript\" id=\"base-inline\">";
        echo file_get_contents(self::instance()->assets_dir . '/js/base-inline.js');
        echo "</script>";
    }

    public function registerScriptsAndStyles(): void
    {
        // Register scripts that exist for all modules.
        wp_register_script(
            self::SHORTCODE_PREFIX . 'base',
            '',
            [],
            self::VERSION,
            false
        );

        wp_register_script(
            self::SHORTCODE_PREFIX . 'base-defer',
            $this->assets_url . 'js/base-defer.js',
            [self::SHORTCODE_PREFIX . 'base'],
            self::VERSION,
            true
        );

        wp_register_script(
            self::SHORTCODE_PREFIX . 'swal2-defer',
            "//cdn.jsdelivr.net/npm/sweetalert2@10",
            [],
            self::VERSION,
            true
        );

        wp_register_script(
            self::SHORTCODE_PREFIX . "knockout-defer",
            "https://ajax.aspnetcdn.com/ajax/knockout/knockout-3.5.0.js",
            [],
            '3.5.0',
            true
        );

        wp_register_script(
            self::SHORTCODE_PREFIX . "select2-defer",
            "https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js",
            ['jquery'],
            '4.1.0',
            true
        );

        wp_register_script(
            TouchPointWP::SHORTCODE_PREFIX . "googleMaps",
            sprintf(
                "https://maps.googleapis.com/maps/api/js?key=%s&v=3&libraries=geometry",
                TouchPointWP::instance()->settings->google_maps_api_key
            ),
            [TouchPointWP::SHORTCODE_PREFIX . "base-defer"],
            null,
            true
        );

        wp_register_script(
            TouchPointWP::SHORTCODE_PREFIX . "fontAwesome",
            "https://kit.fontawesome.com/2b5f44e07f.js",
            [],
            6, // When changing versions, some CSS references will need to be updated, too.
            false
        );

        if ( ! ! $this->involvements) {
            Involvement::registerScriptsAndStyles();
        }

        if ( ! ! $this->global) {
            Partner::registerScriptsAndStyles();
        }

//        if ( ! ! $this->auth) {
//            Auth::registerScriptsAndStyles();
//        }

        if ( ! ! $this->rsvp) {
            Meeting::registerScriptsAndStyles();
        }
    }

    private static array $enqueuedScripts = [];

    /**
     * Enqueue TouchPoint Scripts.  Also, always adds Base if it hasn't been added yet.
     *
     * @param ?string $name
     */
    public static function requireScript(string $name = null): void
    {
        if (!apply_filters(TouchPointWP::HOOK_PREFIX . "include_script_" . strtolower($name), true)){
            return;
        }

        if ( ! in_array("base", self::$enqueuedScripts)) {
            self::$enqueuedScripts[] = "base";
            if (is_admin()) {
                add_action('admin_head', [self::class, "renderBaseInlineScript"]);
            } else {
                add_action('wp_head', [self::class, "renderBaseInlineScript"]);
            }
        }

        if ($name !== "base") {
            if ( ! in_array($name, self::$enqueuedScripts)) {
                self::$enqueuedScripts[] = $name;
                wp_enqueue_script(TouchPointWP::SHORTCODE_PREFIX . $name);
            }
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

    // TODO move to somewhere more conducive to the API data model. (Utilities, probably?)
    public function ajaxGeolocate(): void
    {
        header('Content-Type: application/json');
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            echo json_encode(['error' => 'Only GET requests are allowed.']);
            exit;
        }

        // TODO validate that request is only coming from allowed referrers... or something like that.

        echo json_encode($this->geolocate());

        exit;
    }

    /**
     * @param bool $useApi Set false to only use cached data, and not the IP API.
     *
     * @return stdClass|false An object with a 'lat' and 'lng' attribute, if a location could be identified. Or, false if not available.
     */
    public function geolocate(bool $useApi = true)
    {
        $ip = null; // For future use as a parameter, potentially.

        // Determine IP if one was not provided.
        if (!is_string($ip) || $ip === '') {
            /** @noinspection SpellCheckingInspection */
            $ipHeaderKeys = [
                'HTTP_CLIENT_IP',
                'HTTP_X_FORWARDED_FOR',
                'HTTP_X_FORWARDED',
                'HTTP_FORWARDED_FOR',
                'HTTP_FORWARDED',
                'REMOTE_ADDR'
            ];
            foreach ($ipHeaderKeys as $k) {
                if ( ! empty($_SERVER[$k]) && filter_var($_SERVER[$k], FILTER_VALIDATE_IP)) {
                    $ip = $_SERVER[$k];
                    break;
                }
            }
        }

        // If no IP, we can't go any further.
        if ($ip === '' || $ip === null) {
            return false;
        }

        try {
            $return = $this->getIpData($ip, $useApi);
        } catch (TouchPointWP_WPError|TouchPointWP_Exception $ex) {
            return false;
        }

        if ($return === false || !is_string($return)) {
            return false;
        }

        $d = json_decode($return);
        if (!is_object($d)) {
            new TouchPointWP_Exception("Geolocation Object is Invalid", 178004);
            return false;
        }

        if (property_exists($d, 'error')) {
            new TouchPointWP_Exception("Geolocation Error: " . $d->reason ?? "", 178002);
            return false;
        }

        if (!isset($d->latitude) || !isset($d->longitude) || !isset($d->city)) {
            new TouchPointWP_Exception("Geolocation Data Not Provided", 178003);
            return false;
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
     * @param string $ip The IP address to lookup
     * @param bool   $useApi If false, this won't query the API and will only used cached results.
     *
     * @return string|false The JSON data.  False if not available
     * @throws TouchPointWP_WPError    For API Errors
     * @throws TouchPointWP_Exception  For Errors reported by API
     */
    protected function getIpData(string $ip, bool $useApi = true)
    {
        $ip_pton = inet_pton($ip);

        // TODO allow admin to define some static IPs and corresponding locations

        global $wpdb;
        $tableName = $wpdb->base_prefix . self::TABLE_IP_GEO;
        /** @noinspection SqlResolve */
        $q = $wpdb->prepare("SELECT * FROM $tableName WHERE ip = %s and updatedDt > (NOW() - INTERVAL 30 DAY)", $ip_pton);
        $cache = $wpdb->get_row($q);
        if ($cache) {
            return $cache->data;
        }

        if (! $useApi) {
            return false;
        }

        $return = self::instance()->extGet("https://ipapi.co/" . $ip . "/json/"); // Exceptions thrown here.

        $return = $return['body'];

        if (property_exists($return, 'error')) {
            throw new TouchPointWP_Exception("IP Geolocation Error: " . $return->error . " " . $return->reason ?? "", 178001);
        }

        /** @noinspection SqlResolve */
        $q = $wpdb->prepare( "
            INSERT INTO $tableName (ip, updatedDt, data)
            VALUES ('$ip_pton', NOW(), %s) ON DUPLICATE KEY UPDATE updatedDt = NOW(), data = %s;",
        $return, $return);
        $wpdb->query($q);

        return $return;
    }

    public function registerTaxonomies(): void
    {
        // Resident Codes
        $resCodeTypesToApply = [];
        if ($this->settings->enable_involvements === "on") {
            $resCodeTypesToApply = Involvement_PostTypeSettings::getPostTypesWithGeoEnabled();
        }
        $resCodeTypesToApply[] = 'user';
        register_taxonomy(
            self::TAX_RESCODE,
            $resCodeTypesToApply,
            [
                'hierarchical'      => false,
                'show_ui'           => false,
                'description'       => __('Classify posts by their general locations.'),
                'labels'            => [
                    'name'          => $this->settings->rc_name_plural,
                    'singular_name' => $this->settings->rc_name_singular,
                    'search_items'  => __('Search ' . $this->settings->rc_name_plural),
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
            if ($rc->name !== null && !Utilities::termExists($rc->name, self::TAX_RESCODE)) {
                Utilities::insertTerm(
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
        $divisionTypesToApply = [];
        if ($this->settings->enable_involvements === "on") {
            $divisionTypesToApply = Involvement_PostTypeSettings::getPostTypes();
        }
        // TODO allow this taxonomy to be applied to other post types as an option.
        if (count($divisionTypesToApply) > 0) {
            register_taxonomy(
                self::TAX_DIV,
                $divisionTypesToApply,
                [
                    'hierarchical'      => true,
                    'show_ui'           => true,
                    'description'       => sprintf(__('Classify things by %s.'), $this->settings->dv_name_singular),
                    'labels'            => [
                        'name'          => $this->settings->dv_name_plural,
                        'singular_name' => $this->settings->dv_name_singular,
                        'search_items'  => __('Search ' . $this->settings->dv_name_plural),
                        'all_items'     => __('All ' . $this->settings->dv_name_plural),
                        'edit_item'     => __('Edit ' . $this->settings->dv_name_singular),
                        'update_item'   => __('Update ' . $this->settings->dv_name_singular),
                        'add_new_item'  => __('Add New ' . $this->settings->dv_name_singular),
                        'new_item_name' => __('New ' . $this->settings->dv_name_singular . ' Name'),
                        'menu_name'     => $this->settings->dv_name_plural,
                    ],
                    'public'            => true,
                    'show_in_rest'      => true,
                    'show_admin_column' => false,

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
                    $pTermInfo = Utilities::termExists($d->pName, self::TAX_DIV, 0);
                    if ($pTermInfo === null && $d->pName !== null) {
                        $pTermInfo = Utilities::insertTerm(
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
                    $dTermInfo = Utilities::termExists($d->dName, self::TAX_DIV, $pTermInfo['term_id']);
                    if ($dTermInfo === null && $d->dName !== null) {
                        $dTermInfo = Utilities::insertTerm(
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
                    // Remove terms that are disabled from importing.

                    // Delete disabled divisions.  Get program, so we delete the right division.
                    $pTermInfo = Utilities::termExists($d->pName, self::TAX_DIV, 0);
                    if ($pTermInfo !== null) {
                        $dTermInfo = Utilities::termExists($d->dName, self::TAX_DIV, $pTermInfo['term_id']);
                        if ($dTermInfo !== null) {
                            wp_delete_term($dTermInfo['term_id'], self::TAX_DIV);
                            self::queueFlushRewriteRules();
                        }
                    }

                    // Program
                    // TODO remove programs that no longer have a division selected for use as a term.
                    // TODO remove program & div terms that are no longer present in TouchPoint
                }
            }
        }

        if ($this->settings->enable_involvements === "on") {

            // Weekdays
            register_taxonomy(
                self::TAX_WEEKDAY,
                Involvement_PostTypeSettings::getPostTypes(),
                [
                    'hierarchical'      => false,
                    'show_ui'           => false,
                    'description'       => __('Classify involvements by the day on which they meet.'),
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
                $name = Utilities::getPluralDayOfWeekNameForNumber($di);
                if (!Utilities::termExists($name, self::TAX_WEEKDAY)) {
                    Utilities::insertTerm(
                        $name,
                        self::TAX_WEEKDAY,
                        [
                            'description' => $name,
                            'slug'        => Utilities::getDayOfWeekShortForNumber($di)
                        ]
                    );
                    self::queueFlushRewriteRules();
                }
            }

            // Tenses
            register_taxonomy(
                self::TAX_TENSE,
                Involvement_PostTypeSettings::getPostTypes(),
                [
                    'hierarchical'      => false,
                    'show_ui'           => false,
                    'description'       => __('Classify involvements by tense (present, future, past)'),
                    'labels'            => [
                        'name'          => __('Tenses'),
                        'singular_name' => __('Tense'),
                        'search_items'  => __('Search Tenses'),
                        'all_items'     => __('All Tenses'),
                        'edit_item'     => __('Edit Tense'),
                        'update_item'   => __('Update Tense'),
                        'add_new_item'  => __('Add New Tense'),
                        'new_item_name' => __('New Tense'),
                        'menu_name'     => __('Tenses'),
                    ],
                    'public'            => true,
                    'show_in_rest'      => false,
                    'show_admin_column' => false,

                    // Control the slugs used for this taxonomy
                    'rewrite'           => [
                        'slug'         => 'tense',
                        'with_front'   => false,
                        'hierarchical' => false
                    ],
                ]
            );
            foreach ([
                TouchPointWP::TAX_TENSE_FUTURE => __('Upcoming'),
                TouchPointWP::TAX_TENSE_PRESENT => __('Current'),
                TouchPointWP::TAX_TENSE_PAST => __('Past'),
            ] as $slug => $name) {
                if (!Utilities::termExists($slug, self::TAX_TENSE)) {
                    Utilities::insertTerm(
                        $name,
                        self::TAX_TENSE,
                        [
                            'description' => $name,
                            'slug'        => $slug
                        ]
                    );
                    self::queueFlushRewriteRules();
                }
            }
        }

        // Time of Day
        if ($this->settings->enable_involvements === "on") {
            /** @noinspection SpellCheckingInspection */
            register_taxonomy(
                self::TAX_DAYTIME,
                Involvement_PostTypeSettings::getPostTypes(),
                [
                    'hierarchical'      => false,
                    'show_ui'           => false,
                    'description'       => __('Classify involvements by the portion of the day in which they meet.'),
                    'labels'            => [
                        'name'          => __('Times of Day'),
                        'singular_name' => __('Time of Day'),
                        'search_items'  => __('Search Times of Day'),
                        'all_items'     => __('All Times of Day'),
                        'edit_item'     => __('Edit Time of Day'),
                        'update_item'   => __('Update Time of Day'),
                        'add_new_item'  => __('Add New Time of Day'),
                        'new_item_name' => __('New Time of Day'),
                        'menu_name'     => __('Times of Day'),
                    ],
                    'public'            => true,
                    'show_in_rest'      => true,
                    'show_admin_column' => true,

                    // Control the slugs used for this taxonomy
                    'rewrite'           => [
                        'slug'         => 'timeofday',
                        'with_front'   => false,
                        'hierarchical' => false
                    ],
                ]
            );
            $timesOfDay = [
                __('Late Night'),
                __('Early Morning'),
                __('Morning'),
                __('Midday'),
                __('Afternoon'),
                __('Evening'),
                __('Night')
            ];
            foreach ($timesOfDay as $tod) {
                if (!Utilities::termExists($tod, self::TAX_WEEKDAY)) {
                    $slug = str_replace(" ", "", $tod);
                    $slug = strtolower($slug);
                    Utilities::insertTerm(
                        $tod,
                        self::TAX_DAYTIME,
                        [
                            'description' => $tod,
                            'slug'        => $slug
                        ]
                    );
                    self::queueFlushRewriteRules();
                }
            }
            for ($di = 0; $di < 7; $di++) {
                $name = Utilities::getPluralDayOfWeekNameForNumber($di);
                if (!Utilities::termExists($name, self::TAX_WEEKDAY)) {
                    Utilities::insertTerm(
                        $name,
                        self::TAX_WEEKDAY,
                        [
                            'description' => $name,
                            'slug'        => Utilities::getDayOfWeekShortForNumber($di)
                        ]
                    );
                    self::queueFlushRewriteRules();
                }
            }
        }


        // Age Groups
        $ageGroupTypesToApply = [];
        if ($this->settings->enable_involvements === "on") {
            $ageGroupTypesToApply = Involvement_PostTypeSettings::getPostTypes();
        }
        $ageGroupTypesToApply[] = 'user';
        register_taxonomy(
            self::TAX_AGEGROUP,
            $ageGroupTypesToApply,
            [
                'hierarchical' => false,
                'show_ui' => false,
                'description' => __( 'Classify involvements and users by their age groups.' ),
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
            ]
        );
        foreach (["20s", "30s", "40s", "50s", "60s", "70+"] as $ag) {
            if (!Utilities::termExists($ag, self::TAX_AGEGROUP)) {
                Utilities::insertTerm(
                    $ag,
                    self::TAX_AGEGROUP,
                    [
                        'description' => $ag,
                        'slug'        => sanitize_title($ag)
                    ]
                );
                self::queueFlushRewriteRules();
            }
        }


        // Involvement Marital Status
        if ($this->settings->enable_involvements === "on") {
            register_taxonomy(
                self::TAX_INV_MARITAL,
                Involvement_PostTypeSettings::getPostTypes(),
                [
                    'hierarchical'      => false,
                    'show_ui'           => false,
                    'description'       => __('Classify involvements by whether participants are mostly single or married.'),
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
                if (!Utilities::termExists($ms, self::TAX_INV_MARITAL)) {
                    Utilities::insertTerm(
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

        // Global Partner Category
        if ($this->settings->enable_global === "on") {
            $tax = $this->settings->global_primary_tax;
            if ($tax === "" || $tax === null) {
                unregister_taxonomy(self::TAX_GP_CATEGORY);
            } elseif (count($this->getFamilyEvFields([$tax])) > 0) {
                $tax = $this->getFamilyEvFields([$tax])[0];
                $plural = $tax->field . "s"; // Sad, but works.  i18n someday.
                register_taxonomy(
                    self::TAX_GP_CATEGORY,
                    Partner::POST_TYPE,
                    [
                        'hierarchical'      => false,
                        'show_ui'           => false,
                        'description'       => __('Classify Partners by category chosen in settings.'),
                        'labels'            => [
                            'name'          => $tax->field,
                            'singular_name' => $plural,
                            'search_items'  => sprintf(__('Search %s.'), $plural),
                            'all_items'     => sprintf(__('All %s.'), $plural),
                            'edit_item'     => sprintf(__('Edit %s.'), $tax->field),
                            'update_item'   => sprintf(__('Update %s.'), $tax->field),
                            'add_new_item'  => sprintf(__('Add New %s.'), $tax->field),
                            'new_item_name' => sprintf(__('New %s.'), $tax->field),
                            'menu_name'     => $plural
                        ],
                        'public'            => true,
                        'show_in_rest'      => true,
                        'show_admin_column' => true,

                        // Control the slugs used for this taxonomy
                        'rewrite'           => [
                            'slug'         => self::TAX_GP_CATEGORY,
                            'with_front'   => false,
                            'hierarchical' => false
                        ],
                    ]
                );
                // Terms are inserted on sync.
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
        self::queueFlushRewriteRules();

        $this->createTables();

        $this->settings->migrate();
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

        wp_clear_scheduled_hook(Involvement::CRON_HOOK);
        wp_clear_scheduled_hook(Partner::CRON_HOOK);
        wp_clear_scheduled_hook(Person::CRON_HOOK);

        self::dropTables();
    }


    /**
     * Create or update database tables
     */
    protected function createTables(): void
    {
        global $wpdb;
        /** @noinspection PhpIncludeInspection */
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

    /**
     * Indicates that Tribe Calendar Pro is enabled.
     *
     * @return bool
     */
    public static function useTribeCalendarPro(): bool
    {
        if ( ! function_exists( 'is_plugin_active' ) ){
            /** @noinspection PhpIncludeInspection */
            require_once(ABSPATH . '/wp-admin/includes/plugin.php' );
        }

        return is_plugin_active( 'events-calendar-pro/events-calendar-pro.php');
    }

    /**
     * Indicates that Tribe Calendar is enabled.
     *
     * @return bool
     */
    public static function useTribeCalendar(): bool
    {
        return self::useTribeCalendarPro() || is_plugin_active( 'the-events-calendar/the-events-calendar.php');
    }

    /**
     * Sort a list of hierarchical terms into a list in which each parent is immediately followed by its children.
     *
     * @param WP_Term[] $terms
     * @param bool      $noChildlessParents
     *
     * @return WP_Term[]
     */
    public static function orderHierarchicalTerms(array $terms, bool $noChildlessParents = false): array
    {
        $lineage = [[]];
        foreach ($terms as $t) {
            if (! isset($lineage[$t->parent])) {
                $lineage[$t->parent] = [];
            }
            $lineage[$t->parent][] = $t;
        }

        // Remove parents that have no children
        if ($noChildlessParents) {
            foreach ($lineage[0] as $i => $term) {
                if ( ! isset($lineage[$term->term_id])) {
                    unset($lineage[0][$i]);
                }
            }
        }

        usort($lineage[0], fn($a, $b) => strcasecmp($a->name, $b->name));

        $out = [];
        foreach ($lineage[0] as $t) {
            $out[] = $t;
            if (isset($lineage[$t->term_id])) {
                usort($lineage[$t->term_id], fn($a, $b) => strcasecmp($a->name, $b->name));

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
     * @param string[] $divisions
     *
     * @return array
     */
    public function getMemberTypesForDivisions(array $divisions = []): array
    {
        $divisions = implode(",", $divisions);
        $divisions = str_replace('div','', $divisions);

        $mtObj = $this->settings->get('meta_memberTypes');
        $needsUpdate = false;
        $divKey = "div" . str_replace(",", "_", $divisions);

        if ($mtObj === false) {
            $needsUpdate = true;
            $mtObj = (object)[];
        } else {
            $mtObj = json_decode($mtObj);
            if (!isset($mtObj->$divKey)) {
                $needsUpdate = true;
            } else if (strtotime($mtObj->$divKey->_updated) < time() - 3600 * self::CACHE_TTL || ! is_array($mtObj->$divKey->memTypes)) {
                $needsUpdate = true;
            }
        }

        if ($needsUpdate) {  // potentially move to cron
            $mts = $this->getMemberTypesForDivisions_fromApi($divisions);
            if ($mts !== false) {
                $mtObj->$divKey = $mts; // If the API update fails, don't overwrite existing value.  Existing is probably better than nothing.
                $this->settings->set('meta_memberTypes', json_encode($mtObj));
            } else {
                return [];
            }
        }

        return $mtObj->$divKey->memTypes;
    }

    /**
     * Format the list of divisions into an array with form-name-friendly IDs as the key.
     *
     * @return string[]
     */
    public function getDivisionsAsKVArray(): array
    {
        return self::flattenArrayToKV($this->getDivisions(), 'id', 'name', 'div');
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
            if (strtotime($divsObj->_updated) < time() - 3600 * self::CACHE_TTL || ! is_array($divsObj->divs)) {
                $needsUpdate = true;
            }
        }

        // Get update if needed.
        if ($needsUpdate) {
            $update = $this->updateDivisions();
            if ($update !== false) { // If it didn't work, we'll return the non-updated thing.
                $divsObj = $update;
            }
        }

        return $divsObj->divs;
    }

    /**
     * @return false|object Update the divisions if they're stale.
     */
    private function updateDivisions()
    {
        try {
            $data = $this->apiGet('Divisions');
        } catch (TouchPointWP_Exception $e) {
            return false;
        }

        $obj = (object)[
            '_updated' => date('c'),
            'divs'     => $data->divs
        ];

        $this->settings->set("meta_divisions", json_encode($obj));

        return $obj;
    }


    /**
     * @return false|object Get new MemberTypes for a Division.  Does not cache them.
     */
    private function getMemberTypesForDivisions_fromApi($divisions)
    {
        try {
            $return = $this->apiGet('MemTypes', ['divs' => $divisions]);
        } catch (TouchPointWP_Exception $e) {
            return false;
        }

        return (object)[
            '_updated' => date('c'),
            'memTypes'     => $return->memTypes
        ];
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
            if (strtotime($rcObj->_updated) < time() - 3600 * self::CACHE_TTL || ! is_array($rcObj->resCodes)) {
                $needsUpdate = true;
            }
        }

        // Get update if needed.
        if ($needsUpdate) {
            $update = $this->updateResCodes();
            if ($update !== false) {
                $rcObj = $update;
            }
        }

        if ($rcObj === false) {
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
            $data = $this->apiGet('ResCodes');
        } catch (TouchPointWP_Exception $e) {
            return false;
        }

        if ( ! is_array($data->resCodes)) {
            return false;
        }

        $obj = (object)[
            '_updated' => date('c'),
            'resCodes'     => $data->resCodes
        ];

        $this->settings->set("meta_resCodes", json_encode($obj));

        return $obj;
    }


    /**
     * Returns an array of objects that correspond to the genders.  Each Gender has a name and an id.
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
            if (strtotime($gObj->_updated) < time() - 3600 * self::CACHE_TTL || ! is_array($gObj->genders)) {
                $needsUpdate = true;
            }
        }

        // Get update if needed.
        if ($needsUpdate) {
            $update = $this->updateGenders();
            if ($update !== false) {
                $gObj = $update;
            }
        }

        if ($gObj === false) {
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
            $data = $this->apiGet('Genders');
        } catch (TouchPointWP_Exception $e) {
            return false;
        }

        if ( ! is_array($data->genders)) {
            return false;
        }

        $obj = (object)[
            '_updated' => date('c'),
            'genders'     => $data->genders
        ];

        $this->settings->set("meta_genders", json_encode($obj));

        return $obj;
    }

    /**
     * Returns an array of objects that correspond to keywords.  Each Keyword has a name and an id.
     *
     * @returns object[]
     */
    public function getKeywords(): array
    {
        $kObj = $this->settings->get('meta_keywords');

        $needsUpdate = false;
        if ($kObj === false || $kObj === null) {
            $needsUpdate = true;
        } else {
            $kObj = json_decode($kObj);
            if (strtotime($kObj->_updated) < time() - 3600 * self::CACHE_TTL || ! is_array($kObj->keywords)) {
                $needsUpdate = true;
            }
        }

        // Get update if needed.
        if ($needsUpdate) {
            $update = $this->updateKeywords();
            if ($update !== false) {
                $kObj = $update;
            }
        }

        // If update failed, show a notice on the admin interface.
        if ($kObj === false) {
            return [];
        }

        return $kObj->keywords;
    }

    /**
     * Format the list of divisions into an array with form-name-friendly IDs as the key.
     *
     * @return string[]
     */
    public function getKeywordsAsKVArray(): array
    {
        return self::flattenArrayToKV($this->getKeywords(), 'id', 'name', 'key');
    }

    /**
     * Returns an array of objects that correspond to Person Extra Value Fields.  Each has a name and a type.
     *
     * @param array|null $matches Provide an array of items that should be matched. If provided, only matches are returned.
     *
     * @return array
     */
    public function getPersonEvFields(?array $matches = null): array
    {
        $pevObj = $this->settings->get('meta_personEvFields');

        $needsUpdate = false;
        if ($pevObj === false || $pevObj === null) {
            $needsUpdate = true;
        } else {
            $pevObj = json_decode($pevObj);
            if (strtotime($pevObj->_updated) < time() - 3600 * self::CACHE_TTL || ! is_array($pevObj->personEvFields)) {
                $needsUpdate = true;
            }
        }

        // Get update if needed.
        if ($needsUpdate) {
            $update = $this->updatePersonEvFields();
            if ($update !== false) {
                $pevObj = $update;
            }
        }

        if ($pevObj === false) {
            return [];
        }

        if ($matches !== null) {
            return array_values(
                array_filter(
                $pevObj->personEvFields,
                fn($f) => in_array($f->hash, $matches) ||
                          in_array($f->field . " | " . $f->type, $matches)
                )
            );
        }

        return $pevObj->personEvFields;
    }

    /**
     * Format the list of Person Extra Value Fields into an array with form-name-friendly IDs as the key.
     *
     * @param string|null $type     To limit the list to a particular data type, provide the name of the datatype.
     *                              Needs to match the type in TouchPoint.
     * @param bool        $addNone  Set true to add a "none" option to the list.
     *
     * @return string[]
     */
    public function getPersonEvFieldsAsKVArray(?string $type = null, bool $addNone = false): array
    {
        return self::standardizeExtraValuesForKVArray($this->getPersonEvFields(), $type, $addNone);
    }

    /**
     * Returns an array of objects that correspond to Family Extra Value Fields.  Each has a name and a type.
     *
     * @param array|null $matches Provide an array of items that should be matched. If provided, only matches are returned.
     *
     * @return array
     */
    public function getFamilyEvFields(?array $matches = null): array
    {
        $fevObj = $this->settings->get('meta_familyEvFields');

        $needsUpdate = false;
        if ($fevObj === false || $fevObj === null) {
            $needsUpdate = true;
        } else {
            $fevObj = json_decode($fevObj);
            if (strtotime($fevObj->_updated) < time() - 3600 * self::CACHE_TTL || ! is_array($fevObj->familyEvFields)) {
                $needsUpdate = true;
            }
        }

        // Get update if needed.
        if ($needsUpdate) {
            $update = $this->updateFamilyEvFields();
            if ($update !== false) {
                $fevObj = $update;
            }
        }

        // If update failed, show a notice on the admin interface.
        if ($fevObj === false) {
            return [];
        }

        if ($matches !== null) {
            return array_values(
                array_filter(
                $fevObj->familyEvFields,
                fn($f) => in_array($f->hash, $matches) ||
                          in_array($f->field . " | " . $f->type, $matches)
                )
            );
        }

        return $fevObj->familyEvFields;
    }

    /**
     * Format the list of Family Extra Value Fields into an array with form-name-friendly IDs as the key.
     *
     * @param string|null $type     To limit the list to a particular data type, provide the name of the datatype.
     *                              Needs to match the type in TouchPoint.
     * @param bool        $addNone  Set true to add a "none" option to the list.
     *
     * @return string[]
     */
    public function getFamilyEvFieldsAsKVArray(?string $type = null, bool $addNone = false): array
    {
        return self::standardizeExtraValuesForKVArray($this->getFamilyEvFields(), $type, $addNone);
    }


    /**
     * @param stdClass[] $fields
     * @param ?string    $type
     * @param bool       $addNone
     *
     * @return string[]
     */
    private static function standardizeExtraValuesForKVArray(array $fields, ?string $type = null, bool $addNone = false): array
    {
        $r = [];
        if ($addNone) {
            $r[''] = '';
        }
        if ($type !== null) {
            $type = strtolower(trim($type));
        }
        foreach ($fields as $ev) {
            if ($ev->field == '') { // Apparently blank EV Fields exist.
                continue;
            }
            if ($type === null) { // not filtered by type
                if ($ev->type === "") {
                    $ev->type = __("Unknown Type", TouchPointWP::TEXT_DOMAIN);
                }
                $r[$ev->hash] = $ev->field . " (" . $ev->type . ")";
            } elseif ($type === strtolower($ev->type)) {
                $r[$ev->hash] = $ev->field;
            }
        }

        return $r;
    }


    /**
     * Get the person that corresponds to the current user, if the current user is authenticated, and the user is associated with a PeopleId.
     *
     * @return Person|null
     */
    public static function currentUserPerson(): ?Person
    {
        $wpUserId = get_current_user_id();
        if ($wpUserId > 0) {
            return Person::fromId($wpUserId);
        }
        return null;
    }


    /**
     * Get a list of saved searches pertinent to the current user.  These are grouped by type: User's searches, Public, and Flags.
     *
     * Because these are user-specific and relatively volatile, they are NOT cached in WordPress.
     *
     * @return string[][]
     */
    public function getSavedSearches(?int $peopleId = null, $includeValue = null): array
    {
        if (intval($peopleId) === 0) {
            $peopleId = self::currentUserPerson()->peopleId;
        }

        try {
            $data = $this->apiGet('SavedSearches', ['PeopleId' => $peopleId]);
        } catch (TouchPointWP_Exception $e) {
            return [];
        }

        $r = [];
        foreach ((array)$data->savedSearches as $group => $sArr) {
            $kv = self::flattenArrayToKV($sArr, 'QueryId', 'Name');
            if ($includeValue != null && isset($kv[$includeValue])) { // required value is included
                $includeValue = null;
            }
            switch ($group) {
                case "user":
                    $r[__("Your Searches", TouchPointWP::TEXT_DOMAIN)] = $kv;
                    break;
                case "public":
                    $r[__("Public Searches", TouchPointWP::TEXT_DOMAIN)] = $kv;
                    break;
                case "flags":
                    $r[__("Status Flags", TouchPointWP::TEXT_DOMAIN)] = $kv;
                    break;
            }
        }
        if ($includeValue != null) {
            $r[__("Current Value", TouchPointWP::TEXT_DOMAIN)] = [
                $includeValue => __("Current Value", TouchPointWP::TEXT_DOMAIN)
            ];
        }

        return $r;
    }

    /**
     * @param array  $array
     * @param string $kField
     * @param string $vField
     * @param string $kPrefix
     *
     * @return array
     */
    public static function flattenArrayToKV(array $array, string $kField, string $vField, string $kPrefix = ''): array
    {
        $r = [];
        foreach ($array as $e) {
            $e = (array)$e;
            $r[$kPrefix . $e[$kField]] = $e[$vField];
        }
        return $r;
    }

    /**
     * @return false|object Update the keywords if they're stale.
     */
    private function updateKeywords()
    {
        try {
            $data = $this->apiGet('Keywords');
        } catch (TouchPointWP_Exception $e) {
            return false;
        }

        if ( ! property_exists($data, 'keywords') || ! is_array($data->keywords)) {
            return false;
        }

        usort($data->keywords, fn($a, $b) => strcasecmp($a->name, $b->name));

        $obj = (object)[
            '_updated' => date('c'),
            'keywords' => $data->keywords
        ];

        $this->settings->set("meta_keywords", json_encode($obj));

        return $obj;
    }

    /**
     * Update the Person Extra Values if they're stale.
     *
     * @return false|object False on failure
     */
    public function updatePersonEvFields()
    {
        try {
            $data = $this->apiGet('PersonEvFields');
        } catch (TouchPointWP_Exception $e) {
            return false;
        }

        if ( ! is_array($data->personEvFields)) {
            return false;
        }

        usort($data->personEvFields, fn($a, $b) => strcasecmp($a->field, $b->field));

        $obj = (object)[
            '_updated' => date('c'),
            'personEvFields' => $data->personEvFields
        ];

        $this->settings->set("meta_personEvFields", json_encode($obj));

        return $obj;
    }

    /**
     * Update the Person Extra Values if they're stale.
     *
     * @return false|object False on failure.
     */
    public function updateFamilyEvFields()
    {
        try {
            $data = $this->apiGet('FamilyEvFields');
        } catch (TouchPointWP_Exception $e) {
            return false;
        }

        if ( ! is_array($data->familyEvFields)) {
            return false;
        }

        usort($data->familyEvFields, fn($a, $b) => strcasecmp($a->field, $b->field));

        $obj = (object)[
            '_updated' => date('c'),
            'familyEvFields' => $data->familyEvFields
        ];

        $this->settings->set("meta_familyEvFields", json_encode($obj));

        return $obj;
    }

    /**
     * @param string $command The thing to get
     * @param ?array $parameters URL parameters to be added.
     * @param int    $timeout Amount of time in sec to wait before timing out.
     *
     * @return stdClass|array An array with headers, body, and other keys
     * Data is generally in json_decode($response['body'])->data
     *
     * @throws TouchPointWP_Exception Thrown if the API credentials are incomplete.
     */
    public function apiGet(string $command, ?array $parameters = null, int $timeout = 5)
    {
        if ( ! is_array($parameters)) {
            $parameters = (array)$parameters;
        }

        if (!$this->settings->hasValidApiSettings()) {
            throw new TouchPointWP_Exception(__("Invalid or incomplete API Settings.", TouchPointWP::TEXT_DOMAIN), 170001);
        }

        $parameters['a'] = $command;

        $host = $this->host();

        if ($host === TouchPointWP_Settings::UNDEFINED_PLACEHOLDER)
            throw new TouchPointWP_Exception(__('Host appears to be missing from TouchPoint-WP configuration.', 'TouchPoint-WP'), 170002);


        $r = $this->getHttpClient()->request($host . "/PythonApi/" .
            $this->settings->api_script_name . "?" . http_build_query($parameters),
            [
                'method'  => 'GET',
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode(
                            $this->settings->api_user . ':' . $this->settings->api_pass
                        )
                ],
                'timeout' => $timeout
            ]
        );

        return self::parseApiResponse($r);
    }

    /**
     * @param string $command The thing to post
     * @param mixed  $data Data to post
     * @param int    $timeout Amount of time in sec to wait before timing out.
     *
     * @return stdClass|array An object that corresponds to the Data python object in TouchPoint.
     * @throws TouchPointWP_Exception  If anything went wrong.
     */
    public function apiPost(string $command, $data = null, int $timeout = 5)
    {
        if (!$this->settings->hasValidApiSettings()) {
            throw new TouchPointWP_Exception(__("Invalid or incomplete API Settings.", TouchPointWP::TEXT_DOMAIN), 170001);
        }

        $host = $this->host();

        if ($host === TouchPointWP_Settings::UNDEFINED_PLACEHOLDER) {
            throw new TouchPointWP_Exception(
                __('Host appears to be missing from TouchPoint-WP configuration.', TouchPointWP::TEXT_DOMAIN), 170002
            );
        }

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
                'body' => ['data' => $data],
                'timeout' => $timeout
            ]
        );

        return self::parseApiResponse($r);
    }

    /**
     * @param $response
     *
     * @return stdClass|array
     * @throws TouchPointWP_Exception
     * @throws TouchPointWP_WPError
     */
    private static function parseApiResponse($response)
    {
        if ($response instanceof WP_Error) {
            throw new TouchPointWP_WPError($response);
        }

        $respDecoded = json_decode($response['body']);

        if ($respDecoded === null) {
            throw new TouchPointWP_Exception("Connection Error", 179000);
        }

        // Most likely the issue where a module import failed for no apparent reason.
        if (property_exists($respDecoded, 'output') &&
            strpos($respDecoded->output, "Traceback (most recent call last):") === 0) {
            throw new TouchPointWP_Exception("Script error: " . $respDecoded->output, 179001);
        }

        // Some other script error
        if (property_exists($respDecoded, 'output') && $respDecoded->output !== '') {
            throw new TouchPointWP_Exception("Script error: " . $respDecoded->output, 179002);
        }

        // Error caught by error handling within Python script
        if (property_exists($respDecoded, 'message') && $respDecoded->message !== '') {
            throw new TouchPointWP_Exception($respDecoded->message, 179003);
        }

        return $respDecoded->data;
    }

    /**
     * @param string $url The destination of the request.
     * @param ?array $parameters URL parameters to be added.
     *
     * @return array An array with headers, body, and other keys on failure.
     * @throws TouchPointWP_WPError If there's an issue.
     */
    public function extGet(string $url, ?array $parameters = null): array
    {
        if ( ! is_array($parameters)) {
            $parameters = (array)$parameters;
        }

        $r = $this->getHttpClient()->request(
            $url . "?" . http_build_query($parameters),
            [
                'method'  => 'GET'
            ]
        );

        if ($r instanceof WP_Error) {
            throw new TouchPointWP_WPError($r);
        }

        return $r;
    }

    /**
     * Submit a person query to TouchPoint with a structured array with the parameters.
     *
     * @param array $q
     * @param bool  $verbose
     * @param int   $timeout
     *
     * @return stdClass  The people objects are contained within ->people.
     * @throws TouchPointWP_Exception  Upon failure.
     */
    public function doPersonQuery(array $q, bool $verbose = false, int $timeout = 5): stdClass
    {
        $data = TouchPointWP::instance()->apiPost('people_get', $q, $timeout);
        // An exception may already be thrown.

        // Validate that the API returned something
        if (!isset($data->people) || (!is_array($data->people) && !is_object($data->people))) {
            throw new TouchPointWP_Exception(__("People Query Failed", self::TEXT_DOMAIN), 179004);
        }

        /** @noinspection PhpCastIsUnnecessaryInspection -- It actually is. */
        $data->people = (array)$data->people;

        if ($verbose) {
            echo "Found " . count($data->people) . " people or groups of people";
        }

        return $data;
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
     * Cause a script update on next load.
     */
    public static function queueUpdateDeployedScripts(): void
    {
        $_SESSION[TouchPointWP::SETTINGS_PREFIX . 'updateDeployedScriptsOnNextLoad'] = true;
    }

    /**
     * @param bool $force
     *
     * @return void
     * @see queueUpdateDeployedScripts
     */
    public function updateDeployedScripts(bool $force = false): void
    {
        if ( isset($_SESSION[TouchPointWP::SETTINGS_PREFIX . 'updateDeployedScriptsOnNextLoad']) || $force) {
            try {
                $this->settings->updateDeployedScripts();
            } catch (TouchPointWP_Exception $e) {
                TouchPointWP_AdminAPI::showError($e->getMessage());
            }
            unset($_SESSION[TouchPointWP::SETTINGS_PREFIX . 'updateDeployedScriptsOnNextLoad']);
        }
    }

    /**
     * Cause a flushing of rewrite rules on next load.
     */
    public static function queueFlushRewriteRules(): void
    {
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

    /**
     * This function enqueues the stylesheet for the default templates, to avoid registering the style on sites where
     * custom templates exist.
     */
    public static function enqueuePartialsStyle()
    {
        wp_enqueue_style(
            TouchPointWP::SHORTCODE_PREFIX . 'partials-template-style',
            self::instance()->assets_url . 'template/partials-template-style.css?v=' . TouchPointWP::VERSION,
            [],
            TouchPointWP::VERSION
        );
    }

    /**
     * This function enqueues the stylesheet for actions (swal, basically).
     *
     * @param string $action This string identifies which action is being used.  This should be passed to the filter so
     * the filter can make an informed decision about whether to exclude the stylesheet.
     */
    public static function enqueueActionsStyle(string $action): void
    {
        $includeActionsStyle = !!apply_filters(TouchPointWP::HOOK_PREFIX . "include_actions_style", true, $action);
        if ($includeActionsStyle) {
            wp_enqueue_style(
                TouchPointWP::SHORTCODE_PREFIX . 'actions-style',
                self::instance()->assets_url . 'template/actions-style.css?v=' . TouchPointWP::VERSION,
                [],
                TouchPointWP::VERSION
            );
        }
    }

    /**
     * Create a basic parameter array for doPersonQuery
     *
     * @return array
     *
     * @see doPersonQuery
     */
    public static function newQueryObject(): array
    {
        return [
            'pid' => [],
            'inv' => [],
            'src' => [],
            'meta' => [],
            'groupBy' => null,
            'context' => null
        ];
    }
}