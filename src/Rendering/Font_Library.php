<?php
declare(strict_types=1);

namespace happyhappy\ImageSocialiser\Rendering;

use WP_Post;

/**
 * Consumes fonts installed through the WordPress Font Library (6.5+).
 *
 * Enumerates `wp_font_face` posts and registers every face backed by
 * a TTF or OTF file into the font registry, so Font Library fonts
 * appear in the settings dropdowns, the editor preview, theme
 * support, and templates. WOFF/WOFF2-only faces are excluded: the
 * server renderers (Imagick/GD via FreeType) cannot rasterize them,
 * and offering them in the preview would make preview ≠ render.
 *
 * Font Library fonts are per-site (like uploads), matching the
 * plugin's storage model — on multisite they are hidden while the
 * fonts section is network-locked, exactly like per-site custom
 * uploads. Reading faces works on any site regardless of theme type;
 * only the management UI is block-theme-first.
 *
 * @author	Simon Kraft
 * @license	GPL2
 * @package	happyhappy\ImageSocialiser
 */
final class Font_Library {
	/**
	 * @var	string The font identifier prefix (followed by the face post ID).
	 */
	public const string ID_PREFIX = 'font-library-';
	
	/**
	 * @var	array<string, array{label: string, path: string, url: string}>|null Per-request cache.
	 */
	private static ?array $faces = null;
	
	/**
	 * Get all usable Font Library faces as identifier => file path.
	 *
	 * @return	array<string, string> The font paths
	 */
	public static function get_all(): array {
		return \array_map( static fn( array $face ): string => $face['path'], self::get_faces() );
	}
	
	/**
	 * Get all usable Font Library faces as identifier => public URL.
	 *
	 * @return	array<string, string> The font URLs
	 */
	public static function get_all_urls(): array {
		return \array_map( static fn( array $face ): string => $face['url'], self::get_faces() );
	}
	
	/**
	 * Get the display label for a Font Library font identifier.
	 *
	 * @param	string	$font_id The font identifier
	 * @return	string The label or an empty string for unknown identifiers
	 */
	public static function get_label( string $font_id ): string {
		return self::get_faces()[ $font_id ]['label'] ?? '';
	}
	
	/**
	 * Reset the per-request cache (used by tests and blog switches).
	 */
	public static function reset_cache(): void {
		self::$faces = null;
	}
	
	/**
	 * Enumerate and validate the installed faces.
	 *
	 * @return	array<string, array{label: string, path: string, url: string}> The faces
	 */
	private static function get_faces(): array {
		if ( self::$faces !== null ) {
			return self::$faces;
		}
		
		self::$faces = [];
		
		// Font Library exists since WordPress 6.5
		if ( ! \function_exists( 'wp_get_font_dir' ) || ! \post_type_exists( 'wp_font_face' ) ) {
			return self::$faces;
		}
		
		$font_dir = \wp_get_font_dir();
		$face_posts = \get_posts( [
			'no_found_rows' => true,
			'numberposts' => -1,
			'post_status' => 'publish',
			'post_type' => 'wp_font_face',
			'update_post_term_cache' => false,
		] );
		
		foreach ( $face_posts as $face_post ) {
			if ( ! $face_post instanceof WP_Post ) {
				continue;
			}
			
			$face = \json_decode( $face_post->post_content, true );
			
			if ( ! \is_array( $face ) ) {
				continue;
			}
			
			$sources = \is_array( $face['src'] ?? null ) ? $face['src'] : [ (string) ( $face['src'] ?? '' ) ];
			$resolved = self::resolve_source( $sources, $font_dir );
			
			if ( $resolved === null ) {
				continue;
			}
			
			self::$faces[ self::ID_PREFIX . $face_post->ID ] = [
				'label' => self::build_label( $face_post, $face ),
				'path' => $resolved['path'],
				'url' => $resolved['url'],
			];
		}
		
		return self::$faces;
	}
	
	/**
	 * Pick the first TTF/OTF source and validate its file.
	 *
	 * @param	string[]	$sources The face's source URLs
	 * @param	array	$font_dir The wp_get_font_dir() array
	 * @return	array{path: string, url: string}|null The resolved file or null
	 */
	private static function resolve_source( array $sources, array $font_dir ): ?array {
		$base_url = (string) ( $font_dir['url'] ?? '' );
		$base_path = (string) ( $font_dir['path'] ?? '' );
		
		foreach ( $sources as $url ) {
			$url = (string) $url;
			$extension = \strtolower( \pathinfo( \parse_url( $url, \PHP_URL_PATH ) ?: '', \PATHINFO_EXTENSION ) );
			
			// WOFF/WOFF2-only faces are excluded by design
			if ( $extension !== 'ttf' && $extension !== 'otf' ) {
				continue;
			}
			
			if ( $base_url === '' || ! \str_starts_with( $url, $base_url ) ) {
				continue;
			}
			
			$path = $base_path . \substr( $url, \strlen( $base_url ) );
			
			if ( ! \is_readable( $path ) ) {
				continue;
			}
			
			// same validation as custom uploads: FreeType parses these
			// files server-side, so check the sfnt magic bytes
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$magic = (string) \file_get_contents( $path, false, null, 0, 4 );
			
			if ( $magic !== "\x00\x01\x00\x00" && $magic !== 'OTTO' ) {
				continue;
			}
			
			return [
				'path' => $path,
				'url' => $url,
			];
		}
		
		return null;
	}
	
	/**
	 * Build a readable label from the family and face data.
	 *
	 * @param	\WP_Post	$face_post The face post
	 * @param	array	$face The decoded face data
	 * @return	string The label
	 */
	private static function build_label( WP_Post $face_post, array $face ): string {
		$family = '';
		
		if ( $face_post->post_parent > 0 ) {
			$parent = \get_post( $face_post->post_parent );
			$family = $parent instanceof WP_Post ? $parent->post_title : '';
		}
		
		if ( $family === '' ) {
			$family = \trim( (string) ( $face['fontFamily'] ?? '' ), '"\' ' );
		}
		
		$weight = (string) ( $face['fontWeight'] ?? '' );
		$style = \strtolower( (string) ( $face['fontStyle'] ?? 'normal' ) );
		$variant = \trim(
			( $weight !== '' && $weight !== '400' ? $weight : '' )
			. ( $style !== 'normal' && $style !== '' ? ' ' . \ucfirst( $style ) : '' )
		);
		$name = \trim( $family . ' ' . $variant );
		
		return \sprintf(
			/* translators: %s: font name, e.g. "Open Sans 700 Italic" */
			\__( '%s (Font Library)', 'image-socialiser' ),
			$name !== '' ? $name : '#' . (string) $face_post->ID
		);
	}
}
