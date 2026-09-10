<?php
declare(strict_types=1);

namespace happyhappy\ImageSocialiser\Generation;

use happyhappy\ImageSocialiser\Template\Brand;
use happyhappy\ImageSocialiser\Template\Design;
use happyhappy\ImageSocialiser\Template\Template_Registry;
use WP_Post;

// prevent direct file access
if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Queues image generation as background jobs.
 *
 * Uses Action Scheduler when it is loaded (bundled in vendor/, or
 * provided by another plugin) and falls back to WP-Cron single events
 * otherwise. Jobs are de-duplicated per post so rapid saves collapse
 * into one job.
 *
 * @author	Simon Kraft
 * @license	GPL2
 * @package	happyhappy\ImageSocialiser
 */
final class Scheduler {
	/**
	 * @var	string Hook name for a batched bulk regeneration run.
	 */
	public const string ACTION_BULK = 'image_socialiser_bulk_regenerate';
	
	/**
	 * @var	string Hook name for a single generation job.
	 */
	public const string ACTION_GENERATE = 'image_socialiser_generate';
	
	/**
	 * @var	string Hook name for a term batch during bulk regeneration.
	 */
	public const string ACTION_BULK_TERMS = 'image_socialiser_bulk_terms';
	
	/**
	 * @var	string Hook name for generating the archive/special subjects.
	 */
	public const string ACTION_CONTEXTS = 'image_socialiser_generate_contexts';
	
	/**
	 * @var	string Hook name for generating a term image.
	 */
	public const string ACTION_GENERATE_TERM = 'image_socialiser_generate_term';
	
	/**
	 * @var	string Hook name for a network-wide regeneration batch (multisite).
	 */
	public const string ACTION_NETWORK_BULK = 'image_socialiser_network_bulk';
	
	/**
	 * @var	string Hook name for the recurring orphaned-file sweep.
	 */
	public const string ACTION_SWEEP = 'image_socialiser_sweep_orphans';
	
	/**
	 * @var	int Number of sites per network regeneration batch.
	 */
	public const int SITE_BATCH_SIZE = 10;
	
	/**
	 * @var	int Number of posts scheduled per bulk batch.
	 */
	public const int BULK_BATCH_SIZE = 25;
	
	/**
	 * @var	string Action Scheduler group name.
	 */
	public const string GROUP = 'image-socialiser';
	
	/**
	 * @var	int Maximum generation attempts per job.
	 */
	public const int MAX_ATTEMPTS = 3;
	
	/**
	 * @var	string Option name for the synchronous generation mode.
	 */
	public const string OPTION_SYNC = 'image_socialiser_sync_generation';
	
	/**
	 * @var	int Minimum remaining request seconds required to attempt
	 * 		a synchronous render; below this the queue takes over.
	 */
	public const int SYNC_TIME_BUDGET = 10;
	
