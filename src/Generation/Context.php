<?php
declare(strict_types=1);

namespace happyhappy\ImageSocialiser\Generation;

// prevent direct file access
if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Context image settings: taxonomies and special pages.
 *
 * Taxonomies are opt-in (a network can hold thousands of terms;
 * enabling them is a deliberate choice). Special pages (front, blog,
 * search, 404) and post type archives are toggled individually.
 * Search, 404, and blog images are static by design — one image per
 * design, no per-query content.
 *
 * @author	Simon Kraft
 * @license	GPL2
 * @package	happyhappy\ImageSocialiser
 */
final class Context {
	/**
	 * @var	string Option name of the context settings.
	 */
	public const string OPTION_NAME = 'image_socialiser_context';
	
	/**
	 * @var	string[] The toggleable special groups.
	 */
	public const array SPECIAL_KEYS = [ '404', 'archives', 'blog', 'front', 'search' ];
	
	/**
	 * Get the enabled taxonomies.
	 *
	 * @return	string[] The taxonomy names (validated against public taxonomies)
	 */
	public static function get_enabled_taxonomies(): array {
		$stored = self::get_settings()['taxonomies'];
		$public = \get_taxonomies( [ 'public' => true ] );
		
		return \array_values( \array_intersect( $stored, \array_keys( $public ) ) );
	}
	
	/**
	 * Get the sanitized context settings.
	 *
	 * @return	array{specials: array<string, array{enabled: bool, subtitle: string, template: string, title: string}>, taxonomies: string[], taxonomy_templates: array<string, string>} The settings
	 */
	public static function get_settings(): array {
		$stored = \get_option( self::OPTION_NAME, [] );
		
		return self::sanitize( \is_array( $stored ) ? $stored : [] );
	}
	
	/**
	 * Get the default template id for a special kind.
	 *
	 * 'post_type_archive' maps to the 'archives' group.
	 *
	 * @param	string	$kind The special kind or 'post_type_archive'
	 * @return	string The template id ('' = site default)
	 */
	public static function get_special_template( string $kind ): string {
		$key = $kind === 'post_type_archive' ? 'archives' : $kind;
		
		return self::get_settings()['specials'][ $key ]['template'] ?? '';
	}
	
	/**
	 * Get the default template id for a taxonomy.
	 *
	 * @param	string	$taxonomy The taxonomy name
	 * @return	string The template id ('' = site default)
	 */
	public static function get_taxonomy_template( string $taxonomy ): string {
		return self::get_settings()['taxonomy_templates'][ $taxonomy ] ?? '';
	}
	
	/**
	 * Get the custom title for a special kind.
	 *
	 * 'post_type_archive' maps to the 'archives' group.
	 *
	 * @param	string	$kind The special kind or 'post_type_archive'
	 * @return	string The custom title ('' = use the derived default)
	 */
	public static function get_special_title( string $kind ): string {
		$key = $kind === 'post_type_archive' ? 'archives' : $kind;
		
		return (string) ( self::get_settings()['specials'][ $key ]['title'] ?? '' );
	}
	
	/**
	 * Get the custom subtitle for a special kind.
	 *
	 * 'post_type_archive' maps to the 'archives' group.
	 *
	 * @param	string	$kind The special kind or 'post_type_archive'
	 * @return	string The custom subtitle ('' = use the derived default)
	 */
	public static function get_special_subtitle( string $kind ): string {
		$key = $kind === 'post_type_archive' ? 'archives' : $kind;
		
		return (string) ( self::get_settings()['specials'][ $key ]['subtitle'] ?? '' );
	}
	
	/**
	 * Check whether a special group is enabled.
	 *
	 * @param	string	$kind The special kind ('front', 'blog', 'search', '404', 'archives')
	 * @return	bool Whether it is enabled
	 */
	public static function is_special_enabled( string $kind ): bool {
		return self::get_settings()['specials'][ $kind ]['enabled'] ?? false;
	}
	
	/**
	 * Check whether a taxonomy is enabled.
	 *
	 * @param	string	$taxonomy The taxonomy name
	 * @return	bool Whether it is enabled
	 */
	public static function is_taxonomy_enabled( string $taxonomy ): bool {
		return \in_array( $taxonomy, self::get_enabled_taxonomies(), true );
	}
	
	/**
	 * Sanitize the context settings.
	 *
	 * @param	mixed	$input The raw input
	 * @return	array{specials: array<string, array{enabled: bool, subtitle: string, template: string, title: string}>, taxonomies: string[], taxonomy_templates: array<string, string>} The sanitized settings
	 */
	public static function sanitize( mixed $input ): array {
		$input = \is_array( $input ) ? $input : [];
		$taxonomies = [];
		
		foreach ( (array) ( $input['taxonomies'] ?? [] ) as $taxonomy ) {
			$taxonomy = \sanitize_key( (string) $taxonomy );
			
			if ( $taxonomy !== '' ) {
				$taxonomies[] = $taxonomy;
			}
		}
		
		$taxonomy_templates = [];
		
		foreach ( (array) ( $input['taxonomy_templates'] ?? [] ) as $taxonomy => $template_id ) {
			$taxonomy = \sanitize_key( (string) $taxonomy );
			$template_id = \sanitize_key( (string) $template_id );
			
			if ( $taxonomy !== '' && $template_id !== '' ) {
				$taxonomy_templates[ $taxonomy ] = $template_id;
			}
		}
		
		$specials = [];
		
		foreach ( self::SPECIAL_KEYS as $key ) {
			$special = \is_array( $input['specials'][ $key ] ?? null ) ? $input['specials'][ $key ] : [];
			$specials[ $key ] = [
				'enabled' => ! empty( $special['enabled'] ),
				'subtitle' => \sanitize_text_field( (string) ( $special['subtitle'] ?? '' ) ),
				'template' => \sanitize_key( (string) ( $special['template'] ?? '' ) ),
				'title' => \sanitize_text_field( (string) ( $special['title'] ?? '' ) ),
			];
		}
		
		return [
			'specials' => $specials,
			'taxonomies' => \array_values( \array_unique( $taxonomies ) ),
			'taxonomy_templates' => $taxonomy_templates,
		];
	}
}
