'use strict';

// Run with: node tests/service-editor-preview-unit.cjs
// Exercise the real UI helpers without a browser, WordPress or network writes.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../modules/service-page-cpt/assets/service-editor-v2.js'), 'utf8');
let checks = 0;
const plain = value => JSON.parse(JSON.stringify(value));
function equal(actual, expected, label) { assert.deepEqual(plain(actual), expected, label); checks++; }
function ok(value, label) { assert.ok(value, label); checks++; }
function segment(from, to) {
	const start = source.indexOf('function ' + from);
	const end = source.indexOf('function ' + to, start + 1);
	assert.ok(start >= 0 && end > start, 'UI helper exists: ' + from);
	return source.slice(start, end);
}

const orderLibrarySections = vm.runInNewContext(segment('orderLibrarySections(', 'previewIdsFor(') + '; orderLibrarySections;');
const library = [
	{id: 'empty_default', type: 'reviews', data: {}},
	{id: 'proof', type: 'reviews', data: {layout: 'cards'}},
	{id: 'team', type: 'people', data: {}},
	{id: 'constructor', type: 'reviews', data: {}}
];
const page = [
	{id: 'hero', type: 'hero'},
	{id: 'local_team', type: 'people', shared_key: 'team'},
	{id: 'local_proof', type: 'reviews', shared_key: 'proof'},
	{id: 'second_proof', type: 'reviews', shared_key: 'proof'},
	{id: 'wrong_type', type: 'credentials', shared_key: 'empty_default'},
	{id: 'special_key', type: 'reviews', shared_key: 'constructor'}
];
const before = JSON.stringify(library);
const arranged = orderLibrarySections(library, page);
equal(arranged.linked.map(entry => entry.section.id), ['team', 'proof', 'constructor'], 'linked versions follow actual page order');
equal(arranged.linked.map(entry => entry.index), [2, 1, 3], 'editing uses original library indices');
equal(arranged.linked[1].positions, [3, 4], 'one version records every actual page position');
equal(arranged.unused.map(entry => entry.section.id), ['empty_default'], 'wrong-type references are not treated as inheritance');
equal(JSON.stringify(library), before, 'contextual ordering never rewrites the library');
equal(orderLibrarySections(library, []).unused.map(entry => entry.section.id), library.map(entry => entry.id), 'no target retains original library order');

const mappingContext = {doc: {sections: library}, libraryMode: true, previewTargetSections: () => page};
vm.createContext(mappingContext);
vm.runInContext(segment('previewIdsFor(', 'updatePreviewSavedState('), mappingContext);
equal(mappingContext.previewIdsFor('proof'), ['local_proof', 'second_proof'], 'shared preview targets use raw local IDs');
equal(mappingContext.previewIdsFor('empty_default'), [], 'mismatched types cannot highlight an unrelated preset');
equal(mappingContext.previewIdsFor('missing'), [], 'missing versions have no invented preview target');
mappingContext.libraryMode = false;
equal(mappingContext.previewIdsFor('page_hero'), ['page_hero'], 'page sections retain their own ID');

const previewScrolls = [];
const focusSection = {
	getAttribute: () => 'how', classList: {toggle() {}},
	getBoundingClientRect: () => ({top: 210}),
	scrollIntoView() { throw new Error('Section focus must not move ancestor editor containers'); }
};
const focusContext = {
	activeSectionId: '', activePreviewId: '', previewIdsFor: () => ['how'],
	list: {querySelectorAll: () => []}, orderPanel: null,
	preview: {frame: {contentDocument: {querySelectorAll: () => [focusSection]}, contentWindow: {scrollY: 80, scrollTo: options => previewScrolls.push(options)}}}
};
vm.createContext(focusContext);
vm.runInContext(segment('focusPreviewSection(', 'focusEditorSection('), focusContext);
focusContext.focusPreviewSection('how', '', true);
equal(previewScrolls, [{top: 280, behavior: 'auto'}], 'section focus scrolls inside the frame and never shifts an editor click target');
focusContext.focusPreviewSection('how', '', false);
equal(previewScrolls.length, 1, 'highlighting alone does not change scrolling');
equal(focusContext.activePreviewId, 'how', 'frame-only scrolling preserves the selected section');

