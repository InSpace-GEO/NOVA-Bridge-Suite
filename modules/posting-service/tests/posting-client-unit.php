<?php
/** Offline HTTP transport checks. The WordPress transport is an in-memory response queue. */
define( 'ABSPATH', __DIR__ ); define( 'NOVA_BRIDGE_SUITE_VERSION', '3.0.0' );
class WP_Error {
    private $code; private $message; private $data;
    public function __construct( $code, $message = '', $data = [] ) { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_code() { return $this->code; } public function get_error_data() { return $this->data; } public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_parse_url( $value ) { return parse_url( $value ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_safe_remote_request( $url, $args ) { $GLOBALS['calls'][] = [ $url, $args ]; if ( ! $GLOBALS['responses'] ) { throw new RuntimeException( 'Unexpected HTTP request.' ); } return array_shift( $GLOBALS['responses'] ); }
function wp_remote_retrieve_body( $value ) { return $value['body']; }
function wp_remote_retrieve_response_code( $value ) { return $value['response']['code']; }
function wp_remote_retrieve_header( $value, $name ) { return $value['headers'][strtolower($name)] ?? ''; }
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-posting-client.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-posting-protocol.php';
require __DIR__ . '/contract-fixture.php';
$checks = 0; $calls = []; $responses = [];
function client_check( $ok, $message ) { ++$GLOBALS['checks']; if ( ! $ok ) { throw new RuntimeException( $message ); } }
function client_response( $status, $body, $headers = [] ) { return [ 'response' => [ 'code' => $status ], 'body' => $body, 'headers' => $headers ]; }
$fixture = nova_contract_fixture(); $client = new Nova_Bridge_Suite_Posting_Client( $fixture['connection'] ); $suffix = '/deliveries/' . $fixture['snapshot']['id'];
$responses[] = client_response( 200, $fixture['raw'], [ 'etag' => $fixture['etag'], 'x-nova-attempt-id' => $fixture['attempt_id'] ] );
$response = $client->site_request( 'GET', $suffix );
client_check( $response['raw_body'] === $fixture['raw'] && $response['etag'] === $fixture['etag'] && $response['attempt_id'] === $fixture['attempt_id'], 'Transport preserves exact body, strong ETag and current attempt header.' );
client_check( $fixture['snapshot'] === Nova_Bridge_Suite_Posting_Protocol::snapshot( $response, $fixture['job'] ), 'Real client response passes exact protocol intake.' );
$request = $calls[0];
client_check( $request[0] === $fixture['connection']['base_url'] . '/v1/sites/' . $fixture['connection']['site_id'] . $suffix && 'Bearer ' . $fixture['connection']['token'] === $request[1]['headers']['Authorization'], 'Site token addresses only configured origin and site.' );
client_check( 0 === $request[1]['redirection'] && true === $request[1]['sslverify'] && true === $request[1]['reject_unsafe_urls'], 'Credential-bearing requests keep TLS and no redirects.' );
$event = nova_contract_fixture_event( $fixture ); $bytes = json_encode( $event, JSON_PRETTY_PRINT );
$ack = nova_contract_fixture_event_accept( $bytes );
$responses[] = client_response( 202, json_encode( $ack ) ); $response = $client->site_request_bytes( 'POST', $suffix . '/events', $bytes );
client_check( $bytes === end($calls)[1]['body'] && $ack === Nova_Bridge_Suite_Posting_Protocol::accepted( $response, $event ), 'Durable event bytes sent unchanged and202 remains pending.' );
$responses[] = client_response( 202, json_encode( $ack ) ); $client->site_request_bytes( 'POST', $suffix . '/events', $bytes );
client_check( $calls[1][1]['body'] === $calls[2][1]['body'], 'Lost-ack retry sends identical request bytes.' );
foreach ( [ "\r\n", str_repeat('a', 600), [ $fixture['attempt_id'], $fixture['attempt_id'] ] ] as $invalid ) {
    $responses[] = client_response( 200, $fixture['raw'], [ 'etag' => $fixture['etag'], 'x-nova-attempt-id' => $invalid ] );
    $response = $client->site_request( 'GET', $suffix ); client_check( '' === $response['attempt_id'] && is_wp_error( Nova_Bridge_Suite_Posting_Protocol::snapshot( $response, $fixture['job'] ) ), 'Invalid or duplicate identity header cannot be normalized into authority.' );
}
foreach ( [ 401, 403, 429, 503 ] as $status ) {
    $responses[] = client_response( $status, '<html>gateway error, confidential detail</html>', [ 'retry-after' => '172800' ] ); $error = $client->site_request( 'GET', '/deliveries?limit=100' );
    client_check( is_wp_error($error) && $status === $error->get_error_data()['status'] && '172800' === $error->get_error_data()['retry_after'] && false === strpos($error->get_error_message(),'confidential'), 'Non-JSON error retains suspension/backoff status without exposing response: ' . $status );
}
$responses[] = client_response( 409, '{"error":{"code":"conflict","message":"secret remote detail"}}' ); $error = $client->site_request( 'GET', $suffix );
client_check( 'nova_posting_conflict' === $error->get_error_code() && false === strpos($error->get_error_message(),'secret'), 'Remote error code retained without remote message.' );
$responses[] = client_response( 200, '<html>invalid success</html>' ); client_check( 'nova_posting_response_json' === $client->site_request('GET',$suffix)->get_error_code(), 'Invalid success JSON rejected.' );
$responses[] = client_response( 302, '', [ 'location' => 'https://outside.invalid/' ] ); client_check( 'nova_posting_redirect' === $client->site_request('GET',$suffix)->get_error_code(), 'Redirect never followed.' );
$before = count($calls);
foreach ( [ '//outside.invalid/', '/../sites/other', '/%2e%2e/%2foutside', '/deliveries?x=%0d%0aInjected' ] as $bad ) { client_check( is_wp_error($client->site_request('GET',$bad)), 'Path escape rejected before HTTP.' ); }
client_check( is_wp_error($client->request('GET','/v1/sites/00000000-0000-4000-8000-000000000999/deliveries')) && count($calls) === $before, 'Wrong site never receives configured token.' );
client_check( is_wp_error($client->site_request('GET',$suffix,null,['Authorization'=>'replacement'])) && count($calls) === $before, 'Caller cannot replace auth header.' );
client_check( is_wp_error($client->site_request_bytes('POST',$suffix.'/events','[]')) && count($calls) === $before, 'Event wire body must be an object.' );
$responses[] = client_response( 200, str_repeat('x',1048577) ); client_check( 'nova_posting_response_size' === $client->site_request('GET',$suffix)->get_error_code(), 'Oversized response fails closed without truncation acceptance.' );
echo "PASS {$checks} posting client checks\n";
