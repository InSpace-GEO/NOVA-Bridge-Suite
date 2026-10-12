'use strict';

// Run with: node tests/service-visual-editor-unit.cjs
// Document edits are tested through the same controller transactions and DOM
// events used in WordPress. No browser, CMS, network or publishing is involved.
const assert = require('node:assert/strict');
const visual = require('../modules/service-page-cpt/assets/service-visual-editor-v2.js');
const clone = value => value === undefined ? undefined : JSON.parse(JSON.stringify(value));
let checks = 0;
function equal(actual, expected, message) { assert.deepEqual(actual, expected, message); checks++; }
function ok(actual, message) { assert.ok(actual, message); checks++; }

let current = '<p>Approved <a href="/offer/">offer</a>.</p>';
const writes = [];
const transactionCore = {getField: () => current, setField: (id, path, value) => { writes.push({id, path, value}); current = value; }};
let edit = visual.transaction(transactionCore, 'copy', 'body');
equal(edit.original, current, 'rich editing starts with canonical HTML including links');
edit.set('<p>Draft <strong>copy</strong>.</p>');
equal(current, '<p>Approved <a href="/offer/">offer</a>.</p>', 'drafting never changes saved model data');
edit.cancel(); equal(writes.length, 0, 'cancelling an edit performs no override or history mutation');
equal(edit.apply(), false, 'cancelled transactions cannot be applied later');
edit = visual.transaction(transactionCore, 'copy', 'body');
equal(edit.apply(), false, 'opening and applying unchanged rich text preserves sparse inheritance');
equal(writes.length, 0, 'unchanged text does not create undo entries');
edit = visual.transaction(transactionCore, 'copy', 'body'); edit.set('<p>Final <em>copy</em>.</p>');
equal(edit.apply(), true, 'a completed edit applies');
equal(writes, [{id: 'copy', path: 'body', value: '<p>Final <em>copy</em>.</p>'}], 'rich text formatting is committed once through the canonical field bridge');
equal(edit.apply(), false, 'repeated Apply cannot duplicate undo entries');
edit = visual.transaction(transactionCore, 'copy', 'body'); edit.set('<p>Outdated edit.</p>'); current = '<p>A newer edit.</p>';
assert.throws(() => edit.apply(), /changed while/, 'concurrent field changes are not silently overwritten'); checks++;
equal(current, '<p>A newer edit.</p>', 'a conflicting edit preserves the newer canonical content');

equal(visual.fieldState({shared_key: 'shared', data: {}}, 'items.0.body'), {inherited: true, overridden: false}, 'a shared collection remains inherited until changed');
equal(visual.fieldState({shared_key: 'shared', data: {items: []}}, 'items.0.body'), {inherited: false, overridden: true}, 'an explicitly empty override is not treated as inheritance');
equal(visual.fieldState({shared_key: 'shared', data: {}}, '__page_title'), {inherited: false, overridden: false}, 'the WordPress title is always page-owned');
equal(visual.fieldState({data: {title: 'Local'}}, 'title'), {inherited: false, overridden: false}, 'ordinary page content never suggests a client default');
equal(visual.mediaImage({id: '42', url: '/team.jpg', alt: 'Real team', caption: '<p>Library caption</p>', author: 8}), {id: 42, url: '/team.jpg', alt: 'Real team', caption: ''}, 'image selection retains alt text and excludes unrelated media metadata or automatic captions');
ok(visual.isEmpty({type: 'reviews', effectiveData: {}}), 'empty hidden trust sections remain reachable in the structure');
equal(visual.isEmpty({type: 'related', data: {}}), false, 'automatic related services are not mistaken for empty content');
equal(visual.isEmpty({type: 'video', data: {video_url: 'https://www.youtube.com/watch?v=example'}}), false, 'configured media sections remain visible');

