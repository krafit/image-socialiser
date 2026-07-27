<?php
declare(strict_types=1);

namespace happyhappy\ImageSocialiser\Rendering;

use GdImage;
use happyhappy\ImageSocialiser\Template\Binding;
use happyhappy\ImageSocialiser\Template\Template_Model;

/**
 * Fallback renderer using the GD extension.
 *
 * Covers the common "background + text" case on hosts without Imagick,
 * with lower fidelity: linear two-stop gradients, cover/contain image
 * compositing with opacity, and wrapped TrueType text.
 *
 * @author	Simon Kraft
 * @license	GPL2
 * @package	happyhappy\ImageSocialiser
 */
final class GD_Renderer implements Renderer {
	/**
	 * @inheritdoc
	 */
	public function get_id(): string {
		return 'gd';
	}
	
	/**
	 * @inheritdoc
	 */
	public function is_available(): bool {
		return \extension_loaded( 'gd' )
			&& \function_exists( 'imagettftext' )
			&& \function_exists( 'imagettfbbox' );
	}
	
	/**
	 * @inheritdoc
	 */
	public function render( Template_Model $model, Binding $data ): string {
		$canvas = \imagecreatetruecolor( $model->get_width(), $model->get_height() );
		
		if ( ! $canvas instanceof GdImage ) {
			throw new Rendering_Exception( 'GD could not create the canvas.' );
		}
		
		\imagefill( $canvas, 0, 0, (int) \imagecolorallocate( $canvas, 0, 0, 0 ) );
		
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
		
		\ob_start();
		\imagepng( $canvas );
		$bytes = (string) \ob_get_clean();
		\imagedestroy( $canvas );
		
		if ( $bytes === '' ) {
			throw new Rendering_Exception( 'GD returned an empty image.' );
		}
		
		return $bytes;
	}
	
	/**
	 * Allocate a hex color on a GD image.
	 *
	 * @param	\GdImage	$image The GD image
	 * @param	mixed	$color The hex color input
	 * @param	string	$fallback The fallback color
	 * @return	int The allocated color
	 */
	private function allocate_color( GdImage $image, mixed $color, string $fallback = '#000000' ): int {
		[ $red, $green, $blue ] = $this->hex_to_rgb(
			Template_Model::sanitize_color( $color, $fallback )
		);
		
		return (int) \imagecolorallocate( $image, $red, $green, $blue );
	}
	
	/**
	 * Draw a background layer (solid color or two-stop linear gradient).
	 *
	 * @param	\GdImage	$canvas The target canvas
	 * @param	array	$layer The layer definition
	 */
	private function draw_background( GdImage $canvas, array $layer ): void {
		$width = \imagesx( $canvas );
		$height = \imagesy( $canvas );
		$fill = \is_array( $layer['fill'] ?? null ) ? $layer['fill'] : [];
		
		if ( ( $fill['kind'] ?? 'solid' ) !== 'gradient' ) {
			\imagefilledrectangle(
				$canvas,
				0,
				0,
				$width - 1,
				$height - 1,
				$this->allocate_color( $canvas, $fill['color'] ?? null )
			);
			
			return;
		}
		
		$stops = \is_array( $fill['stops'] ?? null ) ? \array_values( $fill['stops'] ) : [];
		$from = $this->hex_to_rgb(
			Template_Model::sanitize_color( $stops[0]['color'] ?? null )
		);
		$to = $this->hex_to_rgb(
			Template_Model::sanitize_color( $stops[ \count( $stops ) - 1 ]['color'] ?? null, '#ffffff' )
		);
		$is_horizontal = ( $fill['direction'] ?? 'vertical' ) === 'horizontal';
		$steps = $is_horizontal ? $width : $height;
		
		for ( $step = 0; $step < $steps; $step++ ) {
			$ratio = $steps > 1 ? $step / ( $steps - 1 ) : 0;
			$color = (int) \imagecolorallocate(
				$canvas,
				(int) \round( $from[0] + ( $to[0] - $from[0] ) * $ratio ),
				(int) \round( $from[1] + ( $to[1] - $from[1] ) * $ratio ),
				(int) \round( $from[2] + ( $to[2] - $from[2] ) * $ratio )
			);
			
			if ( $is_horizontal ) {
				\imageline( $canvas, $step, 0, $step, $height - 1, $color );
			}
			else {
				\imageline( $canvas, 0, $step, $width - 1, $step, $color );
			}
		}
	}
	
