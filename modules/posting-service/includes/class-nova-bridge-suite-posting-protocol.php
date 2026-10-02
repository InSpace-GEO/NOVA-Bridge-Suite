<?php
/** Validation of the pinned delivery OpenAPI and byte-preserving snapshot intake. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Posting_Protocol {
    // Local journal markers, not members to add to the service's wire objects.
    public const SNAPSHOT = 'nova.delivery-snapshot/v1';
    public const RESULT = 'nova.connector-event/v1';
    private static $schemas;

    private static function schemas(): array {
        if ( null === self::$schemas ) {
            $contract = json_decode( file_get_contents( dirname( __DIR__ ) . '/contracts/delivery-v1.json' ), true );
            if ( ! is_array( $contract['schemas'] ?? null ) ) { throw new RuntimeException( 'Delivery contract is unavailable.' ); }
            self::$schemas = $contract['schemas'];
        }
        return self::$schemas;
    }

    public static function validate( $value, string $name ) {
        try {
            $schemas = self::schemas();
            if ( ! isset( $schemas[ $name ] ) || ! self::matches( $value, $schemas[ $name ], 0 ) ) { return self::error( 'schema', 'The response does not match the pinned ' . $name . ' schema.' ); }
            return true;
        } catch ( Throwable $error ) { return self::error( 'schema', 'The pinned delivery schema could not be verified.' ); }
    }

    private static function matches( $value, array $schema, int $depth ): bool {
        if ( $depth > 64 ) { return false; }
        if ( isset( $schema['$ref'] ) ) {
            $name = substr( $schema['$ref'], strlen( '#/components/schemas/' ) );
            return isset( self::schemas()[ $name ] ) && self::matches( $value, self::schemas()[ $name ], $depth + 1 );
        }
        if ( isset( $schema['oneOf'] ) ) {
            $count = 0;
            foreach ( $schema['oneOf'] as $option ) { $count += self::matches( $value, $option, $depth + 1 ) ? 1 : 0; }
            if ( 1 !== $count ) { return false; }
        }
        if ( isset( $schema['allOf'] ) ) { foreach ( $schema['allOf'] as $option ) { if ( ! self::matches( $value, $option, $depth + 1 ) ) { return false; } } }
        if ( isset( $schema['enum'] ) && ! in_array( $value, $schema['enum'], true ) ) { return false; }
        if ( null === $value && ! empty( $schema['nullable'] ) ) { return true; }
        $type = $schema['type'] ?? null;
        if ( 'object' === $type ) {
            // Callers handling wire JSON must pass stdClass: an empty array is not an object.
            if ( ! $value instanceof stdClass && ( ! is_array( $value ) || array_values( $value ) === $value ) ) { return false; }
            $members = (array) $value;
            foreach ( $schema['required'] ?? [] as $key ) { if ( ! array_key_exists( $key, $members ) ) { return false; } }
            foreach ( $members as $key => $child ) {
                if ( isset( $schema['propertyNames'] ) && ! self::matches( (string) $key, array_merge( [ 'type' => 'string' ], $schema['propertyNames'] ), $depth + 1 ) ) { return false; }
                if ( isset( $schema['properties'][ $key ] ) ) { if ( ! self::matches( $child, $schema['properties'][ $key ], $depth + 1 ) ) { return false; } }
                elseif ( false === ( $schema['additionalProperties'] ?? true ) ) { return false; }
                elseif ( is_array( $schema['additionalProperties'] ?? null ) && ! self::matches( $child, $schema['additionalProperties'], $depth + 1 ) ) { return false; }
            }
        } elseif ( 'array' === $type ) {
            if ( ! is_array( $value ) || array_values( $value ) !== $value || count( $value ) < ( $schema['minItems'] ?? 0 ) || count( $value ) > ( $schema['maxItems'] ?? PHP_INT_MAX ) ) { return false; }
            foreach ( $value as $child ) { if ( isset( $schema['items'] ) && ! self::matches( $child, $schema['items'], $depth + 1 ) ) { return false; } }
        } elseif ( 'string' === $type ) {
            if ( ! is_string( $value ) || 1 !== preg_match( '//u', $value ) ) { return false; }
            $length = preg_match_all( '/./us', $value );
            if ( $length < ( $schema['minLength'] ?? 0 ) || $length > ( $schema['maxLength'] ?? PHP_INT_MAX ) ) { return false; }
            if ( isset( $schema['pattern'] ) && 1 !== preg_match( '~' . str_replace( '~', '\\~', $schema['pattern'] ) . '~uD', $value ) ) { return false; }
            if ( 'date-time' === ( $schema['format'] ?? '' ) && ! self::date_time( $value ) ) { return false; }
            if ( 'uuid' === ( $schema['format'] ?? '' ) && ! self::uuid( $value ) ) { return false; }
        } elseif ( 'integer' === $type || 'number' === $type ) {
            if ( 'integer' === $type ? ! is_int( $value ) : ( ! is_int( $value ) && ! is_float( $value ) ) ) { return false; }
            if ( ! is_finite( (float) $value ) || $value < ( $schema['minimum'] ?? -INF ) || $value > ( $schema['maximum'] ?? INF ) ) { return false; }
        } elseif ( 'boolean' === $type && ! is_bool( $value ) ) { return false; }
        elseif ( 'null' === $type && null !== $value ) { return false; }
        return true;
    }

    public static function error( string $code, string $message ): WP_Error { return new WP_Error( 'nova_posting_' . $code, $message ); }

    public static function uuid( $value ): bool {
        return is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $value );
    }

    public static function decimal_id( $value ): bool {
        return is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]{0,18}$/D', $value ) && ( strlen( $value ) < 19 || strcmp( $value, '9223372036854775807' ) <= 0 );
    }

    /** RFC3339 dates from Node use milliseconds; offsets and omitted fractions are also valid. */
    public static function date_time( $value ): bool {
        if ( ! is_string( $value ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})[Tt](\d{2}):(\d{2}):(\d{2})(?:\.\d+)?(?:[Zz]|([+-])(\d{2}):(\d{2}))$/D', $value, $parts ) ) { return false; }
        return checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) && (int) $parts[4] <= 23 && (int) $parts[5] <= 59 && (int) $parts[6] <= 59 && ( ! isset( $parts[8] ) || ( (int) $parts[8] <= 23 && (int) $parts[9] <= 59 ) );
    }

    /** The SHA domain is the received bytes, never PHP's decoded/re-encoded representation. */
    public static function snapshot( array $response, array $job ) {
        $item = $job['payload']['discovery'] ?? [];
        $raw = $response['raw_body'] ?? null;
        $etag = $response['etag'] ?? null;
        if ( 200 !== (int) ( $response['status'] ?? 0 ) || ! is_string( $raw ) || ! is_string( $etag ) || ! preg_match( '/^"([a-f0-9]{64})"$/D', $etag, $match ) || ! hash_equals( $match[1], hash( 'sha256', $raw ) ) ) { return self::error( 'snapshot_integrity', 'The exact snapshot bytes do not match their strong response ETag.' ); }
        if ( ! self::uuid( $response['attempt_id'] ?? null ) ) { return self::error( 'snapshot_attempt', 'The service did not authorize a current connector attempt.' ); }
        $object = json_decode( $raw, false, 64 );
        if ( JSON_ERROR_NONE !== json_last_error() ) { return self::error( 'snapshot_json', 'The exact snapshot is not valid JSON.' ); }
        $valid = self::validate( $object, 'DeliverySnapshot' );
        if ( is_wp_error( $valid ) ) { return $valid; }
        $snapshot = json_decode( $raw, true, 64 );
        foreach ( [ 'id', 'content_item_version_id', 'source_sha256' ] as $key ) { if ( $snapshot[ $key ] !== ( $item[ $key ] ?? null ) ) { return self::error( 'snapshot_identity', 'The snapshot identity differs from authenticated discovery.' ); } }
        if ( $snapshot['site_id'] !== ( $job['site_id'] ?? null ) ) { return self::error( 'snapshot_site', 'The snapshot belongs to a different publishing site.' ); }
        foreach ( [ 'client_id', 'domain_id', 'url_id', 'content_item_id', 'content_item_version_id' ] as $key ) { if ( ! self::decimal_id( $snapshot[ $key ] ) ) { return self::error( 'snapshot_identity', 'A source identity is not a positive decimal bigint.' ); } }
        if ( $snapshot['version_number'] < 1 || $snapshot['version_number'] > 2147483647 || '' === trim( $snapshot['language'] ) ) { return self::error( 'snapshot_identity', 'The source version or language is invalid.' ); }
        // source_sha256 covers backend source content, not these delivery bytes. Do not equate
        // the two hashes or replace the original body attempt_id with current header authority.
        return $snapshot;
    }

    /** The schema describes conditional evidence in prose; enforce it before any event POST. */
    public static function event_input( $event ) {
        $valid = self::validate( $event, 'ConnectorEventInput' );
        if ( is_wp_error( $valid ) ) { return $valid; }
        $value = (array) $event;
        $success = 'publication_succeeded' === $value['kind'];
        $failure = in_array( $value['kind'], [ 'pull_failed', 'publication_failed' ], true );
        if ( ! self::decimal_id( $value['content_item_version_id'] ) || $value['version_number'] > 2147483647 || $failure !== array_key_exists( 'reason', $value ) || $success !== array_key_exists( 'published_at', $value ) || $success !== array_key_exists( 'publication_ref', $value ) ) { return self::error( 'event_evidence', 'The connector event does not contain exactly the evidence required for its outcome.' ); }
        return true;
    }

    /** A 202 acknowledges durable outgoing intent only; it never certifies backend application. */
    public static function accepted( array $response, $event ) {
        $valid = self::event_input( $event );
        if ( is_wp_error( $valid ) ) { return $valid; }
        if ( 202 !== (int) ( $response['status'] ?? 0 ) || ! is_string( $response['raw_body'] ?? null ) ) { return self::error( 'event_response', 'Expected the contracted pending event acknowledgement.' ); }
        $value = json_decode( $response['raw_body'], false, 64 );
        if ( JSON_ERROR_NONE !== json_last_error() || is_wp_error( self::validate( $value, 'ConnectorEventAccepted' ) ) ) { return self::error( 'event_response', 'The event acknowledgement does not match the current contract.' ); }
        $expected = (array) $event;
        foreach ( [ 'event_id', 'kind', 'site_id', 'delivery_id', 'attempt_id' ] as $key ) { if ( $value->$key !== $expected[ $key ] ) { return self::error( 'event_identity', 'The event acknowledgement belongs to different work.' ); } }
        return (array) $value;
    }

    public static function now(): string { return ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d\TH:i:s.u\Z' ); }
}
