<?php
declare(strict_types=1);

namespace happyhappy\ImageSocialiser\Template;

use happyhappy\ImageSocialiser\Rendering\Custom_Fonts;
use WP_Error;

/**
 * Loader for design packs — complete designs shipped by themes and
 * plugins via a design.json manifest.
 *
 * Follows the register_block_type() pattern: the extension hands over
 * an explicit manifest path, nothing is ever scanned. The parsed and
 * validated manifest is cached by file modification time; design
 * resolution runs only during generation jobs and on admin/editor
 * screens — never on the front-end/crawler path.
 *
 * @author	Simon Kraft
 * @license	GPL2
 * @package	happyhappy\ImageSocialiser
 */
final class Design_Packs {
	/**
	 * @var	string Transient name prefix for cached manifests.
	 */
	public const string CACHE_PREFIX = 'image_socialiser_pack_';
	
	/**
	 * @var	string[] Brand tokens resolvable via 'token:' references.
	 */
	private const array TOKEN_KEYS = [
		'background_from',
		'background_to',
		'body_font',
		'heading_font',
		'muted_color',
		'text_color',
	];
	
	/**
	 * @var	array<string, array> Registered packs by identifier.
	 */
	private static array $packs = [];
	
	/**
	 * Initialize the loader.
	 */
	public static function init(): void {
		\add_action( 'init', [ self::class, 'do_registration_action' ] );
		\add_filter( 'image_socialiser_templates', [ self::class, 'add_templates' ] );
		\add_filter( 'image_socialiser_fonts', [ self::class, 'add_fonts' ] );
		\add_filter( 'image_socialiser_font_urls', [ self::class, 'add_font_urls' ] );
	}
	
	/**
	 * Fire the canonical registration hook for extensions.
	 */
	public static function do_registration_action(): void {
		/**
		 * Register design packs.
		 *
		 * The canonical hook for themes and plugins to call
		 * image_socialiser_register_design(); registering on 'init'
		 * or 'after_setup_theme' works as well.
		 *
		 * @since	0.18.0
		 */
		\do_action( 'image_socialiser_register_designs' );
	}
	
	/**
	 * Register a design pack from its manifest path.
	 *
	 * @param	string	$manifest_path Absolute path to a design.json file
	 * @return	string|\WP_Error The registered design id, or WP_Error on failure
	 */
	public static function register( string $manifest_path ): string|WP_Error {
		$manifest_path = (string) \realpath( $manifest_path );
		
		if ( $manifest_path === '' || ! \is_file( $manifest_path ) || ! \is_readable( $manifest_path ) ) {
			return new WP_Error(
				'image_socialiser_pack_missing',
				\__( 'The design.json manifest file could not be read.', 'image-socialiser' )
			);
		}
		
		$pack = self::load_manifest( $manifest_path );
		
		if ( $pack instanceof WP_Error ) {
			return $pack;
		}
		
		if ( isset( self::$packs[ $pack['id'] ] ) || \in_array( $pack['id'], Built_In_Templates::IDS, true ) ) {
			\_doing_it_wrong(
				__METHOD__,
				\sprintf(
					/* translators: design pack identifier */
					\esc_html__( 'The design id "%s" is already registered — built-ins and earlier registrations win.', 'image-socialiser' ),
					\esc_html( $pack['id'] )
				),
				'0.18.0'
			);
			
			return new WP_Error(
				'image_socialiser_pack_collision',
				\__( 'The design id is already registered.', 'image-socialiser' )
			);
		}
		
		self::$packs[ $pack['id'] ] = $pack;
		
		return $pack['id'];
	}
	
