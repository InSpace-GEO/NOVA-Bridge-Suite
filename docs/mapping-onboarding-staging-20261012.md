# Guided layout onboarding: staging verification, 12 October 2026

Scope: WordPress plugin admin UX on `api-mapping-with-context`. No posting-service or nova-agents-backend changes, remote template synchronization, delivery binding, publication, or production release.

## Local checks

- All 10 standalone PHP suites and 5 JavaScript suites in `modules/api-mapping-context/tests` passed. The final mapping editor suite includes 33 cases; the rules editor includes 18.
- Navigation tests verify the hub, legacy URLs, parent navigation, permission filtering, bounded saved-reference reads and requested profile identity. Guided-flow tests preserve unsaved edits, require a current preview before rule review, distinguish uncertain draft creation, and verify workspace handover.
- The existing-layout progress bar uses separate full-width chrome above reference controls. A regression checks one stepper, preservation of the mounted inspector draft through navigation/save, and ownership-safe cleanup.
- Changed PHP and JavaScript syntax checks passed. Existing PHP 8.5 deprecation in the standalone strategy test remains non-failing.

## Ordinary staging installation

Site: `https://inspace-seo.online/`. WordPress 7.1.3, PHP 8.1.34, Elementor 3.35.9, active NOVA Suite 3.0.0.

- Private plugin/configuration backup retained outside the web root at `/data/sites/web/inspace-seoonline/nova-onboarding-install-20261012/`.
- Candidate package contained one plugin root, 274 runtime/assets files, font licenses, no tests or documentation.
- All 13 pre-existing NOVA/settings records were byte-for-byte unchanged immediately after installation. Final checks preserved original profiles and all delivery bindings while allowing the explicitly created demo records.
- SHA-256 verification matched all 274 installed files to the final local runtime manifest, including the progress-bar placement fix.
- A temporary staging-only shim invalidated the five changed PHP files in FPM OPcache and removed itself. All five reported successful invalidation.

## Browser results

- The branded Layouts hub separates **Fill an existing layout** and **Build new Elementor content**, with examples and independent guided starts.
- New Elementor onboarding: article example preview reported two Heading widgets, three Text Editor widgets and one container. Saved a separate **Onboarding demo — Elementor article** profile, revision 1, without modifying **Staging Elementor article**.
- Created **NOVA onboarding staging demo — 12 October**, page 5267. WordPress reported `draft`; native `_elementor_data` contained two `heading` and three `text-editor` widgets. Elementor opened the draft and rendered the expected headings, paragraphs and list. No Publish action was taken.
- Existing-layout onboarding: selected reference page 5264, described its title, reviewed and locally saved **Onboarding demo — fixed title**. Handover download `nova-template-backend-description-5264.json` contained the saved title purpose and `prepared_local` status. This intentionally incomplete demo describes one field.
- Both saved setups appeared in the hub. Reopening the existing setup selected page 5264 exactly, preserved the saved name/field description, and displayed the full-width four-step navigation above the reference preview.
- Local fixture also verified **Finish setup without a draft** transitions to the saved workspace without native creation.
- Service connection remained `enabled=false`, `paused=true`, with no new delivery bindings. Existing Avada missing-provider and Elementor script-dependency notices were pre-existing and did not prevent testing.

The official InSpace wordmark and self-hosted licensed web fallback fonts are scoped to the plugin admin. Customer reference previews and generated Elementor content retain the site's own design.

## Focused onboarding follow-up

The follow-up removes the cookie-banner toggle, fills the available WordPress admin width and suppresses unrelated theme/plugin notice callbacks only on NOVA settings. Core and NOVA notices remain. On staging the Avada dependency notice disappeared from NOVA settings and remained on the WordPress dashboard.

