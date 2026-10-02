# WordPress template fitting integration handover

This handover records the agreed purpose of the mapping module and the remaining integration work between NOVA posting-service and the WordPress plugin. Clients describe what existing template fields can hold, with optional rules and human instructions. NOVA generates its existing source content; posting-service fits that content to the template; WordPress writes the fitted values to the approved native destinations.

We are not asking for changes to NOVA generation or nova-db. The colleague's explanation places adaptation in posting-service and resolves that architectural question. The plugin now supports local destination descriptions, rules, fixed capacity and a backend-neutral export. Use the [implementation handover](destination-mapping-backend-handover.md) for the tested preparation and remaining API connection; the review below records the original gaps.

## Intended flow

1. In WordPress, select an existing page/template and identify the fields that NOVA may populate. Describe each field's purpose, accepted value type and any constraints. Add template or field instructions only where needed.
2. Synchronize the description to posting-service. Keep the WordPress write addresses and update/clone routing in an approved local profile associated with the remote template revision.
3. NOVA produces its normal source content. Posting-service selects the template and adapts the content to its fields and rules.
4. The plugin pulls a delivery containing the final fitted values, their stable destination identities and the selected template revision. It resolves those identities to the approved native fields and executes the write.
5. The plugin reports complete receipt and, separately, verified native publication. Recovery uses the retained delivery and operation evidence rather than repeating generation or creating another page.

The mapping editor should start with the destination: “this is a short button label” or “this is rich text.” Clients should not have to select a stock source such as `top_content` for every destination. Source selection may be an explicit preference if the adaptation contract supports it; it is not the central mapping requirement.

## Example template

These are requirements for one concrete template, not a proposed HTTP payload. Exact API property names should come from posting-service's authoritative schema.

| Existing destination | What it can hold | Rule or instruction |
| --- | --- | --- |
| Hero heading | Plain text | At most 70 characters |
| Introductory section | Rich text | Fit the introduction into this section |
| Primary button label | Plain text within a button | At most 30 characters; describe the action |
| Primary button destination | URL | Supply an appropriate valid link |
| Three existing step rows | A heading and rich-text body per row | Fit content into these three identified slots; do not add, remove or reorder rows |
| Existing badge label | Plain text | Insert exactly one relevant label here |
| Optional secondary section | Rich text | May be omitted when no suitable content exists |

A button can be described through its label and URL fields; the contract does not need a new universal button object merely to support those two values. The UI should show their shared element and purpose. Structured rules cover measurable constraints such as maximum length and available slots. Human instructions cover meaning and edge cases that those rules cannot express.

## Verified implementation and remaining gaps

Reviewed on 2 October 2026: plugin `api-mapping-with-context` commit `60acfbbd8201deb592bea03b589cf0722c7e429b`, version 3.0.0; posting-service `master` commit `145fbcfb7319333e6369796489c068196cb68665`. The later inspected `develop` commit `da51ba64aff39ca79291765081479a7b40a7ad81` changes deployment configuration but has the same files under `service/src` and `service/openapi`. This is source verification, not authenticated verification of a running deployment.

| Area | Current implementation | Work needed for the intended flow |
| --- | --- | --- |
| Template synchronization | Shared CMS template CRUD, defaults and per-page settings are integrated | Reuse these operations with the supported adaptation definition |
| Field description in posting-service | `id`, `label`, `kind`, `required`; kinds are `text`, `rich_text`, `image`, `list` | Represent field purpose, accepted formats, constraints and instructions through the documented contract |
| Mapping in posting-service | Every mapping entry binds `field_id` to one of twelve stock `source_field` names; source and destination kinds must match | Support describing destinations for adaptation without requiring an exact stock-field copy for each one |
| Plugin editor and prepared export | New drafts use destination label, purpose, type and rules without a stock source; trusted native metadata stays local | Connect this description to the supported backend API |
| Human instructions | Template and per-field instructions are saved and exported locally, bounded to 8,000 UTF-8 bytes | Upload them through supported API properties and make their adaptation status visible |
| Length and repeat rules | Local structured limits and fixed destination groups are implemented; the reviewed API has no corresponding properties | Accept existing capacity and stable slot identities; return fitted values for those slots |
| Adapted content | Current delivery admission copies stock source fields unchanged | Implement or identify the adaptation step and its final output contract |
| Plugin consumption | The native writer reads values by `source_field` | Read fitted values by destination identity and validate their types and approved targets |
| Recovery | Exact delivery retrieval, frozen configuration, durable native operation evidence and event retries are implemented | Include final fitted values in the immutable delivery so retries retain the same output |

