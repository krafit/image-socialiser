<?php
declare(strict_types=1);

namespace happyhappy\ImageSocialiser\Seo;

/**
 * Native mode: prints the meta tags directly on wp_head.
 *
 * Only active when no known SEO plugin is present, guarding against
 * duplicate og:image output — including from SEO plugins this plugin
 * has no dedicated adapter for. Tags are printed at an early priority
 * so they land within the first kilobytes crawlers read.
 *
 * @author	Simon Kraft
 * @license	GPL2
 * @package	happyhappy\ImageSocialiser
 */
final class Native_Adapter implements Seo_Adapter {
	/**
	 * @inheritdoc
	 */
	public function get_id(): string {
		return 'native';
	}
	
	/**
	 * @inheritdoc
	 */
	public function is_active(): bool {
		/**
		 * Filter the constants that indicate an SEO plugin handling
		 * og:image output itself, which disables native mode.
		 *
		 * @param	string[]	$constants The blocking constant names
		 */
		// AIOSEO_VERSION and SEOPRESS_VERSION stay in this list even
		// though dedicated adapters exist (0.14.0): if someone filters
		// an adapter out via image_socialiser_seo_adapters, native mode
		// must still not double-print alongside that plugin
		$constants = (array) \apply_filters(
			'image_socialiser_native_mode_blockers',
			[
				'AIOSEO_VERSION',
				'RANK_MATH_VERSION',
				'SEOPRESS_VERSION',
				'THE_SEO_FRAMEWORK_VERSION',
				'WPSEO_VERSION',
			]
		);
		
		foreach ( $constants as $constant ) {
			if ( \defined( (string) $constant ) ) {
				return false;
			}
		}
		
		return true;
	}
	
	/**
	 * @inheritdoc
	 */
	public function register(): void {
		\add_action( 'wp_head', [ $this, 'print_tags' ], 5 );
	}
	
	/**
	 * Print the og:image and twitter:image meta tags.
	 */
	public function print_tags(): void {
		/**
		 * Filter whether the native adapter prints its meta tags on
		 * the current view. Defaults to false on views another plugin
		 * covers with its own Open Graph block (e.g. Podlove episode
		 * pages), preventing duplicate og:image tags.
		 *
		 * @param	bool	$print Whether to print the native meta tags
		 */
		$print = (bool) \apply_filters(
			'image_socialiser_print_native_tags',
			! Podlove_Integration::is_covering_current_view()
		);
		
		if ( ! $print ) {
			return;
		}
		
		$image = Seo_Handler::resolve_current_image();
		
		if ( $image === null ) {
			return;
		}
		
		\printf(
			'<meta property="og:image" content="%s">' . \PHP_EOL,
			\esc_url( $image['url'] )
		);
		\printf(
			'<meta property="og:image:width" content="%d">' . \PHP_EOL,
			(int) $image['width']
		);
		\printf(
			'<meta property="og:image:height" content="%d">' . \PHP_EOL,
			(int) $image['height']
		);
		\printf(
			'<meta property="og:image:type" content="%s">' . \PHP_EOL,
			\esc_attr( $image['type'] )
		);
		\printf(
			'<meta name="twitter:image" content="%s">' . \PHP_EOL,
			\esc_url( $image['url'] )
		);
	}
}
