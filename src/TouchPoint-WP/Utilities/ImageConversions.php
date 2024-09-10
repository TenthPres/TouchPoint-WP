<?php
/**
 * @package TouchPointWP
 */

namespace tp\TouchPointWP\Utilities;

use DOMDocument;
use tp\TouchPointWP\TouchPointWP_Exception;

/**
 * Utility class for Image Conversions
 */
abstract class ImageConversions
{
	/**
	 * @throws \ImagickException
	 * @throws TouchPointWP_Exception
	 */
	public static function svgToPng($svgContent): string
	{
		$svg = new DOMDocument();
		$svg->loadXML($svgContent);

		// won't work without xmlns.  This makes sure it's present.
		$svg->documentElement->setAttribute('xmlns', 'http://www.w3.org/2000/svg');

		// Test if Imagick is available
		if (!extension_loaded('imagick')) {
			throw new TouchPointWP_Exception('Imagick extension is not available');
		}

		if (count(\Imagick::queryformats('SVG')) < 1) {
			throw new TouchPointWP_Exception('Imagick on this server does not support SVG');
		}

		$im = new \Imagick();
		$im->setResolution(300, 300);
		$im->setBackgroundColor(new \ImagickPixel('transparent'));
		$im->readImageBlob($svg->saveXML());

		$im->setImageAlphaChannel(\Imagick::ALPHACHANNEL_ACTIVATE);

		$im = $im->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);

		$im->setImageFormat('png');

		return $im->getImageBlob();
	}
}