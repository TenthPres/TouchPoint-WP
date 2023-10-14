<?php
/**
 * @package TouchPointWP
 */

namespace tp\TouchPointWP;

use WP_Error;

if ( ! defined('ABSPATH')) {
	exit(1);
}

/**
 * Everything related to the plugin's Taxonomies.
 */
abstract class Taxonomies
{
	public const TAX_TENSE_PRESENT = "present";
	public const TAX_INV_MARITAL = TouchPointWP::HOOK_PREFIX . "inv_marital";
	public const TAX_TENSE_PAST = "past";
	public const TAX_DIV = TouchPointWP::HOOK_PREFIX . "div";
	public const TAX_AGEGROUP = TouchPointWP::HOOK_PREFIX . "agegroup";
	public const TAX_RESCODE = TouchPointWP::HOOK_PREFIX . "rescode";
	public const TAX_CAMPUS = TouchPointWP::HOOK_PREFIX . "campus";
	public const TAX_GP_CATEGORY = TouchPointWP::HOOK_PREFIX . "partner_category";
	public const TAX_WEEKDAY = TouchPointWP::HOOK_PREFIX . "weekday";
	public const TAX_TENSE_FUTURE = "future";
	public const TAX_DAYTIME = TouchPointWP::HOOK_PREFIX . "timeOfDay";
	public const TAX_TENSE = TouchPointWP::HOOK_PREFIX . "tense";
	public const TAXMETA_LOOKUP_ID = TouchPointWP::HOOK_PREFIX . "lookup_id";


	public static bool $forceTermLookupIdUpdate = false;
	private static array $termExistsCache = [];

	/**
	 * Create the label strings for taxonomies, just in case they ever become visible to the user.
	 *
	 * @param string $singular
	 * @param string $plural
	 *
	 * @return array
	 */
	protected static function getLabels(string $singular, string $plural): array
	{
		return [
			'name'          => $singular,
			'singular_name' => $plural,
			/* translators: %s: taxonomy name, plural */
			'search_items'  => sprintf(__('Search %s', 'TouchPoint-WP'), $plural),
			/* translators: %s: taxonomy name, plural */
			'all_items'     => sprintf(__('All %s', 'TouchPoint-WP'), $plural),
			/* translators: %s: taxonomy name, singular */
			'edit_item'     => sprintf(__('Edit %s', 'TouchPoint-WP'), $singular),
			/* translators: %s: taxonomy name, singular */
			'update_item'   => sprintf(__('Update %s', 'TouchPoint-WP'), $singular),
			/* translators: %s: taxonomy name, singular */
			'add_new_item'  => sprintf(__('Add New %s', 'TouchPoint-WP'), $singular),
			/* translators: %s: taxonomy name, singular */
			'new_item_name' => sprintf(__('New %s', 'TouchPoint-WP'), $singular),
			'menu_name'     => $plural
		];
	}

