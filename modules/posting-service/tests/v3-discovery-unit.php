<?php
require __DIR__ . '/discovery-unit.php';
function destination_status( int $id, string $status = 'ready' ): array {
    $uuid = '00000000-0000-4000-8000-' . sprintf( '%012d', $id );
    return [ 'schema_version' => 2, 'tenant_id' => '5', 'site_id' => '11111111-1111-4111-8111-111111111111', 'source_event_id' => 'posting-source-v1-' . str_repeat( 'a', 64 ),
        'content_item_id' => '10', 'content_item_version_id' => (string) ( 100 + $id ), 'version_number' => 1, 'source_sha256' => str_repeat( 'b', 64 ),
        'job_id' => $uuid, 'run_id' => '00000000-0000-4000-8000-000000000500', 'status_revision' => '1', 'status' => $status, 'delivery_id' => 'ready' === $status ? $uuid : null,
        'contract' => [ 'template_version' => 3, 'result_kind' => 'adaptation.delivery', 'result_schema_version' => 2 ] ];
}
function destination_page( array $items, $next = null ): array { return [ 'status' => 200, 'raw_body' => json_encode( [ 'items' => $items, 'next_cursor' => $next ] ) ]; }
[ $run, $store, $client ] = discovery_fixture();
$pending = destination_status( 1, 'pending' ); $ready = destination_status( 2 );
$cancelled = destination_status( 3, 'cancelled' ); $cancelled['delivery_id'] = $cancelled['job_id'];
$client->responses[] = destination_page( [ $pending, $ready, $cancelled ] );
$result = $run->run_destinations();
discovery_check( 'complete' === $result['state'] && 1 === count( $store->items ), 'Only ready V3 statuses enter the native journal; cancelled historical delivery IDs are allowed.' );
discovery_check( str_contains( $client->calls[0][1], 'contract=destination-v3-scalar-v1' ) && null === $store->state['cursor'], 'V3 discovery selects its contract without changing the V1 cursor.' );
discovery_check( 'idle' === $run->run_destinations()['state'] && 1 === count( $client->calls ), 'No polling or additional request without another webhook wake.' );
$store->request_discovery( $connection['site_id'] );
$client->responses[] = destination_page( [ destination_status( 1 ), $ready ] );
$result = $run->run_destinations();
discovery_check( 2 === count( $store->items ) && '2' === $store->observations[1][1], 'A new wake restarts UUID discovery so an earlier pending job becoming ready is not lost.' );
[ $run, $store, $client ] = discovery_fixture();
$client->responses[] = destination_page( [ destination_status( 1 ) ], destination_status( 1 )['job_id'] );
discovery_check( 'continuing' === $run->run_destinations( 1 )['state'], 'Bounded V3 scans retain their cursor.' );
$client->responses[] = destination_page( [ destination_status( 2 ) ] );
discovery_check( 'complete' === $run->run_destinations( 1 )['state'] && str_contains( $client->calls[1][1], '&cursor=' ), 'Interrupted scans resume with UUID pagination.' );
[ $run, $store, $client ] = discovery_fixture();
$bad = destination_status( 2 ); $bad['site_id'] = '22222222-2222-4222-8222-222222222222';
$client->responses[] = destination_page( [ destination_status( 1 ), $bad ] );
discovery_check( is_wp_error( $run->run_destinations() ) && ! $store->items && null === $store->state['destination']['cursor'], 'Wrong-site page fails before any enqueue or cursor advance.' );
[ $run, $store, $client ] = discovery_fixture();
$store->fail_item = destination_status( 1 )['delivery_id']; $client->responses[] = destination_page( [ destination_status( 1 ) ] );
discovery_check( is_wp_error( $run->run_destinations() ) && null === $store->state['destination']['cursor'], 'Failed admission cannot advance discovery.' );
echo "PASS V3 webhook discovery and restart cases\n";
