<?php

namespace tp\TouchPointWP;

use stdClass;
use WP_Error;
use WP_Http;

/**
 * This class handles communication with the TouchPoint API.
 */
class Api
{
	/**
	 * The singleton of Api.
	 */
	private static ?Api $_instance = null;

	/**
	 * The main plugin object.
	 */
	public ?TouchPointWP $parent = null;

	protected static bool $allowApiCalls = true;
	protected static array $apiCallLog = [];
	const MAX_API_CALLS = 20;

	private ?WP_Http $httpClient = null;


	/**
	 * Constructor function.
	 *
	 * @param TouchPointWP $parent Parent object.
	 */
	public function __construct(TouchPointWP $parent)
	{
		$this->parent = $parent;
	}

	/**
	 * Shortcut to the settings object.
	 *
	 * @return Settings
	 */
	protected function settings(): Settings
	{
		return $this->parent->settings;
	}


	/**
	 * Main Api Instance
	 *
	 * Ensures only one instance of Api is loaded or can be loaded.
	 *
	 * @param ?TouchPointWP $parent Object instance.
	 *
	 * @return Api instance
	 * @since 0.0.95 Added
	 * @static
	 * @see TouchPointWP()
	 */
	public static function instance(?TouchPointWP $parent = null): Api
	{
		if (is_null($parent)) {
			$parent = TouchPointWP::instance();
		}

		if (is_null(self::$_instance)) {
			self::$_instance = new self($parent);
		}

		return self::$_instance;
	}


	/**
	 * Gets data from the API via the python script.
	 *
	 * @param string $command The thing to get
	 * @param ?array $parameters URL parameters to be added.
	 * @param int    $timeout Amount of time in sec to wait before timing out.
	 *
	 * @return stdClass|array An array with headers, body, and other keys
	 * Data is generally in json_decode($response['body'])->data
	 *
	 * @throws TouchPointWP_Exception Thrown if the API credentials are incomplete.
	 */
	public function pyGet(string $command, ?array $parameters = null, int $timeout = 5, $verbose = false): array|stdClass
	{
		if ( ! is_array($parameters)) {
			$parameters = (array)$parameters;
		}

		if ( ! $this->settings()->hasValidApiSettings()) {
			throw new TouchPointWP_Exception(__("Invalid or incomplete API Settings.", "TouchPoint-WP"), 170001);
		}

		if (!self::$allowApiCalls) {
			throw new TouchPointWP_Exception("TouchPoint has received too many requests.", 170009);
		}

		$this->checkApiValidity();

		$parameters['a'] = $command;

		$host = $this->parent->host();

		if (!$host) {
			throw new TouchPointWP_Exception(__('Host appears to be missing from TouchPoint-WP configuration.', 'TouchPoint-WP'), 170002);
		}

		$url = $host . "/PythonApi/" .
		       $this->settings()->api_script_name . "?" . http_build_query($parameters);

		self::$apiCallLog[] = $url;

		if ($verbose) {
			echo "<p>Request to $url</p>";
		}

		$r = $this->getHttpClient()->request(
			$url,
			[
				'method'  => 'GET',
				'headers' => [
					'Authorization' => 'Basic ' . base64_encode(
							$this->settings()->api_user . ':' . $this->settings()->api_pass
						)
				],
				'timeout' => $timeout
			]
		);

		return self::parsePyApiResponse($r);
	}


	/**
	 * Do a POST to the Python-Defined API.
	 *
	 * @param string $command The thing to post
	 * @param ?mixed $data Data to post
	 * @param int    $timeout Amount of time in sec to wait before timing out.
	 * @param float  $timeTaken The time taken to complete the request.
	 *
	 * @return stdClass|array An object that corresponds to the Data python object in TouchPoint.
	 * @throws TouchPointWP_Exception  If anything went wrong.
	 */
	public function pyPost(string $command, mixed $data = null, int $timeout = 5, float &$timeTaken = 0): array|stdClass
	{
		$this->checkApiValidity();

		$host = $this->parent->host();

		if ( ! $host) {
			throw new TouchPointWP_Exception(
				__("Host appears to be missing from TouchPoint-WP configuration.", "TouchPoint-WP"), 170002
			);
		}

		$data = json_encode(['inputData' => $data]);

		$url = $host . "/PythonApi/" . $this->settings()->api_script_name . "?" . http_build_query(['a' => $command]);

		self::$apiCallLog[] = $url;
		$tik = microtime(true);

		$r = $this->getHttpClient()->request(
			$url,
			[
				'method'  => 'POST',
				'headers' => [
					'Authorization' => 'Basic ' . base64_encode($this->settings()->api_user . ':' . $this->settings()->api_pass)
				],
				'body'    => ['data' => $data],
				'timeout' => $timeout
			]
		);

		$timeTaken = microtime(true) - $tik;

		return self::parsePyApiResponse($r);
	}


