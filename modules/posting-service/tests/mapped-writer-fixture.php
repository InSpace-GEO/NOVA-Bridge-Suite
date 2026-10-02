<?php
/** Test fixture only. Uses the current delivery schema; never a production configuration. */
function nova_writer_fixture_uuid( string $seed ): string { $hash = hash( 'sha256', $seed ); return substr( $hash, 0, 8 ) . '-' . substr( $hash, 8, 4 ) . '-4' . substr( $hash, 13, 3 ) . '-8' . substr( $hash, 17, 3 ) . '-' . substr( $hash, 20, 12 ); }
function nova_writer_fixture_refresh( array &$fixture ): void {
    $configuration = &$fixture['configuration'];
    $input = Nova_Bridge_Suite_Writing_Adapter::template_input( $configuration['local'] );
    if ( is_wp_error( $input ) ) { throw new RuntimeException( $input->get_error_message() ); }
    $configuration['template'] = array_merge( [ 'id' => $configuration['template']['id'] ?? nova_writer_fixture_uuid( 'template' ), 'revision' => $configuration['template']['revision'] ?? 1 ], array_intersect_key( $input, array_flip( [ 'name', 'page_type', 'definition', 'mapping' ] ) ) );
    $configuration['profile_digest'] = hash( 'sha256', Nova_Bridge_Suite_Writing_Adapter::canonical_json( $configuration['local'] ) );
    $fixture['content']['configuration'] = $configuration['template'];
    $fixture['context']['snapshot_sha256'] = hash( 'sha256', wp_json_encode( $fixture['content'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
    $fixture['context']['version'] = $fixture['content']['version_number'];
    foreach ( [ 'url_id', 'content_item_id', 'content_item_version_id' ] as $key ) { $fixture['context'][ $key ] = $fixture['content'][ $key ]; }
    $fixture['context']['delivery_id'] = $fixture['content']['id'];
}
function nova_writer_fixture( int $post_id = 7, string $signature = 'fixture-layout', string $operation = 'update', string $suffix = 'unit' ): array {
    $site = '00000000-0000-4000-8000-000000000001';
    $local = [ 'catalog_mode' => 'nova', 'template' => [ 'id' => 'nova-delivery-fields-v1', 'revision' => '1' ], 'profile_page_type' => 'page', 'label' => 'Writer fixture ' . $suffix, 'reference_type' => 'post', 'reference_id' => $post_id, 'signature' => $signature, 'revision' => 'local-fixture-' . $suffix, 'routing' => [ 'operation' => $operation, 'publication' => 'draft', 'locale' => 'en', 'post_type' => 'page' ], 'guidance' => 'Use one relevant heading.', 'guidance_mode' => 'set', 'fields' => [ '/title' => [ 'mode' => 'mapped', 'source_path' => 'h1', 'required' => true ], '/slug' => [ 'mode' => 'mapped', 'source_path' => 'url', 'required' => true ], '/excerpt' => [ 'mode' => 'protected', 'protected_slot' => 'retained_note' ], '/content' => [ 'mode' => 'leave_empty' ] ], 'target_descriptors' => [], 'repeat_slots' => [], 'skipped_sources' => [] ];
    $inventory = [];
    foreach ( $local['fields'] as $path => $field ) {
        $local['target_descriptors'][ $path ] = [ 'path' => $path, 'reference_type' => 'post', 'reference_id' => $post_id, 'signature' => $signature ];
        $inventory[ $path ] = [ 'path' => $path, 'writable' => true, 'type' => 'text' ];
    }
    $configuration = [ 'site_id' => $site, 'template' => [ 'id' => nova_writer_fixture_uuid( 'template-' . $suffix ), 'revision' => 1 ], 'local' => $local ];
    $content = [ 'id' => nova_writer_fixture_uuid( 'delivery-' . $suffix ), 'site_id' => $site, 'client_id' => '1', 'domain_id' => '2', 'url_id' => (string) hexdec( substr( hash( 'sha256', 'url-' . $suffix ), 0, 12 ) ), 'url' => home_url( '/nova-writer-fixture-' . $suffix . '/' ), 'page_type_frontend' => 'page', 'language' => 'en', 'content_item_id' => (string) hexdec( substr( hash( 'sha256', $suffix ), 0, 12 ) ), 'content_item_version_id' => '91', 'version_number' => 1, 'source_sha256' => str_repeat( 'a', 64 ), 'configuration' => [], 'content' => [ 'title' => null, 'meta_description' => null, 'h1' => 'Generated fixture heading', 'content' => null, 'top_content' => null, 'bottom_content' => null, 'image_url' => null, 'image_urls' => null, 'image_alt' => null ] ];
    $context = [ 'site_id' => $site, 'version' => 1, 'operation_id' => nova_writer_fixture_uuid( 'writer-op-' . $suffix ), 'attempt_id' => nova_writer_fixture_uuid( 'attempt-' . $suffix ), 'actor_user_id' => 1, 'target_post_id' => $post_id, 'operation' => $operation, 'layout_signature' => $signature, 'provider_tuple' => [ 'wordpress' => $GLOBALS['wp_version'] ?? '', 'plugin' => defined( 'NOVA_BRIDGE_SUITE_VERSION' ) ? NOVA_BRIDGE_SUITE_VERSION : '', 'acf' => function_exists( 'acf_get_setting' ) ? (string) acf_get_setting( 'version' ) : '', 'elementor' => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '' ] ];
    $snapshot = [ 'post' => [ 'ID' => (string) $post_id, 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Original title', 'post_content' => 'Preserve on update, blank on clone', 'post_excerpt' => 'Protected source note', 'post_name' => 'original-fixture' ], 'meta' => [], 'terms' => [] ];
    $fixture = [ 'content' => $content, 'configuration' => $configuration, 'context' => $context, 'snapshot' => $snapshot, 'inventory' => $inventory ];
    nova_writer_fixture_refresh( $fixture ); return $fixture;
}
