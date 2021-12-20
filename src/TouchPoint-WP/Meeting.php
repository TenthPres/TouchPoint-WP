<?php


namespace tp\TouchPointWP;


use WP_Error;

abstract class Meeting implements api
{
    /**
     * Register scripts and styles to be used on display pages.
     */
    public static function registerScriptsAndStyles(): void
    {
        wp_register_script(
            TouchPointWP::SHORTCODE_PREFIX . 'meeting-defer',
            TouchPointWP::instance()->assets_url . 'js/meeting-defer.js',
            [TouchPointWP::SHORTCODE_PREFIX . 'base-defer'],
            TouchPointWP::VERSION,
            true
        );
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
        if (count($uri['path']) === 2) {
            self::ajaxGetMeetingInfo();
            exit;
        }

        switch (strtolower($uri['path'][2])) {
            case "rsvp":
                self::ajaxSubmitRsvps();
                exit;
        }

        return false;
    }

    /**
     * @param $opts
     *
     * @return object
     */
    private static function getMeetingInfo($opts): object
    {
        // TODO caching

        return TouchPointWP::instance()->apiPost('mtg', $opts);
    }

    /**
     * Handles the API call to get meetings, mostly to prep RSVP links.
     */
    private static function ajaxGetMeetingInfo(): void
    {
        header('Content-Type: application/json');
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            echo json_encode(['error' => 'Only GET requests are allowed.']);
            exit;
        }

        $data = self::getMeetingInfo($_GET);

        if ($data instanceof WP_Error) {
            echo json_encode(['error' => $data->get_error_message()]);
            exit;
        }

        echo json_encode(['success' => $data->success]);
        exit;
    }

    /**
     * Handles RSVP Submissions
     */
    private static function ajaxSubmitRsvps(): void
    {
        header('Content-Type: application/json');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['error' => 'Only POST requests are allowed.']);
            exit;
        }

        $inputData = file_get_contents('php://input');
        if ($inputData[0] !== '{') {
            echo json_encode(['error' => 'Invalid data provided.']);
            exit;
        }

        $data = TouchPointWP::instance()->apiPost('mtg_rsvp', json_decode($inputData));

        if ($data instanceof WP_Error) {
            echo json_encode(['error' => $data->get_error_message()]);
            exit;
        }

        echo json_encode(['success' => $data->success]);
        exit;
    }
}