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

An administrator explicitly synchronizes and approves an immutable local recipe
revision with `Nova_Bridge_Suite_Static_Recipe_Adapter::sync_and_approve()`.
The approved profile retains the site, client, domain, origin, complete recipe
and exact remote template revision. Only the scalar publishing definition and
source mapping go to the service. Native field authority remains in WordPress.
Select the resulting template under the source default key `category` using
the service's ETag/If-Match contract. Configuration does not enable polling.

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
