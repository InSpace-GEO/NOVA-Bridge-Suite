# Endpoint mapping staging verification — 12 October 2026

Branch: `api-mapping-with-context`. Site: `https://inspace-seo.online/`. Only the WP plugin changed; neither backend repository nor the plugin's existing posting-service implementation changed.

The final runtime bundle contains seven explicitly selected files. Its adapter SHA256 is `ece0984c927e23ae36938aac3999ace8c06d3a630faea42dc6fd3418a98ecd82`. Private backups of the previous plugin and original NOVA options are held outside the site's public directory under `nova-endpoint-defaults-20261012`. Runtime file hashes and PHP cache invalidation were verified after installation.

## Validation

- All 17 standalone PHP suites and six JavaScript suites passed. New coverage includes 105 document-adapter assertions, 124 controller assertions, 13 admin-shell checks and 15 onboarding tests.
- The live WordPress REST canary passed 57 checks: source-free native templates for all five NOVA CPT endpoints, existing native Blog/Service content read without migration, complete Elementor data/order and a 1275-byte field, existing taxonomy content, blank preview without persistence, confirmed save, concurrent revision rejection, returned-value validation, protected structure rejection and authored instruction preservation.
- Actual WordPress Gutenberg parsing/serialization preserved group padding and heading/paragraph/list wrappers while clearing selected text. An intervening content edit rejected the earlier export with HTTP 409.
- An unauthenticated HTTP request to the endpoint catalog returned 403. The logged-in browser successfully loaded the catalog, read an existing Elementor document, walked through the four steps and saved a standard-fields default.
- The final browser checklist showed 12 supported creation destinations and one explicit WooCommerce product limitation. NOVA's own custom endpoints are included; internal pattern and WPML administrative taxonomies are excluded. A zero-item NOVA CPT can start from native blank fields.
- Original NOVA settings/history and active-plugin configuration remained unchanged (13 backed-up options). The six retained Elementor test pages remained unchanged. The connection stayed disabled and paused.

The CLI canary restored its exact owned test registry using a conditional database comparison and removed its isolated Gutenberg draft. The browser's single temporary default was privately backed up and cleared with ownership/revision checks, restoring fresh onboarding. No prior mappings, native pages, journals or user settings were removed by these tests.

## Boundary

These routes read, prepare, save and validate local document mappings. They do not upload them to either backend or apply generated document values to the site. Existing provider bridges remain the translation infrastructure for later integration. Unsupported complete products/builders are reported explicitly; this work does not silently flatten them into generic body fields. Native repeated structures can be captured, but this adapter does not invent or resize their rows.
