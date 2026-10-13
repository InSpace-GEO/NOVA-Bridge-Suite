# Static / Dynamic template UI validation — 13 October 2026

Supersedes the three-choice onboarding from commit `3c8f29a`; the native compiler and stored recipe contract are unchanged. The agent-assisted snapshot/proposal flow is a plan only, documented in `mapping-analysis-agent-plan.md`.

## Change

- Exactly two choices: **Static template** / **Dynamic template**, with a two-column layout on desktop.
- Static explains fixed field and element quantities, supplied-value requirements and clearing text without removing its fixed place. Its example review does not advertise variable item counts.
- Dynamic explains variable quantities and optional regions. Region controls expose remove-on-empty or retain-the-designed-example behavior, and review includes **Try no optional content** alongside short/long examples.
- Existing `mixed` and `rules` recipes both display as Dynamic without converting their saved mode, native frame or history. New Dynamic setups use the compatible internal `mixed` mode. Preview and review labels show the two client-facing names.

## Verification

All **35 JavaScript workflow checks**, **13 admin shell checks**, JavaScript syntax and `git diff --check` passed. Added coverage verifies exactly two choices/default selection, unchanged load/save behavior for both legacy dynamic modes, static supplied-value controls and fixed quantities, and empty dynamic examples preserving fixed content and explicit empty-region policies.

Only the JavaScript/CSS assets were deployed to `https://inspace-seo.online/`. Browser verification confirmed the two choices, selected Dynamic default and updated asset versions. A native Elementor Static starter compiled with **four nodes, three bindings and zero region instances**. A Dynamic starter with no optional content compiled with **three nodes, two bindings and zero region instances**. Both showed successful structure previews and their correct client-facing mode labels. These previews did not save templates or create/publish content.

A private before/after baseline verified exact hashes for **128 existing settings** (including the saved recipe and draft journal), **13 retained native items** and **15 final runtime assets**. Settings and retained items stayed unchanged. The one-use NOVA cache helper confirmed successful PHP invalidation and loaded runtime, removed itself, and its protected result was moved outside the web root. The earlier native-provider/compiler validation remains in `three-mode-mapper-validation-20261013.md`.

No posting-service, nova-agents-backend, posting-agent source, Google Cloud or n8n configuration was changed. The agent proposal system was inspected/planned but not implemented. Existing unrelated dirty repository files were excluded from staging/commit.
