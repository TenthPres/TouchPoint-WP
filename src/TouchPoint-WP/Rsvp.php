<?php

namespace tp\TouchPointWP;

// TODO sort out what goes here, and what goes in Meetings.

if ( ! defined('ABSPATH')) {
    exit(1);
}

use WP_Post; // TODO remove
use WP_Term;

require_once 'api.iface.php';
require_once 'Involvement.php';
require_once 'Meeting.php';


/**
 * RSVP class file.
 *
 * Class Rsvp
 * @package tp\TouchPointWP
 */


/**
 * The RSVP framework class.
 */
abstract class Rsvp implements api
{
    public const SHORTCODE = TouchPointWP::SHORTCODE_PREFIX . "RSVP";
    protected static TouchPointWP $tpwp;
    private static bool $_isInitiated = false;

    public static function load(TouchPointWP $tpwp): bool
    {
        if (self::$_isInitiated) {
            return true;
        }
        self::$_isInitiated = true;

        self::$tpwp = $tpwp;

        add_action('init', [self::class, 'init']);

        if ( ! shortcode_exists(self::SHORTCODE)) {
            add_shortcode(self::SHORTCODE, [self::class, "shortcode"]);
        }

        return true;
    }


    /**
     * Register stuff
     */
    public static function init(): void
    {
        // register post types

        // add filters
    }


    /**
     * Register scripts and styles to be used on display pages.
     */
    public static function registerScriptsAndStyles()
    {
        Meeting::registerScriptsAndStyles();
    }


    /**
     * @param array  $params
     * @param string $content
     *
     * @return string
     */
    public static function shortcode(array $params, string $content): string
    {
        TouchPointWP::requireScript('swal2-defer');
        TouchPointWP::requireScript('base-defer');

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

            return "<!-- Can't add an RSVP link without a proper Meeting ID in a mtgId parameter. -->" . $content;
        }

        TouchPointWP::requireScript('meeting-defer');

        $meetingId = (int)($params['meetingid']);

        // get any nesting
        $content = do_shortcode($content);


        $href = "#"; //TODO cleanup. //TouchPointWP::instance()->host() . "/Meeting/" . $mtgId;

        // create the link
        $content = "<a href=\"" . $href . "\" class=\"" . $params['class'] . "\" data-tp-action=\"rsvp\" data-tp-mtg=\"$meetingId\">$content</a>";

        return $content;
    }

    /**
     * Handle API requests
     *
     * @param array $uri The request URI already parsed by parse_url()
     *
     * @return bool False if endpoint is not found.  Should print the result.
     */
    public static function api(array $uri): bool
    {
        // todo do something.
        return false;
    }

}