<?php
/** Local UI shell only: no WordPress options, content or network mutations. */
define( 'ABSPATH', __DIR__ );
define( 'NOVA_BRIDGE_SUITE_PLUGIN_FILE', dirname( __DIR__, 3 ) . '/nova-bridge-suite.php' );
$administrator = true; $actions = []; $styles = []; $scripts = []; $localized = []; $checks = 0;
function current_user_can( $capability ) { global $administrator; return $administrator && 'manage_options' === $capability; }
function add_action( $name, $callback, $priority = 10 ) { global $actions; $actions[$name][] = $callback; }
function plugin_dir_url( $file ) { return 'https://site.example/wp-content/plugins/nova-bridge-suite/'; }
function wp_enqueue_style( $handle, $url, $dependencies, $version ) { global $styles; $styles[$handle] = $url; }
function wp_enqueue_script( $handle, $url, $dependencies, $version, $footer ) { global $scripts; $scripts[$handle] = $url; }
function wp_localize_script( $handle, $name, $value ) { global $localized; $localized[$name] = $value; }
function esc_url_raw( $value ) { return $value; }
function rest_url( $path ) { return 'https://site.example/wp-json/' . $path; }
function wp_create_nonce( $action ) { return 'fixture-rest-nonce'; }
function admin_url( $path ) { return 'https://site.example/wp-admin/' . $path; }
function check( $value, $message ) { global $checks; ++$checks; if ( ! $value ) { throw new RuntimeException( $message ); } }
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-endpoint-mappings-admin.php';
Nova_Bridge_Suite_Endpoint_Mappings_Admin::bootstrap(); Nova_Bridge_Suite_Endpoint_Mappings_Admin::bootstrap();
check( count( $actions['admin_enqueue_scripts'] ) === 1, 'The UI shell registered twice' );
ob_start(); Nova_Bridge_Suite_Endpoint_Mappings_Admin::render(); $html = ob_get_clean();
check( strpos( $html, 'id="nova-endpoint-mappings-app"' ) !== false && strpos( $html, 'Loading all content destinations' ) !== false, 'The main coverage mount is absent' );
check( strpos( $html, 'nova-publishing-app' ) === false, 'The UI shell duplicates shared application padding' );
$_GET = []; Nova_Bridge_Suite_Endpoint_Mappings_Admin::assets( 'settings_page_nova-settings' );
check( isset( $scripts['nova-endpoint-mappings'], $styles['nova-endpoint-mappings'] ), 'The default Layouts URL without a tab lacks its assets' );
check( $localized['NovaEndpointMappings']['url'] === 'https://site.example/wp-json/nova-bridge/v1/mapping' && $localized['NovaEndpointMappings']['nonce'] === 'fixture-rest-nonce', 'The UI is not scoped to local nonce-protected APIs' );
check( array_keys( $localized['NovaEndpointMappings'] ) === [ 'url', 'nonce', 'layoutsUrl', 'recipesUrl' ], 'Source content or connection credentials leaked into localization' );
check( $localized['NovaEndpointMappings']['recipesUrl'] === 'https://site.example/wp-admin/options-general.php?page=nova-settings&tab=layout-recipes', 'Creation setup does not open the guided local mapper' );
foreach ( [ 'nova-connection', 'modules', 'unknown-fallback-tab' ] as $tab ) { $_GET = [ 'tab' => $tab ]; $scripts = []; Nova_Bridge_Suite_Endpoint_Mappings_Admin::assets( 'settings_page_nova-settings' ); check( isset( $scripts['nova-endpoint-mappings'] ), 'Settings fallback lacks host-guarded assets for ' . $tab ); }
$scripts = []; Nova_Bridge_Suite_Endpoint_Mappings_Admin::assets( 'post.php' ); check( [] === $scripts, 'Endpoint assets were enqueued outside NOVA settings' );
$administrator = false; ob_start(); Nova_Bridge_Suite_Endpoint_Mappings_Admin::render(); $html = ob_get_clean(); Nova_Bridge_Suite_Endpoint_Mappings_Admin::assets( 'settings_page_nova-settings' );
check( '' === $html && [] === $scripts, 'An unauthorized user received the administrator UI' );
$source = file_get_contents( dirname( __DIR__ ) . '/assets/endpoint-mappings.js' );
check( strpos( $source, "getElementById( 'nova-endpoint-mappings-app' )" ) !== false && strpos( $source, 'if ( host && root.NovaEndpointMappings )' ) !== false, 'Other settings tabs can start requests without the coverage host' );
check( strpos( $source, 'postingUrl' ) === false && strpos( $source, 'create-draft' ) === false && strpos( $source, "'/creation/preview'" ) !== false, 'Onboarding can trigger native publishing instead of local preview' );
echo 'PASS ' . $checks . " endpoint onboarding UI shell checks.\n";
