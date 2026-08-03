=== NOVA Bridge Suite ===
Contributors: jg-inspace
Tags: seo, automation, content, rest-api, page builder
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.7.8
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
No. It works alongside builders like Avada, Elementor, WPBakery, Breakdance, and Beaver Builder so NOVA can update their content safely.

= Does it work on WooCommerce sites? =
Yes. If WooCommerce is active you can enable the rich text field module for category pages.

== Screenshots ==
1. NOVA Settings screen with module toggles.

== Changelog ==

= 2.7.8 =
* Keep generated WPBakery sections together: each title and body now land in one template block instead of being spread across unrelated containers.
* Stop overwriting hero, call-to-action, image and button rows when filling a content-filled WPBakery template.
* Give leftover WPBakery sections the styling of the template section they follow, instead of appending unstyled rows.
* Apply requested text updates before removing elements, so both can be used in one WPBakery request.

= 2.7.7 =
* Let NOVA update existing Divi Supreme button labels and URLs in native Divi 5 layouts.

= 2.7.6 =
* Apply the suite-wide SEO and custom-field metadata payload on Divi create/update requests.
* Allow one Divi module path to update multiple fields, such as an accordion question and answer.
* Inspect, clone, and update existing native Divi 5 text fields without reserializing their layout attributes.
* Reject stale native paths and unsupported Divi 5 structural edits before content can be changed.

= 2.7.5 =
* Preserve block-theme header/footer styling and Gutenberg service-page structure during automated updates.

= 2.7.4 =
* Preserve legacy Avada title heading levels when updating or appending content.

= 2.7.3 =
* Keep anchored Avada content in its own section instead of inserting it inside an existing builder column.

= 2.7.2 =
* Let Elementor create and update requests set the featured image, categories, excerpt, and SEO metadata in one call.

= 2.7.1 =
* Fix Elementor Nested Accordion questions retaining cloned template titles by exposing their item_title fields to NOVA.

= 2.7.0 =
* Add the NOVA Divi Bridge for creating, updating, cloning, and inspecting Divi Builder pages through the nova-divi/v1 REST API.

= 2.6.1 =
* Beaver Builder appended rich text can now be placed after or before a top-level row, and renders without inheriting template tab, animation, custom CSS/JS, or dark-section color styles.

= 2.6.0 =
* Add Beaver Builder Bridge module: REST bridge (nova-beaver/v1) for creating, updating, cloning, and inspecting Beaver Builder pages. Supports clone-from-template workflows, path-based text updates, SEO metadata, featured images, and hardened appended sections that reuse safe layout/module prototypes from the source page.
* Improve managed CPT templates on block themes by rendering native block header and footer template parts.

= 2.5.13 =
* Add Blog CPT typography size controls, per-CPT style overrides, and module-wide custom CSS.

= 2.5.12 =
* Use paragraph labels for the Blog CPT TOC and key takeaways panels.

= 2.5.11 =
* Render Service CPT content inside theme builder templates.

= 2.5.10 =
* Fix Service CPT support for Astra layouts and permalink refreshes.

= 2.5.9 =
* Fix missing Blog archive CTA `copy` values causing PHP warnings.
* Tested up to WordPress 7.0.

= 2.5.8 =
* Fix rare cases where plugin-managed author pages with custom slugs could 404 or fail to render the custom slug correctly on the live site.

= 2.5.7 =
* Restore three-column NOVA Blog card grids on archive, author, and related-article sections where the 2.5.6 card sizing could collapse to two columns.
* Localize the copy-link share button, success label, and failure label from the Blog CPT language setting.

= 2.5.6 =
* Fixed a remaining 2.5.5 issue where media files could still load above NOVA Blog CPT posts when an attachment slug matched the blog post URL.

= 2.5.5 =
* Prevent Service CPT slugs from competing with media attachment slugs.

