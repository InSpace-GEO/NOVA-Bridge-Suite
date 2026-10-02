<?php
/** Standalone journal/CAS protocol checks against a deterministic wpdb boundary. */
define( 'ABSPATH', __DIR__ . '/' ); define( 'ARRAY_A', 'ARRAY_A' );
class WP_Error {
    private $code; private $data;
    public function __construct( $code, $message = '', $data = [] ) { $this->code = $code; $this->data = $data; }
    public function get_error_code() { return $this->code; }
    public function get_error_data() { return $this->data; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_generate_uuid4() { static $i = 0; return '11111111-1111-4111-8111-' . sprintf( '%012d', ++$i ); }
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-posting-protocol.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-posting-discovery.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-posting-jobs.php';

class Posting_Jobs_Database {
    public $prefix = 'fixture_'; public $last_error = ''; public $rows = []; public $discovery = []; public $queries = []; public $fail_query = false; public $deny_lock = false; public $count_override = null;
    public function prepare( $sql, ...$args ) { return [ $sql, $args ]; }
    private function unpack( $query ) { $this->queries[] = $query; $this->last_error = ''; return is_array( $query ) ? $query : [ $query, [] ]; }
    private function guard( $sql, $required ) { if ( false === strpos( $sql, $required ) ) { throw new RuntimeException( 'Missing SQL concurrency boundary: ' . $required ); } }
    public function get_var( $query ) {
        [ $sql, $args ] = $this->unpack( $query );
        if ( false !== strpos( $sql, 'GET_LOCK' ) ) { return $this->deny_lock ? '0' : '1'; }
        if ( false !== strpos( $sql, 'RELEASE_LOCK' ) ) { return '1'; }
        if ( false !== strpos( $sql, 'COUNT(*)' ) ) { return $this->count_override ?? count( $this->rows ); }
        if ( 0 === strpos( $sql, 'SELECT id FROM' ) ) {
            $this->guard( $sql, 'protocol=%s AND identity_conflict=0' );
            foreach ( $this->rows as $row ) {
                if ( $row['site_id'] !== $args[0] || $row['protocol'] !== $args[1] || $row['identity_conflict'] || ! in_array( $row['state'], [ 'ready', 'running' ], true ) || $row['next_attempt'] > $args[2] || $row['lease_until'] >= $args[3] ) { continue; }
                if ( false !== strpos( $sql, "phase IN ('applying','committed','event_pending')" ) && ! in_array( $row['phase'], [ 'applying', 'committed', 'event_pending' ], true ) ) { continue; }
                return $row['id'];
            }
            return null;
        }
        throw new RuntimeException( 'Unexpected SELECT scalar.' );
    }
    public function get_row( $query, $mode ) {
        [ $sql, $args ] = $this->unpack( $query );
        if ( false !== strpos( $sql, 'fixture_nova_posting_discovery' ) ) { return $this->discovery[ $args[0] ] ?? null; }
        if ( false !== strpos( $sql, 'identity = %s' ) ) { foreach ( $this->rows as $row ) { if ( $row['identity'] === $args[0] ) { return $row; } } return null; }
        if ( false !== strpos( $sql, 'id=%d AND lease_token=%s' ) ) { $row = $this->rows[ $args[0] ] ?? null; return $row && $row['lease_token'] === $args[1] ? $row : null; }
        throw new RuntimeException( 'Unexpected SELECT row.' );
    }
    public function query( $query ) {
        [ $sql, $a ] = $this->unpack( $query );
        if ( $this->fail_query ) { $this->last_error = 'fixture storage failure'; return false; }
        if ( 0 === strpos( $sql, 'INSERT INTO fixture_nova_posting_discovery' ) ) {
            $old = $this->discovery[ $a[0] ] ?? [ 'site_id' => $a[0], 'wake_sequence' => 0, 'payload' => '{}' ];
            if ( false !== strpos( $sql, 'wake_sequence=wake_sequence+1' ) ) { ++$old['wake_sequence']; }
            else { $this->guard( $sql, 'ON DUPLICATE KEY UPDATE payload=VALUES(payload),updated_at=VALUES(updated_at)' ); $old['payload'] = $a[1]; }
            $this->discovery[ $a[0] ] = $old; return 1;
        }
        if ( 0 === strpos( $sql, 'INSERT IGNORE INTO fixture_nova_posting_jobs' ) ) {
            foreach ( $this->rows as $row ) { if ( $row['identity'] === $a[0] ) { return 0; } }
            $id = count( $this->rows ) + 1;
            $this->rows[ $id ] = [ 'id' => $id, 'identity' => $a[0], 'site_id' => $a[1], 'content_id' => $a[2], 'version' => $a[3], 'protocol' => $a[4], 'state' => 'ready', 'phase' => 'accepted', 'operation_id' => $a[5], 'payload' => $a[6], 'created_at' => $a[7], 'updated_at' => $a[8], 'identity_conflict' => 0, 'target_id' => 0, 'attempts' => 0, 'next_attempt' => 0, 'lease_token' => '', 'lease_until' => 0, 'last_error' => '' ]; return 1;
        }
        if ( false !== strpos( $sql, 'SET identity_conflict=1' ) ) {
            $this->rows[ $a[1] ] = array_merge( $this->rows[ $a[1] ], [ 'identity_conflict' => 1, 'state' => 'blocked', 'lease_until' => 0, 'last_error' => 'discovery_identity_conflict' ] ); return 1;
        }
        if ( false !== strpos( $sql, "SET state='running',lease_token=" ) ) {
            $this->guard( $sql, "protocol=%s AND identity_conflict=0 AND state IN ('ready','running') AND lease_until < %d" );
            $row = $this->rows[ $a[3] ];
            if ( $row['protocol'] !== $a[4] || $row['identity_conflict'] || ! in_array( $row['state'], [ 'ready', 'running' ], true ) || $row['lease_until'] >= $a[5] ) { return 0; }
            $this->rows[ $a[3] ] = array_merge( $row, [ 'state' => 'running', 'lease_token' => $a[0], 'lease_until' => $a[1], 'attempts' => $row['attempts'] + 1, 'updated_at' => $a[2] ] ); return 1;
        }
        if ( false !== strpos( $sql, 'SET state=%s,phase=%s' ) ) {
            $this->guard( $sql, "identity_conflict=0 AND state='running' AND lease_token=%s AND lease_until >= %d" );
            $this->guard( $sql, 'attempts=%d' );
            $row = $this->rows[ $a[9] ];
            if ( $row['identity_conflict'] || 'running' !== $row['state'] || $row['lease_token'] !== $a[10] || $row['lease_until'] < $a[11] ) { return 0; }
            $this->rows[ $a[9] ] = array_merge( $row, [ 'state' => $a[0], 'phase' => $a[1], 'target_id' => $a[2], 'payload' => $a[3], 'next_attempt' => $a[4], 'last_error' => $a[5], 'lease_until' => $a[6], 'updated_at' => $a[7], 'attempts' => $a[8] ] ); return 1;
        }
        if ( false !== strpos( $sql, 'SET payload=%s,updated_at=%d' ) ) {
            $this->guard( $sql, "identity_conflict=0 AND state<>'complete'" );
            $row = $this->rows[ $a[2] ];
            if ( $row['identity_conflict'] || 'complete' === $row['state'] ) { return 0; }
            $this->rows[ $a[2] ]['payload'] = $a[0]; $this->rows[ $a[2] ]['updated_at'] = $a[1]; return 1;
        }
        if ( false !== strpos( $sql, "phase='attempt_check'" ) ) {
            $this->guard( $sql, "state='complete' AND identity_conflict=0" );
            $row = $this->rows[ $a[2] ];
            if ( 'complete' !== $row['state'] || $row['identity_conflict'] ) { return 0; }
            $this->rows[ $a[2] ] = array_merge( $row, [ 'state' => 'ready', 'phase' => 'attempt_check', 'payload' => $a[0], 'next_attempt' => 0, 'attempts' => 0 ] ); return 1;
        }
        if ( false !== strpos( $sql, "SET state='ready',attempts=0" ) ) {
            $this->guard( $sql, "protocol=%s AND identity_conflict=0 AND state='blocked' AND lease_until=0" );
            $row = $this->rows[ $a[1] ];
            if ( $row['site_id'] !== $a[2] || $row['protocol'] !== $a[3] || $row['identity_conflict'] || 'blocked' !== $row['state'] || $row['lease_until'] ) { return 0; }
            $this->rows[ $a[1] ] = array_merge( $row, [ 'state' => 'ready', 'attempts' => 0, 'next_attempt' => 0, 'last_error' => '' ] ); return 1;
        }
        throw new RuntimeException( 'Unexpected write.' );
    }
}
$checks = 0;
function journal_check( $condition, $message ) { ++$GLOBALS['checks']; if ( ! $condition ) { throw new RuntimeException( $message ); } }
function journal_item( $id = '10' ) { return [ 'id' => '22222222-2222-4222-8222-' . str_pad( $id, 12, '0', STR_PAD_LEFT ), 'content_item_version_id' => '901', 'source_sha256' => str_repeat( 'b', 64 ), 'created_at' => '2026-10-02T12:00:00.123Z' ]; }
$site = '11111111-1111-4111-8111-111111111111';
$db = new Posting_Jobs_Database(); $jobs = new Nova_Bridge_Suite_Posting_Jobs( $db );
$one = $jobs->enqueue_discovered( $site, journal_item() );
journal_check( 1 === $one['id'] && Nova_Bridge_Suite_Posting_Jobs::PROTOCOL === $one['protocol'] && $one['operation_id'] === $one['payload']['business_result_id'], 'Admission stores the exact discovery and one pre-mutation business UUID.' );
$reordered = array_reverse( journal_item(), true ); $replay = $jobs->enqueue_discovered( $site, $reordered );
journal_check( $replay['id'] === $one['id'] && $replay['operation_id'] === $one['operation_id'] && 1 === count( $db->rows ), 'Equivalent object-key ordering reuses the same journal and operation.' );
$claimed = $jobs->claim( $site );
journal_check( 'running' === $claimed['state'] && 1 === $claimed['attempts'] && null === $jobs->claim( $site ), 'One unexpired lease owns the executable row.' );
$saved = $jobs->save( $claimed, [ 'phase' => 'validated' ] );
journal_check( 'validated' === $saved['phase'], 'The owned CAS permits a durable phase transition.' );
foreach ( [ 'site_id', 'content_id', 'version', 'operation_id', 'protocol' ] as $key ) {
    journal_check( is_wp_error( $jobs->save( $saved, [ $key => 'changed' ] ) ), 'Owned updates cannot replace immutable journal identity: ' . $key );
}
foreach ( [ 'protocol', 'business_result_id', 'discovery' ] as $key ) {
    $payload = $saved['payload']; $payload[$key] = 'changed';
    journal_check( is_wp_error( $jobs->save( $saved, [ 'payload' => $payload ] ) ), 'Owned updates cannot replace immutable admission evidence: ' . $key );
}
$imposter = $saved; $imposter['lease_token'] = 'not-the-owner';
journal_check( is_wp_error( $jobs->save( $imposter, [ 'phase' => 'applying' ] ) ), 'Another lease cannot advance the mutation boundary.' );
$db->rows[1]['lease_until'] = time() - 1;
journal_check( is_wp_error( $jobs->save( $saved, [ 'phase' => 'applying' ] ) ), 'An expired owner cannot advance the mutation boundary.' );
$claimed = $jobs->claim( $site );
journal_check( 2 === $claimed['attempts'] && $claimed['lease_token'] !== $saved['lease_token'], 'Recovery claims use a fresh lease token.' );
$changed = journal_item(); $changed['content_item_version_id'] = '902';
journal_check( 'nova_posting_discovery_identity_conflict' === $jobs->enqueue_discovered( $site, $changed )->get_error_code(), 'A different attempt for an existing exact version is quarantined.' );
journal_check( 'blocked' === $db->rows[1]['state'] && journal_item() === json_decode( $db->rows[1]['payload'], true )['discovery'], 'Conflict preserves original evidence and blocks further execution.' );
journal_check( is_wp_error( $jobs->save( $claimed, [ 'phase' => 'applying' ] ) ) && null === $jobs->claim( $site ) && is_wp_error( $jobs->resume( 1, $site ) ), 'Conflicted work cannot be claimed, resumed blindly or advanced by its former owner.' );

foreach ( [ 'content_item_version_id', 'source_sha256', 'created_at' ] as $field ) {
    $probe = new Posting_Jobs_Database(); $store = new Nova_Bridge_Suite_Posting_Jobs( $probe ); $store->enqueue_discovered( $site, journal_item() ); $item = journal_item();
    if ( 'content_item_version_id' === $field ) { $item[$field] = '902'; }
    elseif ( 'source_sha256' === $field ) { $item[$field] = str_repeat( 'c', 64 ); }
    elseif ( 'created_at' === $field ) { $item[$field] = '2026-10-02T12:00:01.123Z'; }
    else { $item[$field] = 'different'; }
    journal_check( is_wp_error( $store->enqueue_discovered( $site, $item ) ) && 1 === $probe->rows[1]['identity_conflict'], 'Immutable rediscovery mismatch is retained: ' . $field );
}

$legacy_db = new Posting_Jobs_Database(); $legacy = new Nova_Bridge_Suite_Posting_Jobs( $legacy_db ); $legacy->enqueue_discovered( $site, journal_item() );
$legacy_db->rows[1]['protocol'] = 'legacy_content_item'; $legacy_db->rows[1]['payload'] = '{}';
journal_check( null === $legacy->claim( $site ) && 'nova_posting_legacy_job_conflict' === $legacy->enqueue_discovered( $site, journal_item() )->get_error_code(), 'Old journal entries are never silently converted or claimed.' );
journal_check( is_wp_error( $legacy->enqueue( [ 'event' => 'content.ready' ] ) ) && 1 === count( $legacy_db->rows ), 'Legacy direct notification intake cannot create work.' );
$legacy_db->rows[1]['state'] = 'blocked';
journal_check( is_wp_error( $legacy->resume( 1, $site ) ), 'Generic operator resume cannot activate a legacy protocol row.' );
$legacy_db->count_override = Nova_Bridge_Suite_Posting_Jobs::MAX_ROWS;
journal_check( 'nova_posting_queue_full' === $legacy->enqueue_discovered( $site, journal_item( '11' ) )->get_error_code(), 'Full journal refuses new identities without evicting recovery evidence.' );

$paused_db = new Posting_Jobs_Database(); $paused = new Nova_Bridge_Suite_Posting_Jobs( $paused_db ); $paused->enqueue_discovered( $site, journal_item() );
journal_check( null === $paused->claim( $site, true ), 'Pause does not claim a new discovered mutation.' );
$paused_db->rows[1]['phase'] = 'event_pending';
journal_check( is_array( $paused->claim( $site, true ) ), 'Pause still permits retained receipt recovery.' );
$before_prune = $paused_db->rows; $paused->prune();
journal_check( $before_prune === $paused_db->rows, 'Full discovery replay cannot lose its acknowledged identity through pruning.' );

$checkpoint_db = new Posting_Jobs_Database(); $checkpoint = new Nova_Bridge_Suite_Posting_Jobs( $checkpoint_db );
$empty = $checkpoint->discovery_state( $site );
journal_check( null === $empty['cursor'] && 0 === $empty['wake_sequence'], 'Uninitialized discovery starts without a cursor.' );
$checkpoint->request_discovery( $site ); $old = $checkpoint->discovery_state( $site );
$checkpoint->request_discovery( $site ); $old['cursor'] = 'saved-cursor'; $old['consumed_sequence'] = 1;
$checkpoint->save_discovery_state( $site, $old ); $next = $checkpoint->discovery_state( $site );
journal_check( 2 === $next['wake_sequence'] && 1 === $next['consumed_sequence'] && 'saved-cursor' === $next['cursor'], 'A checkpoint cannot overwrite a concurrently persisted wake sequence.' );
$checkpoint_db->fail_query = true;
journal_check( is_wp_error( $checkpoint->request_discovery( $site ) ) && is_wp_error( $checkpoint->save_discovery_state( $site, $next ) ), 'Wake and checkpoint storage failure cannot be acknowledged as durable.' );

$retry_db = new Posting_Jobs_Database(); $retry_jobs = new Nova_Bridge_Suite_Posting_Jobs( $retry_db );
$admitted = $retry_jobs->enqueue_discovered( $site, journal_item(), '9007199254740993' );
$retry_db->rows[1]['state'] = 'complete'; $retry_db->rows[1]['phase'] = 'committed';
journal_check( 'complete' === $retry_jobs->enqueue_discovered( $site, journal_item(), '9007199254740993' )['state'], 'A replayed checkpoint does not reopen acknowledged work.' );
journal_check( 'complete' === $retry_jobs->enqueue_discovered( $site, journal_item(), '9' )['state'], 'A lower checkpoint cannot authorize a retry.' );
$announced = $retry_jobs->enqueue_discovered( $site, journal_item(), '9007199254740994' );
journal_check( 'attempt_check' === $announced['phase'] && $admitted['operation_id'] === $announced['operation_id'] && $admitted['payload']['discovery'] === $announced['payload']['discovery'], 'A newer announcement preserves CMS identity and requests only an attempt check.' );
journal_check( is_wp_error( $retry_jobs->enqueue_discovered( $site, journal_item(), 'not-a-checkpoint' ) ), 'An invalid checkpoint cannot reopen work.' );
$flight_db = new Posting_Jobs_Database(); $flight_jobs = new Nova_Bridge_Suite_Posting_Jobs( $flight_db );
$flight_jobs->enqueue_discovered( $site, journal_item(), '9007199254740993' );
$flight = $flight_jobs->claim( $site );
$event_payload = $flight['payload']; $event_payload['event_bytes'] = '{"event_id":"original-id","kind":"publication_failed"}';
$event_payload['native_absence_proof'] = [ 'no_native_commit' => true ];
$flight = $flight_jobs->save( $flight, [ 'phase' => 'event_pending', 'payload' => $event_payload ] );
$announced = $flight_jobs->enqueue_discovered( $site, journal_item(), '9007199254740994' );
journal_check( 'running' === $announced['state'] && 'event_pending' === $announced['phase'] && $flight['lease_token'] === $announced['lease_token'] && $flight['operation_id'] === $announced['operation_id'], 'An in-flight announcement preserves the current owner, event phase and CMS operation.' );
journal_check( '9007199254740993' === $announced['payload']['discovery_checkpoint'] && '9007199254740994' === $announced['payload']['pending_discovery_checkpoint'], 'In-flight announcement stays distinct from the consumed checkpoint.' );
$flight_jobs->enqueue_discovered( $site, journal_item(), '9007199254740995' );
$flight_jobs->enqueue_discovered( $site, journal_item(), '9007199254740994' );
// This worker still holds the payload from before both announcements.
$saved = $flight_jobs->save( $flight, [ 'last_error' => 'ack_retry' ] );
journal_check( '9007199254740995' === $saved['payload']['pending_discovery_checkpoint'] && $event_payload['event_bytes'] === $saved['payload']['event_bytes'], 'Stale worker save merges the newest decimal checkpoint without replacing event bytes.' );
$ack_payload = $saved['payload']; $ack_payload['events'] = [ 'publication_failed' => [ 'accepted' => [ 'status' => 'pending' ], 'bytes' => $event_payload['event_bytes'] ] ];
$terminal = $flight_jobs->save( $saved, [ 'state' => 'complete', 'phase' => 'committed', 'payload' => $ack_payload ] );
journal_check( 'ready' === $terminal['state'] && 'attempt_check' === $terminal['phase'] && 0 === $terminal['attempts'] && 0 === $terminal['next_attempt'] && 0 === $terminal['lease_until'], 'Original failure ACK schedules the retained announcement for an authenticated attempt check.' );
journal_check( '9007199254740995' === $terminal['payload']['discovery_checkpoint'] && ! isset( $terminal['payload']['pending_discovery_checkpoint'] ) && $terminal['payload']['events'] === $ack_payload['events'] && $terminal['payload']['native_absence_proof'] === $event_payload['native_absence_proof'] && $terminal['operation_id'] === $flight['operation_id'], 'Scheduling consumes only the announcement and preserves ACK, absence evidence and operation identity.' );
journal_check( is_wp_error( $flight_jobs->save( $flight, [ 'state' => 'complete' ] ) ), 'The old owner cannot erase the queued attempt check.' );
$checking = $flight_jobs->claim( $site ); $finished = $flight_jobs->save( $checking, [ 'state' => 'complete', 'phase' => 'committed' ] );
journal_check( 'complete' === $finished['state'], 'The checked observation does not cause an endless re-announcement loop.' );

$counter_db = new Posting_Jobs_Database(); $counter_jobs = new Nova_Bridge_Suite_Posting_Jobs( $counter_db ); $counter_jobs->enqueue_discovered( $site, journal_item() );
$counter = $counter_jobs->claim( $site ); $counter = $counter_jobs->save( $counter, [ 'attempts' => 0 ] );
journal_check( 0 === $counter['attempts'] && 0 === $counter_db->rows[1]['attempts'], 'Healthy wait resets persist their failure counter in storage.' );
foreach ( [ -1, '0', 4294967296 ] as $invalid_attempts ) { journal_check( is_wp_error( $counter_jobs->save( $counter, [ 'attempts' => $invalid_attempts ] ) ), 'Invalid retry counters cannot reach SQL.' ); }
$before_lock_failure = $counter_db->rows; $counter_db->deny_lock = true;
journal_check( is_wp_error( $counter_jobs->save( $counter, [ 'state' => 'complete' ] ) ) && $before_lock_failure === $counter_db->rows, 'Worker save fails closed when admission coordination is unavailable.' );

echo 'PASS ' . $checks . " contracted journal checks.\n";
