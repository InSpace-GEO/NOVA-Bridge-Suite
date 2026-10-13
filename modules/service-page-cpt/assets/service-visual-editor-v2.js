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
	function dropOffset(sections, id, targetId, after) {
		var from = sections.findIndex(function (section) { return section.id === id; });
		var target = sections.findIndex(function (section) { return section.id === targetId; });
		if (from < 0 || target < 0 || id === targetId) { return 0; }
		var insertion = target + (after ? 1 : 0);
		if (from < insertion) { insertion--; }
		return insertion - from;
	}
	function safeRichURL(value) {
		var url = String(value || '').trim();
		if (!url || /[\u0000-\u001f\u007f\\]/.test(url) || /^\/\//.test(url)) { return ''; }
		if (/^(?:https?:\/\/|mailto:|tel:|#|\/(?!\/))/i.test(url)) { return url; }
		return !/[:\s]/.test(url) && /^[a-z0-9_.?~-]/i.test(url) ? url : '';
	}
	function richHistory(initial) {
		var entries = [{html: initial, selection: null}], index = 0;
		return {
			record: function (html, selection) { if (entries[index].html === html) { return false; } entries = entries.slice(0, index + 1); entries.push({html: html, selection: copy(selection)}); if (entries.length > 80) { entries.shift(); } index = entries.length - 1; return true; },
			undo: function () { if (index > 0) { index--; return copy(entries[index]); } return null; },
			redo: function () { if (index < entries.length - 1) { index++; return copy(entries[index]); } return null; }
		};
	}
	function wpRichColor(value) {
		var match = String(value || '').match(/^rgba?\(\s*(\d+)\s*[, ]\s*(\d+)\s*[, ]\s*(\d+)(?:\s*[,/]\s*([0-9.]+%?))?\s*\)$/i);
		if (!match || match.slice(1, 4).some(function (part) { return Number(part) > 255; }) || match[4] && match[4] !== '1' && match[4] !== '1.0' && match[4] !== '100%') { return value; }
		return '#' + match.slice(1, 4).map(function (part) { return Number(part).toString(16).padStart(2, '0'); }).join('');
	}
	function safeRichHTML(html, document, canonical, atoms) {
		var template = document.createElement('template'); template.innerHTML = String(html || '');
		if (!template.content) { return ''; }
		var allowed = 'p h1 h2 h3 h4 h5 h6 strong b em i u s del ins sub sup ul ol li blockquote q pre code kbd samp var br hr a span div figure figcaption img table caption col colgroup thead tbody tfoot tr td th dl dt dd abbr acronym cite time address small mark font details summary video audio source'.split(' ');
		var discard = 'script style iframe object embed form input button textarea select option svg math link meta base'.split(' ');
		Array.prototype.slice.call(template.content.querySelectorAll('*')).forEach(function (entry) {
			var tag = entry.tagName.toLowerCase();
			if (discard.indexOf(tag) >= 0 || !canonical && ['video', 'audio', 'source'].indexOf(tag) >= 0) { entry.remove(); return; }
			if (allowed.indexOf(tag) < 0) { entry.replaceWith.apply(entry, Array.prototype.slice.call(entry.childNodes)); return; }
			Array.prototype.slice.call(entry.attributes).forEach(function (attribute) {
				var name = attribute.name.toLowerCase(), value = attribute.value;
				if (name === 'style') {
					var styles = [];
					var properties = canonical ? Array.prototype.slice.call(entry.style) : ['color', 'background-color', 'text-align', 'font-weight', 'font-style', 'text-decoration'];
					properties.forEach(function (property) {
						var item = entry.style.getPropertyValue(property).trim();
						if (canonical && (property === 'color' || property === 'background-color')) { item = wpRichColor(item); }
						if (!item || !/^(?:--[a-z0-9_-]+|[a-z][a-z0-9-]*)$/i.test(property) || /^(?:behavior|-moz-binding|position|z-index)$/i.test(property) || /[\\;{}]|expression|javascript|vbscript|@import/i.test(item)) { return; }
						var urls = item.match(/url\s*\([^)]*\)/gi) || [];
						if (urls.some(function (url) { return !safeRichURL(url.replace(/^url\s*\(\s*['"]?|['"]?\s*\)$/gi, '')); })) { return; }
						if (canonical || /^(?:[a-z#0-9\s.,()%\/-]+|var\(--[a-z0-9_-]+\))$/i.test(item)) { styles.push(property + ':' + item); }
					});
					if (styles.length) { entry.setAttribute('style', styles.join(';')); } else { entry.removeAttribute('style'); }
					return;
				}
				if (name === 'href' && tag === 'a' || name === 'src' && ['img', 'video', 'audio', 'source'].indexOf(tag) >= 0) { var safe = safeRichURL(value); if (safe) { entry.setAttribute(name, safe); } else { entry.removeAttribute(name); } return; }
				if (name === 'class' && canonical) { entry.setAttribute('class', value.split(/\s+/).filter(function (token) { return !/^nova-(?:visual|service-preview)/.test(token); }).join(' ')); return; }
				if (canonical && name === 'srcset' && tag === 'img') { if (!value.split(',').every(function (candidate) { var parts = candidate.trim().split(/\s+/); return parts.length <= 2 && Boolean(safeRichURL(parts[0])) && (!parts[1] || /^(?:[0-9]+w|[0-9.]+x)$/.test(parts[1])); })) { entry.removeAttribute(name); } return; }
				if (canonical && (/^(?:aria-|data-)/.test(name) && !/^data-nova-/.test(name) || ['sizes', 'loading', 'decoding', 'fetchpriority', 'align', 'valign', 'bgcolor', 'border', 'cellpadding', 'cellspacing', 'headers', 'abbr', 'nowrap', 'summary', 'span', 'start', 'reversed', 'value', 'datetime', 'cite', 'hreflang', 'download', 'dir', 'lang', 'open'].indexOf(name) >= 0)) { return; }
				if (['title', 'alt', 'width', 'height', 'colspan', 'rowspan', 'scope', 'type', 'id'].indexOf(name) >= 0 || name === 'color' && tag === 'font') { return; }
				if (tag === 'a' && ['target', 'rel'].indexOf(name) >= 0) { if (name === 'target' && value !== '_blank' && value !== '_self') { entry.removeAttribute(name); } return; }
				if (['video', 'audio'].indexOf(tag) >= 0 && ['controls', 'loop', 'muted', 'preload', 'poster', 'autoplay', 'playsinline'].indexOf(name) >= 0) { if (name === 'poster' && !safeRichURL(value)) { entry.removeAttribute(name); } return; }
				entry.removeAttribute(name);
			});
			if (tag === 'a' && entry.getAttribute('target') === '_blank') { entry.setAttribute('rel', 'noopener noreferrer'); }
			if (tag === 'h1') { var heading = document.createElement('h2'); Array.prototype.slice.call(entry.attributes).forEach(function (attribute) { heading.setAttribute(attribute.name, attribute.value); }); while (entry.firstChild) { heading.appendChild(entry.firstChild); } entry.replaceWith(heading); }
		});
		if (canonical && atoms) { Array.prototype.slice.call(template.content.querySelectorAll('video,audio')).forEach(function (entry) {
			var id = String(Object.keys(atoms).length), placeholder = document.createElement('span'); atoms[id] = entry.outerHTML;
			placeholder.setAttribute('data-nova-rich-atom', id); placeholder.setAttribute('contenteditable', 'false'); placeholder.textContent = entry.tagName.toLowerCase() === 'video' ? 'Video' : 'Audio'; entry.replaceWith(placeholder);
		}); }
		return template.innerHTML;
	}

	function mount(core, window, document) {
		if (!core || !core.root || !core.preview || !core.preview.frame || core.root.getAttribute('data-visual-mounted')) { return null; }
		var config = core.config || {};
		if (config.mode && config.mode !== 'page' || !config.isV2) { return null; }
		var root = core.root, preview = core.preview, inspector = core.getInspector(), registry = config.sections || {};
		var selected = '', selectedPath = '', selectedKind = '', activeEdit = null, inMetadata = false;
		var frameDocument = null, deviceButtons = {}, lastFocus = null, pendingScroll = null, restoringScroll = false;
		var overlays = {}, hovered = '', drag = null, paintQueued = false;
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
		var richToolbar = node('div', {className: 'nova-visual-rich-toolbar', role: 'toolbar', 'aria-label': 'Rich text formatting', hidden: ''});
		var overlayLayer = node('div', {className: 'nova-visual-section-overlays'});
		var dropLine = node('div', {className: 'nova-visual-drop-target', hidden: '', 'aria-hidden': 'true'});
		var fieldTools = node('span', {className: 'nova-visual-field-tools'});
		var structureList = node('ol', {className: 'nova-visual-structure-list'});
		var format = node('select', {className: 'nova-visual-rich-format', 'aria-label': 'Text format', 'data-preview-control': '1'});
		['p', 'h2', 'h3'].forEach(function (tag) { format.appendChild(node('option', {value: tag}, tag === 'p' ? 'Paragraph' : 'Heading ' + tag.slice(1))); }); richToolbar.appendChild(format);
		format.addEventListener('change', function () { richCommand('formatBlock', format.value); });
		function richIcon(label, title, symbol, action, type) { var control = icon(label, title, symbol, action, type); control.classList.add('nova-visual-rich-action'); control.setAttribute('data-rich-action', type); richToolbar.appendChild(control); return control; }
		richIcon('Bold', 'Bold', 'editor-bold', function () { richCommand('bold'); }, 'bold');
		richIcon('Italic', 'Italic', 'editor-italic', function () { richCommand('italic'); }, 'italic');
		richIcon('Bullets', 'Bulleted list', 'editor-ul', function () { richCommand('insertUnorderedList'); }, 'unordered');
		richIcon('Numbered', 'Numbered list', 'editor-ol', function () { richCommand('insertOrderedList'); }, 'ordered');
		var linkPanel = node('div', {className: 'nova-visual-rich-link-panel', hidden: ''});
		var linkURL = node('input', {type: 'text', inputmode: 'url', 'data-preview-control': '1', className: 'nova-visual-rich-link-url', 'aria-label': 'Link URL', placeholder: 'https://…'});
		richIcon('Link', 'Add or edit link', 'admin-links', function () { if (!activeEdit || !activeEdit.rich) { return; } activeEdit.remember(); linkPanel.hidden = !linkPanel.hidden; if (!linkPanel.hidden) { var anchor = activeEdit.anchorLink(); linkURL.value = anchor ? anchor.getAttribute('href') || '' : ''; positionRichToolbar(); linkURL.focus({preventScroll: true}); } }, 'link');
		linkPanel.appendChild(linkURL);
		function closeLink() { linkPanel.hidden = true; if (activeEdit && activeEdit.rich) { activeEdit.focusEditor(); } }
		function applyLink() { var url = safeRichURL(linkURL.value); if (!url) { announce('Enter a website, page, email or telephone link.', true); return; } if (richCommand('createLink', url) !== false) { closeLink(); } }
		linkURL.addEventListener('keydown', function (event) { if (event.key === 'Enter') { event.preventDefault(); event.stopPropagation(); applyLink(); } if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); closeLink(); } });
		linkPanel.appendChild(button('Apply link', applyLink));
		linkPanel.appendChild(button('Remove link', function () { richCommand('unlink'); linkPanel.hidden = true; }));
		linkPanel.appendChild(button('Close link', closeLink));
		var alignment = node('select', {className: 'nova-visual-rich-alignment', 'aria-label': 'Text alignment', 'data-preview-control': '1'});
		[['', 'Site alignment'], ['left', 'Left'], ['center', 'Center'], ['right', 'Right']].forEach(function (entry) { alignment.appendChild(node('option', {value: entry[0]}, entry[1])); }); richToolbar.appendChild(alignment);
		alignment.addEventListener('change', function () { richCommand(alignment.value ? 'justify' + alignment.value.slice(0, 1).toUpperCase() + alignment.value.slice(1) : 'siteAlignment'); });
		var color = node('input', {type: 'color', className: 'nova-visual-rich-color', 'aria-label': 'Text color', value: '#111827', 'data-preview-control': '1'}); richToolbar.appendChild(color);
		color.addEventListener('input', function () { richCommand('foreColor', color.value); });
		var done = richIcon('Done', 'Apply text changes', 'yes-alt', function () { finishEdit(true); }, 'done'); done.classList.add('is-primary');
		richIcon('Cancel', 'Cancel text changes', 'no-alt', function () { finishEdit(false); }, 'cancel'); richToolbar.appendChild(linkPanel);
		richToolbar.addEventListener('mousedown', function (event) { if (activeEdit && activeEdit.rich) { activeEdit.remember(); } if (event.target.closest && event.target.closest('button')) { event.preventDefault(); } });
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
		var structureToggle = button('Sections', function () { showStructure(structure.hidden); }, 'button nova-visual-structure-toggle');
		structureToggle.setAttribute('aria-expanded', 'false'); toolbar.appendChild(structureToggle);
		var deviceGroup = node('div', {className: 'nova-visual-device-controls', role: 'group', 'aria-label': 'Canvas width'}); toolbar.appendChild(deviceGroup);
		['desktop', 'phone'].forEach(function (device) {
			var control = button(device === 'desktop' ? 'Desktop' : 'Phone', function () {
				core.setDevice(device); root.classList.toggle('is-phone', device === 'phone');
				Object.keys(deviceButtons).forEach(function (name) { deviceButtons[name].setAttribute('aria-pressed', String(name === device)); });
				paintOverlays();
			});
			control.setAttribute('aria-pressed', String(device === preview.device)); deviceButtons[device] = control; deviceGroup.appendChild(control);
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
		inspectorContent.appendChild(fieldTools);
		if (inspector) { inspectorContent.appendChild(inspector); }
		root.appendChild(toolbar);
		if (config.onboardingRequired) {
			var setupNotice = node('div', {className: 'nova-visual-setup-notice', role: 'note'});
			setupNotice.appendChild(node('span', {}, 'Finish Service CPT setup before publishing. '));
			if (config.canManage && config.onboardingUrl) { setupNotice.appendChild(node('a', {href: config.onboardingUrl}, 'Resume setup')); }
			else { setupNotice.appendChild(node('span', {}, 'Ask an administrator to finish setup.')); }
			root.appendChild(setupNotice);
		}
		root.appendChild(body); body.appendChild(preview.pane); body.appendChild(overlayLayer); overlayLayer.appendChild(dropLine); body.appendChild(structure); body.appendChild(inspectorDrawer); body.appendChild(richToolbar);
		root.classList.add('nova-service-visual-mode'); document.body.classList.add('nova-service-visual-open'); root.classList.toggle('is-phone', preview.device === 'phone');
		preview.pane.hidden = false; preview.workspace.classList.remove('is-preview-hidden', 'is-preview-expanded');
		preview.frame.setAttribute('title', 'Visual editor of your service page');
		Array.prototype.forEach.call(preview.pane.children, function (entry) {
			if (entry.tagName.toLowerCase() === 'p' && entry.classList.contains('description')) { entry.hidden = true; }
		});

		function getSelected() { return selected ? core.getSection(selected) : null; }
		function icon(label, title, symbol, action, type) {
			var control = button('', function (event) { event.preventDefault(); event.stopPropagation(); action(event); }, 'nova-visual-icon-button' + (type === 'remove' ? ' is-destructive' : ''));
			control.setAttribute('title', title); control.setAttribute('aria-label', title); control.setAttribute('data-section-action', type);
			control.appendChild(node('span', {className: 'dashicons dashicons-' + symbol, 'aria-hidden': 'true'}));
			var text = node('span', {className: 'screen-reader-text'}, label); control.appendChild(text); control.novaLabel = text;
			return control;
		}
		function sectionAction(id, action) {
			if (!finishEdit(true)) { return; } select(id);
			if (action === 'settings') { showInspector(true); return; }
			if (action === 'duplicate') { var result = run(function () { return core.duplicate(id); }); if (result) { select(typeof result === 'string' ? result : result.id); } return; }
			if (action === 'hide') { var section = core.getSection(id); if (section && section.sharedEnabled !== false) { run(function () { core.toggle(id); }); } return; }
			if (action === 'remove') { run(function () { core.remove(id); }); selected = ''; selectedPath = ''; updateSelection(); announce('Section removed. Undo restores it.'); }
		}
		function sectionControls(container, id, keyboard) {
			var section = core.getSection(id); if (!section) { return null; }
			var controls = node('div', {className: keyboard ? 'nova-visual-structure-actions' : 'nova-visual-section-controls', role: 'toolbar', 'aria-label': 'Actions for ' + sectionName(section, registry, 0)});
			if (keyboard) { ['up', 'down'].forEach(function (direction) {
				var offset = direction === 'up' ? -1 : 1, label = direction === 'up' ? 'Move up' : 'Move down';
				var control = icon(label, label, 'arrow-' + direction + '-alt2', function () { if (finishEdit(true)) { run(function () { core.move(id, offset); }); select(id); } }, direction);
				var sections = core.getDocument().sections, index = sections.findIndex(function (entry) { return entry.id === id; }); control.disabled = index + offset < 0 || index + offset >= sections.length; controls.appendChild(control);
			}); }
			controls.appendChild(icon('Settings', 'Section settings', 'admin-generic', function () { sectionAction(id, 'settings'); }, 'settings'));
			controls.appendChild(icon('Duplicate', 'Duplicate section', 'admin-page', function () { sectionAction(id, 'duplicate'); }, 'duplicate'));
			var hide = icon(section.enabled === false ? 'Show' : 'Hide', section.enabled === false ? 'Show section' : 'Hide section', section.enabled === false ? 'visibility' : 'hidden', function () { sectionAction(id, 'hide'); }, 'hide');
			if (section.sharedEnabled === false) { hide.disabled = true; hide.novaLabel.textContent = 'Hidden in defaults'; hide.setAttribute('aria-label', 'Hidden in client defaults'); hide.setAttribute('title', 'Hidden in client defaults'); }
			controls.appendChild(hide);
			controls.appendChild(icon('Remove', 'Remove section', 'trash', function () { sectionAction(id, 'remove'); }, 'remove'));
			container.appendChild(controls); return {controls: controls, hide: hide};
		}
		function captureScroll() {
			var view = preview.frame.contentWindow;
			return {x: view ? view.scrollX || 0 : 0, y: view ? view.scrollY || 0 : 0, parentX: window.scrollX || 0, parentY: window.scrollY || 0, viewportX: preview.viewport.scrollLeft || 0, viewportY: preview.viewport.scrollTop || 0};
		}
		function restoreScroll(position) {
			if (!position) { return; } restoringScroll = true; preview.scrollY = position.y;
			try {
				preview.viewport.scrollLeft = position.viewportX; preview.viewport.scrollTop = position.viewportY;
				if (preview.frame.contentWindow && preview.frame.contentWindow.scrollTo) { preview.frame.contentWindow.scrollTo({left: position.x, top: position.y, behavior: 'instant'}); }
				if (window.scrollTo) { window.scrollTo({left: position.parentX, top: position.parentY, behavior: 'instant'}); }
			} finally { restoringScroll = false; }
			paintOverlays();
		}
		function sectionRect(source) {
			if (!source.getBoundingClientRect || !preview.frame.getBoundingClientRect || !preview.viewport.getBoundingClientRect || !body.getBoundingClientRect) { return null; }
			var frameRect = preview.frame.getBoundingClientRect(), viewportRect = preview.viewport.getBoundingClientRect(), bodyRect = body.getBoundingClientRect(), local = source.getBoundingClientRect();
			var width = preview.frame.contentWindow && preview.frame.contentWindow.innerWidth || preview.frame.clientWidth || frameRect.width;
			var scale = frameRect.width / (width || frameRect.width || 1);
			var rect = {left: frameRect.left + local.left * scale, top: frameRect.top + local.top * scale, right: frameRect.left + local.right * scale, bottom: frameRect.top + local.bottom * scale};
			return {rect: rect, viewport: viewportRect, body: bodyRect, left: Math.max(rect.left, viewportRect.left), top: Math.max(rect.top, viewportRect.top), right: Math.min(rect.right, viewportRect.right), bottom: Math.min(rect.bottom, viewportRect.bottom)};
		}
		function paintOverlays() {
			overlayLayer.hidden = Boolean(preview.framePending || inMetadata || activeEdit && activeEdit.rich);
			Object.keys(overlays).forEach(function (id) {
				var entry = overlays[id], section = core.getSection(id), bounds = sectionRect(entry.source);
				entry.node.classList.toggle('is-selected', id === selected); entry.node.classList.toggle('is-hovered', id === hovered); entry.node.classList.toggle('is-dragging', Boolean(drag && drag.started && drag.id === id));
				entry.node.hidden = !section || section.enabled === false || section.sharedEnabled === false || inMetadata || Boolean(bounds && (bounds.right <= bounds.left || bounds.bottom <= bounds.top));
				if (bounds) { entry.node.style.left = (bounds.left - bounds.body.left) + 'px'; entry.node.style.top = (bounds.top - bounds.body.top) + 'px'; entry.node.style.width = Math.max(0, bounds.right - bounds.left) + 'px'; entry.node.style.height = Math.max(0, bounds.bottom - bounds.top) + 'px'; }
			});
			if (activeEdit && activeEdit.rich) { positionRichToolbar(); }
		}
		function queuePaint() {
			if (paintQueued) { return; } paintQueued = true;
			var schedule = window.requestAnimationFrame || function (callback) { callback(); };
			schedule(function () { paintQueued = false; paintOverlays(); });
		}
		function installOverlays() {
			overlayLayer.textContent = ''; overlays = {}; overlayLayer.appendChild(dropLine);
			frameDocument.querySelectorAll('[data-nova-service-section-id]').forEach(function (source) {
				var id = source.getAttribute('data-nova-service-section-id'); if (!core.getSection(id) || overlays[id]) { return; }
				var overlay = node('div', {className: 'nova-visual-section-overlay', 'data-section-id': id});
				var handle = icon('Move', 'Move section', 'move', function () { select(id); announce('Drag the handle to move this section. You can also use the Up and Down arrow keys.'); }, 'drag'); handle.classList.add('nova-visual-section-handle'); overlay.appendChild(handle);
				handle.addEventListener('pointerdown', function (event) { beginDrag(id, handle, event); });
				handle.addEventListener('keydown', function (event) {
					if (event.key === 'ArrowUp' || event.key === 'ArrowDown') { event.preventDefault(); if (finishEdit(true)) { run(function () { core.move(id, event.key === 'ArrowUp' ? -1 : 1); }); select(id); announce('Section moved.'); } }
				});
				var actions = sectionControls(overlay, id, false); overlayLayer.appendChild(overlay); overlays[id] = {node: overlay, source: source, handle: handle, actions: actions};
				overlay.addEventListener('mouseenter', function () { hovered = id; paintOverlays(); });
				overlay.addEventListener('mouseleave', function () { if (hovered === id) { hovered = ''; paintOverlays(); } });
			}); paintOverlays();
		}
		function updateDrop(clientX, clientY) {
			var choice = null, distance = Infinity;
			Object.keys(overlays).forEach(function (id) {
				var section = core.getSection(id); if (id === drag.id || !section || section.enabled === false || section.sharedEnabled === false) { return; } var bounds = sectionRect(overlays[id].source); if (!bounds) { return; }
				var rect = bounds.rect, dx = Math.max(rect.left - clientX, 0, clientX - rect.right), dy = Math.max(rect.top - clientY, 0, clientY - rect.bottom), score = dx + dy;
				if (score < distance) { distance = score; choice = {id: id, after: clientY >= (rect.top + rect.bottom) / 2, bounds: bounds}; }
			});
			drag.target = choice; dropLine.hidden = !choice;
			if (choice) { var bounds = choice.bounds, top = choice.after ? bounds.rect.bottom : bounds.rect.top; top = Math.max(bounds.viewport.top, Math.min(bounds.viewport.bottom, top));
				dropLine.classList.toggle('is-before', !choice.after); dropLine.classList.toggle('is-after', choice.after); dropLine.style.left = (bounds.left - bounds.body.left) + 'px'; dropLine.style.top = (top - bounds.body.top) + 'px'; dropLine.style.width = Math.max(0, bounds.right - bounds.left) + 'px';
			}
		}
		function autoScrollDrag() {
			if (!drag || !drag.started || !window.requestAnimationFrame || !preview.viewport.getBoundingClientRect) { return; }
			var rect = preview.viewport.getBoundingClientRect(), margin = 56, speed = drag.y < rect.top + margin ? -Math.min(22, (rect.top + margin - drag.y) / 3) : drag.y > rect.bottom - margin ? Math.min(22, (drag.y - rect.bottom + margin) / 3) : 0;
			if (speed && preview.frame.contentWindow) { var view = preview.frame.contentWindow; view.scrollTo({top: Math.max(0, view.scrollY + speed), left: view.scrollX || 0, behavior: 'auto'}); updateDrop(drag.x, drag.y); paintOverlays(); }
			drag.raf = window.requestAnimationFrame(autoScrollDrag);
		}
		function beginDrag(id, handle, event) {
			if (drag || event.button !== undefined && event.button !== 0 || !finishEdit(true)) { return; } event.preventDefault(); event.stopPropagation(); select(id);
			core.setEditing(true); drag = {id: id, handle: handle, pointerId: event.pointerId, x: event.clientX, y: event.clientY, startX: event.clientX, startY: event.clientY, started: false, target: null};
			if (handle.setPointerCapture && event.pointerId !== undefined) { handle.setPointerCapture(event.pointerId); }
			updateSelection();
		}
		function moveDrag(event) {
			if (!drag || event.pointerId !== undefined && drag.pointerId !== undefined && event.pointerId !== drag.pointerId) { return; }
			drag.x = event.clientX; drag.y = event.clientY;
			if (!drag.started && Math.abs(drag.x - drag.startX) + Math.abs(drag.y - drag.startY) < 6) { return; }
			if (!drag.started) { drag.started = true; root.classList.add('is-section-dragging'); autoScrollDrag(); }
			event.preventDefault(); updateDrop(drag.x, drag.y); paintOverlays();
		}
		function endDrag(cancel, event) {
			if (!drag) { return; }
			if (event && event.pointerId !== undefined && drag.pointerId !== undefined && event.pointerId !== drag.pointerId) { return; }
			if (event && typeof event.clientX === 'number' && typeof event.clientY === 'number') { drag.x = event.clientX; drag.y = event.clientY; if (drag.started) { updateDrop(drag.x, drag.y); } }
			var viewportRect = preview.viewport.getBoundingClientRect ? preview.viewport.getBoundingClientRect() : null;
			var inside = !viewportRect || drag.x >= viewportRect.left && drag.x <= viewportRect.right && drag.y >= viewportRect.top && drag.y <= viewportRect.bottom;
			var ended = drag; drag = null; dropLine.hidden = true; root.classList.remove('is-section-dragging');
			if (ended.raf && window.cancelAnimationFrame) { window.cancelAnimationFrame(ended.raf); }
			if (ended.handle.releasePointerCapture && ended.pointerId !== undefined) { try { ended.handle.releasePointerCapture(ended.pointerId); } catch (error) {} }
			if (!cancel && inside && ended.started && ended.target) { var offset = dropOffset(core.getDocument().sections, ended.id, ended.target.id, ended.target.after); if (offset) { run(function () { core.move(ended.id, offset); }); announce('Section moved. Undo restores its position.'); } }
			core.setEditing(false); updateSelection();
		}
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
				var row = node('li', {className: 'nova-visual-structure-item' + (selected === raw.id ? ' is-selected' : ''), 'data-section-id': raw.id});
				var data = section.effectiveData || section.data || {};
				var name = sectionName(section, registry, index) + (data.title ? ' · ' + data.title : '');
				row.appendChild(button((index + 1) + '. ' + name, function () {
					if (!finishEdit(true)) { return; } select(raw.id, '', '', true);
					if (section.enabled === false || section.sharedEnabled === false || isEmpty(section)) { showInspector(true); }
				}, 'nova-visual-section-select'));
				if (section.enabled === false || section.sharedEnabled === false || isEmpty(section)) { row.appendChild(node('small', {className: 'nova-visual-section-state'}, section.sharedEnabled === false ? 'Hidden in client defaults' : section.enabled === false ? 'Hidden' : 'Empty')); }
				sectionControls(row, raw.id, true);
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
			undo.disabled = Boolean(activeEdit || drag) || !core.canUndo(); redo.disabled = Boolean(activeEdit || drag) || !core.canRedo();
			if (frameDocument) { frameDocument.querySelectorAll('[data-nova-service-section-id]').forEach(function (entry) { entry.classList.toggle('nova-service-preview-selected', entry.getAttribute('data-nova-service-section-id') === selected); }); }
			filterInspector(); renderStructure(); updateFieldTools(); paintOverlays();
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
			var edit = activeEdit, position = captureScroll();
			try {
				if (apply) {
					var value = edit.read(); edit.transaction.set(value); edit.transaction.apply();
				} else { edit.transaction.cancel(); }
			} catch (error) { announce(error.message, true); return false; }
			pendingScroll = position; activeEdit = null; edit.cleanup(apply); root.classList.remove('is-inline-editing'); core.setEditing(false); updateSelection(); restoreScroll(position);
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
		function positionRichToolbar() {
			if (!activeEdit || !activeEdit.rich) { return; }
			var bounds = sectionRect(activeEdit.block()); if (!bounds) { return; }
			richToolbar.style.maxWidth = Math.max(120, bounds.viewport.width - 16) + 'px';
			var width = richToolbar.offsetWidth || 560, height = richToolbar.offsetHeight || 42;
			var left = Math.max(bounds.viewport.left + 8, Math.min(bounds.rect.left, bounds.viewport.right - width - 8));
			var above = bounds.rect.top - height - 8, below = above < bounds.viewport.top + 8, top = above;
			if (below) {
				top = bounds.rect.bottom + 8;
				if (top + height > bounds.viewport.bottom - 8) {
					var caret = activeEdit.caretRect(), caretBounds = caret && sectionRect({getBoundingClientRect: function () { return caret; }});
					if (caretBounds) { var caretAbove = caretBounds.rect.top - height - 8; below = caretAbove < bounds.viewport.top + 8; top = below ? caretBounds.rect.bottom + 8 : caretAbove; }
					else { top = bounds.viewport.bottom - height - 8; }
				}
			}
			richToolbar.classList.toggle('is-below', below); richToolbar.classList.toggle('is-above', !below);
			richToolbar.style.left = (left - bounds.body.left) + 'px'; richToolbar.style.top = (Math.max(bounds.viewport.top + 8, top) - bounds.body.top) + 'px';
			positionLinkPanel();
		}
		function positionLinkPanel() {
			if (linkPanel.hidden || !richToolbar.getBoundingClientRect || !preview.viewport.getBoundingClientRect) { return; }
			var viewport = preview.viewport.getBoundingClientRect(), bar = richToolbar.getBoundingClientRect();
			var maxWidth = Math.max(80, viewport.width - 16), maxHeight = Math.max(60, viewport.height - 16);
			linkPanel.style.maxWidth = maxWidth + 'px'; linkPanel.style.maxHeight = maxHeight + 'px'; linkPanel.style.overflowY = 'auto';
			var width = Math.min(linkPanel.offsetWidth || 360, maxWidth), height = Math.min(linkPanel.offsetHeight || 112, maxHeight);
			var below = bar.bottom + 8, above = bar.top - height - 8;
			var top = below + height <= viewport.bottom - 8 ? below : above >= viewport.top + 8 ? above : Math.max(viewport.top + 8, Math.min(below, viewport.bottom - height - 8));
			var left = Math.max(viewport.left + 8, Math.min(bar.left, viewport.right - width - 8));
			linkPanel.style.top = (top - bar.top) + 'px'; linkPanel.style.bottom = 'auto'; linkPanel.style.left = (left - bar.left) + 'px';
		}
		function richCommand(command, value) {
			if (activeEdit && activeEdit.rich) { return run(function () { return activeEdit.command(command, value); }); }
			return false;
		}
		function beginRich(target, id, path, event) {
			if (!finishEdit(true)) { return; } select(id, path, 'html');
			var edit = transaction(core, id, path), renderedHTML = target.innerHTML, owner = target.ownerDocument, atoms = {}, dirty = false, bookmark = null;
			var safe = safeRichHTML(String(edit.original || ''), owner, true, atoms); target.innerHTML = safe;
			var baseline = target.innerHTML, history = richHistory(baseline);
			core.setEditing(true); root.classList.add('is-inline-editing'); richToolbar.hidden = false; linkPanel.hidden = true;
			target.setAttribute('contenteditable', 'true'); target.setAttribute('role', 'textbox'); target.setAttribute('aria-multiline', 'true'); target.setAttribute('aria-label', 'Edit rich text'); target.classList.add('nova-visual-rich-editing');
			function contained(range) { return Boolean(range && (range.startContainer === target || target.contains(range.startContainer)) && (range.endContainer === target || target.contains(range.endContainer))); }
			function selection() { return owner.getSelection ? owner.getSelection() : null; }
			function remember() {
				var selected = selection(); if (selected && selected.rangeCount) { bookmark = contained(selected.getRangeAt(0)) ? selected.getRangeAt(0).cloneRange() : null; }
				var current = block(), tag = current.tagName && current.tagName.toLowerCase(); format.value = ['h2', 'h3'].indexOf(tag) >= 0 ? tag : 'p';
				var aligned = current, explicitAlignment = '';
				while (aligned && (aligned === target || target.contains(aligned))) { explicitAlignment = aligned.style && aligned.style.getPropertyValue ? aligned.style.getPropertyValue('text-align') : ''; if (explicitAlignment || aligned === target) { break; } aligned = aligned.parentElement; }
				alignment.value = explicitAlignment;
				var leaf = startLeaf(), colored = leaf && leaf.nodeType === 3 ? leaf.parentElement : leaf || current;
				if (owner.defaultView && owner.defaultView.getComputedStyle) { var rgb = owner.defaultView.getComputedStyle(colored).color.match(/^rgba?\(\s*(\d+)[,\s]+(\d+)[,\s]+(\d+)(?:\s*[,/]\s*[\d.%]+)?\s*\)$/); if (rgb) { color.value = '#' + rgb.slice(1).map(function (part) { return Number(part).toString(16).padStart(2, '0'); }).join(''); } }
				positionRichToolbar();
			}
			function caret(range) { var selected = selection(); if (selected && range && contained(range)) { selected.removeAllRanges(); selected.addRange(range); bookmark = range.cloneRange(); } }
			function initialCaret() {
				if (!owner.createRange) { return; } var range = null;
				if (event && owner.caretRangeFromPoint) { range = owner.caretRangeFromPoint(event.clientX, event.clientY); }
				if (!contained(range)) { range = owner.createRange(); range.selectNodeContents(target); range.collapse(false); } caret(range);
			}
			function offsets() {
				if (!bookmark || !contained(bookmark) || !owner.createRange) { return null; }
				var before = owner.createRange(); before.selectNodeContents(target); before.setEnd(bookmark.startContainer, bookmark.startOffset); var start = before.toString().length;
				before.setEnd(bookmark.endContainer, bookmark.endOffset); return {start: start, end: before.toString().length};
			}
			function restoreOffsets(saved) {
				if (!owner.createRange || !owner.createTreeWalker) { initialCaret(); return; }
				var range = owner.createRange(), walker = owner.createTreeWalker(target, 4), text, total = 0, start = null, end = null;
				while ((text = walker.nextNode())) { var next = total + text.textContent.length; if (saved && !start && saved.start <= next) { start = {node: text, offset: Math.max(0, saved.start - total)}; } if (saved && saved.end <= next) { end = {node: text, offset: Math.max(0, saved.end - total)}; break; } total = next; }
				if (start && end) { range.setStart(start.node, start.offset); range.setEnd(end.node, end.offset); } else { range.selectNodeContents(target); range.collapse(false); } caret(range);
			}
			function changedRich() { remember(); if (history.record(target.innerHTML, offsets())) { dirty = true; } positionRichToolbar(); }
			function localUndo(redo) { var state = redo ? history.redo() : history.undo(); if (state) { target.innerHTML = state.html; dirty = true; restoreOffsets(state.selection); positionRichToolbar(); } }
			function startLeaf() {
				var current = bookmark && bookmark.startContainer; if (!current || !contained(bookmark)) { return target; }
				var children = current.childNodes || current.children || [], offset = bookmark.startOffset || 0;
				if (current.nodeType !== 3 && children.length) {
					var backward = offset >= children.length, index = Math.min(offset, children.length - 1);
					while (children[index] && children[index].nodeType === 3 && !children[index].textContent.trim() && index + (backward ? -1 : 1) >= 0 && index + (backward ? -1 : 1) < children.length) { index += backward ? -1 : 1; }
					current = children[index];
					while (current && current.nodeType !== 3) { var nested = current.childNodes || current.children || []; if (!nested.length) { break; } var child = backward ? nested.length - 1 : 0; while (nested[child] && nested[child].nodeType === 3 && !nested[child].textContent.trim() && child + (backward ? -1 : 1) >= 0 && child + (backward ? -1 : 1) < nested.length) { child += backward ? -1 : 1; } current = nested[child]; }
				}
				return current && (current === target || target.contains(current)) ? current : target;
			}
			function block() { var current = startLeaf(); if (current && current.nodeType === 3) { current = current.parentElement; } var closest = current && current.closest ? current.closest('p,li,h2,h3,h4,blockquote') : null; return closest && (closest === target || target.contains(closest)) ? closest : target; }
			function anchorLink() { var current = bookmark && bookmark.startContainer; if (current && current.nodeType === 3) { current = current.parentElement; } var anchor = current && current.closest ? current.closest('a') : null; return anchor && target.contains(anchor) ? anchor : null; }
			function command(name, value) {
				if (!bookmark || !contained(bookmark)) { announce('Select text in this paragraph before formatting it.', true); return false; }
				target.focus({preventScroll: true}); caret(bookmark);
				if (name === 'createLink') {
					var url = safeRichURL(value), anchor = anchorLink(); if (!url) { return false; }
					if (anchor) { anchor.setAttribute('href', url); changedRich(); return true; }
					if (bookmark.collapsed) { announce('Select the text you want to link first.', true); return false; }
					value = url;
				}
				if (name === 'siteAlignment') { var current = block(); if (current.style) { current.style.removeProperty('text-align'); } }
				else if (owner.execCommand) { if (name === 'foreColor') { owner.execCommand('styleWithCSS', false, true); } owner.execCommand(name, false, value === undefined ? null : value); }
				changedRich(); return true;
			}
			function paste(event) {
				event.preventDefault(); var source = event.clipboardData; if (!source || !owner.createRange) { return; }
				remember(); if (!bookmark || !contained(bookmark)) { return; }
				var html = source.getData('text/html'), plain = source.getData('text/plain');
				if (!html) { html = plain.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\r?\n/g, '<br>'); }
				var template = owner.createElement('template'); template.innerHTML = safeRichHTML(html, owner, false, null);
				var fragment = template.content, last = fragment && fragment.lastChild; if (!fragment || !last) { return; }
				bookmark.deleteContents(); bookmark.insertNode(fragment); bookmark.setStartAfter(last); bookmark.collapse(true); caret(bookmark); changedRich();
			}
			function preventTextDrop(event) { event.preventDefault(); event.stopPropagation(); announce('Paste text into the paragraph to add it.'); }
			function key(event) {
				var modifier = event.ctrlKey || event.metaKey, name = String(event.key || '').toLowerCase();
				if (name === 'escape') { event.preventDefault(); event.stopPropagation(); finishEdit(false); return; }
				if (modifier && (name === 'z' || name === 'y')) { event.preventDefault(); event.stopPropagation(); localUndo(name === 'y' || Boolean(event.shiftKey)); return; }
				if (modifier && (name === 'b' || name === 'i')) { event.preventDefault(); event.stopPropagation(); remember(); command(name === 'b' ? 'bold' : 'italic'); }
				if (modifier && name === 'a' && owner.createRange) { event.preventDefault(); var range = owner.createRange(); range.selectNodeContents(target); caret(range); }
			}
			function beforeInput(event) { if (event.inputType === 'historyUndo' || event.inputType === 'historyRedo') { event.preventDefault(); localUndo(event.inputType === 'historyRedo'); } }
			function read() {
				if (!dirty || target.innerHTML === baseline) { return edit.original; }
				var template = owner.createElement('template'); template.innerHTML = target.innerHTML;
				if (template.content) { Array.prototype.slice.call(template.content.querySelectorAll('[data-nova-rich-atom]')).forEach(function (entry) { var original = atoms[entry.getAttribute('data-nova-rich-atom')]; if (original) { var media = owner.createElement('template'); media.innerHTML = original; entry.replaceWith(media.content); } else { entry.remove(); } }); }
				return safeRichHTML(template.innerHTML, owner, true, null);
			}
			activeEdit = {rich: true, target: target, transaction: edit, read: read, remember: remember, block: block, anchorLink: anchorLink, command: command, caretRect: function () { if (!bookmark || !contained(bookmark) || !bookmark.getBoundingClientRect) { return null; } var range = bookmark.cloneRange(); range.collapse(false); var bounds = range.getBoundingClientRect(); return bounds.height ? bounds : null; }, focusEditor: function () { target.focus({preventScroll: true}); if (bookmark && contained(bookmark)) { caret(bookmark); } }, cleanup: function (applied) {
				target.removeEventListener('input', changedRich); target.removeEventListener('keyup', remember); target.removeEventListener('mouseup', remember); target.removeEventListener('keydown', key); target.removeEventListener('beforeinput', beforeInput); target.removeEventListener('paste', paste); target.removeEventListener('drop', preventTextDrop); owner.removeEventListener('selectionchange', remember);
				if (!applied || !dirty || target.innerHTML === baseline) { target.innerHTML = renderedHTML; }
				target.removeAttribute('contenteditable'); target.removeAttribute('role'); target.removeAttribute('aria-label'); target.removeAttribute('aria-multiline'); target.classList.remove('nova-visual-rich-editing'); richToolbar.hidden = true; linkPanel.hidden = true; lastFocus = null;
				var selected = selection(); if (selected) { selected.removeAllRanges(); } if (preview.frame.focus) { preview.frame.focus({preventScroll: true}); }
			}};
			target.addEventListener('input', changedRich); target.addEventListener('keyup', remember); target.addEventListener('mouseup', remember); target.addEventListener('keydown', key); target.addEventListener('beforeinput', beforeInput); target.addEventListener('paste', paste); target.addEventListener('drop', preventTextDrop); owner.addEventListener('selectionchange', remember);
			target.focus({preventScroll: true}); initialCaret(); remember(); updateSelection(); positionRichToolbar();
			announce('Edit text on the page. Use the toolbar for formatting; Done applies and Escape cancels.');
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
			var style = current.createElement('style'); style.textContent = 'html{scroll-behavior:auto!important}[data-nova-service-field]{cursor:pointer}[data-nova-service-field]:focus{outline:2px solid #7158e2;outline-offset:4px}.nova-visual-text-editing,.nova-visual-rich-editing{cursor:text!important;min-height:1em;outline:2px solid #7158e2!important;outline-offset:4px}.nova-visual-rich-editing [data-nova-rich-atom]{display:inline-block;padding:6px 10px;border:1px solid #ccd0d4;border-radius:4px;background:#f7f8fa;color:#50575e;cursor:default}.nova-visual-text-placeholder{opacity:.58;min-height:1em}[data-nova-service-section-id].nova-service-preview-selected{outline:2px solid #7158e2!important;outline-offset:2px}'; current.head.appendChild(style);
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
				if (activeEdit && activeEdit.rich) { if (activeEdit.target.contains(event.target)) { activeEdit.remember(); return; } if (!finishEdit(true)) { return; } }
				var section = event.target.closest && event.target.closest('[data-nova-service-section-id]'); if (!section) { return; }
				var id = section.getAttribute('data-nova-service-section-id'), path = target && target.getAttribute('data-nova-service-field'), kind = target && target.getAttribute('data-nova-service-kind');
				if (!target || !path) { if (finishEdit(true)) { select(id); } return; }
				event.preventDefault();
				if (kind === 'text') { beginText(target, id, path); }
				else if (kind === 'html') { beginRich(target, id, path, event); }
				else if (kind === 'image') { chooseImage(id, path); }
				else { if (finishEdit(true)) { select(id, path, kind); showInspector(true); } }
			}
			current.addEventListener('click', activate);
			current.addEventListener('keydown', function (event) {
				if ((event.key === 'Enter' || event.key === ' ') && event.target.hasAttribute('data-nova-service-field') && event.target.getAttribute('contenteditable') !== 'true') { activate(event); }
			});
			current.addEventListener('mousemove', function (event) { var section = event.target.closest && event.target.closest('[data-nova-service-section-id]'); hovered = section ? section.getAttribute('data-nova-service-section-id') : ''; paintOverlays(); });
			current.addEventListener('pointermove', function (event) { if (drag && preview.frame.getBoundingClientRect) { var rect = preview.frame.getBoundingClientRect(), scale = rect.width / (preview.frame.contentWindow.innerWidth || rect.width); moveDrag({pointerId: event.pointerId, clientX: rect.left + event.clientX * scale, clientY: rect.top + event.clientY * scale, preventDefault: function () { event.preventDefault(); }}); } });
			current.addEventListener('pointerup', function (event) { if (drag && preview.frame.getBoundingClientRect) { var rect = preview.frame.getBoundingClientRect(), scale = rect.width / (preview.frame.contentWindow.innerWidth || rect.width); endDrag(false, {pointerId: event.pointerId, clientX: rect.left + event.clientX * scale, clientY: rect.top + event.clientY * scale}); } }); current.addEventListener('pointercancel', function (event) { endDrag(true, event); });
			current.addEventListener('keydown', function (event) { if (event.key === 'Escape' && drag) { event.preventDefault(); endDrag(true); } });
			if (preview.frame.contentWindow && preview.frame.contentWindow.addEventListener) { preview.frame.contentWindow.addEventListener('scroll', function () { if (pendingScroll && !restoringScroll && !activeEdit && !preview.framePending) { pendingScroll = captureScroll(); } queuePaint(); }); }
			current.addEventListener('submit', function (event) { event.preventDefault(); }); installOverlays(); updateSelection(); restoreFocus();
			if (pendingScroll) { var position = pendingScroll; pendingScroll = null; restoreScroll(position); }
			if (current.documentElement) { current.documentElement.setAttribute('data-nova-visual-ready', 'true'); }
			preview.framePending = false; preview.viewport.classList.remove('is-frame-loading'); paintOverlays();
		}
		preview.frame.addEventListener('load', attachFrame);
		document.addEventListener('pointermove', moveDrag); document.addEventListener('pointerup', function (event) { endDrag(false, event); }); document.addEventListener('pointercancel', function (event) { endDrag(true, event); });
		document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && drag) { event.preventDefault(); endDrag(true); } });
		document.addEventListener('click', function (event) { if (activeEdit && activeEdit.rich && !(event.target.closest && event.target.closest('.nova-visual-rich-toolbar'))) { finishEdit(true); } });
		if (window.addEventListener) { window.addEventListener('resize', queuePaint); window.addEventListener('blur', function () { if (drag) { endDrag(true); } }); }
		if (window.ResizeObserver) { var overlayResize = new window.ResizeObserver(queuePaint); overlayResize.observe(preview.viewport); }
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
			if (event && event.type === 'status') { announce(event.message || (preview.status && preview.status.textContent) || ''); paintOverlays(); }
		});
		updateSelection(); attachFrame(); core.setDevice(preview.device || 'desktop');
		announce('Click text to edit. Click an image or select Section settings for other changes.');
		return {select: select, finishEdit: finishEdit, openInspector: function (id) { select(id); showInspector(true); }, isEditing: function () { return Boolean(activeEdit); }, hasPendingChanges: hasPendingChanges};
	}

	var api = {mount: mount, transaction: transaction, fieldState: fieldState, mediaImage: mediaImage, isEmpty: isEmpty, dropOffset: dropOffset, safeRichURL: safeRichURL, richHistory: richHistory, safeRichHTML: safeRichHTML, wpRichColor: wpRichColor};
	if (typeof module !== 'undefined' && module.exports) { module.exports = api; }
	if (host && host.document) {
		host.novaServiceVisualEditor = api;
		function boot() { if (host.novaServiceVisualCore) { api.instance = mount(host.novaServiceVisualCore, host, host.document); } }
		host.document.addEventListener('nova-service-visual-ready', boot); boot();
	}
})(typeof window !== 'undefined' ? window : null);
