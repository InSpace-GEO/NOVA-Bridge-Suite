# Endpoint defaults and live document mappings

The Layouts screen now lists every discovered content destination. A destination has one local blank creation default; existing items are read from their own current native document. Users do not need to map every existing layout group.

## Onboarding

For each supported destination, configure its creation default:

1. Start with standard WordPress fields, even if the destination has no items, or choose a sample to retain its native layout.
2. Choose which supported content slots start empty. Review the server-generated blank preview. Unselected content and protected settings remain from the sample.
3. Add optional field purposes and writing instructions.
4. Review and save the local default. The sample itself is never changed.

Inspect existing content separately to see full current values and native structure. This is read-only and does not require a saved creation default. Older reference drafts and Elementor rules remain under **Other layout tools**.

## Local API

All routes use `/wp-json/nova-bridge/v1/mapping`. They require an authenticated administrator, source/target edit rights, and applicable metadata capabilities. WordPress REST cookie authentication uses `X-WP-Nonce`; standard WordPress application-password authentication also works. Responses are private and not cached.

| Route | Purpose |
| --- | --- |
| `GET /endpoints` | Destination coverage, capabilities, default identities and bounded reference lists; no native content values. |
| `GET /existing?resource_id=post_type:page&reference_type=post&reference_id=123` | The existing item's full filled mapping. Use the actual item ID, without a saved layout-group mapping. |
| `GET /existing?...&reference_id=0` | Standard source-free blank native fields for a supported destination. |
| `POST /creation/preview` | Unsaved blank preview from reviewed source values and selected field paths. |
| `POST /creation` | Save a local endpoint default with `expected_revision` and the reviewed `source_document_version`. |
| `GET /creation?resource_id=post_type:page` | Read its current saved default, or an explicit unsaved empty result; `needs_refresh` flags changed source/schema. |
| `POST /validate` | Validate returned generated values, freshness and immutable native identities; does not apply them to the site. |

A creation request contains `resource_id`, `reference_type`, `reference_id`, `source_document_version`, `selected_fields`, optional `field_descriptions`, `instructions` and `label`, and integer `expected_revision` for a save. IDs of zero select standard fields without a source item. The version must come from the source-read response. Saved defaults have a local UUID and integer revision. Concurrent saves cannot silently overwrite one another.

The mapping format is `nova-document-mapping/v1`. `structure` retains supported native layout and settings. `fields` includes complete current values, purposes, writable/protected flags and native paths. Generated output may change **only `fields[path].value`** for permitted slots; structure, source values, instructions, identity and freshness markers remain unchanged. Creation validation also requires the exact saved `mapping_id` and `revision`. Structure and content hashes detect layout drift and intervening editorial changes.

Public creation templates empty selected values in both field aliases and native structure. Private source evidence in `nova_bridge_endpoint_mappings_v1` contains freshness markers, rather than a duplicate of the original selected content. The source reference identifies where the template originated; its new target ID is zero. Creation defaults reset publication to draft and clear the sample's slug, author, parent and menu order. Protected design, media and unselected copy may remain intentionally.

## Supported capture and boundaries

Standard WordPress posts, pages, editable REST custom post types and eligible public taxonomies use native editorial fields and capability-approved registered metadata. Internal models such as navigation, pattern categories and WPML translation priorities are excluded from the publishing checklist. Approved ACF fields preserve existing nested/repeated structure. Elementor capture retains its ordered document and page settings, with supported content leaves editable. Gutenberg capture retains the block tree; supported paragraph, heading and list text leaves are editable, while unknown or dynamic blocks remain protected. No adapter invents repeated rows or native identities.

NOVA's own Blog and Service CPTs use dedicated raw readers for their structured metadata and protected template settings. The template artifact/code hashes and current language scope are retained for validation. Their generic body scaffold stays protected when their native template owns rendering. Frontend previews are disabled for these models to avoid triggering legacy migration; native fields and structure remain inspectable. WooCommerce categories also retain their below-products copy and protected native display/media settings.

An unsupported builder or complete WooCommerce product document returns an explicit capability limitation. Its generic post fields are not presented as a complete product or builder template. Oversized or sensitive native documents fail explicitly. Endpoint coverage distinguishes these destinations from configured supported defaults.

This is plugin preparation only. It does not upload these mappings to posting-service, change nova-agents-backend, activate the NOVA connection, or add a generic native apply endpoint. A future transport/writer integration must consume the versioned contract, validate its freshness and use a reviewed provider translator. Existing plugin bridges and delivery behavior are unchanged.
