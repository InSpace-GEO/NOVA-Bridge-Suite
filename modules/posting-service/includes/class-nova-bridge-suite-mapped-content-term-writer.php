<?php
/** Existing taxonomy snapshots and surgical writes using declared native term transports. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class Nova_Bridge_Suite_Mapped_Content_Term_Writer {
    private static function error( string $code, string $message, int $status = 409 ): WP_Error { return new WP_Error( 'nova_mapped_' . $code, $message, [ 'status' => $status, 'error_code' => $code ] ); }
    private static function hash( $value ): string { return Nova_Bridge_Suite_Recipe_Engine::digest( $value ); }
    public static function read( string $resource, int $id, ?array $context = null ) {
        $base = Nova_Bridge_Suite_Document_Mapping::read( $resource, 'term', $id ); if ( is_wp_error( $base ) ) { return $base; }
        $taxonomy = $base['target']['taxonomy']; $native = [ 'id' => $resource, 'type' => 'taxonomy', 'taxonomy' => $taxonomy ]; $record = null;
        if ( null === $context ) { $default = Nova_Bridge_Suite_Layout_Recipes::defaults()[$resource] ?? null; $context = $default ? [ 'profile_id' => $default['id'], 'revision' => $default['revision'] ] : []; }
        if ( $context ) { $record = Nova_Bridge_Suite_Layout_Recipes::delivery_record( $context['profile_id'], $context['revision'] ); if ( is_wp_error( $record ) ) { return $record; } if ( $record['resource_id'] !== $resource ) { return self::error( 'term_profile', 'The retained declaration authority belongs to a different taxonomy.' ); } }
        $capture = $record && 'core' === $record['builder'] ? Nova_Bridge_Suite_Recipe_Term_Fields::capture_saved_profile( $native, $id, $record ) : Nova_Bridge_Suite_Recipe_Term_Fields::capture( $native, $id );
        if ( is_wp_error( $capture ) ) { return $capture; }
        $read = Nova_Bridge_Suite_Term_Content::read( $taxonomy, $id, $context ); if ( is_wp_error( $read ) ) { return $read; }
        $mapping = $base; $mapping['provider']['id'] = 'term_content'; $mapping['term_profile_context'] = $context; $mapping['term_bundle'] = $capture['term_fields'];
        $mapping['structure']['term_fields'] = [ 'values' => $capture['term_fields']['values'], 'declarations' => $capture['term_fields']['declarations'], 'schema_hash' => $capture['term_fields']['schema_hash'] ];
        // A declaration has one editable representation. Native metadata aliases remain
        // captured and protected; core text and real ACF leaves keep their native paths.
        foreach ( $mapping['fields'] as $path => &$field ) { if ( 0 !== strpos( $path, '/core/' ) && 0 !== strpos( $path, '/acf/' ) ) { $field['writable'] = false; $field['protected'] = true; } } unset( $field );
        foreach ( $capture['fields'] as $field ) {
            if ( empty( $field['writable'] ) || ! empty( $field['protected'] ) || 0 !== strpos( $field['target'], '/term_fields/values/' ) ) { continue; }
            $value = Nova_Bridge_Suite_Recipe_Engine::get( $mapping['structure'], $field['target'], $found ); if ( ! $found || is_array( $value ) ) { continue; }
            $declared_type = $field['schema']['type'] ?? 'number'; if ( is_array( $declared_type ) ) { $declared_type = array_values( array_diff( $declared_type, [ 'null' ] ) )[0] ?? 'number'; }
            $type = 'html' === $field['format'] ? 'rich_text' : ( 'number' === $field['format'] ? $declared_type : ( 'boolean' === $field['format'] ? 'boolean' : ( 'url' === $field['format'] ? 'url' : 'text' ) ) );
            $mapping['fields'][$field['target']] = [ 'path' => $field['target'], 'structure_path' => $field['target'], 'label' => $field['label'], 'type' => $type, 'value_type' => $type, 'value' => $value, 'current_value' => $value, 'writable' => true, 'protected' => false, 'reason' => '', 'instructions' => '', 'native_schema' => $field['schema'] ];
        }
        $mapping['baseline'] = [ 'structure_sha256' => self::hash( [ $base['baseline']['structure_sha256'], $capture['term_fields']['schema_hash'], $context ] ), 'content_sha256' => self::hash( [ $base['baseline'], $mapping['structure'] ] ) ];
        $mapping['document_version'] = $mapping['baseline']['content_sha256']; return $mapping;
    }
    public static function prepare( array $mapping, array $patch ) {
        $fresh = self::read( $mapping['resource_id'], $mapping['target']['reference_id'], $mapping['term_profile_context'] ); if ( is_wp_error( $fresh ) ) { return $fresh; }
        if ( $fresh['baseline'] !== $mapping['baseline'] ) { return self::error( 'stale_snapshot', 'The existing category changed after its snapshot. Request fresh content.' ); }
        $after = $mapping['structure']; $payload = []; $changed = []; $targets = []; $acf = []; $aliases = [];
        foreach ( $patch as $path => $value ) {
            $field = $mapping['fields'][$path] ?? null; if ( ! is_array( $field ) || empty( $field['writable'] ) || ! empty( $field['protected'] ) || is_array( $value ) ) { return self::error( 'term_field', 'Only approved concrete category content leaves may be rewritten.', 422 ); }
            if ( self::hash( $value ) === self::hash( $field['current_value'] ) ) { continue; }
            if ( isset( $field['native_schema'] ) ) { $checked = rest_validate_value_from_schema( $value, $field['native_schema'], $path ); if ( is_wp_error( $checked ) ) { return $checked; } }
            if ( 'rich_text' === $field['type'] && ( ! is_string( $value ) || wp_kses_post( $value ) !== $value ) ) { return self::error( 'html_policy', 'Category rich text exceeds the installed editorial HTML policy.', 422 ); }
            Nova_Bridge_Suite_Recipe_Engine::put( $after, $path, $value ); $changed[] = $path; $parts = Nova_Bridge_Suite_Recipe_Engine::parts( $path );
            if ( 'core' === $parts[0] && in_array( $parts[1], [ 'name', 'description' ], true ) ) {
                if ( ! is_string( $value ) || ( 'name' === $parts[1] && '' === trim( sanitize_text_field( $value ) ) ) ) { return self::error( 'term_text', 'Supply valid category name and description text.', 422 ); }
                $input = 'name' === $parts[1] ? sanitize_text_field( $value ) : wp_kses_post( $value ); $normalized = sanitize_term_field( $parts[1], wp_slash( $input ), $mapping['target']['reference_id'], $mapping['target']['taxonomy'], 'db' );
                if ( is_wp_error( $normalized ) || ! is_string( $normalized ) ) { return self::error( 'term_sanitizer', 'The native category sanitizer cannot prepare this update.' ); }
                $payload[$parts[1]] = $value; $after['core'][$parts[1]] = wp_unslash( $normalized );
            } elseif ( 'term_fields' === $parts[0] && 'values' === ( $parts[1] ?? '' ) ) { $targets[] = $path; $aliases[] = $mapping['term_bundle']['declarations'][$parts[2]]['key']; }
            elseif ( 'acf' === $parts[0] ) { $acf[$parts[1]] = $after['acf'][$parts[1]]; }
            else { return self::error( 'term_path', 'This category path has no certified native setter.' ); }
        }
        $bundle = $mapping['term_bundle']; $blueprint = [ 'term_fields' => $bundle ]; $blueprint['term_fields']['values'] = $after['term_fields']['values'];
        $compiled = Nova_Bridge_Suite_Recipe_Term_Fields::finalize( $blueprint, [ 'term_fields' => $bundle ], $targets ); if ( is_wp_error( $compiled ) ) { return $compiled; } $payload += $compiled['writer_payload'];
        foreach ( $bundle['declarations'] as $stable => $definition ) {
            if ( ! in_array( $definition['key'], $aliases, true ) ) { continue; } $encoded = 'rest' === $definition['transport'] ? $payload[$definition['rest_key']] : $payload[$definition['transport']][$definition['rest_key']]; $decoded = Nova_Bridge_Suite_Recipe_Term_Fields::decode_writer_value( $encoded, $definition ); if ( is_wp_error( $decoded ) ) { return $decoded; }
            $root = '/term_fields/values/' . $stable;
            // Capture materializes absent optional properties for inspection. Retain
            // those placeholders while normalizing only the selected concrete leaves.
            foreach ( $targets as $target ) { if ( $target !== $root && 0 !== strpos( $target, $root . '/' ) ) { continue; } if ( $target === $root ) { $normalized = $decoded; } else { $normalized = Nova_Bridge_Suite_Recipe_Engine::get( $decoded, substr( $target, strlen( $root ) ), $found ); if ( ! $found ) { return self::error( 'term_readback', 'A selected field disappeared during native normalization.' ); } } Nova_Bridge_Suite_Recipe_Engine::put( $after, $target, $normalized ); }
        }
        if ( $acf ) { $payload['meta_all']['acf'] = $acf; }
        return [ 'mode' => 'rewrite', 'native_kind' => 'term', 'resource_id' => $mapping['resource_id'], 'target' => $mapping['target'], 'baseline' => $mapping['baseline'], 'profile_context' => $mapping['term_profile_context'], 'payload' => $payload, 'after_structure' => $after, 'alias_keys' => array_values( array_unique( $aliases ) ), 'changed_paths' => $changed ];
    }
    public static function verify( array $plan ) {
        $now = self::read( $plan['resource_id'], $plan['target']['reference_id'], $plan['profile_context'] ); if ( is_wp_error( $now ) ) { return $now; }
        $expected = $plan['after_structure']; $actual = $now['structure'];
        // These exact native aliases duplicate values verified in the full declared
        // parent object below. Every unselected sibling and routing value stays strict.
        foreach ( $plan['alias_keys'] as $key ) { unset( $expected['meta'][$key], $actual['meta'][$key] ); }
        return self::hash( $actual ) === self::hash( $expected ) ? $now : self::error( 'term_readback', 'The category differs from its surgical content plan; inspect before retrying.' );
    }
    public static function apply( array $plan ) {
        $fresh = self::read( $plan['resource_id'], $plan['target']['reference_id'], $plan['profile_context'] ); if ( is_wp_error( $fresh ) ) { return $fresh; }
        if ( $fresh['baseline'] !== $plan['baseline'] ) { return self::error( 'stale_snapshot', 'The category changed before its native update.' ); }
        if ( $plan['payload'] ) { $result = Nova_Bridge_Suite_Term_Content::apply( $plan['target']['taxonomy'], $plan['target']['reference_id'], $plan['payload'], $plan['profile_context'] ); if ( is_wp_error( $result ) ) { return $result; } }
        return self::verify( $plan );
    }
}
