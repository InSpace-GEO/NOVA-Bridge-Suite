( function ( root ) {
    'use strict';
    var kinds = [
        { key: 'heading', label: 'Heading', help: 'Each heading becomes its own element. Keep the original heading level by default.', elements: [ [ 'heading', 'Heading' ], [ 'text-editor', 'Text editor' ] ] },
        { key: 'paragraph', label: 'Paragraph', help: 'Each paragraph becomes a text editor, in its original position.', elements: [ [ 'text-editor', 'Text editor' ] ] },
        { key: 'rich_text', label: 'Other rich HTML', help: 'Preserve supported HTML that does not match a simpler block.', elements: [ [ 'text-editor', 'Text editor' ] ] },
        { key: 'list', label: 'List', help: 'Keep ordered and unordered lists together as rich text.', elements: [ [ 'text-editor', 'Text editor' ] ] },
        { key: 'image', label: 'Image', help: 'Use each supplied image URL and its alternative text.', elements: [ [ 'image', 'Image' ] ] },
        { key: 'faq', label: 'FAQ', help: 'Repeat the question and answer for every supplied FAQ item. Questions are not guessed from prose.', elements: [ [ 'accordion', 'Accordion' ], [ 'container', 'Question and answer containers' ] ] },
        { key: 'group', label: 'Nested group', help: 'Create a container and apply these same rules to its children. Repeated groups grow with the supplied items.', elements: [ [ 'container', 'Container' ] ] }
    ];
    var sampleHtml = '<h1>A clearer way to build pages</h1>\n<p>Turn your content into a page that follows your own layout rules.</p>\n<h2>How it works</h2>\n<p>Every heading and paragraph becomes its own Elementor element.</p>\n<ul><li>Keep the original order</li><li>Reuse a local profile</li><li>Review the resulting draft</li></ul>';
    var structuredSample = { blocks: [ { type: 'heading', level: 1, html: 'A flexible service page' }, { type: 'group', children: [ { type: 'heading', level: 2, html: 'Our process' }, { type: 'paragraph', html: 'A group can contain any supported child blocks.' }, { type: 'group', children: [ { type: 'heading', level: 3, html: 'Your next step' }, { type: 'paragraph', html: 'Nested groups use the same content rules.' } ] } ] }, { type: 'faq', items: [ { question: 'Can the page grow?', answer: '<p>Yes. Every supplied item gets its own element or group.</p>' }, { question: 'Do I need an existing page?', answer: '<p>No. New element identities are created for this draft.</p>' } ] } ] };
    function copy( value ) { return JSON.parse( JSON.stringify( value ) ); }
    function uuid() { if ( root.crypto && root.crypto.randomUUID ) { return root.crypto.randomUUID(); } throw new Error( 'A secure browser context is required to create a profile.' ); }
    function newProfile( preset ) { var value = copy( preset ); value.id = uuid(); value.revision = 0; value.label = 'My Blog profile'; return value; }
    function parseInput( mode, value ) {
        if ( mode === 'html' ) { return { html: value }; }
        var parsed;
        try { parsed = JSON.parse( value ); } catch ( error ) { throw new Error( 'The structured sample needs valid JSON.' ); }
        if ( Array.isArray( parsed ) ) { parsed = { blocks: parsed }; }
        if ( ! parsed || typeof parsed !== 'object' || ! Array.isArray( parsed.blocks ) ) { throw new Error( 'Use an object with a blocks array, or a blocks array.' ); }
        return parsed;
    }
    function profileContent( profile ) { var value = copy( profile ); delete value.revision; return JSON.stringify( value ); }
    function mergeSavedProfile( current, submitted, saved ) {
        if ( profileContent( current ) === profileContent( submitted ) ) { return { profile: copy( saved ), dirty: false }; }
        var retained = copy( current ); retained.id = saved.id; retained.revision = saved.revision;
        return { profile: retained, dirty: true };
    }
    function countBlocks( blocks ) { return ( blocks || [] ).reduce( function ( count, block ) { return count + 1 + ( block.type === 'group' ? countBlocks( block.children ) : block.type === 'faq' ? ( block.items || [] ).length : 0 ); }, 0 ); }
    function operationIdentity( previous, profile, input ) {
        var signature = JSON.stringify( [ profile.id, profile.revision, input ] );
        return previous && previous.signature === signature ? previous : { signature: signature, id: uuid() };
    }
    async function rememberedOperation( previous, profile, input, suppliedStorage ) {
        var operation = operationIdentity( previous, profile, input );
        try {
            var storage = suppliedStorage === undefined ? root.sessionStorage : suppliedStorage;
            if ( ! storage || ! root.crypto || ! root.crypto.subtle ) { return operation; }
            var bytes = await root.crypto.subtle.digest( 'SHA-256', new TextEncoder().encode( operation.signature ) );
            var digest = Array.from( new Uint8Array( bytes ) ).map( function ( byte ) { return byte.toString( 16 ).padStart( 2, '0' ); } ).join( '' );
            var prefix = 'nova-content-rule-operation:', key = prefix + digest, retained = storage.getItem( key );
            if ( retained && ! operation.force_new ) { var saved; try { saved = JSON.parse( retained ); } catch ( error ) { saved = null; } if ( saved && typeof saved.id === 'string' && /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test( saved.id ) ) { operation.id = saved.id; } }
            // Retain only retry identities, never the article or profile content.
            storage.setItem( key, JSON.stringify( { id: operation.id, created_at: Date.now() } ) );
            operation.storage_key = key; delete operation.force_new;
            var keys = [];
            for ( var index = 0; index < storage.length; index++ ) { var candidate = storage.key( index ); if ( candidate && candidate.indexOf( prefix ) === 0 ) { var entry; try { entry = JSON.parse( storage.getItem( candidate ) ); } catch ( error ) { entry = null; } keys.push( { key: candidate, time: entry && Number( entry.created_at ) || 0 } ); } }
            keys.sort( function ( first, second ) { return first.key === key ? -1 : second.key === key ? 1 : second.time - first.time; } );
            keys.slice( 20 ).forEach( function ( entry ) { storage.removeItem( entry.key ); } );
        } catch ( error ) { /* Restricted browser storage keeps same-editor retry protection. */ }
        return operation;
    }
    function anotherOperation( previous, suppliedStorage ) {
        if ( ! previous ) { throw new Error( 'Create and verify the first draft before requesting another.' ); }
        var next = { signature: previous.signature, id: uuid(), force_new: true };
        try {
            var storage = suppliedStorage === undefined ? root.sessionStorage : suppliedStorage, key = previous.storage_key;
            if ( storage && typeof key === 'string' && /^nova-content-rule-operation:[0-9a-f]{64}$/.test( key ) ) { var retained = JSON.parse( storage.getItem( key ) || 'null' ); if ( retained && retained.id === previous.id ) { storage.removeItem( key ); } }
        } catch ( error ) { /* The fresh intent still bypasses any old cache value when storage returns. */ }
        return next;
    }
    function safeHref( value, origin ) { try { var base = new URL( origin || root.location && root.location.href || 'https://example.invalid/' ), url = new URL( value, base ); return [ 'http:', 'https:' ].indexOf( url.protocol ) >= 0 && url.origin === base.origin && ! url.username && ! url.password ? url.href : ''; } catch ( error ) { return ''; } }
    function normalizedBinding( value ) { return value && typeof value.profile_id === 'string' && Number.isSafeInteger( value.profile_revision ) && value.profile_revision > 0 ? value : null; }
    function draftResultLinks( result, operationId, origin ) {
        if ( ! result || ! Number.isSafeInteger( result.post_id ) || result.post_id < 1 || result.status !== 'draft' || result.operation_uuid !== operationId || ! safeHref( result.edit_url, origin ) ) { throw new Error( 'The response did not verify a native draft. Keep the same content and retry its retained operation, or reconcile the draft in WordPress.' ); }
        return [ [ result.elementor_edit_url, 'Edit with Elementor' ], [ result.edit_url, 'Open WordPress draft' ], [ result.preview_url, 'View draft' ] ].map( function ( item ) { return [ item[0] && safeHref( item[0], origin ), item[1] ]; } ).filter( function ( item ) { return !! item[0]; } );
    }
    async function request( config, path, payload ) {
        var response = await root.fetch( config.url + path, { method: payload === undefined ? 'GET' : 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': config.nonce, 'Content-Type': 'application/json' }, body: payload === undefined ? undefined : JSON.stringify( payload ) } );
        var result = await response.json();
        if ( ! response.ok ) { var error = new Error( result.message || 'The request could not be completed.' ); error.status = response.status; throw error; }
        return result;
    }
    function mount( host, config ) {
        var doc = host.ownerDocument, profiles = [], preset, profile, dirty = false, baseline = '', mode = 'html', sample = sampleHtml, busy = false, previewSequence = 0, operation = null, verifiedDraft = false, siteId = '', binding = null;
        function el( tag, className, text ) { var node = doc.createElement( tag ); if ( className ) { node.className = className; } if ( text !== undefined ) { node.textContent = text; } return node; }
        function button( label, action, primary ) { var node = el( 'button', 'button' + ( primary ? ' button-primary' : '' ), label ); node.type = 'button'; node.addEventListener( 'click', action ); return node; }
        function select( options, value ) { var node = el( 'select' ); options.forEach( function ( item ) { var option = el( 'option', '', item[1] ); option.value = item[0]; node.appendChild( option ); } ); node.value = value; return node; }
        function field( parent, label, node, hint ) { var wrap = el( 'label', 'ncr-control' ); wrap.append( el( 'span', 'ncr-label', label ), node ); if ( hint ) { wrap.appendChild( el( 'span', 'ncr-hint', hint ) ); } parent.appendChild( wrap ); return node; }
        function number( value, min, max ) { var node = el( 'input' ); node.type = 'number'; node.min = min; node.max = max; node.value = value === undefined ? '' : value; return node; }
        function input( value ) { var node = el( 'input' ); node.type = 'text'; node.value = value || ''; return node; }
        host.replaceChildren();
        var header = el( 'div', 'ncr-header' ); header.append( el( 'p', 'ncr-eyebrow', 'LOCAL WORDPRESS PROFILES' ), el( 'h2', '', 'Build from content' ), el( 'p', '', 'Choose how each content type becomes an Elementor element. The page grows with your content; no existing page is needed.' ) ); host.appendChild( header );
        var notice = el( 'div', 'ncr-notice' ); notice.setAttribute( 'role', 'status' ); notice.setAttribute( 'aria-live', 'polite' ); host.appendChild( notice );
        var toolbar = el( 'div', 'ncr-toolbar' ), profileSelect = select( [], '' ), newButton = button( 'New Blog preset', createNew ), reconcileButton = button( 'Load latest saved revision', reconcile ); reconcileButton.hidden = true; field( toolbar, 'Local profile', profileSelect ); toolbar.append( newButton, reconcileButton ); host.appendChild( toolbar );
        var columns = el( 'div', 'ncr-columns' ), editor = el( 'section', 'ncr-editor' ), previewPanel = el( 'section', 'ncr-preview' ); columns.append( editor, previewPanel ); host.appendChild( columns );
        editor.append( el( 'h3', '', '1. Choose your layout rules' ) );
        var basic = el( 'div', 'ncr-basics' ), labelInput = field( basic, 'Profile name', input( '' ) ), postType = field( basic, 'Create as', select( [], '' ) ); editor.appendChild( basic );
        var rules = el( 'div', 'ncr-rules' ); editor.appendChild( rules );
        var layoutDetails = el( 'details', 'ncr-settings' ); layoutDetails.appendChild( el( 'summary', '', 'Page spacing and empty content' ) );
        var layoutFields = el( 'div', 'ncr-settings-grid' ), width = field( layoutFields, 'Content width (px)', number( 1140, 320, 2400 ) ), gap = field( layoutFields, 'Element gap (px)', number( 24, 0, 120 ) ); layoutDetails.appendChild( layoutFields );
        var hideEmpty = el( 'input' ); hideEmpty.type = 'checkbox'; field( layoutDetails, 'Skip empty content', hideEmpty ); editor.appendChild( layoutDetails );
        var saveRow = el( 'div', 'ncr-actions' ), saveButton = button( 'Save local profile', save, true ), savedState = el( 'span', 'ncr-hint' ); saveRow.append( saveButton, savedState ); editor.appendChild( saveRow );
        editor.append( el( 'h3', 'ncr-content-heading', '2. Try your content' ), el( 'p', 'ncr-hint', 'Paste HTML below, or use structured content for explicit FAQs and nested groups. Content stays in this WordPress site.' ) );
        var modeSelect = field( editor, 'Sample format', select( [ [ 'html', 'HTML' ], [ 'structured', 'Structured groups and FAQs' ] ], mode ) );
        var titleInput = field( editor, 'New draft title', input( 'Content layout draft' ), 'The WordPress title is separate from headings inside your content.' );
        var sampleInput = el( 'textarea', 'ncr-sample' ); sampleInput.rows = 12; sampleInput.value = sample; sampleInput.setAttribute( 'aria-label', 'Sample content' ); editor.appendChild( sampleInput );
        var previewButton = button( 'Update preview', preview, true ); editor.appendChild( previewButton );
        previewPanel.append( el( 'h3', '', 'Content preview' ), el( 'p', 'ncr-hint', 'This shows content order and grouping. Open the created draft to review the exact Elementor styling.' ) );
        var previewState = el( 'p', 'ncr-preview-state', 'Update the preview to see your content.' ), iframe = el( 'iframe', 'ncr-frame' ); iframe.title = 'Content layout preview'; iframe.setAttribute( 'sandbox', '' ); iframe.setAttribute( 'referrerpolicy', 'no-referrer' ); previewPanel.append( previewState, iframe );
        var warnings = el( 'ul', 'ncr-warnings' ); previewPanel.appendChild( warnings );
        var createBox = el( 'div', 'ncr-create' ); createBox.append( el( 'h3', '', '3. Create an Elementor draft' ), el( 'p', 'ncr-hint', 'Save the profile first. Creating a draft does not publish it.' ) ); var createButton = button( 'Create Elementor draft', createDraft, true ), anotherButton = button( 'Create another draft', createAnother ), draftLinks = el( 'div', 'ncr-draft-links' ); anotherButton.hidden = true; createBox.append( createButton, draftLinks, anotherButton ); previewPanel.appendChild( createBox );
        var deliveryDetails = el( 'details', 'ncr-delivery' ); deliveryDetails.append( el( 'summary', '', 'Optional: use a profile for fetched deliveries' ), el( 'p', '', 'Uses main article HTML from unconfigured deliveries on the connected site. Supports pages and posts with a simple post-name URL, optionally under a fixed prefix, in the matching site language. First deliveries create drafts; later versions preserve the page’s publication status. Existing remote mappings keep their current path. No rules are sent to NOVA. Structured FAQs and groups can currently be tried with local sample content.' ) );
        var deliveryCheck = el( 'input' ); deliveryCheck.type = 'checkbox'; field( deliveryDetails, 'Use this saved profile for unconfigured deliveries', deliveryCheck ); var siteText = el( 'p', 'ncr-hint' ), bindButton = button( 'Save delivery preference', saveBinding ); deliveryDetails.append( siteText, bindButton ); host.appendChild( deliveryDetails );
        function note( text, error ) { notice.textContent = text; notice.className = 'ncr-notice' + ( error ? ' ncr-error' : '' ); }
        function state() { saveButton.disabled = busy || ! profile; newButton.disabled = busy || ! preset; reconcileButton.disabled = busy; previewButton.disabled = busy || ! profile; createButton.disabled = busy || ! profile || dirty || ! profile.revision; anotherButton.disabled = busy || ! verifiedDraft || dirty; anotherButton.hidden = ! verifiedDraft; bindButton.disabled = busy || ! siteId || ( deliveryCheck.checked && ( ! profile || dirty || ! profile.revision || [ 'page', 'post' ].indexOf( profile.post_type ) < 0 ) ); profileSelect.disabled = busy; deliveryCheck.disabled = busy || ! siteId; savedState.textContent = ! profile ? '' : dirty || ! profile.revision ? 'Unsaved changes' : 'Saved locally · revision ' + profile.revision; }
        function invalidate() { previewSequence++; previewState.textContent = 'Content changed. Update the preview to refresh it.'; draftLinks.replaceChildren(); operation = null; verifiedDraft = false; anotherButton.hidden = true; }
        function changed() { dirty = true; invalidate(); state(); }
        function setBusy( value ) { busy = value; var controls = host.querySelectorAll ? Array.from( host.querySelectorAll( 'input,select,textarea' ) ) : host.all().filter( function ( node ) { return [ 'input', 'select', 'textarea' ].indexOf( node.tag ) >= 0; } ); controls.forEach( function ( node ) { node.disabled = busy; } ); state(); }
        function refreshDeliveryPreference() {
            deliveryCheck.checked = !! ( binding && profile && binding.profile_id === profile.id && binding.profile_revision === profile.revision );
            var selected = binding && profiles.find( function ( item ) { return item.id === binding.profile_id; } );
            siteText.textContent = ! siteId ? 'Connect a posting site in Mapping to use this optional preference. Local profiles and draft creation work independently.' : 'Connected site: ' + siteId + '. ' + ( binding ? 'Currently uses ' + ( selected && selected.label || 'a local profile' ) + ' revision ' + binding.profile_revision + '. Saving profile edits does not replace this selected revision.' : 'Automatic use of local rules is off.' ) + ( profile && [ 'page', 'post' ].indexOf( profile.post_type ) < 0 ? ' This custom post type can create local drafts; it cannot be selected for fetched deliveries.' : '' );
        }
        function refreshProfiles() { profileSelect.replaceChildren(); var current = el( 'option', '', profile && ! profile.revision ? 'Unsaved profile' : 'Choose a saved profile' ); current.value = ''; profileSelect.appendChild( current ); profiles.forEach( function ( item ) { var option = el( 'option', '', item.label ); option.value = item.id; profileSelect.appendChild( option ); } ); profileSelect.value = profile && profile.revision ? profile.id : ''; }
        function renderRules() {
            rules.replaceChildren();
            kinds.forEach( function ( kind ) {
                var rule = profile.rules[ kind.key ], row = el( 'article', 'ncr-rule' ), head = el( 'div', 'ncr-rule-head' ); head.append( el( 'strong', '', kind.label ), el( 'span', 'ncr-arrow', '→' ) );
                var elementSelect = select( kind.elements, rule.element ); elementSelect.setAttribute( 'aria-label', kind.label + ' element' ); head.appendChild( elementSelect ); row.append( head, el( 'p', 'ncr-hint', kind.help ) );
                elementSelect.addEventListener( 'change', function () { rule.element = elementSelect.value; changed(); } );
                if ( kind.key === 'group' || kind.key === 'faq' ) { rules.appendChild( row ); return; }
                var style = el( 'details', 'ncr-rule-style' ); style.appendChild( el( 'summary', '', 'Style options' ) ); var grid = el( 'div', 'ncr-settings-grid' );
                var align = field( grid, 'Alignment', select( [ [ '', 'Theme default' ], [ 'left', 'Left' ], [ 'center', 'Center' ], [ 'right', 'Right' ] ], rule.settings.align || '' ) );
                function setting( control, key, numeric ) { control.addEventListener( 'input', function () { if ( control.value === '' ) { delete rule.settings[key]; } else { rule.settings[key] = numeric ? Number( control.value ) : control.value; } changed(); } ); }
                setting( align, 'align', false );
                if ( kind.key !== 'image' && kind.key !== 'faq' ) { var font = field( grid, 'Font size (px)', number( rule.settings.typography_font_size, 8, 96 ) ), color = field( grid, 'Text color', input( rule.settings.color || '' ) ); color.placeholder = '#202b3c'; setting( font, 'typography_font_size', true ); setting( color, 'color', false ); }
                if ( kind.key === 'heading' ) { var tag = field( grid, 'Heading level', select( [ [ '', 'Use content level' ], [ 'h1', 'H1' ], [ 'h2', 'H2' ], [ 'h3', 'H3' ], [ 'h4', 'H4' ], [ 'h5', 'H5' ], [ 'h6', 'H6' ] ], rule.settings.html_tag || '' ) ); setting( tag, 'html_tag', false ); }
                if ( [ 'paragraph', 'rich_text', 'list' ].indexOf( kind.key ) >= 0 ) { var lineHeight = field( grid, 'Line height', number( rule.settings.line_height, 0.5, 4 ) ); lineHeight.step = '0.1'; setting( lineHeight, 'line_height', true ); }
                style.appendChild( grid ); row.appendChild( style ); rules.appendChild( row );
            } );
        }
        function useProfile( value ) { profile = copy( value ); baseline = profileContent( profile ); dirty = ! profile.revision; labelInput.value = profile.label; postType.value = profile.post_type; width.value = profile.layout.max_width; gap.value = profile.layout.gap; hideEmpty.checked = profile.hide_empty; reconcileButton.hidden = true; renderRules(); refreshProfiles(); refreshDeliveryPreference(); invalidate(); state(); }
        function canSwitch() { return ! profile || profileContent( profile ) === baseline || ! root.confirm || root.confirm( 'Replace your unsaved profile edits?' ); }
        function createNew() { if ( ! canSwitch() ) { return; } try { useProfile( newProfile( preset ) ); note( 'Blog preset loaded. Name it and save your own local profile.' ); } catch ( error ) { note( error.message, true ); } }
        function reconcile() { var latest = profiles.find( function ( item ) { return item.id === profile.id; } ); if ( latest && canSwitch() ) { useProfile( latest ); note( 'Latest saved revision loaded. Review it before making further changes.' ); } }
        labelInput.addEventListener( 'input', function () { profile.label = labelInput.value; changed(); } ); postType.addEventListener( 'change', function () { profile.post_type = postType.value; changed(); } );
        width.addEventListener( 'input', function () { profile.layout.max_width = Number( width.value ); changed(); } ); gap.addEventListener( 'input', function () { profile.layout.gap = Number( gap.value ); changed(); } ); hideEmpty.addEventListener( 'change', function () { profile.hide_empty = hideEmpty.checked; changed(); } );
        titleInput.addEventListener( 'input', invalidate );
        deliveryCheck.addEventListener( 'change', state );
        sampleInput.addEventListener( 'input', function () { sample = sampleInput.value; invalidate(); } ); modeSelect.addEventListener( 'change', function () { mode = modeSelect.value; sample = mode === 'structured' ? JSON.stringify( structuredSample, null, 2 ) : sampleHtml; sampleInput.value = sample; invalidate(); } );
        profileSelect.addEventListener( 'change', function () { var selected = profiles.find( function ( item ) { return item.id === profileSelect.value; } ); if ( selected && canSwitch() ) { useProfile( selected ); note( 'Saved local profile loaded.' ); } else { refreshProfiles(); } } );
        async function save() {
            var submitted = copy( profile ); setBusy( true ); note( 'Saving your local profile…' );
            try { var saved = await request( config, '/profiles', { profile: submitted, expected_revision: submitted.revision } ), merged = mergeSavedProfile( profile, submitted, saved ); profiles = profiles.filter( function ( item ) { return item.id !== saved.id; } ); profiles.push( saved ); profile = merged.profile; baseline = profileContent( saved ); dirty = merged.dirty; reconcileButton.hidden = true; invalidate(); refreshProfiles(); refreshDeliveryPreference(); note( merged.dirty ? 'Profile saved. Your newer edits are still unsaved.' : 'Profile saved locally. No rules were sent to NOVA.' ); }
            catch ( error ) {
                if ( error.status === 409 ) {
                    try { var latest = await request( config, '/profiles' ); profiles = Array.isArray( latest.profiles ) ? latest.profiles : Object.values( latest.profiles || {} ); refreshProfiles(); reconcileButton.hidden = ! profiles.some( function ( item ) { return item.id === profile.id; } ); }
                    catch ( refreshError ) { note( error.message + ' Your edits are retained. The latest profile list could not be loaded; reload in another tab to reconcile.', true ); return; }
                    note( error.message + ' Your edits are retained. Load the latest saved revision to replace these edits, or keep them for review.', true );
                } else { note( error.message, true ); }
            }
            finally { setBusy( false ); }
        }
        async function preview() {
            var content; try { content = parseInput( mode, sample ); content.title = titleInput.value; } catch ( error ) { note( error.message, true ); return; }
            var sequence = ++previewSequence; setBusy( true ); note( 'Preparing the content preview…' );
            try { var result = await request( config, '/preview', { profile: copy( profile ), input: content } ); if ( sequence !== previewSequence ) { return; } if ( ! result || typeof result.preview_document !== 'string' || ! Array.isArray( result.blocks ) || ! Array.isArray( result.warnings ) ) { throw new Error( 'The server did not return a complete content preview.' ); } iframe.srcdoc = result.preview_document; previewState.textContent = countBlocks( result.blocks ) + ' content blocks · source order retained'; warnings.replaceChildren(); result.warnings.forEach( function ( message ) { warnings.appendChild( el( 'li', '', message ) ); } ); note( 'Preview ready. Save the profile before creating a draft.' ); }
            catch ( error ) { note( error.message, true ); }
            finally { setBusy( false ); }
        }
        async function createDraft() {
            setBusy( true );
            verifiedDraft = false; anotherButton.hidden = true;
            var content; try { content = parseInput( mode, sample ); content.title = titleInput.value; operation = await rememberedOperation( operation, profile, content ); } catch ( error ) { note( error.message, true ); setBusy( false ); return; }
            note( 'Creating the Elementor draft…' );
            try { var result = await request( config, '/create-draft', { profile_id: profile.id, profile_revision: profile.revision, input: content, operation_uuid: operation.id } ), links = draftResultLinks( result, operation.id ); draftLinks.replaceChildren(); links.forEach( function ( item ) { var link = el( 'a', 'button', item[1] ); link.href = item[0]; link.target = '_blank'; link.rel = 'noopener noreferrer'; draftLinks.appendChild( link ); } ); verifiedDraft = true; note( 'Elementor draft created. Open it to review the native layout before publishing.' ); }
            catch ( error ) { note( error.message + ' Retrying the same content keeps the same draft operation.', true ); }
            finally { setBusy( false ); }
        }
        function createAnother() { if ( busy || ! verifiedDraft || dirty ) { return; } try { operation = anotherOperation( operation ); verifiedDraft = false; draftLinks.replaceChildren(); return createDraft(); } catch ( error ) { note( error.message, true ); } }
        async function saveBinding() {
            setBusy( true ); note( 'Saving the local delivery preference…' );
            try { await request( config, '/binding', { site_id: siteId, enabled: deliveryCheck.checked, profile_id: profile.id, profile_revision: profile.revision } ); binding = deliveryCheck.checked ? { site_id: siteId, profile_id: profile.id, profile_revision: profile.revision } : null; refreshDeliveryPreference(); note( deliveryCheck.checked ? 'Local profile selected for unconfigured deliveries from this site. This does not activate the posting connection.' : 'Automatic use of this local profile is off.' ); }
            catch ( error ) { note( error.message, true ); }
            finally { setBusy( false ); }
        }
        setBusy( true );
        var ready = ( async function () {
            try { var result = await request( config, '/profiles' ); profiles = Array.isArray( result.profiles ) ? result.profiles : Object.values( result.profiles || {} ); preset = result.preset; siteId = result.connected_site_id || ''; postType.replaceChildren(); ( result.post_types || [ { value: 'page', label: 'Page' }, { value: 'post', label: 'Post' } ] ).forEach( function ( type ) { var option = el( 'option', '', type.label ); option.value = type.value; postType.appendChild( option ); } ); if ( siteId ) { var preference = await request( config, '/binding' ); binding = normalizedBinding( preference.binding ); } useProfile( profiles[0] || preset ); note( profiles.length ? 'Choose a saved profile or start from the Blog preset.' : 'Start with the Blog preset. Save it locally when your rules are ready.' ); }
            catch ( error ) { note( error.message, true ); }
            finally { setBusy( false ); }
        } )();
        return { ready: ready, getProfile: function () { return copy( profile ); }, isDirty: function () { return dirty; } };
    }
    var api = { kinds: kinds, sampleHtml: sampleHtml, structuredSample: structuredSample, newProfile: newProfile, parseInput: parseInput, mergeSavedProfile: mergeSavedProfile, countBlocks: countBlocks, operationIdentity: operationIdentity, rememberedOperation: rememberedOperation, anotherOperation: anotherOperation, safeHref: safeHref, normalizedBinding: normalizedBinding, draftResultLinks: draftResultLinks, mount: mount };
    if ( typeof module !== 'undefined' && module.exports ) { module.exports = api; }
    root.NovaContentRulesEditor = api;
    if ( root.document ) { var start = function () { var host = root.document.getElementById( 'nova-content-rules-app' ); if ( host && root.NovaContentRules ) { mount( host, root.NovaContentRules ); } }; if ( root.document.readyState === 'loading' ) { root.document.addEventListener( 'DOMContentLoaded', start ); } else { start(); } }
} )( typeof window !== 'undefined' ? window : globalThis );
