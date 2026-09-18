<?php
/** wp eval-file with candidate preloaded. Real temporary journal/native writer; all service HTTP mocked. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit( 1 ); }
require_once __DIR__ . '/mapped-writer-fixture.php';

final class Nova_Delivery_Staging_Writer {
    public static $apply_calls = 0;
    public static $recover_calls = 0;
    public static $crash_after_commit = false;
    public static function plan( $content, $configuration, $context ) { return Nova_Bridge_Suite_Mapped_Writer::plan( $content, $configuration, $context ); }
    public static function apply( $plan, $operation ) {
        ++self::$apply_calls;
        $result = Nova_Bridge_Suite_Mapped_Writer::apply( $plan, $operation );
        if ( ! is_wp_error( $result ) && self::$crash_after_commit ) { self::$crash_after_commit = false; throw new RuntimeException( 'Fixture process loss after native commit' ); }
        return $result;
    }
    public static function recover( $plan, $operation ) { ++self::$recover_calls; return Nova_Bridge_Suite_Mapped_Writer::recover( $plan, $operation ); }
    public static function finish( $plan, $result ) { return Nova_Bridge_Suite_Mapped_Writer::finish( $plan, $result ); }
    public static function verify( $plan, $result ) { return Nova_Bridge_Suite_Mapped_Writer::verify( $plan, $result ); }
}

global $wpdb;
$original_user = get_current_user_id(); $temporary_post = 0; $temporary_table = ''; $database = null; $http_filter = null; $failure = null; $checks = 0;
$assert = static function ( $condition, $message ) use ( &$checks ) { ++$checks; if ( ! $condition ) { throw new RuntimeException( $message ); } };
try {
    foreach ( [ 'Nova_Bridge_Suite_Posting_Jobs', 'Nova_Bridge_Suite_Posting_Worker', 'Nova_Bridge_Suite_Mapped_Writer', 'Nova_Bridge_Suite_Posting_Client', 'Nova_Bridge_Suite_Writing_Adapter' ] as $class ) { $assert( class_exists( $class ), 'Candidate class missing: ' . $class ); }
    $admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
    $assert( ! empty( $admins ), 'An existing administrator is required.' ); wp_set_current_user( (int) $admins[0] );
    $suffix = substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 12 );
    $temporary_post = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'NOVA delivery test ' . $suffix, 'post_name' => 'nova-delivery-test-' . $suffix, 'post_excerpt' => 'Protected source note', 'post_content' => 'Preserve on update, blank on clone' ], true );
    $assert( ! is_wp_error( $temporary_post ) && $temporary_post > 0, 'Own temporary native draft created.' );
    $temporary_post = (int) $temporary_post;
    $entity = Nova_Bridge_Suite_Strategy::entity( 'post', $temporary_post );
    $fingerprint = Nova_Bridge_Suite_Strategy::fingerprint( $entity );
    $fixture = nova_writer_fixture( $temporary_post, $fingerprint['signature'], 'update', $suffix );
    $configuration = $fixture['configuration'];
    $base_content = $fixture['content']; $base_content['content_id'] = (string) time() . (string) random_int( 1000, 9999 ); $base_content['status'] = 'ready';
    $connection = [ 'base_url' => 'https://nova-delivery-canary.invalid', 'site_id' => $configuration['site_id'], 'token' => 'fixture-token-never-sent', 'webhook_secret' => 'fixture-secret', 'enabled' => true, 'paused' => false, 'actor_user_id' => (int) $admins[0] ];
    // wpdb cannot be cloned: its mysqli result handle would be shared and flushed twice.
    // Use an independent connection to the same database, with only this canary's table prefix.
    $database = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
    $assert( $database->ready, 'Independent temporary-journal database connection opened.' );
    $database->set_prefix( substr( $wpdb->prefix, 0, 20 ) . 'nvct_' . $suffix . '_' );
    $temporary_table = $database->prefix . 'nova_posting_jobs';
    $assert( 1 === preg_match( '/^[a-zA-Z0-9_]+$/D', $temporary_table ) && false !== strpos( $temporary_table, 'nvct_' . $suffix . '_' ), 'Temporary table name is bounded to this canary.' );
    $jobs = new Nova_Bridge_Suite_Posting_Jobs( $database );
    $assert( $jobs->install(), 'Own InnoDB delivery journal installed.' );

    $remote_receipts = []; $content_versions = []; $lost_ack_versions = [ 1 => true ]; $http_calls = 0;
    $http_filter = static function ( $preempt, $args, $url ) use ( &$remote_receipts, &$content_versions, &$lost_ack_versions, &$http_calls, $base_content ) {
        if ( 0 !== strpos( $url, 'https://nova-delivery-canary.invalid/' ) ) { return $preempt; }
        ++$http_calls; $parts = wp_parse_url( $url ); parse_str( $parts['query'] ?? '', $query );
        $is_result = '/result' === substr( $parts['path'], -7 );
        $version = (int) ( $query['version'] ?? 0 ); $status = 200; $body = null;
        if ( $is_result && 'POST' === $args['method'] ) {
            $body = json_decode( $args['body'], true ); $version = $body['version'];
            $remote_receipts[ $version ] = array_merge( [ 'content_id' => $base_content['content_id'] ], $body ); $body = $remote_receipts[ $version ]; $status = 201;
            if ( ! empty( $lost_ack_versions[ $version ] ) ) { unset( $lost_ack_versions[ $version ] ); return new WP_Error( 'fixture_response_lost', 'Fixture acknowledged server-side but response was lost.' ); }
        } elseif ( $is_result ) { $body = $remote_receipts[ $version ] ?? [ 'error' => [ 'code' => 'not_found' ] ]; $status = isset( $remote_receipts[ $version ] ) ? 200 : 404; }
        else { $body = $content_versions[ $version ] ?? [ 'error' => [ 'code' => 'not_found' ] ]; $status = isset( $content_versions[ $version ] ) ? 200 : 404; }
        return [ 'headers' => [], 'body' => wp_json_encode( $body ), 'response' => [ 'code' => $status, 'message' => 'Fixture' ], 'cookies' => [], 'filename' => null ];
    };
    add_filter( 'pre_http_request', $http_filter, 999, 3 );
    $resolver = static function ( $pin, $digest, $site ) use ( $configuration ) { return $configuration; };
    $worker = new Nova_Bridge_Suite_Posting_Worker( $connection, $jobs, new Nova_Bridge_Suite_Posting_Client( $connection ), $resolver, 'Nova_Delivery_Staging_Writer' );
    $enqueue = static function ( $version ) use ( $jobs, $base_content, $connection ) { return $jobs->enqueue( [ 'event' => 'content.ready', 'site' => $connection['site_id'], 'content_id' => $base_content['content_id'], 'version' => $version ] ); };
    $retry_now = static function ( $id ) use ( $wpdb, $temporary_table ) { $wpdb->query( $wpdb->prepare( "UPDATE {$temporary_table} SET state='ready',next_attempt=0,lease_until=0 WHERE id=%d", $id ) ); };

    $content_versions[1] = $base_content;
    $first = $enqueue( 1 ); $duplicate = $enqueue( 1 );
    $assert( ! is_wp_error( $first ) && $first['id'] === $duplicate['id'], 'Repeated signed event identity has one durable row.' );
    $claim = $jobs->claim( $connection['site_id'] );
    $assert( $claim && ! is_wp_error( $claim ) && null === $jobs->claim( $connection['site_id'] ), 'Unexpired atomic claim has only one owner.' );
    $same = $jobs->save( $claim, [] ); $same_again = $jobs->save( $same, [] );
    $assert( ! is_wp_error( $same_again ), 'Identical same-second journal saves preserve lease ownership.' );
    $outcome = $worker->process( $same_again );
    $assert( ! is_wp_error( $outcome ) && 'receipt_pending' === $outcome['phase'] && 'ready' === $outcome['state'], 'Lost HTTP receipt acknowledgement retains committed result: ' . wp_json_encode( is_wp_error( $outcome ) ? $outcome->get_error_code() : [ $outcome['phase'], $outcome['last_error'] ] ) );
    $assert( 1 === Nova_Delivery_Staging_Writer::$apply_calls && $base_content['fields']['heading'] === get_post_field( 'post_title', $temporary_post ), 'Exact content committed once through the real native writer.' );
    $assert( 'Protected source note' === get_post_field( 'post_excerpt', $temporary_post ) && 'Preserve on update, blank on clone' === get_post_field( 'post_content', $temporary_post ), 'Protected and leave-empty native values survive the update.' );
    $retry_now( $outcome['id'] ); $outcome = $worker->process( $jobs->claim( $connection['site_id'] ) );
    $assert( 'complete' === $outcome['state'] && 1 === Nova_Delivery_Staging_Writer::$apply_calls, 'Remote winner lookup recovers lost acknowledgement without a second CMS write.' );

    $content_versions[2] = array_merge( $base_content, [ 'version' => 2, 'fields' => [ 'heading' => 'Second recovered fixture heading', 'slug' => $base_content['fields']['slug'] ] ] );
    $enqueue( 2 ); Nova_Delivery_Staging_Writer::$crash_after_commit = true;
    $outcome = $worker->process( $jobs->claim( $connection['site_id'] ) );
    $assert( 'applying' === $outcome['phase'] && 'ready' === $outcome['state'], 'Injected process loss after real transaction retains recover-only phase.' );
    $retry_now( $outcome['id'] ); $outcome = $worker->process( $jobs->claim( $connection['site_id'] ) );
    $assert( 'complete' === $outcome['state'] && 2 === Nova_Delivery_Staging_Writer::$apply_calls && 1 === Nova_Delivery_Staging_Writer::$recover_calls, 'Real transactional marker recovers crash without reapplying.' );

    $content_versions[4] = array_merge( $base_content, [ 'version' => 4, 'fields' => [ 'heading' => 'Fourth fixture heading', 'slug' => $base_content['fields']['slug'] ] ] );
    $enqueue( 4 ); $outcome = $worker->process( $jobs->claim( $connection['site_id'] ) );
    $assert( 'complete' === $outcome['state'], 'Later version commits to the existing content target.' );
    $before = Nova_Delivery_Staging_Writer::$apply_calls;
    $content_versions[3] = array_merge( $base_content, [ 'version' => 3 ] ); $enqueue( 3 ); $outcome = $worker->process( $jobs->claim( $connection['site_id'] ) );
    $assert( 'blocked' === $outcome['state'] && 'newer_version_already_committed' === $outcome['last_error'] && $before === Nova_Delivery_Staging_Writer::$apply_calls, 'Delayed older version never overwrites the higher committed version.' );

    $content_versions[5] = array_merge( $base_content, [ 'version' => 5, 'pin_id' => null ] ); $enqueue( 5 ); $outcome = $worker->process( $jobs->claim( $connection['site_id'] ) );
    $assert( 'blocked' === $outcome['state'] && 'validated' === $outcome['phase'] && $before === Nova_Delivery_Staging_Writer::$apply_calls, 'Missing exact pin blocks before native mutation while retaining fetched content.' );
    $content_versions[6] = array_merge( $base_content, [ 'version' => 7 ] ); $enqueue( 6 ); $outcome = $worker->process( $jobs->claim( $connection['site_id'] ) );
    $assert( 'blocked' === $outcome['state'] && $before === Nova_Delivery_Staging_Writer::$apply_calls, 'Service ignoring exact version query cannot cause a CMS write.' );

    $lease_job = $enqueue( 8 ); $old_claim = $jobs->claim( $connection['site_id'] );
    $wpdb->query( $wpdb->prepare( "UPDATE {$temporary_table} SET lease_until=1 WHERE id=%d", $lease_job['id'] ) );
    $new_claim = $jobs->claim( $connection['site_id'] );
    $assert( $new_claim['lease_token'] !== $old_claim['lease_token'] && is_wp_error( $jobs->save( $old_claim, [ 'phase' => 'applying' ] ) ), 'Expired worker cannot transition after another worker owns the lease.' );
    $jobs->save( $new_claim, [ 'state' => 'blocked', 'last_error' => 'fixture_cleanup' ] );
    $assert( $http_calls > 0 && $before === Nova_Delivery_Staging_Writer::$apply_calls, 'Real HTTP client was exercised entirely through fixture interception.' );
} catch ( Throwable $error ) { $failure = $error; }
finally {
    if ( $http_filter ) { remove_filter( 'pre_http_request', $http_filter, 999 ); }
    if ( is_int( $temporary_post ) && $temporary_post > 0 ) { wp_delete_post( $temporary_post, true ); }
    if ( $temporary_table && isset( $suffix ) && 1 === preg_match( '/^[a-zA-Z0-9_]+$/D', $temporary_table ) && false !== strpos( $temporary_table, 'nvct_' . $suffix . '_' ) ) { $wpdb->query( "DROP TABLE IF EXISTS {$temporary_table}" ); }
    if ( $database instanceof wpdb ) { remove_filter( 'query', [ $database, 'remove_placeholder_escape' ], 0 ); $database->close(); }
    wp_set_current_user( $original_user );
}
if ( $failure ) { WP_CLI::error( 'Delivery canary failed after cleanup: ' . $failure->getMessage() ); }
WP_CLI::success( 'PASS ' . $checks . ' durable delivery/real native writer checks; own page and journal removed; no external HTTP.' );
