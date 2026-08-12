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

        // Kept registered so multilingual flows get an explicit, readable failure
        // instead of a 404 when they reach for the Polylang/WPML term contract.
        register_rest_route(
            $this->namespace,
            '/terms',
            [
                [
                    'methods'             => [\WP_REST_Server::CREATABLE, \WP_REST_Server::READABLE],
                    'callback'            => [$this, 'terms_not_supported'],
                    'permission_callback' => [$this, 'permissions_check'],
                ],
            ]
        );
    }

    public function permissions_check($request): bool
    {
        if (! $request instanceof \WP_REST_Request) {
            return current_user_can('edit_posts');
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

    public function terms_not_supported(\WP_REST_Request $request)
    {
        return new \WP_Error(
            'wgtai_terms_unsupported',
            'Weglot has no per-language taxonomy terms: term names are translated from the rendered page, so there is nothing to write. Category and tag copy is handled by Weglot itself.',
            ['status' => 501]
        );
    }

    /**
     * @return array<int,string>
     */
    private function contract_notes(): array
    {
        return [
            'Content is stored on the source post and served on Weglot-translated requests; no translated post is created.',
            'A translated slug cannot be applied from here - Weglot resolves slugs from its own dashboard (Pro plan and up), so URLs keep the source slug under the language prefix.',
            'Stored content is marked data-wg-notranslate, so it is served verbatim and consumes no Weglot word quota.',
        ];
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
}
