<?php
declare(strict_types=1);

namespace happyhappy\ImageSocialiser\Template;

use happyhappy\ImageSocialiser\Generation\Context;
use happyhappy\ImageSocialiser\Generation\Subject;
use happyhappy\ImageSocialiser\Multisite\Multisite;

use WP_Post;

// prevent direct file access
if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves dynamic template sources for a specific post.
 *
 * @author	Simon Kraft
 * @license	GPL2
 * @package	happyhappy\ImageSocialiser
 */
final class Binding {
	/**
	 * @var	string Meta key for the custom subtitle (secondary line).
	 */
	public const string META_SUBTITLE = '_image_socialiser_subtitle';
	
	/**
	 * @var	string Meta key for the custom Open Graph title.
	 */
	public const string META_TITLE = '_image_socialiser_title';
	
	/**
	 * @var	\WP_Post The post to resolve data for.
	 */
	private readonly ?WP_Post $post;
	
	/**
	 * @var	Subject The subject this binding resolves data for.
	 */
	private readonly Subject $subject;
	
	/**
	 * Binding constructor.
	 *
	 * @param	\WP_Post|Subject	$source The post or subject to resolve data for
	 */
	public function __construct( WP_Post|Subject $source ) {
		if ( $source instanceof WP_Post ) {
			$this->post = $source;
			$this->subject = Subject::from_post( $source->ID );
			
			return;
		}
		
		$this->subject = $source;
		$this->post = $source->kind === 'post' ? \get_post( $source->id ) : null;
	}
	
	/**
	 * Get the post this binding resolves data for, if any.
	 *
	 * @return	\WP_Post|null The post (null for non-post subjects)
	 */
	public function get_post(): ?WP_Post {
		return $this->post;
	}
	
	/**
	 * Get the subject this binding resolves data for.
	 *
	 * @return	Subject The subject
	 */
	public function get_subject(): Subject {
		return $this->subject;
	}
	
	/**
	 * Resolve an image source to an absolute file path.
	 *
	 * @param	string	$source The image source identifier
	 * @param	array	$layer The layer definition (carries the asset
	 * 			path for 'template_asset' sources)
	 * @return	string The absolute file path or an empty string
	 */
	public function get_image_path( string $source, array $layer = [] ): string {
		$path = match ( $source ) {
			'brand_background' => $this->get_background_path(),
			'cover_art' => $this->get_brand_attachment_path( (int) Brand::get_tokens()['cover_art_id'] ),
			'featured' => $this->get_attachment_path( $this->subject->get_featured_id() ),
			'logo' => $this->get_logo_path(),
			'template_asset' => self::get_template_asset_path( $layer ),
			default => '',
		};
		
		/**
		 * Filter the resolved image path for a source.
		 *
		 * Allows binding arbitrary sources, e.g. from post meta.
		 *
		 * @param	string	$path The absolute file path or an empty string
		 * @param	string	$source The image source identifier
		 * @param	\WP_Post|null	$post The current post, or null for
		 * 					non-post subjects (terms, archives, special
		 * 					pages) — type-hint callbacks accordingly
		 * @param	\happyhappy\ImageSocialiser\Generation\Subject	$subject The subject being rendered
		 */
		$path = (string) \apply_filters(
			'image_socialiser_binding_image_path',
			$path,
			$source,
			$this->post,
			$this->subject
		);
		
		if ( $path === '' || ! \is_readable( $path ) ) {
			return '';
		}
		
		return $path;
	}
	
	/**
	 * Resolve a text source to its value.
	 *
	 * @param	string	$source The text source identifier
	 * @return	string The resolved text
	 */
	public function get_text( string $source ): string {
		$value = match ( $source ) {
			'author' => $this->post !== null
				? (string) \get_the_author_meta( 'display_name', (int) $this->post->post_author )
				: '',
			'category' => $this->get_primary_category_name(),
			'date' => $this->post !== null ? (string) \get_the_date( '', $this->post ) : '',
			'site_name' => (string) \get_bloginfo( 'name' ),
			'subtitle' => $this->get_subtitle(),
			'title' => $this->get_title(),
			default => '',
		};
		
		/**
		 * Filter the resolved text for a source.
		 *
		 * Allows binding arbitrary sources, e.g. from post meta.
		 *
		 * @param	string	$value The resolved text
		 * @param	string	$source The text source identifier
		 * @param	\WP_Post|null	$post The current post, or null for
		 * 					non-post subjects (terms, archives, special
		 * 					pages) — type-hint callbacks accordingly
		 * @param	\happyhappy\ImageSocialiser\Generation\Subject	$subject The subject being rendered
		 */
		return (string) \apply_filters(
			'image_socialiser_binding_text',
			$value,
			$source,
			$this->post,
			$this->subject
		);
	}
	
