<?php
/** Capture and verify registered Elementor page presentation controls. */
namespace SEOR_Elementor_Bridge;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Document_Presentation {
    private const EXCLUDED = [ 'id', 'post_title', 'post_excerpt', 'post_content', 'post_content_filtered', 'post_status', 'post_name', 'post_author', 'post_parent', 'post_type', 'post_password', 'post_id', 'menu_order', 'comment_status', 'ping_status', 'post_featured_image', 'template', '__dynamic__', '__globals__' ];

    private static function error( $message ) { return new \WP_Error( 'seor_eb_document_presentation', $message ); }
    private static function permitted_key( $key ) {
        return is_string( $key ) && preg_match( '/^[a-zA-Z][a-zA-Z0-9_-]{0,127}$/D', $key ) && ! in_array( $key, self::EXCLUDED, true ) && ! preg_match( '/(?:^|[_-])(?:id|user|author|role|permission|capability|token|password|secret|credential)(?:$|[_-])/i', $key );
    }
    /** Bound serialized design values while preserving site-local media and global style references. */
    private static function safe_value( $value, $depth = 0 ) {
        if ( $depth > 8 ) { return false; }
        if ( is_string( $value ) ) { return strlen( $value ) <= 8192 && stripos( $value, '[elementor-tag' ) === false; }
        if ( is_float( $value ) ) { return is_finite( $value ); }
        if ( is_int( $value ) || is_bool( $value ) || null === $value ) { return true; }
        if ( ! is_array( $value ) || count( $value ) > 200 ) { return false; }
        foreach ( $value as $key => $part ) {
            if ( is_string( $key ) && ( in_array( strtolower( $key ), [ '__dynamic__', '__globals__' ], true ) || ! preg_match( '/^[a-zA-Z0-9_-]{1,128}$/D', $key ) ) ) { return false; }
            if ( ! self::safe_value( $part, $depth + 1 ) ) { return false; }
        }
        return true;
    }
    public static function validate( $settings ) {
        if ( ! is_array( $settings ) || count( $settings ) > 500 || strlen( (string) wp_json_encode( $settings ) ) > 131072 ) { return self::error( 'The document presentation settings are invalid or too large.' ); }
        $globals = $settings['__globals__'] ?? [];
        if ( ! is_array( $globals ) ) { return self::error( 'Elementor global style references are invalid.' ); }
        foreach ( $globals as $key => $value ) {
            if ( ! self::permitted_key( $key ) || ! array_key_exists( $key, $settings ) || ! is_string( $value ) || strlen( $value ) > 1024 || preg_match( '/[\x00-\x1f\x7f]/', $value ) || stripos( $value, '[elementor-tag' ) !== false ) { return self::error( 'An Elementor global style reference is invalid.' ); }
        }
        foreach ( $settings as $key => $value ) {
            if ( '__globals__' === $key ) { continue; }
            if ( ! self::permitted_key( $key ) || ! self::safe_value( $value ) ) { return self::error( 'The document presentation contains an unsupported control or value.' ); }
        }
        return true;
    }
    /** Some provider control defaults call get_the_ID(); restore the original global even on failure. */
    public static function with_post( $id, callable $callback ) {
        $previous_exists = array_key_exists( 'post', $GLOBALS ); $previous = $GLOBALS['post'] ?? null;
        $post = get_post( $id ); if ( ! $post || ! current_user_can( 'edit_post', $id ) ) { return self::error( 'The Elementor page cannot be inspected with this permission.' ); }
        $GLOBALS['post'] = $post;
        try { return $callback(); }
        catch ( \Throwable $e ) { return self::error( 'Elementor could not read document presentation settings.' ); }
        finally { if ( $previous_exists ) { $GLOBALS['post'] = $previous; } else { unset( $GLOBALS['post'] ); } }
    }
    private static function document( $id ) {
        if ( ! class_exists( '\\Elementor\\Plugin' ) ) { return self::error( 'Elementor is unavailable.' ); }
        $plugin = \Elementor\Plugin::instance();
        if ( ! isset( $plugin->documents, $plugin->controls_manager ) || ! method_exists( $plugin->documents, 'get' ) || ! method_exists( $plugin->controls_manager, 'get_control' ) || ! method_exists( $plugin->controls_manager, 'delete_stack' ) ) { return self::error( 'The Elementor document control registry is unavailable.' ); }
        $document = $plugin->documents->get( $id, false );
        if ( ! is_object( $document ) || ! method_exists( $document, 'get_controls' ) || ! method_exists( $document, 'get_settings' ) ) { return self::error( 'The Elementor document settings are unavailable.' ); }
        // The manager caches each document's control defaults independently of document instances.
        $plugin->controls_manager->delete_stack( $document );
        return [ $document, $plugin->controls_manager ];
    }
    private static function is_data_control( $control, $manager ) {
        if ( ! is_array( $control ) || ! is_string( $control['type'] ?? null ) ) { return false; }
        $instance = $manager->get_control( $control['type'] );
        return $instance instanceof \Elementor\Base_Data_Control;
    }
    /** Effective values of registered controls; unregistered or unsafe controls are omitted. */
    public static function capture( $id ) {
        return self::with_post( $id, static function () use ( $id ) {
            $parts = self::document( $id ); if ( is_wp_error( $parts ) ) { return $parts; }
            [ $document, $manager ] = $parts; $controls = $document->get_controls(); $settings = $document->get_settings();
            if ( ! is_array( $controls ) || ! is_array( $settings ) ) { return self::error( 'Elementor did not return valid document settings.' ); }
            $dynamic = is_array( $settings['__dynamic__'] ?? null ) ? $settings['__dynamic__'] : [];
            $globals = is_array( $settings['__globals__'] ?? null ) ? $settings['__globals__'] : [];
            $result = [];
            foreach ( $controls as $key => $control ) {
                if ( self::permitted_key( $key ) && self::is_data_control( $control, $manager ) && array_key_exists( $key, $settings ) && ! array_key_exists( $key, $dynamic ) && self::safe_value( $settings[$key] ) ) { $result[$key] = $settings[$key]; }
            }
            if ( array_key_exists( '__globals__', $settings ) ) {
                $result['__globals__'] = [];
                foreach ( $globals as $key => $value ) { if ( self::permitted_key( $key ) && array_key_exists( $key, $result ) ) { $result['__globals__'][$key] = $value; } }
            }
            $stored = get_post_meta( $id, '_elementor_page_settings', true );
            if ( '' !== $stored && ! is_array( $stored ) ) { return self::error( 'Stored Elementor page settings are unreadable; review them before saving this mapping.' ); }
            foreach ( is_array( $stored ) ? $stored : [] as $key => $_ ) {
                if ( in_array( $key, self::EXCLUDED, true ) && ! in_array( $key, [ '__dynamic__', '__globals__' ], true ) ) { continue; }
                if ( ! array_key_exists( $key, $result ) || ( '__globals__' === $key && ( ! is_array( $stored[$key] ) || count( $stored[$key] ) !== count( $result[$key] ) ) ) ) { return self::error( 'Stored Elementor page setting requires review before it can be copied: ' . (string) $key ); }
            }
            $valid = self::validate( $result ); return is_wp_error( $valid ) ? $valid : $result;
        } );
    }
    /** Require all frozen controls to exist in the active provider before any native save. */
    public static function assert_supported( $id, $settings ) {
        $valid = self::validate( $settings ); if ( is_wp_error( $valid ) ) { return $valid; }
        return self::with_post( $id, static function () use ( $id, $settings ) {
            $parts = self::document( $id ); if ( is_wp_error( $parts ) ) { return $parts; }
            [ $document, $manager ] = $parts; $controls = $document->get_controls();
            if ( ! is_array( $controls ) ) { return self::error( 'The Elementor document control registry is unavailable.' ); }
            foreach ( $settings as $key => $_ ) { if ( '__globals__' === $key ) { continue; } if ( ! isset( $controls[$key] ) || ! self::is_data_control( $controls[$key], $manager ) ) { return self::error( 'A saved document presentation control is no longer registered: ' . $key ); } }
            return true;
        } );
    }
    public static function read_selected( $id, $expected ) {
        $supported = self::assert_supported( $id, $expected ); if ( is_wp_error( $supported ) ) { return $supported; }
        $actual = self::capture( $id ); if ( is_wp_error( $actual ) ) { return $actual; }
        $selected = [];
        foreach ( $expected as $key => $value ) {
            if ( ! array_key_exists( $key, $actual ) ) { return self::error( 'The saved Elementor document presentation is unavailable at ' . $key . '.' ); }
            if ( '__globals__' === $key ) { $selected[$key] = array_intersect_key( $actual[$key], $value ); } else { $selected[$key] = $actual[$key]; }
        }
        return $selected;
    }
    public static function verify( $id, $expected ) {
        $actual = self::read_selected( $id, $expected ); if ( is_wp_error( $actual ) ) { return $actual; }
        foreach ( $expected as $key => $value ) { if ( $actual[$key] !== $value ) { return self::error( 'The saved Elementor document presentation differs at ' . $key . '.' ); } }
        return true;
    }
}