	/**
	 * Draw an image layer (featured image, logo, …).
	 *
	 * @param	\GdImage	$canvas The target canvas
	 * @param	array	$layer The layer definition
	 * @param	\happyhappy\ImageSocialiser\Template\Binding	$data The dynamic data binding
	 */
	private function draw_image( GdImage $canvas, array $layer, Binding $data ): void {
		$path = $data->get_image_path( (string) ( $layer['source'] ?? '' ), $layer );
		
		if ( $path === '' ) {
			return;
		}
		
		$source = $this->read_image( $path );
		
		if ( $source === null ) {
			return;
		}
		
		$box = $this->get_box( $layer, \imagesx( $canvas ), \imagesy( $canvas ) );
		$frame = \is_array( $layer['frame'] ?? null ) ? $layer['frame'] : [];
		
		if ( isset( $frame['color'] ) ) {
			// offset frame ("sticker"): a solid rect behind the image,
			// shifted down-right; sized to the box, so it pairs with
			// cover-fitted images
			$offset = (int) ( $frame['offset'] ?? 12 );
			\imagefilledrectangle(
				$canvas,
				$box['x'] + $offset,
				$box['y'] + $offset,
				$box['x'] + $offset + $box['w'],
				$box['y'] + $offset + $box['h'],
				$this->allocate_color( $canvas, $frame['color'] )
			);
		}
		
		$source_width = \imagesx( $source );
		$source_height = \imagesy( $source );
		$offset_x = $box['x'];
		$offset_y = $box['y'];
		$target_width = $box['w'];
		$target_height = $box['h'];
		
		if ( ( $layer['fit'] ?? 'cover' ) === 'contain' ) {
			$scale = \min( $box['w'] / $source_width, $box['h'] / $source_height );
			$target_width = \max( 1, (int) \round( $source_width * $scale ) );
			$target_height = \max( 1, (int) \round( $source_height * $scale ) );
			$offset_x += match ( $layer['align'] ?? 'center' ) {
				'left' => 0,
				'right' => $box['w'] - $target_width,
				default => (int) \round( ( $box['w'] - $target_width ) / 2 ),
			};
			$offset_y += (int) \round( ( $box['h'] - $target_height ) / 2 );
			$crop = [
				'h' => $source_height,
				'w' => $source_width,
				'x' => 0,
				'y' => 0,
			];
		}
		else {
			$scale = \max( $box['w'] / $source_width, $box['h'] / $source_height );
			$crop = [
				'h' => \max( 1, (int) \round( $box['h'] / $scale ) ),
				'w' => \max( 1, (int) \round( $box['w'] / $scale ) ),
			];
			$crop['x'] = (int) \round( ( $source_width - $crop['w'] ) / 2 );
			$crop['y'] = (int) \round( ( $source_height - $crop['h'] ) / 2 );
		}
		
		$resampled = \imagecreatetruecolor( $target_width, $target_height );
		
		if ( ! $resampled instanceof GdImage ) {
			return;
		}
		
		\imagecopyresampled(
			$resampled,
			$source,
			0,
			0,
			$crop['x'],
			$crop['y'],
			$target_width,
			$target_height,
			$crop['w'],
			$crop['h']
		);
		$opacity = (float) ( $layer['opacity'] ?? 1 );
		
		if ( $opacity < 1 ) {
			\imagecopymerge(
				$canvas,
				$resampled,
				$offset_x,
				$offset_y,
				0,
				0,
				$target_width,
				$target_height,
				(int) \round( \max( 0, $opacity ) * 100 )
			);
		}
		else {
			\imagecopy(
				$canvas,
				$resampled,
				$offset_x,
				$offset_y,
				0,
				0,
				$target_width,
				$target_height
			);
		}
		
		\imagedestroy( $resampled );
		\imagedestroy( $source );
	}
	
