<?php
/** Code-free recipe onboarding; the API owns validation, storage and native writes. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Layout_Recipes_Admin {
    private static $bootstrapped = false;

    public static function bootstrap(): void {
        if ( self::$bootstrapped ) { return; }
        self::$bootstrapped = true;
        add_filter( 'nova_bridge_suite_settings_tabs', [ __CLASS__, 'tab' ], 102 );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ], 20 );
    }
    public static function tab( array $tabs ): array { return [ 'layout-recipes' => [ 'label' => __( 'Create a content template', 'nova-bridge-suite' ), 'nav_parent' => 'layouts', 'render_callback' => [ __CLASS__, 'render' ] ] ] + $tabs; }

    public static function assets( string $hook ): void {
        if ( false === strpos( $hook, 'nova-settings' ) || ! current_user_can( 'manage_options' ) ) { return; }
        $directory = __DIR__ . '/../assets/';
        $base = plugin_dir_url( NOVA_BRIDGE_SUITE_PLUGIN_FILE ) . 'modules/api-mapping-context/assets/';
        wp_enqueue_style( 'nova-layout-recipes', $base . 'layout-recipes.css', [], (string) filemtime( $directory . 'layout-recipes.css' ) );
        wp_enqueue_script( 'nova-layout-recipes', $base . 'layout-recipes.js', [], (string) filemtime( $directory . 'layout-recipes.js' ), true );
        wp_localize_script( 'nova-layout-recipes', 'NovaLayoutRecipes', [
            'url' => esc_url_raw( rest_url( 'nova-bridge/v1/mapping/layout-recipes' ) ),
            'nonce' => wp_create_nonce( 'wp_rest' ),
            'resourceId' => is_string( $_GET['resource_id'] ?? null ) && preg_match( '/^(?:post_type|taxonomy):[a-z0-9_-]{1,32}$/D', $_GET['resource_id'] ) ? $_GET['resource_id'] : '',
            'profileId' => is_string( $_GET['recipe_id'] ?? null ) && preg_match( '/^[a-zA-Z0-9:_-]{1,200}$/D', $_GET['recipe_id'] ) ? $_GET['recipe_id'] : '',
            'newSetup' => '1' === ( $_GET['new'] ?? '' ),
            'layoutsUrl' => admin_url( 'options-general.php?page=nova-settings&tab=layouts' ),
        ] );
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        echo '<section id="nova-layout-recipes-app" class="nlr"><p role="status">Loading template choices…</p></section>';
        echo '<noscript><p>Enable JavaScript to create and review your content template.</p></noscript>';
    }
}
