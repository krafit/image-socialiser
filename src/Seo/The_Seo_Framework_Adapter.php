<?php
declare(strict_types=1);

namespace happyhappy\ImageSocialiser\Seo;

use Generator as Php_Generator;
use happyhappy\ImageSocialiser\Generation\Post_Types;
use happyhappy\ImageSocialiser\Generation\Resolver;
use happyhappy\ImageSocialiser\Generation\Subject;
use WP_Post;

/**
 * Adapter for The SEO Framework (v4.0+, with legacy v3 fallback).
 *
 * Hooks the image generation parameters and replaces the callback
 * chain with a generator yielding our resolved image — including
 * explicit width/height, which The SEO Framework preserves for
 * non-attachment URLs. If our fallback chain resolves nothing, the
 * parameters are left untouched so the plugin's native generation
 * runs.
 *
 * On legacy v3 installs, the verified `the_seo_framework_ogimage_output`
 * and `the_seo_framework_twitterimage_output` URL filters are used
 * instead. (The spec referenced `the_seo_framework_og_image_args`,
 * which does not exist in the v3 source.)
 *
 * @author	Simon Kraft
 * @license	GPL2
 * @package	happyhappy\ImageSocialiser
 */
final class The_Seo_Framework_Adapter implements Seo_Adapter {
	/**
	 * @inheritdoc
	 */
	public function get_id(): string {
		return 'the-seo-framework';
	}
	
	/**
	 * @inheritdoc
	 */
	public function is_active(): bool {
		return \defined( 'THE_SEO_FRAMEWORK_VERSION' );
	}
	
	/**
	 * @inheritdoc
	 */
	public function register(): void {
		if ( \version_compare( (string) \constant( 'THE_SEO_FRAMEWORK_VERSION' ), '4.0', '>=' ) ) {
			\add_filter(
				'the_seo_framework_image_generation_params',
				[ $this, 'filter_generation_params' ],
				10,
				3
			);
			
			return;
		}
		
		\add_filter( 'the_seo_framework_ogimage_output', [ $this, 'filter_legacy_url' ], 10, 2 );
		\add_filter( 'the_seo_framework_twitterimage_output', [ $this, 'filter_legacy_url' ], 10, 2 );
	}
	
	/**
	 * Filter the image generation parameters (The SEO Framework 4.0+).
	 *
	 * @param	array	$params The image generation parameters (size, multi, cbs, fallback)
	 * @param	array|null	$args The query arguments (id, tax, pta, uid) or null for the auto-determined query
	 * @param	string	$context The caller context, e.g. 'social' or 'organization'
	 * @return	array The filtered parameters
	 */
	public function filter_generation_params( array $params, ?array $args, string $context ): array {
		if ( $context !== 'social' ) {
			return $params;
		}
		
		$image = $this->resolve_from_args( $args );
		
		if ( $image === null ) {
			return $params;
		}
		
		// one canonical social image; keep The SEO Framework's own fallbacks
		$params['multi'] = false;
		$params['cbs'] = [
			'image_socialiser' => static function() use ( $image ): Php_Generator {
				yield [
					'height' => $image['height'],
					'id' => 0,
					'url' => $image['url'],
					'width' => $image['width'],
				];
			},
		];
		
		return $params;
	}
	
	/**
	 * Filter the final image URL (The SEO Framework v3, legacy).
	 *
	 * @param	mixed	$url The image URL determined by The SEO Framework
	 * @param	mixed	$object_id The current page or term ID
	 * @return	string The filtered image URL
	 */
	public function filter_legacy_url( mixed $url, mixed $object_id ): string {
		$url = (string) $url;
		
		if ( ! \is_singular() ) {
			return $url;
		}
		
		$post = \get_post( (int) $object_id );
		
		if ( ! $post instanceof WP_Post ) {
			return $url;
		}
		
		$image = $this->resolve_image( $post );
		
		return $image['url'] ?? $url;
	}
	
	/**
	 * Resolve our image from The SEO Framework's query arguments.
	 *
	 * Covers singular posts, taxonomy terms ($args['tax']) and post
	 * type archives ($args['pta']); user queries are ignored.
	 *
	 * @param	array|null	$args The query arguments or null for the auto-determined query
	 * @return	array{height: int, type: string, url: string, width: int}|null The image data or null
	 */
	private function resolve_from_args( ?array $args ): ?array {
		if ( $args === null ) {
			return Seo_Handler::resolve_current_image();
		}
		
		if ( ! empty( $args['uid'] ) ) {
			return null;
		}
		
		if ( ! empty( $args['tax'] ) ) {
			$subject = Subject::from_term( (int) ( $args['id'] ?? 0 ) );
			
			// the resolver gates on enablement (override-only when disabled)
			return $subject->id > 0 ? ( new Resolver() )->resolve_subject( $subject ) : null;
		}
		
		if ( ! empty( $args['pta'] ) ) {
			return ( new Resolver() )->resolve_subject(
				Subject::from_post_type_archive( (string) $args['pta'] )
			);
		}
		
		if ( empty( $args['id'] ) ) {
			return null;
		}
		
		$post = \get_post( (int) $args['id'] );
		
		return $post instanceof WP_Post ? $this->resolve_image( $post ) : null;
	}
	
	/**
	 * Resolve our image for a supported post.
	 *
	 * @param	\WP_Post	$post The post
	 * @return	array{height: int, type: string, url: string, width: int}|null The image data or null
	 */
	private function resolve_image( WP_Post $post ): ?array {
		if ( ! Post_Types::is_supported( $post->post_type ) ) {
			return null;
		}
		
		return ( new Resolver() )->resolve( $post );
	}
}