	/**
	 * Do a GET to the standard API using a PAB.
	 *
	 * @param string $command The API endpoint to call
	 * @param array  $headers Headers to send with the request.
	 * @param ?int   $onBehalfPid The PID of the user to act on behalf of.
	 * @param int    $timeout Amount of time in sec to wait before timing out.
	 * @param float  $timeTaken The time taken to complete the request.
	 *
	 * @return array|WP_Error The response from the Http request call.
	 * @throws TouchPointWP_Exception  If anything went wrong.
	 */
	public function get(string $command, array $headers = [], ?int $onBehalfPid = null, int $timeout = 5, float &$timeTaken = 0): array|WP_Error
	{
		if (!is_array($headers)) {
			$headers = (array)$headers;
		}

		$this->checkApiValidity();
		$host = $this->parent->host();
		$url = $host . $command;

		self::$apiCallLog[] = $url;
		$tik = microtime(true);

		$headers['Authorization'] = 'PAT ' . $this->getPAT();

		if (!isset($headers['Content-Type'])) {
			$headers['Content-Type'] = 'text/plain';
		}

		if ($onBehalfPid) {
			$headers['X-On-Behalf-Of'] = $onBehalfPid;
		}

		$r = $this->getHttpClient()->request(
			$url,
			[
				'method'  => 'GET',
				'headers' => $headers,
				'timeout' => $timeout
			]
		);

		$timeTaken = microtime(true) - $tik;

		return $r;
	}


	/**
	 * Do a POST to the standard API using a PAB.
	 *
	 * @param string $command The API endpoint to call
	 * @param ?mixed $data Data to post
	 * @param array  $headers Headers to send with the request.
	 * @param ?int   $onBehalfPid The PID of the user to act on behalf of.
	 * @param int    $timeout Amount of time in sec to wait before timing out.
	 * @param float  $timeTaken The time taken to complete the request.
	 *
	 * @return array|WP_Error The response from the Http request call.
	 * @throws TouchPointWP_Exception  If anything went wrong.
	 */
	public function post(string $command, mixed $data = null, array $headers = [], ?int $onBehalfPid = null, int $timeout = 5, float &$timeTaken = 0): array|WP_Error
	{
		if (!is_array($headers)) {
			$headers = (array)$headers;
		}

		$this->checkApiValidity();
		$host = $this->parent->host();
		$url = $host . $command;

		self::$apiCallLog[] = $url;
		$tik = microtime(true);

		$headers['Authorization'] = 'PAT ' . $this->getPAT();

		if (!isset($headers['Content-Type'])) {
			$headers['Content-Type'] = 'text/plain';
		}

		if ($onBehalfPid) {
			$headers['X-On-Behalf-Of'] = $onBehalfPid;
		}

		$r = $this->getHttpClient()->request(
			$url,
			[
				'method'  => 'POST',
				'headers' => $headers,
				'body'    => $data,
				'timeout' => $timeout
			]
		);

		$timeTaken = microtime(true) - $tik;

		return $r;
	}


	/**
	 * Gets the PAT token to use.  Generates one if it doesn't exist.
	 *
	 * @throws TouchPointWP_Exception
	 */
	protected final function getPAT(): string
	{
		if (!$this->settings()->hasValidApiSettings()) {
			throw new TouchPointWP_Exception(__("Invalid or incomplete API Settings.", "TouchPoint-WP"), 170001);
		}

		if (!$this->settings()->api_pat) {
			$this->cyclePAT();
		}

		return $this->settings()->api_pat;
	}


	/**
	 * @return void
	 * @throws TouchPointWP_Exception
	 * @throws TouchPointWP_WPError
	 */
	protected final function cyclePAT(): void
	{
//		$this->invalidatePAT(); TODO this.
		$this->getNewPAT();
	}


