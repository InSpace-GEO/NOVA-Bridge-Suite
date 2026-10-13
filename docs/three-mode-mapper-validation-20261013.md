# Three-mode mapper validation — 13 October 2026

Scope: `api-mapping-with-context`, WordPress plugin only. No posting-service or nova-agents-backend changes, no main-branch commit or pull request, no remote backend connection and no public content publication. Legacy mappings, module settings and retained staging examples were preserved.

## What was built

Five-step onboarding for fixed fields, mixed layouts and content-driven layouts. The local versioned recipe captures a complete protected native design, separates replacement examples from reusable patterns, supports recursive optional/repeated regions and declared variants, and supplies one creation default per endpoint. Existing items continue to use their actual filled mapping. Native starters and saved blueprints support creation without reading a source item again.

Adapters cover Elementor, Gutenberg, WPBakery, Avada, Divi 4/5, Beaver Builder, Breakdance, WordPress content fields, and installed ACF/SCF schemas. Known scalar fields, groups, repeaters and flexible-content layouts support guarded nested translation; provider-owned styles, IDs, relationships and dynamic references remain protected. Desktop/mobile branches can consume the same content with explicit grouping. Unknown controls and missing providers fail explicitly.

Immutable local revisions, profile/default concurrency checks, certified blueprints, exact native readback, bounded generation, and a per-operation draft witness/journal protect saving and creation. Taxonomy translation is read-only in this guide because taxonomies have no native draft status.

## Automated checks

| Suite | Passed checks |
| --- | ---: |
| Shared compiler and Elementor | 124 |
| Other native adapters using real WordPress parsers/serializers, including all three controller modes | 299 |
| Registered local API, Core, ACF, transport and compiler harness | 254 |
| Additional focused nested ACF constraints and flexible layouts | 81 |
| Guided JavaScript workflows | 30 |
| New admin shell | 13 |
| Endpoint admin shell | 14 |
| Existing endpoint mappings | 165 |
| Existing document mappings | 146 |

Relevant older content-rule, preview authorization/lifecycle, layout setup, scoped notices, bridge enablement and custom-field instance checks also passed. JavaScript syntax, staged/runtime PHP syntax and `git diff --check` passed. The native adapter suite was run with `NOVA_WP_CORE_FIXTURE` pointing to real WordPress parser, block and shortcode files; its optional parser tests were not skipped. Test doubles exercise provider contracts, not commercial provider frontend rendering.

Focused UI regressions cover refreshing the default's concurrency identity on explicit reload, and showing recovered native draft links outside the current onboarding step while preserving unfinished edits.

## Live staging results

Site: `https://inspace-seo.online/`, WordPress 7.1.3, PHP 8.1.34, NOVA Suite 3.0.0. Core content, Gutenberg and Elementor 3.35.9 were active and available. The final deployed smoke completed **39 checks** across all three modes for those providers: design, preview, local save, blank export, translation, native draft readback, repeated operation recovery, escaped title preservation and source-free creation.

The responsive Elementor example translated seven desktop examples and four mobile tabs into three desktop cards and two explicitly grouped mobile tabs, preserving its fixed CTA, styles and device visibility. Desktop and mobile frontend checks confirmed the resulting content and tab panels. The captured source remained unchanged.

The actual browser onboarding also saved **NOVA staging demo — Mixed Elementor** as the Pages creation default and created draft **5332**, **NOVA mapper review — 20261013**. The native preview displayed six generated sections and six nested FAQ rows. This labelled default and draft remain available for manual review.

Final baseline verification compared exact serialized rows for **126 pre-existing options**, complete post/meta hashes for **six retained native drafts** (5252, 5255, 5258, 5261, 5264, 5267), and hashes for **15 deployed runtime files**. Baseline options and retained posts remained unchanged. Only the new recipe/default/journal stores and labelled test drafts were added. Nineteen duplicate drafts from this validation run were backed up and moved to WordPress Trash; no permanent deletion occurred. Latest native samples 5323, 5324, 5325, 5327, 5329 and 5330 were retained alongside the browser review draft.

The deployment archive contains only the fifteen owned runtime files. Source/settings backups and helper evidence remain in a private directory outside the web root. The one-use web cache helper confirmed successful PHP invalidation and loaded the final runtime, then removed itself; protected result files were moved outside the web root.

## Remaining validation boundary

ACF and the other commercial builders were inactive or unavailable on staging. Their translation, schema constraints, source preservation and controller contracts passed fixture tests, but their actual installed-provider/theme rendering has not been certified here. Native capability checks gate those paths. ACF output appearance is owned by the site's theme, and repeater/flexible fields require installed support for those types. Backend synchronization and contracted publishing of the new recipe format remain future work outside this request.
