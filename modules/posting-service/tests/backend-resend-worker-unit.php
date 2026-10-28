<?php
/** Real client/worker with deterministic HTTP, journal and CMS boundaries. No DB/network. */
require __DIR__ . '/delivery-unit.php';
$checks = 0;
const REPLACEMENT_ATTEMPT = '00000000-0000-4000-8000-000000000099';

foreach ( [ 'publication_failed', 'publication_succeeded' ] as $kind ) {
    [ $job, $store, $server, $worker ] = delivery_fixture();
    Contract_Test_Writer::$plan_error = 'publication_failed' === $kind;
    $server->lose = $kind; $old = $worker->process( $job );
    $pending = $old['payload']['event_bytes']; $posts = count( $server->posts );
    Contract_Test_Writer::$plan_error = false; $server->attempt = REPLACEMENT_ATTEMPT;
    $next = $worker->process( delivery_resume( $old ) );
    delivery_check( 'complete' === $next['state'] && 1 === Contract_Test_Writer::$apply, 'Replacement completes without duplicating native publication.' );
    delivery_check( $pending === $next['payload']['prior_attempts'][0]['event_bytes'], 'Rejected/pending old receipt bytes remain exact history.' );
    foreach ( array_slice( $server->posts, $posts ) as $bytes ) {
        delivery_check( json_decode( $bytes, true )['attempt_id'] === REPLACEMENT_ATTEMPT && $bytes !== $pending, 'Only the current attempt receives new receipts.' );
    }
}
foreach ( [ 'accepted', 'applying', 'event_pending' ] as $phase ) {
    [ $job, $store, $server, $worker ] = delivery_fixture();
    if ( 'applying' === $phase ) { Contract_Test_Writer::$crash = true; $job = $worker->process( $job ); }
    if ( 'event_pending' === $phase ) { $server->lose = 'publication_succeeded'; $job = $worker->process( $job ); }
    $posts = $server->posts; $writes = Contract_Test_Writer::$apply; $history = $job['payload']; $server->read_status = 410;
    $retired = $worker->process( delivery_resume( $job ) );
    delivery_check( 'complete' === $retired['state'] && 'superseded' === $retired['phase'], 'HTTP 410 retires ' . $phase . ' work.' );
    delivery_check( $posts === $server->posts && $writes === Contract_Test_Writer::$apply, 'Retirement neither posts stale receipts nor starts a native write.' );
    foreach ( [ 'plan', 'result', 'event_bytes' ] as $key ) { if ( isset( $history[$key] ) ) { delivery_check( $retired['payload'][$key] === $history[$key], 'Retirement preserves ' . $key . '.' ); } }
    $calls = $server->calls; $worker->process( delivery_resume( $retired ) );
    delivery_check( $calls === $server->calls, 'A retired job remains terminal on accidental replay.' );
}

class Handover_Writer extends Contract_Test_Writer {
    public static $after_plan; public static $after_apply;
    public static function plan( $snapshot, $configuration, $context ) {
        $plan = parent::plan( $snapshot, $configuration, $context );
        if ( self::$after_plan ) { $fn = self::$after_plan; self::$after_plan = null; $fn(); }
        return $plan;
    }
    public static function apply( $plan, $operation ) {
        $result = parent::apply( $plan, $operation );
        if ( self::$after_apply ) { $fn = self::$after_apply; self::$after_apply = null; $fn(); }
        return $result;
    }
}
function handover_worker( $fixture, $store, $server, $writer = 'Handover_Writer' ) {
    $GLOBALS['server'] = $server;
    return new Nova_Bridge_Suite_Posting_Worker( $fixture['connection'], $store, new Nova_Bridge_Suite_Posting_Client( $fixture['connection'] ),
        static function () use ( $fixture ) { return $fixture['configuration']; }, $writer,
        static function () { return [ 'reference' => [ 'reference_type' => 'post', 'reference_id' => 77 ], 'routing' => [ 'operation' => 'update', 'publication' => 'publish' ] ]; } );
}
foreach ( [ 'after_plan', 'after_apply' ] as $boundary ) {
    Contract_Test_Writer::reset(); $fixture = nova_contract_fixture(); $store = new Contract_Test_Store(); $server = new Contract_Test_Server( $fixture );
    $worker = handover_worker( $fixture, $store, $server );
    Handover_Writer::$$boundary = static function () use ( $server ) { $server->attempt = REPLACEMENT_ATTEMPT; };
    $next = $worker->process( $fixture['job'] );
    delivery_check( 'ready' === $next['state'] && 'validated' === $next['phase'], 'Changed authority at ' . $boundary . ' yields to the replacement.' );
    delivery_check( Contract_Test_Writer::$apply === ( 'after_plan' === $boundary ? 0 : 1 ), 'The final boundary prevents an obsolete write.' );
    if ( 'after_plan' === $boundary ) { Contract_Test_Writer::$recover_value = [ 'state' => 'not_committed', 'no_native_commit' => true, 'safe_to_apply' => true ]; }
    $next = $worker->process( delivery_resume( $next ) );
    delivery_check( 'complete' === $next['state'] && 1 === Contract_Test_Writer::$apply, 'Replacement reconciles or applies once at ' . $boundary . '.' );
    delivery_check( json_decode( end( $server->posts ), true )['attempt_id'] === REPLACEMENT_ATTEMPT, 'Only current authority gets final success.' );
}

