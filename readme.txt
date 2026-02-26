=== NOVA Bridge Suite ===
Contributors: hypernovatechnologies
Tags: seo, automation, content, rest-api, page builder
Requires at least: 6.0
Tested up to: 6.9.1
Requires PHP: 7.4
Stable tag: 2.1.5
License: Proprietary

Connects NOVA to WordPress so your SEO automation can update pages and layouts the standard API cannot reach.

== Description ==
NOVA Bridge Suite is the WordPress companion plugin for NOVA, your AI SEO automation. It opens safe, controlled paths so NOVA can update content and layout elements that are normally locked behind page builders or WordPress internals.

Use it to:
* Update content and layouts in popular page builders.
* Push SEO metadata and custom fields alongside page updates.
* Manage multilingual updates with WPML.
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
= 2.1.5 =
* Service Page CPT: fixed template 1 intro spacing and improved legacy related-post rendering fallback.
* Service Page CPT: related articles now render stable card grids with clickable images and stronger CSS theme compatibility.
* Service Page CPT: compact spacing values are now the default baseline for new/missing spacing options.

= 2.1.4 =
* Added legacy blog post auto-migration to the enhanced blog CPT structure.
* Enforced single-H1 policy by removing body H1 elements when layout/title H1 is enabled.
* Improved compatibility behavior for upgrading older blog CPT data without manual post resaves.

= 1.1.3 =
* Fixed GitHub update packaging so WordPress can install updates from the repository source archive.
* Update checker now prefers GitHub release ZIP assets that match `NOVA-Bridge-Suite-*.zip`.

= 1.1.2 =
* Added Post Resolver module (`/wp-json/nova-post-resolver/v1/resolve/<id>`).
* Added Elementor `append_html` and `append_faqs` support for bottom appended sections.
* Post Resolver is now always enabled (core behavior).

= 1.0.0 =
* Initial release.

== Upgrade Notice ==
= 2.1.5 =
* Includes Service Page template spacing and related-article layout fixes with compact defaults.

== License ==
NOVA Bridge Suite is proprietary software. Usage is governed by a separate commercial license agreement. See `LICENSE.txt`.