	/**
	 * Register several design packs listed in one index.json file.
	 *
	 * The index is a JSON array of manifest paths, relative to the
	 * index file — one explicit entry point, still no scanning.
	 *
	 * @param	string	$index_path Absolute path to an index.json file
	 * @return	array<int, string|\WP_Error> Registered ids or errors, per entry
	 */
	public static function register_collection( string $index_path ): array {
		$index_path = (string) \realpath( $index_path );
		
		if ( $index_path === '' || ! \is_file( $index_path ) || ! \is_readable( $index_path ) ) {
			return [
				new WP_Error(
					'image_socialiser_pack_missing',
					\__( 'The index.json file could not be read.', 'image-socialiser' )
				),
			];
		}
		
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$entries = \json_decode( (string) \file_get_contents( $index_path ), true );
		
		if ( ! \is_array( $entries ) ) {
			return [
				new WP_Error(
					'image_socialiser_pack_invalid',
					\__( 'The index.json file does not contain a JSON array.', 'image-socialiser' )
				),
			];
		}
		
		$results = [];
		$base_directory = \dirname( $index_path );
		
		foreach ( $entries as $entry ) {
			if ( ! \is_string( $entry ) ) {
				continue;
			}
			
			$manifest_path = self::resolve_relative( $base_directory, $entry );
			$results[] = $manifest_path !== ''
				? self::register( $manifest_path )
				: new WP_Error(
					'image_socialiser_pack_traversal',
					\__( 'An index.json entry points outside the index directory.', 'image-socialiser' )
				);
		}
		
		return $results;
	}
	
	/**
	 * Add the registered pack templates (filter callback).
	 *
	 * Token references are resolved here — against the current brand
	 * tokens on every build — so packs inherit design changes exactly
	 * like the built-in templates do.
	 *
	 * @param	array<string, array>	$templates The registered templates
	 * @return	array<string, array> The templates including pack designs
	 */
	public static function add_templates( array $templates ): array {
		foreach ( self::$packs as $pack_id => $pack ) {
			if ( isset( $templates[ $pack_id ] ) ) {
				// built-ins and earlier registrations win
				continue;
			}
			
			$templates[ $pack_id ] = self::build_template( $pack );
		}
		
		return $templates;
	}
	
	/**
	 * Add the validated pack fonts (filter callback).
	 *
	 * @param	array<string, string>	$fonts Font identifier => absolute file path
	 * @return	array<string, string> The fonts including pack fonts
	 */
	public static function add_fonts( array $fonts ): array {
		foreach ( self::$packs as $pack ) {
			foreach ( $pack['fonts'] as $font_id => $font ) {
				if ( ! isset( $fonts[ $font_id ] ) ) {
					$fonts[ $font_id ] = $font['path'];
				}
			}
		}
		
		return $fonts;
	}
	
	/**
	 * Add the pack font URLs for the browser preview (filter callback).
	 *
	 * @param	array<string, string>	$urls Font identifier => public file URL
	 * @return	array<string, string> The URLs including pack fonts
	 */
	public static function add_font_urls( array $urls ): array {
		foreach ( self::$packs as $pack ) {
			foreach ( $pack['fonts'] as $font_id => $font ) {
				if ( ! isset( $urls[ $font_id ] ) && $font['url'] !== '' ) {
					$urls[ $font_id ] = $font['url'];
				}
			}
		}
		
		return $urls;
	}
	
	/**
	 * Get the registered packs (identifier => validated pack).
	 *
	 * @return	array<string, array> The registered packs
	 */
	public static function get_registered(): array {
		return self::$packs;
	}
	
	/**
	 * Reset the registered packs (used by tests).
	 */
	public static function reset(): void {
		self::$packs = [];
	}
	
	/**
	 * Load a manifest, using the parse-once cache.
	 *
	 * The cached result is keyed by the manifest path and invalidated
	 * by file modification time and the plugin version, so a changed
	 * file, a theme switch pointing at other files, or a plugin update
	 * (loader changes) all re-parse automatically.
	 *
	 * @param	string	$manifest_path The absolute, resolved manifest path
	 * @return	array|\WP_Error The validated pack, or WP_Error on failure
	 */
	private static function load_manifest( string $manifest_path ): array|WP_Error {
		$cache_key = self::CACHE_PREFIX . \md5( $manifest_path );
		$modified_time = (int) \filemtime( $manifest_path );
		$cached = \get_transient( $cache_key );
		
		if (
			\is_array( $cached )
			&& ( $cached['mtime'] ?? 0 ) === $modified_time
			&& ( $cached['plugin_version'] ?? '' ) === \happyhappy\ImageSocialiser\Plugin::VERSION
			&& \is_array( $cached['pack'] ?? null )
		) {
			return $cached['pack'];
		}
		
		$pack = self::parse_manifest( $manifest_path );
		
		if ( $pack instanceof WP_Error ) {
			return $pack;
		}
		
		\set_transient(
			$cache_key,
			[
				'mtime' => $modified_time,
				'pack' => $pack,
				'plugin_version' => \happyhappy\ImageSocialiser\Plugin::VERSION,
			],
			\WEEK_IN_SECONDS
		);
		
		return $pack;
	}
	
