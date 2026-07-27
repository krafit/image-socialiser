<?php
declare(strict_types=1);

namespace happyhappy\ImageSocialiser\Admin;

use happyhappy\ImageSocialiser\Editor\Assets;
use happyhappy\ImageSocialiser\Generation\Context;
use happyhappy\ImageSocialiser\Generation\Generator;
use happyhappy\ImageSocialiser\Generation\Post_Types;
use happyhappy\ImageSocialiser\Generation\Resolver;
use happyhappy\ImageSocialiser\Generation\Scheduler;
use happyhappy\ImageSocialiser\Multisite\Multisite;
use happyhappy\ImageSocialiser\Plugin;
use happyhappy\ImageSocialiser\Rendering\Custom_Fonts;
use happyhappy\ImageSocialiser\Rendering\Fonts;
use happyhappy\ImageSocialiser\Rendering\Renderer_Factory;
use happyhappy\ImageSocialiser\Seo\Seo_Handler;
use happyhappy\ImageSocialiser\Template\Brand;
use happyhappy\ImageSocialiser\Template\Design;
use happyhappy\ImageSocialiser\Template\Template_Registry;
use happyhappy\ImageSocialiser\Template\Theme_Support;
use Imagick;

/**
 * Settings screen under Settings > Social Images.
 *
 * Brand tokens, per-post-type configuration, fallback images, renderer
 * and queue health, and a "Regenerate all" action.
 *
 * @author	Simon Kraft
 * @license	GPL2
 * @package	happyhappy\ImageSocialiser
 */
final class Settings {
	/**
	 * @var	string The admin-post action for regenerating all images.
	 */
	public const string ACTION_REGENERATE_ALL = 'image_socialiser_regenerate_all';
	
	/**
	 * @var	string The settings page slug.
	 */
	public const string PAGE_SLUG = 'image-socialiser';
	
	/**
	 * @var	string The settings group of the Design Center tab.
	 */
	public const string GROUP_DESIGN = 'image_socialiser_design';
	
	/**
	 * @var	string The settings group of the General tab.
	 */
	public const string GROUP_GENERAL = 'image_socialiser_general';
	
	/**
	 * @var	string The settings group of the Special Pages & Archives tab.
	 */
	public const string GROUP_CONTEXT = 'image_socialiser_context_tab';
	
	/**
	 * Get the tabs of the settings screen.
	 *
	 * @return	array<string, string> Tab slug => label
	 */
	public static function get_tabs(): array {
		return [
			'general' => \__( 'General', 'image-socialiser' ),
			'design' => \__( 'Design Center', 'image-socialiser' ),
			'context' => \__( 'Special Pages & Archives', 'image-socialiser' ),
			'advanced' => \__( 'Advanced', 'image-socialiser' ),
		];
	}
	
	/**
	 * Get the currently displayed tab.
	 *
	 * @return	string The tab slug
	 */
	private static function get_current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = \sanitize_key( (string) ( $_GET['tab'] ?? '' ) );
		
