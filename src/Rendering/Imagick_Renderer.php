<?php
declare(strict_types=1);

namespace happyhappy\ImageSocialiser\Rendering;

use happyhappy\ImageSocialiser\Template\Binding;
use happyhappy\ImageSocialiser\Template\Template_Model;
use Imagick;
use ImagickDraw;
use ImagickException;
use ImagickPixel;

// prevent direct file access
if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Primary renderer using Imagick's native draw API.
 *
 * Draws layer primitives directly instead of rasterizing SVG, so no
 * SVG delegate (librsvg) is required and no untrusted SVG is ever
 * parsed on the server.
 *
 * @author	Simon Kraft
 * @license	GPL2
 * @package	happyhappy\ImageSocialiser
 */
final class Imagick_Renderer implements Renderer {
	/**
	 * @inheritdoc
	 */
	public function get_id(): string {
		return 'imagick';
	}
	
	/**
	 * @inheritdoc
	 */
	public function is_available(): bool {
		return \extension_loaded( 'imagick' ) && \class_exists( Imagick::class );
	}
	
	/**
	 * @inheritdoc
	 */
	public function render(
		Template_Model $model,
		Binding $data,
		string $format = Output_Format::PNG
	): string {
		$format = Output_Format::normalize( $format );
		try {
			$canvas = new Imagick();
			$canvas->newImage(
				$model->get_width(),
				$model->get_height(),
				new ImagickPixel( 'none' )
			);
			$canvas->setImageFormat( $format === Output_Format::JPEG ? 'jpeg' : 'png' );
			
			foreach ( $model->get_layers() as $layer ) {
				switch ( $layer['type'] ?? '' ) {
					case 'background':
						$this->draw_background( $canvas, $layer );
						break;
					case 'image':
						$this->draw_image( $canvas, $layer, $data );
						break;
					case 'rect':
						$this->draw_rect( $canvas, $layer );
						break;
					case 'text':
						$this->draw_text( $canvas, $layer, $data );
						break;
				}
			}
			
			$canvas->setImageDepth( 8 );
			
			if ( $format === Output_Format::JPEG ) {
				// JPEG has no alpha: composite onto an opaque canvas
				// first, otherwise any uncovered area encodes as black
				$flattened = new Imagick();
				$flattened->newImage(
					$model->get_width(),
					$model->get_height(),
					new ImagickPixel( '#ffffff' )
				);
				$flattened->setImageFormat( 'jpeg' );
				$flattened->compositeImage( $canvas, Imagick::COMPOSITE_OVER, 0, 0 );
				$flattened->setImageCompressionQuality( $this->get_jpeg_quality() );
				$flattened->setImageDepth( 8 );
				$flattened->stripImage();
				$blob = $flattened->getImageBlob();
				$flattened->clear();
				$canvas->clear();
			}
			else {
				$blob = $canvas->getImageBlob();
				$canvas->clear();
			}
		}
		catch ( ImagickException $exception ) {
			throw new Rendering_Exception(
				\esc_html( 'Imagick rendering failed: ' . $exception->getMessage() ),
				0,
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the previous exception is chained, not output
				$exception
			);
		}
		
		if ( $blob === '' ) {
			throw new Rendering_Exception( 'Imagick returned an empty image.' );
		}
		
		return $blob;
	}
	
