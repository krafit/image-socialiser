<?php
declare(strict_types=1);

namespace happyhappy\ImageSocialiser\Template;

use happyhappy\ImageSocialiser\Rendering\Fonts;

/**
 * Theme support: themes hand design values over in code.
 *
 * Declared via add_theme_support( 'image-socialiser', [ … ] ). Partial
 * by design — only declared keys are taken over; everything else stays
 * user-configurable. The matching settings controls render disabled
 * (never removed) with a "Managed by your theme" notice.
 *
 * The theme-support array carries *values*; assets are registered via
 * the existing filters (image_socialiser_fonts for font files,
 * image_socialiser_templates for whole designs).
 *
 * @author	Simon Kraft
 * @license	GPL2
 * @package	happyhappy\ImageSocialiser
 */
final class Theme_Support {
	/**
	 * @var	string The theme support feature name.
	 */
	public const string FEATURE = 'image-socialiser';
	
	/**
	 * @var	array<string, string> Theme color keys => brand token keys.
	 */
	private const array COLOR_MAP = [
		'background_from' => 'background_from',
		'background_to' => 'background_to',
		'muted' => 'muted_color',
		'site_name' => 'site_name_color',
		'subtitle' => 'subtitle_color',
		'text' => 'text_color',
	];
	
	/**
	 * @var	array<string, string> Theme font keys => brand token keys.
	 */
	private const array FONT_MAP = [
		'body' => 'body_font',
		'heading' => 'heading_font',
	];
	
	/**
	 * Check whether the theme covers a value.
	 *
	 * Dotted keys address nested values, e.g. 'colors.text' or
	 * 'layout.text_align'; plain keys address top-level ones
	 * ('logo', 'template', 'background_image', 'font_sizes').
	 *
	 * @param	string	$key The (dotted) key
	 * @return	bool Whether the theme declares the value
	 */
	public static function covers( string $key ): bool {
		$config = self::get_config();
		$path = \explode( '.', $key );
		
		foreach ( $path as $segment ) {
			if ( ! \is_array( $config ) || ! \array_key_exists( $segment, $config ) ) {
				return false;
			}
			
			$config = $config[ $segment ];
		}
		
		return true;
	}
	
	/**
	 * Get the theme's background image.
	 *
	 * Accepts an attachment ID or a file path (theme asset).
	 *
	 * @return	array{id: int, path: string} The background source (id 0 / empty path when unset)
	 */
	public static function get_background_image(): array {
		$config = self::get_config();
		$value = $config['background_image'] ?? null;
		
		if ( \is_numeric( $value ) && (int) $value > 0 ) {
			return [
				'id' => (int) $value,
				'path' => '',
			];
		}
		
		if ( \is_string( $value ) && $value !== '' && \is_readable( $value ) ) {
			return [
				'id' => 0,
				'path' => $value,
			];
		}
		
		return [
			'id' => 0,
			'path' => '',
		];
	}
	
	/**
	 * Get the theme's brand token overrides (colors, fonts, logo).
	 *
	 * Only declared and valid values are returned.
	 *
	 * @return	array The partial brand tokens
	 */
	public static function get_brand_overrides(): array {
		$config = self::get_config();
		$overrides = [];
		$colors = \is_array( $config['colors'] ?? null ) ? $config['colors'] : [];
		$fonts = \is_array( $config['fonts'] ?? null ) ? $config['fonts'] : [];
		
		foreach ( self::COLOR_MAP as $theme_key => $token_key ) {
			if ( ! \array_key_exists( $theme_key, $colors ) ) {
				continue;
			}
			
			$color = Template_Model::sanitize_color( $colors[ $theme_key ], '' );
			
			if ( $color !== '' ) {
				$overrides[ $token_key ] = $color;
			}
		}
		
		foreach ( self::FONT_MAP as $theme_key => $token_key ) {
			if ( ! \array_key_exists( $theme_key, $fonts ) ) {
				continue;
			}
			
			$font_id = \sanitize_key( (string) $fonts[ $theme_key ] );
			
			if ( Fonts::get_path( $font_id ) !== '' ) {
				$overrides[ $token_key ] = $font_id;
			}
		}
		
		if ( \array_key_exists( 'logo', $config ) ) {
			$overrides['logo'] = $config['logo'] === 'none'
				? [
					'id' => 0,
					'mode' => 'none',
				]
				: [
					'id' => \max( 0, (int) $config['logo'] ),
					'mode' => 'custom',
				];
		}
		
		return $overrides;
	}
	
