# API Mapping Context

This module discovers WordPress layouts and native fields, lets administrators map canonical NOVA sources to those fields, and synchronizes the resulting template/mapping/assignment configuration to the posting service. NOVA generates content against a sealed configuration; the plugin fetches the exact delivered version and applies it through the verified local writer.

The plugin integration is included in version **3.0.0** of this branch. Its required backend additions are proposals in the [colleague handover](../../docs/nova-backend-integration-handoff.md); the uncommitted local backend prototype is not part of this plugin deliverable. The new synchronization/execution contract is not ready against an unchanged service. See [validation](../../docs/publishing-integration-validation.md) for the precise local and isolated staging results; a passing fixture test is not a live end-to-end publication claim.

NOVA's Blog and Service CPT modules remain separate. Optional predefined mappings for those CPTs are a later convenience, not a second source catalog. Generic mapping instructions and profiles are **never inserted into ordinary REST content responses**, including when the old guidance option remains saved. Dedicated CPT context is independent.

## Authoring and synchronization

Open **Mapping**, select a concrete reference/layout, and use **NOVA mapping drafts**. The rendered preview and inspector use the existing field inventory, including nested ACF targets.

1. Configure the HTTPS service origin, publishing-site UUID, site-scoped plugin token and webhook signing secret in **NOVA posting service**. NOVA site administration issues the credentials. Saving connection settings records the administrator as the publishing actor; that user's current edit/create/publish capabilities still apply. A changed origin or site pauses new work until resumed.
2. Load the canonical catalog. The server reads the real `GET /v1/sites/{site_id}/writing/stock-templates` array and converts it into the editor's internal catalog. IDs/revisions are strings. Explicit preview templates remain available for offline preparation; preview IDs cannot be synchronized.
3. Choose an exact source template revision. Add layout instructions and per-target instructions, select generated sources, bind protected slots, and review coverage. Local drafts can remain incomplete. An intentional source skip requires a reason and cannot also be mapped.
4. Bind repeats to an ordered list of existing native slots. Adding a mapping slot creates configuration only. Synchronization fixes the site template's group bounds to that exact slot count, within the source group's allowed bounds. Incomplete slots cannot synchronize. Native row creation, removal and reordering are outside this writer's scope.
5. **Save locally**, then **Synchronize**, then **Activate** are separate operations. Synchronization clones the exact source into a site-owned template, submits instructions and bindings, and creates/updates the assignment. It does not publish content. Activation requires a fresh native capability probe, service-side evidence and sealing, retained exact revisions/pin/digest, and successful conditional activation.

Explicitly skipped NOVA sources are removed from the site-owned template before sealing; skipped repeat members are removed from their group, and a group with no remaining generated members is removed with its layout node. Protected slots remain intact. The immutable stock template is unchanged. Thus complete mapping coverage applies to the resulting canonical template: an unspecified unmapped source still prevents activation, and no fake binding is created for a skip.

| Target behavior | Existing-page update | New clone |
| --- | --- | --- |
| Map a NOVA source | Apply the validated generated value | Apply it to the verified cloned target |
| Leave empty | Keep the existing value | Blank the selected supported scalar while preserving structure |
| Protected | Preserve native content/settings/identity/order | Preserve the component from the source |

Field instructions are sent as canonical generated-field `notes` where applicable; layout instructions use `authoring_notes` (8000 UTF8 bytes maximum). All selected-target instructions, the profile label, skips, protection, omitted targets, fixed slots and routing are retained in the canonical mapping's versioned plugin policy. Native content/settings bytes are not uploaded as mapping configuration.

Drafts remain separate from legacy profiles and are scoped to a concrete reference type/ID. Opaque local revision tokens and atomic compare-and-swap reject lost updates. A changed fingerprint requires explicit reconciliation; missing targets remain visible until explicitly discarded. A cached exact catalog definition can support local editing during an outage, but cannot substitute for remote synchronization. If a previous sync has an uncertain response, **Resume pending synchronization** replays its saved request and identity before a newer local revision is sent.

