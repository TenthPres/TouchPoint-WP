<?php

namespace tp\TouchPointWP;

use WP_Error;
use WP_Http;

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
    public const VERSION = "0.0.2";

    /**
     * The Token
     */
    public const TOKEN = "TouchPointWP";

    /**
     * Text domain for translation files
     */
    public const TEXT_DOMAIN = "TouchPoint-WP";

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
    public const TAX_AGEGROUP = self::HOOK_PREFIX . "agegroup";
    public const TAX_INV_MARITAL = self::HOOK_PREFIX . "inv_marital";

    /**
     * The singleton.
     */
    private static ?TouchPointWP $_instance = null;

    /**
     * The admin object.
     */
    public ?TouchPointWP_AdminAPI $admin = null;

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
    protected ?bool $smallGroup = null;

    /**
     * @var ?WP_Http Object for API requests.
     */
    private ?WP_Http $httpClient = null;

    /**
     * Constructor function.
     *
     * @param string $file
     */
    public function __construct($file = '')
    {
        // Load plugin environment variables.
        $this->file       = $file;
        self::$dir        = dirname($this->file);
        $this->assets_dir = trailingslashit(self::$dir) . 'assets';
        $this->assets_url = esc_url(trailingslashit(plugins_url('/assets/', $this->file)));

        $this->script_suffix = defined('SCRIPT_DEBUG') && SCRIPT_DEBUG ? '' : '.min';

        register_activation_hook($this->file, [$this, 'install']);

        // Register frontend JS & CSS.
        add_action('init', [$this, 'registerScriptsAndStyles'], 0);

        // Register AJAX Interfaces
        add_action('init', [$this, 'registerAjaxCommon'], 0);

        // Load admin JS & CSS.
//		add_action( 'admin_enqueue_scripts', [$this, 'admin_enqueue_scripts'], 10, 1 ); // TODO restore?
//		add_action( 'admin_enqueue_scripts', [$this, 'admin_enqueue_styles'], 10, 1 ); // TODO restore?

        // Load API for generic admin functions.
        if (is_admin()) {
            require_once 'TouchPointWP_AdminAPI.php';
            $this->admin = new TouchPointWP_AdminAPI();
        }

        // Handle localisation.
        $this->load_plugin_textdomain();
        add_action('init', [$this, 'load_localisation'], 0);

        // Start session if not started already.
        if (session_status() === PHP_SESSION_NONE)
            session_start();

        // Load Auth tool if enabled.
        if (get_option(self::SETTINGS_PREFIX . 'enable_authentication') === "on") {
            require_once 'Auth.php';
        }

        // Load RSVP tool if enabled.
        if (get_option(self::SETTINGS_PREFIX . 'enable_rsvp') === "on") {
            require_once 'Rsvp.php';
        }

        // Load Small Group tool if enabled.
        if (get_option(self::SETTINGS_PREFIX . 'enable_small_group') === "on") {
            require_once 'SmallGroup.php';
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

        // Load Auth tool if enabled.
        if (get_option(self::SETTINGS_PREFIX . 'enable_small_groups') === "on") {
            require_once 'SmallGroup.php';
            $instance->smallGroup = SmallGroup::load($instance);
        }

        return $instance;
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

        if ( ! ! $this->smallGroup) {
            SmallGroup::registerScriptsAndStyles();
        }
    }

    public function registerAjaxCommon(): void
    {
        add_action( 'wp_ajax_tp_ident', [$this, 'ajaxIdent'] ); // TODO un-hard-code tp_
        add_action( 'wp_ajax_nopriv_tp_ident', [$this, 'ajaxIdent'] );
        add_action( 'wp_ajax_tp_inv_add', [$this, 'ajaxInvJoin'] ); // TODO Move to Involvement?
        add_action( 'wp_ajax_nopriv_tp_inv_add', [$this, 'ajaxInvJoin'] );
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

        $response = $this->apiGet('ident', ['inputData' => $inputData]);

        if ($response instanceof WP_Error) {
            echo json_encode(['error' => 'An API error occurred.']);
            wp_die();
        }

        $people = json_decode($response['body'])->data->people ?? [];

        // TODO sync or queue sync of people

        $ret = [];
        foreach ($people as $p) {
            $p->lastName = $p->lastName[0] ? $p->lastName[0] . "." : "";
            unset($p->lastInitial);
            $ret[] = $p;
        }

        echo json_encode($ret);
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

        // TODO input validation of some kind...

        $response = $this->apiGet('ident', ['inputData' => $inputData]);

        if ($response instanceof WP_Error) {
            echo json_encode(['error' => 'An API error occurred.']);
            wp_die();
        }

        $data = json_decode($response['body'])->data ?? (object)[];

        echo json_encode($data); // TODO perhaps simplify
        wp_die();
    }

    public function registerTaxonomies(): void
    {
        // Resident Codes
        $resCodeTypesToApply = ['user'];
        if (get_option(TouchPointWP::SETTINGS_PREFIX . 'enable_small_groups') === "on") {
            $resCodeTypesToApply[] = SmallGroup::POST_TYPE;
        }
        register_taxonomy(self::TAX_RESCODE, $resCodeTypesToApply, [
            'hierarchical' => false,
            'show_ui' => false,
            'description' => __( 'Classify small groups and users by their general locations.' ),
            'labels' => [
                'name' => $this->settings->rc_name_plural,
                'singular_name' => $this->settings->rc_name_singular,
                'search_items' =>  __( 'Search ' . $this->settings->rc_name_plural ),
                'all_items' => __( 'All ' . $this->settings->rc_name_plural ),
                'edit_item' => __( 'Edit ' . $this->settings->rc_name_singular ),
                'update_item' => __( 'Update ' . $this->settings->rc_name_singular ),
                'add_new_item' => __( 'Add New ' . $this->settings->rc_name_singular ),
                'new_item_name' => __( 'New ' . $this->settings->rc_name_singular . ' Name' ),
                'menu_name' => $this->settings->rc_name_plural,
            ],
            'public' => true,
            'show_in_rest' => true,
            'show_admin_column' => true,

            // Control the slugs used for this taxonomy
            'rewrite' => [
                'slug' => $this->settings->rc_slug,
                'with_front' => false,
                'hierarchical' => false
            ],
        ]);
        foreach ($this->getResCodes() as $rc) {
            if (! term_exists($rc->name, self::TAX_RESCODE)) {
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


        // Involvement Marital Status TODO move to involvement?
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

    public static function getDayOfWeekNameForNumber(int $dayNum)
    {
        $names = [
            __('Sunday'),
            __('Monday'),
            __('Tuesday'),
            __('Wednesday'),
            __('Thursday'),
            __('Friday'),
            __('Saturday'),
        ];

        return $names[$dayNum % 7];
    }

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
     * Installation. Runs on activation.
     */
    public function install()
    {
        $this->_log_version_number();
    }

    /**
     * Log the plugin version number.
     */
    private function _log_version_number()
    {
        update_option(self::TOKEN . '_version', self::VERSION);
    }

    /**
     * @return string The URL of the TouchPoint instance.
     */
    public function host(): string
    {
        return "https://" . $this->settings->host;
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
        $k = $this->settings->__get('api_secret_key');
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
        $return = $this->apiGet('MemTypes', ['divs' => $divisions]);

        if ($return instanceof WP_Error) {
            return [];
        }

        $body = json_decode($return['body']);

        if (property_exists($body, "message") || ! is_array($body->data->memTypes)) {
            return []; // Todo log an error?
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
        $divsObj = $this->settings->__get('meta_divisions');

        $needsUpdate = false;
        if ($divsObj === false) {
            $needsUpdate = true;
        } else {
            $divsObj = json_decode($divsObj);
            if (strtotime($divsObj->_updated) < time() - 3600 * 2 || ! is_array($divsObj->divs)) {
                $needsUpdate = true;
            }
        }

        // Get update if needed.
        if ($needsUpdate) {
            $divsObj = $this->updateDivisions();
        }

        // If update failed, show a notice on the admin interface.
        if ($divsObj === false) {
            add_action('admin_notices', [$this->admin, 'Error_TouchPoint_API']);

            return [];
        }

        return $divsObj->divs;
    }

    /**
     * @return false|object Update the divisions if they're stale.
     */
    private function updateDivisions()
    {
        $return = $this->apiGet('Divisions');

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
        $rcObj = $this->settings->__get('meta_resCodes');

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
            add_action('admin_notices', [$this->admin, 'Error_TouchPoint_API']);

            return [];
        }

        return $rcObj->resCodes;
    }


    /**
     * @return false|object Update the resident codes if they're stale.
     */
    private function updateResCodes()
    {
        $return = $this->apiGet('ResCodes');

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
        $gObj = $this->settings->__get('meta_genders');

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
            add_action('admin_notices', [$this->admin, 'Error_TouchPoint_API']);

            return [];
        }

        return $gObj->genders;
    }


    /**
     * @return false|object Update the genders if they're stale.
     */
    private function updateGenders()
    {
        $return = $this->apiGet('Genders');

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
     */
    public function apiGet(string $command, ?array $parameters = null)
    {
        if ( ! is_array($parameters)) {
            $parameters = (array)$parameters;
        }

        $parameters['a'] = $command;

        return $this->getHttpClient()->request(
            "https://" . $this->settings->host . "/PythonApi/" .
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