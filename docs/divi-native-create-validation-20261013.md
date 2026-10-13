# Native Divi 5 creation foundation — 2026-10-13

## Scope

The `api-mapping-with-context` branch adds a create-only `layout.native_document` input to the Divi bridge. Complete native documents can be persisted without looking up or cloning a source page. Existing native replacement restrictions remain on update routes.

This is the missing native writer foundation; it does not implement the broader static/mixed/rule-driven editor or native rule compiler. The agreed design is recorded in [template modes and adapters](template-modes-and-adapters.md). Other builder bridges already have basic source-free creation paths.

Only WordPress plugin source, tests and documentation changed. Posting-service, nova-agents-backend, the existing mapping UI and saved configuration were not changed. Pre-existing local integration-document and posting-service-test edits were excluded from this commit and deployment.

## Local verification

- All three changed runtime PHP files and both test files passed syntax checks; scoped whitespace checks passed.
- `tests/divi-source-free-unit.php` passed 113 checks using the genuine WordPress 7.1.3 parser copied from staging. Request/storage surfaces are simulated; grammar parsing is not mocked.
- The same suite passed 65 pre-write/update guard checks with the Divi 5 provider unavailable. Without a real parser, the suite explicitly skips successful native grammar/storage cases.
- A separate real-parser smoke passed 26 cases, including exact accepted/rejected size, block-count and block/JSON-depth boundaries, alternate delimiters and duplicate JSON keys.
- Existing WPBakery unit checks passed 232 checks.
- Independent review verified pre-write guards, JSON transport compatibility, native persistence selection, target-specific creation permissions, exact readback, failure cleanup and unchanged cloning/update restrictions.

## Authorized staging verification

Site: `https://inspace-seo.online/`, WordPress 7.1.3, PHP 8.1.34, Suite 3.0.0. Four explicit Divi files were deployed: the module entrypoint, two runtime includes and its README. The previous files and scoped settings were backed up in a private directory outside the public site root.

The real Divi provider is absent. The normal WordPress regression passed the unavailable-provider guard and all existing Divi 4/native-clone/text-update cases. A separate WP-CLI process emulated only the Divi provider/version constants and passed the native creation/parser/storage regression using WordPress's actual parser, post APIs, metadata and permission handling. These process-local constants did not activate Divi or change module settings.

The enabled-process regression verified source-free exact bytes, styles/responsive data, builder metadata, returned hashes, alternate marker whitespace and the existing JSON transport envelope. Invalid documents, excessive resources, missing raw-document permission, a forbidden custom creation capability and whole-document updates were rejected. An injected storage mismatch removed its newly created page. Temporary regression pages were cleaned up; the six previously retained native test pages were preserved.

After testing, all 126 scoped saved settings and all six retained pages matched their pre-deployment hashes. All four deployed file hashes matched the validated local bundle. An uncached web request verified PHP cache invalidation for all three runtime files and loading of the new native helper. Temporary cache helpers and the protected cache-result file were removed from the site.

## Remaining boundary

The staging result certifies native parser/storage behavior, not Divi 5 frontend or Visual Builder rendering. A site running the actual Divi 5 provider is needed for that verification. Complete-document creation requires the provider, the target post type's editing/creation permissions and `unfiltered_html`. It does not add a native section/row/column starter or advanced rule-to-Divi emission; those require a verified provider scaffold.
