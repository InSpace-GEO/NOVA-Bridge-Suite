<?php
/** Real presentation route, capture seals and fresh-source validation; no persistence. */
define( 'ABSPATH', __DIR__ );
$routes = []; $checks = 0; $writes = 0; $captures = 0; $administrator = true; $theme = 'test-theme'; $controls_unavailable = false;
$posts = [
    11 => (object) [ 'ID' => 11, 'post_type' => 'page', 'post_title' => 'Example one' ],
    12 => (object) [ 'ID' => 12, 'post_type' => 'page', 'post_title' => 'Example two' ],
    13 => (object) [ 'ID' => 13, 'post_type' => 'post', 'post_title' => 'Article example' ],
];
$post_meta = [ 11 => [ 'content-layout' => 'full-width', 'header-overlay' => '1', 'editorial_cache' => 'ignore', 'api_token' => 'ignore', 'unregistered-layout' => 'ignore' ], 12 => [ 'content-layout' => 'boxed' ], 13 => [] ];
$registered = [
    'content-layout' => [ 'single' => true, 'type' => 'string', 'description' => 'Content layout', 'show_in_rest' => [ 'schema' => [ 'type' => 'string', 'enum' => [ 'full-width', 'boxed' ] ] ] ],
    'header-overlay' => [ 'single' => true, 'type' => 'boolean', 'description' => 'Overlay header', 'show_in_rest' => true ],
    'layout-inherit' => [ 'single' => true, 'type' => 'string', 'description' => 'Inherited layout', 'show_in_rest' => true ],
    'editorial_cache' => [ 'single' => true, 'type' => 'string', 'description' => 'Cache', 'show_in_rest' => true ],
    'api_token' => [ 'single' => true, 'type' => 'string', 'description' => 'Token', 'show_in_rest' => true ],
];
$blueprint = [ 'document' => [ [ 'id' => 'a123456', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Protected example title', 'width' => '100%', 'responsive' => [ 'mobile' => 'stacked' ] ], 'elements' => [] ] ], 'meta' => [ 'background_color' => '#123456' ], 'template' => '' ];
$blueprints = [ 11 => $blueprint, 12 => $blueprint, 13 => $blueprint ];
class WP_Error {
    private $code; private $message; private $data;
    function __construct( $code, $message, $data = [] ) { $this->code = $code; $this->message = $message; $this->data = $data; }
    function get_error_code() { return $this->code; }
    function get_error_message() { return $this->message; }
    function get_error_data() { return $this->data; }
}
class WP_REST_Response {
    public $headers = []; private $data;
    function __construct( $data ) { $this->data = $data; }
    function get_data() { return $this->data; }
    function header( $name, $value ) { $this->headers[$name] = $value; }
}
class PresentationRequest {
    private $body; private $nonce;
    function __construct( $body, $nonce = 'valid' ) { $this->body = $body; $this->nonce = $nonce; }
    function get_body() { return json_encode( $this->body ); }
    function get_json_params() { return $this->body; }
    function get_header( $name ) { return 'x-wp-nonce' === $name ? $this->nonce : null; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function rest_ensure_response( $value ) { return new WP_REST_Response( $value ); }
function current_user_can( $cap, ...$args ) { global $administrator; return $administrator; }
function wp_verify_nonce( $nonce, $action ) { return 'valid' === $nonce && 'wp_rest' === $action; }
function wp_salt( $scheme ) { return 'presentation-isolated-fixture-' . $scheme; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
if ( ! function_exists( 'mb_substr' ) ) { function mb_substr( $value, $start, $length = null ) { $characters = preg_split( '//u', $value, -1, PREG_SPLIT_NO_EMPTY ); return implode( '', array_slice( $characters, $start, $length ) ); } }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
function register_rest_route( $namespace, $route, $handlers ) { global $routes; $routes[$route] = $handlers; }
function get_post( $id ) { global $posts; return $posts[$id] ?? null; }
function get_post_type( $id ) { $post = get_post( $id ); return $post ? $post->post_type : false; }
function get_permalink( $id ) { return 'https://site.example/example-' . $id . '/'; }
function get_template() { global $theme; return $theme; }
function get_registered_meta_keys( $type, $subtype = '' ) { global $registered; return 'post' === $type && 'page' === $subtype ? $registered : []; }
function metadata_exists( $type, $id, $key ) { global $post_meta; return 'post' === $type && array_key_exists( $key, $post_meta[$id] ?? [] ); }
function get_post_meta( $id, $key, $single = false ) { global $post_meta; return $post_meta[$id][$key] ?? ''; }
function forbidden_write() { global $writes; ++$writes; throw new RuntimeException( 'Capture and selection must not write.' ); }
function add_option( ...$args ) { forbidden_write(); }
function update_option( ...$args ) { forbidden_write(); }
function delete_option( ...$args ) { forbidden_write(); }
function set_transient( ...$args ) { forbidden_write(); }
function update_post_meta( ...$args ) { forbidden_write(); }
function delete_post_meta( ...$args ) { forbidden_write(); }
function wp_insert_post( ...$args ) { forbidden_write(); }
function wp_update_post( ...$args ) { forbidden_write(); }
function wp_remote_request( ...$args ) { throw new RuntimeException( 'Capture and selection must not call external services.' ); }
class Nova_Bridge_Suite_Document_Mapping {
    static function resources() { return [ [ 'id' => 'post_type:page', 'type' => 'post_type', 'post_type' => 'page' ], [ 'id' => 'post_type:post', 'type' => 'post_type', 'post_type' => 'post' ] ]; }
}
class Nova_Bridge_Suite_Recipe_ACF { static function companion_design( ...$args ) { return null; } }
/** Only the native provider reader is a fixture: sealing, selection and freshness are real. */
class Nova_Bridge_Suite_Recipe_Builders {
    static function capture( $builder, $id, $include_document_settings = true ) {
        global $blueprints, $captures, $controls_unavailable; ++$captures;
        if ( $include_document_settings && $controls_unavailable ) { return new WP_Error( 'unsupported_settings', 'Document settings require review' ); }
        if ( ! isset( $blueprints[$id] ) ) { return new WP_Error( 'missing_example', 'Example missing' ); }
        $blueprint = $blueprints[$id]; if ( ! $include_document_settings ) { unset( $blueprint['document_settings'] ); }
        return self::describe( $builder, $blueprint );
    }
    static function describe( $builder, $blueprint ) {
        if ( isset( $blueprint['theme_presentation'] ) ) { $checked = Nova_Bridge_Suite_Theme_Presentation::validate( $blueprint['theme_presentation'] ); if ( is_wp_error( $checked ) ) { return $checked; } }
        return [ 'blueprint' => $blueprint, 'fields' => [ [ 'target' => '/document/0/settings/title', 'label' => 'Heading', 'kind' => 'title', 'format' => 'text', 'value' => $blueprint['document'][0]['settings']['title'], 'writable' => true, 'required' => true, 'native_node_id' => 'a123456' ] ], 'containers' => [] ];
    }
}
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-recipe-engine.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-theme-presentation.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-recipe-visual.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-layout-recipes.php';
Nova_Bridge_Suite_Layout_Recipes::routes();
function check( $value, $message ) { global $checks; ++$checks; if ( ! $value ) { throw new RuntimeException( $message ); } }
function route( $suffix, $body, $nonce = 'valid' ) {
    global $routes; $handler = $routes['/mapping/layout-recipes/' . $suffix]; $request = new PresentationRequest( $body, $nonce );
    $permission = call_user_func( $handler['permission_callback'], $request );
    return true === $permission ? call_user_func( $handler['callback'], $request ) : $permission;
}
function data( $result ) {
    check( $result instanceof WP_REST_Response, is_wp_error( $result ) ? $result->get_error_message() : 'Expected REST response.' );
    check( ( $result->headers['Cache-Control'] ?? '' ) === 'private, no-store', 'Capture response must not be shared or cached.' ); return $result->get_data();
}
function rejected( $result, $message, $status = null ) { check( is_wp_error( $result ) && ( null === $status || ( $result->get_error_data()['status'] ?? null ) === $status ), $message ); }
function capture_design( $id = 11, $resource = 'post_type:page' ) { return data( route( 'design', [ 'resource_id' => $resource, 'builder' => 'elementor', 'mode' => 'static', 'reference_id' => $id, 'manual_selection' => true ] ) ); }
function selection( array $design, array $keys ) {
    return [ 'resource_id' => $design['source_reference']['resource_id'], 'mode' => 'static', 'design' => $design, 'presentation_choices' => $design['presentation_choices'], 'presentation_seal' => $design['presentation_seal'], 'source_reference' => $design['source_reference'], 'source_receipt' => $design['source_receipt'], 'selected_keys' => $keys ];
}
$initial_meta = $post_meta; $initial_blueprints = $blueprints;
check( isset( $routes['/mapping/layout-recipes/presentation'] ) && $routes['/mapping/layout-recipes/presentation']['methods'] === 'POST', 'Missing presentation route.' );
$design = capture_design();
$legacy_body = selection( $design, [] ); $legacy_recipe = $design['recipe_preset']; $legacy_resource = [ 'id' => 'post_type:page', 'type' => 'post_type', 'post_type' => 'page' ];
$blueprints[11]['document_settings'] = [ 'background_color' => '#123456' ];
check( ! is_wp_error( Nova_Bridge_Suite_Recipe_Visual::validate_source( $legacy_body, $legacy_recipe, $legacy_resource ) ), 'A pre-feature source receipt remains valid when only the newly captured optional settings field is added.' );
$controls_unavailable = true;
check( ! is_wp_error( Nova_Bridge_Suite_Recipe_Visual::validate_source( $legacy_body, $legacy_recipe, $legacy_resource ) ), 'A legacy signed source does not invoke the unsupported new presentation reader.' );
check( is_wp_error( route( 'design', [ 'resource_id' => 'post_type:page', 'builder' => 'elementor', 'mode' => 'static', 'reference_id' => 11, 'manual_selection' => true ] ) ), 'A fresh design capture must still reject unsupported presentation settings.' );
$controls_unavailable = false;
$blueprints[11]['document'][0]['settings']['title'] = 'Changed after capture';
check( is_wp_error( Nova_Bridge_Suite_Recipe_Visual::validate_source( $legacy_body, $legacy_recipe, $legacy_resource ) ), 'Legacy receipt projection still rejects changes to the originally captured document.' );
$blueprints[11] = $initial_blueprints[11];
check( ! isset( $design['blueprint']['theme_presentation'] ), 'Capture silently selected page settings.' );
check( array_keys( $design['presentation_choices']['inventory']['fields'] ) === [ 'content-layout', 'header-overlay', 'layout-inherit' ], 'Inventory exposed unregistered or excluded settings.' );
check( [] === $design['recipe_preset']['bindings'], 'Manual capture selected content fields implicitly.' );
$request = selection( $design, [ 'content-layout', 'header-overlay', 'layout-inherit' ] );
$before_captures = $captures; $selected = data( route( 'presentation', $request ) );
check( $captures > $before_captures, 'Selection did not re-read the source.' );
$snapshot = $selected['blueprint']['theme_presentation'];
check( $snapshot['meta']['content-layout']['value'] === 'full-width' && $snapshot['meta']['header-overlay']['value'] === true, 'Selected values differ from typed captured metadata.' );
check( $snapshot['meta']['layout-inherit']['present'] === false && ! array_key_exists( 'value', $snapshot['meta']['layout-inherit'] ), 'Unset source metadata was confused with an explicit value.' );
$selected_base = $selected['blueprint']; unset( $selected_base['theme_presentation'] );
check( $selected_base === $design['blueprint'], 'Selection changed native content, styling or template.' );
check( $selected['source_reference']['native_digest'] !== $design['source_reference']['native_digest'] && $selected['source_receipt'] !== $design['source_receipt'], 'The selected snapshot was not included in provenance.' );
$resource = Nova_Bridge_Suite_Document_Mapping::resources()[0];
$source = Nova_Bridge_Suite_Recipe_Visual::validate_source( selection( $selected, [] ), $selected['recipe_preset'], $resource );
check( ! is_wp_error( $source ) && $source['reference_id'] === 11, 'A freshly selected snapshot failed normal save provenance.' );
$cleared = data( route( 'presentation', selection( $selected, [] ) ) );
check( $cleared['blueprint'] === $design['blueprint'], 'Deselecting settings changed the native design or retained stale selections.' );
$post_meta[11]['header-overlay'] = '0';
$layout_only = data( route( 'presentation', selection( $design, [ 'content-layout' ] ) ) );
check( array_keys( $layout_only['blueprint']['theme_presentation']['meta'] ) === [ 'content-layout' ], 'An unselected metadata change blocked selection or was copied implicitly.' );
$post_meta[11] = $initial_meta[11];

$bad = $request; $bad['presentation_seal'] = str_repeat( '0', 64 ); rejected( route( 'presentation', $bad ), 'Unsigned inventory accepted.', 409 );
$bad = $request; $bad['presentation_choices']['inventory']['fields']['content-layout']['value'] = 'boxed'; rejected( route( 'presentation', $bad ), 'Client-tampered captured value accepted.', 409 );
$bad = $request; $bad['presentation_choices']['inventory']['theme'] = 'other-theme'; rejected( route( 'presentation', $bad ), 'Client-tampered theme accepted.', 409 );
$bad = $request; $bad['design']['blueprint']['document'][0]['settings']['title'] = 'Client modified content'; rejected( route( 'presentation', $bad ), 'Unsigned native design accepted.', 409 );
$bad = $request; $bad['selected_keys'] = [ 'unregistered-layout' ]; rejected( route( 'presentation', $bad ), 'Unknown metadata key accepted.' );
$bad = $request; $bad['selected_keys'] = [ 'content-layout', 'content-layout' ]; rejected( route( 'presentation', $bad ), 'Duplicate metadata selection accepted.' );
$bad = $request; $bad['selected_keys'] = [ 'content-layout' => true ]; rejected( route( 'presentation', $bad ), 'Non-list metadata selection accepted.', 400 );
$bad = $request; $bad['source_reference']['reference_id'] = 12; rejected( route( 'presentation', $bad ), 'Tampered source identity accepted.', 409 );
$bad = $request; $bad['source_receipt'] = ''; rejected( route( 'presentation', $bad ), 'Missing source receipt accepted.', 409 );
$bad = $request; unset( $bad['source_reference'] ); rejected( route( 'presentation', $bad ), 'Selection without an example source accepted.', 409 );
$bad = $request; $bad['resource_id'] = 'post_type:post'; rejected( route( 'presentation', $bad ), 'A page inventory crossed the destination boundary.', 409 );
$bad = $request; $bad['mode'] = 'unrecognized'; rejected( route( 'presentation', $bad ), 'Unknown template mode accepted.', 400 );

$other_design = capture_design( 12 ); $bad = selection( $other_design, [ 'content-layout' ] );
$bad['presentation_choices'] = $design['presentation_choices']; $bad['presentation_seal'] = $design['presentation_seal'];
rejected( route( 'presentation', $bad ), 'A valid inventory from another source page was accepted.', 409 );
$article_design = capture_design( 13, 'post_type:post' ); $bad = selection( $article_design, [ 'content-layout' ] );
$bad['presentation_choices'] = $design['presentation_choices']; $bad['presentation_seal'] = $design['presentation_seal'];
rejected( route( 'presentation', $bad ), 'A valid page inventory was accepted for a verified article source.', 409 );
$blueprints[11]['document'][0]['settings']['title'] = 'Changed example title'; $changed_design = capture_design(); $blueprints[11] = $initial_blueprints[11];
$bad = $request; $bad['presentation_choices'] = $changed_design['presentation_choices']; $bad['presentation_seal'] = $changed_design['presentation_seal'];
rejected( route( 'presentation', $bad ), 'A valid inventory from another captured blueprint was accepted.', 409 );

$post_meta[11]['content-layout'] = 'boxed';
rejected( route( 'presentation', $request ), 'Changed source metadata was signed into a new selection.', 409 );
rejected( Nova_Bridge_Suite_Recipe_Visual::validate_source( selection( $selected, [] ), $selected['recipe_preset'], $resource ), 'Changed selected metadata passed save freshness.', 409 );
$post_meta[11] = $initial_meta[11];
$post_meta[11]['header-overlay'] = '0';
rejected( route( 'presentation', $request ), 'Changed typed source metadata was accepted.', 409 );
$post_meta[11] = $initial_meta[11];
$post_meta[11]['layout-inherit'] = 'boxed';
rejected( route( 'presentation', $request ), 'An unset-to-present metadata change was accepted.', 409 );
$post_meta[11] = $initial_meta[11];
$registration = $registered['content-layout']; unset( $registered['content-layout'] );
rejected( route( 'presentation', $request ), 'A removed metadata registration was accepted.', 409 ); $registered['content-layout'] = $registration;
$theme = 'different-theme'; rejected( route( 'presentation', $request ), 'A changed active theme was accepted.', 409 ); $theme = 'test-theme';
$blueprints[11]['document'][0]['settings']['width'] = '900px';
rejected( route( 'presentation', $request ), 'A changed native design passed the selection boundary.', 409 ); $blueprints[11] = $initial_blueprints[11];
$administrator = false; rejected( route( 'presentation', $request ), 'Non-administrator reached the presentation route.', 403 ); $administrator = true;
rejected( route( 'presentation', $request, 'expired' ), 'Expired nonce reached the presentation route.', 403 );

check( 0 === $writes, 'Read-only selection attempted persistence.' );
check( $post_meta === $initial_meta && $blueprints === $initial_blueprints, 'Capture or selection changed the stored page fixtures.' );
echo 'PASS ' . $checks . " presentation API checks (real seals, metadata selection and source freshness; zero writes).\n";
