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
	function initialDraft( layout, saved ) {
		if ( saved ) { var restored = copy( saved ); restored.fields = ! restored.fields || Array.isArray( restored.fields ) ? {} : restored.fields; restored.repeat_slots = ! restored.repeat_slots || Array.isArray( restored.repeat_slots ) ? {} : restored.repeat_slots; Object.values( restored.repeat_slots ).forEach( function ( slots ) { slots.forEach( function ( slot ) { if ( ! slot.targets || Array.isArray( slot.targets ) ) { slot.targets = {}; } } ); } ); restored.skipped_sources = restored.skipped_sources || []; restored.routing = restored.routing || { operation: 'update', locale: '' }; return restored; }
		return { revision: '', signature: layout.signature, reference_type: layout.reference_type || 'post', reference_id: Number( layout.reference_id || layout.reference_post_id || layout.reference_term_id || 0 ), template: { id: '', revision: '' }, catalog_mode: 'nova', label: layout.label || layout.title || '', guidance: '', fields: {}, skipped_sources: [], repeat_slots: {}, routing: { operation: 'update', locale: '' } };
	}
	function resolveTemplate( draft, catalog ) {
		function exact( item ) { return draft.template && item && item.id === draft.template.id && String( item.revision ) === String( draft.template.revision ); }
		return ( catalog.templates || [] ).find( exact ) || ( exact( draft.catalog_snapshot ) ? draft.catalog_snapshot : undefined );
	}
	function payload( draft, reconciliation ) {
		var result = {};
		[ 'signature', 'reference_type', 'reference_id', 'template', 'catalog_mode', 'label', 'guidance', 'fields', 'skipped_sources', 'repeat_slots', 'routing' ].forEach( function ( key ) { result[ key ] = copy( draft[ key ] === undefined ? ( key === 'skipped_sources' ? [] : {} ) : draft[ key ] ); } );
		result.expected_revision = draft.revision || '';
		if ( reconciliation ) { result.signature = reconciliation.signature || result.signature; result.confirm_reference_change = !! reconciliation.confirm_reference_change; result.discard_stale_targets = ( reconciliation.discard_stale_targets || [] ).slice(); }
		// Only the server resolves trusted target descriptors. A stale field stays visible and in the draft.
		Object.keys( result.fields ).forEach( function ( path ) { delete result.fields[ path ].descriptor; delete result.fields[ path ].target_descriptor; delete result.fields[ path ].binding; } );
		return result;
	}
	function coverage( draft, template, inventory ) {
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
		var document = parent.ownerDocument, host = document.createElement( 'section' ), draft = initialDraft( layout ), catalog = { origin: 'unavailable', templates: [] }, dirty = false, disposed = false, busy = false, selectedPath = '', serverWarnings = [], body, feedback, status, coverageBox, fieldsBox, repeatBox, saveButton, abort = typeof AbortController === 'function' ? new AbortController() : null;
		var base = String( config.mappingUrl || '' ).replace( /\/$/, '' ), fieldInventory = list( layout.fields ), cards = new Map(), referenceConfirmed = false, discardedTargets = [], inventoryStale = false;
		var syncState = null, integrationBox;
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
			coverageBox.appendChild( el( 'strong', '', result.mapped + ' / ' + result.total + ' sources mapped' + ( result.skipped ? ' · ' + result.skipped + ' explicitly skipped' : '' ) ) );
			if ( result.missing.length ) { coverageBox.appendChild( el( 'p', '', result.missing.length + ' sources have no target. You can save this incomplete draft.' ) ); }
			if ( result.missing.length || result.skipped ) { coverageBox.appendChild( el( 'p', 'nmd-warning', 'We strongly recommend mapping every NOVA field so generated content has a place on your page. You can still save intentional skips.' ) ); }
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
				badge.textContent = field.stale ? 'Missing target' : inRepeat ? 'Repeat slot' : { mapped: 'Mapped', leave_empty: 'Leave empty', protected: 'Protected' }[ mode ] || 'Not mapped';
				if ( field.current_value !== undefined && field.current_value !== '' ) { contents.appendChild( el( 'p', 'nmd-current-value', String( field.current_value ).slice( 0, 600 ) ) ); }
				if ( field.stale ) { contents.appendChild( el( 'p', 'nmd-warning', 'This saved target is absent from the current reference. Its mapping is retained; restore or explicitly remove it after review.' ) ); contents.appendChild( button( 'Remove this missing target from the draft', function () { delete draft.fields[ field.path ]; if ( discardedTargets.indexOf( field.path ) < 0 ) { discardedTargets.push( field.path ); } changed(); renderFields(); } ) ); }
				else if ( field.writable === false ) { contents.appendChild( el( 'p', 'nmd-warning', 'This target is currently unavailable for writing. Discovery and saved mappings remain visible.' ) ); }
				var modeSelect = control( contents, 'Field behavior', select( [ { value: '', label: 'Not mapped' }, { value: 'mapped', label: 'Map a NOVA source', disabled: field.writable === false && mode !== 'mapped' }, { value: 'leave_empty', label: 'Leave empty' }, { value: 'protected', label: 'Protected' } ], mode ) );
				modeSelect.disabled = inRepeat || !! field.stale;
				modeSelect.addEventListener( 'change', function () {
					if ( modeSelect.value ) { draft.fields[ field.path ] = { mode: modeSelect.value, source_path: modeSelect.value === 'mapped' ? ( entry && entry.source_path || '' ) : '', instructions: entry && entry.instructions || '' }; }
					else { delete draft.fields[ field.path ]; }
					changed(); redraw(); renderRepeats();
				} );
				if ( inRepeat ) { contents.appendChild( el( 'p', 'nmd-help', 'Assigned in Fixed repeat slots below. Remove that slot binding to change this field’s behavior.' ) ); }
				if ( mode === 'mapped' ) {
					var sourceSelect = control( contents, 'NOVA source', select( [ { value: '', label: 'Choose a source…' } ].concat( sources( template() ).filter( function ( item ) { return item.source_path.indexOf( '[].' ) < 0; } ).map( function ( item ) { return { value: item.source_path, label: item.label + ' · ' + item.source_path }; } ) ), entry.source_path ) );
					sourceSelect.disabled = !! field.stale;
					sourceSelect.addEventListener( 'change', function () { draft.fields[ field.path ].source_path = sourceSelect.value; changed(); } );
				}
				if ( mode === 'leave_empty' ) { contents.appendChild( el( 'p', 'nmd-help', 'Existing pages: omit this field. New clones: blank this field. These are saved instructions; this editor does not change content.' ) ); }
				if ( mode === 'protected' ) { contents.appendChild( el( 'p', 'nmd-help', 'Preserve this component on updates and clones, including its content, settings, identity and position. Writer support must be verified before publication.' ) ); }
				if ( mode === 'protected' ) {
					var slotChoices = [ { value: '', label: 'Additional native protection' } ].concat( list( template() && template().protected_slots ).map( function ( slot ) { return { value: slot.key, label: slot.label || slot.key }; } ) );
					var protectedSlot = control( contents, 'Protected template region', select( slotChoices, entry.protected_slot || '' ) );
					protectedSlot.disabled = !! field.stale;
					protectedSlot.addEventListener( 'change', function () { draft.fields[ field.path ].protected_slot = protectedSlot.value; changed(); } );
				}
				if ( mode ) { var instructions = control( contents, 'Instructions for this field', textInput( entry.instructions, true ) ); instructions.maxLength = 8000; instructions.disabled = !! field.stale; instructions.addEventListener( 'input', function () { draft.fields[ field.path ].instructions = instructions.value; changed(); } ); }
				if ( field.write_mode === 'complete_parent' ) { contents.appendChild( el( 'p', 'nmd-warning', 'Nested field: the current writer sends the complete parent. Keep this mapping; protected publication needs verification of all affected siblings.' ) ); }
				var details = el( 'details', 'nmd-target-details' ); details.append( el( 'summary', '', 'Target details' ), el( 'code', '', field.path ) ); if ( field.transport ) { details.appendChild( el( 'p', 'nmd-help', field.transport + ( field.write_mode ? ' · ' + field.write_mode : '' ) ) ); } contents.appendChild( details );
			}
			redraw(); return card;
		}
		function renderFields() { if ( ! fieldsBox ) { return; } fieldsBox.replaceChildren(); cards.clear(); allFields().forEach( function ( field ) { fieldsBox.appendChild( renderField( field ) ); } ); if ( ! fieldInventory.length ) { fieldsBox.prepend( el( 'p', 'nmd-help', 'No target fields were discovered for this reference.' ) ); } }
		function newSlotId() { return 'slot_' + ( typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function' ? crypto.randomUUID().replace( /-/g, '_' ) : Date.now().toString( 36 ) + '_' + Math.random().toString( 36 ).slice( 2 ) ); }
		function renderRepeats() {
			if ( ! repeatBox ) { return; } repeatBox.replaceChildren();
			var groups = list( template() && template().groups ), known = new Set( groups.map( function ( group ) { return group.key; } ) );
			Object.keys( draft.repeat_slots || {} ).forEach( function ( key ) { if ( ! known.has( key ) ) { groups.push( { key: key, label: key + ' (saved; unavailable)', member_keys: Array.from( new Set( ( draft.repeat_slots[ key ] || [] ).flatMap( function ( slot ) { return Object.keys( slot.targets || {} ); } ) ) ), max: 12, stale: true } ); } } );
			groups.forEach( function ( group ) {
				var section = el( 'section', 'nmd-repeat-group' ); section.appendChild( el( 'h4', '', group.label || group.key ) );
				var slots = draft.repeat_slots[ group.key ] || [], maximum = Math.min( 12, Number( group.max === undefined ? 12 : group.max ) );
				section.appendChild( el( 'p', 'nmd-help', ( group.min || 0 ) + '–' + maximum + ' slots allowed. Each slot points to an existing region; adding or removing a mapping slot does not change CMS rows. These local slot IDs are not NOVA content instance IDs.' ) );
				slots.forEach( function ( slot, index ) {
					var row = el( 'div', 'nmd-repeat-slot' ), top = el( 'div', 'nmd-repeat-head' ); top.append( el( 'strong', '', 'Slot ' + ( index + 1 ) ), button( 'Remove mapping slot', function () { Object.values( slot.targets || {} ).forEach( discardMissing ); draft.repeat_slots[ group.key ].splice( index, 1 ); if ( ! draft.repeat_slots[ group.key ].length ) { delete draft.repeat_slots[ group.key ]; } changed(); renderRepeats(); renderFields(); } ) ); row.appendChild( top );
					members( group ).forEach( function ( member ) {
						var current = ( slot.targets || {} )[ member ] || '', choices = [ { value: '', label: 'Choose an existing target…' } ];
						fieldInventory.forEach( function ( field ) { choices.push( { value: field.path, label: field.label || field.path, disabled: field.path !== current && ( field.writable === false || !! draft.fields[ field.path ] || repeatUse( field.path ) ) } ); } );
						var targetSelect = control( row, member, select( choices, current ) ); targetSelect.disabled = !! group.stale;
						if ( current && ! fieldInventory.some( function ( field ) { return field.path === current; } ) ) { row.appendChild( el( 'p', 'nmd-warning', 'Saved target is missing: ' + current + '. Choose a replacement or remove this binding explicitly.' ) ); }
						targetSelect.addEventListener( 'change', function () { discardMissing( current ); if ( targetSelect.value ) { slot.targets[ member ] = targetSelect.value; } else { delete slot.targets[ member ]; } changed(); renderRepeats(); renderFields(); } );
					} );
					section.appendChild( row );
				} );
				var add = button( 'Add mapping slot', function () { if ( ! draft.repeat_slots[ group.key ] ) { draft.repeat_slots[ group.key ] = []; } draft.repeat_slots[ group.key ].push( { id: newSlotId(), targets: {} } ); changed(); renderRepeats(); } ); add.disabled = slots.length >= maximum || !! group.stale; section.appendChild( add ); repeatBox.appendChild( section );
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
			if ( busy ) { return; }
			var check = coverage( draft, template(), fieldInventory );
			if ( check.duplicates.length ) { note( 'Each repeat target must be unique. Resolve duplicate target bindings before saving.', true ); return; }
			if ( draft.skipped_sources.some( function ( item ) { return ! item.reason || ! item.reason.trim(); } ) ) { note( 'Add a reason for each explicitly skipped source, or remove the skip. Unmapped sources can still be saved.', true ); return; }
			setBusy( true ); note( 'Saving local draft…' );
			try {
				var result = await request( base + '/draft', config.nonce, payload( draft, { signature: layout.signature, confirm_reference_change: referenceConfirmed, discard_stale_targets: discardedTargets } ), abort && abort.signal ); if ( ! active() ) { return; }
				draft = saveResult( result ); dirty = false; discardedTargets = []; referenceConfirmed = false; serverWarnings = result.warnings || draft.warnings || []; if ( result.catalog ) { catalog = result.catalog; }
				render(); note( 'Draft saved. Synchronize it to NOVA when ready.' ); if ( callbacks.onSaved ) { callbacks.onSaved( copy( draft ) ); } loadIntegration();
			} catch ( error ) {
				if ( active() ) { note( error.status === 409 ? 'Save conflict: ' + error.message + ' Your edits are retained. Export them before reopening this reference to reconcile the latest saved draft.' : error.message, true ); }
			} finally { if ( active() ) { setBusy( false ); } }
		}
		function exportDraft() {
			var data = { kind: 'nova_mapping_integration_draft', local_only: true, synchronized: false, activation: 'not_requested', review_status: dirty || ! draft.revision ? 'unsaved_local_changes' : 'saved_local_draft', exported_at: new Date().toISOString(), draft: copy( draft ), reconciliation: { signature: layout.signature, confirm_reference_change: referenceConfirmed, discard_stale_targets: discardedTargets.slice() }, checks: coverage( draft, template(), fieldInventory ) };
			var url = URL.createObjectURL( new Blob( [ JSON.stringify( data, null, 2 ) ], { type: 'application/json' } ) ), anchor = el( 'a' ); anchor.href = url; anchor.download = 'nova-mapping-draft-' + draft.reference_id + '.json'; host.appendChild( anchor ); anchor.click(); anchor.remove(); setTimeout( function () { URL.revokeObjectURL( url ); }, 0 );
		}
		function referencePayload() { return { reference_type: draft.reference_type, reference_id: draft.reference_id, signature: layout.signature, expected_revision: draft.revision || '' }; }
		async function loadIntegration() {
			if ( ! active() || ! config.postingIntegration ) { return; }
			try {
				var query = '?reference_type=' + encodeURIComponent( draft.reference_type ) + '&reference_id=' + encodeURIComponent( draft.reference_id ) + '&signature=' + encodeURIComponent( layout.signature );
				var result = await request( base + '/sync-state' + query, config.nonce, undefined, abort && abort.signal );
				if ( active() ) { syncState = result.state || null; renderIntegration(); }
			} catch ( error ) { if ( active() && integrationBox ) { integrationBox.replaceChildren( el( 'p', 'nmd-warning', 'Synchronization status unavailable: ' + error.message ), button( 'Refresh synchronization status', loadIntegration ) ); } }
		}
		async function integrate( action, resume ) {
			if ( busy ) { return; }
			if ( dirty || ( ! resume && ( ! draft.revision || draft.catalog_mode !== 'nova' ) ) ) { note( 'Save a draft using the connected NOVA catalog before synchronization or activation.', true ); return; }
			setBusy( true );
			try {
				var data = referencePayload(); if ( resume ) { data.resume_pending = true; data.expected_revision = syncState.local_revision; }
				var result = await request( base + '/' + action, config.nonce, data, abort && abort.signal );
				if ( ! active() ) { return; } syncState = result.state || null; renderIntegration();
				note( action === 'activate' ? 'Activation request completed. Review the NOVA status below.' : 'Synchronization request completed. Review the NOVA status below.' );
			} catch ( error ) { if ( active() ) { note( error.message, true ); await loadIntegration(); } }
			finally { if ( active() ) { setBusy( false ); } }
		}
		function renderIntegration() {
			if ( ! integrationBox ) { return; } integrationBox.replaceChildren();
			integrationBox.appendChild( el( 'h4', '', 'NOVA synchronization and publishing' ) );
			var same = syncState && syncState.local_revision === draft.revision, stateName = syncState && syncState.status || 'not_synced';
			integrationBox.appendChild( el( 'p', 'nmd-help', 'Status: ' + stateName.replace( /_/g, ' ' ) + ( syncState && ! same ? ' (an earlier local revision)' : '' ) ) );
			if ( syncState && syncState.error ) { integrationBox.appendChild( el( 'p', 'nmd-warning', warningText( syncState.error ) ) ); }
			var synchronize = button( 'Synchronize to NOVA', function () { integrate( 'sync', false ); } ); synchronize.disabled = dirty || ! draft.revision || draft.catalog_mode !== 'nova'; integrationBox.appendChild( synchronize );
			if ( syncState && stateName === 'pending' ) { var resume = button( 'Resume pending synchronization', function () { integrate( 'sync', true ); } ); resume.disabled = dirty; integrationBox.appendChild( resume ); }
			var activate = button( 'Validate and activate mapping', function () { integrate( 'activate', false ); } ); activate.disabled = dirty || ! same || [ 'synced_draft', 'sealed', 'active' ].indexOf( stateName ) < 0; integrationBox.appendChild( activate );
			integrationBox.appendChild( button( 'Refresh status', loadIntegration ) );
			integrationBox.appendChild( el( 'p', 'nmd-help', 'Synchronization saves configuration in NOVA. Activation validates the mapping and available WordPress writer. Confirm service compatibility with NOVA before unpausing, then verify a controlled test delivery.' ) );
		}
		function render() {
			host.replaceChildren();
			var header = el( 'header', 'nmd-header' ); header.append( el( 'h3', '', 'NOVA mapping draft' ), el( 'span', 'nmd-local-badge', 'Draft editor' ) ); host.appendChild( header );
			feedback = el( 'div', 'nmd-feedback' ); feedback.setAttribute( 'role', 'status' ); feedback.setAttribute( 'aria-live', 'polite' ); host.appendChild( feedback );
			body = el( 'fieldset', 'nmd-body' ); body.disabled = busy; var legend = el( 'legend', 'screen-reader-text', 'NOVA mapping draft settings' ); body.appendChild( legend ); host.appendChild( body );
			var catalogNote = el( 'div', 'nmd-catalog-note' );
			if ( catalog.origin !== 'nova' ) { catalogNote.appendChild( el( 'p', '', catalog.origin === 'preview' ? 'Documented preview catalog. These field definitions and IDs are local examples, not an active NOVA configuration.' : catalog.message || 'The NOVA catalog is not connected yet. Use the documented preview fields to prepare a local draft.' ) ); if ( catalog.origin !== 'preview' ) { catalogNote.appendChild( button( 'Use documented preview fields', choosePreview ) ); } }
			else { catalogNote.appendChild( el( 'p', '', 'NOVA catalog loaded. Save and synchronize your mapping, then validate it for publishing.' ) ); }
			body.appendChild( catalogNote );
			if ( inventoryStale ) { body.appendChild( el( 'p', 'nmd-warning', 'The reference changed while this editor was loading. Export any edits, then refresh the layout inventory before saving.' ) ); }
			else if ( draft.signature !== layout.signature ) { var review = el( 'div', 'nmd-warning' ), reviewLabel = el( 'label', 'nmd-checkbox' ), reviewCheck = el( 'input' ); reviewCheck.type = 'checkbox'; reviewCheck.checked = referenceConfirmed; reviewLabel.append( reviewCheck, el( 'span', '', 'I reviewed the saved targets against this changed layout.' ) ); review.append( el( 'p', '', 'This draft was saved for an earlier layout. Reconcile missing targets and verify all retained bindings.' ), reviewLabel ); reviewCheck.addEventListener( 'change', function () { referenceConfirmed = reviewCheck.checked; changed(); } ); body.appendChild( review ); }
			var templates = ( catalog.templates || [] ).slice(), selected = draft.template && draft.template.id ? draft.template.id + '::' + draft.template.revision : '';
			if ( template() && ! templates.some( function ( item ) { return item.id === template().id && String( item.revision ) === String( template().revision ); } ) ) { templates.push( Object.assign( {}, template(), { label: template().label + ' (cached definition)' } ) ); body.appendChild( el( 'p', 'nmd-help', 'Source choices use the saved exact template definition. The live revision is unavailable; this does not establish current NOVA support.' ) ); }
			var templateSelect = control( body, 'Writing template', select( [ { value: '', label: 'Choose a template…' } ].concat( templates.map( function ( item ) { return { value: item.id + '::' + item.revision, label: item.label + ' · ' + item.family }; } ) ), selected ) );
			templateSelect.addEventListener( 'change', function () { var chosen = templates.find( function ( item ) { return item.id + '::' + item.revision === templateSelect.value; } ); draft.template = chosen ? { id: chosen.id, revision: chosen.revision } : { id: '', revision: '' }; changed(); render(); note( 'Template selection changed. Existing bindings and skips are retained for review.' ); } );
			var label = control( body, 'Mapping label', textInput( draft.label ) ); label.maxLength = 120; label.addEventListener( 'input', function () { draft.label = label.value; changed(); } );
			var guidance = control( body, 'Instructions for this layout', textInput( draft.guidance, true ) ); guidance.maxLength = 8000; guidance.addEventListener( 'input', function () { draft.guidance = guidance.value; changed(); } );
			coverageBox = el( 'div', 'nmd-coverage' ); coverageBox.setAttribute( 'aria-live', 'polite' ); body.appendChild( coverageBox );
			body.appendChild( el( 'h4', '', 'Page and template fields' ) ); body.appendChild( el( 'p', 'nmd-help', 'Select a field here or on the page preview. Nested ACF fields remain available.' ) );
			var search = el( 'input', 'nmd-search' ); search.type = 'search'; search.placeholder = 'Find a target field…'; search.setAttribute( 'aria-label', 'Find a target field' ); body.appendChild( search );
			fieldsBox = el( 'div', 'nmd-fields' ); body.appendChild( fieldsBox ); renderFields(); search.addEventListener( 'input', function () { var query = search.value.toLowerCase().trim(); cards.forEach( function ( card, path ) { card.hidden = ( card.textContent + ' ' + path ).toLowerCase().indexOf( query ) < 0; } ); } );
			renderSkips( body );
			var repeats = el( 'details', 'nmd-advanced' ); repeats.appendChild( el( 'summary', '', 'Fixed repeat slots' ) ); repeatBox = el( 'div' ); repeats.appendChild( repeatBox ); body.appendChild( repeats ); renderRepeats();
			var routing = el( 'details', 'nmd-advanced' ); routing.appendChild( el( 'summary', '', 'Target and routing' ) ); routing.appendChild( el( 'p', 'nmd-help', 'Reference: ' + draft.reference_type + ' #' + draft.reference_id + ( layout.path ? ' · ' + layout.path : '' ) ) ); routing.appendChild( el( 'code', 'nmd-signature', 'Layout: ' + draft.signature ) );
			var operations = [ { value: 'update', label: draft.reference_type === 'term' ? 'Update an existing category or term' : 'Update an existing page' } ]; if ( draft.reference_type !== 'term' ) { operations.push( { value: 'clone', label: 'Clone this reference as a new page' } ); }
			var operation = control( routing, 'Intended operation', select( operations, draft.routing.operation ), draft.reference_type === 'term' ? 'Cloning category and term references is not supported.' : '' ); operation.addEventListener( 'change', function () { draft.routing.operation = operation.value; changed(); } );
			if ( draft.reference_type !== 'term' ) {
				var publication = control( routing, 'Publication policy', select( [ { value: 'preserve', label: 'Keep existing status; new clones stay draft' }, { value: 'publish', label: 'Publish after validation and recovery work completes' }, { value: 'draft', label: 'Save as draft' } ], draft.routing.publication || ( draft.routing.operation === 'clone' ? 'draft' : 'preserve' ) ) );
				publication.addEventListener( 'change', function () { draft.routing.publication = publication.value; changed(); } );
			}
			var locale = control( routing, 'Locale', textInput( draft.routing.locale ), 'Optional workflow locale. Saving does not create or translate a page.' ); locale.maxLength = 35; locale.addEventListener( 'input', function () { draft.routing.locale = locale.value; changed(); } ); body.appendChild( routing );
			var selectedTemplate = template();
			if ( selectedTemplate && ( selectedTemplate.protected_slots || [] ).length ) { var protectedInfo = el( 'details', 'nmd-advanced' ); protectedInfo.append( el( 'summary', '', 'Template protected regions' ), el( 'p', 'nmd-help', 'Keep these native components outside generated source bindings: ' + selectedTemplate.protected_slots.map( function ( slot ) { return slot.label || slot.key; } ).join( ', ' ) + '. Mark their discovered targets Protected above.' ) ); body.appendChild( protectedInfo ); }
			var footer = el( 'div', 'nmd-savebar' ); saveButton = button( 'Save local draft', save, true ); footer.append( saveButton, button( 'Export integration draft', exportDraft ) ); if ( callbacks.onCancel ) { footer.appendChild( button( 'Close', callbacks.onCancel ) ); } status = el( 'span', 'nmd-save-state', dirty ? 'Unsaved local changes' : draft.revision ? 'Local draft saved' : 'No local draft saved' ); footer.appendChild( status ); body.appendChild( footer );
			integrationBox = null; if ( config.postingIntegration ) { integrationBox = el( 'section', 'nmd-integration' ); body.appendChild( integrationBox ); renderIntegration(); } refreshCoverage();
		}
		async function loadDraft() {
		if ( ! active() ) { return; } host.replaceChildren( el( 'p', 'nmd-help', 'Loading NOVA mapping draft…' ) ); host.setAttribute( 'aria-busy', 'true' );
		try {
			var query = '?reference_type=' + encodeURIComponent( draft.reference_type ) + '&reference_id=' + encodeURIComponent( draft.reference_id ) + '&signature=' + encodeURIComponent( draft.signature );
			var loaded = await request( base + '/draft' + query, config.nonce, undefined, abort && abort.signal );
			if ( active() ) { draft = initialDraft( layout, loaded.draft ); draft.fields = draft.fields || {}; draft.repeat_slots = draft.repeat_slots || {}; draft.skipped_sources = draft.skipped_sources || []; draft.routing = draft.routing || { operation: 'update', locale: '' }; inventoryStale = !! ( loaded.reference && loaded.reference.signature !== layout.signature ); serverWarnings = loaded.warnings || []; catalog = loaded.catalog || await request( base + '/catalog?mode=' + encodeURIComponent( draft.catalog_mode || 'nova' ), config.nonce, undefined, abort && abort.signal ); if ( active() ) { render(); setBusy( false ); } }
		} catch ( error ) {
			if ( active() ) { host.replaceChildren(); var failure = el( 'div', 'nmd-feedback is-error', error.message + ' The saved draft could not be loaded; editing is disabled to avoid replacing it.' ); failure.setAttribute( 'role', 'alert' ); host.appendChild( failure ); host.appendChild( button( 'Retry loading', loadDraft ) ); host.setAttribute( 'aria-busy', 'false' ); }
		}
		}
		var controller = { selectField: function ( path ) { focusField( path, false ); }, getDraft: function () { return copy( draft ); }, isDirty: function () { return dirty; }, destroy: function () { disposed = true; if ( abort ) { abort.abort(); } host.remove(); } };
		controller.ready = loadDraft().then( loadIntegration );
		return controller;
	}
	return { mount: mount, initialDraft: initialDraft, payload: payload, coverage: coverage, request: request, saveResult: saveResult, resolveTemplate: resolveTemplate };
} ) );
