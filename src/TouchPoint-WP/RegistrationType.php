<?php


/**
 * @package TouchPointWP
 */

namespace tp\TouchPointWP;

if ( ! defined('ABSPATH')) {
	exit(1);
}

/**
 * an enum.
 *
 * TODO PHP 8.1: convert to enum
 */
abstract class RegistrationType
{
	const CLOSED = 0;
	const JOIN = 1;
	const FORM = 2;
	const RSVP = 3;
	const EXTERNAL = 9;
}