	/**
	 * Initialize the scheduler.
	 */
	public static function init(): void {
		$instance = new self();
		
		\add_action( 'save_post', [ $instance, 'schedule_on_save' ], 20, 2 );
		// wp_after_insert_post (not save_post): in the REST flow the
		// featured image and meta are saved only after save_post has
		// fired, and a synchronous render must see the final state
		\add_action( 'wp_after_insert_post', [ $instance, 'maybe_generate_synchronously' ], 20, 2 );
		\add_action( 'deleted_post', [ $instance, 'handle_deleted_post' ] );
		\add_action( 'init', [ $instance, 'ensure_sweep_scheduled' ] );
		\add_action( self::ACTION_GENERATE, [ $instance, 'run_generation' ], 10, 2 );
		\add_action( self::ACTION_BULK, [ $instance, 'run_bulk_batch' ] );
		\add_action( self::ACTION_BULK_TERMS, [ $instance, 'run_term_batch' ], 10, 2 );
		\add_action( self::ACTION_CONTEXTS, [ $instance, 'run_context_generation' ] );
		\add_action( self::ACTION_GENERATE_TERM, [ $instance, 'run_term_generation' ] );
		\add_action( self::ACTION_SWEEP, [ $instance, 'run_sweep' ] );
		\add_action( 'created_term', [ $instance, 'schedule_on_term_change' ], 20, 3 );
		
		if ( \is_multisite() ) {
			\add_action( self::ACTION_NETWORK_BULK, [ $instance, 'run_network_bulk' ] );
		}
		\add_action( 'edited_term', [ $instance, 'schedule_on_term_change' ], 20, 3 );
		\add_action( 'delete_term', [ $instance, 'handle_deleted_term' ], 20, 3 );
		\add_action(
			'update_option_' . Context::OPTION_NAME,
			[ $instance, 'handle_context_change' ]
		);
		\add_action( 'update_option_' . Brand::OPTION_NAME, [ $instance, 'handle_design_change' ] );
		\add_action( 'update_option_' . Design::OPTION_LAYOUT, [ $instance, 'handle_design_change' ] );
		\add_action( 'update_option_' . Design::OPTION_CONTENT, [ $instance, 'handle_design_change' ] );
		\add_action(
			'update_option_' . Design::OPTION_DESIGN_OVERRIDES,
			[ $instance, 'handle_design_change' ]
		);
		\add_action( 'switch_theme', [ $instance, 'handle_design_change' ] );
		\add_action( 'upgrader_process_complete', [ $instance, 'handle_theme_update' ], 10, 2 );
		\add_action(
			'update_option_' . Template_Registry::OPTION_CPT_TEMPLATES,
			[ $instance, 'handle_design_change' ]
		);
		\add_action(
			'update_option_' . Post_Types::OPTION_NAME,
			[ $instance, 'handle_post_types_change' ]
		);
	}
	
	/**
	 * Make sure the daily orphaned-file sweep is scheduled.
	 */
	public function ensure_sweep_scheduled(): void {
		if ( $this->is_action_scheduler_available() ) {
			if ( ! \as_has_scheduled_action( self::ACTION_SWEEP, [], self::GROUP ) ) {
				\as_schedule_recurring_action(
					\time() + \DAY_IN_SECONDS,
					\DAY_IN_SECONDS,
					self::ACTION_SWEEP,
					[],
					self::GROUP
				);
			}
			
			return;
		}
		
		if ( \wp_next_scheduled( self::ACTION_SWEEP ) === false ) {
			\wp_schedule_event( \time() + \DAY_IN_SECONDS, 'daily', self::ACTION_SWEEP );
		}
	}
	
	/**
	 * Delete a permanently deleted post's generated files.
	 *
	 * @param	int	$post_id The deleted post ID
	 */
	public function handle_deleted_term( int $term_id, int $term_taxonomy_id = 0, string $taxonomy = '' ): void {
		$subject = Subject::from_term( $term_id );
		
		( new Storage() )->delete_stale_for( $subject );
		$subject->delete_state();
	}
	
	/**
	 * Generate the archive and special page subjects (one lightweight job).
	 */
	public function run_context_generation(): void {
		$generator = new Generator();
		$subjects = [];
		
		foreach ( Post_Types::get_supported() as $post_type ) {
			$subjects[] = Subject::from_post_type_archive( $post_type );
		}
		
		foreach ( Subject::SPECIALS as $kind ) {
			$subjects[] = Subject::special( $kind );
		}
		
		foreach ( $subjects as $subject ) {
			if ( $subject->is_enabled() && $generator->needs_generation_subject( $subject ) ) {
				$generator->generate_subject( $subject );
			}
		}
	}
	
