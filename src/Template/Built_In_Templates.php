<?php
declare(strict_types=1);

namespace happyhappy\ImageSocialiser\Template;

use happyhappy\ImageSocialiser\Plugin;

/**
 * The built-in template collection.
 *
 * Every builder derives its design from the token pipeline (brand
 * colors/fonts, layout tokens, content tokens), so all customizations
 * apply to every template. Fonts are always the heading/body tokens —
 * never hard-coded font ids — so the settings dropdowns steer all
 * designs.
 *
 * @author	Simon Kraft
 * @license	GPL2
 * @package	happyhappy\ImageSocialiser
 */
final class Built_In_Templates {
	/**
	 * @var	string[] The identifiers of all built-in templates.
	 */
	public const array IDS = [
		'accent-panel',
		'banner',
		'cover-art',
		'editorial',
		'framed',
		'overlay',
		'poster',
		'split',
		'texture',
	];
	
	/**
	 * Get all built-in template definitions.
	 *
	 * @return	array<string, array> Template arrays by identifier
	 */
	public static function get_all(): array {
		$builders = [
			'editorial' => [ self::class, 'editorial' ],
			'poster' => [ self::class, 'poster' ],
			'split' => [ self::class, 'split' ],
			'cover-art' => [ self::class, 'cover_art' ],
			'overlay' => [ self::class, 'overlay' ],
			'banner' => [ self::class, 'banner' ],
			'framed' => [ self::class, 'framed' ],
			'accent-panel' => [ self::class, 'accent_panel' ],
			'texture' => [ self::class, 'texture' ],
		];
		$templates = [];
		
		foreach ( $builders as $template_id => $builder ) {
			// per-design token overrides apply via this context
			Design::set_template_context( $template_id );
			$templates[ $template_id ] = $builder();
		}
		
		Design::set_template_context( '' );
		
		return $templates;
	}
	
	/**
	 * Accent Panel: color panels hugging the text, over the photo.
	 *
	 * The title and secondary line sit on content-fitted backing
	 * boxes (one per line) in the text color, with the text inverted
	 * to the primary background color — the classic highlighted-text
	 * look. Falls back to the gradient when no featured image is set.
	 *
	 * @return	array The template definition
	 */
	public static function accent_panel(): array {
		$brand = Brand::get_tokens();
		$layout = Design::get_layout_tokens();
		$corners = Layout::get_corner_elements( $layout, Design::is_logo_square() );
		$layers = [
			self::gradient_background( $brand ),
			[
				'box' => [
					'h' => 630,
					'w' => 1200,
					'x' => 0,
					'y' => 0,
				],
				'fit' => 'cover',
				'source' => 'featured',
				'type' => 'image',
			],
		];
		$layers = self::add_corner_layers( $layers, $corners, $brand );
		$layers[] = [
			'align' => $layout['text_align'],
			'backing' => [
				'color' => $brand['text_color'],
				'padding_x' => 20,
				'padding_y' => 10,
			],
			'box' => [
				'h' => 260,
				'w' => 1040,
				'x' => 80,
				'y' => 170,
			],
			'color' => $brand['background_from'],
			'font' => $brand['heading_font'],
			'lineHeight' => 1.3,
			'maxLines' => 3,
			'size' => self::get_title_size( [
				'max' => 76,
				'min' => 44,
			] ),
			'source' => 'title',
			'type' => 'text',
		];
		$layers[] = [
			'align' => $layout['text_align'],
			'backing' => [
				'color' => $brand['text_color'],
				'padding_x' => 14,
				'padding_y' => 6,
			],
			'box' => [
				'h' => 36,
				'w' => 1040,
				'x' => 80,
				'y' => 486,
			],
			'color' => $brand['background_from'],
			'font' => $brand['body_font'],
			'maxLines' => 1,
			'size' => 26,
			'source' => 'subtitle',
			'type' => 'text',
		];
		
		return [
			'canvas' => [
				'h' => 630,
				'w' => 1200,
			],
			'id' => 'accent-panel',
			'label' => \__( 'Accent Panel', 'image-socialiser' ),
			'layers' => $layers,
			'supports' => [ 'layout' ],
			'version' => 1,
		];
	}
	
