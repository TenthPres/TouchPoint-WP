<?php

namespace tp\TouchPointWP;

use WP_Error;
use WP_User;
use WP_User_Query;

/**
 * RSVP class file.
 *
 * Class Rsvp
 * @package tp\TouchPointWP
 */

if ( ! defined('ABSPATH')) {
    exit(1);
}

/**
 * The Auth-handling class.
 */
class Auth extends Component
{
    protected const LOGIN_TIMEOUT = 30; // number of seconds during which the user login tokens are valid.
    protected const SESSION_TIMEOUT = 600; // number of seconds during which the login link is valid (amount of time
                                           // before the login page (silently) expires.

    private static ?Auth $_singleton = null;
    private TouchPointWP $tpwp;

    /**
     * Auth constructor.
     *
     * @param TouchPointWP $tpwp
     */
    private function __construct(TouchPointWP $tpwp)
    {
        $this->tpwp = $tpwp;

        // Start the session
        add_action('login_init', [$this, 'startSession'], 10);

        // The authenticate filter
        add_filter('authenticate', [$this, 'authenticate'], 1, 3);

        // Add the link to the organization's sign-in page
        add_action('login_form', [$this, 'printLoginLink']);

        // Clear session variables when logging out
//        add_action( 'wp_logout', [$this, 'logout'] );

        // If configured, bypass the login form and redirect straight to TouchPoint
//        add_action( 'login_init', 'tp\\TouchPointWP\\Auth::save_redirect_and_maybe_bypass_login', 20 );

        // Redirect user back to original location
//        add_filter( 'login_redirect', 'tp\\TouchPointWP\\Auth::redirect_after_login', 20, 3 );
    }

    /**
     * @param TouchPointWP $tpwp
     *
     * @return Auth
     */
    public static function init(TouchPointWP $tpwp)
    {
        if (self::$_singleton === null) {
            self::$_singleton = new self($tpwp);
        }

        return self::$_singleton;
    }

    /**
     * Starts a new session.
     */
    public static function startSession()
    {
        if ( ! session_id()) {
            session_start();
        }
    }

    public static function registerScriptsAndStyles()
    {
    }

    /**
     * Renders the link used to login through TouchPoint.
     */
    public function printLoginLink()
    {
        $html = '<p class="touchpoint-wp-auth-form-text">';
        $html .= '<a href="%s">';
        $html .= sprintf(
            __('Sign in with your %s account', 'TouchPoint-WP'),
            htmlentities($this->tpwp->settings->auth_display_name)
        );
        $html .= '</a><br /><a class="dim" href="%s">'
                 . __('Sign out', 'TouchPoint-WP') . '</a></p>';
        printf(
            $html,
            $this->getLoginUrl(),
            $this->getLogoutUrl()
        );
    }

    /**
     * Generates the URL used to initiate a sign-in with TouchPoint.
     *
     * @return string The authorization URL used for a TouchPoint login.
     */
    public function getLoginUrl()
    {
        $antiforgeryId                                                 = self::generateAntiForgeryId(self::SESSION_TIMEOUT);
        $_SESSION[TouchPointWP::SETTINGS_PREFIX . 'auth_sessionToken'] = $antiforgeryId;

        return $this->tpwp->host() . '/PyScript/' . $this->tpwp->settings->auth_script_name . '?' . http_build_query(
                [
                    'redirect_to'  => $_GET['redirect_to'],
                    'sessionToken' => $antiforgeryId
                ]
            );
    }

    /**
     * Get a random string with a timestamp on the end.
     *
     * @param int $timeout  How long the token should last.
     *
     * @return string
     */
    protected static function generateAntiForgeryId(int $timeout)
    {
        return strtolower(substr(com_create_guid(), 1, 36) . "-" . dechex(time() + $timeout));
    }

    /**
     * Generates the URL for logging out of TouchPoint. (Does not log out of WordPress.)
     */
    public function getLogoutUrl()
    {
        return $this->tpwp->host() . "/Account/LogOff/";
    }

