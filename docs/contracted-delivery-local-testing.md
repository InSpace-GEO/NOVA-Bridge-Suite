# Current delivery API: local and staging testing

Updated 2026-10-02 for plugin candidate **3.0.0**. This guide changes no deployment and does not certify a live NOVA round trip. Use the [migration report](posting-service-migration-20261002.md) for recorded results and [mapping setup](contracted-mapping-setup.md) for configuration and local human instructions. September reports remain historical evidence for the earlier contract.

The checked-in schema subset is `modules/posting-service/contracts/delivery-v1.json`, extracted from posting-service commit `145fbcfb7319333e6369796489c068196cb68665`. Its source OpenAPI SHA256 is `ce48ded00914e595dde23d543e347bc748155a7726a108331cc051dc8246af28`. This identifies the reviewed contract, not the release running on a particular API host.

## Current flow under test

- A signed `content.ready` notification wakes authenticated discovery. `GET /v1/sites/{site_id}/deliveries` uses a durable decimal cursor; duplicate entries do not create duplicate jobs. Notifications supply no native write authority.
- `GET /v1/sites/{site_id}/deliveries/{delivery_id}` retrieves the complete immutable snapshot. The plugin hashes its exact raw bytes, verifies the strong ETag and schema/identity, and retains the current `X-Nova-Attempt-Id`. `source_sha256` identifies the source separately. Conditional 304 snapshot reuse is not the current worker path.
- The frozen configuration must match an approved local profile for the exact site UUID, template UUID and integer revision. That profile retains native addresses, update/clone routing, protection and human instructions. The worker does not fetch historical pins, assignments or URL-binding revisions.
- A durable `received_complete` connector event is prepared before posting to `/deliveries/{delivery_id}/events`. Native planning, mutation and verification use persisted state, target locks and operation markers. An uncertain commit is reconciled before another write. Explicit clone retries must not create duplicate pages.
- A verified native `publish` state permits a separate `publication_succeeded` event. Draft, pending, private and scheduled posts remain waiting for actual publication. Proven failures before native commit can report `publication_failed`. Event IDs and exact prepared bytes survive lost acknowledgements. HTTP **202** with status **pending** confirms acceptance, not backend application.

Mapping setup uses `/templates`, `/template-defaults` and `/pages/{url_id}/setting`; replacements use the reviewed revision in `If-Match`. Its twelve stock source names are available for offline preparation. Human rules remain editable locally because the API has no instruction property. Custom generated repeats are unsupported; existing native scalar leaves remain subject to writer checks. These limits are explicit test boundaries, not claims that generation obeys local notes.

## Offline commands

Run each command from the plugin repository root with PHP and Node available. These standalone tests use synthetic identities, mocked HTTP and local persistence models. They require neither a NOVA account nor a running backend.

```sh
php modules/api-mapping-context/tests/mapping-drafts-unit.php
php modules/api-mapping-context/tests/strategy-unit.php
php modules/api-mapping-context/tests/rest-guidance-unit.php
php modules/posting-service/tests/mapping-contract-unit.php
php modules/posting-service/tests/protocol-unit.php
php modules/posting-service/tests/posting-client-unit.php
php modules/posting-service/tests/receipt-json-unit.php
php modules/posting-service/tests/discovery-unit.php
php modules/posting-service/tests/posting-jobs-unit.php
php modules/posting-service/tests/delivery-unit.php
php modules/posting-service/tests/delivery-intake-unit.php
php modules/posting-service/tests/mapped-writer-unit.php
php modules/posting-service/tests/mapped-writer-contract-unit.php
php modules/posting-service/tests/posting-settings-unit.php
node modules/api-mapping-context/tests/mapping-drafts-unit.cjs
node modules/posting-service/tests/posting-admin-unit.cjs
node --check modules/api-mapping-context/assets/mapping-drafts.js
```

`mapping-contract-unit.php` also runs `mapping-sync-unit.php`; run the latter alone when investigating setup synchronization. Execute the two writer suites separately. Check every command's exit code rather than relying on the final command in a shell batch.

Coverage includes strict wire schemas and raw-byte identity, current-attempt handling, conditional setup requests, uncertain template-create recovery, retained local instructions, immutable native-profile revisions, durable discovery checkpoints, authentication/pause behavior, event replay, native drift and interrupted transactions. The serializer has independent numeric/Unicode fixtures. Status tests use the actual UI controller with an offline DOM/REST fixture; intake tests verify signed wake-only notifications and administrator permissions. Mock acceptance establishes the tested client behavior, not deployed-service compatibility or application of an event.