	/**
	 * Get the Open Graph title: custom meta value or the post title.
	 *
	 * @return	string The resolved title
	 */
	public function get_title(): string {
		if ( $this->post !== null ) {
			$custom_title = (string) \get_post_meta( $this->post->ID, self::META_TITLE, true );
			
			if ( \trim( $custom_title ) !== '' ) {
				return \trim( $custom_title );
			}
			
			return \html_entity_decode(
				(string) \get_the_title( $this->post ),
				\ENT_QUOTES,
				'UTF-8'
			);
		}
		
		$title = $this->get_subject_title();
		
		/**
		 * Filter the title of a non-post subject.
		 *
		 * @param	string	$title The resolved title
		 * @param	Subject	$subject The subject
		 */
		return (string) \apply_filters( 'image_socialiser_subject_title', $title, $this->subject );
	}
	
	/**
	 * Get the default title for a non-post subject.
	 *
	 * @return	string The title
	 */
	private function get_subject_title(): string {
		if ( $this->subject->kind === 'term' ) {
			$custom_title = (string) \get_term_meta( $this->subject->id, self::META_TITLE, true );
			
			if ( \trim( $custom_title ) !== '' ) {
				return \trim( $custom_title );
			}
			
			$term = \get_term( $this->subject->id );
			
			return $term instanceof \WP_Term
				? \html_entity_decode( $term->name, \ENT_QUOTES, 'UTF-8' )
				: '';
		}
		
		// special pages and post type archives: a custom title from
		// the context settings wins over the derived default
		$custom_title = \trim( Context::get_special_title( $this->subject->kind ) );
		
		if ( $custom_title !== '' ) {
			return $custom_title;
		}
		
		switch ( $this->subject->kind ) {
			case 'post_type_archive':
				$post_type = \get_post_type_object( $this->subject->key );
				
				return $post_type !== null ? (string) ( $post_type->labels->name ?? $post_type->label ) : '';
			case 'search':
				return \__( 'Search', 'image-socialiser' );
			case '404':
				return \__( 'Page not found', 'image-socialiser' );
		}
		
		// front and blog use the site title
		return (string) \get_bloginfo( 'name' );
	}
	
	/**
	 * Get the validated file path of a template-shipped asset.
	 *
	 * Layers with 'source' => 'template_asset' carry the file in
	 * their 'asset_path' key (the template registers it in code —
	 * same trust level as any other template value). The path is
	 * still validated: it must be a plain, readable image file.
	 *
	 * @param	array	$layer The layer definition
	 * @return	string The absolute file path or an empty string
	 */
	private static function get_template_asset_path( array $layer ): string {
		$path = $layer['asset_path'] ?? '';
		
		if ( ! \is_string( $path ) || $path === '' || \str_contains( $path, '://' ) ) {
			return '';
		}
		
		$path = (string) \realpath( $path );
		$extension = \strtolower( \pathinfo( $path, \PATHINFO_EXTENSION ) );
		
		if (
			$path === ''
			|| ! \is_file( $path )
			|| ! \in_array( $extension, [ 'gif', 'jpeg', 'jpg', 'png', 'webp' ], true )
		) {
			return '';
		}
		
		return $path;
	}
	
	/**
	 * Get the absolute file path of an attachment.
	 *
	 * @param	int	$attachment_id The attachment ID
	 * @return	string The absolute file path or an empty string
	 */
	private function get_attachment_path( int $attachment_id ): string {
		if ( $attachment_id === 0 ) {
			return '';
		}
		
		$path = \get_attached_file( $attachment_id );
		
		return \is_string( $path ) ? $path : '';
	}
	
	/**
	 * Get the effective background image path (theme support or brand).
	 *
	 * @return	string The absolute file path or an empty string
	 */
	private function get_background_path(): string {
		$background = Design::get_background();
		
		if ( $background['path'] !== '' ) {
			return $background['path'];
		}
		
		return $this->get_brand_attachment_path( $background['id'] );
	}
	
