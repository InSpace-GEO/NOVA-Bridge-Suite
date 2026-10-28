<?php
/** Editor revision updates use the real adapter with a conditional HTTP service seam. */
require __DIR__ . '/static-category-unit.php';
$checks = 0;
class Static_Editor_Client extends Static_Setup_Client {
    public $requests = []; public $failure = ''; public $conflict = false;
    public $defaults = null; public $defaults_revision = 1; public $default_failure = ''; public $create_failure = false;
    public function site_request( string $method, string $suffix, $body = null, array $headers = [] ) {
        $this->requests[] = [ $method, $suffix, $body, $headers ];
        if ( '/template-defaults' === $suffix ) {
            if ( 'PUT' === $method ) {
                if ( 'before' === $this->default_failure ) { return new WP_Error( 'fixture_transport', 'Offline' ); }
                if ( ( $headers['If-Match'] ?? '' ) !== '"' . $this->defaults_revision . '"' ) { return new WP_Error( 'fixture_conflict', 'Defaults changed' ); }
                $this->defaults = $body['defaults']; ++$this->defaults_revision;
                if ( 'after' === $this->default_failure ) { return new WP_Error( 'fixture_transport', 'Acknowledgement lost' ); }
            }
            return [ 'status' => 200, 'etag' => '"' . $this->defaults_revision . '"', 'body' => [ 'revision' => $this->defaults_revision, 'defaults' => $this->defaults ?? [ 'category' => $this->remote['id'], 'page' => '00000000-0000-4000-8000-000000000999' ] ] ];
        }
        if ( 'POST' === $method ) { $this->lose = $this->create_failure; $reply = parent::site_request( $method, $suffix, $body, $headers ); if ( ! is_wp_error( $reply ) ) { $reply['status'] = 201; } return $reply; }
        if ( 'PUT' === $method ) {
            if ( 'before' === $this->failure ) { return new WP_Error( 'fixture_transport', 'Offline' ); }
            if ( $this->conflict || ( $headers['If-Match'] ?? '' ) !== '"' . $this->remote['revision'] . '"' ) { return new WP_Error( 'fixture_conflict', 'Revision changed' ); }
            $this->remote = $body + [ 'id' => $this->remote['id'], 'revision' => $this->remote['revision'] + 1, 'retired_at' => null ];
            if ( 'after' === $this->failure ) { return new WP_Error( 'fixture_transport', 'Acknowledgement lost' ); }
        }
        return [ 'status' => 200, 'body' => $this->remote, 'etag' => '"' . $this->remote['revision'] . '"' ];
    }
    public function puts() { return array_values( array_filter( $this->requests, static function ( $r ) { return $r[0] === 'PUT'; } ) ); }
}
function editor_fixture() {
    $GLOBALS['options'] = []; $GLOBALS['permissions'] = true;
    Nova_Bridge_Suite_Layout_Recipes::$record = static_record(); $f = nova_contract_fixture(); $f['connection']['enabled'] = true;
    $initial = new Static_Setup_Client( $f['connection'] ); $locks = new Contract_Test_Store();
    $approved = Nova_Bridge_Suite_Static_Recipe_Adapter::sync_and_approve( $f['connection'], static_record()['id'], 2, '5', '6', 'category', null, $initial, $locks );
    delivery_check( ! is_wp_error( $approved ), 'Existing category setup is approved.' );
    $client = new Static_Editor_Client( $f['connection'] ); $client->remote = $initial->remote;
    Nova_Bridge_Suite_Layout_Recipes::$record['revision'] = 4;
    return [ $f['connection'], $client, $locks ];
}
function editor_sync( $c, $client, $locks, bool $saving = false, $load = null ) {
    return Nova_Bridge_Suite_Static_Recipe_Adapter::sync_editor( $c, $load ?: static function () { return Nova_Bridge_Suite_Layout_Recipes::$record; }, $saving, $client, $locks );
}
[ $c, $client, $locks ] = editor_fixture(); $old = $options; $result = editor_sync( $c, $client, $locks, true );
delivery_check( $result['posting_sync']['status'] === 'synced' && $result['posting_sync']['recipe_revision'] === 4 && $result['posting_sync']['template_revision'] === 2 && $result['local_only'] === false, 'Local revision 4 receives remote revision 2 even when scalar fields are unchanged.' );
delivery_check( count( $client->puts() ) === 1 && $client->puts()[0][3]['If-Match'] === '"1"', 'One conditional update targets the existing template.' );
foreach ( $old as $key => $value ) { delivery_check( $options[$key] === $value, 'Older approval and setup options remain immutable.' ); }
$frozen = array_intersect_key( $client->remote, array_flip( [ 'id', 'name', 'page_type', 'definition', 'mapping', 'revision' ] ) );
$f = nova_contract_fixture(); $snapshot = $f['snapshot']; $snapshot['configuration'] = $frozen; $snapshot['page_type_frontend'] = 'category';
$profile = Nova_Bridge_Suite_Static_Recipe_Adapter::configuration_for_snapshot( $snapshot, $client->site_id() );
delivery_check( ! is_wp_error( $profile ) && $profile['record']['revision'] === 4, 'New frozen deliveries resolve to the exact newly saved native mapping.' );
$again = editor_sync( $c, $client, $locks ); delivery_check( $again['posting_sync']['status'] === 'synced' && count( $client->puts() ) === 1, 'Retrying a confirmed revision does not increment remote revision.' );
delivery_check( count( array_filter( $client->requests, static function ( $r ) { return $r[0] !== 'GET' && ! str_starts_with( $r[1], '/templates/' ); } ) ) === 0, 'No default, credential, content or site registration writes occur.' );

