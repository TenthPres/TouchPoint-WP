<?php
/**
 * @package TouchPointWP
 */

namespace tp\TouchPointWP;

use Exception;
use tp\TouchPointWP\Interfaces\api;
use tp\TouchPointWP\Interfaces\module;
use tp\TouchPointWP\Utilities\Http;
use tp\TouchPointWP\Utilities\PersonQuery;
use tp\TouchPointWP\Utilities\Session;
use WP_Error;
use WP_User;

if ( ! defined('ABSPATH')) {
	exit(1);
}

/**
 * Allows users to log in to WordPress with their TouchPoint credentials, and provides other user management
 * functionality.
 */
abstract class Auth implements api, module
{
	/** @noinspection SpellCheckingInspection */
	protected const LOGIN_PARAMETER = 'tptoken';

	private static bool $_isLoaded = false;

	public static function init(): void
	{
		// Start the session
//		add_action('login_init', [self::class, 'startSession'], 10);  TODO remove

		// The authentication filter
		add_filter('authenticate', [self::class, 'authenticate'], 1, 3);

		// Add the link to the church's sign-in page
		add_action('login_form', [self::class, 'printLoginLink']);

		// Reroute 'edit profile' links to the user's TouchPoint profile.
		add_filter('edit_profile_url', [self::class, 'overwriteProfileUrl']);

		// Clear session variables when logging out
		add_action('wp_logout', [self::class, 'logout']);

		// Auto Login content, when appropriate.
		add_action('wp_footer', [self::class, 'footer']);

		// If configured, bypass the login form and redirect straight to TouchPoint
		add_action('login_init', [self::class, 'redirectLoginFormMaybe'], 20);

		// If configured, upon login, if no redirect is specified, redirect to the homepage
		add_filter('login_redirect', [self::class, 'redirectLoginCompleteMaybe'], 10, 3);

		// If configured, prevent admin bar from appearing for subscribers
		add_action('after_setup_theme', [self::class, 'removeAdminBarMaybe']);
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

		// If configured, prevent user from accessing the admin area
		add_action('admin_init', [self::class, 'preventAdminAccessMaybe']);

		//////////////////
		/// Shortcodes ///
		//////////////////

		///////////////
		/// Syncing ///
		///////////////

		return true;
	}


	/**
	 * Clear variables and potentially create a flag for the logout of TouchPoint.
	 */
	public static function logout(): void
	{
		wp_set_current_user(0);

		$tpwp = TouchPointWP::instance();
		if ($tpwp->settings->auth_full_logout === "on") {
			$redir = $tpwp->host() . '/PyScript/' . $tpwp->settings->api_script_name . '?' . http_build_query([
				'r' => $_GET['redirect_to'] ?? get_site_url(),
				'a' => "logout"
			]);

			wp_redirect($redir, Http::SEE_OTHER_TEMP);
			exit;
		}
	}


	/**
	 * Placeholder for automatic login.
	 *
	 * TODO: this
	 */
	public static function footer()
	{
		// echo to print in footer
	}


	/**
	 * Renders the link used to log in through TouchPoint.
	 */
	public static function printLoginLink(): void
	{
		$html = '<p class="touchpoint-wp-auth-form">';
		$url = self::getLoginUrl();
		/** @noinspection HtmlUnknownTarget */
		$html .= "<a href=\"$url\" class=\"button button-secondary button-large\" style=\"width: 100%; text-align: center; margin-bottom: 1em;\">";
		$html .= sprintf(
			// translators: %s is "what you call TouchPoint at your church", which is a setting
			__('Sign in with %s', 'TouchPoint-WP'),
			htmlentities(TouchPointWP::instance()->settings->system_name)
		);
		$html .= '</a></p>';
		echo $html;
	}


	/**
	 * Generates the URL used to initiate a sign-in with TouchPoint.
	 *
	 * @return string The authorization URL used for a TouchPoint login.
	 */
	public static function getLoginUrl(): string
	{
		$tpwp = TouchPointWP::instance();

		$redirectTo = $_GET['redirect_to'] ?? get_site_url();

		return $tpwp->host() . '/PyScript/' . $tpwp->settings->api_script_name . '?' . http_build_query(
				[
					'r'      => $redirectTo,
					'a'      => "login"
				]
			);
	}


