# NOVA Bridge Suite

- Contributors: jg@inspace.io, ad@inspace.io, lm@inspace.io, af@inspace.io
- Requires at least: 6.0
- Tested up to: 7.1
- Requires PHP: 7.4
- Stable tag: 3.0.0
- License: Proprietary

Connects NOVA to WordPress so your SEO automation can update pages and layouts the standard API cannot reach.

## Description

NOVA Bridge Suite is the WordPress companion plugin for NOVA, your AI SEO automation. It opens safe, controlled paths so NOVA can update content and layout elements that are normally locked behind page builders or WordPress internals.

Use it to automatically:

- Update content and layouts in popular page builders.
- Push SEO metadata and custom fields alongside page updates.
- Manage multilingual updates with WPML and Polylang, and serve per-locale content on Weglot.
- Add rich text below WooCommerce category listings.
- Enable NOVA Blog and Service Page custom post types.
And much more.

Modules can be toggled from `Settings -> NOVA Settings`. The core bridge and post resolver are always on; other modules only run when enabled and when the related plugin is active. The standalone `API Mapping Context` module is enabled by default so existing endpoint mappings remain available after upgrading.

The `API Mapping Context` module provides a focused map of the content destinations NOVA is expected to publish to and lets administrators map each discovered field to NOVA content with field-level publishing guidance. Its compact, mapping-first interface keeps field mappings and instructions prominent while placing routes, transports, request paths, and other implementation details in an optional technical-details disclosure.

### NOVA mapping and publishing (3.0.0)

The Mapping workspace now supports canonical NOVA mapping drafts, explicit source skips, fixed repeat slots, Protected fields and Leave empty behavior. Save locally, synchronize to the site-scoped writing API, then activate a sealed configuration. Mappings and author instructions go directly to NOVA; ordinary REST content responses receive no generic mapping context. Dedicated NOVA CPT context remains separate.

Signed content-ready notifications enter a durable local queue. The worker retrieves the exact content version and configuration, applies supported native/ACF scalar or verified Elementor writes, and recovers interrupted commits and result acknowledgments without creating duplicate clones. Leave empty preserves existing values on updates and blanks selected supported fields on clones. Protected preserves native content and structure.

The plugin requires the backend additions described in the [colleague handover](docs/nova-backend-integration-handoff.md). Those are proposed service changes, not code included in this plugin release or a deployed service. See the [validation record](docs/publishing-integration-validation.md) for tested scope and limitations. Term publishing, typed image/link/list values, arbitrary builders and repeat restructuring are not supported by the initial executor.

This branch is versioned **3.0.0** by request; earlier development snapshots used 3.1.x/3.2.x labels. An installation already reporting one of those higher versions needs an explicit package replacement rather than a normal version-increase update.

### Mapping workspace and retained strategy tools

Open **Settings → NOVA Settings → Mapping**. All unique layouts are shown by default, with an optional imported-strategy scope. A rendered reference sits beside the field inspector. Clicking a field or bound page region selects the other; non-visible fields remain available in the inspector. Unknown editorial regions show a compact notice when clicked. Disabled builder bridges can be enabled directly here; save mapping changes first. Gutenberg exposes whole-document content, not independent block write targets.

Upload one or more NOVA strategy CSV files with a `url` column. Files are combined into one scope and can be removed individually. Shared URLs remain until their last file is removed; saved profiles are retained. Existing imports appear as a removable legacy file. All files must target the same host. The legacy single-CSV/URLs API still replaces the strategy; article content is discarded. The plugin matches existing WordPress content and groups verified layouts using theme templates, actual builder structure and applicable ACF/SCF fields. New URLs require an explicit reference choice. Equivalent unresolved URLs are grouped by hierarchy, page type and locale for a shared reference selection.

Give each layout a name, choose NOVA sources for its fields, and describe how to fill its content sections. Profiles are reused across matching documents and survive strategy reimports. Mappings are private to authenticated editors. Ordinary content and NOVA's self-describing CPTs do not require redundant profiles.

The existing `GET /wp-json/nova-bridge/v1/strategy/context?url=<target-url>` and guarded content transports remain available for legacy callers. The new canonical publishing workflow uses the delivered configuration pin and verified local executor. Discovered legacy write transports do not imply that every target is supported by that executor.

Hidden editorial CPTs can use authenticated `/wp-json/nova-bridge/v1/content/POST_TYPE` routes. Hidden ACF/SCF fields use `meta_all.acf.FIELD_NAME`; groups, repeaters and flexible content require complete structured values. The plugin calls WordPress and ACF/SCF APIs locally, so XML-RPC does not need to be enabled. Protected keys, unsupported providers and system post types are excluded. Native REST flags are preserved.

