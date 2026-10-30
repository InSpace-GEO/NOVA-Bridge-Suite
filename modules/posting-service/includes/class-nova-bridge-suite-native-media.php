<?php
/** Shared native image resolution for explicit recipe bindings; no automatic placement. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-nova-bridge-suite-media-importer.php';

final class Nova_Bridge_Suite_Native_Media {
    private static function error( string $message ): WP_Error { return new WP_Error( 'nova_native_media', $message, [ 'status' => 409 ] ); }
    public static function has_featured( array $payload ): bool {
        foreach ( $payload['nova_media'] ?? [] as $entry ) { if ( is_array( $entry ) && ( $entry['kind'] ?? '' ) === 'featured' ) { return true; } }
        return false;
    }
    private static function pointer( string $target ): ?array {
        if ( ! preg_match( '#^/document/(?:0|[1-9][0-9]*)/(?:elements/(?:0|[1-9][0-9]*)/)*settings/(.+)/(url|id)$#D', $target ) || preg_match( '/~(?![01])/', $target ) ) { return null; }
        $parts = array_map( static function ( $part ) { return str_replace( [ '~1', '~0' ], [ '/', '~' ], $part ); }, explode( '/', substr( $target, strlen( '/document/' ) ) ) );
        return array_merge( [ 'elementor_data' ], $parts );
    }
    private static function place( array &$payload, array $path, $value ): bool {
        $leaf = array_pop( $path ); $current =& $payload;
        foreach ( $path as $part ) { if ( ! is_array( $current ) || ! array_key_exists( $part, $current ) || ! is_array( $current[$part] ) ) { return false; } $current =& $current[$part]; }
        // Media controls may omit their empty id/url leaf; only their existing object may be completed.
        $current[$leaf] = $value; return true;
    }
    public static function validate( string $builder, array $payload ) {
        if ( ! array_key_exists( 'nova_media', $payload ) ) { return true; }
        if ( ! is_array( $payload['nova_media'] ) ) { return self::error( 'The native image manifest is invalid.' ); }
        $featured = false; $targets = [];
        foreach ( $payload['nova_media'] as $target => $entry ) {
            if ( ! is_string( $target ) || ! is_array( $entry ) || ! is_string( $entry['url'] ?? null ) || ( isset( $entry['clear'] ) && ! is_bool( $entry['clear'] ) ) ) { return self::error( 'The native image binding is invalid.' ); }
            if ( ! empty( $entry['clear'] ) ? '' !== $entry['url'] : '' === trim( $entry['url'] ) ) { return self::error( 'The native image binding needs a source URL or an explicit clear instruction.' ); }
            if ( 'featured' === ( $entry['kind'] ?? '' ) ) {
                if ( $featured || '/native_post/featured_image' !== $target ) { return self::error( 'A recipe can bind its native featured image only once.' ); }
                $featured = true;
            } elseif ( 'elementor' === ( $entry['kind'] ?? '' ) && 'elementor' === $builder ) {
                $url_target = $entry['url_target'] ?? ''; $id_target = $entry['id_target'] ?? '';
                if ( ! is_string( $url_target ) || ! is_string( $id_target ) || $target !== $url_target || ! str_ends_with( $url_target, '/url' ) || substr( $url_target, 0, -4 ) . '/id' !== $id_target || isset( $targets[$url_target] ) ) { return self::error( 'The paired native image targets are invalid.' ); }
                $url_path = self::pointer( $url_target ); $id_path = self::pointer( $id_target ); $test = $payload;
                if ( ! $url_path || ! $id_path || ! self::place( $test, $url_path, '' ) || ! self::place( $test, $id_path, 0 ) ) { return self::error( 'A native image target no longer belongs to its compiled media control.' ); }
                $targets[$url_target] = true;
            } else { return self::error( 'This builder has no native handler for the selected image control.' ); }
        }
        return true;
    }
    /** Verification mode reads retained outcomes only; it never writes or downloads. */
    public static function resolve( string $builder, int $post_id, array $payload, string $operation, bool $allow_import = true ) {
        $valid = self::validate( $builder, $payload ); if ( is_wp_error( $valid ) ) { return $valid; }
        $manifest = $payload['nova_media'] ?? []; unset( $payload['nova_media'] ); $result = [ 'payload' => $payload ];
        if ( ! $manifest ) { return $result; }
        if ( $post_id < 1 || '' === $operation ) { return self::error( 'Native images require a retained post and publishing operation.' ); }
        $resolved = [];
        try {
            foreach ( $manifest as $entry ) {
                $id = 0; $url = '';
                if ( empty( $entry['clear'] ) ) {
                    $source = $entry['url'];
                    if ( ! array_key_exists( $source, $resolved ) ) {
                        if ( $allow_import ) { Nova_Bridge_Suite_Media_Importer::resolve( $operation, $source, $post_id ); }
                        $retained = Nova_Bridge_Suite_Media_Importer::retained_result( $operation, $source );
                        if ( null === $retained ) { return self::error( 'The native image import is unfinished. Resume the same publishing operation.' ); }
                        $resolved[$source] = $retained;
                    }
                    if ( 'skipped' === $resolved[$source]['state'] ) { continue; }
                    $id = $resolved[$source]['attachment_id']; $url = wp_get_attachment_url( $id );
                    if ( ! is_string( $url ) || '' === $url ) { return self::error( 'The retained native image has no readable attachment URL.' ); }
                }
                if ( 'featured' === $entry['kind'] ) { $result['featured'] = $id; }
                else { self::place( $result['payload'], self::pointer( $entry['url_target'] ), $url ); self::place( $result['payload'], self::pointer( $entry['id_target'] ), $id ); }
            }
        } catch ( Throwable $error ) { return self::error( 'The native image result is not yet verified. Recover this same operation before continuing.' ); }
        return $result;
    }
    public static function apply_featured( int $post_id, array $resolved ) {
        if ( ! array_key_exists( 'featured', $resolved ) ) { return true; }
        if ( $resolved['featured'] ) { set_post_thumbnail( $post_id, $resolved['featured'] ); } else { delete_post_thumbnail( $post_id ); }
        return self::verify_featured( $post_id, $resolved );
    }
    public static function verify_featured( int $post_id, array $resolved ) {
        return ! array_key_exists( 'featured', $resolved ) || (int) get_post_thumbnail_id( $post_id ) === $resolved['featured'] ? true : self::error( 'The saved featured image differs from the retained image binding.' );
    }
}
