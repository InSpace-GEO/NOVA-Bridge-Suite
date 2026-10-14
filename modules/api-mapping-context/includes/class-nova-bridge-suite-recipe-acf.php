<?php
/** Schema-owned nested ACF/SCF recipes. No field definitions are created here. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Recipe_ACF {
    private $validated_schema = [];
    private $prototype_scopes = [];
    private $validated_blueprint = [];
    private static function error( string $message, int $status = 409 ): WP_Error { return new WP_Error( 'nova_recipe_acf', $message, [ 'status' => $status ] ); }
    public static function available(): bool { return class_exists( 'Nova_Bridge_Suite_Content_Transport' ) && function_exists( 'acf_get_field_groups' ) && function_exists( 'acf_get_fields' ) && function_exists( 'get_field' ) && function_exists( 'update_field' ); }
    public static function register(): void { Nova_Bridge_Suite_Recipe_Engine::register_adapter( 'acf', new self() ); }
    private static function escape( string $key ): string { return str_replace( [ '~', '/' ], [ '~0', '~1' ], $key ); }

    private static function schema( array $field ): array {
        $keys = [ 'key', 'name', 'label', 'type', 'instructions', 'required', 'min', 'max', 'min_length', 'max_length', 'choices', 'allow_null', 'multiple', 'field_type', 'taxonomy', 'return_format', 'default_value', 'readonly', 'disabled' ];
        $out = array_intersect_key( $field, array_flip( $keys ) );
        if ( isset( $field['sub_fields'] ) ) { $out['sub_fields'] = array_map( [ __CLASS__, 'schema' ], array_values( array_filter( $field['sub_fields'], static function ( $child ) { return ! in_array( $child['type'] ?? '', [ 'tab', 'message', 'accordion' ], true ); } ) ) ); }
        if ( isset( $field['layouts'] ) ) { $out['layouts'] = []; foreach ( $field['layouts'] as $layout ) { $out['layouts'][] = array_intersect_key( $layout, array_flip( [ 'min', 'max' ] ) ) + [ 'name' => $layout['name'], 'key' => $layout['key'], 'label' => $layout['label'] ?? $layout['name'], 'sub_fields' => array_map( [ __CLASS__, 'schema' ], array_values( array_filter( $layout['sub_fields'] ?? [], static function ( $child ) { return ! in_array( $child['type'] ?? '', [ 'tab', 'message', 'accordion' ], true ); } ) ) ) ]; } }
        return $out;
    }
    private static function catalog( string $post_type, int $post_id = 0, string $template = '', string $taxonomy = '' ): array {
        if ( ! self::available() ) { return []; }
        if ( $taxonomy ) {
            $screen = [ 'taxonomy' => $taxonomy, 'post_id' => $post_id ? $taxonomy . '_' . $post_id : 0 ];
            $fields = Nova_Bridge_Suite_Content_Transport::acf_schema_for_screen( $screen );
        } else { $fields = Nova_Bridge_Suite_Content_Transport::field_catalog( $post_type, $post_id, $template )['acf']; }
        $result = []; foreach ( $fields as $name => $field ) { $result[$name] = self::schema( $field ); } return $result;
    }
    private static function children( array $fields ): array { $out = []; foreach ( $fields as $field ) { $out[$field['name']] = self::blank_value( $field ); } return $out; }
    private static function blank_value( array $field ) {
        $type = $field['type'];
        if ( 'group' === $type ) { return self::children( $field['sub_fields'] ?? [] ); }
        if ( 'repeater' === $type ) { return array_fill( 0, max( 1, min( 500, (int) ( $field['min'] ?? 1 ) ) ), self::children( $field['sub_fields'] ?? [] ) ); }
        if ( 'flexible_content' === $type ) {
            $rows = [];
            foreach ( $field['layouts'] ?? [] as $layout ) { for ( $index = 0; $index < min( 500, (int) ( $layout['min'] ?? 0 ) ); ++$index ) { $rows[] = [ 'acf_fc_layout' => $layout['name'] ] + self::children( $layout['sub_fields'] ); } }
            $minimum = max( 1, min( 500, (int) ( $field['min'] ?? 1 ) ) );
            foreach ( $field['layouts'] ?? [] as $layout ) {
                $counts = array_count_values( array_column( $rows, 'acf_fc_layout' ) );
                while ( count( $rows ) < $minimum && ( empty( $layout['max'] ) || ( $counts[$layout['name']] ?? 0 ) < (int) $layout['max'] ) ) { $rows[] = [ 'acf_fc_layout' => $layout['name'] ] + self::children( $layout['sub_fields'] ); $counts[$layout['name']] = ( $counts[$layout['name']] ?? 0 ) + 1; }
            }
            return $rows;
        }
        if ( array_key_exists( 'default_value', $field ) && ! in_array( $field['default_value'], [ null, false, '' ], true ) ) { return $field['default_value']; }
        if ( in_array( $type, [ 'select', 'radio', 'button_group' ], true ) && empty( $field['multiple'] ) && empty( $field['allow_null'] ) && ! empty( $field['choices'] ) ) { return (string) array_keys( $field['choices'] )[0]; }
        if ( 'true_false' === $type ) { return false; }
        if ( in_array( $type, [ 'number', 'range' ], true ) ) { return empty( $field['required'] ) ? '' : max( 0, (float) ( $field['min'] ?? 0 ) ); }
        if ( in_array( $type, [ 'image', 'file', 'post_object', 'taxonomy' ], true ) && empty( $field['multiple'] ) ) { return 0; }
        if ( in_array( $type, [ 'gallery', 'relationship', 'checkbox' ], true ) || ! empty( $field['multiple'] ) ) { return []; }
        if ( 'link' === $type ) { return [ 'url' => '', 'title' => '', 'target' => '' ]; }
        if ( 'google_map' === $type ) { return []; }
        return '';
    }
    public static function design( string $post_type, int $post_id = 0, string $template = '', string $taxonomy = '' ) {
        if ( ! current_user_can( 'manage_options' ) ) { return self::error( 'Administrator access is required to inspect a complete custom-field template.', 403 ); }
        $schema = self::catalog( $post_type, $post_id, $template, $taxonomy );
        if ( ! $schema ) { return self::error( 'No applicable, writable ACF/SCF field schema is installed for this destination.' ); }
        $new_schema = self::catalog( $post_type, 0, $template, $taxonomy );
        if ( ! $new_schema || Nova_Bridge_Suite_Recipe_Engine::digest( $schema ) !== Nova_Bridge_Suite_Recipe_Engine::digest( $new_schema ) ) { return self::error( 'The example and new-item ACF schemas differ. Configure location rules for the whole destination before capturing a creation template; no fields were dropped.' ); }
        $document = self::children( array_values( $schema ) );
        if ( $post_id ) {
            if ( $taxonomy ) { $object = get_term( $post_id, $taxonomy ); if ( ! $object || is_wp_error( $object ) || ! current_user_can( 'edit_term', $post_id ) ) { return self::error( 'The term field example cannot be edited by this account.', 403 ); } $object_id = $taxonomy . '_' . $post_id; }
            else { $post = get_post( $post_id ); if ( ! $post || $post->post_type !== $post_type || ! current_user_can( 'edit_post', $post_id ) ) { return self::error( 'The custom-field example cannot be edited by this account.', 403 ); } $object_id = $post_id; }
            foreach ( $schema as $name => $field ) { foreach ( [ $name, '_' . $name ] as $key ) { if ( ! current_user_can( $taxonomy ? 'edit_term_meta' : 'edit_post_meta', $post_id, $key ) ) { return self::error( 'You cannot inspect the complete metadata for this custom-field example.', 403 ); } } }
            $read = Nova_Bridge_Suite_Content_Transport::read_named_acf( $schema, $object_id );
            foreach ( $schema as $name => $field ) { if ( array_key_exists( $name, $read ) ) { $document[$name] = self::fill_prototypes( $read[$name], $field ); } }
        }
        $blueprint = [ 'document' => $document, 'schema' => $schema, 'post_type' => $post_type, 'template' => $template, 'schema_hash' => Nova_Bridge_Suite_Recipe_Engine::digest( $new_schema ) ];
        if ( $taxonomy ) { $blueprint['taxonomy'] = $taxonomy; }
        return [ 'blueprint' => $blueprint ] + self::describe( $blueprint );
    }
    /** Absence is optional; a present but invalid schema must never disappear silently. */
    public static function companion_design( string $post_type, int $post_id = 0, string $template = '', string $taxonomy = '' ) {
        if ( ! self::available() ) { return null; }
        $example = self::catalog( $post_type, $post_id, $template, $taxonomy );
        $creation = self::catalog( $post_type, 0, $template, $taxonomy );
        if ( ! $example && ! $creation ) { return null; }
        return self::design( $post_type, $post_id, $template, $taxonomy );
    }
    private static function fill_prototypes( $value, array $field ) {
        if ( 'group' === $field['type'] ) { $out = []; foreach ( $field['sub_fields'] ?? [] as $child ) { $out[$child['name']] = self::fill_prototypes( is_array( $value ) ? ( $value[$child['name']] ?? null ) : null, $child ); } return $out; }
        if ( in_array( $field['type'], [ 'repeater', 'flexible_content' ], true ) && empty( $value ) ) { return self::blank_value( $field ); }
        if ( in_array( $field['type'], [ 'repeater', 'flexible_content' ], true ) && is_array( $value ) ) {
            foreach ( $value as &$row ) {
                $children = $field['sub_fields'] ?? [];
                if ( 'flexible_content' === $field['type'] ) { foreach ( $field['layouts'] ?? [] as $layout ) { if ( ( $row['acf_fc_layout'] ?? '' ) === $layout['name'] ) { $children = $layout['sub_fields']; break; } } }
                foreach ( $children as $child ) { $row[$child['name']] = self::fill_prototypes( $row[$child['name']] ?? null, $child ); }
            }
            unset( $row ); return $value;
        }
        return null === $value || false === $value ? self::blank_value( $field ) : $value;
    }
    private static function format( array $field ): string {
        if ( in_array( $field['type'], [ 'textarea', 'wysiwyg' ], true ) ) { return 'html'; }
        if ( in_array( $field['type'], [ 'url', 'oembed' ], true ) ) { return 'url'; }
        if ( in_array( $field['type'], [ 'number', 'range' ], true ) ) { return 'number'; }
        if ( 'true_false' === $field['type'] ) { return 'boolean'; } return 'text';
    }
    private static function walk( array $schema, array $values, string $path, string $label, array &$fields, array &$containers, int $depth = 0 ): void {
        if ( $depth > 12 ) { return; }
        foreach ( $schema as $field ) {
            $name = $field['name']; $target = $path . '/' . self::escape( $name ); $title = trim( $label . ' › ' . ( $field['label'] ?? $name ), ' ›' ); $value = $values[$name] ?? self::blank_value( $field ); $type = $field['type'];
            if ( 'group' === $type ) { self::walk( $field['sub_fields'] ?? [], is_array( $value ) ? $value : [], $target, $title, $fields, $containers, $depth + 1 ); continue; }
            if ( in_array( $type, [ 'repeater', 'flexible_content' ], true ) ) {
                $items = []; foreach ( (array) $value as $index => $row ) {
                    $children = $field['sub_fields'] ?? []; $item_label = 'Row ' . ( $index + 1 );
                    if ( 'flexible_content' === $type ) { foreach ( $field['layouts'] ?? [] as $layout ) { if ( ( $row['acf_fc_layout'] ?? '' ) === $layout['name'] ) { $children = $layout['sub_fields']; $item_label = $layout['label']; break; } } }
                    $items[] = [ 'index' => $index, 'label' => $item_label ]; self::walk( $children, is_array( $row ) ? $row : [], $target . '/' . $index, $title . ' › ' . $item_label, $fields, $containers, $depth + 1 );
                }
                $containers[] = [ 'target' => $target, 'label' => $title, 'kind' => $type, 'items' => $items, 'layouts' => $field['layouts'] ?? [], 'required' => ! empty( $field['required'] ), 'min_items' => max( (int) ( $field['min'] ?? 0 ), empty( $field['required'] ) ? 0 : 1 ), 'max_items' => min( 500, (int) ( ( $field['max'] ?? 0 ) ?: 500 ) ), '_field_schema' => $field ]; continue;
            }
            if ( 'link' === $type ) { foreach ( [ 'url' => 'url', 'title' => 'text' ] as $part => $format ) { $fields[] = [ 'target' => $target . '/' . $part, 'label' => $title . ' › ' . ucfirst( $part ), 'format' => $format, 'kind' => $part, 'writable' => true, 'value' => $value[$part] ?? '', 'sample' => ! empty( $value[$part] ) ? $value[$part] : ( 'url' === $part ? 'https://example.com/' : 'Example link' ), 'constraints' => [ 'type' => 'string' ] + ( 'url' === $part ? [ 'format' => 'uri-reference' ] : [] ), 'instructions' => $field['instructions'] ?? '', 'required' => 'url' === $part && ! empty( $field['required'] ) ]; } continue; }
            $writable = in_array( $type, [ 'text', 'textarea', 'wysiwyg', 'email', 'url', 'oembed', 'number', 'range', 'true_false', 'color_picker', 'date_picker', 'date_time_picker', 'time_picker', 'select', 'radio', 'button_group' ], true ) && empty( $field['multiple'] );
            $fields[] = [ 'target' => $target, 'label' => $title, 'format' => self::format( $field ), 'kind' => $type, 'writable' => $writable, 'protected' => ! $writable, 'value' => $value, 'sample' => $writable ? self::sample_value( $field, $value ) : $value, 'required' => ! empty( $field['required'] ), 'instructions' => $field['instructions'] ?? '', 'choices' => $field['choices'] ?? [], 'constraints' => self::constraints( $field ) ];
        }
    }
    public static function describe( array $blueprint ): array {
        $fields = []; $containers = []; self::walk( array_values( $blueprint['schema'] ?? [] ), $blueprint['document'] ?? [], '/document', '', $fields, $containers );
        $budget = 0; $suggested = self::suggest( array_values( $blueprint['schema'] ?? [] ), $blueprint['document'] ?? [], '/document', '/acf', $budget );
        return [ 'fields' => $fields, 'containers' => $containers, 'suggested_bindings' => $suggested['bindings'], 'suggested_regions' => $suggested['regions'], 'sample_content' => [ 'acf' => $suggested['sample'] ],
            'warnings' => [ 'ACF fields use the installed schema. Their visual appearance is provided by your theme. Fixed media and relationship IDs remain in the native template.' ] ];
    }

    private static function constraints( array $field ): array {
        $type = $field['type']; $out = [ 'type' => 'number' === self::format( $field ) ? 'number' : ( 'true_false' === $type ? 'boolean' : 'string' ) ];
        foreach ( [ 'min' => 'minimum', 'max' => 'maximum', 'min_length' => 'minLength', 'max_length' => 'maxLength' ] as $native => $key ) { if ( isset( $field[$native] ) && '' !== $field[$native] && is_numeric( $field[$native] ) ) { $out[$key] = in_array( $key, [ 'minLength', 'maxLength' ], true ) ? (int) $field[$native] : (float) $field[$native]; } }
        if ( ! empty( $field['choices'] ) ) { $out['enum'] = array_map( 'strval', array_keys( $field['choices'] ) ); if ( ! empty( $field['allow_null'] ) ) { $out['enum'][] = ''; } }
        if ( 'email' === $type ) { $out['format'] = 'email'; } elseif ( in_array( $type, [ 'url', 'oembed' ], true ) ) { $out['format'] = 'uri-reference'; }
        return $out;
    }

    private static function sample_value( array $field, $value ) {
        if ( 'true_false' === $field['type'] ) { return (bool) $value; }
        if ( in_array( $field['type'], [ 'number', 'range' ], true ) ) { $number = is_numeric( $value ) ? (float) $value : (float) ( $field['min'] ?? 0 ); if ( isset( $field['min'] ) && '' !== $field['min'] && is_numeric( $field['min'] ) ) { $number = max( $number, (float) $field['min'] ); } if ( isset( $field['max'] ) && '' !== $field['max'] && is_numeric( $field['max'] ) ) { $number = min( $number, (float) $field['max'] ); } return $number; }
        if ( ! empty( $field['choices'] ) ) { return in_array( (string) $value, array_map( 'strval', array_keys( $field['choices'] ) ), true ) ? (string) $value : (string) array_keys( $field['choices'] )[0]; }
        if ( is_string( $value ) && '' !== trim( $value ) && ! in_array( $field['type'], [ 'text', 'textarea' ], true ) ) { return $value; }
        $samples = [ 'email' => 'example@example.com', 'url' => 'https://example.com/', 'oembed' => 'https://example.com/', 'color_picker' => '#123456', 'date_picker' => '20260101', 'date_time_picker' => '2026-01-01 12:00:00', 'time_picker' => '12:00:00' ];
        $sample = is_string( $value ) && '' !== trim( $value ) ? $value : ( $samples[$field['type']] ?? ( ( $field['label'] ?? $field['name'] ) . ' example' ) );
        $characters = preg_split( '//u', $sample, -1, PREG_SPLIT_NO_EMPTY );
        if ( isset( $field['min_length'] ) && is_numeric( $field['min_length'] ) ) { while ( count( $characters ) < min( 4000, (int) $field['min_length'] ) ) { $characters[] = 'x'; } }
        if ( ! empty( $field['max_length'] ) && is_numeric( $field['max_length'] ) ) { $characters = array_slice( $characters, 0, (int) $field['max_length'] ); }
        $sample = implode( '', $characters );
        return 'wysiwyg' === $field['type'] ? '<p>' . esc_html( $sample ) . '</p>' : $sample;
    }

    /** Full nested hints are emitted once at the outer owning collection. */
    private static function suggest( array $schema, array $values, string $target, string $source, int &$budget, int $depth = 0 ): array {
        $out = [ 'bindings' => [], 'regions' => [], 'sample' => [] ];
        if ( $depth > 8 || $budget > 5000 ) { return $out; }
        foreach ( $schema as $field ) {
            $budget++; $name = $field['name']; $path = $target . '/' . self::escape( $name ); $input = $source . '/' . self::escape( $name ); $type = $field['type']; $value = $values[$name] ?? self::blank_value( $field );
            if ( 'group' === $type ) {
                $nested = self::suggest( $field['sub_fields'] ?? [], is_array( $value ) ? $value : [], $path, $input, $budget, $depth );
                $out['bindings'] = array_merge( $out['bindings'], $nested['bindings'] ); $out['regions'] = array_merge( $out['regions'], $nested['regions'] ); $out['sample'][$name] = $nested['sample']; continue;
            }
            if ( in_array( $type, [ 'repeater', 'flexible_content' ], true ) ) {
                $row = is_array( $value[0] ?? null ) ? $value[0] : ( self::blank_value( $field )[0] ?? [] ); $children = $field['sub_fields'] ?? []; $variants = []; $sample = []; $variant_samples = [];
                if ( 'flexible_content' === $type ) {
                    foreach ( $field['layouts'] ?? [] as $layout ) {
                        $native = self::layout_prototype( $layout )[0]; foreach ( (array) $value as $existing ) { if ( ( $existing['acf_fc_layout'] ?? '' ) === $layout['name'] ) { $native = $existing; break; } }
                        $nested = self::suggest( $layout['sub_fields'], $native, '/0', '', $budget, $depth + 1 ); $key = $layout['name'];
                        $variants[$key] = [ 'native' => [ $native ], 'bindings' => $nested['bindings'], 'regions' => $nested['regions'] ];
                        $variant_samples[$key] = [ 'layout' => $key ] + $nested['sample'];
                        if ( ! $sample ) { $sample = [ 'layout' => $key ] + $nested['sample']; $row = $native; }
                    }
                    $first = is_array( $value[0] ?? null ) && isset( $variants[$value[0]['acf_fc_layout'] ?? ''] ) ? $value[0]['acf_fc_layout'] : array_key_first( $variants );
                    $prototype = $variants[$first] ?? [ 'native' => [ $row ], 'bindings' => [], 'regions' => [] ];
                    if ( isset( $value[0] ) ) { $prototype['native'] = [ $value[0] ]; }
                    if ( $first && isset( $variants[$first] ) ) { $sample = [ 'layout' => (string) $first ]; $selected_fields = []; $selected_containers = []; foreach ( $field['layouts'] as $layout ) { if ( $layout['name'] === $first ) { $selected = self::suggest( $layout['sub_fields'], $prototype['native'][0], '/0', '', $budget, $depth + 1 ); $sample += $selected['sample']; break; } } }
                } else { $nested = self::suggest( $children, $row, '/0', '', $budget, $depth + 1 ); $sample = $nested['sample']; $prototype = [ 'native' => [ $row ], 'bindings' => $nested['bindings'], 'regions' => $nested['regions'] ]; }
                $region = [ 'id' => 'acf_' . substr( hash( 'sha256', $path ), 0, 16 ), 'label' => $field['label'] ?? $name, 'instructions' => $field['instructions'] ?? '', 'target' => $path, 'start' => 0, 'count' => count( (array) $value ), 'prototype_start' => 0, 'prototype_count' => 1, 'source' => $input, 'kind' => 'repeat', 'empty' => 'remove', 'max_items' => min( 500, (int) ( ( $field['max'] ?? 0 ) ?: 500 ) ), 'prototype' => $prototype ];
                if ( $variants ) { $region['variant_by'] = '/layout'; $region['variants'] = $variants; $region['default_variant'] = array_key_first( $variants ); }
                $out['regions'][] = $region;
                if ( $variants ) {
                    $out['sample'][$name] = [];
                    foreach ( (array) $value as $native_row ) { $key = $native_row['acf_fc_layout'] ?? ''; if ( isset( $variant_samples[$key] ) ) { $out['sample'][$name][] = $variant_samples[$key]; } }
                    if ( ! $out['sample'][$name] ) { $out['sample'][$name][] = $sample; }
                } else { $out['sample'][$name] = array_fill( 0, max( 1, min( 500, (int) ( $field['min'] ?? 1 ) ) ), $sample ); }
                continue;
            }
            $fields = []; $containers = []; self::walk( [ $field ], [ $name => $value ], $target, '', $fields, $containers );
            foreach ( $fields as $descriptor ) {
                if ( empty( $descriptor['writable'] ) ) { continue; }
                $suffix = substr( $descriptor['target'], strlen( $path ) );
                $out['bindings'][] = [ 'target' => $descriptor['target'], 'source' => $input . $suffix, 'format' => $descriptor['format'], 'label' => $descriptor['label'], 'instructions' => $descriptor['instructions'] ?? '', 'required' => ! empty( $descriptor['required'] ) ];
                if ( 'link' === $type ) { $out['sample'][$name] = [ 'url' => is_string( $value['url'] ?? null ) && $value['url'] ? $value['url'] : 'https://example.com/', 'title' => $value['title'] ?? 'Example link' ]; }
                else { $out['sample'][$name] = self::sample_value( $field, $value ); }
            }
        }
        return $out;
    }
    public static function layout_prototype( array $layout ): array { return [ [ 'acf_fc_layout' => $layout['name'] ] + self::children( $layout['sub_fields'] ) ]; }
    public static function layout_description( array $layout, array $row = [] ): array {
        $fields = []; $containers = []; self::walk( $layout['sub_fields'], $row ?: self::layout_prototype( $layout )[0], '/0', $layout['label'] ?? $layout['name'], $fields, $containers ); return [ 'fields' => $fields, 'containers' => $containers ];
    }

    public function validate_blueprint( array $native ) {
        if ( ! is_array( $native['document'] ?? null ) || ! is_array( $native['schema'] ?? null ) || ! is_string( $native['post_type'] ?? null ) ) { return self::error( 'A complete ACF schema and document are required.' ); }
        if ( ! self::available() ) { return self::error( 'The installed ACF/SCF provider is unavailable. No schema validation was bypassed.', 503 ); }
        $live = self::catalog( $native['post_type'], 0, $native['template'] ?? '', $native['taxonomy'] ?? '' );
        if ( ! $live || ! is_string( $native['schema_hash'] ?? null ) || ! hash_equals( $native['schema_hash'], Nova_Bridge_Suite_Recipe_Engine::digest( $live ) ) || Nova_Bridge_Suite_Recipe_Engine::digest( $native['schema'] ) !== Nova_Bridge_Suite_Recipe_Engine::digest( $live ) ) { return self::error( 'The installed ACF field schema changed. Read the design again before translating this template.' ); }
        $this->validated_schema = $live;
        $this->prototype_scopes = [];
        $this->validated_blueprint = $native;
        return $native;
    }

    /** Schema scopes come from the selected native collection, never row-key guesses. */
    private function scope_description( array $native, array $scope = [] ): array {
        $out = [ 'fields' => [], 'containers' => [] ];
        if ( isset( $native['schema'] ) ) { self::walk( array_values( $native['schema'] ), $native['document'], '/document', '', $out['fields'], $out['containers'] ); return $out; }
        $scope = $scope ?: ( $this->prototype_scopes[Nova_Bridge_Suite_Recipe_Engine::digest( $native )] ?? [] );
        foreach ( $native as $index => $row ) { if ( isset( $scope[$index] ) && is_array( $row ) ) { self::walk( $scope[$index], $row, '/' . $index, '', $out['fields'], $out['containers'] ); } }
        return $out;
    }
    private static function collection_scope( array $field, array $rows ): array {
        $scope = [];
        foreach ( $rows as $index => $row ) {
            if ( 'repeater' === $field['type'] ) { $scope[$index] = $field['sub_fields'] ?? []; }
            elseif ( 'flexible_content' === $field['type'] ) { foreach ( $field['layouts'] ?? [] as $layout ) { if ( ( $row['acf_fc_layout'] ?? '' ) === $layout['name'] ) { $scope[$index] = $layout['sub_fields']; break; } } }
        }
        return $scope;
    }
    public function validate_binding( array $native, array $binding, array $scope = [] ) {
        $found = false; $value = Nova_Bridge_Suite_Recipe_Engine::get( $native, $binding['target'], $found );
        if ( ! $found || is_array( $value ) || preg_match( '~(?:^|/)acf_fc_layout$~', $binding['target'] ) ) { return self::error( 'Bind a declared scalar ACF field; layout selectors and complete parent objects are protected.' ); }
        foreach ( $this->scope_description( $native, $scope )['fields'] as $field ) { if ( $field['target'] === $binding['target'] && $field['writable'] && $field['format'] === ( $binding['format'] ?? '' ) ) { return true; } }
        return self::error( 'The chosen ACF field is protected, undeclared or has a different native value format.' );
    }
    public function validate_region( array $native, array $region, array $scope = [] ) {
        foreach ( $this->scope_description( $native, $scope )['containers'] as $container ) {
            if ( $container['target'] === $region['target'] && ( $region['max_items'] ?? 500 ) <= $container['max_items'] ) {
                foreach ( array_merge( [ $region['prototype'] ?? [] ], array_values( $region['variants'] ?? [] ) ) as $prototype ) { if ( isset( $prototype['native'] ) ) { $this->prototype_scopes[Nova_Bridge_Suite_Recipe_Engine::digest( $prototype['native'] )] = self::collection_scope( $container['_field_schema'], $prototype['native'] ); } }
                return true;
            }
        }
        return self::error( 'A repeating ACF region must target a declared row collection within its native maximum.' );
    }

    public function augment_content_schema( array $recipe, array $schema ) {
        $checked = $this->validate_blueprint( $recipe['blueprint'] ); if ( is_wp_error( $checked ) ) { return $checked; }
        return $this->augment_scope( $recipe['blueprint'], $recipe['bindings'] ?? [], $recipe['regions'] ?? [], $schema );
    }

    private static function schema_node( array $schema, array $parts ): array {
        foreach ( $parts as $part ) { $schema = $schema['properties'][$part] ?? []; }
        return $schema;
    }
    private static function schema_put( array &$schema, array $parts, array $node, bool $required = false ): void {
        if ( ! $parts ) { $schema = $node; return; }
        $cursor = &$schema;
        foreach ( $parts as $index => $part ) {
            if ( ! isset( $cursor['properties'] ) ) { $cursor['type'] = 'object'; $cursor['properties'] = []; }
            if ( $index === count( $parts ) - 1 ) { $cursor['properties'][$part] = $node; if ( $required ) { $cursor['required'] = array_values( array_unique( array_merge( $cursor['required'] ?? [], [ $part ] ) ) ); } }
            else { if ( ! isset( $cursor['properties'][$part] ) ) { $cursor['properties'][$part] = [ 'type' => 'object', 'properties' => [] ]; } $cursor = &$cursor['properties'][$part]; }
        }
    }
    private function augment_scope( array $native, array $bindings, array $regions, array $schema, array $scope = [] ): array {
        $description = $this->scope_description( $native, $scope );
        foreach ( $bindings as $binding ) {
            foreach ( $description['fields'] as $field ) {
                if ( $field['target'] !== $binding['target'] ) { continue; }
                $parts = Nova_Bridge_Suite_Recipe_Engine::parts( $binding['source'] ); $node = self::schema_node( $schema, $parts );
                self::schema_put( $schema, $parts, array_merge( $node, $field['constraints'] ?? [] ), $binding['required'] || ! empty( $field['required'] ) ); break;
            }
        }
        foreach ( $regions as $region ) {
            $parts = Nova_Bridge_Suite_Recipe_Engine::parts( $region['source'] ); $node = self::schema_node( $schema, $parts );
            $required = false; $field_schema = [];
            foreach ( $description['containers'] as $container ) {
                if ( $container['target'] !== $region['target'] ) { continue; }
                $field_schema = $container['_field_schema'];
                if ( ! empty( $region['projection'] ) ) { break; }
                $node['minItems'] = $container['min_items'] ?? 0; $node['maxItems'] = min( $region['max_items'], $container['max_items'] );
                $required = ! empty( $container['required'] ); break;
            }
            if ( isset( $region['variants'] ) && isset( $node['items']['oneOf'] ) ) {
                foreach ( array_values( $region['variants'] ) as $index => $prototype ) { $node['items']['oneOf'][$index] = $this->augment_scope( $prototype['native'], $prototype['bindings'] ?? [], $prototype['regions'] ?? [], $node['items']['oneOf'][$index], self::collection_scope( $field_schema, $prototype['native'] ) ); }
            } elseif ( isset( $node['items'] ) ) { $prototype = $region['prototype']; $node['items'] = $this->augment_scope( $prototype['native'], $prototype['bindings'] ?? [], $prototype['regions'] ?? [], $node['items'], self::collection_scope( $field_schema, $prototype['native'] ) ); }
            self::schema_put( $schema, $parts, $node, $required );
        }
        return $schema;
    }
    public function region_context( array $items, string $seed, array $region, array $context = [] ) {
        $native = $context['acf_native'] ?? $this->validated_blueprint; $scope = $context['acf_scope'] ?? [];
        foreach ( $this->scope_description( $native, $scope )['containers'] as $container ) { if ( $container['target'] === $region['target'] ) { return [ 'acf_collection' => $container['_field_schema'] ]; } }
        return self::error( 'The selected collection no longer has its exact native field schema.' );
    }
    public function instantiate( array $prototype, string $seed, array $context = [] ) {
        if ( ! isset( $context['acf_collection'] ) ) { return self::error( 'The native ACF collection context is missing.' ); }
        $context['acf_scope'] = self::collection_scope( $context['acf_collection'], $prototype ); $context['acf_native'] = $prototype;
        return [ 'native' => $prototype, 'context' => $context ];
    }
    public function bind( array $native, array $binding, $value, array $context = [] ) {
        if ( ! isset( $native['schema'] ) && ! isset( $context['acf_scope'] ) ) { return self::error( 'The exact native ACF field context is missing.' ); }
        $scope = $context['acf_scope'] ?? []; $checked = $this->validate_binding( $native, $binding, $scope ); if ( is_wp_error( $checked ) ) { return $checked; }
        Nova_Bridge_Suite_Recipe_Engine::put( $native, $binding['target'], $value );
        return $native;
    }
    public function replace_region( array $native, array $region, array $children, array $context = [] ) {
        if ( ! isset( $native['schema'] ) && ! isset( $context['acf_scope'] ) ) { return self::error( 'The exact native ACF collection context is missing.' ); }
        $scope = $context['acf_scope'] ?? [];
        $checked = $this->validate_region( $native, $region, $scope );
        if ( is_wp_error( $checked ) ) { return $checked; }
        Nova_Bridge_Suite_Recipe_Engine::splice( $native, $region['target'], $region['start'], $region['count'], $children );
        return $native;
    }
    /** Enforce declared cardinality/text bounds before native schema transport writes. */
    public static function validate_constraints( array $fields, array $values, string $path = '/document', int $depth = 0 ) {
        if ( $depth > 12 ) { return self::error( 'The installed custom-field structure exceeds its supported nesting depth.', 422 ); }
        foreach ( $fields as $field ) {
            $value = $values[$field['name']] ?? null; $next = $path . '/' . self::escape( $field['name'] ); $type = $field['type'];
            if ( 'group' === $type && is_array( $value ) ) { $checked = self::validate_constraints( $field['sub_fields'] ?? [], $value, $next, $depth + 1 ); if ( is_wp_error( $checked ) ) { return $checked; } }
            elseif ( in_array( $type, [ 'repeater', 'flexible_content' ], true ) && is_array( $value ) ) {
                $minimum = max( (int) ( $field['min'] ?? 0 ), empty( $field['required'] ) ? 0 : 1 ); $maximum = min( 500, (int) ( ( $field['max'] ?? 0 ) ?: 500 ) );
                if ( count( $value ) < $minimum || count( $value ) > $maximum ) { return self::error( 'The collection at ' . $next . ' is outside its installed minimum/maximum row count.', 422 ); }
                foreach ( $value as $index => $row ) {
                    $children = $field['sub_fields'] ?? [];
                    if ( 'flexible_content' === $type ) { foreach ( $field['layouts'] ?? [] as $layout ) { if ( ( $row['acf_fc_layout'] ?? '' ) === $layout['name'] ) { $children = $layout['sub_fields']; break; } } }
                    if ( is_array( $row ) ) { $checked = self::validate_constraints( $children, $row, $next . '/' . $index, $depth + 1 ); if ( is_wp_error( $checked ) ) { return $checked; } }
                }
                if ( 'flexible_content' === $type ) {
                    $counts = array_count_values( array_column( $value, 'acf_fc_layout' ) );
                    foreach ( $field['layouts'] ?? [] as $layout ) {
                        $count = $counts[$layout['name']] ?? 0;
                        if ( ( ! empty( $layout['min'] ) && $count < (int) $layout['min'] ) || ( ! empty( $layout['max'] ) && $count > (int) $layout['max'] ) ) { return self::error( 'The flexible layout ' . $layout['name'] . ' at ' . $next . ' is outside its installed minimum/maximum count.', 422 ); }
                    }
                }
            } elseif ( is_string( $value ) ) {
                if ( ! empty( $field['required'] ) && '' === trim( $value ) ) { return self::error( 'The required field at ' . $next . ' is empty.', 422 ); }
                $length = preg_match_all( '/./us', $value );
                if ( ( isset( $field['min_length'] ) && '' !== $field['min_length'] && $length < (int) $field['min_length'] ) || ( isset( $field['max_length'] ) && '' !== $field['max_length'] && $length > (int) $field['max_length'] ) ) { return self::error( 'The text at ' . $next . ' is outside its installed length bounds.', 422 ); }
            }
        }
        return true;
    }
    public function finalize( array $blueprint, string $seed, array $context = [] ) {
        $validated = $this->validate_blueprint( $blueprint ); if ( is_wp_error( $validated ) ) { return $validated; }
        $payload = [ 'acf' => $blueprint['document'] ];
        $schema = self::catalog( $blueprint['post_type'], 0, $blueprint['template'] ?? '', $blueprint['taxonomy'] ?? '' );
        $bounds = self::validate_constraints( array_values( $schema ), $blueprint['document'] ); if ( is_wp_error( $bounds ) ) { return $bounds; }
        $checked = Nova_Bridge_Suite_Content_Transport::validate_named_acf( $blueprint['document'], $schema ); if ( is_wp_error( $checked ) ) { return $checked; }
        $render = static function ( $value ) use ( &$render ): string { if ( is_array( $value ) ) { $html = '<dl>'; foreach ( $value as $key => $child ) { if ( 'acf_fc_layout' === $key ) { continue; } $html .= '<dt>' . esc_html( is_int( $key ) ? 'Item ' . ( $key + 1 ) : ucfirst( str_replace( '_', ' ', $key ) ) ) . '</dt><dd>' . $render( $child ) . '</dd>'; } return $html . '</dl>'; } return is_string( $value ) ? wp_kses_post( $value ) : esc_html( (string) $value ); };
        return [ 'native_document' => $blueprint['document'], 'writer_payload' => [ 'meta_all' => $payload ], 'preview_html' => $render( $blueprint['document'] ), 'warnings' => [ 'The preview shows stored field values. Your theme controls the visual layout.' ] ];
    }
    public static function write_draft( array $payload, array $post_fields ) {
        if ( ! self::available() ) { return self::error( 'The installed ACF/SCF provider is unavailable.', 503 ); }
        $type = $post_fields['post_type'] ?? 'page'; $object = get_post_type_object( $type );
        if ( ! current_user_can( 'manage_options' ) || ! $object || ! current_user_can( $object->cap->create_posts ?? $object->cap->edit_posts ) || ! current_user_can( $object->cap->edit_posts ) ) { return self::error( 'You cannot create this custom-field draft.', 403 ); }
        $checked = Nova_Bridge_Suite_Content_Transport::validate_payload( $payload['meta_all'] ?? [], $type, 0, $post_fields['template'] ?? '' ); if ( is_wp_error( $checked ) ) { return $checked; }
        $post = [ 'post_type' => $type, 'post_status' => 'draft', 'post_title' => $post_fields['title'] ?? 'NOVA template preview', 'post_content' => '', 'post_author' => get_current_user_id() ];
        if ( ! empty( $post_fields['template'] ) ) { $post['page_template'] = $post_fields['template']; }
        $id = wp_insert_post( wp_slash( $post ), true ); if ( is_wp_error( $id ) ) { return $id; }
        $result = Nova_Bridge_Suite_Content_Transport::write_fields( get_post( $id ), $checked ); if ( is_wp_error( $result ) ) { wp_delete_post( $id, true ); return $result; } return new WP_REST_Response( [ 'id' => $id ], 201 );
    }

    public static function verify_saved( int $post_id, array $input, string $template = '', string $taxonomy = '' ) {
        if ( ! self::available() ) { return self::error( 'The ACF/SCF readback provider is unavailable.', 503 ); }
        if ( ! current_user_can( 'manage_options' ) || ! current_user_can( $taxonomy ? 'edit_term' : 'edit_post', $post_id ) ) { return self::error( 'You cannot inspect this saved custom-field content.', 403 ); }
        $input = $input['meta_all'] ?? $input;
        if ( ! is_array( $input['acf'] ?? null ) ) { return self::error( 'The compiled custom-field payload is incomplete.' ); }
        if ( $taxonomy ) { $object = get_term( $post_id, $taxonomy ); if ( ! $object || is_wp_error( $object ) ) { return self::error( 'The saved term no longer exists.' ); } $schema = self::catalog( '', $post_id, '', $taxonomy ); $object_id = $taxonomy . '_' . $post_id; }
        else { $post = get_post( $post_id ); if ( ! $post ) { return self::error( 'The saved post no longer exists.' ); } $schema = self::catalog( $post->post_type, $post_id, $template ); $object_id = $post_id; }
        if ( ! $schema ) { return self::error( 'The saved destination no longer exposes its declared ACF schema.' ); }
        $bounds = self::validate_constraints( array_values( $schema ), $input['acf'] ); if ( is_wp_error( $bounds ) ) { return $bounds; }
        foreach ( array_keys( $input['acf'] ) as $name ) { foreach ( [ $name, '_' . $name ] as $key ) { if ( ! current_user_can( $taxonomy ? 'edit_term_meta' : 'edit_post_meta', $post_id, $key ) ) { return self::error( 'You cannot inspect this saved custom-field metadata.', 403 ); } } }
        $checked = Nova_Bridge_Suite_Content_Transport::validate_named_acf( $input['acf'], $schema ); if ( is_wp_error( $checked ) ) { return $checked; }
        return Nova_Bridge_Suite_Content_Transport::verify_named_acf( $checked, $object_id );
    }
}