class Element {
	constructor(tag, document) { this.tagName = tag; this.ownerDocument = document; this.children = []; this.parentNode = null; this.attributes = {}; this.listeners = {}; this.className = ''; this.style = {}; this._text = ''; this.innerHTML = ''; this.value = ''; this.disabled = false; this.hidden = false; this.open = false; this.isConnected = true; }
	get textContent() { return this._text + this.children.map(child => child.textContent).join(''); }
	set textContent(value) { this._text = String(value); this.children = []; }
	setAttribute(name, value) { this.attributes[name] = String(value); if (name === 'hidden') this.hidden = true; if (name === 'id') this.id = String(value); }
	getAttribute(name) { return Object.hasOwn(this.attributes, name) ? this.attributes[name] : null; }
	hasAttribute(name) { return Object.hasOwn(this.attributes, name); }
	removeAttribute(name) { delete this.attributes[name]; if (name === 'hidden') this.hidden = false; }
	get classList() {
		const node = this; return {
			contains(name) { return node.className.split(/\s+/).includes(name); },
			add(...names) { node.className = [...new Set([...node.className.split(/\s+/).filter(Boolean), ...names])].join(' '); },
			remove(...names) { node.className = node.className.split(/\s+/).filter(name => !names.includes(name)).join(' '); },
			toggle(name, force) { const exists = this.contains(name); const add = force === undefined ? !exists : force; if (add) this.add(name); else this.remove(name); return add; }
		};
	}
	appendChild(child) { if (child.parentNode) child.parentNode.children = child.parentNode.children.filter(node => node !== child); child.parentNode = this; this.children.push(child); return child; }
	addEventListener(name, handler) { (this.listeners[name] ||= []).push(handler); }
	removeEventListener(name, handler) { this.listeners[name] = (this.listeners[name] || []).filter(entry => entry !== handler); }
	emit(name, values = {}) { const event = Object.assign({target: this, prevented: false, preventDefault() { this.prevented = true; }, stopPropagation() {}}, values); for (const handler of [...(this.listeners[name] || [])]) handler(event); return event; }
	matches(selector) { if (selector.startsWith('.')) return this.classList.contains(selector.slice(1)); if (selector.startsWith('[')) return this.hasAttribute(selector.slice(1, -1)); return this.tagName === selector; }
	querySelectorAll(selector) { const result = []; for (const child of this.children) { if (child.matches(selector)) result.push(child); result.push(...child.querySelectorAll(selector)); } return result; }
	closest(selector) { if (selector.split(',').some(part => this.matches(part))) return this; return this.parentNode && this.parentNode.closest(selector); }
	focus() { this.ownerDocument.activeElement = this; }
}
class Document extends Element {
	constructor() { super('document', null); this.ownerDocument = this; this.documentElement = this; this.head = new Element('head', this); this.body = new Element('body', this); this.appendChild(this.head); this.appendChild(this.body); }
	createElement(tag) { return new Element(tag, this); }
}
function fixture() {
	const document = new Document(), frameDocument = new Document();
	const root = document.createElement('div'); document.body.appendChild(root);
	const pane = document.createElement('div'), viewport = document.createElement('div'), frame = document.createElement('iframe'), workspace = document.createElement('div'), inspector = document.createElement('div');
	workspace.className = 'nova-service-workspace'; inspector.className = 'nova-service-edit-pane'; workspace.appendChild(pane); workspace.appendChild(inspector); root.appendChild(workspace); pane.appendChild(viewport); viewport.appendChild(frame); frame.contentDocument = frameDocument;
	frame.contentWindow = {scrollX: 0, scrollY: 0, innerWidth: 1200, scrollTo(options) { this.scrollX = options.left || 0; this.scrollY = options.top || 0; }, addEventListener() {}};
	let doc = {version: 2, sections: [
		{id: 'hero', type: 'hero', data: {title: 'Approved title', body: '<p>Hero copy.</p>'}},
		{id: 'copy', type: 'rich_text', content_slot: 'rich_text_1', shared_key: 'default_copy', data: {}},
		{id: 'proof', type: 'reviews', enabled: false, data: {}},
		{id: 'office', type: 'location', shared_key: 'default_location', data: {}},
		{id: 'empty-copy', type: 'rich_text', data: {}}
	]};
	const presets = {default_copy: {body: '<p>Shared <a href="/approved/">approved link</a>.</p>'}, default_location: {images: [{id: 11, url: '/office-a.jpg'}, {id: 12, url: '/office-b.jpg'}]}};
	const mutations = [], history = [], listeners = []; let redo = [], editing = false, saves = 0, pageTitle = 'WordPress title';
	function emit(event) { listeners.forEach(listener => listener(event)); }
	function mutate(kind, fn) { history.push(clone(doc)); redo = []; fn(); mutations.push(kind); emit({type: 'model'}); }
	function section(id) { const raw = doc.sections.find(entry => entry.id === id); return raw && Object.assign(clone(raw), {effectiveData: Object.assign({}, clone(presets[raw.shared_key] || {}), clone(raw.data))}); }
	function field(id, path) { if (path === '__page_title') return pageTitle; return path.split('.').reduce((value, key) => value && value[key], section(id).effectiveData); }
	function setField(id, path, value) { mutate('field:' + path, () => { if (path === '__page_title') { pageTitle = value; return; } const raw = doc.sections.find(entry => entry.id === id); const keys = path.split('.'); if (keys.length === 1) raw.data[path] = value; else { raw.data[keys[0]] = clone(section(id).effectiveData[keys[0]]); let parent = raw.data[keys.shift()]; while (keys.length > 1) parent = parent[keys.shift()]; parent[keys[0]] = value; } }); }
	const core = {root, config: {isV2: true, mode: 'page', sections: {hero: {label: 'Hero'}, rich_text: {label: 'Rich text'}, reviews: {label: 'Reviews'}, location: {label: 'Location'}}}, preview: {pane, viewport, frame, workspace, device: 'desktop', status: {textContent: ''}}, getInspector: () => inspector, getDocument: () => clone(doc), getSection: section, getField: field, setField, resetField: (id, path) => mutate('reset', () => { delete doc.sections.find(entry => entry.id === id).data[path.split('.')[0]]; }), replaceImage: (id, path, image) => mutate('image', () => { doc.sections.find(entry => entry.id === id).data.images = [image]; }), move: (id, offset) => mutate('move', () => { const index = doc.sections.findIndex(entry => entry.id === id); const [entry] = doc.sections.splice(index, 1); doc.sections.splice(index + offset, 0, entry); }), duplicate: id => { const entry = clone(doc.sections.find(section => section.id === id)); entry.id += '-copy'; mutate('duplicate', () => doc.sections.push(entry)); return entry.id; }, remove: id => mutate('remove', () => { doc.sections = doc.sections.filter(entry => entry.id !== id); }), toggle: id => mutate('toggle', () => { const entry = doc.sections.find(section => section.id === id); entry.enabled = entry.enabled === false; }), add: type => { const entry = {id: 'added', type, data: {}}; mutate('add', () => doc.sections.push(entry)); return entry.id; }, select() {}, undo: () => { if (history.length) { redo.push(clone(doc)); doc = history.pop(); emit({type: 'model'}); } }, redo: () => { if (redo.length) { history.push(clone(doc)); doc = redo.pop(); emit({type: 'model'}); } }, canUndo: () => history.length > 0, canRedo: () => redo.length > 0, setDevice: device => { core.preview.device = device; }, setEditing: value => { editing = value; }, save: () => { saves++; }, subscribe: callback => listeners.push(callback)};
	for (const entry of doc.sections) { const details = document.createElement('details'); details.className = 'nova-service-section'; details.setAttribute('data-section-id', entry.id); inspector.appendChild(details); }
	function fieldNode(id, path, kind, tag = 'div') { let sectionNode = frameDocument.body.children.find(entry => entry.getAttribute('data-nova-service-section-id') === id); if (!sectionNode) { sectionNode = frameDocument.createElement('section'); sectionNode.setAttribute('data-nova-service-section-id', id); frameDocument.body.appendChild(sectionNode); } const element = frameDocument.createElement(tag); element.setAttribute('data-nova-service-field', path); element.setAttribute('data-nova-service-kind', kind); element.textContent = 'Rendered value'; element.innerHTML = '<span>Rendered value</span>'; sectionNode.appendChild(element); return element; }
	const mediaEvents = {}, wp = {media() { return {on(name, callback) { mediaEvents[name] = callback; }, open() {}, state() { return {get() { return {first() { return {toJSON() { return {id: 99, url: '/selected.jpg', alt: 'Selected office'}; }}; }}; }}; }}; }};
	const windowHandlers = {};
	const window = {wp, scrollX: 0, scrollY: 0, scrollTo(options) { this.scrollX = options.left || 0; this.scrollY = options.top || 0; }, addEventListener(name, handler) { (windowHandlers[name] ||= []).push(handler); }};
	function beforeUnload() { const event = {prevented: false, returnValue: undefined, preventDefault() { this.prevented = true; }}; for (const handler of windowHandlers.beforeunload || []) handler(event); return event; }
	function button(text) { const selectedRow = root.querySelectorAll('.nova-visual-structure-item').find(entry => entry.classList.contains('is-selected')); const selectedButtons = selectedRow ? selectedRow.querySelectorAll('button') : []; const result = selectedButtons.find(entry => entry.textContent === text) || root.querySelectorAll('button').find(entry => entry.textContent === text); assert.ok(result, 'Button exists: ' + text); return result; }
	return {core, window, document, frameDocument, root, inspector, mutations, fieldNode, button, mediaEvents, beforeUnload, notify: emit, get editing() { return editing; }, get saves() { return saves; }};
}