	/**
	 * Get the theme's font size overrides.
	 *
	 * Shape: [ 'site_name' => int, 'title' => [ 'max' => int, 'min' => int ] ]
	 *
	 * @return	array The declared font sizes (validated, may be empty)
	 */
	public static function get_font_sizes(): array {
		$config = self::get_config();
		$declared = \is_array( $config['font_sizes'] ?? null ) ? $config['font_sizes'] : [];
		$sizes = [];
		
		if ( \is_array( $declared['title'] ?? null ) ) {
			$min = self::clamp_size( $declared['title']['min'] ?? 0 );
			$max = self::clamp_size( $declared['title']['max'] ?? 0 );
			
			if ( $min > 0 && $max >= $min ) {
				$sizes['title'] = [
					'max' => $max,
					'min' => $min,
				];
			}
		}
		
		if ( isset( $declared['site_name'] ) ) {
			$size = self::clamp_size( $declared['site_name'] );
			
			if ( $size > 0 ) {
				$sizes['site_name'] = $size;
			}
		}
		
		return $sizes;
	}
	
	/**
	 * Get the theme's layout token overrides.
	 *
	 * Partial: only declared keys are returned, each validated against
	 * the same rules as the settings.
	 *
	 * @return	array The partial layout tokens
	 */
	public static function get_layout_overrides(): array {
		$config = self::get_config();
		$declared = \is_array( $config['layout'] ?? null ) ? $config['layout'] : [];
		$overrides = [];
		
		foreach ( [ 'logo_position', 'site_name_position' ] as $position_key ) {
			if ( isset( $declared[ $position_key ] )
				&& \in_array( $declared[ $position_key ], Design::POSITIONS, true )
			) {
				$overrides[ $position_key ] = (string) $declared[ $position_key ];
			}
		}
		
		foreach ( [ 'show_logo', 'show_site_name' ] as $toggle_key ) {
			if ( \array_key_exists( $toggle_key, $declared ) ) {
				$overrides[ $toggle_key ] = (bool) $declared[ $toggle_key ];
			}
		}
		
		if ( isset( $declared['text_align'] )
			&& \in_array( $declared['text_align'], Design::TEXT_ALIGNMENTS, true )
		) {
			$overrides['text_align'] = (string) $declared['text_align'];
		}
		
		return $overrides;
	}
	
	/**
	 * Get the theme's site-wide default template identifier.
	 *
	 * @return	string The template identifier or an empty string
	 */
	public static function get_template(): string {
		$config = self::get_config();
		
		return \sanitize_key( (string) ( $config['template'] ?? '' ) );
	}
	
	/**
	 * Get the display name of the active theme for lock-out notices.
	 *
	 * @return	string The theme name
	 */
	public static function get_theme_name(): string {
		$theme = \wp_get_theme();
		
		return (string) $theme->get( 'Name' );
	}
	
	/**
	 * Register the theme support integration.
	 */
	public static function init(): void {
		\add_filter(
			'image_socialiser_default_template_id',
			[ self::class, 'filter_default_template_id' ]
		);
	}
	
	/**
	 * Let a theme-declared template become the site-wide default.
	 *
	 * @param	string	$template_id The default template identifier
	 * @return	string The filtered identifier
	 */
	public static function filter_default_template_id( string $template_id ): string {
		$theme_template = self::get_template();
		
		return $theme_template !== '' ? $theme_template : $template_id;
	}
	
	/**
	 * Clamp a font size to a sane range.
	 *
	 * @param	mixed	$size The raw size
	 * @return	int The clamped size (0 when invalid)
	 */
	private static function clamp_size( mixed $size ): int {
		$size = (int) $size;
		
		return $size >= 10 && $size <= 200 ? $size : 0;
	}
	
	/**
	 * Get the raw theme support configuration.
	 *
	 * @return	array The configuration array (empty when undeclared)
	 */
	private static function get_config(): array {
		$support = \get_theme_support( self::FEATURE );
		
		if ( ! \is_array( $support ) || ! isset( $support[0] ) || ! \is_array( $support[0] ) ) {
			return [];
		}
		
		return $support[0];
	}
}
