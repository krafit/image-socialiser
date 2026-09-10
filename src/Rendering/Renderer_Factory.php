<?php
declare(strict_types=1);

namespace happyhappy\ImageSocialiser\Rendering;

// prevent direct file access
if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Selects the best available renderer at runtime.
 *
 * @author	Simon Kraft
 * @license	GPL2
 * @package	happyhappy\ImageSocialiser
 */
final class Renderer_Factory {
	/**
	 * Create the first available renderer.
	 *
	 * Imagick is the primary renderer; additional (fallback) renderers
	 * can be registered via the filter below. Returns null if no
	 * renderer is available in the current environment.
	 *
	 * @return	\happyhappy\ImageSocialiser\Rendering\Renderer|null The renderer or null
	 */
	public static function create(): ?Renderer {
		/**
		 * Filter the list of renderers, ordered by priority.
		 *
		 * @param	\happyhappy\ImageSocialiser\Rendering\Renderer[]	$renderers List of renderer instances
		 */
		$renderers = (array) \apply_filters(
			'image_socialiser_renderers',
			[
				new Imagick_Renderer(),
				new GD_Renderer(),
			]
		);
		
		foreach ( $renderers as $renderer ) {
			if ( ! $renderer instanceof Renderer ) {
				continue;
			}
			
			if ( $renderer->is_available() ) {
				return $renderer;
			}
		}
		
		return null;
	}
}