foreach ( [ 'before', 'after' ] as $failure ) {
    [ $c, $client, $locks ] = editor_fixture(); $client->failure = $failure;
    $result = editor_sync( $c, $client, $locks, true );
    delivery_check( $result['posting_sync']['status'] === 'failed' && $result['local_only'] && $result['revision'] === 4, 'A transport failure reports the retained local revision: ' . $failure );
    $called = false; $blocked = editor_sync( $c, $client, $locks, true, static function () use ( &$called ) { $called = true; return []; } );
    delivery_check( is_wp_error( $blocked ) && ! $called, 'Pending update blocks another local save before mutation.' );
    $client->failure = ''; $retry = editor_sync( $c, $client, $locks );
    delivery_check( $retry['posting_sync']['status'] === 'synced' && $client->remote['revision'] === 2 && count( $client->puts() ) === ( $failure === 'after' ? 1 : 2 ), 'Retry reconciles a lost acknowledgement or safely repeats an unapplied conditional PUT: ' . $failure );
}
[ $c, $client, $locks ] = editor_fixture(); $client->failure = 'after'; editor_sync( $c, $client, $locks ); $client->failure = ''; $client->remote['revision'] = 3;
$conflict = editor_sync( $c, $client, $locks ); delivery_check( $conflict['posting_sync']['status'] === 'failed' && count( $client->puts() ) === 1, 'A concurrent remote revision cannot be overwritten during reconciliation.' );
[ $c, $client, $locks ] = editor_fixture(); $client->failure = 'after'; editor_sync( $c, $client, $locks ); $client->failure = ''; $client->remote['name'] = 'Another administrator';
$conflict = editor_sync( $c, $client, $locks ); delivery_check( $conflict['posting_sync']['status'] === 'failed' && count( $client->puts() ) === 1, 'Matching revision with different remote fields is not approved.' );
[ $c, $client, $locks ] = editor_fixture(); $client->remote['revision'] = 9;
$missing = editor_sync( $c, $client, $locks ); delivery_check( $missing['posting_sync']['status'] === 'failed' && ! $client->puts(), 'A remote default without trusted local scope cannot be taken over.' );
[ $c, $client, $locks ] = editor_fixture(); Nova_Bridge_Suite_Layout_Recipes::$record['mode'] = 'mixed';
$unsupported = editor_sync( $c, $client, $locks ); delivery_check( $unsupported['posting_sync']['status'] === 'failed' && ! $client->requests, 'Unsupported dynamic recipes stay local with an explicit failure.' );
[ $c, $client, $locks ] = editor_fixture(); $c['enabled'] = false;
delivery_check( is_wp_error( editor_sync( $c, $client, $locks ) ) && ! $client->requests, 'Disabled connections do not synchronize.' );