	/**
	 * Banner: full-bleed featured image, title in a bottom band.
	 *
	 * The corner elements are pinned to the top corners so they never
	 * collide with the band.
	 *
	 * @return	array The template definition
	 */
	public static function banner(): array {
		$brand = Brand::get_tokens();
		$layout = Design::get_layout_tokens();
		$corners = Layout::get_corner_elements(
			\array_merge(
				$layout,
				[
					'logo_position' => 'top-right',
					'site_name_position' => 'top-left',
				]
			),
			Design::is_logo_square()
		);
		$layers = [
			self::gradient_background( $brand ),
			[
				'box' => [
					'h' => 630,
					'w' => 1200,
					'x' => 0,
					'y' => 0,
				],
				'fit' => 'cover',
				'source' => 'featured',
				'type' => 'image',
			],
			[
				'box' => [
					'h' => 212,
					'w' => 1200,
					'x' => 0,
					'y' => 418,
				],
				'fill' => $brand['background_from'],
				'opacity' => 0.95,
				'type' => 'rect',
			],
		];
		$layers = self::add_corner_layers( $layers, $corners, $brand );
		$layers[] = [
			'align' => $layout['text_align'],
			'box' => [
				'h' => 112,
				'w' => 1040,
				'x' => 80,
				'y' => 448,
			],
			'color' => $brand['text_color'],
			'font' => $brand['heading_font'],
			'lineHeight' => 1.15,
			'maxLines' => 2,
			'size' => self::get_title_size( [
				'max' => 56,
				'min' => 36,
			] ),
			'source' => 'title',
			'type' => 'text',
		];
		$layers[] = [
			'align' => $layout['text_align'],
			'box' => [
				'h' => 32,
				'w' => 1040,
				'x' => 80,
				'y' => 566,
			],
			'color' => $brand['subtitle_color'],
			'font' => $brand['body_font'],
			'maxLines' => 1,
			'size' => 24,
			'source' => 'subtitle',
			'type' => 'text',
		];
		
		return [
			'canvas' => [
				'h' => 630,
				'w' => 1200,
			],
			'id' => 'banner',
			'label' => \__( 'Banner', 'image-socialiser' ),
			'layers' => $layers,
			'pins' => [
				'logo_position' => 'top-right',
				'site_name_position' => 'top-left',
			],
			'supports' => [ 'layout' ],
			'version' => 1,
		];
	}
	
	/**
	 * Cover Art: big square cover in a 1/3 column, text in the 2/3 column.
	 *
	 * Built for podcasts and other media. The cover box falls back to
	 * the featured image when no cover art token is set (stacked
	 * layers; empty sources are skipped).
	 *
	 * @return	array The template definition
	 */
	public static function cover_art(): array {
		$brand = Brand::get_tokens();
		$layout = Design::get_layout_tokens();
		// corner elements live in the right (text) column
		$corners = Layout::get_corner_elements(
			$layout,
			Design::is_logo_square(),
			[
				'margin_x' => 80,
				'w' => 784,
				'x' => 416,
			]
		);
		$cover_box = [
			'h' => 352,
			'w' => 352,
			'x' => 72,
			'y' => 139,
		];
		$layers = [
			self::gradient_background( $brand ),
			[
				'box' => $cover_box,
				'fit' => 'cover',
				'source' => 'featured',
				'type' => 'image',
			],
			[
				'box' => $cover_box,
				'fit' => 'cover',
				'source' => 'cover_art',
				'type' => 'image',
			],
		];
		$layers = self::add_corner_layers( $layers, $corners, $brand );
		$layers[] = [
			'align' => $layout['text_align'],
			'box' => [
				'h' => 250,
				'w' => 624,
				'x' => 496,
				'y' => 180,
			],
			'color' => $brand['text_color'],
			'font' => $brand['heading_font'],
			'lineHeight' => 1.15,
			'maxLines' => 4,
			'size' => self::get_title_size( [
				'max' => 64,
				'min' => 40,
			] ),
			'source' => 'title',
			'type' => 'text',
		];
		$layers[] = [
			'align' => $layout['text_align'],
			'box' => [
				'h' => 40,
				'w' => 624,
				'x' => 496,
				'y' => 452,
			],
			'color' => $brand['subtitle_color'],
			'font' => $brand['body_font'],
			'maxLines' => 1,
			'size' => 26,
			'source' => 'subtitle',
			'type' => 'text',
		];
		
		return [
			'canvas' => [
				'h' => 630,
				'w' => 1200,
			],
			'id' => 'cover-art',
			'label' => \__( 'Cover Art', 'image-socialiser' ),
			'layers' => $layers,
			'supports' => [ 'cover_art', 'layout' ],
			'version' => 1,
		];
	}
	
