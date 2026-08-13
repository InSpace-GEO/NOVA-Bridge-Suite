<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Serves stored per-locale content on Weglot-translated requests.
 *
 * Weglot strips the language prefix from REQUEST_URI on plugins_loaded and lets
 * WordPress render the original post as normal, so every ordinary template filter
 * still runs and weglot_get_current_language() still reports the requested
 * language. This service swaps NOVA's stored payload into those filters and marks
 * the result data-wg-notranslate, which Weglot's parser honours for the element
 * and its entire subtree - so our copy is served verbatim and burns no Weglot
 * word quota.
 */
class WGTAI_Render_Service
{
    private WGTAI_Language_Service $language_service;
    private WGTAI_Storage_Service $storage_service;

    private ?array $payload = null;
    private int $post_id    = 0;
    private string $language = '';
    private bool $filters_registered = false;

    /** @var array<int,string> CSS selectors for builder elements whose text this payload changed. */
    private array $builder_notranslate_selectors = [];

    public function __construct(WGTAI_Language_Service $language_service, WGTAI_Storage_Service $storage_service)
    {
        $this->language_service = $language_service;
        $this->storage_service  = $storage_service;
    }

    public function hooks(): void
    {
        add_action('wp', [$this, 'resolve_payload']);
    }

    /**
     * Decides once per request whether a stored payload applies.
     */
    public function resolve_payload(): void
    {
        if (is_admin() || $this->is_rest_request() || ! is_singular()) {
            return;
        }

        $language = $this->language_service->get_current_code();

        if ($language === '' || $this->language_service->is_original_language($language)) {
            return;
        }

        $post_id = get_queried_object_id();

        if ($post_id <= 0) {
            return;
        }

        $payload = $this->storage_service->get($post_id, $language);

        if (null === $payload) {
            // Weglot may serve a regional code ("fr-be") backed by a base language.
            $resolved = $this->language_service->resolve_destination_code($language);

            if ($resolved !== '' && $resolved !== $language) {
                $payload  = $this->storage_service->get($post_id, $resolved);
                $language = $resolved;
            }
        }

        if (null === $payload) {
            return;
        }

        $this->payload  = $payload;
        $this->post_id  = $post_id;
        $this->language = $language;

        // Must run BEFORE register_filters(): it reads the post's real
        // _elementor_data, which our own get_post_metadata filter would shadow.
        $this->prepare_builder_exclusions($post_id);

        $this->register_filters();
    }

    /**
     * Registered only after resolution so the get_post_metadata filter cannot
     * recurse into the payload lookup that installs it.
     */
    private function register_filters(): void
    {
        if ($this->filters_registered) {
            return;
        }

        $this->filters_registered = true;

        // Priority 9: ahead of wpautop (10) and do_shortcode (11) so builder
        // shortcodes inside a stored payload still render.
        add_filter('the_content', [$this, 'filter_content'], 9);
        add_filter('the_title', [$this, 'filter_title'], 10, 2);
        add_filter('single_post_title', [$this, 'filter_single_post_title'], 10, 2);
        add_filter('document_title_parts', [$this, 'filter_document_title_parts']);
        add_filter('get_the_excerpt', [$this, 'filter_excerpt'], 10, 2);
        add_filter('get_post_metadata', [$this, 'filter_post_metadata'], 10, 4);
        add_filter('weglot_exclude_blocks', [$this, 'filter_exclude_blocks']);

        $this->register_yoast_filters();
    }

    private function register_yoast_filters(): void
    {
        $title = $this->get_meta_value('_yoast_wpseo_title');
        $desc  = $this->get_meta_value('_yoast_wpseo_metadesc');

        if (null !== $title) {
            add_filter('wpseo_title', [$this, 'filter_yoast_title'], 20);
            add_filter('wpseo_opengraph_title', [$this, 'filter_yoast_title'], 20);
            add_filter('wpseo_twitter_title', [$this, 'filter_yoast_title'], 20);
        }

        if (null !== $desc) {
            add_filter('wpseo_metadesc', [$this, 'filter_yoast_metadesc'], 20);
            add_filter('wpseo_opengraph_desc', [$this, 'filter_yoast_metadesc'], 20);
            add_filter('wpseo_twitter_description', [$this, 'filter_yoast_metadesc'], 20);
        }
    }

