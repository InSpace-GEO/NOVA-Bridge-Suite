# Mapping setup for the current publishing API

This guide describes the plugin candidate against posting-service commit `145fbcfb7319333e6369796489c068196cb68665` (reviewed 2026-10-02). It replaces the earlier writing-template, assignment, pin and URL-binding setup flow. It does not certify a deployed NOVA service or a real end-to-end delivery.

New drafts describe what destination template fields can hold, with optional rules and instructions; posting-service is expected to fit NOVA's existing content to those destinations. Destination preparation is available locally; synchronization awaits the supported adaptation API. See the [backend handover](destination-mapping-backend-handover.md) for the exact prepared data and remaining connection work. The direct stock-field setup below remains available for existing profiles.

## Prepare destination descriptions

Open the Mapping tab in NOVA Bridge settings and choose a concrete page/template. New drafts need no source catalog or NOVA connection. Choose **Fit NOVA content to this field**, describe its label, purpose and accepted type, and add optional limits and human instructions. Required/optional is explicit; an incomplete local draft can still be saved.

For fixed repeat capacity, create a named group and add existing slots. Bind each slot to its selected scalar destination fields. The editor preserves slot UUIDs and ordinals; it never creates native rows. Protected and Leave empty remain separate choices.

Save the draft, then choose **Export backend description**. This export contains stable destination IDs and rules without WordPress addresses or protected content. It checks the saved revision and current layout; stale or mixed legacy descriptions require review. Backend synchronization and approval are unavailable for these descriptions until the adaptation contract is connected.

Existing drafts retain their original mode. **Prepare destination descriptions instead** switches the local editor explicitly while keeping historical mappings and instructions for review. Convert direct-source fields explicitly, and remove retained legacy repeat bindings after review before describing their existing destinations. Approved historical profiles remain unchanged.

## Existing direct-source profiles

Choose a concrete WordPress page and its current structure in the mapping editor. The local draft retains the page identity, structure signature, server-derived native target descriptors, routing, protection decisions, explicit source skips and human instructions. Saving a draft does not edit or publish that page.

Existing direct-source profiles use the twelve source fields defined by the current delivery contract, including when the site connection is disabled or unavailable:

`title`, `meta_description`, `h1`, `content`, `top_content`, `bottom_content`, `image_url`, `image_urls`, `image_alt`, `url`, `page_type_frontend`, `language`.

This offline catalog is a pinned API enumeration. It is not a request to an invented stock-template endpoint, and it does not claim that NOVA generated those values. Connected template records are added only after a successful, validated API response.

Map supported sources to existing native targets, or choose Protected or Leave empty. A source can fill multiple distinct destinations when their native targets do not overlap. The Required choice governs whether a missing delivered value blocks the write; it is not an instruction to the content generator. A skipped source is omitted from this publishing mapping, with its reason retained locally. Skipping does not remove that field from NOVA generation.

Historical profiles and repeat slots remain available for review. The current API has no generated repeat-group schema, custom source paths, slot catalog or arbitrary length/cardinality constraints. Nonempty historical repeat mappings cannot synchronize through this adapter. No row identities are invented, and native rows are not added, removed or reordered. Individual scalar leaves inside existing structures remain subject to the native writer's checks.

## Human instructions stay visible and local

Global/template guidance and per-target instructions are preserved, including rules such as “exactly one relevant label,” character limits and existing-row capacity. Guidance keeps the local inherit/set/clear choice and an 8000 UTF-8 byte limit. When changing away from a historical template with inherited instructions, the editor preserves those instructions as explicit local guidance.

The current publishing template schema does **not** accept human instructions or arbitrary adaptation rules. Synchronization sends no undocumented properties and does not claim that posting-service consumes these instructions. The editor and sync state report `unsupported_local_only`. The plugin stores the instructions with each approved historical profile so a later local edit cannot silently replace them for an older delivery. Connecting these rules to posting-service content adaptation needs its supported API contract; NOVA generation can remain unchanged.

## Synchronize and approve

Configure the service URL, site UUID, site bearer token and WordPress execution user in the publishing settings. Credentials remain server-side. Enabling the connection allows setup requests; the content-mutation pause can remain enabled while profiles and publishing selections are prepared.

1. Save the local draft, select its publishing page type and synchronize it.
2. The first sync creates a template with `POST /v1/sites/{site_id}/templates`. Later syncs replace that template using `PUT /v1/sites/{site_id}/templates/{template_id}` with `If-Match: "<reviewed revision>"`.
3. Approve the native profile. The plugin reads the exact current template, checks that it matches the synchronized revision and runs the native writer's capability/structure probe. Approval is retained locally for that site, template UUID and integer revision.
4. Configure an optional explicit page selection or a source-page-type default. Keep publication paused until the intended controlled delivery test is ready.

