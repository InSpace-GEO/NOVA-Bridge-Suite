/**
 * Offline onboarding behavior checks: node tests/service-onboarding-ui-unit.cjs
 *
 * The small DOM and deferred fetch fixtures exercise the actual shipped script.
 * No browser, WordPress connection, network request or active setting is used.
 */
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const scriptPath = path.resolve(__dirname, '../modules/service-page-cpt/assets/service-onboarding.js');
const script = fs.readFileSync(scriptPath, 'utf8');
const onboarding = require(scriptPath);
let checks = 0;
const cases = [];

function check(condition, message) {
	checks += 1;
	assert.ok(condition, message);
}
function equal(actual, expected, message) {
	checks += 1;
	assert.deepEqual(actual, expected, message);
}
function copy(value) { return JSON.parse(JSON.stringify(value)); }
function defaultPreset(draft, type) { return draft.presets.sections.find(section => section.id === 'default_' + type); }
function flush() { return new Promise(resolve => setImmediate(resolve)); }
async function scenario(name, run) { await run(); cases.push(name); }

/** Only the DOM operations used by the wizard, with native disabled-control behavior. */
class Element {
	constructor(tagName) {
		this.tagName = tagName;
		this.children = [];
		this.events = {};
		this.attributes = {};
		this.className = '';
		this._text = '';
		this.value = '';
		this.disabled = false;
		this.hidden = false;
		this.classList = {
			add: (...names) => { this.className = [...new Set([...this.className.split(/\s+/).filter(Boolean), ...names])].join(' '); },
			remove: (...names) => { this.className = this.className.split(/\s+/).filter(name => !names.includes(name)).join(' '); },
			toggle: (name, force) => {
				const names = new Set(this.className.split(/\s+/).filter(Boolean));
				if (force === undefined ? !names.has(name) : force) { names.add(name); } else { names.delete(name); }
				this.className = [...names].join(' ');
			},
			contains: name => this.className.split(/\s+/).includes(name),
		};
	}
	appendChild(child) { this.children.push(child); return child; }
	set textContent(value) { this._text = String(value); this.children = []; }
	get textContent() { return this._text + this.children.map(child => child.textContent).join(''); }
	setAttribute(name, value) { this.attributes[name] = String(value); }
	getAttribute(name) { return this.attributes[name] || null; }
	addEventListener(name, callback) { (this.events[name] ||= []).push(callback); }
	querySelectorAll(selector) {
		const tags = selector.split(',').map(tag => tag.trim());
		const matches = [];
		const visit = element => {
			for (const child of element.children) { if (tags.includes(child.tagName)) { matches.push(child); } visit(child); }
		};
		visit(this);
		return matches;
	}
	querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
	focus() { this.focused = true; }
	fire(name, properties = {}) {
		if (this.disabled && ['click', 'input', 'change'].includes(name)) { return; }
		for (const callback of this.events[name] || []) { callback({target: this, ...properties}); }
	}
}

function fixture(options = {}) {
	const root = new Element('div');
	const settingsFingerprint = new Element('input'); settingsFingerprint.value = 'saved-configuration-fingerprint';
	const timers = new Map();
	const requests = [];
	const beforeUnload = [];
	let timerId = 0;
	const supplied = {
		business: {name: 'Example clinic', website: 'https://example.test', phone: ''},
		ui: {cta_type: 'booking', cta_label: 'Book a consultation', cta_target: 'https://example.test/book', style: 'editorial'},
		...(options.draft || {}),
	};
	const draft = options.wizardSettings && options.draft?.presets ? copy(supplied) : onboarding.prepareDraft(supplied);
	// A resumed, valid first action makes the timing tests independent of typing steps.
	if (!(options.wizardSettings && options.draft?.presets)) { onboarding.applyCTA(draft); }
	const state = {
		required: options.classification !== 'upgrade', complete: false,
		classification: options.classification || 'fresh', revision: 0,
		step: options.step || 'business', draft,
	};
	if (options.settings || options.wizardSettings) { state.complete = true; state.required = false; state.fingerprint = 'saved-configuration-fingerprint'; state.language = options.language || 'nl'; }
	const config = {state, demo: options.demo === true, endpoint: 'https://example.test/onboarding', nonce: 'fixture-rest-nonce'};
	if (options.settings || options.wizardSettings) { config.mode = 'settings'; if (!options.stateLanguageOnly) { config.language = options.language || 'nl'; } }
	if (options.initialStep) { config.initialStep = options.initialStep; }
	if (options.wizardSettings) { config.advancedUrl = 'https://example.test/settings?view=advanced'; config.isTranslated = Boolean(options.translated); }
	if (options.preview) { config.previewUrl = 'https://example.test/editor-preview'; }
	const window = {
		novaServiceOnboarding: config,
		AbortController,
		setTimeout: (callback, delay) => { timers.set(++timerId, {callback, delay}); return timerId; },
		clearTimeout: id => timers.delete(id),
		addEventListener: (name, callback) => { if (name === 'beforeunload') { beforeUnload.push(callback); } },
		fetch: (url, requestOptions) => {
			const body = JSON.parse(requestOptions.body);
			const record = {url, body, options: requestOptions, settled: false};
			requests.push(record);
			return new Promise(resolve => { record.resolve = resolve; });
		},
	};
	vm.runInNewContext(script, {
		window, document: {createElement: tag => new Element(tag), getElementById: id => id === (options.settings ? 'nova-service-setup-business' : 'nova-service-onboarding') ? root : options.settings && id === 'nova-service-settings-fingerprint' ? settingsFingerprint : null},
		URL, Promise, module: {exports: {}},
	}, {filename: scriptPath});
	return {
		root, config, timers, requests, settingsFingerprint,
		button(text) {
			return root.querySelectorAll('button').find(button => text instanceof RegExp ? text.test(button.textContent) : button.textContent === text);
		},
		field(labelText) {
			const label = root.querySelectorAll('label').find(label => label.children[0]?.textContent === labelText);
			assert.ok(label, 'Fixture field exists: ' + labelText);
			return label.children[1];
		},
		input(labelText, value) { const control = this.field(labelText); control.value = value; control.fire('input'); },
		runTimer(delay) {
			const timer = [...timers].find(([, timer]) => timer.delay === delay);
			if (!timer) { return false; }
			timers.delete(timer[0]); timer[1].callback(); return true;
		},
		respond(record, options = {}) {
			assert.ok(record && !record.settled, 'Fixture responds to a pending request once');
			record.settled = true;
			const status = options.status || 200;
			const data = options.data || (record.url === config.previewUrl ? {html: '<p>Preview</p>'} : {
				...state, revision: record.body.expected_revision + 1, draft: record.body.draft, step: record.body.step,
				complete: record.body.action === 'finish' || record.body.action === 'save_settings', required: record.body.action !== 'finish' && record.body.action !== 'save_settings',
			});
			record.resolve({ok: status < 400, status, json: () => Promise.resolve(data)});
		},
		navigationIsBlocked() {
			let prevented = false;
			const event = {preventDefault: () => { prevented = true; }};
			for (const handler of beforeUnload) { handler(event); }
			return prevented;
		},
	};
}

