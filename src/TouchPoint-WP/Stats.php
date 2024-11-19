<?php
/**
 * @package TouchPointWP
 */

namespace tp\TouchPointWP;

use InvalidArgumentException;
use tp\TouchPointWP\Utilities\Http;

if ( ! defined('ABSPATH')) {
	exit(1);
}

if ( ! TOUCHPOINT_COMPOSER_ENABLED) {
	require_once "api.php";
}

/**
 * Keep track of some basic stats that are helpful for Tenth to support other churches using the plugin.
 *
 * @property int $involvementJoins
 * @property int $involvementContacts
 * @property int $involvementPosts
 * @property int $meetings
 * @property int $rsvps
 * @property int $people
 * @property int $partnerPosts
 * @property int $userAuths
 * @property int $softAuths
 */
class Stats implements api, \JsonSerializable
{
	protected static ?self $instance = null;

	private bool $_dirty = false;

	protected int $involvementJoins = 0;
	protected int $involvementContacts = 0;
	protected int $involvementPosts = 0;  // updated by query
	protected int $meetings = 0;  // updated by query
	protected int $rsvps = 0;
	protected int $people = 0; // updated by query
	protected int $partnerPosts = 0; // updated by query
	protected int $userAuths = 0;
	protected int $softAuths = 0;

	/**
	 * @var string A GUID representing the installation of this plugin.  A site with multiple Tenth plugins may have one
	 * of these for each.
	 */
	private string $installId;

	/**
	 * @var string A GUID for validating incoming requests from the validation server. Never post this publicly, as in
	 * the future it may be used to transmit some diagnostic information.
	 */
	private string $privateKey;

	/**
	 * @var string A GUID unique to the site.  A site with multiple Tenth plugins would all have this same GUID.
	 */
	private string $siteId;

	const SUBMISSION_ENDPOINT = 'https://www.tenth.org/touchpoint-api/stats/submit';

	/**
	 * Get the instance of the Stats class, loading relevant info from the database.
	 */
	protected function __construct()
	{
		$data = get_option('tp_wp_stats');

		if ($data !== false) {
			$data = json_decode($data, true);
			if ($data !== null) {
				foreach ($data as $key => $value) {
					if (property_exists($this, $key)) {
						$this->$key = $value;
					}
				}
			}
		}

		if (empty($this->installId) || empty($this->privateKey)) {
			$this->privateKey = Utilities::createGuid();
			$this->installId  = Utilities::createGuid();
			$this->_dirty     = true;
		}

		$sid = get_option('tp_siteId', null);
		if (empty($sid)) {
			$sid = Utilities::createGuid();
			update_option('tp_siteId', $sid);
		}
		$this->siteId = $sid;

		$this->updateDb();
	}

	/**
	 * Attempt to save on destruct.
	 */
	protected function __destruct()
	{
		try {
			$this->updateDb();
		} catch (\Exception $e) {
			// ignore
		}
	}

	/**
	 * Save the stats to the database.
	 *
	 * @return bool true on success (or if an update wasn't needed), false on failure.
	 */
	public function updateDb(): bool
	{
		if ($this->_dirty) {
			$d = $this->jsonSerialize();
			unset($d['siteId']);
			$r = update_option('tp_wp_stats', json_encode($d));
			if ($r) {
				$this->_dirty = false;
			}
			return $r;
		}
		return true;
	}

	/**
	 * Setter.  Allows particular statistics to be set.
	 *
	 * @param $name
	 * @param $value
	 *
	 * @throws InvalidArgumentException
	 *
	 * @return void
	 */
	public function __set($name, $value)
	{
		if (in_array($name, ['privateKey', 'siteId', 'installId'])) {
			throw new InvalidArgumentException("Cannot set $name directly.");
		}

		if (property_exists($this, $name)) {
			if ($this->$name !== $value) {
				$this->$name = $value;
				$this->_dirty = true;
			}
		}
	}

	/**
	 * @param string $name
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException
	 */
	public function __get(string $name)
	{
		if (property_exists($this, $name) && !str_starts_with($name, '_')) {
			return $this->$name;
		}
	}

	/**
	 * Assemble the information that's submitted.
	 *
	 * @return array
	 */
	public function getStatsForSubmission(): array
	{
		$data = $this->jsonSerialize();

		$data['site'] = get_site_url();
		$data['plugin'] = 'TouchPointWP';
		$data['version'] = TouchPointWP::VERSION;
		$data['php'] = phpversion();
		$data['wp'] = get_bloginfo('version');
		$data['wpLocale'] = get_locale();
		$data['wpTimezone'] = get_option('timezone_string');
		$data['adminEmail'] = get_option('admin_email');
		$data['siteName'] = get_bloginfo('name');
		$data['installId'] = $this->installId;
		$data['privateKey'] = $this->privateKey;
		$data['updatedDT'] = date('Y-m-d H:i:s'); // needs to be forced or update may not happen, which would make insert fail.

		return $data;
	}

