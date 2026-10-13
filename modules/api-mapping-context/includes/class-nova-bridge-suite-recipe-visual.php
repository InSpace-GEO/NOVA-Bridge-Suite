<?php
/** Native provenance and authenticated visual context; never persists or mutates a page. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Recipe_Visual {
    private static function error( string $message, int $status = 409 ): WP_Error {
        return new WP_Error( 'nova_recipe_visual_source', $message, [ 'status' => $status ] );
    }
    private static function identity( array $source ): array {
        $identity = []; foreach ( [ 'resource_id', 'reference_type', 'reference_id', 'builder', 'native_digest' ] as $key ) { $identity[$key] = $source[$key] ?? null; } return $identity;
    }
    private static function receipt( array $source ): string {
        return hash_hmac( 'sha256', Nova_Bridge_Suite_Recipe_Engine::json( self::identity( $source ) ), wp_salt( 'auth' ) );
    }
    private static function entity( array $resource, int $id ) {
        $term = 'taxonomy' === $resource['type'];
        $entity = $term ? get_term( $id, $resource['taxonomy'] ) : get_post( $id );
        if ( ! $entity || is_wp_error( $entity ) || ( ! $term && $entity->post_type !== $resource['post_type'] ) || ! current_user_can( $term ? 'edit_term' : 'edit_post', $id ) ) {
            return self::error( 'This example does not belong to this destination or cannot be read with your permission.', 403 );
        }
        return $entity;
    }
    public static function resolve_url( array $resource, string $url ) {
        $parsed = wp_parse_url( trim( $url ) ); $home = wp_parse_url( home_url( '/' ) );
        if ( ! is_array( $parsed ) || ! in_array( strtolower( $parsed['scheme'] ?? '' ), [ 'http', 'https' ], true ) || isset( $parsed['user'] ) || isset( $parsed['pass'] ) || preg_replace( '/^www\./', '', strtolower( $parsed['host'] ?? '' ) ) !== preg_replace( '/^www\./', '', strtolower( $home['host'] ?? '' ) ) || ( $parsed['port'] ?? null ) !== ( $home['port'] ?? null ) ) {
            return self::error( 'Choose an example URL on this WordPress site. External URLs are not fetched.', 422 );
        }
        if ( 'taxonomy' === $resource['type'] ) {
            foreach ( $resource['targets'] ?? [] as $target ) {
                if ( ! empty( $target['url'] ) && rtrim( $target['url'], '/' ) === rtrim( trim( $url ), '/' ) ) { return (int) $target['reference_id']; }
            }
            return self::error( 'Choose this category from the example list so its exact identity is known.', 422 );
        }
        $id = function_exists( 'url_to_postid' ) ? (int) url_to_postid( trim( $url ) ) : 0;
        if ( ! $id ) { return self::error( 'That URL could not be matched to a local content item. Choose an example from the list or use its WordPress item ID.', 404 ); }
        $entity = self::entity( $resource, $id ); return is_wp_error( $entity ) ? $entity : $id;
    }
    public static function detected_builder( array $resource, int $id ) {
        if ( 'taxonomy' === $resource['type'] ) { return 'core'; }
        $detected = method_exists( 'Nova_Bridge_Suite_Recipe_Builders', 'detect_reference' ) ? Nova_Bridge_Suite_Recipe_Builders::detect_reference( $id ) : [];
        if ( is_wp_error( $detected ) ) { return $detected; }
        $detected = array_values( array_unique( (array) $detected ) );
        if ( count( $detected ) > 1 ) { return self::error( 'This example contains more than one native builder. Choose its actual builder explicitly before mapping.', 422 ); }
        if ( $detected ) { return $detected[0]; }
        $post = get_post( $id );
        return $post && function_exists( 'has_blocks' ) && has_blocks( $post->post_content ) ? 'gutenberg' : 'core';
    }
    private static function capture( array $resource, int $id, string $builder, string $mode ) {
        if ( 'core' === $builder ) { return Nova_Bridge_Suite_Recipe_Core::design( $resource, $id, $mode ); }
        if ( 'acf' === $builder ) { return Nova_Bridge_Suite_Recipe_ACF::design( $resource['post_type'] ?? '', $id, 'taxonomy' === $resource['type'] ? '' : (string) get_page_template_slug( $id ), $resource['taxonomy'] ?? '' ); }
        return Nova_Bridge_Suite_Recipe_Builders::capture( $builder, $id );
    }
    private static function source( array $resource, int $id, string $builder, array $blueprint ) {
        $entity = self::entity( $resource, $id ); if ( is_wp_error( $entity ) ) { return $entity; }
        $term = 'taxonomy' === $resource['type'];
        $url = $term ? ( function_exists( 'get_term_link' ) ? get_term_link( $entity ) : '' ) : get_permalink( $id );
        return [ 'resource_id' => $resource['id'], 'reference_type' => $term ? 'term' : 'post', 'reference_id' => $id, 'title' => $term ? $entity->name : $entity->post_title, 'url' => is_wp_error( $url ) ? '' : (string) $url, 'builder' => $builder, 'native_digest' => Nova_Bridge_Suite_Recipe_Engine::digest( $blueprint ) ];
    }
    public static function validate_source( array $body, array $recipe, array $resource, bool $fresh = true ) {
        $source = $body['source_reference'] ?? null;
        if ( null === $source ) { return null; }
        if ( ! is_array( $source ) || ! is_int( $source['reference_id'] ?? null ) || $source['reference_id'] < 1 || ( $source['resource_id'] ?? '' ) !== $resource['id'] || ( $source['builder'] ?? '' ) !== $recipe['builder'] || ( $source['reference_type'] ?? '' ) !== ( 'taxonomy' === $resource['type'] ? 'term' : 'post' ) || ( $source['native_digest'] ?? '' ) !== Nova_Bridge_Suite_Recipe_Engine::digest( $recipe['blueprint'] ) || ! is_string( $body['source_receipt'] ?? null ) || ! hash_equals( self::receipt( $source ), $body['source_receipt'] ) ) {
            return self::error( 'The captured example identity could not be verified. Choose that page again before saving.' );
        }
        $canonical = self::source( $resource, $source['reference_id'], $recipe['builder'], $recipe['blueprint'] ); if ( is_wp_error( $canonical ) ) { return $canonical; }
        if ( $fresh ) {
            $current = self::capture( $resource, $source['reference_id'], $recipe['builder'], $recipe['mode'] );
            if ( is_wp_error( $current ) || Nova_Bridge_Suite_Recipe_Engine::digest( $current['blueprint'] ?? [] ) !== $source['native_digest'] ) {
                return self::error( 'The example page or native field schema changed after capture. Capture it again and review your mappings before saving.' );
            }
        }
        return $canonical;
    }
    public static function enrich( array $design, array $resource, int $id = 0, ?array $saved_source = null, string $saved_receipt = '' ): array {
        $builder = $design['builder']; $fields = []; $preview = []; $containers = [];
        foreach ( $design['fields'] ?? [] as $field ) {
            $field['field_id'] = 'field_' . substr( hash( 'sha256', $builder . ':' . $field['target'] ), 0, 20 );
            $value = Nova_Bridge_Suite_Recipe_Engine::get( $design['blueprint'], $field['target'], $found );
            $field['current_value'] = array_key_exists( 'value', $field ) && is_scalar( $field['value'] ) ? $field['value'] : ( $found && is_scalar( $value ) ? $value : '' );
            $locator = $field['locator'] ?? [];
            $locator['strategy'] = $locator['strategy'] ?? ( in_array( $builder, [ 'elementor', 'beaver' ], true ) ? $builder : 'unbound' );
            $locator['node_id'] = $field['native_node_id'] ?? $locator['native_node_id'] ?? $locator['node_id'] ?? '';
            if ( 'native_anchor' === $locator['strategy'] ) { $locator['node_id'] = $locator['anchor'] ?? ''; }
            $locator['control_path'] = $field['control_path'] ?? $locator['control_path'] ?? [];
            $locator['element_type'] = $field['element_type'] ?? $locator['element_type'] ?? '';
            if ( 'core' === $builder ) {
                if ( '/document/title' === $field['target'] ) { $locator = [ 'strategy' => 'core', 'control_path' => [ 'taxonomy' === $resource['type'] ? 'name' : 'title' ], 'precision' => 'region' ]; }
                elseif ( '/document/sections/0/body' === $field['target'] ) { $locator = [ 'strategy' => 'core', 'control_path' => [ 'taxonomy' === $resource['type'] ? 'description' : 'content' ], 'precision' => 'document' ]; }
                else { $locator = [ 'strategy' => 'unbound', 'reason' => 'This field has no independent element on the source page.' ]; }
            }
            if ( 'acf' === $builder ) { $locator = [ 'strategy' => 'unbound', 'reason' => 'The theme decides where this custom field is rendered. Select its declared field from the inventory.' ]; }
            if ( empty( $locator['node_id'] ) && 'core' !== $locator['strategy'] ) { $locator['strategy'] = 'unbound'; }
            $field['locator'] = $locator; $fields[] = $field;
            $preview[] = array_intersect_key( $field, array_flip( [ 'field_id', 'target', 'label', 'format', 'kind', 'example', 'writable', 'protected', 'reason', 'locator' ] ) );
        }
        foreach ( $design['containers'] ?? [] as $container ) {
            $container['container_id'] = 'container_' . substr( hash( 'sha256', $builder . ':' . $container['target'] ), 0, 20 );
            $container['locator'] = $container['locator'] ?? [ 'strategy' => 'unbound' ];
            $container['locator']['node_id'] = $container['native_node_id'] ?? $container['locator']['native_node_id'] ?? $container['locator']['node_id'] ?? '';
            if ( 'native_anchor' === $container['locator']['strategy'] ) { $container['locator']['node_id'] = $container['locator']['anchor'] ?? ''; }
            $containers[] = $container;
        }
        $design['fields'] = $fields; $design['containers'] = $containers; $design['preview_fields'] = $preview; $design['preview_containers'] = $containers;
        $design['source_reference'] = null; $design['source_receipt'] = '';
        $design['visual_preview'] = [ 'status' => 'no_source', 'reason' => 'This is a saved native design or starter without an example page. Choose an existing page to map visually.' ];
        $source = $id ? self::source( $resource, $id, $builder, $design['blueprint'] ) : $saved_source;
        if ( is_wp_error( $source ) ) { $design['visual_preview'] = [ 'status' => 'unavailable', 'reason' => $source->get_error_message() ]; return $design; }
        if ( ! $source ) { return $design; }
        $source_receipt = $id ? self::receipt( $source ) : $saved_receipt;
        $design['source_reference'] = $source; $design['source_receipt'] = $source_receipt;
        $validated = self::validate_source( [ 'source_reference' => $source, 'source_receipt' => $source_receipt ], [ 'builder' => $builder, 'mode' => $design['recipe_preset']['mode'], 'blueprint' => $design['blueprint'] ], $resource );
        if ( is_wp_error( $validated ) ) { $design['visual_preview'] = [ 'status' => 'stale', 'reason' => $validated->get_error_message() ]; return $design; }
        $source = $validated; $design['source_reference'] = $source;
        if ( ! class_exists( 'Nova_Bridge_Suite_Mapping_Preview' ) || ! $source['url'] ) { $design['visual_preview'] = [ 'status' => 'unavailable', 'reason' => 'The authenticated native page preview is unavailable. Use the complete field inventory.' ]; return $design; }
        $session = wp_generate_uuid4();
        $url = Nova_Bridge_Suite_Mapping_Preview::url( $source );
        $url = add_query_arg( [ 'nova_recipe_canvas' => $session, 'nova_recipe_digest' => $source['native_digest'] ], $url );
        $design['visual_preview'] = [ 'status' => 'available', 'url' => $url, 'session_id' => $session, 'digest' => $source['native_digest'], 'reference_id' => $source['reference_id'], 'reference_type' => $source['reference_type'], 'reason' => 'Click content to choose its purpose. Site scripts and forms are disabled in this isolated design view; hidden or theme-controlled fields remain in the inventory.' ];
        foreach ( $design['preview_fields'] as &$field ) { $field['locator']['reference_id'] = $source['reference_id']; } unset( $field );
        foreach ( $design['preview_containers'] as &$container ) { $container['locator']['reference_id'] = $source['reference_id']; } unset( $container );
        return $design;
    }
}