= 2.5.4 =
* Keep Astra submenu backgrounds visible on NOVA Blog and Service CPT pages.
* Prevent Astra archive layout classes from squeezing NOVA Blog CPT archive cards.
* Prevent media attachment slugs from forcing Service CPT URLs to receive numeric suffixes such as -3.
* Add optional Body Clean allowlist selectors for preserving extra frontend elements.

= 2.5.3 =
* Improves reliability for ACF flexible-content fields saved through REST acf, meta_all, and meta_all_flat payloads.
* Ensures REST-saved ACF data is finalized in the format ACF expects, so frontend templates can pick it up immediately.

= 2.5.2 =
* Prevent NOVA-managed Blog CPT settings from registering a custom post type on top of an existing CPT or route base.

= 2.5.1 =
* Fix top-level meta_all ACF flexible-content payloads so regular post writes store blocks in ACF's expected format before WordPress prepares the REST response.

= 2.5.0 =
* Rework the Service CPT templates with breadcrumbs, hero background images, sidebar CTA images, and drag-and-drop section ordering.
* Sync Service CPT template section toggles with the section order sorter, including breadcrumbs and Template 3 CTA cover.
* Add Service CPT CTA color controls and a transparent outline preset, with safer handling for empty CTA content and buttons without URLs.
* Improve Service CPT layout behavior so template 2 images and template 3 sidebar CTAs stay centered while text reclaims space when media is missing.
* Use the active site language for default Service CPT labels such as FAQ, related articles, read more, and CTA text.
* Use real service excerpts and meta copy on archive cards instead of placeholder template text.
* Make Service CPT header offset and hero-to-intro spacing apply reliably on the frontend.
* Prevent Service CPT template/settings saves from crashing during template changes or WPML all-languages admin views.
* Keep hero background images, outline styles, and wide/sidebar CTA styles scoped to their own sections so nested blocks do not break the layout.

= 2.4.10 =
* Further reduce memory usage during post edit, save, trash, and delete actions by preventing unnecessary module loading.
* Keep disabled modules from loading through route-aware bootstrap paths.
* Improve ACF support for meta_all, grouped ACF payloads, clone wrappers, and flexible-content fields.

= 2.4.9 =
* Fix Elementor bridge field values being split into arrays by WordPress REST validation when posting Polylang + Elementor translated pages.
* Preserve raw JSON request payloads for Elementor bridge create/update calls so translated widget text is saved as strings and renders correctly on the frontend.

= 2.4.8 =
* Keep Blog and Service CPTs visible in the WordPress REST types endpoint after the route-aware loading changes in 2.4.7.

= 2.4.7 =
* Add route-aware REST bootstrap loading so NOVA endpoints only load the module needed for that request.
* Keep Elementor, WPML, Polylang, Blog CPT, Service CPT, post resolver, core bridge, and update checker from loading into unrelated save requests.
* Preserve Blog and Service CPT support for targeted Elementor and translation requests by loading those CPT modules only when the target post type needs them.
* Limit the bundled update checker to admin and cron contexts to reduce frontend and REST request memory usage.
* Avoid decoding large REST bodies during bootstrap and skip loading the suite for non-NOVA REST and Elementor editor AJAX save requests.
* Keep localized multilingual settings filters out of targeted NOVA REST requests to prevent WPML save-time memory spikes.
* Load only the matching Blog or Service CPT module during admin edit, trash, delete, and untrash requests for those CPT entries.
* Add missing Elementor REST schema types so flexible payload fields validate without PHP warnings.

= 2.4.6 =
* Write API-provided ACF fields through ACF's `update_field()` when received by the `meta_all` handler so hidden reference meta is created without requiring a manual backend save.
* Create hidden ACF reference meta for raw `meta_all`, `meta_all_flat`, and nested `meta` API payloads without invoking ACF's full save lifecycle.
* Keep the full suite out of core REST write requests; only the bridge module loads when a request actually contains `meta_all` or `meta_all_flat`.

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
