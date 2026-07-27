<?php
declare(strict_types=1);

namespace happyhappy\ImageSocialiser\Editor;

use happyhappy\ImageSocialiser\Generation\Generator;
use happyhappy\ImageSocialiser\Generation\Post_Types;
use happyhappy\ImageSocialiser\Generation\Storage;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST endpoints for the block editor panel.
 *
 * The regenerate endpoint runs synchronously instead of enqueuing a
 * job (a deliberate deviation from the spec): it is an explicit,
 * capability-gated user action, a single render is cheap, and instant
 * feedback beats polling through WP-Cron latency. The background queue
 * remains in place for the save_post path.
 *
 * @author	Simon Kraft
 * @license	GPL2
 * @package	happyhappy\ImageSocialiser
 */
final class Rest_Controller {
	/**
	 * @var	string The REST route namespace.
	 */
	public const string ROUTE_NAMESPACE = 'image-socialiser/v1';
	
	/**
	 * Initialize the REST routes.
	 */
	public static function init(): void {
		\add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
	}
	
	/**
	 * Get the status payload for a post.
	 *
	 * @param	int	$post_id The post ID
	 * @return	array{error: string, hash: string, status: string, url: string} The status payload
	 */
	public static function get_status_payload( int $post_id ): array {
		$status = (string) \get_post_meta( $post_id, Generator::META_STATUS, true );
		$hash = (string) \get_post_meta( $post_id, Generator::META_HASH, true );
		$url = '';
		
		if ( $status === Generator::STATUS_READY && $hash !== '' ) {
			$url = ( new Storage() )->get_url( $post_id, $hash );
		}
		
		return [
			'error' => (string) \get_post_meta( $post_id, Generator::META_ERROR, true ),
			'hash' => $hash,
			'status' => $status,
			'url' => $url,
		];
	}
	
	/**
	 * Check whether the current user may manage a post's image.
	 *
	 * @param	\WP_REST_Request	$request The REST request
	 * @return	bool|\WP_Error Whether access is granted
	 */
	public static function permission_callback( WP_REST_Request $request ): bool|WP_Error {
		$post = \get_post( (int) $request['id'] );
		
		if ( ! $post instanceof WP_Post || ! Post_Types::is_supported( $post->post_type ) ) {
			return new WP_Error(
				'image_socialiser_invalid_post',
				\__( 'Invalid post.', 'image-socialiser' ),
				[ 'status' => 404 ]
			);
		}
		
		return \current_user_can( 'edit_post', $post->ID );
	}
	
	/**
	 * Register the REST routes.
	 */
	public static function register_routes(): void {
		$id_argument = [
			'id' => [
				'required' => true,
				'sanitize_callback' => 'absint',
				'type' => 'integer',
			],
		];
		
		\register_rest_route(
			self::ROUTE_NAMESPACE,
			'/regenerate/(?P<id>\d+)',
			[
				'args' => $id_argument,
				'callback' => [ self::class, 'run_regenerate' ],
				'methods' => WP_REST_Server::CREATABLE,
				'permission_callback' => [ self::class, 'permission_callback' ],
			]
		);
		\register_rest_route(
			self::ROUTE_NAMESPACE,
			'/status/(?P<id>\d+)',
			[
				'args' => $id_argument,
				'callback' => [ self::class, 'run_status' ],
				'methods' => WP_REST_Server::READABLE,
				'permission_callback' => [ self::class, 'permission_callback' ],
			]
		);
	}
	
	/**
	 * Regenerate the image for a post synchronously.
	 *
	 * @param	\WP_REST_Request	$request The REST request
	 * @return	\WP_REST_Response The status payload response
	 */
	public static function run_regenerate( WP_REST_Request $request ): WP_REST_Response {
		$post_id = (int) $request['id'];
		( new Generator() )->generate( $post_id );
		
		return new WP_REST_Response( self::get_status_payload( $post_id ) );
	}
	
	/**
	 * Get the generation status for a post.
	 *
	 * @param	\WP_REST_Request	$request The REST request
	 * @return	\WP_REST_Response The status payload response
	 */
	public static function run_status( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( self::get_status_payload( (int) $request['id'] ) );
	}
}
