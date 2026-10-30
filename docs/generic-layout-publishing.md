# Publishing saved native layouts

A saved layout separates three choices: the NOVA page type (`service`, `informative`, or `category`), the WordPress destination (`post_type:post`, `post_type:page`, or another registered post type), and the builder that owns its native document. An Informative Elementor layout can therefore publish to ordinary Posts without using the NOVA Blog CPT.

The plugin retains the complete reviewed blueprint, its field instructions, and its destination locally. Posting service receives the V3 editorial destinations and instructions. The returned fitted values fill the retained blueprint; publishing does not reread or clone the original example page. Core's configured native title is one authoritative destination, preserving its instructions without adding a competing title field.

## Capability and writer boundary

`Nova_Bridge_Suite_Stored_Layout::publishing_support(resource_id, builder, mode)` supplies the same capability result to the editor and synchronization path. `supports_recipe(record)` additionally checks the saved recipe's supported features. The editor uses this server metadata instead of maintaining builder/destination combinations.

Built-in publishing providers are:

| Builder | Native save | Requirements |
| --- | --- | --- |
| WordPress content fields (`core`) | Update the retained post ID's content, title, excerpt, and template. | Registered post type with writable content and the actor's native create/edit/publish permissions. |
| WordPress blocks (`gutenberg`) | Update the retained post ID with the complete serialized block document and retained native settings. | The content capabilities above and WordPress block parsing/serialization. |
| Elementor (`elementor`) | Use the existing Elementor bridge's known-ID document update. | Elementor, its NOVA bridge and document lifecycle, available widgets, and Elementor support for the selected post type. |
| Flatsome UX Builder (`flatsome`) | Save the retained post ID's native shortcode document, title, excerpt, template and captured page design settings. | The active Flatsome theme and a registered destination with native create/edit/publish permissions. |

These providers share the same writer journal, ownership checks, exact URL resolution, publication policy, and recovery flow. The journal reserves a post ID before calling a provider. A provider saves that ID; it must never create another item. Successful publication requires native readback of the fitted document, settings, identity, and intended URL. Existing items of the matching type retain their ID and URL when adopting the saved layout.

A newer authorized content version replaces the saved layout even when the page's content, styling or images changed after the previous publication, including manual edits. Planning captures the current page as the new operation's baseline instead of requiring the previous success's whole-page fingerprint. Page identity, ownership, editing permissions, stale-version rejection and interrupted-write recovery still apply. Changes during an already planned operation and incorrect native readback remain detectable; old operation records are not rewritten to manufacture a matching baseline.

### Flatsome UX Builder

Choose an existing UX Builder page or post in Layouts. The adapter captures its complete native shortcode layout, including plain HTML between shortcodes, responsive attributes and native page template. Select headings, body fields, links and image controls in the field inventory and add the usual mapping instructions. Unselected values remain part of the saved design. The same saved-layout writer supports ordinary Posts, Pages and eligible custom post types; there is no new REST endpoint or posting-service contract.

Explicitly mapped image elements and background controls import their selected source URL through the shared media importer and store the resulting WordPress attachment ID in the native shortcode attribute. Featured images use the existing common binding. An absent optional image retains the captured image. Extra supplied article images are not automatically inserted into the layout.

Publication compiles the retained snapshot and never rereads or clones the example page. Reusable UX Blocks stay linked by default: their fields are not mapped, and the published page continues using the shared block at render time. In the field-selection step, choose **Capture for mapping** on each block occurrence whose content NOVA should fill. Its native layout is stored in the template and its fields become selectable, without changing the source page or block. The captured copy survives changes to or deletion of its original block. Nested reusable references remain linked until individually captured, and repeated uses of one block can make different choices. Linked blocks must remain available on the site.

Capture preserves native nesting when a block's rows and sections are inserted into the surrounding layout. Publication uses ordinary Flatsome shortcodes; the plugin registers missing bounded nested structural aliases with the same native callbacks on frontend requests. It does not initialize the UX editor registry merely to render a captured layout. Missing or inaccessible blocks cannot be captured, and circular expansion is refused. Other protected native elements keep their original shortcode configuration. The adapter does not copy the example's UX editor history, caches or unrelated metadata. Page-level design settings are separate from the site's global Flatsome configuration.

Flatsome normally omits its editor control registry on ordinary requests. Authoring initializes the installed theme's native registry without changing editor request flags. Captured field definitions are retained as data; publication uses the frontend shortcode writer and does not initialize that registry again. Native `$content` controls are complete rich-text destinations, so several paragraphs, headings, lists or tables can fill one text box without nesting them inside a paragraph shell. Standalone HTML headings remain separate fields; unselected native source stays unchanged.

### Flatsome verification (30 October 2026)

