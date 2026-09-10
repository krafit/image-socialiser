<?php
declare(strict_types=1);

namespace happyhappy\ImageSocialiser\Multisite;

use happyhappy\ImageSocialiser\Admin\Settings;
use happyhappy\ImageSocialiser\Generation\Generator;
use happyhappy\ImageSocialiser\Generation\Scheduler;
use happyhappy\ImageSocialiser\Plugin;
use happyhappy\ImageSocialiser\Rendering\Custom_Fonts;
use happyhappy\ImageSocialiser\Rendering\Fonts;
use happyhappy\ImageSocialiser\Template\Brand;
use happyhappy\ImageSocialiser\Template\Design;

// prevent direct file access
if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The network admin settings page.
 *
 * Holds network-wide defaults per section plus the per-section
 * override permissions, the network font library, and network tools
 * (regenerate all sites, network health).
 *
 * Media at network level is referenced by attachment IDs from the
 * main site's library (plain ID inputs — the media modal is not
 * reliably available in the network admin).
 *
 * @author	Simon Kraft
 * @license	GPL2
 * @package	happyhappy\ImageSocialiser
 */
final class Network_Settings {
	/**
	 * @var	string The network-admin-edit action for saving.
	 */
	public const string ACTION_SAVE = 'image_socialiser_network';
	
	/**
	 * @var	string The admin-post action for deleting a network font.
	 */
	public const string ACTION_DELETE_FONT = 'image_socialiser_network_delete_font';
	
	/**
	 * @var	string The admin-post action for regenerating all sites.
	 */
	public const string ACTION_REGENERATE = 'image_socialiser_network_regenerate';
	
	/**
	 * @var	string The admin-post action for uploading a network font.
	 */
	public const string ACTION_UPLOAD_FONT = 'image_socialiser_network_upload_font';
	
	/**
	 * @var	string The network settings page slug.
	 */
	public const string PAGE_SLUG = 'image-socialiser-network';
	
	/**
	 * Initialize the network settings.
	 */
	public static function init(): void {
		if ( ! Multisite::is_active() ) {
			return;
		}
		
		\add_action( 'network_admin_menu', [ self::class, 'register_page' ] );
		\add_action( 'network_admin_edit_' . self::ACTION_SAVE, [ self::class, 'handle_save' ] );
		\add_action( 'admin_post_' . self::ACTION_DELETE_FONT, [ self::class, 'handle_delete_font' ] );
		\add_action( 'admin_post_' . self::ACTION_REGENERATE, [ self::class, 'handle_regenerate' ] );
		\add_action( 'admin_post_' . self::ACTION_UPLOAD_FONT, [ self::class, 'handle_upload_font' ] );
	}
	
	/**
	 * Register the page under network admin > Settings.
	 */
	public static function register_page(): void {
		\add_submenu_page(
			'settings.php',
			\__( 'Social Images', 'image-socialiser' ),
			\__( 'Social Images', 'image-socialiser' ),
			'manage_network_options',
			self::PAGE_SLUG,
			[ self::class, 'render' ]
		);
	}
	
	/**
	 * Handle deleting a network font.
	 */
	public static function handle_delete_font(): void {
		if ( ! \current_user_can( 'manage_network_options' ) ) {
			\wp_die( \esc_html__( 'You are not allowed to do that.', 'image-socialiser' ) );
		}
		
		$font_id = \sanitize_key( (string) ( $_POST['font_id'] ?? '' ) );
		
		\check_admin_referer( self::ACTION_DELETE_FONT . '_' . $font_id );
		
		if ( Custom_Fonts::get_network_usage( $font_id ) !== '' ) {
			self::redirect( [ 'font-status' => 'in-use' ] );
		}
		
		self::redirect( [
			'font-status' => Custom_Fonts::delete_network( $font_id ) ? 'deleted' : 'not-found',
		] );
	}
	
