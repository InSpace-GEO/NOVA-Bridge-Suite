# WordPress layout onboarding

Open **NOVA settings → Layouts**. Choose how an article should fit the website. The two approaches are independent; a site can save setups for both.

| Approach | Use it when | What the mapping means |
| --- | --- | --- |
| **Fill an existing layout** | An existing page or supported content item has the design and content fields you want to keep. | Describe what belongs in each existing field and what stays unchanged. |
| **Build new Elementor content** | The article should determine how many headings, paragraphs and other widgets the new page needs. | Choose which Elementor widget each content type becomes. Three paragraphs can create three Text Editor widgets. |

Elementor layout rules **are a mapping**. They do not require a second, fixed-field mapping or a source page. “Text Editor” refers to the actual Elementor widget, not this plugin's article input.

## Fill an existing layout

1. **Choose reference.** Select an existing page or supported content item from this site's layout list. Inspect its reference preview and choose **Use this reference**.
2. **Describe fields.** Give the setup a recognizable name. Explain the content each existing field should receive, or choose what to keep or leave empty. Optional repeated regions describe the layout's existing capacity; they do not create extra native rows. Choose **Review setup** to continue.
3. **Review setup.** Check the field summary, intended use and any warnings, then choose **Save setup**. Saving retains the descriptions locally and leaves the reference unchanged.
4. **Save & hand over.** Choose **Open saved setup** for the workspace or **Export handover** for the field descriptions. The export is a description for integration work; exporting does not make NOVA generate content for those fields automatically.

Earlier delivered-field mappings remain available for review. Their synchronization and native writer approval are separate, advanced operations. Saving field descriptions does not complete those operations.

## Build new Elementor content

1. **Rules.** Choose the widget for each supported content type. Most types already have one appropriate output; headings and FAQs offer real choices. Optional heading styles apply to all article headings. Their original H1–H6 levels remain unless explicitly overridden.
2. **Try content.** Select the article example or the explicit FAQs/groups example, then choose **Preview Elementor structure**. No code is needed. The article example contains two headings, two paragraphs and a list. To try another article, expand **Use my own article / edit sample HTML**; structured JSON stays under **Advanced**. FAQs require explicit question-and-answer data, and supplied nested groups reuse the same rules.
3. **Review and save.** Review the server-planned widget counts, name the rules and choose the WordPress content type. **Page** is a website page; **Post** is a blog article. **Save layout rules** retains a local revision. It does not create or publish a page, upload rules to NOVA or enable automatic deliveries.
4. **Create a draft.** Set a title and choose **Create Elementor draft** to create a real WordPress draft from the reviewed content and exact saved revision. Open **Edit with Elementor** to inspect native widgets and styling, or **Open WordPress draft** for the standard editor. **Finish setup without a draft** saves the setup for later without creating a page.

The structure preview uses the renderer's planned native elements and a sandboxed content view. It checks order and grouping; it is not an exact Elementor/theme styling preview. Draft creation separately validates provider support, saves through Elementor and checks the stored native result. A failed or uncertain response does not count as verified creation: retry the same content to reconcile its retained operation. **Create another draft** is a separate, explicit action after verified success.

## Return to a saved workspace

Saved setups appear on **Layouts**. **Open workspace** makes their sections available together for edits, previews and local saves. **Guided setup** remains available. Saving changed rules creates a new revision; a delivery preference already bound to an older revision stays on that revision until explicitly changed.

## NOVA connection and generation

**NOVA connection** is shared service setup, separate from either mapping approach. Keeping the connection off does not prevent local mapping, example previews or Elementor test drafts.

This plugin work does not adapt NOVA's content-generation backend or upload the new rendering rules. Existing-layout descriptions remain preparation for a compatible backend contract. Elementor rules arrange content supplied to the plugin; they do not instruct NOVA to write a different article.

The workspace's optional delivery preference is a separate opt-in for compatible, new unmapped deliveries from the connected site. It uses the main article HTML, supports pages and simple post-name URLs in the matching site language, and does not add structured FAQ data. First deliveries create drafts; later versions preserve publication status. Saving rules or this preference alone does not activate the service connection or prove that a live backend is delivering content.

## InSpace assets

The admin design follows the [InSpace brand guide](https://marketing.inspace.io/brand-guide/) and uses its official wordmark, bundled under `modules/api-mapping-context/assets/brand/`. Aeonik and TT Commons Pro Mono are listed as preferred fonts but their licensed font files were not supplied. The documented web fallbacks—Space Grotesk, Inter and Space Mono—are self-hosted with their SIL Open Font License notices. The admin does not need a remote font service.
