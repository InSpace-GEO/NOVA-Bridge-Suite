<?php
/** Durable, private delivery journal. Unfinished work is never removed by retention. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Nova_Bridge_Suite_Posting_Jobs {
    public const MAX_ROWS = 10000;
    public const MAX_PAYLOAD_BYTES = 2097152;
    public const LEASE_SECONDS = 300;
    private $db;
    private $table;

    public function __construct( $database = null ) {
        global $wpdb;
        $this->db = $database ?: $wpdb;
        $this->table = $this->db->prefix . 'nova_posting_jobs';
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
        return $this->db->get_var( $this->db->prepare( 'SHOW TABLES LIKE %s', $this->db->esc_like( $this->table ) ) ) === $this->table;
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

    public function enqueue( array $event ) {
        $identity = hash( 'sha256', $event['site'] . ':' . $event['content_id'] . ':' . $event['version'] );
        $existing = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table} WHERE identity = %s", $identity ), ARRAY_A );
        if ( $existing ) { return $this->decode( $existing ); }
        // Serialize admission so the finite limit also holds for distinct concurrent events.
        $lock = $this->lock( 'admission' );
        if ( is_wp_error( $lock ) ) { return $lock; }
        try {
            $existing = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table} WHERE identity = %s", $identity ), ARRAY_A );
            if ( $existing ) { return $this->decode( $existing ); }
            $count = $this->db->get_var( "SELECT COUNT(*) FROM {$this->table}" );
            if ( null === $count ) { return $this->error(); }
            if ( (int) $count >= self::MAX_ROWS ) { return $this->error( 'queue_full' ); }
            $now = time();
            $inserted = $this->db->query( $this->db->prepare(
                "INSERT IGNORE INTO {$this->table} (identity,site_id,content_id,version,state,phase,operation_id,payload,created_at,updated_at) VALUES (%s,%s,%s,%d,'ready','accepted',%s,'{}',%d,%d)",
                $identity, $event['site'], $event['content_id'], $event['version'], wp_generate_uuid4(), $now, $now
            ) );
            if ( false === $inserted ) { return $this->error(); }
            $row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table} WHERE identity = %s", $identity ), ARRAY_A );
            return $row ? $this->decode( $row ) : $this->error();
        } finally { $this->unlock( 'admission' ); }
    }

    /** A single CAS winner owns the lease; an expired applying phase remains applying. */
    public function claim( string $site_id, bool $paused = false ) {
        $now = time();
        $phase_filter = $paused ? " AND phase IN ('applying','committed','receipt_pending')" : '';
        $id = $this->db->get_var( $this->db->prepare( "SELECT id FROM {$this->table} WHERE site_id = %s AND state IN ('ready','running') AND next_attempt <= %d AND lease_until < %d{$phase_filter} ORDER BY id LIMIT 1", $site_id, $now, $now ) );
        if ( ! $id ) { return ! empty( $this->db->last_error ) ? $this->error() : null; }
        $token = wp_generate_uuid4();
        $changed = $this->db->query( $this->db->prepare( "UPDATE {$this->table} SET state='running',lease_token=%s,lease_until=%d,attempts=attempts+1,updated_at=%d WHERE id=%d AND state IN ('ready','running') AND lease_until < %d", $token, $now + self::LEASE_SECONDS, $now, $id, $now ) );
        if ( false === $changed ) { return $this->error(); }
        if ( 1 !== $changed ) { return null; }
        return $this->decode( $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table} WHERE id=%d AND lease_token=%s", $id, $token ), ARRAY_A ) );
    }

    /** Every durable transition must still own its unexpired lease. */
    public function save( array $job, array $changes ) {
        $next = array_merge( $job, $changes );
        $payload = wp_json_encode( $next['payload'], JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $payload ) || strlen( $payload ) > self::MAX_PAYLOAD_BYTES ) { return $this->error( 'journal_size' ); }
        if ( ! in_array( $next['state'], [ 'ready', 'running', 'blocked', 'complete' ], true ) || ! in_array( $next['phase'], [ 'accepted', 'validated', 'planned', 'applying', 'committed', 'receipt_pending' ], true ) ) { return $this->error( 'journal_state' ); }
        $now = time();
        $lease = 'running' === $next['state'] ? $now + self::LEASE_SECONDS : 0;
        $changed = $this->db->query( $this->db->prepare(
            "UPDATE {$this->table} SET state=%s,phase=%s,target_id=%d,payload=%s,next_attempt=%d,last_error=%s,lease_until=%d,updated_at=%d,journal_revision=journal_revision+1 WHERE id=%d AND state='running' AND lease_token=%s AND lease_until >= %d",
            $next['state'], $next['phase'], $next['target_id'], $payload, $next['next_attempt'], $next['last_error'], $lease, $now, $job['id'], $job['lease_token'], $now
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

    public function newer_committed( array $job ) {
        $id = $this->db->get_var( $this->db->prepare( "SELECT id FROM {$this->table} WHERE site_id=%s AND content_id=%s AND version>%d AND phase IN ('committed','receipt_pending') LIMIT 1", $job['site_id'], $job['content_id'], $job['version'] ) );
        return ! empty( $this->db->last_error ) ? $this->error() : (bool) $id;
    }

    public function uncertain_target( array $job ) {
        $id = $this->db->get_var( $this->db->prepare( "SELECT id FROM {$this->table} WHERE id<>%d AND site_id=%s AND phase='applying' AND (content_id=%s OR (target_id>0 AND target_id=%d)) LIMIT 1", $job['id'], $job['site_id'], $job['content_id'], $job['target_id'] ) );
        return ! empty( $this->db->last_error ) ? $this->error() : (bool) $id;
    }

    public function resume( int $id, string $site_id ) {
        $changed = $this->db->query( $this->db->prepare( "UPDATE {$this->table} SET state='ready',attempts=0,next_attempt=0,last_error='',updated_at=%d WHERE id=%d AND site_id=%s AND state='blocked' AND lease_until=0", time(), $id, $site_id ) );
        return 1 === $changed ? true : new WP_Error( 'nova_posting_resume_conflict', 'Only a blocked delivery for this installation can be resumed.', [ 'status' => 409 ] );
    }

    public function summaries( string $site_id ) {
        $rows = $this->db->get_results( $this->db->prepare( "SELECT id,content_id,version,state,phase,target_id,attempts,next_attempt,last_error,created_at,updated_at FROM {$this->table} WHERE site_id=%s ORDER BY id DESC LIMIT 100", $site_id ), ARRAY_A );
        return is_array( $rows ) && empty( $this->db->last_error ) ? $rows : $this->error();
    }

    public function prune(): void {
        // Completed rows with newer retained versions are safe to prune: the survivor keeps the ordering floor.
        $this->db->query( $this->db->prepare( "DELETE old FROM {$this->table} old INNER JOIN {$this->table} newer ON newer.site_id=old.site_id AND newer.content_id=old.content_id AND newer.version>old.version AND newer.phase IN ('committed','receipt_pending') WHERE old.state='complete' AND old.updated_at<%d", time() - 90 * DAY_IN_SECONDS ) );
    }
}
