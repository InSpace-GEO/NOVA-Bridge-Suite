<?php
/** Exact local mapping authority and journaled native mapped-content delivery. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Mapped_Content_Writer {
    public const CONTRACT = 'nova-mapped-layout/v1';
    private const PREFIX = 'nova_mapped_content_';
    private static function error( string $code, string $message, int $status = 409 ): WP_Error { return new WP_Error( 'nova_mapped_' . $code, $message, [ 'status' => $status, 'error_code' => $code ] ); }
    private static function digest( $value ): string { return Nova_Bridge_Suite_Recipe_Engine::digest( $value ); }
    /** Shared wire identity only; historical native compiler hashes stay unchanged. */
    public static function wire_digest( $value ): string { return hash( 'sha256', Nova_Bridge_Suite_Receipt_Json::encode( $value ) ); }
    private static function uuid( $value ): bool { return is_string( $value ) && Nova_Bridge_Suite_Posting_Client::uuid( $value ); }
    private static function key( string $kind, string $site, string $id ): string { return self::PREFIX . $kind . '_' . hash( 'sha256', $site . ':' . $id ); }
    private static function retain( string $key, array $value ) {
        if ( ! add_option( $key, $value, '', false ) && self::digest( get_option( $key, [] ) ) !== self::digest( $value ) ) { return self::error( 'identity_conflict', 'This immutable local identity already has different content.' ); }
        return $value;
    }
    private static function checkpoint( string $key, array $value ) {
        update_option( $key, $value, false ); return get_option( $key, null ) === $value ? $value : self::error( 'journal', 'The mapped delivery checkpoint could not be retained.', 500 );
    }
    /** Upload only writer instructions and schema. Native scaffolds and seals remain local. */
    public static function export_default( string $site, string $resource ) {
        if ( ! current_user_can( 'manage_options' ) || ! self::uuid( $site ) ) { return self::error( 'permission', 'A registered site and administrator access are required.', 403 ); }
        $request = new WP_REST_Request( 'GET' ); $request->set_param( 'resource_id', $resource );
        $reply = Nova_Bridge_Suite_Layout_Recipes::blank( $request ); if ( is_wp_error( $reply ) ) { return $reply; } $blank = $reply->get_data();
        $record = Nova_Bridge_Suite_Layout_Recipes::delivery_record( $blank['id'], $blank['revision'] ); if ( is_wp_error( $record ) ) { return $record; }
        $ref = [ 'id' => $record['id'], 'revision' => $record['revision'], 'resource_id' => $resource, 'digest' => self::digest( $record['recipe'] ) ];
        $context = [];
        $walk = static function ( array $scope ) use ( &$walk, &$context ) { foreach ( $scope['bindings'] ?? [] as $binding ) { $context[] = array_intersect_key( $binding, array_flip( [ 'source', 'label', 'instructions', 'format', 'required' ] ) ); } foreach ( $scope['regions'] ?? [] as $region ) { $context[] = array_intersect_key( $region, array_flip( [ 'source', 'label', 'kind', 'empty', 'max_items' ] ) ); $walk( $region['prototype'] ); foreach ( $region['variants'] ?? [] as $variant ) { $walk( $variant ); } } if ( isset( $scope['custom_fields'] ) ) { $walk( $scope['custom_fields'] ); } };
        $walk( $record['recipe'] );
        $schema = self::closed_schema( $blank['content_schema'] ); $schema['x-nova-field-context'] = $context;
        $export = [ 'format' => self::CONTRACT, 'mapping_id' => $ref['id'], 'revision' => $ref['revision'], 'site_id' => $site, 'resource_id' => $resource, 'operation' => 'create', 'schema' => $schema, 'content' => $blank['empty_content'], 'instructions' => $blank['instructions'], 'local_reference' => [ 'kind' => 'recipe', 'id' => $ref['id'], 'revision' => $ref['revision'] ], 'target' => null, 'baseline' => null ];
        $saved = self::retain( self::key( 'mapping', $site, $ref['id'] . ':' . $ref['revision'] ), [ 'reference' => $ref, 'export' => $export ] ); return is_wp_error( $saved ) ? $saved : $export;
    }
    private static function closed_schema( array $schema ): array {
        if ( 'object' === ( $schema['type'] ?? '' ) || isset( $schema['properties'] ) ) { $schema['additionalProperties'] = false; }
        foreach ( $schema['properties'] ?? [] as $key => $child ) { $schema['properties'][$key] = self::closed_schema( $child ); }
        if ( is_array( $schema['items'] ?? null ) ) { $schema['items'] = self::closed_schema( $schema['items'] ); } return $schema;
    }
    /** Native declared types remain authoritative when an unfilled provider returns a sentinel. */
    private static function scalar_schema( array $field ): ?array {
        $native = (string) ( $field['type'] ?? '' ); $value = $field['value'];
        if ( in_array( $native, [ 'text', 'string', 'rich_text', 'textarea', 'wysiwyg', 'url', 'email' ], true ) ) { $type = 'string'; }
        elseif ( in_array( $native, [ 'integer', 'int' ], true ) ) { $type = 'integer'; }
        elseif ( in_array( $native, [ 'number', 'float' ], true ) ) { $type = 'number'; }
        elseif ( in_array( $native, [ 'boolean', 'true_false' ], true ) ) { $type = 'boolean'; }
        else { return null; }
        if ( is_string( $value ) && '' !== $value && in_array( $type, [ 'integer', 'number' ], true ) ) {
            if ( ! preg_match( 'integer' === $type ? '/^-?(?:0|[1-9][0-9]*)$/D' : '/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:e[+-]?[0-9]+)?$/iD', $value ) || abs( (float) $value ) > 9007199254740991 || ! is_finite( (float) $value ) ) { return null; }
            $value = 'integer' === $type ? (int) $value : (float) $value;
        } elseif ( 'boolean' === $type && in_array( $value, [ '0', '1', 0, 1 ], true ) ) { $value = (bool) $value; }
        $valid = 'string' === $type ? is_string( $value ) : ( 'integer' === $type ? is_int( $value ) : ( 'number' === $type ? ( is_int( $value ) || is_float( $value ) ) : is_bool( $value ) ) );
        if ( ! $valid && ! in_array( $value, [ null, false, '' ], true ) ) { return null; }
        $schema = [ 'type' => $type ]; $sentinel = [];
        if ( null === $value ) { $sentinel[] = [ 'type' => 'null' ]; }
        elseif ( false === $value && 'boolean' !== $type ) { $sentinel[] = [ 'type' => 'boolean', 'const' => false ]; }
        elseif ( '' === $value && 'string' !== $type ) { $sentinel[] = [ 'type' => 'string', 'const' => '' ]; }
        $schema = $sentinel ? [ 'anyOf' => array_merge( [ $schema ], $sentinel ) ] : $schema;
        $schema['description'] = $field['label']; $schema['x-native-formats'] = [ in_array( $native, [ 'rich_text', 'wysiwyg' ], true ) ? 'html' : ( 'url' === $native ? 'url' : 'text' ) ];
        return [ 'schema' => $schema, 'value' => $value ];
    }
    /** A fresh filled item-specific snapshot; creation defaults never determine rewrite fields. */
    public static function capture_snapshot( string $site, string $id, string $resource, array $target, ?array $editable_paths = null ) {
        if ( ! self::uuid( $site ) || ! self::uuid( $id ) || ! is_int( $target['reference_id'] ?? null ) || ( $target['reference_type'] ?? '' ) !== 'post' ) { return self::error( 'snapshot_target', 'Supply an exact existing post reference and snapshot request identity.', 400 ); }
        $key = self::key( 'snapshot', $site, $id ); $old = get_option( $key, null );
        if ( is_array( $old ) ) { return $old['resource_id'] === $resource && $old['requested_target'] === $target && $old['requested_paths'] === $editable_paths ? $old['export'] : self::error( 'snapshot_conflict', 'This snapshot request already belongs to another native item.' ); }
        $mapping = Nova_Bridge_Suite_Document_Mapping::read( $resource, 'post', $target['reference_id'] ); if ( is_wp_error( $mapping ) ) { return $mapping; }
        if ( ! in_array( $mapping['provider']['id'], [ 'core', 'elementor' ], true ) ) { return self::error( 'rewrite_provider', 'This complete snapshot provider has no certified surgical delivery writer yet.' ); }
        $editable = []; $protected = []; $properties = []; $context = [];
        foreach ( $mapping['fields'] as $path => $field ) {
            if ( ! empty( $field['protected'] ) || empty( $field['writable'] ) || is_array( $field['value'] ) ) { $protected[] = $path; continue; }
            if ( null !== $editable_paths && ! in_array( $path, $editable_paths, true ) ) { $protected[] = $path; continue; }
            $schema = self::scalar_schema( $field ); if ( null === $schema ) { $protected[] = $path; continue; }
            $editable[$path] = $schema['value']; $properties[$path] = $schema['schema'];
            $context[] = [ 'source' => '/fields/' . str_replace( [ '~', '/' ], [ '~0', '~1' ], $path ), 'label' => $field['label'], 'format' => $properties[$path]['x-native-formats'][0], 'required' => false ];
        }
        if ( null !== $editable_paths && ( ! Nova_Bridge_Suite_Recipe_Engine::list_array( $editable_paths ) || array_diff( $editable_paths, array_keys( $editable ) ) || count( array_unique( $editable_paths, SORT_REGULAR ) ) !== count( $editable_paths ) ) ) { return self::error( 'snapshot_paths', 'Requested paths must be unique approved native editorial fields.', 422 ); }
        if ( ! $editable ) { return self::error( 'snapshot_empty', 'This item exposes no supported editable editorial fields.' ); }
        $ref = [ 'id' => $id, 'baseline_digest' => self::digest( $mapping['baseline'] ) ];
        $wire_target = $target + [ 'url' => $mapping['preview_url'] ];
        $export = [ 'format' => self::CONTRACT, 'mapping_id' => $id, 'revision' => 1, 'site_id' => $site, 'resource_id' => $resource, 'operation' => 'rewrite', 'schema' => [ 'type' => 'object', 'additionalProperties' => false, 'properties' => $properties ], 'content' => $editable, 'instructions' => 'Rewrite only supplied editable fields. Omitted fields stay unchanged. Preserve native structure, URLs, design settings and protected fields.', 'local_reference' => [ 'kind' => 'document', 'id' => $id ], 'target' => $wire_target, 'baseline' => $mapping['baseline'] ];
        $saved = self::retain( $key, [ 'resource_id' => $resource, 'requested_target' => $target, 'requested_paths' => $editable_paths, 'target' => $wire_target, 'mapping' => $mapping, 'editable' => array_keys( $editable ), 'reference' => $ref, 'export' => $export ] ); return is_wp_error( $saved ) ? $saved : $export;
    }
    private static function lock( string $key ) {
        global $wpdb;
        if ( ! isset( $wpdb ) || '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', 'nova_mapped_' . substr( hash( 'sha256', $key ), 0, 48 ) ) ) ) { return self::error( 'busy', 'Another mapped delivery is running; retry the same operation.', 409 ); } return true;
    }
    private static function unlock( string $key ): void { global $wpdb; $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', 'nova_mapped_' . substr( hash( 'sha256', $key ), 0, 48 ) ) ); }
    private static function create_plan( string $site, array $delivery ) {
        $ref = $delivery['mapping_ref'] ?? []; $key = self::key( 'mapping', $site, ( $ref['id'] ?? '' ) . ':' . ( $ref['revision'] ?? 0 ) ); $authority = get_option( $key, null );
        if ( ! is_array( $authority ) || $authority['reference'] !== $ref || 'taxonomy:product_cat' !== ( $ref['resource_id'] ?? '' ) || ( $delivery['resource_id'] ?? '' ) !== $ref['resource_id'] ) { return self::error( 'mapping_authority', 'This exact category mapping revision was not synchronized by this site.' ); }
        $current = Nova_Bridge_Suite_Layout_Recipes::defaults()[$ref['resource_id']] ?? []; if ( ( $current['id'] ?? '' ) !== $ref['id'] || ( $current['revision'] ?? 0 ) !== $ref['revision'] ) { return self::error( 'stale_recipe', 'The destination default changed after content was prepared. Refresh its mapping.' ); }
        $target = $delivery['target'] ?? []; $slug = $target['slug'] ?? '';
        if ( ! is_string( $slug ) || '' === $slug || strlen( $slug ) > 180 || sanitize_title( $slug ) !== $slug || ! is_int( $target['parent'] ?? null ) || $target['parent'] < 0 || array_diff( array_keys( $target ), [ 'slug', 'parent' ] ) ) { return self::error( 'create_target', 'Supply a canonical category slug and parent.', 400 ); }
        $taxonomy = get_taxonomy( 'product_cat' );
        if ( ! $taxonomy || ! current_user_can( $taxonomy->cap->manage_terms ) || ! current_user_can( $taxonomy->cap->edit_terms ) || ! current_user_can( 'manage_options' ) ) { return self::error( 'permission', 'The configured WordPress actor cannot create product categories.', 403 ); }
        if ( $target['parent'] && ( ! get_term( $target['parent'], 'product_cat' ) || ! current_user_can( 'edit_term', $target['parent'] ) ) ) { return self::error( 'parent', 'The configured product category parent is unavailable.', 403 ); }
        if ( get_term_by( 'slug', $slug, 'product_cat' ) ) { return self::error( 'slug_exists', 'The target category slug is already in use. No category was created.' ); }
        $compiled = Nova_Bridge_Suite_Layout_Recipes::delivery_compile( $ref['id'], $ref['revision'], $delivery['content'], $delivery['operation_id'] ); if ( is_wp_error( $compiled ) ) { return $compiled; }
        $payload = $compiled['writer_payload']; if ( ! is_string( $payload['name'] ?? null ) || '' === trim( $payload['name'] ) ) { return self::error( 'category_name', 'The category mapping must supply a nonempty category name.', 422 ); }
        return [ 'mode' => 'create', 'taxonomy' => 'product_cat', 'target' => $target, 'payload' => $payload, 'profile_context' => [ 'profile_id' => $ref['id'], 'revision' => $ref['revision'] ] ];
    }
    private static function rewrite_plan( string $site, array $delivery ) {
        $ref = $delivery['snapshot_ref'] ?? []; $saved = get_option( self::key( 'snapshot', $site, $ref['id'] ?? '' ), null );
        if ( ! is_array( $saved ) || $saved['reference'] !== $ref || $saved['resource_id'] !== ( $delivery['resource_id'] ?? '' ) || ! is_array( $delivery['target'] ?? null ) || self::wire_digest( $saved['target'] ) !== self::wire_digest( $delivery['target'] ) ) { return self::error( 'snapshot_authority', 'The delivery does not match this site’s retained item snapshot.' ); }
        $mapping = $saved['mapping']; $body = $delivery['content'];
        if ( ! $body || Nova_Bridge_Suite_Recipe_Engine::list_array( $body ) || array_diff( array_keys( $body ), $saved['editable'] ) ) { return self::error( 'protected_field', 'Supply only approved, concrete editable snapshot paths.', 422 ); }
        $after = $mapping['structure']; $fields = []; $native = []; $meta = []; $acf = []; $changed_paths = [];
        foreach ( $body as $path => $value ) {
            $mapping['fields'][$path]['value'] = $value; $field = $mapping['fields'][$path];
            if ( self::digest( $value ) === self::digest( $field['current_value'] ) ) { continue; }
            $changed_paths[] = $path;
            if ( 'rich_text' === $field['type'] && ( ! is_string( $value ) || wp_kses_post( $value ) !== $value ) ) { return self::error( 'html_policy', 'The supplied rich text contains markup outside WordPress editorial policy.', 422 ); }
            Nova_Bridge_Suite_Recipe_Engine::put( $after, $field['structure_path'], $value );
            $parts = Nova_Bridge_Suite_Recipe_Engine::parts( $path );
            if ( 'elementor' === ( $field['builder'] ?? '' ) ) { $fields[] = $field['selector_data'] + [ 'value' => $value ]; }
            elseif ( 'core' === $parts[0] && in_array( $parts[1], [ 'title', 'content', 'excerpt' ], true ) ) { $native[ 'post_' . $parts[1] ] = $value; }
            elseif ( 'meta' === $parts[0] ) { $meta[$parts[1]] = $after['meta'][$parts[1]]; }
            elseif ( 'acf' === $parts[0] ) { $acf[$parts[1]] = $after['acf'][$parts[1]]; }
            else { return self::error( 'rewrite_path', 'This editable snapshot path has no certified native writer.' ); }
        }
        $valid = Nova_Bridge_Suite_Document_Mapping::validate( $mapping ); if ( is_wp_error( $valid ) ) { return $valid; }
        $metadata = $meta; if ( $acf ) { $metadata['acf'] = $acf; }
        $transport = $metadata ? Nova_Bridge_Suite_Content_Transport::validate_payload( $metadata, $saved['target']['post_type'] ?? substr( $saved['resource_id'], 10 ), $saved['target']['reference_id'], $mapping['structure']['core']['template'] ) : null;
        if ( is_wp_error( $transport ) ) { return $transport; }
        return [ 'mode' => 'rewrite', 'target' => $saved['target'], 'resource_id' => $saved['resource_id'], 'baseline' => $mapping['baseline'], 'before_structure' => $mapping['structure'], 'after_structure' => $after, 'elementor_fields' => $fields, 'native_fields' => $native, 'metadata' => $transport, 'changed_paths' => $changed_paths ];
    }
    private static function verify_rewrite( array $plan ) {
        $now = Nova_Bridge_Suite_Document_Mapping::read( $plan['resource_id'], 'post', $plan['target']['reference_id'] ); if ( is_wp_error( $now ) ) { return $now; }
        $actual = $now['structure']; $expected = $plan['after_structure'];
        // Registered metadata can persist typed values as strings. Compare declared
        // scalar semantics only at changed metadata leaves, retaining every other byte.
        foreach ( $plan['changed_paths'] as $path ) { if ( 0 !== strpos( $path, '/meta/' ) && 0 !== strpos( $path, '/acf/' ) ) { continue; } $field = $now['fields'][$path] ?? null; $scalar = is_array( $field ) ? self::scalar_schema( $field ) : null; if ( null !== $scalar ) { Nova_Bridge_Suite_Recipe_Engine::put( $actual, $path, $scalar['value'] ); } }
        // Elementor maintains a derived text cache. Its native document remains authoritative.
        if ( isset( $actual['elementor_document'] ) ) { $actual['core']['content'] = ''; $expected['core']['content'] = ''; }
        return self::digest( $actual ) === self::digest( $expected ) ? $now : self::error( 'native_readback', 'The native item differs from the surgical plan; inspect it before retrying.', 409 );
    }
    private static function verify_category( array $plan, int $id, string $operation, string $digest ) {
        $term = get_term( $id, $plan['taxonomy'] );
        if ( ! $term || is_wp_error( $term ) || $term->slug !== $plan['target']['slug'] || (int) $term->parent !== $plan['target']['parent'] || get_term_meta( $id, '_nova_mapped_operation', true ) !== $operation || get_term_meta( $id, '_nova_mapped_operation_digest', true ) !== $digest || ! current_user_can( 'edit_term', $id ) ) { return self::error( 'category_witness', 'The retained category no longer matches its original delivery witness.' ); }
        $read = Nova_Bridge_Suite_Term_Content::read( $plan['taxonomy'], $id, $plan['profile_context'] ); if ( is_wp_error( $read ) ) { return $read; }
        foreach ( $plan['payload'] as $key => $value ) {
            if ( ! array_key_exists( $key, $read ) ) { return self::error( 'category_readback', 'A retained category field was not saved.' ); }
            if ( in_array( $key, [ 'name', 'description' ], true ) ) {
                $input = 'name' === $key ? sanitize_text_field( $value ) : wp_kses_post( $value );
                $expected = sanitize_term_field( $key, wp_slash( $input ), $id, $plan['taxonomy'], 'db' );
                if ( is_wp_error( $expected ) || ! is_string( $expected ) ) { return self::error( 'category_readback', 'The native category sanitizer no longer provides a verifiable expected value.' ); }
                $value = wp_unslash( $expected );
            }
            if ( in_array( $key, [ 'meta', 'meta_all' ], true ) ) { foreach ( $value as $name => $expected ) { if ( ! array_key_exists( $name, $read[$key] ) || self::digest( $read[$key][$name] ) !== self::digest( $expected ) ) { return self::error( 'category_readback', 'A retained custom category field differs from its intended content.' ); } } }
            elseif ( self::digest( $value ) !== self::digest( $read[$key] ) ) { return self::error( 'category_readback', 'A retained category field differs from its intended content.' ); }
        }
        return true;
    }
    private static function receipt( array $delivery, array $plan, int $id ): array {
        $term = 'create' === $plan['mode']; $url = $term ? get_term_link( $id, $plan['taxonomy'] ) : get_permalink( $id );
        return [ 'status' => 'applied', 'remote_id' => (string) $id, 'remote_url' => is_string( $url ) ? $url : '', 'changed_paths' => $plan['changed_paths'] ?? array_keys( $plan['payload'] ) ];
    }
    public static function has_native_attempt( string $site, string $id ): bool { return is_array( get_option( self::key( 'operation', $site, $id ), null ) ); }
    /** Journal first; retained uncertain saves never blindly repeat a native mutation. */
    public static function deliver( string $site, array $delivery ) {
        $wire = $delivery; $mapping = $wire['mapping'] ?? null;
        if ( ! is_array( $mapping ) || ( $mapping['format'] ?? '' ) !== self::CONTRACT || ( $mapping['site_id'] ?? '' ) !== $site || ! self::uuid( $wire['delivery_id'] ?? null ) || ! is_string( $wire['mapping_digest'] ?? null ) || ! hash_equals( self::wire_digest( $mapping ), $wire['mapping_digest'] ) || ( $wire['mapping_id'] ?? '' ) !== ( $mapping['mapping_id'] ?? '' ) || ( $wire['mapping_revision'] ?? 0 ) !== ( $mapping['revision'] ?? 0 ) ) { return self::error( 'delivery_identity', 'The delivery does not contain one exact site-scoped frozen mapping.', 403 ); }
        $kind = 'create' === ( $mapping['operation'] ?? '' ) ? 'mapping' : 'snapshot'; $authority = get_option( self::key( $kind, $site, $mapping['mapping_id'] . ( 'mapping' === $kind ? ':' . $mapping['revision'] : '' ) ), null );
        if ( ! is_array( $authority ) || self::wire_digest( $authority['export'] ) !== self::wire_digest( $mapping ) ) { return self::error( 'local_authority', 'The frozen mapping does not match the site’s retained native authority.' ); }
        if ( ! is_array( $wire['content'] ?? null ) ) { return self::error( 'content_shape', 'Mapped values must be a content object.', 422 ); }
        $schema_content = 'rewrite' === $mapping['operation'] ? array_replace( $mapping['content'], $wire['content'] ) : $wire['content'];
        $schema_check = rest_validate_value_from_schema( $schema_content, $mapping['schema'], 'mapped_content' ); if ( is_wp_error( $schema_check ) ) { return $schema_check; }
        $publication = $wire['publication'] ?? [];
        if ( 'create' === $mapping['operation'] && ( $publication['status'] ?? 'publish' ) !== 'publish' ) { return self::error( 'category_status', 'Categories have no draft status. Explicitly publish the controlled category test.', 422 ); }
        if ( 'rewrite' === $mapping['operation'] && $publication ) { return self::error( 'rewrite_routing', 'An existing-item rewrite cannot change publication or routing identity.', 422 ); }
        $delivery = [ 'contract' => self::CONTRACT, 'site_id' => $site, 'operation_id' => $wire['delivery_id'], 'mode' => $mapping['operation'], 'resource_id' => $mapping['resource_id'], 'content' => $wire['content'] ?? null, 'target' => 'create' === $mapping['operation'] ? [ 'slug' => $publication['slug'] ?? '', 'parent' => $publication['parent'] ?? 0 ] : $mapping['target'], 'wire_digest' => self::digest( array_intersect_key( $wire, array_flip( [ 'delivery_id', 'mapping_id', 'mapping_revision', 'mapping_digest', 'content', 'publication' ] ) ) ) ];
        $delivery[ 'create' === $mapping['operation'] ? 'mapping_ref' : 'snapshot_ref' ] = $authority['reference'];
        if ( ( $delivery['contract'] ?? '' ) !== self::CONTRACT || ( $delivery['site_id'] ?? '' ) !== $site || ! self::uuid( $delivery['operation_id'] ?? null ) || ! in_array( $delivery['mode'] ?? '', [ 'create', 'rewrite' ], true ) || ! is_array( $delivery['content'] ?? null ) || ! current_user_can( 'manage_options' ) ) { return self::error( 'delivery', 'Supply an authenticated site-scoped mapped-content delivery.', 403 ); }
        $key = self::key( 'operation', $site, $delivery['operation_id'] ); $lock = self::lock( $key ); if ( is_wp_error( $lock ) ) { return $lock; }
        $target_key = self::key( 'target', $site, $delivery['resource_id'] . ':' . ( 'create' === $delivery['mode'] ? ( $delivery['target']['slug'] ?? '' ) : (string) ( $delivery['target']['reference_id'] ?? 0 ) ) );
        $target_lock = self::lock( $target_key ); if ( is_wp_error( $target_lock ) ) { self::unlock( $key ); return $target_lock; }
        try {
            $digest = self::digest( $delivery ); $old = get_option( $key, null );
            if ( is_array( $old ) ) {
                if ( ( $old['delivery_digest'] ?? '' ) !== $digest ) { return self::error( 'operation_conflict', 'This operation already belongs to different content. No duplicate was created.' ); }
                if ( 'complete' === $old['phase'] ) { return $old['receipt']; }
                if ( 'rewrite' === $old['plan']['mode'] && ! is_wp_error( self::verify_rewrite( $old['plan'] ) ) ) { $old['receipt'] = self::receipt( $delivery, $old['plan'], $old['plan']['target']['reference_id'] ); $old['phase'] = 'complete'; $saved = self::checkpoint( $key, $old ); return is_wp_error( $saved ) ? $saved : $old['receipt']; }
                if ( 'create' === $old['plan']['mode'] && ! empty( $old['native_id'] ) && ! is_wp_error( self::verify_category( $old['plan'], (int) $old['native_id'], $delivery['operation_id'], $digest ) ) ) { $old['receipt'] = self::receipt( $delivery, $old['plan'], (int) $old['native_id'] ); $old['phase'] = 'complete'; $saved = self::checkpoint( $key, $old ); return is_wp_error( $saved ) ? $saved : $old['receipt']; }
                return self::error( 'uncertain', 'A previous native attempt requires inspection. It was not overwritten or duplicated.' );
            }
            $plan = 'create' === $delivery['mode'] ? self::create_plan( $site, $delivery ) : self::rewrite_plan( $site, $delivery ); if ( is_wp_error( $plan ) ) { return $plan; }
            $journal = [ 'delivery_digest' => $digest, 'phase' => 'writing', 'plan' => $plan, 'created_at' => gmdate( 'c' ) ];
            $retained = self::retain( $key, $journal ); if ( is_wp_error( $retained ) ) { return $retained; }
            if ( 'create' === $plan['mode'] ) {
                $witness = static function ( $id, $tt, $taxonomy ) use ( $plan, $delivery, $digest, $key, &$journal ) {
                    if ( $taxonomy !== $plan['taxonomy'] ) { return; } $term = get_term( $id, $taxonomy ); if ( ! $term || $term->slug !== $plan['target']['slug'] ) { return; }
                    update_term_meta( $id, '_nova_mapped_operation', $delivery['operation_id'] ); update_term_meta( $id, '_nova_mapped_operation_digest', $digest ); $journal['native_id'] = (int) $id; self::checkpoint( $key, $journal );
                };
                add_action( 'created_term', $witness, 1, 3 );
                try { $created = wp_insert_term( sanitize_text_field( $plan['payload']['name'] ), $plan['taxonomy'], [ 'slug' => $plan['target']['slug'], 'parent' => $plan['target']['parent'] ] ); } finally { remove_action( 'created_term', $witness, 1 ); }
                if ( is_wp_error( $created ) ) { return $created; } $id = (int) $created['term_id'];
                if ( get_term_meta( $id, '_nova_mapped_operation', true ) !== $delivery['operation_id'] || get_term_meta( $id, '_nova_mapped_operation_digest', true ) !== $digest ) { return self::error( 'create_witness', 'The new category could not be linked to its durable operation. Inspect it before retrying.' ); }
                $result = Nova_Bridge_Suite_Term_Content::apply( $plan['taxonomy'], $id, $plan['payload'], $plan['profile_context'] ); if ( is_wp_error( $result ) ) { return $result; }
            } else {
                $id = $plan['target']['reference_id'];
                $fresh = Nova_Bridge_Suite_Document_Mapping::read( $plan['resource_id'], 'post', $id ); if ( is_wp_error( $fresh ) || $fresh['baseline'] !== $plan['baseline'] ) { return self::error( 'stale_snapshot', 'The source changed before the write. Request a fresh snapshot.' ); }
                if ( $plan['elementor_fields'] ) { $service = new \SEOR_Elementor_Bridge\Elementor_Service(); $written = $service->update_page( $id, [ 'fields' => $plan['elementor_fields'] ] ); if ( is_wp_error( $written ) ) { return $written; } }
                if ( $plan['native_fields'] ) { $written = wp_update_post( wp_slash( [ 'ID' => $id ] + $plan['native_fields'] ), true ); if ( is_wp_error( $written ) ) { return $written; } }
                if ( $plan['metadata'] ) { $written = Nova_Bridge_Suite_Content_Transport::write_fields( get_post( $id ), $plan['metadata'] ); if ( is_wp_error( $written ) ) { return $written; } }
                $verified = self::verify_rewrite( $plan ); if ( is_wp_error( $verified ) ) { return $verified; }
            }
            $journal['phase'] = 'complete'; $journal['native_id'] = $id; $journal['receipt'] = self::receipt( $delivery, $plan, $id ); $saved = self::checkpoint( $key, $journal ); return is_wp_error( $saved ) ? $saved : $journal['receipt'];
        } catch ( Throwable $error ) { return self::error( 'uncertain', 'Mapped delivery stopped before verification. Inspect its retained operation before retrying.', 500 ); }
        finally { self::unlock( $target_key ); self::unlock( $key ); }
    }
}
