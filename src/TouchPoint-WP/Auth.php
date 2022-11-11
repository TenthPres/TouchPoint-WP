<?php
/**
 * @package TouchPointWP
 */
namespace tp\TouchPointWP;

use tp\TouchPointWP\Utilities\PersonQuery;
use tp\TouchPointWP\Utilities\Session;
use WP_Error;
use WP_User;

if ( ! defined('ABSPATH')) {
    exit(1);
}

/**
 * The Auth-handling class.
 */
abstract class Auth implements api
{
    protected const LOGIN_TIMEOUT_STANDARD = 30;    // number of seconds during which the user login tokens are valid.
    protected const API_KEY_TIMEOUT = 86400;        // How long until an API key needs to be replaced.
    protected const SESSION_TIMEOUT = 600;          // number of seconds during which the login link is valid (amount of time
                                                    // before the login page (silently) expires).
    private static bool $_isLoaded = false;

    public static function init(): void
    {
        // Start the session
        add_action('login_init', [self::class, 'startSession'], 10);

        // The authentication filter
        add_filter('authenticate', [self::class, 'authenticate'], 1, 3);

        // Add the link to the church's sign-in page
        add_action('login_form', [self::class, 'printLoginLink']);

        // Reroute 'edit profile' links to the user's TouchPoint profile.
        add_filter('edit_profile_url', [self::class, 'overwriteProfileUrl']);

        // Clear session variables when logging out
        add_action( 'wp_logout', [self::class, 'logout'] );

        // Auto Login content, when appropriate.
        add_action( 'wp_footer', [self::class, 'footer'] );

        // If configured, bypass the login form and redirect straight to TouchPoint
        add_action('login_init', [self::class, 'redirectLoginFormMaybe'], 20);

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

        //////////////////
        /// Shortcodes ///
        //////////////////

        ///////////////
        /// Syncing ///
        ///////////////

        if (is_admin()) {
            try {
                self::createApiKeyIfNeeded();
            } catch (TouchPointWP_Exception $e) {}
        }

        return true;
    }

    /**
     * Starts a new session.
     */
    public static function startSession()
    {
        Session::startSession();
    }

    /**
     * Clear variables and potentially create a flag for the logout of TouchPoint.
     *
     * Does NOT actually log out of WordPress as this should be called by wp_logout, which accomplishes that.
     */
    public static function logout()
    {
        Session::sessionDestroy();
        $tpwp = TouchPointWP::instance();
        if ($tpwp->settings->auth_full_logout === "on") {
            $redir = $tpwp->host() . '/PyScript/' . $tpwp->settings->api_script_name . '?' . http_build_query(
                    [
                        'r' => $_GET['redirect_to'] ?? get_site_url(),
                        'a' => "logout"
                    ]
                );
            wp_redirect($redir);
            die;
        }
    }

    /**
     * Placeholder for automatic login.
     */
    public static function footer()
    {
        // echo to print in footer
    }

    /**
     * Renders the link used to log in through TouchPoint.
     */
    public static function printLoginLink()
    {
        $html = '<p class="touchpoint-wp-auth-form-text">';
        /** @noinspection HtmlUnknownTarget */
        $html .= '<a href="%s">';
        $html .= sprintf(
            __('Sign in with your %s account', 'TouchPoint-WP'),
            htmlentities(TouchPointWP::instance()->settings->system_name)
        );
        /** @noinspection HtmlUnknownTarget */
        $html .= '</a><br /><a class="dim" href="%s">'
                 . __('Sign out', 'TouchPoint-WP') . '</a></p>';
        printf(
            $html,
            self::getLoginUrl(),
            self::getLogoutUrl()
        );
    }

    /**
     * Generates the URL used to initiate a sign-in with TouchPoint.
     *
     * @return string The authorization URL used for a TouchPoint login.
     * @noinspection SpellCheckingInspection
     */
    public static function getLoginUrl(): string
    {
        try {
            self::createApiKeyIfNeeded();
        } catch (TouchPointWP_Exception $e) {}

        $antiforgeryId = self::generateAntiForgeryId();

        $s = Session::instance();
        $s->auth_sessionToken = $antiforgeryId;

        $tpwp = TouchPointWP::instance();

        return $tpwp->host() . '/PyScript/' . $tpwp->settings->api_script_name . '?' . http_build_query(
            [
                'r'  => $_GET['redirect_to'] ?? get_site_url(),
                'sToken' => $antiforgeryId,
                'a' => "login"
            ]
        );
    }

