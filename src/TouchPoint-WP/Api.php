<?php

namespace tp\TouchPointWP;

use stdClass;
use tp\TouchPointWP\Utilities\Http;
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
	 * Gets data from the API with Basic User Auth.
	 *
	 * This method is provided until TouchPoint has been updated to allow PAT authentication on more endpoints.  The
	 * signature is identical to the PAT version so replacement later should be easy, however, onbehalf
	 *
	 * @param string $command The API endpoint to call
	 * @param array $headers Headers to send with the request.
	 * @param ?int $onBehalfPid The PID of the user to act on behalf of.
	 * @param int $timeout Amount of time in sec to wait before timing out.
	 * @param float $timeTaken The time taken to complete the request.
	 *
	 * @return array|WP_Error An array with headers, body, and other keys
	 *
	 * @throws TouchPointWP_Exception Thrown if the API credentials are incomplete.
	 * @throws TouchPointWP_WPError
	 *
	 * @deprecated Use `get()` instead once possible, which uses PAT authentication.
	 *
	 * @since 0.0.96 Added and deprecated.
	 *
	 */
	public function uGet(string $command, array $parameters = [], array $headers = [], ?int $onBehalfPid = null, int $timeout = 5, float &$timeTaken = 0): array|WP_Error
	{
		$tik = microtime(true);

		if ( ! $this->settings()->hasValidApiSettings()) {
			TouchPointWP::instance()->logoutServiceMaybe();
			throw new TouchPointWP_Exception(__("Invalid or incomplete API Settings.", "TouchPoint-WP"), 170001);
		}

		$this->checkApiValidity();
		$host = $this->parent->host();
		$url = $host . "/api/" . $command;

		self::$apiCallLog[] = $url;

		$headers['Authorization'] = $this->getBasicAuth();

		if (!isset($headers['Content-Type'])) {
			$headers['Content-Type'] = 'text/plain';
		}

		if ($onBehalfPid) {
			TouchPointWP::instance()->logoutServiceMaybe();
			throw new TouchPointWP_Exception("On-Behalf-Of is not supported for Basic Auth.");
		}

		// build query string
		if (!empty($parameters)) {
			$url .= (!str_contains($url, '?') ? '?' : '&') . http_build_query($parameters);
		}

		for ($attempt = 0; $attempt < 2; $attempt++) {
			$r   = $this->getHttpClient()->request(
				$url,
				[
					'method'  => 'GET',
					'headers' => $headers,
					'timeout' => $timeout
				]
			);

			if ($r instanceof WP_Error) {
				return $r;
			}
		}

		$timeTaken = microtime(true) - $tik;

		return $r;
	}


	/**
	 * Get the Basic Auth header for the API.  Only used until PAT is fully implemented.
	 *
	 * @deprecated
	 *
	 * @since 0.0.96 Added and deprecated.
	 *
	 * @return string
	 */
	protected function getBasicAuth(): string
	{
		return 'Basic ' . base64_encode($this->settings()->api_user . ':' . $this->settings()->api_pass);
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
			TouchPointWP::instance()->logoutServiceMaybe();
			throw new TouchPointWP_Exception(__("Invalid or incomplete API Settings.", "TouchPoint-WP"), 170001);
		}

		if (!self::$allowApiCalls) {
			TouchPointWP::instance()->logoutServiceMaybe();
			throw new TouchPointWP_Exception("TouchPoint has received too many requests.", 170009);
		}

		$this->checkApiValidity();

		$parameters['a'] = $command;

		$host = $this->parent->host();

		if (!$host) {
			TouchPointWP::instance()->logoutServiceMaybe();
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
					'Authorization' => $this->getBasicAuth()
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
			TouchPointWP::instance()->logoutServiceMaybe();
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
	 * Prepares the request to be sent to the API.  Handles common elements between all PAT requests.
	 *
	 * @param string $command The API endpoint to call
	 * @param mixed  $headers Headers to send with the request.
	 * @param ?int   $onBehalfPid The PID of the user to act on behalf of.
	 *
	 * @return string The URL to call.
	 * @throws TouchPointWP_Exception
	 */
	protected function prepareRequest(string $command, mixed &$headers, ?int $onBehalfPid): string
	{
		if (!is_array($headers)) {
			$headers = (array)$headers;
		}

		$this->checkApiValidity();
		$host = $this->parent->host();
		$url = $host . $command;

		self::$apiCallLog[] = $url;

		$headers['Authorization'] = 'PAT ' . $this->getPAT();

		if (!isset($headers['Content-Type'])) {
			$headers['Content-Type'] = 'text/plain';
		}

		if ($onBehalfPid) {
			$headers['X-On-Behalf-Of'] = $onBehalfPid;
		}

		return $url;
	}


	/**
	 * Do a GET to the standard API using a PAT.
	 *
	 * @param string $command The API endpoint to call (after /api/)
	 * @param array  $parameters URL parameters to be added.
	 * @param array  $headers Headers to send with the request.
	 * @param ?int   $onBehalfPid The PID of the user to act on behalf of.
	 * @param int    $timeout Amount of time in sec to wait before timing out.
	 * @param float  $timeTaken The time taken to complete the request.
	 *
	 * @return array The response from the Http request call.
	 * @throws TouchPointWP_Exception  If anything went wrong.
	 */
	public function get(string $command, array $parameters = [], array $headers = [], ?int $onBehalfPid = null, int $timeout = 5, float &$timeTaken = 0): array
	{
		$tik = microtime(true);
		for ($attempt = 0; $attempt < 2; $attempt++) {
			$url = $this->prepareRequest("/api/" . $command, $headers, $onBehalfPid);

			// build query string
			if (!empty($parameters)) {
				$url .= (!str_contains($url, '?') ? '?' : '&') . http_build_query($parameters);
			}

			$r   = $this->getHttpClient()->request(
				$url,
				[
					'method'  => 'GET',
					'headers' => $headers,
					'timeout' => $timeout
				]
			);

			if ($r instanceof WP_Error) {
				throw new TouchPointWP_WPError($r);
			}

			if ($r['response']['code'] === Http::UNAUTHORIZED) {
				//if unauthorized, cycle PAT
				$this->cyclePAT();
			} elseif ($r['response']['code'] === Http::TOO_MANY_REQUESTS) {
				self::$allowApiCalls = false;
				throw new TouchPointWP_Exception("TouchPoint has received too many requests.", 170009);
			} elseif ($r['response']['code'] === Http::OK) {
				break;
			}
		}

		$timeTaken = microtime(true) - $tik;

		return $r;
	}


	/**
	 * Do a POST to the standard API using a PAT.
	 *
	 * @param string $command The API endpoint to call (after /api/)
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
		$tik = microtime(true);

		for ($attempt = 0; $attempt < 2; $attempt++) {
			$url = $this->prepareRequest("/api/" . $command, $headers, $onBehalfPid);
			$r   = $this->getHttpClient()->request(
				$url,
				[
					'method'  => 'POST',
					'headers' => $headers,
					'body'    => $data,
					'timeout' => $timeout
				]
			);

			if ($r instanceof WP_Error) {
				return $r;
			}

			if ($r['response']['code'] === Http::UNAUTHORIZED) {
				//if unauthorized, cycle PAT
				$this->cyclePAT();
			} elseif ($r['response']['code'] === Http::TOO_MANY_REQUESTS) {
				self::$allowApiCalls = false;
				throw new TouchPointWP_Exception("TouchPoint has received too many requests.", 170009);
			} elseif ($r['response']['code'] === Http::OK) {
				break;
			}
		}

		$timeTaken = microtime(true) - $tik;

		return $r;
	}


	/**
	 * Determine if PAT has not yet expired.
	 *
	 * @return bool
	 */
	protected function checkPATValidity(): bool
	{
		$expires = $this->settings()->api_pat_expires;

		if ($expires) {
			$expires = strtotime($expires) - (60 * 60 * 24); // 1 day buffer
			$now = time();

			if ($now > $expires) {
				return false;
			}
		}

		return true;
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
		
		if (!$this->checkPATValidity()) {
			$this->cyclePAT();
		}

		return $this->settings()->api_pat;
	}


	/**
	 * Invalidate the existing PAT and get a new one.
	 *
	 * @return void
	 * @throws TouchPointWP_Exception
	 * @throws TouchPointWP_WPError
	 */
	protected final function cyclePAT(): void
	{
		$this->invalidatePAT();
		$this->getNewPAT();
	}


	/**
	 * Invalidate the existing PAT, both here and on the server.
	 *
	 * @return void
	 */
	public final function invalidatePAT(): void
	{
		// get existing PAT
		$pat = $this->settings()->api_pat;
		$host = $this->parent->host();

		// if existing PAT exists, send delete request to invalidate it on the server
		if ($pat && $host) {
			$url = $host . "/api/v1/Account/DeleteUserAccessToken";
			$this->getHttpClient()->request(
				$url,
				[
					'method' => 'POST',
					'headers' => [
						'Authorization' => 'Basic ' . base64_encode(
								$this->settings()->api_user . ':' . $this->settings()->api_pass
							),
						'Content-Type'  => 'text/plain'
					],
					'body' => $pat,
					'blocking' => false
				]
			);
		}

		// remove it and the expiration date from the local settings
		update_option('tp_api_pat', null);
		update_option('tp_api_pat_expires', null);
	}


	/**
	 * Get a new PAT
	 *
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
					'Content-Type'  => 'text/plain'
				],
				'body'    => Utilities::dateTimeNowPlus90D()->format("Y-m-d\TH:i:s"),
			]
		);

		if ($r instanceof WP_Error) {
			throw new TouchPointWP_WPError($r);
		}

		if ($r['response']['code'] !== 200) {
			throw new TouchPointWP_Exception("Error Creating PAT: " . $r['response']['code'], 179006);
		}

		$response = json_decode($r['body']);

		update_option('tp_api_pat', $response->personalAccessToken);
		update_option('tp_api_pat_expires', $response->expirationDate ?? null);

		error_log("TouchPoint-WP INFO: PAT updated.");
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
			TouchPointWP::instance()->logoutServiceMaybe();
			throw new TouchPointWP_Exception(__("Invalid or incomplete API Settings.", "TouchPoint-WP"), 170001);
		}

		if (!self::$allowApiCalls) {
			TouchPointWP::instance()->logoutServiceMaybe();
			throw new TouchPointWP_Exception("TouchPoint has received too many requests.", 170009);
		}

		$mostCommon = "";
		if (count(self::$apiCallLog) > self::MAX_API_CALLS) {
			if ($this->parent->debug) {
				$counts = array_count_values(self::$apiCallLog);
				arsort($counts);
				$mostCommon = "  Most Common: " . key($counts);
			}

			TouchPointWP::instance()->logoutServiceMaybe();
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
			TouchPointWP::instance()->logoutServiceMaybe();
			throw new TouchPointWP_WPError($response);
		}

		if ($response['response']['code'] === 429) {
			self::$allowApiCalls = false;
			TouchPointWP::instance()->logoutServiceMaybe();
			throw new TouchPointWP_Exception("TouchPoint has received too many requests.", 170009);
		}

		$respDecoded = json_decode($response['body']);

		if ($respDecoded === null) {
			TouchPointWP::instance()->logoutServiceMaybe();
			throw new TouchPointWP_Exception("Connection Error", 179000);
		}

		// Most likely the issue where a module import failed for no apparent reason.
		if (property_exists($respDecoded, 'output') &&
		    str_starts_with($respDecoded->output, "Traceback (most recent call last):")) {
			TouchPointWP::instance()->logoutServiceMaybe();
			throw new TouchPointWP_Exception("Script error: " . $respDecoded->output, 179001);
		}

		// Some other script error
		if (property_exists($respDecoded, 'output') && $respDecoded->output !== '') {
			TouchPointWP::instance()->logoutServiceMaybe();
			throw new TouchPointWP_Exception("Script error: " . $respDecoded->output, 179002);
		}

		// Error caught by error handling within Python script
		if (property_exists($respDecoded, 'message') && $respDecoded->message !== '') {
			TouchPointWP::instance()->logoutServiceMaybe();
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