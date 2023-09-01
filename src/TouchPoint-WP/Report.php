<?php
/**
 * @package TouchPointWP
 */

namespace tp\TouchPointWP;

use DateInterval;
use DateTime;
use Exception;
use JsonSerializable;
use tp\TouchPointWP\Utilities\Http;
use WP_Error;
use WP_Post;
use WP_Query;

if ( ! defined('ABSPATH')) {
	exit(1);
}

if ( ! TOUCHPOINT_COMPOSER_ENABLED) {
	require_once "api.php";
	require_once "updatesViaCron.php";
}

/**
 * The Report class gets and processes a SQL or Python report from TouchPoint and presents it in the UX.
 */
class Report implements api, module, JsonSerializable, updatesViaCron
{
	public const SHORTCODE_REPORT = TouchPointWP::SHORTCODE_PREFIX . "Report";
	public const POST_TYPE = TouchPointWP::HOOK_PREFIX . "report";
	public const META_PREFIX = TouchPointWP::SETTINGS_PREFIX . "rpt_";
	public const CRON_HOOK = TouchPointWP::HOOK_PREFIX . "report_cron_hook";
	public const TYPE_META_KEY = self::META_PREFIX . "type";
	public const NAME_META_KEY = self::META_PREFIX . "name";
	public const P1_META_KEY = self::META_PREFIX . "p1";
	public const DEFAULT_CONTENT = '';


	public static bool $_isLoaded = false;

	private static bool $_indexingMode = false;
	/** @var Report[] */
	private static array $_instances = [];

	protected ?WP_Post $post = null;
	protected bool $_postLoaded = false;

	protected string $type;
	protected string $name;
	protected float $interval;
	protected string $p1 = '';

	protected int $status = 0;


	/**
	 * Constructor.  Should not be called without first checking the $_instances variable.
	 */
	protected function __construct($params)
	{
		$this->name     = $params['name'];
		$this->type     = $params['type'];
		$this->interval = max(floor(floatval($params['interval']) * 4) / 4, 0.25);
		$this->p1       = $params['p1'] ?? "";
	}


	/**
	 * If one report can be used in multiple places, merge the parameters.
	 *
	 * @param $params
	 *
	 * @return void
	 */
	protected function mergeParams($params)
	{
		$this->interval = min($this->interval, $params['interval'] ?? $this->interval);
	}


	/**
	 * Get a Report object, based on standard parameters (generally, the parameters used for the shortcode)
	 *
	 * @throws TouchPointWP_Exception
	 */
	public static function fromParams($params): self
	{
		$params = (array)$params;

		// standardize parameters
		$params['name'] = strval($params['name']);
		if (trim($params['name']) === '') {
			throw new TouchPointWP_Exception("TouchPoint Reports must include a name.", 173001);
		}

		$params['type'] = strtolower($params['type']);
		if ($params['type'] !== 'sql') {
			throw new TouchPointWP_Exception("Invalid Report type.", 173002);
		}

		$key = self::cacheKey($params);
		if (isset(self::$_instances[$key])) {
			self::$_instances[$key]->mergeParams($params);
		} else {
			self::$_instances[$key] = new self($params);
		}

		return self::$_instances[$key];
	}


	/**
	 * Loads the module and initializes the other actions.
	 *
	 * @return bool
	 */
	public static function load(): bool
	{
		if (self::$_isLoaded) {
			return true;
		}

		self::$_isLoaded = true;

		add_action(TouchPointWP::INIT_ACTION_HOOK, [self::class, 'init']);

		//////////////////
		/// Shortcodes ///
		//////////////////

		if ( ! shortcode_exists(self::SHORTCODE_REPORT)) {
			add_shortcode(self::SHORTCODE_REPORT, [self::class, "reportShortcode"]);
		}

		////////////
		/// Cron ///
		////////////

		// Setup cron for updating People daily.
		add_action(self::CRON_HOOK, [self::class, 'updateCron']);
		if ( ! wp_next_scheduled(self::CRON_HOOK)) {
			// Runs every 15 minutes, starting now.
			wp_schedule_event(
				time(),
				'tp_every_15_minutes',
				self::CRON_HOOK
			);
		}

		///////////////
		/// Syncing ///
		///////////////

		// Syncing is relatively resource-intensive, and therefore should only be performed by cron.

		return true;
	}

