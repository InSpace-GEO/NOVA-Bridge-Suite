<?php
/**
 * Isolated WP-CLI candidate canary. Current NOVA HTTP responses are simulated locally.
 * Creates one unpublished source; verifies setup only, then removes its own page/options.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'NOVA_MAPPING_STAGING_CANARY' ) ) { throw new RuntimeException( 'Set NOVA_MAPPING_STAGING_CANARY=1 only for an authorized isolated candidate canary.' ); }
$checks = 0; $page_id = 0; $owned_options = []; $failure = null; $cleanup_failures = [];
$old_user = get_current_user_id(); $http_filter = null; $settings_filter = null; $option_filter = null;
$assert = static function ( $condition, string $message ) use ( &$checks ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } ++$checks; WP_CLI::line( 'PASS  ' . $message ); };
$call = static function ( string $method, string $route, array $params ) {
    $request = new WP_REST_Request( $method, '/nova-bridge/v1/mapping/' . $route );
    $request->set_header( 'x-wp-nonce', wp_create_nonce( 'wp_rest' ) );
    if ( 'POST' === $method ) { $request->set_header( 'content-type', 'application/json' ); $request->set_body( wp_json_encode( $params ) ); }
    else { foreach ( $params as $key => $value ) { $request->set_param( $key, $value ); } }
    return rest_do_request( $request );
};
$success = static function ( $response, string $message ) use ( $assert ): array {
    if ( 200 !== $response->get_status() ) { throw new RuntimeException( $message . ': HTTP ' . $response->get_status() . ' ' . wp_json_encode( $response->get_data() ) ); }
    $assert( true, $message ); return $response->get_data();
};
try {
    foreach ( [ 'Nova_Bridge_Suite_Posting_Settings', 'Nova_Bridge_Suite_Posting_Client', 'Nova_Bridge_Suite_Mapping_Drafts', 'Nova_Bridge_Suite_Mapping_Sync', 'Nova_Bridge_Suite_Mapped_Writer' ] as $class ) { $assert( class_exists( $class ), 'Candidate loaded: ' . $class ); }
    $admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] ); $assert( ! empty( $admins ), 'Existing administrator available.' );
    $admin_id = (int) $admins[0]; wp_set_current_user( $admin_id );
    $site_id = wp_generate_uuid4(); $template_id = wp_generate_uuid4(); $origin = 'https://nova-mapping-fixture.invalid';
    $fixture_connection = [ 'base_url' => $origin, 'site_id' => $site_id, 'token' => 'fixture-only-no-production-credential', 'webhook_secret' => 'fixture-only', 'enabled' => true, 'paused' => true, 'actor_user_id' => $admin_id ];
    $settings_filter = static function () use ( &$fixture_connection ) { return $fixture_connection; };
    add_filter( 'pre_option_' . Nova_Bridge_Suite_Posting_Settings::OPTION, $settings_filter, PHP_INT_MAX );
    $assert( Nova_Bridge_Suite_Posting_Settings::connection() === $fixture_connection, 'Fixture connection is memory-only and no constants select a real service.' );
    $remote = [ 'template' => null, 'setting' => null, 'defaults' => [ 'defaults' => [ 'informative' => wp_generate_uuid4() ], 'revision' => 1 ] ]; $calls = [];
    $http_filter = static function ( $pre, $args, $url ) use ( $origin, $site_id, $template_id, &$remote, &$calls, $assert ) {
        if ( 0 !== strpos( $url, $origin . '/' ) ) { return $pre; }
        $prefix = $origin . '/v1/sites/' . $site_id; $assert( 0 === strpos( $url, $prefix . '/' ), 'HTTP request has exact fixture site scope.' );
        $assert( 'Bearer fixture-only-no-production-credential' === ( $args['headers']['Authorization'] ?? '' ) && 0 === $args['redirection'], 'Transport uses site bearer and does not redirect.' );
        $path = substr( $url, strlen( $prefix ) ); $method = $args['method']; $body = isset( $args['body'] ) ? json_decode( $args['body'], true ) : null;
        $calls[] = [ $method, $path ]; $record = null; $code = 200;
        if ( 'GET' === $method && '/templates' === $path ) { $record = $remote['template'] ? [ $remote['template'] ] : []; }
        elseif ( 'POST' === $method && '/templates' === $path ) {
            $assert( true === Nova_Bridge_Suite_Posting_Protocol::validate( json_decode( $args['body'] ), 'PublishingTemplateInput' ), 'Template create matches the actual pinned schema.' );
            $assert( ! isset( $body['authoring_notes'], $body['native_reference'] ) && ! str_contains( $args['body'], 'Exactly one' ) && ! str_contains( $args['body'], '30 characters' ), 'Human instructions and native addresses are not sent as unsupported metadata.' );
            $remote['template'] = $body + [ 'id' => $template_id, 'revision' => 1, 'created_at' => gmdate( 'c' ), 'updated_at' => gmdate( 'c' ), 'retired_at' => null ]; $record = $remote['template']; $code = 201;
        } elseif ( 'GET' === $method && '/templates/' . $template_id === $path ) { $record = $remote['template']; }
        elseif ( '/pages/920/setting' === $path && 'GET' === $method ) { if ( $remote['setting'] ) { $record = $remote['setting']; } else { $code = 404; $record = [ 'error' => [ 'code' => 'not_found', 'message' => 'fixture' ] ]; } }
        elseif ( '/pages/920/setting' === $path && 'PUT' === $method ) {
            $expected = '"' . ( $remote['setting']['revision'] ?? 0 ) . '"';
            if ( $expected !== ( $args['headers']['If-Match'] ?? '' ) ) { $code = 412; $record = [ 'error' => [ 'code' => 'stale_revision', 'message' => 'fixture' ] ]; }
            else {
                $assert( $body === [ 'template_id' => $template_id ], 'Page setting sends only template_id; URL identity is not a native page registration.' );
                $remote['setting'] = [ 'template_id' => $template_id, 'revision' => ( $remote['setting']['revision'] ?? 0 ) + 1 ]; $record = $remote['setting'];
            }
        } elseif ( '/template-defaults' === $path && 'GET' === $method ) { $record = $remote['defaults']; }
        elseif ( '/template-defaults' === $path && 'PUT' === $method ) {
            $assert( ( $args['headers']['If-Match'] ?? '' ) === '"' . $remote['defaults']['revision'] . '"', 'Defaults replacement is conditional on reviewed revision.' );
            $assert( $body['defaults']['informative'] === $remote['defaults']['defaults']['informative'], 'Changing service default preserves the other source-type selection.' );
            $remote['defaults'] = [ 'defaults' => $body['defaults'], 'revision' => $remote['defaults']['revision'] + 1 ]; $record = $remote['defaults'];
        } else { throw new RuntimeException( 'Unexpected fixture HTTP: ' . $method . ' ' . $path ); }
        return [ 'headers' => isset( $record['revision'] ) ? [ 'etag' => '"' . $record['revision'] . '"' ] : [], 'body' => wp_json_encode( $record ), 'response' => [ 'code' => $code, 'message' => 'Simulated NOVA' ], 'cookies' => [], 'filename' => null ];
    };
    add_filter( 'pre_http_request', $http_filter, PHP_INT_MAX, 3 );
    $fixture_connection['enabled'] = false;
    $offline = $success( $call( 'GET', 'catalog', [ 'mode' => 'nova' ] ), 'Current source catalog works without a connection.' );
    $assert( ! $offline['api_available'] && count( $offline['templates'][0]['fields'] ) === 12 && ! $calls, 'Offline preparation uses the pinned source enum without any HTTP request.' ); $fixture_connection['enabled'] = true;
    $page_id = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'NOVA current setup canary ' . wp_generate_uuid4(), 'post_content' => 'Original fixture content.', 'post_author' => $admin_id ], true );
    $assert( ! is_wp_error( $page_id ) && $page_id > 0, 'Temporary unpublished source page created.' ); $page_id = (int) $page_id;
    $before = get_post( $page_id )->to_array(); $before_meta = get_post_meta( $page_id );
    $state_key = 'nova_mapping_sync_' . hash( 'sha256', $site_id . ':post:' . $page_id );
    $prefixes = [ 'nova_mapping_draft_' . hash( 'sha256', 'post:' . $page_id ), $state_key, 'nova_publishing_profile_' . hash( 'sha256', $site_id . ':' . $template_id . ':1' ) ];
    foreach ( $prefixes as $name ) { $missing = new stdClass(); $assert( get_option( $name, $missing ) === $missing, 'Own option identity is unused.' ); $owned_options[] = $name; }
    $owned_options[] = $state_key . '_lock';
    $option_filter = static function ( $name ) use ( $prefixes, &$owned_options ) { foreach ( $prefixes as $prefix ) { if ( $name === $prefix || 0 === strpos( $name, $prefix . '_' ) ) { $owned_options[] = $name; break; } } };
    add_action( 'added_option', $option_filter, PHP_INT_MAX );
    $entity = Nova_Bridge_Suite_Strategy::entity( 'post', $page_id ); $signature = Nova_Bridge_Suite_Strategy::fingerprint( $entity )['signature'];
    $catalog = $success( $call( 'GET', 'catalog', [ 'mode' => 'nova' ] ), 'REST catalog reads current /templates and the bundled source enum.' );
    $assert( $catalog['api_available'] && 'nova-delivery-fields-v1' === $catalog['templates'][0]['id'], 'Catalog distinguishes wire access from local source definitions.' );
    $input = [ 'expected_revision' => '', 'reference_type' => 'post', 'reference_id' => $page_id, 'signature' => $signature, 'catalog_mode' => 'nova', 'template' => [ 'id' => 'nova-delivery-fields-v1', 'revision' => '1' ], 'profile_page_type' => 'service', 'label' => 'Temporary current setup canary', 'guidance_mode' => 'set', 'guidance' => 'Exactly one relevant label.', 'fields' => [ '/title' => [ 'mode' => 'mapped', 'source_path' => 'title', 'required' => true, 'instructions' => 'At most 30 characters.' ] ], 'skipped_sources' => [ [ 'source_path' => 'meta_description', 'reason' => 'No metadata target.' ] ], 'repeat_slots' => [], 'routing' => [ 'operation' => 'update', 'locale' => '', 'publication' => 'preserve' ] ];
    $saved = $success( $call( 'POST', 'draft', $input ), 'Live draft save retains human instructions and binds native inventory.' );
    $params = [ 'reference_type' => 'post', 'reference_id' => $page_id, 'signature' => $signature, 'expected_revision' => $saved['draft']['revision'] ];
    $synced = $success( $call( 'POST', 'sync', $params ), 'Current publishing-template POST succeeds through actual HTTP client and REST route.' );
    $assert( 'synced_draft' === $synced['state']['status'] && 'unsupported_local_only' === $synced['state']['instructions_sync'], 'Synchronized setup explicitly says instructions remain local.' );
    $active = $success( $call( 'POST', 'activate', $params ), 'Actual native writer probe approves the exact scalar profile locally.' );
    $assert( 'active' === $active['state']['status'], 'Local approval succeeds without retired seal/pin endpoints.' );
    $snapshot = [ 'site_id' => $site_id, 'url_id' => '920', 'url' => get_permalink( $page_id ), 'configuration' => array_intersect_key( $remote['template'], array_flip( [ 'id', 'name', 'page_type', 'definition', 'mapping', 'revision' ] ) ) ];
    $count_before = count( $calls ); $exact = Nova_Bridge_Suite_Mapping_Sync::configuration_for_snapshot( $snapshot, $site_id );
    $assert( ! is_wp_error( $exact ) && $exact['local']['guidance'] === $input['guidance'] && $exact['local']['fields']['/title']['instructions'] === $input['fields']['/title']['instructions'], 'Immutable local profile retains all human rules alongside the exact remote revision.' );
    $policy = Nova_Bridge_Suite_Mapping_Sync::local_policy( $snapshot, $exact );
    $assert( ! is_wp_error( $policy ) && $policy['reference']['reference_id'] === $page_id && $count_before === count( $calls ), 'Delivery profile and native routing validate locally without remote policy reads.' );
    $review = $success( $call( 'GET', 'url-binding', $params + [ 'url_id' => '920' ] ), 'Administrator reviews absent page setting.' ); $assert( 0 === $review['server_revision'], 'Missing page setting requires revision zero.' );
    $bound = $success( $call( 'POST', 'url-binding', $params + [ 'url_id' => '920', 'expected_server_revision' => 0 ] ), 'Optional page selection uses template_id and If-Match.' );
    $assert( 1 === $bound['server_revision'], 'Page setting returns exact new server revision.' );
    $conflict = $call( 'POST', 'url-binding', $params + [ 'url_id' => '920', 'expected_server_revision' => 0 ] ); $assert( 412 === $conflict->get_status(), 'Stale page setting is rejected without blind overwrite.' );
    $defaults = $success( $call( 'GET', 'template-default', $params + [ 'source_page_type' => 'service' ] ), 'Administrator reviews current page-type defaults.' );
    $default_saved = $success( $call( 'POST', 'template-default', $params + [ 'source_page_type' => 'service', 'expected_server_revision' => $defaults['server_revision'] ] ), 'Optional source-type default preserves unrelated settings.' ); $assert( 2 === $default_saved['server_revision'], 'Defaults revision increments conditionally.' );
    $no_nonce = new WP_REST_Request( 'POST', '/nova-bridge/v1/mapping/url-binding' ); $no_nonce->set_header( 'content-type', 'application/json' ); $no_nonce->set_body( wp_json_encode( $params + [ 'url_id' => '920', 'expected_server_revision' => 1 ] ) );
    $assert( 403 === rest_do_request( $no_nonce )->get_status(), 'Missing REST nonce cannot change remote selection.' );
    $status = $success( $call( 'GET', 'sync-state', $params ), 'Setup status names the retained remote revision.' ); $assert( $status['state']['template']['id'] === $template_id, 'Status reports current UUID configuration identity.' );
    clean_post_cache( $page_id ); $assert( get_post( $page_id )->to_array() === $before && get_post_meta( $page_id ) === $before_meta, 'Setup and capability probes do not mutate native source fields or metadata.' );
    wp_set_current_user( 0 ); $assert( 401 === $call( 'POST', 'sync', $params )->get_status(), 'Anonymous synchronization is denied.' );
} catch ( Throwable $error ) { $failure = $error->getMessage(); }
finally {
    if ( $http_filter ) { remove_filter( 'pre_http_request', $http_filter, PHP_INT_MAX ); }
    if ( $settings_filter ) { remove_filter( 'pre_option_' . Nova_Bridge_Suite_Posting_Settings::OPTION, $settings_filter, PHP_INT_MAX ); }
    if ( $option_filter ) { remove_action( 'added_option', $option_filter, PHP_INT_MAX ); }
    wp_set_current_user( $old_user );
    foreach ( array_unique( $owned_options ) as $name ) { delete_option( $name ); $missing = new stdClass(); if ( get_option( $name, $missing ) !== $missing ) { $cleanup_failures[] = 'Own option not removed: ' . $name; } }
    if ( is_int( $page_id ) && $page_id > 0 ) { wp_delete_post( $page_id, true ); if ( get_post( $page_id ) ) { $cleanup_failures[] = 'Fixture page not removed.'; } }
}
if ( $cleanup_failures ) { $failure = trim( (string) $failure . ' Cleanup: ' . implode( ' ', $cleanup_failures ) ); }
if ( $failure ) { WP_CLI::error( $failure ); }
WP_CLI::success( 'mapping-sync-staging: ' . $checks . ' current-contract checks; simulated NOVA; own fixture cleaned.' );
