<?php

namespace tp\TouchPointWP;

// TODO sort out what goes here, and what goes in Meetings.

if ( ! defined('ABSPATH')) {
    exit(1);
}

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
     * @param array  $params
     * @param string $content
     *
     * @return string
     *
     * TODO resolve how this works with AppEvents
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
                'meetingid' => null,
                'preload' => __("Loading...", TouchPointWP::TEXT_DOMAIN)
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

            // TODO alternatives to meetingid
            return "<!-- Can't add an RSVP link without a proper Meeting ID in a mtgId parameter. -->" . $content;
        }

        TouchPointWP::requireScript('meeting-defer');

        $meetingId = (int)($params['meetingid']);
        $preloadMsg = $params['preload'];

        // get any nesting
        $content = apply_shortcodes($content);

        // create the link
        return "<a href=\"#\" onclick=\"return false;\" class=\"" . $params['class'] . " disabled\" data-tp-action=\"rsvp\" data-tp-mtg=\"$meetingId\"><span class=\"rsvp-btn-content\" style=\"display:none\">$content</span><span class=\"rsvp-btn-preload\">$preloadMsg</span></a>";
    }

}