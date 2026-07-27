/**
 * Image Socialiser — template gallery on the settings screen.
 *
 * Mounts mini SVG previews of every registered template (rendered with
 * the shared preview module and the site's current design tokens) into
 * the gallery placeholder, decorated with assignment badges. Edits to
 * the brand color inputs update the previews live by swapping the
 * baked color literals; fonts and layout apply after saving.
 *
 * @package happyhappy\ImageSocialiser
 */
( function ( wp ) {
	'use strict';
	
	var data = window.imageSocialiserSettingsPreviews || {};
	var previewModule = window.imageSocialiserPreview;
	var container = document.getElementById( 'image-socialiser-template-gallery' );
	var heroContainer = document.getElementById( 'image-socialiser-hero-preview' );
	
	if ( ! previewModule || ! ( container || heroContainer ) || ! wp.element.createRoot ) {
		return;
	}
	
	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var Preview = previewModule.Preview;
	
	previewModule.loadFonts( data.fonts );
	
	function readColorEdits() {
		var edits = {};
		
		Object.keys( data.liveColors || {} ).forEach( function ( inputId ) {
			var input = document.getElementById( inputId );
			var baked = ( data.liveColors[ inputId ] || '' ).toLowerCase();
			
			if ( ! input || input.disabled || baked === '' ) {
				return;
			}
			
			var value = ( input.value || '' ).toLowerCase();
			
			if ( value !== '' && value !== baked ) {
				edits[ baked ] = value;
			}
		} );
		
		return edits;
	}
	
	function applyColorEdits( value, edits ) {
		if ( typeof value === 'string' ) {
			return edits[ value.toLowerCase() ] || value;
		}
		
		if ( Array.isArray( value ) ) {
			return value.map( function ( item ) {
				return applyColorEdits( item, edits );
			} );
		}
		
		if ( value && typeof value === 'object' ) {
			var result = {};
			
			Object.keys( value ).forEach( function ( key ) {
				result[ key ] = applyColorEdits( value[ key ], edits );
			} );
			
			return result;
		}
		
		return value;
	}
	
	function Gallery() {
		var refresh = useState( 0 );
		var setRefresh = refresh[ 1 ];
		
		// re-render once the preview fonts are loaded
		useEffect( function () {
			return previewModule.onFontsLoaded( function () {
				setRefresh( function ( value ) {
					return value + 1;
				} );
			} );
		}, [] );
		
		// live color preview: re-render on brand color input edits
		useEffect( function () {
			var listener = function ( event ) {
				if ( event.target && ( data.liveColors || {} )[ event.target.id ] !== undefined ) {
					setRefresh( function ( value ) {
						return value + 1;
					} );
				}
			};
			
			document.addEventListener( 'input', listener );
			
			return function () {
				document.removeEventListener( 'input', listener );
			};
		}, [] );
		
		var edits = readColorEdits();
		var hasEdits = Object.keys( edits ).length > 0;
		var templates = data.templates || {};
		var assignments = data.assignments || {};
		var tiles = Object.keys( templates ).map( function ( templateId ) {
			var template = hasEdits
				? applyColorEdits( templates[ templateId ], edits )
				: templates[ templateId ];
			var badges = ( assignments[ templateId ] || [] ).map( function ( label, index ) {
				return el(
					'span',
					{
						className: 'image-socialiser-badge',
						key: 'badge-' + index,
					},
					label
				);
			} );
			
			return el(
				'figure',
				{
					key: templateId,
					style: { flex: '0 1 220px', margin: 0 },
				},
				[
					el( Preview, {
						binding: data.binding || {},
						key: 'mini',
						label: template.label || templateId,
						template: template,
					} ),
					el(
						'figcaption',
						{ key: 'caption', style: { fontSize: '12px', marginTop: '4px', textAlign: 'center' } },
						[ template.label || templateId ].concat( badges )
					),
				]
			);
		} );
		
		return el(
			'div',
			{ style: { display: 'flex', flexWrap: 'wrap', gap: '12px' } },
			tiles
		);
	}
	
	// hero preview (General tab): the default design, full width
	function Hero() {
		var refresh = useState( 0 );
		var setRefresh = refresh[ 1 ];
		
		useEffect( function () {
			return previewModule.onFontsLoaded( function () {
				setRefresh( function ( value ) {
					return value + 1;
				} );
			} );
		}, [] );
		
		var template = ( data.templates || {} )[ data.defaultTemplate ];
		
		if ( ! template ) {
			return null;
		}
		
		return el( Preview, {
			binding: data.binding || {},
			label: template.label || data.defaultTemplate,
			template: template,
		} );
	}
	
	if ( container ) {
		wp.element.createRoot( container ).render( el( Gallery ) );
	}
	
	if ( heroContainer ) {
		wp.element.createRoot( heroContainer ).render( el( Hero ) );
	}
}( window.wp ) );