	/**
	 * Determines whether to redirect to the TouchPoint login automatically, and does so if appropriate.
	 */
	public static function redirectLoginFormMaybe(): void
	{
		$redirect = TouchPointWP::instance()->settings->auth_default === 'on';
		/**
		 * Controls whether to redirect to the TouchPoint login automatically.
		 *
		 * @param bool $redirect Value preset from setting TouchPoint login as default.
		 */
		$redirect = apply_filters('tp_auto_redirect_login', $redirect);

		if (isset($_GET[TouchPointWP::HOOK_PREFIX . 'no_redirect'])) {
			$redirect = false;
		}

		if (self::wantsToLogin() && $redirect && $_SERVER['REQUEST_METHOD'] === "GET") {
			wp_redirect(self::getLoginUrl(), Http::SEE_OTHER_TEMP);
			exit();
		}
	}


	/**
	 * Determines whether to redirect to allow the user to continue to the destination page after logging in.
	 */
	public static function redirectLoginCompleteMaybe(string $redirect_to, ?string $requested_redirect_to = null, WP_User|WP_Error|null $user = null): string
	{
		if (!is_a($user, 'WP_User')) {
			return $redirect_to;
		}

		$redirect = TouchPointWP::instance()->settings->auth_change_profile_urls === 'on';
		$redirect &= !TouchPointWP::userHasEditingPermissions();

		/**
		 * Controls whether to redirect to the TouchPoint login automatically.
		 *
		 * @param bool $redirect Value preset from setting TouchPoint login as default.
		 */
		$redirect = apply_filters('tp_redirect_after_login', $redirect);

		// if there is no defined redirect page, redirect to the home page
		if ($redirect && (!isset($_GET['redirect_to']) || $_GET['redirect_to'] == '')) {
			return home_url();
		}

		return $redirect_to;
	}


	/**
	 * Prevents the admin bar from being displayed for users who can't edit or change anything.
	 */
	public static function removeAdminBarMaybe(): void
	{
		$removeBar = (TouchPointWP::instance()->settings->auth_prevent_admin_bar === 'on')
					 && !is_admin()
					 && !current_user_can('edit_posts');

		/**
		 * Allows for hiding the WordPress-provided Admin bar.
		 *
		 * @param bool $removeBar True if bar should be removed.
		 */
		$removeBar = apply_filters('tp_prevent_admin_bar', $removeBar);

		if ($removeBar) {
			show_admin_bar(false);
		}
	}


	/**
	 * Prevents access to the WordPress admin area for users who can't edit or change anything.
	 *
	 * @return void
	 */
	public static function preventAdminAccessMaybe(): void
	{
		$preventAdmin = (TouchPointWP::instance()->settings->auth_change_profile_urls === 'on')
					 && is_admin()  // means: request is in the admin area, not that user is an admin.
					 && !TouchPointWP::userHasEditingPermissions();


		$destination = null;

		if ($preventAdmin) {
			// if profile.php, redirect to the profile page
			if (isset($_SERVER['REQUEST_URI']) && str_contains($_SERVER['REQUEST_URI'], 'profile.php')) {
				$destination = self::getProfileUrl();
			} else {
				// otherwise, redirect to the home page
				$destination = home_url();
			}
		}

		/**
		 * Allows for preventing access to the WordPress admin area.
		 *
		 * @param ?string $destination The url to which the user should be redirected, or null to allow default behavior.
		 */
		$destination = apply_filters('tp_admin_area_redirect', $destination);

		if ($destination) {
			wp_redirect($destination, Http::SEE_OTHER_TEMP);
			exit;
		}
	}


	/**
	 * Checks to determine if the user wants to log in.
	 *
	 * This is meant to handle a variety of oddities in how WordPress sometimes--but not always--makes intent clear.
	 *
	 * @return bool Whether the user is trying to log in to the site
	 */
	private static function wantsToLogin(): bool
	{
		$wantsToLogin = false;
		// redirect back from TouchPoint after a successful login
		if (isset($_GET[self::LOGIN_PARAMETER])) {
			return false;
		}

		// Default WordPress behavior
		$action = $_REQUEST['action'] ?? 'login';

		// Exceptions
		$action = isset($_GET['loggedout']) ? 'loggedout' : $action;
		if ('login' == $action) {
			$wantsToLogin = true;
		}

		return $wantsToLogin;
	}


	/**
	 * Replace the default WordPress profile link with a link to the user's TouchPoint profile.
	 *
	 * @param string $url
	 *
	 * @return string
	 */
	public static function overwriteProfileUrl(string $url): string
	{
		$tpwp = TouchPointWP::instance();
		if ($tpwp->settings->auth_change_profile_urls === 'on') {
			$newUrl = self::getProfileUrl();
			if ($newUrl)
				return $newUrl;
		}

		return $url;
	}

