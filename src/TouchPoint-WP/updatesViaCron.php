<?php
/**
 * @package TouchPointWP
 */
namespace tp\TouchPointWP;

if ( ! defined('ABSPATH')) {
    exit(1);
}


/**
 * Interface to standardize items that are updated with Cron.
 */
interface updatesViaCron {
    public static function checkUpdates();

    public static function updateCron();
}