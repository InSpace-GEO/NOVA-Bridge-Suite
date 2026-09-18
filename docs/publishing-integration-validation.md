# Posting-service integration validation

Date: 2026-09-18. Plugin release: **3.0.0**, branch `api-mapping-with-context`. Backend contract experiments used an uncommitted local prototype based on `86cf4ed2a66ac6b0714d6028131836040985a6df`. That backend prototype is not included in the plugin commit and has not been pushed, submitted as a PR or deployed.

This record supersedes the implementation boundary in `mapping-preparation-validation.md`. The earlier document records the initial local authoring work. The plugin now implements canonical API synchronization, sealing/activation, signed delivery, exact content/configuration retrieval, native execution and durable recovery. The handover describes backend behavior that still needs review and implementation in the actual service.

## Local checks

- PHP syntax: all 43 PHP files under the mapping and posting-service modules passed. The edited JavaScript assets passed syntax checks.
- Mapping drafts: 69 assertions; synchronization: 38; writer: 35; delivery: 55; private connection settings: 13; permanently disabled generic REST context/CPT separation: 37. All passed. Synchronization includes a stale-owner checkpoint race; the winning lease/state remain intact.
- Existing strategy, preview, ACF instance and module-enablement checks passed. A new discovery regression proves exact scalar ACF leaves remain available beside legacy complete-parent paths.
- Four Node test files passed, reporting 17 tests. Node used `--test-isolation=none` because child-process isolation is restricted in this sandbox.
- PostgreSQL 18 ran in a disposable workspace-owned cluster bound to loopback against the local backend prototype. All 18 prototype migrations applied. The focused backend suite passed **185 tests in 18 files**, including writing store/compiler/sealing/lifecycle, author instructions, exact content retrieval, content/result contracts, plugin authentication/compatibility, and real PHP-generated configuration payloads through Fastify and PostgreSQL. This is evidence for the proposed contract, not a test result for the unchanged or deployed service; backend test/prototype files are not bundled in the plugin commit.
- After adding pinned ready-projection immutability, the affected backend tests passed again: **19 tests in two files**, overlapping the 185 above. They verify authenticated historical pins, tenant isolation, exact version retrieval, pin/template/digest mismatch rejection, and immutable pinned content.
- TypeScript checking, OpenAPI generated-type freshness and operation inventory checks passed.

For the final 3.0.0 plugin commit, the seven standalone PHP suites listed above were rerun successfully (drafts, REST separation, ACF alternatives, sync, writer, delivery and settings). PHP syntax passed for 44 files including the bootstrap; all 17 Node tests and all three edited JavaScript syntax checks passed again. The plugin header, runtime version constant and both readme stable tags all report 3.0.0. No new backend tests were run or backend files changed during this release-only pass.

The exploratory full backend run was not green: 640 passed and 54 failed. Failures included sandbox-blocked subprocess tools, Unix-oriented runner checks, Windows CRLF baseline-byte assertions, timing-sensitive tests under parallel load, and fixtures expecting migrations in the shared test schema. API operation counts/header metadata were updated for the added route; the relevant migrated suites then passed sequentially. This is not a claim that the repository's complete Linux CI or `pnpm verify` passed. CI remains required before release.

## Authorized staging checks

Observed runtime: WordPress 7.1.1, PHP 8.1.34, ACF Pro 6.8.0.1, Elementor 4.1.4, Elementor Pro 4.1.2. The installed NOVA plugin remained 2.8.5; the isolated candidate reported 3.2.11 during these tests. The same execution implementation is now labeled 3.0.0 at the user's request, with clearer UI text distinguishing mapping activation from delivery readiness. Staging tests were not repeated for those metadata/copy changes; local release checks verify the version markers and affected assets/settings. The actual service must admit 3.0.0 in its plugin-version compatibility policy.

