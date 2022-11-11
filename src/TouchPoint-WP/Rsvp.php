<?php
/**
 * @package TouchPointWP
 */
namespace tp\TouchPointWP;

// TODO sort out what goes here, and what goes in Meetings.

if ( ! defined('ABSPATH')) {
    exit(1);
}

if (!TOUCHPOINT_COMPOSER_ENABLED) {
    require_once 'Meeting.php';
}

/**
 * The RSVP framework class.
 */
abstract class Rsvp
{
    public const SHORTCODE = TouchPointWP::SHORTCODE_PREFIX . "RSVP";
    private static bool $_isLoaded = false;

    public static function load(): bool
    {
        if (self::$_isLoaded) {
            return true;
        }
        self::$_isLoaded = true;

        add_action(TouchPointWP::INIT_ACTION_HOOK, [self::class, 'init']);

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
        // standardize parameters
        $params = array_change_key_case($params, CASE_LOWER);

        // set some defaults
        $params  = shortcode_atts(
            [
                'class'     => 'TouchPoint-RSVP',
                'btnclass'  => 'btn button',
                'meetingid' => null,
                'preload'   => __("Loading...", TouchPointWP::TEXT_DOMAIN)
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
            return "<!-- Can't add an RSVP link without a proper Meeting ID in a meetingId parameter. -->" . $content;
        }

        $meetingId  = (int)($params['meetingid']);
        $preloadMsg = $params['preload'];

        // get any nesting
        $content = apply_shortcodes($content);

        // merge the two class parameters
        $class = trim($params['class'] . ' ' . $params['btnclass']);
        if ($class !== '') {
            $class = " class=\"$class\"";
        }

        // create the link
        if (TouchPointWP::isApi()) {
            global $post;
            $link = get_permalink($post);
            return "<a href=\"$link#tp-rsvp-m$meetingId\" $class><span class=\"rsvp-btn-content\">$content</span></a>";
        } else {
            TouchPointWP::requireScript('swal2-defer');
            TouchPointWP::requireScript('meeting-defer');
            TouchPointWP::enqueueActionsStyle('rsvp');
            Person::enqueueUsersForJsInstantiation();

            return "<a href=\"#\" onclick=\"return false;\" $class disabled data-tp-action=\"rsvp\" data-tp-mtg=\"$meetingId\"><span class=\"rsvp-btn-content\" style=\"display:none\">$content</span><span class=\"rsvp-btn-preload\">$preloadMsg</span></a>";
        }
    }

}