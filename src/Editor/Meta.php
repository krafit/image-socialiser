<?php
declare(strict_types=1);

namespace happyhappy\ImageSocialiser\Editor;

use happyhappy\ImageSocialiser\Generation\Post_Types;
use happyhappy\ImageSocialiser\Generation\Resolver;
use happyhappy\ImageSocialiser\Template\Binding;

// prevent direct file access
if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the editable post meta for the block editor.
 *
 * All fields are saved with the post via REST, so the existing
 * save_post trigger covers changes to them without extra endpoints.
 * The generated status/hash meta is deliberately not registered here;
 * the editor reads it through the REST status endpoint instead.
 *
 * @author	Simon Kraft
 * @license	GPL2
 * @package	happyhappy\ImageSocialiser
 */
final class Meta {
	/**
	 * Initialize the meta registration.
	 */
	public static function init(): void {
		\add_action( 'init', [ self::class, 'register' ], 20 );
	}
	
	/**
	 * Register the post meta for all supported post types.
	 */
	public static function register(): void {
		$fields = [
			Binding::META_SUBTITLE => [
				'sanitize_callback' => 'sanitize_text_field',
				'type' => 'string',
			],
			Binding::META_TITLE => [
				'sanitize_callback' => 'sanitize_text_field',
				'type' => 'string',
			],
			Resolver::META_OVERRIDE_ID => [
				'sanitize_callback' => 'absint',
				'type' => 'integer',
			],
		];
		
		foreach ( Post_Types::get_supported() as $post_type ) {
			foreach ( $fields as $meta_key => $field ) {
				\register_post_meta(
					$post_type,
					$meta_key,
					[
						'auth_callback' => static function( bool $allowed, string $meta_key, int $post_id ): bool {
							return \current_user_can( 'edit_post', $post_id );
						},
						'sanitize_callback' => $field['sanitize_callback'],
						'show_in_rest' => true,
						'single' => true,
						'type' => $field['type'],
					]
				);
			}
		}
	}
}
