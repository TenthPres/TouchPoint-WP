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
    }


}