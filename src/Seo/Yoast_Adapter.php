<?php
declare(strict_types=1);

namespace happyhappy\ImageSocialiser\Seo;

// prevent direct file access
if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adapter for Yoast SEO.
 *
 * `wpseo_opengraph_image` only replaces an existing image and does
 * nothing when none is set, so this adapter hooks
 * `wpseo_add_opengraph_images` instead: per the Yoast source, that
 * filter runs before Yoast's own image sources, its image container
 * preserves arbitrary array keys, and the front-end presenter prints
 * any provided width/height as og:image:* meta. Our image is therefore
 * guaranteed and listed first (crawlers use the first og:image); a
 * Yoast-side user-set image may still be output as an additional tag.
 *
 * @author	Simon Kraft
 * @license	GPL2
 * @package	happyhappy\ImageSocialiser
 */
final class Yoast_Adapter implements Seo_Adapter {
	/**
	 * @inheritdoc
	 */
	public function get_id(): string {
		return 'yoast';
	}
	
	/**
	 * @inheritdoc
	 */
	public function is_active(): bool {
		return \defined( 'WPSEO_VERSION' );
	}
	
	/**
	 * @inheritdoc
	 */
	public function register(): void {
		\add_filter( 'wpseo_add_opengraph_images', [ $this, 'add_image' ] );
	}
	
	/**
	 * Add our resolved image to Yoast's image container.
	 *
	 * @param	mixed	$image_container The Yoast Open Graph images container
	 * @return	mixed The unchanged container
	 */
	public function add_image( mixed $image_container ): mixed {
		if ( ! \is_object( $image_container ) || ! \method_exists( $image_container, 'add_image' ) ) {
			return $image_container;
		}
		
		$image = Seo_Handler::resolve_current_image();
		
		if ( $image !== null ) {
			$image_container->add_image( [
				'height' => $image['height'],
				'type' => $image['type'],
				'url' => $image['url'],
				'width' => $image['width'],
			] );
		}
		
		return $image_container;
	}
	
	/**
	 * @inheritdoc
	 *
	 * Yoast stores the per-post Open Graph image as
	 * `_yoast_wpseo_opengraph-image-id` / `_yoast_wpseo_opengraph-image`.
	 */
	public function has_manual_image( int $post_id ): bool {
		return (int) \get_post_meta( $post_id, '_yoast_wpseo_opengraph-image-id', true ) > 0
			|| (string) \get_post_meta( $post_id, '_yoast_wpseo_opengraph-image', true ) !== '';
	}
}
