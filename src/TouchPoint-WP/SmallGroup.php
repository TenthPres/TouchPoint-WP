<?php

namespace tp\TouchPointWP;

/**
 * SmallGroup class file.
 *
 * Class SmallGroup
 * @package tp\TouchPointWP
 */


/**
 * The Small Group system class.
 */
abstract class SmallGroup
{
    public const SHORTCODE = TouchPointWP::SHORTCODE_PREFIX . "SG";
    public const POST_TYPE = TouchPointWP::HOOK_PREFIX . "smallgroup";

    private static bool $_isInitiated = false;
    protected static TouchPointWP $tpwp;

    public static function load(TouchPointWP $tpwp)
    {
        if (self::$_isInitiated) {
            return true;
        }

        self::$tpwp = $tpwp;

        self::$_isInitiated = true;

        add_action('init', [self::class, 'registerPostType']);

//        TODO on activation and deactivation, or change to the small group slug flush rewrite rules.

        return true;
    }

    public static function registerPostType()
    {
        register_post_type( self::POST_TYPE, [
            'labels' => [
                'name'          =>  self::$tpwp->settings->sg_name_plural,
                'singular_name' =>  self::$tpwp->settings->sg_name_singular
            ],
            'public'        =>  true,
            'hierarchical'  =>  false,
//            'show_ui'       =>  false,
            'show_ui'       =>  true,
            'show_in_rest'  =>  true,
//            'rest_controller_class' => TODO: this.
            'supports'      => [
                'title',
                'custom-fields'
            ],
            'has_archive' => true,
            'rewrite' => [
                'slug' => self::$tpwp->settings->sg_slug,
                'with_front' => false,
                'feeds' => false,
                'pages' => true
            ],
            'menu_icon' => "dashicons-groups",
            'query_var' => self::$tpwp->settings->sg_slug,
            'can_export' => false,
            'delete_with_user' => false
        ]);
        self::updateSmallGroupsFromTouchPoint();
    }



    public static function updateSmallGroupsFromTouchPoint()
    {
        $divs = implode(',', self::$tpwp->settings->sg_divisions);
        $divs = str_replace('div','', $divs);
        $response = self::$tpwp->apiGet("OrgsForDivs", ['divs' => $divs]);

        if ($response instanceof \WP_Error)
            return false;

        $orgData = json_decode($response['body'])->data->data;

        foreach ($orgData as $org) {
            set_time_limit(10);

            $q = new \WP_Query([
                'post_type' => self::POST_TYPE,
                'meta_key'    => TouchPointWP::SETTINGS_PREFIX . "OrgId",
                'meta_value'  => $org->organizationId
                               ]);
            $post = $q->get_posts();
            if (count($post) > 0) { // post exists already.
                $post = $post[0];
            } else {
                $post = wp_insert_post([ // create new
                    'post_type' => self::POST_TYPE,
                    'post_name' => $org->name,
                    'meta_input' => [
                        TouchPointWP::SETTINGS_PREFIX . "OrgId" => $org->organizationId
                    ]
                                       ]);
                $post = get_post($post);
            }

            // TODO check for $post being an instanceof WP_Error and report that something went wrong.

            $post->post_content = $org->description; // TODO filter out style and script tags (and others?)

            if ($post->post_title != $org->name) // only update if there's a change.  Otherwise, urls increment.
                $post->post_title = $org->name;

            $post->post_status = 'publish';

            wp_update_post($post);

        }

        return count($orgData);
    }


}