## Runtime and supported scope

The signed ready endpoint durably queues `(site, content_id, version)` before acknowledging it. A worker fetches `GET /v1/content/{content_id}?version=N`, checks the returned version, then fetches the exact delivered pin and its template/mapping/assignment revisions. It never substitutes the current active assignment or latest content. Missing pin/digest data blocks execution. Immutable local snapshots can be restored from the exact remote plugin policy.

The job journal separates `accepted`, `validated`, `planned`, `applying`, `committed` and `receipt_pending`. A crash after `applying` invokes recovery before another write. Native operation/content markers prevent replaying a committed mutation or creating a duplicate clone. Completion includes derived work and verification before a result receipt is submitted/read back. Older versions cannot overwrite a newer committed version of the same content item.

Initial execution supports concrete posts/pages and eligible CPTs, native title/content/excerpt/slug, eligible existing registered scalar metadata, and verified existing ACF scalar rows, including nested leaves when their physical identity/reference is unambiguous. It uses fresh snapshots, row identity checks, transactions and readback, and bypasses general WordPress/ACF save hooks. The runtime probe requires the supported database/provider conditions; discovery alone is not certification.

Activation/execution blocks unsupported cases rather than reporting success:

- Term/category targets are editable local drafts but not supported by the initial publication writer.
- Generated image/link/list objects require typed adapters; they cannot be written through the scalar writer. Explicit omission is available only when the client intends to omit those sources.
- Whole-parent replacement (`complete_parent`), native repeat expansion/reordering, ambiguous/missing rows, incompatible value types and protected overlaps are rejected.
- A new clone requires a mapped generated native slug. Publication/status changes remain subject to capabilities and the verified core taxonomy-count adapter; custom count callbacks, count-status filters, attachment-parent dependencies and cloning a post_translations group remain blocked.
- Elementor 4.1.4 has an implemented cache/CSS adapter with six inspected source hashes and passing isolated rendering, regeneration, protection and clone checks. Other versions or changed source implementations remain blocked; this is not generic Elementor certification.

For contracted repeats the producer must also supply top-level `repeat_instances`, keyed by group, with ordered `{slot_id, instance_id}` entries matching both the sealed mapping and generated rows. Plugin slot IDs and generated instance IDs are different identities. Missing, extra, duplicate or reordered correspondence blocks the delivery. The native content marker retains established correspondence and rejects a later version that changes a slot's generated instance identity. The backend must retain this metadata and keep delivered pinned projections stable (corrections require a new version). Backend and producer changes remain requirements for the service owner; the local prototype only validated the proposed behavior.

## Private APIs and connection

Administrator routes require `manage_options`, WordPress REST authentication and private/no-store responses; the admin UI sends a nonce.

| Route under `/nova-bridge/v1` | Method | Purpose |
| --- | --- | --- |
| `/mapping/catalog?mode=nova\|preview` | GET | Canonical normalized catalog or explicit preview |
| `/mapping/draft?reference_type=post\|term&reference_id=ID&signature=HASH` | GET | Local draft, warnings and current reference identity |
| `/mapping/draft` | POST | Compare-and-swap local save using `expected_revision` (`""` for a new draft) |
| `/mapping/sync` | POST | Synchronize exact saved revision; `resume_pending:true` resumes its recorded operation |
| `/mapping/sync-state` | GET | Read synchronization/pin status for a concrete reference |
| `/mapping/activate` | POST | Verify capability, seal, retain and conditionally activate that revision |
| `/posting/jobs` | GET | Delivery status without native content/credentials |
| `/posting/jobs/{id}/resume` | POST | Retry a saved phase; uncertain mutations are reconciled first |

