<?php
declare(strict_types=1);

namespace happyhappy\ImageSocialiser\Generation;

use happyhappy\ImageSocialiser\Multisite\Multisite;
use happyhappy\ImageSocialiser\Rendering\Fonts;
use happyhappy\ImageSocialiser\Template\Binding;
use happyhappy\ImageSocialiser\Template\Brand;
use happyhappy\ImageSocialiser\Template\Design;
use happyhappy\ImageSocialiser\Template\Template_Model;
use WP_Post;

/**
 * Storage for generated images: paths, hashing, writing and cleanup.
 *
 * Files live as plain files (not attachments) in a dedicated
 * subdirectory of the current site's uploads directory. Filenames are
 * content-addressed, so any change produces a new URL and invalidates
 * caches for free.
 *
 * @author	Simon Kraft
 * @license	GPL2
 * @package	happyhappy\ImageSocialiser
 */
final class Storage {
	/**
	 * @var	string Option name for the global design version.
	 */
	public const string OPTION_DESIGN_VERSION = 'image_socialiser_design_version';
	
	/**
	 * Compute the content hash for a post's image.
	 *
	 * The hash covers everything that affects the rendered output. Any
	 * change produces a new filename and therefore a new URL.
	 *
	 * Note the deliberate asymmetry: this method binds the GIVEN post
	 * object (which may carry unsaved in-memory changes, e.g. during a
	 * save_post cycle), while get_hash_for() re-reads the post from the
	 * database. Do not "simplify" this into a delegation.
	 *
	 * @param	\WP_Post	$post The post
	 * @param	\happyhappy\ImageSocialiser\Template\Template_Model	$model The template
	 * @param	string	$renderer_id The renderer identifier
	 * @return	string The content hash
	 */
	public function get_hash( WP_Post $post, Template_Model $model, string $renderer_id ): string {
		// bind the given object (it may carry unsaved changes), matching
		// the pre-0.13.0 behavior exactly
		return $this->compute_hash(
			new Binding( $post ),
			Subject::from_post( $post->ID ),
			$model,
			$renderer_id
		);
	}
	
	/**
	 * Compute the content hash for any subject.
	 *
	 * @param	Subject	$subject The subject
	 * @param	Template_Model	$model The resolved template
	 * @param	string	$renderer_id The renderer identifier
	 * @return	string The content hash
	 */
	public function get_hash_for( Subject $subject, Template_Model $model, string $renderer_id ): string {
		return $this->compute_hash( new Binding( $subject ), $subject, $model, $renderer_id );
	}
	
	/**
	 * Compute the content hash from a prepared binding.
	 *
	 * @param	Binding	$binding The binding
	 * @param	Subject	$subject The subject
	 * @param	Template_Model	$model The resolved template
	 * @param	string	$renderer_id The renderer identifier
	 * @return	string The content hash
	 */
	private function compute_hash(
		Binding $binding,
		Subject $subject,
		Template_Model $model,
		string $renderer_id
	): string {
		$brand = Brand::get_tokens();
		$parts = [
			// bound text values
			$binding->get_title(),
			$binding->get_subtitle(),
			// the resolved template embeds every design token value,
			// so any design change — from any source — changes the hash
			(string) \wp_json_encode( $model->to_array() ),
			// bound image attachments
			(string) $subject->get_featured_id(),
			(string) Design::resolve_logo_id(),
			(string) (int) $brand['cover_art_id'],
			Design::get_background_fingerprint(),
			// font files (ids + content hashes)
			Fonts::get_fingerprint( $model ),
			$renderer_id,
			Multisite::get_fingerprint(),
			// manual escape hatch
			(string) (int) \get_option( self::OPTION_DESIGN_VERSION, 1 ),
		];
		
		return \sha1( \implode( '|', $parts ) );
	}
	
	/**
	 * Get the storage directory path and URL.
	 *
	 * Uses wp_upload_dir(), which is blog-aware, so every site in a
	 * multisite network gets its own directory automatically.
	 *
	 * @return	array{path: string, url: string} The directory path and URL
	 */
	public function get_directory(): array {
		$uploads = \wp_upload_dir();
		
		/**
		 * Filter the name of the storage subdirectory inside uploads.
		 *
		 * @param	string	$directory_name The subdirectory name
		 */
		$directory_name = (string) \apply_filters( 'image_socialiser_directory_name', 'og-images' );
		$directory_name = \trim( $directory_name, '/' );
		
		return [
			'path' => $uploads['basedir'] . '/' . $directory_name,
			'url' => $uploads['baseurl'] . '/' . $directory_name,
		];
	}
	
