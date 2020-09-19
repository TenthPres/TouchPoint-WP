<?php

namespace tp\TouchPointWP;
/**
 * RSVP class file.
 *
 * Class Rsvp
 * @package tp\TouchPointWP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The Auth-handling class.
 */
class Rsvp
{
    public const SHORTCODE = "TouchPoint-RSVP";

    public static function register() {
        if (! shortcode_exists(self::SHORTCODE))
            add_shortcode(self::SHORTCODE, 'tp\\TouchPointWP\\Rsvp::shortcode');
    }

    public static function unregister() {
        if (shortcode_exists(self::SHORTCODE))
            remove_shortcode(self::SHORTCODE);
    }

    public static function shortcode(array $params = [], string $content = "RSVP")
    {
        // standardize parameters
        $params = array_change_key_case($params, CASE_LOWER);

        // set some defaults
        $params = array_merge([
            'class' => 'TouchPoint-RSVP btn'
                              ], $params);

        // Verify that meeting ID is provided
        if (!isset($params['meetingid']) || !is_numeric($params['meetingid'])) {
            _doing_it_wrong(__FUNCTION__, "No Meeting ID was provided.", TouchPointWP::VERSION);
            return "<!-- Can't add an RSVP link without a proper Meeting ID in a meetingId parameter. -->" . $content;
        }

        // TODO verify that the meeting is real
        $orgId = 0; // TODO get real orgId.

        // get any nesting
        $content = do_shortcode($content);

        $href = TouchPointWP::instance()->host . "\\OnlineReg\\" . $orgId;

        // create the link
        $content = "<a href=\"" . $href . "\" class=\"" . $params['class'] . "\" onclick=\"TouchPointWP.RSVP.btnClick(this)\" data-load-library=\"TouchPoint-WP-RSVP\">" . $content . "</a>";

        return $content;
    }
}