<?php
/**
 * REST controller exposing Elementor bridge endpoints.
 *
 * @package SEOR_Elementor_Bridge
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals
namespace SEOR_Elementor_Bridge;

use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API controller for Elementor bridge.
 */
class Rest_Controller extends WP_REST_Controller {
	/**
	 * Elementor service.
	 *
	 * @var Elementor_Service
	 */
	private $service;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->namespace = 'seor-bridge/v1';
		$this->rest_base = 'pages';
		$this->service   = new Elementor_Service();
	}

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_collection' ),
					'permission_callback' => array( $this, 'permission_check_read' ),
					'args'                => array(
						'slug'                => array(
							'type'              => 'string',
							'description'       => __( 'Retrieve page by slug.', 'nova-bridge-suite' ),
							'required'          => false,
							'sanitize_callback' => 'sanitize_title',
						),
						'ids'                 => array(
							'type'              => 'string',
							'description'       => __( 'Comma separated list of page IDs to fetch.', 'nova-bridge-suite' ),
							'required'          => false,
						),
						'meta_keys'           => array(
							'type'              => 'string',
							'description'       => __( 'Comma separated list of meta keys to include.', 'nova-bridge-suite' ),
						),
						'include_fields'      => array(
							'type'              => 'boolean',
							'description'       => __( 'Whether to include field payloads.', 'nova-bridge-suite' ),
							'default'           => true,
						),
						'include_element_map' => array(
							'type'              => 'boolean',
							'description'       => __( 'Whether to include element summaries.', 'nova-bridge-suite' ),
							'default'           => false,
						),
						'include_document'    => array(
							'type'              => 'boolean',
							'description'       => __( 'Whether to include raw Elementor document data (debug).', 'nova-bridge-suite' ),
							'default'           => false,
						),
						'post_type'           => array(
							'type'              => 'string',
							'description'       => __( 'Target post type (defaults to "page"). Use "any" to search across public post types.', 'nova-bridge-suite' ),
							'default'           => 'page',
						),
						'parent'              => array(
							'type'              => 'integer',
							'description'       => __( 'Limit results to children of this parent page ID.', 'nova-bridge-suite' ),
						),
						'status'              => array(
							'type'              => 'string',
							'description'       => __( 'Filter by post status.', 'nova-bridge-suite' ),
							'default'           => 'publish',
						),
						'search'              => array(
							'type'              => 'string',
							'description'       => __( 'Search term to match against title/content.', 'nova-bridge-suite' ),
						),
						'per_page'            => array(
							'type'              => 'integer',
							'description'       => __( 'Number of items to return (max 100).', 'nova-bridge-suite' ),
							'default'           => 10,
						),
						'page'                => array(
							'type'              => 'integer',
							'description'       => __( 'Page number for pagination.', 'nova-bridge-suite' ),
							'default'           => 1,
						),
						'order'               => array(
							'type'              => 'string',
							'description'       => __( 'Sort order (asc or desc).', 'nova-bridge-suite' ),
							'default'           => 'desc',
						),
						'orderby'             => array(
							'type'              => 'string',
							'description'       => __( 'Field to sort by (date, modified, title, name, ID).', 'nova-bridge-suite' ),
							'default'           => 'date',
						),
						'offset'              => array(
							'type'              => 'integer',
							'description'       => __( 'Number of items to skip before collecting results.', 'nova-bridge-suite' ),
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'permission_check_manage' ),
					'args'                => $this->get_validation_schema(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'permission_check_read' ),
					'args'                => array(
						'id'                   => array(
							'type'     => 'integer',
							'required' => true,
						),
						'meta_keys'            => array(
							'type'        => 'string',
							'description' => __( 'Comma separated list of meta keys to include.', 'nova-bridge-suite' ),
						),
						'include_fields'       => array(
							'type'    => 'boolean',
							'default' => true,
						),
						'include_element_map'  => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'include_document'     => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'permission_check_manage' ),
					'args'                => $this->get_validation_schema(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\\d+)/fields',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_fields' ),
					'permission_callback' => array( $this, 'permission_check_read' ),
					'args'                => array(
						'id' => array(
							'type'     => 'integer',
							'required' => true,
						),
					),
				),
			)
		);
	}

	/**
	 * Permission check for read requests.
	 *
	 * @return bool|WP_Error
	 */
	public function permission_check_read() {
		if ( current_user_can( 'edit_pages' ) ) {
			return true;
		}

		return new WP_Error( 'seor_eb_forbidden', __( 'You are not allowed to access these resources.', 'nova-bridge-suite' ), array( 'status' => 403 ) );
	}

	/**
	 * Permission check for mutating requests.
	 *
	 * @return bool|WP_Error
	 */
	public function permission_check_manage() {
		if ( current_user_can( 'edit_pages' ) ) {
			return true;
		}

		return new WP_Error( 'seor_eb_forbidden', __( 'You are not allowed to modify pages.', 'nova-bridge-suite' ), array( 'status' => 403 ) );
	}

	/**
	 * Handle GET collection request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_collection( $request ) {
		if ( ! $request instanceof WP_REST_Request ) {
			return new WP_Error( 'seor_eb_invalid_request', __( 'Invalid request object provided.', 'nova-bridge-suite' ), array( 'status' => 500 ) );
		}
		$ids              = $this->parse_ids( $request->get_param( 'ids' ) );
		$slug             = $request->get_param( 'slug' );
		$meta             = $this->parse_meta_keys( $request->get_param( 'meta_keys' ) );
		$include_fields   = $this->to_bool( $request->get_param( 'include_fields' ), true );
		$include_map      = $this->to_bool( $request->get_param( 'include_element_map' ), false );
		$include_document = $this->to_bool( $request->get_param( 'include_document' ), false );
		$post_type        = $this->sanitize_post_type( $request->get_param( 'post_type' ) );
		$parent           = $request->get_param( 'parent' );
		$status           = $this->sanitize_status( $request->get_param( 'status' ) );
		$search           = $request->get_param( 'search' );
		$per_page         = $this->sanitize_per_page( $request->get_param( 'per_page' ) );
		$paged            = $this->sanitize_page( $request->get_param( 'page' ) );
		$order            = $this->sanitize_order( $request->get_param( 'order' ) );
		$order_by         = $this->sanitize_orderby( $request->get_param( 'orderby' ) );
		$offset           = $this->sanitize_offset( $request->get_param( 'offset' ) );

		$has_lookup_filter = ! empty( $ids ) || ! empty( $slug );
		$has_query_filters = $this->has_collection_filters(
			array(
				'offset'    => $offset,
				'page'      => $paged,
				'per_page'  => $per_page,
				'search'    => $search,
				'parent'    => $parent,
				'post_type' => $post_type,
				'status'    => $status,
				'order'     => $order,
				'orderby'   => $order_by,
			)
		);

		$allow_unfiltered_requests = apply_filters( 'seor_eb_allow_unfiltered_collection', false, $request );

		if ( ! $has_lookup_filter && ! $has_query_filters && ! $allow_unfiltered_requests ) {
			return new WP_Error(
				'seor_eb_missing_filter',
				__( 'Provide ids, slug, or pagination filters to fetch pages.', 'nova-bridge-suite' ),
				array( 'status' => 400 )
			);
		}

		$args = array(
			'meta_keys'           => $meta,
			'include_fields'      => $include_fields,
			'include_element_map' => $include_map,
			'include_document'    => $include_document,
		);

		$items = array();

		if ( ! empty( $ids ) ) {
			foreach ( $ids as $id ) {
				$payload = $this->service->get_page_payload( $id, $args );
				if ( is_wp_error( $payload ) ) {
					return $payload;
				}
				$items[] = $payload;
			}

			return rest_ensure_response( $items );
		}

		if ( ! empty( $slug ) ) {
			$lookup_types = ( 'any' === $post_type )
				? array_values( get_post_types( array( 'public' => true ) ) )
				: $post_type;

			$page = get_page_by_path( $slug, OBJECT, $lookup_types );
			if ( ! $page ) {
				$slug_name = basename( $slug );
				$fallback  = new \WP_Query(
					array(
						'post_type'      => $lookup_types,
						'name'           => $slug_name,
						'post_status'    => $status,
						'posts_per_page' => 1,
						'fields'         => 'ids',
					)
				);

				if ( $fallback->have_posts() ) {
					$page_id = (int) $fallback->posts[0];
					$payload = $this->service->get_page_payload( $page_id, $args );
					if ( is_wp_error( $payload ) ) {
						return $payload;
					}

					return rest_ensure_response( array( $payload ) );
				}

				return new WP_Error( 'seor_eb_not_found', __( 'Page not found for provided slug.', 'nova-bridge-suite' ), array( 'status' => 404 ) );
			}

			$payload = $this->service->get_page_payload( $page->ID, $args );
			if ( is_wp_error( $payload ) ) {
				return $payload;
			}

			return rest_ensure_response( array( $payload ) );
		}

		$query_args = array(
			'post_type'      => ( 'any' === $post_type ) ? 'any' : $post_type,
			'post_status'    => $status,
			'posts_per_page' => $per_page,
			'paged'          => $paged,
			'orderby'        => $order_by,
			'order'          => $order,
			'fields'         => 'ids',
		);

		if ( null !== $offset ) {
			$query_args['offset'] = $offset;
		}

		if ( null !== $parent && '' !== $parent ) {
			$query_args['post_parent'] = (int) $parent;
		}

		if ( ! empty( $search ) ) {
			$query_args['s'] = sanitize_text_field( $search );
		}

		$wp_query = new \WP_Query( $query_args );

		if ( $wp_query->have_posts() ) {
			foreach ( $wp_query->posts as $post_id ) {
				$payload = $this->service->get_page_payload( $post_id, $args );
				if ( is_wp_error( $payload ) ) {
					return $payload;
				}
				$items[] = $payload;
			}
		}

		$response = rest_ensure_response( $items );
		$response->header( 'X-WP-Total', (int) $wp_query->found_posts );
		$response->header( 'X-WP-TotalPages', (int) $wp_query->max_num_pages );

		return $response;
	}

	/**
	 * Fetch single page payload.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		if ( ! $request instanceof WP_REST_Request ) {
			return new WP_Error( 'seor_eb_invalid_request', __( 'Invalid request object provided.', 'nova-bridge-suite' ), array( 'status' => 500 ) );
		}
		$post_id = (int) $request['id'];
		$args    = array(
			'meta_keys'           => $this->parse_meta_keys( $request->get_param( 'meta_keys' ) ),
			'include_fields'      => $this->to_bool( $request->get_param( 'include_fields' ), true ),
			'include_element_map' => $this->to_bool( $request->get_param( 'include_element_map' ), false ),
			'include_document'    => $this->to_bool( $request->get_param( 'include_document' ), false ),
		);

		$payload = $this->service->get_page_payload( $post_id, $args );

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		return rest_ensure_response( $payload );
	}

	/**
	 * Fetch only field payload.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_fields( $request ) {
		if ( ! $request instanceof WP_REST_Request ) {
			return new WP_Error( 'seor_eb_invalid_request', __( 'Invalid request object provided.', 'nova-bridge-suite' ), array( 'status' => 500 ) );
		}
		$post_id = (int) $request['id'];

		$payload = $this->service->get_page_payload(
			$post_id,
			array(
				'include_fields'      => true,
				'include_element_map' => false,
			)
		);

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		return rest_ensure_response(
			array(
				'id'     => $payload['id'],
				'fields' => $payload['fields'],
			)
		);
	}

	/**
	 * Create a new Elementor page.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		if ( ! $request instanceof WP_REST_Request ) {
			return new WP_Error( 'seor_eb_invalid_request', __( 'Invalid request object provided.', 'nova-bridge-suite' ), array( 'status' => 500 ) );
		}
		$params = $this->extract_payload_from_request( $request );

		$query_post_type = $request->get_param( 'post_type' );
		if ( ! empty( $query_post_type ) ) {
			$params['post_type'] = $query_post_type;
		}
		$result = $this->service->create_page( $params );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			array(
				'post_id' => (int) $result,
			),
			201
		);
	}

	/**
	 * Update an existing Elementor page.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		if ( ! $request instanceof WP_REST_Request ) {
			return new WP_Error( 'seor_eb_invalid_request', __( 'Invalid request object provided.', 'nova-bridge-suite' ), array( 'status' => 500 ) );
		}
		$post_id = (int) $request['id'];
		$params  = $this->extract_payload_from_request( $request );
		$result  = $this->service->update_page( $post_id, $params );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Extract JSON payload from request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	private function extract_payload_from_request( WP_REST_Request $request ) {
		$params = $request->get_json_params();

		if ( empty( $params ) ) {
			$params = array();
		}

		return $params;
	}

	/**
	 * Parse meta keys string into array.
	 *
	 * @param string|array|null $meta_keys Meta keys.
	 * @return array
	 */
	private function parse_meta_keys( $meta_keys ) {
		if ( empty( $meta_keys ) ) {
			return array();
		}

		if ( is_array( $meta_keys ) ) {
			return array_filter(
				array_map( 'sanitize_key', $meta_keys )
			);
		}

		return array_filter(
			array_map(
				'sanitize_key',
				array_map( 'trim', explode( ',', $meta_keys ) )
			)
		);
	}

	/**
	 * Parse comma separated IDs.
	 *
	 * @param string|null $ids IDs string.
	 * @return int[]
	 */
	private function parse_ids( $ids ) {
		if ( empty( $ids ) ) {
			return array();
		}

		$ids = array_map( 'trim', explode( ',', $ids ) );

		return array_filter(
			array_map(
				static function ( $value ) {
					return (int) $value;
				},
				$ids
			),
			static function ( $id ) {
				return $id > 0;
			}
		);
	}

	/**
	 * Normalize per_page argument.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	private function sanitize_per_page( $value ) {
		$per_page = (int) $value;
		if ( $per_page <= 0 ) {
			$per_page = 10;
		}

		return min( $per_page, 100 );
	}

	/**
	 * Normalize page number.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	private function sanitize_page( $value ) {
		$page = (int) $value;
		return max( $page, 1 );
	}

	/**
	 * Normalize order value.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function sanitize_order( $value ) {
		$order = strtoupper( (string) $value );
		return in_array( $order, array( 'ASC', 'DESC' ), true ) ? $order : 'DESC';
	}

	/**
	 * Normalize orderby value.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function sanitize_orderby( $value ) {
		$orderby = sanitize_key( (string) $value );
		$allowed = array( 'date', 'modified', 'title', 'name', 'id', 'menu_order' );

		return in_array( $orderby, $allowed, true ) ? $orderby : 'date';
	}

	/**
	 * Sanitize post status input.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function sanitize_status( $value ) {
		if ( empty( $value ) ) {
			return 'publish';
		}

		$status = sanitize_key( (string) $value );

		if ( 'any' === $status ) {
			return 'any';
		}

		$allowed_statuses = get_post_stati( array(), 'names' );

		return in_array( $status, $allowed_statuses, true ) ? $status : 'publish';
	}

	/**
	 * Sanitize post type input.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function sanitize_post_type( $value ) {
		if ( empty( $value ) ) {
			return 'page';
		}

		$post_type = sanitize_key( (string) $value );

		if ( 'any' === $post_type ) {
			return 'any';
		}

		return post_type_exists( $post_type ) ? $post_type : 'page';
	}

	/**
	 * Sanitize offset parameter.
	 *
	 * @param mixed $value Raw value.
	 * @return int|null
	 */
	private function sanitize_offset( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$offset = (int) $value;

		return max( $offset, 0 );
	}

	/**
	 * Determine if the request includes meaningful collection filters.
	 *
	 * @param array $context Filter context.
	 * @return bool
	 */
	private function has_collection_filters( array $context ) {
		$defaults = array(
			'offset'    => null,
			'page'      => 1,
			'per_page'  => 10,
			'search'    => '',
			'parent'    => null,
			'post_type' => 'page',
			'status'    => 'publish',
			'order'     => 'DESC',
			'orderby'   => 'date',
		);

		$normalized = array_merge( $defaults, $context );

		$has_filters = (
			null !== $normalized['offset'] ||
			(int) $normalized['page'] > 1 ||
			(int) $normalized['per_page'] !== (int) $defaults['per_page'] ||
			'' !== trim( (string) $normalized['search'] ) ||
			( null !== $normalized['parent'] && '' !== (string) $normalized['parent'] ) ||
			$normalized['post_type'] !== $defaults['post_type'] ||
			$normalized['status'] !== $defaults['status'] ||
			strtoupper( (string) $normalized['order'] ) !== $defaults['order'] ||
			$normalized['orderby'] !== $defaults['orderby']
		);

		return (bool) apply_filters( 'seor_eb_has_collection_filters', $has_filters, $normalized );
	}

	/**
	 * Convert to bool with default.
	 *
	 * @param mixed $value   Raw value.
	 * @param bool  $default Default.
	 * @return bool
	 */
	private function to_bool( $value, $default = false ) {
		if ( null === $value ) {
			return (bool) $default;
		}

		if ( is_bool( $value ) ) {
			return $value;
		}

		return in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * Validation schema for mutating routes.
	 *
	 * @return array
	 */
	private function get_validation_schema() {
		return array(
			'title'          => array(
				'type'        => 'string',
				'description' => __( 'Page title.', 'nova-bridge-suite' ),
			),
			'slug'           => array(
				'type'        => 'string',
				'description' => __( 'Page slug.', 'nova-bridge-suite' ),
			),
			'status'         => array(
				'type'        => 'string',
				'description' => __( 'Post status (draft, publish, etc).', 'nova-bridge-suite' ),
			),
			'post_type'      => array(
				'type'        => 'string',
				'description' => __( 'Post type for the Elementor document (defaults to page).', 'nova-bridge-suite' ),
			),
			'post_type'      => array(
				'type'        => 'string',
				'description' => __( 'Post type for the Elementor document (defaults to page).', 'nova-bridge-suite' ),
			),
			'author'         => array(
				'type'        => 'integer',
				'description' => __( 'Author user ID.', 'nova-bridge-suite' ),
			),
			'template'       => array(
				'type'        => 'string',
				'description' => __( 'Page template slug.', 'nova-bridge-suite' ),
			),
			'excerpt'        => array(
				'type'        => 'string',
				'description' => __( 'Optional excerpt.', 'nova-bridge-suite' ),
			),
			'parent'         => array(
				'type'        => 'integer',
				'description' => __( 'Parent page ID.', 'nova-bridge-suite' ),
			),
			'source_page_id' => array(
				'type'        => 'integer',
				'description' => __( 'Existing page ID to use as content blueprint.', 'nova-bridge-suite' ),
			),
			'elementor_data' => array(
				'description' => __( 'Full Elementor document (array or JSON string).', 'nova-bridge-suite' ),
			),
			'append_html'   => array(
				'type'        => 'string',
				'description' => __( 'Optional HTML content appended as a new text-editor section at the end of the document.', 'nova-bridge-suite' ),
			),
			'append_faqs'   => array(
				'type'        => 'array',
				'description' => __( 'Optional FAQ rows appended as an accordion widget in the same bottom section as append_html.', 'nova-bridge-suite' ),
				'items'       => array(
					'type'       => 'object',
					'properties' => array(
						'question' => array(
							'type'        => 'string',
							'description' => __( 'FAQ question text.', 'nova-bridge-suite' ),
						),
						'answer'   => array(
							'type'        => 'string',
							'description' => __( 'FAQ answer HTML/text.', 'nova-bridge-suite' ),
						),
					),
				),
			),
			'meta'          => array(
				'type'        => 'object',
				'description' => __( 'Post meta key/value pairs to update.', 'nova-bridge-suite' ),
			),
			'fields'         => array(
				'type'        => 'array',
				'description' => __( 'List of field mutations.', 'nova-bridge-suite' ),
				'items'       => array(
					'type'       => 'object',
					'properties' => array(
						'field_key'  => array(
							'type'        => 'string',
							'description' => __( 'Element identifier plus setting path (elementId|path.to.setting).', 'nova-bridge-suite' ),
						),
						'element_id' => array(
							'type'        => 'string',
							'description' => __( 'Element ID (used if field_key omitted).', 'nova-bridge-suite' ),
						),
						'path'       => array(
							'type'        => 'string',
							'description' => __( 'Dot notation path inside widget settings.', 'nova-bridge-suite' ),
						),
						'value'      => array(
							'description' => __( 'Value to assign (string or scalar).', 'nova-bridge-suite' ),
						),
					),
					'required'   => array( 'value' ),
				),
			),
			'publish_elementor' => array(
				'type'        => 'boolean',
				'description' => __( 'Force Elementor to publish refreshed content after applying changes.', 'nova-bridge-suite' ),
			),
		);
	}
}
