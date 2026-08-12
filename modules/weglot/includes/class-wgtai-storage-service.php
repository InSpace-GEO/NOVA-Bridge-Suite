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

    // Deliberately OUTSIDE META_PREFIX. While the index lived under the prefix,
    // meta_key('languages') resolved to exactly this key, so a DELETE on
    // .../translations/languages wiped the whole stored-language index and left
    // every payload orphaned. The two key spaces are now disjoint, pinned by
    // "the index key sits outside the payload prefix" in tests/weglot-unit.php.
    public const META_INDEX  = '_nova_weglot_languages';

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
            // wp_slash() is mandatory: update_metadata() runs wp_unslash() over the
            // value before writing, which strips one level of backslashes from every
            // string in the payload. That silently destroys the structured documents
            // built by wp_json_encode() -- \" terminates the JSON string early and
            // \uXXXX loses its escape -- so the stored blob no longer decodes. Same
            // reason as modules/elementor/includes/class-elementor-service.php:503.
            update_post_meta($source_post_id, $this->meta_key($internal_code), wp_slash($payload));

            // Compare the readback with what was requested, not merely against null:
            // when a payload already exists, a failed write leaves the OLD payload in
            // place and a null check would report stored:true for changes that were
            // never saved. addslashes/stripslashes round-trips exactly, so the stored
            // value is identical to $payload whenever the write landed.
            if ($this->get($source_post_id, $internal_code) !== $payload) {
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
            'fields'         => array_keys($this->stored_fields($payload)),
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
        // Update semantics, matching the Polylang bridge (where wp_update_post
        // leaves an omitted field intact) so the same NOVA request means the same
        // thing on both providers:
        //
        //   field omitted  -> whatever is already stored is kept
        //   field = null   -> that field is cleared for this locale
        //   field = value  -> replaced
        //
        // Building from scratch instead meant a follow-up POST that carried only
        // the field it was fixing silently erased the locale's content, excerpt
        // and entire meta map, while still answering 200 stored:true.
        $payload = is_array($existing) ? $existing : [];

        $payload['language']       = $internal_code;
        $payload['source_post_id'] = $source_post_id;
        $payload['updated_at']     = gmdate('c');

        if (! isset($payload['created_at'])) {
            $payload['created_at'] = $payload['updated_at'];
        } else {
            $payload['created_at'] = (string) $payload['created_at'];
        }

        $this->apply_field($payload, $translation, 'title', 'title', function ($value) {
            return sanitize_text_field((string) $value);
        });

        $this->apply_field($payload, $translation, 'content', 'content', function ($value) {
            return $this->sanitize_html((string) $value);
        });

        $this->apply_field($payload, $translation, 'excerpt', 'excerpt', function ($value) {
            return $this->sanitize_html((string) $value);
        });

        // Weglot cannot serve a translated slug that is not registered in its own
        // dashboard, so the value is kept for reporting but never used for routing.
        $this->apply_field($payload, $translation, 'slug', 'requested_slug', function ($value) {
            return sanitize_title((string) $value);
        });

        $payload['meta'] = $this->merge_meta($payload, $translation);

        if ([] === $payload['meta']) {
            unset($payload['meta']);
        }

        return $payload;
    }

    /**
     * Applies one caller-supplied field under the omit/null/value contract above.
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $translation
     */
    private function apply_field(array &$payload, array $translation, string $input_key, string $payload_key, callable $sanitize): void
    {
        // array_key_exists, not isset: isset() is false for null, which is how a
        // caller spells "clear this field".
        if (! array_key_exists($input_key, $translation)) {
            return;
        }

        if (null === $translation[$input_key]) {
            unset($payload[$payload_key]);

            return;
        }

        $payload[$payload_key] = $sanitize($translation[$input_key]);
    }

    /**
     * Merges the request's meta keys over whatever the locale already stores.
     *
     * Per-key, like update_post_meta: a POST carrying one meta key must not drop
     * the others. A key sent as null is removed, which is the only way to clear
     * one (an empty meta object means "no meta keys in this request", not
     * "delete every stored key").
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $translation
     *
     * @return array<string,mixed>
     */
    private function merge_meta(array $payload, array $translation): array
    {
        $incoming = [];
        $supplied = false;

        foreach (['meta', 'custom_fields'] as $meta_key) {
            if (array_key_exists($meta_key, $translation) && is_array($translation[$meta_key])) {
                $incoming = array_merge($incoming, $translation[$meta_key]);
                $supplied = true;
            }
        }

        $stored = isset($payload['meta']) && is_array($payload['meta']) ? $payload['meta'] : [];

        if (! $supplied) {
            return $stored;
        }

        // Only the incoming keys are sanitised: the stored ones already were, and
        // re-running wp_kses_post over a whole builder document on every partial
        // update is pure cost.
        $merged = array_merge($stored, $this->sanitize_meta($incoming));

        return array_filter(
            $merged,
            static function ($value) {
                return null !== $value;
            }
        );
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

            if (in_array($key, $structured, true) && (is_string($value) || is_array($value))) {
                // A JSON request body can carry the document either way. An array
                // used to fall through to sanitize_deep and be stored as a PHP
                // array, which the builder then reads back with json_decode() and
                // cannot use, and which prepare_builder_exclusions() skips at its
                // own is_string() check -- so the value was stored in a shape only
                // half the pipeline understands. Normalise to the JSON string the
                // builder expects.
                $clean[$key] = is_array($value)
                    ? $this->encode_structured_document($this->sanitize_deep($value))
                    : $this->sanitize_structured_document($value);
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
     * Public because the render service drives its notranslate exclusions off the
     * same list: a key that gets structured sanitisation but no builder
     * protection is worse than either alone.
     *
     * @return array<int,string>
     */
    public function structured_meta_keys(): array
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

        return $this->encode_structured_document($this->sanitize_deep($decoded));
    }

    /**
     * @param array<mixed> $document
     */
    private function encode_structured_document(array $document): string
    {
        $encoded = wp_json_encode($document);

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

        update_post_meta($post_id, self::META_INDEX, wp_slash($languages));
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

        update_post_meta($post_id, self::META_INDEX, wp_slash($languages));
    }
}
