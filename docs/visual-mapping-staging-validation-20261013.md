# Visual mapper staging validation — 13 October 2026

Scope: WordPress plugin on `api-mapping-with-context`, deployed to `https://inspace-seo.online/`. Neither backend nor analysis-agent infrastructure was changed.

## Verified behavior

- Fresh onboarding offers Static and Dynamic, then prefers an existing local page. The source-free starter and builder override are secondary disclosures.
- Capturing `/circulaire-visgraat-vloer/` resolves page **3846**, detects Elementor and exposes five real controls: heading, intro rich text, button label, button URL and main rich text. Capture starts with all content fixed.
- Clicking the actual heading selects its control. Clicking the button offers its separate label/link controls. Clicking a heading inside the main text selects the complete rich-text widget, with an explicit explanation of that boundary.
- Five semantic purposes were assigned independently; an uninstructed custom purpose refused preview. Adding instructions allowed compilation and save.
- The isolated reference iframe uses `sandbox="allow-scripts"`, an opaque origin and correlated source/session/digest messages. Desktop uses a 1440-pixel layout and Mobile a 390-pixel layout; scaling does not remount the iframe.
- A saved source-based profile reopens with the same five fields and a fresh authenticated canvas session.

## Retained staging example

- Profile `9bd58127-d98d-4990-9d83-0e6c057fa1ed`, revision **2**, “NOVA visual demo — existing Elementor design”, is the local Pages creation default.
- Original profile `b1e55506-29d8-43e4-8c17-4c19fa09fd7d`, revision **1**, remains unchanged. It was a source-free starter demo, not a capture of page 3846.
- Native review draft **5349**, “NOVA visual mapper review — 20261013”, contains distinct supplied values for all five controls. Browser rendering shows the separate title, intro, button and main text/FAQ. Complete Elementor data and page settings match recompilation with the actual draft-operation seed; the map and surrounding design are retained.

No source page was rewritten or published. Before deployment, 128 scoped settings, 13 retained items, source page 3846 and the previous runtime were backed up privately. Final comparison confirms all 13 retained items and the source post/content/native settings are unchanged. Only the local recipe store and draft-operation journal changed: one labelled profile with two revisions, one completed review draft, and the Pages default. The previous mapping and operation history remain intact.

All 18 deployed runtime files match the package manifest. The one-use PHP cache helper invalidated the exact package, deleted itself and its protected result was moved outside the public webroot.

## Automated checks

- 254 registered API checks, plus 32 visual/source checks.
- 141 engine/Elementor assertions and 325 native adapter assertions using real WordPress parsers/serializers.
- 51 real Core source-capture assertions.
- 48 mounted UI workflows.
- 57 canvas JavaScript checks and 25 PHP authentication/isolation checks.
- Existing preview lifecycle checks, affected PHP lint, JavaScript syntax and whitespace checks pass.

Native adapter tests cover supported builders and declared nested ACF fields. Live provider rendering in this validation is Elementor. Other builders or theme-rendered ACF controls need their own site/provider rendering checks; unsupported visual locators use the field inventory. The reference canvas is the source page's initial rendering, and the compiled outline is a content-structure check. The native draft supplies the actual styling/rendering evidence.
