<?php
/** Run directly with PHP; no WordPress, network or existing site content is accessed. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );
class WP_Error {
    private $code; private $message; private $data;
    public function __construct( $code, $message = '', $data = [] ) { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_code() { return $this->code; }
    public function get_error_data() { return $this->data; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_generate_uuid4() { static $next = 0; return 'test-uuid-' . ++$next; }
function get_current_user_id() { return $GLOBALS['test_user'] ?? 0; }
function wp_set_current_user( $id ) { $GLOBALS['test_user'] = $id; }
function get_post_status( $id ) { return 'publish'; }
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-posting-jobs.php';
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-posting-worker.php';
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-posting-delivery.php';

$checks = 0;
function check_delivery( $condition, string $message ): void {
    global $checks; ++$checks;
    if ( ! $condition ) { throw new RuntimeException( $message ); }
}

class Delivery_Test_Store {
    public $saved; public $history = []; public $newer = false; public $uncertain = false; public $fail_phase = ''; public $fail_code = 'journal_fail'; public $locks = [];
    public function save( $job, $changes ) {
        if ( $this->fail_phase && ( $changes['phase'] ?? '' ) === $this->fail_phase ) { return new WP_Error( $this->fail_code ); }
        $this->saved = array_merge( $job, $changes ); $this->history[] = $this->saved; return $this->saved;
    }
    public function lock( $name ) { $this->locks[ $name ] = true; return true; }
    public function unlock( $name ) { unset( $this->locks[ $name ] ); }
    public function newer_committed( $job ) { return $this->newer; }
    public function uncertain_target( $job ) { return $this->uncertain; }
}

class Delivery_Test_Client {
    public $content; public $remote = null; public $calls = []; public $lost_ack = false; public $get_error = false; public $conflict = false;
    public function __construct( $content ) { $this->content = $content; }
    public function request( $method, $path, $body = null ) {
        $this->calls[] = [ $method, $path, $body ];
        if ( 'GET' === $method && false !== strpos( $path, '/result?' ) ) {
            if ( $this->get_error ) { return new WP_Error( 'outage', '', [ 'status' => 503 ] ); }
            return null === $this->remote ? new WP_Error( 'missing', '', [ 'status' => 404 ] ) : [ 'status' => 200, 'body' => $this->remote ];
        }
        if ( 'GET' === $method ) { return [ 'status' => 200, 'body' => $this->content ]; }
        $this->remote = array_merge( [ 'content_id' => '123' ], $body );
        if ( $this->conflict ) { $this->remote['remote_post_id'] = '999'; }
        if ( $this->lost_ack ) { $this->lost_ack = false; return new WP_Error( 'timeout' ); }
        return [ 'status' => 201, 'body' => $this->remote ];
    }
}

class Delivery_Test_Writer {
    public static $apply = 0; public static $recover = 0; public static $finish = 0; public static $verify = 0;
    public static $throw_after_commit = false; public static $finish_error = false; public static $recovery = null; public static $contexts = [];
    public static function reset(): void { self::$apply = self::$recover = self::$finish = self::$verify = 0; self::$throw_after_commit = self::$finish_error = false; self::$recovery = null; self::$contexts = []; }
    public static function result() { return [ 'state' => 'complete', 'post_id' => 77, 'cms_post_status' => 'publish', 'operation_id' => 'operation-1' ]; }
    public static function plan( $content, $configuration, $context ) { self::$contexts[] = $context; return [ 'target_post_id' => 77, 'site_id' => $context['site_id'], 'operation_id' => $context['operation_id'] ]; }
    public static function apply( $plan, $operation ) { ++self::$apply; if ( self::$throw_after_commit ) { throw new RuntimeException( 'Simulated crash after transactional commit' ); } return self::result(); }
    public static function recover( $plan, $operation ) { ++self::$recover; return self::$recovery ?? self::result(); }
    public static function finish( $plan, $result ) { ++self::$finish; return self::$finish_error ? new WP_Error( 'derived_pending' ) : $result; }
    public static function verify( $plan, $result ) { ++self::$verify; return $result; }
}

function delivery_fixture( array $content_overrides = [], array $connection_overrides = [] ): array {
    Delivery_Test_Writer::reset();
    $connection = array_merge( [ 'site_id' => 'a14ba9d3-6af5-4c7f-841a-f588561c71bd', 'enabled' => true, 'paused' => false, 'actor_user_id' => 42, 'webhook_secret' => 'fixture-secret' ], $connection_overrides );
    $job = [ 'id' => 1, 'site_id' => $connection['site_id'], 'content_id' => '123', 'version' => 2, 'state' => 'running', 'phase' => 'accepted', 'target_id' => 0, 'operation_id' => 'operation-1', 'attempts' => 1, 'next_attempt' => 0, 'last_error' => '', 'lease_token' => 'owned', 'lease_until' => time() + 300, 'payload' => [] ];
    $content = array_merge( [ 'content_id' => '123', 'version' => 2, 'status' => 'ready', 'template_id' => 'template-1', 'template_version' => 'revision-1', 'pin_id' => 'pin-1', 'digest' => 'digest-1', 'fields' => [ 'heading' => 'New' ] ], $content_overrides );
    $store = new Delivery_Test_Store(); $client = new Delivery_Test_Client( $content );
    $configuration = static function ( $pin, $digest, $site ) { return [ 'site_id' => $site, 'pin_id' => $pin, 'digest' => $digest, 'local' => [ 'reference_type' => 'post', 'reference_id' => 77, 'routing' => [ 'operation' => 'update', 'publication' => 'preserve' ] ] ]; };
    $worker = new Nova_Bridge_Suite_Posting_Worker( $connection, $store, $client, $configuration, 'Delivery_Test_Writer' );
    return [ $job, $store, $client, $worker, $connection ];
}

[ $job, $store, $client, $worker, $connection ] = delivery_fixture();
$raw = json_encode( [ 'event' => 'content.ready', 'content_id' => '123', 'site' => $connection['site_id'], 'version' => 2 ] );
$timestamp = '2000000000';
$signature = 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $raw, $connection['webhook_secret'] );
check_delivery( is_array( Nova_Bridge_Suite_Posting_Delivery::authenticate( $raw, $timestamp, $signature, $connection, 2000000000 ) ), 'Correct raw-body signature accepted.' );
check_delivery( is_wp_error( Nova_Bridge_Suite_Posting_Delivery::authenticate( $raw . ' ', $timestamp, $signature, $connection, 2000000000 ) ), 'Byte alteration fails authentication.' );
check_delivery( is_wp_error( Nova_Bridge_Suite_Posting_Delivery::authenticate( $raw, $timestamp, $signature, $connection, 2000000301 ) ), 'Expired timestamp fails authentication.' );
check_delivery( is_wp_error( Nova_Bridge_Suite_Posting_Delivery::authenticate( $raw, $timestamp, $signature, $connection, 1999999699 ) ), 'Too-far future timestamp fails authentication.' );
check_delivery( is_wp_error( Nova_Bridge_Suite_Posting_Delivery::authenticate( str_repeat( 'a', 16385 ), $timestamp, $signature, $connection, 2000000000 ) ), 'Oversize body bounded before decode.' );
foreach ( [ [ 'extra' => true ], [ 'version' => '2' ], [ 'version' => 0 ], [ 'content_id' => 123 ], [ 'site' => 'other-site' ], [ 'event' => 'diagnostic.challenge' ] ] as $patch ) {
    $bad = json_encode( array_merge( json_decode( $raw, true ), $patch ) );
    $sig = 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $bad, $connection['webhook_secret'] );
    check_delivery( is_wp_error( Nova_Bridge_Suite_Posting_Delivery::authenticate( $bad, $timestamp, $sig, $connection, 2000000000 ) ), 'Signed unsupported shape rejected.' );
}

$GLOBALS['test_user'] = 9;
$result = $worker->process( $job );
check_delivery( 'complete' === $result['state'] && 1 === Delivery_Test_Writer::$apply, 'Normal exact delivery applies and acknowledges once.' );
check_delivery( [] === $store->locks && 9 === get_current_user_id(), 'Target locks and original user restored.' );
check_delivery( '/v1/content/123?version=2' === $client->calls[1][1], 'Fetch always explicitly requests notified exact version.' );
check_delivery( 'publish' === Delivery_Test_Writer::$contexts[0]['desired_status'], 'Preserve policy keeps current published status.' );
$phases = array_column( $store->history, 'phase' );
check_delivery( array_search( 'applying', $phases, true ) < array_search( 'committed', $phases, true ), 'Mutation boundary is persisted before committed state.' );

foreach ( [ [ 'version' => 3 ], [ 'content_id' => '124' ], [ 'status' => 'pending' ], [ 'site_id' => 'wrong' ] ] as $patch ) {
    [ $job, $store, $client, $worker ] = delivery_fixture( $patch ); $result = $worker->process( $job );
    check_delivery( 'blocked' === $result['state'] && 0 === Delivery_Test_Writer::$apply, 'Exact identity mismatch never mutates.' );
}
[ $job, $store, $client, $worker ] = delivery_fixture(); $job['site_id'] = 'other-installation'; $result = $worker->process( $job );
check_delivery( 'installation_mismatch' === $result['last_error'] && 0 === Delivery_Test_Writer::$apply && [] === $client->calls, 'Changing configured site never processes a retained foreign-site job.' );
[ $job, $store, $client, $worker ] = delivery_fixture( [ 'repeat_instances' => [ 'steps' => [ [ 'slot_id' => 'one', 'instance_id' => 'a' ], [ 'slot_id' => 'one', 'instance_id' => 'b' ] ] ] ] ); $result = $worker->process( $job );
check_delivery( 'blocked' === $result['state'] && 0 === Delivery_Test_Writer::$apply, 'Duplicate repeat native slots stop before writer planning.' );
[ $job, $store, $client, $worker ] = delivery_fixture( [ 'repeat_instances' => [ 'steps' => [ [ 'slot_id' => 'one', 'instance_id' => 'a' ], [ 'slot_id' => 'two', 'instance_id' => 'a' ] ] ] ] ); $result = $worker->process( $job );
check_delivery( 'blocked' === $result['state'] && 0 === Delivery_Test_Writer::$apply, 'Duplicate repeat generated identities stop before writer planning.' );
$repeat = [ 'steps' => [ [ 'slot_id' => 'one', 'instance_id' => 'a' ], [ 'slot_id' => 'two', 'instance_id' => 'b' ] ] ];
[ $job, $store, $client, $worker ] = delivery_fixture( [ 'repeat_instances' => $repeat ] ); $result = $worker->process( $job );
check_delivery( $repeat === Delivery_Test_Writer::$contexts[0]['repeat_instances'], 'Exact fetched repeat identities are passed intact to pinned planner validation.' );
[ $job, $store, $client, $worker ] = delivery_fixture( [ 'pin_id' => null ] ); $result = $worker->process( $job );
check_delivery( 'validated' === $result['phase'] && 'blocked' === $result['state'] && isset( $result['payload']['content'] ) && 0 === Delivery_Test_Writer::$apply, 'Unpinned current-service payload is retained and blocked, not assigned latest config.' );
[ $job, $store, $client, $worker ] = delivery_fixture(); $client->get_error = true; $result = $worker->process( $job );
check_delivery( 'ready' === $result['state'] && 0 === Delivery_Test_Writer::$apply && count( $client->calls ) === 1, 'Ambiguous receipt lookup blocks all CMS work.' );
[ $job, $store, $client, $worker ] = delivery_fixture(); $store->fail_phase = 'applying'; $result = $worker->process( $job );
check_delivery( is_wp_error( $result ) && 0 === Delivery_Test_Writer::$apply, 'Journal failure before mutation prevents write.' );
[ $job, $store, $client, $worker ] = delivery_fixture(); $store->fail_phase = 'planned'; $store->fail_code = 'nova_posting_journal_size'; $result = $worker->process( $job );
check_delivery( is_wp_error( $result ) && 'blocked' === $store->saved['state'] && 'journal_size' === $store->saved['last_error'] && 0 === Delivery_Test_Writer::$apply, 'Oversized plan is visibly blocked instead of entering an endless lease retry.' );
[ $job, $store, $client, $worker ] = delivery_fixture(); $store->newer = true; $result = $worker->process( $job );
check_delivery( 'newer_version_already_committed' === $result['last_error'] && 0 === Delivery_Test_Writer::$apply, 'Older delayed version cannot overwrite newer committed version.' );
[ $job, $store, $client, $worker ] = delivery_fixture(); $store->uncertain = true; $result = $worker->process( $job );
check_delivery( 'other_mutation_unreconciled' === $result['last_error'] && 0 === Delivery_Test_Writer::$apply, 'Other ambiguous job prevents overwrite before reconciliation.' );

[ $job, $store, $client, $worker ] = delivery_fixture(); $client->lost_ack = true; $result = $worker->process( $job );
check_delivery( 'receipt_pending' === $result['phase'] && 'ready' === $result['state'] && 1 === Delivery_Test_Writer::$apply, 'Lost acknowledgement retains exact receipt after single mutation.' );
$result['state'] = 'running'; ++$result['attempts']; $recovered = $worker->process( $result );
check_delivery( 'complete' === $recovered['state'] && 1 === Delivery_Test_Writer::$apply && 1 === Delivery_Test_Writer::$finish, 'Receipt recovery never repeats mutation or derived work.' );
check_delivery( count( array_filter( $client->calls, static function ( $call ) { return 'POST' === $call[0]; } ) ) === 1, 'Lost acknowledgement is resolved by reading stored winner.' );

[ $job, $store, $client, $worker ] = delivery_fixture(); Delivery_Test_Writer::$throw_after_commit = true; $result = $worker->process( $job );
check_delivery( 'applying' === $result['phase'] && 'ready' === $result['state'], 'Crash after commit retains recover-only phase.' );
$result['state'] = 'running'; ++$result['attempts']; Delivery_Test_Writer::$throw_after_commit = false; $recovered = $worker->process( $result );
check_delivery( 'complete' === $recovered['state'] && 1 === Delivery_Test_Writer::$apply && 1 === Delivery_Test_Writer::$recover, 'Crash recovery finds committed operation and does not clone twice.' );

[ $job, $store, $client, $worker ] = delivery_fixture(); Delivery_Test_Writer::$finish_error = true; $result = $worker->process( $job );
check_delivery( 'committed' === $result['phase'] && 'ready' === $result['state'], 'Derived failure is retained postcommit.' );
$result['state'] = 'running'; ++$result['attempts']; Delivery_Test_Writer::$finish_error = false; $recovered = $worker->process( $result );
check_delivery( 'complete' === $recovered['state'] && 1 === Delivery_Test_Writer::$apply && 2 === Delivery_Test_Writer::$finish, 'Derived retry only finishes committed operation.' );

[ $job, $store, $client, $worker ] = delivery_fixture(); $client->conflict = true; $result = $worker->process( $job );
check_delivery( 'receipt_conflict' === $result['last_error'] && 'blocked' === $result['state'], 'Conflicting immutable remote winner is surfaced.' );
[ $job, $store, $client, $worker ] = delivery_fixture(); $client->remote = [ 'content_id' => '123', 'version' => 2, 'outcome' => 'posted', 'remote_post_id' => '999' ]; $result = $worker->process( $job );
check_delivery( 'blocked' === $result['state'] && 0 === Delivery_Test_Writer::$apply, 'Unknown preexisting remote winner never authorizes overwrite.' );
[ $job, $store, $client, $worker ] = delivery_fixture( [], [ 'paused' => true ] ); $result = $worker->process( $job );
check_delivery( 'paused' === $result['last_error'] && 0 === Delivery_Test_Writer::$apply, 'Paused worker does no new CMS mutation.' );
$job['phase'] = 'applying'; $job['target_id'] = 77; $job['payload']['plan'] = []; $result = $worker->process( $job );
check_delivery( 'complete' === $result['state'] && 0 === Delivery_Test_Writer::$apply && 1 === Delivery_Test_Writer::$recover, 'Paused worker reconciles a previously committed operation and drains its receipt.' );
[ $job, $store, $client, $worker ] = delivery_fixture( [], [ 'paused' => true ] ); $job['phase'] = 'applying'; $job['target_id'] = 77; $job['payload']['plan'] = []; Delivery_Test_Writer::$recovery = [ 'state' => 'not_committed', 'safe_to_apply' => true ]; $result = $worker->process( $job );
check_delivery( 'paused' === $result['last_error'] && 0 === Delivery_Test_Writer::$apply, 'Paused recovery proof of no commit does not authorize a new mutation.' );
[ $job, $store, $client, $worker ] = delivery_fixture(); $job['phase'] = 'applying'; $job['target_id'] = 77; $job['payload']['plan'] = []; Delivery_Test_Writer::$recovery = new WP_Error( 'ambiguous' ); $result = $worker->process( $job );
check_delivery( 'blocked' === $result['state'] && 0 === Delivery_Test_Writer::$apply, 'Ambiguous marker recovery requires review, never a new clone.' );
[ $job, $store, $client, $worker ] = delivery_fixture(); $job['phase'] = 'applying'; $job['target_id'] = 77; $job['payload']['plan'] = []; Delivery_Test_Writer::$recovery = [ 'state' => 'not_committed', 'safe_to_apply' => true ]; $result = $worker->process( $job );
check_delivery( 'complete' === $result['state'] && 1 === Delivery_Test_Writer::$apply, 'Proven atomic absence can safely resume application.' );
[ $job, $store, $client, $worker ] = delivery_fixture(); $job['phase'] = 'applying'; $job['target_id'] = 77; $job['payload']['plan'] = []; Delivery_Test_Writer::$recovery = [ 'state' => 'not_committed', 'safe_to_apply' => true ]; $client->remote = [ 'content_id' => '123', 'version' => 2, 'outcome' => 'posted', 'remote_post_id' => '999', 'cms_post_status' => 'publish' ]; $result = $worker->process( $job );
check_delivery( 'blocked' === $result['state'] && 0 === Delivery_Test_Writer::$apply, 'A remote winner prevents absent-local-marker recovery from creating another page.' );
[ $job, $store, $client, $worker ] = delivery_fixture(); $job['phase'] = 'applying'; $job['target_id'] = 77; $job['payload']['plan'] = []; $client->remote = [ 'content_id' => '123', 'version' => 2, 'outcome' => 'posted', 'remote_post_id' => '999', 'cms_post_status' => 'publish' ]; $result = $worker->process( $job );
check_delivery( 'receipt_conflict' === $result['last_error'] && 0 === Delivery_Test_Writer::$finish, 'Conflicting receipt is found before derived work or publication status promotion.' );
[ $job, $store, $client, $worker ] = delivery_fixture(); $job['attempts'] = 8; $client->get_error = true; $result = $worker->process( $job );
check_delivery( 'blocked' === $result['state'] && false !== strpos( $result['last_error'], 'retry_limit' ), 'Transient retry limit is bounded and visible.' );

class Delivery_SQL_Probe {
    public $prefix = 'wp_'; public $last_error = ''; public $sql = ''; public $args; public $change = 1;
    public function prepare( $query, ...$args ) { $this->sql = $query; $this->args = $args; return $query; }
    public function query( $query ) { $this->sql = $query; return $this->change; }
}
$db = new Delivery_SQL_Probe(); $journal = new Nova_Bridge_Suite_Posting_Jobs( $db );
[ $job ] = delivery_fixture(); $journal->save( $job, [ 'state' => 'blocked', 'last_error' => 'fixture' ] );
check_delivery( false !== strpos( $db->sql, "state='running' AND lease_token=%s AND lease_until >= %d" ), 'Database transition checks ownership and unexpired lease atomically.' );
$db->change = 0; check_delivery( is_wp_error( $journal->save( $job, [ 'phase' => 'applying' ] ) ), 'Lost CAS lease is an error, not permission to mutate.' );
$job['payload'] = [ 'large' => str_repeat( 'x', Nova_Bridge_Suite_Posting_Jobs::MAX_PAYLOAD_BYTES ) ]; check_delivery( is_wp_error( $journal->save( $job, [] ) ), 'Persisted plan size is bounded.' );
class Delivery_Scope_Probe {
    public $prefix = 'wp_'; public $last_error = ''; public $values = []; public $queries = [];
    public function prepare( $query, ...$args ) { $this->queries[] = $query; return $query; }
    public function esc_like( $value ) { return $value; }
    public function get_var( $query ) { return array_shift( $this->values ); }
}
$GLOBALS['wpdb'] = new Delivery_Scope_Probe(); $GLOBALS['wpdb']->values = [ 'wp_nova_posting_jobs', '1' ];
check_delivery( true === Nova_Bridge_Suite_Posting_Jobs::has_unfinished_for_site( 'fixture-site' ), 'Accepted work prevents changing installation scope.' );
check_delivery( false !== strpos( implode( ' ', $GLOBALS['wpdb']->queries ), "state<>'complete'" ), 'Scope guard includes blocked recovery jobs.' );
$GLOBALS['wpdb']->values = [ 'wp_nova_posting_jobs', '0' ];
check_delivery( false === Nova_Bridge_Suite_Posting_Jobs::has_unfinished_for_site( 'fixture-site' ), 'Only completed work permits intentional reconnection.' );
$GLOBALS['wpdb']->values = [ null ];
check_delivery( false === Nova_Bridge_Suite_Posting_Jobs::has_unfinished_for_site( 'fixture-site' ), 'A never-installed journal has no unfinished deliveries.' );
$GLOBALS['wpdb']->last_error = 'fixture database outage'; $GLOBALS['wpdb']->values = [ null ];
check_delivery( is_wp_error( Nova_Bridge_Suite_Posting_Jobs::has_unfinished_for_site( 'fixture-site' ) ), 'Scope guard fails closed on journal lookup error.' );
echo "PASS: {$checks} delivery checks\n";
