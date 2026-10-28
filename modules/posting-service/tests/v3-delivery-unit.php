<?php
/** Exercise the actual HTTP client, schema checks, worker journal and receipts for V3. */
require __DIR__ . '/delivery-unit.php';
$f = nova_contract_fixture();
$f['snapshot']['configuration']['definition'] = [ 'version' => 3, 'instructions' => [ 'mode' => 'set', 'text' => 'Use existing introductory content.' ],
    'fields' => [ [ 'id' => 'heading', 'label' => 'Hero heading', 'purpose' => 'Heading above the product grid.', 'value_type' => 'text', 'required' => true, 'instructions' => 'Select the main heading.' ] ], 'groups' => [] ];
$f['snapshot']['configuration']['mapping'] = [ 'version' => 3 ];
unset( $f['snapshot']['content'], $f['snapshot']['attempt_id'] );
$f['snapshot'] += [ 'kind' => 'adaptation.delivery', 'schema_version' => 2, 'job_id' => '00000000-0000-4000-8000-000000000050', 'run_id' => '00000000-0000-4000-8000-000000000051', 'source_event_id' => 'posting-source-v1-' . str_repeat( 'a', 64 ), 'destination_values' => [ 'heading' => 'Original source heading' ] ];
$f['configuration']['template'] = $f['snapshot']['configuration'];
unset( $f['item']['created_at'] ); $f['item']['contract'] = Nova_Bridge_Suite_Posting_Protocol::DESTINATION;
foreach ( [ 'job_id', 'run_id', 'source_event_id', 'client_id', 'content_item_id', 'version_number' ] as $key ) { $f['item'][ $key ] = $f['snapshot'][ $key ]; }
$f['job']['payload']['discovery'] = $f['item'];
$f['raw'] = json_encode( $f['snapshot'], JSON_UNESCAPED_SLASHES ); $f['etag'] = '"' . hash( 'sha256', $f['raw'] ) . '"';
class V3_Test_Server extends Contract_Test_Server {
    public function request( $url, $args ) {
        if ( 'GET' === $args['method'] ) {
            delivery_check( str_ends_with( $url, '/deliveries/' . $this->fixture['item']['id'] ), 'Retained V3 journals use the unified delivery endpoint.' );
            return self::reply( 200, $this->raw, [ 'etag' => $this->etag, 'x-nova-attempt-id' => $this->attempt ] );
        }
        return parent::request( $url, $args );
    }
}
Contract_Test_Writer::reset(); $store = new Contract_Test_Store(); $server = new V3_Test_Server( $f ); $GLOBALS['server'] = $server;
$worker = new Nova_Bridge_Suite_Posting_Worker( $f['connection'], $store, new Nova_Bridge_Suite_Posting_Client( $f['connection'] ),
    static function () use ( $f ) { return $f['configuration']; }, 'Contract_Test_Writer',
    static function () { return [ 'reference' => [ 'reference_type' => 'post', 'reference_id' => 77 ], 'routing' => [ 'operation' => 'update', 'publication' => 'publish' ] ]; } );
$result = $worker->process( $f['job'] );
delivery_check( ! is_wp_error( $result ) && 'complete' === $result['state'] && 1 === Contract_Test_Writer::$apply, 'A validated V3 result completes the existing native-write journal.' );
delivery_check( [ 'received_complete', 'publication_succeeded' ] === array_map( static function ( $bytes ) { return json_decode( $bytes, true )['kind']; }, $server->posts ), 'V3 uses the existing receipt flow after native verification.' );
$response = [ 'status' => 200, 'raw_body' => $f['raw'], 'etag' => $f['etag'], 'attempt_id' => $f['attempt_id'] ];
$bad = $f['job']; $bad['payload']['discovery']['run_id'] = '00000000-0000-4000-8000-000000000099';
delivery_check( is_wp_error( Nova_Bridge_Suite_Posting_Protocol::snapshot( $response, $bad ) ), 'Wrong adaptation run is rejected even with valid bytes.' );
$bad = $response; $bad['attempt_id'] = ''; delivery_check( is_wp_error( Nova_Bridge_Suite_Posting_Protocol::snapshot( $bad, $f['job'] ) ), 'Readable fitted values without current attempt authority cannot publish.' );
$bad = $response; $bad['raw_body'] .= ' '; delivery_check( is_wp_error( Nova_Bridge_Suite_Posting_Protocol::snapshot( $bad, $f['job'] ) ), 'Changed exact bytes fail their ETag.' );
$bad = $f['job']; unset( $bad['payload']['discovery']['contract'] ); delivery_check( is_wp_error( Nova_Bridge_Suite_Posting_Protocol::snapshot( $response, $bad ) ), 'V3 envelopes cannot enter the legacy delivery path.' );
echo "PASS V3 result verification and native receipt flow\n";
