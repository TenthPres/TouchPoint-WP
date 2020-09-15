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

	/**  KURTZ remove?
	 * The plugin assets URL.
	 */
	public string $assets_url;

	/**
	 * Suffix for JavaScripts.
	 */
	public string $script_suffix;

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

		// Load frontend JS & CSS.
//		add_action( 'wp_enqueue_scripts', [$this, 'enqueue_styles'] , 10 ); // TODO restore?
//		add_action( 'wp_enqueue_scripts', [$this, 'enqueue_scripts'] , 10 ); // TODO restore?

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
	}

	public static function init() {
		$instance = self::instance( __FILE__ );

		if ( is_null( $instance->settings ) ) {
			$instance->settings = TouchPointWP_Settings::instance( $instance );
		}

		return $instance;
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
	 * don't unserialize.
	 */
	public function __wakeup() {
		_doing_it_wrong( __FUNCTION__, esc_html( __( 'Unserializing TouchPointWP is questionable.  Don\'t do it.') ), esc_attr( self::VERSION ) );
	}

	/**
	 * Installation. Runs on activation.
	 */
	public function install() {
		$this->_log_version_number();
	}

	/**
	 * Log the plugin version number.
	 */
	private function _log_version_number() {
		update_option(self::TOKEN . '_version', self::VERSION );
	}

}