<?php
/** Standalone lifecycle checks. In-memory WordPress options only; no CMS/network writes. */
define( 'ABSPATH', __DIR__ );
define( 'NOVA_BRIDGE_SUITE_OPTION', 'nova_bridge_settings' );
define( 'NOVA_BRIDGE_SUITE_VERSION_OPTION', 'nova_bridge_suite_version' );
define( 'NOVA_BRIDGE_SUITE_VERSION', '3.0.0' );
$options = []; $actions = []; $network_plugins = []; $multisite = false; $theme = ''; $sections = []; $fields = []; $flushes = 0; $cpt_calls = []; $checks = 0;
function get_option( $key, $default = false ) { global $options; return array_key_exists( $key, $options ) ? $options[ $key ] : $default; }
function add_option( $key, $value, $deprecated = '', $autoload = null ) { global $options; if ( array_key_exists( $key, $options ) ) { return false; } $options[ $key ] = $value; return true; }
function update_option( $key, $value, $autoload = null ) { global $options, $actions; $existed = array_key_exists( $key, $options ); $old = get_option( $key ); $options[ $key ] = $value; if ( $existed && $old !== $value ) { foreach ( $actions[ 'update_option_' . $key ] ?? [] as $callback ) { $callback( $old, $value, $key ); } } return $old !== $value; }
function get_site_option( $key, $default = false ) { global $network_plugins; return 'active_sitewide_plugins' === $key ? $network_plugins : $default; }
function is_multisite() { global $multisite; return $multisite; }
function get_template() { global $theme; return $theme; }
function use_block_editor_for_post_type( $post_type ) { return true; }
function wp_parse_args( $value, $defaults ) { return array_merge( $defaults, $value ); }
function add_action( $name, $callback, $priority = 10, $accepted = 1 ) { global $actions; $actions[ $name ][] = $callback; }
function register_setting( ...$args ) {}
function add_settings_section( $id, $title, $callback, $page ) { global $sections; $sections[ $id ] = $title; }
function add_settings_field( $id, $title, $callback, $page, $section, $args = [] ) { global $fields; $fields[ $id ] = [ 'section' => $section, 'args' => $args ]; }
function esc_html( $value ) { return htmlspecialchars( $value, ENT_QUOTES ); }
function esc_attr( $value ) { return htmlspecialchars( $value, ENT_QUOTES ); }
function esc_html__( $value, $domain = '' ) { return esc_html( $value ); }
function checked( $checked, $current = true, $echo = true ) { return $checked === $current ? 'checked="checked"' : ''; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) ); }
function flush_rewrite_rules( $hard = true ) { global $flushes; ++$flushes; }
function nova_bridge_suite_load_settings_runtime(): void {}
function nova_bridge_suite_handle_cpt_toggle( bool $old, bool $new ): void { global $cpt_calls; $cpt_calls[] = [ 'cpt', $old, $new ]; }
function nova_bridge_suite_handle_service_cpt_toggle( bool $old, bool $new ): void { global $cpt_calls; $cpt_calls[] = [ 'service', $old, $new ]; }
function check( $value, $message ) { global $checks; ++$checks; if ( ! $value ) { throw new RuntimeException( $message ); } }
require dirname( __DIR__ ) . '/includes/settings.php';
$source = file_get_contents( dirname( __DIR__ ) . '/nova-bridge-suite.php' );
$start = strpos( $source, 'function nova_bridge_suite_activate(): void' );
$end = strpos( $source, 'function nova_bridge_suite_handle_cpt_toggle(', $start );
eval( substr( $source, $start, $end - $start ) );
add_action( 'update_option_' . NOVA_BRIDGE_SUITE_OPTION, 'nova_bridge_suite_settings_updated', 10, 3 );
function reset_fixture( array $stored = [], string $active_theme = 'Avada' ): void {
    global $options, $theme, $network_plugins, $multisite;
    // Keep the active standalone conflict stable because the production conflict
    // inventory is cached within a WordPress request. All other inputs are fresh.
    $options = [ 'active_plugins' => [ 'elementor/elementor.php', 'js_composer/js_composer.php', 'breakdance/plugin.php', 'beaver-builder-lite-version/fl-builder.php', 'gutenberg/gutenberg.php', 'polylang-pro/polylang.php', 'weglot/weglot.php', 'standalone/nova-avada-bridge.php' ] ] + $stored;
    $theme = $active_theme; $multisite = true; $network_plugins = [ 'sitepress-multilingual-cms/sitepress.php' => 1 ];
}
reset_fixture();
$before = $options;
check( 0 === nova_bridge_suite_get_settings()['api_mapping_context'], 'A settings read before activation enabled API Mapping Context' );
check( $before === $options, 'A settings read wrote install defaults or provenance' );
nova_bridge_suite_activate();
$installed = get_option( NOVA_BRIDGE_SUITE_OPTION );
foreach ( [ 'api_mapping_context', 'pagebuilder_elementor', 'pagebuilder_wpbakery', 'pagebuilder_breakdance', 'pagebuilder_beaver', 'gutenberg_bridge', 'multilingual_wpml', 'multilingual_polylang', 'multilingual_weglot' ] as $key ) { check( 1 === $installed[ $key ], 'A supported active builder/language bridge was not enabled: ' . $key ); }
foreach ( [ 'pagebuilder_avada', 'pagebuilder_divi', 'woocommerce_rich_text', 'custom_post_types', 'service_page_cpt' ] as $key ) { check( 0 === $installed[ $key ], 'An unrelated or standalone-conflicting module was enabled: ' . $key ); }
check( ! isset( $installed['posting_service'], $installed['connection_enabled'] ), 'First install changed a backend/publishing connection setting' );
$provenance = get_option( 'nova_bridge_suite_auto_enabled' );
check( ! isset( $provenance['modules']['pagebuilder_avada'], $provenance['modules']['api_mapping_context'] ), 'Conflict/default-on API was falsely labeled auto-detected' );
check( [ 'source' => 'plugin', 'name' => 'sitepress-multilingual-cms/sitepress.php' ] === $provenance['modules']['multilingual_wpml'], 'Network plugin detection lost its bounded provenance' );
check( [ 'source' => 'theme', 'name' => 'Avada' ] === nova_bridge_suite_detect_modules()['pagebuilder_avada'], 'The active parent theme was not detected before applying the standalone conflict gate' );
$snapshot = $options;
nova_bridge_suite_activate();
check( $snapshot === $options, 'Reactivation rewrote an installed site or its provenance' );
ob_start(); nova_bridge_suite_render_checkbox_field( [ 'key' => 'pagebuilder_elementor', 'label' => 'Elementor' ] ); $html = ob_get_clean();
check( false !== strpos( $html, 'Auto-detected and enabled by NOVA' ), 'An actual active auto-enabled module lost its truthful label' );
check( ! in_array( 'pagebuilder_elementor', array_column( nova_bridge_suite_get_recommended_modules(), 'key' ), true ), 'Auto-enabled module still asks to enable it' );
$manual = $installed; $manual['pagebuilder_elementor'] = 0; update_option( NOVA_BRIDGE_SUITE_OPTION, $manual );
check( ! isset( get_option( 'nova_bridge_suite_auto_enabled' )['modules']['pagebuilder_elementor'] ), 'A saved manual toggle retained auto-enable attribution' );
$manual['pagebuilder_elementor'] = 1; update_option( NOVA_BRIDGE_SUITE_OPTION, $manual );
ob_start(); nova_bridge_suite_render_checkbox_field( [ 'key' => 'pagebuilder_elementor', 'label' => 'Elementor' ] ); $html = ob_get_clean();
check( false === strpos( $html, 'Auto-detected and enabled by NOVA' ), 'Manually enabled module was labeled auto-enabled' );
$options['active_plugins'] = [ 'standalone/nova-avada-bridge.php' ]; $network_plugins = []; $theme = '';
check( [] === nova_bridge_suite_auto_enabled_modules(), 'Inactive providers still display active auto-enable provenance' );

