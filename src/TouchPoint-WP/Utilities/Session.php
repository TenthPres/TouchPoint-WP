<?php
/**
 * @package TouchPointWP
 */

namespace tp\TouchPointWP\Utilities;

use tp\TouchPointWP\TouchPointWP;

/**
 * This class wraps handlers for $_SESSION to make it more easily workable.
 *
 * @property array   $people
 * @property int[]   $primaryFam
 * @property int[]   $secondaryFam
 * @property ?string $auth_sessionToken
 * @property ?bool   $updateDeployedScriptsOnNextLoad
 * @property ?bool   $flushRewriteOnNextLoad
 */
class Session
{
	private static ?Session $_instance = null;

	/**
	 * Constructor.  Private to prevent extra instantiation... not that it really matters.
	 */
	private function __construct()
	{
	}

	/**
	 * Start a session if one doesn't already exist.
	 *
	 * @param array $options Options passed directly to session_start, but only if the session isn't yet active.
	 *
	 * @return bool  True if a session has been started (with this call or previously)
	 */
	public static function startSession(array $options = []): bool
	{
		$status = session_status();
		if ($status === PHP_SESSION_NONE) {
			return session_start($options);
		}

		return ($status === PHP_SESSION_ACTIVE);
	}

	public static function sessionDestroy(): bool
	{
		return session_destroy(); // clears all existing variables
	}

	/**
	 * @return Session|null
	 */
	public static function instance(): ?Session
	{
		if (self::$_instance === null) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * Standard getter, though returns null if unset or session isn't established.
	 *
	 * @param string $what
	 *
	 * @return mixed|null
	 */
	public function __get(string $what)
	{
		if (session_status() !== PHP_SESSION_ACTIVE) {
			return null;
		}
		if ( ! isset($_SESSION[TouchPointWP::SETTINGS_PREFIX . $what])) {
			return null;
		}

		return $_SESSION[TouchPointWP::SETTINGS_PREFIX . $what];
	}

	/**
	 * Standard setter
	 *
	 * @param string $what
	 * @param mixed  $value
	 *
	 * @return void
	 */
	public function __set(string $what, $value): void
	{
		if (session_status() !== PHP_SESSION_ACTIVE) {
			return;
		}
		if ($value === null) {
			unset($_SESSION[TouchPointWP::SETTINGS_PREFIX . $what]);
		} else {
			$_SESSION[TouchPointWP::SETTINGS_PREFIX . $what] = $value;
		}
	}
}