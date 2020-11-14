<?php

namespace tp\TouchPointWP;

/**
 * Settings class file.
 *
 * Class TouchPointWP
 * @package tp\TouchPointWP
 */

if ( ! defined('ABSPATH')) {
    exit;
}

/**
 * Settings class.
 *
 * @property-read string host       The base URL for the TouchPoint instance
 * @property-read string api_user   Username of a user account with API access
 * @property-read string api_pass   Password for a user account with API access
 * @property-read string ip_whitelist TouchPoint Server IPs
 *
 * @property-read string auth_display_name  What the church calls TouchPoint
 * @property-read string auth_script_name   The name of the Python script within TouchPoint
 * @property-read string auth_default       Enabled when TouchPoint should be used as the primary authentication method
 * @property-read string auth_change_profile_urls Enabled to indicate the profiles should be located on TouchPoint
 * @property-read string auth_auto_provision Enabled to indicate that new users should be created automatically.
 * @property-read string auth_prevent_admin_bar Enabled to prevent Admin Bar to appearing on webpages for users who don't have special roles.
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
    public array $settings = [];

    /**
     * Constructor function.
     *
     * @param TouchPointWP $parent Parent object.
     */
    public function __construct(TouchPointWP $parent)
    {
        $this->parent = $parent;

        // Initialise settings.
        add_action('init', [$this, 'init_settings'], 11);

        // Register plugin settings.
        add_action('admin_init', [$this, 'register_settings']);

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
    public static function instance(TouchPointWP $parent)
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
    public function init_settings()
    {
        $this->settings = $this->settings_fields();
    }

    /**
     * Build settings fields
     *
     * @return array Fields to be displayed on settings page
     */
    private function settings_fields()
    {
        $settings['basic'] = [
            'title'       => __('Basic Settings', 'TouchPoint-WP'),
            'description' => __('Connect to TouchPoint and choose which features you with to use.', 'TouchPoint-WP'),
            'fields'      => [
                [
                    'id'          => 'enable_authentication',
                    'label'       => __('Enable Authentication', 'TouchPoint-WP'),
                    'description' => __(
                        'Allow TouchPoint users to sign into this website with TouchPoint.',
                        'TouchPoint-WP'
                    ),
                    'type'        => 'checkbox',
                    'default'     => '',
                ],
                [
                    'id'          => 'enable_rsvp',
                    'label'       => __('Enable RSVP Tool', 'TouchPoint-WP'),
                    'description' => __('Add a crazy-simple RSVP button to WordPress event pages.', 'TouchPoint-WP'),
                    'type'        => 'checkbox',
                    'default'     => '',
                ],
                [
                    'id'          => 'enable_small_groups',
                    'label'       => __('Enable Small Groups', 'TouchPoint-WP'),
                    'description' => __(
                        'Load Small Groups from TouchPoint for a web-based Small Group finder.',
                        'TouchPoint-WP'
                    ),
                    'type'        => 'checkbox',
                    'default'     => '',
                ],
                [
                    'id'          => 'host',
                    'label'       => __('TouchPoint Host Name', 'TouchPoint-WP'),
                    'description' => __(
                        'The web address for your TouchPoint database, without the https or any slashes.',
                        'TouchPoint-WP'
                    ),
                    'type'        => 'text',
                    'default'     => 'mychurch.tpsdb.com',
                    'placeholder' => 'mychurch.tpsdb.com',
                ],
                [
                    'id'          => 'api_user',
                    'label'       => __('TouchPoint API User name', 'TouchPoint-WP'),
                    'description' => __(
                        'The username of a user account in TouchPoint with API permissions.  Required for RSVP tool.',
                        'TouchPoint-WP'
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
                        'TouchPoint-WP'
                    ),
                    'type'        => 'text_secret',
                    'default'     => '',
                    'placeholder' => '',
                ],
                [
                    'id'          => 'ip_whitelist',
                    'label'       => __('TouchPoint Server Outgoing IP Addresses', 'TouchPoint-WP'),
                    'description' => __(
                        'One IP address per line.  You should probably only use this if you\'re self-hosting and thereby control the outgoing IPs from TouchPoint.',
                        'TouchPoint-WP'
                    ),
                    'type'        => 'textarea',
                    'default'     => '',
                    'placeholder' => TouchPointWP::DEFAULT_IP_WHITELIST,
                ],
            ],
        ];

        $settings['authentication'] = [
            'title'       => __('Authentication', 'TouchPoint-WP'),
            'description' => __('Allow users to log into WordPress using TouchPoint.', 'TouchPoint-WP'),
            'fields'      => [
                [
                    'id'          => 'auth_script_name',
                    'label'       => __('Authentication Script name', 'TouchPoint-WP'),
                    'description' => __(
                        'The filename of the authentication script installed in your TouchPoint database.',
                        'TouchPoint-WP'
                    ),
                    'type'        => 'text',
                    'default'     => 'WebAuth',
                    'placeholder' => 'WebAuth'
                ],
                [
                    'id'          => 'auth_display_name',
                    'label'       => __('Display Name', 'TouchPoint-WP'),
                    'description' => __(
                        'What you call TouchPoint.  Shows on the WordPress login screen.',
                        'TouchPoint-WP'
                    ),
                    'type'        => 'text',
                    'default'     => 'TouchPoint',
                    'placeholder' => 'TouchPoint'
                ],
                [
                    'id'          => 'auth_default',
                    'label'       => __('Make TouchPoint the default authentication method.', 'TouchPoint-WP'),
                    'description' => __(
                        'By checking this box, the TouchPoint login page will become the default.  To prevent the redirect and reach the standard TouchPoint login page, add \'' . TouchPointWP::HOOK_PREFIX . 'no_redirect\' as a URL parameter.',
                        'TouchPoint-WP'
                    ),
                    'type'        => 'checkbox',
                    'default'     => '',
                ],
                [
                    'id'          => 'auth_auto_provision',
                    'label'       => __('Enable Auto-Provisioning', 'TouchPoint-WP'),
                    'description' => __(
                        'Automatically create WordPress users, if needed, to match authenticated TouchPoint users.',
                        'TouchPoint-WP'
                    ),
                    'type'        => 'checkbox',
                    'default'     => 'on',
                ],
                [
                    'id'          => 'auth_background', // TODO this.
                    'label'       => __('Enable Auto-Sign in', 'TouchPoint-WP'),
                    'description' => __(
                        'Automatically sign in WordPress users when already signed into TouchPoint.',
                        'TouchPoint-WP'
                    ),
                    'type'        => 'checkbox',
                    'default'     => 'on',
                ],
                [
                    'id'          => 'auth_change_profile_urls',
                    'label'       => __('Change \'Edit Profile\' links', 'TouchPoint-WP'),
                    'description' => __(
                        '"Edit Profile" links will take the user to their TouchPoint profile, instead of their WordPress profile.',
                        'TouchPoint-WP'
                    ),
                    'type'        => 'checkbox',
                    'default'     => 'on',
                ],
                [
                    'id'          => 'auth_full_logout', // TODO this.
                    'label'       => __('Enable full logout', 'TouchPoint-WP'),
                    'description' => __('Logout of TouchPoint when logging out of WordPress.', 'TouchPoint-WP'),
                    'type'        => 'checkbox',
                    'default'     => 'on',
                ],
                [
                    'id'          => 'auth_prevent_admin_bar',
                    'label'       => __('Prevent Subscriber Admin Bar', 'TouchPoint-WP'),
                    'description' => __(
                        'By enabling this option, users who can\'t edit anything won\'t see the Admin bar.',
                        'TouchPoint-WP'
                    ),
                    'type'        => 'checkbox',
                    'default'     => '',
                ],
            ],
        ];

        /*	$settings['general'] = [
                'title'       => __( 'Standard', 'TouchPoint-WP' ),
                'description' => __( 'These are fairly standard form input fields.', 'TouchPoint-WP' ),
                'fields'      => [
                    [
                        'id'          => 'text_field',
                        'label'       => __( 'Some Text', 'TouchPoint-WP' ),
                        'description' => __( 'This is a standard text field.', 'TouchPoint-WP' ),
                        'type'        => 'text',
                        'default'     => '',
                        'placeholder' => __( 'Placeholder text', 'TouchPoint-WP' ),
                    ],
                    [
                        'id'          => 'password_field',
                        'label'       => __( 'A Password', 'TouchPoint-WP' ),
                        'description' => __( 'This is a standard password field.', 'TouchPoint-WP' ),
                        'type'        => 'password',
                        'default'     => '',
                        'placeholder' => __( 'Placeholder text', 'TouchPoint-WP' ),
                    ],
                    [
                        'id'          => 'secret_text_field',
                        'label'       => __( 'Some Secret Text', 'TouchPoint-WP' ),
                        'description' => __( 'This is a secret text field - any data saved here will not be displayed after the page has reloaded, but it will be saved.', 'TouchPoint-WP' ),
                        'type'        => 'text_secret',
                        'default'     => '',
                        'placeholder' => __( 'Placeholder text', 'TouchPoint-WP' ),
                    ],
                    [
                        'id'          => 'text_block',
                        'label'       => __( 'A Text Block', 'TouchPoint-WP' ),
                        'description' => __( 'This is a standard text area.', 'TouchPoint-WP' ),
                        'type'        => 'textarea',
                        'default'     => '',
                        'placeholder' => __( 'Placeholder text for this textarea', 'TouchPoint-WP' ),
                    ],
                    [
                        'id'          => 'single_checkbox',
                        'label'       => __( 'An Option', 'TouchPoint-WP' ),
                        'description' => __( 'A standard checkbox - if you save this option as checked then it will store the option as \'on\', otherwise it will be an empty string.', 'TouchPoint-WP' ),
                        'type'        => 'checkbox',
                        'default'     => '',
                    ],
                    [
                        'id'          => 'select_box',
                        'label'       => __( 'A Select Box', 'TouchPoint-WP' ),
                        'description' => __( 'A standard select box.', 'TouchPoint-WP' ),
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
                        'label'       => __( 'Some Options', 'TouchPoint-WP' ),
                        'description' => __( 'A standard set of radio buttons.', 'TouchPoint-WP' ),
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
                        'label'       => __( 'Some Items', 'TouchPoint-WP' ),
                        'description' => __( 'You can select multiple items and they will be stored as an array.', 'TouchPoint-WP' ),
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
                'title'       => __( 'Extra', 'TouchPoint-WP' ),
                'description' => __( "These are some extra input fields that maybe aren't as common as the others.", 'TouchPoint-WP' ),
                'fields'      => array(
                    array(
                        'id'          => 'number_field',
                        'label'       => __( 'A Number', 'TouchPoint-WP' ),
                        'description' => __( 'This is a standard number field - if this field contains anything other than numbers then the form will not be submitted.', 'TouchPoint-WP' ),
                        'type'        => 'number',
                        'default'     => '',
                        'placeholder' => __( '42', 'TouchPoint-WP' ),
                    ),
                    array(
                        'id'          => 'colour_picker',
                        'label'       => __( 'Pick a colour', 'TouchPoint-WP' ),
                        'description' => __( 'This uses WordPress\' built-in colour picker - the option is stored as the colour\'s hex code.', 'TouchPoint-WP' ),
                        'type'        => 'color',
                        'default'     => '#21759B',
                    ),
                    array(
                        'id'          => 'an_image',
                        'label'       => __( 'An Image', 'TouchPoint-WP' ),
                        'description' => __( 'This will upload an image to your media library and store the attachment ID in the option field. Once you have uploaded an image the thumbnail will display above these buttons.', 'TouchPoint-WP' ),
                        'type'        => 'image',
                        'default'     => '',
                        'placeholder' => '',
                    ),
                    array(
                        'id'          => 'multi_select_box',
                        'label'       => __( 'A Multi-Select Box', 'TouchPoint-WP' ),
                        'description' => __( 'A standard multi-select box - the saved data is stored as an array.', 'TouchPoint-WP' ),
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

        $settings = apply_filters($this->parent::TOKEN . '_Settings_fields', $settings);

        return $settings;
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
                'page_title'  => __('TouchPoint-WP', 'TouchPoint-WP'),
                'menu_title'  => __('TouchPoint-WP', 'TouchPoint-WP'),
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
    public function configure_settings($settings = [])
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
        wp_enqueue_media();

        // TODO this this out.  Most of this is not relevant.
        wp_register_script(
            $this->parent::TOKEN . '-settings-js',
            $this->parent->assets_url . 'js/settings' . $this->parent->script_suffix . '.js',
            ['jquery'],
            '1.0.0',
            true
        );
        wp_enqueue_script($this->parent::TOKEN . '-settings-js');
    }

    /**
     * Add settings link to plugin list table
     *
     * @param array $links Existing links.
     *
     * @return array        Modified links.
     */
    public function add_settings_link(array $links)
    {
        $settings_link = '<a href="options-general.php?page=' . $this->parent::TOKEN . '_Settings">' . __(
                'Settings',
                'TouchPoint-WP'
            ) . '</a>';
        array_push($links, $settings_link);

        return $links;
    }

    /**
     * @param string $what The field to get a value for
     *
     * @return false|mixed  The value, if set.  False if not set.
     */
    public function __get(string $what)
    {
        // TODO does not reflect defaults which have not yet been saved.
        return get_option(TouchPointWP::SETTINGS_PREFIX . $what, false);
    }

    /**
     * @param string $what
     * @param mixed  $value
     *
     * @return false|mixed
     */
    public function set(string $what, $value)
    {
        return (update_option(TouchPointWP::SETTINGS_PREFIX . $what, $value, true) ? $value : false);
    }

    /**
     * Register plugin settings
     *
     * @return void
     */
    public function register_settings()
    {
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
                    $validation = '';
                    if (isset($field['callback'])) {
                        $validation = $field['callback'];
                    }

                    // Register field.
                    $option_name = TouchPointWP::SETTINGS_PREFIX . $field['id'];
                    register_setting($this->parent::TOKEN . '_Settings', $option_name, $validation);

                    // Add field to page.
                    add_settings_field(
                        $field['id'],
                        $field['label'],
                        [$this->parent->admin, 'display_field'],
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
     * Settings section.
     *
     * @param array $section Array of section ids.
     *
     * @return void
     */
    public function settings_section(array $section)
    {
        $html = '<p> ' . $this->settings[$section['id']]['description'] . '</p>' . "\n";
        echo $html;
    }

    /**
     * Load settings page content.
     *
     * @return void
     */
    public function settings_page()
    {
        // Build page HTML.
        $html = '<div class="wrap" id="' . $this->parent::TOKEN . '_Settings">' . "\n";
        $html .= '<h2>' . __('TouchPoint-WP Settings', 'TouchPoint-WP') . '</h2>' . "\n";

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
                __('Save Settings', 'TouchPoint-WP')
            ) . '" />' . "\n";
        $html .= '</p>' . "\n";
        $html .= '</form>' . "\n";
        $html .= '</div>' . "\n";

        echo $html;
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