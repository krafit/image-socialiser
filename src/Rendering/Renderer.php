<?php
declare(strict_types=1);

namespace happyhappy\ImageSocialiser\Rendering;

use happyhappy\ImageSocialiser\Template\Binding;
use happyhappy\ImageSocialiser\Template\Template_Model;

/**
 * Interface for image renderers.
 *
 * @author	Simon Kraft
 * @license	GPL2
 * @package	happyhappy\ImageSocialiser
 */
interface Renderer {
	/**
	 * Get the unique renderer identifier.
	 *
	 * Part of the content hash, so switching renderers invalidates
	 * previously generated files.
	 *
	 * @return	string The renderer identifier
	 */
	public function get_id(): string;
	
	/**
	 * Check whether this renderer can run in the current environment.
	 *
	 * @return	bool Whether the renderer is available
	 */
	public function is_available(): bool;
	
	/**
	 * Render a template with bound data to PNG bytes.
	 *
	 * @param	\happyhappy\ImageSocialiser\Template\Template_Model	$model The template to render
	 * @param	\happyhappy\ImageSocialiser\Template\Binding	$data The dynamic data binding
	 * @return	string The rendered PNG image bytes
	 * @throws	\happyhappy\ImageSocialiser\Rendering\Rendering_Exception If rendering fails
	 */
	public function render( Template_Model $model, Binding $data ): string;
}
