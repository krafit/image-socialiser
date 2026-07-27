<?php
declare(strict_types=1);

namespace happyhappy\ImageSocialiser;

/**
 * Uninstall routines for Image Socialiser.
 *
 * @package	happyhappy\ImageSocialiser
 */

// prevent direct file access and make sure we're really uninstalling
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

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

	// post meta
	$meta_keys = [
		'_image_socialiser_error',
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
	$directory = $uploads['basedir'] . '/og-images';
	$files = \array_merge(
		\glob( $directory . '/og-*.png' ) ?: [],
		\glob( $directory . '/fonts/*.ttf' ) ?: [],
		\glob( $directory . '/fonts/*.otf' ) ?: []
	);

	foreach ( $files as $file ) {
		\wp_delete_file( $file );
	}

	foreach ( [ '.htaccess', 'index.php' ] as $guard ) {
		if ( \file_exists( $directory . '/' . $guard ) ) {
			\wp_delete_file( $directory . '/' . $guard );
		}
	}

	if ( \is_dir( $directory ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		@\rmdir( $directory ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
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
	$image_socialiser_fonts_dir = $image_socialiser_uploads['basedir'] . '/og-images/fonts';
	
	foreach ( \array_merge(
		\glob( $image_socialiser_fonts_dir . '/*.ttf' ) ?: [],
		\glob( $image_socialiser_fonts_dir . '/*.otf' ) ?: []
	) as $image_socialiser_font_file ) {
		\wp_delete_file( $image_socialiser_font_file );
	}
}
else {
	image_socialiser_uninstall_site();
}
