<?php
declare(strict_types=1);

namespace happyhappy\ImageSocialiser\Cli;

use happyhappy\ImageSocialiser\Generation\Context;
use happyhappy\ImageSocialiser\Generation\Generator;
use happyhappy\ImageSocialiser\Generation\Scheduler;
use happyhappy\ImageSocialiser\Generation\Post_Types;
use happyhappy\ImageSocialiser\Generation\Storage;
use happyhappy\ImageSocialiser\Rendering\Renderer_Factory;
use happyhappy\ImageSocialiser\Rendering\Rendering_Exception;
use happyhappy\ImageSocialiser\Template\Binding;
use happyhappy\ImageSocialiser\Template\Template_Registry;
use WP_CLI;
use WP_CLI\Utils;
use WP_Post;

/**
 * WP-CLI commands.
 *
 * @author	Simon Kraft
 * @license	GPL2
 * @package	happyhappy\ImageSocialiser
 */
final class Commands {
	/**
	 * Regenerate Open Graph images synchronously.
	 *
	 * ## OPTIONS
	 *
	 * [<post_id>...]
	 * : One or more post IDs to regenerate.
	 *
	 * [--all]
	 * : Regenerate all posts of the supported post types, all terms of
	 * enabled taxonomies, and all archive/special page images.
	 *
	 * [--post_type=<post_type>]
	 * : Limit --all to a single post type (posts only, skips contexts).
	 *
	 * [--taxonomy=<taxonomy>]
	 * : Regenerate all terms of one enabled taxonomy (no posts).
	 *
	 * [--contexts]
	 * : Regenerate the archive and special page images (no posts).
	 *
	 * ## EXAMPLES
	 *
	 *     wp image-socialiser regenerate 42 43
	 *     wp image-socialiser regenerate --all
	 *     wp image-socialiser regenerate --all --post_type=page
	 *     wp image-socialiser regenerate --taxonomy=category
	 *     wp image-socialiser regenerate --contexts
	 *
	 * @param	array	$arguments Positional arguments
	 * @param	array	$assoc_arguments Associative arguments
	 */
	public function regenerate( array $arguments, array $assoc_arguments ): void {
		$post_ids = \array_map( '\intval', $arguments );
		$run_all = isset( $assoc_arguments['all'] );
		$taxonomy = (string) ( $assoc_arguments['taxonomy'] ?? '' );
		$run_contexts = isset( $assoc_arguments['contexts'] );
		
		if ( $taxonomy !== '' || ( $run_all && $taxonomy === '' ) ) {
			$this->regenerate_terms( $run_all ? '' : $taxonomy );
		}
		
		if ( $run_contexts || $run_all ) {
			( new Scheduler() )->run_context_generation();
			WP_CLI::log( 'Archive and special page images regenerated.' );
		}
		
		if ( $run_all ) {
			$post_ids = $this->get_all_post_ids( (string) ( $assoc_arguments['post_type'] ?? '' ) );
		}
		elseif ( $taxonomy !== '' || $run_contexts ) {
			if ( empty( $post_ids ) ) {
				return;
			}
		}
		
		if ( empty( $post_ids ) ) {
			WP_CLI::error( 'No posts found. Pass post IDs or use --all.' );
		}
		
		$generator = new Generator();
		$failed = 0;
		$progress = Utils\make_progress_bar( 'Generating images', \count( $post_ids ) );
		
		foreach ( $post_ids as $post_id ) {
			if ( ! $generator->generate( $post_id ) ) {
				$failed++;
			}
			
			$progress->tick();
		}
		
		$progress->finish();
		
		if ( $failed > 0 ) {
			WP_CLI::warning(
				\sprintf(
					'%1$d of %2$d images could not be generated. Check the %3$s post meta for details.',
					$failed,
					\count( $post_ids ),
					Generator::META_ERROR
				)
			);
		}
		
		WP_CLI::success(
			\sprintf( 'Generated %d images.', \count( $post_ids ) - $failed )
		);
	}
	
