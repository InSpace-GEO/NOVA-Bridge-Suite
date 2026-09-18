<?php
/** Standalone only: php rest-guidance-unit.php. No WordPress/database/site access. */
if ( defined( 'ABSPATH' ) ) { throw new RuntimeException( 'Run this test directly with PHP, outside WordPress.' ); }
define( 'ABSPATH', __DIR__ . '/' );

$options = [];
$hooks = [];
$routes = [];
$settings = [];
$wp_rest_additional_fields = [];
$logged_in = true;
$can_edit = true;
$checks = 0;
function check( $condition, string $message ): void {
	global $checks;
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	++$checks;
}
function get_option( $name, $default = false ) { return $GLOBALS['options'][ $name ] ?? $default; }
function add_option( $name, $value, $deprecated = '', $autoload = 'yes' ) { $GLOBALS['options'][ $name ] = $value; }
function register_setting( $group, $name, $args ) { $GLOBALS['settings'][ $name ] = $args; }
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['hooks'][ $hook ][ $priority ][] = $callback; }
function add_action( $hook, $callback, $priority = 10, $args = 1 ) { add_filter( $hook, $callback, $priority, $args ); }
function apply_filters( $hook, $value, ...$args ) {
	$callbacks = $GLOBALS['hooks'][ $hook ] ?? [];
	ksort( $callbacks );
	foreach ( $callbacks as $entries ) { foreach ( $entries as $callback ) { $value = $callback( $value, ...$args ); } }
	return $value;
}
function register_rest_route( $namespace, $route, $args ) { $GLOBALS['routes'][ '/' . $namespace . $route ] = $args; }
function register_rest_field( $type, $name, $args ) { $GLOBALS['wp_rest_additional_fields'][ $type ][ $name ] = $args; }
function __( $value, $domain = '' ) { return $value; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) ); }
function sanitize_title_with_dashes( $value ) { return sanitize_key( $value ); }
function absint( $value ) { return abs( (int) $value ); }
function is_user_logged_in() { return $GLOBALS['logged_in']; }
function current_user_can( $capability, ...$args ) { return $GLOBALS['can_edit']; }
function post_type_exists( $type ) { return isset( $GLOBALS['post_types'][ $type ] ); }
function get_post_type_object( $type ) { return $GLOBALS['post_types'][ $type ] ?? null; }
function get_post_types( $args = [], $format = 'names' ) { return 'objects' === $format ? $GLOBALS['post_types'] : array_keys( $GLOBALS['post_types'] ); }
function get_post( $id ) { return $GLOBALS['posts'][ $id ] ?? null; }
function taxonomy_exists( $type ) { return 'product_cat' === $type; }
function get_taxonomy( $type ) { return null; }
function get_term( $id, $taxonomy ) { return new WP_Term( $id ); }
function get_registered_meta_keys( $type, $subtype = '' ) { return []; }
function nova_bridge_suite_get_managed_blog_post_types() { return [ 'client_blog' ]; }
function nova_bridge_suite_get_managed_blog_rest_bases() { return [ 'client-articles' ]; }
function nova_bridge_suite_get_service_page_rest_base() { return 'services'; }

