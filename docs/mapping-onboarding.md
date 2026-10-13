# WordPress layout onboarding

Open **NOVA settings → Layouts** for the endpoint checklist and guided creation-default setup. Existing content is inspected directly as a filled mapping. See [Endpoint defaults and live document mappings](endpoint-document-mappings.md) for the current main flow and local API.

The two older editors described below remain under the collapsed **Other layout tools** section. Their saved drafts and rules are retained independently from endpoint creation defaults.

The agreed next mapping design keeps **static**, **mixed** and **rule-driven** templates separate. Mixed templates combine known fields with explicit repeatable/nested regions; rule-driven templates generate the main content structure and may still retain fixed elements. See [template modes and bridge adapters](template-modes-and-adapters.md) for the design and the current implementation boundary. The existing Elementor widget-rule guide below has not yet been expanded into that broader editor.

Each settings section has its own title: **Layouts**, **NOVA connection** or **Modules**. The two layout editors stay under **Layouts**.

The NOVA screen uses the available WordPress admin width. External theme/plugin admin notices are suppressed on this screen only; WordPress core and NOVA notices remain available. Other admin screens are unaffected.

| Approach | Use it when | What the mapping means |
| --- | --- | --- |
| **Fill an existing layout** | An existing page or supported content item has the design and content fields you want to keep. | Describe what belongs in each existing field and what stays unchanged. |
| **Build new Elementor content** | The article should determine how many headings, paragraphs and other widgets the new page needs. | Choose which Elementor widget each content type becomes. Three paragraphs can create three Text Editor widgets. |

Elementor layout rules **are a mapping**. They do not require a second, fixed-field mapping or a source page. “Text Editor” refers to the actual Elementor widget, not this plugin's article input.

## Installation and modules

Under **Modules → NOVA Core**, **Enable API Mapping Context** is an optional switch for layout setup and API field discovery. The core NOVA Bridge and Post Resolver remain enabled. API Mapping Context defaults on only for a genuinely new installation, subject to standalone-plugin conflicts; an upgrade does not turn a previously disabled or missing API choice on.

At first installation, the suite enables matching supported builder and multilingual bridges for providers already active on the site. Conflicting standalone bridges are skipped. **Auto-detected and enabled by NOVA** identifies a bridge actually enabled by that initial setup, while it remains enabled and the same provider is detected. A manual toggle removes that automatic attribution. Updates and reactivation preserve saved module choices; detection on an existing site can offer a recommendation for an administrator to review.

The suite does not install or activate external builder/language plugins. Initial defaults and later module changes do not switch on the NOVA connection or automatic publication. Elementor test drafts still require Elementor and its NOVA bridge to be available.

## Fill an existing layout

1. **Choose reference.** Select an existing page or supported content item from this site's layout list. Expand **View selected reference (optional)** if you want to inspect it, then choose **Use this reference**.
2. **Describe fields.** Select a highlighted content region in the page preview, or use **Choose a content field** to select a supported field and see its existing content. The preview opens alongside one focused field editor; the layout list is hidden during this stage. Choose **Describe the content for this field**, then answer **What content belongs here?**—for example, a short section heading or a paragraph explaining its benefit. You can also keep existing content or leave a field undecided. Use **Previous field**, **Next field** or **Skip for now** to move through the choices. Formats and constraints stay under **Details and optional limits**; native paths and additional notes stay under **Target details**. Choose **Review setup** to continue.
3. **Review setup.** Check the described, retained and undecided field counts. Give the setup a recognizable name, then choose **Save setup**. Saving retains the descriptions locally and leaves the reference unchanged; unfinished field preparation can also be saved.
4. **Save & hand over.** Choose **Open saved setup** for the workspace or **Export handover** for the field descriptions. The export is a description for integration work; exporting does not make NOVA generate content for those fields automatically.

Earlier delivered-field and repeat bindings are retained without edits during the guide. Their details become available in the saved workspace; synchronization and native writer approval remain separate, advanced operations. Saving field descriptions does not complete those operations.

