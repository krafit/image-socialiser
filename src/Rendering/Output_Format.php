<?php
declare(strict_types=1);

namespace happyhappy\ImageSocialiser\Rendering;

use happyhappy\ImageSocialiser\Template\Binding;
use happyhappy\ImageSocialiser\Template\Template_Model;

// prevent direct file access
if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides whether a render is written as PNG or JPEG.
 *
 * PNG is the general format: the flat, text-and-shape designs stay
 * crisp and are in fact smaller than their JPEG equivalents. Once a
 * photograph is composited into the canvas, however, PNG grows past
 * the size limits social scrapers enforce (Bluesky's card service
 * rejects images over roughly 1 MB), so those renders are written as
 * JPEG instead — an order of magnitude smaller at no visible cost on
 * photographic content.
 *
 * The decision is derived from the resolved template plus its binding,
 * never configured, and it is part of the content hash, so a post that
 * gains or loses its photo mints a new filename and regenerates.
 *
 * @author	Simon Kraft
 * @license	GPL2
 * @package	happyhappy\ImageSocialiser
 */
final class Output_Format {
	/**
	 * @var	string The JPEG format identifier (also the file extension).
	 */
	public const string JPEG = 'jpg';
	
	/**
	 * @var	int Default JPEG quality.
	 */
	public const int JPEG_QUALITY = 82;
	
	/**
	 * @var	string The PNG format identifier (also the file extension).
	 */
	public const string PNG = 'png';
	
	/**
	 * @var	string[] Image sources that carry user-supplied imagery.
	 *
	 * 		These are photographs in practice — a featured image,
	 * 		podcast cover art, a brand background photo. The logo is
	 * 		deliberately absent: logos are flat artwork and must not
	 * 		drag an otherwise flat design into JPEG.
	 */
	private const array PHOTOGRAPHIC_SOURCES = [ 'brand_background', 'cover_art', 'featured' ];
	
	/**
	 * Get the file extension for a format.
	 *
	 * @param	string	$format The format identifier
	 * @return	string The file extension without a dot
	 */
	public static function get_extension( string $format ): string {
		return $format === self::JPEG ? self::JPEG : self::PNG;
	}
	
	/**
	 * Get the MIME type for a format.
	 *
	 * @param	string	$format The format identifier
	 * @return	string The MIME type
	 */
	public static function get_mime_type( string $format ): string {
		return $format === self::JPEG ? 'image/jpeg' : 'image/png';
	}
	
	/**
	 * Get every supported file extension.
	 *
	 * @return	string[] The supported extensions without a dot
	 */
	public static function get_extensions(): array {
		return [ self::PNG, self::JPEG ];
	}
	
	/**
	 * Get the supported extensions as a regular expression group.
	 *
	 * @return	string The alternation, e.g. 'png|jpg'
	 */
	public static function get_extension_pattern(): string {
		return \implode( '|', \array_map( '\preg_quote', self::get_extensions() ) );
	}
	
	/**
	 * Normalize an arbitrary value to a supported format identifier.
	 *
	 * @param	string	$format The stored or supplied format
	 * @return	string The format identifier, defaulting to PNG
	 */
	public static function normalize( string $format ): string {
		return $format === self::JPEG ? self::JPEG : self::PNG;
	}
	
	/**
	 * Decide the output format for a resolved template.
	 *
	 * A layer only counts when its source actually resolves to a file:
	 * an empty featured image or an unset cover art token means the
	 * layer is skipped at render time and must not force JPEG.
	 *
	 * @param	Template_Model	$model The resolved template
	 * @param	Binding	$data The dynamic data binding
	 * @return	string The format identifier
	 */
	public static function resolve( Template_Model $model, Binding $data ): string {
		$format = self::PNG;
		
		foreach ( $model->get_layers() as $layer ) {
			if ( ( $layer['type'] ?? '' ) !== 'image' ) {
				continue;
			}
			
			if ( self::is_photographic_layer( $layer, $data ) ) {
				$format = self::JPEG;
				
				break;
			}
		}
		
		/**
		 * Filter the output format of a generated image.
		 *
		 * Return Output_Format::PNG or Output_Format::JPEG to override
		 * the automatic choice. The value is part of the content hash,
		 * so changing it regenerates affected images.
		 *
		 * @since	1.0.0
		 *
		 * @param	string	$format The resolved format identifier
		 * @param	Template_Model	$model The resolved template
		 * @param	Binding	$data The dynamic data binding
		 */
		return self::normalize(
			(string) \apply_filters( 'image_socialiser_output_format', $format, $model, $data )
		);
	}
	
	/**
	 * Check whether an image layer contributes a photograph.
	 *
	 * @param	array	$layer The image layer
	 * @param	Binding	$data The dynamic data binding
	 * @return	bool Whether the layer resolves to a photograph
	 */
	private static function is_photographic_layer( array $layer, Binding $data ): bool {
		$source = (string) ( $layer['source'] ?? '' );
		
		if ( $source === '' || $source === 'logo' ) {
			return false;
		}
		
		$is_user_image = \in_array( $source, self::PHOTOGRAPHIC_SOURCES, true );
		
		// template assets are usually flat textures shipped by a
		// design; only a genuinely photographic file counts
		if ( ! $is_user_image && $source !== 'template_asset' ) {
			return false;
		}
		
		$path = $data->get_image_path( $source, $layer );
		
		if ( $path === '' || ! \is_readable( $path ) ) {
			return false;
		}
		
		return $is_user_image || self::is_photographic_file( $path );
	}
	
	/**
	 * Check whether a file is a photographic (lossy) image.
	 *
	 * @param	string	$path The absolute file path
	 * @return	bool Whether the file is a JPEG
	 */
	private static function is_photographic_file( string $path ): bool {
		$info = @\getimagesize( $path );
		
		return \is_array( $info ) && ( $info[2] ?? 0 ) === \IMAGETYPE_JPEG;
	}
}
