<?php
declare(strict_types=1);

namespace happyhappy\ImageSocialiser\Seo;

/**
 * Integration with Podlove Podcast Publisher.
 *
 * Podlove's "Open Graph Integration" module prints its own og:image
 * on single episode views (and only disables itself for Yoast and
 * AIOSEO), which would duplicate our output. This integration replaces
 * the image inside Podlove's Open Graph block via its
 * `podlove_ogp_image_data` filter — so episodes carry exactly one
 * og:image, ours, while Podlove's og:audio metadata stays intact —
 * and the native adapter yields on views Podlove covers.
 *
 * Because Podlove caches its Open Graph block, its template cache is
 * purged whenever we generate a new image for an episode.
 *
 * @author	Simon Kraft
 * @license	GPL2
 * @package	happyhappy\ImageSocialiser
 */
final class Podlove_Integration {
	/**
	 * @var	string Podlove's Open Graph module class.
	 */
	private const string OPEN_GRAPH_MODULE = '\Podlove\Modules\OpenGraph\Open_Graph';
	
	/**
	 * @var	string Podlove's template cache class.
	 */
	private const string TEMPLATE_CACHE = '\Podlove\Cache\TemplateCache';
	
	/**
	 * Initialize the integration.
	 */
	public static function init(): void {
		\add_action( 'plugins_loaded', [ self::class, 'register' ], 20 );
	}
	
	/**
	 * Replace the image in Podlove's Open Graph block with ours.
	 *
	 * @param	mixed	$data Podlove's og:image element data (property, content)
	 * @return	mixed The filtered element data
	 */
	public static function filter_image_data( mixed $data ): mixed {
		if ( ! \is_array( $data ) ) {
			return $data;
		}
		
		$image = Seo_Handler::resolve_current_image();
		
		if ( $image !== null ) {
			$data['content'] = $image['url'];
		}
		
		return $data;
	}
	
	/**
	 * Check whether Podlove's Open Graph module covers the current view.
	 *
	 * Mirrors Podlove's own guard: single views of its episode post
	 * type with the module enabled.
	 *
	 * @return	bool Whether Podlove prints the Open Graph block here
	 */
	public static function is_covering_current_view(): bool {
		if ( ! self::is_module_active() ) {
			return false;
		}
		
		return \is_single() && \get_post_type() === 'podcast';
	}
	
	/**
	 * Purge Podlove's template cache after an episode image changed.
	 *
	 * Podlove caches its Open Graph block, which would otherwise keep
	 * pointing at a deleted image file after regeneration.
	 *
	 * @param	int	$post_id The post the image was generated for
	 */
	public static function purge_cache( int $post_id ): void {
		if ( ! self::is_module_active() || \get_post_type( $post_id ) !== 'podcast' ) {
			return;
		}
		
		$cache_class = \ltrim( self::TEMPLATE_CACHE, '\\' );
		
		if ( ! \class_exists( $cache_class ) || ! \method_exists( $cache_class, 'get_instance' ) ) {
			return;
		}
		
		$cache = $cache_class::get_instance();
		
		if ( \method_exists( $cache, 'setup_purge' ) ) {
			$cache->setup_purge();
		}
		else if ( \method_exists( $cache, 'purge' ) ) {
			$cache->purge();
		}
	}
	
	/**
	 * Register the integration hooks when Podlove's module is active.
	 */
	public static function register(): void {
		if ( ! self::is_module_active() ) {
			return;
		}
		
		\add_filter( 'podlove_ogp_image_data', [ self::class, 'filter_image_data' ] );
		\add_action( 'image_socialiser_generated', [ self::class, 'purge_cache' ] );
	}
	
	/**
	 * Check whether Podlove's Open Graph module is loaded.
	 *
	 * The module class only exists when the module is enabled.
	 *
	 * @return	bool Whether the module is active
	 */
	private static function is_module_active(): bool {
		return \class_exists( \ltrim( self::OPEN_GRAPH_MODULE, '\\' ) );
	}
}