reset_fixture( [ NOVA_BRIDGE_SUITE_VERSION_OPTION => '2.9.0', NOVA_BRIDGE_SUITE_OPTION => [ 'pagebuilder_elementor' => 1, 'multilingual_wpml' => 0 ] ] );
$legacy = get_option( NOVA_BRIDGE_SUITE_OPTION );
check( 0 === nova_bridge_suite_get_settings()['api_mapping_context'], 'A missing 2.9 API flag became enabled before migration' );
nova_bridge_suite_maybe_upgrade(); nova_bridge_suite_activate();
check( $legacy === get_option( NOVA_BRIDGE_SUITE_OPTION ), 'Upgrade/reactivation replaced old explicit or missing settings' );
check( 0 === nova_bridge_suite_get_settings()['api_mapping_context'] && 1 === nova_bridge_suite_get_settings()['pagebuilder_elementor'] && 0 === nova_bridge_suite_get_settings()['multilingual_wpml'], 'Legacy choices or missing API state changed' );
check( false === get_option( 'nova_bridge_suite_auto_enabled' ), 'Upgrade invented first-install auto-enable provenance' );
foreach ( [ 0, 1 ] as $api ) {
    reset_fixture( [ NOVA_BRIDGE_SUITE_VERSION_OPTION => '2.9.0', NOVA_BRIDGE_SUITE_OPTION => [ 'api_mapping_context' => $api ] ] );
    nova_bridge_suite_maybe_upgrade(); nova_bridge_suite_activate();
    check( $api === nova_bridge_suite_get_settings()['api_mapping_context'], 'An explicit legacy API choice changed during upgrade/reactivation' );
}
reset_fixture( [ NOVA_BRIDGE_SUITE_OPTION => [] ] ); nova_bridge_suite_activate();
check( [] === get_option( NOVA_BRIDGE_SUITE_OPTION ) && 0 === nova_bridge_suite_get_settings()['api_mapping_context'], 'A stored empty settings array was mistaken for a new installation' );
reset_fixture( [ NOVA_BRIDGE_SUITE_VERSION_OPTION => '2.9.0' ] ); nova_bridge_suite_activate();
check( ! array_filter( get_option( NOVA_BRIDGE_SUITE_OPTION ) ) && false === get_option( 'nova_bridge_suite_auto_enabled' ), 'A prior version marker with no module option triggered fresh defaults' );
reset_fixture( [ NOVA_BRIDGE_SUITE_OPTION => false ] ); nova_bridge_suite_activate();
check( false === get_option( NOVA_BRIDGE_SUITE_OPTION ) && 0 === nova_bridge_suite_get_settings()['api_mapping_context'], 'A malformed stored setting was overwritten as if absent' );
reset_fixture( [], 'divi' ); nova_bridge_suite_activate();
check( 1 === get_option( NOVA_BRIDGE_SUITE_OPTION )['pagebuilder_divi'], 'An active child-theme parent did not select its matching Divi bridge' );
check( [ 'source' => 'theme', 'name' => 'Divi' ] === get_option( 'nova_bridge_suite_auto_enabled' )['modules']['pagebuilder_divi'], 'Theme auto-enable provenance was not bounded and exact' );
reset_fixture( [], '' ); $options['active_plugins'] = [ 'standalone/nova-avada-bridge.php' ]; $network_plugins = []; nova_bridge_suite_activate();
check( [ 'api_mapping_context' => 1 ] === array_filter( get_option( NOVA_BRIDGE_SUITE_OPTION ) ), 'Builtin editor function presence inferred a builder or unrelated feature without a supported active plugin/theme' );
nova_bridge_suite_register_settings();
check( 'NOVA Core' === $sections['nova_bridge_core'] && ! isset( $sections['nova_bridge_api_mapping'] ), 'API Mapping Context still has a separate settings accordion' );
check( 'nova_bridge_core' === $fields['nova_bridge_api_mapping_context_toggle']['section'], 'Optional API toggle is not under NOVA Core' );
check( strpos( $source, "register_activation_hook( __FILE__, 'nova_bridge_suite_activate' )" ) < strpos( $source, "nova_bridge_suite_load_selected_modules( [ 'api_mapping_context' ] )" ), 'Lifecycle hooks are registered only after a targeted runtime return' );
echo 'PASS ' . $checks . " settings lifecycle checks (fresh detection, conflicts, upgrades, reactivation, provenance and Core grouping).\n";
