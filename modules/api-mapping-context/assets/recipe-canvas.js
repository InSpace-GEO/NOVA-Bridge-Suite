( function ( root, factory ) {
	'use strict';
	var api = factory();
	if ( typeof module === 'object' && module.exports ) { module.exports = api; }
	else if ( root && root.document ) { api.mount( root, root.document, root.NovaRecipeCanvas ); }
}( typeof window !== 'undefined' ? window : this, function () {
	'use strict';
	var uuid = /^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i;
	var safeId = /^[a-zA-Z0-9_-]{1,160}$/;
	var consent = '#onetrust-banner-sdk,.onetrust-pc-dark-filter,#CybotCookiebotDialog,#CybotCookiebotDialogBodyUnderlay,.cmplz-cookiebanner,.cmplz-soft-cookiewall,#cookie-law-info-bar,.cli-popupbar-overlay,#cookie-notice,#moove_gdpr_cookie_info_bar,.gdpr_lightbox,.cky-consent-container,.cky-overlay,#cookiebanner';
	function validConfig( config ) {
		if ( ! config || ! Number.isSafeInteger( config.referenceId ) || config.referenceId < 1 || ! [ 'post', 'term' ].includes( config.referenceType ) || ! uuid.test( config.sessionId || '' ) || ! /^[a-f0-9]{64}$/.test( config.digest || '' ) ) { return false; }
		try { var origin = new URL( config.parentOrigin ); return [ 'http:', 'https:' ].includes( origin.protocol ) && origin.origin === config.parentOrigin && ! origin.username && ! origin.password; } catch ( error ) { return false; }
	}
	function validMessage( event, parent, config ) {
		var data = event && event.data;
		return !! ( event && event.source === parent && event.origin === config.parentOrigin && data && data.source === 'nova-recipe-admin' && data.sessionId === config.sessionId && data.digest === config.digest && data.referenceId === config.referenceId && data.referenceType === config.referenceType );
	}
	function normalizeItems( items, config, containers ) {
		if ( ! Array.isArray( items ) || items.length > 2000 ) { return []; }
		var seen = new Set(), out = [];
		items.forEach( function ( item ) {
			var id = item && ( containers ? item.container_id || item.field_id : item.field_id );
			if ( ! item || typeof id !== 'string' || ! safeId.test( id ) || seen.has( id ) || typeof item.target !== 'string' || ! item.target.startsWith( '/' ) || item.target.length > 1200 || ! item.locator || item.locator.reference_id !== config.referenceId ) { return; }
			seen.add( id ); out.push( { id: id, target: item.target, label: typeof item.label === 'string' ? item.label.slice( 0, 200 ) : id, writable: item.writable === true, assigned: item.assigned === true, locator: item.locator, current_value: typeof item.current_value === 'string' ? item.current_value : typeof item.value === 'string' ? item.value : '' } );
		} ); return out;
	}
	function unique( document, selector ) { var nodes = document.querySelectorAll( selector ); return nodes.length === 1 ? nodes[0] : null; }
	function controlPath( locator ) { var path = Array.isArray( locator.control_path ) ? locator.control_path.join( '.' ) : String( locator.control_path || '' ); return path.replace( /^\/?(?:settings|core)\//, '' ).replace( /\//g, '.' ).replace( /^settings\./, '' ); }
	function mediaUrl( value, base ) { if ( typeof value !== 'string' || ! value.trim() ) { return ''; } try { var url = new URL( value, base ); return url.protocol === 'https:' && ! url.username && ! url.password ? url.href : /^data:image\/(?:png|jpeg|gif|webp|avif);base64,/i.test( value ) ? value : ''; } catch ( error ) { return ''; } }
	function prepareMedia( document, config, fields ) {
		var scope = unique( document, '[data-elementor-id="' + config.referenceId + '"]' ); if ( ! scope ) { return; } fields = fields || [];
		function nativeValue( owner, type, control ) { var id = owner.getAttribute( 'data-id' ); if ( ! safeId.test( id || '' ) || unique( scope, '[data-id="' + id + '"]' ) !== owner ) { return null; } return fields.find( function ( item ) { return item.writable && item.locator.strategy === 'elementor' && item.locator.reference_id === config.referenceId && item.locator.node_id === id && item.locator.element_type === type && controlPath( item.locator ) === control; } ); }
		scope.querySelectorAll( '[data-widget_type="google_maps.default"]' ).forEach( function ( owner ) {
			var id = owner.getAttribute( 'data-id' ); if ( ! safeId.test( id || '' ) || unique( scope, '[data-id="' + id + '"]' ) !== owner ) { return; }
			var frame = unique( owner, 'iframe' ), field = nativeValue( owner, 'google_maps', 'address' ), placeholder = unique( owner, '.nrc-map-placeholder' ), address = field ? field.current_value : frame && ( frame.getAttribute( 'title' ) || frame.getAttribute( 'aria-label' ) ) || '';
			if ( placeholder ) { if ( field || frame ) { var text = unique( placeholder, '.nrc-map-address' ), next = address || 'No location set', accessible = 'Google Maps location: ' + ( address || 'Not set' ); if ( text && text.textContent !== next ) { text.textContent = next; } if ( placeholder.getAttribute( 'aria-label' ) !== accessible ) { placeholder.setAttribute( 'aria-label', accessible ); } } return; }
			if ( ! frame && ! field ) { return; } placeholder = document.createElement( 'div' ); placeholder.className = 'nrc-map-placeholder'; placeholder.setAttribute( 'role', 'img' ); placeholder.setAttribute( 'aria-label', 'Google Maps location: ' + ( address || 'Not set' ) ); var heading = document.createElement( 'strong' ); heading.textContent = 'Google Maps'; var location = document.createElement( 'p' ); location.className = 'nrc-map-address'; location.textContent = address || 'No location set'; var note = document.createElement( 'p' ); note.textContent = 'Map preview paused. Select this block to map its location.'; placeholder.appendChild( heading ); placeholder.appendChild( location ); placeholder.appendChild( note ); var height = frame && frame.getClientRects()[0]; placeholder.style.minHeight = Math.max( 180, Math.min( 900, height && height.height || 200 ) ) + 'px';
			if ( frame ) { frame.parentElement.replaceChild( placeholder, frame ); } else { ( unique( owner, '.elementor-custom-embed' ) || owner ).appendChild( placeholder ); }
		} );
		scope.querySelectorAll( '[data-widget_type="image.default"]' ).forEach( function ( owner ) {
			var field = nativeValue( owner, 'image', 'image.url' ), image = unique( owner, 'img' ); if ( ! image && ! field ) { return; }
			var value = field && field.current_value || image && ( image.getAttribute( 'data-src' ) || image.getAttribute( 'data-lazy-src' ) ), source = mediaUrl( value, document.baseURI || config.parentOrigin ); if ( ! source ) { return; }
			if ( ! image ) { image = document.createElement( 'img' ); ( unique( owner, '.elementor-widget-container' ) || owner ).appendChild( image ); }
			if ( image.getAttribute( 'src' ) !== source ) { image.setAttribute( 'src', source ); if ( image.getAttribute( 'srcset' ) ) { image.removeAttribute( 'srcset' ); } } if ( image.getAttribute( 'loading' ) !== 'eager' ) { image.setAttribute( 'loading', 'eager' ); }
		} );
	}
	function locate( document, item, config ) {
		var locator = item && item.locator; if ( ! locator || locator.reference_id !== config.referenceId ) { return null; }
		var strategy = locator.strategy, id = String( locator.node_id || '' ), control = controlPath( locator ), owner, selector;
		if ( strategy === 'elementor' ) {
			if ( ! safeId.test( id ) ) { return null; }
			var scope = unique( document, '[data-elementor-id="' + config.referenceId + '"]' ); if ( ! scope ) { return null; }
			owner = unique( scope, '[data-id="' + id + '"]' ); if ( ! owner ) { return null; }
			selector = { 'heading:title': '.elementor-heading-title', 'text-editor:editor': '.elementor-widget-container', 'button:text': '.elementor-button-text', 'image:caption': '.widget-image-caption', 'image:image.url': '.elementor-widget-container img', 'image:image.alt': '.elementor-widget-container img', 'google_maps:address': '.nrc-map-placeholder' }[ locator.element_type + ':' + control ];
		} else if ( strategy === 'beaver' ) {
			if ( ! safeId.test( id ) ) { return null; }
			var beaver = unique( document, '.fl-builder-content-' + config.referenceId ); if ( ! beaver ) { return null; }
			owner = unique( beaver, '.fl-node-' + id ); if ( ! owner ) { return null; }
			selector = { heading: '.fl-heading-text', text: '.fl-rich-text', label: '.fl-button-text', caption: '.fl-photo-caption', alt: '.fl-photo-img' }[ control ];
		} else if ( strategy === 'breakdance' ) {
			// A server-verified concrete ID/class is required; never guess provider markup.
			if ( locator.verified !== true ) { return null; }
			if ( safeId.test( locator.dom_id || '' ) ) { owner = unique( document, '[id="' + locator.dom_id + '"]' ); }
			else if ( safeId.test( locator.dom_class || '' ) ) { owner = unique( document, '.' + locator.dom_class ); }
			if ( ! owner ) { return null; }
		} else if ( strategy === 'core' ) {
			selector = { title: '.entry-title', content: '.entry-content', name: '.woocommerce-products-header__title', description: '.term-description', bottom_content: '.wc-content-below-products' }[ control ];
			if ( ! selector ) { return null; } owner = unique( document, selector ); if ( ! owner && control === 'content' ) { owner = unique( document, '.wp-block-post-content' ); } if ( ! owner && control === 'title' ) { owner = unique( document, '.wp-block-post-title' ); } if ( ! owner || owner.closest( 'nav,footer,form,[role="navigation"],[role="contentinfo"]' ) ) { return null; }
			if ( control === 'bottom_content' && ( config.referenceType !== 'term' || ! owner.closest( 'main,[role="main"],.woocommerce,.content,.site-content' ) ) ) { return null; }
			return { node: owner, owner: owner, precision: [ 'content', 'description', 'bottom_content' ].includes( control ) ? 'region' : 'field' };
		} else if ( strategy === 'native_anchor' ) {
			if ( ! safeId.test( id ) ) { return null; } owner = unique( document, '[id="' + id + '"]' );
			if ( ! owner || owner.closest( 'nav,header,footer,form,[role="navigation"],[role="banner"],[role="contentinfo"]' ) || ! owner.closest( 'main,[role="main"],.entry-content,.wp-block-post-content,[data-elementor-id="' + config.referenceId + '"]' ) ) { return null; }
		} else { return null; }
		var node = selector ? unique( owner, selector ) : null;
		return { node: node || owner, owner: owner, precision: node ? 'field' : 'widget' };
	}
	function visible( node, win ) { if ( ! node || ! node.getClientRects().length || node.closest( '[hidden],[aria-hidden="true"]' ) ) { return false; } var style = win.getComputedStyle( node ); return style.visibility !== 'hidden' && style.display !== 'none' && style.opacity !== '0'; }
	function mount( win, document, config ) {
		if ( ! validConfig( config ) || win.parent === win ) { return null; }
		var fields = [], containers = [], targets = new Map(), containerTargets = new Map(), selected = '', ready = false, timer = null, lastStatuses = '', marked = new Set();
		function send( type, data ) { win.parent.postMessage( Object.assign( { source: 'nova-recipe-canvas', type: type, referenceId: config.referenceId, referenceType: config.referenceType, sessionId: config.sessionId, digest: config.digest }, data || {} ), config.parentOrigin ); }
		function hideConsent() { document.documentElement.classList.add( 'nrc-hide-consent' ); document.documentElement.classList.toggle( 'nrc-consent-present', !! document.querySelector( consent ) ); }
		function mark( node, name, on ) { if ( ! node ) { return; } node.classList.toggle( name, on ); if ( on ) { marked.add( node ); } }
		function highlight( id, scroll ) {
			if ( id && ! targets.has( id ) && ! containerTargets.has( id ) ) { return false; }
			selected = id; marked.forEach( function ( node ) { node.classList.remove( 'nrc-selected' ); } );
			var target = targets.get( id ) || containerTargets.get( id ); if ( target ) { mark( target.node, 'nrc-selected', true ); if ( scroll && visible( target.node, win ) ) { var bounds = target.node.getBoundingClientRect(); win.scrollTo( { top: Math.max( 0, ( win.scrollY || 0 ) + bounds.top - Math.max( 0, ( win.innerHeight - bounds.height ) / 2 ) ), left: win.scrollX || 0, behavior: 'smooth' } ); } } return true;
		}
		function bind() {
			prepareMedia( document, config, fields );
			marked.forEach( function ( node ) { node.classList.remove( 'nrc-target', 'nrc-selected', 'nrc-assigned', 'nrc-protected' ); } ); marked.clear(); targets.clear(); containerTargets.clear();
			var nodeFlags = new Map();
			var statuses = fields.map( function ( item ) { var target = locate( document, item, config ), shown = !! ( target && visible( target.node, win ) ); if ( target ) { targets.set( item.id, target ); var flags = nodeFlags.get( target.node ) || { assigned: false, writable: false }; flags.assigned = flags.assigned || item.assigned; flags.writable = flags.writable || item.writable; nodeFlags.set( target.node, flags ); } return { field_id: item.id, target: item.target, visible: shown, precision: target ? target.precision : 'none', assigned: item.assigned, writable: item.writable, reason: ! target ? 'no_verified_locator' : ! shown ? 'not_visible_in_static_canvas' : '' }; } );
			nodeFlags.forEach( function ( flags, node ) { mark( node, 'nrc-target', true ); mark( node, 'nrc-assigned', flags.assigned ); mark( node, 'nrc-protected', ! flags.writable ); } );
			var groupStatuses = containers.map( function ( item ) { var target = locate( document, item, config ); if ( target ) { containerTargets.set( item.id, target ); } return { container_id: item.id, target: item.target, visible: !! ( target && visible( target.node, win ) ), precision: target ? 'region' : 'none', assigned: item.assigned, writable: item.writable }; } );
			highlight( targets.has( selected ) || containerTargets.has( selected ) ? selected : '', false ); var fingerprint = JSON.stringify( [ statuses, groupStatuses ] ); if ( fingerprint !== lastStatuses ) { lastStatuses = fingerprint; send( 'bindings', { fields: statuses, containers: groupStatuses, viewport: { width: win.innerWidth, height: win.innerHeight } } ); }
		}
		function schedule() { if ( ! ready ) { return; } if ( timer !== null ) { win.clearTimeout( timer ); } timer = win.setTimeout( function () { timer = null; hideConsent(); bind(); }, 100 ); }
		win.addEventListener( 'message', function ( event ) {
			if ( ! validMessage( event, win.parent, config ) ) { return; }
			var data = event.data;
			if ( data.type === 'bind' ) { fields = normalizeItems( data.fields, config, false ); containers = normalizeItems( data.containers || [], config, true ); lastStatuses = ''; if ( ready ) { hideConsent(); bind(); } }
			else if ( data.type === 'select' && typeof data.field_id === 'string' ) { highlight( data.field_id, data.scroll !== false ); }
		} );
		document.addEventListener( 'click', function ( event ) {
			event.preventDefault(); event.stopImmediatePropagation(); var node = event.target && event.target.nodeType === 3 ? event.target.parentElement : event.target, owners = [];
			targets.forEach( function ( target ) { if ( target.owner.contains( node ) && visible( target.node, win ) && ! owners.includes( target.owner ) ) { owners.push( target.owner ); } } );
			var owner = owners.find( function ( candidate ) { return ! owners.some( function ( other ) { return other !== candidate && candidate.contains( other ); } ); } ), ids = [], groupIds = [];
			if ( owner ) { targets.forEach( function ( target, id ) { if ( target.owner === owner && visible( target.node, win ) ) { ids.push( id ); } } ); }
			var groups = []; containerTargets.forEach( function ( target, id ) { if ( target.owner.contains( node ) && visible( target.node, win ) ) { groups.push( { id: id, node: target.owner } ); } } ); groups.sort( function ( a, b ) { return a.node === b.node ? 0 : a.node.contains( b.node ) ? 1 : b.node.contains( a.node ) ? -1 : 0; } ); groupIds = groups.map( function ( group ) { return group.id; } );
			if ( ids.length || groupIds.length ) { highlight( ids[0] || groupIds[0], false ); send( 'pick', { field_ids: ids, container_ids: groupIds } ); return; }
			var leaf = node && node.closest ? node.closest( 'h1,h2,h3,h4,h5,h6,p,li,blockquote,figcaption,img,a,button' ) : null;
			if ( leaf && visible( leaf, win ) && ! leaf.closest( 'nav,header,footer,form,[role="navigation"],[role="banner"],[role="contentinfo"]' ) ) { send( 'unbound', { reason: 'no_verified_locator', label: String( leaf.tagName === 'IMG' ? leaf.getAttribute( 'alt' ) || 'Image' : leaf.textContent || '' ).replace( /\s+/g, ' ' ).trim().slice( 0, 120 ) } ); }
		}, true );
		document.addEventListener( 'submit', function ( event ) { event.preventDefault(); event.stopImmediatePropagation(); }, true );
		document.addEventListener( 'auxclick', function ( event ) { event.preventDefault(); event.stopImmediatePropagation(); }, true );
		document.addEventListener( 'keydown', function ( event ) { if ( event.target && event.target.closest && event.target.closest( 'form,input,select,textarea,[contenteditable]' ) ) { event.preventDefault(); event.stopImmediatePropagation(); } }, true );
		function init() {
			ready = true; var style = document.createElement( 'style' ); style.textContent = consent.split( ',' ).map( function ( selector ) { return 'html.nrc-hide-consent ' + selector; } ).join( ',' ) + '{display:none!important;visibility:hidden!important;pointer-events:none!important}html.nrc-consent-present,html.nrc-consent-present body{overflow:auto!important}.nrc-target{cursor:crosshair!important}.nrc-target:hover{outline:2px dashed #8511D9!important;outline-offset:3px}.nrc-assigned{outline:2px solid #2E9E50!important;outline-offset:3px}.nrc-protected{outline-style:dotted!important}.nrc-selected{outline:3px solid #8511D9!important;outline-offset:4px}.nrc-map-placeholder{display:flex;flex-direction:column;align-items:center;justify-content:center;box-sizing:border-box;padding:24px;background:#f0ebf7;border:1px solid #d9c4ee;border-radius:8px;color:#242424;text-align:center;cursor:crosshair}.nrc-map-placeholder strong{font-size:20px}.nrc-map-placeholder p{margin:8px 0;font-size:14px}'; document.head.appendChild( style ); hideConsent(); prepareMedia( document, config, fields );
			send( 'ready', { limitations: [ 'Site scripts, forms, embedded frames and network requests are disabled in this canvas. It shows the rendered initial state; dynamic components can differ from the live page.' ], viewport: { width: win.innerWidth, height: win.innerHeight } } );
			if ( win.MutationObserver ) { new win.MutationObserver( function ( changes ) { var significant = changes.some( function ( change ) { if ( change.type !== 'attributes' || change.attributeName !== 'class' ) { return true; } var strip = function ( classes ) { return String( classes || '' ).split( /\s+/ ).filter( function ( name ) { return name && ! name.startsWith( 'nrc-' ); } ).sort().join( ' ' ); }; return strip( change.oldValue ) !== strip( change.target.getAttribute( 'class' ) ); } ); if ( significant ) { schedule(); } } ).observe( document.body, { childList: true, subtree: true, attributes: true, attributeOldValue: true, attributeFilter: [ 'class', 'id', 'style', 'hidden', 'aria-hidden', 'src' ] } ); }
			if ( win.ResizeObserver ) { new win.ResizeObserver( schedule ).observe( document.body ); }
			document.addEventListener( 'load', schedule, true ); win.addEventListener( 'resize', schedule );
			if ( document.fonts && document.fonts.ready ) { document.fonts.ready.then( schedule, function () {} ); }
			if ( fields.length || containers.length ) { bind(); }
		}
		if ( document.readyState === 'loading' ) { document.addEventListener( 'DOMContentLoaded', init ); } else { init(); }
		return { refresh: schedule, select: highlight };
	}
	return { validConfig: validConfig, validMessage: validMessage, normalizeItems: normalizeItems, mediaUrl: mediaUrl, prepareMedia: prepareMedia, locate: locate, visible: visible, mount: mount };
} ) );