const app = fixture(); const title = app.fieldNode('hero', 'title', 'text', 'h1'), rich = app.fieldNode('copy', 'body', 'html'), photo = app.fieldNode('office', 'images.0', 'image', 'img');
const before = clone(app.core.getDocument()); const mounted = visual.mount(app.core, app.window, app.document);
ok(mounted, 'native page visual editor mounts');
equal(app.core.getDocument(), before, 'mounting the visual editor changes no page fields or default links');
equal(app.mutations, [], 'opening the editor has no model side effects');
equal(visual.mount(app.core, app.window, app.document), null, 'duplicate ready events cannot mount a second controller');
ok(app.document.body.classList.contains('nova-service-visual-open'), 'visual canvas opens as the primary editor');
ok(app.root.querySelectorAll('.nova-visual-structure-item').some(row => row.textContent.includes('Hidden')), 'hidden sections are included in the structure drawer');
ok(app.root.querySelectorAll('.nova-visual-structure-item').some(row => row.textContent.includes('Empty')), 'empty sections remain configurable without visible front-end markup');

app.frameDocument.emit('click', {target: title});
ok(app.editing && mounted.isEditing(), 'inline editing suspends preview replacement');
title.emit('blur'); equal(app.core.getField('hero', 'title'), 'Approved title', 'focusing a rendered heading preserves the different canonical title when untouched');
equal(app.mutations.length, 0, 'untouched inline text does not create an override');
app.frameDocument.emit('click', {target: title});
title.textContent = 'Draft heading'; title.emit('keydown', {key: 'Escape'});
equal(app.core.getField('hero', 'title'), 'Approved title', 'Escape restores the canonical heading');
equal(app.mutations.length, 0, 'cancelled inline edits add no undo entries');
equal(title.innerHTML, '<span>Rendered value</span>', 'Escape restores the rendered preview without saving its HTML');
equal(app.editing, false, 'cancelling releases the preview buffer');
app.frameDocument.emit('click', {target: title}); title.textContent = 'A new heading'; title.emit('input'); title.emit('keydown', {key: 'Enter'});
equal(app.core.getField('hero', 'title'), 'A new heading', 'Enter commits a heading through the field bridge');
equal(app.mutations.length, 1, 'an inline edit is one undo action');
app.button('Undo').emit('click'); equal(app.core.getField('hero', 'title'), 'Approved title', 'Undo restores the previous heading');
app.button('Redo').emit('click'); equal(app.core.getField('hero', 'title'), 'A new heading', 'Redo restores the applied heading');

