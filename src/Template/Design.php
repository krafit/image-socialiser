<?php
declare(strict_types=1);

namespace happyhappy\ImageSocialiser\Template;

use happyhappy\ImageSocialiser\Generation\Storage;
use happyhappy\ImageSocialiser\Rendering\Fonts;
use happyhappy\ImageSocialiser\Multisite\Multisite;

// prevent direct file access
if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The design token pipeline.
 *
 * Single source for all resolved design tokens (brand, layout,
 * content), superseding direct Brand::get_tokens() calls. The
 * resolution order is: defaults → site settings; network settings and
 * theme support slot into this pipeline in later phases.
 *
 * @author	Simon Kraft
 * @license	GPL2
 * @package	happyhappy\ImageSocialiser
 */
final class Design {
	/**
	 * @var	string Option name for the content tokens.
	 */
	public const string OPTION_CONTENT = 'image_socialiser_content';
	
	/**
	 * @var	string Option name for the per-design token overrides.
	 */
	public const string OPTION_DESIGN_OVERRIDES = 'image_socialiser_design_overrides';
	
	/**
	 * @var	string Option name for the layout tokens.
	 */
	public const string OPTION_LAYOUT = 'image_socialiser_layout';
	
	/**
	 * @var	string[] The valid corner positions.
	 */
	public const array POSITIONS = [ 'top-left', 'top-right', 'bottom-left', 'bottom-right' ];
	
	/**
	 * @var	string[] The valid subtitle sources.
	 */
	public const array SUBTITLE_SOURCES = [ 'none', 'tagline', 'excerpt', 'category' ];
	
	/**
	 * @var	string[] The valid text alignments.
	 */
	public const array TEXT_ALIGNMENTS = [ 'left', 'center', 'right' ];
	
	/**
	 * Bump the global design version.
	 *
	 * With the effect-based content hash this is a manual escape hatch
	 * only; correctness no longer depends on it.
	 */
	public static function bump_design_version(): void {
		$version = (int) \get_option( Storage::OPTION_DESIGN_VERSION, 1 );
		
		\update_option( Storage::OPTION_DESIGN_VERSION, $version + 1 );
	}
	
	/**
	 * Get the sanitized content tokens.
	 *
	 * @return	array{subtitle_source: string} The content tokens
	 */
	public static function get_content_tokens(): array {
		$stored = \get_option( self::OPTION_CONTENT, [] );
		$resolution = Multisite::resolve_section(
			'content',
			self::sanitize_content( \is_array( $stored ) ? $stored : [] ),
			\is_array( \get_option( self::OPTION_CONTENT, null ) )
		);
		$tokens = $resolution['network']
			? self::sanitize_content( (array) $resolution['value'] )
			: $resolution['value'];
		
		/**
		 * Filter the content tokens.
		 *
		 * @param	array	$tokens The sanitized content tokens
		 */
		return (array) \apply_filters( 'image_socialiser_content_tokens', $tokens );
	}
	
	/**
	 * Get the active theme's colour palette as picker swatches.
	 *
	 * Reads the theme.json palette (block and hybrid themes) with the
	 * classic `editor-color-palette` theme support as fallback, and
	 * keeps only parseable solid hex colours — `currentColor`,
	 * `transparent`, CSS variables, and gradients are skipped.
	 *
	 * @return	array<int, array{color: string, name: string}> The swatches (#rrggbb)
	 */
	public static function get_theme_palette(): array {
		$entries = [];
		
		if ( \function_exists( 'wp_get_global_settings' ) ) {
			$palette = \wp_get_global_settings( [ 'color', 'palette', 'theme' ] );
			$entries = \is_array( $palette ) ? $palette : [];
		}
		
		if ( $entries === [] ) {
			$legacy = \get_theme_support( 'editor-color-palette' );
			$entries = \is_array( $legacy ) && \is_array( $legacy[0] ?? null ) ? $legacy[0] : [];
		}
		
		$swatches = [];
		$seen = [];
		
		foreach ( $entries as $entry ) {
			$color = self::normalize_hex_color( (string) ( $entry['color'] ?? '' ) );
			
			if ( $color === '' || isset( $seen[ $color ] ) ) {
				continue;
			}
			
			$seen[ $color ] = true;
			$swatches[] = [
				'color' => $color,
				'name' => (string) ( $entry['name'] ?? $entry['slug'] ?? $color ),
			];
		}
		
		return \array_slice( $swatches, 0, 20 );
	}
	