The importer accepts UTF-8 CSV/JSON up to 10 MB and 10,000 URL rows. The reference inventory is bounded to 5,000 posts and 5,000 product categories; truncation is reported and disables suggestions. The module stores drafts locally, synchronizes explicitly with NOVA, and does not fetch imported URL hosts. See [module documentation](modules/api-mapping-context/README.md) for supported fields and error behavior.

### API Mapping Context

Open `Settings -> NOVA Settings -> API Mapping Context` to inspect Posts, Pages, client-owned editorial custom post types, and one logical WooCommerce Product categories destination when WooCommerce is available. Products and unrelated operational endpoints such as navigation, payments, countries, and plugin configuration are omitted. NOVA's own Service Page CPT and NOVA-managed Blog CPTs are also omitted from this discovery inventory because their dedicated modules already expose the REST context NOVA needs. Eligible client-owned types with REST disabled use the guarded NOVA content transport; unsupported types remain unavailable.

API Mapping Context is a standalone module and does not belong to either custom-post-type module. Disabling it stops discovery, mapping synchronization and delivery processing while retaining saved configuration. Generic REST context injection is disabled regardless of the module's saved legacy guidance preference.

Each destination contains only publishing-related fields: native content fields, featured media, taxonomy assignments, relevant registered custom meta, applicable ACF fields, and the active SEO provider's title and description fields. SEO fields are grouped by provider. Fields verified against an available write transport are marked available; useful fields that are hidden from REST or otherwise lack a writer are marked potential with the reason they cannot currently be changed.

Page-builder mappings come from a selected, concrete document rather than a global widget catalogue. The authenticated `GET /wp-json/nova-bridge/v1/content-endpoints/bridge-fields?post_id=<id>` request loads the actual textual fields returned by the enabled NOVA bridge for that document, including the selector and write contract needed to address them.

Fields use RFC 6901 JSON Pointers, for example `/title`, `/content`, `/meta/blog_intro`, or `/meta/sp_faq/*/answer`. Nested meta and ACF leaves remain visible for mapping but are marked as requiring the complete parent payload when no safe leaf writer exists. Administrators can save a NOVA mapping and guidance for every field.

Retained endpoint defaults support selecting theme templates, a primary template and template-specific instructions. They stay available as saved configuration without inflating discovery with an `@templates` field cross-product. Generic REST responses no longer receive `nova_content_mappings`, `meta_descriptions` or `nova_template_contexts` from this module. New canonical drafts send their mappings and instructions directly to NOVA.

The live inventory at `GET /wp-json/nova-bridge/v1/content-endpoints` is restricted to administrators. Builder-field inspection requires permission to edit the selected document, and saved context is not exposed to anonymous visitors.

## Installation

1. Upload the plugin folder to `wp-content/plugins/` or install the ZIP in `Plugins -> Add New`.
2. Activate `NOVA Bridge Suite`.
3. Go to `Settings -> NOVA Settings` and enable the modules you need.
4. Connect NOVA to your site using WordPress application passwords or another REST authentication method.
5. For canonical mapped publishing, configure the posting-service HTTPS origin, site UUID, site plugin token and webhook signing secret in Mapping. Confirm service compatibility with NOVA before unpausing, then verify a controlled end-to-end delivery before enabling routine publishing.

## Frequently Asked Questions

### Do I need an active NOVA subscription to use this plugin?

This plugin is designed for NOVA. You can activate it without NOVA, but NOVA is the only system trained to use this plugin.

### Will this replace my page builder?

No. It works alongside builders like Avada, Elementor, WPBakery, Beaver Builder, and more. NOVA can update their content safely.

### Does it work on WooCommerce sites?

The API Mapping Context inventory currently supports WooCommerce product categories only; products are intentionally not included yet. If WooCommerce is active you can also enable the optional rich text field module for category pages when the category template needs content below its listings.

## License

NOVA Bridge Suite is proprietary software. Usage is governed by a separate commercial license agreement. See `LICENSE.txt`.

Legacy mapping source keys remain compatible. New canonical draft sources come from NOVA's selected template revision. In the new executor, `leave_empty` skips existing-page writes and blanks selected supported values on clones; Protected preserves them in both cases. Neither choice adds generic REST guidance or intercepts arbitrary writes by other clients.

When creating an Elementor document with `source_page_id`, fields marked `leave_empty` in the source layout profile are copied with empty content. The bridge preserves widget structure and other settings. These omissions override supplied values for those fields during cloning. Ordinary updates still omit writes without clearing existing values. This clone behavior applies to Elementor source-page cloning; explicit full-document replacements are not source clones.