Sync/activate requests carry `reference_type`, `reference_id`, `signature`, and opaque `expected_revision`. Returned component IDs, revision IDs and ETags remain distinct from the local fingerprint and draft revision. The assignment `layout_key` is concrete (`wp_post_ID` or `wp_term_ID`), not a structural fingerprint shared across pages.

`POST /nova-bridge/v1/posting/ready` uses HMAC notification authentication instead of an admin nonce: `X-Nova-Timestamp` and `X-Nova-Signature: sha256=...`, signing `timestamp + '.' + exact raw JSON body`. The event is `{event:"content.ready",content_id:"...",site:"UUID",version:N}`. Intake enforces site identity, a five-minute timestamp window and a 16 KiB body limit, then returns 202 after durable insertion. It does not fetch or mutate content inside the notification request.

The client uses `Authorization: Bearer <site plugin token>` and `X-Nova-Plugin-Version`; it requires HTTPS and does not follow redirects with credentials. The UI does not echo saved tokens. Optional server constants are `NOVA_POSTING_SERVICE_URL`, `NOVA_POSTING_SITE_ID`, `NOVA_POSTING_TOKEN`, and `NOVA_POSTING_WEBHOOK_SECRET`. WordPress must not receive staff/workload credentials.

WP-Cron schedules recovery every minute while the connection is enabled; production operation needs a reliable WordPress cron runner. **Pause** prevents new content mutations while allowing configuration synchronization/activation and draining uncertain/committed work and result receipts. Disabling the connection stops intake/worker execution. The configured publishing actor must remain authorized.

## Compatibility and references

Existing discovery routes, saved endpoint defaults/legacy profiles, `meta_all` transport and NOVA-owned CPT behavior remain available. There is no automatic migration of a legacy profile into an active canonical mapping. The old generic REST-guidance option is retained as data but does not re-enable response decoration. Turning off this module does not delete its drafts or retained configuration.

The guarded editorial fallback below is an existing API with broader value support than the new scalar executor. Its capability list must not be read as a publishing certification for every mapped target. See [the decision record](../../docs/api-mapping-integration-notes.md), [backend handoff](../../docs/nova-backend-integration-handoff.md), and [validation record](../../docs/publishing-integration-validation.md).
## Editorial fallback transport

The module registers authenticated WordPress REST routes independently of each post type's `show_in_rest` flag:

| Route | Methods | Purpose |
| --- | --- | --- |
| `/nova-bridge/v1/content/{post_type}` | GET, POST | List editable items or create an item |
| `/nova-bridge/v1/content/{post_type}/{id}` | GET, POST, PUT, PATCH | Read or update an editable item |

There is no DELETE route. Standard WordPress authentication applies, including application passwords over HTTPS. GET requests use `context=edit` and require editorial permissions, even for publicly published items. Collection queries support the core controller's pagination, slug, status, search and other collection parameters. Core pagination has a maximum of 100 items per page.

Eligible types are `post`, `page`, and registered custom types that have an admin UI, are public or publicly queryable, and support editor, excerpt, custom fields, or an applicable ACF group. WordPress internal types, known commerce/application families, and NOVA's managed Blog/Service CPTs are excluded. Their existing dedicated APIs remain authoritative. Enabling this module does not change CPT, taxonomy, or ACF `show_in_rest` flags and does not enable or call XML-RPC.

The WordPress posts controller handles native fields such as `title`, `content`, `excerpt`, `slug`, `status`, `date`, `author`, `parent`, `template`, and `featured_media` when supported by the post type. Its object-editing, author, taxonomy-assignment and publication capability checks remain in effect. Unknown or read-only request fields are rejected. Use the discovery resource's verified route and field `request_path`, rather than constructing a native route for a hidden CPT.

## `meta_all` contract

`meta_all` is a guarded object, not unrestricted access to all database metadata. This transport accepts:

* Registered, single-valued post meta with a description or REST registration, a safe public name, and a valid registered schema.
* Applicable ACF/SCF fields whose actual field provider supplies `get_field`, `update_field`, field definitions and a supported field type.

Unregistered keys, leading-underscore keys, internal ACF reference keys, dotted/bracket leaf paths, credential-like names, multi-valued meta registrations and opaque structured meta are excluded. Nested registered objects reject properties outside their schema. The existing broad `meta_all` implementation on other suite routes is not inherited by this controller.

Registered WordPress meta authorization callbacks are honored for reads and writes. A create has no object ID yet: a registered callback that requires an existing ID must be satisfied by creating a draft without that field, then updating the field through the new item's route. Arbitrary field selections or descriptions do not override these checks.

ACF values use their field **names** in requests and responses. Internally the writer uses field keys to initialize storage references correctly on new content:

```json
{
  "title": "Example service page",
  "status": "draft",
  "meta_all": {
    "editorial_summary": "A registered single-valued custom field",
    "acf": {
      "hero": { "heading": "Renovation services", "intro": "Introductory copy" },
      "sections": [
        { "acf_fc_layout": "text_section", "heading": "Our process", "body": "<p>Page content</p>" }
      ]
    }
  }
}
```

These field and layout names are examples; the target's actual registry is required. A top-level ACF field can also be sent directly under `meta_all`, but the canonical discovery path is `/meta_all/acf/{field_name}`. Supplying the same ACF field in both places is rejected.

Groups require every child field. Repeaters replace the complete row list and require every child within each row. Flexible content replaces the complete row list; every row requires a registered `acf_fc_layout` name and all that layout's children. Read the current item and retain sibling values when changing one part of a parent. This avoids silently clearing omitted fields. On an existing item, change its template in a separate request before sending the new template's ACF values.

The ACF location engine evaluates the current item or create screen. Fields from nonmatching templates/groups are rejected. `show_in_rest` on an ACF group is unnecessary. `get_field(..., false)` is used for raw values, with internal child keys converted to names; attachment, relationship and taxonomy fields use IDs rather than expanded objects.

Supported ACF field types are:

* Text, textarea, WYSIWYG, email, URL, oEmbed, number, range and true/false.
* Select, radio, checkbox and button group, restricted to declared choices.
* Image, file, gallery, post object, relationship and taxonomy. IDs must refer to readable objects; taxonomy terms require assignment permission.
* Color picker, date picker (`Ymd`), date/time picker (`Y-m-d H:i:s`), time picker (`H:i:s`), link and Google Map.
* Group, repeater and flexible content, only when their installed field provider implements the type and every value-bearing child is supported.

Clone fields, password/user fields, unknown extension field types, readonly/disabled fields, and structured parents containing such fields are not supported by this guarded writer. Tabs, accordions and message controls carry no stored value. Repeater/flexible support requires a provider that implements those types, such as ACF Pro or compatible SCF. Sharing ACF-compatible function names alone is not a verification of every SCF version.

## Validation, persistence and failures

The maximum `meta_all` JSON size is 2 MiB; the outer object is limited to 500 keys. Repeater/flexible values and ID lists are limited to 500 entries. Structured ACF definitions and values have a nesting limit of 12; field names have a 191-byte limit. ACF's own value validation runs in addition to the bridge checks. Textareas and WYSIWYG content use WordPress's allowed post HTML; plain text fields are sanitized as text.

All submitted custom fields are validated before native content is changed. Unknown fields/layouts, incomplete parents and invalid values return HTTP 400; denied field capabilities return 403. A new item remains a draft until custom-field writes succeed, after which the requested native status is applied. Each ACF value is read back after its cache is cleared; unchanged values succeed, while a save filter or database failure that does not retain the requested value returns an error.

WordPress and third-party save hooks are not a database transaction. A runtime failure after a valid update can leave a partial write, reported with `post_id` and `partial_write`. A custom-field failure during creation retains a draft and reports its ID for recovery; it is not reported as a successful publication. Do not blindly retry failed creates without inspecting the returned draft ID.