function first_editor_fixture() {
    $GLOBALS['options'] = []; $GLOBALS['permissions'] = true; Nova_Bridge_Suite_Layout_Recipes::$record = static_record();
    $c = nova_contract_fixture()['connection']; $c['enabled'] = true;
    $client = new Static_Editor_Client( $c ); $client->defaults = [ 'page' => '00000000-0000-4000-8000-000000000999' ];
    return [ $c, $client, new Contract_Test_Store() ];
}
// Exercise the real HTTP transport so a production save cannot silently use the test origin.
class Static_Editor_HTTP_Server {
    public $client; public $connection;
    public function request( $url, $args ) {
        $prefix = $this->connection['base_url'] . '/v1/sites/' . $this->connection['site_id'];
        delivery_check( str_starts_with( $url, $prefix . '/' ) && $args['headers']['Authorization'] === 'Bearer ' . $this->connection['token'], 'Every mapping request uses the configured origin, site and credential.' );
        $reply = $this->client->site_request( $args['method'], substr( $url, strlen( $prefix ) ), isset( $args['body'] ) ? json_decode( $args['body'], true ) : null, $args['headers'] );
        if ( is_wp_error( $reply ) ) { return $reply; }
        return Contract_Test_Server::reply( $reply['status'], json_encode( $reply['body'] ), [ 'etag' => $reply['etag'] ] );
    }
}
foreach ( [ 'https://api.nova.inspace.io', 'https://posting-test.fixture.invalid' ] as $origin ) {
    [ $c, $client, $locks ] = first_editor_fixture(); $c['base_url'] = $origin;
    $server = new Static_Editor_HTTP_Server(); $server->connection = $c; $server->client = $client; $GLOBALS['server'] = $server;
    $real = new Nova_Bridge_Suite_Posting_Client( $c ); $result = editor_sync( $c, $real, $locks, true );
    delivery_check( $result['posting_sync']['status'] === 'synced' && ! $result['local_only'], 'A new registration creates and selects its first category mapping on ' . $origin );
    delivery_check( array_column( $client->requests, 0 ) === [ 'GET', 'POST', 'PUT' ] && $client->puts()[0][1] === '/template-defaults' && $client->puts()[0][3]['If-Match'] === '"1"', 'First setup uses one create and a conditional default selection.' );
    delivery_check( $client->defaults['category'] === $client->remote['id'] && $client->defaults['page'] === '00000000-0000-4000-8000-000000000999', 'Other page-type defaults survive first category setup.' );
    $snapshot = nova_contract_fixture()['snapshot']; $snapshot['configuration'] = array_intersect_key( $client->remote, array_flip( [ 'id', 'name', 'page_type', 'definition', 'mapping', 'revision' ] ) ); $snapshot['page_type_frontend'] = 'category';
    $profile = Nova_Bridge_Suite_Static_Recipe_Adapter::configuration_for_snapshot( $snapshot, $client->site_id() );
    delivery_check( ! is_wp_error( $profile ) && $profile['scope'] === 'site_credential', 'First mapping has an exact local execution profile without private backoffice IDs.' );
    foreach ( [ 'site_id', 'page_type_frontend', 'client_id', 'domain_id' ] as $field ) { $bad = $snapshot; $bad[$field] = 'invalid'; delivery_check( is_wp_error( Nova_Bridge_Suite_Static_Recipe_Adapter::validate_profile( $bad, $profile ) ), 'First mapping rejects invalid scope: ' . $field ); }
    $again = editor_sync( $c, $real, $locks ); delivery_check( $again['posting_sync']['status'] === 'synced' && count( $client->puts() ) === 1, 'Retry does not create or update a confirmed mapping.' );
    Nova_Bridge_Suite_Layout_Recipes::$record['revision'] = 3; $updated = editor_sync( $c, $real, $locks, true );
    delivery_check( $updated['posting_sync']['template_revision'] === 2 && count( array_filter( $client->requests, static function ( $r ) { return $r[0] === 'POST'; } ) ) === 1, 'Subsequent saves update the new mapping instead of creating another.' );
}
foreach ( [ 'before', 'after' ] as $failure ) {
    [ $c, $client, $locks ] = first_editor_fixture(); $client->default_failure = $failure;
    $failed = editor_sync( $c, $client, $locks, true ); delivery_check( $failed['local_only'], 'Unconfirmed default selection is not reported as synced.' );
    $client->default_failure = ''; $retried = editor_sync( $c, $client, $locks );
    delivery_check( $retried['posting_sync']['status'] === 'synced' && count( array_filter( $client->requests, static function ( $r ) { return $r[0] === 'POST'; } ) ) === 1 && count( $client->puts() ) === ( $failure === 'after' ? 1 : 2 ), 'Default selection retries without a duplicate template: ' . $failure );
}
[ $c, $client, $locks ] = first_editor_fixture(); $client->create_failure = true; editor_sync( $c, $client, $locks ); $client->create_failure = false;
$uncertain = editor_sync( $c, $client, $locks );
delivery_check( $uncertain['posting_sync']['code'] === 'nova_static_create_uncertain' && count( array_filter( $client->requests, static function ( $r ) { return $r[0] === 'POST'; } ) ) === 1 && ! $client->puts(), 'A lost create acknowledgement never causes a duplicate create or default write.' );
[ $c, $client, $locks ] = first_editor_fixture(); $client->default_failure = 'before'; editor_sync( $c, $client, $locks ); $client->default_failure = ''; $client->defaults['category'] = '00000000-0000-4000-8000-000000000888';
$conflict = editor_sync( $c, $client, $locks ); delivery_check( $conflict['posting_sync']['code'] === 'nova_static_sync_conflict' && count( $client->puts() ) === 1, 'A concurrently selected category default is never replaced.' );

