<?php
/**
 * Native, self-cleaning draft canary. Run only with an explicit administrator:
 * NOVA_STORED_LAYOUT_CANARY=1 wp eval-file PATH --user=ADMIN_ID
 * No posting-service requests, saved credentials or publication are involved.
 */
if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'NOVA_STORED_LAYOUT_CANARY' ) || strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) !== 'inspace-seo.com' ) { throw new RuntimeException( 'This native canary requires WP-CLI, NOVA_STORED_LAYOUT_CANARY=1 and inspace-seo.com.' ); }
if ( ! get_current_user_id() || ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_pages' ) || ! current_user_can( 'delete_pages' ) ) { throw new RuntimeException( 'Run with --user=<administrator ID>; this canary creates and deletes only its own temporary drafts.' ); }

$nova_canary_root = WP_PLUGIN_DIR . '/nova-bridge-suite';
foreach ( [ 'recipe-engine', 'recipe-elementor', 'recipe-builders', 'mapping-drafts', 'document-mapping' ] as $nova_canary_file ) { require_once $nova_canary_root . '/modules/api-mapping-context/includes/class-nova-bridge-suite-' . $nova_canary_file . '.php'; }
foreach ( [ 'posting-protocol', 'writing-adapter', 'mapped-content-target', 'stored-layout', 'stored-layout-language', 'stored-layout-writer' ] as $nova_canary_file ) { require_once $nova_canary_root . '/modules/posting-service/includes/class-nova-bridge-suite-' . $nova_canary_file . '.php'; }
if ( ! class_exists( '\SEOR_Elementor_Bridge\Elementor_Service' ) || ! class_exists( '\Elementor\Plugin' ) ) { throw new RuntimeException( 'Enable the native Elementor module before running the canary.' ); }

$nova_canary_run = wp_generate_uuid4();
$nova_canary_suffix = substr( str_replace( '-', '', $nova_canary_run ), 0, 16 );
$nova_canary_site = wp_generate_uuid4();
$nova_canary_source_slug = 'nova-layout-canary-example-' . $nova_canary_suffix;
$nova_canary_source_marker = 'nova-layout-canary-example:' . $nova_canary_run;
$nova_canary_actor = get_current_user_id();
$nova_canary_created = [];
$nova_canary_cases = [];
$nova_canary_source_id = 0;
$nova_canary_network = 0;
$nova_canary_report = [ 'ok' => false, 'run_id' => $nova_canary_run, 'site' => home_url( '/' ), 'actor_id' => $nova_canary_actor, 'checks' => [], 'cases' => [], 'cleanup' => [ 'deleted_post_ids' => [], 'deleted_options' => [], 'errors' => [] ] ];
$nova_canary_assert = static function ( bool $condition, string $message ) use ( &$nova_canary_report ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } $nova_canary_report['checks'][] = $message; };
$nova_canary_checked = static function ( $value, string $stage ) { if ( is_wp_error( $value ) ) { throw new RuntimeException( $stage . ': ' . $value->get_error_code() . ': ' . $value->get_error_message() ); } return $value; };
$nova_canary_digest = static function ( $value ): string { return hash( 'sha256', Nova_Bridge_Suite_Writing_Adapter::canonical_json( $value ) ); };
$nova_canary_http = static function () use ( &$nova_canary_network ) { ++$nova_canary_network; return new WP_Error( 'nova_canary_network_disabled', 'Outbound HTTP is disabled during the native stored-layout canary.' ); };
$nova_canary_source_filter = static function ( array $data, array $postarr, array $raw ) use ( $nova_canary_source_slug, $nova_canary_source_marker, $nova_canary_actor ): array {
    if ( empty( $postarr['ID'] ) && ( $raw['post_name'] ?? $postarr['post_name'] ?? '' ) === $nova_canary_source_slug && (int) ( $raw['post_author'] ?? $postarr['post_author'] ?? 0 ) === $nova_canary_actor ) { $data['post_content_filtered'] = $nova_canary_source_marker; $data['post_status'] = 'draft'; }
    return $data;
};
$nova_canary_witness = static function ( int $id, $post, bool $update ) use ( &$nova_canary_created, &$nova_canary_cases, $nova_canary_source_marker, $nova_canary_actor ): void {
    if ( $update || 'page' !== $post->post_type || (int) $post->post_author !== $nova_canary_actor ) { return; }
    if ( $post->post_content_filtered === $nova_canary_source_marker ) { $nova_canary_created[ $id ] = [ 'kind' => 'example', 'marker' => $nova_canary_source_marker ]; return; }
    foreach ( $nova_canary_cases as $case ) { if ( isset( $case['marker'] ) && $post->post_content_filtered === $case['marker'] ) { $nova_canary_created[ $id ] = [ 'kind' => 'target', 'marker' => $case['marker'], 'operation' => $case['operation'], 'url_id' => $case['url_id'] ]; return; } }
};
add_filter( 'pre_http_request', $nova_canary_http, PHP_INT_MAX, 3 );
add_filter( 'wp_insert_post_data', $nova_canary_source_filter, PHP_INT_MAX, 3 );
add_action( 'wp_insert_post', $nova_canary_witness, PHP_INT_MAX, 3 );

