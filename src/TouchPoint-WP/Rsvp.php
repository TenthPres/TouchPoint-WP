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
if ( ! defined('ABSPATH')) {
    Rsvp::handleApiRequest();
}


/**
 * The Auth-handling class.
 */
abstract class Rsvp
{
    public const SHORTCODE = TouchPointWP::SHORTCODE_PREFIX . "RSVP";

    private static bool $_isInitiated = false;

    public static function load(): bool
    {
        if (self::$_isInitiated) {
            return true;
        }

        self::$_isInitiated = true;

        if ( ! shortcode_exists(self::SHORTCODE)) {
            add_shortcode(self::SHORTCODE, [self::class, 'shortcode']);
        }

        // Register frontend JS & CSS.
        add_action('wp_register_scripts', [__CLASS__, 'registerScriptsAndStyles'], 10);

        // Enqueue scripts
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueueScripts']);

        return true;
    }

    public static function registerScriptsAndStyles()
    {
        wp_register_script(
            TouchPointWP::SHORTCODE_PREFIX . 'rsvp',
            TouchPointWP::instance()->assets_url . 'js/rsvp.js',
            [TouchPointWP::SHORTCODE_PREFIX . 'base'],
            TouchPointWP::VERSION,
            true
        );
    }

    public static function unregisterShortcode()
    {
        if (shortcode_exists(self::SHORTCODE)) {
            remove_shortcode(self::SHORTCODE);
        }
    }

    public static function enqueueScripts()
    {
        TouchPointWP::requireScript('rsvp');
    }

    /**
     * @param array  $params
     * @param string $content
     *
     * @return string
     */
    public static function shortcode(array $params, string $content): string
    {
        // standardize parameters
        $params = array_change_key_case($params, CASE_LOWER);

        // set some defaults
        $params  = shortcode_atts(
            [
                'class'     => 'TouchPoint-RSVP btn',
                'meetingid' => null
            ],
            $params,
            self::SHORTCODE
        );
        $content = $content === '' ? __("RSVP", TouchPointWP::TEXT_DOMAIN) : $content;

        // Verify that meeting ID is provided
        if ( ! isset($params['meetingid']) || ! is_numeric($params['meetingid'])) {
            _doing_it_wrong(
                __FUNCTION__,
                "A valid Meeting ID was not provided in the TP-RSVP shortcode.",
                TouchPointWP::VERSION
            );

            return "<!-- Can't add an RSVP link without a proper Meeting ID in a meetingId parameter. -->" . $content;
        }

        $meetingId = (int)($params['meetingid']);

        // get any nesting
        $content = do_shortcode($content);


        $href = TouchPointWP::instance()->host(
            ) . "/Meeting/" . $meetingId; // TODO consider options for referring to the registration instead.  Do not make API calls here.

        // create the link
        $content = "<a href=\"" . $href . "\" class=\"" . $params['class'] . "\" onclick=\"TouchPointWP.RSVP.btnClick(this)\" onmouseover=\"TouchPointWP.RSVP.preload(this)\" data-touchpoint-mtg=\"$meetingId\">$content</a>";

        return $content;
    }

    public static function handleApiRequest()
    {
        // TODO this.
//        wp_remote_post()
        TouchPointWP::getApiCredentials();
        echo json_encode($_GET);
    }
}