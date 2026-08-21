# Weglot Translation API

REST endpoint for serving NOVA-authored per-locale content on a Weglot site.

## Why this module is shaped differently

Polylang and WPML store a real second `wp_posts` row per language, so those bridges
create a translated post and link it. **Weglot never creates one.** It strips the
language prefix from `REQUEST_URI` on `plugins_loaded`, lets WordPress render the
*original* post, then output-buffers the finished HTML and swaps strings for
translations held in Weglot's cloud. There is no translated post ID to create, and
Weglot exposes no public API to write a translation.

So this bridge **stores** each locale's payload on the source post and **serves** it
at render time when Weglot's current language matches, wrapping the result in
`data-wg-notranslate` — an attribute Weglot's parser honours for the element and its
entire subtree. Net effect: NOVA's copy is what visitors see at `/fr/…`, served
verbatim, consuming **no Weglot word quota** and making no Weglot API calls.

## Endpoints

All under `/wp-json/weglot-translations/v1`. `{language}` matches `[A-Za-z0-9_-]+`.

| Route | Methods | Required in the body/query | Capability |
|---|---|---|---|
| `/posts` | `POST` | `source_post_id`, `translations[]` | `edit_post` on `source_post_id` |
| `/posts/{id}/translations` | `GET` | — | `edit_post` on `{id}` |
| `/posts/{id}/translations/{language}` | `DELETE` | — | `edit_post` on `{id}` |
| `/languages` | `GET` | — | `edit_posts` |
| `/terms` | `POST` | `source_term_id`, `taxonomy`, `translations[]` | `edit_term` on `source_term_id` |
| `/terms/{id}/translations` | `GET` | `taxonomy` | `edit_term` on `{id}` |
| `/terms/{id}/translations/{language}` | `DELETE` | `taxonomy` | `edit_term` on `{id}` |

Every `translations[]` item requires `language`; nothing else inside an item is.

**`GET /terms` does not exist.** That route registers `POST` only, so the old stub —
which advertised `GET /terms` and answered `501` with an explanation — is gone, and
the URL now returns `rest_no_route` (`404`) with none. List a term's locales with
`GET /terms/{id}/translations`.

`taxonomy` is required on **all three** term routes, including `DELETE`, where it
scopes the permission check and the term lookup rather than the deletion itself.
Omitting it is a **`403`, not a `400`**: `permissions_check()` runs before argument
validation and refuses any `/terms` request that carries no `taxonomy`. A
`POST /terms` missing `source_term_id` does reach argument validation, and comes back
as `400 rest_missing_callback_param`.

The two POSTs enforce their required keys in different places. `POST /posts`
validates `source_post_id` and `translations` inside the callback and answers
`wgtai_missing_source` / `wgtai_missing_translations` (`400`). `POST /terms` declares
all three of its top-level arguments `required` in the route registration instead, so
WordPress rejects a missing `source_term_id` as `rest_missing_callback_param` before
the callback runs.

Auth: an authenticated user who can edit the post, or the term, that the request
names. The term branch is evaluated first on purpose, so a `/terms` request can never
be granted merely because its numeric id also happens to be an editable post. A
`POST /terms` with no `source_term_id` falls back to the taxonomy's `edit_terms`
capability.

## Post Translations Request

The request body is **identical to the Polylang bridge**, so a multilingual posting
flow only needs to swap the namespace:

```json
{
  "source_post_id": 123,
  "translations": [
    {
      "language": "fr",
      "title": "Titre traduit",
      "content": "<p>Contenu traduit</p>",
      "excerpt": "Résumé",
      "meta": {
        "_yoast_wpseo_title": "Meta title",
        "_yoast_wpseo_metadesc": "Meta description",
        "blog_intro": "Introduction traduite"
      }
    }
  ]
}
```

`language` accepts a Weglot internal code (`fr`), a Weglot external/URL code, or
BCP-47 from NOVA (`nl-NL`) — all resolved against the project's configured
destination languages. `custom_fields` is accepted as an alias for `meta`.

`200 OK` when all items succeed, `207 Multi-Status` when some fail. The response
carries a `notes` array restating the contract deviations below.

### Update semantics