async function main() {
	await scenario('Canonical reusable defaults and generic copy slots', () => {
		const supplied = {business: {name: 'Example clinic'}, ui: {style: 'conversation'}};
		const original = copy(supplied);
		const draft = onboarding.prepareDraft(supplied);
		equal(supplied, original, 'Preparing the wizard does not mutate localized or previously saved state');
		check(draft.template.sections.filter(section => section.shared_key).every(section => draft.presets.sections.some(preset => preset.id === section.shared_key && preset.type === section.type)), 'Every starting shared reference has a matching reusable default');
		equal(draft.template.sections.filter(section => section.content_slot).map(section => section.content_slot), ['rich_text_1', 'rich_text_2', 'rich_text_3'], 'Starting copy uses the three generic posting slots');
		equal(defaultPreset(draft, 'reviews').data.layout, 'conversation', 'Initial selected proof style agrees with the draft preview');
		equal(defaultPreset(draft, 'credentials').data.layout, 'logo_rail', 'A new credential section has a visible starting appearance');
		draft.business.name = 'Changed draft';
		equal(supplied, original, 'Later wizard edits cannot change the source state through shared object references');
	});

	await scenario('First CTA validates link, phone, and required label', () => {
		const draft = onboarding.prepareDraft({ui: {cta_type: 'booking', cta_label: 'Book a consultation', cta_target: 'https://example.test/book'}});
		equal(onboarding.ctaIssue(draft), '', 'A complete booking link is accepted');
		onboarding.applyCTA(draft);
		equal(defaultPreset(draft, 'hero').data.buttons, [{label: 'Book a consultation', url: 'https://example.test/book'}], 'The first action belongs to the shared hero at the top of the page');
		for (const target of ['javascript:alert(1)', 'https://user:pass@example.test', 'ftp://example.test', '']) {
			draft.ui.cta_target = target;
			check(Boolean(onboarding.ctaIssue(draft)), 'An unusable CTA target requires correction: ' + target);
			onboarding.applyCTA(draft);
			equal(defaultPreset(draft, 'hero').data.buttons, [], 'Incomplete or unsafe target is not inserted into the draft document');
		}
		draft.ui.cta_type = 'phone'; draft.ui.cta_target = '+31 40 123 4567';
		equal(onboarding.ctaIssue(draft), '', 'A real-looking phone number is accepted');
		onboarding.applyCTA(draft);
		equal(defaultPreset(draft, 'hero').data.buttons[0].url, 'tel:+31401234567', 'The phone action uses a usable tel URL');
		draft.ui.cta_target = 'call me';
		check(Boolean(onboarding.ctaIssue(draft)), 'Plain text cannot become a phone action');
		draft.ui.cta_target = '+31 40 123 4567'; draft.ui.cta_label = '   ';
		check(Boolean(onboarding.ctaIssue(draft)), 'The first CTA needs a visible button label');
	});

	await scenario('Existing forms link to the real native section anchor', () => {
		const draft = onboarding.prepareDraft({ui: {cta_type: 'form', cta_label: 'Book your appointment', cta_target: '[contact-form-7 id="123"]'}});
		onboarding.applyCTA(draft);
		const form = defaultPreset(draft, 'custom_cta');
		const target = draft.template.sections.find(section => section.type === 'custom_cta' && section.shared_key === form.id);
		equal(form.data.shortcode, '[contact-form-7 id="123"]', 'A form shortcode stays separate from custom HTML');
		equal(form.data.html, '', 'Switching to a shortcode clears stale HTML');
		equal(target.id, 'booking', 'The setup owns an unambiguous form target section');
		check(target.enabled && form.enabled, 'The form target is enabled in both the layout and shared defaults');
		equal(defaultPreset(draft, 'hero').data.buttons[0].url, '#nova-service-' + target.id, 'The primary action matches the renderer’s DOM ID, including its native prefix');
		draft.ui.cta_target = '<form action="https://example.test/contact" method="post"><input name="name"></form>';
		onboarding.applyCTA(draft);
		equal(form.data.html, draft.ui.cta_target, 'Native form HTML can replace the shortcode');
		equal(form.data.shortcode, '', 'Switching to HTML clears stale shortcode content');
		equal(defaultPreset(draft, 'hero').data.buttons[0].url, '#nova-service-booking', 'Changing the form embed does not break its primary-action anchor');
		draft.ui.cta_target = '';
		check(Boolean(onboarding.ctaIssue(draft)), 'An empty form cannot satisfy the required action');
	});

	await scenario('Preview resolves draft defaults without saving example content', () => {
		const draft = onboarding.prepareDraft({business: {name: 'Example clinic'}, ui: {cta_type: 'form', cta_label: 'Book', cta_target: '[contact-form-7 id="123"]'}});
		onboarding.applyCTA(draft);
		const snapshot = copy(draft);
		const preview = onboarding.previewDocument(draft, true);
		equal(draft, snapshot, 'Generating a preview cannot change the autosaved or finished draft');
		check(preview.sections.every(section => !Object.hasOwn(section, 'shared_key')), 'Only the request-local preview copy contains resolved fields');
		equal(preview.sections.find(section => section.type === 'hero').data.buttons[0].url, '#nova-service-booking', 'The preview keeps the same usable form anchor');
		equal(preview.sections.find(section => section.type === 'custom_cta').id, 'booking', 'The preview keeps the form’s original section identity');
		equal(preview.sections.find(section => section.type === 'reviews').data.items[0].author, 'Example testimonial', 'Example social proof is clearly labeled');
		check(preview.sections.find(section => section.type === 'credentials').data.items[0].issuer.includes('Example'), 'Example credential issuer is clearly labeled');
		check(!(defaultPreset(draft, 'reviews').data.items || []).length, 'Example reviews are absent from the saved draft');
		check(!(defaultPreset(draft, 'credentials').data.items || []).length, 'Example credentials are absent from the saved draft');
		check(preview.sections.find(section => section.content_slot === 'rich_text_1').data.title !== '', 'Request-only service copy makes the style preview meaningful');
	});

	await scenario('Preview preserves deliberate local empties and source-link choices', () => {
		const draft = onboarding.prepareDraft({
			presets: {version: 2, sections: [{id: 'default_reviews', type: 'reviews', enabled: false, data: {items: [{body: 'Shared quote'}], link_sources: false}}]},
			template: {version: 2, sections: [{id: 'proof', type: 'reviews', enabled: true, shared_key: 'default_reviews', data: {items: []}}]},
		});
		const resolved = onboarding.previewDocument(draft, false).sections[0];
		equal(resolved.data.items, [], 'An explicit empty local collection overrides inherited cards');
		equal(resolved.data.link_sources, false, 'False source links inherit as a real boolean');
		equal(resolved.enabled, false, 'A disabled reusable section stays disabled when preview examples are off');
		const wrongType = {presets: {version: 2, sections: [{id: 'proof', type: 'credentials', enabled: true, data: {title: 'Wrong issuer'}}]}, template: {version: 2, sections: [{id: 'row', type: 'reviews', enabled: true, shared_key: 'proof', data: {body: 'Local copy'}}]}};
		check(!onboarding.previewDocument(wrongType, false).sections[0].data.title, 'A wrong-type reference cannot borrow another section’s content');
		equal(Object.keys(onboarding.reviewStyles).sort(), ['editorial', 'conversation', 'spotlight', 'publisher_cards', 'compact_rail', 'signature'].sort(), 'All supported testimonial appearances remain available');
		equal(Object.keys(onboarding.credentialStyles).sort(), ['logo_rail', 'featured_membership', 'editorial', 'credential_tiles', 'trust_band', 'evidence_accordion'].sort(), 'All supported credential appearances remain available');
	});

	await scenario('An explicitly requested sample walkthrough cannot save or finish', async () => {
		const view = fixture({classification: 'upgrade', demo: true, preview: true});
		const original = copy(view.config.state.draft);
		check(!view.button('Save draft'), 'An upgrade walkthrough offers no draft-save action');
		view.input('Business name', 'Read-only walkthrough edit');
		check(![...view.timers.values()].some(timer => timer.delay === 900), 'Walkthrough edits schedule no autosave');
		check(view.runTimer(550), 'A walkthrough can still request an unsaved preview');
		await flush();
		check(view.requests.every(request => request.url === view.config.previewUrl), 'The walkthrough calls only the read-only preview endpoint');
		equal(view.requests[0].body.mode, 'template', 'Preview uses the native renderer contract');
		equal(view.requests[0].body.post_id, 0, 'Preview uses a sample context rather than a staging page ID');
		view.respond(view.requests[0]); await flush();
		view.button(/Review and finish$/).fire('click');
		check(view.button('Preview only').disabled, 'Finish remains disabled for the existing installation');
		check(!view.navigationIsBlocked(), 'Read-only changes never trigger an unsaved-navigation warning');
		equal(view.config.state.draft, original, 'Walkthrough interaction preserves the original localized configuration');
	});

	await scenario('Existing Setup settings save business identity explicitly without modifying shared documents', async () => {
		const view = fixture({classification: 'upgrade', settings: true, language: 'de'}), original = copy(view.config.state.draft);
		check(view.root.textContent.includes('Your business'), 'The existing installation has editable business settings');
		check(!view.root.textContent.includes('Preview mode'), 'Real setup settings have no read-only preview banner');
		check(!view.button('Finish setup'), 'An existing installation is not sent through fresh-install activation');
		view.input('Business name', 'Updated real business'); view.input('Phone number', '+31 40 123 4567');
		equal(view.requests.length, 0, 'Existing settings are not activated while typing');
		equal(view.timers.size, 0, 'Existing settings do not schedule draft autosave or a second preview');
		check(view.navigationIsBlocked(), 'Business edits are protected until explicitly saved');
		view.button('Save business settings').fire('click');
		equal(view.requests[0].body.action, 'save_business', 'Business settings use the scoped business-only endpoint action');
		equal(view.requests[0].body.expected_revision, 0, 'The business save checks the current setup revision');
		equal(view.requests[0].body.expected_fingerprint, 'saved-configuration-fingerprint', 'A concurrent shared setting change is guarded by the canonical fingerprint');
		equal(view.requests[0].body.language, 'de', 'The save carries the selected multilingual context');
		equal(Object.keys(view.requests[0].body.draft), ['business'], 'The identity request contains neither presets nor a page template');
		equal(view.requests[0].body.draft.business.phone, '+31 40 123 4567', 'Phone details are editable alongside name and website');
		equal(view.config.state.draft, original, 'Opening and editing settings never mutates the localized shared document');
		check(view.button('Save business settings').disabled, 'Business saves prevent a duplicate write');
		view.respond(view.requests[0], {data: {...view.config.state, revision: 1, fingerprint: 'after-business-save', draft: {business: {...view.requests[0].body.draft.business, name: 'Sanitized business name'}}}}); await flush();
		check(!view.navigationIsBlocked(), 'The acknowledged business settings can be left safely');
		check(view.root.textContent.includes('Business settings saved'), 'Successful saving is explained within Setup settings');
		equal(view.field('Business name').value, 'Sanitized business name', 'Canonical saved business fields replace unsanitized local text');
		equal(view.settingsFingerprint.value, 'after-business-save', 'The shared-content form accepts the business save from this same settings screen');
		view.input('Website', 'https://changed.example.test'); view.button('Save business settings').fire('click');
		equal(view.requests[1].body.expected_revision, 1, 'Later edits use the new acknowledged setup revision');
		equal(view.requests[1].body.expected_fingerprint, 'after-business-save', 'Later identity edits use the newly acknowledged settings fingerprint');
		view.respond(view.requests[1]); await flush();
	});

	await scenario('Existing business settings retain local work on validation and concurrent changes', async () => {
		const view = fixture({classification: 'upgrade', settings: true});
		view.input('Website', 'https://'); view.button('Save business settings').fire('click');
		view.respond(view.requests[0], {status: 400, data: {message: 'Use a valid business website URL.'}}); await flush();
		check(view.root.textContent.includes('Use a valid business website URL.'), 'Explicit saving explains invalid business fields');
		check(!view.button('Save business settings').disabled, 'A validation error allows a corrected retry');
		view.input('Website', 'https://corrected.example.test'); view.button('Save business settings').fire('click');
		view.respond(view.requests[1], {status: 409, data: {message: 'Settings changed in another tab. Reload to continue.'}}); await flush();
		check(view.button('Save business settings').disabled, 'A stale baseline cannot overwrite another settings session');
		check(view.navigationIsBlocked(), 'Uncommitted business edits are retained and protected after a conflict');
		equal(view.field('Website').value, 'https://corrected.example.test', 'The local correction remains visible after a concurrent edit');
		view.button('Save business settings').fire('click');
		equal(view.requests.length, 2, 'A conflict stops repeated writes with the old revision');
	});

	await scenario('Enter within business fields saves only the identity and cannot submit the shared library', async () => {
		const view = fixture({settings: true});
		view.input('Business name', 'A new name');
		let prevented = false;
		view.field('Business name').fire('keydown', {key: 'Enter', preventDefault: () => { prevented = true; }});
		check(prevented, 'Enter prevents an implicit parent WordPress form submit');
		equal(view.requests[0].body.action, 'save_business', 'Enter uses the same explicit scoped business save');
		view.respond(view.requests[0]); await flush();
		view.button('Save business settings').fire('click');
		equal(view.requests.length, 1, 'Saving unchanged business settings produces no redundant write');
	});

	await scenario('Business settings use the language in the real localized setup state', async () => {
		const view = fixture({settings: true, stateLanguageOnly: true, language: 'fr'});
		view.input('Business name', 'French context business'); view.button('Save business settings').fire('click');
		equal(view.requests[0].body.language, 'fr', 'Selected settings language survives when it is localized only inside state');
		view.respond(view.requests[0]); await flush();
		check(!view.navigationIsBlocked(), 'Saving through the state language receives the same navigation protection');
	});

	await scenario('The reopened five-screen setup reads canonical content without seeding defaults', () => {
		const supplied = {business: {name: 'Existing business'}, presets: {version: 2, sections: [{id: 'default_hero', type: 'hero', enabled: true, data: {buttons: [{label: 'Current contact', url: '/contact/', target: '_blank'}, {label: 'Secondary action', url: '/about/', variant: 'quiet'}]}}, {id: 'other_variant', type: 'reviews', enabled: false, data: {items: [{body: '<p>Original quote</p>', author: 'Customer', image: 'https://example.test/customer.jpg', rating: 5}]}}]}, template: {version: 2, sections: []}, ui: {cta_type: 'booking', cta_label: 'Obsolete label', cta_target: 'https://example.test/obsolete'}};
		const original = copy(supplied), draft = onboarding.prepareDraft(supplied, true);
		equal(draft.ui.cta_label, 'Current contact', 'Reopened setup uses the actual primary hero action, not an obsolete setup UI cache');
		equal(draft.ui.cta_target, '/contact/', 'An existing relative target remains visible as its actual saved value');
		equal(onboarding.ctaIssue(draft, true), '', 'Existing same-site relative actions are accepted');
		equal(draft.template, original.template, 'An empty saved page template is not replaced while reopening setup');
		check(!defaultPreset(draft, 'reviews').data.layout && !defaultPreset(draft, 'credentials').data.layout, 'Existing inherited style choices are not seeded into shared content');
		const payload = onboarding.settingsPayload(draft, original);
		equal(payload.presets, original.presets, 'Display placeholders are removed from an unrelated settings payload');
		equal(payload.business, original.business, 'Empty display-only business fields are not added to an existing identity');
		equal(supplied, original, 'Reopening retains the original serialized document and every variant');
		const fallback = {version: 2, sections: [{id: 'hero', type: 'hero', enabled: true, shared_key: 'default_hero', data: {}}]};
		check(onboarding.previewDocument(draft, true, fallback).sections.length === 1, 'An empty saved layout can still show a request-local fallback preview');
		equal(draft.template, original.template, 'The fallback preview never fills the saved template');
	});

	await scenario('Completed installations reopen the same five screens and save only explicitly', async () => {
		const view = fixture({wizardSettings: true, classification: 'upgrade', step: 'review', language: 'de'}), original = copy(view.config.state.draft);
		equal(view.field('Business name').value, 'Example clinic', 'Settings reopen on the first screen instead of a completion or reduced business form');
		equal(view.root.querySelectorAll('nav')[0].querySelectorAll('button').map(item => item.children[1].textContent), ['Your business', 'First call to action', 'People and proof', 'Visual style', 'Review and finish'], 'The five original setup screens remain available');
		check(!view.button('Save draft') && !view.root.textContent.includes('Preview mode'), 'Completed settings have neither draft autosave action nor a read-only demo');
		view.button(/People and proof$/).fire('click');
		check(view.root.querySelectorAll('a').some(link => link.textContent === 'Advanced shared content settings'), 'Imports and detailed reusable variants remain reachable from People and proof');
		view.button(/Visual style$/).fire('click'); view.button(/Review and finish$/).fire('click');
		view.button('Save settings').fire('click');
		equal(view.requests.length, 0, 'Inspecting every screen and saving unchanged settings performs no write');
		check(!view.navigationIsBlocked(), 'Screen navigation alone does not dirty settings');
		view.button(/Your business$/).fire('click'); view.input('Business name', 'Updated real business');
		check(![...view.timers.values()].some(timer => timer.delay === 900), 'Typing in reopened setup schedules no live autosave');
		check(view.navigationIsBlocked(), 'Explicit settings changes remain protected until saved');
		view.button(/Review and finish$/).fire('click'); view.button('Save settings').fire('click');
		equal(view.requests[0].body.action, 'save_settings', 'The final action saves existing settings rather than activating a fresh setup');
		equal(view.requests[0].body.language, 'de', 'The settings save keeps its selected language scope');
		equal(view.requests[0].body.expected_fingerprint, 'saved-configuration-fingerprint', 'The canonical shared fingerprint guards concurrent edits');
		equal(view.requests[0].body.draft.presets, original.presets, 'Changing identity preserves the full shared library');
		equal(view.requests[0].body.draft.template, original.template, 'Changing identity preserves the original page layout');
		check(view.button('Saving settings…').disabled, 'An in-flight settings save freezes the final action');
		view.respond(view.requests[0], {data: {...view.config.state, revision: 1, fingerprint: 'next-fingerprint', draft: view.requests[0].body.draft}}); await flush();
		check(view.button('Save settings') && !view.navigationIsBlocked(), 'A completed save stays in the five-screen editor and acknowledges the saved baseline');
		view.button('Save settings').fire('click'); equal(view.requests.length, 1, 'A repeated unchanged Save settings makes no redundant request');
		equal(view.config.state.draft, original, 'Local changes never mutate the original localized state');
	});

	await scenario('Direct navigation does not require existing pages to re-enter mandatory setup content', () => {
		const view = fixture({wizardSettings: true, draft: {business: {name: ''}, presets: {version: 2, sections: []}, template: {version: 2, sections: []}}, initialStep: 'cta'});
		check(view.root.textContent.includes('Give visitors a clear next step'), 'A direct CTA settings link opens the requested second screen');
		view.button(/Review and finish$/).fire('click'); check(view.button('Save settings'), 'An existing incomplete CTA does not block inspecting other setup screens');
		view.button('Save settings').fire('click'); equal(view.requests.length, 0, 'An unchanged incomplete historical setup is not rewritten or reactivated');
	});

	await scenario('A raw unconfigured upgrade template stays an array in the real settings JSON payload', async () => {
		const view = fixture({wizardSettings: true, draft: {business: {name: 'Upgrade business'}, presets: {version: 2, sections: []}, template: []}});
		view.input('Business name', 'Updated upgrade business'); view.button(/Review and finish$/).fire('click'); view.button('Save settings').fire('click');
		equal(view.requests[0].body.draft.template, [], 'A business-only wire payload retains the actual empty-array template storage shape');
		equal(view.requests[0].body.draft.presets.sections, [], 'Display-only reserved sections are not persisted during an upgrade business edit');
		view.respond(view.requests[0]); await flush();
		check(!view.navigationIsBlocked(), 'The array template baseline is acknowledged without generating a hidden layout');
	});

	await scenario('Primary and extra CTA edits retain secondary buttons, form metadata and stable form anchors', () => {
		const supplied = {business: {name: 'Existing business'}, presets: {version: 2, sections: [{id: 'default_hero', type: 'hero', enabled: true, data: {buttons: [{label: 'Contact', url: '#nova-service-existing-form', target: '_self'}, {label: 'Phone', url: 'tel:+31401234567', variant: 'secondary'}]}}, {id: 'default_custom_cta', type: 'custom_cta', enabled: true, data: {shortcode: '[form id="7"]', html: '<p>Keep the existing HTML</p>', title: 'Existing form'}}, {id: 'default_cta', type: 'cta', enabled: true, data: {buttons: [{label: 'Existing primary', url: '/primary/'}, {label: 'Existing secondary', url: '/secondary/', variant: 'quiet'}]}}]}, template: {version: 2, sections: [{id: 'existing-form', type: 'custom_cta', enabled: true, shared_key: 'default_custom_cta', data: {}}]}};
		const draft = onboarding.prepareDraft(supplied, true), oldCustom = copy(defaultPreset(draft, 'custom_cta')), oldTemplate = copy(draft.template);
		equal(draft.ui.cta_type, 'form', 'The existing form anchor is recognized without renaming its layout section');
		draft.ui.cta_label = 'Updated label'; onboarding.applyCTA(draft, true, false);
		equal(defaultPreset(draft, 'hero').data.buttons[1], supplied.presets.sections[0].data.buttons[1], 'Updating the primary CTA preserves secondary actions and their metadata');
		equal(defaultPreset(draft, 'hero').data.buttons[0].target, '_self', 'Primary CTA metadata is retained while label and destination are edited');
		equal(defaultPreset(draft, 'custom_cta'), oldCustom, 'A form button label edit does not overwrite untouched shortcode and HTML fields');
		equal(draft.template, oldTemplate, 'An existing form section keeps its original stable ID and complete layout');
		const disabled = copy(supplied); defaultPreset(disabled, 'custom_cta').enabled = false; disabled.template.sections[0].enabled = false;
		const disabledDraft = onboarding.prepareDraft(disabled, true); disabledDraft.ui.cta_label = 'Updated disabled form label'; onboarding.applyCTA(disabledDraft, true, false);
		check(defaultPreset(disabledDraft, 'custom_cta').enabled === false && disabledDraft.template.sections[0].enabled === false, 'A label-only edit never reactivates an existing disabled shared form or layout section');
		equal(defaultPreset(disabledDraft, 'custom_cta').data, defaultPreset(disabled, 'custom_cta').data, 'The disabled form HTML, shortcode and metadata remain untouched when only its button label changes');
		draft.ui.cta_type = 'url'; draft.ui.cta_target = '/contact/'; onboarding.applyCTA(draft, true);
		check(defaultPreset(draft, 'custom_cta').enabled, 'Switching the primary CTA to a URL does not disable an independently used form');
		onboarding.replaceFirstButton(defaultPreset(draft, 'cta').data, {label: 'New extra label', url: '/primary/'});
		equal(defaultPreset(draft, 'cta').data.buttons[1], supplied.presets.sections[2].data.buttons[1], 'Extra CTA editing also retains every secondary button');
		const absent = onboarding.prepareDraft({presets: {version: 2, sections: []}, template: {version: 2, sections: []}}, true); absent.ui.cta_type = 'form'; absent.ui.cta_label = 'Book'; absent.ui.cta_target = '[form id="1"]';
		check(onboarding.applyCTA(absent, true).includes('Page defaults'), 'A missing historical form layout requires an explicit section addition rather than silently rewriting the template');
		equal(absent.template.sections, [], 'No replacement form section is generated behind the scenes');
	});

	await scenario('People and proof edits target the office map and preserve untouched review details', async () => {
		const draft = onboarding.prepareDraft({business: {name: 'Actual business'}}); onboarding.applyCTA(Object.assign(draft, {ui: {...draft.ui, cta_label: 'Contact', cta_target: 'https://example.test/contact'}}));
		defaultPreset(draft, 'reviews').data = {title: 'Real testimonials', body: '<p>Keep original introduction</p>', review_source: 'https://example.test/profile', items: [{body: '<p>Original customer story</p>', author: 'Real customer', rating: 5, avatar: 'https://example.test/avatar.jpg', source_logo: 'https://example.test/logo.svg', company: 'Original company'}]};
		defaultPreset(draft, 'location').data = {title: 'Office', body: '<p>Original office text</p>', map_url: 'https://www.google.com/maps?original', images: [{id: 12, url: 'https://example.test/office.jpg', alt: 'Original office'}], image_selection: 'one_per_page'};
		defaultPreset(draft, 'map').data.map_url = 'https://www.google.com/maps?standalone';
		const view = fixture({wizardSettings: true, draft}), original = copy(view.config.state.draft); view.button(/People and proof$/).fire('click');
		equal(view.field('Google Maps embed URL (optional)').value, 'https://www.google.com/maps?original', 'Office setup displays the map belonging to the office section');
		view.input('Office introduction (optional)', '<p>Updated office introduction</p>'); view.input('Google Maps embed URL (optional)', 'https://www.google.com/maps?updated');
		view.button(/Review and finish$/).fire('click'); view.button('Save settings').fire('click');
		const sent = view.requests[0].body.draft;
		equal(defaultPreset(sent, 'location').data.map_url, 'https://www.google.com/maps?updated', 'Editing the office embed targets the visible location section');
		equal(defaultPreset(sent, 'map'), defaultPreset(original, 'map'), 'A separate existing map remains unchanged');
		equal(defaultPreset(sent, 'reviews'), defaultPreset(original, 'reviews'), 'The untouched review profile, introduction, images and metadata remain exact');
		equal(defaultPreset(sent, 'location').data.images, defaultPreset(original, 'location').data.images, 'Editing office text and map preserves the complete photo pool');
		equal(defaultPreset(sent, 'location').data.image_selection, 'one_per_page', 'Existing office photo selection behavior is preserved');
		view.respond(view.requests[0]); await flush();
	});

	await scenario('Settings conflicts retain edits and translated presentation stays shared', async () => {
		const translated = fixture({wizardSettings: true, translated: true}); translated.button(/Visual style$/).fire('click');
		check(translated.button('Conversation').disabled && translated.root.textContent.includes('shared across languages'), 'Translated sites show shared style choices without writing language-specific styling');
		translated.button(/People and proof$/).fire('click'); const sourceLinks = translated.root.querySelectorAll('input').filter(input => input.type === 'checkbox');
		check(sourceLinks.length === 2 && sourceLinks.every(input => input.disabled), 'Review and credential source-link visibility is also inherited rather than editable per language');
		const view = fixture({wizardSettings: true}); view.input('Business name', 'Unsaved local business'); view.button(/Review and finish$/).fire('click'); view.button('Save settings').fire('click');
		view.respond(view.requests[0], {status: 409, data: {message: 'Settings changed in another tab. Reload to continue.'}}); await flush();
		check(view.button('Save settings').disabled, 'A fingerprint conflict prevents another stale write');
		view.button(/Your business$/).fire('click'); equal(view.field('Business name').value, 'Unsaved local business', 'Concurrent updates retain the local work for review');
		check(view.navigationIsBlocked(), 'Uncommitted settings remain protected after a conflict');
	});

	await scenario('Fresh edits autosave only a draft and resume with the current revision', async () => {
		const view = fixture();
		equal(view.requests.length, 0, 'Opening setup makes no settings request');
		view.input('Business name', 'Updated example clinic');
		equal(view.requests.length, 0, 'Typing is debounced instead of immediately posting');
		check(view.navigationIsBlocked(), 'A genuinely unsaved edit warns before navigation');
		check(view.runTimer(900), 'The pending edit schedules a draft save');
		equal(view.requests[0].body.action, 'save', 'Autosave uses the draft-only action');
		equal(view.requests[0].body.expected_revision, 0, 'The first save uses the current server revision');
		equal(view.requests[0].body.draft.business.name, 'Updated example clinic', 'Autosave sends the latest business text');
		check(!view.requests.some(request => request.body.action === 'finish'), 'Autosave cannot activate defaults');
		view.respond(view.requests[0]); await flush();
		check(!view.navigationIsBlocked(), 'A saved edit can leave without an unsaved warning');
		view.button(/Review and finish$/).fire('click'); view.button('Finish setup').fire('click'); await flush();
		equal(view.requests[1].body.action, 'save', 'Moving to review drains the newest step/draft before Finish');
		equal(view.requests[1].body.expected_revision, 1, 'The newer draft uses the acknowledged revision');
		view.respond(view.requests[1]); await flush();
		equal(view.requests[2].body.action, 'finish', 'Activation is an explicit separate action');
		equal(view.requests[2].body.expected_revision, 2, 'Finish uses the revision after the latest draft save');
		view.respond(view.requests[2]); await flush();
		check(view.root.textContent.includes('Your service pages are ready'), 'Successful Finish shows completion');
	});

	await scenario('Finish freezes editing, prevents duplicate activation and allows failure retry', async () => {
		const view = fixture({step: 'review'});
		const finish = view.button('Finish setup'); finish.fire('click');
		check(finish.disabled, 'Finish disables itself during activation');
		equal(finish.textContent, 'Finishing…', 'Activation progress is visible');
		check(view.root.querySelectorAll('button, input, select, textarea').every(control => control.disabled), 'Inputs and navigation freeze with the final approved snapshot');
		check(view.navigationIsBlocked(), 'Leaving during activation asks the user to wait');
		await flush();
		equal(view.requests.length, 1, 'An unchanged resumed draft needs no redundant draft save');
		equal(view.requests[0].body.action, 'finish', 'Only explicit activation is sent');
		finish.fire('click');
		equal(view.requests.length, 1, 'A double click cannot activate twice');
		view.respond(view.requests[0], {status: 500, data: {message: 'Please retry setup'}}); await flush();
		check(!view.button('Finish setup').disabled, 'A failed activation restores the Finish action');
		check(view.root.textContent.includes('Please retry setup'), 'The failure explains the next action');
		check(view.root.querySelectorAll('button').every(control => !control.disabled), 'Back and step navigation are restored after failure');
	});

	await scenario('Pending old save drains newer edits before Finish without a competing autosave', async () => {
		const view = fixture();
		view.input('Business name', 'Latest approved clinic'); view.runTimer(900);
		equal(view.requests[0].body.action, 'save', 'The old draft save is in flight');
		view.button(/Review and finish$/).fire('click'); view.button('Finish setup').fire('click');
		check(view.root.querySelectorAll('button, input, select, textarea').every(control => control.disabled), 'Controls freeze while the old request is pending');
		check(![...view.timers.values()].some(timer => timer.delay === 900), 'Finish clears the newly scheduled autosave');
		view.respond(view.requests[0]); await flush();
		equal(view.requests[1].body.action, 'save', 'The newer draft drains serially after the old save');
		equal(view.requests[1].body.expected_revision, 1, 'The drain uses the acknowledged revision');
		check(![...view.timers.values()].some(timer => [100, 900].includes(timer.delay)), 'Finishing does not rearm either autosave timer');
		view.respond(view.requests[1]); await flush();
		equal(view.requests[2].body.action, 'finish', 'Finish follows both draft saves');
		equal(view.requests[2].body.expected_revision, 2, 'No activation/save race shares the same expected revision');
		equal(view.requests[2].body.draft.business.name, 'Latest approved clinic', 'The final activation includes the approved text');
		view.respond(view.requests[2]); await flush();
		check(view.root.textContent.includes('Your service pages are ready'), 'One successful activation ends setup');
		equal(view.requests.filter(request => request.body.action === 'finish').length, 1, 'Exactly one Finish request was sent');
	});

	await scenario('A stale setup revision keeps local work and stops further conflicting saves', async () => {
		const view = fixture(); view.input('Business name', 'Unsaved local clinic'); view.runTimer(900);
		view.respond(view.requests[0], {status: 409, data: {message: 'Setup changed in another session. Reload to continue with the saved progress.'}}); await flush();
		check(view.root.textContent.includes('Setup changed in another session'), 'A concurrent edit is explained');
		view.input('Business name', 'Still retained locally');
		check(![...view.timers.values()].some(timer => timer.delay === 900), 'A detected conflict stops repeated autosave requests');
		view.button('Save draft').fire('click'); await flush();
		equal(view.requests.length, 1, 'Save does not overwrite another session after a conflict');
		check(view.navigationIsBlocked(), 'Unsaved local work still receives navigation protection');
		equal(view.field('Business name').value, 'Still retained locally', 'The local draft remains available for review');
	});

	await scenario('Incomplete fields keep typing quiet while explicit Save explains validation', async () => {
		const view = fixture(); view.input('Website', 'https://'); view.runTimer(900);
		view.respond(view.requests[0], {status: 400, data: {message: 'Use a valid business website URL.'}}); await flush();
		check(view.root.textContent.includes('Complete this field to save your draft'), 'An incomplete autosaved field receives quiet status feedback');
		check(!view.root.textContent.includes('Use a valid business website URL.'), 'Typing is not interrupted by a full validation alert');
		view.button('Save draft').fire('click');
		view.respond(view.requests[1], {status: 400, data: {message: 'Use a valid business website URL.'}}); await flush();
		check(view.root.textContent.includes('Use a valid business website URL.'), 'Explicit Save explains the validation issue');
	});

	await scenario('A slower old preview cannot replace newer unsaved content', async () => {
		const view = fixture({demo: true, preview: true}); view.runTimer(550);
		const oldPreview = view.requests[0];
		view.input('Business name', 'New preview name'); view.runTimer(550);
		const newPreview = view.requests[1];
		check(oldPreview.options.signal.aborted, 'A newer preview aborts the older request');
		view.respond(newPreview, {data: {html: '<p>New preview</p>'}}); await flush();
		equal(view.root.querySelector('iframe').srcdoc, '<p>New preview</p>', 'The newest preview becomes visible');
		view.respond(oldPreview, {data: {html: '<p>Stale preview</p>'}}); await flush();
		equal(view.root.querySelector('iframe').srcdoc, '<p>New preview</p>', 'A late stale response is ignored even if abort could not cancel it');
		check(view.requests.every(request => request.url === view.config.previewUrl), 'Preview requests never use the onboarding save endpoint');
	});

	console.log(`Service onboarding UI: ${checks} checks passed across ${cases.length} behavior scenarios.`);
}

main().catch(error => { console.error(error); process.exitCode = 1; });
