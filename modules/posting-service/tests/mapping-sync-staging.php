<?php
/**
 * WP-CLI canary with real WordPress REST/options/inventory and a fixture-only HTTP adapter.
 * Load candidate plugin first. Creates one unpublished page; finally removes only owned rows.
 * Never connects to NOVA or writes generated content. Does not persist connection settings.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit( 1 ); }
$checks = 0; $page_id = 0; $owned_options = []; $failure = null; $cleanup_failures = [];
$old_user = get_current_user_id(); $http_filter = null; $settings_filter = null;
$assert = static function ( $condition, string $message ) use ( &$checks ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } ++$checks; WP_CLI::line( 'PASS  ' . $message ); };
$call = static function ( string $method, string $route, array $params ) {
    $request = new WP_REST_Request( $method, '/nova-bridge/v1/mapping/' . $route );
    if ( 'POST' === $method ) { $request->set_header( 'content-type', 'application/json' ); $request->set_body( wp_json_encode( $params ) ); }
    else { foreach ( $params as $key => $value ) { $request->set_param( $key, $value ); } }
    return rest_do_request( $request );
};
$success = static function ( $response, string $message ) use ( $assert ): array {
    if ( 200 !== $response->get_status() ) { throw new RuntimeException( $message . ': HTTP ' . $response->get_status() . ' ' . wp_json_encode( $response->get_data() ) ); }
    $assert( true, $message ); return $response->get_data();
};
try {
    foreach ( [ 'Nova_Bridge_Suite_Posting_Settings', 'Nova_Bridge_Suite_Posting_Client', 'Nova_Bridge_Suite_Mapping_Drafts', 'Nova_Bridge_Suite_Mapping_Sync', 'Nova_Bridge_Suite_Mapped_Writer' ] as $class ) { $assert( class_exists( $class ), 'Candidate component loaded: ' . $class ); }
    $admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] ); $assert( ! empty( $admins ), 'An existing administrator is available.' );
    $admin_id = (int) $admins[0]; wp_set_current_user( $admin_id );
    $site_id = wp_generate_uuid4(); $origin = 'https://nova-mapping-fixture.invalid';
    $fixture_connection = [ 'base_url' => $origin, 'site_id' => $site_id, 'token' => 'fixture-only-no-production-credential', 'webhook_secret' => 'fixture-only', 'enabled' => true, 'paused' => false, 'actor_user_id' => $admin_id ];
    $settings_filter = static function () use ( $fixture_connection ) { return $fixture_connection; };
    add_filter( 'pre_option_' . Nova_Bridge_Suite_Posting_Settings::OPTION, $settings_filter, PHP_INT_MAX );
    $effective = Nova_Bridge_Suite_Posting_Settings::connection();
    $assert( $effective === $fixture_connection, 'In-memory fixture connection is effective; no constant points to another service.' );

    $field = [ 'field_key' => 'page_heading', 'label' => 'Page heading', 'notes' => '', 'field_type' => 'plain_text', 'required' => true, 'minLength' => 1, 'maxLength' => 150 ];
    $summary = array_merge( $field, [ 'field_key' => 'summary', 'label' => 'Summary' ] );
    $source = [ 'template_id' => '901', 'template_version' => '902', 'family' => 'service', 'owner_scope' => 'stock', 'site_id' => null, 'client_id' => null, 'domain_id' => null, 'source_template_id' => null, 'source_template_version' => null, 'revision_no' => '1', 'state' => 'sealed', 'etag' => 'fixture-stock', 'digest' => null, 'json_schema' => null, 'definitions_json' => null, 'missing_requirements' => [], 'fields' => [ $field, $summary ], 'groups' => [], 'layout' => [ [ 'kind' => 'field', 'key' => 'page_heading' ], [ 'kind' => 'field', 'key' => 'summary' ] ], 'examples' => null, 'protected_identities' => [], 'authoring_notes' => '' ];
    $remote = []; $calls = [];
    $http_filter = static function ( $pre, $args, $url ) use ( $origin, $site_id, $source, &$remote, &$calls, $assert ) {
        if ( 0 !== strpos( $url, $origin . '/' ) ) { return $pre; }
        $prefix = $origin . '/v1/sites/' . $site_id . '/writing';
        $assert( 0 === strpos( $url, $prefix . '/' ), 'Fixture API request retains its exact site scope.' );
        $assert( 'Bearer fixture-only-no-production-credential' === ( $args['headers']['Authorization'] ?? '' ) && 0 === $args['redirection'], 'Fixture API transport owns authentication and disables redirects.' );
        $path = substr( $url, strlen( $prefix ) ); $method = $args['method']; $body = isset( $args['body'] ) ? json_decode( $args['body'], true ) : null;
        $calls[] = [ $method, $path ]; $record = null;
        if ( 'GET' === $method && '/stock-templates' === $path ) { $record = [ $source ]; }
        elseif ( 'POST' === $method && '/template-clones' === $path ) {
            $assert( '901' === $body['source_template_id'] && '902' === $body['source_template_version'] && ! empty( $args['headers']['Idempotency-Key'] ), 'Clone pins the real catalog identity and operation key.' );
            $remote['template'] = array_merge( $source, [ 'template_id' => '903', 'template_version' => '904', 'source_template_id' => '901', 'source_template_version' => '902', 'owner_scope' => 'site', 'site_id' => $site_id, 'state' => 'draft', 'etag' => 'fixture-clone', 'authoring_notes' => $body['authoring_notes'] ] ); $record = $remote['template'];
        } elseif ( 'PUT' === $method && '/templates/903' === $path ) {
            $assert( '904' === $body['expected_revision_id'] && 'fixture-clone' === $args['headers']['If-Match'], 'Template replacement is conditional on exact revision and ETag.' );
            unset( $body['expected_revision_id'] ); $remote['template'] = array_merge( $remote['template'], $body, [ 'etag' => 'fixture-template-edited' ] ); $record = $remote['template'];
        } elseif ( 'POST' === $method && '/mappings' === $path ) {
            $remote['mapping'] = array_merge( $body, [ 'mapping_id' => '905', 'mapping_revision_id' => '906', 'revision_no' => '1', 'state' => 'draft', 'etag' => 'fixture-mapping', 'digest' => null, 'missing_requirements' => [] ] ); $record = $remote['mapping'];
        } elseif ( 'POST' === $method && '/assignments' === $path ) {
            $remote['assignment'] = array_merge( $body, [ 'assignment_id' => '907', 'assignment_revision_id' => '908', 'revision_no' => '1', 'state' => 'draft', 'etag' => 'fixture-assignment', 'missing_requirements' => [], 'status' => 'draft' ] ); $record = $remote['assignment'];
        } elseif ( 'POST' === $method && '/evidence' === $path ) {
            $assert( '905' === $body['mapping_id'] && '906' === $body['mapping_revision_id'] && [ 'page_heading' ] === $body['coverage'] && 'InnoDB' === $body['db_engine'], 'Actual writer supplies exact scalar capability evidence.' );
            $record = [ 'evidence_id' => '909', 'mapping_digest' => hash( 'sha256', wp_json_encode( $remote['mapping'] ) ), 'capability_digest' => hash( 'sha256', wp_json_encode( $body ) ) ];
        } elseif ( 'POST' === $method && '/pins/seal' === $path ) {
            foreach ( [ 'template', 'mapping', 'assignment' ] as $kind ) { $assert( $remote[ $kind ]['etag'] === $body[ $kind . '_etag' ], 'Seal uses exact ' . $kind . ' ETag.' ); $remote[ $kind ]['state'] = 'sealed'; $remote[ $kind ]['etag'] .= '-sealed'; }
            $remote['pin'] = [ 'pin_id' => '910', 'site_id' => $site_id, 'assignment_id' => '907', 'assignment_revision_id' => '908', 'template_id' => '903', 'template_version' => '904', 'mapping_id' => '905', 'mapping_revision_id' => '906', 'family' => 'service', 'contract' => [ 'fields' => $remote['template']['fields'], 'authoring_notes' => $remote['template']['authoring_notes'] ], 'digest' => hash( 'sha256', wp_json_encode( $remote['template'] ) ) ]; $record = $remote['pin'];
        } elseif ( 'GET' === $method && '/templates/903?template_version=904' === $path ) { $record = $remote['template']; }
        elseif ( 'GET' === $method && '/mappings/905?mapping_revision_id=906' === $path ) { $record = $remote['mapping']; }
        elseif ( 'GET' === $method && '/assignments/907?assignment_revision_id=908' === $path ) { $record = $remote['assignment']; }
        elseif ( 'GET' === $method && '/pins/910' === $path ) { $record = $remote['pin']; }
        elseif ( 'GET' === $method && '/assignments/907/lifecycle' === $path ) { $record = [ 'assignment_id' => '907', 'etag' => 'fixture-lifecycle', 'status' => 'draft', 'active_pin_id' => null ]; }
        elseif ( 'POST' === $method && '/assignments/907/activate' === $path ) {
            $assert( 'fixture-lifecycle' === $args['headers']['If-Match'] && ! empty( $args['headers']['Idempotency-Key'] ), 'Activation checks lifecycle ETag and stable operation identity.' );
            $record = [ 'assignment_id' => '907', 'assignment_revision_id' => '908', 'pin_id' => '910', 'active_pin_id' => '910', 'digest' => $remote['pin']['digest'], 'status' => 'active', 'etag' => 'fixture-lifecycle-active' ];
        } else { throw new RuntimeException( 'Unexpected fixture API operation: ' . $method . ' ' . $path ); }
        return [ 'headers' => isset( $record['etag'] ) ? [ 'etag' => $record['etag'] ] : [], 'body' => wp_json_encode( $record ), 'response' => [ 'code' => 200, 'message' => 'Fixture' ], 'cookies' => [], 'filename' => null ];
    };
    add_filter( 'pre_http_request', $http_filter, PHP_INT_MAX, 3 );
    $page_id = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'NOVA synchronization canary ' . wp_generate_uuid4(), 'post_content' => 'Original fixture content.', 'post_author' => $admin_id ], true );
    $assert( ! is_wp_error( $page_id ) && $page_id > 0, 'Temporary unpublished source page created.' ); $page_id = (int) $page_id;
    $before = get_post( $page_id )->to_array(); $before_meta = get_post_meta( $page_id );
    $candidate_options = [ 'nova_mapping_draft_' . hash( 'sha256', 'post:' . $page_id ), 'nova_mapping_sync_' . hash( 'sha256', $site_id . ':post:' . $page_id ), 'nova_mapping_sync_' . hash( 'sha256', $site_id . ':post:' . $page_id ) . '_lock', 'nova_mapping_pin_' . hash( 'sha256', $site_id . ':910' ) ];
    foreach ( $candidate_options as $name ) { $missing = new stdClass(); $assert( get_option( $name, $missing ) === $missing, 'Fixture option identity is unused.' ); $owned_options[] = $name; }
    $entity = Nova_Bridge_Suite_Strategy::entity( 'post', $page_id ); $signature = Nova_Bridge_Suite_Strategy::fingerprint( $entity )['signature'];
    $catalog = $success( $call( 'GET', 'catalog', [ 'mode' => 'nova' ] ), 'Real REST catalog parses the actual stock-array API contract.' );
    $assert( 'nova' === $catalog['origin'] && '901' === $catalog['templates'][0]['id'] && '902' === $catalog['templates'][0]['revision'], 'Canonical catalog identities remain strings.' );
    $input = [ 'expected_revision' => '', 'reference_type' => 'post', 'reference_id' => $page_id, 'signature' => $signature, 'catalog_mode' => 'nova', 'template' => [ 'id' => '901', 'revision' => '902' ], 'label' => 'Temporary sync canary', 'guidance' => 'Use clear and factual language.', 'fields' => [ '/title' => [ 'mode' => 'mapped', 'source_path' => 'page_heading', 'instructions' => 'Keep the heading concise.' ] ], 'skipped_sources' => [ [ 'source_path' => 'summary', 'reason' => 'The fixture has no summary target.' ] ], 'repeat_slots' => [], 'routing' => [ 'operation' => 'update', 'locale' => '', 'publication' => 'preserve' ] ];
    $saved = $success( $call( 'POST', 'draft', $input ), 'Real draft save binds the canonical source to live native inventory.' );
    $params = [ 'reference_type' => 'post', 'reference_id' => $page_id, 'signature' => $signature, 'expected_revision' => $saved['draft']['revision'] ];
    $synced = $success( $call( 'POST', 'sync', $params ), 'Real REST synchronization persists site-owned template, mapping and assignment drafts.' );
    $assert( 'synced_draft' === $synced['state']['status'] && 1 === count( $remote['template']['fields'] ) && 'Use clear and factual language.' === $remote['template']['authoring_notes'], 'Explicit skip and layout instructions reach the canonical template.' );
    $active = $success( $call( 'POST', 'activate', $params ), 'Native runtime capability probe allows exact scalar configuration sealing and fixture activation.' );
    $assert( 'active' === $active['state']['status'], 'Only confirmed service activation reports active.' );
    $client = new Nova_Bridge_Suite_Posting_Client( $fixture_connection );
    $exact = Nova_Bridge_Suite_Mapping_Sync::exact_configuration( $client, '910', $remote['pin']['digest'], $site_id );
    $assert( ! is_wp_error( $exact ) && $exact['local']['revision'] === $saved['draft']['revision'], 'Exact remote pin round trip matches its immutable local snapshot.' );
    $status = $success( $call( 'GET', 'sync-state', $params ), 'Private synchronization status reads the retained active state.' );
    $assert( $status['state']['pin']['pin_id'] === '910', 'Status identifies the exact retained pin.' );
    clean_post_cache( $page_id );
    $assert( get_post( $page_id )->to_array() === $before && get_post_meta( $page_id ) === $before_meta, 'Synchronization and capability verification leave native content and metadata unchanged.' );
    wp_set_current_user( 0 ); $anonymous = $call( 'POST', 'sync', $params ); $assert( 401 === $anonymous->get_status(), 'Anonymous synchronization is rejected.' );
} catch ( Throwable $error ) { $failure = $error->getMessage(); }
finally {
    if ( $http_filter ) { remove_filter( 'pre_http_request', $http_filter, PHP_INT_MAX ); }
    if ( $settings_filter ) { remove_filter( 'pre_option_' . Nova_Bridge_Suite_Posting_Settings::OPTION, $settings_filter, PHP_INT_MAX ); }
    wp_set_current_user( $old_user );
    foreach ( $owned_options as $name ) { delete_option( $name ); $missing = new stdClass(); if ( get_option( $name, $missing ) !== $missing ) { $cleanup_failures[] = 'Option not removed: ' . $name; } }
    if ( is_int( $page_id ) && $page_id > 0 ) { wp_delete_post( $page_id, true ); if ( get_post( $page_id ) ) { $cleanup_failures[] = 'Fixture page not removed.'; } }
}
if ( $cleanup_failures ) { $failure = trim( (string) $failure . ' Cleanup: ' . implode( ' ', $cleanup_failures ) ); }
if ( $failure ) { WP_CLI::error( $failure ); }
WP_CLI::success( 'mapping-sync-staging: ' . $checks . ' checks passed; fixture page/options cleaned.' );
