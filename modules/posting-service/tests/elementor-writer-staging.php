<?php
/** Temporary unpublished fixtures only: NOVA_ELEMENTOR_STAGING_CANARY=1 wp eval-file ... */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'NOVA_ELEMENTOR_STAGING_CANARY' ) ) { throw new RuntimeException( 'Explicit Elementor staging canary opt-in is required.' ); }
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-writing-adapter.php';
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-mapped-writer.php';
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-elementor-derived.php';
require_once __DIR__ . '/mapped-writer-fixture.php';
function nova_elementor_check( $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } }
function nova_elementor_result( $result, string $step ) { if ( is_wp_error( $result ) ) { throw new RuntimeException( $step . ': ' . $result->get_error_code() . ' ' . $result->get_error_message() ); } return $result; }
function nova_elementor_descriptor( array $field ): array { return array_intersect_key( $field, array_flip( [ 'path', 'transport', 'builder', 'write_mode', 'acf_key', 'binding', 'source', 'selector_data' ] ) ); }
$created = []; $old_user = get_current_user_id(); $actor = $old_user;
if ( ! $actor ) { $admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] ); $actor = (int) ( $admins[0] ?? 0 ); } nova_elementor_check( $actor > 0, 'Local actor required.' ); wp_set_current_user( $actor );
$suffix = strtolower( wp_generate_password( 10, false, false ) ); $css_files = [];
try {
    nova_elementor_result( Nova_Bridge_Suite_Elementor_Derived::register(), 'Exact provider source gate' );
    $tree = [ [ 'id' => 'a10a001', 'elType' => 'container', 'isInner' => false, 'settings' => [], 'elements' => [
        [ 'id' => 'a10a002', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Original generated heading', 'header_size' => 'h2' ], 'elements' => [] ],
        [ 'id' => 'a10a003', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Protected widget exact bytes', 'header_size' => 'h3' ], 'elements' => [] ],
        [ 'id' => 'a10a004', 'elType' => 'widget', 'widgetType' => 'button', 'settings' => [ 'text' => 'Keep on update', 'link' => [ 'url' => '/protected-destination/' ] ], 'elements' => [] ],
    ] ] ];
    foreach ( [ 'source', 'unrelated' ] as $purpose ) {
        $id = nova_elementor_result( wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'NOVA Elementor ' . $purpose . ' canary ' . $suffix, 'post_content' => 'Native source content', 'post_excerpt' => 'Protected native excerpt', 'post_author' => $actor ], true ), 'Create fixture' ); $id = (int) $id; $created[] = $id;
        update_post_meta( $id, '_elementor_edit_mode', 'builder' ); update_post_meta( $id, '_elementor_template_type', 'wp-page' ); update_post_meta( $id, '_elementor_version', ELEMENTOR_VERSION ); update_post_meta( $id, '_elementor_page_settings', [] ); update_post_meta( $id, '_elementor_data', wp_slash( wp_json_encode( $tree ) ) );
        update_post_meta( $id, '_elementor_css', [ 'status' => 'file', 'time' => time() ] ); update_post_meta( $id, '_elementor_element_cache', wp_slash( wp_json_encode( [ 'timeout' => time() + 3600, 'value' => [ 'content' => 'STALE CANARY CONTENT' ] ] ) ) );
        $uploads = wp_upload_dir( null, false ); $directory = $uploads['basedir'] . '/elementor/css'; wp_mkdir_p( $directory ); $path = $directory . '/post-' . $id . '.css';
        nova_elementor_check( ! file_exists( $path ), 'A new fixture unexpectedly owns an existing CSS file.' ); file_put_contents( $path, '/* isolated ' . $purpose . ' sentinel ' . $suffix . ' */' ); $css_files[ $purpose ] = $path;
        if ( 'source' === $purpose ) { $source = $id; } else { $unrelated = $id; }
    }
    $entity = Nova_Bridge_Suite_Strategy::entity( 'post', $source ); $signature = Nova_Bridge_Suite_Strategy::fingerprint( $entity )['signature'];
    $inventory = Nova_Bridge_Suite_Strategy::field_inventory( $entity ); $selected = [];
    foreach ( $inventory as $field ) { if ( isset( $field['selector_data']['field_key'] ) ) { $selected[ $field['selector_data']['field_key'] ] = $field; } }
    foreach ( [ 'a10a002|title', 'a10a003|title', 'a10a004|text' ] as $key ) { nova_elementor_check( isset( $selected[ $key ] ), 'Required real Elementor bridge field is absent: ' . $key ); }
    $fixture = nova_writer_fixture( $source, $signature, 'update', $suffix . '-elementor' ); $fixture['context']['actor_user_id'] = $actor;
    $generated = $selected['a10a002|title']; $protected = $selected['a10a003|title']; $empty = $selected['a10a004|text'];
    $local = &$fixture['configuration']['local']; unset( $local['fields']['/title'] );
    $local['fields'][ $generated['path'] ] = [ 'mode' => 'mapped', 'source_path' => 'heading' ]; $local['target_descriptors'][ $generated['path'] ] = nova_elementor_descriptor( $generated );
    $local['fields'][ $protected['path'] ] = [ 'mode' => 'protected', 'protected_slot' => 'retained_widget' ]; $local['target_descriptors'][ $protected['path'] ] = nova_elementor_descriptor( $protected );
    $local['fields'][ $empty['path'] ] = [ 'mode' => 'leave_empty' ]; $local['target_descriptors'][ $empty['path'] ] = nova_elementor_descriptor( $empty ); unset( $local );
    $fixture['configuration']['mapping']['bindings'][0]['target_descriptor']['target'] = nova_elementor_descriptor( $generated ); $fixture['configuration']['template']['protected_identities'][] = 'retained_widget'; nova_writer_fixture_policy( $fixture['configuration'] );
    $unrelated_css = file_get_contents( $css_files['unrelated'] ); $unrelated_meta = get_post_meta( $unrelated, '_elementor_css', true );
    // Prime the provider's request-local document cache before mutation.
    \Elementor\Plugin::instance()->documents->get( $source ); \Elementor\Core\Files\CSS\Post::create( $source );
    $plan = nova_elementor_result( Nova_Bridge_Suite_Mapped_Writer::plan( $fixture['content'], $fixture['configuration'], $fixture['context'] ), 'Elementor plan' );
    $committed = nova_elementor_result( Nova_Bridge_Suite_Mapped_Writer::apply( $plan, $fixture['context']['operation_id'] ), 'Elementor apply' );
    $complete = nova_elementor_result( Nova_Bridge_Suite_Mapped_Writer::finish( $plan, $committed ), 'Elementor finish' ); nova_elementor_result( Nova_Bridge_Suite_Mapped_Writer::verify( $plan, $complete ), 'Elementor verify' );
    $raw = get_post_meta( $source, '_elementor_data', true ); $after = json_decode( $raw, true );
    nova_elementor_check( $after[0]['elements'][0]['settings']['title'] === 'Generated fixture heading' && $after[0]['elements'][1] === $tree[0]['elements'][1] && $after[0]['elements'][2] === $tree[0]['elements'][2], 'Generated/protected/Leave empty widget semantics failed.' );
    nova_elementor_check( ! file_exists( $css_files['source'] ) && ! metadata_exists( 'post', $source, '_elementor_css' ) && ! metadata_exists( 'post', $source, '_elementor_element_cache' ), 'Target derived caches were not invalidated.' );
    nova_elementor_check( file_get_contents( $css_files['unrelated'] ) === $unrelated_css && get_post_meta( $unrelated, '_elementor_css', true ) === $unrelated_meta, 'Adapter changed unrelated document caches.' );
    $html = \Elementor\Plugin::instance()->frontend->get_builder_content( $source, false );
    nova_elementor_check( strpos( $html, 'Generated fixture heading' ) !== false && strpos( $html, 'Protected widget exact bytes' ) !== false && strpos( $html, 'STALE CANARY CONTENT' ) === false, 'Actual provider render did not read the verified native update.' );
    $css = new \Elementor\Core\Files\CSS\Post( $source ); $css->update(); nova_elementor_check( metadata_exists( 'post', $source, '_elementor_css' ), 'Actual provider did not regenerate its document CSS metadata.' );
    $clone = $fixture; $clone['configuration']['local']['routing']['operation'] = 'clone'; $clone['context']['operation'] = 'clone'; $clone['context']['operation_id'] .= '-clone'; $clone['content']['content_id'] .= '-clone'; $clone['context']['content_id'] = $clone['content']['content_id']; $clone['content']['fields']['slug'] .= '-clone'; nova_writer_fixture_policy( $clone['configuration'] );
    $clone_plan = nova_elementor_result( Nova_Bridge_Suite_Mapped_Writer::plan( $clone['content'], $clone['configuration'], $clone['context'] ), 'Elementor clone plan' );
    $clone_commit = nova_elementor_result( Nova_Bridge_Suite_Mapped_Writer::apply( $clone_plan, $clone['context']['operation_id'] ), 'Elementor clone apply' ); $created[] = (int) $clone_commit['post_id'];
    $clone_recovered = nova_elementor_result( Nova_Bridge_Suite_Mapped_Writer::recover( $clone_plan, $clone['context']['operation_id'] ), 'Elementor clone recovery' );
    $clone_complete = nova_elementor_result( Nova_Bridge_Suite_Mapped_Writer::finish( $clone_plan, $clone_recovered ), 'Elementor clone finish' ); nova_elementor_result( Nova_Bridge_Suite_Mapped_Writer::verify( $clone_plan, $clone_complete ), 'Elementor clone verify' );
    $cloned_tree = json_decode( get_post_meta( $clone_complete['post_id'], '_elementor_data', true ), true );
    nova_elementor_check( 'draft' === get_post_status( $clone_complete['post_id'] ) && $cloned_tree[0]['elements'][1] === $tree[0]['elements'][1] && '' === $cloned_tree[0]['elements'][2]['settings']['text'] && $cloned_tree[0]['elements'][2]['settings']['link'] === $tree[0]['elements'][2]['settings']['link'], 'Clone changed protected component or omitted Leave empty.' );
    $clone_html = \Elementor\Plugin::instance()->frontend->get_builder_content( $clone_complete['post_id'], false ); nova_elementor_check( strpos( $clone_html, 'Generated fixture heading' ) !== false && strpos( $clone_html, 'Protected widget exact bytes' ) !== false, 'Actual provider clone render failed.' );
    echo wp_json_encode( [ 'ok' => true, 'provider' => ELEMENTOR_VERSION, 'checks' => [ 'exact provider source hashes', 'native widget identity and protected bytes', 'update skips Leave empty', 'target-only cache invalidation', 'actual frontend render and CSS regeneration', 'unpublished complete clone + marker recovery', 'clone blanks only selected Leave empty setting' ], 'temporary_post_ids' => $created ], JSON_PRETTY_PRINT ) . "\n";
} finally { foreach ( array_unique( $created ) as $id ) { wp_delete_post( $id, true ); } foreach ( $css_files as $path ) { if ( file_exists( $path ) ) { unlink( $path ); } } wp_set_current_user( $old_user ); }
