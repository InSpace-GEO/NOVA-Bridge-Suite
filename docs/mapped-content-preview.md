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

## URL-based normal flow

Snapshot requests accept `{request_id,target_url,resource_id?,editable_paths?,language?}`. WordPress resolves the complete URL against actual native permalinks and term links, including their hierarchy. It never downloads the URL, follows redirects or falls back to a matching final slug. Foreign origins, URLs outside a subdirectory installation, ambiguous matches, unavailable objects and insufficient edit permissions reject locally. The bounded native scan stops after 10,000 objects. The legacy explicit native-reference request remains compatible.

The returned mapping retains its native reference internally. `target.requested_url` records the normalized source URL, while `target.url` identifies the actual native item. An optional requested language must match the site locale or an existing translation proven through reciprocal WPML/Polylang relations. Missing translations reject rather than rewriting the source-language item. Cross-domain language URLs are unsupported by this single-origin connector.

Existing taxonomy rewrites capture current native name/description, declared Woo/custom fields and concrete nested ACF leaves. Signed locally confirmed field declarations come from the retained default recipe, but its example content never replaces the existing item's filled values. The snapshot pins that declaration authority and its complete current baseline. Writes use the existing term transports, preserve routing and unselected nested siblings, and reject newer human edits.

Creation accepts `publication:{target_url,status:"publish",language?}`. WordPress derives slug and parent from its registered endpoint permalink structure and locally resolves an existing parent URL. Core/Elementor pages reuse the sealed recipe draft lifecycle, retain the witnessed draft before publication, and verify the complete native content and final URL. A lost publication response can reconcile the same native item; a changed retained draft stays unpublished. Woo category creation likewise verifies its planned native URL. WPML creation supports its verified default language code or locale: the adapter activates that native request context, verifies the saved object's language and restores the previous context. Other language creation, additional dynamic permalink tokens and unsupported builder lifecycles reject before insertion pending certified adapters.

Mapped delivery inherits the existing NOVA connection by default, with a separate explicit feature opt-in and pause policy. Credentials remain in one canonical setting. A nonsecret site/origin witness prevents a later connection change from silently enabling another site's mapped deliveries. An optional isolated override remains available for previews. Existing legacy delivery flags and queues stay unchanged.

Before a first native write, the connector rechecks the exact frozen delivery through the service's current pending/inventory gate. A stale or withdrawn list entry cannot directly authorize a native mutation. Retained receipts replay without another native attempt.

The normal-flow additions have local coverage through 102 real native adapter checks and 35 URL ownership/routing checks, plus the existing recipe API, native document, term transport and connection regression suites.

The 15 October normal-flow rehearsal used an isolated backend/API/database and the staging site's existing saved mappings. It created [Woo category 131](https://inspace-seo.online/product-category/nova-mapped-normal-category-20261015/) with separate top/bottom values and verified Dutch native language. It then rewrote [disposable Elementor page 5361](https://inspace-seo.online/nova-mapped-normal-rewrite-20261015/) using its URL-only filled snapshot. Only two approved editor leaves changed; the complete remaining native structure and settings stayed exact. An initial generated value containing whole-document HTML wrappers was rejected before any native mutation; its failed receipt remains retained. A fresh normal writing run supplied fragments and applied successfully. Both successful receipts replayed without changing native rows or metadata.

The final staging comparison verifies all seven runtime hashes, all 25 original NOVA/plugin options and all 67 original terms. Of 814 original posts, 802 have identical complete row-plus-metadata hashes. The remaining twelve differences are solely Avada's named page-view counters, proven by reproducing each exact original aggregate hash. No original editorial content changed. The process-local delivery gate is disabled again; saved connection settings and cron configuration were not changed.