	/**
	 * Read a bound image file with an explicit coder.
	 *
	 * Imagick::readImage() on a bare path lets ImageMagick pick the
	 * coder by sniffing the file, which is the surface the MVG/MSL
	 * delegate issues lived on. The real image type is detected
	 * first and the coder named explicitly, so a file that is not
	 * one of the four raster formats we support is never handed to
	 * a delegate — it is skipped.
	 *
	 * @param	string	$path The absolute file path
	 * @return	\Imagick|null The image, or null when the type is unsupported
	 * @throws	\ImagickException If reading fails
	 */
	private function read_image_file( string $path ): ?Imagick {
		$coders = [
			\IMAGETYPE_GIF => 'gif',
			\IMAGETYPE_JPEG => 'jpg',
			\IMAGETYPE_PNG => 'png',
			\IMAGETYPE_WEBP => 'webp',
		];
		
		if ( \defined( 'IMAGETYPE_AVIF' ) ) {
			$coders[ \IMAGETYPE_AVIF ] = 'avif';
		}
		
		$size = \wp_getimagesize( $path );
		$coder = $coders[ (int) ( $size[2] ?? 0 ) ] ?? '';
		
		if ( $coder === '' ) {
			return null;
		}
		
		$image = new Imagick();
		$image->readImage( $coder . ':' . $path );
		
		return $image;
	}
	
	/**
	 * Draw a background layer (solid color or two-stop linear gradient).
	 *
	 * @param	\Imagick	$canvas The target canvas
	 * @param	array	$layer The layer definition
	 * @throws	\ImagickException If drawing fails
	 */
	private function draw_background( Imagick $canvas, array $layer ): void {
		$width = $canvas->getImageWidth();
		$height = $canvas->getImageHeight();
		$fill = \is_array( $layer['fill'] ?? null ) ? $layer['fill'] : [];
		$background = new Imagick();
		
		if ( ( $fill['kind'] ?? 'solid' ) === 'gradient' ) {
			$stops = \is_array( $fill['stops'] ?? null ) ? \array_values( $fill['stops'] ) : [];
			$from = Template_Model::sanitize_color( $stops[0]['color'] ?? null );
			$to = Template_Model::sanitize_color(
				$stops[ \count( $stops ) - 1 ]['color'] ?? null,
				'#ffffff'
			);
			$is_horizontal = ( $fill['direction'] ?? 'vertical' ) === 'horizontal';
			$background->newPseudoImage(
				$is_horizontal ? $height : $width,
				$is_horizontal ? $width : $height,
				'gradient:' . $from . '-' . $to
			);
			
			if ( $is_horizontal ) {
				$background->rotateImage( new ImagickPixel( 'none' ), -90 );
			}
		}
		else {
			$color = Template_Model::sanitize_color( $fill['color'] ?? null );
			$background->newPseudoImage( $width, $height, 'canvas:' . $color );
		}
		
		$canvas->compositeImage( $background, Imagick::COMPOSITE_OVER, 0, 0 );
		$background->clear();
	}
	
