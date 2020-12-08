<?php

namespace tp\TouchPointWP;

use WP_Error;

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
    public ?TouchPointWP_Admin $admin = null;

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
    public string $dir;

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
     * @var ?Rsvp The RSVP object for the RSVP tool, if feature is enabled.
     */
    protected ?Rsvp $rsvp = null;

    /**
     * @var ?Auth The Auth object for the Authentication tool, if feature is enabled.
     */
    protected ?Auth $auth = null;

    /**
     * @var ?bool True after the Small Group feature is loaded.
     */
//    protected ?SmallGroup $smallGroup = null; // TODO standardize types
    protected ?bool $smallGroup = null;

    /**
     * @var ?\WP_Http Object for API requests.
     */
    private ?\WP_Http $httpClient = null;

    /**
     * Constructor function.
     *
     * @param string $file
     */
    public function __construct($file = '')
    {
        // Load plugin environment variables.
        $this->file = $file;
        $this->dir = dirname($this->file);
        $this->assets_dir = trailingslashit($this->dir) . 'assets';
        $this->assets_url = esc_url(trailingslashit(plugins_url('/assets/', $this->file)));

        $this->script_suffix = defined('SCRIPT_DEBUG') && SCRIPT_DEBUG ? '' : '.min';

        register_activation_hook($this->file, [$this, 'install']);

        // Register frontend JS & CSS.
        add_action('wp_register_scripts', [$this, 'registerScriptsAndStyles'], 10);

        // Load admin JS & CSS.
//		add_action( 'admin_enqueue_scripts', [$this, 'admin_enqueue_scripts'], 10, 1 ); // TODO restore?
//		add_action( 'admin_enqueue_scripts', [$this, 'admin_enqueue_styles'], 10, 1 ); // TODO restore?

        // Load API for generic admin functions.
        if (is_admin()) {
            $this->admin = new TouchPointWP_Admin();
        }

        // Handle localisation.
        $this->load_plugin_textdomain();
        add_action('init', [$this, 'load_localisation'], 0);

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
            $instance->Auth = Auth::load($instance);
        }

        // Load RSVP tool if enabled.
        if (get_option(self::SETTINGS_PREFIX . 'enable_rsvp') === "on") {
            $instance->Rsvp = Rsvp::load();
        }

        // Load Auth tool if enabled.
        if (get_option(self::SETTINGS_PREFIX . 'enable_small_groups') === "on") {
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
     * @return Object TouchPointWP instance
     * @see TouchPointWP()
     */
    public static function instance($file = '')
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

    public function registerScriptsAndStyles()
    {
        wp_register_script(
            self::SHORTCODE_PREFIX . 'base',
            $this->assets_url . 'js/base.js',   //
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
    public function host()
    {
        return "https://" . $this->settings->host;
    }

    public function getApiCredentials()
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
    public function getApiKey()
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
    public function replaceApiKey()
    {
        return $this->settings->set('api_secret_key', com_create_guid());
    }


    /**
     * Returns an array of objects that correspond to divisions.  Each Division has a name and an id.  The name is both the Program and Division.
     *
     * @returns object[]
     */
    public function getDivisions() {
        $divsObj = $this->settings->__get('meta_divisions');
        if ($divsObj === false) {
            $divsObj = $this->updateDivisions();
        } else {
            $divsObj = json_decode($divsObj);
        }
        if (true || strtotime($divsObj->_updated) < time() - 86400) {
            $divsObj = $this->updateDivisions();
        }
        return $divsObj->divs;
    }

    /**
     * Format the list of divisions into an array with form-name-friendly IDs as the key.
     *
     * @return string[]
     */
    public function getDivisionsAsKVArray() {
        $r = [];
        foreach ($this->getDivisions() as $d) {
            $r['div'.$d->id] = $d->name;
        }
        return $r;
    }

    /**
     * @return false|object Update the divisions if they're stale.
     */
    private function updateDivisions() {
        $return = $this->apiGet('Divisions');

        if ($return instanceof WP_Error)
            return false;

        $obj = (object)[
            '_updated' => date('c'),
            'divs' => json_decode($return['body'])->data->data
        ];

        $this->settings->set("meta_divisions", json_encode($obj));

        return $obj;
    }

    /**
     * @return \WP_Http|null
     */
    private function getHttpClient() {
        if ($this->httpClient === null)
            $this->httpClient = new \WP_Http();
        return $this->httpClient;
    }

    /**
     * @param string $command The thing to get
     * @param ?array $parameters URL parameters to be added.
     *
     * @return array|WP_Error An array with headers, body, and other keys, or WP_Error on failure.
     * Data is generally in json_decode($response['body'])->data->data
     */
    public function apiGet(string $command, ?array $parameters = null) {
        if (!is_array($parameters)) {
            $parameters = (array)$parameters;
        }

        $parameters['a'] = $command;

        return $this->getHttpClient()->request(
            "https://" . $this->settings->host . "/PythonApi/" . "WebApi" . "?" . http_build_query($parameters), // TODO make script name dynamic
            [
                'method' => 'GET',
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode(
                            $this->settings->api_user . ':' . $this->settings->api_pass
                        )
                ]
            ]
        );
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
}