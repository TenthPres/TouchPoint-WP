<?php
/**
 * @package TouchPointWP
 */
namespace tp\TouchPointWP\Utilities;

use tp\TouchPointWP\api;
use tp\TouchPointWP\ExtraValueHandler;
use tp\TouchPointWP\Partner;
use tp\TouchPointWP\Person;
use tp\TouchPointWP\TouchPointWP;
use tp\TouchPointWP\TouchPointWP_Exception;
use tp\TouchPointWP\TouchPointWP_Settings;

if ( ! defined('ABSPATH')) {
    exit(1);
}

/**
 * Cleanup Used for data cleanliness tasks.
 */
abstract class Cleanup implements api
{
    public const CRON_HOOK = TouchPointWP::HOOK_PREFIX . "cleanup_cron_hook";
    private const CACHE_TTL = 24 * 7; // How long things should live before they're cleaned up.  Hours.

    /**
     * Called by the cron task. (and also by ::api() )
     *
     * @see api()
     */
    public static function cronCleanup($verbose = false): void
    {
        try {
            self::cleanMemberTypes();
        } catch (\Exception $e) {
            if ($verbose) {
                echo $e->getMessage();
            }
        }

        try {
            self::cleanupPersonEVs();
        } catch (\Exception $e) {
            if ($verbose) {
                echo $e->getMessage();
            }
        }

        try {
            self::cleanupFamilyEVs();
        } catch (\Exception $e) {
            if ($verbose) {
                echo $e->getMessage();
            }
        }

        if ($verbose) {
            echo "Success";
        }
    }

    /**
     * Handle API requests - mostly, just a way to forcibly trigger the cleanup process
     *
     * @param array $uri The request URI already parsed by parse_url()
     *
     * @return bool False if endpoint is not found.  Should print the result.
     */
    public static function api(array $uri): bool
    {
        self::cronCleanup(true);
        exit;
    }

    /**
     * Clean up Member Types that have been cached for a while.
     *
     * @return ?bool True if cleaning was successful, False if cleaning failed, null if cleaning was not needed.
     */
    protected static function cleanMemberTypes(): ?bool
    {
        $mtObj = TouchPointWP_Settings::instance()->get('meta_memberTypes');
        $needsUpdate = false;

        if ($mtObj === false) {
            $needsUpdate = true;
            $mtObj = (object)[];
        } else {
            $mtObj = (array)json_decode($mtObj);
            foreach ($mtObj as $key => $val) {
                if (strtotime($val->_updated) < time() - 3600 * self::CACHE_TTL) {
                    $needsUpdate = true;
                    unset($mtObj[$key]);
                }
            }
            $mtObj = (object)$mtObj;
        }

        if ($needsUpdate) {
            return TouchPointWP_Settings::instance()->set('meta_memberTypes', json_encode($mtObj));
        }
        return null;
    }

    /**
     * Clean up Person Extra Values that are no longer intended for import.
     *
     * @return int Number of rows if successful (including 0).
     * @throws TouchPointWP_Exception
     */
    protected static function cleanupPersonEVs(): int
    {
        global $wpdb;
        $conditions = ["meta_key LIKE '" . Person::META_PEOPLE_EV_PREFIX . "%'"];
        foreach (TouchPointWP::instance()->getPersonEvFields(TouchPointWP::instance()->settings->people_ev_custom) as $field) {
            $name = Person::META_PEOPLE_EV_PREFIX . ExtraValueHandler::standardizeExtraValueName($field->field);
            $conditions[] = "meta_key <> '$name'";
        }
        $conditions = implode(" AND ", $conditions);

        $try = $wpdb->query("DELETE FROM `$wpdb->usermeta` WHERE $conditions");
        if ($try === false) {
            throw new TouchPointWP_Exception("Error encountered in Cleanup: ". $wpdb->last_error, 170003);
        }
        return $try;
    }


    /**
     * Clean up Family Extra Values from Posts that are no longer intended for import.
     *
     * @return int Number of rows if successful (including 0).
     * @throws TouchPointWP_Exception
     */
    protected static function cleanupFamilyEVs(): int
    {
        global $wpdb;
        $conditions = ["meta_key LIKE '" . Person::META_PEOPLE_EV_PREFIX . "%'"];
        foreach (TouchPointWP::instance()->getPersonEvFields(TouchPointWP::instance()->settings->global_fev_custom) as $field) {
            $name = Partner::META_FEV_PREFIX . ExtraValueHandler::standardizeExtraValueName($field->field);
            $conditions[] = "meta_key <> '$name'";
        }
        $conditions = implode(" AND ", $conditions);

        $try = $wpdb->query("DELETE FROM `$wpdb->postmeta` WHERE $conditions");
        if ($try === false) {
            throw new TouchPointWP_Exception("Error encountered in Cleanup: ". $wpdb->last_error, 170003);
        }
        return $try;
    }
}