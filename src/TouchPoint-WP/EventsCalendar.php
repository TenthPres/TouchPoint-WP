<?php


namespace tp\TouchPointWP;

use WP_Post;

if ( ! defined('ABSPATH')) {
    exit(1);
}

abstract class EventsCalendar
{
    /**
     * @param array $params Parameters from the request to use for filtering or such.
     */
    static function echoAppList(array $params = [])
    {
        $eventsList = [];

        // TODO involve some level of parameterization.  Ministry?

        $eventsQ = tribe_get_events([
            'ends_after' => 'now',
            'event_display' => 'custom',
            'total_per_page'    => 99,
            'hide_subsequent_recurrences' => true
        ]);

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
            $locationContent = implode(" • ", $locationContent);

            $content = trim(get_the_content(null, true, $eQ->ID));
            $content = apply_filters( 'the_content', $content);
            $content = apply_filters( TouchPointWP::HOOK_PREFIX . 'app_events_content', $content);

            // Add domain to relative links
            $content = preg_replace(
                "/['\"]\/([^\/\"']*)[\"']/i",
                '"' . get_home_url() . '/$1"',
                $content
            );

            // Replace TouchPoint links with deeplinks where applicable
            // Registration Links
            if ($dlDomain !== '') {
                $content = preg_replace(
                    "/:\/\/{$tpDomain}\/OnlineReg\/([\d]+)/i",
                    "://" . $dlDomain . '/registrations/register/${1}',
                    $content
                );
            }

            // TODO add setting for style url.  Possibly allow for a template.
            $content .= "<link rel=\"stylesheet\" href=\"https://west.tenth.org/tp/style.css\">";

            // Not needed for apps, but helpful for diagnostics
            $eO['ID'] = $eQ->ID;

            // Android (apparently not used on iOS?)
            $eO['all_day'] = tribe_event_is_all_day($eQ->ID);

            // Android
            $eO['image'] = get_the_post_thumbnail_url($eQ->ID, 'large');
            // iOS
            $eO['RelatedImageFileKey'] = $eO['image'];

            // iOS
            $eO['Description'] = $content;
            // Android
            $eO['content'] = $content;

            // iOS
            $eO['Subject'] = $eQ->post_title;
            // Android
            $eO['title'] = $eQ->post_title;

            // iOS
            $eO['StartDateTime'] = tribe_get_start_date($eQ->ID);
            // Android
            $eO['start_date'] = $eO['StartDateTime'];

            // iOS
            $eO['Location'] = $locationContent;
            // Android
            $eO['room'] = $locationContent;

            $eventsList[] = $eO;
        }

        header('Content-Type: application/json');

        echo json_encode($eventsList);
    }
}