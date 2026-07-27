<?php
declare(strict_types=1);

namespace happyhappy\ImageSocialiser\Editor;

use happyhappy\ImageSocialiser\Generation\Post_Types;
use happyhappy\ImageSocialiser\Generation\Resolver;
use happyhappy\ImageSocialiser\Plugin;
use happyhappy\ImageSocialiser\Rendering\Fonts;
use happyhappy\ImageSocialiser\Template\Binding;
use happyhappy\ImageSocialiser\Template\Brand;
use happyhappy\ImageSocialiser\Template\Design;
use happyhappy\ImageSocialiser\Template\Template_Registry;
use WP_Post;

/**
 * Loads the block editor assets.
 *
 * Assets are scoped to the block editor of supported post types only.
 * The panel script is build-less (wp.element, no JSX/toolchain) and
 * receives the template models, font URLs and resolved binding values
 * for the live SVG preview.
 *
 * @author	Simon Kraft
 * @license	GPL2
 * @package	happyhappy\ImageSocialiser
 */
final class Assets {
	/**
	 * @var	string Script handle of the shared preview module.
	 */
	public const string PREVIEW_HANDLE = 'image-socialiser-preview';
	
	/**
	 * Initialize the asset loading.
	 */
	public static function init(): void {
		\add_action( 'enqueue_block_editor_assets', [ self::class, 'enqueue' ] );
	}
	
	/**
	 * Enqueue the editor panel script with its data.
	 */
	public static function enqueue(): void {
		$post = \get_post();
		
		if ( ! $post instanceof WP_Post || ! Post_Types::is_supported( $post->post_type ) ) {
			return;
		}
		
		$handle = 'image-socialiser-editor';
		$plugin_file = Plugin::get_instance()->plugin_file;
		
		\wp_enqueue_script(
			self::PREVIEW_HANDLE,
			\plugin_dir_url( $plugin_file ) . 'assets/js/preview.js',
			[ 'wp-element' ],
			Plugin::VERSION,
			true
		);
		\wp_enqueue_script(
			$handle,
			\plugin_dir_url( $plugin_file ) . 'assets/js/editor.js',
			[
				self::PREVIEW_HANDLE,
				'wp-api-fetch',
				'wp-block-editor',
				'wp-components',
				'wp-core-data',
				'wp-data',
				'wp-editor',
				'wp-element',
				'wp-i18n',
				'wp-plugins',
			],
			Plugin::VERSION,
			true
		);
		\wp_set_script_translations(
			$handle,
			'image-socialiser',
			\plugin_dir_path( $plugin_file ) . 'languages'
		);
		\wp_add_inline_script(
			$handle,
			'var imageSocialiserEditor = ' . (string) \wp_json_encode( self::get_editor_data( $post ) ) . ';',
			'before'
		);
	}
	
	/**
	 * Collect the data the editor panel needs.
	 *
	 * @param	\WP_Post	$post The edited post
	 * @return	array The editor data
	 */
	private static function get_editor_data( WP_Post $post ): array {
		$binding = new Binding( $post );
		// scaling boundary: every registered template ships resolved in
		// the editor payload — fine for the built-in set; if third-party
		// registrations grow large, switch to lazy REST loading
		// only the resolved template is needed for the editor preview;
		// the per-post picker was retired in 1.0.0
		$resolved = Template_Registry::resolve_for_post( $post );
		$templates = [
			$resolved->get_id() => $resolved->to_public_array(),
		];
		
		$brand = Brand::get_tokens();
		
		return [
			'binding' => [
				'author' => $binding->get_text( 'author' ),
				'background_url' => Design::get_background_url(),
				'category' => $binding->get_text( 'category' ),
				'cover_art_url' => self::get_attachment_url( (int) $brand['cover_art_id'] ),
				'date' => $binding->get_text( 'date' ),
				'logo_url' => self::get_attachment_url( Design::resolve_logo_id() ),
				'site_name' => $binding->get_text( 'site_name' ),
				'subtitle_default' => self::get_subtitle_default( $post ),
			],
			'fonts' => Fonts::get_all_urls(),
			'metaKeys' => [
				'override' => Resolver::META_OVERRIDE_ID,
				'subtitle' => Binding::META_SUBTITLE,
				'title' => Binding::META_TITLE,
			],
			'postId' => $post->ID,
			'restNamespace' => Rest_Controller::ROUTE_NAMESPACE,
			'defaultTemplateId' => $resolved->get_id(),
			'templates' => $templates,
		];
	}
	
	/**
	 * Get the full-size URL of an attachment.
	 *
	 * @param	int	$attachment_id The attachment ID
	 * @return	string The URL or an empty string
	 */
	private static function get_attachment_url( int $attachment_id ): string {
		if ( $attachment_id === 0 ) {
			return '';
		}
		
		$url = \wp_get_attachment_image_url( $attachment_id, 'full' );
		
		return \is_string( $url ) ? $url : '';
	}
	
	/**
	 * Get the site-wide subtitle default for the preview placeholder.
	 *
	 * @param	\WP_Post	$post The edited post
	 * @return	string The default subtitle (may be empty)
	 */
	private static function get_subtitle_default( WP_Post $post ): string {
		return ( new Binding( $post ) )->get_subtitle_fallback();
	}
}