    public function filter_content($content)
    {
        if (! $this->applies_to(get_the_ID())) {
            return $content;
        }

        $translated = $this->payload['content'] ?? '';

        if (! is_string($translated) || '' === trim($translated)) {
            return $content;
        }

        // On a builder page the builder owns the render, and this filter's output
        // only ever reaches the visitor when the builder produced nothing --
        // Elementor's apply_builder_in_content() leaves the_content alone when its
        // own content is empty. That surfaced on 24peptides /fr/ as the payload's
        // raw HTML printed straight into the theme's entry-content, with the whole
        // page template gone. Serving nothing here is strictly better: the page
        // falls back to the source layout, which Weglot still translates.
        if ($this->payload_carries_builder_document()) {
            return $content;
        }

        return sprintf(
            '<div class="nova-weglot-i18n nova-weglot-i18n--%s" data-wg-notranslate>%s</div>',
            esc_attr($this->language),
            $translated
        );
    }

    public function filter_title($title, $post_id = null)
    {
        if (null === $post_id || ! $this->applies_to((int) $post_id)) {
            return $title;
        }

        $translated = $this->payload['title'] ?? '';

        return is_string($translated) && '' !== trim($translated) ? $translated : $title;
    }

    public function filter_single_post_title($title, $post = null)
    {
        $post_id = is_object($post) && isset($post->ID) ? (int) $post->ID : 0;

        if (! $this->applies_to($post_id)) {
            return $title;
        }

        $translated = $this->payload['title'] ?? '';

        return is_string($translated) && '' !== trim($translated) ? $translated : $title;
    }

    /**
     * @param array<string,string> $parts
     *
     * @return array<string,string>
     */
    public function filter_document_title_parts($parts)
    {
        if (! is_array($parts) || null === $this->payload) {
            return $parts;
        }

        $translated = $this->payload['title'] ?? '';

        if (is_string($translated) && '' !== trim($translated)) {
            $parts['title'] = $translated;
        }

        return $parts;
    }

    public function filter_excerpt($excerpt, $post = null)
    {
        $post_id = is_object($post) && isset($post->ID) ? (int) $post->ID : 0;

        if (! $this->applies_to($post_id)) {
            return $excerpt;
        }

        $translated = $this->payload['excerpt'] ?? '';

        return is_string($translated) && '' !== trim($translated) ? $translated : $excerpt;
    }

    /**
     * Serves stored per-locale values for any meta key present in the payload.
     * This is what carries Blog/Service CPT fields and SEO meta, which the
     * templates read straight from post meta.
     *
     * @param mixed  $value
     * @param int    $object_id
     * @param string $meta_key
     * @param bool   $single
     *
     * @return mixed
     */
    public function filter_post_metadata($value, $object_id, $meta_key, $single)
    {
        if (! is_string($meta_key) || '' === $meta_key) {
            return $value;
        }

        // Never let a payload override the bridge's own storage keys: it would
        // otherwise be able to rewrite what get()/get_stored_languages() report for
        // the rest of the request.
        if ($this->is_reserved_meta_key($meta_key)) {
            return $value;
        }

        if (! $this->applies_to((int) $object_id)) {
            return $value;
        }

        $translated = $this->get_meta_value($meta_key);

        if (null === $translated) {
            return $value;
        }

        // Always wrap, for BOTH values of $single. get_metadata_raw() does
        //
        //     if ( $single && is_array( $check ) ) { return $check[0]; }
        //
        // so a single-value read unwraps one level itself, and a multi-value read
        // wants the list. Returning a bare $translated under $single was only
        // correct for scalars: an array meta value (which storage supports, and
        // sanitize_deep recurses into) came back as $check[0] -- the first element
        // for a list, and an "Undefined array key 0" warning plus null for a map.
        return [$translated];
    }

