<?php
/** Exercises the production client/worker against a deterministic current API, without a CMS. */
define( 'ABSPATH', __DIR__ . '/' ); define( 'NOVA_BRIDGE_SUITE_VERSION', '3.0.0' );
class WP_Error {
    private $code; private $message; private $data;
    public function __construct( $code, $message = '', $data = [] ) { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_code() { return $this->code; }
    public function get_error_data() { return $this->data; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
function wp_parse_url( $url ) { return parse_url( $url ); }
function wp_safe_remote_request( $url, $args ) { return $GLOBALS['server']->request( $url, $args ); }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code']; }
function wp_remote_retrieve_header( $r, $key ) { return $r['headers'][$key] ?? ''; }
function wp_generate_uuid4() { static $n = 100; return '11111111-1111-4111-8111-' . sprintf( '%012d', ++$n ); }
function get_current_user_id() { return $GLOBALS['actor'] ?? 0; }
function wp_set_current_user( $id ) { $GLOBALS['actor'] = $id; }
function get_post_status( $id ) { return 'publish'; }
foreach ( [ 'posting-client', 'posting-protocol', 'receipt-json', 'posting-discovery', 'posting-worker' ] as $file ) { require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-' . $file . '.php'; }
require __DIR__ . '/contract-fixture.php';
$checks = 0;
function delivery_check( $condition, $message ) { ++$GLOBALS['checks']; if ( ! $condition ) { throw new RuntimeException( $message ); } }
class Contract_Test_Store {
    public $saved; public $history = []; public $locks = []; public $fail_phase = ''; public $fail_state = ''; public $uncertain = false;
    public function save( $job, $changes ) {
        $next = array_merge( $job, $changes );
        if ( $this->fail_phase === $next['phase'] || $this->fail_state === $next['state'] ) { return new WP_Error( 'fixture_journal_failure' ); }
        $this->history[] = $next; return $this->saved = $next;
    }
    public function lock( $key ) { $this->locks[$key] = true; return true; }
    public function unlock( $key ) { unset( $this->locks[$key] ); }
    public function uncertain_target( $job ) { return $this->uncertain; }
}
class Contract_Test_Writer {
    public static $apply = 0; public static $recover = 0; public static $finish = 0; public static $crash = false; public static $plan_error = false; public static $apply_error = null; public static $recover_value = null; public static $status = 'publish'; public static $context;
    public static function reset() { self::$apply = self::$recover = self::$finish = 0; self::$crash = self::$plan_error = false; self::$apply_error = self::$recover_value = null; self::$status = 'publish'; }
    public static function plan( $snapshot, $config, $context ) { self::$context = $context; return self::$plan_error ? new WP_Error( 'fixture_plan_failed' ) : [ 'context' => $context ]; }
    public static function result( $plan ) { return [ 'post_id' => $plan['context']['target_post_id'], 'cms_post_status' => self::$status ]; }
    public static function apply( $plan, $operation ) { ++self::$apply; if ( self::$crash ) { throw new RuntimeException( 'Process lost after commit' ); } return self::$apply_error ?? self::result( $plan ); }
    public static function recover( $plan, $operation ) { ++self::$recover; return self::$recover_value ?? self::result( $plan ); }
    public static function finish( $plan, $result ) { ++self::$finish; return $result; }
    public static function verify( $plan, $result ) { return self::result( $plan ); }
}
class Contract_Test_Server {
    public $fixture; public $calls = []; public $posts = []; public $accepted = []; public $lose = ''; public $throw_after = ''; public $read_status = 200; public $post_status = 0; public $raw; public $etag; public $attempt; public $wrong_ack = false;
    public function __construct( $fixture ) { $this->fixture = $fixture; $this->raw = $fixture['raw']; $this->etag = $fixture['etag']; $this->attempt = $fixture['attempt_id']; }
    public static function reply( $code, $body, $headers = [] ) { return [ 'response' => [ 'code' => $code ], 'body' => $body, 'headers' => $headers ]; }
    public function request( $url, $args ) {
        $this->calls[] = [ $url, $args ];
        delivery_check( $args['sslverify'] && 0 === $args['redirection'] && $args['reject_unsafe_urls'], 'HTTP safety settings retained.' );
        $path = '/v1/sites/' . $this->fixture['job']['site_id'] . '/deliveries/' . $this->fixture['item']['id'];
        delivery_check( false !== strpos( $url, $path ), 'Only current delivery routes used.' );
        if ( 'GET' === $args['method'] ) {
            delivery_check( ! isset( $args['headers']['If-None-Match'] ), 'GET must return full bytes and current attempt.' );
            return 200 === $this->read_status ? self::reply( 200, $this->raw, [ 'etag' => $this->etag, 'x-nova-attempt-id' => $this->attempt ] ) : self::reply( $this->read_status, '{"error":{"code":"fixture_read_error"}}' );
        }
        delivery_check( substr( $url, -7 ) === '/events', 'Current event endpoint selected.' );
        $bytes = $args['body']; $event = json_decode( $bytes, true );
        delivery_check( ! is_wp_error( Nova_Bridge_Suite_Posting_Protocol::event_input( $event ) ), 'Exact conditional event schema.' );
        $this->posts[] = $bytes;
        if ( $this->post_status ) { return self::reply( $this->post_status, '{"error":{"code":"fixture_event_error"}}' ); }
        $id = $event['event_id'];
        if ( isset( $this->accepted[$id] ) && $this->accepted[$id]['bytes'] !== $bytes ) { return self::reply( 409, '{"error":{"code":"conflict"}}' ); }
        $this->accepted[$id] = $this->accepted[$id] ?? [ 'bytes' => $bytes, 'ack' => nova_contract_fixture_event_accept( $bytes ) ];
        if ( $this->throw_after === $event['kind'] ) { $this->throw_after = ''; throw new RuntimeException( 'Process exception after event acceptance' ); }
        if ( $this->lose === $event['kind'] ) { $this->lose = ''; return new WP_Error( 'fixture_ack_lost' ); }
        $ack = $this->accepted[$id]['ack']; if ( $this->wrong_ack ) { $ack['attempt_id'] = '00000000-0000-4000-8000-000000000099'; }
        return self::reply( 202, json_encode( $ack ) );
    }
}
function delivery_fixture( $patch = [] ) {
    Contract_Test_Writer::reset(); $f = nova_contract_fixture(); $f['connection'] = array_merge( $f['connection'], $patch );
    $s = new Contract_Test_Store(); $server = new Contract_Test_Server( $f ); $GLOBALS['server'] = $server;
    $configuration = static function ( $snapshot, $site ) use ( $f ) { return $f['configuration']; };
    $policy = static function () use ( $f ) { return [ 'reference' => [ 'reference_type' => 'post', 'reference_id' => 77 ], 'routing' => [ 'operation' => 'update', 'publication' => 'publish' ] ]; };
    $w = new Nova_Bridge_Suite_Posting_Worker( $f['connection'], $s, new Nova_Bridge_Suite_Posting_Client( $f['connection'] ), $configuration, 'Contract_Test_Writer', $policy );
    return [ $f['job'], $s, $server, $w ];
}
function delivery_resume( $job ) { $job['state'] = 'running'; return $job; }

[ $job, $store, $server, $worker ] = delivery_fixture(); $GLOBALS['actor'] = 9;
$result = $worker->process( $job );
delivery_check( 'complete' === $result['state'] && 1 === Contract_Test_Writer::$apply && [] === $store->locks && 9 === get_current_user_id(), 'One native write, locks and actor restored.' );
$events = array_map( static function ( $b ) { return json_decode( $b, true ); }, $server->posts );
delivery_check( [ 'received_complete', 'publication_succeeded' ] === array_column( $events, 'kind' ) && $events[0]['event_id'] !== $events[1]['event_id'], 'Separate persisted IDs for receipt and publication.' );
delivery_check( 'pending' === $result['payload']['events']['publication_succeeded']['accepted']['status'], 'Acceptance does not claim backend application.' );
delivery_check( $result['payload']['snapshot_raw'] === $server->raw && $events[1]['delivery_etag'] === $server->etag && $events[1]['source_sha256'] !== hash( 'sha256', $server->raw ), 'Raw delivery and backend source hash remain separate.' );

foreach ( [ 'received_complete', 'publication_succeeded' ] as $lost ) {
    [ $job, $store, $server, $worker ] = delivery_fixture(); $server->lose = $lost;
    $result = $worker->process( $job );
    delivery_check( 'event_pending' === $result['phase'] && 'ready' === $result['state'], 'Lost acknowledgement retains durable event bytes.' );
    $pending = $result['payload']['event_bytes']; $result = $worker->process( delivery_resume( $result ) );
    delivery_check( 'complete' === $result['state'] && 1 === Contract_Test_Writer::$apply && count( $server->accepted ) === 2, 'Exact event replay continues once without repeating native write.' );
    delivery_check( count( array_filter( $server->posts, static function ( $b ) use ( $pending ) { return $pending === $b; } ) ) === 2, 'Lost response replays identical event ID and bytes.' );
}
foreach ( [ 'received_complete', 'publication_succeeded' ] as $kind ) {
    [ $job, $store, $server, $worker ] = delivery_fixture(); $server->throw_after = $kind;
    $result = $worker->process( $job );
    delivery_check( 'event_pending' === $result['phase'] && isset( $result['payload']['event_bytes'] ), 'Thrown exception preserves the newly saved event journal.' );
    $pending = $result['payload']['event_bytes']; $result = $worker->process( delivery_resume( $result ) );
    delivery_check( 'complete' === $result['state'] && 1 === Contract_Test_Writer::$apply && count( $server->accepted ) === 2, 'Exception recovery creates no new event identity or native write.' );
    delivery_check( 2 === count( array_filter( $server->posts, static function ( $bytes ) use ( $pending ) { return $bytes === $pending; } ) ), 'Exception recovery replays the exact accepted event bytes.' );
}
[ $job, $store, $server, $worker ] = delivery_fixture(); Contract_Test_Writer::$crash = true;
$result = $worker->process( $job );
delivery_check( 'applying' === $result['phase'] && 'ready' === $result['state'], 'Process loss retains uncertain mutation phase.' );
Contract_Test_Writer::$crash = false; $result = $worker->process( delivery_resume( $result ) );
delivery_check( 'complete' === $result['state'] && 1 === Contract_Test_Writer::$apply && 1 === Contract_Test_Writer::$recover, 'Native commit is recovered without a second write.' );

foreach ( [ 'tampered_bytes', 'weak_etag', 'missing_attempt', 'wrong_site', 'legacy_member', 'unexpected_304' ] as $case ) {
    [ $job, $store, $server, $worker ] = delivery_fixture();
    if ( 'tampered_bytes' === $case ) { $server->raw .= ' '; }
    elseif ( 'weak_etag' === $case ) { $server->etag = 'W/' . $server->etag; }
    elseif ( 'missing_attempt' === $case ) { $server->attempt = ''; }
    elseif ( 'unexpected_304' === $case ) { $server->read_status = 304; }
    else {
        $body = json_decode( $server->raw, true );
        if ( 'wrong_site' === $case ) { $body['site_id'] = '00000000-0000-4000-8000-000000000099'; }
        else { $body['pin'] = []; }
        $server->raw = json_encode( $body ); $server->etag = '"' . hash( 'sha256', $server->raw ) . '"';
    }
    $result = $worker->process( $job );
    delivery_check( 'blocked' === $result['state'] && 0 === Contract_Test_Writer::$apply && [] === $server->posts, $case . ' stops before any event/write.' );
}
[ $job, $store, $server, $worker ] = delivery_fixture(); $server->attempt = '00000000-0000-4000-8000-000000000099';
$result = $worker->process( $job );
delivery_check( 'complete' === $result['state'] && json_decode( $server->posts[0], true )['attempt_id'] === $server->attempt, 'Header attempt is authoritative over historical body attempt.' );
[ $job, $store, $server, $worker ] = delivery_fixture(); Contract_Test_Writer::$crash = true;
$result = $worker->process( $job ); Contract_Test_Writer::$crash = false; $server->attempt = '00000000-0000-4000-8000-000000000099';
$result = $worker->process( delivery_resume( $result ) );
delivery_check( 'blocked' === $result['state'] && 1 === Contract_Test_Writer::$apply && 0 === Contract_Test_Writer::$recover, 'Changed attempt cannot replay uncertain native write.' );

foreach ( [ 'draft', 'future', 'pending', 'private' ] as $waiting_status ) {
    [ $job, $store, $server, $worker ] = delivery_fixture(); Contract_Test_Writer::$status = $waiting_status;
    $job['attempts'] = 19;
    $result = $worker->process( $job );
    delivery_check( 'awaiting_publication' === $result['last_error'] && 'committed' === $result['phase'] && count( $server->posts ) === 1, $waiting_status . ' produces receipt only and awaits actual publication.' );
    delivery_check( 0 === $result['attempts'], 'Successful publication waiting resets the consecutive retry budget.' );
    $server->read_status = 503; $next = delivery_resume( $result ); $next['attempts'] = 1;
    $result = $worker->process( $next );
    delivery_check( 'ready' === $result['state'] && 'committed' === $result['phase'], 'One transient failure after long publication waiting remains retryable.' );
    $server->read_status = 200;
    Contract_Test_Writer::$status = 'publish'; $result = $worker->process( delivery_resume( $result ) );
    delivery_check( 'complete' === $result['state'] && 1 === Contract_Test_Writer::$apply && count( $server->posts ) === 2, 'Later publication is verified and reported without another content write.' );
}

[ $job, $store, $server, $worker ] = delivery_fixture(); Contract_Test_Writer::$plan_error = true;
$result = $worker->process( $job );
delivery_check( 'complete' === $result['state'] && 0 === Contract_Test_Writer::$apply && json_decode( $server->posts[1], true )['kind'] === 'publication_failed', 'Proven pre-write failure is reported separately from receipt.' );
$failed = $result; $failed['phase'] = 'attempt_check'; $server->attempt = '00000000-0000-4000-8000-000000000099'; Contract_Test_Writer::$plan_error = false;
$result = $worker->process( delivery_resume( $failed ) );
delivery_check( 'complete' === $result['state'] && 1 === Contract_Test_Writer::$apply && count( $result['payload']['prior_attempts'] ) === 1, 'Operator-issued new attempt proceeds only after retained no-native-write proof.' );
$result['phase'] = 'attempt_check'; $server->attempt = '00000000-0000-4000-8000-000000000098';
$result = $worker->process( delivery_resume( $result ) );
delivery_check( 'blocked' === $result['state'] && 1 === Contract_Test_Writer::$apply, 'Completed native write refuses a new attempt.' );

foreach ( [ 'event_pending', 'applying' ] as $phase ) {
    [ $job, $store, $server, $worker ] = delivery_fixture(); $store->fail_phase = $phase;
    $result = $worker->process( $job );
    delivery_check( is_wp_error( $result ) && 0 === Contract_Test_Writer::$apply && count( $server->posts ) === ( 'applying' === $phase ? 1 : 0 ), 'Journal failure precedes external effect at ' . $phase );
}
[ $job, $store, $server, $worker ] = delivery_fixture(); $store->fail_state = 'complete';
$result = $worker->process( $job );
delivery_check( is_wp_error( $result ) && 'event_pending' === $store->saved['phase'], 'Failed local acknowledgement retains outgoing event.' );
$store->fail_state = ''; $result = $worker->process( delivery_resume( $store->saved ) );
delivery_check( 'complete' === $result['state'] && 1 === Contract_Test_Writer::$apply, 'Local acknowledgement recovery never repeats mutation.' );

[ $job, $store, $server, $worker ] = delivery_fixture( [ 'paused' => true ] );
$job['attempts'] = 19;
$result = $worker->process( $job );
delivery_check( 'paused' === $result['last_error'] && 0 === $result['attempts'] && [] === $server->calls && 0 === Contract_Test_Writer::$apply, 'Pause prevents new reads/mutations without exhausting retries.' );
[ $job, $store, $server, $worker ] = delivery_fixture(); $server->post_status = 409;
$result = $worker->process( $job );
delivery_check( 'blocked' === $result['state'] && 0 === Contract_Test_Writer::$apply, 'Event conflict blocks before publishing.' );
[ $job, $store, $server, $worker ] = delivery_fixture(); $server->wrong_ack = true;
$result = $worker->process( $job );
delivery_check( 'blocked' === $result['state'] && 0 === Contract_Test_Writer::$apply, 'Wrong acceptance identity cannot authorize continuation.' );
echo 'PASS ' . $checks . " current delivery execution checks.\n";
