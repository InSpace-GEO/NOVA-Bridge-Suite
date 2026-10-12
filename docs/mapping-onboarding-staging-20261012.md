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