    /**
     * Tells Weglot to leave the head fields we already translated alone, so it
     * does not re-translate our target-language copy back through the API.
     *
     * @param array<int,string> $blocks
     *
     * @return array<int,string>
     */
    public function filter_exclude_blocks($blocks)
    {
        if (! is_array($blocks) || null === $this->payload) {
            return $blocks;
        }

        $selectors = array_merge(['.nova-weglot-i18n'], $this->builder_notranslate_selectors);

        // Gate each head field on the key that actually renders it, not on a
        // different one that merely tends to travel with it. Both mismatches were
        // harmful: a payload carrying only _yoast_wpseo_title emitted the Yoast
        // <title> but never excluded it (Weglot re-translated our own French), and
        // a payload carrying only a top-level title excluded <title> on a Yoast
        // site where Yoast builds it from $post->post_title and never runs
        // the_title (so an untranslated title shipped, marked do-not-touch).
        if ($this->has_yoast_title()) {
            // register_yoast_filters() installed wpseo_title + the OG/Twitter
            // variants, so all three head fields are ours.
            $selectors[] = 'title';
            $selectors[] = 'meta[property="og:title"]';
            $selectors[] = 'meta[name="twitter:title"]';
        } elseif ($this->has_translated_title() && ! $this->seo_plugin_owns_document_title()) {
            // No SEO plugin: <title> comes from document_title_parts, which we
            // filter. The OG/Twitter tags are not ours -- nothing emits them here.
            $selectors[] = 'title';
        }

        if ($this->has_translated_title()) {
            // the_title feeds the theme's rendered <h1> and breadcrumb, which no
            // head selector covers. Without this Weglot re-translates the
            // target-language title in the body: quota spent to garble our copy.
            foreach ($this->body_title_selectors() as $selector) {
                $selectors[] = $selector;
            }
        }

        if (null !== $this->get_meta_value('_yoast_wpseo_metadesc')) {
            $selectors[] = 'meta[name="description"]';
            $selectors[] = 'meta[property="og:description"]';
            $selectors[] = 'meta[name="twitter:description"]';
        }

        /**
         * Themes render stored meta fields in markup this bridge does not own.
         * Add CSS selectors here to keep those regions out of Weglot too.
         *
         * @param array<int,string> $selectors
         * @param string            $language
         * @param int               $post_id
         */
        // Cast like the sibling call sites: this is a documented extension point,
        // and a filter that returns a bare string would otherwise make foreach
        // warn on every translated page view AND drop every built-in selector,
        // handing Weglot the whole document to re-translate.
        $selectors = (array) apply_filters('nova_weglot_notranslate_selectors', $selectors, $this->language, $this->post_id);

        foreach ($selectors as $selector) {
            if (is_string($selector) && '' !== $selector && ! in_array($selector, $blocks, true)) {
                $blocks[] = $selector;
            }
        }

        return $blocks;
    }

    public function filter_yoast_title($title)
    {
        $translated = $this->get_meta_value('_yoast_wpseo_title');

        return is_string($translated) && '' !== trim($translated) ? $translated : $title;
    }

    public function filter_yoast_metadesc($description)
    {
        $translated = $this->get_meta_value('_yoast_wpseo_metadesc');

        return is_string($translated) && '' !== trim($translated) ? $translated : $description;
    }

    /**
     * @return mixed|null
     */
    private function get_meta_value(string $meta_key)
    {
        if (null === $this->payload || empty($this->payload['meta']) || ! is_array($this->payload['meta'])) {
            return null;
        }

        if (! array_key_exists($meta_key, $this->payload['meta'])) {
            return null;
        }

        return $this->payload['meta'][$meta_key];
    }