	/**
	 * Draw an image layer (featured image, logo, …).
	 *
	 * @param	\Imagick	$canvas The target canvas
	 * @param	array	$layer The layer definition
	 * @param	\happyhappy\ImageSocialiser\Template\Binding	$data The dynamic data binding
	 * @throws	\ImagickException If drawing fails
	 */
	private function draw_image( Imagick $canvas, array $layer, Binding $data ): void {
		$path = $data->get_image_path( (string) ( $layer['source'] ?? '' ), $layer );
		
		if ( $path === '' ) {
			return;
		}
		
		$box = $this->get_box( $layer, $canvas->getImageWidth(), $canvas->getImageHeight() );
		$frame = \is_array( $layer['frame'] ?? null ) ? $layer['frame'] : [];
		
		if ( isset( $frame['color'] ) ) {
			// offset frame ("sticker"): a solid rect behind the image,
			// shifted down-right; sized to the box, so it pairs with
			// cover-fitted images
			$offset = (int) ( $frame['offset'] ?? 12 );
			$frame_draw = new ImagickDraw();
			$frame_draw->setFillColor(
				new ImagickPixel( Template_Model::sanitize_color( $frame['color'] ) )
			);
			$frame_draw->rectangle(
				$box['x'] + $offset,
				$box['y'] + $offset,
				$box['x'] + $offset + $box['w'],
				$box['y'] + $offset + $box['h']
			);
			$canvas->drawImage( $frame_draw );
		}
		
		$image = $this->read_image_file( $path );
		
		if ( $image === null ) {
			return;
		}
		
		$image->setIteratorIndex( 0 );
		$image = $image->getImage();
		$offset_x = $box['x'];
		$offset_y = $box['y'];
		
		if ( ( $layer['fit'] ?? 'cover' ) === 'contain' ) {
			$image->thumbnailImage( $box['w'], $box['h'], true );
			$offset_x += match ( $layer['align'] ?? 'center' ) {
				'left' => 0,
				'right' => $box['w'] - $image->getImageWidth(),
				default => (int) \round( ( $box['w'] - $image->getImageWidth() ) / 2 ),
			};
			$offset_y += (int) \round( ( $box['h'] - $image->getImageHeight() ) / 2 );
		}
		else {
			$image->cropThumbnailImage( $box['w'], $box['h'] );
		}
		
		$opacity = (float) ( $layer['opacity'] ?? 1 );
		
		if ( $opacity < 1 ) {
			$image->setImageAlphaChannel( Imagick::ALPHACHANNEL_SET );
			$image->evaluateImage(
				Imagick::EVALUATE_MULTIPLY,
				\max( 0, $opacity ),
				Imagick::CHANNEL_ALPHA
			);
		}
		
		$canvas->compositeImage( $image, Imagick::COMPOSITE_OVER, $offset_x, $offset_y );
		$image->clear();
	}
	
	/**
	 * Draw a rect layer (solid fill and/or stroke, optional corner
	 * radius, opacity).
	 *
	 * A rect may be fill-only, stroke-only (outlined frame — set
	 * 'fill' to 'none') or both. The stroke is centered on the box
	 * edge, matching SVG semantics.
	 *
	 * @param	\Imagick	$canvas The target canvas
	 * @param	array	$layer The layer definition
	 * @throws	\ImagickException If drawing fails
	 */
	private function draw_rect( Imagick $canvas, array $layer ): void {
		$box = $this->get_box( $layer, $canvas->getImageWidth(), $canvas->getImageHeight() );
		$radius = \max( 0, (int) ( $layer['radius'] ?? 0 ) );
		$opacity = \min( 1, \max( 0, (float) ( $layer['opacity'] ?? 1 ) ) );
		$stroke = \is_array( $layer['stroke'] ?? null ) ? $layer['stroke'] : [];
		$stroke_width = \max( 0, (int) ( $stroke['width'] ?? 0 ) );
		$has_fill = \is_string( $layer['fill'] ?? null ) && $layer['fill'] !== 'none';
		
		if ( ! $has_fill && $stroke_width === 0 ) {
			return;
		}
		
		$draw = new ImagickDraw();
		
		if ( $has_fill ) {
			$draw->setFillColor(
				new ImagickPixel( Template_Model::sanitize_color( $layer['fill'] ) )
			);
		}
		else {
			$draw->setFillOpacity( 0 );
		}
		
		if ( $has_fill && $opacity < 1 ) {
			$draw->setFillOpacity( $opacity );
		}
		
		if ( $stroke_width > 0 ) {
			$draw->setStrokeColor(
				new ImagickPixel( Template_Model::sanitize_color( $stroke['color'] ?? null, '#ffffff' ) )
			);
			$draw->setStrokeWidth( $stroke_width );
			
			if ( $opacity < 1 ) {
				$draw->setStrokeOpacity( $opacity );
			}
		}
		
		if ( $radius > 0 ) {
			$draw->roundRectangle(
				$box['x'],
				$box['y'],
				$box['x'] + $box['w'],
				$box['y'] + $box['h'],
				$radius,
				$radius
			);
		}
		else {
			$draw->rectangle( $box['x'], $box['y'], $box['x'] + $box['w'], $box['y'] + $box['h'] );
		}
		
		$canvas->drawImage( $draw );
	}
	