	/**
	 * Handle the "Regenerate all sites" action.
	 *
	 * Iterates the network's sites and schedules each site's own bulk
	 * regeneration (Action Scheduler is blog-aware).
	 */
	public static function handle_regenerate(): void {
		if ( ! \current_user_can( 'manage_network_options' ) ) {
			\wp_die( \esc_html__( 'You are not allowed to do that.', 'image-socialiser' ) );
		}
		
		\check_admin_referer( self::ACTION_REGENERATE );
		( new Scheduler() )->schedule_network_regeneration();
		self::redirect( [ 'image-socialiser-regenerating' => '1' ] );
	}
	
	/**
	 * Handle saving all network sections.
	 */
	public static function handle_save(): void {
		if ( ! \current_user_can( 'manage_network_options' ) ) {
			\wp_die( \esc_html__( 'You are not allowed to do that.', 'image-socialiser' ) );
		}
		
		\check_admin_referer( self::ACTION_SAVE );
		
		$before = self::get_design_snapshot();
		
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- each section runs through its sanitizer below
		$brand = \is_array( $_POST['brand'] ?? null ) ? \wp_unslash( $_POST['brand'] ) : [];
		$layout = \is_array( $_POST['layout'] ?? null ) ? \wp_unslash( $_POST['layout'] ) : [];
		$content = \is_array( $_POST['content'] ?? null ) ? \wp_unslash( $_POST['content'] ) : [];
		$post_types = \is_array( $_POST['post_types'] ?? null ) ? \wp_unslash( $_POST['post_types'] ) : [];
		$fallbacks = \is_array( $_POST['fallbacks'] ?? null ) ? \wp_unslash( $_POST['fallbacks'] ) : [];
		$permissions = \is_array( $_POST['permissions'] ?? null ) ? \wp_unslash( $_POST['permissions'] ) : [];
		// phpcs:enable
		
		\update_site_option( Multisite::OPTION_PREFIX . 'brand', Brand::sanitize( $brand ) );
		\update_site_option( Multisite::OPTION_PREFIX . 'layout', Design::sanitize_layout( $layout ) );
		\update_site_option( Multisite::OPTION_PREFIX . 'content', Design::sanitize_content( $content ) );
		\update_site_option(
			Multisite::OPTION_PREFIX . 'post_types',
			Settings::sanitize_post_types( $post_types )
		);
		\update_site_option(
			Multisite::OPTION_PREFIX . 'fallbacks',
			Settings::sanitize_fallbacks( $fallbacks )
		);
		$sanitized_permissions = [];
		
		foreach ( Multisite::SECTIONS as $section ) {
			$sanitized_permissions[ $section ] = ( $permissions[ $section ] ?? 'site' ) === 'network'
				? 'network'
				: 'site';
		}
		
		\update_site_option( Multisite::OPTION_PERMISSIONS, $sanitized_permissions );
		$arguments = [ 'updated' => '1' ];
		
		// update_site_option fires no update_option_* hooks, so the
		// regular invalidation triggers never see network changes —
		// schedule the network regeneration here when design-relevant
		// sections changed (fallbacks resolve at request time and need
		// no re-render)
		if ( $before !== self::get_design_snapshot() ) {
			( new Scheduler() )->schedule_network_regeneration();
			$arguments['image-socialiser-regenerating'] = '1';
		}
		
		self::redirect( $arguments );
	}
	