	/**
	 * Register stuff
	 */
	public static function init(): void
	{
		register_post_type(
			self::POST_TYPE,
			[
				'labels'            => [
					'name'          => __("TouchPoint Reports", "TouchPoint-WP"),
					'singular_name' => __("TouchPoint Report", "TouchPoint-WP")
				],
				'public'            => false,
				'hierarchical'      => false,
				'show_ui'           => false,
				'show_in_nav_menus' => false,
				'show_in_rest'      => false,
				'supports'          => [],
				'has_archive'       => false,
				'rewrite'           => false,
				'can_export'        => false,
				'delete_with_user'  => false
			]
		);
	}


	/**
	 * Handle API requests
	 *
	 * @param array $uri The request URI already parsed by parse_url()
	 *
	 * @return bool False if endpoint is not found.  Should print the result.
	 */
	public static function api(array $uri): bool
	{
		if (count($uri['path']) === 3) {
			switch ($uri['path'][2]) {
				case "sync":
					TouchPointWP::doCacheHeaders(TouchPointWP::CACHE_NONE);
					try {
						echo self::updateFromTouchPoint();
					} catch (Exception $ex) {
						http_response_code(Http::SERVER_ERROR);
						echo "Update Failed: " . $ex->getMessage();
					}
					exit;

				case "force-sync":
					TouchPointWP::doCacheHeaders(TouchPointWP::CACHE_NONE);
					try {
						echo self::updateFromTouchPoint(true);
					} catch (Exception $ex) {
						http_response_code(Http::SERVER_ERROR);
						echo "Update Failed: " . $ex->getMessage();
					}
					exit;
			}
		}

		return false;
	}

	/**
	 * Get a string that can be used to uniquely identify and disambiguate reports.
	 *
	 * @param $obj
	 *
	 * @return string
	 */
	protected static function cacheKey($obj): string
	{
		$obj = (object)$obj;

		return strtolower($obj->type . "__" . $obj->name . "__" . $obj->p1);
	}

	/**
	 * Get the name, split into words by camelcase (the same way TouchPoint does it)
	 *
	 * @return string
	 */
	protected function title(): string
	{
		$splits = preg_split('/(?=[A-Z])/', $this->name);

		return implode(" ", $splits);
	}

	/**
	 * Handle the report shortcode.
	 *
	 * @param mixed  $params
	 * @param string $content
	 *
	 * @return string
	 */
	public static function reportShortcode($params = [], string $content = ""): string
	{
		$params = array_change_key_case($params, CASE_LOWER);

		$params = shortcode_atts(
			[
				'type'        => 'sql',
				'name'        => '',
				'interval'    => 24,
				'p1'          => '',
				'showupdated' => 'true'
			],
			$params,
			self::SHORTCODE_REPORT
		);

		$params['showupdated'] = (strtolower($params['showupdated']) === 'true' || $params['showupdated'] === 1);

		try {
			$report = self::fromParams($params);
		} catch (TouchPointWP_Exception $e) {
			return "<!-- " . $e->getMessage() . " -->";
		}

		if (self::$_indexingMode) {
			// It has been added to the index already, so our work here is done.
			return "";
		}

		$rc = $report->content();

		if ($rc === self::DEFAULT_CONTENT) {
			return $content;
		}

		// Add Figure elt with a unique ID
		$idAttr = "id=\"" . wp_unique_id('tp-report-') . "\"";
		$rc     = "<figure $idAttr>\n\t" . str_replace("\n", "\n\t", $rc);

		// If desired, add a caption that indicates when the table was last updated.
		if ($params['showupdated']) {
			$updatedS = sprintf(
			// translators: Last updated date/time for a report. %1$s is the date. %2$s is the time.
				esc_html__('Updated on %1$s at %2$s', 'TouchPoint-WP'),
				get_the_modified_date('', $report->getPost()),
				get_the_modified_time('', $report->getPost())
			);

			$rc .= "\n\t<figcaption class='tp-report-updated'>$updatedS</figcaption>";
		}

		$rc .= "\n</figure>";

		return $rc;
	}


