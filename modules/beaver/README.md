# NOVA Beaver Builder Bridge

REST bridge for **Beaver Builder** (Lite and Pro). Unlike WPBakery/Divi, Beaver Builder does not store shortcodes in `post_content`: the layout lives in post meta **`_fl_builder_data`** — a serialized flat array of node objects (`row → column-group → column → module`, linked by `parent` + `position`). This module reads and writes that meta directly; `post_content` only receives a plain-HTML fallback of the text content (mirroring what Beaver Builder's own saves do).

- Namespace: `nova-beaver/v1`
- Auth: standard WordPress REST authentication (application passwords). Permissions: `edit_post` on the resolved target, else `edit_posts` / `edit_pages` by post type.
- Works with Beaver Builder **Lite** for its available modules. Rendering premium or third-party modules requires their original providers; an appended FAQ falls back to rich text when the source has no compatible accordion prototype.

## Endpoints

### `GET /wp-json/nova-beaver/v1/pages`

List pages/posts. Query params: `per_page`, `page`, `status`, `search`, `include`, `post_type`, `parent_id`, and `slug` (exact slug or hierarchical path — used as the existence check).

### `GET /wp-json/nova-beaver/v1/pages/{id-or-slug}`

Single page + layout. Query params:

- `layout_mode` — `outline` (default) or `full` (nested node tree)
- `include_document` — include the raw `_fl_builder_data` node map (ground truth for verifying module settings fields on a new site)
- `text_map` — include `[{path, text, content?}]`; repeater answer/body HTML is in `content` when applicable.

The `layout.outline` is a flat list of text-bearing modules: `{path, tag, label, context, text, content?}`. Use returned paths for content updates. Repeater **items** are settings entries rather than child nodes, so they get a virtual path segment: `"0.2.1@0"` is the first item of the module at `"0.2.1"` — paths stay opaque strings that callers echo back unchanged. Supported repeaters and their body fields are listed below.

### `POST /wp-json/nova-beaver/v1/pages` — create

### `PUT|PATCH /wp-json/nova-beaver/v1/pages/{id-or-slug}` — update

JSON body (all keys optional unless noted):

| Key | Meaning |
|---|---|
| `title` (create: required in practice) | Post title, plain text |
| `slug` | Slug or `parent/child` path (parent resolved to `post_parent`) |
| `status` | `draft` (default on create) / `publish` / ... |
| `post_type` | `page` (default) or `post` / CPT |
| `author`, `parent` / `parent_id`, `excerpt` | Standard post fields |
| `source_page_id` / `source_page` | Clone this post's Beaver Builder layout + meta (incl. featured image) as the template. Cloned layouts get **fresh node IDs** |
| `text_updates` | `[{path, text, field?}]` — replace module text at outline paths. Writes to **the same field the outline showed**: body for `rich-text`, `heading` for headings, button label for `button`, `title` for callout/CTA, item label for `"path@i"` item paths. Override with `field`: `"body"`/`"content"` targets the module's rich body (e.g. an accordion item's answer), any other name targets that settings key |
| `remove_paths` | `["0.1", "0.2.1@0", ...]` — delete modules or supported repeater items at returned paths. Safe to combine with `text_updates` in one request: both use the paths from the same GET (text updates apply first) |
| `append_sections` | `[{title, body, title_tag, type?}]` — each becomes a row > column-group > column with a real `heading` module for the title (BB has one — no `<h2>`-inside-text workaround needed) plus a `rich-text` module for the body. `type: "faq"` renders an `accordion` module from `<h3>Q</h3><p>A</p>` pairs in `body` (`open_first: yes`). In clone mode (and on updates), a single section whose body holds multiple `<h2>` blocks is auto-split into one section per `<h2>` (FAQ headings auto-detected); for from-scratch creates pass `split_sections: true` to opt in |
| `append_html` | One extra rich-text module in its own row |
| `layout.nodes` / `layout.compact` | Replace the layout wholesale (power use): `nodes` = the flat map a GET `document` returns, `compact` = the nested tree from `layout_mode=full` |
| `keep_source_content` | Clone mode: `true` appends sections instead of replacing the template's text slots |
| `meta`, `meta_title`, `meta_description` | Post meta; SEO title/description are mapped to Yoast / AIOSEO / Rank Math keys |
| `featured_image` `{attachment_id\|url, alt, caption}` or `featured_image_url` | Featured image (URL is sideloaded into the media library; non-fatal on failure) |
| `publish_builder` | Force the `_fl_builder_enabled` flag even for an empty layout |

