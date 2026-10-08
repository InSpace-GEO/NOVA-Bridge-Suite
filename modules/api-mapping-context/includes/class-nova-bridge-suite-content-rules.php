<?php
/** Site-local, immutable content rendering profiles. No remote mapping contract is changed. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-nova-bridge-suite-content-rule-renderer.php';

final class Nova_Bridge_Suite_Content_Rules {
    private const OPTION = 'nova_bridge_content_rules_v1';
    private const MAX_PROFILES = 100;
    private const MAX_REVISIONS = 100;
    private const MAX_STORE_BYTES = 2097152;

    public static function error( string $code, string $message, int $status = 400 ): WP_Error {
        return new WP_Error( 'nova_content_rules_' . $code, $message, [ 'status' => $status ] );
    }

    public static function uuid( string $value ): bool {
        return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $value );
    }

    public static function preset(): array {
        return [ 'schema_version' => 1, 'id' => wp_generate_uuid4(), 'revision' => 0, 'label' => 'Elementor article', 'builder' => 'elementor', 'post_type' => 'page',
            'rules' => [
                'heading' => [ 'element' => 'heading', 'settings' => [] ],
                'paragraph' => [ 'element' => 'text-editor', 'settings' => [] ],
                'rich_text' => [ 'element' => 'text-editor', 'settings' => [] ],
                'list' => [ 'element' => 'text-editor', 'settings' => [] ],
                'image' => [ 'element' => 'image', 'settings' => [] ],
                'faq' => [ 'element' => 'accordion', 'settings' => [] ],
                'group' => [ 'element' => 'container', 'settings' => [] ],
            ], 'layout' => [ 'max_width' => 1140, 'gap' => 24 ], 'hide_empty' => true ];
    }

    public static function validate( array $profile ) {
        $keys = [ 'schema_version', 'id', 'revision', 'label', 'builder', 'post_type', 'rules', 'layout', 'hide_empty' ];
        if ( array_diff( array_keys( $profile ), $keys ) || ( $profile['schema_version'] ?? null ) !== 1 || ! is_string( $profile['id'] ?? null ) || ! self::uuid( $profile['id'] ) || ! is_int( $profile['revision'] ?? null ) || $profile['revision'] < 0 || $profile['revision'] > 1000000 ) {
            return self::error( 'profile', 'Use a version 1 profile with a UUID and integer revision.' );
        }
        if ( ! is_string( $profile['label'] ?? null ) || '' === trim( $profile['label'] ) || strlen( $profile['label'] ) > 160 || 1 !== preg_match( '//u', $profile['label'] ) || preg_match( '/[\x00-\x1F<>]/', $profile['label'] ) ) { return self::error( 'label', 'Give the profile a plain UTF-8 label of at most 160 bytes.' ); }
        if ( ( $profile['builder'] ?? '' ) !== 'elementor' || ! is_string( $profile['post_type'] ?? null ) || ! preg_match( '/^[a-z0-9_-]{1,20}$/D', $profile['post_type'] ) || ( function_exists( 'post_type_exists' ) && ! post_type_exists( $profile['post_type'] ) ) ) { return self::error( 'builder', 'Choose Elementor and a registered WordPress post type.' ); }
        if ( ! is_bool( $profile['hide_empty'] ?? null ) ) { return self::error( 'hide_empty', 'The empty-block preference must be a boolean.' ); }
        $elements = [ 'heading' => [ 'heading', 'text-editor' ], 'paragraph' => [ 'text-editor' ], 'rich_text' => [ 'text-editor' ], 'list' => [ 'text-editor' ], 'image' => [ 'image' ], 'faq' => [ 'accordion', 'container' ], 'group' => [ 'container' ] ];
        if ( ! is_array( $profile['rules'] ?? null ) || array_diff( array_keys( $profile['rules'] ), array_keys( $elements ) ) || array_diff( array_keys( $elements ), array_keys( $profile['rules'] ) ) ) { return self::error( 'rules', 'Supply exactly the heading, paragraph, rich_text, list, image, faq and group rules.' ); }
        foreach ( $elements as $type => $allowed ) {
            $rule = $profile['rules'][ $type ];
            if ( ! is_array( $rule ) || array_diff( array_keys( $rule ), [ 'element', 'settings' ] ) || ! in_array( $rule['element'] ?? '', $allowed, true ) || ! is_array( $rule['settings'] ?? null ) ) { return self::error( 'rule', 'The ' . $type . ' rule has an unsupported native element or settings.' ); }
            foreach ( $rule['settings'] as $key => $value ) {
                $permitted = in_array( $type, [ 'heading', 'paragraph', 'rich_text', 'list' ], true ) ? [ 'typography_font_size', 'line_height', 'color', 'align' ] : ( 'image' === $type ? [ 'align' ] : [] );
                if ( 'heading' === $type ) { $permitted[] = 'html_tag'; }
                if ( ! in_array( $key, $permitted, true ) ) { return self::error( 'settings', 'The ' . $type . ' rule does not expose that native style control.' ); }
                $valid = false;
                if ( 'typography_font_size' === $key ) { $valid = is_numeric( $value ) && ! is_string( $value ) && is_finite( (float) $value ) && $value >= 8 && $value <= 96; }
                elseif ( 'line_height' === $key ) { $valid = is_numeric( $value ) && ! is_string( $value ) && is_finite( (float) $value ) && $value >= 0.5 && $value <= 4; }
                elseif ( 'color' === $key ) { $valid = is_string( $value ) && 1 === preg_match( '/^#[0-9a-f]{3}(?:[0-9a-f]{3})?$/iD', $value ); }
                elseif ( 'align' === $key ) { $valid = in_array( $value, [ 'left', 'center', 'right' ], true ); }
                elseif ( 'html_tag' === $key && 'heading' === $type ) { $valid = in_array( $value, [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ], true ); }
                if ( ! $valid ) { return self::error( 'settings', 'The ' . $type . ' setting ' . $key . ' is unsupported or outside its allowed range.' ); }
            }
        }
        if ( ! is_array( $profile['layout'] ?? null ) || array_diff( array_keys( $profile['layout'] ), [ 'max_width', 'gap' ] ) || ! is_int( $profile['layout']['max_width'] ?? null ) || $profile['layout']['max_width'] < 320 || $profile['layout']['max_width'] > 2400 || ! is_int( $profile['layout']['gap'] ?? null ) || $profile['layout']['gap'] < 0 || $profile['layout']['gap'] > 120 ) { return self::error( 'layout', 'Set a width of 320–2400 pixels and a gap of 0–120 pixels.' ); }
        if ( strlen( self::canonical_json( $profile ) ) > 16384 ) { return self::error( 'size', 'The local rendering profile is too large.' ); }
        return $profile;
    }

    private static function canonical_json( array $value ): string {
        $sort = static function ( $item ) use ( &$sort ) {
            if ( is_array( $item ) ) {
                if ( $item && array_keys( $item ) !== range( 0, count( $item ) - 1 ) ) { ksort( $item, SORT_STRING ); }
                foreach ( $item as $key => $child ) { $item[ $key ] = $sort( $child ); }
            }
            return $item;
        };
        $json = wp_json_encode( $sort( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( ! is_string( $json ) ) { throw new InvalidArgumentException( 'The rendering profile cannot be encoded.' ); }
        return $json;
    }

    public static function digest( array $profile ): string { return hash( 'sha256', self::canonical_json( $profile ) ); }

    private static function state() {
        $value = get_option( self::OPTION, false );
        if ( false === $value ) { return [ 'profiles' => [], 'bindings' => [] ]; }
        if ( ! is_array( $value ) || ! is_array( $value['profiles'] ?? null ) || ! is_array( $value['bindings'] ?? null ) ) { return self::error( 'storage', 'The local rendering profile store is invalid; reconcile it before saving.', 409 ); }
        return $value;
    }

    private static function revision( array $state, string $id, int $revision ) {
        $profile = $state['profiles'][ $id ][ $revision ] ?? null;
        if ( ! is_array( $profile ) || ( $profile['id'] ?? null ) !== $id || ( $profile['revision'] ?? null ) !== $revision || $revision < 1 ) { return self::error( 'not_found', 'That immutable rendering profile revision does not exist.', 404 ); }
        return self::validate( $profile );
    }

    public static function profiles(): array {
        $state = self::state(); if ( is_wp_error( $state ) ) { return []; }
        $profiles = [];
        foreach ( $state['profiles'] as $id => $revisions ) {
            if ( ! is_array( $revisions ) || ! $revisions ) { continue; }
            $profile = self::revision( $state, (string) $id, (int) max( array_keys( $revisions ) ) );
            if ( ! is_wp_error( $profile ) ) { $profiles[] = $profile; }
        }
        usort( $profiles, static function ( $a, $b ) { return strcmp( $a['label'] . $a['id'], $b['label'] . $b['id'] ); } );
        return $profiles;
    }

    public static function get( string $id ) {
        $state = self::state(); if ( is_wp_error( $state ) ) { return $state; }
        if ( ! self::uuid( $id ) || empty( $state['profiles'][ $id ] ) || ! is_array( $state['profiles'][ $id ] ) ) { return self::error( 'not_found', 'That local rendering profile does not exist.', 404 ); }
        return self::revision( $state, $id, (int) max( array_keys( $state['profiles'][ $id ] ) ) );
    }

    /** Compare the complete stored option bytes, so concurrent edits cannot overwrite each other. */
    private static function mutate( callable $callback ) {
        global $wpdb;
        if ( ! isset( $wpdb->options ) || ! preg_match( '/^[A-Za-z0-9_]+$/D', $wpdb->options ) ) { return self::error( 'storage', 'Atomic WordPress option storage is unavailable.', 409 ); }
        for ( $attempt = 0; $attempt < 3; ++$attempt ) {
            wp_cache_delete( self::OPTION, 'options' ); wp_cache_delete( 'alloptions', 'options' );
            $stored = get_option( self::OPTION, false ); $state = self::state(); if ( is_wp_error( $state ) ) { return $state; }
            $change = $callback( $state ); if ( is_wp_error( $change ) ) { return $change; }
            $next = $change['state']; $raw = maybe_serialize( $next );
            if ( strlen( $raw ) > self::MAX_STORE_BYTES ) { return self::error( 'storage_size', 'The retained profile store has reached its size limit. No history was removed.', 409 ); }
            if ( $state === $next ) { return $change['result']; }
            if ( false === $stored ) { $updated = add_option( self::OPTION, $next, '', false ); }
            else {
                $updated = $wpdb->query( $wpdb->prepare( 'UPDATE `' . $wpdb->options . '` SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s', $raw, self::OPTION, maybe_serialize( $stored ) ) );
                if ( false === $updated ) { return self::error( 'storage', 'The profile store could not be saved.', 500 ); }
                $updated = 1 === $updated;
            }
            wp_cache_delete( self::OPTION, 'options' ); wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' );
            if ( $updated ) { return $change['result']; }
        }
        return self::error( 'conflict', 'Another administrator changed the local profiles. Reload before saving.', 409 );
    }

    public static function save( array $profile, int $expected_revision ) {
        $checked = self::validate( $profile ); if ( is_wp_error( $checked ) ) { return $checked; }
        if ( $expected_revision < 0 || $profile['revision'] !== $expected_revision ) { return self::error( 'conflict', 'The submitted profile revision must match the reviewed revision.', 409 ); }
        return self::mutate( static function ( array $state ) use ( $profile, $expected_revision ) {
            $revisions = $state['profiles'][ $profile['id'] ] ?? [];
            $latest = $revisions ? (int) max( array_keys( $revisions ) ) : 0;
            if ( $latest !== $expected_revision ) { return self::error( 'conflict', 'The rendering profile changed. Reload the latest revision before saving.', 409 ); }
            if ( ( ! $revisions && count( $state['profiles'] ) >= self::MAX_PROFILES ) || count( $revisions ) >= self::MAX_REVISIONS ) { return self::error( 'history_limit', 'The retained profile history limit was reached. No immutable revision was removed.', 409 ); }
            $saved = $profile; $saved['revision'] = $latest + 1;
            $state['profiles'][ $profile['id'] ][ $saved['revision'] ] = $saved;
            return [ 'state' => $state, 'result' => $saved ];
        } );
    }

    public static function binding( string $site_id ): array {
        $state = self::state(); if ( is_wp_error( $state ) || ! self::uuid( $site_id ) ) { return []; }
        $binding = $state['bindings'][ $site_id ] ?? [];
        return is_array( $binding ) ? $binding : [];
    }

    public static function bind( string $site_id, string $profile_id, int $revision ) {
        if ( ! self::uuid( $site_id ) || ! self::uuid( $profile_id ) || $revision < 1 ) { return self::error( 'binding', 'Bind an explicit site UUID to a saved immutable profile revision.' ); }
        return self::mutate( static function ( array $state ) use ( $site_id, $profile_id, $revision ) {
            $profile = self::revision( $state, $profile_id, $revision ); if ( is_wp_error( $profile ) ) { return $profile; }
            $state['bindings'][ $site_id ] = [ 'site_id' => $site_id, 'profile_id' => $profile_id, 'profile_revision' => $revision, 'profile_digest' => self::digest( $profile ) ];
            return [ 'state' => $state, 'result' => true ];
        } );
    }

    public static function unbind( string $site_id ) {
        if ( ! self::uuid( $site_id ) ) { return self::error( 'binding', 'Choose an explicit site UUID.' ); }
        return self::mutate( static function ( array $state ) use ( $site_id ) { unset( $state['bindings'][ $site_id ] ); return [ 'state' => $state, 'result' => true ]; } );
    }

    /** A configured remote mapping always wins. Unmapped delivery handling requires site opt-in. */
    public static function configuration_for_snapshot( array $snapshot, string $site_id ) {
        if ( ! array_key_exists( 'configuration', $snapshot ) || null !== $snapshot['configuration'] || ! self::uuid( $site_id ) || ( $snapshot['site_id'] ?? '' ) !== $site_id ) { return self::error( 'no_match', 'This snapshot is outside the explicitly enabled local rendering route.', 409 ); }
        $state = self::state(); if ( is_wp_error( $state ) ) { return $state; }
        $binding = $state['bindings'][ $site_id ] ?? null;
        if ( ! is_array( $binding ) || ( $binding['site_id'] ?? '' ) !== $site_id ) { return self::error( 'no_match', 'No local rendering profile was explicitly enabled for this site.', 409 ); }
        $profile = self::revision( $state, (string) ( $binding['profile_id'] ?? '' ), (int) ( $binding['profile_revision'] ?? 0 ) );
        if ( is_wp_error( $profile ) ) { return $profile; }
        $digest = self::digest( $profile );
        if ( ! is_string( $binding['profile_digest'] ?? null ) || ! hash_equals( $digest, $binding['profile_digest'] ) ) { return self::error( 'binding_drift', 'The explicitly bound profile revision changed.', 409 ); }
        return [ 'mode' => 'local_rules', 'site_id' => $site_id, 'profile' => $profile, 'profile_id' => $profile['id'], 'profile_revision' => $profile['revision'], 'profile_digest' => $digest, 'rule_binding' => $binding ];
    }

    public static function render( array $profile, array $input, string $seed ) {
        $checked = self::validate( $profile ); if ( is_wp_error( $checked ) ) { return $checked; }
        return Nova_Bridge_Suite_Content_Rule_Renderer::render( $checked, $input, $seed );
    }
}
