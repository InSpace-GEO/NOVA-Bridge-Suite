<?php
/**
 * Explicitly authorized, retained draft canary. Run via wp eval-file, outside the web root.
 * Required: NOVA_RULE_STAGING_CANARY=1 and NOVA_RULE_STAGING_ORIGIN=<approved home_url>.
 * Optional: NOVA_RULE_STAGING_RUN_UUID=<UUID> to replay the exact same fixture operations.
 * This script never binds a profile, contacts posting/backend, publishes, or deletes a page.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || getenv( 'NOVA_RULE_STAGING_CANARY' ) !== '1' ) { throw new RuntimeException( 'This retained draft canary requires WP-CLI and NOVA_RULE_STAGING_CANARY=1.' ); }
$nova_rule_origin = rtrim( (string) getenv( 'NOVA_RULE_STAGING_ORIGIN' ), '/' );
if ( '' === $nova_rule_origin || $nova_rule_origin !== rtrim( home_url( '/' ), '/' ) ) { WP_CLI::error( 'The explicit approved staging origin must exactly match this WordPress home URL. No canary mutation was attempted.' ); }
if ( ! class_exists( 'Nova_Bridge_Suite_Content_Rules' ) || ! class_exists( 'Nova_Bridge_Suite_Rule_Writer' ) || ! class_exists( '\\Elementor\\Plugin' ) || ! class_exists( '\\SEOR_Elementor_Bridge\\Elementor_Service' ) ) { WP_CLI::error( 'Enable the updated NOVA context/Elementor bridges and Elementor before this canary. No draft was created.' ); }
if ( (string) getenv( 'NOVA_RULE_STAGING_RETAIN' ) !== '' && getenv( 'NOVA_RULE_STAGING_RETAIN' ) !== '1' ) { WP_CLI::error( 'This canary retains its labelled drafts. Cleanup is a separately reviewed operation.' ); }
$nova_rule_run = (string) getenv( 'NOVA_RULE_STAGING_RUN_UUID' );
if ( '' === $nova_rule_run ) { $nova_rule_run = wp_generate_uuid4(); }
if ( ! Nova_Bridge_Suite_Content_Rules::uuid( $nova_rule_run ) ) { WP_CLI::error( 'The canary run identity must be a UUID.' ); }
$nova_rule_previous_user = get_current_user_id();
if ( ! $nova_rule_previous_user ) {
    $nova_rule_admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID', 'orderby' => 'ID', 'order' => 'ASC' ] );
    if ( ! $nova_rule_admins ) { WP_CLI::error( 'This canary requires an existing administrator. It does not create or modify users.' ); }
    wp_set_current_user( (int) $nova_rule_admins[0] );
}
if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_pages' ) ) { wp_set_current_user( $nova_rule_previous_user ); WP_CLI::error( 'The selected CLI user must be an existing administrator with page creation permission.' ); }

function nova_rule_canary_uuid( string $run, string $part ): string {
    $hex = hash( 'sha256', 'nova-rule-staging:' . $run . ':' . $part );
    return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-4' . substr( $hex, 13, 3 ) . '-8' . substr( $hex, 17, 3 ) . '-' . substr( $hex, 20, 12 );
}
function nova_rule_canary_nodes( array $elements ): array {
    $all = [];
    foreach ( $elements as $element ) { $all[] = $element; $all = array_merge( $all, nova_rule_canary_nodes( $element['elements'] ?? [] ) ); }
    return $all;
}
function nova_rule_canary_check( bool $condition, string $name, array &$checks ): void {
    if ( ! $condition ) { throw new RuntimeException( 'canary_check:' . $name ); }
    $checks[] = $name;
}
function nova_rule_canary_result( $value ) {
    if ( is_wp_error( $value ) ) { throw new RuntimeException( 'canary_plugin:' . $value->get_error_code() ); }
    return $value;
}

$nova_rule_prefix = 'NOVA rules canary ' . substr( $nova_rule_run, 0, 8 );
$nova_rule_profile = Nova_Bridge_Suite_Content_Rules::preset();
$nova_rule_profile['id'] = nova_rule_canary_uuid( $nova_rule_run, 'profile' );
$nova_rule_profile['revision'] = 1; $nova_rule_profile['label'] = $nova_rule_prefix . ' local profile';
$nova_rule_profile['rules']['heading']['settings'] = [ 'typography_font_size' => 28, 'line_height' => 1.35, 'color' => '#1b5e9d', 'align' => 'left' ];
$nova_rule_profile['rules']['paragraph']['settings'] = [ 'typography_font_size' => 16, 'line_height' => 1.6, 'color' => '#202934' ];
$nova_rule_profile['rules']['image']['settings'] = [ 'align' => 'center' ];
$nova_rule_image = home_url( '/wp-includes/images/blank.gif' ); $nova_rule_alt = 'NOVA canary alt & "quoted"';
$nova_rule_long = '';
for ( $nova_rule_i = 1; $nova_rule_i <= 12; ++$nova_rule_i ) { $nova_rule_long .= '<h2>Canary long section ' . $nova_rule_i . '</h2><p>Canary long paragraph ' . $nova_rule_i . ' with <strong>native formatting</strong>.</p>'; }
$nova_rule_matrix_rows = [
    [ 'children' => [ [ 'type' => 'heading', 'level' => 3, 'text' => 'Canary row one' ], [ 'type' => 'paragraph', 'html' => 'Canary row one <em>answer</em>.' ] ] ],
    [ 'children' => [ [ 'type' => 'group', 'children' => [ [ 'type' => 'heading', 'level' => 3, 'text' => 'Canary row two' ], [ 'type' => 'paragraph', 'text' => 'Canary row two answer.' ] ] ] ] ],
];
$nova_rule_nested = [ [ 'type' => 'group', 'children' => [ [ 'type' => 'heading', 'level' => 2, 'text' => 'Canary matrix heading' ], [ 'type' => 'repeat', 'items' => $nova_rule_matrix_rows ] ] ] ];
$nova_rule_faqs = [ [ 'question' => 'Canary FAQ question one?', 'answer' => '<p>Canary FAQ answer one with <strong>formatting</strong>.</p>' ], [ 'question' => 'Canary FAQ question two?', 'answer' => '<p>Canary FAQ answer two.</p>' ], [ 'question' => 'Canary FAQ question three?', 'answer' => '<p>Canary FAQ answer three.</p>' ] ];
$nova_rule_cases = [
    'short-html' => [ 'profile' => $nova_rule_profile, 'input' => [ 'title' => $nova_rule_prefix . " short: O'Reilly \\path 😀", 'html' => '<h2 id="nova-canary-anchor">Canary short heading <em>formatted</em></h2><p>Canary short paragraph with <a href="' . esc_attr( home_url( '/' ) ) . '">native link</a>.</p><h3>Canary second heading</h3><p>Canary second paragraph.</p><img src="' . esc_attr( $nova_rule_image ) . '" alt="' . esc_attr( $nova_rule_alt ) . '">' ], 'needles' => [ 'Canary short heading', 'Canary second paragraph' ] ],
    'long-html' => [ 'profile' => $nova_rule_profile, 'input' => [ 'title' => $nova_rule_prefix . ' long article', 'html' => $nova_rule_long ], 'needles' => [ 'Canary long section 12', 'Canary long paragraph 12' ] ],
    'nested-faq-accordion' => [ 'profile' => $nova_rule_profile, 'input' => [ 'title' => $nova_rule_prefix . ' nested FAQ accordion', 'blocks' => $nova_rule_nested, 'faqs' => $nova_rule_faqs ], 'needles' => [ 'Canary row two', 'Canary FAQ question three?', 'Canary FAQ answer three.' ] ],
    'nested-faq-container' => [ 'profile' => $nova_rule_profile, 'input' => [ 'title' => $nova_rule_prefix . ' nested FAQ containers', 'blocks' => $nova_rule_nested, 'faqs' => $nova_rule_faqs ], 'needles' => [ 'Canary row two', 'Canary FAQ question three?', 'Canary FAQ answer three.' ] ],
];
$nova_rule_cases['nested-faq-container']['profile']['id'] = nova_rule_canary_uuid( $nova_rule_run, 'container-profile' );
$nova_rule_cases['nested-faq-container']['profile']['rules']['faq']['element'] = 'container';
$nova_rule_operations = [];
foreach ( $nova_rule_cases as $nova_rule_case => $_nova_rule_case ) { $nova_rule_operations[ nova_rule_canary_uuid( $nova_rule_run, $nova_rule_case ) ] = true; }
$nova_rule_existing_store = get_option( 'nova_bridge_content_rules_v1', false );
$nova_rule_http_count = 0; $nova_rule_save_counts = [];
$nova_rule_deny_http = static function () use ( &$nova_rule_http_count ) { ++$nova_rule_http_count; return new WP_Error( 'nova_rule_canary_http_blocked', 'All outbound HTTP is disabled during the draft canary.' ); };
$nova_rule_force_draft = static function ( array $data, array $postarr, array $unsanitized ) use ( $nova_rule_operations ) {
    $marker = $unsanitized['post_content_filtered'] ?? $data['post_content_filtered'] ?? '';
    foreach ( $nova_rule_operations as $operation => $_ ) { if ( strpos( $marker, Nova_Bridge_Suite_Rule_Writer::FORMAT . ':' . $operation . ':' ) === 0 && ( $data['post_type'] ?? '' ) !== 'revision' ) { $data['post_status'] = 'draft'; break; } }
    return $data;
};
$nova_rule_capture_save = static function ( $id ) use ( &$nova_rule_save_counts, $nova_rule_operations ) {
    $post = get_post( $id ); $marker = $post ? $post->post_content_filtered : '';
    foreach ( $nova_rule_operations as $operation => $_ ) { if ( strpos( $marker, Nova_Bridge_Suite_Rule_Writer::FORMAT . ':' . $operation . ':' ) === 0 ) { $nova_rule_save_counts[ (int) $id ] = ( $nova_rule_save_counts[ (int) $id ] ?? 0 ) + 1; break; } }
};
add_filter( 'pre_http_request', $nova_rule_deny_http, PHP_INT_MAX, 0 );
add_filter( 'wp_insert_post_data', $nova_rule_force_draft, PHP_INT_MAX, 3 );
add_action( 'elementor/editor/after_save', $nova_rule_capture_save, PHP_INT_MAX, 1 );
$nova_rule_output = [ 'canary' => 'nova-content-rules-native-staging/v1', 'run_uuid' => $nova_rule_run, 'origin' => $nova_rule_origin, 'retained' => true, 'versions' => [ 'wordpress' => get_bloginfo( 'version' ), 'elementor' => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '', 'bridge' => defined( 'NOVA_BRIDGE_SUITE_VERSION' ) ? NOVA_BRIDGE_SUITE_VERSION : '', 'php' => PHP_VERSION ], 'cases' => [], 'checks' => [], 'errors' => [] ];
try {
    foreach ( $nova_rule_cases as $nova_rule_case => $nova_rule_fixture ) {
        $operation = nova_rule_canary_uuid( $nova_rule_run, $nova_rule_case ); $checks = []; $case_result = [ 'name' => $nova_rule_case, 'operation_uuid' => $operation, 'profile_id' => $nova_rule_fixture['profile']['id'], 'retained' => true ];
        try {
            $profile = $nova_rule_fixture['profile']; $input = $nova_rule_fixture['input'];
            $expected = nova_rule_canary_result( Nova_Bridge_Suite_Content_Rules::render( $profile, $input, $operation ) );
            $created = nova_rule_canary_result( Nova_Bridge_Suite_Rule_Writer::create_draft( $profile, $input, $operation ) );
            $id = (int) ( $created['post_id'] ?? 0 ); $post = get_post( $id );
            nova_rule_canary_check( $id > 0 && $post && $post->post_status === 'draft' && $post->post_title === $input['title'], 'labelled-draft-title-and-status', $checks );
            $case_result += [ 'post_id' => $id, 'status' => 'draft', 'edit_url' => $created['edit_url'], 'elementor_edit_url' => $created['elementor_edit_url'] ?? '', 'preview_url' => $created['preview_url'] ?? get_preview_post_link( $id ) ];
            $saved = json_decode( get_post_meta( $id, '_elementor_data', true ), true );
            nova_rule_canary_check( is_array( $saved ) && json_last_error() === JSON_ERROR_NONE, 'readable-native-elementor-document', $checks );
            $nodes = nova_rule_canary_nodes( $saved ); $expected_nodes = nova_rule_canary_nodes( $expected['elements'] );
            nova_rule_canary_check( array_column( $nodes, 'id' ) === array_column( $expected_nodes, 'id' ) && count( $nodes ) === count( array_unique( array_column( $nodes, 'id' ) ) ), 'ordered-unique-native-widget-identities', $checks );
            $headings = array_values( array_filter( $nodes, static function ( $node ) { return ( $node['widgetType'] ?? '' ) === 'heading'; } ) );
            nova_rule_canary_check( $headings && $headings[0]['settings']['title_color'] === '#1b5e9d' && (float) $headings[0]['settings']['typography_font_size']['size'] === 28.0, 'native-heading-style-controls', $checks );
            if ( $nova_rule_case === 'long-html' ) { nova_rule_canary_check( count( $headings ) === 12 && count( array_filter( $nodes, static function ( $node ) { return ( $node['elType'] ?? '' ) === 'widget'; } ) ) === 24, 'variable-article-expands-to-24-native-widgets', $checks ); }
            if ( $nova_rule_case === 'nested-faq-accordion' ) {
                $accordions = array_values( array_filter( $nodes, static function ( $node ) { return ( $node['widgetType'] ?? '' ) === 'accordion'; } ) );
                nova_rule_canary_check( count( $accordions ) === 1 && count( $accordions[0]['settings']['tabs'] ) === 3, 'nested-native-accordion-faq-items', $checks );
            }
            if ( $nova_rule_case === 'nested-faq-container' ) { nova_rule_canary_check( count( $headings ) === 6 && count( array_filter( $nodes, static function ( $node ) { return ( $node['elType'] ?? '' ) === 'container'; } ) ) >= 8, 'nested-native-container-faq-components', $checks ); }
            $save_count = $nova_rule_save_counts[ $id ] ?? 0;
            $retry = nova_rule_canary_result( Nova_Bridge_Suite_Rule_Writer::create_draft( $profile, $input, $operation ) );
            nova_rule_canary_check( $retry['post_id'] === $id && ( $nova_rule_save_counts[ $id ] ?? 0 ) === $save_count, 'same-operation-retry-without-second-native-save', $checks );
            $record = get_option( 'nova_rule_operation_' . hash( 'sha256', $operation ), null );
            nova_rule_canary_check( is_array( $record ) && $record['state'] === 'committed' && $record['post_id'] === $id, 'durable-known-native-operation', $checks );
            $recover = nova_rule_canary_result( Nova_Bridge_Suite_Rule_Writer::recover( $record['plan'], $operation ) );
            $verify = nova_rule_canary_result( Nova_Bridge_Suite_Rule_Writer::verify( $record['plan'], $recover ) );
            nova_rule_canary_check( $verify['post_id'] === $id && $verify['cms_post_status'] === 'draft' && ( $nova_rule_save_counts[ $id ] ?? 0 ) === $save_count, 'native-recovery-and-readback-without-publication', $checks );
            $plugin = \Elementor\Plugin::instance();
            if ( ! isset( $plugin->frontend ) || ! is_callable( [ $plugin->frontend, 'get_builder_content_for_display' ] ) ) { throw new RuntimeException( 'canary_check:native-frontend-api' ); }
            $html = $plugin->frontend->get_builder_content_for_display( $id, true );
            nova_rule_canary_check( is_string( $html ) && strlen( $html ) > 0, 'real-native-frontend-render', $checks );
            $text = html_entity_decode( strip_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            foreach ( $nova_rule_fixture['needles'] as $needle ) { nova_rule_canary_check( strpos( $text, $needle ) !== false, 'frontend-content-' . count( $checks ), $checks ); }
            if ( $nova_rule_case === 'short-html' ) {
                $images = array_values( array_filter( $nodes, static function ( $node ) { return ( $node['widgetType'] ?? '' ) === 'image'; } ) );
                nova_rule_canary_check( count( $images ) === 1 && ( $images[0]['settings']['image']['alt'] ?? null ) === $nova_rule_alt, 'native-image-alt-setting', $checks );
                $dom = new DOMDocument(); $prior = libxml_use_internal_errors( true ); $dom->loadHTML( '<?xml encoding="UTF-8">' . $html ); libxml_clear_errors(); libxml_use_internal_errors( $prior ); $found_alt = false;
                foreach ( $dom->getElementsByTagName( 'img' ) as $img ) { if ( $img->getAttribute( 'src' ) === $nova_rule_image && $img->getAttribute( 'alt' ) === $nova_rule_alt ) { $found_alt = true; } }
                nova_rule_canary_check( $found_alt && strpos( $html, 'nova-canary-anchor' ) !== false, 'frontend-image-alt-and-anchor', $checks );
            }
            nova_rule_canary_check( get_post_status( $id ) === 'draft', 'draft-status-after-frontend-render', $checks );
            $case_result += [ 'native_nodes' => count( $nodes ), 'native_widgets' => count( array_filter( $nodes, static function ( $node ) { return ( $node['elType'] ?? '' ) === 'widget'; } ) ), 'frontend_bytes' => strlen( $html ), 'frontend_sha256' => hash( 'sha256', $html ), 'native_save_count' => $save_count, 'checks' => $checks, 'passed' => true ];
        } catch ( Throwable $error ) {
            $detail = $error->getMessage();
            $code = preg_match( '/^canary_(?:check|plugin):([a-zA-Z0-9_.:-]{1,160})$/D', $detail, $match ) ? $match[1] : 'native_canary_interrupted';
            $nova_rule_output['errors'][] = [ 'case' => $nova_rule_case, 'code' => $code ];
            $record = get_option( 'nova_rule_operation_' . hash( 'sha256', $operation ), null );
            $retained_id = is_array( $record ) ? (int) ( $record['post_id'] ?? 0 ) : 0;
            if ( ! $retained_id && is_array( $record ) && preg_match( '/^[a-f0-9]{64}$/D', $record['plan_digest'] ?? '' ) ) {
                global $wpdb;
                $marker = Nova_Bridge_Suite_Rule_Writer::FORMAT . ':' . $operation . ':' . $record['plan_digest'];
                $ids = $wpdb->get_col( $wpdb->prepare( 'SELECT ID FROM ' . $wpdb->posts . ' WHERE post_content_filtered=%s AND post_type=%s ORDER BY ID', $marker, 'page' ) );
                if ( count( $ids ) === 1 ) { $retained_id = (int) $ids[0]; }
            }
            if ( $retained_id ) { $case_result['post_id'] = $retained_id; $case_result['status'] = get_post_status( $retained_id ); $case_result['edit_url'] = get_edit_post_link( $retained_id, 'raw' ); $case_result['preview_url'] = get_preview_post_link( $retained_id ); }
            $case_result['passed'] = false; $case_result['checks'] = $checks;
        }
        $nova_rule_output['cases'][] = $case_result;
    }
    nova_rule_canary_check( get_option( 'nova_bridge_content_rules_v1', false ) === $nova_rule_existing_store, 'existing-profile-and-binding-store-unchanged', $nova_rule_output['checks'] );
    if ( function_exists( 'nova_rule_canary_assert_configuration_unchanged' ) ) {
        nova_rule_canary_assert_configuration_unchanged();
        $nova_rule_output['checks'][] = 'raw-site-configuration-unchanged';
    }
} catch ( Throwable $error ) { $nova_rule_output['errors'][] = [ 'case' => 'scope', 'code' => 'canary_scope_check_failed' ]; }
finally {
    remove_filter( 'pre_http_request', $nova_rule_deny_http, PHP_INT_MAX ); remove_filter( 'wp_insert_post_data', $nova_rule_force_draft, PHP_INT_MAX ); remove_action( 'elementor/editor/after_save', $nova_rule_capture_save, PHP_INT_MAX );
    wp_set_current_user( $nova_rule_previous_user );
}
$nova_rule_output['blocked_http_requests'] = $nova_rule_http_count;
$nova_rule_output['bootstrap_blocked_http_requests'] = (int) ( $GLOBALS['nova_rule_canary_blocked_http_count'] ?? 0 );
$nova_rule_output['passed'] = ! $nova_rule_output['errors'];
echo wp_json_encode( $nova_rule_output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
if ( ! $nova_rule_output['passed'] ) { WP_CLI::halt( 1 ); }