	/**
	 * Get the filename for a post and content hash.
	 *
	 * @param	int	$post_id The post ID
	 * @param	string	$hash The content hash
	 * @return	string The filename
	 */
	public function get_filename( int $post_id, string $hash ): string {
		return $this->get_filename_for( Subject::from_post( $post_id ), $hash );
	}
	
	/**
	 * Get the filename for a subject and hash.
	 *
	 * @param	Subject	$subject The subject
	 * @param	string	$hash The content hash
	 * @return	string The filename
	 */
	public function get_filename_for( Subject $subject, string $hash ): string {
		return $subject->get_prefix() . '-' . $hash . '.png';
	}
	
	/**
	 * Get the public URL for a subject's generated file.
	 *
	 * @param	Subject	$subject The subject
	 * @param	string	$hash The content hash
	 * @return	string The URL
	 */
	public function get_url_for( Subject $subject, string $hash ): string {
		return $this->get_directory()['url'] . '/' . $this->get_filename_for( $subject, $hash );
	}
	
	/**
	 * Save the rendered bytes for a subject.
	 *
	 * @param	Subject	$subject The subject
	 * @param	string	$hash The content hash
	 * @param	string	$bytes The PNG bytes
	 * @return	string The written path or an empty string on failure
	 */
	public function save_for( Subject $subject, string $hash, string $bytes ): string {
		$directory = $this->ensure_directory();
		
		if ( $directory === '' ) {
			return '';
		}
		
		$filename = $this->get_filename_for( $subject, $hash );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$written = \file_put_contents( $directory . '/' . $filename, $bytes );
		
		if ( $written === false ) {
			return '';
		}
		
		$this->delete_stale_for( $subject, $filename );
		
		return $directory . '/' . $filename;
	}
	
	/**
	 * Delete all generated files of a subject except one.
	 *
	 * @param	Subject	$subject The subject
	 * @param	string	$keep_filename The filename to keep (empty to delete all)
	 */
	public function delete_stale_for( Subject $subject, string $keep_filename = '' ): void {
		$files = \glob( $this->get_directory()['path'] . '/' . $subject->get_prefix() . '-*.png' ) ?: [];
		
		foreach ( $files as $file ) {
			// numeric post/term ids are unambiguous via the glob's literal
			// '-' boundary; hyphenated archive keys ('post-2') are not, so
			// those require the exact 40-hex hash segment
			if ( $subject->kind === 'post_type_archive'
				&& \preg_match(
					'/^' . \preg_quote( $subject->get_prefix(), '/' ) . '-[0-9a-f]{40}\.png$/',
					\basename( $file )
				) !== 1
			) {
				continue;
			}
			
			if ( \basename( $file ) === $keep_filename ) {
				continue;
			}
			
			\wp_delete_file( $file );
		}
	}
	
	/**
	 * Get the public URL for a post and content hash.
	 *
	 * @param	int	$post_id The post ID
	 * @param	string	$hash The content hash
	 * @return	string The public URL
	 */
	public function get_url( int $post_id, string $hash ): string {
		return $this->get_directory()['url'] . '/' . $this->get_filename( $post_id, $hash );
	}
	
	/**
	 * Write image bytes for a post and delete its stale files.
	 *
	 * @param	int	$post_id The post ID
	 * @param	string	$hash The content hash
	 * @param	string	$bytes The PNG image bytes
	 * @return	string The absolute file path or an empty string on failure
	 */
	public function save( int $post_id, string $hash, string $bytes ): string {
		return $this->save_for( Subject::from_post( $post_id ), $hash, $bytes );
	}
	
	/**
	 * Delete all orphaned files.
	 *
	 * A file is orphaned when its owning post no longer exists or when
	 * its content hash no longer matches the post's stored hash.
	 *
	 * @return	int The number of deleted files
	 */
	public function delete_orphans(): int {
		$files = \glob( $this->get_directory()['path'] . '/og-*.png' ) ?: [];
		$deleted = 0;
		$entries = [];
		$post_ids = [];
		$term_ids = [];
		
		// first pass: parse filenames and collect IDs so the meta reads
		// below hit primed caches instead of one query per file
		foreach ( $files as $file ) {
			$subject = $this->get_subject_for_file( \basename( $file ), $hash );
			
			if ( $subject === null ) {
				continue;
			}
			
			$entries[] = [ $file, $subject, $hash ];
			
			if ( $subject->kind === 'post' ) {
				$post_ids[] = $subject->id;
			}
			elseif ( $subject->kind === 'term' ) {
				$term_ids[] = $subject->id;
			}
		}
		
		if ( $post_ids !== [] && \function_exists( 'update_meta_cache' ) ) {
			\update_meta_cache( 'post', \array_unique( $post_ids ) );
		}
		
		if ( $term_ids !== [] && \function_exists( 'update_meta_cache' ) ) {
			\update_meta_cache( 'term', \array_unique( $term_ids ) );
		}
		
		$live_context_slugs = [];
		
		foreach ( $entries as [ $file, $subject, $hash ] ) {
			if ( $subject->get_state( Generator::META_HASH ) === $hash ) {
				if ( $subject->kind !== 'post' && $subject->kind !== 'term' ) {
					$live_context_slugs[] = $subject->get_slug();
				}
				
				continue;
			}
			
			\wp_delete_file( $file );
			$deleted++;
		}
		
		$this->prune_context_state( $live_context_slugs );
		
		return $deleted;
	}
	
