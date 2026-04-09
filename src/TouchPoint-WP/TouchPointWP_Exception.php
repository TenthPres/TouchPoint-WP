<?php
/**
 * @package TouchPointWP
 */

namespace tp\TouchPointWP;

use Exception;
use Throwable;
use WP_Error;

if ( ! defined('ABSPATH')) {
	exit;
}

/**
 * An Exception class.
 */
class TouchPointWP_Exception extends Exception
{
	static ?bool $_debugMode = null;

	/**
	 * Create an exception, and log it where it can be reported to the TouchPoint-WP Developers.
	 *
	 * @param string     $message
	 * @param int        $code
	 * @param ?Throwable $previous
	 * @param mixed      $devDetail
	 */
	public function __construct(string $message = "", int $code = 0, ?Throwable $previous = null, mixed $devDetail = null)
	{
		parent::__construct($message, $code, $previous);
		if (is_admin() && TouchPointWP::currentUserIsAdmin()) {
			$message = $this->getMessage();
			if (current_user_can('manage_options') && self::debugMode()) {
				$message .= "<br />" . $this->getFile() . " @ " . $this->getLine() . "<br />";
				$message .= str_replace("\n", "<br />", esc_html($this->getTraceAsString()));
			}
			self::showAdminError($message, $devDetail);
		}
		error_log("TouchPoint-WP: " . $message);
		self::debugLog($this->getCode(), $this->getFile(), $this->getLine(), $this->getMessage() . " " . $this->getTraceAsString());
	}

	/**
	 * Shows an admin error if and only if admin is loaded.
	 *
	 * @param string $message
	 * @param mixed  $devDetail
	 *
	 * @return void
	 */
	protected static function showAdminError(string $message, mixed $devDetail = null): void
	{
		if (is_admin() && TouchPointWP::currentUserIsAdmin()) {
			if ( ! TOUCHPOINT_COMPOSER_ENABLED) {
				require_once 'TouchPointWP_AdminAPI.php';
			}

			TouchPointWP_AdminAPI::showError($message, $devDetail);
		}
	}

	/**
	 * @param $code
	 * @param $file
	 * @param $line
	 * @param $message
	 *
	 * @return void
	 */
	public static function debugLog($code, $file, $line, $message): void
	{
		if (self::debugMode()) {
			$message = str_replace("\n", "<br />", $message);
			file_put_contents(
				TouchPointWP::$dir . '/TouchPointWP_ErrorLog.txt',
				time() . "\t" . TouchPointWP::VERSION . "\t" . $code . "\t" . $file . "#" . $line . "\t" . $message . "\n",
				FILE_APPEND | LOCK_EX
			);
		}
	}

	/**
	 * Let us know whether we're in debug mode.
	 *
	 * @return bool
	 */
	protected static function debugMode(): bool
	{
		if (self::$_debugMode === null) {
			self::$_debugMode = get_option(TouchPointWP::SETTINGS_PREFIX . "DEBUG", "") === "true";
		}

		return self::$_debugMode;
	}

	/**
	 * Get this in a JSON-compatible format
	 *
	 * @return string
	 */
	public function toJson(): string
	{
		return json_encode([
							   'error' => [
								   'status'   => 'failure',
								   'code'     => $this->getCode(),
								   'message'  => $this->getMessage(),
								   'location' => $this->getFile() . " @ L" . $this->getLine()
							   ]
						   ]);
	}


	/**
	 * Convert this exception to a WP_Error object that can be passed through the WordPress API.
	 *
	 * @return WP_Error
	 */
	public function toWpError(): WP_Error
	{
		return new WP_Error($this->getCode(), $this->getMessage());
	}
}
