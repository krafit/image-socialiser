<?php
declare(strict_types=1);

namespace happyhappy\ImageSocialiser\Template;

use happyhappy\ImageSocialiser\Multisite\Multisite;
use happyhappy\ImageSocialiser\Rendering\Fonts;

// prevent direct file access
if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site-wide brand tokens (colors, fonts, logo).
 *
 * Set once in the settings and consumed by templates, so a single
 * design change propagates to every generated image.
 *
 * @author	Simon Kraft
 * @license	GPL2
 * @package	happyhappy\ImageSocialiser
 */
final class Brand {
	/**
	 * @var	string Option name for the brand tokens.
	 */
	public const string OPTION_NAME = 'image_socialiser_brand';
	
	/**
	 * Bump the global design version.
	 *
	 * The design version is part of every content hash, so bumping it
	 * invalidates all generated images at once.
	 */
	public static function bump_design_version(): void {
		Design::bump_design_version();
	}
	
	/**
	 * Sanitize brand tokens from settings input.
	 *
	 * @param	mixed	$input The raw settings input
	 * @return	array The sanitized brand tokens
	 */
	public static function sanitize( mixed $input ): array {
		if ( ! \is_array( $input ) ) {
			return [];
		}
		
		$defaults = self::get_defaults();
		$tokens = [];
		
		foreach ( [ 'background_from', 'background_to', 'muted_color', 'text_color' ] as $color_key ) {
			$tokens[ $color_key ] = Template_Model::sanitize_color(
				$input[ $color_key ] ?? null,
				$defaults[ $color_key ]
			);
		}
		
		foreach ( [ 'site_name_color', 'subtitle_color' ] as $color_key ) {
			// optional colors: empty means "same as the muted color"
			$tokens[ $color_key ] = empty( $input[ $color_key . '_auto' ] )
				? Template_Model::sanitize_color( $input[ $color_key ] ?? null, '' )
				: '';
		}
		
		foreach ( [ 'body_font', 'heading_font' ] as $font_key ) {
			$font_id = (string) ( $input[ $font_key ] ?? '' );
			$tokens[ $font_key ] = Fonts::get_path( $font_id ) !== '' ? $font_id : $defaults[ $font_key ];
		}
		
		$tokens['logo'] = self::sanitize_logo( $input['logo'] ?? null );
		$tokens['background_id'] = \max( 0, (int) ( $input['background_id'] ?? 0 ) );
		$tokens['cover_art_id'] = \max( 0, (int) ( $input['cover_art_id'] ?? 0 ) );
		
		return $tokens;
	}
	
	/**
	 * Get the sanitized brand tokens from the site settings only.
	 *
	 * No theme support applied — this is what the settings screen
	 * round-trips for locked controls, so removing a theme restores
	 * the user's own values.
	 *
	 * @return	array The site-level brand tokens
	 */
	public static function get_site_tokens(): array {
		$stored = \get_option( self::OPTION_NAME, [] );
		
		return self::build_tokens( \is_array( $stored ) ? $stored : [] );
	}
	
	/**
	 * Build sanitized brand tokens from a stored value.
	 *
	 * Shared by the site option and the network option.
	 *
	 * @param	array	$stored The stored (raw) value
	 * @return	array The sanitized brand tokens
	 */
	public static function build_tokens( array $stored ): array {
		$defaults = self::get_defaults();
		
		// migrate the pre-0.8.0 logo_id shape on read (before merging,
		// since the defaults always define the new 'logo' key)
		if ( ! isset( $stored['logo'] ) && isset( $stored['logo_id'] ) ) {
			$stored['logo'] = $stored['logo_id'];
		}
		
		unset( $stored['logo_id'] );
		$tokens = \array_merge( $defaults, $stored );
		
		foreach ( [ 'background_from', 'background_to', 'muted_color', 'text_color' ] as $color_key ) {
			$tokens[ $color_key ] = Template_Model::sanitize_color(
				$tokens[ $color_key ],
				$defaults[ $color_key ]
			);
		}
		
		foreach ( [ 'site_name_color', 'subtitle_color' ] as $color_key ) {
			$tokens[ $color_key ] = Template_Model::sanitize_color( $tokens[ $color_key ] ?? '', '' );
		}
		
		foreach ( [ 'body_font', 'heading_font' ] as $font_key ) {
			if ( Fonts::get_path( (string) $tokens[ $font_key ] ) === '' ) {
				$tokens[ $font_key ] = $defaults[ $font_key ];
			}
		}
		
		$tokens['logo'] = self::sanitize_logo( $tokens['logo'] ?? null );
		$tokens['background_id'] = \max( 0, (int) ( $tokens['background_id'] ?? 0 ) );
		$tokens['cover_art_id'] = \max( 0, (int) ( $tokens['cover_art_id'] ?? 0 ) );
		
		return $tokens;
	}
	
