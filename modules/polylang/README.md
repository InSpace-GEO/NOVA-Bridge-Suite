# Polylang Translation API

REST endpoint for pushing translated content into Polylang.

## Endpoints
- `POST /wp-json/polylang-translations/v1/posts`
- `GET /wp-json/polylang-translations/v1/posts/{id}/translations`
- `POST /wp-json/polylang-translations/v1/terms`
- `GET /wp-json/polylang-translations/v1/terms/{id}?taxonomy=product_cat`
- `GET /wp-json/polylang-translations/v1/languages`

Auth: any authenticated user who can edit the related post/term.

## Post Translations Request
```json
{
  "source_post_id": 123,
  "translations": [
    {
      "language": "fr",
      "title": "Titre traduit",
      "slug": "page-traduite",
      "content": "<p>Contenu traduit</p>",
      "meta": {
        "_yoast_wpseo_title": "Meta title"
      }
    }
  ]
}
```

Optional update hints supported per translation item:
- `post_id`
- `translation_id`
- `term_id` (legacy flow compatibility)

## Term Translations Request
```json
{
  "source_term_id": 10,
  "taxonomy": "product_cat",
  "translations": [
    {
      "language": "de",
      "name": "Kategorie DE",
      "slug": "kategorie-de",
      "description": "Beschreibung",
      "meta": {
        "_yoast_wpseo_metadesc": "Beschreibung"
      }
    }
  ]
}
```

Optional compatibility field:
- `trid` is accepted in the request body and ignored (for easier migration from WPML flows).

## Response
`200 OK` when all translation items succeed, `207 Multi-Status` when some fail.
