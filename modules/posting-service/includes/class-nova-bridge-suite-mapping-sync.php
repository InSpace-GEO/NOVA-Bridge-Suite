<?php
/** Durable canonical configuration synchronization. Native content is never sent here. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Mapping_Sync {
    private $client;
    private $lock_name;
    private $lock_value;
    private $state_name;
    private $state;

    public function __construct( Nova_Bridge_Suite_Posting_Client $client ) { $this->client = $client; }

    public static function bootstrap(): void {
        add_filter( 'nova_bridge_mapping_catalog', [ __CLASS__, 'catalog' ] );
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ], 1003 );
    }

    public static function register_routes(): void {
        foreach ( [ 'sync' => 'POST', 'sync-state' => 'GET', 'activate' => 'POST' ] as $route => $method ) {
            register_rest_route( 'nova-bridge/v1', '/mapping/' . $route, [ 'methods' => $method, 'callback' => [ __CLASS__, str_replace( '-', '_', $route ) . '_response' ], 'permission_callback' => [ 'Nova_Bridge_Suite_Mapping_Drafts', 'can_admin' ] ] );
        }
    }

    private static function connection(): array { return class_exists( 'Nova_Bridge_Suite_Posting_Settings' ) ? Nova_Bridge_Suite_Posting_Settings::connection() : []; }
    private static function error( string $code, string $message, int $status = 409 ): WP_Error { return Nova_Bridge_Suite_Writing_Adapter::error( $code, $message, $status ); }
    private static function json( $value ): string { return Nova_Bridge_Suite_Writing_Adapter::canonical_json( $value ); }
    private static function key( string $site_id, string $type, int $id ): string { return 'nova_mapping_sync_' . hash( 'sha256', $site_id . ':' . $type . ':' . $id ); }
    public static function state( string $type, int $id, string $site_id ): ?array { $state = get_option( self::key( $site_id, $type, $id ), null ); return is_array( $state ) ? $state : null; }

    public static function catalog( $previous ): array {
        $connection = self::connection();
        if ( empty( $connection['enabled'] ) ) { return [ 'templates' => [] ]; }
        $client = new Nova_Bridge_Suite_Posting_Client( $connection );
        $reply = $client->site_request( 'GET', '/writing/stock-templates' );
        if ( is_wp_error( $reply ) || ! is_array( $reply['body'] ) || count( $reply['body'] ) > 100 ) { return [ 'templates' => [] ]; }
        $templates = [];
        foreach ( $reply['body'] as $record ) {
            if ( ! Nova_Bridge_Suite_Writing_Adapter::template_record( $record ) ) { return [ 'templates' => [] ]; }
            $templates[] = Nova_Bridge_Suite_Writing_Adapter::catalog_template( $record );
        }
        return [ 'templates' => $templates ];
    }

    private static function summary( ?array $state ): array {
        if ( ! $state ) { return [ 'status' => 'not_synced' ]; }
        $result = array_intersect_key( $state, array_flip( [ 'status', 'local_revision', 'site_id', 'updated_at', 'error', 'warnings', 'pending', 'activation' ] ) );
        if ( isset( $result['pending'] ) ) { $result['pending'] = [ 'name' => $result['pending']['name'] ]; }
        foreach ( [ 'template' => 'template_version', 'mapping' => 'mapping_revision_id', 'assignment' => 'assignment_revision_id' ] as $kind => $revision ) {
            if ( isset( $state[ $kind ] ) ) { $record = $state[ $kind ]; $result[ $kind ] = [ 'id' => $record[ $kind . '_id' ], 'revision' => $record[ $revision ], 'etag' => $record['etag'], 'state' => $record['state'] ]; }
        }
        if ( isset( $state['pin'] ) ) { $result['pin'] = [ 'pin_id' => $state['pin']['pin_id'], 'digest' => $state['pin']['digest'] ]; }
        return $result;
    }

    private static function response( array $data ) { $response = rest_ensure_response( $data ); $response->header( 'Cache-Control', 'private, no-store' ); return $response; }

    public static function sync_state_response( $request ) {
        $permission = Nova_Bridge_Suite_Mapping_Drafts::can_admin(); if ( is_wp_error( $permission ) ) { return $permission; }
        $type = $request->get_param( 'reference_type' ); $id = $request->get_param( 'reference_id' );
        if ( ! in_array( $type, [ 'post', 'term' ], true ) || ! ctype_digit( (string) $id ) || (int) $id < 1 ) { return self::error( 'reference', 'Supply a concrete reference.', 400 ); }
        $connection = self::connection(); $state = self::state( $type, (int) $id, $connection['site_id'] ?? '' );
        return self::response( [ 'state' => self::summary( $state ), 'warnings' => $state['warnings'] ?? [] ] );
    }

    public static function sync_response( $request ) { return self::run_response( $request, false ); }
    public static function activate_response( $request ) { return self::run_response( $request, true ); }

    private static function run_response( $request, bool $activate ) {
        $permission = Nova_Bridge_Suite_Mapping_Drafts::can_admin(); if ( is_wp_error( $permission ) ) { return $permission; }
        $connection = self::connection();
        if ( empty( $connection['enabled'] ) ) { return self::error( 'connection_disabled', 'Enable the posting-service connection before synchronizing or activating.', 503 ); }
        $input = $request->get_json_params();
        if ( ! is_array( $input ) || ! is_string( $input['expected_revision'] ?? null ) ) { return self::error( 'revision', 'Supply the saved local draft revision.', 400 ); }
        $read = new WP_REST_Request( 'GET' );
        foreach ( [ 'reference_type', 'reference_id', 'signature' ] as $key ) { $read->set_param( $key, $input[ $key ] ?? null ); }
        $reply = Nova_Bridge_Suite_Mapping_Drafts::get_response( $read );
        if ( is_wp_error( $reply ) ) { return $reply; }
        $data = $reply->get_data(); $draft = $data['draft'];
        $service = new self( new Nova_Bridge_Suite_Posting_Client( $connection ) );
        if ( true === ( $input['resume_pending'] ?? false ) && ! $activate ) {
            $saved = self::state( $input['reference_type'], (int) $input['reference_id'], $connection['site_id'] );
            if ( ! $saved || $saved['local_revision'] !== $input['expected_revision'] ) { return self::error( 'revision', 'The pending synchronization revision changed.' ); }
            $draft = $saved['local'];
        } else {
            if ( ! $draft || $draft['revision'] !== $input['expected_revision'] || $draft['signature'] !== $data['reference']['signature'] ) { return self::error( 'revision', 'Save and reconcile the current local draft before synchronization.' ); }
            $valid = self::validate_live( $draft ); if ( is_wp_error( $valid ) ) { return $valid; }
        }
        $result = $activate ? $service->activate( $draft, [ 'actor_user_id' => (int) ( $connection['actor_user_id'] ?? 0 ) ] ) : $service->synchronize( $draft );
        if ( is_wp_error( $result ) ) { return $result; }
        return self::response( [ 'state' => self::summary( $result ), 'warnings' => $result['warnings'] ?? [] ] );
    }

    public static function validate_live( array $draft ) {
        $entity = Nova_Bridge_Suite_Strategy::entity( $draft['reference_type'], (int) $draft['reference_id'] );
        if ( ! $entity || Nova_Bridge_Suite_Strategy::fingerprint( $entity )['signature'] !== $draft['signature'] ) { return self::error( 'reference_changed', 'The selected native layout has changed. Reconcile and save its local draft.' ); }
        $inventory = array_column( Nova_Bridge_Suite_Strategy::field_inventory( $entity ), null, 'path' );
        foreach ( $draft['target_descriptors'] as $path => $descriptor ) {
            if ( ! isset( $inventory[ $path ] ) ) { return self::error( 'target_missing', 'A selected native target is missing: ' . $path ); }
            foreach ( [ 'route', 'transport', 'request_path', 'builder', 'write_mode', 'acf_key', 'binding', 'source', 'parent_path', 'element', 'logical_field', 'selector_data' ] as $key ) {
                if ( self::json( $descriptor[ $key ] ?? null ) !== self::json( $inventory[ $path ][ $key ] ?? null ) ) { return self::error( 'target_changed', 'A selected native destination changed. Reconcile and save the local draft.' ); }
            }
        }
        return true;
    }

    private function acquire( array $draft ) {
        global $wpdb;
        $this->state_name = self::key( $this->client->site_id(), $draft['reference_type'], (int) $draft['reference_id'] );
        $this->lock_name = $this->state_name . '_lock';
        $old = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $this->lock_name ) );
        $decoded = null === $old ? null : maybe_unserialize( $old );
        if ( is_array( $decoded ) && ( $decoded['expires'] ?? 0 ) > time() ) { return self::error( 'busy', 'Another configuration operation is running. Retry after it finishes.' ); }
        $this->lock_value = maybe_serialize( [ 'token' => wp_generate_uuid4(), 'expires' => time() + 300 ] );
        $changed = null === $old
            ? $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s,%s,'no')", $this->lock_name, $this->lock_value ) )
            : $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s", $this->lock_value, $this->lock_name, $old ) );
        if ( 1 !== $changed ) { $this->lock_value = null; return self::error( 'busy', 'Configuration storage is busy or unavailable.', false === $changed ? 500 : 409 ); }
        $this->state = self::state( $draft['reference_type'], (int) $draft['reference_id'], $this->client->site_id() );
        return true;
    }

    private function owned(): bool {
        global $wpdb;
        $current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $this->lock_name ) );
        return is_string( $this->lock_value ) && $current === $this->lock_value && ( maybe_unserialize( $current )['expires'] ?? 0 ) > time();
    }

    private function release(): void {
        global $wpdb;
        if ( $this->lock_value ) { $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND BINARY option_value = BINARY %s", $this->lock_name, $this->lock_value ) ); wp_cache_delete( $this->lock_name, 'options' ); }
        $this->lock_value = null;
    }

    private function checkpoint() {
        global $wpdb;
        if ( ! $this->owned() ) { return self::error( 'lease_lost', 'Synchronization ownership expired. Retry to recover the last recorded step.' ); }
        $this->state['updated_at'] = gmdate( 'c' );
        // A distinct value makes affected_rows=0 an unambiguous missing/expired lease.
        $this->state['checkpoint_id'] = wp_generate_uuid4();
        $value = maybe_serialize( $this->state ); $expires = (int) maybe_unserialize( $this->lock_value )['expires'];
        $saved = $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name,option_value,autoload) SELECT %s,%s,'no' FROM {$wpdb->options} AS lease WHERE lease.option_name=%s AND BINARY lease.option_value=BINARY %s AND UNIX_TIMESTAMP() < %d",
            $this->state_name, $value, $this->lock_name, $this->lock_value, $expires
        ) );
        if ( 0 === $saved ) {
            // Test ownership in the same statement that writes state; a stale worker cannot
            // overwrite the new owner's checkpoint after its earlier owned() read.
            $saved = $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->options} AS state INNER JOIN {$wpdb->options} AS lease ON lease.option_name=%s SET state.option_value=%s,state.autoload='no' WHERE state.option_name=%s AND BINARY lease.option_value=BINARY %s AND UNIX_TIMESTAMP() < %d",
                $this->lock_name, $value, $this->state_name, $this->lock_value, $expires
            ) );
        }
        if ( false === $saved ) { return self::error( 'storage', 'Cannot retain synchronization progress. Retry the same revision.', 500 ); }
        if ( 1 !== $saved ) { return self::error( 'lease_lost', 'Synchronization ownership changed before progress could be saved.' ); }
        wp_cache_delete( $this->state_name, 'options' ); wp_cache_delete( 'notoptions', 'options' );
        return true;
    }

    /** Every remote mutation is recorded before dispatch; retries replay the identical request. */
    private function step( string $name, string $method, string $path, $body = null, array $headers = [] ) {
        if ( isset( $this->state['steps'][ $name ] ) ) { return $this->state['steps'][ $name ]; }
        $operation = [ 'name' => $name, 'method' => $method, 'path' => $path, 'body' => $body, 'headers' => $headers ];
        if ( isset( $this->state['pending'] ) && self::json( $this->state['pending'] ) !== self::json( $operation ) ) { return self::error( 'pending_conflict', 'A different remote operation is awaiting recovery. Resume the recorded synchronization.' ); }
        $this->state['pending'] = $operation;
        $saved = $this->checkpoint(); if ( is_wp_error( $saved ) ) { return $saved; }
        $reply = $this->client->site_request( $method, '/writing' . $path, $body, $headers );
        if ( is_wp_error( $reply ) && 'PUT' === $method && 409 === ( $reply->get_error_data()['status'] ?? 0 ) ) {
            // A successful PUT can lose its acknowledgement. Accept only the exact intended record.
            $revision_key = 0 === strpos( $path, '/templates/' ) ? 'template_version' : 'assignment_revision_id';
            $read = $this->client->site_request( 'GET', '/writing' . $path . '?' . $revision_key . '=' . rawurlencode( $body['expected_revision_id'] ) );
            if ( ! is_wp_error( $read ) && self::matches_put( $read['body'], $body ) ) { $reply = $read; }
        }
        if ( is_wp_error( $reply ) ) { $this->state['error'] = [ 'code' => $reply->get_error_code(), 'message' => $reply->get_error_message() ]; $this->checkpoint(); return $reply; }
        if ( ! is_array( $reply['body'] ) ) { return self::error( 'response_shape', 'The posting service returned an unsupported configuration response.', 502 ); }
        $this->state['steps'][ $name ] = $reply['body']; unset( $this->state['pending'], $this->state['error'] );
        $saved = $this->checkpoint(); return is_wp_error( $saved ) ? $saved : $reply['body'];
    }

    private static function matches_put( $record, array $body ): bool {
        if ( ! is_array( $record ) ) { return false; }
        foreach ( $body as $key => $value ) { if ( 'expected_revision_id' !== $key && ( ! array_key_exists( $key, $record ) || self::json( $value ) !== self::json( $record[ $key ] ) ) ) { return false; } }
        return ( $record['template_version'] ?? $record['assignment_revision_id'] ?? null ) === $body['expected_revision_id'];
    }

    private function idempotency( string $step ): array { return [ 'Idempotency-Key' => 'nova-' . hash( 'sha256', $this->client->site_id() . ':' . $this->state['local_revision'] . ':' . $step ) ]; }

    public function synchronize( array $draft ) {
        $locked = $this->acquire( $draft ); if ( is_wp_error( $locked ) ) { return $locked; }
        try {
            if ( $this->state && $this->state['local_revision'] !== $draft['revision'] && isset( $this->state['pending'] ) ) { return self::error( 'previous_pending', 'Resume the pending synchronization before sending the newer local draft.' ); }
            if ( ! $this->state || $this->state['local_revision'] !== $draft['revision'] ) {
                $previous = $this->state['assignment'] ?? null;
                $this->state = [ 'status' => 'pending', 'site_id' => $this->client->site_id(), 'local_revision' => $draft['revision'], 'local' => $draft, 'steps' => [], 'warnings' => [], 'previous_assignment' => $previous ];
                $saved = $this->checkpoint(); if ( is_wp_error( $saved ) ) { return $saved; }
            }
            if ( in_array( $this->state['status'], [ 'synced_draft', 'active', 'sealed' ], true ) ) { return $this->state; }
            if ( ! isset( $this->state['prepared'] ) ) {
                $stocks = $this->client->site_request( 'GET', '/writing/stock-templates' ); if ( is_wp_error( $stocks ) ) { return $stocks; }
                $source = null;
                foreach ( is_array( $stocks['body'] ) ? $stocks['body'] : [] as $record ) { if ( ( $record['template_id'] ?? null ) === $draft['template']['id'] && ( $record['template_version'] ?? null ) === $draft['template']['revision'] ) { $source = $record; break; } }
                if ( ! $source ) {
                    if ( ! Nova_Bridge_Suite_Posting_Client::id( $draft['template']['id'] ) || ! Nova_Bridge_Suite_Posting_Client::id( $draft['template']['revision'] ) ) { return self::error( 'preview', 'Choose a canonical NOVA template before synchronizing.' ); }
                    $reply = $this->client->site_request( 'GET', '/writing/templates/' . $draft['template']['id'] . '?template_version=' . $draft['template']['revision'] ); if ( is_wp_error( $reply ) ) { return $reply; } $source = $reply['body'];
                }
                $prepared = Nova_Bridge_Suite_Writing_Adapter::prepare( $draft, $source ); if ( is_wp_error( $prepared ) ) { return $prepared; }
                $this->state['prepared'] = $prepared; $this->state['warnings'] = $prepared['warnings'];
                $saved = $this->checkpoint(); if ( is_wp_error( $saved ) ) { return $saved; }
            }
            $prepared = $this->state['prepared'];
            $cloned = $this->step( 'clone_template', 'POST', '/template-clones', [ 'source_template_id' => $draft['template']['id'], 'source_template_version' => $draft['template']['revision'], 'authoring_notes' => $draft['guidance'] ], $this->idempotency( 'clone_template' ) ); if ( is_wp_error( $cloned ) ) { return $cloned; }
            if ( ! Nova_Bridge_Suite_Writing_Adapter::template_record( $cloned ) ) { return self::error( 'response_shape', 'The cloned template record is invalid.', 502 ); }
            $template = $this->step( 'replace_template', 'PUT', '/templates/' . $cloned['template_id'], array_merge( [ 'expected_revision_id' => $cloned['template_version'] ], $prepared['template'] ), [ 'If-Match' => $cloned['etag'] ] ); if ( is_wp_error( $template ) ) { return $template; }
            $cpt = $draft['routing']['post_type']; $layout_key = 'wp_' . $draft['reference_type'] . '_' . $draft['reference_id'];
            if ( ! preg_match( '/^[a-z][a-z0-9_-]{0,63}$/D', $cpt ) || ! preg_match( '/^[a-z][a-z0-9_-]{0,63}$/D', $layout_key ) ) { return self::error( 'route', 'This native reference cannot be represented by a canonical NOVA assignment.' ); }
            $refs = [ 'cpt' => $cpt, 'layout_key' => $layout_key, 'template_id' => $template['template_id'], 'template_version' => $template['template_version'] ];
            $mapping = $this->step( 'create_mapping', 'POST', '/mappings', array_merge( $refs, [ 'bindings' => $prepared['bindings'] ] ), $this->idempotency( 'create_mapping' ) ); if ( is_wp_error( $mapping ) ) { return $mapping; }
            $refs['mapping_id'] = $mapping['mapping_id']; $refs['mapping_revision_id'] = $mapping['mapping_revision_id'];
            $previous = $this->state['previous_assignment'];
            if ( $previous && in_array( $previous['state'], [ 'sealed', 'history' ], true ) ) {
                $previous = $this->step( 'copy_assignment', 'POST', '/assignments/' . $previous['assignment_id'] . '/copies', [ 'base_revision_id' => $previous['assignment_revision_id'] ], array_merge( $this->idempotency( 'copy_assignment' ), [ 'If-Match' => $previous['etag'] ] ) ); if ( is_wp_error( $previous ) ) { return $previous; }
            }
            $assignment = $previous
                ? $this->step( 'replace_assignment', 'PUT', '/assignments/' . $previous['assignment_id'], array_merge( [ 'expected_revision_id' => $previous['assignment_revision_id'] ], $refs ), [ 'If-Match' => $previous['etag'] ] )
                : $this->step( 'create_assignment', 'POST', '/assignments', $refs, $this->idempotency( 'create_assignment' ) );
            if ( is_wp_error( $assignment ) ) { return $assignment; }
            $this->state['template'] = $template; $this->state['mapping'] = $mapping; $this->state['assignment'] = $assignment; $this->state['status'] = 'synced_draft';
            $saved = $this->checkpoint(); return is_wp_error( $saved ) ? $saved : $this->state;
        } finally { $this->release(); }
    }

    public static function retain_configuration( array $pin, array $template, array $mapping, array $assignment, array $local ) {
        if ( ! Nova_Bridge_Suite_Posting_Client::id( $pin['pin_id'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $pin['digest'] ?? '' ) || ! Nova_Bridge_Suite_Posting_Client::uuid( $pin['site_id'] ?? '' ) ) { return self::error( 'pin', 'The sealed pin identity is invalid.', 502 ); }
        foreach ( [ 'template_id' => $template['template_id'], 'template_version' => $template['template_version'], 'mapping_id' => $mapping['mapping_id'], 'mapping_revision_id' => $mapping['mapping_revision_id'], 'assignment_id' => $assignment['assignment_id'], 'assignment_revision_id' => $assignment['assignment_revision_id'] ] as $key => $value ) { if ( ( $pin[ $key ] ?? null ) !== $value ) { return self::error( 'pin_refs', 'The pin does not identify the exact synchronized revisions.', 502 ); } }
        $snapshot = [ 'site_id' => $pin['site_id'], 'pin_id' => $pin['pin_id'], 'digest' => $pin['digest'], 'pin' => $pin, 'template' => $template, 'mapping' => $mapping, 'assignment' => $assignment, 'local' => $local ];
        $snapshot['snapshot_digest'] = hash( 'sha256', self::json( $snapshot ) );
        $name = 'nova_mapping_pin_' . hash( 'sha256', $pin['site_id'] . ':' . $pin['pin_id'] );
        if ( add_option( $name, $snapshot, '', false ) ) { return true; }
        $old = get_option( $name, null );
        return is_array( $old ) && hash_equals( $old['snapshot_digest'] ?? '', $snapshot['snapshot_digest'] ) ? true : self::error( 'pin_conflict', 'A different snapshot already owns this immutable pin. Nothing was replaced.' );
    }

    public static function configuration( string $pin_id, string $digest, string $site_id = '' ) {
        if ( '' === $site_id ) { $site_id = self::connection()['site_id'] ?? ''; }
        $snapshot = get_option( 'nova_mapping_pin_' . hash( 'sha256', $site_id . ':' . $pin_id ), null );
        if ( ! is_array( $snapshot ) || $snapshot['site_id'] !== $site_id || $snapshot['pin_id'] !== $pin_id || ! hash_equals( $snapshot['digest'], $digest ) ) { return self::error( 'configuration_missing', 'The exact delivered configuration pin is not retained on this site.' ); }
        $expected = $snapshot['snapshot_digest']; unset( $snapshot['snapshot_digest'] );
        if ( ! hash_equals( $expected, hash( 'sha256', self::json( $snapshot ) ) ) ) { return self::error( 'configuration_changed', 'The retained configuration snapshot is inconsistent.' ); }
        return $snapshot;
    }

    /** Fetch exact immutable remote identities, including when restoring this installation. */
    public static function exact_configuration( Nova_Bridge_Suite_Posting_Client $client, string $pin_id, string $digest, string $site_id ) {
        if ( $client->site_id() !== $site_id || ! Nova_Bridge_Suite_Posting_Client::id( $pin_id ) || ! preg_match( '/^[a-f0-9]{64}$/D', $digest ) ) { return self::error( 'pin_identity', 'Invalid exact configuration identity.' ); }
        $reply = $client->site_request( 'GET', '/writing/pins/' . $pin_id ); if ( is_wp_error( $reply ) ) { return $reply; }
        $pin = $reply['body'];
        if ( ! is_array( $pin ) || ( $pin['pin_id'] ?? null ) !== $pin_id || ( $pin['site_id'] ?? null ) !== $site_id || ! hash_equals( $digest, $pin['digest'] ?? '' ) ) { return self::error( 'pin_mismatch', 'The exact remote pin and delivered digest do not match.' ); }
        $records = [];
        foreach ( [ 'template' => 'template_version', 'mapping' => 'mapping_revision_id', 'assignment' => 'assignment_revision_id' ] as $kind => $revision ) {
            if ( ! Nova_Bridge_Suite_Posting_Client::id( $pin[ $kind . '_id' ] ?? null ) || ! Nova_Bridge_Suite_Posting_Client::id( $pin[ $revision ] ?? null ) ) { return self::error( 'pin_refs', 'The remote pin has invalid revision references.', 502 ); }
            $reply = $client->site_request( 'GET', '/writing/' . $kind . 's/' . $pin[ $kind . '_id' ] . '?' . $revision . '=' . $pin[ $revision ] ); if ( is_wp_error( $reply ) ) { return $reply; }
            $record = $reply['body'];
            if ( ! is_array( $record ) || ( $record[ $kind . '_id' ] ?? null ) !== $pin[ $kind . '_id' ] || ( $record[ $revision ] ?? null ) !== $pin[ $revision ] || ! in_array( $record['state'] ?? '', [ 'sealed', 'history' ], true ) ) { return self::error( 'pin_refs', 'The remote service did not return the exact retained revision.', 502 ); }
            $records[ $kind ] = $record;
        }
        $rebuilt = self::local_from_policy( $records['mapping'], $records['template'] ); if ( is_wp_error( $rebuilt ) ) { return $rebuilt; }
        $stored = self::configuration( $pin_id, $digest, $site_id );
        if ( ! is_wp_error( $stored ) ) {
            foreach ( [ 'pin', 'template', 'mapping', 'assignment' ] as $kind ) { if ( self::json( $stored[ $kind ] ) !== self::json( 'pin' === $kind ? $pin : $records[ $kind ] ) ) { return self::error( 'retained_changed', 'A retained remote configuration no longer matches this site snapshot.' ); } }
            if ( self::json( Nova_Bridge_Suite_Writing_Adapter::policy( $stored['local'] ) ) !== self::json( Nova_Bridge_Suite_Writing_Adapter::policy( $rebuilt ) ) ) { return self::error( 'policy_changed', 'The retained local publishing policy differs from the canonical mapping.' ); }
            return $stored;
        }
        if ( 'nova_writing_configuration_missing' !== $stored->get_error_code() ) { return $stored; }
        $saved = self::retain_configuration( $pin, $records['template'], $records['mapping'], $records['assignment'], $rebuilt ); if ( is_wp_error( $saved ) ) { return $saved; }
        return self::configuration( $pin_id, $digest, $site_id );
    }

    private static function local_from_policy( array $mapping, array $template ) {
        $bindings = $mapping['bindings'] ?? [];
        $json = $bindings[0]['expected_identity']['plugin_policy_json'] ?? null;
        if ( ! is_string( $json ) || strlen( $json ) > 262144 ) { return self::error( 'policy_missing', 'The exact mapping does not retain this plugin publishing policy.' ); }
        $policy = json_decode( $json, true, 32 ); $digest = hash( 'sha256', $json );
        if ( ! is_array( $policy ) || 1 !== ( $policy['schema_version'] ?? null ) || self::json( $policy ) !== $json || ! in_array( $policy['reference_type'] ?? '', [ 'post', 'term' ], true ) || ! is_int( $policy['reference_id'] ?? null ) || $policy['reference_id'] < 1 || ! is_string( $policy['signature'] ?? null ) ) { return self::error( 'policy_invalid', 'The exact mapping contains an unsupported publishing policy.' ); }
        foreach ( [ 'routing', 'repeat_slots', 'skipped_sources', 'protected_bindings', 'leave_empty' ] as $key ) { if ( ! is_array( $policy[ $key ] ?? null ) ) { return self::error( 'policy_invalid', 'The publishing policy is incomplete.' ); } }
        $local = [ 'schema_version' => 1, 'revision' => $bindings[0]['expected_identity']['local_revision'] ?? '', 'catalog_mode' => 'nova', 'template' => [ 'id' => $template['source_template_id'] ?? $template['template_id'], 'revision' => $template['source_template_version'] ?? $template['template_version'] ], 'label' => '', 'guidance' => $template['authoring_notes'] ?? '', 'reference_type' => $policy['reference_type'], 'reference_id' => $policy['reference_id'], 'signature' => $policy['signature'], 'routing' => $policy['routing'], 'repeat_slots' => $policy['repeat_slots'], 'skipped_sources' => $policy['skipped_sources'], 'fields' => [], 'target_descriptors' => [] ];
        foreach ( $bindings as $binding ) {
            $identity = $binding['expected_identity'] ?? [];
            foreach ( [ 'reference_type', 'reference_id', 'signature' ] as $key ) { if ( ( $identity[ $key ] ?? null ) !== $policy[ $key ] ) { return self::error( 'policy_identity', 'Canonical bindings disagree on their native reference.' ); } }
            if ( ( $identity['local_revision'] ?? null ) !== $local['revision'] || ! hash_equals( $digest, $identity['plugin_policy_digest'] ?? '' ) || ( $binding['target_descriptor']['format'] ?? '' ) !== 'nova_bridge_target_v1' ) { return self::error( 'policy_identity', 'Canonical bindings disagree on the retained policy.' ); }
            $descriptor = $binding['target_descriptor'];
            $targets = false === strpos( $binding['source_path'], '[].' ) ? [ $descriptor['target'] ?? [] ] : array_column( $descriptor['slots'] ?? [], 'target' );
            foreach ( $targets as $target ) {
                if ( ! is_array( $target ) || ! is_string( $target['path'] ?? null ) || isset( $local['target_descriptors'][ $target['path'] ] ) ) { return self::error( 'policy_targets', 'Canonical target addresses are missing or duplicated.' ); }
                $local['target_descriptors'][ $target['path'] ] = $target;
                if ( false === strpos( $binding['source_path'], '[].' ) ) { $local['fields'][ $target['path'] ] = [ 'mode' => 'mapped', 'source_path' => $binding['source_path'], 'instructions' => '', 'binding' => $target['binding'] ?? '' ]; }
            }
        }
        foreach ( $policy['protected_bindings'] as $binding ) {
            $target = $binding['target'] ?? null; $path = $target['path'] ?? '';
            if ( ! $path || isset( $local['target_descriptors'][ $path ] ) ) { return self::error( 'policy_overlap', 'Protected and generated policy addresses overlap.' ); }
            $local['target_descriptors'][ $path ] = $target; $local['fields'][ $path ] = [ 'mode' => 'protected', 'protected_slot' => $binding['slot_key'], 'source_path' => '', 'instructions' => '', 'binding' => $target['binding'] ?? '' ];
        }
        foreach ( $policy['leave_empty'] as $target ) {
            $path = $target['path'] ?? '';
            if ( ! $path || isset( $local['target_descriptors'][ $path ] ) ) { return self::error( 'policy_overlap', 'Omitted and generated policy addresses overlap.' ); }
            $local['target_descriptors'][ $path ] = $target; $local['fields'][ $path ] = [ 'mode' => 'leave_empty', 'source_path' => '', 'instructions' => '', 'binding' => $target['binding'] ?? '' ];
        }
        $local['label'] = $policy['label'] ?? '';
        foreach ( $local['fields'] as $path => &$field ) { $field['instructions'] = $policy['field_instructions'][ $path ] ?? ''; }
        unset( $field );
        return $local;
    }

    private static function seal_refs( array $state ): array {
        return [ 'template_id' => $state['template']['template_id'], 'expected_template_revision_id' => $state['template']['template_version'], 'template_etag' => $state['template']['etag'], 'mapping_id' => $state['mapping']['mapping_id'], 'expected_mapping_revision_id' => $state['mapping']['mapping_revision_id'], 'mapping_etag' => $state['mapping']['etag'], 'assignment_id' => $state['assignment']['assignment_id'], 'expected_assignment_revision_id' => $state['assignment']['assignment_revision_id'], 'assignment_etag' => $state['assignment']['etag'] ];
    }

    public function activate( array $draft, array $context = [] ) {
        $locked = $this->acquire( $draft ); if ( is_wp_error( $locked ) ) { return $locked; }
        try {
            if ( ! $this->state || $this->state['local_revision'] !== $draft['revision'] || ! isset( $this->state['template'], $this->state['mapping'], $this->state['assignment'] ) ) { return self::error( 'not_synced', 'Synchronize this exact saved local revision before activation.' ); }
            if ( 'active' === $this->state['status'] ) { return $this->state; }
            if ( ! is_callable( [ 'Nova_Bridge_Suite_Mapped_Writer', 'probe_mapping' ] ) ) { return self::error( 'writer_probe_required', 'Native writer verification must complete before configuration activation.' ); }
            $probe = Nova_Bridge_Suite_Mapped_Writer::probe_mapping( $this->state['template'], $this->state['mapping'], $this->state['local'], $context );
            if ( is_wp_error( $probe ) ) { return $probe; }
            $evidence = [ 'mapping_id' => $this->state['mapping']['mapping_id'], 'mapping_revision_id' => $this->state['mapping']['mapping_revision_id'] ];
            foreach ( [ 'writer_id', 'provider', 'plugin_version', 'db_engine', 'expires_at' ] as $key ) { if ( ! is_string( $probe[ $key ] ?? null ) || '' === $probe[ $key ] ) { return self::error( 'writer_evidence', 'The writer did not supply complete capability evidence.' ); } $evidence[ $key ] = $probe[ $key ]; }
            $coverage = $probe['coverage'] ?? []; $expected = array_column( $this->state['mapping']['bindings'], 'source_path' ); sort( $coverage ); sort( $expected );
            if ( ! $expected || $coverage !== $expected || strtotime( $evidence['expires_at'] ) <= time() ) { return self::error( 'writer_coverage', 'Fresh writer evidence must cover every canonical generated source.' ); }
            $evidence['coverage'] = $coverage;
            // Evidence POST has no idempotency contract. Reposting fresh read-only proof on recovery is safe.
            if ( ! $this->owned() ) { return self::error( 'lease_lost', 'Configuration ownership expired.' ); }
            $recorded = $this->client->site_request( 'POST', '/writing/evidence', $evidence ); if ( is_wp_error( $recorded ) ) { return $recorded; }
            $this->state['evidence'] = $recorded['body'];
            $saved = $this->checkpoint(); if ( is_wp_error( $saved ) ) { return $saved; }
            if ( ! isset( $this->state['seal_request'] ) ) { $this->state['seal_request'] = self::seal_refs( $this->state ); }
            $pin = $this->step( 'seal_pin', 'POST', '/pins/seal', $this->state['seal_request'], $this->idempotency( 'seal_pin' ) ); if ( is_wp_error( $pin ) ) { return $pin; }
            foreach ( [ 'template' => 'template_version', 'mapping' => 'mapping_revision_id', 'assignment' => 'assignment_revision_id' ] as $kind => $revision ) {
                if ( ! Nova_Bridge_Suite_Posting_Client::id( $pin[ $kind . '_id' ] ?? null ) || ! Nova_Bridge_Suite_Posting_Client::id( $pin[ $revision ] ?? null ) ) { return self::error( 'pin_refs', 'The sealed pin response has invalid revision references.', 502 ); }
                $record = $this->step( 'sealed_' . $kind, 'GET', '/' . $kind . 's/' . $pin[ $kind . '_id' ] . '?' . $revision . '=' . $pin[ $revision ] ); if ( is_wp_error( $record ) ) { return $record; }
                if ( ( $record[ $revision ] ?? null ) !== $pin[ $revision ] || ! in_array( $record['state'] ?? null, [ 'sealed', 'history' ], true ) ) { return self::error( 'seal_response', 'The service did not retain the exact sealed component.', 502 ); }
                $this->state[ $kind ] = $record;
            }
            $retained = self::retain_configuration( $pin, $this->state['template'], $this->state['mapping'], $this->state['assignment'], $this->state['local'] ); if ( is_wp_error( $retained ) ) { return $retained; }
            $this->state['pin'] = $pin; $this->state['status'] = 'sealed';
            $saved = $this->checkpoint(); if ( is_wp_error( $saved ) ) { return $saved; }
            $lifecycle = $this->step( 'activation_lifecycle', 'GET', '/assignments/' . $pin['assignment_id'] . '/lifecycle' ); if ( is_wp_error( $lifecycle ) ) { return $lifecycle; }
            if ( ! is_string( $lifecycle['etag'] ?? null ) || ( $lifecycle['assignment_id'] ?? null ) !== $pin['assignment_id'] ) { return self::error( 'lifecycle_response', 'The assignment lifecycle response is invalid.', 502 ); }
            $body = self::seal_refs( $this->state ); unset( $body['assignment_id'] );
            $activated = $this->step( 'activate_assignment', 'POST', '/assignments/' . $pin['assignment_id'] . '/activate', $body, array_merge( $this->idempotency( 'activate_assignment' ), [ 'If-Match' => $lifecycle['etag'] ] ) ); if ( is_wp_error( $activated ) ) { return $activated; }
            if ( ( $activated['pin_id'] ?? null ) !== $pin['pin_id'] || ( $activated['digest'] ?? null ) !== $pin['digest'] || 'active' !== ( $activated['status'] ?? '' ) ) { return self::error( 'activation_response', 'The service did not activate the exact retained configuration.', 502 ); }
            $this->state['activation'] = $activated; $this->state['status'] = 'active';
            $saved = $this->checkpoint(); return is_wp_error( $saved ) ? $saved : $this->state;
        } finally { $this->release(); }
    }
}
