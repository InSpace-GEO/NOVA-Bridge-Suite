=== NOVA Bridge Suite ===
Contributors: jg-inspace
Tags: seo, automation, content, rest-api, page builder
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.4.6
License: Proprietary

Connects NOVA to WordPress so your SEO automation can update pages and layouts the standard API cannot reach.

== Description ==
NOVA Bridge Suite is the WordPress companion plugin for NOVA, your AI SEO automation. It opens safe, controlled paths so NOVA can update content and layout elements that are normally locked behind page builders or WordPress internals.

Use it to:
* Update content and layouts in popular page builders.
* Push SEO metadata and custom fields alongside page updates.
* Manage multilingual updates with WPML and Polylang.
* Add rich text below WooCommerce category listings.
* Enable NOVA Blog and Service Page custom post types.

Modules are optional and can be toggled from Settings -> NOVA Settings. The core bridge and post resolver are always on; other modules only run when enabled and when the related plugin is active.

== Installation ==
1. Upload the plugin folder to `wp-content/plugins/` or install the ZIP in Plugins -> Add New.
2. Activate "NOVA Bridge Suite".
3. Go to Settings -> NOVA Settings and enable the modules you need.
4. Connect NOVA to your site using WordPress application passwords or another REST authentication method.

== Frequently Asked Questions ==
= Do I need NOVA to use this plugin? =
This plugin is designed for NOVA automations. You can activate it without NOVA, but its main value is when NOVA is connected.

= Will this replace my page builder? =
No. It works alongside builders like Avada, Elementor, WPBakery, and Breakdance so NOVA can update their content safely.

= Does it work on WooCommerce sites? =
Yes. If WooCommerce is active you can enable the rich text field module for category pages.

== Screenshots ==
1. NOVA Settings screen with module toggles.

== Changelog ==
= 2.4.6 =
* Prevent recursive multilingual option resolution during REST create and update requests so WordPress mutations no longer trigger critical errors on multilingual sites.
* Skip expensive `meta_all` and `meta_all_flat` expansion on non-GET REST responses by default to keep create and update responses lightweight and stable.
* Avoid redundant Elementor cache clears during document finalization and stop clearing the global files cache unless a site explicitly opts in.

= 2.4.5 =
* Prevent managed Blog CPT article slugs from being forced to `-2` when the only matching slug belongs to an uploaded attachment image.
* Normalize existing draft and pending Blog CPT slugs after upgrading so attachment-only collisions no longer leave generated suffixes behind.

= 2.4.3 =
* Enable parent and child relationships for the Blog CPT and Service Page CPT by registering both post types as hierarchical and exposing page attributes in the editor.
* Resolve Blog CPT entries by hierarchical slug paths in the dedicated NOVA blog endpoint so child entries can be fetched with paths like `parent/child`.
* Flush rewrite rules once after upgrading so nested Blog and Service URLs start resolving immediately on 2.4.3.
* Render parent pages inside the Blog CPT fallback breadcrumb trail so child entries show `Archive > Parent > Child` instead of jumping straight from the archive to the current page.

= 2.4.2 =
* Make Blog CPT design settings apply reliably on the frontend by enqueueing the module stylesheet after theme and kit styles.
* Add explicit frontend override rules for filled Blog CPT design fields so per-CPT values correctly override the global blog style settings.
* Point the bundled plugin update checker at `InSpace-GEO/NOVA-Bridge-Suite` so WordPress update checks follow the new release source.

= 2.4.1 =
* Add first-class WPML and Polylang support for the Blog and Service CPT modules via plugin-shipped multilingual config plus runtime hooks for dynamic Blog CPT slugs.
* Register renamed/custom Blog CPT slugs with both multilingual plugins at runtime so translations remain available when the blog post type slug is customized.
* Resolve Blog and Service related post IDs and Service attachment IDs through the active multilingual plugin so translated entries point to translated content instead of the source language.

= 2.3.11 =
* Let Blog CPT text, links, and CTA buttons inherit site styling by default, with optional per-CPT color overrides in the settings UI.
* Move existing posts to the renamed Blog CPT slug during settings saves and remap CPT-specific design and layout overrides to the new slug.
* Use the active CPT label in empty archive messages instead of the old hardcoded fallback copy.
* Mark the release metadata as tested through WordPress 6.9 so 6.9.4 sites report compatibility correctly.
* Guard Blog CPT archive CTA sanitization so clearing settings does not emit `Undefined array key "copy"` warnings.

= 2.3.10 =
* Make the Blog CPT table of contents expand to full width when key takeaways are empty instead of leaving the takeaways column blank.
* Keep the existing two-column TOC and key takeaways layout when both panels are populated.

= 2.3.9 =
* Fix deprecated `mb_convert_encoding(..., 'HTML-ENTITIES', ...)` handling in the Blog CPT DOM parsing flow for public post renders.
* Switch blog heading annotation and H1 stripping to numeric-entity encoding so PHP 8.2+ no longer emits deprecation notices.

= 2.3.8 =
* Improve Elementor full-document persistence so verified `_elementor_data` replacements are applied live instead of silently falling back to stale content.
* Correct Elementor JSON decoding to read stored and incoming document payloads raw-first, with unslashed fallback only for legacy escaped inputs.
* Return hard failures for invalid `elementor_data` payloads and persisted document mismatches instead of reporting a false success.
* Support `elementor_page_settings` during document saves and keep Elementor runtime meta aligned after persistence.
* Strengthen Elementor cache invalidation and post-cache refresh during publish-side document updates.

= 2.3.7 =
* Improve WPML taxonomy translation stability for multilingual WooCommerce category updates.
* Normalize WPML language handling during term writes and add diagnostics for translation state debugging.
* Refresh Yoast term indexables and multilingual caches after translated taxonomy updates to keep SEO output consistent.
* Harden duplicate-slug recovery and REST/meta synchronization for translated product categories.

= 2.3.6 =
* NOVA Gutenberg Bridge: improved merge logic, permissions, and dedup.
* Relax GET permissions to read-only for API consumers and GPT actions.
* Add author field support on create/update endpoints.
* Strip source images, media-only containers, and custom widget blocks during merge.
* Handle cover/hero blocks by replacing headings with the page title and stripping source text.
* Improve dedup with global paragraph dedup and better heading-section collapse.
* Rebuild innerContent null-slot mapping when blocks are removed.
* Add debug logging to the merge flow.

== License ==
NOVA Bridge Suite is proprietary software. Usage is governed by a separate commercial license agreement. See `LICENSE.txt`.
