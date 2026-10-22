<?php
/** Standard delivery lifecycle for the explicitly approved scalar category adapter. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Static_Term_Writer {
    public const FORMAT = 'nova.static-term-writer/v1';
    private static function error( string $code, string $message ): WP_Error { return Nova_Bridge_Suite_Static_Recipe_Adapter::error( $code, $message ); }
    private static function digest( array $value ): string { return Nova_Bridge_Suite_Static_Recipe_Adapter::digest( $value ); }
    private static function key( string $operation ): string { return 'nova_static_term_operation_' . hash( 'sha256', $operation ); }
    private static function save( string $key, array $value ) { update_option( $key, $value, false ); return get_option( $key, null ) === $value ? true : self::error( 'journal_storage', 'The native category journal could not be retained.' ); }
    private static function capability() {
        $taxonomy = get_taxonomy( 'product_cat' );
        return $taxonomy && current_user_can( 'manage_options' ) && current_user_can( $taxonomy->cap->manage_terms ) && current_user_can( $taxonomy->cap->edit_terms ) ? true : self::error( 'permission', 'The configured actor cannot create product categories.' );
    }

    public static function plan( array $snapshot, array $configuration, array $context ) {
        try { return self::build_plan( $snapshot, $configuration, $context ); }
        catch ( Nova_Bridge_Suite_Recipe_Failure $error ) { return $error->error; }
        catch ( InvalidArgumentException $error ) { return self::error( 'content_shape', $error->getMessage() ); }
        catch ( Throwable $error ) { return self::error( 'plan_unavailable', 'The category plan could not be prepared. No native write was attempted.' ); }
    }
    private static function build_plan( array $snapshot, array $configuration, array $context ) {
        $valid = Nova_Bridge_Suite_Static_Recipe_Adapter::validate_profile( $snapshot, $configuration ); if ( is_wp_error( $valid ) ) { return $valid; }
        if ( ! Nova_Bridge_Suite_Posting_Protocol::uuid( $context['operation_id'] ?? null ) || ( $context['site_id'] ?? '' ) !== $snapshot['site_id'] || ( $context['operation'] ?? '' ) !== 'create' || ( $context['desired_status'] ?? '' ) !== 'publish' ) { return self::error( 'operation_scope', 'The category plan requires one exact create-and-publish operation.' ); }
        $policy = Nova_Bridge_Suite_Static_Recipe_Adapter::policy( $snapshot, $configuration ); if ( is_wp_error( $policy ) ) { return $policy; }
        $capability = self::capability(); if ( is_wp_error( $capability ) ) { return $capability; }
        $record = $configuration['record'];
        $content = Nova_Bridge_Suite_Recipe_Engine::empty_content( $record['content_schema'] ?? [] );
        if ( ! is_array( $content ) ) { return self::error( 'content_schema', 'The exact saved recipe must declare its complete content object before delivery.' ); }
        foreach ( $configuration['projection']['sources'] as $source => $binding ) {
            $value = $snapshot['content'][$source] ?? null;
            if ( null === $value ) { if ( $binding['required'] ) { return self::error( 'missing_value', 'A required category source is missing: ' . $source ); } $value = ''; }
            if ( ! is_string( $value ) || ( 'h1' === $source && '' === trim( sanitize_text_field( $value ) ) ) || ( 'h1' !== $source && wp_kses_post( $value ) !== $value ) ) { return self::error( 'value_type', 'The category source is not supported editorial text: ' . $source ); }
            Nova_Bridge_Suite_Recipe_Engine::put( $content, $binding['source'], $value );
        }
        $compiled = Nova_Bridge_Suite_Layout_Recipes::delivery_compile( $record['id'], $record['revision'], $content, $context['operation_id'] ); if ( is_wp_error( $compiled ) ) { return $compiled; }
        $payload = $compiled['writer_payload'] ?? [];
        if ( ! is_array( $payload ) || ! is_string( $payload['name'] ?? null ) || '' === trim( sanitize_text_field( $payload['name'] ) ) || array_diff( array_keys( $payload ), [ 'name', 'description', 'content_below_products' ] ) ) { return self::error( 'payload_scope', 'The compiled category contains unsupported destinations.' ); }
        $plan = [ 'format' => self::FORMAT, 'operation_id' => $context['operation_id'], 'configuration' => $configuration, 'identity' => array_intersect_key( $snapshot, array_flip( [ 'site_id', 'client_id', 'domain_id', 'url_id', 'content_item_id', 'content_item_version_id', 'version_number', 'url', 'language', 'page_type_frontend', 'source_sha256', 'configuration' ] ) ), 'target' => $policy['target'], 'payload' => $payload, 'profile_context' => [ 'profile_id' => $record['id'], 'revision' => $record['revision'] ] ];
        $plan['plan_digest'] = self::digest( $plan ); return $plan;
    }
    private static function validate_plan( array $plan, string $operation ) {
        $unsigned = $plan; unset( $unsigned['plan_digest'] );
        if ( ( $plan['format'] ?? '' ) !== self::FORMAT || $operation !== ( $plan['operation_id'] ?? null ) || ! hash_equals( self::digest( $unsigned ), (string) ( $plan['plan_digest'] ?? '' ) ) ) { return self::error( 'plan_identity', 'The retained native plan or operation identity changed.' ); }
        return Nova_Bridge_Suite_Static_Recipe_Adapter::validate_profile( $plan['identity'], $plan['configuration'] );
    }
    private static function receipt( array $plan, int $id ): array {
        return [ 'term_id' => $id, 'native_kind' => 'term', 'cms_post_status' => 'publish', 'publication_ref' => 'wordpress:term:product_cat:' . $id . ';operation:' . $plan['operation_id'] ];
    }
    private static function readback( array $plan, int $id ) {
        $term = get_term( $id, 'product_cat' );
        if ( ! $term || is_wp_error( $term ) || $term->taxonomy !== 'product_cat' || $term->slug !== $plan['target']['slug'] || (int) $term->parent !== $plan['target']['parent'] || ! current_user_can( 'edit_term', $id ) || get_term_meta( $id, '_nova_static_term_operation', true ) !== $plan['operation_id'] || get_term_meta( $id, '_nova_static_term_digest', true ) !== $plan['plan_digest'] ) { return self::error( 'category_witness', 'The category no longer matches the exact native operation witness.' ); }
        $native_url = Nova_Bridge_Suite_Mapped_Content_Target::native_url( 'taxonomy:product_cat', 'term', $id ); if ( is_wp_error( $native_url ) ) { return $native_url; }
        $url = Nova_Bridge_Suite_Mapped_Content_Target::normalize_url( $native_url );
        if ( is_wp_error( $url ) || $url['identity'] !== $plan['target']['target_url'] ) { return self::error( 'category_url', 'The saved category does not have its approved URL.' ); }
        $language = Nova_Bridge_Suite_Mapped_Content_Target::verify_created_language( 'taxonomy:product_cat', 'term', $id, $plan['target'] ); if ( is_wp_error( $language ) ) { return $language; }
        $read = Nova_Bridge_Suite_Term_Content::read( 'product_cat', $id, $plan['profile_context'] ); if ( is_wp_error( $read ) ) { return $read; }
        $payload = class_exists( 'Nova_Bridge_Suite_Recipe_Term_Fields' ) ? Nova_Bridge_Suite_Recipe_Term_Fields::delivery_payload( $plan['configuration']['record']['recipe']['blueprint'], $plan['payload'] ) : $plan['payload'];
        foreach ( $payload as $field => $value ) {
            if ( in_array( $field, [ 'name', 'description' ], true ) ) {
                $input = 'name' === $field ? sanitize_text_field( $value ) : wp_kses_post( $value ); $value = sanitize_term_field( $field, wp_slash( $input ), $id, 'product_cat', 'db' );
                if ( is_wp_error( $value ) || ! is_string( $value ) ) { return self::error( 'category_sanitizer', 'The installed category sanitizer cannot verify the written value.' ); } $value = wp_unslash( $value );
            }
            if ( ! array_key_exists( $field, $read ) || self::digest( [ $value ] ) !== self::digest( [ $read[$field] ] ) ) { return self::error( 'category_readback', 'The category field differs from its retained native plan: ' . $field ); }
        }
        return self::receipt( $plan, $id );
    }

    /** A writing journal never authorizes another insertion, including a crash before the witness. */
    public static function apply( array $plan, string $operation ) {
        $valid = self::validate_plan( $plan, $operation ); if ( is_wp_error( $valid ) ) { return $valid; }
        $key = self::key( $operation ); if ( null !== get_option( $key, null ) ) { return self::recover( $plan, $operation ); }
        $capability = self::capability(); if ( is_wp_error( $capability ) ) { return $capability; }
        $target = Nova_Bridge_Suite_Mapped_Content_Target::creation( 'taxonomy:product_cat', $plan['identity']['url'], $plan['identity']['language'] ); if ( is_wp_error( $target ) ) { return $target; }
        if ( self::digest( $target ) !== self::digest( $plan['target'] ) ) { return self::error( 'target_changed', 'The native category route changed after planning.' ); }
        $journal = [ 'plan_digest' => $plan['plan_digest'], 'phase' => 'writing', 'term_id' => 0 ];
        if ( ! add_option( $key, $journal, '', false ) ) { return self::recover( $plan, $operation ); }
        try {
            $result = Nova_Bridge_Suite_Mapped_Content_Target::with_creation_language( $target, static function () use ( $plan, $operation, $key, &$journal ) {
                $capability = self::capability(); if ( is_wp_error( $capability ) ) { return $capability; }
                $witness = static function ( $id, $tt, $taxonomy ) use ( $plan, $operation, $key, &$journal ) {
                    if ( 'product_cat' !== $taxonomy ) { return; } $term = get_term( $id, $taxonomy );
                    if ( ! $term || $term->slug !== $plan['target']['slug'] || (int) $term->parent !== $plan['target']['parent'] ) { return; }
                    update_term_meta( $id, '_nova_static_term_operation', $operation ); update_term_meta( $id, '_nova_static_term_digest', $plan['plan_digest'] ); $journal['term_id'] = (int) $id; self::save( $key, $journal );
                };
                add_action( 'created_term', $witness, 1, 3 );
                try { $created = wp_insert_term( sanitize_text_field( $plan['payload']['name'] ), 'product_cat', [ 'slug' => $plan['target']['slug'], 'parent' => $plan['target']['parent'] ] ); } finally { remove_action( 'created_term', $witness, 1 ); }
                if ( is_wp_error( $created ) ) { return $created; } $id = (int) $created['term_id'];
                if ( $journal['term_id'] !== $id || get_term_meta( $id, '_nova_static_term_operation', true ) !== $operation || get_term_meta( $id, '_nova_static_term_digest', true ) !== $plan['plan_digest'] ) { return self::error( 'category_witness', 'Category insertion has no complete durable operation witness. Inspect before retrying.' ); }
                $written = Nova_Bridge_Suite_Term_Content::apply( 'product_cat', $id, $plan['payload'], $plan['profile_context'] ); if ( is_wp_error( $written ) ) { return $written; }
                return self::readback( $plan, $id );
            } );
            if ( is_wp_error( $result ) ) { return $result; }
            $journal['phase'] = 'complete'; $journal['term_id'] = $result['term_id']; $saved = self::save( $key, $journal ); return is_wp_error( $saved ) ? $saved : $result;
        } catch ( Throwable $error ) { return self::error( 'uncertain', 'Category delivery stopped before verified completion. Its journal must be reconciled without another insertion.' ); }
    }
    /** Read-only native recovery. Partial saves and missing witnesses always remain uncertain. */
    public static function recover( array $plan, string $operation ) {
        $valid = self::validate_plan( $plan, $operation ); if ( is_wp_error( $valid ) ) { return $valid; }
        $journal = get_option( self::key( $operation ), null );
        if ( null === $journal ) { return [ 'state' => 'not_committed', 'safe_to_apply' => true, 'no_native_commit' => true ]; }
        if ( ! is_array( $journal ) || ( $journal['plan_digest'] ?? '' ) !== $plan['plan_digest'] ) { return self::error( 'operation_conflict', 'This native operation belongs to another retained plan.' ); }
        if ( ! empty( $journal['term_id'] ) ) { return self::readback( $plan, (int) $journal['term_id'] ); }
        return self::error( 'uncertain', 'An insertion was attempted without a durable term identity. No duplicate category will be created.' );
    }
    public static function verify( array $plan, array $result ) {
        $valid = self::validate_plan( $plan, $plan['operation_id'] ?? '' ); if ( is_wp_error( $valid ) ) { return $valid; }
        $journal = get_option( self::key( $plan['operation_id'] ), null );
        if ( ! is_array( $journal ) || ( $journal['plan_digest'] ?? '' ) !== $plan['plan_digest'] || ! is_int( $result['term_id'] ?? null ) || ( $journal['term_id'] ?? 0 ) !== $result['term_id'] ) { return self::error( 'result_identity', 'The term receipt differs from its retained native operation.' ); }
        return self::readback( $plan, $result['term_id'] );
    }
}
