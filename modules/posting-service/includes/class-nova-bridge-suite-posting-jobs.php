<?php
/** Durable, private delivery journal. Unfinished work is never removed by retention. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Nova_Bridge_Suite_Posting_Jobs {
    public const PROTOCOL = 'nova.delivery-snapshot/v1';
    public const MAX_ROWS = 10000;
    public const MAX_PAYLOAD_BYTES = 2097152;
    public const LEASE_SECONDS = 300;
    private $db;
    private $table;
    private $discovery_table;

    public function __construct( $database = null ) {
        global $wpdb;
        $this->db = $database ?: $wpdb;
        $this->table = $this->db->prefix . 'nova_posting_jobs';
        $this->discovery_table = $this->db->prefix . 'nova_posting_discovery';
    }

    public function install() {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $collation = $this->db->get_charset_collate();
        dbDelta( "CREATE TABLE {$this->table} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            identity char(64) NOT NULL,
            site_id varchar(64) NOT NULL,
            content_id varchar(64) NOT NULL,
            version bigint unsigned NOT NULL,
            protocol varchar(64) NOT NULL DEFAULT 'legacy_content_item',
            identity_conflict tinyint unsigned NOT NULL DEFAULT 0,
            state varchar(24) NOT NULL,
            phase varchar(24) NOT NULL,
            target_id bigint unsigned NOT NULL DEFAULT 0,
            operation_id varchar(64) NOT NULL,
            attempts int unsigned NOT NULL DEFAULT 0,
            next_attempt bigint unsigned NOT NULL DEFAULT 0,
            lease_token varchar(64) NOT NULL DEFAULT '',
            lease_until bigint unsigned NOT NULL DEFAULT 0,
            journal_revision bigint unsigned NOT NULL DEFAULT 0,
            last_error varchar(100) NOT NULL DEFAULT '',
            payload longtext NOT NULL,
            created_at bigint unsigned NOT NULL,
            updated_at bigint unsigned NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY identity (identity),
            KEY pending (state,next_attempt,lease_until),
            KEY target_version (site_id,content_id,target_id,version)
        ) ENGINE=InnoDB {$collation};" );
        dbDelta( "CREATE TABLE {$this->discovery_table} (
            site_id varchar(64) NOT NULL,
            wake_sequence bigint unsigned NOT NULL DEFAULT 0,
            payload longtext NOT NULL,
            updated_at bigint unsigned NOT NULL,
            PRIMARY KEY  (site_id)
        ) ENGINE=InnoDB {$collation};" );
        foreach ( [ $this->table, $this->discovery_table ] as $table ) {
            if ( $this->db->get_var( $this->db->prepare( 'SHOW TABLES LIKE %s', $this->db->esc_like( $table ) ) ) !== $table ) { return false; }
        }
        foreach ( [ 'protocol', 'identity_conflict' ] as $column ) {
            if ( $this->db->get_var( $this->db->prepare( "SHOW COLUMNS FROM {$this->table} LIKE %s", $column ) ) !== $column ) { return false; }
        }
        return true;
    }

    /** Prevent changing installation scope/origin while any accepted work still needs recovery. */
    public static function has_unfinished_for_site( string $site_id ) {
        if ( '' === $site_id ) { return false; }
        $store = new self();
        $exists = $store->db->get_var( $store->db->prepare( 'SHOW TABLES LIKE %s', $store->db->esc_like( $store->table ) ) );
        if ( ! empty( $store->db->last_error ) ) { return $store->error(); }
        if ( null === $exists ) { return false; }
        $count = $store->db->get_var( $store->db->prepare( "SELECT COUNT(*) FROM {$store->table} WHERE site_id=%s AND state<>'complete'", $site_id ) );
        return null === $count || ! empty( $store->db->last_error ) ? $store->error() : (int) $count > 0;
    }

    private function error( string $code = 'storage' ): WP_Error {
        return new WP_Error( 'nova_posting_' . $code, 'The delivery journal could not safely complete this operation.', [ 'status' => 503 ] );
    }

    private function decode( $row ) {
        if ( ! is_array( $row ) ) { return null; }
        $payload = json_decode( $row['payload'], true );
        if ( ! is_array( $payload ) ) { return $this->error( 'journal_invalid' ); }
        $row['payload'] = $payload;
        foreach ( [ 'id', 'version', 'target_id', 'attempts', 'next_attempt', 'lease_until', 'created_at', 'updated_at' ] as $key ) { $row[ $key ] = (int) $row[ $key ]; }
        return $row;
    }

    /** Signed notifications are wake hints. They cannot create executable legacy work. */
    public function enqueue( array $event ) {
        return new WP_Error( 'nova_posting_legacy_intake_disabled', 'Discover the contracted snapshot before accepting delivery work.', [ 'status' => 409 ] );
    }

    public function enqueue_discovered( string $site_id, array $item, ?string $checkpoint = null ) {
        $valid = Nova_Bridge_Suite_Posting_Discovery::validate_item( $item );
        if ( is_wp_error( $valid ) ) { return $valid; }
        if ( ! Nova_Bridge_Suite_Posting_Discovery::valid_site( $site_id ) ) { return $this->error( 'discovery_site' ); }
        if ( null !== $checkpoint && ! Nova_Bridge_Suite_Posting_Protocol::decimal_id( $checkpoint ) ) { return $this->error( 'discovery_checkpoint' ); }
        $identity = hash( 'sha256', self::PROTOCOL . ':' . $site_id . ':' . $item['id'] );
        // Serialize admission so the finite limit also holds for distinct concurrent events.
        $lock = $this->lock( 'admission' );
        if ( is_wp_error( $lock ) ) { return $lock; }
        try {
            $existing = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table} WHERE identity = %s", $identity ), ARRAY_A );
            if ( ! empty( $this->db->last_error ) ) { return $this->error(); }
            if ( $existing ) {
                $job = $this->decode( $existing );
                if ( is_wp_error( $job ) ) { return $job; }
                if ( self::PROTOCOL !== ( $job['protocol'] ?? '' ) || self::PROTOCOL !== ( $job['payload']['protocol'] ?? '' ) ) {
                    return new WP_Error( 'nova_posting_legacy_job_conflict', 'An older protocol journal owns this identity; reconcile it explicitly.', [ 'status' => 409 ] );
                }
                $retained = $job['payload']['discovery'] ?? null;
                $unified_observation = self::legacy_destination_observation( $retained, $item );
                if ( ! empty( $job['identity_conflict'] ) || ( ! self::same_value( $retained, $item ) && ! $unified_observation ) ) {
                    // Never replace retained evidence. A current owner loses its next journal CAS.
                    $changed = $this->db->query( $this->db->prepare( "UPDATE {$this->table} SET identity_conflict=1,state='blocked',lease_until=0,last_error='discovery_identity_conflict',updated_at=%d WHERE id=%d", time(), $job['id'] ) );
                    if ( false === $changed ) { return $this->error(); }
                    return new WP_Error( 'nova_posting_discovery_identity_conflict', 'Discovery disagrees with the retained exact snapshot identity. Reconciliation is required.', [ 'status' => 409 ] );
                }
                if ( 'superseded' === $job['phase'] || isset( $job['payload']['retired'] ) ) { return $job; }
                if ( self::has_publication_receipt( $job ) ) {
                    if ( 'complete' === $job['state'] && 'committed' === $job['phase'] ) { return $job; }
                    // Older versions reopened acknowledged successes during discovery. Restore
                    // only their idle attempt check; never supersede a worker or another failure.
                    if ( 'attempt_check' === $job['phase'] && 0 === $job['lease_until'] &&
                        ( 'ready' === $job['state'] || ( 'blocked' === $job['state'] && 'remote_identity_conflict' === $job['last_error'] ) ) ) {
                        $changed = $this->db->query( $this->db->prepare( "UPDATE {$this->table} SET state='complete',phase='committed',next_attempt=0,last_error='',updated_at=%d,journal_revision=journal_revision+1 WHERE id=%d AND protocol=%s AND identity_conflict=0 AND state=%s AND phase='attempt_check' AND last_error=%s AND lease_until=0", time(), $job['id'], self::PROTOCOL, $job['state'], $job['last_error'] ) );
                        if ( false === $changed ) { return $this->error(); }
                        if ( 1 === $changed ) { $job['state'] = 'complete'; $job['phase'] = 'committed'; $job['next_attempt'] = 0; $job['last_error'] = ''; }
                        return $job;
                    }
                }
                // A new announcement may be an operator retry. Only GET's attempt header
                // decides that; the checkpoint never authorizes a CMS write.
                // The retired V3 scan counter and the unified feed cursor are different
                // sequences. Start the new sequence without replacing identity or receipts.
                if ( $unified_observation && null !== $checkpoint && 'unified' !== ( $job['payload']['discovery_feed'] ?? null ) ) {
                    $job['payload']['discovery_feed'] = 'unified';
                    $job['payload']['discovery_checkpoint'] = null;
                    unset( $job['payload']['pending_discovery_checkpoint'] );
                }
                $previous_checkpoint = $job['payload']['pending_discovery_checkpoint'] ?? ( $job['payload']['discovery_checkpoint'] ?? null );
                $new_checkpoint = self::newer_checkpoint( $checkpoint, $previous_checkpoint );
                if ( $new_checkpoint && 'complete' === $job['state'] ) {
                    $job['payload']['discovery_checkpoint'] = $checkpoint;
                    unset( $job['payload']['pending_discovery_checkpoint'] );
                    $json = wp_json_encode( $job['payload'], JSON_UNESCAPED_SLASHES );
                    if ( ! is_string( $json ) || strlen( $json ) > self::MAX_PAYLOAD_BYTES ) { return $this->error( 'journal_size' ); }
                    $changed = $this->db->query( $this->db->prepare( "UPDATE {$this->table} SET state='ready',phase='attempt_check',payload=%s,next_attempt=0,attempts=0,last_error='',updated_at=%d WHERE id=%d AND state='complete' AND identity_conflict=0", $json, time(), $job['id'] ) );
                    if ( false === $changed ) { return $this->error(); }
                    if ( 1 === $changed ) { $job['state'] = 'ready'; $job['phase'] = 'attempt_check'; $job['attempts'] = 0; }
                } elseif ( $new_checkpoint && in_array( $job['state'], [ 'blocked', 'ready' ], true ) && 0 === $job['lease_until'] ) {
                    // Preserve the native phase/evidence. Only authenticated GET may
                    // adopt a different attempt; the feed merely makes this check due.
                    $job['payload']['pending_discovery_checkpoint'] = $checkpoint;
                    $json = wp_json_encode( $job['payload'], JSON_UNESCAPED_SLASHES );
                    if ( ! is_string( $json ) || strlen( $json ) > self::MAX_PAYLOAD_BYTES ) { return $this->error( 'journal_size' ); }
                    $changed = $this->db->query( $this->db->prepare( "UPDATE {$this->table} SET state='ready',payload=%s,next_attempt=0,attempts=0,last_error='',updated_at=%d,journal_revision=journal_revision+1 WHERE id=%d AND protocol=%s AND identity_conflict=0 AND state=%s AND lease_until=0", $json, time(), $job['id'], self::PROTOCOL, $job['state'] ) );
                    if ( false === $changed ) { return $this->error(); }
                    if ( 1 === $changed ) { $job['state'] = 'ready'; $job['next_attempt'] = 0; $job['attempts'] = 0; $job['last_error'] = ''; }
                } elseif ( $new_checkpoint ) {
                    // Keep an announcement arriving before the current attempt's final ACK.
                    // Worker saves share admission's lock and merge this key from storage.
                    $job['payload']['pending_discovery_checkpoint'] = $checkpoint;
                    $json = wp_json_encode( $job['payload'], JSON_UNESCAPED_SLASHES );
                    if ( ! is_string( $json ) || strlen( $json ) > self::MAX_PAYLOAD_BYTES ) { return $this->error( 'journal_size' ); }
                    $changed = $this->db->query( $this->db->prepare( "UPDATE {$this->table} SET payload=%s,updated_at=%d WHERE id=%d AND identity_conflict=0 AND state<>'complete'", $json, time(), $job['id'] ) );
                    if ( false === $changed ) { return $this->error(); }
                }
                return $job;
            }
            $count = $this->db->get_var( "SELECT COUNT(*) FROM {$this->table}" );
            if ( null === $count ) { return $this->error(); }
            if ( (int) $count >= self::MAX_ROWS ) { return $this->error( 'queue_full' ); }
            $now = time();
            $operation_id = strtolower( wp_generate_uuid4() );
            $payload = wp_json_encode( [ 'protocol' => self::PROTOCOL, 'discovery' => $item, 'business_result_id' => $operation_id, 'discovery_checkpoint' => $checkpoint ], JSON_UNESCAPED_SLASHES );
            if ( ! is_string( $payload ) || strlen( $payload ) > self::MAX_PAYLOAD_BYTES ) { return $this->error( 'journal_size' ); }
            $inserted = $this->db->query( $this->db->prepare(
                "INSERT IGNORE INTO {$this->table} (identity,site_id,content_id,version,protocol,state,phase,operation_id,payload,created_at,updated_at) VALUES (%s,%s,%s,%d,%s,'ready','accepted',%s,%s,%d,%d)",
                // Existing columns retain their layout: content_id now stores the delivery UUID.
                $identity, $site_id, $item['id'], 1, self::PROTOCOL, $operation_id, $payload, $now, $now
            ) );
            if ( false === $inserted ) { return $this->error(); }
            $row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table} WHERE identity = %s", $identity ), ARRAY_A );
            return $row ? $this->decode( $row ) : $this->error();
        } finally { $this->unlock( 'admission' ); }
    }

    private static function newer_checkpoint( ?string $next, ?string $previous ): bool {
        return null !== $next && ( null === $previous || strlen( $next ) > strlen( $previous ) || ( strlen( $next ) === strlen( $previous ) && strcmp( $next, $previous ) > 0 ) );
    }

    /** The authenticated unified feed omits private adaptation metadata; retain it locally. */
    private static function legacy_destination_observation( $retained, array $item ): bool {
        if ( ! is_array( $retained ) || Nova_Bridge_Suite_Posting_Protocol::DESTINATION !== ( $retained['contract'] ?? null ) || isset( $item['contract'] ) || is_wp_error( Nova_Bridge_Suite_Posting_Discovery::validate_item( $retained ) ) || is_wp_error( Nova_Bridge_Suite_Posting_Discovery::validate_item( $item ) ) ) { return false; }
        foreach ( [ 'id', 'content_item_version_id', 'source_sha256' ] as $key ) { if ( $retained[$key] !== $item[$key] ) { return false; } }
        return true;
    }

    /** A terminal journal can contain a failure; only an acknowledged success for this attempt is final. */
    private static function has_publication_receipt( array $job ): bool {
        $payload = $job['payload']; $receipt = $payload['events']['publication_succeeded'] ?? [];
        $bytes = $receipt['bytes'] ?? null; $digest = $receipt['sha256'] ?? null;
        if ( ! is_string( $bytes ) || ! is_string( $digest ) || ! hash_equals( hash( 'sha256', $bytes ), $digest ) ) { return false; }
        $event = json_decode( $bytes, true );
        if ( ! is_array( $event ) || 'publication_succeeded' !== ( $event['kind'] ?? '' ) || is_wp_error( Nova_Bridge_Suite_Posting_Protocol::event_input( $event ) ) ) { return false; }
        $discovery = $payload['discovery'] ?? []; $snapshot = $payload['content'] ?? [];
        foreach ( [ 'site_id' => $job['site_id'], 'delivery_id' => $job['content_id'],
            'content_item_version_id' => $discovery['content_item_version_id'] ?? null,
            'source_sha256' => $discovery['source_sha256'] ?? null, 'version_number' => $snapshot['version_number'] ?? null,
            'attempt_id' => $payload['attempt_id'] ?? null, 'delivery_etag' => $payload['delivery_etag'] ?? null ] as $key => $expected ) {
            if ( $expected === null || ( $event[$key] ?? null ) !== $expected ) { return false; }
        }
        if ( ( $discovery['id'] ?? null ) !== $event['delivery_id'] ) { return false; }
        foreach ( [ 'id' => 'delivery_id', 'site_id' => 'site_id', 'content_item_version_id' => 'content_item_version_id', 'source_sha256' => 'source_sha256' ] as $key => $event_key ) {
            if ( ( $snapshot[$key] ?? null ) !== $event[$event_key] ) { return false; }
        }
        $accepted = wp_json_encode( $receipt['accepted'] ?? null );
        return is_string( $accepted ) && ! is_wp_error( Nova_Bridge_Suite_Posting_Protocol::accepted( [ 'status' => 202, 'raw_body' => $accepted ], $event ) );
    }

    private static function same_value( $left, $right ): bool {
        if ( gettype( $left ) !== gettype( $right ) ) { return false; }
        if ( ! is_array( $left ) ) { return $left === $right; }
        if ( count( $left ) !== count( $right ) ) { return false; }
        $list = [] === $left || array_keys( $left ) === range( 0, count( $left ) - 1 );
        if ( $list && array_keys( $left ) !== array_keys( $right ) ) { return false; }
        foreach ( $left as $key => $value ) { if ( ! array_key_exists( $key, $right ) || ! self::same_value( $value, $right[ $key ] ) ) { return false; } }
        return true;
    }

    /** Caller holds discovery:<site> lock. Wakes increment separately and cannot be overwritten. */
    public function discovery_state( string $site_id ) {
        $row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->discovery_table} WHERE site_id=%s", $site_id ), ARRAY_A );
        if ( ! empty( $this->db->last_error ) ) { return $this->error(); }
        $state = $row ? json_decode( $row['payload'], true ) : [];
        if ( ! is_array( $state ) ) { return $this->error( 'discovery_journal' ); }
        $state = array_merge( [ 'cursor' => null, 'cycle_started' => 0, 'last_completed' => 0, 'next_attempt' => 0, 'consumed_sequence' => 0, 'failures' => 0, 'last_error' => '', 'connection_key' => '', 'suspended' => false, 'auth_status' => 0 ], $state );
        $state['wake_sequence'] = (int) ( $row['wake_sequence'] ?? 0 );
        return $state;
    }

    public function save_discovery_state( string $site_id, array $state ) {
        unset( $state['wake_sequence'] );
        $json = wp_json_encode( $state, JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $json ) || strlen( $json ) > 65536 ) { return $this->error( 'discovery_journal' ); }
        $saved = $this->db->query( $this->db->prepare( "INSERT INTO {$this->discovery_table} (site_id,wake_sequence,payload,updated_at) VALUES (%s,0,%s,%d) ON DUPLICATE KEY UPDATE payload=VALUES(payload),updated_at=VALUES(updated_at)", $site_id, $json, time() ) );
        return false === $saved ? $this->error() : true;
    }

    public function request_discovery( string $site_id ) {
        if ( ! Nova_Bridge_Suite_Posting_Discovery::valid_site( $site_id ) ) { return $this->error( 'discovery_site' ); }
        $saved = $this->db->query( $this->db->prepare( "INSERT INTO {$this->discovery_table} (site_id,wake_sequence,payload,updated_at) VALUES (%s,1,'{}',%d) ON DUPLICATE KEY UPDATE wake_sequence=wake_sequence+1,updated_at=VALUES(updated_at)", $site_id, time() ) );
        return false === $saved ? $this->error() : true;
    }

    /** A single CAS winner owns the lease; an expired applying phase remains applying. */
    public function claim( string $site_id, bool $paused = false ) {
        $now = time();
        $phase_filter = $paused ? " AND phase IN ('applying','committed','event_pending')" : '';
        // Rechecking historical V3 results must not consume the worker budget before new deliveries or pending receipts.
        $id = $this->db->get_var( $this->db->prepare( "SELECT id FROM {$this->table} WHERE site_id = %s AND protocol=%s AND identity_conflict=0 AND state IN ('ready','running') AND next_attempt <= %d AND lease_until < %d{$phase_filter} ORDER BY CASE WHEN phase='attempt_check' THEN 1 ELSE 0 END,id LIMIT 1", $site_id, self::PROTOCOL, $now, $now ) );
        if ( ! $id ) { return ! empty( $this->db->last_error ) ? $this->error() : null; }
        $token = wp_generate_uuid4();
        $changed = $this->db->query( $this->db->prepare( "UPDATE {$this->table} SET state='running',lease_token=%s,lease_until=%d,attempts=attempts+1,updated_at=%d WHERE id=%d AND protocol=%s AND identity_conflict=0 AND state IN ('ready','running') AND lease_until < %d", $token, $now + self::LEASE_SECONDS, $now, $id, self::PROTOCOL, $now ) );
        if ( false === $changed ) { return $this->error(); }
        if ( 1 !== $changed ) { return null; }
        return $this->decode( $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table} WHERE id=%d AND lease_token=%s", $id, $token ), ARRAY_A ) );
    }

    /** Every durable transition must still own its unexpired lease. */
    public function save( array $job, array $changes ) {
        $owned = $this->lock( 'admission' );
        if ( is_wp_error( $owned ) ) { return $owned; }
        try { return $this->save_owned( $job, $changes ); }
        finally { $this->unlock( 'admission' ); }
    }

    private function save_owned( array $job, array $changes ) {
        $next = array_merge( $job, $changes );
        foreach ( [ 'id', 'identity', 'site_id', 'content_id', 'version', 'operation_id', 'protocol' ] as $key ) {
            if ( ( $next[ $key ] ?? null ) !== ( $job[ $key ] ?? null ) ) { return $this->error( 'journal_identity' ); }
        }
        foreach ( [ 'protocol', 'business_result_id', 'discovery' ] as $key ) {
            if ( ! self::same_value( $next['payload'][ $key ] ?? null, $job['payload'][ $key ] ?? null ) ) { return $this->error( 'journal_identity' ); }
        }
        $stored = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table} WHERE id=%d AND lease_token=%s", $job['id'], $job['lease_token'] ), ARRAY_A );
        if ( ! empty( $this->db->last_error ) ) { return $this->error(); }
        $stored = $this->decode( $stored );
        if ( is_wp_error( $stored ) ) { return $stored; }
        if ( ! $stored || 'running' !== $stored['state'] || ! empty( $stored['identity_conflict'] ) || $stored['lease_until'] < time() ) { return $this->error( 'lease_lost' ); }
        // Only admission owns these observations. An older worker payload cannot erase
        // a concurrently discovered attempt hint or fabricate an ordering authority.
        foreach ( [ 'discovery_checkpoint', 'pending_discovery_checkpoint', 'discovery_feed' ] as $key ) {
            unset( $next['payload'][ $key ] );
            if ( array_key_exists( $key, $stored['payload'] ) ) { $next['payload'][ $key ] = $stored['payload'][ $key ]; }
        }
        if ( 'complete' === $next['state'] && self::newer_checkpoint( $next['payload']['pending_discovery_checkpoint'] ?? null, $next['payload']['discovery_checkpoint'] ?? null ) ) {
            $next['payload']['discovery_checkpoint'] = $next['payload']['pending_discovery_checkpoint'];
            unset( $next['payload']['pending_discovery_checkpoint'] );
            if ( ! isset( $next['payload']['retired'] ) && ! self::has_publication_receipt( $next ) ) {
                $next['state'] = 'ready'; $next['phase'] = 'attempt_check'; $next['next_attempt'] = 0; $next['attempts'] = 0; $next['last_error'] = '';
            }
        }
        if ( ! is_int( $next['attempts'] ) || $next['attempts'] < 0 || $next['attempts'] > 4294967295 ) { return $this->error( 'journal_state' ); }
        $payload = wp_json_encode( $next['payload'], JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $payload ) || strlen( $payload ) > self::MAX_PAYLOAD_BYTES ) { return $this->error( 'journal_size' ); }
        if ( ! in_array( $next['state'], [ 'ready', 'running', 'blocked', 'complete' ], true ) || ! in_array( $next['phase'], [ 'accepted', 'validated', 'received', 'planned', 'applying', 'committed', 'event_pending', 'attempt_check', 'superseded' ], true ) ) { return $this->error( 'journal_state' ); }
        $now = time();
        $lease = 'running' === $next['state'] ? $now + self::LEASE_SECONDS : 0;
        $changed = $this->db->query( $this->db->prepare(
            "UPDATE {$this->table} SET state=%s,phase=%s,target_id=%d,payload=%s,next_attempt=%d,last_error=%s,lease_until=%d,updated_at=%d,attempts=%d,journal_revision=journal_revision+1 WHERE id=%d AND identity_conflict=0 AND state='running' AND lease_token=%s AND lease_until >= %d",
            $next['state'], $next['phase'], $next['target_id'], $payload, $next['next_attempt'], $next['last_error'], $lease, $now, $next['attempts'], $job['id'], $job['lease_token'], $now
        ) );
        if ( 1 !== $changed ) { return $this->error( 'lease_lost' ); }
        $next['lease_until'] = $lease; $next['updated_at'] = $now;
        return $next;
    }

    /** Connection-scoped native lock also serializes target writes across different content jobs. */
    public function lock( string $identity ) {
        $name = 'nova:' . substr( hash( 'sha256', $this->table . ':' . $identity ), 0, 58 );
        $result = $this->db->get_var( $this->db->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) );
        return '1' === (string) $result ? true : $this->error( 'target_busy' );
    }

    public function unlock( string $identity ): void {
        $name = 'nova:' . substr( hash( 'sha256', $this->table . ':' . $identity ), 0, 58 );
        $this->db->get_var( $this->db->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
    }

    public function uncertain_target( array $job ) {
        if ( ( $job['payload']['native_writer'] ?? '' ) === 'static_recipe_v1' ) {
            $id = $this->db->get_var( $this->db->prepare( "SELECT id FROM {$this->table} WHERE id<>%d AND site_id=%s AND phase='applying' AND (content_id=%s OR (JSON_UNQUOTE(JSON_EXTRACT(payload,'$.native_writer'))='static_recipe_v1' AND JSON_UNQUOTE(JSON_EXTRACT(payload,'$.plan.target.target_url'))=%s)) LIMIT 1", $job['id'], $job['site_id'], $job['content_id'], $job['payload']['plan']['target']['target_url'] ) );
            return ! empty( $this->db->last_error ) ? $this->error() : (bool) $id;
        }
        $id = $this->db->get_var( $this->db->prepare( "SELECT id FROM {$this->table} WHERE id<>%d AND site_id=%s AND phase='applying' AND (content_id=%s OR (target_id>0 AND target_id=%d)) LIMIT 1", $job['id'], $job['site_id'], $job['content_id'], $job['target_id'] ) );
        return ! empty( $this->db->last_error ) ? $this->error() : (bool) $id;
    }

    /** Caller holds the common publication lock, across all versions for a URL. */
    public function predecessors( array $job, array $snapshot ) {
        $rows = $this->db->get_results( $this->db->prepare( "SELECT * FROM {$this->table} WHERE id<>%d AND site_id=%s AND protocol=%s AND identity_conflict=0 AND JSON_UNQUOTE(JSON_EXTRACT(payload,'$.content.url_id'))=%s AND (state<>'complete' OR (phase='superseded' AND JSON_EXTRACT(payload,'$.retirement_reconciled') IS NULL)) ORDER BY id LIMIT 51", $job['id'], $job['site_id'], self::PROTOCOL, $snapshot['url_id'] ), ARRAY_A );
        if ( ! is_array( $rows ) || ! empty( $this->db->last_error ) ) { return $this->error(); }
        if ( count( $rows ) > 50 ) { return $this->error( 'predecessor_limit' ); }
        $decoded = [];
        foreach ( $rows as $row ) { $value = $this->decode( $row ); if ( is_wp_error( $value ) ) { return $value; } $decoded[] = $value; }
        return $decoded;
    }

    /** Revoke an old lease only after remote 410 and native reconciliation. */
    public function retire_predecessor( array $job, array $evidence ) {
        $payload = $job['payload'];
        $payload['retired'] = [ 'reason' => 'remote_gone', 'attempt_id' => $payload['attempt_id'] ?? null ];
        $payload['retirement_reconciled'] = $evidence;
        $json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $json ) || strlen( $json ) > self::MAX_PAYLOAD_BYTES ) { return $this->error( 'journal_size' ); }
        $changed = $this->db->query( $this->db->prepare( "UPDATE {$this->table} SET state='complete',phase='superseded',payload=%s,lease_token='',lease_until=0,next_attempt=0,last_error='delivery_superseded',updated_at=%d,journal_revision=journal_revision+1 WHERE id=%d AND site_id=%s AND protocol=%s AND identity_conflict=0 AND journal_revision=%d", $json, time(), $job['id'], $job['site_id'], self::PROTOCOL, (int) ( $job['journal_revision'] ?? 0 ) ) );
        return 1 === $changed ? true : $this->error( 'lease_lost' );
    }

    public function resume( int $id, string $site_id ) {
        $changed = $this->db->query( $this->db->prepare( "UPDATE {$this->table} SET state='ready',attempts=0,next_attempt=0,last_error='',updated_at=%d WHERE id=%d AND site_id=%s AND protocol=%s AND identity_conflict=0 AND state='blocked' AND lease_until=0", time(), $id, $site_id, self::PROTOCOL ) );
        return 1 === $changed ? true : new WP_Error( 'nova_posting_resume_conflict', 'Only a blocked delivery for this installation can be resumed.', [ 'status' => 409 ] );
    }

    public function storage_exists() {
        $exists = $this->db->get_var( $this->db->prepare( 'SHOW TABLES LIKE %s', $this->db->esc_like( $this->table ) ) );
        return ! empty( $this->db->last_error ) ? $this->error() : $exists === $this->table;
    }

    public function summaries( string $site_id ) {
        $rows = $this->db->get_results( $this->db->prepare( "SELECT id,content_id,version,protocol,state,phase,target_id,attempts,next_attempt,last_error,created_at,updated_at FROM {$this->table} WHERE site_id=%s ORDER BY CASE WHEN state='blocked' THEN 0 WHEN state<>'complete' THEN 1 ELSE 2 END,CASE WHEN state<>'complete' THEN id END ASC,id DESC LIMIT 100", $site_id ), ARRAY_A );
        return is_array( $rows ) && empty( $this->db->last_error ) ? $rows : $this->error();
    }

    public function prune(): void {
        // Fresh discovery cycles can replay any released item. Retain contracted identity/outbox
        // evidence until a separate durable tombstone/retention contract exists.
    }
}
