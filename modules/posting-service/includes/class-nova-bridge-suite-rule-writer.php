<?php
/** Journaled, source-free native Elementor documents from frozen local rendering rules. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Rule_Writer_Failure extends RuntimeException {
    public $reason;
    public function __construct( string $reason, string $message ) { $this->reason = $reason; parent::__construct( $message ); }
}

final class Nova_Bridge_Suite_Rule_Writer {
    public const FORMAT = 'nova.local-rule-document/v1';
    private const MAX_PLAN_BYTES = 786432;

    private static function fail( string $code, string $message ): void { throw new Nova_Bridge_Suite_Rule_Writer_Failure( $code, $message ); }
    private static function guard( callable $callback ) {
        try { return $callback(); }
        catch ( Nova_Bridge_Suite_Rule_Writer_Failure $error ) { return new WP_Error( 'nova_rule_writer_' . $error->reason, $error->getMessage(), [ 'status' => 409, 'blocked' => true ] ); }
        catch ( Throwable $error ) { return new WP_Error( 'nova_writer_ambiguous_commit', 'The native operation was interrupted. Retry the same operation to reconcile its retained draft; no second page will be created.', [ 'status' => 409, 'blocked' => true ] ); }
    }
    private static function json( array $value ): string {
        $sort = static function ( $item ) use ( &$sort ) {
            if ( is_array( $item ) ) {
                if ( $item && array_keys( $item ) !== range( 0, count( $item ) - 1 ) ) { ksort( $item, SORT_STRING ); }
                foreach ( $item as $key => $child ) { $item[ $key ] = $sort( $child ); }
            }
            return $item;
        };
        $json = wp_json_encode( $sort( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( ! is_string( $json ) ) { self::fail( 'encoding', 'The native plan cannot be encoded.' ); }
        return $json;
    }
    private static function digest( array $value ): string { return hash( 'sha256', self::json( $value ) ); }
    private static function option( string $name ) { wp_cache_delete( $name, 'options' ); return get_option( $name, null ); }
    private static function checkpoint( string $name, array $record ): void {
        if ( strlen( self::json( $record ) ) > 2097152 ) { self::fail( 'journal_size', 'The retained native operation exceeds its storage limit.' ); }
        $old = self::option( $name );
        if ( null === $old ) { add_option( $name, $record, '', false ); }
        else { update_option( $name, $record, false ); }
        if ( self::option( $name ) !== $record ) { self::fail( 'storage', 'Native operation progress could not be retained. Reconcile the same operation before retrying.' ); }
    }
    private static function operation_key( string $id ): string { return 'nova_rule_operation_' . hash( 'sha256', $id ); }
    private static function association_key( string $site, string $url ): string { return 'nova_rule_page_' . hash( 'sha256', $site . ':' . $url ); }
    private static function uuid( string $id ): bool { return Nova_Bridge_Suite_Content_Rules::uuid( $id ); }
    private static function decimal( $value ): bool { return is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]{0,18}$/D', $value ); }
    private static function compare_id( string $a, string $b ): int { return strlen( $a ) <=> strlen( $b ) ?: strcmp( $a, $b ); }

    private static function locked( string $identity, callable $callback ) {
        global $wpdb;
        if ( ! isset( $wpdb->posts ) || ! preg_match( '/^[A-Za-z0-9_]+$/D', $wpdb->posts ) ) { self::fail( 'storage', 'Native WordPress database storage is unavailable.' ); }
        $name = 'nova-rule:' . substr( hash( 'sha256', $wpdb->posts . ':' . $identity ), 0, 54 );
        if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ) ) { self::fail( 'busy', 'Another native operation is using this page. Retry the same operation shortly.' ); }
        try { return $callback(); }
        finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); }
    }

    /** Runtime capability checks precede every native mutation, including the initial draft insert. */
    private static function provider( array $profile, array $elements ): array {
        if ( ! class_exists( '\\Elementor\\Plugin' ) || ! defined( 'ELEMENTOR_VERSION' ) || ! class_exists( '\\SEOR_Elementor_Bridge\\Elementor_Service' ) ) {
            self::fail( 'builder_unavailable', 'Enable Elementor and the NOVA Elementor bridge before creating a content-rules draft.' );
        }
        $plugin = \Elementor\Plugin::instance();
        if ( ! isset( $plugin->documents, $plugin->elements_manager ) || ! method_exists( $plugin->documents, 'get' ) || ! method_exists( $plugin->elements_manager, 'get_element' ) ) { self::fail( 'builder_unavailable', 'The installed Elementor version does not expose the supported native document lifecycle.' ); }
        $type = get_post_type_object( $profile['post_type'] );
        if ( ! $type || ! current_user_can( $type->cap->create_posts ?? $type->cap->edit_posts ) ) { self::fail( 'permission', 'The publishing user cannot create this native post type.' ); }
        $supported = get_option( 'elementor_cpt_support', [ 'post', 'page' ] );
        if ( ! in_array( $profile['post_type'], is_array( $supported ) ? $supported : [], true ) && ( ! function_exists( 'post_type_supports' ) || ! post_type_supports( $profile['post_type'], 'elementor' ) ) ) { self::fail( 'post_type', 'Enable Elementor support for this post type before creating its draft.' ); }
        $walk = static function ( array $nodes ) use ( &$walk, $plugin ) {
            foreach ( $nodes as $node ) {
                if ( ! is_array( $node ) || ! is_string( $node['id'] ?? null ) || ! $plugin->elements_manager->get_element( $node['elType'] ?? '', $node['widgetType'] ?? null ) ) { self::fail( 'element_unavailable', 'An element required by the local rules is unavailable in this Elementor installation.' ); }
                $walk( $node['elements'] ?? [] );
            }
        };
        $walk( $elements );
        global $wp_version;
        return [ 'wordpress' => (string) $wp_version, 'elementor' => (string) ELEMENTOR_VERSION, 'bridge' => defined( 'NOVA_BRIDGE_SUITE_VERSION' ) ? NOVA_BRIDGE_SUITE_VERSION : '' ];
    }

    private static function origin_path( string $url ): array {
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || ! in_array( $parts['scheme'] ?? '', [ 'https', 'http' ], true ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) { self::fail( 'url', 'A content-rules delivery needs a canonical URL on this WordPress site.' ); }
        return [ strtolower( $parts['scheme'] . '://' . $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ), rtrim( $parts['path'] ?? '/', '/' ) ];
    }
    private static function routing( array $content, array $profile ): array {
        $language = $content['language'] ?? '';
        if ( ! is_string( $language ) || '' === $language || strtolower( explode( '-', str_replace( '_', '-', $language ) )[0] ) !== strtolower( explode( '-', str_replace( '_', '-', get_locale() ) )[0] ) ) { self::fail( 'locale', 'The delivery language does not match this WordPress site. Local rules do not choose a multilingual destination automatically.' ); }
        if ( ! is_string( $content['url'] ?? null ) || ! in_array( $profile['post_type'], [ 'page', 'post' ], true ) ) { self::fail( 'url', 'The initial delivery route supports canonical WordPress pages and posts with a simple slug permalink.' ); }
        $identity = self::origin_path( $content['url'] ); $home = self::origin_path( home_url( '/' ) );
        if ( $identity[0] !== $home[0] || strpos( $identity[1], $home[1] . '/' ) !== 0 ) { self::fail( 'url_origin', 'The delivery URL must belong to this WordPress origin and installation path.' ); }
        $relative = substr( $identity[1], strlen( $home[1] ) + 1 );
        if ( '' === $relative || false !== strpos( rawurldecode( $relative ), '..' ) || false !== strpos( rawurldecode( $relative ), '\\' ) ) { self::fail( 'url_path', 'The delivery URL does not identify a safe new page path.' ); }
        if ( ! get_option( 'permalink_structure', '' ) ) { self::fail( 'permalink', 'Choose WordPress pretty permalinks before enabling canonical URL delivery rendering.' ); }
        $lookup_path = $relative;
        if ( $profile['post_type'] === 'post' ) {
            $structure = get_option( 'permalink_structure', '' );
            if ( ! is_string( $structure ) || ! preg_match( '#^/(?:[a-z0-9_-]+/)*%postname%/?$#D', $structure ) ) { self::fail( 'permalink', 'Local post deliveries support /%postname%/ or a fixed prefix before %postname%. Date and category routes need an explicit routing adapter.' ); }
            $prefix = ltrim( substr( $structure, 0, strpos( $structure, '%postname%' ) ), '/' );
            if ( strpos( $relative, $prefix ) !== 0 || false !== strpos( substr( $relative, strlen( $prefix ) ), '/' ) ) { self::fail( 'url_path', 'The delivery URL does not match this site’s configured post permalink prefix.' ); }
            $relative = substr( $relative, strlen( $prefix ) ); $lookup_path = $relative;
        }
        $pieces = explode( '/', $relative ); $slug = array_pop( $pieces );
        if ( '' === $slug || sanitize_title( rawurldecode( $slug ) ) !== $slug ) { self::fail( 'url_slug', 'The delivery URL must use a canonical WordPress slug.' ); }
        $parent = 0;
        if ( $pieces ) {
            $post = get_page_by_path( implode( '/', $pieces ), OBJECT, 'page' );
            if ( ! $post || $post->post_status === 'trash' ) { self::fail( 'url_parent', 'The delivery parent path has no existing WordPress page. Choose its hierarchy explicitly before delivery.' ); }
            $parent = (int) $post->ID;
        }
        return [ 'url' => $content['url'], 'identity' => $identity, 'slug' => $slug, 'parent' => $parent, 'language' => $language, 'path' => $lookup_path ];
    }
    private static function association( array $content ): ?array {
        if ( ! self::uuid( $content['site_id'] ?? '' ) || ! self::decimal( $content['url_id'] ?? null ) ) { self::fail( 'identity', 'A verified site and URL identity are required for local delivery rendering.' ); }
        $record = self::option( self::association_key( $content['site_id'], $content['url_id'] ) );
        if ( null !== $record && ( ! is_array( $record ) || ( $record['site_id'] ?? null ) !== $content['site_id'] || ( $record['url_id'] ?? null ) !== $content['url_id'] ) ) { self::fail( 'association', 'The retained local page association requires reconciliation.' ); }
        return $record;
    }

    public static function policy( array $content, array $configuration ) {
        return self::guard( static function () use ( $content, $configuration ) {
            if ( ( $configuration['mode'] ?? '' ) !== 'local_rules' || ! array_key_exists( 'configuration', $content ) || null !== $content['configuration'] || ( $configuration['site_id'] ?? '' ) !== ( $content['site_id'] ?? null ) ) { self::fail( 'configuration', 'Local rules require an explicitly opted-in, unmapped delivery.' ); }
            self::routing( $content, $configuration['profile'] );
            $association = self::association( $content );
            if ( $association && ( $association['state'] ?? '' ) !== 'committed' ) { self::fail( 'association_pending', 'An earlier native operation for this URL must be reconciled first.' ); }
            return [ 'mode' => 'local_rules', 'reference' => [ 'reference_type' => 'post', 'reference_id' => (int) ( $association['post_id'] ?? 0 ) ], 'routing' => [ 'operation' => $association ? 'update' : 'create', 'publication' => $association ? 'preserve' : 'draft' ] ];
        } );
    }

    private static function prepare( array $profile, array $input, string $operation, array $context = [], ?array $content = null ): array {
        if ( ! self::uuid( $operation ) ) { self::fail( 'operation', 'Use the same valid operation UUID when retrying a draft.' ); }
        $checked = Nova_Bridge_Suite_Content_Rules::validate( $profile );
        if ( is_wp_error( $checked ) || $profile['revision'] < 1 ) { self::fail( 'profile', 'Save and approve a valid immutable rendering profile revision first.' ); }
        if ( array_diff( array_keys( $input ), [ 'html', 'blocks', 'faqs', 'title' ] ) ) { self::fail( 'input', 'Supply article HTML or typed blocks and an optional draft title.' ); }
        $render_input = $input;
        $route = $content ? self::routing( $content, $profile ) : null;
        $association = $content ? self::association( $content ) : null;
        if ( $association ) {
            if ( ( $association['state'] ?? '' ) !== 'committed' || ( $association['profile_id'] ?? '' ) !== $profile['id'] || ( $association['language'] ?? '' ) !== $route['language'] ) { self::fail( 'association', 'The generated page belongs to another profile, language or unfinished native operation.' ); }
            if ( ! self::decimal( $content['content_item_version_id'] ?? null ) || self::compare_id( $content['content_item_version_id'], $association['content_item_version_id'] ?? '0' ) <= 0 ) { self::fail( 'stale_source', 'This URL already has this source version or a newer generated document.' ); }
        }
        $seed = $content ? $content['site_id'] . ':' . $content['url_id'] : $operation;
        $rendered = Nova_Bridge_Suite_Content_Rules::render( $profile, $render_input, $seed );
        if ( is_wp_error( $rendered ) ) { self::fail( 'render', $rendered->get_error_message() ); }
        $provider = self::provider( $profile, $rendered['elements'] );
        $title = sanitize_text_field( $input['title'] ?? $profile['label'] );
        if ( '' === $title ) { $title = 'Content layout draft'; }
        $target = (int) ( $association['post_id'] ?? 0 );
        $before = $target ? self::native_state( $target ) : null;
        if ( $target && ( ! current_user_can( 'edit_post', $target ) || self::digest( $before ) !== ( $association['native_digest'] ?? '' ) ) ) { self::fail( 'human_changed', 'This generated page changed after its last verified save. Reconcile its edits before replacing the owned document.' ); }
        if ( $target ) { self::check_url( $target, $route ); }
        elseif ( $route && get_page_by_path( $route['path'], OBJECT, $profile['post_type'] ) ) { self::fail( 'url_occupied', 'Another page already owns this path. Local rules never adopt an unrelated page.' ); }
        $warnings = $rendered['warnings'];
        if ( $content && ( ! empty( $content['content']['top_content'] ) || ! empty( $content['content']['bottom_content'] ) ) ) { $warnings[] = 'This profile renders main article HTML only; top_content and bottom_content require an explicitly selected arrangement.'; }
        $plan = [ 'format' => self::FORMAT, 'operation_id' => $operation, 'profile' => $profile, 'profile_digest' => Nova_Bridge_Suite_Content_Rules::digest( $profile ), 'renderer_version' => $rendered['renderer_version'], 'provider' => $provider, 'input_digest' => self::digest( $input ), 'elements' => $rendered['elements'], 'title' => $title, 'post_type' => $profile['post_type'], 'slug' => $route ? $route['slug'] : sanitize_title( $title ) . '-' . substr( str_replace( '-', '', $operation ), 0, 10 ), 'parent' => $route['parent'] ?? 0, 'route' => $route, 'association_before' => $association, 'target_post_id' => $target, 'native_before' => $before, 'context' => $context, 'source_selection' => $content ? 'content.content' : 'local_input', 'warnings' => $warnings ];
        if ( $content ) { $plan['delivery_identity'] = array_intersect_key( $content, array_flip( [ 'site_id', 'url_id', 'content_item_id', 'content_item_version_id', 'version_number', 'source_sha256', 'language' ] ) ); }
        if ( strlen( self::json( $plan ) ) > self::MAX_PLAN_BYTES ) { self::fail( 'plan_size', 'This rendered document is too large for the recoverable native journal.' ); }
        $plan['plan_digest'] = self::digest( $plan );
        return $plan;
    }

    public static function plan( array $content, array $configuration, array $context ) {
        return self::guard( static function () use ( $content, $configuration, $context ) {
            if ( ( $configuration['mode'] ?? '' ) !== 'local_rules' || ! array_key_exists( 'configuration', $content ) || null !== $content['configuration'] || ( $content['site_id'] ?? null ) !== ( $configuration['site_id'] ?? null ) || Nova_Bridge_Suite_Content_Rules::digest( $configuration['profile'] ) !== ( $configuration['profile_digest'] ?? '' ) ) { self::fail( 'configuration', 'The frozen local rule configuration does not match this unmapped delivery.' ); }
            if ( ! is_string( $content['content']['content'] ?? null ) ) { self::fail( 'missing_body', 'This local rule profile needs main article HTML in content.content. Other source fields are not combined automatically.' ); }
            return self::prepare( $configuration['profile'], [ 'html' => $content['content']['content'], 'title' => $content['content']['title'] ?? $configuration['profile']['label'] ], $context['operation_id'], $context, $content );
        } );
    }

    /** A post-row marker exists atomically with reservation creation, before native save hooks. */
    private static function reservation_marker( array $plan ): string { return self::FORMAT . ':' . $plan['operation_id'] . ':' . $plan['plan_digest']; }
    private static function reserved_post( array $plan ): int {
        global $wpdb;
        $ids = $wpdb->get_col( $wpdb->prepare( 'SELECT ID FROM `' . $wpdb->posts . '` WHERE post_content_filtered = %s AND post_type = %s ORDER BY ID', self::reservation_marker( $plan ), $plan['post_type'] ) );
        if ( ! empty( $wpdb->last_error ) || count( $ids ) > 1 ) { self::fail( 'reservation_ambiguous', 'The native reservation could not be uniquely reconciled. No additional draft was created.' ); }
        return $ids ? (int) $ids[0] : 0;
    }
    private static function owner( array $plan ): array { return [ 'format' => self::FORMAT, 'profile_id' => $plan['profile']['id'], 'site_id' => $plan['delivery_identity']['site_id'] ?? '', 'url_id' => $plan['delivery_identity']['url_id'] ?? '', 'creation_operation' => $plan['association_before']['creation_operation'] ?? $plan['operation_id'] ]; }
    private static function native_state( int $post_id ): array {
        clean_post_cache( $post_id ); $post = get_post( $post_id );
        if ( ! $post || $post->post_status === 'trash' ) { self::fail( 'target_missing', 'The retained generated page no longer exists or is in the trash.' ); }
        $raw = get_post_meta( $post_id, '_elementor_data', true );
        if ( '' === $raw ) { $data = []; }
        else { $data = is_string( $raw ) ? json_decode( $raw, true ) : null; if ( ! is_array( $data ) || json_last_error() !== JSON_ERROR_NONE ) { self::fail( 'native_data', 'The native Elementor document is unreadable; reconcile it before retrying.' ); } }
        return [ 'post_id' => $post_id, 'post_type' => $post->post_type, 'title' => $post->post_title, 'slug' => $post->post_name, 'parent' => (int) $post->post_parent, 'author' => (int) $post->post_author, 'content' => $post->post_content, 'reservation' => $post->post_content_filtered, 'elements' => $data, 'page_settings' => get_post_meta( $post_id, '_elementor_page_settings', true ), 'page_template' => get_post_meta( $post_id, '_wp_page_template', true ), 'owner' => get_post_meta( $post_id, '_nova_content_rule_owner', true ) ];
    }
    private static function check_url( int $post_id, ?array $route ): void {
        if ( ! $route ) { return; }
        if ( 'publish' === get_post_status( $post_id ) ) { $url = get_permalink( $post_id ); }
        else {
            if ( ! function_exists( 'get_sample_permalink' ) && is_file( ABSPATH . 'wp-admin/includes/post.php' ) ) { require_once ABSPATH . 'wp-admin/includes/post.php'; }
            if ( ! function_exists( 'get_sample_permalink' ) ) { self::fail( 'permalink', 'WordPress cannot verify the future permalink of this draft.' ); }
            $sample = get_sample_permalink( $post_id ); $url = str_replace( [ '%pagename%', '%postname%' ], $sample[1] ?? '', $sample[0] ?? '' );
        }
        if ( ! is_string( $url ) || self::origin_path( $url ) !== $route['identity'] ) { self::fail( 'permalink', 'The generated page permalink differs from the verified delivery URL. Reconcile the local page path before writing.' ); }
    }
    /** Native defaults may be added; ordered identity, content and every configured setting must survive. */
    private static function semantics_match( array $expected, array $actual ): bool {
        if ( count( $expected ) !== count( $actual ) || array_values( $actual ) !== $actual ) { return false; }
        $ids = [];
        $subset = static function ( $a, $b, $settings_root = false ) use ( &$subset ): bool {
            if ( ! is_array( $a ) ) { return $a === $b; }
            if ( ! is_array( $b ) ) { return false; }
            foreach ( $a as $key => $value ) { if ( ! array_key_exists( $key, $b ) || ! $subset( $value, $b[ $key ] ) ) { return false; } }
            if ( $a && array_keys( $a ) === range( 0, count( $a ) - 1 ) && count( $a ) !== count( $b ) ) { return false; }
            foreach ( array_diff_key( $b, $a ) as $key => $value ) {
                // Empty native defaults and the editor-only element label are harmless.
                // New links, dynamic bindings, CSS or other effective settings are not.
                if ( $settings_root && $key === '_title' && is_string( $value ) && strlen( $value ) <= 200 && ! preg_match( '/[\x00-\x1F<>]/', $value ) ) { continue; }
                if ( ! in_array( $value, [ '', null, false, [] ], true ) ) { return false; }
            }
            return true;
        };
        foreach ( $expected as $index => $node ) {
            $saved = $actual[ $index ]; $id = $saved['id'] ?? null;
            if ( ! is_string( $id ) || isset( $ids[ $id ] ) || $id !== $node['id'] || ( $saved['elType'] ?? null ) !== $node['elType'] || ( $saved['widgetType'] ?? null ) !== ( $node['widgetType'] ?? null ) || ! $subset( $node['settings'] ?? [], $saved['settings'] ?? [], true ) || ! self::semantics_match( $node['elements'] ?? [], $saved['elements'] ?? [] ) ) { return false; }
            foreach ( $node as $key => $value ) { if ( ! in_array( $key, [ 'settings', 'elements' ], true ) && ( $saved[ $key ] ?? null ) !== $value ) { return false; } }
            foreach ( array_diff_key( $saved, $node ) as $key => $value ) { if ( $key !== 'isInner' || false !== $value ) { return false; } }
            $ids[ $id ] = true;
        }
        return true;
    }
    private static function assert_plan( array $plan, string $operation ): void {
        $copy = $plan; unset( $copy['plan_digest'] );
        if ( ( $plan['format'] ?? '' ) !== self::FORMAT || $operation !== ( $plan['operation_id'] ?? '' ) || ! self::uuid( $operation ) || self::digest( $copy ) !== ( $plan['plan_digest'] ?? '' ) || Nova_Bridge_Suite_Content_Rules::digest( $plan['profile'] ) !== $plan['profile_digest'] ) { self::fail( 'plan_identity', 'The frozen native plan changed. Reconcile its retained operation before writing.' ); }
    }
    private static function result( array $record ): array {
        $id = (int) $record['post_id'];
        $result = [ 'post_id' => $id, 'cms_post_status' => get_post_status( $id ), 'status' => get_post_status( $id ), 'edit_url' => get_edit_post_link( $id, 'raw' ), 'url' => get_permalink( $id ), 'operation_uuid' => $record['plan']['operation_id'], 'native_digest' => $record['native_digest'], 'warnings' => $record['plan']['warnings'] ];
        if ( function_exists( 'get_preview_post_link' ) ) { $result['preview_url'] = get_preview_post_link( $id ); }
        if ( class_exists( '\\Elementor\\Plugin' ) ) {
            $document = \Elementor\Plugin::instance()->documents->get( $id, false );
            if ( is_object( $document ) && method_exists( $document, 'get_edit_url' ) ) { $result['elementor_edit_url'] = $document->get_edit_url(); }
        }
        return $result;
    }
    private static function finalize( array $plan, array $record, array $state ): array {
        if ( ! self::semantics_match( $plan['elements'], $state['elements'] ) || $state['title'] !== $plan['title'] || $state['owner'] !== self::owner( $plan ) ) { self::fail( 'native_mismatch', 'Elementor did not preserve the complete ordered content and configured settings. The retained draft requires review.' ); }
        self::check_url( $record['post_id'], $plan['route'] );
        $record['state'] = 'committed'; $record['native_digest'] = self::digest( $state );
        self::checkpoint( self::operation_key( $plan['operation_id'] ), $record );
        if ( isset( $plan['delivery_identity'] ) ) {
            $identity = $plan['delivery_identity'];
            $association = $identity + [ 'state' => 'committed', 'post_id' => $record['post_id'], 'profile_id' => $plan['profile']['id'], 'profile_revision' => $plan['profile']['revision'], 'profile_digest' => $plan['profile_digest'], 'native_digest' => $record['native_digest'], 'operation_id' => $plan['operation_id'], 'creation_operation' => self::owner( $plan )['creation_operation'] ];
            $current = self::association( $identity );
            if ( $current !== $association && $current !== $plan['association_before'] && ( $current['operation_id'] ?? '' ) !== $plan['operation_id'] ) { self::fail( 'association_changed', 'Another native operation acquired this URL; the retained association was not overwritten.' ); }
            self::checkpoint( self::association_key( $identity['site_id'], $identity['url_id'] ), $association );
        }
        return self::result( $record );
    }

    public static function apply( array $plan, string $operation ) {
        return self::guard( static function () use ( $plan, $operation ) {
            self::assert_plan( $plan, $operation );
            $lock = isset( $plan['delivery_identity'] ) ? $plan['delivery_identity']['site_id'] . ':' . $plan['delivery_identity']['url_id'] : $operation;
            return self::locked( $lock, static function () use ( $plan, $operation ) {
                if ( self::provider( $plan['profile'], $plan['elements'] ) !== $plan['provider'] ) { self::fail( 'provider_changed', 'The native provider changed after planning. Review the retained draft operation.' ); }
                $key = self::operation_key( $operation ); $record = self::option( $key );
                if ( null !== $record && ( ! is_array( $record ) || ( $record['plan_digest'] ?? '' ) !== $plan['plan_digest'] ) ) { self::fail( 'operation_reused', 'This operation UUID already belongs to a different profile or input. Retry its original request.' ); }
                if ( $record && $record['state'] === 'committed' ) { self::verify_record( $plan, $record ); return self::finalize( $plan, $record, self::native_state( (int) $record['post_id'] ) ); }
                if ( $record && $record['state'] === 'saving' ) {
                    $state = self::native_state( (int) $record['post_id'] );
                    if ( self::semantics_match( $plan['elements'], $state['elements'] ) ) { return self::finalize( $plan, $record, $state ); }
                    self::fail( 'save_unknown', 'A native save may have partially completed. Review the retained draft; it will not be overwritten or duplicated automatically.' );
                }
                if ( ! $record ) {
                    if ( isset( $plan['delivery_identity'] ) ) {
                        $current = self::association( $plan['delivery_identity'] );
                        if ( $current !== $plan['association_before'] ) { self::fail( 'association_changed', 'Another operation changed this URL association after planning.' ); }
                    }
                    $record = [ 'state' => 'prepared', 'plan_digest' => $plan['plan_digest'], 'plan' => $plan, 'post_id' => $plan['target_post_id'] ];
                    self::checkpoint( $key, $record );
                }
                if ( isset( $plan['delivery_identity'] ) ) {
                    $association = self::association( $plan['delivery_identity'] );
                    if ( $association !== $plan['association_before'] && ( $association['operation_id'] ?? '' ) !== $operation ) { self::fail( 'association_changed', 'Another unfinished operation owns this URL. Reconcile it before saving the page.' ); }
                }
                if ( ! $record['post_id'] ) {
                    $found = self::reserved_post( $plan );
                    if ( $found ) { $record['post_id'] = $found; $record['state'] = 'reserved'; self::checkpoint( $key, $record ); }
                    elseif ( $record['state'] === 'creating' ) { self::fail( 'create_unknown', 'A previous creation attempt has no uniquely verified reservation. Reconcile it before creating another draft.' ); }
                    else {
                        if ( $plan['route'] && get_page_by_path( $plan['route']['path'], OBJECT, $plan['post_type'] ) ) { self::fail( 'url_occupied', 'Another page acquired this delivery path before creation.' ); }
                        if ( isset( $plan['delivery_identity'] ) ) { self::checkpoint( self::association_key( $plan['delivery_identity']['site_id'], $plan['delivery_identity']['url_id'] ), $plan['delivery_identity'] + [ 'state' => 'pending', 'operation_id' => $operation, 'post_id' => 0 ] ); }
                        $record['state'] = 'creating'; self::checkpoint( $key, $record );
                        $marker = self::reservation_marker( $plan );
                        $protect = static function ( $data, $postarr, $unsanitized ) use ( $marker ) { if ( ( $unsanitized['post_content_filtered'] ?? null ) === $marker ) { $data['post_content_filtered'] = $marker; } return $data; };
                        add_filter( 'wp_insert_post_data', $protect, PHP_INT_MAX, 3 );
                        try { $id = wp_insert_post( [ 'post_type' => $plan['post_type'], 'post_status' => 'draft', 'post_title' => wp_slash( $plan['title'] ), 'post_name' => $plan['slug'], 'post_parent' => $plan['parent'], 'post_author' => get_current_user_id(), 'post_content_filtered' => $marker ], true ); }
                        finally { remove_filter( 'wp_insert_post_data', $protect, PHP_INT_MAX ); }
                        if ( is_wp_error( $id ) || ! $id ) { self::fail( 'create_unknown', 'WordPress did not confirm draft creation. Retry this same operation to reconcile the reservation.' ); }
                        if ( self::reserved_post( $plan ) !== (int) $id ) { self::fail( 'reservation_changed', 'WordPress changed the atomic reservation identity. Reconcile the created draft before retrying.' ); }
                        $record['post_id'] = (int) $id; $record['state'] = 'reserved'; self::checkpoint( $key, $record );
                    }
                }
                $id = (int) $record['post_id'];
                if ( ! current_user_can( 'edit_post', $id ) ) { self::fail( 'permission', 'The publishing user cannot edit the retained generated page.' ); }
                $state = self::native_state( $id );
                if ( $plan['target_post_id'] ) {
                    if ( $state !== $plan['native_before'] || $state['owner'] !== self::owner( $plan ) ) { self::fail( 'human_changed', 'The owned generated document changed after planning. No replacement was made.' ); }
                } else {
                    if ( $state['reservation'] !== self::reservation_marker( $plan ) || $state['elements'] || $state['content'] !== '' || get_post_status( $id ) !== 'draft' || $state['title'] !== $plan['title'] ) { self::fail( 'reservation_changed', 'The reserved draft was edited before native save. Review it before continuing.' ); }
                    update_post_meta( $id, '_nova_content_rule_owner', self::owner( $plan ) );
                    if ( get_post_meta( $id, '_nova_content_rule_owner', true ) !== self::owner( $plan ) ) { self::fail( 'storage', 'The native page ownership marker could not be retained.' ); }
                }
                self::check_url( $id, $plan['route'] );
                $document = \Elementor\Plugin::instance()->documents->get( $id, false );
                if ( ! is_object( $document ) || ! method_exists( $document, 'save' ) || ! method_exists( $document, 'set_is_built_with_elementor' ) || ! method_exists( $document, 'is_editable_by_current_user' ) || ! $document->is_editable_by_current_user() ) { self::fail( 'builder_unavailable', 'The retained draft cannot use the installed native Elementor save lifecycle.' ); }
                if ( isset( $plan['delivery_identity'] ) ) { self::checkpoint( self::association_key( $plan['delivery_identity']['site_id'], $plan['delivery_identity']['url_id'] ), $plan['delivery_identity'] + [ 'state' => 'pending', 'operation_id' => $operation, 'post_id' => $id ] ); }
                $record['state'] = 'saving'; self::checkpoint( $key, $record );
                $service = new \SEOR_Elementor_Bridge\Elementor_Service();
                // The bridge passes title through wp_update_post, whose input is slashed.
                $saved = $service->update_page( $id, [ 'title' => wp_slash( $plan['title'] ), 'elementor_data' => wp_json_encode( $plan['elements'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ] );
                if ( is_wp_error( $saved ) ) { self::fail( 'save_unknown', 'Elementor did not confirm its native save. The reserved draft remains available for reconciliation; no replacement draft will be created.' ); }
                return self::finalize( $plan, $record, self::native_state( $id ) );
            } );
        } );
    }

    private static function verify_record( array $plan, array $record ): array {
        $state = self::native_state( (int) $record['post_id'] );
        if ( self::digest( $state ) !== ( $record['native_digest'] ?? '' ) || $state['owner'] !== self::owner( $plan ) ) { self::fail( 'human_changed', 'The generated page changed after its verified save. Reconcile it before automatic publication confirmation or replacement.' ); }
        self::check_url( (int) $record['post_id'], $plan['route'] );
        return self::result( $record );
    }
    public static function recover( array $plan, string $operation ) {
        return self::guard( static function () use ( $plan, $operation ) {
            self::assert_plan( $plan, $operation );
            $lock = isset( $plan['delivery_identity'] ) ? $plan['delivery_identity']['site_id'] . ':' . $plan['delivery_identity']['url_id'] : $operation;
            return self::locked( $lock, static function () use ( $plan, $operation ) {
            $record = self::option( self::operation_key( $operation ) );
            if ( ! $record ) {
                if ( self::reserved_post( $plan ) || ( $plan['target_post_id'] && get_post( $plan['target_post_id'] ) ) ) { self::fail( 'orphaned_native', 'Native page evidence exists without its operation journal. Reconcile it; absence was not assumed and no second page was created.' ); }
                return [ 'state' => 'not_committed', 'no_native_commit' => true, 'safe_to_apply' => true ];
            }
            if ( ( $record['plan_digest'] ?? '' ) !== $plan['plan_digest'] ) { self::fail( 'operation_reused', 'The retained native operation belongs to a different plan.' ); }
            if ( self::provider( $plan['profile'], $plan['elements'] ) !== $plan['provider'] ) { self::fail( 'provider_changed', 'The native provider changed before recovery. Review the retained document.' ); }
            if ( $record['state'] === 'committed' ) { self::verify_record( $plan, $record ); return self::finalize( $plan, $record, self::native_state( (int) $record['post_id'] ) ); }
            $id = (int) ( $record['post_id'] ?: self::reserved_post( $plan ) );
            if ( $id && $record['state'] === 'saving' ) {
                $state = self::native_state( $id );
                if ( self::semantics_match( $plan['elements'], $state['elements'] ) ) { $record['post_id'] = $id; return self::finalize( $plan, $record, $state ); }
                self::fail( 'save_unknown', 'The retained native save needs review; no automatic second write is authorized.' );
            }
            if ( $id ) { return [ 'state' => 'not_committed', 'no_native_commit' => false, 'safe_to_apply' => true, 'post_id' => $id ]; }
            if ( $record['state'] === 'creating' ) { self::fail( 'create_unknown', 'The original draft creation remains uncertain. No second draft was created.' ); }
            return [ 'state' => 'not_committed', 'no_native_commit' => true, 'safe_to_apply' => true ];
            } );
        } );
    }
    public static function finish( array $plan, array $result ) { return self::verify( $plan, $result ); }
    public static function verify( array $plan, array $result ) {
        return self::guard( static function () use ( $plan, $result ) {
            self::assert_plan( $plan, $plan['operation_id'] ); $record = self::option( self::operation_key( $plan['operation_id'] ) );
            if ( ! is_array( $record ) || $record['state'] !== 'committed' || $record['plan_digest'] !== $plan['plan_digest'] || $record['post_id'] !== ( $result['post_id'] ?? null ) ) { self::fail( 'unverified', 'A known native save is required before confirming this document.' ); }
            return self::verify_record( $plan, $record );
        } );
    }

    public static function create_draft( array $profile, array $input, string $operation_uuid ) {
        return self::guard( static function () use ( $profile, $input, $operation_uuid ) {
            if ( ! self::uuid( $operation_uuid ) ) { self::fail( 'operation', 'Use a valid operation UUID for draft creation and retries.' ); }
            $record = self::option( self::operation_key( $operation_uuid ) );
            if ( $record ) {
                if ( ( $record['plan']['input_digest'] ?? '' ) !== self::digest( $input ) || ( $record['plan']['profile_digest'] ?? '' ) !== Nova_Bridge_Suite_Content_Rules::digest( $profile ) || isset( $record['plan']['delivery_identity'] ) ) { self::fail( 'operation_reused', 'This operation UUID belongs to another input or profile. Retry its original request or start a new draft.' ); }
                $plan = $record['plan'];
            } else { $plan = self::prepare( $profile, $input, $operation_uuid ); }
            return self::apply( $plan, $operation_uuid );
        } );
    }
}
