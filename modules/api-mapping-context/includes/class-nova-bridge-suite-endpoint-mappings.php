<?php
/** Local endpoint creation templates and read-only document inspection. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Endpoint_Mappings {
    private const OPTION = 'nova_bridge_endpoint_mappings_v1';
    private const MAX_STORE_BYTES = 16777216;
    private const MAX_BODY_BYTES = 8388608;
    private const MAX_RESOURCES = 100;
    private const MAX_REVISIONS = 50;
    private static $bootstrapped = false;
    private static $registered = false;

    public static function bootstrap(): void {
        if ( self::$bootstrapped ) { return; }
        self::$bootstrapped = true;
        add_action( 'rest_api_init', [ __CLASS__, 'routes' ], 1004 );
    }

    public static function routes(): void {
        if ( self::$registered ) { return; }
        self::$registered = true;
        foreach ( [
            '/mapping/endpoints' => [ [ 'GET', 'endpoints' ] ],
            '/mapping/creation' => [ [ 'GET', 'creation' ], [ 'POST', 'save_creation' ] ],
            '/mapping/creation/preview' => [ [ 'POST', 'preview_creation' ] ],
            '/mapping/existing' => [ [ 'GET', 'existing' ] ],
            '/mapping/validate' => [ [ 'POST', 'validate' ] ],
        ] as $path => $operations ) {
            $handlers = [];
            foreach ( $operations as $operation ) { $handlers[] = [ 'methods' => $operation[0], 'callback' => [ __CLASS__, $operation[1] ], 'permission_callback' => [ __CLASS__, 'can_admin' ] ]; }
            register_rest_route( 'nova-bridge/v1', $path, $handlers );
        }
    }

    private static function error( string $code, string $message, int $status = 400 ): WP_Error {
        return new WP_Error( 'nova_endpoint_mapping_' . $code, $message, [ 'status' => $status ] );
    }

    /** Standard WordPress REST authentication also permits application passwords. */
    public static function can_admin( $request ) {
        if ( ! current_user_can( 'manage_options' ) ) { return self::error( 'forbidden', 'Administrator access is required.', 403 ); }
        $nonce = $request->get_header( 'x-wp-nonce' );
        if ( null !== $nonce && ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) ) { return self::error( 'nonce', 'The supplied WordPress nonce is invalid.', 403 ); }
        return true;
    }

    private static function response( $data ) {
        if ( is_wp_error( $data ) ) { return $data; }
        if ( is_array( $data ) && is_array( $data['mapping'] ?? null ) && strlen( self::canonical_json( $data['mapping'] ) ) > self::MAX_BODY_BYTES ) { return self::error( 'size', 'The complete document including endpoint instructions exceeds 8 MiB. No partial mapping was returned.', 413 ); }
        $response = rest_ensure_response( $data ); $response->header( 'Cache-Control', 'private, no-store' );
        return $response;
    }

    private static function body( $request ) {
        if ( strlen( (string) $request->get_body() ) > self::MAX_BODY_BYTES ) { return self::error( 'size', 'The mapping request exceeds 8 MiB.', 413 ); }
        $body = $request->get_json_params();
        return is_array( $body ) && ! self::list_array( $body ) ? $body : self::error( 'body', 'Supply a JSON object.' );
    }

    private static function list_array( array $value ): bool { return ! $value || array_keys( $value ) === range( 0, count( $value ) - 1 ); }

    private static function resources() {
        if ( ! class_exists( 'Nova_Bridge_Suite_Document_Mapping' ) ) { return self::error( 'adapter', 'The document mapping adapter is unavailable.', 503 ); }
        return Nova_Bridge_Suite_Document_Mapping::resources();
    }

    private static function resource( $id ) {
        if ( ! is_string( $id ) || ! preg_match( '/^(?:post_type|taxonomy):[a-z0-9_-]{1,32}$/D', $id ) ) { return self::error( 'resource', 'Choose a registered content endpoint.' ); }
        $resources = self::resources(); if ( is_wp_error( $resources ) ) { return $resources; }
        foreach ( $resources as $resource ) { if ( is_array( $resource ) && ( $resource['id'] ?? '' ) === $id ) { return $resource; } }
        return self::error( 'resource_not_found', 'That content endpoint is unavailable.', 404 );
    }

    private static function reference_id( $id ) {
        if ( is_int( $id ) && $id >= 0 && $id <= 9007199254740991 ) { return $id; }
        if ( is_string( $id ) && preg_match( '/^(?:0|[1-9][0-9]{0,15})$/D', $id ) && (int) $id <= 9007199254740991 ) { return (int) $id; }
        return self::error( 'reference', 'Use a nonnegative integer reference ID.' );
    }

    private static function can_target( string $type, int $id ) {
        if ( ! in_array( $type, [ 'post', 'term' ], true ) ) { return self::error( 'reference', 'Choose a post or term reference.' ); }
        if ( $id && ! current_user_can( 'post' === $type ? 'edit_post' : 'edit_term', $id ) ) { return self::error( 'target_forbidden', 'This reference cannot be edited by your account.', 403 ); }
        return true;
    }

    private static function can_meta( array $target, string $path ) {
        if ( ! preg_match( '~^/meta/([^/]+)(?:/|$)~', $path, $match ) || empty( $target['reference_id'] ) ) { return true; }
        $key = str_replace( [ '~1', '~0' ], [ '/', '~' ], $match[1] );
        if ( ! current_user_can( 'term' === ( $target['reference_type'] ?? '' ) ? 'edit_term_meta' : 'edit_post_meta', $target['reference_id'], $key ) ) { return self::error( 'meta_forbidden', 'A selected metadata field cannot be edited by your account.', 403 ); }
        return true;
    }

    private static function read( array $resource, string $type, int $id ) {
        $expected = 'taxonomy' === ( $resource['type'] ?? '' ) ? 'term' : 'post';
        if ( $type !== $expected ) { return self::error( 'reference', 'The reference type does not match the selected endpoint.' ); }
        $permission = self::can_target( $type, $id ); if ( is_wp_error( $permission ) ) { return $permission; }
        return $id ? Nova_Bridge_Suite_Document_Mapping::read( $resource['id'], $type, $id ) : Nova_Bridge_Suite_Document_Mapping::empty( $resource['id'] );
    }

    private static function canonical_json( array $value ): string {
        $sort = static function ( $item ) use ( &$sort ) {
            if ( is_array( $item ) ) { if ( $item && ! self::list_array( $item ) ) { ksort( $item, SORT_STRING ); } foreach ( $item as $key => $child ) { $item[$key] = $sort( $child ); } }
            return $item;
        };
        $json = wp_json_encode( $sort( $value ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $json ) ) { throw new InvalidArgumentException( 'The document cannot be encoded.' ); }
        return $json;
    }

    public static function document_version( array $mapping ): string {
        unset( $mapping['preview_url'] );
        return hash( 'sha256', self::canonical_json( $mapping ) );
    }

    private static function preview_url( string $type, int $id, array $resource ): string {
        if ( ! $id || ! class_exists( 'Nova_Bridge_Suite_Mapping_Preview' ) ) { return ''; }
        if ( 'term' === $type && ( $resource['taxonomy'] ?? '' ) !== 'product_cat' ) { return ''; }
        $url = 'post' === $type ? get_permalink( $id ) : get_term_link( $id, $resource['taxonomy'] );
        return ! is_wp_error( $url ) && is_string( $url ) ? Nova_Bridge_Suite_Mapping_Preview::url( [ 'reference_type' => $type, 'reference_id' => $id, 'url' => $url ] ) : '';
    }

    private static function state( $stored, bool $missing = false ) {
        if ( $missing ) { return [ 'schema_version' => 1, 'defaults' => [], 'revisions' => [] ]; }
        if ( ! is_array( $stored ) || ( $stored['schema_version'] ?? null ) !== 1 || ! is_array( $stored['defaults'] ?? null ) || ! is_array( $stored['revisions'] ?? null ) ) { return self::error( 'storage', 'The local endpoint mapping store is invalid. Reconcile it before saving.', 409 ); }
        foreach ( $stored['defaults'] as $resource => $identity ) {
            $record = is_array( $identity ) ? ( $stored['revisions'][ $identity['id'] ?? '' ][ $identity['revision'] ?? 0 ] ?? null ) : null;
            if ( ! is_array( $record ) || ! is_string( $identity['id'] ?? null ) || ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD', $identity['id'] ) || ! is_int( $identity['revision'] ?? null ) || $identity['revision'] < 1 || ( $record['schema_version'] ?? null ) !== 1 || ( $record['resource_id'] ?? '' ) !== $resource || ( $record['mapping_id'] ?? '' ) !== $identity['id'] || ( $record['revision'] ?? null ) !== $identity['revision'] || ! is_array( $record['mapping'] ?? null ) || ( $record['mapping']['mode'] ?? '' ) !== 'creation' || ( $record['mapping']['resource_id'] ?? '' ) !== $resource || ( $record['mapping']['target']['reference_id'] ?? null ) !== 0 || ! is_array( $record['reference'] ?? null ) || ! in_array( $record['reference']['reference_type'] ?? null, [ 'post', 'term' ], true ) || ! is_int( $record['reference']['reference_id'] ?? null ) || $record['reference']['reference_id'] < 0 || ! is_array( $record['selected_fields'] ?? null ) || ! self::list_array( $record['selected_fields'] ) || ! is_string( $record['label'] ?? null ) || ! is_string( $record['instructions'] ?? null ) ) { return self::error( 'storage', 'A retained endpoint mapping identity is invalid.', 409 ); }
        }
        return $stored;
    }

    private static function load() { $missing = new stdClass(); $stored = get_option( self::OPTION, $missing ); return self::state( $stored, $stored === $missing ); }

    private static function record( array $state, string $resource_id ): ?array {
        $identity = $state['defaults'][$resource_id] ?? null;
        return $identity ? $state['revisions'][$identity['id']][$identity['revision']] : null;
    }

    private static function enrich( array $mapping, ?array $record ): array {
        if ( ! $record ) { return $mapping; }
        $mapping['instructions'] = $record['instructions'];
        foreach ( (array) $record['field_descriptions'] as $path => $description ) {
            if ( ! isset( $mapping['fields'][$path] ) ) { continue; }
            $notes = array_filter( [ $description['purpose'] ?? '', $description['instructions'] ?? '' ], static function ( $value ) { return '' !== $value; } );
            $mapping['fields'][$path]['instructions'] = implode( "\n", $notes );
        }
        return $mapping;
    }

    private static function live_source( array $record ) {
        $resource = self::resource( $record['resource_id'] ); if ( is_wp_error( $resource ) ) { return $resource; }
        $reference = $record['reference'];
        $fresh = self::read( $resource, $reference['reference_type'], $reference['reference_id'] ); if ( is_wp_error( $fresh ) ) { return $fresh; }
        foreach ( $record['selected_fields'] as $path ) { $permission = self::can_meta( $fresh['target'] ?? [], $path ); if ( is_wp_error( $permission ) ) { return $permission; } }
        if ( ! is_string( $record['source_evidence']['document_version'] ?? null ) || ! hash_equals( $record['source_evidence']['document_version'], self::document_version( $fresh ) ) ) { return self::error( 'needs_refresh', 'The captured source or endpoint schema changed. Review and save a refreshed creation template.', 409 ); }
        return $fresh;
    }

    private static function check_values( array $mapping, array $expected, ?array $selected = null ) {
        $submitted = $mapping; $baseline = $expected;
        if ( ! is_array( $mapping['fields'] ?? null ) || array_diff( array_keys( $mapping['fields'] ), array_keys( $expected['fields'] ) ) || array_diff( array_keys( $expected['fields'] ), array_keys( $mapping['fields'] ) ) ) { return self::error( 'fields', 'Retain every field and its exported identity.' ); }
        foreach ( $mapping['fields'] as $path => $field ) {
            if ( ! is_array( $field ) || ! array_key_exists( 'value', $field ) ) { return self::error( 'field', 'Retain each complete exported field.' ); }
            $original = $expected['fields'][$path];
            if ( $field['value'] !== $original['value'] && ( empty( $original['writable'] ) || ! empty( $original['protected'] ) || ( null !== $selected && ! in_array( $path, $selected, true ) ) ) ) { return self::error( 'protected', 'Only the designated writable values can change.' ); }
            unset( $submitted['fields'][$path]['value'], $baseline['fields'][$path]['value'] );
        }
        if ( self::canonical_json( $submitted ) !== self::canonical_json( $baseline ) ) { return self::error( 'immutable', 'Instructions, target identities, source values, field metadata and native structure are read-only after export.' ); }
        return true;
    }

    /** Server evidence is deliberately never included in REST documents or catalog metadata. */
    private static function public_record( ?array $record, string $resource_id ): array {
        if ( ! $record ) { return [ 'resource_id' => $resource_id, 'mapping_id' => null, 'revision' => 0, 'schema_version' => 1, 'document_version' => null, 'reference' => null, 'selected_fields' => [], 'field_descriptions' => (object) [], 'label' => '', 'instructions' => '', 'mapping' => null, 'saved' => false ]; }
        return array_intersect_key( $record, array_flip( [ 'resource_id', 'mapping_id', 'revision', 'schema_version', 'document_version', 'source_document_version', 'reference', 'reference_type', 'reference_id', 'selected_fields', 'field_descriptions', 'label', 'instructions', 'mapping', 'saved', 'local_only', 'native_write_supported' ] ) );
    }

    public static function endpoints( $request ) {
        $permission = self::can_admin( $request ); if ( is_wp_error( $permission ) ) { return $permission; }
        $resources = self::resources(); if ( is_wp_error( $resources ) ) { return $resources; }
        $state = self::load(); if ( is_wp_error( $state ) ) { return $state; }
        $result = []; $configured = 0;
        foreach ( $resources as $resource ) {
            if ( ! is_array( $resource ) || ! is_string( $resource['id'] ?? null ) ) { continue; }
            $item = array_intersect_key( $resource, array_flip( [ 'id', 'resource_id', 'label', 'type', 'post_type', 'taxonomy', 'route', 'methods', 'availability', 'targets_complete', 'creation_supported', 'can_inspect_existing', 'reasons', 'capabilities', 'native_write_supported' ] ) );
            $item['endpoint'] = $resource['route'] ?? ''; $item['targets'] = [];
            $item['targets_complete'] = ( $resource['targets_complete'] ?? true ) && count( $resource['targets'] ?? [] ) <= 100;
            foreach ( array_slice( $resource['targets'] ?? [], 0, 100 ) as $target ) {
                if ( ! is_array( $target ) || ! in_array( $target['reference_type'] ?? '', [ 'post', 'term' ], true ) || ! is_int( $target['reference_id'] ?? null ) || $target['reference_id'] < 1 || is_wp_error( self::can_target( $target['reference_type'], $target['reference_id'] ) ) ) { continue; }
                $item['targets'][] = array_intersect_key( $target, array_flip( [ 'reference_type', 'reference_id', 'title', 'status', 'path' ] ) );
            }
            $item['creation_supported'] = $resource['creation_supported'] ?? true;
            $item['can_inspect_existing'] = $resource['can_inspect_existing'] ?? true;
            $item['native_write_supported'] = false;
            $item['reasons'] = $resource['reasons'] ?? [];
            $record = self::record( $state, $resource['id'] );
            $item['default_mapping'] = $record ? [ 'id' => $record['mapping_id'], 'revision' => $record['revision'], 'label' => $record['label'] ] : null;
            $item['coverage'] = [ 'configured' => !! $record, 'selected_fields' => $record ? count( $record['selected_fields'] ) : 0 ];
            if ( $record ) { ++$configured; } $result[] = $item;
        }
        return self::response( [ 'resources' => $result, 'coverage' => [ 'total' => count( $result ), 'configured' => $configured, 'unconfigured' => count( $result ) - $configured ], 'local_only' => true, 'native_write_supported' => false ] );
    }

    public static function creation( $request ) {
        $permission = self::can_admin( $request ); if ( is_wp_error( $permission ) ) { return $permission; }
        $resource = self::resource( $request->get_param( 'resource_id' ) ); if ( is_wp_error( $resource ) ) { return $resource; }
        $state = self::load(); if ( is_wp_error( $state ) ) { return $state; }
        $record = self::record( $state, $resource['id'] );
        if ( $record && ! empty( $record['reference']['reference_id'] ) ) { $permission = self::can_target( $record['reference']['reference_type'], $record['reference']['reference_id'] ); if ( is_wp_error( $permission ) ) { return $permission; } }
        $public = self::public_record( $record, $resource['id'] );
        if ( $record ) { $live = self::live_source( $record ); $public['needs_refresh'] = is_wp_error( $live ); }
        return self::response( $public );
    }

    public static function existing( $request ) {
        $permission = self::can_admin( $request ); if ( is_wp_error( $permission ) ) { return $permission; }
        $resource = self::resource( $request->get_param( 'resource_id' ) ); if ( is_wp_error( $resource ) ) { return $resource; }
        $type = $request->get_param( 'reference_type' ); $id = self::reference_id( $request->get_param( 'reference_id' ) );
        if ( ! is_string( $type ) || is_wp_error( $id ) ) { return is_wp_error( $id ) ? $id : self::error( 'reference', 'Supply the reference type.' ); }
        $mapping = self::read( $resource, $type, $id ); if ( is_wp_error( $mapping ) ) { return $mapping; }
        $source_version = self::document_version( $mapping );
        $state = self::load(); if ( is_wp_error( $state ) ) { return $state; }
        if ( $id ) { $mapping = self::enrich( $mapping, self::record( $state, $resource['id'] ) ); }
        $preview = false === ( $mapping['provider']['preview_read_only'] ?? true ) ? '' : self::preview_url( $type, $id, $resource );
        return self::response( [ 'resource_id' => $resource['id'], 'schema_version' => 1, 'document_version' => self::document_version( $mapping ), 'source_document_version' => $source_version, 'preview_url' => $preview, 'mapping' => $mapping, 'local_only' => true, 'native_write_supported' => false ] );
    }

    private static function capture( array $body ) {
        if ( array_diff( array_keys( $body ), [ 'resource_id', 'reference_type', 'reference_id', 'selected_fields', 'field_descriptions', 'instructions', 'label', 'expected_revision', 'source_document_version' ] ) ) { return self::error( 'request', 'The creation request contains unsupported properties.' ); }
        $resource = self::resource( $body['resource_id'] ?? null ); if ( is_wp_error( $resource ) ) { return $resource; }
        if ( false === ( $resource['creation_supported'] ?? true ) ) { return self::error( 'creation_unsupported', 'This endpoint does not support a local creation mapping.', 409 ); }
        $type = $body['reference_type'] ?? null; $id = self::reference_id( $body['reference_id'] ?? null );
        if ( ! is_string( $type ) || is_wp_error( $id ) ) { return is_wp_error( $id ) ? $id : self::error( 'reference', 'Supply the reference type.' ); }
        $filled = self::read( $resource, $type, $id ); if ( is_wp_error( $filled ) ) { return $filled; }
        $version = $body['source_document_version'] ?? null;
        if ( ! is_string( $version ) || ! preg_match( '/^[a-f0-9]{64}$/D', $version ) ) { return self::error( 'source_version', 'Inspect the reference and submit its source document version.' ); }
        if ( ! hash_equals( self::document_version( $filled ), $version ) ) { return self::error( 'source_changed', 'The reference changed after inspection. Reload it before saving.', 409 ); }
        $selected = $body['selected_fields'] ?? null;
        if ( ! is_array( $selected ) || ! self::list_array( $selected ) || ! $selected || count( $selected ) > 2000 ) { return self::error( 'fields', 'Select one to 2000 complete writable fields.' ); }
        $seen = [];
        foreach ( $selected as $path ) {
            $field = is_string( $path ) ? ( $filled['fields'][$path] ?? null ) : null;
            if ( ! is_string( $path ) || strlen( $path ) > 1024 || isset( $seen[$path] ) || ! is_array( $field ) || empty( $field['writable'] ) || ! empty( $field['protected'] ) ) { return self::error( 'field', 'Choose each complete writable field once; protected fields cannot be blanked.' ); }
            $permission = self::can_meta( $filled['target'] ?? [], $path ); if ( is_wp_error( $permission ) ) { return $permission; } $seen[$path] = true;
        }
        $instructions = $body['instructions'] ?? ''; $label = $body['label'] ?? ( $resource['label'] . ' creation layout' );
        if ( ! self::text( $instructions, 8000 ) || ! self::text( $label, 160 ) || '' === trim( $label ) || preg_match( '/[<>\r\n]/', $label ) ) { return self::error( 'text', 'Use a plain layout name and UTF-8 instructions within their length limits.' ); }
        $descriptions = $body['field_descriptions'] ?? [];
        if ( ! is_array( $descriptions ) || ( $descriptions && self::list_array( $descriptions ) ) ) { return self::error( 'descriptions', 'Supply descriptions keyed by selected field path.' ); }
        foreach ( $descriptions as $path => $description ) {
            if ( ! isset( $seen[$path] ) || ! is_array( $description ) || array_diff( array_keys( $description ), [ 'purpose', 'instructions' ] ) || ! self::text( $description['purpose'] ?? '', 2000 ) || ! self::text( $description['instructions'] ?? '', 8000 ) ) { return self::error( 'description', 'Use bounded descriptions for selected fields only.' ); }
            if ( strlen( implode( "\n", array_filter( [ $description['purpose'] ?? '', $description['instructions'] ?? '' ], static function ( $value ) { return '' !== $value; } ) ) ) > 8000 ) { return self::error( 'description', 'A field purpose and its instructions together must fit within 8000 UTF-8 bytes.' ); }
        }
        $blank = Nova_Bridge_Suite_Document_Mapping::blank( $filled, $selected, $instructions ); if ( is_wp_error( $blank ) ) { return $blank; }
        $blank = self::enrich( $blank, [ 'instructions' => $instructions, 'field_descriptions' => $descriptions ] );
        if ( strlen( self::canonical_json( $blank ) ) > self::MAX_BODY_BYTES ) { return self::error( 'size', 'The complete creation template including field guidance exceeds 8 MiB. Nothing was saved.', 413 ); }
        $evidence = [ 'document_version' => self::document_version( $filled ), 'baseline' => $filled['baseline'], 'target' => $filled['target'], 'provider' => $filled['provider'] ];
        return [ 'resource_id' => $resource['id'], 'mapping_id' => null, 'revision' => 0, 'schema_version' => 1, 'document_version' => self::document_version( $blank ), 'source_document_version' => $version, 'reference' => [ 'reference_type' => $type, 'reference_id' => $id ], 'reference_type' => $type, 'reference_id' => $id, 'selected_fields' => $selected, 'field_descriptions' => $descriptions ?: (object) [], 'label' => $label, 'instructions' => $instructions, 'mapping' => $blank, 'source_evidence' => $evidence, 'saved' => false, 'local_only' => true, 'native_write_supported' => false ];
    }

    private static function text( $value, int $maximum ): bool { return is_string( $value ) && strlen( $value ) <= $maximum && 1 === preg_match( '//u', $value ) && ! preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value ); }

    public static function preview_creation( $request ) {
        $permission = self::can_admin( $request ); if ( is_wp_error( $permission ) ) { return $permission; }
        $body = self::body( $request ); if ( is_wp_error( $body ) ) { return $body; }
        $record = self::capture( $body ); if ( is_wp_error( $record ) ) { return $record; }
        return self::response( self::public_record( $record, $record['resource_id'] ) );
    }

    public static function save_creation( $request ) {
        $permission = self::can_admin( $request ); if ( is_wp_error( $permission ) ) { return $permission; }
        $body = self::body( $request ); if ( is_wp_error( $body ) ) { return $body; }
        if ( ! is_int( $body['expected_revision'] ?? null ) || $body['expected_revision'] < 0 ) { return self::error( 'revision', 'Submit the reviewed integer default revision, starting at zero.' ); }
        $record = self::capture( $body ); if ( is_wp_error( $record ) ) { return $record; }
        return self::response( self::save( $record, $body['expected_revision'] ) );
    }

    private static function save( array $record, int $expected_revision ) {
        global $wpdb;
        if ( ! isset( $wpdb->options ) || ! preg_match( '/^[A-Za-z0-9_]+$/D', $wpdb->options ) ) { return self::error( 'storage', 'Atomic WordPress option storage is unavailable.', 409 ); }
        $new_id = wp_generate_uuid4();
        for ( $attempt = 0; $attempt < 3; ++$attempt ) {
            wp_cache_delete( self::OPTION, 'options' ); wp_cache_delete( 'alloptions', 'options' );
            $missing = new stdClass(); $stored = get_option( self::OPTION, $missing ); $state = self::state( $stored, $stored === $missing ); if ( is_wp_error( $state ) ) { return $state; }
            $current = self::record( $state, $record['resource_id'] ); $latest = $current['revision'] ?? 0;
            if ( $latest !== $expected_revision ) { return self::error( 'conflict', 'This endpoint default changed. Reload its latest revision before saving.', 409 ); }
            $id = $current['mapping_id'] ?? $new_id; $revisions = $state['revisions'][$id] ?? [];
            if ( ( ! $current && count( $state['defaults'] ) >= self::MAX_RESOURCES ) || count( $revisions ) >= self::MAX_REVISIONS ) { return self::error( 'history_limit', 'The immutable endpoint mapping history limit was reached. No revision was removed.', 409 ); }
            $saved = $record; $saved['mapping_id'] = $id; $saved['revision'] = $latest + 1; $saved['saved'] = true;
            $next = $state; $next['revisions'][$id][$saved['revision']] = $saved; $next['defaults'][$record['resource_id']] = [ 'id' => $id, 'revision' => $saved['revision'] ];
            $raw = maybe_serialize( $next ); if ( strlen( $raw ) > self::MAX_STORE_BYTES ) { return self::error( 'storage_size', 'The endpoint mapping store has reached 16 MiB. No history was removed.', 409 ); }
            if ( $stored === $missing ) { $updated = add_option( self::OPTION, $next, '', false ); }
            else {
                $updated = $wpdb->query( $wpdb->prepare( 'UPDATE `' . $wpdb->options . '` SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s', $raw, self::OPTION, maybe_serialize( $stored ) ) );
                if ( false === $updated ) { return self::error( 'storage', 'The endpoint mapping default could not be saved.', 500 ); } $updated = 1 === $updated;
            }
            wp_cache_delete( self::OPTION, 'options' ); wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' );
            if ( $updated ) { return self::public_record( $saved, $record['resource_id'] ); }
        }
        return self::error( 'conflict', 'Another administrator changed the local endpoint mappings. Reload before saving.', 409 );
    }

    public static function validate( $request ) {
        $permission = self::can_admin( $request ); if ( is_wp_error( $permission ) ) { return $permission; }
        $body = self::body( $request ); if ( is_wp_error( $body ) ) { return $body; }
        if ( array_diff( array_keys( $body ), [ 'mapping', 'mapping_id', 'revision' ] ) || ! is_array( $body['mapping'] ?? null ) || ! in_array( $body['mapping']['mode'] ?? '', [ 'existing', 'creation' ], true ) ) { return self::error( 'mapping', 'Supply a complete document mapping for round-trip validation.' ); }
        $mapping = $body['mapping']; $resource = self::resource( $mapping['resource_id'] ?? null ); if ( is_wp_error( $resource ) ) { return $resource; }
        $state = self::load(); if ( is_wp_error( $state ) ) { return $state; }
        $record = self::record( $state, $resource['id'] );
        if ( 'creation' === $mapping['mode'] ) {
            if ( ! $record || ! is_string( $body['mapping_id'] ?? null ) || ! is_int( $body['revision'] ?? null ) || $body['mapping_id'] !== $record['mapping_id'] || $body['revision'] !== $record['revision'] ) { return self::error( 'needs_refresh', 'Select the current exact creation mapping identity and revision.', 409 ); }
            $source = self::live_source( $record ); if ( is_wp_error( $source ) ) { return $source; }
            $checked = self::check_values( $mapping, $record['mapping'], $record['selected_fields'] ); if ( is_wp_error( $checked ) ) { return $checked; }
            $native = Nova_Bridge_Suite_Document_Mapping::blank( $source, $record['selected_fields'] ); if ( is_wp_error( $native ) ) { return $native; }
            foreach ( $mapping['fields'] as $path => $field ) { $native['fields'][$path]['value'] = $field['value']; }
            $checked = Nova_Bridge_Suite_Document_Mapping::validate( $native ); if ( is_wp_error( $checked ) ) { return $checked; }
            return self::response( [ 'valid' => true, 'mapping' => self::enrich( $checked, $record ), 'mapping_id' => $record['mapping_id'], 'revision' => $record['revision'], 'document_version' => $record['document_version'], 'needs_refresh' => false, 'local_only' => true, 'native_write_supported' => false ] );
        }
        $target = $mapping['target'] ?? []; $type = $target['reference_type'] ?? null; $id = self::reference_id( $target['reference_id'] ?? null );
        if ( ! is_string( $type ) || is_wp_error( $id ) || ! $id ) { return self::error( 'reference', 'An existing mapping requires its actual reference.' ); }
        $fresh = self::read( $resource, $type, $id ); if ( is_wp_error( $fresh ) ) { return $fresh; }
        if ( ( $mapping['baseline'] ?? null ) !== ( $fresh['baseline'] ?? null ) ) { return self::error( 'needs_refresh', 'The existing item changed. Read its current full document before validating.', 409 ); }
        $expected = self::enrich( $fresh, $record );
        $checked = self::check_values( $mapping, $expected ); if ( is_wp_error( $checked ) ) { return $checked; }
        foreach ( $mapping['fields'] ?? [] as $path => $field ) {
            if ( is_string( $path ) && is_array( $field ) && array_key_exists( 'value', $field ) && ( $fresh['fields'][$path]['value'] ?? null ) !== $field['value'] ) { $permission = self::can_meta( $target, $path ); if ( is_wp_error( $permission ) ) { return $permission; } }
        }
        $native = $fresh;
        foreach ( $mapping['fields'] as $path => $field ) { $native['fields'][$path]['value'] = $field['value']; }
        $checked = Nova_Bridge_Suite_Document_Mapping::validate( $native ); if ( is_wp_error( $checked ) ) { return $checked; }
        return self::response( [ 'valid' => true, 'mapping' => self::enrich( $checked, $record ), 'document_version' => self::document_version( $expected ), 'local_only' => true, 'native_write_supported' => false ] );
    }
}