const hidden = {value: 0};
const tabs = [
	{href: 'https://site.example/wp-admin/edit.php?page=nova-service-shared&tab=template&preview_post_id=173'},
	{href: 'https://site.example/wp-admin/edit.php?page=nova-service-shared&tab=library'},
	{href: 'https://external.example/reference'}
];
const targetContext = {
	selectedPreviewId: 221, URL,
	document: {getElementById: () => hidden}, root: {closest: () => ({querySelectorAll: () => tabs})},
	window: {location: {href: 'https://site.example/wp-admin/edit.php?page=nova-service-shared', origin: 'https://site.example'}}
};
vm.createContext(targetContext);
vm.runInContext(segment('syncPreviewTargetContext(', 'previewTargetSections('), targetContext);
targetContext.syncPreviewTargetContext();
equal(hidden.value, 221, 'the actual automatic target is preserved on save');
equal(new URL(tabs[0].href).searchParams.get('preview_post_id'), '221', 'changing tabs retains the selected preview page');
equal(new URL(tabs[1].href).searchParams.get('preview_post_id'), '221', 'every settings tab uses the selected preview page');
equal(tabs[2].href, 'https://external.example/reference', 'external links are not changed');
targetContext.selectedPreviewId = 0; targetContext.syncPreviewTargetContext();
equal(new URL(tabs[0].href).searchParams.get('preview_post_id'), null, 'the sample target clears stale selection parameters');

function officeSelector(section, editorMode = 'page', preset = {data: {images: [
	{id: 11, url: '/office-a.jpg', alt: 'Eindhoven office'},
	{id: 12, url: '/office-b.jpg', alt: 'Office reception'}
]}}) {
	const fields = []; let redraws = 0;
	const context = {
		editorMode, presetFor: () => preset, clone: plain,
		hasOwn: (object, key) => Object.hasOwn(object, key),
		field(container, label, value, setter, options) { fields.push({label, value, setter, options}); },
		removeEditors() {}, render() { redraws++; }
	};
	vm.createContext(context);
	vm.runInContext(segment('renderOfficePhotoSelector(', 'renderImages('), context);
	context.renderOfficePhotoSelector({}, section);
	return {fields, preset, get redraws() { return redraws; }};
}
const officeSection = {id: 'office', type: 'location', shared_key: 'default_location', data: {title: 'Visit us'}};
const officeBefore = JSON.stringify(officeSection);
const office = officeSelector(officeSection);
equal(office.fields[0].value, 'shared', 'linked office photos initially follow the shared pool');
equal(JSON.stringify(officeSection), officeBefore, 'displaying the office selector preserves sparse inheritance');
equal(office.fields[0].options.choices['photo-1'], 'Photo 2 — Office reception', 'a fixed choice identifies the actual shared photo');
office.fields[0].setter('photo-1');
equal(officeSection.data, {title: 'Visit us', images: [{id: 12, url: '/office-b.jpg', alt: 'Office reception'}]}, 'a fixed photo overrides only the existing Images field');
officeSection.data.images[0].alt = 'Custom alternative text';
equal(office.preset.data.images[1].alt, 'Office reception', 'editing a fixed photo cannot change the shared pool');
equal(officeSelector(officeSection).fields[0].value, 'photo-1', 'a saved fixed photo remains selected when reopening the editor');
office.fields[0].setter('shared');
equal(officeSection.data, {title: 'Visit us'}, 'returning to the pool deletes only the image override');
equal(office.redraws, 2, 'changing a photo refreshes the editor and inherited controls');
officeSection.data.images = [];
equal(officeSelector(officeSection).fields[0].value, 'custom', 'an intentionally empty image override never pretends to inherit');
officeSection.data.images = [{url: '/private-office.jpg'}];
const customOffice = officeSelector(officeSection);
equal(customOffice.fields[0].value, 'custom', 'a local photo outside the pool is clearly identified');
const customBefore = JSON.stringify(officeSection);
customOffice.fields[0].setter('custom'); customOffice.fields[0].setter('photo-99');
equal(JSON.stringify(officeSection), customBefore, 'custom and invalid choices preserve local content');
equal(officeSelector(officeSection, 'library').fields.length, 0, 'client defaults retain the pool editor rather than a per-page selector');
equal(officeSelector(officeSection, 'template').fields.length, 0, 'the starting layout does not acquire a fixed page photo');
equal(officeSelector({type: 'location', data: {}}).fields.length, 0, 'local sections continue to use the ordinary image editor');
equal(officeSelector(officeSection, 'page', {data: {images: []}}).fields.length, 0, 'empty pools do not offer unavailable fixed photos');