	/**
	 * Regenerate all terms of enabled taxonomies (or one of them).
	 *
	 * @param	string	$taxonomy A single taxonomy, or empty for all enabled ones
	 */
	private function regenerate_terms( string $taxonomy ): void {
		$taxonomies = $taxonomy !== '' ? [ $taxonomy ] : Context::get_enabled_taxonomies();
		$scheduler = new Scheduler();
		
		foreach ( $taxonomies as $single_taxonomy ) {
			if ( ! Context::is_taxonomy_enabled( $single_taxonomy ) ) {
				WP_CLI::warning(
					\sprintf( 'Taxonomy "%s" is not enabled for image generation — skipped.', $single_taxonomy )
				);
				
				continue;
			}
			
			// run the keyset batches synchronously; run_term_batch()
			// re-enqueues follow-ups, which Action Scheduler picks up
			$scheduler->run_term_batch( $single_taxonomy, 0 );
			WP_CLI::log( \sprintf( 'Term images for "%s" regenerated (follow-up batches queued).', $single_taxonomy ) );
		}
	}
	
	/**
	 * Render the Open Graph image for a single post and print the result.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : The ID of the post to render the image for.
	 *
	 * ## EXAMPLES
	 *
	 *     wp image-socialiser render 42
	 *
	 * @param	array	$arguments Positional arguments
	 * @param	array	$assoc_arguments Associative arguments
	 */
	public function render( array $arguments, array $assoc_arguments ): void {
		$post = \get_post( (int) ( $arguments[0] ?? 0 ) );
		
		if ( ! $post instanceof WP_Post ) {
			WP_CLI::error( 'Invalid post ID.' );
		}
		
		$renderer = Renderer_Factory::create();
		
		if ( $renderer === null ) {
			WP_CLI::error( 'No image renderer is available (Imagick/GD missing).' );
		}
		
		$model = Template_Registry::resolve_for_post( $post );
		$binding = new Binding( $post );
		
		try {
			$bytes = $renderer->render( $model, $binding );
		}
		catch ( Rendering_Exception $exception ) {
			WP_CLI::error( $exception->getMessage() );
		}
		
		$storage = new Storage();
		$hash = $storage->get_hash( $post, $model, $renderer->get_id() );
		$path = $storage->save( $post->ID, $hash, $bytes );
		
		if ( $path === '' ) {
			WP_CLI::error( 'Could not write the image file.' );
		}
		
		\update_post_meta( $post->ID, Generator::META_HASH, $hash );
		\update_post_meta( $post->ID, Generator::META_STATUS, Generator::STATUS_READY );
		\delete_post_meta( $post->ID, Generator::META_ERROR );
		WP_CLI::success(
			\sprintf(
				'Generated %1$s (%2$s) via %3$s.',
				$path,
				$storage->get_url( $post->ID, $hash ),
				$renderer->get_id()
			)
		);
	}
	
