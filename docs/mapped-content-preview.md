# Mapped content delivery preview

The WordPress mapper now has an additive, explicitly opted-in connection for blank endpoint defaults and fresh filled existing-item snapshots. It leaves the older posting connection, queues and worker unchanged.

## Flow

1. WordPress uploads a saved recipe's immutable ID/revision, empty content, closed JSON schema, field context and instructions. Its native blueprint, seals and custom-field write authority stay in WordPress.
2. Content creation fetches that mapping and fills its declared fields. Existing-item rewrites instead request a fresh snapshot of the concrete native item; an endpoint creation default never substitutes for its existing structure.
3. Posting-service freezes the exact mapping and generated values with a unique delivery UUID. WordPress polls using a site-scoped connector credential.
4. WordPress validates its retained native authority and the current source before setters. Category creation translates top/bottom/custom values through the saved recipe and native term transports. Existing-page rewrites update approved core, Elementor and declared nested ACF leaves while preserving other native values and structure.
5. A durable operation journal and category witness prevent duplicate native creation. Verified receipts survive lost acknowledgments; unknown partial saves remain explicit and require reconciliation.

## API and activation

The shared contract is `nova-mapped-layout/v1`. Service routes are under `/v1/sites/{site_id}/mapped`: immutable `layouts/{id}/revisions/{revision}`, endpoint `defaults`, queued `snapshot-requests` with results, and queued `deliveries` with results. Connector and writer credentials have separate roles and site scopes. Mapping wire identities use RFC 8785; existing recipe and native-baseline hashes remain unchanged.

WordPress administrator routes are under `/nova-bridge/v1/mapping/mapped-content`: `status`, `sync`, `snapshot` and `poll`. The private `nova_bridge_mapped_content_connection_v1` option has a separate HTTPS origin/site credential, publishing actor, enabled flag, pause flag and optional polling schedule. Installation or update leaves this feature disabled and paused. Categories have no draft status and require an explicit publication operation.

The preview used a private local API/database, an SSH tunnel and a process-local staging client. The standard production HTTP client still requires HTTPS. No existing NOVA backend database, queue, callback, scheduled worker or deployed environment participates. Backend branches and draft PRs must be reviewed before ordinary integration or deployment; the writing runner currently preserves mapped output through an isolated execution path rather than adding a production agent-runtime switch or applying backend migrations.

## Validation

The isolated preview created WooCommerce category **130** from the staging site's exact saved category mapping, including separate top and bottom content. It applied an item-specific rewrite to disposable Elementor page **5351**, changing two approved text leaves; it retained the original source page. The first rewrite attempt exposed PostgreSQL JSON-object key reordering; canonical target comparison fixed that issue, and the original rejection remains in the delivery history.

Staging examples retained for review:

- [New category with top and bottom content](https://inspace-seo.online/product-category/nova-mapped-category-preview-20261014/)
- [Existing-page rewrite on a disposable copy](https://inspace-seo.online/nova-mapped-rewrite-preview-20261014/)

Live GPT-4.1-mini generated both cases from the exact exported schema/snapshot. Category delivery `881dd7a8-7191-48dd-b99a-10d0306e9570` and rewrite delivery `0f5394b3-da7c-49ab-8663-6bfe232aae0a` have applied receipts. Replaying the category delivery retains category 130. A deliberate edit on the disposable page causes a stale snapshot to be rejected as `nova_document_mapping_drift` before any delivery mutation; the successful rewritten native document was restored exactly afterward, with its expected newer modification timestamp.

The final native verification passes: five installed runtime file hashes, all three category fields after the installed native sanitizer, two changed rewrite fields, 14 unselected fields and the complete protected Elementor structure. All 15 baseline NOVA/plugin options and 66 existing terms remain exact. Of 804 existing posts, 796 have identical complete row-plus-metadata hashes; the other eight differ only in Avada's three named page-view counters, proven by reconstructing their exact original aggregate hashes. No original editorial field changed. Category HTML follows the site's native term-description filtering; the separate bottom field retains its declared rich-text transport.

The category recovery check uses that same native sanitizer for readback after a lost response. The mapped native adapter suite passes 58 checks, including recovery without additional category insertion or native setters.

Native regression checks cover category and rewrite retries, lost native responses, lost receipt acknowledgments, stale source/default rejection, sealed custom fields, nested ACF parent preservation, nullable fields, unchanged empty values and cross-language mapping identity. The staging verification artifact separately checks the installed runtime, expected native field values, full ordered Elementor structure and every pre-existing baseline option/post/term.

This first native delivery path certifies product-category creation and core/Elementor/declared nested ACF post rewrites. Other complete snapshot providers fail closed until their surgical native writers are certified. A broader production rollout still needs deployment review, production registration/scoped credentials, orchestration into the normal writing/storage pipeline and an explicit operational reconciliation path for uncertain partial outcomes.
