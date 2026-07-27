<?php
declare(strict_types=1);

/**
 * Global API functions for Image Socialiser.
 *
 * @package	happyhappy\ImageSocialiser
 */

use happyhappy\ImageSocialiser\Template\Design_Packs;

if ( ! function_exists( 'image_socialiser_register_design' ) ) {
	/**
	 * Register a design pack from its manifest path.
	 *
	 * Call on 'init', 'after_setup_theme', or the canonical
	 * 'image_socialiser_register_designs' action:
	 *
	 *     image_socialiser_register_design( __DIR__ . '/social-designs/poster/design.json' );
	 *
	 * @since	0.18.0
	 *
	 * @param	string	$manifest_path Absolute path to a design.json file
	 * @return	string|\WP_Error The registered design id, or WP_Error on failure
	 */
	function image_socialiser_register_design( string $manifest_path ): string|WP_Error {
		return Design_Packs::register( $manifest_path );
	}
}

if ( ! function_exists( 'image_socialiser_register_design_collection' ) ) {
	/**
	 * Register several design packs listed in one index.json file.
	 *
	 * The index is a JSON array of design.json paths relative to the
	 * index file — one explicit entry point, no directory scanning.
	 *
	 * @since	0.18.0
	 *
	 * @param	string	$index_json_path Absolute path to an index.json file
	 * @return	array<int, string|\WP_Error> Registered ids or errors, per entry
	 */
	function image_socialiser_register_design_collection( string $index_json_path ): array {
		return Design_Packs::register_collection( $index_json_path );
	}
}
