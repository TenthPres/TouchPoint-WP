<?php


namespace tp\TouchPointWP;

use WP_Error;

/**
 * Class Person - Fundamental object meant to correspond to a Person in TouchPoint
 *
 * @package tp\TouchPointWP
 */
class Person extends \WP_User implements api
{
    public static function arrangeNamesForPeople($people): string
    {
        $people = self::groupByFamily($people);

        $familyNames = [];
        $comma = ', ';
        $and = ' & ';
        $useOxford = false;
        foreach($people as $family) {
            $fn = self::formatNamesForFamily($family);
            if (strpos($fn, ', ') !== false) {
                $comma     = '; ';
                $useOxford = true;
            }
            if (strpos($fn, ' & ') !== false) {
                $and = ' and ';
                $useOxford = true;
            }
            $familyNames[] = $fn;
        }
        $last = array_pop($familyNames);
        $str = implode($comma, $familyNames);
        if (count($familyNames) > 0) {
            if ($useOxford)
                $str .= trim($comma);
            $str .= $and;
        }
        $str .= $last;
        return $str;
    }

    protected static function formatNamesForFamily(array $family): string
    {
        if (count($family) < 1)
            return "";

        $standingLastName = $family[0]->lastName;
        $string = "";

        $first = true;
        foreach ($family as $p) {
            if ($standingLastName != $p->lastName) {
                $string .= " " . $standingLastName; // TODO name privacy options

                $standingLastName = $p->lastName;
            }

            if (!$first && count($family) > 1)
                $string  .= " & ";

            $string .= $p->goesBy;

            $first = false;
        }
        $string .= " " . $standingLastName; // TODO name privacy options

        $lastAmpPos = strrpos($string, " & ");
        return str_replace(" & ", ", ", substr($string, 0, $lastAmpPos)) . substr($string, $lastAmpPos);
    }

    public static function groupByFamily(array $people): array
    {
        $families = [];
        foreach ($people as $p) {
            $fid = intval($p->familyId);

            if (!array_key_exists($fid, $families))
                $families[$fid] = [];

            $families[$fid][] = $p;
        }
        return $families;
    }

    private static function ajaxIdent(): void
    {
        $inputData = TouchPointWP::postHeadersAndFiltering();

        $data = TouchPointWP::instance()->apiPost('ident', json_decode($inputData));

        if ($data instanceof WP_Error) {
            echo json_encode(['error' => $data->get_error_message()]);
            exit;
        }

        $people = $data->people ?? [];

        // TODO sync or queue sync of people

        $ret = [];
        foreach ($people as $p) {
            $p->lastName = $p->lastName[0] ? $p->lastName[0] . "." : "";
            unset($p->lastInitial);
            $ret[] = $p;
        }

        echo json_encode(['people' => $ret]);
        exit;
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
        if (count($uri['path']) < 3) {
            return false;
        }

        switch (strtolower($uri['path'][2])) {
            case "ident":
                TouchPointWP::doCacheHeaders(TouchPointWP::CACHE_NONE);
                self::ajaxIdent();
                exit;

            case "contact":
                self::ajaxContact();
                exit;
        }

        return false;
    }

    /**
     * Handles the API call to send a message through a contact form.
     */
    private static function ajaxContact(): void
    {
        $inputData = TouchPointWP::postHeadersAndFiltering();
        $inputData = json_decode($inputData);
        $inputData->keywords = [];

        // TODO get keywords from somewhere
        if (!!$settings || !$settings->contactKeywords) {
            $inputData->keywords = Utilities::idArrayToIntArray($settings->contactKeywords);
        }

        $data = TouchPointWP::instance()->apiPost('p_contact', $inputData);

        if ($data instanceof WP_Error) {
            echo json_encode(['error' => $data->get_error_message()]);
            exit;
        }

        echo json_encode(['success' => $data->success]);
        exit;
    }

    /**
     * Internal.  Sync person data from the API Object into WordPress objects.
     *
     * @param object $data
     *
     * @return bool
     */
    private static function syncPersonData(object $data): bool
    {
        // Get User ID if already exists

        // Else, create user

        // Sync metas
    }
}