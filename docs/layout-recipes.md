# Local template onboarding and native translation

Open **NOVA Settings → Layouts → Configure creation default** for an endpoint. Existing saved document mappings remain available in the older tools; installing this addition does not migrate or overwrite them.

Choose **Static template** or **Dynamic template**, then select the builder or declared custom fields. A static template always has the same fields and the same number of elements on every page or post. Optional content can be left empty, but its fixed place remains. A dynamic template can have different numbers of sections, FAQ items or nested rows on each page or post; declared optional parts can be omitted when empty. Dynamic templates can still keep fixed surrounding elements.

A native starter needs no source page ID. Capturing an existing example copies its design into a local blueprint; later creation uses that saved blueprint rather than cloning the source item again. Existing `mixed` and `rules` recipes both appear as Dynamic template without rewriting their saved mode or revision history.

The guide separates fixed writable fields from repeated, optional and nested regions. Select the old examples to replace and the smaller design pattern to reuse. Replacing seven old cards with a one-card pattern and three new content items produces three cards. Fields and settings outside the chosen regions remain fixed. In ACF/SCF, the installed field groups define the available fields and flexible-content layouts; NOVA does not create field definitions.

Give fields and regions content instructions. For a different mobile composition, bind both native branches to the same content collection and specify each item's mobile group and group title. Grouping is explicit; the plugin does not infer that two differently labelled sections belong together.

Try short and long examples, review the desktop/mobile structure preview, and save one versioned default for the endpoint. Then create a real native **draft** for the provider's actual design review. Retrying the same operation recovers that draft. The guide does not publish pages or taxonomy changes. Taxonomies have no draft status, so they offer field translation and preview only.

## Local API

The authenticated base is `/wp-json/nova-bridge/v1/mapping/layout-recipes`. These routes require an administrator and standard WordPress REST authentication; browser requests also supply the WordPress nonce.

| Route | Purpose |
| --- | --- |
| `GET /catalog` | Relevant destinations, installed provider capabilities, saved profiles and endpoint defaults. |
| `POST /design` | Read a native starter or editable local example; return the certified blueprint, safe fields, regions and sample content. |
| `POST /region` | Build an exact native pattern from the certified design or declared ACF layout; nested requests retain their full ancestor and variant context. |
| `POST /preview` | Validate and compile sample content without creating an item; return a sanitized structure preview and exact preview receipt. |
| `POST /save` | Save a reviewed immutable revision and set its endpoint default using both profile and default compare-and-swap checks. |
| `GET /profile?id=UUID` | Reload a profile and its editor metadata. |
| `GET /blank?resource_id=post_type:page` | Export the exact current default, content schema, instructions and empty input object. |
| `POST /translate` | Compile an exact saved `id`, `revision` and filled `content` into the native document and writer payload without writing. |
| `POST /draft` | Create a labelled native draft from an exact saved revision, content and unique `operation_id`. |
| `GET /draft?operation_id=UUID` | Recover the request's draft identity without creating another item. |

The recipe format is `nova-layout-recipe/v1`, with a builder, mode, complete native blueprint, scalar bindings, recursive regions and an optional separately certified ACF companion. The content schema describes writable input and preserves nested collections and finite layout variants. A changed provider schema, unknown/protected field, modified native prototype, stale default, invalid input or failed native readback produces an explicit error.

Profiles live in the non-autoloaded `nova_bridge_layout_recipes_v1` option, with immutable history. Native draft requests use `nova_bridge_layout_recipe_operations_v1` plus private operation witnesses on created items. Lost journal entries with existing physical witnesses refuse another insertion; uncertain operations require recovery or reconciliation. Completed draft recovery preserves subsequent human edits and refuses to claim a published or deleted item is still a draft.

This interface is preparation inside WordPress. It does not connect the plugin to a backend, synchronize these templates remotely, or replace the legacy contracted publishing protocol. Neither posting-service nor nova-agents-backend is modified. Their future integration must request the exact recipe revision and content schema, fill that contract, and consume the translated native payload through a supported publication path.

## Verification and limits

Pure compiler/controller tests exercise variable counts, nested ACF values, declared flexible layouts, device grouping, source preservation, native identity/reference consistency, revision conflicts and draft recovery. The native adapter suite uses actual WordPress parser and serializer files through `NOVA_WP_CORE_FIXTURE`; it does not simulate vendor frontend rendering.

Use an installed provider and its enabled bridge for real draft testing. Structure previews show sanitized content and visibility, while native drafts show the actual builder/theme. Missing providers and unsupported native controls are clearly gated. ACF repeater/flexible-content support requires a provider that registers those field types. There is no claim that absent commercial builders have been rendered on staging.

The bounded compiler accepts eight nested recipe levels, at most 500 items per declared region, and complete JSON within 8 MiB. Limits reject oversized input rather than returning partial mappings. Provider limits and installed ACF field constraints may be stricter. Styles and device settings remain part of the captured native design; edit those in the builder and recapture the design when needed.
