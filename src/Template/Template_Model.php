<?php
declare(strict_types=1);

namespace happyhappy\ImageSocialiser\Template;

// prevent direct file access
if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The template model — a JSON-serialisable layer stack.
 *
 * Single source of truth shared by the browser preview (SVG) and the
 * server renderer (PNG).
 *
 * @author	Simon Kraft
 * @license	GPL2
 * @package	happyhappy\ImageSocialiser
 */
final class Template_Model {
	/**
	 * @var	int The default canvas height.
	 */
	public const int DEFAULT_HEIGHT = 630;
	
	/**
	 * @var	int The default canvas width.
	 */
	public const int DEFAULT_WIDTH = 1200;
	
	/**
	 * @var	int The canvas height in pixels.
	 */
	private readonly int $height;
	
	/**
	 * @var	string The template identifier.
	 */
	private readonly string $id;
	
	/**
	 * @var	string The human-readable template label.
	 */
	private readonly string $label;
	
	/**
	 * @var	array<int, array<string, mixed>> The layer stack, bottom to top.
	 */
	private readonly array $layers;
	
	/**
	 * @var	array<string, string> Tokens the template pins by design.
	 */
	private readonly array $pins;
	
	/**
	 * @var	string[] Features the template honors (e.g. 'layout').
	 */
	private readonly array $supported_features;
	
	/**
	 * @var	int The template version, bumped on any edit.
	 */
	private readonly int $version;
	
	/**
	 * @var	int The canvas width in pixels.
	 */
	private readonly int $width;
	
	/**
	 * Template_Model constructor.
	 *
	 * @param	string	$id The template identifier
	 * @param	int	$version The template version
	 * @param	int	$width The canvas width in pixels
	 * @param	int	$height The canvas height in pixels
	 * @param	array	$layers The layer stack
	 */
	private function __construct(
		string $id,
		int $version,
		int $width,
		int $height,
		array $layers,
		array $supported_features,
		string $label,
		array $pins
	) {
		$this->id = $id;
		$this->version = $version;
		$this->width = $width;
		$this->height = $height;
		$this->layers = $layers;
		$this->supported_features = $supported_features;
		$this->label = $label;
		$this->pins = $pins;
	}
	
	/**
	 * Create a model from an (untrusted) array representation.
	 *
	 * @param	array	$data The template data
	 * @return	self The template model
	 */
	public static function from_array( array $data ): self {
		$canvas = \is_array( $data['canvas'] ?? null ) ? $data['canvas'] : [];
		$layers = \is_array( $data['layers'] ?? null ) ? $data['layers'] : [];
		$layers = \array_values( \array_filter( $layers, '\is_array' ) );
		
		$supported_features = \is_array( $data['supports'] ?? null ) ? $data['supports'] : [];
		$id = \sanitize_key( (string) ( $data['id'] ?? 'default' ) );
		$pins = \is_array( $data['pins'] ?? null ) ? $data['pins'] : [];
		$label = (string) ( $data['label'] ?? '' );
		
		if ( $label === '' ) {
			$label = \ucwords( \str_replace( [ '-', '_' ], ' ', $id ) );
		}
		
		return new self(
			$id,
			\max( 1, (int) ( $data['version'] ?? 1 ) ),
			self::sanitize_dimension( $canvas['w'] ?? null, self::DEFAULT_WIDTH ),
			self::sanitize_dimension( $canvas['h'] ?? null, self::DEFAULT_HEIGHT ),
			$layers,
			\array_values( \array_filter( $supported_features, '\is_string' ) ),
			$label,
			\array_filter( $pins, '\is_string' )
		);
	}
	
	/**
	 * Get the default site-wide template.
	 *
	 * @return	self The default template model
	 */
	public static function get_default(): self {
		return self::from_array( Built_In_Templates::editorial() );
	}
	
	/**
	 * Get the canvas height.
	 *
	 * @return	int The canvas height in pixels
	 */
	public function get_height(): int {
		return $this->height;
	}
	
	/**
	 * Get the template identifier.
	 *
	 * @return	string The template identifier
	 */
	public function get_id(): string {
		return $this->id;
	}
	
	/**
	 * Get the human-readable template label.
	 *
	 * @return	string The template label
	 */
	public function get_label(): string {
		return $this->label;
	}
	
	/**
	 * Get the layer stack.
	 *
	 * @return	array<int, array<string, mixed>> The layers, bottom to top
	 */
	public function get_layers(): array {
		return $this->layers;
	}
	
	/**
	 * Get the tokens the template pins by design.
	 *
	 * Pinned tokens are overridden by the template regardless of the
	 * layout settings; the UI greys the matching controls out.
	 *
	 * @return	array<string, string> Token name => pinned value
	 */
	public function get_pins(): array {
		return $this->pins;
	}
	
	/**
	 * Get the template version.
	 *
	 * @return	int The template version
	 */
	public function get_version(): int {
		return $this->version;
	}
	
	/**
	 * Get the canvas width.
	 *
	 * @return	int The canvas width in pixels
	 */
	public function get_width(): int {
		return $this->width;
	}
	
	/**
	 * Sanitize a hex color value.
	 *
	 * @param	mixed	$color The color input
	 * @param	string	$fallback The fallback color
	 * @return	string A valid hex color
	 */
	public static function sanitize_color( mixed $color, string $fallback = '#000000' ): string {
		if ( ! \is_string( $color ) ) {
			return $fallback;
		}
		
		if ( \preg_match( '/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $color ) !== 1 ) {
			return $fallback;
		}
		
		return \strtolower( $color );
	}
	
	/**
	 * Sanitize a canvas dimension.
	 *
	 * @param	mixed	$value The dimension input
	 * @param	int	$fallback The fallback dimension
	 * @return	int A dimension between 16 and 4096 pixels
	 */
	private static function sanitize_dimension( mixed $value, int $fallback ): int {
		$value = (int) ( $value ?? 0 );
		
		if ( $value < 16 || $value > 4096 ) {
			return $fallback;
		}
		
		return $value;
	}
	
	/**
	 * Check whether the template honors a feature.
	 *
	 * Used by the settings UI to grey out controls a selected template
	 * ignores (e.g. layout tokens for third-party templates).
	 *
	 * @param	string	$feature The feature name, e.g. 'layout'
	 * @return	bool Whether the template declares support
	 */
	public function supports( string $feature ): bool {
		return \in_array( $feature, $this->supported_features, true );
	}
	
	/**
	 * Get the array representation for browser payloads.
	 *
	 * Identical to to_array(), except that server file paths of
	 * template assets ('asset_path') are stripped from the layers —
	 * the SVG preview only needs the 'asset_url'.
	 *
	 * @return	array The template as array, without server paths
	 */
	public function to_public_array(): array {
		$template = $this->to_array();
		
		foreach ( $template['layers'] as $index => $layer ) {
			unset( $template['layers'][ $index ]['asset_path'] );
		}
		
		return $template;
	}
	
	/**
	 * Get the array representation of the template.
	 *
	 * @return	array The template as array
	 */
	public function to_array(): array {
		return [
			'canvas' => [
				'h' => $this->height,
				'w' => $this->width,
			],
			'id' => $this->id,
			'label' => $this->label,
			'layers' => $this->layers,
			'pins' => $this->pins,
			'supports' => $this->supported_features,
			'version' => $this->version,
		];
	}
}
