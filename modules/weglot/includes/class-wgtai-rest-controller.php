<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if (! defined('ABSPATH')) {
    exit;
}

class WGTAI_REST_Controller extends \WP_REST_Controller
{
    private WGTAI_Storage_Service $storage_service;
    private WGTAI_Language_Service $language_service;

    public function __construct(WGTAI_Storage_Service $storage_service, WGTAI_Language_Service $language_service)
    {
        $this->namespace        = 'weglot-translations/v1';
        $this->rest_base        = 'posts';
        $this->storage_service  = $storage_service;
        $this->language_service = $language_service;
    }

    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => [$this, 'create_translations'],
                    'permission_callback' => [$this, 'permissions_check'],
                    'args'                => $this->get_endpoint_args_for_item_schema(true),
                ],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id>\\d+)/translations',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [$this, 'get_post_translations'],
                    'permission_callback' => [$this, 'permissions_check'],
                ],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id>\\d+)/translations/(?P<language>[A-Za-z0-9_-]+)',
            [
                [
                    'methods'             => \WP_REST_Server::DELETABLE,
                    'callback'            => [$this, 'delete_post_translation'],
                    'permission_callback' => [$this, 'permissions_check'],
                ],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/languages',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [$this, 'list_languages'],
                    'permission_callback' => [$this, 'permissions_check'],
                ],
            ]
        );

        // Per-locale taxonomy terms. Shape mirrors modules/polylang and
        // modules/wpml so the three bridges stay one contract: request
        // {source_term_id, taxonomy, translations[]{language, name, slug,
        // description, parent_id, meta}, trid?}, response 200/207
        // {source_term_id, taxonomy, provider, results, errors, notes}.
        register_rest_route(
            $this->namespace,
            '/terms',
            [
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => [$this, 'create_term_translations'],
                    'permission_callback' => [$this, 'permissions_check'],
                    'args'                => $this->get_term_endpoint_args(),
                ],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/terms/(?P<id>\\d+)/translations',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [$this, 'get_term_translations'],
                    'permission_callback' => [$this, 'permissions_check'],
                    'args'                => [
                        'taxonomy' => [
                            'description' => 'Taxonomy of the source term (e.g. product_cat).',
                            'type'        => 'string',
                            'required'    => true,
                        ],
                    ],
                ],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/terms/(?P<id>\\d+)/translations/(?P<language>[A-Za-z0-9_-]+)',
            [
                [
                    'methods'             => \WP_REST_Server::DELETABLE,
                    'callback'            => [$this, 'delete_term_translation'],
                    'permission_callback' => [$this, 'permissions_check'],
                    'args'                => [
                        'taxonomy' => [
                            'description' => 'Taxonomy of the source term (e.g. product_cat).',
                            'type'        => 'string',
                            'required'    => true,
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * The term branch MUST be evaluated before the post branch. A term id and a
     * post id are independent WordPress id spaces, so the same numeric value can
     * legitimately be both a taxonomy term and an editable post; if the post
     * branch ran first (or the term branch were dropped) a /terms request would
     * fall through to current_user_can('edit_post', $id) and could be granted
     * purely because that numeric id happens to be an editable post, without the
     * caller ever holding the taxonomy's edit_terms capability. Matches
     * modules/polylang/includes/class-ptai-rest-controller.php:97-117.
     */
    public function permissions_check($request): bool
    {
        if (! $request instanceof \WP_REST_Request) {
            return current_user_can('edit_posts');
        }

        $route = (string) $request->get_route();

        if (false !== strpos($route, '/terms')) {
            $taxonomy = $request->get_param('taxonomy');
            $term_id  = (int) ($request->get_param('source_term_id') ?: $request->get_param('id'));

            if (empty($taxonomy) || ! is_string($taxonomy)) {
                return false;
            }

            if ($term_id > 0) {
                return current_user_can('edit_term', $term_id);
            }

            $taxonomy_obj = get_taxonomy($taxonomy);
            $capability   = $taxonomy_obj && ! empty($taxonomy_obj->cap->edit_terms)
                ? $taxonomy_obj->cap->edit_terms
                : 'edit_terms';

            return current_user_can($capability);
        }

        $post_id = (int) ($request->get_param('source_post_id') ?: $request->get_param('id'));

        if ($post_id > 0) {
            return current_user_can('edit_post', $post_id);
        }

        return current_user_can('edit_posts');
    }

    public function create_translations(\WP_REST_Request $request)
    {
        $source_post_id = (int) $request->get_param('source_post_id');
        $translations   = $request->get_param('translations');

        if ($source_post_id <= 0) {
            return new \WP_Error('wgtai_missing_source', 'source_post_id is required.', ['status' => 400]);
        }

        if (! is_array($translations) || empty($translations)) {
            return new \WP_Error('wgtai_missing_translations', 'translations array is required.', ['status' => 400]);
        }

        $results = [];
        $errors  = [];

        foreach ($translations as $translation) {
            if (! is_array($translation)) {
                $errors[] = [
                    'language' => null,
                    'error'    => 'Invalid translation payload.',
                ];
                continue;
            }

            $result = $this->storage_service->save($source_post_id, $translation);

            if (is_wp_error($result)) {
                $errors[] = [
                    'language' => $translation['language'] ?? null,
                    'code'     => $result->get_error_code(),
                    'error'    => $result->get_error_message(),
                    'data'     => $result->get_error_data(),
                ];
                continue;
            }

            $results[] = $result;
        }

        $status = empty($errors) ? 200 : 207;

        return new \WP_REST_Response(
            [
                'source_post_id' => $source_post_id,
                'provider'       => 'weglot',
                'results'        => $results,
                'errors'         => $errors,
                'notes'          => $this->contract_notes(),
            ],
            $status
        );
    }

    public function get_post_translations(\WP_REST_Request $request)
    {
        $source_post_id = (int) $request->get_param('id');

        if ($source_post_id <= 0) {
            return new \WP_Error('wgtai_missing_source', 'source_post_id is required.', ['status' => 400]);
        }

        $post = get_post($source_post_id);

        if (! $post) {
            return new \WP_Error('wgtai_missing_source', 'Source post not found.', ['status' => 404]);
        }

        $original = $this->language_service->get_original_code();
        $stored   = $this->storage_service->get_stored_languages($source_post_id);

        $items = [
            [
                'language'  => $original,
                'url'       => (string) get_permalink($source_post_id),
                'is_source' => true,
                'stored'    => false,
                'title'     => $post->post_title,
                'status'    => $post->post_status,
            ],
        ];

        foreach ($this->language_service->get_destination_codes() as $code) {
            $payload = $this->storage_service->get($source_post_id, $code);

            $items[] = [
                'language'   => $code,
                'url'        => $this->language_service->get_translated_url($source_post_id, $code),
                'is_source'  => false,
                'stored'     => null !== $payload,
                'title'      => is_array($payload) ? ($payload['title'] ?? null) : null,
                'updated_at' => is_array($payload) ? ($payload['updated_at'] ?? null) : null,
            ];
        }

        return new \WP_REST_Response(
            [
                'source_post_id'    => $source_post_id,
                'post_type'         => $post->post_type,
                'provider'          => 'weglot',
                'stored_languages'  => $stored,
                'translations'      => $items,
            ],
            200
        );
    }

    public function delete_post_translation(\WP_REST_Request $request)
    {
        $source_post_id = (int) $request->get_param('id');
        $language       = (string) $request->get_param('language');

        if ($source_post_id <= 0) {
            return new \WP_Error('wgtai_missing_source', 'source_post_id is required.', ['status' => 400]);
        }

        if (! get_post($source_post_id)) {
            return new \WP_Error('wgtai_missing_source', 'Source post not found.', ['status' => 404]);
        }

        $internal_code = $this->language_service->resolve_destination_code($language);

        if ($internal_code === '') {
            $internal_code = $this->language_service->normalize($language);
        }

        $deleted = $this->storage_service->delete($source_post_id, $internal_code);

        return new \WP_REST_Response(
            [
                'source_post_id' => $source_post_id,
                'language'       => $internal_code,
                'deleted'        => $deleted,
            ],
            $deleted ? 200 : 404
        );
    }

    public function list_languages(\WP_REST_Request $request)
    {
        return new \WP_REST_Response($this->language_service->get_languages(), 200);
    }

    /**
     * Resolves and validates the term a /terms request refers to.
     *
     * Taxonomy mismatch is a 404, not a 400: a term that exists but belongs to a
     * different taxonomy than the caller named is a caller bug that would
     * otherwise write (or read) a payload onto the wrong entity. Validated here,
     * in the controller, not in storage -- WGTAI_Storage_Service needs only the
     * id (term_id is globally unique), so no storage code branches on taxonomy.
     *
     * @return \WP_Term|\WP_Error
     */
    private function resolve_term(int $term_id, $taxonomy)
    {
        if ($term_id <= 0) {
            return new \WP_Error('wgtai_missing_source_term', 'source_term_id is required.', ['status' => 400]);
        }

        if (empty($taxonomy) || ! is_string($taxonomy)) {
            return new \WP_Error('wgtai_missing_taxonomy', 'taxonomy is required.', ['status' => 400]);
        }

        $term = get_term($term_id);

        if (is_wp_error($term) || ! ($term instanceof \WP_Term)) {
            return new \WP_Error('wgtai_missing_term', 'Source term not found.', ['status' => 404]);
        }

        if ($term->taxonomy !== $taxonomy) {
            return new \WP_Error(
                'wgtai_taxonomy_mismatch',
                sprintf('Term %d belongs to taxonomy "%s", not "%s".', $term_id, $term->taxonomy, $taxonomy),
                ['status' => 404]
            );
        }

        return $term;
    }

    public function create_term_translations(\WP_REST_Request $request)
    {
        $source_term_id = (int) $request->get_param('source_term_id');
        $taxonomy       = $request->get_param('taxonomy');
        $translations   = $request->get_param('translations');

        $term = $this->resolve_term($source_term_id, $taxonomy);

        if (is_wp_error($term)) {
            return $term;
        }

        if (! is_array($translations) || empty($translations)) {
            return new \WP_Error('wgtai_missing_translations', 'translations array is required.', ['status' => 400]);
        }

        $results = [];
        $errors  = [];

        foreach ($translations as $translation) {
            if (! is_array($translation)) {
                $errors[] = [
                    'language' => null,
                    'error'    => 'Invalid translation payload.',
                ];
                continue;
            }

            $ignored_fields = $this->term_ignored_fields($translation);
            $result         = $this->storage_service->save_term($source_term_id, $translation);

            if (is_wp_error($result)) {
                $errors[] = [
                    'language' => $translation['language'] ?? null,
                    'code'     => $result->get_error_code(),
                    'error'    => $result->get_error_message(),
                    'data'     => $result->get_error_data(),
                ];
                continue;
            }

            // parent_id is accepted and ignored -- Weglot cannot re-parent a
            // taxonomy archive per locale -- and says so here rather than
            // dropping it silently. Same class of fault content_below_products
            // would be if it were served without being named: silent shape
            // degradation the caller has no way to detect.
            $result['ignored_fields'] = $ignored_fields;

            $results[] = $result;
        }

        $status = empty($errors) ? 200 : 207;

        return new \WP_REST_Response(
            [
                'source_term_id' => $source_term_id,
                'taxonomy'       => $taxonomy,
                'provider'       => 'weglot',
                'results'        => $results,
                'errors'         => $errors,
                'notes'          => $this->contract_notes(true),
            ],
            $status
        );
    }

    public function get_term_translations(\WP_REST_Request $request)
    {
        $source_term_id = (int) $request->get_param('id');
        $taxonomy       = $request->get_param('taxonomy');

        $term = $this->resolve_term($source_term_id, $taxonomy);

        if (is_wp_error($term)) {
            return $term;
        }

        $original    = $this->language_service->get_original_code();
        $stored      = $this->storage_service->get_term_stored_languages($source_term_id);
        $source_link = get_term_link($term);
        $source_url  = is_wp_error($source_link) ? '' : (string) $source_link;

        $items = [
            [
                'language'  => $original,
                'url'       => $source_url,
                'is_source' => true,
                'stored'    => false,
                'name'      => $term->name,
            ],
        ];

        foreach ($this->language_service->get_destination_codes() as $code) {
            $payload = $this->storage_service->get_term($source_term_id, $code);

            $items[] = [
                'language'   => $code,
                'url'        => $this->language_service->get_translated_url_for($source_url, $code),
                'is_source'  => false,
                'stored'     => null !== $payload,
                'name'       => is_array($payload) ? ($payload['name'] ?? null) : null,
                'updated_at' => is_array($payload) ? ($payload['updated_at'] ?? null) : null,
            ];
        }

        return new \WP_REST_Response(
            [
                'source_term_id'   => $source_term_id,
                'taxonomy'         => $term->taxonomy,
                'provider'         => 'weglot',
                'stored_languages' => $stored,
                'translations'     => $items,
            ],
            200
        );
    }

    public function delete_term_translation(\WP_REST_Request $request)
    {
        $source_term_id = (int) $request->get_param('id');
        $taxonomy       = $request->get_param('taxonomy');
        $language       = (string) $request->get_param('language');

        $term = $this->resolve_term($source_term_id, $taxonomy);

        if (is_wp_error($term)) {
            return $term;
        }

        $internal_code = $this->language_service->resolve_destination_code($language);

        if ($internal_code === '') {
            $internal_code = $this->language_service->normalize($language);
        }

        $deleted = $this->storage_service->delete_term($source_term_id, $internal_code);

        return new \WP_REST_Response(
            [
                'source_term_id' => $source_term_id,
                'language'       => $internal_code,
                'deleted'        => $deleted,
            ],
            $deleted ? 200 : 404
        );
    }

    /**
     * Top-level request keys this bridge accepts but never stores, named so a
     * caller can tell "accepted but not applied" apart from "silently dropped".
     *
     * @param array<string,mixed> $translation
     *
     * @return array<int,string>
     */
    private function term_ignored_fields(array $translation): array
    {
        $ignored = [];

        if (array_key_exists('parent_id', $translation)) {
            // Weglot serves one archive per term per locale; it has no per-locale
            // hierarchy to re-parent into.
            $ignored[] = 'parent_id';
        }

        return $ignored;
    }

    /**
     * @return array<int,string>
     */
    private function contract_notes(bool $is_term = false): array
    {
        $notes = [
            'Content is stored on the source post and served on Weglot-translated requests; no translated post is created.',
            'A translated slug cannot be applied from here - Weglot resolves slugs from its own dashboard (Pro plan and up), so URLs keep the source slug under the language prefix.',
            'Stored content is marked data-wg-notranslate, so it is served verbatim and consumes no Weglot word quota.',
        ];

        if ($is_term) {
            $notes[] = 'For terms, results[].url is always the real taxonomy archive URL for this term and language, never the requested slug - write that value to public.url, not the slug field.';
            $notes[] = 'parent_id is accepted and listed in results[].ignored_fields, but never applied - Weglot cannot re-parent a taxonomy archive per locale.';
        }

        return $notes;
    }

    public function get_item_schema(): array
    {
        return [
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'weglot_post_translations',
            'type'       => 'object',
            'properties' => [
                'source_post_id' => [
                    'description' => 'Source post the translations belong to.',
                    'type'        => 'integer',
                    'minimum'     => 1,
                ],
                'translations'   => [
                    'description' => 'Per-locale payloads to store.',
                    'type'        => 'array',
                    'items'       => [
                        'type'       => 'object',
                        'properties' => [
                            'language'      => [
                                'description' => 'Weglot destination language (e.g. fr, nl-NL).',
                                'type'        => 'string',
                            ],
                            'title'         => [
                                'description' => 'Translated title.',
                                'type'        => 'string',
                            ],
                            'content'       => [
                                'description' => 'Translated content (HTML allowed).',
                                'type'        => 'string',
                            ],
                            'excerpt'       => [
                                'description' => 'Translated excerpt.',
                                'type'        => 'string',
                            ],
                            'slug'          => [
                                'description' => 'Accepted and recorded, but not used for routing - see notes.',
                                'type'        => 'string',
                            ],
                            'meta'          => [
                                'description' => 'Post meta key/value pairs served for this locale.',
                                'type'        => 'object',
                            ],
                            'custom_fields' => [
                                'description' => 'Alias for meta.',
                                'type'        => 'object',
                            ],
                        ],
                        'required'   => ['language'],
                    ],
                ],
            ],
            'required'   => ['source_post_id', 'translations'],
        ];
    }

    /**
     * Copied in shape from
     * modules/polylang/includes/class-ptai-rest-controller.php:369-429 so the
     * three bridges stay one contract.
     *
     * @return array<string,mixed>
     */
    private function get_term_endpoint_args(): array
    {
        return [
            'source_term_id' => [
                'description' => 'Source term to translate from.',
                'type'        => 'integer',
                'required'    => true,
                'minimum'     => 1,
            ],
            'taxonomy'       => [
                'description' => 'Taxonomy of the source term (e.g. product_cat).',
                'type'        => 'string',
                'required'    => true,
            ],
            'trid'           => [
                'description' => 'Optional legacy group hint (accepted for WPML/Polylang-flow compatibility, ignored by the Weglot bridge).',
                'type'        => 'integer',
                'required'    => false,
                'minimum'     => 1,
            ],
            'translations'   => [
                'description' => 'Per-locale payloads to store.',
                'type'        => 'array',
                'required'    => true,
                'items'       => [
                    'type'       => 'object',
                    'properties' => [
                        'language'    => [
                            'description' => 'Weglot destination language (e.g. fr, nl-NL).',
                            'type'        => 'string',
                        ],
                        'name'        => [
                            'description' => 'Translated term name.',
                            'type'        => 'string',
                        ],
                        'slug'        => [
                            'description' => 'Accepted and recorded, but not used for routing - see notes.',
                            'type'        => 'string',
                        ],
                        'description' => [
                            'description' => 'Translated term description (HTML allowed).',
                            'type'        => 'string',
                        ],
                        'parent_id'   => [
                            'description' => 'Accepted and listed in ignored_fields, but never applied - see notes.',
                            'type'        => 'integer',
                        ],
                        'meta'        => [
                            'description' => 'Term meta key/value pairs served for this locale.',
                            'type'        => 'object',
                        ],
                    ],
                    'required'   => ['language'],
                ],
            ],
        ];
    }
}
