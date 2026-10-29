<?php
/** Local versioned templates, sealed native blueprints and draft translation. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Layout_Recipes {
    private const OPTION = 'nova_bridge_layout_recipes_v1';
    private const OPERATIONS = 'nova_bridge_layout_recipe_operations_v1';
    private const MAX_BYTES = 8388608;
    private static $bootstrapped = false;
    public static function bootstrap(): void {
        if ( self::$bootstrapped ) { return; } self::$bootstrapped = true;
        add_action( 'rest_api_init', [ __CLASS__, 'routes' ], 1005 );
    }
    public static function routes(): void {
        foreach ( [ 'catalog' => [ 'GET', 'catalog' ], 'design' => [ 'POST', 'design' ], 'presentation' => [ 'POST', 'presentation' ], 'region' => [ 'POST', 'region' ], 'preview' => [ 'POST', 'preview' ], 'save' => [ 'POST', 'save' ], 'sync' => [ 'POST', 'sync' ], 'profile' => [ 'GET', 'profile' ], 'blank' => [ 'GET', 'blank' ], 'translate' => [ 'POST', 'translate' ] ] as $suffix => $operation ) {
            register_rest_route( 'nova-bridge/v1', '/mapping/layout-recipes/' . $suffix, [ 'methods' => $operation[0], 'callback' => self::callback( $operation[1] ), 'permission_callback' => [ __CLASS__, 'can_admin' ] ] );
        }
        register_rest_route( 'nova-bridge/v1', '/mapping/layout-recipes/draft', [ [ 'methods' => 'POST', 'callback' => self::callback( 'draft' ), 'permission_callback' => [ __CLASS__, 'can_admin' ] ], [ 'methods' => 'GET', 'callback' => self::callback( 'recover_draft' ), 'permission_callback' => [ __CLASS__, 'can_admin' ] ] ] );
    }
    private static function callback( string $method ): callable { return static function ( $request ) use ( $method ) { try { return self::$method( $request ); } catch ( Throwable $exception ) { return self::error( 'request', 'The template could not be processed. Reload its native design; if draft creation started, use Recover draft before retrying.', 422 ); } }; }
    private static function error( string $code, string $message, int $status = 400 ): WP_Error { return new WP_Error( 'nova_layout_recipe_' . $code, $message, [ 'status' => $status ] ); }
    public static function can_admin( $request ) {
        if ( ! current_user_can( 'manage_options' ) ) { return self::error( 'forbidden', 'Administrator access is required.', 403 ); }
        $nonce = $request->get_header( 'x-wp-nonce' ); if ( null !== $nonce && ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) ) { return self::error( 'nonce', 'Reload WordPress to renew your session.', 403 ); } return true;
    }
    private static function response( $result ) { if ( is_wp_error( $result ) ) { return $result; } $response = rest_ensure_response( $result ); $response->header( 'Cache-Control', 'private, no-store' ); return $response; }
    private static function body( $request ) { if ( strlen( (string) $request->get_body() ) > self::MAX_BYTES ) { return self::error( 'size', 'The complete template request exceeds 8 MiB.', 413 ); } $body = $request->get_json_params(); return is_array( $body ) && ( ! $body || ! Nova_Bridge_Suite_Recipe_Engine::list_array( $body ) ) ? $body : self::error( 'request', 'Supply a template object.' ); }
    private static function uuid( $id ): bool { return is_string( $id ) && 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD', $id ); }
    private static function resource( $id, bool $configure = true ) {
        foreach ( Nova_Bridge_Suite_Document_Mapping::resources() as $resource ) {
            if ( ( $resource['id'] ?? '' ) !== $id ) { continue; }
            if ( $configure && ( ! empty( $resource['system_managed'] ) || ! empty( $resource['coming_soon'] ) ) ) { return self::error( 'managed', 'This destination is supplied by NOVA or is coming soon; no client template is needed.', 403 ); }
            return $resource;
        }
        return self::error( 'destination', 'Choose an available content destination.', 404 );
    }
    private static function state() {
        $missing = new stdClass(); $state = get_option( self::OPTION, $missing ); if ( $state === $missing ) { return [ 'format' => 'nova-layout-recipes-store/v1', 'profiles' => [], 'defaults' => [] ]; }
        return is_array( $state ) && ( $state['format'] ?? '' ) === 'nova-layout-recipes-store/v1' && is_array( $state['profiles'] ?? null ) && is_array( $state['defaults'] ?? null ) ? $state : self::error( 'storage', 'The template history needs reconciliation; no history was overwritten.', 409 );
    }
    private static function mutate( string $option, callable $callback ) {
        global $wpdb; if ( ! isset( $wpdb->options ) || ! preg_match( '/^[A-Za-z0-9_]+$/D', $wpdb->options ) ) { return self::error( 'storage', 'Atomic template storage is unavailable.', 503 ); }
        for ( $attempt = 0; $attempt < 4; ++$attempt ) {
            wp_cache_delete( $option, 'options' ); wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' );
            $missing = new stdClass(); $before = get_option( $option, $missing );
            $state = self::OPTION === $option ? self::state() : ( $before === $missing ? [] : $before ); if ( is_wp_error( $state ) || ! is_array( $state ) ) { return is_wp_error( $state ) ? $state : self::error( 'storage', 'The local draft journal is invalid.', 409 ); }
            $change = $callback( $state ); if ( is_wp_error( $change ) ) { return $change; }
            $raw = maybe_serialize( $change['state'] ); if ( strlen( $raw ) > 33554432 ) { return self::error( 'history', 'The retained template history reached its limit. No saved revision was removed.', 409 ); }
            if ( $change['state'] === $state ) { return $change['result']; }
            $updated = $before === $missing ? add_option( $option, $change['state'], '', false ) : $wpdb->query( $wpdb->prepare( 'UPDATE `' . $wpdb->options . '` SET option_value=%s WHERE option_name=%s AND BINARY option_value=BINARY %s', $raw, $option, maybe_serialize( $before ) ) );
            wp_cache_delete( $option, 'options' ); wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' );
            if ( false === $updated && $before !== $missing ) { return self::error( 'storage', 'The template database write failed.', 500 ); }
            if ( $updated ) { return $change['result']; }
        }
        return self::error( 'conflict', 'Another administrator changed these templates. Reload before saving.', 409 );
    }
    public static function defaults(): array { $state = self::state(); return is_wp_error( $state ) ? [] : $state['defaults']; }
    private static function posting_page_type( string $resource ): ?string {
        if ( 'taxonomy:product_cat' === $resource ) { return 'category'; }
        return 0 === strpos( $resource, 'post_type:' ) ? ( 'post_type:post' === $resource ? 'informative' : 'service' ) : null;
    }
    private static function posting_support( array $record ): array {
        if ( 'taxonomy:product_cat' === ( $record['resource_id'] ?? '' ) ) { return [ 'supported' => true, 'reason' => '' ]; }
        return is_callable( [ 'Nova_Bridge_Suite_Stored_Layout', 'publishing_support' ] )
            ? Nova_Bridge_Suite_Stored_Layout::publishing_support( (string) ( $record['resource_id'] ?? '' ), (string) ( $record['builder'] ?? $record['recipe']['builder'] ?? '' ), (string) ( $record['mode'] ?? $record['recipe']['mode'] ?? '' ) )
            : [ 'supported' => false, 'reason' => 'The saved-layout publishing adapter is unavailable.' ];
    }
    private static function stored_recipe_supported( array $record ): bool {
        return is_callable( [ 'Nova_Bridge_Suite_Stored_Layout', 'supports_recipe' ] ) && Nova_Bridge_Suite_Stored_Layout::supports_recipe( $record );
    }
    private static function posting_metadata( array $record ): array {
        $fallback = self::posting_page_type( (string) ( $record['resource_id'] ?? '' ) );
        if ( null === $fallback ) { return []; }
        $metadata = [ 'posting_page_type' => $record['posting_page_type'] ?? $fallback, 'posting_support' => self::posting_support( $record ) ];
        $supported = self::stored_recipe_supported( $record );
        if ( 'taxonomy:product_cat' !== ( $record['resource_id'] ?? '' ) && $metadata['posting_support']['supported'] && ! $supported ) { $metadata['posting_support'] = [ 'supported' => false, 'reason' => 'This saved recipe contains repeated regions or additional fields without a publishing provider.' ]; }
        if ( $supported && is_callable( [ 'Nova_Bridge_Suite_Stored_Recipe_Sync', 'saved_status' ] ) && class_exists( 'Nova_Bridge_Suite_Posting_Settings' ) ) {
            $status = Nova_Bridge_Suite_Stored_Recipe_Sync::saved_status( Nova_Bridge_Suite_Posting_Settings::connection(), $record['id'], $record['revision'] );
            if ( $status && ( $status['page_type'] ?? null ) === $metadata['posting_page_type'] ) { $metadata['posting_sync'] = $status; }
        }
        return $metadata;
    }
    /** Card metadata for the exact selected local revision, not a new remote read. */
    public static function default_metadata( string $resource_id ): ?array {
        $identity = self::defaults()[$resource_id] ?? null; if ( ! $identity ) { return null; }
        $record = self::record( $identity['id'], $identity['revision'] );
        return is_wp_error( $record ) ? $identity : $identity + self::posting_metadata( $record );
    }
    /** Internal delivery adapter: immutable saved authority, never a remote native recipe. */
    public static function delivery_record( string $id, int $revision ) {
        $record = self::record( $id, $revision ); if ( is_wp_error( $record ) ) { return $record; }
        $checked = self::reviewed( $record ); return is_wp_error( $checked ) ? $checked : $record;
    }
    public static function delivery_compile( string $id, int $revision, array $content, string $operation ) {
        $record = self::delivery_record( $id, $revision ); return is_wp_error( $record ) ? $record : self::compile( $record['recipe'], $content, 'delivery:' . $operation );
    }
    /** Exact same deterministic recipe seed used by the journaled draft lifecycle. */
    public static function delivery_draft_content( string $id, int $revision, array $content, string $operation ) {
        $record = self::delivery_record( $id, $revision ); return is_wp_error( $record ) ? $record : self::compile( $record['recipe'], $content, 'draft:' . $operation );
    }
    private static function record( string $id, ?int $revision = null ) {
        if ( ! self::uuid( $id ) ) { return self::error( 'profile', 'Choose a saved template.', 404 ); } $state = self::state(); if ( is_wp_error( $state ) ) { return $state; }
        $history = $state['profiles'][$id] ?? []; $revision = $revision ?? ( $history ? max( array_keys( $history ) ) : 0 );
        return is_array( $history[$revision] ?? null ) ? $history[$revision] : self::error( 'profile', 'That immutable template revision was not found.', 404 );
    }
    private static function seal( string $builder, array $blueprint ): string { return hash_hmac( 'sha256', Nova_Bridge_Suite_Recipe_Engine::json( [ $builder, $blueprint ] ), wp_salt( 'auth' ) ); }
    private static function certified( string $builder, array $blueprint, $seal ) { return is_string( $seal ) && hash_equals( self::seal( $builder, $blueprint ), $seal ) ? true : self::error( 'blueprint', 'The native design changed outside its supported controls. Read the design again.', 409 ); }
    public static function sources( bool $item = false ): array {
        $sources = $item ? [ '/title' => [ 'Section heading', 'text' ], '/body' => [ 'Section content', 'html' ], '/question' => [ 'FAQ question', 'text' ], '/answer' => [ 'FAQ answer', 'html' ], '/url' => [ 'Link URL', 'url' ], '/children' => [ 'Nested sections', 'collection' ], '/faqs' => [ 'Section FAQs', 'collection' ], '/images' => [ 'Section images', 'collection' ], '/caption' => [ 'Image caption', 'text' ], '/alt' => [ 'Image description', 'text' ], '/layout' => [ 'Section layout', 'text' ], '/mobile_group' => [ 'Mobile group', 'text' ], '/mobile_title' => [ 'Mobile group heading', 'text' ] ] : [ '/title' => [ 'Page title', 'text' ], '/intro' => [ 'Introduction', 'html' ], '/sections' => [ 'Article sections', 'collection' ], '/faqs' => [ 'Article FAQs', 'collection' ], '/cta' => [ 'Call to action', 'collection' ], '/body' => [ 'Main content', 'html' ], '/url' => [ 'Link URL', 'url' ] ];
        $sources += $item ? [ '/title_html' => [ 'Section heading with formatting', 'html' ], '/body_with_children_html' => [ 'Section content including subheadings', 'html' ], '/body_html' => [ 'Section content', 'html' ], '/answer_html' => [ 'FAQ answer', 'html' ] ] : [ '/title_html' => [ 'Page title with formatting', 'html' ], '/intro_html' => [ 'Introduction', 'html' ], '/body_html' => [ 'Main content', 'html' ] ];
        $out = []; foreach ( $sources as $path => $entry ) { $out[] = [ 'path' => $path, 'label' => $entry[0], 'kind' => $entry[1], 'scope' => $item ? 'item' : 'root' ]; } return $out;
    }
    public static function catalog( $request ) {
        $state = self::state(); if ( is_wp_error( $state ) ) { return $state; } $profiles = []; foreach ( $state['profiles'] as $id => $history ) { if ( $history ) { $entry = $history[max( array_keys( $history ) )]; $profiles[] = array_intersect_key( $entry, array_flip( [ 'id', 'revision', 'resource_id', 'label', 'mode', 'builder' ] ) ) + self::posting_metadata( $entry ); } }
        $defaults = []; foreach ( $state['defaults'] as $resource_id => $identity ) { $defaults[$resource_id] = self::default_metadata( $resource_id ); }
        $builders = Nova_Bridge_Suite_Recipe_Builders::catalog( 'page' ); $builders[] = [ 'id' => 'acf', 'label' => 'ACF / SCF custom fields', 'available' => Nova_Bridge_Suite_Recipe_ACF::available(), 'modes' => [ 'static', 'mixed', 'rules' ], 'capture' => true, 'starter' => true, 'draft' => true, 'reason' => Nova_Bridge_Suite_Recipe_ACF::available() ? '' : 'Install an ACF/SCF provider with declared editorial fields.' ];
        array_unshift( $builders, [ 'id' => 'core', 'label' => 'WordPress content fields', 'available' => true, 'modes' => [ 'static', 'mixed', 'rules' ], 'capture' => true, 'starter' => true, 'draft' => true, 'reason' => '' ] );
        $resources = Nova_Bridge_Suite_Document_Mapping::resources();
        foreach ( $resources as &$resource ) {
            $resource['posting_support'] = [];
            foreach ( $builders as $builder ) { foreach ( $builder['modes'] ?? [] as $mode ) {
                $resource['posting_support'][$builder['id']][$mode] = self::posting_support( [ 'resource_id' => $resource['id'], 'builder' => $builder['id'], 'mode' => $mode ] );
            } }
        } unset( $resource );
        return self::response( [ 'resources' => $resources, 'builders' => $builders, 'profiles' => $profiles, 'defaults' => $defaults, 'sources' => self::sources(), 'local_only' => true ] );
    }

    private static function describe( string $builder, array $blueprint ) { return 'acf' === $builder ? Nova_Bridge_Suite_Recipe_ACF::describe( $blueprint ) : ( 'core' === $builder ? Nova_Bridge_Suite_Recipe_Core::describe( $blueprint ) : Nova_Bridge_Suite_Recipe_Builders::describe( $builder, $blueprint ) ); }
    private static function field_source( array $field, bool $item, string $collection = '' ): string {
        $kind = strtolower( (string) ( $field['kind'] ?? '' ) ); $label = strtolower( $field['label'] );
        if ( $item ) {
            if ( '/faqs' === $collection ) { return 'html' === ( $field['format'] ?? '' ) && ! preg_match( '/heading|title|question/', $kind . ' ' . $label ) ? '/answer' : '/question'; }
            if ( 'url' === ( $field['format'] ?? '' ) ) { return '/url'; }
            if ( preg_match( '/heading|title/', $kind . ' ' . $label ) ) { return '/title'; }
            if ( 'html' === ( $field['format'] ?? '' ) || preg_match( '/body|editor|text/', $kind ) ) { return '/body'; }
        }
        return '/fields/f_' . substr( hash( 'sha256', $field['target'] ), 0, 12 );
    }
    private static function binding( array $field, bool $item = false, string $collection = '' ): array { return [ 'target' => $field['target'], 'source' => self::field_source( $field, $item, $collection ), 'format' => $field['format'] ?? 'text', 'required' => ! empty( $field['required'] ), 'label' => $field['label'], 'instructions' => $field['instructions'] ?? '' ]; }
    private static function inside( string $path, array $region ): bool {
        $prefix = $region['target'] . '/'; if ( 0 !== strpos( $path, $prefix ) ) { return false; } $part = explode( '/', substr( $path, strlen( $prefix ) ), 2 )[0]; return ctype_digit( $part ) && (int) $part >= $region['start'] && (int) $part < $region['start'] + $region['count'];
    }
    private static function relative_meta( array $description, array $region ): array {
        if ( 0 === ( $region['count'] ?? null ) && ! array_key_exists( 'prototype_start', $region ) && ! array_key_exists( 'prototype_count', $region ) ) {
            foreach ( $description['containers'] ?? [] as $container ) {
                if ( ( $container['target'] ?? '' ) === ( $region['target'] ?? '' ) && is_array( $container['schema_prototype'] ?? null ) ) { return [ 'fields' => $container['schema_fields'] ?? [], 'containers' => $container['schema_containers'] ?? [] ]; }
            }
        }
        $region['start'] = $region['prototype_start'] ?? $region['start']; $region['count'] = $region['prototype_count'] ?? $region['count'];
        $out = [ 'fields' => [], 'containers' => [] ]; foreach ( $out as $key => $ignored ) { foreach ( $description[$key] ?? [] as $entry ) {
            if ( ! self::inside( $entry['target'], $region ) ) { continue; } $suffix = substr( $entry['target'], strlen( $region['target'] ) + 1 ); $parts = explode( '/', $suffix, 2 ); $entry['target'] = '/' . ( (int) $parts[0] - $region['start'] ) . ( isset( $parts[1] ) ? '/' . $parts[1] : '' ); $out[$key][] = $entry;
        } } return $out;
    }
    private static function region_value( array $scope, array $description, array $request, string $builder = '' ) {
        $target = $request['target'] ?? ''; $start = $request['start'] ?? 0; $count = $request['count'] ?? 1; $pattern_start = $request['prototype_start'] ?? $start; $pattern_count = $request['prototype_count'] ?? 1;
        if ( ! is_string( $target ) || ! is_int( $start ) || ! is_int( $count ) || $start < 0 || $count < 0 ) { return self::error( 'region', 'Choose a native region and its example items.' ); }
        $candidate = null; foreach ( $description['containers'] ?? [] as $container ) { if ( $container['target'] === $target ) { $candidate = $container; break; } }
        $list = Nova_Bridge_Suite_Recipe_Engine::get( $scope, $target, $found ); if ( ! $candidate || ! $found || ! Nova_Bridge_Suite_Recipe_Engine::list_array( $list ) || $start + $count > count( $list ) ) { return self::error( 'region', 'Choose a declared child region within the captured design.' ); }
        $declared_empty = 0 === $count && 'core' === $builder && 0 === $start && [] === $list && ! array_key_exists( 'prototype_start', $request ) && ! array_key_exists( 'prototype_count', $request ) && Nova_Bridge_Suite_Recipe_Engine::list_array( $candidate['schema_prototype'] ?? null ) && ! empty( $candidate['schema_prototype'] );
        if ( 0 === $count && ! $declared_empty ) { return self::error( 'pattern', 'An empty custom collection needs its declared field schema; native builders still require example items.', 409 ); }
        if ( ! $declared_empty && ( ! is_int( $pattern_start ) || ! is_int( $pattern_count ) || $pattern_start < $start || $pattern_count < 1 || $pattern_start + $pattern_count > $start + $count ) ) { return self::error( 'pattern', 'Choose a native example pattern inside the replaced range.' ); }
        $region = [ 'id' => 'region_' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 16 ), 'label' => sanitize_text_field( $request['label'] ?? $candidate['label'] ), 'instructions' => '', 'target' => $target, 'start' => $start, 'count' => $count, 'prototype_start' => $pattern_start, 'prototype_count' => $pattern_count, 'source' => $request['source'] ?? '/sections', 'kind' => $request['kind'] ?? 'repeat', 'empty' => 'remove', 'max_items' => min( 500, max( 1, (int) ( $candidate['max_items'] ?? 500 ) ) ), 'prototype' => [ 'native' => array_slice( $list, $pattern_start, $pattern_count ), 'bindings' => [], 'regions' => [] ] ];
        if ( $declared_empty ) { unset( $region['prototype_start'], $region['prototype_count'] ); $region['prototype']['native'] = $candidate['schema_prototype']; }
        if ( 'optional' === $region['kind'] && in_array( $region['source'], [ '/faqs', '/sections', '/images', '/children' ], true ) ) { $region['context'] = 'parent'; }
        $relative = self::relative_meta( $description, $region ); $seen = [];
        foreach ( $relative['fields'] as $field ) { if ( empty( $field['writable'] ) ) { continue; } $binding = self::binding( $field, true, $region['source'] ); if ( isset( $seen[$binding['source']] ) ) { $binding['source'] = '/fields/f_' . substr( hash( 'sha256', $field['target'] ), 0, 12 ); } $seen[$binding['source']] = true; $region['prototype']['bindings'][] = $binding; }
        $variants_meta = [];
        if ( ! empty( $candidate['layouts'] ) ) {
            $region['variant_by'] = '/layout'; $region['variants'] = []; $region['default_variant'] = $candidate['layouts'][0]['name'];
            foreach ( $candidate['layouts'] as $layout ) { $native = Nova_Bridge_Suite_Recipe_ACF::layout_prototype( $layout ); $meta = Nova_Bridge_Suite_Recipe_ACF::layout_description( $layout ); $bindings = []; foreach ( $meta['fields'] as $field ) { if ( ! empty( $field['writable'] ) ) { $bindings[] = self::binding( $field, true, $region['source'] ); } } $region['variants'][$layout['name']] = [ 'native' => $native, 'bindings' => $bindings, 'regions' => [] ]; $variants_meta[$layout['name']] = $meta + [ 'sources' => self::sources( true ) ]; }
        }
        return [ 'region' => $region ] + $relative + [ 'collection_limits' => [ 'required' => ! empty( $candidate['required'] ), 'min_items' => max( 0, (int) ( $candidate['min_items'] ?? 0 ) ), 'max_items' => $region['max_items'] ], 'sources' => self::sources( true ), 'variants_meta' => $variants_meta, 'layouts' => array_map( static function ( $layout ) { return [ 'value' => $layout['name'], 'label' => $layout['label'] ]; }, $candidate['layouts'] ?? [] ) ];
    }
    private static function preset( string $builder, string $mode, array $design ): array {
        $recipe = [ 'format' => Nova_Bridge_Suite_Recipe_Engine::FORMAT, 'builder' => $builder, 'mode' => $mode, 'blueprint' => $design['blueprint'], 'bindings' => [], 'regions' => [], 'instructions' => '' ];
        foreach ( $design['fields'] ?? [] as $field ) { if ( ! empty( $field['writable'] ) ) { $recipe['bindings'][] = self::binding( $field ); } }
        if ( 'static' !== $mode ) {
            foreach ( $design['suggested_regions'] ?? [] as $hint ) { if ( isset( $hint['prototype'] ) ) { $recipe['regions'][] = $hint; continue; } $value = self::region_value( $design['blueprint'], $design, $hint, $builder ); if ( is_wp_error( $value ) ) { continue; } $recipe['regions'][] = $value['region']; }
            $recipe['bindings'] = array_values( array_filter( $recipe['bindings'], static function ( $binding ) use ( $recipe ) { foreach ( $recipe['regions'] as $region ) { if ( self::inside( $binding['target'], $region ) ) { return false; } } return true; } ) );
        }
        foreach ( $design['suggested_bindings'] ?? [] as $hint ) { foreach ( $recipe['bindings'] as &$binding ) { if ( $binding['target'] === $hint['target'] ) { $binding = array_merge( $binding, array_intersect_key( $hint, array_flip( [ 'source', 'required', 'label', 'instructions' ] ) ) ); } } unset( $binding ); }
        return $recipe;
    }
    private static function assign( array &$content, string $path, $value ): void { $cursor = &$content; $parts = Nova_Bridge_Suite_Recipe_Engine::parts( $path ); foreach ( $parts as $index => $part ) { if ( $index === count( $parts ) - 1 ) { $cursor[$part] = $value; } else { if ( ! isset( $cursor[$part] ) || ! is_array( $cursor[$part] ) ) { $cursor[$part] = []; } $cursor = &$cursor[$part]; } } }
    private static function merge_samples( array $left, array $right ): array { foreach ( $right as $key => $value ) { if ( ! array_key_exists( $key, $left ) ) { $left[$key] = $value; } elseif ( is_array( $left[$key] ) && is_array( $value ) ) { $left[$key] = self::merge_samples( $left[$key], $value ); } } return $left; }
    private static function preferred_samples( array $base, array $preferred ): array { foreach ( $preferred as $key => $value ) { if ( is_array( $value ) && ! Nova_Bridge_Suite_Recipe_Engine::list_array( $value ) && isset( $base[$key] ) && is_array( $base[$key] ) ) { $base[$key] = self::preferred_samples( $base[$key], $value ); } else { $base[$key] = $value; } } return $base; }
    private static function sample_scope( array $recipe, int $items = 2, int $depth = 0 ): array {
        $content = []; foreach ( $recipe['bindings'] ?? [] as $binding ) { $format = $binding['format']; $label = $binding['label'] ?? 'Example content'; $original = Nova_Bridge_Suite_Recipe_Engine::get( $recipe['blueprint'] ?? $recipe['native'] ?? [], $binding['target'], $found ); $value = $found && is_scalar( $original ) && '' !== (string) $original ? $original : ( 'number' === $format ? 1 : ( 'boolean' === $format ? true : ( 'url' === $format ? 'https://example.com/contact' : ( 'html' === $format && ! preg_match( '/heading|title|question/i', $label ) ? '<p>Example content for ' . esc_html( $label ) . '.</p>' : 'Example ' . $label ) ) ) ); self::assign( $content, $binding['source'], $value ); }
        if ( $depth > 7 ) { return $content; }
        foreach ( $recipe['regions'] ?? [] as $region ) {
            $rows = []; $count = 'optional' === $region['kind'] ? 1 : min( $items, $region['max_items'] );
            if ( ( $region['context'] ?? '' ) === 'parent' ) { $content = array_replace_recursive( $content, self::sample_scope( $region['prototype'], $items, $depth + 1 ) ); if ( ! Nova_Bridge_Suite_Recipe_Engine::get( $content, $region['source'], $exists ) ) { self::assign( $content, $region['source'], [ [ 'question' => 'Example question?', 'answer' => '<p>Example answer.</p>' ] ] ); } continue; }
            for ( $index = 0; $index < $count; ++$index ) { $prototype = $region['prototype']; $variant = null; if ( isset( $region['variants'] ) ) { $keys = array_keys( $region['variants'] ); $variant = $keys[$index % count( $keys )]; $prototype = $region['variants'][$variant]; } $row = self::sample_scope( $prototype, $items, $depth + 1 ); if ( $variant ) { self::assign( $row, $region['variant_by'], $variant ); } if ( isset( $region['projection'] ) ) { self::assign( $row, $region['projection']['group_by'], 'group_' . (int) floor( $index / 2 ) ); if ( isset( $region['projection']['title_source'] ) ) { self::assign( $row, $region['projection']['title_source'], 'Mobile group ' . ( (int) floor( $index / 2 ) + 1 ) ); } } $rows[] = $row; }
            $addition = []; self::assign( $addition, $region['source'], 'optional' === $region['kind'] ? ( $rows[0] ?? [] ) : $rows ); $content = self::merge_samples( $content, $addition );
        }
        return $content;
    }
    private static function decorate_design( string $builder, string $mode, array $design ): array {
        foreach ( $design['suggested_bindings'] ?? [] as $hint ) { foreach ( $design['fields'] as &$field ) { if ( $field['target'] === $hint['target'] ) { $field = array_merge( $field, array_intersect_key( $hint, array_flip( [ 'label', 'instructions', 'required' ] ) ) ); } } unset( $field ); }
        foreach ( $design['fields'] as &$field ) { $value = Nova_Bridge_Suite_Recipe_Engine::get( $design['blueprint'], $field['target'], $found ); if ( array_key_exists( 'value', $field ) && is_scalar( $field['value'] ) ) { $value = $field['value']; $found = true; } $field['example'] = $found && is_scalar( $value ) ? mb_substr( wp_strip_all_tags( (string) $value ), 0, 160 ) : ''; } unset( $field );
        $design['builder'] = $builder; $design['seal'] = self::seal( $builder, $design['blueprint'] ); $design['sources'] = self::sources(); $design['recipe_preset'] = self::preset( $builder, $mode, $design ); $design['sample'] = self::sample_scope( $design['recipe_preset'] );
        foreach ( $design['recipe_preset']['bindings'] as $binding ) { foreach ( $design['fields'] as $field ) { if ( $field['target'] === $binding['target'] && ( array_key_exists( 'sample', $field ) || ( array_key_exists( 'value', $field ) && is_scalar( $field['value'] ) && '' !== (string) $field['value'] ) ) ) { self::assign( $design['sample'], $binding['source'], $field['sample'] ?? $field['value'] ); break; } } }
        if ( isset( $design['sample_content'] ) && is_array( $design['sample_content'] ) ) { $design['sample'] = self::preferred_samples( $design['sample'], $design['sample_content'] ); }
        $known = array_column( $design['sources'], 'path' ); foreach ( $design['recipe_preset']['bindings'] as $binding ) { if ( ! in_array( $binding['source'], $known, true ) ) { $design['sources'][] = [ 'path' => $binding['source'], 'label' => $binding['label'], 'kind' => $binding['format'], 'scope' => 'root' ]; $known[] = $binding['source']; } }
        return $design;
    }
    public static function design( $request ) {
        $body = self::body( $request ); if ( is_wp_error( $body ) ) { return $body; } $resource = self::resource( $body['resource_id'] ?? '' ); if ( is_wp_error( $resource ) ) { return $resource; }
        $builder = $body['builder'] ?? ''; $id = $body['reference_id'] ?? 0; $mode = $body['mode'] ?? 'mixed'; if ( ! is_string( $builder ) || ! is_int( $id ) || $id < 0 || ! in_array( $mode, [ 'static', 'mixed', 'rules' ], true ) ) { return self::error( 'design', 'Choose a builder, template approach and local reference.' ); }
        if ( ! empty( $body['reference_url'] ) && class_exists( 'Nova_Bridge_Suite_Recipe_Visual' ) ) { if ( ! is_string( $body['reference_url'] ) ) { return self::error( 'reference', 'Supply one local example URL.' ); } $resolved = Nova_Bridge_Suite_Recipe_Visual::resolve_url( $resource, $body['reference_url'] ); if ( is_wp_error( $resolved ) ) { return $resolved; } if ( $id && $id !== $resolved ) { return self::error( 'reference', 'The example URL and selected item refer to different pages.', 409 ); } $id = $resolved; }
        if ( $id ) { $entity = 'taxonomy' === $resource['type'] ? get_term( $id, $resource['taxonomy'] ) : get_post( $id ); if ( ! $entity || is_wp_error( $entity ) || ( 'taxonomy' !== $resource['type'] && $entity->post_type !== $resource['post_type'] ) || ! current_user_can( 'taxonomy' === $resource['type'] ? 'edit_term' : 'edit_post', $id ) ) { return self::error( 'reference', 'That reference is outside this destination or cannot be edited.', 403 ); } }
        if ( 'auto' === $builder ) { if ( ! $id || ! class_exists( 'Nova_Bridge_Suite_Recipe_Visual' ) ) { return self::error( 'reference', 'Choose an existing page before detecting its builder.' ); } $builder = Nova_Bridge_Suite_Recipe_Visual::detected_builder( $resource, $id ); if ( is_wp_error( $builder ) ) { return $builder; } }
        elseif ( $id && 'core' === $builder && 'taxonomy' !== $resource['type'] && class_exists( 'Nova_Bridge_Suite_Recipe_Visual' ) ) { $detected = Nova_Bridge_Suite_Recipe_Visual::detected_builder( $resource, $id ); if ( is_wp_error( $detected ) || 'core' !== $detected ) { return self::error( 'builder', 'This example has a native builder or block design. Use its detected provider to map those elements instead of flattening the WordPress body field.', 409 ); } }
        if ( 'taxonomy' === $resource['type'] && ! in_array( $builder, [ 'core', 'acf' ], true ) ) { return self::error( 'builder', 'Taxonomy content uses WordPress content fields or its installed ACF schema.' ); }
        $declared_term_fields = $body['declared_term_fields'] ?? [];
        if ( ! is_array( $declared_term_fields ) || ( $declared_term_fields && Nova_Bridge_Suite_Recipe_Engine::list_array( $declared_term_fields ) ) || count( $declared_term_fields ) > 500 || ( $declared_term_fields && ( 'taxonomy' !== $resource['type'] || 'core' !== $builder ) ) ) { return self::error( 'fields', 'Declare local custom fields as a bounded field-name/schema object for this category destination.', 422 ); }
        if ( array_key_exists( 'existing_blueprint', $body ) || array_key_exists( 'existing_seal', $body ) ) {
            if ( 'core' !== $builder || 'taxonomy' !== $resource['type'] || ! is_array( $body['existing_blueprint'] ?? null ) || ! class_exists( 'Nova_Bridge_Suite_Recipe_Visual' ) ) { return self::error( 'fields', 'Capture the category design before confirming additional custom fields.', 409 ); }
            $previous = self::certified( 'core', $body['existing_blueprint'], $body['existing_seal'] ?? '' ); if ( is_wp_error( $previous ) ) { return $previous; }
            $previous = Nova_Bridge_Suite_Recipe_Visual::validate_source( $body, [ 'builder' => 'core', 'mode' => $mode, 'blueprint' => $body['existing_blueprint'] ], $resource );
            if ( is_wp_error( $previous ) ) { return $previous; }
            if ( ! is_array( $previous ) || ! $id || ( $previous['reference_id'] ?? 0 ) !== $id ) { return self::error( 'reference', 'Confirm custom fields against the same verified example category.', 409 ); }
        }
        if ( 'acf' === $builder ) { $template = $id && 'taxonomy' !== $resource['type'] ? (string) get_page_template_slug( $id ) : ''; $design = Nova_Bridge_Suite_Recipe_ACF::design( $resource['post_type'] ?? '', $id, $template, $resource['taxonomy'] ?? '' ); }
        elseif ( 'core' === $builder ) { $design = Nova_Bridge_Suite_Recipe_Core::design( $resource, $id, $mode, $declared_term_fields ); }
        else { $design = $id ? Nova_Bridge_Suite_Recipe_Builders::capture( $builder, $id ) : Nova_Bridge_Suite_Recipe_Builders::starter( $builder, $mode ); }
        if ( is_wp_error( $design ) ) { return $design; } $design = self::decorate_design( $builder, $mode, $design );
        if ( 'acf' !== $builder && ( ! empty( $resource['post_type'] ) || 'taxonomy' === $resource['type'] ) ) {
            $custom = Nova_Bridge_Suite_Recipe_ACF::companion_design( $resource['post_type'] ?? '', $id, (string) ( $design['blueprint']['template'] ?? '' ), $resource['taxonomy'] ?? '' );
            if ( is_wp_error( $custom ) ) { return $custom; }
            if ( is_array( $custom ) ) { $design['custom_fields_design'] = self::decorate_design( 'acf', $mode, $custom ); }
        }
        if ( true === ( $body['manual_selection'] ?? false ) && $id ) {
            $design['recipe_preset']['bindings'] = []; $design['recipe_preset']['regions'] = []; $design['sample'] = new stdClass();
            if ( isset( $design['custom_fields_design'] ) ) { $design['custom_fields_design']['recipe_preset']['bindings'] = []; $design['custom_fields_design']['recipe_preset']['regions'] = []; $design['custom_fields_design']['sample'] = new stdClass(); }
        }
        if ( class_exists( 'Nova_Bridge_Suite_Recipe_Visual' ) ) { $design = Nova_Bridge_Suite_Recipe_Visual::enrich( $design, $resource, $id ); }
        $design = self::presentation_choices( $design, $resource, $id );
        return self::response( $design );
    }
    /** Candidate values are signed, but none are included until explicitly selected. */
    private static function presentation_choices( array $design, array $resource, int $id ): array {
        if ( 'elementor' !== $design['builder'] || ! $id || empty( $resource['post_type'] ) || ! class_exists( 'Nova_Bridge_Suite_Theme_Presentation' ) ) { return $design; }
        $inventory = Nova_Bridge_Suite_Theme_Presentation::candidates( $id );
        if ( is_wp_error( $inventory ) ) { $design['presentation_notice'] = $inventory->get_error_message(); return $design; }
        $base = $design['blueprint']; unset( $base['theme_presentation'] );
        $ticket = [ 'reference_id' => $id, 'resource_id' => $resource['id'], 'base_digest' => Nova_Bridge_Suite_Recipe_Engine::digest( $base ), 'inventory' => $inventory ];
        $design['presentation_choices'] = $ticket;
        $design['presentation_seal'] = self::seal( 'presentation', $ticket );
        return $design;
    }
    /** Pure capture selection: no page, mapping, option or delivery is written. */
    public static function presentation( $request ) {
        $body = self::body( $request ); if ( is_wp_error( $body ) ) { return $body; }
        $resource = self::resource( $body['resource_id'] ?? '' ); if ( is_wp_error( $resource ) ) { return $resource; }
        $design = $body['design'] ?? []; $ticket = $body['presentation_choices'] ?? []; $keys = $body['selected_keys'] ?? null;
        if ( ( $design['builder'] ?? '' ) !== 'elementor' || ! is_array( $design['blueprint'] ?? null ) || ! is_array( $ticket ) || ! is_array( $keys ) || ! Nova_Bridge_Suite_Recipe_Engine::list_array( $keys ) || ! class_exists( 'Nova_Bridge_Suite_Recipe_Visual' ) ) { return self::error( 'presentation', 'Choose fixed page settings from the captured Elementor example.' ); }
        $checked = self::certified( 'elementor', $design['blueprint'], $design['seal'] ?? '' ); if ( is_wp_error( $checked ) ) { return $checked; }
        $checked = self::certified( 'presentation', $ticket, $body['presentation_seal'] ?? '' ); if ( is_wp_error( $checked ) ) { return $checked; }
        $mode = $body['mode'] ?? 'static'; if ( ! in_array( $mode, [ 'static', 'mixed', 'rules' ], true ) ) { return self::error( 'presentation', 'Choose a supported template approach.' ); }
        $source = Nova_Bridge_Suite_Recipe_Visual::validate_source( $body, [ 'builder' => 'elementor', 'mode' => $mode, 'blueprint' => $design['blueprint'] ], $resource );
        if ( is_wp_error( $source ) ) { return $source; }
        $base = $design['blueprint']; unset( $base['theme_presentation'] );
        if ( ! $source || ( $ticket['reference_id'] ?? null ) !== $source['reference_id'] || ( $ticket['resource_id'] ?? '' ) !== $resource['id'] || ( $ticket['base_digest'] ?? '' ) !== Nova_Bridge_Suite_Recipe_Engine::digest( $base ) || ! is_array( $ticket['inventory'] ?? null ) ) { return self::error( 'presentation', 'These page settings belong to another captured design.', 409 ); }
        $snapshot = Nova_Bridge_Suite_Theme_Presentation::select( $ticket['inventory'], $keys ); if ( is_wp_error( $snapshot ) ) { return $snapshot; }
        if ( null !== $snapshot ) { $base['theme_presentation'] = $snapshot; }
        $result = self::describe( 'elementor', $base ); if ( is_wp_error( $result ) ) { return $result; }
        $result = self::decorate_design( 'elementor', $mode, $result );
        $result = Nova_Bridge_Suite_Recipe_Visual::enrich( $result, $resource, $source['reference_id'] );
        if ( ( $result['visual_preview']['status'] ?? '' ) === 'stale' ) { return self::error( 'presentation', 'The example settings changed. Capture the design again before selecting them.', 409 ); }
        return self::response( self::presentation_choices( $result, $resource, $source['reference_id'] ) );
    }
    public static function region( $request ) {
        $body = self::body( $request ); if ( is_wp_error( $body ) ) { return $body; } $design = $body['design'] ?? []; $builder = $design['builder'] ?? '';
        if ( ! is_string( $builder ) || ! is_array( $design['blueprint'] ?? null ) ) { return self::error( 'design', 'Read a native design before choosing regions.' ); }
        $certified = self::certified( $builder, $design['blueprint'], $design['seal'] ?? '' ); if ( is_wp_error( $certified ) ) { return $certified; } $scope = $design['blueprint']; $description = self::describe( $builder, $scope ); if ( is_wp_error( $description ) ) { return $description; }
        $chain = $body['parent_chain'] ?? ( isset( $body['parent_region'] ) ? [ $body['parent_region'] ] : [] );
        if ( ! is_array( $chain ) || count( $chain ) > 8 ) { return self::error( 'depth', 'Choose at most eight nested regions.' ); }
        foreach ( $chain as $index => $parent ) {
            if ( ! is_array( $parent ) ) { return self::error( 'region', 'Choose a complete parent region.' ); }
            $checked = self::check_scope( $scope, $description, [], [ $parent ], $builder ); if ( is_wp_error( $checked ) ) { return $checked; }
            $declared_layouts = []; foreach ( $description['containers'] ?? [] as $candidate ) { if ( $candidate['target'] === $parent['target'] ) { $declared_layouts = $candidate['layouts'] ?? []; break; } }
            $description = self::relative_meta( $description, $parent ); $scope = $parent['prototype']['native'];
            $variant = $body['parent_variants'][$index] ?? ( $index === count( $chain ) - 1 ? ( $body['variant'] ?? null ) : null );
            if ( null !== $variant ) { if ( ! is_string( $variant ) || ! isset( $parent['variants'][$variant] ) ) { return self::error( 'variant', 'Choose a declared layout variant.' ); } $scope = $parent['variants'][$variant]['native']; if ( 'acf' === $builder ) { foreach ( $declared_layouts as $layout ) { if ( $layout['name'] === $variant ) { $description = Nova_Bridge_Suite_Recipe_ACF::layout_description( $layout ); break; } } } else { $description = self::prototype_description( $builder, $scope, $description ); } }
        }
        return self::response( self::region_value( $scope, $description, $body, $builder ) );
    }

    private static function prototype_description( string $builder, array $native, array $fallback ): array {
        if ( 'acf' === $builder || 'core' === $builder ) { return $fallback; }
        $description = self::describe( $builder, [ 'document' => $native ] ); if ( is_wp_error( $description ) ) { return $fallback; }
        foreach ( [ 'fields', 'containers' ] as $key ) { foreach ( $description[$key] as &$entry ) { if ( 0 === strpos( $entry['target'], '/document' ) ) { $entry['target'] = substr( $entry['target'], 9 ); } } unset( $entry ); } return $description;
    }
    private static function check_scope( array $native, array $description, array $bindings, array $regions, string $builder, int $depth = 0 ) {
        if ( $depth > 8 ) { return self::error( 'depth', 'Nested content regions exceed eight levels.' ); }
        $allowed = []; foreach ( $description['fields'] ?? [] as $field ) { if ( ! empty( $field['writable'] ) ) { $allowed[$field['target']] = $field; } }
        foreach ( $bindings as $binding ) { $field = $allowed[$binding['target'] ?? ''] ?? null; $formats = $field['allowed_formats'] ?? [ $field['format'] ?? 'text' ]; if ( ! $field || ! in_array( $binding['format'] ?? '', $formats, true ) ) { return self::error( 'field', 'Only declared writable content fields may receive generated values. Styles, identifiers and bindings remain protected.', 409 ); } }
        foreach ( $regions as $region ) {
            $container = null; foreach ( $description['containers'] ?? [] as $candidate ) { if ( $candidate['target'] === ( $region['target'] ?? '' ) ) { $container = $candidate; break; } }
            $list = Nova_Bridge_Suite_Recipe_Engine::get( $native, $region['target'] ?? '', $found );
            if ( ! $container || ! $found || ! Nova_Bridge_Suite_Recipe_Engine::list_array( $list ) || ! is_int( $region['start'] ?? null ) || ! is_int( $region['count'] ?? null ) || $region['start'] < 0 || $region['count'] < 0 || $region['start'] + $region['count'] > count( $list ) ) { return self::error( 'region', 'Variable regions must select declared native children from this design.', 409 ); }
            $declared_empty = 0 === $region['count'] && 'core' === $builder && 0 === $region['start'] && [] === $list && ! array_key_exists( 'prototype_start', $region ) && ! array_key_exists( 'prototype_count', $region ) && Nova_Bridge_Suite_Recipe_Engine::list_array( $container['schema_prototype'] ?? null ) && ! empty( $container['schema_prototype'] );
            if ( 0 === $region['count'] && ! $declared_empty ) { return self::error( 'prototype', 'An empty custom collection needs its exact declared schema.', 409 ); }
            $pattern_start = $region['prototype_start'] ?? $region['start']; $pattern_count = $region['prototype_count'] ?? $region['count'];
            if ( ! $declared_empty && ( ! is_int( $pattern_start ) || ! is_int( $pattern_count ) || $pattern_start < $region['start'] || $pattern_count < 1 || $pattern_start + $pattern_count > $region['start'] + $region['count'] ) ) { return self::error( 'prototype', 'The example pattern must stay inside its replaced region.' ); }
            $prototype = $declared_empty ? $container['schema_prototype'] : array_slice( $list, $pattern_start, $pattern_count );
            if ( Nova_Bridge_Suite_Recipe_Engine::digest( $prototype ) !== Nova_Bridge_Suite_Recipe_Engine::digest( $region['prototype']['native'] ?? null ) ) { return self::error( 'prototype', 'The native example prototype changed outside its supported controls. Capture the design again.', 409 ); }
            $relative = self::relative_meta( $description, $region ); $checked = self::check_scope( $prototype, $relative, $region['prototype']['bindings'] ?? [], $region['prototype']['regions'] ?? [], $builder, $depth + 1 ); if ( is_wp_error( $checked ) ) { return $checked; }
            foreach ( $region['variants'] ?? [] as $name => $variant ) {
                if ( 'acf' !== $builder || ! isset( $container['layouts'] ) ) { return self::error( 'variant', 'Layout variants must come from an installed flexible-content schema.' ); }
                $known = false; foreach ( $container['layouts'] as $layout ) { if ( $layout['name'] === $name ) { $known = true; $declared = Nova_Bridge_Suite_Recipe_ACF::layout_prototype( $layout ); $variant_description = Nova_Bridge_Suite_Recipe_ACF::layout_description( $layout ); break; } }
                if ( $known && Nova_Bridge_Suite_Recipe_Engine::digest( $declared ) !== Nova_Bridge_Suite_Recipe_Engine::digest( $variant['native'] ?? null ) ) { foreach ( $list as $row ) { if ( ( $row['acf_fc_layout'] ?? '' ) === $name && Nova_Bridge_Suite_Recipe_Engine::digest( [ $row ] ) === Nova_Bridge_Suite_Recipe_Engine::digest( $variant['native'] ?? null ) ) { $declared = [ $row ]; $variant_description = Nova_Bridge_Suite_Recipe_ACF::layout_description( $layout, $row ); break; } } }
                if ( ! $known || Nova_Bridge_Suite_Recipe_Engine::digest( $declared ) !== Nova_Bridge_Suite_Recipe_Engine::digest( $variant['native'] ?? null ) ) { return self::error( 'variant', 'An unknown or changed flexible-content layout was supplied.' ); }
                $checked = self::check_scope( $declared, $variant_description, $variant['bindings'] ?? [], $variant['regions'] ?? [], $builder, $depth + 1 ); if ( is_wp_error( $checked ) ) { return $checked; }
            }
        }
        return true;
    }
    private static function reviewed( array $body ) {
        $recipe = $body['recipe'] ?? null; if ( ! is_array( $recipe ) || ! is_string( $recipe['builder'] ?? null ) || ! is_array( $recipe['blueprint'] ?? null ) ) { return self::error( 'recipe', 'Supply the complete template and its certified design.' ); }
        $seal = self::certified( $recipe['builder'], $recipe['blueprint'], $body['seal'] ?? '' ); if ( is_wp_error( $seal ) ) { return $seal; }
        $description = self::describe( $recipe['builder'], $recipe['blueprint'] ); if ( is_wp_error( $description ) ) { return $description; }
        $checked = Nova_Bridge_Suite_Recipe_Engine::validate( $recipe ); if ( is_wp_error( $checked ) ) { return $checked; }
        $custom_instructions = self::custom_instructions( $recipe ); if ( is_wp_error( $custom_instructions ) ) { return $custom_instructions; }
        $checked = self::check_scope( $recipe['blueprint'], $description, $recipe['bindings'] ?? [], $recipe['regions'] ?? [], $recipe['builder'] ); if ( is_wp_error( $checked ) ) { return $checked; }
        if ( isset( $recipe['custom_fields'] ) ) {
            if ( 'acf' === $recipe['builder'] || 'acf' !== ( $recipe['custom_fields']['builder'] ?? '' ) || isset( $recipe['custom_fields']['custom_fields'] ) ) { return self::error( 'companion', 'Use one separately certified custom-fields companion with a native page or content-field template.' ); }
            $companion = self::reviewed( [ 'recipe' => $recipe['custom_fields'], 'seal' => $body['acf_seal'] ?? '' ] ); if ( is_wp_error( $companion ) ) { return $companion; }
        }
        return $recipe;
    }
    private static function custom_instructions( array $scope, int $depth = 0 ) {
        if ( $depth > 8 ) { return self::error( 'depth', 'Content instructions exceed eight nested levels.' ); }
        foreach ( $scope['bindings'] ?? [] as $binding ) { if ( is_string( $binding['id'] ?? null ) && 0 === strpos( $binding['id'], 'nova_role_custom_' ) && ( ! is_string( $binding['instructions'] ?? null ) || '' === trim( $binding['instructions'] ) ) ) { return self::error( 'instructions', 'Explain what NOVA should fill in each custom-purpose field before previewing or saving.', 422 ); } }
        foreach ( $scope['regions'] ?? [] as $region ) { $checked = self::custom_instructions( $region['prototype'], $depth + 1 ); if ( is_wp_error( $checked ) ) { return $checked; } foreach ( $region['variants'] ?? [] as $variant ) { $checked = self::custom_instructions( $variant, $depth + 1 ); if ( is_wp_error( $checked ) ) { return $checked; } } }
        if ( isset( $scope['custom_fields'] ) ) { return self::custom_instructions( $scope['custom_fields'], $depth + 1 ); } return true;
    }
    private static function compile( array $recipe, array $content, string $seed ) {
        $result = Nova_Bridge_Suite_Recipe_Engine::compile( $recipe, $content, $seed ); if ( is_wp_error( $result ) ) { return $result; }
        if ( isset( $recipe['custom_fields'] ) ) {
            $custom = Nova_Bridge_Suite_Recipe_Engine::compile( $recipe['custom_fields'], $content, $seed . ':custom' ); if ( is_wp_error( $custom ) ) { return $custom; }
            $result['writer_payload']['meta_all']['acf'] = $custom['writer_payload']['meta_all']['acf']; $result['custom_native_document'] = $custom['native_document'];
            $result['warnings'] = array_values( array_unique( array_merge( $result['warnings'], $custom['warnings'] ) ) ); $result['content_schema'] = Nova_Bridge_Suite_Recipe_Engine::merge_content_schemas( $result['content_schema'], $custom['content_schema'] ); if ( is_wp_error( $result['content_schema'] ) ) { return $result['content_schema']; }
            $result['preview_html'] .= '<aside class="nova-custom-fields-preview"><h2>Custom field values</h2>' . $custom['preview_html'] . '</aside>';
            $result['plan_digest'] = Nova_Bridge_Suite_Recipe_Engine::digest( [ $result['native_document'], $result['custom_native_document'], $result['writer_payload'] ] );
        }
        return $result;
    }
    private static function receipt( array $recipe, array $content ): string { return hash_hmac( 'sha256', Nova_Bridge_Suite_Recipe_Engine::digest( [ $recipe, $content ] ), wp_salt( 'nonce' ) ); }
    public static function preview( $request ) {
        $body = self::body( $request ); if ( is_wp_error( $body ) ) { return $body; } $recipe = self::reviewed( $body ); if ( is_wp_error( $recipe ) ) { return $recipe; }
        $content = $body['input']['content'] ?? null; if ( ! is_array( $content ) ) { return self::error( 'sample', 'Provide example content for these fields and regions.' ); }
        $result = self::compile( $recipe, $content, 'preview:' . Nova_Bridge_Suite_Recipe_Engine::digest( $recipe ) ); if ( is_wp_error( $result ) ) { return $result; }
        $view = in_array( $body['view'] ?? '', [ 'desktop', 'mobile' ], true ) ? $body['view'] : 'desktop';
        $html = '<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="default-src \'none\'; img-src https:; style-src \'unsafe-inline\'; base-uri \'none\'; form-action \'none\'"><style>body{font:16px/1.6 system-ui;color:#181829;margin:24px}h1,h2,h3,h4{line-height:1.25}section,details{padding:16px;border:1px solid #eee;border-radius:12px;margin:12px 0}img{max-width:100%;height:auto}.elementor-hidden-' . ( 'mobile' === $view ? 'mobile' : 'desktop' ) . '{display:none}dl{padding:12px;border:1px solid #eee}dt{font-weight:600}dd{margin:4px 0 16px}</style></head><body>' . wp_kses_post( $result['preview_html'] ) . '</body></html>';
        return self::response( [ 'preview_document' => $html, 'preview_receipt' => self::receipt( $recipe, $content ), 'summary' => $result['summary'], 'content_schema' => $result['content_schema'], 'warnings' => $result['warnings'], 'engine_version' => $result['engine_version'], 'plan_digest' => $result['plan_digest'] ] );
    }
    public static function save( $request ) {
        $body = self::body( $request ); if ( is_wp_error( $body ) ) { return $body; }
        $connection = class_exists( 'Nova_Bridge_Suite_Posting_Settings' ) ? Nova_Bridge_Suite_Posting_Settings::connection() : [];
        $load = static function () use ( $request ) { return self::save_local( $request ); };
        if ( ( $body['resource_id'] ?? '' ) === 'taxonomy:product_cat' && ! empty( $connection['enabled'] ) && class_exists( 'Nova_Bridge_Suite_Static_Recipe_Adapter' ) ) {
            return self::response( Nova_Bridge_Suite_Static_Recipe_Adapter::sync_editor( $connection, $load, true ) );
        }
        if ( self::stored_recipe_supported( $body ) && ! empty( $connection['enabled'] ) && class_exists( 'Nova_Bridge_Suite_Stored_Recipe_Sync' ) ) {
            return self::response( Nova_Bridge_Suite_Stored_Recipe_Sync::sync_editor( $connection, $load, true, $body['id'] ?? '' ) );
        }
        return self::response( $load() );
    }
    /** Explicit retry reconciles the same saved revision without creating another local version. */
    public static function sync( $request ) {
        $body = self::body( $request ); if ( is_wp_error( $body ) ) { return $body; }
        if ( ! self::uuid( $body['id'] ?? null ) || ! is_int( $body['revision'] ?? null ) || $body['revision'] < 1 ) { return self::error( 'identity', 'Supply the saved template ID and revision.' ); }
        $saved = self::record( $body['id'], $body['revision'] ); if ( is_wp_error( $saved ) ) { return $saved; }
        if ( self::stored_recipe_supported( $saved ) && class_exists( 'Nova_Bridge_Suite_Stored_Recipe_Sync' ) ) {
            if ( isset( $body['replace_default'] ) && ! is_array( $body['replace_default'] ) ) { return self::error( 'default_replacement', 'Review the currently selected default before replacing it.' ); }
            return self::response( Nova_Bridge_Suite_Stored_Recipe_Sync::sync_editor( Nova_Bridge_Suite_Posting_Settings::connection(), static function () use ( $saved ) { return $saved; }, false, $body['id'], null, null, is_string( $body['recover_template_id'] ?? null ) ? $body['recover_template_id'] : '', $body['replace_default'] ?? null ) );
        }
        if ( 'taxonomy:product_cat' !== ( $saved['resource_id'] ?? '' ) ) { return self::error( 'sync_unavailable', self::posting_support( $saved )['reason'] ?: 'This saved recipe has content features that the publishing adapter cannot apply yet.', 409 ); }
        if ( ! class_exists( 'Nova_Bridge_Suite_Static_Recipe_Adapter' ) || ! class_exists( 'Nova_Bridge_Suite_Posting_Settings' ) ) { return self::error( 'sync_unavailable', 'The posting-service integration is unavailable.', 503 ); }
        return self::response( Nova_Bridge_Suite_Static_Recipe_Adapter::sync_editor( Nova_Bridge_Suite_Posting_Settings::connection(), static function () use ( $body ) { return self::record( $body['id'], $body['revision'] ); } ) );
    }
    private static function save_local( $request ) {
        $body = self::body( $request ); if ( is_wp_error( $body ) ) { return $body; } $resource = self::resource( $body['resource_id'] ?? '' ); if ( is_wp_error( $resource ) ) { return $resource; } $recipe = self::reviewed( $body ); if ( is_wp_error( $recipe ) ) { return $recipe; }
        $scope = self::fits_resource( $recipe, $resource ); if ( is_wp_error( $scope ) ) { return $scope; }
        if ( isset( $body['posting_page_type'] ) && ! in_array( $body['posting_page_type'], [ 'service', 'informative', 'category' ], true ) ) { return self::error( 'posting_page_type', 'Choose Service, Informative or Category as the NOVA page type.' ); }
        if ( 'taxonomy:product_cat' === $resource['id'] && isset( $body['posting_page_type'] ) && 'category' !== $body['posting_page_type'] ) { return self::error( 'posting_page_type', 'WooCommerce product categories use the NOVA Category page type.' ); }
        $source = class_exists( 'Nova_Bridge_Suite_Recipe_Visual' ) ? Nova_Bridge_Suite_Recipe_Visual::validate_source( $body, $recipe, $resource ) : null; if ( is_wp_error( $source ) ) { return $source; }
        $content = $body['content'] ?? null; if ( ! is_array( $content ) || ! is_string( $body['preview_receipt'] ?? null ) || ! hash_equals( self::receipt( $recipe, $content ), $body['preview_receipt'] ) ) { return self::error( 'preview', 'Preview the current template and example before saving.', 409 ); }
        $compiled = self::compile( $recipe, $content, 'save:' . Nova_Bridge_Suite_Recipe_Engine::digest( $recipe ) ); if ( is_wp_error( $compiled ) ) { return $compiled; }
        $id = $body['id'] ?? wp_generate_uuid4(); $expected = $body['expected_revision'] ?? null; $label = $body['label'] ?? '';
        if ( ! self::uuid( $id ) || ! is_int( $expected ) || $expected < 0 || ! is_string( $label ) || '' === trim( $label ) || strlen( $label ) > 200 ) { return self::error( 'identity', 'Name the template and supply its saved revision.' ); }
        $saved = self::mutate( self::OPTION, static function ( array $state ) use ( $body, $resource, $recipe, $compiled, $content, $id, $expected, $label, $source ) {
            $history = $state['profiles'][$id] ?? []; $latest = $history ? max( array_keys( $history ) ) : 0;
            $current_default = $state['defaults'][$resource['id']] ?? null; $expected_default = $body['expected_default'] ?? null;
            $identity = static function ( $entry ) { return is_array( $entry ) ? [ 'id' => $entry['id'] ?? '', 'revision' => $entry['revision'] ?? 0 ] : null; };
            if ( $identity( $current_default ) !== $identity( $expected_default ) ) { return self::error( 'default_conflict', 'Another administrator changed the creation default. Reload this destination before saving.', 409 ); }
            if ( $latest !== $expected || ( $history && $history[$latest]['resource_id'] !== $resource['id'] ) ) { return self::error( 'conflict', 'This template changed elsewhere or belongs to another destination. Reload before saving.', 409 ); }
            if ( $history && ! empty( $history[$latest]['source_reference'] ) && ! $source ) { return self::error( 'reference', 'Keep the verified example identity, or capture a new design explicitly before updating this template.', 409 ); }
            if ( count( $history ) >= 50 || ( ! $history && count( $state['profiles'] ) >= 100 ) ) { return self::error( 'history', 'The retained template history reached its limit. No history was removed.', 409 ); }
            $record = [ 'id' => $id, 'revision' => $latest + 1, 'resource_id' => $resource['id'], 'label' => sanitize_text_field( $label ), 'mode' => $recipe['mode'], 'builder' => $recipe['builder'], 'recipe' => $recipe, 'seal' => $body['seal'], 'acf_seal' => $body['acf_seal'] ?? '', 'content_schema' => $compiled['content_schema'], 'sample' => $content, 'created_at' => gmdate( 'c' ), 'created_by' => get_current_user_id() ];
            $fallback_type = self::posting_page_type( $resource['id'] );
            if ( null !== $fallback_type ) { $previous_type = ( $history[$latest]['resource_id'] ?? '' ) === $resource['id'] ? ( $history[$latest]['posting_page_type'] ?? null ) : null; $record['posting_page_type'] = $body['posting_page_type'] ?? $previous_type ?? $fallback_type; }
            if ( $source ) { $record['source_reference'] = $source; $record['source_receipt'] = $body['source_receipt']; }
            $state['profiles'][$id][$record['revision']] = $record; $state['defaults'][$resource['id']] = array_intersect_key( $record, array_flip( [ 'id', 'revision', 'label', 'mode', 'builder' ] ) ); return [ 'state' => $state, 'result' => $record + [ 'default_saved' => true, 'local_only' => true ] ];
        } ); return is_wp_error( $saved ) ? $saved : $saved + self::posting_metadata( $saved );
    }
    private static function fits_resource( array $recipe, array $resource ) {
        $builder = $recipe['builder']; $blueprint = $recipe['blueprint'];
        if ( 'core' === $builder && ( $blueprint['resource_id'] ?? '' ) !== $resource['id'] ) { return self::error( 'destination', 'Read the content design for this destination before saving.', 409 ); }
        if ( 'taxonomy' === $resource['type'] && ! in_array( $builder, [ 'core', 'acf' ], true ) ) { return self::error( 'destination', 'A page builder design cannot be used as a category field mapping.', 409 ); }
        if ( 'acf' === $builder && ( ( $blueprint['taxonomy'] ?? '' ) !== ( $resource['taxonomy'] ?? '' ) || ( $blueprint['post_type'] ?? '' ) !== ( $resource['post_type'] ?? '' ) ) ) { return self::error( 'destination', 'The declared custom fields belong to another destination.', 409 ); }
        if ( isset( $recipe['custom_fields'] ) ) { return self::fits_resource( $recipe['custom_fields'], $resource ); } return true;
    }
    private static function public_record( array $record ) {
        $record += self::posting_metadata( $record );
        $design = self::describe( $record['builder'], $record['recipe']['blueprint'] ); if ( is_wp_error( $design ) ) { return $design; }
        $design = self::decorate_design( $record['builder'], $record['mode'], [ 'blueprint' => $record['recipe']['blueprint'] ] + $design ); $design['sample'] = $record['sample']; $design['recipe_preset'] = $record['recipe'];
        if ( class_exists( 'Nova_Bridge_Suite_Recipe_Visual' ) ) { $resource = self::resource( $record['resource_id'] ); if ( ! is_wp_error( $resource ) ) { $design = Nova_Bridge_Suite_Recipe_Visual::enrich( $design, $resource, 0, $record['source_reference'] ?? null, $record['source_receipt'] ?? '' ); } } $record['design'] = $design;
        if ( ! empty( $record['source_reference']['reference_id'] ) && isset( $resource ) && ! is_wp_error( $resource ) && ( $record['design']['visual_preview']['status'] ?? '' ) === 'available' ) { $record['design'] = self::presentation_choices( $record['design'], $resource, $record['source_reference']['reference_id'] ); }
        if ( isset( $record['recipe']['custom_fields'] ) ) { $custom = $record['recipe']['custom_fields']; $record['custom_fields_design'] = self::decorate_design( 'acf', $custom['mode'], [ 'blueprint' => $custom['blueprint'] ] + Nova_Bridge_Suite_Recipe_ACF::describe( $custom['blueprint'] ) ); }
        return $record;
    }
    public static function profile( $request ) { $record = self::record( (string) $request->get_param( 'id' ) ); return self::response( is_wp_error( $record ) ? $record : self::public_record( $record ) ); }
    public static function blank( $request ) {
        $resource = self::resource( $request->get_param( 'resource_id' ) ); if ( is_wp_error( $resource ) ) { return $resource; } $identity = self::defaults()[$resource['id']] ?? null; if ( ! $identity ) { return self::error( 'default', 'Configure a creation template for this destination first.', 404 ); }
        $record = self::record( $identity['id'], $identity['revision'] ); if ( is_wp_error( $record ) ) { return $record; }
        $checked = self::reviewed( $record ); if ( is_wp_error( $checked ) ) { return $checked; }
        return self::response( [ 'id' => $record['id'], 'revision' => $record['revision'], 'resource_id' => $resource['id'], 'format' => 'nova-layout-recipe-content/v1', 'mode' => $record['mode'], 'builder' => $record['builder'], 'recipe' => $record['recipe'], 'content_schema' => $record['content_schema'], 'empty_content' => Nova_Bridge_Suite_Recipe_Engine::empty_content( $record['content_schema'] ), 'instructions' => $record['recipe']['instructions'] ?? '', 'local_only' => true ] );
    }
    public static function translate( $request ) {
        $body = self::body( $request ); if ( is_wp_error( $body ) ) { return $body; } $record = self::record( (string) ( $body['id'] ?? '' ), is_int( $body['revision'] ?? null ) ? $body['revision'] : null ); if ( is_wp_error( $record ) ) { return $record; }
        if ( ! is_int( $body['revision'] ?? null ) || $body['revision'] !== $record['revision'] || ! is_array( $body['content'] ?? null ) ) { return self::error( 'version', 'Translate an exact saved template revision and its filled content.', 409 ); }
        $checked = self::reviewed( $record ); if ( is_wp_error( $checked ) ) { return $checked; }
        return self::response( self::compile( $record['recipe'], $body['content'], 'translation:' . $record['id'] . ':' . $record['revision'] . ':' . Nova_Bridge_Suite_Recipe_Engine::digest( $body['content'] ) ) );
    }
    private static function operation( string $id ) {
        if ( ! self::uuid( $id ) ) { return self::error( 'operation', 'Choose a valid draft request.', 404 ); }
        $state = get_option( self::OPERATIONS, [] ); $entry = is_array( $state ) ? ( $state[$id] ?? null ) : null;
        if ( ! is_array( $entry ) ) { return self::error( 'operation', 'That draft request was not found.', 404 ); }
        return ( $entry['user_id'] ?? 0 ) === get_current_user_id() ? $entry : self::error( 'operation', 'This draft request belongs to another administrator.', 403 );
    }
    private static function witnesses( array $operation ): array {
        $query = new WP_Query( [ 'post_type' => $operation['post_type'], 'post_status' => 'any', 'posts_per_page' => 3, 'fields' => 'ids', 'no_found_rows' => true, 'meta_query' => [ [ 'key' => '_nova_layout_recipe_operation', 'value' => $operation['operation_id'] ], [ 'key' => '_nova_layout_recipe_operation_digest', 'value' => $operation['request_digest'] ] ] ] );
        return array_map( 'intval', $query->posts );
    }
    private static function update_operation( array $operation, string $phase, int $id = 0 ) {
        return self::mutate( self::OPERATIONS, static function ( array $state ) use ( $operation, $phase, $id ) {
            $key = $operation['operation_id']; $current = $state[$key] ?? null;
            if ( ! is_array( $current ) || ( $current['request_digest'] ?? '' ) !== $operation['request_digest'] ) { return self::error( 'operation_conflict', 'The draft request changed; no second draft was created.', 409 ); }
            $current['phase'] = $phase; $current['updated_at'] = time(); if ( $id ) { $current['post_id'] = $id; } $state[$key] = $current; return [ 'state' => $state, 'result' => $current ];
        } );
    }
    private static function verify_operation( array $operation, int $id ) {
        $post = get_post( $id );
        if ( ! $post || $post->post_type !== $operation['post_type'] || 'draft' !== $post->post_status || ! current_user_can( 'edit_post', $id ) || (string) $post->post_title !== $operation['title'] ) { return self::error( 'draft_changed', 'The generated item is no longer the expected draft. Review it before retrying.', 409 ); }
        $payload = $operation['writer_payload']; $builder = $operation['builder'];
        if ( 'core' === $builder ) { $checked = (string) $post->post_content === (string) ( $payload['content'] ?? '' ) ? true : self::error( 'draft_changed', 'The saved draft content differs from the translated content.', 409 ); }
        elseif ( 'acf' === $builder ) { $checked = true; }
        else { $checked = Nova_Bridge_Suite_Recipe_Builders::verify_saved( $builder, $id, $payload ); }
        if ( is_wp_error( $checked ) ) { return $checked; }
        if ( ! empty( $operation['template'] ) && (string) get_page_template_slug( $id ) !== $operation['template'] ) { return self::error( 'draft_template', 'The saved draft uses a different page template; review it before retrying.', 409 ); }
        if ( ! empty( $payload['meta_all'] ) ) { $checked = Nova_Bridge_Suite_Recipe_ACF::verify_saved( $id, $payload['meta_all'], $operation['template'] ?? '' ); if ( is_wp_error( $checked ) ) { return $checked; } }
        return true;
    }
    private static function draft_result( array $operation, bool $reused ) {
        return self::response( [ 'id' => (int) $operation['post_id'], 'operation_id' => $operation['operation_id'], 'status' => 'draft', 'edit_url' => get_edit_post_link( $operation['post_id'], 'raw' ), 'view_url' => get_preview_post_link( $operation['post_id'] ), 'reused' => $reused ] );
    }
    private static function recover_operation( array $operation ) {
        if ( 'complete' === $operation['phase'] && ! empty( $operation['post_id'] ) ) {
            $post = get_post( $operation['post_id'] ); if ( ! $post || 'draft' !== $post->post_status || ! current_user_can( 'edit_post', $post->ID ) ) { return self::error( 'draft_missing', 'The previously created item is no longer an editable draft; no duplicate was created.', 409 ); }
            // A user may edit the completed draft. Returning its identity never overwrites those edits.
            return self::draft_result( $operation, true );
        }
        if ( 'writing' === $operation['phase'] && time() - ( $operation['updated_at'] ?? time() ) < 60 ) { return self::error( 'draft_busy', 'This draft request is still running. Use Recover draft in a moment.', 409 ); }
        $ids = self::witnesses( $operation );
        if ( 1 !== count( $ids ) ) { return self::error( 'draft_uncertain', $ids ? 'Several drafts match this request. Review them before retrying.' : 'The request did not finish with a recoverable draft. Review its result before starting a new request.', 409 ); }
        $checked = self::verify_operation( $operation, $ids[0] ); if ( is_wp_error( $checked ) ) { return $checked; }
        $saved = self::update_operation( $operation, 'complete', $ids[0] ); return is_wp_error( $saved ) ? $saved : self::draft_result( $saved, true );
    }
    public static function recover_draft( $request ) { $operation = self::operation( (string) $request->get_param( 'operation_id' ) ); return is_wp_error( $operation ) ? $operation : self::recover_operation( $operation ); }
    public static function draft( $request ) {
        $body = self::body( $request ); if ( is_wp_error( $body ) ) { return $body; }
        $op_id = $body['operation_id'] ?? ''; $title = $body['title'] ?? ''; $revision = $body['revision'] ?? null;
        if ( ! self::uuid( $op_id ) || ! is_string( $title ) || '' === trim( $title ) || strlen( $title ) > 200 || ! is_int( $revision ) || ! is_array( $body['content'] ?? null ) ) { return self::error( 'draft', 'Name the test draft and supply a saved revision, filled content and unique draft request.' ); }
        $title = sanitize_text_field( $title ); $digest = Nova_Bridge_Suite_Recipe_Engine::digest( [ get_current_user_id(), $body['id'] ?? '', $revision, $body['content'], $title ] );
        $existing = self::operation( $op_id );
        if ( ! is_wp_error( $existing ) ) { return hash_equals( $existing['request_digest'], $digest ) ? self::recover_operation( $existing ) : self::error( 'operation_conflict', 'That draft request was already used with different content. No duplicate was created.', 409 ); }
        if ( 404 !== ( $existing->get_error_data()['status'] ?? 0 ) ) { return $existing; }
        $physical_types = function_exists( 'get_post_types' ) ? array_values( get_post_types( [], 'names' ) ) : [ 'post', 'page' ];
        $physical_statuses = function_exists( 'get_post_stati' ) ? array_values( get_post_stati( [], 'names' ) ) : [ 'draft', 'publish', 'private', 'pending', 'future', 'trash', 'auto-draft' ];
        $physical = new WP_Query( [ 'post_type' => $physical_types, 'post_status' => $physical_statuses, 'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => true, 'meta_query' => [ [ 'key' => '_nova_layout_recipe_operation', 'value' => $op_id ] ] ] );
        if ( $physical->posts ) { return self::error( 'draft_orphan', 'A native item already records this request but its journal is missing. Reconcile that item before starting another draft; no duplicate was created.', 409 ); }
        $record = self::record( (string) ( $body['id'] ?? '' ), $revision ); if ( is_wp_error( $record ) ) { return $record; }
        $resource = self::resource( $record['resource_id'] ); if ( is_wp_error( $resource ) ) { return $resource; }
        if ( 'taxonomy' === $resource['type'] ) { return self::error( 'draft', 'Categories have no draft status. This wizard does not publish category changes.', 409 ); }
        $type = $resource['post_type']; $object = get_post_type_object( $type );
        if ( ! $object || ! current_user_can( $object->cap->create_posts ?? $object->cap->edit_posts ) || ! current_user_can( $object->cap->edit_posts ) ) { return self::error( 'draft_permission', 'You cannot create drafts at this destination.', 403 ); }
        $checked = self::reviewed( $record ); if ( is_wp_error( $checked ) ) { return $checked; }
        $compiled = self::compile( $record['recipe'], $body['content'], 'draft:' . $op_id ); if ( is_wp_error( $compiled ) ) { return $compiled; }
        $payload = $compiled['writer_payload']; $template = $record['recipe']['blueprint']['template'] ?? '';
        $meta = ! empty( $payload['meta_all'] ) ? Nova_Bridge_Suite_Content_Transport::validate_payload( $payload['meta_all'], $type, 0, $template ) : null;
        if ( is_wp_error( $meta ) ) { return $meta; }
        $operation = [ 'operation_id' => $op_id, 'user_id' => get_current_user_id(), 'request_digest' => $digest, 'profile_id' => $record['id'], 'revision' => $revision, 'builder' => $record['builder'], 'post_type' => $type, 'title' => $title, 'template' => $template, 'writer_payload' => $payload, 'phase' => 'writing', 'updated_at' => time() ];
        $claimed = self::mutate( self::OPERATIONS, static function ( array $state ) use ( $op_id, $operation ) { if ( isset( $state[$op_id] ) ) { return self::error( 'draft_busy', 'Another request already claimed this draft. Use Recover draft.', 409 ); } if ( count( $state ) >= 100 ) { return self::error( 'draft_history', 'The retained draft journal reached its limit. No draft history was removed.', 409 ); } $state[$op_id] = $operation; return [ 'state' => $state, 'result' => $operation ]; } );
        if ( is_wp_error( $claimed ) ) { return $claimed; }
        $witness = static function ( $id, $post, $update ) use ( $operation ) { if ( ! $update && $post->post_type === $operation['post_type'] && $post->post_title === $operation['title'] ) { update_post_meta( $id, '_nova_layout_recipe_operation', $operation['operation_id'] ); update_post_meta( $id, '_nova_layout_recipe_operation_digest', $operation['request_digest'] ); } };
        $force_draft = static function ( $data, $postarr, $raw = [] ) use ( $operation ) { $incoming = $raw['post_title'] ?? $postarr['post_title'] ?? ''; if ( empty( $postarr['ID'] ) && ( $data['post_type'] ?? '' ) === $operation['post_type'] && in_array( $incoming, [ $operation['title'], wp_slash( $operation['title'] ) ], true ) ) { $data['post_status'] = 'draft'; $data['post_title'] = wp_slash( $operation['title'] ); } return $data; };
        add_action( 'wp_insert_post', $witness, 1, 3 ); add_filter( 'wp_insert_post_data', $force_draft, PHP_INT_MAX, 3 );
        try {
            $native_payload = $payload; unset( $native_payload['meta_all'] ); $fields = [ 'post_type' => $type, 'title' => $title ];
            if ( 'core' === $record['builder'] ) { $result = Nova_Bridge_Suite_Recipe_Core::write_draft( $native_payload, $fields ); }
            elseif ( 'acf' === $record['builder'] ) { $post_fields = [ 'post_type' => $type, 'post_status' => 'draft', 'post_title' => $title, 'post_content' => '', 'post_author' => get_current_user_id() ]; if ( $template ) { $post_fields['page_template'] = $template; } $inserted = wp_insert_post( wp_slash( $post_fields ), true ); $result = is_wp_error( $inserted ) ? $inserted : new WP_REST_Response( [ 'id' => $inserted ] ); }
            else { $result = Nova_Bridge_Suite_Recipe_Builders::write_draft( $record['builder'], $native_payload, $fields ); }
            if ( is_wp_error( $result ) ) { self::update_operation( $operation, 'uncertain' ); return $result; }
            $data = $result instanceof WP_REST_Response ? $result->get_data() : $result; $id = (int) ( $data['id'] ?? $data['post_id'] ?? 0 );
            if ( ! $id || ! in_array( $id, self::witnesses( $operation ), true ) ) { self::update_operation( $operation, 'uncertain' ); return self::error( 'draft_uncertain', 'The native writer did not return this request’s witnessed draft. Use Recover draft before retrying.', 409 ); }
            if ( $meta ) { $written = Nova_Bridge_Suite_Content_Transport::write_fields( get_post( $id ), $meta ); if ( is_wp_error( $written ) ) { self::update_operation( $operation, 'uncertain', $id ); return $written; } }
            $verified = self::verify_operation( $operation, $id ); if ( is_wp_error( $verified ) ) { self::update_operation( $operation, 'uncertain', $id ); return $verified; }
            $complete = self::update_operation( $operation, 'complete', $id ); return is_wp_error( $complete ) ? $complete : self::draft_result( $complete, false );
        } catch ( Throwable $exception ) { self::update_operation( $operation, 'uncertain' ); return self::error( 'draft_uncertain', 'Draft creation stopped before verification. Use Recover draft before retrying.', 409 ); }
        finally { remove_action( 'wp_insert_post', $witness, 1 ); remove_filter( 'wp_insert_post_data', $force_draft, PHP_INT_MAX ); }
    }
}
