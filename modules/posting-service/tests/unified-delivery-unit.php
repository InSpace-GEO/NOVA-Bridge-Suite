<?php
/** Unified v0.8.0 wire bytes through the real client, protocol, worker and receipts; no network/DB. */
require __DIR__ . '/delivery-unit.php';
class Unified_Test_Writer extends Contract_Test_Writer {
    public static $snapshot;
    public static function plan( $snapshot, $config, $context ) {
        self::$snapshot = $snapshot;
        return parent::plan( $snapshot, $config, $context );
    }
}
function unified_execution_fixture( int $version = 1, bool $unmapped = false ): array {
    Contract_Test_Writer::reset(); Unified_Test_Writer::$snapshot = null;
    $f = nova_contract_fixture(); $old = $f['raw'];
    if ( 3 === $version ) {
        $f['configuration']['template']['definition'] = [ 'version' => 3, 'instructions' => [ 'mode' => 'clear' ],
            'fields' => [ [ 'id' => 'heading', 'label' => 'Heading', 'purpose' => 'Main heading', 'value_type' => 'text', 'required' => true, 'instructions' => 'Use the source heading.' ] ], 'groups' => [] ];
        $f['configuration']['template']['mapping'] = [ 'version' => 3 ];
    }
    unset( $f['snapshot']['attempt_id'] );
    $f['snapshot']['configuration'] = $unmapped ? null : [ 'id' => $f['configuration']['template']['id'], 'name' => 'Service fixture',
        'page_type' => 'service-page', 'revision' => 1, 'fields' => [ [ 'id' => 'heading', 'label' => 'Heading', 'value_type' => 'text', 'required' => true ] ], 'groups' => [] ];
    $f['snapshot']['content'] = $unmapped ? [ 'title' => 'Unmapped title', 'content' => '<p>Original HTML.</p>', 'image_urls' => [] ] : [ 'heading' => 'Already mapped by posting service' ];
    if ( $unmapped ) { $f['configuration'] = [ 'mode' => 'local_rules' ]; }
    $f['raw'] = json_encode( $f['snapshot'], JSON_UNESCAPED_SLASHES );
    $f['etag'] = '"' . hash( 'sha256', $f['raw'] ) . '"';
    $store = new Contract_Test_Store(); $server = new Contract_Test_Server( $f ); $GLOBALS['server'] = $server;
    $worker = new Nova_Bridge_Suite_Posting_Worker( $f['connection'], $store, new Nova_Bridge_Suite_Posting_Client( $f['connection'] ),
        static function () use ( $f ) { return $f['configuration']; }, Unified_Test_Writer::class,
        static function () { return [ 'reference' => [ 'reference_type' => 'post', 'reference_id' => 77 ], 'routing' => [ 'operation' => 'update', 'publication' => 'publish' ] ]; } );
    return [ $f, $store, $server, $worker, $old ];
}
foreach ( [ 1, 3 ] as $version ) {
    [ $f, $store, $server, $worker ] = unified_execution_fixture( $version );
    $result = $worker->process( $f['job'] );
    delivery_check( ! is_wp_error( $result ) && 'complete' === $result['state'] && 1 === Contract_Test_Writer::$apply, 'Unified V' . $version . ' executes once through the existing journal.' );
    $view = Unified_Test_Writer::$snapshot;
    delivery_check( $f['configuration']['template'] === $view['configuration'], 'The native view uses the exact retained approved template.' );
    delivery_check( 1 === $version ? 'Already mapped by posting service' === $view['content']['title'] : 'Already mapped by posting service' === $view['destination_values']['heading'], 'Destination field IDs reach the matching native V' . $version . ' writer without a second source mapping.' );
    delivery_check( $f['raw'] === $result['payload']['snapshot_raw'] && $f['snapshot'] === $result['payload']['content'], 'The journal retains the unified wire body, not the transient native view.' );
    $events = array_map( static function ( $bytes ) { return json_decode( $bytes, true ); }, $server->posts );
    delivery_check( [ 'received_complete', 'publication_succeeded' ] === array_column( $events, 'kind' ) && $f['etag'] === $events[1]['delivery_etag'] && $f['attempt_id'] === $events[1]['attempt_id'], 'Receipts attest the actual unified bytes and header attempt.' );
    foreach ( $server->calls as [ $url ] ) { delivery_check( ! str_contains( $url, 'adaptation' ), 'No retired adaptation route is called.' ); }
}
[ $f, $store, $server, $worker ] = unified_execution_fixture( 1, true );
$result = $worker->process( $f['job'] );
delivery_check( 'complete' === $result['state'] && 1 === Contract_Test_Writer::$apply && $f['snapshot'] === Unified_Test_Writer::$snapshot, 'Null-configuration delivery retains canonical source fields and unchanged HTML.' );