A POST for a locale that already has a payload **merges** into it, matching the
Polylang bridge (where `wp_update_post` leaves an omitted field intact), so a retry
that carries only the field it is fixing is safe:

| In the request | Effect |
|---|---|
| field omitted | whatever is stored is kept |
| `"field": null` | that field is cleared for this locale |
| `"field": value` | replaced |

`meta` merges **per key**, like `update_post_meta` — sending one key does not drop
the others, and `"meta": {}` means "this request carries no meta keys", not "delete
them all". Clear a single key by sending it as `null`.

## What is served, and where

| Payload field | Served through |
|---|---|
| `title` | `the_title`, `single_post_title`, `document_title_parts` |
| `content` | `the_content` (priority 9, so builder shortcodes still render) |
| `excerpt` | `get_the_excerpt` |
| `meta.*` | `get_post_metadata` — any key in the payload, which is what carries Blog/Service CPT fields |
| `meta._yoast_wpseo_title` / `_yoast_wpseo_metadesc` | Yoast's `wpseo_title` / `wpseo_metadesc` (+ OpenGraph and Twitter variants) |

Head fields we translate are added to `weglot_exclude_blocks` for that request only,
so Weglot does not re-translate our target-language title and meta description back
through its API. Each field is gated on **the key that actually renders it**: with an
SEO plugin active, `<title>` is built from `meta._yoast_wpseo_title` (a top-level
`title` never reaches the head, because Yoast reads `$post->post_title` and does not
run `the_title`); without one, `document_title_parts` carries the top-level `title`.

`the_title` also feeds the theme's rendered `<h1>` and breadcrumb, which no head
selector covers, so a translated `title` additionally excludes `.entry-title`,
`.wp-block-post-title` and `.post-title`. A theme that names its title element
something else needs one line through the `nova_weglot_title_selectors` filter, or
Weglot machine-translates the title in the body.

Storage: one meta key per locale, `_nova_weglot_i18n_<lang>`, plus an index at
`_nova_weglot_languages` — deliberately **outside** the `_nova_weglot_i18n_` prefix,
so no locale code can resolve onto the index key.

### Page-builder pages (Elementor)

A builder renders from its own post meta, not from `post_content`, and Elementor's
`the_content` filter *replaces* whatever ran before it — so swapping `content` does
not reach an Elementor page. The channel that does is `meta`: send the translated
document as `meta._elementor_data` and `get_post_metadata` serves it per locale.

Two things follow, both handled here:

- **The rendered builder markup carries no `.nova-weglot-i18n` wrapper**, so
  `weglot_exclude_blocks` would not cover it and Weglot would re-translate copy that
  is already in the target language — spending quota and garbling the text, since it
  applies source→target to it. On every request with a builder payload the render
  service diffs the stored document against the post's real one and excludes only the
  **leaf** elements whose **text** actually differs, as `.elementor-element-<id>`.
  Elements the payload left alone stay translatable by Weglot, so a partial
  translation degrades sensibly instead of stranding source-language text. With no
  readable original, everything in the stored document is excluded.

  Leaf-only and text-only are both load-bearing. Weglot honours an exclusion for the
  element *and its whole subtree*, so excluding a changed container would freeze every
  nested widget the payload never touched; and the stored side has been through
  `wp_kses_post` while the original has not, so comparing whole settings marked
  untouched widgets as changed on a round-trip artifact alone. Both sides are
  normalised before a difference is believed.
- **Structured meta is not sanitised as HTML.** `wp_kses_post` rebuilds tag attributes
  double-quoted, which turns an escaped `\"` inside a JSON blob into a raw `"` and
  truncates the document. Keys listed by the `nova_weglot_structured_meta_keys` filter
  (default: `_elementor_data`) are decoded, sanitised leaf by leaf, and re-encoded —
  whether they arrive as a JSON string or as a JSON array, which is normalised to a
  string so the builder can read it back. Nested array meta values are sanitised the
  same way rather than stored verbatim.
- **A structured key that cannot be stored intact fails the write.** If the value is not
  decodable JSON, or cannot be re-encoded after sanitising (invalid UTF-8, a tree past
  the JSON depth limit), `POST` returns `wgtai_invalid_structured_meta` /
  `wgtai_structured_encode_failed` for that locale and the locale keeps its last good
  payload. Storing it anyway is worse than failing: the builder reads the key back with
  `json_decode()`, gets nothing, and renders an **empty** document — Elementor then
  leaves `the_content` alone, and the theme prints the payload's raw `content` HTML with
  the page template gone (seen on 24peptides `/fr/`, Aug 2026).
