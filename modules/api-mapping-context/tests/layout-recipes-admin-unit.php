<?php
/** The code-free shell exposes local controls only and does not load native content. */
define( 'ABSPATH', __DIR__ );
define( 'NOVA_BRIDGE_SUITE_PLUGIN_FILE', dirname( __DIR__, 3 ) . '/nova-bridge-suite.php' );
$administrator = true; $actions = []; $filters = []; $styles = []; $scripts = []; $localized = []; $checks = 0;
function current_user_can( $capability ) { global $administrator; return $administrator && 'manage_options' === $capability; }
function add_action( $name, $callback, $priority = 10 ) { global $actions; $actions[$name][] = $callback; }
function add_filter( $name, $callback, $priority = 10 ) { global $filters; $filters[$name][] = $callback; }
function __( $value, $domain = '' ) { return $value; }
function plugin_dir_url( $file ) { return 'https://site.example/wp-content/plugins/nova-bridge-suite/'; }
function wp_enqueue_style( $handle, $url, $dependencies, $version ) { global $styles; $styles[$handle] = $url; }
function wp_enqueue_script( $handle, $url, $dependencies, $version, $footer ) { global $scripts; $scripts[$handle] = $url; }
function wp_localize_script( $handle, $name, $value ) { global $localized; $localized[$name] = $value; }
function esc_url_raw( $value ) { return $value; }
function rest_url( $path ) { return 'https://site.example/wp-json/' . $path; }
function wp_create_nonce( $action ) { return 'fixture-rest-nonce'; }
function admin_url( $path ) { return 'https://site.example/wp-admin/' . $path; }
function check( $value, $message ) { global $checks; ++$checks; if ( ! $value ) { throw new RuntimeException( $message ); } }
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-layout-recipes-admin.php';
Nova_Bridge_Suite_Layout_Recipes_Admin::bootstrap(); Nova_Bridge_Suite_Layout_Recipes_Admin::bootstrap();
check( count( $actions['admin_enqueue_scripts'] ) === 1, 'The recipe shell registered twice' );
check( count( $filters['nova_bridge_suite_settings_tabs'] ) === 1, 'The template child tab was registered twice' );
$tabs = call_user_func( $filters['nova_bridge_suite_settings_tabs'][0], [ 'layouts' => [ 'label' => 'Layouts' ], 'strategy-mapping' => [ 'label' => 'Legacy workspace' ] ] );
check( $tabs['layout-recipes']['nav_parent'] === 'layouts' && is_callable( $tabs['layout-recipes']['render_callback'] ) && $tabs['strategy-mapping']['label'] === 'Legacy workspace', 'The primary template child displaced an existing layout editor' );
ob_start(); Nova_Bridge_Suite_Layout_Recipes_Admin::render(); $html = ob_get_clean();
check( strpos( $html, 'id="nova-layout-recipes-app"' ) !== false && strpos( $html, 'class="nlr"' ) !== false, 'The recipe mount is absent' );
check( strpos( $html, 'nova-publishing-app' ) === false, 'The recipe shell duplicates shared layout padding' );
$_GET = []; Nova_Bridge_Suite_Layout_Recipes_Admin::assets( 'settings_page_nova-settings' );
check( isset( $scripts['nova-layout-recipes'], $styles['nova-layout-recipes'] ), 'The default settings route lacks host-guarded assets' );
check( $localized['NovaLayoutRecipes']['url'] === 'https://site.example/wp-json/nova-bridge/v1/mapping/layout-recipes' && $localized['NovaLayoutRecipes']['nonce'] === 'fixture-rest-nonce', 'The UI is not scoped to local authenticated recipe APIs' );
check( $localized['NovaLayoutRecipes']['resourceId'] === '' && $localized['NovaLayoutRecipes']['profileId'] === '', 'The default route invents an identity' );
$_GET = [ 'resource_id' => 'taxonomy:product_cat', 'recipe_id' => '00000000-0000-4000-8000-000000000001', 'new' => '1' ]; Nova_Bridge_Suite_Layout_Recipes_Admin::assets( 'settings_page_nova-settings' );
check( $localized['NovaLayoutRecipes']['resourceId'] === 'taxonomy:product_cat' && $localized['NovaLayoutRecipes']['newSetup'] === true, 'A configured destination was lost' );
$_GET = [ 'resource_id' => [ 'bad' ], 'recipe_id' => '<script>' ]; Nova_Bridge_Suite_Layout_Recipes_Admin::assets( 'settings_page_nova-settings' );
check( $localized['NovaLayoutRecipes']['resourceId'] === '' && $localized['NovaLayoutRecipes']['profileId'] === '', 'An invalid query identity was localized' );
check( array_keys( $localized['NovaLayoutRecipes'] ) === [ 'url', 'nonce', 'resourceId', 'profileId', 'newSetup', 'layoutsUrl' ], 'Source content, credentials or native document data leaked into localization' );
$scripts = []; Nova_Bridge_Suite_Layout_Recipes_Admin::assets( 'post.php' ); check( [] === $scripts, 'Recipe assets were loaded outside NOVA settings' );
$administrator = false; ob_start(); Nova_Bridge_Suite_Layout_Recipes_Admin::render(); $html = ob_get_clean(); Nova_Bridge_Suite_Layout_Recipes_Admin::assets( 'settings_page_nova-settings' );
check( '' === $html && [] === $scripts, 'An unauthorized user received the template editor' );
echo 'PASS ' . $checks . " layout recipe UI shell checks.\n";
