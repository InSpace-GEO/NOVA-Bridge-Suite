# API Mapping Context

Version **3.0.0** discovers WordPress layouts and prepares destination descriptions, rules and human instructions. Existing direct-source profiles apply NOVA deliveries through the verified native writer and shared CMS API, pinned to commit `145fbcfb7319333e6369796489c068196cb68665` in the [schema subset](../posting-service/contracts/delivery-v1.json). New destination descriptions await the supported adaptation API.

See [current mapping setup](../../docs/contracted-mapping-setup.md), [local testing](../../docs/contracted-delivery-local-testing.md) and the [migration/validation record](../../docs/posting-service-migration-20261002.md). September handovers and retained simulated tests document the earlier contract. Isolated canaries do not install this candidate or certify a real NOVA round trip.

## Mapping and human rules

Open **Mapping**, select a concrete page/layout, and describe what its destination fields can hold. New drafts work offline without choosing NOVA sources. Add purpose, type, optional length/list limits, required/optional policy and instructions. Fixed groups describe selected existing slots with stable identities; they do not change native rows. Save, then export the backend description. See the [backend handover and tested example](../../docs/destination-mapping-backend-handover.md).

Existing source profiles retain their catalog and mappings until explicitly converted. Unsupported historical repeat bindings remain available for review rather than silently converted. Destination descriptions cannot synchronize or activate against the reviewed stock-field API; they are prepared and exported locally.

Configure the HTTPS service origin, site UUID, site token and authorized WordPress execution user. **Save locally**, **Synchronize**, and **Approve native profile** are separate operations. Synchronization creates/replaces `/v1/sites/{site_id}/templates`; local approval validates native capabilities and retains the exact site/template UUID/integer revision profile. Updates use `If-Match`. Unknown template-create responses require explicit recovery with the reviewed remote UUID, since POST has no contracted idempotency key.

Optional `/pages/{url_id}/setting` selections and `/template-defaults` choose publishing templates on NOVA. The trusted NOVA URL ID is distinct from a WordPress ID. Native addresses, update/clone routing and protection remain local and must match the frozen delivery configuration. Source skips affect publishing mappings, not generation.

Human instructions remain editable, including template-level inherit/set/clear and per-field rules. Bounded UTF-8 instruction text preserves literal HTML and URL examples in the saved/exported description. **The reviewed API has no instruction/rule property, so they remain local and NOVA does not enforce them.** Connect them to posting-service content adaptation without changing NOVA generation.

| Target behavior | Existing-page update | New clone |
| --- | --- | --- |
| Map a supported source | Apply its validated value | Apply to the verified cloned target |
| Leave empty | Preserve its existing value | Blank the selected supported scalar |
| Protected | Preserve content, settings and structure | Preserve it from the source |

Local drafts use opaque revision tokens and atomic compare-and-swap. Structural drift requires explicit reconciliation. Generic mapping instructions are not inserted into ordinary REST responses. NOVA's Blog and Service CPT context remains separate; predefined mappings for those CPTs are optional future work.

## Delivery and recovery

The signed `content.ready` endpoint only durably wakes authenticated discovery. The worker polls `/deliveries` with its retained decimal checkpoint and fetches `/deliveries/{delivery_id}` with a full response. It checks the exact raw-byte SHA256/strong ETag, source identities, current `X-Nova-Attempt-Id`, frozen configuration and approved local profile. Historical pins, assignments and URL bindings are not fetched.

The private journal retains the verified snapshot, native plan, operation UUID and exact event bytes before effects. `received_complete` and `publication_succeeded` are distinct events posted to `/deliveries/{delivery_id}/events`. A `202/pending` acknowledgement confirms acceptance, not backend application. Draft/private/pending/future content waits until native publication; a status/date-only transition can be verified, while content or protected-field drift stops it.

Native transaction markers, ownership/version fences and clone witnesses prevent duplicate writes after interruptions or lost acknowledgements. Retries replay identical event IDs and bytes. A new attempt needs proven absence of a native commit. New announcements remain retained while older event acknowledgement is pending. Historical journals require manual review. Pause prevents new writes while allowing reconciliation and prepared event replay; disabled connections stop execution. Authentication and eligibility failures do not bypass these checks.

Initial native execution supports verified posts/pages/CPTs, scalar fields, existing ACF leaves and the inspected Elementor 4.1.4 implementation. Terms, structural row expansion/reordering, whole-parent replacement and unsupported image/list destinations stop approval or execution. The runtime probe requires supported database/provider conditions; discovery alone does not certify a writer. Explicit clone routing requires a mapped native slug and current capabilities.

Administrator setup routes use `manage_options`, authenticated REST nonces and private/no-store responses. Tokens remain server-side; the HTTPS client does not redirect credentials. WP-Cron schedules recovery each minute while enabled and needs a reliable runner in production. Current local routes and runnable checks are documented in the linked setup/testing guides.

The existing editorial fallback described below has broader value support than the scalar delivery writer. Its capability list is not publishing certification or a fallback for failed delivery validation.

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

