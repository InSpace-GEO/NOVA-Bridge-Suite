<?php
/** Editor revision updates use the real adapter with a conditional HTTP service seam. */
require __DIR__ . '/static-category-unit.php';
$checks = 0;
class Static_Editor_Client extends Static_Setup_Client {
    public $requests = []; public $failure = ''; public $conflict = false;
    public function site_request( string $method, string $suffix, $body = null, array $headers = [] ) {
        $this->requests[] = [ $method, $suffix, $body, $headers ];
        if ( '/template-defaults' === $suffix ) { return [ 'status' => 200, 'body' => [ 'defaults' => [ 'category' => $this->remote['id'], 'page' => '00000000-0000-4000-8000-000000000999' ] ] ]; }
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
echo 'PASS ' . $checks . " static category editor synchronization checks.\n";
