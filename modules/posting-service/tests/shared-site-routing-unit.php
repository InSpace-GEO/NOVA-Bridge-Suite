<?php
/** Real intake/discovery/worker with independent in-memory installations, no DB/network. */
require __DIR__ . '/webhook-worker-unit.php';
function home_url( $path = '/' ) { return rtrim( $GLOBALS['wake_options']['home'] ?? 'https://eazzypark.fixture.invalid', '/' ) . '/' . ltrim( $path, '/' ); }
set_error_handler( static function ( $severity, $message, $file, $line ) { throw new ErrorException( $message, 0, $severity, $file, $line ); } );
$checks = 0;
$base = 'https://eazzypark.fixture.invalid';
$airports = [ 'eindhoven', 'schiphol', 'rotterdam', 'brussel' ];
$exclude = array_map( static function ( $name ) { return '/' . $name; }, $airports );

function scope_fixture( string $url, string $home, array $excluded = [], bool $enabled = true ) {
    [ $store, $server, $request ] = wake_fixture( [ 'url_scope_enabled' => $enabled, 'url_scope_excluded_paths' => $excluded ] );
    $GLOBALS['wake_options']['home'] = $home;
    $snapshot = json_decode( $server->raw, true ); $snapshot['url'] = $url;
    $server->raw = json_encode( $snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); $server->etag = '"' . hash( 'sha256', $server->raw ) . '"';
    return [ $store, $server, $request ];
}

// One shared signed hint can wake every installation; only the URL owner reports.
foreach ( array_merge( [ '' ], $airports ) as $destination ) {
    $url = $base . ( $destination ? '/' . $destination : '' ) . '/parking-guide/';
    $writers = 0;
    foreach ( array_merge( [ '' ], $airports ) as $installation ) {
        [ $store, $server, $request ] = scope_fixture( $url, $base . ( $installation ? '/' . $installation : '' ), $installation ? [] : $exclude );
        wake_run( $request );
        $owned = $destination === $installation;
        delivery_check( 'complete' === $store->row['state'] && ( $owned ? 'committed' : 'skipped' ) === $store->row['phase'], 'Each installation completes locally with the correct routing decision.' );
        delivery_check( ( $owned ? 1 : 0 ) === Contract_Test_Writer::$apply, 'Only the URL owner writes native content.' );
        delivery_check( ( $owned ? 2 : 0 ) === count( $server->posts ), 'Foreign installations send no receipt or publication result.' );
        delivery_check( ( $owned ? 'committed' : 'skipped' ) === get_option( Nova_Bridge_Suite_Posting_Delivery::LAST_RUN_OPTION )['last_job']['phase'], 'Routing decisions remain visible in local run diagnostics.' );
        $writers += Contract_Test_Writer::$apply;
        $before = count( $server->posts ); wake_run( $request );
        delivery_check( $before === count( $server->posts ) && ( $owned ? 1 : 0 ) === Contract_Test_Writer::$apply, 'Duplicate forwarding cannot repeat a skipped or published item.' );
    }
    delivery_check( 1 === $writers, 'Exactly one of five installations publishes each destination.' );
}

