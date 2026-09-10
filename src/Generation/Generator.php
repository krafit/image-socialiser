<?php
declare(strict_types=1);

namespace happyhappy\ImageSocialiser\Generation;

use happyhappy\ImageSocialiser\Rendering\Output_Format;
use happyhappy\ImageSocialiser\Rendering\Renderer_Factory;
use happyhappy\ImageSocialiser\Rendering\Rendering_Exception;
use happyhappy\ImageSocialiser\Template\Binding;
use happyhappy\ImageSocialiser\Template\Template_Registry;
use WP_Post;

// prevent direct file access
if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates the Open Graph image for a post.
 *
 * Resolves the template, binds the dynamic data, computes the content
 * hash, renders via the best available renderer and stores the result
 * plus status in post meta.
 *
 * @author	Simon Kraft
 * @license	GPL2
 * @package	happyhappy\ImageSocialiser
 */
final class Generator {
	/**
	 * @var	string Meta key for the last generation error message.
	 */
	public const string META_ERROR = '_image_socialiser_error';
	
	/**
	 * @var	string Meta key for the output format of the generated image.
	 *
	 * 		Stored so the front-end resolver can build the filename and
	 * 		MIME type without resolving the template — the crawler path
	 * 		must stay a plain meta read.
	 */
	public const string META_FORMAT = '_image_socialiser_format';
	
	/**
	 * @var	string Meta key for the content hash of the generated image.
	 */
	public const string META_HASH = '_image_socialiser_hash';
	
	/**
	 * @var	string Meta key for the generation status.
	 */
	public const string META_STATUS = '_image_socialiser_status';
	
	/**
	 * @var	string Generation failed after all retries.
	 */
	public const string STATUS_FAILED = 'failed';
	
	/**
	 * @var	string Generation is queued but not finished yet.
	 */
	public const string STATUS_PENDING = 'pending';
	
	/**
	 * @var	string A generated image is available.
	 */
	public const string STATUS_READY = 'ready';
	
	/**
	 * Generate the image for a post.
	 *
	 * @param	int	$post_id The post ID
	 * @return	bool Whether generation succeeded
	 */
	public function generate( int $post_id ): bool {
		return $this->generate_subject( Subject::from_post( $post_id ) );
	}
	
	/**
	 * Generate the image for any subject.
	 *
	 * @param	Subject	$subject The subject
	 * @return	bool Whether generation succeeded
	 */
	public function generate_subject( Subject $subject ): bool {
		if ( ! $subject->is_enabled() ) {
			return false;
		}
		
		$renderer = Renderer_Factory::create();
		
		if ( $renderer === null ) {
			$this->set_failed( $subject, 'No image renderer is available.' );
			
			return false;
		}
		
		$model = Template_Registry::resolve_for_subject( $subject );
		$binding = new Binding( $subject );
		$format = Output_Format::resolve( $model, $binding );
		$storage = new Storage();
		$hash = $storage->get_hash_for( $subject, $model, $renderer->get_id(), $format );
		$filename = $storage->get_filename_for( $subject, $hash, $format );
		
		if ( \file_exists( $storage->get_directory()['path'] . '/' . $filename ) ) {
			$this->set_ready( $subject, $hash, $storage, $format );
			$storage->delete_stale_for( $subject, $filename );
			
			return true;
		}
		
		try {
			$bytes = $renderer->render( $model, $binding, $format );
		}
		catch ( Rendering_Exception $exception ) {
			$this->set_failed( $subject, $exception->getMessage() );
			
			return false;
		}
		
		$path = $storage->save_for( $subject, $hash, $bytes, $format );
		
		if ( $path === '' ) {
			$this->set_failed( $subject, 'Could not write the image file.' );
			
			return false;
		}
		
		$this->set_ready( $subject, $hash, $storage, $format );
		
		return true;
	}
	
	/**
	 * Check whether a post needs (re)generation.
	 *
	 * @param	\WP_Post	$post The post
	 * @return	bool Whether the current output is outdated or missing
	 */
	public function needs_generation( WP_Post $post ): bool {
		return $this->needs_generation_subject( Subject::from_post( $post->ID ) );
	}
	
	/**
	 * Check whether a subject needs (re)generation.
	 *
	 * @param	Subject	$subject The subject
	 * @return	bool Whether the current output is outdated or missing
	 */
	public function needs_generation_subject( Subject $subject ): bool {
		$renderer = Renderer_Factory::create();
		
		if ( $renderer === null ) {
			return false;
		}
		
		if ( $subject->get_state( self::META_STATUS ) !== self::STATUS_READY ) {
			return true;
		}
		
		$model = Template_Registry::resolve_for_subject( $subject );
		$format = Output_Format::resolve( $model, new Binding( $subject ) );
		$storage = new Storage();
		$hash = $storage->get_hash_for( $subject, $model, $renderer->get_id(), $format );
		
		if ( $hash !== $subject->get_state( self::META_HASH ) ) {
			return true;
		}
		
		return ! \file_exists(
			$storage->get_directory()['path'] . '/'
				. $storage->get_filename_for( $subject, $hash, $format )
		);
	}
	
	/**
	 * Mark generation as failed for a post.
	 *
	 * @param	int	$post_id The post ID
	 * @param	string	$message The error message
	 */
	private function set_failed( Subject $subject, string $message ): void {
		$subject->set_state( self::META_STATUS, self::STATUS_FAILED );
		$subject->set_state( self::META_ERROR, $message );
	}
	
	/**
	 * Mark a generated image as ready for a subject.
	 *
	 * This is the "ready" signal for external automations: both
	 * actions fire unconditionally whenever an image finishes
	 * generating — in queued and in synchronous mode alike — and
	 * carry the final, publicly reachable image URL. Social-posting
	 * integrations can hook them to send their post only once the
	 * image exists.
	 *
	 * @param	Subject	$subject The subject
	 * @param	string	$hash The content hash
	 * @param	Storage	$storage The storage used for the image
	 */
	private function set_ready(
		Subject $subject,
		string $hash,
		Storage $storage,
		string $format = Output_Format::PNG
	): void {
		$subject->set_state( self::META_HASH, $hash );
		$subject->set_state( self::META_FORMAT, Output_Format::normalize( $format ) );
		$subject->set_state( self::META_STATUS, self::STATUS_READY );
		$subject->unset_state( self::META_ERROR );
		
		$url = $storage->get_directory()['url'] . '/'
			. $storage->get_filename_for( $subject, $hash, $format );
		
		if ( $subject->kind === 'post' ) {
			/**
			 * Fires after an Open Graph image has been generated for a post.
			 *
			 * The image file is written and publicly reachable at
			 * `$url` when this fires, making it a reliable gate for
			 * social auto-posting.
			 *
			 * @since	1.0.0	The `$url` parameter was added.
			 *
			 * @param	int	$post_id The post the image was generated for
			 * @param	string	$hash The content hash of the generated image
			 * @param	string	$url The public URL of the generated image
			 */
			\do_action( 'image_socialiser_generated', $subject->id, $hash, $url );
		}
		
		/**
		 * Fires after an Open Graph image has been generated for any subject.
		 *
		 * The image file is written and publicly reachable at `$url`
		 * when this fires, making it a reliable gate for social
		 * auto-posting.
		 *
		 * @since	1.0.0	The `$url` parameter was added.
		 *
		 * @param	Subject	$subject The subject
		 * @param	string	$hash The content hash of the generated image
		 * @param	string	$url The public URL of the generated image
		 */
		\do_action( 'image_socialiser_generated_subject', $subject, $hash, $url );
	}
}
