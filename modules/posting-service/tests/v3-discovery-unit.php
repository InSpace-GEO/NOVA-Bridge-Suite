<?php
/** V3 uses the same public feed and checkpoint as direct deliveries after v0.8.0. */
require __DIR__ . '/discovery-unit.php';
[ $run, $store, $client, $clock, $connection ] = discovery_fixture();
$client->responses = [ discovery_page( [ discovery_item( '1' ), discovery_item( '2' ) ], '700' ) ];
$result = $run->run();
discovery_check( 'complete' === $result['state'] && 2 === count( $store->items ) && [ [ 'GET', '/deliveries?limit=100' ] ] === $client->calls, 'Direct and adapted deliveries require one feed without a kind discriminator.' );
$store->state['destination'] = [ 'cursor' => '00000000-0000-4000-8000-000000000010', 'scan' => 900, 'active' => true ];
$legacy = $store->state['destination'];
$run->wake(); $client->responses[] = discovery_page( [ discovery_item( '3' ) ], '701' );
$run->run();
discovery_check( '/deliveries?limit=100&cursor=700' === $client->calls[1][1] && '701' === $store->state['cursor'], 'An upgrade preserves the shared decimal feed checkpoint despite a retained UUID status sweep.' );
discovery_check( $legacy === $store->state['destination'] && 3 === count( $store->items ), 'Retired sweep state cannot trigger another status request or block new deliveries.' );
discovery_check( ! method_exists( Nova_Bridge_Suite_Posting_Discovery::class, 'run_destinations' ), 'The removed adaptation endpoint cannot be called by discovery.' );
$run->wake(); $bad = discovery_item( '4' ); $bad['kind'] = 'adaptation';
$client->responses[] = [ 'status' => 200, 'raw_body' => json_encode( [ 'items' => [ $bad ], 'next_cursor' => '702' ] ) ];
discovery_check( is_wp_error( $run->run() ) && '701' === $store->state['cursor'], 'An unexpected public discriminator cannot silently alter the contracted feed.' );
echo "PASS unified V3 discovery and upgrade cases\n";
