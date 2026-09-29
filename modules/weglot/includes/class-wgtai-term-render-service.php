<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if (! defined('ABSPATH')) {
    exit;
}

/** Serves NOVA's stored copy on the source term's Weglot archive URL. */
class WGTAI_Term_Render_Service
{
    private WGTAI_Language_Service $language_service;
    private WGTAI_Storage_Service $storage_service;
    private ?array $payload = null;
    private int $term_id = 0;
    private string $taxonomy = '';
    private string $language = '';

    public function __construct(WGTAI_Language_Service $language_service, WGTAI_Storage_Service $storage_service)
    {
        $this->language_service = $language_service;
        $this->storage_service  = $storage_service;
    }

    public function hooks(): void
    {
        add_action('wp', [$this, 'resolve_payload']);
    }

    public function resolve_payload(): void
    {
        if (is_admin() || is_feed() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }

        if (! (is_tax() || is_category() || is_tag())) {
            return;
        }

        $term = get_queried_object();

        if (! ($term instanceof \WP_Term) || (int) $term->term_id <= 0) {
            return;
        }

        $language = $this->language_service->get_current_code();

        if ('' === $language || $this->language_service->is_original_language($language)) {
            return;
        }

        $payload = $this->storage_service->get_term((int) $term->term_id, $language);

        if (null === $payload) {
            $resolved = $this->language_service->resolve_destination_code($language);

            if ('' !== $resolved && $resolved !== $language) {
                $payload  = $this->storage_service->get_term((int) $term->term_id, $resolved);
                $language = $resolved;
            }
        }

        if (null === $payload) {
            return;
        }

        $this->payload  = $payload;
        $this->term_id  = (int) $term->term_id;
        $this->taxonomy = (string) $term->taxonomy;
        $this->language = $language;

        // The queried object can be cached before `wp`, so filter both the
        // template helpers and later get_term() calls. No source term is edited.
        add_filter('single_term_title', [$this, 'filter_title']);
        add_filter('woocommerce_page_title', [$this, 'filter_title']);
        add_filter('get_term', [$this, 'filter_term'], 10, 2);
        add_filter('get_the_archive_description', [$this, 'filter_archive_description']);
        add_filter('woocommerce_taxonomy_archive_description_raw', [$this, 'filter_woocommerce_description'], 10, 2);
        add_filter('get_term_metadata', [$this, 'filter_term_metadata'], 10, 4);
        add_filter('document_title_parts', [$this, 'filter_document_title_parts']);
        add_filter('weglot_exclude_blocks', [$this, 'filter_exclude_blocks']);

        if (null !== $this->meta_value('_yoast_wpseo_title')) {
            add_filter('wpseo_title', [$this, 'filter_yoast_title'], 20);
            add_filter('wpseo_opengraph_title', [$this, 'filter_yoast_title'], 20);
            add_filter('wpseo_twitter_title', [$this, 'filter_yoast_title'], 20);
        }

        if (null !== $this->meta_value('_yoast_wpseo_metadesc')) {
            add_filter('wpseo_metadesc', [$this, 'filter_yoast_metadesc'], 20);
            add_filter('wpseo_opengraph_desc', [$this, 'filter_yoast_metadesc'], 20);
            add_filter('wpseo_twitter_description', [$this, 'filter_yoast_metadesc'], 20);
        }
    }

    public function filter_title($title)
    {
        $translated = $this->payload['name'] ?? null;

        return is_string($translated) && '' !== trim($translated) ? $translated : $title;
    }

    public function filter_term($term, $taxonomy)
    {
        if (! ($term instanceof \WP_Term) || ! $this->applies_to((int) $term->term_id, (string) $taxonomy)) {
            return $term;
        }

        // WordPress can reuse a cached term object. Clone it before replacing
        // display fields so a later source-language read cannot inherit them.
        $translated = clone $term;

        if (isset($this->payload['name']) && is_string($this->payload['name']) && '' !== trim($this->payload['name'])) {
            $translated->name = $this->payload['name'];
        }

        if (array_key_exists('description', $this->payload) && is_string($this->payload['description'])) {
            $translated->description = $this->payload['description'];
        }

        return $translated;
    }

    public function filter_archive_description($description)
    {
        if (! array_key_exists('description', $this->payload) || ! is_string($this->payload['description'])) {
            return $description;
        }

        return $this->wrap_html($this->payload['description'], 'nova-weglot-term-description');
    }

