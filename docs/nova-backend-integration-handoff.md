# NOVA Bridge Suite 3.0.0: backend integration handover

Updated 2026-09-18. Target plugin release: **3.0.0**, on `api-mapping-with-context`.

This handover describes the service behavior required by the plugin and suggests how to implement the remaining additions. The deliverable is the WordPress plugin only. **No backend code is included in the plugin commit.** The plugin's `modules/posting-service` directory contains its WordPress client, queue and writer; it is separate from the posting-service backend repository. Backend experiments remain a local, uncommitted, unshipped prototype; there is no backend commit, pull request or deployment to adopt. The service owner can implement equivalent behavior using the service's preferred architecture.

The existing API below was verified against posting-service commit `86cf4ed2a66ac6b0714d6028131836040985a6df`. This identifies the reviewed source, not the deployed service's current capabilities. Required additions are separated explicitly. Plugin 3.0.0 already sends `authoring_notes` during synchronization and reads exact-version/pin metadata during delivery. **Both synchronization and publishing require compatible service additions before real use.**

## Intended workflow and responsibility split

1. A WordPress administrator chooses a concrete existing page/template reference and an exact NOVA template revision. The plugin discovers native writable fields and lets the administrator map generated sources, add instructions, choose protected destinations, intentionally skip sources, and select existing repeat slots.
2. A local draft can remain incomplete. Saving it does not change native content, synchronize NOVA, or claim publishing readiness. Draft revisions and layout fingerprints are local identities, separate from NOVA resource revisions.
3. Synchronization creates a site-owned template customization, canonical mapping and concrete assignment through the writing API. Global instructions require the additive `authoring_notes` field described below. The stock template is preserved.
4. Activation probes the actual installed writer and selected destinations, records source coverage, seals exact template/mapping/assignment revisions into a pin, and conditionally activates that configuration. Missing coverage, incompatible targets or stale evidence block activation.
5. **The NOVA producer selects the pin before generation.** It generates against that exact compiled template and its instructions, then persists the generated content version together with its pin, digest and repeat-instance correspondence.
6. A signed notification queues that exact content version in WordPress. The worker retrieves the exact content and historical configuration, validates native state, applies the approved update/clone operation, verifies the result and records the exact-version receipt. Retries reconcile retained state instead of guessing a new mapping or repeating an uncertain clone.

Generic mapped-page instructions are never inserted into ordinary WordPress REST responses, including when an old opt-in setting exists. NOVA-owned CPT behavior remains a separate feature. Optional predefined mappings for those CPTs are future convenience work; they are not a prerequisite for arbitrary-page mappings.

## Existing service API used by the plugin

All writing paths in this table are relative to **`/v1/sites/{site_id}/writing`**. The plugin sends `Authorization: Bearer <site plugin token>` and `X-Nova-Plugin-Version: 3.0.0`. Preserve existing authorization, conditional-write and idempotency behavior.

| Purpose | Existing method and path | Relevant contract |
| --- | --- | --- |
| Source catalog | `GET /stock-templates` | Raw array of template records, not a new profile envelope. |
| Exact template | `GET /templates/{template_id}?template_version=N` | Retain the exact revision, fields, groups, layout, protected identities and ETag. |
| Site template customization | `POST /template-clones`; `PUT /templates/{template_id}` | Clone uses `Idempotency-Key` and source template ID/version. Replace uses `If-Match` and `expected_revision_id`. |
| Native mapping | `POST /mappings`; `GET /mappings/{mapping_id}?mapping_revision_id=N`; `PUT /mappings/{mapping_id}` | Bindings contain canonical source identities, target descriptors and expected identities. Creation is idempotent; replacement is conditional. |
| Concrete assignment | `POST /assignments`; `GET /assignments/{assignment_id}?assignment_revision_id=N`; `PUT /assignments/{assignment_id}` | Associates `cpt`/`layout_key` with exact template and mapping revisions. |
| Editable assignment copy | `POST /assignments/{id}/copies` | `If-Match`, `Idempotency-Key` and `base_revision_id`; historical revisions remain recoverable. The service also offers template/mapping copies; this plugin creates fresh customized templates/mappings instead. |
| Writer evidence | `POST /evidence` | Exact mapping ID/revision, `writer_id`, `provider`, `plugin_version`, `db_engine`, `coverage` and `expires_at`. Evidence comes from a fresh native probe. |
| Seal configuration | `POST /pins/seal` | `Idempotency-Key`; exact component IDs, expected revisions and component ETags. |
| Activate configuration | `GET /assignments/{id}/lifecycle`; `POST /assignments/{id}/activate` | Activation uses lifecycle `If-Match`, idempotency and exact component references. |

