# Mapping preparation validation

Historical record of the initial authoring-only stage. See [publishing-integration-validation.md](publishing-integration-validation.md) for the subsequent API integration, executor and recovery validation. The implementation boundary and remaining work below describe that earlier stage.

Date: 2026-09-18. Branch: `api-mapping-with-context`, based on `42236f9`.

## Implemented boundary

The new workflow authors, validates, saves and exports local mapping drafts. It does not synchronize with NOVA, activate remote configuration, consume production content or publish from those drafts. Leave empty and Protected in the new editor are saved intentions for the future executor. Existing content writers remain separate.

## Local checks

- PHP syntax checks passed for all added/modified PHP files; JavaScript syntax checks passed for both editor files.
- `mapping-drafts-unit.php`: 64 catalog, validation, reference/revision conflict, preservation and no-publication checks passed.
- `rest-guidance-unit.php`: 37 optional guidance and CPT separation checks passed.
- Existing `strategy-unit.php`, `preview-unit.php`, `acf-instance-unit.php` and `enable-bridge-unit.php` passed. PHP 8.5 emits a deprecation for the pre-existing `ReflectionMethod::setAccessible()` test call.
- Four Node test files passed (17 reported tests: 14 new draft cases plus three existing script suites). Used `--test-isolation=none` because the local sandbox prevents Node's child-process test isolation.
- Browser smoke used the actual editor assets with a local mock API. Verified explicit preview catalog selection, title mapping, layout instructions, separate Protected/Leave empty choices, two existing repeat slots, justified source skip, saving an incomplete draft and reloading its saved state. Confirmed inspector cards open/close and preview selection works. This is UI coverage, not proof of backend synchronization.

## Authorized staging checks

Environment observed during this earlier test stage: WordPress 7.1.1, PHP 8.1.34, ACF Pro 6.8.0.1, Elementor 4.1.4 and Elementor Pro 4.1.2. Installed NOVA remained 2.8.5; the candidate then identified as 3.2.11. The final mapping branch is now versioned 3.0.0; this historical record does not imply another staging run under that label.

The candidate was uploaded outside the site's active plugin directory and loaded only in isolated WP CLI processes with the installed NOVA plugin skipped. In-memory settings selected candidate mapping/Elementor components. Because staging initialized REST before `eval-file`, the runner invoked only newly registered candidate REST callbacks; it did not replay the global WordPress lifecycle. These tests do not verify normal web-request bootstrap of a deployed candidate.

| Test | Result | What it establishes |
| --- | --- | --- |
| `mapping-drafts-staging.php` | 72 checks passed | Real REST authentication, server inventory, private responses, database inserts/updates, non-autoloading storage, stale and interleaved compare-and-swap conflicts, explicit skips, modes, fixed slots and rejected forged descriptors. Source content/meta remain unchanged. |
| `acf-flat-matrix-staging.php` | Passed | Concrete nested scalar updates through native `meta_all` preserve sibling form data, row markers and hidden field references without creating stray top-level fields. |
| `acf-matrix-staging.php` | Passed | A complete-parent nested ACF update retains untouched sibling values and flexible layout/row order. |
| `elementor-clone-staging.php` | 23 checks passed | Temporary Elementor source and clones retain widget IDs, list order, native form payload, sibling/container settings and original source. Legacy clone omission blanks the chosen heading; an update without builder values leaves them unchanged. |

The optional subscriber case was skipped because staging had no existing subscriber; no user or role was created. Anonymous access rejection and administrator access passed.

The Elementor test initially compared associative JSON property order too strictly. Diagnostics showed only property reordering, so comparison now sorts associative object keys while retaining list order and scalar types. No writer change was required to make it pass.

All fixture posts and mapping options were removed by the canaries. Installed NOVA settings, version and activation were checked unchanged. Elementor's normal save lifecycle can invalidate generated cache. No existing page content was edited and no production NOVA request was sent.

## Remaining verification before publication

- Connect and test the actual catalog, configuration/instruction sync, revision/ETag, assignment/pin, activation, content-version, notification and receipt contracts.
- Agree intentional source skipping and exact repeat-capacity/instance identity with NOVA. A local slot ID is a mapping identity; existing layout fingerprints do not prove native row identity or detect every same-shape row swap/count change.
- Build the new executor and durable recovery lifecycle. Test duplicate delivery, stale versions, lost receipts, interrupted hooks/derived work and concurrent editor changes before claiming recovery safety.
- The ACF canaries assert immediate results, then delete their temporary pages before deferred shutdown work. They do not certify delayed resaves or whole-parent transaction safety.
- The Elementor canary verifies current document/widget preservation, not automatic Protected execution, cloned page settings/dependencies, global cache behavior or crash recovery.
- Test the candidate under the normal deployed web/bootstrap lifecycle before release. No plugin replacement, release, commit or push was performed during this preparation.
