<?php
/** Execute the actual entrypoint with isolated modules and the real WooCommerce provider. */
define( 'ABSPATH', __DIR__ ); define( 'REST_REQUEST', true );
$route = $argv[1] ?? 'ready';
$fixture = sys_get_temp_dir() . '/nova-bootstrap-' . bin2hex( random_bytes( 8 ) ) . '/';
$files = []; $dirs = []; $actions = []; $loaded = []; $registered = []; $checks = 0;
function fixture_file( $name, $content ) { global $fixture, $files, $dirs; $dir = dirname( $fixture . $name ); if ( ! is_dir( $dir ) ) { mkdir( $dir, 0700, true ); $dirs[] = $dir; } file_put_contents( $fixture . $name, $content ); $files[] = $fixture . $name; }
function plugin_dir_path( $file ) { return $GLOBALS['fixture']; }
function is_admin() { return false; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_unslash( $v ) { return $v; }
function get_option( $key, $default = false ) { return $default; }
function add_action( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['actions'][$hook][$priority][] = $callback; }
function add_filter( ...$args ) {}
function register_activation_hook( ...$args ) {}
function register_deactivation_hook( ...$args ) {}
function taxonomy_exists( $taxonomy ) { return 'product_cat' === $taxonomy; }
function register_term_meta( $taxonomy, $key, $args ) { $GLOBALS['registered'][$taxonomy][$key] = $args; }
function register_rest_field( $taxonomy, $key, $args ) { $GLOBALS['wp_rest_additional_fields'][$taxonomy][$key] = $args; }
function nova_bridge_suite_get_settings() { return [ 'api_mapping_context' => 1, 'woocommerce_rich_text' => 1, 'future_provider' => 1, 'disabled_provider' => 0, 'conflicted_provider' => 1 ]; }
function nova_bridge_suite_module_definitions() { $out = []; foreach ( array_keys( nova_bridge_suite_get_settings() ) as $key ) { $out[$key] = [ 'path' => 'modules/' . $key . '.php' ]; } return $out; }
function nova_bridge_suite_get_module_conflicts() { return [ 'conflicted_provider' => true ]; }
function check( $value, $label ) { ++$GLOBALS['checks']; if ( ! $value ) { throw new RuntimeException( $label ); } }
try {
    fixture_file( 'includes/route-conflicts.php', '<?php' ); fixture_file( 'includes/settings.php', '<?php' ); fixture_file( 'includes/class-nova-bridge-suite-wpml-support.php', '<?php class Nova_Bridge_Suite_WPML_Support { static function bootstrap_runtime() {} }' );
    foreach ( nova_bridge_suite_module_definitions() as $key => $definition ) { fixture_file( $definition['path'], '<?php $GLOBALS["loaded"][] = ' . var_export( $key, true ) . ';' ); }
    $woo = str_replace( '\\', '/', dirname( __DIR__ ) . '/modules/woocommerce/wc-content-below-products.php' );
    file_put_contents( $fixture . 'modules/woocommerce_rich_text.php', '<?php require ' . var_export( $woo, true ) . '; $GLOBALS["loaded"][]="woocommerce_rich_text";' );
    fixture_file( 'modules/bridge/nova-bridge.php', '<?php $GLOBALS["loaded"][]="core";' ); fixture_file( 'modules/post-resolver/nova-post-resolver.php', '<?php $GLOBALS["loaded"][]="resolver";' );
    $_SERVER['REQUEST_METHOD'] = 'POST'; $_SERVER['HTTP_HOST'] = 'fixture.invalid'; $_SERVER['REQUEST_URI'] = '/wp-json/nova-bridge/v1/posting/' . $route;
    // A WordPress-active third-party plugin registers independently of NOVA's registry.
    add_action( 'rest_api_init', static function () { register_rest_field( 'product_cat', 'third_party_field', [ 'schema' => [ 'type' => 'string' ], 'get_callback' => '__return_empty_string', 'update_callback' => '__return_true' ] ); } );
    require dirname( __DIR__ ) . '/nova-bridge-suite.php';
    check( class_exists( 'WCCBP_Content_Below_Products' ), 'Real enabled WooCommerce provider must load during plugin bootstrap' );
    check( in_array( 'future_provider', $loaded, true ), 'New registered module must load without adding another hard-coded route dependency' );
    check( ! in_array( 'disabled_provider', $loaded, true ) && ! in_array( 'conflicted_provider', $loaded, true ), 'Disabled and conflicting modules must stay unloaded' );
    check( in_array( 'core', $loaded, true ) && in_array( 'resolver', $loaded, true ), 'Core support is loaded' );
    // Run the normal provider registration hooks, without plugin migration/worker side effects.
    foreach ( [ 'init', 'rest_api_init' ] as $hook ) { $priorities = $actions[$hook] ?? []; ksort( $priorities ); foreach ( $priorities as $callbacks ) { foreach ( $callbacks as $callback ) { if ( 'nova_bridge_suite_maybe_upgrade' !== $callback ) { call_user_func( $callback ); } } } }
    check( isset( $registered['product_cat']['content_below_products'] ), 'WooCommerce metadata registered on init' );
    check( is_callable( $GLOBALS['wp_rest_additional_fields']['product_cat']['content_below_products']['update_callback'] ?? null ), 'WooCommerce REST setter registered on rest_api_init' );
    check( isset( $GLOBALS['wp_rest_additional_fields']['product_cat']['third_party_field'] ), 'Third-party field registered normally' );
    echo 'PASS ' . $checks . ' posting bootstrap checks: ' . $route . "\n";
} finally {
    foreach ( $files as $file ) { unlink( $file ); }
    // Only remove our randomly named, empty test directories.
    foreach ( array_reverse( $dirs ) as $dir ) { if ( is_dir( $dir ) ) { rmdir( $dir ); } }
    foreach ( [ 'modules', 'includes' ] as $dir ) { if ( is_dir( $fixture . $dir ) ) { rmdir( $fixture . $dir ); } }
    if ( is_dir( $fixture ) ) { rmdir( $fixture ); }
}
