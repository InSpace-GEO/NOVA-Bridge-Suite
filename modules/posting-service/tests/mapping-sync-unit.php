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
function wp_safe_remote_request( $url, $args ) { $GLOBALS['http_calls'][] = [ $url, $args ]; return isset( $GLOBALS['http_handler'] ) ? $GLOBALS['http_handler']( $url, $args ) : $GLOBALS['http_reply']; }
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

require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-posting-protocol.php';
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-posting-client.php';
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-writing-adapter.php';
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-mapping-sync.php';
class QueueClient extends Nova_Bridge_Suite_Posting_Client {
    public $queue = []; public $calls = [];
    public function site_request( string $method, string $suffix, $body = null, array $headers = [] ) {
        $this->calls[] = [ $method, $suffix, $body, $headers ];
        if ( ! $this->queue ) { throw new RuntimeException( 'Unexpected request ' . $method . ' ' . $suffix ); }
        $next = array_shift( $this->queue );
        if ( $next[0] !== $method || $next[1] !== $suffix ) { throw new RuntimeException( 'Wrong request order ' . $method . ' ' . $suffix ); }
        return $next[2];
    }
}
class Nova_Bridge_Suite_Mapped_Writer {
    public static $blocked = false;
    public static function probe_mapping( array $template, array $mapping, array $local, array $context ) {
        return self::$blocked || ( $context['actor_user_id'] ?? 0 ) !== 7 ? new WP_Error( 'test_writer_blocked', 'Unverified writer', [ 'status' => 409 ] ) : [ 'writer_id' => 'fixture', 'provider' => 'fixture', 'expires_at' => gmdate( 'c', time() + 3600 ) ];
    }
}
function home_url( $path = '/' ) { return 'https://wordpress.example' . $path; }
function get_permalink( $id ) { return isset( $GLOBALS['fixture_permalink'] ) ? $GLOBALS['fixture_permalink'] : 'https://wordpress.example/page-' . $id . '/'; }
$checks = 0;
function check( $condition, $message ) { ++$GLOBALS['checks']; if ( ! $condition ) { throw new RuntimeException( $message ); } }
function error_is( $value, $suffix, $status = 409 ) { check( is_wp_error( $value ) && str_ends_with( $value->get_error_code(), $suffix ) && ( $value->get_error_data()['status'] ?? null ) === $status, 'Expected error ' . $suffix . ', got ' . ( is_wp_error( $value ) ? $value->get_error_code() : 'success' ) ); }
function reply( array $record, int $status = 200 ): array { return [ 'status' => $status, 'body' => $record, 'etag' => '"' . $record['revision'] . '"' ]; }
$site = '11111111-1111-4111-8111-111111111111'; $remote_id = '22222222-2222-4222-8222-222222222222';
$connection = [ 'base_url' => 'https://nova.example', 'site_id' => $site, 'token' => 'fixture-token-not-a-credential' ];
$target = static function ( $path ) { return [ 'path' => $path, 'reference_type' => 'post', 'reference_id' => 10, 'signature' => 'layout-1', 'transport' => 'wordpress', 'request_path' => $path, 'write_mode' => 'replace' ]; };
$draft = [ 'revision' => 'local-1', 'schema_version' => 1, 'reference_type' => 'post', 'reference_id' => 10, 'signature' => 'layout-1', 'catalog_mode' => 'nova', 'template' => [ 'id' => 'nova-delivery-fields-v1', 'revision' => '1' ], 'profile_page_type' => 'service', 'label' => 'Service layout', 'guidance_mode' => 'set', 'guidance' => 'Exactly one relevant label; keep the layout limits.', 'fields' => [ '/title' => [ 'mode' => 'mapped', 'source_path' => 'title', 'required' => true, 'instructions' => 'At most 30 characters.' ], '/faq' => [ 'mode' => 'protected', 'source_path' => '', 'instructions' => 'Retain original copy.' ] ], 'skipped_sources' => [ [ 'source_path' => 'meta_description', 'reason' => 'No metadata destination.' ] ], 'repeat_slots' => [], 'routing' => [ 'operation' => 'update', 'locale' => 'nl-NL', 'post_type' => 'page', 'native_template' => '', 'publication' => 'preserve' ], 'target_descriptors' => [ '/title' => $target( '/title' ), '/faq' => $target( '/faq' ) ] ];
$original = $draft; $input = Nova_Bridge_Suite_Writing_Adapter::template_input( $draft );
check( ! is_wp_error( $input ) && count( Nova_Bridge_Suite_Writing_Adapter::source_fields() ) === 12, 'Adapter supports exact twelve stock source names.' );
check( true === Nova_Bridge_Suite_Posting_Protocol::validate( $input, 'PublishingTemplateInput' ) && $input['definition']['fields'][0]['required'] === true, 'Actual pinned schema accepts exact current projection and explicit required choice.' );
check( $input['mapping']['fields'][0] === [ 'field_id' => Nova_Bridge_Suite_Writing_Adapter::field_id( '/title' ), 'source_field' => 'title' ], 'Remote field identity is deterministic for the local destination.' );
check( ! str_contains( json_encode( $input ), 'instructions' ) && ! str_contains( json_encode( $input ), 'Exactly one' ) && ! str_contains( json_encode( $input ), 'reference_id' ) && $draft === $original, 'Human instructions and native addresses are retained unchanged locally, never disguised as remote fields.' );
check( str_contains( implode( ' ', Nova_Bridge_Suite_Writing_Adapter::warnings( $draft ) ), 'does not accept them' ), 'Unsupported instruction synchronization is explicit.' );
check( Nova_Bridge_Suite_Writing_Adapter::canonical_json( [ 'z' => 'é/a', 'a' => [ 2, 1 ] ] ) === '{"a":[2,1],"z":"é/a"}', 'Canonical profile preserves UTF8, slash and array order.' );
$bad = $draft; unset( $bad['template'] ); error_is( Nova_Bridge_Suite_Writing_Adapter::template_input( $bad ), 'legacy_catalog' );
$bad = $draft; $bad['fields']['/title']['source_path'] = 'steps[].label'; error_is( Nova_Bridge_Suite_Writing_Adapter::template_input( $bad ), 'source_unsupported' );
$bad = $draft; $bad['repeat_slots']['steps'] = [ [ 'id' => 'retained-slot', 'targets' => [] ] ]; $before = $bad; error_is( Nova_Bridge_Suite_Writing_Adapter::template_input( $bad ), 'repeat_unsupported' ); check( $before === $bad, 'Unsupported historical repeats are not rewritten or backfilled.' );
$bad = $draft; $bad['guidance'] = str_repeat( 'é', 4001 ); error_is( Nova_Bridge_Suite_Writing_Adapter::template_input( $bad ), 'notes_size', 400 );
$bad['guidance'] = str_repeat( 'é', 4000 ); check( ! is_wp_error( Nova_Bridge_Suite_Writing_Adapter::template_input( $bad ) ), '8000 UTF8 bytes of local rules remain supported.' );
$mirror = $draft; $mirror['fields']['/second-title'] = $draft['fields']['/title']; $mirror['target_descriptors']['/second-title'] = $target( '/second-title' ); check( count( Nova_Bridge_Suite_Writing_Adapter::template_input( $mirror )['definition']['fields'] ) === 2, 'One delivered source may intentionally fill distinct native destinations.' );
$record = $input + [ 'id' => $remote_id, 'revision' => 1, 'created_at' => '2026-10-02T10:00:00Z', 'updated_at' => '2026-10-02T10:00:00Z', 'retired_at' => null ];
$api = new QueueClient( $connection ); $api->queue = [ [ 'POST', '/templates', reply( $record, 201 ) ] ];
$service = new Nova_Bridge_Suite_Mapping_Sync( $api ); $state = $service->synchronize( $draft );
check( ! is_wp_error( $state ) && $state['status'] === 'synced_draft' && $state['instructions_sync'] === 'unsupported_local_only', 'Template POST stores exact successful revision but does not imply native approval.' );
check( $api->calls[0][2] === $input && $api->calls[0][3] === [], 'POST uses current exact wire shape with no invented idempotency header.' );
$snapshot = [ 'site_id' => $site, 'configuration' => array_intersect_key( $record, array_flip( [ 'id', 'name', 'page_type', 'definition', 'mapping', 'revision' ] ) ), 'url_id' => '99', 'url' => get_permalink( 10 ) ];
error_is( Nova_Bridge_Suite_Mapping_Sync::configuration_for_snapshot( $snapshot, $site ), 'profile_unapproved' );
$api->queue = [ [ 'GET', '/templates/' . $remote_id, reply( $record ) ] ];
Nova_Bridge_Suite_Mapped_Writer::$blocked = true; error_is( $service->activate( $draft, [ 'actor_user_id' => 7 ] ), 'test_writer_blocked' );
Nova_Bridge_Suite_Mapped_Writer::$blocked = false;
$api->queue = [ [ 'GET', '/templates/' . $remote_id, reply( $record ) ] ];
$active = $service->activate( $draft, [ 'actor_user_id' => 7 ] );
check( $active['status'] === 'active', 'Local approval requires successful exact revision read and writer probe.' );
$profile = Nova_Bridge_Suite_Mapping_Sync::configuration_for_snapshot( $snapshot, $site );
check( $profile['local'] === $draft && $profile['profile_digest'] === hash( 'sha256', Nova_Bridge_Suite_Writing_Adapter::canonical_json( $draft ) ), 'Approved profile retains instructions, native guards and exact routing immutably.' );
$policy = Nova_Bridge_Suite_Mapping_Sync::frozen_policy( $api, $snapshot, $profile );
check( $policy['reference']['reference_id'] === 10 && $policy['routing'] === $draft['routing'] && count( $api->calls ) === 3, 'Frozen profile lookup/policy require no removed pin, assignment or binding reads.' );
$wrong = $snapshot; $wrong['configuration']['revision'] = 2; error_is( Nova_Bridge_Suite_Mapping_Sync::configuration_for_snapshot( $wrong, $site ), 'profile_unapproved' );
$wrong = $snapshot; $wrong['configuration']['name'] = 'Other'; error_is( Nova_Bridge_Suite_Mapping_Sync::configuration_for_snapshot( $wrong, $site ), 'profile_unapproved' );
$wrong = $snapshot; $wrong['configuration'] = null; error_is( Nova_Bridge_Suite_Mapping_Sync::configuration_for_snapshot( $wrong, $site ), 'profile_missing' );
$wrong = $snapshot; $wrong['url'] = 'https://outside.example/page-10/'; error_is( Nova_Bridge_Suite_Mapping_Sync::frozen_policy( $api, $wrong, $profile ), 'target_url' );
$wrong['url'] = get_permalink( 11 ); error_is( Nova_Bridge_Suite_Mapping_Sync::frozen_policy( $api, $wrong, $profile ), 'target_url' );
$GLOBALS['fixture_permalink'] = 'https://wordpress.example/?page_id=10';
$plain = $snapshot; $plain['url'] = get_permalink( 10 );
check( ! is_wp_error( Nova_Bridge_Suite_Mapping_Sync::frozen_policy( $api, $plain, $profile ) ), 'An exact draft/plain query permalink identifies the approved native page.' );
$plain['url'] = 'https://wordpress.example/?page_id=11'; error_is( Nova_Bridge_Suite_Mapping_Sync::frozen_policy( $api, $plain, $profile ), 'target_url' );
unset( $GLOBALS['fixture_permalink'] );
$changed_profile = $profile; $changed_profile['local']['routing']['operation'] = 'clone'; error_is( Nova_Bridge_Suite_Mapping_Sync::frozen_policy( $api, $snapshot, $changed_profile ), 'profile_identity' );
// Same template, a new local revision: conditional replace with lost acknowledgement.
$next = $draft; $next['revision'] = 'local-2'; $next['guidance'] = 'Updated local rule.';
$record2 = $record; $record2['revision'] = 2; $record2['updated_at'] = '2026-10-02T10:01:00Z';
$api->queue = [ [ 'PUT', '/templates/' . $remote_id, new WP_Error( 'transport_timeout', 'unknown acknowledgement', [ 'status' => 503 ] ) ] ];
error_is( $service->synchronize( $next ), 'transport_timeout', 503 );
$last = end( $api->calls ); check( $last[3] === [ 'If-Match' => '"1"' ], 'Template updates use the exact reviewed revision ETag.' );
$api->queue = [ [ 'GET', '/templates/' . $remote_id, reply( $record2 ) ] ];
$recovered = $service->synchronize( $next ); check( $recovered['status'] === 'synced_draft' && $recovered['template']['revision'] === 2 && count( $api->queue ) === 0, 'Lost PUT acknowledgement recovers exact revision+1 through GET without another mutation.' );
check( Nova_Bridge_Suite_Mapping_Sync::configuration_for_snapshot( $snapshot, $site )['local']['guidance'] === $draft['guidance'], 'Older delivery keeps the old human rules after a local edit.' );
$api->queue = [ [ 'GET', '/templates/' . $remote_id, reply( $record2 ) ] ]; check( $service->activate( $next, [ 'actor_user_id' => 7 ] )['status'] === 'active', 'New remote revision has separate native approval.' );
// A lost POST cannot be retried automatically.
$fresh = $draft; $fresh['reference_id'] = 20; $fresh['revision'] = 'fresh-1'; $new_id = '33333333-3333-4333-8333-333333333333';
$new_record = $record; $new_record['id'] = $new_id;
$fresh_api = new QueueClient( $connection ); $fresh_service = new Nova_Bridge_Suite_Mapping_Sync( $fresh_api );
$fresh_api->queue = [ [ 'POST', '/templates', new WP_Error( 'transport_timeout', 'unknown', [ 'status' => 503 ] ) ] ];
error_is( $fresh_service->synchronize( $fresh ), 'transport_timeout', 503 );
error_is( $fresh_service->synchronize( $fresh ), 'create_unknown' ); check( count( $fresh_api->calls ) === 1, 'Unknown create never duplicates a remote template.' );
$fresh_api->queue = [ [ 'GET', '/templates/' . $new_id, reply( $new_record ) ] ];
check( $fresh_service->synchronize( $fresh, $new_id )['template']['id'] === $new_id, 'Explicit UUID recovery reads and compares the actual created record.' );
// Database lease loss cannot overwrite a new owner between owned() and checkpoint SQL.
$lease_draft = $draft; $lease_draft['reference_id'] = 30;
$lease = new Nova_Bridge_Suite_Mapping_Sync( new QueueClient( $connection ) );
$acquire = new ReflectionMethod( $lease, 'acquire' ); $checkpoint = new ReflectionMethod( $lease, 'checkpoint' ); $release = new ReflectionMethod( $lease, 'release' );
check( true === $acquire->invoke( $lease, $lease_draft ), 'Lease acquired for atomic checkpoint test.' );
$property = new ReflectionProperty( $lease, 'state' ); $property->setValue( $lease, [ 'status' => 'old_owner' ] );
$lock_property = new ReflectionProperty( $lease, 'lock_name' ); $lock_name = $lock_property->getValue( $lease );
$wpdb->before_query = static function ( $db ) use ( $lock_name ) { $db->rows[ $lock_name ] = serialize( [ 'token' => 'new-owner', 'expires' => time() + 300 ] ); };
error_is( $checkpoint->invoke( $lease ), 'lease_lost' ); $release->invoke( $lease );
check( maybe_unserialize( $wpdb->rows[ $lock_name ] )['token'] === 'new-owner', 'Stale release does not remove the new owner lease.' );
echo 'PASS ' . $checks . " current mapping sync checks.\n";