Clone mode (`source_page_id` + `append_sections`) writes the sections **into the template's content slots** in document order. A content slot is a `rich-text` module that is empty, heading-led (`h2`–`h4`), or paragraph-scale (≥ 240 visible chars) — short heading-less texts (hero subtitles, CTA copy) are template chrome and are left untouched; edit those via `text_updates`. The first slot skips a heading equal to the page title. Unfilled content slots are removed and rows/column-groups/columns they leave empty are pruned, so no stale example text or empty bands survive. Sections that don't fit are appended — FAQ sections are always appended as accordions.

Capability model: the effective post type (including the `type` alias and values nested in a JSON `content` payload) is re-validated server-side — creating requires that type's `edit_posts` capability, `publish`/`future`/`private` status requires its `publish_posts`, and assigning another `author` requires `edit_others_posts` (silently dropped otherwise).

## Beaver Builder format notes (for maintainers)

- **Every write deletes `_fl_builder_draft` (+`_fl_builder_draft_settings`).** The builder UI loads the draft when one exists; a stale draft would show old content and publishing from the UI would clobber the bridge's layout with it.
- **Every write clears the per-post asset cache** (`FLBuilderModel::delete_asset_cache`) so BB regenerates the layout's CSS/JS under `/uploads/bb-plugin/cache/`.
- Node IDs are ~13-char random alphanumerics. They are **regenerated on clone** (a copy must not share IDs with its source — BB uses them in CSS classes and cache filenames) and **kept on update** (stable CSS/caches for unchanged modules). `FLBuilderModel::generate_node_id()` is used when available.
- Module settings are **opaque**: the bridge mutates only the known text fields and preserves everything else (including settings it doesn't model) through parse → serialize round-trips.
- Module nodes carry their slug at `settings->type`; structural nodes are `row`, `column-group`, `column`. Container modules (Box) are handled generically — any node other nodes point at as `parent` keeps its children.
- Field names to re-verify on a new site via `GET ?include_document=1`: accordion/tabs `items[]` (`label`/`content`) and callout/CTA (`title`/`text`) — see the module design doc.

## Beaver repeater content (2.8.6)

The compact endpoint now includes `faq`, `pp-iconlist` and `content-slider` items:

```text
GET /wp-json/nova-beaver/v1/pages/3312?layout_mode=outline&include_document=false&text_map=true&include_meta=true
```

Each item has an opaque `path`, such as `7.0.0.1@0`. `text` contains its question, title or list text. An additional `content` string contains its rich HTML answer/body, when the module has one. Both `layout.outline` and `text_map` include this body. Existing accordion/tab paths and labels remain unchanged; their bodies are now included too. Full mode still returns `layout.compact`; `include_document=true` is unnecessary for ordinary content discovery.

| Module | Native collection | Default text target | `field:"body"` or `field:"content"` target |
| --- | --- | --- | --- |
| `faq` | `faqs` | `question` | `answer` |
| `pp-iconlist` | `list_items` | The string item itself | None |
| `content-slider` | `slides` | `title` | `text` |
| `accordion`, `tabs` | `items` | `label` | `content` |

Use paths returned by the current target's GET. For example, after confirming this FAQ item exists:

```json
{
  "text_updates": [
    {"path": "7.0.0.1@0", "text": "What does the service include?"},
    {"path": "7.0.0.1@0", "field": "body", "text": "<p>Service details with <strong>formatting</strong>.</p>"}
  ]
}
```

Multiple updates to the same node/item are applied in request order. Different fields are retained; the last update wins when the same field is targeted repeatedly. Plain labels strip tags; body HTML is sanitized using WordPress `wp_kses_post`. Native item field names such as `answer` or slide `text` also use the correct HTML sanitizer. Presentation settings and unknown fields are retained unless explicitly targeted.

`remove_paths` accepts returned item paths and removes entries from their actual native collection. Updates run before removals so both use the original GET indices. After removal, read again before using paths. Out-of-range item updates do not add items. This patch edits/removes existing entries; it does not introduce a repeater-insertion API or alter how `append_sections` chooses its renderer.

For explicit template mapping on create, use the existing `source_page_id`, `keep_source_content:true` and `text_updates` contract. The bridge accepts a mapped create without appended sections. An upstream workflow must permit that contract; this patch does not change its prompts or validators. Existing automatic slot replacement is unchanged, and setting WordPress `title` alone still does not rewrite a static hero module.

WordPress fallback `post_content` now also includes these modules' text and answer/body HTML, for consumers that do not render Beaver's native layout. Rendering the styled modules requires the site's original module providers.

The regression script is `tests/beaver-repeaters-regression.php`. It accepts the full page-3312 GET as an external fixture; client layout data is not bundled in the plugin.
