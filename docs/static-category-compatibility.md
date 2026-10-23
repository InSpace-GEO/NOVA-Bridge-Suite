# Static WooCommerce category compatibility

The mapping module can project the existing static category recipe into the
posting service's released V1 publishing-template API. This is an explicit
compatibility path for one core `product_cat` recipe with three semantic roles:

| Destination role | Delivered source | Native destination |
| --- | --- | --- |
| Title | `h1` | Category name |
| Top category content | `top_content` | Category description |
| Bottom category content | `bottom_content` | Declared `content_below_products` REST field |

The delivered `title` is the SEO title; it must not replace the category name.
The adapter rejects variable regions, other custom roles, ACF companions and
undeclared bottom fields. This does not enable dynamic-template delivery.

With the posting connection enabled, an administrator saving the category creation
template synchronizes it to the configured posting-service URL and site credential.
If that registration has no `category` default, the editor creates its first remote
template, retains the exact local execution profile, and selects the default with
ETag/If-Match while preserving other page-type defaults. This works for both test
and production connections; switching registrations does not reuse the old site's
approvals. Only the scalar publishing definition and source mapping go to the
service. Native field authority remains in WordPress. Configuration does not
enable polling.

Editor-created profiles use the authenticated site scope, origin, complete recipe
and exact remote template revision. Private backoffice client/domain IDs are not
required for setup. Existing explicit CLI approvals made with
`Nova_Bridge_Suite_Static_Recipe_Adapter::sync_and_approve()` retain their additional
client/domain pins.

Once that category default is approved and the posting connection is enabled,
saving its creation template in the editor also updates the existing remote
template with `PUT` and `If-Match`. The returned remote revision is bound to the
exact new local recipe; older approved profiles remain intact. Local and remote
revision numbers are independent. Even unchanged scalar fields need a remote
revision when the native recipe changes. Other destination defaults are untouched.

The editor reports local save and remote synchronization separately. After a
failed or lost update response, **Sync saved revision** reconciles that same
revision without another local save or a blind remote update. A pending update
blocks newer category saves until reconciled. This uses a single WordPress
option and the existing lock mechanism, without cron or database migrations.
An unrecognized remote default, conflicting revision or unsupported recipe
requires setup/review. An unconfirmed first template creation requires manual
reconciliation rather than a blind duplicate POST. A failed default selection
can be retried with **Sync saved revision** using the already created template.

Native execution uses the standard delivery journal with a separate term writer.
It creates only a new category at the verified planned category URL. It refuses
an existing target rather than treating creation as a rewrite. Recovery reads
the durable native witness and complete field readback; it never inserts a
second category. Actor capabilities are checked again immediately before the
native insert. Partial saves without full proof remain blocked for inspection.

Native term sanitizers remain authoritative. In the staging test, WordPress
removed paragraph tags from the stored description while preserving its text;
the separate bottom field retained its HTML. Verification checks the expected
native normalization, not byte equality with the incoming rich-text string.

## Staging acceptance, 2026-10-17

- Site: `inspace-seo.online`, client `135`, domain `67`.
- Local recipe: `40b6a190-d535-42ff-aa90-a607b2a58444`, revision `2`.
- Remote template: `bb7e247b-0ab6-442c-a4e1-4058479a436b`, revision `1`.
- `category` default selected at defaults revision `2`.
- Native category: `132`, slug `nova-static-category-test-20261017`.
- The isolated native test exercised the actual saved recipe, separate fields,
  duplicate application and read-only recovery. Outbound WordPress HTTP was
  blocked; no synthetic connector receipt or real delivery job was submitted.
- Credentials were configured privately in WordPress. Both connectors remained
  disabled; the primary connection remained paused.

This is template-API plus native-writer acceptance, not a genuine backend-to-CMS
delivery. The backend deployment configuration leaves the normal publisher off;
its exposed publishing cycle has no client/domain/item selector. Testing the
remaining source-event/receipt loop requires a safely scoped publisher test.
No existing backend or cloud environment was changed for this acceptance.
# Delivery provider availability

Posting REST requests (ready, manual discovery retry and job resume) initialize all
enabled NOVA modules from the module registry before WordPress `init` and
`rest_api_init`. Disabled modules and standalone-plugin conflicts remain excluded.
WordPress-active third-party plugins register their fields through the normal hooks;
they do not need a hard-coded NOVA loader entry.

During worker processing only, a saved custom category field with no remaining REST
or metadata registration is omitted. Available fields are written and verified; a
successful partial field set emits the normal `publication_succeeded` receipt. This
also applies when a provider disappears between planning and applying a retained
plan. No missing-field setter is called and existing storage is left untouched.
Each skipped field is logged once per worker pass as `nova_posting_field_skipped`
with job, site, content, delivery and operation identifiers, field name and transport;
content and credentials are not logged. There is no automatic retry for omitted fields.

Saved mapping seals, tenant/destination checks, permissions, changed registrations,
value types and native write/readback failures remain enforced. A registered field
whose setter is broken or whose schema changed is not treated as absent. Explicitly
approved local metadata declarations remain writable without a plugin registration.
Mapping editor validation stays strict, and delivery does not alter saved mappings.
This is a temporary tolerance for registered taxonomy custom fields, not an expansion
of the static adapter's supported roles or ACF/page-builder support.