	/**
	 * Parse and validate a design.json manifest (v1 schema).
	 *
	 * File-level problems (unreadable files, path traversal, invalid
	 * font files) reject the whole pack; malformed layers are dropped
	 * individually.
	 *
	 * @param	string	$manifest_path The absolute, resolved manifest path
	 * @return	array|\WP_Error The validated pack, or WP_Error on failure
	 */
	private static function parse_manifest( string $manifest_path ): array|WP_Error {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$manifest = \json_decode( (string) \file_get_contents( $manifest_path ), true );
		
		if ( ! \is_array( $manifest ) ) {
			return new WP_Error(
				'image_socialiser_pack_invalid',
				\__( 'The design.json manifest is not valid JSON.', 'image-socialiser' )
			);
		}
		
		$pack_id = (string) ( $manifest['id'] ?? '' );
		
		if ( \sanitize_key( $pack_id ) !== $pack_id || \preg_match( '/^[a-z0-9]+(-[a-z0-9]+)+$/', $pack_id ) !== 1 ) {
			return new WP_Error(
				'image_socialiser_pack_invalid',
				\__( 'The design id must be lowercase and namespaced with the theme/plugin slug, e.g. "acme-poster".', 'image-socialiser' )
			);
		}
		
		$directory = \dirname( $manifest_path );
		$fonts = self::validate_fonts( $manifest['fonts'] ?? [], $directory, $pack_id );
		
		if ( $fonts instanceof WP_Error ) {
			return $fonts;
		}
		
		$assets = self::validate_assets( $manifest['assets'] ?? [], $directory );
		
		if ( $assets instanceof WP_Error ) {
			return $assets;
		}
		
		$layers = self::validate_layers(
			\is_array( $manifest['layers'] ?? null ) ? $manifest['layers'] : [],
			$assets
		);
		
		if ( $layers === [] ) {
			return new WP_Error(
				'image_socialiser_pack_invalid',
				\__( 'The design.json manifest contains no valid layers.', 'image-socialiser' )
			);
		}
		
		$label = \sanitize_text_field( (string) ( $manifest['label'] ?? '' ) );
		$pins = \is_array( $manifest['pins'] ?? null ) ? $manifest['pins'] : [];
		$supports = \is_array( $manifest['supports'] ?? null ) ? $manifest['supports'] : [];
		
		return [
			'assets' => $assets,
			'canvas' => \is_array( $manifest['canvas'] ?? null ) ? $manifest['canvas'] : [],
			'fonts' => $fonts,
			'id' => $pack_id,
			'label' => $label !== '' ? $label : \ucwords( \str_replace( '-', ' ', $pack_id ) ),
			'layers' => $layers,
			'pins' => \array_filter( $pins, '\is_string' ),
			'supports' => \array_values( \array_filter( $supports, '\is_string' ) ),
			'version' => \max( 1, (int) ( $manifest['version'] ?? 1 ) ),
		];
	}
	