$GLOBALS['wake_options']['home'] = $base . '/eindhoven';
$scope = [ 'url_scope_enabled' => true, 'url_scope_excluded_paths' => [] ];
foreach ( [
    [ $base . '/eindhoven', true ], [ $base . '/eindhoven/', true ], [ $base . '/eindhoven/article/?campaign=x', true ],
    [ '/eindhoven/article/', true ], [ '/schiphol/article/', false ], [ '/eindhoven-extra/article/', false ],
    [ $base . '/Eindhoven/article/', false ], [ 'https://EAZZYPARK.fixture.invalid:443/eindhoven/article/', true ],
    [ 'https://foreign.fixture.invalid/eindhoven/article/', false ], [ 'https://eazzypark.fixture.invalid.attacker.invalid/eindhoven/article/', false ],
    [ $base . ':444/eindhoven/article/', false ], [ $base . '/%65indhoven/article/', true ],
] as [ $url, $expected ] ) { delivery_check( $expected === Nova_Bridge_Suite_Posting_Url_Scope::owns( $scope, $url ), 'Origin and segment-boundary routing: ' . $url ); }
foreach ( [ '//foreign.fixture.invalid/eindhoven/', $base . '/eindhoven/../schiphol/', $base . '/eindhoven/%2e%2e/schiphol/', $base . '/eindhoven%2fschiphol/', $base . '/eindhoven%5carticle/', $base . '/eindhoven\\article/', $base . '/eindhoven//article/', $base . '/eindhoven/%zz', $base . '/eindhoven/#fragment', 'https://user:password@eazzypark.fixture.invalid/eindhoven/' ] as $invalid ) {
    delivery_check( is_wp_error( Nova_Bridge_Suite_Posting_Url_Scope::owns( $scope, $invalid ) ), 'Ambiguous URLs require local review, never publication or a silent skip.' );
}
delivery_check( true === Nova_Bridge_Suite_Posting_Url_Scope::owns( [], 'unchanged-old-behaviour' ), 'Disabled routing performs no new URL validation.' );
$GLOBALS['wake_options']['home'] = $base;
delivery_check( true === Nova_Bridge_Suite_Posting_Url_Scope::owns( $scope + [], $base . '/eindhoven-extra/' ), 'Root installation owns all paths until exclusions are configured.' );
$scope['url_scope_excluded_paths'] = $exclude;
delivery_check( false === Nova_Bridge_Suite_Posting_Url_Scope::owns( $scope, '/eindhoven/article/' ) && true === Nova_Bridge_Suite_Posting_Url_Scope::owns( $scope, '/eindhoven-extra/' ), 'Root exclusions use complete path segments.' );
delivery_check( [ '/eindhoven', '/schiphol' ] === Nova_Bridge_Suite_Posting_Url_Scope::exclusions( " /eindhoven/\r\n/schiphol\n/eindhoven\n" ), 'Exclude-list normalization retains unique root-relative paths.' );
foreach ( [ 'eindhoven', 'https://other.invalid/path', '/eindhoven?x=1', '/../', '/eindhoven%2fother' ] as $invalid ) { delivery_check( is_wp_error( Nova_Bridge_Suite_Posting_Url_Scope::exclusions( $invalid ) ), 'Invalid excluded paths are rejected on save.' ); }

// A missing local profile in a foreign installation must never turn into failure.
[ $store, $server, $request ] = scope_fixture( $base . '/eindhoven/article/', $base . '/schiphol' );
$calls = 0;
$worker = new Nova_Bridge_Suite_Posting_Worker( Nova_Bridge_Suite_Posting_Settings::$connection, $store, new Nova_Bridge_Suite_Posting_Client( Nova_Bridge_Suite_Posting_Settings::$connection ), static function () use ( &$calls ) { ++$calls; return new WP_Error( 'profile_unapproved' ); }, 'Contract_Test_Writer' );
$result = $worker->process( $GLOBALS['wake_fixture']['job'] );
delivery_check( 'skipped' === $result['phase'] && 0 === $calls && [] === $server->posts, 'Ownership is checked before mapping lookup and every receipt.' );
$server->attempt = '00000000-0000-4000-8000-000000000098';
$result['phase'] = 'attempt_check'; $result['state'] = 'running';
$result = $worker->process( $result );
delivery_check( 'skipped' === $result['phase'] && 0 === $calls && [] === $server->posts && $result['payload']['attempt_id'] === $server->attempt, 'A fresh backend attempt remains skipped in a foreign installation.' );

// An explicitly re-discovered skip can be accepted after the routing is corrected.
[ $store, $server, $request ] = scope_fixture( $base . '/eindhoven/article/', $base . '/schiphol' ); wake_run( $request );
$GLOBALS['wake_options']['home'] = $base . '/eindhoven';
$store->row['phase'] = 'attempt_check'; $store->row['state'] = 'ready'; $store->row['lease_until'] = 0;
wake_run( $request );
delivery_check( 'committed' === $store->row['phase'] && 1 === Contract_Test_Writer::$apply && 2 === count( $server->posts ) && ! isset( $store->row['payload']['scope_skipped'] ), 'Fresh discovery may process an untouched local skip after routing changes.' );

// A changed setting must not discard a receipt or uncertain native commit.
[ $store, $server, $request ] = scope_fixture( $base . '/eindhoven/article/', $base . '/eindhoven' );
$server->lose = 'publication_succeeded'; wake_run( $request );
$pending = $store->row['payload']['event_bytes']; $GLOBALS['wake_options']['home'] = $base . '/schiphol'; $store->row['next_attempt'] = time() - 1;
wake_run( $request );
delivery_check( 'committed' === $store->row['phase'] && 1 === Contract_Test_Writer::$apply && $pending === end( $server->posts ), 'Already-started recovery replays the final receipt unchanged even after routing changes.' );

