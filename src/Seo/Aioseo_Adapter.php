<?php
declare(strict_types=1);

namespace happyhappy\ImageSocialiser\Seo;

/**
 * Adapter for All in One SEO (4.x).
 *
 * Hooks the whole-array tag filters `aioseo_facebook_tags` and
 * `aioseo_twitter_tags` (verified against AIOSEO 4.9 source,
 * app/Common/Social/Output.php) and replaces the image keys. AIOSEO
 * runs `array_filter()` on the filtered array, so an empty
 * `og:image:secure_url` on non-SSL sites is dropped cleanly.
 *
 * Deliberately 4.x-only: `is_active()` requires AIOSEO >= 4.0. The
 * legacy 3.x line (`aiosp_opengraph_meta`) uses a different, here
 * unverifiable filter surface — 3.x installs keep the pre-existing
 * behavior (native mode blocked, no image), which is the status quo,
 * not a regression.
 *
 * @author	Simon Kraft
 * @license	GPL2
 * @package	happyhappy\ImageSocialiser
 */
final class Aioseo_Adapter implements Seo_Adapter {
	/**
	 * @inheritdoc
	 */
	public function get_id(): string {
		return 'aioseo';
	}
	
	/**
	 * @inheritdoc
	 */
	public function is_active(): bool {
		return \defined( 'AIOSEO_VERSION' )
			&& \version_compare( (string) \constant( 'AIOSEO_VERSION' ), '4.0', '>=' );
	}
	
	/**
	 * @inheritdoc
	 */
	public function register(): void {
		\add_filter( 'aioseo_facebook_tags', [ $this, 'filter_facebook_tags' ] );
		\add_filter( 'aioseo_twitter_tags', [ $this, 'filter_twitter_tags' ] );
	}
	
	/**
	 * Replace the og:image keys in AIOSEO's Facebook tag array.
	 *
	 * @param	mixed	$meta The tag array (`property => content`)
	 * @return	mixed The filtered tag array
	 */
	public function filter_facebook_tags( mixed $meta ): mixed {
		if ( ! \is_array( $meta ) ) {
			return $meta;
		}
		
		$image = Seo_Handler::resolve_current_image();
		
		if ( $image === null ) {
			return $meta;
		}
		
		$meta['og:image'] = $image['url'];
		$meta['og:image:secure_url'] = \is_ssl() ? $image['url'] : '';
		$meta['og:image:width'] = (string) $image['width'];
		$meta['og:image:height'] = (string) $image['height'];
		
		return $meta;
	}
	
	/**
	 * Replace the twitter:image key in AIOSEO's Twitter tag array.
	 *
	 * @param	mixed	$meta The tag array (`name => content`)
	 * @return	mixed The filtered tag array
	 */
	public function filter_twitter_tags( mixed $meta ): mixed {
		if ( ! \is_array( $meta ) ) {
			return $meta;
		}
		
		$image = Seo_Handler::resolve_current_image();
		
		if ( $image === null ) {
			return $meta;
		}
		
		$meta['twitter:image'] = $image['url'];
		
		return $meta;
	}
}