	/**
	 * Remove context-state rows whose "ready" file no longer exists.
	 *
	 * Keeps the options table from accumulating state for removed post
	 * types or long-disabled special pages. Pending/failed states are
	 * kept — a queued job may still be about to write the file.
	 *
	 * @param	string[]	$live_slugs Slugs whose file was just verified
	 */
	private function prune_context_state( array $live_slugs ): void {
		global $wpdb;
		
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$option_names = $wpdb->get_col(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'image\_socialiser\_state\_%'"
		);
		
		if ( ! \is_array( $option_names ) ) {
			return;
		}
		
		foreach ( $option_names as $option_name ) {
			$slug = \substr( (string) $option_name, \strlen( Subject::OPTION_STATE_PREFIX ) );
			
			if ( \in_array( $slug, $live_slugs, true ) ) {
				continue;
			}
			
			$state = \get_option( (string) $option_name, [] );
			
			if ( \is_array( $state )
				&& ( $state[ Generator::META_STATUS ] ?? '' ) === Generator::STATUS_READY
			) {
				\delete_option( (string) $option_name );
			}
		}
	}
	
	/**
	 * Map a generated filename back to its owning subject.
	 *
	 * @param	string	$filename The basename
	 * @param	string|null	$hash Receives the file's content hash
	 * @return	Subject|null The subject or null for unrecognized files
	 */
	private function get_subject_for_file( string $filename, ?string &$hash ): ?Subject {
		$hash = null;
		
		if ( \preg_match( '/^og-term-(\d+)-([0-9a-f]{40})\.png$/', $filename, $matches ) === 1 ) {
			$hash = $matches[2];
			
			return Subject::from_term( (int) $matches[1] );
		}
		
		if ( \preg_match( '/^og-archive-([a-z0-9_\-]+)-([0-9a-f]{40})\.png$/', $filename, $matches ) === 1 ) {
			$hash = $matches[2];
			
			return Subject::from_post_type_archive( $matches[1] );
		}
		
		if ( \preg_match( '/^og-(front|blog|search|404)-([0-9a-f]{40})\.png$/', $filename, $matches ) === 1 ) {
			$hash = $matches[2];
			
			return Subject::special( $matches[1] );
		}
		
		if ( \preg_match( '/^og-(\d+)-([0-9a-f]{40})\.png$/', $filename, $matches ) === 1 ) {
			$hash = $matches[2];
			
			return Subject::from_post( (int) $matches[1] );
		}
		
		return null;
	}
	
	/**
	 * Delete all generated files of a post except one.
	 *
	 * @param	int	$post_id The post ID
	 * @param	string	$keep_filename The filename to keep (empty to delete all)
	 */
	public function delete_stale( int $post_id, string $keep_filename = '' ): void {
		$this->delete_stale_for( Subject::from_post( $post_id ), $keep_filename );
	}
	
	/**
	 * Make sure the storage directory exists and is guarded.
	 *
	 * Creates the directory, an index.php guard and an .htaccess file
	 * with long-lived immutable cache headers (URLs are
	 * content-addressed).
	 *
	 * @return	string The directory path or an empty string on failure
	 */
	private function ensure_directory(): string {
		$directory = $this->get_directory()['path'];
		
		if ( ! \wp_mkdir_p( $directory ) ) {
			return '';
		}
		
		$guards = [
			'.htaccess' => '<IfModule mod_headers.c>' . \PHP_EOL
				. "\t" . 'Header set Cache-Control "public, max-age=31536000, immutable"' . \PHP_EOL
				. '</IfModule>' . \PHP_EOL,
			'index.php' => '<?php' . \PHP_EOL . '// silence is golden' . \PHP_EOL,
		];
		
		foreach ( $guards as $filename => $content ) {
			$guard_path = $directory . '/' . $filename;
			
			if ( \file_exists( $guard_path ) ) {
				continue;
			}
			
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			\file_put_contents( $guard_path, $content );
		}
		
		return $directory;
	}
}
