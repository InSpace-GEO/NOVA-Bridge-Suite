( function () {

	'use strict';

	var root, config, scopeChosen = false, controlId = 0, state = { importOpen: false, data: null, selected: '', dirty: false, busy: false, filter: 'all', scope: 'all', epoch: 0, layout: null, activeField: '', frame: null, statuses: {}, frameReady: false };

	var sources = [ [ '', 'Guidance only / no direct source' ], [ 'leave_empty', 'Leave empty (do not send)' ], [ 'h1', 'Visible heading (H1)', 'Content' ], [ 'content', 'Full content', 'Content' ], [ 'top_content', 'Intro', 'Content' ], [ 'bottom_content', 'Main content', 'Content' ], [ 'title', 'SEO title', 'SEO metadata' ], [ 'meta_description', 'Meta description', 'SEO metadata' ], [ 'primary_keyword', 'Primary keyword', 'SEO metadata' ], [ 'secondary_keywords', 'Secondary keywords', 'SEO metadata' ], [ 'featured_media', 'Uploaded WordPress image ID', 'Image data' ], [ 'image_url', 'Primary image URL', 'Image data' ], [ 'image_urls', 'All image URLs', 'Image data' ], [ 'image_alt', 'Image alternative text', 'Image data' ] ];

	function el( tag, cls, text ) { var n = document.createElement( tag ); if ( cls ) { n.className = cls; } if ( text !== undefined ) { n.textContent = String( text ); } return n; }

	function arr( value ) { return Array.isArray( value ) ? value : Object.keys( value || {} ).map( function ( key ) { return Object.assign( { id: key, path: key }, value[ key ] ); } ); }

	function button( text, cls, action ) { var n = el( 'button', cls || 'button', text ); n.type = 'button'; n.addEventListener( 'click', action ); return n; }

	function enableBridgeButton( provider ) {
		var enable = button( 'Enable ' + ( provider.label || provider.id ) + ' bridge', 'button button-primary', function () {
			if ( state.dirty ) { notice( 'Save your mapping changes before enabling the bridge so the field inventory can refresh safely.', true ); return; }
			enable.disabled = true;
			action( function () { return api( '/enable-bridge', { builder: provider.id }, true ); }, 'Bridge enabled. The field inventory has been refreshed.' ).finally( function () { enable.disabled = false; } );
		} ); return enable;
	}

	function control( parent, title, input, help ) { var group = el( 'label', 'ns-control' ), label = el( 'span', 'ns-label', title ), id = 'ns-control-' + ( ++controlId ); label.id = id; input.setAttribute( 'aria-labelledby', id ); group.appendChild( label ); group.appendChild( input ); if ( help ) { var description = el( 'span', 'ns-help', help ); description.id = id + '-help'; input.setAttribute( 'aria-describedby', description.id ); group.appendChild( description ); } parent.appendChild( group ); return input; }

	function input( type, value ) { var n = el( 'input' ); n.type = type; n.value = value || ''; return n; }

	function textarea( value, rows ) { var n = el( 'textarea' ); n.value = value || ''; n.rows = rows || 3; return n; }

	function select( choices, value ) { var n = el( 'select' ), groups = new Map(); choices.forEach( function ( item ) { var parent = n; if ( item[2] ) { if ( ! groups.has( item[2] ) ) { var group = el( 'optgroup' ); group.label = item[2]; n.appendChild( group ); groups.set( item[2], group ); } parent = groups.get( item[2] ); } var o = el( 'option', '', item[1] ); o.value = item[0]; parent.appendChild( o ); } ); n.value = value || ''; return n; }

	function notice( text, error ) { var box = root.querySelector( '.ns-feedback' ); if ( box ) { box.remove(); } box = el( 'div', 'ns-notice ns-feedback' + ( error ? ' ns-error' : '' ), text ); box.setAttribute( 'role', error ? 'alert' : 'status' ); root.prepend( box ); }

	async function api( suffix, body, mapping ) {

		var opts = { method: body === undefined ? 'GET' : 'POST', credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json', 'X-WP-Nonce': config.nonce } };

		if ( body !== undefined ) { opts.headers[ 'Content-Type' ] = 'application/json'; opts.body = JSON.stringify( body ); }

		var response = await fetch( ( mapping ? config.mappingUrl : config.url ) + suffix, opts ), result;

		try { result = await response.json(); } catch ( e ) { throw new Error( 'WordPress returned an unreadable response. Reload and try again.' ); }

		if ( ! response.ok ) { throw new Error( result.message || 'Request failed (' + response.status + ').' ); }

		return result;

	}



	function canLeave() { return ! state.dirty || window.confirm( 'You have unsaved mapping changes. Discard them?' ); }

	function markDirty() { state.dirty = true; var n = root.querySelector( '.ns-save-state' ); if ( n ) { n.textContent = 'Unsaved changes'; } }

	function setScope( scope ) { state.scope = scope; scopeChosen = true; try { localStorage.setItem( 'nova-mapping-scope:' + config.mappingUrl, scope ); } catch ( e ) {} }

	async function refresh( message ) {

		var epoch = ++state.epoch;

		root.setAttribute( 'aria-busy', 'true' ); root.inert = true;

		try { var data = await api( '?scope=' + state.scope, undefined, true ); if ( epoch !== state.epoch ) { return; } if ( ! scopeChosen ) { scopeChosen = true; if ( data.summary.strategy_urls > 0 && state.scope !== 'strategy' ) { setScope( 'strategy' ); return await refresh( message ); } } state.data = data; state.dirty = false; render(); if ( message ) { notice( message ); } }

		finally { root.removeAttribute( 'aria-busy' ); root.inert = false; }

	}

	async function action( operation, success ) { if ( state.busy ) { return; } state.busy = true; root.inert = true; try { await operation(); await refresh( success ); } catch ( e ) { notice( e.message, true ); } finally { state.busy = false; root.inert = false; } }

	function entries() {

		var out = arr( state.data.layouts ).map( function ( l ) { return { key: l.signature, layout: l, rows: arr( l.rows ), unresolved: false }; } );

		if ( state.scope === 'strategy' ) {
			var groups = new Map();
			arr( state.data.unresolved ).forEach( function ( r ) {
				var candidates = arr( r.candidates ).map( function ( c ) { return c.signature || ( c.reference_type + ':' + c.reference_id ); } ).sort();
				// Group only unresolved siblings with the same hints and candidate layouts. No inferred assignment.
				var key = ! r.reference_id && r.parent_path && r.parent_path !== '/' && [ 'needs_reference', 'suggested' ].includes( r.status ) ? JSON.stringify( [ r.parent_path, r.page_type || '', r.locale || '', r.status, r.basis || '', candidates ] ) : r.id;
				if ( ! groups.has( key ) ) { var group = { key: 'row:' + r.id, row: r, rows: [], unresolved: true }; groups.set( key, group ); out.push( group ); }
				groups.get( key ).rows.push( r );
			} );
		}


		return out;

	}

	function statusText( entry ) { return entry.unresolved ? 'Choose reference' : { mapped: 'Mapping saved', native: 'Native fields', unmapped: 'Needs mapping' }[ entry.layout.mapping_status ]; }

	function render() {

		++state.epoch; state.frame = null; state.layout = null; root.replaceChildren();

		var header = el( 'header', 'ns-header' ), text = el( 'div' ); text.appendChild( el( 'div', 'ns-eyebrow', 'NOVA / MAPPING' ) ); text.appendChild( el( 'h1', '', 'Map your site layouts' ) ); text.appendChild( el( 'p', '', 'Map each unique layout once. Select a field on the page or in the inspector.' ) ); header.appendChild( text );

		header.appendChild( button( 'Refresh inventory', 'button', function () { if ( canLeave() ) { refresh().catch( function ( e ) { notice( e.message, true ); } ); } } ) ); root.appendChild( header );

		var scopeBar = el( 'div', 'ns-scope' );

		var scope = control( scopeBar, 'Mapping scope', select( [ [ 'all', 'All site layouts' ], [ 'strategy', 'Imported strategy' ] ], state.scope ) );

		scope.addEventListener( 'change', function () { if ( ! canLeave() ) { scope.value = state.scope; return; } setScope( scope.value ); state.selected = ''; refresh().catch( function ( e ) { notice( e.message, true ); } ); } );

		scopeBar.appendChild( el( 'p', 'ns-help', 'Without a strategy, all layouts are shown. Your scope choice is remembered in this browser; mappings outside the scope are kept.' ) ); root.appendChild( scopeBar );

		renderImport();

		if ( state.data.source_host && state.data.source_host !== state.data.site_host ) { notice( 'The imported strategy belongs to ' + state.data.source_host + '. References are checked against this staging site.', true ); }

		var summary = state.data.summary, stats = el( 'div', 'ns-stats' );

		[ [ summary.site_layouts, 'Site layouts' ], [ summary.visible_layouts, 'In this scope' ], [ summary.strategy_urls, 'Strategy URLs' ], [ summary.unresolved, 'URLs needing references' ] ].forEach( function ( item ) { var card = el( 'div' ); card.appendChild( el( 'strong', '', item[0] ) ); card.appendChild( el( 'span', '', item[1] ) ); stats.appendChild( card ); } ); root.appendChild( stats );

		root.appendChild( el( 'p', 'ns-help', summary.site_items + ' eligible content items, including drafts. Native layouts remain visible; unsupported builder fields are flagged when inspected.' ) );

		var workspace = el( 'div', 'ns-workspace' ), nav = el( 'aside', 'ns-sidebar' ), navhead = el( 'div', 'ns-sidebar-head' ); navhead.appendChild( el( 'h2', '', 'Layouts' ) );

		var filter = select( [ [ 'all', 'All layouts' ], [ 'pending', 'Needs mapping' ], [ 'mapped', 'Mapping saved / native' ] ], state.filter ); filter.setAttribute( 'aria-label', 'Filter layouts' );

		filter.addEventListener( 'change', function () { if ( ! canLeave() ) { filter.value = state.filter; return; } state.filter = filter.value; state.dirty = false; render(); } ); navhead.appendChild( filter );

		var search = input( 'search' ); search.placeholder = 'Find a layout…'; search.setAttribute( 'aria-label', 'Find a layout' ); navhead.appendChild( search ); nav.appendChild( navhead );

		var all = entries().filter( function ( e ) { var pending = e.unresolved || e.layout.mapping_status === 'unmapped'; return state.filter === 'all' || ( state.filter === 'pending' ? pending : ! pending ); } );

		if ( ! all.some( function ( e ) { return e.key === state.selected; } ) ) { state.selected = all.length ? all[0].key : ''; }

		all.forEach( function ( entry ) {

			var label = entry.unresolved ? ( entry.rows.length > 1 ? entry.row.parent_path + '… · ' + entry.rows.length + ' URLs' : entry.row.path ) : ( entry.layout.label || entry.layout.title || 'Untitled layout' );

			var item = button( '', 'ns-queue-item' + ( entry.key === state.selected ? ' is-selected' : '' ), function () { if ( entry.key === state.selected || ! canLeave() ) { return; } state.selected = entry.key; state.dirty = false; render(); } );

			item.dataset.search = ( label + ' ' + ( entry.unresolved ? entry.rows.map( function ( r ) { return r.path; } ).join( ' ' ) : '' ) + ' ' + ( entry.layout ? entry.layout.post_type + ' ' + entry.layout.builders.join( ' ' ) + ' ' + entry.layout.path : '' ) ).toLowerCase(); item.setAttribute( 'aria-pressed', String( entry.key === state.selected ) ); item.appendChild( el( 'span', 'ns-queue-path', label ) );

			var meta = el( 'span', 'ns-queue-meta' ); meta.appendChild( el( 'span', 'ns-badge', statusText( entry ) ) ); if ( entry.layout ) { meta.appendChild( el( 'span', '', entry.layout.members.length + ' items' ) ); } item.appendChild( meta );

			if ( entry.layout ) { item.appendChild( el( 'span', 'ns-help', entry.layout.post_type + ' · ' + ( entry.layout.builders.join( ', ' ) || 'Native' ) ) ); } nav.appendChild( item );

		} );

		search.addEventListener( 'input', function () { nav.querySelectorAll( '.ns-queue-item' ).forEach( function ( item ) { item.hidden = item.dataset.search.indexOf( search.value.trim().toLowerCase() ) < 0; } ); } );

		workspace.appendChild( nav ); var editor = el( 'section', 'ns-editor' ); workspace.appendChild( editor ); root.appendChild( workspace );

		var selected = all.find( function ( e ) { return e.key === state.selected; } );

		if ( ! selected ) { editor.appendChild( el( 'h2', '', state.scope === 'strategy' && ! summary.strategy_urls ? 'Import a strategy to narrow the queue' : 'No layouts in this view' ) ); return; }

		if ( selected.unresolved ) { editor.appendChild( el( 'h2', '', selected.rows.length > 1 ? selected.row.parent_path + '… · ' + selected.rows.length + ' URLs' : selected.row.path ) ); renderReference( selected, editor ); return; }

		loadLayout( selected, editor );

	}

	function renderImport() {
		var imports = arr( state.data.imports ), panel = el( 'details', 'ns-import' ); panel.open = state.importOpen || ( state.scope === 'strategy' && ! state.data.summary.strategy_urls ); panel.addEventListener( 'toggle', function () { state.importOpen = panel.open; } );
		panel.appendChild( el( 'summary', '', imports.length ? 'Strategy files · ' + imports.length + ( imports.length === 1 ? ' file · ' : ' files · ' ) + state.data.summary.strategy_urls + ' unique URLs' : 'Optional: import strategies' ) );
		var body = el( 'div', 'ns-import-body' ), file = input( 'file' ); file.accept = '.csv,text/csv'; file.multiple = true;
		control( body, 'Strategy CSV files', file, 'Select multiple CSVs with a url column. Up to 10 MB per batch; 50 files and 10,000 rows total. Files must target the same website. Saved layout mappings are kept.' );
		body.appendChild( button( 'Upload strategy files', 'button button-primary', function () {
			var files = Array.from( file.files );
			if ( ! files.length || files.length + imports.length > 50 || files.reduce( function ( total, f ) { return total + f.size; }, 0 ) > 10 * 1024 * 1024 ) { notice( 'Choose CSV files up to 10 MB combined; keep at most 50 imported files.', true ); return; }
			if ( state.dirty ) { notice( 'Save your mapping changes before changing strategy files.', true ); return; }
			action( async function () { var batch = await Promise.all( files.map( async function ( f ) { return { name: f.name, csv: await f.text() }; } ) ); await api( '/import', { files: batch } ); setScope( 'strategy' ); state.selected = ''; }, 'Strategy files imported. Showing their combined layouts.' );
		} ) );
		imports.forEach( function ( imported ) {
			var row = el( 'div', 'ns-import-file' ); row.appendChild( el( 'span', '', imported.name + ' · ' + imported.url_count + ' URLs' ) );
			var remove = button( 'Remove', 'button', function () {
				if ( state.dirty ) { notice( 'Save your mapping changes before changing strategy files.', true ); return; }
				action( function () { return api( '/remove-import', { id: imported.id } ); }, 'File removed. Saved layout mappings are kept.' );
			} ); remove.setAttribute( 'aria-label', 'Remove ' + imported.name ); row.appendChild( remove ); body.appendChild( row );
		} );
		if ( imports.length ) { body.appendChild( el( 'p', 'ns-help', 'Shared URLs stay in scope until their last file is removed. For duplicate URLs, the latest imported file supplies the page type and locale. Removing all files leaves the strategy scope empty; All site layouts remains available.' ) ); }
		panel.appendChild( body ); root.appendChild( panel );
	}

	async function loadLayout( entry, editor, reference ) {

		var epoch = ++state.epoch, ref = reference || entry.layout; state.frame = null; state.frameReady = false; state.statuses = {}; state.activeField = ''; editor.replaceChildren( el( 'p', '', 'Loading reference fields…' ) );

		try {

			var layout = await api( '/layout?reference_type=' + encodeURIComponent( ref.reference_type ) + '&reference_id=' + ref.reference_id + '&signature=' + entry.layout.signature, undefined, true );

			if ( epoch !== state.epoch ) { return; } state.layout = layout; renderEditor( entry, layout, editor );

		} catch ( e ) { if ( epoch !== state.epoch ) { return; } editor.replaceChildren( el( 'p', '', e.message ), button( 'Retry', 'button', function () { loadLayout( entry, editor, reference ); } ) ); }

	}

	function renderEditor( entry, layout, parent ) {

		parent.replaceChildren(); var saved = layout.profile || {}, top = el( 'div', 'ns-editor-head' ); top.appendChild( el( 'h2', '', saved.label || layout.title || 'Untitled layout' ) );

		var examples = control( top, 'Rendered reference', select( entry.layout.members.map( function ( m ) { return [ m.reference_type + ':' + m.reference_id, ( m.title || '#' + m.reference_id ) + ' · ' + m.post_status ]; } ), layout.reference_type + ':' + layout.reference_id ) );

		examples.addEventListener( 'change', function () { if ( ! canLeave() ) { examples.value = layout.reference_type + ':' + layout.reference_id; return; } state.dirty = false; var ref = entry.layout.members.find( function ( m ) { return m.reference_type + ':' + m.reference_id === examples.value; } ); loadLayout( entry, parent, ref ); } ); parent.appendChild( top );

		var affected = el( 'details', 'ns-details' ); affected.appendChild( el( 'summary', '', 'Used by ' + entry.layout.members.length + ' site items · ' + entry.rows.length + ' strategy URLs' ) );

		entry.layout.members.forEach( function ( m ) { affected.appendChild( el( 'p', 'ns-help', m.path || m.title + ' (draft/private reference)' ) ); } ); parent.appendChild( affected );

		entry.rows.filter( function ( r ) { return r.status === 'needs_parent' || r.status === 'needs_layout'; } ).forEach( function ( r ) { parent.appendChild( el( 'p', 'ns-notice', r.path + ': ' + r.basis + ' You can map this reference now.' ) ); } );

		var failed = arr( layout.providers ).filter( function ( p ) { return ! p.available; } );

		failed.forEach( function ( p ) {
			var warning = el( 'div', 'ns-notice ns-error' ); warning.setAttribute( 'role', 'status' );
			warning.appendChild( el( 'p', '', p.label + ': ' + p.message + ' The field inventory is incomplete.' ) );
			if ( p.module_enabled === false ) { warning.appendChild( enableBridgeButton( p ) ); }
			parent.appendChild( warning );
		} );

		if ( layout.unbound_fields ) { parent.appendChild( el( 'p', 'ns-notice ns-error', layout.unbound_fields + ' saved fields cannot be rebound to this reference. Choose the original mapped reference before saving; existing mappings are preserved.' ) ); }

		var label = control( parent, 'Layout name', input( 'text', saved.label || layout.title ), 'A name that describes where NOVA should put content.' );

		var guidance = control( parent, 'Layout instructions', textarea( saved.guidance || '', 3 ) ); label.addEventListener( 'input', markDirty ); guidance.addEventListener( 'input', markDirty );

		var visual = el( 'div', 'ns-visual-workspace' ), page = el( 'section', 'ns-page-panel' ), inspector = el( 'section', 'ns-inspector' );

		page.appendChild( el( 'h3', '', 'Page preview' ) );

		var viewport = select( [ [ 'desktop', 'Fit panel' ], [ 'mobile', 'Mobile' ] ], 'desktop' ); viewport.setAttribute( 'aria-label', 'Preview viewport' ); viewport.addEventListener( 'change', function () { page.classList.toggle( 'ns-mobile-preview', viewport.value === 'mobile' ); } ); page.appendChild( viewport );

		var previewStatus = el( 'p', 'ns-preview-status ns-help', 'Loading the rendered page…' ); previewStatus.setAttribute( 'role', 'status' ); page.appendChild( previewStatus );

		if ( layout.preview_url ) {

			var frame = el( 'iframe', 'ns-page-frame' ); frame.title = 'Rendered reference: ' + ( layout.title || layout.reference_id ); frame.referrerPolicy = 'no-referrer';

			// Block form submission, popups and top navigation while retaining the actual theme render.

			frame.setAttribute( 'sandbox', 'allow-scripts allow-same-origin' ); frame.src = layout.preview_url; state.frame = frame; page.appendChild( frame );

			frame.addEventListener( 'load', function () { if ( state.frame === frame && ! state.frameReady ) { previewStatus.textContent = 'Waiting for preview controls. If the page shows an error, refresh the reference. Fields remain available here.'; } } );

		} else { previewStatus.textContent = 'No rendered preview is available. Use the field inspector.'; }

		inspector.appendChild( el( 'h3', '', 'Field inspector' ) ); var fieldSearch = input( 'search' ); fieldSearch.placeholder = 'Find a field…'; fieldSearch.setAttribute( 'aria-label', 'Find a field' ); inspector.appendChild( fieldSearch ); inspector.appendChild( el( 'p', 'ns-help', 'Full content includes intro and main content. Choose either part to map it separately. Leave empty omits this field; existing site content is not cleared.' ) );

		var choices = el( 'div', 'ns-region-choices' ); inspector.appendChild( choices );

		var list = el( 'div', 'ns-field-list' ), mappings = Object.assign( {}, saved.fields || {} );

		arr( layout.fields ).forEach( function ( f ) {

			var path = f.path || f.pointer; if ( ! path ) { return; }

			var mapping = Object.assign( { mapping: '', description: '' }, mappings[path] || {} ); mappings[path] = mapping;

			var row = el( 'div', 'ns-field' ); row.dataset.path = path; row.dataset.search = ( path + ' ' + ( f.label || '' ) + ' ' + ( f.current_value || '' ) ).toLowerCase();

			var pick = button( f.label || path, 'ns-field-select', function () { selectField( path, true ); } ); pick.setAttribute( 'aria-pressed', 'false' ); row.appendChild( pick );

			row.appendChild( el( 'span', 'ns-field-visibility ns-help', 'Available in inspector; checking page…' ) );

			if ( f.current_value !== undefined ) { row.appendChild( el( 'p', 'ns-current-value', f.current_value || '(empty)' ) ); }

			if ( ! f.writable ) { row.appendChild( el( 'span', 'ns-badge', 'No verified writer' ) ); }

			var sourceChoices = sources.slice(); if ( mapping.mapping && ! sourceChoices.some( function ( s ) { return s[0] === mapping.mapping; } ) ) { sourceChoices.push( [ mapping.mapping, mapping.mapping ] ); }

			var source = control( row, 'NOVA source', select( f.writable ? sourceChoices : sources.filter( function ( choice ) { return choice[0] === '' || choice[0] === 'leave_empty' || choice[0] === mapping.mapping; } ), mapping.mapping ) ); source.addEventListener( 'change', function () { mapping.mapping = source.value; markDirty(); } );

			var notes = control( row, 'Field instructions', textarea( mapping.description, 2 ) ); notes.placeholder = 'Explain how to fill or preserve this field…'; notes.addEventListener( 'input', function () { mapping.description = notes.value; markDirty(); } );

			var technical = el( 'details', 'ns-field-technical' ); technical.appendChild( el( 'summary', '', 'Field details' ) ); technical.appendChild( el( 'code', '', path ) ); if ( f.native_description ) { technical.appendChild( el( 'p', '', f.native_description ) ); } if ( f.route ) { technical.appendChild( el( 'p', '', f.route + ' · ' + ( f.request_path || '' ) ) ); } row.appendChild( technical ); list.appendChild( row );

		} );

		fieldSearch.addEventListener( 'input', function () { list.querySelectorAll( '.ns-field' ).forEach( function ( row ) { row.hidden = row.dataset.search.indexOf( fieldSearch.value.trim().toLowerCase() ) < 0; } ); } );

		inspector.appendChild( list ); visual.appendChild( page ); visual.appendChild( inspector ); parent.appendChild( visual );

		var footer = el( 'div', 'ns-savebar' ), save = button( 'Save layout mapping', 'button button-primary', function () {

			if ( state.busy ) { return; } var output = {}; Object.keys( mappings ).forEach( function ( p ) { var m = mappings[p]; if ( m.mapping || m.description.trim() ) { output[p] = m; } } ); save.disabled = true;

			action( function () { return api( '/profiles', { signature: layout.signature, reference_type: layout.reference_type, reference_id: layout.reference_id, label: label.value, guidance: guidance.value, fields: output } ); }, 'Layout mapping saved.' ).finally( function () { save.disabled = !! layout.unbound_fields; } );

		} ); save.disabled = !! layout.unbound_fields; footer.appendChild( save ); footer.appendChild( el( 'span', 'ns-save-state', 'Changes are saved per layout.' ) ); parent.appendChild( footer );

	}

	function postPreview( type, data ) {

		if ( ! state.frame || ! state.layout ) { return; }

		var origin; try { origin = new URL( state.layout.preview_url ).origin; } catch ( e ) { return; }

		state.frame.contentWindow.postMessage( Object.assign( { source: 'nova-mapping-admin', type: type, referenceId: state.layout.reference_id }, data || {} ), origin );

	}

	function selectField( path, fromInspector ) {

		state.activeField = path;

		root.querySelectorAll( '.ns-field' ).forEach( function ( row ) { var match = row.dataset.path === path; row.classList.toggle( 'is-selected', match ); row.querySelector( '.ns-field-select' ).setAttribute( 'aria-pressed', String( match ) ); if ( match && ! fromInspector ) { row.hidden = false; row.scrollIntoView( { behavior: 'smooth', block: 'nearest' } ); } } );

		if ( fromInspector ) { var issue = root.querySelector('.ns-region-choices'); if(issue){issue.replaceChildren();} postPreview( 'select', { path: path } ); }

	}

	function previewMessage( event ) {

		if ( ! state.frame || ! state.layout || event.source !== state.frame.contentWindow || event.origin !== new URL( state.layout.preview_url ).origin || ! event.data || event.data.source !== 'nova-mapping-preview' || event.data.referenceId !== state.layout.reference_id || event.data.referenceType !== state.layout.reference_type ) { return; }

		var data = event.data, status = root.querySelector( '.ns-preview-status' );

		if ( data.type === 'ready' ) { state.frameReady = true; status.textContent = 'Click a highlighted region to inspect its fields. Links and forms are disabled.'; postPreview( 'bind', { builders: state.layout.builders, providers: state.layout.providers, fields: arr( state.layout.fields ).map( function ( f ) { return { path: f.path, source: f.source, builder: f.builder, element: f.element, selector_data: f.selector_data }; } ) } ); }

		if ( data.type === 'unbound' && typeof data.label === 'string' ) {
			selectField( '', false );
			var panel = root.querySelector( '.ns-region-choices' ), provider = arr( state.layout.providers ).find( function ( p ) { return p.id === data.builder; } );
			panel.replaceChildren(); panel.setAttribute( 'role', 'status' );
			panel.appendChild( el( 'strong', '', data.label.slice(0,140) ) );
			var message = 'This looks like content, but NOVA has no verified field binding for it. It has not been added as a writable field.';
			if ( provider && ! provider.available ) { message = provider.message; }
			else if ( data.builder === 'gutenberg' ) { message = 'This is part of a Gutenberg document. The bridge reads and updates the complete block document; this block is not an independent write target.'; }
			else if ( data.builder ) { message = 'This content has no verified visual binding. Check the existing fields below; a custom or dynamic source may require additional support.'; }
			panel.appendChild( el( 'p', 'ns-help', message ) );
			if ( provider && ! provider.available && provider.module_enabled === false ) {
				panel.appendChild( enableBridgeButton( provider ) );
				var link = el( 'a', 'button', 'Open Modules' ); link.href = config.modulesUrl; panel.appendChild( link );
			}
			if ( data.builder === 'gutenberg' && ( ! provider || provider.available ) ) { var docField = arr(state.layout.fields).find(function(f){return f.builder === 'gutenberg' && f.element === 'document';}); if(docField){panel.appendChild(button('Select document content','button',function(){selectField(docField.path,true);}));} }
			panel.appendChild( button( 'Dismiss', 'button', function () { panel.replaceChildren(); postPreview('select',{path:''}); status.textContent='Select a page region or a field in the inspector.'; } ) );
			status.textContent = message;
		}
		if ( data.type === 'clear-issue' ) { root.querySelector('.ns-region-choices').replaceChildren(); }
		if ( data.type === 'bindings' && Array.isArray( data.fields ) ) {

			data.fields.forEach( function ( f ) { state.statuses[f.path] = f; } );

			root.querySelectorAll( '.ns-field' ).forEach( function ( row ) { var f = state.statuses[row.dataset.path]; row.querySelector( '.ns-field-visibility' ).textContent = f && f.visible ? ( f.precision === 'document' ? 'Whole document · not an individual block' : ( f.precision === 'widget' ? 'On page · widget region' : 'On page · click to highlight' ) ) : 'Not visible or not bound in this preview · available here'; } );

		}

		if ( data.type === 'pick' && Array.isArray( data.paths ) && data.paths.length ) {

			var known = data.paths.filter( function ( p ) { return arr( state.layout.fields ).some( function ( f ) { return f.path === p; } ); } ); if ( ! known.length ) { return; }

			selectField( known[0], false ); var box = root.querySelector( '.ns-region-choices' ); box.replaceChildren();

			if ( known.length > 1 ) { box.appendChild( el( 'p', 'ns-help', 'This region contains multiple fields. Choose one:' ) ); known.forEach( function ( path ) { var f = state.layout.fields.find( function ( f ) { return f.path === path; } ); box.appendChild( button( f.label || path, 'button', function () { selectField( path, true ); } ) ); } ); }

		}

		if ( data.type === 'selection' && data.path === state.activeField && ! data.visible ) { status.textContent = 'This field is not visible or has no verified page binding. Edit it in the inspector.'; }

		else if ( data.type === 'selection' && data.visible ) { status.textContent = 'Selected region highlighted on the page.'; }

	}

	function renderReference( entry, parent ) {

		var candidates = arr( entry.row.candidates || [] ), box = el( 'div', 'ns-reference' );
		var selectedRows = new Set( entry.rows.map( function ( r ) { return r.id; } ) );
		if ( entry.rows.length > 1 ) {
			box.appendChild( el( 'p', 'ns-help', 'These URLs share a parent path and reference candidates. Choose which URLs should use this example; map its layout once.' ) );
			var members = el( 'details' ); members.open = true; members.appendChild( el( 'summary', '', entry.rows.length + ' URLs in this reference group' ) );
			entry.rows.forEach( function ( r ) { var label = el( 'label', 'ns-reference-member' ), check = input( 'checkbox' ); check.checked = true; check.addEventListener( 'change', function () { if ( check.checked ) { selectedRows.add( r.id ); } else { selectedRows.delete( r.id ); } } ); label.appendChild( check ); label.appendChild( el( 'span', '', r.path ) ); members.appendChild( label ); } ); box.appendChild( members );
		}


		if ( entry.row.basis ) { box.appendChild( el( 'p', 'ns-help', String( entry.row.basis ).replace( /_/g, ' ' ) ) ); }

		candidates.forEach( function ( c ) {

			var item = el( 'div', 'ns-candidate' ); item.appendChild( el( 'strong', '', c.title || c.label || c.url || 'Reference content' ) ); item.appendChild( el( 'span', 'ns-help', c.url || c.path || '' ) );

			item.appendChild( button( 'Use this reference', 'button', function () { assign( c.reference_type || c.type || ( c.term_id || c.reference_term_id ? 'term' : 'post' ), c.reference_id || c.post_id || c.reference_post_id || c.term_id || c.reference_term_id || c.id ); } ) ); box.appendChild( item );

		} );

		if ( ! candidates.length ) { box.appendChild( el( 'p', '', 'No matching layout could be established from the site. Choose a known example below.' ) ); }

		var finder = el( 'div', 'ns-reference-finder' ), search = control( finder, 'Find an existing page or category', input( 'search' ) ); search.placeholder = 'Search by title, path or WordPress ID…';

		var choices = control( finder, 'Reference content', select( [ [ '', 'Choose existing content…' ] ], '' ) );

		function updateReferences() { var query = search.value.trim().toLowerCase(), refs = arr( state.data.references || [] ).filter( function ( ref ) { return ( String( ref.title || '' ) + ' ' + String( ref.path || ref.url || '' ) + ' ' + ref.reference_id ).toLowerCase().indexOf( query ) !== -1; } ).slice( 0, 100 ); choices.replaceChildren(); var blank = el( 'option', '', 'Choose existing content…' ); blank.value = ''; choices.appendChild( blank ); refs.forEach( function ( ref ) { var option = el( 'option', '', ( ref.reference_type === 'term' ? 'Category: ' : '' ) + ref.title + ' — ' + ( ref.path || ref.url ) ); option.value = ref.reference_type + ':' + ref.reference_id; choices.appendChild( option ); } ); }

		search.addEventListener( 'input', updateReferences ); updateReferences(); finder.appendChild( button( 'Use selected reference for this group', 'button button-primary', function () { if ( ! choices.value ) { notice( 'Choose reference content first.', true ); return; } var parts = choices.value.split( ':' ); assign( parts[ 0 ], Number( parts[ 1 ] ) ); } ) ); box.appendChild( finder );

		var manual = el( 'details', 'ns-details' ); manual.open = ! candidates.length; manual.appendChild( el( 'summary', '', 'Choose a different reference' ) );

		var type = control( manual, 'Reference type', select( [ [ 'post', 'Page, post or custom post type' ], [ 'term', 'WooCommerce product category' ] ], 'post' ) );

		var id = control( manual, 'WordPress ID', input( 'number' ), 'Open the item in WordPress and use post=123 or tag_ID=123 from its edit URL.' ); id.min = 1; id.step = 1;

		manual.appendChild( button( 'Use reference', 'button', function () { if ( ! /^\d+$/.test( id.value ) || Number( id.value ) < 1 ) { notice( 'Enter a valid WordPress ID.', true ); return; } assign( type.value, Number( id.value ) ); } ) ); box.appendChild( manual ); parent.appendChild( box );

		function assign( refType, refId ) { if ( ! selectedRows.size ) { notice( 'Select at least one URL for this reference.', true ); return; } action( function () { return api( '/assign', { row_ids: Array.from( selectedRows ), reference_type: refType, reference_id: Number( refId ) } ); }, 'Reference selected for this URL group. You can now map its layout.' ); }

	}



	function init() { root = document.getElementById( 'nova-strategy-app' ); config = window.NovaStrategyAdmin; if ( ! root || ! config ) { return; } try { var savedScope = localStorage.getItem( 'nova-mapping-scope:' + config.mappingUrl ); if ( savedScope === 'all' || savedScope === 'strategy' ) { state.scope = savedScope; scopeChosen = true; } } catch ( e ) {} refresh().catch( function ( e ) { notice( e.message, true ); root.appendChild( button( 'Retry', 'button', init ) ); } ); }

	window.addEventListener( 'message', previewMessage );

	window.addEventListener( 'beforeunload', function ( e ) { if ( state.dirty ) { e.preventDefault(); e.returnValue = ''; } } );

	if ( document.readyState === 'loading' ) { document.addEventListener( 'DOMContentLoaded', init ); } else { init(); }

}() );
