<?php


namespace tp\TouchPointWP;

if ( ! defined('ABSPATH')) {
    exit(1);
}

use WP_Post;

require_once 'api.php';

abstract class EventsCalendar implements api
{
    /**
     * Print json for Events Calendar for Mobile app.
     *
     * @param array $params Parameters from the request to use for filtering or such.
     */
    protected static function echoAppList(array $params = [])
    {
        $eventsList = [];

        $params = array_merge(
            [
                'ends_after'                  => 'now',
                'event_display'               => 'custom',
                'total_per_page'              => 20,
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
                    "/:\/\/{$tpDomain}\/OnlineReg\/([\d]+)/i",
                    "://" . $dlDomain . '/registrations/register/${1}?from={{MOBILE_OS}}',
                    $content
                );
            }

            // TODO add setting for style url.  Possibly allow for a template.
            if ($content !== '' && TouchPointWP::instance()->settings->ec_use_standardizing_style === 'on') {
                $cssUrl = TouchPointWP::instance()->assets_url . 'template/ec-standardizing-style.css?v=' . TouchPointWP::VERSION;
                $content = "<link rel=\"stylesheet\" href=\"{$cssUrl}\">" . $content;
            }

            // Not needed for apps, but helpful for diagnostics
            $eO['ID'] = $eQ->ID;

            // Android (apparently not used on iOS?)
            $eO['all_day'] = tribe_event_is_all_day($eQ->ID);

            // Android
            $eO['image'] = get_the_post_thumbnail_url($eQ->ID, 'large');
            // iOS
            $eO['RelatedImageFileKey'] = $eO['image'] === false ? "" : $eO['image'];

            // iOS
            $eO['Description'] = str_replace("{{MOBILE_OS}}", "iOS", $content);
            // Android
            $eO['content'] = str_replace("{{MOBILE_OS}}", "android", $content);

            // iOS
            $eO['Subject'] = $eQ->post_title;
            // Android
            $eO['title'] = $eQ->post_title;

            // iOS
            $eO['StartDateTime'] = tribe_get_start_date($eQ->ID,false,'Y-m-d\TH:i:s\Z');
            // Android
            $eO['start_date'] = tribe_get_start_date($eQ->ID,true,'c');

            // iOS
            $eO['Location'] = $locationContent;
            // Android
            $eO['room'] = $locationContent;

            $eventsList[] = $eO;
        }

        header('Content-Type: application/json');

        echo json_encode($eventsList);
    }

    public static function api(array $uri): bool
    {
        EventsCalendar::echoAppList($uri['query']);

        exit;
    }
}
