<?php
/**
 * @package TouchPointWP
 */
namespace tp\TouchPointWP;

if ( ! defined('ABSPATH')) {
    exit(1);
}


/**
 * API Interface
 */
interface updatesViaCron {
    public static function checkUpdates();

    public static function updateCron();}