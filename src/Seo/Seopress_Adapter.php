<?php
declare(strict_types=1);

namespace happyhappy\ImageSocialiser\Seo;

// prevent direct file access
if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adapter for SEOPress.
 *
 * Hooks `seopress_social_og_thumb` and
 * `seopress_social_twitter_card_thumb`. The return shape is subtle
 * and dictated by SEOPress's two code paths (verified against the
 * wp-seopress-public master source):
 *
 * - The legacy output path (inc/functions/options-social.php) builds
 *   the filtered value as a full HTML meta-tag string and echoes it
 *   verbatim — a bare URL would print broken markup.
 * - The modern spec pipeline (src/Services/Metas/…) filters a bare
 *   URL, then regex-extracts `content="…"` from the result precisely
 *   to stay compatible with HTML-returning callbacks.
 *
 * Returning meta-tag HTML therefore works in both: the legacy path
 * echoes it (carrying our explicit og:image:width/height), the modern
 * path extracts the first `content` attribute — the og:image URL.
 *
 * @author	Simon Kraft
 * @license	GPL2
 * @package	happyhappy\ImageSocialiser
 */
final class Seopress_Adapter implements Seo_Adapter {
	/**
	 * @var	bool Whether SEOPress reached our og:image filter this request.
	 */
	private bool $og_filter_ran = false;
	
	/**
	 * @var	bool Whether SEOPress reached our twitter:image filter this request.
	 */
	private bool $twitter_filter_ran = false;
	/**
	 * @inheritdoc
	 */
	public function get_id(): string {
		return 'seopress';
	}
	
	/**
	 * @inheritdoc
	 */
	public function is_active(): bool {
		return \defined( 'SEOPRESS_VERSION' );
	}
	
	/**
	 * @inheritdoc
	 */
	public function register(): void {
		\add_filter( 'seopress_social_og_thumb', [ $this, 'filter_og_thumb' ] );
		\add_filter( 'seopress_social_twitter_card_thumb', [ $this, 'filter_twitter_thumb' ] );
		\add_action( 'wp_head', [ $this, 'maybe_emit_fallback' ], 99 );
	}
	
	/**
	 * Emit our tags when SEOPress never reached the image filters.
	 *
	 * SEOPress's modern pipeline (SocialFacebookMeta::getMetasForPost())
	 * walks its image specifications, skips every one whose
	 * isSatisfyBy() is false, and only fires `seopress_social_og_thumb`
	 * from inside a specification that matched. Its last-resort
	 * specification requires a site icon. On a post with no SEOPress
	 * image and no featured image, on a site without a site icon,
	 * nothing matches, our filter never runs, and no og:image is
	 * printed at all — even though a generated image exists.
	 *
	 * This runs late on wp_head and covers exactly that gap: it emits
	 * nothing when either filter did run (SEOPress handled it, or we
	 * deliberately stepped aside for its own per-post image), and
	 * nothing when the site has switched SEOPress's Open Graph output
	 * off, since that is a deliberate choice to respect.
	 */
	public function maybe_emit_fallback(): void {
		if ( $this->og_filter_ran && $this->twitter_filter_ran ) {
			return;
		}
		
		if ( ! $this->is_open_graph_enabled() ) {
			return;
		}
		
		$image = Seo_Handler::resolve_current_image();
		
		if ( $image === null ) {
			return;
		}
		
		if ( ! $this->og_filter_ran ) {
			// filter_og_thumb() returns markup because SEOPress echoes
			// the filtered value verbatim on its legacy path; here we
			// are printing into wp_head ourselves, so it is the same
			// markup either way
			echo \wp_kses(
				$this->build_og_tags( $image ),
				[
					'meta' => [
						'content' => true,
						'property' => true,
					],
				]
			);
		}
		
		if ( ! $this->twitter_filter_ran ) {
			\printf(
				'<meta name="twitter:image" content="%s">' . "\n",
				\esc_url( $image['url'] )
			);
		}
	}
	
	/**
	 * Check whether SEOPress is configured to output Open Graph tags.
	 *
	 * @return	bool Whether Open Graph output is enabled
	 */
	private function is_open_graph_enabled(): bool {
		if ( ! \function_exists( 'seopress_get_service' ) ) {
			return false;
		}
		
		$option = \seopress_get_service( 'SocialOption' );
		
		if ( ! \is_object( $option ) || ! \method_exists( $option, 'getSocialFacebookOGEnable' ) ) {
			return false;
		}
		
		return $option->getSocialFacebookOGEnable() === '1';
	}
	
	/**
	 * Build the og:image tag block for an image.
	 *
	 * @param	array{height: int, type: string, url: string, width: int}	$image The image data
	 * @return	string The meta tag markup
	 */
	private function build_og_tags( array $image ): string {
		$html = \sprintf( '<meta property="og:image" content="%s">', \esc_url( $image['url'] ) ) . \PHP_EOL;
		
		if ( \is_ssl() ) {
			$html .= \sprintf(
				'<meta property="og:image:secure_url" content="%s">',
				\esc_url( $image['url'] )
			) . \PHP_EOL;
		}
		
		$html .= \sprintf( '<meta property="og:image:width" content="%d">', (int) $image['width'] ) . \PHP_EOL;
		$html .= \sprintf( '<meta property="og:image:height" content="%d">', (int) $image['height'] ) . \PHP_EOL;
		
		return $html;
	}
	
	/**
	 * Replace the og:image block SEOPress is about to output.
	 *
	 * @param	mixed	$value The meta-tag HTML (legacy path) or bare URL (modern path)
	 * @return	mixed The filtered value
	 */
	public function filter_og_thumb( mixed $value ): mixed {
		// recorded before the early return: the safety net below only
		// fires when SEOPress never got here at all, not when it got
		// here and we chose to leave its value alone
		$this->og_filter_ran = true;
		$image = Seo_Handler::resolve_current_image();
		
		if ( $image === null ) {
			return $value;
		}
		
		return $this->build_og_tags( $image );
	}
	
	/**
	 * Replace the twitter:image tag SEOPress is about to output.
	 *
	 * @param	mixed	$value The meta-tag HTML (legacy path) or bare URL (modern path)
	 * @return	mixed The filtered value
	 */
	public function filter_twitter_thumb( mixed $value ): mixed {
		$this->twitter_filter_ran = true;
		$image = Seo_Handler::resolve_current_image();
		
		if ( $image === null ) {
			return $value;
		}
		
		return \sprintf( '<meta name="twitter:image" content="%s">', \esc_url( $image['url'] ) );
	}
	
	/**
	 * @inheritdoc
	 *
	 * SEOPress stores the per-post image as
	 * `_seopress_social_fb_img_attachment_id` / `_seopress_social_fb_img`.
	 */
	public function has_manual_image( int $post_id ): bool {
		return (int) \get_post_meta( $post_id, '_seopress_social_fb_img_attachment_id', true ) > 0
			|| (string) \get_post_meta( $post_id, '_seopress_social_fb_img', true ) !== '';
	}
}
