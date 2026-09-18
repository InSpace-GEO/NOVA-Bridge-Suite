<?php
/** Test fixture only. Never a production configuration or a remote request. */
function nova_writer_fixture( int $post_id = 7, string $signature = 'fixture-layout', string $operation = 'update', string $suffix = 'unit' ): array {
    $local = [ 'reference_type' => 'post', 'reference_id' => $post_id, 'signature' => $signature, 'revision' => 'local-fixture-' . $suffix, 'routing' => [ 'operation' => $operation, 'publication' => 'draft', 'locale' => 'en' ], 'fields' => [ '/title' => [ 'mode' => 'mapped', 'source_path' => 'heading' ], '/slug' => [ 'mode' => 'mapped', 'source_path' => 'slug' ], '/excerpt' => [ 'mode' => 'protected', 'protected_slot' => 'retained_note' ], '/content' => [ 'mode' => 'leave_empty' ] ], 'target_descriptors' => [], 'repeat_slots' => [], 'skipped_sources' => [] ];
    $inventory = [];
    foreach ( $local['fields'] as $path => $field ) {
        $local['target_descriptors'][ $path ] = [ 'path' => $path, 'reference_type' => 'post', 'reference_id' => $post_id, 'signature' => $signature ];
        $inventory[ $path ] = [ 'path' => $path, 'writable' => true, 'type' => 'text' ];
    }
    $template = [ 'template_id' => '101', 'template_version' => '102', 'state' => 'sealed', 'fields' => [ [ 'field_key' => 'heading', 'field_type' => 'heading', 'required' => true ], [ 'field_key' => 'slug', 'field_type' => 'meta', 'semantic' => 'slug', 'required' => true ] ], 'groups' => [], 'protected_identities' => [ 'retained_note' ] ];
    $mapping = [ 'mapping_id' => '103', 'mapping_revision_id' => '104', 'template_id' => '101', 'template_version' => '102', 'state' => 'sealed', 'cpt' => 'page', 'bindings' => [] ];
    foreach ( [ 'heading' => '/title', 'slug' => '/slug' ] as $source => $path ) {
        $mapping['bindings'][] = [ 'source_path' => $source, 'source_type' => 'heading' === $source ? 'heading' : 'meta', 'target_descriptor' => [ 'format' => 'nova_bridge_target_v1', 'target' => $local['target_descriptors'][ $path ] ], 'expected_identity' => [ 'reference_type' => 'post', 'reference_id' => $post_id, 'signature' => $signature, 'local_revision' => $local['revision'] ] ];
    }
    $pin = [ 'pin_id' => '105', 'digest' => str_repeat( 'a', 64 ), 'site_id' => '00000000-0000-4000-8000-000000000001', 'template_id' => '101', 'template_version' => '102', 'mapping_id' => '103', 'mapping_revision_id' => '104' ];
    $configuration = [ 'pin' => $pin, 'pin_id' => $pin['pin_id'], 'digest' => $pin['digest'], 'site_id' => $pin['site_id'], 'template' => $template, 'mapping' => $mapping, 'local' => $local ];
    nova_writer_fixture_policy( $configuration );
    $content = [ 'content_id' => 'writer-fixture-' . $suffix, 'version' => 1, 'pin_id' => '105', 'digest' => $pin['digest'], 'template_id' => '101', 'template_version' => '102', 'locale' => 'en', 'fields' => [ 'heading' => 'Generated fixture heading', 'slug' => 'nova-writer-fixture-' . $suffix ] ];
    $context = [ 'site_id' => $pin['site_id'], 'content_id' => $content['content_id'], 'version' => 1, 'operation_id' => 'writer-op-' . $suffix, 'actor_user_id' => 1, 'target_post_id' => $post_id, 'operation' => $operation, 'layout_signature' => $signature, 'provider_tuple' => [ 'wordpress' => $GLOBALS['wp_version'] ?? '', 'plugin' => defined( 'NOVA_BRIDGE_SUITE_VERSION' ) ? NOVA_BRIDGE_SUITE_VERSION : '', 'acf' => function_exists( 'acf_get_setting' ) ? (string) acf_get_setting( 'version' ) : '', 'elementor' => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '' ] ];
    $snapshot = [ 'post' => [ 'ID' => (string) $post_id, 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Original title', 'post_content' => 'Preserve on update, blank on clone', 'post_excerpt' => 'Protected source note', 'post_name' => 'original-fixture' ], 'meta' => [], 'terms' => [] ];
    return [ 'content' => $content, 'configuration' => $configuration, 'context' => $context, 'snapshot' => $snapshot, 'inventory' => $inventory ];
}
function nova_writer_fixture_policy( array &$configuration ): void {
    $json = Nova_Bridge_Suite_Writing_Adapter::canonical_json( Nova_Bridge_Suite_Writing_Adapter::policy( $configuration['local'] ) );
    foreach ( $configuration['mapping']['bindings'] as $index => &$binding ) { $binding['expected_identity']['plugin_policy_digest'] = hash( 'sha256', $json ); if ( 0 === $index ) { $binding['expected_identity']['plugin_policy_json'] = $json; } }
    unset( $binding );
}