app.frameDocument.emit('click', {target: rich});
const firstTextarea = app.root.querySelectorAll('textarea')[0];
equal(firstTextarea.value, '<p>Shared <a href="/approved/">approved link</a>.</p>', 'rich editor loads canonical shared HTML rather than rendered decoration');
firstTextarea.value = '<p>Cancelled replacement.</p>'; app.button('Cancel').emit('click');
equal(app.core.getSection('copy').data, {}, 'Cancel preserves inherited rich text without materializing an override');
app.frameDocument.emit('click', {target: rich}); app.root.querySelectorAll('textarea')[0].value = '<p>Normalized HTML without typing.</p>'; app.button('Apply').emit('click');
equal(app.core.getSection('copy').data, {}, 'editor formatting changes without user input do not detach shared rich text');
app.frameDocument.emit('click', {target: rich}); const changedRich = app.root.querySelectorAll('textarea')[0]; changedRich.value = '<p>Page <strong>specific</strong> content.</p>'; changedRich.emit('input'); app.button('Apply').emit('click');
equal(app.core.getSection('copy').data, {body: '<p>Page <strong>specific</strong> content.</p>'}, 'Apply overrides only the edited field on the current page');
app.button('Use client default').emit('click'); equal(app.core.getSection('copy').data, {}, 'reset restores inheritance by deleting the local key');
app.frameDocument.emit('click', {target: rich}); const quicktagsRich = app.root.querySelectorAll('textarea')[0];
quicktagsRich.value = '<p>Shared <strong>approved link</strong>.</p>'; quicktagsRich.emit('change'); app.button('Apply').emit('click');
equal(app.core.getSection('copy').data, {body: '<p>Shared <strong>approved link</strong>.</p>'}, 'HTML Quicktags toolbar changes apply without requiring a typing input event');
app.button('Use client default').emit('click');
app.frameDocument.emit('click', {target: rich}); const unchangedHtml = app.root.querySelectorAll('textarea')[0]; unchangedHtml.emit('change'); app.button('Apply').emit('click');
equal(app.core.getSection('copy').data, {}, 'a change event with unchanged canonical HTML does not create an inherited override');
app.frameDocument.emit('click', {target: rich}); const submittedRich = app.root.querySelectorAll('textarea')[0]; submittedRich.value = '<p>Applied before the normal WordPress submit.</p>'; submittedRich.emit('input'); app.notify({type: 'beforeSave'});
equal(app.core.getField('copy', 'body'), '<p>Applied before the normal WordPress submit.</p>', 'native WordPress submission flushes an active rich text edit');
equal(mounted.isEditing(), false, 'normal WordPress submission closes the edit transaction');

app.frameDocument.emit('click', {target: photo}); ok(app.editing, 'the media chooser pauses preview refresh');
app.mediaEvents.select(); app.mediaEvents.close();
equal(app.core.getSection('office').data.images, [{id: 99, url: '/selected.jpg', alt: 'Selected office', caption: ''}], 'the media chooser applies a fixed image to this page');
equal(app.editing, false, 'closing the media chooser releases preview refresh');
app.button('Phone').emit('click'); equal(app.core.preview.device, 'phone', 'Phone changes the canvas device through the core');
app.button('Desktop').emit('click'); equal(app.core.preview.device, 'desktop', 'Desktop restores the canvas width');

mounted.select('proof'); app.button('Show').emit('click'); equal(app.core.getSection('proof').enabled, true, 'hidden trust sections can be enabled from the contextual toolbar');
app.button('Hide').emit('click'); equal(app.core.getSection('proof').enabled, false, 'a section can be hidden without deleting its content');
app.button('Remove').emit('click'); equal(app.core.getSection('proof'), undefined, 'remove changes the model rather than deleting unrelated rendered HTML');
app.button('Undo').emit('click'); equal(app.core.getSection('proof').enabled, false, 'Undo restores the removed section and its visibility');
app.button('Save page').emit('click'); equal(app.saves, 1, 'Save uses the normal WordPress save bridge');
app.button('WordPress details').emit('click'); equal(app.document.body.classList.contains('nova-service-visual-open'), false, 'WordPress metadata is accessible without a second editing model');
app.button('Back to visual editor').emit('click'); ok(app.document.body.classList.contains('nova-service-visual-open'), 'the canvas can be reopened without losing edits');

const unsupported = fixture(); unsupported.core.config.mode = 'library';
equal(visual.mount(unsupported.core, unsupported.window, unsupported.document), null, 'client default settings retain their existing editor');
unsupported.core.config.mode = 'page'; unsupported.core.config.isV2 = false;
equal(visual.mount(unsupported.core, unsupported.window, unsupported.document), null, 'legacy pages retain their existing editor and migration controls');

