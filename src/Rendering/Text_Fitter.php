<?php
declare(strict_types=1);

namespace happyhappy\ImageSocialiser\Rendering;

use Closure;

// prevent direct file access
if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fits text into a bounding box.
 *
 * Shrinks the font size within a min/max range until the text fits,
 * word-wraps it and ellipsizes as a last resort. Measuring is delegated
 * to an injected callable so the same logic works for Imagick and GD.
 *
 * @author	Simon Kraft
 * @license	GPL2
 * @package	happyhappy\ImageSocialiser
 */
final class Text_Fitter {
	/**
	 * @var	\Closure Text measurer, signature: fn( string $text, int $font_size ): array{0: float, 1: float}
	 */
	private Closure $measure;
	
	/**
	 * Text_Fitter constructor.
	 *
	 * @param	callable	$measure Measurer returning [ width, height ] for a text at a font size
	 */
	public function __construct( callable $measure ) {
		$this->measure = Closure::fromCallable( $measure );
	}
	
	/**
	 * Fit text into a box.
	 *
	 * @param	string	$text The text to fit
	 * @param	int	$minimum_size The minimum font size
	 * @param	int	$maximum_size The maximum font size
	 * @param	int	$box_width The box width in pixels
	 * @param	int	$box_height The box height in pixels
	 * @param	float	$line_height The line height multiplier
	 * @param	int	$max_lines The maximum number of lines (0 = unlimited)
	 * @return	array{lines: string[], size: int} The fitted lines and font size
	 */
	public function fit(
		string $text,
		int $minimum_size,
		int $maximum_size,
		int $box_width,
		int $box_height,
		float $line_height = 1.2,
		int $max_lines = 0
	): array {
		$minimum_size = \max( 1, $minimum_size );
		$maximum_size = \max( $minimum_size, $maximum_size );
		
		for ( $font_size = $maximum_size; $font_size >= $minimum_size; $font_size-- ) {
			$lines = $this->wrap( $text, $font_size, $box_width );
			
			if ( $this->fits( $lines, $font_size, $box_width, $box_height, $line_height, $max_lines ) ) {
				return [
					'lines' => $lines,
					'size' => $font_size,
				];
			}
		}
		
		return [
			'lines' => $this->truncate(
				$this->wrap( $text, $minimum_size, $box_width ),
				$minimum_size,
				$box_width,
				$box_height,
				$line_height,
				$max_lines
			),
			'size' => $minimum_size,
		];
	}
	
	/**
	 * Check whether wrapped lines fit the box constraints.
	 *
	 * @param	string[]	$lines The wrapped lines
	 * @param	int	$font_size The font size
	 * @param	int	$box_width The box width in pixels
	 * @param	int	$box_height The box height in pixels
	 * @param	float	$line_height The line height multiplier
	 * @param	int	$max_lines The maximum number of lines (0 = unlimited)
	 * @return	bool Whether the lines fit
	 */
	private function fits(
		array $lines,
		int $font_size,
		int $box_width,
		int $box_height,
		float $line_height,
		int $max_lines
	): bool {
		$line_count = \count( $lines );
		
		if ( $max_lines > 0 && $line_count > $max_lines ) {
			return false;
		}
		
		if ( $line_count * $this->get_line_step( $font_size, $line_height ) > $box_height ) {
			return false;
		}
		
		foreach ( $lines as $line ) {
			if ( $this->get_width( $line, $font_size ) > $box_width ) {
				return false;
			}
		}
		
		return true;
	}
	
	/**
	 * Get the vertical advance per line.
	 *
	 * @param	int	$font_size The font size
	 * @param	float	$line_height The line height multiplier
	 * @return	int The line step in pixels
	 */
	public function get_line_step( int $font_size, float $line_height ): int {
		return \max( 1, (int) \round( $font_size * $line_height ) );
	}
	
	/**
	 * Measure the rendered width of a text.
	 *
	 * @param	string	$text The text to measure
	 * @param	int	$font_size The font size
	 * @return	float The width in pixels
	 */
	private function get_width( string $text, int $font_size ): float {
		[ $width ] = ( $this->measure )( $text, $font_size );
		
		return (float) $width;
	}
	
	/**
	 * Cut wrapped lines down to the allowed line count and ellipsize overflow.
	 *
	 * @param	string[]	$lines The wrapped lines
	 * @param	int	$font_size The font size
	 * @param	int	$box_width The box width in pixels
	 * @param	int	$box_height The box height in pixels
	 * @param	float	$line_height The line height multiplier
	 * @param	int	$max_lines The maximum number of lines (0 = unlimited)
	 * @return	string[] The truncated lines
	 */
	private function truncate(
		array $lines,
		int $font_size,
		int $box_width,
		int $box_height,
		float $line_height,
		int $max_lines
	): array {
		$allowed_lines = (int) \floor( $box_height / $this->get_line_step( $font_size, $line_height ) );
		
		if ( $max_lines > 0 ) {
			$allowed_lines = \min( $allowed_lines, $max_lines );
		}
		
		$allowed_lines = \max( 1, $allowed_lines );
		$is_cut = \count( $lines ) > $allowed_lines;
		
		if ( $is_cut ) {
			$lines = \array_slice( $lines, 0, $allowed_lines );
		}
		
		foreach ( $lines as $index => $line ) {
			$is_last = $index === \count( $lines ) - 1;
			$needs_ellipsis = ( $is_last && $is_cut )
				|| $this->get_width( $line, $font_size ) > $box_width;
			
			if ( $needs_ellipsis ) {
				$lines[ $index ] = $this->ellipsize( $line, $font_size, $box_width );
			}
		}
		
		return $lines;
	}
	
	/**
	 * Shorten a line until it fits the width, appending an ellipsis.
	 *
	 * @param	string	$line The line to shorten
	 * @param	int	$font_size The font size
	 * @param	int	$box_width The box width in pixels
	 * @return	string The ellipsized line
	 */
	private function ellipsize( string $line, int $font_size, int $box_width ): string {
		$ellipsis = '…';
		
		while (
			$line !== ''
			&& $this->get_width( \rtrim( $line ) . $ellipsis, $font_size ) > $box_width
		) {
			// drop the last character, UTF-8 safe without mbstring
			$line = (string) \preg_replace( '/.\z/us', '', $line );
		}
		
		return \rtrim( $line ) . $ellipsis;
	}
	
	/**
	 * Greedily word-wrap a text to a maximum pixel width.
	 *
	 * A single word wider than the box is kept on its own line and
	 * handled by truncate() later.
	 *
	 * @param	string	$text The text to wrap
	 * @param	int	$font_size The font size
	 * @param	int	$box_width The box width in pixels
	 * @return	string[] The wrapped lines
	 */
	private function wrap( string $text, int $font_size, int $box_width ): array {
		$words = \preg_split( '/\s+/u', \trim( $text ) ) ?: [];
		$lines = [];
		$current_line = '';
		
		foreach ( $words as $word ) {
			if ( $word === '' ) {
				continue;
			}
			
			$candidate = $current_line === '' ? $word : $current_line . ' ' . $word;
			
			if ( $current_line === '' || $this->get_width( $candidate, $font_size ) <= $box_width ) {
				$current_line = $candidate;
				
				continue;
			}
			
			$lines[] = $current_line;
			$current_line = $word;
		}
		
		if ( $current_line !== '' ) {
			$lines[] = $current_line;
		}
		
		return $lines;
	}
}
