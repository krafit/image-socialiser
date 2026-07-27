/**
 * Image Socialiser — shared SVG preview module.
 *
 * Renders the template layer model to SVG in the browser, mirroring
 * the server renderers. Used by the editor panel and the settings
 * template gallery.
 *
 * @package happyhappy\ImageSocialiser
 */
( function ( wp ) {
	'use strict';
	
	var el = wp.element.createElement;
	
	// ---- text measuring & fitting (mirrors PHP Text_Fitter) ---------------
	
	var measureContext = document.createElement( 'canvas' ).getContext( '2d' );
	
	function textWidth( text, fontSize, fontId ) {
		measureContext.font = fontSize + 'px "' + fontId + '", sans-serif';
		
		return measureContext.measureText( text ).width;
	}
	
	function wrapText( text, fontSize, fontId, boxWidth ) {
		var words = text.trim().split( /\s+/ );
		var lines = [];
		var current = '';
		
		words.forEach( function ( word ) {
			if ( word === '' ) {
				return;
			}
			
			var candidate = current === '' ? word : current + ' ' + word;
			
			if ( current === '' || textWidth( candidate, fontSize, fontId ) <= boxWidth ) {
				current = candidate;
				
				return;
			}
			
			lines.push( current );
			current = word;
		} );
		
		if ( current !== '' ) {
			lines.push( current );
		}
		
		return lines;
	}
	
	function ellipsize( line, fontSize, fontId, boxWidth ) {
		var characters = Array.from( line );
		
		while ( characters.length > 0 && textWidth( characters.join( '' ).replace( /\s+$/, '' ) + '…', fontSize, fontId ) > boxWidth ) {
			characters.pop();
		}
		
		return characters.join( '' ).replace( /\s+$/, '' ) + '…';
	}
	
	function fitText( text, fontId, minSize, maxSize, boxWidth, boxHeight, lineHeight, maxLines ) {
		var size;
		var lines;
		var step;
		
		for ( size = maxSize; size >= minSize; size-- ) {
			lines = wrapText( text, size, fontId, boxWidth );
			step = Math.max( 1, Math.round( size * lineHeight ) );
			
			var fitsLines = maxLines === 0 || lines.length <= maxLines;
			var fitsHeight = lines.length * step <= boxHeight;
			var fitsWidth = lines.every( function ( line ) {
				return textWidth( line, size, fontId ) <= boxWidth;
			} );
			
			if ( fitsLines && fitsHeight && fitsWidth ) {
				return { lines: lines, size: size };
			}
		}
		
		size = minSize;
		lines = wrapText( text, size, fontId, boxWidth );
		step = Math.max( 1, Math.round( size * lineHeight ) );
		var allowed = Math.max( 1, Math.floor( boxHeight / step ) );
		
		if ( maxLines > 0 ) {
			allowed = Math.min( allowed, maxLines );
		}
		
		var isCut = lines.length > allowed;
		
		if ( isCut ) {
			lines = lines.slice( 0, allowed );
		}
		
		return {
			lines: lines.map( function ( line, index ) {
				var isLast = index === lines.length - 1;
				
				if ( ( isLast && isCut ) || textWidth( line, size, fontId ) > boxWidth ) {
					return ellipsize( line, size, fontId, boxWidth );
				}
				
				return line;
			} ),
			size: size,
		};
	}
	
	function sizeRange( size ) {
		if ( size && typeof size === 'object' ) {
			var minimum = Math.max( 1, parseInt( size.min, 10 ) || 16 );
			
			return [ minimum, Math.max( minimum, parseInt( size.max, 10 ) || minimum ) ];
		}
		
		var fixed = Math.max( 1, parseInt( size, 10 ) || 32 );
		
		return [ fixed, fixed ];
	}
	
	// ---- font loading ------------------------------------------------------
	
	var fontsLoaded = false;
	var fontListeners = [];
	var fontsRequested = false;
	
	function loadFonts( fonts ) {
		if ( fontsRequested ) {
			return;
		}
		
		fontsRequested = true;
		fonts = fonts || {};
		var promises = Object.keys( fonts ).map( function ( fontId ) {
			var face = new FontFace( fontId, 'url(' + fonts[ fontId ] + ')' );
			
			return face.load().then( function ( loaded ) {
				document.fonts.add( loaded );
			} ).catch( function () {} );
		} );
		
		Promise.all( promises ).then( function () {
			fontsLoaded = true;
			fontListeners.forEach( function ( listener ) {
				listener();
			} );
		} );
	}
	
	function onFontsLoaded( listener ) {
		if ( fontsLoaded ) {
			listener();
			
			return function () {};
		}
		
		fontListeners.push( listener );
		
		return function () {
			fontListeners = fontListeners.filter( function ( registered ) {
				return registered !== listener;
			} );
		};
	}
	
	// ---- SVG preview --------------------------------------------------------
	
	function layerBox( layer, canvas ) {
		var box = layer.box || {};
		
		return {
			h: Math.min( Math.max( 1, parseInt( box.h, 10 ) || canvas.h ), canvas.h ),
			w: Math.min( Math.max( 1, parseInt( box.w, 10 ) || canvas.w ), canvas.w ),
			x: Math.max( 0, parseInt( box.x, 10 ) || 0 ),
			y: Math.max( 0, parseInt( box.y, 10 ) || 0 ),
		};
	}
	
	function backgroundElements( layer, canvas, key ) {
		var fill = layer.fill || {};
		
		if ( fill.kind === 'gradient' ) {
			var stops = Array.isArray( fill.stops ) ? fill.stops : [];
			var from = ( stops[ 0 ] || {} ).color || '#000000';
			var to = ( stops[ stops.length - 1 ] || {} ).color || '#ffffff';
			var isHorizontal = fill.direction === 'horizontal';
			var gradientId = 'imgsoc-gradient-' + key;
			
			return [
				el(
					'defs',
					{ key: key + '-defs' },
					el(
						'linearGradient',
						{
							id: gradientId,
							x1: '0%',
							x2: isHorizontal ? '100%' : '0%',
							y1: '0%',
							y2: isHorizontal ? '0%' : '100%',
						},
						el( 'stop', { offset: '0%', stopColor: from } ),
						el( 'stop', { offset: '100%', stopColor: to } )
					)
				),
				el( 'rect', {
					fill: 'url(#' + gradientId + ')',
					height: canvas.h,
					key: key + '-rect',
					width: canvas.w,
					x: 0,
					y: 0,
				} ),
			];
		}
		
		return [
			el( 'rect', {
				fill: fill.color || '#000000',
				height: canvas.h,
				key: key + '-rect',
				width: canvas.w,
				x: 0,
				y: 0,
			} ),
		];
	}
	
	function imageElement( layer, canvas, binding, key ) {
		var sources = {
			brand_background: binding.backgroundUrl,
			cover_art: binding.coverArtUrl,
			featured: binding.featuredUrl,
			logo: binding.logoUrl,
			template_asset: layer.asset_url || '',
		};
		var url = sources[ layer.source ] || '';
		
		if ( url === '' ) {
			return null;
		}
		
		var box = layerBox( layer, canvas );
		var aspect = 'xMidYMid slice';
		var elements = [];
		
		if ( layer.fit === 'contain' ) {
			if ( layer.align === 'left' ) {
				aspect = 'xMinYMid meet';
			} else if ( layer.align === 'right' ) {
				aspect = 'xMaxYMid meet';
			} else {
				aspect = 'xMidYMid meet';
			}
		}
		
		if ( layer.frame && layer.frame.color ) {
			// offset frame ("sticker"): a solid rect behind the image
			var frameOffset = parseInt( layer.frame.offset, 10 );
			
			if ( isNaN( frameOffset ) ) {
				frameOffset = 12;
			}
			
			elements.push( el( 'rect', {
				fill: layer.frame.color,
				height: box.h,
				key: key + '-frame',
				width: box.w,
				x: box.x + frameOffset,
				y: box.y + frameOffset,
			} ) );
		}
		
		elements.push( el( 'image', {
			height: box.h,
			href: url,
			key: key,
			opacity: layer.opacity === undefined ? 1 : layer.opacity,
			preserveAspectRatio: aspect,
			width: box.w,
			x: box.x,
			y: box.y,
		} ) );
		
		return elements;
	}
	
	function textElements( layer, canvas, binding, key ) {
		var text = ( binding[ layer.source ] || '' ).trim();
		
		if ( text === '' ) {
			return null;
		}
		
		var box = layerBox( layer, canvas );
		var fontId = layer.font || 'inter-regular';
		var range = sizeRange( layer.size );
		var lineHeight = parseFloat( layer.lineHeight ) || 1.2;
		var fitted = fitText( text, fontId, range[ 0 ], range[ 1 ], box.w, box.h, lineHeight, parseInt( layer.maxLines, 10 ) || 0 );
		var step = Math.max( 1, Math.round( fitted.size * lineHeight ) );
		var ascent = fitted.size * 0.97;
		var elements = [];
		
		if ( layer.backing && layer.backing.color ) {
			// content-fitted backing boxes, one per line; contiguous
			// boxes with vertical padding on the first/last line only
			// (mirrors the server renderers)
			var paddingX = Math.max( 0, parseInt( layer.backing.padding_x, 10 ) || ( layer.backing.padding_x === 0 ? 0 : 16 ) );
			var paddingY = Math.max( 0, parseInt( layer.backing.padding_y, 10 ) || ( layer.backing.padding_y === 0 ? 0 : 8 ) );
			var last = fitted.lines.length - 1;
			
			fitted.lines.forEach( function ( line, index ) {
				var lineWidth = textWidth( line, fitted.size, fontId );
				var lineX = box.x;
				
				if ( layer.align === 'center' ) {
					lineX = box.x + ( box.w - lineWidth ) / 2;
				} else if ( layer.align === 'right' ) {
					lineX = box.x + box.w - lineWidth;
				}
				
				lineX = Math.max( box.x, lineX );
				var top = box.y + index * step - ( index === 0 ? paddingY : 0 );
				var bottom = box.y + ( index + 1 ) * step + ( index === last ? paddingY : 0 );
				
				elements.push( el( 'rect', {
					fill: layer.backing.color,
					fillOpacity: layer.backing.opacity === undefined ? 1 : layer.backing.opacity,
					height: bottom - top,
					key: key + '-backing-' + index,
					rx: Math.max( 0, parseInt( layer.backing.radius, 10 ) || 0 ),
					width: lineWidth + 2 * paddingX,
					x: lineX - paddingX,
					y: top,
				} ) );
			} );
		}
		
		fitted.lines.forEach( function ( line, index ) {
			var x = box.x;
			var anchor = 'start';
			
			if ( layer.align === 'center' ) {
				x = box.x + box.w / 2;
				anchor = 'middle';
			} else if ( layer.align === 'right' ) {
				x = box.x + box.w;
				anchor = 'end';
			}
			
			elements.push( el( 'text', {
				fill: layer.color || '#ffffff',
				fontFamily: '"' + fontId + '", sans-serif',
				fontSize: fitted.size,
				key: key + '-' + index,
				textAnchor: anchor,
				x: x,
				y: box.y + ascent + index * step,
			}, line ) );
		} );
		
		return elements;
	}
	
	function rectElement( layer, canvas, key ) {
		var box = layerBox( layer, canvas );
		var hasFill = typeof layer.fill === 'string' && layer.fill !== 'none';
		var stroke = layer.stroke || {};
		var strokeWidth = Math.max( 0, parseInt( stroke.width, 10 ) || 0 );
		
		if ( ! hasFill && strokeWidth === 0 ) {
			return null;
		}
		
		var attributes = {
			fill: hasFill ? layer.fill : 'none',
			fillOpacity: layer.opacity === undefined ? 1 : layer.opacity,
			height: box.h,
			key: key,
			rx: Math.max( 0, parseInt( layer.radius, 10 ) || 0 ),
			width: box.w,
			x: box.x,
			y: box.y,
		};
		
		if ( strokeWidth > 0 ) {
			attributes.stroke = stroke.color || '#ffffff';
			attributes.strokeOpacity = layer.opacity === undefined ? 1 : layer.opacity;
			attributes.strokeWidth = strokeWidth;
		}
		
		return el( 'rect', attributes );
	}
	
	function Preview( props ) {
		var template = props.template;
		var binding = props.binding;
		var canvas = template.canvas || { h: 630, w: 1200 };
		var children = [];
		
		( template.layers || [] ).forEach( function ( layer, index ) {
			var key = 'layer-' + index;
			var elements = null;
			
			switch ( layer.type ) {
				case 'background':
					elements = backgroundElements( layer, canvas, key );
					break;
				case 'image':
					elements = imageElement( layer, canvas, binding, key );
					break;
				case 'rect':
					elements = rectElement( layer, canvas, key );
					break;
				case 'text':
					elements = textElements( layer, canvas, binding, key );
					break;
			}
			
			if ( elements ) {
				children = children.concat( elements );
			}
		} );
		
		return el(
			'svg',
			{
				'aria-label': props.label || '',
				role: 'img',
				style: { borderRadius: '2px', display: 'block', height: 'auto', width: '100%' },
				viewBox: '0 0 ' + canvas.w + ' ' + canvas.h,
				xmlns: 'http://www.w3.org/2000/svg',
			},
			children
		);
	}
	
	// ---- panel ---------------------------------------------------------------
	
	window.imageSocialiserPreview = {
		Preview: Preview,
		loadFonts: loadFonts,
		onFontsLoaded: onFontsLoaded,
	};
}( window.wp ) );
