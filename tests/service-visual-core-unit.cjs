const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync('modules/service-page-cpt/assets/service-editor-v2.js', 'utf8');
const plain = value => JSON.parse(JSON.stringify(value));
let checks = 0;
function equal(actual, expected, label) { assert.deepEqual(plain(actual), plain(expected), label); checks++; }
function ok(value, label) { assert.ok(value, label); checks++; }
function rejects(action, label) { assert.throws(action, label); checks++; }
const title = {value: 'Saved service title'}, publisher = {disabled: false, clicks: 0, click() { this.clicks++; }}, draftSaver = {disabled: false, clicks: 0, click() { this.clicks++; }};
const doc = {version: 2, sections: [
    {id: 'hero', type: 'hero', enabled: true, data: {title: 'Hero', body: '<p>Approved introduction.</p>'}},
    {id: 'offer', type: 'rich_text', enabled: true, content_slot: 'rich_text_1', data: {body: '<p>Approved body.</p>', intent: 'offer'}},
    {id: 'reviews', type: 'reviews', enabled: true, shared_key: 'default_reviews', data: {title: 'Local heading'}},
    {id: 'office', type: 'location', enabled: true, shared_key: 'default_location', data: {}},
]};
const presets = [
    {id: 'default_reviews', type: 'reviews', enabled: true, data: {body: 'Client proof', items: [{author: 'Alice', body: '<p>First review</p>', rating: 5}, {author: 'Bob', body: '<p>Second review</p>', rating: 4}]}},
    {id: 'default_location', type: 'location', enabled: true, data: {address: 'Stored office', image_selection: 'one_per_page', images: [{id: 11, url: 'https://example.test/a.jpg'}, {id: 12, url: 'https://example.test/b.jpg'}]}},
];
const scrolls = [], refreshes = [], dispatches = [];
const context = {
    config: {visualEditor: true}, editorMode: 'page', doc: plain(doc), presets: plain(presets), registry: {hero: {}, rich_text: {}, reviews: {}, location: {}},
    visualListeners: [], visualUndo: [], visualRedo: [], visualCurrent: null, visualRestoring: false, activeSectionId: '', activePreviewId: '', expanded: {}, saved: true,
    preview: {serial: 2, controller: {abort() { context.aborts++; }}, editing: false}, aborts: 0,
    clone: plain, root: {querySelector() { return 'inspector'; }},
    document: {getElementById(id) { return id === 'title' ? title : id === 'publish' ? publisher : id === 'save-post' ? draftSaver : null; }, dispatchEvent(event) { dispatches.push(event.type); }},
    window: {clearTimeout() {}, CustomEvent: class { constructor(type) { this.type = type; } }},
    write() {}, updatePreviewSavedState() {}, renderOrderContext() {}, schedulePreview(value) { if (context.preview.editing) { context.preview.pending = true; return; } refreshes.push(value); }, removeEditors() {},
    render() { context.visualNotify('model'); }, focusPreviewSection(id, previewId, scroll) { scrolls.push({id, scroll}); }, fitPreviewFrame() {},
    sectionLabel(section) { return section.type; }, richTextSlot(section) { return section.content_slot || ''; }, uniqueId() { return 'new-' + (++context.ids); }, ids: 0,
};
context.presetFor = section => context.presets.find(p => p.id === section.shared_key && p.type === section.type);
context.effectiveData = section => Object.assign({}, plain(context.presetFor(section)?.data || {}), plain(section.data));
context.hasOwn = (object, key) => Object.hasOwn(object, key);
vm.createContext(context);
vm.runInContext(source.slice(source.indexOf('\tfunction collection('), source.indexOf('\tfunction changed()')), context);
const emptySection = {data: {}}, emptyImages = context.collection(emptySection, 'images');
equal(emptySection.data, {}, 'rendering an empty collection preserves omission');
emptyImages.push({url: 'https://example.test/new.jpg'}); equal(emptySection.data.images, [{url: 'https://example.test/new.jpg'}], 'adding a photo attaches the edited collection');
const clearedSection = {data: {images: []}}; context.collection(clearedSection, 'images'); equal(clearedSection.data, {images: []}, 'an explicit cleared collection remains explicit');
vm.runInContext(source.slice(source.indexOf('\tfunction changed()'), source.indexOf('\tfunction write()')), context);
context.setupVisualCore(); const core = context.window.novaServiceVisualCore;
equal(dispatches, ['nova-service-visual-ready'], 'the controller mounts after the canonical editor is ready');
const untouched = core.getDocument(); core.getSection('reviews'); core.getField('reviews', 'items.0.body'); core.select('reviews');
equal(core.getDocument(), untouched, 'selection and reading shared fields never detach them');
ok(!core.canUndo(), 'merely selecting a field creates no undo step');
equal(scrolls.at(-1), {id: 'reviews', scroll: false}, 'inline selection does not move the page under the pointer');
core.select('office', true); ok(scrolls.at(-1).scroll, 'structure navigation can scroll deliberately');
core.setField('reviews', 'items.0.body', '<p>Edited review</p>');
equal(core.getSection('reviews').data, {title: 'Local heading', items: [{author: 'Alice', body: '<p>Edited review</p>', rating: 5}, {author: 'Bob', body: '<p>Second review</p>', rating: 4}]}, 'a card edit copies only its whole inherited collection and retains other review details');
equal(context.presets[0].data.items[0].body, '<p>First review</p>', 'page editing never writes the shared library');
core.undo(); equal(core.getSection('reviews').data, {title: 'Local heading'}, 'undo restores sparse shared inheritance');
ok(core.canRedo(), 'a completed edit can be redone'); core.redo();
equal(core.getField('reviews', 'items.0.body'), '<p>Edited review</p>', 'redo restores the exact page field');
core.resetField('reviews', 'items.0.body'); equal(core.getSection('reviews').data, {title: 'Local heading'}, 'reset restores the shared collection while retaining separate local fields');
core.replaceImage('office', 'images.1', {id: 99, url: 'https://example.test/fixed.jpg', alt: 'Chosen office', caption: ''});
equal(core.getSection('office').data.images, [{id: 99, url: 'https://example.test/fixed.jpg', alt: 'Chosen office', caption: ''}], 'a pooled office image click creates one fixed local choice');
equal(context.presets[1].data.images.length, 2, 'media selection leaves the client pool intact');
core.resetField('office', 'images.0'); equal(core.getSection('office').data, {}, 'resetting the photo resumes the client pool');
const duplicate = core.duplicate('offer');
ok(!Object.hasOwn(core.getSection(duplicate), 'content_slot') && !Object.hasOwn(core.getSection(duplicate).data, 'intent'), 'a duplicate cannot compete with an existing posting slot');
equal(core.getSection('offer').content_slot, 'rich_text_1', 'the original posting slot stays stable');
core.move(duplicate, -1); equal(core.getDocument().sections.map(s => s.id), ['hero', duplicate, 'offer', 'reviews', 'office'], 'section moves change order without changing identity');
core.toggle('office'); ok(core.getSection('office').enabled === false, 'hiding retains the section and its source'); core.undo(); ok(core.getSection('office').enabled, 'undo restores visibility');
const added = core.add('rich_text', 'offer'); equal(core.getSection(added).content_slot, 'rich_text_2', 'adding rich text assigns only an unused generic posting slot');
core.remove(added); ok(!core.getSection(added), 'remove affects only the selected section'); core.undo(); ok(core.getSection(added), 'undo can recover a removed section');
const beforeInvalid = core.getDocument();
rejects(() => core.setField('reviews', '__proto__.polluted', 'x'), 'unsafe paths are rejected');
rejects(() => core.setField('reviews', 'items.0.constructor', 'x'), 'prototype fields are not writable');
rejects(() => core.setField('reviews', 'items.99.body', 'x'), 'stale item indexes cannot create arbitrary content');
rejects(() => core.setField('hero', 'body', {unsafe: true}), 'inline fields require a text value');
equal(core.getDocument(), beforeInvalid, 'failed inline edits preserve the document');
context.doc.sections.push({id: 'missing', type: 'reviews', enabled: true, shared_key: 'deleted_version', data: {body: 'Keep local content'}});
rejects(() => core.resetField('missing', 'body'), 'an unavailable client default cannot clear local content');
equal(core.getField('missing', 'body'), 'Keep local content', 'missing-version reset preserves the existing text');
core.setEditing(true); equal(context.aborts, 1, 'typing cancels an already queued renderer response');
ok(context.preview.editing, 'typing holds the canvas transaction'); core.setEditing(false); ok(!context.preview.editing && refreshes.at(-1), 'commit or cancel resumes one fresh render');
context.preview.appliedSerial = context.preview.serial; const settledRefreshes = refreshes.length;
core.setEditing(true); core.setEditing(false); equal(refreshes.length, settledRefreshes, 'opening and cancelling an unchanged field does not reload the canvas');
equal(context.preview.appliedSerial, context.preview.serial, 'an unchanged close retains a settled renderer state');
core.setEditing(true); core.setField('hero', 'body', '<p>Changed while the canvas was paused.</p>');
ok(context.preview.pending, 'a completed edit queues the canonical document render while paused');
core.setEditing(false); equal(refreshes.length, settledRefreshes + 1, 'applying a real edit resumes exactly one renderer request');
context.preview.appliedSerial = context.preview.serial; context.preview.serial++;
core.setEditing(true); core.setEditing(false); equal(refreshes.length, settledRefreshes + 2, 'an earlier pending render resumes even if the field was cancelled');
const pendingSerial = context.preview.serial, appliedBeforeDuplicateClose = context.preview.appliedSerial;
core.setEditing(false); equal(context.preview.serial, pendingSerial, 'duplicate close callbacks leave the active render token intact');
equal(context.preview.appliedSerial, appliedBeforeDuplicateClose, 'duplicate close callbacks cannot advertise an unfinished render as applied');
core.setField('hero', '__page_title', 'New WordPress title'); equal(title.value, 'New WordPress title', 'editing the fallback heading updates the WordPress title');
core.undo(); equal(title.value, 'Saved service title', 'undo restores title and document together');
core.subscribe(event => event.type === 'beforeSave' ? false : undefined); ok(context.visualNotify('beforeSave') === false, 'a failed edit transaction can block normal WordPress submission');
core.save(); equal(publisher.clicks, 1, 'the visual save button uses the existing WordPress save action');
context.config.saveAction = 'save-post'; core.save(); equal(draftSaver.clicks, 1, 'required onboarding saves through the existing WordPress draft action');
equal(publisher.clicks, 1, 'a required-setup draft save does not invoke publication');
console.log(checks + ' visual core checks passed');
