<?php
declare(strict_types=1);

namespace happyhappy\ImageSocialiser\Generation;

use happyhappy\ImageSocialiser\Multisite\Multisite;

// prevent direct file access
if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the post types that get generated Open Graph images.
 *
 * All public post types are supported by default, gated behind a
 * filter — works out of the box, stays controllable.
 *
 * @author	Simon Kraft
 * @license	GPL2
 * @package	happyhappy\ImageSocialiser
 */
final class Post_Types {
	/**
	 * @var	string Option name for the enabled post types.
	 */
	public const string OPTION_NAME = 'image_socialiser_post_types';
	
	/**
	 * Get the supported post types.
	 *
	 * All public post types (except attachments) are supported until
	 * the option narrows the selection in the settings.
	 *
	 * @return	string[] The supported post type names
	 */
	public static function get_supported(): array {
		$post_types = \get_post_types( [ 'public' => true ] );
		unset( $post_types['attachment'] );
		$enabled = \get_option( self::OPTION_NAME, null );
		$resolution = Multisite::resolve_section(
			'post_types',
			\is_array( $enabled ) ? $enabled : null,
			\is_array( $enabled )
		);
		$enabled = $resolution['value'];
		
		if ( \is_array( $enabled ) ) {
			$post_types = \array_intersect( $post_types, $enabled );
		}
		
		/**
		 * Filter the post types that get generated Open Graph images.
		 *
		 * @param	string[]	$post_types The supported post type names
		 */
		$post_types = (array) \apply_filters(
			'image_socialiser_supported_post_types',
			\array_values( $post_types )
		);
		
		return \array_values( \array_filter( $post_types, '\is_string' ) );
	}
	
	/**
	 * Check whether a post type is supported.
	 *
	 * @param	string	$post_type The post type name
	 * @return	bool Whether the post type is supported
	 */
	public static function is_supported( string $post_type ): bool {
		return \in_array( $post_type, self::get_supported(), true );
	}
}
