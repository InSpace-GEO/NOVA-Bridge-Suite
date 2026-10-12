<?php
/** Local native-document capture and creation blueprints. This adapter never writes CMS content. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Document_Mapping {
    public const FORMAT = 'nova-document-mapping/v1';
    private const MAX_BYTES = 8388608;
    private const MAX_FIELDS = 2000;
    private const MAX_TARGETS = 1000;
    private const MAX_RELEVANCE_FIELDS = 64;
    private static $excluded_taxonomies = [];

    private static function error( string $code, string $message, int $status = 409 ): WP_Error { return new WP_Error( 'nova_document_mapping_' . $code, $message, [ 'status' => $status ] ); }
    private static function fail( string $message ): void { throw new InvalidArgumentException( $message ); }
    private static function guard( callable $work ) {
        try { return $work(); }
        catch ( InvalidArgumentException $exception ) { return self::error( 'capability', $exception->getMessage() ); }
        catch ( Throwable $exception ) { return self::error( 'unavailable', 'The complete native document could not be inspected. No content or settings were changed.' ); }
    }
    private static function list_array( $value ): bool { return is_array( $value ) && ( ! $value || array_keys( $value ) === range( 0, count( $value ) - 1 ) ); }
    private static function json( $value ): string {
        $sort = static function ( $item, int $depth = 0 ) use ( &$sort ) {
            if ( $depth > 64 || is_object( $item ) || is_resource( $item ) || ( is_float( $item ) && ! is_finite( $item ) ) ) { self::fail( 'The native document contains an unsupported or excessively nested value.' ); }
            if ( is_array( $item ) ) { if ( ! self::list_array( $item ) ) { ksort( $item, SORT_STRING ); } foreach ( $item as $key => $child ) { $item[ $key ] = $sort( $child, $depth + 1 ); } }
            return $item;
        };
        $json = wp_json_encode( $sort( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( ! is_string( $json ) || strlen( $json ) > self::MAX_BYTES ) { self::fail( 'The complete mapping exceeds the eight-megabyte export limit or is not valid UTF-8.' ); }
        return $json;
    }
    private static function hash( $value ): string { return hash( 'sha256', self::json( $value ) ); }
    private static function pointer( array $parts ): string { return '/' . implode( '/', array_map( static function ( $part ) { return str_replace( [ '~', '/' ], [ '~0', '~1' ], (string) $part ); }, $parts ) ); }
    private static function parts( string $path ): array {
        if ( '' === $path || '/' !== $path[0] || strlen( $path ) > 1000 || preg_match( '/~(?![01])|[\x00-\x1f]/', $path ) || preg_match( '#(?:^|/)(?:__proto__|constructor|prototype)(?:/|$)#', $path ) ) { self::fail( 'A native field pointer is invalid.' ); }
        return array_map( static function ( $part ) { return str_replace( [ '~1', '~0' ], [ '/', '~' ], $part ); }, explode( '/', substr( $path, 1 ) ) );
    }
    private static function at( array $structure, string $path ) { $value = $structure; foreach ( self::parts( $path ) as $part ) { if ( ! is_array( $value ) || ! array_key_exists( $part, $value ) ) { self::fail( 'A discovered native field no longer exists in the complete document.' ); } $value = $value[ $part ]; } return $value; }
    private static function put( array &$structure, string $path, $value ): void {
        $parts = self::parts( $path ); $cursor = &$structure;
        foreach ( $parts as $index => $part ) { if ( ! is_array( $cursor ) || ! array_key_exists( $part, $cursor ) ) { self::fail( 'A blank field does not address an existing native value.' ); } if ( $index === count( $parts ) - 1 ) { $cursor[ $part ] = $value; } else { $cursor = &$cursor[ $part ]; } }
    }
    private static function safe_key( string $key ): bool { return '' !== $key && strlen( $key ) <= 191 && ! preg_match( '/(?:password|passwd|secret|token|credential|api[_-]?key|private[_-]?key|session|nonce)|[\x00-\x20]|^(?:__proto__|constructor|prototype)$/i', $key ); }
    private static function no_secrets( $value, int $depth = 0 ): bool {
        if ( $depth > 64 || is_object( $value ) || is_resource( $value ) ) { return false; }
        if ( is_array( $value ) ) { foreach ( $value as $key => $child ) { if ( is_string( $key ) && ! self::safe_key( $key ) && ! in_array( $child, [ '', null, [], false ], true ) ) { return false; } if ( ! self::no_secrets( $child, $depth + 1 ) ) { return false; } } }
        return true;
    }

    private static function catalog(): array {
        self::$excluded_taxonomies = [];
        $discovered = class_exists( 'Nova_Bridge_Suite_Content_Context' ) ? Nova_Bridge_Suite_Content_Context::discover_resources() : []; $resources = [];
        foreach ( $discovered['resources'] ?? [] as $resource ) { if ( is_array( $resource ) && preg_match( '/^(post_type|taxonomy):[a-z0-9_-]{1,32}$/D', $resource['id'] ?? '' ) ) { $resources[ $resource['id'] ] = $resource; } }
        global $wp_rest_server; $routes = is_object( $wp_rest_server ) && method_exists( $wp_rest_server, 'get_routes' ) ? $wp_rest_server->get_routes() : [];
        $types = function_exists( 'get_post_types' ) ? (array) get_post_types( [], 'objects' ) : [];
        foreach ( $types as $type ) {
            if ( ! is_object( $type ) || ! self::editorial_type( $type ) ) { continue; }
            $name = $type->name; $id = 'post_type:' . $name;
            if ( isset( $resources[ $id ] ) ) { continue; }
            $route = '/' . trim( ( $type->rest_namespace ?? '' ) ?: 'wp/v2', '/' ) . '/' . ( ( $type->rest_base ?? '' ) ?: $name );
            $write = self::write_schema( $routes[ $route ] ?? [], [ 'title', 'content', 'excerpt', 'meta' ] );
            $can_edit = current_user_can( $type->cap->edit_posts ?? 'edit_posts' ); $can_create = current_user_can( $type->cap->create_posts ?? $type->cap->edit_posts ?? 'edit_posts' );
            $reason = ! $can_edit || ! $can_create ? 'The current administrator lacks this post type\'s edit or create capability.' : ( ! $write['methods'] ? 'No native REST create route with editorial fields is registered for ' . $route . '.' : '' );
            $fields = self::native_schema_fields( $write['args'], [ 'title', 'content', 'excerpt' ], '' === $reason );
            $resources[ $id ] = [ 'id' => $id, 'type' => 'post_type', 'post_type' => $name, 'label' => $type->labels->name ?? $name, 'route' => $route, 'methods' => $write['methods'], 'availability' => '' === $reason ? 'available' : 'unavailable', 'fields' => $fields, 'document_mapping_reason' => $reason ];
        }
        if ( function_exists( 'get_taxonomies' ) ) {
            foreach ( (array) get_taxonomies( [], 'objects' ) as $taxonomy ) {
                $name = $taxonomy->name ?? ''; $id = 'taxonomy:' . $name;
                if ( ! self::editorial_taxonomy( $taxonomy, $types ) ) { unset( $resources[ $id ] ); continue; }
                if ( isset( $resources[ $id ] ) ) { continue; }
                $route = '/' . trim( ( $taxonomy->rest_namespace ?? '' ) ?: 'wp/v2', '/' ) . '/' . ( ( $taxonomy->rest_base ?? '' ) ?: $name );
                $write = self::write_schema( $routes[ $route ] ?? [], [ 'name' ] );
                $reason = empty( $taxonomy->show_in_rest ) ? 'This public taxonomy does not expose a native REST endpoint.' : ( ! $write['methods'] ? 'No native REST create route with a term name is registered for ' . $route . '.' : '' );
                $fields = self::native_schema_fields( $write['args'], [ 'name', 'description' ], '' === $reason );
                $resources[ $id ] = [ 'id' => $id, 'type' => 'taxonomy', 'taxonomy' => $name, 'label' => $taxonomy->labels->name ?? $name, 'route' => $route, 'methods' => $write['methods'], 'availability' => '' === $reason ? 'available' : 'unavailable', 'fields' => $fields, 'document_mapping_reason' => $reason ];
            }
        }
        foreach ( $resources as $id => &$resource ) { if ( 0 === strpos( $id, 'taxonomy:' ) ) { $relevance = self::taxonomy_relevance( substr( $id, 9 ), $resource ); if ( 'relevant' !== $relevance['status'] ) { self::$excluded_taxonomies[ $id ] = [ 'resource_id' => $id, 'status' => $relevance['status'], 'reason' => $relevance['reason'] ]; unset( $resources[ $id ] ); } else { $resource['content_relevance'] = $relevance; } } } unset( $resource );
        $labels = []; foreach ( $resources as $resource ) { $label = (string) ( $resource['label'] ?? $resource['id'] ); $labels[ $label ] = ( $labels[ $label ] ?? 0 ) + 1; }
        foreach ( $resources as &$resource ) { $label = (string) ( $resource['label'] ?? $resource['id'] ); if ( $labels[ $label ] > 1 ) { $resource['label'] = $label . ' (' . $resource['id'] . ')'; } } unset( $resource );
        return $resources;
    }
    /** Diagnostics distinguish incomplete evidence from proven-unused auxiliary archives. */
    public static function excluded_resources(): array { if ( ! current_user_can( 'manage_options' ) ) { return []; } self::catalog(); return array_values( self::$excluded_taxonomies ); }
    private static function editorial_type( $type ): bool {
        $name = $type->name ?? '';
        return preg_match( '/^[a-z0-9_-]{1,32}$/D', $name ) && ! empty( $type->public ) && ! empty( $type->show_ui ) && ! empty( $type->show_in_rest )
            && ! in_array( $name, [ 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face', 'product_variation', 'shop_order', 'shop_order_refund', 'shop_coupon', 'shop_webhook', 'shop_subscription' ], true )
            && ! preg_match( '/^(?:acf|acfe|elementor|wc)[_-]|^e-/', $name );
    }
    private static function editorial_taxonomy( $taxonomy, array $types ): bool {
        $name = $taxonomy->name ?? '';
        if ( ! preg_match( '/^[a-z0-9_-]{1,32}$/D', $name ) || empty( $taxonomy->public ) || empty( $taxonomy->show_ui ) || in_array( $name, [ 'nav_menu', 'post_format', 'link_category', 'wp_theme', 'wp_pattern_category', 'translation_priority', 'elementor_library_type', 'elementor_library_category' ], true ) || ! current_user_can( $taxonomy->cap->edit_terms ?? 'manage_categories' ) ) { return false; }
        foreach ( (array) ( $taxonomy->object_type ?? [] ) as $name ) { $type = $types[ $name ] ?? ( function_exists( 'get_post_type_object' ) ? get_post_type_object( $name ) : null ); if ( $type && self::editorial_type( $type ) ) { return true; } }
        return false;
    }
    private static function meaningful_text( $value, int $depth = 0 ): bool {
        if ( $depth > 12 ) { return false; }
        if ( is_array( $value ) ) { foreach ( $value as $child ) { if ( self::meaningful_text( $child, $depth + 1 ) ) { return true; } } return false; }
        if ( ! is_string( $value ) ) { return false; }
        $value = preg_replace( '#<(script|style|template)\b[^>]*>.*?</\1>#is', '', $value );
        $value = html_entity_decode( strip_tags( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        return 1 === preg_match( '/[^\s\x{00a0}\x{200b}\x{feff}]/u', $value );
    }
    private static function editorial_meta_name( string $key ): bool {
        return self::safe_key( $key ) && ! preg_match( '/(?:^|[_-])(?:seo|rankmath|rank_math|yoast|aioseo|robots|canonical|redirect|name|slug|url|link|permalink|route|routing|thumbnail|image|media|icon|logo|color|colour|css|style|font|size|width|height|border|padding|margin|align|order|position|id|language|locale|display|visibility|verification)(?:$|[_-])/i', $key ) && ! in_array( strtolower( $key ), [ 'name', 'title', 'url', 'link', 'term_id' ], true );
    }
    private static function editorial_text_schema( array $schema, int $depth = 0 ): bool {
        if ( $depth > 12 || ! empty( $schema['readonly'] ) || isset( $schema['enum'] ) || ! empty( $schema['format'] ) ) { return false; }
        if ( 'string' === ( $schema['type'] ?? '' ) ) { return true; }
        if ( 'array' === ( $schema['type'] ?? '' ) && is_array( $schema['items'] ?? null ) ) { return self::editorial_text_schema( $schema['items'], $depth + 1 ); }
        if ( 'object' === ( $schema['type'] ?? '' ) ) { foreach ( (array) ( $schema['properties'] ?? [] ) as $key => $child ) { if ( self::editorial_meta_name( (string) $key ) && is_array( $child ) && self::editorial_text_schema( $child, $depth + 1 ) ) { return true; } } }
        return false;
    }
    /** Auxiliary taxonomy archives enter onboarding only with actual editorial-page evidence. */
    private static function taxonomy_relevance( string $name, array $resource ): array {
        if ( 'product_cat' === $name ) { return [ 'status' => 'relevant', 'reason' => 'Product categories are a default content-page destination.' ]; }
        $registered = function_exists( 'get_registered_meta_keys' ) ? array_merge( (array) get_registered_meta_keys( 'term' ), (array) get_registered_meta_keys( 'term', $name ) ) : []; $candidates = [];
        foreach ( (array) ( $resource['fields'] ?? [] ) as $field ) {
            $path = $field['path'] ?? ''; if ( ! is_string( $path ) || empty( $field['writable'] ) || 'seo' === ( $field['source'] ?? '' ) ) { continue; }
            if ( preg_match( '#^/meta(?:_all)?/([^/]+)(?:/|$)#', $path, $match ) ) {
                $key = str_replace( [ '~1', '~0' ], [ '/', '~' ], $match[1] );
                if ( ! self::editorial_meta_name( $key ) || empty( $registered[ $key ]['single'] ) || ! self::editorial_text_schema( self::meta_schema( $registered[ $key ] ) ) ) { continue; }
                $candidates[ $key ] = true;
                $hint = $key . ' ' . ( $registered[ $key ]['description'] ?? '' ) . ' ' . ( $field['label'] ?? '' );
                if ( preg_match( '/(?:body|copy|intro|description|wysiwyg|faq|content|text|story|blurb|summary|details|paragraph|heading|headline|question|answer|editorial|bio)/i', $hint ) || in_array( $field['type'] ?? '', [ 'rich_text', 'textarea', 'wysiwyg' ], true ) ) { return [ 'status' => 'relevant', 'reason' => 'The taxonomy declares an editable custom editorial content schema.' ]; }
            } elseif ( 0 === strpos( $path, '/acf/' ) && in_array( $field['type'] ?? '', [ 'string', 'rich_text', 'textarea', 'wysiwyg' ], true ) && ! empty( $field['acf_field_key'] ) ) {
                $parts = self::parts( $path ); $key = $parts[1] ?? ''; if ( self::editorial_meta_name( $key ) && preg_match( '/(?:body|copy|intro|description|faq|content|text|story|summary|details|heading|question|answer|bio)/i', $key . ' ' . ( $field['label'] ?? '' ) ) ) { return [ 'status' => 'relevant', 'reason' => 'The taxonomy declares an editable custom editorial ACF field.' ]; }
            }
        }
        global $wpdb;
        if ( is_object( $wpdb ) && method_exists( $wpdb, 'get_var' ) && method_exists( $wpdb, 'prepare' ) && preg_match( '/^[a-zA-Z0-9_]+$/D', $wpdb->term_taxonomy ?? '' ) ) {
            $table = $wpdb->term_taxonomy;
            // Match visible text outside HTML tags; normalize nonbreaking spaces before matching.
            $description = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(LOWER(tt.description), '&nbsp;', ' '), '&#160;', ' '), '&#xa0;', ' '), %s, ' '), %s, ' '), %s, ' ')";
            $found = $wpdb->get_var( $wpdb->prepare( 'SELECT EXISTS(SELECT 1 FROM `' . $table . '` tt WHERE tt.taxonomy = %s AND ' . $description . ' REGEXP %s LIMIT 1)', $name, "\xc2\xa0", "\xe2\x80\x8b", "\xef\xbb\xbf", '(^|>)[[:space:]]*[^<[:space:]][^<]*($|<)' ) );
            if ( '1' === (string) $found ) { return [ 'status' => 'relevant', 'reason' => 'At least one native taxonomy page has filled description content.' ]; }
            if ( '0' !== (string) $found ) { return [ 'status' => 'unknown', 'reason' => 'Native content-page relevance could not be checked completely; review this taxonomy.' ]; }
            if ( $candidates && preg_match( '/^[a-zA-Z0-9_]+$/D', $wpdb->termmeta ?? '' ) ) {
                $keys = array_slice( array_keys( $candidates ), 0, self::MAX_RELEVANCE_FIELDS ); $placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
                $found = $wpdb->get_var( $wpdb->prepare( 'SELECT EXISTS(SELECT 1 FROM `' . $table . '` tt JOIN `' . $wpdb->termmeta . '` tm ON tm.term_id = tt.term_id WHERE tt.taxonomy = %s AND tm.meta_key IN (' . $placeholders . ") AND TRIM(tm.meta_value) NOT IN ('', 'a:0:{}', 'b:0;', 'N;') LIMIT 1)", ...array_merge( [ $name ], $keys ) ) );
                if ( '1' === (string) $found ) { return [ 'status' => 'relevant', 'reason' => 'A registered custom editorial field contains native taxonomy-page content.' ]; }
                if ( '0' !== (string) $found || count( $candidates ) > self::MAX_RELEVANCE_FIELDS ) { return [ 'status' => 'unknown', 'reason' => 'Custom taxonomy-page content could not be checked completely; review this taxonomy.' ]; }
            } elseif ( $candidates ) { return [ 'status' => 'unknown', 'reason' => 'The registered custom editorial content store is unavailable for inspection.' ]; }
            return [ 'status' => 'unused', 'reason' => 'No filled content-page description or declared editorial content field was found.' ];
        }
        if ( ! function_exists( 'get_terms' ) ) { return [ 'status' => 'unknown', 'reason' => 'Taxonomy content-page relevance is unavailable; review this taxonomy.' ]; }
        $terms = get_terms( [ 'taxonomy' => $name, 'hide_empty' => false, 'number' => self::MAX_TARGETS + 1, 'orderby' => 'term_id', 'order' => 'ASC' ] );
        if ( ! is_array( $terms ) || is_wp_error( $terms ) ) { return [ 'status' => 'unknown', 'reason' => 'Taxonomy content-page relevance could not be inspected.' ]; }
        foreach ( array_slice( $terms, 0, self::MAX_TARGETS ) as $term ) {
            if ( ! current_user_can( 'edit_term', $term->term_id ) ) { continue; }
            if ( self::meaningful_text( $term->description ?? '' ) ) { return [ 'status' => 'relevant', 'reason' => 'At least one native taxonomy page has filled description content.' ]; }
            foreach ( array_slice( $candidates, 0, self::MAX_RELEVANCE_FIELDS, true ) as $key => $unused ) { if ( current_user_can( 'edit_term_meta', $term->term_id, $key ) && self::meaningful_text( get_term_meta( $term->term_id, $key, true ) ) ) { return [ 'status' => 'relevant', 'reason' => 'A registered custom editorial field contains native taxonomy-page content.' ]; } }
        }
        return count( $terms ) > self::MAX_TARGETS || count( $candidates ) > self::MAX_RELEVANCE_FIELDS ? [ 'status' => 'unknown', 'reason' => 'The bounded relevance sample is incomplete; review this taxonomy rather than treating it as unused.' ] : [ 'status' => 'unused', 'reason' => 'No filled content-page description or declared editorial content field was found.' ];
    }
    /** Collection creation is deliberately required; an unrelated item callback is not proof. */
    private static function write_schema( array $handlers, array $editorial_keys ): array {
        $methods = []; $args = [];
        foreach ( $handlers as $handler ) {
            if ( ! is_array( $handler ) ) { continue; }
            $declared = $handler['methods'] ?? []; $declared = is_string( $declared ) ? preg_split( '/[ ,|]+/', $declared ) : ( self::list_array( $declared ) ? $declared : array_keys( array_filter( (array) $declared ) ) );
            if ( ! in_array( 'POST', $declared, true ) || ! is_array( $handler['args'] ?? null ) || ! array_intersect( $editorial_keys, array_keys( $handler['args'] ) ) ) { continue; }
            $methods[] = 'POST'; $args = array_merge( $args, $handler['args'] );
        }
        return [ 'methods' => array_values( array_unique( $methods ) ), 'args' => $args ];
    }
    private static function native_schema_fields( array $args, array $keys, bool $available ): array {
        $fields = []; foreach ( $keys as $key ) { $fields[ '/' . $key ] = [ 'path' => '/' . $key, 'type' => 'string', 'writable' => $available && isset( $args[ $key ] ) && empty( $args[ $key ]['readonly'] ) ]; }
        foreach ( (array) ( $args['meta']['properties'] ?? [] ) as $key => $schema ) { if ( self::safe_key( (string) $key ) && is_array( $schema ) ) { $path = self::pointer( [ 'meta', $key ] ); $fields[ $path ] = [ 'path' => $path, 'label' => (string) $key, 'type' => $schema['type'] ?? 'string', 'writable' => $available && empty( $schema['readonly'] ) ]; } }
        return $fields;
    }
    private static function resource( string $id ) {
        if ( ! preg_match( '/^(post_type|taxonomy):[a-z0-9_-]{1,32}$/D', $id ) ) { return self::error( 'resource', 'Choose a canonical discovered content resource.', 400 ); }
        $catalog = self::catalog(); return $catalog[ $id ] ?? self::error( 'resource', 'That content resource is not available to this administrator.', 404 );
    }
    private static function available( array $resource ): bool { return ( $resource['availability'] ?? $resource['status'] ?? '' ) === 'available' || ! empty( $resource['usable'] ); }
    private static function native_writable( array $resource, string $key ): bool {
        if ( ! self::available( $resource ) ) { return false; }
        foreach ( (array) ( $resource['fields'] ?? [] ) as $field ) { if ( ( $field['path'] ?? '' ) === '/' . $key && ! empty( $field['writable'] ) ) { return true; } }
        return false;
    }
    private static function unsupported_reason( string $id ): string {
        if ( 'post_type:product' === $id ) { return 'Coming soon.'; }
        if ( 'post_type:attachment' === $id ) { return 'A complete media document adapter is required; generic post fields do not represent the original file and its native media variants.'; }
        return '';
    }
    private static function supported_resource( string $id ): bool { return '' === self::unsupported_reason( $id ); }

    public static function resources(): array {
        if ( ! current_user_can( 'manage_options' ) ) { return []; }
        $result = [];
        foreach ( self::catalog() as $id => $resource ) {
            $term = 0 === strpos( $id, 'taxonomy:' ); $name = substr( $id, strpos( $id, ':' ) + 1 ); $targets = []; $complete = true;
            if ( 'post_type:product' === $id ) { $items = []; }
            elseif ( $term && function_exists( 'get_terms' ) ) { $items = get_terms( [ 'taxonomy' => $name, 'hide_empty' => false, 'number' => self::MAX_TARGETS + 1, 'orderby' => 'term_id', 'order' => 'ASC' ] ); }
            elseif ( ! $term && function_exists( 'get_posts' ) ) { $items = get_posts( [ 'post_type' => $name, 'post_status' => [ 'publish', 'draft', 'private', 'pending', 'future' ], 'numberposts' => self::MAX_TARGETS + 1, 'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => true ] ); }
            else { $items = []; $complete = false; }
            if ( is_wp_error( $items ) || ! is_array( $items ) ) { $items = []; $complete = false; }
            if ( count( $items ) > self::MAX_TARGETS ) { $complete = false; $items = array_slice( $items, 0, self::MAX_TARGETS ); }
            foreach ( $items as $item ) { $ref = (int) ( $term ? $item->term_id : $item->ID ); if ( current_user_can( $term ? 'edit_term' : 'edit_post', $ref ) ) { $targets[] = [ 'reference_type' => $term ? 'term' : 'post', 'reference_id' => $ref, 'title' => $term ? $item->name : $item->post_title, 'status' => $term ? 'term' : $item->post_status ]; } }
            $supported = self::supported_resource( $id );
            $reasons = [ $supported ? 'Templates and filled mappings are local preparation; this adapter has no native writing endpoint.' : self::unsupported_reason( $id ) ];
            if ( ! self::available( $resource ) ) { $reasons[] = (string) ( $resource['document_mapping_reason'] ?? $resource['message'] ?? 'No verified native writable endpoint is available for this resource.' ); }
            $model = self::suite_model( $id ); $native_available = false;
            if ( $model ) { $native = self::empty( $id ); $native_available = ! is_wp_error( $native ); if ( ! $native_available ) { $reasons[] = $native->get_error_message(); } }
            $status = 'post_type:product' === $id ? 'coming_soon' : ( $model ? ( $native_available ? 'mapped_by_nova' : 'nova_mapping_unavailable' ) : ( self::available( $resource ) ? 'client_configurable' : 'unavailable' ) );
            if ( 'unknown' === ( $resource['content_relevance']['status'] ?? '' ) ) { $reasons[] = $resource['content_relevance']['reason']; }
            $label = 'taxonomy:product_cat' === $id ? 'WooCommerce categories' : (string) ( $resource['label'] ?? $name );
            $result[] = [ 'id' => $id, 'resource_id' => $id, 'label' => $label, 'type' => $term ? 'taxonomy' : 'post_type', $term ? 'taxonomy' : 'post_type' => $name, 'route' => (string) ( $resource['route'] ?? '' ), 'methods' => (array) ( $resource['methods'] ?? [] ), 'availability' => self::available( $resource ) ? 'available' : 'unavailable', 'creation_supported' => self::available( $resource ) && $supported && ( ! $model || $native_available ), 'can_inspect_existing' => $supported, 'system_managed' => '' !== $model, 'native_mapping_available' => $native_available, 'native_provider' => $model ?: null, 'provider_status' => $status, 'client_configurable' => '' === $model && 'post_type:product' !== $id, 'coming_soon' => 'post_type:product' === $id, 'content_relevance' => $resource['content_relevance'] ?? null, 'native_write_supported' => false, 'reasons' => $reasons, 'targets' => $targets, 'targets_complete' => $complete ];
        }
        return $result;
    }

    private static function field( array &$fields, array $structure, string $path, string $label, string $type, bool $writable, array $extra = [] ): void {
        if ( count( $fields ) >= self::MAX_FIELDS ) { self::fail( 'This document exceeds the 2000-field complete-capture limit.' ); }
        $value = self::at( $structure, $path );
        $fields[ $path ] = array_merge( [ 'path' => $path, 'structure_path' => $path, 'label' => $label, 'type' => $type, 'value_type' => $type, 'value' => $value, 'current_value' => $value, 'writable' => $writable, 'protected' => ! $writable, 'reason' => $writable ? '' : 'Native identity, layout or unsupported content is protected.', 'instructions' => '' ], $extra );
    }
    private static function envelope( string $resource, array $target, array $provider, array $structure, array $fields, array $warnings, $native_version, string $preview = '' ): array {
        $shape = $structure;
        foreach ( $fields as $field ) { if ( $field['writable'] ) { self::put( $shape, $field['structure_path'], self::blank_value( $field['current_value'] ) ); if ( isset( $field['gutenberg_block_path'] ) ) { self::blank_gutenberg( $shape, $field ); } } }
        if ( isset( $shape['gutenberg_blocks'] ) ) { $shape['core']['content'] = serialize_blocks( $shape['gutenberg_blocks'] ); }
        if ( isset( $shape['elementor_document'] ) ) { $shape['core']['content'] = ''; }
        if ( isset( $shape['suite_template'] ) ) { $shape['core']['content'] = ''; }
        $baseline = [ 'structure_sha256' => self::hash( [ $target, $provider, $shape ] ), 'content_sha256' => self::hash( [ $target, $provider, $structure, $native_version ] ) ];
        $mapping = [ 'format' => self::FORMAT, 'mode' => 'existing', 'resource_id' => $resource, 'target' => $target, 'source_reference' => null, 'provider' => $provider, 'structure' => $structure, 'fields' => $fields, 'baseline' => $baseline, 'document_version' => $baseline['content_sha256'], 'instructions' => '', 'selected_paths' => [], 'warnings' => $warnings, 'preview_url' => $preview, 'native_write_supported' => false ];
        self::json( $mapping ); return $mapping;
    }
    private static function blank_value( $value ) { if ( is_string( $value ) ) { return ''; } if ( is_array( $value ) ) { return []; } if ( is_bool( $value ) ) { return false; } if ( is_int( $value ) || is_float( $value ) ) { return 0; } return null; }
    private static function core( $object, bool $term ): array {
        if ( $term ) { return [ 'name' => $object->name, 'description' => $object->description, 'slug' => $object->slug, 'parent' => (int) $object->parent ]; }
        return [ 'title' => $object->post_title, 'content' => $object->post_content, 'excerpt' => $object->post_excerpt, 'slug' => $object->post_name, 'status' => $object->post_status, 'parent' => (int) $object->post_parent, 'author' => (int) $object->post_author, 'menu_order' => (int) $object->menu_order, 'template' => function_exists( 'get_page_template_slug' ) ? (string) get_page_template_slug( $object->ID ) : '', 'featured_media' => function_exists( 'get_post_thumbnail_id' ) ? (int) get_post_thumbnail_id( $object->ID ) : 0 ];
    }
    private static function preview( $object, bool $term ): string {
        $url = $term ? get_term_link( $object ) : get_permalink( $object->ID ); if ( ! is_string( $url ) ) { return ''; }
        $parts = parse_url( $url ); $home = parse_url( home_url( '/' ) );
        if ( ! is_array( $parts ) || ! is_array( $home ) || ! in_array( $parts['scheme'] ?? '', [ 'http', 'https' ], true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || strtolower( $parts['host'] ?? '' ) !== strtolower( $home['host'] ?? '' ) || ( $parts['port'] ?? null ) !== ( $home['port'] ?? null ) ) { return ''; }
        return $url;
    }

    public static function read( string $resource_id, string $reference_type, int $reference_id ) {
        return self::guard( static function () use ( $resource_id, $reference_type, $reference_id ) {
            if ( ! current_user_can( 'manage_options' ) || $reference_id < 1 || ! in_array( $reference_type, [ 'post', 'term' ], true ) || ! current_user_can( 'term' === $reference_type ? 'edit_term' : 'edit_post', $reference_id ) ) { return self::error( 'permission', 'Administrator and target-edit permissions are required.', 403 ); }
            $resource = self::resource( $resource_id ); if ( is_wp_error( $resource ) ) { return $resource; }
            if ( ! self::supported_resource( $resource_id ) ) { return self::error( 'provider', self::unsupported_reason( $resource_id ) ); }
            $term = 'term' === $reference_type; $name = substr( $resource_id, strpos( $resource_id, ':' ) + 1 );
            if ( $term !== ( 0 === strpos( $resource_id, 'taxonomy:' ) ) ) { return self::error( 'target', 'The target type differs from the selected resource.', 400 ); }
            $object = $term ? get_term( $reference_id, $name ) : get_post( $reference_id );
            if ( ! $object || is_wp_error( $object ) || ( $term ? $object->taxonomy : $object->post_type ) !== $name || ( ! $term && in_array( $object->post_status, [ 'trash', 'auto-draft', 'inherit' ], true ) ) ) { return self::error( 'target', 'That editable native item is unavailable in this resource.', 404 ); }
            $target = [ 'reference_type' => $reference_type, 'reference_id' => $reference_id, $term ? 'taxonomy' : 'post_type' => $name ];
            $structure = [ 'core' => self::core( $object, $term ), 'meta' => [] ]; $fields = []; $warnings = []; $provider = [ 'id' => 'core', 'version' => (string) ( $GLOBALS['wp_version'] ?? '' ), 'complete' => true ]; $version = $term ? [] : [ $object->post_modified ?? '', $object->post_modified_gmt ?? '' ];
            // Dedicated NOVA templates own the body. Preserve their raw scaffold once,
            // without inventing generic builder aliases that would duplicate source text.
            if ( ! $term && '' === self::suite_model( $resource_id ) ) {
                $raw = get_post_meta( $reference_id, '_elementor_data', true );
                $elementor_mode = get_post_meta( $reference_id, '_elementor_edit_mode', true );
                $has_elementor_data = '' !== $raw && [] !== $raw && ! ( is_string( $raw ) && preg_match( '/^\s*\[\s*\]\s*$/D', $raw ) );
                if ( $has_elementor_data && 'builder' !== $elementor_mode ) { return self::error( 'provider', 'Stored Elementor data exists but Elementor is not the active editor. Native document authority is ambiguous; choose the intended editor before capture.' ); }
                if ( 'builder' === $elementor_mode ) {
                    if ( ! class_exists( '\SEOR_Elementor_Bridge\Elementor_Service' ) || ! defined( 'ELEMENTOR_VERSION' ) ) { return self::error( 'provider', 'The complete Elementor reader is unavailable. Enable its reviewed bridge before exporting this document.' ); }
                    $service = new \SEOR_Elementor_Bridge\Elementor_Service(); $payload = $service->get_page_payload( $reference_id, [ 'include_document' => true, 'include_fields' => true, 'include_element_map' => true ] );
                    if ( is_wp_error( $payload ) || ! is_array( $payload['document'] ?? null ) || ! self::list_array( $payload['document'] ) ) { return self::error( 'provider', 'Elementor did not return a complete ordered native document.' ); }
                    $stored = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
                    if ( ! is_array( $stored ) || self::hash( $stored ) !== self::hash( $payload['document'] ) ) { return self::error( 'provider', 'The provider document differs from its stored native data. Export requires an unambiguous complete snapshot.' ); }
                    $structure['elementor_document'] = $payload['document']; $structure['elementor_page_settings'] = get_post_meta( $reference_id, '_elementor_page_settings', true );
                    if ( ! self::no_secrets( $structure['elementor_document'] ) || ! self::no_secrets( $structure['elementor_page_settings'] ) ) { return self::error( 'sensitive', 'This provider document contains credential-like settings. A reviewed document adapter is required; no partial export was returned.' ); }
                    $provider = [ 'id' => 'elementor', 'version' => (string) ELEMENTOR_VERSION, 'complete' => true ]; $version[] = $raw;
                    $ordinals = []; self::elementor_fields( $structure['elementor_document'], [ 'elementor_document' ], $structure, $fields, $warnings, [], $ordinals );
                } elseif ( preg_match( '/\[(?:vc_|et_pb_|fusion_)/', $object->post_content ) || get_post_meta( $reference_id, '_fl_builder_enabled', true ) || get_post_meta( $reference_id, '_breakdance_data', true ) || 'on' === get_post_meta( $reference_id, '_et_pb_use_builder', true ) ) { return self::error( 'provider', 'This builder has no complete document-mapping adapter yet. Its source content was not flattened or partially exported.' ); }
                elseif ( false !== strpos( $object->post_content, '<!-- wp:' ) ) {
                    if ( ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) { return self::error( 'provider', 'The native Gutenberg parser and serializer are required.' ); }
                    $structure['gutenberg_blocks'] = parse_blocks( $object->post_content ); $structure['gutenberg_content'] = [];
                    if ( ! is_array( $structure['gutenberg_blocks'] ) ) { return self::error( 'provider', 'The complete Gutenberg document cannot be parsed.' ); }
                    $provider['id'] = 'gutenberg'; self::gutenberg_fields( $structure['gutenberg_blocks'], [ 'gutenberg_blocks' ], $structure, $fields, $warnings );
                }
            }
            foreach ( $structure['core'] as $key => $value ) {
                $writable = self::native_writable( $resource, $key ) && in_array( $key, $term ? [ 'name', 'description' ] : [ 'title', 'content', 'excerpt' ], true );
                if ( 'content' === $key && 'core' !== $provider['id'] ) { $writable = false; }
                self::field( $fields, $structure, '/core/' . $key, ucfirst( str_replace( '_', ' ', $key ) ), in_array( $key, [ 'content', 'description', 'excerpt' ], true ) ? 'rich_text' : ( is_int( $value ) ? 'integer' : 'text' ), $writable );
            }
            self::meta_fields( $resource, $object, $term, $structure, $fields, $warnings );
            if ( ! self::native_writable( $resource, 'content' ) ) { foreach ( $fields as &$field ) { if ( in_array( $field['builder'] ?? '', [ 'elementor', 'gutenberg' ], true ) ) { $field['writable'] = false; $field['protected'] = true; $field['reason'] = 'The resource has no verified editorial body writer.'; } } unset( $field ); }
            if ( ! $term ) { self::suite_fields( $resource_id, $resource, $reference_id, $structure, $fields, $provider, $warnings ); }
            elseif ( 'product_cat' === $name ) { self::woocommerce_category( $resource, $reference_id, $structure, $fields, $provider ); }
            if ( ! self::available( $resource ) ) { $warnings[] = 'The resource has no verified native write route. This is a protected local inspection, not a ready publishing adapter.'; }
            $mapping = self::envelope( $resource_id, $target, $provider, $structure, $fields, array_values( array_unique( $warnings ) ), $version, false === ( $provider['preview_read_only'] ?? true ) ? '' : self::preview( $object, $term ) );
            if ( in_array( $provider['id'], [ 'nova_blog', 'nova_service' ], true ) ) { $mapping['instructions'] = self::native_instructions(); }
            return $mapping;
        } );
    }

    private static function elementor_fields( array $nodes, array $prefix, array $structure, array &$fields, array &$warnings, array $seen, array &$ordinals, int $depth = 0 ): array {
        if ( $depth > 32 || ! self::list_array( $nodes ) ) { self::fail( 'The native Elementor tree has unsupported depth or child ordering.' ); }
        $known = [ 'heading' => [ 'title' ], 'text-editor' => [ 'editor' ], 'button' => [ 'text', 'link' ], 'image' => [ 'image', 'caption' ], 'accordion' => [ 'tabs' ] ];
        foreach ( $nodes as $index => $node ) {
            if ( ! is_array( $node ) || ! is_string( $node['id'] ?? null ) || '' === $node['id'] || isset( $seen[ $node['id'] ] ) ) { self::fail( 'Elementor node identity is missing or duplicated.' ); }
            $seen[ $node['id'] ] = true; $path = array_merge( $prefix, [ $index ] ); $widget = $node['widgetType'] ?? ''; $settings = $node['settings'] ?? [];
            if ( ( $node['elType'] ?? '' ) === 'widget' ) {
                $ordinals[ $widget ] = ( $ordinals[ $widget ] ?? 0 ) + 1;
                $names = [ 'heading' => 'Heading', 'text-editor' => 'Paragraph', 'button' => 'Button', 'image' => 'Image', 'accordion' => 'Accordion' ]; $widget_label = ( $names[ $widget ] ?? ucfirst( $widget ) ) . ' ' . $ordinals[ $widget ];
                if ( ! isset( $known[ $widget ] ) ) { $warnings[] = 'Unsupported ' . $widget . ' widget retained as opaque, protected native structure.'; }
                else { foreach ( $known[ $widget ] as $setting ) { if ( ! array_key_exists( $setting, $settings ) ) { continue; } $parts = array_merge( $path, [ 'settings', $setting ] ); $pointer = self::pointer( $parts ); $dynamic = isset( $settings['__dynamic__'][ $setting ] );
                    $label = count( $known[ $widget ] ) === 1 && ! is_array( $settings[ $setting ] ) ? $widget_label : $widget_label . ' · ' . ucfirst( $setting );
                    self::field( $fields, $structure, $pointer, $label, is_array( $settings[ $setting ] ) ? 'object' : ( 'editor' === $setting ? 'rich_text' : 'text' ), ! $dynamic && is_string( $settings[ $setting ] ), [ 'builder' => 'elementor', 'selector_data' => [ 'element_id' => $node['id'], 'path' => [ $setting ] ] ] );
                    if ( is_array( $settings[ $setting ] ) ) { self::elementor_leaves( $settings[ $setting ], $parts, $structure, $fields, $node['id'], $widget_label, [ $setting ], $dynamic ); }
                } }
            }
            if ( isset( $node['elements'] ) ) { if ( ! is_array( $node['elements'] ) ) { self::fail( 'An Elementor child document is incomplete.' ); } $seen = self::elementor_fields( $node['elements'], array_merge( $path, [ 'elements' ] ), $structure, $fields, $warnings, $seen, $ordinals, $depth + 1 ); }
        }
        return $seen;
    }
    private static function elementor_leaves( array $values, array $prefix, array $structure, array &$fields, string $id, string $widget, array $setting_path, bool $dynamic, int $depth = 0 ): void {
        if ( $depth > 12 ) { self::fail( 'An Elementor content setting is excessively nested.' ); }
        foreach ( $values as $key => $value ) {
            $parts = array_merge( $prefix, [ $key ] ); $selector = array_merge( $setting_path, [ (string) $key ] );
            if ( is_array( $value ) ) { self::elementor_leaves( $value, $parts, $structure, $fields, $id, $widget, $selector, $dynamic, $depth + 1 ); }
            elseif ( is_string( $value ) && in_array( $key, [ 'url', 'alt', 'tab_title', 'tab_content', 'text' ], true ) ) { $names = [ 'url' => 'URL', 'alt' => 'Alternative text', 'tab_title' => 'Question', 'tab_content' => 'Answer', 'text' => 'Text' ]; $row = isset( $setting_path[1] ) && ctype_digit( (string) $setting_path[1] ) ? ' · item ' . ( (int) $setting_path[1] + 1 ) : ''; self::field( $fields, $structure, self::pointer( $parts ), $widget . $row . ' · ' . $names[ $key ], in_array( $key, [ 'tab_content' ], true ) ? 'rich_text' : ( 'url' === $key ? 'url' : 'text' ), ! $dynamic, [ 'builder' => 'elementor', 'selector_data' => [ 'element_id' => $id, 'path' => $selector ] ] ); }
        }
    }
    private static function meta_fields( array $resource, $object, bool $term, array &$structure, array &$fields, array &$warnings ): void {
        if ( ! function_exists( 'get_registered_meta_keys' ) ) { return; }
        $name = $term ? $object->taxonomy : $object->post_type; $id = (int) ( $term ? $object->term_id : $object->ID );
        $registered = array_merge( (array) get_registered_meta_keys( $term ? 'term' : 'post' ), (array) get_registered_meta_keys( $term ? 'term' : 'post', $name ) );
        foreach ( (array) ( $resource['fields'] ?? [] ) as $field ) {
            $native = $field['path'] ?? ''; if ( ! is_string( $native ) || ! preg_match( '#^/meta(?:_all)?/([^/]+)(?:/.*)?$#D', $native, $match ) ) { continue; }
            $key = str_replace( [ '~1', '~0' ], [ '/', '~' ], $match[1] );
            if ( ! self::safe_key( $key ) || ! isset( $registered[ $key ] ) || empty( $registered[ $key ]['single'] ) || ! current_user_can( $term ? 'edit_term_meta' : 'edit_post_meta', $id, $key ) ) { continue; }
            $value = $term ? get_term_meta( $id, $key, true ) : get_post_meta( $id, $key, true );
            if ( ! self::no_secrets( $value ) ) { $warnings[] = 'A credential-like registered metadata value was excluded from editorial mapping fields.'; continue; }
            self::json( $value ); $structure['meta'][ $key ] = $value;
            $path = self::pointer( [ 'meta', $key ] );
            if ( ! isset( $fields[ $path ] ) ) { self::field( $fields, $structure, $path, $key, is_array( $value ) ? 'object' : (string) ( $registered[ $key ]['type'] ?? 'string' ), ! is_array( $value ) && ! empty( $field['writable'] ) && count( self::parts( $native ) ) === 2, [ 'native_path' => self::pointer( [ 'meta', $key ] ), 'native_schema' => self::meta_schema( $registered[ $key ] ) ] ); }
            $parts = self::parts( $native ); $parts[0] = 'meta';
            if ( count( $parts ) > 2 ) { self::discovered_leaves( $structure, $fields, $parts, $field ); }
        }
        self::acf_fields( $resource, $object, $term, $structure, $fields, $warnings );
    }
    private static function meta_schema( array $registration ): array {
        $rest = $registration['show_in_rest'] ?? []; $schema = is_array( $rest ) ? (array) ( $rest['schema'] ?? [] ) : [];
        if ( ! $schema ) { $schema = [ 'type' => (string) ( $registration['type'] ?? 'string' ) ]; }
        return $schema;
    }
    /** Expand only discovered concrete/wildcard leaves against real native values; no invented rows. */
    private static function discovered_leaves( array $structure, array &$fields, array $parts, array $descriptor ): void {
        $walk = static function ( $value, array $actual, array $remaining ) use ( &$walk, $structure, &$fields, $descriptor ) {
            if ( ! $remaining ) {
                $path = self::pointer( $actual ); if ( isset( $fields[ $path ] ) ) { return; }
                self::field( $fields, $structure, $path, (string) ( $descriptor['label'] ?? end( $actual ) ), is_array( $value ) ? 'object' : (string) ( $descriptor['type'] ?? 'string' ), ! is_array( $value ) && ! empty( $descriptor['writable'] ) && 'acf_fc_layout' !== end( $actual ), [ 'native_path' => $descriptor['path'] ] ); return;
            }
            $part = array_shift( $remaining ); if ( ! is_array( $value ) ) { return; }
            if ( '*' === $part ) { foreach ( $value as $key => $child ) { if ( is_int( $key ) || ctype_digit( (string) $key ) ) { $walk( $child, array_merge( $actual, [ $key ] ), $remaining ); } } }
            elseif ( array_key_exists( $part, $value ) ) { $walk( $value[ $part ], array_merge( $actual, [ $part ] ), $remaining ); }
        };
        $walk( $structure, [], $parts );
    }
    private static function acf_fields( array $resource, $object, bool $term, array &$structure, array &$fields, array &$warnings ): void {
        if ( ! function_exists( 'get_field' ) || ! function_exists( 'get_field_object' ) ) { return; }
        $id = (int) ( $term ? $object->term_id : $object->ID ); $acf_id = $term ? $object->taxonomy . '_' . $id : $id; $approved = (array) ( $resource['fields'] ?? [] ); $roots = [];
        foreach ( $approved as $descriptor ) {
            $native = $descriptor['path'] ?? ''; if ( ! is_string( $native ) || ! preg_match( '#^/acf/([^/]+)$#D', $native, $match ) || empty( $descriptor['acf_field_key'] ) ) { continue; }
            $name = str_replace( [ '~1', '~0' ], [ '/', '~' ], $match[1] );
            if ( ! self::safe_key( $name ) || ! current_user_can( $term ? 'edit_term_meta' : 'edit_post_meta', $id, $name ) ) { continue; }
            $definition = get_field_object( $descriptor['acf_field_key'], $acf_id, false, false );
            if ( ! is_array( $definition ) || ( $definition['name'] ?? '' ) !== $name || in_array( $definition['type'] ?? '', [ 'password', 'clone' ], true ) ) { continue; }
            $value = get_field( $descriptor['acf_field_key'], $acf_id, false );
            if ( ! self::no_secrets( $value ) || ! self::acf_safe_definition( $definition ) ) { $warnings[] = 'A sensitive ACF field or structured child was excluded from editorial capture.'; continue; }
            self::json( $value ); $structure['acf'][ $name ] = $value; $roots[ $name ] = true;
            self::field( $fields, $structure, self::pointer( [ 'acf', $name ] ), (string) ( $descriptor['label'] ?? $name ), is_array( $value ) ? 'object' : (string) ( $descriptor['type'] ?? 'string' ), ! is_array( $value ) && ! empty( $descriptor['writable'] ), [ 'native_path' => $native ] );
        }
        foreach ( $approved as $descriptor ) { $native = $descriptor['path'] ?? ''; if ( ! is_string( $native ) || 0 !== strpos( $native, '/acf/' ) ) { continue; } $parts = self::parts( $native ); if ( count( $parts ) > 2 && isset( $roots[ $parts[1] ] ) ) { self::discovered_leaves( $structure, $fields, $parts, $descriptor ); } }
    }
    private static function acf_safe_definition( array $field, int $depth = 0 ): bool {
        if ( $depth > 12 || 'password' === ( $field['type'] ?? '' ) || ( isset( $field['name'] ) && ! self::safe_key( (string) $field['name'] ) ) ) { return false; }
        foreach ( (array) ( $field['sub_fields'] ?? [] ) as $child ) { if ( ! is_array( $child ) || ! self::acf_safe_definition( $child, $depth + 1 ) ) { return false; } }
        foreach ( (array) ( $field['layouts'] ?? [] ) as $layout ) { foreach ( (array) ( $layout['sub_fields'] ?? [] ) as $child ) { if ( ! is_array( $child ) || ! self::acf_safe_definition( $child, $depth + 1 ) ) { return false; } } }
        return true;
    }

    private static function suite_model( string $resource ): string {
        if ( 'post_type:service_page' === $resource && class_exists( 'SEORAI\\ServicePageCPT\\Plugin', false ) ) { return 'nova_service'; }
        if ( 0 === strpos( $resource, 'post_type:' ) && class_exists( 'SEORAI\\BodycleanCPT\\Plugin', false ) && function_exists( 'nova_bridge_suite_get_managed_blog_post_types' ) ) {
            if ( in_array( substr( $resource, 10 ), (array) nova_bridge_suite_get_managed_blog_post_types(), true ) ) { return 'nova_blog'; }
        }
        return '';
    }
    private static function suite_keys( string $model ): array {
        if ( 'nova_blog' === $model ) { return [ 'blog_intro', 'blog_key_takeaways', 'blog_toc_disable', 'blog_part_1', 'blog_cta_title', 'blog_cta_copy', 'blog_cta_button_label', 'blog_cta_button_url', 'blog_cta_disable', 'blog_part_2', 'blog_faqs', 'blog_related_posts', 'blog_cta_after_related_title', 'blog_cta_after_related_copy', 'blog_cta_after_related_button_label', 'blog_cta_after_related_button_url', 'blog_cta_after_related_disable' ]; }
        return [ 'sp_hero_eyebrow', 'sp_hero_title', 'sp_hero_copy', 'sp_hero_primary_label', 'sp_hero_primary_url', 'sp_hero_secondary_label', 'sp_hero_secondary_url', 'sp_intro', 'sp_main_1', 'sp_main_2', 'sp_main_3', 'sp_table', 'sp_image_1', 'sp_image_2', 'sp_sidebar_image', 'sp_sidebar_title', 'sp_sidebar_copy', 'sp_sidebar_primary_label', 'sp_sidebar_primary_url', 'sp_sidebar_secondary_label', 'sp_sidebar_secondary_url', 'sp_cta_title', 'sp_cta_bullets', 'sp_cta_button_label', 'sp_cta_button_url', 'sp_cta_more_text', 'sp_cta_more_url', 'sp_extra_copy', 'sp_tab_1_title', 'sp_tab_1_content', 'sp_tab_2_title', 'sp_tab_2_content', 'sp_tab_3_title', 'sp_tab_3_content', 'sp_faq', 'sp_related_posts' ];
    }
    /** Capture persisted dedicated templates directly: module getters/renderers can migrate old posts. */
    private static function suite_fields( string $resource_id, array $resource, int $id, array &$structure, array &$fields, array &$provider, array &$warnings ): void {
        $model = self::suite_model( $resource_id ); if ( '' === $model ) { return; }
        $name = substr( $resource_id, 10 ); $registered = array_merge( (array) get_registered_meta_keys( 'post' ), (array) get_registered_meta_keys( 'post', $name ) ); $keys = self::suite_keys( $model );
        $required = 'nova_blog' === $model ? [ 'blog_intro', 'blog_part_1', 'blog_faqs' ] : [ 'sp_intro', 'sp_main_1', 'sp_faq' ];
        foreach ( $required as $key ) { if ( empty( $registered[ $key ]['single'] ) ) { self::fail( 'The dedicated native template metadata schema is unavailable. A generic post body is not a complete capture.' ); } }
        $structure['suite_template'] = self::suite_template( $model );
        foreach ( $keys as $key ) {
            if ( ! isset( $registered[ $key ] ) || empty( $registered[ $key ]['single'] ) ) { continue; }
            if ( $id && ! current_user_can( 'edit_post_meta', $id, $key ) ) { self::fail( 'A native dedicated-template metadata field is not readable with target-edit permission; no partial document was returned.' ); }
            $registration = $registered[ $key ]; $schema = self::meta_schema( $registration );
            $value = $id ? get_post_meta( $id, $key, true ) : self::schema_empty( $schema );
            if ( ! self::no_secrets( $value ) ) { self::fail( 'The dedicated native template contains sensitive metadata; no partial document was returned.' ); }
            $structure['meta'][ $key ] = $value; $path = self::pointer( [ 'meta', $key ] );
            // REST may intentionally hide global-CTA or inactive component slots. Keep them protected.
            $writable = self::available( $resource ) && ! empty( $registration['show_in_rest'] ) && self::suite_key_active( $model, $name, $key, $structure['suite_template']['settings'] );
            if ( ! $id && isset( $registration['auth_callback'] ) && '__return_true' !== $registration['auth_callback'] ) { $writable = false; }
            $label = ucwords( str_replace( '_', ' ', preg_replace( '/^(?:blog|sp)_/', '', $key ) ) );
            self::field( $fields, $structure, $path, $label, (string) ( $registration['type'] ?? 'string' ), false, [ 'native_path' => $path, 'native_schema' => $schema, 'native_provider' => $model ] );
            self::schema_content_fields( $structure, $fields, $path, $schema, $writable, $label, $model, preg_match( '/_url$/D', $key ) ? 'url' : '' );
            if ( ! $id && $writable && in_array( $key, [ 'blog_key_takeaways', 'blog_faqs', 'sp_faq', 'sp_cta_bullets', 'sp_table' ], true ) ) {
                $strict = self::editorial_array_schema( $schema );
                if ( $strict ) { self::field( $fields, $structure, $path, $label, 'array', true, [ 'native_path' => $path, 'native_schema' => $strict, 'native_provider' => $model, 'source_free_editorial_array' => true, 'instructions' => 'Supply only the declared native editorial rows and text values. Preserve the item schema; do not add native IDs, styles or extra properties.' ] ); }
            }
        }
        foreach ( $fields as &$field ) { if ( '/core/content' === $field['path'] || isset( $field['builder'] ) ) { $field['writable'] = false; $field['protected'] = true; $field['reason'] = 'The dedicated native template renders its own metadata slots; this body scaffold remains protected.'; } } unset( $field );
        $provider = array_merge( $provider, [ 'id' => $model, 'native_body_provider' => $provider['id'], 'template_sha256' => self::hash( $structure['suite_template'] ), 'preview_read_only' => false ] );
        $warnings[] = 'Dedicated native template, layout settings and metadata are captured without rendering or running legacy migration. Frontend preview is disabled because rendering may migrate legacy content.';
    }
    private static function native_instructions(): string { return 'NOVA supplies this native mapping. Fill only the selected editorial values and declared content arrays. Keep native layout, global CTA content, media references, related-item IDs, routing, toggles and all read-only identities unchanged. The new item remains a draft; this document does not write or publish native content.'; }
    private static function editorial_array_schema( array $schema, int $depth = 0 ): array {
        if ( $depth > 8 || ! empty( $schema['readonly'] ) ) { return []; }
        $type = $schema['type'] ?? '';
        if ( 'string' === $type ) { return $schema; }
        if ( 'array' === $type && is_array( $schema['items'] ?? null ) ) { $items = self::editorial_array_schema( $schema['items'], $depth + 1 ); if ( ! $items ) { return []; } $schema['items'] = $items; $schema['maxItems'] = min( 200, (int) ( $schema['maxItems'] ?? 200 ) ); return $schema; }
        if ( 'object' === $type && ! empty( $schema['properties'] ) ) {
            foreach ( $schema['properties'] as $key => $child ) { if ( ! self::editorial_meta_name( (string) $key ) || ! is_array( $child ) ) { return []; } $strict = self::editorial_array_schema( $child, $depth + 1 ); if ( ! $strict ) { return []; } $schema['properties'][ $key ] = $strict; }
            $schema['additionalProperties'] = false; $schema['required'] = array_keys( $schema['properties'] ); return $schema;
        }
        return [];
    }
    private static function editorial_array_value( $value, array $schema ): bool {
        if ( 'string' === ( $schema['type'] ?? '' ) ) { return is_string( $value ) && false === strpos( $value, "\0" ) && 1 === preg_match( '//u', $value ); }
        if ( 'array' === ( $schema['type'] ?? '' ) ) { if ( ! self::list_array( $value ) || count( $value ) > ( $schema['maxItems'] ?? 200 ) ) { return false; } foreach ( $value as $child ) { if ( ! self::editorial_array_value( $child, $schema['items'] ) ) { return false; } } return true; }
        if ( 'object' === ( $schema['type'] ?? '' ) ) { if ( ! is_array( $value ) || array_diff( array_keys( $value ), array_keys( $schema['properties'] ) ) || array_diff( $schema['required'], array_keys( $value ) ) ) { return false; } foreach ( $value as $key => $child ) { if ( ! self::editorial_array_value( $child, $schema['properties'][ $key ] ) ) { return false; } } return true; }
        return false;
    }
    private static function suite_key_active( string $model, string $type, string $key, array $settings ): bool {
        if ( 'nova_blog' === $model ) {
            $map = [ 'blog_intro' => [ 'intro' ], 'blog_key_takeaways' => [ 'content_1', 'key_takeaways' ], 'blog_part_1' => [ 'content_1' ], 'blog_part_2' => [ 'content_2' ], 'blog_faqs' => [ 'faq' ] ];
            if ( ! isset( $map[ $key ] ) ) { return false; }
            $components = (array) ( $settings['quarantined_cpt_bodyclean_components'] ?? [] ); $overrides = (array) ( $settings['quarantined_cpt_bodyclean_components_by_cpt'][ $type ] ?? [] );
            foreach ( $map[ $key ] as $component ) { if ( array_key_exists( $component, $overrides ) ? ! $overrides[ $component ] : ( array_key_exists( $component, $components ) && ! $components[ $component ] ) ) { return false; } }
            return true;
        }
        $template = $settings['service_cpt_template'] ?? 'service-page-1-column';
        $layouts = [ 'service-page-1-column' => [ 'hero', 'intro', 'content', 'cta_wide', 'faq', 'related' ], 'service-page-2' => [ 'hero', 'intro', 'image_text', 'text_image', 'cta_wide', 'faq', 'content', 'related' ], 'service-page-3' => [ 'hero', 'intro', 'content', 'cta_cover', 'cta_wide', 'tabs', 'faq' ] ];
        if ( ! isset( $layouts[ $template ] ) ) { $template = 'service-page-1-column'; }
        $saved = (array) ( $settings['service_cpt_template_components'] ?? [] ); if ( isset( $saved[ $template ] ) && is_array( $saved[ $template ] ) ) { $saved = $saved[ $template ]; }
        $active = []; foreach ( $layouts[ $template ] as $component ) { if ( ! array_key_exists( $component, $saved ) || $saved[ $component ] ) { $active[] = $component; } }
        $component = 0 === strpos( $key, 'sp_hero_' ) ? 'hero' : ( 'sp_intro' === $key ? 'intro' : ( preg_match( '/^sp_(?:main_|extra_copy|table)/', $key ) ? 'content' : ( 0 === strpos( $key, 'sp_sidebar_' ) ? 'cta_cover' : ( 0 === strpos( $key, 'sp_cta_' ) ? 'cta_wide' : ( 0 === strpos( $key, 'sp_tab_' ) ? 'tabs' : ( 'sp_faq' === $key ? 'faq' : ( 'sp_related_posts' === $key ? 'related' : '' ) ) ) ) ) ) );
        if ( 'content' === $component && ( in_array( 'image_text', $active, true ) || in_array( 'text_image', $active, true ) ) ) { $active[] = 'content'; }
        if ( '' === $component || ! in_array( $component, $active, true ) || ( 'service-page-1-column' === $template && 'sp_main_3' === $key ) || ( 'service-page-3' !== $template && in_array( $key, [ 'sp_table', 'sp_extra_copy' ], true ) ) ) { return false; }
        // Global CTA content intentionally replaces per-item CTA controls.
        $global = 0 === strpos( $key, 'sp_sidebar_' ) ? 'sidebar' : ( 0 === strpos( $key, 'sp_cta_' ) ? 'cta' : ( preg_match( '/^sp_hero_(?:primary|secondary)_/', $key ) ? 'hero' : '' ) );
        if ( $global ) { foreach ( $settings as $name => $value ) { if ( 0 === strpos( $name, 'service_cpt_global_' . $global . '_' ) && null !== $value && '' !== $value && 0 !== $value && false !== $value && [] !== $value ) { return false; } } }
        return true;
    }
    private static function schema_empty( array $schema ) { $type = $schema['type'] ?? 'string'; if ( in_array( $type, [ 'array', 'object' ], true ) ) { return []; } if ( 'boolean' === $type ) { return false; } if ( in_array( $type, [ 'integer', 'number' ], true ) ) { return 0; } return ''; }
    private static function schema_content_fields( array $structure, array &$fields, string $path, array $schema, bool $writable, string $label, string $provider, string $format = '', int $depth = 0 ): void {
        if ( $depth > 12 ) { self::fail( 'A native dedicated-template field schema exceeds the supported nesting limit.' ); }
        $value = self::at( $structure, $path ); $type = $schema['type'] ?? 'string';
        if ( 'array' === $type && is_array( $value ) && isset( $schema['items'] ) && is_array( $schema['items'] ) ) { foreach ( $value as $index => $child ) { if ( ! is_int( $index ) && ! ctype_digit( (string) $index ) ) { continue; } self::schema_content_fields( $structure, $fields, $path . '/' . $index, $schema['items'], $writable, $label . ' · item ' . ( (int) $index + 1 ), $provider, '', $depth + 1 ); } return; }
        if ( 'object' === $type && is_array( $value ) ) { foreach ( (array) ( $schema['properties'] ?? [] ) as $key => $child_schema ) { if ( self::safe_key( (string) $key ) && is_array( $child_schema ) && array_key_exists( $key, $value ) ) { self::schema_content_fields( $structure, $fields, $path . '/' . str_replace( [ '~', '/' ], [ '~0', '~1' ], $key ), $child_schema, $writable && empty( $child_schema['readonly'] ), $label . ' · ' . ucfirst( $key ), $provider, '', $depth + 1 ); } } return; }
        if ( 'string' !== $type || is_array( $value ) ) { return; }
        $format = $format ?: ( $schema['format'] ?? '' ); $kind = in_array( $format, [ 'url', 'uri', 'uri-reference' ], true ) ? 'url' : ( 'email' === $format ? 'email' : 'rich_text' );
        $notes = is_string( $schema['description'] ?? null ) && '' !== $schema['description'] ? $schema['description'] : 'Fill the native ' . $label . ' editorial slot. Keep its declared value type and native position.';
        self::field( $fields, $structure, $path, $label, $kind, $writable && empty( $schema['readonly'] ), [ 'native_path' => $path, 'native_schema' => $schema, 'native_provider' => $provider, 'instructions' => $notes ] );
    }
    private static function suite_template( string $model ): array {
        $base = dirname( __DIR__, 2 ); $options = []; $artifacts = [];
        if ( 'nova_blog' === $model ) {
            $prefix = 'quarantined_cpt_bodyclean_'; $names = [ 'components', 'component_order', 'components_by_cpt', 'component_order_by_cpt', 'exclude_selectors', 'allow_selectors', 'header_offset', 'breadcrumb_separator', 'enable', 'schema_type', 'blog_style_preset', 'blog_content_max_width', 'blog_text_color', 'blog_link_color', 'blog_link_hover_color', 'blog_panel_bg', 'blog_panel_border', 'blog_meta_border', 'blog_share_bg', 'blog_share_border', 'blog_cta_bg', 'blog_cta_button_bg', 'blog_cta_button_text', 'blog_cta_button_hover', 'blog_cta_by_cpt', 'blog_style_by_cpt', 'blog_size_settings', 'blog_custom_css', 'blog_author_box_bg', 'blog_author_box_border', 'blog_card_radius' ];
            foreach ( [ 'primary', 'after_related' ] as $cta ) { foreach ( [ 'title', 'copy', 'button_label', 'button_url' ] as $key ) { $names[] = 'blog_cta_' . $cta . '_' . $key; } }
            foreach ( [ 'author', 'language', 'key_takeaways', 'toc', 'toc_read_more', 'toc_read_less', 'related_articles', 'faq_title', 'publications' ] as $label ) { $names[] = 'label_' . $label; }
            $files = [ 'cpt/quarantined-cpt.php', 'cpt/templates/single-quarantined-page.php' ];
        } else {
            $prefix = 'service_cpt_'; $names = [ 'template', 'template_components', 'template_component_order', 'components', 'exclude_selectors', 'header_offset', 'content_width', 'wide_width', 'space_scale', 'space_section_padding', 'space_section_gap', 'space_card_padding', 'label_faq', 'label_related', 'label_language' ];
            foreach ( [ 'primary', 'contrast', 'surface', 'text', 'accent', 'border', 'hero_bg', 'hero_text', 'cta_bg', 'cta_text', 'sidebar_cta_bg', 'sidebar_cta_text', 'wide_cta_bg', 'wide_cta_text', 'button_bg', 'button_text', 'button_outline', 'faq_bg', 'faq_question', 'faq_answer', 'tabs_active_bg', 'tabs_active_text', 'tabs_inactive_bg', 'tabs_inactive_text', 'tabs_border' ] as $color ) { $names[] = 'color_' . $color; }
            foreach ( [ 'hero_primary_label', 'hero_primary_url', 'hero_secondary_label', 'hero_secondary_url', 'hero_background_image', 'sidebar_title', 'sidebar_copy', 'sidebar_image', 'sidebar_primary_label', 'sidebar_primary_url', 'sidebar_secondary_label', 'sidebar_secondary_url', 'cta_title', 'cta_bullet_1', 'cta_bullet_2', 'cta_bullet_3', 'cta_button_label', 'cta_button_url', 'cta_more_text', 'cta_more_url' ] as $cta ) { $names[] = 'global_' . $cta; }
            $files = [ 'service-page-cpt/service-page-cpt.php' ]; foreach ( glob( $base . '/service-page-cpt/templates/layouts/*.html' ) ?: [] as $file ) { $files[] = substr( str_replace( '\\', '/', $file ), strlen( str_replace( '\\', '/', $base ) ) + 1 ); }
        }
        foreach ( $names as $name ) {
            $key = $prefix . $name; $value = get_option( $key, null ); if ( ! self::no_secrets( $value ) ) { self::fail( 'A native template setting contains sensitive or unsupported data.' ); } $options[ $key ] = $value;
            if ( class_exists( 'Nova_Bridge_Suite_WPML_Support' ) ) { $localized = Nova_Bridge_Suite_WPML_Support::get_localized_option_name( $key ); if ( is_string( $localized ) && $localized !== $key ) { $value = get_option( $localized, null ); if ( ! self::no_secrets( $value ) ) { self::fail( 'A localized native template setting contains sensitive data.' ); } $options[ $localized ] = $value; } }
        }
        foreach ( $files as $file ) { $hash = is_file( $base . '/' . $file ) ? hash_file( 'sha256', $base . '/' . $file ) : false; if ( ! is_string( $hash ) ) { self::fail( 'The dedicated native template artifact is unavailable; complete versioned capture is required.' ); } $artifacts[ $file ] = $hash; }
        $language = class_exists( 'Nova_Bridge_Suite_WPML_Support' ) && method_exists( 'Nova_Bridge_Suite_WPML_Support', 'get_current_language_code' ) ? Nova_Bridge_Suite_WPML_Support::get_current_language_code() : '';
        return [ 'provider' => $model, 'kind' => 'native_module_template', 'language_scope' => $language ?: 'site_default', 'site_locale' => function_exists( 'get_locale' ) ? get_locale() : '', 'artifacts_sha256' => $artifacts, 'settings' => $options, 'settings_read_only' => true ];
    }
    private static function woocommerce_category( array $resource, int $id, array &$structure, array &$fields, array &$provider ): void {
        $structure['woocommerce_category'] = []; foreach ( [ 'display_type', 'thumbnail_id', 'order' ] as $key ) { $structure['woocommerce_category'][ $key ] = $id ? get_term_meta( $id, $key, true ) : ''; self::field( $fields, $structure, '/woocommerce_category/' . $key, ucfirst( str_replace( '_', ' ', $key ) ), 'text', false ); }
        $registered = (array) get_registered_meta_keys( 'term', 'product_cat' ); $key = 'content_below_products';
        if ( ! empty( $registered[ $key ]['single'] ) && ( ! $id || current_user_can( 'edit_term_meta', $id, $key ) ) ) {
            $structure['meta'][ $key ] = $id ? get_term_meta( $id, $key, true ) : ''; $path = '/meta/' . $key;
            self::field( $fields, $structure, $path, 'Content below products', 'rich_text', self::available( $resource ) && ! empty( $registered[ $key ]['show_in_rest'] ), [ 'native_path' => '/' . $key, 'native_schema' => self::meta_schema( $registered[ $key ] ) ] );
        }
        $provider = array_merge( $provider, [ 'id' => 'woocommerce_category', 'version' => defined( 'WC_VERSION' ) ? (string) WC_VERSION : $provider['version'] ] );
    }

    private static function html_root( string $html, string $tag ) {
        if ( ! class_exists( 'DOMDocument' ) ) { self::fail( 'The DOM extension is required for lossless Gutenberg text preparation.' ); }
        $before = libxml_use_internal_errors( true ); $document = new DOMDocument();
        try { if ( ! $document->loadHTML( '<!doctype html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>', LIBXML_NONET ) ) { return null; } }
        finally { libxml_clear_errors(); libxml_use_internal_errors( $before ); }
        $body = $document->getElementsByTagName( 'body' )->item( 0 ); $roots = [];
        foreach ( $body->childNodes as $node ) { if ( $node instanceof DOMElement ) { $roots[] = $node; } elseif ( XML_TEXT_NODE === $node->nodeType && '' !== trim( $node->nodeValue ) ) { return null; } }
        return 1 === count( $roots ) && strtolower( $roots[0]->tagName ) === $tag ? [ $document, $roots[0] ] : null;
    }
    /** DOM's HTML serializer omits empty </li> tags; native Gutenberg requires explicit wrappers. */
    private static function html_node( DOMNode $node ): string {
        if ( $node instanceof DOMElement ) {
            $tag = strtolower( $node->tagName ); $html = '<' . $tag;
            foreach ( $node->attributes as $attribute ) { $html .= ' ' . $attribute->name . '="' . htmlspecialchars( $attribute->value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '"'; }
            $html .= '>'; if ( in_array( $tag, [ 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr' ], true ) ) { return $html; }
            return $html . self::inner( $node ) . '</' . $tag . '>';
        }
        if ( XML_TEXT_NODE === $node->nodeType ) { return htmlspecialchars( $node->nodeValue, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
        return $node->ownerDocument->saveHTML( $node );
    }
    private static function inner( DOMNode $node ): string { $html = ''; foreach ( $node->childNodes as $child ) { $html .= self::html_node( $child ); } return $html; }
    private static function gutenberg_fields( array $blocks, array $prefix, array &$structure, array &$fields, array &$warnings, int $depth = 0 ): void {
        if ( $depth > 32 || ! self::list_array( $blocks ) ) { self::fail( 'The Gutenberg block tree has unsupported depth or ordering.' ); }
        foreach ( $blocks as $index => $block ) {
            if ( ! is_array( $block ) || ! is_array( $block['innerBlocks'] ?? null ) || ! is_array( $block['innerContent'] ?? null ) || ! is_string( $block['innerHTML'] ?? null ) ) { self::fail( 'The Gutenberg block tree is incomplete.' ); }
            $path = array_merge( $prefix, [ $index ] ); $name = $block['blockName'] ?? ''; $tag = '';
            if ( ! $block['innerBlocks'] ) {
                if ( 'core/paragraph' === $name ) { $tag = 'p'; }
                elseif ( 'core/heading' === $name ) { $tag = 'h' . (int) ( $block['attrs']['level'] ?? 2 ); }
                elseif ( 'core/list-item' === $name ) { $tag = 'li'; }
                elseif ( 'core/list' === $name ) { $tag = ! empty( $block['attrs']['ordered'] ) ? 'ol' : 'ul'; }
            }
            $parsed = $tag ? self::html_root( $block['innerHTML'], $tag ) : null;
            if ( $parsed ) {
                $nodes = 'core/list' === $name ? iterator_to_array( $parsed[1]->childNodes ) : [ $parsed[1] ]; $ordinal = 0;
                foreach ( $nodes as $node ) {
                    if ( ! $node instanceof DOMElement || ( 'core/list' === $name && 'li' !== strtolower( $node->tagName ) ) ) { continue; }
                    $item = $ordinal++; if ( 'core/list' === $name && ( $node->getElementsByTagName( 'ul' )->length || $node->getElementsByTagName( 'ol' )->length ) ) { $warnings[] = 'A legacy nested Gutenberg list item is retained as protected HTML; its surrounding list is not flattened.'; continue; }
                    $slot = 'block_' . substr( hash( 'sha256', self::pointer( $path ) . ':' . $item ), 0, 20 ); $structure['gutenberg_content'][ $slot ] = self::inner( $node );
                    self::field( $fields, $structure, '/gutenberg_content/' . $slot, ucfirst( substr( $name, 5 ) ) . ' ' . ( $index + 1 ) . ( 'core/list' === $name ? ' · item ' . ( $item + 1 ) : '' ), 'rich_text', true, [ 'builder' => 'gutenberg', 'gutenberg_block_path' => self::pointer( $path ), 'gutenberg_tag' => $tag, 'gutenberg_item' => 'core/list' === $name ? $item : null ] );
                }
            } elseif ( ! in_array( $name, [ 'core/group', 'core/columns', 'core/column', 'core/list', 'core/list-item' ], true ) ) { $warnings[] = 'Unsupported or dynamic Gutenberg block retained as opaque, protected native structure: ' . ( $name ?: 'freeform' ); }
            self::gutenberg_fields( $block['innerBlocks'], array_merge( $path, [ 'innerBlocks' ] ), $structure, $fields, $warnings, $depth + 1 );
        }
    }
    private static function blank_gutenberg( array &$structure, array $field ): void {
        $block = self::at( $structure, $field['gutenberg_block_path'] ); $parsed = self::html_root( $block['innerHTML'], $field['gutenberg_tag'] );
        if ( ! $parsed || $block['innerBlocks'] ) { self::fail( 'This Gutenberg field cannot be blanked without changing its block structure.' ); }
        $node = $parsed[1];
        if ( null !== $field['gutenberg_item'] ) { $items = []; foreach ( $node->childNodes as $child ) { if ( $child instanceof DOMElement && 'li' === strtolower( $child->tagName ) ) { $items[] = $child; } } $node = $items[ $field['gutenberg_item'] ] ?? null; }
        if ( ! $node ) { self::fail( 'The selected Gutenberg text node no longer exists.' ); }
        while ( $node->firstChild ) { $node->removeChild( $node->firstChild ); }
        $html = self::html_node( $parsed[1] );
        $block['innerHTML'] = $html; $block['innerContent'] = [ $html ]; self::put( $structure, $field['gutenberg_block_path'], $block );
    }

    public static function empty( string $resource_id ) {
        return self::guard( static function () use ( $resource_id ) {
            if ( ! current_user_can( 'manage_options' ) ) { return self::error( 'permission', 'Administrator access is required.', 403 ); }
            $resource = self::resource( $resource_id ); if ( is_wp_error( $resource ) ) { return $resource; }
            if ( ! self::supported_resource( $resource_id ) ) { return self::error( 'provider', self::unsupported_reason( $resource_id ) ); }
            if ( ! self::available( $resource ) ) { return self::error( 'resource', 'An empty creation blueprint requires a verified native content endpoint.' ); }
            $term = 0 === strpos( $resource_id, 'taxonomy:' ); $name = substr( $resource_id, strpos( $resource_id, ':' ) + 1 );
            $target = [ 'reference_type' => $term ? 'term' : 'post', 'reference_id' => 0, $term ? 'taxonomy' : 'post_type' => $name ];
            $structure = [ 'core' => $term ? [ 'name' => '', 'description' => '', 'slug' => '', 'parent' => 0 ] : [ 'title' => '', 'content' => '', 'excerpt' => '', 'slug' => '', 'status' => 'draft', 'parent' => 0, 'author' => 0, 'menu_order' => 0, 'template' => '', 'featured_media' => 0 ], 'meta' => [] ]; $fields = [];
            foreach ( $structure['core'] as $key => $value ) { self::field( $fields, $structure, '/core/' . $key, ucfirst( $key ), in_array( $key, [ 'content', 'excerpt', 'description' ], true ) ? 'rich_text' : ( is_int( $value ) ? 'integer' : 'text' ), self::native_writable( $resource, $key ) && in_array( $key, $term ? [ 'name', 'description' ] : [ 'title', 'content', 'excerpt' ], true ) ); }
            $provider = [ 'id' => 'core', 'version' => (string) ( $GLOBALS['wp_version'] ?? '' ), 'complete' => true ]; $warnings = [ 'Standard native fields only. No sample content or repeater rows were invented.' ];
            if ( ! $term ) { self::suite_fields( $resource_id, $resource, 0, $structure, $fields, $provider, $warnings ); }
            elseif ( 'product_cat' === $name ) { self::woocommerce_category( $resource, 0, $structure, $fields, $provider ); }
            $mapping = self::envelope( $resource_id, $target, $provider, $structure, $fields, $warnings, [] );
            $mapping['mode'] = 'creation'; if ( in_array( $provider['id'], [ 'nova_blog', 'nova_service' ], true ) ) { $mapping['instructions'] = self::native_instructions(); } return $mapping;
        } );
    }
    public static function blank( array $filled, array $selected_paths, string $instructions = '' ) {
        return self::guard( static function () use ( $filled, $selected_paths, $instructions ) {
            $checked = self::validate( $filled ); if ( is_wp_error( $checked ) ) { return $checked; }
            if ( 'creation' === $filled['mode'] && $filled['source_reference'] ) { return self::error( 'selection', 'Start authoring a creation blueprint from a fresh filled source mapping.', 400 ); }
            self::instructions( $instructions );
            foreach ( $filled['fields'] as $field ) { if ( self::hash( $field['value'] ) !== self::hash( $field['current_value'] ) ) { return self::error( 'selection', 'Creation authoring requires the original full source values, not a generation result.', 400 ); } }
            $mapping = self::trusted_blank( $filled, $selected_paths ); $mapping['instructions'] = '' === $instructions && in_array( $filled['provider']['id'], [ 'nova_blog', 'nova_service' ], true ) ? $filled['instructions'] : $instructions;
            self::json( $mapping ); return $mapping;
        } );
    }
    /** Blank native locations first, then refresh every alias and protected parent value. */
    private static function trusted_blank( array $filled, array $selected_paths ): array {
        if ( ! self::list_array( $selected_paths ) || count( $selected_paths ) > self::MAX_FIELDS || count( array_unique( $selected_paths, SORT_REGULAR ) ) !== count( $selected_paths ) ) { self::fail( 'Select unique existing writable native field paths.' ); }
        $mapping = $filled; $mapping['mode'] = 'creation'; $mapping['source_reference'] = $filled['mode'] === 'existing' ? $filled['target'] : $filled['source_reference']; $mapping['target']['reference_id'] = 0; $mapping['preview_url'] = ''; $mapping['selected_paths'] = $selected_paths;
        $elementor_content_selected = false; $suite_content_selected = false;
        foreach ( $selected_paths as $path ) {
            if ( ! is_string( $path ) || ! isset( $mapping['fields'][ $path ] ) || ! $mapping['fields'][ $path ]['writable'] || $mapping['fields'][ $path ]['protected'] ) { self::fail( 'A selected field is missing, unavailable or protected. No native content was changed.' ); }
            $field = $mapping['fields'][ $path ]; self::put( $mapping['structure'], $field['structure_path'], self::blank_value( $field['current_value'] ) );
            if ( isset( $field['gutenberg_block_path'] ) ) { self::blank_gutenberg( $mapping['structure'], $field ); }
            if ( 'elementor' === ( $field['builder'] ?? '' ) ) { $elementor_content_selected = true; }
            if ( isset( $field['native_provider'] ) ) { $suite_content_selected = true; }
        }
        if ( isset( $mapping['structure']['gutenberg_blocks'] ) && $selected_paths ) { $mapping['structure']['core']['content'] = serialize_blocks( $mapping['structure']['gutenberg_blocks'] ); }
        // Elementor owns the native body; post_content can be a duplicate derived text cache.
        if ( $elementor_content_selected || $suite_content_selected ) { $mapping['structure']['core']['content'] = ''; }
        // A prepared new item must not inherit its sample's publication or URL identity.
        $mapping['structure']['core']['slug'] = ''; $mapping['structure']['core']['parent'] = 0;
        if ( 'post' === $mapping['target']['reference_type'] ) { $mapping['structure']['core']['status'] = 'draft'; $mapping['structure']['core']['author'] = 0; $mapping['structure']['core']['menu_order'] = 0; }
        foreach ( $mapping['fields'] as &$field ) { $field['value'] = self::at( $mapping['structure'], $field['structure_path'] ); $field['current_value'] = $field['value']; } unset( $field );
        return $mapping;
    }
    private static function instructions( $value ): void { if ( ! is_string( $value ) || strlen( $value ) > 8000 || 1 !== preg_match( '//u', $value ) || false !== strpos( $value, "\0" ) ) { self::fail( 'Instructions must be UTF-8 text of at most 8000 bytes.' ); } }
    private static function safe_url( string $value ): bool {
        if ( '' === $value ) { return true; }
        if ( preg_match( '/[\x00-\x20<>"\x27\\\\]/', $value ) ) { return false; }
        $parts = parse_url( $value ); if ( ! is_array( $parts ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) { return false; }
        if ( isset( $parts['scheme'] ) && ! in_array( strtolower( $parts['scheme'] ), [ 'http', 'https', 'mailto', 'tel' ], true ) ) { return false; }
        return ! isset( $parts['scheme'] ) || ! in_array( strtolower( $parts['scheme'] ), [ 'http', 'https' ], true ) || ! empty( $parts['host'] );
    }
    private static function allowed_value( $value, array $field ): bool {
        if ( is_object( $value ) || is_resource( $value ) ) { return false; }
        if ( $value === $field['current_value'] ) { return true; }
        if ( ! empty( $field['source_free_editorial_array'] ) && ! self::editorial_array_value( $value, $field['native_schema'] ) ) { return false; }
        if ( isset( $field['native_schema'] ) && function_exists( 'rest_validate_value_from_schema' ) && is_wp_error( rest_validate_value_from_schema( $value, $field['native_schema'], $field['path'] ) ) ) { return false; }
        $type = $field['type'];
        if ( 'url' === $type && ( ! is_string( $value ) || ! self::safe_url( $value ) ) ) { return false; }
        if ( 'email' === $type && ( ! is_string( $value ) || ( '' !== $value && ( function_exists( 'is_email' ) ? ! is_email( $value ) : ! filter_var( $value, FILTER_VALIDATE_EMAIL ) ) ) ) ) { return false; }
        if ( in_array( $type, [ 'text', 'string', 'rich_text', 'textarea', 'wysiwyg', 'url', 'email' ], true ) ) { return is_string( $value ) && false === strpos( $value, "\0" ) && 1 === preg_match( '//u', $value ); }
        if ( in_array( $type, [ 'integer', 'int' ], true ) ) { return is_int( $value ); }
        if ( in_array( $type, [ 'number', 'float' ], true ) ) { return ( is_int( $value ) || is_float( $value ) ) && is_finite( (float) $value ); }
        if ( in_array( $type, [ 'boolean', 'true_false' ], true ) ) { return is_bool( $value ); }
        if ( in_array( $type, [ 'array', 'object', 'group', 'repeater', 'flexible_content' ], true ) ) { return is_array( $value ) && self::no_secrets( $value ); }
        return gettype( $value ) === gettype( $field['current_value'] );
    }
    public static function validate( array $mapping ) {
        return self::guard( static function () use ( $mapping ) {
            if ( ( $mapping['format'] ?? '' ) !== self::FORMAT || ! in_array( $mapping['mode'] ?? '', [ 'existing', 'creation' ], true ) || ! is_string( $mapping['resource_id'] ?? null ) || ! is_array( $mapping['target'] ?? null ) || ! is_array( $mapping['fields'] ?? null ) ) { return self::error( 'shape', 'Supply a complete versioned native document mapping.', 400 ); }
            $source = $mapping['mode'] === 'existing' ? $mapping['target'] : ( $mapping['source_reference'] ?? null );
            if ( $source ) { if ( ! is_array( $source ) || ! is_string( $source['reference_type'] ?? null ) || ! is_int( $source['reference_id'] ?? null ) ) { return self::error( 'identity', 'The source reference is invalid.', 400 ); } $live = self::read( $mapping['resource_id'], $source['reference_type'], $source['reference_id'] ); }
            else { $live = self::empty( $mapping['resource_id'] ); }
            if ( is_wp_error( $live ) ) { return $live; }
            if ( ( $mapping['baseline'] ?? null ) !== $live['baseline'] || ( $mapping['document_version'] ?? null ) !== $live['document_version'] ) { return self::error( 'drift', 'The source structure or content changed after review. Re-read it before saving.', 409 ); }
            if ( 'creation' === $mapping['mode'] ) { $live = self::trusted_blank( $live, $mapping['selected_paths'] ?? [] ); }
            if ( array_diff( array_keys( $mapping ), array_keys( $live ) ) || array_diff( array_keys( $live ), array_keys( $mapping ) ) ) { return self::error( 'identity', 'The mapping has unsupported or missing authority fields.', 400 ); }
            foreach ( $live as $key => $value ) { if ( 'fields' !== $key && self::hash( $mapping[ $key ] ) !== self::hash( $value ) ) { return self::error( 'identity', 'Native target identity, provider, instructions or read-only structure was altered.', 409 ); } }
            self::instructions( $mapping['instructions'] );
            if ( array_diff( array_keys( $mapping['fields'] ), array_keys( $live['fields'] ) ) || array_diff( array_keys( $live['fields'] ), array_keys( $mapping['fields'] ) ) ) { return self::error( 'fields', 'The mapping field inventory differs from the reviewed native document.', 409 ); }
            foreach ( $live['fields'] as $path => $field ) {
                $submitted = $mapping['fields'][ $path ]; if ( ! is_array( $submitted ) || ! array_key_exists( 'value', $submitted ) ) { return self::error( 'fields', 'A native mapping field is incomplete.', 400 ); }
                $immutable = $submitted; $expected = $field; unset( $immutable['value'], $expected['value'] );
                $changed = self::hash( $submitted['value'] ) !== self::hash( $field['value'] );
                if ( self::hash( $immutable ) !== self::hash( $expected ) || ( $changed && ( $field['protected'] || ( 'creation' === $mapping['mode'] && ! in_array( $path, $live['selected_paths'], true ) ) ) ) || ( ! $field['protected'] && ! self::allowed_value( $submitted['value'], $field ) ) ) { return self::error( 'field', 'A field value has an unsupported shape or its native protection was changed.', 409 ); }
                self::instructions( $submitted['instructions'] ?? '' );
            }
            self::json( $mapping ); return $mapping;
        } );
    }
}
