<?php
/**
 * REST endpoints for the NOVA Avada Bridge.
 */

declare(strict_types=1);

namespace NovaAvadaBridge;

use WP_Error;
use WP_Post;
use WP_Query;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

use function __;
use function absint;
use function current_user_can;
use function is_array;
use function is_wp_error;
use function post_type_exists;
use function register_rest_route;
use function sanitize_text_field;
use function sanitize_title;

class REST_Controller extends WP_REST_Controller {
    private Page_Service $page_service;
    private Layout_Transformer $transformer;

    public function __construct(Page_Service $page_service, Layout_Transformer $transformer) {
        $this->page_service = $page_service;
        $this->transformer  = $transformer;

        $this->namespace = 'nova-avada/v1';
        $this->rest_base = 'pages';
    }

    public function register_routes() {
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            [
                'methods'  => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_items'],
                'permission_callback' => [$this, 'permissions_check'],
                'args' => $this->get_collection_params(),
            ],
            [
                'methods'  => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'create_item'],
                'permission_callback' => [$this, 'permissions_check'],
                'args' => $this->get_endpoint_args_for_item_schema(true),
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<identifier>[\\w\\-\\/]+)', [
            [
                'methods'  => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_item'],
                'permission_callback' => [$this, 'permissions_check'],
                'args' => [
                    'identifier' => [
                        'description' => __('Page ID or slug.', 'nova-bridge-suite'),
                        'required'    => true,
                        'type'        => 'string',
                    ],
                    'include_document' => [
                        'description' => __('Set to true to include the raw Fusion Builder shortcodes.', 'nova-bridge-suite'),
                        'type'        => 'boolean',
                        'default'     => false,
                    ],
                    'layout_mode' => [
                        'description' => __('full returns every layout attribute, outline only returns tag/text/path.', 'nova-bridge-suite'),
                        'type'        => 'string',
                        'enum'        => ['full', 'outline'],
                        'default'     => 'outline',
                    ],
                    'outline_style' => [
                        'description' => __('summary returns a flat list of text nodes, tree preserves the nested hierarchy.', 'nova-bridge-suite'),
                        'type'        => 'string',
                        'enum'        => ['summary', 'tree'],
                        'default'     => 'summary',
                    ],
                    'text_map' => [
                        'description' => __('Include a flattened list of text nodes for targeted replacements.', 'nova-bridge-suite'),
                        'type'        => 'boolean',
                        'default'     => false,
                    ],
                    'include_meta' => [
                        'description' => __('Set to false to skip the Avada-specific meta payload.', 'nova-bridge-suite'),
                        'type'        => 'boolean',
                        'default'     => true,
                    ],
                ],
            ],
            [
                'methods'  => WP_REST_Server::EDITABLE,
                'callback' => [$this, 'update_item'],
                'permission_callback' => [$this, 'permissions_check'],
                'args' => $this->get_endpoint_args_for_item_schema(false),
            ],
        ]);
    }

    public function permissions_check($request) {
        if (!current_user_can('edit_pages')) {
            return new WP_Error('nova_avada_forbidden', __('You do not have permission to manage pages.', 'nova-bridge-suite'), ['status' => 403]);
        }

        return true;
    }

    /**
     * @param WP_REST_Request $request
     */
    public function get_items($request) {
        $per_page = absint($request->get_param('per_page')) ?: 10;
        $per_page = min($per_page, 50);
        $page     = absint($request->get_param('page')) ?: 1;
        $offset   = absint($request->get_param('offset')) ?: 0;

        $args = [
            'post_type'      => 'page',
            'posts_per_page' => $per_page,
            'post_status'    => ['publish', 'draft', 'pending', 'future'],
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'paged'          => max(1, $page),
        ];

        $post_type = $request->get_param('post_type');
        if ($post_type) {
            $pt = sanitize_text_field((string) $post_type);
            if ($pt && post_type_exists($pt)) {
                $args['post_type'] = $pt;
            }
        }

        if ($request->get_param('status')) {
            $args['post_status'] = sanitize_text_field((string) $request->get_param('status'));
        }

        if ($request->get_param('search')) {
            $args['s'] = sanitize_text_field((string) $request->get_param('search'));
        }

        if ($request->get_param('slug')) {
            $slug = sanitize_title((string) $request->get_param('slug'));
            if ($slug) {
                $args['name'] = $slug;
                $args['posts_per_page'] = 1;
            }
        }

        if ($request->get_param('include')) {
            $args['post__in'] = array_map('intval', (array) $request->get_param('include'));
        }

        if ($offset > 0) {
            $args['offset'] = $offset;
        }

        $query = new WP_Query($args);
        $items = [];

        foreach ($query->posts as $post) {
            $payload = $this->page_service->build_page_payload($post);
            $items[] = $this->filter_payload_for_request($payload, $request);
        }

        $response = new WP_REST_Response($items);
        $response->header('X-WP-Total', (int) $query->found_posts);
        $response->header('X-WP-TotalPages', (int) $query->max_num_pages);

        return $response;
    }

    /**
     * @param WP_REST_Request $request
     */
    public function get_item($request) {
        $identifier = $request->get_param('identifier');
        $post       = $this->page_service->get_page($identifier);

        if (!$post instanceof WP_Post) {
            return new WP_Error('nova_avada_not_found', __('Page not found.', 'nova-bridge-suite'), ['status' => 404]);
        }

        return $this->prepare_item_for_response($post, $request);
    }

    /**
     * @param WP_REST_Request $request
     */
    public function create_item($request) {
        $data   = $this->prepare_item_for_database($request);
        $template_identifier = $request->get_param('template');
        if ($template_identifier && empty($data['layout'])) {
            $template_payload = $this->resolve_template_payload($template_identifier);
            if (is_wp_error($template_payload)) {
                return $template_payload;
            }

            $data['layout'] = $template_payload['layout'];
            if (!empty($template_payload['meta'])) {
                $template_meta = $template_payload['meta'];
                $data['meta'] = $this->merge_template_meta($template_meta, $data['meta'] ?? []);
            }
        } elseif (!empty($request->get_param('source_page_id')) && empty($data['layout'])) {
            $source_payload = $this->resolve_template_payload($request->get_param('source_page_id'));
            if (is_wp_error($source_payload)) {
                return $source_payload;
            }
            $data['layout'] = $source_payload['layout'];
            if (!empty($source_payload['meta'])) {
                $data['meta'] = $this->merge_template_meta($source_payload['meta'], $data['meta'] ?? []);
            }
        }

        $text_updates = $this->extract_text_updates($request);
        if (!empty($text_updates)) {
            if (empty($data['layout'])) {
                return new WP_Error('nova_avada_missing_layout', __('A layout or template must be supplied when applying text updates.', 'nova-bridge-suite'), ['status' => 400]);
            }

            $data['layout'] = $this->page_service->apply_text_updates($data['layout'], $text_updates);
        }

        if (!empty($data['remove_paths']) && !empty($data['layout'])) {
            $data['layout'] = $this->page_service->remove_paths($data['layout'], $data['remove_paths']);
            unset($data['remove_paths']);
        }

        if (!empty($data['append_html'])) {
            $data['layout'] = $this->page_service->append_html_block($data['layout'], $data['append_html']);
            unset($data['append_html']);
        }

        if (!empty($data['append_sections'])) {
            $data['layout'] = $this->page_service->append_sections($data['layout'], $data['append_sections']);
            unset($data['append_sections']);
        }

        $result = $this->page_service->create_page($data);

        if (is_wp_error($result)) {
            return $result;
        }

        $response = $this->prepare_item_for_response($result, $request);
        $response->set_status(201);

        return $response;
    }

    /**
     * @param WP_REST_Request $request
     */
    public function update_item($request) {
        $identifier = $request->get_param('identifier');
        $post       = $this->page_service->get_page($identifier);

        if (!$post instanceof WP_Post) {
            return new WP_Error('nova_avada_not_found', __('Page not found.', 'nova-bridge-suite'), ['status' => 404]);
        }

        $data   = $this->prepare_item_for_database($request);
        $template_identifier = $request->get_param('template');
        if ($template_identifier && empty($data['layout'])) {
            $template_payload = $this->resolve_template_payload($template_identifier);
            if (is_wp_error($template_payload)) {
                return $template_payload;
            }

            $data['layout'] = $template_payload['layout'];
            if (!empty($template_payload['meta'])) {
                $template_meta = $template_payload['meta'];
                $data['meta'] = $this->merge_template_meta($template_meta, $data['meta'] ?? []);
            }
        } elseif (!empty($request->get_param('source_page_id')) && empty($data['layout'])) {
            $source_payload = $this->resolve_template_payload($request->get_param('source_page_id'));
            if (is_wp_error($source_payload)) {
                return $source_payload;
            }
            $data['layout'] = $source_payload['layout'];
            if (!empty($source_payload['meta'])) {
                $data['meta'] = $this->merge_template_meta($source_payload['meta'], $data['meta'] ?? []);
            }
        }

        $text_updates = $this->extract_text_updates($request);
        if (!empty($text_updates)) {
            if (empty($data['layout'])) {
                $current_payload = $this->page_service->build_page_payload($post);
                $data['layout']  = $current_payload['layout'];
            }

            $data['layout'] = $this->page_service->apply_text_updates($data['layout'], $text_updates);
        }

        if (!empty($data['remove_paths'])) {
            if (empty($data['layout'])) {
                $current_payload = $this->page_service->build_page_payload($post);
                $data['layout']  = $current_payload['layout'];
            }
            $data['layout'] = $this->page_service->remove_paths($data['layout'], $data['remove_paths']);
            unset($data['remove_paths']);
        }

        if (!empty($data['append_html'])) {
            $data['layout'] = $this->page_service->append_html_block($data['layout'], $data['append_html']);
            unset($data['append_html']);
        }

        if (!empty($data['append_sections'])) {
            if (empty($data['layout'])) {
                $current_payload = $this->page_service->build_page_payload($post);
                $data['layout']  = $current_payload['layout'];
            }
            $data['layout'] = $this->page_service->append_sections($data['layout'], $data['append_sections']);
            unset($data['append_sections']);
        }

        $result = $this->page_service->update_page($post->ID, $data);

        if (is_wp_error($result)) {
            return $result;
        }

        return $this->prepare_item_for_response($result, $request);
    }

    /**
     * @param WP_Post          $post
     * @param WP_REST_Request  $request
     *
     * @return WP_REST_Response
     */
    public function prepare_item_for_response($post, $request) {
        $payload  = $this->page_service->build_page_payload($post);
        $payload  = $this->filter_payload_for_request($payload, $request);
        $response = new WP_REST_Response($payload);

        return $response;
    }

    /**
     * @return array<string, mixed>
     *
     * Core WP_REST_Controller omits a return type, so we do the same for compatibility.
     */
    protected function prepare_item_for_database($request) {
        $data = [];
        $fields = ['title', 'slug', 'status', 'excerpt', 'content'];
        foreach ($fields as $field) {
            if (null !== $request->get_param($field)) {
                $data[$field] = $request->get_param($field);
            }
        }

        if (null !== $request->get_param('parent')) {
            $data['parent'] = $request->get_param('parent');
        }

        if (null !== $request->get_param('meta') && is_array($request->get_param('meta'))) {
            $data['meta'] = $request->get_param('meta');
        }

        $layout = $request->get_param('layout');
        if (is_array($layout)) {
            $data['layout'] = $layout;
        } else {
            $shortcodes = $request->get_param('shortcodes') ?? $request->get_param('raw_shortcodes');
            if (is_string($shortcodes)) {
                $data['layout'] = ['raw_shortcodes' => $shortcodes];
            }
        }

        if (!isset($data['layout'])) {
            $data['layout'] = [];
        }

        if (null !== $request->get_param('publish_builder')) {
            $data['publish_builder'] = $this->is_truthy($request->get_param('publish_builder'));
        }

        if (is_string($request->get_param('append_html')) && '' !== trim((string) $request->get_param('append_html'))) {
            $data['append_html'] = (string) $request->get_param('append_html');
        }

        if (is_string($request->get_param('post_type'))) {
            $data['post_type'] = $request->get_param('post_type');
        }

        $append_sections = $request->get_param('append_sections');
        if (is_array($append_sections)) {
            $normalized_sections = [];
            foreach ($append_sections as $section) {
                if (!is_array($section)) {
                    continue;
                }
                $title = isset($section['title']) ? (string) $section['title'] : '';
                $body  = isset($section['body']) ? (string) $section['body'] : '';
                $title_tag = isset($section['title_tag']) ? (string) $section['title_tag'] : '';
                if ('' === trim($title) && '' === trim($body)) {
                    continue;
                }
                $normalized_sections[] = [
                    'title'     => $title,
                    'body'      => $body,
                    'title_tag' => $title_tag,
                ];
            }
            if (!empty($normalized_sections)) {
                $data['append_sections'] = $normalized_sections;
            }
        }

        $remove_paths = $request->get_param('remove_paths');
        if (is_array($remove_paths)) {
            $normalized_paths = [];
            foreach ($remove_paths as $path) {
                if ('' !== trim((string) $path)) {
                    $normalized_paths[] = (string) $path;
                }
            }
            if (!empty($normalized_paths)) {
                $data['remove_paths'] = $normalized_paths;
            }
        }

        if (null !== $request->get_param('author')) {
            $data['author'] = $request->get_param('author');
        }

        return $data;
    }

    public function get_collection_params() {
        return [
            'per_page' => [
                'description' => __('Number of pages to return.', 'nova-bridge-suite'),
                'type'        => 'integer',
                'default'     => 10,
                'minimum'     => 1,
            ],
            'page' => [
                'description' => __('Page number for pagination.', 'nova-bridge-suite'),
                'type'        => 'integer',
                'default'     => 1,
                'minimum'     => 1,
            ],
            'offset' => [
                'description' => __('Number of items to skip before collecting results.', 'nova-bridge-suite'),
                'type'        => 'integer',
                'default'     => 0,
                'minimum'     => 0,
            ],
            'status' => [
                'description' => __('Limit response to a specific status.', 'nova-bridge-suite'),
                'type'        => 'string',
            ],
            'slug' => [
                'description' => __('Limit response to a specific page slug.', 'nova-bridge-suite'),
                'type'        => 'string',
            ],
            'search' => [
                'description' => __('Search term for page titles.', 'nova-bridge-suite'),
                'type'        => 'string',
            ],
            'post_type' => [
                'description' => __('Target post type (defaults to page). Must be a registered post type.', 'nova-bridge-suite'),
                'type'        => 'string',
            ],
            'include' => [
                'description' => __('Specific page IDs to include.', 'nova-bridge-suite'),
                'type'        => 'array',
                'items'       => [
                    'type' => 'integer',
                ],
            ],
            'include_document' => [
                'description' => __('Return the raw Fusion Builder shortcodes when true.', 'nova-bridge-suite'),
                'type'        => 'boolean',
                'default'     => false,
            ],
            'layout_mode' => [
                'description' => __('Control how much layout data is returned. Use outline for AI-friendly payloads.', 'nova-bridge-suite'),
                'type'        => 'string',
                'enum'        => ['full', 'outline'],
                'default'     => 'outline',
            ],
            'outline_style' => [
                'description' => __('summary outputs a flat list of text nodes, tree preserves nesting.', 'nova-bridge-suite'),
                'type'        => 'string',
                'enum'        => ['summary', 'tree'],
                'default'     => 'summary',
            ],
            'text_map' => [
                'description' => __('Include a flattened list of layout text nodes.', 'nova-bridge-suite'),
                'type'        => 'boolean',
                'default'     => false,
            ],
            'include_meta' => [
                'description' => __('Include Avada/Fusion meta data block.', 'nova-bridge-suite'),
                'type'        => 'boolean',
                'default'     => true,
            ],
        ];
    }

    public function get_endpoint_args_for_item_schema($creating = false) {
        $args = parent::get_endpoint_args_for_item_schema($creating);
        $args['template'] = [
            'description' => __('Existing page ID or slug whose layout/meta should be cloned when layout is omitted.', 'nova-bridge-suite'),
            'type'        => 'string',
            'required'    => false,
        ];
        $args['text_updates'] = [
            'description' => __('Array of text patch objects `[ { "path": "0.1", "text": "New copy" } ]` applied to the compact layout.', 'nova-bridge-suite'),
            'type'        => 'array',
            'items'       => [
                'type'       => 'object',
                'properties' => [
                    'path' => [
                        'type'        => 'string',
                        'description' => __('Compact node path identifier.', 'nova-bridge-suite'),
                    ],
                    'text' => [
                        'type'        => 'string',
                        'description' => __('Replacement text value.', 'nova-bridge-suite'),
                    ],
                ],
            ],
        ];
        $args['publish_builder'] = [
            'description' => __('Flush Avada caches and bump the revision so builder CSS regenerates.', 'nova-bridge-suite'),
            'type'        => 'boolean',
            'required'    => false,
        ];
        $args['parent'] = [
            'description' => __('Numeric parent page ID for nesting.', 'nova-bridge-suite'),
            'type'        => 'integer',
            'required'    => false,
        ];
        $args['append_html'] = [
            'description' => __('Optional HTML block to append as a new fusion_text at the end of the layout.', 'nova-bridge-suite'),
            'type'        => 'string',
            'required'    => false,
        ];
        $args['append_sections'] = [
            'description' => __('Optional sections to append as fusion_title + fusion_text at the end of the layout.', 'nova-bridge-suite'),
            'type'        => 'array',
            'items'       => [
                'type'       => 'object',
                'properties' => [
                    'title' => [
                        'type' => 'string',
                    ],
                    'body' => [
                        'type' => 'string',
                    ],
                    'title_tag' => [
                        'type'        => 'string',
                        'description' => __('Heading tag for the title (e.g., h2/h3). Defaults to h2.', 'nova-bridge-suite'),
                    ],
                ],
            ],
            'required' => false,
        ];
        $args['remove_paths'] = [
            'description' => __('Array of outline paths to remove from the layout before applying updates.', 'nova-bridge-suite'),
            'type'        => 'array',
            'items'       => [
                'type' => 'string',
            ],
            'required'    => false,
        ];
        $args['post_type'] = [
            'description' => __('Target post type for creation/update (defaults to page).', 'nova-bridge-suite'),
            'type'        => 'string',
            'required'    => false,
        ];
        $args['author'] = [
            'description' => __('Numeric user ID to assign as author.', 'nova-bridge-suite'),
            'type'        => 'integer',
            'required'    => false,
        ];

        return $args;
    }

    public function get_item_schema() {
        if ($this->schema) {
            return $this->schema;
        }

        $node_schema = $this->get_layout_node_schema_template();

        $this->schema = [
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'nova-avada-page',
            'type'       => 'object',
            'properties' => [
                'id'      => [
                    'description' => __('Unique identifier for the page.', 'nova-bridge-suite'),
                    'type'        => 'integer',
                    'readonly'    => true,
                    'context'     => ['view', 'edit', 'embed'],
                ],
                'title'   => [
                    'description' => __('Title for the page.', 'nova-bridge-suite'),
                    'type'        => 'string',
                    'context'     => ['view', 'edit'],
                ],
                'slug'    => [
                    'description' => __('URL slug.', 'nova-bridge-suite'),
                    'type'        => 'string',
                    'context'     => ['view', 'edit'],
                ],
                'status'  => [
                    'description' => __('Post status.', 'nova-bridge-suite'),
                    'type'        => 'string',
                    'enum'        => ['publish', 'draft', 'pending', 'future', 'private'],
                    'context'     => ['view', 'edit'],
                ],
                'post_type' => [
                    'description' => __('Post type for the item.', 'nova-bridge-suite'),
                    'type'        => 'string',
                    'context'     => ['view', 'edit'],
                ],
                'author' => [
                    'description' => __('Author user ID.', 'nova-bridge-suite'),
                    'type'        => 'integer',
                    'context'     => ['view', 'edit'],
                ],
                'excerpt' => [
                    'description' => __('Short description for the page.', 'nova-bridge-suite'),
                    'type'        => 'string',
                    'context'     => ['view', 'edit'],
                ],
                'content' => [
                    'description' => __('Optional fallback HTML content.', 'nova-bridge-suite'),
                    'type'        => 'string',
                    'context'     => ['view', 'edit'],
                ],
                'modified_gmt' => [
                    'description' => __('Last modified time in GMT.', 'nova-bridge-suite'),
                    'type'        => 'string',
                    'format'      => 'date-time',
                    'readonly'    => true,
                    'context'     => ['view', 'edit'],
                ],
                'permalink' => [
                    'description' => __('Public URL for the page.', 'nova-bridge-suite'),
                    'type'        => 'string',
                    'format'      => 'uri',
                    'readonly'    => true,
                    'context'     => ['view', 'edit'],
                ],
                'meta'    => [
                    'description' => __('Additional post meta to persist.', 'nova-bridge-suite'),
                    'type'        => 'object',
                    'additionalProperties' => true,
                    'context'     => ['view', 'edit'],
                ],
                'layout'  => [
                    'description' => __('Layout payload used to generate Avada shortcodes.', 'nova-bridge-suite'),
                    'type'        => 'object',
                    'context'     => ['view', 'edit'],
                    'properties'  => [
                        'raw_shortcodes' => [
                            'type'        => 'string',
                            'description' => __('Raw shortcode string from Avada builder.', 'nova-bridge-suite'),
                        ],
                        'compact'       => [
                            'type'        => 'array',
                            'items'       => [
                                '$ref' => '#/definitions/layout_node',
                            ],
                            'description' => __('Tree representation of the desired layout.', 'nova-bridge-suite'),
                        ],
                        'status'        => [
                            'type'        => 'string',
                            'description' => __('Fusion builder status meta value.', 'nova-bridge-suite'),
                        ],
                        'has_builder'  => [
                            'type'        => 'boolean',
                            'description' => __('Whether the page currently stores Fusion Builder content.', 'nova-bridge-suite'),
                        ],
                    ],
                ],
            ],
            'definitions' => [
                'layout_node' => $node_schema,
            ],
        ];

        return $this->schema;
    }

    private function get_layout_node_schema_template(): array {
        return [
            'type'       => 'object',
            'required'   => ['tag'],
            'properties' => [
                'tag'          => [
                    'type'        => 'string',
                    'description' => __('Shortcode tag name, e.g., fusion_builder_column or fusion_text.', 'nova-bridge-suite'),
                ],
                'attributes'   => [
                    'type'                 => 'object',
                    'additionalProperties' => ['type' => 'string'],
                    'description'          => __('Shortcode attributes as key/value pairs.', 'nova-bridge-suite'),
                ],
                'text'         => [
                    'type'        => 'string',
                    'description' => __('Text content that sits within the shortcode after child elements have rendered.', 'nova-bridge-suite'),
                ],
                'self_closing' => [
                    'type'        => 'boolean',
                    'description' => __('Whether the shortcode should be rendered as self closing.', 'nova-bridge-suite'),
                ],
                'children'     => [
                    'type'  => 'array',
                    'items' => [
                        '$ref' => '#/definitions/layout_node',
                    ],
                ],
            ],
        ];
    }

    /**
     * Apply request-specific layout filters to the payload.
     *
     * @param array<string, mixed> $payload
     */
    private function filter_payload_for_request(array $payload, $request): array {
        if (!isset($payload['layout']) || !is_array($payload['layout'])) {
            return $payload;
        }

        $include_document = $this->is_truthy($request->get_param('include_document'));
        $layout_mode      = $request->get_param('layout_mode') ?: 'outline';
        $outline_style    = $request->get_param('outline_style') ?: 'summary';
        $include_text_map = $this->is_truthy($request->get_param('text_map'));
        $include_meta_raw = $request->get_param('include_meta');
        $include_meta     = null === $include_meta_raw ? true : $this->is_truthy($include_meta_raw);

        $compact = $payload['layout']['compact'] ?? [];

        if ($include_text_map && !empty($compact)) {
            $payload['layout']['text_map'] = $this->transformer->extract_text_map($compact);
        }

        if ('outline' === $layout_mode) {
            if ('tree' === $outline_style) {
                $payload['layout']['outline'] = !empty($compact) ? $this->transformer->to_outline($compact) : [];
            } else {
                $payload['layout']['outline'] = !empty($compact) ? $this->transformer->to_outline_summary($compact) : [];
            }

            unset($payload['layout']['compact']);
        }

        if (!$include_document && isset($payload['layout']['raw_shortcodes'])) {
            unset($payload['layout']['raw_shortcodes']);
        }

        if (!$include_meta && isset($payload['meta'])) {
            unset($payload['meta']);
        }

        return $payload;
    }

    /**
     * Normalize text updates from the request payload.
     *
     * @return array<int, array<string, string>>
     */
    private function extract_text_updates($request): array {
        $updates = $request->get_param('text_updates');
        if (!is_array($updates)) {
            return [];
        }

        $normalized = [];
        foreach ($updates as $update) {
            if (!is_array($update)) {
                continue;
            }

            if (!isset($update['path']) || !array_key_exists('text', $update)) {
                continue;
            }

            $normalized[] = [
                'path' => (string) $update['path'],
                'text' => (string) $update['text'],
            ];
        }

        return $normalized;
    }

    /**
     * @param mixed $identifier
     *
     * @return array<string, mixed>|WP_Error
     */
    private function resolve_template_payload($identifier) {
        $template_post = $this->page_service->get_page($identifier);
        if (!$template_post instanceof WP_Post) {
            return new WP_Error('nova_avada_template_not_found', __('Template page not found.', 'nova-bridge-suite'), ['status' => 404]);
        }

        return $this->page_service->build_page_payload($template_post);
    }

    /**
     * Merge template meta into request meta, preserving user-supplied keys.
     *
     * @param array<string, mixed> $template_meta
     * @param array<string, mixed> $request_meta
     *
     * @return array<string, mixed>
     */
    private function merge_template_meta(array $template_meta, array $request_meta): array {
        if (empty($request_meta)) {
            return $template_meta;
        }

        // Template values only fill gaps; user values win.
        foreach ($template_meta as $key => $value) {
            if (!array_key_exists($key, $request_meta)) {
                $request_meta[$key] = $value;
            }
        }

        return $request_meta;
    }

    /**
     * Basic truthy detection for request parameters.
     *
     * @param mixed $value
     */
    private function is_truthy($value): bool {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return 0 !== $value;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }
}