The template wire payload contains only `name`, `page_type`, `enabled`, `definition` and `mapping`. Definitions have version 1 and fields with `id`, `label`, `kind` and `required`; mappings have version 1 and `{field_id, source_field}` entries. Stable field IDs derive from the selected native inventory path. Native addresses, protection, routing and human instructions remain in WordPress.

Synchronization and local approval are separate. A synchronized template is not proof that its native targets are approved or that a delivery will succeed. Deliveries for an unapproved exact revision stop before mutation. A new template revision requires its own approval; older approved profiles are retained immutably.

A lost template-update acknowledgement is reconciled by reading and comparing the expected revision and complete payload. An uncertain template-create result is never retried automatically because the current POST has no contracted idempotency key. Enter the actual created template UUID for explicit recovery; the plugin reads it and requires an exact match. Do not create another template simply because the acknowledgement was lost.

## Select a template for a NOVA page or source page type

The editor's local `/wp-json/nova-bridge/v1/mapping/url-binding` route is retained for compatibility with its UI, but it now manages the current **page setting** API. It no longer writes assignments, native references or historical binding revisions.

Enter the trusted decimal NOVA URL ID explicitly. It is distinct from a WordPress post ID. Read the current selection before replacing it:

- `GET /v1/sites/{site_id}/pages/{url_id}/setting`
- `PUT /v1/sites/{site_id}/pages/{url_id}/setting`, body `{ "template_id": "<approved template UUID>" }`

A new setting uses `If-Match: "0"`; an existing setting uses the exact reviewed integer revision. Ownership is checked by NOVA. A conflict requires another review instead of a blind overwrite.

For a source-page-type default, the local `/mapping/template-default` route reads `GET /v1/sites/{site_id}/template-defaults` and conditionally writes `PUT` to that same path. The plugin merges the requested entry into the reviewed defaults map and preserves unrelated entries. The defaults collection's revision is the conditional-update authority.

All local setup routes require `manage_options` and a valid WordPress REST nonce. Responses are private and uncached. Administrative selection records are retained for review; they do not replace the delivery snapshot's configuration authority.

## Delivery uses the frozen configuration and retained native profile

The current delivery snapshot supplies its configuration UUID, integer revision and complete definition/mapping. WordPress requires an approved local profile for that exact site, UUID and revision, verifies the complete frozen projection and checks the retained profile digest. There is no fallback to today's active template, an old pin, a current assignment or a historical URL-binding API.

The selected native page, update/clone operation, publication choice and protected targets come from that retained local profile. An update delivery must match the approved page's current permalink, including the query part of a draft/plain permalink. Clone routing requires the same WordPress origin and the writer's additional checks. A template selection on NOVA is not permission to target another native page.

Current delivery intake, snapshot retrieval and connector-event reporting are covered by the worker and protocol tests. HTTP 202 acceptance of an event does not prove backend result application. A native draft or scheduled post is not reported as successfully published before actual publication. Generic REST context injection remains disabled; optional predefined mappings for the plugin's own CPTs are a separate concern.

The native writer supports only its verified target/provider combinations. Unsupported image/list destinations, terms, structural drift or unverified provider versions stop approval or delivery. Mapping an enum source does not certify that every native field can consume that value.

## Validation and isolated staging

Offline checks run without WordPress or NOVA:

```powershell
php modules/posting-service/tests/mapping-sync-unit.php
php modules/posting-service/tests/mapping-contract-unit.php
php modules/api-mapping-context/tests/mapping-drafts-unit.php
node modules/api-mapping-context/tests/mapping-drafts-unit.cjs
node modules/posting-service/tests/posting-admin-unit.cjs
```

The current setup canary is `modules/posting-service/tests/mapping-sync-staging.php`. It requires an authorized isolated candidate load plus `NOVA_MAPPING_STAGING_CANARY=1`. It uses real WordPress REST, draft storage, inventory and native probing; service HTTP responses are simulated at a fixture-only origin through the actual client. It creates one unpublished source and removes its own page/options in cleanup. Its pass result establishes the tested integration seams, not real NOVA authorization, generation or application of connector events.

`modules/api-mapping-context/tests/mapping-drafts-staging.php` separately exercises real local draft persistence. Load candidate files without activating or replacing the installed plugin. Check the current migration validation report for actual run results; this guide does not imply a candidate deployment or a completed real-service test.