const formatted = fixture(), editors = new Map();
formatted.core.config.saveLabel = 'Update page';
formatted.window.tinymce = {get: id => editors.get(id)};
formatted.window.wp.editor = {
	initialize(id, options) {
		const handlers = new Map(); const editor = {
			content: '<p>Shared <a href="/approved/">approved link</a>.</p>\n',
			on(names, handler) { for (const name of names.split(' ')) { const list = handlers.get(name) || []; list.push(handler); handlers.set(name, list); } },
			emit(name) { for (const handler of handlers.get(name) || []) handler({preventDefault() {}}); },
			getContent() { return this.content; }, isHidden: () => false, focus() {}
		};
		editors.set(id, editor); options.tinymce.setup(editor); editor.emit('init');
	},
	remove(id) { editors.delete(id); }
};
const formattedField = formatted.fieldNode('copy', 'body', 'html');
visual.mount(formatted.core, formatted.window, formatted.document);
ok(formatted.button('Update page'), 'the primary save button distinguishes an existing published page');
formatted.frameDocument.emit('click', {target: formattedField}); formatted.button('Apply').emit('click');
equal(formatted.core.getSection('copy').data, {}, 'untouched TinyMCE normalization preserves the inherited canonical field');
formatted.frameDocument.emit('click', {target: formattedField});
let richEditor = [...editors.values()][0], normalized = richEditor.content;
richEditor.content = '<p>A temporary formatting edit.</p>'; richEditor.emit('change'); richEditor.content = normalized; richEditor.emit('Undo'); formatted.button('Apply').emit('click');
equal(formatted.core.getSection('copy').data, {}, 'undoing all rich editor changes avoids a normalized local override');
formatted.frameDocument.emit('click', {target: formattedField}); richEditor = [...editors.values()][0]; richEditor.content = '<p>A deliberate <strong>format change</strong>.</p>'; richEditor.emit('change'); formatted.button('Apply').emit('click');
equal(formatted.core.getSection('copy').data, {body: '<p>A deliberate <strong>format change</strong>.</p>'}, 'TinyMCE toolbar edits preserve formatting and apply only the canonical body field');
equal(formatted.mutations.length, 1, 'normalization, open and Undo add no history; one real rich edit adds one transaction');

const globallyHidden = fixture();
globallyHidden.core.config.sharedUrl = 'https://site.example/wp-admin/edit.php?page=nova-service-shared&tab=library';
const ordinaryGetSection = globallyHidden.core.getSection;
globallyHidden.core.getSection = id => { const entry = ordinaryGetSection(id); if (id === 'proof') { entry.shared_key = 'default_reviews'; entry.enabled = true; entry.sharedEnabled = false; } return entry; };
const hiddenController = visual.mount(globallyHidden.core, globallyHidden.window, globallyHidden.document); hiddenController.select('proof');
ok(globallyHidden.root.querySelectorAll('.nova-visual-structure-item').some(row => row.textContent.includes('Hidden in client defaults')), 'globally disabled shared sections show why they are absent from the canvas');
equal(globallyHidden.button('Hidden in defaults').disabled, true, 'a page visibility toggle does not claim it can enable globally disabled proof');
const defaultsLink = globallyHidden.root.querySelectorAll('a').find(node => node.textContent === 'Edit client defaults');
equal(defaultsLink.getAttribute('href'), globallyHidden.core.config.sharedUrl, 'linked sections expose the existing client default editor');
equal(defaultsLink.getAttribute('target'), '_blank', 'client default editing opens without discarding the current page draft');

const cancelMedia = fixture(), cancelPhoto = cancelMedia.fieldNode('office', 'images.0', 'image', 'img');
visual.mount(cancelMedia.core, cancelMedia.window, cancelMedia.document); cancelMedia.frameDocument.emit('click', {target: cancelPhoto}); cancelMedia.mediaEvents.close();
equal(cancelMedia.core.getSection('office').data, {}, 'closing media without choosing a photo preserves the global pool');
equal(cancelMedia.mutations, [], 'cancelling media selection creates no override or undo entry');

