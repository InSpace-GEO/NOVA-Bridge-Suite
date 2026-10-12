<?php
/** Endpoint controller/store checks with isolated WordPress and document-adapter seams. */
define( 'ABSPATH', __DIR__ );
const ENDPOINT_OPTION = 'nova_bridge_endpoint_mappings_v1';
$options = [ 'nova_bridge_settings' => [ 'api_mapping_context' => 0 ], 'nova_bridge_posting_connection' => [ 'enabled' => false ], 'nova_mapping_draft_legacy' => [ 'keep' => true ] ];
$initial_other_options = $options; $administrator = true; $routes = []; $actions = []; $checks = 0; $uuid_number = 0; $native_writes = 0;
class WP_Error {
    private $code; private $message; private $data;
    function __construct( $code, $message, $data = [] ) { $this->code = $code; $this->message = $message; $this->data = $data; }
    function get_error_code() { return $this->code; } function get_error_data() { return $this->data; } function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function current_user_can( $cap, ...$args ) { global $administrator; if ( $cap === 'manage_options' ) { return $administrator; } if ( in_array( $cap, [ 'edit_post', 'edit_term' ], true ) ) { return $administrator && ( $args[0] ?? 0 ) !== 22; } if ( in_array( $cap, [ 'edit_post_meta', 'edit_term_meta' ], true ) ) { return $administrator && ( $args[1] ?? '' ) !== 'locked'; } return false; }
function wp_verify_nonce( $nonce, $action ) { return $nonce === 'valid' && $action === 'wp_rest'; }
function add_action( $name, $callback, $priority = 10 ) { global $actions; $actions[] = [ $name, $callback, $priority ]; }
function register_rest_route( $namespace, $path, $handlers ) { global $routes; $routes[$namespace . $path] = $handlers; }
function get_option( $key, $default = false ) { global $options; return array_key_exists( $key, $options ) ? $options[$key] : $default; }
function add_option( $key, $value, $unused = '', $autoload = null ) { global $options; if ( array_key_exists( $key, $options ) ) { return false; } $options[$key] = $value; return true; }
function maybe_serialize( $value ) { return serialize( $value ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_cache_delete( $key, $group ) {}
function wp_generate_uuid4() { global $uuid_number; return sprintf( '11111111-1111-4111-8111-%012d', ++$uuid_number ); }
function get_permalink( $id ) { return 'https://site.example/article-' . $id; }
function get_term_link( $id, $taxonomy ) { return 'https://site.example/category-' . $id; }
function wp_remote_request( ...$args ) { throw new RuntimeException( 'These local routes must not contact an external service.' ); }
function wp_update_post( ...$args ) { global $native_writes; ++$native_writes; throw new RuntimeException( 'These routes must not write native content.' ); }
class Nova_Bridge_Suite_Mapping_Preview { static function url( $entity ) { return $entity['url'] . '?nova_mapping_preview=' . $entity['reference_type']; } }
class EndpointResponse { public $headers = []; private $data; function __construct( $data ) { $this->data = $data; } function header( $name, $value ) { $this->headers[$name] = $value; } function get_data() { return $this->data; } }
function rest_ensure_response( $value ) { return new EndpointResponse( $value ); }
class EndpointRequest {
    private $params; private $body; private $headers; private $raw;
    function __construct( $params = [], $body = null, $headers = [], $raw = null ) { $this->params = $params; $this->body = $body; $this->headers = $headers; $this->raw = $raw; }
    function get_param( $name ) { return $this->params[$name] ?? null; } function get_header( $name ) { return $this->headers[$name] ?? null; }
    function get_json_params() { return $this->body; } function get_body() { return $this->raw ?? json_encode( $this->body ); }
}
class EndpointDb {
    public $options = 'wp_options'; public $race = null; public $fail = false; public $updates = 0;
    function prepare( $sql, ...$args ) { return $args; }
    function query( $args ) {
        global $options; ++$this->updates;
        if ( $this->fail ) { return false; }
        if ( $this->race ) { $race = $this->race; $this->race = null; $race(); }
        [ $next, $key, $previous ] = $args;
        if ( ! isset( $options[$key] ) || serialize( $options[$key] ) !== $previous ) { return 0; }
        $options[$key] = unserialize( $next ); return 1;
    }
}
$wpdb = new EndpointDb();
class Nova_Bridge_Suite_Document_Mapping {
    public static $source = 'SECRET SOURCE ARTICLE'; public static $many = false; public static $preview_read_only = true;
    static function resources() { $resources = [
        [ 'id' => 'post_type:page', 'label' => 'Pages', 'type' => 'post_type', 'post_type' => 'page', 'route' => '/wp/v2/pages', 'methods' => [ 'GET', 'POST', 'PATCH' ], 'targets' => [ [ 'reference_type' => 'post', 'reference_id' => 11, 'title' => 'Sample page', 'status' => 'draft', 'content' => self::$source ], [ 'reference_type' => 'post', 'reference_id' => 22, 'title' => 'Denied page', 'status' => 'draft' ] ], 'targets_complete' => true, 'creation_supported' => true, 'can_inspect_existing' => true, 'secret' => self::$source ],
        [ 'id' => 'post_type:post', 'label' => 'Posts', 'type' => 'post_type', 'post_type' => 'post', 'route' => '/wp/v2/posts', 'methods' => [ 'GET', 'POST' ], 'targets' => [], 'targets_complete' => true, 'creation_supported' => true ],
        [ 'id' => 'taxonomy:product_cat', 'label' => 'Product categories', 'type' => 'taxonomy', 'taxonomy' => 'product_cat', 'route' => '/wp/v2/product_cat', 'methods' => [ 'GET', 'POST' ], 'targets' => [], 'targets_complete' => true, 'creation_supported' => true ],
        [ 'id' => 'post_type:unsupported', 'label' => 'Unsupported', 'type' => 'post_type', 'route' => '/wp/v2/unsupported', 'methods' => [ 'GET' ], 'targets' => [], 'creation_supported' => false, 'can_inspect_existing' => false, 'reasons' => [ 'Provider unavailable' ] ],
    ]; if(self::$many){$resources[0]['targets']=array_map(static function($id){return ['reference_type'=>'post','reference_id'=>$id,'title'=>'Page '.$id,'status'=>'draft'];},range(1001,1101));}return $resources; }
    static function read( $resource, $type, $id ) {
        $structure = [ 'core' => [ 'title' => 'Article heading', 'content' => self::$source ], 'meta' => [ 'locked' => 'Denied metadata' ], 'style' => [ 'color' => '#8511D9' ], 'footer' => 'Protected brand footer' ];
        $fields = [];
        foreach ( [ '/core/title' => 'Article heading', '/core/content' => self::$source, '/meta/locked' => 'Denied metadata', '/footer' => 'Protected brand footer' ] as $path => $value ) { $fields[$path] = [ 'path' => $path, 'structure_path' => $path, 'label' => $path, 'type' => 'string', 'value' => $value, 'current_value' => $value, 'writable' => $path !== '/footer', 'protected' => $path === '/footer' ]; }
        return [ 'format' => 'nova-document-mapping/v1', 'mode' => 'existing', 'resource_id' => $resource, 'target' => [ 'reference_type' => $type, 'reference_id' => $id ], 'provider' => [ 'id' => 'fixture', 'version' => '1', 'complete' => true, 'preview_read_only' => self::$preview_read_only ], 'structure' => $structure, 'fields' => $fields, 'baseline' => [ 'structure_sha256' => hash( 'sha256', json_encode( $structure ) ), 'content_sha256' => hash( 'sha256', self::$source ) ], 'instructions' => '' ];
    }
    static function empty( $resource ) { $mapping = self::read( $resource, strpos( $resource, 'taxonomy:' ) === 0 ? 'term' : 'post', 0 ); $mapping['mode'] = 'creation'; $mapping['source_reference'] = null; foreach ( [ '/core/title', '/core/content', '/meta/locked' ] as $path ) { self::set( $mapping['structure'], $path, '' ); $mapping['fields'][$path]['value'] = $mapping['fields'][$path]['current_value'] = ''; } $mapping['baseline'] = [ 'structure_sha256' => hash( 'sha256', json_encode( $mapping['structure'] ) ), 'content_sha256' => hash( 'sha256', '' ) ]; return $mapping; }
    static function set( &$structure, $path, $value ) { $parts = explode( '/', trim( $path, '/' ) ); $cursor = &$structure; foreach ( $parts as $part ) { $cursor = &$cursor[$part]; } $cursor = $value; }
    static function blank( $filled, $paths, $instructions = '' ) { $blank = $filled; $blank['mode'] = 'creation'; $blank['source_reference'] = $filled['target']['reference_id'] ? $filled['target'] : null; $blank['target']['reference_id'] = 0; $blank['selected_paths'] = $paths; $blank['instructions'] = $instructions; foreach ( $paths as $path ) { $blank['fields'][$path]['value'] = ''; $blank['fields'][$path]['current_value'] = ''; self::set( $blank['structure'], $path, '' ); } return $blank; }
    static function validate( $mapping ) {
        $reference = $mapping['mode'] === 'creation' ? ( $mapping['source_reference'] ?? null ) : $mapping['target'];
        $fresh = $reference ? self::read( $mapping['resource_id'], $reference['reference_type'], $reference['reference_id'] ) : self::empty( $mapping['resource_id'] );
        if ( $mapping['mode'] === 'creation' ) { $fresh = self::blank( $fresh, $mapping['selected_paths'] ?? [] ); }
        if ( $mapping['baseline'] !== $fresh['baseline'] ) { return new WP_Error( 'source_changed', 'Source changed', [ 'status' => 409 ] ); }
        foreach ( [ 'format', 'mode', 'resource_id', 'target', 'provider', 'structure' ] as $key ) { if ( $mapping[$key] !== $fresh[$key] ) { return new WP_Error( 'immutable', 'Immutable document changed', [ 'status' => 400 ] ); } }
        if ( array_keys( $mapping['fields'] ) !== array_keys( $fresh['fields'] ) ) { return new WP_Error( 'fields', 'Unknown field', [ 'status' => 400 ] ); }
        foreach ( $mapping['fields'] as $path => $field ) { $expected = $fresh['fields'][$path]; $value = $field['value']; $field['value'] = $expected['value']; if ( $field !== $expected || ! is_string( $value ) || $expected['protected'] && $value !== $expected['value'] ) { return new WP_Error( 'field', 'Invalid field change', [ 'status' => 400 ] ); } }
        return $mapping;
    }
}
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-endpoint-mappings.php';
function check( $value, $message ) { global $checks; ++$checks; if ( ! $value ) { throw new RuntimeException( $message ); } }
function data( $response ) { check( $response instanceof EndpointResponse, is_wp_error( $response ) ? $response->get_error_message() : 'Expected a REST response' ); check( $response->headers['Cache-Control'] === 'private, no-store', 'Document response may be shared/cached' ); return $response->get_data(); }
function rejected( $value, $status, $message ) { check( is_wp_error( $value ) && ( $value->get_error_data()['status'] ?? 0 ) === $status, $message ); }
function body_for( $resource = 'post_type:page', $type = 'post', $id = 11, $revision = 0 ) { $read = data( Nova_Bridge_Suite_Endpoint_Mappings::existing( new EndpointRequest( [ 'resource_id' => $resource, 'reference_type' => $type, 'reference_id' => $id ] ) ) ); return [ 'resource_id' => $resource, 'reference_type' => $type, 'reference_id' => $id, 'selected_fields' => [ '/core/content' ], 'field_descriptions' => [ '/core/content' => [ 'purpose' => 'Main article' ] ], 'source_document_version' => $read['source_document_version'], 'expected_revision' => $revision, 'label' => 'Article template', 'instructions' => 'Keep layout order' ]; }

Nova_Bridge_Suite_Endpoint_Mappings::bootstrap(); Nova_Bridge_Suite_Endpoint_Mappings::bootstrap(); Nova_Bridge_Suite_Endpoint_Mappings::routes(); Nova_Bridge_Suite_Endpoint_Mappings::routes();
check( count( $actions ) === 1 && count( $routes ) === 5, 'Hooks/routes were registered repeatedly' );
foreach ( $routes as $handlers ) { foreach ( $handlers as $handler ) { check( $handler['permission_callback'] === [ 'Nova_Bridge_Suite_Endpoint_Mappings', 'can_admin' ], 'An endpoint lacks authentication' ); } }
check( true === Nova_Bridge_Suite_Endpoint_Mappings::can_admin( new EndpointRequest() ), 'Application-password authentication requires a browser nonce' );
rejected( Nova_Bridge_Suite_Endpoint_Mappings::endpoints( new EndpointRequest( [], null, [ 'x-wp-nonce' => 'wrong' ] ) ), 403, 'Invalid supplied nonce succeeded' );
rejected( Nova_Bridge_Suite_Endpoint_Mappings::endpoints( new EndpointRequest( [], null, [ 'x-wp-nonce' => '' ] ) ), 403, 'Empty supplied nonce was treated as service authentication' );
$administrator = false; rejected( Nova_Bridge_Suite_Endpoint_Mappings::existing( new EndpointRequest( [ 'resource_id' => 'post_type:page', 'reference_type' => 'post', 'reference_id' => 11 ] ) ), 403, 'Anonymous/subscriber read succeeded' ); $administrator = true;
$catalog = data( Nova_Bridge_Suite_Endpoint_Mappings::endpoints( new EndpointRequest() ) );
check( $catalog['coverage'] === [ 'total' => 4, 'configured' => 0, 'unconfigured' => 4 ], 'Initial endpoint coverage is wrong' );
check( count( $catalog['resources'][0]['targets'] ) === 1 && ! str_contains( json_encode( $catalog ), Nova_Bridge_Suite_Document_Mapping::$source ), 'Catalog leaked protected/source documents or inaccessible references' );
check( $catalog['resources'][3]['creation_supported'] === false && $catalog['native_write_supported'] === false, 'Catalog claimed an unsupported/native-write capability' );
Nova_Bridge_Suite_Document_Mapping::$many=true;$bounded=data(Nova_Bridge_Suite_Endpoint_Mappings::endpoints(new EndpointRequest()));check(count($bounded['resources'][0]['targets'])===100&&$bounded['resources'][0]['targets_complete']===false,'A truncated reference catalog claimed completeness');Nova_Bridge_Suite_Document_Mapping::$many=false;
$absent = data( Nova_Bridge_Suite_Endpoint_Mappings::creation( new EndpointRequest( [ 'resource_id' => 'post_type:post' ] ) ) );
check( $absent['mapping'] === null && $absent['revision'] === 0, 'An absent default is not an explicit known-resource empty state' );
rejected( Nova_Bridge_Suite_Endpoint_Mappings::creation( new EndpointRequest( [ 'resource_id' => 'post_type:unknown' ] ) ), 404, 'Unknown endpoint was mistaken for an empty default' );
rejected( Nova_Bridge_Suite_Endpoint_Mappings::existing( new EndpointRequest( [ 'resource_id' => 'post_type:page', 'reference_type' => 'post', 'reference_id' => 22 ] ) ), 403, 'A denied reference was read' );
rejected( Nova_Bridge_Suite_Endpoint_Mappings::existing( new EndpointRequest( [ 'resource_id' => 'post_type:page', 'reference_type' => 'term', 'reference_id' => 11 ] ) ), 400, 'An incompatible reference type was accepted' );
$source = data( Nova_Bridge_Suite_Endpoint_Mappings::existing( new EndpointRequest( [ 'resource_id' => 'post_type:page', 'reference_type' => 'post', 'reference_id' => 11 ] ) ) );
check( $source['mapping']['structure']['core']['content'] === Nova_Bridge_Suite_Document_Mapping::$source && str_contains( $source['preview_url'], 'nova_mapping_preview=post' ), 'Direct full-filled mapping/preview requires a saved layout group' );
Nova_Bridge_Suite_Document_Mapping::$preview_read_only=false;$unsafe_preview=data(Nova_Bridge_Suite_Endpoint_Mappings::existing(new EndpointRequest(['resource_id'=>'post_type:page','reference_type'=>'post','reference_id'=>11])));check($unsafe_preview['preview_url']===''&&$unsafe_preview['mapping']['provider']['preview_read_only']===false,'Read-only document inspection opened a provider preview that can migrate native content');Nova_Bridge_Suite_Document_Mapping::$preview_read_only=true;
$empty = data( Nova_Bridge_Suite_Endpoint_Mappings::existing( new EndpointRequest( [ 'resource_id' => 'post_type:post', 'reference_type' => 'post', 'reference_id' => 0 ] ) ) );
check( $empty['mapping']['target']['reference_id'] === 0 && $empty['mapping']['structure']['core']['content'] === '' && $empty['preview_url'] === '', 'No-item standard mapping did not use empty schema fields' );
$body = body_for(); $before = $options;
$preview = data( Nova_Bridge_Suite_Endpoint_Mappings::preview_creation( new EndpointRequest( [], $body ) ) );
check( $options === $before && $preview['revision'] === 0 && !$preview['saved'], 'A blank preview wrote a saved/default mapping' );
check( $preview['mapping']['target']['reference_id'] === 0 && $preview['mapping']['source_reference']['reference_id'] === 11, 'Creation identity still targets the sample item' );
check( $preview['mapping']['fields']['/core/content']['value'] === '' && $preview['mapping']['fields']['/core/content']['current_value'] === '' && $preview['mapping']['structure']['core']['content'] === '', 'Selected content was not blanked throughout the document' );
check( !str_contains( json_encode( $preview ), Nova_Bridge_Suite_Document_Mapping::$source ) && !isset( $preview['source_evidence'] ), 'Public blank preview leaked original source evidence' );
check( $preview['mapping']['structure']['style']['color'] === '#8511D9' && $preview['mapping']['structure']['footer'] === 'Protected brand footer', 'Blanking discarded protected layout/style' );
$saved = data( Nova_Bridge_Suite_Endpoint_Mappings::save_creation( new EndpointRequest( [], $body ) ) );
check( $saved['revision'] === 1 && $saved['saved'] && is_string( $saved['mapping_id'] ), 'First save did not produce an immutable identity/revision' );
check( isset( $options[ENDPOINT_OPTION]['revisions'][$saved['mapping_id']][1]['source_evidence'] ), 'Private source evidence was not retained server-side' );
check( !str_contains(json_encode($options[ENDPOINT_OPTION]),Nova_Bridge_Suite_Document_Mapping::$source),'Original designated content was unnecessarily retained in private evidence');
rejected( Nova_Bridge_Suite_Endpoint_Mappings::save_creation( new EndpointRequest( [], $body ) ), 409, 'A stale initial revision overwrote the default' );
$body['expected_revision'] = 1; $body['label'] = 'Second revision';
$second = data( Nova_Bridge_Suite_Endpoint_Mappings::save_creation( new EndpointRequest( [], $body ) ) );
check( $second['mapping_id'] === $saved['mapping_id'] && $second['revision'] === 2 && $options[ENDPOINT_OPTION]['revisions'][$saved['mapping_id']][1]['label'] === 'Article template', 'An update changed identity or overwrote immutable history' );
$returned = data( Nova_Bridge_Suite_Endpoint_Mappings::creation( new EndpointRequest( [ 'resource_id' => 'post_type:page' ] ) ) );
check( $returned['revision'] === 2 && $returned['selected_fields'] === ['/core/content'] && $returned['field_descriptions']['/core/content']['purpose'] === 'Main article' && !str_contains(json_encode($returned),Nova_Bridge_Suite_Document_Mapping::$source), 'Saved default did not reload its exact safe document and descriptions' );
check( $returned['needs_refresh'] === false, 'Saving endpoint notes made its unchanged native source falsely stale' );
$creation_edit = $returned['mapping']; $creation_edit['fields']['/core/content']['value'] = 'Generated article'; $validation_before = $options;
$creation_valid = data( Nova_Bridge_Suite_Endpoint_Mappings::validate( new EndpointRequest( [], [ 'mapping' => $creation_edit, 'mapping_id' => $returned['mapping_id'], 'revision' => 2 ] ) ) );
check( $creation_valid['valid'] && $creation_valid['mapping']['instructions'] === 'Keep layout order' && $creation_valid['mapping']['fields']['/core/content']['instructions'] === 'Main article' && $options === $validation_before, 'Creation validation lost approved instructions or performed a write' );
rejected( Nova_Bridge_Suite_Endpoint_Mappings::validate( new EndpointRequest( [], [ 'mapping' => $creation_edit, 'mapping_id' => $returned['mapping_id'], 'revision' => 1 ] ) ), 409, 'Stale creation revision was accepted' );
foreach ( [ 'instructions', 'protected', 'unselected', 'typed' ] as $case ) {
    $changed = $creation_edit;
    if ( $case === 'instructions' ) { $changed['instructions'] = 'Changed after export'; }
    if ( $case === 'protected' ) { $changed['fields']['/footer']['value'] = 'Replace protected footer'; }
    if ( $case === 'unselected' ) { $changed['fields']['/core/title']['value'] = 'Not a designated blank field'; }
    if ( $case === 'typed' ) { $changed['fields']['/core/content']['value'] = [ 'Unexpected array' ]; }
    rejected( Nova_Bridge_Suite_Endpoint_Mappings::validate( new EndpointRequest( [], [ 'mapping' => $changed, 'mapping_id' => $returned['mapping_id'], 'revision' => 2 ] ) ), 400, 'Creation ' . $case . ' immutability/type guard failed' );
}
$enriched = data( Nova_Bridge_Suite_Endpoint_Mappings::existing( new EndpointRequest( [ 'resource_id' => 'post_type:page', 'reference_type' => 'post', 'reference_id' => 11 ] ) ) );
check( $enriched['mapping']['instructions'] === 'Keep layout order' && $enriched['mapping']['fields']['/core/content']['instructions'] === 'Main article', 'Existing read did not inherit shared endpoint guidance' );
$changed_guidance=$enriched['mapping'];$changed_guidance['fields']['/core/content']['instructions']='Injected';rejected(Nova_Bridge_Suite_Endpoint_Mappings::validate(new EndpointRequest([],['mapping'=>$changed_guidance])),400,'Round-trip field guidance was writable after export');
$changed_source = body_for( 'post_type:post', 'post', 0 ); Nova_Bridge_Suite_Document_Mapping::$source = 'New source';
$stale = body_for(); Nova_Bridge_Suite_Document_Mapping::$source = 'Changed again'; rejected( Nova_Bridge_Suite_Endpoint_Mappings::save_creation( new EndpointRequest( [], $stale ) ), 409, 'A source changed after inspection was captured silently' );
$refresh=data(Nova_Bridge_Suite_Endpoint_Mappings::creation(new EndpointRequest(['resource_id'=>'post_type:page'])));check($refresh['needs_refresh']===true,'A default sourced from changed native content did not require refresh');rejected(Nova_Bridge_Suite_Endpoint_Mappings::validate(new EndpointRequest([],['mapping'=>$creation_edit,'mapping_id'=>$returned['mapping_id'],'revision'=>2])),409,'Changed source accepted its stale creation baseline');
$fresh = body_for( 'post_type:page', 'post', 11, 2 ); $fresh['selected_fields'] = [ '/footer' ]; rejected( Nova_Bridge_Suite_Endpoint_Mappings::preview_creation( new EndpointRequest( [], $fresh ) ), 400, 'Protected field was blanked' );
$fresh['selected_fields'] = [ '/meta/locked' ]; rejected( Nova_Bridge_Suite_Endpoint_Mappings::preview_creation( new EndpointRequest( [], $fresh ) ), 403, 'Metadata edit capability was bypassed' );
$fresh['selected_fields'] = [ '/core/content', '/core/content' ]; rejected( Nova_Bridge_Suite_Endpoint_Mappings::preview_creation( new EndpointRequest( [], $fresh ) ), 400, 'A duplicate target was accepted' );
$fresh['selected_fields'] = [ '/core/content' ]; $fresh['field_descriptions']['/core/title'] = ['purpose'=>'Not selected']; rejected( Nova_Bridge_Suite_Endpoint_Mappings::preview_creation( new EndpointRequest( [], $fresh ) ), 400, 'Description outside selected fields was accepted' );
$fresh = body_for( 'post_type:page', 'post', 11, 2 ); unset($fresh['source_document_version']); rejected( Nova_Bridge_Suite_Endpoint_Mappings::save_creation( new EndpointRequest( [], $fresh ) ), 400, 'Capture omitted the inspected source marker' );
$long_notes=body_for('post_type:page','post',11,2);$long_notes['field_descriptions']['/core/content']=['purpose'=>'Heading','instructions'=>str_repeat('x',8000)];rejected(Nova_Bridge_Suite_Endpoint_Mappings::preview_creation(new EndpointRequest([],$long_notes)),400,'Combined field guidance exceeded the native mapping format limit');
$standard = body_for( 'post_type:post', 'post', 0 ); $standard_saved = data( Nova_Bridge_Suite_Endpoint_Mappings::save_creation( new EndpointRequest( [], $standard ) ) );
check( $standard_saved['reference']['reference_id'] === 0 && $standard_saved['mapping']['source_reference'] === null, 'An endpoint without items could not save a default mapping' );
$standard_edit=$standard_saved['mapping'];$standard_edit['fields']['/core/content']['value']='Generated standard article';$standard_valid=data(Nova_Bridge_Suite_Endpoint_Mappings::validate(new EndpointRequest([],['mapping'=>$standard_edit,'mapping_id'=>$standard_saved['mapping_id'],'revision'=>1])));check($standard_valid['valid'],'A saved no-source creation document could not round-trip');
$term = body_for('taxonomy:product_cat','term',0);$term_saved=data(Nova_Bridge_Suite_Endpoint_Mappings::save_creation(new EndpointRequest([],$term)));check($term_saved['mapping']['target']['reference_type']==='term','Standard taxonomy capture was not supported');
$mapping = data( Nova_Bridge_Suite_Endpoint_Mappings::existing( new EndpointRequest( [ 'resource_id' => 'post_type:page', 'reference_type' => 'post', 'reference_id' => 11 ] ) ) )['mapping']; $mapping['fields']['/core/content']['value'] = 'Replacement text'; $before = $options;
$valid = data( Nova_Bridge_Suite_Endpoint_Mappings::validate( new EndpointRequest( [], ['mapping'=>$mapping] ) ) ); check( $valid['valid'] && $options === $before && $native_writes === 0, 'Validation mutated defaults or native content' );
$mapping['structure']['style']['color']='#000000';rejected(Nova_Bridge_Suite_Endpoint_Mappings::validate(new EndpointRequest([],['mapping'=>$mapping])),400,'Client changed immutable style');
$mapping = data(Nova_Bridge_Suite_Endpoint_Mappings::existing(new EndpointRequest(['resource_id'=>'post_type:page','reference_type'=>'post','reference_id'=>11])))['mapping'];$mapping['fields']['/meta/locked']['value']='Unauthorized';rejected(Nova_Bridge_Suite_Endpoint_Mappings::validate(new EndpointRequest([],['mapping'=>$mapping])),403,'Round-trip metadata write capability was bypassed');
$race_body=body_for('post_type:page','post',11,2);$wpdb->race=function(){global$options;$options[ENDPOINT_OPTION]['concurrent_resource_marker']='preserve me';};$race_saved=data(Nova_Bridge_Suite_Endpoint_Mappings::save_creation(new EndpointRequest([],$race_body)));check($race_saved['revision']===3&&$options[ENDPOINT_OPTION]['concurrent_resource_marker']==='preserve me','Compare-and-swap clobbered a concurrent store update');
$race_body['expected_revision']=3;$wpdb->race=function(){global$options;$state=$options[ENDPOINT_OPTION];$identity=$state['defaults']['post_type:page'];$record=$state['revisions'][$identity['id']][$identity['revision']];$record['revision']=4;$record['label']='Concurrent winner';$state['revisions'][$identity['id']][4]=$record;$state['defaults']['post_type:page']['revision']=4;$options[ENDPOINT_OPTION]=$state;};rejected(Nova_Bridge_Suite_Endpoint_Mappings::save_creation(new EndpointRequest([],$race_body)),409,'Concurrent same-resource winner was overwritten');check($options[ENDPOINT_OPTION]['revisions'][$saved['mapping_id']][4]['label']==='Concurrent winner','Conflict mutated the winner');
$valid_store=$options[ENDPOINT_OPTION];$options[ENDPOINT_OPTION]=new stdClass();rejected(Nova_Bridge_Suite_Endpoint_Mappings::creation(new EndpointRequest(['resource_id'=>'post_type:page'])),409,'Corrupt store was treated as empty');$options[ENDPOINT_OPTION]=$valid_store;
rejected(Nova_Bridge_Suite_Endpoint_Mappings::save_creation(new EndpointRequest([],[],[],str_repeat('x',8388609))),413,'Oversized request was parsed');
foreach($initial_other_options as$key=>$value){check($options[$key]===$value,'A legacy mapping, module flag or connection changed');}
check($native_writes===0,'Read/blank/store/validate routes performed a native write');
echo 'PASS '.$checks." endpoint mapping authentication, blanking, empty resources, immutable revisions, CAS races and validation checks.\n";
