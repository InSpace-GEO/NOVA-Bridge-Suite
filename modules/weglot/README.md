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

- `POST /wp-json/weglot-translations/v1/posts`
- `GET /wp-json/weglot-translations/v1/posts/{id}/translations`
- `DELETE /wp-json/weglot-translations/v1/posts/{id}/translations/{language}`
- `GET /wp-json/weglot-translations/v1/languages`
- `POST /wp-json/weglot-translations/v1/terms` → always `501` (see Limitations)

Auth: any authenticated user who can edit the related post.

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
through its API.

Storage: one meta key per locale, `_nova_weglot_i18n_<lang>`, plus an index at
`_nova_weglot_i18n_languages`.

### Page-builder pages (Elementor)

A builder renders from its own post meta, not from `post_content`, and Elementor's
`the_content` filter *replaces* whatever ran before it — so swapping `content` does
not reach an Elementor page. The channel that does is `meta`: send the translated
document as `meta._elementor_data` and `get_post_metadata` serves it per locale.

Two things follow, both handled here:

- **The rendered builder markup carries no `.nova-weglot-i18n` wrapper**, so
  `weglot_exclude_blocks` would not cover it and Weglot would re-translate copy that
  is already in the target language — spending quota and garbling the text, since it
  applies source→target to it. On every request with an `_elementor_data` payload the
  render service diffs the stored document against the post's real one and excludes
  only the elements whose settings actually differ, as
  `.elementor-element-<id>`. Elements the payload left alone stay translatable by
  Weglot, so a partial translation degrades sensibly instead of stranding source-language
  text. With no readable original, everything in the stored document is excluded.
- **Structured meta is not sanitised as HTML.** `wp_kses_post` rebuilds tag attributes
  double-quoted, which turns an escaped `\"` inside a JSON blob into a raw `"` and
  truncates the document. Keys listed by the `nova_weglot_structured_meta_keys` filter
  (default: `_elementor_data`) are decoded, sanitised leaf by leaf, and re-encoded.
  Nested array meta values are sanitised the same way rather than stored verbatim.

```php
add_filter( 'nova_weglot_structured_meta_keys', function ( $keys ) {
    $keys[] = '_fl_builder_data';   // another builder that stores a JSON document
    return $keys;
} );
```

## Limitations

These are Weglot's, not the bridge's:

- **Translated slugs cannot be applied from here.** A `slug` in the payload is
  recorded as `requested_slug` and never used for routing. Weglot resolves slug
  translations from its own dashboard (Pro plan and up) and has no write API, so
  URLs stay `/{lang}/{source-slug}`. New URLs are not auto-detected by Weglot either.
- **Taxonomy terms are not supported.** Term names are translated from the rendered
  page, so there is nothing to write — `/terms` returns `501` with that message
  rather than a confusing `404`.
- **Nothing appears in Weglot's Translation List.** The dashboard and visual editor
  will not show NOVA content, so an editor working there is editing a layer that is
  not being served.
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

Implemented but **not yet verified against a live Weglot site.** See the checklist in
the PR description before enabling for a client.
