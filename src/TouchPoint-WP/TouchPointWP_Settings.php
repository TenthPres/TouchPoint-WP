<?php

namespace tp\TouchPointWP;

/**
 * Settings class file.
 *
 * Class TouchPointWP_Settings
 * @package tp\TouchPointWP
 */

if ( ! defined('ABSPATH')) {
    exit;
}

/**
 * Settings class.
 *
 * @property-read string host               The domain for the TouchPoint instance
 * @property-read string host_deeplink      The domain for mobile deep linking to the Custom Mobile App
 * @property-read string system_name        What the church calls TouchPoint
 * @property-read string api_user           Username of a user account with API access
 * @property-read string api_pass           Password for a user account with API access
 * @property-read string api_script_name    The name of the script loaded into TouchPoint for API Interfacing
 * @property-read string api_secret_key     The secret key used for the Auth API
 * @property-read string google_maps_api_key Google Maps API Key for embedded maps and such
 *
 * @property-read string auth_script_name   The name of the Python script within TouchPoint
 * @property-read string auth_default       Enabled when TouchPoint should be used as the primary authentication method
 * @property-read string auth_change_profile_urls Enabled to indicate the profiles should be located on TouchPoint
 * @property-read string auth_auto_provision Enabled to indicate that new users should be created automatically.
 * @property-read string auth_prevent_admin_bar Enabled to prevent Admin Bar to appearing on webpages for users who don't have special roles.
 * @property-read string auth_full_logout   Enabled to indicate that logging out of WordPress should also log the user out of TouchPoint.
 *
 * @property-read string sg_name_plural     What small groups should be called, plural (e.g. "Small Groups" or "Life Groups")
 * @property-read string sg_name_singular   What a small group should be called, singular (e.g. "Small Group" or "Life Group")
 * @property-read string sg_slug            Slug for Small Group posts (e.g. "smallgroups" for church.org/smallgroups)
 * @property-read string[] sg_divisions     Involvements that are within these divisions should be imported from TouchPoint as Small Groups.
 * @property-read string[] sg_leader_types  Member Types who should be listed as leaders for the small group
 * @property-read string[] sg_host_types    Member Types whose home addresses should be used for the small group location
 *
 * @property-read int sg_cron_last_run      Timestamp of the last time the Small Groups syncing task ran.  (No setting UI.)  TODO refactor.
 *
 * @property-read string cs_name_plural     What courses should be called, plural (e.g. "Classes")
 * @property-read string cs_name_singular   What a course should be called, singular (e.g. "Class")
 * @property-read string cs_slug            Slug for course posts (e.g. "courses" for church.org/courses)
 * @property-read string[] cs_divisions     Involvements that are within these divisions should be imported from TouchPoint as courses.
 * @property-read string[] cs_leader_types  Member Types who should be listed as leaders for the course
 *
 * @property-read string ec_use_standardizing_style Whether to insert the standardizing stylesheet into mobile app requests.
 *
 * @property-read string rc_name_plural     What resident codes should be called, plural (e.g. "Resident Codes" or "Zones")
 * @property-read string rc_name_singular   What a resident code should be called, singular (e.g. "Resident Code" or "Zone")
 * @property-read string rc_slug            Slug for resident code taxonomy (e.g. "zones" for church.org/zones)
 *
 * @property-read string dv_name_plural     What divisions should be called, plural (e.g. "Divisions" or "Ministries")
 * @property-read string dv_name_singular   What a division should be called, singular (e.g. "Division" or "Ministry")
 * @property-read string dv_slug            Slug for division taxonomy (e.g. "ministries" for church.org/ministries)
 * @property-read array  dv_divisions       Which divisions should be imported
 */
class TouchPointWP_Settings
{

    /**
     * The singleton of TouchPointWP_Settings.
     */
    private static ?TouchPointWP_Settings $_instance = null;

    /**
     * The main plugin object.
     */
    public ?TouchPointWP $parent = null;

    /**
     * Available settings for plugin.
     */
    protected array $settings = [];

    protected bool $settingsIncludeDetail = false;

    public const UNDEFINED_PLACEHOLDER = INF;

    /**
     * Constructor function.
     *
     * @param TouchPointWP $parent Parent object.
     */
    public function __construct(TouchPointWP $parent)
    {
        $this->parent = $parent;

        // Initialise settings.
        add_action('init', [$this, 'initSettings'], 11);

        // Register plugin settings.
        add_action('admin_init', [$this, 'registerSettings']);

        // Add settings page to menu.
        add_action('admin_menu', [$this, 'add_menu_item']);

        // Add settings link to plugins page.
        add_filter(
            'plugin_action_links_' . plugin_basename($this->parent->file),
            [
                $this,
                'add_settings_link',
            ]
        );

        // Configure placement of plugin settings page. See readme for implementation.
        add_filter(TouchPointWP::SETTINGS_PREFIX . 'menu_settings', [$this, 'configure_settings']);
    }

    /**
     * Main TouchPointWP_Settings Instance
     *
     * Ensures only one instance of TouchPointWP_Settings is loaded or can be loaded.
     *
     * @param TouchPointWP $parent Object instance.
     *
     * @return TouchPointWP_Settings instance
     * @since 1.0.0
     * @static
     * @see TouchPointWP()
     */
    public static function instance(TouchPointWP $parent): TouchPointWP_Settings
    {
        if (is_null(self::$_instance)) {
            self::$_instance = new self($parent);
        }

        return self::$_instance;
    }

    /**
     * Initialise settings
     *
     * @return void
     */
    public function initSettings(): void
    {
        $this->settings = $this->settingsFields(false, false);
    }

    /**
     * Indicates whether there are adequate settings in place for API calls.
     *
     * @return bool
     */
    public function hasValidApiSettings(): bool
    {
        $host = $this->getWithoutDefault('host');

        return !($this->getWithoutDefault('api_script_name') === TouchPointWP_Settings::UNDEFINED_PLACEHOLDER ||
               $host === TouchPointWP_Settings::UNDEFINED_PLACEHOLDER || $host === '' ||
               $this->getWithoutDefault('api_user') === TouchPointWP_Settings::UNDEFINED_PLACEHOLDER ||
               $this->getWithoutDefault('api_pass') === TouchPointWP_Settings::UNDEFINED_PLACEHOLDER);
    }

