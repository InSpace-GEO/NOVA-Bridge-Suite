# API Mapping Context

This standalone NOVA Bridge Suite module discovers relevant WordPress publishing endpoints and the fields NOVA can write to them. Administrators can select usable endpoints, map each API field to a NOVA content key, choose template-specific mappings, and add optional authoring guidance.

The module is independent from the Blog and Service CPT modules. Those NOVA-owned post types keep their dedicated REST context and are deliberately excluded from the generic endpoint inventory.

## Compatibility contracts

The module keeps the existing `Nova_Bridge_Suite_Content_Context` class name, legacy class include path, `nova_bridge_suite_content_contexts` option, schema version, REST routes, filters, JavaScript global, and DOM identifiers. Turning the module off stops discovery and context injection but does not delete saved mappings; they return when the module is enabled again.

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

Responses include `meta_all`, `meta_descriptions`, `nova_content_mappings`, `nova_template_contexts`, and `nova_transport`; saved strategy-profile guidance is applied after generic context. Native route discovery additionally reports `content_bridge`, and hidden CPT resolver results use the guarded route when available. This module does not edit n8n workflows: callers must use the returned route and request paths.

## Developer integration

`Nova_Bridge_Suite_Content_Transport::supports_post_type($type)` checks eligibility; `route_for($post_type)` returns the collection route or an empty string. `field_catalog($post_type, $post_id = 0, $template = '')` returns separate `acf` and `meta` registries, and `read_fields(WP_Post $post)` returns the current editor's safe values. Catalog presence proves a supported field writer, while actual writes still enforce object-scoped permissions.

The final response passes through `nova_bridge_strategy_decorate_record` with the response array, `post`, and the item ID. This lets strategy profiles add their guidance after the native context has been assembled.

## Validation in 3.1.0

Staging verification used WordPress 7.1 and PHP 8.1.34 with ACF Pro 6.8.0.1, SCF 6.9.5, Elementor 4.1.4 and WooCommerce 10.9.3. Both field providers passed the structured-field/authorization roundtrip suite. SCF was removed afterward and the original ACF Pro activation restored.

From a disposable WordPress staging root with ACF Pro (or SCF), Elementor and WooCommerce active:

```sh
wp eval-file wp-content/plugins/nova-bridge-suite/modules/api-mapping-context/tests/strategy-unit.php
wp eval-file wp-content/plugins/nova-bridge-suite/modules/api-mapping-context/tests/strategy-integration.php
wp eval-file wp-content/plugins/nova-bridge-suite/modules/api-mapping-context/tests/content-context-regression.php
```

The integration suites create temporary posts, terms and users and restore their saved mapping options in `finally`. Run them only on a test site. The standalone strategy-unit script performs no site mutations.


## Mapping workspace (3.2.0)

The **Mapping** tab replaces the two previous navigation tabs. Legacy `strategy-mapping`, `api-mapping-context`, `content-context`, and `mapping` bookmarks open the unified workspace. Existing endpoint defaults remain under **Advanced publishing fields and existing defaults**; no configuration migration or deletion is required. Layout profiles continue to override matching defaults through the existing response decorators.

The default queue groups all eligible site content by the existing structural fingerprint. **Imported strategy** filters that queue without deleting other profiles. The catalog enumerates posts and product categories in batches, including editable drafts; detailed fields load when a reference is selected. Current discovery is limited to the module's eligible editorial content types and product categories, not every WordPress infrastructure/commerce object or every possible theme-builder display condition.

Administrator-only, non-cached endpoints:

- `GET /nova-bridge/v1/mapping?scope=all|strategy`: layout groups, membership, strategy reference issues, and counts. The default scope is `all`.
- `GET /nova-bridge/v1/mapping/layout?reference_type=post|term&reference_id=ID&signature=HASH`: fields, saved mappings rebound to this reference, provider diagnostics, and a user/reference-bound preview URL. A changed signature returns 409.

The normal WordPress frontend renders the preview. Its nonce and permissions are checked before rendering and the queried object must match the selected reference. Responses are private/no-store and same-origin framed. Mapping instrumentation is not added to ordinary public pages. Preview links/forms are disabled; the iframe also prevents form submission, popups, and top-level navigation.

Selecting a mapped region selects the inspector field; selecting an inspector field highlights/scrolls the page. Elementor uses document-scoped widget IDs and known leaf selectors, falling back explicitly to the widget region. Known native title/content/category wrappers are also supported. Ambiguous, hidden, SEO, custom ACF, and other unbound fields stay in the inspector. A rendered widget is not proof that every nested setting has a distinct DOM node. Shared/dynamic source attribution and arbitrary custom-theme bindings are not inferred from matching text.

Reference switching rebinds profile fields using the existing bridge binding rules. If saved fields cannot be rebound, saving is disabled to prevent silently removing them. Missing parent URLs now use `needs_parent`, separate from missing references, and do not block the mapping editor. Enabled builder and CPT modules load consistently for discovery, mapping, and strategy REST requests; disabled modules remain disabled.

Run `wp eval-file wp-content/plugins/nova-bridge-suite/modules/api-mapping-context/tests/mapping-workspace.php` for read-only live checks of scopes, grouping, missing-parent behavior, module dependencies, permissions, nonce binding, and legacy navigation. It temporarily filters options in memory and does not write site data. The existing `strategy-unit.php` remains runnable with standalone PHP.