The candidate was loaded only by WP CLI from a temporary directory outside the active plugin folder, with installed NOVA skipped for those processes. Only newly registered candidate REST callbacks were invoked when CLI had already initialized REST. Service HTTP was intercepted for exact fixture origins; no production NOVA call was made. Temporary pages were drafts. Publication count tests changed status only inside transactions that were rolled back.

| Canary | Result and scope |
| --- | --- |
| `mapping-sync-staging.php` | 71 checks, repeated after atomic checkpoint SQL was finalized: real WordPress catalog/draft/REST inventory, authenticated scoped transport, exact revision/ETag requests, capability evidence, sealing, activation, exact pin restoration, private status and anonymous denial. No native content changed. |
| `delivery-staging.php` | 25 checks using a separate temporary InnoDB journal and real writer: duplicate admission, leases, same-second durable updates, interrupted native commit, marker recovery, lost receipt acknowledgment, stale versions and exact-version mismatch. |
| `mapped-writer-staging.php` | Seven scenarios: native preservation, editor drift, rollback, lost commit acknowledgment with one clone, deleted-source recovery, version two updating that same clone, and an actual nested ACF scalar preserving parent count, hidden references and siblings. |
| `elementor-writer-staging.php` | Seven scenarios: exact provider source hashes, widget/protected bytes, update/clone Leave empty semantics, target-only cache invalidation, real frontend rendering and CSS regeneration, unpublished clone and marker recovery. Unrelated document CSS preserved. |
| `taxonomy-counts-staging.php` | Nine checks: transactional publish/draft counts, draft clone behavior, generic core counts and rejection of custom callbacks. Simulated publication was rolled back. |
| `strategy-integration.php` | 140 checks: discovery, routing, private content, nested ACF and WordPress permissions. Generic mapped-page REST decoration absent. |
| `content-context-regression.php` | Passed: migration/normalization, discovery, guarded ACF writes, hidden CPT transport, mapping workspace, permissions, and generic REST/OPTIONS privacy. Inactive NOVA Blog/Service CPT checks were explicitly skipped; their separate context behavior is covered by the standalone separation checks. |
| `omission-workspace.php` | Six checks: retained omission configuration without generic REST decoration. |

The earlier native/meta_all and Elementor legacy canaries remain recorded in `mapping-preparation-validation.md`. They are not substitutes for the new executor tests above.

## Verification limits

- The test runner confirms installed settings, version and active-plugin state remain unchanged. Candidate tests clean their own fixtures; the temporary upload directory and local database are removed/stopped after testing.
- The browser tool reported no available browsers for this turn. Both in-app browser and Chrome creation failed. The connected-service UI fixture and JavaScript checks are available, but the new sync/publishing controls have no browser smoke result. The older draft-editor smoke belongs to the earlier preparation record.
- Normal deployed web bootstrap, a live configured service, real signed provider delivery over HTTPS, cron operation under site traffic, and deployed producer output still need an end-to-end acceptance run.
- The writer supports verified native/ACF scalar destinations and the inspected Elementor 4.1.4 implementation. Image/link/list value writers, arbitrary builders, whole-parent reconstruction, repeat restructuring, translation-group clones, and custom taxonomy count callbacks are intentionally rejected before mutation. A changed Elementor implementation requires renewed adapter verification.
- Fixed slots preserve current structure and require stable producer `repeat_instances` correspondence. Existing descriptors do not prove historical semantic row identity or detect every equal-shape row swap before planning. Concurrent native changes after planning are rejected under transaction locks.
- Generic save hooks are bypassed by the contracted writer. These tests do not certify arbitrary third-party indexing, CDN/page caches, delayed resaves or plugin lifecycle effects.

The plugin is prepared as version 3.0.0 for a plugin-only commit on `api-mapping-with-context`. No deployment, push or pull request is part of this task. The backend prototype remains uncommitted. Backend implementation/migrations, producer propagation of exact pins/repeat identities, and site credential provisioning are handoff work, not completed production operations.