    /**
     * Authenticates the user with TouchPoint
     *
     * @param WP_User|WP_Error $user A WP_User, if the user has already authenticated.
     * @param string           $username The username provided during form-based sign in. Not used.
     * @param string           $password The password provided during form-based sign in. Not used.
     *
     * @return WP_User|WP_Error The authenticated WP_User, or a WP_Error if there were errors.
     */
    public function authenticate($user, $username, $password)
    {
        // Don't re-authenticate if already authenticated
        if (is_a($user, 'WP_User')) {
            return $user;
        }

        // If 'loginToken' is present, this is the Authorization Response looping back through TouchPoint.
        if (isset($_GET['loginToken'])) {
            // Verify that the login token is valid.
            if ( ! Auth::AntiForgeryTimestampIsValid($_GET['loginToken'], self::LOGIN_TIMEOUT)) {
                return new WP_Error(
                    'expired_login_token',
                    __('Your login credential expired.', 'TouchPoint-WP')
                );
            }

            // Find the user with the loginToken in their meta.
            $q = new WP_User_Query(
                [
                    'meta_key'     => TouchPointWP::SETTINGS_PREFIX . 'loginToken',
                    'meta_value'   => $_GET['loginToken'],
                    'meta_compare' => '='
                ]
            );
            if ($q->get_total() < 1) {
                return new WP_Error(
                    'invalid_login_token',
                    __('Your login credential is invalid.', 'TouchPoint-WP')
                );
            }
            /** @var WP_User $user */
            $user = $q->get_results()[0];

            // Get loginSessionToken
            $lst = get_user_meta($user->ID, TouchPointWP::SETTINGS_PREFIX . 'loginSessionToken');

            // Remove meta fields
            if ( ! (update_user_meta($user->ID, TouchPointWP::SETTINGS_PREFIX . 'loginToken', null) &&
                    update_user_meta($user->ID, TouchPointWP::SETTINGS_PREFIX . 'loginSessionToken', null))
            ) {
                return new WP_Error(
                    [
                        'meta_clearing_failed',
                        __('Unable to clear tokens from user profile.', 'TouchPoint-WP')
                    ]
                );
            }

            // Verify that LST from Meta (from TouchPoint) matches Session.  Prevents login by link sharing.
            if ( ! $lst === $_SESSION[TouchPointWP::SETTINGS_PREFIX . 'auth_sessionToken']) {
                return new WP_Error(
                    [
                        'LST_not_recognized',
                        __('Session could not be validated.', 'TouchPoint-WP')
                    ]
                );
            }

            return $user;
        } elseif (isset($_GET['action']) && $_GET['action'] === 'touchpoint') { // TODO move to an API endpoint.
            // The TouchPoint script is posting data to WordPress.

            // Check that the application secret is valid.
            if (getallheaders()['X-API-KEY'] !== $this->tpwp->settings->getApiKey()) {
                self::apiError(
                    'invalid_key',
                    __('ERROR: Access denied.  API Key is not valid.', 'TouchPoint-WP')
                );
            }

            // Get POSTed data
            $input = file_get_contents('php://input');

            // Check that request is coming from an allowed IP.
            $ips = str_replace('\r', '', ($this->tpwp->settings->ip_whitelist ?: TouchPointWP::DEFAULT_IP_WHITELIST));
            $ips = explode('\n', $ips);
            if (isset($_SERVER['REMOTE_ADDR'])) {
                if ((WP_DEBUG && $_SERVER['REMOTE_ADDR'] === "127.0.0.1") || in_array($_SERVER['REMOTE_ADDR'], $ips)) {
                    return $this->handleTouchPointAuthData($input);
                }
            }
            if (isset(getallheaders()['x-real-ip'])) {
                if (in_array(getallheaders()['x-real-ip'], $ips)) {
                    return $this->handleTouchPointAuthData($input);
                }
            }

            // The attempt was probably blocked by IP.
            self::apiError(
                'remote_forbidden',
                __('ERROR: Access denied.  Remote forbidden.', 'TouchPoint-WP')
            );
        }

        if (is_a($user, 'WP_User')) {
            $_SESSION['TouchPoint-WP_signed_in_with_auth'] = true;
        }

        return $user;
    }

    /**
     * @param string $afid Anti-forgery ID.
     *
     * @param int    $timeout
     *
     * @return bool True if the timestamp hasn't expired yet.
     */
    protected static function AntiForgeryTimestampIsValid(string $afid, int $timeout)
    {
        $afidTime = hexdec(substr($afid, 37));

        return ($afidTime <= time() + $timeout) && $afidTime >= time();
    }

    /**
     * Print a JSON object that reflects an API Error, and exit.
     *
     * @param $code
     * @param $message
     */
    protected static function apiError($code, $message) // TODO potentially move to an API endpoint.
    {
        echo json_encode(
            [
                'status'  => 'failure',
                'code'    => $code,
                'message' => $message
            ]
        );

        exit(1);
    }

