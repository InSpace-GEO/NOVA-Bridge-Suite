<?php
/** Optional installation ownership for installations sharing a NOVA site credential. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Posting_Url_Scope {
    private static function error( string $message ) {
        return new WP_Error( 'nova_posting_url_scope', $message );
    }

    /** Decode path segments separately so encoded separators never widen ownership. */
    private static function path( string $path ) {
        if ( '' === $path ) { return '/'; }
        if ( '/' !== $path[0] || preg_match( '/[\\x00-\\x20\\x7f\\\\?#]/', $path ) || false !== strpos( $path, '//' ) || preg_match( '/%(?![a-f0-9]{2})/i', $path ) ) {
            return self::error( 'Use an unambiguous URL path beginning with /.' );
        }
        $segments = [];
        foreach ( explode( '/', trim( $path, '/' ) ) as $segment ) {
            $decoded = rawurldecode( $segment );
            if ( in_array( $decoded, [ '.', '..' ], true ) || preg_match( '/[\\x00-\\x20\\x7f\\\\\/]/', $decoded ) ) {
                return self::error( 'URL paths cannot contain dot segments or encoded separators.' );
            }
            $segments[] = $decoded;
        }
        return '/' . implode( '/', $segments );
    }

    private static function url( $url ) {
        if ( ! is_string( $url ) || '' === $url || preg_match( '/[\\x00-\\x20\\x7f\\\\]/', $url ) ) { return self::error( 'A valid public URL is required for installation routing.' ); }
        $parts = parse_url( $url );
        if ( ! is_array( $parts ) || ! in_array( strtolower( $parts['scheme'] ?? '' ), [ 'https', 'http' ], true ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) {
            return self::error( 'Use an HTTP or HTTPS public URL without credentials or a fragment.' );
        }
        $path = self::path( $parts['path'] ?? '/' );
        if ( is_wp_error( $path ) ) { return $path; }
        $scheme = strtolower( $parts['scheme'] );
        $port = $parts['port'] ?? ( 'https' === $scheme ? 443 : 80 );
        return [ 'origin' => $scheme . '://' . strtolower( $parts['host'] ) . ':' . $port, 'path' => $path ];
    }

    public static function exclusions( $value ) {
        if ( ! is_string( $value ) ) { return self::error( 'Enter excluded paths one per line.' ); }
        $paths = [];
        foreach ( preg_split( '/\\r\\n|\\r|\\n/', $value ) as $line ) {
            $line = trim( $line );
            if ( '' === $line ) { continue; }
            $path = self::path( $line );
            if ( is_wp_error( $path ) ) { return $path; }
            // Retain the URL spelling; decoding is only for comparisons.
            $paths[] = '/' === $line ? '/' : rtrim( $line, '/' );
        }
        return array_values( array_unique( $paths ) );
    }

    private static function contains( string $prefix, string $path ): bool {
        return '/' === $prefix || $path === $prefix || 0 === strpos( $path, $prefix . '/' );
    }

    /** True owns the URL, false is a local skip, WP_Error requires local review. */
    public static function owns( array $connection, $target ) {
        if ( empty( $connection['url_scope_enabled'] ) ) { return true; }
        // The stored public home avoids language filters narrowing the installation.
        $home_value = get_option( 'home', home_url( '/' ) );
        $home = self::url( $home_value );
        if ( is_wp_error( $home ) ) { return $home; }
        // A leading / is relative to the domain root, not the sub-site directory.
        if ( is_string( $target ) && '/' === substr( $target, 0, 1 ) && '//' !== substr( $target, 0, 2 ) ) {
            $parts = parse_url( $home_value );
            $target = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . $target;
        }
        $url = self::url( $target );
        if ( is_wp_error( $url ) ) { return $url; }
        if ( ! is_array( $connection['url_scope_excluded_paths'] ?? [] ) ) { return self::error( 'Review the excluded paths for this installation.' ); }
        $exclusions = [];
        foreach ( $connection['url_scope_excluded_paths'] ?? [] as $path ) {
            if ( ! is_string( $path ) ) { return self::error( 'Review the excluded paths for this installation.' ); }
            $normalized = self::path( $path );
            if ( is_wp_error( $normalized ) ) { return $normalized; }
            $exclusions[] = $normalized;
        }
        if ( $url['origin'] !== $home['origin'] || ! self::contains( $home['path'], $url['path'] ) ) { return false; }
        foreach ( $exclusions as $path ) { if ( self::contains( $path, $url['path'] ) ) { return false; } }
        return true;
    }
}
