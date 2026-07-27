<?php
declare(strict_types=1);

namespace happyhappy\ImageSocialiser;

use happyhappy\ImageSocialiser\Admin\Settings;
use happyhappy\ImageSocialiser\Cli\Commands;
use happyhappy\ImageSocialiser\Editor\Assets;
use happyhappy\ImageSocialiser\Editor\Term_Fields;
use happyhappy\ImageSocialiser\Editor\Meta;
use happyhappy\ImageSocialiser\Editor\Rest_Controller;
use happyhappy\ImageSocialiser\Generation\Scheduler;
use happyhappy\ImageSocialiser\Multisite\Network_Settings;
use happyhappy\ImageSocialiser\Rendering\Custom_Fonts;
use happyhappy\ImageSocialiser\Rendering\Renderer_Factory;
use happyhappy\ImageSocialiser\Seo\OEmbed_Integration;
use happyhappy\ImageSocialiser\Seo\Podlove_Integration;
use happyhappy\ImageSocialiser\Seo\Seo_Handler;
use happyhappy\ImageSocialiser\Template\Design_Packs;
use happyhappy\ImageSocialiser\Template\Theme_Support;

/**
 * Main plugin class.
 *
 * @author	Simon Kraft
 * @license	GPL2
 * @package	happyhappy\ImageSocialiser
 */
final class Plugin {
	/**
	 * @var	string Plugin version.
	 */
	public const string VERSION = '1.0.0-beta.1';
	
	/**
	 * @var	self|null Unique instance of the class.
	 */
	private static ?self $instance = null;
	
	/**
	 * @var	string The full path to the main plugin file.
	 */
	public string $plugin_file = '';
	
	/**
	 * Get a unique instance of the class.
	 *
	 * @return	self The single instance of this class
	 */
	public static function get_instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		
		return self::$instance;
	}
	
	/**
	 * Initialize the plugin.
	 */
	public function init(): void {
		Assets::init();
		Design_Packs::init();
		Term_Fields::init();
		Custom_Fonts::init();
		Network_Settings::init();
		Settings::init();
		Meta::init();
		Rest_Controller::init();
		OEmbed_Integration::init();
		Podlove_Integration::init();
		Scheduler::init();
		Seo_Handler::init();
		Theme_Support::init();
		
		\add_action( 'init', [ $this, 'load_textdomain' ] );
		\add_action( 'init', [ $this, 'maybe_upgrade' ], 5 );
		// per-request Font Library cache must not leak across blog switches
		\add_action( 'switch_blog', [ \happyhappy\ImageSocialiser\Rendering\Font_Library::class, 'reset_cache' ] );
		\add_action( 'admin_notices', [ $this, 'maybe_show_renderer_notice' ] );
		
		if ( \defined( 'WP_CLI' ) && \constant( 'WP_CLI' ) ) {
			\WP_CLI::add_command( 'image-socialiser', Commands::class );
		}
	}
	
	/**
	 * Run one-time upgrade routines when the stored version changes.
	 */
	public function maybe_upgrade(): void {
		$stored = (string) \get_option( 'image_socialiser_version', '' );
		
		if ( $stored === self::VERSION ) {
			return;
		}
		
		// 0.14.0: split the shared context-state map into per-subject
		// options (autoload off) to avoid concurrent-job write races
		$legacy = \get_option( \happyhappy\ImageSocialiser\Generation\Subject::LEGACY_OPTION_STATE, null );
		
		if ( \is_array( $legacy ) ) {
			foreach ( $legacy as $slug => $state ) {
				if ( \is_array( $state ) && \preg_match( '/^[a-z0-9_\-]+$/', (string) $slug ) === 1 ) {
					\update_option(
						\happyhappy\ImageSocialiser\Generation\Subject::OPTION_STATE_PREFIX . $slug,
						$state,
						false
					);
				}
			}
			
			\delete_option( \happyhappy\ImageSocialiser\Generation\Subject::LEGACY_OPTION_STATE );
		}
		
		\update_option( 'image_socialiser_version', self::VERSION, false );
	}
	
	/**
	 * Load the bundled translations.
	 */
	public function load_textdomain(): void {
		\load_plugin_textdomain(
			'image-socialiser',
			false,
			\dirname( \plugin_basename( $this->plugin_file ) ) . '/languages'
		);
	}
	
	/**
	 * Show an admin notice if no image renderer is available.
	 */
	public function maybe_show_renderer_notice(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}
		
		if ( Renderer_Factory::create() !== null ) {
			return;
		}
		
		echo '<div class="notice notice-error"><p>'
			. \esc_html__(
				'Image Socialiser: No image renderer is available. Please ask your host to enable the Imagick or GD PHP extension.',
				'image-socialiser'
			)
			. '</p></div>';
	}
}
