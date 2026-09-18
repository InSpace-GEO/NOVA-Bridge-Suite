<?php
/**
 * Staging-only integration canary: wp eval-file /absolute/path/mapping-drafts-staging.php.
 * Load the candidate plugin first. Uses real REST, inventory and options storage.
 * Creates one temporary draft page and its local mapping option; removes both in finally.
 * Never calls NOVA, publishes content, changes existing mappings/settings, or changes roles.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit( 1 ); }

$checks = 0;
$failure = null;
$cleanup_failures = [];
$old_user = get_current_user_id();
$post_id = 0;
$owned_option_name = '';
$registered_meta = [];
$guidance_hook = 'pre_option_nova_bridge_mapping_rest_guidance';
// false means "do not override" to pre_option; the string '0' explicitly disables it.
$guidance_off = static function () { return '0'; };
$race = null;
$race_priority = PHP_INT_MAX;
$assert = static function ( $condition, string $message ) use ( &$checks ): void {
    if ( ! $condition ) { throw new RuntimeException( $message ); }
    ++$checks;
    WP_CLI::line( 'PASS  ' . $message );
};
$call = static function ( string $method, string $route, array $params, int $user_id ) {
    wp_set_current_user( $user_id );
    $request = new WP_REST_Request( $method, '/nova-bridge/v1/mapping/' . $route );
    if ( 'POST' === $method ) {
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( wp_json_encode( $params ) );
    } else {
        foreach ( $params as $key => $value ) { $request->set_param( $key, $value ); }
    }
    return rest_do_request( $request );
};
$success = static function ( $response, string $message ) use ( $assert ): array {
    if ( 200 !== $response->get_status() ) { throw new RuntimeException( $message . ' (HTTP ' . $response->get_status() . ': ' . wp_json_encode( $response->get_data() ) . ')' ); }
    $assert( true, $message );
    return $response->get_data();
};
$rejected = static function ( $response, int $status, string $code, string $message ) use ( $assert ): void {
    $data = $response->get_data();
    $assert( $status === $response->get_status() && 'nova_mapping_draft_' . $code === ( $data['code'] ?? '' ), $message . ' (HTTP ' . $response->get_status() . ', ' . ( $data['code'] ?? 'no code' ) . ')' );
};

try {
    foreach ( [ 'Nova_Bridge_Suite_Mapping_Drafts', 'Nova_Bridge_Suite_Strategy', 'Nova_Bridge_Suite_Content_Context', 'Nova_Bridge_Suite_Content_Transport' ] as $class ) {
        $assert( class_exists( $class ), 'Candidate component is loaded: ' . $class );
    }
    $admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
    $assert( ! empty( $admins ), 'An existing administrator is available.' );
    $admin_id = (int) $admins[0];
    wp_set_current_user( $admin_id );
    $assert( current_user_can( 'manage_options' ), 'Selected administrator can manage mapping drafts.' );
    add_filter( $guidance_hook, $guidance_off, PHP_INT_MAX );
    $assert( ! Nova_Bridge_Suite_Content_Context::rest_guidance_enabled(), 'Optional REST guidance is disabled in memory only.' );

    $suffix = strtolower( wp_generate_password( 12, false, false ) );
    $title = 'NOVA mapping draft test ' . $suffix;
    $created = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => $title, 'post_content' => '<p>Unchanged source content.</p>', 'post_excerpt' => 'Unchanged source excerpt.', 'post_author' => $admin_id ], true );
    $assert( ! is_wp_error( $created ) && $created > 0, 'Temporary unpublished page created.' );
    $post_id = (int) $created;
    $candidate_option = 'nova_mapping_draft_' . hash( 'sha256', 'post:' . $post_id );
    $missing = new stdClass();
    $assert( $missing === get_option( $candidate_option, $missing ), 'Temporary reference has no pre-existing mapping option.' );
    $owned_option_name = $candidate_option;

    $meta = [];
    foreach ( [ 'step_heading', 'step_body', 'optional_copy', 'protected_copy' ] as $part ) {
        $key = 'nova_draft_test_' . $suffix . '_' . $part;
        $assert( ! isset( get_registered_meta_keys( 'post', 'page' )[ $key ] ), 'Temporary meta registration does not replace an existing key: ' . $part );
        $registered = register_post_meta( 'page', $key, [
            'type' => 'string', 'single' => true, 'show_in_rest' => false,
            'description' => 'Temporary editorial ' . $part . ' used by the NOVA mapping draft canary.',
            'auth_callback' => static function ( $allowed, $meta_key, $object_id ) { return $object_id > 0 && current_user_can( 'edit_post', $object_id ); },
        ] );
        $assert( $registered, 'Temporary editorial metadata registered: ' . $part );
        $registered_meta[] = $key;
        update_post_meta( $post_id, $key, 'Original ' . $part . ' ' . $suffix );
        $meta[ $part ] = $key;
    }

    $server = rest_get_server();
    $routes = $server->get_routes();
    $assert( isset( $routes['/nova-bridge/v1/mapping/catalog'], $routes['/nova-bridge/v1/mapping/draft'] ), 'Candidate bootstrap registers catalog and draft REST routes.' );
    $entity = Nova_Bridge_Suite_Strategy::entity( 'post', $post_id );
    $assert( is_array( $entity ), 'Real discovery accepts the temporary page as an editable reference.' );
    $fingerprint = Nova_Bridge_Suite_Strategy::fingerprint( $entity );
    $inventory = array_column( Nova_Bridge_Suite_Strategy::field_inventory( $entity ), null, 'path' );
    $assert( ! empty( $inventory['/title']['writable'] ), 'Real inventory exposes the native title target.' );
    $paths = [];
    foreach ( $meta as $part => $key ) {
        $path = '/meta_all/' . $key;
        $assert( ! empty( $inventory[ $path ]['writable'] ) && 'nova_content_bridge' === ( $inventory[ $path ]['transport'] ?? '' ), 'Real inventory exposes the exact registered scalar target: ' . $part );
        $paths[ $part ] = $path;
    }
    $reference = [ 'reference_type' => 'post', 'reference_id' => $post_id, 'signature' => $fingerprint['signature'], 'mode' => 'preview' ];
    $original_post = get_post( $post_id )->to_array();
    $original_meta = get_post_meta( $post_id );

    $catalog_response = $call( 'GET', 'catalog', [ 'mode' => 'preview' ], $admin_id );
    $catalog = $success( $catalog_response, 'Administrator reads the documented preview catalog.' );
    $assert( 'private, no-store' === ( $catalog_response->get_headers()['Cache-Control'] ?? '' ), 'Catalog response prevents shared caching.' );
    $assert( 'preview' === $catalog['origin'], 'Preview catalog is explicitly identified.' );
    $template = null;
    foreach ( $catalog['templates'] as $candidate ) { if ( 'service' === $candidate['family'] ) { $template = $candidate; break; } }
    $assert( is_array( $template ), 'Preview catalog includes a service template.' );
    $new_draft = $success( $call( 'GET', 'draft', $reference, $admin_id ), 'Administrator reads a new reference draft.' );
    $assert( null === $new_draft['draft'], 'A new reference does not inherit another page’s mapping.' );
    $input = [
        'expected_revision' => '', 'reference_type' => 'post', 'reference_id' => $post_id, 'signature' => $fingerprint['signature'],
        'catalog_mode' => 'preview', 'template' => [ 'id' => $template['id'], 'revision' => $template['revision'] ],
        'label' => $title, 'guidance' => 'Preserve the source page while preparing its mapping.',
        'fields' => [ '/title' => [ 'mode' => 'mapped', 'source_path' => 'page_heading', 'instructions' => 'Use one heading.', 'binding' => 'forged-target', 'route' => 'https://forged-target.invalid/' ] ],
        'skipped_sources' => [ [ 'source_path' => 'summary', 'reason' => 'This example has no separate summary target.' ] ],
        'repeat_slots' => [], 'routing' => [ 'operation' => 'update', 'locale' => 'nl-NL', 'post_type' => 'forged-target', 'route' => 'https://forged-target.invalid/' ],
        'target_descriptors' => [ '/title' => [ 'route' => 'https://forged-target.invalid/' ] ], 'status' => 'active',
    ];

    foreach ( [ [ 'GET', 'catalog', [ 'mode' => 'preview' ] ], [ 'GET', 'draft', $reference ], [ 'POST', 'draft', $input ] ] as $case ) {
        $rejected( $call( $case[0], $case[1], $case[2], 0 ), 401, 'forbidden', 'Anonymous caller cannot ' . $case[0] . ' ' . $case[1] . '.' );
    }
    $subscribers = get_users( [ 'role' => 'subscriber', 'number' => 1, 'fields' => 'ID' ] );
    if ( $subscribers ) {
        $rejected( $call( 'POST', 'draft', $input, (int) $subscribers[0] ), 403, 'forbidden', 'Existing subscriber cannot save mapping drafts.' );
    } else { WP_CLI::line( 'SKIP  No existing subscriber; no user or role was created for this optional check.' ); }

    $first_response = $call( 'POST', 'draft', $input, $admin_id );
    $first = $success( $first_response, 'Incomplete mapping with an explicit skipped source saves through real REST and database storage.' );
    $draft = $first['draft'];
    $assert( 'private, no-store' === ( $first_response->get_headers()['Cache-Control'] ?? '' ), 'Saved draft response prevents shared caching.' );
    $assert( is_string( $draft['revision'] ) && '' !== $draft['revision'], 'Saved draft has an opaque string revision.' );
    $assert( 'local_draft' === $draft['status'] && true === $first['handoff']['local_only'] && false === $first['handoff']['verified'] && false === $first['handoff']['synchronized'], 'Local save does not claim activation, certification or synchronization.' );
    $assert( false === strpos( wp_json_encode( $first ), 'forged-target' ), 'Client-supplied native descriptors, routing and active status do not enter trusted output.' );
    $assert( $post_id === $draft['reference_id'] && 'page' === $draft['routing']['post_type'] && $fingerprint['signature'] === $draft['target_descriptors']['/title']['signature'], 'Saved native scope is resolved from the actual reference.' );
    $assert( 'summary' === $draft['skipped_sources'][0]['source_path'] && false !== strpos( implode( ' ', $first['warnings'] ), 'Unmapped NOVA fields' ), 'Skipped and unmapped NOVA fields remain advisory for local drafts.' );
    global $wpdb;
    $stored = maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $owned_option_name ) ) );
    $autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $owned_option_name ) );
    $assert( $draft['revision'] === ( $stored['revision'] ?? '' ) && 'no' === $autoload, 'Real options storage retains this revision without autoloading it.' );
    $rejected( $call( 'POST', 'draft', $input, $admin_id ), 409, 'conflict', 'Repeating a stale initial save cannot overwrite the stored draft.' );

    $input['expected_revision'] = $draft['revision'];
    $input['guidance'] = 'Edited locally; protected components must remain intact.';
    $second = $success( $call( 'POST', 'draft', $input, $admin_id ), 'Matching revision updates the local draft.' );
    $assert( $second['draft']['revision'] !== $draft['revision'], 'Successful update rotates its revision token.' );
    $rejected( $call( 'POST', 'draft', $input, $admin_id ), 409, 'conflict', 'An older edit cannot replace the new revision.' );
    $input['expected_revision'] = $second['draft']['revision'];

    // Interleave one competing write immediately before the candidate's UPDATE.
    // The query hook is removed before writing, and matches only this new option.
    $race_applied = false;
    $winner = $second['draft']; $winner['revision'] = wp_generate_uuid4(); $winner['guidance'] = 'Concurrent test editor retained.';
    $name_clause = $wpdb->prepare( 'option_name = %s', $owned_option_name );
    $race = static function ( $query ) use ( &$race, &$race_applied, $race_priority, $name_clause, $owned_option_name, $winner, $assert ) {
        global $wpdb;
        if ( 0 !== strpos( $query, "UPDATE {$wpdb->options} SET option_value" ) || false === strpos( $query, $name_clause ) ) { return $query; }
        remove_filter( 'query', $race, $race_priority );
        $changed = $wpdb->update( $wpdb->options, [ 'option_value' => maybe_serialize( $winner ) ], [ 'option_name' => $owned_option_name ] );
        $assert( 1 === $changed, 'Competing test edit changes only the newly created mapping option.' );
        $race_applied = true;
        wp_cache_delete( $owned_option_name, 'options' );
        return $query;
    };
    add_filter( 'query', $race, $race_priority );
    $rejected( $call( 'POST', 'draft', $input, $admin_id ), 409, 'conflict', 'A change arriving between read and SQL update is rejected by real database compare-and-swap.' );
    $assert( $race_applied, 'The real database race path was exercised.' );
    $read = $success( $call( 'GET', 'draft', $reference, $admin_id ), 'Read back the competing winner after the conflict.' );
    $assert( $winner['revision'] === $read['draft']['revision'] && $winner['guidance'] === $read['draft']['guidance'], 'The losing request leaves the competing edit intact.' );
    $input['expected_revision'] = $winner['revision'];

    $input['fields'][ $paths['optional_copy'] ] = [ 'mode' => 'leave_empty', 'source_path' => '', 'instructions' => 'Skip on updates; blank only on clones.' ];
    $input['fields'][ $paths['protected_copy'] ] = [ 'mode' => 'protected', 'source_path' => '', 'instructions' => 'Preserve this field on updates and clones.' ];
    foreach ( [ 'update', 'clone' ] as $operation ) {
        $input['routing']['operation'] = $operation;
        $result = $success( $call( 'POST', 'draft', $input, $admin_id ), 'Distinct Leave empty and Protected intents save for ' . $operation . ' routing.' );
        $assert( 'leave_empty' === $result['draft']['fields'][ $paths['optional_copy'] ]['mode'] && 'protected' === $result['draft']['fields'][ $paths['protected_copy'] ]['mode'], 'Both field modes remain distinct in the ' . $operation . ' draft.' );
        $input['expected_revision'] = $result['draft']['revision'];
    }

    $input['repeat_slots'] = [ 'steps' => [ [ 'id' => 'existing-slot-one', 'targets' => [ 'step_heading' => $paths['step_heading'], 'step_body' => $paths['step_body'] ] ], [ 'id' => 'unfinished-slot', 'targets' => [] ] ] ];
    $repeat = $success( $call( 'POST', 'draft', $input, $admin_id ), 'Fixed repeat slots bind existing registered scalar targets and retain an unfinished local slot.' );
    $assert( $input['repeat_slots'] === $repeat['draft']['repeat_slots'] && false !== strpos( implode( ' ', $repeat['warnings'] ), 'unfinished-slot is incomplete' ), 'Repeat member addresses and slot identities round-trip without inventing native rows.' );
    $input['expected_revision'] = $repeat['draft']['revision'];
    $duplicate = $input; $duplicate['repeat_slots']['steps'][1]['targets'] = [ 'step_heading' => $paths['step_heading'] ];
    $rejected( $call( 'POST', 'draft', $duplicate, $admin_id ), 400, 'repeat_target', 'A native target cannot be assigned to two repeat slots.' );
    $overlap = $input; $overlap['fields'][ $paths['step_heading'] ] = [ 'mode' => 'protected', 'source_path' => '', 'instructions' => '' ];
    $rejected( $call( 'POST', 'draft', $overlap, $admin_id ), 400, 'repeat_target', 'Protected scalar target cannot also be used as a repeat output.' );
    $stale = $input; $stale['signature'] = 'stale-layout-signature';
    $rejected( $call( 'POST', 'draft', $stale, $admin_id ), 409, 'reference_changed', 'A stale reference signature cannot replace the saved draft.' );
    $final = $success( $call( 'GET', 'draft', $reference, $admin_id ), 'Final draft remains readable after rejected changes.' );
    $assert( $repeat['draft']['revision'] === $final['draft']['revision'], 'Rejected requests never rotate or replace the saved revision.' );

    $native_request = new WP_REST_Request( 'GET', '/wp/v2/pages/' . $post_id ); $native_request->set_param( 'context', 'edit' );
    $native_response = rest_do_request( $native_request );
    $assert( 200 === $native_response->get_status(), 'Native page remains readable with guidance disabled.' );
    $assert( [] === array_intersect( [ 'nova_content_mappings', 'nova_template_contexts', 'nova_strategy_context', 'nova_omit_fields' ], array_keys( $native_response->get_data() ) ), 'Native page response does not expose generic mapping guidance while disabled.' );
    clean_post_cache( $post_id );
    $current_post = get_post( $post_id )->to_array();
    foreach ( [ 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_name', 'post_parent', 'post_modified', 'post_modified_gmt' ] as $field ) {
        $assert( $original_post[ $field ] === $current_post[ $field ], 'Mapping operations do not mutate native ' . $field . '.' );
    }
    $assert( $original_meta === get_post_meta( $post_id ), 'Mapping, omission, protection and repeat operations leave every source metadata value unchanged.' );
} catch ( Throwable $error ) {
    $failure = $error;
} finally {
    if ( $race ) { remove_filter( 'query', $race, $race_priority ); }
    remove_filter( $guidance_hook, $guidance_off, PHP_INT_MAX );
    if ( $owned_option_name ) {
        try {
            delete_option( $owned_option_name );
            $sentinel = new stdClass();
            if ( $sentinel !== get_option( $owned_option_name, $sentinel ) ) { $cleanup_failures[] = 'Temporary mapping option remains: ' . $owned_option_name; }
        } catch ( Throwable $error ) { $cleanup_failures[] = 'Mapping option cleanup: ' . $error->getMessage(); }
    }
    if ( $post_id ) {
        try {
            wp_delete_post( $post_id, true );
            if ( get_post( $post_id ) ) { $cleanup_failures[] = 'Temporary draft page remains: ' . $post_id; }
        } catch ( Throwable $error ) { $cleanup_failures[] = 'Temporary page cleanup: ' . $error->getMessage(); }
    }
    foreach ( $registered_meta as $key ) {
        try { unregister_post_meta( 'page', $key ); }
        catch ( Throwable $error ) { $cleanup_failures[] = 'Metadata registration cleanup: ' . $error->getMessage(); }
    }
    wp_set_current_user( $old_user );
}
foreach ( $cleanup_failures as $message ) { WP_CLI::warning( $message ); }
if ( $failure || $cleanup_failures ) { WP_CLI::error( 'Mapping draft staging canary failed: ' . ( $failure ? $failure->getMessage() : 'fixture cleanup incomplete' ) ); }
WP_CLI::success( 'PASS ' . $checks . ' real WordPress mapping draft, revision, REST and preservation checks. Temporary page/option removed; existing settings and mappings untouched.' );
