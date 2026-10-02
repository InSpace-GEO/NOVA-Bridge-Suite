<?php
/** Signed ready-event intake and administrator delivery recovery. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Posting_Delivery {
    public const CRON_HOOK = 'nova_bridge_posting_drain';
    public const MAX_EVENT_BYTES = 16384;
    public const CLOCK_SKEW_SECONDS = 300;
    public const SCHEMA_OPTION = 'nova_bridge_posting_schema_version';
    public const SCHEMA_VERSION = 3;
    private static $schema_attempted = null;

    public static function bootstrap(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
        add_filter( 'cron_schedules', [ __CLASS__, 'schedules' ] );
        add_action( self::CRON_HOOK, [ __CLASS__, 'drain' ] );
        add_action( 'init', [ __CLASS__, 'schedule' ] );
    }

    public static function schedules( array $schedules ): array {
        $schedules['nova_posting_minute'] = [ 'interval' => 60, 'display' => 'NOVA delivery recovery every minute' ];
        return $schedules;
    }

    public static function schedule(): void {
        $connection = Nova_Bridge_Suite_Posting_Settings::connection();
        if ( ! empty( $connection['enabled'] ) && self::ensure_schema() && ! wp_next_scheduled( self::CRON_HOOK ) ) { wp_schedule_event( time() + 10, 'nova_posting_minute', self::CRON_HOOK ); }
    }

    /** Upgrade only an enabled connection; disabled installs perform no schema work. */
    public static function ensure_schema(): bool {
        if ( self::SCHEMA_VERSION === (int) get_option( self::SCHEMA_OPTION, 0 ) ) { return true; }
        if ( null !== self::$schema_attempted ) { return self::$schema_attempted; }
        self::$schema_attempted = false;
        $store = new Nova_Bridge_Suite_Posting_Jobs();
        $locked = $store->lock( 'schema' );
        if ( is_wp_error( $locked ) ) { return false; }
        try {
            if ( true !== $store->install() ) { return false; }
            update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
            self::$schema_attempted = self::SCHEMA_VERSION === (int) get_option( self::SCHEMA_OPTION, 0 );
            return self::$schema_attempted;
        } finally { $store->unlock( 'schema' ); }
    }

    public static function drain(): void {
        ( new Nova_Bridge_Suite_Posting_Worker( Nova_Bridge_Suite_Posting_Settings::connection() ) )->run();
    }

    public static function register_routes(): void {
        register_rest_route( 'nova-bridge/v1', '/posting/ready', [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'ready' ], 'permission_callback' => '__return_true' ] );
        register_rest_route( 'nova-bridge/v1', '/posting/jobs', [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'status' ], 'permission_callback' => [ __CLASS__, 'can_admin' ] ] );
        register_rest_route( 'nova-bridge/v1', '/posting/jobs/(?P<id>[1-9][0-9]*)/resume', [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'resume' ], 'permission_callback' => [ __CLASS__, 'can_admin' ] ] );
        register_rest_route( 'nova-bridge/v1', '/posting/discovery/retry', [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'retry_discovery' ], 'permission_callback' => [ __CLASS__, 'can_retry_discovery' ] ] );
    }

    public static function can_admin() {
        return current_user_can( 'manage_options' ) ? true : new WP_Error( 'nova_posting_forbidden', 'Administrator access is required.', [ 'status' => is_user_logged_in() ? 403 : 401 ] );
    }

    public static function can_retry_discovery( $request ) {
        $permission = self::can_admin();
        if ( is_wp_error( $permission ) ) { return $permission; }
        return wp_verify_nonce( (string) $request->get_header( 'x-wp-nonce' ), 'wp_rest' ) ? true : new WP_Error( 'nova_posting_nonce', 'Refresh the Mapping page before rechecking access.', [ 'status' => 403 ] );
    }

    public static function retry_discovery( $request ) {
        $permission = self::can_retry_discovery( $request ); if ( is_wp_error( $permission ) ) { return $permission; }
        $connection = Nova_Bridge_Suite_Posting_Settings::connection();
        if ( empty( $connection['enabled'] ) ) { return new WP_Error( 'nova_posting_disabled', 'The connection is disabled.', [ 'status' => 503 ] ); }
        if ( ! self::ensure_schema() ) { return new WP_Error( 'nova_posting_storage', 'Discovery storage is unavailable.', [ 'status' => 503 ] ); }
        $result = ( new Nova_Bridge_Suite_Posting_Discovery( $connection ) )->retry_authentication();
        return is_wp_error( $result ) ? $result : self::response( [ 'recheck_scheduled' => true, 'message' => 'Discovery will authenticate again on the next worker run. Retained work is unchanged.' ] );
    }

    private static function response( array $data, int $status = 200 ) {
        $response = new WP_REST_Response( $data, $status );
        $response->header( 'Cache-Control', 'private, no-store' );
        return $response;
    }

    /** The deployed sender signs the exact UTF-8 body with timestamp + '.' + raw body. */
    public static function authenticate( string $raw, string $timestamp, string $signature, array $connection, ?int $now = null ) {
        $invalid = static function ( int $status = 401 ) { return new WP_Error( 'nova_posting_invalid_notification', 'The notification could not be accepted.', [ 'status' => $status ] ); };
        if ( strlen( $raw ) > self::MAX_EVENT_BYTES ) { return $invalid( 413 ); }
        if ( ! preg_match( '/^[0-9]{1,12}$/D', $timestamp ) || abs( ( $now ?? time() ) - (int) $timestamp ) > self::CLOCK_SKEW_SECONDS || ! preg_match( '/^sha256=[a-f0-9]{64}$/D', $signature ) || ! is_string( $connection['webhook_secret'] ?? null ) || '' === $connection['webhook_secret'] ) { return $invalid(); }
        $expected = 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $raw, $connection['webhook_secret'] );
        if ( ! hash_equals( $expected, $signature ) ) { return $invalid(); }
        $event = json_decode( $raw, true, 16 );
        if ( ! is_array( $event ) || count( $event ) !== 4 || count( array_diff( array_keys( $event ), [ 'event', 'content_id', 'site', 'version' ] ) ) || 'content.ready' !== ( $event['event'] ?? null ) || ! is_string( $event['content_id'] ?? null ) || ! preg_match( '/^[0-9]{1,64}$/D', $event['content_id'] ) || ! is_int( $event['version'] ?? null ) || $event['version'] < 1 || ( $event['site'] ?? null ) !== ( $connection['site_id'] ?? null ) ) { return $invalid( 400 ); }
        return $event;
    }

    public static function ready( $request ) {
        $connection = Nova_Bridge_Suite_Posting_Settings::connection();
        $event = self::authenticate( (string) $request->get_body(), (string) $request->get_header( 'x-nova-timestamp' ), (string) $request->get_header( 'x-nova-signature' ), $connection );
        if ( is_wp_error( $event ) ) { return $event; }
        if ( empty( $connection['enabled'] ) ) { return new WP_Error( 'nova_posting_disabled', 'Delivery intake is disabled.', [ 'status' => 503 ] ); }
        if ( ! self::ensure_schema() ) { return new WP_Error( 'nova_posting_storage', 'Discovery storage is unavailable.', [ 'status' => 503 ] ); }
        $woken = ( new Nova_Bridge_Suite_Posting_Discovery( $connection ) )->wake();
        if ( is_wp_error( $woken ) ) { return $woken; }
        // Only the durable wake is acknowledged. Discovery supplies authoritative work identities.
        return self::response( [ 'accepted' => true, 'wake_accepted' => true ], 202 );
    }

    public static function status( $request ) {
        $permission = self::can_admin();
        if ( is_wp_error( $permission ) ) { return $permission; }
        $connection = Nova_Bridge_Suite_Posting_Settings::connection();
        if ( ! empty( $connection['enabled'] ) && ! self::ensure_schema() ) { return new WP_Error( 'nova_posting_storage', 'Discovery storage is unavailable.', [ 'status' => 503 ] ); }
        $store = new Nova_Bridge_Suite_Posting_Jobs();
        $exists = $store->storage_exists(); if ( is_wp_error( $exists ) ) { return $exists; }
        $jobs = $exists ? $store->summaries( $connection['site_id'] ?? '' ) : [];
        if ( is_wp_error( $jobs ) ) { return $jobs; }
        $discovery = self::SCHEMA_VERSION === (int) get_option( self::SCHEMA_OPTION, 0 ) ? $store->discovery_state( $connection['site_id'] ?? '' ) : [];
        $public_state = is_wp_error( $discovery ) ? [ 'last_error' => 'discovery_storage' ] : array_intersect_key( $discovery, array_flip( [ 'cycle_started', 'last_completed', 'next_attempt', 'failures', 'last_error', 'suspended', 'auth_status' ] ) );
        return self::response( [ 'enabled' => ! empty( $connection['enabled'] ), 'paused' => ! empty( $connection['paused'] ), 'next_scheduled' => wp_next_scheduled( self::CRON_HOOK ) ?: null, 'jobs' => $jobs, 'discovery' => $public_state ] );
    }

    public static function resume( $request ) {
        $permission = self::can_admin();
        if ( is_wp_error( $permission ) ) { return $permission; }
        $connection = Nova_Bridge_Suite_Posting_Settings::connection();
        $result = ( new Nova_Bridge_Suite_Posting_Jobs() )->resume( (int) $request->get_param( 'id' ), $connection['site_id'] ?? '' );
        return is_wp_error( $result ) ? $result : self::response( [ 'resumed' => true, 'message' => 'The saved phase will be retried. An uncertain mutation is reconciled before any new write.' ] );
    }
}
