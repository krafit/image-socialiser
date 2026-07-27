<?php
declare(strict_types=1);

namespace happyhappy\ImageSocialiser\Template;

/**
 * Corner box presets for built-in templates.
 *
 * Translates the layout tokens (positions, toggles) into concrete
 * boxes on the 1200×630 canvas. When both corner elements share a
 * corner they stack: logo above, site name below.
 *
 * @author	Simon Kraft
 * @license	GPL2
 * @package	happyhappy\ImageSocialiser
 */
final class Layout {
	/**
	 * @var	int Vertical margin of the corner slots.
	 */
	private const int MARGIN_Y = 64;
	
	/**
	 * @var	int Horizontal margin of the corner slots.
	 */
	private const int MARGIN_X = 80;
	
	/**
	 * @var	int Gap between stacked corner elements.
	 */
	private const int STACK_GAP = 12;
	
	/**
	 * Get the corner element layers for the given layout tokens.
	 *
	 * @param	array	$layout The layout tokens
	 * @param	bool	$square_logo Whether the logo box should be square (site icon)
	 * @param	array	$area Optional area constraint: x, w, h, margin_x, margin_y
	 * @return	array{logo: array|null, site_name: array|null} Partial layer definitions (box + align) or null when hidden
	 */
	public static function get_corner_elements( array $layout, bool $square_logo, array $area = [] ): array {
		$area = \array_merge(
			[
				'h' => 630,
				'margin_x' => self::MARGIN_X,
				'margin_y' => self::MARGIN_Y,
				'w' => 1200,
				'x' => 0,
			],
			$area
		);
		$inner_width = $area['w'] - 2 * $area['margin_x'];
		$logo_size = $square_logo
			? [ 'h' => 72, 'w' => 72 ]
			: [ 'h' => 72, 'w' => \min( 300, $inner_width ) ];
		$site_name_size = [ 'h' => 40, 'w' => \min( 720, $inner_width ) ];
		$stacked = ! empty( $layout['show_logo'] )
			&& ! empty( $layout['show_site_name'] )
			&& $layout['logo_position'] === $layout['site_name_position'];
		$logo = null;
		$site_name = null;
		
		if ( ! empty( $layout['show_logo'] ) ) {
			$logo = self::get_slot( $layout['logo_position'], $logo_size, $area );
		}
		
		if ( ! empty( $layout['show_site_name'] ) ) {
			$site_name = self::get_slot( $layout['site_name_position'], $site_name_size, $area );
		}
		
		if ( $stacked && $logo !== null && $site_name !== null ) {
			if ( \str_starts_with( $layout['logo_position'], 'top' ) ) {
				// logo keeps the slot, site name moves below it
				$site_name['box']['y'] = $logo['box']['y'] + $logo['box']['h'] + self::STACK_GAP;
			}
			else {
				// site name keeps the bottom slot, logo moves above it
				$logo['box']['y'] = $site_name['box']['y'] - $logo['box']['h'] - self::STACK_GAP;
			}
		}
		
		return [
			'logo' => $logo,
			'site_name' => $site_name,
		];
	}
	
	/**
	 * Get the box and alignment for a corner slot.
	 *
	 * @param	string	$position The corner position
	 * @param	array{h: int, w: int}	$size The element size
	 * @param	int	$canvas_width The canvas width in pixels
	 * @param	int	$canvas_height The canvas height in pixels
	 * @return	array{align: string, box: array{h: int, w: int, x: int, y: int}} The slot definition
	 */
	/**
	 * Get the box and alignment for a corner slot.
	 *
	 * @param	string	$position The corner position
	 * @param	array{h: int, w: int}	$size The element size
	 * @param	array{h: int, margin_x: int, margin_y: int, w: int, x: int}	$area The area constraint
	 * @return	array{align: string, box: array{h: int, w: int, x: int, y: int}} The slot definition
	 */
	private static function get_slot( string $position, array $size, array $area ): array {
		$is_right = \str_ends_with( $position, 'right' );
		$is_bottom = \str_starts_with( $position, 'bottom' );
		// corner elements share a 72px row and center within it, so a
		// 40px site name aligns optically with a 72px logo
		$row_height = 72;
		$row_offset = (int) \round( ( $row_height - $size['h'] ) / 2 );
		
		return [
			'align' => $is_right ? 'right' : 'left',
			'box' => [
				'h' => $size['h'],
				'w' => $size['w'],
				'x' => $is_right
					? $area['x'] + $area['w'] - $area['margin_x'] - $size['w']
					: $area['x'] + $area['margin_x'],
				'y' => $is_bottom
					? $area['h'] - $area['margin_y'] - $row_height + $row_offset
					: $area['margin_y'] + $row_offset,
			],
		];
	}
}
