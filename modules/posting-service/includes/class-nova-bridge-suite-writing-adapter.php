<?php
/** Projects local native profiles onto the delivery-only publishing-template contract. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Writing_Adapter {
    public static function error( string $code, string $message, int $status = 400 ): WP_Error {
        return new WP_Error( 'nova_writing_' . $code, $message, [ 'status' => $status ] );
    }

    public static function canonical_json( $value ): string {
        $normalize = function ( $item ) use ( &$normalize ) {
            if ( is_float( $item ) || is_resource( $item ) || is_object( $item ) ) { throw new InvalidArgumentException( 'Unsupported policy value.' ); }
            if ( is_array( $item ) ) {
                if ( [] !== $item && array_keys( $item ) !== range( 0, count( $item ) - 1 ) ) { ksort( $item, SORT_STRING ); }
                foreach ( $item as $key => $child ) { $item[ $key ] = $normalize( $child ); }
            }
            return $item;
        };
        $json = wp_json_encode( $normalize( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( ! is_string( $json ) ) { throw new InvalidArgumentException( 'Profile is not valid UTF8 JSON.' ); }
        return $json;
    }

    /** The twelve source names in PublishingTemplateMapping, not generated custom fields. */
    public static function source_fields(): array {
        $types = [ 'title' => 'text', 'meta_description' => 'text', 'h1' => 'text', 'content' => 'rich_text', 'top_content' => 'rich_text', 'bottom_content' => 'rich_text', 'image_url' => 'image', 'image_urls' => 'list', 'image_alt' => 'text', 'url' => 'text', 'page_type_frontend' => 'text', 'language' => 'text' ];
        $fields = [];
        foreach ( $types as $key => $type ) { $fields[ $key ] = [ 'source_path' => $key, 'label' => ucwords( str_replace( '_', ' ', $key ) ), 'type' => $type ]; }
        return $fields;
    }

    public static function stock_catalog(): array {
        return [ 'id' => 'nova-delivery-fields-v1', 'revision' => '1', 'label' => 'Current NOVA delivery fields', 'family' => 'page', 'authoring_notes' => '', 'fields' => array_values( self::source_fields() ), 'groups' => [], 'protected_slots' => [] ];
    }

    public static function template_record( $value ): bool {
        return is_array( $value ) && Nova_Bridge_Suite_Posting_Protocol::uuid( $value['id'] ?? null ) && is_int( $value['revision'] ?? null ) && $value['revision'] > 0 && true === Nova_Bridge_Suite_Posting_Protocol::validate( $value, 'PublishingTemplate' );
    }

    public static function catalog_template( array $record ): array {
        $item = self::stock_catalog();
        $item['id'] = $record['id']; $item['revision'] = (string) $record['revision']; $item['label'] = $record['name']; $item['family'] = $record['page_type'];
        return $item;
    }

    public static function field_id( string $path ): string { return 'field_' . substr( hash( 'sha256', $path ), 0, 24 ); }
    public static function slot_uuid( $value ): bool { return is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $value ); }

    /** Validate retained instructions. They never become unsupported API properties. */
    public static function authoring_notes( array $draft ) {
        $notes = $draft['guidance'] ?? ''; $mode = $draft['guidance_mode'] ?? ( '' === $notes ? 'inherit' : 'set' );
        if ( ! is_string( $notes ) || strlen( $notes ) > 8000 || 1 !== preg_match( '//u', $notes ) || ! in_array( $mode, [ 'inherit', 'set', 'clear' ], true ) || ( 'set' === $mode && '' === trim( $notes ) ) ) { return self::error( 'notes_size', 'Local layout instructions allow 8000 UTF-8 bytes; choose inherit, set or clear explicitly.' ); }
        foreach ( $draft['fields'] ?? [] as $field ) {
            if ( ! is_string( $field['instructions'] ?? '' ) || strlen( $field['instructions'] ?? '' ) > 8000 ) { return self::error( 'notes_size', 'Local field instructions allow 8000 UTF-8 bytes.' ); }
        }
        return [ 'guidance_mode' => $mode, 'guidance' => $notes ];
    }

    /** Exact wire input only. Descriptors, notes, native IDs and guards stay in WordPress. */
    public static function template_input( array $draft ) {
        $notes = self::authoring_notes( $draft ); if ( is_wp_error( $notes ) ) { return $notes; }
        if ( 'nova' !== ( $draft['catalog_mode'] ?? '' ) ) { return self::error( 'preview', 'Choose the current NOVA delivery catalog before synchronization.', 409 ); }
        if ( 'nova-delivery-fields-v1' !== ( $draft['template']['id'] ?? '' ) && ! Nova_Bridge_Suite_Posting_Protocol::uuid( $draft['template']['id'] ?? null ) ) { return self::error( 'legacy_catalog', 'The historical writing template is retained. Select the current delivery catalog and review its source mappings before synchronizing.', 409 ); }
        foreach ( $draft['repeat_slots'] ?? [] as $slots ) { if ( $slots ) { return self::error( 'repeat_unsupported', 'Saved repeat mappings are retained locally. This posting API supplies no generated repeat members or slot contract; explicitly review them before using scalar delivery fields.', 409 ); } }
        $known = self::source_fields(); $skipped = []; $fields = []; $mapping = []; $seen = [];
        foreach ( $draft['skipped_sources'] ?? [] as $skip ) {
            if ( ! isset( $known[ $skip['source_path'] ?? '' ] ) ) { return self::error( 'source_unsupported', 'A retained skipped source is outside the current twelve-field delivery contract. Review it explicitly.', 409 ); }
            $skipped[ $skip['source_path'] ] = true;
        }
        $selected = $draft['fields'] ?? []; ksort( $selected, SORT_STRING );
        foreach ( $selected as $path => $field ) {
            if ( 'mapped' !== ( $field['mode'] ?? '' ) ) { continue; }
            $source = $field['source_path'] ?? '';
            if ( ! isset( $known[ $source ] ) || isset( $skipped[ $source ] ) ) { return self::error( 'source_unsupported', 'A selected source must belong to the current delivery contract and cannot also be skipped. Custom generated sources are not supported by this posting API.', 409 ); }
            if ( ! isset( $draft['target_descriptors'][ $path ] ) ) { return self::error( 'target_missing', 'Reconcile the missing native target before synchronization.', 409 ); }
            $seen[ $source ] = true;
            $id = self::field_id( $path );
            // Stock content is nullable. Required is a local explicit choice, never inferred from a prompt.
            $fields[] = [ 'id' => $id, 'label' => $known[ $source ]['label'], 'kind' => $known[ $source ]['type'], 'required' => true === ( $field['required'] ?? false ) ];
            $mapping[] = [ 'field_id' => $id, 'source_field' => $source ];
        }
        if ( ! $fields ) { return self::error( 'empty_mapping', 'Map at least one supported delivery field before synchronization.', 409 ); }
        $page_type = $draft['profile_page_type'] ?? $draft['catalog_snapshot']['family'] ?? 'page';
        $name = trim( $draft['label'] ?? '' ); if ( '' === $name ) { $name = 'WordPress ' . ( $draft['reference_type'] ?? 'page' ) . ' ' . ( $draft['reference_id'] ?? '' ); }
        $input = [ 'name' => $name, 'page_type' => $page_type, 'definition' => [ 'version' => 1, 'fields' => $fields ], 'mapping' => [ 'version' => 1, 'fields' => $mapping ], 'enabled' => true ];
        $valid = Nova_Bridge_Suite_Posting_Protocol::validate( $input, 'PublishingTemplateInput' );
        return is_wp_error( $valid ) ? $valid : $input;
    }

    public static function prepare( array $draft, array $source = [] ) {
        $input = self::template_input( $draft ); if ( is_wp_error( $input ) ) { return $input; }
        return [ 'template' => $input, 'warnings' => self::warnings( $draft ) ];
    }

    public static function warnings( array $draft ): array {
        $warnings = [ 'Human instructions and rules are retained in WordPress only. The current posting API does not accept them or apply them during generation.', 'Publishing templates map existing delivered fields; they do not generate extra labels, shorten text or create repeat content.' ];
        if ( ! empty( $draft['skipped_sources'] ) ) { $warnings[] = 'Skipped sources are omitted from this publishing mapping. NOVA may still deliver them; their generation is unchanged.'; }
        return $warnings;
    }

    /** Used by local diagnostics only; never sent to NOVA. */
    public static function policy( array $draft ): array { return $draft; }
}
