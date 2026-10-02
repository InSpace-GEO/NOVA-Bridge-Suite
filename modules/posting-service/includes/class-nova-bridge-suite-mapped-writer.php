<?php
/** Exact local row plans. No generic save hooks, remote commands or whole-parent ACF writes. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Writer_Failure extends RuntimeException {
    public $reason;
    public function __construct( string $reason, string $message ) { $this->reason = $reason; parent::__construct( $message ); }
}

final class Nova_Bridge_Suite_Mapped_Writer {
    public const WRITER_ID = 'nova_verified_rows_v3';
    private const MAX_SNAPSHOT_BYTES = 8388608;
    private static $elementor_derived = null;

    /** Local PHP integration only. Registration describes an independently reviewed exact tuple. */
    public static function register_elementor_derived( string $version, callable $callback, string $evidence_id, ?callable $preflight = null ): void {
        self::$elementor_derived = [ 'version' => $version, 'callback' => $callback, 'evidence_id' => $evidence_id, 'preflight' => $preflight ];
    }

    private static function fail( string $reason, string $message ): void { throw new Nova_Bridge_Suite_Writer_Failure( $reason, $message ); }
    private static function guard( callable $operation ) {
        try { return $operation(); }
        catch ( Nova_Bridge_Suite_Writer_Failure $error ) { return new WP_Error( 'nova_writer_' . $error->reason, $error->getMessage(), [ 'status' => 409, 'blocked' => true ] ); }
        catch ( Throwable $error ) { return new WP_Error( 'nova_writer_internal', 'The local writer failed. Reconcile its durable operation marker before retrying.', [ 'status' => 500, 'blocked' => true ] ); }
    }
    private static function json( $value ): string {
        $json = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( false === $json ) { self::fail( 'encoding', 'The plan contains invalid JSON values.' ); }
        return $json;
    }
    private static function sorted( $value ) {
        if ( ! is_array( $value ) ) { return $value; }
        if ( $value && array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) { ksort( $value, SORT_STRING ); }
        foreach ( $value as $key => $item ) { $value[ $key ] = self::sorted( $item ); }
        return $value;
    }
    public static function digest( $value ): string { return hash( 'sha256', self::json( self::sorted( $value ) ) ); }
    private static function same( $first, $second ): bool { return self::digest( $first ) === self::digest( $second ); }
    private static function uuid( $value ): bool { return is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $value ); }
    private static function delivery_identity( array $content, array $context ): array {
        if ( ! self::uuid( $content['id'] ?? null ) || ! self::uuid( $context['attempt_id'] ?? null ) || ! is_string( $context['snapshot_sha256'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $context['snapshot_sha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $content['source_sha256'] ?? '' ) ) { self::fail( 'snapshot_identity', 'The verified delivery hash and current response-header attempt UUID are required.' ); }
        foreach ( [ 'url_id', 'content_item_id', 'content_item_version_id' ] as $key ) { if ( ! is_string( $content[ $key ] ?? null ) || ! preg_match( '/^[1-9][0-9]{0,18}$/D', $content[ $key ] ) || ( isset( $context[ $key ] ) && $context[ $key ] !== $content[ $key ] ) ) { self::fail( 'snapshot_identity', 'The verified delivery and journal content identities disagree.' ); } }
        if ( isset( $context['delivery_id'] ) && $context['delivery_id'] !== $content['id'] ) { self::fail( 'snapshot_identity', 'The delivery UUID differs from its journal.' ); }
        if ( isset( $context['source_sha256'] ) && $context['source_sha256'] !== $content['source_sha256'] ) { self::fail( 'snapshot_identity', 'The canonical source hash differs from its journal.' ); }
        return [ 'site_id' => $content['site_id'], 'delivery_id' => $content['id'], 'url_id' => $content['url_id'], 'content_id' => $content['content_item_id'], 'content_item_version_id' => $content['content_item_version_id'], 'version' => $content['version_number'], 'attempt_id' => $context['attempt_id'], 'snapshot_sha256' => $context['snapshot_sha256'], 'source_sha256' => $content['source_sha256'], 'template_id' => $content['configuration']['id'], 'template_revision' => $content['configuration']['revision'] ];
    }
    private static function actor( array $context, callable $operation ) {
        $id = (int) ( $context['actor_user_id'] ?? 0 );
        if ( $id < 1 || ! get_userdata( $id ) ) { self::fail( 'actor', 'A valid locally authorized publishing user is required.' ); }
        $previous = get_current_user_id(); wp_set_current_user( $id );
        try { return $operation(); } finally { wp_set_current_user( $previous ); }
    }
    private static function meta_rows( array $snapshot, string $key ): array { return array_values( array_filter( $snapshot['meta'], static function ( $row ) use ( $key ) { return $row['meta_key'] === $key; } ) ); }
    private static function one_meta( array $snapshot, string $key ): array {
        $rows = self::meta_rows( $snapshot, $key );
        if ( count( $rows ) !== 1 ) { self::fail( 'physical_identity', 'A required metadata row is missing or duplicated: ' . $key ); }
        return $rows[0];
    }
    private static function pointer_parts( string $path ): array {
        if ( '' === $path || '/' !== $path[0] || preg_match( '/~(?![01])/', $path ) ) { self::fail( 'pointer', 'A native target contains an invalid JSON pointer.' ); }
        return array_map( static function ( $part ) { return str_replace( [ '~1', '~0' ], [ '/', '~' ], $part ); }, explode( '/', substr( $path, 1 ) ) );
    }
    private static function pointer( array $parts ): string { return '/' . implode( '/', array_map( static function ( $part ) { return str_replace( [ '~', '/' ], [ '~0', '~1' ], (string) $part ); }, $parts ) ); }

    public static function plan( array $content, array $configuration, array $context ) {
        return self::guard( static function () use ( $content, $configuration, $context ) {
            return self::actor( $context, static function () use ( $content, $configuration, $context ) {
                $local = $configuration['local'] ?? [];
                if ( isset( $context['target_post_id'] ) && (int) $context['target_post_id'] !== (int) ( $local['reference_id'] ?? 0 ) ) { self::fail( 'target_changed', 'The frozen native reference differs from the approved local mapping.' ); }
                $post_id = (int) ( $local['reference_id'] ?? 0 );
                if ( ! current_user_can( 'edit_post', $post_id ) ) { self::fail( 'permission', 'The publishing user cannot edit this native document.' ); }
                $entity = Nova_Bridge_Suite_Strategy::entity( 'post', $post_id );
                if ( ! $entity ) { self::fail( 'target', 'The selected document is not an eligible local publishing target.' ); }
                $context['layout_signature'] = Nova_Bridge_Suite_Strategy::fingerprint( $entity )['signature'];
                $context['source_post_id'] = $post_id; $context['provider_tuple'] = self::runtime_tuple();
                $inventory = array_column( Nova_Bridge_Suite_Strategy::field_inventory( $entity ), null, 'path' );
                $plan = self::build_plan( $content, $configuration, $context, self::snapshot( $post_id ), $inventory );
                self::check_row_permissions( $plan, $post_id );
                $type = get_post_type_object( $plan['snapshot']['post']['post_type'] );
                if ( ! $type || ( 'clone' === $plan['operation'] && ! current_user_can( $type->cap->create_posts ) ) || ( 'publish' === $plan['desired_status'] && ! current_user_can( $type->cap->publish_posts ) ) ) { self::fail( 'permission', 'The publishing user lacks the required create or publication capability.' ); }
                self::database_capability(); self::check_provider_capability( $plan ); return $plan;
            } );
        } );
    }

    /** Pure plan construction over a fresh raw snapshot; rejects unsafe input before native writes. */
    public static function build_plan( array $content, array $configuration, array $context, array $snapshot, array $inventory ): array {
        $template = $configuration['template'] ?? $configuration['remote'] ?? [];
        $local = $configuration['local'] ?? [];
        if ( ! is_array( $content['configuration'] ?? null ) || ! self::same( $content['configuration'], $template ) || ! self::uuid( $template['id'] ?? null ) || ! is_int( $template['revision'] ?? null ) || $template['revision'] < 1 ) { self::fail( 'configuration', 'Execution requires the exact frozen delivery template and a retained local profile.' ); }
        if ( ! self::uuid( $context['site_id'] ?? null ) || ( $configuration['site_id'] ?? null ) !== $context['site_id'] || ( $content['site_id'] ?? null ) !== $context['site_id'] ) { self::fail( 'site_mismatch', 'The delivery does not belong to this installation.' ); }
        if ( empty( $local['revision'] ) || ( $local['reference_type'] ?? '' ) !== 'post' ) { self::fail( 'configuration', 'Execution requires an immutable retained concrete post profile.' ); }
        if ( (int) ( $snapshot['post']['ID'] ?? 0 ) !== (int) ( $local['reference_id'] ?? 0 ) || ( isset( $context['target_post_id'] ) && (int) $context['target_post_id'] !== (int) $local['reference_id'] ) ) { self::fail( 'target_changed', 'The native plan cannot retarget the frozen mapping to a different document.' ); }
        if ( ( $local['signature'] ?? null ) !== ( $context['layout_signature'] ?? null ) ) { self::fail( 'layout_drift', 'The native layout changed since this mapping was approved.' ); }
        self::verify_policy( $template, $local );
        $profile_digest = hash( 'sha256', Nova_Bridge_Suite_Writing_Adapter::canonical_json( $local ) );
        if ( ! is_string( $configuration['profile_digest'] ?? null ) || ! hash_equals( $profile_digest, $configuration['profile_digest'] ) ) { self::fail( 'policy', 'The retained local routing, instructions or protection policy changed.' ); }
        if ( ! is_int( $content['version_number'] ?? null ) || $content['version_number'] < 1 || ( $context['version'] ?? $content['version_number'] ) !== $content['version_number'] ) { self::fail( 'version', 'The worker and fetched content versions do not agree.' ); }
        if ( ( $local['routing']['locale'] ?? '' ) !== '' && ( $content['language'] ?? null ) !== $local['routing']['locale'] ) { self::fail( 'locale', 'The content locale does not match the retained mapping.' ); }
        if ( ! self::uuid( $context['operation_id'] ?? null ) || ( isset( $context['business_result_id'] ) && $context['business_result_id'] !== $context['operation_id'] ) ) { self::fail( 'operation', 'A durable business-result UUID is required before execution.' ); }
        $delivery_identity = self::delivery_identity( $content, $context );
        $operation = $local['routing']['operation'] ?? '';
        if ( ! in_array( $operation, [ 'update', 'clone' ], true ) || ( isset( $context['effective_operation'] ) && $context['effective_operation'] !== $operation ) || ( isset( $context['operation'] ) && $context['operation'] !== $operation ) ) { self::fail( 'operation', 'The worker operation differs from the approved local mapping.' ); }
        $publication = $local['routing']['publication'] ?? 'preserve';
        if ( ! in_array( $publication, [ 'preserve', 'draft', 'publish' ], true ) ) { self::fail( 'publication', 'The approved publication policy is unsupported.' ); }
        $desired = 'preserve' === $publication ? ( 'clone' === $operation ? 'draft' : $snapshot['post']['post_status'] ) : $publication;
        if ( ! in_array( $snapshot['post']['post_status'], [ 'publish', 'draft', 'private', 'pending', 'future' ], true ) || ! in_array( $desired, [ 'publish', 'draft', 'private', 'pending', 'future' ], true ) ) { self::fail( 'publication', 'This native post state is outside the direct writer scope.' ); }
        $taxonomy = self::taxonomy_plan( $snapshot, $operation, $desired );
        if ( isset( $local['routing']['post_type'] ) && $local['routing']['post_type'] !== $snapshot['post']['post_type'] ) { self::fail( 'post_type', 'The document post type changed.' ); }
        if ( strlen( self::json( $snapshot ) ) > self::MAX_SNAPSHOT_BYTES ) { self::fail( 'snapshot_size', 'This document exceeds the bounded writer snapshot size.' ); }
        $values = $content['content'] ?? null;
        if ( ! is_array( $values ) ) { self::fail( 'values', 'Delivery content must be an object of canonical source values.' ); }
        foreach ( [ 'url', 'page_type_frontend', 'language' ] as $source ) { $values[ $source ] = $content[ $source ] ?? null; }
        $definitions = array_column( $template['definition']['fields'] ?? [], null, 'id' );
        $bindings = array_column( $template['mapping']['fields'] ?? [], 'source_field', 'field_id' );
        $writes = []; $guards = [];
        foreach ( $local['fields'] ?? [] as $path => $field ) {
            $descriptor = $local['target_descriptors'][ $path ] ?? null;
            if ( ! is_array( $descriptor ) || ( $descriptor['path'] ?? '' ) !== $path ) { self::fail( 'local_descriptor', 'A retained local target descriptor is missing or changed.' ); }
            if ( 'protected' === ( $field['mode'] ?? '' ) ) { $guards[] = self::resolve_target( $descriptor, $snapshot, $inventory, true ); continue; }
            if ( 'leave_empty' === ( $field['mode'] ?? '' ) ) { if ( 'clone' === $operation ) { $writes[] = [ 'target' => self::resolve_target( $descriptor, $snapshot, $inventory ), 'value' => '', 'source_path' => null ]; } continue; }
            if ( 'mapped' !== ( $field['mode'] ?? '' ) ) { self::fail( 'configuration', 'The retained field policy is unsupported.' ); }
            $id = Nova_Bridge_Suite_Writing_Adapter::field_id( $path );
            $definition = $definitions[ $id ] ?? null; $source = $bindings[ $id ] ?? null;
            if ( ! is_array( $definition ) || ! is_string( $source ) || $source !== ( $field['source_path'] ?? '' ) ) { self::fail( 'binding_identity', 'The frozen source binding differs from the retained native target.' ); }
            $target = self::resolve_target( $descriptor, $snapshot, $inventory );
            if ( ! array_key_exists( $source, $values ) || null === $values[ $source ] ) { if ( ! empty( $definition['required'] ) ) { self::fail( 'missing_value', 'A required mapped source has no generated value.' ); } continue; }
            $value = $values[ $source ];
            self::validate_value( $value, $definition );
            if ( 'post' === $target['kind'] && 'post_name' === $target['column'] ) {
                if ( 'url' !== $source ) { self::fail( 'slug', 'Native slugs must be explicitly mapped from the delivery URL.' ); }
                $value = self::url_slug( $value );
            }
            $writes[] = [ 'target' => $target, 'value' => $value, 'source_path' => $source ];
        }
        $changes = [ 'post' => [], 'meta' => [] ]; $footprints = []; $elementor = []; $uses_acf = false;
        foreach ( $writes as $write ) {
            $target = $write['target']; $value = $write['value'];
            if ( ! is_string( $value ) ) { self::fail( 'value_adapter', 'This direct writer only accepts scalar text values. Image, link and list targets need a verified typed adapter.' ); }
            if ( '' !== $value && ( ( 'email' === ( $target['value_type'] ?? '' ) && false === filter_var( $value, FILTER_VALIDATE_EMAIL ) ) || ( 'url' === ( $target['value_type'] ?? '' ) && ( false === filter_var( $value, FILTER_VALIDATE_URL ) || ! preg_match( '#^https?://#i', $value ) ) ) ) ) { self::fail( 'target_value', 'The generated scalar is invalid for its native email or URL field.' ); }
            foreach ( $guards as $protected ) { if ( self::overlaps( $target, $protected ) ) { self::fail( 'protected_overlap', 'A planned replacement overlaps a protected native component.' ); } }
            foreach ( $footprints as $other ) { if ( self::overlaps( $target, $other ) ) { self::fail( 'physical_overlap', 'Two generated bindings resolve to the same or overlapping physical target.' ); } }
            $footprints[] = $target;
            if ( 'post' === $target['kind'] ) {
                if ( 'post_name' === $target['column'] && ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $value ) ) { self::fail( 'slug', 'The mapped slug is not a canonical native slug.' ); }
                $changes['post'][ $target['column'] ] = $value;
            } elseif ( 'meta' === $target['kind'] ) { $changes['meta'][ $target['meta_id'] ] = $value; $uses_acf = $uses_acf || ! empty( $target['acf'] ); }
            else { $elementor[ $target['pointer'] ] = $value; }
        }
        if ( $elementor ) { $row = self::one_meta( $snapshot, '_elementor_data' ); $changes['meta'][ $row['meta_id'] ] = self::replace_json_scalars( $row['meta_value'], $elementor ); }
        if ( 'clone' === $operation && empty( $changes['post']['post_name'] ) ) { self::fail( 'clone_slug', 'A new clone requires an explicit generated slug bound to its native slug target.' ); }
        $is_elementor = count( self::meta_rows( $snapshot, '_elementor_data' ) ) > 0;
        if ( $is_elementor ) { self::one_meta( $snapshot, '_elementor_data' ); self::one_meta( $snapshot, '_elementor_page_settings' ); }
        $clone_ids = [];
        if ( $is_elementor && 'clone' === $operation ) { $clone_ids = self::prepare_elementor_clone( $snapshot, $changes, $guards, $context['operation_id'] ); }
        $plan = [ 'format' => self::WRITER_ID, 'context' => $context, 'operation' => $operation, 'desired_status' => $desired, 'site_id' => $context['site_id'], 'content_id' => $content['content_item_id'], 'version' => $content['version_number'], 'template_id' => $template['id'], 'template_revision' => $template['revision'], 'profile_digest' => $profile_digest, 'source_post_id' => (int) $snapshot['post']['ID'], 'snapshot' => $snapshot, 'snapshot_digest' => self::digest( $snapshot ), 'changes' => $changes, 'protected' => $guards, 'uses_acf' => $uses_acf, 'uses_elementor' => $is_elementor, 'taxonomy' => $taxonomy ];
        $plan['delivery_identity'] = $delivery_identity; $plan['elementor_id_map'] = $clone_ids;
        $plan['plan_digest'] = self::digest( $plan );
        return $plan;
    }

    private static function verify_policy( array $template, array $local ): void {
        if ( ! class_exists( 'Nova_Bridge_Suite_Writing_Adapter' ) ) { self::fail( 'policy', 'The local policy adapter is unavailable.' ); }
        $input = Nova_Bridge_Suite_Writing_Adapter::template_input( $local );
        if ( is_wp_error( $input ) ) { self::fail( 'configuration', $input->get_error_message() ); }
        foreach ( [ 'definition', 'mapping', 'page_type', 'name' ] as $key ) { if ( ! self::same( $template[ $key ] ?? null, $input[ $key ] ) ) { self::fail( 'binding_identity', 'The frozen template differs from the exact retained local mapping.' ); } }
    }

    private static function validate_value( $value, array $field ): void {
        if ( ! in_array( $field['kind'] ?? '', [ 'text', 'rich_text' ], true ) ) { self::fail( 'value_adapter', 'Image and list destinations need a verified typed native adapter.' ); }
        if ( ! is_string( $value ) || preg_match( '//u', $value ) !== 1 || false !== strpos( $value, "\0" ) ) { self::fail( 'value_type', 'A generated scalar has the wrong value type or encoding.' ); }
        if ( strlen( $value ) > self::MAX_SNAPSHOT_BYTES ) { self::fail( 'value_length', 'The generated scalar exceeds the bounded writer size.' ); }
        if ( 'rich_text' === $field['kind'] ) {
            if ( ! function_exists( 'wp_kses_post' ) || wp_kses_post( $value ) !== $value ) { self::fail( 'html_policy', 'Generated HTML cannot be applied without changing its content under WordPress HTML rules.' ); }
        } elseif ( preg_match( '/<[^>]*>/', $value ) ) { self::fail( 'html_policy', 'A text-only source contains HTML.' ); }
    }

    private static function url_slug( string $url ): string {
        $parts = parse_url( $url ); $home = parse_url( home_url( '/' ) );
        if ( ! is_array( $parts ) || ! is_array( $home ) || ! in_array( $parts['scheme'] ?? '', [ 'http', 'https' ], true ) || strtolower( $parts['host'] ?? '' ) !== strtolower( $home['host'] ?? '' ) || ( $parts['port'] ?? null ) !== ( $home['port'] ?? null ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) { self::fail( 'slug', 'The mapped delivery URL must be an unambiguous URL on this WordPress site.' ); }
        $slug = basename( rtrim( $parts['path'] ?? '', '/' ) );
        if ( ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug ) ) { self::fail( 'slug', 'The delivery URL does not identify a supported canonical native slug.' ); }
        return $slug;
    }

    private static function resolve_target( array $descriptor, array $snapshot, array $inventory, bool $protected = false ): array {
        $path = $descriptor['path'] ?? '';
        $live = $inventory[ $path ] ?? null;
        if ( ! $live && ! empty( $descriptor['binding'] ) ) {
            $matches = array_values( array_filter( $inventory, static function ( $field ) use ( $descriptor ) { return ( $field['binding'] ?? null ) === $descriptor['binding']; } ) );
            if ( count( $matches ) === 1 ) { $live = $matches[0]; }
        }
        if ( ! is_array( $live ) || ( ! $protected && empty( $live['writable'] ) ) ) { self::fail( 'target_drift', 'A mapped target no longer has a unique supported native binding.' ); }
        foreach ( [ 'transport', 'builder', 'write_mode', 'acf_key', 'binding', 'source', 'selector_data' ] as $key ) { if ( isset( $descriptor[ $key ] ) && ( $live[ $key ] ?? null ) !== $descriptor[ $key ] ) { self::fail( 'target_drift', 'A native target capability changed since approval.' ); } }
        $parts = self::pointer_parts( $live['path'] );
        $native = [ 'title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt', 'slug' => 'post_name' ];
        if ( count( $parts ) === 1 && isset( $native[ $parts[0] ] ) ) { return [ 'kind' => 'post', 'column' => $native[ $parts[0] ] ]; }
        if ( ( $live['builder'] ?? '' ) === 'elementor' ) {
            $row = self::one_meta( $snapshot, '_elementor_data' ); $data = json_decode( $row['meta_value'], true );
            if ( ! is_array( $data ) || json_last_error() !== JSON_ERROR_NONE ) { self::fail( 'elementor_document', 'The native Elementor document is not a complete JSON array.' ); }
            $selector = $live['selector_data'] ?? [];
            $element_id = $selector['element_id'] ?? '';
            $setting_path = $selector['path'] ?? null;
            if ( isset( $selector['field_key'] ) && is_string( $selector['field_key'] ) && substr_count( $selector['field_key'], '|' ) === 1 ) {
                list( $element_id, $field_path ) = explode( '|', $selector['field_key'], 2 );
                $setting_path = explode( '.', $field_path );
            }
            if ( ! is_string( $element_id ) || ! is_array( $setting_path ) || ! $setting_path ) { self::fail( 'elementor_selector', 'An exact native widget and setting path are required.' ); }
            $nodes = []; self::elementor_nodes( $data, [], $nodes );
            if ( ! isset( $nodes[ $element_id ] ) || count( $nodes[ $element_id ] ) !== 1 ) { self::fail( 'elementor_identity', 'The widget identity is missing or duplicated.' ); }
            $node = $nodes[ $element_id ][0]; $node_pointer = self::pointer( $node['path'] );
            if ( $protected ) { return [ 'kind' => 'elementor', 'pointer' => $node_pointer, 'protected_node' => true ]; }
            $settings = [ 'heading' => 'title', 'text-editor' => 'editor', 'button' => 'text' ];
            if ( count( $setting_path ) !== 1 || ( $settings[ $node['node']['widgetType'] ?? '' ] ?? null ) !== $setting_path[0] || isset( $node['node']['settings']['__dynamic__'][ $setting_path[0] ] ) ) { self::fail( 'elementor_setting', 'Only verified existing static text settings of native heading, text-editor and button widgets are supported.' ); }
            if ( ! isset( $node['node']['settings'][ $setting_path[0] ] ) || ! is_string( $node['node']['settings'][ $setting_path[0] ] ) ) { self::fail( 'elementor_setting', 'The setting is not an existing scalar text value.' ); }
            return [ 'kind' => 'elementor', 'pointer' => self::pointer( array_merge( $node['path'], [ 'settings' ], $setting_path ) ) ];
        }
        if ( ( $parts[0] ?? '' ) !== 'meta_all' || ! in_array( count( $parts ), [ 2, 3 ], true ) || ( count( $parts ) === 3 && $parts[1] !== 'acf' ) ) { self::fail( 'writer_scope', 'This target requires an unsupported builder, structured parent or native endpoint.' ); }
        $name = end( $parts );
        if ( '' === $name || self::operational_meta( $name ) || ( ! $protected && 0 === strpos( $name, '_' ) ) ) { self::fail( 'writer_scope', 'Hidden metadata and provider document rows require their dedicated writer.' ); }
        if ( 'complete_parent' === ( $live['write_mode'] ?? '' ) && ! $protected ) { self::fail( 'complete_parent', 'Complete-parent ACF replacement is outside the contracted scalar writer.' ); }
        $acf = ! empty( $live['acf_key'] );
        if ( $protected && in_array( $live['type'] ?? '', [ 'group', 'repeater', 'flexible_content' ], true ) ) {
            self::one_meta( $snapshot, $name );
            if ( ! $acf || self::one_meta( $snapshot, '_' . $name )['meta_value'] !== $live['acf_key'] ) { self::fail( 'acf_reference', 'The protected ACF parent has no exact native reference.' ); }
            return [ 'kind' => 'meta_region', 'key' => $name ];
        }
        $row = self::one_meta( $snapshot, $name );
        if ( $acf ) {
            $reference = self::one_meta( $snapshot, '_' . $name );
            if ( $reference['meta_value'] !== $live['acf_key'] || ( ! $protected && ! in_array( $live['type'] ?? '', [ 'text', 'textarea', 'wysiwyg', 'url', 'email' ], true ) ) ) { self::fail( 'acf_reference', 'The exact existing scalar ACF row or hidden reference is unsupported.' ); }
        } elseif ( ! $protected && ( is_serialized( $row['meta_value'] ) || ! in_array( $live['type'] ?? '', [ 'string', 'text', 'textarea', 'wysiwyg', 'url', 'email' ], true ) ) ) { self::fail( 'structured_meta', 'Structured or non-text metadata requires a separate verified writer.' ); }
        return [ 'kind' => 'meta', 'meta_id' => (int) $row['meta_id'], 'key' => $name, 'acf' => $acf, 'value_type' => $live['type'] ?? 'string' ];
    }
    private static function elementor_nodes( array $nodes, array $prefix, array &$result ): void {
        if ( count( $prefix ) > 64 || ( $nodes && array_keys( $nodes ) !== range( 0, count( $nodes ) - 1 ) ) ) { self::fail( 'elementor_document', 'The native document must retain bounded ordered child arrays.' ); }
        foreach ( $nodes as $index => $node ) {
            if ( ! is_array( $node ) || ! isset( $node['id'] ) || ! is_string( $node['id'] ) || '' === $node['id'] ) { self::fail( 'elementor_document', 'The Elementor document contains a node without stable native identity.' ); }
            if ( isset( $result[ $node['id'] ] ) ) { self::fail( 'elementor_identity', 'The complete Elementor document contains duplicate native identities.' ); }
            $path = array_merge( $prefix, [ (string) $index ] ); $result[ $node['id'] ][] = [ 'path' => $path, 'node' => $node ];
            if ( isset( $node['elements'] ) ) { if ( ! is_array( $node['elements'] ) ) { self::fail( 'elementor_document', 'An Elementor child region is incomplete.' ); } self::elementor_nodes( $node['elements'], array_merge( $path, [ 'elements' ] ), $result ); }
        }
    }
    private static function clone_references( string $value, array $ids, string $pattern ): string {
        if ( '' === $pattern ) { return $value; }
        if ( preg_match( '/(?:' . $pattern . ')/', $value ) && preg_match( '/(?:[a-z][a-z0-9+.-]*:\/\/|^\s*\/|(?:href|src)\s*=\s*["\'](?!#)|url\(\s*["\']?(?!#))/i', $value ) ) { self::fail( 'clone_reference', 'A node reference may address another document; the clone cannot safely retarget it.' ); }
        // These are native Elementor DOM/CSS identities, not arbitrary text or custom CSS IDs.
        $value = preg_replace_callback( '/elementor-element-(' . $pattern . ')(?![A-Za-z0-9_-])/', static function ( $match ) use ( $ids ) { return 'elementor-element-' . $ids[ $match[1] ]; }, $value );
        if ( ! is_string( $value ) ) { self::fail( 'clone_reference', 'The document exceeds the supported clone-reference matcher.' ); }
        $value = preg_replace_callback( '/(data-id\s*=\s*)(["\'])(' . $pattern . ')\2/', static function ( $match ) use ( $ids ) { return $match[1] . $match[2] . $ids[ $match[3] ] . $match[2]; }, $value );
        if ( ! is_string( $value ) || 0 !== preg_match( '/(?:' . $pattern . ')/', $value ) ) { self::fail( 'clone_reference', 'A source node identity occurs outside a reviewed Elementor DOM/CSS reference. Review this clone before publishing.' ); }
        return $value;
    }
    private static function clone_reference_values( $value, array $ids, string $pattern, array $path, array &$replacements, array $node_id_paths = [], int $depth = 0 ) {
        if ( $depth > 64 || is_object( $value ) ) { self::fail( 'clone_reference', 'The clone contains unsupported nested native references.' ); }
        if ( is_array( $value ) ) {
            foreach ( $value as $key => $item ) {
                if ( '' !== $pattern && is_string( $key ) && 0 !== preg_match( '/(?:' . $pattern . ')/', $key ) ) { self::fail( 'clone_reference', 'A native setting key contains an unsupported source node reference.' ); }
                $value[ $key ] = self::clone_reference_values( $item, $ids, $pattern, array_merge( $path, [ (string) $key ] ), $replacements, $node_id_paths, $depth + 1 );
            }
        }
        elseif ( is_string( $value ) ) {
            $pointer = self::pointer( $path );
            if ( isset( $node_id_paths[ $pointer ] ) ) { return $value; }
            $changed = self::clone_references( $value, $ids, $pattern );
            if ( $changed !== $value ) { $replacements[ $pointer ] = $changed; $value = $changed; }
        }
        return $value;
    }
    /** Clone-only identity transform; the persisted operation determines every replacement ID. */
    private static function prepare_elementor_clone( array $snapshot, array &$changes, array $guards, string $operation_id ): array {
        $row = self::one_meta( $snapshot, '_elementor_data' );
        $json = $changes['meta'][ $row['meta_id'] ] ?? $row['meta_value'];
        $document = json_decode( $json, true );
        if ( ! is_array( $document ) || json_last_error() !== JSON_ERROR_NONE ) { self::fail( 'elementor_document', 'The complete clone document is invalid.' ); }
        $nodes = []; self::elementor_nodes( $document, [], $nodes );
        $ids = []; $used = array_fill_keys( array_keys( $nodes ), true ); $id_paths = []; $replacements = [];
        foreach ( $nodes as $old => $matches ) {
            if ( ! preg_match( '/^[a-f0-9]{7}$/D', (string) $old ) ) { self::fail( 'clone_identity', 'Clone identity regeneration supports standard seven-hex-digit Elementor node IDs only.' ); }
            $attempt = 0;
            do { $new = substr( hash( 'sha256', 'nova-elementor-clone-v1' . "\0" . $operation_id . "\0" . $old . "\0" . $attempt++ ), 0, 7 ); } while ( isset( $used[ $new ] ) );
            $used[ $new ] = true; $ids[ $old ] = $new;
            $pointer = self::pointer( array_merge( $matches[0]['path'], [ 'id' ] ) ); $id_paths[ $pointer ] = true; $replacements[ $pointer ] = $new;
        }
        $pattern = implode( '|', array_keys( $ids ) );
        $references = []; self::clone_reference_values( $document, $ids, $pattern, [], $references, $id_paths );
        foreach ( $references as $pointer => $value ) {
            foreach ( $guards as $guard ) { if ( 'elementor' === $guard['kind'] && ( $pointer === $guard['pointer'] || 0 === strpos( $pointer, $guard['pointer'] . '/' ) ) ) { self::fail( 'clone_protected_reference', 'A protected value contains a node reference that would need changing in a clone.' ); } }
            $replacements[ $pointer ] = $value;
        }
        $changes['meta'][ $row['meta_id'] ] = self::replace_json_scalars( $json, $replacements );
        $settings = self::one_meta( $snapshot, '_elementor_page_settings' );
        $raw = $changes['meta'][ $settings['meta_id'] ] ?? $settings['meta_value'];
        $settings_value = @unserialize( $raw, [ 'allowed_classes' => false ] );
        if ( ! is_array( $settings_value ) ) { self::fail( 'clone_reference', 'Elementor page settings must be a complete native serialized array.' ); }
        $settings_edits = []; $new_settings = self::clone_reference_values( $settings_value, $ids, $pattern, [], $settings_edits );
        if ( $settings_edits ) {
            foreach ( $guards as $guard ) { if ( ( $guard['key'] ?? null ) === '_elementor_page_settings' ) { self::fail( 'clone_protected_reference', 'Protected page settings contain a clone reference.' ); } }
            $changes['meta'][ $settings['meta_id'] ] = serialize( $new_settings );
        }
        foreach ( [ 'post_title', 'post_content', 'post_excerpt' ] as $column ) {
            $value = $changes['post'][ $column ] ?? $snapshot['post'][ $column ]; $changed = self::clone_references( $value, $ids, $pattern );
            if ( $value !== $changed ) {
                foreach ( $guards as $guard ) { if ( 'post' === $guard['kind'] && $guard['column'] === $column ) { self::fail( 'clone_protected_reference', 'A protected native value contains a clone reference.' ); } }
                $changes['post'][ $column ] = $changed;
            }
        }
        foreach ( $snapshot['meta'] as $other ) {
            if ( self::operational_meta( $other['meta_key'] ) || in_array( $other['meta_key'], [ '_elementor_data', '_elementor_page_settings' ], true ) ) { continue; }
            $value = $changes['meta'][ $other['meta_id'] ] ?? $other['meta_value'];
            if ( '' !== $pattern && ( 0 !== preg_match( '/(?:' . $pattern . ')/', $value ) || 0 !== preg_match( '/(?:' . $pattern . ')/', $other['meta_key'] ) ) ) { self::fail( 'clone_reference', 'Additional native metadata contains a source node reference without a reviewed clone adapter.' ); }
        }
        return $ids;
    }
    private static function overlaps( array $first, array $second ): bool {
        if ( 'post' === $first['kind'] || 'post' === $second['kind'] ) { return 'post' === $first['kind'] && 'post' === $second['kind'] && $first['column'] === $second['column']; }
        if ( 'elementor' === $first['kind'] || 'elementor' === $second['kind'] ) {
            if ( $first['kind'] !== $second['kind'] ) { return '_elementor_data' === ( $first['key'] ?? $second['key'] ?? '' ); }
            return $first['pointer'] === $second['pointer'] || 0 === strpos( $first['pointer'], $second['pointer'] . '/' ) || 0 === strpos( $second['pointer'], $first['pointer'] . '/' );
        }
        if ( $first['key'] === $second['key'] ) { return true; }
        foreach ( [ [ $first, $second ], [ $second, $first ] ] as $pair ) { if ( 'meta_region' === $pair[0]['kind'] && ( 0 === strpos( $pair[1]['key'], $pair[0]['key'] . '_' ) || 0 === strpos( $pair[1]['key'], '_' . $pair[0]['key'] . '_' ) ) ) { return true; } }
        return false;
    }

    /** Replace only scalar JSON tokens, preserving every untouched native byte and all IDs/order. */
    public static function replace_json_scalars( string $json, array $replacements ): string {
        json_decode( $json, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) { self::fail( 'elementor_json', 'The native document JSON is invalid.' ); }
        $position = 0; $spans = []; self::scan_json( $json, $position, [], $spans, 0 );
        $edits = [];
        foreach ( $replacements as $pointer => $value ) {
            if ( ! isset( $spans[ $pointer ] ) || ! $spans[ $pointer ]['scalar'] || ! is_string( $value ) ) { self::fail( 'elementor_json', 'A requested setting is not an existing native JSON scalar.' ); }
            $edits[] = [ 'start' => $spans[ $pointer ]['start'], 'length' => $spans[ $pointer ]['length'], 'value' => self::json( $value ) ];
        }
        usort( $edits, static function ( $a, $b ) { return $b['start'] <=> $a['start']; } );
        foreach ( $edits as $edit ) { $json = substr_replace( $json, $edit['value'], $edit['start'], $edit['length'] ); }
        return $json;
    }
    private static function whitespace( string $json, int &$position ): void { $length = strlen( $json ); while ( $position < $length && false !== strpos( " \t\r\n", $json[ $position ] ) ) { ++$position; } }
    private static function json_string( string $json, int &$position ): string {
        $start = $position++; $length = strlen( $json );
        while ( $position < $length ) { if ( '\\' === $json[ $position ] ) { $position += 2; } elseif ( '"' === $json[ $position++ ] ) { $value = json_decode( substr( $json, $start, $position - $start ), true ); if ( ! is_string( $value ) ) { self::fail( 'elementor_json', 'Invalid JSON string.' ); } return $value; } }
        self::fail( 'elementor_json', 'Unterminated JSON string.' );
    }
    private static function scan_json( string $json, int &$position, array $path, array &$spans, int $depth ): void {
        if ( $depth > 64 ) { self::fail( 'elementor_json', 'The native document exceeds the JSON depth limit.' ); }
        self::whitespace( $json, $position ); $start = $position; $token = $json[ $position ] ?? ''; $scalar = true;
        if ( '{' === $token || '[' === $token ) {
            $scalar = false; ++$position; $index = 0; $keys = []; $end = '{' === $token ? '}' : ']'; self::whitespace( $json, $position );
            while ( ( $json[ $position ] ?? '' ) !== $end ) {
                if ( '{' === $token ) {
                    if ( '"' !== ( $json[ $position ] ?? '' ) ) { self::fail( 'elementor_json', 'A JSON object key is invalid.' ); }
                    $key = self::json_string( $json, $position );
                    if ( isset( $keys[ $key ] ) ) { self::fail( 'elementor_json', 'Duplicate JSON object keys make native identity ambiguous.' ); } $keys[ $key ] = true;
                    self::whitespace( $json, $position ); if ( ':' !== ( $json[ $position++ ] ?? '' ) ) { self::fail( 'elementor_json', 'A JSON separator is invalid.' ); }
                } else { $key = (string) $index++; }
                self::scan_json( $json, $position, array_merge( $path, [ $key ] ), $spans, $depth + 1 ); self::whitespace( $json, $position );
                if ( ( $json[ $position ] ?? '' ) === $end ) { break; }
                if ( ',' !== ( $json[ $position++ ] ?? '' ) ) { self::fail( 'elementor_json', 'A JSON separator is invalid.' ); } self::whitespace( $json, $position );
            }
            ++$position;
        } elseif ( '"' === $token ) { self::json_string( $json, $position ); }
        elseif ( preg_match( '/\G(?:true|false|null|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?)/A', $json, $match, 0, $position ) ) { $position += strlen( $match[0] ); }
        else { self::fail( 'elementor_json', 'A native JSON token is invalid.' ); }
        $spans[ self::pointer( $path ) ] = [ 'start' => $start, 'length' => $position - $start, 'scalar' => $scalar ];
    }

    private static function table( string $property ): string {
        global $wpdb; $name = $wpdb->$property;
        if ( ! is_string( $name ) || ! preg_match( '/^[A-Za-z0-9_]+$/D', $name ) ) { self::fail( 'database', 'The local WordPress table identifier is unsupported.' ); }
        return '`' . $name . '`';
    }
    private static function rows( string $sql ): array {
        global $wpdb; $result = $wpdb->get_results( $sql, ARRAY_A );
        if ( ! is_array( $result ) || ! empty( $wpdb->last_error ) ) { self::fail( 'database', 'A required local database read failed.' ); }
        return $result;
    }
    private static function execute( string $sql ): int {
        global $wpdb; $changed = $wpdb->query( $sql );
        if ( false === $changed ) { self::fail( 'database', 'A local transaction statement failed.' ); }
        return (int) $changed;
    }
    private static function snapshot( int $post_id, bool $lock = false ): array {
        global $wpdb; $suffix = $lock ? ' FOR UPDATE' : '';
        $posts = self::rows( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'posts' ) . ' WHERE ID = %d' . $suffix, $post_id ) );
        if ( count( $posts ) !== 1 ) { self::fail( 'target', 'The native document is missing or ambiguous.' ); }
        $meta = self::rows( $wpdb->prepare( 'SELECT meta_id, post_id, meta_key, meta_value FROM ' . self::table( 'postmeta' ) . ' WHERE post_id = %d ORDER BY meta_id' . $suffix, $post_id ) );
        $terms = self::rows( $wpdb->prepare( 'SELECT object_id, term_taxonomy_id, term_order FROM ' . self::table( 'term_relationships' ) . ' WHERE object_id = %d ORDER BY term_taxonomy_id' . $suffix, $post_id ) );
        return [ 'post' => $posts[0], 'meta' => $meta, 'terms' => $terms ];
    }
    private static function runtime_tuple(): array {
        return [ 'wordpress' => $GLOBALS['wp_version'] ?? '', 'plugin' => defined( 'NOVA_BRIDGE_SUITE_VERSION' ) ? NOVA_BRIDGE_SUITE_VERSION : '', 'acf' => function_exists( 'acf_get_setting' ) ? (string) acf_get_setting( 'version' ) : '', 'elementor' => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '' ];
    }
    private static function check_row_permissions( array $plan, int $post_id ): void {
        $rows = array_column( $plan['snapshot']['meta'], null, 'meta_id' );
        foreach ( $plan['changes']['meta'] as $id => $value ) {
            if ( ! isset( $rows[ $id ] ) || ! current_user_can( 'edit_post_meta', $post_id, $rows[ $id ]['meta_key'] ) ) { self::fail( 'permission', 'The publishing user cannot edit a mapped native metadata row.' ); }
        }
    }
    private static function taxonomy_plan( array $snapshot, string $operation, string $desired ): array {
        if ( 'clone' !== $operation && $desired === $snapshot['post']['post_status'] ) { return []; }
        if ( ! class_exists( 'Nova_Bridge_Suite_Taxonomy_Counts' ) ) {
            if ( ! empty( $snapshot['terms'] ) || 'update' === $operation ) { self::fail( 'taxonomy_lifecycle', 'This clone or publication transition requires the reviewed taxonomy-count adapter.' ); }
            return [];
        }
        $result = Nova_Bridge_Suite_Taxonomy_Counts::plan( $snapshot, $operation, $desired );
        if ( is_wp_error( $result ) ) { self::fail( 'taxonomy_lifecycle', $result->get_error_message() ); }
        return $result;
    }
    private static function taxonomy_apply( array $plan, ?array $before, array $after ): void {
        if ( empty( $plan['taxonomy'] ) ) { return; }
        if ( ! class_exists( 'Nova_Bridge_Suite_Taxonomy_Counts' ) ) { self::fail( 'taxonomy_lifecycle', 'The reviewed taxonomy-count adapter is no longer available.' ); }
        $result = Nova_Bridge_Suite_Taxonomy_Counts::apply( $plan['taxonomy'], $before, $after );
        if ( is_wp_error( $result ) ) { self::fail( 'taxonomy_lifecycle', $result->get_error_message() ); }
    }
    private static function database_capability(): array {
        global $wpdb;
        if ( function_exists( 'has_filter' ) && has_filter( 'query' ) ) {
            foreach ( $GLOBALS['wp_filter']['query']->callbacks ?? [] as $callbacks ) {
                foreach ( $callbacks as $entry ) {
                    $callback = $entry['function'] ?? null;
                    // wpdb::prepare registers this pure core unescape function on every normal installation.
                    $allowed = is_array( $callback ) && 2 === count( $callback ) && $callback[0] instanceof wpdb && 'remove_placeholder_escape' === $callback[1];
                    if ( $allowed ) { $method = new ReflectionMethod( $callback[0], $callback[1] ); $allowed = 'wpdb' === strtolower( $method->getDeclaringClass()->getName() ) && realpath( $method->getFileName() ) === realpath( ABSPATH . WPINC . '/class-wpdb.php' ); }
                    if ( ! $allowed ) { self::fail( 'database_filter', 'Unreviewed SQL query filters prevent verified direct-row execution.' ); }
                }
            }
        }
        $names = [ $wpdb->posts, $wpdb->postmeta, $wpdb->term_relationships ];
        $rows = self::rows( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name IN (%s,%s,%s)', ...$names ) );
        $engines = array_column( $rows, 'Engine', 'Name' );
        foreach ( $names as $name ) { if ( strtolower( $engines[ $name ] ?? '' ) !== 'innodb' ) { self::fail( 'database_engine', 'Every affected table must use InnoDB transactions.' ); } }
        $triggers = self::rows( $wpdb->prepare( 'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE IN (%s,%s,%s)', ...$names ) );
        if ( $triggers ) { self::fail( 'database_trigger', 'Unknown database triggers prevent verified direct-row execution.' ); }
        return $engines;
    }
    private static function check_provider_capability( array $plan ): void {
        if ( ! self::same( $plan['context']['provider_tuple'] ?? null, self::runtime_tuple() ) ) { self::fail( 'provider_drift', 'The installed writer/provider tuple changed after planning.' ); }
        if ( isset( $plan['operation'], $plan['desired_status'] ) && ! self::same( $plan['taxonomy'] ?? [], self::taxonomy_plan( $plan['snapshot'], $plan['operation'], $plan['desired_status'] ) ) ) { self::fail( 'taxonomy_lifecycle', 'The approved taxonomy count policy changed after planning.' ); }
        if ( $plan['uses_acf'] && ( ! function_exists( 'acf_get_field' ) || ! function_exists( 'acf_get_setting' ) ) ) { self::fail( 'acf_unavailable', 'The inspected ACF-compatible provider is no longer available.' ); }
        if ( $plan['uses_elementor'] ) {
            if ( ! self::$elementor_derived && class_exists( 'Nova_Bridge_Suite_Elementor_Derived' ) ) { $registered = Nova_Bridge_Suite_Elementor_Derived::register(); if ( is_wp_error( $registered ) ) { self::fail( 'elementor_capability', $registered->get_error_message() ); } }
            $adapter = self::$elementor_derived;
            if ( $adapter && $adapter['preflight'] ) { $available = call_user_func( $adapter['preflight'], $plan ); if ( is_wp_error( $available ) || true !== $available ) { self::fail( 'elementor_capability', is_wp_error( $available ) ? $available->get_error_message() : 'Elementor derived work is unavailable before mutation.' ); } }
            if ( ! $adapter || ! defined( 'ELEMENTOR_VERSION' ) || $adapter['version'] !== ELEMENTOR_VERSION || '' === $adapter['evidence_id'] || ! class_exists( '\Elementor\Plugin' ) ) { self::fail( 'elementor_capability', 'An exact-version reviewed Elementor cache/CSS adapter is required before any mutation.' ); }
            $plugin = \Elementor\Plugin::instance();
            if ( ! isset( $plugin->widgets_manager ) || ! method_exists( $plugin->widgets_manager, 'get_widget_types' ) ) { self::fail( 'elementor_capability', 'Elementor widget dependencies cannot be verified.' ); }
            $raw = self::one_meta( $plan['snapshot'], '_elementor_data' )['meta_value']; self::replace_json_scalars( $raw, [] );
            $nodes = []; $data = json_decode( $raw, true );
            if ( ! is_array( $data ) ) { self::fail( 'elementor_document', 'The complete Elementor document is not an ordered node array.' ); }
            self::elementor_nodes( $data, [], $nodes );
            foreach ( $nodes as $matches ) { foreach ( $matches as $entry ) { $node = $entry['node']; if ( 'widget' === ( $node['elType'] ?? '' ) && ! $plugin->widgets_manager->get_widget_types( $node['widgetType'] ?? '' ) ) { self::fail( 'elementor_dependency', 'A source widget dependency is missing.' ); } } }
        }
    }
    public static function probe( int $post_id = 0 ) {
        return self::guard( static function () use ( $post_id ) {
            $engines = self::database_capability();
            return [ 'writer_id' => self::WRITER_ID, 'provider_tuple' => self::runtime_tuple(), 'engines' => $engines, 'native_scalar' => true, 'acf_existing_scalar' => function_exists( 'acf_get_field' ), 'complete_parent' => false, 'elementor_derived_registered' => self::$elementor_derived && defined( 'ELEMENTOR_VERSION' ) && self::$elementor_derived['version'] === ELEMENTOR_VERSION, 'elementor_evidence_id' => self::$elementor_derived['evidence_id'] ?? null, 'post_id' => $post_id, 'certification' => 'runtime_probe_only' ];
        } );
    }

    /** Read-only evidence for this exact mapping on the installed native document. */
    public static function probe_mapping( array $template, array $mapping, array $local, array $context = [] ) {
        return self::guard( static function () use ( $template, $mapping, $local, $context ) {
            return self::actor( $context, static function () use ( $template, $mapping, $local ) {
                if ( 'post' !== ( $local['reference_type'] ?? '' ) ) { self::fail( 'writer_scope', 'The direct writer currently supports concrete posts only.' ); }
                $post_id = (int) $local['reference_id'];
                if ( ! current_user_can( 'edit_post', $post_id ) ) { self::fail( 'permission', 'The configured publishing user cannot edit the selected native document.' ); }
                $entity = Nova_Bridge_Suite_Strategy::entity( 'post', $post_id );
                if ( ! $entity || Nova_Bridge_Suite_Strategy::fingerprint( $entity )['signature'] !== $local['signature'] ) { self::fail( 'layout_drift', 'Reconcile the approved local layout before activation.' ); }
                self::verify_policy( $template, $local );
                $inventory = array_column( Nova_Bridge_Suite_Strategy::field_inventory( $entity ), null, 'path' );
                $snapshot = self::snapshot( $post_id );
                $operation = $local['routing']['operation'] ?? '';
                $publication = $local['routing']['publication'] ?? 'preserve';
                $type = get_post_type_object( $snapshot['post']['post_type'] );
                if ( ! in_array( $operation, [ 'update', 'clone' ], true ) || ! in_array( $publication, [ 'preserve', 'draft', 'publish' ], true ) || ! $type || ( 'clone' === $operation && ! current_user_can( $type->cap->create_posts ) ) || ( 'publish' === $publication && ! current_user_can( $type->cap->publish_posts ) ) ) { self::fail( 'permission', 'The approved routing exceeds the publishing user capabilities.' ); }
                $desired = 'preserve' === $publication ? ( 'clone' === $operation ? 'draft' : $snapshot['post']['post_status'] ) : $publication;
                self::taxonomy_plan( $snapshot, $operation, $desired );
                $definitions = array_column( $template['definition']['fields'] ?? [], null, 'id' );
                $bindings = array_column( $template['mapping']['fields'] ?? [], 'source_field', 'field_id' );
                $guards = []; $targets = []; $coverage = []; $uses_acf = false; $slug = false;
                foreach ( $local['fields'] as $path => $field ) {
                    $descriptor = $local['target_descriptors'][ $path ] ?? null;
                    if ( ! is_array( $descriptor ) ) { self::fail( 'local_descriptor', 'A retained native descriptor is missing.' ); }
                    if ( 'protected' === $field['mode'] ) { $guards[] = self::resolve_target( $descriptor, $snapshot, $inventory, true ); continue; }
                    if ( 'leave_empty' === $field['mode'] ) { if ( 'clone' === $operation ) { $targets[] = self::resolve_target( $descriptor, $snapshot, $inventory ); } continue; }
                    $id = Nova_Bridge_Suite_Writing_Adapter::field_id( $path );
                    if ( 'mapped' !== $field['mode'] || ! isset( $definitions[ $id ], $bindings[ $id ] ) || $bindings[ $id ] !== ( $field['source_path'] ?? '' ) || ! in_array( $definitions[ $id ]['kind'], [ 'text', 'rich_text' ], true ) ) { self::fail( 'value_adapter', 'Every mapped destination needs a supported scalar source before activation.' ); }
                    $coverage[ $id ] = true;
                    $target = self::resolve_target( $descriptor, $snapshot, $inventory ); $targets[] = $target;
                    if ( 'post' === $target['kind'] && 'post_name' === $target['column'] ) { if ( 'url' !== $bindings[ $id ] ) { self::fail( 'slug', 'Native slugs must be mapped from the delivery URL.' ); } $slug = true; }
                }
                foreach ( $targets as $index => $target ) {
                    $uses_acf = $uses_acf || ! empty( $target['acf'] );
                    foreach ( $guards as $protected ) { if ( self::overlaps( $target, $protected ) ) { self::fail( 'protected_overlap', 'A destination overlaps protected native content.' ); } }
                    foreach ( array_slice( $targets, 0, $index ) as $other ) { if ( self::overlaps( $target, $other ) ) { self::fail( 'physical_overlap', 'Two sources resolve to overlapping native storage.' ); } }
                }
                if ( 'clone' === $operation && ! $slug ) { self::fail( 'clone_slug', 'Clone activation requires a generated field mapped to native slug.' ); }
                $elementor = (bool) self::meta_rows( $snapshot, '_elementor_data' );
                if ( $elementor ) {
                    self::one_meta( $snapshot, '_elementor_page_settings' );
                    if ( 'clone' === $operation ) { $probe_changes = [ 'post' => [], 'meta' => [] ]; self::prepare_elementor_clone( $snapshot, $probe_changes, $guards, '00000000-0000-4000-8000-000000000001' ); }
                }
                $engines = self::database_capability(); $tuple = self::runtime_tuple();
                self::check_provider_capability( [ 'context' => [ 'provider_tuple' => $tuple ], 'uses_acf' => $uses_acf, 'uses_elementor' => $elementor, 'snapshot' => $snapshot ] );
                return [ 'writer_id' => self::WRITER_ID, 'provider' => $elementor ? 'elementor:' . $tuple['elementor'] : ( $uses_acf ? 'acf:' . $tuple['acf'] : 'wordpress:' . $tuple['wordpress'] ), 'plugin_version' => $tuple['plugin'], 'db_engine' => 'InnoDB', 'coverage' => array_keys( $coverage ), 'provider_tuple' => $tuple, 'expires_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() + HOUR_IN_SECONDS ), 'evidence' => [ 'reference_id' => $post_id, 'snapshot_digest' => self::digest( $snapshot ), 'engines' => $engines, 'target_count' => count( $targets ), 'protected_count' => count( $guards ), 'repeat_policy' => 'existing_fixed_slots_only', 'save_hooks' => 'bypassed', 'query_filters' => 'reviewed_core_placeholder_escape_only', 'database_triggers' => 'absent', 'elementor_adapter_evidence' => self::$elementor_derived['evidence_id'] ?? null ] ];
            } );
        } );
    }
    private static function assert_plan( array $plan, string $operation_id ): void {
        $digest = $plan['plan_digest'] ?? ''; $unsigned = $plan; unset( $unsigned['plan_digest'] );
        if ( ( $plan['format'] ?? '' ) !== self::WRITER_ID || ! is_string( $digest ) || ! hash_equals( $digest, self::digest( $unsigned ) ) || ( $plan['context']['operation_id'] ?? null ) !== $operation_id ) { self::fail( 'plan_integrity', 'The persisted plan or its operation identity changed.' ); }
    }
    private static function operation_key( string $operation_id ): string { return '_nova_writer_op_' . hash( 'sha256', $operation_id ); }
    private static function clone_witness_key( string $operation_id ): string { return '_nova_writer_clone_' . hash( 'sha256', $operation_id ); }
    private static function content_key( string $site_id, string $content_id ): string { return '_nova_writer_content_' . hash( 'sha256', $site_id . "\0" . $content_id ); }
    private static function target_fence( int $post_id ): ?array {
        global $wpdb;
        $rows = self::rows( $wpdb->prepare( 'SELECT meta_id, post_id, meta_value FROM ' . self::table( 'postmeta' ) . ' WHERE post_id = %d AND meta_key = %s ORDER BY meta_id FOR UPDATE', $post_id, '_nova_writer_target_fence_v1' ) );
        if ( count( $rows ) > 1 ) { self::fail( 'target_fence', 'The native target has ambiguous version fences.' ); }
        if ( ! $rows ) { return null; }
        $record = json_decode( $rows[0]['meta_value'], true );
        if ( ! is_array( $record ) || ( $record['fence_post_id'] ?? null ) !== $post_id || ! is_array( $record['delivery_identity'] ?? null ) || empty( $record['operation_id'] ) ) { self::fail( 'target_fence', 'The persisted native version fence is invalid.' ); }
        return [ 'post_id' => $post_id, 'meta_id' => (int) $rows[0]['meta_id'], 'raw' => $rows[0]['meta_value'], 'record' => $record ];
    }
    /** The delivery inventory cursor is not an ordering key for native mutations. */
    public static function assert_target_fence( array $identity, string $operation_id, ?array $record ): void {
        if ( ! $record ) { return; }
        $previous = $record['delivery_identity'] ?? [];
        foreach ( [ 'site_id', 'url_id', 'content_id' ] as $member ) { if ( ( $previous[ $member ] ?? null ) !== ( $identity[ $member ] ?? null ) ) { self::fail( 'target_conflict', 'A different site, NOVA URL or content item already owns this native target.' ); } }
        if ( ! is_int( $previous['version'] ?? null ) ) { self::fail( 'target_fence', 'The retained native version fence is incomplete.' ); }
        if ( $identity['version'] <= $previous['version'] ) {
            if ( self::same( $identity, $previous ) && ( $record['operation_id'] ?? null ) === $operation_id ) { self::fail( 'ambiguous_marker', 'The exact delivery has a native fence but its operation marker is missing; reconcile without rewriting.' ); }
            self::fail( 'older_version', 'An equal or older version, or a new attempt after a commit, cannot replace committed native work.' );
        }
        if ( 'complete' !== ( $record['state'] ?? null ) ) { self::fail( 'recovery_pending', 'The earlier native operation still needs recovery before newer work can mutate this target.' ); }
    }
    private static function save_target_fence( int $post_id, array $record, ?array $existing ): void {
        $record['fence_post_id'] = $post_id;
        self::save_marker( $post_id, '_nova_writer_target_fence_v1', $record, $existing );
    }
    private static function marker( string $key, bool $lock = false ): ?array {
        global $wpdb;
        $rows = self::rows( $wpdb->prepare( 'SELECT meta_id, post_id, meta_value FROM ' . self::table( 'postmeta' ) . ' WHERE meta_key = %s ORDER BY meta_id' . ( $lock ? ' FOR UPDATE' : '' ), $key ) );
        if ( count( $rows ) > 1 ) { self::fail( 'ambiguous_marker', 'More than one native target has this durable operation identity.' ); }
        if ( ! $rows ) { return null; }
        $record = json_decode( $rows[0]['meta_value'], true );
        if ( ! is_array( $record ) || empty( $record['operation_id'] ) || (int) ( $record['post_id'] ?? 0 ) !== (int) $rows[0]['post_id'] ) { self::fail( 'ambiguous_marker', 'The durable writer marker cannot be reconciled.' ); }
        return [ 'post_id' => (int) $rows[0]['post_id'], 'meta_id' => (int) $rows[0]['meta_id'], 'raw' => $rows[0]['meta_value'], 'record' => $record ];
    }
    private static function begin(): void { self::execute( 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE' ); self::execute( 'START TRANSACTION' ); }
    private static function rollback(): void { global $wpdb; $wpdb->query( 'ROLLBACK' ); }
    private static function commit(): void {
        global $wpdb;
        if ( false === $wpdb->query( 'COMMIT' ) ) { self::fail( 'ambiguous_commit', 'The CMS commit acknowledgment was lost. Inspect the durable operation marker before any retry.' ); }
    }
    private static function cache( int $post_id, array $taxonomy = [] ): void {
        // Avoid clean_post_cache/save_post hooks: only non-mutating object-cache invalidation here.
        wp_cache_delete( $post_id, 'posts' ); wp_cache_delete( $post_id, 'post_meta' ); wp_cache_delete( $post_id, 'post_parent' ); wp_cache_set( 'last_changed', microtime(), 'posts' );
        if ( function_exists( 'acf_flush_value_cache' ) ) { acf_flush_value_cache( $post_id ); }
        if ( $taxonomy && class_exists( 'Nova_Bridge_Suite_Taxonomy_Counts' ) ) { Nova_Bridge_Suite_Taxonomy_Counts::cache( $taxonomy, $post_id ); }
    }
    private static function operational_meta( string $key ): bool { return 0 === strpos( $key, '_nova_writer_' ) || in_array( $key, [ '_edit_lock', '_edit_last', '_elementor_css', '_elementor_element_cache' ], true ); }
    private static function state_digest( array $snapshot ): string {
        $meta = [];
        foreach ( $snapshot['meta'] as $row ) { if ( ! self::operational_meta( $row['meta_key'] ) ) { $meta[] = [ $row['meta_key'], $row['meta_value'] ]; } }
        usort( $meta, static function ( $a, $b ) { return strcmp( self::json( $a ), self::json( $b ) ); } );
        return self::digest( [ 'post' => $snapshot['post'], 'meta' => $meta, 'terms' => $snapshot['terms'] ] );
    }
    /** Publication may change native status/dates, never the verified document or routing. */
    private static function publication_digest( array $snapshot ): string {
        foreach ( [ 'post_status', 'post_date', 'post_date_gmt', 'post_modified', 'post_modified_gmt' ] as $column ) { unset( $snapshot['post'][ $column ] ); }
        return self::state_digest( $snapshot );
    }
    private static function save_marker( int $post_id, string $key, array $record, ?array $existing = null ): void {
        global $wpdb; $value = self::json( $record );
        if ( $existing ) {
            $changed = self::execute( $wpdb->prepare( 'UPDATE ' . self::table( 'postmeta' ) . ' SET post_id = %d, meta_value = %s WHERE meta_id = %d AND BINARY meta_value = BINARY %s', $post_id, $value, $existing['meta_id'], $existing['raw'] ) );
            if ( 1 !== $changed && $value !== $existing['raw'] ) { self::fail( 'marker_conflict', 'The durable writer marker changed concurrently.' ); }
        } else {
            if ( false === $wpdb->insert( $wpdb->postmeta, [ 'post_id' => $post_id, 'meta_key' => $key, 'meta_value' => $value ], [ '%d', '%s', '%s' ] ) ) { self::fail( 'marker_storage', 'The CMS mutation marker could not be persisted.' ); }
        }
    }
    private static function ensure_slug( array $post, string $slug, int $exclude ): void {
        global $wpdb;
        if ( ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug ) || ctype_digit( $slug ) || in_array( $slug, [ 'feed', 'rdf', 'rss', 'rss2', 'atom', 'trackback', 'embed' ], true ) ) { self::fail( 'slug', 'The planned slug is reserved or unsupported.' ); }
        $duplicates = self::rows( $wpdb->prepare( 'SELECT ID FROM ' . self::table( 'posts' ) . ' WHERE post_name = %s AND post_type IN (%s, %s) AND ID <> %d ORDER BY ID FOR UPDATE', $slug, $post['post_type'], 'attachment', $exclude ) );
        if ( $duplicates ) { self::fail( 'slug_conflict', 'Another native document already owns the planned slug.' ); }
    }

    public static function apply( array $plan, string $operation_id ) {
        return self::guard( static function () use ( $plan, $operation_id ) {
            self::assert_plan( $plan, $operation_id ); self::database_capability(); self::check_provider_capability( $plan );
            return self::actor( $plan['context'], static function () use ( $plan, $operation_id ) {
                global $wpdb; $started = false;
                try {
                    self::begin(); $started = true;
                    $existing = self::marker( self::operation_key( $operation_id ), true );
                    if ( $existing ) { $result = self::verified_marker( $plan, $existing ); self::commit(); $started = false; return $result; }
                    $current = self::snapshot( $plan['source_post_id'], true );
                    if ( 'clone' === $plan['operation'] && self::marker( self::clone_witness_key( $operation_id ), true ) ) { self::fail( 'ambiguous_marker', 'The source records a committed clone whose operation marker is missing; reconcile without creating another copy.' ); }
                    $source_fence = 'update' === $plan['operation'] ? self::target_fence( $plan['source_post_id'] ) : null;
                    if ( 'update' === $plan['operation'] ) { self::assert_target_fence( $plan['delivery_identity'], $operation_id, $source_fence['record'] ?? null ); }
                    $content_key = self::content_key( $plan['site_id'], $plan['content_id'] ); $content_marker = self::marker( $content_key, true );
                    if ( $content_marker ) {
                        if ( (int) $content_marker['record']['version'] >= $plan['version'] ) { self::fail( 'older_version', 'An equal or newer version already committed to this content target.' ); }
                        if ( 'complete' !== $content_marker['record']['state'] ) { self::fail( 'recovery_pending', 'The previous version still needs derived-work recovery.' ); }
                    }
                    if ( ! hash_equals( $plan['snapshot_digest'], self::digest( $current ) ) ) { self::fail( 'native_drift', 'The locked native document changed after planning; no CMS writes were applied.' ); }
                    if ( ! current_user_can( 'edit_post', $plan['source_post_id'] ) ) { self::fail( 'permission', 'Native edit permission was revoked.' ); }
                    self::check_row_permissions( $plan, $plan['source_post_id'] );
                    $type = get_post_type_object( $current['post']['post_type'] );
                    if ( ! $type || ( 'clone' === $plan['operation'] && ! current_user_can( $type->cap->create_posts ) ) || ( 'publish' === $plan['desired_status'] && ! current_user_can( $type->cap->publish_posts ) ) ) { self::fail( 'permission', 'Creation or publication permission changed after planning.' ); }
                    $post_id = $plan['source_post_id']; $meta_ids = []; $expected = $current;
                    if ( 'clone' === $plan['operation'] ) {
                        $post = $current['post']; unset( $post['ID'] );
                        $post['post_status'] = 'draft'; $post['post_name'] = $plan['changes']['post']['post_name']; $post['post_author'] = $plan['context']['actor_user_id'];
                        $post['post_date'] = current_time( 'mysql' ); $post['post_date_gmt'] = current_time( 'mysql', true ); $post['post_modified'] = $post['post_date']; $post['post_modified_gmt'] = $post['post_date_gmt']; $post['guid'] = '';
                        self::ensure_slug( $post, $post['post_name'], 0 );
                        if ( false === $wpdb->insert( $wpdb->posts, $post ) ) { self::fail( 'clone_insert', 'The unpublished clone could not be created.' ); }
                        $post_id = (int) $wpdb->insert_id;
                        if ( false === $wpdb->update( $wpdb->posts, [ 'guid' => home_url( '/?p=' . $post_id ) ], [ 'ID' => $post_id ] ) ) { self::fail( 'clone_insert', 'The clone identity could not be recorded.' ); }
                        $expected['post'] = array_merge( $post, [ 'ID' => $post_id, 'guid' => home_url( '/?p=' . $post_id ) ] );
                        $expected['meta'] = array_values( array_filter( $expected['meta'], static function ( $row ) { return ! self::operational_meta( $row['meta_key'] ); } ) );
                        foreach ( $expected['terms'] as &$term ) { $term['object_id'] = $post_id; } unset( $term );
                        foreach ( $current['meta'] as $row ) {
                            if ( self::operational_meta( $row['meta_key'] ) ) { continue; }
                            if ( false === $wpdb->insert( $wpdb->postmeta, [ 'post_id' => $post_id, 'meta_key' => $row['meta_key'], 'meta_value' => $row['meta_value'] ], [ '%d', '%s', '%s' ] ) ) { self::fail( 'clone_meta', 'The complete source metadata could not be copied.' ); }
                            $meta_ids[ $row['meta_id'] ] = (int) $wpdb->insert_id;
                        }
                        foreach ( $current['terms'] as $row ) { $row['object_id'] = $post_id; if ( false === $wpdb->insert( $wpdb->term_relationships, $row, [ '%d', '%d', '%d' ] ) ) { self::fail( 'clone_terms', 'The complete source term relationships could not be copied.' ); } }
                    } else { foreach ( $current['meta'] as $row ) { $meta_ids[ $row['meta_id'] ] = (int) $row['meta_id']; } }
                    if ( 'clone' === $plan['operation'] ) { self::check_row_permissions( $plan, $post_id ); }
                    $native = $plan['changes']['post'];
                    if ( isset( $native['post_name'] ) ) { self::ensure_slug( $current['post'], $native['post_name'], $post_id ); }
                    if ( $native || $plan['changes']['meta'] ) {
                        $native['post_modified'] = current_time( 'mysql' ); $native['post_modified_gmt'] = current_time( 'mysql', true );
                        if ( false === $wpdb->update( $wpdb->posts, $native, [ 'ID' => $post_id ] ) ) { self::fail( 'native_write', 'The native scalar update failed.' ); }
                        $expected['post'] = array_merge( $expected['post'], $native );
                    }
                    $changes = $plan['changes']['meta']; ksort( $changes, SORT_NUMERIC ); $old_rows = array_column( $current['meta'], null, 'meta_id' );
                    foreach ( $changes as $old_id => $value ) {
                        if ( ! isset( $meta_ids[ $old_id ], $old_rows[ $old_id ] ) ) { self::fail( 'physical_identity', 'A planned physical row is missing from the complete source.' ); }
                        if ( $value === $old_rows[ $old_id ]['meta_value'] ) { continue; }
                        $changed = self::execute( $wpdb->prepare( 'UPDATE ' . self::table( 'postmeta' ) . ' SET meta_value = %s WHERE meta_id = %d AND post_id = %d AND BINARY meta_value = BINARY %s', $value, $meta_ids[ $old_id ], $post_id, $old_rows[ $old_id ]['meta_value'] ) );
                        if ( 1 !== $changed ) { self::fail( 'physical_conflict', 'A native scalar row did not match its locked previous value.' ); }
                    }
                    foreach ( $expected['meta'] as &$row ) { if ( array_key_exists( $row['meta_id'], $changes ) ) { $row['meta_value'] = $changes[ $row['meta_id'] ]; } } unset( $row );
                    $expected['post'] = array_map( 'strval', $expected['post'] );
                    foreach ( $expected['terms'] as &$term ) { $term = array_map( 'strval', $term ); } unset( $term );
                    $after = self::snapshot( $post_id, true );
                    if ( ! hash_equals( self::state_digest( $expected ), self::state_digest( $after ) ) ) { self::fail( 'verification', 'The complete native post, metadata or term state differs from the approved scalar plan. The transaction was rolled back.' ); }
                    self::taxonomy_apply( $plan, 'clone' === $plan['operation'] ? null : $current, $after );
                    $record = [ 'state' => 'cms_committed', 'operation_id' => $operation_id, 'plan_digest' => $plan['plan_digest'], 'post_id' => $post_id, 'remote_post_id' => (string) $post_id, 'cms_post_status' => $after['post']['post_status'], 'content_id' => $plan['content_id'], 'version' => $plan['version'], 'template_id' => $plan['template_id'], 'template_revision' => $plan['template_revision'], 'profile_digest' => $plan['profile_digest'], 'site_id' => $plan['site_id'], 'derived_pending' => true, 'desired_status' => $plan['desired_status'], 'expected_state_digest' => self::state_digest( $after ), 'committed_at' => gmdate( 'c' ) ];
                    $record['delivery_identity'] = $plan['delivery_identity']; $record['elementor_id_map'] = $plan['elementor_id_map'];
                    $record['publication_state_digest'] = self::publication_digest( $after );
                    self::save_marker( $post_id, self::operation_key( $operation_id ), $record ); self::save_marker( $post_id, $content_key, $record, $content_marker );
                    self::save_target_fence( $post_id, $record, $source_fence );
                    if ( 'clone' === $plan['operation'] ) {
                        // ponytail: retain one source witness per clone until journal retirement defines safe pruning.
                        self::save_marker( $plan['source_post_id'], self::clone_witness_key( $operation_id ), [ 'state' => 'cms_committed', 'operation_id' => $operation_id, 'post_id' => $plan['source_post_id'], 'clone_post_id' => $post_id, 'plan_digest' => $plan['plan_digest'], 'delivery_identity' => $plan['delivery_identity'] ] );
                    }
                    self::commit(); $started = false;
                    try { self::cache( $post_id, $plan['taxonomy'] ?? [] ); } catch ( Throwable $ignored ) { /* The durable derived phase retries cache work. */ }
                    return $record;
                } finally { if ( $started ) { self::rollback(); } }
            } );
        } );
    }

    private static function verified_marker( array $plan, array $marker ): array {
        $record = $marker['record'];
        if ( ( $record['plan_digest'] ?? '' ) !== $plan['plan_digest'] || ( $record['operation_id'] ?? '' ) !== $plan['context']['operation_id'] || ( $record['template_id'] ?? '' ) !== $plan['template_id'] || ( $record['template_revision'] ?? null ) !== $plan['template_revision'] || ( $record['profile_digest'] ?? '' ) !== $plan['profile_digest'] || ( $record['version'] ?? null ) !== $plan['version'] || ! self::same( $record['delivery_identity'] ?? null, $plan['delivery_identity'] ) ) { self::fail( 'ambiguous_marker', 'A durable operation marker names a different plan or snapshot identity.' ); }
        $current = self::snapshot( $marker['post_id'], true );
        $latest = self::target_fence( $marker['post_id'] );
        if ( ! $latest ) { self::fail( 'target_fence', 'The committed native target has lost its ordering fence.' ); }
        $latest_identity = $latest['record']['delivery_identity'] ?? [];
        foreach ( [ 'site_id', 'url_id', 'content_id' ] as $member ) { if ( ( $latest_identity[ $member ] ?? null ) !== ( $plan['delivery_identity'][ $member ] ?? null ) ) { self::fail( 'target_fence', 'The committed native target has a different content identity.' ); } }
        $order = ( $latest_identity['version'] ?? 0 ) <=> $plan['version'];
        if ( $order < 0 || ( 0 === $order && ( ! self::same( $latest_identity, $plan['delivery_identity'] ) || $latest['record']['operation_id'] !== $record['operation_id'] ) ) ) { self::fail( 'target_fence', 'The native fence disagrees with the committed delivery identity.' ); }
        if ( $order > 0 ) { if ( 'complete' !== $record['state'] ) { self::fail( 'recovery_pending', 'An unfinished operation was superseded without verified completion.' ); } $record['superseded'] = true; return $record; }
        if ( ! hash_equals( $record['expected_state_digest'] ?? '', self::state_digest( $current ) ) ) {
            if ( 'complete' !== ( $record['state'] ?? '' ) || ! empty( $record['derived_pending'] ) || ! in_array( $record['cms_post_status'] ?? '', [ 'draft', 'future', 'pending', 'private' ], true ) || ! in_array( $current['post']['post_status'], [ 'draft', 'future', 'pending', 'private', 'publish' ], true ) || ! hash_equals( $record['publication_state_digest'] ?? '', self::publication_digest( $current ) ) ) { self::fail( 'recovery_drift', 'The committed native content or structure changed. Preserve the marker and reconcile before retrying.' ); }
            // WordPress changed only the publication envelope. Attest it without replaying writes.
            $content_key = self::content_key( $plan['site_id'], $plan['content_id'] );
            $content_marker = self::marker( $content_key, true );
            if ( ! $content_marker || ( $content_marker['record']['operation_id'] ?? null ) !== $record['operation_id'] ) { self::fail( 'marker_conflict', 'A different operation owns the content during publication verification.' ); }
            $record['publication_transition'] = [ 'from' => $record['cms_post_status'], 'to' => $current['post']['post_status'], 'verified_at' => gmdate( 'c' ), 'post_date_gmt' => $current['post']['post_date_gmt'] ?? null ];
            $record['cms_post_status'] = $current['post']['post_status']; $record['expected_state_digest'] = self::state_digest( $current );
            self::save_marker( $marker['post_id'], self::operation_key( $record['operation_id'] ), $record, $marker );
            self::save_marker( $marker['post_id'], $content_key, $record, $content_marker );
            self::save_target_fence( $marker['post_id'], $record, $latest );
        }
        return $record;
    }
    public static function recover( array $plan, string $operation_id ) {
        return self::guard( static function () use ( $plan, $operation_id ) {
            self::assert_plan( $plan, $operation_id ); self::database_capability(); $started = false;
            try {
                self::begin(); $started = true;
                $marker = self::marker( self::operation_key( $operation_id ), true );
                if ( $marker ) { $result = self::verified_marker( $plan, $marker ); }
                else {
                    if ( 'clone' === $plan['operation'] ) {
                        $prior_content = self::marker( self::content_key( $plan['site_id'], $plan['content_id'] ), true );
                        if ( self::marker( self::clone_witness_key( $operation_id ), true ) || ( $prior_content && ( $prior_content['record']['operation_id'] ?? null ) === $operation_id ) ) { self::fail( 'ambiguous_marker', 'Native clone evidence remains without this operation marker. Reconcile without declaring absence or creating another copy.' ); }
                    }
                    $fence = 'update' === $plan['operation'] ? self::target_fence( $plan['source_post_id'] ) : null;
                    if ( $fence && $fence['record']['operation_id'] === $operation_id ) { self::fail( 'ambiguous_marker', 'The native fence names this operation but its durable operation marker is missing.' ); }
                    try {
                        $current = self::snapshot( $plan['source_post_id'], true );
                        if ( 'update' === $plan['operation'] ) { self::assert_target_fence( $plan['delivery_identity'], $operation_id, $fence['record'] ?? null ); }
                        if ( ! hash_equals( $plan['snapshot_digest'], self::digest( $current ) ) ) { self::fail( 'native_drift', 'The target changed after planning; reconcile without replaying the old plan.' ); }
                        $result = [ 'state' => 'not_committed', 'no_native_commit' => true, 'safe_to_apply' => true ];
                    } catch ( Nova_Bridge_Suite_Writer_Failure $error ) {
                        if ( 'target' === $error->reason ) { self::fail( 'ambiguous_target', 'The native target is missing. Its post-local operation marker and fence may have been deleted after a commit; reconcile without declaring failure or replaying.' ); }
                        if ( ! in_array( $error->reason, [ 'target_conflict', 'older_version', 'recovery_pending', 'native_drift' ], true ) ) { throw $error; }
                        $result = [ 'state' => 'not_committed', 'no_native_commit' => true, 'safe_to_apply' => false, 'failure_code' => 'nova_writer_' . $error->reason ];
                    }
                }
                self::commit(); $started = false; return $result;
            } finally { if ( $started ) { self::rollback(); } }
        } );
    }
    public static function verify( array $plan, array $result ) {
        return self::recover( $plan, $result['operation_id'] ?? '' );
    }
    public static function finish( array $plan, array $result ) {
        return self::guard( static function () use ( $plan, $result ) {
            self::assert_plan( $plan, $result['operation_id'] ?? '' ); self::database_capability(); self::check_provider_capability( $plan );
            return self::actor( $plan['context'], static function () use ( $plan, $result ) {
                global $wpdb; $started = false;
                try {
                    self::begin(); $started = true; $before = self::snapshot( (int) $result['post_id'], true );
                    $marker = self::marker( self::operation_key( $result['operation_id'] ), true );
                    if ( ! $marker ) { self::fail( 'ambiguous_marker', 'Derived recovery has no committed operation marker.' ); }
                    $record = self::verified_marker( $plan, $marker );
                    if ( 'complete' === $record['state'] ) { self::commit(); $started = false; self::cache( $record['post_id'], $plan['taxonomy'] ?? [] ); return $record; }
                    $post_id = $record['post_id'];
                    $fences = [];
                    foreach ( [ $post_id ] as $fence_id ) {
                        $fence = self::target_fence( $fence_id );
                        if ( ! $fence && $fence_id !== $post_id && ! self::rows( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'posts' ) . ' WHERE ID = %d FOR UPDATE', $fence_id ) ) ) { continue; }
                        if ( ! $fence || $fence['record']['operation_id'] !== $record['operation_id'] || ! self::same( $fence['record']['delivery_identity'], $plan['delivery_identity'] ) ) { self::fail( 'target_fence', 'A different snapshot owns the target during derived recovery.' ); }
                        $fences[ $fence_id ] = $fence;
                    }
                    if ( ! current_user_can( 'edit_post', $post_id ) ) { self::fail( 'permission', 'Native edit permission was revoked during recovery.' ); }
                    self::cache( $post_id, $plan['taxonomy'] ?? [] );
                    if ( $plan['uses_elementor'] ) {
                        $derived = call_user_func( self::$elementor_derived['callback'], $post_id, [ 'operation_id' => $record['operation_id'], 'plan_digest' => $plan['plan_digest'], 'allowed_meta' => [ '_elementor_css', '_elementor_element_cache' ] ] );
                        if ( is_wp_error( $derived ) || ! is_array( $derived ) || true !== ( $derived['completed'] ?? false ) ) { self::fail( 'derived_pending', 'Verified Elementor cache/CSS work is incomplete; retry only this derived phase.' ); }
                        if ( self::state_digest( self::snapshot( $post_id, true ) ) !== $record['expected_state_digest'] ) { self::fail( 'derived_mutation', 'The derived adapter changed native content or protected state; its database changes were rolled back.' ); }
                    }
                    if ( $record['desired_status'] !== $record['cms_post_status'] ) {
                        $type = get_post_type_object( $plan['snapshot']['post']['post_type'] );
                        if ( ! $type || ( 'publish' === $record['desired_status'] && ! current_user_can( $type->cap->publish_posts ) ) ) { self::fail( 'permission', 'Publication permission was revoked during recovery.' ); }
                        if ( false === $wpdb->update( $wpdb->posts, [ 'post_status' => $record['desired_status'] ], [ 'ID' => $post_id ] ) ) { self::fail( 'publication', 'The verified publication status update failed.' ); }
                    }
                    $after = self::snapshot( $post_id, true );
                    self::taxonomy_apply( $plan, $before, $after );
                    $record['cms_post_status'] = $after['post']['post_status']; $record['state'] = 'complete'; $record['derived_pending'] = false; $record['expected_state_digest'] = self::state_digest( $after ); $record['completed_at'] = gmdate( 'c' );
                    $record['publication_state_digest'] = self::publication_digest( $after );
                    self::save_marker( $post_id, self::operation_key( $record['operation_id'] ), $record, $marker );
                    $key = self::content_key( $plan['site_id'], $plan['content_id'] ); $content_marker = self::marker( $key, true );
                    if ( ! $content_marker || $content_marker['record']['operation_id'] !== $record['operation_id'] ) { self::fail( 'marker_conflict', 'A different content version owns the native target during recovery.' ); }
                    self::save_marker( $post_id, $key, $record, $content_marker );
                    foreach ( $fences as $fence_id => $fence ) { self::save_target_fence( $fence_id, $record, $fence ); }
                    self::commit(); $started = false; self::cache( $post_id, $plan['taxonomy'] ?? [] ); return $record;
                } finally { if ( $started ) { self::rollback(); } }
            } );
        } );
    }
}
