<?php


namespace tp\TouchPointWP;

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

        global $wpdb;

        $postmeta = $wpdb->postmeta;
        $posts    = $wpdb->posts;

        // TODO involve some level of parameterization.  Ministry?

        // TODO compare performance with a standard query for events.

        /** @noinspection SqlResolve */
        $qEvents = "
SELECT p.ID as ID,
       p.post_title as title,
       p.post_content as content,
       CAST(pm_s.meta_value AS DATETIME) as EventStartDateUTC,
       CAST(pm_t.meta_value AS DATETIME) as start_date,
       pm_ad.meta_value as all_day,
       NULL as recurrence
FROM {$posts} as p
         LEFT JOIN {$postmeta} AS pm_s ON p.ID = pm_s.post_id AND pm_s.meta_key = '_EventStartDateUTC'
         LEFT JOIN {$postmeta} AS pm_e ON p.ID = pm_e.post_id AND pm_e.meta_key = '_EventEndDateUTC'
         LEFT JOIN {$postmeta} AS pm_t ON p.ID = pm_t.post_id AND pm_t.meta_key = '_EventStartDate'
         LEFT JOIN {$postmeta} AS pm_ad ON p.ID = pm_ad.post_id AND pm_ad.meta_key = '_EventAllDay'
WHERE p.post_type = 'tribe_events' AND p.post_parent = 0
  AND CAST(pm_e.meta_value AS DATETIME) > NOW()

UNION

SELECT MIN(p.ID) as ID,
       p.post_title as title,
       p.post_content as content,
       MIN(CAST(pm_s.meta_value AS DATETIME)) as EventStartDateUTC,
       MIN(CAST(pm_t.meta_value AS DATETIME)) as start_date,
       pm_ad.meta_value as all_day,
       'Recurring' as recurrence
FROM {$posts} as p
         LEFT JOIN {$postmeta} AS pm_s ON p.ID = pm_s.post_id AND pm_s.meta_key = '_EventStartDateUTC'
         LEFT JOIN {$postmeta} AS pm_e ON p.ID = pm_e.post_id AND pm_e.meta_key = '_EventEndDateUTC'
         LEFT JOIN {$postmeta} AS pm_t ON p.ID = pm_t.post_id AND pm_t.meta_key = '_EventStartDate'
         LEFT JOIN {$postmeta} AS pm_ad ON p.ID = pm_ad.post_id AND pm_ad.meta_key = '_EventAllDay'
WHERE p.post_type = 'tribe_events' AND p.post_parent <> 0
  AND CAST(pm_e.meta_value AS DATETIME) > NOW()
  GROUP BY p.post_parent

ORDER BY EventStartDateUTC";

        $eventsQ = $wpdb->get_results($qEvents, ARRAY_A);

        $usePro = TouchPointWP::useTribeCalendarPro();

        $tpDomain = TouchPointWP::instance()->settings->host;
        $dlDomain = TouchPointWP::instance()->settings->host_deeplink;

        foreach ($eventsQ as $eQ) {
            $eO = [];

            $locationContent = [];

            $location = trim(tribe_get_venue($eQ['ID']));
            if ($location !== '') {
                $locationContent[] = $location;
            }
            if ($usePro && $eQ['recurrence'] !== null) {
                $locationContent[] = $eQ['recurrence'];
            }
            $locationContent = implode(" • ", $locationContent);


            $content = apply_filters( 'the_content', get_the_content(null, true, $eQ['ID']) );

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

            // Android (apparently not used on iOS?)
            $eO['all_day'] = ! ! ($eQ['all_day'] === 'yes');

            // Android
            $eO['image'] = get_the_post_thumbnail_url($eQ['ID'], 'large');
            // iOS
            $eO['RelatedImageFileKey'] = $eO['image'];

            // iOS
            $eO['Description'] = $content;
            // Android
            $eO['content'] = $content;

            // iOS
            $eO['Subject'] = $eQ['title'];
            // Android
            $eO['title'] = $eQ['title'];

            // iOS
            $eO['StartDateTime'] = $eQ['start_date'];
            // Android
            $eO['start_date'] = $eQ['start_date'];

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