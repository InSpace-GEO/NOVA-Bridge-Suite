# Local content rules: authorized native staging validation

On 2026-10-08, the candidate at `0daa6c79f2d89b5c8cb06bda6c801cf71086b45e` passed **53 native checks** on [inspace-seo.online](https://inspace-seo.online/): WordPress 7.1.3, Elementor 3.35.9, PHP 8.1.34. This tests the local rendering/writing adapter; neither backend was changed or used.

## Isolation and scope

The site's installed Bridge Suite was 2.8.5 and Elementor was inactive. A private candidate copy and guarded WP-CLI preload loaded the candidate Suite 3.0.0 and installed Elementor for that process. No plugin activation, site deployment or stored connection preference was applied. The installed plugin was backed up; archive comparison afterward confirmed its files remained identical. A separate ordinary CLI inspection confirmed Suite 2.8.5 and inactive Elementor after the test.

The preload used request-only settings/version/connection filters, retained original MU plugins, disabled cron dispatch and blocked WordPress HTTP/mail. Raw database fingerprints for active plugins, suite settings/version, posting connection/schema, rules/bindings, content-context settings, cron, home/siteurl and Elementor settings matched at the end of the test. One Elementor HTTP attempt was blocked. Elementor emitted an existing-provider false-to-array deprecation during its blocked API path; the draft/native checks passed. Its shutdown notice followed the JSON report, so the retained raw console report requires extracting the bounded JSON object.

## Retained drafts

Run identity: `7b34f2a1-b8dc-4421-950a-927140e9d1a6`. All pages remain drafts, with labelled canary titles and owned operation markers.

| Case | WordPress draft | Native widgets | Native saves |
| --- | --- | ---: | ---: |
| Short HTML, styled headings, image/alt, anchor and escaped Unicode title | [5252](https://inspace-seo.online/wp-admin/post.php?post=5252&action=edit) | 5 | 1 |
| Long HTML: twelve heading/paragraph pairs | [5255](https://inspace-seo.online/wp-admin/post.php?post=5255&action=edit) | 24 | 1 |
| Nested/repeated groups and three Accordion FAQs | [5258](https://inspace-seo.online/wp-admin/post.php?post=5258&action=edit) | 6 | 1 |
| Nested/repeated groups and three Container FAQs | [5261](https://inspace-seo.online/wp-admin/post.php?post=5261&action=edit) | 11 | 1 |

Each document preserved ordered, unique native element IDs and configured heading styles. The actual Elementor frontend renderer retained the expected article and FAQ text; image alt and anchor checks passed. Repeating the operation and recovering its committed journal returned the same draft without another native save. No source page IDs, saved rule binding or publication were needed.

The reproducible canary is `modules/posting-service/tests/rule-writer-staging.php`. It requires WP-CLI, `NOVA_RULE_STAGING_CANARY=1`, an explicit `NOVA_RULE_STAGING_ORIGIN` matching `home_url`, and available candidate classes/native provider. It retains drafts; optional `NOVA_RULE_STAGING_RUN_UUID` replays the same operations. Use an isolated bootstrap or an explicitly authorized candidate installation when running it.

## Remaining validation

The active website still runs its original plugin setup. These drafts need Elementor enabled for a normal native editor review. Full editor interaction and desktop/mobile visual checks, staging crash injection, publication transition, other Elementor versions and a real posting-service delivery/receipt round trip were not tested. Standalone suites separately cover interrupted writes, human-edit protection and delivery dispatch contracts.