	/**
	 * Handle uploading a network font.
	 */
	public static function handle_upload_font(): void {
		if ( ! \current_user_can( 'manage_network_options' ) ) {
			\wp_die( \esc_html__( 'You are not allowed to do that.', 'image-socialiser' ) );
		}
		
		\check_admin_referer( self::ACTION_UPLOAD_FONT );
		
		$file = $_FILES['image_socialiser_font'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$error = Custom_Fonts::validate_upload( \is_array( $file ) ? $file : [] );
		
		if ( $error !== '' ) {
			self::redirect( [ 'font-status' => $error ] );
		}
		
		$label = \sanitize_text_field(
			(string) \wp_unslash( $_POST['image_socialiser_font_label'] ?? '' )
		);
		
		if ( $label === '' ) {
			$label = \pathinfo( \sanitize_file_name( (string) $file['name'] ), \PATHINFO_FILENAME );
		}
		
		$stored = Custom_Fonts::store_network( (string) $file['tmp_name'], (string) $file['name'], $label );
		
		self::redirect( [ 'font-status' => $stored ? 'uploaded' : 'move-failed' ] );
	}
	
	/**
	 * Snapshot the design-relevant network options for change detection.
	 *
	 * Fallback images are excluded deliberately — they resolve at
	 * request time and require no re-render.
	 *
	 * @return	array The current values of the design-relevant sections
	 */
	private static function get_design_snapshot(): array {
		return [
			\get_site_option( Multisite::OPTION_PREFIX . 'brand' ),
			\get_site_option( Multisite::OPTION_PREFIX . 'content' ),
			\get_site_option( Multisite::OPTION_PREFIX . 'layout' ),
			\get_site_option( Multisite::OPTION_PREFIX . 'post_types' ),
			\get_site_option( Multisite::OPTION_PERMISSIONS ),
		];
	}
	
	/**
	 * Render the network settings page.
	 */
	public static function render(): void {
		if ( ! \current_user_can( 'manage_network_options' ) ) {
			return;
		}
		
		echo '<div class="wrap"><h1>' . \esc_html__( 'Social Images', 'image-socialiser' ) . '</h1>';
		self::render_notices();
		echo '<p class="description">'
			. \esc_html__(
				'Network defaults apply to every site until a site saves its own settings. Sections set to "Network only" always use the network values — they also override theme support on all sites.',
				'image-socialiser'
			)
			. '</p>';
		echo '<form action="' . \esc_url( \network_admin_url( 'edit.php?action=' . self::ACTION_SAVE ) ) . '" method="post">';
		\wp_nonce_field( self::ACTION_SAVE );
		self::render_brand_section();
		self::render_layout_section();
		self::render_content_section();
		self::render_post_types_section();
		self::render_fallbacks_section();
		self::render_permissions_section();
		\submit_button( \__( 'Save network settings', 'image-socialiser' ) );
		echo '</form>';
		self::render_fonts_section();
		self::render_tools_section();
		self::render_health_section();
		echo '</div>';
	}
	
	/**
	 * Render the brand section.
	 */
	private static function render_brand_section(): void {
		$stored = Multisite::get_network_option( 'brand' ) ?? [];
		$tokens = Brand::build_tokens( $stored );
		$color_labels = [
			'background_from' => \__( 'Background (from)', 'image-socialiser' ),
			'background_to' => \__( 'Background (to)', 'image-socialiser' ),
			'muted_color' => \__( 'Muted color', 'image-socialiser' ),
			'text_color' => \__( 'Text color', 'image-socialiser' ),
		];
		$font_labels = [
			'body_font' => \__( 'Body font', 'image-socialiser' ),
			'heading_font' => \__( 'Heading font', 'image-socialiser' ),
		];
		
		echo '<h2>' . \esc_html__( 'Brand', 'image-socialiser' ) . '</h2><table class="form-table">';
		
		foreach ( $color_labels as $key => $label ) {
			\printf(
				'<tr><th scope="row">%1$s</th><td><input type="color" name="brand[%2$s]" value="%3$s"></td></tr>',
				\esc_html( $label ),
				\esc_attr( $key ),
				\esc_attr( \substr( (string) $tokens[ $key ], 0, 7 ) )
			);
		}
		
		foreach ( $font_labels as $key => $label ) {
			echo '<tr><th scope="row">' . \esc_html( $label ) . '</th><td>';
			\printf( '<select name="brand[%s]">', \esc_attr( $key ) );
			
			// bundled + network fonts only (site fonts are per-site)
			$fonts = \array_merge(
				\array_keys( Fonts::BUNDLED ),
				\array_keys( Custom_Fonts::get_network_index() )
			);
			
			foreach ( $fonts as $font_id ) {
				// single-weight display faces are offered for headings only
				if ( $key === 'body_font'
					&& Fonts::is_heading_only( $font_id )
					&& $font_id !== (string) $tokens[ $key ]
				) {
					continue;
				}
				
				\printf(
					'<option value="%1$s" %2$s>%3$s</option>',
					\esc_attr( $font_id ),
					\selected( (string) $tokens[ $key ], $font_id, false ),
					\esc_html( Fonts::get_label( $font_id ) )
				);
			}
			
			echo '</select></td></tr>';
		}
		
		$logo = $tokens['logo'];
		$modes = [
			'auto' => \__( 'Automatic (each site: its site icon, or its theme logo)', 'image-socialiser' ),
			'custom' => \__( 'Custom image', 'image-socialiser' ),
			'none' => \__( 'No logo', 'image-socialiser' ),
		];
		
		echo '<tr><th scope="row">' . \esc_html__( 'Logo', 'image-socialiser' ) . '</th><td><fieldset>';
		
		foreach ( $modes as $mode => $label ) {
			\printf(
				'<p><label><input type="radio" name="brand[logo][mode]" value="%1$s" %2$s> %3$s</label></p>',
				\esc_attr( $mode ),
				\checked( $logo['mode'], $mode, false ),
				\esc_html( $label )
			);
		}
		
		echo '</fieldset>';
		self::render_media_id_input( 'brand[logo][id]', (int) $logo['id'] );
		echo '</td></tr>';
		echo '<tr><th scope="row">' . \esc_html__( 'Cover art', 'image-socialiser' ) . '</th><td>';
		self::render_media_id_input( 'brand[cover_art_id]', (int) $tokens['cover_art_id'] );
		echo '</td></tr>';
		echo '<tr><th scope="row">' . \esc_html__( 'Background image', 'image-socialiser' ) . '</th><td>';
		self::render_media_id_input( 'brand[background_id]', (int) $tokens['background_id'] );
		echo '</td></tr>';
		echo '</table>';
	}
	
	/**
	 * Render the content section.
	 */
	private static function render_content_section(): void {
		$stored = Multisite::get_network_option( 'content' ) ?? [];
		$tokens = Design::sanitize_content( $stored );
		$labels = [
			'category' => \__( 'First category', 'image-socialiser' ),
			'excerpt' => \__( 'Excerpt', 'image-socialiser' ),
			'none' => \__( 'None', 'image-socialiser' ),
			'tagline' => \__( 'Site tagline', 'image-socialiser' ),
		];
		
		echo '<h2>' . \esc_html__( 'Content', 'image-socialiser' ) . '</h2><table class="form-table">';
		echo '<tr><th scope="row">' . \esc_html__( 'Secondary line', 'image-socialiser' ) . '</th><td>';
		echo '<select name="content[subtitle_source]">';
		
		foreach ( Design::SUBTITLE_SOURCES as $source ) {
			\printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				\esc_attr( $source ),
				\selected( $tokens['subtitle_source'], $source, false ),
				\esc_html( $labels[ $source ] ?? $source )
			);
		}
		
		echo '</select></td></tr></table>';
	}
	
