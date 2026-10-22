<?php
/** Signed wake -> production discovery/client/worker, with an in-memory CMS and journal. */
require __DIR__ . '/delivery-unit.php';
$checks = 0;
class WP_REST_Response {
    public $data; public $status;
    public function __construct( $data, $status ) { $this->data = $data; $this->status = $status; }
    public function header( $name, $value ) {}
}
class Wake_Request {
    public $body; public $headers;
    public function get_body() { return $this->body; }
    public function get_header( $name ) { return $this->headers[$name] ?? ''; }
    public function get_param( $name ) { return 1; }
}
function current_user_can( $cap ) { return true; }
function is_user_logged_in() { return true; }
function wp_verify_nonce( $nonce, $action ) { return 'fixture-nonce' === $nonce && 'wp_rest' === $action; }
function get_option( $key, $fallback = false ) { return $GLOBALS['wake_options'][$key] ?? $fallback; }
function update_option( $key, $value, ...$args ) { $GLOBALS['wake_options'][$key] = $value; return true; }
function delete_option( $key ) { unset( $GLOBALS['wake_options'][$key] ); }
function wp_next_scheduled( $hook ) { return false; }
class Nova_Bridge_Suite_Posting_Settings {
    public static $connection;
    public static function connection() { return self::$connection; }
}
class Nova_Bridge_Suite_Mapping_Sync {
    public static function configuration_for_snapshot( $snapshot, $site ) { return $GLOBALS['wake_fixture']['configuration']; }
    public static function frozen_policy( $client, $snapshot, $configuration ) {
        return [ 'reference' => [ 'reference_type' => 'post', 'reference_id' => 77 ], 'routing' => [ 'operation' => 'update', 'publication' => 'publish' ] ];
    }
}
class Nova_Bridge_Suite_Mapped_Writer extends Contract_Test_Writer {}
class Wake_Store extends Contract_Test_Store {
    public $state = [ 'cursor' => null, 'cycle_started' => 0, 'last_completed' => 0, 'next_attempt' => 0, 'consumed_sequence' => 0, 'wake_sequence' => 0, 'failures' => 0, 'last_error' => '', 'connection_key' => '', 'suspended' => false, 'auth_status' => 0 ];
    public $row; public $claims = 0; public $pruned = 0;
    public function lock( $key ) { if ( isset( $this->locks[$key] ) ) { return new WP_Error( 'fixture_busy' ); } return parent::lock( $key ); }
    public function request_discovery( $site ) { ++$this->state['wake_sequence']; return true; }
    public function discovery_state( $site ) { return $this->state; }
    public function save_discovery_state( $site, $state ) { $state['wake_sequence'] = $this->state['wake_sequence']; $this->state = $state; return true; }
    public function enqueue_discovered( $site, $item, $checkpoint ) {
        if ( ! $this->row ) { $this->row = array_merge( $GLOBALS['wake_fixture']['job'], [ 'state' => 'ready', 'lease_until' => 0, 'attempts' => 0 ] ); }
        return $this->row;
    }
    public function claim( $site, $paused ) {
        $job = $this->row;
        if ( ! $job || ! in_array( $job['state'], [ 'ready', 'running' ], true ) || $job['next_attempt'] > time() || $job['lease_until'] >= time() ) { return null; }
        if ( $paused && ! in_array( $job['phase'], [ 'applying', 'committed', 'event_pending' ], true ) ) { return null; }
        ++$this->claims; ++$job['attempts']; $job['state'] = 'running'; $job['lease_until'] = time() + 300;
        return $this->row = $job;
    }
    public function save( $job, $changes ) {
        $saved = parent::save( $job, $changes );
        if ( ! is_wp_error( $saved ) ) { if ( 'running' !== $saved['state'] ) { $saved['lease_until'] = 0; } $this->row = $saved; }
        return $saved;
    }
    public function resume( $id, $site ) {
        if ( ! $this->row || 'blocked' !== $this->row['state'] ) { return new WP_Error( 'fixture_not_blocked' ); }
        $this->row = array_merge( $this->row, [ 'state' => 'ready', 'attempts' => 0, 'next_attempt' => 0, 'last_error' => '' ] ); return true;
    }
    public function prune() { ++$this->pruned; }
}
class Nova_Bridge_Suite_Posting_Jobs {
    public function __call( $name, $args ) { return call_user_func_array( [ $GLOBALS['wake_store'], $name ], $args ); }
}
class Wake_Server extends Contract_Test_Server {
    public $discovery_status = 200; public $on_discovery; public $pages = 0;
    public function request( $url, $args ) {
        if ( false === strpos( $url, '/deliveries?' ) ) { return parent::request( $url, $args ); }
        ++$this->pages;
        if ( $this->on_discovery ) { $callback = $this->on_discovery; $this->on_discovery = null; $callback(); }
        if ( 200 !== $this->discovery_status ) { return self::reply( $this->discovery_status, '{"error":{"code":"fixture_discovery_error"}}' ); }
        $items = false === strpos( $url, '&cursor=' ) ? [ $this->fixture['item'] ] : [];
        return self::reply( 200, json_encode( [ 'items' => $items, 'next_cursor' => '1' ] ) );
    }
}
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-posting-delivery.php';
function wake_fixture( array $patch = [] ) {
    Contract_Test_Writer::reset();
    $f = nova_contract_fixture(); $f['connection'] = array_merge( $f['connection'], $patch );
    $GLOBALS['wake_fixture'] = $f; $GLOBALS['wake_store'] = $store = new Wake_Store(); $GLOBALS['server'] = $server = new Wake_Server( $f );
    $GLOBALS['wake_options'] = [ 'nova_bridge_posting_schema_version' => 3 ];
    Nova_Bridge_Suite_Posting_Settings::$connection = $f['connection'];
    $request = new Wake_Request();
    $request->body = json_encode( [ 'event' => 'content.ready', 'content_id' => $f['snapshot']['content_item_id'], 'site' => $f['snapshot']['site_id'], 'version' => $f['snapshot']['version_number'] ] );
    $timestamp = (string) time();
    $request->headers = [ 'x-nova-timestamp' => $timestamp, 'x-nova-signature' => 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $request->body, $f['connection']['webhook_secret'] ), 'x-wp-nonce' => 'fixture-nonce' ];
    return [ $store, $server, $request ];
}
function wake_run( $request ) {
    $answer = Nova_Bridge_Suite_Posting_Delivery::ready( $request );
    delivery_check( ! is_wp_error( $answer ) && 202 === $answer->status, 'Signed wake is acknowledged.' );
    Nova_Bridge_Suite_Posting_Delivery::run_queued();
}

