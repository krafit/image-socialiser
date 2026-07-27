<?php
declare(strict_types=1);

namespace happyhappy\ImageSocialiser\Editor;

use happyhappy\ImageSocialiser\Admin\Settings;
use happyhappy\ImageSocialiser\Generation\Context;
use happyhappy\ImageSocialiser\Generation\Resolver;
use happyhappy\ImageSocialiser\Plugin;
use happyhappy\ImageSocialiser\Template\Binding;

/**
 * Term edit screen fields.
 *
 * Mirrors the post editor panel for terms of enabled taxonomies: a
 * custom image title, a custom subtitle, and a manual image override
 * (all term meta). Saving re-triggers generation via the scheduler's
 * edited_term hook.
 *
 * @author	Simon Kraft
 * @license	GPL2
 * @package	happyhappy\ImageSocialiser
 */
final class Term_Fields {
	/**
	 * @var	string The nonce action for saving the fields.
	 */
	public const string NONCE_ACTION = 'image_socialiser_term_fields';
	
	/**
	 * Initialize the term fields.
	 */
	public static function init(): void {
		\add_action( 'admin_init', [ self::class, 'register_hooks' ] );
		\add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
	}
	
	/**
	 * Enqueue the media picker assets on term edit screens.
	 *
	 * @param	string	$hook_suffix The current admin page
	 */
	public static function enqueue( string $hook_suffix ): void {
		if ( $hook_suffix !== 'term.php' ) {
			return;
		}
		
		$screen = \get_current_screen();
		
		if ( $screen === null || ! Context::is_taxonomy_enabled( (string) $screen->taxonomy ) ) {
			return;
		}
		
		\wp_enqueue_media();
		\wp_enqueue_style(
			'image-socialiser-admin',
			\plugin_dir_url( Plugin::get_instance()->plugin_file ) . 'assets/css/admin.css',
			[],
			Plugin::VERSION
		);
		\wp_enqueue_script(
			'image-socialiser-admin',
			\plugin_dir_url( Plugin::get_instance()->plugin_file ) . 'assets/js/admin.js',
			[],
			Plugin::VERSION,
			true
		);
	}
	
	/**
	 * Register the render and save hooks for all enabled taxonomies.
	 */
	public static function register_hooks(): void {
		foreach ( Context::get_enabled_taxonomies() as $taxonomy ) {
			\add_action( $taxonomy . '_edit_form_fields', [ self::class, 'render' ] );
			\add_action( 'edited_' . $taxonomy, [ self::class, 'save' ], 10 );
		}
	}
	
	/**
	 * Render the fields on the term edit screen.
	 *
	 * @param	\WP_Term	$term The term being edited
	 */
	public static function render( \WP_Term $term ): void {
		$title = (string) \get_term_meta( $term->term_id, Binding::META_TITLE, true );
		$subtitle = (string) \get_term_meta( $term->term_id, Binding::META_SUBTITLE, true );
		$override_id = (int) \get_term_meta( $term->term_id, Resolver::META_OVERRIDE_ID, true );
		
		\wp_nonce_field( self::NONCE_ACTION, 'image_socialiser_term_nonce' );
		echo '<tr class="form-field"><th scope="row"><label for="image-socialiser-term-title">'
			. \esc_html__( 'Social image title', 'image-socialiser' )
			. '</label></th><td>';
		\printf(
			'<input type="text" id="image-socialiser-term-title" name="image_socialiser_term_title" value="%1$s" placeholder="%2$s">',
			\esc_attr( $title ),
			\esc_attr( $term->name )
		);
		echo '<p class="description">'
			. \esc_html__( 'Used on the generated image instead of the term name.', 'image-socialiser' )
			. '</p></td></tr>';
		echo '<tr class="form-field"><th scope="row"><label for="image-socialiser-term-subtitle">'
			. \esc_html__( 'Social image subtitle', 'image-socialiser' )
			. '</label></th><td>';
		\printf(
			'<input type="text" id="image-socialiser-term-subtitle" name="image_socialiser_term_subtitle" value="%s">',
			\esc_attr( $subtitle )
		);
		echo '<p class="description">'
			. \esc_html__( 'Shown as the second line; defaults to the term description.', 'image-socialiser' )
			. '</p></td></tr>';
		echo '<tr class="form-field"><th scope="row">'
			. \esc_html__( 'Social image override', 'image-socialiser' )
			. '</th><td>';
		Settings::render_media_field( [
			'description' => \__( 'Skips generation and uses this image for the term archive.', 'image-socialiser' ),
			'name' => 'image_socialiser_term_override',
			'value' => $override_id,
		] );
		echo '</td></tr>';
	}
	
	/**
	 * Save the fields.
	 *
	 * Runs at priority 10 on edited_{taxonomy}, before the scheduler's
	 * generation trigger at 20, so the job sees the fresh values.
	 *
	 * @param	int	$term_id The term ID
	 */
	public static function save( int $term_id ): void {
		if ( ! isset( $_POST['image_socialiser_term_nonce'] )
			|| \wp_verify_nonce(
				\sanitize_key( (string) $_POST['image_socialiser_term_nonce'] ),
				self::NONCE_ACTION
			) === false
			|| ! \current_user_can( 'edit_term', $term_id )
		) {
			return;
		}
		
		$fields = [
			Binding::META_SUBTITLE => \sanitize_text_field(
				(string) \wp_unslash( $_POST['image_socialiser_term_subtitle'] ?? '' )
			),
			Binding::META_TITLE => \sanitize_text_field(
				(string) \wp_unslash( $_POST['image_socialiser_term_title'] ?? '' )
			),
			Resolver::META_OVERRIDE_ID => (string) \max(
				0,
				(int) ( $_POST['image_socialiser_term_override'] ?? 0 )
			),
		];
		
		foreach ( $fields as $meta_key => $value ) {
			if ( $value === '' || $value === '0' ) {
				\delete_term_meta( $term_id, $meta_key );
				
				continue;
			}
			
			\update_term_meta( $term_id, $meta_key, $value );
		}
	}
}