	/**
	 * For the taxonomies that are based on Lookups in the TouchPoint database, insert or update the terms.  Also
	 * removes any items that are no longer present in the list.
	 *
	 * @param string[] $list where the slug is the key and the name is the value.
	 * @param string   $taxonomy
	 * @param bool     $forceIdUpdate
	 *
	 * @return void
	 */
	public static function insertTermsForArrayBasedTaxonomy(array $list, string $taxonomy, bool $forceIdUpdate)
	{
		$existingIds = [];
		foreach ($list as $slug => $name) {
			// In addition to making sure term exists, make sure it has the correct meta id, too.
			$term = self::termExists($name, $taxonomy);
			$idUpdate = $forceIdUpdate;
			if ( ! $term) {
				$term = self::insertTerm(
					$name,
					$taxonomy,
					[
						'description' => $name,
						'slug'        => $slug
					]
				);
				if (is_wp_error($term)) {
					new TouchPointWP_WPError($term);
					$term = null;
				}
				if ($idUpdate) {
					TouchPointWP::queueFlushRewriteRules();
				}
			}
			if ( ! ! $term) {
				$existingIds[] = $term['term_id'];
			}
		}

		// Delete any terms that are no longer current.
		$terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false, 'exclude' => $existingIds]);
		foreach ($terms as $term) {
			wp_delete_term($term->term_id, $taxonomy);
		}
	}

	/**
	 * For the taxonomies that are based on Lookups in the TouchPoint database, insert or update the terms.  Also
	 * removes any items that are no longer present in the list.
	 *
	 * @param object[] $list
	 * @param string   $taxonomy
	 * @param bool     $forceIdUpdate
	 *
	 * @return void
	 */
	public static function insertTermsForLookupBasedTaxonomy(array $list, string $taxonomy, bool $forceIdUpdate)
	{
		$existingIds = [];
		foreach ($list as $i) {
			if ($i->name === null) {
				continue;
			}
			// In addition to making sure term exists, make sure it has the correct meta id, too.
			$term = self::termExists($i->name, $taxonomy);
			$idUpdate = $forceIdUpdate;
			if ( ! $term) {
				$term = self::insertTerm(
					$i->name,
					$taxonomy,
					[
						'description' => $i->name,
						'slug'        => sanitize_title($i->name)
					]
				);
				if (is_wp_error($term)) {
					new TouchPointWP_WPError($term);
					$term = null;
				}
				$idUpdate = true;
			}
			// Update the term meta if term is new, or if id update is forced.
			if ($term !== null && isset($term['term_id']) && $idUpdate) {
				update_term_meta($term['term_id'], self::TAXMETA_LOOKUP_ID, $i->id);
			}
			if ($idUpdate) {
				TouchPointWP::queueFlushRewriteRules();
			}
			if ( ! ! $term) {
				$existingIds[] = $term['term_id'];
			}
		}

		// Delete any terms that are no longer current.
		$terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false, 'exclude' => $existingIds]);
		foreach ($terms as $term) {
			wp_delete_term($term->term_id, $taxonomy);
		}
	}

	/**
	 * Get the term id for a given taxonomy and value.
	 *
	 * @param $taxonomy string Taxonomy name
	 * @param $value int|string The Lookup ID or name or slug of the term.
	 *
	 * @return ?int
	 *
	 * @since 0.0.32
	 */
	public static function getTaxTermId(string $taxonomy, $value): ?int
	{
		$args = [
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'fields'     => 'ids'
		];

		if (is_numeric($value)) {
			// by lookup id
			$args['meta_key']   = self::TAXMETA_LOOKUP_ID;
			$args['meta_value'] = $value;
		} else {
			// by name
			$args['name'] = $value;
			$t            = get_terms($args);
			if (count($t) > 0) {
				return $t[0];
			}

			// by slug
			unset($args['name']);
			$args['slug'] = $value;
		}
		$t = get_terms($args);
		if (count($t) > 0) {
			return $t[0];
		}

		return null;
	}

	/**
	 * Filter to add a tp_post_type option to get_terms that takes either a string of one post type or an array of post
	 * types.
	 *
	 * @param $clauses
	 * @param $taxonomy
	 * @param $args
	 *
	 * Hat tip https://dfactory.eu/wp-how-to-get-terms-post-type/
	 *
	 * @return mixed
	 */
	public static function getTermsClauses($clauses, $taxonomy, $args): array
	{
		if (isset($args[TouchPointWP::HOOK_PREFIX . 'post_type']) && ! empty($args[TouchPointWP::HOOK_PREFIX . 'post_type']) && $args['fields'] !== 'count') {
			global $wpdb;

			$post_types = [];

			if (is_array($args[TouchPointWP::HOOK_PREFIX . 'post_type'])) {
				foreach ($args[TouchPointWP::HOOK_PREFIX . 'post_type'] as $cpt) {
					$post_types[] = "'" . $cpt . "'";
				}
			} else {
				$post_types[] = "'" . $args[TouchPointWP::HOOK_PREFIX . 'post_type'] . "'";
			}

			if ( ! empty($post_types)) {
				$clauses['fields'] = 'DISTINCT ' . str_replace(
						'tt.*',
						'tt.term_taxonomy_id, tt.taxonomy, tt.description, tt.parent',
						$clauses['fields']
					) . ', COUNT(p.post_type) AS count';
				$clauses['join'] .= ' LEFT JOIN ' . $wpdb->term_relationships . ' AS r ON r.term_taxonomy_id = tt.term_taxonomy_id LEFT JOIN ' . $wpdb->posts . ' AS p ON p.ID = r.object_id';
				$clauses['where'] .= ' AND (p.post_type IN (' . implode(
						',',
						$post_types
					) . ') OR (tt.parent = 0 AND tt.count = 0))';
				$clauses['orderby'] = 'GROUP BY t.term_id ' . $clauses['orderby'];
			}
		}

		return $clauses;
	}

	/**
	 * Insert the terms for the registered taxonomies.  (This is supposed to happen a while after the taxonomies are
	 * loaded.)
	 *
	 * @param TouchPointWP $instance
	 *
	 * @return void
	 */
	public static function insertTerms(TouchPointWP $instance)
	{
		// Resident Codes
		$types = self::getPostTypesForTaxonomy($instance, self::TAX_RESCODE);
		if (count($types) > 0) {
			self::insertTermsForLookupBasedTaxonomy(
				$instance->getResCodes(),
				self::TAX_RESCODE,
				self::$forceTermLookupIdUpdate
			);
		}

		// Campuses
		$types = self::getPostTypesForTaxonomy($instance, self::TAX_CAMPUS);
		if (count($types) > 0) {
			self::insertTermsForLookupBasedTaxonomy(
				$instance->getCampuses(),
				self::TAX_CAMPUS,
				self::$forceTermLookupIdUpdate
			);
		}

		// Age Groups
		$types = self::getPostTypesForTaxonomy($instance, self::TAX_AGEGROUP);
		if (count($types) > 0) {
			$taxTerms = [
				"20" => "20s",
				"30" => "30s",
				"40" => "40s",
				"50" => "50s",
				"60" => "60s",
				"70" => "70+"
			];
			self::insertTermsForArrayBasedTaxonomy(
				$taxTerms,
				self::TAX_AGEGROUP,
				self::$forceTermLookupIdUpdate
			);
		}

		// Divisions: Involvements and Events
		$types = self::getPostTypesForTaxonomy($instance, self::TAX_DIV);
		if (count($types) > 0) {
			$existingIds = [];
			$enabledDivisions = $instance->settings->dv_divisions;
			foreach ($instance->getDivisions() as $d) {
				if (!in_array('div' . $d->id, $enabledDivisions)) {
					continue;
				}
				if (!$d->pName || !$d->dName) {
					continue;
				}

				// Program
				$idUpdate = self::$forceTermLookupIdUpdate;
				$pTermInfo = self::termExists($d->pName, self::TAX_DIV, 0);
				if (! $pTermInfo) {
					$pTermInfo = self::insertTerm(
						$d->pName,
						self::TAX_DIV,
						[
							'description' => $d->pName,
							'slug'        => sanitize_title($d->pName)
						]
					);
					$idUpdate = true;
					if (is_wp_error($pTermInfo)) {
						new TouchPointWP_WPError($pTermInfo);
						$pTermInfo = null;
						continue;
					}
				}
				$proId = get_term_meta($pTermInfo['term_id'], TouchPointWP::SETTINGS_PREFIX . 'programId', true);
				if ($idUpdate || $proId !== intval($d->proId)) {
					update_term_meta(
						$pTermInfo['term_id'],
						TouchPointWP::SETTINGS_PREFIX . 'programId',
						intval($d->proId)
					);
					TouchPointWP::queueFlushRewriteRules();
				}
				if ( !! $pTermInfo) {
					$existingIds[] = $pTermInfo['term_id'];
				}

				// Division
				$idUpdate = self::$forceTermLookupIdUpdate;
				$dTermInfo = self::termExists($d->dName, self::TAX_DIV, $pTermInfo['term_id']);
				if (! $dTermInfo) {
					$dTermInfo = self::insertTerm(
						$d->dName,
						self::TAX_DIV,
						[
							'description' => $d->dName,
							'slug'        => sanitize_title($d->dName),
							'parent'      => $pTermInfo['term_id']
						]
					);
					$idUpdate = true;
					if (is_wp_error($dTermInfo)) {
						new TouchPointWP_WPError($dTermInfo);
						$dTermInfo = null;
						continue;
					}
				}
				$divId = get_term_meta($dTermInfo['term_id'], TouchPointWP::SETTINGS_PREFIX . 'divId', true);
				if ($idUpdate || $divId !== intval($d->id)) {
					update_term_meta(
						$dTermInfo['term_id'],
						TouchPointWP::SETTINGS_PREFIX . 'divId',
						intval($d->id)
					);
					TouchPointWP::queueFlushRewriteRules();
				}
				if ( !! $dTermInfo) {
					$existingIds[] = $dTermInfo['term_id'];
				}
			}

			// Delete any terms that are no longer current.
			$terms = get_terms(['taxonomy' => self::TAX_DIV, 'hide_empty' => false, 'exclude' => $existingIds]);
			foreach ($terms as $term) {
				wp_delete_term($term->term_id, self::TAX_DIV);
			}
		}

		// Weekdays
		$types = self::getPostTypesForTaxonomy($instance, self::TAX_WEEKDAY);
		if (count($types) > 0) {
			// Weekdays
			$days = [
				'sun' => 'Sundays',
				'mon' => 'Mondays',
				'tue' => 'Tuesdays',
				'wed' => 'Wednesdays',
				'thu' => 'Thursdays',
				'fri' => 'Fridays',
				'sat' => 'Saturdays',
			];
			self::insertTermsForArrayBasedTaxonomy(
				$days,
				self::TAX_WEEKDAY,
				self::$forceTermLookupIdUpdate
			);
		}

		// Tenses
		$types = self::getPostTypesForTaxonomy($instance, self::TAX_TENSE);
		if (count($types) > 0) {
			$tenses = [
				self::TAX_TENSE_FUTURE  => 'Upcoming',
				self::TAX_TENSE_PRESENT => 'Current',
				self::TAX_TENSE_PAST    => 'Past',
			];
			self::insertTermsForArrayBasedTaxonomy(
				$tenses,
				self::TAX_TENSE,
				self::$forceTermLookupIdUpdate
			);
		}

		// Time of Day
		$types = self::getPostTypesForTaxonomy($instance, self::TAX_DAYTIME);
		if (count($types) > 0) {
			// Time of Day
			$timesOfDay = [
				'latenight'    => 'Late Night',
				'earlymorning' => 'Early Morning',
				'morning'      => 'Morning',
				'midday'       => 'Midday',
				'afternoon'    => 'Afternoon',
				'evening'      => 'Evening',
				'night'        => 'Night'
			];
			self::insertTermsForArrayBasedTaxonomy(
				$timesOfDay,
				self::TAX_DAYTIME,
				self::$forceTermLookupIdUpdate
			);
		}

		// Marital Status
		$types = self::getPostTypesForTaxonomy($instance, self::TAX_INV_MARITAL);
		if (count($types) > 0) {
			$maritalStatuses = [
				'mostly_single'  => 'Mostly Single',
				'mostly_married' => 'Mostly Married'
			];
			self::insertTermsForArrayBasedTaxonomy(
				$maritalStatuses,
				self::TAX_INV_MARITAL,
				self::$forceTermLookupIdUpdate
			);
		}

		// Partner categories can't be included here because the values are only known at sync.
	}

	/**
	 * Wrapper for the WordPress term_exists function to reduce database calls
	 *
	 * @param int|string $term The term to check. Accepts term ID, slug, or name.
	 * @param string     $taxonomy Optional. The taxonomy name to use.
	 * @param int|null   $parent Optional. ID of parent term under which to confine the exists search.
	 *
	 * @return mixed Returns null if the term does not exist.
	 *			   Returns the term ID if no taxonomy is specified and the term ID exists.
	 *			   Returns an array of the term ID and the term taxonomy ID if the taxonomy is specified and the
	 *				  pairing exists.
	 *			   Returns 0 if term ID 0 is passed to the function.
	 *
	 * @see term_exists()
	 */
	public static function termExists($term, string $taxonomy = "", ?int $parent = null)
	{
		$key = $term . "|" . $taxonomy . "|" . $parent;
		if ( ! array_key_exists($key, self::$termExistsCache)) {
			self::$termExistsCache[$key] = term_exists($term, $taxonomy, $parent);
		}

		return self::$termExistsCache[$key];
	}

	/**
	 * Wrapper for the WordPress wp_insert_term function to reduce database calls
	 *
	 * Add a new term to the database.
	 *
	 * A non-existent term is inserted in the following sequence:
	 * 1. The term is added to the term table, then related to the taxonomy.
	 * 2. If everything is correct, several actions are fired.
	 * 3. The 'term_id_filter' is evaluated.
	 * 4. The term cache is cleaned.
	 * 5. Several more actions are fired.
	 * 6. An array is returned containing the `term_id` and `term_taxonomy_id`.
	 *
	 * If the 'slug' argument is not empty, then it is checked to see if the term
	 * is invalid. If it is not a valid, existing term, it is added and the term_id
	 * is given.
	 *
	 * If the taxonomy is hierarchical, and the 'parent' argument is not empty,
	 * the term is inserted and the term_id will be given.
	 *
	 * Error handling:
	 * If `$taxonomy` does not exist or `$term` is empty,
	 * a WP_Error object will be returned.
	 *
	 * If the term already exists on the same hierarchical level,
	 * or the term slug and name are not unique, a WP_Error object will be returned.
	 *
	 * @param string       $term The term name to add.
	 * @param string       $taxonomy The taxonomy to which to add the term.
	 * @param array|string $args {
	 *     Optional. Array or query string of arguments for inserting a term.
	 *
	 * @type string        $alias_of Slug of the term to make this term an alias of.
	 *                               Default empty string. Accepts a term slug.
	 * @type string        $description The term description. Default empty string.
	 * @type int           $parent The id of the parent term. Default 0.
	 * @type string        $slug The term slug to use. Default empty string.
	 * }
	 * @return array|WP_Error {
	 *     An array of the new term data, WP_Error otherwise.
	 *
	 * @type int           $term_id The new term ID.
	 * @type int|string    $term_taxonomy_id The new term taxonomy ID. Can be a numeric string.
	 * }
	 *
	 * @see wp_insert_term()
	 */
	public static function insertTerm(string $term, string $taxonomy, $args = [])
	{
		$parent = $args['parent'] ?? null;
		$key    = $term . "|" . $taxonomy . "|" . $parent;
		$r      = wp_insert_term($term, $taxonomy, $args);
		if ( ! is_wp_error($r)) {
			self::$termExistsCache[$key] = $r;
		}

		return $r;
	}

	/**
	 * @param TouchPointWP $instance
	 * @param string       $taxonomy
	 *
	 * @return array
	 */
	protected static function getPostTypesForTaxonomy(TouchPointWP $instance, string $taxonomy): array
	{
		$types = [];

		switch ($taxonomy) {
			case self::TAX_RESCODE:
				if ($instance->settings->enable_involvements === "on") {
					$types = Involvement_PostTypeSettings::getPostTypesWithGeoEnabled();
				}
				if ($instance->settings->rc_additional_post_types) {
					$types = array_merge(
						$types,
						$instance->settings->rc_additional_post_types
					);
				}
				if ($instance->settings->enable_meeting_cal === "on") {
					$types[] = Meeting::POST_TYPE;
				}
				$types[] = 'user';
				return $types;

			case self::TAX_CAMPUS:
				if ($instance->settings->enable_campuses !== "on") {
					return $types;
				}
				if ($instance->settings->enable_involvements === "on") {
					$types = Involvement_PostTypeSettings::getPostTypes();
				}
				if ($instance->settings->enable_meeting_cal === "on") {
					$types[] = Meeting::POST_TYPE;
				}
				$types[] = 'user';
				return $types;

			case self::TAX_DIV:
				if ($instance->settings->enable_involvements === "on") {
					$types = Involvement_PostTypeSettings::getPostTypes();
				}
				if ($instance->settings->enable_meeting_cal === "on") {
					$types[] = Meeting::POST_TYPE;
				}
				if ($instance->settings->dv_additional_post_types) {
					$types = array_merge(
						$types,
						$instance->settings->dv_additional_post_types
					);
				}
				return $types;

			case self::TAX_AGEGROUP:
				if ($instance->settings->enable_involvements === "on") {
					$types = Involvement_PostTypeSettings::getPostTypes();
				}
				$types[] = 'user';
				return $types;

			case self::TAX_WEEKDAY:
			case self::TAX_TENSE:
			case self::TAX_INV_MARITAL:
				if ($instance->settings->enable_involvements === "on") {
					$types = Involvement_PostTypeSettings::getPostTypes();
				}
				return $types;

			case self::TAX_GP_CATEGORY:
				if ($instance->settings->enable_involvements === "on"
				    && class_exists('\tp\TouchPointWP\Partner', false)) {
					return [\tp\TouchPointWP\Partner::POST_TYPE];
				}

		}

		return $types;
	}

	/**
	 * Register the taxonomies.
	 *
	 * @param TouchPointWP $instance
	 *
	 * @return void
	 */
	public static function registerTaxonomies(TouchPointWP $instance): void
	{
		// Resident Codes
		$types = self::getPostTypesForTaxonomy($instance, self::TAX_RESCODE);
		if (count($types) > 0) {
			register_taxonomy(
				self::TAX_RESCODE,
				$types,
				[
					'hierarchical'      => false,
					'show_ui'           => false,
					'description'       => __('Classify posts by their general locations.', 'TouchPoint-WP'),
					'labels'            => self::getLabels(
						$instance->settings->rc_name_singular,
						$instance->settings->rc_name_plural
					),
					'public'            => true,
					'show_in_rest'      => true,
					'show_admin_column' => true,

					// Control the slugs used for this taxonomy
					'rewrite'           => [
						'slug'         => $instance->settings->rc_slug,
						'with_front'   => false,
						'hierarchical' => false
					],
				]
			);
			// Terms inserted via insertTerms method
		}

		// Campuses
		$types = self::getPostTypesForTaxonomy($instance, self::TAX_CAMPUS);
		if (count($types) > 0) {
			register_taxonomy(
				self::TAX_CAMPUS,
				$types,
				[
					'hierarchical'      => false,
					'show_ui'           => false,
					'description'       => __('Classify posts by their church campus.', 'TouchPoint-WP'),
					'labels'            =>  self::getLabels(
						$instance->settings->camp_name_singular,
						$instance->settings->camp_name_plural
					),
					'public'            => true,
					'show_in_rest'      => true,
					'show_admin_column' => true,

					// Control the slugs used for this taxonomy
					'rewrite'           => [
						'slug'         => $instance->settings->camp_slug,
						'with_front'   => false,
						'hierarchical' => false
					],
				]
			);
			// Terms inserted via insertTerms method
		}

		// Divisions & Programs
		$types = self::getPostTypesForTaxonomy($instance, self::TAX_DIV);
		if (count($types) > 0) {
			register_taxonomy(
				self::TAX_DIV,
				$types,
				[
					'hierarchical'      => true,
					'show_ui'           => true,
					/* translators: %s: taxonomy name, singular */
					'description'       => sprintf(
						__('Classify things by %s.', 'TouchPoint-WP'),
						$instance->settings->dv_name_singular
					),
					'labels'            =>  self::getLabels(
						$instance->settings->dv_name_singular,
						$instance->settings->dv_name_plural
					),
					'public'            => true,
					'show_in_rest'      => true,
					'show_admin_column' => false,

					// Control the slugs used for this taxonomy
					'rewrite'           => [
						'slug'         => $instance->settings->dv_slug,
						'with_front'   => false,
						'hierarchical' => true
					],
				]
			);
			// Terms inserted via insertTerms method
		}

		// Weekdays
		$types = self::getPostTypesForTaxonomy($instance, self::TAX_WEEKDAY);
		if (count($types) > 0) {
			register_taxonomy(
				self::TAX_WEEKDAY,
				$types,
				[
					'hierarchical'      => false,
					'show_ui'           => false,
					'description'       => __('Classify involvements by the day on which they meet.', 'TouchPoint-WP'),
					'labels'            =>  self::getLabels(__('Weekday', 'TouchPoint-WP'), __('Weekdays', 'TouchPoint-WP')),
					'public'            => true,
					'show_in_rest'      => true,
					'show_admin_column' => true,

					// Control the slugs used for this taxonomy
					'rewrite'           => [
						'slug'         => 'weekday',
						'with_front'   => false,
						'hierarchical' => false
					],
				]
			);
			// Terms inserted via insertTerms method

		}

		// Tenses
		$types = self::getPostTypesForTaxonomy($instance, self::TAX_TENSE);
		if (count($types) > 0) {
			register_taxonomy(
				self::TAX_TENSE,
				$types,
				[
					'hierarchical'      => false,
					'show_ui'           => false,
					'description'       => __(
						'Classify involvements by tense (present, future, past)',
						'TouchPoint-WP'
					),
					'labels'            =>  self::getLabels(__("Tense", 'TouchPoint-WP'), __("Tenses", 'TouchPoint-WP')),
					'public'            => true,
					'show_in_rest'      => false,
					'show_admin_column' => false,

					// Control the slugs used for this taxonomy
					'rewrite'           => [
						'slug'         => 'tense',
						'with_front'   => false,
						'hierarchical' => false
					],
				]
			);
			// Terms inserted via insertTerms method

			// Time of Day
			/** @noinspection SpellCheckingInspection */
			register_taxonomy(
				self::TAX_DAYTIME,
				Involvement_PostTypeSettings::getPostTypes(),
				[
					'hierarchical'      => false,
					'show_ui'           => false,
					'description'       => __(
						'Classify involvements by the portion of the day in which they meet.',
						'TouchPoint-WP'
					),
					'labels'            =>  self::getLabels(
						__('Time of Day', 'TouchPoint-WP'),
						__('Times of Day', 'TouchPoint-WP')
					),
					'public'            => true,
					'show_in_rest'      => true,
					'show_admin_column' => true,

					// Control the slugs used for this taxonomy
					'rewrite'           => [
						'slug'         => 'timeofday',
						'with_front'   => false,
						'hierarchical' => false
					],
				]
			);
			// Terms inserted via insertTerms method
		}

		// Age Groups
		$types = self::getPostTypesForTaxonomy($instance, self::TAX_AGEGROUP);
		if (count($types) > 0) {
			register_taxonomy(
				self::TAX_AGEGROUP,
				$types,
				[
					'hierarchical'      => false,
					'show_ui'           => false,
					'description'       => __('Classify involvements and users by their age groups.', 'TouchPoint-WP'),
					'labels'            => self::getLabels(
						__('Age Group', 'TouchPoint-WP'),
						__('Age Groups', 'TouchPoint-WP')
					),
					'public'            => true,
					'show_in_rest'      => true,
					'show_admin_column' => true,

					// Control the slugs used for this taxonomy
					'rewrite'           => [
						'slug'         => self::TAX_AGEGROUP,
						'with_front'   => false,
						'hierarchical' => false
					],
				]
			);
			// Terms inserted via insertTerms method
		}

		// Involvement Marital Status
		$types = self::getPostTypesForTaxonomy($instance, self::TAX_INV_MARITAL);
		if (count($types) > 0) {
			register_taxonomy(
				self::TAX_INV_MARITAL,
				$types,
				[
					'hierarchical'      => false,
					'show_ui'           => false,
					'description'       => __(
						'Classify involvements by whether participants are mostly single or married.',
						'TouchPoint-WP'
					),
					'labels'            =>  self::getLabels(
						__('Marital Status', 'TouchPoint-WP'),
						__('Marital Statuses', 'TouchPoint-WP')
					),
					'public'            => true,
					'show_in_rest'      => true,
					'show_admin_column' => true,

					// Control the slugs used for this taxonomy
					'rewrite'           => [
						'slug'         => self::TAX_INV_MARITAL,
						'with_front'   => false,
						'hierarchical' => false
					],
				]
			);
			// Terms inserted via insertTerms method
		}

		// Global Partner Category
		$types = self::getPostTypesForTaxonomy($instance, self::TAX_GP_CATEGORY);
		if ($types > 0) {
			$tax = $instance->settings->global_primary_tax;
			if ($tax !== "" &&
			    $instance->settings->enable_global === "on" &&
			    count($instance->getFamilyEvFields([$tax])) > 0) {
				$tax    = $instance->getFamilyEvFields([$tax])[0];
				$plural = $tax->field . "s"; // TODO Sad, but works.  i18n someday.
				register_taxonomy(
					self::TAX_GP_CATEGORY,
					$types,
					[
						'hierarchical'      => false,
						'show_ui'           => false,
						'description'       => __('Classify Partners by category chosen in settings.', 'TouchPoint-WP'),
						'labels'            => self::getLabels($tax->field, $plural),
						'public'            => true,
						'show_in_rest'      => true,
						'show_admin_column' => true,

						// Control the slugs used for this taxonomy
						'rewrite'           => [
							'slug'         => self::TAX_GP_CATEGORY,
							'with_front'   => false,
							'hierarchical' => false
						],
					]
				);
				// Terms are inserted on sync.
			}
		}
	}
}