const emptyTitle = fixture(); emptyTitle.core.setField('hero', 'title', '');
const emptyHeading = emptyTitle.fieldNode('hero', 'title', 'text', 'h1'); emptyHeading.textContent = ''; emptyHeading.innerHTML = '';
emptyTitle.core.preview.framePending = true; emptyTitle.core.preview.viewport.classList.add('is-frame-loading');
const emptyInitialMutations = emptyTitle.mutations.length;
visual.mount(emptyTitle.core, emptyTitle.window, emptyTitle.document);
equal(emptyTitle.frameDocument.documentElement.getAttribute('data-nova-visual-ready'), 'true', 'canvas readiness is exposed only after edit listeners and field controls are attached');
equal(emptyTitle.core.preview.framePending, false, 'attaching the frame releases the pending flag');
equal(emptyTitle.core.preview.viewport.classList.contains('is-frame-loading'), false, 'attaching the frame removes the interaction loading overlay');
equal(emptyHeading.textContent, 'Click to add a title', 'empty titles remain visible and editable in the canvas');
equal(emptyTitle.core.getField('hero', 'title'), '', 'visual placeholder text is never stored in the document');
emptyTitle.frameDocument.emit('click', {target: emptyHeading}); equal(emptyHeading.textContent, '', 'the placeholder clears before entering actual title text'); emptyHeading.emit('blur');
equal(emptyTitle.core.getField('hero', 'title'), '', 'opening and blurring an empty title does not save its placeholder');
equal(emptyTitle.mutations.length, emptyInitialMutations, 'untouched empty headings add no override or undo history');
equal(emptyHeading.textContent, 'Click to add a title', 'an untouched empty field restores its editing hint');
emptyTitle.frameDocument.emit('click', {target: emptyHeading}); emptyHeading.textContent = 'Cancelled title'; emptyHeading.emit('input'); emptyHeading.emit('keydown', {key: 'Escape'});
equal(emptyTitle.core.getField('hero', 'title'), '', 'Escape preserves the empty canonical title');
equal(emptyHeading.textContent, 'Click to add a title', 'cancelling an empty-title edit restores its hint');
emptyTitle.frameDocument.emit('click', {target: emptyHeading}); emptyHeading.textContent = 'My service page'; emptyHeading.emit('input'); emptyHeading.emit('keydown', {key: 'Enter'});
equal(emptyTitle.core.getField('hero', 'title'), 'My service page', 'typing replaces the visual hint with actual canonical title content');
equal(emptyHeading.hasAttribute('data-nova-visual-placeholder'), false, 'a completed title no longer carries placeholder state');

const buffered = fixture(), bufferedRich = buffered.fieldNode('copy', 'body', 'html'), bufferedHeading = buffered.fieldNode('hero', 'title', 'text', 'h1');
const bufferedController = visual.mount(buffered.core, buffered.window, buffered.document);
buffered.frameDocument.emit('click', {target: bufferedRich});
equal(buffered.beforeUnload().prevented, false, 'opening inherited rich text alone does not warn about unsaved work');
equal(bufferedController.hasPendingChanges(), false, 'an untouched rich editor has no dirty buffer');
let bufferedTextarea = buffered.root.querySelectorAll('textarea')[0]; bufferedTextarea.value = '<p>Unsaved draft in the rich editor.</p>'; bufferedTextarea.emit('input');
equal(bufferedController.hasPendingChanges(), true, 'a rich edit is protected before Apply changes the model');
equal(buffered.beforeUnload().prevented, true, 'navigation warns about buffered rich text changes');
equal(buffered.beforeUnload().returnValue, '', 'buffered edits request the browser standard unload warning');
equal(buffered.core.getSection('copy').data, {}, 'the unload warning does not detach inherited rich text');
equal(buffered.mutations, [], 'the unload warning does not apply an edit or create undo history');
bufferedTextarea.value = buffered.core.getField('copy', 'body'); bufferedTextarea.emit('input');
equal(buffered.beforeUnload().prevented, false, 'undoing every rich change removes the unload warning');
bufferedTextarea.value = '<p>A cancelled buffer.</p>'; bufferedTextarea.emit('input'); buffered.button('Cancel').emit('click');
equal(buffered.beforeUnload().prevented, false, 'Cancel removes dirty buffer protection');
equal(buffered.mutations, [], 'opening, changing and cancelling an inherited field leaves the document and history untouched');
buffered.frameDocument.emit('click', {target: bufferedHeading}); bufferedHeading.textContent = 'An unsaved inline title'; bufferedHeading.emit('input');
equal(buffered.beforeUnload().prevented, true, 'an uncommitted inline heading is also protected');
equal(buffered.core.getField('hero', 'title'), 'Approved title', 'inline unload protection leaves the canonical heading unchanged');
bufferedHeading.emit('keydown', {key: 'Escape'}); equal(buffered.beforeUnload().prevented, false, 'cancelling inline text removes its unload warning');
buffered.frameDocument.emit('click', {target: bufferedRich}); bufferedTextarea = buffered.root.querySelectorAll('textarea')[0]; bufferedTextarea.value = '<p>Committed on normal submission.</p>'; bufferedTextarea.emit('input'); buffered.notify({type: 'beforeSave'});
equal(bufferedController.hasPendingChanges(), false, 'normal WordPress submit flushes the buffer before navigation');
equal(buffered.beforeUnload().prevented, false, 'the visual buffer guard does not interrupt an authorized normal save');