- The app and available admin content widths both measured 1690px, with `max-width: none` and no horizontal page overflow.
- Existing-layout setup now gates reference confirmation, field review and confirmed saving. Step 2 has one supported field beside an open preview, without the layout sidebar, strategy tools or reference selector. The measured columns were approximately 942px/628px. Review and completion hide the preview; the full workspace restores its tools.
- Cookie controls are absent. The reference iframe initializes with the always-on known-banner hiding class; refresh preserves it. Standalone tests also cover late class/ID assignment and confirm no consent writes.
- Saved **Onboarding demo — focused heading** for reference 5267. One heading was described; **Skip for now** did not change another field. Review showed one described field, zero deliberate skips, and undecided fields retained. Save unlocked completion and the workspace. Reopening preserved the exact reference and human purpose.
- Grouped-page search finds member titles, paths and IDs. A unique search for **NOVA onboarding staging demo** labels and selects reference 5267, rather than the group's first reference. Existing meaningful and saved field purposes are retained; new guided fields avoid the exact generic bridge-description boilerplate.
- All 11 standalone PHP and five JavaScript suites passed. The final mapping suite contains 37 cases; rules editor contains 18. Changed runtime syntax and whitespace checks passed. PHP 7.4 compatibility was reviewed statically; local execution used PHP 8.5 and staging uses PHP 8.1.
- Private backups are retained at `/data/sites/web/inspace-seoonline/nova-focused-onboarding-20261012/`. All 15 pre-existing options and both reference pages/native Elementor data were unchanged after the UI test. All seven deployed runtime files matched the final source manifest and use readable 0644 permissions. The one-shot PHP cache helper invalidated the changed admin helper and removed itself.
- Browser screenshot capture was unavailable during this follow-up; live DOM, computed layout, preview bindings, persisted records and runtime hashes were verified instead.

Elementor content-rule generation, the posting-service, nova-agents-backend, connection settings, delivery bindings and page publication were not changed.

## Initial defaults, titles and fresh staging setup

- Fresh activation with no existing settings, version or provenance enables API Mapping Context and compatible detected NOVA bridges. Runtime defaults leave missing flags off, so a 2.9 upgrade does not silently enable API Mapping Context. Existing choices and conflicting standalone bridges are respected. Auto-enable attribution is shown only for a still-active detected source and effective enabled module, and manual toggle changes remove it.
- API Mapping Context is optional within **NOVA Core**, rather than a separate accordion. No third-party plugin, backend connection or publication setting is activated by this initialization.
- The visible and accessible headings resolve to **Layouts**, **NOVA connection** and **Modules**. Future saved setups are grouped in a collapsed **Saved layouts (N)** disclosure.
- All 14 standalone PHP suites passed, including 41 lifecycle cases and 34 navigation/title/disclosure checks. Updated bootstrap/settings/helper syntax and whitespace checks passed. Lifecycle verification used in-memory option fixtures; the live staging installation exercised the existing-install path.
- Before the requested staging reset, no unfinished jobs, rule operations, rule-page associations, pending syncs, live sync locks or rule bindings were found. Four mapping-option rows were backed up remotely and downloaded to a local file outside the source repository. SHA-256 was verified before clearing: `92e6885ddb10695c22c15fdb2a92eb03a0fe1d6b185fa81eec3a45252829f950`.
- Deleted two exact mapping-draft options and emptied the two rule profiles and endpoint mapping resources. Strategy configuration was absent. Imported strategies would retain their rows/imports while resetting only profiles and assignments. The onboarding hub and rule-profile API both reported no saved setup afterward; testing did not create replacement mappings.
- Eleven protected option rows and six existing native pages/Elementor documents remained unchanged, including module choices, connection state and six committed rule-operation history records. Publishing authority, pins, synchronization/recovery history, unused guidance flags and native ownership metadata were excluded from the reset.
- Private server backups and the verified mapping export are retained under `/data/sites/web/inspace-seoonline/nova-install-defaults-20261012/`. The local mapping export is under the separate Playground staging-artifact directory `install-defaults-20261012/mapping-options.json`. All four changed runtime files matched their deployment manifest and remain readable. The one-shot cache helper invalidated all three changed PHP files successfully and removed itself.
- Browser checks confirmed all three titles, the enabled but editable API checkbox within NOVA Core, no saved-layout disclosure on the empty hub, and the service connection still off.
