<?php
/**
 * Staging only: wp eval-file /absolute/path/elementor-clone-staging.php.
 * Requires Elementor and the candidate Elementor/mapping bridges to be loaded.
 * Creates its own draft source and clones; no existing source ID is used.
 * Verifies current legacy clone omission and untouched widget payloads only.
 * Does not certify page settings/dependencies, concurrent edits or crash recovery.
 * Elementor's normal save lifecycle may invalidate its global generated cache.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit( 1 ); }

$checks = 0;
$failure = null;
$cleanup_failures = [];
$ids = [];
$old_user = get_current_user_id();
$override = null;
$creating = false;
$clone_titles = [];
$profile_hook = 'pre_option_nova_bridge_suite_strategy';
$profile_filter = static function ( $value ) use ( &$override ) { return null === $override ? $value : $override; };
$guidance_off = static function () { return '0'; };
$capture_created = static function ( $id, $post, $update ) use ( &$creating, &$ids, &$clone_titles ) {
    if ( $creating && ! $update && 'page' === $post->post_type && in_array( $post->post_title, $clone_titles, true ) ) { $ids[] = (int) $id; }
};
$assert = static function ( $condition, string $message ) use ( &$checks ): void {
    if ( ! $condition ) { throw new RuntimeException( $message ); }
    ++$checks;
    WP_CLI::line( 'PASS  ' . $message );
};
$sort_object_keys = static function ( $value ) use ( &$sort_object_keys ) {
    if ( ! is_array( $value ) ) { return $value; }
    foreach ( $value as $key => $item ) { $value[ $key ] = $sort_object_keys( $item ); }
    // JSON object property order is immaterial; ordered node/repeater lists are not.
    if ( $value && array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) { ksort( $value, SORT_STRING ); }
    return $value;
};
$canonical = static function ( array $nodes ) use ( &$canonical, $sort_object_keys ): array {
    foreach ( $nodes as &$node ) {
        // Elementor may add these equivalent empty/default structural properties.
        if ( ( $node['settings'] ?? null ) === [] ) { unset( $node['settings'] ); }
        if ( ( $node['isInner'] ?? null ) === false ) { unset( $node['isInner'] ); }
        if ( isset( $node['elements'] ) ) { $node['elements'] = $canonical( $node['elements'] ); }
    }
    unset( $node );
    return $sort_object_keys( $nodes );
};
// These values come only from this canary's synthetic source, never existing site content.
$fixture_differences = static function ( $expected, $actual ): array {
    $differences = [];
    $value = static function ( $item ): array {
        $encoded = wp_json_encode( $item );
        return [ 'type' => gettype( $item ), 'value' => strlen( (string) $encoded ) > 600 ? substr( $encoded, 0, 600 ) . '...[truncated]' : $item ];
    };
    $walk = static function ( $left, $right, string $path ) use ( &$walk, &$differences, $value ): void {
        if ( $left === $right || count( $differences ) >= 20 ) { return; }
        if ( is_array( $left ) && is_array( $right ) ) {
            $left_keys = array_keys( $left ); $right_keys = array_keys( $right );
            if ( $left_keys !== $right_keys && ! array_diff( $left_keys, $right_keys ) && ! array_diff( $right_keys, $left_keys ) ) {
                $differences[] = [ 'path' => $path, 'difference' => 'key_order', 'expected_keys' => $left_keys, 'actual_keys' => $right_keys ];
            }
            foreach ( $left as $key => $item ) {
                if ( count( $differences ) >= 20 ) { return; }
                $child_path = $path . '/' . str_replace( [ '~', '/' ], [ '~0', '~1' ], (string) $key );
                if ( ! array_key_exists( $key, $right ) ) { $differences[] = [ 'path' => $child_path, 'difference' => 'missing', 'expected' => $value( $item ) ]; }
                else { $walk( $item, $right[ $key ], $child_path ); }
            }
            foreach ( $right as $key => $item ) {
                if ( count( $differences ) >= 20 ) { return; }
                if ( ! array_key_exists( $key, $left ) ) { $differences[] = [ 'path' => $path . '/' . str_replace( [ '~', '/' ], [ '~0', '~1' ], (string) $key ), 'difference' => 'added', 'actual' => $value( $item ) ]; }
            }
            return;
        }
        $differences[] = [ 'path' => $path, 'difference' => 'value_or_type', 'expected' => $value( $left ), 'actual' => $value( $right ) ];
    };
    $walk( $expected, $actual, '' );
    return $differences;
};
$find = static function ( array $nodes, string $id ) use ( &$find ) {
    foreach ( $nodes as $node ) {
        if ( ( $node['id'] ?? '' ) === $id ) { return $node; }
        $child = $find( $node['elements'] ?? [], $id );
        if ( $child ) { return $child; }
    }
    return null;
};
$request = static function ( string $method, string $path, array $payload ) {
    $request = new WP_REST_Request( $method, '/seor-bridge/v1/pages' . $path );
    $request->set_header( 'content-type', 'application/json' );
    $request->set_body( wp_json_encode( $payload ) );
    return rest_do_request( $request );
};

try {
    foreach ( [ 'Elementor\\Plugin', 'SEOR_Elementor_Bridge\\Elementor_Service', 'Nova_Bridge_Suite_Strategy', 'Nova_Bridge_Suite_Content_Context' ] as $class ) {
        $assert( class_exists( $class ), 'Required component is loaded: ' . $class );
    }
    $admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
    $assert( ! empty( $admins ), 'An existing administrator is available.' );
    wp_set_current_user( (int) $admins[0] );
    $assert( current_user_can( 'manage_options' ), 'Selected user can inspect and map its temporary fixture.' );
    add_filter( 'pre_option_nova_bridge_mapping_rest_guidance', $guidance_off, PHP_INT_MAX );
    $server = rest_get_server();
    $assert( isset( $server->get_routes()['/seor-bridge/v1/pages'] ), 'Candidate Elementor clone REST route is registered.' );
    $assert( false !== has_filter( 'seor_eb_clone_document', [ 'Nova_Bridge_Suite_Strategy', 'empty_elementor_clone_fields' ] ), 'Legacy clone omission hook is registered.' );

    $suffix = strtolower( wp_generate_password( 12, false, false ) );
    $prefix = 'NOVA temporary Elementor clone ' . $suffix;
    $source_id = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => $prefix . ' source', 'post_author' => get_current_user_id() ], true );
    $assert( ! is_wp_error( $source_id ) && $source_id > 0, 'Temporary unpublished Elementor source created.' );
    $source_id = (int) $source_id;
    $ids[] = $source_id;
    $plugin = \Elementor\Plugin::instance();
    $native_form = isset( $plugin->widgets_manager ) && method_exists( $plugin->widgets_manager, 'get_widget_types' ) ? $plugin->widgets_manager->get_widget_types( 'form' ) : null;
    $form = [
        'id' => 'b100003', 'elType' => 'widget', 'widgetType' => $native_form ? 'form' : 'html',
        'settings' => $native_form ? [
            'form_name' => 'NOVA untouched fixture ' . $suffix,
            'form_fields' => [ [ '_id' => 'a12cafe', 'field_type' => 'text', 'field_label' => 'Untouched name', 'custom_id' => 'fixture_name', 'required' => '' ] ],
            'submit_actions' => [], 'button_text' => 'Fixture only',
        ] : [ 'html' => '<form data-nova-fixture="' . $suffix . '" action="#"><label>Untouched name <input name="fixture_name" type="text" disabled></label><button type="button" disabled>Fixture only</button></form>' ],
        'elements' => [],
    ];
    $fixture = [ [ 'id' => 'b100001', 'elType' => 'container', 'settings' => [ 'content_width' => 'full', 'flex_direction' => 'column' ], 'elements' => [
        [ 'id' => 'b100002', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Original mapped fixture heading', 'header_size' => 'h2', 'align' => 'left' ], 'elements' => [] ],
        $form,
        [ 'id' => 'b100004', 'elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => [ 'editor' => '<p>Untouched sibling copy ' . $suffix . '.</p>', 'align' => 'left' ], 'elements' => [] ],
    ] ] ];
    update_post_meta( $source_id, '_elementor_edit_mode', 'builder' );
    update_post_meta( $source_id, '_elementor_template_type', 'wp-page' );
    if ( defined( 'ELEMENTOR_VERSION' ) ) { update_post_meta( $source_id, '_elementor_version', ELEMENTOR_VERSION ); }
    update_post_meta( $source_id, '_elementor_data', wp_slash( wp_json_encode( $fixture ) ) );
    $source_settings = [ 'hide_title' => 'yes' ];
    update_post_meta( $source_id, '_elementor_page_settings', $source_settings );
    $source_raw = get_post_meta( $source_id, '_elementor_data', true );
    $service = new \SEOR_Elementor_Bridge\Elementor_Service();
    $document = $service->get_elementor_document_data( $source_id );
    $assert( is_array( $document ) && $canonical( $document ) === $canonical( $fixture ), 'Temporary source reads back with all fixture IDs and native settings.' );
    WP_CLI::line( 'FORM FIXTURE  ' . ( $native_form ? 'Native Elementor form widget (no submit actions).' : 'Core Elementor HTML widget containing an inert form; native Pro Form is unavailable.' ) );
    $entity = Nova_Bridge_Suite_Strategy::entity( 'post', $source_id );
    $assert( is_array( $entity ), 'Temporary source is eligible for real mapping discovery.' );
    $fingerprint = Nova_Bridge_Suite_Strategy::fingerprint( $entity );
    $fields = Nova_Bridge_Suite_Strategy::field_inventory( $entity );
    $target = null;
    foreach ( $fields as $field ) {
        if ( 'elementor' === ( $field['builder'] ?? '' ) && 'b100002|title' === ( $field['selector_data']['field_key'] ?? '' ) ) { $target = $field; break; }
    }
    $assert( is_array( $target ) && ! empty( $target['writable'] ), 'Actual inventory resolves the intended heading selector.' );
    $base_option = Nova_Bridge_Suite_Strategy::get_option();
    $override = $base_option;
    $profile = [ 'id' => $fingerprint['signature'], 'signature' => $fingerprint['signature'], 'label' => 'Temporary clone omission fixture', 'reference_type' => 'post', 'reference_id' => $source_id, 'reference_post_id' => $source_id, 'guidance' => '', 'fields' => [ $target['path'] => [ 'mapping' => 'leave_empty', 'description' => '', 'binding' => $target['binding'] ?? '' ] ] ];
    $override['profiles'][ $fingerprint['signature'] ] = $profile;
    add_filter( $profile_hook, $profile_filter, PHP_INT_MAX );
    add_action( 'wp_insert_post', $capture_created, PHP_INT_MAX, 3 );
    $create = static function ( string $title, array $changes ) use ( $source_id, &$ids, &$creating, &$clone_titles, $request, $assert ) {
        $clone_titles[] = $title;
        $creating = true;
        try { $response = $request( 'POST', '', [ 'source_page_id' => $source_id, 'title' => $title, 'post_type' => 'page', 'status' => 'draft', 'publish_elementor' => false, 'fields' => $changes ] ); }
        finally { $creating = false; }
        $data = $response->get_data();
        if ( $response->get_status() >= 300 || empty( $data['post_id'] ) ) { throw new RuntimeException( 'Temporary clone failed: HTTP ' . $response->get_status() . ' ' . wp_json_encode( $data ) ); }
        $id = (int) $data['post_id'];
        $ids[] = $id;
        $assert( 'draft' === get_post_status( $id ), 'Created clone remains unpublished.' );
        return $id;
    };

    $clone_id = $create( $prefix . ' omitted', [ [ 'field_key' => $target['selector_data']['field_key'], 'value' => 'Explicit content must still be omitted.' ] ] );
    $expected = $document;
    $expected[0]['elements'][0]['settings']['title'] = '';
    $cloned = $service->get_elementor_document_data( $clone_id );
    $normalized_expected = $canonical( $expected );
    $normalized_actual = is_array( $cloned ) ? $canonical( $cloned ) : $cloned;
    if ( $normalized_actual !== $normalized_expected ) { throw new RuntimeException( 'Omitted clone changed the synthetic fixture beyond its heading value. First 20 differences: ' . wp_json_encode( $fixture_differences( $normalized_expected, $normalized_actual ) ) ); }
    $assert( true, 'Omitted clone differs only in the selected heading value; container, IDs, order, form and sibling settings are preserved.' );
    $assert( '' === $find( $cloned, 'b100002' )['settings']['title'], 'Clone omission takes precedence over the explicitly supplied heading value.' );
    $assert( $canonical( [ $find( $document, 'b100003' ) ] ) === $canonical( [ $find( $cloned, 'b100003' ) ] ), 'Untouched form widget keeps its ID and full native payload.' );

    unset( $override['profiles'][ $fingerprint['signature'] ] );
    $control_id = $create( $prefix . ' control', [] );
    $control = $service->get_elementor_document_data( $control_id );
    $assert( is_array( $control ) && $canonical( $control ) === $canonical( $document ), 'Clone without an omission mapping preserves the complete source document.' );

    // Existing-page updates must not apply the clone-only omission filter.
    $override['profiles'][ $fingerprint['signature'] ] = $profile;
    $updated = $request( 'PATCH', '/' . $control_id, [ 'title' => $prefix . ' updated control', 'status' => 'draft', 'publish_elementor' => false ] );
    if ( $updated->get_status() >= 300 ) { throw new RuntimeException( 'Temporary update failed: ' . wp_json_encode( $updated->get_data() ) ); }
    $after_update = $service->get_elementor_document_data( $control_id );
    $assert( is_array( $after_update ) && $canonical( $after_update ) === $canonical( $document ), 'Existing-page update omits unsupplied builder fields and retains the original heading and form.' );
    $assert( 'draft' === get_post_status( $source_id ) && 'draft' === get_post_status( $control_id ), 'Source and updated control remain drafts.' );
    $assert( $source_raw === get_post_meta( $source_id, '_elementor_data', true ), 'Original temporary source document remains byte-for-byte unchanged.' );
    $assert( $source_settings === get_post_meta( $source_id, '_elementor_page_settings', true ), 'Source page settings remain unchanged.' );
    remove_filter( $profile_hook, $profile_filter, PHP_INT_MAX );
    $assert( $base_option === Nova_Bridge_Suite_Strategy::get_option(), 'Temporary omission profile was never persisted to existing mapping settings.' );
} catch ( Throwable $error ) {
    $failure = $error;
} finally {
    remove_filter( $profile_hook, $profile_filter, PHP_INT_MAX );
    remove_filter( 'pre_option_nova_bridge_mapping_rest_guidance', $guidance_off, PHP_INT_MAX );
    remove_action( 'wp_insert_post', $capture_created, PHP_INT_MAX );
    foreach ( array_reverse( array_unique( $ids ) ) as $id ) {
        try {
            if ( get_post( $id ) ) { wp_delete_post( $id, true ); }
            if ( get_post( $id ) ) { $cleanup_failures[] = 'Temporary Elementor post remains: ' . $id; }
        } catch ( Throwable $error ) { $cleanup_failures[] = 'Temporary post ' . $id . ': ' . $error->getMessage(); }
    }
    wp_set_current_user( $old_user );
}
foreach ( $cleanup_failures as $message ) { WP_CLI::warning( $message ); }
if ( $failure || $cleanup_failures ) { WP_CLI::error( 'Elementor clone canary failed: ' . ( $failure ? $failure->getMessage() : 'fixture cleanup incomplete' ) ); }
WP_CLI::success( 'PASS ' . $checks . ' temporary Elementor clone omission and document preservation checks. All fixture posts removed; existing mappings untouched.' );
WP_CLI::line( 'SCOPE  This verifies immediate document/widget preservation. It does not certify automatic Protected execution, cloned page settings/dependencies, concurrent edits, crash recovery, or global cache behavior.' );