## Isolated WordPress canaries

Use an authorized staging copy or disposable installation with the intended WordPress, PHP and native providers. The canaries exercise real WordPress storage; service responses are intercepted at a fixture-only origin. They need no live NOVA credentials. The current staging candidate is isolated; the installed plugin remains separate.

Put the candidate outside the active plugin directory. Run WP CLI with the installed NOVA plugin skipped for that process while leaving ACF, Elementor and other required native providers loaded. The isolated loader must refuse already-loaded NOVA classes/constants, load the candidate with temporary in-memory module/connection overrides, and register only the candidate REST callbacks if REST was already initialized. It must check that installed NOVA version, settings and activation remain unchanged.

For a prepared loader named `run-candidate.php`, use these **POSIX shell** commands after replacing the verified installation path, plugin slug and temporary directory:

```sh
NOVA_MAPPING_STAGING_CANARY=1 wp --path=/path/to/staging-wordpress --skip-plugins=installed-nova-slug eval-file /tmp/nova-contract-canary/run-candidate.php mapping-sync-staging.php
NOVA_DELIVERY_STAGING_CANARY=1 wp --path=/path/to/staging-wordpress --skip-plugins=installed-nova-slug eval-file /tmp/nova-contract-canary/run-candidate.php delivery-staging.php
NOVA_WRITER_STAGING_CANARY=1 wp --path=/path/to/staging-wordpress --skip-plugins=installed-nova-slug eval-file /tmp/nova-contract-canary/run-candidate.php mapped-writer-staging.php
NOVA_ELEMENTOR_STAGING_CANARY=1 wp --path=/path/to/staging-wordpress --skip-plugins=installed-nova-slug eval-file /tmp/nova-contract-canary/run-candidate.php elementor-writer-staging.php
wp --path=/path/to/staging-wordpress --skip-plugins=installed-nova-slug eval-file /tmp/nova-contract-canary/run-candidate.php taxonomy-counts-staging.php
wp --path=/path/to/staging-wordpress --skip-plugins=installed-nova-slug eval-file /tmp/nova-contract-canary/run-candidate.php mapping-drafts-staging.php
```

The loader is separately prepared test infrastructure, not a shipped plugin entry point. It must dispatch `mapping-drafts-staging.php` from `modules/api-mapping-context/tests/`; the other listed files are under `modules/posting-service/tests/`. A missing explicit opt-in should stop the guarded canary before fixtures are created.

- **Mapping sync:** real inventory, REST, draft/options persistence and writer approval; current template/page-setting/defaults requests through the real HTTP client; nonce/412 rejection; exact local-profile lookup and preservation of human rules. No generated content is written.
- **Delivery:** real temporary native pages and journal tables with simulated delivery/event HTTP, draft-to-publication handling, lost acknowledgements and interrupted execution. This exercises the worker's seams, not NOVA's processing of accepted events.
- **Native/ACF and Elementor writers:** real supported scalar targets, protection, explicit clones, readback, drift and commit recovery. Provider/version checks remain enforced. Existing rows are not structurally generated.
- **Taxonomy counts:** real core count behavior; simulated publication transitions are rolled back. **Mapping drafts:** local storage, conflicts, reconciliation and no publication.

Each canary owns and cleans its temporary fixtures. Verify final cleanup output and unchanged installed settings before removing the isolated candidate. Record candidate commit/diff or archive hash, runtime versions, exact commands, per-process exit codes and result counts in the [current migration report](posting-service-migration-20261002.md). Do not copy September counts into current acceptance: those exercised the preceding protocol. Browser review of the setup controls is a separate check.

## Controlled test with real NOVA

Real-service acceptance needs an accessible deployment compatible with the pinned contract, a provisioned site UUID/token, a webhook secret if notifications are tested, trusted NOVA URL IDs and an approved template revision. Start with one known staging page and no unrelated queued work. Verify template selection, discovery, the exact snapshot and attempt, `received_complete`, native content and eventual publication. Capture the exact connector event IDs and obtain backend evidence that those events were applied.

Then exercise lost event acknowledgement, duplicate discovery, pause/recovery, changed-attempt handling and a controlled explicit clone. Compare HTTP method/path/status and safe identity/state traces; keep credentials out of logs. The expected event response is 202/pending, including replay acceptance—not the old 201/200 publication-result receipt contract.

Real WordPress mutation plus simulated service acceptance does not establish live token provisioning, backend event application, cloud recovery, deployed cron behavior or production readiness. Those are separate acceptance checks. They do not block running the offline and fixture-service tests above.