	/**
	 * Editorial: gradient, dimmed featured image, big left-aligned title.
	 *
	 * The long-standing default design.
	 *
	 * @return	array The template definition
	 */
	public static function editorial(): array {
		$brand = Brand::get_tokens();
		$layout = Design::get_layout_tokens();
		$corners = Layout::get_corner_elements( $layout, Design::is_logo_square() );
		$layers = [
			self::gradient_background( $brand ),
			[
				'box' => [
					'h' => 630,
					'w' => 1200,
					'x' => 0,
					'y' => 0,
				],
				'fit' => 'cover',
				'source' => 'brand_background',
				'type' => 'image',
			],
			[
				'box' => [
					'h' => 630,
					'w' => 1200,
					'x' => 0,
					'y' => 0,
				],
				'fit' => 'cover',
				'opacity' => 0.25,
				'source' => 'featured',
				'type' => 'image',
			],
		];
		$layers = self::add_corner_layers( $layers, $corners, $brand );
		$layers[] = [
			'align' => $layout['text_align'],
			'box' => [
				'h' => 240,
				'w' => 1040,
				'x' => 80,
				'y' => 180,
			],
			'color' => $brand['text_color'],
			'font' => $brand['heading_font'],
			'lineHeight' => 1.15,
			'maxLines' => 3,
			'size' => self::get_title_size( [
				'max' => 84,
				'min' => 48,
			] ),
			'source' => 'title',
			'type' => 'text',
		];
		$layers[] = [
			'align' => $layout['text_align'],
			'box' => [
				'h' => 40,
				'w' => 1040,
				'x' => 80,
				'y' => 446,
			],
			'color' => $brand['subtitle_color'],
			'font' => $brand['body_font'],
			'maxLines' => 1,
			'size' => 30,
			'source' => 'subtitle',
			'type' => 'text',
		];
		$editorial = [
			'canvas' => [
				'h' => 630,
				'w' => 1200,
			],
			'id' => 'editorial',
			'label' => \__( 'Editorial', 'image-socialiser' ),
			'layers' => $layers,
			'supports' => [ 'background_image', 'layout' ],
			'version' => 3,
		];
		
		/**
		 * Filter the default template definition.
		 *
		 * Editorial is the site-wide default design; this filter keeps
		 * its pre-0.9.0 name and semantics.
		 *
		 * @param	array	$editorial The default template as array
		 */
		return (array) \apply_filters( 'image_socialiser_default_template', $editorial );
	}
	
	/**
	 * Framed: an outlined frame over the background, title inside.
	 *
	 * Uses the stroke-only rect primitive; the corner elements move
	 * inside the frame margins.
	 *
	 * @return	array The template definition
	 */
	public static function framed(): array {
		$brand = Brand::get_tokens();
		$layout = Design::get_layout_tokens();
		// corner elements sit inside the frame
		$corners = Layout::get_corner_elements(
			$layout,
			Design::is_logo_square(),
			[
				'margin_x' => 104,
				'margin_y' => 96,
			]
		);
		$layers = [
			self::gradient_background( $brand ),
			[
				'box' => [
					'h' => 630,
					'w' => 1200,
					'x' => 0,
					'y' => 0,
				],
				'fit' => 'cover',
				'source' => 'brand_background',
				'type' => 'image',
			],
			[
				'box' => [
					'h' => 550,
					'w' => 1120,
					'x' => 40,
					'y' => 40,
				],
				'fill' => 'none',
				'stroke' => [
					'color' => $brand['text_color'],
					'width' => 3,
				],
				'type' => 'rect',
			],
		];
		$layers = self::add_corner_layers( $layers, $corners, $brand );
		$layers[] = [
			'align' => $layout['text_align'],
			'box' => [
				'h' => 210,
				'w' => 960,
				'x' => 120,
				'y' => 200,
			],
			'color' => $brand['text_color'],
			'font' => $brand['heading_font'],
			'lineHeight' => 1.15,
			'maxLines' => 3,
			'size' => self::get_title_size( [
				'max' => 80,
				'min' => 44,
			] ),
			'source' => 'title',
			'type' => 'text',
		];
		$layers[] = [
			'align' => $layout['text_align'],
			'box' => [
				'h' => 38,
				'w' => 960,
				'x' => 120,
				'y' => 434,
			],
			'color' => $brand['subtitle_color'],
			'font' => $brand['body_font'],
			'maxLines' => 1,
			'size' => 28,
			'source' => 'subtitle',
			'type' => 'text',
		];
		
		return [
			'canvas' => [
				'h' => 630,
				'w' => 1200,
			],
			'id' => 'framed',
			'label' => \__( 'Framed', 'image-socialiser' ),
			'layers' => $layers,
			'supports' => [ 'background_image', 'layout' ],
			'version' => 1,
		];
	}
	