    /**
     * @param string $data Data POSTed by TouchPoint
     *
     * @return void Prints response and terminates.
     */
    protected function handleTouchPointAuthData(string $data)
    {
        $data = json_decode($data);

        // work-around to format data until PR #1127 is merged.  TODO remove after PR #1127 is merged.
        if (is_array($data) && count($data) === 1) {
            $data = json_decode($data[0]);
        }

        // Make sure sessionToken is valid
        if ( ! isset($data->sessionToken) ||
             ! Auth::AntiForgeryTimestampIsValid($data->sessionToken, self::SESSION_TIMEOUT)) {
            self::apiError(
                'no_session_token',
                __('You don\'t appear to have a current session on our website.', 'TouchPoint-WP')
            );
        }

        // get user.  Returns WP_User if one is found or created, false otherwise.
        $user = $this->getWpUserFromTouchPointData($data);

        if ($user === false) {
            self::apiError(
                'no_account',
                __('No user account found.  Consider enabling auto-provisioning.', 'TouchPoint-WP')
            );
        }

        // Update user with current data.
        $this->updateWpUserWithTouchPointData($user, $data->p);


        // Generate login token and response.
        $resp = [
            'status'         => 'success',
            'userLoginToken' => $this->generateAntiForgeryId(self::LOGIN_TIMEOUT),
            'wpid'           => $user->ID
        ];

        // TODO periodically send an updated API Key.

        if ( ! (update_user_meta($user->ID, TouchPointWP::SETTINGS_PREFIX . 'loginToken', $resp['userLoginToken']) &&
                update_user_meta($user->ID, TouchPointWP::SETTINGS_PREFIX . 'loginSessionToken', $data->sessionToken))
        ) {
            self::apiError(
                'meta_update_failed',
                __('Unable to save tokens to user profile.', 'TouchPoint-WP')
            );
        }

        echo json_encode($resp);

        exit(0);
    }

    /**
     * Find or create (if provisioning is enabled) a WP user to match the data from TouchPoint
     *
     * @param object $data
     *
     * @return false|WP_User  The WP user object, or false on failure.
     */
    protected function getWpUserFromTouchPointData(object $data)
    {
        // Find user based on WordPress ID
        if (isset($data->p->wpid) && $data->p->wpid > 0) {
            $user = get_user_by('id', $data->p->wpid);
            if ( ! ! $user) // verify that a user was found.
            {
                return $user;
            }
        }

        // Find user based on PeopleId
        if (isset($data->p->obj->PeopleId)) {
            $q = new WP_User_Query(
                [
                    'meta_key'     => TouchPointWP::SETTINGS_PREFIX . 'peopleId',
                    'meta_value'   => $data->p->obj->PeopleId,
                    'meta_compare' => '='
                ]
            );
            if ($q->get_total() === 1) {
                return $q->get_results()[0];
            }
        }

        // TODO figure out what to do with TP users without an email address
        // TODO figure out what to do with TP users with an "inactive" primary email address.

        if ($this->tpwp->settings->auth_auto_provision === 'on') {
            // Provision a new user, since we were unsuccessful in finding one.
            $uid = wp_create_user(self::generateUserName($data->p), com_create_guid(), $data->p->obj->EmailAddress);
            if (is_numeric($uid)) { // user was successfully generated.
                update_user_meta($uid, 'created_by', 'TouchPoint-WP');

                return new WP_User($uid);
            }
        }

        // user was not successfully generated.
        return false;
    }

    /**
     * Generates a username for a new WordPress user based on TouchPoint data.
     *
     * @param object $pData
     *
     * @return string  A viable, available username.
     */
    protected static function generateUserName(object $pData)
    {
        // Best.  Matches TouchPoint username.  However, it's possible users won't have usernames.
        if (isset($pData->obj->Usernames[0])) {
            $try = $pData->obj->Usernames[0];
            if ( ! username_exists($try)) {
                return $try;
            }
        }

        // Better.  Concat of full name.  Does not intersect with above.
        $try = strtolower($pData->obj->Name);
        $try = preg_replace('/[^\w\d]+/g', '', $try);
        if ( ! username_exists($try)) {
            return $try;
        }

        // Good.  Full name, plus the ID.
        $try .= $pData->obj->PeopleId;
        if ( ! username_exists($try)) {
            return $try;
        }

        // Works.  Not human-readable.  But, unlikely to happen.
        return "touchpoint-" . $pData->obj->PeopleId;
    }

    /**
     * @param WP_User $user The User object to update.
     * @param object  $pData The person data object from TouchPoint.
     */
    protected function updateWpUserWithTouchPointData(WP_User $user, object $pData)
    {
        // Prevent password change email.
        add_filter('send_password_change_email', '__return_false');

        wp_update_user(
            [
                'ID'         => $user->ID,

                'user_email' => $pData->obj->EmailAddress,
                'nickname'   => $pData->obj->Name,
                'first_name' => $pData->obj->FirstName,
                'last_name'  => $pData->obj->LastName,

                TouchPointWP::SETTINGS_PREFIX . 'peopleId' => $pData->obj->PeopleId
            ]
        );

        // Restores password change email, so password changes though other mechanisms still work.
        remove_filter('send_password_change_email', '__return_false');

//        update_user_meta($user->ID, 'description', $pData->ev->bio);  TODO import bios or other Extra Values.
    }

}