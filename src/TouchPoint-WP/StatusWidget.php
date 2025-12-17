<?php
/**
 * @package TouchPointWP
 */

namespace tp\TouchPointWP;

use DateTime;
use Exception;
use tp\TouchPointWP\Utilities\DateFormats;

if ( ! defined('ABSPATH')) {
	exit;
}

/**
 * Class StatusWidget
 *
 * Provides an admin dashboard widget that shows some basic stats about the plugin.
 *
 * @since 0.0.95
 *
 * @package tp\TouchPointWP
 */
abstract class StatusWidget
{
	/**
	 * Initialize the widget.
	 */
	public static function init(): void
	{
		add_action( 'wp_dashboard_setup', [self::class, 'dashboardSetup'] );
	}

	/**
	 * Set up the dashboard widget.
	 */
	public static function dashboardSetup(): void
	{
		wp_add_dashboard_widget('tpwp_status',
			esc_html__( 'TouchPoint-WP Status', 'TouchPoint-WP' ),
			[self::class, 'render']
		);
	}

	/**
	 * Convert a timestamp to a formatted string.
	 *
	 * @param mixed $timestamp The timestamp to convert.
	 *
	 * @return string The formatted string.
	 */
	protected static function timestampToFormated(mixed $timestamp): string
	{
		if (!is_numeric($timestamp)) {
			try {
				$timestamp = new DateTime($timestamp, wp_timezone());
			} catch (Exception) {
				return 'Never';
			}
		} else {
			try {
				$timestamp = new DateTime('@' . $timestamp, Utilities::utcTimeZone());

			} catch (Exception) {
				return 'Never';
			}
		}
		$timestamp->setTimezone(wp_timezone());

		return wp_sprintf(
			// translators: %1$s is the date(s), %2$s is the time(s).
			__('%1$s at %2$s', 'TouchPoint-WP'),
			DateFormats::DateStringFormattedShort($timestamp),
			DateFormats::TimeStringFormatted($timestamp)
		);
	}

	/**
	 * Print the widget content.
	 *
	 * @return void
	 */
	public static function render(): void
	{
		$stats = (object) Stats::instance()->getStatsForSubmission(true);
		$settings = TouchPointWP::instance()->settings;

		global $wpdb;
		$rpt = Report::POST_TYPE;
		$reportData = $wpdb->get_row("SELECT MAX(post_modified) as ts, COUNT(*) as cnt FROM $wpdb->posts WHERE post_type = '$rpt'");

		echo '<div class="tpwp-status">';
		echo '<table style="width:100%">';

		echo "<tr><td></td>";

		echo "<th>" . __("Imported", "TouchPoint-WP") . "</th>";
		echo "<th>" . __("Last Updated", "TouchPoint-WP") . "</th></tr>";

		$label = __("People", "TouchPoint-WP");
		$ts = self::timestampToFormated($settings->person_cron_last_run);
		echo "<tr><th style=\"text-align:left;\">$label</th><td style=\"text-align:center;\">$stats->people</td><td style=\"text-align:center;\">$ts</td></tr>";

		if ($settings->enable_involvements === "on") {
			$label = __('Involvements', 'TouchPoint-WP');
			$ts = self::timestampToFormated($settings->inv_cron_last_run);
			echo "<tr><th style=\"text-align:left;\">$label</th><td style=\"text-align:center;\">$stats->involvementPosts</td><td style=\"text-align:center;\">$ts</td></tr>";

			foreach (Involvement::allTypeSettings() as $type) {
				$count = $stats->involvementCounts[$type->postTypeWithPrefix()] ?? 0;
				$ts = self::timestampToFormated($settings->inv_cron_last_run); // TODO
				echo "<tr><th style=\"text-align:left; padding-left:1em;\">$type->namePlural</th><td style=\"text-align:center;\">$count</td><td style=\"text-align:center;\">$ts</td></tr>";
			}
		}

		if ($settings->enable_meeting_cal === "on") {
			$label = __('Meetings', 'TouchPoint-WP');
			// TODO replace timestamp with event-type timestamp
			$ts = self::timestampToFormated($settings->inv_cron_last_run);
			echo "<tr><th style=\"text-align:left;\">$label</th><td style=\"text-align:center;\">$stats->meetings</td><td style=\"text-align:center;\">$ts</td></tr>";
		}

		if ($settings->enable_global === "on") {
			$label = __('Partners', 'TouchPoint-WP');
			$ts = self::timestampToFormated($settings->global_cron_last_run);
			echo "<tr><th style=\"text-align:left;\">$label</th><td style=\"text-align:center;\">$stats->partnerPosts</td><td style=\"text-align:center;\">$ts</td></tr>";
		}

		$label = __('Reports', 'TouchPoint-WP');
		$ts = self::timestampToFormated($reportData->ts);
		echo "<tr><th style=\"text-align:left;\">$label</th><td style=\"text-align:center;\">$reportData->cnt</td><td style=\"text-align:center;\">$ts</td></tr>";

		echo "</table>";

		// Translators: %s is the current version number.
		$label = __("Version: %s", "TouchPoint-WP");
		$label = wp_sprintf($label, TouchPointWP::VERSION);
		echo "<div style=\"text-align: right;\">$label</div>";

		echo "</div>";
	}
}