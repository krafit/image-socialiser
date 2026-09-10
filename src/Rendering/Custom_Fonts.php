<?php
declare(strict_types=1);

namespace happyhappy\ImageSocialiser\Rendering;

use happyhappy\ImageSocialiser\Admin\Settings;
use happyhappy\ImageSocialiser\Generation\Storage;
use happyhappy\ImageSocialiser\Multisite\Multisite;
use happyhappy\ImageSocialiser\Template\Brand;
use happyhappy\ImageSocialiser\Template\Theme_Support;

// prevent direct file access
if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Custom font uploads.
 *
 * TTF and OTF only — both renderers consume them directly via
 * FreeType, and browsers load them fine for the preview; WOFF/WOFF2
 * would need server-side decompression for no gain. Files are stored
 * in the plugin's own fonts directory (not the media library) with
 * generated names, and an index option maps identifiers to files.
 *
 * Fonts are parsed by FreeType server-side, so upload validation
 * (magic bytes, size cap) is a security control, not cosmetics.
 *
 * @author	Simon Kraft
 * @license	GPL2
 * @package	happyhappy\ImageSocialiser
 */
final class Custom_Fonts {
	/**
	 * @var	string The admin-post action for deleting a font.
	 */
	public const string ACTION_DELETE = 'image_socialiser_delete_font';
	
	/**
	 * @var	string The admin-post action for uploading a font.
	 */
	public const string ACTION_UPLOAD = 'image_socialiser_upload_font';
	
	/**
	 * @var	int Maximum font file size in bytes (2 MB).
	 */
	public const int MAX_FILE_SIZE = 2097152;
	
	/**
	 * @var	string Option name of the font index (id => file, label).
	 */
	public const string OPTION_NAME = 'image_socialiser_custom_fonts';
	
	/**
	 * @var	string Network option name of the network font library index.
	 */
	public const string NETWORK_OPTION_NAME = 'image_socialiser_network_custom_fonts';
	
	/**
	 * Get the network font library as identifier => absolute file path.
	 *
	 * Files live in the main site's fonts directory and are available
	 * to every site in the network.
	 *
	 * @return	array<string, string> The network fonts
	 */
	public static function get_all_network(): array {
		if ( ! Multisite::is_active() ) {
			return [];
		}
		
		$directory = self::get_network_directory()['path'];
		$fonts = [];
		
		foreach ( self::get_network_index() as $font_id => $entry ) {
			$path = $directory . '/' . $entry['file'];
			
			if ( \is_readable( $path ) ) {
				$fonts[ $font_id ] = $path;
			}
		}
		
		return $fonts;
	}
	
	/**
	 * Get the network font library as identifier => public URL.
	 *
	 * @return	array<string, string> The network font URLs
	 */
	public static function get_all_network_urls(): array {
		if ( ! Multisite::is_active() ) {
			return [];
		}
		
		$directory = self::get_network_directory();
		$urls = [];
		
		foreach ( self::get_network_index() as $font_id => $entry ) {
			if ( \is_readable( $directory['path'] . '/' . $entry['file'] ) ) {
				$urls[ $font_id ] = $directory['url'] . '/' . $entry['file'];
			}
		}
		
		return $urls;
	}
	
	/**
	 * Get the network fonts directory (main site's fonts directory).
	 *
	 * @return	array{path: string, url: string} The directory path and URL
	 */
	public static function get_network_directory(): array {
		return Multisite::in_main_site( static fn(): array => self::get_directory() );
	}
	
	/**
	 * Get the network font index.
	 *
	 * @return	array<string, array{file: string, label: string}> The index
	 */
	public static function get_network_index(): array {
		if ( ! Multisite::is_active() ) {
			return [];
		}
		
		$index = \get_site_option( self::NETWORK_OPTION_NAME, [] );
		
		if ( ! \is_array( $index ) ) {
			return [];
		}
		
		$sanitized = [];
		
		foreach ( $index as $font_id => $entry ) {
			if ( ! \is_array( $entry ) || empty( $entry['file'] ) ) {
				continue;
			}
			
			$sanitized[ (string) $font_id ] = [
				'file' => \basename( (string) $entry['file'] ),
				'label' => (string) ( $entry['label'] ?? $font_id ),
			];
		}
		
		return $sanitized;
	}
	
