<?php
/** Publish a sealed, natively created recipe draft with retained routing/readback authority. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class Nova_Bridge_Suite_Mapped_Content_Post_Writer {
    private static function error( string $code, string $message, int $status = 409 ): WP_Error { return new WP_Error( 'nova_mapped_' . $code, $message, [ 'status' => $status, 'error_code' => $code ] ); }
    private static function hash( $value ): string { return Nova_Bridge_Suite_Recipe_Engine::digest( $value ); }
    public static function plan( array $record, array $delivery ) {
        if ( ! in_array( $record['builder'], [ 'core', 'elementor' ], true ) ) { return self::error( 'create_provider', 'This builder has no certified mapped publication lifecycle yet.' ); }
        $type = substr( $record['resource_id'], 10 ); $object = get_post_type_object( $type );
        if ( ! $object || ! current_user_can( $object->cap->create_posts ?? $object->cap->edit_posts ) || ! current_user_can( $object->cap->publish_posts ?? 'publish_posts' ) ) { return self::error( 'permission', 'The configured actor cannot create and publish at this destination.', 403 ); }
        $target = $delivery['target'];
        if ( ! is_string( $target['target_url'] ?? null ) || ! function_exists( 'wp_unique_post_slug' ) || wp_unique_post_slug( $target['slug'], 0, 'publish', $type, $target['parent'] ) !== $target['slug'] ) { return self::error( 'create_route', 'This target needs a unique planned native URL before publication.' ); }
        $compiled = Nova_Bridge_Suite_Layout_Recipes::delivery_draft_content( $record['id'], $record['revision'], $delivery['content'], $delivery['operation_id'] ); if ( is_wp_error( $compiled ) ) { return $compiled; }
        $title = $compiled['writer_payload']['title'] ?? ''; $headings = [];
        $walk = static function ( array $nodes ) use ( &$walk, &$headings ) { foreach ( $nodes as $node ) { if ( ( $node['widgetType'] ?? '' ) === 'heading' && ( $node['settings']['header_size'] ?? '' ) === 'h1' && is_string( $node['settings']['title'] ?? null ) ) { $headings[] = sanitize_text_field( $node['settings']['title'] ); } $walk( $node['elements'] ?? [] ); } };
        if ( 'elementor' === $record['builder'] ) { $walk( $compiled['writer_payload']['elementor_data'] ); if ( count( array_unique( $headings ) ) === 1 ) { $title = $headings[0]; } }
        if ( ! is_string( $title ) || '' === trim( $title ) ) { $title = $delivery['publication']['title'] ?? ''; }
        $title = is_string( $title ) ? sanitize_text_field( $title ) : ''; if ( '' === $title || strlen( $title ) > 200 ) { return self::error( 'create_title', 'The mapped content needs one unambiguous page title.', 422 ); }
        return [ 'mode' => 'create', 'native_kind' => 'post', 'resource_id' => $record['resource_id'], 'post_type' => $type, 'builder' => $record['builder'], 'target' => $target, 'title' => $title, 'payload' => $compiled['writer_payload'], 'profile_context' => [ 'id' => $record['id'], 'revision' => $record['revision'] ], 'content' => $delivery['content'] ];
    }
    private static function content( array $plan, int $id ) {
        $language = Nova_Bridge_Suite_Mapped_Content_Target::verify_created_language( $plan['resource_id'], 'post', $id, $plan['target'] ); if ( is_wp_error( $language ) ) { return $language; }
        $post = get_post( $id );
        if ( ! $post || $post->post_type !== $plan['post_type'] || $post->post_title !== $plan['title'] || ! current_user_can( 'edit_post', $id ) ) { return self::error( 'post_witness', 'The native item no longer matches its original recipe delivery.' ); }
        if ( 'core' === $plan['builder'] ) { $valid = $post->post_content === ( $plan['payload']['content'] ?? '' ) ? true : self::error( 'post_content', 'The native page content differs from its translated recipe.' ); }
        else { $valid = Nova_Bridge_Suite_Recipe_Builders::verify_saved( $plan['builder'], $id, $plan['payload'] ); }
        if ( is_wp_error( $valid ) ) { return $valid; }
        if ( ! empty( $plan['payload']['template'] ) && get_page_template_slug( $id ) !== $plan['payload']['template'] ) { return self::error( 'post_template', 'The native page template changed before publication.' ); }
        if ( ! empty( $plan['payload']['meta_all'] ) ) { return Nova_Bridge_Suite_Recipe_ACF::verify_saved( $id, $plan['payload']['meta_all'], $plan['payload']['template'] ?? '' ); }
        return true;
    }
    public static function verify( array $plan, int $id, string $operation, ?array $expected = null ) {
        if ( get_post_meta( $id, '_nova_layout_recipe_operation', true ) !== $operation ) { return self::error( 'post_witness', 'The native item is not witnessed by this recipe operation.' ); }
        $valid = self::content( $plan, $id ); if ( is_wp_error( $valid ) ) { return $valid; }
        $post = get_post( $id ); $native_url = Nova_Bridge_Suite_Mapped_Content_Target::native_url( $plan['resource_id'], 'post', $id ); $url = is_wp_error( $native_url ) ? $native_url : Nova_Bridge_Suite_Mapped_Content_Target::normalize_url( $native_url );
        if ( 'publish' !== $post->post_status || $post->post_name !== $plan['target']['slug'] || (int) $post->post_parent !== $plan['target']['parent'] || is_wp_error( $url ) || $url['identity'] !== $plan['target']['target_url'] ) { return self::error( 'post_url_readback', 'The native item does not have the planned publication URL. Its identity was retained; no duplicate will be created.' ); }
        $now = Nova_Bridge_Suite_Document_Mapping::read( $plan['resource_id'], 'post', $id ); if ( is_wp_error( $now ) ) { return $now; }
        if ( null !== $expected ) { $actual = $now['structure']; if ( isset( $actual['elementor_document'] ) ) { $actual['core']['content'] = ''; $expected['core']['content'] = ''; } if ( self::hash( $actual ) !== self::hash( $expected ) ) { return self::error( 'post_native_readback', 'Publication changed native content outside its approved routing fields.' ); } }
        return $now;
    }
    /** Checkpoint the witnessed draft before changing any publication fields. */
    public static function apply( array $plan, string $operation, array $journal, callable $checkpoint ) {
        $id = (int) ( $journal['native_id'] ?? 0 );
        if ( $id && 'publish' === get_post_status( $id ) ) { $valid = self::verify( $plan, $id, $operation, $journal['published_structure'] ?? null ); return is_wp_error( $valid ) ? $valid : $id; }
        if ( ! $id ) {
            $request = new WP_REST_Request( 'POST' ); $request->set_body( wp_json_encode( [ 'id' => $plan['profile_context']['id'], 'revision' => $plan['profile_context']['revision'], 'content' => $plan['content'], 'title' => $plan['title'], 'operation_id' => $operation ] ) ); $request->set_header( 'content-type', 'application/json' );
            $draft = Nova_Bridge_Suite_Layout_Recipes::draft( $request ); if ( is_wp_error( $draft ) ) { return $draft; } $id = (int) $draft->get_data()['id'];
            $valid = self::content( $plan, $id ); if ( is_wp_error( $valid ) ) { return $valid; }
            $captured = Nova_Bridge_Suite_Document_Mapping::read( $plan['resource_id'], 'post', $id ); if ( is_wp_error( $captured ) ) { return $captured; }
            $journal['native_id'] = $id; $journal['draft_structure'] = $captured['structure'];
            $journal['published_structure'] = $captured['structure']; foreach ( [ 'slug' => $plan['target']['slug'], 'parent' => $plan['target']['parent'], 'status' => 'publish' ] as $key => $value ) { $journal['published_structure']['core'][$key] = $value; }
            $retained = $checkpoint( $journal ); if ( is_wp_error( $retained ) ) { return $retained; }
        }
        $fresh = Nova_Bridge_Suite_Document_Mapping::read( $plan['resource_id'], 'post', $id ); if ( is_wp_error( $fresh ) || get_post_status( $id ) !== 'draft' || ! isset( $journal['draft_structure'], $journal['published_structure'] ) || self::hash( $fresh['structure'] ) !== self::hash( $journal['draft_structure'] ) ) { return self::error( 'post_draft_changed', 'The retained native draft changed. It was not published or duplicated.' ); }
        $unique = wp_unique_post_slug( $plan['target']['slug'], $id, 'publish', $plan['post_type'], $plan['target']['parent'] ); if ( $unique !== $plan['target']['slug'] ) { return self::error( 'post_slug_changed', 'Another item claimed this URL. The retained draft was not published.' ); }
        $published = wp_update_post( wp_slash( [ 'ID' => $id, 'post_name' => $plan['target']['slug'], 'post_parent' => $plan['target']['parent'], 'post_status' => 'publish' ] ), true ); if ( is_wp_error( $published ) ) { return $published; }
        $valid = self::verify( $plan, $id, $operation, $journal['published_structure'] ); return is_wp_error( $valid ) ? $valid : $id;
    }
}