	/**
	 * Get the WP_Post object corresponding to the Report.
	 *
	 * @param bool $create Set true if the post should be created if it doesn't exist.
	 *
	 * @return WP_Post|null
	 */
	public function getPost(bool $create = false): ?WP_Post
	{
		if ( ! $this->_postLoaded || ($this->post === null && $create)) {
			$q = new WP_Query([
				'post_type'   => self::POST_TYPE,
				'meta_query'  => [
					'relation' => 'AND',
					[
						'key'   => self::TYPE_META_KEY,
						'value' => $this->type
					],
					[
						'key'   => self::NAME_META_KEY,
						'value' => $this->name
					],
					[
						'key'   => self::P1_META_KEY,
						'value' => $this->p1
					]
				],
				'numberposts' => 2
// only need one, but if there's two, there should be an error condition.
			]);

			$reportPosts = $q->get_posts();
			$counts      = count($reportPosts);
			if ($counts > 1) {  // multiple posts match, which isn't great.
				new TouchPointWP_Exception("Multiple Posts Exist", 170006);
			}
			if ($counts > 0) { // post exists already.
				$this->post = $reportPosts[0];
			} elseif ($create) {
				$postId = wp_insert_post([
					'post_type'   => self::POST_TYPE,
					'post_status' => 'publish',
					'post_name'   => $this->title(),
					'meta_input'  => [
						self::NAME_META_KEY => $this->name,
						self::TYPE_META_KEY => $this->type,
						self::P1_META_KEY   => $this->p1
					]
				]);
				if (is_wp_error($postId)) {
					$this->post = null;
					new TouchPointWP_WPError($postId);

					return null;
				} elseif ($postId === 0) {
					$this->post = null;
					new TouchPointWP_Exception("Could not create post.", 173003);

					return null;
				} else {
					$this->post = get_post($postId);
				}
			} else {
				$this->post = null;
			}
			$this->_postLoaded = true;
		}

		return $this->post;
	}

	/**
	 * Save post changes back to database.
	 *
	 * @return int|WP_Error|null
	 */
	protected function submitUpdate()
	{
		if ( ! $this->getPost()) {
			return null;
		}

		return wp_update_post($this->post);
	}


	/**
	 * Get the report content.  If no content is available, return the provided default content instead.
	 *
	 * @param string $contentIfError
	 *
	 * @return string
	 */
	public function content(string $contentIfError = self::DEFAULT_CONTENT): string
	{
		$post = $this->getPost();
		if ($post === null) {
			return $contentIfError;
		}

		return get_the_content(null, false, $post);
	}