	/**
	 * Validate the declared pack fonts.
	 *
	 * @param	mixed	$declared The manifest 'fonts' value
	 * @param	string	$directory The manifest directory
	 * @param	string	$pack_id The pack identifier
	 * @return	array<string, array{path: string, url: string}>|\WP_Error The fonts, or WP_Error
	 */
	private static function validate_fonts( mixed $declared, string $directory, string $pack_id ): array|WP_Error {
		if ( ! \is_array( $declared ) ) {
			return [];
		}
		
		$fonts = [];
		
		foreach ( $declared as $font_id => $relative ) {
			$font_id = \sanitize_key( (string) $font_id );
			$path = \is_string( $relative ) ? self::resolve_relative( $directory, $relative ) : '';
			$extension = \strtolower( \pathinfo( $path, \PATHINFO_EXTENSION ) );
			
			if (
				$font_id === ''
				|| $path === ''
				|| ! \in_array( $extension, [ 'otf', 'ttf' ], true )
				|| ! Custom_Fonts::is_valid_font_file( $path )
			) {
				return new WP_Error(
					'image_socialiser_pack_font',
					\sprintf(
						/* translators: 1: font identifier, 2: design pack identifier */
						\__( 'The font "%1$s" of the design pack "%2$s" is missing, outside the pack directory, or not a valid TTF/OTF file.', 'image-socialiser' ),
						(string) $font_id,
						$pack_id
					)
				);
			}
			
			$fonts[ $font_id ] = [
				'path' => $path,
				'url' => self::get_url_for_path( $path ),
			];
		}
		
		return $fonts;
	}
	
	/**
	 * Validate the declared pack assets.
	 *
	 * @param	mixed	$declared The manifest 'assets' value
	 * @param	string	$directory The manifest directory
	 * @return	array<string, array{path: string, url: string}>|\WP_Error The assets, or WP_Error
	 */
	private static function validate_assets( mixed $declared, string $directory ): array|WP_Error {
		if ( ! \is_array( $declared ) ) {
			return [];
		}
		
		$assets = [];
		
		foreach ( $declared as $name => $relative ) {
			$name = \sanitize_key( (string) $name );
			$path = \is_string( $relative ) ? self::resolve_relative( $directory, $relative ) : '';
			$extension = \strtolower( \pathinfo( $path, \PATHINFO_EXTENSION ) );
			
			if (
				$name === ''
				|| $path === ''
				|| ! \in_array( $extension, [ 'gif', 'jpeg', 'jpg', 'png', 'webp' ], true )
			) {
				return new WP_Error(
					'image_socialiser_pack_asset',
					\sprintf(
						/* translators: asset name */
						\__( 'The asset "%s" is missing, outside the pack directory, or not an image file.', 'image-socialiser' ),
						(string) $name
					)
				);
			}
			
			$assets[ $name ] = [
				'path' => $path,
				'url' => self::get_url_for_path( $path ),
			];
		}
		
		return $assets;
	}
	
	/**
	 * Validate the manifest layers structurally.
	 *
	 * Unknown or malformed layers are dropped. Server paths and URLs
	 * are stripped — 'template_asset' layers may only reference a
	 * declared asset by name; the validated paths are injected at
	 * build time.
	 *
	 * @param	array	$declared The manifest 'layers' value
	 * @param	array<string, array{path: string, url: string}>	$assets The validated assets
	 * @return	array<int, array> The validated layers
	 */
	private static function validate_layers( array $declared, array $assets ): array {
		$layers = [];
		
		foreach ( $declared as $layer ) {
			if ( ! \is_array( $layer ) ) {
				continue;
			}
			
			// paths never come from the manifest layer itself
			unset( $layer['asset_path'], $layer['asset_url'] );
			$type = (string) ( $layer['type'] ?? '' );
			
			if ( ! \in_array( $type, [ 'background', 'image', 'rect', 'text' ], true ) ) {
				continue;
			}
			
			if ( $type === 'image' ) {
				$source = (string) ( $layer['source'] ?? '' );
				$known_sources = [ 'brand_background', 'cover_art', 'featured', 'logo', 'template_asset' ];
				
				/**
				 * Filter the image sources design packs may reference.
				 *
				 * Extend this when binding additional image sources via
				 * image_socialiser_binding_image_path.
				 *
				 * @since	0.18.0
				 *
				 * @param	string[]	$known_sources The allowed source identifiers
				 */
				$known_sources = (array) \apply_filters( 'image_socialiser_pack_image_sources', $known_sources );
				
				if ( ! \in_array( $source, $known_sources, true ) ) {
					continue;
				}
				
				if ( $source === 'template_asset' ) {
					$asset_name = \sanitize_key( (string) ( $layer['asset'] ?? '' ) );
					
					if ( ! isset( $assets[ $asset_name ] ) ) {
						continue;
					}
					
					$layer['asset'] = $asset_name;
				}
			}
			
			$layers[] = $layer;
		}
		
		return $layers;
	}
	