equal(app.root.querySelectorAll('.nova-visual-setup-notice').length, 0, 'existing sites do not show a fresh-install setup banner');
const setupAdmin = fixture(); Object.assign(setupAdmin.core.config, {onboardingRequired: true, canManage: true, onboardingUrl: 'https://site.example/wp-admin/edit.php?post_type=service_page&page=nova-service-shared&tab=setup'});
visual.mount(setupAdmin.core, setupAdmin.window, setupAdmin.document);
const setupLink = setupAdmin.root.querySelectorAll('a').find(entry => entry.textContent === 'Resume setup');
equal(setupLink.getAttribute('href'), setupAdmin.core.config.onboardingUrl, 'an administrator can resume required onboarding from the visual editor');
const setupAuthor = fixture(); Object.assign(setupAuthor.core.config, {onboardingRequired: true, canManage: false, onboardingUrl: 'https://site.example/wp-admin/setup'}); visual.mount(setupAuthor.core, setupAuthor.window, setupAuthor.document);
ok(setupAuthor.root.querySelectorAll('.nova-visual-setup-notice')[0].textContent.includes('Ask an administrator to finish setup.'), 'an author sees the correct required-setup action without an inaccessible settings link');
equal(setupAuthor.root.querySelectorAll('a').length, 0, 'the setup banner exposes no administrator link to an author');

const dragOrder = [{id: 'hero'}, {id: 'copy'}, {id: 'hidden'}, {id: 'office'}, {id: 'empty'}];
equal(visual.dropOffset(dragOrder, 'hero', 'office', false), 2, 'dropping before a visible target counts hidden canonical sections');
equal(visual.dropOffset(dragOrder, 'hero', 'office', true), 3, 'dropping after a target accounts for removing the source first');
equal(visual.dropOffset(dragOrder, 'office', 'copy', false), -2, 'upward drops preserve every unrelated canonical position');
equal(visual.dropOffset(dragOrder, 'copy', 'hidden', false), 0, 'dropping in the existing position is a no-op');
equal(visual.dropOffset(dragOrder, 'missing', 'office', true), 0, 'unknown sources cannot reorder the document');
equal(visual.dropOffset(dragOrder, 'copy', 'copy', true), 0, 'a section cannot be dropped into itself');
equal(app.root.querySelectorAll('.nova-visual-section-tools').length, 0, 'the permanent bottom action row is removed');
equal(app.root.querySelectorAll('.nova-visual-device-controls')[0].getAttribute('role'), 'group', 'desktop and phone are a dedicated semantic device group');
ok(app.button('Sections').classList.contains('nova-visual-structure-toggle'), 'Sections is a distinct layout control');

function rect(left, top, width, height) { return {left, top, width, height, right: left + width, bottom: top + height}; }
function dragFixture() {
	const test = fixture(); const hero = test.fieldNode('hero', 'title', 'text', 'h1'), copy = test.fieldNode('copy', 'body', 'html'), office = test.fieldNode('office', 'images.0', 'image', 'img');
	const viewport = rect(100, 100, 1200, 800); test.core.preview.frame.getBoundingClientRect = () => viewport; test.core.preview.viewport.getBoundingClientRect = () => viewport;
	const mounted = visual.mount(test.core, test.window, test.document); test.root.querySelectorAll('.nova-visual-body')[0].getBoundingClientRect = () => viewport;
	for (const [field, top] of [[hero, 0], [copy, 250], [office, 600]]) { field.parentNode.getBoundingClientRect = () => rect(0, top - test.core.preview.frame.contentWindow.scrollY, 1200, 200); }
	mounted.select('hero');
	function overlay(id) { return test.root.querySelectorAll('.nova-visual-section-overlay').find(entry => entry.getAttribute('data-section-id') === id); }
	function handle(id) { return overlay(id).querySelectorAll('button').find(entry => entry.getAttribute('data-section-action') === 'drag'); }
	function start(id = 'hero', pointerId = 1) { handle(id).emit('pointerdown', {button: 0, pointerId, clientX: 120, clientY: 120}); }
	function move(x, y) { test.document.emit('pointermove', {pointerId: 1, clientX: x, clientY: y}); }
	function drop(x, y) { test.document.emit('pointerup', {pointerId: 1, clientX: x, clientY: y}); }
	return Object.assign(test, {mounted, overlay, handle, start, move, drop});
}
const mouse = dragFixture(), dragBefore = mouse.core.getDocument();
equal(mouse.overlay('hero').querySelectorAll('.nova-visual-section-handle').length, 1, 'each rendered section has its own accessible drag handle');
equal(mouse.overlay('hero').querySelectorAll('.nova-visual-section-controls')[0].children.length, 4, 'each section has settings, duplicate, visibility and remove icons');
mouse.start(); ok(mouse.editing, 'dragging pauses renderer replacement'); mouse.move(500, 860); mouse.drop(500, 860);
equal(mouse.core.getDocument().sections.map(entry => entry.id), ['copy', 'proof', 'office', 'hero', 'empty-copy'], 'a mouse drop commits the canonical order including hidden and empty sections');
equal(mouse.mutations, ['move'], 'a complete drag is one undoable document mutation');
for (const original of dragBefore.sections) { equal(mouse.core.getSection(original.id).data, original.data, 'dragging preserves all fields for ' + original.id); }
equal(mouse.editing, false, 'dropping releases renderer replacement'); mouse.button('Undo').emit('click'); equal(mouse.core.getDocument(), dragBefore, 'one Undo restores the entire pre-drag document');