	/**
	 * Normalize a colour value to #rrggbb, or reject it.
	 *
	 * @param	string	$color The raw colour value
	 * @return	string The normalized colour or an empty string
	 */
	private static function normalize_hex_color( string $color ): string {
		$color = \strtolower( \trim( $color ) );
		
		if ( \preg_match( '/^#[0-9a-f]{6}$/', $color ) === 1 ) {
			return $color;
		}
		
		if ( \preg_match( '/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $color, $matches ) === 1 ) {
			return '#' . $matches[1] . $matches[1] . $matches[2] . $matches[2] . $matches[3] . $matches[3];
		}
		
		return '';
	}
	
	/**
	 * Get the sanitized layout tokens.
	 *
	 * @return	array{
	 * 	logo_position: string,
	 * 	show_logo: bool,
	 * 	show_site_name: bool,
	 * 	site_name_position: string,
	 * 	text_align: string
	 * } The layout tokens
	 */
	public static function get_layout_tokens(): array {
		$resolution = Multisite::resolve_section(
			'layout',
			null,
			\is_array( \get_option( self::OPTION_LAYOUT, null ) )
		);
		$tokens = $resolution['network']
			? self::sanitize_layout( (array) $resolution['value'] )
			: self::get_site_layout_tokens();
		
		if ( ! $resolution['locked'] ) {
			// theme support beats site settings for declared keys —
			// but a network lock beats theme support
			$tokens = \array_merge( $tokens, Theme_Support::get_layout_overrides() );
		}
		
		/**
		 * Filter the layout tokens.
		 *
		 * @param	array	$tokens The sanitized layout tokens
		 */
		return (array) \apply_filters( 'image_socialiser_layout_tokens', $tokens );
	}
	
	/**
	 * Get the layout tokens from the site settings only (no theme support).
	 *
	 * @return	array The site-level layout tokens
	 */
	public static function get_site_layout_tokens(): array {
		$stored = \get_option( self::OPTION_LAYOUT, [] );
		
		return self::sanitize_layout( \is_array( $stored ) ? $stored : [] );
	}
	
	/**
	 * Get all resolved design tokens (brand + layout + content).
	 *
	 * @return	array The merged design tokens
	 */
	public static function get_tokens(): array {
		return \array_merge(
			Brand::get_tokens(),
			self::get_layout_tokens(),
			self::get_content_tokens()
		);
	}
	
	/**
	 * Resolve the effective background image.
	 *
	 * Theme support (attachment ID or theme file path) beats the site
	 * setting.
	 *
	 * @return	array{id: int, path: string} The background source (id 0 / empty path when unset)
	 */
	public static function get_background(): array {
		$theme_background = Theme_Support::get_background_image();
		
		if ( $theme_background['id'] > 0 || $theme_background['path'] !== '' ) {
			return $theme_background;
		}
		
		return [
			'id' => (int) Brand::get_tokens()['background_id'],
			'path' => '',
		];
	}
	
	/**
	 * Get the public URL of the effective background image.
	 *
	 * Used by the browser previews. Theme file paths are mapped to
	 * their content URL; paths outside wp-content yield no URL.
	 *
	 * @return	string The URL or an empty string
	 */
	public static function get_background_url(): string {
		$background = self::get_background();
		
		if ( $background['id'] > 0 ) {
			$url = \wp_get_attachment_image_url( $background['id'], 'full' );
			
			return \is_string( $url ) ? $url : '';
		}
		
		if ( $background['path'] !== ''
			&& \defined( 'WP_CONTENT_DIR' )
			&& \str_starts_with( $background['path'], (string) \constant( 'WP_CONTENT_DIR' ) )
		) {
			return \content_url(
				\substr( $background['path'], \strlen( (string) \constant( 'WP_CONTENT_DIR' ) ) )
			);
		}
		
		return '';
	}
	
	/**
	 * Get a stable fingerprint of the effective background image.
	 *
	 * Part of the content hash: attachment ID, or theme file path plus
	 * modification time (so a theme update shipping a new file
	 * invalidates automatically).
	 *
	 * @return	string The background fingerprint
	 */
	public static function get_background_fingerprint(): string {
		$background = self::get_background();
		
		if ( $background['path'] !== '' ) {
			return $background['path'] . ':' . (string) (int) @\filemtime( $background['path'] );
		}
		
		return (string) $background['id'];
	}
	
	/**
	 * Check whether the resolved logo is (likely) square.
	 *
	 * Site icons are square by definition; templates use this to pick
	 * a square-friendly logo box.
	 *
	 * @return	bool Whether the logo source is the site icon
	 */
	public static function is_logo_square(): bool {
		$logo = Brand::get_tokens()['logo'];
		
		return $logo['mode'] === 'auto' && (int) \get_option( 'site_icon', 0 ) > 0;
	}
	
	/**
	 * Resolve the effective logo attachment ID.
	 *
	 * Tri-state: 'none' → 0, 'custom' → the chosen attachment,
	 * 'auto' → site icon, then the theme's custom logo, then 0.
	 *
	 * @return	int The attachment ID or 0 for no logo
	 */
	public static function resolve_logo_id(): int {
		return self::resolve_logo()['id'];
	}
	
	/**
	 * Resolve the effective logo with its provenance.
	 *
	 * A custom logo provided by the network layer lives in the main
	 * site's media library; the auto chain (site icon, theme logo)
	 * stays contextual to the current site.
	 *
	 * @return	array{id: int, network: bool} The logo attachment and whether it is main-site media
	 */
	public static function resolve_logo(): array {
		$logo = Brand::get_tokens()['logo'];
		
		switch ( $logo['mode'] ) {
			case 'none':
				return [
					'id' => 0,
					'network' => false,
				];
			case 'custom':
				return [
					'id' => (int) $logo['id'],
					'network' => Multisite::uses_network( 'brand' ),
				];
		}
		
		$site_icon_id = (int) \get_option( 'site_icon', 0 );
		
		if ( $site_icon_id > 0 ) {
			return [
				'id' => $site_icon_id,
				'network' => false,
			];
		}
		
		return [
			'id' => (int) \get_theme_mod( 'custom_logo', 0 ),
			'network' => false,
		];
	}
	

	
	/**
	 * Sanitize content tokens from settings input.
	 *
	 * @param	mixed	$input The raw settings input
	 * @return	array{subtitle_source: string} The sanitized content tokens
	 */
	public static function sanitize_content( mixed $input ): array {
		$input = \is_array( $input ) ? $input : [];
		$source = (string) ( $input['subtitle_source'] ?? 'none' );
		
		return [
			'subtitle_source' => \in_array( $source, self::SUBTITLE_SOURCES, true ) ? $source : 'none',
		];
	}
	
	/**
	 * @var	string The template a token resolution currently runs for.
	 */
	private static string $template_context = '';
	
	/**
	 * @var	string[] The brand token keys a per-design override may set.
	 */
	public const array OVERRIDABLE_TOKENS = [
		'background_from',
		'background_to',
		'body_font',
		'heading_font',
		'muted_color',
		'site_name_color',
		'subtitle_color',
		'text_color',
	];
	
	/**
	 * Set the template the following token resolutions run for.
	 *
	 * Template builders (built-ins, design packs) set this so the
	 * per-design override tier applies to the right template; pass an
	 * empty string to clear the context.
	 *
	 * @param	string	$template_id The template identifier or an empty string
	 */
	public static function set_template_context( string $template_id ): void {
		self::$template_context = $template_id;
	}
	
	/**
	 * Get the template the current token resolution runs for.
	 *
	 * @return	string The template identifier or an empty string
	 */
	public static function get_template_context(): string {
		return self::$template_context;
	}
	
	/**
	 * Get the sanitized per-design token overrides.
	 *
	 * @return	array<string, array<string, string>> Template id => partial brand tokens
	 */
	public static function get_design_overrides(): array {
		$stored = \get_option( self::OPTION_DESIGN_OVERRIDES, [] );
		
		return self::sanitize_design_overrides( \is_array( $stored ) ? $stored : [] );
	}
	
	/**
	 * Get the effective override tokens for the current template context.
	 *
	 * The per-design tier sits on top of defaults, network, site, and
	 * theme values — but it is site-level configuration, so it does
	 * not apply while the brand section is network-locked.
	 *
	 * @return	array<string, string> The partial brand tokens (may be empty)
	 */
	public static function get_context_overrides(): array {
		if ( self::$template_context === '' || Multisite::is_locked( 'brand' ) ) {
			return [];
		}
		
		return self::get_design_overrides()[ self::$template_context ] ?? [];
	}
	
	/**
	 * Sanitize the per-design token overrides from settings input.
	 *
	 * Empty values mean "no override" and are dropped; templates
	 * without any override are dropped entirely.
	 *
	 * @param	mixed	$input The raw settings input
	 * @return	array<string, array<string, string>> Template id => partial brand tokens
	 */
	public static function sanitize_design_overrides( mixed $input ): array {
		if ( ! \is_array( $input ) ) {
			return [];
		}
		
		$sanitized = [];
		
		foreach ( $input as $template_id => $tokens ) {
			// reject malformed ids instead of silently mangling them;
			// well-formed ids are not checked against the registry here
			// (the registry itself resolves tokens, which would recurse)
			if ( \sanitize_key( (string) $template_id ) !== $template_id || $template_id === '' ) {
				continue;
			}
			
			if ( ! \is_array( $tokens ) ) {
				continue;
			}
			
			$overrides = [];
			
			foreach ( self::OVERRIDABLE_TOKENS as $token_key ) {
				$value = (string) ( $tokens[ $token_key ] ?? '' );
				
				if ( $value === '' ) {
					continue;
				}
				
				if ( \str_ends_with( $token_key, '_font' ) ) {
					$value = Fonts::get_path( $value ) !== ''
						? $value
						: '';
				}
				else {
					$value = Template_Model::sanitize_color( $value, '' );
				}
				
				if ( $value !== '' ) {
					$overrides[ $token_key ] = $value;
				}
			}
			
			if ( $overrides !== [] ) {
				$sanitized[ $template_id ] = $overrides;
			}
		}
		
		return $sanitized;
	}
	
	/**
	 * Sanitize layout tokens from settings input.
	 *
	 * @param	mixed	$input The raw settings input
	 * @return	array{
	 * 	logo_position: string,
	 * 	show_logo: bool,
	 * 	show_site_name: bool,
	 * 	site_name_position: string,
	 * 	text_align: string
	 * } The sanitized layout tokens
	 */
	public static function sanitize_layout( mixed $input ): array {
		$input = \is_array( $input ) ? $input : [];
		$site_name_position = (string) ( $input['site_name_position'] ?? 'top-left' );
		$logo_position = (string) ( $input['logo_position'] ?? 'top-right' );
		$text_align = (string) ( $input['text_align'] ?? 'left' );
		
		return [
			'logo_position' => \in_array( $logo_position, self::POSITIONS, true )
				? $logo_position
				: 'top-right',
			'show_logo' => ! isset( $input['show_logo'] ) || (bool) $input['show_logo'],
			'show_site_name' => ! isset( $input['show_site_name'] ) || (bool) $input['show_site_name'],
			'site_name_position' => \in_array( $site_name_position, self::POSITIONS, true )
				? $site_name_position
				: 'top-left',
			'text_align' => \in_array( $text_align, self::TEXT_ALIGNMENTS, true )
				? $text_align
				: 'left',
		];
	}
}