	/**
	 * Update all reports from TouchPoint that are due for an update.
	 *
	 * @param bool $forceEvenIfNotDue
	 *
	 * @return int
	 * @throws TouchPointWP_Exception
	 */
	public static function updateFromTouchPoint(bool $forceEvenIfNotDue = false): int
	{
		// Find Report Shortcodes in post content and add their involvements to the query.
		$referencingPosts   = Utilities::getPostContentWithShortcode(self::SHORTCODE_REPORT);
		$postIdsToNotDelete = [];


		//////////////////
		//// Indexing ////
		//////////////////

		self::$_indexingMode = true;
		foreach ($referencingPosts as $postI) {
			global $post;
			$post = $postI;
			set_time_limit(10);
			apply_shortcodes($postI->post_content);
		}
		self::$_indexingMode = false;

		$needsUpdate = [];
		foreach (self::$_instances as $report) {
			if ($report->getPost()) {
				$postIdsToNotDelete[] = $report->getPost()->ID;
			}
			if ($report->needsUpdate() || $forceEvenIfNotDue) {
				$needsUpdate[] = $report;
			}
		}


		//////////////////
		//// API Call ////
		//////////////////

		$updates = [];
		if (count($needsUpdate) > 0) {
			$data    = TouchPointWP::instance()->apiPost('report_run', ['reports' => $needsUpdate], 60);
			$updates = $data->report_results ?? [];
		}


		//////////////////////
		//// Update Posts ////
		//////////////////////

		$updateCount = 0;
		foreach ($updates as $u) {
			try {
				$report = self::fromParams($u);
			} catch (TouchPointWP_Exception $e) {
				continue;
			}

			$post               = $report->getPost(true);
			$post->post_content = self::cleanupContent($u->result);
			$submit             = $report->submitUpdate();

			if ( ! in_array($post->ID, $postIdsToNotDelete)) {
				$postIdsToNotDelete[] = $post->ID;
			}

			if ($submit) {
				$updateCount++;
			}
		}


		//////////////////
		//// Removals ////
		//////////////////

		$q = new WP_Query([
			                  'post_type'    => self::POST_TYPE,
			                  'nopaging'     => true,
			                  'post__not_in' => $postIdsToNotDelete
		                  ]);
		foreach ($q->get_posts() as $post) {
			set_time_limit(10);
			wp_delete_post($post->ID, true);
			$updateCount++;
		}

		if ($updateCount > 0) {
			TouchPointWP::instance()->flushRewriteRules();
		}

		return $updateCount;
	}


	/**
	 * Cleanup the content returned from the API before saving to the database.
	 *
	 * @param string $content
	 *
	 * @return string
	 */
	private static function cleanupContent(string $content): string
	{
		$closes  = substr($content, strrpos($content, '</tr>') + 5);
		$content = substr($content, 0, strrpos($content, '<tr'));
		$content .= $closes;

		return $content;
	}


	/**
	 * Get the update Interval as a DateInterval for use with DateTime functions.
	 *
	 * @return DateInterval
	 */
	public function intervalAsDateInterval(): DateInterval
	{
		$m = ($this->interval * 60) % 60;
		$h = $this->interval - ($m / 60);

		return new DateInterval("PT{$h}H{$m}M");
	}


	/**
	 * Determines if THIS report is due for an update.  Does NOT process the update itself.
	 *
	 * @return bool
	 */
	public function needsUpdate(): bool
	{
		$p = $this->getPost();
		if ($p === null) {
			return true;
		}
		$expires = DateTime::createFromFormat("Y-m-d H:i:s", $p->post_modified, wp_timezone());
		$expires->add($this->intervalAsDateInterval());

		return $expires <= Utilities::dateTimeNow();
	}


	/**
	 * Run the updating cron task.  Fail quietly to not disturb the visitor experience if using WP default cron
	 * handling.
	 *
	 * @return void
	 */
	public static function updateCron(): void
	{
		try {
			// Fork sync to a different process, if supported. (only some linux systems)
			$forked = false;
			/** @noinspection SpellCheckingInspection */
			if (function_exists('pcntl_fork')) {
				$pid = pcntl_fork();
				if ($pid >= 0) {
					// Forking successful.
					$forked = true;

					if ($pid === 0) {
						// Child process.  Parent process will have some PID > 0.
						self::updateFromTouchPoint();
						exit;
					}
				}
			}
			if ( ! $forked) {
				self::updateFromTouchPoint();
			}
		} catch (Exception $ex) {
		}
	}

	/**
	 * Handle which data should be converted to JSON.  Used for posting to the API.
	 *
	 * @return mixed data which can be serialized by json_encode
	 */
	public function jsonSerialize()
	{
		return [
			'name' => $this->name,
			'type' => $this->type,
			'p1'   => $this->p1
		];
	}

	/**
	 * Check to see if a cron run is needed, and run it if so.  Connected to an init function.
	 *
	 * @return void
	 */
	public static function checkUpdates()
	{
		// This method does nothing because the overhead is relatively great, and should not be hooked to every page load.
	}
}