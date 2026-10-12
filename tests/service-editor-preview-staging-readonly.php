<?php
/** Actual WordPress preview/usage checks; never save a post or client option. */
use SEORAI\ServicePageCPT\ServiceDocument as Document;
use SEORAI\ServicePageCPT\ServiceShared as Shared;
use SEORAI\ServicePageCPT\ServiceSharedAdmin as Admin;
$checks = [];
$assert = static function ( $ok, $label ) use ( &$checks ) { if ( ! $ok ) { throw new RuntimeException( $label ); } $checks[] = $label; };
$admin = get_user_by( 'login', 'codex-cta-test' );
if ( ! $admin ) { throw new RuntimeException( 'Existing authorized test account is missing.' ); }
$snapshot = static function () {
    $state = [ 'posts' => [], 'options' => [] ];
    foreach ( get_posts( [ 'post_type' => 'service_page', 'post_status' => [ 'publish', 'draft', 'pending', 'private', 'future' ], 'posts_per_page' => -1, 'suppress_filters' => true ] ) as $post ) {
        $meta = get_post_meta( $post->ID ); unset( $meta['_edit_lock'] ); ksort( $meta );
        $state['posts'][$post->ID] = hash( 'sha256', serialize( [ $post, $meta ] ) );
    }
    ksort( $state['posts'] );
    foreach ( [ Shared::OPTION_PRESETS, Shared::OPTION_TEMPLATE, 'nova_bridge_settings', 'elementor_active_kit', 'elementor_google_font', 'elementor_local_google_fonts', '_elementor_local_google_fonts' ] as $key ) { $state['options'][$key] = serialize( get_option( $key ) ); }
    return $state;
};
$request = static function ( $payload ) {
    $r = new WP_REST_Request( 'POST', '/nova-service/v1/editor-preview' );
    $r->set_body_params( $payload ); return rest_do_request( $r );
};
$before = $snapshot();
$elementor_frontend = class_exists( '\\Elementor\\Plugin' ) ? \Elementor\Plugin::$instance->frontend : null;
$font_state = static function ( $frontend ) {
    $state = [];
    if ( ! $frontend ) { return $state; }
    foreach ( [ 'fonts_to_enqueue', 'registered_fonts', 'icon_fonts_to_enqueue', 'enqueued_icon_fonts' ] as $name ) {
        if ( ! property_exists( $frontend, $name ) ) { continue; }
        $property = new ReflectionProperty( $frontend, $name );
        $state[$name] = $property->getValue( $frontend );
    }
    return $state;
};
$font_state_before = $font_state( $elementor_frontend );
$font_option_filters = static function () { return isset( $GLOBALS['wp_filter']['pre_option_elementor_local_google_fonts'] ) ? $GLOBALS['wp_filter']['pre_option_elementor_local_google_fonts']->callbacks : []; };
$font_option_filters_before = $font_option_filters();
$expected_fonts = [];
if ( $elementor_frontend && class_exists( '\\Elementor\\Fonts' ) && \Elementor\Fonts::is_google_fonts_enabled() ) {
    $kit_id = (int) get_option( 'elementor_active_kit', 0 );
    // Independently use the active CSS metadata that the site's normal font
    // pipeline saves, instead of asserting a hardcoded family or copied collector.
    $kit_css = get_post_meta( $kit_id, '_elementor_css', true );
    foreach ( (array) ( $kit_css['fonts'] ?? [] ) as $family ) {
        if ( in_array( \Elementor\Fonts::get_font_type( $family ), [ \Elementor\Fonts::GOOGLE, \Elementor\Fonts::EARLYACCESS ], true ) ) { $expected_fonts[] = $family; }
    }
    $assert( ! empty( $expected_fonts ), 'The installed Elementor kit supplies real configured Google font families for preview checks' );
}
$assert_site_fonts = static function ( string $html, string $context ) use ( &$assert, $expected_fonts ) {
    foreach ( array_unique( $expected_fonts ) as $family ) {
        $handle = sanitize_key( $family );
        $assert( false !== strpos( $html, 'id="elementor-gf-' . $handle . '-css"' ) || false !== strpos( $html, 'id="elementor-gf-local-' . $handle . '-css"' ), $context . ' includes the configured font stylesheet for ' . $family );
    }
};
$page = Document::get_document( 5618 );
wp_set_current_user( 0 );
$response = $request( [ 'mode' => 'page', 'post_id' => 5618, 'document' => $page ] );
$assert( in_array( $response->get_status(), [ 401, 403 ], true ), 'Anonymous requests cannot preview private editor documents' );
wp_set_current_user( $admin->ID );
$assert( current_user_can( 'manage_options' ) && current_user_can( 'edit_post', 5618 ), 'Existing staging test account has the required permissions' );
foreach ( $page['sections'] as &$s ) { if ( 'reviews' === $s['type'] ) { $s['data'] = [ 'layout' => 'conversation' ]; } } unset( $s );
$page['sections'][] = [ 'id' => 'preview_only_form', 'type' => 'custom_cta', 'enabled' => true, 'data' => [ 'title' => 'UNSAVED PREVIEW MARKER', 'html' => '<form action="https://example.org/submit"><input name="email"><button type="submit">Preview form</button></form><script>window.top.location="https://example.org/side-effect"</script>', 'shortcode' => '[nova_editor_sideeffect_test]' ] ];
$GLOBALS['nova_editor_sideeffects'] = 0;
add_shortcode( 'nova_editor_sideeffect_test', static function () { ++$GLOBALS['nova_editor_sideeffects']; return 'SHOULD NOT RUN'; } );
$allow_fixture_shortcode = static function ( $names ) { $names[] = 'nova_editor_sideeffect_test'; return $names; };
add_filter( 'nova_service_allowed_shortcodes', $allow_fixture_shortcode );
try {
    $response = $request( [ 'mode' => 'page', 'post_id' => 5618, 'document' => $page, 'title' => 'Unsaved service title' ] );
    $data = $response->get_data(); $html = $data['html'] ?? '';
    if ( 200 !== $response->get_status() || '' === $html ) { echo wp_json_encode( [ 'status' => $response->get_status(), 'error' => $data ], JSON_PRETTY_PRINT ) . "\n"; exit( 1 ); }
    $assert( 200 === $response->get_status() && false !== stripos( $html, '<!doctype html' ), 'Unsaved page preview returns a standalone HTML document' );
    $assert( false !== strpos( $html, 'nova-service__layout--conversation' ), 'Unsaved review style renders before any save' );
    $assert( false !== strpos( $html, 'UNSAVED PREVIEW MARKER' ), 'New unsaved page section is visible' );
    $assert( false !== strpos( $html, 'Unsaved service title' ), 'Unsaved WordPress title participates in the preview' );
    $assert( false !== strpos( $html, 'service-frontend-v2.css' ), 'Preview loads the actual Service frontend stylesheet' );
    $assert( false !== strpos( $html, 'hello-elementor' ) || false !== strpos( $html, 'elementor' ), 'Preview includes site theme or brand styles' );
    $assert_site_fonts( $html, 'Unsaved page preview' );
    $assert( ! preg_match( '/<script\b/i', $html ), 'Preview never executes page or third-party scripts' );
    $assert( false === strpos( $html, 'action="https://example.org/submit"' ) && false === strpos( $html, 'href="https://example.org/side-effect"' ), 'Preview removes submission and navigation actions' );
    $assert( 0 === $GLOBALS['nova_editor_sideeffects'], 'Preview does not execute a custom shortcode' );
    $assert( false !== strpos( $html, '<details' ), 'Native expandable proof and FAQ controls remain available' );
    $assert( $before === $snapshot(), 'Page preview leaves all client posts, metadata and options unchanged' );
} finally { remove_shortcode( 'nova_editor_sideeffect_test' ); remove_filter( 'nova_service_allowed_shortcodes', $allow_fixture_shortcode ); }
$library = Shared::get_presets();
foreach ( $library['sections'] as &$s ) { if ( 'vital_example_reviews' === $s['id'] ) { $s['data']['layout'] = 'spotlight'; } } unset( $s );
$response = $request( [ 'mode' => 'library', 'post_id' => 5618, 'document' => $library ] );
$assert( 200 === $response->get_status() && false !== strpos( $response->get_data()['html'] ?? '', 'nova-service__layout--spotlight' ), 'Unsaved client defaults apply to their linked page in preview' );
$assert( $before === $snapshot(), 'Shared preview leaves saved client defaults and page documents unchanged' );
$sample = $request( [ 'mode' => 'library', 'post_id' => 0, 'document' => Shared::get_presets() ] );
$assert( 200 === $sample->get_status() && false !== strpos( $sample->get_data()['html'] ?? '', 'id="nova-service-v2"' ), 'Client defaults can preview without creating a service post' );
$assert_site_fonts( $sample->get_data()['html'] ?? '', 'Zero-post shared preview' );
$sample_layout = $request( [ 'mode' => 'template', 'post_id' => 0, 'document' => Shared::get_template() ?: Document::default_document(), 'title' => 'Unsaved onboarding font preview' ] );
$assert( 200 === $sample_layout->get_status(), 'Onboarding can preview its layout without a saved service target' );
$assert_site_fonts( $sample_layout->get_data()['html'] ?? '', 'Zero-post onboarding preview' );
$assert( $font_option_filters_before === $font_option_filters() && $before['options']['elementor_local_google_fonts'] === serialize( get_option( 'elementor_local_google_fonts' ) ), 'Font preview removes only its temporary local-download override without changing the saved font setting' );
$assert( ! $elementor_frontend || ( \Elementor\Plugin::$instance->frontend === $elementor_frontend && $font_state_before === $font_state( $elementor_frontend ) ), 'Preview leaves the original Elementor frontend identity and font queues unchanged' );
$assert( $before === $snapshot(), 'A sample preview creates no post and changes no settings' );
$template = Shared::get_template();
if ( count( $template['sections'] ) > 1 ) { $last = array_pop( $template['sections'] ); array_unshift( $template['sections'], $last ); }
$response = $request( [ 'mode' => 'template', 'post_id' => 5618, 'document' => $template ] );
$assert( 200 === $response->get_status(), 'Unsaved default-layout preview renders without saving' );
$assert( $before === $snapshot(), 'Layout preview leaves the default template and existing page order unchanged' );
foreach ( [ [ 'mode' => 'wrong', 'post_id' => 5618, 'document' => $page ], [ 'mode' => 'page', 'post_id' => 5618, 'document' => [ 'version' => 2, 'sections' => 'wrong' ] ], [ 'mode' => 'page', 'post_id' => 99999999, 'document' => $page ] ] as $bad ) {
    $response = $request( $bad ); $assert( $response->get_status() >= 400, 'Malformed or missing-target preview is rejected without a write' );
}
$deny_manage = static function ( $caps ) { $caps['manage_options'] = false; return $caps; };
add_filter( 'user_has_cap', $deny_manage );
try {
    $response = $request( [ 'mode' => 'library', 'post_id' => 5618, 'document' => $library ] );
    $assert( 403 === $response->get_status(), 'Editing a page alone does not authorize shared-default previews' );
} finally { remove_filter( 'user_has_cap', $deny_manage ); }
$context_method = new ReflectionMethod( Admin::class, 'preview_context' );
$context = $context_method->invoke( null, Shared::get_presets(), Shared::get_template() );
$targets = array_column( $context['previewTargets'], null, 'id' );
$assert( isset( $targets[5461], $targets[5618] ) && ! isset( $targets[168] ), 'Preview selector includes native examples and excludes legacy pages' );
$assert( array_column( $targets[5618]['sectionOrder'], 'id' ) === array_column( Document::get_document( 5618 )['sections'], 'id' ), 'Editor context follows the saved page section order' );
$assert( count( $context['presetUsage']['vital_example_reviews'] ) >= 1 && ! empty( $context['presetMeta']['default_reviews']['unused'] ), 'Settings distinguish used review presets from the unused default that caused confusion' );
$wrong_type_library = [ 'version' => 2, 'sections' => [ [ 'id' => 'vital_example_reviews', 'type' => 'credentials', 'enabled' => true, 'data' => [ 'body' => '' ] ] ] ];
$wrong_context = $context_method->invoke( null, $wrong_type_library, Shared::get_template() );
$wrong_targets = array_column( $wrong_context['previewTargets'], null, 'id' );
$assert( empty( $wrong_context['presetUsage']['vital_example_reviews'] ) && ! empty( $wrong_context['presetMeta']['vital_example_reviews']['unused'] ) && empty( $wrong_context['presetMeta']['vital_example_reviews']['usedByTemplate'] ) && ! in_array( 'vital_example_reviews', $wrong_targets[5618]['refKeys'], true ), 'Missing or wrong-type shared references are not falsely marked as used' );
$assert( $before === $snapshot(), 'All preview, validation, permissions and usage checks leave client storage unchanged' );
echo wp_json_encode( [ 'result' => 'PASS', 'read_only' => true, 'checks' => $checks ], JSON_PRETTY_PRINT ) . "\n";