	/**
	 * Overlay: full-bleed featured image, color overlay, centered title.
	 *
	 * Pins the text alignment (centered by design); falls back to the
	 * gradient when no featured image is set.
	 *
	 * @return	array The template definition
	 */
	public static function overlay(): array {
		$brand = Brand::get_tokens();
		$layout = Design::get_layout_tokens();
		$corners = Layout::get_corner_elements( $layout, Design::is_logo_square() );
		$layers = [
			self::gradient_background( $brand ),
			[
				'box' => [
					'h' => 630,
					'w' => 1200,
					'x' => 0,
					'y' => 0,
				],
				'fit' => 'cover',
				'source' => 'featured',
				'type' => 'image',
			],
			[
				'box' => [
					'h' => 630,
					'w' => 1200,
					'x' => 0,
					'y' => 0,
				],
				'fill' => $brand['background_from'],
				'opacity' => 0.55,
				'type' => 'rect',
			],
		];
		$layers = self::add_corner_layers( $layers, $corners, $brand );
		$layers[] = [
			'align' => 'center',
			'box' => [
				'h' => 250,
				'w' => 1000,
				'x' => 100,
				'y' => 180,
			],
			'color' => $brand['text_color'],
			'font' => $brand['heading_font'],
			'lineHeight' => 1.1,
			'maxLines' => 3,
			'size' => self::get_title_size( [
				'max' => 104,
				'min' => 56,
			] ),
			'source' => 'title',
			'type' => 'text',
		];
		$layers[] = [
			'align' => 'center',
			'box' => [
				'h' => 44,
				'w' => 880,
				'x' => 160,
				'y' => 452,
			],
			'color' => $brand['subtitle_color'],
			'font' => $brand['body_font'],
			'maxLines' => 1,
			'size' => 30,
			'source' => 'subtitle',
			'type' => 'text',
		];
		
		return [
			'canvas' => [
				'h' => 630,
				'w' => 1200,
			],
			'id' => 'overlay',
			'label' => \__( 'Overlay', 'image-socialiser' ),
			'layers' => $layers,
			'pins' => [
				'text_align' => 'center',
			],
			'supports' => [ 'layout' ],
			'version' => 1,
		];
	}
	
