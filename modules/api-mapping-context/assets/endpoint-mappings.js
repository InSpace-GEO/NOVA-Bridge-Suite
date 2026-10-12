( function ( root, factory ) {
	'use strict';
	var api = factory();
	if ( typeof module === 'object' && module.exports ) { module.exports = api; }
	if ( root ) { root.NovaEndpointMappingsEditor = api; }
	if ( typeof document !== 'undefined' ) {
		function start() { var host = document.getElementById( 'nova-endpoint-mappings-app' ); if ( host && root.NovaEndpointMappings ) { api.mount( host, root.NovaEndpointMappings ); } }
		if ( document.readyState === 'loading' ) { document.addEventListener( 'DOMContentLoaded', start ); } else { start(); }
	}
}( typeof window === 'undefined' ? null : window, function () {
	'use strict';
	function copy( value ) { return JSON.parse( JSON.stringify( value ) ); }
	function same( left, right ) { return JSON.stringify( left ) === JSON.stringify( right ); }
	function message( value ) { return typeof value === 'string' ? value : value && ( value.message || value.reason ) || 'This document needs review.'; }
	function referenceType( resource ) { return resource.type === 'taxonomy' || resource.kind === 'term' ? 'term' : 'post'; }
	function referenceKey( type, id ) { return type + ':' + id; }
	function parseReference( key ) { var match = /^(post|term):(0|[1-9][0-9]*)$/.exec( key ); if ( ! match || ! Number.isSafeInteger( Number( match[2] ) ) ) { throw new Error( 'Choose a valid content item.' ); } return { reference_type: match[1], reference_id: Number( match[2] ) }; }
	function catalog( value ) {
		if ( ! value || ! Array.isArray( value.resources ) ) { throw new Error( 'The endpoint checklist could not be verified. Reload to try again.' ); }
		var ids = new Set();
		return value.resources.map( function ( resource ) {
			if ( ! resource || typeof resource.id !== 'string' || ! resource.id || ids.has( resource.id ) || typeof resource.label !== 'string' || ! Array.isArray( resource.targets ) ) { throw new Error( 'The endpoint checklist contains an unsupported resource.' ); }
			ids.add( resource.id );
			var result = copy( resource );
			result.targets.forEach( function ( target ) { if ( ! [ 'post', 'term' ].includes( target.reference_type ) || ! Number.isSafeInteger( target.reference_id ) || target.reference_id < 1 || typeof target.title !== 'string' ) { throw new Error( 'The endpoint reference list could not be verified.' ); } } );
			if ( result.default_mapping && ( typeof result.default_mapping.id !== 'string' || ! Number.isInteger( result.default_mapping.revision ) || result.default_mapping.revision < 1 ) ) { throw new Error( 'A saved default has an unsupported identity.' ); }
			return result;
		} );
	}
	function comingSoon( resource ) { return resource.coming_soon === true || resource.id === 'post_type:product' || resource.post_type === 'product'; }
	function coverage( resources ) {
		var available = resources.filter( function ( resource ) { return ! comingSoon( resource ); } ), managed = available.filter( function ( resource ) { return resource.system_managed === true; } ), automatic = managed.filter( function ( resource ) { return resource.native_mapping_available !== false; } );
		var manual = available.filter( function ( resource ) { return ! resource.system_managed && resource.require_creation_default !== false && resource.creation_supported === true; } ), saved = manual.filter( function ( resource ) { return !! resource.default_mapping; } );
		var unavailable = available.filter( function ( resource ) { return ! resource.system_managed && resource.require_creation_default !== false && resource.creation_supported !== true; } ).length + managed.length - automatic.length;
		return { total: resources.length, supported: manual.length, saved: saved.length, missing: manual.length - saved.length, managed: automatic.length, coming_soon: resources.length - available.length, unavailable: unavailable, complete: manual.length + managed.length > 0 && manual.length === saved.length && managed.length === automatic.length };
	}
	function documentMapping( value, resourceId ) {
		if ( ! value || value.format !== 'nova-document-mapping/v1' || value.resource_id !== resourceId || ! value.target || ! value.provider || ! value.structure || ! value.baseline || ! /^[a-f0-9]{64}$/.test( value.baseline.structure_sha256 || '' ) || ! /^[a-f0-9]{64}$/.test( value.baseline.content_sha256 || '' ) || ! value.fields || Array.isArray( value.fields ) ) { throw new Error( 'The full document mapping could not be verified. Your choices are kept.' ); }
		Object.keys( value.fields ).forEach( function ( path ) { var field = value.fields[ path ]; if ( ! field || field.path !== path || typeof field.label !== 'string' || ! Object.prototype.hasOwnProperty.call( field, 'value' ) || ! Object.prototype.hasOwnProperty.call( field, 'current_value' ) || typeof field.writable !== 'boolean' || typeof field.protected !== 'boolean' ) { throw new Error( 'The document returned an unsupported content field.' ); } } );
		return value;
	}
	function readResult( result, resourceId, reference ) {
		if ( ! result || result.resource_id !== resourceId || ! /^[a-f0-9]{64}$/.test( result.document_version || '' ) || ! /^[a-f0-9]{64}$/.test( result.source_document_version || '' ) ) { throw new Error( 'The source document version could not be verified.' ); }
		var mapping = documentMapping( result.mapping, resourceId );
		if ( mapping.target.reference_type !== reference.reference_type || mapping.target.reference_id !== reference.reference_id ) { throw new Error( 'The returned document belongs to a different content item. Nothing was selected.' ); }
		return result;
	}
	function writableFields( mapping ) { return Object.values( mapping.fields ).filter( function ( field ) { return field.writable && ! field.protected; } ).sort( function ( a, b ) { return Number( ! a.builder ) - Number( ! b.builder ); } ); }
	function defaultSelection( mapping ) { return writableFields( mapping ).filter( function ( field ) { return typeof field.value === 'string'; } ).map( function ( field ) { return field.path; } ); }
	function sourcePayload( state ) {
		if ( ! state.source ) { throw new Error( 'Read a source before preparing this default.' ); }
		var reference = parseReference( state.reference ), mapping = state.source.mapping;
		if ( ! state.selected.length ) { throw new Error( 'Select at least one content field to empty for new content.' ); }
		state.selected.forEach( function ( path ) { var field = mapping.fields[ path ]; if ( ! field || ! field.writable || field.protected ) { throw new Error( 'A selected content slot is no longer supported. Review it before saving.' ); } } );
		return { resource_id: state.resourceId, reference_type: reference.reference_type, reference_id: reference.reference_id, selected_fields: state.selected.slice().sort(), source_document_version: state.source.source_document_version };
	}
	function savePayload( state ) {
		var result = sourcePayload( state ); result.expected_revision = state.revision; result.label = state.label.trim(); result.instructions = state.instructions;
		if ( ! result.label ) { throw new Error( 'Give this default a name.' ); }
		result.field_descriptions = {};
		result.selected_fields.forEach( function ( path ) { result.field_descriptions[ path ] = copy( state.descriptions[ path ] || { purpose: '', instructions: '' } ); } );
		return result;
	}
	function emptyValue( value ) { return typeof value === 'string' ? '' : typeof value === 'number' ? 0 : typeof value === 'boolean' ? false : value === null ? null : []; }
	function previewResult( result, submitted, source ) {
		if ( ! result || result.resource_id !== submitted.resource_id || result.saved !== false ) { throw new Error( 'The blank preview could not be verified. No default was saved.' ); }
		var mapping = documentMapping( result.mapping, submitted.resource_id );
		if ( mapping.mode !== 'creation' || mapping.target.reference_id !== 0 || mapping.target.reference_type !== submitted.reference_type || result.source_document_version !== submitted.source_document_version ) { throw new Error( 'The preview did not return a new-content template for this source.' ); }
		submitted.selected_fields.forEach( function ( path ) { var field = mapping.fields[ path ], blank = source ? emptyValue( source.fields[ path ].value ) : emptyValue( field && field.value ); if ( ! field || ! same( field.value, blank ) || ! same( field.current_value, blank ) ) { throw new Error( 'A selected field is not empty in the preview. Review the template before saving.' ); } } );
		return result;
	}
	function savedResult( result, submitted ) {
		if ( ! result || result.saved !== true || result.resource_id !== submitted.resource_id || typeof result.mapping_id !== 'string' || ! result.mapping_id || result.revision !== submitted.expected_revision + 1 || result.reference_type !== submitted.reference_type || result.reference_id !== submitted.reference_id || ! Array.isArray( result.selected_fields ) || ! same( result.selected_fields.slice().sort(), submitted.selected_fields ) ) { throw new Error( 'The save response could not be verified. Your input is kept; reload the saved default before retrying.' ); }
		var mapping = documentMapping( result.mapping, submitted.resource_id );
		if ( mapping.mode !== 'creation' || mapping.target.reference_id !== 0 || mapping.target.reference_type !== submitted.reference_type || result.source_document_version !== submitted.source_document_version ) { throw new Error( 'The saved response is not a blank creation default for this source.' ); }
		previewResult( Object.assign( {}, result, { saved: false } ), submitted );
		return result;
	}
	function safePreview( value, origin ) { try { var url = new URL( value, origin ); return [ 'http:', 'https:' ].includes( url.protocol ) && url.origin === new URL( origin ).origin ? url.href : ''; } catch ( error ) { return ''; } }
	async function request( base, suffix, nonce, body ) {
		var options = { method: body === undefined ? 'GET' : 'POST', credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json', 'X-WP-Nonce': nonce } };
		if ( body !== undefined ) { options.headers['Content-Type'] = 'application/json'; options.body = JSON.stringify( body ); }
		var response = await fetch( base + suffix, options ), value;
		try { value = await response.json(); } catch ( error ) { throw new Error( 'WordPress returned an unreadable response. Your input is kept.' ); }
		if ( ! response.ok ) { var failure = new Error( value && value.message || 'WordPress could not complete this request (' + response.status + ').' ); failure.status = response.status; failure.code = value && value.code || ''; throw failure; }
		return value;
	}
	function mount( host, config ) {
		var doc = host.ownerDocument, base = String( config.url || '' ).replace( /\/$/, '' ), disposed = false, epoch = 0, catalogEpoch = 0;
		var state = { resources: [], resourceId: '', mode: '', step: 1, busy: false, loading: true, reference: '', source: null, preview: null, previewKey: '', selected: [], selectionInitialized: false, needsSourceRefresh: false, descriptions: {}, label: '', instructions: '', revision: 0, dirty: false, done: false, notice: '', error: false, inspectPath: '', descriptionPath: '' };
		function el( tag, name, text ) { var node = doc.createElement( tag ); if ( name ) { node.className = name; } if ( text !== undefined ) { node.textContent = String( text ); } return node; }
		function button( text, action, primary ) { var node = el( 'button', 'button' + ( primary ? ' button-primary' : '' ), text ); node.type = 'button'; node.disabled = state.busy; node.addEventListener( 'click', action ); return node; }
		function control( parent, label, input, help ) { var group = el( 'div', 'nem-control' ), title = el( 'label', '', label ); title.appendChild( input ); group.appendChild( title ); if ( help ) { group.appendChild( el( 'p', 'nem-help', help ) ); } parent.appendChild( group ); return input; }
		function select( options, value ) { var node = el( 'select' ); options.forEach( function ( option ) { var child = el( 'option', '', option.label ); child.value = option.value; node.appendChild( child ); } ); node.value = value || ''; return node; }
		function input( value ) { var node = el( 'input' ); node.type = 'text'; node.value = value || ''; return node; }
		function textarea( value ) { var node = el( 'textarea' ); node.value = value || ''; return node; }
		function note( text, error ) { state.notice = text; state.error = !! error; }
		function resource() { return state.resources.find( function ( item ) { return item.id === state.resourceId; } ); }
		function canLeave() { return ! state.dirty || typeof window === 'undefined' || window.confirm( 'You have unsaved default-template choices. Discard them?' ); }
		function changed() { state.dirty = true; state.done = false; }
		function warnings( parent, list ) { if ( ! Array.isArray( list ) || ! list.length ) { return; } var box = el( 'div', 'nem-warnings' ); list.forEach( function ( item ) { box.appendChild( el( 'p', '', message( item ) ) ); } ); parent.appendChild( box ); }
		function details( parent, title, value ) { var box = el( 'details' ); box.append( el( 'summary', '', title ), el( 'pre', '', typeof value === 'string' ? value : JSON.stringify( value, null, 2 ) ) ); parent.appendChild( box ); }
		function previewCurrent() { try { return !! state.preview && state.previewKey === JSON.stringify( sourcePayload( state ) ); } catch ( error ) { return false; } }
		async function loadCatalog( initial ) {
			var turn = ++catalogEpoch;
			try { var values = catalog( await request( base, '/endpoints', config.nonce ) ); if ( disposed || turn !== catalogEpoch ) { return; } state.resources = values; }
			catch ( error ) { if ( ! disposed && turn === catalogEpoch ) { note( error.message, true ); } }
			finally { if ( ! disposed && turn === catalogEpoch ) { state.loading = false; render(); } }
		}
		async function open( resourceId, mode ) {
			var chosen = state.resources.find( function ( item ) { return item.id === resourceId; } );
			if ( ! chosen || comingSoon( chosen ) || chosen.system_managed || ! [ 'creation', 'inspect' ].includes( mode ) || state.busy || ! canLeave() ) { return; }
			var turn = ++epoch; Object.assign( state, { resourceId: resourceId, mode: mode, step: 1, busy: mode === 'creation', reference: referenceKey( referenceType( chosen ), 0 ), source: null, preview: null, previewKey: '', selected: [], selectionInitialized: false, needsSourceRefresh: false, descriptions: {}, label: chosen.label + ' default', instructions: '', revision: 0, dirty: false, done: false, notice: '', error: false, inspectPath: '', descriptionPath: '' } ); render();
			if ( mode !== 'creation' ) { return; }
			try {
				var saved = await request( base, '/creation?resource_id=' + encodeURIComponent( resourceId ), config.nonce );
				if ( disposed || turn !== epoch ) { return; }
				if ( saved.resource_id !== resourceId || ! Number.isInteger( saved.revision ) || saved.revision < 0 || saved.revision > 0 && ( ! saved.mapping || ! Array.isArray( saved.selected_fields ) ) ) { throw new Error( 'The saved default could not be verified. Reload before making changes.' ); }
				state.revision = saved.revision;
				if ( saved.mapping ) { documentMapping( saved.mapping, resourceId ); state.reference = referenceKey( saved.reference_type, saved.reference_id ); parseReference( state.reference ); state.selected = saved.selected_fields.slice(); state.selectionInitialized = true; state.descriptions = copy( saved.field_descriptions || {} ); state.label = saved.label || state.label; state.instructions = saved.instructions || ''; if ( saved.needs_refresh ) { state.needsSourceRefresh = true; note( 'The saved source or endpoint schema changed. Read the current source and review its slots before saving a refreshed default.', true ); } }
			} catch ( error ) { if ( ! disposed && turn === epoch ) { note( error.message, true ); state.mode = 'load_error'; } }
			finally { if ( ! disposed && turn === epoch ) { state.busy = false; render(); } }
		}
		async function readSource( inspect ) {
			if ( state.busy ) { return; } var chosen = resource(), reference;
			try { reference = parseReference( state.reference ); if ( inspect && reference.reference_id === 0 ) { throw new Error( 'Choose an existing item to inspect.' ); } } catch ( error ) { note( error.message, true ); render(); return; }
			var turn = ++epoch; state.busy = true; render();
			try {
				var reply = readResult( await request( base, '/existing?resource_id=' + encodeURIComponent( chosen.id ) + '&reference_type=' + reference.reference_type + '&reference_id=' + reference.reference_id, config.nonce ), chosen.id, reference );
				if ( disposed || turn !== epoch ) { return; } state.source = reply; state.inspectPath = Object.keys( reply.mapping.fields )[0] || '';
				if ( ! inspect ) { if ( ! state.selectionInitialized ) { state.selected = defaultSelection( reply.mapping ); state.selectionInitialized = true; } state.preview = null; state.previewKey = ''; state.needsSourceRefresh = false; state.step = 2; changed(); }
				note( inspect ? 'Filled mapping read from this existing item. No mapping or content was saved.' : 'Reference loaded. Select which content slots should be empty in the new-content default.' );
			} catch ( error ) { if ( ! disposed && turn === epoch ) { note( error.message, true ); } }
			finally { if ( ! disposed && turn === epoch ) { state.busy = false; render(); } }
			if ( ! disposed && turn === epoch && ! inspect && state.source && state.selected.length && state.step === 2 ) { await blankPreview(); }
		}
		async function blankPreview() {
			if ( state.busy ) { return; } var submitted;
			try { submitted = sourcePayload( state ); } catch ( error ) { note( error.message, true ); render(); return; }
			var turn = ++epoch; state.busy = true; render();
			try { var reply = previewResult( await request( base, '/creation/preview', config.nonce, submitted ), submitted, state.source.mapping ); if ( disposed || turn !== epoch ) { return; } if ( JSON.stringify( sourcePayload( state ) ) === JSON.stringify( submitted ) ) { state.preview = reply; state.previewKey = JSON.stringify( submitted ); note( 'Blank preview ready. Selected content is empty in the native structure too.' ); } }
			catch ( error ) { if ( ! disposed && turn === epoch ) { note( error.message, true ); state.needsSourceRefresh = /source_changed|needs_refresh/.test( error.code || '' ); state.preview = null; state.previewKey = ''; } }
			finally { if ( ! disposed && turn === epoch ) { state.busy = false; render(); } }
		}
		async function save() {
			if ( state.busy || state.mode !== 'creation' || state.step !== 4 || state.needsSourceRefresh || ! previewCurrent() ) { return; }
			var submitted; try { submitted = savePayload( state ); } catch ( error ) { note( error.message, true ); render(); return; }
			var turn = ++epoch; state.busy = true; render();
			try {
				var saved = savedResult( await request( base, '/creation', config.nonce, submitted ), submitted ); if ( disposed || turn !== epoch ) { return; }
				var unchanged = same( savePayload( state ), submitted ); state.revision = saved.revision; state.dirty = ! unchanged; state.done = unchanged;
				note( unchanged ? 'Default saved locally for ' + resource().label + '. Its source content was not changed.' : 'The earlier default was saved. Your newer choices remain unsaved.' );
				await loadCatalog( false );
			} catch ( error ) { if ( ! disposed && turn === epoch ) { state.needsSourceRefresh = /source_changed|needs_refresh/.test( error.code || '' ); note( error.status === 409 && ! state.needsSourceRefresh ? 'This default changed elsewhere. Your choices are kept. Reload the saved default and reconcile its revision before retrying.' : error.message + ( state.needsSourceRefresh ? ' Your choices are kept; read the current source before retrying.' : '' ), true ); } }
			finally { if ( ! disposed && turn === epoch ) { state.busy = false; render(); } }
		}
		function close() { if ( state.busy || ! canLeave() ) { return; } ++epoch; state.mode = ''; state.resourceId = ''; state.dirty = false; state.notice = ''; render(); }
		function goStep( step ) { if ( state.busy || state.done || step > state.step || step < 1 || step > 4 ) { return; } state.step = step; render(); }
		function advance( step ) { if ( state.busy || state.done || state.needsSourceRefresh ) { return; } if ( step === 3 && state.step === 2 && previewCurrent() ) { state.step = 3; state.descriptionPath = state.selected.includes( state.descriptionPath ) ? state.descriptionPath : state.selected[0]; render(); } if ( step === 4 && state.step === 3 && previewCurrent() ) { state.step = 4; render(); } }
		function chooseReference( value ) { if ( state.busy || ! canLeave() ) { return false; } state.reference = value; state.source = null; state.preview = null; state.previewKey = ''; state.selected = []; state.selectionInitialized = false; state.needsSourceRefresh = false; state.descriptions = {}; state.dirty = false; state.done = false; render(); return true; }
		function sourceChoice( parent, inspect ) {
			var chosen = resource(), values = inspect ? [ { value: '', label: 'Choose an existing item…' } ] : [ { value: referenceKey( referenceType( chosen ), 0 ), label: 'Use standard WordPress fields (no sample needed)' } ];
			chosen.targets.forEach( function ( item ) { values.push( { value: referenceKey( item.reference_type, item.reference_id ), label: item.title + ' · #' + item.reference_id + ( item.status ? ' · ' + item.status : '' ) } ); } );
			if ( state.reference && parseReference( state.reference ).reference_id > 0 && ! values.some( function ( item ) { return item.value === state.reference; } ) ) { values.push( { value: state.reference, label: 'Item #' + parseReference( state.reference ).reference_id + ' (verified when read)' } ); }
			var picker = control( parent, inspect ? 'Existing content item' : 'Start from', select( values, state.reference ), inspect ? 'This reads the actual filled document. It does not create or save a mapping.' : 'Use standard content fields, or take a sample from this destination to retain its native design.' );
			picker.addEventListener( 'change', function () { if ( ! chooseReference( picker.value ) ) { picker.value = state.reference; } } );
			if ( chosen.targets_complete === false ) { parent.appendChild( el( 'p', 'nem-help', 'The reference list is bounded. It may not include every item on a large website.' ) ); }
			var direct = el( 'details' ); direct.appendChild( el( 'summary', '', 'Use an item ID' ) ); var id = control( direct, 'WordPress item ID', input( '' ), 'Use the number from its WordPress edit URL. The lookup verifies that it belongs to ' + chosen.label + ' before reading it.' ); id.type = 'number'; id.min = 1; id.max = Number.MAX_SAFE_INTEGER; id.step = 1;
			direct.appendChild( button( 'Read item by ID', function () { var value = String( id.value ).trim(); if ( ! /^[1-9][0-9]*$/.test( value ) || ! Number.isSafeInteger( Number( value ) ) ) { note( 'Enter a positive WordPress item ID.', true ); render(); return; } if ( chooseReference( referenceKey( referenceType( chosen ), Number( value ) ) ) ) { readSource( inspect ); } } ) ); parent.appendChild( direct );
		}
		function referencePreview( parent ) {
			var current = state.source.mapping, origin = typeof window === 'undefined' ? base : window.location.href, url = safePreview( state.source.preview_url || current.preview_url, origin );
			var box = el( 'details', 'nem-preview' ); box.open = false; box.append( el( 'summary', '', 'View the sample reference (optional)' ), el( 'p', 'nem-help', 'This preview shows the filled sample. The blank template preview below shows what will be kept or emptied; the sample is unchanged.' ) );
			if ( url ) { var frame = el( 'iframe' ); frame.title = 'Read-only reference preview'; frame.src = url; box.appendChild( frame ); } else { box.appendChild( el( 'p', 'nem-help', 'No visual preview is available for this source. Its complete fields and native layout are available below.' ) ); }
			parent.appendChild( box );
		}
		function renderBlank( parent ) {
			if ( ! previewCurrent() ) { parent.appendChild( el( 'p', 'nem-help', 'Update the blank preview after changing the selected content slots.' ) ); return; }
			var mapping = state.preview.mapping, slots = el( 'div', 'nem-blank-slots' ); parent.append( el( 'h4', '', 'Blank default preview' ), el( 'p', 'nem-help', 'Selected content is empty. Native structure, styles, protected settings and unselected content are retained.' ) );
			state.selected.forEach( function ( path ) { var field = mapping.fields[ path ], slot = el( 'div', 'nem-blank-slot' ); slot.append( el( 'strong', '', field.label ), el( 'code', '', JSON.stringify( field.value ) ) ); slots.appendChild( slot ); } ); parent.appendChild( slots ); details( parent, 'View the complete blank native structure', mapping.structure ); warnings( parent, mapping.warnings );
		}
		function renderSelectFields( parent ) {
			var grid = el( 'div', 'nem-editor-grid' ), preview = el( 'div' ), choices = el( 'div' ); parent.append( el( 'h4', '', 'Choose the content slots for new content' ), el( 'p', '', 'Select the fields that should start empty. Unselected copy remains from the sample; protected design and settings stay intact.' ) ); referencePreview( preview ); renderBlank( preview ); grid.append( preview, choices ); parent.appendChild( grid );
			var list = el( 'div', 'nem-fields' ); choices.appendChild( list );
			writableFields( state.source.mapping ).forEach( function ( field ) { var selected = state.selected.includes( field.path ), card = el( 'div', 'nem-field' + ( selected ? ' selected' : '' ) ), label = el( 'label' ), checkbox = el( 'input' ); checkbox.type = 'checkbox'; checkbox.checked = selected; checkbox.dataset.path = field.path; label.append( checkbox, el( 'span', '', field.label ) ); card.appendChild( label ); card.appendChild( el( 'p', 'nem-help', selected ? 'Will start empty in the default.' : 'Will keep the sample value.' ) ); details( card, 'Read full sample value', field.value ); checkbox.addEventListener( 'change', function () { if ( state.busy ) { return; } state.selected = checkbox.checked ? Array.from( new Set( state.selected.concat( field.path ) ) ) : state.selected.filter( function ( path ) { return path !== field.path; } ); changed(); state.preview = null; state.previewKey = ''; render(); } ); list.appendChild( card ); } );
			state.selected.filter( function ( path ) { return ! state.source.mapping.fields[ path ] || ! state.source.mapping.fields[ path ].writable || state.source.mapping.fields[ path ].protected; } ).forEach( function ( path ) { var card = el( 'div', 'nem-field' ); card.append( el( 'p', '', 'A saved slot is no longer supported: ' + path ), button( 'Remove unavailable slot', function () { state.selected = state.selected.filter( function ( item ) { return item !== path; } ); changed(); state.preview = null; render(); } ) ); list.appendChild( card ); } );
			if ( ! writableFields( state.source.mapping ).length ) { choices.appendChild( el( 'p', 'nem-help', 'This source has no supported writable content slots. Return to choose standard fields or another sample.' ) ); }
			var kept = Object.values( state.source.mapping.fields ).filter( function ( field ) { return field.protected || ! field.writable; } ); choices.appendChild( el( 'p', 'nem-protected', kept.length + ' readonly or protected fields stay intact.' ) );
			choices.appendChild( button( 'Update blank preview', blankPreview ) ); warnings( choices, state.source.mapping.warnings );
		}
		function renderDescriptions( parent ) {
			parent.append( el( 'h4', '', 'Describe what belongs in these slots' ), el( 'p', 'nem-help', 'Write a purpose for the slots that need extra context. You can review the default without filling every description.' ) );
			var picker = control( parent, 'Content slot', select( state.selected.map( function ( path ) { return { value: path, label: state.source.mapping.fields[ path ].label }; } ), state.descriptionPath ) ); picker.addEventListener( 'change', function () { if ( state.busy ) { return; } state.descriptionPath = picker.value; render(); } );
			var path = state.descriptionPath || state.selected[0], description = state.descriptions[ path ] || ( state.descriptions[ path ] = { purpose: '', instructions: '' } );
			var purpose = control( parent, 'What content belongs here?', textarea( description.purpose ), 'For example: introduce the service, explain a benefit, or answer a common question.' ); purpose.maxLength = 2000; purpose.addEventListener( 'input', function () { description.purpose = purpose.value; changed(); } );
			var advanced = el( 'details' ); advanced.appendChild( el( 'summary', '', 'Optional writing instructions' ) ); var instructions = control( advanced, 'Instructions for this slot', textarea( description.instructions ) ); instructions.maxLength = 2000; instructions.addEventListener( 'input', function () { description.instructions = instructions.value; changed(); } ); details( advanced, 'Native field identity', path ); parent.appendChild( advanced );
			var global = control( parent, 'Instructions for the whole default', textarea( state.instructions ), 'Optional context shared by all of this default’s content slots.' ); global.maxLength = 8000; global.addEventListener( 'input', function () { state.instructions = global.value; changed(); } );
		}
		function renderInspector( parent ) {
			sourceChoice( parent, true ); parent.appendChild( button( 'Read filled mapping', function () { readSource( true ); }, true ) );
			if ( ! state.source ) { return; } var mapping = state.source.mapping;
			parent.append( el( 'p', 'nem-feedback', 'Read-only inspection of #' + mapping.target.reference_id + '. Full current values are shown; no saved layout-group mapping is required.' ), el( 'p', 'nem-help', 'Provider: ' + mapping.provider.id + ( mapping.provider.complete ? ' · complete document' : ' · needs review' ) ) ); warnings( parent, mapping.warnings );
			var paths = Object.keys( mapping.fields ); if ( ! paths.length ) { parent.appendChild( el( 'p', '', 'No supported content fields were returned.' ) ); return; }
			var picker = control( parent, 'Inspect content field', select( paths.map( function ( path ) { return { value: path, label: mapping.fields[ path ].label }; } ), state.inspectPath ) ); picker.addEventListener( 'change', function () { state.inspectPath = picker.value; render(); } );
			var field = mapping.fields[ state.inspectPath ] || mapping.fields[ paths[0] ], current = el( 'section', 'nem-inspector-field' ); current.append( el( 'h4', '', field.label ), el( 'p', 'nem-help', field.protected || ! field.writable ? 'Readonly or protected content.' : 'Available content slot. This inspector does not write it.' ), el( 'pre', '', typeof field.value === 'string' ? field.value : JSON.stringify( field.value, null, 2 ) ) ); parent.appendChild( current ); details( parent, 'Native layout and structure', mapping.structure ); details( parent, 'Document freshness markers', { document_version: state.source.document_version, baseline: mapping.baseline } );
		}
		function renderWorkspace( parent ) {
			if ( ! state.mode ) { return; } var chosen = resource(); if ( ! chosen || chosen.system_managed ) { return; } var workspace = el( 'section', 'nem-workspace' ), header = el( 'div', 'nem-workspace-head' ), text = el( 'div' ); text.append( el( 'h3', '', state.mode === 'inspect' ? 'Inspect existing ' + chosen.label : 'New-content default for ' + chosen.label ), el( 'p', 'nem-help', state.mode === 'inspect' ? 'Read the actual filled native document without configuring a layout group.' : 'This default prepares new items for this destination. Existing items use their own live filled document.' ) ); header.append( text, button( 'Back to all destinations', close ) ); workspace.appendChild( header ); parent.appendChild( workspace );
			if ( state.mode === 'load_error' ) { workspace.appendChild( button( 'Reload saved default', function () { open( chosen.id, 'creation' ); } ) ); return; }
			if ( state.done ) { var summary = coverage( state.resources ); workspace.append( el( 'h4', '', 'Default saved for ' + chosen.label ), el( 'p', '', summary.complete ? 'Every supported new-content destination has a saved default.' : summary.missing + ' supported destinations still need a default.' ), button( 'Continue with all destinations', close, true ) ); return; }
			var fieldset = el( 'fieldset' ); fieldset.disabled = state.busy; workspace.appendChild( fieldset );
			if ( state.mode === 'inspect' ) { renderInspector( fieldset ); return; }
			var steps = el( 'ol', 'nem-steps' ); steps.setAttribute( 'aria-label', 'Creation default setup' ); [ 'Choose a source', 'Blank content slots', 'Describe content', 'Review and save' ].forEach( function ( label, index ) { var item = el( 'li' ), action = button( ( index + 1 ) + '. ' + label, function () { goStep( index + 1 ); } ); action.disabled = state.busy || index + 1 > state.step; if ( index + 1 === state.step ) { action.setAttribute( 'aria-current', 'step' ); } item.appendChild( action ); steps.appendChild( item ); } ); workspace.insertBefore ? workspace.insertBefore( steps, fieldset ) : workspace.appendChild( steps );
			if ( state.step === 1 ) { var source = el( 'div', 'nem-source' ); sourceChoice( source, false ); source.appendChild( button( 'Use this source', function () { readSource( false ); }, true ) ); fieldset.appendChild( source ); }
			if ( state.step === 2 ) { renderSelectFields( fieldset ); }
			if ( state.step === 3 ) { renderDescriptions( fieldset ); }
			if ( state.step === 4 ) { var review = el( 'section', 'nem-review' ), name = control( review, 'Default name', input( state.label ) ); name.maxLength = 120; name.addEventListener( 'input', function () { state.label = name.value; changed(); } ); review.append( el( 'p', '', state.selected.length + ' content slots will start empty. The sample and existing site items stay unchanged.' ), el( 'p', 'nem-help', 'Only this destination’s local creation default is saved. No content is published or sent to NOVA.' ) ); renderBlank( review ); if ( state.instructions ) { details( review, 'Whole-default instructions', state.instructions ); } fieldset.appendChild( review ); }
			var footer = el( 'div', 'nem-savebar' ); if ( state.step > 1 ) { footer.appendChild( button( 'Back', function () { goStep( state.step - 1 ); } ) ); } if ( state.needsSourceRefresh ) { footer.appendChild( button( 'Read current source', function () { readSource( false ); } ) ); } if ( state.step === 2 ) { var describe = button( 'Describe selected slots', function () { advance( 3 ); }, true ); describe.disabled = state.busy || ! previewCurrent() || state.needsSourceRefresh; footer.appendChild( describe ); } if ( state.step === 3 ) { footer.appendChild( button( 'Review default', function () { advance( 4 ); }, true ) ); } if ( state.step === 4 ) { var saveButton = button( 'Save creation default', save, true ); saveButton.disabled = state.busy || ! previewCurrent() || state.needsSourceRefresh; footer.appendChild( saveButton ); footer.appendChild( button( 'Reload saved default', function () { open( chosen.id, 'creation' ); } ) ); } footer.appendChild( el( 'span', 'nem-help', state.busy ? 'Working…' : state.dirty ? 'Unsaved choices' : 'Saved revision ' + state.revision ) ); workspace.appendChild( footer );
		}
		function render() {
			if ( disposed ) { return; } host.replaceChildren(); host.setAttribute( 'aria-busy', String( state.busy || state.loading ) );
			var header = el( 'header', 'nem-head' + ( state.mode ? ' nem-head-focused' : '' ) ), introduction = el( 'p', '', 'Save a blank default for new Pages, Posts, categories and supported custom content. Existing items are read as filled mappings automatically, with their own content and layout.' ); introduction.hidden = !! state.mode; header.append( el( 'h2', '', state.mode ? 'Content destination setup' : 'Set up every content destination' ), introduction, el( 'p', 'nem-help', 'This prepares templates locally in WordPress. It does not change source items or start publication.' ) ); host.appendChild( header );
			if ( state.notice ) { var feedback = el( 'p', 'nem-feedback' + ( state.error ? ' error' : '' ), state.notice ); feedback.setAttribute( 'role', state.error ? 'alert' : 'status' ); host.appendChild( feedback ); }
			if ( state.loading ) { host.appendChild( el( 'p', '', 'Loading all content destinations…' ) ); return; }
			var summary = coverage( state.resources ), line = el( 'div', 'nem-summary' ); line.append( el( 'span', 'nem-badge ' + ( summary.complete ? 'saved' : 'pending' ), summary.supported ? summary.saved + ' of ' + summary.supported + ' client defaults set up' : 'No client defaults to set up' ), button( 'Refresh destinations', function () { if ( ! state.busy ) { loadCatalog( false ); } } ) ); if ( summary.managed ) { line.appendChild( el( 'span', 'nem-badge saved', summary.managed + ' mapped by NOVA' ) ); } if ( summary.unavailable ) { line.appendChild( el( 'span', 'nem-badge blocked', summary.unavailable + ' destinations need capability review' ) ); } host.appendChild( line );
			var grid = el( 'div', 'nem-resource-grid' ); grid.hidden = !! state.mode;
			state.resources.forEach( function ( item ) {
				var card = el( 'article', 'nem-resource' ), actions = el( 'div', 'nem-actions' ); card.appendChild( el( 'h3', '', item.label ) );
				if ( comingSoon( item ) ) { card.appendChild( el( 'span', 'nem-badge', 'Coming soon' ) ); grid.appendChild( card ); return; }
				if ( item.system_managed ) {
					var automatic = el( 'span', 'nem-badge saved' ), checkmark = el( 'span', 'nem-auto-check', '✓' ); checkmark.setAttribute( 'aria-hidden', 'true' ); automatic.append( checkmark, el( 'span', '', 'Automatically mapped by NOVA' ) ); card.appendChild( automatic ); grid.appendChild( card ); return;
				} else {
					card.append( el( 'span', 'nem-badge ' + ( item.default_mapping ? 'saved' : item.creation_supported ? 'pending' : 'blocked' ), item.default_mapping ? 'Default saved · revision ' + item.default_mapping.revision : item.creation_supported ? 'Default needed for new content' : 'Creation needs review' ), el( 'p', 'nem-help', 'Existing content uses its current filled native document.' ) );
					var setup = button( item.default_mapping ? 'Edit creation default' : 'Configure creation default', function () { open( item.id, 'creation' ); }, true ); setup.disabled = state.busy || item.creation_supported !== true; actions.appendChild( setup );
				}
				var inspect = button( 'Inspect existing content', function () { open( item.id, 'inspect' ); } ); inspect.disabled = state.busy || item.can_inspect_existing === false || ! item.targets.length; actions.appendChild( inspect ); card.appendChild( actions );
				if ( ! item.system_managed && ( item.creation_supported === false || item.can_inspect_existing === false ) && item.reasons && item.reasons.length ) { warnings( card, item.reasons ); }
				if ( item.endpoint || item.route ) { details( card, 'Destination details', item.endpoint || item.route ); } grid.appendChild( card );
			} ); host.appendChild( grid ); if ( ! state.resources.length ) { host.appendChild( el( 'p', '', 'No supported editable content destinations were discovered.' ) ); } renderWorkspace( host );
		}
		render(); var ready = loadCatalog( true );
		return { ready: ready, open: open, close: close, goStep: goStep, getState: function () { return copy( state ); }, destroy: function () { disposed = true; ++epoch; host.replaceChildren(); } };
	}
	return { mount: mount, catalog: catalog, coverage: coverage, parseReference: parseReference, readResult: readResult, defaultSelection: defaultSelection, sourcePayload: sourcePayload, savePayload: savePayload, previewResult: previewResult, savedResult: savedResult, safePreview: safePreview, request: request };
} ) );