foreach ( [ 'missing_attempt', 'changed_bytes', 'wrong_profile' ] as $case ) {
    [ $f, $store, $server, $worker, $old ] = unified_execution_fixture( 3 );
    if ( 'missing_attempt' === $case ) { $server->attempt = ''; }
    if ( 'changed_bytes' === $case ) { $f['job']['payload']['snapshot_raw'] = $old; }
    if ( 'wrong_profile' === $case ) {
        $wire = $f['snapshot']; $wire['configuration']['revision'] = 2;
        $server->raw = json_encode( $wire ); $server->etag = '"' . hash( 'sha256', $server->raw ) . '"';
    }
    $result = $worker->process( $f['job'] );
    delivery_check( 0 === Contract_Test_Writer::$apply && null === Unified_Test_Writer::$snapshot, $case . ' cannot authorize a native write.' );
    if ( 'wrong_profile' !== $case ) { delivery_check( 'blocked' === $result['state'] && [] === $server->posts, $case . ' fails before an event or write.' ); }
}

// A receipt lost during the previous release must replay its old bytes even after GET changes.
[ $old_job, $old_store, $old_server, $old_worker ] = delivery_fixture();
$old_server->lose = 'publication_succeeded'; $pending = $old_worker->process( $old_job );
$old_receipt = $pending['payload']['event_bytes']; $old_etag = $pending['payload']['delivery_etag'];
[ $f, $store, $server, $worker ] = unified_execution_fixture( 3 );
$result = $worker->process( delivery_resume( $pending ) );
delivery_check( 'complete' === $result['state'] && [ $old_receipt ] === $server->posts && 0 === Contract_Test_Writer::$apply, 'An already saved page completes its exact old success receipt without fetching or writing again.' );
delivery_check( $old_etag === json_decode( $server->posts[0], true )['delivery_etag'] && 1 === count( $server->calls ), 'Historical receipt replay never substitutes the new public body hash.' );

// Legacy V3 discovery retains the common identity checks that still exist in the unified body.
[ $f, $store, $server, $worker ] = unified_execution_fixture( 3 );
$legacy = array_diff_key( $f['item'], [ 'created_at' => true ] ) + [ 'contract' => Nova_Bridge_Suite_Posting_Protocol::DESTINATION,
    'job_id' => '00000000-0000-4000-8000-000000000050', 'run_id' => '00000000-0000-4000-8000-000000000051', 'source_event_id' => 'posting-source-v1-' . str_repeat( 'a', 64 ),
    'client_id' => $f['snapshot']['client_id'], 'content_item_id' => $f['snapshot']['content_item_id'], 'version_number' => $f['snapshot']['version_number'] ];
$f['job']['payload']['discovery'] = $legacy;
$result = $worker->process( $f['job'] );
delivery_check( 'complete' === $result['state'] && 1 === Contract_Test_Writer::$apply, 'An unfetched historical V3 journal accepts unified delivery with matching common identity and current attempt.' );
Contract_Test_Writer::reset(); $f['job']['payload']['discovery']['client_id'] = '999';
$result = $worker->process( $f['job'] );
delivery_check( 'blocked' === $result['state'] && 0 === Contract_Test_Writer::$apply, 'Unified delivery cannot erase a retained V3 tenant identity mismatch.' );
echo "PASS unified delivery compatibility and receipt recovery\n";
