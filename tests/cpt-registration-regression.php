<?php
/**
 * Bootstrap a fresh WordPress request to exercise the selective module loader.
 * Run each scenario separately: php tests/cpt-registration-regression.php /path/to/wordpress woo-create
 * Use a staging site; normal plugin init/upgrade hooks can update rewrite options.
 */
if ( 'cli' !== PHP_SAPI ) { exit( 1 ); }
$scenarios = [
    'frontend'       => [ 'GET', '/', [] ],
    'woo-create'     => [ 'POST', '/wp-json/wc/v3/products/categories', [] ],
    'woo-read'       => [ 'GET', '/wp-json/wc/v3/products/categories', [] ],
    'core-write'     => [ 'POST', '/wp-json/wp/v2/posts', [] ],
    'core-read'      => [ 'GET', '/wp-json/wp/v2/types', [] ],
    'unrelated-rest' => [ 'GET', '/wp-json/unrelated/v1/status', [] ],
    'query-rest'     => [ 'POST', '/', [ 'rest_route' => '/wc/v3/products/categories' ] ],
    'admin-post'     => [ 'GET', '/wp-admin/post-new.php', [ 'post_type' => 'post' ] ],
    'admin-terms'    => [ 'GET', '/wp-admin/edit-tags.php', [ 'taxonomy' => 'product_cat' ] ],
    'admin-write'    => [ 'POST', '/wp-admin/post.php', [ 'action' => 'editpost', 'post_type' => 'post' ] ],
    'admin-ajax'     => [ 'POST', '/wp-admin/admin-ajax.php', [ 'action' => 'inline-save', 'post_type' => 'post' ] ],
];
$scenario = $argv[2] ?? '';
$root = rtrim( $argv[1] ?? '', '/\\' );
if ( ! isset( $scenarios[ $scenario ] ) || ! is_file( $root . '/wp-load.php' ) ) {
    fwrite( STDERR, "Usage: php cpt-registration-regression.php WP_ROOT SCENARIO\nScenarios: " . implode( ', ', array_keys( $scenarios ) ) . "\n" );
    exit( 1 );
}
[ $method, $uri, $params ] = $scenarios[ $scenario ];
$_SERVER['REQUEST_METHOD'] = $method;
$_SERVER['REQUEST_URI'] = $uri;
$_GET = 'GET' === $method || 'query-rest' === $scenario ? $params : [];
$_POST = 'POST' === $method && 'query-rest' !== $scenario ? $params : [];
$_REQUEST = $params;
if ( 0 === strpos( $scenario, 'admin-' ) ) { define( 'WP_ADMIN', true ); }
if ( 'admin-ajax' === $scenario ) { define( 'DOING_AJAX', true ); }
require $root . '/wp-load.php';

$assert = static function ( $condition, $message ) {
    if ( ! $condition ) { throw new RuntimeException( $message ); }
};
$settings = nova_bridge_suite_get_settings();
$conflicts = nova_bridge_suite_get_module_conflicts();
$modules = [
    'service_page_cpt' => [ 'modules/service-page-cpt/service-page-cpt.php', 'SEORAI\\ServicePageCPT\\Plugin' ],
    'custom_post_types' => [ 'modules/cpt/quarantined-cpt.php', 'SEORAI\\BodycleanCPT\\Plugin' ],
];
$registered = [];
foreach ( $modules as $key => [ $path, $class ] ) {
    $enabled = ! empty( $settings[ $key ] ) && empty( $conflicts[ $key ] );
    $loaded = in_array( realpath( NOVA_BRIDGE_SUITE_PLUGIN_DIR . $path ), get_included_files(), true );
    $assert( $enabled === $loaded, "$scenario: $key loading does not respect its enabled/conflict state" );
    if ( ! $enabled ) { continue; }
    $assert( class_exists( $class, false ), "$scenario: $class is missing" );
    $types = 'service_page_cpt' === $key ? [ 'service_page' ] : $class::get_post_types();
    if ( 'custom_post_types' === $key && ! get_option( 'quarantined_cpt_bodyclean_enable_cpts', true ) ) { continue; }
    foreach ( $types as $type ) {
        $assert( post_type_exists( $type ), "$scenario: enabled post type $type is not registered" );
        $object = get_post_type_object( $type );
        $assert( $object->public && $object->publicly_queryable, "$scenario: $type is not publicly queryable" );
        $assert( false !== $object->rewrite, "$scenario: $type rewrite registration is missing" );
        $registered[] = $type;
    }
}
echo wp_json_encode( [ 'scenario' => $scenario, 'result' => 'PASS', 'registered' => $registered ] ) . "\n";