	/**
	 * Draw a text layer with auto-fitting.
	 *
	 * @param	\Imagick	$canvas The target canvas
	 * @param	array	$layer The layer definition
	 * @param	\happyhappy\ImageSocialiser\Template\Binding	$data The dynamic data binding
	 * @throws	\ImagickException If drawing fails
	 */
	private function draw_text( Imagick $canvas, array $layer, Binding $data ): void {
		$text = \trim( $data->get_text( (string) ( $layer['source'] ?? '' ) ) );
		
		if ( $text === '' ) {
			return;
		}
		
		$font_path = Fonts::get_path( (string) ( $layer['font'] ?? 'inter-regular' ) );
		
		if ( $font_path === '' ) {
			return;
		}
		
		$box = $this->get_box( $layer, $canvas->getImageWidth(), $canvas->getImageHeight() );
		$draw = new ImagickDraw();
		$draw->setFont( $font_path );
		$draw->setFillColor(
			new ImagickPixel( Template_Model::sanitize_color( $layer['color'] ?? null, '#ffffff' ) )
		);
		$draw->setTextEncoding( 'UTF-8' );
		$measure = static function( string $line, int $font_size ) use ( $canvas, $draw ): array {
			$draw->setFontSize( $font_size );
			$metrics = $canvas->queryFontMetrics( $draw, $line );
			
			return [ (float) $metrics['textWidth'], (float) $metrics['textHeight'] ];
		};
		[ $minimum_size, $maximum_size ] = $this->get_size_range( $layer['size'] ?? 32 );
		$line_height = (float) ( $layer['lineHeight'] ?? 1.2 );
		$fitter = new Text_Fitter( $measure );
		$fitted = $fitter->fit(
			$text,
			$minimum_size,
			$maximum_size,
			$box['w'],
			$box['h'],
			$line_height,
			(int) ( $layer['maxLines'] ?? 0 )
		);
		$draw->setFontSize( $fitted['size'] );
		$metrics = $canvas->queryFontMetrics( $draw, 'Mg' );
		$line_step = $fitter->get_line_step( $fitted['size'], $line_height );
		$baseline = $box['y'] + (float) $metrics['ascender'];
		$align = (string) ( $layer['align'] ?? 'left' );
		$positions = [];
		
		foreach ( $fitted['lines'] as $index => $line ) {
			$line_metrics = $canvas->queryFontMetrics( $draw, $line );
			$x = match ( $align ) {
				'center' => $box['x'] + ( $box['w'] - (float) $line_metrics['textWidth'] ) / 2,
				'right' => $box['x'] + $box['w'] - (float) $line_metrics['textWidth'],
				default => (float) $box['x'],
			};
			$positions[ $index ] = [
				'width' => (float) $line_metrics['textWidth'],
				'x' => \max( (float) $box['x'], $x ),
			];
		}
		
		$this->draw_text_backing( $canvas, $layer, $positions, $box['y'], $line_step );
		
		foreach ( $fitted['lines'] as $index => $line ) {
			$canvas->annotateImage(
				$draw,
				$positions[ $index ]['x'],
				$baseline + $index * $line_step,
				0,
				$line
			);
		}
	}
	