    /**
     * Marks the page-builder elements this payload rewrote as notranslate.
     *
     * Elementor renders from _elementor_data, not post_content, so a stored
     * payload reaches an Elementor page through filter_post_metadata rather than
     * the_content -- and the markup Elementor produces carries no
     * .nova-weglot-i18n wrapper. Left alone, Weglot would machine-translate the
     * copy we just served: that spends the word quota this bridge exists to
     * avoid, and it re-runs source->target on already-translated text, which
     * garbles it rather than being a harmless no-op.
     *
     * Excluding the whole builder wrapper would be wrong: elements the payload
     * did NOT translate should still be handled by Weglot. So diff the stored
     * document against the post's real one and exclude only the leaf elements
     * whose text actually differs.
     */
    private function prepare_builder_exclusions(int $post_id): void
    {
        $this->builder_notranslate_selectors = [];

        $templates = $this->builder_selector_templates();
        $selectors = [];

        // Driven by the same key list as sanitisation. Extending
        // nova_weglot_structured_meta_keys used to buy sanitisation with no
        // protection, which is worse than either alone: the payload lands, and
        // Weglot re-translates it.
        foreach ($this->storage_service->structured_meta_keys() as $meta_key) {
            if (! is_string($meta_key) || ! isset($templates[$meta_key]) || ! is_string($templates[$meta_key])) {
                // Sanitised but no known element wrapper for this builder, so
                // there is nothing to hand Weglot. Register one through
                // nova_weglot_builder_selector_templates.
                continue;
            }

            foreach ($this->changed_element_ids($post_id, $meta_key) as $element_id) {
                $selectors[] = sprintf($templates[$meta_key], $element_id);
            }
        }

        $this->builder_notranslate_selectors = array_values(array_unique($selectors));
    }

    /**
     * Structured meta key => CSS selector template for the wrapper the builder
     * renders around one element, with the element ID substituted for %s.
     *
     * A class selector avoids depending on attribute-selector support in Weglot's
     * parser; for Elementor, [data-id="<id>"] is the equivalent.
     *
     * Only Elementor ships here, because it is the only builder whose payload
     * reaches the page through post meta rather than post_content -- content that
     * arrives as post_content (Divi, WPBakery, Avada shortcodes, Gutenberg) is
     * already covered by the .nova-weglot-i18n wrapper filter_content emits.
     * Builders that keep their own meta tree can register a template here; the
     * walker expects Elementor's {id, settings, elements} shape.
     *
     * @return array<string,string>
     */
    private function builder_selector_templates(): array
    {
        /**
         * @param array<string,string> $templates
         */
        return (array) apply_filters(
            'nova_weglot_builder_selector_templates',
            ['_elementor_data' => '.elementor-element-%s']
        );
    }

    /**
     * IDs of the leaf elements whose text this payload rewrote, for one builder key.
     *
     * @return array<int,string>
     */
    private function changed_element_ids(int $post_id, string $meta_key): array
    {
        $stored = $this->payload['meta'][$meta_key] ?? null;

        if (! is_string($stored) || '' === trim($stored)) {
            return [];
        }

        $stored_tree = $this->decode_builder_document($stored);

        if (null === $stored_tree) {
            return [];
        }

        $original_tree = $this->decode_builder_document(get_post_meta($post_id, $meta_key, true));
        $original      = [];

        if (null !== $original_tree) {
            $this->index_builder_settings($original_tree, $original);
        }

        // With no readable original the index is empty, so every element counts
        // as changed and the whole stored document is treated as ours. That is
        // the intended fallback: leaving some source-language text in place is
        // the lesser fault, because the alternative re-translates our own copy.
        $changed = [];
        $this->collect_changed_builder_ids($stored_tree, $original, $changed);

        return $changed;
    }

