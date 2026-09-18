<?php
/** Site-scoped posting-service HTTP transport. Credentials never leave this boundary. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Nova_Bridge_Suite_Posting_Client {
    private $connection;
    private const MAX_RESPONSE = 1048576;
    private const MAX_REQUEST = 262144;

    public function __construct( array $connection ) { $this->connection = $connection; }

    public function site_id(): string { return (string) ( $this->connection['site_id'] ?? '' ); }

    public function connection_error() {
        $url = $this->connection['base_url'] ?? '';
        $parts = is_string( $url ) ? wp_parse_url( $url ) : false;
        if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) || ! in_array( $parts['path'] ?? '', [ '', '/' ], true ) ) { return self::error( 'connection', 'Configure an HTTPS posting-service origin without a path or embedded credentials.', 503 ); }
        if ( ! self::uuid( $this->site_id() ) ) { return self::error( 'connection', 'Configure the publishing site UUID issued by NOVA.', 503 ); }
        $token = $this->connection['token'] ?? '';
        if ( ! is_string( $token ) || ! preg_match( '/^[\x21-\x7e]{16,2048}$/D', $token ) ) { return self::error( 'connection', 'Configure the site-scoped plugin credential issued by NOVA.', 503 ); }
        return null;
    }

    public static function uuid( string $value ): bool { return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $value ); }
    public static function id( $value ): bool { return is_string( $value ) && strlen( $value ) <= 19 && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) && ( strlen( $value ) < 19 || strcmp( $value, '9223372036854775807' ) <= 0 ); }

    private static function error( string $code, string $message, int $status, array $metadata = [] ): WP_Error {
        return new WP_Error( 'nova_posting_' . $code, $message, array_merge( [ 'status' => $status, 'error_code' => $code ], $metadata ) );
    }

    public function site_request( string $method, string $suffix, $body = null, array $headers = [] ) {
        if ( '/' !== substr( $suffix, 0, 1 ) ) { return self::error( 'path', 'A site API path is required.', 400 ); }
        return $this->request( $method, '/v1/sites/' . rawurlencode( $this->site_id() ) . $suffix, $body, $headers );
    }

    /** Returns status/body/etag/retry_after/request_id or a safe WP_Error. No automatic retries. */
    public function request( string $method, string $path, $body = null, array $headers = [] ) {
        $error = $this->connection_error();
        if ( $error ) { return $error; }
        if ( ! in_array( $method, [ 'GET', 'POST', 'PUT', 'DELETE' ], true ) || ! preg_match( '#^/v1/[A-Za-z0-9_/?=&%.-]+$#D', $path ) || preg_match( '/%0[ad]|%2f|%5c|\.\./i', $path ) || false !== strpos( $path, '//' ) ) { return self::error( 'path', 'Unsupported posting-service request path.', 400 ); }
        if ( 0 === strpos( $path, '/v1/sites/' ) && 0 !== strpos( $path, '/v1/sites/' . $this->site_id() . '/' ) ) { return self::error( 'site_scope', 'The request belongs to a different publishing site.', 403 ); }
        $request_headers = [ 'Accept' => 'application/json', 'Authorization' => 'Bearer ' . $this->connection['token'], 'X-Nova-Plugin-Version' => defined( 'NOVA_BRIDGE_SUITE_VERSION' ) ? NOVA_BRIDGE_SUITE_VERSION : '0.0.0' ];
        foreach ( $headers as $name => $value ) {
            $allowed = [ 'If-Match', 'If-None-Match', 'Idempotency-Key' ];
            if ( ! in_array( $name, $allowed, true ) || ! is_string( $value ) || strlen( $value ) > 512 || preg_match( '/[\r\n\x00]/', $value ) ) { return self::error( 'header', 'Unsupported posting-service request header.', 400 ); }
            $request_headers[ $name ] = $value;
        }
        $args = [ 'method' => $method, 'headers' => $request_headers, 'timeout' => 15, 'redirection' => 0, 'sslverify' => true, 'reject_unsafe_urls' => true, 'limit_response_size' => self::MAX_RESPONSE + 1 ];
        if ( null !== $body ) {
            if ( 'GET' === $method || ! is_array( $body ) ) { return self::error( 'request', 'The request body must be an object or array on a write operation.', 400 ); }
            $json = wp_json_encode( $body );
            if ( ! is_string( $json ) || strlen( $json ) > self::MAX_REQUEST ) { return self::error( 'request_size', 'The posting-service request exceeds 256 KiB.', 400 ); }
            $args['headers']['Content-Type'] = 'application/json'; $args['body'] = $json;
        }
        $response = wp_safe_remote_request( rtrim( $this->connection['base_url'], '/' ) . $path, $args );
        if ( is_wp_error( $response ) ) { return self::error( 'transport', 'The posting service could not be reached. Retry using the same operation identity.', 502 ); }
        $status = (int) wp_remote_retrieve_response_code( $response );
        $raw = (string) wp_remote_retrieve_body( $response );
        $metadata = [ 'retry_after' => self::safe_header( wp_remote_retrieve_header( $response, 'retry-after' ) ), 'request_id' => self::safe_header( wp_remote_retrieve_header( $response, 'x-request-id' ) ) ];
        if ( strlen( $raw ) > self::MAX_RESPONSE ) { return self::error( 'response_size', 'The posting-service response exceeds the supported size.', 502, $metadata ); }
        if ( $status >= 300 && 304 !== $status && $status < 400 ) { return self::error( 'redirect', 'Posting-service redirects are not followed with site credentials.', 502, $metadata ); }
        $decoded = null;
        if ( '' !== $raw && ! in_array( $status, [ 204, 304 ], true ) ) {
            $decoded = json_decode( $raw, true, 64 );
            if ( JSON_ERROR_NONE !== json_last_error() ) { return self::error( 'response_json', 'The posting service returned an invalid JSON response.', 502, $metadata ); }
        }
        if ( ( $status < 200 || $status >= 300 ) && 304 !== $status ) {
            $code = is_array( $decoded ) && is_string( $decoded['error']['code'] ?? null ) ? $decoded['error']['code'] : 'http_error';
            if ( ! preg_match( '/^[a-z0-9_]{1,80}$/D', $code ) ) { $code = 'http_error'; }
            // Raw service messages can contain configuration details; expose only code and protocol metadata.
            return self::error( $code, 'Posting service returned ' . $status . ' (' . $code . ').', $status >= 400 && $status <= 599 ? $status : 502, $metadata );
        }
        return array_merge( [ 'status' => $status, 'body' => $decoded, 'etag' => self::safe_header( wp_remote_retrieve_header( $response, 'etag' ) ) ], $metadata );
    }

    private static function safe_header( $value ): string {
        return is_scalar( $value ) ? substr( preg_replace( '/[\r\n\x00]/', '', (string) $value ), 0, 512 ) : '';
    }
}
