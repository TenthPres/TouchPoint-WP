<?php
/**
 * @package TouchPointWP
 */
namespace tp\TouchPointWP;

if ( ! defined('ABSPATH')) {
    exit(1);
}

if (!TOUCHPOINT_COMPOSER_ENABLED) {
    require_once 'api.php';
}

use Exception;
use tp\TouchPointWP\Utilities\Http;

/**
 * Handle meeting content, particularly RSVPs.
 */
abstract class Meeting implements api
{
    /**
     * Register scripts and styles to be used on display pages.
     */
    public static function registerScriptsAndStyles(): void
    {
		$i = TouchPointWP::instance();
        wp_register_script(
            TouchPointWP::SHORTCODE_PREFIX . 'meeting-defer',
            $i->assets_url . 'js/meeting-defer' . $i->script_ext,
            [TouchPointWP::SHORTCODE_PREFIX . 'base-defer', 'wp-i18n'],
            TouchPointWP::VERSION,
            true
        );
	    wp_set_script_translations(TouchPointWP::SHORTCODE_PREFIX . 'meeting-defer', 'TouchPoint-WP', $i->getJsLocalizationDir());
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
     * @throws TouchPointWP_Exception
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
            http_response_code(Http::METHOD_NOT_ALLOWED);
            echo json_encode([
                'error'      => 'Only GET requests are allowed.',
                'error_i18n' => __("Only GET requests are allowed.", 'TouchPoint-WP')
            ]);
            exit;
        }

        try {
            $data = self::getMeetingInfo($_GET);
        } catch (TouchPointWP_Exception $ex) {
            http_response_code(Http::SERVER_ERROR);
            echo json_encode(['error' => $ex->getMessage()]);
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
            http_response_code(Http::METHOD_NOT_ALLOWED);
            echo json_encode([
                'error'      => 'Only POST requests are allowed.',
                'error_i18n' => __("Only POST requests are allowed.", 'TouchPoint-WP')
            ]);
            exit;
        }

        $inputData = file_get_contents('php://input');
        if ($inputData[0] !== '{') {
            http_response_code(Http::BAD_REQUEST);
            echo json_encode([
                'error'      => 'Invalid data provided.',
                'error_i18n' => __("Invalid data provided.", 'TouchPoint-WP')
            ]);
            exit;
        }

        try {
            $data = TouchPointWP::instance()->apiPost('mtg_rsvp', json_decode($inputData));
        } catch (Exception $ex) {
            http_response_code(Http::SERVER_ERROR);
            echo json_encode(['error' => $ex->getMessage()]);
            exit;
        }

        echo json_encode(['success' => $data->success]);
        exit;
    }
}