    /**
     * Build settings fields
     *
     * @param bool $includeAll Set to true to return all settings parameters, including those for disabled features.
     * @param bool $includeDetail Set to true to get options from TouchPoint, potentially including the API call.
     *
     * @return array Fields to be displayed on settings page
     */
    private function settingsFields(bool $includeAll = false, ?bool $includeDetail = null): array
    {
        if ($includeDetail !== false) {
            $includeDetail = $this->hasValidApiSettings();
        }

        if (count($this->settings) > 0 && ($includeDetail === $this->settingsIncludeDetail || !$includeDetail)) {
            // Settings are already loaded, and they have adequate detail for the task at hand.
            return $this->settings;
        }

        $this->settings['basic'] = [
            'title'       => __('Basic Settings', 'TouchPoint-WP'),
            'description' => __('Connect to TouchPoint and choose which features you wish to use.', 'TouchPoint-WP'),
            'fields'      => [
                [
                    'id'          => 'enable_authentication',
                    'label'       => __('Enable Authentication', 'TouchPoint-WP'),
                    'description' => __(
                        'Allow TouchPoint users to sign into this website with TouchPoint.',
                        TouchPointWP::TEXT_DOMAIN
                    ),
                    'type'        => 'checkbox',
                    'default'     => '',
                ],
//                [
//                    'id'          => 'enable_rsvp',
//                    'label'       => __('Enable RSVP Tool', 'TouchPoint-WP'),
//                    'description' => __('Add a crazy-simple RSVP button to WordPress event pages.', 'TouchPoint-WP'),
//                    'type'        => 'checkbox',
//                    'default'     => '',
//                ],
                [
                    'id'          => 'enable_small_groups',
                    'label'       => __('Enable Small Groups', 'TouchPoint-WP'),
                    'description' => __(
                        'Load Small Groups from TouchPoint for a web-based Small Group finder.',
                        TouchPointWP::TEXT_DOMAIN
                    ),
                    'type'        => 'checkbox',
                    'default'     => '',
                ],
                [
                    'id'          => 'enable_courses',
                    'label'       => __('Enable Courses', 'TouchPoint-WP'),
                    'description' => __(
                        'Load Courses (Sunday School, etc) from TouchPoint for a web-based listing of Courses.',
                        TouchPointWP::TEXT_DOMAIN
                    ),
                    'type'        => 'checkbox',
                    'default'     => '',
                ],
                [
                    'id'          => 'system_name',
                    'label'       => __('Display Name', TouchPointWP::TEXT_DOMAIN),
                    'description' => __(
                        'What your church calls your TouchPoint database.',
                        TouchPointWP::TEXT_DOMAIN
                    ),
                    'type'        => 'text',
                    'default'     => 'TouchPoint',
                    'placeholder' => 'TouchPoint'
                ],
                [
                    'id'          => 'host',
                    'label'       => __('TouchPoint Host Name', 'TouchPoint-WP'),
                    'description' => __(
                        'The domain for your TouchPoint database, without the https or any slashes.',
                        TouchPointWP::TEXT_DOMAIN
                    ),
                    'type'        => 'text',
                    'default'     => '',
                    'placeholder' => 'mychurch.tpsdb.com',
                    'callback'    => [$this, 'validation_lowercase']
                ],
                [
                    'id'          => 'host_deeplink',
                    'label'       => __('Custom Mobile App Deeplink Host Name', 'TouchPoint-WP'),
                    'description' => __(
                        "The domain for your mobile app deeplinks, without the https or any slashes.  If you aren't 
                        using the custom mobile app, leave this blank.",
                        TouchPointWP::TEXT_DOMAIN
                    ),
                    'type'        => 'text',
                    'default'     => '',
                    'placeholder' => 'mychurch.app.tpsdb.co',
                    'callback'    => [$this, 'validation_lowercase']
                ],
                [
                    'id'          => 'api_user',
                    'label'       => __('TouchPoint API Username', 'TouchPoint-WP'),
                    'description' => __(
                        'The username of a user account in TouchPoint with API permissions.  Required for all tools except Authentication.',
                        TouchPointWP::TEXT_DOMAIN
                    ),
                    'type'        => 'text',
                    'default'     => '',
                    'placeholder' => '',
                ],
                [
                    'id'          => 'api_pass',
                    'label'       => __('TouchPoint API User Password', 'TouchPoint-WP'),
                    'description' => __(
                        'The password of a user account in TouchPoint with API permissions.',
                        TouchPointWP::TEXT_DOMAIN
                    ),
                    'type'        => 'text_secret',
                    'default'     => '',
                    'placeholder' => $this->passwordPlaceholder('api_pass'),
                    'callback'    => fn($new) => $this->validation_secret($new, 'api_pass')
                ],
                [
                    'id'          => 'api_script_name',
                    'label'       => __('TouchPoint API Script Name', 'TouchPoint-WP'),
                    'description' => __(
                        'The name of the Python script loaded into TouchPoint.  Don\'t change this unless you know what you\'re doing.',
                        TouchPointWP::TEXT_DOMAIN
                    ),
                    'type'        => 'text',
                    'default'     => 'WebApi',
                    'placeholder' => ''
                ],
                [
                    'id'          => 'google_maps_api_key',
                    'label'       => __('Google Maps API Key', 'TouchPoint-WP'),
                    'description' => __(
                        'Required for embedding maps.',
                        TouchPointWP::TEXT_DOMAIN
                    ),
                    'type'        => 'text',
                    'default'     => '',
                    'placeholder' => '',
                ],
            ],
        ];

        // Add Script generation section if necessary settings are established.
        if ($this->getWithoutDefault('system_name') !== self::UNDEFINED_PLACEHOLDER
            && $this->hasValidApiSettings()) {
            /** @noinspection HtmlUnknownTarget */
            $this->settings['basic']['fields'][] = [
                'id'          => 'generate-scripts',
                'label'       => __('Generate Scripts', 'TouchPoint-WP'),
                'type'    => 'instructions',
                'description' => strtr(
                    '<p>' . __('Once your settings on this page are set and saved, use this tool to generate
the scripts needed for TouchPoint in a convenient installation package.  ', TouchPointWP::TEXT_DOMAIN) .
'<a href="{uploadUrl}">' . __('Upload the package to {tpName} here', TouchPointWP::TEXT_DOMAIN) . '</a>.</p>
<p><a href="{apiUrl}" class="button-secondary" target="tp_zipIfr">' . __('Generate Scripts', TouchPointWP::TEXT_DOMAIN) . '</a></p>
<iframe name="tp_zipIfr" style="width:0; height:0; opacity:0;"></iframe>',
                    [
                        '{apiUrl}'    => "/" . TouchPointWP::API_ENDPOINT . "/" . TouchPointWP::API_ENDPOINT_GENERATE_SCRIPTS,
                        '{tpName}'    => $this->get('system_name'),
                        '{uploadUrl}' => "https://" . $this->get('host') . "/InstallPyScriptProject"
                    ]
                ),
            ];
        }


        if (get_option(TouchPointWP::SETTINGS_PREFIX . 'enable_authentication') === "on" || $includeAll) {
            $this->settings['authentication'] = [
                'title'       => __('Authentication', TouchPointWP::TEXT_DOMAIN),
                'description' => __('Allow users to log into WordPress using TouchPoint.', TouchPointWP::TEXT_DOMAIN),
                'fields'      => [
                    [
                        'id'          => 'auth_script_name',
                        'label'       => __('Authentication Script name', TouchPointWP::TEXT_DOMAIN),
                        'description' => __(
                            'The filename of the authentication script installed in your TouchPoint database.',
                            TouchPointWP::TEXT_DOMAIN
                        ),
                        'type'        => 'text',
                        'default'     => 'WebAuth',
                        'placeholder' => 'WebAuth'
                    ],
                    [
                        'id'          => 'auth_default',
                        'label'       => __('Make TouchPoint the default authentication method.', TouchPointWP::TEXT_DOMAIN),
                        'description' => __(
                            'By checking this box, the TouchPoint login page will become the default.  To prevent the redirect and reach the standard TouchPoint login page, add \'' . TouchPointWP::HOOK_PREFIX . 'no_redirect\' as a URL parameter.',
                            TouchPointWP::TEXT_DOMAIN
                        ),
                        'type'        => 'checkbox',
                        'default'     => '',
                    ],
                    [
                        'id'          => 'auth_auto_provision',
                        'label'       => __('Enable Auto-Provisioning', TouchPointWP::TEXT_DOMAIN),
                        'description' => __(
                            'Automatically create WordPress users, if needed, to match authenticated TouchPoint users.',
                            TouchPointWP::TEXT_DOMAIN
                        ),
                        'type'        => 'checkbox',
                        'default'     => 'on',
                    ],
                    [
                        'id'          => 'auth_background', // TODO this.
                        'label'       => __('Enable Auto-Sign in', TouchPointWP::TEXT_DOMAIN),
                        'description' => __(
                            'Automatically sign in WordPress users when already signed into TouchPoint.',
                            TouchPointWP::TEXT_DOMAIN
                        ),
                        'type'        => 'checkbox',
                        'default'     => 'on',
                    ],
                    [
                        'id'          => 'auth_change_profile_urls',
                        'label'       => __('Change \'Edit Profile\' links', TouchPointWP::TEXT_DOMAIN),
                        'description' => __(
                            '"Edit Profile" links will take the user to their TouchPoint profile, instead of their WordPress profile.',
                            TouchPointWP::TEXT_DOMAIN
                        ),
                        'type'        => 'checkbox',
                        'default'     => 'on',
                    ],
                    [
                        'id'          => 'auth_full_logout', // TODO this.
                        'label'       => __('Enable full logout', TouchPointWP::TEXT_DOMAIN),
                        'description' => __('Logout of TouchPoint when logging out of WordPress.', TouchPointWP::TEXT_DOMAIN),
                        'type'        => 'checkbox',
                        'default'     => 'on',
                    ],
                    [
                        'id'          => 'auth_prevent_admin_bar',
                        'label'       => __('Prevent Subscriber Admin Bar', TouchPointWP::TEXT_DOMAIN),
                        'description' => __(
                            'By enabling this option, users who can\'t edit anything won\'t see the Admin bar.',
                            TouchPointWP::TEXT_DOMAIN
                        ),
                        'type'        => 'checkbox',
                        'default'     => '',
                    ],
                ],
            ];
        }

        if (get_option(TouchPointWP::SETTINGS_PREFIX . 'enable_small_groups') === "on" || $includeAll) {
            $this->settings['small_groups'] = [
                'title'       => __('Small Groups', TouchPointWP::TEXT_DOMAIN),
                'description' => __('Import Small Groups from TouchPoint to your website.', TouchPointWP::TEXT_DOMAIN),
                'fields'      => [
                    [
                        'id'          => 'sg_divisions',
                        'label'       => __('Divisions to Import', TouchPointWP::TEXT_DOMAIN),
                        'description' => __(
                            'Involvements from these divisions will be imported as small groups.',
                            TouchPointWP::TEXT_DOMAIN
                        ),
                        'type'        => 'checkbox_multi',
                        'options'     => $includeDetail ? $this->parent->getDivisionsAsKVArray() : [],
                        'default'     => [],
                    ],
                    [
                        'id'          => 'sg_name_plural',
                        'label'       => __('Small Groups Name (Plural)', TouchPointWP::TEXT_DOMAIN),
                        'description' => __(
                            'What you call small groups at your church',
                            TouchPointWP::TEXT_DOMAIN
                        ),
                        'type'        => 'text',
                        'default'     => 'Small Groups',
                        'placeholder' => 'Small Groups'
                    ],
                    [
                        'id'          => 'sg_name_singular',
                        'label'       => __('Small Groups Name (Singular)', TouchPointWP::TEXT_DOMAIN),
                        'description' => __(
                            'What you call a small group at your church',
                            TouchPointWP::TEXT_DOMAIN
                        ),
                        'type'        => 'text',
                        'default'     => 'Small Group',
                        'placeholder' => 'Small Group'
                    ],
                    [
                        'id'          => 'sg_slug',
                        'label'       => __('Small Groups Slug', TouchPointWP::TEXT_DOMAIN),
                        'description' => __(
                            'The root path for Small Group pages',
                            TouchPointWP::TEXT_DOMAIN
                        ),
                        'type'        => 'text',
                        'default'     => 'smallgroups',
                        'placeholder' => 'smallgroups',
                        'callback'    => fn($new) => $this->validation_slug($new, 'sg_slug')
                    ],
                    [
                        'id'          => 'sg_leader_types',
                        'label'       => __('Leader Member Types', TouchPointWP::TEXT_DOMAIN),
                        'description' => __(
                            'Members of these types will be listed as leaders and used as contact persons.',
                            TouchPointWP::TEXT_DOMAIN
                        ),
                        'type'        => 'checkbox_multi',
                        'options'     => $includeDetail ? $this->parent->getMemberTypesForDivisionsAsKVArray($this->get('sg_divisions')) : [],
                        'default'     => [],
                    ],
                    [
                        'id'          => 'sg_host_types',
                        'label'       => __('Host Member Types', TouchPointWP::TEXT_DOMAIN),
                        'description' => __(
                            'Members of these types will have their home address used as the location.',
                            TouchPointWP::TEXT_DOMAIN
                        ),
                        'type'        => 'checkbox_multi',
                        'options'     => $includeDetail ? $this->parent->getMemberTypesForDivisionsAsKVArray($this->get('sg_divisions')) : [],
                        'default'     => [],
                    ],
                    [
                        'id'          => 'sg_filter_defaults',
                        'label'       => __('Default Group Filters', TouchPointWP::TEXT_DOMAIN),
                        'description' => __(
                            "Filtering criteria to make available to users by default.  Can be overridden by shortcode 
                            parameters.  Filters generally won't appear unless groups match multiple options.",
                            TouchPointWP::TEXT_DOMAIN
                        ),
                        'type'        => 'checkbox_multi',
                        'options' => [
                            'div'         => $this->get('dv_name_singular'),
                            'genderId'    => __('Gender', TouchPointWP::TEXT_DOMAIN),
                            'rescode'     => $this->get('rc_name_singular'),
                            'weekday'     => __('Weekday', TouchPointWP::TEXT_DOMAIN),
                            'inv_marital' => __('Marital Status', TouchPointWP::TEXT_DOMAIN),
                            'agegroup'    => __('Age Group', TouchPointWP::TEXT_DOMAIN),
                        ],
                        'default'     => ['genderId', 'rescode', 'weekday', 'agegroup', 'div']
                    ],
                ],
            ];
        }


        if (get_option(TouchPointWP::SETTINGS_PREFIX . 'enable_courses') === "on" || $includeAll) {
            $this->settings['courses'] = [
                'title'       => __('Courses', TouchPointWP::TEXT_DOMAIN),
                'description' => __('Import Courses (Sunday School, etc.) from TouchPoint to your website.', TouchPointWP::TEXT_DOMAIN),
                'fields'      => [
                    [
                        'id'          => 'cs_divisions',
                        'label'       => __('Divisions to Import', TouchPointWP::TEXT_DOMAIN),
                        'description' => __(
                            'Involvements from these divisions will be imported as courses.',
                            TouchPointWP::TEXT_DOMAIN
                        ),
                        'type'        => 'checkbox_multi',
                        'options'     => $includeDetail ? $this->parent->getDivisionsAsKVArray() : [],
                        'default'     => [],
                    ],
                    [
                        'id'          => 'cs_name_plural',
                        'label'       => __('Courses Name (Plural)', TouchPointWP::TEXT_DOMAIN),
                        'description' => __(
                            'What you call courses at your church',
                            TouchPointWP::TEXT_DOMAIN
                        ),
                        'type'        => 'text',
                        'default'     => 'Classes',
                        'placeholder' => 'Classes'
                    ],
                    [
                        'id'          => 'cs_name_singular',
                        'label'       => __('Courses Name (Singular)', TouchPointWP::TEXT_DOMAIN),
                        'description' => __(
                            'What you call a class at your church',
                            TouchPointWP::TEXT_DOMAIN
                        ),
                        'type'        => 'text',
                        'default'     => 'Class',
                        'placeholder' => 'Class'
                    ],
                    [
                        'id'          => 'cs_slug',
                        'label'       => __('Courses Slug', TouchPointWP::TEXT_DOMAIN),
                        'description' => __(
                            'The root path for Class pages',
                            TouchPointWP::TEXT_DOMAIN
                        ),
                        'type'        => 'text',
                        'default'     => 'classes',
                        'placeholder' => 'classes',
                        'callback'    => fn($new) => $this->validation_slug($new, 'cl_slug')
                    ],
                    [
                        'id'          => 'cs_leader_types',
                        'label'       => __('Leader Member Types', TouchPointWP::TEXT_DOMAIN),
                        'description' => __(
                            'Members of these types will be listed as leaders and used as contact persons.',
                            TouchPointWP::TEXT_DOMAIN
                        ),
                        'type'        => 'checkbox_multi',
                        'options'     => $includeDetail ? $this->parent->getMemberTypesForDivisionsAsKVArray($this->get('cs_divisions')) : [],
                        'default'     => [],
                    ],
                    [
                        'id'          => 'cs_filter_defaults',
                        'label'       => __('Default Class Filters', TouchPointWP::TEXT_DOMAIN),
                        'description' => __(
                            "Filtering criteria to make available to users by default.  Can be overridden by shortcode 
                            parameters.  Filters generally won't appear unless courses match multiple options.",
                            TouchPointWP::TEXT_DOMAIN
                        ),
                        'type'        => 'checkbox_multi',
                        'options' => [
                            'div'         => $this->get('dv_name_singular'),
                            'genderId'    => __('Gender', TouchPointWP::TEXT_DOMAIN),
                            'weekday'     => __('Weekday', TouchPointWP::TEXT_DOMAIN),
                            'inv_marital' => __('Marital Status', TouchPointWP::TEXT_DOMAIN),
                            'agegroup'    => __('Age Group', TouchPointWP::TEXT_DOMAIN),
                        ],
                        'default'     => ['genderId', 'weekday', 'agegroup', 'div']
                    ],
                ],
            ];
        }


        if (class_exists("tp\TouchPointWP\EventsCalendar") || $includeAll) {
            $this->settings['events_calendar'] = [
                'title'       => __('Events Calendar', TouchPointWP::TEXT_DOMAIN),
                'description' => __('Integrate with The Events Calendar from ModernTribe.', TouchPointWP::TEXT_DOMAIN),
                'fields'      => [
                    [
                        'id'          => 'copy-app-endpoint-address',
                        'label'       => __('Events for Custom Mobile App', 'TouchPoint-WP'),
                        'type'    => 'instructions',
                        'description' => strtr(
                            '<p>' . __('To use your Events Calendar events in the Custom mobile app, set the Provider to <code>Wordpress Plugin - Modern Tribe</code> and use this url:', TouchPointWP::TEXT_DOMAIN) . '</p>' .
                            '<input type="url" value="{apiUrl}" readonly style="width: 100%;" />',
                            [
                                '{apiUrl}'    => get_site_url() . "/" .
                                                 TouchPointWP::API_ENDPOINT . "/" .
                                                 TouchPointWP::API_ENDPOINT_APP_EVENTS . "?v=" .
                                                 TouchPointWP::VERSION
                            ]
                        ),
                    ],
                    [
                        'id'          => 'ec_use_standardizing_style',
                        'label'       => __( 'Use Standardizing Stylesheet', TouchPointWP::TEXT_DOMAIN ),
                        'description' => __( 'Inserts some basic CSS into the events feed to clean up display', TouchPointWP::TEXT_DOMAIN ),
                        'type'        => 'checkbox',
                        'default'     => 'on',
                    ],
                ],
            ];
        }

        $this->settings['divisions'] = [
            'title'       => __('Divisions', TouchPointWP::TEXT_DOMAIN),
            'description' => __('Import Divisions from TouchPoint to your website as a taxonomy.  These are used to classify Small Groups and users.', TouchPointWP::TEXT_DOMAIN),
            'fields'      => [
                [
                    'id'          => 'dv_name_plural',
                    'label'       => __('Division Name (Plural)', TouchPointWP::TEXT_DOMAIN),
                    'description' => __(
                        'What you call Divisions at your church',
                        TouchPointWP::TEXT_DOMAIN
                    ),
                    'type'        => 'text',
                    'default'     => 'Divisions',
                    'placeholder' => 'Divisions'
                ],
                [
                    'id'          => 'dv_name_singular',
                    'label'       => __('Division Name (Singular)', TouchPointWP::TEXT_DOMAIN),
                    'description' => __(
                        'What you call a Division at your church',
                        TouchPointWP::TEXT_DOMAIN
                    ),
                    'type'        => 'text',
                    'default'     => 'Division',
                    'placeholder' => 'Division'
                ],
                [
                    'id'          => 'dv_slug',
                    'label'       => __('Division Slug', TouchPointWP::TEXT_DOMAIN),
                    'description' => __(
                        'The root path for the Division Taxonomy',
                        TouchPointWP::TEXT_DOMAIN
                    ),
                    'type'        => 'text',
                    'default'     => 'div',
                    'placeholder' => 'div',
                    'callback'    => fn($new) => $this->validation_slug($new, 'dv_slug')
                ],
                [
                    'id'          => 'dv_divisions',
                    'label'       => __('Divisions to Import', TouchPointWP::TEXT_DOMAIN),
                    'description' => __(
                        'These divisions will be imported for the taxonomy.',
                        TouchPointWP::TEXT_DOMAIN
                    ),
                    'type'        => 'checkbox_multi',
                    'options'     => $includeDetail ? $this->parent->getDivisionsAsKVArray() : [],
                    'default'     => [],
                    'callback'    => function($new) {sort($new); return $new;}
                ],
            ],
        ];

        $this->settings['resident_codes'] = [
            'title'       => __('Resident Codes', TouchPointWP::TEXT_DOMAIN),
            'description' => __('Import Resident Codes from TouchPoint to your website as a taxonomy.  These are used to classify Small Groups and users.', TouchPointWP::TEXT_DOMAIN),
            'fields'      => [
                [
                    'id'          => 'rc_name_plural',
                    'label'       => __('Resident Code Name (Plural)', TouchPointWP::TEXT_DOMAIN),
                    'description' => __(
                        'What you call Resident Codes at your church',
                        TouchPointWP::TEXT_DOMAIN
                    ),
                    'type'        => 'text',
                    'default'     => 'Resident Codes',
                    'placeholder' => 'Resident Codes'
                ],
                [
                    'id'          => 'rc_name_singular',
                    'label'       => __('Resident Code Name (Singular)', TouchPointWP::TEXT_DOMAIN),
                    'description' => __(
                        'What you call a resident code at your church',
                        TouchPointWP::TEXT_DOMAIN
                    ),
                    'type'        => 'text',
                    'default'     => 'Resident Code',
                    'placeholder' => 'Resident Code'
                ],
                [
                    'id'          => 'rc_slug',
                    'label'       => __('Resident Code Slug', TouchPointWP::TEXT_DOMAIN),
                    'description' => __(
                        'The root path for the Resident Code Taxonomy',
                        TouchPointWP::TEXT_DOMAIN
                    ),
                    'type'        => 'text',
                    'default'     => 'rescodes',
                    'placeholder' => 'rescodes',
                    'callback'    => fn($new) => $this->validation_slug($new, 'rc_slug')
                ]
            ],
        ];


        /*	$settings['general'] = [
                'title'       => __( 'Standard', TouchPointWP::TEXT_DOMAIN ),
                'description' => __( 'These are fairly standard form input fields.', TouchPointWP::TEXT_DOMAIN ),
                'fields'      => [
                    [
                        'id'          => 'text_field',
                        'label'       => __( 'Some Text', TouchPointWP::TEXT_DOMAIN ),
                        'description' => __( 'This is a standard text field.', TouchPointWP::TEXT_DOMAIN ),
                        'type'        => 'text',
                        'default'     => '',
                        'placeholder' => __( 'Placeholder text', TouchPointWP::TEXT_DOMAIN ),
                    ],
                    [
                        'id'          => 'password_field',
                        'label'       => __( 'A Password', TouchPointWP::TEXT_DOMAIN ),
                        'description' => __( 'This is a standard password field.', TouchPointWP::TEXT_DOMAIN ),
                        'type'        => 'password',
                        'default'     => '',
                        'placeholder' => __( 'Placeholder text', TouchPointWP::TEXT_DOMAIN ),
                    ],
                    [
                        'id'          => 'secret_text_field',
                        'label'       => __( 'Some Secret Text', TouchPointWP::TEXT_DOMAIN ),
                        'description' => __( 'This is a secret text field - any data saved here will not be displayed after the page has reloaded, but it will be saved.', TouchPointWP::TEXT_DOMAIN ),
                        'type'        => 'text_secret',
                        'default'     => '',
                        'placeholder' => __( 'Placeholder text', TouchPointWP::TEXT_DOMAIN ),
                    ],
                    [
                        'id'          => 'text_block',
                        'label'       => __( 'A Text Block', TouchPointWP::TEXT_DOMAIN ),
                        'description' => __( 'This is a standard text area.', TouchPointWP::TEXT_DOMAIN ),
                        'type'        => 'textarea',
                        'default'     => '',
                        'placeholder' => __( 'Placeholder text for this textarea', TouchPointWP::TEXT_DOMAIN ),
                    ],
                    [
                        'id'          => 'single_checkbox',
                        'label'       => __( 'An Option', TouchPointWP::TEXT_DOMAIN ),
                        'description' => __( 'A standard checkbox - if you save this option as checked then it will store the option as \'on\', otherwise it will be an empty string.', TouchPointWP::TEXT_DOMAIN ),
                        'type'        => 'checkbox',
                        'default'     => '',
                    ],
                    [
                        'id'          => 'select_box',
                        'label'       => __( 'A Select Box', TouchPointWP::TEXT_DOMAIN ),
                        'description' => __( 'A standard select box.', TouchPointWP::TEXT_DOMAIN ),
                        'type'        => 'select',
                        'options'     => [
                            'drupal'    => 'Drupal',
                            'joomla'    => 'Joomla',
                            'wordpress' => 'WordPress',
                        ],
                        'default'     => 'wordpress',
                    ],
                    [
                        'id'          => 'radio_buttons',
                        'label'       => __( 'Some Options', TouchPointWP::TEXT_DOMAIN ),
                        'description' => __( 'A standard set of radio buttons.', TouchPointWP::TEXT_DOMAIN ),
                        'type'        => 'radio',
                        'options'     => [
                            'superman' => 'Superman',
                            'batman'   => 'Batman',
                            'ironman'  => 'Iron Man',
                        ],
                        'default'     => 'batman',
                    ],
                    [
                        'id'          => 'multiple_checkboxes',
                        'label'       => __( 'Some Items', TouchPointWP::TEXT_DOMAIN ),
                        'description' => __( 'You can select multiple items and they will be stored as an array.', TouchPointWP::TEXT_DOMAIN ),
                        'type'        => 'checkbox_multi',
                        'options'     => [
                            'square'    => 'Square',
                            'circle'    => 'Circle',
                            'rectangle' => 'Rectangle',
                            'triangle'  => 'Triangle',
                        ],
                        'default'     => [ 'circle', 'triangle' ],
                    ],
                ],
            ];

            $settings['extra'] = array(
                'title'       => __( 'Extra', TouchPointWP::TEXT_DOMAIN ),
                'description' => __( "These are some extra input fields that maybe aren't as common as the others.", TouchPointWP::TEXT_DOMAIN ),
                'fields'      => array(
                    array(
                        'id'          => 'number_field',
                        'label'       => __( 'A Number', TouchPointWP::TEXT_DOMAIN ),
                        'description' => __( 'This is a standard number field - if this field contains anything other than numbers then the form will not be submitted.', TouchPointWP::TEXT_DOMAIN ),
                        'type'        => 'number',
                        'default'     => '',
                        'placeholder' => __( '42', TouchPointWP::TEXT_DOMAIN ),
                    ),
                    array(
                        'id'          => 'colour_picker',
                        'label'       => __( 'Pick a colour', TouchPointWP::TEXT_DOMAIN ),
                        'description' => __( 'This uses WordPress\' built-in colour picker - the option is stored as the colour\'s hex code.', TouchPointWP::TEXT_DOMAIN ),
                        'type'        => 'color',
                        'default'     => '#21759B',
                    ),
                    array(
                        'id'          => 'an_image',
                        'label'       => __( 'An Image', TouchPointWP::TEXT_DOMAIN ),
                        'description' => __( 'This will upload an image to your media library and store the attachment ID in the option field. Once you have uploaded an image the thumbnail will display above these buttons.', TouchPointWP::TEXT_DOMAIN ),
                        'type'        => 'image',
                        'default'     => '',
                        'placeholder' => '',
                    ),
                    array(
                        'id'          => 'multi_select_box',
                        'label'       => __( 'A Multi-Select Box', TouchPointWP::TEXT_DOMAIN ),
                        'description' => __( 'A standard multi-select box - the saved data is stored as an array.', TouchPointWP::TEXT_DOMAIN ),
                        'type'        => 'select_multi',
                        'options'     => array(
                            'linux'   => 'Linux',
                            'mac'     => 'Mac',
                            'windows' => 'Windows',
                        ),
                        'default'     => array( 'linux' ),
                    ),
                ),
            ); */

        $this->settings = apply_filters($this->parent::TOKEN . '_Settings_fields', $this->settings);

        return $this->settings;
    }

    /**
     * Returns a placeholder for a password setting field that doesn't expose the password itself.
     *
     * @param string $settingName
     *
     * @return string
     */
    private function passwordPlaceholder(string $settingName): string
    {
        $pass = $this->getWithoutDefault($settingName);
        if ($pass === '' || $pass === self::UNDEFINED_PLACEHOLDER) {
            return '';
        }
        return __('password saved', TouchPointWP::TEXT_DOMAIN);
    }

    /**
     * Add settings page to admin menu
     *
     * @return void
     */
    public function add_menu_item()
    {
        $args = $this->menu_settings();

        // Do nothing if wrong location key is set.
        if (is_array($args) && isset($args['location']) && function_exists('add_' . $args['location'] . '_page')) {
            switch ($args['location']) {
                case 'options':
                case 'submenu':
                    $page = add_submenu_page(
                        $args['parent_slug'],
                        $args['page_title'],
                        $args['menu_title'],
                        $args['capability'],
                        $args['menu_slug'],
                        $args['function']
                    );
                    break;
                case 'menu':
                    $page = add_menu_page(
                        $args['page_title'],
                        $args['menu_title'],
                        $args['capability'],
                        $args['menu_slug'],
                        $args['function'],
                        $args['icon_url'],
                        $args['position']
                    );
                    break;
                default:
                    return;
            }
            add_action('admin_print_styles-' . $page, array($this, 'settings_assets'));
        }
    }

    /**
     * Prepare default settings page arguments
     *
     * @return mixed|void
     */
    private function menu_settings()
    {
        return apply_filters(
            TouchPointWP::SETTINGS_PREFIX . 'menu_settings',
            [
                'location'    => 'options', // Possible settings: options, menu, submenu.
                'parent_slug' => 'options-general.php',
                'page_title'  => __('TouchPoint-WP', TouchPointWP::TEXT_DOMAIN),
                'menu_title'  => __('TouchPoint-WP', TouchPointWP::TEXT_DOMAIN),
                'capability'  => 'manage_options',
                'menu_slug'   => $this->parent::TOKEN . '_Settings',
                'function'    => [$this, 'settings_page'],
                'icon_url'    => '',
                'position'    => null,
            ]
        );
    }

    /**
     * Container for settings page arguments
     *
     * @param array $settings Settings array.
     *
     * @return array
     */
    public function configure_settings($settings = []): array
    {
        return $settings;
    }

    /**
     * Load settings JS & CSS
     *
     * @return void
     */
    public function settings_assets()
    {
        // We're including the WP media scripts here because they're needed for the image upload field.
        // If you're not including an image upload then you can leave this function call out.
//        wp_enqueue_media(); // todo remove?

        // TODO remove.  Most of this is not relevant.
//        wp_register_script(
//            $this->parent::TOKEN . '-settings-js',
//            $this->parent->assets_url . 'js/settings' . $this->parent->script_suffix . '.js',
//            ['jquery'],
//            '1.0.0',
//            true
//        );
//        wp_enqueue_script($this->parent::TOKEN . '-settings-js');
    }

    /**
     * Add settings link to plugin list table
     *
     * @param array $links Existing links.
     *
     * @return array        Modified links.
     */
    public function add_settings_link(array $links): array
    {
        $settings_link = '<a href="options-general.php?page=' . $this->parent::TOKEN . '_Settings">' . __(
                'Settings',
                TouchPointWP::TEXT_DOMAIN
            ) . '</a>';
        array_push($links, $settings_link);

        return $links;
    }

    /**
     * @param string $what The field to get a value for
     *
     * @return string|false  The value, if set.  False if not set.
     */
    public function __get(string $what)
    {
        return $this->get($what);
    }

    /**
     * @param string $what
     *
     * @return false|string|array
     */
    public function get(string $what)
    {
        $v = $this->getWithoutDefault($what);

        if ($v === self::UNDEFINED_PLACEHOLDER) {
            $v = $this->getDefaultValueForSetting($what);
        }
        if ($v === self::UNDEFINED_PLACEHOLDER) {
            $v = false;
        }

        return $v;
    }

    /**
     * @param string $what The field to get a value for
     * @param mixed  $default Default value to use.  Defaults to UNDEFINED_PLACEHOLDER
     *
     * @return mixed  The value, if set.  False if not set.
     */
    protected function getWithoutDefault(string $what, $default = self::UNDEFINED_PLACEHOLDER)
    {
        $opt = get_option(TouchPointWP::SETTINGS_PREFIX . $what, $default);

        if ($opt === '')
            return self::UNDEFINED_PLACEHOLDER;

        return $opt;
    }

    /**
     * @param string $what
     * @param mixed  $value
     * @param bool   $autoload
     *
     * @return false|mixed
     */
    public function set(string $what, $value, bool $autoload = false): bool
    {
        return update_option(TouchPointWP::SETTINGS_PREFIX . $what, $value, $autoload);
    }

    /**
     * Register plugin settings
     *
     * @return void
     */
    public function registerSettings(): void
    {
        $this->settings = $this->settingsFields(false, is_admin() && !wp_doing_ajax());
        if (is_array($this->settings)) {
            // Check posted/selected tab.
            $current_section = '';
            if (isset($_POST['tab']) && $_POST['tab']) {
                $current_section = $_POST['tab'];
            } elseif (isset($_GET['tab']) && $_GET['tab']) {
                $current_section = $_GET['tab'];
            }

            foreach ($this->settings as $section => $data) {
                if ($current_section && $current_section !== $section) {
                    continue;
                }

                // Add section to page.
                add_settings_section(
                    $section,
                    $data['title'],
                    [$this, 'settings_section'],
                    $this->parent::TOKEN . '_Settings'
                );

                foreach ($data['fields'] as $field) {
                    // Validation callback for field.
                    $validation = [];
                    if (isset($field['callback'])) {
                        $validation['sanitize_callback'] = $field['callback'];
                    }

                    // Register field.
                    $option_name = TouchPointWP::SETTINGS_PREFIX . $field['id'];
                    register_setting($this->parent::TOKEN . '_Settings', $option_name, $validation);

                    // Add field to page.
                    add_settings_field(
                        $field['id'],
                        $field['label'],
                        [$this->parent->admin(), 'display_field'],
                        $this->parent::TOKEN . '_Settings',
                        $section,
                        [
                            'field'  => $field,
                            'prefix' => TouchPointWP::SETTINGS_PREFIX,
                        ]
                    );
                }

                if ( ! $current_section) {
                    break;
                }
            }
        }
    }

    /**
     * @param $id
     *
     * @return mixed
     */
    protected function getDefaultValueForSetting(string $id)
    {
        foreach ($this->settingsFields(false, false) as $category) {
            foreach ($category['fields'] as $field) {
                if ($field['id'] === $id){
                    if (isset($field['default'])) { // Is there is a default, return it.
                        return $field['default'];
                    } else {
                        // If the field is defined, but there is no default, return undefined placeholder.
                        // This will also be the result if a module is not enabled.
                        return self::UNDEFINED_PLACEHOLDER;
                    }
                }
            }
        }
        return self::UNDEFINED_PLACEHOLDER;
    }

    /**
     * Settings section.
     *
     * @param array $section Array of section ids.
     *
     * @return void
     */
    public function settings_section(array $section): void
    {
        $html = '<p> ' . $this->settings[$section['id']]['description'] . '</p>' . "\n";
        echo $html;
    }

    /**
     * Load settings page content.
     *
     * @return void
     */
    public function settings_page(): void
    {
        // Build page HTML.
        $html = '<div class="wrap" id="' . $this->parent::TOKEN . '_Settings">' . "\n";
        $html .= '<h2>' . __('TouchPoint-WP Settings', TouchPointWP::TEXT_DOMAIN) . '</h2>' . "\n";

        $tab = '';

        if (isset($_GET['tab']) && $_GET['tab']) {
            $tab .= $_GET['tab'];
        }

        // Show page tabs.
        if (is_array($this->settings) && 1 < count($this->settings)) {
            $html .= '<h2 class="nav-tab-wrapper">' . "\n";

            $c = 0;
            foreach ($this->settings as $section => $data) {
                // Set tab class.
                $class = 'nav-tab';
                if ( ! isset($_GET['tab']) && 0 === $c) {
                    $class .= ' nav-tab-active';
                } elseif (isset($_GET['tab']) && $section == $_GET['tab']) {
                    $class .= ' nav-tab-active';
                }

                // Set tab link.
                $tab_link = add_query_arg(array('tab' => $section));
                if (isset($_GET['settings-updated'])) {
                    $tab_link = remove_query_arg('settings-updated', $tab_link);
                }

                // Output tab.
                $html .= '<a href="' . $tab_link . '" class="' . esc_attr($class) . '">' . esc_html(
                        $data['title']
                    ) . '</a>' . "\n";

                ++$c;
            }

            $html .= '</h2>' . "\n";
        }

        /** @noinspection HtmlUnknownTarget */
        $html .= '<form method="post" action="options.php" enctype="multipart/form-data">' . "\n";

        // Get settings fields.
        ob_start();
        settings_fields($this->parent::TOKEN . '_Settings');
        do_settings_sections($this->parent::TOKEN . '_Settings');
        $html .= ob_get_clean();

        $html .= '<p class="submit">' . "\n";
        $html .= '<input type="hidden" name="tab" value="' . esc_attr($tab) . '" />' . "\n";
        $html .= '<input name="Submit" type="submit" class="button-primary" value="' . esc_attr(
                __('Save Settings', TouchPointWP::TEXT_DOMAIN)
            ) . '" />' . "\n";
        $html .= '</p>' . "\n";
        $html .= '</form>' . "\n";
        $html .= '</div>' . "\n";

        echo $html;
    }

    public function validation_secret($new, $field): string
    {
        if ($new === '') { // If there is no value, submit the already-saved one.
            return $this->$field;
        }

        return $new;
    }

    /**
     * Slug validator.  Also (more importantly) tells WP that it needs to flush rewrite rules.
     *
     * @param mixed  $new The new value.
     * @param string $field The name of the setting.  Used to determine if the setting is actually changed.
     *
     * @return string
     */
    public function validation_slug($new, string $field): string
    {
        if ($new != $this->$field) { // only validate the field if it's changing.
            $new = $this->validation_lowercase($new);
            $new = preg_replace("[^a-z/]", '', $new);

            // since any slug change is probably going to need this...
            $this->parent->queueFlushRewriteRules();
        }
        return $new;
    }

    /**
     * Force a value to lowercase; used as a validator
     *
     * @param string $data  Mixed case string
     *
     * @return string lower-case string
     */
    public function validation_lowercase(string $data): string
    {
        return strtolower($data);
    }

    /**
     * Cloning is forbidden.
     *
     * @since 1.0.0
     */
    public function __clone()
    {
        _doing_it_wrong(
            __FUNCTION__,
            esc_html(__('Cloning TouchPointWP Settings is forbidden.')),
            esc_attr($this->parent::VERSION)
        );
    }

    /**
     * Unserializing instances of this class is forbidden.
     *
     * @since 1.0.0
     */
    public function __wakeup()
    {
        _doing_it_wrong(
            __FUNCTION__,
            esc_html(__('Unserializing instances of TouchPointWP Settings is forbidden.')),
            esc_attr($this->parent::VERSION)
        );
    }

}