[ $store, $server, $request ] = wake_fixture();
$answer = Nova_Bridge_Suite_Posting_Delivery::ready( $request );
delivery_check( 202 === $answer->status && 1 === $store->state['wake_sequence'] && ! $store->row && [] === $server->calls && 0 === Contract_Test_Writer::$apply, '202 accepts a wake before discovery, receipt or native write; it does not confirm publication.' );
// Simulate PHP dying before its shutdown hook: persisted storage survives; request statics do not.
$queued = new ReflectionProperty( Nova_Bridge_Suite_Posting_Delivery::class, 'run_queued' );
if ( PHP_VERSION_ID < 80100 ) { $queued->setAccessible( true ); }
$queued->setValue( null, false );
wake_run( $request );
delivery_check( 'complete' === $store->row['state'] && 1 === Contract_Test_Writer::$apply, 'An explicitly supplied second notification recovers a lost shutdown and publishes once.' );
$posts = $server->posts; wake_run( $request );
delivery_check( $posts === $server->posts && 1 === Contract_Test_Writer::$apply, 'Repeated same-content notification cannot replay a completed CMS write or produce new receipts.' );

[ $store, $server, $request ] = wake_fixture();
$server->on_discovery = static function () use ( $request ) { Nova_Bridge_Suite_Posting_Delivery::ready( $request ); Nova_Bridge_Suite_Posting_Delivery::run_queued(); };
wake_run( $request );
delivery_check( 2 === $store->state['wake_sequence'] && 1 === $store->state['consumed_sequence'] && 1 === $store->claims && 1 === Contract_Test_Writer::$apply, 'A concurrent wake survives discovery save; recursive runner entry cannot create a second write.' );
wake_run( $request );
delivery_check( $store->state['wake_sequence'] === $store->state['consumed_sequence'] && 1 === Contract_Test_Writer::$apply, 'An explicitly supplied later notification consumes outstanding wake without repeating publication.' );

[ $store, $server, $request ] = wake_fixture(); $server->lose = 'publication_succeeded';
wake_run( $request ); $pending = $store->row['payload']['event_bytes']; $attempts = $store->row['attempts']; $due = $store->row['next_attempt'];
delivery_check( 'event_pending' === $store->row['phase'] && 'ready' === $store->row['state'] && $due > time(), 'Lost final receipt acknowledgement retains exact bytes and local back-off.' );
wake_run( $request );
delivery_check( $attempts === $store->row['attempts'] && $due === $store->row['next_attempt'], 'A notification before the retry is due does not reset back-off or consume attempts.' );
$store->row['next_attempt'] = time() - 1; wake_run( $request );
delivery_check( 'complete' === $store->row['state'] && 1 === Contract_Test_Writer::$apply && 2 === count( array_filter( $server->posts, static function ( $bytes ) use ( $pending ) { return $bytes === $pending; } ) ), 'A later wake replays the final receipt byte-for-byte without another write.' );

[ $store, $server, $request ] = wake_fixture(); $server->lose = 'publication_succeeded';
Nova_Bridge_Suite_Posting_Delivery::initialize(); Nova_Bridge_Suite_Posting_Delivery::run_queued();
$pending = $store->row['payload']['event_bytes']; $store->row['next_attempt'] = time() - 1;
Nova_Bridge_Suite_Posting_Settings::$connection['token'] = 'rotated-synthetic-test-token';
Nova_Bridge_Suite_Posting_Settings::$connection['paused'] = true;
Nova_Bridge_Suite_Posting_Delivery::initialize(); Nova_Bridge_Suite_Posting_Delivery::run_queued();
delivery_check( 'complete' === $store->row['state'] && 1 === Contract_Test_Writer::$apply && 2 === count( array_filter( $server->posts, static function ( $bytes ) use ( $pending ) { return $bytes === $pending; } ) ), 'Explicit reconnect catch-up can clear an already accepted final receipt journal while new writes are paused.' );

