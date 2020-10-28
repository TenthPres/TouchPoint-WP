<?php
namespace tp\TouchPointWP;

/**
* Component class file.
*
* Class Component
* @package tp\TouchPointWP
*/

if ( ! defined('ABSPATH')) {
    exit(1);
}

/**
* The Auth-handling class.
*/
abstract class Component {

    abstract public static function registerScriptsAndStyles();

}