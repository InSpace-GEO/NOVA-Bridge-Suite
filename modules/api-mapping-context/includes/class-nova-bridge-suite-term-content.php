<?php
/** Authenticated existing-term editorial transport. This API never creates taxonomy items. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Term_Content {
    private const STORE = 'nova_bridge_layout_recipes_v1';
    private const MAX_BYTES = 2097152;
    private static $bootstrapped = false;
    public static function bootstrap(): void {
        if ( self::$bootstrapped ) { return; } self::$bootstrapped = true;
        add_action( 'rest_api_init', [ __CLASS__, 'routes' ], 1006 );
    }
    public static function routes(): void {
        register_rest_route( 'nova-bridge/v1', '/term-content/(?P<taxonomy>[a-zA-Z0-9_-]+)/(?P<id>[\d]+)', [
            [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'get' ], 'permission_callback' => [ __CLASS__, 'can_access' ] ],
            [ 'methods' => 'POST, PUT, PATCH', 'callback' => [ __CLASS__, 'update' ], 'permission_callback' => [ __CLASS__, 'can_access' ] ],
        ] );
    }
    private static function error( string $message, int $status = 422 ): WP_Error { return new WP_Error( 'nova_term_content', $message, [ 'status' => $status ] ); }
    private static function id( $value ): int { return is_int( $value ) && $value > 0 ? $value : ( is_string( $value ) && preg_match( '/^[1-9][0-9]*$/D', $value ) && (string) (int) $value === $value ? (int) $value : 0 ); }
    private static function resource( string $taxonomy, int $id ) {
        if ( ! preg_match( '/^[a-zA-Z0-9_-]{1,32}$/D', $taxonomy ) || $id < 1 ) { return self::error( 'Choose an existing category or editorial taxonomy item.', 404 ); }
        $type = get_taxonomy( $taxonomy );
        if ( ! $type || empty( $type->show_ui ) || ( empty( $type->public ) && empty( $type->publicly_queryable ) ) ) { return self::error( 'This taxonomy is not a public editorial destination.', 404 ); }
        if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_term', $id ) ) { return self::error( 'Administrator and category editing access are required.', 403 ); }
        $term = get_term( $id, $taxonomy ); if ( ! $term || is_wp_error( $term ) || $term->taxonomy !== $taxonomy ) { return self::error( 'The category item was not found in this taxonomy.', 404 ); }
        return [ 'id' => 'taxonomy:' . $taxonomy, 'type' => 'taxonomy', 'taxonomy' => $taxonomy ];
    }
    public static function can_access( $request ) {
        $nonce = $request->get_header( 'x-wp-nonce' ); if ( null !== $nonce && ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) ) { return self::error( 'Reload WordPress to renew your session.', 403 ); }
        $resource = self::resource( (string) $request->get_param( 'taxonomy' ), self::id( $request->get_param( 'id' ) ) ); return is_wp_error( $resource ) ? $resource : true;
    }
    private static function response( $result ) { if ( is_wp_error( $result ) ) { return $result; } $response = rest_ensure_response( $result ); $response->header( 'Cache-Control', 'private, no-store' ); return $response; }
    private static function context( $request ): array { return [ 'profile_id' => $request->get_param( 'profile_id' ), 'revision' => self::id( $request->get_param( 'revision' ) ) ]; }
    public static function get( $request ) { return self::response( self::read( (string) $request->get_param( 'taxonomy' ), self::id( $request->get_param( 'id' ) ), self::context( $request ) ) ); }
    public static function update( $request ) {
        $raw = (string) $request->get_body(); if ( strlen( $raw ) > self::MAX_BYTES ) { return self::error( 'The term content request exceeds 2 MiB.', 413 ); }
        $body = $request->get_json_params(); if ( ! is_array( $body ) || ( $body && Nova_Bridge_Suite_Recipe_Engine::list_array( $body ) ) ) { return self::error( 'Supply an editorial field object.' ); }
        $context = [ 'profile_id' => $body['profile_id'] ?? null, 'revision' => $body['revision'] ?? 0 ]; unset( $body['profile_id'], $body['revision'] );
        return self::response( self::apply( (string) $request->get_param( 'taxonomy' ), self::id( $request->get_param( 'id' ) ), $body, $context ) );
    }
    /** Only immutable locally saved and separately sealed schemas can authorise legacy fields. */
    private static function profile( array $resource, array $context ) {
        $id = $context['profile_id'] ?? null; $revision = $context['revision'] ?? 0;
        if ( null === $id && ! $revision ) { return null; }
        if ( ! is_string( $id ) || ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD', $id ) || ! is_int( $revision ) || $revision < 1 ) { return self::error( 'Supply an exact saved mapping profile and revision.', 409 ); }
        $state = get_option( self::STORE, [] ); $record = is_array( $state ) ? ( $state['profiles'][$id][$revision] ?? null ) : null;
        if ( ! is_array( $state ) || ( $state['format'] ?? '' ) !== 'nova-layout-recipes-store/v1' || ! is_array( $record ) || ( $record['id'] ?? '' ) !== $id || ( $record['revision'] ?? 0 ) !== $revision || ( $record['resource_id'] ?? '' ) !== $resource['id'] || ! in_array( $record['builder'] ?? '', [ 'core', 'acf' ], true ) || ! is_array( $record['recipe']['blueprint'] ?? null ) ) { return self::error( 'That saved mapping revision does not belong to this category destination.', 409 ); }
        $recipe = $record['recipe']; $builder = $recipe['builder'] ?? ''; $blueprint = $recipe['blueprint'];
        if ( $builder !== $record['builder'] || ( $blueprint['taxonomy'] ?? '' ) !== $resource['taxonomy'] || ( 'core' === $builder && ( $blueprint['resource_id'] ?? '' ) !== $resource['id'] ) || ! is_string( $record['seal'] ?? null ) || ! hash_equals( hash_hmac( 'sha256', Nova_Bridge_Suite_Recipe_Engine::json( [ $builder, $blueprint ] ), wp_salt( 'auth' ) ), $record['seal'] ) ) { return self::error( 'The saved category mapping seal or destination could not be verified.', 409 ); }
        $valid = Nova_Bridge_Suite_Recipe_Engine::validate( $recipe ); if ( is_wp_error( $valid ) ) { return $valid; }
        if ( isset( $recipe['custom_fields'] ) ) {
            $custom = $recipe['custom_fields'];
            if ( 'acf' !== ( $custom['builder'] ?? '' ) || ( $custom['blueprint']['taxonomy'] ?? '' ) !== $resource['taxonomy'] || ! is_string( $record['acf_seal'] ?? null ) || ! hash_equals( hash_hmac( 'sha256', Nova_Bridge_Suite_Recipe_Engine::json( [ 'acf', $custom['blueprint'] ] ), wp_salt( 'auth' ) ), $record['acf_seal'] ) ) { return self::error( 'The saved category custom-field companion seal could not be verified.', 409 ); }
            $valid = Nova_Bridge_Suite_Recipe_Engine::validate( $custom ); if ( is_wp_error( $valid ) ) { return $valid; }
        }
        return $record;
    }
    private static function capture( array $resource, int $id, array $context ) {
        $profile = self::profile( $resource, $context ); if ( is_wp_error( $profile ) ) { return $profile; }
        $local = $profile['recipe']['blueprint']['term_fields']['local_declarations'] ?? [];
        if ( $local ) { if ( ! method_exists( 'Nova_Bridge_Suite_Recipe_Term_Fields', 'capture_saved_profile' ) ) { return self::error( 'The approved custom-field authority is unavailable.', 503 ); } return Nova_Bridge_Suite_Recipe_Term_Fields::capture_saved_profile( $resource, $id, $profile ); }
        return Nova_Bridge_Suite_Recipe_Term_Fields::capture( $resource, $id );
    }
    private static function values( array $bundle ) {
        $out = []; foreach ( $bundle['declarations'] as $stable => $definition ) { if ( ! array_key_exists( $stable, $bundle['original_values'] ) ) { continue; } $value = Nova_Bridge_Suite_Recipe_Term_Fields::encode_writer_value( $bundle['original_values'][$stable], $definition ); if ( is_wp_error( $value ) ) { return $value; } if ( 'rest' === $definition['transport'] ) { $out[$definition['rest_key']] = $value; } else { $out[$definition['transport']][$definition['rest_key']] = $value; } } return $out;
    }
    private static function acf_schema( string $taxonomy, int $id ) { return Nova_Bridge_Suite_Content_Transport::acf_schema_for_screen( [ 'taxonomy' => $taxonomy, 'post_id' => $taxonomy . '_' . $id ] ); }
    private static function acf_caps( int $id, array $names ) {
        foreach ( $names as $name ) { foreach ( [ $name, '_' . $name ] as $key ) { if ( ! current_user_can( 'edit_term_meta', $id, $key ) ) { return self::error( 'You cannot access this category custom-field metadata.', 403 ); } } } return true;
    }
    public static function read( string $taxonomy, int $id, array $context = [] ) {
        $resource = self::resource( $taxonomy, $id ); if ( is_wp_error( $resource ) ) { return $resource; }
        $capture = self::capture( $resource, $id, $context ); if ( is_wp_error( $capture ) ) { return $capture; }
        $values = self::values( $capture['term_fields'] ); if ( is_wp_error( $values ) ) { return $values; }
        $term = get_term( $id, $taxonomy ); $out = [ 'id' => $id, 'taxonomy' => $taxonomy, 'name' => $term->name, 'description' => $term->description ] + $values;
        if ( Nova_Bridge_Suite_Recipe_ACF::available() ) { $schema = self::acf_schema( $taxonomy, $id ); $caps = self::acf_caps( $id, array_keys( $schema ) ); if ( is_wp_error( $caps ) ) { return $caps; } if ( $schema ) { $out['meta_all']['acf'] = Nova_Bridge_Suite_Content_Transport::read_named_acf( $schema, $taxonomy . '_' . $id ); } }
        $out['excluded_fields'] = $capture['excluded_fields']; return $out;
    }
    private static function preflight( array $resource, int $id, array $payload, array $context ) {
        $raw = Nova_Bridge_Suite_Recipe_Engine::json( $payload ); if ( strlen( $raw ) > self::MAX_BYTES ) { return self::error( 'The term content request exceeds 2 MiB.', 413 ); }
        $capture = self::capture( $resource, $id, $context ); if ( is_wp_error( $capture ) ) { return $capture; } $native = [ 'term_fields' => $capture['term_fields'] ];
        foreach ( [ 'meta', 'meta_all' ] as $transport ) { if ( array_key_exists( $transport, $payload ) && ( ! is_array( $payload[$transport] ) || ( $payload[$transport] && Nova_Bridge_Suite_Recipe_Engine::list_array( $payload[$transport] ) ) ) ) { return self::error( 'Custom metadata must be a field-name object.' ); } }
        $declarations = Nova_Bridge_Suite_Recipe_Term_Fields::declarations_for_payload( $native, $payload ); if ( is_wp_error( $declarations ) ) { return $declarations; }
        $top = [ 'name' => true, 'description' => true, 'meta' => true, 'meta_all' => true ]; $roots = [ 'meta' => [], 'meta_all' => [ 'acf' => true ] ]; $targets = []; $callbacks = [];
        foreach ( $declarations as $stable => $definition ) {
            $allowed = method_exists( 'Nova_Bridge_Suite_Recipe_Term_Fields', 'authorize' ) ? Nova_Bridge_Suite_Recipe_Term_Fields::authorize( $definition, $resource['taxonomy'], $id ) : current_user_can( 'edit_term_meta', $id, $definition['key'] );
            if ( is_wp_error( $allowed ) ) { return $allowed; } if ( true !== $allowed ) { return self::error( 'You cannot edit this category custom field.', 403 ); }
            if ( 'rest' === $definition['transport'] ) { $top[$definition['rest_key']] = true; $value = $payload[$definition['rest_key']]; $callbacks[$definition['key']] = $GLOBALS['wp_rest_additional_fields'][$resource['taxonomy']][$definition['key']]['update_callback']; } else { $roots[$definition['transport']][$definition['rest_key']] = true; $value = $payload[$definition['transport']][$definition['rest_key']]; }
            $value = Nova_Bridge_Suite_Recipe_Term_Fields::decode_writer_value( $value, $definition ); if ( is_wp_error( $value ) ) { return $value; }
            $native['term_fields']['values'][$stable] = $value; $targets[] = '/term_fields/values/' . $stable;
        }
        foreach ( $payload as $key => $value ) { if ( ! isset( $top[$key] ) ) { return self::error( 'Unknown or read-only category field: ' . $key ); } }
        foreach ( [ 'meta', 'meta_all' ] as $transport ) { foreach ( $payload[$transport] ?? [] as $key => $value ) { if ( ! isset( $roots[$transport][$key] ) ) { return self::error( 'Unknown or unapproved category metadata: ' . $key ); } } }
        $plan = []; $native_input = [];
        foreach ( [ 'name', 'description' ] as $key ) {
            if ( ! array_key_exists( $key, $payload ) ) { continue; } $value = $payload[$key];
            if ( ! is_string( $value ) || false !== strpos( $value, "\0" ) || 1 !== preg_match( '//u', $value ) || ( 'name' === $key && '' === trim( $value ) ) ) { return self::error( 'Supply a valid text value for the category ' . $key . '.' ); }
            $native_input[$key] = 'name' === $key ? sanitize_text_field( $value ) : wp_kses_post( $value );
            // wp_update_term filters slashed args in db context, then unslashes for storage.
            // Keep its input separate so native filters never receive their own filtered output.
            $expected = sanitize_term_field( $key, wp_slash( $native_input[$key] ), $id, $resource['taxonomy'], 'db' ); if ( is_wp_error( $expected ) ) { return $expected; }
            if ( ! is_string( $expected ) ) { return self::error( 'The installed category field sanitizer returned an unsupported value.', 409 ); }
            $plan[$key] = wp_unslash( $expected );
            if ( 'name' === $key && '' === trim( $plan[$key] ) ) { return self::error( 'The category name must contain text.' ); }
        }
        $compiled = Nova_Bridge_Suite_Recipe_Term_Fields::finalize( $native, [ 'term_fields' => $capture['term_fields'] ], $targets ); if ( is_wp_error( $compiled ) ) { return $compiled; } $plan += $compiled['writer_payload'];
        $acf = [];
        if ( array_key_exists( 'acf', $payload['meta_all'] ?? [] ) ) {
            if ( ! Nova_Bridge_Suite_Recipe_ACF::available() ) { return self::error( 'The installed ACF/SCF provider is unavailable.', 503 ); }
            $input = $payload['meta_all']['acf']; if ( ! is_array( $input ) || ( $input && Nova_Bridge_Suite_Recipe_Engine::list_array( $input ) ) ) { return self::error( 'ACF values must use declared field names and complete parent objects.' ); }
            $schema = self::acf_schema( $resource['taxonomy'], $id ); $caps = self::acf_caps( $id, array_keys( $input ) ); if ( is_wp_error( $caps ) ) { return $caps; }
            $selected_schema = array_intersect_key( $schema, $input );
            $bounds = Nova_Bridge_Suite_Recipe_ACF::validate_constraints( $selected_schema, $input, '/meta_all/acf' ); if ( is_wp_error( $bounds ) ) { return $bounds; }
            $acf = Nova_Bridge_Suite_Content_Transport::validate_named_acf( $input, $schema ); if ( is_wp_error( $acf ) ) { return $acf; }
        }
        return [ 'payload' => $plan, 'native_input' => $native_input, 'declarations' => $declarations, 'callbacks' => $callbacks, 'acf' => $acf ];
    }
    private static function uncertain( int $id, array $applied, string $field, bool $restored = false ): WP_Error {
        return new WP_Error( 'nova_term_content_partial', 'A native field setter or readback failed. Some category changes may already be saved; inspect the item before retrying.', [ 'status' => 500, 'term_id' => $id, 'write_status' => 'uncertain', 'applied_fields' => $applied, 'failed_field' => $field, 'failed_field_restored' => $restored, 'review_required' => true ] );
    }
    private static function replace_multi( int $id, string $key, array $values ): bool {
        try { delete_term_meta( $id, $key ); if ( [] !== get_term_meta( $id, $key, false ) ) { return false; }
            foreach ( $values as $value ) { $result = add_term_meta( $id, $key, wp_slash( $value ) ); if ( is_wp_error( $result ) || false === $result ) { return false; } } return true;
        } catch ( Throwable $error ) { return false; }
    }
    /** All validation precedes native setters. Plugin callbacks do not offer a transaction. */
    public static function apply( string $taxonomy, int $id, array $payload, array $profileContext = [] ) {
        $resource = self::resource( $taxonomy, $id ); if ( is_wp_error( $resource ) ) { return $resource; }
        try { $plan = self::preflight( $resource, $id, $payload, $profileContext ); } catch ( Throwable $error ) { return self::error( 'The native field definitions or validators could not prepare this update.', 409 ); }
        if ( is_wp_error( $plan ) ) { return $plan; } $applied = []; $field = 'native content';
        try {
            $native = $plan['native_input'];
            if ( $native ) { $result = wp_update_term( $id, $taxonomy, wp_slash( $native ) ); if ( is_wp_error( $result ) || false === $result ) { return self::uncertain( $id, $applied, $field ); } $applied = array_keys( $native ); }
            foreach ( $plan['declarations'] as $definition ) {
                $field = $definition['key']; $value = 'rest' === $definition['transport'] ? $plan['payload'][$definition['rest_key']] : $plan['payload'][$definition['transport']][$definition['rest_key']];
                $allowed = method_exists( 'Nova_Bridge_Suite_Recipe_Term_Fields', 'authorize' ) ? Nova_Bridge_Suite_Recipe_Term_Fields::authorize( $definition, $taxonomy, $id ) : current_user_can( 'edit_term_meta', $id, $field ); if ( true !== $allowed ) { return self::uncertain( $id, $applied, $field ); }
                if ( 'rest' === $definition['transport'] ) { $request = new WP_REST_Request( 'PATCH' ); $request->set_param( 'context', 'edit' ); $result = call_user_func( $plan['callbacks'][$field], $value, get_term( $id, $taxonomy ), $field, $request, $taxonomy ); if ( is_wp_error( $result ) ) { return self::uncertain( $id, $applied, $field ); } }
                elseif ( $definition['single'] ) { $result = update_term_meta( $id, $field, wp_slash( $value ) ); if ( is_wp_error( $result ) ) { return self::uncertain( $id, $applied, $field ); } }
                else { $before = get_term_meta( $id, $field, false ); if ( ! self::replace_multi( $id, $field, $value ) ) { $restored = self::replace_multi( $id, $field, $before ) && $before === get_term_meta( $id, $field, false ); return self::uncertain( $id, $applied, $field, $restored ); } }
                $applied[] = $field;
            }
            foreach ( $plan['acf'] as $name => $entry ) { $field = 'acf.' . $name; $caps = self::acf_caps( $id, [ $name ] ); if ( is_wp_error( $caps ) ) { return self::uncertain( $id, $applied, $field ); } update_field( $entry['field']['key'], $entry['value'], $taxonomy . '_' . $id ); $applied[] = $field; }
            if ( $plan['acf'] ) { $check = Nova_Bridge_Suite_Content_Transport::verify_named_acf( $plan['acf'], $taxonomy . '_' . $id ); if ( is_wp_error( $check ) ) { return self::uncertain( $id, $applied, $field ); } }
            $readback = self::read( $taxonomy, $id, $profileContext ); if ( is_wp_error( $readback ) ) { return self::uncertain( $id, $applied, 'readback' ); }
            foreach ( $plan['payload'] as $key => $value ) { if ( ! array_key_exists( $key, $readback ) ) { return self::uncertain( $id, $applied, $key ); } if ( in_array( $key, [ 'meta', 'meta_all' ], true ) ) { foreach ( $value as $name => $expected ) { if ( ! array_key_exists( $name, $readback[$key] ) || Nova_Bridge_Suite_Recipe_Engine::digest( $readback[$key][$name] ) !== Nova_Bridge_Suite_Recipe_Engine::digest( $expected ) ) { return self::uncertain( $id, $applied, $name ); } } } elseif ( $readback[$key] !== $value ) { return self::uncertain( $id, $applied, $key ); } }
            return [ 'id' => $id, 'taxonomy' => $taxonomy, 'updated_fields' => $applied, 'content' => $readback ];
        } catch ( Throwable $error ) { return self::uncertain( $id, $applied, $field ); }
    }
}
