<?php
/** Actual Flatsome capture/compiler/publishing/UI-media paths with in-memory WordPress seams; no DB or HTTP. */
define( 'ABSPATH', __DIR__ );
const RECIPE_OPTION = 'nova_bridge_layout_recipes_v1';
$options = [ 'nova_bridge_suite_modules' => [ 'api_mapping_context' => true ], 'nova_bridge_posting_connection' => [ 'enabled' => false ], 'nova_mapping_draft_old' => [ 'keep' => 'unchanged' ] ];
$original_options = $options; $routes = []; $administrator = true; $nonce_valid = true; $checks = 0; $uuid_counter = 0; $writes = 0; $post_counter = 100; $native_fail = false; $acf_reject_save = false; $acf_example_only = false; $post_meta = []; $acf_values = []; $acf_reads = 0; $acf_writes = 0; $post_types = []; $actions = []; $filters = []; $current_user = 7; $denied_caps = [];
class WP_Error { private $code; private $message; private $data; function __construct( $code, $message, $data = [] ) { $this->code = $code; $this->message = $message; $this->data = $data; } function get_error_code() { return $this->code; } function get_error_message() { return $this->message; } function get_error_data() { return $this->data; } function add_data( $data ) { $this->data = $data; } }
class WP_REST_Response { public $headers = []; private $data; private $status; function __construct( $data, $status = 200 ) { $this->data = $data; $this->status = $status; } function get_data() { return $this->data; } function get_status() { return $this->status; } function set_status( $value ) { $this->status = $value; } function header( $name, $value ) { $this->headers[$name] = $value; } }
class WP_REST_Posts_Controller {}
class WP_Post { public $ID; public $post_type; public $post_title; public $post_content; public $post_status; public $post_author; public $post_excerpt = ''; public $post_name = ''; function __construct( array $data ) { foreach ( $data as $key => $value ) { $this->$key = $value; } } }
class WP_Post_Type { public $name; public $cap; public $_builtin = false; public $show_ui = true; public $public = true; public $publicly_queryable = true; public $exclude_from_search = false; function __construct( $name ) { $this->name = $name; $this->cap = (object) [ 'edit_posts' => 'edit_posts', 'create_posts' => 'edit_posts', 'publish_posts' => 'publish_posts' ]; } }
class RecipeRequest { private $params; private $body; private $headers; private $raw; function __construct( $params = [], $body = null, $headers = [ 'x-wp-nonce' => 'valid' ], $raw = null ) { $this->params = $params; $this->body = $body; $this->headers = $headers; $this->raw = $raw; } function get_param( $name ) { return $this->params[$name] ?? null; } function get_header( $name ) { return $this->headers[$name] ?? null; } function get_json_params() { return $this->body; } function get_body() { return $this->raw ?? json_encode( $this->body ); } }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function rest_ensure_response( $value ) { return $value instanceof WP_REST_Response ? $value : new WP_REST_Response( $value ); }
function add_action( $name, $callback, $priority = 10, $arguments = 1 ) { global $actions; $actions[$name][$priority][] = [ $callback, $arguments ]; }
function add_filter( $name, $callback, $priority = 10, $arguments = 1 ) { global $filters; $filters[$name][$priority][] = [ $callback, $arguments ]; }
function remove_hook( &$hooks, $name, $callback, $priority ) { $hooks[$name][$priority] = array_values( array_filter( $hooks[$name][$priority] ?? [], static function ( $entry ) use ( $callback ) { return $entry[0] !== $callback; } ) ); }
function remove_action( $name, $callback, $priority = 10 ) { global $actions; remove_hook( $actions, $name, $callback, $priority ); }
function remove_filter( $name, $callback, $priority = 10 ) { global $filters; remove_hook( $filters, $name, $callback, $priority ); }
function do_action( $name, ...$arguments ) { global $actions; $hooks = $actions[$name] ?? []; ksort( $hooks ); foreach ( $hooks as $entries ) { foreach ( $entries as $entry ) { call_user_func_array( $entry[0], array_slice( $arguments, 0, $entry[1] ) ); } } }
function apply_filters( $name, $value, ...$arguments ) { global $filters; $hooks = $filters[$name] ?? []; ksort( $hooks ); foreach ( $hooks as $entries ) { foreach ( $entries as $entry ) { $value = call_user_func_array( $entry[0], array_slice( array_merge( [ $value ], $arguments ), 0, $entry[1] ) ); } } return $value; }
function register_rest_route( $namespace, $route, $handlers ) { global $routes; $routes[$route] = $handlers; }
function current_user_can( $capability, ...$args ) { global $administrator, $denied_caps; return $administrator && ! in_array( $capability, $denied_caps, true ) && ( ! in_array( $capability, [ 'edit_post', 'edit_term' ], true ) || ( $args[0] ?? 0 ) !== 22 ); }
function wp_verify_nonce( $nonce, $action ) { global $nonce_valid; return $nonce_valid && $nonce === 'valid' && $action === 'wp_rest'; }
function wp_salt( $scheme ) { return 'isolated-test-' . $scheme; }
function get_current_user_id() { global $current_user; return $current_user; }
function get_option( $key, $default = false ) { global $options; return array_key_exists( $key, $options ) ? $options[$key] : $default; }
function add_option( $key, $value, $unused = '', $autoload = null ) { global $options; if ( array_key_exists( $key, $options ) ) { return false; } $options[$key] = $value; return true; }
function update_option( $key, $value, $autoload = null ) { global $options; $options[$key] = $value; return true; }
function delete_option( $key ) { global $options; unset( $options[$key] ); return true; }
function wp_cache_delete( ...$args ) {}
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function maybe_serialize( $value ) { return serialize( $value ); }
function wp_generate_uuid4() { global $uuid_counter; ++$uuid_counter; return sprintf( '%08x-1111-4111-8111-%012d', $uuid_counter, $uuid_counter ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
if ( ! function_exists( 'mb_substr' ) ) { function mb_substr( $value, $start, $length = null ) { $characters = preg_split( '//u', $value, -1, PREG_SPLIT_NO_EMPTY ); return implode( '', array_slice( $characters, $start, $length ) ); } }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
function esc_attr( $value ) { return esc_html( $value ); }
function esc_url( $value ) { return $value; }
function wp_kses_post( $value ) { return preg_replace( '~<script\b[^>]*>.*?</script>~si', '', $value ); }
function wp_slash( $value ) { return is_array( $value ) ? array_map( 'wp_slash', $value ) : ( is_string( $value ) ? addslashes( $value ) : $value ); }
function wp_unslash( $value ) { return is_array( $value ) ? array_map( 'wp_unslash', $value ) : ( is_string( $value ) ? stripslashes( $value ) : $value ); }
function get_post_type_object( $name ) { global $post_types; return $post_types[$name] ?? ( $post_types[$name] = new WP_Post_Type( $name ) ); }
function get_post_types( $args = [], $output = 'names' ) { global $post_types; $result = []; foreach ( $post_types as $name => $type ) { $matches = true; foreach ( $args as $key => $value ) { if ( ( $type->$key ?? null ) !== $value ) { $matches = false; } } if ( $matches ) { $result[$name] = $output === 'objects' ? $type : $name; } } return $result; }
function get_post_stati( $args = [], $output = 'names' ) { $statuses = [ 'draft', 'publish', 'private', 'pending', 'future', 'trash', 'auto-draft', 'editor-review' ]; return array_combine( $statuses, $statuses ); }
function post_type_supports( $type, $feature ) { return true; }
function get_registered_meta_keys( ...$args ) { return []; }
function get_page_template_slug( $id ) { return $GLOBALS['post_meta'][$id]['_wp_page_template'] ?? 'protected-template.php'; }
function get_post( $id ) { global $posts; if ( ! empty( $GLOBALS['forbidden_reference_ids'][$id] ) ) { throw new RuntimeException( 'Publication reread the removed source item' ); } return $posts[$id] ?? null; }
function get_post_field( $field, $id ) { $post = get_post( $id ); return $post ? ( $post->$field ?? '' ) : ''; }
function get_post_meta( $id, $key, $single = false ) { global $post_meta; if ( ! empty( $GLOBALS['forbidden_reference_ids'][$id] ) ) { throw new RuntimeException( 'Publication reread removed source metadata' ); } return $post_meta[$id][$key] ?? ''; }
function update_post_meta( $id, $key, $value ) { global $post_meta; $post_meta[$id][$key] = wp_unslash( $value ); return true; }
function delete_post_meta( $id, $key ) { global $post_meta; unset( $post_meta[$id][$key] ); return true; }
function wp_insert_post( $data, $error = false ) { global $posts, $post_counter, $writes, $native_fail; ++$writes; if ( $native_fail ) { return new WP_Error( 'native_failed', 'Native insertion rejected', [ 'status' => 500 ] ); } $data = wp_unslash( apply_filters( 'wp_insert_post_data', $data, $data, $data ) ); $id = ++$post_counter; $posts[$id] = new WP_Post( [ 'ID' => $id, 'post_type' => $data['post_type'] ?? 'page', 'post_title' => $data['post_title'] ?? '', 'post_content' => $data['post_content'] ?? '', 'post_status' => $data['post_status'] ?? 'draft', 'post_author' => $data['post_author'] ?? 7, 'post_name' => $data['post_name'] ?? '' ] ); foreach ( $data['meta_input'] ?? [] as $key => $value ) { update_post_meta( $id, $key, wp_slash( $value ) ); } do_action( 'wp_insert_post', $id, $posts[$id], false ); return $id; }
function wp_update_post( $data, $error = false ) { global $posts, $writes; ++$writes; $data = wp_unslash( $data ); $id = $data['ID']; if ( ! isset( $posts[$id] ) ) { return new WP_Error( 'missing', 'Native item missing', [ 'status' => 404 ] ); } foreach ( $data as $key => $value ) { if ( $key === 'meta_input' ) { foreach ( $value as $meta_key => $meta_value ) { update_post_meta( $id, $meta_key, wp_slash( $meta_value ) ); } } elseif ( $key === 'page_template' ) { update_post_meta( $id, '_wp_page_template', $value ); } else { $posts[$id]->$key = $value; } } return $id; }
function wp_delete_post( $id, $force = false ) { global $posts, $post_meta, $writes; ++$writes; unset( $posts[$id], $post_meta[$id] ); return true; }
function get_permalink( $id ) { return 'https://site.example/draft-' . $id; }
function get_edit_post_link( $id, $context = '' ) { return 'https://site.example/wp-admin/post.php?post=' . $id . '&action=edit'; }
function get_preview_post_link( $id ) { return 'https://site.example/draft-' . $id . '?preview=true'; }
function get_term( $id, $taxonomy = '' ) { return $id === 31 && in_array( $taxonomy, [ '', 'product_cat' ], true ) ? (object) [ 'term_id' => 31, 'taxonomy' => 'product_cat', 'name' => 'Category source', 'description' => '<p>Category body.</p>' ] : null; }
function wp_remote_request( ...$args ) { throw new RuntimeException( 'The template API must not call a backend.' ); }
function acf_get_field_groups( $screen ) { global $acf_example_only; return $acf_example_only && empty( $screen['post_id'] ) ? [] : [ [ 'key' => 'group_test', 'active' => true ] ]; }
function acf_get_fields( $group ) { global $acf_schema; return array_values( $acf_schema ); }
function acf_get_field_type( $type ) { return (object) [ 'name' => $type ]; }
function get_field( $key, $id, $formatted = true ) { global $acf_values, $acf_reads; ++$acf_reads; return $acf_values[$id][$key] ?? false; }
function update_field( $key, $value, $id ) { global $acf_values, $acf_writes, $acf_reject_save; ++$acf_writes; if ( $acf_reject_save ) { return false; } $acf_values[$id][$key] = $value; return true; }
function acf_flush_value_cache( ...$args ) {}
function is_email( $value ) { return filter_var( $value, FILTER_VALIDATE_EMAIL ) !== false; }
class RecipeDb { public $options = 'wp_options'; public $posts = 'wp_posts'; public $postmeta = 'wp_postmeta'; public $fail = false; public $race = null; function prepare( $sql, ...$args ) { return [ 'sql' => $sql, 'args' => $args ]; } function query( $statement ) { global $options; if ( $this->fail ) { return false; } if ( $this->race ) { $race = $this->race; $this->race = null; $race(); } [ $next, $key, $previous ] = $statement['args']; if ( ! isset( $options[$key] ) || serialize( $options[$key] ) !== $previous ) { return 0; } $options[$key] = unserialize( $next ); return 1; } function get_var( $statement ) { return 1; } }
class WP_Query { public $posts = []; function __construct( $args ) { global $posts, $post_meta; $types = $args['post_type'] ?? 'any'; if ( 'any' === $types ) { $types = get_post_types( [ 'exclude_from_search' => false ], 'names' ); } $types = (array) $types; foreach ( $posts as $id => $post ) { if ( ! in_array( $post->post_type, $types, true ) ) { continue; } if ( is_array( $args['post_status'] ?? null ) && ! in_array( $post->post_status, $args['post_status'], true ) ) { continue; } $matched = true; foreach ( $args['meta_query'] ?? [] as $query ) { if ( ! is_array( $query ) ) { continue; } if ( ( $post_meta[$id][$query['key']] ?? null ) !== $query['value'] ) { $matched = false; } } if ( $matched ) { $this->posts[] = $id; } } $this->posts = array_slice( $this->posts, 0, $args['posts_per_page'] ); } }
$wpdb = new RecipeDb();
$posts = [ 11 => new WP_Post( [ 'ID' => 11, 'post_type' => 'page', 'post_title' => 'Original page', 'post_content' => '<p>Original source — 🛰️</p>', 'post_status' => 'publish', 'post_author' => 7 ] ), 12 => new WP_Post( [ 'ID' => 12, 'post_type' => 'post', 'post_title' => 'Article', 'post_content' => 'Article body', 'post_status' => 'draft', 'post_author' => 7 ] ), 22 => new WP_Post( [ 'ID' => 22, 'post_type' => 'page', 'post_title' => 'Denied', 'post_content' => '', 'post_status' => 'draft', 'post_author' => 8 ] ) ];
function af( $name, $type = 'text', $extra = [] ) { static $field_counter = 0; return array_merge( [ 'key' => 'field_' . $name . '_' . ++$field_counter, 'name' => $name, 'label' => ucfirst( $name ), 'type' => $type, 'required' => false ], $extra ); }
$acf_schema = [ 'headline' => af( 'headline' ), 'stats' => af( 'stats', 'group', [ 'sub_fields' => [ af( 'score', 'number', [ 'required' => true, 'min' => 0, 'max' => 100 ] ), af( 'enabled', 'true_false' ) ] ] ), 'rows' => af( 'rows', 'repeater', [ 'sub_fields' => [ af( 'title' ), af( 'body', 'wysiwyg' ), af( 'items', 'repeater', [ 'sub_fields' => [ af( 'title' ), af( 'children', 'repeater', [ 'sub_fields' => [ af( 'title' ) ] ] ) ] ] ) ] ] ), 'matrix' => af( 'matrix', 'flexible_content', [ 'layouts' => [ [ 'key' => 'layout_copy', 'name' => 'copy', 'label' => 'Copy section', 'sub_fields' => [ af( 'heading' ), af( 'body', 'wysiwyg' ) ] ], [ 'key' => 'layout_faq', 'name' => 'faq', 'label' => 'FAQ section', 'sub_fields' => [ af( 'question' ), af( 'answer', 'wysiwyg' ) ] ] ] ] ) ];
$acf_schema['secondary'] = af( 'secondary', 'flexible_content', [ 'layouts' => [ [ 'key' => 'layout_secondary_faq', 'name' => 'faq', 'label' => 'Nested feature layout', 'sub_fields' => [ af( 'groups', 'repeater', [ 'sub_fields' => [ af( 'heading' ), af( 'items', 'repeater', [ 'sub_fields' => [ af( 'title' ), af( 'enabled', 'true_false' ) ] ] ) ] ] ) ] ] ] ] );
class Nova_Bridge_Suite_Document_Mapping { static function resources() { return [ [ 'id' => 'post_type:page', 'label' => 'Pages', 'type' => 'post_type', 'post_type' => 'page', 'targets' => [ [ 'reference_id' => 11, 'reference_type' => 'post', 'title' => 'Original page' ] ], 'creation_supported' => true ], [ 'id' => 'post_type:post', 'label' => 'Posts', 'type' => 'post_type', 'post_type' => 'post', 'targets' => [], 'creation_supported' => true ], [ 'id' => 'taxonomy:product_cat', 'label' => 'Product categories', 'type' => 'taxonomy', 'taxonomy' => 'product_cat', 'targets' => [], 'creation_supported' => true ], [ 'id' => 'post_type:nova_service', 'label' => 'NOVA Services', 'type' => 'post_type', 'post_type' => 'nova_service', 'system_managed' => true ], [ 'id' => 'post_type:product', 'label' => 'Products', 'type' => 'post_type', 'post_type' => 'product', 'coming_soon' => true ] ]; } }

// Test-only provider registry and WordPress shortcode parser; no theme or WP bootstrap.
define( 'UX_BUILDER_VERSION', '3.19-fixture' );
$core = getenv( 'NOVA_WP_CORE_FIXTURE' );
if ( ! $core || ! is_file( $core . '/shortcodes.php' ) ) { throw new RuntimeException( 'Set NOVA_WP_CORE_FIXTURE to the standalone WordPress parser fixture.' ); }
require_once $core . '/shortcodes.php';
class Flatsome_Integration_Registry {
    public function get( $tag ) {
        $options = $tag === 'title' ? [ 'text' => [ 'type' => 'textfield' ] ] : ( $tag === 'ux_image' ? [ 'id' => [ 'type' => 'image' ] ] : ( in_array( $tag, [ 'section', 'ux_banner' ], true ) ? [ 'bg' => [ 'type' => 'image' ] ] : [] ) );
        return in_array( $tag, [ 'section', 'row', 'col', 'ux_text', 'title', 'ux_image', 'ux_banner' ], true ) ? [ 'name' => $tag, 'options' => $options ] : null;
    }
}
function ux_builder_shortcodes() { return new Flatsome_Integration_Registry(); }
function ux_row() {}
function ux_col() {}
function get_ux_builder_post_types() { return [ 'post' => 'Posts', 'page' => 'Pages' ]; }
function metadata_exists( $type, $id, $key ) { return array_key_exists( $key, $GLOBALS['post_meta'][$id] ?? [] ); }
function clean_post_cache( $id ) {}
function get_post_status( $id ) { return get_post( $id )->post_status ?? false; }
function wp_attachment_is_image( $id ) { return $id >= 9000; }
function wp_get_attachment_metadata( $id ) { return [ 'width' => 600, 'height' => 400 ]; }
function wp_get_attachment_url( $id ) { return 'https://site.example/uploads/imported-' . $id . '.jpg'; }
$thumbnails = []; $media_rows = [];
function get_post_thumbnail_id( $id ) { return $GLOBALS['thumbnails'][$id] ?? 0; }
function set_post_thumbnail( $id, $attachment ) { $GLOBALS['thumbnails'][$id] = $attachment; return true; }
function delete_post_thumbnail( $id ) { unset( $GLOBALS['thumbnails'][$id] ); return true; }
function wp_safe_remote_get( ...$args ) { throw new RuntimeException( 'Integration fixture must never download anything.' ); }
class Flatsome_Integration_Db extends RecipeDb {
    public $last_error = '';
    public function get_col( $statement ) { $id = $GLOBALS['media_rows'][$statement['args'][1]] ?? 0; return $id ? [ $id ] : []; }
}
$wpdb = new Flatsome_Integration_Db();
function retain_image( $operation, $url, $id ) { $GLOBALS['media_rows']['nova.cpt-media/v1:' . hash( 'sha256', $operation . ':' . $url )] = $id; }
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-recipe-engine.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-content-transport.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-recipe-core.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-recipe-acf.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-recipe-elementor.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-recipe-builders.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-recipe-visual.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-layout-recipes.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-mapping-drafts.php';
require dirname( __DIR__, 2 ) . '/posting-service/includes/class-nova-bridge-suite-writing-adapter.php';
require dirname( __DIR__, 2 ) . '/posting-service/includes/class-nova-bridge-suite-stored-layout.php';
require dirname( __DIR__, 2 ) . '/posting-service/includes/class-nova-bridge-suite-stored-recipe-layout.php';
require dirname( __DIR__, 2 ) . '/posting-service/includes/class-nova-bridge-suite-stored-layout-provider.php';
Nova_Bridge_Suite_Recipe_Core::register(); Nova_Bridge_Suite_Recipe_ACF::register(); Nova_Bridge_Suite_Recipe_Builders::register(); Nova_Bridge_Suite_Layout_Recipes::routes();
function check( $value, $message ) { global $checks; ++$checks; if ( ! $value ) { throw new RuntimeException( $message ); } }
function good( $result ) { check( ! is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_message() : 'result' ); return $result; }
function route( $suffix, $method = 'GET', $params = [], $body = null ) { global $routes; $handlers = $routes['/mapping/layout-recipes/' . $suffix] ?? []; if ( isset( $handlers['callback'] ) ) { $handlers = [ $handlers ]; } foreach ( $handlers as $handler ) { if ( $handler['methods'] !== $method ) { continue; } $request = new RecipeRequest( $params, $body ); $allowed = call_user_func( $handler['permission_callback'], $request ); if ( $allowed !== true ) { return $allowed; } return call_user_func( $handler['callback'], $request ); } throw new RuntimeException( 'Missing route' ); }
function data( $result ) { good( $result ); check( $result instanceof WP_REST_Response, 'Expected REST response' ); return $result->get_data(); }
function default_identity( $resource ) { return Nova_Bridge_Suite_Layout_Recipes::defaults()[$resource] ?? null; }
function fitted( $values ) { $out = []; foreach ( $values as $path => $value ) { $out[Nova_Bridge_Suite_Writing_Adapter::field_id( $path )] = $value; } return $out; }

$source_markup = '[section bg="11" bg_size="cover" padding="70px"][row][col span="6"][title text="Reference heading"][ux_text]<p>Reference paragraph.</p>[/ux_text][ux_image id="12" width="80"][ux_image id="13"][/col][/row][/section]';
add_filter( 'ux_builder_meta_options', static function () { return [ '_footer' => [ 'type' => 'select', 'heading' => 'Footer' ], '_header' => [ 'type' => 'select', 'heading' => 'Header' ] ]; } );
$adapter = good( Nova_Bridge_Suite_Recipe_Engine::adapter( 'flatsome' ) );
$article_url = 'https://assets.example/article.jpg'; $background_url = 'https://assets.example/background.jpg';
foreach ( [ 'page' => 'service', 'post' => 'informative' ] as $post_type => $page_type ) {
    $reference_id = $post_type === 'page' ? 601 : 602;
    $posts[$reference_id] = new WP_Post( [ 'ID' => $reference_id, 'post_type' => $post_type, 'post_title' => 'UX reference', 'post_content' => $source_markup, 'post_status' => 'publish', 'post_author' => 7 ] );
    $post_meta[$reference_id]['_footer'] = 'disabled';
    $resource = 'post_type:' . $post_type;
    $design = data( route( 'design', 'POST', [], [ 'resource_id' => $resource, 'builder' => 'auto', 'reference_id' => $reference_id, 'mode' => 'static', 'manual_selection' => true ] ) );
    check( $design['builder'] === 'flatsome', 'Actual shortcode carrier auto-detects UX Builder for ' . $post_type );
    check( Nova_Bridge_Suite_Flatsome_Document::serialize( $design['blueprint']['document'] ) === $source_markup, 'Capture preserves reference shortcode structure exactly' );
    check( $design['blueprint']['meta']['_footer'] === [ 'present' => true, 'value' => 'disabled' ] && $design['blueprint']['meta']['_header']['present'] === false, 'Capture retains native page-design values and meaningful absence' );
    $field_map = array_column( $design['fields'], null, 'target' );
    $selected = []; $decorative_path = '';
    foreach ( $field_map as $path => $field ) {
        if ( $path === '/native_post/featured_image' ) { $selected['featured'] = $field; }
        elseif ( ( $field['native_media']['kind'] ?? '' ) === 'flatsome' ) {
            $value = Nova_Bridge_Suite_Recipe_Engine::get( $design['blueprint'], $path );
            if ( (string) $value === '11' ) { $selected['background'] = $field; }
            if ( (string) $value === '12' ) { $selected['image'] = $field; }
            if ( (string) $value === '13' ) { $decorative_path = $path; }
        } elseif ( str_ends_with( $path, '/attributes/text' ) ) { $selected['heading'] = $field; }
        elseif ( ( $field['format'] ?? '' ) === 'html' && str_contains( (string) ( $field['value'] ?? $field['current_value'] ?? '' ), 'Reference paragraph' ) ) { $selected['body'] = $field; }
    }
    check( count( $selected ) === 5 && $decorative_path !== '', 'Heading, body, image, background and featured controls are exposed distinctly' );
    $recipe = $design['recipe_preset']; $recipe['bindings'] = [];
    $content = [ 'heading' => 'Mapped heading', 'body' => 'Mapped paragraph with <strong>formatting</strong>.', 'image' => $article_url, 'background' => $background_url, 'featured' => $article_url ];
    foreach ( $selected as $purpose => $field ) {
        $binding = [ 'target' => $field['target'], 'source' => '/' . $purpose, 'format' => $field['format'], 'required' => empty( $field['native_media'] ), 'label' => ucfirst( $purpose ), 'instructions' => '' ];
        if ( ! empty( $field['native_media'] ) ) { $binding['native_media'] = true; }
        $recipe['bindings'][] = $binding;
    }
    $source = [ 'source_reference' => $design['source_reference'], 'source_receipt' => $design['source_receipt'] ];
    $preview = data( route( 'preview', 'POST', [], $source + [ 'resource_id' => $resource, 'recipe' => $recipe, 'seal' => $design['seal'], 'input' => [ 'content' => $content ] ] ) );
    $saved = data( route( 'save', 'POST', [], $source + [ 'resource_id' => $resource, 'recipe' => $recipe, 'seal' => $design['seal'], 'expected_revision' => 0, 'expected_default' => default_identity( $resource ), 'label' => 'UX image template', 'content' => $content, 'preview_receipt' => $preview['preview_receipt'] ] ) );
    check( $saved['posting_page_type'] === $page_type && ! empty( $saved['posting_support']['supported'] ), 'Normal save supports native ' . $post_type . ' with its correct NOVA page-type default' );

    $body = [ 'id' => $saved['id'], 'revision' => $saved['revision'], 'content' => $content, 'title' => 'UX native review', 'operation_id' => wp_generate_uuid4() ];
    retain_image( 'layout-draft:' . $body['operation_id'], $article_url, 9001 ); retain_image( 'layout-draft:' . $body['operation_id'], $background_url, 9002 );
    $before_count = count( $posts ); $result = data( route( 'draft', 'POST', [], $body ) );
    $native = get_post( $result['id'] );
    check( count( $posts ) === $before_count + 1 && $native->post_type === $post_type && $native->post_status === 'draft', 'Normal UI creates exactly one native destination draft' );
    check( str_contains( $native->post_content, 'bg="9002"' ) && str_contains( $native->post_content, 'id="9001"' ) && str_contains( $native->post_content, 'id="13"' ) && get_post_thumbnail_id( $result['id'] ) === 9001, 'UI draft applies imported background/image IDs and featured while retaining decorative image' );
    check( str_contains( $native->post_content, 'text="Mapped heading"' ) && str_contains( $native->post_content, 'Mapped paragraph with <strong>formatting</strong>.' ), 'UI draft carries fitted text and rich content without flattening the layout' );
    check( get_post_meta( $result['id'], '_footer', true ) === 'disabled' && ! metadata_exists( 'post', $result['id'], '_header' ) && get_page_template_slug( $result['id'] ) === 'protected-template.php', 'Native page settings and template survive the UI media save' );
    $before_writes = $writes; $again = data( route( 'draft', 'POST', [], $body ) );
    check( $again['id'] === $result['id'] && $writes === $before_writes, 'Completed UI operation reuses its draft without another write' );

    $draft = good( Nova_Bridge_Suite_Stored_Recipe_Layout::from_recipe( $saved ) );
    check( $draft['reference_id'] === 0 && $draft['profile_page_type'] === $page_type && $draft['routing']['post_type'] === $post_type, 'Publication uses a saved layout and generic native destination' );
    foreach ( [ 'image', 'background', 'featured' ] as $purpose ) {
        check( $draft['source_hints'][$selected[$purpose]['target']] === [ 'image_url', 'image_urls' ], 'Remote projection exposes image sources as URL hints' );
    }
    $values = [ '/title' => 'Published replacement' ]; foreach ( $selected as $purpose => $field ) { $values[$field['target']] = $content[$purpose]; }
    // The reference item is now unavailable. Compile/apply use only the saved layout.
    unset( $posts[$reference_id], $post_meta[$reference_id] ); $GLOBALS['forbidden_reference_ids'][$reference_id] = true;
    $compiled = good( Nova_Bridge_Suite_Stored_Recipe_Layout::compile( $draft, fitted( $values ), 'publish-' . $post_type ) );
    check( Nova_Bridge_Suite_Recipe_Engine::get( [ 'document' => $compiled['writer_payload']['flatsome_document'] ], $selected['image']['target'] ) === '12', 'Frozen payload keeps template image before runtime import' );
    $operation = 'publish-' . $post_type; retain_image( $operation, $article_url, 9003 ); retain_image( $operation, $background_url, 9004 );
    good( Nova_Bridge_Suite_Stored_Layout_Provider::apply( 'flatsome', $result['id'], $compiled['writer_payload'], $compiled['post_fields'], $operation ) );
    check( str_contains( $native->post_content, 'bg="9004"' ) && str_contains( $native->post_content, 'id="9003"' ) && get_post_thumbnail_id( $result['id'] ) === 9003, 'Saved publication payload updates image/background/featured on native ' . $post_type );
    $snapshot = [ $options, $post_meta, $thumbnails, $writes ]; good( Nova_Bridge_Suite_Stored_Layout_Provider::verify( 'flatsome', $result['id'], $compiled['writer_payload'], $operation ) );
    check( $snapshot === [ $options, $post_meta, $thumbnails, $writes ], 'Publication verification remains read-only and uses retained images' );

    $optional_values = $values; foreach ( [ 'image', 'background', 'featured' ] as $purpose ) { $optional_values[$selected[$purpose]['target']] = ''; }
    $optional = good( Nova_Bridge_Suite_Stored_Recipe_Layout::compile( $draft, fitted( $optional_values ), 'optional-' . $post_type ) );
    check( empty( $optional['writer_payload']['nova_media'] ) && str_contains( Nova_Bridge_Suite_Flatsome_Document::serialize( $optional['writer_payload']['flatsome_document'] ), 'id="12"' ), 'Empty optional fitted images retain original reference assets' );
    $skip_op = 'skip-' . $post_type;
    foreach ( [ $article_url, $background_url ] as $url ) { $options['nova_cpt_media_' . hash( 'sha256', $skip_op . ':' . $url )] = [ 'state' => 'skipped' ]; }
    $thumbnails[$result['id']] = 9010;
    good( Nova_Bridge_Suite_Stored_Layout_Provider::apply( 'flatsome', $result['id'], $compiled['writer_payload'], $compiled['post_fields'], $skip_op ) );
    check( str_contains( $native->post_content, 'bg="11"' ) && str_contains( $native->post_content, 'id="12"' ) && get_post_thumbnail_id( $result['id'] ) === 9010, 'Known skipped optional imports preserve template assets and current featured image' );
    good( Nova_Bridge_Suite_Stored_Layout_Provider::verify( 'flatsome', $result['id'], $compiled['writer_payload'], $skip_op ) );
}
echo "PASS $checks Flatsome mapping/media/UI/provider integration checks (memory only, no DB/HTTP).\n";