	/**
	 * Poster: solid brand color, oversized centered title.
	 *
	 * Pins the text alignment (centered by design) and the site name
	 * position (bottom center).
	 *
	 * @return	array The template definition
	 */
	public static function poster(): array {
		$brand = Brand::get_tokens();
		$layout = Design::get_layout_tokens();
		// only the logo is a free corner element here
		$corners = Layout::get_corner_elements(
			\array_merge( $layout, [ 'show_site_name' => false ] ),
			Design::is_logo_square()
		);
		$layers = [
			[
				'fill' => [
					'color' => $brand['background_from'],
					'kind' => 'solid',
				],
				'type' => 'background',
			],
		];
		$layers = self::add_corner_layers( $layers, $corners, $brand );
		$layers[] = [
			'align' => 'center',
			'box' => [
				'h' => 250,
				'w' => 1000,
				'x' => 100,
				'y' => 180,
			],
			'color' => $brand['text_color'],
			'font' => $brand['heading_font'],
			'lineHeight' => 1.1,
			'maxLines' => 3,
			'size' => self::get_title_size( [
				'max' => 104,
				'min' => 56,
			] ),
			'source' => 'title',
			'type' => 'text',
		];
		$layers[] = [
			'align' => 'center',
			'box' => [
				'h' => 44,
				'w' => 880,
				'x' => 160,
				'y' => 452,
			],
			'color' => $brand['subtitle_color'],
			'font' => $brand['body_font'],
			'maxLines' => 1,
			'size' => 30,
			'source' => 'subtitle',
			'type' => 'text',
		];
		
		if ( ! empty( $layout['show_site_name'] ) ) {
			$layers[] = [
				'align' => 'center',
				'box' => [
					'h' => 36,
					'w' => 1000,
					'x' => 100,
					'y' => 540,
				],
				'color' => $brand['site_name_color'],
				'font' => $brand['body_font'],
				'maxLines' => 1,
				'size' => self::get_site_name_size( 26 ),
				'source' => 'site_name',
				'type' => 'text',
			];
		}
		
		return [
			'canvas' => [
				'h' => 630,
				'w' => 1200,
			],
			'id' => 'poster',
			'label' => \__( 'Poster', 'image-socialiser' ),
			'layers' => $layers,
			'pins' => [
				'site_name_position' => 'bottom-center',
				'text_align' => 'center',
			],
			'supports' => [ 'layout' ],
			'version' => 1,
		];
	}
	
	/**
	 * Split: text column on the left, featured image cover on the right.
	 *
	 * @return	array The template definition
	 */
	public static function split(): array {
		$brand = Brand::get_tokens();
		$layout = Design::get_layout_tokens();
		// corner elements live in the left (text) column
		$corners = Layout::get_corner_elements(
			$layout,
			Design::is_logo_square(),
			[
				'margin_x' => 80,
				'w' => 720,
				'x' => 0,
			]
		);
		$layers = [
			self::gradient_background( $brand ),
			[
				'box' => [
					'h' => 630,
					'w' => 480,
					'x' => 720,
					'y' => 0,
				],
				'fit' => 'cover',
				'source' => 'featured',
				'type' => 'image',
			],
		];
		$layers = self::add_corner_layers( $layers, $corners, $brand );
		$layers[] = [
			'align' => $layout['text_align'],
			'box' => [
				'h' => 280,
				'w' => 560,
				'x' => 80,
				'y' => 170,
			],
			'color' => $brand['text_color'],
			'font' => $brand['heading_font'],
			'lineHeight' => 1.15,
			'maxLines' => 4,
			'size' => self::get_title_size( [
				'max' => 72,
				'min' => 44,
			] ),
			'source' => 'title',
			'type' => 'text',
		];
		$layers[] = [
			'align' => $layout['text_align'],
			'box' => [
				'h' => 40,
				'w' => 560,
				'x' => 80,
				'y' => 470,
			],
			'color' => $brand['subtitle_color'],
			'font' => $brand['body_font'],
			'maxLines' => 1,
			'size' => 26,
			'source' => 'subtitle',
			'type' => 'text',
		];
		
		return [
			'canvas' => [
				'h' => 630,
				'w' => 1200,
			],
			'id' => 'split',
			'label' => \__( 'Split', 'image-socialiser' ),
			'layers' => $layers,
			'supports' => [ 'layout' ],
			'version' => 1,
		];
	}
	