[ $store, $server, $request ] = wake_fixture( [ 'paused' => true ] ); wake_run( $request );
delivery_check( 'accepted' === $store->row['phase'] && 0 === $store->claims && 0 === Contract_Test_Writer::$apply, 'Paused wake discovers work but never starts a new native write.' );
Nova_Bridge_Suite_Posting_Settings::$connection['paused'] = false; wake_run( $request );
delivery_check( 'complete' === $store->row['state'] && 1 === Contract_Test_Writer::$apply, 'A notification after unpausing processes the retained job.' );

[ $store, $server, $request ] = wake_fixture(); Contract_Test_Writer::$status = 'draft'; wake_run( $request );
delivery_check( 'complete' === $store->row['state'] && 'draft' === $store->row['payload']['result']['cms_post_status'] && 2 === count( $server->posts ), 'A verified draft immediately completes through the existing success receipt.' );
$posts = $server->posts; Contract_Test_Writer::$status = 'publish'; wake_run( $request );
delivery_check( $posts === $server->posts && 1 === Contract_Test_Writer::$apply, 'A later status change and duplicate wake cannot repeat a completed draft save or receipt.' );

[ $store, $server, $request ] = wake_fixture(); Contract_Test_Writer::$status = 'future'; Contract_Test_Writer::$verify_error = new WP_Error( 'fixture_verification_failed' );
Nova_Bridge_Suite_Posting_Delivery::initialize(); Nova_Bridge_Suite_Posting_Delivery::run_queued();
delivery_check( 'committed' === $store->row['phase'] && 1 === count( $server->posts ), 'Unverified scheduled save retains recovery evidence without a success receipt.' );
Contract_Test_Writer::$verify_error = null; $store->row['last_error'] = 'awaiting_publication'; $store->row['next_attempt'] = time() - 1;
Nova_Bridge_Suite_Posting_Settings::$connection['token'] = 'rotated-synthetic-test-token'; Nova_Bridge_Suite_Posting_Settings::$connection['paused'] = true;
Nova_Bridge_Suite_Posting_Delivery::initialize(); Nova_Bridge_Suite_Posting_Delivery::run_queued();
delivery_check( 'complete' === $store->row['state'] && 1 === Contract_Test_Writer::$apply && 2 === count( $server->posts ) && 'future' === $store->row['payload']['result']['cms_post_status'], 'Explicit reconnect after back-off completes a legacy awaiting-publication job while new writes are paused.' );

[ $store, $server, $request ] = wake_fixture(); $server->read_status = 401; wake_run( $request );
delivery_check( 'blocked' === $store->row['state'] && $store->state['suspended'], 'Authentication failure suspends discovery and retains the blocked job.' );
$server->read_status = 200; $claims = $store->claims; wake_run( $request );
delivery_check( $claims === $store->claims && 'blocked' === $store->row['state'], 'Notification cannot reset auth suspension or resume a manually blocked job.' );
Nova_Bridge_Suite_Posting_Delivery::retry_discovery( $request ); Nova_Bridge_Suite_Posting_Delivery::run_queued();
delivery_check( ! $store->state['suspended'] && 'blocked' === $store->row['state'], 'Explicit access recheck invokes discovery but preserves manual block policy.' );
Nova_Bridge_Suite_Posting_Delivery::resume( $request ); Nova_Bridge_Suite_Posting_Delivery::run_queued();
delivery_check( 'complete' === $store->row['state'] && 1 === Contract_Test_Writer::$apply, 'Explicit blocked-job resume actually runs the retained phase without cron.' );

[ $store, $server, $request ] = wake_fixture(); $server->discovery_status = 503; wake_run( $request );
$due = $store->state['next_attempt']; $pages = $server->pages; $server->discovery_status = 200; wake_run( $request );
delivery_check( $due > time() && $due === $store->state['next_attempt'] && $pages === $server->pages, 'Repeated wake cannot bypass discovery back-off.' );
$store->state['next_attempt'] = time() - 1; wake_run( $request );
delivery_check( 'complete' === $store->row['state'] && 1 === Contract_Test_Writer::$apply, 'An explicitly supplied notification after back-off recovers failed discovery.' );

[ $store, $server, $request ] = wake_fixture();
$store->row = $GLOBALS['wake_fixture']['job']; $store->row['lease_until'] = time() + 300;
wake_run( $request );
delivery_check( 0 === $store->claims && 0 === Contract_Test_Writer::$apply, 'Another live lease prevents a concurrent notification from processing its job.' );
$store->row['lease_until'] = time() - 1; wake_run( $request );
delivery_check( 'complete' === $store->row['state'] && 1 === Contract_Test_Writer::$apply, 'An explicitly supplied later notification can recover an expired processing lease.' );
echo 'PASS ' . $checks . " webhook-driven worker checks.\n";
