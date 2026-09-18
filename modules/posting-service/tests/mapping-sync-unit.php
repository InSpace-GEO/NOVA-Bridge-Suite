<?php
/** Standalone protocol/retention tests. No WordPress installation or remote service is touched. */
if ( defined( 'ABSPATH' ) ) { throw new RuntimeException( 'Run standalone only.' ); }
define( 'ABSPATH', __DIR__ . '/' ); define( 'NOVA_BRIDGE_SUITE_VERSION', '2.9.0-test' );
set_error_handler( static function ( $severity, $message, $file, $line ) { throw new ErrorException( $message, 0, $severity, $file, $line ); } );
class WP_Error {
    private $code; private $message; private $data;
    public function __construct( $code, $message, $data = [] ) { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_code() { return $this->code; } public function get_error_message() { return $this->message; } public function get_error_data() { return $this->data; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_parse_url( $value ) { return parse_url( $value ); }
function wp_generate_uuid4() { static $id = 0; return 'lock-' . ++$id; }
function maybe_serialize( $value ) { return serialize( $value ); }
function maybe_unserialize( $value ) { return unserialize( $value, [ 'allowed_classes' => false ] ); }
function wp_cache_delete( $key, $group ) {}
function get_option( $key, $fallback = null ) { return isset( $GLOBALS['wpdb']->rows[ $key ] ) ? maybe_unserialize( $GLOBALS['wpdb']->rows[ $key ] ) : $fallback; }
function add_option( $key, $value, $deprecated = '', $autoload = false ) { if ( isset( $GLOBALS['wpdb']->rows[ $key ] ) ) { return false; } $GLOBALS['wpdb']->rows[ $key ] = maybe_serialize( $value ); return true; }
function update_option( $key, $value, $autoload = false ) { $GLOBALS['wpdb']->rows[ $key ] = maybe_serialize( $value ); return true; }
function wp_insert_post() { throw new RuntimeException( 'Configuration sync must never mutate native content.' ); }
function wp_update_post() { return wp_insert_post(); }
function update_post_meta() { return wp_insert_post(); }
function wp_safe_remote_request( $url, $args ) { $GLOBALS['http_calls'][] = [ $url, $args ]; return $GLOBALS['http_reply']; }
function wp_remote_retrieve_response_code( $response ) { return $response['status']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function wp_remote_retrieve_header( $response, $name ) { return $response['headers'][ $name ] ?? ''; }
class SyncDB {
    public $options = 'wp_options'; public $rows = []; public $before_query = null;
    public function prepare( $sql, ...$args ) { return [ $sql, $args ]; }
    public function get_var( $query ) { return $this->rows[ $query[1][0] ] ?? null; }
    public function query( $query ) {
        list( $sql, $args ) = $query;
        if ( $this->before_query ) { $callback = $this->before_query; $this->before_query = null; $callback( $this, $query ); }
        if ( false !== strpos( $sql, 'SELECT %s,%s' ) ) { if ( ( $this->rows[ $args[2] ] ?? null ) !== $args[3] || time() >= $args[4] || isset( $this->rows[ $args[0] ] ) ) { return 0; } $this->rows[ $args[0] ] = $args[1]; return 1; }
        if ( false !== strpos( $sql, 'INNER JOIN' ) ) { if ( ( $this->rows[ $args[0] ] ?? null ) !== $args[3] || time() >= $args[4] || ! isset( $this->rows[ $args[2] ] ) ) { return 0; } $this->rows[ $args[2] ] = $args[1]; return 1; }
        if ( 0 === strpos( $sql, 'INSERT IGNORE' ) ) { if ( isset( $this->rows[ $args[0] ] ) ) { return 0; } $this->rows[ $args[0] ] = $args[1]; return 1; }
        if ( 0 === strpos( $sql, 'UPDATE' ) ) { if ( ( $this->rows[ $args[1] ] ?? null ) !== $args[2] ) { return 0; } $this->rows[ $args[1] ] = $args[0]; return 1; }
        if ( 0 === strpos( $sql, 'DELETE' ) ) { if ( ( $this->rows[ $args[0] ] ?? null ) !== $args[1] ) { return 0; } unset( $this->rows[ $args[0] ] ); return 1; }
        throw new RuntimeException( 'Unexpected SQL.' );
    }
}
$wpdb = new SyncDB(); $http_calls = []; $http_reply = [ 'status' => 200, 'body' => '[]' ];
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-posting-client.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-writing-adapter.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-mapping-sync.php';
class QueueClient extends Nova_Bridge_Suite_Posting_Client {
    public $queue = []; public $calls = [];
    public function site_request( string $method, string $suffix, $body = null, array $headers = [] ) {
        $this->calls[] = [ $method, $suffix, $body, $headers ];
        if ( ! $this->queue ) { throw new RuntimeException( 'Unexpected request: ' . $method . ' ' . $suffix ); }
        $next = array_shift( $this->queue );
        if ( $next[0] !== $method || $next[1] !== $suffix ) { throw new RuntimeException( 'Wrong request order: ' . $method . ' ' . $suffix ); }
        return is_wp_error( $next[2] ) ? $next[2] : [ 'status' => 200, 'body' => $next[2], 'etag' => $next[2]['etag'] ?? '' ];
    }
}
class Nova_Bridge_Suite_Mapped_Writer {
    public static $blocked = false;
    public static function probe_mapping( array $template, array $mapping, array $local, array $context ) {
        if ( self::$blocked || ( $context['actor_user_id'] ?? 0 ) !== 7 ) { return new WP_Error( 'test_writer_blocked', 'Unverified writer', [ 'status' => 409 ] ); }
        return [ 'writer_id' => 'test_only', 'provider' => 'test_tuple', 'plugin_version' => 'test', 'db_engine' => 'InnoDB-test', 'coverage' => array_column( $mapping['bindings'], 'source_path' ), 'expires_at' => gmdate( 'c', time() + 3600 ) ];
    }
}
$checks = 0;
function check( $condition, $message ) { ++$GLOBALS['checks']; if ( ! $condition ) { throw new RuntimeException( $message ); } }
function error_is( $value, $suffix, $status = 409 ) { check( is_wp_error( $value ) && str_ends_with( $value->get_error_code(), $suffix ) && ( $value->get_error_data()['status'] ?? null ) === $status, 'Expected error ' . $suffix ); }
$site = '11111111-1111-4111-8111-111111111111';
$connection = [ 'base_url' => 'https://nova.example', 'site_id' => $site, 'token' => 'secret-site-token-test-only' ];
$client = new Nova_Bridge_Suite_Posting_Client( $connection );
$reply = $client->site_request( 'GET', '/writing/stock-templates' );
check( $reply['body'] === [] && $http_calls[0][0] === 'https://nova.example/v1/sites/' . $site . '/writing/stock-templates', 'Actual stock route is site-scoped and has a raw array response.' );
check( $http_calls[0][1]['headers']['Authorization'] === 'Bearer ' . $connection['token'] && $http_calls[0][1]['redirection'] === 0 && $http_calls[0][1]['sslverify'], 'HTTPS transport owns auth and disables redirects.' );
error_is( $client->request( 'GET', '/v1/sites/22222222-2222-4222-8222-222222222222/writing/stock-templates' ), 'site_scope', 403 );
error_is( $client->request( 'GET', '/v1/content/x', null, [ 'Authorization' => 'evil' ] ), 'header', 400 );
$http_reply = [ 'status' => 302, 'body' => '' ]; error_is( $client->request( 'GET', '/v1/content/x' ), 'redirect', 502 );
$http_reply = [ 'status' => 409, 'body' => '{"error":{"code":"conflict","message":"secret-site-token-test-only"}}' ];
$failure = $client->request( 'GET', '/v1/content/x' ); error_is( $failure, 'conflict' ); check( ! str_contains( $failure->get_error_message(), $connection['token'] ), 'Raw upstream error messages cannot echo secrets.' );
$bad = new Nova_Bridge_Suite_Posting_Client( array_merge( $connection, [ 'base_url' => 'http://nova.example' ] ) ); error_is( $bad->request( 'GET', '/v1/content/x' ), 'connection', 503 );
$field = static function ( $key ) { return [ 'field_key' => $key, 'label' => ucfirst( $key ), 'notes' => '', 'field_type' => 'plain_text', 'required' => true, 'minLength' => 1, 'maxLength' => 100 ]; };
$source = [ 'template_id' => '9007199254740993', 'template_version' => '9007199254740994', 'family' => 'service', 'owner_scope' => 'stock', 'fields' => [ $field( 'title' ), $field( 'summary' ) ], 'groups' => [], 'layout' => [ [ 'kind' => 'field', 'key' => 'title' ], [ 'kind' => 'field', 'key' => 'summary' ], [ 'kind' => 'protected_slot', 'key' => 'faq_block' ] ], 'protected_identities' => [ 'faq_block' ], 'etag' => 'stock-etag', 'state' => 'sealed', 'authoring_notes' => '' ];
$target = static function ( $path ) { return [ 'path' => $path, 'reference_type' => 'post', 'reference_id' => 10, 'signature' => 'layout-1', 'transport' => 'wordpress', 'request_path' => $path, 'write_mode' => 'replace' ]; };
$draft = [ 'revision' => 'local-1', 'schema_version' => 1, 'reference_type' => 'post', 'reference_id' => 10, 'signature' => 'layout-1', 'catalog_mode' => 'nova', 'template' => [ 'id' => $source['template_id'], 'revision' => $source['template_version'] ], 'label' => 'Service layout', 'guidance' => 'Schrijf helder / duidelijk.', 'fields' => [ '/title' => [ 'mode' => 'mapped', 'source_path' => 'title', 'instructions' => 'A useful heading.' ], '/faq' => [ 'mode' => 'protected', 'source_path' => '', 'instructions' => '', 'protected_slot' => 'faq_block' ] ], 'skipped_sources' => [ [ 'source_path' => 'summary', 'reason' => 'No summary in this layout.' ] ], 'repeat_slots' => [], 'routing' => [ 'operation' => 'update', 'locale' => 'nl-NL', 'post_type' => 'page', 'native_template' => '', 'publication' => 'preserve' ], 'target_descriptors' => [ '/title' => $target( '/title' ), '/faq' => $target( '/faq' ) ] ];
$prepared = Nova_Bridge_Suite_Writing_Adapter::prepare( $draft, $source );
check( count( $prepared['template']['fields'] ) === 1 && count( $source['fields'] ) === 2 && $prepared['template']['fields'][0]['notes'] === 'A useful heading.', 'Skip removes canonical generated field from site customization and preserves source stock.' );
check( $prepared['template']['layout'][1]['kind'] === 'protected_slot' && $prepared['template']['authoring_notes'] === $draft['guidance'], 'Protected layout and global instructions survive customization.' );
check( $prepared['bindings'][0]['expected_identity']['plugin_policy_digest'] === hash( 'sha256', $prepared['policy_json'] ), 'Policy digest covers canonical local routing/protection/skips.' );
check( Nova_Bridge_Suite_Writing_Adapter::canonical_json( [ 'z' => 'é/a', 'a' => [ 2, 1 ] ] ) === '{"a":[2,1],"z":"é/a"}', 'Canonical policy sorts keys and preserves UTF8/slashes/array order.' );
$too_long = $draft; $too_long['guidance'] = str_repeat( 'é', 4001 ); error_is( Nova_Bridge_Suite_Writing_Adapter::prepare( $too_long, $source ), 'notes_size', 400 );
$repeat_source = $source; $repeat_source['groups'] = [ [ 'group_key' => 'steps', 'label' => 'Steps', 'notes' => '', 'required' => true, 'minItems' => 1, 'maxItems' => 6, 'fields' => [ $field( 'step_heading' ), $field( 'step_body' ) ], 'preceding_anchor' => [ 'kind' => 'field', 'key' => 'summary' ] ] ];
array_splice( $repeat_source['layout'], 2, 0, [ [ 'kind' => 'repeat_group', 'key' => 'steps' ] ] );
$repeat = $draft; $repeat['skipped_sources'][] = [ 'source_path' => 'steps[].step_body', 'reason' => 'No body.' ]; $repeat['repeat_slots']['steps'] = [ [ 'id' => 'slot-a', 'targets' => [ 'step_heading' => '/step1' ] ], [ 'id' => 'slot-b', 'targets' => [ 'step_heading' => '/step2' ] ] ];
$repeat['target_descriptors']['/step1'] = $target( '/step1' ); $repeat['target_descriptors']['/step2'] = $target( '/step2' );
$repeat_prepared = Nova_Bridge_Suite_Writing_Adapter::prepare( $repeat, $repeat_source );
check( $repeat_prepared['template']['groups'][0]['minItems'] === 2 && $repeat_prepared['template']['groups'][0]['maxItems'] === 2 && count( $repeat_prepared['template']['groups'][0]['fields'] ) === 1, 'Existing fixed repeat slot count is exact in canonical schema; skipped member removed.' );
check( $repeat_prepared['template']['groups'][0]['preceding_anchor']['key'] === 'title' && $repeat_prepared['template']['groups'][0]['following_anchor']['kind'] === 'protected', 'Skipped adjacent fields cannot leave dangling anchors.' );
check( $repeat_prepared['bindings'][1]['target_descriptor']['slots'][1]['slot_id'] === 'slot-b', 'Canonical wildcard binding preserves fixed native slot ordering.' );
$repeat['repeat_slots']['steps'][1]['targets'] = []; error_is( Nova_Bridge_Suite_Writing_Adapter::prepare( $repeat, $repeat_source ), 'repeat_incomplete', 400 );
$clone = array_merge( $source, [ 'template_id' => '101', 'template_version' => '102', 'source_template_id' => $source['template_id'], 'source_template_version' => $source['template_version'], 'owner_scope' => 'site', 'site_id' => $site, 'state' => 'draft', 'etag' => 'clone-etag' ] );
$template = array_merge( $clone, $prepared['template'], [ 'etag' => 'edited-etag' ] );
$mapping = [ 'mapping_id' => '201', 'mapping_revision_id' => '202', 'cpt' => 'page', 'layout_key' => 'wp_post_10', 'template_id' => '101', 'template_version' => '102', 'bindings' => $prepared['bindings'], 'state' => 'draft', 'etag' => 'mapping-etag' ];
$assignment = [ 'assignment_id' => '301', 'assignment_revision_id' => '302', 'cpt' => 'page', 'layout_key' => 'wp_post_10', 'template_id' => '101', 'template_version' => '102', 'mapping_id' => '201', 'mapping_revision_id' => '202', 'state' => 'draft', 'etag' => 'assignment-etag' ];
$client = new QueueClient( $connection ); $service = new Nova_Bridge_Suite_Mapping_Sync( $client );
$client->queue = [ [ 'GET', '/writing/stock-templates', [ $source ] ], [ 'POST', '/writing/template-clones', $clone ], [ 'PUT', '/writing/templates/101', new WP_Error( 'lost_response', 'Transport uncertain', [ 'status' => 502 ] ) ] ];
error_is( $service->synchronize( $draft ), 'lost_response', 502 );
$pending = Nova_Bridge_Suite_Mapping_Sync::state( 'post', 10, $site );
check( $pending['pending']['name'] === 'replace_template' && $pending['steps']['clone_template']['template_id'] === '101', 'Remote IDs and pending request are durable before retry.' );
$newer = $draft; $newer['revision'] = 'local-2'; error_is( $service->synchronize( $newer ), 'previous_pending' );
$client->queue = [ [ 'PUT', '/writing/templates/101', new WP_Error( 'conflict', 'Stale etag', [ 'status' => 409 ] ) ], [ 'GET', '/writing/templates/101?template_version=102', $template ], [ 'POST', '/writing/mappings', $mapping ], [ 'POST', '/writing/assignments', $assignment ] ];
$synced = $service->synchronize( $draft ); check( ! is_wp_error( $synced ) && $synced['status'] === 'synced_draft', 'Lost PUT acknowledgement reconciles only exact requested content.' );
check( $client->calls[2][2] === $client->calls[3][2] && $client->calls[2][3] === $client->calls[3][3], 'Recovery replays original body and If-Match unchanged.' );
$count = count( $client->calls ); check( $service->synchronize( $draft )['status'] === 'synced_draft' && count( $client->calls ) === $count, 'Repeated successful synchronization does not create more objects.' );
Nova_Bridge_Suite_Mapped_Writer::$blocked = true; error_is( $service->activate( $draft, [ 'actor_user_id' => 7 ] ), 'writer_blocked' ); check( count( $client->calls ) === $count, 'Failed capability probe sends no evidence or activation.' ); Nova_Bridge_Suite_Mapped_Writer::$blocked = false;
$sealed_template = array_merge( $template, [ 'state' => 'sealed', 'etag' => 'sealed-template' ] ); $sealed_mapping = array_merge( $mapping, [ 'state' => 'sealed', 'etag' => 'sealed-mapping' ] ); $sealed_assignment = array_merge( $assignment, [ 'state' => 'sealed', 'etag' => 'sealed-assignment' ] );
$pin = [ 'pin_id' => '401', 'site_id' => $site, 'digest' => str_repeat( 'a', 64 ), 'template_id' => '101', 'template_version' => '102', 'mapping_id' => '201', 'mapping_revision_id' => '202', 'assignment_id' => '301', 'assignment_revision_id' => '302', 'contract' => [] ];
$client->queue = [ [ 'POST', '/writing/evidence', [ 'evidence_id' => '501' ] ], [ 'POST', '/writing/pins/seal', $pin ], [ 'GET', '/writing/templates/101?template_version=102', $sealed_template ], [ 'GET', '/writing/mappings/201?mapping_revision_id=202', $sealed_mapping ], [ 'GET', '/writing/assignments/301?assignment_revision_id=302', $sealed_assignment ], [ 'GET', '/writing/assignments/301/lifecycle', [ 'assignment_id' => '301', 'etag' => 'lifecycle-etag' ] ], [ 'POST', '/writing/assignments/301/activate', [ 'pin_id' => '401', 'digest' => $pin['digest'], 'status' => 'active' ] ] ];
$active = $service->activate( $draft, [ 'actor_user_id' => 7 ] ); check( ! is_wp_error( $active ) && $active['status'] === 'active', 'Fresh capability proof, seal, immutable retention and conditional activation complete.' );
$last = end( $client->calls ); check( $last[3]['If-Match'] === 'lifecycle-etag' && isset( $last[3]['Idempotency-Key'] ), 'Activation uses lifecycle precondition plus stable idempotency.' );
$retained = Nova_Bridge_Suite_Mapping_Sync::configuration( '401', $pin['digest'], $site ); check( $retained['local']['revision'] === 'local-1' && $retained['mapping']['state'] === 'sealed', 'Exact historical configuration retains the approved local snapshot.' );
error_is( Nova_Bridge_Suite_Mapping_Sync::configuration( '401', str_repeat( 'b', 64 ), $site ), 'configuration_missing' );
$different = $draft; $different['routing']['publication'] = 'publish'; error_is( Nova_Bridge_Suite_Mapping_Sync::retain_configuration( $pin, $sealed_template, $sealed_mapping, $sealed_assignment, $different ), 'pin_conflict' );
$remote_queue = [ [ 'GET', '/writing/pins/401', $pin ], [ 'GET', '/writing/templates/101?template_version=102', $sealed_template ], [ 'GET', '/writing/mappings/201?mapping_revision_id=202', $sealed_mapping ], [ 'GET', '/writing/assignments/301?assignment_revision_id=302', $sealed_assignment ] ];
$client->queue = $remote_queue; $exact = Nova_Bridge_Suite_Mapping_Sync::exact_configuration( $client, '401', $pin['digest'], $site ); check( $exact['local']['revision'] === 'local-1', 'Even retained configurations are checked against exact remote sealed identities.' );
unset( $wpdb->rows[ 'nova_mapping_pin_' . hash( 'sha256', $site . ':401' ) ] );
$client->queue = $remote_queue; $recovered = Nova_Bridge_Suite_Mapping_Sync::exact_configuration( $client, '401', $pin['digest'], $site );
check( ! is_wp_error( $recovered ) && $recovered['local']['fields']['/faq']['protected_slot'] === 'faq_block' && Nova_Bridge_Suite_Writing_Adapter::canonical_json( $recovered['local']['skipped_sources'] ) === Nova_Bridge_Suite_Writing_Adapter::canonical_json( $draft['skipped_sources'] ), 'Restore reconstructs exact protection and skip policy from immutable mapping, never current assignment.' );
$client->queue = [ [ 'GET', '/writing/pins/401', array_merge( $pin, [ 'digest' => str_repeat( 'b', 64 ) ] ) ] ]; error_is( Nova_Bridge_Suite_Mapping_Sync::exact_configuration( $client, '401', $pin['digest'], $site ), 'pin_mismatch' );
check( $client->queue === [], 'All expected protocol calls consumed.' );
$acquire = new ReflectionMethod( Nova_Bridge_Suite_Mapping_Sync::class, 'acquire' );
$checkpoint = new ReflectionMethod( Nova_Bridge_Suite_Mapping_Sync::class, 'checkpoint' );
$release = new ReflectionMethod( Nova_Bridge_Suite_Mapping_Sync::class, 'release' );
check( true === $acquire->invoke( $service, $draft ), 'Checkpoint race fixture acquires the configuration lease.' );
$state_name = 'nova_mapping_sync_' . hash( 'sha256', $site . ':post:10' ); $lock_name = $state_name . '_lock';
$winner = get_option( $state_name ); $winner['local']['guidance'] = 'New owner retained this edit.';
$race = null;
$race = static function ( $db, $query ) use ( &$race, $state_name, $lock_name, $winner ) {
    if ( false === strpos( $query[0], 'INNER JOIN' ) ) { $db->before_query = $race; return; }
    $db->rows[ $lock_name ] = maybe_serialize( [ 'token' => 'new-owner', 'expires' => time() + 300 ] );
    $db->rows[ $state_name ] = maybe_serialize( $winner );
};
$wpdb->before_query = $race;
error_is( $checkpoint->invoke( $service ), 'lease_lost' );
check( get_option( $state_name ) === $winner, 'A lease replacement between ownership read and state write cannot overwrite the new owner.' );
$release->invoke( $service );
check( get_option( $lock_name )['token'] === 'new-owner', 'Stale cleanup cannot delete the replacement owner lease.' );
echo "mapping-sync-unit: $checks checks passed\n";
