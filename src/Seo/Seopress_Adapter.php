<?php
declare(strict_types=1);

namespace happyhappy\ImageSocialiser\Seo;

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
	}
	
	/**
	 * Replace the og:image block SEOPress is about to output.
	 *
	 * @param	mixed	$value The meta-tag HTML (legacy path) or bare URL (modern path)
	 * @return	mixed The filtered value
	 */
	public function filter_og_thumb( mixed $value ): mixed {
		$image = Seo_Handler::resolve_current_image();
		
		if ( $image === null ) {
			return $value;
		}
		
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
	 * Replace the twitter:image tag SEOPress is about to output.
	 *
	 * @param	mixed	$value The meta-tag HTML (legacy path) or bare URL (modern path)
	 * @return	mixed The filtered value
	 */
	public function filter_twitter_thumb( mixed $value ): mixed {
		$image = Seo_Handler::resolve_current_image();
		
		if ( $image === null ) {
			return $value;
		}
		
		return \sprintf( '<meta name="twitter:image" content="%s">', \esc_url( $image['url'] ) );
	}
}
