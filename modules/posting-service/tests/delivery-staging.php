<?php
/** Opt-in real WordPress/native/journal canary. Only a synthetic HTTPS service is mocked. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'NOVA_DELIVERY_STAGING_CANARY' ) ) { throw new RuntimeException( 'NOVA_DELIVERY_STAGING_CANARY=1 is required on isolated staging.' ); }
require_once __DIR__ . '/mapped-writer-fixture.php';
require_once __DIR__ . '/contract-fixture.php';
final class Nova_Delivery_Staging_Writer {
    public static $apply_calls = 0; public static $recover_calls = 0; public static $crash_after_commit = false;
    public static function plan( $content, $config, $context ) { return Nova_Bridge_Suite_Mapped_Writer::plan( $content, $config, $context ); }
    public static function apply( $plan, $operation ) {
        ++self::$apply_calls; $result = Nova_Bridge_Suite_Mapped_Writer::apply( $plan, $operation );
        if ( ! is_wp_error( $result ) && self::$crash_after_commit ) { self::$crash_after_commit = false; throw new RuntimeException( 'Fixture crash after native commit' ); }
        return $result;
    }
    public static function recover( $plan, $operation ) { ++self::$recover_calls; return Nova_Bridge_Suite_Mapped_Writer::recover( $plan, $operation ); }
    public static function finish( $plan, $result ) { return Nova_Bridge_Suite_Mapped_Writer::finish( $plan, $result ); }
    public static function verify( $plan, $result ) { return Nova_Bridge_Suite_Mapped_Writer::verify( $plan, $result ); }
}
global $wpdb;
$original_user = get_current_user_id(); $temporary_post = 0; $tables = []; $database = null; $http_filter = null; $failure = null; $checks = 0;
$assert = static function ( $condition, $message ) use ( &$checks ) { ++$checks; if ( ! $condition ) { throw new RuntimeException( $message ); } };
$state_is = static function ( $result, $state ) use ( $assert ) { $assert( ! is_wp_error( $result ) && ( $result['state'] ?? '' ) === $state, 'Expected state ' . $state . ': ' . ( is_wp_error( $result ) ? $result->get_error_code() : ( $result['last_error'] ?? 'unknown' ) ) ); };
$settings_snapshot = static function () use ( $wpdb ) {
    // Hash-only comparison of existing connection/mapping/activation options; never print values.
    return $wpdb->get_results( "SELECT option_name, SHA2(option_value,256) AS value_hash FROM {$wpdb->options} WHERE option_name='active_plugins' OR option_name LIKE 'nova\\_%' ORDER BY option_name", ARRAY_A );
};
$before_settings = $settings_snapshot();
try {
    foreach ( [ 'Posting_Discovery', 'Posting_Jobs', 'Posting_Worker', 'Mapped_Writer', 'Posting_Client', 'Posting_Protocol', 'Receipt_Json' ] as $class ) { $assert( class_exists( 'Nova_Bridge_Suite_' . $class ), 'Candidate class missing: ' . $class ); }
    $admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] ); $assert( ! empty( $admins ), 'Existing administrator required.' ); wp_set_current_user( (int) $admins[0] );
    $suffix = substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 12 );
    $temporary_post = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'NOVA delivery test ' . $suffix, 'post_name' => 'nova-delivery-test-' . $suffix, 'post_excerpt' => 'Protected source note', 'post_content' => 'Preserve on update, blank on clone' ], true );
    $assert( ! is_wp_error( $temporary_post ) && $temporary_post > 0, 'Own draft created.' ); $temporary_post = (int) $temporary_post;
    $fingerprint = Nova_Bridge_Suite_Strategy::fingerprint( Nova_Bridge_Suite_Strategy::entity( 'post', $temporary_post ) );
    $native = nova_writer_fixture( $temporary_post, $fingerprint['signature'], 'update', $suffix );
    $native['context']['actor_user_id'] = (int) $admins[0];
    $make = static function ( int $version, string $title, string $publication = 'publish', bool $other_content = false ) use ( $native ) {
        $copy = $native; $copy['content']['id'] = wp_generate_uuid4(); $copy['content']['version_number'] = $version;
        $copy['content']['content_item_version_id'] = (string) ( time() * 100 + $version );
        if ( $other_content ) { $copy['content']['content_item_id'] .= '1'; }
        $copy['content']['content']['h1'] = $title; $copy['content']['source_sha256'] = hash( 'sha256', wp_json_encode( $copy['content'] ) );
        $copy['configuration']['local']['routing']['publication'] = $publication;
        $copy['context']['operation_id'] = wp_generate_uuid4(); $copy['context']['attempt_id'] = wp_generate_uuid4();
        nova_writer_fixture_refresh( $copy );
        return nova_contract_fixture( $copy );
    };
    $first = $make( 1, 'First contracted fixture heading', 'draft' );
    $connection = array_merge( $first['connection'], [ 'base_url' => 'https://nova-delivery-canary.invalid', 'actor_user_id' => (int) $admins[0] ] );
    // Independent connection avoids sharing live mysqli result handles.
    $database = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ); $assert( $database->ready, 'Independent journal connection ready.' );
    $database->set_prefix( substr( $wpdb->prefix, 0, 20 ) . 'nvct_' . $suffix . '_' );
    foreach ( [ 'nova_posting_jobs', 'nova_posting_discovery' ] as $name ) {
        $table = $database->prefix . $name;
        $assert( 1 === preg_match( '/^[a-zA-Z0-9_]+$/D', $table ) && false !== strpos( $table, 'nvct_' . $suffix . '_' ), 'Table bounded to fixture.' );
        $assert( null === $database->get_var( $database->prepare( 'SHOW TABLES LIKE %s', $database->esc_like( $table ) ) ), 'Table identity unused.' ); $tables[] = $table;
    }
    $jobs_table = $tables[0]; $jobs = new Nova_Bridge_Suite_Posting_Jobs( $database ); $assert( $jobs->install(), 'Own journal schemas installed.' );
    $fixtures = []; $feed = []; $sent = []; $accepted = []; $lost = []; $tampered = []; $http_calls = 0;
    $add = static function ( array $fixture ) use ( &$fixtures, &$feed ) { $id = $fixture['item']['id']; $fixtures[$id] = $fixture; $feed[] = $id; return $id; };
    $id1 = $add( $first ); $lost[$id1]['received_complete'] = 'exception';
    $http_filter = static function ( $preempt, $args, $url ) use ( &$fixtures, &$feed, &$sent, &$accepted, &$lost, &$tampered, &$http_calls, $connection, $assert ) {
        if ( 0 !== strpos( $url, 'https://nova-delivery-canary.invalid/' ) ) { return $preempt; }
        ++$http_calls; $path = wp_parse_url( $url, PHP_URL_PATH ); $root = '/v1/sites/' . $connection['site_id'] . '/deliveries'; $headers = []; $body = ''; $status = 200;
        $assert( 'Bearer ' . $connection['token'] === ( $args['headers']['Authorization'] ?? '' ), 'Real client supplies the fixture site token.' );
        if ( $path === $root && 'GET' === $args['method'] ) {
            parse_str( wp_parse_url( $url, PHP_URL_QUERY ) ?? '', $query ); $cursor = isset( $query['cursor'] ) ? (int) $query['cursor'] : 0;
            $ids = array_slice( $feed, $cursor, 100 ); $items = [];
            foreach ( $ids as $id ) { $items[] = $fixtures[$id]['item']; }
            $body = wp_json_encode( [ 'items' => $items, 'next_cursor' => $ids ? (string) ( $cursor + count($ids) ) : ( $query['cursor'] ?? null ) ] );
        } elseif ( preg_match( '#^' . preg_quote( $root, '#' ) . '/([a-f0-9-]{36})(/events)?$#D', $path, $match ) ) {
            $id = $match[1]; $fixture = $fixtures[$id] ?? null;
            if ( ! $fixture ) { return new WP_Error( 'fixture_unknown_delivery' ); }
            if ( empty( $match[2] ) && 'GET' === $args['method'] ) {
                $assert( ! isset( $args['headers']['If-None-Match'] ), 'Exact GET requests full current attempt authority.' );
                $headers = [ 'etag' => $fixture['etag'], 'x-nova-attempt-id' => $fixture['attempt_id'] ]; $body = $tampered[$id] ?? $fixture['raw'];
            } elseif ( ! empty( $match[2] ) && 'POST' === $args['method'] ) {
                $bytes = $args['body']; $event = json_decode( $bytes, true ); $kind = $event['kind'] ?? '';
                $assert( true === Nova_Bridge_Suite_Posting_Protocol::event_input( $event ), 'Current conditional event schema used.' );
                $assert( $event['delivery_id'] === $id && $event['attempt_id'] === $fixture['attempt_id'] && $event['delivery_etag'] === $fixture['etag'], 'Event carries exact authenticated delivery authority.' );
                $sent[$id][$kind][] = $bytes; $event_id = $event['event_id'];
                if ( isset( $accepted[$event_id] ) && $accepted[$event_id]['bytes'] !== $bytes ) { return new WP_Error( 'fixture_event_bytes_changed' ); }
                $accepted[$event_id] = $accepted[$event_id] ?? [ 'bytes' => $bytes, 'ack' => nova_contract_fixture_event_accept( $bytes ) ];
                $body = wp_json_encode( $accepted[$event_id]['ack'] ); $status = 202;
                if ( ! empty( $lost[$id][$kind] ) ) {
                    $mode = $lost[$id][$kind]; unset( $lost[$id][$kind] );
                    if ( 'exception' === $mode ) { throw new RuntimeException( 'Fixture exception after durable event acceptance' ); }
                    return new WP_Error( 'fixture_ack_lost', 'Accepted fixture event; acknowledgment lost.' );
                }
            } else { return new WP_Error( 'fixture_unexpected_method' ); }
        } else { return new WP_Error( 'fixture_unexpected_route', 'Only current delivery endpoints are mocked.' ); }
        return [ 'headers' => $headers, 'body' => $body, 'response' => [ 'code' => $status, 'message' => 'Fixture' ], 'cookies' => [], 'filename' => null ];
    };
    add_filter( 'pre_http_request', $http_filter, 999, 3 );
    $client = new Nova_Bridge_Suite_Posting_Client( $connection );
    // Configuration identity/native safety are real; network profile synchronization has a separate canary.
    $resolver = static function ( $snapshot, $site ) use ( &$fixtures, $assert ) { $fixture = $fixtures[$snapshot['id']]; $assert( $site === $snapshot['site_id'] && $fixture['snapshot']['configuration'] === $snapshot['configuration'], 'Exact frozen configuration selected.' ); return $fixture['configuration']; };
    $policy = static function ( $client, $snapshot, $configuration ) { $local = $configuration['local']; return [ 'reference' => array_intersect_key( $local, array_flip( [ 'reference_type', 'reference_id', 'signature' ] ) ), 'routing' => $local['routing'] ]; };
    $worker = new Nova_Bridge_Suite_Posting_Worker( $connection, $jobs, $client, $resolver, 'Nova_Delivery_Staging_Writer', $policy );
    $discovery = new Nova_Bridge_Suite_Posting_Discovery( $connection, $jobs, $client );
    $discover = static function () use ( $discovery, $assert ) { $assert( true === $discovery->wake(), 'Wake saved.' ); $result = $discovery->run(); $assert( ! is_wp_error($result) && 'complete' === $result['state'], 'Current authenticated discovery completes.' ); };
    $retry = static function ( $id ) use ( $database, $jobs_table, $assert ) { $assert( false !== $database->query( $database->prepare( "UPDATE {$jobs_table} SET state='ready',next_attempt=0,lease_until=0 WHERE id=%d", $id ) ), 'Own fixture job made immediately due.' ); };
    $claim = static function () use ( $jobs, $connection, $assert ) { $job = $jobs->claim($connection['site_id']); $assert( is_array($job), 'Own due job claimed.' ); return $job; };
    $preserved = static function () use ( $assert, $temporary_post ) { $assert( 'Protected source note' === get_post_field('post_excerpt',$temporary_post) && 'Preserve on update, blank on clone' === get_post_field('post_content',$temporary_post), 'Protected and leave-empty update values retained.' ); };

    $discover(); $one = $jobs->enqueue_discovered($connection['site_id'],$first['item'],'1'); $duplicate = $jobs->enqueue_discovered($connection['site_id'],$first['item'],'1');
    $assert( !is_wp_error($one) && $one['id'] === $duplicate['id'] && $one['operation_id'] === $duplicate['operation_id'], 'Duplicate discovery keeps one job/operation.' );
    $owned = $claim(); $assert( null === $jobs->claim($connection['site_id']), 'Only one lease owns this work.' );
    $owned = $jobs->save($owned,[]); $assert( !is_wp_error($owned), 'Same-second CAS save retains ownership.' );
    $result = $worker->process($owned); $state_is($result,'ready');
    $assert( 'event_pending' === $result['phase'] && 0 === Nova_Delivery_Staging_Writer::$apply_calls, 'Lost received acknowledgement retains bytes before any native write.' );
    $received_bytes = $result['payload']['event_bytes']; $retry($result['id']); $result = $worker->process($claim()); $state_is($result,'ready');
    $assert( 'committed' === $result['phase'] && 'awaiting_publication' === $result['last_error'] && 1 === Nova_Delivery_Staging_Writer::$apply_calls, 'Real native draft waits for actual publication.' );
    $assert( [$received_bytes,$received_bytes] === $sent[$id1]['received_complete'] && empty($sent[$id1]['publication_succeeded']), 'Received event repeats exact bytes while draft never reports published.' );
    $assert( 'First contracted fixture heading' === get_post_field('post_title',$temporary_post) && 'draft' === get_post_status($temporary_post), 'Exact content reached own native draft.' ); $preserved();
    $published = wp_update_post( [ 'ID'=>$temporary_post, 'post_status'=>'publish' ], true ); $assert( !is_wp_error($published), 'Own draft manually published through WordPress.' );
    $retry($result['id']); $result = $worker->process($claim()); $state_is($result,'complete');
    $assert( 1 === Nova_Delivery_Staging_Writer::$apply_calls && 1 === count($sent[$id1]['publication_succeeded']), 'Manual publication verified without repeating content write.' );
    $assert( 'pending' === $result['payload']['events']['publication_succeeded']['accepted']['status'], '202 remains backend-pending evidence.' );

    $second = $make(2,'Published fixture heading after draft'); $id2 = $add($second); $lost[$id2]['publication_succeeded'] = true; $discover();
    $result = $worker->process($claim()); $state_is($result,'ready'); $assert( 'event_pending' === $result['phase'] && 2 === Nova_Delivery_Staging_Writer::$apply_calls, 'Lost publication acknowledgement follows one native commit.' );
    $publication_bytes = $result['payload']['event_bytes']; $retry($result['id']); $result = $worker->process($claim()); $state_is($result,'complete');
    $assert( [$publication_bytes,$publication_bytes] === $sent[$id2]['publication_succeeded'] && 2 === Nova_Delivery_Staging_Writer::$apply_calls, 'Lost success acknowledgement replays exact202 event with no native retry.' ); $preserved();

    $third = $make(3,'Recovered fixture heading'); $id3 = $add($third); $discover(); Nova_Delivery_Staging_Writer::$crash_after_commit = true;
    $result = $worker->process($claim()); $state_is($result,'ready'); $assert( 'applying' === $result['phase'], 'Crash after actual commit remains recover-only.' );
    $retry($result['id']); $result = $worker->process($claim()); $state_is($result,'complete');
    $assert( 3 === Nova_Delivery_Staging_Writer::$apply_calls && 1 === Nova_Delivery_Staging_Writer::$recover_calls && 'Recovered fixture heading' === get_post_field('post_title',$temporary_post), 'Real operation marker recovers committed write once.' ); $preserved();

    $other = $make(500,'Must never replace unrelated content','publish',true); $other_id = $add($other); $discover();
    $before_post = get_post($temporary_post)->to_array(); $before_meta = get_post_meta($temporary_post);
    $result = $worker->process($claim()); $state_is($result,'complete');
    $assert( $before_post === get_post($temporary_post)->to_array() && $before_meta === get_post_meta($temporary_post) && isset($sent[$other_id]['publication_failed']) && !isset($sent[$other_id]['publication_succeeded']), 'Different content identity fails closed with no native changes.' );

    $tamper = $make(4,'Tampered bytes never reach native writer'); $tamper_id = $add($tamper); $tampered[$tamper_id] = "\n" . $tamper['raw']; $discover();
    $before_apply = Nova_Delivery_Staging_Writer::$apply_calls; $result = $worker->process($claim()); $state_is($result,'blocked');
    $assert( $before_apply === Nova_Delivery_Staging_Writer::$apply_calls && empty($sent[$tamper_id]) && $before_post === get_post($temporary_post)->to_array(), 'Changed raw bytes produce no event or native mutation.' );

    $edited = $make(5,'Draft whose content must not be silently re-certified','draft'); $edited_id = $add($edited); $discover();
    $result = $worker->process($claim()); $state_is($result,'ready'); $assert( 'awaiting_publication' === $result['last_error'], 'Second own draft awaits publication.' );
    wp_update_post( [ 'ID'=>$temporary_post, 'post_status'=>'publish', 'post_content'=>'Fixture human edit after native commit' ], true );
    $before_apply = Nova_Delivery_Staging_Writer::$apply_calls; $retry($result['id']); $result = $worker->process($claim());
    $assert( is_array($result) && 'nova_writer_recovery_drift' === $result['last_error'] && empty($sent[$edited_id]['publication_succeeded']) && $before_apply === Nova_Delivery_Staging_Writer::$apply_calls && 'Fixture human edit after native commit' === get_post_field('post_content',$temporary_post), 'Publication with changed content is not certified or overwritten.' );
    // Prevent this intentionally unresolved fixture from competing in the lease-only check.
    $database->query( $database->prepare( "UPDATE {$jobs_table} SET state='blocked' WHERE id=%d", $result['id'] ) );
    $lease = $make(6,'Lease-only fixture'); $add($lease); $discover(); $old = $claim();
    $database->query( $database->prepare("UPDATE {$jobs_table} SET lease_until=1 WHERE id=%d",$old['id']) ); $new = $claim();
    $assert( $new['lease_token'] !== $old['lease_token'] && is_wp_error($jobs->save($old,['phase'=>'applying'])), 'Expired lease cannot cross a native mutation boundary.' );
    $jobs->save($new,['state'=>'blocked','last_error'=>'fixture_cleanup']);

    // Exercise announcement/worker serialization against the real SQL journal without CMS effects.
    $hint = $make(7,'Journal-only announcement fixture');
    $jobs->enqueue_discovered($connection['site_id'],$hint['item'],'9007199254740993'); $in_flight = $claim();
    $hint_payload = $in_flight['payload']; $hint_payload['event_bytes'] = '{"fixture":"retained acknowledgment"}';
    $hint_payload['native_absence_proof'] = ['no_native_commit'=>true];
    $in_flight = $jobs->save($in_flight,['phase'=>'event_pending','payload'=>$hint_payload]);
    $announced = $jobs->enqueue_discovered($connection['site_id'],$hint['item'],'9007199254740994');
    $assert(!is_wp_error($announced) && $announced['lease_token'] === $in_flight['lease_token'] && 'running' === $announced['state'], 'Real SQL announcement preserves the in-flight lease.');
    $terminal = $jobs->save($in_flight,['state'=>'complete','phase'=>'committed']);
    $assert(!is_wp_error($terminal) && 'ready' === $terminal['state'] && 'attempt_check' === $terminal['phase'] && $terminal['operation_id'] === $in_flight['operation_id'] && $terminal['payload']['event_bytes'] === $hint_payload['event_bytes'] && '9007199254740994' === $terminal['payload']['discovery_checkpoint'] && !isset($terminal['payload']['pending_discovery_checkpoint']), 'Old acknowledgment save retains the newer SQL announcement and exact event evidence.');
    $assert('0' === (string)$database->get_var($database->prepare("SELECT attempts FROM {$jobs_table} WHERE id=%d",$terminal['id'])), 'Reset failure counter is durably zero.');
    $checked = $claim(); $closed = $jobs->save($checked,['state'=>'complete','phase'=>'committed']);
    $assert(!is_wp_error($closed) && 'complete' === $closed['state'], 'Consumed announcement closes without a rescheduling loop.');
    $assert( $http_calls > 0, 'Real client transport exercised by scoped fixture interception.' );
} catch ( Throwable $error ) { $failure = $error; }
finally {
    if ( $http_filter ) { remove_filter('pre_http_request',$http_filter,999); }
    if ( is_int($temporary_post) && $temporary_post > 0 ) { wp_delete_post($temporary_post,true); }
    foreach ( $tables as $table ) { if ( isset($suffix) && preg_match('/^[a-zA-Z0-9_]+$/D',$table) && false !== strpos($table,'nvct_'.$suffix.'_') ) { $wpdb->query("DROP TABLE IF EXISTS {$table}"); } }
    if ( $database instanceof wpdb ) { remove_filter('query',[$database,'remove_placeholder_escape'],0); $database->close(); }
    wp_set_current_user($original_user);
    if ( $before_settings !== $settings_snapshot() && ! $failure ) { $failure = new RuntimeException('Existing NOVA settings or active plugins changed during canary.'); }
}
if ( $failure ) { WP_CLI::error('Current delivery canary failed after cleanup: '.$failure->getMessage()); }
WP_CLI::success('PASS '.$checks.' current delivery/native/journal checks. Own page/revisions and tables removed; service HTTP simulated; existing options preserved.');