Template, mapping, assignment and pin IDs/revisions are positive decimal **strings**, avoiding JavaScript integer truncation. Content `version` is a positive JSON integer; `site_id` is a UUID. Local draft revisions are opaque strings. A layout fingerprint describes structure; it is neither a canonical revision nor permission to reuse another page's native IDs. The plugin assignment key identifies the concrete reference, such as `wp_post_123`.

Existing delivery endpoints are:

- `GET /v1/content/{content_id}`: latest ready content at the reviewed service commit. Exact-version selection is an addition below.
- `GET /v1/content/{content_id}/result?version=N`: look up an exact-version receipt.
- `POST /v1/content/{content_id}/result`: submit the receipt with `version` in the JSON body, alongside `outcome`, `remote_post_id`, `cms_post_status`, `fail_reason` and, when supplied by the verified result, `posted_at`.

The existing site `/catalog` and `/mapping` observation endpoints are not the canonical template/mapping save contract. No new generic “save profile” endpoint is needed.

## Required additive contract

The behavior in this section is necessary for the plugin's integration. Endpoint and property names shown here are what plugin 3.0.0 sends or reads. An alternative public shape requires coordinated plugin changes; internal tables, migration numbering and implementation techniques remain the service owner's choice.

### 1. Retrieve the exact notified ready content version

Extend `GET /v1/content/{content_id}` with optional `version`, a positive integer. With `?version=N`, return only ready version N for the authenticated site, or an appropriate not-found/not-ready error. Never silently fall back to latest. Without the query, preserve existing latest-ready behavior for older callers.

The plugin verifies the returned `content_id`, `version` and ready status even after a successful response. A service that ignores the query will therefore block delivery when it returns a different version. Historical content and its configuration references must remain available for recovery.

### 2. Bind each generated projection to immutable configuration

Add these top-level properties to `ContentItem`:

| Property | Shape | Required behavior |
| --- | --- | --- |
| `pin_id` | Positive decimal string | Identifies the exact sealed configuration used to generate this version. |
| `digest` | 64 lowercase hexadecimal characters | Equals that pin's canonical digest. It is not the layout fingerprint or the plugin policy digest. |
| `repeat_instances` | Object from group key to ordered arrays of `{slot_id: string, instance_id: string}` | Required for every mapped repeat group; correspondence rules follow below. |

Keep existing `template_id` and `template_version`, and require them to agree with the pin. Validate pin ownership, template identity and digest before exposing a ready projection. `pin_id` and `digest` form a pair. Older unpinned content can remain readable for legacy consumers, but the contracted WordPress writer rejects it.

Once a pinned version is ready, its generated values and configuration/repeat references must be immutable. Corrections produce a new content version. Otherwise a retry could retrieve different instructions or content under the same delivery identity.

This **illustrative response fragment** shows field placement, not a complete production `ContentItem`. IDs, slot tokens and digest are synthetic; real values must come from the sealed mapping and producer. The service's existing envelope fields still apply.

