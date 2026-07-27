<?php
declare(strict_types=1);

namespace happyhappy\ImageSocialiser\Template;

use WP_Post;

/**
 * Registry for available templates.
 *
 * Themes and plugins can add designs via the filter below; a per-post
 * template override is stored in post meta.
 *
 * @author	Simon Kraft
 * @license	GPL2
 * @package	happyhappy\ImageSocialiser
 */
final class Template_Registry {
	/**
	 * @var	string Legacy meta key for the retired per-post template
	 * 		override (removed in 1.0.0). Kept only so uninstall can
	 * 		clean up values left on posts by earlier versions.
	 */
	public const string META_TEMPLATE_ID = '_image_socialiser_template_id';
	
	/**
	 * @var	string Option name for the per-post-type default templates.
	 */
	public const string OPTION_CPT_TEMPLATES = 'image_socialiser_cpt_templates';
	
	/**
	 * Get a template by its identifier.
	 *
	 * Falls back to the default template for unknown identifiers.
	 *
	 * @param	string	$template_id The template identifier
	 * @return	\happyhappy\ImageSocialiser\Template\Template_Model The template model
	 */
	public static function get( string $template_id ): Template_Model {
		if ( $template_id === '' || $template_id === 'default' ) {
			$template_id = self::get_default_id();
		}
		
		$templates = self::get_all();
		
		return $templates[ $template_id ]
			?? $templates[ self::get_default_id() ]
			?? Template_Model::get_default();
	}
	
	/**
	 * Resolve the template for any subject.
	 *
	 * Posts use the post chain (meta, per-CPT option); terms the
	 * per-taxonomy setting; archives and special pages the special
	 * pages settings.
	 *
	 * @param	\happyhappy\ImageSocialiser\Generation\Subject	$subject The subject
	 * @return	Template_Model The resolved template
	 */
	public static function resolve_for_subject( \happyhappy\ImageSocialiser\Generation\Subject $subject ): Template_Model {
		if ( $subject->kind === 'post' ) {
			$post = \get_post( $subject->id );
			
			if ( $post instanceof \WP_Post ) {
				return self::resolve_for_post( $post );
			}
			
			return self::get( 'default' );
		}
		
		if ( $subject->kind === 'term' ) {
			$term = \get_term( $subject->id );
			$taxonomy = $term instanceof \WP_Term ? $term->taxonomy : '';
			
			return self::get( \happyhappy\ImageSocialiser\Generation\Context::get_taxonomy_template( $taxonomy ) );
		}
		
		return self::get( \happyhappy\ImageSocialiser\Generation\Context::get_special_template( $subject->kind ) );
	}
	
	/**
	 * Get the identifier of the site-wide default template.
	 *
	 * The stored value 'default' (and the empty value) alias this
	 * identifier, so existing installs keep working unchanged.
	 *
	 * @return	string The default template identifier
	 */
	public static function get_default_id(): string {
		/**
		 * Filter the site-wide default template identifier.
		 *
		 * Theme support (via its 'template' key) and site settings hook
		 * in here.
		 *
		 * @param	string	$template_id The default template identifier
		 */
		return (string) \apply_filters( 'image_socialiser_default_template_id', 'editorial' );
	}
	
	/**
	 * Get all registered templates.
	 *
	 * @return	array<string, \happyhappy\ImageSocialiser\Template\Template_Model> Templates by identifier
	 */
	public static function get_all(): array {
		/**
		 * Filter the registered templates.
		 *
		 * @param	array<string, array>	$templates Template definitions as arrays, keyed by identifier
		 */
		$definitions = (array) \apply_filters(
			'image_socialiser_templates',
			Built_In_Templates::get_all()
		);
		$templates = [];
		
		foreach ( $definitions as $template_id => $definition ) {
			if ( ! \is_array( $definition ) ) {
				continue;
			}
			
			$definition['id'] = (string) $template_id;
			$templates[ (string) $template_id ] = Template_Model::from_array( $definition );
		}
		
		return $templates;
	}
	
	/**
	 * Resolve the template to use for a post.
	 *
	 * @param	\WP_Post	$post The post
	 * @return	\happyhappy\ImageSocialiser\Template\Template_Model The template model
	 */
	public static function resolve_for_post( WP_Post $post ): Template_Model {
		$cpt_templates = \get_option( self::OPTION_CPT_TEMPLATES, [] );
		$template_id = \is_array( $cpt_templates )
			? (string) ( $cpt_templates[ $post->post_type ] ?? '' )
			: '';
		
		if ( $template_id === '' ) {
			$template_id = 'default';
		}
		
		/**
		 * Filter the template identifier used for a post.
		 *
		 * @param	string	$template_id The template identifier
		 * @param	\WP_Post	$post The current post
		 */
		$template_id = (string) \apply_filters(
			'image_socialiser_template_for_post',
			$template_id,
			$post
		);
		
		return self::get( $template_id );
	}
}