	/**
	 * Texture: the template ships its own background asset.
	 *
	 * A subtle dot texture (transparent PNG, alpha baked in) overlays
	 * the brand gradient via the 'template_asset' image source, so the
	 * brand colors still apply.
	 *
	 * @return	array The template definition
	 */
	public static function texture(): array {
		$brand = Brand::get_tokens();
		$layout = Design::get_layout_tokens();
		$corners = Layout::get_corner_elements( $layout, Design::is_logo_square() );
		$asset = self::get_template_asset( 'dots.png' );
		$layers = [
			self::gradient_background( $brand ),
			[
				'asset_path' => $asset['path'],
				'asset_url' => $asset['url'],
				'box' => [
					'h' => 630,
					'w' => 1200,
					'x' => 0,
					'y' => 0,
				],
				'fit' => 'cover',
				'source' => 'template_asset',
				'type' => 'image',
			],
		];
		$layers = self::add_corner_layers( $layers, $corners, $brand );
		$layers[] = [
			'align' => $layout['text_align'],
			'box' => [
				'h' => 240,
				'w' => 1040,
				'x' => 80,
				'y' => 180,
			],
			'color' => $brand['text_color'],
			'font' => $brand['heading_font'],
			'lineHeight' => 1.15,
			'maxLines' => 3,
			'size' => self::get_title_size( [
				'max' => 84,
				'min' => 48,
			] ),
			'source' => 'title',
			'type' => 'text',
		];
		$layers[] = [
			'align' => $layout['text_align'],
			'box' => [
				'h' => 40,
				'w' => 1040,
				'x' => 80,
				'y' => 446,
			],
			'color' => $brand['subtitle_color'],
			'font' => $brand['body_font'],
			'maxLines' => 1,
			'size' => 30,
			'source' => 'subtitle',
			'type' => 'text',
		];
		
		return [
			'canvas' => [
				'h' => 630,
				'w' => 1200,
			],
			'id' => 'texture',
			'label' => \__( 'Texture', 'image-socialiser' ),
			'layers' => $layers,
			'supports' => [ 'layout' ],
			'version' => 1,
		];
	}
	
	/**
	 * Append the corner element layers (logo, site name) to a stack.
	 *
	 * @param	array	$layers The layer stack
	 * @param	array{logo: array|null, site_name: array|null}	$corners The corner slots
	 * @param	array	$brand The brand tokens
	 * @return	array The extended layer stack
	 */
	private static function add_corner_layers( array $layers, array $corners, array $brand ): array {
		if ( $corners['logo'] !== null ) {
			$layers[] = [
				'align' => $corners['logo']['align'],
				'box' => $corners['logo']['box'],
				'fit' => 'contain',
				'source' => 'logo',
				'type' => 'image',
			];
		}
		
		if ( $corners['site_name'] !== null ) {
			$layers[] = [
				'align' => $corners['site_name']['align'],
				'box' => $corners['site_name']['box'],
				'color' => $brand['site_name_color'],
				'font' => $brand['body_font'],
				'maxLines' => 1,
				'size' => self::get_site_name_size( 28 ),
				'source' => 'site_name',
				'type' => 'text',
			];
		}
		
		return $layers;
	}
	
	/**
	 * Get the effective site name font size (theme support or default).
	 *
	 * @param	int	$default The template's default size
	 * @return	int The font size
	 */
	private static function get_site_name_size( int $default ): int {
		return (int) ( Theme_Support::get_font_sizes()['site_name'] ?? $default );
	}
	
	/**
	 * Get the effective title font size range (theme support or default).
	 *
	 * @param	array{max: int, min: int}	$default The template's default range
	 * @return	array{max: int, min: int} The size range
	 */
	private static function get_title_size( array $default ): array {
		return Theme_Support::get_font_sizes()['title'] ?? $default;
	}
	
	/**
	 * Get the path and URL of a bundled template asset.
	 *
	 * @param	string	$file The file name inside assets/textures/
	 * @return	array{path: string, url: string} The absolute path and the URL
	 */
	private static function get_template_asset( string $file ): array {
		$plugin_file = Plugin::get_instance()->plugin_file;
		
		return [
			'path' => \plugin_dir_path( $plugin_file ) . 'assets/textures/' . $file,
			'url' => \plugin_dir_url( $plugin_file ) . 'assets/textures/' . $file,
		];
	}
	
	/**
	 * Get the standard gradient background layer.
	 *
	 * @param	array	$brand The brand tokens
	 * @return	array The background layer definition
	 */
	private static function gradient_background( array $brand ): array {
		return [
			'fill' => [
				'kind' => 'gradient',
				'stops' => [
					[
						'color' => $brand['background_from'],
						'offset' => 0,
					],
					[
						'color' => $brand['background_to'],
						'offset' => 1,
					],
				],
			],
			'type' => 'background',
		];
	}
}