		return isset( self::get_tabs()[ $tab ] ) ? $tab : 'general';
	}
	
	/**
	 * Initialize the settings screen.
	 */
	public static function init(): void {
		\add_action( 'admin_menu', [ self::class, 'register_page' ] );
		\add_action( 'admin_init', [ self::class, 'register_settings' ] );
		\add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
		\add_action(
			'admin_post_' . self::ACTION_REGENERATE_ALL,
			[ self::class, 'handle_regenerate_all' ]
		);
	}
	
	/**
	 * Enqueue the media picker script on the settings page only.
	 *
	 * @param	string	$hook_suffix The current admin page hook suffix
	 */
	public static function enqueue( string $hook_suffix ): void {
		if ( $hook_suffix !== 'settings_page_' . self::PAGE_SLUG ) {
			return;
		}
		
		$current_tab = self::get_current_tab();
		
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
		if ( ! \in_array( $current_tab, [ 'design', 'general' ], true ) ) {
			return;
		}
		
		\wp_enqueue_script(
			Assets::PREVIEW_HANDLE,
			\plugin_dir_url( Plugin::get_instance()->plugin_file ) . 'assets/js/preview.js',
			[ 'wp-element' ],
			Plugin::VERSION,
			true
		);
		\wp_enqueue_script(
			'image-socialiser-settings-preview',
			\plugin_dir_url( Plugin::get_instance()->plugin_file ) . 'assets/js/settings-preview.js',
			[ Assets::PREVIEW_HANDLE, 'wp-element' ],
			Plugin::VERSION,
			true
		);
		\wp_add_inline_script(
			'image-socialiser-settings-preview',
			'var imageSocialiserSettingsPreviews = ' . (string) \wp_json_encode( self::get_preview_data() ) . ';',
			'before'
		);
	}
	
	/**
	 * Collect the data for the template gallery previews.
	 *
	 * @return	array The preview data (templates, fonts, sample binding)
	 */
	private static function get_preview_data(): array {
		$templates = [];
		
		foreach ( Template_Registry::get_all() as $template_id => $model ) {
			$templates[ $template_id ] = $model->to_public_array();
		}
		
		$brand = Brand::get_tokens();
		
		return [
			'assignments' => self::get_template_assignments(),
			'defaultTemplate' => Template_Registry::get_default_id(),
			'binding' => [
				'backgroundUrl' => Design::get_background_url(),
				'coverArtUrl' => self::get_preview_url( (int) $brand['cover_art_id'] ),
				'featuredUrl' => '',
				'logoUrl' => self::get_preview_url( Design::resolve_logo_id() ),
				'site_name' => (string) \get_bloginfo( 'name' ),
				'subtitle' => (string) \get_bloginfo( 'description' ),
				'title' => \__( 'Your post title appears here', 'image-socialiser' ),
			],
			'fonts' => Fonts::get_all_urls(),
			// input id => baked color, for the live color preview
			'liveColors' => [
				'image-socialiser-brand-background_from' => $brand['background_from'],
				'image-socialiser-brand-background_to' => $brand['background_to'],
				'image-socialiser-brand-muted_color' => $brand['muted_color'],
				'image-socialiser-brand-site_name_color' => $brand['site_name_color'],
				'image-socialiser-brand-subtitle_color' => $brand['subtitle_color'],
				'image-socialiser-brand-text_color' => $brand['text_color'],
			],
			'templates' => $templates,
		];
	}
	
	/**
	 * Get the assignment badges for every template.
	 *
	 * @return	array<string, string[]> Template id => badge labels
	 */
	private static function get_template_assignments(): array {
		$assignments = [];
		$default_id = Template_Registry::get_default_id();
		$assignments[ $default_id ][] = \__( 'Site default', 'image-socialiser' );
		$cpt_templates = \get_option( Template_Registry::OPTION_CPT_TEMPLATES, [] );
		$cpt_templates = \is_array( $cpt_templates ) ? $cpt_templates : [];
		$post_types = \get_post_types( [ 'public' => true ], 'objects' );
		
		foreach ( $cpt_templates as $post_type => $template_id ) {
			$template_id = (string) $template_id;
			
			if ( $template_id === '' || $template_id === 'default' || ! isset( $post_types[ $post_type ] ) ) {
				continue;
			}
			
			$assignments[ $template_id ][] = (string) $post_types[ $post_type ]->labels->name;
		}
		
		foreach ( Context::get_enabled_taxonomies() as $taxonomy ) {
			$template_id = Context::get_taxonomy_template( $taxonomy );
			$taxonomy_object = \get_taxonomy( $taxonomy );
			
			if ( $template_id === '' || $template_id === 'default' || $taxonomy_object === false ) {
				continue;
			}
			
			$assignments[ $template_id ][] = (string) $taxonomy_object->labels->name;
		}
		
		$special_labels = [
			'404' => \__( '404 page', 'image-socialiser' ),
			'archives' => \__( 'Post type archives', 'image-socialiser' ),
			'blog' => \__( 'Blog index', 'image-socialiser' ),
			'front' => \__( 'Front page', 'image-socialiser' ),
			'search' => \__( 'Search', 'image-socialiser' ),
		];
		
		foreach ( $special_labels as $kind => $label ) {
			// numeric-string keys ('404') degrade to int in array literals
			$kind = (string) $kind;
			
			if ( ! Context::is_special_enabled( $kind ) ) {
				continue;
			}
			
			$template_id = Context::get_special_template( $kind );
			
			if ( $template_id !== '' && $template_id !== 'default' ) {
				$assignments[ $template_id ][] = $label;
			}
		}
		
		return $assignments;
	}
	
	/**
	 * Get the full-size URL of an attachment for the preview.
	 *
	 * @param	int	$attachment_id The attachment ID
	 * @return	string The URL or an empty string
	 */
	private static function get_preview_url( int $attachment_id ): string {
		if ( $attachment_id === 0 ) {
			return '';
		}
		
		$url = \wp_get_attachment_image_url( $attachment_id, 'full' );
		
		return \is_string( $url ) ? $url : '';
	}
	
	/**
	 * Handle the "Regenerate all" action.
	 */
	public static function handle_regenerate_all(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_die( \esc_html__( 'You are not allowed to do that.', 'image-socialiser' ) );
		}
		
		\check_admin_referer( self::ACTION_REGENERATE_ALL );
		( new Scheduler() )->handle_design_change();
		\wp_safe_redirect(
			\add_query_arg(
				'image-socialiser-regenerating',
				'1',
				\admin_url( 'options-general.php?page=' . self::PAGE_SLUG )
			)
		);
		
		exit;
	}
	
	/**
	 * Register the settings page.
	 */
	public static function register_page(): void {
		\add_options_page(
			\__( 'Social Images', 'image-socialiser' ),
			\__( 'Social Images', 'image-socialiser' ),
			'manage_options',
			self::PAGE_SLUG,
			[ self::class, 'render_page' ]
		);
	}
	
	/**
	 * Register the settings, sections and fields.
	 */
	public static function register_settings(): void {
		// one settings group per tab: options.php updates every option
		// of the posted group, so options must only belong to the tab
		// whose form carries their fields
		\register_setting(
			self::GROUP_DESIGN,
			Brand::OPTION_NAME,
			[
				'sanitize_callback' => [ Brand::class, 'sanitize' ],
				'type' => 'array',
			]
		);
		\register_setting(
			self::GROUP_DESIGN,
			Design::OPTION_LAYOUT,
			[
				'sanitize_callback' => [ Design::class, 'sanitize_layout' ],
				'type' => 'array',
			]
		);
		\register_setting(
			self::GROUP_DESIGN,
			Design::OPTION_CONTENT,
			[
				'sanitize_callback' => [ Design::class, 'sanitize_content' ],
				'type' => 'array',
			]
		);
		\register_setting(
			self::GROUP_DESIGN,
			Design::OPTION_DESIGN_OVERRIDES,
			[
				'sanitize_callback' => [ Design::class, 'sanitize_design_overrides' ],
				'type' => 'array',
			]
		);
		\register_setting(
			self::GROUP_GENERAL,
			Post_Types::OPTION_NAME,
			[
				'sanitize_callback' => [ self::class, 'sanitize_post_types' ],
				'type' => 'array',
			]
		);
		\register_setting(
			self::GROUP_GENERAL,
			Template_Registry::OPTION_CPT_TEMPLATES,
			[
				'sanitize_callback' => [ self::class, 'sanitize_cpt_templates' ],
				'type' => 'array',
			]
		);
		\register_setting(
			self::GROUP_GENERAL,
			Resolver::OPTION_FALLBACKS,
			[
				'sanitize_callback' => [ self::class, 'sanitize_fallbacks' ],
				'type' => 'array',
			]
		);
		\register_setting(
			self::GROUP_CONTEXT,
			Context::OPTION_NAME,
			[
				'sanitize_callback' => [ Context::class, 'sanitize' ],
				'type' => 'array',
			]
		);
		\add_settings_section(
			'image_socialiser_brand',
			\__( 'Brand', 'image-socialiser' ),
			static function(): void {
				echo '<p>' . \esc_html__(
					'These design tokens are used by the image templates. Saving changes regenerates all images in the background.',
					'image-socialiser'
				) . '</p>';
			},
			self::PAGE_SLUG . '-design'
		);
		
		$brand_fields = [
			'background_from' => [ \__( 'Background gradient start', 'image-socialiser' ), 'color' ],
			'background_to' => [ \__( 'Background gradient end', 'image-socialiser' ), 'color' ],
			'text_color' => [ \__( 'Text color', 'image-socialiser' ), 'color' ],
			'muted_color' => [ \__( 'Muted text color', 'image-socialiser' ), 'color' ],
			'subtitle_color' => [ \__( 'Secondary line color', 'image-socialiser' ), 'optional_color' ],
			'site_name_color' => [ \__( 'Site name color', 'image-socialiser' ), 'optional_color' ],
			'heading_font' => [ \__( 'Heading font', 'image-socialiser' ), 'font' ],
			'body_font' => [ \__( 'Body font', 'image-socialiser' ), 'font' ],
			'logo' => [ \__( 'Logo', 'image-socialiser' ), 'logo' ],
			'cover_art_id' => [ \__( 'Cover art', 'image-socialiser' ), 'media' ],
			'background_id' => [ \__( 'Background image', 'image-socialiser' ), 'media' ],
		];
		
		foreach ( $brand_fields as $key => [ $label, $type ] ) {
			\add_settings_field(
				'image_socialiser_brand_' . $key,
				$label,
				[ self::class, 'render_' . $type . '_field' ],
				self::PAGE_SLUG . '-design',
				'image_socialiser_brand',
				[
					'key' => $key,
					'label_for' => 'image-socialiser-brand-' . $key,
					'option' => Brand::OPTION_NAME,
				]
			);
		}
		
		\add_settings_section(
			'image_socialiser_layout',
			\__( 'Layout', 'image-socialiser' ),
			'__return_empty_string',
			self::PAGE_SLUG . '-design'
		);
		\add_settings_field(
			'image_socialiser_layout_elements',
			\__( 'Elements', 'image-socialiser' ),
			[ self::class, 'render_layout_field' ],
			self::PAGE_SLUG . '-design',
			'image_socialiser_layout'
		);
		\add_settings_section(
			'image_socialiser_context',
			\__( 'Archives & special pages', 'image-socialiser' ),
			static function(): void {
				echo '<p>' . \esc_html__(
					'Generate images for term archives, post type archives, and the special pages. Search, 404, and blog images are static — one image per design.',
					'image-socialiser'
				) . '</p>';
			},
			self::PAGE_SLUG . '-context'
		);
		\add_settings_field(
			'image_socialiser_context_taxonomies',
			\__( 'Taxonomies', 'image-socialiser' ),
			[ self::class, 'render_taxonomies_field' ],
			self::PAGE_SLUG . '-context',
			'image_socialiser_context'
		);
		\add_settings_field(
			'image_socialiser_context_specials',
			\__( 'Special pages', 'image-socialiser' ),
			[ self::class, 'render_specials_field' ],
			self::PAGE_SLUG . '-context',
			'image_socialiser_context'
		);
		\add_settings_section(
			'image_socialiser_general',
			\__( 'Content', 'image-socialiser' ),
			'__return_empty_string',
			self::PAGE_SLUG . '-general'
		);
		\add_settings_field(
			'image_socialiser_post_types',
			\__( 'Post types', 'image-socialiser' ),
			[ self::class, 'render_post_types_field' ],
			self::PAGE_SLUG . '-general',
			'image_socialiser_general'
		);
		\add_settings_field(
			'image_socialiser_subtitle_source',
			\__( 'Secondary line', 'image-socialiser' ),
			[ self::class, 'render_subtitle_source_field' ],
			self::PAGE_SLUG . '-design',
			'image_socialiser_layout',
			[
				'label_for' => 'image-socialiser-subtitle-source',
			]
		);
		\add_settings_section(
			'image_socialiser_overrides',
			\__( 'Per-design overrides', 'image-socialiser' ),
			static function(): void {
				echo '<p>' . \esc_html__(
					'Override individual colors and fonts for a single design. Empty controls keep the site-wide value; overrides apply on top of theme and network values.',
					'image-socialiser'
				) . '</p>';
			},
			self::PAGE_SLUG . '-design'
		);
		\add_settings_field(
			'image_socialiser_design_overrides',
			\__( 'Design', 'image-socialiser' ),
			[ self::class, 'render_design_overrides_field' ],
			self::PAGE_SLUG . '-design',
			'image_socialiser_overrides',
			[
				'label_for' => 'image-socialiser-override-template',
			]
		);
		\add_settings_field(
			'image_socialiser_default_fallback',
			\__( 'Site-wide fallback image', 'image-socialiser' ),
			[ self::class, 'render_media_field' ],
			self::PAGE_SLUG . '-general',
			'image_socialiser_general',
			[
				'description' => \__(
					'Used when no image could be generated and no other fallback applies.',
					'image-socialiser'
				),
				'key' => '_default',
				'option' => Resolver::OPTION_FALLBACKS,
			]
		);
	}
	
	/**
	 * Render the layout controls (toggles, positions, text alignment).
	 */
	public static function render_layout_field(): void {
		$effective = Design::get_layout_tokens();
		$stored = Design::get_site_layout_tokens();
		$pins = Template_Registry::get( 'default' )->get_pins();
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
		$network_locked = self::is_network_locked( 'layout' );
		$theme_locked_any = false;
		$pinned_any = false;
		$is_locked = static function( string $token ) use ( $network_locked, $pins, &$theme_locked_any, &$pinned_any ): bool {
			if ( $network_locked ) {
				return true;
			}
			
			if ( Theme_Support::covers( 'layout.' . $token ) ) {
				$theme_locked_any = true;
				
				return true;
			}
			
			if ( isset( $pins[ $token ] ) ) {
				$pinned_any = true;
				
				return true;
			}
			
			return false;
		};
		
		foreach ( $rows as $row ) {
			$show_locked = $is_locked( $row['show_key'] );
			$position_locked = $is_locked( $row['position_key'] );
			$show_value = $show_locked ? $effective[ $row['show_key'] ] : $stored[ $row['show_key'] ];
			$position_value = $position_locked
				? ( $pins[ $row['position_key'] ] ?? $effective[ $row['position_key'] ] )
				: $stored[ $row['position_key'] ];
			
			echo '<p>';
			\printf(
				'<input type="hidden" name="%1$s[%2$s]" value="%3$s">',
				\esc_attr( Design::OPTION_LAYOUT ),
				\esc_attr( $row['show_key'] ),
				$stored[ $row['show_key'] ] ? '1' : '0'
			);
			\printf(
				'<label><input type="checkbox" %1$s value="1" %2$s %3$s> %4$s</label> ',
				$show_locked ? '' : 'name="' . \esc_attr( Design::OPTION_LAYOUT . '[' . $row['show_key'] . ']' ) . '"',
				\checked( (bool) $show_value, true, false ),
				\disabled( $show_locked, true, false ),
				\esc_html( $row['label'] )
			);
			
			if ( $position_locked ) {
				\printf(
					'<input type="hidden" name="%1$s[%2$s]" value="%3$s">',
					\esc_attr( Design::OPTION_LAYOUT ),
					\esc_attr( $row['position_key'] ),
					\esc_attr( $stored[ $row['position_key'] ] )
				);
			}
			
			\printf(
				'<select %1$s aria-label="%2$s" %3$s>',
				$position_locked
					? ''
					: 'name="' . \esc_attr( Design::OPTION_LAYOUT . '[' . $row['position_key'] . ']' ) . '"',
				\esc_attr(
					\sprintf(
						/* translators: %s: element name */
						\__( 'Position of %s', 'image-socialiser' ),
						$row['label']
					)
				),
				\disabled( $position_locked, true, false )
			);
			
			foreach ( Design::POSITIONS as $position ) {
				\printf(
					'<option value="%1$s" %2$s>%3$s</option>',
					\esc_attr( $position ),
					\selected( $position_value, $position, false ),
					\esc_html( $position_labels[ $position ] )
				);
			}
			
			// a pinned position may sit outside the four corners (e.g.
			// Poster's bottom center) — show it as its own entry
			if ( $position_locked && ! \in_array( $position_value, Design::POSITIONS, true ) ) {
				\printf(
					'<option value="%1$s" selected>%2$s</option>',
					\esc_attr( (string) $position_value ),
					\esc_html( \ucwords( \str_replace( '-', ' ', (string) $position_value ) ) )
				);
			}
			
			echo '</select></p>';
		}
		
		$text_align_locked = $is_locked( 'text_align' );
		$text_align_value = $text_align_locked
			? ( $pins['text_align'] ?? $effective['text_align'] )
			: $stored['text_align'];
		
		if ( $text_align_locked ) {
			\printf(
				'<input type="hidden" name="%1$s[text_align]" value="%2$s">',
				\esc_attr( Design::OPTION_LAYOUT ),
				\esc_attr( $stored['text_align'] )
			);
		}
		
		echo '<p><label>' . \esc_html__( 'Text alignment', 'image-socialiser' ) . ' ';
		\printf(
			'<select %1$s %2$s>',
			$text_align_locked ? '' : 'name="' . \esc_attr( Design::OPTION_LAYOUT . '[text_align]' ) . '"',
			\disabled( $text_align_locked, true, false )
		);
		
		foreach ( Design::TEXT_ALIGNMENTS as $alignment ) {
			\printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				\esc_attr( $alignment ),
				\selected( $text_align_value, $alignment, false ),
				\esc_html( $alignment_labels[ $alignment ] )
			);
		}
		
		echo '</select></label>';
		echo '<span class="description"> '
			. \esc_html__( 'Applies to the title and the secondary line.', 'image-socialiser' )
			. '</span></p>';
		
		if ( $network_locked ) {
			self::render_lockout_notice( 'network' );
		}
		elseif ( $theme_locked_any ) {
			self::render_lockout_notice( 'theme' );
		}
		
		if ( $pinned_any ) {
			echo '<p class="description">'
				. \esc_html__(
					'Greyed-out values without a theme notice are defined by the current default template.',
					'image-socialiser'
				)
				. '</p>';
		}
		
		if ( ! Template_Registry::get( 'default' )->supports( 'layout' ) ) {
			echo '<p class="description">'
				. \esc_html__(
					'The current default template does not use these layout settings.',
					'image-socialiser'
				)
				. '</p>';
		}
	}
	
	/**
	 * Render the tri-state logo control.
	 */
	public static function render_logo_field(): void {
		$lock = self::get_lock( 'logo' );
		$locked = $lock !== '';
		$logo = Brand::get_tokens()['logo'];
		$stored_logo = Brand::get_site_tokens()['logo'];
		$displayed = $locked ? $logo : $stored_logo;
		$modes = [
			'auto' => \__( 'Automatic (site icon, or the theme logo)', 'image-socialiser' ),
			'custom' => \__( 'Custom image', 'image-socialiser' ),
			'none' => \__( 'No logo', 'image-socialiser' ),
		];
		
		if ( $locked ) {
			\printf(
				'<input type="hidden" name="%1$s[logo][mode]" value="%2$s">',
				\esc_attr( Brand::OPTION_NAME ),
				\esc_attr( $stored_logo['mode'] )
			);
			\printf(
				'<input type="hidden" name="%1$s[logo][id]" value="%2$d">',
				\esc_attr( Brand::OPTION_NAME ),
				(int) $stored_logo['id']
			);
		}
		
		echo '<fieldset>';
		
		foreach ( $modes as $mode => $label ) {
			\printf(
				'<p><label><input type="radio" %1$s value="%2$s" %3$s %4$s> %5$s</label></p>',
				$locked ? '' : 'name="' . \esc_attr( Brand::OPTION_NAME . '[logo][mode]' ) . '"',
				\esc_attr( $mode ),
				\checked( $displayed['mode'], $mode, false ),
				\disabled( $locked, true, false ),
				\esc_html( $label )
			);
		}
		
		echo '</fieldset>';
		
		if ( $locked ) {
			self::render_lockout_notice( $lock );
			
			return;
		}
		
		self::render_media_field( [
			'description' => \__( 'Used when "Custom image" is selected.', 'image-socialiser' ),
			'name' => Brand::OPTION_NAME . '[logo][id]',
			'value' => (int) $stored_logo['id'],
		] );
	}
	
	/**
	 * Render the subtitle source select.
	 */
	public static function render_subtitle_source_field(): void {
		$locked = self::is_network_locked( 'content' );
		$effective = Design::get_content_tokens()['subtitle_source'];
		$stored_option = \get_option( Design::OPTION_CONTENT, [] );
		$stored = Design::sanitize_content( \is_array( $stored_option ) ? $stored_option : [] )['subtitle_source'];
		$current = $locked ? $effective : $stored;
		$labels = [
			'category' => \__( 'First category', 'image-socialiser' ),
			'excerpt' => \__( 'Excerpt', 'image-socialiser' ),
			'none' => \__( 'None', 'image-socialiser' ),
			'tagline' => \__( 'Tagline', 'image-socialiser' ),
		];
		
		if ( $locked ) {
			\printf(
				'<input type="hidden" name="%1$s[subtitle_source]" value="%2$s">',
				\esc_attr( Design::OPTION_CONTENT ),
				\esc_attr( $stored )
			);
		}
		
		\printf(
			'<select id="image-socialiser-subtitle-source" %1$s %2$s>',
			$locked ? '' : 'name="' . \esc_attr( Design::OPTION_CONTENT . '[subtitle_source]' ) . '"',
			\disabled( $locked, true, false )
		);
		
		foreach ( Design::SUBTITLE_SOURCES as $source ) {
			\printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				\esc_attr( $source ),
				\selected( $current, $source, false ),
				\esc_html( $labels[ $source ] )
			);
		}
		
		echo '</select>';
		
		if ( $locked ) {
			self::render_lockout_notice( 'network' );
		}
		echo '<p class="description">'
			. \esc_html__(
				'Shown as a second line under the title when a post has no custom subtitle.',
				'image-socialiser'
			)
			. '</p>';
	}
	
	/**
	 * @var	array<string, string> Brand token keys => theme support keys.
	 */
	private const array THEME_KEY_MAP = [
		'background_from' => 'colors.background_from',
		'background_id' => 'background_image',
		'background_to' => 'colors.background_to',
		'body_font' => 'fonts.body',
		'heading_font' => 'fonts.heading',
		'logo' => 'logo',
		'muted_color' => 'colors.muted',
		'text_color' => 'colors.text',
	];
	
	/**
	 * Check whether the theme covers a brand/setting key.
	 *
	 * @param	string	$token_key The token key
	 * @return	bool Whether the matching settings control is locked
	 */
	private static function is_theme_locked( string $token_key ): bool {
		return self::get_lock( $token_key ) !== '';
	}
	
	/**
	 * @var	array<string, string> Token keys => permission section.
	 */
	private const array SECTION_MAP = [
		'background_from' => 'brand',
		'background_id' => 'brand',
		'background_to' => 'brand',
		'body_font' => 'brand',
		'cover_art_id' => 'brand',
		'heading_font' => 'brand',
		'logo' => 'brand',
		'muted_color' => 'brand',
		'subtitle_source' => 'content',
		'text_color' => 'brand',
	];
	
	/**
	 * Get the lock source of a settings control.
	 *
	 * A network lock beats theme support, matching the pipeline.
	 *
	 * @param	string	$token_key The token key
	 * @return	string 'network', 'theme', or an empty string when editable
	 */
	private static function get_lock( string $token_key ): string {
		if ( self::is_network_locked( self::SECTION_MAP[ $token_key ] ?? '' ) ) {
			return 'network';
		}
		
		$theme_key = self::THEME_KEY_MAP[ $token_key ] ?? $token_key;
		
		return Theme_Support::covers( $theme_key ) ? 'theme' : '';
	}
	
	/**
	 * Check whether a section is effectively network-locked.
	 *
	 * Matches the pipeline: a lock only takes effect once network
	 * values exist.
	 *
	 * @param	string	$section The permission section
	 * @return	bool Whether the section's controls are locked
	 */
	private static function is_network_locked( string $section ): bool {
		return $section !== ''
			&& Multisite::is_locked( $section )
			&& Multisite::get_network_option( $section ) !== null;
	}
	
	/**
	 * Render the lock-out notice for a control.
	 *
	 * @param	string	$lock The lock source ('theme' or 'network')
	 */
	private static function render_lockout_notice( string $lock = 'theme' ): void {
		$message = $lock === 'network'
			? \__( 'Managed network-wide.', 'image-socialiser' )
			: \sprintf(
				/* translators: %s: theme name */
				\__( 'Managed by your theme (%s).', 'image-socialiser' ),
				Theme_Support::get_theme_name()
			);
		
		\printf(
			'<p class="description image-socialiser-lockout">%s</p>',
			\esc_html( $message )
		);
	}
	
	/**
	 * Render a brand color field.
	 *
	 * Locked fields show the effective (theme) value disabled and
	 * round-trip the stored site value via a hidden input, so removing
	 * the theme restores the user's own setting.
	 *
	 * @param	array	$args The field arguments (option, key)
	 */
	public static function render_color_field( array $args ): void {
		$lock = self::get_lock( $args['key'] );
		$locked = $lock !== '';
		$effective = (string) Brand::get_tokens()[ $args['key'] ];
		$stored = (string) Brand::get_site_tokens()[ $args['key'] ];
		
		if ( $locked ) {
			\printf(
				'<input type="hidden" name="%1$s[%2$s]" value="%3$s">',
				\esc_attr( $args['option'] ),
				\esc_attr( $args['key'] ),
				\esc_attr( \substr( $stored, 0, 7 ) )
			);
		}
		
		self::render_theme_palette_datalist();
		\printf(
			'<input type="color" id="%1$s" %2$s value="%3$s" %4$s %5$s>',
			\esc_attr( 'image-socialiser-brand-' . $args['key'] ),
			$locked ? '' : 'name="' . \esc_attr( $args['option'] . '[' . $args['key'] . ']' ) . '"',
			\esc_attr( \substr( $locked ? $effective : $stored, 0, 7 ) ),
			\disabled( $locked, true, false ),
			self::$palette_rendered ? 'list="image-socialiser-theme-palette"' : ''
		);
		
		if ( $locked ) {
			self::render_lockout_notice( $lock );
		}
	}
	
	/**
	 * Render an optional brand color field ("same as muted" checkbox).
	 *
	 * An empty stored value means the color inherits the muted color;
	 * the checkbox toggles between inheriting and a custom value.
	 *
	 * @param	array	$args The field arguments (option, key)
	 */
	public static function render_optional_color_field( array $args ): void {
		$lock = self::get_lock( $args['key'] );
		$locked = $lock !== '';
		$effective = (string) Brand::get_tokens()[ $args['key'] ];
		$stored = (string) Brand::get_site_tokens()[ $args['key'] ];
		$inherits = $stored === '';
		
		if ( $locked ) {
			\printf(
				'<input type="hidden" name="%1$s[%2$s]" value="%3$s">',
				\esc_attr( $args['option'] ),
				\esc_attr( $args['key'] ),
				\esc_attr( \substr( $stored, 0, 7 ) )
			);
			
			if ( $inherits ) {
				\printf(
					'<input type="hidden" name="%1$s[%2$s_auto]" value="1">',
					\esc_attr( $args['option'] ),
					\esc_attr( $args['key'] )
				);
			}
		}
		
		self::render_theme_palette_datalist();
		\printf(
			'<label><input type="checkbox" %1$s value="1" %2$s %3$s data-imgsoc-color-auto="%4$s"> %5$s</label> ',
			$locked ? '' : 'name="' . \esc_attr( $args['option'] . '[' . $args['key'] . '_auto]' ) . '"',
			\checked( $inherits, true, false ),
			\disabled( $locked, true, false ),
			\esc_attr( 'image-socialiser-brand-' . $args['key'] ),
			\esc_html__( 'Same as the muted text color', 'image-socialiser' )
		);
		\printf(
			'<input type="color" id="%1$s" %2$s value="%3$s" %4$s %5$s>',
			\esc_attr( 'image-socialiser-brand-' . $args['key'] ),
			$locked ? '' : 'name="' . \esc_attr( $args['option'] . '[' . $args['key'] . ']' ) . '"',
			\esc_attr( \substr( $locked || $inherits ? $effective : $stored, 0, 7 ) ),
			\disabled( $locked || $inherits, true, false ),
			self::$palette_rendered ? 'list="image-socialiser-theme-palette"' : ''
		);
		
		if ( $locked ) {
			self::render_lockout_notice( $lock );
		}
	}
	
	/**
	 * Render the per-design token override controls.
	 *
	 * One fieldset per registered design; a select toggles which
	 * fieldset is visible (all are posted). Empty inputs mean "no
	 * override".
	 */
	public static function render_design_overrides_field(): void {
		if ( self::is_network_locked( 'brand' ) ) {
			self::render_lockout_notice( 'network' );
			
			return;
		}
		
		$overrides = Design::get_design_overrides();
		$color_labels = [
			'background_from' => \__( 'Background gradient start', 'image-socialiser' ),
			'background_to' => \__( 'Background gradient end', 'image-socialiser' ),
			'text_color' => \__( 'Text color', 'image-socialiser' ),
			'muted_color' => \__( 'Muted text color', 'image-socialiser' ),
			'subtitle_color' => \__( 'Secondary line color', 'image-socialiser' ),
			'site_name_color' => \__( 'Site name color', 'image-socialiser' ),
		];
		$font_labels = [
			'heading_font' => \__( 'Heading font', 'image-socialiser' ),
			'body_font' => \__( 'Body font', 'image-socialiser' ),
		];
		$templates = Template_Registry::get_all();
		
		echo '<select id="image-socialiser-override-template">';
		
		foreach ( $templates as $template_id => $model ) {
			$has_overrides = ! empty( $overrides[ $template_id ] );
			\printf(
				'<option value="%1$s">%2$s%3$s</option>',
				\esc_attr( $template_id ),
				\esc_html( $model->get_label() ),
				$has_overrides ? ' •' : ''
			);
		}
		
		echo '</select>';
		
		foreach ( $templates as $template_id => $model ) {
			\printf(
				'<fieldset class="image-socialiser-override-fieldset" data-template="%s" hidden>',
				\esc_attr( $template_id )
			);
			
			foreach ( $color_labels as $token_key => $label ) {
				$value = (string) ( $overrides[ $template_id ][ $token_key ] ?? '' );
				
				echo '<p><label>';
				\printf(
					'<input type="checkbox" value="1" %1$s data-imgsoc-override-toggle="%2$s"> %3$s ',
					\checked( $value !== '', true, false ),
					\esc_attr( 'imgsoc-override-' . $template_id . '-' . $token_key ),
					\esc_html( $label )
				);
				echo '</label>';
				\printf(
					'<input type="color" id="%1$s" name="%2$s" value="%3$s" %4$s %5$s>',
					\esc_attr( 'imgsoc-override-' . $template_id . '-' . $token_key ),
					\esc_attr( Design::OPTION_DESIGN_OVERRIDES . '[' . $template_id . '][' . $token_key . ']' ),
					\esc_attr( $value !== '' ? $value : '#000000' ),
					\disabled( $value === '', true, false ),
					self::$palette_rendered ? 'list="image-socialiser-theme-palette"' : ''
				);
				echo '</p>';
			}
			
			foreach ( $font_labels as $token_key => $label ) {
				$value = (string) ( $overrides[ $template_id ][ $token_key ] ?? '' );
				
				echo '<p><label>' . \esc_html( $label ) . ' ';
				\printf(
					'<select name="%s">',
					\esc_attr( Design::OPTION_DESIGN_OVERRIDES . '[' . $template_id . '][' . $token_key . ']' )
				);
				\printf(
					'<option value="">%s</option>',
					\esc_html__( 'Site-wide font', 'image-socialiser' )
				);
				
				foreach ( \array_keys( Fonts::get_all() ) as $font_id ) {
					if ( $token_key === 'body_font' && Fonts::is_heading_only( (string) $font_id ) ) {
						continue;
					}
					
					\printf(
						'<option value="%1$s" %2$s>%3$s</option>',
						\esc_attr( (string) $font_id ),
						\selected( $value, (string) $font_id, false ),
						\esc_html( Fonts::get_label( (string) $font_id ) )
					);
				}
				
				echo '</select></label></p>';
			}
			
			echo '</fieldset>';
		}
		
		echo '<p class="description">' . \esc_html__(
			'Overrides marked with • are set. Unchecked colors and "Site-wide font" mean no override.',
			'image-socialiser'
		) . '</p>';
	}
	
	/**
	 * @var	bool Whether the theme palette datalist has been rendered.
	 */
	private static bool $palette_rendered = false;
	
	/**
	 * Render the shared theme-palette datalist once.
	 *
	 * Feeds the theme's solid palette colours into the native colour
	 * pickers as swatches — convenient presets, no behaviour change.
	 */
	private static function render_theme_palette_datalist(): void {
		if ( self::$palette_rendered ) {
			return;
		}
		
		$palette = Design::get_theme_palette();
		
		if ( $palette === [] ) {
			return;
		}
		
		self::$palette_rendered = true;
		echo '<datalist id="image-socialiser-theme-palette">';
		
		foreach ( $palette as $swatch ) {
			\printf(
				'<option value="%1$s">%2$s</option>',
				\esc_attr( $swatch['color'] ),
				\esc_html( $swatch['name'] )
			);
		}
		
		echo '</datalist>';
	}
	
	/**
	 * Render a brand font select field.
	 *
	 * @param	array	$args The field arguments (option, key)
	 */
	public static function render_font_field( array $args ): void {
		$lock = self::get_lock( $args['key'] );
		$locked = $lock !== '';
		$effective = (string) Brand::get_tokens()[ $args['key'] ];
		$stored = (string) Brand::get_site_tokens()[ $args['key'] ];
		$current = $locked ? $effective : $stored;
		
		if ( $locked ) {
			\printf(
				'<input type="hidden" name="%1$s[%2$s]" value="%3$s">',
				\esc_attr( $args['option'] ),
				\esc_attr( $args['key'] ),
				\esc_attr( $stored )
			);
		}
		
		\printf(
			'<select id="%1$s" %2$s %3$s>',
			\esc_attr( 'image-socialiser-brand-' . $args['key'] ),
			$locked ? '' : 'name="' . \esc_attr( $args['option'] . '[' . $args['key'] . ']' ) . '"',
			\disabled( $locked, true, false )
		);
		
		foreach ( \array_keys( Fonts::get_all() ) as $font_id ) {
			// single-weight display faces are offered for headings only
			if ( $args['key'] === 'body_font' && Fonts::is_heading_only( $font_id ) && $font_id !== $current ) {
				continue;
			}
			
			\printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				\esc_attr( $font_id ),
				\selected( $current, $font_id, false ),
				\esc_html( Fonts::get_label( $font_id ) )
			);
		}
		
		echo '</select>';
		
		if ( $args['key'] === 'body_font' ) {
			echo '<p class="description">'
				. \esc_html__( 'Single-weight display faces are available for the heading font only.', 'image-socialiser' )
				. '</p>';
		}
		
		if ( $locked ) {
			self::render_lockout_notice( $lock );
		}
	}
	
	/**
	 * Render a media (attachment) picker field.
	 *
	 * @param	array	$args The field arguments (option, key, description)
	 */
	public static function render_media_field( array $args ): void {
		if ( isset( $args['value'] ) ) {
			$attachment_id = (int) $args['value'];
		}
		else {
			$stored = \get_option( $args['option'], [] );
			$attachment_id = \is_array( $stored ) ? (int) ( $stored[ $args['key'] ] ?? 0 ) : 0;
		}
		
		$lock = isset( $args['key'] ) ? self::get_lock( (string) $args['key'] ) : '';
		
		if ( ! empty( $args['locked'] ) ) {
			$lock = (string) $args['locked'];
		}
		
		$locked = $lock !== '';
		$name = (string) ( $args['name'] ?? $args['option'] . '[' . $args['key'] . ']' );
		$preview = $attachment_id !== 0 ? \wp_get_attachment_image_url( $attachment_id, 'thumbnail' ) : '';
		
		echo '<div class="image-socialiser-media-field">';
		\printf(
			'<input type="hidden" class="image-socialiser-media-id" name="%1$s" value="%2$d">',
			\esc_attr( $name ),
			$attachment_id
		);
		\printf(
			'<img class="image-socialiser-media-preview" src="%1$s" alt="" style="display:%2$s;">',
			\esc_url( \is_string( $preview ) ? $preview : '' ),
			$preview ? 'block' : 'none'
		);
		echo '<div class="image-socialiser-media-actions">';
		\printf(
			'<button type="button" class="button image-socialiser-media-select" %2$s>%1$s</button>',
			\esc_html__( 'Select image', 'image-socialiser' ),
			\disabled( $locked, true, false )
		);
		\printf(
			'<button type="button" class="button-link-delete image-socialiser-media-remove" style="display:%1$s;" %3$s>%2$s</button>',
			$attachment_id !== 0 && ! $locked ? 'inline' : 'none',
			\esc_html__( 'Remove', 'image-socialiser' ),
			\disabled( $locked, true, false )
		);
		echo '</div>';
		
		if ( $locked ) {
			self::render_lockout_notice( $lock );
		}
		
		if ( ! empty( $args['description'] ) ) {
			echo '<p class="description">' . \esc_html( (string) $args['description'] ) . '</p>';
		}
		
		echo '</div>';
	}
	
	/**
	 * Render the taxonomy toggles with per-taxonomy template selects.
	 */
	public static function render_taxonomies_field(): void {
		$settings = Context::get_settings();
		$taxonomies = \get_taxonomies( [ 'public' => true ], 'objects' );
		unset( $taxonomies['post_format'] );
		$templates = Template_Registry::get_all();
		
		foreach ( $taxonomies as $taxonomy ) {
			$enabled = \in_array( $taxonomy->name, $settings['taxonomies'], true );
			
			echo '<p>';
			\printf(
				'<label><input type="checkbox" name="%1$s[taxonomies][]" value="%2$s" %3$s> %4$s</label> ',
				\esc_attr( Context::OPTION_NAME ),
				\esc_attr( $taxonomy->name ),
				\checked( $enabled, true, false ),
				\esc_html( $taxonomy->labels->name ?? $taxonomy->name )
			);
			\printf(
				'<select name="%1$s[taxonomy_templates][%2$s]" aria-label="%3$s">',
				\esc_attr( Context::OPTION_NAME ),
				\esc_attr( $taxonomy->name ),
				\esc_attr(
					\sprintf(
						/* translators: %s: taxonomy name */
						\__( 'Template for %s', 'image-socialiser' ),
						$taxonomy->labels->name ?? $taxonomy->name
					)
				)
			);
			\printf( '<option value="">%s</option>', \esc_html__( 'Site default', 'image-socialiser' ) );
			
			foreach ( $templates as $template_id => $template_model ) {
				\printf(
					'<option value="%1$s" %2$s>%3$s</option>',
					\esc_attr( $template_id ),
					\selected( $settings['taxonomy_templates'][ $taxonomy->name ] ?? '', $template_id, false ),
					\esc_html( $template_model->get_label() )
				);
			}
			
			echo '</select></p>';
		}
		
		echo '<p class="description">'
			. \esc_html__(
				'Term images are generated when a term is created or edited, and during bulk regeneration.',
				'image-socialiser'
			)
			. '</p>';
	}
	
	/**
	 * Render the special pages toggles with template selects.
	 */
	public static function render_specials_field(): void {
		$settings = Context::get_settings();
		$templates = Template_Registry::get_all();
		$labels = [
			'404' => \__( '404 page', 'image-socialiser' ),
			'archives' => \__( 'Post type archives', 'image-socialiser' ),
			'blog' => \__( 'Blog (posts index)', 'image-socialiser' ),
			'front' => \__( 'Front page (latest posts)', 'image-socialiser' ),
			'search' => \__( 'Search results', 'image-socialiser' ),
		];
		
		foreach ( Context::SPECIAL_KEYS as $key ) {
			$special = $settings['specials'][ $key ];
			
			echo '<p>';
			\printf(
				'<label><input type="checkbox" name="%1$s[specials][%2$s][enabled]" value="1" %3$s> %4$s</label> ',
				\esc_attr( Context::OPTION_NAME ),
				\esc_attr( $key ),
				\checked( $special['enabled'], true, false ),
				\esc_html( $labels[ $key ] )
			);
			\printf(
				'<select name="%1$s[specials][%2$s][template]" aria-label="%3$s">',
				\esc_attr( Context::OPTION_NAME ),
				\esc_attr( $key ),
				\esc_attr(
					\sprintf(
						/* translators: %s: special page name */
						\__( 'Template for %s', 'image-socialiser' ),
						$labels[ $key ]
					)
				)
			);
			\printf( '<option value="">%s</option>', \esc_html__( 'Site default', 'image-socialiser' ) );
			
			foreach ( $templates as $template_id => $template_model ) {
				\printf(
					'<option value="%1$s" %2$s>%3$s</option>',
					\esc_attr( $template_id ),
					\selected( $special['template'], $template_id, false ),
					\esc_html( $template_model->get_label() )
				);
			}
			
			echo '</select>';
			$placeholders = self::get_special_placeholders( $key );
			\printf(
				'<br><input type="text" class="regular-text" name="%1$s[specials][%2$s][title]" value="%3$s" placeholder="%4$s" aria-label="%5$s">',
				\esc_attr( Context::OPTION_NAME ),
				\esc_attr( $key ),
				\esc_attr( (string) ( $special['title'] ?? '' ) ),
				\esc_attr( $placeholders['title'] ),
				\esc_attr(
					\sprintf(
						/* translators: %s: special page name */
						\__( 'Custom title for %s', 'image-socialiser' ),
						$labels[ $key ]
					)
				)
			);
			\printf(
				' <input type="text" class="regular-text" name="%1$s[specials][%2$s][subtitle]" value="%3$s" placeholder="%4$s" aria-label="%5$s">',
				\esc_attr( Context::OPTION_NAME ),
				\esc_attr( $key ),
				\esc_attr( (string) ( $special['subtitle'] ?? '' ) ),
				\esc_attr( $placeholders['subtitle'] ),
				\esc_attr(
					\sprintf(
						/* translators: %s: special page name */
						\__( 'Custom subtitle for %s', 'image-socialiser' ),
						$labels[ $key ]
					)
				)
			);
			echo '</p>';
		}
		
		echo '<p class="description">'
			. \esc_html__(
				'Leave the title and subtitle empty to use the default shown in each field. The front page and blog toggles apply when the site shows latest posts; a static front page is covered as a regular page.',
				'image-socialiser'
			)
			. '</p>';
	}
	
	/**
	 * Get the placeholder (derived default) title and subtitle for a
	 * special group, shown in the empty custom-text fields.
	 *
	 * @param	string	$key The special key ('404', 'archives', 'blog', 'front', 'search')
	 * @return	array{subtitle: string, title: string} The placeholders
	 */
	private static function get_special_placeholders( string $key ): array {
		$tagline = (string) \get_bloginfo( 'description' );
		$site_name = (string) \get_bloginfo( 'name' );
		
		return match ( $key ) {
			'404' => [
				'subtitle' => \__( '(none)', 'image-socialiser' ),
				'title' => \__( 'Page not found', 'image-socialiser' ),
			],
			'archives' => [
				'subtitle' => \__( '(none)', 'image-socialiser' ),
				'title' => \__( 'The post type name', 'image-socialiser' ),
			],
			'search' => [
				'subtitle' => \__( '(none)', 'image-socialiser' ),
				'title' => \__( 'Search', 'image-socialiser' ),
			],
			default => [
				'subtitle' => $tagline !== '' ? $tagline : \__( '(site tagline)', 'image-socialiser' ),
				'title' => $site_name,
			],
		};
	}
	
	/**
	 * Render the per-post-type configuration table.
	 */
	public static function render_post_types_field(): void {
		$templates = Template_Registry::get_all();
		$has_multiple_templates = \count( $templates ) > 1;
		$post_types_locked = self::is_network_locked( 'post_types' );
		$fallbacks_locked = self::is_network_locked( 'fallbacks' );
		// effective (network-resolved) for display when locked; the raw
		// site option round-trips through hidden inputs
		$supported = Post_Types::get_supported();
		$site_enabled = \get_option( Post_Types::OPTION_NAME, null );
		$site_enabled = \is_array( $site_enabled ) ? \array_map( '\strval', $site_enabled ) : null;
		$cpt_templates = \get_option( Template_Registry::OPTION_CPT_TEMPLATES, [] );
		$cpt_templates = \is_array( $cpt_templates ) ? $cpt_templates : [];
		$post_types = \get_post_types( [ 'public' => true ], 'objects' );
		unset( $post_types['attachment'] );
		
		echo '<table class="widefat striped image-socialiser-table"><thead><tr>';
		echo '<th>' . \esc_html__( 'Post type', 'image-socialiser' ) . '</th>';
		echo '<th>' . \esc_html__( 'Generate images', 'image-socialiser' ) . '</th>';
		
		if ( $has_multiple_templates ) {
			echo '<th>' . \esc_html__( 'Default template', 'image-socialiser' ) . '</th>';
		}
		
		echo '<th>' . \esc_html__( 'Fallback image', 'image-socialiser' ) . '</th>';
		echo '</tr></thead><tbody>';
		
		if ( $post_types_locked && \is_array( $site_enabled ) ) {
			// round-trip the site's own selection while the lock is active
			foreach ( $site_enabled as $enabled_type ) {
				\printf(
					'<input type="hidden" name="%1$s[]" value="%2$s">',
					\esc_attr( Post_Types::OPTION_NAME ),
					\esc_attr( $enabled_type )
				);
			}
		}
		
		foreach ( $post_types as $post_type ) {
			echo '<tr><td>' . \esc_html( $post_type->labels->name ) . '</td>';
			\printf(
				'<td><input type="checkbox" %1$s value="%2$s" %3$s %4$s aria-label="%5$s"></td>',
				$post_types_locked ? '' : 'name="' . \esc_attr( Post_Types::OPTION_NAME . '[]' ) . '"',
				\esc_attr( $post_type->name ),
				\checked( \in_array( $post_type->name, $supported, true ), true, false ),
				\disabled( $post_types_locked, true, false ),
				\esc_attr(
					\sprintf(
						/* translators: %s: post type name */
						\__( 'Generate images for %s', 'image-socialiser' ),
						$post_type->labels->name
					)
				)
			);
			
			if ( $has_multiple_templates ) {
				$current_template = (string) ( $cpt_templates[ $post_type->name ] ?? '' );
				
				\printf(
					'<td><select name="%1$s[%2$s]">',
					\esc_attr( Template_Registry::OPTION_CPT_TEMPLATES ),
					\esc_attr( $post_type->name )
				);
				\printf(
					'<option value="">%s</option>',
					\esc_html__( 'Site default', 'image-socialiser' )
				);
				
				foreach ( $templates as $template_id => $template_model ) {
					\printf(
						'<option value="%1$s" %2$s>%3$s</option>',
						\esc_attr( $template_id ),
						\selected( $current_template, $template_id, false ),
						\esc_html( $template_model->get_label() )
					);
				}
				
				echo '</select></td>';
			}
			
			echo '<td>';
			self::render_media_field( [
				'key' => $post_type->name,
				'locked' => $fallbacks_locked ? 'network' : '',
				'option' => Resolver::OPTION_FALLBACKS,
			] );
			echo '</td></tr>';
		}
		
		echo '</tbody></table>';
		
		if ( $post_types_locked ) {
			self::render_lockout_notice( 'network' );
		}
	}
	
	/**
	 * Render the settings page.
	 */
	public static function render_page(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}
		
		$current_tab = self::get_current_tab();
		
		echo '<div class="wrap"><h1>' . \esc_html__( 'Social Images', 'image-socialiser' ) . '</h1>';
		
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['image-socialiser-regenerating'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>'
				. \esc_html__(
					'Regeneration of all images has been queued and runs in the background.',
					'image-socialiser'
				)
				. '</p></div>';
		}
		
		self::render_font_status_notice();
		echo '<nav class="nav-tab-wrapper">';
		
		foreach ( self::get_tabs() as $tab => $label ) {
			\printf(
				'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
				\esc_url(
					\add_query_arg(
						[
							'page' => self::PAGE_SLUG,
							'tab' => $tab,
						],
						\admin_url( 'options-general.php' )
					)
				),
				$tab === $current_tab ? ' nav-tab-active' : '',
				\esc_html( $label )
			);
		}
		
		echo '</nav>';
		
		match ( $current_tab ) {
			'context' => self::render_context_tab(),
			'design' => self::render_design_tab(),
			'advanced' => self::render_advanced_tab(),
			default => self::render_general_tab(),
		};
		
		echo '</div>';
	}
	
	/**
	 * Render the General tab: status card, post types, tools.
	 */
	private static function render_general_tab(): void {
		$design_url = \add_query_arg(
			[
				'page' => self::PAGE_SLUG,
				'tab' => 'design',
			],
			\admin_url( 'options-general.php' )
		);
		$context_url = \add_query_arg(
			[
				'page' => self::PAGE_SLUG,
				'tab' => 'context',
			],
			\admin_url( 'options-general.php' )
		);
		
		echo '<div class="image-socialiser-intro">';
		echo '<div class="image-socialiser-intro-text">';
		echo '<h2>' . \esc_html__( 'Sharing images for everything you publish', 'image-socialiser' ) . '</h2>';
		echo '<p>' . \esc_html__(
			'Image Socialiser generates a branded sharing image for every piece of content on this site — the picture social networks, chat apps, and search results show when someone links to you. Images are rendered in the background from your design and served as static files; per post, a custom title, subtitle, or image can be set right in the editor.',
			'image-socialiser'
		) . '</p>';
		echo '<p>' . \wp_kses(
			\sprintf(
				/* translators: 1: Design Center URL, 2: Special Pages & Archives URL */
				\__( 'Pick a design and set your colors, fonts, and logo in the <a href="%1$s">Design Center</a>. Images for term archives, the front page, search, and the 404 page live under <a href="%2$s">Special Pages &amp; Archives</a>. Below, choose which post types get images.', 'image-socialiser' ),
				\esc_url( $design_url ),
				\esc_url( $context_url )
			),
			[
				'a' => [ 'href' => [] ],
			]
		) . '</p>';
		echo '</div>';
		echo '<figure class="image-socialiser-intro-preview">';
		echo '<div id="image-socialiser-hero-preview"></div>';
		echo '<figcaption class="description">' . \wp_kses(
			\sprintf(
				/* translators: %s: Design Center URL */
				\__( 'Your current design, rendered live — change it in the <a href="%s">Design Center</a>.', 'image-socialiser' ),
				\esc_url( $design_url )
			),
			[
				'a' => [ 'href' => [] ],
			]
		) . '</figcaption>';
		echo '</figure>';
		echo '</div>';
		self::render_status_card();
		echo '<form action="options.php" method="post">';
		\settings_fields( self::GROUP_GENERAL );
		\do_settings_sections( self::PAGE_SLUG . '-general' );
		\submit_button();
		echo '</form>';
		echo '<h2>' . \esc_html__( 'Tools', 'image-socialiser' ) . '</h2>';
		echo '<div class="image-socialiser-tools">';
		echo '<form action="' . \esc_url( \admin_url( 'admin-post.php' ) ) . '" method="post">';
		\wp_nonce_field( self::ACTION_REGENERATE_ALL );
		echo '<input type="hidden" name="action" value="' . \esc_attr( self::ACTION_REGENERATE_ALL ) . '">';
		\submit_button( \__( 'Regenerate all images', 'image-socialiser' ), 'secondary' );
		echo '<p class="description">'
			. \esc_html__(
				'Bumps the design version and rebuilds every image in batched background jobs.',
				'image-socialiser'
			)
			. '</p>';
		echo '</form></div>';
	}
	
	/**
	 * Render the Special Pages & Archives tab.
	 */
	private static function render_context_tab(): void {
		echo '<form action="options.php" method="post">';
		\settings_fields( self::GROUP_CONTEXT );
		\do_settings_sections( self::PAGE_SLUG . '-context' );
		\submit_button();
		echo '</form>';
	}
	
	/**
	 * Render the Design Center tab: controls, fonts, pinned gallery.
	 */
	private static function render_design_tab(): void {
		echo '<div class="image-socialiser-design-columns">';
		echo '<div class="image-socialiser-design-main">';
		echo '<form action="options.php" method="post">';
		\settings_fields( self::GROUP_DESIGN );
		\do_settings_sections( self::PAGE_SLUG . '-design' );
		\submit_button();
		echo '</form>';
		self::render_fonts_manager();
		echo '</div>';
		echo '<aside class="image-socialiser-design-aside">';
		echo '<h2>' . \esc_html__( 'Designs', 'image-socialiser' ) . '</h2>';
		echo '<div id="image-socialiser-template-gallery"></div>';
		echo '<p class="description">' . \esc_html__(
			'Previews use your current design tokens; color changes update them live, fonts and layout apply after saving. Badges show where each design is assigned.',
			'image-socialiser'
		) . '</p>';
		
		if ( Theme_Support::covers( 'template' ) ) {
			echo '<p class="description">' . \esc_html(
				\sprintf(
					/* translators: 1: theme name, 2: template label */
					\__( 'Your theme (%1$s) sets the site-wide default template: %2$s.', 'image-socialiser' ),
					Theme_Support::get_theme_name(),
					Template_Registry::get( 'default' )->get_label()
				)
			) . '</p>';
		}
		
		echo '</aside></div>';
	}
	
	/**
	 * Render the Advanced tab: in-product documentation.
	 */
	private static function render_advanced_tab(): void {
		echo '<div class="image-socialiser-advanced">';
		echo '<h2>' . \esc_html__( 'The layer & token model', 'image-socialiser' ) . '</h2>';
		echo '<p>' . \esc_html__(
			'Every design is a stack of layers (background, image, rect, text) on a 1200×630 canvas, rendered identically by the server (PNG) and the browser preview (SVG). Layers reference design tokens — your brand colors, fonts, logo, and layout settings — so one change propagates to every design. Filenames embed a hash of the fully resolved design: any effective change mints new URLs and regenerates images automatically.',
			'image-socialiser'
		) . '</p>';
		echo '<h2>' . \esc_html__( 'Authoring a custom design', 'image-socialiser' ) . '</h2>';
		echo '<p>' . \esc_html__(
			'Ship complete designs as design packs: one directory with a declarative design.json manifest, optional fonts, and background assets. Scaffold a starter pack on the command line:',
			'image-socialiser'
		) . '</p>';
		echo '<pre><code>wp image-socialiser scaffold-design acme-poster</code></pre>';
		\printf(
			'<p>%s</p>',
			\wp_kses(
				\sprintf(
					/* translators: %s: link to the documentation */
					\__( 'The full format reference — layers, tokens, fonts, assets, previewing — lives in the %s.', 'image-socialiser' ),
					'<a href="https://github.com/krafit/image-socialiser/wiki/Creating-designs" target="_blank" rel="noopener noreferrer">'
						. \esc_html__( 'Creating designs guide', 'image-socialiser' )
						. '</a>'
				),
				[ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ]
			)
		);
		echo '<h2>' . \esc_html__( 'Bundling designs with a theme or plugin', 'image-socialiser' ) . '</h2>';
		echo '<p>' . \esc_html__(
			'Register a pack from your theme or plugin — the design appears in every selector and this gallery automatically:',
			'image-socialiser'
		) . '</p>';
		echo '<pre><code>' . \esc_html(
			"add_action( 'image_socialiser_register_designs', static function (): void {\n"
			. "\timage_socialiser_register_design( __DIR__ . '/social-designs/poster/design.json' );\n"
			. '} );'
		) . '</code></pre>';
		\printf(
			'<p>%s</p>',
			\wp_kses(
				\sprintf(
					/* translators: 1: link to the theme-authors guide, 2: link to the developer reference */
					\__( 'Themes can also hand over individual design values via add_theme_support(); see the %1$s. All hooks, options, and CLI commands are documented in the %2$s.', 'image-socialiser' ),
					'<a href="https://github.com/krafit/image-socialiser/wiki/For-theme-authors" target="_blank" rel="noopener noreferrer">'
						. \esc_html__( 'theme authors guide', 'image-socialiser' )
						. '</a>',
					'<a href="https://github.com/krafit/image-socialiser/wiki/Developer-Reference" target="_blank" rel="noopener noreferrer">'
						. \esc_html__( 'developer reference', 'image-socialiser' )
						. '</a>'
				),
				[ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ]
			)
		);
		echo '</div>';
	}
	
	/**
	 * Render the status card: renderer, queue, counts, diagnostics.
	 */
	private static function render_status_card(): void {
		$renderer = Renderer_Factory::create();
		$counts = self::get_status_counts();
		
		echo '<div class="image-socialiser-status-card">';
		echo '<p class="image-socialiser-status-line">';
		
		if ( $renderer === null ) {
			echo '<span class="image-socialiser-status-bad">● </span>'
				. \esc_html__( 'No image renderer is available — images cannot be generated.', 'image-socialiser' );
		}
		else if ( $counts['failed'] > 0 ) {
			echo '<span class="image-socialiser-status-warn">● </span>'
				. \esc_html(
					\sprintf(
						/* translators: 1: ready count, 2: failed count */
						\__( '%1$d images generated, %2$d failed — see the details below.', 'image-socialiser' ),
						$counts['ready'],
						$counts['failed']
					)
				);
		}
		else if ( $counts['pending'] > 0 ) {
			echo '<span class="image-socialiser-status-ok">● </span>'
				. \esc_html(
					\sprintf(
						/* translators: 1: ready count, 2: pending count */
						\__( '%1$d images generated, %2$d queued and generating in the background.', 'image-socialiser' ),
						$counts['ready'],
						$counts['pending']
					)
				);
		}
		else {
			echo '<span class="image-socialiser-status-ok">● </span>'
				. \esc_html(
					\sprintf(
						/* translators: %d: ready count */
						\__( 'Everything is fine — %d images generated.', 'image-socialiser' ),
						$counts['ready']
					)
				);
		}
		
		echo '</p>';
		echo '<details><summary>' . \esc_html__( 'Details', 'image-socialiser' ) . '</summary>';
		self::render_health();
		echo '</details></div>';
	}
	
	/**
	 * Get the total status counts across all subject kinds.
	 *
	 * @return	array{failed: int, pending: int, ready: int} The counts
	 */
	private static function get_status_counts(): array {
		global $wpdb;
		
		$totals = self::get_context_state_counts();
		
		foreach ( [ $wpdb->postmeta, $wpdb->termmeta ] as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$counts = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT meta_value AS status, COUNT(*) AS total FROM {$table} WHERE meta_key = %s GROUP BY meta_value",
					Generator::META_STATUS
				),
				\OBJECT_K
			);
			
			foreach ( [ 'failed', 'pending', 'ready' ] as $status ) {
				$totals[ $status ] += (int) ( $counts[ $status ]->total ?? 0 );
			}
		}
		
		return $totals;
	}
	
	/**
	 * Sanitize the per-post-type template selection.
	 *
	 * @param	mixed	$input The raw settings input
	 * @return	array<string, string> Post type => template identifier
	 */
	public static function sanitize_cpt_templates( mixed $input ): array {
		if ( ! \is_array( $input ) ) {
			return [];
		}
		
		$public_types = \get_post_types( [ 'public' => true ] );
		// 'default' is the legacy alias for the site default template
		$template_ids = \array_merge( [ 'default' ], \array_keys( Template_Registry::get_all() ) );
		$sanitized = [];
		
		foreach ( $input as $post_type => $template_id ) {
			$template_id = \sanitize_key( (string) $template_id );
			
			if ( ! isset( $public_types[ $post_type ] ) || $template_id === '' ) {
				continue;
			}
			
			if ( ! \in_array( $template_id, $template_ids, true ) ) {
				continue;
			}
			
			$sanitized[ (string) $post_type ] = $template_id;
		}
		
		return $sanitized;
	}
	
	/**
	 * Sanitize the fallback image attachment IDs.
	 *
	 * @param	mixed	$input The raw settings input
	 * @return	array<string, int> Post type (or '_default') => attachment ID
	 */
	public static function sanitize_fallbacks( mixed $input ): array {
		if ( ! \is_array( $input ) ) {
			return [];
		}
		
		$public_types = \get_post_types( [ 'public' => true ] );
		$sanitized = [];
		
		foreach ( $input as $key => $attachment_id ) {
			$attachment_id = \absint( $attachment_id );
			
			if ( $attachment_id === 0 ) {
				continue;
			}
			
			if ( $key !== '_default' && ! isset( $public_types[ $key ] ) ) {
				continue;
			}
			
			$sanitized[ (string) $key ] = $attachment_id;
		}
		
		return $sanitized;
	}
	
	/**
	 * Sanitize the enabled post types.
	 *
	 * @param	mixed	$input The raw settings input
	 * @return	string[] The enabled post type names
	 */
	public static function sanitize_post_types( mixed $input ): array {
		if ( ! \is_array( $input ) ) {
			return [];
		}
		
		$public_types = \get_post_types( [ 'public' => true ] );
		unset( $public_types['attachment'] );
		
		return \array_values( \array_intersect( $public_types, \array_map( '\strval', $input ) ) );
	}
	
	/**
	 * Render the notice for the last font action.
	 */
	public static function render_font_status_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = \sanitize_key( (string) ( $_GET['font-status'] ?? '' ) );
		
		if ( $status === '' ) {
			return;
		}
		
		$messages = [
			'deleted' => [ 'success', \__( 'The font has been deleted.', 'image-socialiser' ) ],
			'in-use' => [ 'error', \__( 'This font cannot be deleted while it is in use.', 'image-socialiser' ) ],
			'move-failed' => [ 'error', \__( 'The font file could not be stored.', 'image-socialiser' ) ],
			'no-file' => [ 'error', \__( 'No font file was uploaded.', 'image-socialiser' ) ],
			'not-found' => [ 'error', \__( 'The font could not be found.', 'image-socialiser' ) ],
			'too-large' => [ 'error', \__( 'The font file exceeds the 2 MB size limit.', 'image-socialiser' ) ],
			'uploaded' => [ 'success', \__( 'The font has been uploaded and is available in all font selects.', 'image-socialiser' ) ],
			'wrong-type' => [ 'error', \__( 'Only valid TTF and OTF font files are accepted.', 'image-socialiser' ) ],
		];
		
		if ( ! isset( $messages[ $status ] ) ) {
			return;
		}
		
		\printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			\esc_attr( $messages[ $status ][0] ),
			\esc_html( $messages[ $status ][1] )
		);
	}
	
	/**
	 * Render the custom fonts manager (own forms, outside the main form).
	 */
	private static function render_fonts_manager(): void {
		echo '<h2>' . \esc_html__( 'Fonts', 'image-socialiser' ) . '</h2>';
		echo '<div class="image-socialiser-fonts">';
		
		if ( self::is_network_locked( 'fonts' ) ) {
			echo '<p class="description">'
				. \esc_html__(
					'Fonts are managed network-wide; per-site fonts are disabled on this network.',
					'image-socialiser'
				)
				. '</p></div>';
			
			return;
		}
		
		$index = Custom_Fonts::get_index();
		
		if ( \count( $index ) > 0 ) {
			echo '<table class="widefat striped image-socialiser-table"><thead><tr>';
			echo '<th>' . \esc_html__( 'Font', 'image-socialiser' ) . '</th>';
			echo '<th>' . \esc_html__( 'File', 'image-socialiser' ) . '</th>';
			echo '<th></th>';
			echo '</tr></thead><tbody>';
			
			foreach ( $index as $font_id => $entry ) {
				$usage = Custom_Fonts::get_usage( (string) $font_id );
				
				echo '<tr>';
				echo '<td>' . \esc_html( $entry['label'] ) . '</td>';
				echo '<td><code>' . \esc_html( $entry['file'] ) . '</code></td>';
				echo '<td>';
				
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
					\wp_nonce_field( Custom_Fonts::ACTION_DELETE . '_' . $font_id );
					echo '<input type="hidden" name="action" value="' . \esc_attr( Custom_Fonts::ACTION_DELETE ) . '">';
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
		\wp_nonce_field( Custom_Fonts::ACTION_UPLOAD );
		echo '<input type="hidden" name="action" value="' . \esc_attr( Custom_Fonts::ACTION_UPLOAD ) . '">';
		echo '<p><label>' . \esc_html__( 'Font name', 'image-socialiser' ) . ' ';
		echo '<input type="text" name="image_socialiser_font_label" class="regular-text"></label></p>';
		echo '<p><input type="file" name="image_socialiser_font" accept=".ttf,.otf" required></p>';
		\submit_button( \__( 'Upload font', 'image-socialiser' ), 'secondary' );
		echo '<p class="description">'
			. \esc_html__(
				'TTF and OTF files only, 2 MB maximum. Uploaded fonts appear in every font select and in the editor preview. Make sure the font license allows embedding it in generated images.',
				'image-socialiser'
			)
			. '</p>';
		echo '</form></div>';
	}
	
	/**
	 * Count archive/special subject states from their per-subject options.
	 *
	 * @return	array{failed: int, pending: int, ready: int} The status counts
	 */
	private static function get_context_state_counts(): array {
		global $wpdb;
		
		$counts = [
			'failed' => 0,
			'pending' => 0,
			'ready' => 0,
		];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$values = $wpdb->get_col(
			"SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE 'image\_socialiser\_state\_%'"
		);
		
		foreach ( \is_array( $values ) ? $values : [] as $value ) {
			$state = \maybe_unserialize( (string) $value );
			$status = \is_array( $state ) ? (string) ( $state[ Generator::META_STATUS ] ?? '' ) : '';
			
			if ( isset( $counts[ $status ] ) ) {
				$counts[ $status ]++;
			}
		}
		
		return $counts;
	}
	
	/**
	 * Render the health panel.
	 */
	private static function render_health(): void {
		global $wpdb;
		
		$renderer = Renderer_Factory::create();
		$renderer_label = \__( 'None available', 'image-socialiser' );
		
		if ( $renderer !== null ) {
			$renderer_label = $renderer->get_id();
			
			if ( $renderer->get_id() === 'imagick' && \class_exists( Imagick::class ) ) {
				$version = Imagick::getVersion();
				$renderer_label .= ' (' . (string) ( $version['versionString'] ?? '' ) . ')';
			}
		}
		
		$adapter = Seo_Handler::get_active_adapter();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$counts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_value AS status, COUNT(*) AS total FROM {$wpdb->postmeta} WHERE meta_key = %s GROUP BY meta_value",
				Generator::META_STATUS
			),
			\OBJECT_K
		);
		// term subjects mirror the post status counts in term meta
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$term_counts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_value AS status, COUNT(*) AS total FROM {$wpdb->termmeta} WHERE meta_key = %s GROUP BY meta_value",
				Generator::META_STATUS
			),
			\OBJECT_K
		);
		$context_counts = self::get_context_state_counts();
		$format_counts = static fn( int $ready, int $terms, int $contexts ): string => \sprintf(
			/* translators: 1: post image count, 2: term image count, 3: archive/special image count */
			\__( '%1$d posts, %2$d terms, %3$d archives/special pages', 'image-socialiser' ),
			$ready,
			$terms,
			$contexts
		);
		$rows = [
			\__( 'Active renderer', 'image-socialiser' ) => $renderer_label,
			\__( 'SEO integration', 'image-socialiser' ) => $adapter !== null
				? $adapter->get_id()
				: \__( 'None detected', 'image-socialiser' ),
			\__( 'Background queue', 'image-socialiser' ) => \function_exists( 'as_enqueue_async_action' )
				? \__( 'Action Scheduler', 'image-socialiser' )
				: \__( 'WP-Cron fallback', 'image-socialiser' ),
			\__( 'Generated images', 'image-socialiser' ) => $format_counts(
				(int) ( $counts['ready']->total ?? 0 ),
				(int) ( $term_counts['ready']->total ?? 0 ),
				$context_counts['ready']
			),
			\__( 'Queued', 'image-socialiser' ) => $format_counts(
				(int) ( $counts['pending']->total ?? 0 ),
				(int) ( $term_counts['pending']->total ?? 0 ),
				$context_counts['pending']
			),
			\__( 'Failed', 'image-socialiser' ) => $format_counts(
				(int) ( $counts['failed']->total ?? 0 ),
				(int) ( $term_counts['failed']->total ?? 0 ),
				$context_counts['failed']
			),
		];
		
		echo '<table class="widefat striped image-socialiser-table"><tbody>';
		
		foreach ( $rows as $label => $value ) {
			echo '<tr><td>' . \esc_html( $label ) . '</td><td>' . \esc_html( $value ) . '</td></tr>';
		}
		
		echo '</tbody></table>';
	}
}
