<?php
/**
 * @package TouchPointWP
 */

namespace tp\TouchPointWP\Interfaces;

if ( ! defined('ABSPATH')) {
	exit(1);
}


/**
 * Interface to standardize items that are updated with Cron.
 */
interface updatesViaCron
{
	/**
	 * Check to see if a cron run is needed, and run it if so.  Connected to an init function.
	 *
	 * @return void
	 */
	public static function checkUpdates(): void;

	/**
	 * Run the updating cron task.  Fail quietly to not disturb the visitor experience if using WP default cron
	 * handling.
	 *
	 * @return void
	 */
	public static function updateCron(): void;
}