<?php
/**
 * @package TouchPointWP
 */

namespace tp\TouchPointWP;

/**
 * This is a base interface for all feature classes.
 */
interface module
{
	/**
	 * Loads the module and initializes the other actions.
	 *
	 * @return bool
	 */
	public static function load(): bool;

	public const TEMPLATES_TO_OVERWRITE = [
		'archive.php',
		'archive.html',
		'singular.php',
		'singular.html',
		'single.php',
		'single.html',
		'index.php',
		'template-canvas.php'
	];
}