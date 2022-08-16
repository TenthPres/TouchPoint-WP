<?php
/**
 * @package TouchPointWP
 */

namespace tp\TouchPointWP;

if ( ! defined('ABSPATH')) {
    exit(1);
}

use WP_Post;

if (!TOUCHPOINT_COMPOSER_ENABLED) {
    require_once 'api.php';
}

/**
 * Event Calendar for the Mobile App
 */
abstract class EventsCalendar implements api
{
    protected static function generateEventsList(array $params = []): array
    {
        $eventsList = [];

        $params = array_merge(
            [
                'ends_after'                  => 'now',
                'event_display'               => 'custom',
                'total_per_page'              => 50,
                'hide_subsequent_recurrences' => true
            ],
            $params
        );

        $eventsQ = tribe_get_events($params);

        $usePro = TouchPointWP::useTribeCalendarPro();

        $tpDomain = TouchPointWP::instance()->settings->host;
        $dlDomain = TouchPointWP::instance()->settings->host_deeplink;

        foreach ($eventsQ as $eQ) {
            /** @var WP_Post $eQ */
            global $post;
            $post = $eQ;

            $eO = [];

            $locationContent = [];

            $location = trim(tribe_get_venue($eQ->ID));
            if ($location !== '') {
                $locationContent[] = $location;
            }
            if ($usePro && tribe_is_recurring_event($eQ->ID)) {
                $locationContent[] = __("Recurring", TouchPointWP::TEXT_DOMAIN);
            }
            if ($usePro && tribe_event_is_multiday($eQ->ID)) {
                $locationContent[] = __("Multi-Day", TouchPointWP::TEXT_DOMAIN);
            }
            $locationContent = implode(" • ", $locationContent);

            $content = trim(get_the_content(null, true, $eQ->ID));
            $content = apply_filters('the_content', $content);
            $content = apply_filters(TouchPointWP::HOOK_PREFIX . 'app_events_content', $content);

            $content = html_entity_decode($content);

            // Add Header and footer Scripts, etc.
            if ($content !== '') {
                ob_start();
                do_action('wp_print_styles');
                do_action('wp_print_head_scripts');
                $content = ob_get_clean() . $content;

                ob_start();
                do_action('wp_print_footer_scripts');
                do_action('wp_print_scripts');
                $content .= ob_get_clean();
            }

            // Add domain to relative links
            $content = preg_replace(
                "/['\"]\/([^\/\"']*)[\"']/i",
                '"' . get_home_url() . '/$1"',
                $content
            );

            // Replace TouchPoint links with deeplinks where applicable
            // Registration Links
            if ($tpDomain !== '' && $dlDomain !== '') {
                $content = preg_replace(
                    "/:\/\/$tpDomain\/OnlineReg\/([\d]+)/i",
                    "://" . $dlDomain . '/registrations/register/${1}?from={{MOBILE_OS}}',
                    $content
                );
            }

            if ($content !== '') {
                $cssUrl = null;
                if (TouchPointWP::instance()->settings->ec_use_standardizing_style === 'on') {
                    $cssUrl = TouchPointWP::instance(
                        )->assets_url . 'template/ec-standardizing-style.css?v=' . TouchPointWP::VERSION;
                }
                $cssUrl = apply_filters(TouchPointWP::HOOK_PREFIX . 'app_events_css_url', $cssUrl);
                if (is_string($cssUrl)) {
                    $content = "<link rel=\"stylesheet\" href=\"$cssUrl\">" . $content;
                }
            }

            // Not needed for apps, but helpful for diagnostics
            $eO['ID'] = $eQ->ID;

            // Android (apparently not used on iOS?)
            $eO['all_day'] = tribe_event_is_all_day($eQ->ID);

            // Android
            $eO['image'] = get_the_post_thumbnail_url($eQ->ID, 'large');
            // iOS
            $eO['RelatedImageFileKey'] = $eO['image'];

            // iOS
            $eO['Description'] = str_replace("{{MOBILE_OS}}", "iOS", $content);
            // Android
            $eO['content'] = str_replace("{{MOBILE_OS}}", "android", $content);

            // iOS
            $eO['Subject'] = $eQ->post_title;
            // Android
            $eO['title'] = $eQ->post_title;

            // iOS
            $eO['StartDateTime'] = tribe_get_start_date($eQ->ID, true, 'c');
            // Android
            $eO['start_date'] = $eO['StartDateTime'];

            // iOS
            $eO['Location'] = $locationContent;
            // Android
            $eO['room'] = $locationContent;

            $eventsList[] = $eO;
        }
        return $eventsList;
    }

    /**
     * Print json for Events Calendar for Mobile app.
     *
     * @param array $params Parameters from the request to use for filtering or such.
     */
    protected static function echoAppList(array $params = []): void
    {
        $eventsList = self::generateEventsList($params);

        header('Content-Type: application/json');

        echo json_encode($eventsList);
    }

    /**
     * Generate previews of the HTML generated for the App Events Calendar
     *
     * This is wildly inefficient since each iframe will calculate the full list.
     */
    protected static function previewAppList(array $params = []): void
    {
        $eventsList = self::generateEventsList($params);

        foreach ($eventsList as $i => $eo) {
            echo "<h2>{$eo['title']}</h2>";
            $url = get_site_url() . "/" .
                   TouchPointWP::API_ENDPOINT . "/" .
                   TouchPointWP::API_ENDPOINT_APP_EVENTS . "/" .$i;
            echo "<iframe src='$url' style='width:500px; height:500px;'></iframe>";
        }
    }

    protected static function previewAppListItem(array $params = [], int $item = 0): void
    {
        $eventsList = self::generateEventsList($params);

        echo $eventsList[$item]['content'];
    }

    public static function api(array $uri): bool
    {
        if (count($uri['path']) === 2) {
            TouchPointWP::doCacheHeaders();  // Public? May as well, since there isn't any way to distinguish users.
            EventsCalendar::echoAppList($uri['query']);
            exit;
        }

        // Preview list
        if (count($uri['path']) === 3 &&
            strtolower($uri['path'][2]) === 'preview' &&
            TouchPointWP::currentUserIsAdmin()
        ) {
            EventsCalendar::previewAppList($uri['query']);
            exit;
        }

        // Preview items
        if (count($uri['path']) === 3 &&
            is_numeric($uri['path'][2]) &&
            TouchPointWP::currentUserIsAdmin()
        ) {
            EventsCalendar::previewAppListItem($uri['query'], intval($uri['path'][2]));
            exit;
        }

        return false;
    }
}