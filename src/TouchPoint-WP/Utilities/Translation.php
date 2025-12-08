<?php
/**
 * @package TouchPointWP
 */

namespace tp\TouchPointWP\Utilities;

use tp\TouchPointWP\TouchPointWP;

if ( ! defined('ABSPATH')) {
	exit(1);
}

/**
 * Used for Translation management.
 */
abstract class Translation {

	/**
	 * Determine whether campuses should be used as a "language" field.
	 *
	 * @return bool
	 */
	public static function useCampusAsLanguage(): bool{
		$cName = TouchPointWP::instance()->settings->camp_name_singular;
		return strtolower($cName) == "language";
	}

	/**
	 * Take a string and return the language code that it matches.  Null if no match or WPML isn't enabled.
	 *
	 * @param string $string
	 *
	 * @return string|null
	 */
	public static function getWpmlLangCodeForString(string $string): ?string
	{
		if (!defined("ICL_LANGUAGE_CODE")) {
			return null;
		}

		global $wpdb;

		$lang_code_query = "
			SELECT language_code
			FROM {$wpdb->prefix}icl_languages_translations
			WHERE name = %s OR language_code = %s
		";

		return $wpdb->get_var($wpdb->prepare($lang_code_query, $string, $string));
	}
}