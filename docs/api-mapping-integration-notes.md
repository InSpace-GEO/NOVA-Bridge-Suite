# NOVA mapping integration: decisions and implementation

Updated 2026-09-18. Plugin release: **3.0.0**, branch `api-mapping-with-context`. Only plugin work is being committed. The inspected posting-service baseline was `86cf4ed2a66ac6b0714d6028131836040985a6df`; backend additions are requirements/proposals for its owner to review and implement.

The initial review treated the supplied `04-plugin-implementation.md` and `05-writing-and-protected-content.md` as design input. Missing examples in those handovers were not evidence that backend features were absent. The actual posting-service OpenAPI, routes, compiler and PostgreSQL store were subsequently supplied and inspected. Local backend prototyping helped validate the contract; that prototype remains uncommitted, unpushed and outside the plugin deliverable. No backend PR or live service deployment is part of this work.

## Confirmed product decisions

- Clients map real pages/templates to canonical NOVA content sources. NOVA owns authoritative templates, mappings, assignments and sealed configurations; WordPress owns discovery, local drafts, exact configuration caches and execution/recovery.
- Generic mapping context does not belong in ordinary REST content responses. The final implementation disables that decoration even if its old preference is saved. Native discovery/content values remain available. NOVA's dedicated CPT context is independent.
- NOVA-owned Blog/Service CPTs remain separate from generic discovery. Predefined mappings for those CPTs are an optional later addition using the same canonical catalog.
- Source fields come from NOVA. Explicit preview fixtures support offline preparation but cannot be synchronized or treated as production identities.
- Incomplete local drafts and intentional skips remain allowed. A skip requires a reason, receives a warning, and removes that generated source from the site-owned canonical template during synchronization. It does not fabricate coverage or weaken sealing rules for the resulting template.
- **Leave empty** preserves an existing-page value and blanks a supported selected scalar on a new clone. **Protected** preserves native content, settings, identity and order in both cases.
- Initial nested ACF scope edits values in existing structure. Native row addition, deletion and reordering are outside scope. Discovery remains available even where the initial writer cannot execute a target.
- Delivery uses the exact content version and sealed configuration, not a guessed endpoint, current active profile or latest content.

## Canonical configuration lifecycle

The plugin uses the actual site-authorized API under `/v1/sites/{site_id}/writing`:

1. Read the raw `stock-templates` array and normalize exact field/group/protected-slot definitions for the editor.
2. Save local drafts separately, scoped to concrete reference type/ID, with opaque revisions and atomic compare-and-swap. The fingerprint detects drift; it is not a remote revision or assignment identity.
3. Clone the selected source and replace its site-owned draft with exact revision and `If-Match`. Mapped field instructions become field `notes`; global guidance becomes `authoring_notes`.
4. Remove explicitly skipped fields/members. Remove an empty generated group with its layout node, retain protected slots, and repair adjacent group anchors. Unspecified unmapped sources remain incomplete.
5. Create canonical source bindings and create/update the concrete assignment. `layout_key` is `wp_post_ID` or `wp_term_ID`; pages sharing a structural fingerprint do not inherit each other's native IDs. A retained assignment is copied before editing.
6. On a separate activation request, probe actual native capability, submit exact mapping evidence, seal the combined configuration, read sealed component revisions, and retain the immutable pin/digest plus local policy before conditional activation.

Local save, synchronization, sealing, activation, native commit and result acknowledgment remain distinct. API failures never promote state to success. Remote operations retain their request body, preconditions and idempotency identity before dispatch. Lost PUT acknowledgments are accepted only when an exact read matches the intended body; another editor's conflicting change is not overwritten by refreshing its ETag.

The normalized catalog and local draft/export are internal interfaces, not requests to a fictional `/profiles` endpoint. The existing `/catalog` and `/mapping` observation APIs are not canonical writing saves.

## Native descriptors, instructions and policy

Descriptors come from trusted WordPress inventory. Individual bindings use `target_descriptor:{format:"nova_bridge_target_v1",target:...}`. Each repeated wildcard member has ordered `slots:[{slot_id,target}]`. Generated content cannot supply arbitrary endpoints or selectors.

Every binding's `expected_identity` includes the concrete reference, fingerprint, local revision and policy SHA256. The first binding also holds `plugin_policy_json`, a canonical JSON string containing the reference, label, all selected-target instructions, protected/Leave empty targets, fixed slots, skips and routing. Object keys sort, arrays retain order, and UTF8/slashes remain unescaped. This retains configuration addresses without uploading native content/settings bytes or exceeding the API's nested-object boundary.

Global `authoring_notes` and generated-field notes are limited to 8000 UTF8 bytes. Other selected-target instructions remain in the canonical plugin policy. On cache loss, the plugin can rebuild an executor snapshot only from the delivered pin's exact retained revisions and valid policy, never from the currently active assignment.

