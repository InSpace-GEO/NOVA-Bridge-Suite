<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if (! defined('ABSPATH')) {
    exit;
}

class WTAI_REST_Controller extends \WP_REST_Controller
{
    private WTAI_Translation_Service $translation_service;

    public function __construct(WTAI_Translation_Service $translation_service)
    {
        $this->namespace           = 'wpml-translations/v1';
        $this->rest_base           = 'posts';
        $this->translation_service = $translation_service;
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
            '/languages',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [$this, 'list_languages'],
                    'permission_callback' => [$this, 'permissions_check'],
                ],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/terms/(?P<id>\\d+)',
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
    }

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
            return new \WP_Error('wtai_missing_source', 'source_post_id is required.', ['status' => 400]);
        }

        if (! is_array($translations) || empty($translations)) {
            return new \WP_Error('wtai_missing_translations', 'translations array is required.', ['status' => 400]);
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

            $result = $this->translation_service->upsert_post_translation($source_post_id, $translation);

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
                'results'        => $results,
                'errors'         => $errors,
            ],
            $status
        );
    }

    public function create_term_translations(\WP_REST_Request $request)
    {
        $source_term_id = (int) $request->get_param('source_term_id');
        $taxonomy       = $request->get_param('taxonomy');
        $translations   = $request->get_param('translations');
        $trid           = (int) $request->get_param('trid');

        if ($source_term_id <= 0) {
            return new \WP_Error('wtai_missing_source_term', 'source_term_id is required.', ['status' => 400]);
        }

        if (empty($taxonomy) || ! is_string($taxonomy)) {
            return new \WP_Error('wtai_missing_taxonomy', 'taxonomy is required.', ['status' => 400]);
        }

        if (! is_array($translations) || empty($translations)) {
            return new \WP_Error('wtai_missing_translations', 'translations array is required.', ['status' => 400]);
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

            $result = $this->translation_service->upsert_term_translation($source_term_id, $taxonomy, $translation, $trid ?: null);

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
                'source_term_id' => $source_term_id,
                'taxonomy'       => $taxonomy,
                'results'        => $results,
                'errors'         => $errors,
            ],
            $status
        );
    }

    public function list_languages(\WP_REST_Request $request)
    {
        $languages = $this->translation_service->get_languages();

        return new \WP_REST_Response($languages, 200);
    }

    public function get_term_translations(\WP_REST_Request $request)
    {
        $source_term_id = (int) $request->get_param('id');
        $taxonomy       = $request->get_param('taxonomy');

        if ($source_term_id <= 0) {
            return new \WP_Error('wtai_missing_source_term', 'source_term_id is required.', ['status' => 400]);
        }

        if (empty($taxonomy) || ! is_string($taxonomy)) {
            return new \WP_Error('wtai_missing_taxonomy', 'taxonomy is required.', ['status' => 400]);
        }

        $result = $this->translation_service->get_term_translations($source_term_id, $taxonomy);

        if (is_wp_error($result)) {
            return $result;
        }

        return new \WP_REST_Response($result, 200);
    }

    public function get_post_translations(\WP_REST_Request $request)
    {
        $source_post_id = (int) $request->get_param('id');

        if ($source_post_id <= 0) {
            return new \WP_Error('wtai_missing_source', 'source_post_id is required.', ['status' => 400]);
        }

        $post = get_post($source_post_id);
        if (! $post) {
            return new \WP_Error('wtai_missing_source', 'Source post not found.', ['status' => 404]);
        }

        $result = $this->translation_service->get_post_translations($source_post_id);

        if (is_wp_error($result)) {
            return $result;
        }

        return new \WP_REST_Response($result, 200);
    }

    public function get_item_schema(): array
    {
        return [
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'wpml_post_translations',
            'type'       => 'object',
            'properties' => [
                'source_post_id' => [
                    'description' => 'Source post to translate from.',
                    'type'        => 'integer',
                    'minimum'     => 1,
                ],
                'translations'  => [
                    'description' => 'Translations to apply.',
                    'type'        => 'array',
                    'items'       => [
                        'type'       => 'object',
                        'properties' => [
                            'language'       => [
                                'description' => 'Language code (e.g. fr, de).',
                                'type'        => 'string',
                            ],
                            'title'          => [
                                'description' => 'Translated title.',
                                'type'        => 'string',
                            ],
                            'slug'           => [
                                'description' => 'Translated slug.',
                                'type'        => 'string',
                            ],
                            'content'        => [
                                'description' => 'Translated content (HTML allowed).',
                                'type'        => 'string',
                            ],
                            'excerpt'        => [
                                'description' => 'Translated excerpt.',
                                'type'        => 'string',
                            ],
                            'status'         => [
                                'description' => 'Post status for the translation.',
                                'type'        => 'string',
                            ],
                            'comment_status' => [
                                'description' => 'Comment status (open or closed).',
                                'type'        => 'string',
                            ],
                            'parent_id'      => [
                                'description' => 'Parent post ID for hierarchy.',
                                'type'        => 'integer',
                            ],
                            'meta'           => [
                                'description' => 'Post meta key/value pairs.',
                                'type'        => 'object',
                            ],
                            'custom_fields'  => [
                                'description' => 'Alias for meta.',
                                'type'        => 'object',
                            ],
                            'taxonomies'     => [
                                'description' => 'Taxonomy term assignments for the target language.',
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
                'description' => 'Taxonomy of the source term (e.g. category, product_cat).',
                'type'        => 'string',
                'required'    => true,
            ],
            'trid'           => [
                'description' => 'Optional WPML translation group ID to force linkage.',
                'type'        => 'integer',
                'required'    => false,
                'minimum'     => 1,
            ],
            'translations'   => [
                'description' => 'Translations to apply.',
                'type'        => 'array',
                'required'    => true,
                'items'       => [
                    'type'       => 'object',
                    'properties' => [
                        'language'    => [
                            'description' => 'Language code (e.g. fr, de).',
                            'type'        => 'string',
                        ],
                        'name'        => [
                            'description' => 'Translated term name.',
                            'type'        => 'string',
                        ],
                        'term_id'     => [
                            'description' => 'Existing term ID for update.',
                            'type'        => 'integer',
                        ],
                        'slug'        => [
                            'description' => 'Translated term slug.',
                            'type'        => 'string',
                        ],
                        'description' => [
                            'description' => 'Translated term description.',
                            'type'        => 'string',
                        ],
                        'parent_id'   => [
                            'description' => 'Parent term ID.',
                            'type'        => 'integer',
                        ],
                        'meta'        => [
                            'description' => 'Term meta key/value pairs.',
                            'type'        => 'object',
                        ],
                    ],
                    'required'   => ['language'],
                ],
            ],
        ];
    }
}
