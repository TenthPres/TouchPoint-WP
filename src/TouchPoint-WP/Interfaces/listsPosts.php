<?php
/**
 * @package TouchPointWP
 */

namespace tp\TouchPointWP\Interfaces;

use WP_Query;

if ( ! defined('ABSPATH')) {
	exit(1);
}

/**
 * For classes whose posts can be printed as a list, such as on their archive pages and in their list shortcodes.
 *
 * @since 0.2.2 Added
 */
interface listsPosts
{
	/**
	 * Print the list items for the posts in a query.  A theme can replace the list items with its own template parts.
	 *
	 * @param WP_Query $q      The query whose posts are listed.
	 * @param array    $params The list's parameters, which the list item can use.  (e.g. `itemclass`)
	 *
	 * @return void
	 *
	 * @since 0.2.2 Added
	 */
	public static function doList(WP_Query $q, array $params = []): void;
}
