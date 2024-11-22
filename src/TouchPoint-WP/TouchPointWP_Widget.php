<?php
/**
 * @package TouchPointWP
 */

namespace tp\TouchPointWP;

use tp\TouchPointWP\Utilities\DateFormats;

if ( ! defined('ABSPATH')) {
	exit;
}

/**
 * Class TouchPointWP_Widget
 *
 * Provides an admin dashboard widget that shows some basic stats about the plugin.
 *
 * @package tp\TouchPointWP
 */
abstract class TouchPointWP_Widget
{
	/**
	 * Initialize the widget.
	 */
	public static function init(): void
	{
		add_action( 'wp_dashboard_setup', [self::class, 'dashboardSetup'] );
	}

	/**
	 * Setup the dashboard widget.
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
				$timestamp = new \DateTime($timestamp, wp_timezone());
			} catch (\Exception $e) {
				return 'Never';
			}
		} else {
			try {
				$timestamp = new \DateTime('@' . $timestamp, Utilities::utcTimeZone());

			} catch (\Exception $e) {
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

		echo '<tr><th style="text-align:left;">People</th><td style="text-align:center;">' . $stats->people . '</td><td style="text-align:center;">' . self::timestampToFormated($settings->person_cron_last_run) . "</td></tr>";

		if ($settings->enable_involvements === "on") {
			echo '<tr><th style="text-align:left;">Involvements</th><td style="text-align:center;">' . $stats->involvementPosts . '</td><td style="text-align:center;">' . self::timestampToFormated($settings->inv_cron_last_run) . "</td></tr>";
		}

		if ($settings->enable_meeting_cal === "on") {
			echo '<tr><th style="text-align:left;">Meetings</th><td style="text-align:center;">' . $stats->meetings . '</td><td style="text-align:center;">' . self::timestampToFormated($settings->inv_cron_last_run) . "</td></tr>";
		}

		if ($settings->enable_global === "on") {
			echo '<tr><th style="text-align:left;">Partners</th><td style="text-align:center;">' . $stats->partnerPosts . '</td><td style="text-align:center;">' . self::timestampToFormated($settings->global_cron_last_run) . "</td></tr>";
		}

		echo '<tr><th style="text-align:left;">Reports</th><td style="text-align:center;">' . $reportData->cnt . '</td><td style="text-align:center;">' . self::timestampToFormated($reportData->ts) . "</td></tr>";

		echo "</table>";

		echo '<div style="text-align: right;">Version ' . TouchPointWP::VERSION . "</div>";

		echo '</div>';
	}
}