The guide advances through **Use this reference**, **Review setup** and a confirmed **Save setup**. Step numbers let you return to earlier stages; they cannot skip forward to save or completion. A save conflict keeps your edits and does not open the completion stage. After saving, **Open saved setup** opens the workspace, where all fields and advanced choices are available. Repeated regions there describe existing capacity; they do not create extra native rows.

The reference preview shows the existing content, not a generated result. Known cookie banners and their overlays are hidden automatically, including after refresh or late loading. This affects the preview only and does not accept consent, change cookies or update site settings. **Refresh preview** requests a fresh authenticated link if the view has expired.

## Build new Elementor content

1. **Rules.** Choose the widget for each supported content type. Most types already have one appropriate output; headings and FAQs offer real choices. Optional heading styles apply to all article headings. Their original H1–H6 levels remain unless explicitly overridden.
2. **Try content.** Select the article example or the explicit FAQs/groups example, then choose **Preview Elementor structure**. No code is needed. The article example contains two headings, two paragraphs and a list. To try another article, expand **Use my own article / edit sample HTML**; structured JSON stays under **Advanced**. FAQs require explicit question-and-answer data, and supplied nested groups reuse the same rules.
3. **Review and save.** Review the server-planned widget counts, name the rules and choose the WordPress content type. **Page** is a website page; **Post** is a blog article. **Save layout rules** retains a local revision. It does not create or publish a page, upload rules to NOVA or enable automatic deliveries.
4. **Create a draft.** Set a title and choose **Create Elementor draft** to create a real WordPress draft from the reviewed content and exact saved revision. Open **Edit with Elementor** to inspect native widgets and styling, or **Open WordPress draft** for the standard editor. **Finish setup without a draft** saves the setup for later without creating a page.

The structure preview uses the renderer's planned native elements and a sandboxed content view. It checks order and grouping; it is not an exact Elementor/theme styling preview. Draft creation separately validates provider support, saves through Elementor and checks the stored native result. A failed or uncertain response does not count as verified creation: retry the same content to reconcile its retained operation. **Create another draft** is a separate, explicit action after verified success.

In this guide, reviewing rules requires a complete preview of the current content and rules. Creating a draft or finishing setup also requires an exact saved revision without unsaved rule changes. Editing the rules or sample invalidates its old preview. Successful draft creation, or **Finish setup without a draft**, opens the saved workspace; an already-saved setup can also open its workspace directly.

## Return to a saved workspace

Saved setups appear inside the collapsed **Saved layouts (N)** section on **Layouts**. Expand it and choose **Open workspace** to make the setup's sections available together for edits, previews and local saves. **Guided setup** remains available. Saving changed rules creates a new revision; a delivery preference already bound to an older revision stays on that revision until explicitly changed.

## NOVA connection and generation

**NOVA connection** is shared service setup, separate from either mapping approach. Keeping the connection off does not prevent local mapping, example previews or Elementor test drafts.

This plugin work does not adapt NOVA's content-generation backend or upload the new rendering rules. Existing-layout descriptions remain preparation for a compatible backend contract. Elementor rules arrange content supplied to the plugin; they do not instruct NOVA to write a different article.

The workspace's optional delivery preference is a separate opt-in for compatible, new unmapped deliveries from the connected site. It uses the main article HTML, supports pages and simple post-name URLs in the matching site language, and does not add structured FAQ data. First deliveries create drafts; later versions preserve publication status. Saving rules or this preference alone does not activate the service connection or prove that a live backend is delivering content.

## InSpace assets

The admin design follows the [InSpace brand guide](https://marketing.inspace.io/brand-guide/) and uses its official wordmark, bundled under `modules/api-mapping-context/assets/brand/`. Aeonik and TT Commons Pro Mono are listed as preferred fonts but their licensed font files were not supplied. The documented web fallbacks—Space Grotesk, Inter and Space Mono—are self-hosted with their SIL Open Font License notices. The admin does not need a remote font service.
