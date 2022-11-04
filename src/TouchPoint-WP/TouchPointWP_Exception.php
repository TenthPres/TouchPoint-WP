<?php
/**
 * @package TouchPointWP
 */
namespace tp\TouchPointWP;

use Exception;
use Throwable;

if (!TOUCHPOINT_COMPOSER_ENABLED) {
    require_once 'TouchPointWP_AdminAPI.php';
}

if ( ! defined('ABSPATH')) {
    exit;
}

/**
 * An Exception class.
 */
class TouchPointWP_Exception extends Exception
{
    /**
     * Create an exception, and log it where it can be reported to the TouchPoint-WP Developers.
     *
     * @param string     $message
     * @param int        $code
     * @param ?Throwable $previous
     */
    public function __construct(string $message = "", int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        if (is_admin() && TouchPointWP::currentUserIsAdmin()) { // TODO add a limit to only show under debug conditions
            $message = $this->getMessage();
            if (current_user_can('manage_options')) {
                $message .= "<br />" . $this->getFile() . " @ " . $this->getLine() . "<br />";
                $message .= str_replace("\n", "<br />", $this->getTraceAsString());
            }
            TouchPointWP_AdminAPI::showError($message);
        }
        error_log($message);
        self::debugLog($this->getCode(), $this->getFile(), $this->getLine(), $this->getMessage());
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
        if (get_option(TouchPointWP::SETTINGS_PREFIX . "DEBUG", "") === "true") {
            file_put_contents(
                TouchPointWP::$dir . '/TouchPointWP_ErrorLog.txt',
                time() . "\t" . TouchPointWP::VERSION . "\t" . $code . "\t" . $file . "#" . $line . "\t" . $message . "\n",
                FILE_APPEND | LOCK_EX
            );
        }
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
}