    public function filter_woocommerce_description($description, $term)
    {
        if (! ($term instanceof \WP_Term) || ! $this->applies_to((int) $term->term_id, (string) $term->taxonomy)) {
            return $description;
        }

        return array_key_exists('description', $this->payload) && is_string($this->payload['description'])
            ? $this->payload['description']
            : $description;
    }

    public function filter_term_metadata($value, $object_id, $meta_key, $single)
    {
        if (! is_string($meta_key) || '' === $meta_key || $this->is_reserved_meta_key($meta_key)) {
            return $value;
        }

        if (! $this->applies_to((int) $object_id, $this->taxonomy)) {
            return $value;
        }

        $translated = $this->meta_value($meta_key);

        if (null === $translated) {
            return $value;
        }

        // The category template reads this field as HTML. A wrapper prevents
        // Weglot from translating NOVA's approved bottom copy a second time.
        if ('content_below_products' === $meta_key && is_string($translated)) {
            $translated = $this->wrap_html($translated, 'nova-weglot-term-meta');
        }

        // WordPress unwraps the first entry itself for single-value reads.
        return [$translated];
    }

    public function filter_document_title_parts($parts)
    {
        if (! is_array($parts)) {
            return $parts;
        }

        $translated = $this->filter_title('');

        if ('' !== $translated) {
            $parts['title'] = $translated;
        }

        return $parts;
    }

    public function filter_yoast_title($title)
    {
        $translated = $this->meta_value('_yoast_wpseo_title');

        return is_string($translated) && '' !== trim($translated) ? $translated : $title;
    }

    public function filter_yoast_metadesc($description)
    {
        $translated = $this->meta_value('_yoast_wpseo_metadesc');

        return is_string($translated) && '' !== trim($translated) ? $translated : $description;
    }

    public function filter_exclude_blocks($blocks)
    {
        if (! is_array($blocks) || null === $this->payload) {
            return $blocks;
        }

        $selectors = ['.nova-weglot-term-description', '.nova-weglot-term-meta'];

        if (isset($this->payload['name']) && '' !== trim((string) $this->payload['name'])) {
            $selectors = array_merge($selectors, [
                '.woocommerce-products-header__title', '.page-title', '.wp-block-query-title',
                '.wp-block-archive-title',
            ]);

            if (null === $this->meta_value('_yoast_wpseo_title') && ! $this->seo_plugin_owns_document_title()) {
                $selectors[] = 'title';
            }
        }

        if (array_key_exists('description', $this->payload)) {
            $selectors = array_merge($selectors, ['.term-description', '.taxonomy-description', '.archive-description']);
        }

        if (null !== $this->meta_value('content_below_products')) {
            $selectors[] = '.wc-content-below-products';
        }

        if (null !== $this->meta_value('_yoast_wpseo_title')) {
            $selectors = array_merge($selectors, ['title', 'meta[property="og:title"]', 'meta[name="twitter:title"]']);
        }

        if (null !== $this->meta_value('_yoast_wpseo_metadesc')) {
            $selectors = array_merge($selectors, [
                'meta[name="description"]', 'meta[property="og:description"]', 'meta[name="twitter:description"]',
            ]);
        }

        $selectors = (array) apply_filters('nova_weglot_term_notranslate_selectors', $selectors, $this->language, $this->term_id);

        foreach ($selectors as $selector) {
            if (is_string($selector) && '' !== $selector && ! in_array($selector, $blocks, true)) {
                $blocks[] = $selector;
            }
        }

        return $blocks;
    }

    private function applies_to(int $term_id, string $taxonomy): bool
    {
        return null !== $this->payload && $term_id === $this->term_id && $taxonomy === $this->taxonomy;
    }

    private function meta_value(string $meta_key)
    {
        if (null === $this->payload || ! isset($this->payload['meta']) || ! is_array($this->payload['meta'])) {
            return null;
        }

        return array_key_exists($meta_key, $this->payload['meta']) ? $this->payload['meta'][$meta_key] : null;
    }

    private function is_reserved_meta_key(string $meta_key): bool
    {
        return WGTAI_Storage_Service::META_INDEX === $meta_key
            || 0 === strpos($meta_key, WGTAI_Storage_Service::META_PREFIX);
    }

    private function wrap_html(string $html, string $class): string
    {
        if ('' === trim($html)) {
            return '';
        }

        return '<div class="' . $class . '" data-wg-notranslate>' . $html . '</div>';
    }

    private function seo_plugin_owns_document_title(): bool
    {
        return defined('WPSEO_VERSION') || class_exists('WPSEO_Frontend')
            || defined('RANK_MATH_VERSION') || defined('AIOSEO_VERSION');
    }
}