// First-time editor approval also works through the complete delivery/native writer path.
[ $c, $client, $locks ] = first_editor_fixture(); editor_sync( $c, $client, $locks );
$snapshot = nova_contract_fixture()['snapshot']; $snapshot['configuration'] = array_intersect_key( $client->remote, array_flip( [ 'id', 'name', 'page_type', 'definition', 'mapping', 'revision' ] ) ); $snapshot['page_type_frontend'] = 'category'; $snapshot['url'] = home_url( '/product-category/editor-created/' ); $snapshot['content']['top_content'] = '<p>Top.</p>'; $snapshot['content']['bottom_content'] = '<p>Bottom.</p>';
$f = nova_contract_fixture( [ 'content' => $snapshot ] ); $GLOBALS['server'] = $server = new Contract_Test_Server( $f ); $store = new Contract_Test_Store(); $GLOBALS['terms'] = []; $GLOBALS['insertions'] = 0;
$worker = new Nova_Bridge_Suite_Posting_Worker( $f['connection'], $store ); $result = $worker->process( $f['job'] );
delivery_check( $result['state'] === 'complete' && $GLOBALS['insertions'] === 1 && json_decode( $server->posts[1], true )['kind'] === 'publication_succeeded', 'A delivery frozen with the editor-created mapping publishes one category and emits success.' );

// The unified API resolves V1 category bindings before delivery; native category
// publication still uses the exact approved recipe and its original source keys.
[ $c, $client, $locks ] = first_editor_fixture(); editor_sync( $c, $client, $locks );
$snapshot = nova_contract_fixture()['snapshot']; $snapshot['configuration'] = array_intersect_key( $client->remote, array_flip( [ 'id', 'name', 'page_type', 'definition', 'mapping', 'revision' ] ) ); $snapshot['page_type_frontend'] = 'category'; $snapshot['url'] = home_url( '/product-category/unified-created/' ); $snapshot['content']['top_content'] = '<p>Unified top.</p>'; $snapshot['content']['bottom_content'] = '<p>Unified bottom.</p>';
$f = nova_contract_fixture( [ 'content' => $snapshot ] ); $source = array_merge( $snapshot['content'], array_intersect_key( $snapshot, array_flip( [ 'url', 'page_type_frontend', 'language' ] ) ) );
$wire = $snapshot; unset( $wire['attempt_id'] ); $wire['configuration'] = Nova_Bridge_Suite_Posting_Protocol::public_configuration( $snapshot['configuration'] ); $wire['content'] = [];
foreach ( $snapshot['configuration']['mapping']['fields'] as $binding ) { if ( isset( $source[$binding['source_field']] ) ) { $wire['content'][$binding['field_id']] = $source[$binding['source_field']]; } }
$f['snapshot'] = $wire; $f['raw'] = json_encode( $wire, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); $f['etag'] = '"' . hash( 'sha256', $f['raw'] ) . '"';
$GLOBALS['server'] = $server = new Contract_Test_Server( $f ); $store = new Contract_Test_Store(); $GLOBALS['terms'] = []; $GLOBALS['insertions'] = 0;
$worker = new Nova_Bridge_Suite_Posting_Worker( $f['connection'], $store ); $result = $worker->process( $f['job'] );
delivery_check( $result['state'] === 'complete' && $GLOBALS['insertions'] === 1 && json_decode( $server->posts[1], true )['kind'] === 'publication_succeeded' && $result['payload']['content'] === $wire, 'Unified V1 values publish one approved category and retain the exact public journal body.' );
echo 'PASS ' . $checks . " static category editor synchronization checks.\n";