Local checks cover lossless shortcode capture, nested layouts, rich-text replacement, native control discovery, media import manifests, generic post destinations and existing Elementor/core behavior. An additional harness uses the installed Flatsome 3.20.7 source to verify ordinary-request registry loading and actual shortcode rendering; those isolated tests mock WordPress persistence.

With the user's explicit staging exception, the candidate was installed on `inspace-seo.com` and Flatsome was temporarily activated. WP-CLI invoked the same capture, preview, save and draft handlers used by the mapping UI with real WordPress persistence. Service/Page draft 5843 and Informative/Post draft 5851 were created and then updated through the stored-layout provider after removing only their disposable reference drafts. All 78 checks passed, including content order, no duplicate mapped heading, multiple rich-text blocks, actual native shortcode rendering, image/background/featured attachment IDs, and page template/footer preservation. Existing creation defaults and Hello Elementor were restored afterward; the candidate plugin and inactive Flatsome files remain installed.

Selective UX Block capture adds 54 focused adapter checks, 37 API/provenance checks, 103 UI workflows (including existing workflows), and 23 checks using the actual Flatsome renderer. The subsequent staging run passed 42 checks: captured parent and nested blocks 5860/5859 and example page 5861 were deleted before creating draft 5862 from saved profile `9edfe749-05f3-42f5-ad10-66fe2a032ec9`. The untouched linked block 5858 retained its native reference and its later content edit appeared without recapture. Stored compilation and known-ID updates continued after source deletion. A second fresh WordPress process rendered the deeper rows/sections without loading the authoring registry. The original creation default and Hello Elementor were restored. These are real WordPress handler/provider tests over SSH, with no remote posting-service calls or backend delivery exercised.

This was not a browser click test or a posting-service/LLM delivery. Outbound HTTP was blocked. Image responses were streamed from this test's own local fixture files while the actual WordPress import/sideload and retained-media logic ran. Output drafts, local test profiles and their media remain for inspection. No production data or remote template default was changed.

The capability boundary currently covers **static recipes with fixed scalar bindings**. Repeated regions and ACF/SCF companions remain outside this publishing path. Other builders can still have local capture/preview/draft support without claiming delivery support. The existing WooCommerce category adapter remains separate. Registered permalink and site-default-language constraints still apply; a supported builder does not make an arbitrary URL writable.

## Adding another native provider

Register the adapter with `Nova_Bridge_Suite_Recipe_Engine::register_adapter(builder, adapter)`. In addition to the normal recipe compilation contract, it must expose `describe(blueprint)` with writable editorial targets and the complete publication contract below. Existing registered adapters remain authoritative when the publishing compiler loads built-ins.

| Method | Contract |
| --- | --- |
| `publication_support(post_type)` | Return `['supported' => true, 'reason' => '']`, or an unsupported result with an actionable reason. Check the native provider's availability for that post type. |
| `publication_probe(post_type, payload, status)` | Return a stable provider identity array or `WP_Error`, without writing. The writer freezes this identity and checks it again before applying. |
| `publication_snapshot(post_id, payload)` | Return the native state needed to detect changes and reconcile this payload, or `WP_Error`. This is read-only and deterministic. |
| `publication_apply(post_id, payload, post_fields)` | Save only the supplied retained ID and return `true` or `WP_Error`. Honor the supplied native title/excerpt and preserve unrelated identity. |
| `publication_verify(post_id, payload)` | Read back the intended native document/settings; return `true` only for an exact successful save, otherwise `WP_Error`. |

Implement all five methods before advertising publishing support. Compilation returns a `writer_payload`; it must not perform native writes. Provider snapshot/verification must cover every native value that the provider applies so that partial writes cannot be mistaken for completion. The shared writer owns insertion, its journal, publication status, and the delivery receipt.

## Saving and confirming the default

1. Select the WordPress destination, NOVA page type, and Static template; capture and review the example layout.
2. Preview the mapped fields and save the creation default. With an enabled connection and supported provider, the plugin synchronizes that exact saved revision using the existing posting-service template APIs.
3. If another template already handles the NOVA page type, the local revision remains saved and the UI explains the conflict. Choose **Use this template as … default** to explicitly replace the observed default.
4. Replacement requires the observed template ID and defaults revision. A changed remote default stops the operation for another review. The defaults update uses `If-Match`, preserves the other NOVA page types, and is read back before confirmation.
5. **Default confirmed** means the exact saved revision was approved locally and read back as the remote default. **Saved locally** alone does not establish that future deliveries will use it. **Sync saved revision** retries synchronization without creating another local revision.

Switching a default affects future template selection. It does not rewrite already frozen delivery payloads, remove the old template, or publish content by itself. Loading a layout/card displays the retained last synchronization result without making a remote synchronization request.

