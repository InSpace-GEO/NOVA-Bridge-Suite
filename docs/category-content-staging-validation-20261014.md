# Category content mapping validation — 14 October 2026

Scope: WordPress plugin on `api-mapping-with-context`, deployed to `https://inspace-seo.online/`. No backend or analysis-agent infrastructure changes.

## Live staging evidence

- Read category **107**, Eiken vloeren. Native description and `content_below_products` appear as independent top/bottom fields, with distinct purposes, input paths, values and native payload destinations.
- Browser confirmations expose the actual `_inspace_wc_cat_buttons` JSON list as six editable label/link fields. Confirming `_inspace_wc_cat_intro` next produces ten editable fields and retains both earlier top/bottom choices and the first custom declaration. The live preview compiles successfully with two independent bindings.
- Confirmed structures retain their native storage codec. A three-button source list compiles to a one-button native JSON string without changing the source.
- A dedicated temporary category exercised bottom-only and top-only updates, quote/backslash preservation, nested typed lists with `false` and `0`, explicit empty values, unknown-field rejection and validation before any setter. The fixture was removed after each run; no client category was rewritten.
- Twenty live assertions pass. All **22** deployed runtime files match the final package manifest. Exact-package PHP cache invalidation uses a one-use helper whose protected result is kept outside the public webroot; temporary diagnostics are removed.
- Narrow-view inspection confirms that the ten discovered fields and both top/bottom mappings remain accessible without horizontal document overflow. Temporary browser viewport overrides were reset.

The PHP empty-map boundary was verified in both mounted tests and the real browser: `declared_term_fields: []` is normalized to a field-name object before adding declarations. The interface verifies the returned schemas before claiming confirmation; a silent no-op response cannot discard the user's previous choices.

## Preservation and recovery

Before deployment, 128 scoped options, all 14 retained staging review posts, source page **3846**, and category 107 with its complete term metadata were backed up/hashed privately. Original runtime copies were retained, including the destination-detection file before its additional change.

Final comparisons confirm unchanged scoped settings, saved profile/default history, retained posts, the source Elementor document/settings, and category 107. The browser demonstration is an unsaved local proposal; it does not replace a creation default or publish category content.

## Automated coverage

- 254 registered layout API checks; 53 Core source checks; 60 category/ACF integration checks; 32 visual/source checks.
- 160 typed term-field checks, including nested groups, empty/primitive lists, schema bounds, optional omissions, safe legacy names, JSON storage, profile authority and native-root versus nested property names.
- 68 native term transport checks, including installed taxonomy sanitizers, callback/readback failure classification and verified restoration of failed multiple-value replacements.
- 146 document-mapping checks plus 76 taxonomy-relevance checks. Custom-only destinations cover registered rich content, nested schemas, REST getters/updaters, installed ACF schemas and bounded legacy content. Configuration, counters and SEO-only archives remain excluded.
- 64 mounted UI workflows; 62 canvas JavaScript checks; 25 PHP canvas authentication/isolation checks.
- 141 engine/Elementor assertions; 325 native adapter assertions using actual WordPress parsers/serializers; 81 focused ACF checks and the missing-provider check.
- Affected PHP lint, JavaScript syntax and whitespace checks pass.

ACF/SCF is inactive on this staging site. Its nested taxonomy behavior is covered by provider-schema fixtures, not a claim of live ACF rendering. Unknown storage codecs, incomplete/open schemas and vendor-owned settings need a supported definition or adapter and remain diagnostic. Recapture an existing example to include newly discovered fields; saved historical profiles are not silently rewritten.
