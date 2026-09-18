<?php
/** Exact local row plans. No generic save hooks, remote commands or whole-parent ACF writes. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Writer_Failure extends RuntimeException {
    public $reason;
    public function __construct( string $reason, string $message ) { $this->reason = $reason; parent::__construct( $message ); }
}

final class Nova_Bridge_Suite_Mapped_Writer {
    public const WRITER_ID = 'nova_verified_rows_v1';
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
    private static function actor( array $context, callable $operation ) {
        $id = (int) ( $context['actor_user_id'] ?? 0 );
        if ( $id < 1 || ! get_userdata( $id ) ) { self::fail( 'actor', 'A valid locally authorized publishing user is required.' ); }
        $previous = get_current_user_id();
        wp_set_current_user( $id );
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
                $operation = $local['routing']['operation'] ?? '';
                $post_id = 'clone' === $operation ? (int) ( $local['reference_id'] ?? 0 ) : (int) ( $context['target_post_id'] ?? $local['reference_id'] ?? 0 );
                if ( 'clone' === $operation ) {
                    $existing = self::marker( self::content_key( (string) ( $context['site_id'] ?? '' ), (string) ( $content['content_id'] ?? '' ) ) );
                    if ( $existing ) { $post_id = (int) $existing['post_id']; $context['effective_operation'] = 'update'; }
                }
                if ( ! current_user_can( 'edit_post', $post_id ) ) { self::fail( 'permission', 'The publishing user cannot edit this native document.' ); }
                $entity = Nova_Bridge_Suite_Strategy::entity( 'post', $post_id );
                if ( ! $entity ) { self::fail( 'target', 'The selected document is not an eligible local publishing target.' ); }
                $fingerprint = Nova_Bridge_Suite_Strategy::fingerprint( $entity );
                $context['layout_signature'] = $fingerprint['signature'];
                $context['source_post_id'] = $post_id;
                $context['provider_tuple'] = self::runtime_tuple();
                $inventory = array_column( Nova_Bridge_Suite_Strategy::field_inventory( $entity ), null, 'path' );
                $plan = self::build_plan( $content, $configuration, $context, self::snapshot( $post_id ), $inventory );
                self::check_row_permissions( $plan, $post_id );
                $type = get_post_type_object( $plan['snapshot']['post']['post_type'] );
                if ( ! $type || ( 'clone' === $plan['operation'] && ! current_user_can( $type->cap->create_posts ) ) || ( 'publish' === $plan['desired_status'] && ! current_user_can( $type->cap->publish_posts ) ) ) { self::fail( 'permission', 'The publishing user lacks the required create or publication capability.' ); }
                self::database_capability();
                self::check_provider_capability( $plan );
                return $plan;
            } );
        } );
    }

    /** Pure plan construction over a fresh raw snapshot; throws a Writer_Failure on unsafe input. */
    public static function build_plan( array $content, array $configuration, array $context, array $snapshot, array $inventory ): array {
        $pin = $configuration['pin'] ?? $configuration;
        $template = $configuration['template'] ?? [];
        $mapping = $configuration['mapping'] ?? [];
        $local = $configuration['local'] ?? [];
        foreach ( [ 'pin_id', 'digest' ] as $key ) {
            if ( ! is_string( $pin[ $key ] ?? null ) || '' === $pin[ $key ] || ( $content[ $key ] ?? null ) !== $pin[ $key ] ) { self::fail( 'pin_mismatch', 'The content does not name the exact retained publishing pin and digest.' ); }
        }
        if ( ! preg_match( '/^[a-f0-9]{64}$/D', $pin['digest'] ) || ( $pin['site_id'] ?? null ) !== ( $context['site_id'] ?? null ) || empty( $context['site_id'] ) ) { self::fail( 'site_mismatch', 'The publishing pin does not belong to this installation.' ); }
        foreach ( [ 'template_id', 'template_version' ] as $key ) {
            if ( empty( $template[ $key ] ) || ( $pin[ $key ] ?? null ) !== $template[ $key ] || ( $content[ $key ] ?? null ) !== $template[ $key ] || ( $mapping[ $key ] ?? null ) !== $template[ $key ] ) { self::fail( 'template_mismatch', 'The exact content, mapping and template revisions do not agree.' ); }
        }
        foreach ( [ 'mapping_id', 'mapping_revision_id' ] as $key ) { if ( empty( $mapping[ $key ] ) || ( $pin[ $key ] ?? null ) !== $mapping[ $key ] ) { self::fail( 'mapping_mismatch', 'The pin does not identify this exact mapping revision.' ); } }
        if ( 'sealed' !== ( $mapping['state'] ?? '' ) || 'sealed' !== ( $template['state'] ?? '' ) || empty( $local['revision'] ) || ( $local['reference_type'] ?? '' ) !== 'post' ) { self::fail( 'configuration', 'Execution requires sealed exact revisions and their retained concrete post mapping.' ); }
        if ( ( $local['signature'] ?? null ) !== ( $context['layout_signature'] ?? null ) ) { self::fail( 'layout_drift', 'The native layout changed since this mapping was approved.' ); }
        self::verify_policy( $mapping, $local );
        if ( ! is_int( $content['version'] ?? null ) || $content['version'] < 1 || ! is_string( $content['content_id'] ?? null ) || '' === $content['content_id'] || ( $context['version'] ?? $content['version'] ) !== $content['version'] || ( $context['content_id'] ?? $content['content_id'] ) !== $content['content_id'] ) { self::fail( 'version', 'The worker and fetched content identities do not agree.' ); }
        if ( ( $local['routing']['locale'] ?? '' ) !== '' && ( $content['locale'] ?? null ) !== $local['routing']['locale'] ) { self::fail( 'locale', 'The content locale does not match the retained mapping.' ); }
        if ( ! is_string( $context['operation_id'] ?? null ) || ! preg_match( '/^[A-Za-z0-9_-]{1,128}$/D', $context['operation_id'] ) ) { self::fail( 'operation', 'A stable local operation identity is required before execution.' ); }
        $configured_operation = $local['routing']['operation'] ?? '';
        $operation = $context['effective_operation'] ?? $configured_operation;
        if ( ! in_array( $operation, [ 'update', 'clone' ], true ) || ! in_array( $configured_operation, [ 'update', 'clone' ], true ) || ( isset( $context['operation'] ) && $context['operation'] !== $configured_operation ) ) { self::fail( 'operation', 'The worker operation differs from the approved local mapping.' ); }
        $publication = $local['routing']['publication'] ?? 'preserve';
        if ( ! in_array( $publication, [ 'preserve', 'draft', 'publish' ], true ) ) { self::fail( 'publication', 'The approved publication policy is unsupported.' ); }
        $desired = 'preserve' === $publication ? ( 'clone' === $operation ? 'draft' : $snapshot['post']['post_status'] ) : $publication;
        if ( ! in_array( $snapshot['post']['post_status'], [ 'publish', 'draft', 'private', 'pending' ], true ) || ! in_array( $desired, [ 'publish', 'draft', 'private', 'pending' ], true ) ) { self::fail( 'publication', 'This native post state is outside the direct writer scope.' ); }
        $taxonomy = self::taxonomy_plan( $snapshot, $operation, $desired );
        if ( isset( $local['routing']['post_type'] ) && $local['routing']['post_type'] !== $snapshot['post']['post_type'] ) { self::fail( 'post_type', 'The document post type changed.' ); }
        if ( ( $mapping['cpt'] ?? null ) !== $snapshot['post']['post_type'] ) { self::fail( 'post_type', 'The exact mapping is assigned to a different post type.' ); }
        if ( strlen( self::json( $snapshot ) ) > self::MAX_SNAPSHOT_BYTES ) { self::fail( 'snapshot_size', 'This document exceeds the bounded writer snapshot size.' ); }
        $values = $content['fields'] ?? null;
        if ( ! is_array( $values ) ) { self::fail( 'values', 'Generated values must be a closed object.' ); }
        $definitions = [];
        foreach ( $template['fields'] ?? [] as $field ) { $definitions[ $field['field_key'] ] = $field; }
        $groups = [];
        foreach ( $template['groups'] ?? [] as $group ) { $groups[ $group['group_key'] ] = $group; foreach ( $group['fields'] as $field ) { $definitions[ $group['group_key'] . '[].' . $field['field_key'] ] = $field; } }
        foreach ( $values as $key => $value ) { if ( ! isset( $definitions[ $key ] ) && ! isset( $groups[ $key ] ) ) { self::fail( 'unknown_value', 'Generated values contain an unknown or protected source key.' ); } }
        foreach ( $template['fields'] ?? [] as $field ) { if ( array_key_exists( $field['field_key'], $values ) ) { self::validate_value( $values[ $field['field_key'] ], $field ); } elseif ( $field['required'] ) { self::fail( 'missing_value', 'A required generated field is missing.' ); } }
        foreach ( $groups as $key => $group ) {
            if ( ! array_key_exists( $key, $values ) ) { if ( $group['required'] ) { self::fail( 'missing_value', 'A required repeat group is missing.' ); } continue; }
            $rows = $values[ $key ];
            if ( ! is_array( $rows ) || ( $rows && array_keys( $rows ) !== range( 0, count( $rows ) - 1 ) ) || count( $rows ) < $group['minItems'] || count( $rows ) > $group['maxItems'] ) { self::fail( 'repeat_bounds', 'A generated group does not satisfy its exact template bounds.' ); }
            $allowed = array_column( $group['fields'], null, 'field_key' );
            foreach ( $rows as $row ) {
                if ( ! is_array( $row ) || array_diff( array_keys( $row ), array_keys( $allowed ) ) ) { self::fail( 'repeat_shape', 'A repeat member has unknown fields.' ); }
                foreach ( $allowed as $member => $field ) { if ( array_key_exists( $member, $row ) ) { self::validate_value( $row[ $member ], $field ); } elseif ( $field['required'] ) { self::fail( 'missing_value', 'A required repeat member is missing.' ); } }
            }
        }
        $writes = []; $guards = []; $protected_slots = []; $seen_sources = [];
        foreach ( $local['fields'] ?? [] as $path => $field ) {
            $descriptor = $local['target_descriptors'][ $path ] ?? null;
            if ( ! is_array( $descriptor ) ) { self::fail( 'local_descriptor', 'A retained local target descriptor is missing.' ); }
            if ( 'protected' === $field['mode'] ) {
                $guards[] = self::resolve_target( $descriptor, $snapshot, $inventory, true );
                if ( ! empty( $field['protected_slot'] ) ) {
                    if ( isset( $protected_slots[ $field['protected_slot'] ] ) ) { self::fail( 'protected_identity', 'A canonical protected slot has more than one local identity.' ); }
                    $protected_slots[ $field['protected_slot'] ] = true;
                }
            } elseif ( 'leave_empty' === $field['mode'] && 'clone' === $operation ) { $writes[] = [ 'target' => self::resolve_target( $descriptor, $snapshot, $inventory ), 'value' => '', 'source_path' => null ]; }
        }
        if ( array_diff( $template['protected_identities'] ?? [], array_keys( $protected_slots ) ) || array_diff( array_keys( $protected_slots ), $template['protected_identities'] ?? [] ) ) { self::fail( 'protected_identity', 'Every canonical protected slot needs its exact retained native binding.' ); }
        foreach ( $mapping['bindings'] ?? [] as $binding ) {
            $source = $binding['source_path'] ?? '';
            if ( ! isset( $definitions[ $source ] ) || isset( $seen_sources[ $source ] ) ) { self::fail( 'binding', 'Mapping bindings contain an unknown or duplicate generated source.' ); }
            $seen_sources[ $source ] = true;
            $identity = $binding['expected_identity'] ?? [];
            foreach ( [ 'reference_type', 'reference_id', 'signature' ] as $key ) { if ( (string) ( $identity[ $key ] ?? '' ) !== (string) ( $local[ $key ] ?? '' ) ) { self::fail( 'binding_identity', 'A canonical mapping binding differs from the approved reference.' ); } }
            if ( ( $identity['local_revision'] ?? null ) !== $local['revision'] || ( $binding['source_type'] ?? '' ) !== $definitions[ $source ]['field_type'] ) { self::fail( 'binding_identity', 'A canonical binding does not match the retained local revision or source type.' ); }
            $target = $binding['target_descriptor'] ?? [];
            if ( ( $target['format'] ?? '' ) !== 'nova_bridge_target_v1' ) { self::fail( 'descriptor_version', 'This mapping uses an unsupported native descriptor format.' ); }
            if ( false === strpos( $source, '[].' ) ) {
                $descriptor = $target['target'] ?? [];
                $path = $descriptor['path'] ?? '';
                $local_field = $local['fields'][ $path ] ?? [];
                if ( ( $local_field['mode'] ?? '' ) !== 'mapped' || ( $local_field['source_path'] ?? '' ) !== $source || ! self::same( $descriptor, $local['target_descriptors'][ $path ] ?? null ) ) { self::fail( 'binding_identity', 'The canonical target differs from the retained local source binding.' ); }
                if ( array_key_exists( $source, $values ) ) { $writes[] = [ 'target' => self::resolve_target( $descriptor, $snapshot, $inventory ), 'value' => $values[ $source ], 'source_path' => $source ]; }
            } else {
                list( $key, $member ) = explode( '[].', $source, 2 );
                $slots = $local['repeat_slots'][ $key ] ?? [];
                $expected = [];
                foreach ( $slots as $slot ) { if ( ! isset( $slot['targets'][ $member ] ) ) { self::fail( 'repeat_binding', 'An approved fixed slot has no member target.' ); } $expected[] = [ 'slot_id' => $slot['id'], 'target' => $local['target_descriptors'][ $slot['targets'][ $member ] ] ]; }
                if ( ! self::same( $target['slots'] ?? null, $expected ) ) { self::fail( 'repeat_binding', 'Canonical repeat slots differ from the approved existing native slots.' ); }
                if ( ! array_key_exists( $key, $values ) ) { continue; }
                if ( count( $values[ $key ] ) !== count( $slots ) ) { self::fail( 'repeat_capacity', 'Generation must exactly fit the approved existing slot count; native rows cannot be created or removed.' ); }
                $instances = $context['repeat_instances'][ $key ] ?? [];
                if ( count( $instances ) !== count( $slots ) || count( array_unique( array_column( $instances, 'instance_id' ) ) ) !== count( $slots ) ) { self::fail( 'repeat_identity', 'Execution requires verified repeat-instance correspondence for every fixed slot.' ); }
                foreach ( $expected as $index => $slot ) {
                    if ( ( $instances[ $index ]['slot_id'] ?? '' ) !== $slot['slot_id'] || ! is_string( $instances[ $index ]['instance_id'] ?? null ) || '' === $instances[ $index ]['instance_id'] ) { self::fail( 'repeat_identity', 'Repeat-instance identity or ordering changed.' ); }
                    if ( array_key_exists( $member, $values[ $key ][ $index ] ) ) { $writes[] = [ 'target' => self::resolve_target( $slot['target'], $snapshot, $inventory ), 'value' => $values[ $key ][ $index ][ $member ], 'source_path' => $source ]; }
                }
            }
        }
        if ( array_diff( array_keys( $definitions ), array_keys( $seen_sources ) ) ) { self::fail( 'coverage', 'The sealed mapping does not cover every generated source in this exact template.' ); }
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
        $plan = [ 'format' => self::WRITER_ID, 'context' => $context, 'operation' => $operation, 'desired_status' => $desired, 'site_id' => $context['site_id'], 'content_id' => $content['content_id'], 'version' => $content['version'], 'pin_id' => $pin['pin_id'], 'digest' => $pin['digest'], 'source_post_id' => (int) $snapshot['post']['ID'], 'snapshot' => $snapshot, 'snapshot_digest' => self::digest( $snapshot ), 'changes' => $changes, 'protected' => $guards, 'uses_acf' => $uses_acf, 'uses_elementor' => $is_elementor, 'taxonomy' => $taxonomy ];
        $plan['plan_digest'] = self::digest( $plan );
        return $plan;
    }

    private static function verify_policy( array $mapping, array $local ): void {
        if ( ! class_exists( 'Nova_Bridge_Suite_Writing_Adapter' ) ) { self::fail( 'policy', 'The canonical local policy adapter is unavailable.' ); }
        $json = Nova_Bridge_Suite_Writing_Adapter::canonical_json( Nova_Bridge_Suite_Writing_Adapter::policy( $local ) );
        $digest = hash( 'sha256', $json );
        $bindings = $mapping['bindings'] ?? [];
        if ( ! $bindings || ( $bindings[0]['expected_identity']['plugin_policy_json'] ?? null ) !== $json ) { self::fail( 'policy', 'The canonical mapping does not contain the exact approved local policy.' ); }
        foreach ( $bindings as $binding ) { if ( ( $binding['expected_identity']['plugin_policy_digest'] ?? null ) !== $digest ) { self::fail( 'policy', 'The canonical mapping policy digest differs from the retained policy.' ); } }
    }

    private static function validate_value( $value, array $field ): void {
        $type = $field['field_type'] ?? '';
        if ( in_array( $type, [ 'heading', 'plain_text', 'rich_text', 'meta' ], true ) ) {
            if ( ! is_string( $value ) || preg_match( '//u', $value ) !== 1 || false !== strpos( $value, "\0" ) ) { self::fail( 'value_type', 'A generated scalar has the wrong value type or encoding.' ); }
            $length = preg_match_all( '/./us', $value, $ignored );
            $minimum = $field['minLength'] ?? ( 'rich_text' === $type ? 0 : 1 );
            $maximum = $field['maxLength'] ?? ( 'rich_text' === $type ? ( $field['html']['max_utf8_bytes'] ?? 0 ) : ( 'heading' === $type ? 200 : ( 'meta' === $type ? ( 'title' === ( $field['semantic'] ?? '' ) ? 60 : ( 'description' === ( $field['semantic'] ?? '' ) ? 160 : 200 ) ) : 4000 ) ) );
            if ( $length < $minimum || $length > $maximum ) { self::fail( 'value_length', 'A generated scalar violates its pinned length limits.' ); }
            if ( 'rich_text' === $type ) {
                $policy = $field['html'] ?? [];
                if ( ! isset( $policy['max_utf8_bytes'], $policy['allowed_tags'], $policy['allowed_url_protocols'] ) || strlen( $value ) > $policy['max_utf8_bytes'] || ! function_exists( 'wp_kses' ) ) { self::fail( 'html_policy', 'The exact rich-text policy cannot be validated.' ); }
                $tags = [];
                foreach ( $policy['allowed_tags'] as $tag => $attributes ) {
                    $tags[ $tag ] = [];
                    foreach ( $attributes as $attribute ) {
                        $forbidden = false;
                        foreach ( $policy['forbidden_attributes'] ?? [] as $pattern ) { if ( preg_match( '/^' . str_replace( '\\*', '.*', preg_quote( $pattern, '/' ) ) . '$/iD', $attribute ) ) { $forbidden = true; break; } }
                        if ( ! $forbidden ) { $tags[ $tag ][ $attribute ] = true; }
                    }
                }
                if ( wp_kses( $value, $tags, $policy['allowed_url_protocols'] ) !== $value ) { self::fail( 'html_policy', 'Generated HTML violates the exact pinned allowlist.' ); }
            } elseif ( preg_match( '/<[^>]*>/', $value ) ) { self::fail( 'html_policy', 'A text-only source contains HTML.' ); }
            if ( 'meta' === $type && 'slug' === ( $field['semantic'] ?? '' ) && ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $value ) ) { self::fail( 'slug', 'A generated slug violates the pinned format.' ); }
            return;
        }
        // These types have no direct-row value adapter; reject before any CMS mutation.
        self::fail( 'value_adapter', 'A generated field requires an image, link or list adapter outside this scalar writer scope.' );
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
                self::verify_policy( $mapping, $local );
                $inventory = array_column( Nova_Bridge_Suite_Strategy::field_inventory( $entity ), null, 'path' );
                $snapshot = self::snapshot( $post_id );
                $operation = $local['routing']['operation'] ?? '';
                $publication = $local['routing']['publication'] ?? 'preserve';
                $type = get_post_type_object( $snapshot['post']['post_type'] );
                if ( ! in_array( $operation, [ 'update', 'clone' ], true ) || ! in_array( $publication, [ 'preserve', 'draft', 'publish' ], true ) || ! $type || ( 'clone' === $operation && ! current_user_can( $type->cap->create_posts ) ) || ( 'publish' === $publication && ! current_user_can( $type->cap->publish_posts ) ) ) { self::fail( 'permission', 'The approved routing exceeds the publishing user capabilities.' ); }
                $desired = 'preserve' === $publication ? ( 'clone' === $operation ? 'draft' : $snapshot['post']['post_status'] ) : $publication;
                self::taxonomy_plan( $snapshot, $operation, $desired );
                $definitions = [];
                foreach ( $template['fields'] as $field ) { $definitions[ $field['field_key'] ] = $field; }
                foreach ( $template['groups'] as $group ) {
                    $slots = $local['repeat_slots'][ $group['group_key'] ] ?? [];
                    if ( ! $slots || count( $slots ) !== $group['minItems'] || count( $slots ) !== $group['maxItems'] ) { self::fail( 'repeat_capacity', 'A contracted repeat group must exactly fit the approved existing native slots.' ); }
                    foreach ( $group['fields'] as $field ) { $definitions[ $group['group_key'] . '[].' . $field['field_key'] ] = $field; }
                }
                $guards = []; $protected_slots = []; $targets = []; $coverage = []; $uses_acf = false; $slug = false;
                foreach ( $local['fields'] as $path => $field ) {
                    if ( 'protected' === $field['mode'] ) {
                        $guards[] = self::resolve_target( $local['target_descriptors'][ $path ], $snapshot, $inventory, true );
                        if ( ! empty( $field['protected_slot'] ) ) { if ( isset( $protected_slots[ $field['protected_slot'] ] ) ) { self::fail( 'protected_identity', 'A protected slot has duplicate native identities.' ); } $protected_slots[ $field['protected_slot'] ] = true; }
                    } elseif ( 'leave_empty' === $field['mode'] && 'clone' === $operation ) { $targets[] = self::resolve_target( $local['target_descriptors'][ $path ], $snapshot, $inventory ); }
                }
                if ( array_diff( $template['protected_identities'], array_keys( $protected_slots ) ) || array_diff( array_keys( $protected_slots ), $template['protected_identities'] ) ) { self::fail( 'protected_identity', 'Canonical protected slots require exact local bindings.' ); }
                foreach ( $mapping['bindings'] as $binding ) {
                    $source = $binding['source_path'];
                    if ( ! isset( $definitions[ $source ] ) || isset( $coverage[ $source ] ) || ! in_array( $definitions[ $source ]['field_type'], [ 'heading', 'plain_text', 'rich_text', 'meta' ], true ) ) { self::fail( 'value_adapter', 'Every generated source needs one supported scalar binding before activation.' ); }
                    $coverage[ $source ] = true; $descriptor = $binding['target_descriptor'];
                    if ( 'nova_bridge_target_v1' !== ( $descriptor['format'] ?? '' ) ) { self::fail( 'descriptor_version', 'A target descriptor uses an unsupported format.' ); }
                    $items = isset( $descriptor['target'] ) ? [ $descriptor['target'] ] : array_column( $descriptor['slots'] ?? [], 'target' );
                    if ( ! $items ) { self::fail( 'repeat_binding', 'A generated source has no concrete native destination.' ); }
                    foreach ( $items as $item ) {
                        if ( ! self::same( $item, $local['target_descriptors'][ $item['path'] ?? '' ] ?? null ) ) { self::fail( 'binding_identity', 'A canonical binding differs from the approved native descriptor.' ); }
                        $target = self::resolve_target( $item, $snapshot, $inventory ); $targets[] = $target;
                        $slug = $slug || ( 'post' === $target['kind'] && 'post_name' === $target['column'] );
                    }
                }
                if ( array_diff( array_keys( $definitions ), array_keys( $coverage ) ) ) { self::fail( 'coverage', 'A generated source has no verified native destination.' ); }
                foreach ( $targets as $index => $target ) {
                    $uses_acf = $uses_acf || ! empty( $target['acf'] );
                    foreach ( $guards as $protected ) { if ( self::overlaps( $target, $protected ) ) { self::fail( 'protected_overlap', 'A destination overlaps protected native content.' ); } }
                    foreach ( array_slice( $targets, 0, $index ) as $other ) { if ( self::overlaps( $target, $other ) ) { self::fail( 'physical_overlap', 'Two sources resolve to overlapping native storage.' ); } }
                }
                if ( 'clone' === $operation && ! $slug ) { self::fail( 'clone_slug', 'Clone activation requires a generated field mapped to native slug.' ); }
                $elementor = (bool) self::meta_rows( $snapshot, '_elementor_data' );
                if ( $elementor ) { self::one_meta( $snapshot, '_elementor_page_settings' ); }
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
    private static function content_key( string $site_id, string $content_id ): string { return '_nova_writer_content_' . hash( 'sha256', $site_id . "\0" . $content_id ); }
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
    private static function save_marker( int $post_id, string $key, array $record, ?array $existing = null ): void {
        global $wpdb; $value = self::json( $record );
        if ( $existing ) {
            $changed = self::execute( $wpdb->prepare( 'UPDATE ' . self::table( 'postmeta' ) . ' SET meta_value = %s WHERE meta_id = %d AND BINARY meta_value = BINARY %s', $value, $existing['meta_id'], $existing['raw'] ) );
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
                    $content_key = self::content_key( $plan['site_id'], $plan['content_id'] ); $content_marker = self::marker( $content_key, true );
                    if ( $content_marker ) {
                        if ( (int) $content_marker['record']['version'] >= $plan['version'] ) { self::fail( 'older_version', 'An equal or newer version already committed to this content target.' ); }
                        if ( 'complete' !== $content_marker['record']['state'] ) { self::fail( 'recovery_pending', 'The previous version still needs derived-work recovery.' ); }
                        if ( 'clone' === $plan['operation'] || $content_marker['post_id'] !== $plan['source_post_id'] ) { self::fail( 'target_changed', 'A retained content target already exists. Rebuild the plan for that target.' ); }
                        if ( ! self::same( $content_marker['record']['repeat_instances'] ?? [], $plan['context']['repeat_instances'] ?? [] ) ) { self::fail( 'repeat_identity', 'Generated repeat-instance correspondence changed for an existing content target.' ); }
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
                    $record = [ 'state' => 'cms_committed', 'operation_id' => $operation_id, 'plan_digest' => $plan['plan_digest'], 'post_id' => $post_id, 'remote_post_id' => (string) $post_id, 'cms_post_status' => $after['post']['post_status'], 'content_id' => $plan['content_id'], 'version' => $plan['version'], 'pin_id' => $plan['pin_id'], 'digest' => $plan['digest'], 'site_id' => $plan['site_id'], 'derived_pending' => true, 'desired_status' => $plan['desired_status'], 'expected_state_digest' => self::state_digest( $after ), 'committed_at' => gmdate( 'c' ) ];
                    $record['repeat_instances'] = $plan['context']['repeat_instances'] ?? [];
                    self::save_marker( $post_id, self::operation_key( $operation_id ), $record ); self::save_marker( $post_id, $content_key, $record, $content_marker );
                    self::commit(); $started = false;
                    try { self::cache( $post_id, $plan['taxonomy'] ?? [] ); } catch ( Throwable $ignored ) { /* The durable derived phase retries cache work. */ }
                    return $record;
                } finally { if ( $started ) { self::rollback(); } }
            } );
        } );
    }

    private static function verified_marker( array $plan, array $marker ): array {
        $record = $marker['record'];
        if ( ( $record['plan_digest'] ?? '' ) !== $plan['plan_digest'] || ( $record['operation_id'] ?? '' ) !== $plan['context']['operation_id'] || ( $record['pin_id'] ?? '' ) !== $plan['pin_id'] || ( $record['version'] ?? null ) !== $plan['version'] ) { self::fail( 'ambiguous_marker', 'A durable operation marker names a different plan or content identity.' ); }
        $current = self::snapshot( $marker['post_id'], true );
        if ( ! hash_equals( $record['expected_state_digest'] ?? '', self::state_digest( $current ) ) ) {
            $latest = self::marker( self::content_key( $plan['site_id'], $plan['content_id'] ), true );
            if ( 'complete' === $record['state'] && $latest && (int) $latest['record']['version'] > $plan['version'] ) { $record['superseded'] = true; return $record; }
            self::fail( 'recovery_drift', 'The committed native state changed. Preserve the marker and reconcile before retrying.' );
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
                else { self::snapshot( $plan['source_post_id'], true ); $result = [ 'state' => 'not_committed', 'safe_to_apply' => true ]; }
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
                    self::save_marker( $post_id, self::operation_key( $record['operation_id'] ), $record, $marker );
                    $key = self::content_key( $plan['site_id'], $plan['content_id'] ); $content_marker = self::marker( $key, true );
                    if ( ! $content_marker || $content_marker['record']['operation_id'] !== $record['operation_id'] ) { self::fail( 'marker_conflict', 'A different content version owns the native target during recovery.' ); }
                    self::save_marker( $post_id, $key, $record, $content_marker );
                    self::commit(); $started = false; self::cache( $post_id, $plan['taxonomy'] ?? [] ); return $record;
                } finally { if ( $started ) { self::rollback(); } }
            } );
        } );
    }
}
