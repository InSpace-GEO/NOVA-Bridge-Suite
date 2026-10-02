<?php
/** Offline REST boundary checks: real signed intake/discovery, in-memory WordPress storage. */
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
    private $code; private $data;
    public function __construct( $code, $message = '', $data = [] ) { $this->code = $code; $this->data = $data; }
    public function get_error_code() { return $this->code; }
    public function get_error_data() { return $this->data; }
}
class WP_REST_Response {
    public $data; public $status; public $headers = [];
    public function __construct( $data, $status ) { $this->data = $data; $this->status = $status; }
    public function header( $name, $value ) { $this->headers[$name] = $value; }
}
class Intake_Request {
    public $body = ''; public $headers = [];
    public function get_body() { return $this->body; }
    public function get_header( $name ) { return $this->headers[$name] ?? ''; }
    public function get_param( $name ) { return null; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function current_user_can( $cap ) { return $GLOBALS['admin'] && 'manage_options' === $cap; }
function is_user_logged_in() { return $GLOBALS['logged_in']; }
function wp_verify_nonce( $nonce, $action ) { return 'valid-fixture-nonce' === $nonce && 'wp_rest' === $action; }
function get_option( $name, $default = false ) { return $GLOBALS['options'][$name] ?? $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['options'][$name] = $value; return true; }
function wp_next_scheduled( $hook ) { return false; }
function wp_schedule_event() { ++$GLOBALS['scheduled']; }
function register_rest_route( $namespace, $path, $route ) { $GLOBALS['routes'][$path] = $route; }
class Nova_Bridge_Suite_Posting_Settings {
    public static $connection = [];
    public static function connection() { return self::$connection; }
}
class Nova_Bridge_Suite_Posting_Client {
    public function __construct( $connection ) {}
    public function site_request() { throw new RuntimeException( 'Intake/status/recheck must never make HTTP requests.' ); }
}
class Nova_Bridge_Suite_Posting_Jobs {
    public static $exists = false; public static $installs = 0; public static $reads = 0; public static $wakes = 0;
    public static $state = [ 'cursor' => 'private-cursor', 'connection_key' => 'private-key', 'suspended' => true, 'auth_status' => 403, 'last_error' => 'site_disabled', 'next_attempt' => 900 ];
    public function lock( $key ) { return true; }
    public function unlock( $key ) {}
    public function install() { ++self::$installs; self::$exists = true; return true; }
    public function storage_exists() { return self::$exists; }
    public function summaries( $site ) { ++self::$reads; if ( ! self::$exists ) { throw new RuntimeException( 'Missing journal must not be queried.' ); } return []; }
    public function discovery_state( $site ) { return self::$state; }
    public function save_discovery_state( $site, $state ) { self::$state = $state; return true; }
    public function request_discovery( $site ) { ++self::$wakes; return true; }
}
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-posting-protocol.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-posting-discovery.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-posting-delivery.php';
$checks = 0; $admin = true; $logged_in = true; $options = []; $scheduled = 0; $routes = [];
function intake_check( $condition, $message ) { ++$GLOBALS['checks']; if ( ! $condition ) { throw new RuntimeException( $message ); } }
$request = new Intake_Request();
Nova_Bridge_Suite_Posting_Settings::$connection = [ 'enabled' => false, 'paused' => true, 'site_id' => '', 'base_url' => '', 'token' => '' ];
Nova_Bridge_Suite_Posting_Delivery::schedule();
$status = Nova_Bridge_Suite_Posting_Delivery::status( $request );
intake_check( 200 === $status->status && [] === $status->data['jobs'] && [] === $status->data['discovery'], 'Disabled unconfigured installation returns empty status without a schema.' );
intake_check( 0 === Nova_Bridge_Suite_Posting_Jobs::$installs && 0 === Nova_Bridge_Suite_Posting_Jobs::$reads && 0 === $scheduled, 'Disabled status and schedule do not install, query missing journals or schedule work.' );
intake_check( 'private, no-store' === $status->headers['Cache-Control'], 'Administrator status is not cacheable.' );
$admin = false; $logged_in = false;
intake_check( 401 === Nova_Bridge_Suite_Posting_Delivery::status( $request )->get_error_data()['status'], 'Anonymous users cannot inspect delivery status.' );
$logged_in = true;
intake_check( 403 === Nova_Bridge_Suite_Posting_Delivery::can_retry_discovery( $request )->get_error_data()['status'], 'Signed-in non-administrators cannot reset discovery authorization.' );
$admin = true;
intake_check( 403 === Nova_Bridge_Suite_Posting_Delivery::retry_discovery( $request )->get_error_data()['status'], 'Administrator access alone does not replace the recheck nonce.' );
$request->headers['x-wp-nonce'] = 'valid-fixture-nonce';
intake_check( 503 === Nova_Bridge_Suite_Posting_Delivery::retry_discovery( $request )->get_error_data()['status'], 'Recheck cannot enable a disabled connection.' );
$connection = [ 'enabled' => true, 'paused' => true, 'site_id' => '11111111-1111-4111-8111-111111111111', 'base_url' => 'https://fixture.invalid', 'token' => 'fixture-token', 'webhook_secret' => 'fixture-signing-secret' ];
Nova_Bridge_Suite_Posting_Settings::$connection = $connection;
$status = Nova_Bridge_Suite_Posting_Delivery::status( $request );
intake_check( 1 === Nova_Bridge_Suite_Posting_Jobs::$installs && 403 === $status->data['discovery']['auth_status'] && 'site_disabled' === $status->data['discovery']['last_error'], 'Enabled schema upgrades once and exposes the actual discovery failure.' );
intake_check( ! isset( $status->data['discovery']['cursor'], $status->data['discovery']['connection_key'] ), 'Status does not expose private cursor or credential fingerprint.' );
$rechecked = Nova_Bridge_Suite_Posting_Delivery::retry_discovery( $request );
intake_check( 200 === $rechecked->status && $rechecked->data['recheck_scheduled'] && ! Nova_Bridge_Suite_Posting_Jobs::$state['suspended'], 'Nonce-authenticated administrator can schedule same-credential reauthentication without HTTP.' );
intake_check( is_wp_error( Nova_Bridge_Suite_Posting_Delivery::retry_discovery( $request ) ), 'Recheck applies only to authorization suspension.' );
$request->body = json_encode( [ 'event' => 'content.ready', 'content_id' => '123', 'site' => $connection['site_id'], 'version' => 1 ] );
$timestamp = (string) time(); $request->headers['x-nova-timestamp'] = $timestamp;
$request->headers['x-nova-signature'] = 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $request->body, $connection['webhook_secret'] );
$accepted = Nova_Bridge_Suite_Posting_Delivery::ready( $request );
intake_check( 202 === $accepted->status && $accepted->data['wake_accepted'] && 1 === Nova_Bridge_Suite_Posting_Jobs::$wakes, 'Paused connection accepts a signed event solely as a durable discovery wake.' );
intake_check( ! isset( $accepted->data['job_id'] ) && 1 === Nova_Bridge_Suite_Posting_Jobs::$installs, 'Hint intake neither claims a content job nor repeats schema upgrade.' );
$request->body .= ' ';
intake_check( 401 === Nova_Bridge_Suite_Posting_Delivery::ready( $request )->get_error_data()['status'] && 1 === Nova_Bridge_Suite_Posting_Jobs::$wakes, 'Invalid raw-body signature cannot create a wake.' );
$request->body = rtrim( $request->body ); Nova_Bridge_Suite_Posting_Settings::$connection['enabled'] = false;
intake_check( 503 === Nova_Bridge_Suite_Posting_Delivery::ready( $request )->get_error_data()['status'] && 1 === Nova_Bridge_Suite_Posting_Jobs::$wakes, 'Signed notification cannot activate a disabled connection.' );
Nova_Bridge_Suite_Posting_Delivery::register_routes();
intake_check( 'POST' === $routes['/posting/discovery/retry']['methods'] && [ 'Nova_Bridge_Suite_Posting_Delivery', 'can_retry_discovery' ] === $routes['/posting/discovery/retry']['permission_callback'], 'Authorization recheck is a protected POST route.' );
echo 'PASS ' . $checks . " contracted intake and status checks.\n";
