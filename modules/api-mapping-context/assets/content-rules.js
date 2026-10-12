( function ( root ) {
    'use strict';
    var kinds = [
        { key: 'heading', label: 'Article headings', help: 'H1–H6 headings keep their level and their place in the article.', example: 'Two headings → two Heading widgets.', elements: [ [ 'heading', 'Elementor Heading widget' ], [ 'text-editor', 'Elementor Text Editor widget' ] ] },
        { key: 'paragraph', label: 'Paragraphs', help: 'Each paragraph gets its own editable Elementor Text Editor widget.', example: 'Three paragraphs → three Text Editor widgets.', elements: [ [ 'text-editor', 'Elementor Text Editor widget' ] ] },
        { key: 'rich_text', label: 'Other formatted content', help: 'Tables, quotes and complex markup stay together as rich text. Check the preview and any preservation warnings before creating a draft.', example: 'One table → one Text Editor widget.', elements: [ [ 'text-editor', 'Elementor Text Editor widget' ] ] },
        { key: 'list', label: 'Lists', help: 'An ordered or unordered list stays together in one editable widget.', example: 'One bullet list → one Text Editor widget.', elements: [ [ 'text-editor', 'Elementor Text Editor widget' ] ] },
        { key: 'image', label: 'Images', help: 'Standalone images become Elementor Image widgets. Linked images and captions stay together as rich text.', example: 'One standalone image → one Image widget.', elements: [ [ 'image', 'Elementor Image widget' ] ] },
        { key: 'faq', label: 'Structured FAQs', help: 'Use explicit question/answer data. Ordinary paragraphs are not automatically turned into FAQs.', example: 'Choose an accordion or a visible question-and-answer layout.', elements: [ [ 'accordion', 'Elementor Accordion widget' ], [ 'container', 'Elementor question-and-answer containers' ] ] },
        { key: 'group', label: 'Sections and repeated groups', help: 'Apply these same rules inside every supplied group. The number of sections follows the content.', example: 'A section with a heading and text → a Container with child widgets.', elements: [ [ 'container', 'Elementor Container' ] ] }
    ];
    var sampleHtml = '<h1>A clearer way to build pages</h1>\n<p>Turn your content into a page that follows your own layout rules.</p>\n<h2>How it works</h2>\n<p>Every heading and paragraph becomes its own Elementor element.</p>\n<ul><li>Keep the original order</li><li>Reuse a local profile</li><li>Review the resulting draft</li></ul>';
    var structuredSample = { blocks: [ { type: 'heading', level: 1, html: 'A flexible service page' }, { type: 'group', children: [ { type: 'heading', level: 2, html: 'Our process' }, { type: 'paragraph', html: 'A group can contain any supported child blocks.' }, { type: 'group', children: [ { type: 'heading', level: 3, html: 'Your next step' }, { type: 'paragraph', html: 'Nested groups use the same content rules.' } ] } ] }, { type: 'faq', items: [ { question: 'Can the page grow?', answer: '<p>Yes. Every supplied item gets its own element or group.</p>' }, { question: 'Do I need an existing page?', answer: '<p>No. New element identities are created for this draft.</p>' } ] } ] };
    function ruleExample( kind, rule ) { return kind.key === 'heading' ? 'Two headings → two ' + ( rule.element === 'text-editor' ? 'Text Editor' : 'Heading' ) + ' widgets.' : kind.example; }
    function copy( value ) { return JSON.parse( JSON.stringify( value ) ); }
    function uuid() { if ( root.crypto && root.crypto.randomUUID ) { return root.crypto.randomUUID(); } throw new Error( 'A secure browser context is required to create a profile.' ); }
    function newProfile( preset ) { var value = copy( preset ); value.id = uuid(); value.revision = 0; value.label = 'My article layout'; return value; }
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
    function elementSummary( value ) {
        if ( ! value || ! [ value.widget_count, value.containers, value.total_elements ].every( function ( count ) { return Number.isSafeInteger( count ) && count >= 0; } ) || ! Array.isArray( value.widgets ) ) { return null; }
        if ( value.widgets.some( function ( item ) { return ! item || typeof item.type !== 'string' || typeof item.label !== 'string' || ! Number.isSafeInteger( item.count ) || item.count < 0; } ) ) { return null; }
        return value;
    }
    async function request( config, path, payload ) {
        var response = await root.fetch( config.url + path, { method: payload === undefined ? 'GET' : 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': config.nonce, 'Content-Type': 'application/json' }, body: payload === undefined ? undefined : JSON.stringify( payload ) } );
        var result = await response.json();
        if ( ! response.ok ) { var error = new Error( result.message || 'The request could not be completed.' ); error.status = response.status; throw error; }
        return result;
    }
    function mount( host, config ) {
        var doc = host.ownerDocument, profiles = [], preset, profile, dirty = false, baseline = '', mode = 'html', sample = sampleHtml, busy = false, previewSequence = 0, operation = null, verifiedDraft = false, siteId = '', binding = null;
        var guided = config.initialView !== 'workspace', step = 1, previewReady = false, lastPreviewSummary = null, lastPreviewBlocks = [], createdDraft = null, lastDraftOutcome = 'none', sampleIsExample = true, sampleEditorGuided = guided;
        function el( tag, className, text ) { var node = doc.createElement( tag ); if ( className ) { node.className = className; } if ( text !== undefined ) { node.textContent = text; } return node; }
        function button( label, action, primary ) { var node = el( 'button', 'button' + ( primary ? ' button-primary' : '' ), label ); node.type = 'button'; node.addEventListener( 'click', action ); return node; }
        function select( options, value ) { var node = el( 'select' ); options.forEach( function ( item ) { var option = el( 'option', '', item[1] ); option.value = item[0]; node.appendChild( option ); } ); node.value = value; return node; }
        function field( parent, label, node, hint ) { var wrap = el( 'label', 'ncr-control' ); wrap.append( el( 'span', 'ncr-label', label ), node ); if ( hint ) { wrap.appendChild( el( 'span', 'ncr-hint', hint ) ); } parent.appendChild( wrap ); return node; }
        function number( value, min, max ) { var node = el( 'input' ); node.type = 'number'; node.min = min; node.max = max; node.value = value === undefined ? '' : value; return node; }
        function input( value ) { var node = el( 'input' ); node.type = 'text'; node.value = value || ''; return node; }
        function link( label, value, className ) { var node = el( 'a', className || '', label ); var href = safeHref( value ); if ( href ) { node.href = href; } else { node.hidden = true; } return node; }
        host.replaceChildren();
        var header = el( 'header', 'ncr-header' );
        header.append( el( 'p', 'ncr-eyebrow', 'NEW ELEMENTOR CONTENT' ), el( 'h2', '', 'Build new Elementor content' ), el( 'p', '', 'Turn an article into editable Elementor widgets. This is an independent approach: you do not need to map an existing page first.' ) );
        if ( config.layoutsUrl ) { header.appendChild( link( 'Choose another layout approach', config.layoutsUrl, 'ncr-back-link' ) ); }
        host.appendChild( header );
        var notice = el( 'div', 'ncr-notice' ); notice.setAttribute( 'role', 'status' ); notice.setAttribute( 'aria-live', 'polite' ); host.appendChild( notice );
        var toolbar = el( 'div', 'ncr-toolbar' ), profileSelect = select( [], '' ), newButton = button( 'New layout', createNew ), reconcileButton = button( 'Load latest saved revision', reconcile );
        var guidedButton = button( 'Guided setup', function () { guided = true; step = 1; updateView(); } ), workspaceButton = button( 'Open workspace', function () { finishSetup( false ); } );
        reconcileButton.hidden = true; field( toolbar, 'Saved layout rules', profileSelect ); toolbar.append( newButton, guidedButton, workspaceButton, reconcileButton ); host.appendChild( toolbar );
        var workspaceBanner = el( 'div', 'ncr-workspace-banner', 'Your workspace: adjust rules, try content or create a draft in any order. Guided setup is always available.' ); host.appendChild( workspaceBanner );
        var stepper = el( 'nav', 'ncr-stepper' ); stepper.setAttribute( 'aria-label', 'Elementor layout setup steps' ); host.appendChild( stepper );
        var stepLabels = [ 'Rules', 'Try content', 'Review and save', 'Create a draft' ], stepButtons = [];
        stepLabels.forEach( function ( label, index ) { var node = button( ( index + 1 ) + '. ' + label, function () { goToStep( index + 1 ); } ); node.dataset.step = String( index + 1 ); stepButtons.push( node ); stepper.appendChild( node ); } );
        var flow = el( 'div', 'ncr-flow' ), panels = [], nextButtons = [];
        host.appendChild( flow );
        function panel( index, title, caption ) { var node = el( 'section', 'ncr-step ncr-stage' ); node.dataset.step = String( index ); node.append( el( 'p', 'ncr-step-caption', 'STEP ' + index + ' OF 4' ), el( 'h3', 'ncr-step-title', title ), el( 'p', 'ncr-hint', caption ) ); panels.push( node ); flow.appendChild( node ); return node; }
        var rulesStep = panel( 1, 'Choose how content becomes Elementor widgets', 'NOVA supplies the article. These rules arrange supplied content on this WordPress site; they do not write or rewrite the article.' );
        var ruleColumns = el( 'div', 'ncr-rule-columns' ); ruleColumns.append( el( 'strong', '', 'Content type' ), el( 'strong', '', 'Elementor widget' ) ); rulesStep.appendChild( ruleColumns );
        var rules = el( 'div', 'ncr-rules' ); rulesStep.appendChild( rules );
        rulesStep.appendChild( el( 'p', 'ncr-hint', 'Heading style options apply to all article headings. Original H1–H6 levels are retained unless you deliberately override them. Individual heading-level styling can be adjusted in Elementor after creation.' ) );
        var layoutDetails = el( 'details', 'ncr-settings' ); layoutDetails.appendChild( el( 'summary', '', 'Spacing and empty content' ) );
        var layoutFields = el( 'div', 'ncr-settings-grid' ), width = field( layoutFields, 'Content width (px)', number( 1140, 320, 2400 ) ), gap = field( layoutFields, 'Space between elements (px)', number( 24, 0, 120 ) );
        layoutDetails.appendChild( layoutFields ); var hideEmpty = el( 'input' ); hideEmpty.type = 'checkbox'; field( layoutDetails, 'Skip empty content', hideEmpty ); rulesStep.appendChild( layoutDetails );

        var sampleStep = panel( 2, 'Try a real content example', 'Choose an example and preview what your rules will create. No code is needed. The preview shows content order and grouping; exact styling is reviewed in the Elementor draft.' );
        var sampleActions = el( 'div', 'ncr-sample-actions' ), articleButton = button( 'Article example', function () { useExample( 'html' ); } ), faqButton = button( 'FAQs and groups example', function () { useExample( 'structured' ); } );
        sampleActions.append( articleButton, faqButton ); sampleStep.appendChild( sampleActions );
        var sampleGrid = el( 'div', 'ncr-sample-grid' ), sampleEditor = el( 'div', 'ncr-sample-editor' ), previewPanel = el( 'div', 'ncr-preview' );
        sampleGrid.append( sampleEditor, previewPanel ); sampleStep.appendChild( sampleGrid );
        var sampleSummary = el( 'p', 'ncr-sample-summary' ); sampleEditor.appendChild( sampleSummary );
        var sampleInput = el( 'textarea', 'ncr-sample' ); sampleInput.rows = 12; sampleInput.value = sample; sampleInput.setAttribute( 'aria-label', 'Article HTML' );
        var htmlDetails = el( 'details', 'ncr-custom-input' ); htmlDetails.appendChild( el( 'summary', '', 'Use my own article / edit sample HTML' ) ); htmlDetails.open = ! guided;
        var htmlInputBox = el( 'div', 'ncr-html-input' ); field( htmlInputBox, 'Article HTML', sampleInput, 'Paste article HTML here only if you want to try your own content. Headings, paragraphs and lists become separate widgets. Complex HTML stays together.' ); htmlDetails.appendChild( htmlInputBox ); sampleEditor.appendChild( htmlDetails );
        var structuredInfo = el( 'div', 'ncr-structured-info', 'This example supplies questions, answers and nested groups explicitly. Ordinary article paragraphs are not guessed into FAQ items.' ); structuredInfo.hidden = true; sampleEditor.appendChild( structuredInfo );
        var advancedInput = el( 'details', 'ncr-advanced-input' ); advancedInput.appendChild( el( 'summary', '', 'Advanced: structured content input' ) );
        var modeSelect = field( advancedInput, 'Advanced input format', select( [ [ 'html', 'Article HTML' ], [ 'structured', 'Explicit blocks, groups and FAQs (JSON)' ] ], mode ) ), structuredInputBox = el( 'div', 'ncr-structured-input' );
        advancedInput.appendChild( structuredInputBox ); sampleEditor.appendChild( advancedInput );
        var previewButton = button( 'Preview Elementor structure', preview, true ); sampleEditor.appendChild( previewButton );
        previewPanel.append( el( 'h4', '', 'Structure preview' ), el( 'p', 'ncr-hint', 'Widget structure is planned here. Font, color and theme styling are checked in the actual draft.' ) );
        var previewState = el( 'p', 'ncr-preview-state', 'Preview the selected example to see what will be created.' ), iframe = el( 'iframe', 'ncr-frame' );
        iframe.title = 'Content structure preview'; iframe.setAttribute( 'sandbox', '' ); iframe.setAttribute( 'referrerpolicy', 'no-referrer' ); previewPanel.append( previewState, iframe );
        var widgetSummary = el( 'div', 'ncr-widget-summary' ), warnings = el( 'ul', 'ncr-warnings' ); previewPanel.append( widgetSummary, warnings );

        var saveStep = panel( 3, 'Review and save your layout rules', 'Saving keeps these rules on this WordPress site. It does not create a page, publish content, upload rules or enable automatic deliveries.' );
        var reviewSummary = el( 'div', 'ncr-review-summary' ); saveStep.appendChild( reviewSummary );
        var basic = el( 'div', 'ncr-basics' ), labelInput = field( basic, 'Layout name', input( '' ) ), postType = field( basic, 'Create as', select( [], '' ), 'Page is a website page; Post is a blog article. Other content types can create local drafts when Elementor supports them.' );
        saveStep.appendChild( basic );
        var saveRow = el( 'div', 'ncr-actions' ), saveButton = button( 'Save layout rules', save, true ), savedState = el( 'span', 'ncr-hint' ); saveRow.append( saveButton, savedState ); saveStep.appendChild( saveRow );

        var createStep = panel( 4, 'Create a real Elementor test draft', 'This action creates a WordPress draft using the saved rules and the content you just tried. Open it in Elementor to review the native widgets and exact styling.' );
        var draftPlan = el( 'div', 'ncr-widget-summary ncr-draft-plan' ); createStep.appendChild( draftPlan );
        var titleInput = field( createStep, 'Draft title', input( 'Content layout draft' ), 'This is the WordPress title, separate from headings inside the article.' );
        var createButton = button( 'Create Elementor draft', createDraft, true ), anotherButton = button( 'Create another draft', createAnother ), draftLinks = el( 'div', 'ncr-draft-links' ), outcome = el( 'div', 'ncr-outcome' );
        var finishButton = button( 'Finish setup without a draft', function () { finishSetup( true ); } );
        anotherButton.hidden = true; createStep.append( createButton, outcome, draftLinks, anotherButton, finishButton );

        panels.forEach( function ( node, index ) {
            var navigation = el( 'div', 'ncr-step-navigation ncr-step-actions' );
            if ( index > 0 ) { navigation.appendChild( button( 'Back', function () { goToStep( index ); } ) ); }
            if ( index < 3 ) { var next = button( index === 0 ? 'Next: try content' : index === 1 ? 'Next: review rules' : 'Next: create a draft', function () { goToStep( index + 2 ); }, true ); nextButtons[index] = next; navigation.appendChild( next ); }
            node.appendChild( navigation );
        } );
        var deliveryDetails = el( 'details', 'ncr-delivery' );
        deliveryDetails.append( el( 'summary', '', 'Optional: use these rules for future NOVA deliveries' ), el( 'p', '', 'This separate preference uses main article HTML only for new, unmapped deliveries on the connected site. First deliveries create drafts; later versions preserve publication status. Existing template mappings continue through their own route.' ), el( 'p', 'ncr-hint', 'Supports pages and simple post-name URLs in the matching site language. Structured FAQ/group input can be tried locally; this preference does not add structured FAQ data to delivered article HTML.' ) );
        if ( config.connectionUrl ) { deliveryDetails.appendChild( link( 'Open NOVA connection settings', config.connectionUrl, 'ncr-connection-link' ) ); }
        var deliveryCheck = el( 'input' ); deliveryCheck.type = 'checkbox'; field( deliveryDetails, 'Use these saved rules for new, unmapped deliveries', deliveryCheck );
        var siteText = el( 'p', 'ncr-hint' ), bindButton = button( 'Save delivery preference', saveBinding ); deliveryDetails.append( siteText, bindButton ); host.appendChild( deliveryDetails );

        function note( text, error ) { notice.textContent = text; notice.className = 'ncr-notice' + ( error ? ' ncr-error' : '' ); }
        function canGuideStep( target ) { return !! profile && ! busy && ( target <= 2 || ( previewReady && ( target === 3 || ( target === 4 && ! dirty && !! profile.revision ) ) ) ); }
        function goToStep( target ) { if ( ! canGuideStep( target ) ) { return; } step = target; updateView(); }
        function updateView() {
            host.className = 'ncr ' + ( guided ? 'is-guided' : 'is-workspace' );
            workspaceBanner.hidden = guided; stepper.hidden = ! guided; guidedButton.hidden = guided; workspaceButton.hidden = ! guided;
            panels.forEach( function ( node, index ) { node.hidden = guided && index + 1 !== step; var navigation = node.children[ node.children.length - 1 ]; navigation.hidden = ! guided; } );
            stepButtons.forEach( function ( node, index ) { node.className = 'button' + ( index + 1 === step ? ' is-current' : '' ) + ( index + 1 < step ? ' is-complete' : '' ); if ( index + 1 === step ) { node.setAttribute( 'aria-current', 'step' ); } else if ( node.removeAttribute ) { node.removeAttribute( 'aria-current' ); } } );
            if ( sampleEditorGuided !== guided ) { htmlDetails.open = ! guided; sampleEditorGuided = guided; }
            deliveryDetails.hidden = guided; state();
        }
        function rememberWorkspaceView() {
            if ( ! profile || ! profile.revision || ! root.history || ! root.history.replaceState || ! root.location ) { return; }
            try { var url = new URL( root.location.href ); url.searchParams.set( 'view', 'workspace' ); url.searchParams.set( 'profile_id', profile.id ); url.searchParams.delete( 'new' ); root.history.replaceState( null, '', url.href ); } catch ( error ) { /* A restrictive host can keep the current address without affecting saved rules. */ }
        }
        function rememberProfileChoice() {
            if ( ! profile || ! root.history || ! root.history.replaceState || ! root.location ) { return; }
            try { var url = new URL( root.location.href ); url.searchParams.set( 'view', guided ? 'setup' : 'workspace' ); if ( profile.revision ) { url.searchParams.set( 'profile_id', profile.id ); url.searchParams.delete( 'new' ); } else { url.searchParams.delete( 'profile_id' ); url.searchParams.set( 'new', '1' ); } root.history.replaceState( null, '', url.href ); } catch ( error ) { /* An explicit choice remains usable even if the host cannot update its address. */ }
        }
        function finishSetup( requireReviewedPreview ) { if ( busy || ! profile || dirty || ! profile.revision || ( requireReviewedPreview && guided && ! previewReady ) ) { return; } guided = false; updateView(); rememberWorkspaceView(); note( lastDraftOutcome === 'uncertain' ? 'Rules are saved. The draft attempt is unverified: keep the original content and retry the same operation to reconcile it.' : lastDraftOutcome === 'verified' ? 'Rules are saved. Your earlier draft remains in WordPress; finishing setup creates no additional draft.' : 'Rules are saved on this website. No draft was created by this setup. You can try content or create a draft later.' ); }
        function state() {
            var inputs = flow.querySelectorAll ? Array.from( flow.querySelectorAll( 'input,select,textarea' ) ) : flow.all().filter( function ( node ) { return [ 'input', 'select', 'textarea' ].indexOf( node.tag ) >= 0; } ); inputs.forEach( function ( node ) { node.disabled = busy || ! profile; } );
            saveButton.disabled = busy || ! profile || ( ! dirty && !! profile.revision ); newButton.disabled = busy || ! preset; reconcileButton.disabled = busy;
            previewButton.disabled = busy || ! profile; createButton.disabled = busy || ! profile || dirty || ! profile.revision || ( guided && ! previewReady );
            anotherButton.disabled = busy || ! verifiedDraft || dirty; anotherButton.hidden = ! verifiedDraft; guidedButton.disabled = busy; workspaceButton.disabled = busy || ! profile || dirty || ! profile.revision;
            finishButton.disabled = busy || ! profile || dirty || ! profile.revision || ( guided && ! previewReady ); finishButton.hidden = ! guided;
            bindButton.disabled = busy || ! siteId || ! profile || ( deliveryCheck.checked && ( dirty || ! profile.revision || [ 'page', 'post' ].indexOf( profile.post_type ) < 0 ) );
            profileSelect.disabled = busy; deliveryCheck.disabled = busy || ! siteId || ! profile; articleButton.disabled = busy || ! profile; faqButton.disabled = busy || ! profile;
            stepButtons.forEach( function ( node, index ) { node.disabled = ! canGuideStep( index + 1 ); } );
            nextButtons.forEach( function ( node, index ) { node.disabled = ! canGuideStep( index + 2 ); } );
            previewButton.className = 'button' + ( ! guided || ! previewReady ? ' button-primary' : '' );
            saveButton.className = 'button' + ( dirty || ! profile || ! profile.revision ? ' button-primary' : '' );
            if ( nextButtons[1] ) { nextButtons[1].className = 'button' + ( previewReady ? ' button-primary' : '' ); }
            if ( nextButtons[2] ) { nextButtons[2].className = 'button' + ( profile && ! dirty && profile.revision ? ' button-primary' : '' ); }
            savedState.textContent = ! profile ? '' : dirty || ! profile.revision ? 'Not saved yet' : 'Saved on this website · revision ' + profile.revision;
        }
        function clearDraftIdentity() { draftLinks.replaceChildren(); outcome.replaceChildren(); operation = null; verifiedDraft = false; createdDraft = null; anotherButton.hidden = true; state(); }
        function invalidate() { previewSequence++; previewReady = false; lastPreviewSummary = null; lastPreviewBlocks = []; previewState.textContent = 'Update the preview to reflect your latest content and rules.'; widgetSummary.replaceChildren(); clearDraftIdentity(); renderReview(); renderDraftPlan(); }
        function metadataChanged() { dirty = true; clearDraftIdentity(); renderReview(); renderDraftPlan(); state(); }
        function changed() { dirty = true; invalidate(); renderReview(); state(); }
        function setBusy( value ) { busy = value; var controls = host.querySelectorAll ? Array.from( host.querySelectorAll( 'input,select,textarea' ) ) : host.all().filter( function ( node ) { return [ 'input', 'select', 'textarea' ].indexOf( node.tag ) >= 0; } ); controls.forEach( function ( node ) { node.disabled = busy; } ); state(); }
        function refreshDeliveryPreference() {
            deliveryCheck.checked = !! ( binding && profile && binding.profile_id === profile.id && binding.profile_revision === profile.revision );
            var selected = binding && profiles.find( function ( item ) { return item.id === binding.profile_id; } );
            siteText.textContent = ! siteId ? 'A NOVA connection is needed only for automatic deliveries. Saving rules, trying content and creating local drafts work independently.' : 'Connected site: ' + siteId + '. ' + ( binding ? 'Currently uses ' + ( selected && selected.label || 'saved layout rules' ) + ' revision ' + binding.profile_revision + '. Saving edits does not replace this selected revision.' : 'Automatic use of local rules is off.' ) + ( profile && [ 'page', 'post' ].indexOf( profile.post_type ) < 0 ? ' This custom post type can create local drafts; it cannot be selected for fetched deliveries.' : '' );
        }
        function refreshProfiles() { profileSelect.replaceChildren(); var current = el( 'option', '', profile && ! profile.revision ? 'New, unsaved layout' : 'Choose saved rules' ); current.value = ''; profileSelect.appendChild( current ); profiles.forEach( function ( item ) { var option = el( 'option', '', item.label ); option.value = item.id; profileSelect.appendChild( option ); } ); profileSelect.value = profile && profile.revision ? profile.id : ''; }
        function renderReview() {
            reviewSummary.replaceChildren();
            if ( ! profile ) { return; }
            var list = el( 'ul', 'ncr-review-list' );
            kinds.forEach( function ( kind ) { var rule = profile.rules[kind.key], choice = kind.elements.find( function ( item ) { return item[0] === rule.element; } ); list.appendChild( el( 'li', '', kind.label + ' → ' + ( choice ? choice[1] : rule.element ) ) ); } );
            reviewSummary.append( el( 'strong', '', 'Your layout rules' ), list, el( 'p', 'ncr-hint', 'Source order is preserved. Rules are applied to every matching block, so the number of widgets follows the article.' ) );
            var reviewedPlan = el( 'div', 'ncr-widget-summary' ); renderPlan( reviewedPlan, 'Widget plan checked in preview' ); reviewSummary.appendChild( reviewedPlan );
        }
        function renderPlan( parent, title ) {
            parent.replaceChildren();
            if ( ! previewReady ) { parent.appendChild( el( 'p', 'ncr-hint', 'Try the current content in step 2 to check its widget plan.' ) ); return; }
            parent.appendChild( el( 'h4', '', title ) );
            if ( lastPreviewSummary ) {
                parent.appendChild( el( 'p', '', lastPreviewSummary.widget_count + ' planned widgets · ' + lastPreviewSummary.containers + ' containers' ) );
                var list = el( 'ul' ); lastPreviewSummary.widgets.forEach( function ( item ) { list.appendChild( el( 'li', '', item.count + ' × ' + item.label ) ); } ); parent.appendChild( list );
            } else { parent.appendChild( el( 'p', 'ncr-hint', countBlocks( lastPreviewBlocks ) + ' recognized content blocks and group items. Native counts will be available in the draft.' ) ); }
        }
        function renderDraftPlan() { renderPlan( draftPlan, 'Planned structure for this draft' ); }
        function renderWidgetSummary( value, blocks ) {
            widgetSummary.replaceChildren();
            if ( value ) {
                widgetSummary.appendChild( el( 'h4', '', 'Planned Elementor structure' ) );
                var stats = el( 'div', 'ncr-widget-stats' ); stats.append( el( 'span', 'ncr-widget-stat', value.widget_count + ' widgets' ), el( 'span', 'ncr-widget-stat', value.containers + ' containers' ) ); widgetSummary.appendChild( stats );
                var list = el( 'ul' ); value.widgets.forEach( function ( item ) { list.appendChild( el( 'li', '', item.count + ' × ' + item.label ) ); } ); widgetSummary.appendChild( list );
            } else {
                widgetSummary.append( el( 'h4', '', 'Recognized content' ), el( 'p', '', countBlocks( blocks ) + ' article blocks and group items. Native element counts are available in the created draft.' ) );
            }
        }
        function renderRules() {
            rules.replaceChildren();
            kinds.forEach( function ( kind ) {
                var rule = profile.rules[kind.key], row = el( 'article', 'ncr-rule' ), head = el( 'div', 'ncr-rule-head' ); head.append( el( 'strong', '', kind.label ), el( 'span', 'ncr-arrow', '→' ) );
                var example = el( 'p', 'ncr-rule-example', ruleExample( kind, rule ) );
                if ( kind.elements.length > 1 ) { var elementSelect = select( kind.elements, rule.element ); elementSelect.setAttribute( 'aria-label', kind.label + ' Elementor widget' ); head.appendChild( elementSelect ); elementSelect.addEventListener( 'change', function () { rule.element = elementSelect.value; example.textContent = ruleExample( kind, rule ); changed(); } ); }
                else { head.appendChild( el( 'span', 'ncr-widget-chip', kind.elements[0][1] ) ); }
                row.append( head, el( 'p', 'ncr-hint', kind.help ), example );
                if ( kind.key === 'group' || kind.key === 'faq' ) { rules.appendChild( row ); return; }
                var style = el( 'details', 'ncr-rule-style' ); style.appendChild( el( 'summary', '', kind.key === 'heading' ? 'Style all headings' : 'Style options' ) ); var grid = el( 'div', 'ncr-settings-grid' );
                var align = field( grid, 'Alignment', select( [ [ '', 'Theme default' ], [ 'left', 'Left' ], [ 'center', 'Center' ], [ 'right', 'Right' ] ], rule.settings.align || '' ) );
                function setting( control, key, numeric ) { control.addEventListener( 'input', function () { if ( control.value === '' ) { delete rule.settings[key]; } else { rule.settings[key] = numeric ? Number( control.value ) : control.value; } changed(); } ); }
                setting( align, 'align', false );
                if ( kind.key !== 'image' ) { var font = field( grid, 'Font size (px)', number( rule.settings.typography_font_size, 8, 96 ) ), color = field( grid, 'Text color', input( rule.settings.color || '' ) ); color.placeholder = '#0B0B0C'; setting( font, 'typography_font_size', true ); setting( color, 'color', false ); }
                if ( kind.key === 'heading' ) { var tag = field( grid, 'Heading level', select( [ [ '', 'Keep each article heading level' ], [ 'h1', 'Make every heading H1' ], [ 'h2', 'Make every heading H2' ], [ 'h3', 'Make every heading H3' ], [ 'h4', 'Make every heading H4' ], [ 'h5', 'Make every heading H5' ], [ 'h6', 'Make every heading H6' ] ], rule.settings.html_tag || '' ) ); setting( tag, 'html_tag', false ); }
                if ( [ 'paragraph', 'rich_text', 'list' ].indexOf( kind.key ) >= 0 ) { var lineHeight = field( grid, 'Line height', number( rule.settings.line_height, 0.5, 4 ) ); lineHeight.step = '0.1'; setting( lineHeight, 'line_height', true ); }
                style.appendChild( grid ); row.appendChild( style ); rules.appendChild( row );
            } );
        }
        function updateSampleFormat() {
            modeSelect.value = mode; htmlDetails.hidden = mode !== 'html'; structuredInfo.hidden = mode !== 'structured'; structuredInputBox.hidden = mode !== 'structured';
            if ( mode === 'structured' ) { structuredInputBox.appendChild( sampleInput ); sampleInput.setAttribute( 'aria-label', 'Structured content JSON' ); }
            else { var label = htmlInputBox.children[0]; if ( sampleInput.parentNode !== label ) { if ( label.insertBefore ) { label.insertBefore( sampleInput, label.children[1] ); } else { label.appendChild( sampleInput ); } } sampleInput.setAttribute( 'aria-label', 'Article HTML' ); }
            sampleInput.value = sample;
            updateSampleSummary();
        }
        function updateSampleSummary() {
            sampleSummary.textContent = sampleIsExample ? ( mode === 'structured' ? 'FAQs and groups example: three headings, two paragraphs, two nested sections and two explicit question-and-answer items.' : 'Article example: two headings, two paragraphs and one bullet list, kept in their original order.' ) : ( mode === 'structured' ? 'Your structured content is selected. Preview it to check supplied blocks, sections and questions.' : 'Your article content is selected. Preview it to check the content structure before creating a draft.' );
            articleButton.setAttribute( 'aria-pressed', String( sampleIsExample && mode === 'html' ) ); faqButton.setAttribute( 'aria-pressed', String( sampleIsExample && mode === 'structured' ) );
        }
        function useExample( nextMode ) { mode = nextMode; sampleIsExample = true; sample = mode === 'structured' ? JSON.stringify( structuredSample, null, 2 ) : sampleHtml; if ( guided ) { htmlDetails.open = false; } updateSampleFormat(); invalidate(); note( mode === 'structured' ? 'FAQ and group example selected. Preview Elementor structure to see the planned layout.' : 'Article example selected. Preview Elementor structure to see one widget per article block.' ); }
        function useProfile( value ) { profile = copy( value ); baseline = profileContent( profile ); dirty = ! profile.revision; labelInput.value = profile.label; postType.value = profile.post_type; width.value = profile.layout.max_width; gap.value = profile.layout.gap; hideEmpty.checked = profile.hide_empty; reconcileButton.hidden = true; renderRules(); refreshProfiles(); refreshDeliveryPreference(); invalidate(); renderReview(); updateView(); }
        function canSwitch() { return ! profile || profileContent( profile ) === baseline || ! root.confirm || root.confirm( 'Replace your unsaved layout rule edits?' ); }
        function createNew() { if ( ! canSwitch() ) { return; } try { guided = true; step = 1; useProfile( newProfile( preset ) ); rememberProfileChoice(); note( 'New layout started from the article preset. Choose your widgets, then try content.' ); } catch ( error ) { note( error.message, true ); } }
        function reconcile() { var latest = profiles.find( function ( item ) { return item.id === profile.id; } ); if ( latest && canSwitch() ) { useProfile( latest ); note( 'Latest saved rules loaded. Review them before making changes.' ); } }
        labelInput.addEventListener( 'input', function () { profile.label = labelInput.value; metadataChanged(); } ); postType.addEventListener( 'change', function () { profile.post_type = postType.value; metadataChanged(); refreshDeliveryPreference(); } );
        width.addEventListener( 'input', function () { profile.layout.max_width = Number( width.value ); changed(); } ); gap.addEventListener( 'input', function () { profile.layout.gap = Number( gap.value ); changed(); } ); hideEmpty.addEventListener( 'change', function () { profile.hide_empty = hideEmpty.checked; changed(); } );
        titleInput.addEventListener( 'input', clearDraftIdentity ); deliveryCheck.addEventListener( 'change', state );
        sampleInput.addEventListener( 'input', function () { sample = sampleInput.value; sampleIsExample = false; updateSampleSummary(); invalidate(); } ); modeSelect.addEventListener( 'change', function () { useExample( modeSelect.value ); } );
        profileSelect.addEventListener( 'change', function () { var selected = profiles.find( function ( item ) { return item.id === profileSelect.value; } ); if ( selected && canSwitch() ) { useProfile( selected ); rememberProfileChoice(); note( 'Saved layout rules loaded. Use the workspace or return to guided setup.' ); } else { refreshProfiles(); } } );
        async function save() {
            if ( busy || ! profile ) { note( 'Choose saved layout rules or start a new layout before saving.', true ); return; }
            var submitted = copy( profile ); setBusy( true ); note( 'Saving layout rules on this website…' );
            try { var saved = await request( config, '/profiles', { profile: submitted, expected_revision: submitted.revision } ), merged = mergeSavedProfile( profile, submitted, saved ); profiles = profiles.filter( function ( item ) { return item.id !== saved.id; } ); profiles.push( saved ); profile = merged.profile; baseline = profileContent( saved ); dirty = merged.dirty; reconcileButton.hidden = true; if ( merged.dirty ) { invalidate(); } else { clearDraftIdentity(); } refreshProfiles(); refreshDeliveryPreference(); renderReview(); renderDraftPlan(); if ( guided && step === 3 && ! dirty ) { step = 4; updateView(); } note( merged.dirty ? 'Rules saved. Your newer edits are still unsaved.' : 'Layout rules saved on this website. No page was created and automatic deliveries remain a separate choice.' ); }
            catch ( error ) {
                if ( error.status === 409 ) {
                    try { var latest = await request( config, '/profiles' ); profiles = Array.isArray( latest.profiles ) ? latest.profiles : Object.values( latest.profiles || {} ); refreshProfiles(); reconcileButton.hidden = ! profiles.some( function ( item ) { return item.id === profile.id; } ); }
                    catch ( refreshError ) { note( error.message + ' Your edits are retained. The latest rules could not be loaded; reload in another tab to reconcile.', true ); return; }
                    note( error.message + ' Your edits are retained. Load the latest saved revision to replace these edits, or keep them for review.', true );
                } else { note( error.message, true ); }
            }
            finally { setBusy( false ); }
        }
        async function preview() {
            if ( busy || ! profile ) { note( 'Choose saved layout rules or start a new layout before previewing.', true ); return; }
            var content; try { content = parseInput( mode, sample ); content.title = titleInput.value; } catch ( error ) { note( error.message, true ); return; }
            var sequence = ++previewSequence; setBusy( true ); note( 'Preparing the structure preview…' );
            try { var result = await request( config, '/preview', { profile: copy( profile ), input: content } ); if ( sequence !== previewSequence ) { return; } if ( ! result || typeof result.preview_document !== 'string' || ! Array.isArray( result.blocks ) || ! Array.isArray( result.warnings ) ) { throw new Error( 'The server did not return a complete content preview.' ); } iframe.srcdoc = result.preview_document; lastPreviewSummary = elementSummary( result.element_summary ); lastPreviewBlocks = result.blocks; previewReady = true; previewState.textContent = countBlocks( result.blocks ) + ' content blocks and group items · original order retained'; renderWidgetSummary( lastPreviewSummary, result.blocks ); renderReview(); renderDraftPlan(); warnings.replaceChildren(); result.warnings.forEach( function ( message ) { warnings.appendChild( el( 'li', '', message ) ); } ); note( guided ? 'Preview ready. Continue to review and save these rules.' : 'Structure preview ready. Exact styling is reviewed in the Elementor draft.' ); }
            catch ( error ) { previewReady = false; note( error.message, true ); }
            finally { setBusy( false ); }
        }
        async function createDraft() {
            if ( busy || ! profile || dirty || ! profile.revision || ( guided && ! previewReady ) ) { note( guided && ! previewReady ? 'Preview the current content and save your reviewed rules before creating a draft in guided setup.' : 'Choose and save an exact layout rule revision before creating a draft.', true ); return; }
            setBusy( true ); verifiedDraft = false; anotherButton.hidden = true;
            var content; try { content = parseInput( mode, sample ); content.title = titleInput.value; operation = await rememberedOperation( operation, profile, content ); } catch ( error ) { note( error.message, true ); setBusy( false ); return; }
            note( 'Creating an actual Elementor draft in WordPress…' );
            try {
                lastDraftOutcome = 'uncertain';
                var result = await request( config, '/create-draft', { profile_id: profile.id, profile_revision: profile.revision, input: content, operation_uuid: operation.id } ), links = draftResultLinks( result, operation.id );
                draftLinks.replaceChildren(); links.forEach( function ( item ) { var node = el( 'a', 'button', item[1] ); node.href = item[0]; node.target = '_blank'; node.rel = 'noopener noreferrer'; draftLinks.appendChild( node ); } );
                createdDraft = result; verifiedDraft = true; lastDraftOutcome = 'verified'; outcome.replaceChildren( el( 'h4', '', 'WordPress draft created' ), el( 'p', '', 'Draft #' + result.post_id + ' is ready to review. Open Elementor to inspect its editable widgets and exact styling.' ) );
                var actualSummary = elementSummary( result.element_summary ); if ( actualSummary ) { outcome.appendChild( el( 'p', 'ncr-hint', actualSummary.widget_count + ' native widgets and ' + actualSummary.containers + ' containers were saved.' ) ); }
                guided = false; step = 4; updateView(); rememberWorkspaceView(); note( 'Elementor draft created. Your workspace is now ready; guided setup remains available.' );
            }
            catch ( error ) { note( error.message + ' Retrying the same content keeps the same draft operation.', true ); }
            finally { setBusy( false ); }
        }
        function createAnother() { if ( busy || ! verifiedDraft || dirty ) { return; } try { operation = anotherOperation( operation ); verifiedDraft = false; draftLinks.replaceChildren(); outcome.replaceChildren(); return createDraft(); } catch ( error ) { note( error.message, true ); } }
        async function saveBinding() {
            if ( busy || ! profile ) { note( 'Choose saved layout rules before changing a delivery preference.', true ); return; }
            setBusy( true ); note( 'Saving the local delivery preference…' );
            try { await request( config, '/binding', { site_id: siteId, enabled: deliveryCheck.checked, profile_id: profile.id, profile_revision: profile.revision } ); binding = deliveryCheck.checked ? { site_id: siteId, profile_id: profile.id, profile_revision: profile.revision } : null; refreshDeliveryPreference(); note( deliveryCheck.checked ? 'Saved rules selected for new, unmapped deliveries from this site. The NOVA connection is not activated by this preference.' : 'Automatic use of these layout rules is off.' ); }
            catch ( error ) { note( error.message, true ); }
            finally { setBusy( false ); }
        }
        updateSampleFormat(); updateView(); setBusy( true );
        var ready = ( async function () {
            try {
                var result = await request( config, '/profiles' ); profiles = Array.isArray( result.profiles ) ? result.profiles : Object.values( result.profiles || {} ); preset = result.preset; siteId = result.connected_site_id || '';
                postType.replaceChildren(); ( result.post_types || [ { value: 'page', label: 'Page' }, { value: 'post', label: 'Post' } ] ).forEach( function ( type ) { var option = el( 'option', '', type.label ); option.value = type.value; postType.appendChild( option ); } );
                if ( siteId ) { var preference = await request( config, '/binding' ); binding = normalizedBinding( preference.binding ); }
                var chosen = config.profileId && profiles.find( function ( item ) { return item.id === config.profileId; } );
                if ( ! config.newSetup && config.profileId && ! chosen ) { refreshProfiles(); refreshDeliveryPreference(); renderReview(); updateView(); note( 'The requested saved layout is not available. Choose another saved layout explicitly or start a new layout. No alternative has been loaded.', true ); return; }
                useProfile( config.newSetup ? newProfile( preset ) : chosen || profiles[0] || preset );
                note( guided ? 'Start with content types and their Elementor widgets. No existing page mapping is needed.' : 'Saved layout workspace ready. Adjust rules, try content or create a draft.' );
            }
            catch ( error ) { note( error.message, true ); }
            finally { setBusy( false ); }
        } )();
        return { ready: ready, getProfile: function () { return profile ? copy( profile ) : null; }, isDirty: function () { return dirty; }, getView: function () { return { guided: guided, step: step, previewReady: previewReady }; } };
    }
    var api = { kinds: kinds, sampleHtml: sampleHtml, structuredSample: structuredSample, newProfile: newProfile, parseInput: parseInput, mergeSavedProfile: mergeSavedProfile, countBlocks: countBlocks, operationIdentity: operationIdentity, rememberedOperation: rememberedOperation, anotherOperation: anotherOperation, safeHref: safeHref, normalizedBinding: normalizedBinding, draftResultLinks: draftResultLinks, elementSummary: elementSummary, mount: mount };
    if ( typeof module !== 'undefined' && module.exports ) { module.exports = api; }
    root.NovaContentRulesEditor = api;
    if ( root.document ) { var start = function () { var host = root.document.getElementById( 'nova-content-rules-app' ); if ( host && root.NovaContentRules ) { mount( host, root.NovaContentRules ); } }; if ( root.document.readyState === 'loading' ) { root.document.addEventListener( 'DOMContentLoaded', start ); } else { start(); } }
} )( typeof window !== 'undefined' ? window : globalThis );