## Fixed repeats and producer identity

For mapped existing slots, synchronization sets group `minItems` and `maxItems` to the same selected count, within the source bounds and at most 12. Every retained generated member needs a distinct target in every slot. Configuration edits do not add/remove/reorder native rows. Protected layout nodes are retained when generated members are omitted.

The producer must generate against the sealed schema and publish top-level `repeat_instances`, for example:

```json
{"steps":[{"slot_id":"local-slot-a","instance_id":"generated-step-a"},{"slot_id":"local-slot-b","instance_id":"generated-step-b"}]}
```

Order corresponds to `fields.steps` and the sealed mapping. Slot IDs are plugin configuration identities; instance IDs belong to generated items. These are trusted producer metadata, not generated prose or row-index guesses. Missing, duplicate, reordered or incompatible correspondence blocks publication. Once content has committed, the native content marker retains correspondence and rejects a later version that changes an established slot's generated instance identity. The backend must retain and return this sidecar, and its producer must supply it. This behavior was exercised in a local prototype, not deployed.

## Delivery and recovery

NOVA administration issues the site plugin token and webhook signing secret. WordPress uses HTTPS Bearer authentication and `X-Nova-Plugin-Version`; staff/workload credentials are not installed. Local settings retain the publishing actor and pause state. The sender signs `timestamp + '.' + exact raw body` using HMAC-SHA256 and sends `content.ready` with exact site/content/version.

Intake stores a unique delivery before returning 202. The worker fetches that exact version, validates its pin/digest/template/repeat metadata, and reads the exact immutable configuration. The journal separates accepted, validated, planned, applying, committed and receipt-pending phases. Native operation/content markers, physical row checks and transaction recovery distinguish a completed commit from an attempt safe to apply again.

After commit, only derived work, final status/readback and result delivery remain. Lost result responses are resolved through exact receipt lookup without republishing. Older content versions cannot overwrite newer committed ones. Pause stops new content mutations while draining uncertain/committed work and receipts; configuration sync/activation can still be prepared while paused. Disabling the connection stops intake/worker execution. Production needs reliable WordPress cron scheduling.

## Verified writer boundary

The initial executor supports concrete posts/pages and eligible CPTs, native title/content/excerpt/slug, eligible existing registered scalar metadata, and supported ACF scalar rows. Nested leaves are eligible only with unambiguous existing physical identities and expected field references. It does not treat a whole-parent `meta_all` replacement as a certified scalar write.

Capability checks verify actual database/provider conditions. Direct writes bypass general WordPress/ACF save hooks. Protected targets remain disjoint from generated/blanking footprints; drift, missing/duplicate rows, unsupported targets and unsupported lifecycle dependencies block activation or execution. New clones require a generated native slug and current actor capabilities.

The exact Elementor **4.1.4** adapter is implemented and passed isolated real rendering/CSS regeneration, protected-byte and omission/clone checks. Registration verifies six inspected source hashes. Other Elementor versions or changed implementations remain blocked; this is not universal provider certification.

Core taxonomy-count support updates verified counts in the same transaction, including tested language-counting draft clones and publication transitions. Custom count callbacks, count-status filters, attachment-parent dependencies and cloning a `post_translations` group remain blocked. These post-assignment count operations do not add support for directly publishing term/category content.

Current exclusions include term/category execution, generated image/link/list objects without typed adapters, `complete_parent`, and native repeat expansion/reordering. The separate legacy guarded `meta_all` API remains broader; its value support must not be mistaken for the contracted executor's capability.

## Backend additions and rollout

The plugin requires exact-version content lookup, plugin-authorized exact pin reads, projection pin/digest/repeat metadata, and canonical `authoring_notes`. Delivered pinned versions must remain stable; corrections use a new version. Guidance must survive clone/edit/seal/copy and participate in immutable contract identity. Preserve existing empty stock digests and legacy unpinned reads; the contracted writer blocks unpinned execution. The backend owner should implement these contract guarantees. The local prototype used migrations 017/018 and ready-projection immutability triggers, but those numbers and storage mechanisms are suggestions, not required implementation choices or files in this plugin commit.

Local compiler/PostgreSQL/authenticated API tests and isolated WordPress canaries verify the implemented protocol. The installed staging plugin was not replaced. Fixture API tests do not deploy either repository or connect the generation producer. Exact result counts, environment details and limitations are maintained in [publishing-integration-validation.md](publishing-integration-validation.md).

The remaining operational handoff is in [nova-backend-integration-handoff.md](nova-backend-integration-handoff.md): review the proposed contract, implement and deploy backend support, connect generation to the exact pin and repeat sidecar, provision the real connection, and verify the intended provider/version and scheduler before production enablement.
