# WordPress V1 and V3 mapping

The `api-mapping-with-context` branch selects the publishing contract from the
saved mapping's intent. The wire schema is pinned to posting-service commit
`07e37d5054044a66c1f6f35652be43038fdb9169`; the coordinated runtime enablement is
posting-service PR #55 and nova-cloud-platform PR #240.

## Version selection

- **V1:** copy existing NOVA source fields directly, with no fitting instructions.
  A supported nested ACF/Elementor target does not itself require an LLM.
- **V3:** destination descriptions, fixed groups, or instructions on written
  fields/the template require LLM fitting. Inherited instructions also count;
  explicitly clearing them restores V1 if no other field needs fitting. Notes
  on protected or omitted fields stay local and do not trigger fitting.
- New fitting mappings go directly to V3. V2 is the older source-redistribution
  contract; this plugin does not emit or consume V2 adaptations.

When a direct mapping gains instructions, the whole template uses V3. Its
source selections become `source_hints` (preferences, not exact bindings).
Original local fields, native descriptors, guards and the approved profile digest
remain unchanged. Explicitly described destinations still require a meaningful
purpose. Retained mixed legacy bindings must be reviewed explicitly.

## Setup and delivery

1. In the mapping editor, describe destinations or choose **Copy delivery fields
   directly**. Save the local draft.
2. Synchronize the publishing template. Requests carry V1 direct bindings or V3
   purposes, instructions, scalar constraints and fixed groups. Conditional
   updates use the previous ETag.
3. Validate and approve the exact native profile, then select the page/default
   template. V3 approval enables adaptation discovery for that site.
4. A ready webhook wakes the existing bounded worker. It reads V3 ready results
   through `/adaptations?contract=destination-v3-scalar-v1` and
   `/adaptation-deliveries/{id}`, verifies bytes, identity and current attempt,
   applies fitted values by stable destination ID, and sends existing receipts.

No recurring WordPress cron is added. V3 has its own UUID cursor, revisits pending
jobs on another wake, and never treats a blocked/cancelled adaptation as a CMS
publication success. One wake scans at most two pages per feed and processes at
most three journal jobs; larger backlogs need another wake or manual catch-up.

Existing approved V1 profiles are verified using their frozen V1 serializer,
including old profiles whose instructions were previously local-only. An explicit
sync upgrades such a template even if its local revision has not changed. The
new remote revision requires new native approval; historical approvals are kept.

## Supported native scope

V3 updates scalar fields in existing posts/pages, including supported nested ACF
leaves and static Elementor heading, text-editor and button text. Protected fields
and omitted optional values keep their existing contents. V3 selects original
source text; it does not rewrite text or add/remove/reorder layout rows. Missing
required values and impossible constraints block fitting.

Category terms still use the separate V1 static-recipe writer. V3 category
writing, cloning, compound images/lists/links and whole-parent replacement need
separate native integrations. Unsupported descriptions can be saved/exported but
cannot synchronize as enabled scalar V3 templates.

## Verification and rollout

Focused standalone tests cover request serialization, version selection,
conditional upgrades, historical profiles, webhook discovery, exact result
validation, native plans, receipts and the editor's save/sync/approve controls.
These use synthetic storage/HTTP/CMS models; they do not prove a live LLM run.

```sh
php modules/posting-service/tests/mapping-version-unit.php
php modules/posting-service/tests/destination-description-unit.php
php modules/posting-service/tests/v3-discovery-unit.php
php modules/posting-service/tests/v3-native-unit.php
php modules/posting-service/tests/v3-delivery-unit.php
node --test modules/api-mapping-context/tests/mapping-drafts-unit.cjs
```

Install the tested commit on `inspace-seo.com`, verifying installed hashes,
unchanged settings/active plugins and authenticated admin/front-end responses.
After the coordinated cloud/service staging deployment, synchronize and approve a
V3 test layout, then verify a fitted result, native values and final receipt before
claiming end-to-end publication.
