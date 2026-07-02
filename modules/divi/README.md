# NOVA Divi Bridge

REST bridge for the **Divi Builder**. Creates and updates posts/pages whose layout is a Divi 4 `et_pb_*` shortcode tree stored in `post_content`, with the `_et_pb_use_builder = on` meta set so the page opens natively in the Divi Visual Builder.

**Divi 5:** content written by this module renders on Divi 5 sites through Divi's backwards-compatibility layer and can be converted per page with the official Divi 5 Migrator. Responses include a `divi` info block (`builder_version`, `divi5_active`).

- Namespace: `nova-divi/v1`
- Auth: standard WordPress REST authentication (application passwords). Permissions: `edit_post` on the resolved target, else `edit_posts` / `edit_pages` by post type.

## Endpoints

### `GET /wp-json/nova-divi/v1/pages`

List pages/posts. Query params: `per_page`, `page`, `status`, `search`, `include`, `post_type`, `parent_id`, and `slug` (exact slug or hierarchical path — used as the existence check).

### `GET /wp-json/nova-divi/v1/pages/{id-or-slug}`

Single page + layout. Query params:

- `layout_mode` — `outline` (default) or `full` (compact node tree)
- `include_document` — include raw shortcodes
- `text_map` — include `[{path, text}]`

The `layout.outline` is a flat list of text-bearing modules: `{path, tag, label, context, text}`. Those `path` values are the only valid targets for `text_updates` / `remove_paths`.

### `POST /wp-json/nova-divi/v1/pages` — create

### `PUT|PATCH /wp-json/nova-divi/v1/pages/{id-or-slug}` — update

JSON body (all keys optional unless noted):

| Key | Meaning |
|---|---|
| `title` (create: required in practice) | Post title, plain text |
| `slug` | Slug or `parent/child` path (parent resolved to `post_parent`) |
| `status` | `draft` (default on create) / `publish` / ... |
| `post_type` | `page` (default) or `post` / CPT |
| `author`, `parent` / `parent_id`, `excerpt` | Standard post fields |
| `source_page_id` / `source_page` | Clone this post's Divi layout + meta as the template |
| `text_updates` | `[{path, text}]` — replace module text at outline paths. Buttons update `button_text`; add `"field": "title"` to target a named attribute instead of the body |
| `remove_paths` | `["0.1", ...]` — delete modules at outline paths |
| `append_sections` | `[{title, body, title_tag, type?}]` — each becomes a section > row > column with the heading as `<h2>` HTML inside an `et_pb_text`. `type: "faq"` renders an `et_pb_accordion` from `<h3>Q</h3><p>A</p>` pairs in `body`. A single section whose body holds multiple `<h2>` blocks is auto-split into one section per `<h2>` (FAQ headings auto-detected) |
| `append_html` | One extra text module in its own section |
| `layout.raw_shortcodes` / `layout.compact` | Replace the layout wholesale (power use) |
| `keep_source_content` | Clone mode: `true` appends sections instead of replacing the template's text slots |
| `meta`, `meta_title`, `meta_description` | Post meta; SEO title/description are mapped to Yoast / AIOSEO / Rank Math keys |
| `featured_image` `{attachment_id\|url, alt, caption}` or `featured_image_url` | Featured image (URL is sideloaded into the media library; non-fatal on failure) |
| `page_layout` | `et_full_width_page` / `et_right_sidebar` / `et_left_sidebar` / `et_no_sidebar` |
| `show_title`, `old_content`, `publish_builder` | Divi extras: hide/show default title, plain-HTML builder-off fallback, force the builder flag |

Clone mode (`source_page_id` + `append_sections`) writes the sections **into the template's `et_pb_text` slots** in document order (the first slot skips a heading equal to the page title), clears leftover example text, and appends whatever didn't fit — FAQ sections are always appended as accordions.

## Divi format notes (for maintainers)

- Generated elements are stamped with `fb_built="1"` (sections) and `_builder_version` (from `ET_BUILDER_VERSION`, fallback `4.27.4`).
- Attribute values use Divi's percent-encoding: `"` → `%22`, `[` → `%91`, `]` → `%93`, `\` → `%92`. Never `esc_attr` them — HTML entities break the builder, and a raw quote breaks shortcode parsing.
- Divi 4 has no heading module on older sites: headings are emitted as `<h2>/<h3>` HTML inside `et_pb_text` bodies. Incoming `<h1>` is always downgraded to `<h2>`.
- `et_pb_*` tags are force-registered into `$shortcode_tags` before parsing so `get_shortcode_regex()` works even when Divi isn't bootstrapped in the REST request.
