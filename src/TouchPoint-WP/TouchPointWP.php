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

    public const DEFAULT_IP_WHITELIST = "Leave blank unless you know what you're doing.";

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

        // Start session for those components that need it.
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

    public static function load($file)
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
    public static function generateAntiForgeryId(int $timeout)
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
    public static function AntiForgeryTimestampIsValid(string $afId, int $timeout)
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

    public static function getPluralDayOfWeekNameForNumber(int $dayNum)
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

    public static function getDayOfWeekShortForNumber(int $dayNum)
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

    public function registerScriptsAndStyles()
    {

        // Register scripts that exist for all modules
        wp_register_script(
            self::SHORTCODE_PREFIX . 'base-defer',
            $this->assets_url . 'js/base.js',
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