# Static and dynamic templates

The mapper offers two choices. They describe how much of the content structure may change; both can preserve branding, navigation, images, dynamic site bindings and other fixed elements.

| Mode | What the writer receives | What changes per article |
| --- | --- | --- |
| Static template | Empty content fields and their instructions, with a complete protected native blueprint. | Field values; the fields and their quantities stay fixed. Optional values may be empty without removing their fixed places. |
| Dynamic template | Fixed content fields plus explicitly declared repeatable, optional or nested regions. | Different quantities of sections, FAQ rows or nested elements per item. Optional parts can be omitted when empty; fixed surrounding design can remain. |

The supplied Rollit Elementor article is a dynamic example: its branding, hero and responsive shells can remain fixed, while article sections expand into desktop cards and mobile tabs. Mobile grouping must be explicit because some desktop sections are combined into one mobile tab. The example is a finished native document, not an exported ruleset.

## What a bridge adapter does

An adapter translates a reviewed template and its filled content into the native structures accepted by a particular destination: Elementor containers/widgets, Gutenberg blocks, builder shortcodes, native builder node trees, or ACF group/repeater/flexible-content values. It then delegates persistence to the existing bridge or custom-field writer.

For example, one `article.sections[]` collection could become a desktop card and a corresponding mobile tab per section. An ACF adapter could instead put each section into a repeater row. The WordPress theme decides how those ACF values appear on the page.

Creating a page without a source page ID is a necessary writer capability, but does not itself implement this translation. The adapter also needs to expand the declared regions, preserve fixed native settings, allocate identifiers and anchors, keep tab labels/panels synchronized, and validate the result against the saved template and installed provider.

A source page may be used during onboarding to capture a design example. Once its complete reusable native blueprint is saved, translation and new-page creation use that saved blueprint and the filled content. They do not read or clone the original source page at runtime. A native starter also avoids a source page during onboarding.

## Current implementation boundary

This branch includes a shared two-choice template editor and recipe compiler, alongside the existing static endpoint defaults, filled existing-document inspection and older basic Elementor content-rule editor. The older renderer is retained for compatibility; it does not implement the new native recipe contract. The compiler retains the stored `static`, `mixed` and `rules` identifiers for compatibility: `mixed` and `rules` both display as Dynamic template, while new dynamic setups use the flexible `mixed` contract. Existing saved recipes and revisions are not migrated.

The versioned `nova-layout-recipe/v1` contract holds a complete native blueprint, explicit scalar bindings and reviewed repeatable/optional regions. A region can use a composite native pattern, such as a heading plus body. Its replacement range and example-pattern range are separate: seven old cards can be replaced by three new cards using one example card, rather than repeating all seven examples for each new item. Nested regions and finite declared layout variants retain supplied content order.

| Adapter | Native recipe output | Mutation boundary |
| --- | --- | --- |
| Elementor | Complete containers/widgets and native page settings/template. | Known editorial widget controls; explicitly selected child regions; coordinated nested-tab headers/panels; per-view content anchors and native IDs. Dynamic controls, global references and unknown widgets stay protected. |
| Gutenberg | Complete parsed native block trees, serialized by WordPress. | Exposed supported text leaves and structural child regions. Dynamic/shared/template blocks and locked content remain protected. |
| WPBakery | Complete native compact/shortcode layout. | Exposed editorial module values and known native child regions. A source that cannot round-trip without changing native fragments is rejected. |
| Avada | Complete native Fusion layout. | Exposed editorial module values and known native child regions, with the same full-source capture checks. |
| Divi 4 | Complete native shortcode layout. | Declared module controls and native child regions; inline heading wrappers are preserved. |
| Divi 5 | Complete native block document through `layout.native_document`. | Declared native controls and structural regions. Locked/global content stays protected; actual draft creation retains provider/parser and raw-document permission gates. |
| Beaver Builder | Complete native hierarchical layout restored to provider node records. | Exposed heading/rich-text/module fields and selected native rows. Starter FAQ output uses heading/body rows; a native accordion needs an appropriate captured pattern. |
| Breakdance | Complete native root/tree with retained envelope context. | Known editorial heading/text/FAQ values and native child regions; provider IDs are regenerated. |
| WordPress content fields | Native title/body fields, or taxonomy name/description values. | Explicit fixed fields and declared section/FAQ collections. Taxonomies have no draft state and this wizard does not publish taxonomy changes. |
| ACF / SCF | Values against the site's installed field schema. | Known writable scalar fields, groups, repeaters and declared flexible-content layouts, including nested rows. Media/relationship identifiers and unsupported structured fields remain protected. |

