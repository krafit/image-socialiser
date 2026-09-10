<?php
declare(strict_types=1);

namespace happyhappy\ImageSocialiser\Generation;

use happyhappy\ImageSocialiser\Multisite\Multisite;

use happyhappy\ImageSocialiser\Rendering\Output_Format;
use happyhappy\ImageSocialiser\Template\Template_Registry;
use WP_Post;

// prevent direct file access
if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the Open Graph image for a post via the fallback chain.
 *
 * Resolution order:
 *
 * 1. per-post manual image override (attachment chosen by the author)
 * 2. generated image (status ready, file present)
 * 3. per-post-type fallback image (settings)
 * 4. site-wide default image (settings)
 * 5. filterable hard-coded fallback
 *
 * Width, height and type are always resolved alongside the URL, so no
 * crawler has to fetch the file to learn its dimensions.
 *
 * @author	Simon Kraft
 * @license	GPL2
 * @package	happyhappy\ImageSocialiser
 */
final class Resolver {
	/**
	 * @var	string Meta key for the per-post manual image override.
	 */
	public const string META_OVERRIDE_ID = '_image_socialiser_override_id';
	
	/**
	 * @var	string Option name for the fallback image attachment IDs.
	 */
	public const string OPTION_FALLBACKS = 'image_socialiser_fallbacks';
	
	/**
	 * Resolve the Open Graph image for a post.
	 *
	 * @param	\WP_Post	$post The post
	 * @return	array{height: int, type: string, url: string, width: int}|null The image data or null
	 */
	public function resolve( WP_Post $post ): ?array {
		$image = $this->get_override_image( $post )
			?? $this->get_generated_image( $post )
			?? $this->get_fallback_image( $post->post_type )
			?? $this->get_fallback_image( '_default' );
		
		/**
		 * Filter the resolved Open Graph image for a post.
		 *
		 * Also acts as the last step of the fallback chain: return
		 * image data here to provide a hard-coded fallback.
		 *
		 * @param	array{height: int, type: string, url: string, width: int}|null	$image The image data or null
		 * @param	\WP_Post	$post The current post
		 */
		$image = \apply_filters( 'image_socialiser_resolved_image', $image, $post );
		
		return \is_array( $image ) ? $image : null;
	}
	
	/**
	 * Resolve the Open Graph image for a non-post subject.
	 *
	 * Chain: manual override (terms) -> generated image -> per-CPT
	 * fallback (post type archives) -> site-wide fallback -> filter.
	 *
	 * @param	Subject	$subject The subject
	 * @return	array{height: int, type: string, url: string, width: int}|null The image data or null
	 */
	public function resolve_subject( Subject $subject ): ?array {
		if ( $subject->kind === 'post' ) {
			$post = \get_post( $subject->id );
			
			return $post instanceof WP_Post ? $this->resolve( $post ) : null;
		}
		
		$override_id = $subject->kind === 'term'
			? (int) \get_term_meta( $subject->id, self::META_OVERRIDE_ID, true )
			: 0;
		
		if ( ! $subject->is_enabled() ) {
			// disabled subjects emit nothing — except an explicit manual
			// term override, which reflects a deliberate author choice
			return $this->get_attachment_image( $override_id );
		}
		
		$image = $this->get_attachment_image( $override_id )
			?? $this->get_generated_subject_image( $subject )
			?? ( $subject->kind === 'post_type_archive' ? $this->get_fallback_image( $subject->key ) : null )
			?? $this->get_fallback_image( '_default' );
		
		/**
		 * Filter the resolved Open Graph image for a non-post subject.
		 *
		 * Also acts as the last step of the fallback chain.
		 *
		 * @param	array{height: int, type: string, url: string, width: int}|null	$image The image data or null
		 * @param	Subject	$subject The subject
		 */
		$image = \apply_filters( 'image_socialiser_resolved_subject_image', $image, $subject );
		
		return \is_array( $image ) ? $image : null;
	}
	
