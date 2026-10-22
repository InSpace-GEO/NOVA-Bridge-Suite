<?php
/** Separate processes exercise host completion functions and abrupt worker exits. */
define( 'ABSPATH', __DIR__ . '/' );
$mode = $argv[1] ?? 'unsupported'; $checks = 0; $options = []; $option_writes = []; $admin = true; $logged_in = true; $behavior = 'success';
function check( $condition, $message ) { ++$GLOBALS['checks']; if ( ! $condition ) { throw new RuntimeException( $message ); } }
class WP_Error { private $code; private $data; public function __construct( $code, $message = '', $data = [] ) { $this->code = $code; $this->data = $data; } public function get_error_code() { return $this->code; } public function get_error_data() { return $this->data; } }
class WP_REST_Response { public $data; public $status; public function __construct( $data, $status ) { $this->data = $data; $this->status = $status; } public function header( $key, $value ) {} }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function current_user_can( $capability ) { return $GLOBALS['admin']; }
function is_user_logged_in() { return $GLOBALS['logged_in']; }
function get_option( $key, $default = false ) { return $GLOBALS['options'][$key] ?? $default; }
function update_option( $key, $value, $autoload = null ) {
    $GLOBALS['option_writes'][] = [ 'key' => $key, 'value' => $value, 'autoload' => $autoload ];
    if ( ! empty( $GLOBALS['fail_diagnostics'] ) ) { throw new RuntimeException( 'secret-storage-message' ); }
    $GLOBALS['options'][$key] = $value; return true;
}
function flush_fixture() {
    $record = get_option( Nova_Bridge_Suite_Posting_Delivery::LAST_RUN_OPTION );
    check( 'running' === $record['state'] && ! $record['completed'] && null === $record['successful'], 'Running record is durable before host completion is called.' );
    if ( 'fastcgi-throw' === $GLOBALS['mode'] ) { throw new RuntimeException( 'secret-flush-error' ); }
    return $GLOBALS['mode'] === 'fastcgi-false' ? false : ( $GLOBALS['mode'] === 'fastcgi-void' ? null : true );
}
if ( 0 === strpos( $mode, 'fastcgi' ) ) { function fastcgi_finish_request() { return flush_fixture(); } }
elseif ( 'litespeed' === $mode ) { function litespeed_finish_request() { return flush_fixture(); } }
class Nova_Bridge_Suite_Posting_Settings {
    public static $fail_next = false;
    public static function connection() {
        if ( self::$fail_next ) { self::$fail_next = false; throw new RuntimeException( 'secret-correlation-lookup' ); }
        return [ 'enabled' => false, 'site_id' => '11111111-1111-4111-8111-111111111111' ];
    }
}
class Nova_Bridge_Suite_Posting_Jobs {
    public function storage_exists() { return true; }
    public function summaries( $site ) { return [ [ 'id' => 1, 'state' => 'blocked', 'next_attempt' => 0, 'last_error' => 'authentication_required' ], [ 'id' => 2, 'state' => 'ready', 'next_attempt' => 123, 'last_error' => 'nova_posting_transport' ] ]; }
}
class Nova_Bridge_Suite_Posting_Worker {
    public function __construct( $connection ) {}
    public function run( $observe ) {
        $record = get_option( Nova_Bridge_Suite_Posting_Delivery::LAST_RUN_OPTION );
        if ( empty( $GLOBALS['fail_diagnostics'] ) ) { check( 'running' === $record['state'] && 0 === $record['jobs_claimed'], 'Worker starts with an unfinished zero-claim record.' ); }
        $job = [ 'id' => 12, 'content_id' => '12345678901234567890', 'version' => 4, 'delivery_id' => '22222222-2222-4222-8222-222222222222', 'attempt_id' => 'secret-not-a-uuid', 'state' => 'running', 'phase' => 'applying', 'attempts' => 2, 'next_attempt' => 0, 'last_error' => '', 'payload' => [ 'token' => 'secret-payload-token' ], 'url' => 'https://secret.example/token' ];
        if ( array_key_exists( 'diagnostic_content_id', $GLOBALS ) ) { $job['content_id'] = $GLOBALS['diagnostic_content_id']; }
        $observe( [ 'jobs_claimed' => 1, 'last_job' => $job ] );
        if ( 'abort' === $GLOBALS['mode'] ) { exit( 0 ); }
        if ( 'throw' === $GLOBALS['behavior'] ) { throw new RuntimeException( 'secret-exception https://secret.example/token' ); }
        if ( 'newer' === $GLOBALS['behavior'] ) {
            $newer = get_option( Nova_Bridge_Suite_Posting_Delivery::LAST_RUN_OPTION ); $newer['run_id'] = 'newer-fixture-run'; $newer['jobs_claimed'] = 0;
            update_option( Nova_Bridge_Suite_Posting_Delivery::LAST_RUN_OPTION, $newer, false );
        }
        $job['state'] = 'ready'; $job['next_attempt'] = 1234567890; $job['last_error'] = 'secret_plain_code';
        $observe( [ 'last_job' => $job, 'last_error' => 'https://secret.example/token', 'discovery_http_status' => 503, 'token' => 'secret-top-level-token' ] );
    }
}
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-posting-delivery.php';
function run_fixture() {
    $queued = new ReflectionProperty( Nova_Bridge_Suite_Posting_Delivery::class, 'run_queued' ); if ( PHP_VERSION_ID < 80100 ) { $queued->setAccessible( true ); } $queued->setValue( null, true );
    Nova_Bridge_Suite_Posting_Delivery::run_queued();
}
if ( 'fastcgi-throw' === $mode ) {
    try { run_fixture(); throw new RuntimeException( 'Expected fixture completion exception' ); } catch ( RuntimeException $error ) { check( 'secret-flush-error' === $error->getMessage(), 'Original host completion exception is preserved.' ); }
    $record = get_option( Nova_Bridge_Suite_Posting_Delivery::LAST_RUN_OPTION );
    check( 'interrupted' === $record['state'] && 'fastcgi' === $record['response_completion_method'] && ! $record['response_completion_returned'] && null === $record['response_completion_result'] && null === $record['response_completion_elapsed_ms'] && 0 === $record['jobs_claimed'], 'A throwing host completion call is not reported as completed and starts no worker.' );
    echo 'PASS ' . $checks . " failed host-completion diagnostic checks.\n"; exit( 0 );
}
if ( 'abort' === $mode ) {
    register_shutdown_function( static function () {
        $record = get_option( Nova_Bridge_Suite_Posting_Delivery::LAST_RUN_OPTION );
        check( 'running' === $record['state'] && ! $record['completed'] && null === $record['successful'] && null === $record['duration_ms'] && 1 === $record['jobs_claimed'], 'Abrupt exit retains an unfinished record and the actual claim count; success is unknown.' );
        check( 'applying' === $record['last_job']['phase'] && ! isset( $record['last_job']['payload'], $record['last_job']['url'] ), 'Interrupted record retains only bounded job identity and state.' );
        echo 'PASS ' . $GLOBALS['checks'] . " abrupt-exit diagnostic checks.\n";
    } );
    run_fixture();
}
run_fixture();
$record = get_option( Nova_Bridge_Suite_Posting_Delivery::LAST_RUN_OPTION );
$expected = 0 === strpos( $mode, 'fastcgi' ) ? 'fastcgi' : $mode;
check( $expected === $record['response_completion_method'], 'Actual host completion method is recorded.' );
check( ( 'unsupported' !== $mode ) === $record['response_completion_returned'], 'Unsupported completion is not presented as a returned completion call.' );
check( ( in_array( $mode, [ 'unsupported', 'fastcgi-void' ], true ) ? null : 'fastcgi-false' !== $mode ) === $record['response_completion_result'], 'Only a literal boolean completion return is reported as known.' );
check( 'unsupported' === $mode ? null === $record['response_completion_elapsed_ms'] : is_int( $record['response_completion_elapsed_ms'] ), 'Completion timing is local elapsed time only when the call returned.' );
check( 'finished' === $record['state'] && $record['completed'] && false === $record['successful'] && is_int( $record['duration_ms'] ) && 1 === $record['jobs_claimed'], 'A returned batch is distinct from successful job processing.' );
check( 'unclassified_error' === $record['last_error'] && 'unclassified_error' === $record['last_job']['error_code'] && 503 === $record['discovery_http_status'], 'Unknown error codes collapse to fixed safe diagnostics while discovery HTTP status remains useful.' );
check( '11111111-1111-4111-8111-111111111111' === $record['site_id'] && '22222222-2222-4222-8222-222222222222' === $record['last_job']['delivery_id'] && null === $record['last_job']['attempt_id'], 'Correlation retains only valid site/delivery/attempt UUIDs.' );
check( '12345678901234567890' === $record['last_job']['content_id'] && 1234567890 === $record['last_job']['next_attempt'] && 'new_wake_or_manual_catch_up_after_due' === $record['last_job']['recovery_action'], 'Retry diagnostics correlate native work and retain its manual catch-up requirement.' );
check( false === strpos( json_encode( $record ), 'secret' ) && strlen( json_encode( $record ) ) < 2000, 'Payloads, URLs, tokens, raw error text and unknown plain codes never enter the bounded option.' );
check( [] === array_filter( $option_writes, static function ( $write ) { return false !== $write['autoload']; } ), 'Last-run diagnostics never request autoload.' );
$status = Nova_Bridge_Suite_Posting_Delivery::status( null );
check( $record === $status->data['last_run'] && null === $status->data['next_scheduled'] && 'explicit_resume' === $status->data['jobs'][0]['recovery_action'] && 'new_wake_or_manual_catch_up_after_due' === $status->data['jobs'][1]['recovery_action'], 'Existing administrator status exposes diagnostics and per-job recovery without adding a scheduler.' );
$admin = false; $logged_in = false;
check( 401 === Nova_Bridge_Suite_Posting_Delivery::status( null )->get_error_data()['status'], 'Anonymous callers cannot inspect last-run diagnostics.' );
$logged_in = true;
check( 403 === Nova_Bridge_Suite_Posting_Delivery::status( null )->get_error_data()['status'], 'Non-administrators cannot inspect last-run diagnostics.' );
$admin = true; $behavior = 'throw';
try { run_fixture(); throw new RuntimeException( 'Expected fixture interruption' ); } catch ( RuntimeException $error ) { check( 0 === strpos( $error->getMessage(), 'secret-exception' ), 'Original exception still propagates without logging its text.' ); }
$record = get_option( Nova_Bridge_Suite_Posting_Delivery::LAST_RUN_OPTION );
check( 'interrupted' === $record['state'] && ! $record['completed'] && false === $record['successful'] && 'runner_exception' === $record['last_error'] && 1 === $record['jobs_claimed'] && false === strpos( json_encode( $record ), 'secret' ), 'Thrown worker failure records interruption without fabricating completion or leaking messages.' );
$behavior = 'newer'; run_fixture();
check( 'newer-fixture-run' === get_option( Nova_Bridge_Suite_Posting_Delivery::LAST_RUN_OPTION )['run_id'] && 'running' === get_option( Nova_Bridge_Suite_Posting_Delivery::LAST_RUN_OPTION )['state'], 'An older request does not replace a newer run already observed by its option read; this is not an atomic concurrency guarantee.' );
$behavior = 'success'; $fail_diagnostics = true;
// Disable only the fixture assertion in completion: persistence failure must still reach the worker.
$mode_before_failure = $mode;
if ( 'unsupported' === $mode ) { run_fixture(); check( true, 'Diagnostic persistence failure cannot stop worker processing.' ); }
$fail_diagnostics = false; Nova_Bridge_Suite_Posting_Settings::$fail_next = true; run_fixture();
check( null === get_option( Nova_Bridge_Suite_Posting_Delivery::LAST_RUN_OPTION )['site_id'] && get_option( Nova_Bridge_Suite_Posting_Delivery::LAST_RUN_OPTION )['completed'], 'A failed diagnostic identity lookup leaves null correlation and does not stop the worker or its cleanup.' );
if ( 'unsupported' === $mode ) {
    foreach ( [
        [ 'f8ea26b3-39f0-478a-8c2b-f18309c23567', 'f8ea26b3-39f0-478a-8c2b-f18309c23567' ],
        [ 'C6A80E8A-873E-4F2D-8D67-CC1394CF399D', 'c6a80e8a-873e-4f2d-8d67-cc1394cf399d' ],
        [ str_repeat( '9', 64 ), str_repeat( '9', 64 ) ],
        [ str_repeat( '9', 65 ), null ],
        [ 'https://secret.example/token', null ],
        [ "f8ea26b3-39f0-478a-8c2b-f18309c23567\nsecret", null ],
        [ 'g8ea26b3-39f0-478a-8c2b-f18309c23567', null ],
        [ [ 'token' => 'secret' ], null ],
        [ null, null ],
    ] as [ $diagnostic_content_id, $expected_content_id ] ) {
        run_fixture();
        $record = get_option( Nova_Bridge_Suite_Posting_Delivery::LAST_RUN_OPTION );
        check( $expected_content_id === $record['last_job']['content_id'] && false === strpos( json_encode( $record ), 'secret' ), 'Journal content identity accepts only UUIDs or bounded legacy decimal IDs and redacts all other values.' );
    }
}
echo 'PASS ' . $checks . ' runner diagnostic checks (' . $mode_before_failure . ").\n";
