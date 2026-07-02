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
| `source_page_id` / `source_page` | Clone this post's Divi layout + meta (incl. featured image) as the template |
| `text_updates` | `[{path, text, field?}]` — replace module text at outline paths. Writes to **the same field the outline showed**: body for `et_pb_text`, `title` for accordion items / toggles / blurbs / CTAs / headings, `button_text` for buttons, `heading` for slides, `name` for team members. Override with `field`: `"body"`/`"content"` targets the inner body (e.g. an accordion item's answer), any other name targets that attribute |
| `remove_paths` | `["0.1", ...]` — delete modules at outline paths. Safe to combine with `text_updates` in one request: both use the paths from the same GET (text updates apply first) |
| `append_sections` | `[{title, body, title_tag, type?}]` — each becomes a section > row > column with the heading as `<h2>` HTML inside an `et_pb_text`. `type: "faq"` renders an `et_pb_accordion` from `<h3>Q</h3><p>A</p>` pairs in `body`. In clone mode (and on updates), a single section whose body holds multiple `<h2>` blocks is auto-split into one section per `<h2>` (FAQ headings auto-detected); for from-scratch creates pass `split_sections: true` to opt in |
| `append_html` | One extra text module in its own section |
| `layout.raw_shortcodes` / `layout.compact` | Replace the layout wholesale (power use) |
| `keep_source_content` | Clone mode: `true` appends sections instead of replacing the template's text slots |
| `meta`, `meta_title`, `meta_description` | Post meta; SEO title/description are mapped to Yoast / AIOSEO / Rank Math keys |
| `featured_image` `{attachment_id\|url, alt, caption}` or `featured_image_url` | Featured image (URL is sideloaded into the media library; non-fatal on failure) |
| `page_layout` | `et_full_width_page` / `et_right_sidebar` / `et_left_sidebar` / `et_no_sidebar` |
| `show_title`, `old_content`, `publish_builder` | Divi extras: hide/show default title, plain-HTML builder-off fallback, force the builder flag |

Clone mode (`source_page_id` + `append_sections`) writes the sections **into the template's content slots** in document order. A content slot is an `et_pb_text` that is empty, heading-led (`h2`–`h4`), or paragraph-scale (≥ 240 visible chars) — short heading-less texts (hero subtitles, CTA copy) are template chrome and are left untouched; edit those via `text_updates`. The first slot skips a heading equal to the page title. Unfilled content slots are removed and containers they leave empty are pruned, so no stale example text or empty section bands survive. Sections that don't fit are appended — FAQ sections are always appended as accordions.

Capability model: the effective post type (including the `type` alias and values nested in a JSON `content` payload) is re-validated server-side — creating requires that type's `edit_posts` capability, `publish`/`future`/`private` status requires its `publish_posts`, and assigning another `author` requires `edit_others_posts` (silently dropped otherwise).

## Divi format notes (for maintainers)

- Generated elements are stamped with `fb_built="1"` (sections) and `_builder_version` (from `ET_BUILDER_VERSION`, fallback `4.27.4`).
- Attribute values use Divi's percent-encoding: `"` → `%22`, `[` → `%91`, `]` → `%93`, `\` → `%92`. Never `esc_attr` them — HTML entities break the builder, and a raw quote breaks shortcode parsing.
- Divi 4 has no heading module on older sites: headings are emitted as `<h2>/<h3>` HTML inside `et_pb_text` bodies. Incoming `<h1>` is always downgraded to `<h2>`.
- `et_pb_*` tags are force-registered into `$shortcode_tags` before parsing so `get_shortcode_regex()` works even when Divi isn't bootstrapped in the REST request.
