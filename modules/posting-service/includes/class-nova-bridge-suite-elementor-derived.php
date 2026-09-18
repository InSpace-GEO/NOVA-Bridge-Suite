<?php
/** Post-specific cache invalidation for the inspected Elementor 4.1.4 implementation. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Elementor_Derived {
    private const VERSION = '4.1.4';
    private const EVIDENCE = 'elementor-4.1.4-native-text-cache-v1';
    private const SOURCES = [
        'Elementor\\Core\\Files\\Base' => '45e186fd9a6a74ec834ba50ae45eb0aa68d0eb89cd6a98ac888695535c38e92a',
        'Elementor\\Core\\Files\\CSS\\Base' => 'c562e864e4669344f9102f60f744e85f4dfa3b5e51c7e47c2a9f7f881b0606ad',
        'Elementor\\Core\\Files\\CSS\\Post' => '43e215abce4270bb581f6bdd69272fa2af68cb89dcfe5d2f7d5f4e84128b328b',
        'Elementor\\Core\\Files\\Manager' => '873ddcc44624cd7853ef32bde81ee18204bd8d5d08121be9b5322b21fbf945b0',
        'Elementor\\Core\\Documents_Manager' => '95457811d6246ed6a6ed0ad0d7c922b38ed25583aad5548258802198cf31edb6',
        'Elementor\\Core\\Base\\Document' => '49f5f04be59557d181de4753d5d781ff29ca9b31bec0a46924ee6082b913c6ff',
    ];

    private static function error( string $message ): WP_Error { return new WP_Error( 'nova_elementor_derived_capability', $message, [ 'status' => 409 ] ); }
    public static function register() {
        $verified = self::source_capability();
        if ( is_wp_error( $verified ) ) { return $verified; }
        Nova_Bridge_Suite_Mapped_Writer::register_elementor_derived( self::VERSION, [ self::class, 'invalidate' ], self::EVIDENCE, [ self::class, 'preflight' ] );
        return true;
    }
    private static function source_capability() {
        if ( ! defined( 'ELEMENTOR_VERSION' ) || ELEMENTOR_VERSION !== self::VERSION ) { return self::error( 'The reviewed Elementor derived adapter requires version ' . self::VERSION . '.' ); }
        foreach ( self::SOURCES as $class => $expected ) {
            if ( ! class_exists( $class ) ) { return self::error( 'An inspected Elementor provider class is missing.' ); }
            $reflection = new ReflectionClass( $class ); $path = $reflection->getFileName();
            $source = is_string( $path ) && is_readable( $path ) ? file_get_contents( $path ) : false;
            if ( ! is_string( $source ) || ! hash_equals( $expected, hash( 'sha256', rtrim( str_replace( "\r\n", "\n", $source ), "\r\n" ) . "\n" ) ) ) { return self::error( 'The installed Elementor implementation differs from the inspected cache adapter source.' ); }
        }
        foreach ( [ 'elementor/files/file_name', 'elementor/documents/get/post_id' ] as $hook ) { if ( has_filter( $hook ) ) { return self::error( 'A custom Elementor file or document identity filter requires a reviewed adapter.' ); } }
        $plugin = \Elementor\Plugin::instance();
        if ( ! isset( $plugin->documents, $plugin->files_manager ) || get_class( $plugin->documents ) !== 'Elementor\\Core\\Documents_Manager' || get_class( $plugin->files_manager ) !== 'Elementor\\Core\\Files\\Manager' ) { return self::error( 'Custom Elementor manager implementations are outside this reviewed adapter.' ); }
        return true;
    }
    private static function css_path( int $post_id ) {
        if ( $post_id < 1 ) { return self::error( 'A concrete Elementor document is required.' ); }
        $uploads = wp_upload_dir( null, false );
        $base = empty( $uploads['error'] ) && isset( $uploads['basedir'] ) ? realpath( $uploads['basedir'] ) : false;
        if ( ! $base ) { return self::error( 'The local uploads directory cannot be verified.' ); }
        $directory = $base . DIRECTORY_SEPARATOR . 'elementor' . DIRECTORY_SEPARATOR . 'css';
        $existing = $directory;
        while ( ! is_dir( $existing ) && $existing !== $base ) { $existing = dirname( $existing ); }
        $resolved = realpath( $existing );
        if ( ! $resolved || ( $resolved !== $base && 0 !== strpos( $resolved, $base . DIRECTORY_SEPARATOR ) ) || ! is_writable( $resolved ) || is_link( $directory ) || is_link( dirname( $directory ) ) ) { return self::error( 'The document CSS directory is not a verified writable local uploads path.' ); }
        $path = $directory . DIRECTORY_SEPARATOR . 'post-' . $post_id . '.css';
        if ( is_link( $path ) || ( file_exists( $path ) && ( ! is_file( $path ) || ! is_writable( $path ) ) ) ) { return self::error( 'The document CSS cache is not a writable regular local file.' ); }
        return $path;
    }
    public static function preflight( array $plan ) {
        $capability = self::source_capability(); if ( is_wp_error( $capability ) ) { return $capability; }
        $path = self::css_path( (int) ( $plan['snapshot']['post']['ID'] ?? 0 ) ); if ( is_wp_error( $path ) ) { return $path; }
        return true;
    }
    private static function property( string $class, string $name ): ReflectionProperty {
        $property = new ReflectionProperty( $class, $name ); $property->setAccessible( true ); return $property;
    }

    /** Idempotent invalidation only. The reviewed provider rebuilds CSS/HTML lazily on frontend use. */
    public static function invalidate( int $post_id, array $context ) {
        $capability = self::source_capability(); if ( is_wp_error( $capability ) ) { return $capability; }
        if ( ( $context['allowed_meta'] ?? null ) !== [ '_elementor_css', '_elementor_element_cache' ] ) { return self::error( 'The derived-work allowlist differs from this adapter.' ); }
        $path = self::css_path( $post_id ); if ( is_wp_error( $path ) ) { return $path; }
        global $wpdb;
        if ( ! preg_match( '/^[A-Za-z0-9_]+$/D', $wpdb->postmeta ) ) { return self::error( 'The local metadata table is unsupported.' ); }
        $deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM `' . $wpdb->postmeta . '` WHERE post_id = %d AND meta_key IN (%s,%s)', $post_id, '_elementor_css', '_elementor_element_cache' ) );
        if ( false === $deleted ) { return self::error( 'The document cache metadata could not be invalidated.' ); }
        if ( file_exists( $path ) && ! unlink( $path ) ) { return self::error( 'The document CSS cache could not be removed.' ); }
        clearstatcache( true, $path );
        if ( file_exists( $path ) ) { return self::error( 'The document CSS cache still exists after invalidation.' ); }
        wp_cache_delete( $post_id, 'post_meta' ); wp_cache_delete( $post_id, 'posts' );
        $plugin = \Elementor\Plugin::instance();
        // These exact property names and cache keys are source-hash gated above. No save/render hooks run.
        $documents = self::property( 'Elementor\\Core\\Documents_Manager', 'documents' ); $cached = $documents->getValue( $plugin->documents ); $old = $cached[ $post_id ] ?? null; unset( $cached[ $post_id ] ); $documents->setValue( $plugin->documents, $cached );
        $current = self::property( 'Elementor\\Core\\Documents_Manager', 'current_doc' ); if ( $old && $current->getValue( $plugin->documents ) === $old ) { $current->setValue( $plugin->documents, null ); }
        $files = self::property( 'Elementor\\Core\\Files\\Manager', 'files' ); $cached = $files->getValue( $plugin->files_manager );
        foreach ( $cached as $key => $file ) { if ( is_object( $file ) && get_class( $file ) === 'Elementor\\Core\\Files\\CSS\\Post' && (int) $file->get_post_id() === $post_id ) { unset( $cached[ $key ] ); } } $files->setValue( $plugin->files_manager, $cached );
        $printed = self::property( 'Elementor\\Core\\Files\\CSS\\Base', 'printed' ); $cached = $printed->getValue(); unset( $cached[ 'elementor-post-' . $post_id ] ); $printed->setValue( null, $cached );
        $remaining = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM `' . $wpdb->postmeta . '` WHERE post_id = %d AND meta_key IN (%s,%s)', $post_id, '_elementor_css', '_elementor_element_cache' ) );
        if ( ! empty( $wpdb->last_error ) || '0' !== (string) $remaining ) { return self::error( 'Document cache invalidation could not be verified.' ); }
        return [ 'completed' => true, 'evidence_id' => self::EVIDENCE, 'css' => 'invalidated_for_lazy_regeneration', 'post_id' => $post_id ];
    }
}