```json
{
  "content_id": "701",
  "version": 7,
  "status": "ready",
  "template_id": "101",
  "template_version": "3",
  "pin_id": "501",
  "digest": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
  "fields": {
    "page_heading": "Inspect the installation",
    "steps": [
      { "step_title": "Inspect", "step_body": "Check the existing installation." },
      { "step_title": "Maintain", "step_body": "Record the maintenance work." }
    ]
  },
  "repeat_instances": {
    "steps": [
      { "slot_id": "slot-inspect", "instance_id": "step-instance-a" },
      { "slot_id": "slot-maintain", "instance_id": "step-instance-b" }
    ]
  }
}
```

### 3. Let the site plugin retrieve an exact historical pin

Add **`GET /v1/sites/{site_id}/writing/pins/{pin_id}`**, authenticated with the existing site plugin token. The reviewed service exposes an internal workload-authorized pin read; WordPress must not receive workload credentials to use it.

Return the exact immutable pin record with these existing pin identities and contract data:

```text
pin_id, site_id, assignment_id, assignment_revision_id,
template_id, template_version, mapping_id, mapping_revision_id,
digest, family, contract
```

IDs/revisions are decimal strings; `site_id` is a UUID; `digest` is the canonical 64-character hexadecimal digest; `contract` is the sealed contract object. Enforce tenant isolation and normal plugin-version compatibility. The record must remain retrievable after another configuration becomes active.

The plugin checks the remote pin and fetches its exact template, mapping and assignment revisions. It also verifies the canonical plugin policy before using a retained or reconstructed local configuration. Do not substitute the currently active pin or the most recent mapping when the delivered pin is unavailable.

### 4. Retain global authoring instructions canonically

Add optional **`authoring_notes: string`**, maximum **8000 UTF-8 bytes**, to template clone/replace requests and template records/compiled contracts. Default absence to an empty string. Preserve it through clone, replacement, editable copies, compilation, sealing and exact historical reads. Nonempty instructions must participate in the immutable contract/digest used for generation; existing empty stock-template compatibility should be preserved deliberately.

For example, the plugin sends this complete clone request body:

```json
{
  "source_template_id": "101",
  "source_template_version": "3",
  "authoring_notes": "Use the client's terminology and write for facilities managers."
}
```

Its subsequent template `PUT` includes `expected_revision_id`, the complete `fields`, `groups`, `layout`, `examples`, and `authoring_notes`, with `If-Match`. This is not a request to hide instructions inside examples. Per-field instructions continue to use existing field `notes`. The producer must actually use both levels of guidance when generating content; storing them alone does not complete this requirement.

The plugin sends `authoring_notes` even when empty. An older service that rejects this property cannot complete synchronization; the plugin surfaces that error rather than silently discarding guidance or reporting success against an invented fallback contract.

## Mapping policy, intentional omissions and fixed repeats

Canonical bindings retain server-discovered native addresses. Ordinary target descriptors use `{format: "nova_bridge_target_v1", target: <descriptor>}`; repeated source members use `{format: "nova_bridge_target_v1", slots: [{slot_id, target: <descriptor>}]}`. These are plugin-owned opaque descriptors, not arbitrary REST endpoints supplied by a browser.

Each binding's `expected_identity` includes the concrete reference, fingerprint, local draft revision and `plugin_policy_digest`. The first binding also carries `plugin_policy_json`. This canonical JSON retains reference identity, field instructions, protected bindings, Leave empty targets, ordered repeat slots, routing, skipped-source decisions and label. Keys are sorted recursively, array order is preserved, and SHA256 is computed over the UTF-8 JSON with unescaped Unicode/slashes. Preserve these values through mapping revisions. **The policy digest and the overall configuration pin digest are different identities.** Native content/settings payloads are not uploaded as mapping configuration.

