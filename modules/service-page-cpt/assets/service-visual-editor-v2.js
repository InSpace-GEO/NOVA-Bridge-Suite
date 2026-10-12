(function (host) {
	'use strict';

	function copy(value) { return value === undefined ? undefined : JSON.parse(JSON.stringify(value)); }
	function same(a, b) { return JSON.stringify(a) === JSON.stringify(b); }
	function fieldState(section, path) {
		var key = String(path || '').split('.')[0];
		if (key === '__page_title') { return {inherited: false, overridden: false}; }
		var linked = Boolean(section && section.shared_key);
		var local = Boolean(section && section.data && Object.prototype.hasOwnProperty.call(section.data, key));
		return {inherited: linked && !local, overridden: linked && local};
	}
	// An edit starts from the document field, never from the rendered page HTML.
	// In particular, opening or cancelling an inherited field must not turn it
	// into a local override. A completed edit is one undoable bridge mutation.
	function transaction(core, id, path) {
		var before = copy(core.getField(id, path)), draft = copy(before), closed = false;
		return {
			original: copy(before),
			set: function (value) { if (!closed) { draft = copy(value); } },
			get: function () { return copy(draft); },
			apply: function () {
				if (closed) { return false; }
				if (!same(core.getField(id, path), before)) { throw new Error('This field changed while you were editing. Close the editor and open it again.'); }
				closed = true;
				if (!same(before, draft)) { core.setField(id, path, copy(draft)); return true; }
				return false;
			},
			cancel: function () { closed = true; }
		};
	}
	function mediaImage(attachment) {
		return {id: Number(attachment.id || 0), url: String(attachment.url || ''), alt: String(attachment.alt || ''), caption: ''};
	}
	function sectionName(section, registry, index) {
		if (section.label) { return section.label; }
		if (section.type === 'rich_text') {
			var slot = /rich_text_([123])/.exec(section.content_slot || section.id || '');
			return 'Rich text ' + (slot ? slot[1] : index + 1);
		}
		return (registry[section.type] || {}).label || section.type;
	}
	function isEmpty(section) {
		var data = section.effectiveData || section.data || {};
		if (section.type === 'hero' || section.type === 'related') { return false; }
		return !['title', 'body', 'html', 'shortcode', 'address', 'map_url', 'video_url'].some(function (key) { return Boolean(data[key]); }) &&
			!['images', 'buttons', 'items', 'post_ids'].some(function (key) { return Array.isArray(data[key]) && data[key].length > 0; });
	}

	function mount(core, window, document) {
		if (!core || !core.root || !core.preview || !core.preview.frame || core.root.getAttribute('data-visual-mounted')) { return null; }
		var config = core.config || {};
		if (config.mode && config.mode !== 'page' || !config.isV2) { return null; }
		var root = core.root, preview = core.preview, inspector = core.getInspector(), registry = config.sections || {};
		var selected = '', selectedPath = '', selectedKind = '', activeEdit = null, richCounter = 0, inMetadata = false;
		var frameDocument = null, deviceButtons = {}, lastFocus = null;
		root.setAttribute('data-visual-mounted', '1');
		function node(tag, attributes, text) {
			var result = document.createElement(tag);
			Object.keys(attributes || {}).forEach(function (name) {
				if (name === 'className') { result.className = attributes[name]; }
				else { result.setAttribute(name, attributes[name]); }
			});
			if (text !== undefined) { result.textContent = text; }
			return result;
		}
		function button(text, action, className) {
			var result = node('button', {type: 'button', className: className || 'button'}, text);
			result.addEventListener('click', action); return result;
		}
		function announce(text, error) { status.textContent = text || ''; status.classList.toggle('is-error', Boolean(error)); }
		function run(action) { try { return action(); } catch (error) { announce(error.message || 'The edit could not be applied.', true); return false; } }
		var toolbar = node('div', {className: 'nova-visual-toolbar', role: 'toolbar', 'aria-label': 'Service page editor'});
		var body = node('div', {className: 'nova-visual-body'});
		var status = node('span', {className: 'nova-visual-status', role: 'status', 'aria-live': 'polite'});
		var structure = node('aside', {className: 'nova-visual-structure', 'aria-label': 'Page sections', hidden: ''});
		var inspectorDrawer = node('aside', {className: 'nova-visual-inspector', 'aria-label': 'Selected section settings', hidden: ''});
		var richPanel = node('section', {className: 'nova-visual-rich-panel', role: 'dialog', 'aria-label': 'Edit rich text', hidden: ''});
		var sectionTools = node('div', {className: 'nova-visual-section-tools', role: 'toolbar', 'aria-label': 'Selected section', hidden: ''});
		var sectionLabel = node('strong', {className: 'nova-visual-section-label'});
		var fieldTools = node('span', {className: 'nova-visual-field-tools'});
		var structureList = node('ol', {className: 'nova-visual-structure-list'});
		function panel(target, title, close) {
			var heading = node('div', {className: 'nova-visual-panel-heading'});
			heading.appendChild(node('h3', {}, title)); heading.appendChild(button('Close', close, 'button button-small'));
			target.appendChild(heading); var content = node('div', {className: 'nova-visual-panel-body'}); target.appendChild(content); return content;
		}
		function showStructure(open) {
			structure.hidden = !open; root.classList.toggle('is-structure-open', open); structureToggle.setAttribute('aria-expanded', String(open));
			if (open) { showInspector(false); }
		}
		function showInspector(open) {
			inspectorDrawer.hidden = !open; root.classList.toggle('is-inspector-open', open);
			if (open) { showStructure(false); filterInspector(); }
		}
		var structureToggle = button('Sections', function () { showStructure(structure.hidden); });
		structureToggle.setAttribute('aria-expanded', 'false'); toolbar.appendChild(structureToggle);
		['desktop', 'phone'].forEach(function (device) {
			var control = button(device === 'desktop' ? 'Desktop' : 'Phone', function () {
				core.setDevice(device); root.classList.toggle('is-phone', device === 'phone');
				Object.keys(deviceButtons).forEach(function (name) { deviceButtons[name].setAttribute('aria-pressed', String(name === device)); });
			});
			control.setAttribute('aria-pressed', String(device === preview.device)); deviceButtons[device] = control; toolbar.appendChild(control);
		});
		var undo = button('Undo', function () { if (!activeEdit) { run(function () { core.undo(); }); } }); toolbar.appendChild(undo);
		var redo = button('Redo', function () { if (!activeEdit) { run(function () { core.redo(); }); } }); toolbar.appendChild(redo);
		toolbar.appendChild(status);
		var save = button(config.saveLabel || 'Save page', function () { if (finishEdit(true)) { run(function () { core.save(); }); } }, 'button button-primary nova-visual-save'); toolbar.appendChild(save);
		var metadata = button('WordPress details', function () {
			if (!finishEdit(true)) { return; } inMetadata = !inMetadata;
			root.classList.toggle('nova-service-visual-mode', !inMetadata); document.body.classList.toggle('nova-service-visual-open', !inMetadata);
			body.hidden = inMetadata; metadata.textContent = inMetadata ? 'Back to visual editor' : 'WordPress details';
			if (!inMetadata) { core.setDevice(preview.device || 'desktop'); }
		}); toolbar.appendChild(metadata);
		var structureContent = panel(structure, 'Page sections', function () { showStructure(false); }); structureContent.appendChild(structureList);
		var addLabel = node('label', {className: 'nova-visual-add-label'}); addLabel.appendChild(node('span', {}, 'Add a section'));
		var addType = node('select', {className: 'nova-visual-add-type'});
		Object.keys(registry).forEach(function (type) { addType.appendChild(node('option', {value: type}, registry[type].label || type)); }); addLabel.appendChild(addType); structureContent.appendChild(addLabel);
		structureContent.appendChild(button('Add section', function () {
			if (!finishEdit(true)) { return; }
			var result = run(function () { return core.add(addType.value, selected); });
			if (result && typeof result === 'string') { select(result); }
			else if (result && result.id) { select(result.id); }
		}));
		var inspectorContent = panel(inspectorDrawer, 'Section settings', function () { showInspector(false); });
		if (inspector) { inspectorContent.appendChild(inspector); }
		sectionTools.appendChild(sectionLabel);
		var moveUp = button('Move up', function () { if (finishEdit(true)) { run(function () { core.move(selected, -1); }); } }, 'button button-small'); sectionTools.appendChild(moveUp);
		var moveDown = button('Move down', function () { if (finishEdit(true)) { run(function () { core.move(selected, 1); }); } }, 'button button-small'); sectionTools.appendChild(moveDown);
		sectionTools.appendChild(button('Duplicate', function () {
			if (!finishEdit(true)) { return; } var result = run(function () { return core.duplicate(selected); });
			if (typeof result === 'string') { select(result); } else if (result && result.id) { select(result.id); }
		}, 'button button-small'));
		var hide = button('Hide', function () { if (finishEdit(true)) { run(function () { core.toggle(selected); }); } }, 'button button-small'); sectionTools.appendChild(hide);
		sectionTools.appendChild(button('Settings', function () { if (finishEdit(true)) { showInspector(inspectorDrawer.hidden); } }, 'button button-small'));
		sectionTools.appendChild(button('Remove', function () {
			if (!finishEdit(true)) { return; } run(function () { core.remove(selected); }); selected = ''; selectedPath = ''; updateSelection(); announce('Section removed. Undo restores it.');
		}, 'button button-small button-link-delete'));
		sectionTools.appendChild(fieldTools);
		root.appendChild(toolbar);
		if (config.onboardingRequired) {
			var setupNotice = node('div', {className: 'nova-visual-setup-notice', role: 'note'});
			setupNotice.appendChild(node('span', {}, 'Finish Service CPT setup before publishing. '));
			if (config.canManage && config.onboardingUrl) { setupNotice.appendChild(node('a', {href: config.onboardingUrl}, 'Resume setup')); }
			else { setupNotice.appendChild(node('span', {}, 'Ask an administrator to finish setup.')); }
			root.appendChild(setupNotice);
		}
		root.appendChild(body); body.appendChild(preview.pane); body.appendChild(structure); body.appendChild(inspectorDrawer); body.appendChild(sectionTools); body.appendChild(richPanel);
		root.classList.add('nova-service-visual-mode'); document.body.classList.add('nova-service-visual-open'); root.classList.toggle('is-phone', preview.device === 'phone');
		preview.pane.hidden = false; preview.workspace.classList.remove('is-preview-hidden', 'is-preview-expanded');
		preview.frame.setAttribute('title', 'Visual editor of your service page');
		Array.prototype.forEach.call(preview.pane.children, function (entry) {
			if (entry.tagName.toLowerCase() === 'p' && entry.classList.contains('description')) { entry.hidden = true; }
		});

		function getSelected() { return selected ? core.getSection(selected) : null; }
		function filterInspector() {
			if (!inspector) { return; }
			inspector.querySelectorAll('.nova-service-section').forEach(function (entry) {
				var match = entry.getAttribute('data-section-id') === selected; entry.hidden = !match;
				if (match && !entry.open) { entry.open = true; }
			});
			inspector.querySelectorAll('.nova-service-order-context').forEach(function (entry) { entry.hidden = true; });
		}
		function renderStructure() {
			structureList.textContent = '';
			(core.getDocument().sections || []).forEach(function (raw, index) {
				var section = core.getSection(raw.id) || raw;
				var row = node('li', {className: 'nova-visual-structure-item' + (selected === raw.id ? ' is-selected' : '')});
				var data = section.effectiveData || section.data || {};
				var name = sectionName(section, registry, index) + (data.title ? ' · ' + data.title : '');
				row.appendChild(button((index + 1) + '. ' + name, function () {
					if (!finishEdit(true)) { return; } select(raw.id, '', '', true);
					if (section.enabled === false || section.sharedEnabled === false || isEmpty(section)) { showInspector(true); }
				}, 'nova-visual-section-select'));
				if (section.enabled === false || section.sharedEnabled === false || isEmpty(section)) { row.appendChild(node('small', {className: 'nova-visual-section-state'}, section.sharedEnabled === false ? 'Hidden in client defaults' : section.enabled === false ? 'Hidden' : 'Empty')); }
				structureList.appendChild(row);
			});
		}
		function updateFieldTools() {
			fieldTools.textContent = ''; var section = getSelected(); if (!section) { return; }
			var state = selectedPath ? fieldState(section, selectedPath) : {inherited: false, overridden: false};
			var sourceNote = selectedPath ? state.inherited ? 'From client defaults · editing changes this page' : state.overridden ? 'Changed on this page' : 'Page content' : section.shared_key ? 'Uses client defaults' : 'Page content';
			fieldTools.appendChild(node('span', {className: 'nova-visual-source-note'}, section.sharedEnabled === false ? 'Hidden in client defaults' : sourceNote));
			if (section.shared_key && config.sharedUrl) { fieldTools.appendChild(node('a', {href: config.sharedUrl, target: '_blank', rel: 'noopener', className: 'nova-visual-shared-link'}, 'Edit client defaults')); }
			if (!selectedPath) { return; }
			var key = selectedPath.split('.')[0];
			var resetLabel = key === 'images' ? 'Use default photos' : key === 'buttons' ? 'Use default buttons' : key === 'items' ? 'Use default ' + (section.type === 'reviews' ? 'reviews' : section.type === 'credentials' ? 'credentials' : 'items') : 'Use client default';
			if (state.overridden) { fieldTools.appendChild(button(resetLabel, function () {
				if (!finishEdit(true)) { return; } run(function () { core.resetField(selected, selectedPath); });
			}, 'button button-small')); }
		}
		function updateSelection() {
			var section = getSelected(), sections = core.getDocument().sections || [];
			sectionTools.hidden = !section || inMetadata; undo.disabled = Boolean(activeEdit) || !core.canUndo(); redo.disabled = Boolean(activeEdit) || !core.canRedo();
			if (section) {
				var index = sections.findIndex(function (entry) { return entry.id === selected; });
				sectionLabel.textContent = sectionName(section, registry, index); moveUp.disabled = index <= 0; moveDown.disabled = index === sections.length - 1;
				hide.disabled = section.sharedEnabled === false;
				hide.textContent = section.sharedEnabled === false ? 'Hidden in defaults' : section.enabled === false ? 'Show' : 'Hide';
			}
			if (frameDocument) { frameDocument.querySelectorAll('[data-nova-service-section-id]').forEach(function (entry) { entry.classList.toggle('nova-service-preview-selected', entry.getAttribute('data-nova-service-section-id') === selected); }); }
			filterInspector(); renderStructure(); updateFieldTools();
		}
		function select(id, path, kind, scroll) {
			selected = id || ''; selectedPath = path || ''; selectedKind = kind || '';
			if (selected) { core.select(selected, Boolean(scroll)); } updateSelection();
		}
		function restoreFocus() {
			if (!lastFocus || !frameDocument) { return; }
			var match = Array.prototype.find.call(frameDocument.querySelectorAll('[data-nova-service-field]'), function (entry) {
				var section = entry.closest('[data-nova-service-section-id]');
				return section && section.getAttribute('data-nova-service-section-id') === lastFocus.id && entry.getAttribute('data-nova-service-field') === lastFocus.path;
			});
			if (match) { match.focus({preventScroll: true}); lastFocus = null; }
		}
		function finishEdit(apply) {
			if (!activeEdit) { return true; }
			var edit = activeEdit;
			try {
				if (apply) {
					var value = edit.read(); edit.transaction.set(value); edit.transaction.apply();
				} else { edit.transaction.cancel(); }
			} catch (error) { announce(error.message, true); return false; }
			activeEdit = null; edit.cleanup(apply); root.classList.remove('is-inline-editing'); core.setEditing(false); updateSelection();
			announce(apply ? 'Edit applied to this page. Save page to publish it.' : 'Edit cancelled.'); return true;
		}
		function hasPendingChanges() {
			if (!activeEdit) { return false; }
			try { return !same(activeEdit.read(), activeEdit.transaction.original); }
			catch (error) { return true; }
		}
		function pasteText(event, ownerDocument) {
			event.preventDefault(); var text = event.clipboardData ? event.clipboardData.getData('text/plain').replace(/\s*[\r\n]+\s*/g, ' ') : '';
			var selection = ownerDocument.getSelection(); if (!selection || !selection.rangeCount) { return; }
			var range = selection.getRangeAt(0); range.deleteContents(); var inserted = ownerDocument.createTextNode(text); range.insertNode(inserted); range.setStartAfter(inserted); range.collapse(true); selection.removeAllRanges(); selection.addRange(range);
		}
		function beginText(target, id, path) {
			if (!finishEdit(true)) { return; } select(id, path, 'text');
			var edit = transaction(core, id, path), originalHTML = target.innerHTML, placeholder = target.getAttribute('data-nova-visual-placeholder'), originalText = placeholder ? '' : target.textContent, dirty = false;
			if (placeholder) { target.textContent = ''; target.removeAttribute('data-nova-visual-placeholder'); target.classList.remove('nova-visual-text-placeholder'); }
			core.setEditing(true); root.classList.add('is-inline-editing');
			target.setAttribute('contenteditable', 'true'); target.setAttribute('role', 'textbox'); target.setAttribute('aria-label', path === '__page_title' ? 'Page title' : 'Edit ' + path);
			target.classList.add('nova-visual-text-editing');
			function blur() { finishEdit(true); }
			function key(event) {
				if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); finishEdit(false); }
				if (event.key === 'Enter' && !event.isComposing) { event.preventDefault(); event.stopPropagation(); finishEdit(true); }
			}
			function input() { dirty = true; }
			function paste(event) { dirty = true; pasteText(event, target.ownerDocument); }
			activeEdit = {transaction: edit, read: function () { return dirty && target.textContent !== originalText ? target.textContent.replace(/\s*[\r\n]+\s*/g, ' ').trim() : edit.original; }, cleanup: function (applied) {
				target.removeEventListener('blur', blur); target.removeEventListener('keydown', key); target.removeEventListener('paste', paste); target.removeEventListener('input', input);
				target.removeAttribute('contenteditable'); target.removeAttribute('role'); target.removeAttribute('aria-label'); target.classList.remove('nova-visual-text-editing');
				if (!applied) { target.innerHTML = originalHTML; }
				if (placeholder && (!applied || !dirty || target.textContent === '')) { target.textContent = placeholder; target.setAttribute('data-nova-visual-placeholder', placeholder); target.classList.add('nova-visual-text-placeholder'); }
				lastFocus = {id: id, path: path};
			}};
			target.addEventListener('blur', blur); target.addEventListener('keydown', key); target.addEventListener('paste', paste); target.addEventListener('input', input);
			target.focus({preventScroll: true}); updateSelection(); announce('Edit text here. Enter applies; Escape cancels.');
		}
		function beginRich(target, id, path) {
			if (!finishEdit(true)) { return; } select(id, path, 'html');
			var edit = transaction(core, id, path), editorId = 'nova-visual-rich-' + (++richCounter), dirty = false, initialEditorHTML = String(edit.original || '');
			core.setEditing(true); root.classList.add('is-inline-editing'); richPanel.textContent = ''; richPanel.hidden = false;
			var content = panel(richPanel, 'Edit rich text', function () { finishEdit(false); });
			var state = fieldState(core.getSection(id), path);
			content.appendChild(node('p', {className: 'nova-visual-source-note'}, state.inherited ? 'From client defaults. Apply changes only this page.' : 'Changes apply to this page.'));
			var textarea = node('textarea', {id: editorId, rows: 12, 'aria-label': 'Rich text content'}); textarea.value = String(edit.original || ''); content.appendChild(textarea);
			function markDirty() { dirty = true; }
			textarea.addEventListener('input', markDirty);
			// WordPress Quicktags changes HTML through the toolbar and dispatches
			// change rather than input. Those edits must participate in Apply too.
			textarea.addEventListener('change', markDirty);
			var actions = node('div', {className: 'nova-visual-rich-actions'});
			actions.appendChild(button('Cancel', function () { finishEdit(false); })); actions.appendChild(button('Apply', function () { finishEdit(true); }, 'button button-primary')); richPanel.appendChild(actions);
			function richKey(event) { if (event.key === 'Escape') { event.preventDefault(); finishEdit(false); } }
			richPanel.addEventListener('keydown', richKey);
			activeEdit = {transaction: edit, read: function () {
				if (!dirty) { return edit.original; }
				var editor = window.tinymce && window.tinymce.get(editorId);
				var value = editor && !editor.isHidden() ? editor.getContent() : textarea.value;
				return value === initialEditorHTML || value === edit.original ? edit.original : value;
			}, cleanup: function () {
				if (window.wp && window.wp.editor) { window.wp.editor.remove(editorId); }
				richPanel.removeEventListener('keydown', richKey); richPanel.hidden = true; richPanel.textContent = ''; lastFocus = {id: id, path: path};
				if (target.isConnected) { target.focus({preventScroll: true}); }
			}};
			if (window.wp && window.wp.editor) {
				window.wp.editor.initialize(editorId, {tinymce: {wpautop: true, height: 280, menubar: false, block_formats: 'Paragraph=p;Heading 2=h2;Heading 3=h3;Heading 4=h4', toolbar1: 'formatselect bold italic bullist numlist blockquote link unlink undo redo', toolbar2: '', setup: function (editor) {
					var initialized = false;
					editor.on('init', function () { initialEditorHTML = editor.getContent(); initialized = true; editor.focus(); });
					editor.on('input change keyup Undo Redo', function () { if (initialized && editor.getContent() !== initialEditorHTML) { dirty = true; } });
					editor.on('keydown', function (event) { if (event.key === 'Escape') { event.preventDefault(); finishEdit(false); } });
				}}, quicktags: true, mediaButtons: false});
			} else { textarea.focus(); }
			updateSelection(); announce('Edit rich text, then choose Apply or Cancel.');
		}
		function chooseImage(id, path) {
			if (!finishEdit(true)) { return; } select(id, path, 'image');
			if (!window.wp || !window.wp.media) { showInspector(true); announce('Choose an image in Section settings.'); return; }
			core.setEditing(true);
			var frame = window.wp.media({title: 'Choose image for this page', button: {text: 'Use image'}, multiple: false, library: {type: 'image'}});
			frame.on('select', function () {
				var attachment = frame.state().get('selection').first().toJSON();
				run(function () { core.replaceImage(id, path, mediaImage(attachment)); }); announce('Image changed on this page. Save page to publish it.');
			});
			frame.on('close', function () { core.setEditing(false); updateSelection(); }); frame.open();
		}
		function attachFrame() {
			var current = preview.frame.contentDocument; if (!current || current === frameDocument) { return; } frameDocument = current;
			var style = current.createElement('style'); style.textContent = '[data-nova-service-field]{cursor:pointer}[data-nova-service-field]:focus{outline:2px solid #7158e2;outline-offset:4px}.nova-visual-text-editing{cursor:text;min-height:1em;outline:2px solid #7158e2!important;outline-offset:4px}.nova-visual-text-placeholder{opacity:.58;min-height:1em}[data-nova-service-section-id].nova-service-preview-selected{outline:2px solid #7158e2!important;outline-offset:2px}'; current.head.appendChild(style);
			current.querySelectorAll('.nova-service__preview-placeholder').forEach(function (entry) {
				var field = entry.closest('[data-nova-service-field]'), path = field && field.getAttribute('data-nova-service-field');
				var sectionNode = entry.closest('[data-nova-service-section-id]'), section = sectionNode && core.getSection(sectionNode.getAttribute('data-nova-service-section-id'));
				entry.textContent = path === 'map_url' ? 'Google Maps — click to edit location' : path === 'video_url' ? 'Video — click to edit' : section && section.type === 'custom_cta' ? 'Form or calendar — select section settings' : 'Embedded content — select section settings';
			});
			current.querySelectorAll('[data-nova-service-field]').forEach(function (entry) {
				entry.setAttribute('tabindex', '0');
				if (entry.getAttribute('data-nova-service-kind') === 'text' && !entry.textContent.trim()) {
					var path = entry.getAttribute('data-nova-service-field'), placeholder = path === '__page_title' || path === 'title' ? 'Click to add a title' : 'Click to add text';
					entry.textContent = placeholder; entry.setAttribute('data-nova-visual-placeholder', placeholder); entry.classList.add('nova-visual-text-placeholder');
				}
			});
			function activate(event) {
				var target = event.target.closest && event.target.closest('[data-nova-service-field]');
				if (event.target.closest && event.target.closest('a,button[type="submit"],input[type="submit"]')) { event.preventDefault(); }
				if (activeEdit && activeEdit.read && event.target.closest && event.target.closest('.nova-visual-text-editing')) { return; }
				var section = event.target.closest && event.target.closest('[data-nova-service-section-id]'); if (!section) { return; }
				var id = section.getAttribute('data-nova-service-section-id'), path = target && target.getAttribute('data-nova-service-field'), kind = target && target.getAttribute('data-nova-service-kind');
				if (!target || !path) { if (finishEdit(true)) { select(id); } return; }
				event.preventDefault();
				if (kind === 'text') { beginText(target, id, path); }
				else if (kind === 'html') { beginRich(target, id, path); }
				else if (kind === 'image') { chooseImage(id, path); }
				else { if (finishEdit(true)) { select(id, path, kind); showInspector(true); } }
			}
			current.addEventListener('click', activate);
			current.addEventListener('keydown', function (event) {
				if ((event.key === 'Enter' || event.key === ' ') && event.target.hasAttribute('data-nova-service-field') && event.target.getAttribute('contenteditable') !== 'true') { activate(event); }
			});
			current.addEventListener('submit', function (event) { event.preventDefault(); }); updateSelection(); restoreFocus();
			if (current.documentElement) { current.documentElement.setAttribute('data-nova-visual-ready', 'true'); }
			preview.framePending = false; preview.viewport.classList.remove('is-frame-loading');
		}
		preview.frame.addEventListener('load', attachFrame);
		if (window.addEventListener) { window.addEventListener('beforeunload', function (event) {
			// Buffered edits have not reached the document yet. Warn before leaving
			// without committing them, so merely opening or cancelling an inherited
			// field still creates no override or undo entry.
			if (hasPendingChanges()) { event.preventDefault(); event.returnValue = ''; }
		}); }
		core.subscribe(function (event) {
			if (event && event.type === 'beforeSave') { var completed = finishEdit(true); if (!completed && event.preventDefault) { event.preventDefault(); } return completed; }
			if (!event || event.type === 'model') { updateSelection(); }
			if (event && event.type === 'frame') { attachFrame(); updateSelection(); restoreFocus(); }
			if (event && event.type === 'status') { announce(event.message || (preview.status && preview.status.textContent) || ''); }
		});
		updateSelection(); attachFrame(); core.setDevice(preview.device || 'desktop');
		announce('Click text to edit. Click an image or select Section settings for other changes.');
		return {select: select, finishEdit: finishEdit, openInspector: function (id) { select(id); showInspector(true); }, isEditing: function () { return Boolean(activeEdit); }, hasPendingChanges: hasPendingChanges};
	}

	var api = {mount: mount, transaction: transaction, fieldState: fieldState, mediaImage: mediaImage, isEmpty: isEmpty};
	if (typeof module !== 'undefined' && module.exports) { module.exports = api; }
	if (host && host.document) {
		host.novaServiceVisualEditor = api;
		function boot() { if (host.novaServiceVisualCore) { api.instance = mount(host.novaServiceVisualCore, host, host.document); } }
		host.document.addEventListener('nova-service-visual-ready', boot); boot();
	}
})(typeof window !== 'undefined' ? window : null);