	/**
	 * Sanitize the tri-state logo token, migrating the legacy shape.
	 *
	 * @param	mixed	$logo The raw logo input (array, or a legacy attachment ID)
	 * @return	array{id: int, mode: string} The sanitized logo token
	 */
	public static function sanitize_logo( mixed $logo ): array {
		// legacy shape from before 0.8.0: bare attachment ID
		if ( \is_numeric( $logo ) ) {
			$id = \max( 0, (int) $logo );
			
			return [
				'id' => $id,
				'mode' => $id > 0 ? 'custom' : 'auto',
			];
		}
		
		$logo = \is_array( $logo ) ? $logo : [];
		$mode = (string) ( $logo['mode'] ?? 'auto' );
		
		if ( ! \in_array( $mode, [ 'auto', 'custom', 'none' ], true ) ) {
			$mode = 'auto';
		}
		
		return [
			'id' => \max( 0, (int) ( $logo['id'] ?? 0 ) ),
			'mode' => $mode,
		];
	}
	
	/**
	 * Get the default brand tokens.
	 *
	 * @return	array{
	 * 	background_from: string,
	 * 	background_to: string,
	 * 	body_font: string,
	 * 	heading_font: string,
	 * 	logo_id: int,
	 * 	muted_color: string,
	 * 	text_color: string
	 * } The default brand tokens
	 */
	private static function get_defaults(): array {
		return [
			'background_from' => '#1e293b',
			'background_id' => 0,
			'background_to' => '#0f172a',
			'body_font' => 'inter-regular',
			'cover_art_id' => 0,
			'heading_font' => 'inter-bold',
			'logo' => [
				'id' => 0,
				'mode' => 'auto',
			],
			'muted_color' => '#94a3b8',
			'site_name_color' => '',
			'subtitle_color' => '',
			'text_color' => '#ffffff',
		];
	}
	
	/**
	 * Get the sanitized brand tokens.
	 *
	 * @return	array{
	 * 	background_from: string,
	 * 	background_id: int,
	 * 	background_to: string,
	 * 	body_font: string,
	 * 	cover_art_id: int,
	 * 	heading_font: string,
	 * 	logo: array{id: int, mode: string},
	 * 	muted_color: string,
	 * 	text_color: string
	 * } The brand tokens
	 */
	public static function get_tokens(): array {
		$resolution = Multisite::resolve_section(
			'brand',
			null,
			\is_array( \get_option( self::OPTION_NAME, null ) )
		);
		$tokens = $resolution['network']
			? self::build_tokens( (array) $resolution['value'] )
			: self::get_site_tokens();
		
		if ( ! $resolution['locked'] ) {
			// theme support beats site settings for declared keys —
			// but a network lock beats theme support
			$tokens = \array_merge( $tokens, Theme_Support::get_brand_overrides() );
		}
		
		// per-design overrides are the top tier (for the template a
		// resolution currently runs for; empty outside builders)
		$tokens = \array_merge( $tokens, Design::get_context_overrides() );
		
		// optional colors inherit the muted color when unset
		foreach ( [ 'site_name_color', 'subtitle_color' ] as $color_key ) {
			if ( $tokens[ $color_key ] === '' ) {
				$tokens[ $color_key ] = $tokens['muted_color'];
			}
		}
		
		/**
		 * Filter the brand tokens.
		 *
		 * @param	array	$tokens The sanitized brand tokens
		 */
		return (array) \apply_filters( 'image_socialiser_brand_tokens', $tokens );
	}
}
