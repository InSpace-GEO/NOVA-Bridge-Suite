# NOVA Service CPT v2 handover

This is the unreleased `2.9.0-rc.1` staging candidate. Native service pages use one flexible section template and an integrated visual editor within WordPress. They do not require the Gutenberg, Essential Blocks or FAQ Block plugins. The site's header and footer remain in place. Site-wide fonts, colors and button styling supply the baseline; service content uses a scoped layout with optional presentation overrides.

## Set up a fresh installation

Open **Services → Service page settings**. Required setup applies only when the site has no existing Service CPT pages or customer configuration; untouched factory settings do not count as customer configuration. Sites with existing services or settings keep their pages, rendering, options and posting access.

The five steps are:

1. **Your business:** enter the business name. Service pages inherit the site's fonts, colors and button styling.
2. **First call to action:** add a required button label and a usable booking/contact URL, phone number, or existing form/calendar shortcode or embed. This action appears in the hero near the top of the page.
3. **People and proof:** optionally add reusable people photos, testimonials, issuer logos and credentials, location photos/address/maps, video or another CTA. Source links are optional. Continue without these when they are unavailable; no Google Cloud or API key setup is required.
4. **Visual style:** choose testimonial and credential appearances and inspect the live service preview.
5. **Review and finish:** check the choices, then select **Finish setup** to activate the shared defaults and one starting layout.

Progress autosaves as a resumable setup draft. Draft saves do not change active shared defaults. Finish validates the business and first CTA, protects against concurrent setup changes, and activates the configuration together. Before completion, manual service pages remain drafts instead of publishing; REST creation and native posting return HTTP 409 with `nova_service_setup_required` and a `setup_url` for the administrator.

The preview uses the actual renderer with inherited site branding. Its illustrative copy, testimonials and credentials exist only in the preview and are never saved as customer content. Forms, calendars and external media are inactive in this preview; test their interaction on a saved page.

Existing installations can use **Setup preview** for a read-only walkthrough. The explicit URL is `/wp-admin/edit.php?post_type=service_page&page=nova-service-shared&tab=setup&demo=1`. It does not autosave setup changes or activate defaults, and Finish is disabled. Opening the walkthrough does not migrate existing pages or replace their configured shared content.