	/**
	 * Scaffold a starter design pack.
	 *
	 * Writes a directory containing a valid, token-driven design.json
	 * plus a README with the registration snippet. See the Creating
	 * designs guide in the wiki for the full format reference:
	 * https://github.com/krafit/image-socialiser/wiki/Creating-designs
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The design id — lowercase, namespaced with your theme/plugin
	 * slug, e.g. "acme-poster".
	 *
	 * [--dir=<path>]
	 * : Target directory for the pack. Default: ./<id>/
	 *
	 * ## EXAMPLES
	 *
	 *     wp image-socialiser scaffold-design acme-poster
	 *     wp image-socialiser scaffold-design acme-poster --dir=wp-content/themes/acme/social-designs/poster
	 *
	 * @subcommand	scaffold-design
	 *
	 * @param	array	$arguments Positional arguments
	 * @param	array	$assoc_arguments Associative arguments
	 */
	public function scaffold_design( array $arguments, array $assoc_arguments ): void {
		$design_id = (string) ( $arguments[0] ?? '' );
		
		if ( \sanitize_key( $design_id ) !== $design_id
			|| \preg_match( '/^[a-z0-9]+(-[a-z0-9]+)+$/', $design_id ) !== 1
		) {
			WP_CLI::error( 'The design id must be lowercase and namespaced, e.g. "acme-poster".' );
		}
		
		$directory = (string) ( $assoc_arguments['dir'] ?? \getcwd() . '/' . $design_id );
		
		if ( \file_exists( $directory . '/design.json' ) ) {
			WP_CLI::error( \sprintf( '%s/design.json already exists.', $directory ) );
		}
		
		if ( ! \wp_mkdir_p( $directory ) ) {
			WP_CLI::error( \sprintf( 'Could not create the directory %s.', $directory ) );
		}
		
		$manifest = [
			'$schema' => 'https://simon.blog/image-socialiser/design-schema/v1.json',
			'id' => $design_id,
			'label' => \ucwords( \str_replace( '-', ' ', $design_id ) ),
			'version' => 1,
			'supports' => [],
			'layers' => [
				[
					'type' => 'background',
					'fill' => [
						'kind' => 'gradient',
						'stops' => [
							[
								'color' => 'token:background_from',
								'offset' => 0,
							],
							[
								'color' => 'token:background_to',
								'offset' => 1,
							],
						],
					],
				],
				[
					'type' => 'text',
					'source' => 'title',
					'font' => 'token:heading_font',
					'box' => [
						'x' => 80,
						'y' => 180,
						'w' => 1040,
						'h' => 240,
					],
					'align' => 'left',
					'maxLines' => 3,
					'lineHeight' => 1.15,
					'size' => [
						'min' => 48,
						'max' => 84,
					],
					'color' => 'token:text_color',
				],
				[
					'type' => 'text',
					'source' => 'subtitle',
					'font' => 'token:body_font',
					'box' => [
						'x' => 80,
						'y' => 446,
						'w' => 1040,
						'h' => 40,
					],
					'align' => 'left',
					'maxLines' => 1,
					'size' => 30,
					'color' => 'token:muted_color',
				],
				[
					'type' => 'text',
					'source' => 'site_name',
					'font' => 'token:body_font',
					'box' => [
						'x' => 80,
						'y' => 80,
						'w' => 720,
						'h' => 40,
					],
					'align' => 'left',
					'maxLines' => 1,
					'size' => 28,
					'color' => 'token:muted_color',
				],
			],
		];
		$readme = "# " . \ucwords( \str_replace( '-', ' ', $design_id ) ) . " — Image Socialiser design pack\n\n"
			. "Register the pack from your theme or plugin:\n\n"
			. "```php\n"
			. "add_action( 'image_socialiser_register_designs', static function (): void {\n"
			. "\timage_socialiser_register_design( __DIR__ . '/" . \basename( $directory ) . "/design.json' );\n"
			. "} );\n"
			. "```\n\n"
			. "Add pack fonts (`\"fonts\": { \"id\": \"fonts/File.ttf\" }`) and background\n"
			. "assets (`\"assets\": { \"name\": \"assets/file.png\" }`, referenced by an image\n"
			. "layer with `\"source\": \"template_asset\", \"asset\": \"name\"`) next to the\n"
			. "manifest — files must live inside this directory. Colors and fonts may be\n"
			. "literals or token references (token:text_color, token:heading_font, …).\n\n"
			. "Full format reference:\n"
			. "https://github.com/krafit/image-socialiser/wiki/Creating-designs\n";
		$written = \file_put_contents(
			$directory . '/design.json',
			(string) \wp_json_encode( $manifest, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES ) . "\n"
		) !== false
			&& \file_put_contents( $directory . '/README.md', $readme ) !== false;
		
		if ( ! $written ) {
			WP_CLI::error( \sprintf( 'Could not write into %s.', $directory ) );
		}
		
		WP_CLI::success( \sprintf( 'Design pack "%s" scaffolded in %s.', $design_id, $directory ) );
	}
	
	/**
	 * Delete orphaned generated image files.
	 *
	 * ## EXAMPLES
	 *
	 *     wp image-socialiser sweep
	 *
	 * @param	array	$arguments Positional arguments
	 * @param	array	$assoc_arguments Associative arguments
	 */
	public function sweep( array $arguments, array $assoc_arguments ): void {
		WP_CLI::success(
			\sprintf( 'Deleted %d orphaned files.', ( new Storage() )->delete_orphans() )
		);
	}
	
	/**
	 * Get all post IDs of the supported post types.
	 *
	 * @param	string	$post_type Optional single post type to limit to
	 * @return	int[] The post IDs
	 */
	private function get_all_post_ids( string $post_type = '' ): array {
		$post_types = Post_Types::get_supported();
		
		if ( $post_type !== '' ) {
			if ( ! \in_array( $post_type, $post_types, true ) ) {
				WP_CLI::error(
					\sprintf( 'Post type "%s" is not supported.', $post_type )
				);
			}
			
			$post_types = [ $post_type ];
		}
		
		$post_ids = [];
		$page = 1;
		
		do {
			$batch = \get_posts( [
				'fields' => 'ids',
				'no_found_rows' => true,
				'order' => 'ASC',
				'orderby' => 'ID',
				'paged' => $page,
				'post_status' => [ 'publish', 'future' ],
				'post_type' => $post_types,
				'posts_per_page' => 100,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			] );
			$post_ids = \array_merge( $post_ids, \array_map( '\intval', $batch ) );
			$page++;
		} while ( \count( $batch ) === 100 );
		
		return $post_ids;
	}
}