try {
    $native = [ [ 'id' => 'a123456', 'elType' => 'container', 'isInner' => false, 'settings' => [ 'background_background' => 'classic', 'background_color' => '#edf2ff', 'background_overlay_opacity' => 0.75, 'padding_mobile' => [ 'unit' => 'px', 'top' => '16', 'right' => '16', 'bottom' => '16', 'left' => '16', 'isLinked' => true ] ], 'elements' => [
        [ 'id' => 'b123456', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Canary example heading', 'header_size' => 'h1', 'typography_typography' => 'custom', 'typography_font_size' => [ 'unit' => 'px', 'size' => 48.5 ], 'title_color' => '#204080' ], 'elements' => [] ],
        [ 'id' => 'c123456', 'elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => [ 'editor' => '<p>Canary example body.</p>', 'custom_css' => '.elementor-element-b123456{letter-spacing:0.25px;}' ], 'elements' => [] ],
        [ 'id' => 'd123456', 'elType' => 'widget', 'widgetType' => 'button', 'settings' => [ 'text' => 'Protected canary button', 'link' => [ 'url' => '#canary-details' ], 'background_color' => '#204080' ], 'elements' => [] ],
    ] ] ];
    $service = new \SEOR_Elementor_Bridge\Elementor_Service();
    $nova_canary_source_id = (int) $nova_canary_checked( $service->create_page( [ 'post_type' => 'page', 'title' => 'NOVA layout canary example ' . $nova_canary_suffix, 'slug' => $nova_canary_source_slug, 'status' => 'draft', 'elementor_data' => wp_json_encode( $native ), 'elementor_page_settings' => [ 'background_background' => 'classic', 'background_color' => '#f8fafc', 'custom_css' => '.elementor-element-b123456{margin-bottom:12.5px;} selector::before{content:"\\f00c";}' ], 'template' => 'elementor_canvas' ] ), 'Create temporary native example' );
    $source_post = get_post( $nova_canary_source_id );
    $nova_canary_assert( $source_post && $source_post->post_content_filtered === $nova_canary_source_marker && 'draft' === $source_post->post_status, 'Temporary example is an owned draft' );
    $nova_canary_created[ $nova_canary_source_id ] = [ 'kind' => 'example', 'marker' => $nova_canary_source_marker ];
    $field = static function ( string $label, string $type ): array { return [ 'mode' => 'adapt', 'source_path' => '', 'instructions' => 'Use the supplied canary content in this exact field.', 'required' => true, 'description' => [ 'label' => $label, 'purpose' => 'The ' . $label . ' of the new page.', 'value_type' => $type, 'constraints' => [] ] ]; };
    $selector = static function ( string $path, string $id, string $setting ): array { return [ 'path' => $path, 'builder' => 'elementor', 'selector_data' => [ 'element_id' => $id, 'path' => [ $setting ] ] ]; };
    $local = [ 'schema_version' => 1, 'revision' => $nova_canary_run, 'reference_type' => 'post', 'reference_id' => $nova_canary_source_id, 'signature' => 'canary-' . $nova_canary_run, 'catalog_mode' => 'destination', 'template' => [ 'id' => 'local-destination', 'revision' => '1' ], 'profile_page_type' => 'service', 'label' => 'Native stored-layout canary', 'guidance_mode' => 'set', 'guidance' => 'Distribute only the supplied content. Preserve native styling.', 'routing' => [ 'operation' => 'create', 'publication' => 'draft', 'post_type' => 'page', 'locale' => '' ], 'repeat_slots' => [], 'destination_groups' => [], 'fields' => [ '/title' => $field( 'Page title', 'text' ), '/headline' => $field( 'Hero heading', 'text' ), '/body' => $field( 'Body content', 'rich_text' ), '/button' => [ 'mode' => 'protected', 'source_path' => '', 'instructions' => '' ] ], 'target_descriptors' => [ '/title' => [ 'path' => '/title' ], '/headline' => $selector( '/headline', 'b123456', 'title' ), '/body' => $selector( '/body', 'c123456', 'editor' ), '/button' => $selector( '/button', 'd123456', 'text' ) ] ];
    $local['stored_layout'] = $nova_canary_checked( Nova_Bridge_Suite_Stored_Layout::capture( $local ), 'Capture native example' );
    $saved_blueprint = $local['stored_layout']['blueprint'];
    $nova_canary_assert( $saved_blueprint['document'][0]['settings']['background_overlay_opacity'] === 0.75 && $saved_blueprint['document'][0]['elements'][0]['settings']['typography_font_size']['size'] === 48.5, 'Capture preserves fractional native styling' );
    $nova_canary_checked( wp_delete_post( $nova_canary_source_id, true ), 'Delete owned example before delivery' );
    $nova_canary_assert( ! get_post( $nova_canary_source_id ), 'Example page is deleted before native delivery planning' );
    $nova_canary_report['cleanup']['deleted_post_ids'][] = $nova_canary_source_id;
    $language = str_replace( '_', '-', get_locale() );
    $route_home = home_url( '/' );
    if ( function_exists( 'pll_languages_list' ) ) {
        $locales = pll_languages_list( [ 'fields' => 'locale' ] ); $slugs = pll_languages_list( [ 'fields' => 'slug' ] );
        $index = array_search( 'nl_NL', $locales, true );
        if ( false !== $index && isset( $slugs[$index] ) ) { $language = 'nl-NL'; $route_home = pll_home_url( $slugs[$index] ); }
    }
    if ( has_filter( 'wpml_element_language_details' ) || defined( 'ICL_SITEPRESS_VERSION' ) ) { $default_language = apply_filters( 'wpml_default_language', null ); $languages = (array) apply_filters( 'wpml_active_languages', null, [ 'skip_missing' => 0 ] ); $language = str_replace( '_', '-', $languages[ $default_language ]['default_locale'] ?? $default_language ); }

    foreach ( [ 3, 1 ] as $version ) {
        $case_local = $local; $case_local['revision'] .= ':v' . $version;
        if ( 1 === $version ) { $case_local['catalog_mode'] = 'nova'; $case_local['guidance_mode'] = 'clear'; $case_local['guidance'] = ''; foreach ( [ '/title' => 'title', '/headline' => 'h1', '/body' => 'content' ] as $path => $source ) { $case_local['fields'][ $path ] = [ 'mode' => 'mapped', 'source_path' => $source, 'required' => true, 'instructions' => '' ]; } }
        $operation = wp_generate_uuid4(); $url_id = (string) random_int( 100000000000, 900000000000 ); $slug = 'nova-layout-canary-v' . $version . '-' . $nova_canary_suffix; $template_id = wp_generate_uuid4();
        $op_key = 'nova_layout_operation_' . hash( 'sha256', $operation ); $association_key = 'nova_layout_page_' . hash( 'sha256', $nova_canary_site . ':' . $url_id );
        $nova_canary_assert( null === get_option( $op_key, null ) && null === get_option( $association_key, null ), 'V' . $version . ' canary journal identities are unused' );
        $nova_canary_cases[ $operation ] = [ 'operation' => $operation, 'url_id' => $url_id, 'operation_key' => $op_key, 'association_key' => $association_key, 'slug' => $slug ];
        $input = $nova_canary_checked( Nova_Bridge_Suite_Writing_Adapter::template_input( $case_local ), 'Serialize V' . $version . ' mapping' );
        $nova_canary_assert( $input['definition']['version'] === $version && $input['mapping']['version'] === $version, 'V' . $version . ' serializer selects the requested contract' );
        $template = array_intersect_key( $input, array_flip( [ 'name', 'page_type', 'definition', 'mapping' ] ) ) + [ 'id' => $template_id, 'revision' => 1 ];
        $configuration = [ 'mode' => 'stored_layout_v3', 'site_id' => $nova_canary_site, 'template' => $template, 'local' => $case_local, 'profile_digest' => $nova_canary_digest( $case_local ) ];
        $title = 'Fresh native canary V' . $version . ' ' . $nova_canary_suffix; $heading = 'Independent stored-layout heading V' . $version; $body = '<p>New canary content for V' . $version . '. The example was deleted before this page was created.</p>';
        $content = [ 'id' => wp_generate_uuid4(), 'site_id' => $nova_canary_site, 'url_id' => $url_id, 'content_item_id' => (string) random_int( 100000000000, 900000000000 ), 'content_item_version_id' => (string) random_int( 100000000000, 900000000000 ), 'version_number' => 1, 'source_sha256' => hash( 'sha256', $body ), 'configuration' => $template, 'url' => rtrim( $route_home, '/' ) . '/' . $slug . '/', 'language' => $language, 'page_type_frontend' => 'service' ];
        if ( 3 === $version ) { $content['kind'] = 'adaptation.delivery'; $content['schema_version'] = 2; $content['destination_values'] = [ Nova_Bridge_Suite_Writing_Adapter::field_id( '/title' ) => $title, Nova_Bridge_Suite_Writing_Adapter::field_id( '/headline' ) => $heading, Nova_Bridge_Suite_Writing_Adapter::field_id( '/body' ) => $body ]; }
        else { $content['content'] = [ 'title' => $title, 'h1' => $heading, 'content' => $body ]; }
        $context = [ 'actor_user_id' => $nova_canary_actor, 'site_id' => $nova_canary_site, 'operation_id' => $operation, 'business_result_id' => $operation, 'attempt_id' => wp_generate_uuid4(), 'snapshot_sha256' => $nova_canary_digest( $content ), 'source_sha256' => $content['source_sha256'] ];
        $nova_canary_checked( Nova_Bridge_Suite_Stored_Layout_Writer::probe_mapping( $template, $template['mapping'], $case_local, $context ), 'Probe V' . $version . ' native lifecycle' );
        $plan = $nova_canary_checked( Nova_Bridge_Suite_Stored_Layout_Writer::plan( $content, $configuration, $context ), 'Plan V' . $version . ' source-free delivery' );
        $nova_canary_cases[ $operation ]['plan_digest'] = $plan['plan_digest']; $nova_canary_cases[ $operation ]['marker'] = Nova_Bridge_Suite_Stored_Layout_Writer::FORMAT . ':' . $operation . ':' . $plan['plan_digest'];
        $before = $nova_canary_checked( Nova_Bridge_Suite_Stored_Layout_Writer::recover( $plan, $operation ), 'Recover before V' . $version . ' creation' );
        $nova_canary_assert( ( $before['state'] ?? '' ) === 'not_committed' && ! empty( $before['safe_to_apply'] ), 'V' . $version . ' recovery confirms no previous native commit' );
        $result = $nova_canary_checked( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $plan, $operation ), 'Apply V' . $version . ' native draft' );
        $id = (int) $result['post_id']; $nova_canary_cases[ $operation ]['post_id'] = $id;
        $nova_canary_created[ $id ] = [ 'kind' => 'target', 'marker' => $nova_canary_cases[ $operation ]['marker'], 'operation' => $operation, 'url_id' => $url_id ];
        $post = get_post( $id ); $raw = get_post_meta( $id, '_elementor_data', true ); $stored = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
        $nova_canary_assert( $post && 'draft' === $post->post_status && $post->post_title === $title && $post->post_name === $slug && $id !== $nova_canary_source_id, 'V' . $version . ' creates one fresh draft with the planned native identity' );
        $nova_canary_assert( $nova_canary_digest( $stored ) === $nova_canary_digest( $plan['payload']['elementor_data'] ), 'V' . $version . ' saved complete native document matches its plan' );
        $nova_canary_assert( $stored[0]['elements'][0]['settings']['title'] === $heading && $stored[0]['elements'][1]['settings']['editor'] === $body && $stored[0]['elements'][2]['settings']['text'] === 'Protected canary button', 'V' . $version . ' fits content and preserves protected native content' );
        $nova_canary_assert( $stored[0]['settings'] === $saved_blueprint['document'][0]['settings'] && $stored[0]['elements'][0]['settings']['typography_font_size']['size'] === 48.5 && $stored[0]['elements'][0]['id'] !== 'b123456', 'V' . $version . ' preserves native styling with new element IDs' );
        $nova_canary_assert( $nova_canary_digest( get_post_meta( $id, '_elementor_page_settings', true ) ) === $nova_canary_digest( $plan['payload']['elementor_page_settings'] ) && get_page_template_slug( $id ) === 'elementor_canvas', 'V' . $version . ' preserves page settings and the native page template' );
        $again = $nova_canary_checked( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $plan, $operation ), 'Replay V' . $version . ' operation' );
        $recovered = $nova_canary_checked( Nova_Bridge_Suite_Stored_Layout_Writer::recover( $plan, $operation ), 'Recover committed V' . $version . ' operation' );
        $finished = $nova_canary_checked( Nova_Bridge_Suite_Stored_Layout_Writer::finish( $plan, $result ), 'Finish V' . $version . ' operation' );
        $verified = $nova_canary_checked( Nova_Bridge_Suite_Stored_Layout_Writer::verify( $plan, $result ), 'Verify V' . $version . ' operation' );
        $nova_canary_assert( (int) $again['post_id'] === $id && (int) $recovered['post_id'] === $id && (int) $finished['post_id'] === $id && (int) $verified['post_id'] === $id, 'V' . $version . ' replay, recovery, finish and verification retain the same native page' );
        global $wpdb; $same = $wpdb->get_col( $wpdb->prepare( 'SELECT ID FROM `' . $wpdb->posts . '` WHERE post_content_filtered=%s', $nova_canary_cases[ $operation ]['marker'] ) );
        $nova_canary_assert( count( $same ) === 1 && (int) $same[0] === $id && ! get_post( $nova_canary_source_id ), 'V' . $version . ' has no duplicate page and no surviving example dependency' );
        $nova_canary_report['cases'][] = [ 'version' => $version, 'post_id' => $id, 'status' => $post->post_status, 'planned_url' => $plan['target']['target_url'], 'source_deleted' => true, 'native_digest' => $verified['native_digest'] ];
    }
    $nova_canary_report['ok'] = true;
} catch ( Throwable $error ) {
    $nova_canary_report['error'] = $error->getMessage();
} finally {
    remove_action( 'wp_insert_post', $nova_canary_witness, PHP_INT_MAX ); remove_filter( 'wp_insert_post_data', $nova_canary_source_filter, PHP_INT_MAX );
    // Include uniquely witnessed reservations if a native call ended before returning its ID.
    global $wpdb;
    foreach ( $nova_canary_cases as $case ) { if ( empty( $case['marker'] ) ) { continue; } foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SELECT ID FROM `' . $wpdb->posts . '` WHERE post_content_filtered=%s AND post_type=%s AND post_author=%d', $case['marker'], 'page', $nova_canary_actor ) ) as $id ) { $nova_canary_created[ (int) $id ] = [ 'kind' => 'target', 'marker' => $case['marker'], 'operation' => $case['operation'], 'url_id' => $case['url_id'] ]; } }
    foreach ( $nova_canary_created as $id => $proof ) {
        try {
            $post = get_post( $id ); if ( ! $post ) { continue; }
            if ( 'page' !== $post->post_type || (int) $post->post_author !== $nova_canary_actor || $post->post_content_filtered !== $proof['marker'] ) { throw new RuntimeException( 'Ownership changed; retained post ' . $id ); }
            if ( 'target' === $proof['kind'] ) { $owner = get_post_meta( $id, '_nova_stored_layout_owner', true ); if ( $owner && ( ( $owner['site_id'] ?? '' ) !== $nova_canary_site || ( $owner['url_id'] ?? '' ) !== $proof['url_id'] || ( $owner['creation_operation'] ?? '' ) !== $proof['operation'] ) ) { throw new RuntimeException( 'Native owner changed; retained post ' . $id ); } }
            $deleted = wp_delete_post( $id, true ); if ( ! $deleted || get_post( $id ) ) { throw new RuntimeException( 'Could not remove owned canary post ' . $id ); }
            $nova_canary_report['cleanup']['deleted_post_ids'][] = $id;
        } catch ( Throwable $error ) { $nova_canary_report['cleanup']['errors'][] = $error->getMessage(); }
    }
    foreach ( $nova_canary_cases as $case ) {
        foreach ( [ 'operation_key', 'association_key' ] as $kind ) {
            try {
                foreach ( $nova_canary_created as $id => $proof ) { if ( ( $proof['operation'] ?? '' ) === $case['operation'] && get_post( $id ) ) { throw new RuntimeException( 'Canary page ' . $id . ' remains; retained its recovery journals.' ); } }
                $key = $case[ $kind ]; $saved = get_option( $key, null ); if ( null === $saved ) { continue; }
                $identity = 'operation_key' === $kind ? ( $saved['plan'] ?? [] ) : $saved;
                if ( ( $identity['operation_id'] ?? '' ) !== $case['operation'] || ( $identity['site_id'] ?? '' ) !== $nova_canary_site || ( 'operation_key' === $kind ? ( $identity['delivery_identity']['url_id'] ?? '' ) : ( $identity['url_id'] ?? '' ) ) !== $case['url_id'] ) { throw new RuntimeException( 'Journal ownership changed; retained option ' . $key ); }
                delete_option( $key ); if ( null !== get_option( $key, null ) ) { throw new RuntimeException( 'Could not remove owned canary option ' . $key ); }
                $nova_canary_report['cleanup']['deleted_options'][] = $key;
            } catch ( Throwable $error ) { $nova_canary_report['cleanup']['errors'][] = $error->getMessage(); }
        }
    }
    remove_filter( 'pre_http_request', $nova_canary_http, PHP_INT_MAX );
    $nova_canary_report['outbound_http_attempts_blocked'] = $nova_canary_network;
    $nova_canary_report['cleanup']['deleted_post_ids'] = array_values( array_unique( $nova_canary_report['cleanup']['deleted_post_ids'] ) );
    if ( $nova_canary_report['cleanup']['errors'] ) { $nova_canary_report['ok'] = false; }
}
echo wp_json_encode( $nova_canary_report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
if ( ! $nova_canary_report['ok'] ) { WP_CLI::halt( 1 ); }
