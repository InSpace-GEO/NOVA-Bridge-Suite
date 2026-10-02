<?php
/** Local destination descriptions and the existing stock-delivery projection. */
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
        if ( ! is_array( $draft['fields'] ?? [] ) || count( $draft['fields'] ?? [] ) > 500 ) { return self::error( 'description', 'Supply a bounded destination field map.' ); }
        foreach ( $draft['fields'] ?? [] as $field ) {
            if ( ! is_array( $field ) ) { return self::error( 'description', 'Each destination must have a field description.' ); }
            if ( ! is_string( $field['instructions'] ?? '' ) || strlen( $field['instructions'] ?? '' ) > 8000 || 1 !== preg_match( '//u', $field['instructions'] ?? '' ) || false !== strpos( $field['instructions'] ?? '', "\0" ) ) { return self::error( 'notes_size', 'Local field instructions allow 8000 valid UTF-8 bytes.' ); }
        }
        return [ 'guidance_mode' => $mode, 'guidance' => $notes ];
    }

    public static function is_destination_draft( array $draft ): bool {
        if ( 'destination' === ( $draft['catalog_mode'] ?? '' ) || ! empty( $draft['destination_groups'] ) ) { return true; }
        foreach ( $draft['fields'] ?? [] as $field ) { if ( 'adapt' === ( $field['mode'] ?? '' ) ) { return true; } }
        return false;
    }

    /** Backend handover data, not an undocumented publishing-template HTTP request. */
    public static function destination_description( array $draft ) {
        $notes = self::authoring_notes( $draft ); if ( is_wp_error( $notes ) ) { return $notes; }
        if ( ! class_exists( 'Nova_Bridge_Suite_Mapping_Drafts' ) || ! method_exists( 'Nova_Bridge_Suite_Mapping_Drafts', 'normalize_description' ) ) { return self::error( 'description_unavailable', 'Destination description validation is unavailable.', 503 ); }
        foreach ( $draft['repeat_slots'] ?? [] as $slots ) { if ( $slots ) { return self::error( 'mixed_mapping', 'Review historical repeat bindings and describe their existing destinations before exporting the backend description.', 409 ); } }
        $fields = []; $ids = []; $selected = $draft['fields'] ?? []; ksort( $selected, SORT_STRING );
        foreach ( $selected as $path => $field ) {
            if ( 'mapped' === ( $field['mode'] ?? '' ) ) { return self::error( 'mixed_mapping', 'Convert retained direct-source bindings explicitly before exporting a destination description.', 409 ); }
            if ( 'adapt' !== ( $field['mode'] ?? '' ) ) { continue; }
            if ( ! isset( $draft['target_descriptors'][ $path ] ) || '' !== ( $field['source_path'] ?? '' ) || ( array_key_exists( 'required', $field ) && ! is_bool( $field['required'] ) ) ) { return self::error( 'destination', 'Each adapted field needs its saved native descriptor and explicit value policy.' ); }
            $description = Nova_Bridge_Suite_Mapping_Drafts::normalize_description( $field['description'] ?? null ); if ( is_wp_error( $description ) ) { return $description; }
            $id = self::field_id( $path ); if ( in_array( $id, $ids, true ) ) { return self::error( 'destination', 'Destination identities must be unique.' ); }
            $ids[ $path ] = $id;
            $item = [ 'id' => $id ] + $description + [ 'required' => $field['required'] ?? false, 'instructions' => $field['instructions'] ?? '' ];
            if ( ! $item['constraints'] ) { unset( $item['constraints'] ); }
            foreach ( [ 'element', 'logical_field' ] as $key ) { if ( isset( $draft['target_descriptors'][ $path ][ $key ] ) ) { $item[ $key ] = $draft['target_descriptors'][ $path ][ $key ]; } }
            $fields[] = $item;
        }
        if ( ! $fields ) { return self::error( 'empty_description', 'Describe at least one destination for NOVA content before exporting.' ); }
        $groups = []; $seen = []; $grouped = []; $selected_groups = $draft['destination_groups'] ?? [];
        if ( ! is_array( $selected_groups ) || count( $selected_groups ) > 64 ) { return self::error( 'destination_group', 'Supply at most 64 fixed destination groups.' ); }
        foreach ( $selected_groups as $group ) {
            if ( ! is_array( $group ) || ! self::slot_uuid( $group['id'] ?? null ) || isset( $seen[ $group['id'] ] ) || ! is_string( $group['label'] ?? null ) || '' === trim( $group['label'] ) || strlen( $group['label'] ) > 200 || 1 !== preg_match( '//u', $group['label'] ) || false !== strpos( $group['label'], "\0" ) || ! is_array( $group['slots'] ?? null ) || ! $group['slots'] || count( $group['slots'] ) > 500 ) { return self::error( 'destination_group', 'Fixed groups need unique UUIDs, a label and existing slots.' ); }
            $seen[ $group['id'] ] = true; $slots = [];
            foreach ( $group['slots'] as $ordinal => $slot ) {
                if ( ! is_array( $slot ) || ! self::slot_uuid( $slot['id'] ?? null ) || isset( $seen[ $slot['id'] ] ) || ( $slot['ordinal'] ?? null ) !== $ordinal || ! is_array( $slot['fields'] ?? null ) || ! $slot['fields'] || count( $slot['fields'] ) > 500 ) { return self::error( 'destination_group', 'Existing slots require unique UUIDs, ordered ordinals and selected destinations.' ); }
                $seen[ $slot['id'] ] = true; $members = [];
                foreach ( $slot['fields'] as $path ) {
                    if ( ! is_string( $path ) || ! isset( $ids[ $path ] ) || isset( $grouped[ $path ] ) || ! in_array( $selected[ $path ]['description']['value_type'], [ 'text', 'rich_text', 'url', 'email' ], true ) ) { return self::error( 'destination_group', 'Each fixed-slot member must identify one distinct adapted scalar destination.' ); }
                    $grouped[ $path ] = true; $members[] = $ids[ $path ];
                }
                $slots[] = [ 'id' => $slot['id'], 'ordinal' => $ordinal, 'field_ids' => $members ];
            }
            $groups[] = [ 'id' => $group['id'], 'label' => $group['label'], 'capacity' => count( $slots ), 'slots' => $slots ];
        }
        $text = 'inherit' === $notes['guidance_mode'] ? ( $draft['catalog_snapshot']['authoring_notes'] ?? '' ) : $notes['guidance'];
        if ( 'clear' === $notes['guidance_mode'] ) { $text = ''; }
        if ( ! is_string( $text ) || strlen( $text ) > 8000 || 1 !== preg_match( '//u', $text ) || false !== strpos( $text, "\0" ) ) { return self::error( 'notes_size', 'Inherited layout instructions are invalid.' ); }
        $name = trim( $draft['label'] ?? '' ); if ( '' === $name ) { $name = 'WordPress publishing template'; }
        return [ 'format' => 'nova-template-description/v1', 'name' => $name, 'page_type' => $draft['profile_page_type'] ?? 'page', 'instructions' => [ 'mode' => $notes['guidance_mode'], 'text' => $text ], 'fields' => $fields, 'groups' => $groups ];
    }

    /** Local scalar validation seam. It never accepts a remote envelope or performs a write. */
    public static function validate_fitted_values( array $draft, array $values ) {
        $description = self::destination_description( $draft ); if ( is_wp_error( $description ) ) { return $description; }
        $known = array_column( $description['fields'], null, 'id' );
        if ( array_diff( array_keys( $values ), array_keys( $known ) ) ) { return self::error( 'fitted_unknown', 'The fitted result contains a destination outside this description.' ); }
        $result = [];
        foreach ( $draft['fields'] as $path => $field ) {
            if ( 'adapt' !== $field['mode'] ) { continue; }
            $id = self::field_id( $path ); $rule = $known[ $id ];
            if ( ! array_key_exists( $id, $values ) || null === $values[ $id ] ) {
                if ( $rule['required'] ) { return self::error( 'fitted_required', 'A required destination has no fitted value: ' . $rule['label'] ); }
                continue;
            }
            if ( ! in_array( $rule['value_type'], [ 'text', 'rich_text', 'url', 'email' ], true ) ) { return self::error( 'fitted_writer', 'This compound value still requires a verified typed native writer.' ); }
            $value = $values[ $id ];
            if ( ! is_string( $value ) || 1 !== preg_match( '//u', $value ) || false !== strpos( $value, "\0" ) ) { return self::error( 'fitted_type', 'A fitted scalar must contain valid UTF-8 text.' ); }
            if ( '' !== $value && ( ( 'url' === $rule['value_type'] && ( false === filter_var( $value, FILTER_VALIDATE_URL ) || ! preg_match( '#^https?://#i', $value ) ) ) || ( 'email' === $rule['value_type'] && false === filter_var( $value, FILTER_VALIDATE_EMAIL ) ) ) ) { return self::error( 'fitted_type', 'A fitted URL or email is invalid.' ); }
            $text = 'rich_text' === $rule['value_type'] ? html_entity_decode( strip_tags( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : $value;
            if ( isset( $rule['constraints']['max_length'] ) && preg_match_all( '/./us', $text ) > $rule['constraints']['max_length'] ) { return self::error( 'fitted_length', 'A fitted value exceeds its character limit: ' . $rule['label'] ); }
            $result[ $path ] = $value;
        }
        return $result;
    }

    /** Exact wire input only. Descriptors, notes, native IDs and guards stay in WordPress. */
    public static function template_input( array $draft ) {
        if ( self::is_destination_draft( $draft ) ) { return self::error( 'adaptation_contract_unavailable', 'Destination descriptions are prepared locally. The current posting API cannot synchronize them or return fitted destination values; export the backend description for integration.', 409 ); }
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
        $warnings = [ 'Human instructions and rules are retained in WordPress only. The current posting API does not accept them or apply them during content adaptation.', 'The current publishing API maps existing delivered fields; fitting them to destination descriptions requires the backend adaptation contract.' ];
        if ( ! empty( $draft['skipped_sources'] ) ) { $warnings[] = 'Skipped sources are omitted from this publishing mapping. NOVA may still deliver them; their generation is unchanged.'; }
        return $warnings;
    }

    /** Used by local diagnostics only; never sent to NOVA. */
    public static function policy( array $draft ): array { return $draft; }
}
