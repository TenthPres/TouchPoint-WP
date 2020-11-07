<?php

namespace tp\TouchPointWP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Main plugin class.
 *
 * Class TouchPointWP
 * @package tp\TouchPointWP
 */
class TouchPointWP {

	/**
	 * Version number
	 */
	public const VERSION = "0.0.1";

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
	 * Constructor function.
	 *
	 * @param string $file
	 */
	public function __construct($file = '') {

		// Load plugin environment variables.
		$this->file       = $file;
		$this->dir        = dirname( $this->file );
		$this->assets_dir = trailingslashit( $this->dir ) . 'assets';
		$this->assets_url = esc_url( trailingslashit( plugins_url( '/assets/', $this->file ) ) );

		$this->script_suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		register_activation_hook( $this->file, [ $this, 'install' ] );

		// Register frontend JS & CSS.
		add_action( 'wp_register_scripts', [$this, 'registerScriptsAndStyles'] , 10 );

		// Load admin JS & CSS.
//		add_action( 'admin_enqueue_scripts', [$this, 'admin_enqueue_scripts'], 10, 1 ); // TODO restore?
//		add_action( 'admin_enqueue_scripts', [$this, 'admin_enqueue_styles'], 10, 1 ); // TODO restore?

		// Load API for generic admin functions.
		if ( is_admin() ) {
			$this->admin = new TouchPointWP_Admin();
		}

		// Handle localisation.
		$this->load_plugin_textdomain();
		add_action( 'init', [$this, 'load_localisation'], 0 );

        // Load Auth tool if enabled.
        if (get_option(self::SETTINGS_PREFIX . 'enable_authentication') === "on") {
            require_once 'Auth.php';
        }

		// Load RSVP tool if enabled.
        if (get_option(self::SETTINGS_PREFIX . 'enable_rsvp') === "on") {
            require_once 'Rsvp.php';
        }
	}

	public static function init($file) {
		$instance = self::instance($file);

		if ( is_null( $instance->settings ) ) {
			$instance->settings = TouchPointWP_Settings::instance( $instance );
		}

        // Load Auth tool if enabled.
        if (get_option(self::SETTINGS_PREFIX . 'enable_authentication') === "on") {
            $instance->Auth = Auth::init($instance);
        }

        // Load RSVP tool if enabled.
        if (get_option(self::SETTINGS_PREFIX . 'enable_rsvp') === "on") {
            $instance->Rsvp = Rsvp::init();
        }

		return $instance;
	}

	public function registerScriptsAndStyles() {
        wp_register_script(self::SHORTCODE_PREFIX . 'base',
                           $this->assets_url . 'js/base.js',   //
                           [],
                           self::VERSION, true);

        if (!!$this->auth) {
            Auth::registerScriptsAndStyles();
        }

        if (!!$this->rsvp) {
            Rsvp::registerScriptsAndStyles();
        }
    }

	/**
	 * Load plugin localisation
	 */
	public function load_localisation() {
		load_plugin_textdomain( self::TEXT_DOMAIN, false, dirname( plugin_basename( $this->file ) ) . '/lang/' );
	}

	/**
	 * Load plugin textdomain
	 */
	public function load_plugin_textdomain() {
		$locale = apply_filters( 'plugin_locale', get_locale(), self::TEXT_DOMAIN );

		load_textdomain( self::TEXT_DOMAIN, WP_LANG_DIR . '/' . self::TEXT_DOMAIN . '/' . self::TEXT_DOMAIN . '-' . $locale . '.mo' );
		load_plugin_textdomain( self::TEXT_DOMAIN, false, dirname( plugin_basename( $this->file ) ) . '/lang/' );
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
	public static function instance( $file = '') {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self( $file );
		}

		return self::$_instance;
	}

	/**
	 * Don't clone.
	 */
	public function __clone() {
		_doing_it_wrong( __FUNCTION__, esc_html( __( 'Cloning TouchPointWP is questionable.  Don\'t do it.') ), esc_attr( self::VERSION ) );
	}

	/**
	 * don't deserialize.
	 */
	public function __wakeup() {
		_doing_it_wrong( __FUNCTION__, esc_html( __( 'Deserializing TouchPointWP is questionable.  Don\'t do it.') ), esc_attr( self::VERSION ) );
	}

	/**
	 * Installation. Runs on activation.
	 */
	public function install() {
		$this->_log_version_number();
	}

    /**
     * @return string The URL of the TouchPoint instance.
     */
	public function host() {
        return "https://" . $this->settings->host;
    }

	/**
	 * Log the plugin version number.
	 */
	private function _log_version_number() {
		update_option(self::TOKEN . '_version', self::VERSION );
	}

	public function getApiCredentials() {
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
    public function getApiKey() {
        $k = $this->settings->__get('api_secret_key');
        if ($k === false) {
            $k = $this->replaceApiKey();
        }
        return $k;
    }

    /**
     * @return string
     */
    public function replaceApiKey() {
        return $this->settings->set('api_secret_key', com_create_guid());
    }

    /**
     * Get a random string with a timestamp on the end.
     *
     * @param int $timeout  How long the token should last.
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
}