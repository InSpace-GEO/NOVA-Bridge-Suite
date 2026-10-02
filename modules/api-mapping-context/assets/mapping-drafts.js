( function ( root, factory ) {
	'use strict';
	var api = factory();
	if ( typeof module === 'object' && module.exports ) { module.exports = api; }
	if ( root ) { root.NovaMappingDrafts = api; }
}( typeof window === 'undefined' ? null : window, function () {
	'use strict';
	var controlId = 0;
	function copy( value ) { return JSON.parse( JSON.stringify( value ) ); }
	function list( value ) { return Array.isArray( value ) ? value : Object.keys( value || {} ).map( function ( key ) { return Object.assign( { path: key }, value[ key ] ); } ); }
	function sources( template ) { return list( template && template.fields ).map( function ( field ) { return Object.assign( {}, field, { source_path: field.source_path || field.key || '' } ); } ).filter( function ( field ) { return field.source_path; } ); }
	function members( group ) { return ( group.member_keys || [] ).map( function ( member ) { return typeof member === 'string' ? member : member.key; } ).filter( Boolean ); }
	function utf8Bytes( value ) { return new TextEncoder().encode( value || '' ).length; }
	function boundedText( value, limit ) { var result = '', size = 0; for ( var character of String( value || '' ) ) { size += utf8Bytes( character ); if ( size > limit ) { break; } result += character; } return result; }
	function slotUuid( value ) { return typeof value === 'string' && /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/.test( value ); }
	function destinationMode( draft ) { return draft.catalog_mode === 'destination'; }
	function describeField( field ) {
		var type = field.type || '', format = field.format || '', valueType = { html: 'rich_text', rich_text: 'rich_text', url: 'url', uri: 'url', email: 'email', image: 'image', list: 'list', link: 'link' }[ format ] || { wysiwyg: 'rich_text', rich_text: 'rich_text', url: 'url', email: 'email', image: 'image', gallery: 'list', link: 'link', array: 'list', list: 'list' }[ type ] || ( /editor|rich.?text/i.test( field.element || '' ) ? 'rich_text' : 'text' );
		if ( [ '/content', '/description' ].indexOf( field.path ) >= 0 ) { valueType = 'rich_text'; }
		return { label: boundedText( field.label || field.path, 200 ), purpose: boundedText( field.native_description, 2000 ), value_type: valueType, constraints: {} };
	}
	function adaptField( field, previous ) { return { mode: 'adapt', source_path: '', required: !! ( previous && previous.required ), instructions: previous && previous.instructions || '', description: previous && previous.description ? copy( previous.description ) : describeField( field ) }; }
	function prepareDestination( draft, template ) { if ( template ) { draft.catalog_snapshot = copy( template ); } if ( draft.guidance_mode === 'inherit' && template && template.authoring_notes ) { draft.guidance = template.authoring_notes; draft.guidance_mode = 'set'; } draft.catalog_mode = 'destination'; draft.template = { id: 'local-destination', revision: '1' }; draft.destination_groups = draft.destination_groups || []; }
	function groupUse( draft, path ) { return ( draft.destination_groups || [] ).some( function ( group ) { return group.slots.some( function ( slot ) { return slot.fields.indexOf( path ) >= 0; } ); } ); }
	function addDestinationSlot( draft, group ) { if ( group.slots.length >= 500 ) { throw new Error( 'A fixed group can describe at most 500 existing slots.' ); } var slot = { id: newSlotId(), ordinal: group.slots.length, fields: [] }; group.slots.push( slot ); return slot; }
	function assignDestinationField( draft, slot, path ) {
		if ( ! draft.fields[ path ] || draft.fields[ path ].mode !== 'adapt' ) { throw new Error( 'Choose an adapted destination field.' ); }
		if ( [ 'text', 'rich_text', 'url', 'email' ].indexOf( draft.fields[ path ].description.value_type ) < 0 ) { throw new Error( 'Fixed slots currently describe scalar destination fields only.' ); }
		if ( groupUse( draft, path ) ) { throw new Error( 'A destination field can belong to only one existing slot.' ); }
		slot.fields.push( path );
	}
	function newSlotId() {
		if ( typeof crypto === 'undefined' || typeof crypto.getRandomValues !== 'function' ) { throw new Error( 'Secure random slot IDs are unavailable. Use a supported HTTPS browser.' ); }
		if ( typeof crypto.randomUUID === 'function' ) { return crypto.randomUUID().toLowerCase(); }
		var bytes = crypto.getRandomValues( new Uint8Array( 16 ) ); bytes[ 6 ] = ( bytes[ 6 ] & 15 ) | 64; bytes[ 8 ] = ( bytes[ 8 ] & 63 ) | 128;
		var hex = Array.from( bytes, function ( byte ) { return byte.toString( 16 ).padStart( 2, '0' ); } ).join( '' );
		return hex.slice( 0, 8 ) + '-' + hex.slice( 8, 12 ) + '-' + hex.slice( 12, 16 ) + '-' + hex.slice( 16, 20 ) + '-' + hex.slice( 20 );
	}
	function initialDraft( layout, saved ) {
		if ( saved ) { var restored = copy( saved ); restored.guidance_mode = restored.guidance_mode || ( restored.guidance ? 'set' : 'inherit' ); restored.fields = ! restored.fields || Array.isArray( restored.fields ) ? {} : restored.fields; restored.repeat_slots = ! restored.repeat_slots || Array.isArray( restored.repeat_slots ) ? {} : restored.repeat_slots; Object.values( restored.repeat_slots ).forEach( function ( slots ) { slots.forEach( function ( slot ) { if ( ! slot.targets || Array.isArray( slot.targets ) ) { slot.targets = {}; } } ); } ); restored.skipped_sources = restored.skipped_sources || []; if ( destinationMode( restored ) ) { restored.destination_groups = restored.destination_groups || []; } restored.routing = restored.routing || { operation: 'update', locale: '' }; return restored; }
		return { revision: '', signature: layout.signature, reference_type: layout.reference_type || 'post', reference_id: Number( layout.reference_id || layout.reference_post_id || layout.reference_term_id || 0 ), template: { id: 'local-destination', revision: '1' }, catalog_mode: 'destination', label: layout.label || layout.title || '', guidance_mode: 'inherit', guidance: '', fields: {}, skipped_sources: [], repeat_slots: {}, destination_groups: [], routing: { operation: 'update', locale: '' } };
	}
	function resolveTemplate( draft, catalog ) {
		function exact( item ) { return draft.template && item && item.id === draft.template.id && String( item.revision ) === String( draft.template.revision ); }
		return ( catalog.templates || [] ).find( exact ) || ( exact( draft.catalog_snapshot ) ? draft.catalog_snapshot : undefined );
	}
	function payload( draft, reconciliation ) {
		var result = {};
		[ 'signature', 'reference_type', 'reference_id', 'template', 'catalog_mode', 'label', 'profile_page_type', 'guidance', 'fields', 'skipped_sources', 'repeat_slots', 'routing' ].forEach( function ( key ) { result[ key ] = copy( draft[ key ] === undefined ? ( key === 'skipped_sources' ? [] : {} ) : draft[ key ] ); } );
		if ( destinationMode( draft ) ) { result.destination_groups = copy( draft.destination_groups || [] ); }
		result.profile_page_type = draft.profile_page_type || 'page';
		result.guidance_mode = draft.guidance_mode || ( draft.guidance ? 'set' : 'inherit' );
		result.expected_revision = draft.revision || '';
		if ( reconciliation ) { result.signature = reconciliation.signature || result.signature; result.confirm_reference_change = !! reconciliation.confirm_reference_change; result.discard_stale_targets = ( reconciliation.discard_stale_targets || [] ).slice(); }
		// Only the server resolves trusted target descriptors. A stale field stays visible and in the draft.
		Object.keys( result.fields ).forEach( function ( path ) { delete result.fields[ path ].descriptor; delete result.fields[ path ].target_descriptor; delete result.fields[ path ].binding; } );
		return result;
	}
	function coverage( draft, template, inventory ) {
		if ( destinationMode( draft ) ) { return destinationCoverage( draft, inventory ); }
		var expected = sources( template ), known = new Set( expected.map( function ( field ) { return field.source_path; } ) ), covered = new Set(), skipped = new Set(), used = new Map(), issues = [], missing = [], duplicates = [], fields = draft.fields || {}, paths = new Map( list( inventory ).map( function ( field ) { return [ field.path, field ]; } ) );
		function target( path, owner ) {
			if ( ! path ) { return; }
			if ( used.has( path ) ) { duplicates.push( path ); issues.push( 'Target is used more than once: ' + path ); } else { used.set( path, owner ); }
			if ( ! paths.has( path ) ) { issues.push( 'Saved target is no longer in this reference: ' + path ); }
			else if ( paths.get( path ).writable === false ) { issues.push( 'Target is unavailable for writing: ' + path ); }
		}
		Object.keys( fields ).forEach( function ( path ) {
			var field = fields[ path ];
			if ( field.mode === 'mapped' ) {
				target( path, 'field' );
				if ( field.source_path ) { covered.add( field.source_path ); if ( ! known.has( field.source_path ) ) { issues.push( 'Saved source is absent from this template: ' + field.source_path ); } }
				else { issues.push( 'Choose a NOVA source for ' + path ); }
			} else if ( field.mode === 'protected' || field.mode === 'leave_empty' ) { target( path, field.mode ); }
		} );
		var groups = new Map( list( template && template.groups ).map( function ( group ) { return [ group.key, group ]; } ) );
		Object.keys( draft.repeat_slots || {} ).forEach( function ( key ) {
			var group = groups.get( key ), slots = draft.repeat_slots[ key ] || [];
			if ( ! group && slots.length ) { issues.push( 'Saved repeat group is absent from this template: ' + key ); }
			slots.forEach( function ( slot, index ) {
				var keys = group ? members( group ) : Object.keys( slot.targets || {} );
				keys.forEach( function ( member ) {
					var path = ( slot.targets || {} )[ member ];
					if ( path ) { target( path, key + ':' + slot.id ); covered.add( key + '[].' + member ); }
					else { issues.push( key + ' slot ' + ( index + 1 ) + ' has no target for ' + member + '.' ); }
				} );
				Object.keys( slot.targets || {} ).filter( function ( member ) { return keys.indexOf( member ) < 0; } ).forEach( function ( member ) { if ( slot.targets[ member ] ) { target( slot.targets[ member ], key + ':' + slot.id ); issues.push( 'Saved repeat member is absent from this template: ' + key + '[].' + member ); } } );
			} );
		} );
		groups.forEach( function ( group, key ) {
			var count = ( ( draft.repeat_slots || {} )[ key ] || [] ).length;
			if ( count < Number( group.min || 0 ) ) { issues.push( ( group.label || key ) + ' needs at least ' + group.min + ' fixed mapping slots.' ); }
			if ( count > Number( group.max === undefined ? 12 : group.max ) ) { issues.push( ( group.label || key ) + ' has more slots than this template permits.' ); }
		} );
		( draft.skipped_sources || [] ).forEach( function ( entry ) {
			if ( ! entry.reason || ! entry.reason.trim() ) { issues.push( 'Add a reason for skipping ' + entry.source_path + '.' ); return; }
			skipped.add( entry.source_path );
			if ( ! known.has( entry.source_path ) ) { issues.push( 'Skipped source is absent from this template: ' + entry.source_path ); }
			if ( covered.has( entry.source_path ) ) { issues.push( 'Source is both mapped and skipped: ' + entry.source_path ); }
		} );
		expected.forEach( function ( field ) { if ( ! covered.has( field.source_path ) && ! skipped.has( field.source_path ) ) { missing.push( field ); } } );
		return { total: expected.length, mapped: expected.filter( function ( field ) { return covered.has( field.source_path ); } ).length, skipped: expected.filter( function ( field ) { return skipped.has( field.source_path ); } ).length, missing: missing, issues: Array.from( new Set( issues ) ), duplicates: Array.from( new Set( duplicates ) ) };
	}
	function destinationCoverage( draft, inventory ) {
		var fields = draft.fields || {}, known = new Map( list( inventory ).map( function ( field ) { return [ field.path, field ]; } ) ), issues = [], duplicates = [], occupied = new Set(), identities = new Set(), adapted = 0, handled = 0;
		Object.keys( fields ).forEach( function ( path ) {
			var field = fields[ path ];
			if ( ! known.has( path ) ) { issues.push( 'Saved target is no longer in this reference: ' + path ); }
			if ( field.mode === 'adapt' ) { adapted++; handled++; if ( known.has( path ) && known.get( path ).writable === false ) { issues.push( 'Destination is preparation only; its native writer is unavailable: ' + path ); } if ( ! field.description || ! field.description.label || ! field.description.label.trim() ) { issues.push( 'Add a destination label for ' + path ); } }
			else if ( [ 'protected', 'leave_empty' ].indexOf( field.mode ) >= 0 ) { handled++; }
			else if ( field.mode === 'mapped' ) { issues.push( 'Historical source binding retained; convert this field explicitly to destination fitting: ' + path ); }
		} );
		( draft.destination_groups || [] ).forEach( function ( group ) {
			if ( ! slotUuid( group.id ) || identities.has( group.id ) ) { issues.push( 'Fixed group needs a unique structural UUID: ' + group.label ); } identities.add( group.id );
			if ( ! group.label || ! group.label.trim() ) { issues.push( 'Add a label for the fixed group.' ); }
			if ( ! group.slots.length ) { issues.push( 'Describe at least one existing slot in ' + group.label ); }
			group.slots.forEach( function ( slot, index ) {
				if ( ! slotUuid( slot.id ) || identities.has( slot.id ) || slot.ordinal !== index ) { issues.push( 'Fixed slot needs its unique structural UUID and current ordinal: ' + group.label ); } identities.add( slot.id );
				if ( ! slot.fields.length ) { issues.push( group.label + ' slot ' + ( index + 1 ) + ' needs an adapted destination field.' ); }
				slot.fields.forEach( function ( path ) { if ( occupied.has( path ) ) { duplicates.push( path ); issues.push( 'Destination belongs to more than one slot: ' + path ); } occupied.add( path ); if ( ! fields[ path ] || fields[ path ].mode !== 'adapt' ) { issues.push( 'Fixed slots can contain only adapted destinations: ' + path ); } if ( ! known.has( path ) ) { issues.push( 'Fixed slot target is no longer in this reference: ' + path ); } } );
			} );
		} );
		var eligible = list( inventory ).filter( function ( field ) { return field.writable !== false; } ), missing = eligible.filter( function ( field ) { return ! fields[ field.path ] || [ 'adapt', 'protected', 'leave_empty' ].indexOf( fields[ field.path ].mode ) < 0; } );
		return { total: eligible.length, mapped: adapted, handled: handled, skipped: 0, missing: missing, issues: Array.from( new Set( issues ) ), duplicates: Array.from( new Set( duplicates ) ) };
	}
	async function request( url, nonce, body, signal ) {
		var options = { method: body === undefined ? 'GET' : 'POST', credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json', 'X-WP-Nonce': nonce }, signal: signal };
		if ( body !== undefined ) { options.headers[ 'Content-Type' ] = 'application/json'; options.body = JSON.stringify( body ); }
		var response = await fetch( url, options ), result;
		try { result = await response.json(); } catch ( error ) { throw new Error( 'WordPress returned an unreadable response. Your edits are still here.' ); }
		if ( ! response.ok ) { var failure = new Error( result.message || 'Request failed (' + response.status + ').' ); failure.status = response.status; failure.code = result.code; throw failure; }
		return result;
	}
	function saveResult( response ) {
		var saved = response && response.draft;
		if ( ! saved || typeof saved.revision !== 'string' || ! saved.revision ) { throw new Error( 'WordPress did not confirm a saved draft. Your edits are still here.' ); }
		return initialDraft( {}, saved );
	}
	function mount( parent, layout, config, callbacks ) {
		callbacks = callbacks || {};
		var document = parent.ownerDocument, host = document.createElement( 'section' ), draft = initialDraft( layout ), catalog = { origin: 'unavailable', templates: [] }, dirty = false, disposed = false, busy = false, selectedPath = '', serverWarnings = [], body, feedback, status, coverageBox, fieldsBox, repeatBox, destinationBox, saveButton, abort = typeof AbortController === 'function' ? new AbortController() : null;
		var base = String( config.mappingUrl || '' ).replace( /\/$/, '' ), fieldInventory = list( layout.fields ), cards = new Map(), referenceConfirmed = false, discardedTargets = [], inventoryStale = false;
		var syncState = null, integrationBox, urlBinding = null, bindingUrl = '', bindingRevision = '', recoveryId = '', defaultType = '', defaultRevision = '', defaultRecord = null;
		host.className = 'nmd-editor'; parent.appendChild( host );
		function el( tag, className, text ) { var node = document.createElement( tag ); if ( className ) { node.className = className; } if ( text !== undefined ) { node.textContent = String( text ); } return node; }
		function button( title, action, primary ) { var node = el( 'button', 'button' + ( primary ? ' button-primary' : '' ), title ); node.type = 'button'; node.addEventListener( 'click', action ); return node; }
		function textInput( value, multiline ) { var node = el( multiline ? 'textarea' : 'input' ); if ( multiline ) { node.rows = 3; } else { node.type = 'text'; } node.value = value || ''; return node; }
		function control( container, title, node, help ) { var wrap = el( 'div', 'nmd-control' ), label = el( 'label', '', title ); node.id = 'nmd-control-' + ( ++controlId ); label.htmlFor = node.id; wrap.append( label, node ); if ( help ) { var note = el( 'p', 'nmd-help', help ); note.id = node.id + '-help'; node.setAttribute( 'aria-describedby', note.id ); wrap.appendChild( note ); } container.appendChild( wrap ); return node; }
		function select( choices, value ) { var node = el( 'select' ); choices.forEach( function ( item ) { var option = el( 'option', '', item.label ); option.value = item.value; option.disabled = !! item.disabled; node.appendChild( option ); } ); if ( value && ! choices.some( function ( item ) { return item.value === value; } ) ) { var stale = el( 'option', '', value + ' (saved; unavailable)' ); stale.value = value; node.appendChild( stale ); } node.value = value || ''; return node; }
		function template() { return resolveTemplate( draft, catalog ); }
		function active() { return ! disposed && host.isConnected !== false; }
		function note( message, error ) { feedback.textContent = message; feedback.className = 'nmd-feedback' + ( error ? ' is-error' : '' ); feedback.setAttribute( 'role', error ? 'alert' : 'status' ); }
		function changed() { if ( ! active() ) { return; } dirty = true; serverWarnings = []; if ( status ) { status.textContent = 'Unsaved local changes'; } if ( callbacks.onDirty ) { callbacks.onDirty(); } refreshCoverage(); renderIntegration(); }
		function setBusy( value ) { busy = value; if ( body ) { body.disabled = value; } host.setAttribute( 'aria-busy', String( value ) ); }
		function warningText( item ) { return typeof item === 'string' ? item : ( item.message || item.code || 'Review this draft before integration.' ); }
		function refreshCoverage() {
			if ( ! coverageBox ) { return; }
			coverageBox.replaceChildren();
			var result = coverage( draft, template(), fieldInventory );
			coverageBox.appendChild( el( 'strong', '', destinationMode( draft ) ? result.mapped + ' destinations described · ' + result.handled + ' fields configured' : result.mapped + ' / ' + result.total + ' sources mapped' + ( result.skipped ? ' · ' + result.skipped + ' explicitly skipped' : '' ) ) );
			if ( result.missing.length ) { coverageBox.appendChild( el( 'p', '', result.missing.length + ( destinationMode( draft ) ? ' writable fields have no behavior. Saving incomplete preparation is allowed.' : ' sources have no target. You can save this incomplete draft.' ) ) ); }
			if ( result.missing.length || result.skipped ) { coverageBox.appendChild( el( 'p', 'nmd-warning', destinationMode( draft ) ? 'We strongly recommend describing every field that should receive adapted content, and explicitly protecting or leaving empty the others. Destination values can remain optional.' : 'We strongly recommend mapping every NOVA field so generated content has a place on your page. You can still save intentional skips.' ) ); }
			var warnings = result.issues.concat( serverWarnings.map( warningText ) );
			if ( warnings.length ) { var details = el( 'details', 'nmd-checks' ), summary = el( 'summary', '', warnings.length + ' mapping checks to review' ), entries = el( 'ul' ); Array.from( new Set( warnings ) ).forEach( function ( item ) { entries.appendChild( el( 'li', '', item ) ); } ); details.append( summary, entries ); coverageBox.appendChild( details ); }
			if ( saveButton ) { saveButton.disabled = ! draft.template || ! draft.template.id || result.duplicates.length > 0 || inventoryStale || ( draft.signature !== layout.signature && ! referenceConfirmed ); }
		}
		function allFields() {
			var fields = fieldInventory.slice(), known = new Set( fields.map( function ( item ) { return item.path; } ) );
			Object.keys( draft.fields || {} ).forEach( function ( path ) { if ( ! known.has( path ) ) { fields.push( { path: path, label: path, stale: true, writable: false } ); } } );
			return fields;
		}
		function repeatUse( path ) { var found = false; Object.keys( draft.repeat_slots || {} ).forEach( function ( key ) { ( draft.repeat_slots[ key ] || [] ).forEach( function ( slot ) { if ( Object.values( slot.targets || {} ).indexOf( path ) >= 0 ) { found = true; } } ); } ); return found; }
		function discardMissing( path ) { if ( path && ! fieldInventory.some( function ( field ) { return field.path === path; } ) && discardedTargets.indexOf( path ) < 0 ) { discardedTargets.push( path ); } }
		function focusField( path, notify ) {
			if ( ! active() ) { return; }
			selectedPath = path;
			cards.forEach( function ( card, key ) { card.classList.toggle( 'is-selected', key === path ); } );
			var card = cards.get( path );
			if ( card && ! notify ) { card.hidden = false; card.open = true; card.scrollIntoView( { block: 'nearest', behavior: 'smooth' } ); }
			if ( notify && callbacks.onSelectField ) { callbacks.onSelectField( path ); }
		}
		function renderField( field ) {
			var card = el( 'details', 'nmd-field' ), summary = el( 'summary' ), label = el( 'span', 'nmd-field-name', field.label || field.path ), badge = el( 'span', 'nmd-field-state' ), contents = el( 'div', 'nmd-field-body' );
			card.dataset.path = field.path; card.open = selectedPath === field.path; card.classList.toggle( 'is-selected', card.open ); summary.append( label, badge ); summary.addEventListener( 'click', function ( event ) { event.preventDefault(); card.open = ! card.open; focusField( field.path, true ); } ); card.append( summary, contents ); cards.set( field.path, card );
			function redraw() {
				contents.replaceChildren();
				var entry = ( draft.fields || {} )[ field.path ], mode = entry ? entry.mode : '', inRepeat = repeatUse( field.path );
				badge.textContent = field.stale ? 'Missing target' : inRepeat ? 'Legacy repeat slot' : { adapt: 'Destination', mapped: 'Legacy source', leave_empty: 'Leave empty', protected: 'Protected' }[ mode ] || 'Not configured';
				if ( field.current_value !== undefined && field.current_value !== '' ) { contents.appendChild( el( 'p', 'nmd-current-value', String( field.current_value ).slice( 0, 600 ) ) ); }
				if ( field.stale ) { contents.appendChild( el( 'p', 'nmd-warning', 'This saved target is absent from the current reference. Its mapping is retained; restore or explicitly remove it after review.' ) ); contents.appendChild( button( 'Remove this missing target from the draft', function () { delete draft.fields[ field.path ]; if ( discardedTargets.indexOf( field.path ) < 0 ) { discardedTargets.push( field.path ); } changed(); renderFields(); } ) ); }
				else if ( field.writable === false ) { contents.appendChild( el( 'p', 'nmd-warning', 'This target is currently unavailable for writing. Discovery and saved mappings remain visible.' ) ); }
				var behaviors = [ { value: '', label: 'Not configured' } ];
				if ( destinationMode( draft ) ) { behaviors.push( { value: 'adapt', label: 'Fit NOVA content to this field' } ); }
				if ( ! destinationMode( draft ) || mode === 'mapped' ) { behaviors.push( { value: 'mapped', label: 'Legacy: map a NOVA source', disabled: field.writable === false && mode !== 'mapped' } ); }
				behaviors.push( { value: 'leave_empty', label: 'Leave empty' }, { value: 'protected', label: 'Protected' } );
				var modeSelect = control( contents, 'Field behavior', select( behaviors, mode ) );
				modeSelect.disabled = inRepeat || !! field.stale;
				modeSelect.addEventListener( 'change', function () {
					if ( groupUse( draft, field.path ) && modeSelect.value !== 'adapt' ) { note( 'Remove this field from its existing slot before changing its behavior.', true ); modeSelect.value = mode; return; }
					if ( modeSelect.value === 'adapt' ) { draft.fields[ field.path ] = adaptField( field, entry ); }
					else if ( modeSelect.value ) { draft.fields[ field.path ] = { mode: modeSelect.value, source_path: modeSelect.value === 'mapped' ? ( entry && entry.source_path || '' ) : '', instructions: entry && entry.instructions || '', required: !! ( entry && entry.required ) }; }
					else { delete draft.fields[ field.path ]; }
					changed(); redraw(); renderRepeats(); renderDestinationGroups();
				} );
				if ( inRepeat ) { contents.appendChild( el( 'p', 'nmd-help', 'Assigned in Fixed repeat slots below. Remove that slot binding to change this field’s behavior.' ) ); }
				if ( mode === 'mapped' && ! destinationMode( draft ) ) {
					var sourceSelect = control( contents, 'NOVA source', select( [ { value: '', label: 'Choose a source…' } ].concat( sources( template() ).filter( function ( item ) { return item.source_path.indexOf( '[].' ) < 0; } ).map( function ( item ) { return { value: item.source_path, label: item.label + ' · ' + item.source_path }; } ) ), entry.source_path ) );
					sourceSelect.disabled = !! field.stale;
					sourceSelect.addEventListener( 'change', function () { draft.fields[ field.path ].source_path = sourceSelect.value; changed(); } );
					var required = control( contents, 'Require a delivered value', select( [ { value: 'optional', label: 'Optional: skip a null value' }, { value: 'required', label: 'Required: block if no value is delivered' } ], entry.required ? 'required' : 'optional' ), 'This validates a delivery; it does not instruct NOVA to generate a value.' ); required.addEventListener( 'change', function () { draft.fields[ field.path ].required = required.value === 'required'; changed(); } );
				}
				if ( mode === 'mapped' && destinationMode( draft ) ) { contents.appendChild( el( 'p', 'nmd-warning', 'Historical source binding retained: ' + entry.source_path + '. Choose “Fit NOVA content to this field” to replace it with a destination description.' ) ); }
				if ( mode === 'adapt' ) { renderDescription( contents, field, entry, redraw ); }
				if ( mode === 'leave_empty' ) { contents.appendChild( el( 'p', 'nmd-help', 'Existing pages: omit this field. New clones: blank this field. These are saved instructions; this editor does not change content.' ) ); }
				if ( mode === 'protected' ) { contents.appendChild( el( 'p', 'nmd-help', 'Preserve this component on updates and clones, including its content, settings, identity and position. Writer support must be verified before publication.' ) ); }
				if ( mode === 'protected' && ! destinationMode( draft ) ) {
					var slotChoices = [ { value: '', label: 'Additional native protection' } ].concat( list( template() && template().protected_slots ).map( function ( slot ) { return { value: slot.key, label: slot.label || slot.key }; } ) );
					var protectedSlot = control( contents, 'Protected template region', select( slotChoices, entry.protected_slot || '' ) );
					protectedSlot.disabled = !! field.stale;
					protectedSlot.addEventListener( 'change', function () { draft.fields[ field.path ].protected_slot = protectedSlot.value; changed(); } );
				}
				if ( mode ) { var instructions = control( contents, 'Human instructions for this field', textInput( entry.instructions, true ), 'For example: exactly one relevant label or an edge case that the structured rules do not express. Saved locally for the backend handover; adaptation synchronization is not available yet.' ); instructions.maxLength = 8000; instructions.disabled = !! field.stale; instructions.addEventListener( 'input', function () { draft.fields[ field.path ].instructions = instructions.value; changed(); } ); }
				if ( field.write_mode === 'complete_parent' ) { contents.appendChild( el( 'p', 'nmd-warning', 'Nested field: the current writer sends the complete parent. Keep this mapping; protected publication needs verification of all affected siblings.' ) ); }
				var details = el( 'details', 'nmd-target-details' ); details.append( el( 'summary', '', 'Target details' ), el( 'code', '', field.path ) ); if ( field.transport ) { details.appendChild( el( 'p', 'nmd-help', field.transport + ( field.write_mode ? ' · ' + field.write_mode : '' ) ) ); } contents.appendChild( details );
			}
			redraw(); return card;
		}
		function renderDescription( container, field, entry, redraw ) {
			var description = entry.description || ( entry.description = describeField( field ) );
			var label = control( container, 'Destination label', textInput( description.label ) ); label.maxLength = 200; label.addEventListener( 'input', function () { description.label = label.value; changed(); } );
			var types = [ 'text', 'rich_text', 'url', 'email', 'image', 'list', 'link' ], type = control( container, 'What this field can hold', select( types.map( function ( kind ) { return { value: kind, label: { text: 'Plain text or button label', rich_text: 'Rich text', url: 'URL or button destination', email: 'Email address', image: 'Image', list: 'List', link: 'Structured link' }[ kind ] }; } ), description.value_type ) );
			type.addEventListener( 'change', function () { if ( groupUse( draft, field.path ) && [ 'text', 'rich_text', 'url', 'email' ].indexOf( type.value ) < 0 ) { note( 'Unassign this field from its fixed slot before choosing a compound value type.', true ); type.value = description.value_type; return; } description.value_type = type.value; if ( description.value_type !== 'list' ) { delete description.constraints.min_items; delete description.constraints.max_items; } if ( [ 'text', 'rich_text', 'url', 'email' ].indexOf( description.value_type ) < 0 ) { delete description.constraints.max_length; } changed(); redraw(); renderDestinationGroups(); note( 'Value type updated. Incompatible length or list limits were removed.' ); } );
			var purpose = control( container, 'Purpose in this template', textInput( description.purpose, true ), 'For example: the short heading of the second existing service step.' ); purpose.maxLength = 2000; purpose.addEventListener( 'input', function () { description.purpose = purpose.value; changed(); } );
			var rules = description.value_type === 'list' ? [ [ 'min_items', 'Minimum list items', 0, 500 ], [ 'max_items', 'Maximum list items', 0, 500 ] ] : [ 'text', 'rich_text', 'url', 'email' ].indexOf( description.value_type ) >= 0 ? [ [ 'max_length', 'Maximum characters', 1, 100000 ] ] : [];
			rules.forEach( function ( rule ) {
				var input = control( container, rule[ 1 ], textInput( description.constraints[ rule[ 0 ] ] === undefined ? '' : String( description.constraints[ rule[ 0 ] ] ) ), 'Optional structured adaptation rule.' ); input.type = 'number'; input.min = rule[ 2 ]; input.max = rule[ 3 ]; input.step = 1;
				input.addEventListener( 'input', function () { if ( input.value === '' ) { delete description.constraints[ rule[ 0 ] ]; } else { description.constraints[ rule[ 0 ] ] = Number( input.value ); } changed(); } );
			} );
			var required = control( container, 'Must the fitted value be present?', select( [ { value: 'optional', label: 'Optional: omit an absent value' }, { value: 'required', label: 'Required: reject a delivery without this value' } ], entry.required ? 'required' : 'optional' ) ); required.addEventListener( 'change', function () { entry.required = required.value === 'required'; changed(); } );
			container.appendChild( el( 'p', 'nmd-help', 'A button is described through its label and URL fields where discovery exposes both. Existing Elementor discovery does not expose a writable button URL.' ) );
			container.appendChild( el( 'p', 'nmd-warning', 'Destination preparation only. The current reviewed API does not accept this description or return fitted values yet. Selecting image, list or structured link does not add a native writer for that type.' ) );
		}
		function renderFields() { if ( ! fieldsBox ) { return; } fieldsBox.replaceChildren(); cards.clear(); allFields().forEach( function ( field ) { fieldsBox.appendChild( renderField( field ) ); } ); if ( ! fieldInventory.length ) { fieldsBox.prepend( el( 'p', 'nmd-help', 'No target fields were discovered for this reference.' ) ); } }
		function renderDestinationGroups() {
			if ( ! destinationBox ) { return; } destinationBox.replaceChildren();
			( draft.destination_groups || [] ).forEach( function ( group, groupIndex ) {
				var section = el( 'section', 'nmd-repeat-group' ), label = control( section, 'Existing group label', textInput( group.label ) ); label.maxLength = 200; label.addEventListener( 'input', function () { group.label = label.value; changed(); } );
				section.appendChild( el( 'p', 'nmd-help', 'Fixed capacity: ' + group.slots.length + ' existing slots. Mapping slots describe native rows; they never create, remove or reorder those rows.' ) );
				group.slots.forEach( function ( slot, index ) {
					var row = el( 'div', 'nmd-repeat-slot' ), top = el( 'div', 'nmd-repeat-head' ); top.append( el( 'strong', '', 'Existing slot ' + ( index + 1 ) ), button( 'Remove slot description', function () { group.slots.splice( index, 1 ); group.slots.forEach( function ( remaining, ordinal ) { remaining.ordinal = ordinal; } ); changed(); renderDestinationGroups(); } ) ); row.appendChild( top );
					slot.fields.forEach( function ( path, fieldIndex ) { var line = el( 'div', 'nmd-repeat-head' ); line.append( el( 'span', '', ( draft.fields[ path ] && draft.fields[ path ].description && draft.fields[ path ].description.label || path ) + ' · ' + path ), button( 'Unassign field', function () { slot.fields.splice( fieldIndex, 1 ); changed(); renderDestinationGroups(); } ) ); row.appendChild( line ); } );
					var choices = [ { value: '', label: 'Choose an existing adapted field…' } ];
					fieldInventory.forEach( function ( field ) { var entry = draft.fields[ field.path ]; if ( entry && entry.mode === 'adapt' && entry.description && [ 'text', 'rich_text', 'url', 'email' ].indexOf( entry.description.value_type ) >= 0 && [ 'group', 'repeater', 'flexible_content', 'array', 'object', 'link', 'image', 'gallery' ].indexOf( field.type ) < 0 && ! groupUse( draft, field.path ) ) { choices.push( { value: field.path, label: entry.description.label + ' · ' + field.path } ); } } );
					var chosen = control( row, 'Add a field to this existing slot', select( choices, '' ) ); chosen.addEventListener( 'change', function () { if ( ! chosen.value ) { return; } try { assignDestinationField( draft, slot, chosen.value ); changed(); renderDestinationGroups(); } catch ( error ) { note( error.message, true ); } } );
					section.appendChild( row );
				} );
				var add = button( 'Describe another existing slot', function () { try { addDestinationSlot( draft, group ); changed(); renderDestinationGroups(); } catch ( error ) { note( error.message, true ); } } ); add.disabled = group.slots.length >= 500;
				section.append( add, button( 'Remove group description', function () { draft.destination_groups.splice( groupIndex, 1 ); changed(); renderDestinationGroups(); } ) ); destinationBox.appendChild( section );
			} );
			var addGroup = button( 'Describe a fixed existing group', function () { if ( draft.destination_groups.length >= 64 ) { return; } try { var group = { id: newSlotId(), label: 'Existing group', slots: [] }; addDestinationSlot( draft, group ); draft.destination_groups.push( group ); changed(); renderDestinationGroups(); } catch ( error ) { note( error.message, true ); } } ); addGroup.disabled = draft.destination_groups.length >= 64; destinationBox.appendChild( addGroup );
		}
		function renderRepeats() {
			if ( ! repeatBox ) { return; } repeatBox.replaceChildren();
			var groups = list( template() && template().groups ), known = new Set( groups.map( function ( group ) { return group.key; } ) );
			Object.keys( draft.repeat_slots || {} ).forEach( function ( key ) { if ( ! known.has( key ) ) { groups.push( { key: key, label: key + ' (saved; unavailable)', member_keys: Array.from( new Set( ( draft.repeat_slots[ key ] || [] ).flatMap( function ( slot ) { return Object.keys( slot.targets || {} ); } ) ) ), max: 12, stale: true } ); } } );
			groups.forEach( function ( group ) {
				var section = el( 'section', 'nmd-repeat-group' ); section.appendChild( el( 'h4', '', group.label || group.key ) );
				var slots = draft.repeat_slots[ group.key ] || [], maximum = Math.min( 12, Number( group.max === undefined ? 12 : group.max ) );
				section.appendChild( el( 'p', 'nmd-help', ( group.min || 0 ) + '–' + maximum + ' slots allowed. Each slot points to an existing region; adding or removing a mapping slot does not change CMS rows. These local slot IDs are not NOVA content instance IDs.' ) );
				slots.forEach( function ( slot, index ) {
					var row = el( 'div', 'nmd-repeat-slot' ), top = el( 'div', 'nmd-repeat-head' ); top.append( el( 'strong', '', 'Slot ' + ( index + 1 ) ), button( 'Remove mapping slot', function () { Object.values( slot.targets || {} ).forEach( discardMissing ); draft.repeat_slots[ group.key ].splice( index, 1 ); draft.repeat_slots[ group.key ].forEach( function ( remaining, ordinal ) { if ( slotUuid( remaining.id ) && Number.isInteger( remaining.ordinal ) ) { remaining.ordinal = ordinal; } } ); if ( ! draft.repeat_slots[ group.key ].length ) { delete draft.repeat_slots[ group.key ]; } changed(); renderRepeats(); renderFields(); } ) ); row.appendChild( top );
					if ( ! slotUuid( slot.id ) || slot.ordinal !== index ) { row.append( el( 'p', 'nmd-warning', 'This legacy slot has no valid structural UUID/ordinal. Its historical identity is preserved; a replacement creates a new mapping identity.' ), button( 'Replace legacy slot identity', function () { try { slot.id = newSlotId(); slot.ordinal = index; changed(); renderRepeats(); } catch ( error ) { note( error.message, true ); } } ) ); }
					members( group ).forEach( function ( member ) {
						var current = ( slot.targets || {} )[ member ] || '', choices = [ { value: '', label: 'Choose an existing target…' } ];
						fieldInventory.forEach( function ( field ) { choices.push( { value: field.path, label: field.label || field.path, disabled: field.path !== current && ( field.writable === false || !! draft.fields[ field.path ] || repeatUse( field.path ) ) } ); } );
						var targetSelect = control( row, member, select( choices, current ) ); targetSelect.disabled = !! group.stale;
						if ( current && ! fieldInventory.some( function ( field ) { return field.path === current; } ) ) { row.appendChild( el( 'p', 'nmd-warning', 'Saved target is missing: ' + current + '. Choose a replacement or remove this binding explicitly.' ) ); }
						targetSelect.addEventListener( 'change', function () { discardMissing( current ); if ( targetSelect.value ) { slot.targets[ member ] = targetSelect.value; } else { delete slot.targets[ member ]; } changed(); renderRepeats(); renderFields(); } );
					} );
					section.appendChild( row );
				} );
				var add = button( 'Add mapping slot', function () { try { var id = newSlotId(); if ( ! draft.repeat_slots[ group.key ] ) { draft.repeat_slots[ group.key ] = []; } draft.repeat_slots[ group.key ].push( { id: id, ordinal: draft.repeat_slots[ group.key ].length, targets: {} } ); changed(); renderRepeats(); } catch ( error ) { note( error.message, true ); } } ); add.disabled = slots.length >= maximum || !! group.stale; section.appendChild( add ); repeatBox.appendChild( section );
				if ( group.stale && ! slots.length ) { section.appendChild( button( 'Remove unavailable empty group', function () { delete draft.repeat_slots[ group.key ]; changed(); renderRepeats(); } ) ); }
			} );
			if ( ! groups.length ) { repeatBox.appendChild( el( 'p', 'nmd-help', 'This template has no repeat groups.' ) ); }
		}
		function renderSkips( container ) {
			var details = el( 'details', 'nmd-advanced' ); details.appendChild( el( 'summary', '', 'Source coverage and explicit skips' ) ); details.appendChild( el( 'p', 'nmd-help', 'Missing targets are advisory. Explicitly skip a source with a reason so reviewers can distinguish a decision from unfinished mapping.' ) );
			var expected = sources( template() ), known = new Set( expected.map( function ( item ) { return item.source_path; } ) );
			( draft.skipped_sources || [] ).forEach( function ( entry ) { if ( ! known.has( entry.source_path ) ) { expected.push( { source_path: entry.source_path, label: entry.source_path + ' (saved; unavailable)' } ); } } );
			expected.forEach( function ( source ) {
				var line = el( 'div', 'nmd-skip-row' ), label = el( 'label', 'nmd-checkbox' ), checkbox = el( 'input' ); checkbox.type = 'checkbox'; var saved = draft.skipped_sources.find( function ( item ) { return item.source_path === source.source_path; } ); checkbox.checked = !! saved; label.append( checkbox, el( 'span', '', 'Skip ' + ( source.label || source.source_path ) ) ); line.append( label, el( 'code', '', source.source_path ) );
				var reason = control( line, 'Reason for skipping', textInput( saved && saved.reason ) ); reason.maxLength = 2000; reason.parentNode.hidden = ! saved;
				checkbox.addEventListener( 'change', function () { if ( checkbox.checked ) { draft.skipped_sources.push( { source_path: source.source_path, reason: reason.value } ); } else { draft.skipped_sources = draft.skipped_sources.filter( function ( item ) { return item.source_path !== source.source_path; } ); } reason.parentNode.hidden = ! checkbox.checked; changed(); } );
				reason.addEventListener( 'input', function () { var item = draft.skipped_sources.find( function ( entry ) { return entry.source_path === source.source_path; } ); if ( item ) { item.reason = reason.value; changed(); } } ); details.appendChild( line );
			} ); container.appendChild( details );
		}
		async function choosePreview() {
			if ( busy ) { return; } setBusy( true );
			try { var result = await request( base + '/catalog?mode=preview', config.nonce, undefined, abort && abort.signal ); if ( ! active() ) { return; } catalog = result; draft.catalog_mode = 'preview'; changed(); render(); note( 'Documented preview fields loaded. No NOVA connection, synchronization or activation has occurred.' ); }
			catch ( error ) { if ( active() ) { note( error.message, true ); } }
			finally { if ( active() ) { setBusy( false ); } }
		}
		async function save() {
			if ( utf8Bytes( draft.guidance ) > 8000 || ( draft.guidance_mode === 'set' && ! draft.guidance.trim() ) ) { note( 'Replacement instructions must be nonempty and at most 8000 UTF-8 bytes. Choose Clear to remove inherited instructions.', true ); return; }
			if ( busy ) { return; }
			var check = coverage( draft, template(), fieldInventory );
			if ( check.duplicates.length ) { note( 'Each repeat target must be unique. Resolve duplicate target bindings before saving.', true ); return; }
			if ( ! destinationMode( draft ) && draft.skipped_sources.some( function ( item ) { return ! item.reason || ! item.reason.trim(); } ) ) { note( 'Add a reason for each explicitly skipped source, or remove the skip. Unmapped sources can still be saved.', true ); return; }
			setBusy( true ); note( 'Saving local draft…' );
			try {
				var result = await request( base + '/draft', config.nonce, payload( draft, { signature: layout.signature, confirm_reference_change: referenceConfirmed, discard_stale_targets: discardedTargets } ), abort && abort.signal ); if ( ! active() ) { return; }
				draft = saveResult( result ); dirty = false; discardedTargets = []; referenceConfirmed = false; serverWarnings = result.warnings || draft.warnings || []; if ( result.catalog ) { catalog = result.catalog; }
				render(); note( destinationMode( draft ) ? 'Destination preparation saved locally. Export it for the backend handover; adaptation synchronization is not available yet.' : 'Draft saved. Synchronize it to NOVA when ready.' ); if ( callbacks.onSaved ) { callbacks.onSaved( copy( draft ) ); } loadIntegration();
			} catch ( error ) {
				if ( active() ) { note( error.status === 409 ? 'Save conflict: ' + error.message + ' Your edits are retained. Export them before reopening this reference to reconcile the latest saved draft.' : error.message, true ); }
			} finally { if ( active() ) { setBusy( false ); } }
		}
		function downloadJson( data, name ) {
			var url = URL.createObjectURL( new Blob( [ JSON.stringify( data, null, 2 ) ], { type: 'application/json' } ) ), anchor = el( 'a' ); anchor.href = url; anchor.download = name; host.appendChild( anchor ); anchor.click(); anchor.remove(); setTimeout( function () { URL.revokeObjectURL( url ); }, 0 );
		}
		function exportDraft() {
			var data = { kind: 'nova_mapping_integration_draft', local_only: true, synchronized: false, activation: 'not_requested', review_status: dirty || ! draft.revision ? 'unsaved_local_changes' : 'saved_local_draft', exported_at: new Date().toISOString(), draft: copy( draft ), reconciliation: { signature: layout.signature, confirm_reference_change: referenceConfirmed, discard_stale_targets: discardedTargets.slice() }, checks: coverage( draft, template(), fieldInventory ) };
			downloadJson( data, 'nova-mapping-draft-' + draft.reference_id + '.json' );
		}
		async function exportBackendDescription() {
			if ( busy || dirty || ! draft.revision || ! destinationMode( draft ) ) { note( 'Save and review this destination draft before exporting its backend description.', true ); return; }
			setBusy( true );
			try {
				var query = '?reference_type=' + encodeURIComponent( draft.reference_type ) + '&reference_id=' + encodeURIComponent( draft.reference_id ) + '&signature=' + encodeURIComponent( layout.signature ), result = await request( base + '/draft' + query, config.nonce, undefined, abort && abort.signal );
				if ( ! active() ) { return; }
				if ( ! result.draft || result.draft.revision !== draft.revision ) { throw new Error( 'The saved draft changed. Reopen and review its latest revision before exporting.' ); }
				if ( ! result.backend_description || result.backend_description.status !== 'prepared_local' ) { throw new Error( result.backend_description && result.backend_description.error && result.backend_description.error.message || 'The backend description needs review before export.' ); }
				downloadJson( result.backend_description, 'nova-template-backend-description-' + draft.reference_id + '.json' ); note( 'Backend description exported. This is local preparation, not a synchronized or published template.' );
			} catch ( error ) { if ( active() ) { note( error.message, true ); } }
			finally { if ( active() ) { setBusy( false ); } }
		}
		function referencePayload() { return { reference_type: draft.reference_type, reference_id: draft.reference_id, signature: layout.signature, expected_revision: draft.revision || '' }; }
		async function loadIntegration() {
			if ( ! active() || ! config.postingIntegration || destinationMode( draft ) ) { return; }
			try {
				var query = '?reference_type=' + encodeURIComponent( draft.reference_type ) + '&reference_id=' + encodeURIComponent( draft.reference_id ) + '&signature=' + encodeURIComponent( layout.signature );
				var result = await request( base + '/sync-state' + query, config.nonce, undefined, abort && abort.signal );
				if ( active() ) { syncState = result.state || null; urlBinding = result.url_binding || null; if ( urlBinding && ! bindingUrl ) { bindingUrl = urlBinding.url_id; bindingRevision = String( urlBinding.revision ); } renderIntegration(); }
			} catch ( error ) { if ( active() && integrationBox ) { integrationBox.replaceChildren( el( 'p', 'nmd-warning', 'Synchronization status unavailable: ' + error.message ), button( 'Refresh synchronization status', loadIntegration ) ); } }
		}
		async function integrate( action, resume ) {
			if ( busy || dirty || ! draft.revision || draft.catalog_mode !== 'nova' ) { note( 'Save a reviewed draft using the current NOVA catalog first.', true ); return; }
			setBusy( true );
			try {
				var data = referencePayload(); if ( resume ) { data.recover_template_id = recoveryId.trim(); }
				var result = await request( base + '/' + action, config.nonce, data, abort && abort.signal );
				if ( ! active() ) { return; } syncState = result.state || null; renderIntegration();
				note( action === 'activate' ? 'Native profile approved locally. Human instructions remain local only.' : 'Publishing template synchronized. Instructions and native addresses remain in WordPress.' );
			} catch ( error ) { if ( active() ) { note( error.message, true ); await loadIntegration(); } }
			finally { if ( active() ) { setBusy( false ); } }
		}
		async function selectRemote( write, defaults ) {
			if ( busy || ( ! defaults && ! /^[1-9][0-9]*$/.test( bindingUrl ) ) || ( defaults && ! /^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/.test( defaultType ) ) ) { note( 'Enter the trusted NOVA URL ID or source page type first.', true ); return; }
			var revision = defaults ? defaultRevision : bindingRevision;
			if ( write && ( dirty || ! syncState || syncState.status !== 'active' || syncState.local_revision !== draft.revision || ! /^(0|[1-9][0-9]*)$/.test( revision ) || ! Number.isSafeInteger( Number( revision ) ) ) ) { note( 'Approve this exact profile, then review the current server revision before saving.', true ); return; }
			setBusy( true );
			try {
				var data = referencePayload(); if ( defaults ) { data.source_page_type = defaultType; } else { data.url_id = bindingUrl; }
				var endpoint = base + ( defaults ? '/template-default' : '/url-binding' );
				if ( write ) { data.expected_server_revision = Number( revision ); } else { endpoint += '?' + new URLSearchParams( data ).toString(); }
				var result = await request( endpoint, config.nonce, write ? data : undefined, abort && abort.signal );
				if ( ! active() ) { return; }
				if ( defaults ) { defaultRecord = result.template_default; defaultRevision = String( result.server_revision ); }
				else { urlBinding = result.url_binding; bindingRevision = String( result.server_revision ); }
				renderIntegration(); note( result.message );
			} catch ( error ) { if ( active() ) { note( error.status === 412 ? 'The server selection changed. Review it again before replacing it; no overwrite was attempted.' : error.message, true ); } }
			finally { if ( active() ) { setBusy( false ); } }
		}
		function renderIntegration() {
			if ( ! integrationBox ) { return; } integrationBox.replaceChildren();
			if ( destinationMode( draft ) ) { integrationBox.append( el( 'h4', '', 'Template adaptation setup' ), el( 'p', 'nmd-warning', 'Saved for backend handover. The current reviewed posting-service API cannot synchronize destination descriptions, rules or fitted values. Publication remains unavailable for this preparation.' ) ); var unavailable = button( 'Backend synchronization unavailable', function () {} ); unavailable.disabled = true; integrationBox.appendChild( unavailable ); return; }
			integrationBox.appendChild( el( 'h4', '', 'Publishing-template setup' ) );
			var same = syncState && syncState.local_revision === draft.revision, stateName = syncState && syncState.status || 'not_synced';
			integrationBox.appendChild( el( 'p', 'nmd-help', 'Status: ' + stateName.replace( /_/g, ' ' ) + ( syncState && ! same ? ' (an earlier local revision)' : '' ) ) );
			integrationBox.appendChild( el( 'p', 'nmd-warning', 'Human instructions and rules are saved locally only. The current API does not accept or apply them during generation. Publishing templates map existing delivered fields; they do not generate custom labels or repeat members.' ) );
			if ( syncState && syncState.error ) { integrationBox.appendChild( el( 'p', 'nmd-warning', warningText( syncState.error ) ) ); }
			var synchronize = button( 'Synchronize publishing template', function () { integrate( 'sync', false ); } ); synchronize.disabled = dirty || ! draft.revision || draft.catalog_mode !== 'nova' || catalog.api_available === false || stateName === 'pending'; integrationBox.appendChild( synchronize );
			if ( syncState && stateName === 'pending' ) {
				if ( syncState.pending && syncState.pending.requires_template_id ) { var recovery = control( integrationBox, 'Created NOVA template UUID for recovery', textInput( recoveryId ), 'Creation is never retried automatically. Review the created template in NOVA; it must match this exact request.' ); recovery.addEventListener( 'input', function () { recoveryId = recovery.value; } ); }
				var resume = button( 'Recover pending synchronization', function () { integrate( 'sync', true ); } ); resume.disabled = dirty || ! same; integrationBox.appendChild( resume );
			}
			var activate = button( 'Validate and approve native profile', function () { integrate( 'activate', false ); } ); activate.disabled = dirty || ! same || [ 'synced_draft', 'active' ].indexOf( stateName ) < 0; integrationBox.appendChild( activate );
			integrationBox.appendChild( button( 'Refresh status', loadIntegration ) );
			if ( syncState && syncState.template ) { integrationBox.appendChild( el( 'p', 'nmd-help', 'NOVA template ' + syncState.template.id + ', revision ' + syncState.template.revision + '. Native approval belongs to this exact revision.' ) ); }
			integrationBox.appendChild( el( 'p', 'nmd-help', 'Approval validates the WordPress writer locally. Delivery is controlled by the connection pause setting. Existing deliveries keep their frozen configuration.' ) );
			var bindingBox = el( 'fieldset', 'nmd-routing' ); bindingBox.disabled = dirty || ! same || stateName !== 'active'; bindingBox.appendChild( el( 'legend', '', 'Optional NOVA page selection' ) );
			var url = control( bindingBox, 'Trusted NOVA URL ID', textInput( bindingUrl ), 'Distinct from a WordPress post ID. NOVA verifies ownership; native targets stay local.' ); url.inputMode = 'numeric';
			var revision = control( bindingBox, 'Reviewed server setting revision', textInput( bindingRevision ), 'Review first. Zero creates a missing page setting; an existing revision conditionally replaces it.' ); revision.readOnly = true;
			url.addEventListener( 'input', function () { bindingUrl = url.value.trim(); bindingRevision = ''; revision.value = ''; } );
			bindingBox.append( button( 'Review current page setting', function () { selectRemote( false, false ); } ), button( 'Select this template for the NOVA page', function () { selectRemote( true, false ); } ) );
			if ( urlBinding ) { bindingBox.appendChild( el( 'p', 'nmd-help', 'Selected template ' + urlBinding.template_id + ', setting revision ' + urlBinding.revision + '.' ) ); } integrationBox.appendChild( bindingBox );
			var defaultsBox = el( 'fieldset', 'nmd-routing' ); defaultsBox.disabled = dirty || ! same || stateName !== 'active'; defaultsBox.appendChild( el( 'legend', '', 'Optional source page-type default' ) );
			var sourceType = control( defaultsBox, 'NOVA source page type', textInput( defaultType ), 'For example service or informative. A page selection takes precedence. Other defaults are preserved.' );
			sourceType.addEventListener( 'input', function () { defaultType = sourceType.value.trim(); defaultRevision = ''; } );
			defaultsBox.append( button( 'Review template defaults', function () { selectRemote( false, true ); } ), button( 'Set this source-type default', function () { selectRemote( true, true ); } ) );
			if ( defaultRecord ) { defaultsBox.appendChild( el( 'p', 'nmd-help', 'Defaults revision ' + defaultRevision + '; selected: ' + ( defaultRecord.defaults[ defaultType ] || '(none)' ) + '.' ) ); } integrationBox.appendChild( defaultsBox );
		}
		function render() {
			host.replaceChildren();
			var header = el( 'header', 'nmd-header' ); header.append( el( 'h3', '', 'NOVA mapping draft' ), el( 'span', 'nmd-local-badge', 'Draft editor' ) ); host.appendChild( header );
			feedback = el( 'div', 'nmd-feedback' ); feedback.setAttribute( 'role', 'status' ); feedback.setAttribute( 'aria-live', 'polite' ); host.appendChild( feedback );
			body = el( 'fieldset', 'nmd-body' ); body.disabled = busy; var legend = el( 'legend', 'screen-reader-text', 'NOVA mapping draft settings' ); body.appendChild( legend ); host.appendChild( body );
			var catalogNote = el( 'div', 'nmd-catalog-note' );
			if ( destinationMode( draft ) ) { catalogNote.appendChild( el( 'p', '', 'Describe what each template field can hold. Posting-service will fit existing NOVA content into these destinations. No NOVA source selection or live connection is needed for preparation.' ) ); if ( Object.values( draft.fields ).some( function ( field ) { return field.mode === 'mapped'; } ) || Object.values( draft.repeat_slots ).some( function ( slots ) { return slots.length; } ) ) { catalogNote.appendChild( el( 'p', 'nmd-warning', 'Historical source bindings and repeat mappings are retained. Explicitly convert or remove them before producing a destination-only backend handover.' ) ); } if ( Object.values( draft.repeat_slots ).some( function ( slots ) { return slots.length; } ) ) { catalogNote.appendChild( button( 'Remove retained legacy repeat bindings after review', function () { Object.values( draft.repeat_slots ).forEach( function ( slots ) { slots.forEach( function ( slot ) { Object.values( slot.targets || {} ).forEach( discardMissing ); } ); } ); draft.repeat_slots = {}; changed(); render(); note( 'Legacy repeat bindings removed from this local draft. Describe the existing native fields and fixed slots before saving.' ); } ) ); } }
			else if ( catalog.origin !== 'nova' ) { catalogNote.appendChild( el( 'p', '', catalog.origin === 'preview' ? 'Documented preview catalog. These field definitions and IDs are local examples, not an active NOVA configuration.' : catalog.message || 'The NOVA catalog is not connected yet. Use the documented preview fields to prepare a local draft.' ) ); if ( catalog.origin !== 'preview' ) { catalogNote.appendChild( button( 'Use documented preview fields', choosePreview ) ); } }
			else { catalogNote.appendChild( el( 'p', '', catalog.message || 'Current NOVA delivery fields loaded. Human instructions remain local.' ) ); }
			body.appendChild( catalogNote );
			if ( inventoryStale ) { body.appendChild( el( 'p', 'nmd-warning', 'The reference changed while this editor was loading. Export any edits, then refresh the layout inventory before saving.' ) ); }
			else if ( draft.signature !== layout.signature ) { var review = el( 'div', 'nmd-warning' ), reviewLabel = el( 'label', 'nmd-checkbox' ), reviewCheck = el( 'input' ); reviewCheck.type = 'checkbox'; reviewCheck.checked = referenceConfirmed; reviewLabel.append( reviewCheck, el( 'span', '', 'I reviewed the saved targets against this changed layout.' ) ); review.append( el( 'p', '', 'This draft was saved for an earlier layout. Reconcile missing targets and verify all retained bindings.' ), reviewLabel ); reviewCheck.addEventListener( 'change', function () { referenceConfirmed = reviewCheck.checked; changed(); } ); body.appendChild( review ); }
			if ( ! destinationMode( draft ) ) { body.appendChild( button( 'Prepare destination descriptions instead', function () { prepareDestination( draft, template() ); changed(); render(); note( 'Switched to destination preparation. Historical mappings are retained; convert each field explicitly.' ); } ) ); }
			var templates = ( catalog.templates || [] ).slice(), selected = draft.template && draft.template.id ? draft.template.id + '::' + draft.template.revision : '';
			if ( ! destinationMode( draft ) ) {
			if ( template() && ! templates.some( function ( item ) { return item.id === template().id && String( item.revision ) === String( template().revision ); } ) ) { templates.push( Object.assign( {}, template(), { label: template().label + ' (cached definition)' } ) ); body.appendChild( el( 'p', 'nmd-help', 'Source choices use the saved exact template definition. The live revision is unavailable; this does not establish current NOVA support.' ) ); }
			var templateSelect = control( body, 'Delivery field catalog', select( [ { value: '', label: 'Choose a template…' } ].concat( templates.map( function ( item ) { return { value: item.id + '::' + item.revision, label: item.label + ' · ' + item.family }; } ) ), selected ) );
			templateSelect.addEventListener( 'change', function () { var chosen = templates.find( function ( item ) { return item.id + '::' + item.revision === templateSelect.value; } ); if ( draft.guidance_mode === 'inherit' && template() && template().authoring_notes ) { draft.guidance = template().authoring_notes; draft.guidance_mode = 'set'; } draft.template = chosen ? { id: chosen.id, revision: chosen.revision } : { id: '', revision: '' }; draft.profile_page_type = chosen && chosen.family || draft.profile_page_type || 'page'; changed(); render(); note( 'Template selection changed. Existing bindings and skips are retained for review.' ); } );
			}
			var label = control( body, 'Mapping label', textInput( draft.label ) ); label.maxLength = 120; label.addEventListener( 'input', function () { draft.label = label.value; changed(); } );
			var pageType = control( body, 'Publishing target page type', textInput( draft.profile_page_type || 'page' ), 'Lowercase words and hyphens. This labels the publishing template, not the WordPress post type.' ); pageType.addEventListener( 'input', function () { draft.profile_page_type = pageType.value; changed(); } );
			body.appendChild( el( 'p', 'nmd-help', 'Use instructions for template edge cases that structured rules cannot express. They are retained locally for posting-service adaptation; backend synchronization is pending.' ) );
			var notesMode = control( body, 'Template instructions', select( [ { value: 'inherit', label: 'Retain inherited local instructions' }, { value: 'set', label: 'Set replacement instructions' }, { value: 'clear', label: 'Clear instructions explicitly' } ], draft.guidance_mode ) );
			notesMode.addEventListener( 'change', function () { draft.guidance_mode = notesMode.value; if ( draft.guidance_mode !== 'set' ) { draft.guidance = ''; } changed(); render(); } );
			if ( draft.guidance_mode === 'inherit' ) { body.appendChild( el( 'p', 'nmd-help', 'Retained local inherited instructions: ' + ( template() && template().authoring_notes || '(none)' ) ) ); }
			if ( draft.guidance_mode === 'set' ) { var guidance = control( body, 'Local human instructions and rules', textInput( draft.guidance, true ) ), notesCount = el( 'p', 'nmd-help', utf8Bytes( draft.guidance ) + ' / 8000 UTF-8 bytes' ); body.appendChild( notesCount ); guidance.addEventListener( 'input', function () { draft.guidance = guidance.value; notesCount.textContent = utf8Bytes( draft.guidance ) + ' / 8000 UTF-8 bytes'; changed(); } ); }
			coverageBox = el( 'div', 'nmd-coverage' ); coverageBox.setAttribute( 'aria-live', 'polite' ); body.appendChild( coverageBox );
			body.appendChild( el( 'h4', '', 'Page and template fields' ) ); body.appendChild( el( 'p', 'nmd-help', 'Select a field here or on the page preview. Nested ACF fields remain available.' ) );
			var search = el( 'input', 'nmd-search' ); search.type = 'search'; search.placeholder = 'Find a target field…'; search.setAttribute( 'aria-label', 'Find a target field' ); body.appendChild( search );
			fieldsBox = el( 'div', 'nmd-fields' ); body.appendChild( fieldsBox ); renderFields(); search.addEventListener( 'input', function () { var query = search.value.toLowerCase().trim(); cards.forEach( function ( card, path ) { card.hidden = ( card.textContent + ' ' + path ).toLowerCase().indexOf( query ) < 0; } ); } );
			repeatBox = null; destinationBox = null;
			if ( destinationMode( draft ) ) { var destinations = el( 'details', 'nmd-advanced' ); destinations.append( el( 'summary', '', 'Fixed existing groups and slots' ), el( 'p', 'nmd-help', 'Describe repeated regions by assigning the existing adapted scalar fields in each row. This is fixed capacity; no native rows are added, removed or reordered.' ) ); destinationBox = el( 'div' ); destinations.appendChild( destinationBox ); body.appendChild( destinations ); renderDestinationGroups(); }
			else { renderSkips( body ); var repeats = el( 'details', 'nmd-advanced' ); repeats.appendChild( el( 'summary', '', 'Fixed repeat slots' ) ); repeats.appendChild( el( 'p', 'nmd-warning', 'Historical repeat mappings remain local. The current delivery API has no generated repeat-member contract; explicitly reconcile them before synchronization.' ) ); repeatBox = el( 'div' ); repeats.appendChild( repeatBox ); body.appendChild( repeats ); renderRepeats(); }
			var routing = el( 'details', 'nmd-advanced' ); routing.appendChild( el( 'summary', '', 'Target and routing' ) ); routing.appendChild( el( 'p', 'nmd-help', 'Reference: ' + draft.reference_type + ' #' + draft.reference_id + ( layout.path ? ' · ' + layout.path : '' ) ) ); routing.appendChild( el( 'code', 'nmd-signature', 'Layout: ' + draft.signature ) );
			var operations = [ { value: 'update', label: draft.reference_type === 'term' ? 'Update an existing category or term' : 'Update an existing page' } ]; if ( draft.reference_type !== 'term' ) { operations.push( { value: 'clone', label: 'Clone this reference as a new page' } ); }
			var operation = control( routing, 'Intended operation', select( operations, draft.routing.operation ), draft.reference_type === 'term' ? 'Cloning category and term references is not supported.' : '' ); operation.addEventListener( 'change', function () { draft.routing.operation = operation.value; changed(); } );
			if ( draft.reference_type !== 'term' ) {
				var publication = control( routing, 'Publication policy', select( [ { value: 'preserve', label: 'Keep existing status; new clones stay draft' }, { value: 'publish', label: 'Publish after validation and recovery work completes' }, { value: 'draft', label: 'Save as draft' } ], draft.routing.publication || ( draft.routing.operation === 'clone' ? 'draft' : 'preserve' ) ) );
				publication.addEventListener( 'change', function () { draft.routing.publication = publication.value; changed(); } );
			}
			var locale = control( routing, 'Locale', textInput( draft.routing.locale ), 'Optional workflow locale. Saving does not create or translate a page.' ); locale.maxLength = 35; locale.addEventListener( 'input', function () { draft.routing.locale = locale.value; changed(); } ); body.appendChild( routing );
			var selectedTemplate = template();
			if ( ! destinationMode( draft ) && selectedTemplate && ( selectedTemplate.protected_slots || [] ).length ) { var protectedInfo = el( 'details', 'nmd-advanced' ); protectedInfo.append( el( 'summary', '', 'Template protected regions' ), el( 'p', 'nmd-help', 'Keep these native components outside generated source bindings: ' + selectedTemplate.protected_slots.map( function ( slot ) { return slot.label || slot.key; } ).join( ', ' ) + '. Mark their discovered targets Protected above.' ) ); body.appendChild( protectedInfo ); }
			var footer = el( 'div', 'nmd-savebar' ); saveButton = button( 'Save local draft', save, true ); footer.append( saveButton, button( 'Export local draft', exportDraft ) ); if ( destinationMode( draft ) ) { footer.appendChild( button( 'Export backend description', exportBackendDescription ) ); } if ( callbacks.onCancel ) { footer.appendChild( button( 'Close', callbacks.onCancel ) ); } status = el( 'span', 'nmd-save-state', dirty ? 'Unsaved local changes' : draft.revision ? 'Local draft saved' : 'No local draft saved' ); footer.appendChild( status ); body.appendChild( footer );
			integrationBox = null; if ( config.postingIntegration ) { integrationBox = el( 'section', 'nmd-integration' ); body.appendChild( integrationBox ); renderIntegration(); } refreshCoverage();
		}
		async function loadDraft() {
		if ( ! active() ) { return; } host.replaceChildren( el( 'p', 'nmd-help', 'Loading NOVA mapping draft…' ) ); host.setAttribute( 'aria-busy', 'true' );
		try {
			var query = '?reference_type=' + encodeURIComponent( draft.reference_type ) + '&reference_id=' + encodeURIComponent( draft.reference_id ) + '&signature=' + encodeURIComponent( draft.signature );
			var loaded = await request( base + '/draft' + query, config.nonce, undefined, abort && abort.signal );
			if ( active() ) { draft = initialDraft( layout, loaded.draft ); draft.fields = draft.fields || {}; draft.repeat_slots = draft.repeat_slots || {}; draft.skipped_sources = draft.skipped_sources || []; draft.routing = draft.routing || { operation: 'update', locale: '' }; inventoryStale = !! ( loaded.reference && loaded.reference.signature !== layout.signature ); serverWarnings = loaded.warnings || []; catalog = loaded.catalog || ( destinationMode( draft ) ? { origin: 'destination', templates: [] } : await request( base + '/catalog?mode=' + encodeURIComponent( draft.catalog_mode || 'nova' ), config.nonce, undefined, abort && abort.signal ) ); if ( active() ) { render(); setBusy( false ); } }
		} catch ( error ) {
			if ( active() ) { host.replaceChildren(); var failure = el( 'div', 'nmd-feedback is-error', error.message + ' The saved draft could not be loaded; editing is disabled to avoid replacing it.' ); failure.setAttribute( 'role', 'alert' ); host.appendChild( failure ); host.appendChild( button( 'Retry loading', loadDraft ) ); host.setAttribute( 'aria-busy', 'false' ); }
		}
		}
		var controller = { selectField: function ( path ) { focusField( path, false ); }, getDraft: function () { return copy( draft ); }, isDirty: function () { return dirty; }, destroy: function () { disposed = true; if ( abort ) { abort.abort(); } host.remove(); } };
		controller.ready = loadDraft().then( loadIntegration );
		return controller;
	}
	return { mount: mount, initialDraft: initialDraft, payload: payload, coverage: coverage, request: request, saveResult: saveResult, resolveTemplate: resolveTemplate, utf8Bytes: utf8Bytes, newSlotId: newSlotId, describeField: describeField, adaptField: adaptField, prepareDestination: prepareDestination, addDestinationSlot: addDestinationSlot, assignDestinationField: assignDestinationField };
} ) );
