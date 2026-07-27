/**
 * Image Socialiser — settings screen media pickers.
 *
 * @package happyhappy\ImageSocialiser
 */
( function () {
	'use strict';
	
	if ( ! window.wp || ! window.wp.media ) {
		return;
	}
	
	document.addEventListener( 'click', function ( event ) {
		var select = event.target.closest( '.image-socialiser-media-select' );
		var remove = event.target.closest( '.image-socialiser-media-remove' );
		var field;
		
		if ( select ) {
			event.preventDefault();
			field = select.closest( '.image-socialiser-media-field' );
			
			var frame = window.wp.media( {
				library: { type: 'image' },
				multiple: false,
			} );
			
			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				var preview = field.querySelector( '.image-socialiser-media-preview' );
				var url = attachment.sizes && attachment.sizes.thumbnail
					? attachment.sizes.thumbnail.url
					: attachment.url;
				
				field.querySelector( '.image-socialiser-media-id' ).value = attachment.id;
				preview.src = url;
				preview.style.display = 'block';
				field.querySelector( '.image-socialiser-media-remove' ).style.display = 'inline';
			} );
			frame.open();
		}
		
		if ( remove ) {
			event.preventDefault();
			field = remove.closest( '.image-socialiser-media-field' );
			field.querySelector( '.image-socialiser-media-id' ).value = '0';
			field.querySelector( '.image-socialiser-media-preview' ).style.display = 'none';
			remove.style.display = 'none';
		}
	} );
}() );

( function () {
	'use strict';
	
	// Design Center: show the override fieldset of the selected design
	var overrideSelect = document.getElementById( 'image-socialiser-override-template' );
	
	if ( overrideSelect ) {
		var showFieldset = function () {
			document.querySelectorAll( '.image-socialiser-override-fieldset' ).forEach( function ( fieldset ) {
				fieldset.hidden = fieldset.dataset.template !== overrideSelect.value;
			} );
		};
		
		overrideSelect.addEventListener( 'change', showFieldset );
		showFieldset();
	}
	
	// optional colors + per-design colors: the checkbox enables the input
	document.addEventListener( 'change', function ( event ) {
		var toggle = event.target;
		var inputId = toggle.dataset && ( toggle.dataset.imgsocColorAuto || toggle.dataset.imgsocOverrideToggle );
		
		if ( ! inputId ) {
			return;
		}
		
		var input = document.getElementById( inputId );
		
		if ( ! input ) {
			return;
		}
		
		if ( toggle.dataset.imgsocColorAuto ) {
			// "same as muted": checked disables the custom color
			input.disabled = toggle.checked;
		} else {
			// override toggle: checked enables the color input
			input.disabled = ! toggle.checked;
			
			if ( toggle.checked ) {
				// fire an input event so the live preview picks it up
				input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			}
		}
	} );
}() );