	/**
	 * Get the path of a brand attachment, honoring network provenance.
	 *
	 * Brand media provided by the network layer lives in the main
	 * site's library.
	 *
	 * @param	int	$attachment_id The attachment ID
	 * @return	string The absolute file path or an empty string
	 */
	private function get_brand_attachment_path( int $attachment_id ): string {
		if ( Multisite::uses_network( 'brand' ) ) {
			return Multisite::get_main_site_attachment_path( $attachment_id );
		}
		
		return $this->get_attachment_path( $attachment_id );
	}
	
	/**
	 * Get the logo path, honoring the logo's provenance.
	 *
	 * @return	string The absolute file path or an empty string
	 */
	private function get_logo_path(): string {
		$logo = Design::resolve_logo();
		
		if ( $logo['network'] ) {
			return Multisite::get_main_site_attachment_path( $logo['id'] );
		}
		
		return $this->get_attachment_path( $logo['id'] );
	}
	
	/**
	 * Get the excerpt: manual excerpt or trimmed content.
	 *
	 * @return	string The excerpt
	 */
	private function get_excerpt(): string {
		if ( $this->post === null ) {
			return '';
		}
		
		if ( \trim( $this->post->post_excerpt ) !== '' ) {
			return \trim( $this->post->post_excerpt );
		}
		
		return \wp_trim_words( \wp_strip_all_tags( $this->post->post_content ), 20, '…' );
	}
	
	/**
	 * Get the secondary line: custom meta value or the site-wide source.
	 *
	 * @return	string The resolved subtitle
	 */
	public function get_subtitle(): string {
		if ( $this->post !== null ) {
			$custom_subtitle = (string) \get_post_meta( $this->post->ID, self::META_SUBTITLE, true );
			
			if ( \trim( $custom_subtitle ) !== '' ) {
				return \trim( $custom_subtitle );
			}
			
			return $this->get_subtitle_fallback();
		}
		
		$subtitle = $this->get_subject_subtitle();
		
		/**
		 * Filter the secondary line of a non-post subject.
		 *
		 * @since	1.0.0
		 *
		 * @param	string	$subtitle The resolved subtitle
		 * @param	Subject	$subject The subject
		 */
		return (string) \apply_filters( 'image_socialiser_subject_subtitle', $subtitle, $this->subject );
	}
	
	/**
	 * Get the default secondary line for a non-post subject.
	 *
	 * @return	string The subtitle
	 */
	private function get_subject_subtitle(): string {
		if ( $this->subject->kind === 'term' ) {
			$custom_subtitle = (string) \get_term_meta( $this->subject->id, self::META_SUBTITLE, true );
			
			if ( \trim( $custom_subtitle ) !== '' ) {
				return \trim( $custom_subtitle );
			}
			
			$term = \get_term( $this->subject->id );
			$description = $term instanceof \WP_Term ? \wp_strip_all_tags( $term->description ) : '';
			
			return $description !== '' ? \wp_trim_words( $description, 20, '…' ) : '';
		}
		
		// special pages and post type archives: a custom subtitle from
		// the context settings wins over the derived default
		$custom_subtitle = \trim( Context::get_special_subtitle( $this->subject->kind ) );
		
		if ( $custom_subtitle !== '' ) {
			return $custom_subtitle;
		}
		
		return match ( $this->subject->kind ) {
			'blog', 'front' => (string) \get_bloginfo( 'description' ),
			// search and 404 images are static and stay minimal
			default => '',
		};
	}
	
	/**
	 * Get the site-wide subtitle value, ignoring the per-post meta.
	 *
	 * @return	string The subtitle from the configured source
	 */
	public function get_subtitle_fallback(): string {
		return match ( Design::get_content_tokens()['subtitle_source'] ) {
			'category' => $this->get_primary_category_name(),
			'excerpt' => $this->get_excerpt(),
			'tagline' => (string) \get_bloginfo( 'description' ),
			default => '',
		};
	}
	
	/**
	 * Get the name of the first assigned category.
	 *
	 * @return	string The category name or an empty string
	 */
	private function get_primary_category_name(): string {
		if ( $this->post === null ) {
			return '';
		}
		
		$categories = \get_the_category( $this->post->ID );
		
		if ( empty( $categories[0]->name ) ) {
			return '';
		}
		
		return (string) $categories[0]->name;
	}
}
