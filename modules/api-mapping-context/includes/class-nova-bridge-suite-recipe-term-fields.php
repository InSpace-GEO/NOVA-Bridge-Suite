<?php
/** Declared, typed taxonomy editorial fields. Discovery and compilation never write CMS data. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Recipe_Term_Fields {
    private const MAX_DEPTH = 10;
    private const MAX_FIELDS = 1500;
    private const EXCLUDED = [ 'id', 'term_id', 'term_taxonomy_id', 'taxonomy', 'slug', 'parent', 'count', 'link', 'name', 'description', 'meta', 'meta_all', 'meta_all_flat', 'acf', 'display_type', 'thumbnail_id', 'order', 'menu_order', 'product_count', 'nova_content_context', 'nova_mapping', 'nova_document' ];
    private static function error( string $message, int $status = 422 ): WP_Error { return new WP_Error( 'nova_recipe_term_fields', $message, [ 'status' => $status ] ); }
    private static function escape( string $key ): string { return str_replace( [ '~', '/' ], [ '~0', '~1' ], $key ); }
    private static function id( string $transport, string $key ): string { return 't_' . substr( hash( 'sha256', $transport . ':' . $key ), 0, 20 ); }
    private static function protected_name( string $key ): bool {
        return '' === $key || in_array( strtolower( $key ), array_merge( self::EXCLUDED, [ '__proto__', 'prototype', 'constructor' ] ), true ) || preg_match( '/^product_count(?:$|_)/i', $key ) || preg_match( '/^_(?:wp_|edit_|yoast_|rank_math_|oembed_|elementor_|fusion(?:$|_)|thumbnail_id$)/i', $key ) || preg_match( '/(?:^|[_-])(?:password|passwd|secret|token|nonce|api[_-]?key|credential|session|cache|transient)(?:$|[_-])/i', $key );
    }
    private static function protected_property( string $key ): bool {
        // Native transport names are reserved at the root, not inside declared content groups.
        return '' === $key || in_array( strtolower( $key ), [ '__proto__', 'prototype', 'constructor' ], true ) || preg_match( '/(?:^|[_-])(?:password|passwd|secret|token|nonce|api[_-]?key|credential|session|cache|transient)(?:$|[_-])/i', $key );
    }
    private static function provider_owned( string $key, string $taxonomy, int $id ): bool {
        if ( $id && function_exists( 'get_term_meta' ) ) {
            if ( '_' === substr( $key, 0, 1 ) ) { $own = get_term_meta( $id, $key, true ); if ( is_string( $own ) && 0 === strpos( $own, 'field_' ) ) { return true; } }
            $reference = get_term_meta( $id, '_' . $key, true ); if ( is_string( $reference ) && 0 === strpos( $reference, 'field_' ) ) { return true; }
            foreach ( (array) get_term_meta( $id ) as $meta_key => $references ) { if ( ! is_string( $meta_key ) || '_' !== substr( $meta_key, 0, 1 ) || ! is_array( $references ) || ! is_string( $references[0] ?? null ) || 0 !== strpos( $references[0], 'field_' ) ) { continue; } $root = substr( $meta_key, 1 ); if ( $root && 0 === strpos( $key, $root . '_' ) ) { return true; } }
        }
        return false;
    }
    private static function callable_id( $callback ): string {
        if ( is_string( $callback ) ) { return hash( 'sha256', $callback ); }
        if ( is_array( $callback ) && count( $callback ) === 2 ) { return hash( 'sha256', ( is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0] ) . '::' . (string) $callback[1] ); }
        if ( $callback instanceof Closure ) { $reflection = new ReflectionFunction( $callback ); return hash( 'sha256', (string) $reflection->getFileName() . ':' . $reflection->getStartLine() . ':' . $reflection->getEndLine() ); }
        return is_object( $callback ) ? hash( 'sha256', get_class( $callback ) . '::__invoke' ) : '';
    }
    /** Keep only finite, declared JSON schemas. An open object is never guessed from stored data. */
    private static function schema( array $schema, int $depth = 0 ) {
        if ( $depth > self::MAX_DEPTH ) { return self::error( 'The custom-field schema is nested too deeply.' ); }
        $types = $schema['type'] ?? null; $nullable = false;
        if ( is_array( $types ) ) { $nullable = in_array( 'null', $types, true ); $types = array_values( array_diff( $types, [ 'null' ] ) ); if ( count( $types ) !== 1 ) { return self::error( 'A field with several alternative JSON types needs a dedicated field adapter.' ); } $types = $types[0]; }
        if ( ! in_array( $types, [ 'string', 'number', 'integer', 'boolean', 'object', 'array' ], true ) ) { return self::error( 'Register a string, number, boolean, closed object or typed list schema for this field.' ); }
        foreach ( [ 'oneOf', 'anyOf', 'allOf', 'not', '$ref', 'patternProperties', 'dependencies', 'dependentSchemas', 'if', 'then', 'else', 'contains', 'prefixItems', 'propertyNames' ] as $unsupported ) { if ( isset( $schema[$unsupported] ) ) { return self::error( 'This field uses schema rules that require a dedicated adapter.' ); } }
        if ( ! empty( $schema['readonly'] ) || ( isset( $schema['context'] ) && ! in_array( 'edit', (array) $schema['context'], true ) ) ) { return self::error( 'This field contains a read-only property; use its field-provider adapter.' ); }
        $out = [ 'type' => $nullable ? [ $types, 'null' ] : $types ];
        if ( isset( $schema['x-nova-storage'] ) ) { if ( 0 !== $depth || 'json' !== $schema['x-nova-storage'] || ! in_array( $types, [ 'object', 'array' ], true ) ) { return self::error( 'JSON text storage must be explicitly declared on the complete custom object or list.' ); } $out['x-nova-storage'] = 'json'; }
        foreach ( [ 'title', 'description', 'format', 'enum', 'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf', 'minLength', 'maxLength', 'pattern', 'minItems', 'maxItems', 'uniqueItems', 'default', 'required' ] as $key ) { if ( array_key_exists( $key, $schema ) ) { $out[$key] = $schema[$key]; } }
        if ( 'object' === $types ) {
            if ( ! is_array( $schema['properties'] ?? null ) || ! $schema['properties'] || false !== ( $schema['additionalProperties'] ?? true ) ) { return self::error( 'Declare the object properties and set additionalProperties to false before mapping this field.' ); }
            $out['properties'] = []; $out['additionalProperties'] = false;
            foreach ( $schema['properties'] as $key => $child ) { if ( ! is_string( $key ) || self::protected_property( $key ) || ! is_array( $child ) ) { return self::error( 'This object contains a protected or undeclared property.' ); } $checked = self::schema( $child, $depth + 1 ); if ( is_wp_error( $checked ) ) { return $checked; } $out['properties'][$key] = $checked; }
            ksort( $out['properties'], SORT_STRING );
            if ( isset( $out['required'] ) && ( ! is_array( $out['required'] ) || array_diff( $out['required'], array_keys( $out['properties'] ) ) ) ) { return self::error( 'The required property list does not match this closed object.' ); }
        } elseif ( 'array' === $types ) {
            if ( ! is_array( $schema['items'] ?? null ) || isset( $schema['items'][0] ) || isset( $schema['additionalItems'] ) ) { return self::error( 'Register one item schema for this list; tuple or untyped lists cannot be inferred safely.' ); }
            $checked = self::schema( $schema['items'], $depth + 1 ); if ( is_wp_error( $checked ) ) { return $checked; } $out['items'] = $checked;
        }
        return $out;
    }
    private static function base_type( array $schema ): string { return is_array( $schema['type'] ) ? (string) array_values( array_diff( $schema['type'], [ 'null' ] ) )[0] : (string) $schema['type']; }
    private static function blank( array $schema ) {
        if ( array_key_exists( 'default', $schema ) ) { $checked = self::typed( $schema['default'], $schema, false ); if ( ! is_wp_error( $checked ) ) { return $schema['default']; } }
        if ( isset( $schema['enum'][0] ) ) { return $schema['enum'][0]; }
        $type = self::base_type( $schema ); if ( 'object' === $type ) { $out = []; foreach ( $schema['properties'] as $key => $child ) { $out[$key] = self::blank( $child ); } return $out; }
        if ( 'array' === $type ) { return array_fill( 0, min( 500, max( 0, (int) ( $schema['minItems'] ?? 0 ) ) ), self::blank( $schema['items'] ) ); } if ( 'boolean' === $type ) { return false; }
        if ( in_array( $type, [ 'integer', 'number' ], true ) ) {
            $minimum = $schema['minimum'] ?? null; $maximum = $schema['maximum'] ?? null;
            if ( isset( $schema['exclusiveMinimum'] ) && ! is_bool( $schema['exclusiveMinimum'] ) ) { $minimum = $schema['exclusiveMinimum'] + ( 'integer' === $type ? 1 : 0.001 ); } elseif ( true === ( $schema['exclusiveMinimum'] ?? false ) && null !== $minimum ) { $minimum += 'integer' === $type ? 1 : 0.001; }
            if ( isset( $schema['exclusiveMaximum'] ) && ! is_bool( $schema['exclusiveMaximum'] ) ) { $maximum = $schema['exclusiveMaximum'] - ( 'integer' === $type ? 1 : 0.001 ); } elseif ( true === ( $schema['exclusiveMaximum'] ?? false ) && null !== $maximum ) { $maximum -= 'integer' === $type ? 1 : 0.001; }
            $value = max( 0, $minimum ?? 0 ); if ( null !== $maximum && $value > $maximum ) { $value = $maximum; }
            if ( 'integer' === $type ) { $value = ceil( $value ); if ( null !== $maximum && $value > $maximum ) { $value = floor( $maximum ); } }
            if ( isset( $schema['multipleOf'] ) && $schema['multipleOf'] > 0 ) { $value = ceil( $value / $schema['multipleOf'] ) * $schema['multipleOf']; if ( null !== $maximum && $value > $maximum ) { $value = floor( $maximum / $schema['multipleOf'] ) * $schema['multipleOf']; } }
            return 'integer' === $type ? (int) $value : $value;
        }
        $examples = [ 'email' => 'example@example.invalid', 'uri' => 'https://example.invalid/', 'url' => 'https://example.invalid/', 'uri-reference' => '/', 'date' => '2000-01-01', 'date-time' => '2000-01-01T00:00:00Z', 'time' => '00:00:00', 'ipv4' => '192.0.2.1', 'ip-address' => '192.0.2.1', 'ipv6' => '2001:db8::1', 'uuid' => '00000000-0000-4000-8000-000000000000' ];
        $value = $examples[$schema['format'] ?? ''] ?? ''; $minimum = max( 0, min( 4096, (int) ( $schema['minLength'] ?? 0 ) ) ); if ( strlen( $value ) < $minimum ) { $value .= str_repeat( 'x', $minimum - strlen( $value ) ); } return $value;
    }
    private static function materialize( $value, array $schema ) {
        $type = self::base_type( $schema ); if ( null === $value ) { return in_array( $type, [ 'array', 'object' ], true ) ? self::blank( $schema ) : $value; }
        if ( 'object' === $type && is_array( $value ) ) { foreach ( $schema['properties'] as $key => $child ) { $value[$key] = self::materialize( array_key_exists( $key, $value ) ? $value[$key] : self::blank( $child ), $child ); } }
        elseif ( 'array' === $type && is_array( $value ) ) { foreach ( $value as $key => $child ) { $value[$key] = self::materialize( $child, $schema['items'] ); } } return $value;
    }
    private static function inferred_schema( $value, int $depth = 0 ) {
        if ( $depth > self::MAX_DEPTH || is_object( $value ) || is_resource( $value ) || null === $value ) { return null; }
        if ( is_string( $value ) ) { return [ 'type' => 'string', 'format' => preg_match( '/<\/?(?:p|div|h[1-6]|ul|ol|a|strong)\b/i', $value ) ? 'html' : 'text' ]; }
        if ( is_bool( $value ) ) { return [ 'type' => 'boolean' ]; } if ( is_int( $value ) ) { return [ 'type' => 'integer' ]; } if ( is_float( $value ) && is_finite( $value ) ) { return [ 'type' => 'number' ]; }
        if ( ! is_array( $value ) || ! $value || count( $value ) > 500 ) { return null; }
        if ( Nova_Bridge_Suite_Recipe_Engine::list_array( $value ) ) { $item = self::inferred_schema( $value[0], $depth + 1 ); if ( ! $item ) { return null; } foreach ( $value as $row ) { if ( self::inferred_schema( $row, $depth + 1 ) !== $item ) { return null; } } return [ 'type' => 'array', 'items' => $item, 'maxItems' => 500 ]; }
        $properties = []; foreach ( $value as $key => $child ) { if ( ! is_string( $key ) || self::protected_property( $key ) ) { return null; } $item = self::inferred_schema( $child, $depth + 1 ); if ( ! $item ) { return null; } if ( 'string' === ( $item['type'] ?? '' ) && preg_match( '/(?:^|_)(?:url|href|link)$/i', $key ) && ! is_wp_error( self::typed( $child, [ 'type' => 'string', 'format' => 'uri-reference' ], false ) ) ) { $item['format'] = 'uri-reference'; } $properties[$key] = $item; } ksort( $properties, SORT_STRING ); return [ 'type' => 'object', 'properties' => $properties, 'additionalProperties' => false ];
    }
    private static function local_declarations( string $taxonomy, int $id, array $declared, array $authority = [] ) {
        if ( count( $declared ) > 100 ) { return self::error( 'Declare at most one hundred additional category fields.' ); } $out = [];
        foreach ( $declared as $key => $definition ) {
            if ( is_array( $definition ) && isset( $definition['key'], $definition['schema'] ) ) { $key = $definition['key']; $definition = $definition['schema']; }
            if ( ! is_string( $key ) || self::protected_name( $key ) || self::provider_owned( $key, $taxonomy, $id ) || ! is_array( $definition ) || ! $id || ( ! $authority && ! metadata_exists( 'term', $id, $key ) ) || ! self::authorize( [ 'key' => $key, 'local' => true ], $taxonomy, $id ) ) { return self::error( 'Only an existing, editable editorial custom field can be explicitly declared for this category mapping.', 403 ); }
            $schema = self::schema( $definition ); if ( is_wp_error( $schema ) ) { return $schema; } $out[$key] = $schema;
        } ksort( $out, SORT_STRING ); return $out;
    }
    private static function registry( string $taxonomy, int $id, bool $read, array $local = [] ): array {
        // Cron/CLI compilation must see the same registered REST fields as an administrator capture.
        if ( function_exists( 'rest_get_server' ) && function_exists( 'did_action' ) && 0 === did_action( 'rest_api_init' ) ) { rest_get_server(); }
        $declarations = []; $values = []; $unavailable = []; $aliases = []; $meta = [];
        if ( function_exists( 'get_registered_meta_keys' ) ) { $meta = array_merge( (array) get_registered_meta_keys( 'term', '' ), (array) get_registered_meta_keys( 'term', $taxonomy ) ); }
        $additional = (array) ( $GLOBALS['wp_rest_additional_fields'][$taxonomy] ?? [] );
        // The NOVA WooCommerce content module exposes one field through two transports; use its native top-level field.
        if ( 'product_cat' === $taxonomy && isset( $meta['content_below_products'], $additional['content_below_products'] ) && is_callable( $additional['content_below_products']['get_callback'] ?? null ) && is_callable( $additional['content_below_products']['update_callback'] ?? null ) ) { $aliases['content_below_products'] = true; }
        foreach ( $meta as $key => $args ) {
            if ( ! is_string( $key ) || self::protected_name( $key ) || self::provider_owned( $key, $taxonomy, $id ) || isset( $aliases[$key] ) ) { continue; }
            $rest = $args['show_in_rest'] ?? false; $rest_args = is_array( $rest ) ? $rest : []; $schema = (array) ( $rest_args['schema'] ?? [] );
            $schema += [ 'type' => $args['type'] ?? 'string', 'description' => $args['description'] ?? '' ];
            if ( ! empty( $schema['readonly'] ) || ( isset( $schema['context'] ) && ! in_array( 'edit', (array) $schema['context'], true ) ) ) { $unavailable[] = [ 'label' => $schema['title'] ?? $key, 'key' => $key, 'reason' => 'This registered custom field is read-only.' ]; continue; }
            $checked = self::schema( $schema ); if ( is_wp_error( $checked ) ) { $unavailable[] = [ 'label' => $schema['title'] ?? $key, 'key' => $key, 'reason' => $checked->get_error_message() ]; continue; }
            $single = ! empty( $args['single'] ); if ( ! $single ) { $checked = [ 'type' => 'array', 'items' => $checked, 'maxItems' => 500 ]; }
            $allowed = $id ? current_user_can( 'edit_term_meta', $id, $key ) : current_user_can( 'manage_options' );
            if ( ! $allowed ) { $unavailable[] = [ 'label' => $schema['title'] ?? $key, 'key' => $key, 'reason' => 'Your account cannot edit this custom field.' ]; continue; }
            $transport = $rest ? 'meta' : 'meta_all'; $stable = self::id( $transport, $key );
            $declarations[$stable] = [ 'key' => $key, 'rest_key' => (string) ( $rest_args['name'] ?? $key ), 'transport' => $transport, 'single' => $single, 'schema' => $checked, 'label' => 'content_below_products' === $key ? 'Bottom content — below products' : (string) ( $schema['title'] ?? $key ), 'description' => (string) ( $args['description'] ?? $schema['description'] ?? '' ), 'format' => 'content_below_products' === $key ? 'html' : '', 'auth_id' => self::callable_id( $args['auth_callback'] ?? null ), 'sanitize_id' => self::callable_id( $args['sanitize_callback'] ?? null ) ];
            if ( $read ) { $exists = $id && metadata_exists( 'term', $id, $key ); $raw = $exists ? get_term_meta( $id, $key, $single ) : self::blank( $checked ); $value = self::typed( $raw, $checked, true ); if ( is_wp_error( $value ) ) { $unavailable[] = [ 'label' => $declarations[$stable]['label'], 'key' => $key, 'reason' => 'The saved value does not match its registered schema: ' . $value->get_error_message() ]; unset( $declarations[$stable] ); continue; } $values[$stable] = $value; }
        }
        foreach ( $additional as $key => $args ) {
            if ( ! is_string( $key ) || self::protected_name( $key ) || self::provider_owned( $key, $taxonomy, $id ) ) { continue; }
            $schema = $args['schema'] ?? null;
            if ( ! is_array( $schema ) || ! is_callable( $args['get_callback'] ?? null ) || ! is_callable( $args['update_callback'] ?? null ) || ! empty( $schema['readonly'] ) || ( isset( $schema['context'] ) && ! in_array( 'edit', (array) $schema['context'], true ) ) ) { $unavailable[] = [ 'label' => is_array( $schema ) ? ( $schema['title'] ?? $key ) : $key, 'key' => $key, 'reason' => 'The field needs an editable REST schema with both a getter and an update callback.' ]; continue; }
            $checked = self::schema( $schema ); if ( is_wp_error( $checked ) ) { $unavailable[] = [ 'label' => $schema['title'] ?? $key, 'key' => $key, 'reason' => $checked->get_error_message() ]; continue; }
            $stable = self::id( 'rest', $key ); $declarations[$stable] = [ 'key' => $key, 'rest_key' => $key, 'transport' => 'rest', 'single' => true, 'schema' => $checked, 'label' => 'content_below_products' === $key ? 'Bottom content — below products' : (string) ( $schema['title'] ?? $key ), 'description' => (string) ( $schema['description'] ?? '' ), 'format' => 'content_below_products' === $key ? 'html' : '', 'get_id' => self::callable_id( $args['get_callback'] ), 'update_id' => self::callable_id( $args['update_callback'] ), 'sanitize_id' => self::callable_id( $args['sanitize_callback'] ?? null ) ];
            if ( $read ) {
                $raw = self::blank( $checked );
                if ( $id ) {
                    $term = get_term( $id, $taxonomy ); $object = [ 'id' => $id, 'term_id' => $id, 'taxonomy' => $taxonomy ];
                    foreach ( [ 'name', 'slug', 'description', 'parent', 'count' ] as $property ) { if ( is_object( $term ) && isset( $term->$property ) ) { $object[$property] = $term->$property; } }
                    $request = class_exists( 'WP_REST_Request' ) ? new WP_REST_Request( 'GET' ) : null; if ( $request ) { $request->set_param( 'context', 'edit' ); }
                    try { $raw = call_user_func( $args['get_callback'], $object, $key, $request, $taxonomy ); } catch ( Throwable $error ) { $raw = self::error( 'Its native REST getter could not read the example.' ); }
                }
                $value = is_wp_error( $raw ) ? $raw : self::typed( $raw, $checked, false );
                if ( is_wp_error( $value ) ) { $unavailable[] = [ 'label' => $declarations[$stable]['label'], 'key' => $key, 'reason' => 'The native field is unavailable: ' . $value->get_error_message() ]; unset( $declarations[$stable] ); continue; } $values[$stable] = $value;
            }
        }
        foreach ( $local as $key => $schema ) {
            if ( isset( $meta[$key] ) || isset( $additional[$key] ) ) { continue; } $stable = self::id( 'meta_all', $key );
            $declarations[$stable] = [ 'key' => $key, 'rest_key' => $key, 'transport' => 'meta_all', 'single' => true, 'schema' => $schema, 'label' => (string) ( $schema['title'] ?? $key ), 'description' => (string) ( $schema['description'] ?? '' ), 'format' => '', 'local' => true ]; if ( 'json' === ( $schema['x-nova-storage'] ?? '' ) ) { $declarations[$stable]['storage_codec'] = 'json'; }
            if ( $read ) { $exists = metadata_exists( 'term', $id, $key ); $raw = $exists ? get_term_meta( $id, $key, true ) : self::blank( $schema ); $value = ! empty( $declarations[$stable]['storage_codec'] ) && $exists ? self::decode_writer_value( $raw, $declarations[$stable] ) : self::typed( $raw, $schema, true ); if ( is_wp_error( $value ) ) { $unavailable[] = [ 'label' => $declarations[$stable]['label'], 'key' => $key, 'reason' => 'The saved custom field does not match the confirmed schema: ' . $value->get_error_message() ]; unset( $declarations[$stable] ); continue; } $values[$stable] = $value; }
        }
        if ( $read && $id && function_exists( 'get_term_meta' ) ) {
            $stored = (array) get_term_meta( $id );
            foreach ( $stored as $reference_key => $references ) {
                if ( ! is_string( $reference_key ) || '_' !== substr( $reference_key, 0, 1 ) || ! is_array( $references ) || ! is_string( $references[0] ?? null ) || 0 !== strpos( $references[0], 'field_' ) ) { continue; }
                $key = substr( $reference_key, 1 ); if ( self::protected_name( $key ) || ! array_key_exists( $key, $stored ) ) { continue; }
                // Expanded repeater metadata has its own references; show only the public root.
                $nested = false; foreach ( array_keys( $stored ) as $candidate ) { if ( is_string( $candidate ) && '_' === substr( $candidate, 0, 1 ) && $candidate !== $reference_key ) { $root = substr( $candidate, 1 ); $root_ref = $stored[$candidate][0] ?? null; if ( $root && is_string( $root_ref ) && 0 === strpos( $root_ref, 'field_' ) && 0 === strpos( $key, $root . '_' ) ) { $nested = true; break; } } } if ( $nested ) { continue; }
                $unavailable[] = [ 'label' => $key, 'key' => $key, 'provider' => 'acf', 'reason' => function_exists( 'acf_get_field_groups' ) && function_exists( 'acf_get_fields' ) ? 'Managed by ACF/SCF; map it using the installed field-provider schema.' : 'Managed by ACF/SCF, whose field-provider schema is currently unavailable. Enable the provider to map this field.' ];
            }
            foreach ( $stored as $key => $unused ) { if ( ! is_string( $key ) || self::protected_name( $key ) || self::provider_owned( $key, $taxonomy, $id ) || isset( $meta[$key] ) || isset( $additional[$key] ) || isset( $local[$key] ) ) { continue; } $raw = get_term_meta( $id, $key, true ); $suggestion = self::inferred_schema( $raw ); $item = [ 'label' => $key, 'key' => $key, 'reason' => 'An existing custom field was found. Confirm its content type and structure before mapping it; its plugin or theme has not declared a schema.', 'declaration_required' => true, 'value_type' => is_array( $raw ) ? ( Nova_Bridge_Suite_Recipe_Engine::list_array( $raw ) ? 'array' : 'object' ) : ( is_int( $raw ) ? 'integer' : ( is_float( $raw ) ? 'number' : ( is_bool( $raw ) ? 'boolean' : 'string' ) ) ) ]; if ( is_string( $raw ) && strlen( $raw ) <= 2097152 && preg_match( '/^\s*[\[{]/', $raw ) ) { $decoded = json_decode( $raw, true, 32 ); if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) { $structured = self::inferred_schema( $decoded ); if ( $structured ) { $suggestion = $structured + [ 'x-nova-storage' => 'json' ]; $item['value_type'] = $structured['type']; $item['storage_hint'] = 'json'; } } } if ( $suggestion ) { $item['suggested_schema'] = $suggestion; } $unavailable[] = $item; }
        }
        ksort( $declarations, SORT_STRING ); ksort( $values, SORT_STRING );
        return [ 'declarations' => $declarations, 'values' => $values, 'unavailable' => $unavailable ];
    }
    /** Read only declared public fields; never scan and expose arbitrary private term metadata. */
    public static function capture( array $resource, int $id = 0, array $declared_fields = [] ) {
        return self::capture_with_authority( $resource, $id, $declared_fields );
    }
    private static function capture_with_authority( array $resource, int $id, array $declared_fields, array $authority = [] ) {
        if ( 'taxonomy' !== ( $resource['type'] ?? '' ) || ! is_string( $resource['taxonomy'] ?? null ) ) { return self::error( 'Choose a taxonomy destination.' ); }
        $taxonomy = $resource['taxonomy'];
        if ( ! current_user_can( 'manage_options' ) || ( $id && ! current_user_can( 'edit_term', $id ) ) ) { return self::error( 'Administrator and category editing access are required.', 403 ); }
        if ( $id ) { $term = get_term( $id, $taxonomy ); if ( ! $term || is_wp_error( $term ) || $term->taxonomy !== $taxonomy ) { return self::error( 'The category example no longer exists.', 404 ); } }
        $local = self::local_declarations( $taxonomy, $id, $declared_fields, $authority ); if ( is_wp_error( $local ) ) { return $local; }
        $catalog = self::registry( $taxonomy, $id, true, $local );
        $values = $catalog['values']; foreach ( $values as $stable => $value ) { $values[$stable] = self::materialize( $value, $catalog['declarations'][$stable]['schema'] ); }
        $bundle = [ 'taxonomy' => $taxonomy, 'reference_id' => $id, 'values' => $values, 'original_values' => $catalog['values'], 'declarations' => $catalog['declarations'], 'local_declarations' => $local, 'unavailable' => $catalog['unavailable'], 'schema_hash' => Nova_Bridge_Suite_Recipe_Engine::digest( $catalog['declarations'] ) ];
        if ( $authority ) { $bundle['local_authority'] = $authority; }
        return [ 'term_fields' => $bundle ] + self::describe( [ 'term_fields' => $bundle ] );
    }
    public static function design( array $resource, int $id = 0, array $declared_fields = [] ) { return self::capture( $resource, $id, $declared_fields ); }
    private static function saved_authority( string $taxonomy, array $authority ) {
        $id = $authority['profile_id'] ?? ''; $revision = $authority['revision'] ?? 0;
        if ( ! is_string( $id ) || ! is_int( $revision ) || $revision < 1 || ! function_exists( 'get_option' ) || ! function_exists( 'wp_salt' ) ) { return self::error( 'A verified saved mapping is required to initialize custom category fields.', 409 ); }
        $state = get_option( 'nova_bridge_layout_recipes_v1', [] ); $record = is_array( $state ) ? ( $state['profiles'][$id][$revision] ?? null ) : null; $blueprint = $record['recipe']['blueprint'] ?? null;
        if ( ! is_array( $state ) || ( $state['format'] ?? '' ) !== 'nova-layout-recipes-store/v1' || ! is_array( $record ) || ( $record['id'] ?? '' ) !== $id || ( $record['revision'] ?? 0 ) !== $revision || ( $record['resource_id'] ?? '' ) !== 'taxonomy:' . $taxonomy || ( $record['builder'] ?? '' ) !== 'core' || ! is_array( $blueprint ) || ( $blueprint['taxonomy'] ?? '' ) !== $taxonomy || isset( $blueprint['term_fields']['local_authority'] ) || ! is_string( $record['seal'] ?? null ) || ! is_string( $authority['seal'] ?? null ) || ! hash_equals( $record['seal'], $authority['seal'] ) || ! hash_equals( hash_hmac( 'sha256', Nova_Bridge_Suite_Recipe_Engine::json( [ 'core', $blueprint ] ), wp_salt( 'auth' ) ), $record['seal'] ) ) { return self::error( 'The saved custom category field authority is missing, changed or belongs to another destination.', 409 ); }
        $checked = self::validate_blueprint( $blueprint ); if ( is_wp_error( $checked ) ) { return $checked; } return $record;
    }
    /** Internal transport entry point: exact stored profile authority, never an HTTP allow-missing option. */
    public static function capture_saved_profile( array $resource, int $id, array $record ) {
        $local = $record['recipe']['blueprint']['term_fields']['local_declarations'] ?? []; if ( ! $local ) { return self::capture( $resource, $id ); }
        $authority = [ 'profile_id' => $record['id'] ?? '', 'revision' => $record['revision'] ?? 0, 'seal' => $record['seal'] ?? '' ]; $stored = self::saved_authority( (string) ( $resource['taxonomy'] ?? '' ), $authority ); if ( is_wp_error( $stored ) ) { return $stored; }
        if ( Nova_Bridge_Suite_Recipe_Engine::digest( $record ) !== Nova_Bridge_Suite_Recipe_Engine::digest( $stored ) ) { return self::error( 'Use the exact immutable saved mapping record.', 409 ); }
        return self::capture_with_authority( $resource, $id, $local, $authority );
    }
    /** A local field is authorized only inside the administrator-reviewed, signed mapping/profile flow. */
    public static function authorize( array $declaration, string $taxonomy, int $id ): bool {
        $key = $declaration['key'] ?? ''; if ( ! is_string( $key ) || self::protected_name( $key ) || self::provider_owned( $key, $taxonomy, $id ) || ! current_user_can( 'manage_options' ) || ( $id && ! current_user_can( 'edit_term', $id ) ) ) { return false; }
        $registered = function_exists( 'get_registered_meta_keys' ) ? array_merge( (array) get_registered_meta_keys( 'term', '' ), (array) get_registered_meta_keys( 'term', $taxonomy ) ) : [];
        if ( ! empty( $declaration['local'] ) ) { return ! isset( $registered[$key] ) && ! isset( $GLOBALS['wp_rest_additional_fields'][$taxonomy][$key] ); }
        if ( 'rest' === ( $declaration['transport'] ?? '' ) ) { return is_callable( $GLOBALS['wp_rest_additional_fields'][$taxonomy][$key]['update_callback'] ?? null ); }
        return isset( $registered[$key] ) && ( ! $id || current_user_can( 'edit_term_meta', $id, $key ) );
    }
    private static function format( array $schema, string $override = '' ): string {
        if ( $override ) { return $override; } $type = self::base_type( $schema ); if ( 'boolean' === $type ) { return 'boolean'; } if ( in_array( $type, [ 'integer', 'number' ], true ) ) { return 'number'; }
        if ( in_array( $schema['format'] ?? '', [ 'uri', 'uri-reference', 'url' ], true ) ) { return 'url'; } if ( in_array( $schema['format'] ?? '', [ 'html', 'rich-text' ], true ) ) { return 'html'; } return 'text';
    }
    private static function allowed_formats( array $schema, string $override = '' ): array {
        if ( $override ) { return [ $override ]; }
        if ( 'string' === self::base_type( $schema ) && ! array_intersect_key( $schema, array_flip( [ 'enum', 'pattern', 'minLength', 'maxLength' ] ) ) && in_array( $schema['format'] ?? '', [ '', 'text' ], true ) ) { return [ 'text', 'html', 'url' ]; }
        return [ self::format( $schema ) ];
    }
    private static function walk( array $schema, $value, string $path, string $label, string $override, array &$fields, array &$containers, int $depth = 0, bool $required = false ): void {
        if ( $depth > self::MAX_DEPTH || count( $fields ) > self::MAX_FIELDS ) { return; } $type = self::base_type( $schema );
        if ( 'object' === $type ) { foreach ( $schema['properties'] as $key => $child ) { $present = is_array( $value ) && array_key_exists( $key, $value ); if ( ! $present ) { continue; } self::walk( $child, $value[$key], $path . '/' . self::escape( $key ), $label . ' › ' . ( $child['title'] ?? $key ), '', $fields, $containers, $depth + 1, in_array( $key, $schema['required'] ?? [], true ) ); } return; }
        if ( 'array' === $type ) {
            $items = []; foreach ( (array) $value as $index => $row ) { $items[] = [ 'index' => $index, 'label' => 'Item ' . ( $index + 1 ) ]; self::walk( $schema['items'], $row, $path . '/' . $index, $label . ' › Item ' . ( $index + 1 ), '', $fields, $containers, $depth + 1 ); }
            $prototype = [ self::blank( $schema['items'] ) ]; $schema_fields = []; $schema_containers = []; self::walk( $schema['items'], $prototype[0], '/0', $label . ' › Example item', '', $schema_fields, $schema_containers, $depth + 1 );
            $containers[] = [ 'target' => $path, 'label' => $label, 'items' => $items, 'required' => $required || ! empty( $schema['minItems'] ), 'minItems' => (int) ( $schema['minItems'] ?? 0 ), 'maxItems' => min( 500, (int) ( $schema['maxItems'] ?? 500 ) ), 'min_items' => (int) ( $schema['minItems'] ?? 0 ), 'max_items' => min( 500, (int) ( $schema['maxItems'] ?? 500 ) ), 'collection_limits' => [ 'required' => $required || ! empty( $schema['minItems'] ), 'min_items' => (int) ( $schema['minItems'] ?? 0 ), 'max_items' => min( 500, (int) ( $schema['maxItems'] ?? 500 ) ) ], 'schema_prototype' => $prototype, 'schema_fields' => $schema_fields, 'schema_containers' => $schema_containers, 'schema' => $schema, 'locator' => [ 'strategy' => 'none', 'status' => 'unavailable', 'reason' => 'The site theme decides where this custom list is rendered.' ] ]; return;
        }
        $format = self::format( $schema, $override ); $fields[] = [ 'target' => $path, 'label' => $label, 'format' => $format, 'allowed_formats' => self::allowed_formats( $schema, $override ), 'kind' => 'html' === $format ? 'body' : ( 'url' === $format ? 'link' : 'custom' ), 'writable' => true, 'protected' => false, 'required' => $required, 'constraints' => array_intersect_key( $schema, array_flip( [ 'type', 'format', 'enum', 'minLength', 'maxLength', 'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf', 'pattern' ] ) ), 'value' => null === $value ? self::blank( $schema ) : $value, 'sample' => null === $value ? self::blank( $schema ) : $value, 'schema' => $schema, 'locator' => [ 'strategy' => 'none', 'status' => 'unavailable', 'reason' => 'Use the field inventory; the site theme controls where this custom field is rendered.' ] ];
    }
    public static function describe( array $blueprint ): array {
        $fields = []; $containers = []; $bundle = $blueprint['term_fields'] ?? [];
        foreach ( $bundle['declarations'] ?? [] as $id => $declaration ) {
            if ( ! array_key_exists( $id, $bundle['values'] ?? [] ) ) { continue; }
            $start = count( $fields ); self::walk( $declaration['schema'], $bundle['values'][$id], '/term_fields/values/' . $id, $declaration['label'], $declaration['format'] ?? '', $fields, $containers );
            for ( $index = $start; $index < count( $fields ); ++$index ) { $fields[$index]['custom_field_key'] = $declaration['key']; $fields[$index]['transport'] = $declaration['transport']; if ( 'content_below_products' === $declaration['key'] && 'product_cat' === ( $bundle['taxonomy'] ?? '' ) ) { $fields[$index]['locator'] = [ 'strategy' => 'taxonomy', 'control_path' => 'content_below_products', 'selector' => '.wc-content-below-products', 'status' => 'candidate', 'precision' => 'element' ]; } }
        }
        foreach ( $bundle['unavailable'] ?? [] as $index => $item ) { $fields[] = [ 'target' => '/term_fields/unavailable/' . $index, 'label' => $item['label'], 'format' => 'text', 'kind' => 'custom', 'writable' => false, 'protected' => true, 'reason' => $item['reason'], 'custom_field_key' => $item['key'], 'locator' => [ 'strategy' => 'none', 'status' => 'unavailable', 'reason' => $item['reason'] ] ]; }
        return [ 'fields' => $fields, 'containers' => $containers, 'excluded_fields' => $bundle['unavailable'] ?? [], 'warnings' => empty( $bundle['unavailable'] ) ? [] : [ 'Some custom fields need a confirmed supported schema. They remain visible in the inventory with the reason.' ] ];
    }
    /** Strict schema validation, with only the scalar conversions WordPress metadata makes on storage. */
    private static function typed( $value, array $schema, bool $storage, int $depth = 0 ) {
        if ( $depth > self::MAX_DEPTH ) { return self::error( 'The value is nested too deeply.' ); }
        if ( null === $value && is_array( $schema['type'] ) && in_array( 'null', $schema['type'], true ) ) { return null; } $type = self::base_type( $schema );
        if ( is_object( $value ) && 'object' === $type ) { $value = get_object_vars( $value ); }
        if ( $storage && is_string( $value ) ) {
            if ( 'boolean' === $type && in_array( $value, [ '', '0', '1' ], true ) ) { $value = '1' === $value; }
            elseif ( 'integer' === $type && preg_match( '/^-?(?:0|[1-9][0-9]*)$/D', $value ) && (string) (int) $value === $value ) { $value = (int) $value; }
            elseif ( 'number' === $type && is_numeric( $value ) && is_finite( (float) $value ) ) { $value = (float) $value; }
        }
        if ( 'string' === $type && ( ! is_string( $value ) || strlen( $value ) > 2097152 || false !== strpos( $value, "\0" ) || 1 !== preg_match( '//u', $value ) ) ) { return self::error( 'A bounded UTF-8 string is required.' ); }
        if ( 'integer' === $type && ! is_int( $value ) ) { return self::error( 'An integer is required; numeric text is not interchangeable.' ); }
        if ( 'number' === $type && ( ! ( is_int( $value ) || is_float( $value ) ) || ! is_finite( (float) $value ) ) ) { return self::error( 'A finite number is required.' ); }
        if ( 'boolean' === $type && ! is_bool( $value ) ) { return self::error( 'An explicit boolean is required.' ); }
        if ( 'object' === $type ) {
            if ( ! is_array( $value ) || ( $value && Nova_Bridge_Suite_Recipe_Engine::list_array( $value ) ) || array_diff( array_keys( $value ), array_keys( $schema['properties'] ) ) || array_diff( $schema['required'] ?? [], array_keys( $value ) ) ) { return self::error( 'The value does not match its declared closed object properties.' ); }
            foreach ( $value as $key => $child ) { $checked = self::typed( $child, $schema['properties'][$key], $storage, $depth + 1 ); if ( is_wp_error( $checked ) ) { return $checked; } $value[$key] = $checked; }
        }
        if ( 'array' === $type ) {
            if ( ! Nova_Bridge_Suite_Recipe_Engine::list_array( $value ) || count( $value ) > 500 || count( $value ) < ( $schema['minItems'] ?? 0 ) || count( $value ) > ( $schema['maxItems'] ?? 500 ) ) { return self::error( 'The ordered list does not match its declared item count.' ); }
            foreach ( $value as $key => $child ) { $checked = self::typed( $child, $schema['items'], $storage, $depth + 1 ); if ( is_wp_error( $checked ) ) { return $checked; } $value[$key] = $checked; }
            if ( ! empty( $schema['uniqueItems'] ) ) { $hashes = array_map( [ 'Nova_Bridge_Suite_Recipe_Engine', 'digest' ], $value ); if ( count( array_unique( $hashes ) ) !== count( $hashes ) ) { return self::error( 'The list requires unique items.' ); } }
        }
        if ( isset( $schema['enum'] ) && ! in_array( $value, $schema['enum'], true ) ) { return self::error( 'The value is outside its declared choices.' ); }
        if ( in_array( $type, [ 'number', 'integer' ], true ) ) {
            if ( ( isset( $schema['minimum'] ) && $value < $schema['minimum'] ) || ( isset( $schema['maximum'] ) && $value > $schema['maximum'] ) || ( isset( $schema['exclusiveMinimum'] ) && is_numeric( $schema['exclusiveMinimum'] ) && ! is_bool( $schema['exclusiveMinimum'] ) && $value <= $schema['exclusiveMinimum'] ) || ( isset( $schema['exclusiveMaximum'] ) && is_numeric( $schema['exclusiveMaximum'] ) && ! is_bool( $schema['exclusiveMaximum'] ) && $value >= $schema['exclusiveMaximum'] ) ) { return self::error( 'The number exceeds its declared limits.' ); }
            if ( isset( $schema['multipleOf'] ) && ( $schema['multipleOf'] <= 0 || abs( $value / $schema['multipleOf'] - round( $value / $schema['multipleOf'] ) ) > 0.0000001 ) ) { return self::error( 'The number does not match its declared increment.' ); }
        }
        if ( 'string' === $type ) {
            if ( in_array( $schema['format'] ?? '', [ 'uri', 'uri-reference', 'url' ], true ) && '' !== $value ) { $parts = parse_url( $value ); if ( ! is_array( $parts ) || preg_match( '/[\x00-\x20<>"\x27\\\\]/', $value ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || ( isset( $parts['scheme'] ) && ! in_array( strtolower( $parts['scheme'] ), [ 'http', 'https', 'mailto', 'tel' ], true ) ) || ( in_array( strtolower( $parts['scheme'] ?? '' ), [ 'http', 'https' ], true ) && empty( $parts['host'] ) ) ) { return self::error( 'The custom field URL is unsafe or malformed.' ); } }
            $length = function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : preg_match_all( '/./us', $value, $unused );
            if ( $length < ( $schema['minLength'] ?? 0 ) || $length > ( $schema['maxLength'] ?? 2097152 ) ) { return self::error( 'The text exceeds its declared length limits.' ); }
            if ( isset( $schema['pattern'] ) && 1 !== @preg_match( '~' . str_replace( '~', '\\~', $schema['pattern'] ) . '~u', $value ) ) { return self::error( 'The text does not match its declared pattern.' ); }
        }
        if ( function_exists( 'rest_validate_value_from_schema' ) ) { $checked = rest_validate_value_from_schema( $value, $schema, 'Custom category field' ); if ( is_wp_error( $checked ) ) { return $checked; } }
        return $value;
    }
    public static function validate_blueprint( array $blueprint ) {
        if ( ! isset( $blueprint['term_fields'] ) ) { return $blueprint; } $bundle = $blueprint['term_fields'];
        if ( ! is_array( $bundle ) || ! is_string( $bundle['taxonomy'] ?? null ) || ! is_int( $bundle['reference_id'] ?? null ) || ! is_array( $bundle['values'] ?? null ) || ! is_array( $bundle['declarations'] ?? null ) || ! is_string( $bundle['schema_hash'] ?? null ) || ! current_user_can( 'manage_options' ) || ( $bundle['reference_id'] && ! current_user_can( 'edit_term', $bundle['reference_id'] ) ) ) { return self::error( 'The declared category fields are incomplete or cannot be edited by this account.', 403 ); }
        $authority = $bundle['local_authority'] ?? []; if ( $authority ) { $record = self::saved_authority( $bundle['taxonomy'], $authority ); if ( is_wp_error( $record ) ) { return $record; } if ( Nova_Bridge_Suite_Recipe_Engine::digest( $record['recipe']['blueprint']['term_fields']['local_declarations'] ?? [] ) !== Nova_Bridge_Suite_Recipe_Engine::digest( $bundle['local_declarations'] ?? [] ) ) { return self::error( 'The approved local field schema does not match this target capture.', 409 ); } }
        $local = self::local_declarations( $bundle['taxonomy'], $bundle['reference_id'], $bundle['local_declarations'] ?? [], $authority ); if ( is_wp_error( $local ) ) { return $local; }
        $catalog = self::registry( $bundle['taxonomy'], $bundle['reference_id'], false, $local );
        if ( $bundle['reference_id'] ) { $term = get_term( $bundle['reference_id'], $bundle['taxonomy'] ); if ( ! $term || is_wp_error( $term ) || $term->taxonomy !== $bundle['taxonomy'] ) { return self::error( 'The custom-field example category no longer exists.', 409 ); } }
        // Capture may report a getter/value failure as unavailable; compare all captured declarations, never silently accept replacements.
        foreach ( $bundle['declarations'] as $id => $declaration ) { if ( ! isset( $catalog['declarations'][$id] ) || Nova_Bridge_Suite_Recipe_Engine::digest( $declaration ) !== Nova_Bridge_Suite_Recipe_Engine::digest( $catalog['declarations'][$id] ) ) { return self::error( 'A custom category field schema, transport or permission changed. Capture a renewed mapping.', 409 ); } }
        if ( Nova_Bridge_Suite_Recipe_Engine::digest( $bundle['declarations'] ) !== $bundle['schema_hash'] || array_diff( array_keys( $bundle['values'] ), array_keys( $bundle['declarations'] ) ) || array_diff( array_keys( $bundle['declarations'] ), array_keys( $bundle['values'] ) ) ) { return self::error( 'The sealed custom-field schema or value inventory was changed.', 409 ); }
        if ( ! is_array( $bundle['original_values'] ?? null ) || array_diff( array_keys( $bundle['declarations'] ), array_keys( $bundle['original_values'] ) ) || array_diff( array_keys( $bundle['original_values'] ), array_keys( $bundle['declarations'] ) ) ) { return self::error( 'The original typed category fields are missing from the sealed blueprint.', 409 ); }
        foreach ( $bundle['original_values'] as $id => $value ) { $checked = self::typed( $value, $bundle['declarations'][$id]['schema'], false ); if ( is_wp_error( $checked ) ) { return $checked; } }
        return $blueprint;
    }
    private static function schema_at( array $blueprint, string $target ) {
        $parts = Nova_Bridge_Suite_Recipe_Engine::parts( $target ); if ( array_slice( $parts, 0, 2 ) !== [ 'term_fields', 'values' ] || count( $parts ) < 3 ) { return null; }
        $schema = $blueprint['term_fields']['declarations'][$parts[2]]['schema'] ?? null; if ( ! is_array( $schema ) ) { return null; }
        foreach ( array_slice( $parts, 3 ) as $part ) { $type = self::base_type( $schema ); if ( 'object' === $type ) { $schema = $schema['properties'][$part] ?? null; } elseif ( 'array' === $type && ctype_digit( (string) $part ) ) { $schema = $schema['items']; } else { return null; } if ( ! is_array( $schema ) ) { return null; } }
        return $schema;
    }
    public static function owns( string $target ): bool { return 0 === strpos( $target, '/term_fields/' ); }
    public static function validate_binding( array $native, array $binding ) {
        $schema = self::schema_at( $native, $binding['target'] ); $value = Nova_Bridge_Suite_Recipe_Engine::get( $native, $binding['target'], $found );
        if ( ! $found || ! $schema || in_array( self::base_type( $schema ), [ 'object', 'array' ], true ) ) { return self::error( 'Choose a declared scalar custom category field.' ); }
        $parts = Nova_Bridge_Suite_Recipe_Engine::parts( $binding['target'] ); $declaration = $native['term_fields']['declarations'][$parts[2]];
        $formats = self::allowed_formats( $schema, count( $parts ) === 3 ? ( $declaration['format'] ?? '' ) : '' ); if ( ! in_array( $binding['format'] ?? '', $formats, true ) ) { return self::error( 'Use an allowed custom-field format; field types cannot be changed by a mapping.' ); }
        return true;
    }
    public static function validate_region( array $native, array $region ) {
        $schema = self::schema_at( $native, $region['target'] ); $value = Nova_Bridge_Suite_Recipe_Engine::get( $native, $region['target'], $found ); if ( ! $found || ! $schema || 'array' !== self::base_type( $schema ) || ! Nova_Bridge_Suite_Recipe_Engine::list_array( $value ) ) { return self::error( 'Choose a declared typed custom-field list.' ); }
        if ( ( $region['max_items'] ?? 500 ) > min( 500, (int) ( $schema['maxItems'] ?? 500 ) ) ) { return self::error( 'The region exceeds its native schema item limit.' ); }
        return true;
    }
    /** Validate relative prototype bindings against the root list schema before Engine strips that context. */
    private static function prototype_scope( array $native, array $bindings, array $regions, array $schema ): bool {
        foreach ( $bindings as $binding ) { $parts = Nova_Bridge_Suite_Recipe_Engine::parts( $binding['target'] ); $node_schema = $schema; foreach ( $parts as $part ) { if ( 'array' === self::base_type( $node_schema ) && ctype_digit( (string) $part ) ) { $node_schema = $node_schema['items']; } elseif ( 'object' === self::base_type( $node_schema ) && isset( $node_schema['properties'][$part] ) ) { $node_schema = $node_schema['properties'][$part]; } else { return false; } } if ( in_array( self::base_type( $node_schema ), [ 'array', 'object' ], true ) || ! in_array( $binding['format'], self::allowed_formats( $node_schema ), true ) ) { return false; } }
        foreach ( $regions as $region ) { $parts = Nova_Bridge_Suite_Recipe_Engine::parts( $region['target'] ); $node_schema = $schema; foreach ( $parts as $part ) { if ( 'array' === self::base_type( $node_schema ) && ctype_digit( (string) $part ) ) { $node_schema = $node_schema['items']; } elseif ( 'object' === self::base_type( $node_schema ) && isset( $node_schema['properties'][$part] ) ) { $node_schema = $node_schema['properties'][$part]; } else { return false; } } if ( 'array' !== self::base_type( $node_schema ) || ( $region['max_items'] ?? 500 ) > ( $node_schema['maxItems'] ?? 500 ) || ! self::prototype_scope( $region['prototype']['native'] ?? [], $region['prototype']['bindings'] ?? [], $region['prototype']['regions'] ?? [], $node_schema ) ) { return false; } foreach ( $region['variants'] ?? [] as $prototype ) { if ( ! self::prototype_scope( $prototype['native'] ?? [], $prototype['bindings'] ?? [], $prototype['regions'] ?? [], $node_schema ) ) { return false; } } }
        return true;
    }
    public static function validate_plan_scope( array $native, array $bindings, array $regions ) {
        if ( ! isset( $native['term_fields'] ) ) { return true; }
        foreach ( $regions as $region ) { if ( ! self::owns( $region['target'] ) ) { continue; } $schema = self::schema_at( $native, $region['target'] ); if ( ! $schema || ! self::prototype_scope( $region['prototype']['native'] ?? [], $region['prototype']['bindings'] ?? [], $region['prototype']['regions'] ?? [], $schema ) ) { return self::error( 'A custom-field prototype binding does not match its declared typed list schema.' ); } foreach ( $region['variants'] ?? [] as $prototype ) { if ( ! self::prototype_scope( $prototype['native'] ?? [], $prototype['bindings'] ?? [], $prototype['regions'] ?? [], $schema ) ) { return self::error( 'A custom-field variant does not match its declared list schema.' ); } } }
        return true;
    }
    public static function bind( array $native, array $binding, $value ) { $schema = self::schema_at( $native, $binding['target'] ); if ( $schema ) { $checked = self::typed( $value, $schema, false ); if ( is_wp_error( $checked ) ) { return $checked; } } Nova_Bridge_Suite_Recipe_Engine::put( $native, $binding['target'], $value ); return $native; }
    public static function replace_region( array $native, array $region, array $children ) { Nova_Bridge_Suite_Recipe_Engine::splice( $native, $region['target'], $region['start'], $region['count'], $children ); return $native; }
    private static function assign( &$value, array $parts, $replacement ): void { if ( ! $parts ) { $value = $replacement; return; } $part = array_shift( $parts ); if ( ! is_array( $value ) ) { $value = []; } if ( ! array_key_exists( $part, $value ) ) { $value[$part] = []; } self::assign( $value[$part], $parts, $replacement ); }
    /** Return writer values only. Actual transport authorisation and writes belong to the existing bridge. */
    public static function finalize( array $blueprint, array $original = [], array $targets = [] ) {
        if ( ! isset( $blueprint['term_fields'] ) ) { return [ 'writer_payload' => [], 'warnings' => [] ]; }
        $checked = self::validate_blueprint( $blueprint ); if ( is_wp_error( $checked ) ) { return $checked; } $bundle = $blueprint['term_fields']; $payload = [];
        foreach ( $bundle['declarations'] as $id => $declaration ) {
            $path = '/term_fields/values/' . $id; $selected = false; foreach ( $targets as $target ) { if ( $target === $path || 0 === strpos( $target, $path . '/' ) ) { $selected = true; break; } }
            if ( ! $selected ) { continue; } $value = $bundle['original_values'][$id];
            // Capture makes absent optional properties selectable; only selected paths become actual CMS values.
            foreach ( $targets as $target ) { if ( $target !== $path && 0 !== strpos( $target, $path . '/' ) ) { continue; } $replacement = Nova_Bridge_Suite_Recipe_Engine::get( $blueprint, $target, $found ); if ( ! $found ) { return self::error( 'A selected custom-field path is no longer present in the native blueprint.' ); } self::assign( $value, array_slice( Nova_Bridge_Suite_Recipe_Engine::parts( $target ), 3 ), $replacement ); }
            if ( in_array( $declaration['transport'], [ 'meta', 'meta_all' ], true ) && function_exists( 'sanitize_meta' ) ) {
                if ( $declaration['single'] ) { $value = sanitize_meta( $declaration['key'], $value, 'term', $bundle['taxonomy'] ); }
                else { foreach ( $value as &$row ) { $row = sanitize_meta( $declaration['key'], $row, 'term', $bundle['taxonomy'] ); } unset( $row ); }
            } elseif ( 'rest' === $declaration['transport'] ) {
                $registration = $GLOBALS['wp_rest_additional_fields'][$bundle['taxonomy']][$declaration['key']] ?? [];
                if ( is_callable( $registration['sanitize_callback'] ?? null ) ) { $value = call_user_func( $registration['sanitize_callback'], $value, null, $declaration['key'] ); }
                elseif ( function_exists( 'rest_sanitize_value_from_schema' ) ) { $value = rest_sanitize_value_from_schema( $value, $declaration['schema'], $declaration['key'] ); }
            }
            if ( is_wp_error( $value ) ) { return $value; } $checked_value = self::typed( $value, $declaration['schema'], false ); if ( is_wp_error( $checked_value ) ) { return $checked_value; }
            if ( 'content_below_products' === $declaration['key'] ) { $checked_value = wp_kses_post( $checked_value ); }
            $writer_value = self::encode_writer_value( $checked_value, $declaration ); if ( is_wp_error( $writer_value ) ) { return $writer_value; }
            if ( 'rest' === $declaration['transport'] ) { $payload[$declaration['rest_key']] = $writer_value; } else { $payload[$declaration['transport']][$declaration['rest_key']] = $writer_value; }
        }
        return [ 'writer_payload' => $payload, 'warnings' => [] ];
    }
    public static function declarations_for_payload( array $blueprint, array $payload ) {
        $checked = self::validate_blueprint( $blueprint ); if ( is_wp_error( $checked ) ) { return $checked; } $out = [];
        foreach ( $blueprint['term_fields']['declarations'] ?? [] as $id => $declaration ) { $present = 'rest' === $declaration['transport'] ? array_key_exists( $declaration['rest_key'], $payload ) : array_key_exists( $declaration['rest_key'], $payload[$declaration['transport']] ?? [] ); if ( $present ) { $out[$id] = $declaration; } } return $out;
    }
    public static function validate_value( $value, array $schema ) { $checked = self::schema( $schema ); return is_wp_error( $checked ) ? $checked : self::typed( $value, $checked, false ); }
    private static function content_node( array $schema, array $parts ): array { foreach ( $parts as $part ) { $schema = $schema['properties'][$part] ?? []; } return $schema; }
    private static function content_put( array &$schema, array $parts, array $value, bool $required = false ): void {
        if ( ! $parts ) { $schema = $value; return; } $part = array_shift( $parts ); if ( ! isset( $schema['properties'] ) ) { $schema['type'] = 'object'; $schema['properties'] = []; }
        if ( ! isset( $schema['properties'][$part] ) ) { $schema['properties'][$part] = []; } self::content_put( $schema['properties'][$part], $parts, $value, $required );
        if ( $required ) { $schema['required'] = array_values( array_unique( array_merge( $schema['required'] ?? [], [ $part ] ) ) ); }
    }
    private static function augment_scope( array $native, array $bindings, array $regions, array $schema, array $native_schema = [] ): array {
        if ( $native_schema ) { $fields = []; $containers = []; self::walk( $native_schema, $native, '', '', '', $fields, $containers ); $description = [ 'fields' => $fields, 'containers' => $containers ]; }
        else { $description = self::describe( $native ); }
        foreach ( $bindings as $binding ) {
            foreach ( $description['fields'] as $field ) {
                if ( $field['target'] !== $binding['target'] || empty( $field['writable'] ) ) { continue; } $parts = Nova_Bridge_Suite_Recipe_Engine::parts( $binding['source'] ); $node = self::content_node( $schema, $parts ); $constraints = $field['constraints'] ?? [];
                if ( isset( $field['schema'] ) ) { $constraints['type'] = self::base_type( $field['schema'] ); }
                if ( 'html' === $binding['format'] ) { $constraints['format'] = 'html'; } elseif ( 'url' === $binding['format'] ) { $constraints['format'] = 'uri-reference'; }
                self::content_put( $schema, $parts, array_merge( $node, $constraints ), ! empty( $binding['required'] ) || ! empty( $field['required'] ) ); break;
            }
        }
        foreach ( $regions as $region ) {
            $container = null; foreach ( $description['containers'] as $candidate ) { if ( $candidate['target'] === $region['target'] ) { $container = $candidate; break; } } if ( ! $container ) { continue; }
            $parts = Nova_Bridge_Suite_Recipe_Engine::parts( $region['source'] ); $node = self::content_node( $schema, $parts ); $collection_schema = $container['schema'] ?? []; if ( ! $collection_schema ) { continue; }
            if ( 'parent' === ( $region['context'] ?? 'item' ) ) { $prototype = $region['prototype']; $schema = self::augment_scope( $prototype['native'], $prototype['bindings'] ?? [], $prototype['regions'] ?? [], $schema, $collection_schema ); continue; }
            $augment_item = static function ( array $item_schema, array $prototype ) use ( $collection_schema ): array { return self::augment_scope( $prototype['native'], $prototype['bindings'] ?? [], $prototype['regions'] ?? [], $item_schema, $collection_schema ); };
            if ( isset( $region['variants'], $node['items']['oneOf'] ) ) { foreach ( array_values( $region['variants'] ) as $index => $prototype ) { if ( isset( $node['items']['oneOf'][$index] ) ) { $node['items']['oneOf'][$index] = $augment_item( $node['items']['oneOf'][$index], $prototype ); } } }
            elseif ( isset( $node['items'] ) ) { $node['items'] = $augment_item( $node['items'], $region['prototype'] ); }
            elseif ( isset( $node['anyOf'] ) ) { foreach ( $node['anyOf'] as &$choice ) { if ( 'object' === ( $choice['type'] ?? '' ) || isset( $choice['properties'] ) ) { $choice = $augment_item( $choice, $region['prototype'] ); } } unset( $choice ); }
            if ( 'repeat' === $region['kind'] && empty( $region['projection'] ) ) { $node['minItems'] = (int) ( $container['min_items'] ?? 0 ); $node['maxItems'] = min( $region['max_items'], (int) ( $container['max_items'] ?? 500 ) ); if ( ! empty( $collection_schema['uniqueItems'] ) ) { $node['uniqueItems'] = true; } }
            self::content_put( $schema, $parts, $node, ! empty( $container['required'] ) );
        }
        return $schema;
    }
    /** Canonical content retains the site's actual types and validation limits at each mapped purpose. */
    public static function augment_content_schema( array $recipe, array $schema ) {
        $checked = self::validate_blueprint( $recipe['blueprint'] ); if ( is_wp_error( $checked ) ) { return $checked; }
        if ( ! isset( $recipe['blueprint']['term_fields'] ) ) { return $schema; }
        return self::augment_scope( $recipe['blueprint'], $recipe['bindings'] ?? [], $recipe['regions'] ?? [], $schema );
    }
    public static function decode_writer_value( $value, array $declaration ) {
        if ( 'json' !== ( $declaration['storage_codec'] ?? '' ) ) { return self::typed( $value, $declaration['schema'], false ); }
        if ( empty( $declaration['local'] ) || 'json' !== ( $declaration['schema']['x-nova-storage'] ?? '' ) || ! is_string( $value ) || strlen( $value ) > 2097152 ) { return self::error( 'Use the explicitly approved bounded JSON-text storage field.' ); }
        $decoded = json_decode( $value, false, 32 ); if ( JSON_ERROR_NONE !== json_last_error() ) { return self::error( 'The native custom field is not valid JSON text.' ); } return self::typed( $decoded, $declaration['schema'], false );
    }
    private static function json_shape( $value, array $schema ) {
        if ( null === $value ) { return null; } $type = self::base_type( $schema );
        if ( 'object' === $type ) { ksort( $value, SORT_STRING ); foreach ( $value as $key => $child ) { $value[$key] = self::json_shape( $child, $schema['properties'][$key] ); } return (object) $value; }
        if ( 'array' === $type ) { foreach ( $value as $key => $child ) { $value[$key] = self::json_shape( $child, $schema['items'] ); } } return $value;
    }
    public static function encode_writer_value( $value, array $declaration ) {
        $checked = self::typed( $value, $declaration['schema'], false ); if ( is_wp_error( $checked ) ) { return $checked; }
        if ( 'json' !== ( $declaration['storage_codec'] ?? '' ) ) { return $checked; }
        if ( empty( $declaration['local'] ) || 'json' !== ( $declaration['schema']['x-nova-storage'] ?? '' ) ) { return self::error( 'JSON text storage is only supported for an explicitly approved local field.' ); }
        $encoded = wp_json_encode( self::json_shape( $checked, $declaration['schema'] ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); return is_string( $encoded ) && strlen( $encoded ) <= 2097152 ? $encoded : self::error( 'The compiled JSON text field exceeds its bounded storage size.' );
    }
}
