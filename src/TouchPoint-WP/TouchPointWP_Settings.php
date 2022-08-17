<?php
/**
 * @package TouchPointWP
 */
namespace tp\TouchPointWP;

if ( ! defined('ABSPATH')) {
    exit;
}

/**
 * The Settings class - most settings are available through the default getter.
 *
 * @property-read string version            The plugin version.  Used for tracking updates.
 *
 * @property-read string enable_authentication  Whether the Authentication module is included.
 * @property-read string enable_involvements  Whether the Involvement module is included.
 * @property-read string enable_people_lists  Whether to allow public People Lists.
 * @property-read string enable_rsvp        Whether the RSVP module is included.
 * @property-read string enable_global      Whether to import Global partners.
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
 * @property-read array people_contact_keywords Keywords to use for the generic Contact person button.
 * @property-read string people_ev_bio      Extra Value field that should be imported as a User bio.
 * @property-read string people_ev_wpId     The name of the extra value field where the WordPress User ID will be stored.
 * @property-read array people_ev_custom    Custom Extra values that are copied as user meta fields
 *
 * @property-read string global_name_plural What global partners should be called, plural (e.g. "Missionaries" or "Ministry Partners")
 * @property-read string global_name_plural_decoupled What global partners should be called when they're Secure, plural (e.g. "Secure Partners")
 * @property-read string global_name_singular What a global partner should be called, singular (e.g. "Missionary" or "Ministry Partner")
 * @property-read string global_name_singular_decoupled What a secure global partner should be called, singular (e.g. "Secure Partner")
 * @property-read string global_slug        Slug for global partners (e.g. "partners" for church.org/partners)
 * @property-read string global_search      The uid for the saved search to use for global partners
 * @property-read string global_description A Family Extra Value to import as the body of a global partner's post.
 * @property-read string global_summary     A Family Extra Value to import as the summary of a global partner.
 * @property-read string global_geo_lat     A Family Extra Value to import as an overriding latitude.
 * @property-read string global_geo_lng     A Family Extra Value to import as an overriding longitude.
 * @property-read string global_location    A Family Extra Value to import as a location label.
 * @property-read array global_fev_custom   Custom Family Extra values that are copied as post meta fields
 * @property-read string global_primary_tax A Family Extra Value that should be used as the primary taxonomy for partners
 *
 * @property-read int|false global_cron_last_run Timestamp of the last time the Partner syncing task ran.  (No setting UI.)
 *
 * @property-read string auth_script_name   The name of the Python script within TouchPoint
 * @property-read string auth_default       Enabled when TouchPoint should be used as the primary authentication method
 * @property-read string auth_change_profile_urls Enabled to indicate the profiles should be located on TouchPoint
 * @property-read string auth_auto_provision Enabled to indicate that new users should be created automatically.
 * @property-read string auth_prevent_admin_bar Enabled to prevent Admin Bar to appearing on webpages for users who don't have special roles.
 * @property-read string auth_full_logout   Enabled to indicate that logging out of WordPress should also log the user out of TouchPoint.
 *
 * @property-read int|false person_cron_last_run Timestamp of the last time the Person syncing task ran.  (No setting UI.)
 *
 * @property-read int|false inv_cron_last_run Timestamp of the last time the Involvement syncing task ran.  (No setting UI.)
 * @property-read string inv_json           JSON string describing how Involvements should be handled.  (No direct setting UI.)
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
        add_filter(TouchPointWP::SETTINGS_PREFIX . 'menu_settings', [$this, 'configureSettings']);
    }

    /**
     * Main TouchPointWP_Settings Instance
     *
     * Ensures only one instance of TouchPointWP_Settings is loaded or can be loaded.
     *
     * @param ?TouchPointWP $parent Object instance.
     *
     * @return TouchPointWP_Settings instance
     * @since 1.0.0
     * @static
     * @see TouchPointWP()
     */
    public static function instance(?TouchPointWP $parent = null): TouchPointWP_Settings
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
     * Initialise settings
     *
     * @return void
     */
    public function initSettings(): void
    {
        $this->settings = $this->settingsFields();
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
     * @param bool|string $includeDetail Set to true to get options from TouchPoint, likely including the API calls. Set
     *                      to the key of a specific page to only load options for that page.
     *
     * @return array Fields to be displayed on settings page
     */
    private function settingsFields($includeDetail = false): array
    {
        // Don't call API if we don't have API credentials
        if (!$this->hasValidApiSettings()) {
            $includeDetail = false;
        }

        if (count($this->settings) > 0 && $includeDetail === false) {
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
                    'callback'    => fn($new) => $this->validation_updateScriptsIfChanged($new, 'enable_authentication'),
                ],
                [
                    'id'          => 'enable_rsvp',
                    'label'       => __('Enable RSVP Tool', 'TouchPoint-WP'),
                    'description' => __('Add a crazy-simple RSVP button to WordPress event pages.', 'TouchPoint-WP'),
                    'type'        => 'checkbox',
                    'default'     => '',
                ],
                [
                    'id'          => 'enable_involvements',
                    'label'       => __('Enable Involvements', 'TouchPoint-WP'),
                    'description' => __(
                        'Load Involvements from TouchPoint for involvement listings and entries native in your website.',
                        TouchPointWP::TEXT_DOMAIN
                    ),
                    'type'        => 'checkbox',
                    'default'     => '',
                ],
                [
                    'id'          => 'enable_people_lists',
                    'label'       => __('Enable Public People Lists', 'TouchPoint-WP'),
                    'description' => __(
                        'Import public people listings from TouchPoint (e.g. staff or elders)',
                        TouchPointWP::TEXT_DOMAIN
                    ),
                    'type'        => 'checkbox',
                    'default'     => '',
                ],
                [
                    'id'          => 'enable_global',
                    'label'       => __('Enable Global Partner Listings', 'TouchPoint-WP'),
                    'description' => __(
                        'Import ministry partners from TouchPoint to list publicly.',
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
                    'placeholder' => '',
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
                        '{apiUrl}'    => "/" . TouchPointWP::API_ENDPOINT . "/" . TouchPointWP::API_ENDPOINT_ADMIN_SCRIPTZIP,
                        '{tpName}'    => $this->get('system_name'),
                        '{uploadUrl}' => "https://" . $this->get('host') . "/InstallPyScriptProject"
                    ]
                ),
            ];
        }

        if (get_option(TouchPointWP::SETTINGS_PREFIX . 'enable_people_lists') === "on") { // TODO MULTI
            $includeThis = $includeDetail === true || $includeDetail === 'people';
            $this->settings['people'] = [
                'title'       => __('People', TouchPointWP::TEXT_DOMAIN),
                'description' => __('Manage how people are synchronized between TouchPoint and WordPress.', TouchPointWP::TEXT_DOMAIN),
                'fields'      => [
                    [
                        'id'          => 'people_contact_keywords',
                        'label'       => __('Contact Keywords', TouchPointWP::TEXT_DOMAIN),
                        'description' => __(
                            'These keywords will be used when someone clicks the "Contact" button on a Person\'s listing or profile.',
                            TouchPointWP::TEXT_DOMAIN
                        ),
                        'type'        => 'checkbox_multi',
                        'formClass'   => 'column-wrap',
                        'options'     => $includeThis ? $this->parent->getKeywordsAsKVArray() : [],
                        'default'     => [],
                    ],
                    [
                        'id'          => 'people_ev_wpId',
                        'label'       => __('Extra Value for WordPress User ID', TouchPointWP::TEXT_DOMAIN),
                        'description' => __(
                            'The name of the extra value to use for the WordPress User ID.  If you are using multiple WordPress instances with one TouchPoint database, you will need these values to be unique between WordPress instances.  In most cases, the default is fine.',
                            TouchPointWP::TEXT_DOMAIN
                        ),
                        'type'        => 'text',
                        'default'     => 'WordPress User ID',
                        'placeholder' => 'WordPress User ID'
                    ],
                    [
                        'id'          => 'people_ev_bio',
                        'label'       => __('Extra Value: Biography', TouchPointWP::TEXT_DOMAIN),
                        'description' => __(
                            'Import a Bio from a Person Extra Value field.  Can be an HTML or Text Extra Value.  This will overwrite any values set by WordPress.  Leave blank to not import.',
                            TouchPointWP::TEXT_DOMAIN
                        ),
                        'type'        => 'select',
                        'options'     => $includeThis ? $this->parent->getPersonEvFieldsAsKVArray('text', true) : [],
                        'default'     => '',
                    ],
                    [
                        'id'          => 'people_ev_custom',
                        'label'       => __('Extra Values to Import', TouchPointWP::TEXT_DOMAIN),
                        'description' => __(
                            'Import People Extra Value fields as User Meta data.',
                            TouchPointWP::TEXT_DOMAIN
                        ),
                        'type'        => 'checkbox_multi',
                        'formClass'   => 'column-wrap',
                        'options'     => $includeThis ? $this->parent->getPersonEvFieldsAsKVArray() : [],
                        'default'     => [],
                    ],
                ],
            ];
        }

        if (get_option(TouchPointWP::SETTINGS_PREFIX . 'enable_authentication') === "on") { // TODO MULTI
//            $includeThis = $includeDetail === true || $includeDetail === 'authentication';
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
                        'placeholder' => 'WebAuth',
                        'callback'    => fn($new) => $this->validation_updateScriptsIfChanged($new, 'auth_script_name'),
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

        if (get_option(TouchPointWP::SETTINGS_PREFIX . 'enable_involvements') === "on") {  // TODO MULTI
            $includeThis = $includeDetail === true || $includeDetail === 'involvements';
            $this->settings['involvements'] = [
                'title'       => __('Involvements', TouchPointWP::TEXT_DOMAIN),
                'description' => __('Import Involvements from TouchPoint to your website, for Small Groups, Classes, and more.  You do not need to import an involvement here to use the RSVP tool.', TouchPointWP::TEXT_DOMAIN),
                'fields'      => [
                    [
                        'id'          => 'inv_json', // involvement settings json (stored as a json string)
                        'type'        => 'textarea',
                        'label'       => __('Involvement Post Types', 'TouchPoint-WP'),
                        'default'     => '{}',
                        'hidden'      => true,
                        'description' => !$includeThis ? "" : function() {
                            TouchPointWP::requireScript("base");
                            TouchPointWP::requireScript("knockout-defer");
                            TouchPointWP::requireScript("select2-defer");

                            foreach (Involvement_PostTypeSettings::instance() as $it) {
                                if (is_numeric($it->taskOwner)) {
                                    Person::enqueueForJS_byPeopleId(intval($it->taskOwner));
                                }
                            }

                            ob_start();
                            /** @noinspection PhpIncludeInspection */
                            include TouchPointWP::$dir . "/src/templates/admin/invKoForm.php";
                            return ob_get_clean();
                        },
                        'callback'    => fn($new) => Involvement_PostTypeSettings::validateNewSettings($new)
                    ],
                ],
            ];
        }

        if (get_option(TouchPointWP::SETTINGS_PREFIX . 'enable_global') === "on") { // TODO MULTI
            $includeThis = $includeDetail === true || $includeDetail === 'global';
            $this->settings['global'] = [
                'title'       => __('Global Partners', TouchPointWP::TEXT_DOMAIN),
                'description' => __('Manage how global partners are imported from TouchPoint for listing on WordPress.  Partners are grouped by family, and content is provided through Family Extra Values.  This works for both People and Business records.', TouchPointWP::TEXT_DOMAIN),
                'fields'      => [
                    [
                        'id'          => 'global_name_plural',
                        'label'       => __('Global Partner Name (Plural)', TouchPointWP::TEXT_DOMAIN),
                        'description' => __(
                            'What you call Global Partners at your church',
                            TouchPointWP::TEXT_DOMAIN
                        ),
                        'type'        => 'text',
                        'default'     => 'Partners',
                        'placeholder' => 'Partners'
                    ],
                    [
                        'id'          => 'global_name_singular',
                        'label'       => __('Global Partner Name (Singular)', TouchPointWP::TEXT_DOMAIN),
                        'description' => __(
                            'What you call a Global Partner at your church',
                            TouchPointWP::TEXT_DOMAIN
                        ),
                        'type'        => 'text',
                        'default'     => 'Partner',
                        'placeholder' => 'Partner'
                    ],
                    [
                        'id'          => 'global_name_plural_decoupled',
                        'label'       => __('Global Partner Name for Secure Places (Plural)', TouchPointWP::TEXT_DOMAIN),
                        'description' => __(
                            'What you call Secure Global Partners at your church',
                            TouchPointWP::TEXT_DOMAIN
                        ),
                        'type'        => 'text',
                        'default'     => 'Secure Partners',
                        'placeholder' => 'Secure Partners'
                    ],
                    [
                        'id'          => 'global_name_singular_decoupled',
                        'label'       => __('Global Partner Name for Secure Places (Singular)', TouchPointWP::TEXT_DOMAIN),
                        'description' => __(
                            'What you call a Secure Global Partner at your church',
                            TouchPointWP::TEXT_DOMAIN
                        ),
                        'type'        => 'text',
                        'default'     => 'Secure Partner',
                        'placeholder' => 'Secure Partner'
                    ],
                    [
                        'id'          => 'global_slug',
                        'label'       => __('Global Partner Slug', TouchPointWP::TEXT_DOMAIN),
                        'description' => __(
                            'The root path for Global Partner posts',
                            TouchPointWP::TEXT_DOMAIN
                        ),
                        'type'        => 'text',
                        'default'     => 'partners',
                        'placeholder' => 'partners',
                        'callback'    => fn($new) => $this->validation_slug($new, 'global_slug')
                    ],
                    [
                        'id'          => 'global_search',
                        'label'       => __('Saved Search', TouchPointWP::TEXT_DOMAIN),
                        'description' => __(
                            'Anyone who is included in this saved search will be included in the listing.',
                            TouchPointWP::TEXT_DOMAIN
                        ),
                        'type'        => 'select_grouped',
                        'options'     => $includeThis ? $this->parent->getSavedSearches(null, $this->global_search) : [],
                        'default'     => '',
                    ],
                    [
                        'id'          => 'global_description',
                        'label'       => __('Extra Value: Description', TouchPointWP::TEXT_DOMAIN),
                        'description' => __(
                            'Import a description from a Family Extra Value field.  Can be an HTML or Text Extra Value.  This becomes the body of the Global Partner post.',
                            TouchPointWP::TEXT_DOMAIN
                        ),
                        'type'        => 'select',
                        'options'     => $includeThis ? $this->parent->getFamilyEvFieldsAsKVArray('text', true) : [],
                        'default'     => '',
                    ],
                    [
                        'id'          => 'global_summary',
                        'label'       => __('Extra Value: Summary', TouchPointWP::TEXT_DOMAIN),
                        'description' => __(
                            'Optional. Import a short description from a Family Extra Value field.  Can be an HTML or Text Extra Value.  If not provided, the full bio will be truncated.',
                            TouchPointWP::TEXT_DOMAIN
                        ),
                        'type'        => 'select',
                        'options'     => $includeThis ? $this->parent->getFamilyEvFieldsAsKVArray('text', true) : [],
                        'default'     => '',
                    ],
                    [
                        'id'          => 'global_geo_lat',
                        'label'       => __('Latitude Override', TouchPointWP::TEXT_DOMAIN),
                        'description' => __(
                            'Designate a text Family Extra Value that will contain a latitude that overrides any locations on the partner\'s profile for the partner map.  Both latitude and longitude must be provided for an override to take place.',
                            TouchPointWP::TEXT_DOMAIN
                        ),
                        'type'        => 'select',
                        'options'     => $includeThis ? $this->parent->getFamilyEvFieldsAsKVArray('text', true) : [],
                        'default'     => '',
                    ],
                    [
                        'id'          => 'global_geo_lng',
                        'label'       => __('Longitude Override', TouchPointWP::TEXT_DOMAIN),
                        'description' => __(
                            'Designate a text Family Extra Value that will contain a longitude that overrides any locations on the partner\'s profile for the partner map.  Both latitude and longitude must be provided for an override to take place.',
                            TouchPointWP::TEXT_DOMAIN
                        ),
                        'type'        => 'select',
                        'options'     => $includeThis ? $this->parent->getFamilyEvFieldsAsKVArray('text', true) : [],
                        'default'     => '',
                    ],
                    [
                        'id'          => 'global_location',
                        'label'       => __('Public Location', TouchPointWP::TEXT_DOMAIN),
                        'description' => __(
                            'Designate a text Family Extra Value that will contain the partner\'s location, as you want listed publicly.  For partners who have DecoupleLocation enabled, this field will be associated with the map point, not the list entry.',
                            TouchPointWP::TEXT_DOMAIN
                        ),
                        'type'        => 'select',
                        'options'     => $includeThis ? $this->parent->getFamilyEvFieldsAsKVArray('text', true) : [],
                        'default'     => '',
                    ],
                    [
                        'id'          => 'global_fev_custom',
                        'label'       => __('Extra Values to Import', TouchPointWP::TEXT_DOMAIN),
                        'description' => __(
                            'Import Family Extra Value fields as Meta data on the partner\'s post.',
                            TouchPointWP::TEXT_DOMAIN
                        ),
                        'type'        => 'checkbox_multi',
                        'formClass'   => 'column-wrap',
                        'options'     => $includeThis ? $this->parent->getFamilyEvFieldsAsKVArray() : [],
                        'default'     => [],
                    ],
                    [
                        'id'          => 'global_primary_tax',
                        'label'       => __('Primary Taxonomy', TouchPointWP::TEXT_DOMAIN),
                        'description' => __(
                            'Import a Family Extra Value as the primary means by which partners are organized.',
                            TouchPointWP::TEXT_DOMAIN
                        ),
                        'type'        => 'select',
                        'options'     => $includeThis ? $this->parent->getFamilyEvFieldsAsKVArray('code', true) : [],
                        'default'     => [],
                    ],
                ],
            ];
        }

        if (TouchPointWP::useTribeCalendar()) {
            /** @noinspection HtmlUnknownTarget */
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
                            '<input type="url" value="{apiUrl}" readonly style="width: 100%;" />' .
                            '<a href="{previewUrl}" class="btn">' . __('Preview', TouchPointWP::TEXT_DOMAIN) . '</a>',
                            [
                                '{apiUrl}'    => get_site_url() . "/" .
                                                 TouchPointWP::API_ENDPOINT . "/" .
                                                 TouchPointWP::API_ENDPOINT_APP_EVENTS . "?v=" .
                                                 TouchPointWP::VERSION,
                                '{previewUrl}' => get_site_url() . "/" .
                                                 TouchPointWP::API_ENDPOINT . "/" .
                                                 TouchPointWP::API_ENDPOINT_APP_EVENTS . "/preview/?v=" .
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

        $includeThis = $includeDetail === true || $includeDetail === 'divisions';
        $this->settings['divisions'] = [
            'title'       => __('Divisions', TouchPointWP::TEXT_DOMAIN),
            'description' => __('Import Divisions from TouchPoint to your website as a taxonomy.  These are used to classify users and involvements.', TouchPointWP::TEXT_DOMAIN),
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
                    'options'     => $includeThis ? $this->parent->getDivisionsAsKVArray() : [],
                    'default'     => [],
                    'callback'    => function($new) {sort($new); return $new;}
                ],
            ],
        ];

        $this->settings['campuses'] = [
            'title'       => __('Campuses', TouchPointWP::TEXT_DOMAIN),
            'description' => __('Import Campuses from TouchPoint to your website as a taxonomy.  These are used to classify users and involvements.', TouchPointWP::TEXT_DOMAIN),
            'fields'      => [
                [
                    'id'          => 'camp_name_plural',
                    'label'       => __('Campus Name (Plural)', TouchPointWP::TEXT_DOMAIN),
                    'description' => __(
                        'What you call Campuses at your church',
                        TouchPointWP::TEXT_DOMAIN
                    ),
                    'type'        => 'text',
                    'default'     => 'Campuses',
                    'placeholder' => 'Campuses'
                ],
                [
                    'id'          => 'camp_name_singular',
                    'label'       => __('Campus Name (Singular)', TouchPointWP::TEXT_DOMAIN),
                    'description' => __(
                        'What you call a Campus at your church',
                        TouchPointWP::TEXT_DOMAIN
                    ),
                    'type'        => 'text',
                    'default'     => 'Campus',
                    'placeholder' => 'Campus'
                ],
                [
                    'id'          => 'camp_slug',
                    'label'       => __('Campus Slug', TouchPointWP::TEXT_DOMAIN),
                    'description' => __(
                        'The root path for the Campus Taxonomy',
                        TouchPointWP::TEXT_DOMAIN
                    ),
                    'type'        => 'text',
                    'default'     => 'campus',
                    'placeholder' => 'campus',
                    'callback'    => fn($new) => $this->validation_slug($new, 'camp_slug')
                ]
            ],
        ];

        $this->settings['resident_codes'] = [
            'title'       => __('Resident Codes', TouchPointWP::TEXT_DOMAIN),
            'description' => __('Import Resident Codes from TouchPoint to your website as a taxonomy.  These are used to classify users and involvements that have locations.', TouchPointWP::TEXT_DOMAIN),
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

        $this->settings = apply_filters($this->parent::TOKEN . '_settings_fields', $this->settings);

        return $this->settings;
    }

    /**
     * Returns a placeholder for a password setting field that doesn't expose the password itself.
     *
     * @param string $settingName
     *
     * @return string
     * @noinspection PhpSameParameterValueInspection
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
                    add_submenu_page(
                        $args['parent_slug'],
                        $args['page_title'],
                        $args['menu_title'],
                        $args['capability'],
                        $args['menu_slug'],
                        $args['function']
                    );
                    break;
                case 'menu':
                    add_menu_page(
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
            // add_action('admin_print_styles-' . $page, [$this, 'settings_assets']);  TODO SOMEDAY MAYBE if needing to upload media through interface, uncomment this.
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
                'function'    => [$this, 'settingsPage'],
                'icon_url'    => '',
                'position'    => null,
            ]
        );
    }

    /**
     * Container for settings page arguments
     *
     * @param ?array $settings Settings array.
     *
     * @return array
     */
    public function configureSettings(?array $settings = []): array
    {
        return $settings;
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
        $links[]       = $settings_link;

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
        $opt = get_option(TouchPointWP::SETTINGS_PREFIX . $what, $default); // TODO MULTI

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
        return update_option(TouchPointWP::SETTINGS_PREFIX . $what, $value, $autoload); // TODO MULTI
    }


    /**
     * Migrate settings from version to version.  This may be called even when a migration isn't necessary.
     */
    public function migrate(): void
    {
        global $wpdb;

        try {
            $this->updateDeployedScripts();
        } catch (TouchPointWP_Exception $e) {
            if (is_admin()) {
                TouchPointWP_AdminAPI::showError($e->getMessage());
            }
        }

        // 0.0.4 to 0.0.5 -- Merging Small Groups and Courses Components into a single Involvement Component
        $sgEnabled = $this->getWithoutDefault('enable_small_groups') === "on";
        $csEnabled = $this->getWithoutDefault('enable_courses') === "on";
        if ($sgEnabled || $csEnabled) {
            $this->set('enable_involvements', 'on');

            $invSettings = [];

            // Migrate Smallgroup settings
            if ($sgEnabled) {
                $settings = [
                    'nameSingular' => $this->get('sg_name_singular'),
                    'namePlural' => $this->get('sg_name_plural'),
                    'slug' => $this->get('sg_slug'),
                    'importDivs' => $this->get('sg_divisions'),
                    'useGeo' => true,
                    'leaderTypes' => $this->get('sg_leader_types'),
                    'hostTypes' => $this->get('sg_host_types'),
                    'filters' => $this->get('sg_filter_defaults'),
                    'postType' => "smallgroup"
                ];
                $invSettings[] = (object)$settings;
            }

            // Migrate Course settings
            if ($csEnabled) {
                $settings = [
                    'nameSingular' => $this->get('cs_name_singular'),
                    'namePlural' => $this->get('cs_name_plural'),
                    'slug' => $this->get('cs_slug'),
                    'importDivs' => $this->get('cs_divisions'),
                    'useGeo' => false,
                    'leaderTypes' => $this->get('cs_leader_types'),
                    'hostTypes' => [],
                    'filters' => $this->get('cs_filter_defaults'),
                    'postType' => "course"
                ];
                $invSettings[] = (object)$settings;
            }

            // Save Smallgroup & Course settings
            $this->set('inv_json', json_encode($invSettings), true);

            // Remove the old settings
            foreach (wp_load_alloptions() as $option => $value) {
                if (strpos($option, TouchPointWP::SETTINGS_PREFIX . 'sg_') === 0 ||
                    strpos($option, TouchPointWP::SETTINGS_PREFIX . 'cs_') === 0) {
                    delete_option($option); // TODO MULTI
                }
            }

//            delete_network_option(null, TouchPointWP::SETTINGS_PREFIX . 'enable_courses'); // TODO MULTI
            delete_option(TouchPointWP::SETTINGS_PREFIX . 'enable_courses');
//            delete_network_option(null, TouchPointWP::SETTINGS_PREFIX . 'enable_small_groups'); // TODO MULTI
            delete_option(TouchPointWP::SETTINGS_PREFIX . 'enable_small_groups');
        }

        // Remove former smallgroup cron hook.  New cron is scheduled elsewhere.
        if (wp_next_scheduled(TouchPointWP::HOOK_PREFIX . "sg_cron_hook")) {
            wp_clear_scheduled_hook(TouchPointWP::HOOK_PREFIX . "sg_cron_hook");
        }

        // Replace SgNearby shortcode with newer Inv-Nearby
        $oldShortcode = "[" . TouchPointWP::SHORTCODE_PREFIX . "SgNearby";
        $newShortcode = "[" . Involvement::SHORTCODE_NEARBY;
        if ($sgEnabled) {
            $newShortcode .= " type=smallgroup";
        }
        /** @noinspection SqlResolve */
        $wpdb->query("
            UPDATE $wpdb->posts
            SET post_content = REPLACE(post_content, '$oldShortcode', '$newShortcode') 
            WHERE post_content LIKE '%$oldShortcode%'
        ");

        // Update version string
        $this->set('version', TouchPointWP::VERSION);
    }

    /**
     * Generate new scripts and deploy to TouchPoint.
     *
     * @return void
     * @throws TouchPointWP_Exception
     */
    public function updateDeployedScripts(): void
    {
        $scripts = ["WebApi" => TouchPointWP::instance()->settings->api_script_name];
        if (TouchPointWP::instance()->settings->enable_authentication) {
            $scripts["WebAuth"] = TouchPointWP::instance()->settings->auth_script_name;
        }

        $scriptContent = TouchPointWP::instance()->admin()->generatePython(false, $scripts);
        $data = TouchPointWP::instance()->apiPost('updateScripts', $scriptContent, 60);
        $updates = $data->scriptsUpdated ?? 0;

        if (count($scriptContent) !== $updates) {
            throw new TouchPointWP_Exception(__("Script Update Failed", TouchPointWP::TEXT_DOMAIN), 170004);
        }
    }

    /**
     * Register plugin settings
     *
     * @return void
     */
    public function registerSettings(): void
    {
        $currentSection = false;
        if (isset($_POST['tab']) && $_POST['tab']) {
            $currentSection = $_POST['tab'];
        } elseif (isset($_GET['tab']) && $_GET['tab']) {
            $currentSection = $_GET['tab'];
        }

        $this->settings = $this->settingsFields($currentSection);
        foreach ($this->settings as $section => $data) {
            // Check posted/selected tab.
            if ($currentSection && $currentSection !== $section) {
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
                $args = [];
                if (isset($field['callback'])) {
                    $args['sanitize_callback'] = $field['callback'];
                }

                // Register field.  Don't save a value for instruction types.
                if ($field['type'] == 'instructions') {
                    $args['sanitize_callback'] = fn($new) => null;
                }

                $option_name = TouchPointWP::SETTINGS_PREFIX . $field['id'];
                register_setting($this->parent::TOKEN . '_Settings', $option_name, $args);

                // Add field to page.
                add_settings_field(
                    $field['id'],
                    $field['label'],
                    [$this->parent->admin(), 'displayField'],
                    $this->parent::TOKEN . '_Settings',
                    $section,
                    [
                        'field'  => $field,
                        'prefix' => TouchPointWP::SETTINGS_PREFIX,
                    ]
                );
            }

            if ( ! $currentSection) {
                break;
            }
        }
    }

    /**
     * Gets the default value for a setting field, if one exists.  Otherwise, the UNDEFINED_PLACEHOLDER is returned.
     *
     * @param string $id
     *
     * @return mixed
     */
    protected function getDefaultValueForSetting(string $id)
    {
        if (substr($id, 0, 7) === "enable") {  // Prevents settings content from needing to be generated for these settings.
            return '';
        }
        foreach ($this->settingsFields() as $category) {
            foreach ($category['fields'] as $field) {
                if ($field['id'] === $id) {
                    if (array_key_exists('default', $field)) {
                        return $field['default'];
                    }
                    return self::UNDEFINED_PLACEHOLDER;
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
    public function settingsPage(): void
    {
        // Build page HTML.
        $html = '<div class="wrap" id="' . $this->parent::TOKEN . '_Settings">' . "\n";
        $html .= '<h2>' . __('TouchPoint-WP Settings', TouchPointWP::TEXT_DOMAIN) . '</h2>' . "\n";

        $tab = '';

        if (isset($_GET['tab']) && $_GET['tab']) {
            $tab .= $_GET['tab'];
        }

        // Show page tabs.
        if (count($this->settings) > 1) {
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

    /**
     * Validator for Secret settings, like API keys.  If a new value is not provided, the old value is kept intact.
     *
     * @param string $new
     * @param string $field
     *
     * @return string
     */
    protected function validation_secret(string $new, string $field): string
    {
        if ($new === '') { // If there is no value, submit the already-saved one.
            return $this->$field;
        }

        return $new;
    }

    /**
     * Slug validator.  Also, (more importantly) tells WP that it needs to flush rewrite rules.
     *
     * @param mixed  $new The new value.
     * @param string $field The name of the setting.  Used to determine if the setting is actually changed.
     *
     * @return string
     */
    protected function validation_slug($new, string $field): string
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
     * If a setting is changed that impacts the scripts, update the scripts.
     *
     * @param mixed $new the new value, which could be anything
     * @param string $field The name of the field that's getting updated
     *
     * @return mixed lower-case string
     */
    protected function validation_updateScriptsIfChanged($new, string $field)
    {
        if ($new !== $this->$field) {
            TouchPointWP::queueUpdateDeployedScripts();
        }
        return $new;
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