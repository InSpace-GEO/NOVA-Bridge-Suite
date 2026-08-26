# NOVA Bridge Suite

- Contributors: jg@inspace.io, ad@inspace.io, lm@inspace.io, af@inspace.io
- Requires at least: 6.0
- Tested up to: 7.1
- Requires PHP: 7.4
- Stable tag: 2.12.1
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

Modules are optional and can be toggled from `Settings -> NOVA Settings`. The core bridge and post resolver are always on; other modules only run when enabled and when the related plugin is active.

The `API Content Context` tab provides a focused map of the content destinations NOVA is expected to publish to and lets administrators map each discovered field to NOVA content with field-level publishing guidance. Its compact, mapping-first interface keeps field mappings and instructions prominent while placing routes, transports, request paths, and other implementation details in an optional technical-details disclosure.

### API Content Context

Open `Settings -> NOVA Settings -> API Content Context` to inspect Posts, Pages, client-owned editorial custom post types, and one logical WooCommerce Product categories destination when WooCommerce is available. Products and unrelated operational endpoints such as navigation, payments, countries, and plugin configuration are omitted. NOVA's own Service Page CPT and NOVA-managed Blog CPTs are also omitted from this discovery inventory because their dedicated modules already expose the REST context NOVA needs. A relevant client-owned custom post type can still be listed as unavailable when its REST API support is disabled.

Each destination contains only publishing-related fields: native content fields, featured media, taxonomy assignments, relevant registered custom meta, applicable ACF fields, and the active SEO provider's title and description fields. SEO fields are grouped by provider. Fields verified against an available write transport are marked available; useful fields that are hidden from REST or otherwise lack a writer are marked potential with the reason they cannot currently be changed.

Page-builder mappings come from a selected, concrete document rather than a global widget catalogue. The authenticated `GET /wp-json/nova-bridge/v1/content-endpoints/bridge-fields?post_id=<id>` request loads the actual textual fields returned by the enabled NOVA bridge for that document, including the selector and write contract needed to address them.

Fields use RFC 6901 JSON Pointers, for example `/title`, `/content`, `/meta/blog_intro`, or `/meta/sp_faq/*/answer`. Nested meta and ACF leaves remain visible for mapping but are marked as requiring the complete parent payload when no safe leaf writer exists. Administrators can save a NOVA mapping and guidance for every field.

For post types with theme templates, administrators choose which templates NOVA uses, may select more than one, and designate one selected template as primary. When multiple templates are selected, each one requires a short explanation of when NOVA should use it. A selected template can also define targeted mapping and guidance overrides for real API fields. Template choices and overrides are stored as configuration instead of inflating discovery with an `@templates` field cross-product. In authenticated edit-context responses, active-template field overrides are merged into the read-only `nova_content_mappings` and `meta_descriptions` objects, while the read-only `nova_template_contexts` object reports the selected, primary, and current template context records.

The live inventory at `GET /wp-json/nova-bridge/v1/content-endpoints` is restricted to administrators. Builder-field inspection requires permission to edit the selected document, and saved context is not exposed to anonymous visitors.

## Installation

1. Upload the plugin folder to `wp-content/plugins/` or install the ZIP in `Plugins -> Add New`.
2. Activate `NOVA Bridge Suite`.
3. Go to `Settings -> NOVA Settings` and enable the modules you need.
4. Connect NOVA to your site using WordPress application passwords or another REST authentication method.

## Frequently Asked Questions

### Do I need an active NOVA subscription to use this plugin?

This plugin is designed for NOVA. You can activate it without NOVA, but NOVA is the only system trained to use this plugin.

### Will this replace my page builder?

No. It works alongside builders like Avada, Elementor, WPBakery, Beaver Builder, and more. NOVA can update their content safely.

### Does it work on WooCommerce sites?

The API Content Context inventory currently supports WooCommerce product categories only; products are intentionally not included yet. If WooCommerce is active you can also enable the optional rich text field module for category pages when the category template needs content below its listings.

## License

NOVA Bridge Suite is proprietary software. Usage is governed by a separate commercial license agreement. See `LICENSE.txt`.
