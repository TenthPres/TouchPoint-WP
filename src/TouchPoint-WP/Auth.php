<?php

namespace tp\TouchPointWP;

use WP_Error;
use WP_REST_Controller;
use WP_User;
use WP_User_Query;

/**
 * Auth class file.
 *
 * TODO Rework error handling in favor of exceptions.
 *
 * Class Auth
 * @package tp\TouchPointWP
 */

if ( ! defined('ABSPATH')) {
    exit(1);
}

/**
 * The Auth-handling class.
 */
class Auth extends WP_REST_Controller
{
    protected const LOGIN_TIMEOUT_STANDARD = 30;     // number of seconds during which the user login tokens are valid.
    protected const LOGIN_TIMEOUT_LINK = 10;     // number of seconds during which the user link tokens are valid.
    protected const SESSION_TIMEOUT = 600;  // number of seconds during which the login link is valid (amount of time
    // before the login page (silently) expires).

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

        // Add the link to the church's sign-in page
        add_action('login_form', [$this, 'printLoginLink']);

        // Reroute 'edit profile' links to the user's TouchPoint profile.
        add_filter('edit_profile_url', [$this, 'changeProfileUrl']);

        // Clear session variables when logging out
        add_action( 'wp_logout', [$this, 'logout'] );

        // Auto Login content, when appropriate.
        add_action( 'wp_footer', [$this, 'footer'] );

        // If configured, bypass the login form and redirect straight to TouchPoint
        add_action('login_init', [$this, 'redirectLoginFormMaybe'], 20);