	/**
	 * Draw a rect layer (solid fill and/or stroke; radius is ignored —
	 * documented GD fidelity degradation).
	 *
	 * A rect may be fill-only, stroke-only (outlined frame — set
	 * 'fill' to 'none') or both. The stroke band is centered on the
	 * box edge, approximating the Imagick/SVG stroke with four filled
	 * bars.
	 *
	 * @param	\GdImage	$canvas The target canvas
	 * @param	array	$layer The layer definition
	 */
	private function draw_rect( GdImage $canvas, array $layer ): void {
		$box = $this->get_box( $layer, \imagesx( $canvas ), \imagesy( $canvas ) );
		$opacity = \min( 1, \max( 0, (float) ( $layer['opacity'] ?? 1 ) ) );
		$alpha = (int) \round( ( 1 - $opacity ) * 127 );
		$stroke = \is_array( $layer['stroke'] ?? null ) ? $layer['stroke'] : [];
		$stroke_width = \max( 0, (int) ( $stroke['width'] ?? 0 ) );
		
		if ( \is_string( $layer['fill'] ?? null ) && $layer['fill'] !== 'none' ) {
			[ $red, $green, $blue ] = $this->hex_to_rgb(
				Template_Model::sanitize_color( $layer['fill'] )
			);
			\imagefilledrectangle(
				$canvas,
				$box['x'],
				$box['y'],
				$box['x'] + $box['w'],
				$box['y'] + $box['h'],
				(int) \imagecolorallocatealpha( $canvas, $red, $green, $blue, $alpha )
			);
		}
		
		if ( $stroke_width === 0 ) {
			return;
		}
		
		[ $red, $green, $blue ] = $this->hex_to_rgb(
			Template_Model::sanitize_color( $stroke['color'] ?? null, '#ffffff' )
		);
		$color = (int) \imagecolorallocatealpha( $canvas, $red, $green, $blue, $alpha );
		$outer = (int) \floor( $stroke_width / 2 );
		$inner = (int) \ceil( $stroke_width / 2 );
		$edges = [
			// [ x1, y1, x2, y2 ] — top, bottom, left, right band
			[ $box['x'] - $outer, $box['y'] - $outer, $box['x'] + $box['w'] + $outer, $box['y'] + $inner - 1 ],
			[
				$box['x'] - $outer,
				$box['y'] + $box['h'] - $inner + 1,
				$box['x'] + $box['w'] + $outer,
				$box['y'] + $box['h'] + $outer,
			],
			[ $box['x'] - $outer, $box['y'] - $outer, $box['x'] + $inner - 1, $box['y'] + $box['h'] + $outer ],
			[
				$box['x'] + $box['w'] - $inner + 1,
				$box['y'] - $outer,
				$box['x'] + $box['w'] + $outer,
				$box['y'] + $box['h'] + $outer,
			],
		];
		
		foreach ( $edges as $edge ) {
			\imagefilledrectangle( $canvas, $edge[0], $edge[1], $edge[2], $edge[3], $color );
		}
	}
	
