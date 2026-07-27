<?php
declare(strict_types=1);

namespace happyhappy\ImageSocialiser\Seo;

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
}