Responses include `meta_all` and `nova_transport`. This generic Mapping module does not add `meta_descriptions`, `nova_content_mappings` or `nova_template_contexts`; its mappings and author instructions are synchronized to NOVA instead. Dedicated CPT context is separate. Native route discovery additionally reports `content_bridge`, and hidden CPT resolver results use the guarded route when available. This module does not edit n8n workflows: callers must use the returned route and request paths.

## Developer integration

`Nova_Bridge_Suite_Content_Transport::supports_post_type($type)` checks eligibility; `route_for($post_type)` returns the collection route or an empty string. `field_catalog($post_type, $post_id = 0, $template = '')` returns separate `acf` and `meta` registries, and `read_fields(WP_Post $post)` returns the current editor's safe values. Catalog presence advertises a discovered writer; actual writes still enforce object-scoped permissions. Discovery is not certification of protected-content preservation or atomic execution.

The compatibility filter `nova_bridge_strategy_decorate_record` remains registered, but generic mapping profile decoration is disabled. Discovery/editor access remains available.

## Integration checks

Standalone plugin suites use local mocks and do not call a service or mutate a WordPress installation:

```sh
php modules/posting-service/tests/mapping-sync-unit.php
php modules/posting-service/tests/mapped-writer-unit.php
php modules/posting-service/tests/delivery-unit.php
php modules/posting-service/tests/posting-settings-unit.php
```

The local posting-service prototype tests validated the PHP adapter's actual JSON fixture through authenticated Fastify routes and disposable PostgreSQL, including clone/edit, coverage, evidence, sealing, conditional activation and exact pin reads. Those backend files are not part of the plugin release. The [handover](../../docs/nova-backend-integration-handoff.md) describes the contract and acceptance checks for the backend owner to reproduce.

Real WordPress canaries are `mapping-sync-staging.php`, `mapped-writer-staging.php`, and `delivery-staging.php` under `modules/posting-service/tests`. Load the candidate in isolation from the installed plugin and use only an authorized disposable staging site. The sync canary uses real REST/inventory/options and a fixture-only HTTP adapter; it never calls NOVA. Writer canaries mutate temporary drafts and clean them up. Follow the [validation record](../../docs/publishing-integration-validation.md) for exact environment/results and provider-specific checks.

## Draft-workflow checks

Run the standalone suites from the plugin repository; they do not connect to a WordPress site:

```sh
php modules/api-mapping-context/tests/mapping-drafts-unit.php
php modules/api-mapping-context/tests/rest-guidance-unit.php
php modules/api-mapping-context/tests/strategy-unit.php
node --test modules/api-mapping-context/tests/mapping-drafts-unit.cjs
node --test modules/api-mapping-context/tests/strategy-ui-unit.cjs
```

The first two PHP suites are mocked standalone checks and must not be run through `wp eval-file`. On a restricted Windows Node environment that rejects test-worker creation with `spawn EPERM`, Node 24 can run the JavaScript suites with `--test-isolation=none`.

Draft checks cover catalog/source identities, skips, fixed slots, protected overlaps, permissions, disabled generic REST guidance, revision conflicts, stale targets and local-only status. JavaScript checks also exercise opaque revisions, saved empty PHP collections, exact cached definitions and preservation of edits after failed requests. These do not certify publishing or protection under concurrent editor writes.

For a real integration canary, load the candidate plugin in a **disposable WordPress staging site**, then run:

```sh
wp eval-file wp-content/plugins/nova-bridge-suite/modules/api-mapping-context/tests/mapping-drafts-staging.php
```

This canary creates a temporary draft page and local mapping option and removes them in `finally`. It exercises actual REST registration, discovery and options storage; it does not call NOVA or publish content. Consult the current validation record for separate writer and recovery canaries; this draft canary alone does not certify publication.