ACF/SCF can be the main destination model or a companion to a builder recipe. Both consume the same canonical content, while the companion contributes its guarded custom-field payload. Field groups must apply to the destination/new item and template, rather than only the example's identity. A changed installed schema rejects translation until the template is reviewed again. The theme controls the appearance of custom-field values.

Capture does not infer that similar-looking native elements are repeated sections. The administrator chooses the examples to replace, the pattern to reuse, the source collection and any nesting. Desktop/mobile projections consume the same canonical collection with explicit contiguous grouping keys; they do not ask a writer to invent independent copies of an article. HTML normalization retains inline heading markup, source order, heading hierarchy, rich bodies and semantic anchors. FAQs require explicit structured content; the normalizer does not guess questions from ordinary headings. A simple section pattern can consume `body_with_children_html`; a genuinely nested recipe can bind the direct `body_html` and generate its `children` separately.

The compiler does not accept arbitrary generated native trees, styles or identifiers. Server seals protect the complete native blueprint; region patterns must match selected native examples or installed ACF layout declarations. Unknown native properties remain in the blueprint. Bounds include eight recipe nesting levels, at most 500 items per declared region, bounded request/generated JSON and at most 2,000 native nodes; individual provider limits can be stricter. Limits reject oversized content rather than silently truncating it. Conflicting source schemas across device views or the custom-field companion also reject translation.

Native identity changes must retain navigation and styling references. Elementor allocates new node/repeater/tab IDs, rewrites known native selectors and renewed content anchors, and keeps generated tab labels paired with panels. Its provider-owned `isInner` envelope follows the installed Elementor contract before payload freezing: widgets omit the flag, other nodes default to `false`, and explicit non-widget `true` is retained. Full saved-document verification stays strict. A fixed tab-label binding cannot overlap panels being regenerated. A fixed menu linking to an anchor in a removed sample is rejected with guidance to declare its canonical correspondence; the compiler does not guess which new section an old sample meant.

## Guided onboarding

1. Choose the content destination and Static template or Dynamic template.
2. Capture an editable native example or choose an available native starter.
3. Select fixed writable fields, repeatable/nested regions and declared layout variants. Add content instructions and explicit device grouping. Unselected design remains protected; styling changes are made in the native builder and captured again.
4. Try short, long and nested example content in desktop/mobile structure previews. A current preview is required for saving.
5. Save an immutable local revision as the endpoint's creation default, then create a labelled native draft for final review where the destination supports drafts.

The preview is sanitized content structure, not the provider's complete rendered design. It does not execute global widgets, dynamic site references or a customer's theme. Available provider versions, loaded bridge modules, target capabilities and applicable native controls determine whether a real native draft can be created. Full native readback is required for successful draft verification; missing providers or changed content/settings fail explicitly. A retained operation identity and witness journal allow recovery without creating a second draft, while completed drafts can subsequently be edited by the user.

Saving remains local to the WordPress plugin. It does not enable a connection, set backend delivery flags, publish existing content or alter posting-service/nova-agents-backend. Existing content keeps its own identity and live filled mapping. Exported recipe/schema/content are a reusable translation contract; this addition does not establish contracted posting delivery support for every provider or claim live rendering validation for builders absent from the test site.
