<?php
declare(strict_types=1);

namespace happyhappy\ImageSocialiser\Rendering;

use Exception;

// prevent direct file access
if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exception thrown when rendering an image fails.
 *
 * @author	Simon Kraft
 * @license	GPL2
 * @package	happyhappy\ImageSocialiser
 */
final class Rendering_Exception extends Exception {
}
