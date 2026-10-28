<?php
/** Explicit, source-free presentation selections from registered WordPress metadata. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class Nova_Bridge_Suite_Theme_Presentation {
    public const FORMAT = 'nova.theme-presentation/v2';
    public const CANDIDATES_FORMAT = 'nova.theme-presentation-candidates/v1';
    private const MAX_FIELDS = 128;
    private static function error( string $message ): WP_Error { return new WP_Error( 'nova_theme_presentation', $message ); }
    private static function family(): string { return function_exists( 'get_template' ) ? (string) get_template() : ''; }
    private static function registrations( string $type ): array { return function_exists( 'get_registered_meta_keys' ) ? array_merge( (array) get_registered_meta_keys( 'post' ), (array) get_registered_meta_keys( 'post', $type ) ) : []; }

    /** An underscore alone is not sensitive: themes also register protected layout fields. */
    private static function safe_key( $key ): bool {
        return is_string( $key ) && preg_match( '/^[a-zA-Z_][a-zA-Z0-9_-]{0,190}$/D', $key )
            && ! preg_match( '/^_?(?:wp_|edit_|oembed_|elementor_|nova[_-]|seor[_-]|thumbnail_id$)|(?:password|passwd|secret|token|credential|api[_-]?key|private[_-]?key|session|nonce|oauth)|(?:^|[_-])(?:seo|seopress|rankmath|rank_math|yoast|aioseo|robots|canonical|redirect(?:s|ion|ions)?|schema|opengraph|og|twitter|cache|transient|checksum|guid|permalink)(?:$|[_-])/i', $key )
            // Genesis also declares layout metadata; exclude its editorial fields, not the provider.
            && ! preg_match( '/^_?genesis_(?:title|description|canonical_uri|noindex|nofollow|noarchive|redirect)$/i', $key )
            && ! preg_match( '/(?:^|[_-])(?:post|source|object|owner|site|user|term|revision|delivery|content|domain|client)[_-]id(?:$|[_-])/i', $key )
            && ! in_array( strtolower( $key ), [ 'id', 'title', 'content', 'excerpt', 'slug', 'url', 'author', 'post_title', 'post_content', 'post_excerpt', 'post_name', 'post_author', 'post_status', 'post_parent', 'post_date', 'menu_order', 'comment_status', 'ping_status', 'footnotes', '_acf_changed' ], true );
    }

    private static function schema( array $registration ) {
        if ( empty( $registration['single'] ) || ( empty( $registration['show_in_rest'] ) && empty( $registration['description'] ) ) ) { return null; }
        $rest = $registration['show_in_rest'] ?? [];
        $schema = is_array( $rest ) && is_array( $rest['schema'] ?? null ) ? $rest['schema'] : [];
        if ( ! isset( $schema['type'] ) && isset( $registration['type'] ) ) { $schema['type'] = $registration['type']; }
        return self::clean_schema( $schema );
    }

    /** Bounded data schemas; custom composition requires a provider-declared adapter. */
    private static function clean_schema( array $schema, int $depth = 0 ) {
        if ( $depth > 8 || isset( $schema['oneOf'] ) || isset( $schema['anyOf'] ) || isset( $schema['allOf'] ) || isset( $schema['not'] ) || isset( $schema['$ref'] ) || isset( $schema['format'] ) || ! empty( $schema['readonly'] ) ) { return null; }
        if ( ! is_string( $schema['type'] ?? null ) && ! is_array( $schema['type'] ?? null ) ) { return null; }
        $types = (array) $schema['type'];
        if ( ! $types || count( $types ) > 7 ) { return null; }
        foreach ( $types as $type ) { if ( ! is_string( $type ) || ! in_array( $type, [ 'string', 'boolean', 'integer', 'number', 'array', 'object', 'null' ], true ) ) { return null; } }
        $out = [ 'type' => $schema['type'] ];
        foreach ( [ 'enum', 'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'minLength', 'maxLength', 'pattern', 'minItems', 'maxItems', 'uniqueItems', 'required', 'minProperties', 'maxProperties' ] as $key ) { if ( array_key_exists( $key, $schema ) ) { $out[$key] = $schema[$key]; } }
        if ( in_array( 'array', $types, true ) && ( ! is_array( $schema['items'] ?? null ) || ! ( $out['items'] = self::clean_schema( $schema['items'], $depth + 1 ) ) ) ) { return null; }
        if ( in_array( 'object', $types, true ) ) {
            if ( ! is_array( $schema['properties'] ?? null ) || count( $schema['properties'] ) > self::MAX_FIELDS || ( isset( $schema['additionalProperties'] ) && false !== $schema['additionalProperties'] ) ) { return null; }
            $out['properties'] = [];
            foreach ( $schema['properties'] as $key => $child ) { if ( ! self::safe_key( $key ) || ! is_array( $child ) || ! ( $out['properties'][$key] = self::clean_schema( $child, $depth + 1 ) ) ) { return null; } }
            $out['additionalProperties'] = false;
        }
        $encoded = json_encode( $out );
        return false !== $encoded && strlen( $encoded ) <= 16384 ? $out : null;
    }

    private static function json_safe( $value, int $depth = 0 ): bool {
        if ( $depth > 8 || is_object( $value ) || is_resource( $value ) ) { return false; }
        if ( is_array( $value ) ) {
            if ( count( $value ) > self::MAX_FIELDS ) { return false; }
            foreach ( $value as $key => $child ) { if ( ( is_string( $key ) && ! self::safe_key( $key ) ) || ! self::json_safe( $child, $depth + 1 ) ) { return false; } }
        } elseif ( is_string( $value ) && ( strlen( $value ) > 16384 || preg_match( '/[\\x00-\\x08\\x0b\\x0c\\x0e-\\x1f]|(?:Bearer\\s+[A-Za-z0-9._~-]{12,}|-----BEGIN .*PRIVATE KEY|\\b(?:sk-proj-|nova_pk_)[A-Za-z0-9_-]{12,})/i', $value ) ) ) { return false; }
        return false !== json_encode( $value );
    }

    /** WordPress returns scalar metadata as strings; compare using its declared type. */
    private static function normalized( $value, array $schema ) {
        $types = (array) $schema['type'];
        if ( in_array( 'boolean', $types, true ) && in_array( $value, [ '', '0', '1', 0, 1 ], true ) ) { $value = in_array( $value, [ '1', 1 ], true ); }
        elseif ( in_array( 'integer', $types, true ) && is_string( $value ) && preg_match( '/^-?(?:0|[1-9][0-9]*)$/D', $value ) && (string) (int) $value === $value ) { $value = (int) $value; }
        elseif ( in_array( 'number', $types, true ) && is_string( $value ) && is_numeric( $value ) ) { $value = (float) $value; }
        elseif ( in_array( 'string', $types, true ) && is_scalar( $value ) ) { $value = (string) $value; }
        if ( is_array( $value ) && in_array( 'array', $types, true ) ) { foreach ( $value as &$item ) { $item = self::normalized( $item, $schema['items'] ); } unset( $item ); }
        if ( is_array( $value ) && in_array( 'object', $types, true ) ) { foreach ( $value as $key => &$item ) { if ( isset( $schema['properties'][$key] ) ) { $item = self::normalized( $item, $schema['properties'][$key] ); } } unset( $item ); }
        return $value;
    }

    private static function valid_value( $value, array $schema ): bool {
        if ( ! self::json_safe( $value ) ) { return false; }
        if ( function_exists( 'rest_validate_value_from_schema' ) ) { return ! is_wp_error( rest_validate_value_from_schema( $value, $schema, 'presentation' ) ); }
        // The stored data shape can also be checked before the REST helpers are loaded.
        $matched = false;
        foreach ( (array) $schema['type'] as $type ) { $matched = $matched || ( 'null' === $type && null === $value ) || ( 'string' === $type && is_string( $value ) ) || ( 'boolean' === $type && is_bool( $value ) ) || ( 'integer' === $type && is_int( $value ) ) || ( 'number' === $type && ( is_int( $value ) || is_float( $value ) ) ) || ( in_array( $type, [ 'array', 'object' ], true ) && is_array( $value ) ); }
        if ( ! $matched || ( isset( $schema['enum'] ) && ! in_array( $value, $schema['enum'], true ) ) ) { return false; }
        if ( is_array( $value ) ) {
            if ( isset( $schema['items'] ) ) { if ( $value && array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) { return false; } foreach ( $value as $child ) { if ( ! self::valid_value( $child, $schema['items'] ) ) { return false; } } }
            else { if ( array_diff( array_keys( $value ), array_keys( $schema['properties'] ) ) || array_diff( $schema['required'] ?? [], array_keys( $value ) ) ) { return false; } foreach ( $value as $key => $child ) { if ( ! self::valid_value( $child, $schema['properties'][$key] ) ) { return false; } } }
        }
        return true;
    }

    private static function entry( int $id, string $key, array $schema ) {
        $entry = [ 'schema' => $schema, 'present' => metadata_exists( 'post', $id, $key ) ];
        if ( $entry['present'] ) { $entry['value'] = self::normalized( get_post_meta( $id, $key, true ), $schema ); if ( ! self::valid_value( $entry['value'], $schema ) ) { return null; } }
        return $entry;
    }


    /** Read-only discovery. Registration declares a field, not that it should be copied. */
    public static function candidates( int $id ) {
        $type = $id > 0 && function_exists( 'get_post_type' ) ? (string) get_post_type( $id ) : '';
        if ( ! $type || ! self::family() || ! current_user_can( 'edit_post', $id ) ) { return self::error( 'An editable example page and active theme are required to inspect its presentation settings.' ); }
        $fields = [];
        foreach ( self::registrations( $type ) as $key => $registration ) {
            if ( count( $fields ) >= self::MAX_FIELDS ) { break; }
            if ( ! self::safe_key( $key ) || ! is_array( $registration ) || ! current_user_can( 'edit_post_meta', $id, $key ) || ! ( $schema = self::schema( $registration ) ) || ! ( $entry = self::entry( $id, $key, $schema ) ) ) { continue; }
            $label = $registration['label'] ?? $registration['description'] ?? '';
            $label = is_string( $label ) && '' !== trim( $label ) ? trim( strip_tags( $label ) ) : ucwords( str_replace( [ '_', '-' ], ' ', trim( $key, '_' ) ) );
            $fields[$key] = [ 'label' => substr( $label, 0, 200 ) ] + $entry;
        }
        return [ 'format' => self::CANDIDATES_FORMAT, 'theme' => self::family(), 'post_type' => $type, 'fields' => $fields ];
    }

    /** Caller must verify the inventory's capture seal before accepting a UI selection. */
    public static function select( array $inventory, array $selected_keys ) {
        if ( ( $inventory['format'] ?? '' ) !== self::CANDIDATES_FORMAT || ! is_array( $inventory['fields'] ?? null ) || count( $selected_keys ) > self::MAX_FIELDS ) { return self::error( 'Read the example presentation settings again before selecting them.' ); }
        $meta = [];
        foreach ( $selected_keys as $key ) {
            if ( ! self::safe_key( $key ) || ! isset( $inventory['fields'][$key] ) || isset( $meta[$key] ) ) { return self::error( 'Select each registered setting only once from the captured example.' ); }
            $meta[$key] = array_intersect_key( $inventory['fields'][$key], array_flip( [ 'schema', 'present', 'value' ] ) );
        }
        if ( ! $meta ) { return null; }
        ksort( $meta );
        $snapshot = [ 'format' => self::FORMAT, 'theme' => $inventory['theme'] ?? '', 'post_type' => $inventory['post_type'] ?? '', 'meta' => $meta ];
        $valid = self::validate( $snapshot ); return is_wp_error( $valid ) ? $valid : $snapshot;
    }

    public static function capture( int $id, array $selected_keys = [] ) {
        if ( ! $selected_keys ) { return null; }
        $inventory = self::candidates( $id ); return is_wp_error( $inventory ) ? $inventory : self::select( $inventory, $selected_keys );
    }

    /** Pure validation: no source, active theme or metadata registry reads. */
    public static function validate( array $snapshot ) {
        if ( count( $snapshot ) !== 4 || ( $snapshot['format'] ?? '' ) !== self::FORMAT || ! is_string( $snapshot['theme'] ?? null ) || ! preg_match( '/^[a-zA-Z0-9_.\\/-]{1,191}$/D', $snapshot['theme'] ) || ! is_string( $snapshot['post_type'] ?? null ) || ! preg_match( '/^[a-z0-9_-]{1,32}$/D', $snapshot['post_type'] ) || ! is_array( $snapshot['meta'] ?? null ) || ! $snapshot['meta'] || count( $snapshot['meta'] ) > self::MAX_FIELDS ) { return self::error( 'The stored presentation selection is invalid.' ); }
        $encoded = json_encode( $snapshot ); if ( false === $encoded || strlen( $encoded ) > 65536 ) { return self::error( 'The stored presentation selection exceeds its supported size.' ); }
        foreach ( $snapshot['meta'] as $key => $entry ) {
            if ( ! self::safe_key( $key ) || ! is_array( $entry ) || ! is_bool( $entry['present'] ?? null ) || ! is_array( $entry['schema'] ?? null ) || self::clean_schema( $entry['schema'] ) !== $entry['schema'] ) { return self::error( 'The selected setting ' . $key . ' does not have a supported declared schema.' ); }
            if ( count( $entry ) !== ( $entry['present'] ? 3 : 2 ) || ( $entry['present'] && ( ! array_key_exists( 'value', $entry ) || ! self::valid_value( $entry['value'], $entry['schema'] ) ) ) ) { return self::error( 'The selected setting ' . $key . ' has an invalid captured value.' ); }
        }
        return true;
    }

    public static function assert_current( array $snapshot ) {
        $valid = self::validate( $snapshot ); if ( is_wp_error( $valid ) ) { return $valid; }
        if ( self::family() !== $snapshot['theme'] ) { return self::error( 'The active theme changed. Read and save the layout design again.' ); }
        $registrations = self::registrations( $snapshot['post_type'] );
        foreach ( $snapshot['meta'] as $key => $entry ) { if ( ! isset( $registrations[$key] ) || self::schema( $registrations[$key] ) !== $entry['schema'] ) { return self::error( 'The registered setting ' . $key . ' changed. Read and save the layout design again.' ); } }
        return true;
    }

    public static function native_state( int $id, array $snapshot ) {
        $valid = self::assert_current( $snapshot ); if ( is_wp_error( $valid ) ) { return $valid; }
        if ( $id <= 0 || get_post_type( $id ) !== $snapshot['post_type'] || ! current_user_can( 'edit_post', $id ) ) { return self::error( 'The selected presentation belongs to another page type or cannot be inspected.' ); }
        $actual = $snapshot; $actual['meta'] = [];
        foreach ( $snapshot['meta'] as $key => $entry ) {
            if ( ! current_user_can( 'edit_post_meta', $id, $key ) ) { return self::error( 'The publishing actor cannot access the selected setting ' . $key . '.' ); }
            $stored = self::entry( $id, $key, $entry['schema'] ); if ( ! $stored ) { return self::error( 'The current setting ' . $key . ' is not valid for its schema.' ); } $actual['meta'][$key] = $stored;
        }
        return $actual;
    }

    public static function verify( int $id, array $snapshot ) {
        $actual = self::native_state( $id, $snapshot ); if ( is_wp_error( $actual ) ) { return $actual; }
        foreach ( $snapshot['meta'] as $key => $entry ) { $stored = $actual['meta'][$key]; if ( $entry['present'] !== $stored['present'] || ( $entry['present'] && $entry['value'] !== $stored['value'] ) ) { return self::error( 'The saved presentation differs at ' . $key . '.' ); } } return true;
    }

    /** Apply only selected fixed fields, after the builder's native save hooks. */
    public static function apply( int $id, array $snapshot ) {
        $actual = self::native_state( $id, $snapshot ); if ( is_wp_error( $actual ) ) { return $actual; }
        foreach ( $snapshot['meta'] as $key => $entry ) {
            if ( $entry['present'] && function_exists( 'sanitize_meta' ) && self::normalized( sanitize_meta( $key, $entry['value'], 'post', $snapshot['post_type'] ), $entry['schema'] ) !== $entry['value'] ) { return self::error( 'The provider no longer accepts the captured setting ' . $key . '.' ); }
        }
        foreach ( $snapshot['meta'] as $key => $entry ) { $stored = $actual['meta'][$key]; if ( $entry['present'] ) { if ( ! $stored['present'] || $entry['value'] !== $stored['value'] ) { update_post_meta( $id, $key, wp_slash( $entry['value'] ) ); } } elseif ( $stored['present'] ) { delete_post_meta( $id, $key ); } }
        return self::verify( $id, $snapshot );
    }
}
