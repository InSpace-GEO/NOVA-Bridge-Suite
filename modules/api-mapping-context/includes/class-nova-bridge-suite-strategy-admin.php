<?php
/** Strategy-scoped mapping interface. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Strategy_Admin {
	public static function bootstrap(): void {
		add_filter( 'nova_bridge_suite_settings_tabs', [ __CLASS__, 'tab' ], 100 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
	}
	public static function tab( array $tabs ): array {
		unset( $tabs['api-mapping-context'] );
		return [ 'strategy-mapping' => [ 'label' => __( 'Mapping', 'nova-bridge-suite' ), 'legacy_slugs' => [ 'api-mapping-context', 'content-context', 'mapping' ], 'render_callback' => [ __CLASS__, 'render' ] ] ] + $tabs;
	}
	public static function assets( string $hook ): void {
		if ( false === strpos( $hook, 'nova-settings' ) || ! in_array( $_GET['tab'] ?? '', [ 'strategy-mapping', 'api-mapping-context', 'content-context', 'mapping' ], true ) || ! current_user_can( 'manage_options' ) ) { return; }
		$base = NOVA_BRIDGE_SUITE_API_MAPPING_CONTEXT_URL;
		$dir = NOVA_BRIDGE_SUITE_API_MAPPING_CONTEXT_DIR . 'assets/';
		wp_enqueue_style( 'nova-mapping-drafts', $base . 'assets/mapping-drafts.css', [], NOVA_BRIDGE_SUITE_VERSION . '.' . filemtime( $dir . 'mapping-drafts.css' ) );
		wp_enqueue_script( 'nova-mapping-drafts', $base . 'assets/mapping-drafts.js', [], NOVA_BRIDGE_SUITE_VERSION . '.' . filemtime( $dir . 'mapping-drafts.js' ), true );
		wp_enqueue_style( 'nova-strategy-mapping', $base . 'assets/strategy-admin.css', [], NOVA_BRIDGE_SUITE_VERSION . '.' . filemtime( $dir . 'strategy-admin.css' ) );
		wp_enqueue_script( 'nova-strategy-mapping', $base . 'assets/strategy-admin.js', [ 'nova-mapping-drafts' ], NOVA_BRIDGE_SUITE_VERSION . '.' . filemtime( $dir . 'strategy-admin.js' ), true );
		wp_enqueue_script( 'nova-posting-admin', $base . 'assets/posting-admin.js', [ 'nova-strategy-mapping' ], NOVA_BRIDGE_SUITE_VERSION . '.' . filemtime( $dir . 'posting-admin.js' ), true );
		wp_localize_script( 'nova-strategy-mapping', 'NovaStrategyAdmin', [
			'url' => esc_url_raw( rest_url( 'nova-bridge/v1/strategy' ) ),
			'mappingUrl' => esc_url_raw( rest_url( 'nova-bridge/v1/mapping' ) ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
			'postingIntegration' => class_exists( 'Nova_Bridge_Suite_Mapping_Sync' ),
			'postingUrl' => esc_url_raw( rest_url( 'nova-bridge/v1/posting' ) ),
			'modulesUrl' => admin_url( 'options-general.php?page=nova-settings&tab=modules' ),
			'endpointSettingsUrl' => admin_url( 'options-general.php?page=nova-settings&tab=api-mapping-context' ),
		] );
	}
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		if ( class_exists( 'Nova_Bridge_Suite_Posting_Settings' ) ) { Nova_Bridge_Suite_Posting_Settings::render(); }
		echo '<div id="nova-strategy-app" class="nova-strategy"><p role="status">Loading site layouts…</p></div>';
		echo '<details class="nova-mapping-advanced"><summary>Existing endpoint defaults</summary>';
		echo '<p>Saved defaults remain available for review. Mapping context is never added to page responses. NOVA-owned CPTs retain their own context.</p>';
		Nova_Bridge_Suite_Content_Context::render_settings_tab();
		echo '</details>';
	}
}
