# Elementor removals (2.8.8)

POST `/wp-json/seor-bridge/v1/pages` or
`/wp-json/seor-bridge/v1/pages/{id}` accepts two optional operations:

```json
{
  "remove_elements": ["faq-heading-id"],
  "remove_accordion_items": [
    {"element_id": "accordion-id", "indices": [5, 6, 7, 8, 9, 10, 11, 12]}
  ]
}
```

Use IDs and zero-based indices from the same GET used to construct `fields`.
Field edits apply before removals, so indices refer to the original document.
`remove_elements` removes complete elements and descendants. `remove_accordion_items`
supports native `nested-accordion`, `accordion`, and `toggle` widgets. For nested
accordions it removes the question row and same-index answer container together,
then reindexes both arrays. Remaining row IDs, styling and content are retained.
To remove every FAQ, remove the heading and accordion via `remove_elements`.
Removing every row through `indices` is rejected to avoid empty accordion defaults.

Unknown or ambiguous IDs, unsupported widgets, out-of-range indices and mismatched
nested question/container counts fail rather than guessing. Removing a nested
answer container directly is rejected; use the paired operation. Always read the
current document before retrying an index-based removal.

Omitted operations and empty operation arrays preserve existing behavior. Omitting
a field is still NOT deletion. Full `elementor_data` replacements remain supported,
including when combined with explicit removals. Native accordion `tabs` array
replacement and `append_faqs` are unchanged.

Document settings are separate from widget fields. To enable Hide Title, send:

```json
{"elementor_page_settings":{"hide_title":"yes"}}
```

This replaces the stored page-settings object, so merge with existing settings
when updating one property. Read it with `meta_keys=_elementor_page_settings`.
Pass `template` separately. Source cloning copies widget data, not these settings;
callers must propagate the intended settings and template to translations.

# Slug lookups on multilingual sites (2.8.13)

`GET /wp-json/seor-bridge/v1/pages?slug=…` resolves the slug in the language the
request asks for: the WPML language directory (`/en/wp-json/seor-bridge/v1/pages?slug=services`)
or `?lang=en`. Two pages that share a slug in different languages (German
`/services/`, English `/en/services/`) no longer resolve to the default-language
one. A slug with no page in a non-default language answers `404
seor_eb_not_found`; it never falls back to the default-language page. A request
in the default language (an unprefixed `/wp-json/` call) that finds nothing in
that language falls back to the previous lookup, so existing update flows keep
finding their pages. `lang=all`, and sites without WPML or Polylang, keep the
previous lookup.

Nested paths (`services/consulting`) match the page whose parent chain spells
that path. Without `?status=`, a draft is still found by its slug, and a
published page with the same path wins over it. Resolving the parent in English
does not make the new page English: create it through the same `/en/` address,
or with `?lang=en`.