The current template schema rejects additional properties. Historical writing schemas found elsewhere in the YAML are not reachable through the active endpoints and cannot be used as an instruction/rules API. We should not invent extra request properties or encode instructions inside a field label.

The verified implementation therefore supports direct stock-field publishing. It does not establish that template fitting works. If the fitting layer already exists in another deployed component, the next step is to identify its contract and integrate it rather than build a second adapter.

### Source evidence

- [Current template input and mapping schema](https://github.com/InSpace-GEO/posting-service/blob/145fbcfb7319333e6369796489c068196cb68665/service/openapi/posting-service.v1.yaml#L971).
- [Template validation and stock-source type matching](https://github.com/InSpace-GEO/posting-service/blob/145fbcfb7319333e6369796489c068196cb68665/service/src/publishing-templates.ts#L28).
- [Delivery admission copying existing source values](https://github.com/InSpace-GEO/posting-service/blob/145fbcfb7319333e6369796489c068196cb68665/service/src/delivery-snapshots.ts#L60). The content-event admission path also copies them unchanged.
- [Plugin template projection](../modules/posting-service/includes/class-nova-bridge-suite-writing-adapter.php), [local draft validation](../modules/api-mapping-context/includes/class-nova-bridge-suite-mapping-drafts.php) and [native writer](../modules/posting-service/includes/class-nova-bridge-suite-mapped-writer.php).

## Integration actions

On the posting-service side, identify or expose the contract for destination descriptions, optional structured rules and human instructions. Supply one complete accepted template request and the corresponding fitted delivery response using the example above. If this is already deployed, provide the relevant implementation/release and example rather than introducing another service.

The response must let the plugin distinguish the three step headings from one another and resolve every returned value to its configured field. Fitted values and the selected configuration must remain fixed for that delivery. Later template edits or a retry must not reinterpret the saved output. Measurable hard constraints should be checked before a delivery is made available; a fitting failure must be explicit rather than silently truncating or dropping content. Human instructions guide adaptation; the contract should not claim deterministic enforcement of arbitrary natural-language rules.

On the plugin side, the source-free description workflow, metadata retention, rule controls and local export are now prepared and tested. Connect synchronization and delivery value lookup once the supported API is identified. Reuse the current approval, discovery, publication and recovery infrastructure. Preserve existing approved profiles; do not rewrite their frozen projection in place.

Native write support must be verified separately from content fitting. The current writer supports its tested scalar targets, including nested ACF scalar leaves and static Elementor heading, text-editor and button-text settings. Elementor button URL writes and compound ACF link/image/list objects are not covered by that verified writer. Add the required typed native handling and tests before claiming those destinations can be populated. Fitting suitable text alone does not establish support for an entire widget.

Keep the agreed local policies: Protected preserves content on both updates and clones; Leave empty skips the field on updates and clears it on clones. Optional adapted fields should have explicit omission behavior, with absence distinct from an intentional empty value. Existing ACF structure remains fixed for the first release. Generic REST field context remains off for these mappings; NOVA's own CPTs remain separate, with predefined mappings optional.

## Testing and first adaptation test

The 3.0.0 migration has already passed 17 offline script invocations and six final isolated WordPress staging canaries. These cover native updates/clones, nested ACF, Elementor rendering, template synchronization, exact delivery validation, receipt/publication events and crash recovery. NOVA HTTP responses were simulated. They do not prove real LLM fitting, application of human instructions or a deployed backend round trip. The [migration report](posting-service-migration-20261002.md) records the counts, evidence and cleanup limitation.

Once the fitting contract is connected, we will run the test on WordPress staging ourselves:

1. Prepare one controlled template with the heading, introduction, three existing step rows and badge. Start with verified scalar destinations; include button/link writes only after their native handling is verified.
2. Synchronize and approve its exact revision, then request one controlled NOVA content delivery through the existing backend workflow.
3. Retain redacted template requests, delivery bytes and event requests for the resulting post. Check that the returned values match field identities, length limits, existing slot capacity and the badge instruction.
4. Apply to a controlled draft, inspect native values and rendered output, then publish it and verify the publication result. Keep the test post and its log available for review.
5. Replay the same delivery and simulate a lost event response. Verify identical retained output, no duplicate post and no repeated native write. Verify that later template edits do not change that delivery.

Usable site credentials and access to the shared CMS API are still prerequisites for the real round trip. We do not need a WordPress-specific API or changes to NOVA generation. We will distinguish receipt acceptance from evidence that the backend applied the reported publication result.