	/**
	 * Draw a text layer with auto-fitting.
	 *
	 * @param	\GdImage	$canvas The target canvas
	 * @param	array	$layer The layer definition
	 * @param	\happyhappy\ImageSocialiser\Template\Binding	$data The dynamic data binding
	 */
	private function draw_text( GdImage $canvas, array $layer, Binding $data ): void {
		$text = \trim( $data->get_text( (string) ( $layer['source'] ?? '' ) ) );
		
		if ( $text === '' ) {
			return;
		}
		
		$font_path = Fonts::get_path( (string) ( $layer['font'] ?? 'inter-regular' ) );
		
		if ( $font_path === '' ) {
			return;
		}
		
		$box = $this->get_box( $layer, \imagesx( $canvas ), \imagesy( $canvas ) );
		$measure = static function( string $line, int $font_size ) use ( $font_path ): array {
			$bounds = \imagettfbbox( $font_size * 0.75, 0, $font_path, $line );
			
			if ( $bounds === false ) {
				return [ 0.0, 0.0 ];
			}
			
			return [
				(float) ( \max( $bounds[2], $bounds[4] ) - \min( $bounds[0], $bounds[6] ) ),
				(float) ( \max( $bounds[1], $bounds[3] ) - \min( $bounds[5], $bounds[7] ) ),
			];
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
		$point_size = $fitted['size'] * 0.75;
		$color = $this->allocate_color( $canvas, $layer['color'] ?? null, '#ffffff' );
		$reference = \imagettfbbox( $point_size, 0, $font_path, 'Mg' );
		$ascent = $reference !== false ? -\min( $reference[5], $reference[7] ) : $fitted['size'];
		$line_step = $fitter->get_line_step( $fitted['size'], $line_height );
		$align = (string) ( $layer['align'] ?? 'left' );
		$positions = [];
		
		foreach ( $fitted['lines'] as $index => $line ) {
			[ $line_width ] = $measure( $line, $fitted['size'] );
			$x = match ( $align ) {
				'center' => $box['x'] + (int) \round( ( $box['w'] - $line_width ) / 2 ),
				'right' => $box['x'] + (int) \round( $box['w'] - $line_width ),
				default => $box['x'],
			};
			$positions[ $index ] = [
				'width' => $line_width,
				'x' => \max( $box['x'], $x ),
			];
		}
		
		$this->draw_text_backing( $canvas, $layer, $positions, $box['y'], $line_step );
		
		foreach ( $fitted['lines'] as $index => $line ) {
			\imagettftext(
				$canvas,
				$point_size,
				0,
				(int) $positions[ $index ]['x'],
				$box['y'] + (int) \round( $ascent ) + $index * $line_step,
				$color,
				$font_path,
				$line
			);
		}
	}
	
	/**
	 * Draw the content-fitted backing boxes behind a text layer.
	 *
	 * One box per line, hugging the measured line width; boxes of
	 * consecutive lines are contiguous, with the vertical padding
	 * applied to the first and last line only. The corner radius is
	 * ignored — documented GD fidelity degradation.
	 *
	 * @param	\GdImage	$canvas The target canvas
	 * @param	array	$layer The layer definition
	 * @param	array<int, array{width: float, x: int}>	$positions The per-line x and width
	 * @param	int	$box_y The text box top edge
	 * @param	int	$line_step The vertical line step
	 */
	private function draw_text_backing(
		GdImage $canvas,
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
		$opacity = \min( 1, \max( 0, (float) ( $backing['opacity'] ?? 1 ) ) );
		$last = \count( $positions ) - 1;
		[ $red, $green, $blue ] = $this->hex_to_rgb(
			Template_Model::sanitize_color( $backing['color'] )
		);
		$color = (int) \imagecolorallocatealpha(
			$canvas,
			$red,
			$green,
			$blue,
			(int) \round( ( 1 - $opacity ) * 127 )
		);
		
		foreach ( $positions as $index => $position ) {
			\imagefilledrectangle(
				$canvas,
				(int) \round( $position['x'] - $padding_x ),
				$box_y + $index * $line_step - ( $index === 0 ? $padding_y : 0 ),
				(int) \round( $position['x'] + $position['width'] + $padding_x ),
				$box_y + ( $index + 1 ) * $line_step + ( $index === $last ? $padding_y : 0 ),
				$color
			);
		}
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
	 * Convert a hex color to RGB values.
	 *
	 * @param	string	$hex The sanitized hex color
	 * @return	array{0: int, 1: int, 2: int} The red, green and blue values
	 */
	private function hex_to_rgb( string $hex ): array {
		$hex = \ltrim( $hex, '#' );
		
		if ( \strlen( $hex ) < 6 ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		
		return [
			(int) \hexdec( \substr( $hex, 0, 2 ) ),
			(int) \hexdec( \substr( $hex, 2, 2 ) ),
			(int) \hexdec( \substr( $hex, 4, 2 ) ),
		];
	}
	
	/**
	 * Read an image file into a GD image.
	 *
	 * @param	string	$path The absolute file path
	 * @return	\GdImage|null The GD image or null
	 */
	private function read_image( string $path ): ?GdImage {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$contents = \file_get_contents( $path );
		
		if ( $contents === false ) {
			return null;
		}
		
		$image = \imagecreatefromstring( $contents );
		
		return $image instanceof GdImage ? $image : null;
	}
}
