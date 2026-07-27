<?php
declare(strict_types=1);

namespace happyhappy\ImageSocialiser\Seo;

use happyhappy\ImageSocialiser\Generation\Post_Types;
use happyhappy\ImageSocialiser\Generation\Resolver;
use WP_Post;

/**
 * oEmbed integration: the resolved image becomes the embed thumbnail.
 *
 * Covers the WordPress oEmbed provider endpoint
 * (/wp-json/oembed/1.0/embed) and internal post embeds. Runs at a
 * late priority to override whatever thumbnail WordPress determined.
 * Post-only by design — non-singular subjects have no oEmbed
 * representation.
 *
 * Note: embed consumers cache oEmbed responses; a regenerated image
 * appears only when the consumer re-fetches.
 *
 * @author	Simon Kraft
 * @license	GPL2
 * @package	happyhappy\ImageSocialiser
 */
final class OEmbed_Integration {
	/**
	 * Initialize the integration.
	 */
	public static function init(): void {
		\add_filter( 'oembed_response_data', [ self::class, 'filter_response_data' ], 9999, 2 );
	}
	
	/**
	 * Set the resolved image as the oEmbed thumbnail.
	 *
	 * @param	mixed	$data The oEmbed response data
	 * @param	mixed	$post The post being embedded
	 * @return	mixed The filtered response data
	 */
	public static function filter_response_data( mixed $data, mixed $post ): mixed {
		if ( ! \is_array( $data ) || ! $post instanceof WP_Post || ! Post_Types::is_supported( $post->post_type ) ) {
			return $data;
		}
		
		/**
		 * Filter whether the generated image is used as the oEmbed
		 * thumbnail.
		 *
		 * @param	bool	$enabled Whether to override the thumbnail
		 * @param	\WP_Post	$post The post being embedded
		 */
		if ( ! \apply_filters( 'image_socialiser_oembed_thumbnail', true, $post ) ) {
			return $data;
		}
		
		$image = ( new Resolver() )->resolve( $post );
		
		if ( $image === null ) {
			return $data;
		}
		
		$data['thumbnail_url'] = $image['url'];
		$data['thumbnail_width'] = $image['width'];
		$data['thumbnail_height'] = $image['height'];
		
		return $data;
	}
}
