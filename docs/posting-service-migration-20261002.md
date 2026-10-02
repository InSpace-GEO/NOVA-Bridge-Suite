# Posting-service migration — 2 October 2026

This plugin candidate remains version **3.0.0** on `api-mapping-with-context`. The migration changes WordPress only. It replaces the earlier writing/pin integration with the shared CMS delivery API. The September testing reports describe historical simulated contracts; use this document and [current mapping setup](contracted-mapping-setup.md) for the migrated implementation.

## Contract and mapping

The contract is pinned to posting-service master commit `145fbcfb7319333e6369796489c068196cb68665`. The OpenAPI SHA256 is `ce48ded00914e595dde23d543e347bc748155a7726a108331cc051dc8246af28`; the same API schema was found on develop and the deployment feature branch inspected on 2 October. A source commit identifies the reviewed contract, not the release running on a particular host.

The plugin synchronizes publishing templates through `/v1/sites/{site_id}/templates`, selects per-page templates through `/pages/{url_id}/setting`, and supports `/template-defaults`. Updates use the quoted revision in `If-Match`. Template creation has no server idempotency key: after an unknown response, the plugin retains its intent and requires the reviewed remote template UUID instead of creating a second template.

The twelve supported sources are `title`, `meta_description`, `h1`, `content`, `top_content`, `bottom_content`, `image_url`, `image_urls`, `image_alt`, `url`, `page_type_frontend`, and `language`. They remain selectable for local preparation when API access is unavailable. Unused sources can be omitted from the publishing mapping; this does not stop NOVA generating them.

Native field paths, WordPress IDs, routing, layout fingerprints, Protected/Leave empty and human instructions remain in an approved local profile. Its identity is the site UUID, remote template UUID and exact integer revision. The frozen configuration delivered by NOVA must match that profile before a write. Update deliveries must identify the approved page URL; explicit clones use the approved local source template. Later remote or local edits cannot redirect an already frozen delivery.

**Human instructions remain saved and editable, but are not sent to or enforced by NOVA.** The current API has no instruction/rule property. Examples needing backend support include “insert exactly one relevant label,” length limits and generation that fits existing repeat rows. The plugin does not silently add unsupported JSON fields or claim those rules were applied. Existing custom repeat bindings remain retained for review; this stock delivery API cannot generate their custom members. Existing nested ACF targets can still receive supported scalar source fields without adding, removing or reordering native rows.

Generic mapping REST context stays off. NOVA's own CPT context remains separate. Protected fields survive both update and clone; Leave empty skips updates and clears clones.

## Delivery, publication and recovery

1. Poll `GET /v1/sites/{site_id}/deliveries?limit=100&cursor=...`. The decimal cursor is a durable checkpoint; a signed `content.ready` webhook only wakes discovery.
2. Fetch `GET /deliveries/{delivery_id}` with a full response. Verify the SHA256 of the exact raw bytes against the strong ETag, validate all identities and retain the current `X-Nova-Attempt-Id`. The backend's `source_sha256` is a different hash and is preserved separately.
3. Persist and POST a `received_complete` event to `/deliveries/{delivery_id}/events`. Persist the exact event ID and bytes before network transmission.
4. Resolve the approved profile and plan the native write. Apply once, verify the resulting native content and preserve the durable operation marker. Recover an uncertain transaction before considering another write.
5. For actual native status `publish`, persist and POST a separate `publication_succeeded` event with publication evidence. Draft, pending, private and future posts keep waiting; later status/date changes can be verified without another content write. Content, slug, layout or protected-field drift stops verification. A proven failure before native commit produces `publication_failed`.

Normal immediate publication therefore uses one discovery request, one delivery GET and two event POSTs, excluding mapping setup and retries. Lost responses replay the same event ID and bytes. A `202` response means durable acceptance with status `pending`; it is not evidence that NOVA has applied the result. There is no supported connector status lookup that proves that final step.

Duplicate discovery, interrupted native commits and lost event acknowledgments do not authorize duplicate clones or native writes. A changed attempt can proceed only after retained evidence proves no native commit. Historical protocol journals remain visible for manual review and are not converted or replayed automatically. Authentication failures suspend discovery; pause prevents new writes while allowing reconciliation and already prepared event delivery.

## Validation

The migrated API is exercised with synthetic identities and mocked HTTP. WordPress staging canaries additionally exercise real WordPress storage, native pages, ACF, Elementor and transactional journals through an isolated CLI loader. They do not use a deployed NOVA connection or overwrite the installed plugin.

All 17 offline script invocations finished with exit code 0. PHP lint passed for 61 files in the mapping and posting modules; both changed JavaScript files passed syntax checking. OpenAPI extraction hash and Git whitespace checks passed.

