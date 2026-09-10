<?php
declare(strict_types=1);

namespace happyhappy\ImageSocialiser\Seo;

// prevent direct file access
if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adapter for Rank Math.
 *
 * Hooks `rank_math/opengraph/{network}/image` for both networks: the
 * filter runs on every image Rank Math is about to add — and, per its
 * source, once more with an empty URL when it found none — so
 * returning our URL guarantees exactly one og:image (Rank Math
 * de-duplicates by URL) even on posts without any image. Images
 * provided through this filter skip Rank Math's URL validation, and
 * the follow-up `image_array` filter carries our explicit
 * width/height/type, which Rank Math prints as og:image:* meta.
 *
 * @author	Simon Kraft
 * @license	GPL2
 * @package	happyhappy\ImageSocialiser
 */
final class Rank_Math_Adapter implements Seo_Adapter {
	/**
	 * @inheritdoc
	 */
	public function get_id(): string {
		return 'rank-math';
	}
	
	/**
	 * @inheritdoc
	 */
	public function is_active(): bool {
		return \defined( 'RANK_MATH_VERSION' );
	}
	
	/**
	 * @inheritdoc
	 */
	public function register(): void {
		foreach ( [ 'facebook', 'twitter' ] as $network ) {
			\add_filter( 'rank_math/opengraph/' . $network . '/image', [ $this, 'filter_image_url' ] );
			\add_filter(
				'rank_math/opengraph/' . $network . '/image_array',
				[ $this, 'filter_image_array' ]
			);
		}
	}
	
	/**
	 * Add dimensions and type to our image's attachment array.
	 *
	 * @param	mixed	$attachment The image data Rank Math is about to add
	 * @return	mixed The filtered image data
	 */
	public function filter_image_array( mixed $attachment ): mixed {
		if ( ! \is_array( $attachment ) || empty( $attachment['url'] ) ) {
			return $attachment;
		}
		
		$image = Seo_Handler::resolve_current_image();
		
		if ( $image === null || $attachment['url'] !== $image['url'] ) {
			return $attachment;
		}
		
		$attachment['height'] = $image['height'];
		$attachment['type'] = $image['type'];
		$attachment['width'] = $image['width'];
		
		return $attachment;
	}
	
	/**
	 * Replace the image URL with our resolved image.
	 *
	 * @param	mixed	$url The image URL Rank Math is about to add
	 * @return	string The filtered image URL
	 */
	public function filter_image_url( mixed $url ): string {
		$image = Seo_Handler::resolve_current_image();
		
		return $image['url'] ?? (string) $url;
	}
	
	/**
	 * @inheritdoc
	 *
	 * Rank Math stores the per-post Facebook image as
	 * `rank_math_facebook_image_id` / `rank_math_facebook_image`.
	 */
	public function has_manual_image( int $post_id ): bool {
		return (int) \get_post_meta( $post_id, 'rank_math_facebook_image_id', true ) > 0
			|| (string) \get_post_meta( $post_id, 'rank_math_facebook_image', true ) !== '';
	}
}
