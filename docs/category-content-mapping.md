# Category content mapping

The manual Static/Dynamic onboarding captures independent native category fields:

- **Category title** writes `name`.
- **Top category content** writes the native `description`.
- **Bottom content — below products** writes `content_below_products` through the installed WooCommerce content bridge.

Each field has its own purpose and example value. Keeping a field leaves it outside the generated patch. Omitted optional input preserves the target value; explicitly empty input clears only that selected field. The setup wizard saves local mapping proposals and previews; it does not publish categories, which have no draft status.

## Custom content

Capture includes editable registered term metadata, additional REST fields with declared schemas and native getter/updater callbacks, and the installed ACF/SCF taxonomy field schema. Scalar types, choices, constraints, nested groups and typed lists remain declared throughout the input schema, preview and translation. Empty typed lists use a schema-owned example pattern without adding fake rows to the stored category. ACF fields retain their provider-owned complete-parent transport under `meta_all.acf`.

Existing unregistered editorial metadata appears under **Additional fields needing confirmation**. An administrator must confirm its content type before assigning a purpose. A bounded existing object/list can supply a readable proposal for its child names and types. This declaration belongs to the sealed template; it never exposes new public WordPress metadata or overrides a registered field's permissions. Safe legacy editorial names with a leading underscore are supported; credentials, internal settings and ACF reference metadata remain protected.

Legacy JSON strings containing a list/group offer an explicit structured declaration. Its root schema carries `x-nova-storage: "json"`. The mapper uses typed children internally and emits a JSON string to native metadata, preserving the theme's storage format. Registered string fields are never automatically interpreted as JSON. Unknown codecs, open/ambiguous structures or incomplete item definitions require a provider adapter/schema and show an explanation instead of silently disappearing.

The bottom-content preview has an exact locator for the bridge's `.wc-content-below-products` wrapper. Other custom fields remain accessible through the field inventory when the theme supplies no reliable visual association. NOVA does not guess a writable field from matching rendered text.

## Plugin term transport

Authenticated administrators with native term editing access can use:

`GET /wp-json/nova-bridge/v1/term-content/{taxonomy}/{id}`

`POST`, `PUT` or `PATCH` at that route accept the compiled native `writer_payload`. This updates an existing term only. Registered metadata and known REST callbacks retain native permissions. Legacy declarations require `profile_id` and an exact integer `revision` identifying an already saved, matching, sealed Core category profile. The approved schema can initialize those fields on another existing category even when its keys have not been stored yet. Requests cannot invent local declarations.

All requested fields, schemas and permissions are checked before the first setter. Native taxonomy sanitizer results are predicted from the original input, while the setters receive that original input once. Metadata setters preserve quoted text and backslashes. Readback must match the prepared values. A failed multiple-value replacement attempts verified restoration; callbacks cannot promise a transaction across fields, so other setter/readback failures return an explicit uncertain-write response with applied fields and review required.

This change is plugin-only; backend integration is not enabled here. Tests cover category capture and source certification, separate optional fields, schema-owned empty collections, nested ACF, explicit JSON storage, saved-profile authority, unknown-field rejection and native write/readback behavior.