const cancelledDrag = dragFixture(), cancelOrder = cancelledDrag.core.getDocument();
cancelledDrag.start(); cancelledDrag.move(500, 850); cancelledDrag.document.emit('keydown', {key: 'Escape'});
equal(cancelledDrag.core.getDocument(), cancelOrder, 'Escape cancels a drag without changing canonical order'); equal(cancelledDrag.mutations, [], 'cancelled drags create no undo entries');
cancelledDrag.start(); cancelledDrag.move(500, 850); cancelledDrag.document.emit('pointercancel'); equal(cancelledDrag.mutations, [], 'pointer cancellation leaves content and history untouched');
cancelledDrag.start(); cancelledDrag.move(500, 850); cancelledDrag.drop(20, 850); equal(cancelledDrag.mutations, [], 'dropping outside the canvas cancels the reorder');
cancelledDrag.start(); cancelledDrag.drop(120, 120); equal(cancelledDrag.mutations, [], 'clicking the handle without dragging is a no-op');
equal(cancelledDrag.core.getDocument(), cancelOrder, 'every cancelled gesture preserves hidden sections and field values');
cancelledDrag.handle('hero').emit('keydown', {key: 'ArrowDown'}); equal(cancelledDrag.core.getDocument().sections.map(entry => entry.id), ['copy', 'hero', 'proof', 'office', 'empty-copy'], 'the drag handle also provides keyboard ordering');
cancelledDrag.core.preview.framePending = true; cancelledDrag.notify({type: 'status'}); equal(cancelledDrag.root.querySelectorAll('.nova-visual-section-overlays')[0].hidden, true, 'old canvas controls are inaccessible while a new frame is loading');

const scrollClose = fixture(), scrollField = scrollClose.fieldNode('copy', 'body', 'html');
const canvasView = scrollClose.core.preview.frame.contentWindow; canvasView.scrollX = 4; canvasView.scrollY = 720; scrollClose.window.scrollX = 3; scrollClose.window.scrollY = 160;
let obsoleteFieldFocuses = 0; scrollField.focus = () => { obsoleteFieldFocuses++; canvasView.scrollY = 0; };
scrollClose.window.wp.editor = {initialize() {}, remove() { canvasView.scrollY = 0; scrollClose.window.scrollY = 0; }};
const scrollController = visual.mount(scrollClose.core, scrollClose.window, scrollClose.document);
scrollClose.frameDocument.emit('click', {target: scrollField}); scrollController.finishEdit(false);
equal([canvasView.scrollX, canvasView.scrollY, scrollClose.window.scrollX, scrollClose.window.scrollY], [4, 720, 3, 160], 'closing TinyMCE restores exact canvas and WordPress reading positions after editor cleanup');
equal(obsoleteFieldFocuses, 0, 'closing rich text never refocuses the old rendered field');
equal(scrollClose.mutations, [], 'scroll restoration does not commit a cancelled inherited field');
const replacementDocument = new Document(), replacementSection = replacementDocument.createElement('section'), replacementField = replacementDocument.createElement('div');
replacementSection.setAttribute('data-nova-service-section-id', 'copy'); replacementField.setAttribute('data-nova-service-field', 'body'); replacementField.setAttribute('data-nova-service-kind', 'html'); replacementField.textContent = 'Re-rendered copy'; replacementSection.appendChild(replacementField); replacementDocument.body.appendChild(replacementSection);
scrollClose.core.preview.frame.contentDocument = replacementDocument; canvasView.scrollY = 0; scrollClose.core.preview.frame.emit('load');
equal(canvasView.scrollY, 720, 'a later safe iframe refresh preserves the captured reading position');

const multiplePointers = dragFixture(); multiplePointers.start('hero', 1); multiplePointers.move(500, 860); multiplePointers.start('copy', 2);
multiplePointers.document.emit('pointerup', {pointerId: 2, clientX: 500, clientY: 860});
equal(multiplePointers.mutations, [], 'a second pointer cannot end or commit the active drag');
equal(multiplePointers.editing, true, 'a second pointer release leaves the first gesture active');
multiplePointers.document.emit('pointercancel', {pointerId: 2}); equal(multiplePointers.editing, true, 'a different pointer cancellation leaves the first gesture active');
equal(multiplePointers.mutations, [], 'another pointer cancellation does not change document history');
multiplePointers.drop(500, 860);
equal(multiplePointers.core.getDocument().sections.map(section => section.id), ['copy', 'proof', 'office', 'hero', 'empty-copy'], 'a second pointerdown cannot replace the source of the first drag');
equal(multiplePointers.mutations, ['move'], 'the original pointer still commits exactly one reorder');
const framePointers = dragFixture(); framePointers.start('hero', 7); framePointers.document.emit('pointermove', {pointerId: 7, clientX: 500, clientY: 860});
framePointers.frameDocument.emit('pointerup', {pointerId: 8, clientX: 400, clientY: 760}); equal(framePointers.editing, true, 'iframe-forwarded pointerup retains its pointer identity');
framePointers.frameDocument.emit('pointercancel', {pointerId: 8}); equal(framePointers.editing, true, 'iframe-forwarded pointercancel ignores other pointers');
framePointers.frameDocument.emit('pointercancel', {pointerId: 7}); equal(framePointers.editing, false, 'the matching iframe cancellation ends the original gesture');
equal(framePointers.mutations, [], 'matching cancellation ends the drag without creating history');

console.log('Passed ' + checks + ' visual editor checks.');