	/**
	 * Delete a custom font (file + index entry).
	 *
	 * @param	string	$font_id The font identifier
	 * @return	bool Whether the font was deleted
	 */
	public static function delete( string $font_id ): bool {
		$index = self::get_index();
		
		if ( ! isset( $index[ $font_id ] ) ) {
			return false;
		}
		
		$path = self::get_directory()['path'] . '/' . $index[ $font_id ]['file'];
		
		if ( \is_file( $path ) ) {
			\wp_delete_file( $path );
		}
		
		unset( $index[ $font_id ] );
		\update_option( self::OPTION_NAME, $index );
		
		return true;
	}
	
	/**
	 * Delete a network font (file + network index entry).
	 *
	 * @param	string	$font_id The font identifier
	 * @return	bool Whether the font was deleted
	 */
	public static function delete_network( string $font_id ): bool {
		$index = self::get_network_index();
		
		if ( ! isset( $index[ $font_id ] ) ) {
			return false;
		}
		
		$path = self::get_network_directory()['path'] . '/' . $index[ $font_id ]['file'];
		
		if ( \is_file( $path ) ) {
			\wp_delete_file( $path );
		}
		
		unset( $index[ $font_id ] );
		\update_site_option( self::NETWORK_OPTION_NAME, $index );
		
		return true;
	}
	
	/**
	 * Check where a network font is currently referenced.
	 *
	 * Only the network brand tokens are checked — individual sites may
	 * still reference the font; their pipeline falls back to Inter.
	 *
	 * @param	string	$font_id The font identifier
	 * @return	string A description of the usage, or an empty string when unused
	 */
	public static function get_network_usage( string $font_id ): string {
		$network_brand = Multisite::get_network_option( 'brand' ) ?? [];
		$fonts = [
			(string) ( $network_brand['body_font'] ?? '' ),
			(string) ( $network_brand['heading_font'] ?? '' ),
		];
		
		if ( \in_array( $font_id, $fonts, true ) ) {
			return \__( 'the network brand settings', 'image-socialiser' );
		}
		
		return '';
	}
	
	/**
	 * Get all custom fonts as identifier => absolute file path.
	 *
	 * Missing files are skipped (the token pipeline then falls back to
	 * the bundled fonts).
	 *
	 * @return	array<string, string> The custom fonts
	 */
	public static function get_all(): array {
		$directory = self::get_directory()['path'];
		$fonts = [];
		
		foreach ( self::get_index() as $font_id => $entry ) {
			$path = $directory . '/' . $entry['file'];
			
			if ( \is_readable( $path ) ) {
				$fonts[ $font_id ] = $path;
			}
		}
		
		return $fonts;
	}
	
	/**
	 * Get all custom fonts as identifier => public URL.
	 *
	 * @return	array<string, string> The font URLs
	 */
	public static function get_all_urls(): array {
		$directory = self::get_directory();
		$urls = [];
		
		foreach ( self::get_index() as $font_id => $entry ) {
			if ( \is_readable( $directory['path'] . '/' . $entry['file'] ) ) {
				$urls[ $font_id ] = $directory['url'] . '/' . $entry['file'];
			}
		}
		
		return $urls;
	}
	
	/**
	 * Get the fonts storage directory (inside the og-images directory).
	 *
	 * @return	array{path: string, url: string} The directory path and URL
	 */
	public static function get_directory(): array {
		$storage = ( new Storage() )->get_directory();
		
		return [
			'path' => $storage['path'] . '/fonts',
			'url' => $storage['url'] . '/fonts',
		];
	}
	
	/**
	 * Get the font index (id => file, label).
	 *
	 * @return	array<string, array{file: string, label: string}> The index
	 */
	public static function get_index(): array {
		$index = \get_option( self::OPTION_NAME, [] );
		
		if ( ! \is_array( $index ) ) {
			return [];
		}
		
		$sanitized = [];
		
		foreach ( $index as $font_id => $entry ) {
			if ( ! \is_array( $entry ) || empty( $entry['file'] ) ) {
				continue;
			}
			
			$sanitized[ (string) $font_id ] = [
				'file' => \basename( (string) $entry['file'] ),
				'label' => (string) ( $entry['label'] ?? $font_id ),
			];
		}
		
		return $sanitized;
	}
	