        // If configured, prevent admin bar from appearing for subscribers
        add_action('after_setup_theme', [$this, 'removeAdminBarMaybe']);
    }

    /**
     * @param TouchPointWP $tpwp
     *
     * @return bool
     */
    public static function load(TouchPointWP $tpwp): bool
    {
        if (self::$_singleton === null) {
            self::$_singleton = new self($tpwp);
        }

        return true;
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

    /**
     * Clear variables and potentially create a flag for the logout of TouchPoint.
     */
    public function logout()
    {
        session_destroy(); // clear all existing variables
        if ($this->tpwp->settings->auth_full_logout === "on") {
            $redir = $this->tpwp->host() . '/PyScript/' . $this->tpwp->settings->auth_script_name . '?' . http_build_query(
                    [
                        'redirect_to'  => isset($_GET['redirect_to']) ? $_GET['redirect_to'] : get_site_url(),
                        'action' => "logout"
                    ]
                );
            wp_redirect($redir);
            die;
        }
    }

    /**
     * Placeholder for automatic login.
     */
    public function footer()
    {
        // echo to print in footer
    }

    /**
     * Renders the link used to login through TouchPoint.
     */
    public function printLoginLink()
    {
        $html = '<p class="touchpoint-wp-auth-form-text">';
        /** @noinspection HtmlUnknownTarget */
        $html .= '<a href="%s">';
        $html .= sprintf(
            __('Sign in with your %s account', 'TouchPoint-WP'),
            htmlentities($this->tpwp->settings->system_name)
        );
        /** @noinspection HtmlUnknownTarget */
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
     * @noinspection SpellCheckingInspection
     */
    public function getLoginUrl()
    {
        $antiforgeryId = TouchPointWP::generateAntiForgeryId(self::SESSION_TIMEOUT);

        $_SESSION[TouchPointWP::SETTINGS_PREFIX . 'auth_sessionToken'] = $antiforgeryId;

        return $this->tpwp->host() . '/PyScript/' . $this->tpwp->settings->auth_script_name . '?' . http_build_query(
                [
                    'redirect_to'  => isset($_GET['redirect_to']) ? $_GET['redirect_to'] : get_site_url(),
                    'sessionToken' => $antiforgeryId,
                    'action' => "login"
                ]
            );
    }

    /**
     * Generates the URL for logging out of TouchPoint. (Does not log out of WordPress.)
     */
    public function getLogoutUrl()
    {
        return $this->tpwp->host() . "/Account/LogOff/";
    }

    /**
     * Determines whether to redirect to the TouchPoint login automatically, and does so if appropriate.
     */
    public function redirectLoginFormMaybe()
    {
        $redirect = apply_filters(
            TouchPointWP::HOOK_PREFIX . 'auto_redirect_login',
            ($this->tpwp->settings->auth_default === 'on')
        );

        if (isset($_GET[TouchPointWP::HOOK_PREFIX . 'no_redirect'])) {
            $redirect = false;
        }

        if ($this->wantsToLogin() && $redirect) {
            wp_redirect($this->getLoginUrl());
            die();
        }
    }

    /**
     * Prevents the admin bar from being displayed for users who can't edit or change anything.
     */
    public function removeAdminBarMaybe()
    {
        $removeBar = apply_filters(
            TouchPointWP::HOOK_PREFIX . 'prevent_admin_bar',
            ($this->tpwp->settings->auth_prevent_admin_bar === 'on') && current_user_can('subscriber') && ! is_admin()
        );

        if ($removeBar) {
            show_admin_bar(false);
        }
    }

    /**
     * Checks to determine if the user wants to login.
     *
     * This is meant to handle a variety of oddities in how WordPress sometimes--but not always--makes intent clear.
     *
     * @return bool Whether or not the user is trying to log in to the site
     */
    private function wantsToLogin()
    {
        $wants_to_login = false;
        // redirect back from TouchPoint after a successful login
        if (isset($_GET['loginToken'])) {
            return false;
        }

        // Default WordPress behavior
        $action = isset($_REQUEST['action']) ? $_REQUEST['action'] : 'login';
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
    public function changeProfileUrl(string $url)
    {
        if ($this->tpwp->settings->auth_change_profile_urls === 'on') {
            $userId   = get_current_user_id();
            $peopleId = (int)(get_user_meta($userId, Person::META_PEOPLEID, true));
            if ($peopleId > 0) { // make sure we have a PeopleId.  Users aren't necessarily TouchPoint users.
                return $this->tpwp->host() . '/Person2/' . $peopleId;
            }
        }
        return $url;
    }

    /**
     * Authenticates the user with TouchPoint
     *
     * @param WP_User|WP_Error $user A WP_User, if the user has already authenticated.
     * @param mixed            $username The username provided during form-based sign in. Not used.
     * @param mixed            $password The password provided during form-based sign in. Not used.
     *
     * @return WP_User|WP_Error The authenticated WP_User, or a WP_Error if there were errors.
     *
     * @noinspection PhpUnusedParameterInspection  We don't use the username or password, but they're in the WP API.
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
            if ( ! TouchPointWP::AntiForgeryTimestampIsValid($_GET['loginToken'], self::LOGIN_TIMEOUT_STANDARD)) {
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
            $lst = get_user_meta($user->ID, TouchPointWP::SETTINGS_PREFIX . 'loginSessionToken', true);

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
            if (getallheaders()['X-API-KEY'] !== $this->tpwp->getApiKey()) {
                self::apiError(
                    'invalid_key',
                    __('ERROR: Access denied.  API Key is not valid.', 'TouchPoint-WP')
                );
            }

            // Get data POSTed by TouchPoint
            $input = file_get_contents('php://input');

            $this->handleTouchPointAuthData($input);
        }

        return $user;  // functionally, "do nothing"
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
        $data = json_decode($data); // No, this duplication is not a mistake.
        $isFromLink = false;

        // Make sure sessionToken is valid
        if ( ! isset($data->sessionToken) ||
             ! TouchPointWP::AntiForgeryTimestampIsValid($data->sessionToken, self::SESSION_TIMEOUT)) {
            // invalid or missing.

            if (isset($data->linkedRequest) &&
                $data->linkedRequest === true) { // TODO add a test of whether links are allowed
                $isFromLink = true;

            } else {
//                var_dump($data);
                self::apiError(
                    'no_session_token',
                    __('You don\'t appear to have a current session on our website.', 'TouchPoint-WP')
                );
            }
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
        $this->updateWpUserWithTouchPointData($user, $data->u);


        // Generate login token and response.
        /** @noinspection SpellCheckingInspection */
        $resp = [
            'status'         => 'success',
            'userLoginToken' => TouchPointWP::generateAntiForgeryId($isFromLink ? self::LOGIN_TIMEOUT_LINK : self::LOGIN_TIMEOUT_STANDARD),
            'wpid'           => $user->ID
        ];

        // TODO periodically send an updated API Key.

        if ( ! update_user_meta($user->ID, TouchPointWP::SETTINGS_PREFIX . 'loginToken', $resp['userLoginToken']) ||
             (!$isFromLink && !update_user_meta($user->ID, TouchPointWP::SETTINGS_PREFIX . 'loginSessionToken', $data->sessionToken))
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
        if (isset($data->u->wpid) && $data->u->wpid > 0) {
            $user = get_user_by('id', $data->u->wpid);
            if ( ! ! $user) // verify that a user was found.  TODO verify that other things match, too.
            {
                return $user;
            }
        }

        // Find user based on PeopleId
        if (isset($data->u->PeopleId)) {
            $q = new WP_User_Query(
                [
                    'meta_key'     => Person::META_PEOPLEID,
                    'meta_value'   => $data->u->PeopleId,
                    'meta_compare' => '='
                ]
            );
            if ($q->get_total() === 1) {
                return $q->get_results()[0];
            } // if the person isn't properly found, continue to provisioning.
        }

        // TODO figure out what to do with TP users without an email address
        // TODO figure out what to do with TP users with an "inactive" primary email address.

        if ($this->tpwp->settings->auth_auto_provision === 'on') {
            // Provision a new user, since we were unsuccessful in finding one.
            $uid = wp_create_user(self::generateUserName($data->u), com_create_guid(), $data->u->EmailAddress);
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
     * @deprecated
     * @see Person::generateUserName
     *
     * @return string  A viable, available username.
     */
    protected static function generateUserName(object $pData)
    {
        // Best.  Matches TouchPoint username.  However, it's possible users won't have usernames.
        if (isset($pData->Usernames[0])) {
            $try = $pData->Usernames[0];
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
                'ID' => $user->ID,

                'user_email' => $pData->EmailAddress,
                'nickname'   => $pData->Name,
                'first_name' => $pData->FirstName,
                'last_name'  => $pData->LastName,

                Person::META_PEOPLEID => $pData->PeopleId
            ]
        );

        // Restores password change email, so password changes though other mechanisms still work.
        remove_filter('send_password_change_email', '__return_false');
//        update_user_meta($user->ID, 'description', $pData->ev->bio);  TODO import bios or other Extra Values.
    }

}