	/**
	 * Process one batch of terms during bulk regeneration.
	 *
	 * Uses keyset pagination (term_id lower bound) instead of offsets:
	 * terms created or deleted while a bulk run is in flight would
	 * shift offset windows and skip or duplicate terms.
	 *
	 * @param	string	$taxonomy The taxonomy name
	 * @param	int	$last_id Process terms with an ID greater than this
	 */
	public function run_term_batch( string $taxonomy, int $last_id = 0 ): void {
		global $wpdb;
		
		// keyset pagination needs a lower bound, which get_terms() does
		// not offer
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$term_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT t.term_id FROM {$wpdb->terms} t"
					. " INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id"
					. ' WHERE tt.taxonomy = %s AND t.term_id > %d ORDER BY t.term_id ASC LIMIT %d',
				$taxonomy,
				$last_id,
				self::BULK_BATCH_SIZE
			)
		);
		
		if ( ! \is_array( $term_ids ) || $term_ids === [] ) {
			return;
		}
		
		$generator = new Generator();
		
		foreach ( $term_ids as $term_id ) {
			$generator->generate_subject( Subject::from_term( (int) $term_id ) );
		}
		
		if ( \count( $term_ids ) === self::BULK_BATCH_SIZE ) {
			$this->enqueue_action(
				self::ACTION_BULK_TERMS,
				[ $taxonomy, (int) \end( $term_ids ) ]
			);
		}
	}
	
	/**
	 * Run a single term generation job.
	 *
	 * @param	int	$term_id The term ID
	 */
	public function run_term_generation( int $term_id ): void {
		( new Generator() )->generate_subject( Subject::from_term( $term_id ) );
	}
	
	/**
	 * Enqueue a term generation when a term of an enabled taxonomy changes.
	 *
	 * @param	int	$term_id The term ID
	 * @param	int	$term_taxonomy_id The term taxonomy ID
	 * @param	string	$taxonomy The taxonomy name
	 */
	public function schedule_on_term_change( int $term_id, int $term_taxonomy_id = 0, string $taxonomy = '' ): void {
		if ( ! Context::is_taxonomy_enabled( $taxonomy ) ) {
			return;
		}
		
		Subject::from_term( $term_id )->set_state( Generator::META_STATUS, Generator::STATUS_PENDING );
		$this->enqueue_action( self::ACTION_GENERATE_TERM, [ $term_id ] );
	}
	
	/**
	 * Enqueue an async action, de-duplicated, with the cron fallback.
	 *
	 * @param	string	$hook The action hook
	 * @param	array	$arguments The action arguments
	 */
	private function enqueue_action( string $hook, array $arguments ): void {
		if ( $this->is_action_scheduler_available() ) {
			if ( ! \as_has_scheduled_action( $hook, $arguments, self::GROUP ) ) {
				\as_enqueue_async_action( $hook, $arguments, self::GROUP );
			}
			
			return;
		}
		
		if ( \wp_next_scheduled( $hook, $arguments ) === false ) {
			\wp_schedule_single_event( \time(), $hook, $arguments );
		}
	}
	
	/**
	 * Delete a permanently deleted post's generated files.
	 *
	 * @param	int	$post_id The post ID
	 */
	public function handle_deleted_post( int $post_id ): void {
		( new Storage() )->delete_stale( $post_id );
	}
	
	/**
	 * Regenerate all posts after the enabled post types changed.
	 *
	 * Wrapper around schedule_bulk_regeneration() because the
	 * update_option hook passes the old value as first argument.
	 */
	public function handle_post_types_change(): void {
		$this->schedule_bulk_regeneration();
	}
	
	/**
	 * Run the orphaned-file sweep.
	 */
	public function run_sweep(): void {
		( new Storage() )->delete_orphans();
	}
	
	/**
	 * Enqueue a generation job for a post, de-duplicated.
	 *
	 * @param	int	$post_id The post ID
	 */
	public function enqueue( int $post_id ): void {
		$arguments = [ $post_id, 1 ];
		
		if ( $this->is_action_scheduler_available() ) {
			if ( ! \as_has_scheduled_action( self::ACTION_GENERATE, $arguments, self::GROUP ) ) {
				\as_enqueue_async_action( self::ACTION_GENERATE, $arguments, self::GROUP );
			}
			
			return;
		}
		
		if ( \wp_next_scheduled( self::ACTION_GENERATE, $arguments ) === false ) {
			\wp_schedule_single_event( \time(), self::ACTION_GENERATE, $arguments );
		}
	}
	
	/**
	 * Bump the design version and regenerate all supported posts.
	 *
	 * Hooked to the brand option update; can also be called directly,
	 * e.g. from a settings screen.
	 */
	public function handle_design_change(): void {
		Design::bump_design_version();
		$this->schedule_bulk_regeneration();
		$this->enqueue_action( self::ACTION_CONTEXTS, [] );
		
		foreach ( Context::get_enabled_taxonomies() as $taxonomy ) {
			$this->enqueue_action( self::ACTION_BULK_TERMS, [ $taxonomy, 0 ] );
		}
	}
	
	/**
	 * Schedule a network-wide regeneration of all sites (multisite).
	 *
	 * Runs as a chained background job so large networks never hit the
	 * request time limit of a single admin request.
	 */
	public function schedule_network_regeneration(): void {
		if ( ! \is_multisite() ) {
			return;
		}
		
		$this->enqueue_action( self::ACTION_NETWORK_BULK, [ 0 ] );
	}
	
	/**
	 * Process one batch of sites during a network regeneration.
	 *
	 * Keyset pagination over blog IDs, same rationale as the term
	 * batches: sites added or removed mid-run must not shift windows.
	 *
	 * @param	int	$last_site_id Process sites with an ID greater than this
	 */
	public function run_network_bulk( int $last_site_id = 0 ): void {
		if ( ! \is_multisite() ) {
			return;
		}
		
		global $wpdb;
		
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$site_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT blog_id FROM {$wpdb->blogs} WHERE blog_id > %d ORDER BY blog_id ASC LIMIT %d",
				$last_site_id,
				self::SITE_BATCH_SIZE
			)
		);
		
		if ( ! \is_array( $site_ids ) || $site_ids === [] ) {
			return;
		}
		
		foreach ( $site_ids as $site_id ) {
			\switch_to_blog( (int) $site_id );
			
			try {
				$this->handle_design_change();
			} finally {
				\restore_current_blog();
			}
		}
		
		if ( \count( $site_ids ) === self::SITE_BATCH_SIZE ) {
			$this->enqueue_action( self::ACTION_NETWORK_BULK, [ (int) \end( $site_ids ) ] );
		}
	}
	
	/**
	 * Regenerate when the active theme was updated and declares support.
	 *
	 * A theme update can change theme-support values or bundled assets
	 * (background image, fonts) without any option hook firing; the
	 * content hash would notice, but nothing would proactively
	 * re-render.
	 *
	 * @param	\WP_Upgrader	$upgrader The upgrader instance
	 * @param	array	$hook_extra Details about the performed update
	 */
	public function handle_theme_update( object $upgrader, array $hook_extra ): void {
		if ( ( $hook_extra['type'] ?? '' ) !== 'theme' || ( $hook_extra['action'] ?? '' ) !== 'update' ) {
			return;
		}
		
		$updated = (array) ( $hook_extra['themes'] ?? [] );
		$active = [ \get_stylesheet(), \get_template() ];
		
		if ( \array_intersect( $updated, $active ) === [] ) {
			return;
		}
		
		if ( \get_theme_support( 'image-socialiser' ) === false ) {
			return;
		}
		
		$this->handle_design_change();
	}
	
	/**
	 * Handle a change of the context settings.
	 *
	 * Deliberately does NOT bump the design version: toggling archive
	 * or special-page generation must never re-render post images.
	 * Context subjects whose template assignment changed regenerate
	 * anyway — their resolved template is part of the content hash.
	 */
	public function handle_context_change(): void {
		$this->enqueue_action( self::ACTION_CONTEXTS, [] );
		
		foreach ( Context::get_enabled_taxonomies() as $taxonomy ) {
			$this->enqueue_action( self::ACTION_BULK_TERMS, [ $taxonomy, 0 ] );
		}
	}
	
	/**
	 * Run a bulk regeneration batch and chain the next one.
	 *
	 * @param	int	$page The batch page, starting at 1
	 */
	public function run_bulk_batch( int $page = 1 ): void {
		$post_ids = \get_posts( [
			'fields' => 'ids',
			'no_found_rows' => true,
			'order' => 'ASC',
			'orderby' => 'ID',
			'paged' => \max( 1, $page ),
			'post_status' => $this->get_supported_statuses(),
			'post_type' => Post_Types::get_supported(),
			'posts_per_page' => self::BULK_BATCH_SIZE,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		] );
		
		foreach ( $post_ids as $post_id ) {
			\update_post_meta( (int) $post_id, Generator::META_STATUS, Generator::STATUS_PENDING );
			$this->enqueue( (int) $post_id );
		}
		
		if ( \count( $post_ids ) === self::BULK_BATCH_SIZE ) {
			$this->schedule_bulk_regeneration( $page + 1 );
		}
	}
	
	/**
	 * Generate the image inline while the save request is running.
	 *
	 * Opt-in via the synchronous mode option: the post's image is
	 * rendered before the publish/update request returns, so anything
	 * fetching the page right after publish (social auto-posters,
	 * Bluesky's card service, crawlers) sees the real image instead
	 * of the fallback. The queued job from schedule_on_save() stays
	 * in place as a safety net and retry path: if the inline render
	 * is skipped (time budget) or fails, the queue finishes the work;
	 * if the inline render succeeds, the job becomes a no-op.
	 *
	 * @param	int	$post_id The post ID
	 * @param	\WP_Post	$post The post object
	 */
	public function maybe_generate_synchronously( int $post_id, WP_Post $post ): void {
		if ( ! (bool) \get_option( self::OPTION_SYNC, false ) ) {
			return;
		}
		
		if ( \wp_is_post_revision( $post_id ) !== false || \wp_is_post_autosave( $post_id ) !== false ) {
			return;
		}
		
		if ( ! \in_array( $post->post_status, $this->get_supported_statuses(), true ) ) {
			return;
		}
		
		if ( ! Post_Types::is_supported( $post->post_type ) ) {
			return;
		}
		
		$generator = new Generator();
		
		if ( ! $generator->needs_generation( $post ) ) {
			return;
		}
		
		// a render on a slow host must never wedge the save request;
		// with too little execution time left, the queue takes over
		if ( ! $this->has_time_budget() ) {
			return;
		}
		
		// failure needs no handling here: the Generator records the
		// error state and the queued job retries with backoff
		$generator->generate( $post_id );
	}
	
	/**
	 * Run a single generation job and retry on failure with backoff.
	 *
	 * @param	int	$post_id The post ID
	 * @param	int	$attempt The current attempt, starting at 1
	 */
	public function run_generation( int $post_id, int $attempt = 1 ): void {
		$generator = new Generator();
		$subject = Subject::from_post( $post_id );
		
		// a synchronous render may already have produced the image;
		// skip instead of re-verifying so the ready actions do not
		// fire a second time for the same save
		if (
			$subject->get_state( Generator::META_STATUS ) === Generator::STATUS_READY
			&& ! $generator->needs_generation_subject( $subject )
		) {
			return;
		}
		
		if ( $generator->generate( $post_id ) ) {
			return;
		}
		
		if ( $attempt >= self::MAX_ATTEMPTS ) {
			return;
		}
		
		$this->schedule_retry( $post_id, $attempt + 1 );
	}
	
	/**
	 * Check whether enough request time remains for an inline render.
	 *
	 * @return	bool Whether a synchronous render may be attempted
	 */
	private function has_time_budget(): bool {
		$limit = (int) \ini_get( 'max_execution_time' );
		
		if ( $limit <= 0 ) {
			return true;
		}
		
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- a float cast of a server-set timestamp
		$started = (float) ( $_SERVER['REQUEST_TIME_FLOAT'] ?? \microtime( true ) );
		
		return $limit - ( \microtime( true ) - $started ) >= self::SYNC_TIME_BUDGET;
	}
	
	/**
	 * Schedule a bulk regeneration batch.
	 *
	 * @param	int	$page The batch page, starting at 1
	 */
	public function schedule_bulk_regeneration( int $page = 1 ): void {
		$arguments = [ $page ];
		
		if ( $this->is_action_scheduler_available() ) {
			if ( ! \as_has_scheduled_action( self::ACTION_BULK, $arguments, self::GROUP ) ) {
				\as_enqueue_async_action( self::ACTION_BULK, $arguments, self::GROUP );
			}
			
			return;
		}
		
		if ( \wp_next_scheduled( self::ACTION_BULK, $arguments ) === false ) {
			\wp_schedule_single_event( \time(), self::ACTION_BULK, $arguments );
		}
	}
	
	/**
	 * Schedule a generation job for a saved post if needed.
	 *
	 * Covers featured-image and custom-title changes as well, since the
	 * block editor saves them with the post via REST.
	 *
	 * @param	int	$post_id The post ID
	 * @param	\WP_Post	$post The post object
	 */
	public function schedule_on_save( int $post_id, WP_Post $post ): void {
		if ( \wp_is_post_revision( $post_id ) !== false || \wp_is_post_autosave( $post_id ) !== false ) {
			return;
		}
		
		if ( ! \in_array( $post->post_status, $this->get_supported_statuses(), true ) ) {
			return;
		}
		
		if ( ! Post_Types::is_supported( $post->post_type ) ) {
			return;
		}
		
		if ( ! ( new Generator() )->needs_generation( $post ) ) {
			return;
		}
		
		\update_post_meta( $post_id, Generator::META_STATUS, Generator::STATUS_PENDING );
		$this->enqueue( $post_id );
	}
	
	/**
	 * Get the post statuses that trigger generation.
	 *
	 * @return	string[] The supported post statuses
	 */
	private function get_supported_statuses(): array {
		/**
		 * Filter the post statuses that trigger image generation.
		 *
		 * @param	string[]	$statuses The supported post statuses
		 */
		return (array) \apply_filters(
			'image_socialiser_supported_post_statuses',
			[ 'publish', 'future' ]
		);
	}
	
	/**
	 * Check whether Action Scheduler is loaded.
	 *
	 * @return	bool Whether Action Scheduler functions are available
	 */
	private function is_action_scheduler_available(): bool {
		return \function_exists( 'as_enqueue_async_action' )
			&& \function_exists( 'as_has_scheduled_action' )
			&& \function_exists( 'as_schedule_recurring_action' )
			&& \function_exists( 'as_schedule_single_action' );
	}
	
	/**
	 * Schedule a retry with exponential backoff.
	 *
	 * @param	int	$post_id The post ID
	 * @param	int	$attempt The upcoming attempt number
	 */
	private function schedule_retry( int $post_id, int $attempt ): void {
		$timestamp = \time() + \MINUTE_IN_SECONDS * ( 4 ** ( $attempt - 1 ) );
		$arguments = [ $post_id, $attempt ];
		
		if ( $this->is_action_scheduler_available() ) {
			\as_schedule_single_action( $timestamp, self::ACTION_GENERATE, $arguments, self::GROUP );
			
			return;
		}
		
		\wp_schedule_single_event( $timestamp, self::ACTION_GENERATE, $arguments );
	}
}