	/**
	 * Assembles the URL to the TouchPoint profile for a given People ID.  Assumes current user if no peopleId is given.
	 *
	 * @param int|null $peopleId
	 *
	 * @return string|null
	 */
	public static function getProfileUrl(?int $peopleId = null): ?string
	{
		$tpwp = TouchPointWP::instance();
		if ($peopleId === null) {
			$userId   = get_current_user_id();
			$peopleId = (int)(get_user_meta($userId, Person::META_PEOPLEID, true));
		}
		if ($peopleId >= 0) {
			return $tpwp->host() . '/Person2/' . $peopleId . "#tab-personal";
		}

		return null;
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
		if (count($uri['path']) < 3) {
			return false;
		}

		switch (strtolower($uri['path'][2])) {
			case "login.js":   // Some hosts bypass PHP for js extensions, so this doesn't work.
			case "login.jsr":
				wp_redirect(content_url('/plugins/touchpoint-wp/ext/login.js'), Http::SEE_OTHER_TEMP);
				exit;
		}

		return false;
	}


	/**
	 * Authenticates the user with TouchPoint
	 *
	 * @param WP_Error|WP_User|null $user A WP_User, if the user has already authenticated.
	 * @param mixed                 $username The username provided during form-based sign in. Not used.
	 * @param mixed                 $password The password provided during form-based sign in. Not used.
	 *
	 * @return WP_Error|WP_User|null The authenticated WP_User, or a WP_Error if there were errors.  The WP API expects
	 *     WP_Error
	 *
	 * @noinspection PhpUnusedParameterInspection  We don't use the username or password, but they're in the WP API.
	 */
	public static function authenticate(WP_Error|WP_User|null $user, mixed $username, mixed $password): WP_Error|WP_User|null
	{
		// Don't re-authenticate if already authenticated
		if (is_a($user, 'WP_User')) {
			return $user;
		}

		// If parameter token is present, this is the Authorization Response looping back through TouchPoint.
		if (isset($_GET[self::LOGIN_PARAMETER])) {
			// Verify that the login token is valid.
			$api = TouchPointWP::instance()->api;

			try {
				$r = $api->post("/api/v1/Account/ValidateOneTimeLogin", data: $_GET[self::LOGIN_PARAMETER]);
			} catch (TouchPointWP_Exception $e) {
				return $e->toWpError();
			}

			if ($r['response']['code'] !== Http::OK) {
				$e = new TouchPointWP_Exception(__('Your login token is invalid.', 'TouchPoint-WP'), 177003);
				return $e->toWpError();
			}

			// other than peopleId and email addresses, this probably shouldn't really be used.  Run through WebPublicPerson.
			$userData = json_decode($r['body']);

			if (!isset($userData->peopleId)) {
				$e = new TouchPointWP_Exception(__('Your login token is invalid.', 'TouchPoint-WP'), 177003);
				return $e->toWpError();
			}

			// Find person by TouchPoint People ID, $userData->PeopleId
			$q = new PersonQuery(
				[
					'meta_key'     => Person::META_PEOPLEID,
					'meta_value'   => $userData->peopleId,
					'meta_compare' => '='
				]
			);
			if ($q->get_total() > 0) {
				$p = $q->get_first_result();
				$user = $p->toNewWpUser();

				self::incrementUserAuthStat();
				return $user;
			}

			$pq = TouchPointWP::newQueryObject();
			$pq['pid'] = [(string)($userData->peopleId)];
			$pq['context'] = 'users';

			try {
				$pData = TouchPointWP::instance()->doPersonQuery($pq)->people;
			} catch (TouchPointWP_Exception $e) {
				return $e->toWpError();
			}

			if (count($pData) < 1) {
				$e = new TouchPointWP_Exception(__('No user account found.', 'TouchPoint-WP'), 177008);
				return $e->toWpError();
			}

			$allowCreation = TouchPointWP::instance()->settings->auth_auto_provision === 'on';
			$person        = Person::updatePersonFromApiData($pData[0], $allowCreation);

			if ($person === null) {
				$e = new TouchPointWP_Exception(
					'No user account found.  If you\'re a site administrator, consider enabling auto-provisioning.',
					177007
				);
				return $e->toWpError();
			} else {
				$user = $person->toNewWpUser();
				self::incrementUserAuthStat();
			}
		}

		return $user;
	}


	/**
	 * Increments the user authentication statistics.
	 *
	 * @return void
	 */
	protected static function incrementUserAuthStat(): void
	{
		try {
			$stats            = Stats::instance();
			$stats->userAuths += 1;
			$stats->updateDb();
		} catch (Exception) {
		}
	}
}