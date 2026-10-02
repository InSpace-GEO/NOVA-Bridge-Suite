<?php
/** Current pinned delivery contract, exact byte integrity and acknowledgement boundaries. */
define( 'ABSPATH', __DIR__ );
class WP_Error { private $code; public function __construct( $code, $message = '', $data = [] ) { $this->code = $code; } public function get_error_code() { return $this->code; } }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-posting-protocol.php';
require __DIR__ . '/contract-fixture.php';
$checks = 0;
function protocol_check( $ok, $message ) { ++$GLOBALS['checks']; if ( ! $ok ) { throw new RuntimeException( $message ); } }
function protocol_valid( $v, $name = 'DeliverySnapshot' ) { return true === Nova_Bridge_Suite_Posting_Protocol::validate( $v, $name ); }
$fixture = nova_contract_fixture(); $object = json_decode( $fixture['raw'] );
protocol_check( protocol_valid( $object ), 'Complete current wire snapshot accepted.' );
foreach ( array_keys( (array) $object ) as $key ) { if ( 'attempt_id' === $key ) { continue; } $bad = clone $object; unset( $bad->$key ); protocol_check( ! protocol_valid( $bad ), 'Required snapshot field ' . $key ); }
foreach ( [ 'configuration', 'content' ] as $key ) { $bad = json_decode( $fixture['raw'] ); $bad->$key->uncontracted = true; protocol_check( ! protocol_valid( $bad ), 'Closed nested object ' . $key ); }
foreach ( [ 'configuration', 'content' ] as $key ) { $bad = clone $object; $bad->$key = []; protocol_check( ! protocol_valid( $bad ), 'List cannot impersonate object ' . $key ); }
$null = json_decode( $fixture['raw'] ); $null->configuration = null; $null->content->image_urls = null;
protocol_check( protocol_valid( $null ), 'Null configuration and nullable source values remain transport-valid.' );
$bad = json_decode( $fixture['raw'] ); $bad->content->image_urls = (object) []; protocol_check( ! protocol_valid( $bad ), 'Image URLs must be list or null.' );
$bad = clone $object; $bad->pin = (object) []; protocol_check( ! protocol_valid( $bad ), 'Historical generation/pin contract is not accepted.' );
foreach ( [ '2026-02-30T12:00:00Z', '2026-10-02', '2026-10-02T24:00:00Z', '2026-10-02T12:00:00+25:00' ] as $date ) { protocol_check( ! Nova_Bridge_Suite_Posting_Protocol::date_time( $date ), 'Invalid RFC3339 date rejected.' ); }
foreach ( [ '2026-10-02T12:00:00Z', '2026-10-02T12:00:00.123Z', '2026-10-02T12:00:00.123456Z', '2026-10-02T14:00:00+02:00' ] as $date ) { protocol_check( Nova_Bridge_Suite_Posting_Protocol::date_time( $date ), 'Current RFC3339 date accepted.' ); }
$response = [ 'status' => 200, 'raw_body' => $fixture['raw'], 'etag' => $fixture['etag'], 'attempt_id' => $fixture['attempt_id'] ];
protocol_check( Nova_Bridge_Suite_Posting_Protocol::snapshot( $response, $fixture['job'] ) === $fixture['snapshot'], 'Exact response validated without rewriting snapshot.' );
protocol_check( $fixture['item']['source_sha256'] !== hash( 'sha256', $fixture['raw'] ), 'Source hash and raw delivery hash are distinct domains.' );
foreach ( [ 'whitespace', 'weak_etag', 'old_etag', 'absent_attempt', 'bad_attempt', 'wrong_site', 'wrong_civ', 'wrong_delivery', 'wrong_hash', 'bad_id', 'zero_version', 'numeric_id', 'old_protocol' ] as $case ) {
    $r = $response; $job = $fixture['job']; $body = $fixture['snapshot'];
    if ( 'whitespace' === $case ) { $r['raw_body'] .= "\n"; }
    if ( 'weak_etag' === $case ) { $r['etag'] = 'W/' . $r['etag']; }
    if ( 'old_etag' === $case ) { $r['etag'] = '"sha256:' . hash( 'sha256', $r['raw_body'] ) . '"'; }
    if ( 'absent_attempt' === $case ) { unset( $r['attempt_id'] ); }
    if ( 'bad_attempt' === $case ) { $r['attempt_id'] = 'bad'; }
    if ( 'wrong_site' === $case ) { $job['site_id'] = '00000000-0000-4000-8000-000000000002'; }
    if ( 'wrong_civ' === $case ) { $job['payload']['discovery']['content_item_version_id'] = '999'; }
    if ( 'wrong_delivery' === $case ) { $job['payload']['discovery']['id'] = '00000000-0000-4000-8000-000000000002'; }
    if ( 'wrong_hash' === $case ) { $job['payload']['discovery']['source_sha256'] = str_repeat( 'c', 64 ); }
    if ( in_array( $case, [ 'bad_id', 'zero_version', 'numeric_id', 'old_protocol' ], true ) ) {
        if ( 'bad_id' === $case ) { $body['url_id'] = '9223372036854775808'; }
        if ( 'zero_version' === $case ) { $body['version_number'] = 0; }
        if ( 'numeric_id' === $case ) { $body['client_id'] = 5; }
        if ( 'old_protocol' === $case ) { $body['protocol'] = 'nova.publication-snapshot/v1'; }
        $r['raw_body'] = json_encode( $body ); $r['etag'] = '"' . hash( 'sha256', $r['raw_body'] ) . '"';
    }
    protocol_check( is_wp_error( Nova_Bridge_Suite_Posting_Protocol::snapshot( $r, $job ) ), 'Snapshot rejected: ' . $case );
}
$retry = $response; $retry['attempt_id'] = '00000000-0000-4000-8000-000000000099';
protocol_check( $fixture['snapshot'] === Nova_Bridge_Suite_Posting_Protocol::snapshot( $retry, $fixture['job'] ), 'Current header attempt may differ from frozen historical body attempt.' );
$without = $fixture['snapshot']; unset( $without['attempt_id'] ); $retry['raw_body'] = json_encode( $without ); $retry['etag'] = '"' . hash( 'sha256', $retry['raw_body'] ) . '"';
protocol_check( $without === Nova_Bridge_Suite_Posting_Protocol::snapshot( $retry, $fixture['job'] ), 'Optional body attempt can be absent when header authorizes current work.' );
foreach ( [ 'received_complete', 'publication_succeeded', 'publication_failed', 'pull_failed' ] as $kind ) {
    $event = nova_contract_fixture_event( $fixture, $kind );
    protocol_check( true === Nova_Bridge_Suite_Posting_Protocol::event_input( $event ), 'Exact event outcome evidence accepted: ' . $kind );
    $accepted = nova_contract_fixture_event_accept( json_encode( $event ) ); $ack = [ 'status' => 202, 'raw_body' => json_encode( $accepted ) ];
    protocol_check( $accepted === Nova_Bridge_Suite_Posting_Protocol::accepted( $ack, $event ), '202 acknowledges pending intent: ' . $kind );
    $bad = $event; $bad['extra'] = true; protocol_check( is_wp_error( Nova_Bridge_Suite_Posting_Protocol::event_input( $bad ) ), 'Uncontracted event members rejected.' );
    if ( 'publication_succeeded' === $kind ) { unset( $bad['extra'], $bad['published_at'] ); }
    elseif ( 'received_complete' === $kind ) { unset( $bad['extra'] ); $bad['reason'] = 'unexpected'; }
    else { unset( $bad['extra'], $bad['reason'] ); }
    protocol_check( is_wp_error( Nova_Bridge_Suite_Posting_Protocol::event_input( $bad ) ), 'Missing or forbidden conditional evidence rejected.' );
    $accepted['status'] = 'applied'; $ack['raw_body'] = json_encode( $accepted ); protocol_check( is_wp_error( Nova_Bridge_Suite_Posting_Protocol::accepted( $ack, $event ) ), 'Acceptance cannot claim backend applied.' );
}
$event = nova_contract_fixture_event( $fixture ); $accepted = nova_contract_fixture_event_accept( json_encode( $event ) );
foreach ( [ 'event_id', 'delivery_id', 'site_id', 'attempt_id', 'kind' ] as $key ) { $bad = $accepted; $bad[$key] = 'kind' === $key ? 'publication_failed' : '00000000-0000-4000-8000-000000000099'; protocol_check( is_wp_error( Nova_Bridge_Suite_Posting_Protocol::accepted( [ 'status' => 202, 'raw_body' => json_encode( $bad ) ], $event ) ), 'Echo identity mismatch rejected: ' . $key ); }
foreach ( [ 200, 201, 204 ] as $status ) { protocol_check( is_wp_error( Nova_Bridge_Suite_Posting_Protocol::accepted( [ 'status' => $status, 'raw_body' => json_encode( $accepted ) ], $event ) ), 'Legacy acknowledgement code rejected.' ); }
$defaults = (object) [ 'defaults' => (object) [], 'revision' => 1 ]; protocol_check( protocol_valid( $defaults, 'PublishingTemplateDefaults' ), 'Empty defaults are an object.' );
$defaults->defaults->{'Bad_key'} = $fixture['snapshot']['configuration']['id']; protocol_check( ! protocol_valid( $defaults, 'PublishingTemplateDefaults' ), 'Default source page-type keys follow current contract.' );
echo "PASS {$checks} current delivery protocol checks\n";
