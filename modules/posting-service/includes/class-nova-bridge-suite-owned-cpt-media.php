<?php
/** Download and retain an image once per native publishing operation. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Owned_CPT_Media {
    private const LIMIT = 10485760;
    private static function key( string $op, string $url ): string { return 'nova_cpt_media_' . hash( 'sha256', $op . ':' . $url ); }
    private static function marker( string $op, string $url ): string { return 'nova.cpt-media/v1:' . hash( 'sha256', $op . ':' . $url ); }
    private static function save( string $key, array $value ): bool {
        if ( null === get_option( $key, null ) ) { add_option( $key, $value, '', false ); } else { update_option( $key, $value, false ); }
        wp_cache_delete( $key, 'options' ); return get_option( $key, null ) === $value;
    }
    private static function existing( string $marker ): int {
        global $wpdb;
        if ( ! isset( $wpdb->posts ) || ! preg_match( '/^[A-Za-z0-9_]+$/D', $wpdb->posts ) ) { throw new RuntimeException( 'Media storage unavailable.' ); }
        $ids = $wpdb->get_col( $wpdb->prepare( 'SELECT ID FROM `' . $wpdb->posts . '` WHERE post_type = %s AND post_content_filtered = %s ORDER BY ID', 'attachment', $marker ) );
        if ( ! is_array( $ids ) || ! empty( $wpdb->last_error ) || count( $ids ) > 1 ) { throw new RuntimeException( 'Media recovery is ambiguous.' ); }
        return $ids ? (int) $ids[0] : 0;
    }
    /** Returns the retained attachment, or zero for a known optional-media failure. */
    public static function resolve( string $op, string $url, int $post_id, string $alt = '' ): int {
        $key = self::key( $op, $url ); $marker = self::marker( $op, $url );
        $record = get_option( $key, null );
        if ( is_array( $record ) && 'skipped' === ( $record['state'] ?? '' ) ) { return 0; }
        if ( is_array( $record ) && 'complete' === ( $record['state'] ?? '' ) ) {
            $id = (int) ( $record['attachment_id'] ?? 0 );
            if ( ! $id || self::existing( $marker ) !== $id || ! wp_attachment_is_image( $id ) ) { throw new RuntimeException( 'Retained image is unavailable.' ); }
            if ( '' !== $alt ) { update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) ); }
            return $id;
        }
        if ( null !== $record && ( ! is_array( $record ) || 'preparing' !== ( $record['state'] ?? '' ) ) ) { throw new RuntimeException( 'Invalid media journal.' ); }
        $id = self::existing( $marker );
        if ( ! $id ) {
            if ( ! current_user_can( 'upload_files' ) || ! wp_http_validate_url( $url ) || ! preg_match( '#^https?://#i', $url ) ) { return self::skip( $key, $op, 'invalid_or_forbidden' ); }
            if ( ! self::save( $key, [ 'state' => 'preparing' ] ) ) { throw new RuntimeException( 'Cannot retain media progress.' ); }
            if ( ! function_exists( 'wp_tempnam' ) ) { require_once ABSPATH . 'wp-admin/includes/file.php'; }
            if ( ! function_exists( 'media_handle_sideload' ) ) { require_once ABSPATH . 'wp-admin/includes/media.php'; }
            if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) { require_once ABSPATH . 'wp-admin/includes/image.php'; }
            $tmp = wp_tempnam( 'nova-image' );
            if ( ! $tmp ) { return self::skip( $key, $op, 'temporary_file' ); }
            try {
                $response = wp_safe_remote_get( $url, [ 'timeout' => 20, 'redirection' => 0, 'stream' => true, 'filename' => $tmp, 'limit_response_size' => self::LIMIT + 1 ] );
                $size = @filesize( $tmp );
                if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) || false === $size || $size < 1 || $size > self::LIMIT ) { return self::skip( $key, $op, 'download' ); }
                $info = @getimagesize( $tmp );
                $types = [ 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp' ];
                if ( ! is_array( $info ) || ! isset( $types[$info['mime'] ?? ''] ) || empty( $info[0] ) || empty( $info[1] ) || (float) $info[0] * (float) $info[1] > 40000000 ) { return self::skip( $key, $op, 'invalid_image' ); }
                $name = 'nova-' . substr( hash( 'sha256', $op . ':' . $url ), 0, 20 ) . '.' . $types[$info['mime']];
                $file = [ 'name' => $name, 'tmp_name' => $tmp ];
                $protect = static function ( $data, $postarr, $raw ) use ( $marker ) { if ( ( $raw['post_content_filtered'] ?? null ) === $marker ) { $data['post_content_filtered'] = $marker; } return $data; };
                add_filter( 'wp_insert_post_data', $protect, PHP_INT_MAX, 3 );
                try { $id = media_handle_sideload( $file, $post_id, null, [ 'post_content_filtered' => $marker ] ); } finally { remove_filter( 'wp_insert_post_data', $protect, PHP_INT_MAX ); }
                if ( is_wp_error( $id ) || ! $id ) {
                    if ( self::existing( $marker ) ) { throw new RuntimeException( 'Media insertion needs recovery.' ); }
                    return self::skip( $key, $op, 'sideload' );
                }
                $id = (int) $id;
            } finally { if ( is_file( $tmp ) ) { @unlink( $tmp ); } }
        }
        if ( self::existing( $marker ) !== $id || ! wp_attachment_is_image( $id ) ) { throw new RuntimeException( 'Image attachment could not be verified.' ); }
        if ( ! wp_get_attachment_metadata( $id ) ) {
            if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) { require_once ABSPATH . 'wp-admin/includes/image.php'; }
            $file = get_attached_file( $id ); $metadata = $file ? wp_generate_attachment_metadata( $id, $file ) : false;
            if ( ! $metadata || ! wp_update_attachment_metadata( $id, $metadata ) ) { throw new RuntimeException( 'Image metadata needs recovery.' ); }
        }
        if ( '' !== $alt ) { update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) ); }
        if ( ! self::save( $key, [ 'state' => 'complete', 'attachment_id' => $id ] ) ) { throw new RuntimeException( 'Cannot retain media result.' ); }
        return $id;
    }
    private static function skip( string $key, string $op, string $reason ): int {
        if ( ! self::save( $key, [ 'state' => 'skipped' ] ) ) { throw new RuntimeException( 'Cannot retain media failure.' ); }
        error_log( 'NOVA CPT skipped optional image for operation ' . $op . ': ' . $reason ); return 0;
    }
    public static function retained( string $op, string $url ): int {
        $record = get_option( self::key( $op, $url ), null );
        if ( ! is_array( $record ) ) { return 0; }
        return 'complete' === ( $record['state'] ?? '' ) ? (int) ( $record['attachment_id'] ?? 0 ) : 0;
    }
}
