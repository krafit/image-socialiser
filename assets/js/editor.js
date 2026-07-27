/**
 * Image Socialiser — block editor panel.
 *
 * Build-less on purpose: uses wp.element directly, no JSX/toolchain.
 * The SVG preview renders the same layer model the server renders to
 * PNG, with a JS mirror of the PHP Text_Fitter, so it is instant and
 * needs no server round-trip.
 *
 * @package happyhappy\ImageSocialiser
 */
( function ( wp ) {
	'use strict';
	
	var data = window.imageSocialiserEditor || {};
	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var __ = wp.i18n.__;
	var useSelect = wp.data.useSelect;
	var useDispatch = wp.data.useDispatch;
	var apiFetch = wp.apiFetch;
	var components = wp.components;
	var PluginDocumentSettingPanel = wp.editor.PluginDocumentSettingPanel;
	var MediaUpload = wp.blockEditor.MediaUpload;
	var MediaUploadCheck = wp.blockEditor.MediaUploadCheck;
	
	var previewModule = window.imageSocialiserPreview;
	
	if ( ! PluginDocumentSettingPanel || ! data.postId || ! previewModule ) {
		return;
	}
	
	var Preview = previewModule.Preview;
	
	previewModule.loadFonts( data.fonts );
	
	
	function Panel() {
		var refresh = useState( 0 );
		var setRefresh = refresh[ 1 ];
		var statusState = useState( { error: '', status: '', url: '' } );
		var status = statusState[ 0 ];
		var setStatus = statusState[ 1 ];
		var busyState = useState( false );
		var busy = busyState[ 0 ];
		var setBusy = busyState[ 1 ];
		var editPost = useDispatch( 'core/editor' ).editPost;
		
		var selected = useSelect( function ( select ) {
			var editor = select( 'core/editor' );
			var meta = editor.getEditedPostAttribute( 'meta' ) || {};
			var featuredId = editor.getEditedPostAttribute( 'featured_media' );
			var featured = featuredId ? select( 'core' ).getMedia( featuredId ) : null;
			var overrideId = meta[ data.metaKeys.override ] || 0;
			var override = overrideId ? select( 'core' ).getMedia( overrideId ) : null;
			
			return {
				featuredUrl: featured && featured.source_url ? featured.source_url : '',
				isSaving: editor.isSavingPost() && ! editor.isAutosavingPost(),
				meta: meta,
				override: override,
				overrideId: overrideId,
				title: editor.getEditedPostAttribute( 'title' ) || '',
			};
		}, [] );
		
		// re-render once the preview fonts are loaded
		useEffect( function () {
			return previewModule.onFontsLoaded( function () {
				setRefresh( function ( value ) {
					return value + 1;
				} );
			} );
		}, [] );
		
		var fetchStatus = function () {
			apiFetch( { path: '/' + data.restNamespace + '/status/' + data.postId } ).then( setStatus ).catch( function () {} );
		};
		
		// initial status
		useEffect( fetchStatus, [] );
		
		// refetch status after each save (save_post enqueues the job)
		var wasSaving = useRef( false );
		
		useEffect( function () {
			if ( wasSaving.current && ! selected.isSaving ) {
				fetchStatus();
			}
			
			wasSaving.current = selected.isSaving;
		}, [ selected.isSaving ] );
		
		// poll while pending
		useEffect( function () {
			if ( status.status !== 'pending' ) {
				return undefined;
			}
			
			var polls = 0;
			var interval = window.setInterval( function () {
				polls++;
				
				if ( polls > 15 ) {
					window.clearInterval( interval );
					
					return;
				}
				
				fetchStatus();
			}, 4000 );
			
			return function () {
				window.clearInterval( interval );
			};
		}, [ status.status ] );
		
		var regenerate = function () {
			setBusy( true );
			apiFetch( {
				method: 'POST',
				path: '/' + data.restNamespace + '/regenerate/' + data.postId,
			} ).then( function ( payload ) {
				setStatus( payload );
				setBusy( false );
			} ).catch( function () {
				setBusy( false );
			} );
		};
		
		var updateMeta = function ( key, value ) {
			var meta = {};
			meta[ key ] = value;
			editPost( { meta: meta } );
		};
		
		var templates = data.templates || {};
		var template = templates[ data.defaultTemplateId ];
		var binding = {
			author: data.binding.author,
			backgroundUrl: data.binding.background_url,
			category: data.binding.category,
			coverArtUrl: data.binding.cover_art_url,
			date: data.binding.date,
			featuredUrl: selected.featuredUrl,
			logoUrl: data.binding.logo_url,
			site_name: data.binding.site_name,
			subtitle: ( selected.meta[ data.metaKeys.subtitle ] || '' ).trim() || data.binding.subtitle_default,
			title: ( selected.meta[ data.metaKeys.title ] || '' ).trim() || selected.title,
		};
		var statusLabels = {
			failed: __( 'Generation failed', 'image-socialiser' ),
			pending: __( 'Generation queued …', 'image-socialiser' ),
			ready: __( 'Image generated', 'image-socialiser' ),
		};
		var children = [];
		
		if ( template ) {
			children.push( el( Preview, {
				binding: binding,
				key: 'preview',
				label: __( 'Social image preview', 'image-socialiser' ),
				template: template,
			} ) );
		}
		
		if ( selected.overrideId ) {
			children.push( el(
				components.Notice,
				{ isDismissible: false, key: 'override-notice', status: 'info' },
				__( 'A manual image override is set; it replaces the generated image.', 'image-socialiser' )
			) );
		}
		
		children.push( el( components.TextControl, {
			__next40pxDefaultSize: true,
			__nextHasNoMarginBottom: true,
			help: __( 'Leave empty to use the post title.', 'image-socialiser' ),
			key: 'title',
			label: __( 'Image title', 'image-socialiser' ),
			onChange: function ( value ) {
				updateMeta( data.metaKeys.title, value );
			},
			placeholder: selected.title,
			value: selected.meta[ data.metaKeys.title ] || '',
		} ) );
		
		children.push( el( components.TextControl, {
			__next40pxDefaultSize: true,
			__nextHasNoMarginBottom: true,
			help: data.binding.subtitle_default
				? __( 'Leave empty to use the site-wide source.', 'image-socialiser' )
				: '',
			key: 'subtitle',
			label: __( 'Subtitle', 'image-socialiser' ),
			onChange: function ( value ) {
				updateMeta( data.metaKeys.subtitle, value );
			},
			placeholder: data.binding.subtitle_default || '',
			value: selected.meta[ data.metaKeys.subtitle ] || '',
		} ) );
		
		children.push( el(
			MediaUploadCheck,
			{ key: 'override' },
			el( MediaUpload, {
				allowedTypes: [ 'image' ],
				onSelect: function ( media ) {
					updateMeta( data.metaKeys.override, media && media.id ? media.id : 0 );
				},
				render: function ( open ) {
					var buttons = [
						el(
							components.Button,
							{ key: 'select', onClick: open.open, variant: 'secondary' },
							selected.overrideId
								? __( 'Change image override', 'image-socialiser' )
								: __( 'Set manual image override', 'image-socialiser' )
						),
					];
					
					if ( selected.overrideId ) {
						buttons.push( el(
							components.Button,
							{
								isDestructive: true,
								key: 'remove',
								onClick: function () {
									updateMeta( data.metaKeys.override, 0 );
								},
								variant: 'tertiary',
							},
							__( 'Remove', 'image-socialiser' )
						) );
					}
					
					return el( components.Flex, { gap: 2, justify: 'flex-start' }, buttons );
				},
				value: selected.overrideId,
			} )
		) );
		
		var statusChildren = [];
		
		if ( status.status ) {
			statusChildren.push( el(
				'p',
				{ key: 'label', style: { margin: '0 0 4px' } },
				statusLabels[ status.status ] || status.status
			) );
		}
		
		if ( status.status === 'failed' && status.error ) {
			statusChildren.push( el(
				components.Notice,
				{ isDismissible: false, key: 'error', status: 'error' },
				status.error
			) );
		}
		
		if ( status.status === 'ready' && status.url ) {
			statusChildren.push( el(
				components.ExternalLink,
				{ href: status.url, key: 'link' },
				__( 'View generated file', 'image-socialiser' )
			) );
		}
		
		children.push( el( 'div', { key: 'status', style: { marginTop: '8px' } }, statusChildren ) );
		children.push( el(
			components.Button,
			{
				isBusy: busy,
				key: 'regenerate',
				onClick: regenerate,
				style: { marginTop: '8px' },
				variant: 'primary',
			},
			busy ? __( 'Generating …', 'image-socialiser' ) : __( 'Regenerate image', 'image-socialiser' )
		) );
		
		return el(
			PluginDocumentSettingPanel,
			{
				name: 'image-socialiser',
				title: __( 'Social image', 'image-socialiser' ),
			},
			children
		);
	}
	
	wp.plugins.registerPlugin( 'image-socialiser', {
		icon: 'share',
		render: Panel,
	} );
}( window.wp ) );
