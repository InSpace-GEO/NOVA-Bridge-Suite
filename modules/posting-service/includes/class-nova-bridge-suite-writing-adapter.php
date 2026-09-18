<?php
/** Converts verified local mappings to the posting service's canonical writing objects. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Writing_Adapter {
    public static function error( string $code, string $message, int $status = 400 ): WP_Error {
        return new WP_Error( 'nova_writing_' . $code, $message, [ 'status' => $status ] );
    }

    /** Object keys sort recursively; arrays retain order. No floats/native objects enter policy. */
    public static function canonical_json( $value ): string {
        $normalize = function ( $item ) use ( &$normalize ) {
            if ( is_float( $item ) || is_resource( $item ) || is_object( $item ) ) { throw new InvalidArgumentException( 'Unsupported policy value.' ); }
            if ( is_array( $item ) ) {
                if ( array_keys( $item ) !== range( 0, count( $item ) - 1 ) && [] !== $item ) { ksort( $item, SORT_STRING ); }
                foreach ( $item as $key => $child ) { $item[ $key ] = $normalize( $child ); }
            }
            return $item;
        };
        $json = wp_json_encode( $normalize( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( ! is_string( $json ) ) { throw new InvalidArgumentException( 'Policy is not valid UTF8 JSON.' ); }
        return $json;
    }

    public static function template_record( $record ): bool {
        return is_array( $record ) && Nova_Bridge_Suite_Posting_Client::id( $record['template_id'] ?? null ) && Nova_Bridge_Suite_Posting_Client::id( $record['template_version'] ?? null ) && in_array( $record['family'] ?? null, [ 'service', 'category', 'informative' ], true ) && is_array( $record['fields'] ?? null ) && is_array( $record['groups'] ?? null ) && is_array( $record['layout'] ?? null ) && is_array( $record['protected_identities'] ?? null ) && is_string( $record['etag'] ?? null );
    }

    public static function catalog_template( array $record ): array {
        $fields = []; $groups = []; $protected = [];
        foreach ( $record['fields'] as $field ) { $fields[] = [ 'source_path' => $field['field_key'], 'label' => $field['label'], 'type' => $field['field_type'] ]; }
        foreach ( $record['groups'] as $group ) {
            $members = [];
            foreach ( $group['fields'] as $field ) { $members[] = $field['field_key']; $fields[] = [ 'source_path' => $group['group_key'] . '[].' . $field['field_key'], 'label' => $field['label'], 'type' => $field['field_type'] ]; }
            $groups[] = [ 'key' => $group['group_key'], 'label' => $group['label'], 'min' => $group['minItems'], 'max' => $group['maxItems'], 'member_keys' => $members ];
        }
        foreach ( $record['protected_identities'] as $key ) { $protected[] = [ 'key' => $key, 'label' => ucwords( str_replace( '_', ' ', $key ) ) ]; }
        return [ 'id' => $record['template_id'], 'revision' => $record['template_version'], 'label' => ucfirst( $record['family'] ) . ' — ' . $record['template_id'] . '/' . $record['template_version'], 'family' => $record['family'], 'fields' => $fields, 'groups' => $groups, 'protected_slots' => $protected ];
    }

    /** Returns template PUT fields and mapping bindings. Does not claim writer capability. */
    public static function prepare( array $draft, array $source ) {
        if ( ! self::template_record( $source ) || 'nova' !== ( $draft['catalog_mode'] ?? '' ) || $source['template_id'] !== ( $draft['template']['id'] ?? null ) || $source['template_version'] !== ( $draft['template']['revision'] ?? null ) ) { return self::error( 'template_identity', 'An exact canonical NOVA template revision is required.', 409 ); }
        $guidance = $draft['guidance'] ?? '';
        if ( ! is_string( $guidance ) || strlen( $guidance ) > 8000 ) { return self::error( 'notes_size', 'Layout instructions exceed the NOVA limit of 8000 UTF8 bytes.' ); }
        $known = []; $skips = []; $notes = []; $mapped = [];
        foreach ( $source['fields'] as $field ) { $known[ $field['field_key'] ] = $field; }
        foreach ( $source['groups'] as $group ) { foreach ( $group['fields'] as $field ) { $known[ $group['group_key'] . '[].' . $field['field_key'] ] = $field; } }
        foreach ( $draft['skipped_sources'] as $skip ) {
            if ( ! isset( $known[ $skip['source_path'] ] ) ) { return self::error( 'source_changed', 'A skipped source no longer belongs to the selected canonical template.', 409 ); }
            $skips[ $skip['source_path'] ] = true;
        }
        foreach ( $draft['fields'] as $path => $field ) {
            if ( 'mapped' !== $field['mode'] ) { continue; }
            $key = $field['source_path'];
            if ( ! isset( $known[ $key ] ) || false !== strpos( $key, '[].' ) || isset( $skips[ $key ] ) || isset( $mapped[ $key ] ) ) { return self::error( 'source_changed', 'Each individual generated source must have one supported destination and cannot also be skipped.', 409 ); }
            $mapped[ $key ] = $path;
            if ( '' !== $field['instructions'] ) { $notes[ $key ] = $field['instructions']; }
        }
        $fields = []; $groups = []; $removed = [];
        foreach ( $source['fields'] as $field ) {
            $key = $field['field_key'];
            if ( isset( $skips[ $key ] ) ) { $removed[ $key ] = true; continue; }
            if ( isset( $notes[ $key ] ) ) { $field['notes'] = $notes[ $key ]; }
            $fields[] = $field;
        }
        foreach ( $source['groups'] as $group ) {
            $members = [];
            foreach ( $group['fields'] as $field ) { if ( ! isset( $skips[ $group['group_key'] . '[].' . $field['field_key'] ] ) ) { $members[] = $field; } }
            if ( ! $members ) { $removed[ $group['group_key'] ] = true; continue; }
            $group['fields'] = $members;
            $slots = $draft['repeat_slots'][ $group['group_key'] ] ?? [];
            if ( $slots ) {
                // A fixed-size schema prevents NOVA from generating rows the native layout cannot hold.
                $count = count( $slots );
                if ( $count > 12 || $count < $group['minItems'] || $count > $group['maxItems'] ) { return self::error( 'repeat_count', 'Existing repeat slots do not fit the canonical group bounds. Adjust the selected layout before synchronizing.' ); }
                $group['minItems'] = $count; $group['maxItems'] = $count;
            }
            $groups[] = $group;
        }
        $layout = array_values( array_filter( $source['layout'], static function ( $member ) use ( $removed ) { return 'protected_slot' === $member['kind'] || ! isset( $removed[ $member['key'] ] ); } ) );
        foreach ( $groups as &$group ) {
            // Recompute optional adjacency anchors after explicitly omitted generated members.
            foreach ( $layout as $index => $member ) {
                if ( 'repeat_group' !== $member['kind'] || $member['key'] !== $group['group_key'] ) { continue; }
                foreach ( [ 'preceding_anchor' => -1, 'following_anchor' => 1 ] as $anchor => $offset ) {
                    unset( $group[ $anchor ] ); $adjacent = $layout[ $index + $offset ] ?? null;
                    if ( $adjacent && in_array( $adjacent['kind'], [ 'field', 'protected_slot' ], true ) ) { $group[ $anchor ] = [ 'kind' => 'field' === $adjacent['kind'] ? 'field' : 'protected', 'key' => $adjacent['key'] ]; }
                }
            }
        }
        unset( $group );
        foreach ( $fields as $field ) { if ( strlen( $field['notes'] ) > 8000 ) { return self::error( 'notes_size', 'A field instruction exceeds the NOVA limit of 8000 UTF8 bytes.' ); } }
        $descriptors = $draft['target_descriptors']; $bindings = []; $used = [];
        $identity = [ 'reference_type' => $draft['reference_type'], 'reference_id' => $draft['reference_id'], 'signature' => $draft['signature'], 'local_revision' => $draft['revision'] ];
        foreach ( $mapped as $key => $path ) {
            if ( ! isset( $descriptors[ $path ] ) ) { return self::error( 'target_missing', 'A native target is missing. Reconcile the local draft.', 409 ); }
            $target = $descriptors[ $path ]; $used[ $path ] = true;
            $bindings[] = self::binding( $key, $known[ $key ], null, [ 'format' => 'nova_bridge_target_v1', 'target' => $target ], $identity, $target['write_mode'] ?? 'replace' );
        }
        foreach ( $groups as $group ) {
            $key = $group['group_key']; $slots = $draft['repeat_slots'][ $key ] ?? [];
            foreach ( $group['fields'] as $field ) {
                $targets = []; $seen_slots = [];
                foreach ( $slots as $slot ) {
                    $path = $slot['targets'][ $field['field_key'] ] ?? null;
                    if ( ! $path || ! isset( $descriptors[ $path ] ) || isset( $used[ $path ] ) || isset( $seen_slots[ $slot['id'] ] ) ) { return self::error( 'repeat_incomplete', 'Every existing repeat slot needs a distinct destination for each generated member before synchronization.' ); }
                    $seen_slots[ $slot['id'] ] = true; $used[ $path ] = true;
                    $targets[] = [ 'slot_id' => $slot['id'], 'target' => $descriptors[ $path ] ];
                }
                if ( $targets ) { $bindings[] = self::binding( $key . '[].' . $field['field_key'], $field, $key, [ 'format' => 'nova_bridge_target_v1', 'slots' => $targets ], $identity, 'fixed_slots' ); }
            }
        }
        $policy = self::policy( $draft );
        try { $policy_json = self::canonical_json( $policy ); } catch ( InvalidArgumentException $error ) { return self::error( 'policy', $error->getMessage() ); }
        $policy_digest = hash( 'sha256', $policy_json );
        foreach ( $bindings as $index => &$binding ) { $binding['expected_identity']['plugin_policy_digest'] = $policy_digest; if ( 0 === $index ) { $binding['expected_identity']['plugin_policy_json'] = $policy_json; } }
        unset( $binding );
        $warnings = [];
        $covered = array_column( $bindings, 'source_path' );
        foreach ( $known as $key => $field ) { if ( ! isset( $skips[ $key ] ) && ! in_array( $key, $covered, true ) ) { $warnings[] = 'Generated source ' . $key . ' has no destination. NOVA cannot seal this configuration.'; } }
        if ( $skips ) { $warnings[] = 'Explicitly skipped sources are omitted from this site-owned template. The original stock template is unchanged.'; }
        if ( ! $bindings ) { $warnings[] = 'No generated source bindings exist; this configuration cannot be activated.'; }
        return [ 'template' => [ 'fields' => $fields, 'groups' => $groups, 'layout' => $layout, 'examples' => null, 'authoring_notes' => $guidance ], 'bindings' => $bindings, 'policy_json' => $policy_json, 'policy_digest' => $policy_digest, 'warnings' => $warnings ];
    }

    private static function binding( string $source, array $field, ?string $group, array $target, array $identity, string $write_mode ): array {
        return [ 'source_path' => $source, 'field_key' => $field['field_key'], 'group_key' => $group, 'source_type' => $field['field_type'], 'target_type' => $field['field_type'], 'write_mode' => $write_mode, 'target_descriptor' => $target, 'expected_identity' => $identity ];
    }

    public static function policy( array $draft ): array {
        $protected = []; $empty = []; $instructions = [];
        foreach ( $draft['fields'] as $path => $field ) {
            $instructions[ $path ] = $field['instructions'] ?? '';
            if ( 'protected' === $field['mode'] ) { $protected[] = [ 'slot_key' => $field['protected_slot'] ?? '', 'target' => $draft['target_descriptors'][ $path ] ]; }
            if ( 'leave_empty' === $field['mode'] ) { $empty[] = $draft['target_descriptors'][ $path ]; }
        }
        return [ 'schema_version' => 1, 'reference_type' => $draft['reference_type'], 'reference_id' => $draft['reference_id'], 'signature' => $draft['signature'], 'label' => $draft['label'] ?? '', 'field_instructions' => $instructions, 'protected_bindings' => $protected, 'leave_empty' => $empty, 'repeat_slots' => $draft['repeat_slots'], 'routing' => $draft['routing'], 'skipped_sources' => $draft['skipped_sources'] ];
    }
}