The administration wizard follows the user-provided [InSpace brand guide](https://marketing.inspace.io/brand-guide/), **v3.5 Proposal**. This publisher branding applies to the wizard; public service pages retain the customer's site branding. Asset sources and bundled font licenses are recorded in the [branding notice](../modules/service-page-cpt/assets/branding/NOTICE.md). The guide's private content and commercial font files are not redistributed.

## Configure reusable content once

Open **Services → Service page settings**. The menu's main Services label follows the site's configured CPT name. After required setup, one settings screen contains the configuration tabs and a read-only **Setup preview** walkthrough:

- **Customer defaults:** configure shared images, CTAs, forms, reviews, credentials, locations, maps and video once. The built-in starting layout already references the reserved default versions. Create further versions with stable keys such as `people-amsterdam` or `cta-consultation` when needed. Shared sections cannot reference other shared sections.
- **Default page layout:** arrange the starting sections for new service pages. Choose shared versions for reusable elements and keep each page's approved rich text local. Existing pages keep their saved order; changes to linked shared content continue to flow through.
- **Legacy settings:** retain the previous configuration for existing legacy pages. Old NOVA Services Settings bookmarks redirect here; the duplicate menu entries are hidden. Opening this tab does not migrate pages or replace legacy options.

In Customer defaults, choose the service page to preview. Its linked shared sections appear first, in the actual saved page order. The order outline also shows local rich text and FAQs, which remain editable on that page. Other variations are separated and labelled when they are unused on the selected page. Each shared section explains its page usage and any local field overrides. Changing an unused default version does not change a page linked to another version. Switch to library order when arranging the library itself.

Good shared content includes the team, business locations, Google Maps, business-wide awards, verified reviews, general conversion buttons, forms, calendars and a company introduction video. Page copy and any existing FAQs stay local. Related services are selected automatically from other published service pages; NOVA does not need to supply IDs during posting.

For example, create two People versions for different offices and choose the relevant version on each service page. Create a compact certification section and a larger proof section as separate shared keys if different services need different presentations. Change one shared version to update all its linked pages. Disabling a shared section hides every linked instance; a page's own hidden instance also stays hidden.

Keep shared keys stable after pages reference them. A missing or mismatched reference cannot inherit content; its local overrides remain available. Removing a shared section used by the default layout requires updating that layout first. Restoring the original key/type restores inheritance.

## Edit a service page

Native pages open a full-width visual canvas using the actual Service renderer, site fonts and brand styles. Click a heading to edit it in place; Enter applies and Escape cancels. Click a paragraph to edit rich text directly on the page. A compact toolbar follows the active paragraph or list item and provides paragraph/H2/H3 formatting, bold/italic, lists, links, alignment and text colour. Choose **Done**, click outside the text, or save the page to apply the field; Escape or the cancel icon restores it. Enter adds a paragraph, and Ctrl/Cmd+Z and redo operate only within the active text field until it is applied. Clicking a page photograph opens the media chooser. These edits customize the current page; opening or cancelling an inherited field keeps its shared link intact, including reviews that are shortened in their display layout.

Hover over or select a section to reveal its drag handle and **Settings**, **Duplicate**, **Hide/Show** and **Remove** icons. Drag the handle to reorder sections; the insertion line shows the drop position and the canvas scrolls at its edges. Escape cancels a drag. The focused handle also accepts Up/Down arrow keys. **Sections** provides the page structure, including hidden and empty sections, and lets you add sections or use the equivalent accessible icon controls. Settings open only for the selected section. **Undo/Redo** cover text, media, inheritance and section order; a drag is one undoable change. Desktop and Phone are grouped separately from the purple Sections control and display the responsive layout. **WordPress details** returns to metadata, status, SEO and legacy rollback controls. **Update page**, **Publish page** or **Submit for review** uses the normal WordPress save workflow; nothing is published just by editing the canvas.

Canvas editing preserves the version-2 document, generic posting slots and regular REST response. Shared fields show their source; choosing client defaults removes the corresponding local field or collection override. Administrators can open shared settings separately to change every linked page. Forms, calendars and external media stay inactive in the editor; use the saved page to check those interactions. The shared library and starting-layout settings retain their field editor and preview. Existing legacy pages retain explicit opt-in migration.

Finishing or cancelling rich text preserves the current canvas and editor scroll position. Unchanged or cancelled fields do not reload the canvas; an earlier pending render still resumes when editing ends. Formatting is optional and site styles supply the defaults. Pasted HTML is cleaned; existing rich-text images, tables and their safe layout attributes remain intact when other text changes. Unfinished text edits warn before leaving the screen without saving. Fresh installations show required setup guidance inside the canvas and offer **Save draft** until onboarding is complete.

The authenticated editor-only route is `POST /wp-json/nova-service/v1/editor-preview`, with `mode:page|library|template`, `post_id`, `document` and an optional unsaved `title`. Library/template previews require service settings permissions; page previews require permission to edit the target. It returns an inert standalone HTML preview. Routine posting still reads context from the regular GET response; no context endpoint or publishing-system change is added.

The first hero supplies H1. Leave **Display title** empty to follow the WordPress page title. Other section headings use H2 or H3. Place the first useful CTA high on the page. The posting slots are **Rich text 1**, **Rich text 2** and **Rich text 3**. They do not assign a purpose or request new content; preserve the already approved copy.

| Section | Main fields and use |
| --- | --- |
| Hero and first CTA | Title, eyebrow, summary, images and CTA buttons |
| Rich text | Approved copy in its existing order; optional images |
| People / Location images | Multiple images, alt text and captions; location address |
| Google Maps and address | Address and an HTTPS Google Maps embed URL |
| Reviews and social proof | Real testimonial, reviewer, optional source link and rating |
| Awards and certifications | Title, description, badge/logo and evidence link |
| Call to action | Repeat conversion buttons, copy and optional images |
| Form or calendar | Trusted HTML or an approved installed plugin shortcode |
| Related services | Automatic published service cards; optional manual page override |
| Frequently asked questions | Question and rich-text answer pairs |
| Video | Uploaded HTTPS MP4/WebM or YouTube/Vimeo; optional poster |

Appearance options include default, split, stacked and compact. Split pairs text and images; stacked supports vertical hero/CTA arrangements; compact gives smaller spacing/cards/media where supported. They are section appearances within the single template.

Use **Row width → Half row — two columns** on two adjacent sections to place them side by side. Use **Full row** for a section that should span the page. Mobile displays each section in one column, in the saved reading order. Row width inherits from a linked shared section unless its local Override box is checked; selecting full width explicitly overrides a shared half width. No sticky sidebar is added.

The staging default starts with hero, reviews, then the first two rich-text sections. Full-row rich text headings and content expand across the available container width. Awards and the form each have their own full-width section, with location content between them; the form directly precedes the FAQ. FAQ questions, icons and answers have matching horizontal insets. FAQ and related services each use full rows. Related services use **Site default** or **Service cards** for a bounded horizontal card grid with larger top images (up to 200px high), short titles and short excerpts. Two cards stay bounded to about 420px each, three or more use three desktop columns, tablet uses two columns, and phones stack the cards. **Compact service list** and **Compact** thumbnail variants remain available.

Subtle full-width backgrounds and thin boundaries connect the page sections. Reviews and FAQ use the site's soft background; paired rows alternate soft and plain bands. Adjacent full-row rich-text sections share a light inset divider. These transitions reuse the configured brand colors and preserve hero/CTA overrides, section order, mobile stacking and embedded form styling.

Native images have automatic size limits. Single gallery and inline images retain their proportions within a 640px-wide, 420px-high desktop envelope (300px maximum height on mobile). Hero media is limited to 560px wide and 440px high on desktop, or 480px wide and 320px high on mobile. Heights also shrink with shorter viewports. CTA images use a 360px-wide, 260px-high envelope, with 240px maximum height on mobile. Half-row photos retain their 300px ceiling. Small related thumbnails, review avatars and credential badges retain their dedicated sizes; form/calendar descendants keep provider styling. These limits apply equally to migrated and new native pages and require no changes to stored image data.

Choose **Reusable shared section** to link a matching library version. Unchecked **Override** boxes inherit that field. Check a box to customize only that field; uncheck it to resume inheritance. Image/button/item collections override as a whole. A checked field with an empty value deliberately clears shared content. **Copy shared section into local fields** detaches the section and copies its current effective values.

Administrators can use **Save this layout as site default** from a page. It saves the current section layout for new pages without saving changes to the current page. It clears local hero title/copy, rich-text copy, FAQ answers and related choices, while retaining shared references and reusable presentation defaults. Review the **Default page layout** tab afterwards. **Restore the built-in starting layout** removes the saved site layout.

## Import a few reviews and their source score

In a **Reviews and social proof** section, paste the public profile URL and choose **Import / refresh profile**. The importer returns available review candidates, the source's overall score/count when available, and any provider limitations. Select the reviews to display, choose **Apply selected reviews**, then save the page or shared library. When a profile exposes only its overall score, use **Apply source overall score**. Retrieval and selection are previews; only Apply changes the document. Manual review cards remain available.

The aggregate is the provider's published overall score, retaining its 5- or 10-point scale. It is never inferred from the few selected reviews. **Provider overall** is that provider's own aggregate; **Published combined overall** is its published aggregate across multiple listed sources. The source metadata preserves this distinction. Individual cards retain the original provider score and a normalized 0–5 rating for consistent star rendering.

Supported Trustpilot and Kiyoh profiles can supply snapshot cards through public JSON-LD when review text is present. WebwinkelKeur also supports its public profile's strictly parsed embedded JSON: the tested profile exposes its actual combined score/count and six selectable reviews. Its combined aggregate can cover other sources while selected cards come directly from the profile; if only its own-source score is available, the preview labels that scope and reports the limitation. A Thuiswinkel certificate is certification evidence; its linked Kiyoh review profile may provide reviews. Profiles that require authentication or load reviews through client-side code can return an actionable error/warning. This importer does not bypass those restrictions. Snapshot reviews append to existing cards, deduplicating matching author/text/rating; remove any older cards you no longer need and refresh the source when updating them.

Google retrieval uses the pasted public profile URL. The tested Google business profile does not expose selectable review cards in its public response. The importer reports this honestly and offers **Add Google profile link**. This adds a compact source link beside review cards; it adds no map and invents no score. Enter verified reviews manually with **Source or publisher**; the original review URL is optional. A profile link requires no remote request on each page render. If the source has a safe business map reference, **Google source display** can explicitly opt into its official map widget. The widget does not create selectable review cards or a separate imported score. Older widget references also default to the compact profile link until explicitly set to display a widget. A dedicated map section remains available when location information is useful.

For available Google review candidates, Apply saves only the profile reference and selected review identifiers. Google review text, business name, overall rating and review count are refreshed from the public source for display and are not persisted in the service document. Availability can change: if a selected review disappears from the returned set, refresh the picker and choose another available review. Existing manual cards remain separate from the Google reference.

For a linked shared Reviews section, check **Override review source** before importing for that page. Snapshot card imports also require **Override items**; an overall-score-only import does not. Google references and widgets leave manual items unchanged. Alternatively, use **Copy shared section into local fields**, or import once in the shared library to configure the source/selection for every linked page.

Manual cards work without provider retrieval. Use **Add review** to write one, or **Paste several verified reviews** for a CSV with `author,rating,body,url` columns. Rating (0–5) and URL are optional; author and review text are required. Put commas, line breaks or quotes inside a quoted CSV field and escape a quote as `""`. Preview the pasted reviews, select the cards to keep, then **Add selected pasted reviews** and save. Matching author/text/rating are deduplicated. This does not invent or recalculate an overall provider score.

Reviews support six additional **Section appearance** choices: **Editorial** (`editorial`), **Conversation** (`conversation`), **Spotlight** (`spotlight`), **Publisher cards** (`publisher_cards`), **Compact rail** (`compact_rail`) and **Signature** (`signature`). They present the same supplied quotes as an editorial feature, speech bubbles, a contrasting quote band, publisher cards, a compact shared band, or an identity-and-quote layout. Every supplied review is retained. Existing **Site default**, **Review cards**, **Review list** and **Compact** appearances remain available. An imported source's overall score stays separate from the selected individual cards. Site fonts, colors and shared styling remain the baseline; choose a common appearance in **Customer defaults** and leave page overrides unchecked to inherit it.

**Link to review sources** (`data.link_sources`) controls clickable publisher/profile and author attribution links. Source URLs are optional for manually supplied reviews. Leave the setting enabled for the existing behavior, or disable it to retain source names and logos without links; stored URLs can still identify platform branding. Missing `link_sources` behaves as `true`, preserving older documents. The setting can be inherited from Customer defaults or overridden per page, just like the appearance. Required attribution for live provider-supplied content and controls inside an official provider iframe remain with that provider.

For example, an optional routine API override is `{"nova_service_content":{"section_overrides":{"reviews":{"data":{"layout":"editorial","link_sources":false}}}}}`. Use the actual review section ID from the ordinary GET response. Omit these fields to keep the customer's choices; `inherit:["layout","link_sources"]` resumes the shared defaults. The existing `meta_descriptions` in normal GET responses explain both controls.

Platform reviews show their source row above the quote; website testimonials show the publisher in the attribution footer. An actual provider URL identifies the platform; a free-text label alone does not assign a provider logo. Google Maps sources use the original packaged attribution logo. Other sources retain a clear name badge and can use a customer-configured logo. Set **Publisher / source logo** once in a shared Reviews section (`data.source_logo`); individual cards can override it with `items[].source_logo`. Both fields are optional. A website testimonial keeps its website publisher branding instead of taking a nearby vendor profile's identity. Source links and any published overall score remain separate from the selected quotes.

Provider logos carry the optional source link when available, with an accessible source name. A logo does not repeat the same provider name as a separate visible text link; a source without a logo retains its text link. Descriptive testimonial publisher labels remain visible. Disabling source links preserves the branding without a clickable logo.

Website testimonials use a quote-led design: a decorative quotation mark, larger quote typography, and a customer attribution footer with the publisher logo and original source link. A supplied customer photo is used as-is; otherwise the footer can show a neutral initial derived from the existing author name. It adds no invented person, role, verification mark or rating. Explicit ratings remain visible in a secondary position. Recognized platform reviews retain their platform/rating header. Quotes and stored shared selections are unchanged by this presentation.

For credentials, upload the issuer's actual logo, earned badge or certificate image in **Issuer logo, badge or certificate image** (`items[].image`). **Issuer or organization** (`items[].issuer`) is separate from the optional evidence link label (`items[].source`) and evidence URL (`items[].url`). Images link to their evidence when supplied. Choose **Logo and badge cards** (`badges`), **Logo strip** (`logos`), **Credential cards** (`cards`), **Compact credential list** (`list`) or **Compact**. These presentations preserve the original image proportions and inherit the site's fonts and colors. A registration without an image remains labelled text; the renderer creates no certification seal.

Awards and certifications also offer six additional **Section appearance** choices: **Logo rail** (`logo_rail`), **Featured membership** (`featured_membership`), **Editorial** (`editorial`), **Credential tiles** (`credential_tiles`), **Trust band** (`trust_band`) and **Evidence accordion** (`evidence_accordion`). Logo rail joins the items into a compact strip; Featured membership gives the first nonempty item prominence with the rest as supporting rows; Editorial uses simple evidence rows; Credential tiles gives each item its own image-ready surface; Trust band uses the site's contrasting palette; Evidence accordion uses native expandable rows with the first nonempty item initially open. All supplied items and their complete descriptions remain present, including credentials with no image. Neutral document outlines indicate missing imagery without creating a certification seal. Uploaded logos and certificate images keep their natural proportions.

Choose these styles once in **Customer defaults**, then let linked pages inherit them or override the appearance for one page. Existing saved styles are retained. **Link to credential sources** uses the same optional `data.link_sources` boolean: omitted means `true`; `false` shows issuer images and text without image/evidence anchors. Evidence URLs are optional. The accordion keeps its summary as one keyboard control and places any evidence link inside the expanded content. Its disclosures are separate from FAQs and create no FAQ structured data.

For routine API posting, an optional override looks like `{"nova_service_content":{"section_overrides":{"credentials":{"data":{"layout":"logo_rail","link_sources":false}}}}}`. Use the actual credential section ID. Omission preserves shared content and choices; `inherit:["layout","link_sources"]` resumes the customer's defaults. Ordinary GET `meta_descriptions` includes these choices alongside the existing review guidance.

## Migrate existing pages deliberately

An upgrade leaves existing legacy pages in their original experience. Open a legacy page, choose **Preview new layout**, read the conversion warnings and inspect the result. **Use new editor for this page** explicitly switches that page. WordPress content and legacy `sp_*` meta remain preserved.

Migration retains supported legacy copy, images, CTAs, tables, tabs, FAQs and related links as native sections. Existing related blog links become manual cards; service links remain service references. Static block content can be imported, while dynamic/custom block behavior may need replacement. Check image roles, hidden legacy fields, forms and embedded content. Unsupported or unsafe markup is sanitized. If the conversion exceeds document limits, migration stops with warnings rather than silently dropping content.

**Return this page to the legacy layout** restores legacy rendering while keeping the edited native document. Returning to the new editor reuses that saved native document; repeated migration does not overwrite native edits. Save pending edits before switching experiences.

## Post content from NOVA

On a fresh site, an administrator must finish setup before REST service creation or native content posting. A pending setup response includes HTTP 409, `nova_service_setup_required` and `setup_url`. Existing installations bypass required setup and keep their existing posting behavior.

Authenticate using the site's normal WordPress REST mechanism. Read an ordinary service response, such as `GET /wp-json/wp/v2/services/{id}`. It includes `meta_note` and `meta_descriptions`, matching the Blog CPT pattern. The same guidance appears in collection responses. There is no separate context endpoint and no need to request `context=edit` for posting instructions.

Create or update through `/wp-json/wp/v2/{configured-service-slug}`; the default slug is `services`. Prefer `nova_service_content` for routine posting so NOVA does not need to choose shared media, CTAs or proof on every request:

```json
{
  "title": "Service page title",
  "status": "draft",
  "nova_service_content": {
    "hero": { "body": "<p>The existing approved opening paragraph.</p>" },
    "rich_text_1": { "body": "<h2>Existing heading</h2><p>The first part of the approved copy.</p>" },
    "rich_text_2": { "body": "<h2>Existing heading</h2><p>The next part of the approved copy.</p>" },
    "rich_text_3": { "body": "<h2>Existing heading</h2><p>The final part of the approved copy.</p>" },
    "faq": [{ "question": "An existing question?", "answer": "<p>Its existing approved answer.</p>" }]
  }
}
```

`hero` accepts `title`, `eyebrow` and `body`. `rich_text_1`, `rich_text_2` and `rich_text_3` accept `title` and `body`. There is no intent field. Copy is already approved: do not rewrite, summarize, expand, duplicate or reorder it during posting. Use an existing opening paragraph for the hero only when the approved copy includes one. Do not generate an introduction or FAQs to fill empty components.

With three active rich-text slots, distribute the remaining approved body roughly 30% / 30% / 40%, in source order. With two active slots use roughly 40% / 60%; with one use 100%. The response guidance adjusts to the page's enabled slots. Percentages are approximate: split at existing heading, paragraph, list or table boundaries, keeping each unit intact. Short or indivisible copy may stay in one active slot; leave other slots empty. Disabled components are omitted from the returned routine content and descriptions.

Content writes patch supplied keys and retain the page's shared selections and omitted fields. Existing hidden sections stay hidden during a content refresh; newly added sections and supplied sections on a new page are enabled. Native content writes explicitly opt legacy pages into v2 while preserving the legacy source.

The content API records generic section-level `content_slot` metadata (`rich_text_1`, `rich_text_2` or `rich_text_3`) so moving a section or changing its ID does not redirect later content updates. Old offer/how/why payload aliases and saved documents remain compatible, but normal GET responses and schemas advertise only generic slots.

Related cards automatically use up to three other published services, preferring shared taxonomy terms when available and otherwise using recent services. The current post, protected posts and other languages are excluded. Existing explicit card selections remain as page overrides. Do not supply related service IDs in routine posting.

CTAs, custom forms/calendars, credentials, reviews, location images, maps, people and video inherit the customer's settings. Every override is optional. Omission preserves the shared reference and current values. To customize an existing section without replacing the layout, supply `section_overrides` inside `nova_service_content`, keyed by a section ID from the returned `nova_service_document`:

```json
{
  "nova_service_content": {
    "section_overrides": {
      "people": { "data": { "images": [{ "id": 123, "alt": "Approved team image" }] } },
      "custom_cta": { "data": { "shortcode": "[contact-form-7 id=\"123\"]" } },
      "map": { "enabled": false }
    }
  }
}
```

Use actual section IDs and an installed, allowlisted shortcode. Overrides can provide `data`, `enabled` or another matching `shared_key`; supplied data keys override entire fields, while omitted keys keep inheriting. Empty strings/arrays clear an override field. Use `inherit:["images"]`, for example, to remove a local image override and resume the customer default. Empty `shared_key` detaches a copy of the effective content. Unknown section IDs and mismatched shared versions are rejected before writing the page. `section_overrides` is never required for ordinary posting.

Use `nova_service_document` when NOVA needs to change the complete structure. It replaces the entire ordered section list, so include every section to retain. A shared reference looks like:

```json
{
  "version": 2,
  "sections": [
    { "id": "team", "type": "people", "enabled": true, "shared_key": "people-amsterdam", "data": {} },
    { "id": "rich_text_1", "type": "rich_text", "content_slot": "rich_text_1", "enabled": true, "data": { "body": "<p>The approved copy.</p>" } }
  ]
}
```

Missing data keys inherit from the shared section; explicit local keys override whole fields. Empty strings clear scalar fields, empty arrays clear collections and `sections:[]` deliberately removes all sections. Omitting both native fields preserves the current document and experience. New requests using legacy `sp_*` meta or nonempty WordPress content without either native field remain legacy for older integrations.

The migration API is `GET /wp-json/nova-service/v1/pages/{id}/migration`, returning the document, warnings, `can_migrate` and a signed preview URL. POST to the same route with `{"action":"migrate"}`, `{"action":"legacy"}` or `{"action":"v2"}`. Editing permission and normal REST authentication are required.

Review retrieval is `POST /wp-json/nova-service/v1/reviews/import` with `{"url":"https://provider.example/profile"}` using a supported provider profile URL. Its response is a preview, not a document write. Persist non-Google selected cards in `items` and source aggregates, including `rating_scale:5|10` and `aggregate_scope:"provider"|"combined"`, in `data.review_source`. For Google persist only `review_source` with `provider:"google"`, `url`, `selected_review_ids` and an optional `google_place_id` exposed by the source URL; do not copy returned Google review text or aggregate metadata into the document. A Google no-public-data error may include `data.fallback` with a safe `embed_url`: applying the compact fallback stores `{provider:"google",url,display:"profile",embed_url?}`. Missing or empty `selected_review_ids` means a profile-only source with no page-render retrieval or imported average. `display:"widget"` explicitly enables an available safe map reference; omitted display defaults to profile.

## Content and embed limits

Use verified reviews, ratings, awards, certifications, people and locations. Omit unavailable proof. Google Maps accepts approved HTTPS embed URLs; an address can also supply a map. Uploaded video must be an HTTPS MP4/WebM under this site's uploads; YouTube/Vimeo URLs are normalized to embeds with autoplay disabled.

Custom HTML supports safe markup, native form controls and approved HTTPS provider iframes. Supply a real HTTPS or site-relative form endpoint; this plugin does not create a submission backend. Scripts, event handlers and per-button endpoint overrides are stripped. Prefer installed form/calendar shortcodes for plugin-owned flows. Common supported tags include Contact Form 7, WPForms, Gravity Forms, Fluent Forms, Formidable, Ninja Forms, Forminator, BookingPress, Amelia and Bookly. Unsupported or inactive providers cannot execute; stored allowlisted shortcodes survive temporary provider deactivation. Test each provider's actual submission/booking flow on staging.

Developers can extend `nova_service_allowed_shortcodes` and `nova_service_embed_providers`. Current document limits are 60 sections, 20 images and 10 buttons per section, 80 repeated items per section, and 100 related service IDs per section. Invalid API documents are rejected before changing the post; invalid editor saves retain the previously saved sections.

Release remains withheld until the user's staging acceptance testing is complete.
