# Visual manual mapping

The manual mapper turns an existing native page into a reusable creation template. The administrator selects the content that NOVA may replace and explains its purpose. The captured native blueprint retains the surrounding design, styles and protected settings.

## Start with an existing page

1. Choose the destination and **Static template** or **Dynamic template**.
2. Find an existing item by title, local URL or WordPress ID, then choose **Use this existing page**. WordPress verifies its identity, destination and edit permissions and detects its native builder. An ambiguous builder requires an explicit choice.
3. Select a highlighted field in the reference canvas, or choose it from the complete declared native field inventory. Assign its purpose and any instructions.
4. Try example content, check desktop/mobile views and compile the current choices.
5. Save the local creation default. Where drafts are supported, create a labelled native review draft and inspect its actual saved design.

**Start without an existing page** is a secondary option for destinations without a suitable example. It supplies an available native starter and field inventory, but has no existing-page canvas. A source page is an onboarding example: saved translation uses the complete stored blueprint and does not need to clone or reread that page for each new item.

## Choose purpose, not a fixed topic

Purpose choices include title, introduction, rich text, image URL/description, button text, link URL, FAQ question/answer, banner content, call to action and a custom purpose. Only purposes compatible with the native field's format are offered.

Each selected native field receives its own content slot. Two fields with the same purpose remain independent; assigning both as “Rich text” does not automatically duplicate one value into both. Advanced source bindings can deliberately share supplied content when appropriate.

Instructions explain what belongs in the slot, for example “Briefly explain who this page helps.” A custom purpose needs a name and instructions before preview or save. Choosing **Keep existing content** removes that replacement binding. Unselected content and native settings stay fixed.

Map the actual native control. An Elementor Text Editor containing several headings and paragraphs is one rich-text field: its purpose replaces the whole value, not an independently writable paragraph inside it. When a widget exposes multiple controls, the canvas offers a focused chooser. Inspect the current native value when the boundary is unclear.

## Canvas and inventory

The canvas shows the existing page's server-rendered initial state. Its opaque iframe runs only NOVA's selection instrumentation; site scripts, network requests from scripts, forms and embedded frames are disabled. Cookie overlays are hidden locally without changing consent cookies. Selection and highlights remain inside the iframe.

Visual binding requires a verified native locator. Dynamic or unsupported controls, theme-controlled ACF fields, ambiguous DOM identities and fields hidden in the initial state remain available through the inventory where supported. Matching visible text does not establish a native write target. A blank or unbound canvas is not permission to invent a field or change a protected control.

Desktop/mobile widths can reveal different native branches. Review both and use the inventory for content absent from the visible branch. A static initial-state canvas cannot reproduce every script-driven tab, animation or dynamic component; it is not a complete live-rendering test.

## Static and dynamic templates

**Static** keeps the existing fields and element count. Each replacement field may require a supplied value. An empty text value can clear that field while its wrapper remains in the layout; making a scalar field optional does not hide the widget.

**Dynamic** permits explicitly selected repeatable, nested or optional regions while retaining fixed surroundings. The examples to replace and the pattern to repeat are separate selections. Optional regions can disappear when their supplied content is empty. Desktop/mobile grouping must be declared; similar-looking elements do not prove a repetition rule.

## Freshness and review

Captured examples carry a signed `source_receipt` binding the destination, reference identity, builder and native blueprint digest. Saving verifies that receipt and freshly compares the example's native data/schema. If it changed, capture it again and review the mappings before saving. Reloading a saved template can report a stale or unavailable example without replacing the saved revision.

Three views serve different purposes:

| View | What it establishes |
| --- | --- |
| Reference canvas | Where a verified native field appears in the existing example's initial rendering. |
| Compiled content outline | How the selected bindings and regions consume supplied example content. It does not reproduce the full native styling. |
| Native review draft | Whether the installed provider retains the planned native document and how that actual draft renders. |

A current compiled preview is required before saving. Native draft creation uses a retained operation identity and verified readback; an interrupted or uncertain request should be recovered before starting another draft. Taxonomy destinations have no draft state and this onboarding does not publish taxonomy changes.

This flow stores local WordPress mapping metadata and creation defaults. It does not connect an analysis agent, integrate a backend, enable delivery, alter n8n/GCP, publish the source page or apply generated content to it. Staging verification facts are recorded separately.