An explicit source skip removes that generated field from the **site-owned** template. Skipping a repeat member removes that member; skipping every generated member removes the generated group and its layout node where valid. Protected slots remain intact. Skip decisions and reasons remain in the local draft and canonical plugin policy, with warnings in WordPress. This supports clients who intentionally use only part of NOVA's content while keeping coverage checks honest: remaining generated sources must still be mapped and writable before activation. An unspecified unmapped field is not automatically treated as skipped.

Repeat groups use only selected existing ordered native slots, at most 12 and within the source group's permitted bounds. The customized template fixes the generated row count by setting the group's minimum and maximum to that count. The plugin does not add, delete or reorder native rows.

The producer must supply top-level `repeat_instances`, outside generated `fields`, for every repeated group. Its order and count must match both the generated rows and the sealed target slots. `slot_id` comes from the mapping; `instance_id` comes from a trusted producer and identifies the generated item. Preserve that correspondence across later versions for the same content target. The WordPress content marker rejects a later version that changes established correspondence. Missing metadata is an error, not permission for WordPress to infer identity from array positions.

This fixed-slot model does not prove historical semantic identity of every native row or detect every equal-shape reorder made before planning. Concurrent native edits after planning are checked under transaction locks. Structural repeat edits need a separate supported contract.

## Authentication, delivery and recovery

NOVA administration provisions a site plugin token and webhook signing secret. The reviewed staff API includes `POST /sites/{site_id}/tokens`; provisioning remains a trusted NOVA operation. WordPress receives no staff/OIDC or workload credentials and does not mint its own token. Configure the HTTPS service origin, publishing-site UUID, site token, webhook secret and an appropriate WordPress publishing actor. The service compatibility policy must admit release **3.0.0**.

Ready notifications go to **`POST /wp-json/nova-bridge/v1/posting/ready`** with exactly these four JSON properties:

```json
{
  "event": "content.ready",
  "content_id": "701",
  "site": "123e4567-e89b-42d3-a456-426614174000",
  "version": 7
}
```

Send `X-Nova-Timestamp` as Unix seconds and `X-Nova-Signature: sha256=<lowercase hex>`. Compute HMAC-SHA256 with the site's webhook secret over `timestamp + "." + exact raw UTF-8 request body`. The receiver allows 300 seconds of clock skew and a 16 KiB body. It authenticates the site/event and durably queues before returning 202; fetching content or writing the CMS does not run inside notification intake. The sender can retry the same event safely after uncertain acknowledgment.

The worker retains exact content/configuration and a validated plan before mutation. It records the applying phase, uses transactional native state/operation markers, reconciles uncertain commits, completes required derived work and readback, then posts a result receipt. A lost result acknowledgment is resolved through the exact-version result `GET`. A later content version must not cause an older delayed job to overwrite newer committed content, and a retry must not create another clone. Retain enough historical content, configuration and receipts to support this recovery path.

Administrator controls are private WordPress endpoints: `/mapping/catalog`, `/mapping/draft`, `/mapping/sync`, `/mapping/sync-state`, `/mapping/activate`, `/posting/jobs` and `/posting/jobs/{id}/resume`, under `/wp-json/nova-bridge/v1`. They are not NOVA backend routes. The worker is scheduled every minute; production needs a reliable cron runner.

While the connection is enabled, **pause stops new content mutations** but allows configuration synchronization/activation, uncertain-commit recovery and receipt draining. Notifications can still queue. Disabling the connection stops intake and worker operation. Resume continues the retained phase; it does not authorize guessing a replacement mapping or resetting uncertain work to a fresh write.

## Supported native scope

- Concrete posts/pages and eligible post types with verified native scalar destinations, registered scalar metadata and existing ACF scalar leaves. Draft discovery of term targets does not mean term publication is supported.
- Elementor **4.1.4** with the inspected six-source-hash adapter, including target cache/CSS regeneration. Another version or changed source hashes require renewed adapter verification.
- Existing fixed repeat slots, explicit protected targets and update/clone routing. Leave empty preserves existing values during update and blanks supported destinations on clone without changing structure.
- Verified core taxonomy-count transitions run in the same transaction. Custom count callbacks, count-status filters, attachment-parent dependencies and cloning `post_translations` groups are rejected.