	/**
	 * Get the generated image for a subject, if ready and present.
	 *
	 * @param	Subject	$subject The subject
	 * @return	array{height: int, type: string, url: string, width: int}|null The image data or null
	 */
	private function get_generated_subject_image( Subject $subject ): ?array {
		$status = $subject->get_state( Generator::META_STATUS );
		$hash = $subject->get_state( Generator::META_HASH );
		
		if ( $status !== Generator::STATUS_READY || $hash === '' ) {
			return null;
		}
		
		$storage = new Storage();
		// read the stored format instead of resolving the template:
		// this runs on the crawler path and must stay a meta read
		$format = Output_Format::normalize( $subject->get_state( Generator::META_FORMAT ) );
		
		if (
			! \file_exists(
				$storage->get_directory()['path'] . '/'
					. $storage->get_filename_for( $subject, $hash, $format )
			)
		) {
			return null;
		}
		
		return [
			'height' => 630,
			'type' => Output_Format::get_mime_type( $format ),
			'url' => $storage->get_url_for( $subject, $hash, $format ),
			'width' => 1200,
		];
	}
	
	/**
	 * Get image data for an attachment.
	 *
	 * @param	int	$attachment_id The attachment ID
	 * @return	array{height: int, type: string, url: string, width: int}|null The image data or null
	 */
	private function get_attachment_image( int $attachment_id ): ?array {
		if ( $attachment_id === 0 ) {
			return null;
		}
		
		$source = \wp_get_attachment_image_src( $attachment_id, 'full' );
		
		if ( ! \is_array( $source ) ) {
			return null;
		}
		
		return [
			'height' => (int) $source[2],
			'type' => (string) \get_post_mime_type( $attachment_id ),
			'url' => (string) $source[0],
			'width' => (int) $source[1],
		];
	}
	
	/**
	 * Get the configured fallback image for a post type.
	 *
	 * @param	string	$key The post type name or '_default' for the site-wide fallback
	 * @return	array{height: int, type: string, url: string, width: int}|null The image data or null
	 */
	private function get_fallback_image( string $key ): ?array {
		$stored = \get_option( self::OPTION_FALLBACKS, null );
		$resolution = Multisite::resolve_section(
			'fallbacks',
			\is_array( $stored ) ? $stored : [],
			\is_array( $stored )
		);
		$fallbacks = (array) $resolution['value'];
		
		if ( empty( $fallbacks[ $key ] ) ) {
			return null;
		}
		
		if ( $resolution['network'] ) {
			// network fallbacks live in the main site's library
			return Multisite::get_main_site_attachment_image( (int) $fallbacks[ $key ] );
		}
		
		return $this->get_attachment_image( (int) $fallbacks[ $key ] );
	}
	
	/**
	 * Get the generated image for a post, if ready and present.
	 *
	 * @param	\WP_Post	$post The post
	 * @return	array{height: int, type: string, url: string, width: int}|null The image data or null
	 */
	private function get_generated_image( WP_Post $post ): ?array {
		$status = (string) \get_post_meta( $post->ID, Generator::META_STATUS, true );
		$hash = (string) \get_post_meta( $post->ID, Generator::META_HASH, true );
		
		if ( $status !== Generator::STATUS_READY || $hash === '' ) {
			return null;
		}
		
		$storage = new Storage();
		$format = Output_Format::normalize(
			(string) \get_post_meta( $post->ID, Generator::META_FORMAT, true )
		);
		$path = $storage->get_directory()['path'] . '/'
			. $storage->get_filename( $post->ID, $hash, $format );
		
		if ( ! \file_exists( $path ) ) {
			return null;
		}
		
		$model = Template_Registry::resolve_for_post( $post );
		
		return [
			'height' => $model->get_height(),
			'type' => Output_Format::get_mime_type( $format ),
			'url' => $storage->get_url( $post->ID, $hash, $format ),
			'width' => $model->get_width(),
		];
	}
	
	/**
	 * Get the per-post manual override image.
	 *
	 * @param	\WP_Post	$post The post
	 * @return	array{height: int, type: string, url: string, width: int}|null The image data or null
	 */
	private function get_override_image( WP_Post $post ): ?array {
		return $this->get_attachment_image(
			(int) \get_post_meta( $post->ID, self::META_OVERRIDE_ID, true )
		);
	}
}
