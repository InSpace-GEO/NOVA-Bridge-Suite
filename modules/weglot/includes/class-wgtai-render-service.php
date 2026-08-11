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

        return $single ? $translated : [$translated];
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

        $selectors = ['.nova-weglot-i18n'];

        if ($this->has_translated_title()) {
            $selectors[] = 'title';
            $selectors[] = 'meta[property="og:title"]';
            $selectors[] = 'meta[name="twitter:title"]';
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
        $selectors = apply_filters('nova_weglot_notranslate_selectors', $selectors, $this->language, $this->post_id);

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

    private function is_reserved_meta_key(string $meta_key): bool
    {
        return WGTAI_Storage_Service::META_INDEX === $meta_key
            || 0 === strpos($meta_key, WGTAI_Storage_Service::META_PREFIX);
    }

    private function has_translated_title(): bool
    {
        $title = $this->payload['title'] ?? '';

        return is_string($title) && '' !== trim($title);
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