- **`content` is never injected on a builder page.** When a payload carries a builder
  document, `the_content` is left alone: the builder owns the render, so anything this
  filter emits reaches a visitor only when the builder produced nothing — as raw,
  unstyled HTML. Falling back to the source layout, which Weglot still translates, is
  the better failure. Classic `post_content` pages are unaffected.

Both sides are driven off the **same** key list, so registering a builder gets it
sanitisation *and* notranslate protection. Registering only the first would leave the
payload exposed to re-translation:

```php
add_filter( 'nova_weglot_structured_meta_keys', function ( $keys ) {
    $keys[] = '_fl_builder_data';   // another builder that stores a JSON document
    return $keys;
} );

add_filter( 'nova_weglot_builder_selector_templates', function ( $templates ) {
    $templates['_fl_builder_data'] = '.fl-node-%s';   // that builder's element wrapper
    return $templates;
} );
```

Only Elementor ships with a template, because it is the only bridged builder whose
payload reaches the page through post meta. Divi, WPBakery, Avada and Gutenberg copy
arrives as `post_content` and is already covered by the `.nova-weglot-i18n` wrapper.
The tree walker expects Elementor's `{id, settings, elements}` shape; a builder that
nests differently needs its own walker rather than just a template.

## Taxonomy terms

`POST /terms` stores a per-locale payload on the source **term**, in `wp_termmeta`,
under the same `_nova_weglot_i18n_<lang>` key convention posts use in `wp_postmeta` —
the two tables are what keep a shared numeric id from colliding, not a second key
prefix. The request mirrors the Polylang and WPML term bridges, so a term flow only
swaps the namespace:

```json
{
  "source_term_id": 42,
  "taxonomy": "product_cat",
  "translations": [
    {
      "language": "fr",
      "name": "Chauffage au sol",
      "description": "<p>Description traduite</p>",
      "slug": "chauffage-au-sol",
      "meta": { "content_below_products": "<p>Texte sous les produits</p>" }
    }
  ]
}
```

`trid` is accepted and ignored — it exists only so a WPML/Polylang flow does not have
to strip it. `200` when every locale stored, `207` when some did not; a per-locale
failure comes back in `errors[]`, never as a whole-request error. Merge semantics are
the post ones: omitted keeps, `null` clears, `meta` merges per key.

Each `results[]` entry carries `source_term_id`, `language`, `stored`, `created`,
`fields[]`, `ignored_fields[]` and `url`. **`url` is the real taxonomy archive URL for
that term and language, never the requested slug** — that is the value to write to
`public.url`.

Term-specific rules:

- **`parent_id` is accepted, named in `results[].ignored_fields`, and never applied.**
  Weglot serves one archive per term per locale and has no per-locale hierarchy to
  re-parent into.
- **`slug` is recorded as `requested_slug` and never routed**, exactly like the post
  slug, and is deliberately kept out of `fields[]` so a response cannot imply the
  locale controls its own URL.
- **A page-builder meta key is refused on a term, not sanitised.** Keys listed by
  `nova_weglot_structured_meta_keys` (default `_elementor_data`) fail that locale with
  `wgtai_structured_meta_not_supported` in `errors[]` (so the request is a `207`,
  and every other locale in it still stores), because no builder
  document renders on a taxonomy archive: storing it as display HTML would buy
  `wp_kses_post` corruption of the JSON with none of the notranslate protection.
- **A term id belonging to another taxonomy is a `404`** (`wgtai_taxonomy_mismatch`),
  not a `400`. Writing — or reading — a payload onto the wrong entity is the failure
  being prevented, and the controller checks it, not storage: `term_id` is globally
  unique, so no storage code branches on taxonomy.

### Terms are stored and read back, but not served

