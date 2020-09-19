<?php

namespace tp\TouchPointWP;
/**
 * RSVP class file.
 *
 * Class Rsvp
 * @package tp\TouchPointWP
 */

//if ( ! defined( 'ABSPATH' ) ) { // TODO restore
//    exit;
//}
if ( ! defined( 'ABSPATH' ) ) {
    Rsvp::handleApiRequest();
}


/**
 * The Auth-handling class.
 */
abstract class Rsvp
{
    public const SHORTCODE = TouchPointWP::SHORTCODE_PREFIX . "RSVP";

    private static bool $_isInitiated = false;

    public static function init()
    {
        if (self::$_isInitiated)
            return;

        self::$_isInitiated = true;

        self::registerShortcode();

        wp_register_script(TouchPointWP::SHORTCODE_PREFIX . 'rsvp',
                           TouchPointWP::instance()->assets_url . 'js/rsvp.js',
                           [TouchPointWP::SHORTCODE_PREFIX . 'base'],
                           TouchPointWP::VERSION, true);

        add_action('wp_enqueue_scripts', 'tp\\TouchPointWP\\Rsvp::enqueueScripts');
    }

    public static function registerShortcode()
    {
        if (! shortcode_exists(self::SHORTCODE))
            add_shortcode(self::SHORTCODE, 'tp\\TouchPointWP\\Rsvp::shortcode');
    }

    public static function unregisterShortcode()
    {
        if (shortcode_exists(self::SHORTCODE))
            remove_shortcode(self::SHORTCODE);
    }

    public static function enqueueScripts() {
        wp_enqueue_script(TouchPointWP::SHORTCODE_PREFIX . 'rsvp');
    }

    /**
     * @param array $params
     * @param string $content
     *
     * @return string
     */
    public static function shortcode(array $params, string $content)
    {

        // standardize parameters
        $params = array_change_key_case($params, CASE_LOWER);

        // set some defaults
        $params = shortcode_atts([
            'class' => 'TouchPoint-RSVP btn',
            'meetingid' => null
                              ], $params, self::SHORTCODE);
        $content = $content === '' ? __("RSVP", TouchPointWP::TEXT_DOMAIN) : $content;

        // Verify that meeting ID is provided
        if (!isset($params['meetingid']) || !is_numeric($params['meetingid'])) {
            _doing_it_wrong(__FUNCTION__, "No Meeting ID was provided.", TouchPointWP::VERSION);
            return "<!-- Can't add an RSVP link without a proper Meeting ID in a meetingId parameter. -->" . $content;
        }

        $meetingId = intval($params['meetingid']);

        // get any nesting
        $content = do_shortcode($content);



        $href = TouchPointWP::instance()->host . "\\Meeting\\" . $meetingId; // TODO consider options for refering to the registration instead.  Do not make API calls here.

        // create the link
        $content = "<a href=\"" . $href . "\" class=\"" . $params['class'] . "\" onclick=\"TouchPointWP.RSVP.btnClick(this)\" onmouseover=\"TouchPointWP.RSVP.preload(this)\" data-touchpoint-mtg=\"$meetingId\">$content</a>";

        return $content;
    }

    public static function handleApiRequest() {
        // TODO this.
//        wp_remote_post()
        TouchPointWP::getApiCredentials();
        echo json_encode($_GET);
    }
}