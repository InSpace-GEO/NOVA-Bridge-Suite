(function (window, document) {
	'use strict';
	var stepIds = ['business', 'cta', 'content', 'style', 'review'];
	var reviewStyles = {editorial: 'Editorial', conversation: 'Conversation', spotlight: 'Spotlight', publisher_cards: 'Publisher cards', compact_rail: 'Compact rail', signature: 'Signature'};
	var credentialStyles = {logo_rail: 'Logo rail', featured_membership: 'Featured membership', editorial: 'Editorial', credential_tiles: 'Credential tiles', trust_band: 'Trust band', evidence_accordion: 'Evidence accordion'};
	function clone(value) { return JSON.parse(JSON.stringify(value)); }
	function preset(draft, type) {
		var found = draft.presets.sections.find(function (section) { return section.id === 'default_' + type && section.type === type; });
		if (!found) { found = {id: 'default_' + type, type: type, enabled: true, data: {}}; draft.presets.sections.push(found); }
		return found;
	}
	function prepareDraft(value) {
		var draft = clone(value || {});
		draft.business = Object.assign({name: '', website: '', phone: ''}, draft.business || {});
		draft.presets = draft.presets && Array.isArray(draft.presets.sections) ? draft.presets : {version: 2, sections: []};
		draft.template = draft.template && Array.isArray(draft.template.sections) ? draft.template : {version: 2, sections: []};
		draft.ui = Object.assign({cta_type: 'booking', cta_label: '', cta_target: '', style: 'editorial', optional_panels: []}, draft.ui || {});
		if (!Array.isArray(draft.ui.optional_panels)) { draft.ui.optional_panels = []; }
		['hero', 'cta', 'custom_cta', 'reviews', 'credentials', 'people', 'location', 'map', 'video'].forEach(function (type) { preset(draft, type); });
		if (!draft.template.sections.length) {
			draft.template.sections = [{id: 'hero', type: 'hero', enabled: true, shared_key: 'default_hero', data: {title: '', body: ''}}];
			['rich_text_1', 'rich_text_2', 'rich_text_3'].forEach(function (slot) { draft.template.sections.push({id: slot, type: 'rich_text', content_slot: slot, enabled: true, data: {title: '', body: ''}}); });
			['people', 'reviews', 'credentials', 'location', 'map', 'cta', 'custom_cta', 'video'].forEach(function (type) { draft.template.sections.push({id: type, type: type, enabled: true, shared_key: 'default_' + type, data: {}}); });
			draft.template.sections.push({id: 'related', type: 'related', enabled: true, data: {}}, {id: 'faq', type: 'faq', enabled: false, data: {}});
		}
		var firstButton = (preset(draft, 'hero').data.buttons || [])[0];
		if (!draft.ui.cta_label && firstButton) { draft.ui.cta_label = firstButton.label || ''; }
		if (!draft.ui.cta_target && firstButton) { draft.ui.cta_target = firstButton.url || ''; if (/^tel:/i.test(draft.ui.cta_target)) { draft.ui.cta_type = 'phone'; draft.ui.cta_target = draft.ui.cta_target.replace(/^tel:/i, ''); } }
		if (!reviewStyles[draft.ui.style]) { draft.ui.style = 'editorial'; }
		if (!preset(draft, 'reviews').data.layout) { preset(draft, 'reviews').data.layout = draft.ui.style; }
		if (!preset(draft, 'credentials').data.layout) { preset(draft, 'credentials').data.layout = 'logo_rail'; }
		return draft;
	}
	function applyCTA(draft) {
		var type = draft.ui.cta_type, target = draft.ui.cta_target.trim(), label = draft.ui.cta_label.trim();
		var custom = preset(draft, 'custom_cta');
		if (type === 'phone') { target = 'tel:' + target.replace(/^tel:/i, '').replace(/[\s().-]/g, ''); }
		if (type === 'form') {
			var isShortcode = /^\s*\[[a-zA-Z0-9_-]+\b/.test(target);
			custom.data.shortcode = isShortcode ? target : ''; custom.data.html = isShortcode ? '' : target; custom.enabled = true;
			var formSection = draft.template.sections.find(function (section) { return section.type === 'custom_cta' && section.shared_key === 'default_custom_cta'; });
			if (formSection) { formSection.id = 'booking'; formSection.enabled = true; }
			target = '#nova-service-booking';
		}
		preset(draft, 'hero').data.buttons = !ctaIssue(draft) && label && target ? [{label: label, url: target}] : [];
	}
	function ctaIssue(draft) {
		if (!draft.ui.cta_label.trim()) { return 'Give your first button a clear label.'; }
		var target = draft.ui.cta_target.trim();
		if (draft.ui.cta_type === 'form') { return target ? '' : 'Paste your form shortcode or embed code.'; }
		if (draft.ui.cta_type === 'phone') { return /^\+?[0-9][0-9\s().-]{5,}$/.test(target.replace(/^tel:/i, '')) ? '' : 'Enter the phone number visitors should call.'; }
		try { var parsed = new URL(target); if (['https:', 'http:'].indexOf(parsed.protocol) >= 0 && !parsed.username && !parsed.password) { return ''; } } catch (error) { /* The message below explains the fix. */ }
		return 'Enter the complete booking or contact URL, starting with https://.';
	}
	function previewDocument(draft, examples) {
		var byId = {};
		draft.presets.sections.forEach(function (section) { byId[section.id] = section; });
		var candidate = clone(draft.template);
		candidate.sections.forEach(function (section) {
			var shared = byId[section.shared_key];
			if (shared && shared.type === section.type) { section.data = Object.assign({}, clone(shared.data), section.data || {}); section.enabled = section.enabled !== false && shared.enabled !== false; }
			delete section.shared_key;
			if (!examples) { return; }
			if (section.type === 'hero') { section.data.title = section.data.title || 'A service page for ' + (draft.business.name || 'your business'); section.data.body = section.data.body || '<p>Explain the value of your service and give visitors a clear next step.</p>'; }
			if (section.type === 'rich_text' && section.content_slot === 'rich_text_1') { section.data.title = section.data.title || 'Your service, clearly explained'; section.data.body = section.data.body || '<p>Your page-specific content goes here. Shared calls to action, people and proof are already in place.</p>'; }
			if (section.type === 'reviews' && !(section.data.items || []).length && !section.data.review_source) { section.enabled = true; section.data.items = [{body: 'A real customer experience belongs here. This example only helps you choose a style.', author: 'Example testimonial', source: 'Your customer'}]; }
			if (section.type === 'credentials' && !(section.data.items || []).length) { section.enabled = true; section.data.items = [{title: 'Your certification or membership', issuer: 'Example issuer', body: 'Add your own earned qualification and issuer logo.'}]; }
		});
		return candidate;
	}
	if (typeof module !== 'undefined' && module.exports) { module.exports = {prepareDraft: prepareDraft, applyCTA: applyCTA, ctaIssue: ctaIssue, previewDocument: previewDocument, reviewStyles: reviewStyles, credentialStyles: credentialStyles}; }
	if (!window || !document) { return; }
	var config = window.novaServiceOnboarding || {}, root = document.getElementById('nova-service-onboarding');
	if (!root) { return; }
	var state = config.state || {}, draft = prepareDraft(state.draft), demo = config.demo === true || state.classification === 'upgrade';
	var step = Math.max(0, stepIds.indexOf(state.step)), version = 0, savedVersion = 0, saveTimer, previewTimer, saving = null, saveAgain = false, conflict = false, finishing = false, previewController, previewSequence = 0;
	var content, progress, message, saveStatus, next, back, previewFrame, previewStatus, previewNote;
	function node(tag, className, text) { var item = document.createElement(tag); if (className) { item.className = className; } if (text !== undefined) { item.textContent = text; } return item; }
	function button(text, action, className) { var item = node('button', className || 'nova-setup-button', text); item.type = 'button'; item.addEventListener('click', action); return item; }
	function announce(text, error) { message.textContent = text || ''; message.classList.toggle('is-error', Boolean(error)); message.setAttribute('role', error ? 'alert' : 'status'); }
	function request(url, body, signal) {
		return window.fetch(url, {method: body ? 'POST' : 'GET', credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce || config.restNonce}, body: body ? JSON.stringify(body) : undefined, signal: signal}).then(function (response) {
			return response.json().then(function (data) { if (!response.ok) { var error = new Error(data.message || 'This could not be saved. Please try again.'); error.status = response.status; throw error; } return data; });
		});
	}
	function updateSaveStatus(text) { if (saveStatus) { saveStatus.textContent = text || (demo ? 'Preview only · nothing is saved' : version === savedVersion ? 'Draft saved' : 'Saving your draft…'); saveStatus.classList.toggle('is-saved', !text && !demo && version === savedVersion); } }
	function changed() {
		if (finishing) { return; }
		version += 1; updateSaveStatus(); window.clearTimeout(saveTimer);
		if (!demo && !conflict) { saveTimer = window.setTimeout(function () { saveDraft(true).catch(function () {}); }, 900); }
		queuePreview();
	}
	function saveDraft(quiet) {
		window.clearTimeout(saveTimer);
		if (demo) { return Promise.resolve(state); }
		if (conflict) { return Promise.reject(new Error('Setup changed in another tab. Reload to continue with the latest saved draft.')); }
		if (saving) { saveAgain = true; return saving.then(function () { return version !== savedVersion ? saveDraft(quiet) : state; }).catch(function (error) { if (!quiet) { announce(error.message, true); } throw error; }); }
		if (version === savedVersion) { return Promise.resolve(state); }
		var sentVersion = version, payload = clone(draft);
		updateSaveStatus('Saving your draft…');
		saving = request(config.endpoint || config.restUrl, {action: 'save', expected_revision: state.revision || 0, step: stepIds[step], draft: payload}).then(function (result) {
			state = result.state || result; savedVersion = sentVersion; updateSaveStatus(); return state;
		}).catch(function (error) {
			if (error.status === 409) { conflict = true; }
			updateSaveStatus(quiet && error.status === 400 ? 'Complete this field to save your draft' : 'Draft not saved'); if (!quiet || error.status !== 400) { announce(error.message, true); } throw error;
		}).finally(function () { saving = null; if (saveAgain && !conflict && !finishing) { saveAgain = false; if (version !== savedVersion) { saveTimer = window.setTimeout(function () { saveDraft(true).catch(function () {}); }, 100); } } });
		return saving;
	}
	function queuePreview() { window.clearTimeout(previewTimer); previewTimer = window.setTimeout(refreshPreview, 550); }
	function refreshPreview() {
		if (!config.previewUrl && !config.editorPreviewUrl) { previewStatus.textContent = 'Preview becomes available when the editor is connected.'; return; }
		if (previewController) { previewController.abort(); }
		previewController = typeof window.AbortController === 'function' ? new window.AbortController() : null;
		var sequence = ++previewSequence;
		previewStatus.textContent = 'Updating preview…';
		request(config.previewUrl || config.editorPreviewUrl, {mode: 'template', post_id: 0, title: draft.business.name ? draft.business.name + ' · service preview' : 'Your service preview', document: previewDocument(draft, true)}, previewController && previewController.signal).then(function (result) {
			if (sequence !== previewSequence) { return; }
			previewFrame.srcdoc = result.html || ''; previewStatus.textContent = 'Live preview';
		}).catch(function (error) { if (error.name !== 'AbortError' && sequence === previewSequence) { previewStatus.textContent = 'Preview could not load. Your draft is kept.'; } });
	}
	function field(parent, labelText, value, setter, options) {
		options = options || {};
		var label = node('label', 'nova-setup-field'), caption = node('span', 'nova-setup-field-label', labelText), control;
		label.appendChild(caption);
		if (options.choices) { control = node('select'); Object.keys(options.choices).forEach(function (key) { var choice = node('option', '', options.choices[key]); choice.value = key; choice.defaultSelected = key === value; control.appendChild(choice); }); }
		else { control = node(options.multiline ? 'textarea' : 'input'); if (options.multiline) { control.rows = options.rows || 3; } else { control.type = options.type || 'text'; } }
		control.value = value === undefined || value === null ? '' : value; control.autocomplete = options.autocomplete || 'off';
		if (options.placeholder) { control.placeholder = options.placeholder; } if (options.required) { control.required = true; }
		control.addEventListener(options.choices ? 'change' : 'input', function () { setter(control.value); changed(); });
		label.appendChild(control); if (options.help) { label.appendChild(node('small', 'nova-setup-help', options.help)); } parent.appendChild(label); return control;
	}
	function checkbox(parent, labelText, value, setter, help) {
		var label = node('label', 'nova-setup-check'), input = node('input'); input.type = 'checkbox'; input.checked = value !== false;
		input.addEventListener('change', function () { setter(input.checked); changed(); }); label.appendChild(input); label.appendChild(node('span', '', labelText)); parent.appendChild(label);
		if (help) { parent.appendChild(node('p', 'nova-setup-help', help)); }
	}
	function media(parent, labelText, value, setter, multiple) {
		var group = node('div', 'nova-setup-media'), list = Array.isArray(value) ? value : value ? [{url: value}] : [];
		group.appendChild(node('span', 'nova-setup-field-label', labelText));
		var images = node('div', 'nova-setup-media-images');
		list.forEach(function (image) { var picture = node('img'); picture.src = image.url; picture.alt = image.alt || ''; images.appendChild(picture); }); group.appendChild(images);
		group.appendChild(button(list.length ? 'Change images' : multiple ? 'Choose images' : 'Choose logo or image', function () {
			if (!window.wp || !window.wp.media) { announce('The WordPress media library is unavailable. Reload this screen and try again.', true); return; }
			var frame = window.wp.media({title: labelText, button: {text: multiple ? 'Use images' : 'Use image'}, multiple: Boolean(multiple), library: {type: 'image'}});
			frame.on('select', function () { var selected = frame.state().get('selection').toJSON().map(function (item) { return {id: item.id, url: item.url, alt: item.alt || '', caption: ''}; }); setter(multiple ? selected.slice(0, 20) : selected[0].url); changed(); renderStep(); }); frame.open();
		}, 'nova-setup-button is-secondary'));
		if (list.length) { group.appendChild(button(multiple ? 'Remove images' : 'Remove image', function () { setter(multiple ? [] : ''); changed(); renderStep(); }, 'nova-setup-text-button')); }
		parent.appendChild(group);
	}
	function heading(title, description) { var titleNode = node('h2', 'nova-setup-heading', title); titleNode.tabIndex = -1; content.appendChild(titleNode); content.appendChild(node('p', 'nova-setup-description', description)); }
	function renderBusiness() {
		heading('Start with your business', 'A few details now. Your site’s fonts and colors stay the starting point.');
		field(content, 'Business name', draft.business.name, function (value) { draft.business.name = value; }, {required: true, autocomplete: 'organization', placeholder: 'Your business name'});
		field(content, 'Website', draft.business.website, function (value) { draft.business.website = value; }, {type: 'url', autocomplete: 'url', placeholder: 'https://your-website.com'});
		var brand = node('div', 'nova-setup-info-card'); var text = node('div'); text.appendChild(node('strong', '', 'Your brand, already in place')); text.appendChild(node('p', '', 'Service pages follow your website’s fonts, colors and buttons. The choices here shape the layout.')); brand.appendChild(text); content.appendChild(brand);
	}
	function renderCTA() {
		heading('Give visitors a clear next step', 'Your first call to action appears near the top of every new service page.');
		var choices = {booking: ['Book an appointment', 'A booking or calendar link'], url: ['Visit a contact page', 'A page or enquiry link'], phone: ['Call your business', 'A phone number'], form: ['Use an existing form', 'A form shortcode or embed']}, cards = node('div', 'nova-setup-choice-grid');
		Object.keys(choices).forEach(function (type) { var choice = button('', function () { var previous = draft.ui.cta_type; if (previous !== type && [previous, type].some(function (value) { return value === 'phone' || value === 'form'; })) { draft.ui.cta_target = type === 'form' ? preset(draft, 'custom_cta').data.shortcode || preset(draft, 'custom_cta').data.html || '' : type === 'phone' ? draft.business.phone || '' : ''; } if (previous === 'form' && type !== 'form') { preset(draft, 'custom_cta').enabled = false; } draft.ui.cta_type = type; applyCTA(draft); changed(); renderStep(); }, 'nova-setup-choice' + (draft.ui.cta_type === type ? ' is-selected' : '')); choice.setAttribute('aria-pressed', draft.ui.cta_type === type ? 'true' : 'false'); choice.appendChild(node('strong', '', choices[type][0])); choice.appendChild(node('small', '', choices[type][1])); cards.appendChild(choice); }); content.appendChild(cards);
		field(content, 'Button label', draft.ui.cta_label, function (value) { draft.ui.cta_label = value; applyCTA(draft); }, {required: true, placeholder: draft.ui.cta_type === 'phone' ? 'Call us' : 'Book a consultation'});
		var form = draft.ui.cta_type === 'form', phone = draft.ui.cta_type === 'phone';
		field(content, form ? 'Form shortcode or embed code' : phone ? 'Phone number' : 'Where should the button go?', draft.ui.cta_target, function (value) { draft.ui.cta_target = value; if (phone) { draft.business.phone = value; } applyCTA(draft); }, {required: true, multiline: form, rows: 4, type: phone ? 'tel' : 'url', placeholder: form ? '[contact-form-7 id="123" title="Contact"]' : phone ? '+31 40 123 4567' : 'https://your-website.com/book', help: form ? 'Paste a form or calendar you already use on this WordPress site.' : phone ? 'Visitors can call directly from their phone.' : 'Use a complete link to your booking tool, contact page or calendar.'});
	}
	function optionalPanel(name, title, detail, renderer) {
		var panel = node('details', 'nova-setup-optional'); panel.open = draft.ui.optional_panels.indexOf(name) >= 0;
		var summary = node('summary'); summary.appendChild(node('strong', '', title)); summary.appendChild(node('small', '', detail)); panel.appendChild(summary);
		var inner = node('div', 'nova-setup-optional-body'); renderer(inner); panel.appendChild(inner);
		panel.addEventListener('toggle', function () { var included = draft.ui.optional_panels.indexOf(name) >= 0; if (panel.open && !included) { draft.ui.optional_panels.push(name); changed(); } else if (!panel.open && included) { draft.ui.optional_panels = draft.ui.optional_panels.filter(function (key) { return key !== name; }); changed(); } }); content.appendChild(panel);
	}
	function renderReviews(parent) {
		var data = preset(draft, 'reviews').data; data.items = data.items || [];
		parent.appendChild(node('p', 'nova-setup-help', 'Add real customer experiences. Publisher names, logos and source links are optional.'));
		media(parent, 'Publisher or website logo (optional)', data.source_logo, function (value) { data.source_logo = value; }, false);
		checkbox(parent, 'Link to review sources', data.link_sources, function (value) { data.link_sources = value; });
		data.items.forEach(function (item, index) {
			var row = node('div', 'nova-setup-repeat'); row.appendChild(node('h3', '', 'Testimonial ' + (index + 1)));
			field(row, 'Customer experience', item.body, function (value) { item.body = value; }, {multiline: true, placeholder: 'Their experience in their own words'});
			field(row, 'Customer name', item.author, function (value) { item.author = value; });
			field(row, 'Publisher or source (optional)', item.source, function (value) { item.source = value; }, {placeholder: 'Google, a customer website, or your customer'});
			field(row, 'Original review URL (optional)', item.url, function (value) { item.url = value; }, {type: 'url', placeholder: 'https://…'});
			row.appendChild(button('Remove testimonial', function () { data.items.splice(index, 1); changed(); renderStep(); }, 'nova-setup-text-button')); parent.appendChild(row);
		});
		parent.appendChild(button('Add a testimonial', function () { if (data.items.length >= 80) { return; } data.items.push({}); changed(); renderStep(); }, 'nova-setup-button is-secondary'));
		parent.appendChild(node('p', 'nova-setup-help', 'Review profiles and selected imports can also be managed in Service page settings after setup.'));
	}
	function renderCredentials(parent) {
		var data = preset(draft, 'credentials').data; data.items = data.items || [];
		parent.appendChild(node('p', 'nova-setup-help', 'Show awards, memberships or qualifications your business has earned.'));
		checkbox(parent, 'Link to credential sources', data.link_sources, function (value) { data.link_sources = value; });
		data.items.forEach(function (item, index) {
			var row = node('div', 'nova-setup-repeat'); row.appendChild(node('h3', '', 'Credential ' + (index + 1)));
			field(row, 'Award, membership or qualification', item.title, function (value) { item.title = value; });
			field(row, 'Issuing organization (optional)', item.issuer, function (value) { item.issuer = value; });
			media(row, 'Issuer logo, badge or certificate', item.image, function (value) { item.image = value; }, false);
			field(row, 'Evidence URL (optional)', item.url, function (value) { item.url = value; }, {type: 'url', placeholder: 'https://…'});
			row.appendChild(button('Remove credential', function () { data.items.splice(index, 1); changed(); renderStep(); }, 'nova-setup-text-button')); parent.appendChild(row);
		});
		parent.appendChild(button('Add a credential', function () { if (data.items.length >= 80) { return; } data.items.push({}); changed(); renderStep(); }, 'nova-setup-button is-secondary'));
	}
	function renderContent() {
		heading('Add what helps visitors trust you', 'These details are reusable across your service pages. Everything in this step is optional.');
		optionalPanel('people', 'People', 'Introduce the team behind the service', function (parent) { var data = preset(draft, 'people').data; media(parent, 'Team or people photos', data.images, function (value) { data.images = value; }, true); field(parent, 'A short introduction (optional)', data.body, function (value) { data.body = value; }, {multiline: true}); });
		optionalPanel('reviews', 'Testimonials', 'Let customers share their experience', renderReviews);
		optionalPanel('credentials', 'Awards and qualifications', 'Show issuer logos, badges or certificates', renderCredentials);
		optionalPanel('location', 'Location', 'Photos, address and an optional map', function (parent) {
			var location = preset(draft, 'location').data, map = preset(draft, 'map').data;
			field(parent, 'Business address', location.address, function (value) { location.address = value; }, {multiline: true, rows: 2}); media(parent, 'Location photos', location.images, function (value) { location.images = value; }, true);
			field(parent, 'Google Maps embed URL (optional)', map.map_url, function (value) { map.map_url = value; }, {type: 'url', placeholder: 'https://www.google.com/maps/embed?…', help: 'In Google Maps choose Share → Embed a map and copy the iframe’s src link. No API key is needed.'});
		});
		optionalPanel('video', 'Video', 'An introduction or service explanation', function (parent) { var data = preset(draft, 'video').data; field(parent, 'Video URL', data.video_url, function (value) { data.video_url = value; }, {type: 'url', placeholder: 'https://…', help: 'A YouTube or Vimeo link, or a video uploaded to this site.'}); });
		optionalPanel('cta', 'Another call to action', 'A useful next step further down the page', function (parent) { var data = preset(draft, 'cta').data; data.buttons = data.buttons || []; var item = data.buttons[0] || {label: '', url: ''}; field(parent, 'Heading (optional)', data.title, function (value) { data.title = value; }); field(parent, 'Button label', item.label, function (value) { item.label = value; data.buttons = item.label || item.url ? [item] : []; }); field(parent, 'Button URL', item.url, function (value) { item.url = value; data.buttons = item.label || item.url ? [item] : []; }, {type: 'url', placeholder: 'https://…'}); });
	}
	function styleGrid(parent, choices, value, setter, kind) {
		var grid = node('div', 'nova-setup-style-grid');
		Object.keys(choices).forEach(function (key) { var choice = button('', function () { setter(key); changed(); renderStep(); }, 'nova-setup-style-card' + (value === key ? ' is-selected' : '')); choice.setAttribute('aria-pressed', value === key ? 'true' : 'false'); var illustration = node('span', 'nova-setup-style-illustration style-' + key + ' kind-' + kind); illustration.setAttribute('aria-hidden', 'true'); illustration.appendChild(node('i', 'shape-mark')); illustration.appendChild(node('i', 'shape-line')); illustration.appendChild(node('i', 'shape-line is-short')); choice.appendChild(illustration); choice.appendChild(node('strong', '', choices[key])); grid.appendChild(choice); }); parent.appendChild(grid);
	}
	function renderStyle() {
		heading('Choose how your proof looks', 'Pick a presentation that suits your business. Your website’s brand styling stays in place.');
		var reviews = preset(draft, 'reviews').data, credentials = preset(draft, 'credentials').data;
		content.appendChild(node('h3', 'nova-setup-subheading', 'Testimonials'));
		styleGrid(content, reviewStyles, reviews.layout || draft.ui.style, function (value) { reviews.layout = value; draft.ui.style = value; }, 'reviews');
		content.appendChild(node('h3', 'nova-setup-subheading', 'Awards and qualifications'));
		styleGrid(content, credentialStyles, credentials.layout || 'logo_rail', function (value) { credentials.layout = value; }, 'credentials');
		content.appendChild(node('p', 'nova-setup-help', 'Examples appear in the preview when you have not added your own proof. They are never saved as your content.'));
	}
	function renderReview() {
		heading('Ready for your first service page', 'Finish once. New service pages will start with these customer defaults, and you can adjust them later.');
		var summary = node('dl', 'nova-setup-summary');
		function row(label, value) { summary.appendChild(node('dt', '', label)); summary.appendChild(node('dd', '', value)); }
		row('Business', draft.business.name || 'Not entered yet'); row('First button', draft.ui.cta_label || 'Not entered yet'); row('Button action', {booking: 'Booking link', url: 'Contact or enquiry page', phone: 'Phone call', form: 'Your existing form'}[draft.ui.cta_type] || 'Link');
		row('People photos', String((preset(draft, 'people').data.images || []).length)); row('Testimonials', String((preset(draft, 'reviews').data.items || []).length)); row('Credentials', String((preset(draft, 'credentials').data.items || []).length));
		row('Brand styling', 'Your website’s fonts and colors'); content.appendChild(summary);
		content.appendChild(node('div', 'nova-setup-info-card', 'Your service-specific titles, rich text and FAQs are added per page. These shared defaults stay linked automatically.'));
		if (demo) { content.appendChild(node('p', 'nova-setup-demo-note', 'This walkthrough is read-only. Finish is disabled so your existing pages and service settings stay unchanged.')); }
	}
	function validateStep(index) { if (index === 0 && !draft.business.name.trim()) { return 'Enter your business name to continue.'; } if (index === 1) { return ctaIssue(draft); } return ''; }
	function go(index) { if (finishing) { return; } if (index > step) { var issue = validateStep(step); if (issue) { announce(issue, true); return; } } step = index; announce(''); version += 1; renderStep(true); changed(); }
	function finish() {
		if (demo || finishing) { return; }
		var issue = validateStep(0) || validateStep(1); if (issue) { announce(issue, true); return; }
		applyCTA(draft); finishing = true; window.clearTimeout(saveTimer); root.querySelectorAll('button, input, select, textarea').forEach(function (control) { control.disabled = true; }); next.textContent = 'Finishing…'; announce('');
		saveDraft().then(function () { return request(config.endpoint || config.restUrl, {action: 'finish', expected_revision: state.revision || 0, step: 'review', draft: clone(draft)}); }).then(function (result) { state = result.state || result; complete(); }).catch(function (error) { finishing = false; saveAgain = false; root.querySelectorAll('button, input, select, textarea').forEach(function (control) { control.disabled = false; }); renderStep(); announce(error.message, true); });
	}
	function renderStep(focus) {
		content.textContent = ''; [renderBusiness, renderCTA, renderContent, renderStyle, renderReview][step]();
		progress.querySelectorAll('button').forEach(function (item, index) { item.classList.toggle('is-active', index === step); item.classList.toggle('is-done', index < step); item.setAttribute('aria-current', index === step ? 'step' : 'false'); });
		back.hidden = step === 0; next.textContent = step === 4 ? demo ? 'Preview only' : 'Finish setup' : ['Set the first action', 'Add people and proof', 'Choose visual styles', 'Review setup'][step]; next.disabled = step === 4 && demo;
		previewNote.textContent = 'Preview uses your site’s styling. Example copy and proof are shown where needed; active forms and media open on the saved page.';
		updateSaveStatus(); queuePreview(); if (focus) { var target = content.querySelector('h2'); if (target) { target.focus({preventScroll: true}); } }
	}
	function complete() {
		window.clearTimeout(saveTimer); savedVersion = version; root.textContent = ''; var panel = node('div', 'nova-setup-complete'); panel.appendChild(node('span', 'nova-setup-complete-mark', 'Setup complete')); panel.appendChild(node('h2', '', 'Your service pages are ready')); panel.appendChild(node('p', '', 'Your customer defaults and one flexible page layout are in place. Start with your first service.'));
		var create = node('a', 'nova-setup-button', 'Create your first service page'); create.href = config.createUrl || 'post-new.php?post_type=service_page'; panel.appendChild(create);
		var settings = node('a', 'nova-setup-text-button', 'View service settings'); settings.href = config.settingsUrl || 'edit.php?post_type=service_page&page=nova-service-shared'; panel.appendChild(settings); root.appendChild(panel);
	}
	function shell() {
		root.textContent = ''; root.classList.add('nova-setup');
		var aside = node('aside', 'nova-setup-sidebar'), brand = node('div', 'nova-setup-brand');
		if (config.logoUrl) { var logo = node('img'); logo.src = config.logoUrl; logo.alt = 'InSpace'; brand.appendChild(logo); } else { brand.appendChild(node('strong', '', 'InSpace')); }
		aside.appendChild(brand); aside.appendChild(node('p', 'nova-setup-kicker', 'Service page setup')); aside.appendChild(node('h2', '', 'Set up NOVA services')); aside.appendChild(node('p', 'nova-setup-sidebar-copy', 'Set up your customer defaults once. Every service page gets a strong start.'));
		progress = node('nav', 'nova-setup-steps'); progress.setAttribute('aria-label', 'Setup progress');
		['Your business', 'First call to action', 'People and proof', 'Visual style', 'Review and finish'].forEach(function (label, index) { var item = button('', function () { go(index); }, 'nova-setup-step'); item.appendChild(node('span', 'nova-setup-step-number', String(index + 1))); item.appendChild(node('span', '', label)); progress.appendChild(item); }); aside.appendChild(progress); aside.appendChild(node('p', 'nova-setup-sidebar-footnote', demo ? 'A safe walkthrough of the setup experience.' : 'Your progress is saved as a draft. Nothing goes live until Finish.')); root.appendChild(aside);
		var workspace = node('div', 'nova-setup-workspace'), editor = node('section', 'nova-setup-editor');
		message = node('div', 'nova-setup-message'); message.setAttribute('aria-live', 'polite'); editor.appendChild(message); if (demo) { editor.appendChild(node('div', 'nova-setup-demo-banner', 'Preview mode · existing settings are kept')); }
		content = node('div', 'nova-setup-content'); editor.appendChild(content);
		var footer = node('div', 'nova-setup-footer'), actions = node('div', 'nova-setup-actions'); back = button('Previous step', function () { go(step - 1); }, 'nova-setup-button is-secondary'); next = button('Set the first action', function () { if (step === 4) { finish(); } else { go(step + 1); } }); actions.appendChild(back); actions.appendChild(next); footer.appendChild(actions);
		saveStatus = node('span', 'nova-setup-save-status'); saveStatus.setAttribute('aria-live', 'polite'); footer.appendChild(saveStatus);
		if (!demo) { footer.appendChild(button('Save draft', function () { saveDraft().catch(function () {}); }, 'nova-setup-text-button')); } editor.appendChild(footer); workspace.appendChild(editor);
		var preview = node('section', 'nova-setup-preview'), header = node('div', 'nova-setup-preview-header'); header.appendChild(node('strong', '', 'Your page, taking shape')); previewStatus = node('span', '', 'Live preview'); header.appendChild(previewStatus); preview.appendChild(header);
		previewFrame = node('iframe', 'nova-setup-preview-frame'); previewFrame.title = 'Unsaved service page preview'; previewFrame.setAttribute('sandbox', 'allow-same-origin'); previewFrame.setAttribute('referrerpolicy', 'same-origin'); preview.appendChild(previewFrame); previewNote = node('p', 'nova-setup-preview-note'); preview.appendChild(previewNote); workspace.appendChild(preview); root.appendChild(workspace); renderStep();
	}
	window.addEventListener('beforeunload', function (event) { if (!demo && !state.complete && (finishing || version !== savedVersion)) { event.preventDefault(); event.returnValue = ''; } });
	if (state.complete && !demo) { root.classList.add('nova-setup'); complete(); } else { shell(); }
}(typeof window === 'undefined' ? null : window, typeof document === 'undefined' ? null : document));
