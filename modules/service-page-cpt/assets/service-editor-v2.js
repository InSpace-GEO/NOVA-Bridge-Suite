(function (window, document, $) {
	'use strict';

	var config = window.novaServiceEditor;
	var root = document.getElementById('nova-service-editor');
	if (!config || !root) { return; }
	var message = root.querySelector('.nova-service-message');
	var saved = true;
	var submitting = false;
	var form = document.getElementById('post') || document.getElementById('nova-service-settings-form');
	if (form) {
		['input', 'change'].forEach(function (name) { form.addEventListener(name, function (event) {
			if (event.isTrusted && !event.target.hasAttribute('data-review-preview') && !event.target.hasAttribute('data-preview-control')) {
				saved = false; updatePreviewSavedState();
				if (event.target.id === 'title') { schedulePreview(); }
			}
		}); });
	}

	function element(tag, attrs, text) {
		var node = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (key) {
			if (key === 'className') { node.className = attrs[key]; }
			else { node.setAttribute(key, attrs[key]); }
		});
		if (text !== undefined) { node.textContent = text; }
		return node;
	}
	function button(text, handler, className) {
		var node = element('button', {type: 'button', className: className || 'button'}, text);
		node.addEventListener('click', handler);
		return node;
	}
	function status(text, error) {
		message.textContent = text;
		message.className = 'nova-service-message' + (text ? (error ? ' notice notice-error inline' : ' notice notice-success inline') : '');
	}
	function request(url, options) {
		options = options || {};
		options.headers = Object.assign({'X-WP-Nonce': config.restNonce, 'Content-Type': 'application/json'}, options.headers || {});
		options.credentials = 'same-origin';
		return window.fetch(url, options).then(function (response) {
			return response.json().then(function (data) {
				if (!response.ok) { var error = new Error(data.message || 'The request could not be completed.'); error.data = data.data || {}; throw error; }
				return data;
			});
		});
	}
	root.querySelectorAll('[data-service-migrate]').forEach(function (control) {
		control.addEventListener('click', function () {
			if (!saved) { status('Save or update your page before changing its layout.', true); return; }
			var action = control.getAttribute('data-service-migrate');
			control.disabled = true;
			status('Changing this page’s layout…');
			request(config.restUrl, {method: 'POST', body: JSON.stringify({action: action})}).then(function () {
				submitting = true;
				window.location.assign(config.editUrl);
			}).catch(function (error) { control.disabled = false; status(error.message, true); });
		});
	});
	if (!config.isV2) {
		request(config.restUrl).then(function (data) {
			var preview = root.querySelector('[data-service-preview]');
			if (preview && data.preview_url) { preview.href = data.preview_url; }
			var warnings = root.querySelector('.nova-service-migration-warnings');
			(data.warnings || []).forEach(function (warning) { warnings.appendChild(element('li', {}, warning)); });
			if (data.can_migrate === false) { root.querySelector('[data-service-migrate="migrate"]').disabled = true; }
		}).catch(function (error) { status(error.message, true); });
		return;
	}

	var input = document.getElementById('nova-service-document');
	var list = root.querySelector('.nova-service-sections');
	var registry = config.sections || {};
	var doc = config.document || {version: 2, sections: []};
	if (!Array.isArray(doc.sections)) { doc.sections = []; }
	var richFields = {};
	var expanded = {};
	var editorCounter = 0;
	var relatedTitles = {};
	var presets = (config.presets && config.presets.sections) || [];
	var libraryMode = config.mode === 'library';
	var editorMode = config.mode || 'page';
	var preview = null;
	var previewTargets = Array.isArray(config.previewTargets) ? config.previewTargets : [];
	var selectedPreviewId = Number(config.previewPostId || config.postId || 0);
	if (editorMode !== 'page' && !previewTargets.some(function (target) { return Number(target.id) === selectedPreviewId; })) { selectedPreviewId = previewTargets.length ? Number(previewTargets[0].id) : 0; }
	var libraryView = selectedPreviewId ? 'page' : 'library';
	var activeSectionId = '';
	var activePreviewId = '';
	var orderPanel = null;
	var localBackups = {};
	var reviewImports = {};
	var bulkReviews = {};
	var richTypes = ['hero', 'rich_text', 'people', 'location', 'reviews', 'credentials', 'cta', 'custom_cta', 'related', 'faq'];
	var imageTypes = ['hero', 'people', 'location', 'cta'];
	var buttonTypes = ['hero', 'cta'];

	function clone(value) { return JSON.parse(JSON.stringify(value)); }
	function presetFor(section) { return presets.find(function (preset) { return preset.id === section.shared_key && preset.type === section.type; }); }
	function hasOwn(object, key) { return Object.prototype.hasOwnProperty.call(object, key); }
	function effectiveData(section) {
		var preset = presetFor(section);
		return Object.assign({}, preset ? clone(preset.data || {}) : {}, clone(section.data || {}));
	}
	function richTextSlot(section) {
		if (section.type !== 'rich_text') { return ''; }
		var aliases = {offer: 'rich_text_1', how: 'rich_text_2', why: 'rich_text_3'};
		var slot = section.content_slot || '';
		if (aliases[slot]) { return aliases[slot]; }
		if (/^rich_text_[123]$/.test(slot)) { return slot; }
		var match = /^(?:content_)*(rich_text_[123]|offer|how|why)$/.exec(section.id || '');
		if (match) { return aliases[match[1]] || match[1]; }
		// Existing documents keep their old internal values. These are only
		// mapped to generic labels; rendering the editor changes no fields.
		return aliases[effectiveData(section).intent] || '';
	}
	function sectionLabel(section) {
		if (section.type !== 'rich_text') { return (registry[section.type] || {}).label || section.type; }
		var slot = richTextSlot(section);
		var ordinal = slot ? Number(slot.slice(-1)) : doc.sections.filter(function (entry) { return entry.type === 'rich_text'; }).indexOf(section) + 1;
		return 'Rich text ' + Math.max(1, ordinal);
	}
	function collection(section, key) {
		var rows = Array.isArray(section.data[key]) ? section.data[key] : [];
		return (section.data[key] = rows.map(function (row) { return row && !Array.isArray(row) && typeof row === 'object' ? row : {}; }));
	}
	function changed() { saved = false; write(); updatePreviewSavedState(); renderOrderContext(); schedulePreview(); }
	function write() { input.value = JSON.stringify(doc); }
	function selectedPreviewTarget() { return previewTargets.find(function (target) { return Number(target.id) === selectedPreviewId; }) || null; }
	function syncPreviewTargetContext() {
		var hidden = document.getElementById('nova-service-preview-post-id'); if (hidden) { hidden.value = selectedPreviewId; }
		var settings = root.closest('.nova-service-settings'); if (!settings) { return; }
		settings.querySelectorAll('a.nav-tab').forEach(function (tab) {
			var url = new URL(tab.href, window.location.href);
			if (url.origin !== window.location.origin) { return; }
			if (selectedPreviewId) { url.searchParams.set('preview_post_id', selectedPreviewId); } else { url.searchParams.delete('preview_post_id'); }
			tab.href = url.href;
		});
	}
	function previewTargetSections() {
		if (!libraryMode) { return doc.sections; }
		var target = selectedPreviewTarget();
		return target ? (target.sectionOrder || (target.document && target.document.sections) || []) : [];
	}
	function orderLibrarySections(sections, pageSections) {
		var linked = [], unused = [], seen = Object.create(null);
		pageSections.forEach(function (pageSection, position) {
			var index = sections.findIndex(function (section) { return section.id === pageSection.shared_key && section.type === pageSection.type; });
			if (index < 0) { return; }
			var key = sections[index].id;
			if (!seen[key]) { seen[key] = {section: sections[index], index: index, positions: []}; linked.push(seen[key]); }
			seen[key].positions.push(position + 1);
		});
		sections.forEach(function (section, index) { if (!seen[section.id]) { unused.push({section: section, index: index, positions: []}); } });
		return {linked: linked, unused: unused};
	}
	function previewIdsFor(sectionId) {
		if (!libraryMode) { return [sectionId]; }
		var preset = doc.sections.find(function (section) { return section.id === sectionId; });
		return previewTargetSections().filter(function (section) { return preset && section.shared_key === sectionId && section.type === preset.type; }).map(function (section) { return section.id; });
	}
	function updatePreviewSavedState() {
		if (!preview) { return; }
		preview.saved.textContent = saved ? 'Saved content' : 'Unsaved changes';
		preview.saved.classList.toggle('is-unsaved', !saved);
	}
	function focusPreviewSection(sectionId, previewId, scroll) {
		activeSectionId = sectionId;
		var ids = previewIdsFor(sectionId);
		activePreviewId = previewId && ids.indexOf(previewId) >= 0 ? previewId : (ids[0] || '');
		list.querySelectorAll('.nova-service-section').forEach(function (row) { row.classList.toggle('is-preview-active', row.getAttribute('data-section-id') === sectionId); });
		if (orderPanel) { orderPanel.querySelectorAll('[data-order-section]').forEach(function (row) { row.classList.toggle('is-preview-active', row.getAttribute('data-order-section') === (activePreviewId || sectionId)); }); }
		if (!preview || !preview.frame.contentDocument) { return; }
		var frameDoc = preview.frame.contentDocument, active = null;
		frameDoc.querySelectorAll('[data-nova-service-section-id]').forEach(function (section) {
			var chosen = section.getAttribute('data-nova-service-section-id') === activePreviewId;
			section.classList.toggle('nova-service-preview-selected', chosen); if (chosen) { active = section; }
		});
		if (active && scroll) {
			// Scroll only the preview document. scrollIntoView also moves parent
			// scroll containers and can shift an editor control before mouseup.
			var frameWindow = preview.frame.contentWindow;
			frameWindow.scrollTo({top: Math.max(0, active.getBoundingClientRect().top + frameWindow.scrollY - 10), behavior: 'auto'});
		}
	}
	function focusEditorSection(sectionId, previewId) {
		var row = Array.prototype.find.call(list.querySelectorAll('.nova-service-section'), function (node) { return node.getAttribute('data-section-id') === sectionId; });
		if (!row) { return; }
		row.open = true; row.scrollIntoView({block: 'nearest', behavior: 'auto'}); focusPreviewSection(sectionId, previewId, true);
	}
	function renderOrderContext() {
		if (!orderPanel) { return; }
		var wasOpen = orderPanel.open;
		orderPanel.textContent = '';
		var sections = previewTargetSections(), target = selectedPreviewTarget();
		orderPanel.appendChild(element('summary', {}, (libraryMode ? 'Sections on ' + (target ? target.title : 'the preview page') : 'Section order') + ' (' + sections.length + ')'));
		if (libraryMode) {
			orderPanel.appendChild(element('p', {className: 'description'}, 'Shared sections below follow the selected page’s order. Local sections belong to that page; change their content or order from its page editor.'));
			if (target && target.editUrl) { var link = element('a', {href: target.editUrl, target: '_blank', rel: 'noopener'}, 'Edit this service page'); orderPanel.appendChild(link); }
			orderPanel.appendChild(button(libraryView === 'page' ? 'View library order' : 'View selected page order', function () { removeEditors(); libraryView = libraryView === 'page' ? 'library' : 'page'; render(); }, 'button button-small'));
		}
		var ordered = element('ol', {className: 'nova-service-order-list'});
		sections.forEach(function (section, index) {
			var row = element('li', {'data-order-section': section.id});
			var label = section.title || (section.data && section.data.title) || (registry[section.type] || {}).label || section.type;
			var sharedKey = section.shared_key;
			var matching = !libraryMode || doc.sections.some(function (preset) { return preset.id === sharedKey && preset.type === section.type; });
			var name = (index + 1) + '. ' + label + (section.enabled === false ? ' (hidden)' : '');
			if (matching) { row.appendChild(button(name, function () { focusEditorSection(libraryMode ? sharedKey : section.id, section.id); }, 'button-link')); }
			else { row.appendChild(element('span', {}, name)); }
			if (libraryMode) { row.appendChild(element('small', {className: 'description'}, sharedKey ? 'Shared: ' + sharedKey + (matching ? '' : ' (missing version)') : 'Local content')); }
			ordered.appendChild(row);
		});
		orderPanel.appendChild(ordered); orderPanel.open = wasOpen;
		if (activeSectionId) { focusPreviewSection(activeSectionId, activePreviewId, false); }
	}
	function librarySectionContext(section) {
		var target = selectedPreviewTarget();
		var references = previewTargetSections().filter(function (entry) { return entry.shared_key === section.id && entry.type === section.type; });
		var usages = (config.presetUsage || {})[section.id] || [];
		var meta = (config.presetMeta || {})[section.id] || {};
		var lines = [];
		if (target && !references.length) { lines.push('Not used on ' + target.title + '. Changes to this version will not affect the selected preview page.'); }
		if (references.length) {
			var positions = references.map(function (reference) { return previewTargetSections().indexOf(reference) + 1; });
			lines.push('Used on ' + target.title + ' at ' + (positions.length > 1 ? 'positions ' : 'position ') + positions.join(', ') + '.');
			var overrides = [];
			references.forEach(function (reference) { (reference.overrideFields || Object.keys(reference.data || {})).forEach(function (key) { if (overrides.indexOf(key) < 0) { overrides.push(key); } }); });
			var names = {layout: 'appearance', body: 'text', title: 'heading', review_source: 'review source', items: 'items', heading_level: 'heading level'};
			if (overrides.length) { lines.push('Page overrides: ' + overrides.map(function (key) { return names[key] || key.replace(/_/g, ' '); }).join(', ') + '. Those fields keep the page’s local values.'); }
			else { lines.push('This page inherits every field from this version.'); }
			if (section.enabled === false || references.every(function (reference) { return reference.enabled === false; })) { lines.push('This version is hidden on the selected page.'); }
		}
		var pageCount = usages.map(function (usage) { return usage.postId; }).filter(function (id, index, ids) { return ids.indexOf(id) === index; }).length;
		lines.push(pageCount ? 'Used on ' + pageCount + (pageCount === 1 ? ' service page.' : ' service pages.') : 'No service pages currently use this version.');
		if (meta.usedByTemplate) { lines.push('Included in the starting layout for new pages.'); }
		if (meta.reserved && meta.empty) { lines.push('Empty starting version: add shared content here only if you want pages to use this version.'); }
		return lines.join(' ');
	}
	function fitPreviewFrame() {
		if (!preview || !preview.viewport.clientWidth) { return; }
		var width = preview.device === 'phone' ? 390 : 1140;
		var scale = Math.min(1, preview.viewport.clientWidth / width);
		preview.frame.style.width = width + 'px';
		preview.frame.style.height = Math.max(360, preview.viewport.clientHeight / scale) + 'px';
		preview.frame.style.transform = 'scale(' + scale + ')';
	}
	function schedulePreview(immediate) {
		if (!preview || !config.editorPreviewUrl) { return; }
		window.clearTimeout(preview.timer); preview.serial++;
		if (preview.controller) { preview.controller.abort(); preview.controller = null; }
		preview.status.textContent = immediate ? 'Updating preview…' : 'Waiting for your latest changes…';
		preview.timer = window.setTimeout(refreshPreview, immediate ? 0 : 650);
	}
	function refreshPreview() {
		if (!preview) { return; }
		collectEditors(); var serial = preview.serial;
		var controller = window.AbortController ? new window.AbortController() : null; preview.controller = controller;
		preview.status.textContent = 'Updating preview…'; preview.pane.setAttribute('aria-busy', 'true');
		var payload = {mode: editorMode, post_id: editorMode === 'page' ? Number(config.postId) : selectedPreviewId, document: clone(doc)};
		var title = document.getElementById('title'); if (editorMode === 'page' && title) { payload.title = title.value; }
		request(config.editorPreviewUrl, {method: 'POST', body: JSON.stringify(payload), signal: controller ? controller.signal : undefined}).then(function (result) {
			if (serial !== preview.serial) { return; }
			if (!result || typeof result.html !== 'string') { throw new Error('The page preview did not return a display.'); }
			try { preview.scrollY = preview.frame.contentWindow.scrollY || 0; } catch (error) { preview.scrollY = 0; }
			preview.appliedSerial = serial; preview.frame.srcdoc = result.html;
			preview.warnings.textContent = '';
			(result.warnings || []).forEach(function (warning) { preview.warnings.appendChild(element('li', {}, warning)); });
			preview.notes.hidden = !(result.warnings || []).length;
			preview.status.textContent = 'Preview updated' + (saved ? '.' : ' — changes are not saved.');
		}).catch(function (error) {
			if (serial !== preview.serial || error.name === 'AbortError') { return; }
			preview.status.textContent = 'Preview could not update: ' + error.message + (preview.appliedSerial ? ' The last preview is still shown.' : '');
		}).finally(function () { if (serial === preview.serial) { preview.pane.setAttribute('aria-busy', 'false'); preview.controller = null; } });
	}
	function setupPreview() {
		if (!config.editorPreviewUrl) { return; }
		var pane = root.querySelector('.nova-service-preview-pane'), workspace = root.querySelector('.nova-service-workspace');
		if (!pane || !workspace) { return; }
		pane.hidden = false; workspace.classList.add('has-preview');
		var stacked = window.innerWidth <= 1050 || workspace.clientWidth <= 650;
		preview = {pane: pane, workspace: workspace, device: stacked ? 'phone' : 'desktop', timer: null, controller: null, serial: 0, appliedSerial: 0, scrollY: 0};
		var heading = element('div', {className: 'nova-service-preview-heading'}); heading.appendChild(element('h3', {}, 'Live page preview'));
		preview.saved = element('span', {className: 'nova-service-preview-saved'}); heading.appendChild(preview.saved); pane.appendChild(heading);
		if (editorMode !== 'page') {
			var targetLabel = element('label', {className: 'nova-service-field'}); targetLabel.appendChild(element('span', {className: 'nova-service-field-label'}, 'Preview service page'));
			var selector = element('select', {'data-preview-control': '1'});
			if (!previewTargets.length) { selector.appendChild(element('option', {value: 0}, 'Sample service page')); }
			previewTargets.forEach(function (target) { selector.appendChild(element('option', {value: target.id}, target.title)); }); selector.value = selectedPreviewId;
			selector.addEventListener('change', function () {
				removeEditors(); selectedPreviewId = Number(selector.value); libraryView = selectedPreviewId ? 'page' : 'library'; activePreviewId = ''; activeSectionId = ''; preview.scrollY = 0;
				syncPreviewTargetContext();
				render(); schedulePreview(true);
			}); targetLabel.appendChild(selector); pane.appendChild(targetLabel);
			pane.appendChild(element('p', {className: 'description'}, libraryMode ? 'See your shared changes on this page before saving. Page overrides stay in place.' : 'See this starting layout with content from the selected page. Saving this layout does not change that page.'));
		}
		var controls = element('div', {className: 'nova-service-preview-controls'});
		['desktop', 'phone'].forEach(function (device) {
			var control = button(device === 'desktop' ? 'Desktop' : 'Phone', function () { preview.device = device; controls.querySelectorAll('[data-preview-device]').forEach(function (node) { node.setAttribute('aria-pressed', String(node.getAttribute('data-preview-device') === device)); }); fitPreviewFrame(); }, 'button button-small');
			control.setAttribute('data-preview-device', device); control.setAttribute('aria-pressed', String(device === preview.device)); controls.appendChild(control);
		});
		controls.appendChild(button('Refresh', function () { schedulePreview(true); }, 'button button-small'));
		var expand = button('Expand preview', function () { var expanded = workspace.classList.toggle('is-preview-expanded'); expand.textContent = expanded ? 'Back to editing' : 'Expand preview'; expand.setAttribute('aria-expanded', String(expanded)); fitPreviewFrame(); }, 'button button-small'); expand.setAttribute('aria-expanded', 'false'); controls.appendChild(expand); pane.appendChild(controls);
		var sizeLabel = element('label', {className: 'nova-service-preview-size'}); sizeLabel.appendChild(element('span', {}, 'Preview width'));
		var size = element('input', {type: 'range', min: 35, max: 65, value: 44, 'data-preview-control': '1', 'aria-label': 'Preview column width'});
		size.addEventListener('input', function () { workspace.style.setProperty('--nova-preview-width', size.value + '%'); fitPreviewFrame(); }); sizeLabel.appendChild(size); pane.appendChild(sizeLabel);
		preview.status = element('p', {className: 'nova-service-preview-status', role: 'status', 'aria-live': 'polite'}); pane.appendChild(preview.status);
		preview.viewport = element('div', {className: 'nova-service-preview-viewport'});
		preview.frame = element('iframe', {className: 'nova-service-preview-frame', title: 'Preview of your current service page changes', sandbox: 'allow-same-origin', referrerpolicy: 'same-origin'});
		preview.frame.addEventListener('load', function () {
			if (!preview.appliedSerial) { return; }
			var frameDoc = preview.frame.contentDocument; if (!frameDoc) { return; }
			var selectionStyle = frameDoc.createElement('style'); selectionStyle.textContent = '.nova-service-preview-selected{outline:3px solid #2271b1!important;outline-offset:3px;scroll-margin-top:16px}'; frameDoc.head.appendChild(selectionStyle);
			frameDoc.addEventListener('click', function (event) {
				if (event.target.closest('a,button[type="submit"],input[type="submit"]')) { event.preventDefault(); }
				var section = event.target.closest('[data-nova-service-section-id]'); if (!section) { return; }
				var id = section.getAttribute('data-nova-service-section-id'), editorId = id;
				if (libraryMode) { var reference = previewTargetSections().find(function (entry) { return entry.id === id; }); editorId = reference && doc.sections.some(function (preset) { return preset.id === reference.shared_key && preset.type === reference.type; }) ? reference.shared_key : ''; }
				if (editorId) { focusEditorSection(editorId, id); }
			});
			frameDoc.addEventListener('submit', function (event) { event.preventDefault(); });
			if (activeSectionId) { focusPreviewSection(activeSectionId, activePreviewId, true); }
			else { preview.frame.contentWindow.scrollTo(0, preview.scrollY); }
			fitPreviewFrame();
		}); preview.viewport.appendChild(preview.frame); pane.appendChild(preview.viewport);
		preview.notes = element('details', {className: 'nova-service-preview-notes', hidden: ''}); preview.notes.appendChild(element('summary', {}, 'Preview notes')); preview.warnings = element('ul'); preview.notes.appendChild(preview.warnings); pane.appendChild(preview.notes);
		pane.appendChild(element('p', {className: 'description'}, 'This preview shows your current edits. Save the page or shared settings to publish them. Forms and external embeds may be inactive here.'));
		var mobileToggle = button(stacked ? 'Show live preview' : 'Hide live preview', function () {
			var hidden = pane.hidden; pane.hidden = !hidden; workspace.classList.toggle('is-preview-hidden', !hidden); mobileToggle.setAttribute('aria-expanded', String(hidden));
			mobileToggle.textContent = hidden ? 'Hide live preview' : 'Show live preview';
			if (!hidden) { workspace.classList.remove('is-preview-expanded'); expand.textContent = 'Expand preview'; expand.setAttribute('aria-expanded', 'false'); }
			if (hidden) { fitPreviewFrame(); pane.scrollIntoView({block: 'nearest'}); }
		}, 'button nova-service-preview-toggle'); mobileToggle.setAttribute('aria-expanded', String(!stacked)); workspace.insertBefore(mobileToggle, workspace.firstChild);
		if (stacked) { pane.hidden = true; workspace.classList.add('is-preview-hidden'); }
		orderPanel = element('details', {className: 'nova-service-order-context'}); orderPanel.open = true;
		var editPane = workspace.querySelector('.nova-service-edit-pane'); editPane.insertBefore(orderPanel, list);
		if (window.ResizeObserver) { preview.resizeObserver = new window.ResizeObserver(fitPreviewFrame); preview.resizeObserver.observe(preview.viewport); } else { window.addEventListener('resize', fitPreviewFrame); }
		list.addEventListener('focusin', function (event) { var section = event.target.closest('.nova-service-section'); if (section) { focusPreviewSection(section.getAttribute('data-section-id'), '', true); } });
		list.addEventListener('click', function (event) { var section = event.target.closest('.nova-service-section'); if (section) { focusPreviewSection(section.getAttribute('data-section-id'), '', true); } });
		if (window.IntersectionObserver) {
			preview.sectionObserver = new window.IntersectionObserver(function (entries) {
				var visible = entries.filter(function (entry) { return entry.isIntersecting; }).sort(function (a, b) { return Math.abs(a.boundingClientRect.top - 110) - Math.abs(b.boundingClientRect.top - 110); });
				if (visible.length) { focusPreviewSection(visible[0].target.getAttribute('data-section-id'), '', true); }
			}, {rootMargin: '-100px 0px -55% 0px', threshold: 0});
		}
		updatePreviewSavedState(); schedulePreview(true);
		syncPreviewTargetContext();
	}
	function collectEditors() {
		Object.keys(richFields).forEach(function (id) {
			var editor = window.tinymce && window.tinymce.get(id);
			var textarea = document.getElementById(id);
			var content = editor && !editor.isHidden() ? editor.getContent() : (textarea ? textarea.value : '');
			richFields[id](content);
		});
		write();
	}
	function removeEditors() {
		collectEditors();
		Object.keys(richFields).forEach(function (id) {
			if (window.wp && window.wp.editor) { window.wp.editor.remove(id); }
		});
		richFields = {};
	}
	function initEditors(container) {
		if (!window.wp || !window.wp.editor) { return; }
		container.querySelectorAll('textarea[data-rich-editor]').forEach(function (textarea) {
			if (textarea.disabled || textarea.getAttribute('data-editor-ready')) { return; }
			textarea.setAttribute('data-editor-ready', '1');
			window.wp.editor.initialize(textarea.id, {
				tinymce: {wpautop: true, height: 180, menubar: false, block_formats: 'Paragraph=p;Heading 2=h2;Heading 3=h3;Heading 4=h4', toolbar1: 'formatselect bold italic bullist numlist blockquote link unlink undo redo', toolbar2: '', setup: function (editor) {
					var initialized = false;
					editor.on('init', function () { initialized = true; });
					editor.on('focus', function () { var row = textarea.closest('.nova-service-section'); if (row) { focusPreviewSection(row.getAttribute('data-section-id'), '', true); } });
					editor.on('change keyup input SetContent', function () {
						if (initialized && richFields[textarea.id]) { richFields[textarea.id](editor.getContent()); changed(); }
					});
				}},
				quicktags: true, mediaButtons: false
			});
		});
	}
	function field(container, labelText, value, setter, options) {
		options = options || {};
		var label = element('label', {className: 'nova-service-field'});
		label.appendChild(element('span', {className: 'nova-service-field-label'}, labelText));
		var control;
		if (options.choices) {
			control = element('select');
			Object.keys(options.choices).forEach(function (key) { control.appendChild(element('option', {value: key}, options.choices[key])); });
		} else if (options.multiline || options.rich) {
			control = element('textarea', {rows: options.rows || 4});
		} else {
			control = element('input', {type: options.type || 'text'});
		}
		control.value = options.choices && !value ? Object.keys(options.choices)[0] : (value === undefined || value === null ? '' : value);
		if (options.min !== undefined) { control.min = options.min; }
		if (options.max !== undefined) { control.max = options.max; }
		if (options.step !== undefined) { control.step = options.step; }
		if (options.pattern) { control.pattern = options.pattern; }
		if (options.maxLength) { control.maxLength = options.maxLength; }
		if (options.disabled) { control.disabled = true; }
		if (options.placeholder) { control.placeholder = options.placeholder; }
		control.addEventListener(options.choices ? 'change' : 'input', function () { setter(control.value); changed(); });
		if (options.rich && !options.disabled) {
			control.id = 'nova_service_rich_' + (++editorCounter);
			control.setAttribute('data-rich-editor', '1');
			richFields[control.id] = setter;
		}
		label.appendChild(control);
		if (options.help) { label.appendChild(element('small', {className: 'description'}, options.help)); }
		container.appendChild(label);
		return control;
	}
	function dataField(container, section, key, label, options) {
		options = Object.assign({}, options || {});
		var preset = presetFor(section);
		var inherited = section.shared_key && !hasOwn(section.data, key);
		if (section.shared_key) { overrideToggle(container, section, key, label, options.choices ? Object.keys(options.choices)[0] : ''); }
		options.disabled = inherited;
		return field(container, label, inherited ? (preset && preset.data && preset.data[key]) : section.data[key], function (value) { section.data[key] = value; }, options);
	}
	function dataCheckbox(container, section, key, labelText, defaultValue, help) {
		var preset = presetFor(section), inherited = Boolean(section.shared_key && !hasOwn(section.data, key));
		overrideToggle(container, section, key, labelText, defaultValue);
		var data = inherited ? (preset && preset.data || {}) : section.data;
		var value = hasOwn(data, key) ? data[key] : defaultValue;
		var label = element('label', {className: 'nova-service-checkbox-control'});
		var checkbox = element('input', {type: 'checkbox'}); checkbox.checked = value !== false; checkbox.disabled = inherited;
		checkbox.addEventListener('change', function () { section.data[key] = checkbox.checked; changed(); });
		label.appendChild(checkbox); label.appendChild(document.createTextNode(labelText)); container.appendChild(label);
		if (help) { container.appendChild(element('p', {className: 'description nova-service-checkbox-help'}, help)); }
	}
	function overrideToggle(container, section, key, label, fallback) {
		var preset = presetFor(section) || {data: {}};
		if (!section.shared_key) { return; }
		var wrapper = element('label', {className: 'nova-service-override'});
		var checkbox = element('input', {type: 'checkbox'}); checkbox.checked = hasOwn(section.data, key);
		checkbox.addEventListener('change', function () {
			removeEditors();
			if (checkbox.checked) { section.data[key] = hasOwn(preset.data || {}, key) ? clone(preset.data[key]) : clone(fallback); }
			else { delete section.data[key]; }
			changed(); render();
		});
		wrapper.appendChild(checkbox); wrapper.appendChild(document.createTextNode('Override ' + label.toLowerCase() + ' for this section'));
		container.appendChild(wrapper);
	}
	function collectionGroup(container, section, key, label, renderer) {
		var preset = presetFor(section);
		var inherited = section.shared_key && !hasOwn(section.data, key);
		overrideToggle(container, section, key, label, []);
		var wrapper = element('div', {className: inherited ? 'nova-service-inherited' : ''});
		var displayed = inherited ? Object.assign({}, section, {data: effectiveData(section)}) : section;
		renderer(wrapper, displayed);
		if (inherited) { wrapper.querySelectorAll('input, textarea, select, button').forEach(function (control) { control.disabled = true; }); }
		container.appendChild(wrapper);
	}
	function renderSharedSelector(container, section) {
		if (libraryMode) { return; }
		var choices = {'': 'Local section — content belongs to this page'};
		presets.filter(function (preset) { return preset.type === section.type; }).forEach(function (preset) { choices[preset.id] = preset.id + (preset.data && preset.data.title ? ' — ' + preset.data.title : '') + (preset.enabled === false ? ' (globally hidden)' : ''); });
		if (section.shared_key && !hasOwn(choices, section.shared_key)) { choices[section.shared_key] = section.shared_key + ' (missing shared section)'; }
		field(container, 'Reusable shared section', section.shared_key || '', function (key) {
			removeEditors();
			if (key) {
				if (!section.shared_key) { localBackups[section.id] = clone(section.data); }
				section.shared_key = key; section.data = {};
			} else {
				section.data = effectiveData(section); delete section.shared_key;
			}
			changed(); render();
		}, {choices: choices, help: 'Client defaults are selected by the page layout. Leave the shared version linked to follow the site settings, or choose another version. Check an Override box to customize only that field.'});
		if (section.shared_key) {
			var activePreset = presetFor(section);
			container.appendChild(element('p', {className: 'nova-service-shared-note'}, 'Linked to shared section: ' + section.shared_key + '. Unchecked fields inherit shared content. Empty local overrides intentionally clear shared values.' + (activePreset && activePreset.enabled === false ? ' This shared version is globally hidden; enable it in the shared library to show it on linked pages.' : '')));
			container.appendChild(button('Copy shared section into local fields', function () { removeEditors(); section.data = effectiveData(section); delete section.shared_key; changed(); render(); }));
			if (localBackups[section.id]) { container.appendChild(button('Restore previous local fields', function () { removeEditors(); section.data = localBackups[section.id]; delete localBackups[section.id]; delete section.shared_key; changed(); render(); })); }
		}
	}
	function moveSection(index, offset) {
		if (index + offset < 0 || index + offset >= doc.sections.length) { return; }
		removeEditors();
		var section = doc.sections.splice(index, 1)[0];
		doc.sections.splice(index + offset, 0, section);
		changed(); render();
	}
	function uniqueId() { return 'section-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 8); }
	function renderImages(container, section) {
		var images = collection(section, 'images');
		var group = element('div', {className: 'nova-service-group'});
		group.appendChild(element('h4', {}, section.type === 'people' ? 'People photos' : section.type === 'location' ? 'Location photos' : 'Images'));
		var grid = element('div', {className: 'nova-service-image-grid'});
		images.forEach(function (image, index) {
			var card = element('div', {className: 'nova-service-image-card'});
			if (image.url) { card.appendChild(element('img', {src: image.url, alt: image.alt || '', loading: 'lazy'})); }
			field(card, 'Image URL', image.url, function (value) { image.url = value; }, {type: 'url'});
			field(card, 'Alternative text', image.alt, function (value) { image.alt = value; }, {help: 'Describe what the image shows. Leave empty for a decorative image.'});
			field(card, 'Caption', image.caption, function (value) { image.caption = value; });
			var controls = element('div', {className: 'nova-service-row-actions'});
			if (index > 0) { controls.appendChild(button('Move image left', function () { removeEditors(); images.splice(index - 1, 0, images.splice(index, 1)[0]); changed(); render(); })); }
			controls.appendChild(button('Remove image', function () { removeEditors(); images.splice(index, 1); changed(); render(); }, 'button-link-delete'));
			card.appendChild(controls); grid.appendChild(card);
		});
		group.appendChild(grid);
		group.appendChild(button('Select images from media library', function () {
			if (images.length >= 20) { status('Each section supports up to 20 images. Add another section for more images.', true); return; }
			var frame = window.wp.media({title: 'Select service page images', button: {text: 'Add selected images'}, multiple: true, library: {type: 'image'}});
			frame.on('select', function () {
				removeEditors();
				frame.state().get('selection').toJSON().slice(0, 20 - images.length).forEach(function (attachment) {
					images.push({id: attachment.id, url: attachment.url, alt: attachment.alt || '', caption: attachment.caption || ''});
				});
				changed(); render();
			});
			frame.open();
		}));
		group.appendChild(button('Add image by URL', function () { if (images.length >= 20) { status('Each section supports up to 20 images.', true); return; } removeEditors(); images.push({id: 0, url: '', alt: '', caption: ''}); changed(); render(); }));
		container.appendChild(group);
	}
	function renderButtons(container, section) {
		var items = collection(section, 'buttons');
		var group = element('div', {className: 'nova-service-group'});
		group.appendChild(element('h4', {}, 'Call to action buttons'));
		items.forEach(function (item, index) {
			var row = element('div', {className: 'nova-service-repeat-row'});
			field(row, 'Button label', item.label, function (value) { item.label = value; });
			field(row, 'Destination URL', item.url, function (value) { item.url = value; }, {placeholder: 'https://…', help: 'Use an HTTP(S) URL, a /relative path, #anchor, tel: link or mailto: link.'});
			row.appendChild(button('Remove button', function () { removeEditors(); items.splice(index, 1); changed(); render(); }, 'button-link-delete'));
			group.appendChild(row);
		});
		group.appendChild(button('Add button', function () { if (items.length >= 10) { status('Each section supports up to 10 buttons.', true); return; } removeEditors(); items.push({label: '', url: ''}); changed(); render(); }));
		container.appendChild(group);
	}
	function plainText(html) { var node = document.createElement('div'); node.innerHTML = html || ''; return (node.textContent || '').replace(/\s+/g, ' ').trim(); }
	function reviewBody(text) { var node = document.createElement('div'); node.textContent = text || ''; return '<p>' + node.innerHTML.replace(/\r?\n/g, '<br>') + '</p>'; }
	function reviewKey(item) { return [item.author || '', plainText(item.body), item.rating || 0].join('\n'); }
	function parseReviewCsv(text) {
		text = String(text || '').replace(/^\uFEFF/, '').replace(/\r\n?/g, '\n');
		var rows = [], row = [], cell = '', quoted = false, closed = false;
		for (var i = 0; i < text.length; i++) {
			var character = text[i];
			if (quoted) {
				if (character === '"' && text[i + 1] === '"') { cell += '"'; i++; }
				else if (character === '"') { quoted = false; closed = true; }
				else { cell += character; }
			} else if (character === ',' || character === '\n') {
				row.push(cell); cell = ''; closed = false;
				if (character === '\n') { rows.push(row); row = []; }
			} else if (character === '"') {
				if (cell.trim() || closed) { throw new Error('CSV contains an unexpected quote. Put text containing commas or quotes inside double quotes.'); }
				cell = ''; quoted = true;
			} else if (closed) {
				if (!/\s/.test(character)) { throw new Error('CSV contains text after a closing quote. Separate columns with commas.'); }
			} else { cell += character; }
		}
		if (quoted) { throw new Error('CSV contains an unfinished quoted field.'); }
		if (cell || row.length) { row.push(cell); rows.push(row); }
		rows = rows.filter(function (values) { return values.some(function (value) { return value.trim(); }); });
		if (rows.length && rows[0].map(function (value) { return value.trim().toLowerCase(); }).join(',') === 'author,rating,body,url') { rows.shift(); }
		if (!rows.length) { throw new Error('Paste at least one review using author,rating,body,url columns.'); }
		if (rows.length > 80) { throw new Error('A section supports up to 80 reviews. Split larger imports across sections.'); }
		return rows.map(function (values, index) {
			if (values.length !== 4) { throw new Error('Review ' + (index + 1) + ' needs four columns: author,rating,body,url. Use double quotes around text containing commas.'); }
			var review = {author: values[0].trim(), body: values[2].trim(), url: values[3].trim()};
			if (!review.author || !review.body) { throw new Error('Review ' + (index + 1) + ' needs an author and review text.'); }
			if (values[1].trim()) { var rating = Number(values[1]); if (!Number.isFinite(rating) || rating < 0 || rating > 5) { throw new Error('Review ' + (index + 1) + ' rating must be a number from 0 to 5, or empty.'); } review.rating = rating; }
			if (review.url) { var parsed; try { parsed = new URL(review.url); } catch (error) { throw new Error('Review ' + (index + 1) + ' needs a complete HTTP(S) source URL, or an empty URL.'); } if (['http:', 'https:'].indexOf(parsed.protocol) < 0 || parsed.username || parsed.password) { throw new Error('Review ' + (index + 1) + ' source URL must use HTTP(S) without credentials.'); } }
			return review;
		});
	}
	function renderBulkReviews(container, section, items) {
		var state = bulkReviews[section.id] || (bulkReviews[section.id] = {text: '', source: '', reviews: null, selected: [], error: ''});
		var group = element('details', {className: 'nova-service-bulk-reviews'});
		group.appendChild(element('summary', {}, 'Paste several verified reviews'));
		group.open = Boolean(state.reviews || state.error);
		group.appendChild(element('p', {className: 'description'}, 'Paste CSV with four columns: author,rating,body,url. Rating (0–5) and source URL may be empty. Put text with commas or line breaks inside double quotes; escape a double quote as "". This adds manual cards without changing the provider’s overall score.'));
		var csvLabel = element('label', {className: 'nova-service-field'}); csvLabel.appendChild(element('span', {className: 'nova-service-field-label'}, 'Review CSV'));
		var csv = element('textarea', {rows: 5, 'data-review-preview': '1', placeholder: 'author,rating,body,url\nAlex,5,"Helpful team, clear advice.",https://example.com/review\nSam,,"A verified customer testimonial.",'}); csv.value = state.text;
		csv.addEventListener('input', function () { state.text = csv.value; }); csvLabel.appendChild(csv); group.appendChild(csvLabel);
		var sourceLabel = element('label', {className: 'nova-service-field'}); sourceLabel.appendChild(element('span', {className: 'nova-service-field-label'}, 'Source label for these cards (optional)'));
		var source = element('input', {type: 'text', 'data-review-preview': '1', placeholder: 'Customer testimonial or review platform'}); source.value = state.source; source.addEventListener('input', function () { state.source = source.value; }); sourceLabel.appendChild(source); group.appendChild(sourceLabel);
		group.appendChild(button('Preview pasted reviews', function () { state.error = ''; try { state.reviews = parseReviewCsv(state.text); state.selected = state.reviews.map(function (review, index) { return index; }); } catch (error) { state.reviews = null; state.error = error.message; } removeEditors(); render(); }));
		if (state.error) { group.appendChild(element('p', {className: 'notice notice-error inline nova-service-import-status', role: 'alert'}, state.error)); }
		if (state.reviews) {
			var candidates = element('div', {className: 'nova-service-review-candidates'});
			state.reviews.forEach(function (review, index) {
				var label = element('label', {className: 'nova-service-review-candidate'}), checkbox = element('input', {type: 'checkbox', 'data-review-preview': '1'}); checkbox.checked = state.selected.indexOf(index) >= 0;
				checkbox.addEventListener('change', function () { if (checkbox.checked) { state.selected.push(index); } else { state.selected = state.selected.filter(function (value) { return value !== index; }); } });
				label.appendChild(checkbox); var copy = element('div'); copy.appendChild(element('strong', {}, review.author + (typeof review.rating === 'number' ? ' — ' + review.rating + '/5' : ''))); copy.appendChild(element('p', {}, review.body)); if (review.url) { copy.appendChild(element('small', {className: 'description'}, review.url)); } label.appendChild(copy); candidates.appendChild(label);
			}); group.appendChild(candidates);
			group.appendChild(button('Add selected pasted reviews', function () {
				if (!state.selected.length) { status('Select at least one pasted review to add.', true); return; }
				removeEditors(); var additions = [], keys = items.map(reviewKey);
				state.reviews.forEach(function (review, index) { if (state.selected.indexOf(index) < 0) { return; } var item = Object.assign({}, review, {body: reviewBody(review.body)}); if (state.source.trim()) { item.source = state.source.trim(); } var key = reviewKey(item); if (keys.indexOf(key) < 0) { additions.push(item); keys.push(key); } });
				if (items.length + additions.length > 80) { status('This section supports up to 80 review cards. Remove older cards or select fewer reviews.', true); render(); return; }
				section.data.items = items.concat(additions); state.reviews = null; state.error = ''; changed(); render(); status(additions.length + ' verified review cards added. Save the page or shared library to publish them.');
			}));
		}
		container.appendChild(group);
	}
	function renderReviewImport(container, section) {
		var shared = Boolean(section.shared_key);
		var sourceEditable = !shared || hasOwn(section.data, 'review_source');
		var currentSource = effectiveData(section).review_source || {};
		var state = reviewImports[section.id] || (reviewImports[section.id] = {url: currentSource.url || '', result: null, fallback: null, selected: [], loading: false, error: ''});
		var group = element('div', {className: 'nova-service-group nova-service-review-import'});
		group.appendChild(element('h4', {}, 'Import reviews from a public profile'));
		overrideToggle(group, section, 'review_source', 'Review source', {});
		if (shared && !sourceEditable) { group.appendChild(element('p', {className: 'description'}, 'Check Override review source or copy this shared section into local fields before importing for this page. Other provider snapshots also need Override items; Google keeps manual review items unchanged.')); }
		var label = element('label', {className: 'nova-service-field'});
		label.appendChild(element('span', {className: 'nova-service-field-label'}, 'Public review profile URL'));
		var url = element('input', {type: 'text', placeholder: 'https://…', 'data-review-preview': '1'}); url.value = state.url; url.disabled = !sourceEditable;
		url.addEventListener('input', function () { state.url = url.value; });
		label.appendChild(url); group.appendChild(label);
		group.appendChild(element('p', {className: 'description'}, 'Import an available provider profile, then select the reviews to show. The overall score comes from the source; it is never calculated from selected reviews. Retrieval and selection do not change your saved page until you apply and save.'));
		var importButton = button(state.loading ? 'Fetching reviews…' : 'Import / refresh profile', function () {
			if (!state.url.trim()) { state.error = 'Enter a public review profile URL.'; removeEditors(); render(); return; }
			state.loading = true; state.error = ''; state.result = null; state.fallback = null; removeEditors(); render();
			request(config.importerUrl, {method: 'POST', body: JSON.stringify({url: state.url.trim()})}).then(function (result) {
				state.result = result; state.url = result.url || state.url; state.selected = [];
				if (result.provider === 'google' && Array.isArray(currentSource.selected_review_ids)) { state.selected = (result.reviews || []).filter(function (review) { return currentSource.selected_review_ids.indexOf(review.id) >= 0; }).map(function (review) { return review.id; }); }
			}).catch(function (error) { state.error = error.message; state.fallback = error.data && error.data.fallback; }).finally(function () { state.loading = false; removeEditors(); render(); });
		});
		importButton.disabled = !sourceEditable || state.loading; group.appendChild(importButton);
		if (state.error) { group.appendChild(element('p', {className: 'notice notice-error inline nova-service-import-status', role: 'alert'}, state.error)); }
		if (state.fallback && state.fallback.provider === 'google') {
			var fallback = state.fallback;
			group.appendChild(element('p', {className: 'description'}, 'Google does not expose selectable reviews for this profile. Add a compact link to the original Google reviews and enter verified review cards manually. No map or invented overall score is added.'));
			var profileButton = button('Add Google profile link', function () {
				if (!sourceEditable) { status('Override the review source before adding this profile.', true); return; }
				removeEditors(); section.data.review_source = {provider: 'google', url: fallback.url, display: 'profile'};
				if (fallback.embed_url) { section.data.review_source.embed_url = fallback.embed_url; }
				state.result = null; state.fallback = null; changed(); render(); status('Google profile link added. Save the page or shared library to publish this change. Add verified review cards below.');
			}); profileButton.disabled = !sourceEditable; group.appendChild(profileButton);
		}
		if (currentSource.provider) {
			var summary = 'Saved source: ' + currentSource.provider;
			if (currentSource.business_name) { summary += ' — ' + currentSource.business_name; }
			if (typeof currentSource.average_rating === 'number') { summary += (currentSource.aggregate_scope === 'combined' ? ' · combined overall ' : ' · provider overall ') + currentSource.average_rating + '/' + (currentSource.rating_scale || 5); }
			if (typeof currentSource.review_count === 'number') { summary += ' · ' + currentSource.review_count + ' reviews'; }
			if (currentSource.provider === 'google') { summary += currentSource.display === 'widget' && currentSource.embed_url ? ' · official Google map widget' : ' · compact Google source link'; }
			group.appendChild(element('p', {className: 'nova-service-shared-note'}, summary));
			if (currentSource.provider === 'google' && currentSource.embed_url) {
				field(group, 'Google source display', currentSource.display || 'profile', function (value) {
					section.data.review_source = Object.assign({}, currentSource, {display: value});
				}, {choices: {profile: 'Compact Google profile link', widget: 'Official map widget (optional)'}, disabled: !sourceEditable, help: 'The compact link is the default for reviews. Choose the map only when it helps visitors locate the business; it does not import individual reviews.'});
			}
			if (sourceEditable) { group.appendChild(button('Clear imported source and overall score', function () { removeEditors(); section.data.review_source = {}; state.result = null; state.fallback = null; state.url = ''; changed(); render(); }, 'button-link-delete')); }
		}
		if (state.result) {
			var result = state.result;
			var panel = element('div', {className: 'nova-service-review-result'});
			panel.appendChild(element('h4', {}, result.business_name || 'Profile review preview'));
			var aggregate = typeof result.average_rating === 'number' ? (result.aggregate_scope === 'combined' ? 'Published combined overall rating: ' : 'Provider overall rating: ') + result.average_rating + '/' + (result.rating_scale || 5) : 'Source overall rating unavailable';
			if (typeof result.review_count === 'number') { aggregate += ' · ' + result.review_count + ' reviews'; }
			panel.appendChild(element('p', {className: 'nova-service-source-average'}, aggregate));
			(result.warnings || []).forEach(function (warning) { panel.appendChild(element('p', {className: 'description'}, warning)); });
			if (result.provider === 'google') { panel.appendChild(element('p', {className: 'description'}, 'Only verified reviews available in the public profile can be selected; selection references refresh live. Review text, business name and aggregate values shown here are a temporary preview and are not copied into the service document.')); }
			else { panel.appendChild(element('p', {className: 'description'}, 'Selected reviews are added to your existing cards. Matching text, author and rating are deduplicated. Remove older cards you no longer need.')); }
			var candidates = element('div', {className: 'nova-service-review-candidates'});
			(result.reviews || []).forEach(function (review) {
				var card = element('label', {className: 'nova-service-review-candidate'});
				var checkbox = element('input', {type: 'checkbox', 'data-review-preview': '1'}); checkbox.checked = state.selected.indexOf(review.id) >= 0;
				checkbox.addEventListener('change', function () { if (checkbox.checked) { state.selected.push(review.id); } else { state.selected = state.selected.filter(function (id) { return id !== review.id; }); } });
				card.appendChild(checkbox);
				var text = element('div'); text.appendChild(element('strong', {}, review.author || 'Unattributed review'));
				if (typeof review.rating === 'number') { text.appendChild(element('span', {className: 'nova-service-candidate-rating'}, ' ' + (typeof review.original_rating === 'number' ? review.original_rating : review.rating) + '/' + (review.original_scale || 5))); }
				if (review.date) { text.appendChild(element('small', {className: 'description'}, review.date)); }
				text.appendChild(element('p', {}, review.body || '')); card.appendChild(text); candidates.appendChild(card);
			});
			panel.appendChild(candidates);
			if (!(result.reviews || []).length) { panel.appendChild(element('p', {}, 'No selectable public reviews were returned. Check the provider warning, use its approved embed, or add verified reviews manually.')); }
			var aggregateOnly = !(result.reviews || []).length && typeof result.average_rating === 'number';
			var apply = button(aggregateOnly ? 'Apply source overall score' : 'Apply selected reviews', function () {
				if (!sourceEditable) { status('Override the review source before applying this import.', true); return; }
				var selected = (result.reviews || []).filter(function (review) { return state.selected.indexOf(review.id) >= 0; });
				if (!selected.length && !aggregateOnly) { status('Select at least one review to apply.', true); return; }
				if (selected.length && result.provider !== 'google' && shared && !hasOwn(section.data, 'items')) { status('Check Override items before adding imported reviews to this linked section.', true); return; }
				removeEditors();
				if (result.provider === 'google') {
					section.data.review_source = {provider: 'google', url: result.url, display: 'profile', selected_review_ids: selected.map(function (review) { return review.id; })};
					if (result.google_place_id) { section.data.review_source.google_place_id = result.google_place_id; }
				} else {
					var items = selected.length ? collection(section, 'items') : (section.data.items || []); var keys = items.map(reviewKey); var additions = [];
					selected.forEach(function (review) {
						var item = {author: review.author || '', body: reviewBody(review.body), source: result.provider, url: review.url || result.url};
						if (typeof review.rating === 'number') { item.rating = review.rating; }
						if (typeof review.original_rating === 'number') { item.original_rating = review.original_rating; }
						if (typeof review.original_scale === 'number') { item.original_scale = review.original_scale; }
						if (review.date) { item.date = review.date; }
						var key = reviewKey(item); if (keys.indexOf(key) < 0) { additions.push(item); keys.push(key); }
					});
					if (items.length + additions.length > 80) { status('This section supports up to 80 review cards. Remove older cards before applying these reviews.', true); render(); return; }
					if (selected.length) { section.data.items = items.concat(additions); }
					var source = {provider: result.provider, url: result.url, business_name: result.business_name || ''};
					if (typeof result.average_rating === 'number') { source.average_rating = result.average_rating; source.rating_scale = result.rating_scale || 5; }
					if (result.aggregate_scope === 'provider' || result.aggregate_scope === 'combined') { source.aggregate_scope = result.aggregate_scope; }
					if (typeof result.review_count === 'number') { source.review_count = result.review_count; }
					if (result.fetched_at) { source.imported_at = result.fetched_at; }
					section.data.review_source = source;
				}
				changed(); render(); status(aggregateOnly ? 'Source overall score applied. Save the page or shared library to publish this change.' : 'Selected reviews applied. Save the page or shared library to publish this change.');
			});
			apply.disabled = !sourceEditable || (!(result.reviews || []).length && !aggregateOnly); panel.appendChild(apply); group.appendChild(panel);
		}
		container.appendChild(group);
	}
	function renderLogoControl(container, item, key, options) {
		var group = element('div', {className: 'nova-service-logo-control'});
		var preview = element('div', {className: 'nova-service-logo-preview'});
		var image = element('img', {alt: options.previewAlt, loading: 'lazy', decoding: 'async'});
		preview.appendChild(image);
		function updatePreview(value) {
			var valid = false;
			if (typeof value === 'string' && ! /[\s\\<>"'`]/.test(value)) {
				try {
					var relative = /^\/(?!\/)/.test(value), parsed = new URL(value, window.location.href);
					valid = !parsed.username && !parsed.password && (relative ? parsed.origin === window.location.origin : /^https:\/\//i.test(value) && parsed.protocol === 'https:');
				} catch (error) { valid = false; }
			}
			preview.hidden = !valid;
			if (valid) { image.src = value; }
			else { image.removeAttribute('src'); }
		}
		field(group, options.label, item[key], function (value) { item[key] = value; updatePreview(value); }, {placeholder: options.placeholder || 'https://… or /wp-content/uploads/…', help: options.help, disabled: options.disabled});
		updatePreview(item[key]); group.appendChild(preview);
		var actions = element('div', {className: 'nova-service-row-actions'});
		var choose = button(options.buttonLabel, function () {
			var frame = window.wp.media({title: options.mediaTitle, button: {text: 'Use image'}, multiple: false, library: {type: 'image'}});
			frame.on('select', function () { removeEditors(); item[key] = frame.state().get('selection').first().toJSON().url; changed(); render(); }); frame.open();
		}); choose.disabled = Boolean(options.disabled); actions.appendChild(choose);
		if (item[key]) { var remove = button('Remove image', function () { removeEditors(); item[key] = ''; changed(); render(); }, 'button-link-delete'); remove.disabled = Boolean(options.disabled); actions.appendChild(remove); }
		group.appendChild(actions); container.appendChild(group);
	}
	function renderDataLogoControl(container, section, key, options) {
		var inherited = section.shared_key && !hasOwn(section.data, key), preset = presetFor(section);
		overrideToggle(container, section, key, options.label, '');
		var displayed = inherited ? (preset && preset.data || {}) : section.data;
		renderLogoControl(container, displayed, key, Object.assign({}, options, {disabled: Boolean(inherited)}));
	}
	function renderItems(container, section) {
		var type = section.type;
		var items = collection(section, 'items');
		var group = element('div', {className: 'nova-service-group'});
		var singular = {faq: 'FAQ', reviews: 'review', credentials: 'award or certification', related: 'link card'}[type];
		group.appendChild(element('h4', {}, {faq: 'Questions and answers', reviews: 'Reviews and testimonials', credentials: 'Awards and certifications', related: 'Custom link cards'}[type]));
		if (type === 'reviews') { group.appendChild(element('p', {className: 'description'}, 'Add real customer reviews or testimonials. Source URLs are optional; publisher names and logos can appear without links. A source can be a review platform, a client website or a customer testimonial. A recognized source URL identifies a publisher badge, with a provider logo when available.')); renderBulkReviews(group, section, items); }
		items.forEach(function (item, index) {
			var row = element('div', {className: 'nova-service-repeat-row nova-service-item'});
			row.appendChild(element('h4', {}, singular.charAt(0).toUpperCase() + singular.slice(1) + ' ' + (index + 1)));
			if (type === 'faq') {
				field(row, 'Question', item.question, function (value) { item.question = value; });
				field(row, 'Answer', item.answer, function (value) { item.answer = value; }, {rich: true});
			} else {
				field(row, type === 'reviews' ? 'Review title (optional)' : 'Title', item.title, function (value) { item.title = value; });
				field(row, type === 'reviews' ? 'Review or testimonial' : 'Description', item.body, function (value) { item.body = value; }, {rich: true});
				if (type === 'reviews') {
					field(row, 'Reviewer name', item.author, function (value) { item.author = value; });
					field(row, 'Source or publisher', item.source, function (value) { item.source = value; }, {placeholder: 'Client website, customer testimonial or review platform', help: 'Shown on the review card even without a source URL. Add an original URL below only if you want to include one.'});
					field(row, 'Rating (optional, 0–5)', item.rating || '', function (value) { item.rating = value === '' ? 0 : Number(value); delete item.original_rating; delete item.original_scale; }, {type: 'number', min: 0, max: 5, step: 0.1, help: typeof item.original_rating === 'number' ? 'Imported source rating: ' + item.original_rating + '/' + (item.original_scale || 5) + '. This editing value is normalized to 0–5; changing it replaces the imported rating.' : ''});
					renderLogoControl(row, item, 'source_logo', {label: 'Source or publisher logo override (optional)', buttonLabel: 'Choose source logo', mediaTitle: 'Choose review publisher logo', previewAlt: 'Review publisher logo preview', help: 'Leave empty to follow the section’s publisher logo or identified source badge. Upload another publisher’s logo only when this card needs a different source image.'});
				}
				if (type === 'credentials') {
					field(row, 'Issuer or organization (optional)', item.issuer, function (value) { item.issuer = value; }, {help: 'Name the organization behind this membership, qualification or award.'});
					field(row, 'Evidence link label (optional)', item.source, function (value) { item.source = value; }, {help: 'Optional wording for the verification link, such as View certificate or Read more.'});
				}
				field(row, type === 'reviews' ? 'Original review or testimonial URL (optional)' : type === 'credentials' ? 'Evidence or verification URL (optional)' : 'Link URL (optional)', item.url, function (value) { item.url = value; }, {type: 'url'});
				if (type === 'credentials') {
					renderLogoControl(row, item, 'image', {label: 'Issuer logo, badge or certificate image (optional)', buttonLabel: 'Choose logo or badge', mediaTitle: 'Choose issuer logo, badge or certificate', previewAlt: 'Credential image preview', placeholder: 'https://example.com/logo.png', help: 'Choose an image or enter its complete HTTP(S) URL. Use the issuer’s official logo, an earned badge or the actual certificate. Add an evidence URL when available; the section’s source-link setting controls click-through.'});
				} else if (type !== 'reviews') {
					field(row, 'Image URL (optional)', item.image, function (value) { item.image = value; }, {type: 'url'});
					row.appendChild(button('Choose image', function () {
						var frame = window.wp.media({title: 'Choose card image', button: {text: 'Use image'}, multiple: false, library: {type: 'image'}});
						frame.on('select', function () { removeEditors(); item.image = frame.state().get('selection').first().toJSON().url; changed(); render(); }); frame.open();
					}));
				}
			}
			var actions = element('div', {className: 'nova-service-row-actions'});
			if (index > 0) { actions.appendChild(button('Move up', function () { removeEditors(); items.splice(index - 1, 0, items.splice(index, 1)[0]); changed(); render(); })); }
			actions.appendChild(button('Remove ' + singular, function () { removeEditors(); items.splice(index, 1); changed(); render(); }, 'button-link-delete'));
			row.appendChild(actions); group.appendChild(row);
		});
		group.appendChild(button('Add ' + singular, function () { if (items.length >= 80) { status('Each section supports up to 80 items.', true); return; } removeEditors(); items.push({}); changed(); render(); }));
		container.appendChild(group);
	}
	function titleText(post) {
		var node = document.createElement('div'); node.innerHTML = post.title.rendered;
		return node.textContent || 'Service page #' + post.id;
	}
	function renderRelated(container, section) {
		var ids = section.data.post_ids = Array.isArray(section.data.post_ids) ? section.data.post_ids : [];
		var group = element('div', {className: 'nova-service-group'});
		group.appendChild(element('h4', {}, 'Specific service links (optional)'));
		group.appendChild(element('p', {className: 'description'}, 'Related services are selected automatically when this section has no specific service selections or custom cards. Choose links only when this page needs a different set. Clear both selections and custom cards to resume automatic suggestions.'));
		var selected = element('ul', {className: 'nova-service-related-selected'});
		ids.forEach(function (id, index) {
			var row = element('li');
			row.appendChild(element('span', {'data-related-title': id}, relatedTitles[id] || 'Service page #' + id));
			row.appendChild(button('Remove', function () { removeEditors(); ids.splice(index, 1); changed(); render(); }, 'button-link-delete'));
			selected.appendChild(row);
		});
		group.appendChild(selected);
		var results = element('div', {className: 'nova-service-related-results', 'aria-live': 'polite'});
		var timer;
		var generation = 0;
		field(group, 'Search service pages', '', function (term) {
			window.clearTimeout(timer);
			var current = ++generation;
			if (term.trim().length < 2) { results.textContent = ''; return; }
			timer = window.setTimeout(function () {
				results.textContent = 'Searching…';
				request(config.servicesUrl + '?per_page=20&status=publish&search=' + encodeURIComponent(term) + '&_fields=id,title').then(function (posts) {
					if (current !== generation) { return; }
					posts = Array.isArray(posts) ? posts : [];
					results.textContent = '';
					posts.filter(function (post) { return post.id !== Number(config.postId) && ids.indexOf(post.id) < 0; }).forEach(function (post) {
						relatedTitles[post.id] = titleText(post);
						results.appendChild(button('Add: ' + relatedTitles[post.id], function () { if (ids.length >= 100) { status('Each section supports up to 100 related services.', true); return; } removeEditors(); ids.push(post.id); changed(); render(); }));
					});
					if (!results.children.length) { results.textContent = 'No matching service pages found.'; }
				}).catch(function (error) { results.textContent = error.message; });
			}, 300);
		}, {help: 'Optional: choose published service pages to replace the automatic suggestions. Their title, image and URL stay in sync.'});
		group.appendChild(results); container.appendChild(group);
		if (ids.length) {
			request(config.servicesUrl + '?include=' + ids.join(',') + '&per_page=100&_fields=id,title').then(function (posts) {
				posts = Array.isArray(posts) ? posts : [];
				posts.forEach(function (post) {
					relatedTitles[post.id] = titleText(post);
					root.querySelectorAll('[data-related-title="' + post.id + '"]').forEach(function (span) { span.textContent = relatedTitles[post.id]; });
				});
			}).catch(function () {});
		}
	}
	function renderSection(section, index, displayIndex) {
		if (!section.data || Array.isArray(section.data)) { section.data = {}; }
		var info = registry[section.type] || {label: section.type, description: ''};
		var details = element('details', {className: 'nova-service-section' + (section.enabled === false ? ' is-disabled' : ''), 'data-section-id': section.id});
		details.open = expanded[section.id] === undefined ? (displayIndex === undefined ? index : displayIndex) === 0 : expanded[section.id];
		var summary = element('summary', {className: 'nova-service-section-header'});
		var contextualOrder = libraryMode && libraryView === 'page' && Boolean(selectedPreviewTarget());
		summary.appendChild(element('span', {className: 'nova-service-drag dashicons dashicons-menu' + (contextualOrder ? ' is-contextual' : ''), title: contextualOrder ? 'Order follows the selected service page' : 'Drag to reorder', 'aria-hidden': 'true'}));
		var displayTitle = effectiveData(section).title;
		summary.appendChild(element('span', {className: 'nova-service-section-name'}, sectionLabel(section) + (libraryMode ? ' [' + section.id + ']' : '') + (displayTitle ? ': ' + displayTitle : '')));
		if (libraryMode && selectedPreviewTarget()) {
			var usedHere = previewTargetSections().some(function (reference) { return reference.shared_key === section.id && reference.type === section.type; });
			summary.appendChild(element('span', {className: 'nova-service-usage-badge' + (usedHere ? ' is-used' : '')}, usedHere ? 'On this page' : 'Not on this page'));
		}
		var enabled = element('label', {className: 'nova-service-enabled'});
		var checkbox = element('input', {type: 'checkbox'}); checkbox.checked = section.enabled !== false;
		checkbox.addEventListener('click', function (event) { event.stopPropagation(); });
		checkbox.addEventListener('change', function () { section.enabled = checkbox.checked; details.classList.toggle('is-disabled', !checkbox.checked); changed(); });
		enabled.addEventListener('click', function (event) { event.stopPropagation(); });
		enabled.appendChild(checkbox); enabled.appendChild(document.createTextNode(libraryMode ? ' Show on linked pages' : ' Show section')); summary.appendChild(enabled);
		details.appendChild(summary);
		var content = element('div', {className: 'nova-service-section-content'});
		content.appendChild(element('p', {className: 'description'}, section.type === 'rich_text' ? 'Flexible rich text with your own heading, images and formatting. Use the content this service needs.' : (info.description || '')));
		if (libraryMode) { content.appendChild(element('p', {className: 'nova-service-shared-note'}, librarySectionContext(section))); }
		var actions = element('div', {className: 'nova-service-section-actions'});
		var up = button('Move up', function () { moveSection(index, -1); }); up.disabled = index === 0;
		var down = button('Move down', function () { moveSection(index, 1); }); down.disabled = index === doc.sections.length - 1;
		if (!contextualOrder) { actions.appendChild(up); actions.appendChild(down); }
		actions.appendChild(button('Duplicate', function () { if (doc.sections.length >= 60) { status('A service page supports up to 60 sections.', true); return; } removeEditors(); var copy = clone(section); copy.id = uniqueId(); doc.sections.splice(index + 1, 0, copy); expanded[copy.id] = true; changed(); render(); }));
		actions.appendChild(button('Remove section', function () {
			if (!window.confirm('Remove this section and its content? This takes effect when you save the page.')) { return; }
			removeEditors(); doc.sections.splice(index, 1); changed(); render();
		}, 'button-link-delete'));
		content.appendChild(actions);
		if (libraryMode) {
			field(content, 'Shared section key', section.id, function (value) { expanded[value] = details.open; section.id = value; details.setAttribute('data-section-id', value); }, {pattern: '[a-zA-Z0-9_-]{1,80}', maxLength: 80, help: 'A unique key, for example people-amsterdam or awards-standard. Keep it stable after service pages reference it.'});
		}
		renderSharedSelector(content, section);
		dataField(content, section, 'width', 'Row width', {choices: {full: 'Full row', half: 'Half row — two columns'}, help: 'Place two half-row sections next to each other to share a row. Each section becomes full width on mobile. Shared sections can supply this setting; use an override only when needed.'});
		dataField(content, section, 'eyebrow', 'Eyebrow / small label (optional)');
		dataField(content, section, 'title', section.type === 'hero' ? 'Display title (leave empty to use the page title)' : 'Section heading');
		if (section.type !== 'hero') { dataField(content, section, 'heading_level', 'Heading level', {choices: {h2: 'H2 — main section', h3: 'H3 — supporting section'}}); }
		var appearances = {default: 'Site default'};
		if (['hero', 'rich_text', 'people', 'location', 'cta'].indexOf(section.type) >= 0) {
			appearances.split = 'Text and media side by side'; appearances.stacked = 'Stacked';
		}
		if (section.type === 'reviews') {
			appearances.editorial = 'Editorial'; appearances.conversation = 'Conversation'; appearances.spotlight = 'Spotlight';
			appearances.publisher_cards = 'Publisher cards'; appearances.compact_rail = 'Compact rail'; appearances.signature = 'Signature';
			appearances.cards = 'Review cards'; appearances.list = 'Review list';
		}
		if (section.type === 'credentials') {
			appearances.logo_rail = 'Logo rail'; appearances.featured_membership = 'Featured membership'; appearances.editorial = 'Editorial';
			appearances.credential_tiles = 'Credential tiles'; appearances.trust_band = 'Trust band'; appearances.evidence_accordion = 'Evidence accordion';
			appearances.badges = 'Logo and badge cards'; appearances.logos = 'Logo strip'; appearances.cards = 'Credential cards'; appearances.list = 'Compact credential list';
		}
		if (section.type === 'related') { appearances.cards = 'Service cards'; appearances.list = 'Compact service list'; }
		appearances.compact = 'Compact';
		dataField(content, section, 'layout', 'Section appearance', {choices: appearances, help: 'Site fonts, colors and shared styling form the baseline. Choose a layout variant only when this section needs a different presentation; linked sections can inherit their library appearance.'});
		if (richTypes.indexOf(section.type) >= 0) { dataField(content, section, 'body', section.type === 'hero' ? 'Introductory copy' : 'Rich text', {rich: true}); }
		if (imageTypes.indexOf(section.type) >= 0 || section.type === 'rich_text') { collectionGroup(content, section, 'images', 'Images', renderImages); }
		if (buttonTypes.indexOf(section.type) >= 0) { collectionGroup(content, section, 'buttons', 'Buttons', renderButtons); }
		if (section.type === 'location' || section.type === 'map') { dataField(content, section, 'address', 'Location address', {multiline: true, rows: 2}); }
		if (section.type === 'map') {
			dataField(content, section, 'map_url', 'Google Maps embed URL', {type: 'url', help: 'In Google Maps choose Share → Embed a map, then paste only the iframe src URL (https://www.google.com/maps/embed?…).'});
		}
		if (section.type === 'reviews') {
			dataCheckbox(content, section, 'link_sources', 'Link to review sources', true, 'Source URLs are optional. Turn this off to show publisher labels and logos without clickable source links. Linked sections follow Client defaults unless you override this setting.');
			renderDataLogoControl(content, section, 'source_logo', {label: 'Publisher / source logo', buttonLabel: 'Choose publisher logo', mediaTitle: 'Choose default review publisher logo', previewAlt: 'Default review publisher logo preview', help: 'Default logo for this section’s reviews or website testimonials. Individual cards can override it. Identified review platforms use a source badge and a provider logo when available.'});
			renderReviewImport(content, section);
		}
		if (section.type === 'credentials') {
			dataCheckbox(content, section, 'link_sources', 'Link to credential sources', true, 'Evidence URLs are optional. Turn this off to keep issuer images and credential text visible without click-through. Linked sections follow Client defaults unless you override this setting.');
		}
		if (['reviews', 'credentials', 'faq', 'related'].indexOf(section.type) >= 0) { collectionGroup(content, section, 'items', 'Items', renderItems); }
		if (section.type === 'related') { collectionGroup(content, section, 'post_ids', 'Specific service links', renderRelated); }
		if (section.type === 'video') {
			dataField(content, section, 'body', 'Video introduction (optional)', {rich: true});
			dataField(content, section, 'video_url', 'Video URL', {type: 'url', help: 'Use an HTTPS MP4/WebM file from this site’s uploads, or a YouTube/Vimeo URL. Unsupported providers are rejected on save.'});
			dataField(content, section, 'poster', 'Poster image URL (optional)', {type: 'url'});
			content.appendChild(button('Choose video file', function () {
				var frame = window.wp.media({title: 'Choose service video', button: {text: 'Use video'}, multiple: false, library: {type: 'video'}});
				frame.on('select', function () { removeEditors(); section.data.video_url = frame.state().get('selection').first().toJSON().url; changed(); render(); }); frame.open();
			}));
			content.appendChild(button('Choose poster image', function () {
				var frame = window.wp.media({title: 'Choose video poster', button: {text: 'Use image'}, multiple: false, library: {type: 'image'}});
				frame.on('select', function () { removeEditors(); section.data.poster = frame.state().get('selection').first().toJSON().url; changed(); render(); }); frame.open();
			}));
		}
		if (section.type === 'custom_cta') {
			content.appendChild(element('p', {className: 'description'}, 'Use a form, booking calendar or another call to action from a plugin already installed on this site. Common providers include Contact Form 7, WPForms, Gravity Forms, Fluent Forms, Amelia and booking plugins. Use the shortcode supplied by that plugin; the provider must remain active.'));
			dataField(content, section, 'shortcode', 'Plugin shortcode', {multiline: true, rows: 2, placeholder: '[contact-form-7 id="123" title="Request a quote"]', help: 'Only trusted shortcode providers are allowed. Unsupported shortcodes are omitted; your site administrator can extend the allowlist.'});
			dataField(content, section, 'html', 'Custom HTML', {multiline: true, rows: 7, help: 'Add trusted HTML for a form or calendar. Unsafe code is removed on save. Use the provider’s shortcode when its embed needs JavaScript. Do not place secret keys in this field.'});
		}
		details.appendChild(content);
		details.addEventListener('toggle', function () { expanded[section.id] = details.open; if (details.open) { initEditors(details); } });
		return details;
	}
	function render() {
		if ($(list).hasClass('ui-sortable')) { $(list).sortable('destroy'); }
		if (preview && preview.sectionObserver) { preview.sectionObserver.disconnect(); }
		list.textContent = '';
		var contextualOrder = libraryMode && libraryView === 'page' && Boolean(selectedPreviewTarget());
		if (contextualOrder) {
			var groups = orderLibrarySections(doc.sections, previewTargetSections());
			list.appendChild(element('h3', {className: 'nova-service-library-group-heading'}, 'Shared sections on ' + selectedPreviewTarget().title));
			if (!groups.linked.length) { list.appendChild(element('p', {className: 'description'}, 'This page does not use any of these shared versions. Choose another preview page or link a shared version from its page editor.')); }
			groups.linked.forEach(function (entry, position) { list.appendChild(renderSection(entry.section, entry.index, position)); });
			if (groups.unused.length) { list.appendChild(element('h3', {className: 'nova-service-library-group-heading'}, 'Other library versions — not used on this page')); groups.unused.forEach(function (entry, position) { list.appendChild(renderSection(entry.section, entry.index, groups.linked.length + position)); }); }
		} else { doc.sections.forEach(function (section, index) { list.appendChild(renderSection(section, index)); }); }
		list.querySelectorAll('details[open]').forEach(initEditors);
		if (!doc.sections.length) { list.appendChild(element('p', {className: 'nova-service-empty'}, libraryMode ? 'No shared sections yet. Add the reusable content this site needs.' : 'No sections yet. Add a hero and a call to action to start your page.')); }
		if (!contextualOrder) { $(list).sortable({items: '> .nova-service-section', handle: '.nova-service-drag', axis: 'y', placeholder: 'nova-service-sort-placeholder', start: function () {
			collectEditors();
			Object.keys(richFields).forEach(function (id) { if (window.wp && window.wp.editor) { window.wp.editor.remove(id); } });
		}, stop: function () {
			var byId = Object.create(null); doc.sections.forEach(function (section) { byId[section.id] = section; });
			doc.sections = Array.prototype.map.call(list.querySelectorAll('.nova-service-section'), function (node) { return byId[node.getAttribute('data-section-id')]; });
			richFields = {}; changed(); render();
		}}); }
		write();
		renderOrderContext();
		if (preview && preview.sectionObserver) { list.querySelectorAll('.nova-service-section').forEach(function (row) { preview.sectionObserver.observe(row); }); }
		if (activeSectionId) { focusPreviewSection(activeSectionId, activePreviewId, false); }
	}
	var typeSelect = document.getElementById('nova-service-add-type');
	Object.keys(registry).forEach(function (type) { typeSelect.appendChild(element('option', {value: type}, registry[type].label)); });
	document.getElementById('nova-service-add-section').addEventListener('click', function () {
		if (doc.sections.length >= 60) { status('A service page supports up to 60 sections.', true); return; }
		removeEditors();
		var section = {id: uniqueId(), type: typeSelect.value, enabled: true, data: {heading_level: 'h2'}};
		if (section.type === 'rich_text') {
			var usedSlots = doc.sections.map(richTextSlot);
			var availableSlot = ['rich_text_1', 'rich_text_2', 'rich_text_3'].find(function (slot) { return usedSlots.indexOf(slot) < 0; });
			if (availableSlot) { section.content_slot = availableSlot; }
		}
		doc.sections.push(section); expanded[section.id] = true; changed(); render();
		var added = list.lastElementChild; if (added) { added.scrollIntoView({block: 'nearest', behavior: 'smooth'}); }
	});
	document.getElementById('nova-service-collapse').addEventListener('click', function () { list.querySelectorAll('details').forEach(function (details) { details.open = false; }); });
	var saveTemplate = document.getElementById('nova-service-save-template');
	if (saveTemplate) { saveTemplate.addEventListener('click', function () {
		collectEditors(); saveTemplate.disabled = true;
		var body = new window.FormData(); body.append('action', 'nova_service_save_template'); body.append('_nonce', config.templateNonce); body.append('document', JSON.stringify(doc));
		window.fetch(config.templateActionUrl, {method: 'POST', credentials: 'same-origin', body: body}).then(function (response) { return response.json(); }).then(function (result) {
			if (!result.success) { throw new Error(result.data && result.data.message || 'The site layout could not be saved.'); }
			status(result.data.message);
		}).catch(function (error) { status(error.message, true); }).finally(function () { saveTemplate.disabled = false; });
	}); }
	if (form) { form.addEventListener('submit', function () { collectEditors(); submitting = true; }); }
	window.addEventListener('beforeunload', function (event) {
		if (!saved && !submitting) { event.preventDefault(); event.returnValue = ''; }
	});
	// WordPress prints wp.editor's default settings after footer scripts. Wait
	// for DOM readiness so the first expanded section gets the rich editor too.
	$(function () {
		setupPreview();
		render();
		// Initial editor setup is not an author edit.
		saved = true;
		updatePreviewSavedState();
	});
}(window, document, window.jQuery));