	/**
	 * Build the template array for a pack, resolving token references.
	 *
	 * @param	array	$pack The validated pack
	 * @return	array The template definition
	 */
	private static function build_template( array $pack ): array {
		// per-design token overrides apply via this context
		Design::set_template_context( $pack['id'] );
		$brand = Brand::get_tokens();
		Design::set_template_context( '' );
		$layers = [];
		
		foreach ( $pack['layers'] as $layer ) {
			$layer = self::resolve_tokens( $layer, $brand );
			
			if ( ( $layer['source'] ?? '' ) === 'template_asset' ) {
				$asset = $pack['assets'][ $layer['asset'] ];
				$layer['asset_path'] = $asset['path'];
				$layer['asset_url'] = $asset['url'];
			}
			
			$layers[] = $layer;
		}
		
		return [
			'canvas' => $pack['canvas'],
			'id' => $pack['id'],
			'label' => $pack['label'],
			'layers' => $layers,
			'pins' => $pack['pins'],
			'supports' => $pack['supports'],
			'version' => $pack['version'],
		];
	}
	
	/**
	 * Resolve 'token:' references against the brand tokens, recursively.
	 *
	 * Colors and fonts may reference the brand tokens (e.g.
	 * 'token:text_color', 'token:heading_font') so a pack can inherit
	 * the site's design instead of pinning literals.
	 *
	 * @param	array	$values The layer (or nested) values
	 * @param	array	$brand The brand tokens
	 * @return	array The values with tokens resolved
	 */
	private static function resolve_tokens( array $values, array $brand ): array {
		foreach ( $values as $key => $value ) {
			if ( \is_array( $value ) ) {
				$values[ $key ] = self::resolve_tokens( $value, $brand );
				
				continue;
			}
			
			if ( ! \is_string( $value ) || ! \str_starts_with( $value, 'token:' ) ) {
				continue;
			}
			
			$token = \substr( $value, 6 );
			
			if ( \in_array( $token, self::TOKEN_KEYS, true ) && \is_string( $brand[ $token ] ?? null ) ) {
				$values[ $key ] = $brand[ $token ];
			}
		}
		
		return $values;
	}
	
	/**
	 * Resolve a relative path against a base directory, confined.
	 *
	 * The resolved realpath() must stay inside the base directory —
	 * no '../' traversal, no symlinks escaping the pack, no stream
	 * wrappers.
	 *
	 * @param	string	$base_directory The base (pack) directory
	 * @param	string	$relative The relative path from the manifest
	 * @return	string The absolute path, or an empty string when rejected
	 */
	private static function resolve_relative( string $base_directory, string $relative ): string {
		if ( $relative === '' || \str_contains( $relative, '://' ) ) {
			return '';
		}
		
		$base_directory = (string) \realpath( $base_directory );
		$path = (string) \realpath( $base_directory . '/' . $relative );
		
		if (
			$base_directory === ''
			|| $path === ''
			|| ! \is_file( $path )
			|| ! \str_starts_with( $path, $base_directory . \DIRECTORY_SEPARATOR )
		) {
			return '';
		}
		
		return $path;
	}
	
	/**
	 * Map an absolute file path to its public URL.
	 *
	 * Only files inside the content directory (themes, plugins,
	 * uploads) can be mapped; others get no URL and simply do not
	 * show in the browser preview — the server render still works.
	 *
	 * @param	string	$path The absolute file path
	 * @return	string The public URL or an empty string
	 */
	private static function get_url_for_path( string $path ): string {
		if ( ! \defined( 'WP_CONTENT_DIR' ) ) {
			return '';
		}
		
		$content_directory = (string) \realpath( (string) \constant( 'WP_CONTENT_DIR' ) );
		
		if ( $content_directory === '' || ! \str_starts_with( $path, $content_directory . \DIRECTORY_SEPARATOR ) ) {
			return '';
		}
		
		return \content_url( \str_replace( \DIRECTORY_SEPARATOR, '/', \substr( $path, \strlen( $content_directory ) ) ) );
	}
}
