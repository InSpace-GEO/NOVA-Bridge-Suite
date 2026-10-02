<?php
/** Offline current delivery polling: durable checkpoint, bounded work and failure recovery. */
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
    private $code; private $data;
    public function __construct( $code, $message = '', $data = [] ) { $this->code = $code; $this->data = $data; }
    public function get_error_code() { return $this->code; } public function get_error_data() { return $this->data; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-posting-protocol.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-posting-discovery.php';
class Discovery_Memory_Jobs {
    public $state = [ 'cursor' => null, 'cycle_started' => 0, 'last_completed' => 0, 'next_attempt' => 0, 'consumed_sequence' => 0, 'wake_sequence' => 0, 'failures' => 0, 'last_error' => '', 'connection_key' => '', 'suspended' => false, 'auth_status' => 0 ];
    public $items = []; public $observations = []; public $locks = []; public $saves = []; public $fail_save = 0; public $fail_item = ''; public $save_count = 0;
    public function lock( $name ) { if ( isset( $this->locks[$name] ) ) { return new WP_Error( 'busy' ); } $this->locks[$name] = true; return true; }
    public function unlock( $name ) { unset( $this->locks[$name] ); }
    public function discovery_state( $site ) { return $this->state; }
    public function save_discovery_state( $site, $state ) {
        if ( ++$this->save_count === $this->fail_save ) { return new WP_Error( 'checkpoint_failed' ); }
        $state['wake_sequence'] = $this->state['wake_sequence']; $this->state = $state; $this->saves[] = $state; return true;
    }
    public function request_discovery( $site ) { ++$this->state['wake_sequence']; return true; }
    public function enqueue_discovered( $site, $item, $checkpoint = null ) {
        if ( $this->fail_item === $item['id'] ) { return new WP_Error( 'insert_failed' ); }
        $this->items[$item['id']] = $item; $this->observations[] = [ $item['id'], $checkpoint ]; return [ 'id' => count( $this->items ) ];
    }
}
class Discovery_Client {
    public $responses = []; public $calls = []; public $on_call;
    public function site_request( $method, $path ) {
        $this->calls[] = [ $method, $path ];
        if ( $this->on_call ) { call_user_func( $this->on_call ); }
        if ( ! $this->responses ) { throw new RuntimeException( 'Unexpected service request: ' . $path ); }
        return array_shift( $this->responses );
    }
}
$checks = 0;
function discovery_check( $value, $message ) { ++$GLOBALS['checks']; if ( ! $value ) { throw new RuntimeException( $message ); } }
function discovery_item( $id = '10' ) { return [ 'id' => '00000000-0000-4000-8000-' . str_pad( $id, 12, '0', STR_PAD_LEFT ), 'content_item_version_id' => '900' . $id, 'source_sha256' => str_repeat( 'a', 64 ), 'created_at' => '2026-10-02T12:00:00.123Z' ]; }
function discovery_page( array $items = [], $cursor = null ) { return [ 'status' => 200, 'body' => [ 'items' => $items, 'next_cursor' => $cursor ] ]; }
function discovery_full_page() { return array_map( 'discovery_item', range( 1, 100 ) ); }
function discovery_fixture() {
    $connection = [ 'site_id' => '11111111-1111-4111-8111-111111111111', 'base_url' => 'https://fixture.invalid', 'token' => 'fixture-credential', 'enabled' => true, 'paused' => false ];
    $store = new Discovery_Memory_Jobs(); $client = new Discovery_Client(); $clock = (object) [ 'now' => strtotime( '2026-10-02T12:01:00Z' ) ];
    return [ new Nova_Bridge_Suite_Posting_Discovery( $connection, $store, $client, static function () use ( $clock ) { return $clock->now; } ), $store, $client, $clock, $connection ];
}
[ $run, $store, $client, $clock, $connection ] = discovery_fixture();
$client->responses = [ discovery_page( discovery_full_page(), '9007199254740993' ), discovery_page( [ discovery_item( '101' ) ], '9007199254740994' ) ];
$first = $run->run( 1 );
discovery_check( 'continuing' === $first['state'] && 100 === count( $store->items ) && '9007199254740993' === $store->state['cursor'], 'Full page persists all work before checkpoint and remains resumable.' );
$restarted = new Nova_Bridge_Suite_Posting_Discovery( $connection, $store, $client, static function () use ( $clock ) { return $clock->now; } );
$second = $restarted->run( 1 );
discovery_check( 'complete' === $second['state'] && '9007199254740994' === $store->state['cursor'] && 101 === count( $store->items ), 'Terminal nonempty page retains permanent checkpoint.' );
discovery_check( '/deliveries?limit=100&cursor=9007199254740993' === $client->calls[1][1], 'Large decimal checkpoint stays exact on request.' );
discovery_check( 'idle' === $run->run()['state'] && 2 === count( $client->calls ), 'Completed poll respects five-minute interval.' );
$clock->now += 300; $client->responses[] = discovery_page( [], '9007199254740994' );
discovery_check( 'complete' === $run->run()['state'] && '9007199254740994' === $store->state['cursor'] && '/deliveries?limit=100&cursor=9007199254740994' === $client->calls[2][1], 'Empty page echoes checkpoint; periodic poll never restarts at zero.' );
$run->wake(); $client->responses[] = discovery_page( [ discovery_item( '101' ) ], '9007199254740995' ); $run->run();
discovery_check( [ discovery_item('101')['id'], '9007199254740995' ] === end( $store->observations ) && 101 === count( $store->items ), 'Retry reannouncement passes a new observation checkpoint with identical delivery identity.' );
discovery_check( [] === $store->locks, 'Cross-process lock always released.' );
$run->suspend( new WP_Error( 'forbidden', '', [ 'status' => 403 ] ) ); $run->retry_authentication();
discovery_check( '9007199254740995' === $store->state['cursor'], 'Explicit auth recheck preserves permanent cursor.' );
$connection['token'] = 'rotated-fixture-credential'; $client->responses[] = discovery_page( [], '9007199254740995' );
$rotated = new Nova_Bridge_Suite_Posting_Discovery( $connection, $store, $client, static function () use ( $clock ) { return $clock->now; } ); $rotated->run();
discovery_check( '9007199254740995' === $store->state['cursor'], 'Credential rotation retains current-protocol origin checkpoint.' );

[ $run, $store, $client ] = discovery_fixture(); $run->wake();
discovery_check( 1 === $store->state['wake_sequence'] && [] === $store->items && [] === $client->calls, 'Wake stores a hint only.' );
$client->responses = [ discovery_page( [ discovery_item() ], '10' ), discovery_page( [], '10' ) ];
$client->on_call = static function () use ( $store, $client ) { $store->request_discovery( '' ); $client->on_call = null; };
$run->run(); discovery_check( $store->state['wake_sequence'] > $store->state['consumed_sequence'], 'Wake during fetch survives checkpoint save.' );
$run->run(); discovery_check( '/deliveries?limit=100&cursor=10' === $client->calls[1][1], 'Concurrent wake polls retained checkpoint.' );

[ $run, $store, $client ] = discovery_fixture();
$client->responses = [ discovery_page( discovery_full_page(), '100' ), discovery_page( [ discovery_item('101') ], '101' ), discovery_page( [], '101' ) ];
$run->run( 1 ); $run->wake(); $run->run( 1 );
discovery_check( '/deliveries?limit=100&cursor=100' === $client->calls[1][1] && $store->state['wake_sequence'] > $store->state['consumed_sequence'], 'Wake never restarts active bounded pagination.' );
$run->run( 1 ); discovery_check( '/deliveries?limit=100&cursor=101' === $client->calls[2][1], 'Outstanding wake remains pending until prior pagination finishes.' );

[ $run, $store, $client, $clock ] = discovery_fixture();
$store->fail_save = 2; $client->responses = [ discovery_page( [ discovery_item() ], '10' ) ];
discovery_check( is_wp_error( $run->run() ) && null === $store->state['cursor'] && 1 === count( $store->items ), 'Lost checkpoint never moves past stored work.' );
$store->fail_save = 0; $client->responses = [ discovery_page( [ discovery_item() ], '10' ) ]; $run->run();
discovery_check( 1 === count( $store->items ) && '/deliveries?limit=100' === $client->calls[1][1], 'After lost checkpoint page safely replays.' );

[ $run, $store, $client, $clock ] = discovery_fixture();
$store->fail_item = discovery_item('11')['id']; $client->responses = [ discovery_page( [ discovery_item(), discovery_item('11') ], '11' ) ];
discovery_check( is_wp_error( $run->run() ) && null === $store->state['cursor'] && 1 === count( $store->items ), 'Partial storage failure retains input checkpoint.' );
$store->fail_item = ''; $clock->now += 60; $client->responses = [ discovery_page( [ discovery_item(), discovery_item('11') ], '11' ) ]; $run->run();
discovery_check( 2 === count( $store->items ), 'Partially stored page safely replays after storage recovery.' );

foreach ( [ 'extra', 'bad_uuid', 'numeric_civ', 'overflow_civ', 'bad_hash', 'bad_date', 'duplicate' ] as $case ) {
    [ $run, $store, $client ] = discovery_fixture(); $bad = discovery_item('11');
    if ( 'extra' === $case ) { $bad['native_reference'] = 'untrusted'; }
    if ( 'bad_uuid' === $case ) { $bad['id'] = '11'; }
    if ( 'numeric_civ' === $case ) { $bad['content_item_version_id'] = 11; }
    if ( 'overflow_civ' === $case ) { $bad['content_item_version_id'] = '9223372036854775808'; }
    if ( 'bad_hash' === $case ) { $bad['source_sha256'] = 'bad'; }
    if ( 'bad_date' === $case ) { $bad['created_at'] = '2026-02-31T12:00:00Z'; }
    if ( 'duplicate' === $case ) { $bad = discovery_item(); }
    $client->responses = [ discovery_page( [ discovery_item(), $bad ], '11' ) ];
    discovery_check( is_wp_error( $run->run() ) && [] === $store->items, 'Whole invalid page rejected before admitting any item: ' . $case );
}
foreach ( [ null, '0', '01', 'opaque', '9223372036854775808' ] as $cursor ) {
    [ $run, $store, $client ] = discovery_fixture(); $client->responses = [ discovery_page( [ discovery_item() ], $cursor ) ];
    discovery_check( is_wp_error( $run->run() ) && [] === $store->items, 'Nonempty page needs advancing bounded decimal checkpoint.' );
}
[ $run, $store, $client, $clock ] = discovery_fixture();
$client->responses = [ discovery_page( [ discovery_item() ], '10' ) ]; $run->run(); $run->wake();
$client->responses = [ discovery_page( [ discovery_item('11') ], '10' ) ];
discovery_check( is_wp_error( $run->run() ) && 1 === count( $store->items ), 'Nonempty response must advance its checkpoint.' );
$clock->now += 60; $client->responses = [ discovery_page( [], '11' ) ];
discovery_check( is_wp_error( $run->run() ) && '10' === $store->state['cursor'], 'Empty response cannot advance past unseen work.' );

[ $run, $store, $client ] = discovery_fixture();
$page = discovery_page(); $page['raw_body'] = '{"items":{},"next_cursor":null}'; $client->responses = [ $page ];
discovery_check( is_wp_error( $run->run() ), 'Wire object cannot impersonate items list.' );
[ $run, $store, $client ] = discovery_fixture();
$page = discovery_page( [ discovery_item() ], '10' ); $page['raw_body'] = json_encode( $page['body'] ); $page['body'] = [];
$client->responses = [ $page ]; discovery_check( 'complete' === $run->run()['state'], 'Validated raw JSON is authoritative over separately decoded helper data.' );
[ $run, $store, $client ] = discovery_fixture();
$store->state['cursor'] = 'historical-opaque-checkpoint'; $client->responses = [ discovery_page() ];
discovery_check( 'complete' === $run->run()['state'] && null === $store->state['cursor'], 'Old protocol checkpoint resets once without accepting old journal work.' );
[ $run, $store, $client, $clock ] = discovery_fixture();
$client->responses = [ discovery_page( [ discovery_item() ], '10' ) ]; $run->run(); $run->wake();
$client->responses = [ new WP_Error( 'invalid_cursor', '', [ 'status' => 400, 'error_code' => 'invalid_cursor' ] ) ];
discovery_check( is_wp_error( $run->run() ) && '10' === $store->state['cursor'], 'Invalid current cursor remains visible; no historical410reset guess.' );
$clock->now += 60; $client->responses = [ new WP_Error( 'reset', '', [ 'status' => 410, 'error_code' => 'reset_required' ] ) ];
discovery_check( is_wp_error( $run->run() ) && '10' === $store->state['cursor'] && 3 === count( $client->calls ), 'Retired reset response does not silently erase current checkpoint.' );


[ $run, $store, $client, $clock, $connection ] = discovery_fixture();
$client->responses = [ new WP_Error( 'unauthorized', '', [ 'status' => 401, 'error_code' => 'unauthorized' ] ) ];
discovery_check( is_wp_error( $run->run() ) && $store->state['suspended'], 'Invalid credentials suspend discovery durably.' );
$clock->now += 10000; $run->wake(); $run->run();
discovery_check( 1 === count( $client->calls ), 'Elapsed time and valid webhook cannot bypass a credential suspension.' );
discovery_check( 401 === $run->run()->get_error_data()['status'] && 401 === $store->state['auth_status'], 'Invalid credentials retain a 401 stop-all signal.' );
$connection['token'] = 'rotated-fixture-credential'; $client->responses = [ discovery_page() ];
$rotated = new Nova_Bridge_Suite_Posting_Discovery( $connection, $store, $client, static function () use ( $clock ) { return $clock->now; } );
discovery_check( 'complete' === $rotated->run()['state'] && ! $store->state['suspended'], 'Credential rotation restarts a fresh authenticated cycle.' );
$rotated->suspend( new WP_Error( 'forbidden', '', [ 'status' => 403 ] ) );
discovery_check( $store->state['suspended'] && is_wp_error( $rotated->run() ) && 2 === count( $client->calls ), 'Worker-reported eligibility failure suspends the same credential.' );
discovery_check( 403 === $rotated->run()->get_error_data()['status'] && 403 === $store->state['auth_status'], 'Restricted new work retains 403 so the worker can separately authenticate retained recovery.' );
discovery_check( true === $rotated->retry_authentication() && ! $store->state['suspended'] && 0 === $store->state['auth_status'] && 2 === count( $client->calls ), 'Explicit recheck clears suspension without an HTTP call or changing retained work.' );
$client->responses = [ discovery_page() ];
discovery_check( 'complete' === $rotated->run()['state'] && 3 === count( $client->calls ), 'Same repaired credential is authenticated again after explicit recheck.' );

[ $run, $store, $client, $clock ] = discovery_fixture();
$client->responses = [ new WP_Error( 'limited', '', [ 'status' => 429, 'retry_after' => '600' ] ) ]; $run->run();
discovery_check( $store->state['next_attempt'] === $clock->now + 600, 'Server Retry-After takes precedence over the normal cycle period.' );
$run->wake(); $clock->now += 300;
discovery_check( 'backoff' === $run->run()['state'] && 1 === count( $client->calls ), 'A wake does not bypass Retry-After.' );
discovery_check( is_wp_error( $run->retry_authentication() ) && $store->state['next_attempt'] === $clock->now + 300 && 1 === count( $client->calls ), 'An operator auth recheck cannot bypass ordinary server Retry-After.' );

foreach ( [ '172800', '000172800', 'date' ] as $retry_header ) {
    [ $run, $store, $client, $clock ] = discovery_fixture();
    $deadline = $clock->now + 172800;
    $header = 'date' === $retry_header ? gmdate( 'D, d M Y H:i:s \G\M\T', $deadline ) : $retry_header;
    $client->responses = [ new WP_Error( 'unavailable', '', [ 'status' => 503, 'retry_after' => $header ] ) ]; $run->run();
    discovery_check( $deadline === $store->state['next_attempt'], 'A valid Retry-After longer than one day is retained exactly: ' . $retry_header );
    $clock->now += 86401; $run->wake();
    discovery_check( 'backoff' === $run->run()['state'] && 1 === count( $client->calls ), 'Long server delays are not truncated by periodic scans or hints: ' . $retry_header );
    $clock->now = $deadline; $client->responses = [ discovery_page() ];
    discovery_check( 'complete' === $run->run()['state'] && 2 === count( $client->calls ), 'Discovery resumes when the complete server delay expires: ' . $retry_header );
}
foreach ( [ (string) PHP_INT_MAX, str_repeat( '9', 128 ) ] as $retry_header ) {
    [ $run, $store, $client, $clock ] = discovery_fixture();
    $client->responses = [ new WP_Error( 'limited', '', [ 'status' => 429, 'retry_after' => $retry_header ] ) ]; $run->run();
    discovery_check( PHP_INT_MAX === $store->state['next_attempt'] && 'retry_after_out_of_bounds' === $store->state['last_error'], 'Unrepresentable numeric server delay blocks automatic retry without float or integer overflow.' );
    $clock->now += 172800; $run->wake();
    discovery_check( 'backoff' === $run->run()['state'] && 1 === count( $client->calls ) && is_wp_error( $run->retry_authentication() ), 'Unrepresentable delay cannot be bypassed by ordinary wake or auth recheck.' );
}

[ $run, $store, $client, $clock, $connection ] = discovery_fixture();
$connection['enabled'] = false;
$disabled = new Nova_Bridge_Suite_Posting_Discovery( $connection, $store, $client );
discovery_check( 'disabled' === $disabled->run()['state'] && is_wp_error( $disabled->wake() ) && [] === $client->calls && [] === $store->saves, 'Disabled connection neither schedules work nor contacts the service.' );
$connection['enabled'] = true; $connection['paused'] = true; $client->responses = [ discovery_page() ];
$paused = new Nova_Bridge_Suite_Posting_Discovery( $connection, $store, $client, static function () use ( $clock ) { return $clock->now; } );
discovery_check( 'complete' === $paused->run()['state'], 'Paused mutation mode can still discover durable work.' );
echo 'PASS ' . $checks . " contracted discovery checks.\n";