	/**
	 * Get the human-readable label of a custom font.
	 *
	 * @param	string	$font_id The font identifier
	 * @return	string The label or an empty string for unknown fonts
	 */
	public static function get_label( string $font_id ): string {
		return self::get_index()[ $font_id ]['label']
			?? self::get_network_index()[ $font_id ]['label']
			?? '';
	}
	
	/**
	 * Handle the font deletion admin-post request.
	 */
	public static function handle_delete(): void {
		if ( ! \current_user_can( 'manage_options' ) || Multisite::is_locked( 'fonts' ) ) {
			\wp_die( \esc_html__( 'You are not allowed to do that.', 'image-socialiser' ) );
		}
		
		$font_id = \sanitize_key( (string) ( $_POST['font_id'] ?? '' ) );
		
		\check_admin_referer( self::ACTION_DELETE . '_' . $font_id );
		
		$usage = self::get_usage( $font_id );
		
		if ( $usage !== '' ) {
			self::redirect( 'in-use' );
		}
		
		self::redirect( self::delete( $font_id ) ? 'deleted' : 'not-found' );
	}
	
	/**
	 * Handle the font upload admin-post request.
	 */
	public static function handle_upload(): void {
		// the settings page hides the form while the network owns the
		// fonts section; the handler must not rely on that
		if ( ! \current_user_can( 'manage_options' ) || Multisite::is_locked( 'fonts' ) ) {
			\wp_die( \esc_html__( 'You are not allowed to do that.', 'image-socialiser' ) );
		}
		
		\check_admin_referer( self::ACTION_UPLOAD );
		
		$file = $_FILES['image_socialiser_font'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$error = self::validate_upload( \is_array( $file ) ? $file : [] );
		
		if ( $error !== '' ) {
			self::redirect( $error );
		}
		
		$label = \sanitize_text_field(
			(string) \wp_unslash( $_POST['image_socialiser_font_label'] ?? '' )
		);
		
		if ( $label === '' ) {
			$label = \pathinfo( \sanitize_file_name( (string) $file['name'] ), \PATHINFO_FILENAME );
		}
		
		$stored = self::store( (string) $file['tmp_name'], (string) $file['name'], $label );
		
		self::redirect( $stored ? 'uploaded' : 'move-failed' );
	}
	
	/**
	 * Initialize the upload handling.
	 */
	public static function init(): void {
		\add_action( 'admin_post_' . self::ACTION_DELETE, [ self::class, 'handle_delete' ] );
		\add_action( 'admin_post_' . self::ACTION_UPLOAD, [ self::class, 'handle_upload' ] );
	}
	
	/**
	 * Check where a font is currently referenced.
	 *
	 * Guards deletion: the site's brand tokens and the theme support
	 * declaration are checked. (The pipeline would fall back to Inter
	 * anyway, but blocking is clearer than silently changing designs.)
	 *
	 * @param	string	$font_id The font identifier
	 * @return	string A description of the usage, or an empty string when unused
	 */
	public static function get_usage( string $font_id ): string {
		$site_tokens = Brand::get_site_tokens();
		
		if ( \in_array( $font_id, [ $site_tokens['body_font'], $site_tokens['heading_font'] ], true ) ) {
			return \__( 'the brand settings', 'image-socialiser' );
		}
		
		$theme_overrides = Theme_Support::get_brand_overrides();
		
		if ( \in_array( $font_id, [ $theme_overrides['body_font'] ?? '', $theme_overrides['heading_font'] ?? '' ], true ) ) {
			return \__( 'your theme', 'image-socialiser' );
		}
		
		return '';
	}
	
	/**
	 * Store a validated font file and register it in the index.
	 *
	 * The target filename is generated (identifier + content hash), so
	 * no user-supplied name ever hits the filesystem.
	 *
	 * @param	string	$temporary_path The uploaded temporary file
	 * @param	string	$original_name The original filename (for the extension)
	 * @param	string	$label The display label
	 * @return	bool Whether the font was stored
	 */
	public static function store( string $temporary_path, string $original_name, string $label ): bool {
		$index = self::get_index();
		$entry = self::store_file(
			$temporary_path,
			$original_name,
			$label,
			$index,
			self::get_directory()['path']
		);
		
		if ( $entry === null ) {
			return false;
		}
		
		$index[ $entry['id'] ] = [
			'file' => $entry['file'],
			'label' => $label,
		];
		\update_option( self::OPTION_NAME, $index );
		
		return true;
	}
	
	/**
	 * Store a validated font file in the network library.
	 *
	 * Must run in the main site's context (network admin); the file
	 * lands in the main site's fonts directory, the index in a network
	 * option — available to every site.
	 *
	 * @param	string	$temporary_path The uploaded temporary file
	 * @param	string	$original_name The original filename (for the extension)
	 * @param	string	$label The display label
	 * @return	bool Whether the font was stored
	 */
	public static function store_network( string $temporary_path, string $original_name, string $label ): bool {
		$index = self::get_network_index();
		$entry = Multisite::in_main_site(
			fn(): ?array => self::store_file(
				$temporary_path,
				$original_name,
				$label,
				$index,
				self::get_directory()['path']
			)
		);
		
		if ( $entry === null ) {
			return false;
		}
		
		$index[ $entry['id'] ] = [
			'file' => $entry['file'],
			'label' => $label,
		];
		\update_site_option( self::NETWORK_OPTION_NAME, $index );
		
		return true;
	}
	
	/**
	 * Write a font file with a generated identifier and filename.
	 *
	 * @param	string	$temporary_path The uploaded temporary file
	 * @param	string	$original_name The original filename (for the extension)
	 * @param	string	$label The display label
	 * @param	array	$index The index to check identifiers against
	 * @param	string	$directory The target directory
	 * @return	array{file: string, id: string}|null The stored entry or null on failure
	 */
	private static function store_file(
		string $temporary_path,
		string $original_name,
		string $label,
		array $index,
		string $directory
	): ?array {
		$extension = \strtolower( \pathinfo( $original_name, \PATHINFO_EXTENSION ) ) === 'otf' ? 'otf' : 'ttf';
		// sanitize_title transliterates accents/umlauts (Schräglage -> schraeglage-ish)
		$base_id = \function_exists( 'sanitize_title' )
			? \sanitize_key( \sanitize_title( $label ) )
			: \sanitize_key( \str_replace( ' ', '-', $label ) );
		
		if ( $base_id === '' ) {
			$base_id = 'custom-font';
		}
		
		$font_id = $base_id;
		$suffix = 2;
		
		while ( isset( $index[ $font_id ] ) || Fonts::get_path( $font_id ) !== '' ) {
			$font_id = $base_id . '-' . $suffix;
			$suffix++;
		}
		
		$filesystem = self::get_filesystem();
		
		if ( $filesystem === null || ! \wp_mkdir_p( $directory ) ) {
			return null;
		}
		
		// Storage::ensure_directory() guards the parent; the fonts
		// subdirectory needs its own
		$guard_path = $directory . '/index.php';
		
		if ( ! $filesystem->exists( $guard_path ) ) {
			$filesystem->put_contents(
				$guard_path,
				'<?php' . \PHP_EOL . '// silence is golden' . \PHP_EOL,
				\FS_CHMOD_FILE
			);
		}
		
		$filename = $font_id . '-' . \substr( (string) \md5_file( $temporary_path ), 0, 8 ) . '.' . $extension;
		$target = $directory . '/' . $filename;
		// is_uploaded_file() keeps the guarantee move_uploaded_file()
		// used to provide: only a genuine PHP upload is ever moved,
		// anything else (WP-CLI, a design pack) is copied instead
		$stored = \is_uploaded_file( $temporary_path )
			? $filesystem->move( $temporary_path, $target, true )
			: $filesystem->copy( $temporary_path, $target, true );
		
		if ( ! $stored ) {
			return null;
		}
		
		$filesystem->chmod( $target, \FS_CHMOD_FILE );
		
		return [
			'file' => $filename,
			'id' => $font_id,
		];
	}
	
	/**
	 * Get the initialized WordPress filesystem abstraction.
	 *
	 * Font files are written through WP_Filesystem rather than the
	 * raw PHP file functions, both because WordPress.org requires it
	 * and because it respects the site's configured filesystem
	 * method. Callers must handle null: on a host using an FTP or
	 * SSH method without stored credentials, WP_Filesystem() cannot
	 * initialize without prompting, and an upload fails cleanly
	 * instead of writing through an unexpected path.
	 *
	 * @return	\WP_Filesystem_Base|null The filesystem, or null when unavailable
	 */
	private static function get_filesystem(): ?\WP_Filesystem_Base {
		global $wp_filesystem;
		
		if ( ! $wp_filesystem instanceof \WP_Filesystem_Base ) {
			require_once \ABSPATH . 'wp-admin/includes/file.php';
			
			if ( ! \WP_Filesystem() ) {
				return null;
			}
		}
		
		return $wp_filesystem instanceof \WP_Filesystem_Base ? $wp_filesystem : null;
	}
	
	/**
	 * Validate an uploaded font file.
	 *
	 * Checks the upload state, the size cap, the extension, and the
	 * sfnt magic bytes (0x00010000 for TrueType, 'OTTO' for CFF
	 * OpenType) — a renamed non-font file never passes.
	 *
	 * @param	array	$file The $_FILES entry
	 * @return	string An error code, or an empty string when valid
	 */
	public static function validate_upload( array $file ): string {
		if ( empty( $file['tmp_name'] ) || ! \is_readable( (string) $file['tmp_name'] )
			|| ( (int) ( $file['error'] ?? \UPLOAD_ERR_NO_FILE ) ) !== \UPLOAD_ERR_OK
		) {
			return 'no-file';
		}
		
		if ( (int) ( $file['size'] ?? 0 ) > self::MAX_FILE_SIZE
			|| (int) \filesize( (string) $file['tmp_name'] ) > self::MAX_FILE_SIZE
		) {
			return 'too-large';
		}
		
		$extension = \strtolower( \pathinfo( (string) ( $file['name'] ?? '' ), \PATHINFO_EXTENSION ) );
		
		if ( ! \in_array( $extension, [ 'otf', 'ttf' ], true ) ) {
			return 'wrong-type';
		}
		
		if ( ! self::is_valid_font_file( (string) $file['tmp_name'] ) ) {
			return 'wrong-type';
		}
		
		return '';
	}
	
	/**
	 * Check whether a file is a parseable TrueType/OpenType font.
	 *
	 * Verifies the sfnt magic bytes (0x00010000 for TrueType, 'OTTO'
	 * for CFF OpenType) — a renamed non-font file never passes. Also
	 * used by the design-pack loader before a pack font ever reaches
	 * FreeType.
	 *
	 * @param	string	$path The absolute file path
	 * @return	bool Whether the file is a valid font file
	 */
	public static function is_valid_font_file( string $path ): bool {
		if ( ! \is_readable( $path ) ) {
			return false;
		}
		
		// WP_Filesystem has no partial-read API — get_contents() would
		// pull an entire font file into memory to inspect four bytes,
		// and this runs for every Font Library face on admin screens,
		// so the stream functions stay with this justification
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$handle = \fopen( $path, 'rb' );
		
		if ( $handle === false ) {
			return false;
		}
		
		$magic = (string) \fread( $handle, 4 );
		\fclose( $handle );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		
		return $magic === "\x00\x01\x00\x00" || $magic === 'OTTO';
	}
	
	/**
	 * Redirect back to the settings page with a status code.
	 *
	 * @param	string	$status The status code for the notice
	 */
	private static function redirect( string $status ): void {
		\wp_safe_redirect(
			\add_query_arg(
				'font-status',
				\rawurlencode( $status ),
				\admin_url( 'options-general.php?page=' . Settings::PAGE_SLUG )
			)
		);
		exit;
	}
}
