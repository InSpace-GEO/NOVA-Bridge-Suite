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
 *
 * save() and save_term() (and their get()/delete()/get_stored_languages() peers)
 * are thin entry points that differ only in which WGTAI_Storage_Entity descriptor
 * they thread through build_payload() and the persistence helpers below -- one
 * sanitiser, one set of persistence primitives, no subclass. See
 * WGTAI_Storage_Entity for why a subclass was rejected.
 */
class WGTAI_Storage_Service
{
    public const META_PREFIX = '_nova_weglot_i18n_';

    // Deliberately OUTSIDE META_PREFIX. While the index lived under the prefix,
    // meta_key('languages') resolved to exactly this key, so a DELETE on
    // .../translations/languages wiped the whole stored-language index and left
    // every payload orphaned. The two key spaces are now disjoint, pinned by
    // "the index key sits outside the payload prefix" in tests/weglot-unit.php.
    //
    // Reused as-is for terms: wp_termmeta is a different table from wp_postmeta,
    // so a source_post_id and a source_term_id with the same numeric value never
    // collide even though both use this exact key string. A second, term-only
    // prefix would fork is_reserved_meta_key() (class-wgtai-render-service.php)
    // into two definitions that will drift.
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
        return $this->save_for($this->post_entity(), $source_post_id, $translation);
    }

    /**
     * Stores one locale payload against a source term.
     *
     * The taxonomy is deliberately not a parameter: term_id is globally unique
     * in WordPress, so storage needs only the id. Validating that the id belongs
     * to the taxonomy the caller expects is the REST controller's job, not
     * storage's -- that avoids per-request mutable taxonomy state on this
     * service object.
     *
     * @param array<string,mixed> $translation
     *
     * @return array<string,mixed>|\WP_Error
     */
    public function save_term(int $source_term_id, array $translation)
    {
        return $this->save_for($this->term_entity(), $source_term_id, $translation);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function get(int $post_id, string $internal_code): ?array
    {
        return $this->get_for($this->post_entity(), $post_id, $internal_code);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function get_term(int $term_id, string $internal_code): ?array
    {
        return $this->get_for($this->term_entity(), $term_id, $internal_code);
    }

    public function delete(int $post_id, string $internal_code): bool
    {
        return $this->delete_for($this->post_entity(), $post_id, $internal_code);
    }

    public function delete_term(int $term_id, string $internal_code): bool
    {
        return $this->delete_for($this->term_entity(), $term_id, $internal_code);
    }

    /**
     * Languages that currently have stored content for this post.
     *
     * @return array<int,string>
     */
    public function get_stored_languages(int $post_id): array
    {
        return $this->get_stored_languages_for($this->post_entity(), $post_id);
    }

    /**
     * Languages that currently have stored content for this term.
     *
     * @return array<int,string>
     */
    public function get_term_stored_languages(int $term_id): array
    {
        return $this->get_stored_languages_for($this->term_entity(), $term_id);
    }

    /**
     * Fields actually present in a stored payload (used for reporting).
     *
     * Driven by the entity's field_map rather than a hardcoded list: the map's
     * 'slug' entries (requested_slug) are always excluded here because a
     * requested slug is recorded and returned but never routed, so reporting it
     * in fields[] would claim a locale controls its own URL when it does not.
     *
     * @param array<string,mixed> $payload
     *
     * @return array<string,mixed>
     */
    public function stored_fields(array $payload, WGTAI_Storage_Entity $entity): array
    {
        $fields = [];

        foreach ($entity->field_map() as $definition) {
            [$payload_key, $type] = $definition;

            if ('slug' === $type) {
                continue;
            }

            if (isset($payload[$payload_key]) && '' !== $payload[$payload_key]) {
                $fields[$payload_key] = $payload[$payload_key];
            }
        }

        if (! empty($payload['meta']) && is_array($payload['meta'])) {
            $fields['meta'] = $payload['meta'];
        }

        return $fields;
    }

    /**
     * @param array<string,mixed> $translation
     *
     * @return array<string,mixed>|\WP_Error
     */
    private function save_for(WGTAI_Storage_Entity $entity, int $id, array $translation)
    {
        if (! $entity->exists($id)) {
            return $entity->missing_error();
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

        $existing = $this->get_for($entity, $id, $internal_code);
        $payload  = $this->build_payload($entity, $translation, $internal_code, $id, $existing);

        // A payload we cannot sanitise without destroying it must not be stored:
        // a builder document that lands unusable renders as nothing, and the
        // theme then prints the payload's raw `content` HTML instead of the
        // page template. Reporting the failure is what lets NOVA retry.
        if (is_wp_error($payload)) {
            return $payload;
        }

        // update_metadata() returns false both on failure AND when the stored
        // value is already identical (a retry inside the same second produces
        // the same updated_at), so the write is confirmed by reading it back
        // instead.
        if ($existing !== $payload) {
            $entity->write_meta($id, $this->meta_key($internal_code), $payload);

            // Compare the readback with what was requested, not merely against
            // null: when a payload already exists, a failed write leaves the OLD
            // payload in place and a null check would report stored:true for
            // changes that were never saved.
            if ($this->get_for($entity, $id, $internal_code) !== $payload) {
                return new \WP_Error(
                    'wgtai_store_failed',
                    'Could not store the translation payload.',
                    ['status' => 500]
                );
            }
        }

        $this->add_to_index($entity, $id, $internal_code);

        return [
            $entity->id_key() => $id,
            'language'      => $internal_code,
            'stored'        => true,
            'created'       => null === $existing,
            'fields'        => array_keys($this->stored_fields($payload, $entity)),
            'url'           => $entity->url($id, $internal_code),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function get_for(WGTAI_Storage_Entity $entity, int $id, string $internal_code): ?array
    {
        $internal_code = $this->language_service->normalize($internal_code);

        if ($id <= 0 || $internal_code === '') {
            return null;
        }

        $payload = $entity->read_meta($id, $this->meta_key($internal_code));

        return is_array($payload) && ! empty($payload) ? $payload : null;
    }

    private function delete_for(WGTAI_Storage_Entity $entity, int $id, string $internal_code): bool
    {
        $internal_code = $this->language_service->normalize($internal_code);

        if ($id <= 0 || $internal_code === '') {
            return false;
        }

        $deleted = $entity->delete_meta($id, $this->meta_key($internal_code));

        $this->remove_from_index($entity, $id, $internal_code);

        return $deleted;
    }

    /**
     * @return array<int,string>
     */
    private function get_stored_languages_for(WGTAI_Storage_Entity $entity, int $id): array
    {
        if ($id <= 0) {
            return [];
        }

        $index = $entity->read_meta($id, self::META_INDEX);

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
     * @param array<string,mixed>      $translation
     * @param array<string,mixed>|null $existing
     *
     * @return array<string,mixed>|\WP_Error
     */
    private function build_payload(WGTAI_Storage_Entity $entity, array $translation, string $internal_code, int $id, ?array $existing)
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

        $payload['language']      = $internal_code;
        $payload[$entity->id_key()] = $id;
        $payload['updated_at']    = gmdate('c');

        if (! isset($payload['created_at'])) {
            $payload['created_at'] = $payload['updated_at'];
        } else {
            $payload['created_at'] = (string) $payload['created_at'];
        }

        foreach ($entity->field_map() as $input_key => $definition) {
            [$payload_key, $type] = $definition;

            $this->apply_field($payload, $translation, $input_key, $payload_key, function ($value) use ($type) {
                return $this->sanitize_by_type($type, $value);
            });
        }

        $meta = $this->merge_meta($payload, $translation, $entity);

        if (is_wp_error($meta)) {
            return $meta;
        }

        $payload['meta'] = $meta;

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
     * Dispatches to the sanitiser for one field_map() entry's declared type.
     *
     * @param mixed $value
     */
    private function sanitize_by_type(string $type, $value): string
    {
        if ('html' === $type) {
            return $this->sanitize_html((string) $value);
        }

        if ('slug' === $type) {
            return sanitize_title((string) $value);
        }

        return sanitize_text_field((string) $value);
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
     * @return array<string,mixed>|\WP_Error
     */
    private function merge_meta(array $payload, array $translation, WGTAI_Storage_Entity $entity)
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
        $sanitized = $this->sanitize_meta($incoming, $entity);

        if (is_wp_error($sanitized)) {
            return $sanitized;
        }

        $merged = array_merge($stored, $sanitized);

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
     * @return array<string,mixed>|\WP_Error
     */
    private function sanitize_meta(array $meta, WGTAI_Storage_Entity $entity)
    {
        $clean      = [];
        $structured = $this->structured_meta_keys();

        foreach ($meta as $key => $value) {
            if (! is_string($key) || '' === $key) {
                continue;
            }

            $is_structured = in_array($key, $structured, true);

            if ($is_structured && ! in_array($key, $entity->structured_meta_keys(), true)) {
                // Accepted structured-document keys are per entity type. The term
                // descriptor declares none ("no builder document renders on a
                // taxonomy archive"), so a key that would be treated as a JSON
                // document on a post must be refused outright here, rather than
                // handed either sanitiser: wp_kses_post would corrupt the JSON
                // blob, and storing it as display HTML buys sanitisation with no
                // builder protection, which prepare_builder_exclusions() in the
                // render service was written specifically to avoid being worse
                // than either alone.
                return new \WP_Error(
                    'wgtai_structured_meta_not_supported',
                    sprintf(
                        'Meta key "%s" is registered as a page-builder document and is not supported on this entity.',
                        $key
                    ),
                    [
                        'status'   => 400,
                        'meta_key' => $key,
                    ]
                );
            }

            if ($is_structured && (is_string($value) || is_array($value))) {
                // A JSON request body can carry the document either way. An array
                // used to fall through to sanitize_deep and be stored as a PHP
                // array, which the builder then reads back with json_decode() and
                // cannot use, and which prepare_builder_exclusions() skips at its
                // own is_string() check -- so the value was stored in a shape only
                // half the pipeline understands. Normalise to the JSON string the
                // builder expects.
                $document = is_array($value)
                    ? $this->encode_structured_document($this->sanitize_deep($value), $key)
                    : $this->sanitize_structured_document($value, $key);

                if (is_wp_error($document)) {
                    return $document;
                }

                $clean[$key] = $document;
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
     * This is the global, filterable list of keys the SITE treats as structured
     * documents -- what a post entity's descriptor uses verbatim as its own
     * structured_meta_keys. It stays public and untouched by the entity
     * refactor because the render service calls it directly (its own
     * notranslate exclusions and builder-cache handling are keyed off the same
     * list), and because a term's descriptor needs this exact list to know
     * which incoming keys to REFUSE rather than sanitise -- see sanitize_meta().
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

    /**
     * @return string|\WP_Error
     */
    private function sanitize_structured_document(string $value, string $meta_key)
    {
        $decoded = json_decode($value, true);

        if (! is_array($decoded)) {
            // Not the structured document we were promised. This used to fall
            // back to sanitize_html() and store the value as display HTML, which
            // is the worst of both worlds: the write answers 200 stored:true, but
            // the builder reads the key back with json_decode(), gets nothing, and
            // renders an empty document -- so the theme prints the payload's raw
            // `content` instead of the page template. Fail the write instead, so
            // the caller sees it and the locale keeps its last good payload.
            return new \WP_Error(
                'wgtai_invalid_structured_meta',
                sprintf(
                    'Meta key "%s" is registered as a page-builder document but its value is not decodable JSON (%s). Nothing was stored for this locale.',
                    $meta_key,
                    json_last_error_msg()
                ),
                [
                    'status'   => 400,
                    'meta_key' => $meta_key,
                ]
            );
        }

        return $this->encode_structured_document($this->sanitize_deep($decoded), $meta_key);
    }

    /**
     * @param array<mixed> $document
     *
     * @return string|\WP_Error
     */
    private function encode_structured_document(array $document, string $meta_key)
    {
        $encoded = wp_json_encode($document);

        // wp_json_encode() returns false when the data cannot be encoded at all
        // (invalid UTF-8 that survives its sanity check, or a tree deeper than
        // the JSON depth limit). Returning '' here stored an empty document, with
        // the same silent outcome as the branch above.
        if (! is_string($encoded) || '' === trim($encoded)) {
            return new \WP_Error(
                'wgtai_structured_encode_failed',
                sprintf(
                    'Could not re-encode meta key "%s" after sanitising it (%s). Nothing was stored for this locale.',
                    $meta_key,
                    json_last_error_msg()
                ),
                [
                    'status'   => 500,
                    'meta_key' => $meta_key,
                ]
            );
        }

        return $encoded;
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

    private function add_to_index(WGTAI_Storage_Entity $entity, int $id, string $internal_code): void
    {
        $languages = $this->get_stored_languages_for($entity, $id);

        if (! in_array($internal_code, $languages, true)) {
            $languages[] = $internal_code;
        }

        sort($languages);

        $entity->write_meta($id, self::META_INDEX, $languages);
    }

    private function remove_from_index(WGTAI_Storage_Entity $entity, int $id, string $internal_code): void
    {
        $languages = array_values(
            array_filter(
                $this->get_stored_languages_for($entity, $id),
                static function ($code) use ($internal_code) {
                    return $code !== $internal_code;
                }
            )
        );

        if (empty($languages)) {
            $entity->delete_meta($id, self::META_INDEX);

            return;
        }

        $entity->write_meta($id, self::META_INDEX, $languages);
    }

    /**
     * The post descriptor. Every seam below returns exactly today's behaviour:
     * see the seam table in the Weglot terms implementation plan for the line
     * numbers this replaced.
     */
    private function post_entity(): WGTAI_Storage_Entity
    {
        $language_service = $this->language_service;

        return new WGTAI_Storage_Entity(
            'source_post_id',
            [
                'title'   => ['title', 'text'],
                'content' => ['content', 'html'],
                'excerpt' => ['excerpt', 'html'],
                // Weglot cannot serve a translated slug that is not registered in
                // its own dashboard, so the value is kept for reporting but never
                // used for routing.
                'slug'    => ['requested_slug', 'slug'],
            ],
            $this->structured_meta_keys(),
            static function (int $id): bool {
                return (bool) get_post($id);
            },
            static function (): \WP_Error {
                return new \WP_Error('wgtai_missing_source', 'Source post not found.', ['status' => 404]);
            },
            static function (int $id, string $key) {
                return get_post_meta($id, $key, true);
            },
            static function (int $id, string $key, $value): void {
                // wp_slash() is mandatory: update_metadata() runs wp_unslash() over
                // the value before writing, which strips one level of backslashes
                // from every string in the payload. That silently destroys the
                // structured documents built by wp_json_encode() -- \" terminates
                // the JSON string early and \uXXXX loses its escape -- so the
                // stored blob no longer decodes. Same reason as
                // modules/elementor/includes/class-elementor-service.php:503.
                update_post_meta($id, $key, wp_slash($value));
            },
            static function (int $id, string $key): bool {
                return (bool) delete_post_meta($id, $key);
            },
            function (int $id, string $code) use ($language_service): string {
                return $language_service->get_translated_url($id, $code);
            }
        );
    }

    /**
     * The term descriptor. structured_meta_keys is deliberately empty: see
     * sanitize_meta() for why an empty list here, not the post's list, is what
     * makes a page-builder meta key on a term a hard rejection instead of quiet
     * sanitisation with no notranslate protection.
     */
    private function term_entity(): WGTAI_Storage_Entity
    {
        $language_service = $this->language_service;

        return new WGTAI_Storage_Entity(
            'source_term_id',
            [
                'name'        => ['name', 'text'],
                'description' => ['description', 'html'],
                // Same "recorded, reported, never routed" contract as the post
                // slug: Weglot cannot re-route a taxonomy archive to a translated
                // slug it does not know about.
                'slug'        => ['requested_slug', 'slug'],
            ],
            [],
            static function (int $id): bool {
                $term = get_term($id);

                return ! is_wp_error($term) && $term instanceof \WP_Term;
            },
            static function (): \WP_Error {
                return new \WP_Error('wgtai_missing_term', 'Source term not found.', ['status' => 404]);
            },
            static function (int $id, string $key) {
                return get_term_meta($id, $key, true);
            },
            static function (int $id, string $key, $value): void {
                update_term_meta($id, $key, wp_slash($value));
            },
            static function (int $id, string $key): bool {
                return (bool) delete_term_meta($id, $key);
            },
            function (int $id, string $code) use ($language_service): string {
                $term = get_term($id);

                if (is_wp_error($term) || ! ($term instanceof \WP_Term)) {
                    return '';
                }

                $link = get_term_link($term);

                if (is_wp_error($link) || ! is_string($link)) {
                    return '';
                }

                // Entity-agnostic on purpose: get_translated_url_for() takes a
                // plain URL, so the same Weglot URL-object machinery that resolves
                // a post's permalink resolves a term archive's permalink too, with
                // no term-specific code inside the language service.
                return $language_service->get_translated_url_for($link, $code);
            }
        );
    }
}
