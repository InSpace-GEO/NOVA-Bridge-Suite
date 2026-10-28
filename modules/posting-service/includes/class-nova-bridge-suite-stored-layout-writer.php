<?php
/** Source-free creation from an immutable captured Elementor layout and V3 fitted values. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Stored_Layout_Writer_Failure extends RuntimeException {
    public $reason;
    public function __construct( string $reason, string $message ) { $this->reason = $reason; parent::__construct( $message ); }
}

final class Nova_Bridge_Suite_Stored_Layout_Writer {
    public const FORMAT = 'nova.stored-layout-document/v1';
    private const OWNER = '_nova_stored_layout_owner';
    private static function fail( string $code, string $message ): void { throw new Nova_Bridge_Suite_Stored_Layout_Writer_Failure( $code, $message ); }
    private static function guard( callable $work ) {
        try { return $work(); }
        catch ( Nova_Bridge_Suite_Stored_Layout_Writer_Failure $error ) { return new WP_Error( 'nova_layout_writer_' . $error->reason, $error->getMessage(), [ 'status' => 409, 'blocked' => true ] ); }
        catch ( Throwable $error ) { return new WP_Error( 'nova_writer_ambiguous_commit', 'The stored-layout operation was interrupted. Recover the same operation; its reserved page must not be duplicated.', [ 'status' => 409, 'blocked' => true ] ); }
    }
    private static function checked( $value ) { if ( is_wp_error( $value ) ) { self::fail( 'native', $value->get_error_message() ); } return $value; }
    private static function digest( $value ): string { return hash( 'sha256', Nova_Bridge_Suite_Writing_Adapter::canonical_json( $value ) ); }
    private static function option( string $key ) { wp_cache_delete( $key, 'options' ); return get_option( $key, null ); }
    private static function checkpoint( string $key, array $value ): void {
        if ( strlen( wp_json_encode( $value ) ) > 2097152 ) { self::fail( 'journal_size', 'The retained native operation exceeds its storage limit.' ); }
        if ( null === self::option( $key ) ) { add_option( $key, $value, '', false ); } else { update_option( $key, $value, false ); }
        if ( self::option( $key ) !== $value ) { self::fail( 'storage', 'Native progress could not be retained. Reconcile this operation before retrying.' ); }
    }
    private static function operation_key( string $id ): string { return 'nova_layout_operation_' . hash( 'sha256', $id ); }
    private static function association_key( string $site, string $url ): string { return 'nova_layout_page_' . hash( 'sha256', $site . ':' . $url ); }
    /** Resolve a site-relative path without changing the authenticated snapshot. */
    private static function target_url( $url ) {
        if ( ! is_string( $url ) || '' === $url || 0 === strpos( $url, '//' ) || preg_match( '/^[a-z][a-z0-9+.-]*:/i', $url ) || in_array( $url[0], [ '?', '#' ], true ) ) { return $url; }
        $home = get_option( 'home', '' );
        if ( ! is_string( $home ) || '' === $home ) { $home = home_url( '/' ); }
        $site = self::checked( Nova_Bridge_Suite_Mapped_Content_Target::normalize_url( $home ) );
        if ( '/' === $url[0] ) { return $site['origin'] . $url; }
        return $site['origin'] . ( '/' === $site['path'] ? '/' : rtrim( $site['path'], '/' ) . '/' ) . $url;
    }
    private static function decimal( $id ): bool { return is_string( $id ) && 1 === preg_match( '/^[1-9][0-9]{0,18}$/D', $id ); }
    private static function compare( string $a, string $b ): int { return strlen( $a ) <=> strlen( $b ) ?: strcmp( $a, $b ); }
    private static function association( array $identity ): ?array {
        $saved = self::option( self::association_key( $identity['site_id'], $identity['url_id'] ) );
        if ( null !== $saved && ( ! is_array( $saved ) || ( $saved['site_id'] ?? null ) !== $identity['site_id'] || ( $saved['url_id'] ?? null ) !== $identity['url_id'] ) ) { self::fail( 'association', 'The retained page association requires reconciliation.' ); }
        return $saved;
    }
    private static function target( array $content, array $configuration, string $type ): array {
        $association = self::association( $content ); $url = self::target_url( $content['url'] ?? '' ); $resource = 'post_type:' . $type;
        if ( $association ) {
            if ( ! in_array( $association['state'] ?? '', [ 'committed', 'retired' ], true ) || ( $association['template_id'] ?? '' ) !== $configuration['template']['id'] || ( $association['language'] ?? '' ) !== $content['language'] || ( $association['content_item_id'] ?? '' ) !== $content['content_item_id'] || self::compare( $content['content_item_version_id'], $association['content_item_version_id'] ?? '0' ) <= 0 ) { self::fail( 'association', 'This URL belongs to another mapping, content item, language or unfinished/newer delivery.' ); }
            $wanted = self::checked( Nova_Bridge_Suite_Mapped_Content_Target::normalize_url( $url ) );
            if ( $wanted['identity'] !== $association['target']['target_url'] ) { self::fail( 'url_changed', 'A retained page cannot be moved to a different delivery URL.' ); }
            return [ 'post_id' => (int) $association['post_id'], 'target' => $association['target'], 'association' => $association ];
        }
        // Inspect canonical native URLs only: never infer a page from a matching leaf slug or redirect.
        $found = Nova_Bridge_Suite_Mapped_Content_Target::resolve( $url, $resource );
        if ( is_wp_error( $found ) ) {
            if ( $found->get_error_code() !== 'nova_mapped_target_not_found' ) { self::checked( $found ); }
            return [ 'post_id' => 0, 'target' => self::checked( Nova_Bridge_Suite_Stored_Layout_Language::creation( $resource, $url, $content['language'] ?? '' ) ), 'association' => null ];
        }
        $id = (int) $found['reference_id']; $post = get_post( $id );
        if ( $found['reference_type'] !== 'post' || ! $post || $post->post_type !== $type || ! current_user_can( 'edit_post', $id ) || ! in_array( $post->post_status, [ 'draft', 'publish' ], true ) ) { self::fail( 'target', 'The exact URL must identify an editable draft or published page of the mapped type.' ); }
        if ( '' !== get_post_meta( $id, self::OWNER, true ) ) { self::fail( 'ownership', 'This existing page already has a stored-layout owner. Reconcile its retained association before replacing it.' ); }
        return [ 'post_id' => $id, 'target' => self::checked( Nova_Bridge_Suite_Stored_Layout_Language::existing( $resource, $id, $url ) ), 'association' => null ];
    }
    private static function locked( array $plan, callable $work ) {
        global $wpdb;
        if ( ! isset( $wpdb->posts ) || ! preg_match( '/^[A-Za-z0-9_]+$/D', $wpdb->posts ) ) { self::fail( 'storage', 'Native database storage is unavailable.' ); }
        $lock = 'nova-layout:' . substr( hash( 'sha256', $wpdb->posts . ':' . $plan['site_id'] . ':' . $plan['target']['target_url'] ), 0, 50 );
        if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) { self::fail( 'busy', 'Another native operation owns this URL. Retry shortly.' ); }
        try { return $work(); } finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
    }
    private static function provider( string $type, array $payload, string $status ): array {
        if ( array_key_exists( 'theme_presentation', $payload ) ) { self::checked( Nova_Bridge_Suite_Theme_Presentation::assert_current( $payload['theme_presentation'] ) ); }
        if ( ! class_exists( '\Elementor\Plugin' ) || ! defined( 'ELEMENTOR_VERSION' ) || ! class_exists( '\SEOR_Elementor_Bridge\Elementor_Service' ) ) { self::fail( 'builder_unavailable', 'Enable Elementor and the NOVA Elementor bridge before publishing stored layouts.' ); }
        $post_type = get_post_type_object( $type );
        if ( ! $post_type || ! current_user_can( $post_type->cap->create_posts ?? $post_type->cap->edit_posts ) || ( 'publish' === $status && ! current_user_can( $post_type->cap->publish_posts ) ) ) { self::fail( 'permission', 'The configured actor cannot create or publish this post type.' ); }
        $plugin = \Elementor\Plugin::instance();
        if ( ! isset( $plugin->documents ) || ! is_callable( [ $plugin->documents, 'get' ] ) || ! \SEOR_Elementor_Bridge\Element_Registry::available( $plugin ) ) { self::fail( 'builder_unavailable', 'The native Elementor document lifecycle is unavailable.' ); }
        $walk = static function ( array $nodes ) use ( &$walk, $plugin ) { foreach ( $nodes as $node ) { if ( ! \SEOR_Elementor_Bridge\Element_Registry::contains( $plugin, $node['elType'] ?? '', $node['widgetType'] ?? null ) ) { self::fail( 'element_unavailable', 'A widget used by the stored layout is unavailable. No page was created.' ); } $walk( $node['elements'] ?? [] ); } };
        $walk( $payload['elementor_data'] ?? [] );
        $support = get_option( 'elementor_cpt_support', [ 'post', 'page' ] );
        if ( ! in_array( $type, is_array( $support ) ? $support : [], true ) && ( ! function_exists( 'post_type_supports' ) || ! post_type_supports( $type, 'elementor' ) ) ) { self::fail( 'post_type', 'Enable Elementor for the selected post type.' ); }
        global $wp_version;
        return [ 'wordpress' => (string) $wp_version, 'elementor' => (string) ELEMENTOR_VERSION, 'bridge' => defined( 'NOVA_BRIDGE_SUITE_VERSION' ) ? NOVA_BRIDGE_SUITE_VERSION : '' ];
    }
    private static function validate_configuration( array $content, array $configuration ): array {
        $local = $configuration['local'] ?? []; $template = $configuration['template'] ?? [];
        $version = $template['definition']['version'] ?? null;
        if ( ( $configuration['mode'] ?? '' ) !== 'stored_layout_v3' || ! in_array( $version, [ 1, 3 ], true ) || ( 3 === $version && ( ( $content['kind'] ?? '' ) !== 'adaptation.delivery' || ( $content['schema_version'] ?? null ) !== 2 ) ) || self::digest( $content['configuration'] ?? null ) !== self::digest( $template ) || ( $configuration['site_id'] ?? null ) !== ( $content['site_id'] ?? null ) || ! Nova_Bridge_Suite_Posting_Protocol::uuid( $content['site_id'] ?? null ) || self::digest( $local ) !== ( $configuration['profile_digest'] ?? null ) ) { self::fail( 'configuration', 'This delivery does not match its exact approved stored-layout profile.' ); }
        if ( ! self::decimal( $content['url_id'] ?? null ) || ! self::decimal( $content['content_item_id'] ?? null ) || ! self::decimal( $content['content_item_version_id'] ?? null ) || ! is_int( $content['version_number'] ?? null ) || $content['version_number'] < 1 ) { self::fail( 'identity', 'The delivery needs its verified content and URL identities.' ); }
        if ( ! in_array( $local['routing']['operation'] ?? '', [ 'create', 'clone' ], true ) ) { self::fail( 'operation', 'The approved mapping is not configured to create pages from its saved layout.' ); }
        if ( ! empty( $local['routing']['locale'] ) && $local['routing']['locale'] !== ( $content['language'] ?? null ) ) { self::fail( 'locale', 'The delivery language differs from the approved mapping.' ); }
        self::checked( Nova_Bridge_Suite_Stored_Layout::validate( $local ) );
        return $local;
    }
    public static function policy( array $content, array $configuration ) {
        return self::guard( static function () use ( $content, $configuration ) {
            $local = self::validate_configuration( $content, $configuration ); $resolved = self::target( $content, $configuration, $local['stored_layout']['post_type'] );
            $publication = $local['routing']['publication'] ?? 'preserve';
            if ( ! in_array( $publication, [ 'publish', 'draft', 'preserve' ], true ) ) { self::fail( 'publication', 'This publication policy is unsupported.' ); }
            return [ 'mode' => 'stored_layout_v3', 'reference' => [ 'reference_type' => 'post', 'reference_id' => $resolved['post_id'] ], 'routing' => [ 'operation' => $resolved['post_id'] ? 'update' : 'create', 'publication' => 'preserve' === $publication && ! $resolved['post_id'] ? 'draft' : $publication ] ];
        } );
    }
    public static function probe_mapping( array $template, array $mapping, array $local, array $context = [] ) {
        return self::guard( static function () use ( $template, $local, $context ) {
            $actor = (int) ( $context['actor_user_id'] ?? 0 ); if ( $actor < 1 ) { self::fail( 'actor', 'Choose a valid publishing user before approving stored-layout creation.' ); }
            $previous = get_current_user_id(); wp_set_current_user( $actor );
            try {
            self::checked( Nova_Bridge_Suite_Stored_Layout::validate( $local ) );
            $stored = $local['stored_layout'];
            // Approval checks the captured artifact only. The example page may already be gone.
            $payload = $stored['writer_payload'] ?? [ 'elementor_data' => $stored['blueprint']['document'] ?? [] ];
            if ( isset( $stored['blueprint']['theme_presentation'] ) ) { $payload['theme_presentation'] = $stored['blueprint']['theme_presentation']; }
            $type = $stored['post_type'] ?? $local['routing']['post_type'] ?? 'page';
            $provider = self::provider( $type, $payload, 'publish' === ( $local['routing']['publication'] ?? '' ) ? 'publish' : 'draft' );
            return [ 'writer_id' => self::FORMAT, 'provider' => 'elementor:' . $provider['elementor'], 'provider_tuple' => $provider, 'evidence' => [ 'source_free' => true, 'layout_digest' => self::digest( $stored ) ] ];
            } finally { wp_set_current_user( $previous ); }
        } );
    }
    public static function plan( array $content, array $configuration, array $context ) {
        return self::guard( static function () use ( $content, $configuration, $context ) {
            $local = self::validate_configuration( $content, $configuration ); $operation = $context['operation_id'] ?? '';
            if ( ! Nova_Bridge_Suite_Posting_Protocol::uuid( $operation ) || ( isset( $context['business_result_id'] ) && $operation !== $context['business_result_id'] ) || ( isset( $context['site_id'] ) && $context['site_id'] !== $content['site_id'] ) ) { self::fail( 'operation', 'A matching durable operation identity is required.' ); }
            $values = $content['destination_values'] ?? [];
            if ( 1 === $configuration['template']['definition']['version'] ) {
                $sources = $content['content'] ?? []; if ( ! is_array( $sources ) ) { self::fail( 'values', 'Direct delivery content must be a source object.' ); }
                foreach ( [ 'url', 'page_type_frontend', 'language' ] as $name ) { $sources[$name] = $content[$name] ?? null; }
                $values = []; foreach ( $configuration['template']['mapping']['fields'] as $binding ) { if ( array_key_exists( $binding['source_field'], $sources ) && null !== $sources[$binding['source_field']] ) { $values[$binding['field_id']] = $sources[$binding['source_field']]; } }
            }
            $compiled = self::checked( Nova_Bridge_Suite_Stored_Layout::compile( Nova_Bridge_Suite_Writing_Adapter::adaptation_draft( $local ), $values, $content['site_id'] . ':' . $content['url_id'] ) );
            if ( ( $compiled['builder'] ?? '' ) !== 'elementor' ) { self::fail( 'builder', 'This native lifecycle currently supports stored Elementor layouts.' ); }
            $type = $compiled['post_type']; $resolved = self::target( $content, $configuration, $type );
            $target_id = $resolved['post_id']; $association = $resolved['association']; $target = $resolved['target'];
            if ( isset( $context['target_post_id'] ) && $context['target_post_id'] !== $target_id ) { self::fail( 'target_changed', 'The delivery URL acquired a different native target after its policy was selected.' ); }
            $publication = $local['routing']['publication'] ?? 'preserve'; $status = 'preserve' === $publication ? ( $target_id ? get_post_status( $target_id ) : 'draft' ) : $publication;
            if ( ! in_array( $status, [ 'publish', 'draft' ], true ) ) { self::fail( 'publication', 'Stored-layout creation and replacement support draft or published pages.' ); }
            $payload = $compiled['writer_payload']; $post_fields = $compiled['post_fields'];
            $title = $post_fields['title'] ?? '';
            if ( empty( $local['fields']['/title'] ) || ! in_array( $local['fields']['/title']['mode'] ?? '', [ 'adapt', 'mapped', 'protected' ], true ) ) {
                $headings = []; $walk = static function ( array $nodes ) use ( &$walk, &$headings ) { foreach ( $nodes as $node ) { if ( ( $node['widgetType'] ?? '' ) === 'heading' && ( $node['settings']['header_size'] ?? '' ) === 'h1' && is_string( $node['settings']['title'] ?? null ) ) { $headings[] = sanitize_text_field( $node['settings']['title'] ); } $walk( $node['elements'] ?? [] ); } }; $walk( $payload['elementor_data'] );
                $title = count( array_unique( $headings ) ) === 1 ? $headings[0] : str_replace( '-', ' ', $target['slug'] );
            }
            $title = sanitize_text_field( $title ); if ( '' === trim( $title ) || strlen( $title ) > 1000 ) { self::fail( 'title', 'The fitted layout needs a nonempty page title.' ); }
            $post_fields['title'] = $title; $provider = self::provider( $type, $payload, $status );
            $before = $target_id ? self::native_state( $target_id, $payload['theme_presentation'] ?? null, $payload['elementor_document_settings'] ?? null ) : null;
            if ( $target_id && ( $before['type'] !== $type || ! in_array( $before['status'], [ 'draft', 'publish' ], true ) || ( ! $association && $before['owner'] !== '' ) ) ) { self::fail( 'target_changed', 'The existing page type, status or ownership changed during planning.' ); }
            // Compare using the previous operation's scope, so a legacy page can adopt a newly captured theme snapshot.
            if ( $target_id && ( ! current_user_can( 'edit_post', $target_id ) || ( $association && self::digest( self::native_state( $target_id, $association['theme_presentation'] ?? null, $association['elementor_document_settings'] ?? null ) ) !== ( $association['native_digest'] ?? '' ) ) ) ) { self::fail( 'human_changed', 'This generated page changed after its verified save. Review its edits before replacing the owned document.' ); }
            $plan = [ 'format' => self::FORMAT, 'operation_id' => $operation, 'context' => $context, 'site_id' => $content['site_id'], 'template_id' => $configuration['template']['id'], 'template_revision' => $configuration['template']['revision'], 'profile_digest' => $configuration['profile_digest'], 'layout_digest' => $compiled['layout_digest'], 'post_type' => $type, 'payload' => $payload, 'post_fields' => $post_fields, 'target' => $target, 'target_post_id' => $target_id, 'desired_status' => $status, 'provider' => $provider, 'native_before' => $before, 'association_before' => $association, 'delivery_identity' => array_intersect_key( $content, array_flip( [ 'site_id', 'url_id', 'content_item_id', 'content_item_version_id', 'version_number', 'source_sha256', 'language' ] ) ) ];
            if ( strlen( wp_json_encode( $plan ) ) > 786432 ) { self::fail( 'plan_size', 'This layout exceeds the recoverable native journal size.' ); }
            $plan['plan_digest'] = self::digest( $plan ); return $plan;
        } );
    }
    private static function assert_plan( array $plan, string $operation ): void {
        $copy = $plan; unset( $copy['plan_digest'] );
        if ( ( $plan['format'] ?? '' ) !== self::FORMAT || $operation !== ( $plan['operation_id'] ?? '' ) || ! Nova_Bridge_Suite_Posting_Protocol::uuid( $operation ) || self::digest( $copy ) !== ( $plan['plan_digest'] ?? '' ) ) { self::fail( 'plan_identity', 'The retained native plan or operation identity changed.' ); }
    }
    private static function reservation_marker( array $plan ): string { return self::FORMAT . ':' . $plan['operation_id'] . ':' . $plan['plan_digest']; }
    private static function reserved_post( array $plan ): int {
        global $wpdb;
        $ids = $wpdb->get_col( $wpdb->prepare( 'SELECT ID FROM `' . $wpdb->posts . '` WHERE post_content_filtered = %s AND post_type = %s ORDER BY ID', self::reservation_marker( $plan ), $plan['post_type'] ) );
        if ( ! empty( $wpdb->last_error ) || ! is_array( $ids ) || count( $ids ) > 1 ) { self::fail( 'reservation_ambiguous', 'The native reservation cannot be uniquely reconciled. No second page was created.' ); }
        return $ids ? (int) $ids[0] : 0;
    }
    private static function owner( array $plan ): array { return [ 'format' => self::FORMAT, 'site_id' => $plan['site_id'], 'url_id' => $plan['delivery_identity']['url_id'], 'template_id' => $plan['template_id'], 'creation_operation' => $plan['association_before']['creation_operation'] ?? $plan['operation_id'] ]; }
    private static function native_state( int $id, ?array $theme = null, ?array $document_settings = null ): array {
        clean_post_cache( $id ); $post = get_post( $id ); if ( ! $post || $post->post_status === 'trash' ) { self::fail( 'target_missing', 'The retained generated page no longer exists. It will not be recreated automatically.' ); }
        $raw = get_post_meta( $id, '_elementor_data', true ); $elements = '' === $raw ? [] : ( is_array( $raw ) ? $raw : json_decode( (string) $raw, true ) );
        if ( ! is_array( $elements ) ) { self::fail( 'native_data', 'The retained native Elementor document is unreadable.' ); }
        $state = [ 'post_id' => $id, 'type' => $post->post_type, 'status' => $post->post_status, 'title' => $post->post_title, 'excerpt' => $post->post_excerpt, 'slug' => $post->post_name, 'parent' => (int) $post->post_parent, 'author' => (int) $post->post_author, 'content' => $post->post_content, 'reservation' => $post->post_content_filtered, 'elements' => $elements, 'page_settings' => get_post_meta( $id, '_elementor_page_settings', true ), 'template' => get_page_template_slug( $id ), 'native_language' => function_exists( 'pll_get_post_language' ) ? pll_get_post_language( $id, 'slug' ) : null, 'owner' => get_post_meta( $id, self::OWNER, true ) ];
        if ( null !== $theme ) { $state['theme_presentation'] = self::checked( Nova_Bridge_Suite_Theme_Presentation::native_state( $id, $theme ) ); }
        if ( null !== $document_settings ) { $state['document_presentation'] = self::checked( \SEOR_Elementor_Bridge\Document_Presentation::read_state( $id, $document_settings ) ); }
        return $state;
    }
    private static function check_content( array $plan, int $id ): array {
        $state = self::native_state( $id, $plan['payload']['theme_presentation'] ?? null, $plan['payload']['elementor_document_settings'] ?? null );
        if ( $state['type'] !== $plan['post_type'] || $state['title'] !== $plan['post_fields']['title'] || $state['excerpt'] !== ( $plan['post_fields']['excerpt'] ?? '' ) || $state['slug'] !== $plan['target']['slug'] || $state['parent'] !== $plan['target']['parent'] || $state['owner'] !== self::owner( $plan ) || ( $plan['target_post_id'] && $state['author'] !== $plan['native_before']['author'] ) ) { self::fail( 'native_mismatch', 'The retained page identity or fitted fields differ from the approved plan.' ); }
        self::checked( Nova_Bridge_Suite_Recipe_Builders::verify_saved( 'elementor', $id, $plan['payload'] ) );
        if ( isset( $plan['payload']['theme_presentation'] ) ) { self::checked( Nova_Bridge_Suite_Theme_Presentation::verify( $id, $plan['payload']['theme_presentation'] ) ); }
        self::checked( Nova_Bridge_Suite_Stored_Layout_Language::verify( 'post_type:' . $plan['post_type'], $id, $plan['target'] ) );
        return $state;
    }
    private static function existing_save_witnessed( array $plan, int $id ): bool {
        try { self::check_content( $plan, $id ); return true; }
        catch ( Nova_Bridge_Suite_Stored_Layout_Writer_Failure $error ) {
            $claimed = $plan['native_before']; $claimed['owner'] = self::owner( $plan );
            if ( self::native_state( $id, $plan['payload']['theme_presentation'] ?? null, $plan['payload']['elementor_document_settings'] ?? null ) === $claimed ) { return false; }
            throw $error;
        }
    }
    private static function check_url( array $plan, int $id ): void {
        if ( 'publish' === get_post_status( $id ) ) { $url = self::checked( Nova_Bridge_Suite_Mapped_Content_Target::native_url( 'post_type:' . $plan['post_type'], 'post', $id ) ); }
        else {
            if ( ! function_exists( 'get_sample_permalink' ) && is_file( ABSPATH . 'wp-admin/includes/post.php' ) ) { require_once ABSPATH . 'wp-admin/includes/post.php'; }
            if ( ! function_exists( 'get_sample_permalink' ) ) { self::fail( 'permalink', 'WordPress cannot verify the draft future permalink.' ); }
            $sample = get_sample_permalink( $id ); $url = str_replace( [ '%pagename%', '%postname%' ], $sample[1] ?? '', $sample[0] ?? '' );
        }
        $actual = self::checked( Nova_Bridge_Suite_Mapped_Content_Target::normalize_url( $url ) );
        if ( $actual['identity'] !== $plan['target']['target_url'] ) { self::fail( 'permalink', 'The saved page URL differs from the delivery URL. Its ID was retained; no duplicate will be created.' ); }
    }
    private static function result( array $record ): array { $id = (int) $record['post_id']; return [ 'state' => 'complete', 'operation_id' => $record['plan']['operation_id'], 'post_id' => $id, 'cms_post_status' => get_post_status( $id ), 'url' => get_permalink( $id ), 'native_digest' => $record['native_digest'] ]; }
    private static function complete( array $plan, array $record ): array {
        $id = (int) $record['post_id']; $state = self::check_content( $plan, $id ); self::check_url( $plan, $id );
        if ( $state['status'] !== $plan['desired_status'] ) { self::fail( 'publication', 'The retained page has not reached the intended native publication state.' ); }
        $record['state'] = 'committed'; $record['native_digest'] = self::digest( $state ); self::checkpoint( self::operation_key( $plan['operation_id'] ), $record );
        $association = $plan['delivery_identity'] + [ 'state' => 'committed', 'post_id' => $id, 'target' => $plan['target'], 'template_id' => $plan['template_id'], 'template_revision' => $plan['template_revision'], 'profile_digest' => $plan['profile_digest'], 'layout_digest' => $plan['layout_digest'], 'native_digest' => $record['native_digest'], 'operation_id' => $plan['operation_id'], 'creation_operation' => self::owner( $plan )['creation_operation'] ];
        if ( isset( $plan['payload']['theme_presentation'] ) ) { $association['theme_presentation'] = $plan['payload']['theme_presentation']; }
        if ( isset( $plan['payload']['elementor_document_settings'] ) ) { $association['elementor_document_settings'] = $plan['payload']['elementor_document_settings']; }
        $current = self::association( $plan['delivery_identity'] );
        if ( $current !== $association && $current !== $plan['association_before'] && ( $current['operation_id'] ?? '' ) !== $plan['operation_id'] ) { self::fail( 'association_changed', 'Another operation acquired this page association.' ); }
        self::checkpoint( self::association_key( $plan['site_id'], $plan['delivery_identity']['url_id'] ), $association ); return self::result( $record );
    }
    public static function apply( array $plan, string $operation ) {
        return self::guard( static function () use ( $plan, $operation ) {
            self::assert_plan( $plan, $operation );
            return self::locked( $plan, static function () use ( $plan, $operation ) { return self::checked( Nova_Bridge_Suite_Stored_Layout_Language::with_context( $plan['target'], static function () use ( $plan, $operation ) {
                if ( self::provider( $plan['post_type'], $plan['payload'], $plan['desired_status'] ) !== $plan['provider'] ) { self::fail( 'provider_changed', 'The native provider changed after planning.' ); }
                $key = self::operation_key( $operation ); $record = self::option( $key );
                if ( null !== $record && ( ! is_array( $record ) || ( $record['plan_digest'] ?? '' ) !== $plan['plan_digest'] ) ) { self::fail( 'operation_reused', 'This operation belongs to a different native plan.' ); }
                if ( $record && 'committed' === $record['state'] ) { self::verify_record( $plan, $record ); return self::complete( $plan, $record ); }
                if ( ! $record ) { if ( self::association( $plan['delivery_identity'] ) !== $plan['association_before'] ) { self::fail( 'association_changed', 'The URL association changed after planning.' ); } $record = [ 'state' => 'prepared', 'plan_digest' => $plan['plan_digest'], 'plan' => $plan, 'post_id' => $plan['target_post_id'] ]; self::checkpoint( $key, $record ); }
                $association = self::association( $plan['delivery_identity'] );
                if ( $association !== $plan['association_before'] && ( $association['operation_id'] ?? '' ) !== $operation ) { self::fail( 'association_pending', 'Another operation owns this URL.' ); }
                if ( ! $record['post_id'] ) {
                    $found = self::reserved_post( $plan );
                    if ( $found ) { $record['post_id'] = $found; $record['state'] = 'reserved'; self::checkpoint( $key, $record ); }
                    elseif ( $record['state'] === 'creating' ) { self::fail( 'create_unknown', 'An uncertain creation has no unique reservation. Inspect it before trying again.' ); }
                    else {
                        self::checked( Nova_Bridge_Suite_Stored_Layout_Language::creation( 'post_type:' . $plan['post_type'], $plan['target']['target_url'], $plan['delivery_identity']['language'] ) );
                        self::checkpoint( self::association_key( $plan['site_id'], $plan['delivery_identity']['url_id'] ), $plan['delivery_identity'] + [ 'state' => 'pending', 'operation_id' => $operation, 'post_id' => 0 ] );
                        $record['state'] = 'creating'; self::checkpoint( $key, $record ); $marker = self::reservation_marker( $plan );
                        $protect = static function ( $data, $postarr, $raw ) use ( $marker ) { if ( ( $raw['post_content_filtered'] ?? null ) === $marker ) { $data['post_content_filtered'] = $marker; } return $data; };
                        add_filter( 'wp_insert_post_data', $protect, PHP_INT_MAX, 3 );
                        try { $id = wp_insert_post( wp_slash( [ 'post_type' => $plan['post_type'], 'post_status' => 'draft', 'post_title' => $plan['post_fields']['title'], 'post_name' => $plan['target']['slug'], 'post_parent' => $plan['target']['parent'], 'post_author' => get_current_user_id(), 'post_content_filtered' => $marker ] ), true ); } finally { remove_filter( 'wp_insert_post_data', $protect, PHP_INT_MAX ); }
                        if ( is_wp_error( $id ) || ! $id || self::reserved_post( $plan ) !== (int) $id ) { self::fail( 'create_unknown', 'WordPress did not confirm its reserved page. Reconcile this same operation.' ); }
                        $record['post_id'] = (int) $id; $record['state'] = 'reserved'; self::checkpoint( $key, $record );
                    }
                }
                $id = (int) $record['post_id']; if ( ! current_user_can( 'edit_post', $id ) ) { self::fail( 'permission', 'The configured actor cannot edit the retained page.' ); }
                $saved = in_array( $record['state'], [ 'saving', 'saved', 'publishing' ], true );
                if ( $saved && $record['state'] === 'saving' && $plan['target_post_id'] ) { $saved = self::existing_save_witnessed( $plan, $id ); }
                if ( $saved ) { self::check_content( $plan, $id ); }
                else {
                    $state = self::native_state( $id, $plan['payload']['theme_presentation'] ?? null, $plan['payload']['elementor_document_settings'] ?? null );
                    if ( $plan['target_post_id'] ) {
                        $before = $plan['native_before']; $claimed = $before; $claimed['owner'] = self::owner( $plan );
                        // A lost ownership-marker response may be resumed only for this exact frozen plan.
                        if ( $state !== $before && $state !== $claimed ) { self::fail( 'human_changed', 'The existing page changed after planning.' ); }
                        if ( $before['owner'] !== '' && $before['owner'] !== self::owner( $plan ) ) { self::fail( 'ownership', 'The page belongs to another native operation.' ); }
                        self::check_url( $plan, $id );
                    }
                    elseif ( $state['reservation'] !== self::reservation_marker( $plan ) || $state['elements'] || '' !== $state['content'] || 'draft' !== $state['status'] ) { self::fail( 'reservation_changed', 'The reserved page changed before the native layout save.' ); }
                    update_post_meta( $id, self::OWNER, self::owner( $plan ) ); if ( get_post_meta( $id, self::OWNER, true ) !== self::owner( $plan ) ) { self::fail( 'storage', 'The page ownership marker was not retained.' ); }
                    if ( ! $plan['target_post_id'] ) { self::checked( Nova_Bridge_Suite_Stored_Layout_Language::assign( $id, $plan['target'] ) ); }
                    else { self::checked( Nova_Bridge_Suite_Stored_Layout_Language::verify( 'post_type:' . $plan['post_type'], $id, $plan['target'] ) ); }
                    $record['state'] = 'saving'; self::checkpoint( $key, $record );
                    $payload = $plan['payload']; $payload['elementor_data'] = wp_json_encode( $payload['elementor_data'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); $payload['title'] = wp_slash( $plan['post_fields']['title'] ); $payload['excerpt'] = wp_slash( $plan['post_fields']['excerpt'] ?? '' );
                    $service = new \SEOR_Elementor_Bridge\Elementor_Service(); self::checked( $service->update_page( $id, $payload ) );
                    if ( isset( $plan['payload']['theme_presentation'] ) ) { self::checked( Nova_Bridge_Suite_Theme_Presentation::apply( $id, $plan['payload']['theme_presentation'] ) ); }
                    self::check_content( $plan, $id );
                }
                $record['state'] = 'saved'; self::checkpoint( $key, $record ); self::check_url( $plan, $id );
                if ( get_post_status( $id ) !== $plan['desired_status'] ) {
                    if ( ! in_array( get_post_status( $id ), [ 'draft', 'publish' ], true ) || wp_unique_post_slug( $plan['target']['slug'], $id, $plan['desired_status'], $plan['post_type'], $plan['target']['parent'] ) !== $plan['target']['slug'] ) { self::fail( 'publication', 'The page status or planned slug changed before publication.' ); }
                    $record['state'] = 'publishing'; self::checkpoint( $key, $record ); self::checked( wp_update_post( [ 'ID' => $id, 'post_status' => $plan['desired_status'] ], true ) );
                }
                return self::complete( $plan, $record );
            } ) ); } );
        } );
    }
    private static function verify_record( array $plan, array $record ): array {
        $state = self::check_content( $plan, (int) $record['post_id'] ); self::check_url( $plan, (int) $record['post_id'] );
        if ( self::digest( $state ) !== ( $record['native_digest'] ?? '' ) || $state['status'] !== $plan['desired_status'] ) { self::fail( 'human_changed', 'The generated page changed after its verified save.' ); }
        return self::result( $record );
    }
    public static function recover( array $plan, string $operation ) {
        return self::guard( static function () use ( $plan, $operation ) {
            self::assert_plan( $plan, $operation ); return self::locked( $plan, static function () use ( $plan, $operation ) {
                $record = self::option( self::operation_key( $operation ) );
                if ( ! $record ) {
                    if ( $plan['target_post_id'] && self::native_state( $plan['target_post_id'], $plan['payload']['theme_presentation'] ?? null, $plan['payload']['elementor_document_settings'] ?? null ) === $plan['native_before'] && self::association( $plan['delivery_identity'] ) === $plan['association_before'] ) { return [ 'state' => 'not_committed', 'no_native_commit' => true, 'safe_to_apply' => true ]; }
                    if ( self::reserved_post( $plan ) || $plan['target_post_id'] ) { self::fail( 'orphaned_native', 'Native evidence exists without its journal. No second page will be created.' ); }
                    return [ 'state' => 'not_committed', 'no_native_commit' => true, 'safe_to_apply' => true ];
                }
                if ( ( $record['plan_digest'] ?? '' ) !== $plan['plan_digest'] ) { self::fail( 'operation_reused', 'The retained operation belongs to a different plan.' ); }
                if ( 'committed' === $record['state'] ) { self::verify_record( $plan, $record ); return self::complete( $plan, $record ); }
                $id = (int) ( $record['post_id'] ?: self::reserved_post( $plan ) );
                if ( $id && in_array( $record['state'], [ 'saving', 'saved', 'publishing' ], true ) ) {
                    if ( $record['state'] === 'saving' && $plan['target_post_id'] && ! self::existing_save_witnessed( $plan, $id ) ) { return [ 'state' => 'not_committed', 'no_native_commit' => false, 'safe_to_apply' => true, 'post_id' => $id ]; }
                    self::check_content( $plan, $id ); if ( get_post_status( $id ) === $plan['desired_status'] ) { $record['post_id'] = $id; return self::complete( $plan, $record ); }
                }
                if ( $id ) { return [ 'state' => 'not_committed', 'no_native_commit' => false, 'safe_to_apply' => true, 'post_id' => $id ]; }
                if ( 'creating' === $record['state'] ) { self::fail( 'create_unknown', 'The original page creation remains uncertain. No duplicate was created.' ); }
                return [ 'state' => 'not_committed', 'no_native_commit' => true, 'safe_to_apply' => true ];
            } );
        } );
    }
    public static function finish( array $plan, array $result ) { return self::verify( $plan, $result ); }
    /** Remote retirement permits a newer plan to adopt a uniquely reserved page.
     * Does not finish/publish the old content or invent a successful old receipt. */
    public static function supersede( array $plan, string $operation ) {
        return self::guard( static function () use ( $plan, $operation ) {
            self::assert_plan( $plan, $operation );
            return self::locked( $plan, static function () use ( $plan, $operation ) {
                $record = self::option( self::operation_key( $operation ) );
                if ( ! $record ) { return self::recover( $plan, $operation ); }
                if ( ( $record['plan_digest'] ?? '' ) !== $plan['plan_digest'] ) { self::fail( 'operation_reused', 'The retired operation belongs to another plan.' ); }
                if ( 'committed' === $record['state'] ) { self::verify_record( $plan, $record ); }
                $id = (int) ( $record['post_id'] ?: self::reserved_post( $plan ) );
                if ( ! $id ) {
                    if ( 'creating' === $record['state'] ) { self::fail( 'create_unknown', 'An uncertain creation has no unique reservation.' ); }
                    return [ 'state' => 'not_committed', 'no_native_commit' => true ];
                }
                $state = self::native_state( $id, $plan['payload']['theme_presentation'] ?? null, $plan['payload']['elementor_document_settings'] ?? null );
                if ( ! current_user_can( 'edit_post', $id ) || $state['type'] !== $plan['post_type'] ||
                    ( ! $plan['target_post_id'] && $state['reservation'] !== self::reservation_marker( $plan ) ) ||
                    ( $state['owner'] !== '' && $state['owner'] !== self::owner( $plan ) ) ) { self::fail( 'ownership', 'The retired page identity cannot be reconciled.' ); }
                self::check_url( $plan, $id );
                $current = self::association( $plan['delivery_identity'] );
                if ( $current !== $plan['association_before'] && ( $current['operation_id'] ?? '' ) !== $operation ) { self::fail( 'association_changed', 'Another operation owns the URL.' ); }
                $association = $plan['delivery_identity'] + [ 'state' => 'retired', 'post_id' => $id, 'target' => $plan['target'], 'template_id' => $plan['template_id'], 'native_digest' => self::digest( $state ), 'operation_id' => $operation, 'creation_operation' => self::owner( $plan )['creation_operation'] ];
                foreach ( [ 'theme_presentation', 'elementor_document_settings' ] as $key ) { if ( isset( $plan['payload'][$key] ) ) { $association[$key] = $plan['payload'][$key]; } }
                self::checkpoint( self::association_key( $plan['site_id'], $plan['delivery_identity']['url_id'] ), $association );
                return [ 'state' => 'retired', 'post_id' => $id, 'native_digest' => $association['native_digest'] ];
            } );
        } );
    }
    public static function verify( array $plan, array $result ) {
        return self::guard( static function () use ( $plan, $result ) { self::assert_plan( $plan, $plan['operation_id'] ); $record = self::option( self::operation_key( $plan['operation_id'] ) ); if ( ! is_array( $record ) || 'committed' !== $record['state'] || $record['plan_digest'] !== $plan['plan_digest'] || $record['post_id'] !== ( $result['post_id'] ?? null ) ) { self::fail( 'unverified', 'A verified native save is required before publication confirmation.' ); } return self::verify_record( $plan, $record ); } );
    }
}
