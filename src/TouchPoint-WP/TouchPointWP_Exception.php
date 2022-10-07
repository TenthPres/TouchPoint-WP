<?php
/**
 * @package TouchPointWP
 */
namespace tp\TouchPointWP;

use Exception;
use Throwable;

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
        if (is_admin() && TouchPointWP::currentUserIsAdmin()) {
            TouchPointWP_AdminAPI::showError($this->getMessage());
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
    public static function debugLog($code, $file, $line, $message) {
        if (get_option(TouchPointWP::SETTINGS_PREFIX . "DEBUG", "") === "true") {
            file_put_contents(
                TouchPointWP::$dir . '/TouchPointWP_ErrorLog.txt',
                time() . "\t" . TouchPointWP::VERSION . "\t" . $code . "\t" . $file . "#" . $line . "\t" . $message . "\n",
                FILE_APPEND | LOCK_EX
            );
        }
    }
}
