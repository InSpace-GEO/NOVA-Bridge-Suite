<?php
/** Current publishing setup: real HTTP-client seam, pinned schemas, no network or CMS writes. */
require_once __DIR__ . '/mapping-sync-unit.php';
$checks = 0;
class Nova_Bridge_Suite_Posting_Settings { public static $value = []; public static function connection() { return self::$value; } }
class Nova_Bridge_Suite_Mapping_Drafts { public static function can_admin() { return true; } }
class Nova_Bridge_Suite_Strategy {
    public static $inventory = [];
    public static function entity( $type, $id ) { return [ 'reference_type' => $type, 'reference_id' => $id ]; }
    public static function fingerprint( $entity ) { return [ 'signature' => 'layout-1' ]; }
    public static function field_inventory( $entity ) { return self::$inventory; }
}
class SetupRequest {
    public $input; public $method; public $nonce = 'valid';
    public function __construct( $method, $input ) { $this->method = $method; $this->input = $input; }
    public function get_header( $key ) { return $this->nonce; }
    public function get_method() { return $this->method; }
    public function get_json_params() { return $this->input; }
    public function get_params() { return $this->input; }
}
class SetupResponse {
    public $data; public $headers = [];
    public function __construct( $data ) { $this->data = $data; }
    public function header( $key, $value ) { $this->headers[ $key ] = $value; }
}
function rest_ensure_response( $data ) { return new SetupResponse( $data ); }
function wp_verify_nonce( $nonce, $action ) { return 'valid' === $nonce && 'wp_rest' === $action; }
Nova_Bridge_Suite_Strategy::$inventory = array_values( $draft['target_descriptors'] );
Nova_Bridge_Suite_Posting_Settings::$value = $connection + [ 'enabled' => true, 'paused' => true ];
function queue_http( array $queue ): void {
    $GLOBALS['http_handler'] = static function ( $url, $args ) use ( &$queue ) {
        check( count( $queue ) > 0, 'No unexpected backend request.' );
        $next = array_shift( $queue );
        check( $args['method'] === $next[0] && str_ends_with( $url, $next[1] ), 'Current contracted method/path: ' . $next[0] . ' ' . $next[1] );
        if ( isset( $next[4] ) ) { $next[4]( $args ); }
        return [ 'status' => $next[2], 'body' => json_encode( $next[3] ), 'headers' => isset( $next[3]['revision'] ) ? [ 'etag' => '"' . $next[3]['revision'] . '"' ] : [] ];
    };
}
$before_calls = count( $http_calls );
Nova_Bridge_Suite_Posting_Settings::$value['enabled'] = false;
$catalog = Nova_Bridge_Suite_Mapping_Sync::catalog( [] );
check( count( $catalog['templates'][0]['fields'] ) === 12 && false === $catalog['api_available'] && count( $http_calls ) === $before_calls, 'Unconfigured sites can prepare mappings from the actual pinned twelve-field catalog without network access.' );
Nova_Bridge_Suite_Posting_Settings::$value['enabled'] = true;
queue_http( [ [ 'GET', '/templates', 200, [ $record ] ] ] );
$catalog = Nova_Bridge_Suite_Mapping_Sync::catalog( [] );
check( $catalog['api_available'] && count( $catalog['templates'] ) === 2 && $catalog['templates'][1]['id'] === $remote_id && '' === $catalog['templates'][1]['authoring_notes'], 'Catalog parses the current raw-array template endpoint and does not fabricate generation instructions.' );
queue_http( [ [ 'GET', '/templates', 403, [ 'error' => [ 'code' => 'forbidden', 'message' => 'fixture' ] ] ] ] );
check( count( Nova_Bridge_Suite_Mapping_Sync::catalog( [] )['templates'] ) === 1, 'Denied template access still keeps pinned local source choices.' );
$params = [ 'reference_type' => 'post', 'reference_id' => 10, 'signature' => 'layout-1', 'expected_revision' => $next['revision'], 'url_id' => '99' ];
$request = new SetupRequest( 'POST', $params + [ 'expected_server_revision' => 0 ] ); $request->nonce = 'invalid';
$before_calls = count( $http_calls ); error_is( Nova_Bridge_Suite_Mapping_Sync::url_binding_response( $request ), 'nonce', 403 ); check( count( $http_calls ) === $before_calls, 'Invalid nonce cannot issue an external mutation.' );
queue_http( [ [ 'GET', '/pages/99/setting', 404, [ 'error' => [ 'code' => 'not_found', 'message' => 'fixture' ] ] ] ] );
$read = Nova_Bridge_Suite_Mapping_Sync::url_binding_response( new SetupRequest( 'GET', $params ) );
check( null === $read->data['url_binding'] && 0 === $read->data['server_revision'], 'A missing page selection is explicitly represented as revision zero.' );
queue_http( [ [ 'PUT', '/pages/99/setting', 200, [ 'template_id' => $remote_id, 'revision' => 1 ], static function ( $args ) use ( $remote_id ) {
    check( json_decode( $args['body'], true ) === [ 'template_id' => $remote_id ], 'Page-setting body contains only template_id, never WP IDs, notes or native_reference.' );
    check( $args['headers']['If-Match'] === '"0"', 'Page-setting creation uses If-Match zero.' );
} ] ] );
$saved = Nova_Bridge_Suite_Mapping_Sync::url_binding_response( new SetupRequest( 'POST', $params + [ 'expected_server_revision' => 0 ] ) );
check( $saved instanceof SetupResponse && $saved->data['url_binding']['url_id'] === '99' && $saved->headers['Cache-Control'] === 'private, no-store', 'Page selection saves with nonce/capability checks even while content changes are paused.' );
queue_http( [ [ 'PUT', '/pages/99/setting', 412, [ 'error' => [ 'code' => 'stale_revision', 'message' => 'fixture' ] ] ] ] );
error_is( Nova_Bridge_Suite_Mapping_Sync::url_binding_response( new SetupRequest( 'POST', $params + [ 'expected_server_revision' => 0 ] ) ), 'stale_revision', 412 );
$default_params = $params + [ 'source_page_type' => 'service', 'expected_server_revision' => 4 ];
$other_id = '44444444-4444-4444-8444-444444444444';
queue_http( [
    [ 'GET', '/template-defaults', 200, [ 'defaults' => [ 'informative' => $other_id ], 'revision' => 4 ] ],
    [ 'PUT', '/template-defaults', 200, [ 'defaults' => [ 'informative' => $other_id, 'service' => $remote_id ], 'revision' => 5 ], static function ( $args ) use ( $other_id, $remote_id ) {
        check( $args['headers']['If-Match'] === '"4"' && json_decode( $args['body'], true ) === [ 'defaults' => [ 'informative' => $other_id, 'service' => $remote_id ] ], 'Changing one source-type default preserves unrelated selections with an exact revision precondition.' );
    } ],
] );
$defaults = Nova_Bridge_Suite_Mapping_Sync::template_default_response( new SetupRequest( 'POST', $default_params ) );
check( $defaults->data['server_revision'] === 5, 'Server acknowledges the new defaults revision.' );
queue_http( [ [ 'GET', '/template-defaults', 200, [ 'defaults' => (object) [], 'revision' => 1 ] ] ] );
$empty = Nova_Bridge_Suite_Mapping_Sync::template_default_response( new SetupRequest( 'GET', $params + [ 'source_page_type' => 'service' ] ) );
check( $empty instanceof SetupResponse && $empty->data['template_default']['defaults'] === [], 'Actual wire empty defaults object validates without confusing it with a JSON list.' );
queue_http( [ [ 'GET', '/template-defaults', 200, [ 'defaults' => [], 'revision' => 1 ] ] ] );
check( is_wp_error( Nova_Bridge_Suite_Mapping_Sync::template_default_response( new SetupRequest( 'GET', $params + [ 'source_page_type' => 'service' ] ) ) ), 'A malformed wire defaults list is rejected rather than normalized into an object.' );
queue_http( [ [ 'GET', '/template-defaults', 200, [ 'defaults' => [ 'informative' => $other_id ], 'revision' => 6 ] ] ] );
error_is( Nova_Bridge_Suite_Mapping_Sync::template_default_response( new SetupRequest( 'POST', $default_params ) ), 'setting_revision', 412 );
// A definitive POST rejection can be corrected and retried; no remote outcome is ambiguous.
$denied_draft = $draft; $denied_draft['reference_id'] = 40; $denied_draft['revision'] = 'denied-1';
$denied_api = new QueueClient( $connection ); $denied_service = new Nova_Bridge_Suite_Mapping_Sync( $denied_api );
$denied_api->queue = [ [ 'POST', '/templates', new WP_Error( 'nova_posting_forbidden', 'scope missing', [ 'status' => 403 ] ) ] ];
error_is( $denied_service->synchronize( $denied_draft ), 'forbidden', 403 );
$denied_record = $record; $denied_record['id'] = '55555555-5555-4555-8555-555555555555';
$denied_api->queue = [ [ 'POST', '/templates', reply( $denied_record, 201 ) ] ];
check( $denied_service->synchronize( $denied_draft )['status'] === 'synced_draft', 'After a definitive auth rejection, restored scopes permit a fresh create without unknown-outcome recovery.' );
echo 'PASS ' . $checks . " current mapping administrative contract checks.\n";