| Check | Result |
| --- | --- |
| Protocol / discovery / HTTP client | 77 / 63 / 24 checks |
| Journal / signed intake | 47 / 16 checks |
| Delivery execution and event crash recovery | 314 checks |
| Exact event JSON | 37 checks |
| Native writer and transaction model | 144 checks, including its 35 baseline checks |
| Mapping synchronization / administrative contract | 40 / 37 checks |
| Local drafts / REST context / connection settings | 77 / 37 / 13 checks |
| Draft JavaScript / publishing status UI | 18 / 8 checks |
| Nested ACF identities and existing structure | Passed |

Staging runtime: **WordPress 7.1.2, PHP 8.1.34, ACF 6.8.0.1, Elementor 4.1.4**, InnoDB native writer tables. All six final canaries exited 0:

| Staging canary | Evidence |
| --- | --- |
| `mapped-writer-staging.php` | Native update/clone, Protected/Leave empty, SQL rollback, lost commit acknowledgment, source deletion recovery, nested ACF parent/reference/sibling preservation |
| `elementor-writer-staging.php` | Exact provider source hashes, native widget identity, targeted cache invalidation, actual frontend rendering/CSS regeneration, protected bytes, clone recovery |
| `delivery-staging.php` | 151 checks: real native writer/journals, received-event exception after acceptance, identical replay, later manual publication, lost success response, process loss after native commit, source conflict, raw tampering, editor drift, stale leases and concurrent retry announcements |
| `mapping-sync-staging.php` | 64 checks: real local REST/client, simulated current template API, exact local approval, retained human rules, page selection/defaults, conditional conflicts, nonce permissions, source preservation |
| `mapping-drafts-staging.php` | 72 checks: real inventory/REST/options CAS, skips, Protected/Leave empty, local historical slots, rejected overlaps/drift, no generic REST context or native source edits |
| `content-context-regression.php` | Existing discovery/privacy regression and exact raw option restoration passed; inactive managed-CPT checks were skipped |

The draft canary skipped its optional subscriber check because no subscriber existed. No user/role was created. Temporary fixtures, revisions, test tables and candidate files were cleaned up. The installed plugin files were not replaced: installed version and activation stayed **2.8.10**. Earlier retained September test posts were not selected for modification.

### Legacy-test cleanup incident

The first five current-contract canaries passed. The additional legacy context test initially failed the loader's settings-restoration guard because it restored through newly registered sanitizers. This normalized the suite/context options and introduced `api_mapping_context: 0`, a key absent from the installed 2.8.10 module defaults. That candidate-only zero flag was removed with a conditional SQL update while preserving all thirteen other stored module flags.

The test now snapshots and restores both raw option rows, including absence and autoload, bypasses restoration sanitizers, suppresses module-toggle side effects during the fixture, and compares exact stored bytes. The full final rerun passed these guards. Private option backups were also captured outside the webroot for the final rerun and removed after success. **The initial pre-test raw rows were not persisted, so their exact historical bytes/autoload cannot be certified retrospectively.** The original failed run is retained separately from the final passing evidence; it is not counted as a pass.

### Candidate identity

Isolated staging ZIP SHA256: `049354fce369d6948495646eb2b1618d689ae781b1d077cc16e7123afee27b32`. Installable plugin ZIP SHA256: `c045a30884eeef2ccb503749ac0d2a9182724fbb4dd91ed63089bd0b2a1da99e`. Both contain the same 219 tested source/assets files under different root folders. The migration preserves version 3.0.0.

- `modules/posting-service/includes/class-nova-bridge-suite-posting-worker.php`: `17e91ded0066277d3f5ecd96ce84509abee1ec5ba563ad3013251cc8bed92c0c`
- `modules/posting-service/includes/class-nova-bridge-suite-posting-jobs.php`: `26e05059c2bbae4025f2631d080adecca269946cb5439aa9c371314c923786c2`
- `modules/posting-service/includes/class-nova-bridge-suite-mapped-writer.php`: `09f291261535da6646200d6fb9cc7f5812862ebb5254eee22f5516109a6a4f8f`
- `modules/posting-service/includes/class-nova-bridge-suite-mapping-sync.php`: `ad830552488d36ecdcfe12894f32ad6a3f778378b5679c949c02e8339490c807`
- `modules/posting-service/contracts/delivery-v1.json`: `becdbeddaddbe537327178e3ee6aa6f381ce406a61274b8463a19573be9a23dd`

No deployed NOVA request was made by these canaries. Production API access, generation and downstream event application still require the controlled live test below.


## First live test

Use the same shared CMS API, a securely provisioned site token, one known NOVA URL and an approved template revision. Start with one controlled staging draft/update and no unrelated queued generation. Run discovery, confirm the exact delivery and received event, inspect native content, then publish and confirm the publication event. Check backend application evidence for those exact event IDs; historical counters alone do not prove this delivery was applied.

The three discovered Cloud Run service origins returned public `/health` HTTP 404 on 2 October. Restricted ingress can explain that response. Their externally reachable API/release and credentials remain unverified, so these local and staging checks do not certify a live backend round trip. The next live test requires usable API access, not another WordPress-specific service or a colleague-run test.
