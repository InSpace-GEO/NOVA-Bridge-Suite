# Static, mixed and rule-driven templates

The mapping design keeps three modes. They describe how much of the content structure may change; all three may preserve branding, navigation, images, dynamic site bindings and other fixed elements.

| Mode | What the writer receives | What changes per article |
| --- | --- | --- |
| Static | A blank document with a known set of content fields and their instructions. | Field values; the number and arrangement of fields stay fixed. |
| Mixed | Fixed content fields plus explicitly declared repeatable, optional or nested regions. | Field values and the allowed regions, such as a variable number of section cards or FAQ rows. |
| Rule-driven | A content schema and rules describing how supplied content becomes a native document. | The main article structure, including section counts, nesting and presentation. Fixed surrounding elements can still remain. |

The supplied Rollit Elementor article is a mixed/rule-driven example: its branding, hero and responsive shells can remain fixed, while article sections expand into desktop cards and mobile tabs. Mobile grouping must be explicit because some desktop sections are combined into one mobile tab. The example is a finished native document, not an exported ruleset.

## What a bridge adapter does

An adapter translates a reviewed template and its filled content into the native structures accepted by a particular destination: Elementor containers/widgets, Gutenberg blocks, builder shortcodes, native builder node trees, or ACF group/repeater/flexible-content values. It then delegates persistence to the existing bridge or custom-field writer.

For example, one `article.sections[]` collection could become a desktop card and a corresponding mobile tab per section. An ACF adapter could instead put each section into a repeater row. The WordPress theme decides how those ACF values appear on the page.

Creating a page without a source page ID is a necessary writer capability, but does not itself implement this translation. The adapter also needs to expand the declared regions, preserve fixed native settings, allocate identifiers and anchors, keep tab labels/panels synchronized, and validate the result against the saved template and installed provider.

A source page may be used during onboarding to capture a design example. Once its reusable native blueprint is saved, new-page creation should use that blueprint and the filled content rather than requiring a live source page on every request. A starter blueprint can also avoid a source page during onboarding.

## Current implementation boundary

Static endpoint defaults and filled existing-document mappings remain available. The current local rule editor is an Elementor-only basic widget renderer; it is not yet the three-mode recipe editor described above.

| Bridge | Existing creation without a source page ID | Shared advanced rule compiler |
| --- | --- | --- |
| Elementor | Complete native widget data or its built-in content scaffold. | Basic local renderer only; native prototypes and responsive region rules remain to be added. |
| Gutenberg | Nested blocks, serialized block content or HTML conversion. | Remains to be added. |
| WPBakery | Complete shortcode/compact layout or generated sections. | Remains to be added. |
| Avada | Complete Fusion layout or generated sections. | Remains to be added. |
| Divi 4 | Complete shortcode layout or generated sections. | Remains to be added. |
| Divi 5 | Complete native document through `layout.native_document`, with the provider and raw-document permission checks described in the Divi module README. | A verified native scaffold and rule emitter remain to be added. |
| Beaver Builder | Complete native node tree or generated heading/rich-text structure. | Remains to be added; generated native accordions need a suitable prototype. |
| Breakdance | Complete native JSON or generated heading/text structure. | Remains to be added, including native FAQ/group/image recipes. |
| ACF / SCF | The guarded content transport can write complete groups, repeaters and known flexible-content layouts against an installed schema. | Schema-based recipe expansion remains to be added to the new mapping flow. |

These native writer capabilities do not imply full-document capture, dynamic-region editing, or contracted publishing support for every provider. The newer document-mapping API currently has narrower provider and array-mutation support than the legacy native writers.

## Intended onboarding

1. Choose the destination's template mode: static, mixed or rule-driven.
2. Start with an existing design or a native starter; identify fixed and writable content.
3. For mixed/rule-driven templates, identify repeatable/nested regions and describe their content, native presentation, optional conditions and device grouping.
4. Preview different content sizes and device views, then create a native draft for review.
5. Save the local creation default for that endpoint. Existing pages continue to return their own filled mapping and structure.

This document records the agreed design and the current writer foundation. It does not introduce a new onboarding screen or complete advanced recipe engine. Work remains scoped to the WordPress plugin; neither posting-service nor nova-agents-backend is changed.