## Earlier transport validation record (3.1.0)

The existing transport's recorded staging verification used WordPress 7.1 and PHP 8.1.34 with ACF Pro 6.8.0.1, SCF 6.9.5, Elementor 4.1.4 and WooCommerce 10.9.3. Both field providers passed the structured-field/authorization roundtrip suite. SCF was removed afterward and the original ACF Pro activation restored. This historical record does not itself certify the separate contracted executor; consult its current validation record.

From a disposable WordPress staging root with ACF Pro (or SCF), Elementor and WooCommerce active:

```sh
wp eval-file wp-content/plugins/nova-bridge-suite/modules/api-mapping-context/tests/strategy-unit.php
wp eval-file wp-content/plugins/nova-bridge-suite/modules/api-mapping-context/tests/strategy-integration.php
wp eval-file wp-content/plugins/nova-bridge-suite/modules/api-mapping-context/tests/content-context-regression.php
```

The integration suites create temporary posts, terms and users and restore their saved mapping options in `finally`. Run them only on a test site. The standalone strategy-unit script performs no site mutations.


## Mapping workspace (3.2.0)

The **Mapping** tab replaces the two previous navigation tabs. Legacy `strategy-mapping`, `api-mapping-context`, `content-context`, and `mapping` bookmarks open the unified workspace. Existing endpoint defaults and legacy profiles remain editable; no configuration migration or deletion is required. Generic mapping/profile instructions are no longer inserted into content responses.

The default queue groups all eligible site content by the existing structural fingerprint. **Imported strategy** filters that queue without deleting other profiles. The catalog enumerates posts and product categories in batches, including editable drafts; detailed fields load when a reference is selected. Current discovery is limited to the module's eligible editorial content types and product categories, not every WordPress infrastructure/commerce object or every possible theme-builder display condition.

Administrator-only, non-cached endpoints:

- `GET /nova-bridge/v1/mapping?scope=all|strategy`: layout groups, membership, strategy reference issues, and counts. The default scope is `all`.
- `GET /nova-bridge/v1/mapping/layout?reference_type=post|term&reference_id=ID&signature=HASH`: fields, saved mappings rebound to this reference, provider diagnostics, and a user/reference-bound preview URL. A changed signature returns 409.

The normal WordPress frontend renders the preview. Its nonce and permissions are checked before rendering and the queried object must match the selected reference. Responses are private/no-store and same-origin framed. Mapping instrumentation is not added to ordinary public pages. Preview links/forms are disabled; the iframe also prevents form submission, popups, and top-level navigation.

Selecting a mapped region selects the inspector field; selecting an inspector field highlights/scrolls the page. Elementor uses document-scoped widget IDs and known leaf selectors, falling back explicitly to the widget region. Known native title/content/category wrappers are also supported. Ambiguous, hidden, SEO, custom ACF, and other unbound fields stay in the inspector. A rendered widget is not proof that every nested setting has a distinct DOM node. Shared/dynamic source attribution and arbitrary custom-theme bindings are not inferred from matching text.

In the existing legacy-profile workflow, reference switching rebinds profile fields using the existing bridge binding rules. If saved fields cannot be rebound, saving is disabled to prevent silently removing them. NOVA drafts instead retain concrete reference identity and require explicit stale-target reconciliation as described above. Missing parent URLs use `needs_parent`, separate from missing references, and do not block the mapping editor. Enabled builder and CPT modules load consistently for discovery, mapping, and strategy REST requests; disabled modules remain disabled.

Run `wp eval-file wp-content/plugins/nova-bridge-suite/modules/api-mapping-context/tests/mapping-workspace.php` for read-only live checks of scopes, grouping, missing-parent behavior, module dependencies, permissions, nonce binding, and legacy navigation. It temporarily filters options in memory and does not write site data. The existing `strategy-unit.php` remains runnable with standalone PHP.