class Rejected_Old_Receipt_Server extends Contract_Test_Server {
    public $replaced = false;
    public function request( $url, $args ) {
        if ( 'POST' === $args['method'] && ! $this->replaced ) { $this->replaced = true; $this->attempt = REPLACEMENT_ATTEMPT; return self::reply( 409, '{"error":{"code":"conflict"}}' ); }
        return parent::request( $url, $args );
    }
}
Contract_Test_Writer::reset(); $fixture = nova_contract_fixture(); $store = new Contract_Test_Store(); $server = new Rejected_Old_Receipt_Server( $fixture );
$worker = handover_worker( $fixture, $store, $server ); $next = $worker->process( $fixture['job'] );
delivery_check( 'ready' === $next['state'] && REPLACEMENT_ATTEMPT === $next['payload']['attempt_id'], 'A callback race rechecks authority and schedules replacement instead of blocking.' );
$next = $worker->process( delivery_resume( $next ) );
delivery_check( 'complete' === $next['state'] && 1 === Contract_Test_Writer::$apply, 'A rejected old callback cannot strand new work.' );

class Predecessor_Store extends Contract_Test_Store {
    public $previous = []; public $retired = []; public $deny_publication = false;
    public function predecessors( $job, $snapshot ) { return $this->previous; }
    public function retire_predecessor( $job, $evidence ) { $this->retired[] = [ $job, $evidence ]; $this->previous = []; return true; }
    public function lock( $key ) { return $this->deny_publication && 0 === strpos( $key, 'publication:' ) ? new WP_Error( 'busy' ) : parent::lock( $key ); }
}
class Previous_Delivery_Server extends Contract_Test_Server {
    public $previous_id; public $previous_status = 410;
    public function request( $url, $args ) {
        if ( strpos( $url, '/deliveries/' . $this->previous_id ) !== false ) { return self::reply( $this->previous_status, '{}' ); }
        return parent::request( $url, $args );
    }
}
foreach ( [ 'retired', 'still_active', 'unknown_native' ] as $case ) {
    Contract_Test_Writer::reset(); $fixture = nova_contract_fixture(); $store = new Predecessor_Store(); $server = new Previous_Delivery_Server( $fixture );
    $previous = $fixture['job']; $previous['id'] = 123; $previous['payload']['content'] = $fixture['snapshot'];
    $previous['payload']['content']['version_number'] = $fixture['snapshot']['version_number'] - 1;
    $previous['payload']['discovery']['id'] = $server->previous_id = '99999999-9999-4999-8999-999999999999';
    $previous['payload']['plan'] = [ 'context' => [ 'target_post_id' => 77 ] ];
    $previous['payload']['pending_event'] = [ 'kind' => 'publication_failed' ]; $store->previous = [ $previous ];
    if ( 'still_active' === $case ) { $server->previous_status = 200; }
    if ( 'unknown_native' === $case ) { Contract_Test_Writer::$recover_value = new WP_Error( 'unknown' ); }
    $worker = handover_worker( $fixture, $store, $server ); $next = $worker->process( $fixture['job'] );
    delivery_check( ( 'retired' === $case ) === ( 'complete' === $next['state'] ), 'Newer-version handover requires retired authority and native proof: ' . $case );
    delivery_check( Contract_Test_Writer::$apply === ( 'retired' === $case ? 1 : 0 ), 'No competing/unreconciled native write: ' . $case );
    if ( 'retired' === $case ) { delivery_check( $store->retired[0][0]['payload']['pending_event'] === $previous['payload']['pending_event'], 'Prior pending receipt survives as history, not an outgoing event.' ); }
}
Contract_Test_Writer::reset(); $fixture = nova_contract_fixture(); $store = new Predecessor_Store(); $store->deny_publication = true; $server = new Contract_Test_Server( $fixture );
$worker = handover_worker( $fixture, $store, $server ); $next = $worker->process( $fixture['job'] );
delivery_check( 'target_busy' === $next['last_error'] && 0 === Contract_Test_Writer::$apply && [] === $server->posts, 'The common URL lock serializes old/new deliveries before effects.' );
echo "Backend resend worker checks: $checks passed.\n";
