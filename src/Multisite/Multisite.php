<?php
declare(strict_types=1);

namespace happyhappy\ImageSocialiser\Multisite;

/**
 * Multisite resolution helpers.
 *
 * Network options hold network-wide defaults per settings section. The
 * super admin decides per section whether sites may override:
 *
 * - 'site' (default): network values act as defaults until a site
 *   saves its own section; theme support still applies on top.
 * - 'network' (locked): network values always apply — a network lock
 *   beats theme support (decided in the roadmap).
 *
 * Media chosen at network level (logo, cover art, background,
 * fallbacks, fonts) lives in the main site's library/uploads and is
 * resolved there.
 *
 * @author	Simon Kraft
 * @license	GPL2
 * @package	happyhappy\ImageSocialiser
 */
final class Multisite {
	/**
	 * @var	string Option name of the per-section override permissions.
	 */
	public const string OPTION_PERMISSIONS = 'image_socialiser_network_permissions';
	
	/**
	 * @var	string Prefix of the network-level section options.
	 */
	public const string OPTION_PREFIX = 'image_socialiser_network_';
	
	/**
	 * @var	string[] The permission-managed settings sections.
	 */
	public const array SECTIONS = [ 'brand', 'content', 'fallbacks', 'fonts', 'layout', 'post_types' ];
	
	/**
	 * Resolve a section through the network layer.
	 *
	 * @param	string	$section The section name
	 * @param	mixed	$site_value The site's sanitized value
	 * @param	bool	$site_saved Whether the site has saved its own option
	 * @return	array{locked: bool, network: bool, value: mixed} Resolution result (network = network value applies)
	 */
	public static function resolve_section( string $section, mixed $site_value, bool $site_saved ): array {
		if ( ! self::is_active() ) {
			return [
				'locked' => false,
				'network' => false,
				'value' => $site_value,
			];
		}
		
		$network_value = self::get_network_option( $section );
		$locked = self::is_locked( $section );
		
		if ( $locked && $network_value !== null ) {
			return [
				'locked' => true,
				'network' => true,
				'value' => $network_value,
			];
		}
		
		if ( ! $site_saved && $network_value !== null ) {
			return [
				'locked' => false,
				'network' => true,
				'value' => $network_value,
			];
		}
		
		return [
			'locked' => $locked,
			'network' => false,
			'value' => $site_value,
		];
	}
	
	/**
	 * Get the raw network option of a section.
	 *
	 * @param	string	$section The section name
	 * @return	array|null The stored network value or null when unset
	 */
	public static function get_network_option( string $section ): ?array {
		if ( ! self::is_active() ) {
			return null;
		}
		
		$value = \get_site_option( self::OPTION_PREFIX . $section, null );
		
		return \is_array( $value ) ? $value : null;
	}
	
	/**
	 * Get the absolute file path of a main-site attachment.
	 *
	 * @param	int	$attachment_id The attachment ID (main site's library)
	 * @return	string The path or an empty string
	 */
	public static function get_main_site_attachment_path( int $attachment_id ): string {
		if ( $attachment_id === 0 ) {
			return '';
		}
		
		return (string) self::in_main_site(
			static fn() => (string) \get_attached_file( $attachment_id )
		);
	}
	
	/**
	 * Get image data (url, dimensions, type) of a main-site attachment.
	 *
	 * @param	int	$attachment_id The attachment ID (main site's library)
	 * @return	array|null The image data or null
	 */
	public static function get_main_site_attachment_image( int $attachment_id ): ?array {
		if ( $attachment_id === 0 ) {
			return null;
		}
		
		return self::in_main_site( static function() use ( $attachment_id ): ?array {
			$source = \wp_get_attachment_image_src( $attachment_id, 'full' );
			
			if ( ! \is_array( $source ) ) {
				return null;
			}
			
			return [
				'height' => (int) $source[2],
				'type' => (string) \get_post_mime_type( $attachment_id ),
				'url' => (string) $source[0],
				'width' => (int) $source[1],
			];
		} );
	}
	
	/**
	 * Get the main site's uploads base (for the network font library).
	 *
	 * @return	array{basedir: string, baseurl: string} The uploads base
	 */
	public static function get_main_site_uploads(): array {
		$uploads = self::in_main_site( static fn(): array => \wp_upload_dir() );
		
		return [
			'basedir' => (string) $uploads['basedir'],
			'baseurl' => (string) $uploads['baseurl'],
		];
	}
	
	/**
	 * Run a callback in the main site's context.
	 *
	 * @param	callable	$callback The callback
	 * @return	mixed The callback's return value
	 */
	public static function in_main_site( callable $callback ): mixed {
		if ( ! self::is_active() || \get_current_blog_id() === \get_main_site_id() ) {
			return $callback();
		}
		
		\switch_to_blog( \get_main_site_id() );
		
		try {
			return $callback();
		} finally {
			\restore_current_blog();
		}
	}
	
	/**
	 * Check whether this is a multisite network.
	 *
	 * @return	bool Whether multisite is active
	 */
	public static function is_active(): bool {
		return \function_exists( 'is_multisite' ) && \is_multisite();
	}
	
	/**
	 * Check whether a section is locked network-wide.
	 *
	 * @param	string	$section The section name
	 * @return	bool Whether sites may not override the section
	 */
	public static function is_locked( string $section ): bool {
		if ( ! self::is_active() ) {
			return false;
		}
		
		return self::get_permission( $section ) === 'network';
	}
	
	/**
	 * Get the override permission of a section.
	 *
	 * @param	string	$section The section name
	 * @return	string 'network' (locked) or 'site' (overridable, default)
	 */
	public static function get_permission( string $section ): string {
		$permissions = \get_site_option( self::OPTION_PERMISSIONS, [] );
		$permission = \is_array( $permissions ) ? (string) ( $permissions[ $section ] ?? 'site' ) : 'site';
		
		return $permission === 'network' ? 'network' : 'site';
	}
	
	/**
	 * Get a fingerprint of the network resolution state.
	 *
	 * Part of the content hash: whether network values (and thus
	 * main-site media) apply changes the rendered result even when the
	 * attachment IDs look identical.
	 *
	 * @return	string The multisite fingerprint
	 */
	public static function get_fingerprint(): string {
		if ( ! self::is_active() ) {
			return '';
		}
		
		$flags = [];
		
		foreach ( self::SECTIONS as $section ) {
			$flags[] = $section . ':' . ( self::uses_network( $section ) ? '1' : '0' );
		}
		
		return \get_main_site_id() . '|' . \implode( ',', $flags );
	}
	
	/**
	 * Check whether a section currently resolves to network values.
	 *
	 * @param	string	$section The section name
	 * @return	bool Whether the network layer provides the section
	 */
	public static function uses_network( string $section ): bool {
		if ( ! self::is_active() || self::get_network_option( $section ) === null ) {
			return false;
		}
		
		if ( self::is_locked( $section ) ) {
			return true;
		}
		
		$site_option = \get_option( self::get_site_option_name( $section ), null );
		
		return ! \is_array( $site_option );
	}
	
	/**
	 * Map a section name to its site option name.
	 *
	 * @param	string	$section The section name
	 * @return	string The site option name
	 */
	public static function get_site_option_name( string $section ): string {
		return match ( $section ) {
			'brand' => 'image_socialiser_brand',
			'content' => 'image_socialiser_content',
			'fallbacks' => 'image_socialiser_fallbacks',
			'fonts' => 'image_socialiser_custom_fonts',
			'layout' => 'image_socialiser_layout',
			'post_types' => 'image_socialiser_post_types',
			default => 'image_socialiser_' . $section,
		};
	}
}
