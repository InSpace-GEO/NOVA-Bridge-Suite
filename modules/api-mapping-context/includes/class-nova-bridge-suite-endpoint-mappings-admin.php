<?php
/** All-destination onboarding and read-only live document inspection. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Endpoint_Mappings_Admin {
    private static $bootstrapped = false;

    public static function bootstrap(): void {
        if ( self::$bootstrapped ) { return; }
        self::$bootstrapped = true;
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ], 20 );
    }

    public static function assets( string $hook ): void {
        if ( false === strpos( $hook, 'nova-settings' ) || ! current_user_can( 'manage_options' ) ) { return; }
        // The settings router can resolve an unknown/legacy tab to Layouts. The
        // script checks its host before making requests on other settings tabs.
        $directory = __DIR__ . '/../assets/';
        $base = plugin_dir_url( NOVA_BRIDGE_SUITE_PLUGIN_FILE ) . 'modules/api-mapping-context/assets/';
        wp_enqueue_style( 'nova-endpoint-mappings', $base . 'endpoint-mappings.css', [], (string) filemtime( $directory . 'endpoint-mappings.css' ) );
        wp_enqueue_script( 'nova-endpoint-mappings', $base . 'endpoint-mappings.js', [], (string) filemtime( $directory . 'endpoint-mappings.js' ), true );
        wp_localize_script( 'nova-endpoint-mappings', 'NovaEndpointMappings', [
            'url' => esc_url_raw( rest_url( 'nova-bridge/v1/mapping' ) ),
            'nonce' => wp_create_nonce( 'wp_rest' ),
            'layoutsUrl' => admin_url( 'options-general.php?page=nova-settings&tab=layouts' ),
        ] );
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        echo '<section id="nova-endpoint-mappings-app" class="nem"><p role="status">Loading all content destinations…</p></section>';
        echo '<noscript><p>Enable JavaScript to configure default templates and inspect existing content.</p></noscript>';
    }
}
