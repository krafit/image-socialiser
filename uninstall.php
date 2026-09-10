<?php
declare(strict_types=1);

namespace happyhappy\ImageSocialiser;

/**
 * Uninstall routines for Image Socialiser.
 *
 * @package	happyhappy\ImageSocialiser
 */

use happyhappy\ImageSocialiser\Rendering\Output_Format;

// prevent direct file access and make sure we're really uninstalling
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// the plugin itself is not loaded during uninstall, so the one class
// needed to know which extensions were written is pulled in directly
// (Output_Format::get_extensions() has no dependencies of its own)
require_once __DIR__ . '/src/Rendering/Output_Format.php';

/**
 * Delete one site's options, meta, scheduled jobs, and generated files.
 */
function image_socialiser_uninstall_site(): void {
	// options
	\delete_option( 'image_socialiser_brand' );
	\delete_option( 'image_socialiser_content' );
	\delete_option( 'image_socialiser_custom_fonts' );
	\delete_option( 'image_socialiser_cpt_templates' );
	\delete_option( 'image_socialiser_design_overrides' );
	\delete_option( 'image_socialiser_design_version' );
	\delete_option( 'image_socialiser_fallbacks' );
	\delete_option( 'image_socialiser_layout' );
	\delete_option( 'image_socialiser_context' );
	\delete_option( 'image_socialiser_context_state' );
	\delete_option( 'image_socialiser_version' );
	
	// per-subject state rows (0.14.0+)
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$GLOBALS['wpdb']->query(
		"DELETE FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'image\_socialiser\_state\_%'"
	);
	// design-pack manifest caches (0.18.0+)
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$GLOBALS['wpdb']->query(
		"DELETE FROM {$GLOBALS['wpdb']->options}
		WHERE option_name LIKE '\_transient\_image\_socialiser\_pack\_%'
		OR option_name LIKE '\_transient\_timeout\_image\_socialiser\_pack\_%'"
	);
	\delete_option( 'image_socialiser_post_types' );
	\delete_option( 'image_socialiser_sync_generation' );

	// post meta
	$meta_keys = [
		'_image_socialiser_error',
		'_image_socialiser_format',
		'_image_socialiser_hash',
		'_image_socialiser_override_id',
		'_image_socialiser_status',
		'_image_socialiser_subtitle',
		'_image_socialiser_template_id',
		'_image_socialiser_title',
	];

	foreach ( $meta_keys as $meta_key ) {
		\delete_post_meta_by_key( $meta_key );
	}
	
	// term meta (title, subtitle, override, generation state)
	global $wpdb;
	
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		"DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE '\_image\_socialiser\_%'"
	);

	// scheduled jobs
	\wp_unschedule_hook( 'image_socialiser_generate' );
	\wp_unschedule_hook( 'image_socialiser_bulk_regenerate' );
	\wp_unschedule_hook( 'image_socialiser_sweep_orphans' );

	if ( \function_exists( 'as_unschedule_all_actions' ) ) {
		\as_unschedule_all_actions( '', [], 'image-socialiser' );
	}

	// generated files
	$uploads = \wp_upload_dir();
	// other plugins and the active theme are still loaded during
	// uninstall, so a filtered storage directory resolves correctly
	// here — matching Storage::get_directory()
	$directory_name = \trim(
		(string) \apply_filters( 'image_socialiser_directory_name', 'og-images' ),
		'/'
	);
	$directory = $uploads['basedir'] . '/' . $directory_name;
	// renders are written as PNG or JPEG depending on the design, so
	// every supported extension has to be swept, not just PNG
	$extensions = \implode( ',', Output_Format::get_extensions() );
	$files = \array_merge(
		\glob( $directory . '/og-*.{' . $extensions . '}', \GLOB_BRACE ) ?: [],
		\glob( $directory . '/fonts/*.ttf' ) ?: [],
		\glob( $directory . '/fonts/*.otf' ) ?: []
	);

	foreach ( $files as $file ) {
		\wp_delete_file( $file );
	}

	// the fonts subdirectory carries its own guard and has to go
	// first, or the parent rmdir() below always fails
	image_socialiser_uninstall_directory( $directory . '/fonts', [ 'index.php' ] );
	image_socialiser_uninstall_directory( $directory, [ '.htaccess', 'index.php' ] );
}

/**
 * Remove a directory's guard files and then the directory itself.
 *
 * @param	string	$directory The absolute directory path
 * @param	string[]	$guards The guard filenames to remove first
 */
function image_socialiser_uninstall_directory( string $directory, array $guards ): void {
	if ( ! \is_dir( $directory ) ) {
		return;
	}

	foreach ( $guards as $guard ) {
		if ( \file_exists( $directory . '/' . $guard ) ) {
			\wp_delete_file( $directory . '/' . $guard );
		}
	}

	// WP_Filesystem is not bootstrapped during uninstall, and a failed
	// rmdir() (directory not empty) is an expected, non-fatal outcome
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged
	@\rmdir( $directory );
}

if ( \is_multisite() ) {
	foreach ( \get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) as $image_socialiser_site_id ) {
		\switch_to_blog( (int) $image_socialiser_site_id );
		image_socialiser_uninstall_site();
		\restore_current_blog();
	}
	
	// network options
	\delete_site_option( 'image_socialiser_network_brand' );
	\delete_site_option( 'image_socialiser_network_content' );
	\delete_site_option( 'image_socialiser_network_custom_fonts' );
	\delete_site_option( 'image_socialiser_network_fallbacks' );
	\delete_site_option( 'image_socialiser_network_layout' );
	\delete_site_option( 'image_socialiser_network_permissions' );
	\delete_site_option( 'image_socialiser_network_post_types' );
	
	// network font files (main site's fonts directory)
	\switch_to_blog( \get_main_site_id() );
	$image_socialiser_uploads = \wp_upload_dir();
	\restore_current_blog();
	$image_socialiser_fonts_dir = $image_socialiser_uploads['basedir'] . '/'
		. \trim( (string) \apply_filters( 'image_socialiser_directory_name', 'og-images' ), '/' )
		. '/fonts';
	
	foreach ( \array_merge(
		\glob( $image_socialiser_fonts_dir . '/*.ttf' ) ?: [],
		\glob( $image_socialiser_fonts_dir . '/*.otf' ) ?: []
	) as $image_socialiser_font_file ) {
		\wp_delete_file( $image_socialiser_font_file );
	}
	
	image_socialiser_uninstall_directory( $image_socialiser_fonts_dir, [ 'index.php' ] );
	image_socialiser_uninstall_directory( \dirname( $image_socialiser_fonts_dir ), [ '.htaccess', 'index.php' ] );
}
else {
	image_socialiser_uninstall_site();
}
