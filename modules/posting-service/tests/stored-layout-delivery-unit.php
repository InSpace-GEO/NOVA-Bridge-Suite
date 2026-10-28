<?php
/** Real stored-layout compiler/writer through default worker dispatch, with deterministic API and CMS. */
require __DIR__ . '/stored-layout-writer-unit.php';
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-receipt-json.php';
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-posting-worker.php';
require_once __DIR__ . '/contract-fixture.php';
function wp_generate_uuid4() { static $n = 5000; return op( ++$n ); }
class Nova_Bridge_Suite_Mapping_Sync {
    public static function configuration_for_snapshot( $snapshot, $site ) { return $GLOBALS['delivery_configuration']; }
    public static function frozen_policy( $client, $snapshot, $configuration ) { return Nova_Bridge_Suite_Stored_Layout_Writer::policy( $snapshot, $configuration ); }
}
class Layout_Delivery_Store {
    public $history = []; public $locks = []; public $lock_history = [];
    public function save( $job, $changes ) { $saved = array_merge( $job, $changes ); $this->history[] = $saved; return $saved; }
    public function lock( $key ) { $this->locks[$key] = true; $this->lock_history[] = $key; return true; }
    public function unlock( $key ) { unset( $this->locks[$key] ); }
    public function uncertain_target( $job ) { return false; }
}
class Layout_Delivery_Client {
    public $fixture; public $events = []; public $gets = []; public $lose_receipt = false;
    public function __construct( $fixture ) { $this->fixture = $fixture; }
    public function site_request( $method, $path ) {
        $expected = '/deliveries/' . $this->fixture['snapshot']['id'];
        check( $method === 'GET' && $path === $expected, 'Each mapping version uses its contracted result endpoint.' ); $this->gets[] = $path;
        return [ 'status' => 200, 'raw_body' => $this->fixture['raw'], 'etag' => $this->fixture['etag'], 'attempt_id' => $this->fixture['attempt_id'] ];
    }
    public function site_request_bytes( $method, $path, $bytes ) {
        check( $method === 'POST' && $path === '/deliveries/' . $this->fixture['snapshot']['id'] . '/events', 'Stored-layout publication keeps the existing event contract.' );
        $event = json_decode( $bytes, true ); $this->events[] = $event;
        if ( $this->lose_receipt && $event['kind'] === 'publication_succeeded' ) { $this->lose_receipt = false; throw new RuntimeException( 'Receipt acknowledgement lost' ); }
        return [ 'status' => 202, 'raw_body' => json_encode( nova_contract_fixture_event_accept( $bytes ) ) ];
    }
}
function delivery_setup( $version = 3, $target_url = null, $publication = 'draft' ) {
    reset_native(); list( $content, $configuration ) = fixture( '501', $version, $publication );
    if ( null !== $target_url ) { $content['url'] = $target_url; }
    if ( $version === 1 ) { $configuration['local']['template'] = [ 'id' => 'nova-delivery-fields-v1', 'revision' => '1' ]; }
    $wire = ok( Nova_Bridge_Suite_Writing_Adapter::template_input( $configuration['local'] ) ); unset( $wire['enabled'] );
    $configuration['template'] = [ 'id' => op( 99 ), 'revision' => 1 ] + $wire; $configuration['profile_digest'] = hash( 'sha256', Nova_Bridge_Suite_Writing_Adapter::canonical_json( $configuration['local'] ) );
    $base = nova_contract_fixture( [ 'configuration' => $configuration ] );
    if ( $version === 1 ) { $content['content'] = array_replace( $base['snapshot']['content'], $content['content'] ); }
    $base['snapshot'] = array_replace( $base['snapshot'], $content, [ 'configuration' => $configuration['template'] ] );
    if ( $version === 3 ) {
        unset( $base['snapshot']['content'], $base['snapshot']['attempt_id'] );
        $base['snapshot'] += [ 'job_id' => op( 201 ), 'run_id' => op( 202 ), 'source_event_id' => 'posting-source-v1-' . str_repeat( 'a', 64 ) ];
    }
    $base['item'] = [ 'id' => $base['snapshot']['id'], 'content_item_version_id' => $base['snapshot']['content_item_version_id'], 'source_sha256' => $base['snapshot']['source_sha256'], 'created_at' => '2026-10-26T12:00:00.000Z' ];
    if ( $version === 3 ) { unset( $base['item']['created_at'] ); $base['item']['contract'] = Nova_Bridge_Suite_Posting_Protocol::DESTINATION; foreach ( [ 'job_id', 'run_id', 'source_event_id', 'client_id', 'content_item_id', 'version_number' ] as $key ) { $base['item'][$key] = $base['snapshot'][$key]; } }
    $base['job']['content_id'] = $base['snapshot']['id']; $base['job']['payload']['discovery'] = $base['item'];
    $base['raw'] = json_encode( $base['snapshot'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); $base['etag'] = '"' . hash( 'sha256', $base['raw'] ) . '"';
    $GLOBALS['delivery_configuration'] = $configuration;
    ok( Nova_Bridge_Suite_Posting_Protocol::snapshot( [ 'status' => 200, 'raw_body' => $base['raw'], 'etag' => $base['etag'], 'attempt_id' => $base['attempt_id'] ], $base['job'] ) );
    $store = new Layout_Delivery_Store(); $client = new Layout_Delivery_Client( $base ); $worker = new Nova_Bridge_Suite_Posting_Worker( $base['connection'], $store, $client );
    return [ $base, $store, $client, $worker ];
}
$checks = 0;
foreach ( [ 1, 3 ] as $version ) {
    list( $f, $store, $client, $worker ) = delivery_setup( $version ); $saved = ok( $worker->process( $f['job'] ) );
    check( $saved['state'] === 'complete' && $saved['target_id'] === 10 && $saved['payload']['native_writer'] === 'stored_layout_v3' && $inserts === 1 && $saves === 1, 'Default worker dispatch creates and retains exactly one native page.' );
    check( $saved['payload']['selected_policy']['reference']['reference_id'] === 0 && $saved['payload']['context']['operation'] === 'create', 'Worker accepts a source-free creation policy with target zero.' );
    check( $saved['payload']['result']['cms_post_status'] === 'draft' && array_column( $client->events, 'kind' ) === [ 'received_complete', 'publication_succeeded' ], 'A verified saved draft receives the authorized initial-webhook success receipt.' );
    check( $client->events[1]['publication_ref'] === 'wordpress:post:10;operation:' . $f['job']['operation_id'], 'Receipt references the newly created post, never the example or target zero.' );
    check( $saved['payload']['content']['configuration']['definition']['version'] === $version && $source_reads === 0, 'Wire version remains unchanged and no source page is read.' );
    check( ! in_array( 'target:' . $f['job']['site_id'] . ':0', $store->lock_history, true ) && count( array_filter( $store->lock_history, static function ( $key ) { return str_starts_with( $key, 'layout-url:' ); } ) ) === 1 && $store->locks === [], 'Source-free creation locks the native URL and releases its locks.' );
}
foreach ( [ 1, 3 ] as $version ) {
    list( $f, $store, $client, $worker ) = delivery_setup( $version, '/services/article/', 'preserve' );
    existing_page( 20, 'services' ); existing_page( 15179, 'article', 'publish', 20 );
    $saved = ok( $worker->process( $f['job'] ) );
    check( $saved['state'] === 'complete' && $saved['phase'] === 'committed' && $saved['target_id'] === 15179 && $inserts === 0 && $saves === 1, 'Default dispatch applies the stored template to the existing page without a second insert.' );
    check( $saved['payload']['selected_policy']['reference']['reference_id'] === 15179 && $saved['payload']['context']['target_post_id'] === 15179 && $saved['payload']['context']['operation'] === 'update', 'Existing-page policy and worker context retain the same concrete update target.' );
    check( $saved['payload']['result']['url'] === 'https://example.test/services/article/' && get_post( 15179 )->post_parent === 20 && get_post( 15179 )->post_author === 7 && get_post_status( 15179 ) === 'publish', 'Existing-page replacement preserves canonical hierarchy, author and preserved publication state.' );
    check( array_column( $client->events, 'kind' ) === [ 'received_complete', 'publication_succeeded' ] && $client->events[1]['publication_ref'] === 'wordpress:post:15179;operation:' . $f['job']['operation_id'], 'The final receipt identifies the updated page after its native commit.' );
    check( $saved['payload']['content']['url'] === '/services/article/' && $saved['payload']['content']['configuration']['definition']['version'] === $version && $source_reads === 0, 'Relative target resolution leaves the authenticated snapshot and mapping version unchanged.' );
}
list( $f, $store, $client, $worker ) = delivery_setup( 3, '/services/article/', 'preserve' );
existing_page( 20, 'services' ); existing_page( 15179, 'article', 'publish', 20 ); $client->lose_receipt = true;
$pending = ok( $worker->process( $f['job'] ) ); $event_id = $pending['payload']['pending_event']['event_id'];
check( $pending['phase'] === 'event_pending' && $pending['target_id'] === 15179 && $inserts === 0 && $saves === 1, 'A lost replacement receipt retains the existing target and completed native save.' );
$pending['state'] = 'running'; $done = ok( ( new Nova_Bridge_Suite_Posting_Worker( $f['connection'], $store, $client ) )->process( $pending ) );
check( $done['state'] === 'complete' && $done['target_id'] === 15179 && $inserts === 0 && $saves === 1 && $client->events[2]['event_id'] === $event_id, 'Replacement receipt retry neither reinserts nor rewrites the existing page.' );
foreach ( [ 'crash_owner', 'crash_before_save', 'crash_save' ] as $crash ) {
    list( $f, $store, $client, $worker ) = delivery_setup( 3, '/services/article/', 'publish' );
    existing_page( 20, 'services' ); existing_page( 15179, 'article', 'draft', 20 ); $GLOBALS[$crash] = true;
    $pending = ok( $worker->process( $f['job'] ) );
    check( $pending['state'] === 'ready' && $pending['phase'] === 'applying' && $pending['target_id'] === 15179 && $inserts === 0, 'Interrupted replacement preserves its frozen plan and existing target for worker recovery.' );
    $pending['state'] = 'running'; $done = ok( ( new Nova_Bridge_Suite_Posting_Worker( $f['connection'], $store, $client ) )->process( $pending ) );
    check( $done['state'] === 'complete' && $done['target_id'] === 15179 && $inserts === 0 && $saves === 1 && $publication_changes === 1, 'A new worker recovers interrupted ownership and native saves without duplicate replacement or publication.' );
}
list( $f, $store, $client, $worker ) = delivery_setup(); $crash_insert = true; $first = ok( $worker->process( $f['job'] ) );
check( $first['state'] === 'ready' && $first['phase'] === 'applying' && $first['payload']['native_writer'] === 'stored_layout_v3' && $inserts === 1, 'An uncertain insert retains the selected native writer and its frozen plan.' );
$first['state'] = 'running'; $resumed = ok( ( new Nova_Bridge_Suite_Posting_Worker( $f['connection'], $store, $client ) )->process( $first ) );
check( $resumed['state'] === 'complete' && $inserts === 1 && $saves === 1 && $resumed['target_id'] === 10, 'A new worker restores source-free dispatch and reuses the reserved page after response loss.' );
list( $f, $store, $client, $worker ) = delivery_setup(); $client->lose_receipt = true; $pending = ok( $worker->process( $f['job'] ) ); $event_id = $pending['payload']['pending_event']['event_id'];
check( $pending['phase'] === 'event_pending' && $inserts === 1, 'Lost final receipt acknowledgement retains its exact outgoing event.' );
$pending['state'] = 'running'; $done = ok( ( new Nova_Bridge_Suite_Posting_Worker( $f['connection'], $store, $client ) )->process( $pending ) );
check( $done['state'] === 'complete' && $inserts === 1 && $saves === 1 && $client->events[2]['event_id'] === $event_id, 'Receipt replay changes neither page nor event identity.' );
list( $f, $store, $client, $worker ) = delivery_setup(); $crash_insert = true; $bad = ok( $worker->process( $f['job'] ) ); $bad['state'] = 'running'; $bad['payload']['plan']['format'] = 'wrong-writer'; $blocked = ok( $worker->process( $bad ) );
check( $blocked['state'] === 'blocked' && $blocked['last_error'] === 'native_writer_identity' && $inserts === 1 && $saves === 0, 'Resume rejects a mismatched native writer identity before another native action.' );
foreach ( [ 'crash_insert', 'crash_save', 'lost_receipt' ] as $interruption ) {
    list( $f, $store, $client, $worker ) = delivery_setup();
    if ( 'lost_receipt' === $interruption ) { $client->lose_receipt = true; } else { $GLOBALS[$interruption] = true; }
    $old = ok( $worker->process( $f['job'] ) ); $before_events = count( $client->events );
    $client->fixture['attempt_id'] = op( 900 ); $old['state'] = 'running';
    $current = ok( ( new Nova_Bridge_Suite_Posting_Worker( $f['connection'], $store, $client ) )->process( $old ) );
    check( $current['state'] === 'complete' && $current['target_id'] === 10 && $inserts === 1 && $saves === 1, 'Current attempt recovers exactly one real stored-layout page after ' . $interruption );
    foreach ( array_slice( $client->events, $before_events ) as $event ) { check( $event['attempt_id'] === op( 900 ), 'Reconciled native publication only sends receipts for current authority.' ); }
    check( count( $current['payload']['prior_attempts'] ) === 1, 'Native takeover retains its prior attempt history.' );
}
echo "Stored-layout delivery checks: $checks passed.\n";
