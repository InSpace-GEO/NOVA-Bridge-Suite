# Local content rules and source-free Elementor pages

This plugin feature keeps rendering rules in WordPress. It changes neither posting-service nor nova-agents-backend, and uploads no rules. Existing fixed-field update/clone profiles remain a separate workflow.

## Use the editor

Enable API Mapping Context and open **Settings → NOVA Settings → Build from content**. Start with the Elementor article preset, choose a WordPress post type, and name the profile. Each content type has one rule: for example, every heading becomes a Heading widget and every paragraph becomes a Text Editor widget. Styles are optional; otherwise the site's Elementor defaults apply. FAQ can use an Accordion or a group of headings and answers. Groups repeat and nest according to the supplied content rather than a fixed row count.

Paste sample HTML or switch to the structured sample. **Preview** shows a sanitized content preview; **Create Elementor draft** creates a real, editable native document for exact layout review. Save the profile before creating a draft. No source page or widget IDs are required. Draft creation uses the currently authenticated WordPress user's permissions and needs the Elementor bridge/native provider to be available. Retrying the same request reuses its operation; after verified success, **Create another draft** starts an intentional separate draft.

Saving retains immutable local revisions. A stale save returns a conflict instead of overwriting another administrator's work. Existing delivery bindings keep their approved revision until explicitly changed.

## Content and scope

HTML headings, paragraphs, lists and images retain their source order and inline rich text. Containers and explicit structured groups can produce nested layouts. FAQ relationships must be provided as structured items; arbitrary prose is not guessed into questions and answers. Unsupported safe HTML is retained as rich text where possible. Unsupported structures and resource limits fail explicitly instead of truncating an article.

The first native adapter targets Elementor's widget document format: Heading, Text Editor, Image, Accordion and Container. The profile schema is versioned so other page builders can receive their own adapters later. Elementor's MCP is optional and is not required by this renderer. The current official MCP supports Atomic V4 layouts; this plugin's existing native bridge supplies the widget save lifecycle.

These are rendering rules. They do not ask NOVA to rewrite content, fit it to a fixed number of fields, or change generation instructions.

## Optional delivery translation

The editor works without a posting-service connection. With a connection already configured, an administrator can explicitly select an exact local profile revision for **unconfigured deliveries** from that site. This preference is saved locally; it sends no setup request to either backend. It does not enable/unpause the service connection.

Only a verified legacy delivery with `configuration: null` can select that route. Deliveries carrying a remote template still require the existing matching approved profile; a failed or unknown remote template never falls back to local rules. The first local route reads the main article HTML at `content.content`. Top/bottom article fields are not merged automatically; their presence produces a warning. Other source-field arrangements and remote structured/adaptation contracts require separate support.

Delivery URLs must belong to this WordPress installation and match its base language. Page paths can use an existing page hierarchy. Blog posts support `/%postname%/` and fixed prefixes such as `/blog/%postname%/`; date/category permalinks, custom post type delivery routing and automatic multilingual selection need another routing adapter. Registered Elementor-enabled custom post types can still be used for local draft creation.

The worker retains the selected profile, renderer/native plan and operation identity before a write. A local URL-to-page association and native operation witness reconcile retries and later content versions. The first delivery creates a draft; later versions update the owned document and preserve its publication status. Human changes to generated content stop automatic replacement. Draft receipt and successful publication remain separate events; a draft does not produce `publication_succeeded`.

## Verification boundary

Standalone PHP/Node checks exercise normalization, variable block counts, nested groups, profile revision conflicts, permissions, native creation/recovery through simulated WordPress/Elementor boundaries, and existing delivery compatibility. A localhost UI fixture can exercise the real editor and preview without contacting a site.

Validation on 2026-10-08 passed all 23 standalone PHP suites in `modules/api-mapping-context/tests` and `modules/posting-service/tests`, all 44 Node tests across their six unit files, and changed PHP/JavaScript syntax checks. New coverage includes 54 renderer/profile checks, administrator routes and UI behavior, and 44 native writer/delivery lifecycle checks. Browser verification covered saving a local revision, HTML preview, unsaved-preview restrictions, and nested groups with both FAQ layouts. These tests use simulated WordPress/Elementor boundaries.

An authorized isolated test on `inspace-seo.online` passed 53 native checks on WordPress 7.1.3, Elementor 3.35.9 and PHP 8.1.34. It retained four drafts and verified native saves, frontend output, styles, image alt text, variable block counts, nested FAQs, and success-path retries/recovery. The installed Suite 2.8.5 and inactive Elementor configuration stayed unchanged. See [the staging record](content-rules-staging-20261008.md).

Before installing this candidate on a customer site, also open drafts in the native Elementor editor, inspect the full desktop/mobile page layout and exercise an explicitly approved draft-to-publish transition. Editor interaction, actual crash injection on staging, multilingual delivery routing and a live posting-service round trip have not been certified. The isolated native result applies to the tested Elementor/provider version.
