<?php
/** Capture and verify Elementor page presentation controls and retained literal settings. */
namespace SEOR_Elementor_Bridge;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Document_Presentation {
    /** Snapshot-only namespace; never send this marker to Elementor. */
    public const RETAINED = '__retained__';
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
    private static function validate_values( array $settings ) {
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
    public static function validate( $settings ) {
        if ( ! is_array( $settings ) || count( $settings ) > 500 || strlen( (string) wp_json_encode( $settings ) ) > 131072 ) { return self::error( 'The document presentation settings are invalid or too large.' ); }
        $registered = $settings; unset( $registered[self::RETAINED] );
        $valid = self::validate_values( $registered ); if ( is_wp_error( $valid ) ) { return $valid; }
        if ( array_key_exists( self::RETAINED, $settings ) ) {
            $retained = $settings[self::RETAINED];
            if ( ! is_array( $retained ) || count( $registered ) + count( $retained ) > 500 ) { return self::error( 'Retained Elementor page settings are invalid or too large.' ); }
            $valid = self::validate_values( $retained ); if ( is_wp_error( $valid ) ) { return $valid; }
            foreach ( $retained as $key => $_ ) { if ( '__globals__' !== $key && array_key_exists( $key, $registered ) ) { return self::error( 'A retained Elementor setting conflicts with a registered control: ' . $key ); } }
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
    /** Freeze registered values and safe stored literals without depending on the source page later. */
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
            $retained = [];
            foreach ( is_array( $stored ) ? $stored : [] as $key => $value ) {
                if ( in_array( $key, self::EXCLUDED, true ) && ! in_array( $key, [ '__dynamic__', '__globals__' ], true ) ) { continue; }
                if ( '__globals__' === $key || ( '__dynamic__' === $key && [] === $value ) ) { continue; }
                if ( array_key_exists( $key, $result ) ) { continue; }
                // A registered control must keep its native semantics; never demote it to a literal.
                if ( array_key_exists( $key, $controls ) || ! self::permitted_key( $key ) || ! self::safe_value( $value ) ) { return self::error( 'Stored Elementor page setting requires review before it can be copied: ' . (string) $key ); }
                $retained[$key] = $value;
            }
            if ( is_array( $stored ) && array_key_exists( '__globals__', $stored ) ) {
                if ( ! is_array( $stored['__globals__'] ) ) { return self::error( 'Stored Elementor global style references are invalid.' ); }
                foreach ( $stored['__globals__'] as $key => $value ) {
                    if ( array_key_exists( $key, $retained ) ) { $retained['__globals__'][$key] = $value; }
                    elseif ( ! array_key_exists( $key, $result['__globals__'] ?? [] ) ) { return self::error( 'Stored Elementor global style reference requires review before it can be copied: ' . (string) $key ); }
                }
            }
            if ( $retained ) { $result[self::RETAINED] = $retained; }
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
            foreach ( $settings as $key => $_ ) { if ( in_array( $key, [ '__globals__', self::RETAINED ], true ) ) { continue; } if ( ! isset( $controls[$key] ) || ! self::is_data_control( $controls[$key], $manager ) ) { return self::error( 'A saved document presentation control is no longer registered: ' . $key ); } }
            foreach ( $settings[self::RETAINED] ?? [] as $key => $_ ) { if ( '__globals__' !== $key && array_key_exists( $key, $controls ) ) { return self::error( 'A retained Elementor setting is now a registered control; capture the reference page again: ' . $key ); } }
            return true;
        } );
    }
    /** Flatten the snapshot for native hooks, without persisting our provenance marker. */
    public static function native_settings( array $settings ) {
        $retained = $settings[self::RETAINED] ?? []; unset( $settings[self::RETAINED] );
        foreach ( $retained as $key => $value ) {
            if ( '__globals__' === $key ) { $settings[$key] = array_merge( $settings[$key] ?? [], $value ); }
            else { $settings[$key] = $value; }
        }
        return $settings;
    }
    private static function read_retained( $id, array $expected ) {
        $stored = get_post_meta( $id, '_elementor_page_settings', true );
        if ( '' === $stored ) { $stored = []; }
        if ( ! is_array( $stored ) ) { return self::error( 'Stored Elementor page settings are unreadable.' ); }
        if ( ( array_key_exists( '__globals__', $stored ) && ! is_array( $stored['__globals__'] ) ) || ( array_key_exists( '__dynamic__', $stored ) && ! is_array( $stored['__dynamic__'] ) ) ) { return self::error( 'Stored Elementor page style references are unreadable.' ); }
        $selected = [];
        foreach ( $expected as $key => $value ) {
            if ( '__globals__' === $key ) {
                $globals = $stored[$key] ?? [];
                if ( ! is_array( $globals ) ) { return self::error( 'Stored Elementor global style references are unreadable.' ); }
                $selected[$key] = array_intersect_key( $globals, $value );
            } else {
                if ( ! array_key_exists( $key, $stored ) ) { return self::error( 'The retained Elementor page setting is unavailable at ' . $key . '.' ); }
                $selected[$key] = $stored[$key];
                if ( isset( $stored['__dynamic__'][$key] ) ) { return self::error( 'The retained Elementor page setting became dynamic at ' . $key . '.' ); }
                // A newly attached global reference would override this frozen literal.
                if ( isset( $stored['__globals__'][$key] ) && ! array_key_exists( $key, $expected['__globals__'] ?? [] ) ) { return self::error( 'The retained Elementor page setting acquired a different global style at ' . $key . '.' ); }
            }
        }
        return $selected;
    }
    /** Restore frozen unregistered literals after hooks; preserve registered and unrelated settings. */
    public static function persist_retained( $id, array $settings ) {
        $supported = self::assert_supported( $id, $settings ); if ( is_wp_error( $supported ) ) { return $supported; }
        $retained = $settings[self::RETAINED] ?? []; if ( ! $retained ) { return true; }
        $stored = get_post_meta( $id, '_elementor_page_settings', true );
        if ( '' === $stored ) { $stored = []; }
        if ( ! is_array( $stored ) || ( array_key_exists( '__globals__', $stored ) && ! is_array( $stored['__globals__'] ) ) ) { return self::error( 'Elementor did not persist readable page settings.' ); }
        foreach ( $retained as $key => $value ) {
            if ( '__globals__' === $key ) { continue; }
            $stored[$key] = $value;
            if ( isset( $stored['__globals__'] ) ) { unset( $stored['__globals__'][$key] ); }
        }
        if ( isset( $retained['__globals__'] ) ) { $stored['__globals__'] = array_merge( $stored['__globals__'] ?? [], $retained['__globals__'] ); }
        update_post_meta( $id, '_elementor_page_settings', wp_slash( $stored ) );
        $actual = self::read_retained( $id, $retained ); if ( is_wp_error( $actual ) ) { return $actual; }
        return $actual === $retained ? true : self::error( 'Elementor did not persist the retained page settings exactly.' );
    }
    public static function read_selected( $id, $expected ) {
        $supported = self::assert_supported( $id, $expected ); if ( is_wp_error( $supported ) ) { return $supported; }
        return self::with_post( $id, static function () use ( $id, $expected ) {
            $parts = self::document( $id ); if ( is_wp_error( $parts ) ) { return $parts; }
            $actual = $parts[0]->get_settings(); if ( ! is_array( $actual ) ) { return self::error( 'Elementor did not return valid document settings.' ); }
            $selected = [];
            foreach ( $expected as $key => $value ) {
                if ( self::RETAINED === $key ) { $selected[$key] = self::read_retained( $id, $value ); if ( is_wp_error( $selected[$key] ) ) { return $selected[$key]; } }
                elseif ( '__globals__' === $key ) {
                    if ( ! is_array( $actual[$key] ?? [] ) ) { return self::error( 'Elementor global style references are unreadable.' ); }
                    $selected[$key] = array_intersect_key( $actual[$key] ?? [], $value );
                } else {
                    if ( ! array_key_exists( $key, $actual ) ) { return self::error( 'The saved Elementor document presentation is unavailable at ' . $key . '.' ); }
                    if ( isset( $actual['__dynamic__'][$key] ) ) { return self::error( 'The saved Elementor document presentation became dynamic at ' . $key . '.' ); }
                    if ( isset( $actual['__globals__'][$key] ) && ! array_key_exists( $key, $expected['__globals__'] ?? [] ) ) { return self::error( 'The saved Elementor document presentation acquired a different global style at ' . $key . '.' ); }
                    $selected[$key] = $actual[$key];
                }
            }
            return $selected;
        } );
    }
    public static function verify( $id, $expected ) {
        $actual = self::read_selected( $id, $expected ); if ( is_wp_error( $actual ) ) { return $actual; }
        foreach ( $expected as $key => $value ) { if ( $actual[$key] !== $value ) { return self::error( 'The saved Elementor document presentation differs at ' . $key . '.' ); } }
        return true;
    }
}
