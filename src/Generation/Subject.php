<?php
declare(strict_types=1);

namespace happyhappy\ImageSocialiser\Generation;

use WP_Post;
use WP_Term;

/**
 * A subject an Open Graph image is generated for.
 *
 * Generalizes the pipeline beyond posts: terms, post type archives,
 * and the special pages (front, blog, search, 404). Posts keep their
 * exact pre-0.13.0 state storage (post meta) and filenames — no
 * migration.
 *
 * | Kind                | Identity  | State storage | Filename                     |
 * | post                | post ID   | post meta     | og-{id}-{hash}.png           |
 * | term                | term ID   | term meta     | og-term-{id}-{hash}.png      |
 * | post_type_archive   | post type | option map    | og-archive-{type}-{hash}.png |
 * | front/blog/search/404 | fixed   | option map    | og-{kind}-{hash}.png         |
 *
 * @author	Simon Kraft
 * @license	GPL2
 * @package	happyhappy\ImageSocialiser
 */
final class Subject {
	/**
	 * @var	string Legacy (pre-0.14.0) option name of the shared state map.
	 */
	public const string LEGACY_OPTION_STATE = 'image_socialiser_context_state';
	
	/**
	 * @var	string Option name prefix for per-subject state (non-post/non-term).
	 */
	public const string OPTION_STATE_PREFIX = 'image_socialiser_state_';
	
	/**
	 * @var	string[] The special page kinds.
	 */
	public const array SPECIALS = [ '404', 'blog', 'front', 'search' ];
	
	/**
	 * @param	string	$kind The subject kind
	 * @param	int	$id The post or term ID (0 otherwise)
	 * @param	string	$key The post type name for archives (empty otherwise)
	 */
	private function __construct(
		public readonly string $kind,
		public readonly int $id = 0,
		public readonly string $key = ''
	) {}
	
	/**
	 * Create a post subject.
	 *
	 * @param	int	$post_id The post ID
	 * @return	self The subject
	 */
	public static function from_post( int $post_id ): self {
		return new self( 'post', $post_id );
	}
	
	/**
	 * Create a post type archive subject.
	 *
	 * @param	string	$post_type The post type name
	 * @return	self The subject
	 */
	public static function from_post_type_archive( string $post_type ): self {
		return new self( 'post_type_archive', 0, \sanitize_key( $post_type ) );
	}
	
	/**
	 * Resolve the current main query to a subject.
	 *
	 * Singular views yield post subjects; the blog page set to a real
	 * page resolves to that page's post subject (it is covered as a
	 * post). Returns null for unsupported contexts (author archives,
	 * date archives, …).
	 *
	 * @return	self|null The subject or null
	 */
	public static function from_query(): ?self {
		if ( \is_singular() ) {
			return self::from_post( (int) \get_queried_object_id() );
		}
		
		if ( \is_front_page() && \is_home() ) {
			return self::special( 'front' );
		}
		
		if ( \is_home() ) {
			$page_for_posts = (int) \get_option( 'page_for_posts', 0 );
			
			return $page_for_posts > 0 ? self::from_post( $page_for_posts ) : self::special( 'blog' );
		}
		
		if ( \is_category() || \is_tag() || \is_tax() ) {
			$term = \get_queried_object();
			
			return $term instanceof WP_Term ? self::from_term( $term->term_id ) : null;
		}
		
		if ( \is_post_type_archive() ) {
			$post_type = \get_query_var( 'post_type' );
			
			if ( \is_array( $post_type ) ) {
				$post_type = (string) \reset( $post_type );
			}
			
			return \is_string( $post_type ) && $post_type !== ''
				? self::from_post_type_archive( $post_type )
				: null;
		}
		
		if ( \is_search() ) {
			return self::special( 'search' );
		}
		
		if ( \is_404() ) {
			return self::special( '404' );
		}
		
		return null;
	}
	
	/**
	 * Create a term subject.
	 *
	 * @param	int	$term_id The term ID
	 * @return	self The subject
	 */
	public static function from_term( int $term_id ): self {
		return new self( 'term', $term_id );
	}
	
	/**
	 * Create a special page subject (front, blog, search, 404).
	 *
	 * @param	string	$kind The special kind
	 * @return	self The subject
	 * @throws	\InvalidArgumentException For unknown kinds
	 */
	public static function special( string $kind ): self {
		if ( ! \in_array( $kind, self::SPECIALS, true ) ) {
			throw new \InvalidArgumentException(
				\sprintf( 'Unknown special subject kind "%s".', \esc_html( $kind ) )
			);
		}
		
		return new self( $kind );
	}
	