function previewHarness(mode, supportsAbort = true) {
	const timers = new Map(); const calls = []; let timerId = 0; let collections = 0;
	const preview = {
		serial: 0, appliedSerial: 0, timer: null, controller: null,
		status: {textContent: ''}, notes: {hidden: true},
		warnings: {textContent: '', children: [], appendChild(node) { this.children.push(node); }},
		pane: {attributes: {}, setAttribute(key, value) { this.attributes[key] = value; }},
		frame: {srcdoc: 'previous preview', contentWindow: {scrollY: 123}}
	};
	const window = {
		setTimeout(callback, delay) { const id = ++timerId; timers.set(id, {callback, delay}); return id; },
		clearTimeout(id) { timers.delete(id); }
	};
	if (supportsAbort) { window.AbortController = class { constructor() { this.signal = {aborted: false}; } abort() { this.signal.aborted = true; } }; }
	const context = {
		preview, window, config: {editorPreviewUrl: '/editor-preview', postId: 173}, editorMode: mode, selectedPreviewId: 221,
		doc: {version: 2, sections: [{id: 'proof', type: 'reviews', enabled: true, shared_key: 'shared_proof', data: {}}]}, saved: false,
		clone: plain, collectEditors() { collections++; }, document: {getElementById: () => ({value: 'Unsaved page title'})},
		element(tag, attrs, text) { return {tag, attrs, text}; },
		visualNotify() {},
		request(url, options) { let resolve, reject; const promise = new Promise((done, fail) => { resolve = done; reject = fail; }); calls.push({url, options, resolve, reject}); return promise; }
	};
	vm.createContext(context);
	vm.runInContext(segment('schedulePreview(', 'setupPreview('), context);
	return {context, preview, timers, calls, get collections() { return collections; }, flush() { const queued = Array.from(timers.values()); timers.clear(); queued.forEach(timer => timer.callback()); }};
}
const settle = () => new Promise(resolve => setImmediate(resolve));

(async () => {
	const harness = previewHarness('page');
	harness.context.schedulePreview(); harness.context.schedulePreview(); harness.context.schedulePreview();
	equal(harness.timers.size, 1, 'typing debounces to one pending preview');
	equal(Array.from(harness.timers.values())[0].delay, 650, 'typing allows a short editing pause');
	harness.flush();
	equal(harness.calls.length, 1, 'a debounce posts one preview');
	equal(harness.collections, 1, 'TinyMCE contents are collected before the request');
	const firstPayload = JSON.parse(harness.calls[0].options.body);
	equal(firstPayload.title, 'Unsaved page title', 'page preview carries the unsaved WordPress title');
	equal(firstPayload.post_id, 173, 'page preview targets its own page');
	equal(firstPayload.document.sections[0].data, {}, 'preview preserves sparse inheritance');
	harness.context.schedulePreview(true);
	ok(harness.calls[0].options.signal.aborted, 'a newer change cancels an older request');
	harness.flush();
	harness.calls[1].resolve({html: 'newest preview', warnings: ['Forms are inactive here.']}); await settle();
	equal(harness.preview.frame.srcdoc, 'newest preview', 'the newest response is displayed');
	ok(harness.preview.status.textContent.includes('not saved'), 'preview does not claim publication');
	equal(harness.preview.notes.hidden, false, 'server warnings are visible');
	harness.calls[0].resolve({html: 'stale preview', warnings: []}); await settle();
	equal(harness.preview.frame.srcdoc, 'newest preview', 'late responses cannot replace a newer preview');
	harness.context.schedulePreview(true); harness.flush();
	harness.calls[2].reject(new Error('Invalid section field')); await settle();
	equal(harness.preview.frame.srcdoc, 'newest preview', 'an error retains the last usable preview');
	ok(harness.preview.status.textContent.includes('Invalid section field') && harness.preview.status.textContent.includes('last preview'), 'errors explain the retained preview');
	equal(harness.preview.pane.attributes['aria-busy'], 'false', 'loading state clears after an error');
	ok(harness.calls.every(call => call.url === '/editor-preview'), 'live preview performs no save or migration request');

	const libraryPreview = previewHarness('library', false);
	libraryPreview.context.schedulePreview(true); libraryPreview.flush();
	equal(JSON.parse(libraryPreview.calls[0].options.body).post_id, 221, 'library preview uses the selected real page');
	ok(!Object.hasOwn(JSON.parse(libraryPreview.calls[0].options.body), 'title'), 'library preview preserves target page title');
	libraryPreview.context.schedulePreview(true); libraryPreview.flush();
	libraryPreview.calls[1].resolve({html: 'latest library', warnings: []}); await settle();
	libraryPreview.calls[0].reject(new Error('late error')); await settle();
	equal(libraryPreview.preview.frame.srcdoc, 'latest library', 'serial guards also work without AbortController');
	ok(!libraryPreview.preview.status.textContent.includes('late error'), 'stale errors cannot overwrite current status');
	const typingPreview = previewHarness('page');
	typingPreview.preview.editing = true; typingPreview.context.schedulePreview(true); typingPreview.flush();
	equal(typingPreview.calls.length, 0, 'inline typing does not request or replace its focused canvas');
	typingPreview.preview.editing = false; typingPreview.context.schedulePreview(true); typingPreview.flush();
	equal(typingPreview.calls.length, 1, 'finishing inline editing resumes the renderer');
	const focusedCanvas = typingPreview.preview.frame.srcdoc;
	typingPreview.preview.editing = true; typingPreview.calls[0].resolve({html: 'late render during typing', warnings: []}); await settle();
	equal(typingPreview.preview.frame.srcdoc, focusedCanvas, 'a response arriving during an edit cannot destroy the caret');
	console.log(checks + ' editor preview checks passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
