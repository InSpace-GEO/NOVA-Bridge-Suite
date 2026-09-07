( function () {
	'use strict';
	var config = window.NovaMappingPreview, fields = [], builders = [], providers = [], issueNode = null, targets = new Map(), selected = '', ready = false, hideConsent = true;
	if ( ! config || window.parent === window ) { return; }
	var consentSelectors = '#onetrust-banner-sdk,.onetrust-pc-dark-filter,#CybotCookiebotDialog,#CybotCookiebotDialogBodyUnderlay,.cmplz-cookiebanner,.cmplz-soft-cookiewall,#cookie-law-info-bar,.cli-popupbar-overlay,#cookie-notice,#moove_gdpr_cookie_info_bar,.gdpr_lightbox,.cky-consent-container,.cky-overlay,#cookiebanner';
	function updateConsent() { document.documentElement.classList.toggle( 'nova-preview-hide-consent', hideConsent && !! document.querySelector( consentSelectors ) ); }
	function send( type, data ) { window.parent.postMessage( Object.assign( { source: 'nova-mapping-preview', type: type, referenceId: config.referenceId, referenceType: config.referenceType }, data || {} ), config.parentOrigin ); }
	function visible( node ) { return !! ( node && node.getClientRects().length && getComputedStyle( node ).visibility !== 'hidden' ); }
	function unique( selector, scope ) { var nodes = ( scope || document ).querySelectorAll( selector ); return nodes.length === 1 ? nodes[0] : null; }
	function target( field ) {
		if ( field.source === 'acf' && typeof field.preview_text === 'string' ) {
			var normalize = function ( text ) { return text.replace( /\s+/g, ' ' ).trim(); }, expected = normalize( field.preview_text );
			if ( expected.length < 12 || expected.length > 20000 ) { return null; }
			var matches = Array.from( document.querySelectorAll( 'main p,main h1,main h2,main h3,main h4,main li,main div,main section,.entry-content p,.entry-content h2,.entry-content h3,.entry-content div' ) ).filter( function ( node ) { return ! node.closest( 'nav,header,footer,form,[aria-hidden="true"]' ) && visible( node ) && normalize( node.textContent ) === expected; } );
			matches = matches.filter( function ( node ) { return ! matches.some( function ( other ) { return other !== node && node.contains( other ); } ); } );
			return matches.length === 1 ? { node: matches[0], precision: 'text-match' } : null;
		}
		if ( field.source === 'builder' && field.builder === 'elementor' ) {
			var key = ( field.selector_data || {} ).field_key || '', id = key.split( '|' )[0];
			if ( ! /^[a-zA-Z0-9_-]+$/.test( id ) ) { return null; }
			var scope = unique( '[data-elementor-id="' + config.referenceId + '"]' );
			if ( ! scope ) { return null; }
			var widget = unique( '[data-id="' + id + '"]', scope );
			if ( ! widget ) { return null; }
			var setting = key.split( '|' ).slice(1).join('|');
			var selector = { 'heading:title': '.elementor-heading-title', 'text-editor:editor': '.elementor-widget-container', 'button:text': '.elementor-button-text' }[ field.element + ':' + setting ];
			return { node: selector && unique( selector, widget ) || widget, precision: selector && unique( selector, widget ) ? 'field' : 'widget' };
		}
		if ( field.builder === 'gutenberg' && field.element === 'document' ) { var documentNode = unique( '.entry-content' ) || unique( '.wp-block-post-content' ); return documentNode ? { node: documentNode, precision: 'document' } : null; }
		// Native bindings require known wrappers; ACF text matches above are explicitly labelled.
		var selector = { '/title': '.entry-title', '/name': '.woocommerce-products-header__title', '/description': '.term-description' }[ field.path ];
		if ( field.path === '/content' && ! builders.some( function ( b ) { return b !== 'gutenberg'; } ) && ! fields.some( function ( f ) { return f.source === 'builder'; } ) && ! document.querySelector( '[data-elementor-type="wp-page"], [data-elementor-type="single-page"], .fl-builder-content, .fusion-builder-row, .vc_row, .breakdance' ) ) { selector = '.entry-content'; }
		var node = selector && unique( selector );
		return node ? { node: node, precision: 'region' } : null;
	}
	function bind() {
		targets.forEach( function ( t ) { t.node.classList.remove( 'nova-mapping-target', 'nova-mapping-selected' ); } ); targets.clear();
		var statuses = [];
		fields.forEach( function ( field ) { var t = target( field ); if ( t ) { targets.set( field.path, t ); t.node.classList.add( 'nova-mapping-target' ); } statuses.push( { path: field.path, visible: !! ( t && visible( t.node ) ), precision: t ? t.precision : 'none' } ); } );
		send( 'bindings', { fields: statuses } ); highlight( selected, false );
	}
	function highlight( path, scroll ) {
		selected = path; if ( issueNode ) { issueNode.classList.remove( 'nova-mapping-issue' ); issueNode = null; }
		targets.forEach( function ( t, key ) { t.node.classList.toggle( 'nova-mapping-selected', key === path ); } );
		var t = targets.get( path );
		if ( t && visible( t.node ) && scroll ) { t.node.scrollIntoView( { behavior: 'smooth', block: 'center' } ); }
		if ( path ) { send( 'selection', { path: path, visible: !! ( t && visible( t.node ) ) } ); }
	}
	window.addEventListener( 'message', function ( event ) {
		if ( event.source !== window.parent || event.origin !== config.parentOrigin || ! event.data || event.data.source !== 'nova-mapping-admin' || event.data.referenceId !== config.referenceId ) { return; }
		if ( event.data.type === 'consent' ) { hideConsent = !! event.data.hidden; updateConsent(); }
		if ( event.data.type === 'bind' && Array.isArray( event.data.fields ) ) { hideConsent = event.data.hideConsent !== false; updateConsent(); builders = Array.isArray( event.data.builders ) ? event.data.builders : []; providers = Array.isArray( event.data.providers ) ? event.data.providers : []; fields = event.data.fields.filter( function ( f ) { return f && typeof f.path === 'string'; } ); if ( ready ) { bind(); } }
		if ( event.data.type === 'select' && typeof event.data.path === 'string' ) { highlight( event.data.path, true ); }
	} );

	// Consider editorial leaves only, inside main content. No overlays or full-page scan.
	function contentCandidate( node ) {
		if ( ! node || ! node.closest ) { return null; }
		var leaf = node.closest( 'h1,h2,h3,h4,h5,h6,p,li,blockquote,figcaption,img,a,button' );
		if ( ! leaf || ! visible( leaf ) || leaf.closest( 'nav,header,footer,form,[role="navigation"],[role="banner"],[role="contentinfo"],[aria-hidden="true"],.screen-reader-text,.elementor-location-header,.elementor-location-footer' ) ) { return null; }
		if ( ! leaf.closest( 'main,[role="main"],.entry-content,.wp-block-post-content,.term-description,[data-elementor-id="' + config.referenceId + '"]' ) ) { return null; }
		if ( leaf.tagName === 'IMG' ) { return leaf.getAttribute( 'alt' ) && leaf.getBoundingClientRect().width >= 48 && leaf.getBoundingClientRect().height >= 48 ? leaf : null; }
		return leaf.textContent.trim().length >= 3 ? leaf : null;
	}
	function providerFor( node ) {
		var wrappers = { elementor: '[data-elementor-id]', beaver: '.fl-builder-content', wpbakery: '.vc_row,.wpb_content_element', divi: '.et_pb_section,.et_pb_module', avada: '.fusion-builder-row,.fusion-layout-column', breakdance: '.breakdance', gutenberg: '.wp-block-post-content,[class*="wp-block-"]' };
		var builder = Object.keys( wrappers ).find( function ( b ) { return node.closest( wrappers[b] ); } );
		if ( ! builder && builders.length === 1 ) { builder = builders[0]; }
		return { builder: builder || '', provider: providers.find( function ( p ) { return p.id === builder; } ) };
	}
	function showIssue( node, reason ) {
		highlight( '', false ); if ( issueNode ) { issueNode.classList.remove( 'nova-mapping-issue' ); } issueNode = node; node.classList.add( 'nova-mapping-issue' );
		var detected = providerFor( node );
		send( 'unbound', { builder: detected.builder, reason: reason, label: node.tagName === 'IMG' ? 'Image: ' + node.getAttribute( 'alt' ).slice(0,120) : node.textContent.trim().replace(/\s+/g,' ').slice(0,120) } );
	}

	// Preview clicks select regions; links and forms must not navigate or submit.
	document.addEventListener( 'click', function ( event ) {
		event.preventDefault(); event.stopImmediatePropagation();
		var node = event.target, matches = [];
		while ( node && node !== document ) { targets.forEach( function ( t, path ) { if ( t.node === node ) { matches.push( path ); } } ); if ( matches.length ) { break; } node = node.parentElement; }
		var candidate = contentCandidate( event.target );
		var specific = matches.some( function ( path ) { return targets.get(path).precision !== 'document' && ! ['/content','/description'].includes(path); } );
		if ( candidate && ! specific ) {
			var info = providerFor( candidate );
			if ( info.provider && ! info.provider.available ) { showIssue( candidate, 'provider_unavailable' ); return; }
			if ( info.builder === 'gutenberg' ) { showIssue( candidate, 'document_only' ); return; }
		}
		if ( issueNode ) { issueNode.classList.remove( 'nova-mapping-issue' ); issueNode = null; }
		if ( matches.length ) { highlight( matches[0], false ); send( 'pick', { paths: matches } ); }
		else if ( candidate ) { showIssue( candidate, 'unknown' ); }
		else { send( 'clear-issue' ); }
	}, true );
	document.addEventListener( 'submit', function ( event ) { event.preventDefault(); event.stopImmediatePropagation(); }, true );
	document.addEventListener( 'auxclick', function ( event ) { event.preventDefault(); }, true );
	function init() {
		ready = true;
		var consentStyle = document.createElement( 'style' ); consentStyle.textContent = consentSelectors.split( ',' ).map( function ( selector ) { return 'html.nova-preview-hide-consent ' + selector; } ).join( ',' ) + '{display:none!important;visibility:hidden!important;pointer-events:none!important}html.nova-preview-hide-consent,html.nova-preview-hide-consent body{overflow:auto!important}'; document.head.appendChild( consentStyle ); updateConsent();
		var style = document.createElement( 'style' ); style.textContent = '.nova-mapping-issue{outline:2px dashed #b7791f!important;outline-offset:3px}.nova-mapping-target{cursor:crosshair!important}.nova-mapping-target:hover{outline:2px dashed #2563eb!important;outline-offset:3px}.nova-mapping-selected{outline:3px solid #2563eb!important;outline-offset:4px;background-color:rgba(37,99,235,.08)!important}'; document.head.appendChild( style );
		bind(); send( 'ready' );
		var timer; new MutationObserver( function ( changes ) { if ( changes.some( function ( m ) { return m.type === 'childList'; } ) ) { clearTimeout( timer ); timer = setTimeout( function () { updateConsent(); bind(); }, 150 ); } } ).observe( document.body, { childList: true, subtree: true } );
		window.addEventListener( 'resize', function () { clearTimeout( timer ); timer = setTimeout( function () { updateConsent(); bind(); }, 150 ); } );
	}
	if ( document.readyState === 'loading' ) { document.addEventListener( 'DOMContentLoaded', init ); } else { init(); }
}() );