## Compatibility

New saved-recipe projections use `nova.stored-recipe/v1`, with the builder and native destination sealed into the layout. Previously captured `nova-stored-layout/v1` Elementor Page profiles keep their existing validation and compilation path. Historical immutable profiles are retained.

The native operation journal format remains `nova.stored-layout-document/v1`. New plans retain their builder; older plans without that field continue through the Elementor provider. The existing Elementor provider identity tuple is preserved so an upgrade does not invalidate a retained operation solely through this refactor. Generic providers retain the native snapshot scope needed to compare later versions against prior committed state.

Elementor dynamic tags in native widget, container, page and nested repeater settings remain protected native configuration. For example, a post-title or featured-image tag stays dynamic for the destination post instead of freezing the example's current title or image. These controls are not offered as writable mapping fields; the LLM only fills the selected independent fields. The saved layout retains tag expressions unchanged, including third-party bindings whose native provider remains responsible for rendering. This does not inline external templates or grant the mapper access to dynamic controls. External template references and source-page-specific CSS retain their existing checks.

## Local verification

Run from the repository root with PHP and Node available. These standalone tests use in-memory WordPress/transport fixtures; they do not write to a staging or production database.

```powershell
node modules/api-mapping-context/tests/layout-recipes-ui-unit.cjs
node modules/api-mapping-context/tests/endpoint-mappings-unit.cjs
php modules/api-mapping-context/tests/layout-recipes-sync-api-unit.php
php modules/posting-service/tests/stored-recipe-sync-unit.php
php modules/posting-service/tests/stored-layout-unit.php
php modules/posting-service/tests/stored-layout-writer-unit.php
php modules/posting-service/tests/stored-layout-delivery-unit.php
php modules/posting-service/tests/stored-layout-language-unit.php
php modules/posting-service/tests/stored-layout-default-language-unit.php
```

The generic compiler/writer tests also use the real WordPress block serializer. Point the environment variable at a disposable local WordPress `wp-includes` directory containing `blocks.php` and `class-wp-block-parser.php` (with its parser dependencies):

```powershell
$env:NOVA_WP_CORE_FIXTURE = 'C:\path\to\wordpress\wp-includes'
php modules/posting-service/tests/stored-recipe-layout-unit.php
php modules/posting-service/tests/stored-layout-generic-writer-unit.php
```

Those two commands print **SKIP** if the fixture is missing; that is not a passing verification result. Local fixture success does not establish live staging rendering or a complete posting-service delivery.
# Staging verification (29 October 2026)

The candidate was installed on `inspace-seo.com` by swapping plugin files only, with a verified backup. All 174 packaged PHP files passed the staging PHP 8.1 syntax check. The staging site retained its existing test NOVA connection.

Normal WordPress UI checks confirmed:

- A static Elementor layout with destination Posts and NOVA page type Informative saved locally, then explicitly replaced the previous Informative default using the new reviewed action. Posting service confirmed the exact saved revision.
- Native review draft 5822 was created as a regular Post and rendered its heading and both body fields.
- A fresh setup captured that actual Post, mapped all three native fields, compiled its preview, saved another template and confirmed its Informative default. The confirmation persisted on the Posts card alongside `/wp/v2/posts`; the Service/Page default remained present.
- Two older Elementor test fixtures failed the pre-existing complete-node capture check; they were left unchanged. The newly created native Post captured successfully.

These checks exercise the live setup/sync and review-draft paths. The delivery worker, known-ID saves, higher-version replacements and uncertain-save recovery were verified with local in-memory tests, not a new backend-triggered CIV publication. No production WordPress settings or production database rows were changed by this verification.

## Native dynamic-tag regression (29 October 2026)

Aquavitalis saved its Posts layout locally, but synchronization stopped before HTTP because the old source-independence check rejected every nonempty Elementor `__dynamic__` map. The native recipe engine already retained these protected settings; the publication projection now follows that same contract, including protection of nested repeater controls.

On `inspace-seo.com`, normal Elementor UI editing assigned the native Post Title tag to the isolated draft 5822. A fresh Static/Informative/Posts mapping captured it, exposed only the two independent text fields, synchronized and confirmed remote template revision 1. Native review draft 5828 then rendered its own title (`NOVA retained dynamic title proof 29 Oct`) through the retained tag, plus both text fields. The example title was not frozen into that heading. The site's Hello theme also renders its separate native title above the Elementor document; this test verifies dynamic resolution, not theme title visibility.

Local tests cover protected title, featured-image, internal-URL and third-party expressions, nested controls, exact metadata preservation, known-ID writes/readback/recovery, higher content versions and rejection of mapped dynamic overrides. No backend-triggered publication or production save was performed for this regression.

