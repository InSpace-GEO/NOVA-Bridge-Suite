<?php
/** Synthetic current-contract identities only; no network access or production configuration. */
function nova_contract_fixture( array $native = [] ): array {
    $site = $native['configuration']['site_id'] ?? '00000000-0000-4000-8000-000000000001';
    $template = [ 'id' => '00000000-0000-4000-8000-000000000101', 'name' => 'Service fixture', 'page_type' => 'service-page',
        'definition' => [ 'version' => 1, 'fields' => [ [ 'id' => 'heading', 'label' => 'Heading', 'kind' => 'text', 'required' => true ] ] ],
        'mapping' => [ 'version' => 1, 'fields' => [ [ 'field_id' => 'heading', 'source_field' => 'title' ] ] ], 'revision' => 1 ];
    $local = [ 'reference_type' => 'post', 'reference_id' => 77, 'signature' => 'fixture-layout', 'revision' => 'fixture-local-1',
        'routing' => [ 'operation' => 'update', 'publication' => 'publish', 'locale' => 'en' ],
        'fields' => [ '/title' => [ 'mode' => 'mapped', 'source_path' => 'heading' ], '/excerpt' => [ 'mode' => 'protected' ], '/content' => [ 'mode' => 'leave_empty' ] ],
        'target_descriptors' => [], 'repeat_slots' => [], 'skipped_sources' => [] ];
    foreach ( $local['fields'] as $path => $field ) { $local['target_descriptors'][ $path ] = [ 'path' => $path, 'reference_type' => 'post', 'reference_id' => 77, 'signature' => 'fixture-layout' ]; }
    $configuration = $native['configuration'] ?? [ 'site_id' => $site, 'template' => $template, 'local' => $local, 'profile_digest' => hash( 'sha256', json_encode( $local ) ) ];
    $content = $native['content'] ?? [];
    $snapshot = $content && isset( $content['content_item_id'] ) ? $content : [
        'id' => '00000000-0000-4000-8000-000000000020', 'site_id' => $site, 'client_id' => '5', 'domain_id' => '6', 'url_id' => '8',
        'url' => 'https://native.fixture.invalid/service/', 'page_type_frontend' => 'service-page', 'language' => 'en',
        'content_item_id' => '123', 'content_item_version_id' => '202', 'version_number' => 2,
        'source_sha256' => str_repeat( 'b', 64 ), 'attempt_id' => '00000000-0000-4000-8000-000000000012',
        'configuration' => $configuration['template'],
        'content' => [ 'title' => 'Contracted fixture heading', 'meta_description' => null, 'h1' => 'Fixture heading', 'content' => '<p>Generated content.</p>', 'top_content' => null, 'bottom_content' => null, 'image_url' => null, 'image_urls' => [], 'image_alt' => null ],
    ];
    if ( is_array( $snapshot['configuration'] ) ) { $snapshot['configuration'] = array_intersect_key( $snapshot['configuration'], array_flip( [ 'id', 'name', 'page_type', 'definition', 'mapping', 'revision' ] ) ); }
    $raw = json_encode( $snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
    $etag = '"' . hash( 'sha256', $raw ) . '"';
    $attempt = $native['context']['attempt_id'] ?? $snapshot['attempt_id'] ?? '00000000-0000-4000-8000-000000000012';
    $item = [ 'id' => $snapshot['id'], 'content_item_version_id' => $snapshot['content_item_version_id'], 'source_sha256' => $snapshot['source_sha256'], 'created_at' => '2026-10-02T12:00:00.123Z' ];
    $operation = $native['context']['operation_id'] ?? '00000000-0000-4000-8000-000000000013';
    $job = [ 'id' => 1, 'site_id' => $site, 'content_id' => $snapshot['id'], 'version' => 1, 'state' => 'running', 'phase' => 'accepted', 'target_id' => 0, 'operation_id' => $operation, 'attempts' => 1, 'next_attempt' => 0, 'last_error' => '', 'lease_token' => 'fixture-owned', 'lease_until' => time() + 300,
        'payload' => [ 'protocol' => 'nova.delivery-snapshot/v1', 'business_result_id' => $operation, 'discovery' => $item, 'discovery_checkpoint' => '1' ] ];
    $connection = [ 'base_url' => 'https://posting.fixture.invalid', 'site_id' => $site, 'token' => 'synthetic-test-token-only', 'enabled' => true, 'paused' => false, 'actor_user_id' => 1, 'webhook_secret' => 'synthetic-signing-secret' ];
    $context = array_merge( $native['context'] ?? [], [ 'site_id' => $site, 'operation_id' => $operation, 'attempt_id' => $attempt, 'delivery_etag' => $etag, 'snapshot_sha256' => hash( 'sha256', $raw ) ] );
    return [ 'snapshot' => $snapshot, 'configuration' => $configuration, 'raw' => $raw, 'etag' => $etag, 'attempt_id' => $attempt, 'item' => $item, 'job' => $job, 'connection' => $connection, 'context' => $context ];
}

function nova_contract_fixture_event( array $fixture, string $kind = 'received_complete' ): array {
    $event = [ 'event_id' => '00000000-0000-4000-8000-000000000030', 'kind' => $kind, 'site_id' => $fixture['snapshot']['site_id'], 'delivery_id' => $fixture['snapshot']['id'],
        'content_item_version_id' => $fixture['snapshot']['content_item_version_id'], 'version_number' => $fixture['snapshot']['version_number'],
        'source_sha256' => $fixture['snapshot']['source_sha256'], 'attempt_id' => $fixture['attempt_id'], 'delivery_etag' => $fixture['etag'] ];
    if ( 'publication_succeeded' === $kind ) { $event['published_at'] = '2026-10-02T12:00:00.123Z'; $event['publication_ref'] = 'https://native.fixture.invalid/service/'; }
    if ( in_array( $kind, [ 'pull_failed', 'publication_failed' ], true ) ) { $event['reason'] = 'Fixture failure'; }
    return $event;
}

function nova_contract_fixture_event_accept( string $bytes ): array {
    $event = json_decode( $bytes, true, 64, JSON_THROW_ON_ERROR );
    return array_merge( array_intersect_key( $event, array_flip( [ 'event_id', 'kind', 'site_id', 'delivery_id', 'attempt_id' ] ) ), [ 'accepted_at' => '2026-10-02T12:00:00.123Z', 'status' => 'pending' ] );
}
