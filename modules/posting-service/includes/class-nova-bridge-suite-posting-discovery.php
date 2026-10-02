<?php
/** Bounded polling of immutable deliveries. Notifications only wake this authenticated feed. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Nova_Bridge_Suite_Posting_Discovery {
    public const CYCLE_SECONDS = 300;
    private $connection;
    private $jobs;
    private $client;
    private $clock;

    public function __construct( array $connection, $jobs = null, $client = null, $clock = null ) {
        $this->connection = $connection;
        $this->jobs = $jobs ?: new Nova_Bridge_Suite_Posting_Jobs();
        $this->client = $client ?: new Nova_Bridge_Suite_Posting_Client( $connection );
        $this->clock = $clock ?: static function () { return time(); };
    }

    private static function error( string $code, int $status = 409 ): WP_Error {
        return new WP_Error( 'nova_posting_' . $code, 'Contracted discovery requires attention (' . $code . ').', [ 'status' => $status, 'error_code' => $code ] );
    }

    public static function valid_site( string $site ): bool {
        return Nova_Bridge_Suite_Posting_Protocol::uuid( $site );
    }

    /** A small strict boundary also protects independent storage callers and offline tests. */
    public static function validate_item( array $item ) {
        $keys = [ 'id', 'content_item_version_id', 'source_sha256', 'created_at' ];
        if ( count( $item ) !== count( $keys ) || array_diff( $keys, array_keys( $item ) ) ) { return self::error( 'discovery_schema' ); }
        if ( ! Nova_Bridge_Suite_Posting_Protocol::uuid( $item['id'] ) || ! Nova_Bridge_Suite_Posting_Protocol::decimal_id( $item['content_item_version_id'] ) || ! is_string( $item['source_sha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $item['source_sha256'] ) || ! Nova_Bridge_Suite_Posting_Protocol::date_time( $item['created_at'] ) ) { return self::error( 'discovery_identity' ); }
        return true;
    }

    public function wake() {
        if ( empty( $this->connection['enabled'] ) ) { return self::error( 'disabled', 503 ); }
        return $this->jobs->request_discovery( (string) ( $this->connection['site_id'] ?? '' ) );
    }

    /** Only an authorized administrator calls this after repairing site/token eligibility. */
    public function retry_authentication() {
        if ( empty( $this->connection['enabled'] ) ) { return self::error( 'disabled', 503 ); }
        $site = (string) ( $this->connection['site_id'] ?? '' );
        if ( ! self::valid_site( $site ) ) { return self::error( 'discovery_site' ); }
        $lock = 'discovery:' . $site; $owned = $this->jobs->lock( $lock );
        if ( is_wp_error( $owned ) ) { return $owned; }
        try {
            $state = $this->jobs->discovery_state( $site );
            if ( is_wp_error( $state ) ) { return $state; }
            if ( empty( $state['suspended'] ) ) { return self::error( 'discovery_not_suspended' ); }
            $state['suspended'] = false; $state['auth_status'] = 0; $state['next_attempt'] = 0; $state['failures'] = 0;
            $state['cycle_started'] = 0; $state['last_completed'] = 0; $state['last_error'] = '';
            return $this->jobs->save_discovery_state( $site, $state );
        } finally { $this->jobs->unlock( $lock ); }
    }

    private function connection_key( string $site ): string {
        return hash( 'sha256', wp_json_encode( [ $site, $this->connection['base_url'] ?? '', $this->connection['token'] ?? '' ] ) );
    }

    /** A worker authentication failure suspends this credential without losing pending work. */
    public function suspend( WP_Error $error ) {
        $data = (array) $error->get_error_data();
        $status = (int) ( $data['status'] ?? 0 );
        if ( ! in_array( $status, [ 401, 403 ], true ) ) { return $error; }
        $site = (string) ( $this->connection['site_id'] ?? '' );
        if ( ! self::valid_site( $site ) ) { return self::error( 'discovery_site' ); }
        $lock = 'discovery:' . $site; $owned = $this->jobs->lock( $lock );
        if ( is_wp_error( $owned ) ) { return $owned; }
        try {
            $state = $this->jobs->discovery_state( $site );
            if ( is_wp_error( $state ) ) { return $state; }
            $state['connection_key'] = $this->connection_key( $site );
            return $this->failure( $site, $state, $error, (int) call_user_func( $this->clock ) );
        } finally { $this->jobs->unlock( $lock ); }
    }

    private function failure( string $site, array $state, $error, int $now ) {
        $data = is_wp_error( $error ) ? (array) $error->get_error_data() : [];
        $status = (int) ( $data['status'] ?? 502 );
        $code = $data['error_code'] ?? ( is_wp_error( $error ) ? $error->get_error_code() : 'discovery_unavailable' );
        $state['last_error'] = is_string( $code ) && preg_match( '/^[a-z0-9_]{1,100}$/D', $code ) ? $code : 'discovery_unavailable';
        $state['failures'] = min( 8, (int) $state['failures'] + 1 );
        $delay = min( self::CYCLE_SECONDS, 15 * ( 2 ** ( $state['failures'] - 1 ) ) );
        $next_attempt = $now + $delay;
        if ( in_array( $status, [ 429, 503 ], true ) && is_string( $data['retry_after'] ?? null ) && '' !== $data['retry_after'] ) {
            $retry = trim( $data['retry_after'] );
            if ( ctype_digit( $retry ) ) {
                $seconds = ltrim( $retry, '0' ); $limit = (string) ( PHP_INT_MAX - $now );
                // Compare decimal strings before casting or adding. Unrepresentable delays
                // stop automatic discovery instead of wrapping or shortening the server delay.
                if ( strlen( $seconds ) > strlen( $limit ) || ( strlen( $seconds ) === strlen( $limit ) && strcmp( $seconds, $limit ) > 0 ) ) {
                    $next_attempt = PHP_INT_MAX; $state['last_error'] = 'retry_after_out_of_bounds';
                } else { $next_attempt = max( $next_attempt, $now + (int) $seconds ); }
            } else {
                $retry_at = strtotime( $retry );
                if ( false !== $retry_at ) { $next_attempt = max( $next_attempt, $retry_at ); }
            }
        }
        $state['suspended'] = in_array( $status, [ 401, 403 ], true );
        $state['auth_status'] = $state['suspended'] ? $status : 0;
        $state['next_attempt'] = $next_attempt;
        $saved = $this->jobs->save_discovery_state( $site, $state );
        return is_wp_error( $saved ) ? $saved : $error;
    }

    /** At most two pages by default; a restart resumes only a durably saved cursor. */
    public function run( int $max_pages = 2 ) {
        if ( empty( $this->connection['enabled'] ) ) { return [ 'state' => 'disabled', 'pages' => 0, 'items' => 0 ]; }
        $site = (string) ( $this->connection['site_id'] ?? '' );
        if ( ! self::valid_site( $site ) ) { return self::error( 'discovery_site' ); }
        $lock = 'discovery:' . $site;
        $owned = $this->jobs->lock( $lock );
        if ( is_wp_error( $owned ) ) { return $owned; }
        try {
            $state = $this->jobs->discovery_state( $site );
            if ( is_wp_error( $state ) ) { return $state; }
            $now = (int) call_user_func( $this->clock );
            $connection_key = $this->connection_key( $site );
            $changed = ! hash_equals( (string) $state['connection_key'], $connection_key );
            $origin_key = hash( 'sha256', wp_json_encode( [ $site, $this->connection['base_url'] ?? '' ] ) );
            $reset = ( $state['checkpoint_protocol'] ?? null ) !== Nova_Bridge_Suite_Posting_Protocol::SNAPSHOT || ( $state['origin_key'] ?? null ) !== $origin_key;
            if ( $reset ) { $state['cursor'] = null; $state['cycle_started'] = 0; $state['last_completed'] = 0; $state['checkpoint_protocol'] = Nova_Bridge_Suite_Posting_Protocol::SNAPSHOT; $state['origin_key'] = $origin_key; }
            if ( $changed || $reset ) { $state['connection_key'] = $connection_key; $state['suspended'] = false; $state['auth_status'] = 0; $state['next_attempt'] = 0; $state['cycle_started'] = 0; $state['failures'] = 0; }
            if ( null !== $state['cursor'] && ! Nova_Bridge_Suite_Posting_Protocol::decimal_id( $state['cursor'] ) ) { return self::error( 'discovery_checkpoint' ); }
            if ( ! empty( $state['suspended'] ) ) { return self::error( 'discovery_auth_suspended', 403 === (int) ( $state['auth_status'] ?? 401 ) ? 403 : 401 ); }
            if ( PHP_INT_MAX === $state['next_attempt'] || $state['next_attempt'] > $now ) { return [ 'state' => 'backoff', 'pages' => 0, 'items' => 0 ]; }
            $wake = $state['wake_sequence'] > $state['consumed_sequence'];
            $active = ! empty( $state['cycle_started'] );
            if ( ! $changed && ! $reset && ! $wake && ! $active && $state['last_completed'] + self::CYCLE_SECONDS > $now ) { return [ 'state' => 'idle', 'pages' => 0, 'items' => 0 ]; }
            // Finish a resumable cycle before consuming a newer wake; frequent hints must not
            // repeatedly reset its cursor and starve released items on later pages.
            if ( ! $active ) {
                $state['cycle_started'] = $now; $state['consumed_sequence'] = $state['wake_sequence'];
                $state['last_error'] = ''; $state['failures'] = 0;
                $saved = $this->jobs->save_discovery_state( $site, $state );
                if ( is_wp_error( $saved ) ) { return $saved; }
            }
            $pages = 0; $items = 0; $started = microtime( true );
            $max_pages = max( 1, min( 5, $max_pages ) );
            while ( $pages < $max_pages && microtime( true ) - $started < 20 ) {
                $path = '/deliveries?limit=100';
                if ( null !== $state['cursor'] ) { $path .= '&cursor=' . rawurlencode( $state['cursor'] ); }
                $reply = $this->client->site_request( 'GET', $path ); ++$pages;
                $now = (int) call_user_func( $this->clock );
                if ( is_wp_error( $reply ) ) {
                    return $this->failure( $site, $state, $reply, $now );
                }
                $body = $reply['body'] ?? null;
                if ( isset( $reply['raw_body'] ) ) {
                    $wire = json_decode( $reply['raw_body'], false, 64 );
                    $valid = JSON_ERROR_NONE === json_last_error() ? Nova_Bridge_Suite_Posting_Protocol::validate( $wire, 'DeliveryPage' ) : self::error( 'discovery_schema' );
                    if ( is_wp_error( $valid ) || false === $valid ) { return $this->failure( $site, $state, is_wp_error( $valid ) ? $valid : self::error( 'discovery_schema' ), $now ); }
                    $body = json_decode( $reply['raw_body'], true, 64 );
                }
                if ( 200 !== (int) ( $reply['status'] ?? 0 ) || ! is_array( $body ) || count( $body ) !== 2 || ! array_key_exists( 'next_cursor', $body ) || ! is_array( $body['items'] ?? null ) || array_values( $body['items'] ) !== $body['items'] || count( $body['items'] ) > 100 || ( null !== $body['next_cursor'] && ! Nova_Bridge_Suite_Posting_Protocol::decimal_id( $body['next_cursor'] ) ) ) {
                    return $this->failure( $site, $state, self::error( 'discovery_page' ), $now );
                }
                $next = $body['next_cursor']; $previous = $state['cursor'];
                $advanced = null !== $next && ( null === $previous || strlen( $next ) > strlen( $previous ) || ( strlen( $next ) === strlen( $previous ) && strcmp( $next, $previous ) > 0 ) );
                if ( $body['items'] ? ! $advanced : $next !== $previous ) { return $this->failure( $site, $state, self::error( 'discovery_checkpoint' ), $now ); }
                // Validate the whole page before admitting any part; cursor advances only after all inserts.
                $seen = [];
                foreach ( $body['items'] as $item ) {
                    $valid = is_array( $item ) ? self::validate_item( $item ) : self::error( 'discovery_schema' );
                    if ( is_wp_error( $valid ) ) { return $this->failure( $site, $state, $valid, $now ); }
                    if ( isset( $seen[ $item['id'] ] ) ) { return $this->failure( $site, $state, self::error( 'discovery_duplicate' ), $now ); }
                    $seen[ $item['id'] ] = true;
                }
                foreach ( $body['items'] as $item ) {
                    $accepted = $this->jobs->enqueue_discovered( $site, $item, $next );
                    if ( is_wp_error( $accepted ) ) { return $this->failure( $site, $state, $accepted, $now ); }
                    ++$items;
                }
                $state['cursor'] = $body['next_cursor']; $state['next_attempt'] = 0; $state['failures'] = 0; $state['last_error'] = '';
                $complete = count( $body['items'] ) < 100;
                if ( $complete ) { $state['cycle_started'] = 0; $state['last_completed'] = $now; }
                $saved = $this->jobs->save_discovery_state( $site, $state );
                if ( is_wp_error( $saved ) ) { return $saved; }
                if ( $complete ) { break; }
            }
            return [ 'state' => empty( $state['cycle_started'] ) ? 'complete' : 'continuing', 'pages' => $pages, 'items' => $items ];
        } finally { $this->jobs->unlock( $lock ); }
    }
}