Typed image/link/list writes, arbitrary builders, whole-parent reconstruction and repeat restructuring remain unsupported. Capability probing must reject these before publishing. General WordPress save hooks are bypassed by the contracted writer; arbitrary third-party indexing, CDN/page caches and delayed resaves are not certified by this work.

## Suggested implementation design, not a backend deliverable

The local prototype explored adding projection `pin_id`, `digest` and `repeat_instances` storage with site/template/digest validation and immutable ready-version enforcement, plus canonical template `authoring_notes` storage. Its experimental migrations were numbered 017 and 018. These names, tables, columns and trigger choices are suggestions only; they are not migrations bundled for release or instructions to merge a backend branch.

The required outcome is consistent, tenant-scoped immutable history and exact-version reads, with generation using the selected pin and instructions. The service owner should choose the migration sequence and implementation compatible with the actual service, update its OpenAPI/generated contracts, and add equivalent regression coverage. API/schema support does not automatically connect the real generation/projection producer.

## Evidence and acceptance before rollout

The [plugin integration validation record](publishing-integration-validation.md) is the source of detailed test scope and limitations. Relevant evidence is:

- **185 focused backend tests in 18 files passed against the uncommitted local prototype**, using disposable PostgreSQL and real PHP-generated configuration payloads through Fastify. After immutable ready projections were added, 19 affected tests in two files passed again; these overlap the 185. This is evidence for the proposed contract, not the unchanged/deployed backend. The exploratory full backend run was not green, and no complete Linux CI success is claimed.
- Isolated WordPress canaries passed: mapping/sync **71 checks**, delivery/recovery **25 checks**, native/ACF writer **7 scenarios**, Elementor **7 scenarios**, taxonomy counts **9 checks**, and strategy/discovery **140 checks**. Service HTTP used an exact fixture origin; no production NOVA call occurred. Temporary pages were drafts; simulated publication checks were rolled back.
- Installed staging NOVA remained **2.8.5**, with settings and activation unchanged. The isolated candidate reported **3.2.11** during those tests; the release is now labeled **3.0.0**, with clearer mapping-activation/readiness UI text. Staging tests were not repeated for those metadata/copy changes. Local release checks passed again: seven standalone PHP suites, 44 PHP syntax checks, 17 Node tests, JavaScript syntax checks and all four version markers. No live service deployment or new publishing-controls browser smoke is claimed.

Before enabling a real site, the backend owner and plugin operator should complete this acceptance checklist:

1. Implement/review the four additive contracts above, regenerate service API artifacts, and run normal service CI and migration validation. Confirm plugin 3.0.0 authentication/compatibility, historical reads and cross-site denial.
2. Connect the actual producer: select the sealed pin before generation, use that exact template plus global/field notes, persist pin/digest/template identity with the immutable content version, and emit ordered stable repeat-instance metadata. Do not attach the current pin retrospectively at delivery time.
3. Verify intentional skips, remaining-field coverage and protected-slot preservation through actual generation. Test missing pins, mismatched digests/templates, missing historical versions and invalid repeat correspondence as blocked deliveries.
4. Provision real site credentials and webhook configuration, a permitted WordPress actor and reliable cron. Verify normal deployed plugin bootstrap and the intended native/provider tuple before enabling mutation.
5. Run a controlled HTTPS end-to-end update and clone, then duplicate notifications, process interruption, uncertain commit, lost receipt acknowledgment, an older delayed version and pause/resume. Confirm one native result, exact-version receipts and protected content preservation.
6. Review native limitations and third-party derived effects for that site, record acceptance, and only then enable production mutations.

No backend implementation, producer rollout, credential provisioning, live deployment or production acceptance is completed by committing the plugin. This document is the specification and handover for that remaining work.