    /**
     * @param mixed $raw
     *
     * @return array<int,mixed>|null
     */
    private function decode_builder_document($raw): ?array
    {
        if (! is_string($raw) || '' === trim($raw)) {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            // get_post_meta() returns UNslashed data, so this is not the normal
            // path -- it is a tolerance for a document that some other writer
            // stored with its escaping still doubled.
            $decoded = json_decode(wp_unslash($raw), true);
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<int,mixed>    $elements
     * @param array<string,mixed> $index
     */
    private function index_builder_settings(array $elements, array &$index): void
    {
        foreach ($elements as $element) {
            if (! is_array($element)) {
                continue;
            }

            if (isset($element['id']) && is_scalar($element['id'])) {
                $index[(string) $element['id']] = isset($element['settings']) && is_array($element['settings'])
                    ? $element['settings']
                    : [];
            }

            if (isset($element['elements']) && is_array($element['elements'])) {
                $this->index_builder_settings($element['elements'], $index);
            }
        }
    }

    /**
     * @param array<int,mixed>    $elements
     * @param array<string,mixed> $original
     * @param array<int,string>   $changed
     */
    private function collect_changed_builder_ids(array $elements, array $original, array &$changed): void
    {
        foreach ($elements as $element) {
            if (! is_array($element)) {
                continue;
            }

            $children = isset($element['elements']) && is_array($element['elements']) ? $element['elements'] : [];

            // Leaf elements only. Weglot honours an exclusion for the element AND
            // its entire subtree, so excluding a container would freeze every
            // nested widget this payload never touched -- they would be served in
            // the source language with no machine translation at all. One changed
            // setting on a section would silently drop the whole section out of
            // translation. Widgets are where the text lives; containers carry
            // layout.
            if ([] === $children && isset($element['id']) && is_scalar($element['id'])) {
                $id = (string) $element['id'];

                // The ID lands in a CSS selector, so only accept ones that
                // cannot break out of it. Elementor's are short hex strings.
                if (preg_match('/^[A-Za-z0-9_-]+$/', $id)) {
                    $settings = isset($element['settings']) && is_array($element['settings'])
                        ? $element['settings']
                        : [];

                    if (! array_key_exists($id, $original) || $this->settings_text_differs($original[$id], $settings)) {
                        $changed[] = $id;
                    }
                }
            }

            if ([] !== $children) {
                $this->collect_changed_builder_ids($children, $original, $changed);
            }
        }
    }

    /**
     * True when this payload rewrote any of an element's text.
     *
     * Compares only the string leaves, and only after normalising both sides. A
     * whole-settings comparison was over-broad in a way that mattered: the stored
     * document has been through wp_kses_post leaf by leaf (which rebuilds tag
     * attributes, drops disallowed ones and normalises entities) and the original
     * read from the DB has not, so a widget nobody translated compared unequal on
     * the round-trip artifact alone and got excluded from translation.
     *
     * @param mixed               $original
     * @param array<string,mixed> $settings
     */
    private function settings_text_differs($original, array $settings): bool
    {
        $original_text = is_array($original) ? $this->collect_text_leaves($original) : [];
        $stored_text   = $this->collect_text_leaves($settings);

        ksort($original_text);
        ksort($stored_text);

        // Fast path: identical raw text needs no normalisation, which keeps
        // wp_kses_post off the hot path for every untouched widget on the page.
        if ($original_text === $stored_text) {
            return false;
        }

        return array_map([$this, 'normalize_text_leaf'], $original_text)
            !== array_map([$this, 'normalize_text_leaf'], $stored_text);
    }

    /**
     * Non-empty string leaves of a settings tree, keyed by their path.
     *
     * Keyed by path so a moved value counts as a change, and restricted to
     * strings so a non-string setting (a size, a count, a toggle) cannot mark an
     * element as translated. Settings that are strings but not prose -- a colour,
     * a CSS unit -- are still compared, which is harmless: a translation payload
     * is the original with its text swapped, so they match anyway.
     *
     * @param array<mixed> $settings
     *
     * @return array<string,string>
     */
    private function collect_text_leaves(array $settings, string $prefix = ''): array
    {
        $leaves = [];

        foreach ($settings as $key => $value) {
            $path = '' === $prefix ? (string) $key : $prefix . '.' . $key;

            if (is_string($value)) {
                if ('' !== trim($value)) {
                    $leaves[$path] = $value;
                }
                continue;
            }

            if (is_array($value)) {
                $leaves += $this->collect_text_leaves($value, $path);
            }
        }

        return $leaves;
    }

    private function normalize_text_leaf(string $value): string
    {
        // wp_kses_post is what rewrote the stored side, so running it over both
        // makes the comparison like-for-like whichever side has been through it.
        $value = wp_kses_post($value);
        $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value);

        return is_string($value) ? trim($value) : '';
    }

    /**
     * True when this payload carries a page-builder document for the post, i.e.
     * the builder -- not the_content -- is what renders the page.
     *
     * Driven by the same key list as sanitisation and the notranslate
     * exclusions, so registering a builder through
     * nova_weglot_structured_meta_keys covers all three.
     */
    private function payload_carries_builder_document(): bool
    {
        foreach ($this->storage_service->structured_meta_keys() as $meta_key) {
            if (! is_string($meta_key) || '' === $meta_key) {
                continue;
            }

            $document = $this->get_meta_value($meta_key);

            if (is_string($document) && '' !== trim($document)) {
                return true;
            }
        }

        return false;
    }

    private function is_reserved_meta_key(string $meta_key): bool
    {
        // Two independent key spaces: the index deliberately sits outside
        // META_PREFIX so a locale payload can never collide with it. Both clauses
        // are load-bearing.
        return WGTAI_Storage_Service::META_INDEX === $meta_key
            || 0 === strpos($meta_key, WGTAI_Storage_Service::META_PREFIX);
    }

    private function has_translated_title(): bool
    {
        $title = $this->payload['title'] ?? '';

        return is_string($title) && '' !== trim($title);
    }

    private function has_yoast_title(): bool
    {
        $title = $this->get_meta_value('_yoast_wpseo_title');

        return is_string($title) && '' !== trim($title);
    }

    /**
     * True when an SEO plugin builds <title> itself.
     *
     * These plugins short-circuit the document title (Yoast and AIOSEO filter
     * wpseo_title / aioseo_title, Rank Math hooks pre_get_document_title), so
     * document_title_parts -- the filter this service uses -- never decides the
     * head. They also build it from $post->post_title without running the_title,
     * so the source-language title is what ships.
     */
    private function seo_plugin_owns_document_title(): bool
    {
        return defined('WPSEO_VERSION')
            || class_exists('WPSEO_Frontend')
            || defined('RANK_MATH_VERSION')
            || defined('AIOSEO_VERSION');
    }

    /**
     * Selectors for the theme's rendered post title.
     *
     * the_title feeds an <h1> this bridge does not own, so there is no wrapper to
     * key off. These cover WordPress core, block themes and the common classic
     * conventions; a theme that names it something else needs one line through
     * this filter, or the title is machine-translated in the body.
     *
     * @return array<int,string>
     */
    private function body_title_selectors(): array
    {
        /**
         * @param array<int,string> $selectors
         * @param string            $language
         * @param int               $post_id
         */
        return (array) apply_filters(
            'nova_weglot_title_selectors',
            ['.entry-title', '.wp-block-post-title', '.post-title'],
            $this->language,
            $this->post_id
        );
    }

    private function applies_to($post_id): bool
    {
        return null !== $this->payload && $this->post_id > 0 && (int) $post_id === $this->post_id;
    }

    private function is_rest_request(): bool
    {
        return defined('REST_REQUEST') && REST_REQUEST;
    }
}