    /**
     * Get a random string with a timestamp on the end.
     *
     * @return string
     */
    public static function generateAntiForgeryId(): string
    {
        return strtolower(substr(Utilities::createGuid(), 0, 36) . "-" . dechex(time()));
    }

    /**
     * @param ?string $key   The api key to test against.  If no key is provided, validates the saved key.
     * @param ?string $host  The http hostname to use for this key. Will use $_SERVER['HTTP_HOST'] if no value is provided.
     *
     * @return string|bool
     * Returns true if the key is valid.
     * Returns false is the key is invalid.
     * Returns a new key if the provided key is valid, but expired. (Does not send it to the server -- that needs to be handled separately.)
     */
    public static function validateApiKey(string $key = null, string $host = null)
    {
        if ($host === null) {
            $host = $_SERVER['HTTP_HOST'];
        }

        $host = str_replace('.', '_', $host);
        $tpwp = TouchPointWP::instance();

        $k = $tpwp->settings->get('api_key_' . $host);
        if ($k === false) {
            return self::replaceApiKey($host);
        }

        if ($key !== null && $key !== $k) {
            return false;
        }

        if (! self::AntiForgeryTimestampIsValid($k, self::API_KEY_TIMEOUT)) {
            return self::replaceApiKey($host);
        }

        return true;
    }

    /**
     * Generates a key and saves it.
     *
     * @param $host
     *
     * @return string
     */
    public static function replaceApiKey($host): string
    {
        $tpwp = TouchPointWP::instance();
        $host = str_replace('.', '_', $host);

        $key = Auth::generateAntiForgeryId();
        $tpwp->settings->set('api_key_' . $host, $key);
        return $key;
    }

    /**
     * @return void
     * @throws TouchPointWP_Exception
     */
    private static function createApiKeyIfNeeded(): void
    {
        $host = $_SERVER['HTTP_HOST'];
        $k = self::validateApiKey(null, $host); // will return true or a new key.

        if ($k !== true) {  // Only if the saved key is unset or invalid.  (
            TouchPointWP::instance()->apiPost("auth_key_set", [
                'apiKey' => $k,
                'host' => $host
            ]);
        }
    }

    /**
     * Generates the URL for logging out of TouchPoint. (Does not log out of WordPress.)
     */
    public static function getLogoutUrl(): string
    {
        return TouchPointWP::instance()->host() . "/Account/LogOff/";
    }

    /**
     * Determines whether to redirect to the TouchPoint login automatically, and does so if appropriate.
     */
    public static function redirectLoginFormMaybe()
    {
        $redirect = apply_filters(
            TouchPointWP::HOOK_PREFIX . 'auto_redirect_login',
            (TouchPointWP::instance()->settings->auth_default === 'on')
        );

        if (isset($_GET[TouchPointWP::HOOK_PREFIX . 'no_redirect'])) {
            $redirect = false;
        }

        if (self::wantsToLogin() && $redirect && $_SERVER['REQUEST_METHOD'] === "GET") {
            wp_redirect(self::getLoginUrl());
            die();
        }
    }

    /**
     * Prevents the admin bar from being displayed for users who can't edit or change anything.
     */
    public static function removeAdminBarMaybe()
    {
        $removeBar = (TouchPointWP::instance()->settings->auth_prevent_admin_bar === 'on')
                     && !is_admin()
                     && current_user_can('subscriber');

        $removeBar = apply_filters(TouchPointWP::HOOK_PREFIX . 'prevent_admin_bar', $removeBar );

        if ($removeBar) {
            show_admin_bar(false);
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
        $wants_to_login = false;
        // redirect back from TouchPoint after a successful login
        if (isset($_GET['loginToken'])) {
            return false;
        }

        // Default WordPress behavior
        $action = $_REQUEST['action'] ?? 'login';

        // Exceptions
        $action = isset($_GET['loggedout']) ? 'loggedout' : $action;
        if ('login' == $action) {
            $wants_to_login = true;
        }

        return $wants_to_login;
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
            $userId   = get_current_user_id();
            $peopleId = (int)(get_user_meta($userId, Person::META_PEOPLEID, true));
            if ($peopleId > 0) { // make sure we have a PeopleId.  Users aren't necessarily TouchPoint users.
                return $tpwp->host() . '/Person2/' . $peopleId . "#tab-personal";
            }
        }
        return $url;
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
            case "token":
                if ($_SERVER['REQUEST_METHOD'] !== "POST") {
                    return false;
                }
                self::handlePostFromTouchPoint();
                exit;
        }

