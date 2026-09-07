<?php
/**
 * @package TouchPointWP
 */

namespace tp\TouchPointWP\Utilities;

if ( ! defined('ABSPATH')) {
	exit(1);
}

/**
 * Used for database actions that are relatively generic.
 */
abstract class Database
{
	/**
	 * Deleted all post meta for a given post that starts with a given prefix.
	 *
	 * @param int    $postId
	 * @param string $prefix
	 *
	 * @return bool
	 */
	public static function deletePostMetaByPrefix(int $postId, string $prefix): bool
	{
		global $wpdb;

		// Get all meta keys for the given post
		$meta_keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT meta_key FROM $wpdb->postmeta WHERE post_id = %d AND meta_key LIKE %s",
				$postId,
				$wpdb->esc_like($prefix) . '%'
			)
		);

		// Loop through each meta key and delete it
		$success = true;
		foreach ($meta_keys as $meta_key) {
			$success *= delete_post_meta($postId, $meta_key);
		}
		return $success;
	}
}