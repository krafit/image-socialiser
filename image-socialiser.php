<?php
declare(strict_types=1);

namespace happyhappy\ImageSocialiser;

/**
 * Plugin Name:	Image Socialiser
 * Plugin URI:	https://simon.blog/
 * Description:	Generates branded Open Graph images per post from a site-wide, editable template.
 * Version:	1.0.0-beta.1
 * Author:	Simon Kraft
 * Author URI:	https://simon.blog/
 * License:	GPL2
 * License URI:	https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:	image-socialiser
 * Domain Path:	/languages
 * Requires PHP:	8.3
 * Requires at least:	6.6
 *
 * @package	happyhappy\ImageSocialiser
 */

// prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'IMAGE_SOCIALISER_FILE', __FILE__ );
define( 'IMAGE_SOCIALISER_VERSION', '1.0.0-beta.1' );

// Action Scheduler registers itself and must be loaded on plugin inclusion
if ( file_exists( __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php' ) ) {
	require_once __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';
}

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}
else {
	spl_autoload_register( static function( string $class_name ): void {
		$prefix = 'happyhappy\\ImageSocialiser\\';
		
		if ( strpos( $class_name, $prefix ) !== 0 ) {
			return;
		}
		
		$relative_class = substr( $class_name, strlen( $prefix ) );
		$path = __DIR__ . '/src/' . str_replace( '\\', '/', $relative_class ) . '.php';
		
		if ( file_exists( $path ) ) {
			require_once $path;
		}
	} );
}

require_once __DIR__ . '/src/functions.php';

$image_socialiser = Plugin::get_instance();
$image_socialiser->plugin_file = IMAGE_SOCIALISER_FILE;
$image_socialiser->init();
