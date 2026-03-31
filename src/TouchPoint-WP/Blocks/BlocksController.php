<?php

/**
 * @package TouchPointWP
 */
namespace tp\TouchPointWP\Blocks;

use tp\TouchPointWP\Interfaces\api;
use tp\TouchPointWP\TouchPointWP;

abstract class BlocksController implements api
{

	/**
	 * Registers the block using a `blocks-manifest.php` file, which improves the performance of block type registration.
	 * Behind the scenes, it also registers all assets so they can be enqueued
	 * through the block editor in the corresponding context.
	 *
	 * @see https://make.wordpress.org/core/2025/03/13/more-efficient-block-type-registration-in-6-8/
	 * @see https://make.wordpress.org/core/2024/10/17/new-block-type-registration-apis-to-improve-performance-in-wordpress-6-7/
	 */
	public static function init(): void
	{
		$blocksRoot = TouchPointWP::$dir . '/blocks/';

		// Hook the enqueue function
		add_action('enqueue_block_editor_assets', [BlocksController::class, 'enqueueBlockAssets']);

		/**
		 * Registers the block(s) metadata from the `blocks-manifest.php` and registers the block type(s)
		 * based on the registered block metadata.
		 * Added in WordPress 6.8 to simplify the block metadata registration process added in WordPress 6.7.
		 *
		 * @see https://make.wordpress.org/core/2025/03/13/more-efficient-block-type-registration-in-6-8/
		 */
//		if (function_exists('wp_register_block_types_from_metadata_collection')) {  TODO re-enable when it doesn't cause warnings in the logs.
//			wp_register_block_types_from_metadata_collection($blocksRoot, $blocksRoot . 'blocks-manifest.php');
//			return;
//		}

		/**
		 * Registers the block(s) metadata from the `blocks-manifest.php` file.
		 * Added to WordPress 6.7 to improve the performance of block type registration.
		 *
		 * @see https://make.wordpress.org/core/2024/10/17/new-block-type-registration-apis-to-improve-performance-in-wordpress-6-7/
		 */
		if (function_exists('wp_register_block_metadata_collection')) {
			wp_register_block_metadata_collection($blocksRoot, $blocksRoot . 'blocks-manifest.php');
		}

		/**
		 * Registers the block type(s) in the `blocks-manifest.php` file.
		 *
		 * @see https://developer.wordpress.org/reference/functions/register_block_type/
		 */
		$manifest_data = require $blocksRoot . '/blocks-manifest.php';
		foreach ($manifest_data as $block_type) {
			register_block_type($block_type['name'], $block_type);
		}
	}

	public static function enqueueBlockAssets(): void
	{
		$blocksRoot = TouchPointWP::$dir . '/blocks/';
		$manifestPath = $blocksRoot . 'blocks-manifest.php';

		if (!file_exists($manifestPath)) {
			return;
		}

		$manifest_data = require $manifestPath;

		foreach ($manifest_data as $block_name => $block_metadata) {
			$block_dir = $blocksRoot . $block_name;

			if (isset($block_metadata['editorScript'])) {
				$fileName = substr($block_metadata['editorScript'], 7);
				wp_enqueue_script(
					"{$block_name}-editor-script",
					plugins_url("$block_name/$fileName", $block_dir),
					['wp-blocks', 'wp-element', 'wp-editor'],
					TouchPointWP::VERSION
				);
			}

			if (isset($block_metadata['editorStyle'])) {
				$fileName = substr($block_metadata['editorStyle'], 7);
				wp_enqueue_style(
					"{$block_name}-editor-style",
					plugins_url("$block_name/$fileName", $block_dir),
					[],
					TouchPointWP::VERSION
				);
			}

			if (isset($block_metadata['style'])) {
				$fileName = substr($block_metadata['style'], 7);
				wp_enqueue_style(
					"{$block_name}-style",
					plugins_url("$block_name/$fileName", $block_dir),
					[],
					TouchPointWP::VERSION
				);
			}

			if (isset($block_metadata['viewScript'])) {
				$fileName = substr($block_metadata['viewScript'], 7);
				wp_enqueue_script(
					"{$block_name}-view-script",
					plugins_url("$block_name/$fileName", $block_dir),
					[],
					TouchPointWP::VERSION
				);
			}
		}
	}


	/**
	 * Handle API requests
	 *
	 * @param array $uri The request URI already parsed by parse_url()
	 *
	 * @return bool False if endpoint is not found.  Should print the result.
	 *
	 * @since 0.0.97 Added
	 */
	public static function api(array $uri): bool
	{
		if (count($uri['path']) < 3) {
			return false;
		}

		switch (strtolower($uri['path'][2])) {
			case "block-editor-style":
				header('Content-Type: text/css');
				header('Cache-Control: max-age=3600, public');

				$dir = TouchPointWP::instance()->assets_dir;

				// open files and print
				echo file_get_contents($dir . '/template/block-preview-style.css') . "\n\n";
				if (TouchPointWP::includeActionsStyle("block-preview")) {
					echo file_get_contents($dir . '/template/actions-style.css') . "\n\n";
				}
				if (TouchPointWP::includePartialsStyle("block-preview")) {
					echo file_get_contents($dir . '/template/partials-template-style.css') . "\n\n";
				}

				exit;
		}

		return false;
	}
}