        return false;
    }

    /**
     * Authenticates the user with TouchPoint
     *
     * @param WP_User|WP_Error $user A WP_User, if the user has already authenticated.
     * @param mixed            $username The username provided during form-based sign in. Not used.
     * @param mixed            $password The password provided during form-based sign in. Not used.
     *
     * @return WP_User|WP_Error The authenticated WP_User, or a WP_Error if there were errors.  The WP API expects WP_Error
     *
     * @noinspection PhpUnusedParameterInspection  We don't use the username or password, but they're in the WP API.
     */
    public static function authenticate($user, $username, $password)
    {
        // Don't re-authenticate if already authenticated
        if (is_a($user, 'WP_User')) {
            return $user;
        }

        // If 'loginToken' is present, this is the Authorization Response looping back through TouchPoint.
        if (isset($_GET['loginToken'])) {
            // Verify that the login token is valid.
            if ( ! self::AntiForgeryTimestampIsValid($_GET['loginToken'], self::LOGIN_TIMEOUT_STANDARD)) {
                return new WP_Error(
                    177002,
                    __('Your login token expired.', 'TouchPoint-WP') . "<br />" . $_GET['loginToken']
                );
            }

            // Find the user with the loginToken in their meta.
            $q = new PersonQuery(
                [
                    'meta_key'     => TouchPointWP::SETTINGS_PREFIX . 'loginToken',
                    'meta_value'   => $_GET['loginToken'],
                    'meta_compare' => '='
                ]
            );
            if ($q->get_total() !== 1) {
                return new WP_Error(
                    177003,
                    __('Your login token is invalid.', 'TouchPoint-WP')
                );
            }

            $p = $q->get_results()[0];
            $lst = $p->loginSessionToken;

            // Verify that LST from Meta (from TouchPoint) matches Session.  Prevents login by link sharing.
	        $s = Session::instance();
            if ( ! $lst === $s->auth_sessionToken) {
                return new WP_Error([
                    177004,
                    __('Session could not be validated.', 'TouchPoint-WP')
                ]);
            }

            $p->setLoginTokens(null, null);
			$s->auth_sessionToken = null;

            $user = $p->toNewWpUser();
        }

        return $user;
    }

    /**
     * @param string $afId Anti-forgery ID.
     *
     * @param int    $timeout
     *
     * @return bool True if the timestamp hasn't expired yet.
     */
    protected static function AntiForgeryTimestampIsValid(string $afId, int $timeout): bool
    {
        $afIdTime = hexdec(substr($afId, 37));

        return ($afIdTime >= time() - $timeout) && $afIdTime <= time();
    }

    /**
     * Handles the data POSTed by TouchPoint at the start of a login transaction
     *
     * @return void
     */
    protected static function handlePostFromTouchPoint() {
        try {
            // Check that the application secret is valid.
            $apiKeyValidation = self::validateApiKey(Utilities::getAllHeaders()['X-Api-Key']);
            if ($apiKeyValidation === false) {
                throw new TouchPointWP_Exception(
                    'Access denied.  API Key is not valid.',
                    177005
                );
            }

            // Get data POSTed by TouchPoint
            $data = file_get_contents('php://input');
            $data = json_decode($data);

            // Make sure session token is valid
            if ( ! isset($data->sToken) || ! self::AntiForgeryTimestampIsValid($data->sToken, self::SESSION_TIMEOUT)) {
                throw new TouchPointWP_Exception("No Session Exists", 177006);
            }

            // Get user.  Returns WP_User if one is found or created, false otherwise.
            $person = Person::updatePersonFromApiData($data->p);

            // Get user's relatives. They don't need to be users--they can just be objects.  TODO

            if ($person === null) {
                throw new TouchPointWP_Exception(
                    'No user account found.  If you\'re a site administrator, consider enabling auto-provisioning.',
                    177007
                );
            }

            // Generate login token and response.
            $userLoginToken = self::generateAntiForgeryId();
            $tpwp           = TouchPointWP::instance();

            /** @noinspection SpellCheckingInspection */
            $resp = [
                'status'         => 'success',
                'userLoginToken' => $userLoginToken,
                'wpid'           => $person->ID,
                'wpevk'          => $tpwp->settings->people_ev_wpId
            ];

            if ($apiKeyValidation !== true) {
                $resp['apiKey'] = $apiKeyValidation;
            }

            $person->setLoginTokens($data->sToken, $userLoginToken);

            echo json_encode($resp);

            exit(0);

        } catch (TouchPointWP_Exception $e) {
            echo $e->toJson();
            exit(1);
        }
    }
}