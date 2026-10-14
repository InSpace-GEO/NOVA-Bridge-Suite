<?php
/** Explicitly opted-in modern mapping connection, separate from legacy delivery queues. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Mapped_Content_Connector {
    public const OPTION = 'nova_bridge_mapped_content_connection_v1';
    public const CRON_HOOK = 'nova_bridge_mapped_content_poll';
    private $connection;
    private $client;
    public function __construct( array $connection, $client = null ) { $this->connection = $connection; $this->client = $client ?? new Nova_Bridge_Suite_Posting_Client( $connection ); }
    private static function error( string $code, string $message, int $status = 409 ): WP_Error { return new WP_Error( 'nova_mapped_' . $code, $message, [ 'status' => $status, 'error_code' => $code ] ); }
    public static function bootstrap(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'routes' ], 1008 );
        add_action( 'admin_init', [ __CLASS__, 'register' ] );
        add_action( 'init', [ __CLASS__, 'schedule' ] );
        add_action( self::CRON_HOOK, [ __CLASS__, 'scheduled_poll' ] );
    }
    public static function connection(): array { $saved = get_option( self::OPTION, [] ); return array_merge( [ 'base_url' => '', 'site_id' => '', 'token' => '', 'enabled' => false, 'paused' => true, 'automatic' => false, 'actor_user_id' => 0 ], is_array( $saved ) ? $saved : [] ); }
    public static function register(): void { register_setting( self::OPTION, self::OPTION, [ 'type' => 'array', 'show_in_rest' => false, 'sanitize_callback' => [ __CLASS__, 'sanitize' ] ] ); }
    public static function sanitize( $input ): array {
        $old = self::connection(); if ( ! is_array( $input ) || ! current_user_can( 'manage_options' ) ) { return $old; }
        $next = $old; foreach ( [ 'base_url', 'site_id', 'token' ] as $key ) { if ( is_string( $input[$key] ?? null ) && '' !== trim( $input[$key] ) ) { $next[$key] = trim( $input[$key] ); } }
        $next['base_url'] = rtrim( $next['base_url'], '/' ); $next['site_id'] = strtolower( $next['site_id'] );
        if ( ( $next['base_url'] || $next['site_id'] || $next['token'] ) && is_wp_error( ( new Nova_Bridge_Suite_Posting_Client( $next ) )->connection_error() ) ) { add_settings_error( self::OPTION, 'nova_mapped_connection', 'Use the separate HTTPS service origin, site UUID and site-scoped connector credential.' ); return $old; }
        foreach ( [ 'enabled', 'paused', 'automatic' ] as $key ) { $next[$key] = ! empty( $input[$key] ); }
        $next['actor_user_id'] = get_current_user_id();
        if ( $old['base_url'] !== $next['base_url'] || $old['site_id'] !== $next['site_id'] ) { $next['paused'] = true; }
        return $next;
    }
    public static function routes(): void {
        foreach ( [ 'status' => 'GET', 'sync' => 'POST', 'poll' => 'POST', 'snapshot' => 'POST' ] as $route => $method ) { register_rest_route( 'nova-bridge/v1', '/mapping/mapped-content/' . $route, [ 'methods' => $method, 'callback' => [ __CLASS__, $route . '_response' ], 'permission_callback' => [ 'Nova_Bridge_Suite_Layout_Recipes', 'can_admin' ] ] ); }
    }
    private static function response( $result ) { if ( is_wp_error( $result ) ) { return $result; } $reply = rest_ensure_response( $result ); $reply->header( 'Cache-Control', 'private, no-store' ); return $reply; }
    public static function status_response( $request ) { $connection = self::connection(); return self::response( [ 'enabled' => ! empty( $connection['enabled'] ), 'paused' => ! empty( $connection['paused'] ), 'automatic' => ! empty( $connection['automatic'] ), 'site_id' => $connection['site_id'], 'credentials_configured' => ! empty( $connection['token'] ), 'legacy_connection_unchanged' => true ] ); }
    public static function sync_response( $request ) { return self::response( ( new self( self::connection() ) )->sync_defaults() ); }
    public static function poll_response( $request ) { return self::response( ( new self( self::connection() ) )->poll() ); }
    public static function snapshot_response( $request ) { $body = $request->get_json_params(); return self::response( is_array( $body ) ? ( new self( self::connection() ) )->snapshot( $body ) : self::error( 'snapshot', 'Supply a concrete snapshot request.', 400 ) ); }
    public static function schedule(): void {
        $connection = self::connection();
        if ( ! empty( $connection['enabled'] ) && ! empty( $connection['automatic'] ) ) { if ( ! wp_next_scheduled( self::CRON_HOOK ) ) { wp_schedule_event( time() + 60, 'nova_posting_minute', self::CRON_HOOK ); } }
        elseif ( wp_next_scheduled( self::CRON_HOOK ) ) { wp_clear_scheduled_hook( self::CRON_HOOK ); }
    }
    public static function scheduled_poll(): void { ( new self( self::connection() ) )->poll(); }
    private function ready() {
        if ( empty( $this->connection['enabled'] ) ) { return self::error( 'connection_disabled', 'Enable the separate mapped-content connection before synchronization or delivery.', 503 ); }
        return $this->client->connection_error();
    }
    private function as_actor( callable $work ) {
        $ready = $this->ready(); if ( is_wp_error( $ready ) ) { return $ready; }
        $actor = (int) ( $this->connection['actor_user_id'] ?? 0 ); if ( $actor < 1 ) { return self::error( 'actor', 'A WordPress administrator must explicitly configure the mapped connection.', 403 ); }
        $old = get_current_user_id(); wp_set_current_user( $actor );
        try { return current_user_can( 'manage_options' ) ? $work() : self::error( 'actor', 'The configured WordPress actor no longer has mapping permission.', 403 ); }
        finally { wp_set_current_user( $old ); }
    }
    private function upload( array $mapping ) {
        $path = '/mapped/layouts/' . $mapping['mapping_id'] . '/revisions/' . $mapping['revision'];
        $reply = $this->client->site_request( 'PUT', $path, $mapping ); if ( is_wp_error( $reply ) ) { return $reply; }
        $body = $reply['body'] ?? null;
        if ( ! is_array( $body ) || ! is_array( $body['mapping'] ?? null ) || Nova_Bridge_Suite_Mapped_Content_Writer::wire_digest( $body['mapping'] ) !== Nova_Bridge_Suite_Mapped_Content_Writer::wire_digest( $mapping ) || ( $body['digest'] ?? '' ) !== Nova_Bridge_Suite_Mapped_Content_Writer::wire_digest( $mapping ) ) { return self::error( 'upload_identity', 'The service did not acknowledge this exact immutable mapping.', 502 ); } return $body;
    }
    public function sync_defaults() {
        return $this->as_actor( function () {
            $synced = []; foreach ( Nova_Bridge_Suite_Layout_Recipes::defaults() as $resource => $default ) { $mapping = Nova_Bridge_Suite_Mapped_Content_Writer::export_default( $this->client->site_id(), $resource ); if ( is_wp_error( $mapping ) ) { return $mapping; } $reply = $this->upload( $mapping ); if ( is_wp_error( $reply ) ) { return $reply; } $synced[] = [ 'resource_id' => $resource, 'mapping_id' => $mapping['mapping_id'], 'revision' => $mapping['revision'], 'digest' => $reply['digest'] ]; } return [ 'mappings' => $synced ];
        } );
    }
    public function snapshot( array $request ) {
        return $this->as_actor( function () use ( $request ) {
            if ( ! Nova_Bridge_Suite_Posting_Client::uuid( (string) ( $request['request_id'] ?? '' ) ) || ! is_string( $request['resource_id'] ?? null ) || ! is_int( $request['reference_id'] ?? null ) || ! in_array( $request['reference_type'] ?? '', [ 'post', 'term' ], true ) || ( isset( $request['editable_paths'] ) && ! is_array( $request['editable_paths'] ) ) ) { return self::error( 'snapshot', 'Supply an exact site snapshot request.', 400 ); }
            $mapping = Nova_Bridge_Suite_Mapped_Content_Writer::capture_snapshot( $this->client->site_id(), $request['request_id'], $request['resource_id'], [ 'reference_type' => $request['reference_type'], 'reference_id' => $request['reference_id'] ], $request['editable_paths'] ?? null );
            if ( is_wp_error( $mapping ) ) { return $mapping; } return $this->upload( $mapping );
        } );
    }
    private static function code( WP_Error $error ): string { $code = (string) ( $error->get_error_data()['error_code'] ?? $error->get_error_code() ); $code = preg_replace( '/[^a-z0-9_]/', '_', strtolower( $code ) ); return substr( $code, 0, 80 ); }
    private function retained_receipt( array $delivery ) {
        $site = $this->client->site_id(); $id = $delivery['delivery_id'] ?? ''; if ( ! is_string( $id ) || ! Nova_Bridge_Suite_Posting_Client::uuid( $id ) ) { return self::error( 'delivery_id', 'The service returned an invalid delivery identity.', 502 ); }
        $key = 'nova_mapped_receipt_' . hash( 'sha256', $site . ':' . $id ); $digest = Nova_Bridge_Suite_Recipe_Engine::digest( array_intersect_key( $delivery, array_flip( [ 'delivery_id', 'mapping_id', 'mapping_revision', 'mapping', 'mapping_digest', 'content', 'publication' ] ) ) ); $old = get_option( $key, null );
        if ( is_array( $old ) ) { return $old['digest'] === $digest ? $old['receipt'] : self::error( 'delivery_conflict', 'The service changed a retained delivery identity.', 409 ); }
        $result = Nova_Bridge_Suite_Mapped_Content_Writer::deliver( $site, $delivery );
        if ( is_wp_error( $result ) ) { $code = self::code( $result ); if ( 'busy' === $code ) { return $result; } $uncertain = Nova_Bridge_Suite_Mapped_Content_Writer::has_native_attempt( $site, $id ) || ( $result->get_error_data()['write_status'] ?? '' ) === 'uncertain'; $result = [ 'status' => $uncertain ? 'uncertain' : 'rejected', 'error_code' => $code ]; }
        $record = [ 'digest' => $digest, 'receipt' => $result ]; if ( ! add_option( $key, $record, '', false ) && get_option( $key, null ) !== $record ) { return self::error( 'receipt_storage', 'The native result could not be retained for an exact retry.', 500 ); } return $result;
    }
    public function poll() {
        return $this->as_actor( function () {
            $out = [ 'snapshots' => 0, 'deliveries' => 0, 'paused' => ! empty( $this->connection['paused'] ), 'errors' => [] ];
            $synced = $this->sync_defaults(); if ( is_wp_error( $synced ) ) { return $synced; }
            $reply = $this->client->site_request( 'GET', '/mapped/snapshot-requests' ); if ( is_wp_error( $reply ) ) { return $reply; }
            $requests = $reply['body']['requests'] ?? null; if ( ! is_array( $requests ) || count( $requests ) > 100 ) { return self::error( 'requests', 'The service returned an invalid bounded snapshot queue.', 502 ); }
            foreach ( $requests as $request ) {
                $snapshot = $this->snapshot( $request ); $result = is_wp_error( $snapshot ) ? [ 'error_code' => self::code( $snapshot ) ] : [ 'mapping_id' => $snapshot['mapping']['mapping_id'], 'mapping_revision' => $snapshot['mapping']['revision'] ];
                if ( is_wp_error( $snapshot ) && in_array( $snapshot->get_error_data()['status'] ?? 0, [ 429, 502, 503, 504 ], true ) ) { $out['errors'][] = self::code( $snapshot ); continue; }
                $ack = $this->client->site_request( 'POST', '/mapped/snapshot-requests/' . $request['request_id'] . '/result', $result ); if ( is_wp_error( $ack ) ) { $out['errors'][] = self::code( $ack ); } else { ++$out['snapshots']; }
            }
            if ( $out['paused'] ) { return $out; }
            $reply = $this->client->site_request( 'GET', '/mapped/deliveries' ); if ( is_wp_error( $reply ) ) { return $reply; }
            $deliveries = $reply['body']['deliveries'] ?? null; if ( ! is_array( $deliveries ) || count( $deliveries ) > 100 ) { return self::error( 'deliveries', 'The service returned an invalid bounded delivery queue.', 502 ); }
            foreach ( $deliveries as $delivery ) { $receipt = $this->retained_receipt( $delivery ); if ( is_wp_error( $receipt ) ) { $out['errors'][] = self::code( $receipt ); continue; } $ack = $this->client->site_request( 'POST', '/mapped/deliveries/' . $delivery['delivery_id'] . '/result', $receipt ); if ( is_wp_error( $ack ) ) { $out['errors'][] = self::code( $ack ); } else { ++$out['deliveries']; } }
            return $out;
        } );
    }
    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; } $settings = self::connection();
        echo '<details class="nova-mapping-advanced"><summary>Mapped-content connection</summary><p>Connect saved endpoint templates and fresh existing-item snapshots to a compatible NOVA service. This is a separate opt-in connection; existing delivery settings stay unchanged.</p>'; settings_errors( self::OPTION );
        echo '<form method="post" action="options.php">'; settings_fields( self::OPTION );
        foreach ( [ 'base_url' => 'Mapped service URL', 'site_id' => 'Website identifier (UUID)', 'token' => 'Site connector credential' ] as $key => $label ) { $secret = 'token' === $key; echo '<p><label>' . esc_html( $label ) . '<br><input class="regular-text" name="' . esc_attr( self::OPTION . '[' . $key . ']' ) . '" type="' . ( $secret ? 'password' : 'text' ) . '" value="' . ( $secret ? '' : esc_attr( $settings[$key] ) ) . '" autocomplete="' . ( $secret ? 'new-password' : 'off' ) . '"></label></p>'; }
        foreach ( [ 'enabled' => 'Enable mapping synchronization and retrieval', 'paused' => 'Pause changes to site content', 'automatic' => 'Check for mapping requests and deliveries every minute' ] as $key => $label ) { echo '<p><label><input type="checkbox" name="' . esc_attr( self::OPTION . '[' . $key . ']' ) . '" value="1" ' . checked( ! empty( $settings[$key] ), true, false ) . '> ' . esc_html( $label ) . '</label></p>'; }
        echo '<p>New categories are immediately visible: WordPress has no category draft status. The administrator saving this connection supplies the publishing permissions.</p>'; submit_button( 'Save mapped-content connection' ); echo '</form></details>';
    }
}