	/**
	 * Delete the subject's stored generation state.
	 */
	public function delete_state(): void {
		switch ( $this->kind ) {
			case 'post':
				\delete_post_meta( $this->id, Generator::META_STATUS );
				\delete_post_meta( $this->id, Generator::META_HASH );
				\delete_post_meta( $this->id, Generator::META_ERROR );
				
				return;
			case 'term':
				\delete_term_meta( $this->id, Generator::META_STATUS );
				\delete_term_meta( $this->id, Generator::META_HASH );
				\delete_term_meta( $this->id, Generator::META_ERROR );
				
				return;
		}
		
		\delete_option( self::OPTION_STATE_PREFIX . $this->get_slug() );
	}
	
	/**
	 * Get the bound featured image attachment ID.
	 *
	 * Posts use the featured image; terms an optional term image
	 * (term meta 'thumbnail_id', the WooCommerce-established key).
	 *
	 * @return	int The attachment ID or 0
	 */
	public function get_featured_id(): int {
		return match ( $this->kind ) {
			'post' => (int) \get_post_thumbnail_id( $this->id ),
			'term' => (int) \get_term_meta( $this->id, 'thumbnail_id', true ),
			default => 0,
		};
	}
	
	/**
	 * Get the filename prefix ('og-42', 'og-term-7', 'og-front', …).
	 *
	 * Post prefixes match the pre-0.13.0 format exactly.
	 *
	 * @return	string The prefix
	 */
	public function get_prefix(): string {
		return match ( $this->kind ) {
			'post' => 'og-' . $this->id,
			'term' => 'og-term-' . $this->id,
			'post_type_archive' => 'og-archive-' . $this->key,
			default => 'og-' . $this->kind,
		};
	}
	
	/**
	 * Get a unique slug ('post-42', 'term-7', 'archive-post', 'front').
	 *
	 * @return	string The slug
	 */
	public function get_slug(): string {
		return match ( $this->kind ) {
			'post' => 'post-' . $this->id,
			'term' => 'term-' . $this->id,
			'post_type_archive' => 'archive-' . $this->key,
			default => $this->kind,
		};
	}
	
	/**
	 * Get a stored generation state value.
	 *
	 * @param	string	$key The meta key (Generator::META_*)
	 * @return	string The stored value or an empty string
	 */
	public function get_state( string $key ): string {
		switch ( $this->kind ) {
			case 'post':
				return (string) \get_post_meta( $this->id, $key, true );
			case 'term':
				return (string) \get_term_meta( $this->id, $key, true );
		}
		
		$state = \get_option( self::OPTION_STATE_PREFIX . $this->get_slug(), [] );
		
		return \is_array( $state ) ? (string) ( $state[ $key ] ?? '' ) : '';
	}
	
	/**
	 * Check whether image generation is enabled for this subject.
	 *
	 * @return	bool Whether the subject is enabled
	 */
	public function is_enabled(): bool {
		switch ( $this->kind ) {
			case 'post':
				$post = \get_post( $this->id );
				
				return $post instanceof WP_Post && Post_Types::is_supported( $post->post_type );
			case 'term':
				$term = \get_term( $this->id );
				
				return $term instanceof WP_Term && Context::is_taxonomy_enabled( $term->taxonomy );
			case 'post_type_archive':
				$post_type = \get_post_type_object( $this->key );
				
				return $post_type !== null
					&& ! empty( $post_type->has_archive )
					&& Post_Types::is_supported( $this->key )
					&& Context::is_special_enabled( 'archives' );
		}
		
		return Context::is_special_enabled( $this->kind );
	}
	
	/**
	 * Store a generation state value.
	 *
	 * @param	string	$key The meta key (Generator::META_*)
	 * @param	string	$value The value
	 */
	public function set_state( string $key, string $value ): void {
		switch ( $this->kind ) {
			case 'post':
				\update_post_meta( $this->id, $key, $value );
				
				return;
			case 'term':
				\update_term_meta( $this->id, $key, $value );
				
				return;
		}
		
		$option_name = self::OPTION_STATE_PREFIX . $this->get_slug();
		$state = \get_option( $option_name, [] );
		$state = \is_array( $state ) ? $state : [];
		$state[ $key ] = $value;
		// per-subject rows keep concurrent jobs from clobbering each
		// other's state; never autoloaded
		\update_option( $option_name, $state, false );
	}
	
	/**
	 * Remove a single stored state value.
	 *
	 * @param	string	$key The meta key (Generator::META_*)
	 */
	public function unset_state( string $key ): void {
		switch ( $this->kind ) {
			case 'post':
				\delete_post_meta( $this->id, $key );
				
				return;
			case 'term':
				\delete_term_meta( $this->id, $key );
				
				return;
		}
		
		$option_name = self::OPTION_STATE_PREFIX . $this->get_slug();
		$state = \get_option( $option_name, [] );
		$state = \is_array( $state ) ? $state : [];
		unset( $state[ $key ] );
		\update_option( $option_name, $state, false );
	}
}