// Disabled settings retain the existing pipeline, including old target checks.
[ $store, $server, $request ] = scope_fixture( $base . '/eindhoven/article/', $base . '/schiphol', [], false ); wake_run( $request );
delivery_check( 'committed' === $store->row['phase'] && 1 === Contract_Test_Writer::$apply, 'No filtering is added to existing connections by default.' );

// A mixed shared feed cannot spend all three native-work slots on foreign items.
class Scope_Batch_Store extends Wake_Store {
    public $rows = []; public $fixtures = [];
    public function enqueue_discovered( $site, $item, $checkpoint ) {
        $id = $item['id'];
        if ( ! isset( $this->rows[$id] ) ) { $this->rows[$id] = array_merge( $this->fixtures[$id]['job'], [ 'state' => 'ready', 'lease_until' => 0, 'attempts' => 0 ] ); }
        return $this->rows[$id];
    }
    public function claim( $site, $paused ) {
        foreach ( $this->rows as $id => $row ) {
            if ( ! in_array( $row['state'], [ 'ready', 'running' ], true ) ) { continue; }
            $this->row = $row; $claimed = parent::claim( $site, $paused );
            if ( $claimed ) { $this->rows[$id] = $claimed; return $claimed; }
        }
        return null;
    }
    public function save( $job, $changes ) {
        $saved = parent::save( $job, $changes );
        if ( ! is_wp_error( $saved ) ) { $this->rows[$saved['payload']['discovery']['id']] = $saved; }
        return $saved;
    }
}
class Scope_Batch_Server extends Contract_Test_Server {
    public $fixtures = [];
    public function request( $url, $args ) {
        if ( false !== strpos( $url, '/deliveries?' ) ) {
            $items = false === strpos( $url, '&cursor=' ) ? array_column( array_values( $this->fixtures ), 'item' ) : [];
            return self::reply( 200, json_encode( [ 'items' => $items, 'next_cursor' => '1' ] ) );
        }
        if ( preg_match( '#/deliveries/([a-f0-9-]+)#', $url, $match ) && isset( $this->fixtures[$match[1]] ) ) {
            $this->fixture = $this->fixtures[$match[1]]; $this->raw = $this->fixture['raw']; $this->etag = $this->fixture['etag']; $this->attempt = $this->fixture['attempt_id'];
        }
        return parent::request( $url, $args );
    }
}
[ $unused, $unused_server, $request ] = scope_fixture( $base . '/root-article/', $base, $exclude );
$fixtures = [];
for ( $i = 0; $i < 8; ++$i ) {
    $snapshot = nova_contract_fixture()['snapshot'];
    $snapshot['id'] = sprintf( '00000000-0000-4000-8000-%012d', 400 + $i );
    $snapshot['url'] = $base . ( $i < 4 ? '/' . $airports[$i] : '' ) . '/article-' . $i . '/'; $snapshot['url_id'] = (string) ( 800 + $i );
    $fixtures[$snapshot['id']] = nova_contract_fixture( [ 'content' => $snapshot, 'context' => [ 'operation_id' => sprintf( '00000000-0000-4000-8000-%012d', 900 + $i ) ] ] );
    $fixtures[$snapshot['id']]['job']['id'] = $i + 1;
}
$GLOBALS['wake_store'] = $store = new Scope_Batch_Store(); $store->fixtures = $fixtures;
$GLOBALS['server'] = $server = new Scope_Batch_Server( reset( $fixtures ) ); $server->fixtures = $fixtures;
wake_run( $request );
delivery_check( 7 === $store->claims && 3 === Contract_Test_Writer::$apply && 6 === count( $server->posts ), 'Four foreign items are skipped before three owned items publish in one bounded batch.' );
delivery_check( 7 === get_option( Nova_Bridge_Suite_Posting_Delivery::LAST_RUN_OPTION )['jobs_claimed'], 'Diagnostics include skipped jobs without confusing them with native writes.' );
delivery_check( 'ready' === end( $store->rows )['state'], 'The fourth owned item retains the existing three-publication limit.' );
delivery_check( 4 === count( array_filter( $store->rows, static function ( $row ) { return 'skipped' === $row['phase']; } ) ), 'Every foreign item has a terminal local skip.' );

echo "PASS {$checks} shared-site routing checks (in-memory WordPress/service doubles, no database or network).\n";