	/**
	 * Render the fallbacks section.
	 */
	private static function render_fallbacks_section(): void {
		$stored = Multisite::get_network_option( 'fallbacks' ) ?? [];
		
		echo '<h2>' . \esc_html__( 'Fallback image', 'image-socialiser' ) . '</h2><table class="form-table">';
		echo '<tr><th scope="row">' . \esc_html__( 'Network-wide fallback', 'image-socialiser' ) . '</th><td>';
		self::render_media_id_input( 'fallbacks[_default]', (int) ( $stored['_default'] ?? 0 ) );
		echo '</td></tr></table>';
	}
	
	/**
	 * Render the layout section.
	 */
	private static function render_layout_section(): void {
		$stored = Multisite::get_network_option( 'layout' ) ?? [];
		$tokens = Design::sanitize_layout( $stored );
		$position_labels = [
			'bottom-left' => \__( 'Bottom left', 'image-socialiser' ),
			'bottom-right' => \__( 'Bottom right', 'image-socialiser' ),
			'top-left' => \__( 'Top left', 'image-socialiser' ),
			'top-right' => \__( 'Top right', 'image-socialiser' ),
		];
		$alignment_labels = [
			'center' => \__( 'Center', 'image-socialiser' ),
			'left' => \__( 'Left', 'image-socialiser' ),
			'right' => \__( 'Right', 'image-socialiser' ),
		];
		$rows = [
			[
				'label' => \__( 'Site name', 'image-socialiser' ),
				'position_key' => 'site_name_position',
				'show_key' => 'show_site_name',
			],
			[
				'label' => \__( 'Logo', 'image-socialiser' ),
				'position_key' => 'logo_position',
				'show_key' => 'show_logo',
			],
		];
		
		echo '<h2>' . \esc_html__( 'Layout', 'image-socialiser' ) . '</h2><table class="form-table">';
		echo '<tr><th scope="row">' . \esc_html__( 'Elements', 'image-socialiser' ) . '</th><td>';
		
		foreach ( $rows as $row ) {
			echo '<p>';
			\printf( '<input type="hidden" name="layout[%s]" value="0">', \esc_attr( $row['show_key'] ) );
			\printf(
				'<label><input type="checkbox" name="layout[%1$s]" value="1" %2$s> %3$s</label> ',
				\esc_attr( $row['show_key'] ),
				\checked( (bool) $tokens[ $row['show_key'] ], true, false ),
				\esc_html( $row['label'] )
			);
			\printf( '<select name="layout[%s]">', \esc_attr( $row['position_key'] ) );
			
			foreach ( Design::POSITIONS as $position ) {
				\printf(
					'<option value="%1$s" %2$s>%3$s</option>',
					\esc_attr( $position ),
					\selected( $tokens[ $row['position_key'] ], $position, false ),
					\esc_html( $position_labels[ $position ] )
				);
			}
			
			echo '</select></p>';
		}
		
		echo '<p><label>' . \esc_html__( 'Text alignment', 'image-socialiser' ) . ' ';
		echo '<select name="layout[text_align]">';
		
		foreach ( Design::TEXT_ALIGNMENTS as $alignment ) {
			\printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				\esc_attr( $alignment ),
				\selected( $tokens['text_align'], $alignment, false ),
				\esc_html( $alignment_labels[ $alignment ] )
			);
		}
		