	/**
	 * Draw the content-fitted backing boxes behind a text layer.
	 *
	 * One box per line, hugging the measured line width; boxes of
	 * consecutive lines are contiguous, with the vertical padding
	 * applied to the first and last line only, so the backing works
	 * with translucent colors without overlap seams.
	 *
	 * @param	\Imagick	$canvas The target canvas
	 * @param	array	$layer The layer definition
	 * @param	array<int, array{width: float, x: float}>	$positions The per-line x and width
	 * @param	int	$box_y The text box top edge
	 * @param	int	$line_step The vertical line step
	 * @throws	\ImagickException If drawing fails
	 */
	private function draw_text_backing(
		Imagick $canvas,
		array $layer,
		array $positions,
		int $box_y,
		int $line_step
	): void {
		$backing = \is_array( $layer['backing'] ?? null ) ? $layer['backing'] : [];
		
		if ( ! isset( $backing['color'] ) || $positions === [] ) {
			return;
		}
		
		$padding_x = \max( 0, (int) ( $backing['padding_x'] ?? 16 ) );
		$padding_y = \max( 0, (int) ( $backing['padding_y'] ?? 8 ) );
		$radius = \max( 0, (int) ( $backing['radius'] ?? 0 ) );
		$opacity = \min( 1, \max( 0, (float) ( $backing['opacity'] ?? 1 ) ) );
		$last = \count( $positions ) - 1;
		$draw = new ImagickDraw();
		$draw->setFillColor(
			new ImagickPixel( Template_Model::sanitize_color( $backing['color'] ) )
		);
		
		if ( $opacity < 1 ) {
			$draw->setFillOpacity( $opacity );
		}
		
		foreach ( $positions as $index => $position ) {
			$top = $box_y + $index * $line_step - ( $index === 0 ? $padding_y : 0 );
			$bottom = $box_y + ( $index + 1 ) * $line_step + ( $index === $last ? $padding_y : 0 );
			$left = $position['x'] - $padding_x;
			$right = $position['x'] + $position['width'] + $padding_x;
			
			if ( $radius > 0 ) {
				$draw->roundRectangle( $left, $top, $right, $bottom, $radius, $radius );
			}
			else {
				$draw->rectangle( $left, $top, $right, $bottom );
			}
		}
		
		$canvas->drawImage( $draw );
	}
	
	/**
	 * Get a sanitized layer bounding box.
	 *
	 * @param	array	$layer The layer definition
	 * @param	int	$canvas_width The canvas width in pixels
	 * @param	int	$canvas_height The canvas height in pixels
	 * @return	array{h: int, w: int, x: int, y: int} The bounding box
	 */
	private function get_box( array $layer, int $canvas_width, int $canvas_height ): array {
		$box = \is_array( $layer['box'] ?? null ) ? $layer['box'] : [];
		$x = \max( 0, (int) ( $box['x'] ?? 0 ) );
		$y = \max( 0, (int) ( $box['y'] ?? 0 ) );
		
		return [
			'h' => \min( \max( 1, (int) ( $box['h'] ?? $canvas_height ) ), $canvas_height ),
			'w' => \min( \max( 1, (int) ( $box['w'] ?? $canvas_width ) ), $canvas_width ),
			'x' => \min( $x, $canvas_width - 1 ),
			'y' => \min( $y, $canvas_height - 1 ),
		];
	}
	
	/**
	 * Normalize a size definition to a [ minimum, maximum ] range.
	 *
	 * @param	mixed	$size The size definition (int or array with min/max)
	 * @return	array{0: int, 1: int} The minimum and maximum font size
	 */
	private function get_size_range( mixed $size ): array {
		if ( \is_array( $size ) ) {
			$minimum = \max( 1, (int) ( $size['min'] ?? 16 ) );
			$maximum = \max( $minimum, (int) ( $size['max'] ?? $minimum ) );
			
			return [ $minimum, $maximum ];
		}
		
		$fixed = \max( 1, (int) $size );
		
		return [ $fixed, $fixed ];
	}

	/**
	 * Get the JPEG quality used for photographic renders.
	 *
	 * @return	int The quality between 1 and 100
	 */
	private function get_jpeg_quality(): int {
		/**
		 * Filter the JPEG quality of photographic renders.
		 *
		 * @since	1.0.0
		 *
		 * @param	int	$quality The quality between 1 and 100
		 */
		$quality = (int) \apply_filters( 'image_socialiser_jpeg_quality', Output_Format::JPEG_QUALITY );
		
		return \max( 1, \min( 100, $quality ) );
	}

}
