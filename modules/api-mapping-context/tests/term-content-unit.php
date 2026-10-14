<?php
/** Real term schema/ACF transport with isolated native persistence and callback seams. */
define( 'ABSPATH', __DIR__ );
$checks = 0; $registered = []; $term_values = []; $terms = []; $denied = []; $writes = 0; $options = []; $routes = []; $fail_add_once = false; $acf_reject = false; $admin = true;
$term_sanitizers = []; $term_write_mutator = null;
$GLOBALS['wp_rest_additional_fields'] = [];
class WP_Error { private $code; private $message; private $data; function __construct( $code, $message, $data = [] ) { $this->code = $code; $this->message = $message; $this->data = $data; } function get_error_code() { return $this->code; } function get_error_message() { return $this->message; } function get_error_data() { return $this->data; } }
class WP_REST_Response { private $data; public $headers = []; function __construct( $data ) { $this->data = $data; } function get_data() { return $this->data; } function header( $name, $value ) { $this->headers[$name] = $value; } }
class WP_REST_Posts_Controller {}
class WP_Post {}
class WP_Post_Type {}
class WP_REST_Request {
    private $body; private $headers; public $params = [];
    function __construct( $method = 'GET', $params = [], $body = null, $headers = [] ) { $this->params = $params; $this->body = $body; $this->headers = $headers; }
    function set_param( $key, $value ) { $this->params[$key] = $value; }
    function get_param( $key ) { return $this->params[$key] ?? null; }
    function get_header( $key ) { return $this->headers[$key] ?? null; }
    function get_body() { return json_encode( $this->body ); }
    function get_json_params() { return $this->body; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function rest_ensure_response( $value ) { return new WP_REST_Response( $value ); }
function register_rest_route( $namespace, $route, $handlers ) { $GLOBALS['routes'][$namespace . $route] = $handlers; }
function add_action( ...$arguments ) {}
function add_filter( ...$arguments ) {}
function current_user_can( $cap, ...$arguments ) { return $GLOBALS['admin'] && ! in_array( $cap . ':' . implode( ':', $arguments ), $GLOBALS['denied'], true ); }
function wp_verify_nonce( $nonce, $action ) { return 'valid' === $nonce && in_array( $action, [ 'wp_rest', 'wccbp_save_term' ], true ); }
function wp_salt( $scheme ) { return 'term-unit-' . $scheme; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function get_option( $key, $default = false ) { return $GLOBALS['options'][$key] ?? $default; }
function get_taxonomy( $taxonomy ) { return 'product_cat' === $taxonomy ? (object) [ 'name' => $taxonomy, 'public' => true, 'publicly_queryable' => true, 'show_ui' => true ] : null; }
function get_term( $id, $taxonomy = '' ) { $term = $GLOBALS['terms'][$id] ?? null; return $term && ( '' === $taxonomy || $term->taxonomy === $taxonomy ) ? $term : null; }
function get_registered_meta_keys( $type, $subtype = '' ) { return $GLOBALS['registered'][$type . ':' . $subtype] ?? []; }
function register_term_meta( $taxonomy, $key, $args ) { $GLOBALS['registered']['term:' . $taxonomy][$key] = $args + [ 'single' => false, 'type' => 'string', 'show_in_rest' => false, 'description' => '' ]; }
function taxonomy_exists( $taxonomy ) { return 'product_cat' === $taxonomy; }
function register_rest_field( $taxonomy, $key, $args ) { $GLOBALS['wp_rest_additional_fields'][$taxonomy][$key] = $args; }
function metadata_exists( $type, $id, $key ) { return array_key_exists( $key, $GLOBALS['term_values'][$id] ?? [] ); }
function get_term_meta( $id, $key = '', $single = false ) { $values = $GLOBALS['term_values'][$id] ?? []; if ( '' === $key ) { return $values; } return array_key_exists( $key, $values ) ? ( $single ? ( $values[$key][0] ?? '' ) : $values[$key] ) : ( $single ? '' : [] ); }
function wp_slash( $value ) { return is_array( $value ) ? array_map( 'wp_slash', $value ) : ( is_string( $value ) ? addslashes( $value ) : $value ); }
function wp_unslash( $value ) { return is_array( $value ) ? array_map( 'wp_unslash', $value ) : ( is_string( $value ) ? stripslashes( $value ) : $value ); }
function stored( $value ) { return is_bool( $value ) ? ( $value ? '1' : '' ) : ( is_int( $value ) || is_float( $value ) ? (string) $value : $value ); }
function update_term_meta( $id, $key, $value ) { ++$GLOBALS['writes']; $value = stored( wp_unslash( $value ) ); $same = get_term_meta( $id, $key, true ) === $value; $GLOBALS['term_values'][$id][$key] = [ $value ]; return ! $same; }
function delete_term_meta( $id, $key ) { ++$GLOBALS['writes']; unset( $GLOBALS['term_values'][$id][$key] ); return true; }
function add_term_meta( $id, $key, $value ) { ++$GLOBALS['writes']; $value = wp_unslash( $value ); if ( $GLOBALS['fail_add_once'] && 'reject' === $value ) { $GLOBALS['fail_add_once'] = false; return false; } $GLOBALS['term_values'][$id][$key][] = stored( $value ); return 1; }
function sanitize_term_field( $field, $value, $id, $taxonomy, $context ) { $filter = $GLOBALS['term_sanitizers'][$field] ?? null; return is_callable( $filter ) ? call_user_func( $filter, $value, $id, $taxonomy, $context ) : $value; }
function wp_update_term( $id, $taxonomy, $fields ) { ++$GLOBALS['writes']; foreach ( $fields as $key => $value ) { $GLOBALS['terms'][$id]->$key = wp_unslash( sanitize_term_field( $key, $value, $id, $taxonomy, 'db' ) ); } if ( is_callable( $GLOBALS['term_write_mutator'] ) ) { call_user_func( $GLOBALS['term_write_mutator'], $GLOBALS['terms'][$id] ); } return [ 'term_id' => $id ]; }
function sanitize_meta( $key, $value, $type, $taxonomy = '' ) { $registration = array_merge( get_registered_meta_keys( $type ), get_registered_meta_keys( $type, $taxonomy ) )[$key] ?? []; return is_callable( $registration['sanitize_callback'] ?? null ) ? call_user_func( $registration['sanitize_callback'], $value ) : $value; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function wp_kses_post( $value ) { return preg_replace( '~<script\b[^>]*>.*?</script>~is', '', $value ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return esc_html( $value ); }
function is_email( $value ) { return false !== filter_var( $value, FILTER_VALIDATE_EMAIL ); }
function acf_get_field_groups( $screen ) { return [ [ 'key' => 'group_category', 'active' => true ] ]; }
function acf_get_fields( $group ) { return array_values( $GLOBALS['acf_schema'] ); }
function acf_get_field_type( $type ) { return (object) [ 'name' => $type ]; }
function get_field( $key, $id, $formatted = true ) { return $GLOBALS['acf_values'][$id][$key] ?? false; }
function normalize_acf( $value, array $field ) {
    if ( 'group' === $field['type'] ) { $out = []; foreach ( $field['sub_fields'] as $child ) { $out[$child['name']] = normalize_acf( $value[$child['key']] ?? $value[$child['name']], $child ); } return $out; }
    if ( 'repeater' === $field['type'] ) { $out = []; foreach ( $value as $row ) { $out[] = normalize_acf( $row, [ 'type' => 'group', 'sub_fields' => $field['sub_fields'] ] ); } return $out; }
    return $value;
}
function update_field( $key, $value, $id ) { ++$GLOBALS['writes']; if ( $GLOBALS['acf_reject'] ) { return false; } foreach ( $GLOBALS['acf_schema'] as $field ) { if ( $field['key'] === $key ) { $GLOBALS['acf_values'][$id][$key] = normalize_acf( $value, $field ); return true; } } return false; }
function acf_flush_value_cache( ...$args ) {}
function field( $name, $type = 'text', $extra = [] ) { return [ 'key' => 'field_' . $name, 'name' => $name, 'label' => $name, 'type' => $type ] + $extra; }
function check( $value, $label ) { ++$GLOBALS['checks']; if ( ! $value ) { throw new RuntimeException( $label ); } }
function good( $value, $label ) { check( ! is_wp_error( $value ), $label . ( is_wp_error( $value ) ? ': ' . $value->get_error_message() : '' ) ); return $value; }
function rejected( $value, $status, $label ) { check( is_wp_error( $value ) && ( $value->get_error_data()['status'] ?? 0 ) === $status, $label . ( is_wp_error( $value ) ? ': ' . $value->get_error_message() : '' ) ); }
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-recipe-engine.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-content-transport.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-recipe-term-fields.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-recipe-core.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-recipe-acf.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-term-content.php';
Nova_Bridge_Suite_Recipe_Core::register(); Nova_Bridge_Suite_Recipe_ACF::register();
use Nova_Bridge_Suite_Term_Content as Content;
use Nova_Bridge_Suite_Recipe_Engine as Engine;
$resource = [ 'id' => 'taxonomy:product_cat', 'type' => 'taxonomy', 'taxonomy' => 'product_cat' ];
$terms[31] = (object) [ 'term_id' => 31, 'taxonomy' => 'product_cat', 'name' => 'Original', 'description' => '<p>Original top.</p>' ];
$term_values[31] = [ 'content_below_products' => [ '<p>Original bottom.</p>' ], 'score' => [ '4' ], 'legacy_html' => [ '<p>Legacy editorial copy.</p>' ], 'multi_text' => [ 'one', 'two' ], 'cards' => [ [ [ 'title' => 'Card', 'body' => '<p>Card body.</p>' ] ] ], '_secret_token' => [ 'never expose' ], 'api_key' => [ 'never expose key' ] ];
register_term_meta( 'product_cat', 'score', [ 'single' => true, 'type' => 'integer', 'show_in_rest' => false ] );
register_term_meta( 'product_cat', 'multi_text', [ 'single' => false, 'type' => 'string', 'show_in_rest' => false ] );
register_term_meta( 'product_cat', 'cards', [ 'single' => true, 'type' => 'array', 'show_in_rest' => [ 'schema' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'properties' => [ 'title' => [ 'type' => 'string' ], 'body' => [ 'type' => 'string', 'format' => 'html' ] ], 'required' => [ 'title', 'body' ], 'additionalProperties' => false ], 'maxItems' => 2 ] ] ] );
$GLOBALS['wp_rest_additional_fields']['product_cat']['content_below_products'] = [ 'get_callback' => static function ( $object ) { return get_term_meta( $object['id'], 'content_below_products', true ); }, 'update_callback' => static function ( $value, $term, $key, $request, $taxonomy ) { check( $term->term_id === 31 && $key === 'content_below_products' && $request->get_param( 'context' ) === 'edit' && $taxonomy === 'product_cat', 'Native additional setter receives the real term and edit context.' ); return update_term_meta( $term->term_id, $key, wp_slash( $value ) ); }, 'schema' => [ 'type' => 'string', 'context' => [ 'view', 'edit' ] ] ];
$acf_schema = [ 'headline' => field( 'headline', 'text', [ 'max_length' => 20 ] ), 'stats' => field( 'stats', 'group', [ 'sub_fields' => [ field( 'label' ), field( 'enabled', 'true_false' ) ] ] ), 'rows' => field( 'rows', 'repeater', [ 'min' => 1, 'max' => 2, 'sub_fields' => [ field( 'question' ), field( 'answer', 'wysiwyg' ) ] ] ) ];
$acf_values['product_cat_31'] = [ 'field_headline' => 'Original headline', 'field_stats' => [ 'label' => 'Original label', 'enabled' => 1 ], 'field_rows' => [ [ 'question' => 'Original?', 'answer' => '<p>Original answer.</p>' ] ] ];
Content::routes();
check( count( $routes ) === 1 && count( reset( $routes ) ) === 2 && false === strpos( key( $routes ), 'create' ), 'Term transport registered an unintended create/public route.' );
$read = good( Content::read( 'product_cat', 31 ), 'Registered and ACF term values can be read.' );
check( $read['meta_all']['score'] === 4 && $read['meta_all']['multi_text'] === [ 'one', 'two' ] && $read['content_below_products'] === '<p>Original bottom.</p>', 'Readback lost typed hidden fields, multiple metadata values or separate bottom content.' );
check( $read['meta_all']['acf']['stats']['enabled'] === true && ! isset( $read['meta_all']['legacy_html'] ) && false === strpos( json_encode( $read ), 'never expose' ), 'Term read exposed undeclared/private data or lost ACF types.' );
$request = new WP_REST_Request( 'GET', [ 'taxonomy' => 'product_cat', 'id' => '31' ], null, [ 'x-wp-nonce' => 'valid' ] );
check( Content::can_access( $request ) === true && Content::get( $request )->headers['Cache-Control'] === 'private, no-store', 'Authenticated term responses can be shared or cached.' );
$admin = false; rejected( Content::can_access( $request ), 403, 'Anonymous term inspection was accepted.' ); $admin = true;
rejected( Content::can_access( new WP_REST_Request( 'GET', [ 'taxonomy' => 'product_cat', 'id' => 31 ], null, [ 'x-wp-nonce' => 'expired' ] ) ), 403, 'An expired cookie-auth nonce was accepted.' );
rejected( Content::apply( 'product_cat', 0, [] ), 404, 'The transport created a category instead of requiring an existing item.' );
rejected( Content::apply( 'product_tag', 31, [] ), 404, 'Term transport crossed the taxonomy boundary.' );
$before = serialize( [ $terms, $term_values, $acf_values ] ); $before_writes = $writes;
rejected( Content::apply( 'product_cat', 31, [ 'name' => 'Must not save', 'meta_all' => [ 'score' => '5' ] ] ), 422, 'Invalid typed metadata reached native setters.' );
rejected( Content::apply( 'product_cat', 31, [ 'description' => 'Must not save', 'meta_all' => [ 'legacy_html' => 'Undeclared' ] ] ), 422, 'A legacy database key was authorised without an approved profile.' );
rejected( Content::apply( 'product_cat', 31, [ 'meta' => [ 'api_key' => 'Secret' ] ] ), 422, 'A private/configuration key was accepted.' );
rejected( Content::apply( 'product_cat', 31, [ 'name' => '<b></b>' ] ), 422, 'A sanitized empty category name was accepted.' );
rejected( Content::apply( 'product_cat', 31, [ 'meta' => [ 'cards' => [ [ 'title' => 'X', 'body' => 'Y', 'unexpected' => 'Z' ] ] ] ] ), 422, 'An undeclared nested property reached native setters.' );
rejected( Content::apply( 'product_cat', 31, [ 'name' => 'Must not save', 'meta_all' => [ 'acf' => [ 'rows' => [] ] ] ] ), 422, 'ACF cardinality was checked after native content writes.' );
rejected( Content::apply( 'product_cat', 31, [ 'meta_all' => [ 'acf' => [ 'stats' => [ 'label' => 'Incomplete' ] ] ] ] ), 400, 'ACF complete-parent sibling validation was bypassed.' );
check( $writes === $before_writes && $before === serialize( [ $terms, $term_values, $acf_values ] ), 'A preflight rejection modified existing category state.' );
$registered['term:product_cat']['score']['sanitize_callback'] = static function ( $value ) { return (string) $value; };
rejected( Content::apply( 'product_cat', 31, [ 'name' => 'Must not save', 'meta_all' => [ 'score' => 9 ] ] ), 422, 'A schema-breaking native sanitizer was detected only after writes.' );
check( $writes === $before_writes, 'A failed sanitizer mutated the native category.' ); unset( $registered['term:product_cat']['score']['sanitize_callback'] );
$result = good( Content::apply( 'product_cat', 31, [ 'description' => '<p>New top.</p>', 'content_below_products' => '<p>New bottom.</p>', 'meta_all' => [ 'score' => 7, 'multi_text' => [ 'first', 'second', 'third' ] ], 'meta' => [ 'cards' => [ [ 'title' => 'New card', 'body' => '<p>New body.</p>' ] ] ] ] ), 'All native declared term transports apply and verify.' );
check( $terms[31]->description === '<p>New top.</p>' && $result['content']['content_below_products'] === '<p>New bottom.</p>' && $result['content']['name'] === 'Original', 'Separate top/bottom updates changed an omitted category title.' );
check( $result['content']['meta_all']['score'] === 7 && $result['content']['meta_all']['multi_text'] === [ 'first', 'second', 'third' ] && $result['content']['meta']['cards'][0]['title'] === 'New card', 'Typed hidden, multiple or nested term values did not survive actual setters.' );
// Native term description filters permit fewer tags than post-content KSES.
$term_sanitizers['description'] = static function ( $value, $id, $taxonomy, $context ) { check( $id === 31 && $taxonomy === 'product_cat' && $context === 'db', 'Expected native description uses the real term database-filter context.' ); return wp_slash( strip_tags( wp_unslash( $value ), '<strong><a>' ) ); };
$term_sanitizers['name'] = static function ( $value ) { return wp_slash( wp_unslash( $value ) . ' suffix' ); };
$filtered_input = '<p>Client\'s "quoted" C:\\flooring <strong>top copy</strong>.</p>';
$filtered = good( Content::apply( 'product_cat', 31, [ 'name' => 'Filtered category', 'description' => $filtered_input ] ), 'Predictable installed core term sanitation is accepted after readback.' );
check( $filtered['content']['description'] === strip_tags( $filtered_input, '<strong><a>' ) && $filtered['content']['name'] === 'Filtered category suffix', 'Native description tags/slashes or a non-idempotent name filter were applied incorrectly.' );
$term_sanitizers = [];
$term_write_mutator = static function ( $term ) { $term->description = 'Unexpected setter-time change'; };
$unexpected = Content::apply( 'product_cat', 31, [ 'description' => '<p>Expected description.</p>' ] ); rejected( $unexpected, 500, 'A setter-time plugin mutation bypassed actual native readback.' );
check( $unexpected->get_error_data()['write_status'] === 'uncertain', 'An unpredictable native filter mutation claimed atomic success.' ); $term_write_mutator = null;
$acf_update = good( Content::apply( 'product_cat', 31, [ 'meta_all' => [ 'acf' => [ 'stats' => [ 'label' => 'Updated label', 'enabled' => false ], 'rows' => [ [ 'question' => 'One?', 'answer' => '<p>First.</p>' ], [ 'question' => 'Two?', 'answer' => '<p>Second.</p>' ] ] ] ] ] ), 'Schema-owned ACF category fields apply and verify.' );
check( $acf_update['content']['meta_all']['acf']['stats']['enabled'] === false && count( $acf_update['content']['meta_all']['acf']['rows'] ) === 2 && $acf_update['content']['meta_all']['acf']['headline'] === 'Original headline', 'ACF nested rows or omitted roots were lost.' );
$acf_reject = true; $failed = Content::apply( 'product_cat', 31, [ 'meta_all' => [ 'acf' => [ 'headline' => 'Rejected headline' ] ] ] ); rejected( $failed, 500, 'Rejected ACF persistence was reported as successful.' ); check( $failed->get_error_data()['write_status'] === 'uncertain', 'A partial provider write claimed atomicity.' ); $acf_reject = false;
$prior_multi = get_term_meta( 31, 'multi_text', false ); $fail_add_once = true;
$failed = Content::apply( 'product_cat', 31, [ 'meta_all' => [ 'multi_text' => [ 'replacement', 'reject' ] ] ] ); rejected( $failed, 500, 'A failed multi-value replacement was reported as successful.' );
check( $failed->get_error_data()['failed_field_restored'] && get_term_meta( 31, 'multi_text', false ) === $prior_multi, 'A failed multi-value replacement destroyed its original complete values.' );
$original_setter = $GLOBALS['wp_rest_additional_fields']['product_cat']['content_below_products']['update_callback'];
$GLOBALS['wp_rest_additional_fields']['product_cat']['content_below_products']['update_callback'] = static function ( $value, $term ) { update_term_meta( $term->term_id, 'content_below_products', wp_slash( $value ) ); return new WP_Error( 'plugin_failed', 'A plugin failed after saving.' ); };
$failed = Content::apply( 'product_cat', 31, [ 'description' => '<p>Saved before callback failure.</p>', 'content_below_products' => '<p>Setter partially saved.</p>' ] );
rejected( $failed, 500, 'A partial native callback failure was reported as successful.' );
check( $failed->get_error_data()['write_status'] === 'uncertain' && $failed->get_error_data()['applied_fields'] === [ 'description' ] && $terms[31]->description === '<p>Saved before callback failure.</p>', 'A partial setter failure concealed previously saved changes.' );
$GLOBALS['wp_rest_additional_fields']['product_cat']['content_below_products']['update_callback'] = $original_setter;

// A confirmed legacy declaration is authorised only by an immutable scoped mapping seal.
$profile_id = '12345678-1111-4111-8111-123456789012';
$term_values[31]['_inspace_wc_cat_below'] = [ '<p>Private editorial lower field.</p>' ];
$term_values[31]['_inspace_wc_cat_buttons'] = [ json_encode( [ [ 'label' => 'Original button', 'url' => '/original/' ] ] ) ];
$button_schema = [ 'type' => 'array', 'x-nova-storage' => 'json', 'items' => [ 'type' => 'object', 'properties' => [ 'label' => [ 'type' => 'string' ], 'url' => [ 'type' => 'string', 'format' => 'uri-reference' ] ], 'required' => [ 'label', 'url' ], 'additionalProperties' => false ], 'maxItems' => 2 ];
$denied[] = 'edit_term_meta:31:_inspace_wc_cat_below';
$design = good( Nova_Bridge_Suite_Recipe_Core::design( $resource, 31, 'static', [ 'legacy_html' => [ 'type' => 'string', 'format' => 'html' ], '_inspace_wc_cat_below' => [ 'type' => 'string', 'format' => 'html' ], '_inspace_wc_cat_buttons' => $button_schema ] ), 'A local category declaration can be captured.' );
$recipe = [ 'format' => Engine::FORMAT, 'builder' => 'core', 'mode' => 'static', 'blueprint' => $design['blueprint'], 'bindings' => [], 'regions' => [], 'instructions' => '' ];
$record = [ 'id' => $profile_id, 'revision' => 1, 'resource_id' => $resource['id'], 'builder' => 'core', 'recipe' => $recipe, 'seal' => hash_hmac( 'sha256', Engine::json( [ 'core', $recipe['blueprint'] ] ), wp_salt( 'auth' ) ) ];
$options['nova_bridge_layout_recipes_v1'] = [ 'format' => 'nova-layout-recipes-store/v1', 'profiles' => [ $profile_id => [ 1 => $record ] ], 'defaults' => [] ];
$context = [ 'profile_id' => $profile_id, 'revision' => 1 ];
$legacy = good( Content::apply( 'product_cat', 31, [ 'meta_all' => [ 'legacy_html' => '<p>Approved replacement.</p>', '_inspace_wc_cat_below' => '<p>Approved private editorial copy.</p>' ] ], $context ), 'A saved approved local schema authorises legacy term content.' );
check( $legacy['content']['meta_all']['legacy_html'] === '<p>Approved replacement.</p>', 'A approved local custom field did not survive the native setter/readback.' );
check( $legacy['content']['meta_all']['_inspace_wc_cat_below'] === '<p>Approved private editorial copy.</p>', 'Explicit local editorial approval did not authorise its otherwise protected field.' );
rejected( Content::apply( 'product_cat', 31, [ 'meta_all' => [ '_inspace_wc_cat_below' => 'Unapproved protected field' ] ] ), 422, 'A protected editorial field was writable without its approved sealed profile.' );
$denied = [];
$json_value = json_encode( [ [ 'label' => 'Client\'s "quoted" C:\\flooring button', 'url' => '/contact/' ], [ 'label' => 'Second', 'url' => '/second/' ] ] );
$json_result = good( Content::apply( 'product_cat', 31, [ 'meta_all' => [ '_inspace_wc_cat_buttons' => $json_value ] ], $context ), 'Confirmed JSON-string storage accepts schema-validated structured values.' );
check( is_string( get_term_meta( 31, '_inspace_wc_cat_buttons', true ) ) && json_decode( get_term_meta( 31, '_inspace_wc_cat_buttons', true ), true ) === json_decode( $json_value, true ) && $json_result['content']['meta_all']['_inspace_wc_cat_buttons'] === get_term_meta( 31, '_inspace_wc_cat_buttons', true ), 'JSON custom fields changed storage type or lost quoted/backslash button content.' );
$before_writes = $writes;
rejected( Content::apply( 'product_cat', 31, [ 'name' => 'Must not save', 'meta_all' => [ '_inspace_wc_cat_buttons' => '[invalid' ] ], $context ), 422, 'Malformed JSON custom content reached native writers.' );
rejected( Content::apply( 'product_cat', 31, [ 'meta_all' => [ '_inspace_wc_cat_buttons' => json_encode( [ [ 'label' => 'X', 'url' => '/x/', 'unknown' => 'Extra' ] ] ) ] ], $context ), 422, 'A confirmed JSON codec bypassed closed-object schema validation.' );
check( $writes === $before_writes, 'Rejected JSON custom fields changed existing category values.' );
$terms[32] = (object) [ 'term_id' => 32, 'taxonomy' => 'product_cat', 'name' => 'New empty category', 'description' => '' ]; $term_values[32] = [];
$source_before = serialize( $term_values[31] );
rejected( Content::apply( 'product_cat', 32, [ 'meta_all' => [ 'legacy_html' => 'Unapproved initialization' ] ] ), 422, 'A new category initialized undeclared legacy keys without saved mapping approval.' );
$initialized = good( Content::apply( 'product_cat', 32, [ 'meta_all' => [ 'legacy_html' => '<p>New category content.</p>', '_inspace_wc_cat_below' => '<p>New private editorial content.</p>', '_inspace_wc_cat_buttons' => $json_value ] ], $context ), 'Approved blank mappings can initialize their known legacy fields on a newly created category.' );
check( $initialized['content']['meta_all']['legacy_html'] === '<p>New category content.</p>' && $initialized['content']['meta_all']['_inspace_wc_cat_below'] === '<p>New private editorial content.</p>' && json_decode( $initialized['content']['meta_all']['_inspace_wc_cat_buttons'], true ) === json_decode( $json_value, true ), 'An approved blank mapping did not populate all native target storage formats.' );
check( $source_before === serialize( $term_values[31] ), 'Initializing a new category modified the original example category.' );
$route_initialized = Content::update( new WP_REST_Request( 'PATCH', [ 'taxonomy' => 'product_cat', 'id' => 32 ], [ 'profile_id' => $profile_id, 'revision' => 1, 'meta_all' => [ '_inspace_wc_cat_buttons' => '[]' ] ] ) );
check( $route_initialized instanceof WP_REST_Response && $route_initialized->get_data()['content']['meta_all']['_inspace_wc_cat_buttons'] === '[]', 'The REST route did not separate exact profile controls from native JSON payload fields.' );
$before_writes = $writes;
$options['nova_bridge_layout_recipes_v1']['profiles'][$profile_id][1]['seal'] = 'forged'; rejected( Content::apply( 'product_cat', 31, [ 'name' => 'Must not save', 'meta_all' => [ 'legacy_html' => 'Forged' ] ], $context ), 409, 'A forged profile authorised native content mutation.' );
$options['nova_bridge_layout_recipes_v1']['profiles'][$profile_id][1] = $record;
rejected( Content::apply( 'product_cat', 31, [ 'meta_all' => [ 'legacy_html' => 'Wrong version' ] ], [ 'profile_id' => $profile_id, 'revision' => 2 ] ), 409, 'A nonexistent mapping revision authorised local fields.' );
$options['nova_bridge_layout_recipes_v1']['profiles'][$profile_id][1]['resource_id'] = 'taxonomy:other'; rejected( Content::read( 'product_cat', 31, $context ), 409, 'A mapping belonging to another destination revealed local fields.' );
$options['nova_bridge_layout_recipes_v1']['profiles'][$profile_id][1] = $record;
$denied[] = 'edit_term_meta:31:score'; rejected( Content::apply( 'product_cat', 31, [ 'meta_all' => [ 'score' => 9 ] ] ), 422, 'Denied registered metadata reached its setter.' ); $denied = [];
check( $writes === $before_writes, 'Profile/permission rejection occurred after native writes.' );
$over_limit = new WP_REST_Request( 'POST', [ 'taxonomy' => 'product_cat', 'id' => 31 ], [ 'description' => str_repeat( 'x', 2097153 ) ] ); rejected( Content::update( $over_limit ), 413, 'The REST term-content payload size limit was bypassed.' );
$bad_body = new WP_REST_Request( 'POST', [ 'taxonomy' => 'product_cat', 'id' => 31 ], [ [ 'description' => 'Bad array' ] ] ); rejected( Content::update( $bad_body ), 422, 'The term route accepted a top-level list.' );
$final = Content::update( new WP_REST_Request( 'PATCH', [ 'taxonomy' => 'product_cat', 'id' => 31 ], [ 'content_below_products' => '' ] ) ); check( $final instanceof WP_REST_Response && $final->get_data()['content']['content_below_products'] === '' && $final->headers['Cache-Control'] === 'private, no-store', 'The REST update route did not explicitly clear only selected bottom content.' );

// Exercise the actual WooCommerce module's JSON and WordPress form slash contracts.
require dirname( __DIR__, 2 ) . '/woocommerce/wc-content-below-products.php';
WCCBP_Content_Below_Products::register_term_meta(); WCCBP_Content_Below_Products::register_rest_field();
$quoted = <<<'HTML'
<p>Client's "quoted" flooring; C:\flooring\oak; literal \"quote\".</p><script>removeMe()</script>
HTML;
$clean_quoted = wp_kses_post( $quoted );
WCCBP_Content_Below_Products::rest_update_field( $quoted, [ 'id' => 31 ] );
check( WCCBP_Content_Below_Products::rest_get_field( [ 'id' => 31 ] ) === $clean_quoted, 'The real WooCommerce JSON callback lost quote/backslash content or skipped HTML sanitization.' );
$quoted_result = good( Content::apply( 'product_cat', 31, [ 'content_below_products' => $quoted ] ), 'The real WooCommerce additional-field adapter applies and verifies quoted content.' );
check( $quoted_result['content']['content_below_products'] === $clean_quoted, 'The term bridge and real WooCommerce callback applied extra unslashing.' );
$_POST = [ 'wccbp_nonce' => 'valid', 'content_below_products' => wp_slash( $quoted ) ]; WCCBP_Content_Below_Products::save_term_field( 31 );
check( get_term_meta( 31, 'content_below_products', true ) === $clean_quoted, 'The real category form handler lost once-unslashed quote/backslash content.' );
$before_writes = $writes; $_POST['wccbp_nonce'] = 'invalid'; WCCBP_Content_Below_Products::save_term_field( 31 );
$_POST['wccbp_nonce'] = 'valid'; $denied[] = 'edit_term:31'; WCCBP_Content_Below_Products::save_term_field( 31 ); $denied = [];
check( $writes === $before_writes, 'The WooCommerce form fix changed existing nonce/capability checks.' );
$_POST = [ 'wccbp_nonce' => 'valid' ]; WCCBP_Content_Below_Products::save_term_field( 31 ); check( $writes === $before_writes, 'An omitted category form field erased saved bottom content.' );
$_POST = [ 'wccbp_nonce' => 'valid', 'content_below_products' => '' ]; WCCBP_Content_Below_Products::save_term_field( 31 ); check( get_term_meta( 31, 'content_below_products', true ) === '', 'The real category form cannot explicitly clear bottom content.' );
$_POST = [];
echo 'PASS ' . $checks . " term content transport checks.\n";
