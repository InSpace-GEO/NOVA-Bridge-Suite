<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Per-locale content storage for Weglot sites.
 *
 * Weglot never creates a second post: /fr/<slug> renders the ORIGINAL post with
 * its language prefix stripped, then Weglot machine-translates the rendered HTML.
 * So instead of inserting a translated post (Polylang/WPML), this bridge stores
 * each locale's NOVA-authored payload on the source post and the render service
 * swaps it in when Weglot's current language matches.
 */
class WGTAI_Storage_Service
{
    public const META_PREFIX = '_nova_weglot_i18n_';
    public const META_INDEX  = '_nova_weglot_i18n_languages';

    private WGTAI_Language_Service $language_service;

    public function __construct(WGTAI_Language_Service $language_service)
    {
        $this->language_service = $language_service;
    }

    public function meta_key(string $internal_code): string
    {
        $internal_code = str_replace('-', '_', $this->language_service->normalize($internal_code));

        return self::META_PREFIX . $internal_code;
    }

    /**
     * Stores one locale payload against a source post.
     *
     * @param array<string,mixed> $translation
     *
     * @return array<string,mixed>|\WP_Error
     */
    public function save(int $source_post_id, array $translation)
    {
        $source_post = get_post($source_post_id);

        if (! $source_post) {
            return new \WP_Error('wgtai_missing_source', 'Source post not found.', ['status' => 404]);
        }

        $requested = isset($translation['language']) ? (string) $translation['language'] : '';

        if ('' === trim($requested)) {
            return new \WP_Error('wgtai_missing_language', 'Translation language is required.', ['status' => 400]);
        }

        if ($this->language_service->is_original_language($requested)) {
            return new \WP_Error(
                'wgtai_same_language',
                'Translation language matches the Weglot original language. Edit the source post directly instead.',
                ['status' => 400]
            );
        }

        $internal_code = $this->language_service->resolve_destination_code($requested);

        if ($internal_code === '') {
            return new \WP_Error(
                'wgtai_unknown_language',
                sprintf('Language "%s" is not a configured Weglot destination language.', $requested),
                [
                    'status'    => 400,
                    'available' => $this->language_service->get_destination_codes(),
                ]
            );
        }

        $existing = $this->get($source_post_id, $internal_code);
        $payload  = $this->build_payload($translation, $internal_code, $source_post_id, $existing);

        // update_post_meta() returns false both on failure AND when the stored value
        // is already identical (a retry inside the same second produces the same
        // updated_at), so the write is confirmed by reading it back instead.
        if ($existing !== $payload) {
            update_post_meta($source_post_id, $this->meta_key($internal_code), $payload);

            if (null === $this->get($source_post_id, $internal_code)) {
                return new \WP_Error(
                    'wgtai_store_failed',
                    'Could not store the translation payload.',
                    ['status' => 500]
                );
            }
        }

        $this->add_to_index($source_post_id, $internal_code);

        return [
            'source_post_id' => $source_post_id,
            'language'       => $internal_code,
            'stored'         => true,
            'created'        => null === $existing,
            'fields'         => array_values(array_keys($this->stored_fields($payload))),
            'url'            => $this->language_service->get_translated_url($source_post_id, $internal_code),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function get(int $post_id, string $internal_code): ?array
    {
        $internal_code = $this->language_service->normalize($internal_code);

        if ($post_id <= 0 || $internal_code === '') {
            return null;
        }

        $payload = get_post_meta($post_id, $this->meta_key($internal_code), true);

        return is_array($payload) && ! empty($payload) ? $payload : null;
    }

    public function delete(int $post_id, string $internal_code): bool
    {
        $internal_code = $this->language_service->normalize($internal_code);

        if ($post_id <= 0 || $internal_code === '') {
            return false;
        }

        $deleted = delete_post_meta($post_id, $this->meta_key($internal_code));

        $this->remove_from_index($post_id, $internal_code);

        return (bool) $deleted;
    }

    /**
     * Languages that currently have stored content for this post.
     *
     * @return array<int,string>
     */
    public function get_stored_languages(int $post_id): array
    {
        if ($post_id <= 0) {
            return [];
        }

        $index = get_post_meta($post_id, self::META_INDEX, true);

        if (! is_array($index)) {
            return [];
        }

        $languages = [];

        foreach ($index as $code) {
            if (! is_string($code)) {
                continue;
            }

            $code = $this->language_service->normalize($code);

            if ($code !== '' && ! in_array($code, $languages, true)) {
                $languages[] = $code;
            }
        }

        return $languages;
    }

    /**
     * Fields actually present in a stored payload (used for reporting).
     *
     * @param array<string,mixed> $payload
     *
     * @return array<string,mixed>
     */
    public function stored_fields(array $payload): array
    {
        $fields = [];

        foreach (['title', 'content', 'excerpt'] as $key) {
            if (isset($payload[$key]) && '' !== $payload[$key]) {
                $fields[$key] = $payload[$key];
            }
        }

        if (! empty($payload['meta']) && is_array($payload['meta'])) {
            $fields['meta'] = $payload['meta'];
        }

        return $fields;
    }

    /**
     * @param array<string,mixed>      $translation
     * @param array<string,mixed>|null $existing
     *
     * @return array<string,mixed>
     */
    private function build_payload(array $translation, string $internal_code, int $source_post_id, ?array $existing): array
    {
        $payload = [
            'language'       => $internal_code,
            'source_post_id' => $source_post_id,
            'updated_at'     => gmdate('c'),
        ];

        if (is_array($existing) && isset($existing['created_at'])) {
            $payload['created_at'] = (string) $existing['created_at'];
        } else {
            $payload['created_at'] = $payload['updated_at'];
        }

        if (isset($translation['title'])) {
            $payload['title'] = sanitize_text_field((string) $translation['title']);
        }

        if (isset($translation['content'])) {
            $payload['content'] = $this->sanitize_html((string) $translation['content']);
        }

        if (isset($translation['excerpt'])) {
            $payload['excerpt'] = $this->sanitize_html((string) $translation['excerpt']);
        }

        // Weglot cannot serve a translated slug that is not registered in its own
        // dashboard, so the value is kept for reporting but never used for routing.
        if (isset($translation['slug'])) {
            $payload['requested_slug'] = sanitize_title((string) $translation['slug']);
        }

        $meta = [];

        foreach (['meta', 'custom_fields'] as $meta_key) {
            if (! empty($translation[$meta_key]) && is_array($translation[$meta_key])) {
                $meta = array_merge($meta, $translation[$meta_key]);
            }
        }

        if (! empty($meta)) {
            $payload['meta'] = $this->sanitize_meta($meta);
        }

        return $payload;
    }

    private function sanitize_html(string $value): string
    {
        return current_user_can('unfiltered_html') ? $value : wp_kses_post($value);
    }

    /**
     * @param array<string,mixed> $meta
     *
     * @return array<string,mixed>
     */
    private function sanitize_meta(array $meta): array
    {
        $clean      = [];
        $structured = $this->structured_meta_keys();

        foreach ($meta as $key => $value) {
            if (! is_string($key) || '' === $key) {
                continue;
            }

            if (is_string($value) && in_array($key, $structured, true)) {
                $clean[$key] = $this->sanitize_structured_document($value);
                continue;
            }

            if (is_scalar($value) || null === $value) {
                $clean[$key] = is_string($value) ? $this->sanitize_html($value) : $value;
                continue;
            }

            if (is_array($value)) {
                // Arrays used to be stored verbatim, which let a caller without
                // unfiltered_html smuggle markup past kses in a nested value.
                $clean[$key] = $this->sanitize_deep($value);
            }
        }

        return $clean;
    }

    /**
     * Meta keys whose value is a structured document, not display HTML.
     *
     * A page builder stores its tree as a JSON string, and running that blob
     * through wp_kses_post corrupts it: kses rebuilds tag attributes with double
     * quotes, so an escaped \" inside the JSON comes back as a raw " and the
     * string terminates early. Sanitise the text leaves inside the document
     * instead, then re-encode.
     *
     * @return array<int,string>
     */
    private function structured_meta_keys(): array
    {
        /**
         * @param array<int,string> $keys
         */
        return (array) apply_filters('nova_weglot_structured_meta_keys', ['_elementor_data']);
    }

    private function sanitize_structured_document(string $value): string
    {
        $decoded = json_decode($value, true);

        if (! is_array($decoded)) {
            // Not the structured document we were promised: treat it as ordinary
            // HTML rather than storing it unchecked.
            return $this->sanitize_html($value);
        }

        $encoded = wp_json_encode($this->sanitize_deep($decoded));

        return is_string($encoded) ? $encoded : '';
    }

    /**
     * Sanitises every string leaf, leaving structure and keys intact.
     *
     * Keys are deliberately untouched: they are a builder's setting names, and
     * rewriting one silently detaches the value from the element that reads it.
     *
     * @param array<mixed> $value
     *
     * @return array<mixed>
     */
    private function sanitize_deep(array $value): array
    {
        $clean = [];

        foreach ($value as $key => $item) {
            if (is_string($item)) {
                $clean[$key] = $this->sanitize_html($item);
                continue;
            }

            if (is_array($item)) {
                $clean[$key] = $this->sanitize_deep($item);
                continue;
            }

            if (is_scalar($item) || null === $item) {
                $clean[$key] = $item;
            }
        }

        return $clean;
    }

    private function add_to_index(int $post_id, string $internal_code): void
    {
        $languages = $this->get_stored_languages($post_id);

        if (! in_array($internal_code, $languages, true)) {
            $languages[] = $internal_code;
        }

        sort($languages);

        update_post_meta($post_id, self::META_INDEX, $languages);
    }

    private function remove_from_index(int $post_id, string $internal_code): void
    {
        $languages = array_values(
            array_filter(
                $this->get_stored_languages($post_id),
                static function ($code) use ($internal_code) {
                    return $code !== $internal_code;
                }
            )
        );

        if (empty($languages)) {
            delete_post_meta($post_id, self::META_INDEX);

            return;
        }

        update_post_meta($post_id, self::META_INDEX, $languages);
    }
}