	/**
	 * Submit stats to Tenth.
	 *
	 * @return void
	 */
	protected function submitStats(): void
	{
		$this->updateQueriedStats();

		$data = $this->getStatsForSubmission();

		wp_remote_post(self::SUBMISSION_ENDPOINT, [
			'body' => ['data' => $data],
			'timeout' => 10,
			'blocking' => false,
		]);
	}

	/**
	 * Assemble the object into a format that can be serialized to JSON.  Only includes
	 * the parameters that are part of this class, not those that are loaded from the database separately,
	 * such as version numbers.
	 *
	 * @inheritDoc
	 */
	public function jsonSerialize()
	{
		$r = [];

		// all properties that don't start with an underscore
		foreach (get_object_vars($this) as $key => $value) {
			if ( ! str_starts_with($key, '_')) {
				$r[$key] = $value;
			}
		}

		return $r;
	}

	/**
	 * Update the stats that are determined from queries.
	 *
	 * @return void
	 */
	protected function updateQueriedStats(): void
	{
		global $wpdb;

		$this->involvementPosts = $wpdb->get_var("SELECT COUNT(DISTINCT meta_value) as c FROM $wpdb->postmeta WHERE meta_key = 'tp_invId'") ?? -1;
		$this->meetings = $wpdb->get_var("SELECT COUNT(DISTINCT meta_value) as c FROM $wpdb->postmeta WHERE meta_key = 'tp_mtgId'") ?? -1;
		$this->people = $wpdb->get_var("SELECT COUNT(DISTINCT meta_value) as c FROM $wpdb->usermeta WHERE meta_key = 'tp_peopleId';") ?? -1;
		$this->partnerPosts = $wpdb->get_var("SELECT COUNT(*) as c FROM $wpdb->posts WHERE post_type = 'tp_partner'") ?? -1;

		$this->_dirty = true;
	}

	/**
	 * Get the singleton.
	 *
	 * @return Stats
	 */
	public static function instance(): Stats
	{
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
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
		if (count($uri['path']) !== 3) {
			return false;
		}

		$s = self::instance();

		switch (strtolower($uri['path'][2])) {
			case "get":
				if (strtolower($_GET['key']) == strtolower($s->privateKey) ||
				    current_user_can('manage_options')) {
					header('Content-Type: application/json');
					$s->updateQueriedStats();
					echo json_encode($s->getStatsForSubmission());
					exit;
				}

			case "submit":
				if ($_SERVER['REQUEST_METHOD'] === "POST") {
					self::handleSubmission();
				} else {
					$s->submitStats();
				}
				exit;

		}

		return false;
	}

	/**
	 * Handle submissions received to this site (presumably tenth.org) from other users of the plugin.
	 *
	 * @return void
	 */
	public static function handleSubmission(): void
	{

		if ($_SERVER['REQUEST_METHOD'] !== "POST") {
			http_response_code(Http::METHOD_NOT_ALLOWED);
			echo "Only POST requests are allowed.";
			exit;
		}

		$data = $_POST['data'] ?? null;

		if (empty($data)) {
			http_response_code(Http::BAD_REQUEST);
			echo "No data was submitted.";
			exit;
		}

		// validate that privateKey, installId, and siteId are all included.
		if ( ! isset($data['privateKey']) || ! isset($data['installId']) || ! isset($data['siteId'])) {
			http_response_code(Http::BAD_REQUEST);
			echo "Keys not provided.";
			exit;
		}

		// remove any fields that are not part of the stats object.
		$s = self::instance();
		$data = array_intersect_key($data, $s->getStatsForSubmission());

		// upsert the data into the database into the stats table without destructive replace function
		global $wpdb;
		$r = $wpdb->update($wpdb->prefix . TouchPointWP::TABLE_STATS, $data, ['installId' => $data['installId']]);
		if ($r < 1) {
			$r = $wpdb->insert($wpdb->prefix . TouchPointWP::TABLE_STATS, $data);
		}

		if ($r === false) {
			http_response_code(Http::SERVER_ERROR);
			echo "Server error.";
			echo $wpdb->last_error;
			exit;
		}

//        echo $r;
		exit;
	}
}