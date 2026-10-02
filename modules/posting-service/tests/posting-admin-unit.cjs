'use strict';
// Executes the actual status controller against a minimal DOM and an offline REST fixture.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../../api-mapping-context/assets/posting-admin.js'), 'utf8');
let checks = 0;
function check(condition, message) { checks++; assert.ok(condition, message); }
class Element {
    constructor(tag) { this.tag = tag; this.textContent = ''; this.children = []; this.attributes = {}; this.events = {}; }
    appendChild(child) { this.children.push(child); return child; }
    prepend(child) { this.children.unshift(child); }
    replaceChildren(...children) { this.children = children; }
    setAttribute(name, value) { this.attributes[name] = value; }
    addEventListener(name, callback) { this.events[name] = callback; }
    set innerHTML(value) { throw new Error('Status must not render server text as HTML.'); }
    all() { return [this, ...this.children.flatMap(child => child.all())]; }
}
async function fixture(status, later = []) {
    const host = new Element('div'), calls = [], responses = [{ ok: true, data: status }, ...later];
    const context = {
        document: { readyState: 'complete', getElementById: id => id === 'nova-posting-status' ? host : null, createElement: tag => new Element(tag) },
        window: { NovaStrategyAdmin: { postingUrl: '/wp-json/nova-bridge/v1/posting', nonce: 'fixture-nonce' } },
        fetch: async (url, options) => {
            calls.push({ url, options });
            assert.ok(responses.length, 'Unexpected REST request');
            const response = responses.shift(); return { ok: response.ok, json: async () => response.data };
        }
    };
    vm.runInNewContext(source, context);
    await new Promise(resolve => setImmediate(resolve));
    return { host, calls, button: label => host.all().find(item => item.tag === 'button' && item.textContent === label), text: () => host.all().map(item => item.textContent).join(' ') };
}
(async () => {
    const restricted = { enabled: true, paused: false, jobs: [], discovery: { suspended: true, auth_status: 403, last_error: '<script>untrusted</script>' } };
    const view = await fixture(restricted, [{ ok: true, data: { recheck_scheduled: true } }, { ok: true, data: { ...restricted, discovery: {} } }]);
    check(view.text().includes('restricted new work') && view.text().includes('only where NOVA still permits authenticated access'), '403 explains the separate recovery authorization gate');
    check(view.text().includes('<script>untrusted</script>') && view.host.all().every(item => item.tag !== 'script'), 'Discovery issue is safely rendered as text');
    await view.button('Recheck NOVA access').events.click();
    check(view.calls[1].url.endsWith('/discovery/retry') && view.calls[1].options.method === 'POST' && view.calls[1].options.headers['X-WP-Nonce'] === 'fixture-nonce', 'Access recheck is a nonce-bearing POST');
    check(view.calls[2].url.endsWith('/jobs') && !view.button('Recheck NOVA access') && view.host.attributes['aria-busy'] === 'false', 'Successful recheck refreshes the actual server state and releases busy state');
    const unauthorized = await fixture({ ...restricted, discovery: { suspended: true, auth_status: 401, last_error: 'unauthorized' } });
    check(unauthorized.text().includes('Delivery is stopped until access is restored'), '401 accurately describes the stop-all state');
    const disabled = await fixture({ enabled: false, jobs: [], discovery: {} });
    check(disabled.text().includes('Connection disabled.') && disabled.text().includes('No delivery jobs yet.') && !disabled.button('Recheck NOVA access'), 'Disabled unconfigured status renders without a recovery action');
    const failed = await fixture(restricted, [{ ok: false, data: { message: 'Refresh the Mapping page.' } }, { ok: true, data: restricted }]);
    await failed.button('Recheck NOVA access').events.click();
    check(failed.host.all().some(item => item.attributes.role === 'alert' && item.textContent === 'Refresh the Mapping page.') && failed.host.attributes['aria-busy'] === 'false', 'Failed authorization recheck displays the safe error and releases busy state');
    await failed.button('Refresh publishing status').events.click();
    check(failed.calls.length === 3, 'Operator can refresh after a failed recheck');
    process.stdout.write(`PASS ${checks} publishing status UI checks.\n`);
})().catch(error => { process.stderr.write(error.stack + '\n'); process.exitCode = 1; });