Responses include `meta_all` and `nova_transport`. This generic Mapping module does not add `meta_descriptions`, `nova_content_mappings` or `nova_template_contexts`. Destination descriptions and instructions remain in local drafts/exports until the backend adaptation contract is connected; existing direct-source templates use the current setup API. Dedicated CPT context is separate. Native route discovery additionally reports `content_bridge`, and hidden CPT resolver results use the guarded route when available. This module does not edit n8n workflows: callers must use the returned route and request paths.

## Developer integration

`Nova_Bridge_Suite_Content_Transport::supports_post_type($type)` checks eligibility; `route_for($post_type)` returns the collection route or an empty string. `field_catalog($post_type, $post_id = 0, $template = '')` returns separate `acf` and `meta` registries, and `read_fields(WP_Post $post)` returns the current editor's safe values. Catalog presence advertises a discovered writer; actual writes still enforce object-scoped permissions. Discovery is not certification of protected-content preservation or atomic execution.

The compatibility filter `nova_bridge_strategy_decorate_record` remains registered, but generic mapping profile decoration is disabled. Discovery/editor access remains available.

## Integration checks

Standalone plugin suites use local mocks and do not call a service or mutate a WordPress installation:

```sh
php modules/posting-service/tests/mapping-contract-unit.php
php modules/posting-service/tests/destination-description-unit.php
php modules/posting-service/tests/protocol-unit.php
php modules/posting-service/tests/receipt-json-unit.php
php modules/posting-service/tests/mapped-writer-contract-unit.php
php modules/posting-service/tests/delivery-unit.php
php modules/posting-service/tests/posting-settings-unit.php
```

The mapping and writer contract suites include their baseline suites. The [local testing guide](../../docs/contracted-delivery-local-testing.md) lists the complete discovery, intake, journal and UI commands, test boundaries and optional real-service acceptance. Earlier local backend prototype results are historical and do not validate this changed protocol or a deployed NOVA host.

Real WordPress canaries are under `modules/posting-service/tests`. Load the candidate in isolation from the installed plugin and use only an authorized disposable staging site. The sync canary uses real REST/inventory/options and a fixture-only HTTP adapter; it never calls NOVA. Writer canaries mutate temporary drafts and clean them up. Follow the [local testing guide](../../docs/contracted-delivery-local-testing.md) for isolated loading, required canary flags and separately recorded results. Installed-plugin activation/settings must remain unchanged, and mocked receipt acceptance does not prove NOVA applied the result.

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


## Mapping workspace (3.0.0)

The **Mapping** tab replaces the two previous navigation tabs. Legacy `strategy-mapping`, `api-mapping-context`, `content-context`, and `mapping` bookmarks open the unified workspace. Existing endpoint defaults and legacy profiles remain editable; no configuration migration or deletion is required. Generic mapping/profile instructions are no longer inserted into content responses.

The default queue groups all eligible site content by the existing structural fingerprint. **Imported strategy** filters that queue without deleting other profiles. The catalog enumerates posts and product categories in batches, including editable drafts; detailed fields load when a reference is selected. Current discovery is limited to the module's eligible editorial content types and product categories, not every WordPress infrastructure/commerce object or every possible theme-builder display condition.

Administrator-only, non-cached endpoints:

- `GET /nova-bridge/v1/mapping?scope=all|strategy`: layout groups, membership, strategy reference issues, and counts. The default scope is `all`.
- `GET /nova-bridge/v1/mapping/layout?reference_type=post|term&reference_id=ID&signature=HASH`: fields, saved mappings rebound to this reference, provider diagnostics, and a user/reference-bound preview URL. A changed signature returns 409.

The normal WordPress frontend renders the preview. Its nonce and permissions are checked before rendering and the queried object must match the selected reference. Responses are private/no-store and same-origin framed. Mapping instrumentation is not added to ordinary public pages. Preview links/forms are disabled; the iframe also prevents form submission, popups, and top-level navigation.

Selecting a mapped region selects the inspector field; selecting an inspector field highlights/scrolls the page. Elementor uses document-scoped widget IDs and known leaf selectors, falling back explicitly to the widget region. Known native title/content/category wrappers are also supported. Ambiguous, hidden, SEO, custom ACF, and other unbound fields stay in the inspector. A rendered widget is not proof that every nested setting has a distinct DOM node. Shared/dynamic source attribution and arbitrary custom-theme bindings are not inferred from matching text.

In the existing legacy-profile workflow, reference switching rebinds profile fields using the existing bridge binding rules. If saved fields cannot be rebound, saving is disabled to prevent silently removing them. NOVA drafts instead retain concrete reference identity and require explicit stale-target reconciliation as described above. Missing parent URLs use `needs_parent`, separate from missing references, and do not block the mapping editor. Enabled builder and CPT modules load consistently for discovery, mapping, and strategy REST requests; disabled modules remain disabled.

Run `wp eval-file wp-content/plugins/nova-bridge-suite/modules/api-mapping-context/tests/mapping-workspace.php` for read-only live checks of scopes, grouping, missing-parent behavior, module dependencies, permissions, nonce binding, and legacy navigation. It temporarily filters options in memory and does not write site data. The existing `strategy-unit.php` remains runnable with standalone PHP.
