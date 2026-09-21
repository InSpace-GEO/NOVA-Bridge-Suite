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
