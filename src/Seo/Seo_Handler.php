<?php
declare(strict_types=1);

namespace happyhappy\ImageSocialiser\Seo;

use happyhappy\ImageSocialiser\Generation\Post_Types;
use happyhappy\ImageSocialiser\Generation\Resolver;
use happyhappy\ImageSocialiser\Generation\Subject;
use WP_Post;

// prevent direct file access
if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects the active SEO plugin and registers the matching adapter.
 *
 * Adapters are checked in order; the first active one wins, so only a
 * single integration emits our image. Additional adapters (Rank Math,
 * Yoast, native wp_head mode) will be added in later stages and can be
 * provided via the filter below in the meantime.
 *
 * @author	Simon Kraft
 * @license	GPL2
 * @package	happyhappy\ImageSocialiser
 */
final class Seo_Handler {
	/**
	 * @var	\happyhappy\ImageSocialiser\Seo\Seo_Adapter|null The registered adapter.
	 */
	private static ?Seo_Adapter $active_adapter = null;
	
	/**
	 * @var	array<int, array|null> Resolved images per post ID (request memo).
	 */
	private static array $resolved = [];
	
	/**
	 * Initialize the handler.
	 */
	public static function init(): void {
		\add_action( 'plugins_loaded', [ self::class, 'register_adapter' ], 20 );
	}
	
	/**
	 * Get the registered adapter.
	 *
	 * @return	\happyhappy\ImageSocialiser\Seo\Seo_Adapter|null The registered adapter or null
	 */
	public static function get_active_adapter(): ?Seo_Adapter {
		return self::$active_adapter;
	}
	
	/**
	 * Detect the active SEO plugin and register its adapter.
	 */
	public static function register_adapter(): void {
		if ( self::$active_adapter !== null ) {
			return;
		}
		
		/**
		 * Filter the list of SEO adapters, ordered by priority.
		 *
		 * @param	\happyhappy\ImageSocialiser\Seo\Seo_Adapter[]	$adapters List of adapter instances
		 */
		$adapters = (array) \apply_filters(
			'image_socialiser_seo_adapters',
			[
				new The_Seo_Framework_Adapter(),
				new Rank_Math_Adapter(),
				new Yoast_Adapter(),
				new Aioseo_Adapter(),
				new Seopress_Adapter(),
				// native must stay last — it is the no-SEO-plugin fallback
				new Native_Adapter(),
			]
		);
		
		foreach ( $adapters as $adapter ) {
			if ( ! $adapter instanceof Seo_Adapter ) {
				continue;
			}
			
			if ( ! $adapter->is_active() ) {
				continue;
			}
			
			$adapter->register();
			self::$active_adapter = $adapter;
			
			return;
		}
	}
	
	/**
	 * Check whether the active SEO plugin's own image wins for a post.
	 *
	 * An image set in the SEO plugin's own social panel expresses the
	 * same intent as our manual override, so it takes precedence over
	 * the generated one — the author picked a specific image for this
	 * post. When this returns true the adapters pass the SEO plugin's
	 * value through untouched, which also keeps that plugin's own
	 * dimensions and alt text intact.
	 *
	 * @param	int	$post_id The post ID
	 * @return	bool Whether to leave the SEO plugin's image alone
	 */
	public static function defers_to_seo_plugin( int $post_id ): bool {
		$adapter = self::$active_adapter;
		
		if ( ! $adapter instanceof Seo_Adapter || $post_id <= 0 ) {
			return false;
		}
		
		/**
		 * Filter whether an image set in the SEO plugin's own social
		 * panel beats the generated image.
		 *
		 * Set to false to make the generated image always win, which
		 * is how versions before 1.0.0 behaved.
		 *
		 * @param	bool	$respect Whether the SEO plugin's own image wins
		 * @param	int	$post_id The post ID
		 * @param	string	$adapter_id The active adapter identifier
		 */
		$respect = (bool) \apply_filters(
			'image_socialiser_respect_seo_plugin_image',
			true,
			$post_id,
			$adapter->get_id()
		);
		
		return $respect && $adapter->has_manual_image( $post_id );
	}
	
	/**
	 * Resolve our image for the current singular front-end view.
	 *
	 * Memoized per post for the duration of the request, since SEO
	 * plugins run their image filters multiple times per page.
	 *
	 * @return	array{height: int, type: string, url: string, width: int}|null The image data or null
	 */
	public static function resolve_current_image(): ?array {
		$subject = Subject::from_query();
		
		if ( $subject === null ) {
			return null;
		}
		
		if ( $subject->kind === 'post' && self::defers_to_seo_plugin( $subject->id ) ) {
			return null;
		}
		
		$slug = $subject->get_slug();
		
		if ( ! \array_key_exists( $slug, self::$resolved ) ) {
			if ( $subject->kind === 'post' ) {
				$post = \get_post( $subject->id );
				self::$resolved[ $slug ] = $post instanceof WP_Post && Post_Types::is_supported( $post->post_type )
					? ( new Resolver() )->resolve( $post )
					: null;
			}
			else {
				// the resolver gates on enablement itself (disabled
				// subjects return only an explicit term override)
				self::$resolved[ $slug ] = ( new Resolver() )->resolve_subject( $subject );
			}
		}
		
		return self::$resolved[ $slug ];
	}
}