		echo '</select></label></p></td></tr></table>';
	}
	
	/**
	 * Render a plain attachment ID input with a main-site hint.
	 *
	 * @param	string	$name The input name
	 * @param	int	$value The current attachment ID
	 */
	private static function render_media_id_input( string $name, int $value ): void {
		\printf(
			'<input type="number" name="%1$s" value="%2$d" min="0" class="small-text"> ',
			\esc_attr( $name ),
			(int) $value
		);
		echo '<span class="description">' . \esc_html__( 'Media ID from the main site.', 'image-socialiser' ) . '</span>';
		$image = Multisite::get_main_site_attachment_image( $value );
		
		if ( $image !== null ) {
			\printf(
				'<br><img src="%s" style="max-height:48px;margin-top:4px;" alt="">',
				\esc_url( $image['url'] )
			);
		}
	}
	
	/**
	 * Render the network fonts section (own forms, outside the main form).
	 */
	private static function render_fonts_section(): void {
		echo '<h2>' . \esc_html__( 'Network fonts', 'image-socialiser' ) . '</h2>';
		echo '<p class="description">'
			. \esc_html__( 'Network fonts are available to every site in the network.', 'image-socialiser' )
			. '</p>';
		$index = Custom_Fonts::get_network_index();
		
		if ( \count( $index ) > 0 ) {
			echo '<table class="widefat striped image-socialiser-table"><thead><tr>';
			echo '<th>' . \esc_html__( 'Font', 'image-socialiser' ) . '</th>';
			echo '<th>' . \esc_html__( 'File', 'image-socialiser' ) . '</th>';
			echo '<th></th></tr></thead><tbody>';
			
			foreach ( $index as $font_id => $entry ) {
				$usage = Custom_Fonts::get_network_usage( (string) $font_id );
				
				echo '<tr><td>' . \esc_html( $entry['label'] ) . '</td>';
				echo '<td><code>' . \esc_html( $entry['file'] ) . '</code></td><td>';
				
				if ( $usage !== '' ) {
					echo \esc_html(
						\sprintf(
							/* translators: %s: where the font is used */
							\__( 'In use by %s', 'image-socialiser' ),
							$usage
						)
					);
				}
				else {
					echo '<form action="' . \esc_url( \admin_url( 'admin-post.php' ) ) . '" method="post">';
					\wp_nonce_field( self::ACTION_DELETE_FONT . '_' . $font_id );
					echo '<input type="hidden" name="action" value="' . \esc_attr( self::ACTION_DELETE_FONT ) . '">';
					echo '<input type="hidden" name="font_id" value="' . \esc_attr( (string) $font_id ) . '">';
					\printf(
						'<button type="submit" class="button-link-delete">%s</button>',
						\esc_html__( 'Delete', 'image-socialiser' )
					);
					echo '</form>';
				}
				
				echo '</td></tr>';
			}
			
			echo '</tbody></table>';
		}
		
		echo '<form action="' . \esc_url( \admin_url( 'admin-post.php' ) ) . '" method="post" enctype="multipart/form-data">';
		\wp_nonce_field( self::ACTION_UPLOAD_FONT );
		echo '<input type="hidden" name="action" value="' . \esc_attr( self::ACTION_UPLOAD_FONT ) . '">';
		echo '<p><label>' . \esc_html__( 'Font name', 'image-socialiser' ) . ' ';
		echo '<input type="text" name="image_socialiser_font_label" class="regular-text"></label></p>';
		echo '<p><input type="file" name="image_socialiser_font" accept=".ttf,.otf" required></p>';
		\submit_button( \__( 'Upload font', 'image-socialiser' ), 'secondary' );
		echo '<p class="description">'
			. \esc_html__(
				'TTF and OTF files only, 2 MB maximum. Make sure the font license allows embedding it in generated images.',
				'image-socialiser'
			)
			. '</p>';
		echo '</form>';
	}
	
	/**
	 * Render the network health section (per-site status counts).
	 */
	private static function render_health_section(): void {
		global $wpdb;
		
		$site_ids = \get_sites( [ 'fields' => 'ids', 'number' => 26 ] );
		
		echo '<h2>' . \esc_html__( 'Network health', 'image-socialiser' ) . '</h2>';
		echo '<table class="widefat striped image-socialiser-table"><thead><tr>';
		echo '<th>' . \esc_html__( 'Site', 'image-socialiser' ) . '</th>';
		echo '<th>' . \esc_html__( 'Generated images', 'image-socialiser' ) . '</th>';
		echo '<th>' . \esc_html__( 'Queued', 'image-socialiser' ) . '</th>';
		echo '<th>' . \esc_html__( 'Failed', 'image-socialiser' ) . '</th>';
		echo '</tr></thead><tbody>';
		
		foreach ( \array_slice( $site_ids, 0, 25 ) as $site_id ) {
			\switch_to_blog( (int) $site_id );
			
			try {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$counts = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT meta_value AS status, COUNT(*) AS total FROM {$wpdb->postmeta} WHERE meta_key = %s GROUP BY meta_value",
						Generator::META_STATUS
					),
					\OBJECT_K
				);
				$name = (string) \get_bloginfo( 'name' );
			} finally {
				\restore_current_blog();
			}
			
			\printf(
				'<tr><td>%1$s</td><td>%2$d</td><td>%3$d</td><td>%4$d</td></tr>',
				\esc_html( $name !== '' ? $name : '#' . (string) $site_id ),
				(int) ( $counts['ready']->total ?? 0 ),
				(int) ( $counts['pending']->total ?? 0 ),
				(int) ( $counts['failed']->total ?? 0 )
			);
		}
		
		echo '</tbody></table>';
		
		if ( \count( $site_ids ) > 25 ) {
			echo '<p class="description">'
				. \esc_html__( 'Showing the first 25 sites.', 'image-socialiser' )
				. '</p>';
		}
	}
	
	/**
	 * Render the notices for the last action.
	 */
	private static function render_notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>'
				. \esc_html__( 'Network settings saved.', 'image-socialiser' )
				. '</p></div>';
		}
		
		if ( ! empty( $_GET['image-socialiser-regenerating'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>'
				. \esc_html__(
					'Regeneration has been queued for every site and runs in the background.',
					'image-socialiser'
				)
				. '</p></div>';
		}
		// phpcs:enable
		
		Settings::render_font_status_notice();
	}
	
	/**
	 * Render the post types section.
	 */
	private static function render_post_types_section(): void {
		$stored = Multisite::get_network_option( 'post_types' ) ?? [];
		$types = \get_post_types( [ 'public' => true ], 'objects' );
		unset( $types['attachment'] );
		
		echo '<h2>' . \esc_html__( 'Post types', 'image-socialiser' ) . '</h2>';
		echo '<p class="description">'
			. \esc_html__( 'Based on the main site’s public post types; unknown types are ignored per site.', 'image-socialiser' )
			. '</p><p>';
		
		foreach ( $types as $type ) {
			\printf(
				'<label style="margin-right:16px;"><input type="checkbox" name="post_types[]" value="%1$s" %2$s> %3$s</label>',
				\esc_attr( $type->name ),
				\checked( \in_array( $type->name, $stored, true ), true, false ),
				\esc_html( $type->labels->name ?? $type->name )
			);
		}
		
		echo '</p>';
	}
	
	/**
	 * Render the per-section override permissions.
	 */
	private static function render_permissions_section(): void {
		$section_labels = [
			'brand' => \__( 'Brand', 'image-socialiser' ),
			'content' => \__( 'Content', 'image-socialiser' ),
			'fallbacks' => \__( 'Fallback image', 'image-socialiser' ),
			'fonts' => \__( 'Fonts', 'image-socialiser' ),
			'layout' => \__( 'Layout', 'image-socialiser' ),
			'post_types' => \__( 'Post types', 'image-socialiser' ),
		];
		
		echo '<h2>' . \esc_html__( 'Override permissions', 'image-socialiser' ) . '</h2>';
		echo '<p class="description">'
			. \esc_html__(
				'"Network only" locks a section: sites see the values greyed out, and theme support is ignored for it.',
				'image-socialiser'
			)
			. '</p><table class="form-table">';
		
		foreach ( Multisite::SECTIONS as $section ) {
			$permission = Multisite::get_permission( $section );
			
			echo '<tr><th scope="row">' . \esc_html( $section_labels[ $section ] ) . '</th><td>';
			\printf(
				'<label><input type="radio" name="permissions[%1$s]" value="site" %2$s> %3$s</label> &nbsp; ',
				\esc_attr( $section ),
				\checked( $permission, 'site', false ),
				\esc_html__( 'Sites may override', 'image-socialiser' )
			);
			\printf(
				'<label><input type="radio" name="permissions[%1$s]" value="network" %2$s> %3$s</label>',
				\esc_attr( $section ),
				\checked( $permission, 'network', false ),
				\esc_html__( 'Network only', 'image-socialiser' )
			);
			echo '</td></tr>';
		}
		
		echo '</table>';
	}
	
	/**
	 * Render the network tools section.
	 */
	private static function render_tools_section(): void {
		echo '<h2>' . \esc_html__( 'Tools', 'image-socialiser' ) . '</h2>';
		echo '<form action="' . \esc_url( \admin_url( 'admin-post.php' ) ) . '" method="post">';
		\wp_nonce_field( self::ACTION_REGENERATE );
		echo '<input type="hidden" name="action" value="' . \esc_attr( self::ACTION_REGENERATE ) . '">';
		\submit_button( \__( 'Regenerate all sites', 'image-socialiser' ), 'secondary' );
		echo '<p class="description">'
			. \esc_html__(
				'Bumps every site’s design version and schedules its bulk regeneration in the background.',
				'image-socialiser'
			)
			. '</p></form>';
	}
	
	/**
	 * Redirect back to the network settings page.
	 *
	 * @param	array<string, string>	$args Query args to append
	 */
	private static function redirect( array $args ): void {
		\wp_safe_redirect(
			\add_query_arg(
				\array_map( 'rawurlencode', $args ),
				\network_admin_url( 'settings.php?page=' . self::PAGE_SLUG )
			)
		);
		exit;
	}
}