There is **no term render path in this module.** `WGTAI_Render_Service` hooks
`the_content`, `the_title`, `single_post_title`, `document_title_parts`,
`get_the_excerpt`, `get_post_metadata`, `weglot_exclude_blocks` and the Yoast head
filters — and nothing on `term_description`, `single_term_title`, `get_term_metadata`
or WooCommerce's archive-description filters. A stored term payload is therefore
visible over REST and invisible to a visitor: the archive keeps its source-language
name and description, which Weglot machine-translates exactly as before.

The response does not say so either. `notes[]` adds only the `url`-vs-slug and
`parent_id` lines for a term, while the first line it shares with `/posts` still reads
"stored on the source post **and served** on Weglot-translated requests" — written for
posts, and not true of a term today. So a flow must not read `POST /terms` → `200` as
"the archive now shows NOVA's copy". The route is a write to storage: useful for
`results[].url`, and for the payload a later render stage will serve.

## Limitations

These are Weglot's, not the bridge's:

- **Translated slugs cannot be applied from here.** A `slug` in the payload is
  recorded as `requested_slug` and never used for routing. Weglot resolves slug
  translations from its own dashboard (Pro plan and up) and has no write API, so
  URLs stay `/{lang}/{source-slug}`. New URLs are not auto-detected by Weglot either.
- **Term payloads are stored but not rendered** — the one item in this list that is
  the bridge's, not Weglot's. The `/terms` routes are real (see
  [Taxonomy terms](#taxonomy-terms)), but nothing in this module serves a stored term
  name or description on the archive page, so Weglot machine-translates it.
- **Nothing appears in Weglot's Translation List.** The dashboard and visual editor
  will not show NOVA content, so an editor working there is editing a layer that is
  not being served.
- **`GET /languages` returns an empty `locale`.** The Polylang and WPML bridges report
  a real WP locale (`fr_FR`) under that key; Weglot has none. The URL segment — which
  the dashboard can customise, and which is *not* a locale — is reported under
  `external_code`. Anything mapping `locale` → hreflang or → a WP language pack must
  read `code` or `external_code` instead.
- **Only singular views are swapped.** Archive, listing and search views keep the
  source-language title and excerpt, which Weglot then machine-translates — so a
  listing can show a different wording than the page it links to.
- **Theme markup this bridge does not own** (a theme rendering stored meta fields in
  its own template) is still translated by Weglot. Add CSS selectors via the
  `nova_weglot_notranslate_selectors` filter to exclude those regions:

```php
add_filter( 'nova_weglot_notranslate_selectors', function ( $selectors, $language, $post_id ) {
    $selectors[] = '.entry-content';
    return $selectors;
}, 10, 3 );
```

## Verification status

`php tests/weglot-unit.php` runs standalone (no WordPress, no Weglot) and covers the
storage, render, language and REST surfaces.

Still **not verified against a live Weglot site.** `tests/weglot-regression.php`
(`wp eval-file`) is the end-to-end pass; the two things only a real page settles are
that `_elementor_data` reaches Elementor's `json_decode` intact through real
`get_post_meta`, and that the excluded elements are exactly the ones that stay
untranslated while the Weglot dashboard's word count does not move.

### Page-builder render caches

Elementor's **element cache** stores *rendered HTML* in the post's
`_elementor_element_cache` meta and `Document::print_elements()` echoes it without ever
reading `_elementor_data`:

```php
$is_element_cache_active = 'disable' !== get_option( 'elementor_element_cache_ttl', '' ); // ON by default
```

That cache has no language dimension, so whichever language renders first is served to
every locale. Seen on 24peptides: `/de/ /fr/ /es/` returned the **English** body while
their `<title>` and og/meta tags — filters, not cached — were correctly per-locale, and
our own notranslate exclusions (computed from the document we stored) told Weglot to
leave that English alone.

While a payload with a builder document applies, this bridge therefore forces
`elementor_element_cache_ttl` to `disable` for the request and reports
`_elementor_element_cache` as empty. Elementor re-renders from the document
`filter_post_metadata()` serves, and `set_document_cache()` never writes the translated
HTML back onto the post — which would otherwise leak one locale's copy into every other
language and into the source page. Register another builder's cache key through
`nova_weglot_builder_cache_meta_keys`.

**After deploying, purge the existing cache once** (Elementor → Tools → Regenerate CSS &
Data): entries written before this fix survive their 24h TTL, and one of them may already
hold a translated render of the source page.