class WP_Error {
	private $code;
	public function __construct( $code, $message, $data = [] ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
}
class WP_Post {
	public $ID;
	public $post_type;
	public function __construct( $id, $type ) { $this->ID = $id; $this->post_type = $type; }
}
class WP_Term {
	public $term_id;
	public function __construct( $id ) { $this->term_id = $id; }
}
class WP_Post_Type {
	public $name;
	public $show_in_rest = true;
	public $rest_base;
	public $rest_namespace = 'wp/v2';
	public function __construct( $name, $base ) { $this->name = $name; $this->rest_base = $base; }
}
class WP_REST_Request extends ArrayObject {
	private $method;
	private $route;
	public function __construct( $method = 'GET', $route = '/wp/v2/pages/1' ) { parent::__construct( [ 'context' => 'edit' ] ); $this->method = $method; $this->route = $route; }
	public function get_param( $key ) { return $this[ $key ] ?? null; }
	public function get_method() { return $this->method; }
	public function get_route() { return $this->route; }
}
class WP_REST_Response {
	private $data;
	public function __construct( $data ) { $this->data = $data; }
	public function get_data() { return $this->data; }
	public function set_data( $data ) { $this->data = $data; }
	public function get_status() { return 200; }
}
class WP_REST_Server {
	public const READABLE = 'GET';
	public function get_routes() { return [ '/example/v1/content' => [] ]; }
}
class WP_REST_Posts_Controller {
	protected $post_type;
	protected $namespace;
	protected $rest_base;
	public function __construct( $type ) { $this->post_type = $type; }
	public function prepare_item_for_response( $item, $request ) { return new WP_REST_Response( [ 'id' => $item->ID, 'title' => 'Unchanged content', 'meta' => [], 'acf' => [] ] ); }
}
class Suite_Module_Marker {}
class_alias( Suite_Module_Marker::class, 'SEORAI\\ServicePageCPT\\Plugin' );
class_alias( Suite_Module_Marker::class, 'SEORAI\\BodycleanCPT\\Plugin' );

require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-content-context.php';
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-strategy.php';
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-content-transport.php';

$post_types = [ 'page' => new WP_Post_Type( 'page', 'pages' ), 'service_page' => new WP_Post_Type( 'service_page', 'services' ), 'client_blog' => new WP_Post_Type( 'client_blog', 'client-articles' ) ];
$posts = [ 1 => new WP_Post( 1, 'page' ), 2 => new WP_Post( 2, 'service_page' ), 3 => new WP_Post( 3, 'client_blog' ) ];
$resources = [];
foreach ( array_keys( $post_types ) as $type ) {
	$resources[ 'post_type:' . $type ] = [ 'id' => 'post_type:' . $type, 'enabled' => true, 'type' => 'post_type', 'post_type' => $type, 'fields' => [ '/title' => [ 'description' => 'Private title guidance', 'mapping' => 'h1' ] ] ];
}
$resources['taxonomy:product_cat'] = [ 'enabled' => true, 'type' => 'taxonomy', 'taxonomy' => 'product_cat', 'fields' => [ '/name' => [ 'description' => 'Private category guidance', 'mapping' => 'h1' ] ] ];
$resources['route:example'] = [ 'enabled' => true, 'type' => 'route', 'route' => '/example/v1/content', 'fields' => [ '/title' => [ 'description' => 'Private route guidance' ] ] ];
// Supply an already normalized saved configuration without mocking the response code.
$cache = new ReflectionProperty( Nova_Bridge_Suite_Content_Context::class, 'config_cache' );
if ( PHP_VERSION_ID < 80100 ) { $cache->setAccessible( true ); }
$cache->setValue( null, [ 'version' => 4, 'resources' => $resources ] );
$suite_callback = static function () { return [ 'native' => 'Suite-owned guidance' ]; };
foreach ( [ 'service_page', 'client_blog' ] as $type ) { $wp_rest_additional_fields[ $type ]['meta_descriptions'] = [ 'get_callback' => $suite_callback, 'schema' => [ 'type' => 'object' ] ]; }
$suite_registrations = $wp_rest_additional_fields;

check( ! Nova_Bridge_Suite_Content_Context::rest_guidance_enabled(), 'Optional guidance must default off even with saved context.' );
Nova_Bridge_Suite_Content_Context::register_settings();
check( ! isset( $settings[ Nova_Bridge_Suite_Content_Context::GUIDANCE_OPTION ] ), 'Generic REST guidance cannot be enabled through settings.' );
foreach ( [ false, 0, '0', 'false', 'true', 'yes', [], null ] as $value ) { check( ! Nova_Bridge_Suite_Content_Context::sanitize_guidance( $value ), 'Only explicit boolean opt-in is accepted.' ); }
foreach ( [ true, 1, '1' ] as $value ) { check( Nova_Bridge_Suite_Content_Context::sanitize_guidance( $value ), 'Explicit option values enable guidance.' ); }
Nova_Bridge_Suite_Content_Context::register_rest_api();
Nova_Bridge_Suite_Strategy::register_prepare_filters();
check( [] === $hooks, 'Default-off setup must not register post/term decoration hooks.' );
check( $suite_registrations === $wp_rest_additional_fields, 'Default-off setup must preserve suite fields and add no generic helper fields.' );
check( isset( $routes['/nova-bridge/v1/content-endpoints'], $routes['/nova-bridge/v1/content-endpoints/bridge-fields'] ), 'Discovery and field inspection routes must remain registered.' );
check( [ Nova_Bridge_Suite_Content_Context::class, 'can_discover_resources' ] === $routes['/nova-bridge/v1/content-endpoints']['permission_callback'], 'Discovery must keep its authorization callback.' );
check( true === Nova_Bridge_Suite_Content_Context::can_discover_resources(), 'Administrators can still discover fields.' );
$logged_in = false;
check( 'nova_content_context_unauthenticated' === Nova_Bridge_Suite_Content_Context::can_discover_resources()->get_error_code(), 'Discovery must still reject anonymous callers.' );
$logged_in = true;
$can_edit = false;
check( 'nova_content_context_forbidden' === Nova_Bridge_Suite_Content_Context::can_discover_resources()->get_error_code(), 'Discovery must still reject non-administrators.' );
$can_edit = true;
$request = new WP_REST_Request();
$original = [ 'id' => 1, 'title' => 'Unchanged content', 'meta_descriptions' => [ 'native' => 'Other module value' ] ];
$response = new WP_REST_Response( $original );
Nova_Bridge_Suite_Content_Context::filter_post_type_response( $response, $posts[1], $request );
Nova_Bridge_Suite_Content_Context::filter_product_category_response( $response, new WP_Term( 1 ), $request );
Nova_Bridge_Suite_Content_Context::filter_rest_post_dispatch( $response, new WP_REST_Server(), new WP_REST_Request( 'POST', '/example/v1/content' ) );
check( $original === $response->get_data(), 'All default-off response filters must preserve the entire existing response.' );
check( $original === Nova_Bridge_Suite_Strategy::decorate_record( $original, 'post', 1 ), 'Direct strategy decoration must also be disabled.' );
$controller = new Nova_Bridge_Suite_Content_Controller( 'page' );
$transport = $controller->prepare_item_for_response( $posts[1], $request )->get_data();
check( [] === array_intersect( [ 'meta_descriptions', 'nova_content_mappings', 'nova_template_contexts', 'nova_strategy_context' ], array_keys( $transport ) ), 'The hidden-content transport must omit every optional guidance field by default.' );
check( 'Unchanged content' === $transport['title'] && isset( $transport['meta_all'], $transport['nova_transport'] ), 'Disabling guidance must preserve native content and writer metadata.' );

$options[ Nova_Bridge_Suite_Content_Context::GUIDANCE_OPTION ] = true;
Nova_Bridge_Suite_Content_Context::register_rest_api();
Nova_Bridge_Suite_Strategy::register_prepare_filters();
check( [] === $hooks, 'A previously saved opt-in cannot enable generic response hooks.' );
check( ! isset( $wp_rest_additional_fields['page']['meta_descriptions'], $wp_rest_additional_fields['page']['nova_content_mappings'] ), 'A previously saved opt-in cannot expose mappings.' );
foreach ( [ 'service_page', 'client_blog' ] as $type ) { check( $suite_registrations[ $type ] === $wp_rest_additional_fields[ $type ], 'Opt-in must not replace or extend suite-owned CPT fields.' ); }
Nova_Bridge_Suite_Content_Context::filter_post_type_response( $response, $posts[1], $request );
check( $original === $response->get_data(), 'Saved generic guidance stays out of authenticated page responses.' );
$transport = $controller->prepare_item_for_response( $posts[1], $request )->get_data();
check( ! isset( $transport['nova_content_mappings'], $transport['meta_descriptions'] ), 'Hidden-content responses keep generic guidance disabled.' );
$term_response = new WP_REST_Response( [ 'id' => 1, 'name' => 'Category' ] );
Nova_Bridge_Suite_Content_Context::filter_product_category_response( $term_response, new WP_Term( 1 ), $request );
check( ! isset( $term_response->get_data()['meta_descriptions'] ), 'Category guidance stays out of REST responses.' );
$route_response = new WP_REST_Response( [ 'title' => 'Route content' ] );
Nova_Bridge_Suite_Content_Context::filter_rest_post_dispatch( $route_response, new WP_REST_Server(), new WP_REST_Request( 'POST', '/example/v1/content' ) );
check( ! isset( $route_response->get_data()['meta_descriptions'] ), 'Generic route guidance stays out of REST responses.' );
foreach ( [ 2, 3 ] as $id ) {
	$suite_response = new WP_REST_Response( $original );
	Nova_Bridge_Suite_Content_Context::filter_post_type_response( $suite_response, $posts[ $id ], $request );
	check( $original === $suite_response->get_data(), 'Generic guidance must leave suite-owned CPT responses unchanged.' );
	check( ! Nova_Bridge_Suite_Content_Transport::supports_post_type( $posts[ $id ]->post_type ), 'Generic transport must not claim suite-owned CPTs.' );
}
$options[ Nova_Bridge_Suite_Content_Context::GUIDANCE_OPTION ] = false;
$response = new WP_REST_Response( $original );
Nova_Bridge_Suite_Content_Context::filter_post_type_response( $response, $posts[1], $request );
check( $original === $response->get_data(), 'A previously registered response filter must stop injecting after opt-out.' );
echo 'PASS ' . $checks . " disabled mapping REST guidance and preserved CPT context checks.\n";