	/**
	 * @throws TouchPointWP_Exception
	 * @throws TouchPointWP_WPError
	 */
	private function getNewPAT(): void
	{
		$host = $this->parent->host();

		if ( ! $host) {
			throw new TouchPointWP_Exception(__("Host appears to be missing from TouchPoint-WP configuration.", "TouchPoint-WP"), 170002);
		}

		$url = $host . "/api/v1/Account/CreateUserAccessToken";

		self::$apiCallLog[] = $url;

		$r = $this->getHttpClient()->request(
			$url,
			[
				'method'  => 'POST',
				'headers' => [
					'Authorization' => 'Basic ' . base64_encode($this->settings()->api_user . ':' . $this->settings()->api_pass),
					'Content-Type'  => 'plain/text'
				],
				'body'    => Utilities::dateTimeNowPlus90D()->format("Y-m-d\TH:i:s"),
			]
		);

		if ($r instanceof WP_Error) {
			throw new TouchPointWP_WPError($r);
		}

		if ($r['response']['code'] !== 200) {
			throw new TouchPointWP_Exception("Error Creating PAT: " . $r['response']['code'], 179005);
		}

		$response = json_decode($r['body']);

		update_option('tp_api_pat', $response->personalAccessToken);
		update_option('tp_api_pat_expires', $response->expirationDate);
	}


	/**
	 * @return WP_Http|null
	 */
	protected final function getHttpClient(): ?WP_Http
	{
		if ($this->httpClient === null) {
			$this->httpClient = new WP_Http();
		}

		return $this->httpClient;
	}


	/**
	 * Check that we aren't hitting rate limits or have missing credentials.
	 *
	 * @throws TouchPointWP_Exception
	 */
	protected function checkApiValidity(): void
	{
		if ( ! $this->settings()->hasValidApiSettings()) {
			throw new TouchPointWP_Exception(__("Invalid or incomplete API Settings.", "TouchPoint-WP"), 170001);
		}

		if (!self::$allowApiCalls) {
			throw new TouchPointWP_Exception("TouchPoint has received too many requests.", 170009);
		}

		$mostCommon = "";
		if (count(self::$apiCallLog) > self::MAX_API_CALLS) {
			if ($this->parent->debug) {
				$counts = array_count_values(self::$apiCallLog);
				arsort($counts);
				$mostCommon = "  Most Common: " . key($counts);
			}

			throw new TouchPointWP_Exception("Too many API calls have been attempted in this session.$mostCommon", 170009);
		}
	}


	/**
	 * @param WP_Error|array $response
	 *
	 * @return stdClass|array
	 * @throws TouchPointWP_Exception
	 * @throws TouchPointWP_WPError
	 */
	private static function parsePyApiResponse(WP_Error|array $response): array|stdClass
	{
		if ($response instanceof WP_Error) {
			throw new TouchPointWP_WPError($response);
		}

		if ($response['response']['code'] === 429) {
			self::$allowApiCalls = false;
			throw new TouchPointWP_Exception("TouchPoint has received too many requests.", 170009);
		}

		$respDecoded = json_decode($response['body']);

		if ($respDecoded === null) {
			throw new TouchPointWP_Exception("Connection Error", 179000);
		}

		// Most likely the issue where a module import failed for no apparent reason.
		if (property_exists($respDecoded, 'output') &&
		    str_starts_with($respDecoded->output, "Traceback (most recent call last):")) {
			throw new TouchPointWP_Exception("Script error: " . $respDecoded->output, 179001);
		}

		// Some other script error
		if (property_exists($respDecoded, 'output') && $respDecoded->output !== '') {
			throw new TouchPointWP_Exception("Script error: " . $respDecoded->output, 179002);
		}

		// Error caught by error handling within Python script
		if (property_exists($respDecoded, 'message') && $respDecoded->message !== '') {
			throw new TouchPointWP_Exception($respDecoded->message, 179003);
		}

		if ( ! property_exists($respDecoded->data, "VERSION") || $respDecoded->data->VERSION !== TouchPointWP::VERSION) {
			if (in_array("updateScripts", $respDecoded->data->a ?? [])) {
				if (class_exists("TouchPointWP_AdminAPI")) {
					TouchPointWP_AdminAPI::showError(
						__("The scripts on TouchPoint that interact with this plugin are out-of-date, and an automatic update failed.", "TouchPoint-WP")
					);
				}
			} else {
				self::instance()->settings()->updateDeployedScripts();
			}
		}

		return $respDecoded->data;
	}
}