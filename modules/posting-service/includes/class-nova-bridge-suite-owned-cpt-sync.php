<?php
/** Automatic publishing descriptions for the two CPTs owned by this plugin. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Owned_CPT_Sync {
    public static function bootstrap(): void { add_action( 'wp_loaded', [ __CLASS__, 'refresh' ], 100 ); }

    /** Refresh only when a live native profile changes; no polling cron is required. */
    public static function refresh(): void {
        if ( ! class_exists( 'Nova_Bridge_Suite_Posting_Settings' ) || ! function_exists( 'rest_get_server' ) ) { return; }
        $connection = Nova_Bridge_Suite_Posting_Settings::connection();
        if ( empty( $connection['enabled'] ) || empty( $connection['site_id'] ) ) { return; }
        rest_get_server(); // Registers the active CPT's public context callbacks and schema.
        $types = [];
        if ( class_exists( 'SEORAI\\ServicePageCPT\\Plugin', false ) && post_type_exists( 'service_page' ) ) { $types['service_page'] = [ 'page_type' => 'service', 'select_default' => true ]; }
        if ( class_exists( 'SEORAI\\BodycleanCPT\\Plugin', false ) && function_exists( 'nova_bridge_suite_get_managed_blog_post_types' ) ) {
            $blogs = array_values( array_filter( nova_bridge_suite_get_managed_blog_post_types(), 'post_type_exists' ) );
            // A content-type default cannot choose safely between multiple native blog CPTs.
            foreach ( $blogs as $blog_type ) { $types[ $blog_type ] = [ 'page_type' => 'informative', 'select_default' => 1 === count( $blogs ) ]; }
        }
        foreach ( $types as $post_type => $policy ) {
            $page_type = $policy['page_type']; $select_default = $policy['select_default'];
            $built = self::build( $post_type, $page_type );
            if ( is_wp_error( $built ) ) { continue; }
            $marker = 'nova_owned_cpt_refresh_' . hash( 'sha256', $connection['site_id'] . ':' . ( $connection['base_url'] ?? '' ) . ':' . $post_type . ':' . $page_type );
            $revision = $built['draft']['revision'] . ( $select_default ? ':default' : ':unselected' ); $last = get_option( $marker, [] );
            if ( is_array( $last ) && ( $last['revision'] ?? '' ) === $revision && ( in_array( $last['status'] ?? '', [ 'synced', 'unselected' ], true ) || (int) ( $last['retry_after'] ?? 0 ) > time() ) ) { continue; }
            $result = self::synchronize( $connection, $post_type, $page_type, null, $select_default );
            if ( is_wp_error( $result ) && 'nova_owned_cpt_default_conflict' === $result->get_error_code() ) {
                // Keep the existing default and make our profile available to an explicit URL binding.
                $result = self::synchronize( $connection, $post_type, $page_type, null, false );
            }
            if ( is_wp_error( $result ) ) {
                update_option( $marker, [ 'revision' => $revision, 'status' => 'failed', 'retry_after' => time() + 300, 'code' => $result->get_error_code() ], false );
                error_log( 'NOVA owned CPT synchronization failed: ' . $post_type . ' ' . $result->get_error_code() );
            } else {
                update_option( $marker, [ 'revision' => $revision, 'status' => ! empty( $result['default_selected'] ) ? 'synced' : 'unselected', 'template_id' => $result['template_id'], 'template_revision' => $result['template_revision'] ], false );
            }
        }
    }
    private static function error( string $code, string $message ): WP_Error { return new WP_Error( 'nova_owned_cpt_' . $code, $message, [ 'status' => 409 ] ); }
    private static function json( $value ): string { return Nova_Bridge_Suite_Writing_Adapter::canonical_json( $value ); }
    private static function profile_key( string $site, string $id, int $revision ): string { return 'nova_owned_cpt_profile_' . hash( 'sha256', $site . ':' . $id . ':' . $revision ); }
    private static function same( $a, $b ): bool { return self::json( $a ) === self::json( $b ); }
    private static function stable_uuid( string $value ): string {
        $hash = hash( 'sha256', $value );
        return substr( $hash, 0, 8 ) . '-' . substr( $hash, 8, 4 ) . '-4' . substr( $hash, 13, 3 ) . '-8' . substr( $hash, 17, 3 ) . '-' . substr( $hash, 20, 12 );
    }

    /** Read the registered live context: this never requires an example post. */
    public static function build( string $post_type, string $page_type ) {
        $service = 'service_page' === $post_type && class_exists( 'SEORAI\\ServicePageCPT\\Plugin', false );
        $blog = class_exists( 'SEORAI\\BodycleanCPT\\Plugin', false ) && function_exists( 'nova_bridge_suite_get_managed_blog_post_types' ) && in_array( $post_type, nova_bridge_suite_get_managed_blog_post_types(), true );
        if ( ( ! $service && ! $blog ) || ! post_type_exists( $post_type ) || '' === $page_type ) { return self::error( 'module', 'The requested NOVA-owned post type is not active.' ); }
        $registered = $GLOBALS['wp_rest_additional_fields'][ $post_type ] ?? [];
        $schema = $registered['meta_descriptions']['schema']['properties'] ?? null;
        $callback = $registered['meta_descriptions']['get_callback'] ?? null;
        if ( ! is_array( $schema ) || ! is_callable( $callback ) ) { return self::error( 'context', 'The active CPT field descriptions are not registered yet.' ); }
        $request = new WP_REST_Request( 'GET' );
        $descriptions = call_user_func( $callback, [ 'id' => 0 ], 'meta_descriptions', $request );
        if ( ! is_array( $descriptions ) ) { return self::error( 'context', 'The active CPT field descriptions are unavailable.' ); }
        $candidates = $service
            ? [ 'sp_hero_title' => 'text', 'sp_hero_copy' => 'rich_text', 'sp_intro' => 'rich_text', 'sp_main_1' => 'rich_text', 'sp_main_2' => 'rich_text', 'sp_main_3' => 'rich_text', 'sp_extra_copy' => 'rich_text' ]
            : [ 'blog_intro' => 'rich_text', 'blog_part_1' => 'rich_text', 'blog_part_2' => 'rich_text' ];
        $faq = $service ? 'sp_faq' : 'blog_faqs';
        $has_faq = isset( $schema[$faq], $descriptions[$faq] );
        $draft = [ 'reference_type' => 'post', 'reference_id' => 0, 'catalog_mode' => 'destination', 'profile_page_type' => $page_type, 'label' => $service ? 'NOVA Service page' : 'NOVA Blog page', 'guidance_mode' => 'set', 'guidance' => 'Distribute the provided source content among the available fields without losing or repeating sections. ' . ( $has_faq ? 'Place complete source FAQ question and answer pairs in the available FAQ slots, and retain any overflow in rich text. Do not invent questions or answers.' : 'Include any FAQ content in the available rich text fields.' ), 'routing' => [ 'post_type' => $post_type, 'operation' => 'create', 'publication' => 'publish' ], 'fields' => [], 'target_descriptors' => [], 'destination_groups' => [], 'repeat_slots' => [] ];
        // Notes about unavailable structured fields would cause content to be dropped.
        // Keep the remaining site/layout-specific context as additional guidance.
        $note_callback = $registered['meta_note']['get_callback'] ?? null;
        if ( is_callable( $note_callback ) ) {
            $note = call_user_func( $note_callback, [ 'id' => 0 ], 'meta_note', $request );
            if ( is_string( $note ) && 1 === preg_match( '//u', $note ) && false === strpos( $note, "\0" ) ) {
                $sentences = preg_split( '/(?<=[.!?])\s+/u', trim( $note ) );
                foreach ( $sentences as $sentence ) {
                    if ( '' !== $sentence && ! preg_match( '/faq|sp_image|blog_image|\btabs?\b/i', $sentence ) && strlen( $draft['guidance'] . ' ' . $sentence ) <= 8000 ) { $draft['guidance'] .= ' ' . $sentence; }
                }
            }
        }
        $local = [ 'resource_id' => 'post_type:' . $post_type, 'routing' => $draft['routing'], 'fields' => [] ];
        $all = [ '/title' => [ 'text', 'Page title', 'The title of the page or article.' ] ];
        foreach ( $candidates as $key => $type ) {
            $purpose = $descriptions[ $key ] ?? '';
            if ( ! isset( $schema[ $key ] ) || ! is_string( $purpose ) || '' === trim( $purpose ) ) { continue; }
            $all['/meta/' . $key] = [ $type, ucwords( str_replace( '_', ' ', preg_replace( '/^(sp|blog)_/', '', $key ) ) ), $purpose ];
        }
        if ( 1 === count( $all ) ) { return self::error( 'context', 'No supported active editorial fields are available for this CPT.' ); }
        $hints = []; $instructions = [];
        if ( function_exists( 'post_type_supports' ) && post_type_supports( $post_type, 'thumbnail' ) ) {
            $all['/featured_media'] = [ 'url', 'Featured image', 'The source image URL for the page or article featured image.' ];
            $hints['/featured_media'] = [ 'image_url', 'image_urls' ];
            $instructions['/featured_media'] = 'Use only an image URL from image_url or image_urls. Never use the page URL. Leave empty if no source image exists.';
            $all['/image_alt'] = [ 'text', 'Featured image alternative text', 'The alternative text supplied for the source image.' ];
            $hints['/image_alt'] = [ 'image_alt' ];
            $instructions['/image_alt'] = 'Use only the supplied image_alt value, unchanged. Leave empty if it is missing.';
        }
        if ( $service ) {
            foreach ( [ 'sp_image_1', 'sp_image_2' ] as $image_key ) {
                if ( ! isset( $schema[$image_key], $descriptions[$image_key] ) || ! is_string( $descriptions[$image_key] ) || '' === trim( $descriptions[$image_key] ) ) { continue; }
                $path = '/meta/' . $image_key;
                $purpose = preg_replace( '/attachment\s+ID/i', 'source image URL', $descriptions[$image_key] );
                $all[$path] = [ 'url', ucwords( str_replace( '_', ' ', $image_key ) ), $purpose . ' Use the URL of a supplied source image.' ];
                $hints[$path] = [ 'image_url', 'image_urls' ];
                $instructions[$path] = 'Use only an image URL from image_url or image_urls. Never use the page URL. Leave empty if no source image exists.';
            }
        }
        if ( class_exists( 'Nova_Bridge_Suite_Owned_CPT_SEO' ) && Nova_Bridge_Suite_Owned_CPT_SEO::available() ) {
            $all['/@nova/seo/title'] = [ 'text', 'SEO title', 'The original NOVA title used as the SEO title.' ];
            $all['/@nova/seo/description'] = [ 'text', 'SEO description', 'The original NOVA meta description used as the SEO description.' ];
            $hints['/@nova/seo/title'] = [ 'title' ];
            $hints['/@nova/seo/description'] = [ 'meta_description' ];
            $instructions['/@nova/seo/title'] = 'Use only the source title exactly as provided. Leave empty if it is missing.';
            $instructions['/@nova/seo/description'] = 'Use only the source meta_description exactly as provided. Leave empty if it is missing.';
        }
        if ( $has_faq ) {
            $group = [ 'id' => self::stable_uuid( $post_type . ':' . $faq ), 'label' => 'FAQ', 'slots' => [] ];
            for ( $i = 0; $i < 8; ++$i ) {
                $question = '/meta/' . $faq . '/' . $i . '/question'; $answer = '/meta/' . $faq . '/' . $i . '/answer';
                $all[$question] = [ 'text', 'FAQ ' . ( $i + 1 ) . ' question', 'The question in an existing source FAQ pair, if present.' ];
                $all[$answer] = [ 'rich_text', 'FAQ ' . ( $i + 1 ) . ' answer', 'The answer corresponding to this existing source FAQ question, if present.' ];
                $group['slots'][] = [ 'id' => self::stable_uuid( $post_type . ':' . $faq . ':' . $i ), 'ordinal' => $i, 'fields' => [ $question, $answer ] ];
            }
            $draft['destination_groups'][] = $group;
        }
        if ( $blog && isset( $schema['blog_key_takeaways'], $descriptions['blog_key_takeaways'] ) ) {
            $group = [ 'id' => self::stable_uuid( $post_type . ':blog_key_takeaways' ), 'label' => 'Key takeaways', 'slots' => [] ];
            for ( $i = 0; $i < 5; ++$i ) {
                $path = '/meta/blog_key_takeaways/' . $i;
                $all[$path] = [ 'text', 'Key takeaway ' . ( $i + 1 ), 'One existing source key takeaway or bullet, if present. Do not invent a takeaway.' ];
                $group['slots'][] = [ 'id' => self::stable_uuid( $post_type . ':blog_key_takeaways:' . $i ), 'ordinal' => $i, 'fields' => [ $path ] ];
            }
            $draft['destination_groups'][] = $group;
        }
        foreach ( $all as $path => $details ) {
            list( $type, $label, $purpose ) = $details;
            $draft['fields'][ $path ] = [ 'mode' => 'adapt', 'source_path' => '', 'required' => '/title' === $path, 'instructions' => $instructions[$path] ?? '', 'description' => [ 'label' => $label, 'purpose' => $purpose, 'value_type' => $type, 'constraints' => [] ] ];
            $draft['target_descriptors'][ $path ] = [ 'path' => $path ];
            $local['fields'][ Nova_Bridge_Suite_Writing_Adapter::field_id( $path ) ] = [ 'target' => $path, 'value_type' => $type ];
        }
        if ( $hints ) { $draft['source_hints'] = $hints; }
        $draft['revision'] = hash( 'sha256', self::json( $draft ) );
        $draft['signature'] = $draft['revision'];
        return [ 'draft' => $draft, 'local' => $local ];
    }

    private static function defaults( $reply ) {
        if ( is_wp_error( $reply ) ) { return $reply; }
        $body = $reply['body'] ?? [];
        if ( 200 !== ( $reply['status'] ?? 0 ) || ! is_array( $body['defaults'] ?? null ) || ! is_int( $body['revision'] ?? null ) || $body['revision'] < 1 || ( $reply['etag'] ?? '' ) !== '"' . $body['revision'] . '"' ) { return self::error( 'defaults', 'Posting service returned invalid template defaults or revision.' ); }
        foreach ( $body['defaults'] as $id ) { if ( ! Nova_Bridge_Suite_Posting_Protocol::uuid( $id ) ) { return self::error( 'defaults', 'Posting service returned an invalid template ID.' ); } }
        return $body['defaults'];
    }

    /** Synchronize only this owned type, preserving all other site templates and defaults. */
    public static function synchronize( array $connection, string $post_type, string $page_type, $client = null, bool $select_default = true ) {
        if ( empty( $connection['enabled'] ) ) { return self::error( 'connection', 'The posting connection is disabled.' ); }
        $built = self::build( $post_type, $page_type ); if ( is_wp_error( $built ) ) { return $built; }
        $client = $client ?: new Nova_Bridge_Suite_Posting_Client( $connection );
        $invalid = $client->connection_error(); if ( is_wp_error( $invalid ) ) { return $invalid; }
        $site = $client->site_id(); $scope = 'owned-cpt:' . $post_type . ':' . $page_type;
        $reply = null; $defaults = []; $selected = null;
        if ( $select_default ) {
            $reply = $client->site_request( 'GET', '/template-defaults' ); $defaults = self::defaults( $reply ); if ( is_wp_error( $defaults ) ) { return $defaults; }
            $previous = Nova_Bridge_Suite_Mapping_Sync::scope_state( $site, $scope );
            $selected = $defaults[ $page_type ] ?? null;
            if ( $selected && $selected !== ( $previous['template']['id'] ?? null ) ) { return self::error( 'default_conflict', 'A different template is already selected for this content type. It was not changed.' ); }
        }
        $sync = new Nova_Bridge_Suite_Mapping_Sync( $client, $scope );
        $state = $sync->synchronize( $built['draft'] ); if ( is_wp_error( $state ) ) { return $state; }
        $record = $state['template'] ?? null;
        if ( ! Nova_Bridge_Suite_Writing_Adapter::template_record( $record ) || empty( $record['enabled'] ) || null !== ( $record['retired_at'] ?? null ) || 3 !== ( $record['definition']['version'] ?? null ) ) { return self::error( 'template', 'The synchronized template is not an active V3 template.' ); }
        $frozen = array_intersect_key( $record, array_flip( [ 'id', 'name', 'page_type', 'definition', 'mapping', 'revision' ] ) );
        $profile = [ 'mode' => 'owned_cpt_v3', 'site_id' => $site, 'template' => $frozen, 'draft' => $built['draft'], 'local' => $built['local'], 'profile_digest' => hash( 'sha256', self::json( $built['local'] ) ) ];
        $key = self::profile_key( $site, $record['id'], $record['revision'] );
        if ( ! add_option( $key, $profile, '', false ) && ! self::same( get_option( $key, null ), $profile ) ) { return self::error( 'profile_conflict', 'This template revision already has a different native profile.' ); }
        update_option( 'nova_posting_v3_' . hash( 'sha256', $site ), true, false );
        if ( ! Nova_Bridge_Suite_Mapping_Sync::destination_enabled( $site ) ) { return self::error( 'storage', 'V3 delivery discovery could not be enabled.' ); }
        if ( $select_default && $selected !== $record['id'] ) {
            $defaults[ $page_type ] = $record['id'];
            $confirmed = self::defaults( $client->site_request( 'PUT', '/template-defaults', [ 'defaults' => $defaults ], [ 'If-Match' => $reply['etag'] ] ) );
            if ( is_wp_error( $confirmed ) ) { return $confirmed; }
            if ( ! self::same( $confirmed, $defaults ) ) { return self::error( 'default_conflict', 'The default template update was not confirmed.' ); }
        }
        return [ 'status' => 'synced', 'default_selected' => $select_default, 'template_id' => $record['id'], 'template_revision' => $record['revision'], 'page_type' => $page_type, 'draft_revision' => $built['draft']['revision'] ];
    }

    /** Null means this is another writer's template; mismatched owned profiles fail closed. */
    public static function configuration_for_snapshot( array $snapshot, string $site_id ) {
        $remote = $snapshot['configuration'] ?? null;
        if ( ( $snapshot['site_id'] ?? null ) !== $site_id || ! is_array( $remote ) || ! Nova_Bridge_Suite_Posting_Protocol::uuid( $remote['id'] ?? null ) || ! is_int( $remote['revision'] ?? null ) || $remote['revision'] < 1 ) { return null; }
        $profile = get_option( self::profile_key( $site_id, $remote['id'], $remote['revision'] ), null );
        if ( null === $profile ) { return null; }
        if ( ! is_array( $profile ) || ( $profile['mode'] ?? '' ) !== 'owned_cpt_v3' || ( $profile['site_id'] ?? '' ) !== $site_id || ! Nova_Bridge_Suite_Posting_Protocol::configuration_matches( $remote, $profile['template'] ?? null ) || ! is_array( $profile['local'] ?? null ) || ! is_array( $profile['draft'] ?? null ) || ! hash_equals( hash( 'sha256', self::json( $profile['local'] ) ), $profile['profile_digest'] ?? '' ) ) { return self::error( 'profile_mismatch', 'The frozen template does not match its native CPT profile.' ); }
        $remote = $profile['template'];
        $input = Nova_Bridge_Suite_Writing_Adapter::template_input( $profile['draft'], 3 ); if ( is_wp_error( $input ) ) { return $input; }
        unset( $input['enabled'] );
        foreach ( $input as $key => $value ) { if ( ! array_key_exists( $key, $remote ) || ! self::same( $value, $remote[ $key ] ) ) { return self::error( 'profile_mismatch', 'The retained draft does not match the frozen template.' ); } }
        return $profile;
    }
}
