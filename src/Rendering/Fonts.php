<?php
declare(strict_types=1);

namespace happyhappy\ImageSocialiser\Rendering;

use happyhappy\ImageSocialiser\Multisite\Multisite;
use happyhappy\ImageSocialiser\Plugin;
use happyhappy\ImageSocialiser\Template\Template_Model;

/**
 * Registry for all usable fonts.
 *
 * Bundled fonts plus custom uploads (see Custom_Fonts); additional
 * fonts can be registered by themes or plugins via the filter below.
 *
 * @author	Simon Kraft
 * @license	GPL2
 * @package	happyhappy\ImageSocialiser
 */
final class Fonts {
	/**
	 * @var	array<string, string> Bundled fonts: identifier => filename in assets/fonts/.
	 */
	public const array BUNDLED = [
		'alfa-slab-one' => 'AlfaSlabOne-Regular.ttf',
		'anton' => 'Anton-Regular.ttf',
		'fraunces-bold' => 'Fraunces-Bold.ttf',
		'fraunces-regular' => 'Fraunces-Regular.ttf',
		'instrument-serif' => 'InstrumentSerif-Regular.ttf',
		'inter-bold' => 'Inter-Bold.ttf',
		'inter-regular' => 'Inter-Regular.ttf',
		'lora-bold' => 'Lora-Bold.ttf',
		'lora-regular' => 'Lora-Regular.ttf',
		'oswald-bold' => 'Oswald-Bold.ttf',
		'oswald-regular' => 'Oswald-Regular.ttf',
		'playfair-display-bold' => 'PlayfairDisplay-Bold.ttf',
		'playfair-display-regular' => 'PlayfairDisplay-Regular.ttf',
		'roboto-slab-bold' => 'RobotoSlab-Bold.ttf',
		'roboto-slab-regular' => 'RobotoSlab-Regular.ttf',
		'space-grotesk-bold' => 'SpaceGrotesk-Bold.ttf',
		'space-grotesk-regular' => 'SpaceGrotesk-Regular.ttf',
	];
	
	/**
	 * @var	string[] Single-weight display faces, offered for headings only.
	 */
	public const array HEADING_ONLY = [
		'alfa-slab-one',
		'anton',
		'instrument-serif',
	];
	
	/**
	 * Get all registered fonts as identifier => absolute file path.
	 *
	 * @return	array<string, string> The registered fonts
	 */
	public static function get_all(): array {
		$font_directory = \plugin_dir_path( Plugin::get_instance()->plugin_file ) . 'assets/fonts/';
		
		/**
		 * Filter the registered fonts.
		 *
		 * @param	array<string, string>	$fonts Font identifier => absolute TTF/OTF file path
		 */
		$bundled = [];
		
		foreach ( self::BUNDLED as $font_id => $filename ) {
			$bundled[ $font_id ] = $font_directory . $filename;
		}
		
		return (array) \apply_filters(
			'image_socialiser_fonts',
			\array_merge(
				$bundled,
				Custom_Fonts::get_all_network(),
				// per-site fonts (uploads + Font Library) only where the
				// section is overridable
				Multisite::is_locked( 'fonts' )
					? []
					: \array_merge( Custom_Fonts::get_all(), Font_Library::get_all() )
			)
		);
	}
	
	/**
	 * Get all registered fonts as identifier => public URL.
	 *
	 * Only fonts inside the plugin directory can be mapped to a URL;
	 * others can be provided via the filter below.
	 *
	 * @return	array<string, string> The font URLs
	 */
	public static function get_all_urls(): array {
		$plugin_file = Plugin::get_instance()->plugin_file;
		$plugin_path = \plugin_dir_path( $plugin_file );
		$plugin_url = \plugin_dir_url( $plugin_file );
		$urls = [];
		
		$custom_urls = \array_merge(
			Custom_Fonts::get_all_network_urls(),
			Multisite::is_locked( 'fonts' )
				? []
				: \array_merge( Custom_Fonts::get_all_urls(), Font_Library::get_all_urls() )
		);
		
		foreach ( self::get_all() as $font_id => $path ) {
			if ( isset( $custom_urls[ $font_id ] ) ) {
				$urls[ $font_id ] = $custom_urls[ $font_id ];
				
				continue;
			}
			
			if ( ! \is_string( $path ) || ! \str_starts_with( $path, $plugin_path ) ) {
				continue;
			}
			
			$urls[ $font_id ] = $plugin_url . \substr( $path, \strlen( $plugin_path ) );
		}
		
		/**
		 * Filter the font URLs used by the browser preview.
		 *
		 * @param	array<string, string>	$urls Font identifier => public font file URL
		 */
		return (array) \apply_filters( 'image_socialiser_font_urls', $urls );
	}
	
	/**
	 * Get a fingerprint of the fonts a template uses.
	 *
	 * Combines font identifiers and file hashes, so replacing a font
	 * file (custom fonts, plugin update shipping new files) changes
	 * every content hash that uses it. File hashes are memoized per
	 * request.
	 *
	 * @param	\happyhappy\ImageSocialiser\Template\Template_Model	$model The template
	 * @return	string The font fingerprint
	 */
	public static function get_fingerprint( Template_Model $model ): string {
		static $file_hashes = [];
		
		$font_ids = [];
		
		foreach ( $model->get_layers() as $layer ) {
			if ( ( $layer['type'] ?? '' ) !== 'text' ) {
				continue;
			}
			
			$font_ids[] = (string) ( $layer['font'] ?? 'inter-regular' );
		}
		
		$font_ids = \array_unique( $font_ids );
		\sort( $font_ids );
		$parts = [];
		
		foreach ( $font_ids as $font_id ) {
			$path = self::get_path( $font_id );
			
			if ( ! isset( $file_hashes[ $font_id ] ) ) {
				$file_hashes[ $font_id ] = $path !== '' ? (string) \md5_file( $path ) : 'missing';
			}
			
			$parts[] = $font_id . ':' . $file_hashes[ $font_id ];
		}
		
		return \implode( ',', $parts );
	}
	
	/**
	 * Get a human-readable label for a font identifier.
	 *
	 * Custom fonts use their stored label; others get a title-cased
	 * identifier.
	 *
	 * @param	string	$font_id The font identifier
	 * @return	string The label
	 */
	public static function get_label( string $font_id ): string {
		$custom_label = Custom_Fonts::get_label( $font_id );
		
		if ( $custom_label !== '' ) {
			return $custom_label;
		}
		
		$library_label = Font_Library::get_label( $font_id );
		
		if ( $library_label !== '' ) {
			return $library_label;
		}
		
		return \ucwords( \str_replace( [ '-', '_' ], ' ', $font_id ) );
	}
	
	/**
	 * Check whether a font is a heading-only display face.
	 *
	 * Single-weight display faces have no matching regular weight for
	 * body text; the settings offer them for the heading font only.
	 *
	 * @param	string	$font_id The font identifier
	 * @return	bool Whether the font is heading-only
	 */
	public static function is_heading_only( string $font_id ): bool {
		return \in_array( $font_id, self::HEADING_ONLY, true );
	}
	
	/**
	 * Get the file path for a font identifier.
	 *
	 * @param	string	$font_id The font identifier
	 * @return	string The absolute file path or an empty string
	 */
	public static function get_path( string $font_id ): string {
		$fonts = self::get_all();
		$path = $fonts[ $font_id ] ?? '';
		
		if ( ! \is_string( $path ) || ! \is_readable( $path ) ) {
			return '';
		}
		
		return $path;
	}
}
