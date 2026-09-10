<?php
declare(strict_types=1);

namespace happyhappy\ImageSocialiser\Seo;

// prevent direct file access
if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface for SEO plugin adapters.
 *
 * One adapter per SEO plugin; each hooks the plugin's native filters
 * to inject the resolved Open Graph image.
 *
 * @author	Simon Kraft
 * @license	GPL2
 * @package	happyhappy\ImageSocialiser
 */
interface Seo_Adapter {
	/**
	 * Get the unique adapter identifier.
	 *
	 * @return	string The adapter identifier
	 */
	public function get_id(): string;
	
	/**
	 * Check whether the corresponding SEO plugin is active.
	 *
	 * @return	bool Whether the SEO plugin is active
	 */
	public function is_active(): bool;
	
	/**
	 * Register the adapter's hooks.
	 */
	public function register(): void;
	
	/**
	 * Check whether the SEO plugin holds its own per-post social image.
	 *
	 * Every supported SEO plugin has a per-post Open Graph image
	 * field of its own. Setting it is the same deliberate act as
	 * setting our manual override, so when one is present we step
	 * aside and let the SEO plugin output its own image — with its
	 * own dimensions and alt text, which we could not reproduce
	 * faithfully from a bare URL.
	 *
	 * @param	int	$post_id The post ID
	 * @return	bool Whether the SEO plugin has an explicit image for this post
	 */
	public function has_manual_image( int $post_id ): bool;
}
